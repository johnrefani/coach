<?php
// Start session (if not already started)
session_start();

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get username from session
$username = $_SESSION['username'];

// Prepare SQL statement to prevent SQL injection
$sql = "SELECT First_Name, Last_Name, Username, DOB, Gender, Email, Email_Verification, Contact_Number, Contact_Verification, Mentee_Icon 
        FROM mentee_profiles 
        WHERE Username = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $name = $row['First_Name'] . " " . $row['Last_Name'];
    $username = $row['Username'];
    $dob = $row['DOB'];
    $gender = $row['Gender'];
    $email = $row['Email'];
    $email_verification = $row['Email_Verification'];
    $contact = $row['Contact_Number'];
    $mobile_verification = $row['Contact_Verification'];
    $profile_picture = $row['Mentee_Icon'];
} else {
    echo "Error: User profile not found";
    exit();
}

$stmt->close();

// Function to display verification status
function getVerificationStatus($status) {
  if (is_null($status) || strtolower($status) === 'pending' || $status == 0) {
      return '<span class="pending">Pending</span>';
  } elseif (strtolower($status) === 'active' || $status == 1) {
      return '<span class="active">Active</span>';
  } else {
      return '<span class="pending">Pending</span>';
  }
}

// Function to get profile picture path
function getProfilePicture($profile_picture) {
    return (!empty($profile_picture)) ? $profile_picture : "img/default_pfp.png";
}

// Get the correct profile picture path
$profile_picture_path = getProfilePicture($profile_picture);

// Fetch first name and mentee icon again
$firstName = '';
$menteeIcon = '';

$sql = "SELECT First_Name, Mentee_Icon FROM mentee_profiles WHERE Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['First_Name'];
    $menteeIcon = $row['Mentee_Icon'];
}

$stmt->close();
$conn->close(); // Close the connection only after all queries are done
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
  <link rel="stylesheet" href="css/profilestyle.css" />
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


    <main class="profile-container">
    <nav class="tabs">
      <button class="active" onclick="window.location.href='profile.php'">Profile</button>
      <button onclick="window.location.href='editprof.php'">Edit Profile</button>
      <button onclick="window.location.href='emailverify.php'">Email Verification</button>
      <button onclick="window.location.href='phoneverify.php'">Phone Verification</button>
      <button onclick="window.location.href='changeuser.php'">Change Username</button>
      <button onclick="window.location.href='resetpass.php'">Reset Password</button>
    </nav>

      <section class="profile-card">
        <div class="profile-left">
          <!-- Using the profile picture path here -->
          <img src="<?php echo $profile_picture_path; ?>" alt="Profile Picture" />
          <h2><?php echo $name; ?></h2>
          <p><?php echo $username; ?></p>
        </div>

        <div class="profile-right">
          <div class="info-row"><span>Name</span><span><?php echo $name; ?></span></div>
          <div class="info-row"><span>Username</span><span><?php echo $username; ?></span></div>
          <div class="info-row"><span>Date of Birth</span><span><?php echo $dob; ?></span></div>
          <div class="info-row"><span>Gender</span><span><?php echo $gender; ?></span></div>
          <div class="info-row"><span>Email</span><span><?php echo $email; ?></span></div>
          <div class="info-row"><span>Email</span><span>Email</span></div>
          <div class="info-row"><span>Email</span><span><?php echo $email; ?></span></div>
          <div class="info-row">
            <span>Email Verification</span>
            <?php echo getVerificationStatus($email_verification); ?>
          </div>
          <div class="info-row"><span>Contact</span><span><?php echo $contact; ?></span></div>
          <div class="info-row">
            <span>Mobile verification</span>
            <?php echo getVerificationStatus($mobile_verification); ?>
          </div>
        </div>
      </section>
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
    </script>
      <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
      <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</body>
</html>