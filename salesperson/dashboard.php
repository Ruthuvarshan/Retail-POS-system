<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'salesperson') {
    header('Location: ../public/index.php');
    exit;
}

$conn = getConnection();

// Get sales data for charts
$salesData = [
    'daily' => getSalesData($conn, 'DAY'),
    'weekly' => getSalesData($conn, 'WEEK'),
    'monthly' => getSalesData($conn, 'MONTH')
];

$topProducts = getTopProducts($conn);
$performanceMetrics = getPerformanceMetrics($conn);

function getSalesData($conn, $interval) {
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(sale_date, '%Y-%m-%d') AS period,
            SUM(total_amount) AS total
        FROM sales_transactions
        WHERE salesperson_id = ?
        AND sale_date >= DATE_SUB(NOW(), INTERVAL 1 $interval)
        GROUP BY period
        ORDER BY period
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getTopProducts($conn) {
    $stmt = $conn->prepare("
        SELECT p.name, SUM(si.quantity) AS total_quantity
        FROM sales_items si
        JOIN products p ON si.product_id = p.product_id
        JOIN sales_transactions st ON si.sale_id = st.sale_id
        WHERE st.salesperson_id = ?
        GROUP BY p.product_id
        ORDER BY total_quantity DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getPerformanceMetrics($conn) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_sales,
            SUM(total_amount) AS total_revenue,
            AVG(total_amount) AS avg_sale,
            MAX(total_amount) AS best_sale
        FROM sales_transactions
        WHERE salesperson_id = ?
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

include '../includes/header/header.php';
?>

<div class="welcome-banner">
    <div class="welcome-message">
        <h2>Welcome, <?php echo $_SESSION['full_name']; ?>!</h2>
        <p><?php echo date('l, F d, Y'); ?></p>
    </div>
    <div class="quick-actions">
        <a href="pos.php" class="action-button"><i class="fas fa-cash-register"></i> New Sale</a>
        <a href="customers.php?action=add" class="action-button"><i class="fas fa-user-plus"></i> Add Customer</a>
        <a href="sales_report.php" class="action-button"><i class="fas fa-chart-bar"></i> Sales Report</a>
    </div>
</div>

    <!-- Performance Metrics -->
    <div class="dashboard-stats">
        <div class="stat-card">
            <div class="stat-card-icon" style="background-color: #4CAF50;">
                <i class="fas fa-cash-register"></i>
            </div>            <div class="stat-card-info">
                <h3>Total Sales</h3>
                <p class="stat-value"><?= $performanceMetrics['total_sales'] ?></p>
                <p class="stat-label">Transactions</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon" style="background-color: #2196F3;">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="stat-card-info">
                <h3>Total Revenue</h3>
                <p class="stat-value">$<?= number_format($performanceMetrics['total_revenue'], 2) ?></p>
                <p class="stat-label">Sales amount</p>
            </div>        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon" style="background-color: #673AB7;">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="stat-card-info">
                <h3>Average Sale</h3>
                <p class="stat-value">$<?= number_format($performanceMetrics['avg_sale'], 2) ?></p>
                <p class="stat-label">Per transaction</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-card-icon" style="background-color: #FF9800;">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="stat-card-info">
                <h3>Best Sale</h3>
                <p class="stat-value">$<?= number_format($performanceMetrics['best_sale'], 2) ?></p>
                <p class="stat-label">Highest transaction</p>
            </div>
        </div>
    </div>

    <!-- Sales Charts -->
    <div class="dashboard-row">
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-area"></i> Sales Trends</h3>
            </div>
            <div class="card-content">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
        
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie"></i> Top Products</h3>
            </div>
            <div class="card-content">
                <canvas id="productsChart"></canvas>
            </div>
        </div>    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Sales Trend Chart
new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($salesData['weekly'], 'period')) ?>,
        datasets: [{
            label: 'Weekly Sales',
            data: <?= json_encode(array_column($salesData['weekly'], 'total')) ?>,
            borderColor: '#007bff',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: { display: true, text: 'Amount (₹)' }
            }
        }
    }
});

// Top Products Chart
new Chart(document.getElementById('productsChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($topProducts, 'name')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($topProducts, 'total_quantity')) ?>,
            backgroundColor: [
                '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
</script>

<?php
mysqli_close($conn);
include '../includes/footer/footer.php';
?>