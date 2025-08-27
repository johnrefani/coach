<?php
session_start();

require 'connection/db_connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT user_id, username, password, user_type, first_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['login_success'] = true;

            switch ($user['user_type']) {
                case 'Mentee':
                    header("Location: CoachMenteeHome.php");
                    break;
                case 'Mentor':
                    header("Location: CoachMentor.php");
                    break;
                case 'Admin':
                    header("Location: CoachAdmin.php");
                    break;
                case 'Super Admin':
                    header("Location: CoachSuperAdmin.php");
                    break;
                default:
                    header("Location: login.php"); 
                    break;
            }
            exit();

        } else {
            echo "<script>alert('Incorrect password.'); window.location.href='login.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('Username not found.'); window.location.href='login.php';</script>";
        exit();
    }
    
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <link rel="stylesheet" href="css/login.css">
    <link rel="icon" href="uploads/img/coachicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="page-bg"></div>

    <div class="login-container">
        
        <div class="login-box">
            <div class="logo">
                <img src="uploads/img/LogoCoach.png" alt="Logo" />
            </div>
            <div class="login-header">
                <h2>LOGIN</h2>
                <p>Please sign in to continue</p>
            </div>
            
            <form action="" method="post">
                <input type="text" name="username" placeholder="Username or Email" required>
                <input type="password" name="password" placeholder="Password" required>
                
                <div class="options">
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="login-btn">Login</button>
            </form>

            <div class="register-section">
                <span>Don’t have an account?</span>
                <a href="signup.php" class="register-link">Sign Up</a>
            </div>
        </div>

        <div class="info-box">
            <img src="uploads/img/progress.png" alt="Project Illustration" class="illustration">
           <div class="coach-welcome">
                <h3 class="coach-header">Welcome to COACH</h3>
                <p class="coach-subtext">Your hub for mentorship, learning, and staying connected.</p>
            </div>
        </div>
    </div>
</body>
</html>