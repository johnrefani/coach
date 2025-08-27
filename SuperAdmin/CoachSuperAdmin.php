<?php
session_start();
if (!isset($_SESSION['superadmin'])) {
    header("Location: loginsuperadmin.php");
    exit();
}

// Database connection
$conn = new mysqli("localhost", "root", "", "coach");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch SuperAdmin data
$username = $_SESSION['superadmin'];
$stmt = $conn->prepare("SELECT SAdmin_Name, SAdmin_Icon FROM SuperAdmin WHERE SAdmin_Username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $_SESSION['superadmin_name'] = $row['SAdmin_Name'];
    
    // Check if SAdmin_Icon exists and is not empty
    if (isset($row['SAdmin_Icon']) && !empty($row['SAdmin_Icon'])) {
        $_SESSION['superadmin_icon'] = $row['SAdmin_Icon'];
    } else {
        $_SESSION['superadmin_icon'] = "img/default_pfp.png";
    }
} else {
    $_SESSION['superadmin_name'] = "SuperAdmin";
    $_SESSION['superadmin_icon'] = "img/default_pfp.png";
}

// Get number of admins
$adminCountQuery = "SELECT COUNT(*) AS total FROM Admins";
$adminCountResult = $conn->query($adminCountQuery);
$adminCount = 0;

if ($adminCountResult && $adminCountResult->num_rows > 0) {
    $row = $adminCountResult->fetch_assoc();
    $adminCount = $row['total'];
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/superadmin_dashboardstyle.css" />
  <link rel="stylesheet" href="css/admin_coursesstyle.css" />
  <link rel="stylesheet" href="css/superadhomestyle.css" />
  <link rel="stylesheet" href="css/clockstyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>SuperAdmin Dashboard</title>
</head>
<body>
  <nav>
    <div class="nav-top">
      <div class="logo">
        <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
        <div class="logo-name">COACH</div>
      </div>

      <div class="admin-profile">
        <img src="<?php echo htmlspecialchars($_SESSION['superadmin_icon']); ?>" alt="SuperAdmin Profile Picture" />
        <div class="admin-text">
          <span class="admin-name"><?php echo htmlspecialchars($_SESSION['superadmin_name']); ?></span>
          <span class="admin-role">SuperAdmin</span>
        </div>
        <a href="CoachSuperAdminPFP.php?username=<?= urlencode($_SESSION['superadmin']) ?>" class="edit-profile-link" title="Edit Profile">
          <ion-icon name="create-outline" class="verified-icon"></ion-icon>
        </a>
      </div>
    </div>

    <div class="menu-items">
      <ul class="navLinks">
        <li class="navList active">
          <a href="CoachSuperAdmin.php">
            <ion-icon name="home-outline"></ion-icon>
            <span class="links">Home</span>
          </a>
        </li>
        <li class="navList">
          <a href="#" onclick="window.location='CoachAdminAdmins.php'">
            <ion-icon name="lock-closed-outline"></ion-icon>
            <span class="links">Moderators</span>
          </a>
        </li>
      </ul>

      <ul class="bottom-link">
        <li class="logout-link">
          <a href="#" onclick="confirmLogout()" style="color: white; text-decoration: none; font-size: 18px;">
            <ion-icon name="log-out-outline"></ion-icon>
            Logout
          </a>
        </li>
      </ul>
    </div>
  </nav>

  <section class="dashboard">
    <div class="top">
      <ion-icon class="navToggle" name="menu-outline"></ion-icon>
      <img src="img/logo.png" alt="Logo">
    </div>

    <div id="homeContent" style="padding: 20px;">
      <section class="widget-section">
        <h2>SuperAdmin <span class="preview">Dashboard</span></h2>

        <section class="clock-section">
          <div class="clock-container">
            <div class="time">
              <span id="hours">00</span>:<span id="minutes">00</span>:<span id="seconds">00</span>
              <span id="ampm">AM</span>
            </div>
            <div class="date" id="date"></div>
          </div>
        </section>

        <div class="widget-grid">
          <div class="widget blue full">
  <div class="details1">
    <h1>COACH Admin Security Hub</h1>
    <p>SuperAdmin Credential Management Panel</p>
    <p>Access Level: Restricted to Admin Account Control</p>
    <p class="note">
      This panel is strictly reserved for secure handling of Admin credentials. 
    </p>
  </div>
</div>
          <div class="widget green full">
            <img src="img/mentor.png" alt="Icon" class="img-icon" />
            <div class="details">
              <h3><?php echo $adminCount; ?></h3>
              <p>MODERATORS</p>
              <span class="note">Total Moderators</span>
            </div>
          </div>
</div>
         <div class="course-details">
  <h2>SuperAdmin Access Panel</h2>
  <p class="course-reminder">
    SuperAdmin privileges are exclusively designated for managing Admin credentials. Please maintain strict 
    confidentiality and ensure that sensitive access information is securely handled. Refrain from accessing 
    course content, mentor areas, or learner data to uphold privacy and system integrity.
  </p>
  <button class="start-course-btn">Manage Admin Credentials</button>
</div>

          
      </section>
    </div>
  </section>

  <script src="admin_mentees.js"></script>
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
  <script>
    function updateClock() {
      const now = new Date();
      let hours = now.getHours();
      const minutes = now.getMinutes();
      const seconds = now.getSeconds();
      const ampm = hours >= 12 ? 'PM' : 'AM';

      hours = hours % 12 || 12;

      document.getElementById('hours').textContent = String(hours).padStart(2, '0');
      document.getElementById('minutes').textContent = String(minutes).padStart(2, '0');
      document.getElementById('seconds').textContent = String(seconds).padStart(2, '0');
      document.getElementById('ampm').textContent = ampm;

      const options = { weekday: 'short', day: '2-digit', month: 'long', year: 'numeric' };
      document.getElementById('date').textContent = now.toLocaleDateString('en-US', options);
    }

    setInterval(updateClock, 1000);
    updateClock();

    function confirmLogout() {
      if (confirm("Are you sure you want to log out?")) {
        window.location.href = "logout.php";
      }
    }
  </script>
</body>
</html>