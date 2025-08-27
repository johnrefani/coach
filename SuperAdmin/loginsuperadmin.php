<?php
session_start();
$error = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username']) && isset($_POST['password'])) {
    // Database connection
    $host = "localhost";
    $dbname = "coach";
    $dbuser = "root";
    $dbpass = "";

    try {
        $conn = new mysqli($host, $dbuser, $dbpass, $dbname);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }

        $username = $_POST['username'];
        $password = $_POST['password'];

        // Check credentials
        $stmt = $conn->prepare("SELECT SAdmin_Password FROM SuperAdmin WHERE SAdmin_Username = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $username);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($hashedPassword);
            $stmt->fetch();

            if (password_verify($password, $hashedPassword)) {
                $_SESSION['superadmin'] = $username;
                header("Location: CoachSuperAdmin.php");
                exit();
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }

        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        $error = "System error occurred. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuperAdmin Login</title>
    <link rel="stylesheet" href="css/loginstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
</head>
<body>
    <!-- Background -->
    <div class="page-bg"></div>

    <!-- Login Container -->
    <div class="login-container">
        
        <!-- LEFT: Login Form -->
        <div class="login-box">
            <div class="logo">
                <img src="img/LogoCoach.png" alt="Logo" />
            </div>
           <div class="login-header">
    <h2>LOGIN</h2>
    <p>Please sign in to continue</p>
</div>
            
           <form method="post" action="">
                <input type="text" name="username" placeholder="Username or Email" required>
                <input type="password" name="password" placeholder="Password" required>
                
                <div class="options">
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="login-btn">Login</button>
            </form>
            <?php if (!empty($error)): ?>
            <p style="color: red; text-align: center; margin-top: 10px;"><?php echo $error; ?></p>
        <?php endif; ?>

            <div class="register-section">
    <span>Don’t have an account?</span>
    <a href="signup_mentee.php" class="register-link">Sign Up</a>
</div>
        </div>

        <!-- RIGHT: Illustration & Text -->
        <div class="info-box">
            <img src="img/progress.png" alt="Project Illustration" class="illustration">
           <div class="coach-welcome">
    <h3 class="coach-header">Welcome to COACH</h3>
<p class="coach-subtext">Your hub for mentorship, learning, and staying connected.</p>
</div>
            </div>
        </div>
    </div>
</body>
</html>
