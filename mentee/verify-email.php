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
$sql = "SELECT first_name, last_name, username, email, email_verification, icon 
        FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['first_name'];
    $lastName = $row['last_name'];
    $email = $row['email'];
    $email_verification = $row['email_verification'];
    $menteeIcon = $row['icon'];
} else {
    echo "User not found.";
    exit();
}
$stmt->close();

function getStatusBadge($status) {
    if (is_null($status) || strtolower($status) !== 'active') {
        return '<span class="badge pending">Pending</span>';
    } else {
        return '<span class="badge active">Active</span>';
    }
}

// Handle form actions
$status_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Send code (Initial)
    if (isset($_POST['send_code'])) {
        $code = rand(100000, 999999);
        $_SESSION['email_code'] = $code;
        $_SESSION['email_code_time'] = time();

        $subject = "Your Email Verification Code";
        $message = "Your verification code is: $code";
        $headers = "From: COACH <your_email@gmail.com>\r\n";

        if (mail($email, $subject, $message, $headers)) {
            $status_message = "Verification code sent to $email.";
        } else {
            $status_message = "Failed to send email. Check SMTP settings.";
        }
    }

    // Resend code
    elseif (isset($_POST['resend_code'])) {
        $code = rand(100000, 999999);
        $_SESSION['email_code'] = $code;
        $_SESSION['email_code_time'] = time();

        $subject = "Your Email Verification Code";
        $message = "Your verification code is: $code";
        $headers = "From: COACH <your_email@gmail.com>\r\n";

        if (mail($email, $subject, $message, $headers)) {
            $status_message = "Verification code resent to $email.";
        } else {
            $status_message = "Failed to resend email.";
        }
    }

    // Verify code
    elseif (isset($_POST['verify_code'])) {
        $input_code = $_POST['code'] ?? '';
        $saved_code = $_SESSION['email_code'] ?? null;
        $timestamp = $_SESSION['email_code_time'] ?? 0;

        if ($saved_code && time() - $timestamp <= 300) {
            if ($input_code == $saved_code) {
                $stmt = $conn->prepare("UPDATE users SET email_verification='Active' WHERE username=?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $status_message = "Email verified successfully!";
                $email_verification = "Active";
                unset($_SESSION['email_code']);
            } else {
                $status_message = "Invalid verification code.";
            }
        } else {
            $status_message = "Code expired or not found. Please resend.";
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/verify-email.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>My Profile</title>
</head>
<body>
     <!-- Navigation Section -->
     <section class="background" id="home">
        <nav class="navbar">
          <div class="logo">
            <img src="LogoCoach.png" alt="Logo">
            <span>COACH</span>
          </div>
    
          <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="home.php">Home</a></li>
          <li><a href="course.php">Courses</a></li>
          <li><a href="resourcelibrary.php">Resource Library</a></li>
          <li><a href="activities.php">Activities</a></li>
          <li><a href="forum-chat.php">Sessions</a></li>
          <li><a href="group-chat.php">Forums</a></li>
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
      <button class="active" onclick="window.location.href='verify-email.php'">Email Verification</button>
      <button onclick="window.location.href='verify-phone.php'">Phone Verification</button>
      <button onclick="window.location.href='edit-username.php'">Edit Username</button>
      <button onclick="window.location.href='reset-password.php'">Reset Password</button>
    </nav>

    <div class="container">
      <h2>Email Verification</h2>
      <p>Verify your email address to ensure account security and receive notifications.</p>
      <?php if ($email_verification !== 'Active') : ?>
  <div style="background-color: #ffe5e5; color: #a94442; border: 1px solid #f5c6cb; padding: 10px 15px; border-radius: 8px; margin-bottom: 20px;">
    <strong>❗ Your Email Is Not Verified</strong>
  </div>
<?php else : ?>
  <div style="background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 10px 15px; border-radius: 8px; margin-bottom: 20px;">
    ✅ Your Email Is Verified
  </div>
<?php endif; ?>


      <?php if (!empty($status_message)) : ?>
        <p class="status-msg"><?php echo htmlspecialchars($status_message); ?></p>
      <?php endif; ?>

      <form method="POST">
        <label>
          Current Email:
          <input type="email" style="text-transform: none" value="<?php echo htmlspecialchars($email); ?>" disabled>
        </label>
        <label>
          Enter Verification Code:
          <input type="text" name="code" placeholder="Enter code" required>
        </label>
        <button type="submit" name="verify_code">Verify Email</button>
      </form>

      <form method="POST" class="code-buttons">
        <button type="submit" name="send_code" id="sendBtn">Send Code</button>
      </form>
    </div>
  </main>

  <script src="mentee.js"></script>
    <script>
      // Toggle profile menu
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
      
      // Logout confirmation
      function confirmLogout() {
        if (confirm("Are you sure you want to logout?")) {
          window.location.href = "logout.php";
        }
      }

    let countdown = 30;
    const timer = document.getElementById("timer");
    const resendBtn = document.getElementById("resendBtn");

    const interval = setInterval(() => {
      countdown--;
      timer.textContent = countdown;
      if (countdown <= 0) {
        clearInterval(interval);
        resendBtn.disabled = false;
        resendBtn.textContent = "Resend Code";
      }
    }, 1000);
  </script>
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</body>
</html>
