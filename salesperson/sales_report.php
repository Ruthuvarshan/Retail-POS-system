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

// Get default currency
$currency = '$';
$currencyQuery = "SELECT setting_value FROM settings WHERE setting_key = 'currency'";
$currencyResult = mysqli_query($conn, $currencyQuery);
if ($currencyResult && $row = mysqli_fetch_assoc($currencyResult)) {
    $currency = $row['setting_value'];
}

// Set default filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // First day of current month
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : 'all';
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Build query based on filters for sales transactions
$query = "SELECT st.*, 
                 CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                 u.full_name as salesperson_name
          FROM sales_transactions st
          LEFT JOIN customers c ON st.customer_id = c.customer_id
          LEFT JOIN users u ON st.salesperson_id = u.user_id
          WHERE st.salesperson_id = {$_SESSION['user_id']}
            AND DATE(st.sale_date) BETWEEN '$start_date' AND '$end_date'";

if ($payment_method !== 'all') {
    $payment_method = mysqli_real_escape_string($conn, $payment_method);
    $query .= " AND st.payment_method = '$payment_method'";
}

if ($customer_id > 0) {
    $query .= " AND st.customer_id = $customer_id";
}

$query .= " ORDER BY st.sale_date DESC";
$result = mysqli_query($conn, $query);

// Calculate totals
$total_sales_count = 0;
$total_sales_amount = 0;
$total_tax = 0;
$total_discount = 0;
$total_revenue = 0;
$sales_by_payment_method = [];
$daily_sales = [];
$item_sales = [];

// If we have results, process them
if ($result) {
    $total_sales_count = mysqli_num_rows($result);
    $tmp_result = mysqli_query($conn, $query);
    while ($row = mysqli_fetch_assoc($tmp_result)) {
        $total_sales_amount += $row['subtotal'];
        $total_tax += $row['tax_amount'];
        $total_discount += $row['discount_amount'];
        $total_revenue += $row['total_amount'];
        
        // Count by payment method
        if (!isset($sales_by_payment_method[$row['payment_method']])) {
            $sales_by_payment_method[$row['payment_method']] = [
                'count' => 0,
                'amount' => 0
            ];
        }
        $sales_by_payment_method[$row['payment_method']]['count']++;
        $sales_by_payment_method[$row['payment_method']]['amount'] += $row['total_amount'];
        
        // Group by date for daily sales chart
        $sale_date = date('Y-m-d', strtotime($row['sale_date']));
        if (!isset($daily_sales[$sale_date])) {
            $daily_sales[$sale_date] = [
                'count' => 0,
                'amount' => 0
            ];
        }
        $daily_sales[$sale_date]['count']++;
        $daily_sales[$sale_date]['amount'] += $row['total_amount'];
    }
    
    // Sort daily sales by date
    ksort($daily_sales);
}

// Get top selling products
$top_products_query = "SELECT p.name, p.product_id, p.sku, 
                     SUM(si.quantity) as total_quantity, 
                     SUM(si.total_price) as total_sales,
                     c.name as category_name
                FROM sales_items si
                JOIN products p ON si.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                JOIN sales_transactions st ON si.sale_id = st.sale_id
                WHERE st.salesperson_id = {$_SESSION['user_id']}
                AND DATE(st.sale_date) BETWEEN '$start_date' AND '$end_date'";

if ($category_id > 0) {
    $top_products_query .= " AND p.category_id = $category_id";
}

$top_products_query .= " GROUP BY p.product_id
                ORDER BY total_quantity DESC
                LIMIT 10";

$top_products_result = mysqli_query($conn, $top_products_query);

// Get customers for filter dropdown
$customers_query = "SELECT c.customer_id, c.first_name, c.last_name 
                   FROM customers c
                   JOIN sales_transactions st ON c.customer_id = st.customer_id
                   WHERE st.salesperson_id = {$_SESSION['user_id']}
                   GROUP BY c.customer_id
                   ORDER BY c.first_name, c.last_name";
$customers_result = mysqli_query($conn, $customers_query);

// Get categories for filter dropdown
$categories_query = "SELECT category_id, name FROM categories WHERE status = 'active' ORDER BY name";
$categories_result = mysqli_query($conn, $categories_query);

// Get sales by category
$category_sales_query = "SELECT c.name as category_name, 
                        SUM(si.quantity) as total_quantity, 
                        SUM(si.total_price) as total_sales
                    FROM sales_items si
                    JOIN products p ON si.product_id = p.product_id
                    JOIN categories c ON p.category_id = c.category_id
                    JOIN sales_transactions st ON si.sale_id = st.sale_id
                    WHERE st.salesperson_id = {$_SESSION['user_id']}
                    AND DATE(st.sale_date) BETWEEN '$start_date' AND '$end_date'
                    GROUP BY c.category_id
                    ORDER BY total_sales DESC";
$category_sales_result = mysqli_query($conn, $category_sales_query);

// Prepare data for charts
$labels_daily = [];
$data_daily_amount = [];
$data_daily_count = [];
foreach ($daily_sales as $date => $data) {
    $labels_daily[] = date('M d', strtotime($date));
    $data_daily_amount[] = $data['amount'];
    $data_daily_count[] = $data['count'];
}

$labels_payment = [];
$data_payment = [];
$colors_payment = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'];
$i = 0;
foreach ($sales_by_payment_method as $method => $data) {
    $labels_payment[] = ucfirst(str_replace('_', ' ', $method));
    $data_payment[] = $data['amount'];
    $i++;
}

$labels_category = [];
$data_category = [];
$colors_category = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40'];
if ($category_sales_result) {
    $i = 0;
    while ($row = mysqli_fetch_assoc($category_sales_result)) {
        $labels_category[] = $row['category_name'];
        $data_category[] = $row['total_sales'];
        $i++;
    }
    mysqli_data_seek($category_sales_result, 0);
}

// Include header
include '../includes/header/header.php';
?>

<!-- Include Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

<!-- Include Print Stylesheet -->
<link rel="stylesheet" href="../assets/css/print-styles.css" media="print">

<div class="container-fluid">
    <!-- Print-only header that will appear on printed reports -->
    <div class="print-header" style="display:none;">
        <h1><?php echo isset($settings['company_name']) ? $settings['company_name'] : 'Retail POS System'; ?></h1>
        <p>Sales Report</p>
        <p>Period: <?php echo date('F d, Y', strtotime($start_date)); ?> - <?php echo date('F d, Y', strtotime($end_date)); ?></p>
        <p>Generated on: <?php echo date('F d, Y h:i A'); ?></p>
        <p>Generated by: <?php echo $_SESSION['full_name']; ?></p>
    </div>

    <div class="page-header">
        <h2><i class="fas fa-chart-line"></i> Sales Report</h2>
        <div class="page-actions non-printable">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Report
            </button>
            <a href="pos.php" class="btn btn-secondary">
                <i class="fas fa-cash-register"></i> Back to POS
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4 non-printable">
        <div class="card-header">
            <h5><i class="fas fa-filter"></i> Filter Sales</h5>
        </div>
        <div class="card-body">
            <form method="get" action="" class="row">
                <div class="col-md-2 form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" class="form-control" name="start_date" id="start_date" 
                           value="<?php echo $start_date; ?>" required>
                </div>
                
                <div class="col-md-2 form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" class="form-control" name="end_date" id="end_date" 
                           value="<?php echo $end_date; ?>" required>
                </div>
                
                <div class="col-md-2 form-group">
                    <label for="payment_method">Payment Method</label>
                    <select class="form-control" name="payment_method" id="payment_method">
                        <option value="all" <?php echo ($payment_method == 'all') ? 'selected' : ''; ?>>All Methods</option>
                        <option value="cash" <?php echo ($payment_method == 'cash') ? 'selected' : ''; ?>>Cash</option>
                        <option value="credit_card" <?php echo ($payment_method == 'credit_card') ? 'selected' : ''; ?>>Credit Card</option>
                        <option value="debit_card" <?php echo ($payment_method == 'debit_card') ? 'selected' : ''; ?>>Debit Card</option>
                        <option value="mobile_payment" <?php echo ($payment_method == 'mobile_payment') ? 'selected' : ''; ?>>Mobile Payment</option>
                    </select>
                </div>
                
                <div class="col-md-2 form-group">
                    <label for="customer_id">Customer</label>
                    <select class="form-control" name="customer_id" id="customer_id">
                        <option value="0">All Customers</option>
                        <?php 
                        if ($customers_result) {
                            while ($customer = mysqli_fetch_assoc($customers_result)) {
                                $selected = ($customer_id == $customer['customer_id']) ? 'selected' : '';
                                echo '<option value="' . $customer['customer_id'] . '" ' . $selected . '>';
                                echo $customer['first_name'] . ' ' . $customer['last_name'];
                                echo '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <div class="col-md-2 form-group">
                    <label for="category_id">Product Category</label>
                    <select class="form-control" name="category_id" id="category_id">
                        <option value="0">All Categories</option>
                        <?php 
                        if ($categories_result) {
                            while ($category = mysqli_fetch_assoc($categories_result)) {
                                $selected = ($category_id == $category['category_id']) ? 'selected' : '';
                                echo '<option value="' . $category['category_id'] . '" ' . $selected . '>';
                                echo $category['name'];
                                echo '</option>';
                            }
                            mysqli_data_seek($categories_result, 0);
                        }
                        ?>
                    </select>
                </div>
                
                <div class="col-md-2 form-group d-flex align-items-end">
                    <button type="submit" class="btn btn-primary mr-2">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="sales_report.php" class="btn btn-secondary">
                        <i class="fas fa-sync"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Report Period Info -->
    <div class="alert alert-info mb-4">
        <div class="row">
            <div class="col-md-6">
                <h4><i class="fas fa-calendar-alt"></i> Report Period</h4>
                <p><strong>From:</strong> <?php echo date('F j, Y', strtotime($start_date)); ?></p>
                <p><strong>To:</strong> <?php echo date('F j, Y', strtotime($end_date)); ?></p>
            </div>
            <div class="col-md-6">
                <h4><i class="fas fa-chart-pie"></i> Summary</h4>
                <p><strong>Total Transactions:</strong> <?php echo $total_sales_count; ?></p>
                <p><strong>Total Revenue:</strong> <?php echo $currency . number_format($total_revenue, 2); ?></p>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Sales</h5>
                    <h3 class="card-text"><?php echo $currency . number_format($total_sales_amount, 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Net Revenue</h5>
                    <h3 class="card-text"><?php echo $currency . number_format($total_revenue, 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Tax</h5>
                    <h3 class="card-text"><?php echo $currency . number_format($total_tax, 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Discount</h5>
                    <h3 class="card-text"><?php echo $currency . number_format($total_discount, 2); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row mb-4">
        <!-- Daily Sales Trend -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line"></i> Daily Sales Trend</h5>
                </div>
                <div class="card-body">
                    <canvas id="dailySalesChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Payment Method Distribution -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie"></i> Payment Methods</h5>
                </div>
                <div class="card-body">
                    <canvas id="paymentMethodChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- Category Sales Distribution -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-tags"></i> Sales by Category</h5>
                </div>
                <div class="card-body">
                    <canvas id="categorySalesChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Top Selling Products -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-trophy"></i> Top Selling Products</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($top_products_result && mysqli_num_rows($top_products_result) > 0) {
                                    while ($product = mysqli_fetch_assoc($top_products_result)) {
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($product['name']) . ' <small class="text-muted">(' . htmlspecialchars($product['sku']) . ')</small></td>';
                                        echo '<td>' . htmlspecialchars($product['category_name']) . '</td>';
                                        echo '<td>' . $product['total_quantity'] . '</td>';
                                        echo '<td>' . $currency . number_format($product['total_sales'], 2) . '</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="4" class="text-center">No product sales found.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sales by Payment Method -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-money-check-alt"></i> Sales by Payment Method</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Payment Method</th>
                                    <th>Number of Sales</th>
                                    <th>Total Amount</th>
                                    <th>Average Sale Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($sales_by_payment_method as $method => $data) {
                                    $avg_sale = $data['count'] > 0 ? $data['amount'] / $data['count'] : 0;
                                    echo '<tr>';
                                    echo '<td>' . ucfirst(str_replace('_', ' ', $method)) . '</td>';
                                    echo '<td>' . $data['count'] . '</td>';
                                    echo '<td>' . $currency . number_format($data['amount'], 2) . '</td>';
                                    echo '<td>' . $currency . number_format($avg_sale, 2) . '</td>';
                                    echo '</tr>';
                                }
                                if (empty($sales_by_payment_method)) {
                                    echo '<tr><td colspan="4" class="text-center">No sales data available</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Performance -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-folder"></i> Category Performance</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Items Sold</th>
                                    <th>Revenue</th>
                                    <th>% of Total Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($category_sales_result && mysqli_num_rows($category_sales_result) > 0) {
                                    while ($category = mysqli_fetch_assoc($category_sales_result)) {
                                        $percent = ($total_revenue > 0) ? ($category['total_sales'] / $total_revenue * 100) : 0;
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($category['category_name']) . '</td>';
                                        echo '<td>' . $category['total_quantity'] . '</td>';
                                        echo '<td>' . $currency . number_format($category['total_sales'], 2) . '</td>';
                                        echo '<td>' . number_format($percent, 1) . '%</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="4" class="text-center">No category data available</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detailed Sales Transactions -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-list"></i> Sales Transactions</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Subtotal</th>
                            <th>Tax</th>
                            <th>Discount</th>
                            <th>Total</th>
                            <th>Payment Method</th>
                            <th class="non-printable">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result && mysqli_num_rows($result) > 0) {
                            while ($sale = mysqli_fetch_assoc($result)) {
                                ?>
                                <tr>
                                    <td><?php echo $sale['invoice_number']; ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($sale['sale_date'])); ?></td>
                                    <td><?php echo empty($sale['customer_name']) ? 'Walk-in Customer' : $sale['customer_name']; ?></td>
                                    <td><?php echo $currency . number_format($sale['subtotal'], 2); ?></td>
                                    <td><?php echo $currency . number_format($sale['tax_amount'], 2); ?></td>
                                    <td><?php echo $currency . number_format($sale['discount_amount'], 2); ?></td>
                                    <td><?php echo $currency . number_format($sale['total_amount'], 2); ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?></td>
                                    <td class="non-printable">
                                        <a href="receipt.php?id=<?php echo $sale['sale_id']; ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-receipt"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo '<tr><td colspan="9" class="text-center">No sales transactions found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Initialize Charts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Daily Sales Chart
    const dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
    const dailySalesChart = new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($labels_daily); ?>,
            datasets: [{
                label: 'Sales Amount',
                data: <?php echo json_encode($data_daily_amount); ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '<?php echo $currency; ?>' + value;
                        }
                    }
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Daily Sales Revenue',
                    font: {
                        size: 16
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '<?php echo $currency; ?>' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            }
        }
    });

    // Payment Method Chart
    const paymentCtx = document.getElementById('paymentMethodChart').getContext('2d');
    const paymentMethodChart = new Chart(paymentCtx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode($labels_payment); ?>,
            datasets: [{
                data: <?php echo json_encode($data_payment); ?>,
                backgroundColor: <?php echo json_encode($colors_payment); ?>,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = '<?php echo $currency; ?>' + context.parsed.toFixed(2);
                            const total = context.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((context.parsed / total) * 100);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });

    // Category Sales Chart
    const categoryCtx = document.getElementById('categorySalesChart').getContext('2d');
    const categorySalesChart = new Chart(categoryCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($labels_category); ?>,
            datasets: [{
                label: 'Sales by Category',
                data: <?php echo json_encode($data_category); ?>,
                backgroundColor: <?php echo json_encode($colors_category); ?>,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '<?php echo $currency; ?>' + value;
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '<?php echo $currency; ?>' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            }
        }
    });
});
</script>

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
        
        canvas {
            max-width: 100% !important;
            height: auto !important;
        }
    }
    
    .card {
        margin-bottom: 20px;
    }
    
    .card-header {
        background-color: #f8f9fa;
        font-weight: bold;
    }
    
    .table th {
        background-color: #f8f9fa;
    }
    
    .page-header {
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .page-actions {
        display: flex;
        gap: 10px;
    }
    
    .alert-info {
        background-color: #d9edf7;
        border-color: #bce8f1;
        color: #31708f;
    }
</style>

<?php
// Include footer
if ($result) mysqli_free_result($result);
mysqli_close($conn);
include '../includes/footer/footer.php';
?>