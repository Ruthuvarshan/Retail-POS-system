<?php
// Verify session exists
if (!isset($_SESSION['user_id'])) {
    header('Location: /dbms_project/public/index.php');
    exit;
}

// Determine current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Get database connection
require_once $_SERVER['DOCUMENT_ROOT'] . '/dbms_project/config/database.php';
$conn = getConnection();

// Get system settings
$settings = [];
$settings_query = "SELECT setting_key, setting_value FROM settings";
$settings_result = mysqli_query($conn, $settings_query);
if ($settings_result) {
    while ($row = mysqli_fetch_assoc($settings_result)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    mysqli_free_result($settings_result);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <title><?php echo isset($settings['company_name']) ? $settings['company_name'] : 'Retail POS System'; ?></title>
    <link rel="stylesheet" href="/dbms_project/assets/css/style.css">
    <!-- Enhanced Dashboard Styles -->
    <link rel="stylesheet" href="/dbms_project/assets/css/enhanced-dashboard.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <!-- Animate.css for additional animations -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
</head>
<body>
    <div class="wrapper">
        <nav class="sidebar">
            <div class="sidebar-header">
                <h3><?php echo isset($settings['company_name']) ? $settings['company_name'] : 'Retail POS System'; ?></h3>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="user-details">
                        <p class="user-name"><?php echo $_SESSION['full_name']; ?></p>
                        <p class="user-role"><?php echo ucfirst($_SESSION['role']); ?></p>
                    </div>
                </div>
            </div>
            
            <ul class="sidebar-menu">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <!-- Admin Menu -->
                    <li class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    </li>
                    <li class="<?php echo $current_page === 'users.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/admin/users.php"><i class="fas fa-users"></i> Users</a>
                    </li>
                    <li class="<?php echo $current_page === 'products.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/admin/products.php"><i class="fas fa-box"></i> Products</a>
                    </li>
                    <li class="<?php echo $current_page === 'categories.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/admin/categories.php"><i class="fas fa-tags"></i> Categories</a>
                    </li>
                    <li class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/admin/reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
                    </li>
                    <li class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/admin/settings.php"><i class="fas fa-cog"></i> Settings</a>
                    </li>
                <?php elseif ($_SESSION['role'] === 'salesperson'): ?>
                    <!-- Enhanced Salesperson Menu -->
                    <li class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/salesperson/dashboard.php"><i class="fas fa-tachometer-alt"></i> Sales Dashboard</a>
                    </li>
                    <li class="<?php echo $current_page === 'pos.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/salesperson/pos.php"><i class="fas fa-cash-register"></i> POS System</a>
                    </li>
                    <li class="<?php echo $current_page === 'customers.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/salesperson/customers.php"><i class="fas fa-users-cog"></i> Customer Management</a>
                    </li>
                    <li class="<?php echo $current_page === 'live_inventory.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/salesperson/live_inventory.php"><i class="fas fa-boxes"></i> Live Inventory</a>
                    </li>
                    <li class="<?php echo $current_page === 'sales_report.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/salesperson/sales_report.php"><i class="fas fa-chart-line"></i> Sales Reports</a>
                    </li>
                <?php elseif ($_SESSION['role'] === 'stock_manager'): ?>
                    <!-- Stock Manager Menu -->
                    <li class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/stock_manager/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    </li>
                    <li class="<?php echo $current_page === 'add_product.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/stock_manager/add_product.php"><i class="fas fa-plus-circle"></i> Add Product</a>
                    </li>
                    <li class="<?php echo $current_page === 'inventory.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/stock_manager/inventory.php"><i class="fas fa-warehouse"></i> Inventory</a>
                    </li>                    
                    <li class="<?php echo $current_page === 'stock_reports.php' ? 'active' : ''; ?>">
                        <a href="/dbms_project/stock_manager/stock_reports.php"><i class="fas fa-chart-line"></i> Stock Reports</a>
                    </li>
                <?php endif; ?>
                
                <!-- Common Menu Items -->
                <li class="<?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
                    <a href="/dbms_project/<?php echo $_SESSION['role']; ?>/profile.php"><i class="fas fa-user"></i> My Profile</a>
                </li>
                <li>
                    <a href="/dbms_project/public/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </li>
            </ul>
        </nav>
        
        <div class="main-content">
            <header class="top-header">
                <button class="sidebar-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div class="header-date">
                    <i class="far fa-calendar-alt"></i> <?php echo date('l, F d, Y'); ?>
                </div>
                
                <div class="header-actions">
                    <div class="dropdown">
                        <button class="dropdown-toggle">
                            <i class="fas fa-user"></i> <?php echo $_SESSION['full_name']; ?> <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-menu">
                            <a href="/dbms_project/<?php echo $_SESSION['role']; ?>/profile.php"><i class="fas fa-user-circle"></i> My Profile</a>
                            <a href="/dbms_project/public/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </header>
            
            <div class="container">
                <!-- Main content will be inserted here -->