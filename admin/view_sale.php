<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../public/index.php');
    exit;
}

// Check if sale ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: reports.php?report=sales');
    exit;
}

$saleId = (int)$_GET['id'];

// Get database connection
$conn = getConnection();

// Get company settings for invoice header
$settings = [];
$settingsQuery = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email', 'currency', 'tax_rate')";
$settingsResult = mysqli_query($conn, $settingsQuery);
while ($row = mysqli_fetch_assoc($settingsResult)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Default currency if not set
$currency = isset($settings['currency']) ? $settings['currency'] : '$';

// Fetch sale information
$sale = null;
$query = "SELECT t.*, 
         c.first_name, c.last_name, c.email, c.phone, c.address,
         u.full_name as salesperson_name
         FROM sales_transactions t
         LEFT JOIN customers c ON t.customer_id = c.customer_id
         LEFT JOIN users u ON t.salesperson_id = u.user_id
         WHERE t.sale_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $saleId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $sale = $result->fetch_assoc();
} else {
    $_SESSION['error_message'] = "Sale not found.";
    header('Location: reports.php?report=sales');
    exit;
}
$stmt->close();

// Fetch sale items
$items = [];
$itemsQuery = "SELECT si.*, p.name, p.sku, c.name as category_name
             FROM sales_items si
             JOIN products p ON si.product_id = p.product_id
             LEFT JOIN categories c ON p.category_id = c.category_id
             WHERE si.sale_id = ?
             ORDER BY si.item_id";
$stmt = $conn->prepare($itemsQuery);
$stmt->bind_param("i", $saleId);
$stmt->execute();
$itemsResult = $stmt->get_result();
while ($item = $itemsResult->fetch_assoc()) {
    $items[] = $item;
}
$stmt->close();

// Include header
include '../includes/header/header.php';
?>

<!-- Include Print Stylesheet -->
<link rel="stylesheet" href="../assets/css/print-styles.css" media="print">

<div class="container-fluid">
    <!-- Print-only header that will appear on printed invoice -->
    <div class="print-header" style="display:none;">
        <h1><?php echo isset($settings['company_name']) ? $settings['company_name'] : 'Retail POS System'; ?></h1>
        <p><?php echo isset($settings['company_address']) ? $settings['company_address'] : ''; ?></p>
        <p>Phone: <?php echo isset($settings['company_phone']) ? $settings['company_phone'] : ''; ?></p>
        <p>Email: <?php echo isset($settings['company_email']) ? $settings['company_email'] : ''; ?></p>
        <hr>
        <h2>INVOICE</h2>
    </div>

    <div class="page-header">
        <h2><i class="fas fa-file-invoice"></i> Sale Invoice</h2>
        <div class="page-actions non-printable">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Invoice
            </button>
            <a href="reports.php?report=sales" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Sales Report
            </a>
        </div>
    </div>

    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-info-circle"></i> Invoice #<?php echo $sale['invoice_number']; ?></h3>
        </div>
        <div class="card-content">
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="invoice-section">
                        <h4>Invoice Information</h4>
                        <table class="table table-borderless invoice-info">
                            <tbody>
                                <tr>
                                    <th>Invoice Number:</th>
                                    <td><?php echo $sale['invoice_number']; ?></td>
                                </tr>
                                <tr>
                                    <th>Date & Time:</th>
                                    <td><?php echo date('F d, Y h:i A', strtotime($sale['sale_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Payment Method:</th>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Salesperson:</th>
                                    <td><?php echo $sale['salesperson_name']; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="invoice-section">
                        <h4>Customer Information</h4>
                        <?php if ($sale['customer_id'] && $sale['first_name']): ?>
                        <table class="table table-borderless invoice-info">
                            <tbody>
                                <tr>
                                    <th>Name:</th>
                                    <td><?php echo $sale['first_name'] . ' ' . $sale['last_name']; ?></td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td><?php echo $sale['email']; ?></td>
                                </tr>
                                <tr>
                                    <th>Phone:</th>
                                    <td><?php echo $sale['phone']; ?></td>
                                </tr>
                                <tr>
                                    <th>Address:</th>
                                    <td><?php echo $sale['address']; ?></td>
                                </tr>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p>Walk-in Customer</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="invoice-section">
                <h4>Purchased Items</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th>Unit Price</th>
                                <th>Quantity</th>
                                <th>Discount</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $count = 1;
                            foreach ($items as $item): 
                            ?>
                            <tr>
                                <td><?php echo $count++; ?></td>
                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                <td><?php echo htmlspecialchars($item['sku']); ?></td>
                                <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                                <td><?php echo $currency . number_format($item['unit_price'], 2); ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td><?php echo $currency . number_format($item['discount_amount'], 2); ?></td>
                                <td><?php echo $currency . number_format($item['total_price'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="invoice-section">
                        <h4>Notes</h4>
                        <p><?php echo $sale['notes'] ? nl2br(htmlspecialchars($sale['notes'])) : 'No notes provided.'; ?></p>
                    </div>
                    
                    <!-- Admin-only Actions -->
                    <div class="invoice-section non-printable">
                        <h4>Admin Actions</h4>
                        <div class="admin-actions">
                            <a href="reports.php?report=sales&filter_invoice=<?php echo urlencode($sale['invoice_number']); ?>" class="btn btn-info mr-2">
                                <i class="fas fa-search"></i> Find Similar Sales
                            </a>
                            <a href="#" class="btn btn-warning" data-toggle="modal" data-target="#editNoteModal">
                                <i class="fas fa-edit"></i> Edit Note
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="invoice-section invoice-totals">
                        <h4>Invoice Totals</h4>
                        <table class="table table-borderless">
                            <tbody>
                                <tr>
                                    <th>Subtotal:</th>
                                    <td class="text-right"><?php echo $currency . number_format($sale['subtotal'], 2); ?></td>
                                </tr>
                                <tr>
                                    <th>Tax (<?php echo isset($settings['tax_rate']) ? $settings['tax_rate'] : '0'; ?>%):</th>
                                    <td class="text-right"><?php echo $currency . number_format($sale['tax_amount'], 2); ?></td>
                                </tr>
                                <tr>
                                    <th>Discount:</th>
                                    <td class="text-right"><?php echo $currency . number_format($sale['discount_amount'], 2); ?></td>
                                </tr>
                                <tr class="invoice-grand-total">
                                    <th>Grand Total:</th>
                                    <td class="text-right"><?php echo $currency . number_format($sale['total_amount'], 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="invoice-section print-footer" style="display:none;">
                <p>Thank you for your business!</p>
                <p>Invoice generated on <?php echo date('F d, Y h:i A'); ?></p>
                <p>This is an official document from <?php echo isset($settings['company_name']) ? $settings['company_name'] : 'Retail POS System'; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Edit Note Modal -->
<div class="modal fade" id="editNoteModal" tabindex="-1" role="dialog" aria-labelledby="editNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editNoteModalLabel">Edit Invoice Note</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="update_sale_note.php" method="post">
                <div class="modal-body">
                    <input type="hidden" name="sale_id" value="<?php echo $saleId; ?>">
                    <div class="form-group">
                        <label for="saleNote">Note:</label>
                        <textarea class="form-control" id="saleNote" name="note" rows="4"><?php echo htmlspecialchars($sale['notes']); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .invoice-section {
        margin-bottom: 30px;
    }
    
    .invoice-section h4 {
        font-size: 18px;
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 15px;
        color: #333;
    }
    
    .invoice-info th {
        width: 40%;
        font-weight: 600;
        padding: 5px 10px 5px 0;
    }
    
    .invoice-info td {
        padding: 5px 0;
    }
    
    .invoice-totals {
        background-color: #f9f9f9;
        padding: 15px;
        border-radius: 5px;
    }
    
    .invoice-grand-total {
        font-size: 18px;
        font-weight: bold;
        border-top: 2px solid #ddd;
    }
    
    .invoice-grand-total th,
    .invoice-grand-total td {
        padding-top: 15px;
    }
    
    .admin-actions {
        margin-top: 15px;
    }
    
    @media print {
        .print-header {
            display: block !important;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .print-footer {
            display: block !important;
            text-align: center;
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        
        .invoice-section {
            page-break-inside: avoid;
        }
    }
</style>

<?php
// Clean up database connections
mysqli_close($conn);
include '../includes/footer/footer.php';
?>
