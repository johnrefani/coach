<?php
session_start();

// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Mentee'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    // If not a Mentee, redirect to the login page.
    header("Location: login.php");
    exit();
}

// --- FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

$username = $_SESSION['username'];
$message = "";
$error = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Check if new passwords match
    if ($new_password !== $confirm_password) {
        $error = "New passwords do not match!";
    } else {
        // Verify current password
        $sql = "SELECT password FROM users WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stored_hashed_password = $row['password'];

            // Verify current password
            if (password_verify($current_password, $stored_hashed_password)) {
                // Hash the new password
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

                // Update password in database
                $update_sql = "UPDATE users SET password = ? WHERE username = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ss", $hashed_new_password, $username);

                if ($update_stmt->execute()) {
                    $message = "Password updated successfully!";
                } else {
                    $error = "Error updating password: " . $conn->error;
                }
                $update_stmt->close();
            } else {
                $error = "Current password is incorrect!";
            }
        } else {
            $error = "User not found!";
        }
        $stmt->close();
    }
}

// Fetch first name and mentee icon
$firstName = '';
$menteeIcon = '';

$sql = "SELECT first_name, icon FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username); // Using $username here
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['first_name'];
    $menteeIcon = $row['icon'];
}

$stmt->close();
$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/reset-password.css" />
  <link rel="stylesheet" href="css/message.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>Reset Password</title>
  </style>
</head>
<body>
     <!-- Navigation Section -->
     <section class="background" id="home">
        <nav class="navbar">
          <div class="logo">
            <img src="../uploads/img/LogoCoach.png" alt="Logo">
            <span>COACH</span>
          </div>
    
          <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="home.php">Home</a></li>
          <li><a href="course.php">Courses</a></li>
          <li><a href="resource_library.php">Resource Library</a></li>
          <li><a href="activities.php">Activities</a></li>
          <li><a href="forum-chat.php">Sessions</a></li>
          <li><a href="forums.php">Forums</a></li>
        </ul>
      </div>
    
          <div class="nav-profile">
  <a href="#" id="profile-icon">
    <?php if (!empty($menteeIcon)): ?>
      <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 35px; height: 35px; border-radius: 50%;">
    <?php else: ?>
      <ion-icon name="person-circle-outline" style="font-size: 35px;"></ion-icon>
    <?php endif; ?>
  </a>
</div>

<div class="sub-menu-wrap hide" id="profile-menu">
  <div class="sub-menu">
    <div class="user-info">
      <div class="user-icon">
        <?php if (!empty($menteeIcon)): ?>
          <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 40px; height: 40px; border-radius: 50%;">
        <?php else: ?>
          <ion-icon name="person-circle-outline" style="font-size: 40px;"></ion-icon>
        <?php endif; ?>
      </div>
      <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
    </div>
    <ul class="sub-menu-items">
      <li><a href="profile.php">Profile</a></li>
      <li><a href="#settings">Settings</a></li>
      <li><a href="#" onclick="confirmLogout()">Logout</a></li>
    </ul>
  </div>
</div>
        </nav>
    </section>


    <main class="profile-container">
    <nav class="tabs">
      <button onclick="window.location.href='profile.php'">Profile</button>
      <button onclick="window.location.href='edit-profile.php'">Edit Profile</button>
      <button onclick="window.location.href='verify-email.php'">Email Verification</button>
      <button onclick="window.location.href='verify-phone.php'">Phone Verification</button>
      <button onclick="window.location.href='edit-username.php'">Change Username</button>
      <button class="active" onclick="window.location.href='reset-password.php'">Reset Password</button>
    </nav>

    <div class="container">
      <h2>Reset Password</h2>
      
      <?php if($message): ?>
        <div class="message"><?php echo $message; ?></div>
      <?php endif; ?>
      
      <?php if($error): ?>
        <div class="error"><?php echo $error; ?></div>
      <?php endif; ?>
      
      <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="resetPasswordForm">
        <label>Current Password 
          <input type="password" name="current_password" id="current_password" placeholder="Enter current password" required>
        </label>
        
        <label>New Password 
          <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required>
          <div class="password-requirements">
            Password should be at least 8 characters long and include letters and numbers
          </div>
        </label>
        
        <label>Confirm New Password 
          <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required>
        </label>
        
        <button type="submit">Reset Password</button>
      </form>
    </div>
  </main>

  <script>
    document.getElementById('profile-icon').addEventListener('click', function(e) {
      e.preventDefault();
      const profileMenu = document.getElementById('profile-menu');
      profileMenu.classList.toggle('show');
      profileMenu.classList.remove('hide');
    });

    // Close menu when clicking elsewhere
    window.addEventListener('click', function(e) {
      const profileIcon = document.getElementById('profile-icon');
      const profileMenu = document.getElementById('profile-menu');
      if (!profileMenu.contains(e.target) && !profileIcon.contains(e.target)) {
        profileMenu.classList.remove('show');
        profileMenu.classList.add('hide');
      }
    });

    // Confirm logout
    function confirmLogout() {
      if (confirm("Are you sure you want to logout?")) {
        window.location.href = '../logout.php';
      }
    }

    // Form validation
    document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
      const newPassword = document.getElementById('new_password').value;
      const confirmPassword = document.getElementById('confirm_password').value;
      
      // Validate password strength
      if (newPassword.length < 8) {
        alert('Password must be at least 8 characters long');
        e.preventDefault();
        return;
      }
      
      // Check if password contains both letters and numbers
      if (!/[A-Za-z]/.test(newPassword) || !/[0-9]/.test(newPassword)) {
        alert('Password must include both letters and numbers');
        e.preventDefault();
        return;
      }
      
      // Check if passwords match
      if (newPassword !== confirmPassword) {
        alert('Passwords do not match');
        e.preventDefault();
        return;
      }
    });
  </script>
</body>
</html>