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
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            animation: gradientBG 15s ease infinite;
            background-size: 400% 400%;
        }
        
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .login-container {
            width: 400px;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            box-shadow: 0 5px 30px rgba(0, 0, 0, 0.3);
            padding: 40px;
            backdrop-filter: blur(10px);
            transform: translateY(0);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
        }
          .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            font-size: 28px;
            color: #2c3e50;
            margin-bottom: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-shadow: 1px 1px 1px rgba(0,0,0,0.1);
        }
        
        .login-header p {
            color: #555;
            margin: 0;
            font-size: 16px;
            letter-spacing: 0.3px;
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
            transition: all 0.3s ease;
        }
        
        .form-actions {
            margin-top: 30px;
        }
        
        .btn {
            display: inline-block;
            font-weight: 500;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            user-select: none;
            border: 1px solid transparent;
            padding: 0.5rem 1rem;
            font-size: 1rem;
            line-height: 1.5;
            border-radius: 0.25rem;
            transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .btn:after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .btn:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        
        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            20% {
                transform: scale(25, 25);
                opacity: 0.3;
            }
            100% {
                opacity: 0;
                transform: scale(40, 40);
            }
        }
          .btn-primary {
            color: #fff;
            background: linear-gradient(135deg, #4CAF50, #2E7D32);
            border-color: #4CAF50;
            box-shadow: 0 3px 5px rgba(46, 125, 50, 0.2);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #43A047, #2E7D32);
            border-color: #2E7D32;
            box-shadow: 0 5px 15px rgba(46, 125, 50, 0.3);
            transform: translateY(-2px);
        }
        
        .btn-block {
            display: block;
            width: 100%;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            font-size: 0.95rem;
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