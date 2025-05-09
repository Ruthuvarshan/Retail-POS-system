<?php
// Start session and include database connection
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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
$salesperson_id = isset($_GET['salesperson_id']) ? (int)$_GET['salesperson_id'] : 0;
$filter_invoice = isset($_GET['filter_invoice']) ? $_GET['filter_invoice'] : '';

// Build query based on filters for sales transactions
$query = "SELECT st.*, 
                 CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                 u.full_name as salesperson_name
          FROM sales_transactions st
          LEFT JOIN customers c ON st.customer_id = c.customer_id
          LEFT JOIN users u ON st.salesperson_id = u.user_id
          WHERE DATE(st.sale_date) BETWEEN '$start_date' AND '$end_date'";

if ($payment_method !== 'all') {
    $payment_method = mysqli_real_escape_string($conn, $payment_method);
    $query .= " AND st.payment_method = '$payment_method'";
}

if ($customer_id > 0) {
    $query .= " AND st.customer_id = $customer_id";
}

if ($salesperson_id > 0) {
    $query .= " AND st.salesperson_id = $salesperson_id";
}

if (!empty($filter_invoice)) {
    $safe_invoice = mysqli_real_escape_string($conn, $filter_invoice);
    $query .= " AND st.invoice_number LIKE '%$safe_invoice%'";
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
$sales_by_salesperson = [];
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
        
        // Group by salesperson
        if (!isset($sales_by_salesperson[$row['salesperson_id']])) {
            $sales_by_salesperson[$row['salesperson_id']] = [
                'name' => $row['salesperson_name'],
                'count' => 0,
                'amount' => 0
            ];
        }
        $sales_by_salesperson[$row['salesperson_id']]['count']++;
        $sales_by_salesperson[$row['salesperson_id']]['amount'] += $row['total_amount'];
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
                WHERE DATE(st.sale_date) BETWEEN '$start_date' AND '$end_date'";

if ($category_id > 0) {
    $top_products_query .= " AND p.category_id = $category_id";
}

if ($salesperson_id > 0) {
    $top_products_query .= " AND st.salesperson_id = $salesperson_id";
}

$top_products_query .= " GROUP BY p.product_id
                ORDER BY total_quantity DESC
                LIMIT 10";

$top_products_result = mysqli_query($conn, $top_products_query);

// Get customers for filter dropdown
$customers_query = "SELECT c.customer_id, c.first_name, c.last_name 
                   FROM customers c
                   JOIN sales_transactions st ON c.customer_id = st.customer_id
                   GROUP BY c.customer_id
                   ORDER BY c.first_name, c.last_name";
$customers_result = mysqli_query($conn, $customers_query);

// Get categories for filter dropdown
$categories_query = "SELECT category_id, name FROM categories WHERE status = 'active' ORDER BY name";
$categories_result = mysqli_query($conn, $categories_query);

// Get salespersons for filter dropdown
$salespersons_query = "SELECT user_id, full_name FROM users WHERE role = 'salesperson' ORDER BY full_name";
$salespersons_result = mysqli_query($conn, $salespersons_query);

// Get sales by category
$category_sales_query = "SELECT c.name as category_name, 
                        SUM(si.quantity) as total_quantity, 
                        SUM(si.total_price) as total_sales
                    FROM sales_items si
                    JOIN products p ON si.product_id = p.product_id
                    JOIN categories c ON p.category_id = c.category_id
                    JOIN sales_transactions st ON si.sale_id = st.sale_id
                    WHERE DATE(st.sale_date) BETWEEN '$start_date' AND '$end_date'";

if ($salesperson_id > 0) {
    $category_sales_query .= " AND st.salesperson_id = $salesperson_id";
}

$category_sales_query .= " GROUP BY c.category_id
                    ORDER BY total_sales DESC";
                    
$category_sales_result = mysqli_query($conn, $category_sales_query);

// Generate arrays for daily sales chart
$dates_array = [];
$sales_array = [];
foreach ($daily_sales as $date => $data) {
    $dates_array[] = $date;
    $sales_array[] = $data['amount'];
}

// Generate arrays for payment method chart
$payment_labels = [];
$payment_data = [];
foreach ($sales_by_payment_method as $method => $data) {
    $payment_labels[] = ucfirst(str_replace('_', ' ', $method));
    $payment_data[] = $data['amount'];
}

// Generate arrays for salesperson performance chart
$salesperson_labels = [];
$salesperson_data = [];
arsort($sales_by_salesperson); // Sort by sales amount
foreach ($sales_by_salesperson as $id => $data) {
    $salesperson_labels[] = $data['name'];
    $salesperson_data[] = $data['amount'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Reports - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/enhanced-dashboard.css">
    <link rel="stylesheet" href="../assets/css/print-styles.css" media="print">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-filters {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .summary-card {
            background-color: #fff;
            border-radius: 5px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }
        .summary-card h3 {
            margin-top: 0;
            color: #555;
        }
        .summary-card .amount {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin: 10px 0;
        }
        .chart-container {
            background-color: #fff;
            border-radius: 5px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .chart-wrapper {
            height: 300px;
        }
        .two-column {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
        }
        table.report-table {
            width: 100%;
            border-collapse: collapse;
        }
        .report-table th, .report-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .report-table th {
            background-color: #f2f2f2;
        }
        .report-table tr:hover {
            background-color: #f5f5f5;
        }
        .print-button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 15px;
        }
        .no-print {
            display: block;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header/header.php'; ?>
    
    <div class="container">
        <h1>Sales Reports</h1>
        
        <div class="report-filters no-print">
            <form method="GET" action="reports.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Start Date:</label>
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date:</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="form-group">
                        <label>Payment Method:</label>
                        <select name="payment_method">
                            <option value="all" <?php echo $payment_method === 'all' ? 'selected' : ''; ?>>All Methods</option>
                            <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="credit_card" <?php echo $payment_method === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                            <option value="debit_card" <?php echo $payment_method === 'debit_card' ? 'selected' : ''; ?>>Debit Card</option>
                            <option value="mobile_payment" <?php echo $payment_method === 'mobile_payment' ? 'selected' : ''; ?>>Mobile Payment</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Customer:</label>
                        <select name="customer_id">
                            <option value="0">All Customers</option>
                            <?php if ($customers_result): ?>
                                <?php while ($customer = mysqli_fetch_assoc($customers_result)): ?>
                                    <option value="<?php echo $customer['customer_id']; ?>" 
                                        <?php echo $customer_id == $customer['customer_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Category:</label>
                        <select name="category_id">
                            <option value="0">All Categories</option>
                            <?php if ($categories_result): ?>
                                <?php while ($category = mysqli_fetch_assoc($categories_result)): ?>
                                    <option value="<?php echo $category['category_id']; ?>" 
                                        <?php echo $category_id == $category['category_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Salesperson:</label>
                        <select name="salesperson_id">
                            <option value="0">All Salespersons</option>
                            <?php if ($salespersons_result): ?>
                                <?php while ($person = mysqli_fetch_assoc($salespersons_result)): ?>
                                    <option value="<?php echo $person['user_id']; ?>" 
                                        <?php echo $salesperson_id == $person['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($person['full_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Invoice Number:</label>
                        <input type="text" name="filter_invoice" value="<?php echo htmlspecialchars($filter_invoice); ?>" placeholder="Search invoices...">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </form>
        </div>
        
        <button class="print-button no-print" onclick="window.print()">Print Report</button>
        
        <div class="report-summary">
            <div class="summary-card">
                <h3>Total Revenue</h3>
                <div class="amount"><?php echo $currency . number_format($total_revenue, 2); ?></div>
                <div><?php echo $total_sales_count; ?> transactions</div>
            </div>
            <div class="summary-card">
                <h3>Subtotal</h3>
                <div class="amount"><?php echo $currency . number_format($total_sales_amount, 2); ?></div>
            </div>
            <div class="summary-card">
                <h3>Tax Amount</h3>
                <div class="amount"><?php echo $currency . number_format($total_tax, 2); ?></div>
            </div>
            <div class="summary-card">
                <h3>Discounts</h3>
                <div class="amount"><?php echo $currency . number_format($total_discount, 2); ?></div>
            </div>
        </div>
        
        <div class="two-column">
            <div class="chart-container">
                <h2>Daily Sales</h2>
                <div class="chart-wrapper">
                    <canvas id="dailySalesChart"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <h2>Payment Methods</h2>
                <div class="chart-wrapper">
                    <canvas id="paymentMethodChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="two-column">
            <div class="chart-container">
                <h2>Top 10 Products</h2>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Total Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($top_products_result): ?>
                            <?php while ($product = mysqli_fetch_assoc($top_products_result)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['sku']); ?>)</td>
                                    <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                    <td><?php echo number_format($product['total_quantity']); ?></td>
                                    <td><?php echo $currency . number_format($product['total_sales'], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">No product data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="chart-container">
                <h2>Sales by Category</h2>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Total Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($category_sales_result): ?>
                            <?php while ($category = mysqli_fetch_assoc($category_sales_result)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                    <td><?php echo number_format($category['total_quantity']); ?></td>
                                    <td><?php echo $currency . number_format($category['total_sales'], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3">No category data available</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="chart-container">
            <h2>Salesperson Performance</h2>
            <div class="chart-wrapper">
                <canvas id="salespersonChart"></canvas>
            </div>
        </div>
        
        <div class="chart-container">
            <h2>Recent Sales Transactions</h2>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Salesperson</th>
                        <th>Payment Method</th>
                        <th>Subtotal</th>
                        <th>Tax</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th class="no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && mysqli_num_rows($result) > 0): ?>
                        <?php while ($sale = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($sale['sale_date'])); ?></td>
                                <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($sale['salesperson_name']); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $sale['payment_method'])); ?></td>
                                <td><?php echo $currency . number_format($sale['subtotal'], 2); ?></td>
                                <td><?php echo $currency . number_format($sale['tax_amount'], 2); ?></td>
                                <td><?php echo $currency . number_format($sale['discount_amount'], 2); ?></td>
                                <td><?php echo $currency . number_format($sale['total_amount'], 2); ?></td>
                                <td class="no-print">
                                    <a href="view_sale.php?id=<?php echo $sale['sale_id']; ?>" class="btn btn-sm btn-info">View</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">No sales transactions found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php include '../includes/footer/footer.php'; ?>
    
    <script>
        // Initialize charts when page is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Daily sales chart
            const dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
            const dailySalesChart = new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($dates_array); ?>,
                    datasets: [{
                        label: 'Daily Sales',
                        data: <?php echo json_encode($sales_array); ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 2,
                        tension: 0.1
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
                                    return '<?php echo $currency; ?>' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '<?php echo $currency; ?>' + context.raw.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // Payment method chart
            const paymentCtx = document.getElementById('paymentMethodChart').getContext('2d');
            const paymentMethodChart = new Chart(paymentCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode($payment_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($payment_data); ?>,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.7)',
                            'rgba(54, 162, 235, 0.7)',
                            'rgba(255, 206, 86, 0.7)',
                            'rgba(75, 192, 192, 0.7)',
                            'rgba(153, 102, 255, 0.7)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return label + ': ' + '<?php echo $currency; ?>' + value.toLocaleString() + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            
            // Salesperson performance chart
            const salespersonCtx = document.getElementById('salespersonChart').getContext('2d');
            const salespersonChart = new Chart(salespersonCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($salesperson_labels); ?>,
                    datasets: [{
                        label: 'Sales by Salesperson',
                        data: <?php echo json_encode($salesperson_data); ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.7)',
                        borderColor: 'rgba(75, 192, 192, 1)',
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
                                    return '<?php echo $currency; ?>' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '<?php echo $currency; ?>' + context.raw.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
