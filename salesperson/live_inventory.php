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

// Set default filters
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$stock_status = isset($_GET['stock_status']) ? $_GET['stock_status'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build query based on filters
$query = "SELECT p.*, c.name as category_name 
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.category_id
          WHERE 1=1";

// Apply filters
if ($category_id > 0) {
    $query .= " AND p.category_id = $category_id";
}

if ($stock_status === 'low') {
    $query .= " AND p.stock_quantity <= p.minimum_stock";
} elseif ($stock_status === 'out') {
    $query .= " AND p.stock_quantity = 0";
}

if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query .= " AND (p.name LIKE '%$search_term%' OR p.sku LIKE '%$search_term%')";
}

$query .= " ORDER BY p.name";
$result = mysqli_query($conn, $query);

// Get all categories for filter dropdown
$categories_query = "SELECT * FROM categories ORDER BY name";
$categories_result = mysqli_query($conn, $categories_query);

// Include header
include '../includes/header/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h2><i class="fas fa-boxes"></i> Live Inventory</h2>
        <div class="page-actions">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Inventory
            </button>
            <a href="pos.php" class="btn btn-secondary">
                <i class="fas fa-cash-register"></i> Back to POS
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 non-printable">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filter Inventory</h5>
        </div>
        <div class="card-body">
            <form method="get" action="" class="row">
                <div class="col-md-3 form-group">
                    <label for="category_id">Category</label>
                    <select class="form-control" name="category_id" id="category_id">
                        <option value="0">All Categories</option>
                        <?php while ($category = mysqli_fetch_assoc($categories_result)): ?>
                            <option value="<?php echo $category['category_id']; ?>" 
                                <?php echo ($category_id == $category['category_id']) ? 'selected' : ''; ?>>
                                <?php echo $category['name']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="col-md-3 form-group">
                    <label for="stock_status">Stock Status</label>
                    <select class="form-control" name="stock_status" id="stock_status">
                        <option value="all" <?php echo ($stock_status == 'all') ? 'selected' : ''; ?>>All Stock</option>
                        <option value="low" <?php echo ($stock_status == 'low') ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="out" <?php echo ($stock_status == 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                </div>
                
                <div class="col-md-4 form-group">
                    <label for="search">Search</label>
                    <input type="text" class="form-control" name="search" id="search" 
                           placeholder="Search by name or SKU" value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                
                <div class="col-md-2 form-group d-flex align-items-end">
                    <button type="submit" class="btn btn-primary mr-2">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="inventory_report.php" class="btn btn-secondary">
                        <i class="fas fa-sync"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Content -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-clipboard-list"></i> Inventory List</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Stock Qty</th>
                            <th>Min Stock</th>
                            <th>Cost Price</th>
                            <th>Selling Price</th>
                            <th>Status</th>
                            <th class="non-printable">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result && mysqli_num_rows($result) > 0) {
                            while ($product = mysqli_fetch_assoc($result)) {
                                $stock_class = '';
                                if ($product['stock_quantity'] <= 0) {
                                    $stock_class = 'text-danger font-weight-bold';
                                } elseif ($product['stock_quantity'] <= $product['minimum_stock']) {
                                    $stock_class = 'text-warning font-weight-bold';
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                    <td class="<?php echo $stock_class; ?>">
                                        <?php echo $product['stock_quantity']; ?>
                                    </td>
                                    <td><?php echo $product['minimum_stock']; ?></td>
                                    <td><?php echo '$' . number_format($product['cost_price'], 2); ?></td>
                                    <td><?php echo '$' . number_format($product['selling_price'], 2); ?></td>
                                    <td>
                                        <?php if ($product['status'] == 'active'): ?>
                                            <span class="badge badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="non-printable">
                                        <form method="post" action="pos.php" style="display:inline-block;">
                                            <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                            <input type="hidden" name="quantity" value="1">
                                            <button type="submit" name="add_to_cart" class="btn btn-sm btn-primary"
                                                <?php echo ($product['stock_quantity'] <= 0) ? 'disabled' : ''; ?>>
                                                <i class="fas fa-cart-plus"></i> Add to Cart
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo '<tr><td colspan="9" class="text-center">No products found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .non-printable {
            display: none !important;
        }
        
        body {
            padding: 0;
            margin: 0;
        }
        
        .container-fluid {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .page-header {
            margin-bottom: 20px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            padding: 5px;
            border: 1px solid #ddd;
        }
    }
</style>

<?php
// Include footer
mysqli_free_result($result);
mysqli_close($conn);
include '../includes/footer/footer.php';
?>