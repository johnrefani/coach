<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

// Connect to the database
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Redirect if mentee not logged in
if (!isset($_SESSION['username'])) {
  header("Location: login_mentee.php");
  exit();
}

$menteeUsername = $_SESSION['username'];
$firstName = '';
$menteeIcon = '';

// Fetch assigned quizzes for the mentee
$sql = "SELECT * FROM QuizAssignments WHERE Mentee_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $menteeUsername);
$stmt->execute();
$result = $stmt->get_result();

$assignments = [];
while ($row = $result->fetch_assoc()) {
  $course = $row['Course_Title'];

  // Check if the mentee already submitted the quiz
  $scoreStmt = $conn->prepare("SELECT Score, Total_Questions, Date_Taken FROM menteescores WHERE Username = ? AND Course_Title = ?");
  $scoreStmt->bind_param("ss", $menteeUsername, $course);
  $scoreStmt->execute();
  $scoreResult = $scoreStmt->get_result();
  $existingScore = $scoreResult->fetch_assoc();

  $row['already_taken'] = $existingScore ? true : false;
  $row['score_data'] = $existingScore;
  $assignments[] = $row;

  $scoreStmt->close();
}

// ✅ Fix: Use $menteeUsername instead of $username
$sql = "SELECT First_Name, Mentee_Icon FROM mentee_profiles WHERE Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $menteeUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  $row = $result->fetch_assoc();
  $firstName = $row['First_Name'];
  $menteeIcon = $row['Mentee_Icon'];
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
  <link rel="stylesheet" href="css/mentee_courses.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>Mentee Dashboard</title>
  <style>
    body {
      margin-top: 30px;
      font-family: Arial, sans-serif;
      
    }
    .container {
      max-width: 800px;
      margin: auto;
      background: #fff;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h2 {
      color: #6a1b9a;
      margin-bottom: 15px;
    }
    .assignment-box {
      background-color: #ede7f6;
      border: 1px solid #d1c4e9;
      padding: 15px;
      margin-bottom: 15px;
      border-radius: 6px;
    }
    .assignment-box h3 {
      margin: 0;
      color: #4a148c;
    }
    .assignment-box button {
      margin-top: 10px;
      background-color: #7b1fa2;
      color: white;
      padding: 10px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    .assignment-box button:hover {
      background-color: #6a1b9a;
    }
    .note {
      color: green;
      font-weight: bold;
      margin-top: 10px;
    }
    .review-form {
      display: inline;
    }
  </style>
</head>
<body>
  <section class="background" id="home">
    <nav class="navbar">
      <div class="logo">
        <img src="img/LogoCoach.png" alt="Logo">
        <span>COACH</span>
      </div>

      <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="CoachMenteeHome.php">Home</a></li>
          <li><a href="CoachMentee.php#courses">Courses</a></li>
          <li><a href="CoachMentee.php#resourceLibrary">Resource Library</a></li>
          <li><a href="CoachMenteeActivities.php">Activities</a></li>
          <li><a href="forum-chat.php">Sessions</a></li>
          <li><a href="#Forums">Forums</a></li>
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

  <!-- Activities Section -->

<div class="container">
  <h2>Assigned Activities</h2>
  <?php if (count($assignments) > 0): ?>
    <?php foreach ($assignments as $assignment): ?>
      <div class="assignment-box">
        <h3>Course: <?= htmlspecialchars($assignment['Course_Title']) ?></h3>
        <p>Date Assigned: <?= htmlspecialchars($assignment['Date_Assigned']) ?></p>

        <?php if ($assignment['already_taken']): ?>
          <p class="note">
            You have already taken this quiz.<br>
            Score: <?= htmlspecialchars($assignment['score_data']['Score']) ?> / <?= htmlspecialchars($assignment['score_data']['Total_Questions']) ?><br>
            Date Taken: <?= htmlspecialchars($assignment['score_data']['Date_Taken']) ?>
          </p>

          <!-- Review Activity Button -->
          <form action="CoachReviewAssessment.php" method="get" class="review-form">
            <input type="hidden" name="course_title" value="<?= htmlspecialchars($assignment['Course_Title']) ?>">
            <button type="submit">Review Activity</button>
          </form>

        <?php else: ?>
          <!-- Take Quiz Button -->
          <form action="CoachMenteeAssessment.php" method="get">
            <input type="hidden" name="course_title" value="<?= htmlspecialchars($assignment['Course_Title']) ?>">
            <button type="submit">Check Activity</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p>No assigned quizzes at the moment.</p>
  <?php endif; ?>
</div>

<script src="mentee.js"></script>
<script>
  // Profile menu toggle functionality
  const profileIcon = document.getElementById("profile-icon");
  const profileMenu = document.getElementById("profile-menu");
  
  if (profileIcon && profileMenu) {
    profileIcon.addEventListener("click", function (e) {
      e.preventDefault();
      console.log("Profile icon clicked");
      profileMenu.classList.toggle("show");
      profileMenu.classList.toggle("hide");
    });
    
    // Close menu when clicking outside
    document.addEventListener("click", function (e) {
      if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target)) {
        profileMenu.classList.remove("show");
        profileMenu.classList.add("hide");
      }
    });
  } else {
    console.error("Profile menu elements not found");
  }
  
    function confirmLogout() {
    var confirmation = confirm("Are you sure you want to log out?");
    if (confirmation) {
      // If the user clicks "OK", redirect to logout.php
      window.location.href = "logout.php";
    } else {
      // If the user clicks "Cancel", do nothing
      return false;
    }
  }
  </script>
</body>
</html>
