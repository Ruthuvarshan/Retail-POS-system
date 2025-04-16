<?php
// Start session
session_start();

// If user is already logged in, redirect to the appropriate dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: ../admin/dashboard.php');
    } elseif ($_SESSION['role'] === 'salesperson') {
        header('Location: ../salesperson/dashboard.php');
    } elseif ($_SESSION['role'] === 'stock_manager') {
        header('Location: ../stock_manager/dashboard.php');
    }
    exit;
}

// Process login request
$error_message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../config/database.php';
    $conn = getConnection();
    
    // Sanitize inputs
    $username = sanitizeInput($_POST['username'], $conn);
    $password = $_POST['password']; // We'll verify the password directly against database
    
    // Query to check user credentials
    $query = "SELECT user_id, username, full_name, role, status FROM users WHERE username = ? AND password = ? AND status = 'active'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        
        // Update last login time
        $update_query = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("i", $user['user_id']);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Redirect to appropriate dashboard based on role
        if ($user['role'] === 'admin') {
            header('Location: ../admin/dashboard.php');
        } elseif ($user['role'] === 'salesperson') {
            header('Location: ../salesperson/dashboard.php');
        } elseif ($user['role'] === 'stock_manager') {
            header('Location: ../stock_manager/dashboard.php');
        }
        exit;
    } else {
        $error_message = "Invalid username or password. Please try again.";
    }
    
    // Close the database connection
    mysqli_close($conn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retail POS - Login</title>
    <link rel="stylesheet" href="/dbms_project/assets/css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <h1>Retail POS System</h1>
            <p>Enter your credentials to access the system</p>
        </div>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <form class="login-form" action="index.php" method="post">
            <div class="form-group">
                <label for="username"><i class="fas fa-user"></i> Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </div>
        </form>
        
        <div class="login-footer">
            <p>&copy; <?php echo date('Y'); ?> Retail POS System. All rights reserved.</p>
        </div>
    </div>
    
    <style>
        body.login-page {
            background-color:rgb(0, 255, 255);
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
        }
        
        .login-container {
            width: 400px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgb(255, 0, 0);
            padding: 30px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: #777;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }
        
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 16px;
        }
        
        .form-group input:focus {
            border-color: #4CAF50;
            outline: none;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        .form-actions {
            margin-top: 30px;
        }
        
        .btn {
            display: inline-block;
            font-weight: 400;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            user-select: none;
            border: 1px solid transparent;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            line-height: 1.5;
            border-radius: 0.25rem;
            transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            cursor: pointer;
        }
        
        .btn-primary {
            color: #fff;
            background-color: #4CAF50;
            border-color: #4CAF50;
        }
        
        .btn-primary:hover {
            background-color: #45a049;
            border-color: #45a049;
        }
        
        .btn-block {
            display: block;
            width: 100%;
            padding: 12px;
        }
        
        .login-footer {
            margin-top: 30px;
            text-align: center;
            color: #777;
            font-size: 14px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 3px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .fas {
            margin-right: 5px;
        }
    </style>
</body>
</html>