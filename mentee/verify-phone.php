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

// Get username from session
$username = $_SESSION['username'];

// Fetch first name and mentee icon again
$firstName = '';
$menteeIcon = '';

$sql = "SELECT first_name, icon FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username); // Use $username from session
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['first_name'];
    $menteeIcon = $row['icon'];
}

// Fetch user's phone number and verification status
$stmt = $conn->prepare("SELECT contact_number, contact_verification FROM users WHERE username = ?");
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
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/verify-phone.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
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

<!-- Main Content -->
<main class="profile-container">
    <nav class="tabs">
      <button onclick="window.location.href='profile.php'">Profile</button>
      <button onclick="window.location.href='edit-profile.php'">Edit Profile</button>
      <button onclick="window.location.href='verify-email.php'">Email Verification</button>
      <button class="active" onclick="window.location.href='verify-phone.php'">Phone Verification</button>
      <button onclick="window.location.href='edit-username.php'">Edit Username</button>
      <button onclick="window.location.href='reset-password.php'">Reset Password</button>
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
document.addEventListener("DOMContentLoaded", function() {
    // Select all necessary elements for the new logic
    const profileIcon = document.getElementById("profile-icon"); // Already defined outside the DOMContentLoaded
    const profileMenu = document.getElementById("profile-menu");   // Already defined outside the DOMContentLoaded
    // NEW: Logout Dialog elements
    const logoutDialog = document.getElementById("logoutDialog");
    const cancelLogoutBtn = document.getElementById("cancelLogout");
    const confirmLogoutBtn = document.getElementById("confirmLogoutBtn");

    // --- Profile Menu Toggle Logic (Adapted from the new script) ---
    // The existing logic outside this block is a bit fragmented, combining it here for coherence.
    if (profileIcon && profileMenu) {
        // Remove the outside event listener for profileIcon to prevent double-toggle
        // We'll rely on the document.addEventListener('DOMContentLoaded', ...) block
        
        profileIcon.addEventListener("click", function (e) {
            e.preventDefault();
            // Using 'show' and 'hide' class toggles from the original script
            profileMenu.classList.toggle("show");
            profileMenu.classList.toggle("hide");
        });
        
        // Close menu when clicking elsewhere
        document.addEventListener("click", function (e) {
            if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target) && !e.target.closest('#profile-menu')) {
                profileMenu.classList.remove("show");
                profileMenu.classList.add("hide");
            }
        });
    }

    // --- Logout Dialog Logic (New) ---
    // Make confirmLogout function globally accessible for the onclick in HTML
    window.confirmLogout = function(e) { 
        if (e) e.preventDefault();
        if (logoutDialog) {
            logoutDialog.style.display = "flex";
        }
    }

    // Attach event listeners to the dialog buttons
    if (cancelLogoutBtn && logoutDialog) {
        cancelLogoutBtn.addEventListener("click", function(e) {
            e.preventDefault(); 
            logoutDialog.style.display = "none";
        });
    }

    if (confirmLogoutBtn) {
        confirmLogoutBtn.addEventListener("click", function(e) {
            e.preventDefault(); 
            // Redirect to the login page (or logout script)
            window.location.href = "../login.php"; 
        });
    }

    // --- Original sendVerificationCode() and previewImageAndSubmit() remain globally accessible ---
});

// Original logic that must remain globally accessible (outside DOMContentLoaded)

// --- Fragmented Profile Icon Logic (Keeping the parts that are NOT duplicated in DOMContentLoaded) ---
const profileIcon = document.getElementById('profile-icon');
const profileMenu = document.getElementById('profile-menu');

// NOTE: The event listener below will be superseded by the one inside DOMContentLoaded for better management.
/*
profileIcon.addEventListener('click', function () {
    profileMenu.classList.toggle('active');
});
*/

// --- Original sendVerificationCode() function (Must remain) ---
function sendVerificationCode() {
    const phone = document.getElementById('phone').value.trim();
    if (!phone) {
        alert('Please enter a valid phone number');
        return;
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'send_phone_code.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        if (this.status === 200) {
            alert('Verification code sent to your phone number');
        } else {
            alert('Failed to send verification code. Please try again.');
        }
    };
    xhr.send('number=' + encodeURIComponent(phone));
}

// The old confirmLogout is replaced by the window.confirmLogout inside DOMContentLoaded.
/* function confirmLogout() {
    if (confirm('Are you sure you want to logout?')) {
      window.location.href = '../login.php';
    }
}
*/

// The original logic for toggling and closing the profile menu is now consolidated/replaced 
// within the DOMContentLoaded block above.

// --- Original previewImageAndSubmit() function (Must remain) ---
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

<div id="logoutDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3>Confirm Logout</h3>
        <p>Are you sure you want to log out?</p>
        <div class="dialog-buttons">
            <button id="cancelLogout" type="button">Cancel</button>
            <button id="confirmLogoutBtn" type="button">Logout</button>
        </div>
    </div>
</body>
</html>