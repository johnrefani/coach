<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Database credentials
    $host = "localhost";
    $dbUsername = "root";
    $dbPassword = "";
    $dbName = "coach";

    // Create connection
    $conn = new mysqli($host, $dbUsername, $dbPassword, $dbName);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Get input
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Fetch admin by username only
    $sql = "SELECT * FROM admins WHERE Admin_Username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();

        // Use the correct case for column name
        $hashedPassword = $row['Admin_Password'];

        if (password_verify($password, $hashedPassword)) {
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_id'] = $row['Admin_ID']; // Also case-sensitive
            $stmt->close();
            $conn->close();
            header("Location: CoachAdmin.php");
            exit();
        }
    }

    $stmt->close();
    $conn->close();

    // Login failed
    echo "<script>
        alert('localhost says: Incorrect username or password.');
        window.location.href = 'loginadmin.php';
    </script>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderator Login</title>
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
