<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
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
            
            <form action="login.php" method="post">
                <input type="text" name="username" placeholder="Username or Email" required>
                <input type="password" name="password" placeholder="Password" required>
                
                <div class="options">
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="login-btn">Login</button>
            </form>

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
