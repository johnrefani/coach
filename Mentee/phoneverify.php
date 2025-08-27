<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Database credentials
$host = 'localhost';
$db_user = 'root';       // Change to your DB username
$db_pass = '';           // Change to your DB password
$db_name = 'coach';      // Change to your DB name

// Create connection
$conn = new mysqli($host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Get username from session
$username = $_SESSION['username'];

// Fetch first name and mentee icon again
$firstName = '';
$menteeIcon = '';

$sql = "SELECT First_Name, Mentee_Icon FROM mentee_profiles WHERE Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username); // Use $username from session
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['First_Name'];
    $menteeIcon = $row['Mentee_Icon'];
}

// Fetch user's phone number and verification status
$stmt = $conn->prepare("SELECT Contact_Number, Contact_Verification FROM mentee_profiles WHERE Username = ?");
$stmt->bind_param("s", $username); // Use $username from session
$stmt->execute();
$stmt->bind_result($phone, $verified);
$stmt->fetch();
$stmt->close();
$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
  <link rel="stylesheet" href="css/phonestyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>My Profile</title>
  <style>
    
  </style>
</head>
<body>

<!-- Navigation -->
<section class="background" id="home">
  <nav class="navbar">
    <div class="logo">
      <img src="LogoCoach.png" alt="Logo">
      <span>COACH</span>
    </div>

    <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="CoachMenteeHome.php">Home</a></li>
          <li><a href="CoachMentee.php#courses">Courses</a></li>
          <li><a href="CoachMentee.php#resourceLibrary">Resource Library</a></li>
          <li><a href="CoachMenteeActivities.php">Activities</a></li>
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

<!-- Main Content -->
<main class="profile-container">
    <nav class="tabs">
      <button onclick="window.location.href='profile.php'">Profile</button>
      <button onclick="window.location.href='editprof.php'">Edit Profile</button>
      <button onclick="window.location.href='emailverify.php'">Email Verification</button>
      <button class="active" onclick="window.location.href='phoneverify.php'">Phone Verification</button>
      <button onclick="window.location.href='changeuser.php'">Change Username</button>
      <button onclick="window.location.href='resetpass.php'">Reset Password</button>
    </nav>

  <div class="container">
    <h2>Phone Verification</h2>
    <p>Verify your phone number to secure your account and enable SMS notifications.</p>

    <?php if ($verified == 1): ?>
      <div class="verification-status verified">
        <ion-icon name="checkmark-circle"></ion-icon>
        <p>Your phone number is verified</p>
      </div>
    <?php else: ?>
      <div class="verification-status unverified">
        <ion-icon name="alert-circle"></ion-icon>
        <p>Your phone number is not verified</p>
      </div>
    <?php endif; ?>

    <form method="post" action="send.php" id="phoneForm">
      <div class="phone-input-container">
        <label for="phone">Phone Number</label>
        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" <?php echo ($verified == 1) ? : ''; ?>disabled>
      </div>

      <div id="verificationSection" <?php echo ($verified == 1) ? 'style="display:none;"' : ''; ?>>
        <?php if (!empty($phone)): ?>
          <div class="verification-code-container">
            <label for="code">Verification Code</label>
            <input type="text" id="code" name="code" placeholder="Enter verification code">
            <button type="button" id="sendCodeBtn" onclick="sendVerificationCode()">Send Code</button>
          </div>
          <button type="submit" id="verifyBtn">Verify Phone</button>
        <?php else: ?>
          <button type="submit" id="saveBtn">Save Number</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>

<!-- JavaScript -->
<script>
  const profileIcon = document.getElementById('profile-icon');
  const profileMenu = document.getElementById('profile-menu');

  profileIcon.addEventListener('click', function () {
    profileMenu.classList.toggle('active');
  });

  function sendVerificationCode() {
    const phone = document.getElementById('phone').value.trim();
    if (!phone) {
        alert('Please enter a valid phone number');
        return;
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'send.php', true);  // Changed from send_code.php to send.php
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        if (this.status === 200) {
            alert('Verification code sent to your phone number');
        } else {
            alert('Failed to send verification code. Please try again.');
        }
    };
    xhr.send('number=' + encodeURIComponent(phone));  // Changed from 'phone=' to 'number='
}

  function confirmLogout() {
    if (confirm('Are you sure you want to logout?')) {
      window.location.href = 'logout.php';
    }
  }

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

    // Preview image and auto-submit when file selected
    function previewImageAndSubmit(input) {
      if (input.files && input.files[0]) {
        var reader = new FileReader();
        
        reader.onload = function(e) {
          document.getElementById('profilePreview').src = e.target.result;
          // Auto-submit the form when a file is selected
          document.getElementById('submit-pic').click();
        }
        
        reader.readAsDataURL(input.files[0]);
      }
    }
</script>

</body>
</html>