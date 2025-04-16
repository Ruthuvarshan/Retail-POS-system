<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a salesperson
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'salesperson') {
    header('Location: ../public/index.php');
    exit;
}

// Get database connection
$conn = getConnection();

// Initialize variables
$message = '';
$messageType = '';
$cart = [];
$cartTotal = 0;
$cartTax = 0;
$cartDiscount = 0;
$cartGrandTotal = 0;

// Get default tax rate from settings
$taxRate = 0;
$taxRateQuery = "SELECT setting_value FROM settings WHERE setting_key = 'tax_rate'";
$taxRateResult = mysqli_query($conn, $taxRateQuery);
if ($taxRateResult && $row = mysqli_fetch_assoc($taxRateResult)) {
    $taxRate = floatval($row['setting_value']);
}

// Get default currency from settings
$currency = '$';
$currencyQuery = "SELECT setting_value FROM settings WHERE setting_key = 'currency'";
$currencyResult = mysqli_query($conn, $currencyQuery);
if ($currencyResult && $row = mysqli_fetch_assoc($currencyResult)) {
    $currency = $row['setting_value'];
}

// Load customers for dropdown
$customers = [];
$customersQuery = "SELECT customer_id, first_name, last_name FROM customers WHERE status = 'active' ORDER BY first_name, last_name";
$customersResult = mysqli_query($conn, $customersQuery);
if ($customersResult) {
    while ($row = mysqli_fetch_assoc($customersResult)) {
        $customers[] = $row;
    }
    mysqli_free_result($customersResult);
}

// Initialize or retrieve shopping cart from session
if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

// Handle Add to Cart
if (isset($_POST['add_to_cart']) && isset($_POST['product_id'])) {
    $productId = (int)$_POST['product_id'];
    $quantity = (int)$_POST['quantity'];
    
    // Get product information
    $productQuery = "SELECT * FROM products WHERE product_id = ? AND status = 'active'";
    $stmt = $conn->prepare($productQuery);
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        
        // Check if we have enough stock
        if ($product['stock_quantity'] >= $quantity) {
            $productPrice = $product['selling_price'];
            $productTotal = $productPrice * $quantity;
              // Check if product already exists in cart
            $found = false;
            foreach ($_SESSION['pos_cart'] as &$item) {
                if ($item['product_id'] == $productId) {
                    // Update quantity
                    $newQuantity = $item['quantity'] + $quantity;
                    
                    // Check if the new total quantity exceeds available stock
                    if ($newQuantity <= $product['stock_quantity']) {
                        $item['quantity'] = $newQuantity;
                        $item['total'] = $item['price'] * $newQuantity;
                        $message = "Cart updated successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Not enough stock available!";
                        $messageType = "danger";
                    }
                    $found = true;
                    break;
                }
            }
            // Unset the reference to avoid bugs when adding multiple products
            unset($item);
            
            // If product was not found in the cart, add it
            if (!$found) {
                $_SESSION['pos_cart'][] = [
                    'product_id' => $productId,
                    'name' => $product['name'],
                    'price' => $productPrice,
                    'quantity' => $quantity,
                    'total' => $productTotal
                ];
                $message = "Product added to cart!";
                $messageType = "success";
            }
        } else {
            $message = "Not enough stock available!";
            $messageType = "danger";
        }
    }
    $stmt->close();
}

// Handle Remove from Cart
if (isset($_GET['remove']) && isset($_SESSION['pos_cart'])) {
    $removeIndex = (int)$_GET['remove'];
    if (isset($_SESSION['pos_cart'][$removeIndex])) {
        array_splice($_SESSION['pos_cart'], $removeIndex, 1);
        $message = "Item removed from cart!";
        $messageType = "warning";
    }
}

// Handle Clear Cart
if (isset($_GET['clear']) && $_GET['clear'] === 'cart') {
    $_SESSION['pos_cart'] = [];
    $message = "Cart has been cleared!";
    $messageType = "warning";
}

// Handle Process Sale
if (isset($_POST['process_sale']) && !empty($_SESSION['pos_cart'])) {
    $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $paymentMethod = sanitizeInput($_POST['payment_method'], $conn);
    $notes = sanitizeInput($_POST['notes'], $conn);
    $discountAmount = !empty($_POST['discount_amount']) ? (float)$_POST['discount_amount'] : 0;
    
    // Calculate totals
    $subtotal = 0;
    foreach ($_SESSION['pos_cart'] as $item) {
        $subtotal += $item['total'];
    }
    $taxAmount = ($subtotal - $discountAmount) * ($taxRate / 100);
    $totalAmount = $subtotal - $discountAmount + $taxAmount;
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Generate invoice number
        $invoiceNumber = generateInvoiceNumber($conn);
        
        // Create sales transaction record
        $salesQuery = "INSERT INTO sales_transactions 
                      (invoice_number, customer_id, salesperson_id, subtotal, tax_amount, 
                       discount_amount, total_amount, payment_method, notes) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($salesQuery);
        $stmt->bind_param("siidddsss", $invoiceNumber, $customerId, $_SESSION['user_id'], 
                         $subtotal, $taxAmount, $discountAmount, $totalAmount, $paymentMethod, $notes);
        $stmt->execute();
        $saleId = $stmt->insert_id;
        $stmt->close();
        
        // Add sale items and update inventory
        foreach ($_SESSION['pos_cart'] as $item) {
            // Add item to sale_items table
            $itemQuery = "INSERT INTO sales_items 
                         (sale_id, product_id, quantity, unit_price, total_price) 
                         VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($itemQuery);
            $stmt->bind_param("iiddd", $saleId, $item['product_id'], $item['quantity'], $item['price'], $item['total']);
            $stmt->execute();
            $stmt->close();
            
            // Update product stock quantity
            $updateStockQuery = "UPDATE products 
                               SET stock_quantity = stock_quantity - ? 
                               WHERE product_id = ?";
            $stmt = $conn->prepare($updateStockQuery);
            $stmt->bind_param("ii", $item['quantity'], $item['product_id']);
            $stmt->execute();
            $stmt->close();
            
            // Record stock movement
            $movementType = "out";
            $movementQuery = "INSERT INTO stock_movements 
                            (product_id, quantity, movement_type, reference_id, notes, performed_by) 
                            VALUES (?, ?, ?, ?, ?, ?)";
            $movementNote = "Sale: " . $invoiceNumber;
            $stmt = $conn->prepare($movementQuery);
            $stmt->bind_param("iisisi", $item['product_id'], $item['quantity'], 
                            $movementType, $saleId, $movementNote, $_SESSION['user_id']);
            $stmt->execute();
            $stmt->close();
        }
        
        // Commit the transaction
        $conn->commit();
        
        // Clear the cart after successful sale
        $_SESSION['pos_cart'] = [];
        
        // Show success message
        $message = "Sale completed successfully! Invoice #: " . $invoiceNumber;
        $messageType = "success";
        
        // Redirect to print receipt page
        header("Location: receipt.php?id=" . $saleId);
        exit;
        
    } catch (Exception $e) {
        // Roll back the transaction in case of an error
        $conn->rollback();
        $message = "Error processing sale: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Calculate cart totals
$cart = $_SESSION['pos_cart'];
$cartTotal = 0;
foreach ($cart as $item) {
    $cartTotal += $item['total'];
}
$cartDiscount = 0; // This would be set if applying a discount
$cartTax = ($cartTotal - $cartDiscount) * ($taxRate / 100);
$cartGrandTotal = $cartTotal - $cartDiscount + $cartTax;

// Include header
include '../includes/header/header.php';
?>

<div class="pos-container">
    <div class="page-header">
        <h2><i class="fas fa-cash-register"></i> Point of Sale</h2>
        <div class="page-actions">
            <a href="?clear=cart" class="btn btn-warning" onclick="return confirm('Are you sure you want to clear the cart?')">
                <i class="fas fa-trash"></i> Clear Cart
            </a>
            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#productSearchModal">
                <i class="fas fa-search"></i> Find Products
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show">
            <?php echo $message; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="pos-layout">
        <div class="pos-cart">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-shopping-cart"></i> Current Sale</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Qty</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cart)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">Cart is empty</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($cart as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $item['name']; ?></td>
                                            <td><?php echo $currency . number_format($item['price'], 2); ?></td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td><?php echo $currency . number_format($item['total'], 2); ?></td>
                                            <td>
                                                <a href="?remove=<?php echo $index; ?>" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="cart-summary">
                        <div class="summary-item">
                            <span>Subtotal:</span>
                            <span><?php echo $currency . number_format($cartTotal, 2); ?></span>
                        </div>
                        <div class="summary-item">
                            <span>Tax (<?php echo $taxRate; ?>%):</span>
                            <span><?php echo $currency . number_format($cartTax, 2); ?></span>
                        </div>
                        <div class="summary-item">
                            <span>Discount:</span>
                            <span><?php echo $currency . number_format($cartDiscount, 2); ?></span>
                        </div>
                        <div class="summary-item total">
                            <span>Total:</span>
                            <span><?php echo $currency . number_format($cartGrandTotal, 2); ?></span>
                        </div>
                    </div>

                    <button type="button" class="btn btn-success btn-block btn-lg <?php echo empty($cart) ? 'disabled' : ''; ?>" 
                            <?php echo empty($cart) ? 'disabled' : ''; ?>
                            data-toggle="modal" data-target="#checkoutModal">
                        <i class="fas fa-money-bill-wave"></i> Process Payment
                    </button>
                </div>
            </div>
        </div>

        <div class="pos-products">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-boxes"></i> Quick Add Products</h3>
                    <form class="product-search">
                        <div class="input-group">
                            <input type="text" id="quickSearch" class="form-control" placeholder="Quick search...">
                            <div class="input-group-append">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="card-body">
                    <div class="product-grid" id="quickProductGrid">
                        <?php
                        // Fetch popular or commonly sold products
                        $productsQuery = "SELECT p.*, c.name as category_name 
                                        FROM products p
                                        LEFT JOIN categories c ON p.category_id = c.category_id
                                        WHERE p.status = 'active' AND p.stock_quantity > 0
                                        ORDER BY p.name
                                        LIMIT 16";
                        $productsResult = mysqli_query($conn, $productsQuery);
                        
                        if ($productsResult && mysqli_num_rows($productsResult) > 0) {
                            while ($product = mysqli_fetch_assoc($productsResult)) {
                                ?>                                <div class="product-item">
                                    <form method="post" action="pos.php">
                                        <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        
                                        <div class="product-content">
                                            <h4 class="product-name"><?php echo $product['name']; ?></h4>
                                            <p class="product-price"><?php echo $currency . number_format($product['selling_price'], 2); ?></p>
                                            <p class="product-stock <?php echo $product['stock_quantity'] < $product['minimum_stock'] ? 'low-stock' : ''; ?>">
                                                Stock: <?php echo $product['stock_quantity']; ?>
                                            </p>
                                            
                                            <button type="submit" name="add_to_cart" class="btn btn-primary btn-block">
                                                <i class="fas fa-plus"></i> Add
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                <?php
                            }
                            mysqli_free_result($productsResult);
                        } else {
                            echo '<div class="no-products">No products available.</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Search Modal -->
    <div class="modal fade" id="productSearchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Product Search</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="input-group mb-3">
                        <input type="text" id="productSearchInput" class="form-control" placeholder="Search products...">
                        <div class="input-group-append">
                            <button class="btn btn-primary" id="searchProductsBtn">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped" id="productsTable">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Search results will appear here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Checkout Modal -->
    <div class="modal fade" id="checkoutModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="">
                    <div class="modal-header">
                        <h5 class="modal-title">Process Payment</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="customer_id">Customer (Optional)</label>
                            <select class="form-control" id="customer_id" name="customer_id">
                                <option value="">Walk-in Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['customer_id']; ?>">
                                        <?php echo $customer['first_name'] . ' ' . $customer['last_name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="payment_method">Payment Method</label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="mobile_payment">Mobile Payment</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="discount_amount">Discount Amount</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><?php echo $currency; ?></span>
                                </div>
                                <input type="number" class="form-control" id="discount_amount" name="discount_amount" min="0" step="0.01" value="0">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                        </div>
                        
                        <div class="checkout-summary">
                            <div class="summary-item">
                                <span>Subtotal:</span>
                                <span><?php echo $currency . number_format($cartTotal, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Tax (<?php echo $taxRate; ?>%):</span>
                                <span><?php echo $currency . number_format($cartTax, 2); ?></span>
                            </div>
                            <div class="summary-item">
                                <span>Discount:</span>
                                <span id="checkoutDiscount"><?php echo $currency . number_format($cartDiscount, 2); ?></span>
                            </div>
                            <div class="summary-item total">
                                <span>Total:</span>
                                <span id="checkoutTotal"><?php echo $currency . number_format($cartGrandTotal, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="process_sale" class="btn btn-success">Complete Sale</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .pos-container {
        padding: 0;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .pos-layout {
        display: flex;
        gap: 20px;
    }
    
    .pos-cart {
        flex: 0 0 40%;
    }
    
    .pos-products {
        flex: 0 0 60%;
    }
    
    .card {
        background-color: #fff;
        border-radius: 5px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }
    
    .card-header {
        padding: 15px 20px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .card-body {
        padding: 20px;
    }
    
    .product-search {
        flex: 0 0 60%;
    }
    
    .product-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 15px;
    }
    
    .product-item {
        border: 1px solid #eee;
        border-radius: 4px;
        padding: 10px;
        transition: all 0.3s;
    }
    
    .product-item:hover {
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
    }
    
    .product-content {
        display: flex;
        flex-direction: column;
    }
    
    .product-name {
        font-size: 1rem;
        margin: 0 0 5px;
        color: #333;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .product-price {
        font-weight: bold;
        color: #28a745;
        margin: 0 0 5px;
    }
    
    .product-stock {
        font-size: 0.85rem;
        color: #6c757d;
        margin: 0 0 10px;
    }
    
    .low-stock {
        color: #dc3545;
    }
    
    .cart-summary {
        margin-top: 20px;
        border-top: 1px solid #eee;
        padding-top: 15px;
    }
    
    .summary-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
    }
    
    .summary-item.total {
        font-weight: bold;
        font-size: 1.2rem;
        margin-top: 10px;
        border-top: 1px solid #eee;
        padding-top: 10px;
    }
    
    .checkout-summary {
        margin-top: 20px;
        border-top: 1px solid #eee;
        padding-top: 15px;
    }
    
    @media (max-width: 992px) {
        .pos-layout {
            flex-direction: column-reverse;
        }
        
        .pos-cart, .pos-products {
            flex: 0 0 100%;
        }
    }
</style>

<script>
    // Quick search functionality
    document.getElementById('quickSearch').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const products = document.querySelectorAll('.product-item');
        
        products.forEach(product => {
            const name = product.querySelector('.product-name').textContent.toLowerCase();
            if (name.includes(searchTerm)) {
                product.style.display = 'block';
            } else {
                product.style.display = 'none';
            }
        });
    });
    
    // Product search modal functionality
    document.getElementById('searchProductsBtn').addEventListener('click', function() {
        const searchTerm = document.getElementById('productSearchInput').value;
        
        // Perform AJAX request to search products
        fetch(`search_products.php?term=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(products => {
                const tableBody = document.querySelector('#productsTable tbody');
                tableBody.innerHTML = '';
                
                if (products.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="5" class="text-center">No products found</td></tr>';
                    return;
                }
                
                products.forEach(product => {
                    tableBody.innerHTML += `
                        <tr>
                            <td>${product.name}</td>
                            <td>${product.category_name}</td>
                            <td>${product.selling_price}</td>
                            <td>${product.stock_quantity}</td>
                            <td>
                                <form method="post" action="">
                                    <input type="hidden" name="product_id" value="${product.product_id}">
                                    <div class="input-group input-group-sm">
                                        <input type="number" class="form-control" name="quantity" min="1" max="${product.stock_quantity}" value="1">
                                        <div class="input-group-append">
                                            <button type="submit" name="add_to_cart" class="btn btn-primary">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    `;
                });
            })
            .catch(error => {
                console.error('Error fetching products:', error);
            });
    });
    
    // Update discount and total in checkout modal
    document.getElementById('discount_amount').addEventListener('input', function(e) {
        const discountAmount = parseFloat(e.target.value) || 0;
        const subtotal = <?php echo $cartTotal; ?>;
        const taxRate = <?php echo $taxRate; ?>;
        
        const tax = (subtotal - discountAmount) * (taxRate / 100);
        const total = subtotal - discountAmount + tax;
        
        document.getElementById('checkoutDiscount').textContent = '<?php echo $currency; ?>' + discountAmount.toFixed(2);
        document.getElementById('checkoutTotal').textContent = '<?php echo $currency; ?>' + total.toFixed(2);
    });
</script>

<?php
// Create a search_products.php file for AJAX product search
$search_products_file = '../salesperson/search_products.php';
$search_products_content = '<?php
session_start();
require_once "../config/database.php";

// Check if user is logged in and is a salesperson
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "salesperson") {
    header("Content-Type: application/json");
    echo json_encode(["error" => "Unauthorized access"]);
    exit;
}

// Get database connection
$conn = getConnection();

// Get search term
$term = isset($_GET["term"]) ? $_GET["term"] : "";
$term = sanitizeInput($term, $conn);

// Search for products
$query = "SELECT p.*, c.name as category_name 
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.category_id
          WHERE p.status = \'active\' 
          AND p.stock_quantity > 0
          AND (p.name LIKE ? OR p.sku LIKE ? OR c.name LIKE ?)
          ORDER BY p.name
          LIMIT 30";

$stmt = $conn->prepare($query);
$searchTerm = "%" . $term . "%";
$stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    // Format price for display
    $row["selling_price"] = "$" . number_format($row["selling_price"], 2);
    $products[] = $row;
}

$stmt->close();
mysqli_close($conn);

// Return JSON response
header("Content-Type: application/json");
echo json_encode($products);
?>';

// Check if the file exists, if not create it
if (!file_exists($search_products_file)) {
    file_put_contents($search_products_file, $search_products_content);
}

// Close connection
mysqli_close($conn);

// Include footer
include '../includes/footer/footer.php';
?>