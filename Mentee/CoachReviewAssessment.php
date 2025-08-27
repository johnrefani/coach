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

if (!isset($_GET['course_title'])) {
  echo "No course selected.";
  exit();
}

$menteeUsername = $_SESSION['username'];
$courseTitle = $_GET['course_title'];

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Fetch answered questions
$sql = "SELECT Question, Selected_Answer, Correct_Answer, Is_Correct FROM mentee_answers 
        WHERE Username = ? AND Course_Title = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $menteeUsername, $courseTitle);
$stmt->execute();
$result = $stmt->get_result();

$answers = [];
while ($row = $result->fetch_assoc()) {
  // Here we use the 'Question' from the mentee_answers table directly
  $row['Question_Text'] = $row['Question'] ?? "Question not found.";
  $answers[] = $row;
}

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
  <title>Review Activity - <?= htmlspecialchars($courseTitle) ?></title>
  <style>
    body {
      margin-top: 30px;
      font-family: Arial, sans-serif;
    }
    .container {
      max-width: 900px;
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
    .question-box {
      background-color: #ede7f6;
      border: 1px solid #d1c4e9;
      padding: 15px;
      margin-bottom: 15px;
      border-radius: 6px;
    }
    .correct {
      color: green;
      font-weight: bold;
    }
    .incorrect {
      color: red;
      font-weight: bold;
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
          <li><a href="#sessions">Sessions</a></li>
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


<div class="container">
  <h2>Review of <?= htmlspecialchars($courseTitle) ?> Activity</h2>

  <?php if (count($answers) > 0): ?>
    <?php foreach ($answers as $index => $ans): ?>
      <div class="question-box">
        <p><strong>Q<?= $index + 1 ?>:</strong> <?= htmlspecialchars($ans['Question_Text']) ?></p>
        <p>Your Answer: 
          <span class="<?= $ans['Is_Correct'] ? 'correct' : 'incorrect' ?>">
            <?= htmlspecialchars($ans['Selected_Answer']) ?>
          </span>
        </p>
        <?php if (!$ans['Is_Correct']): ?>
          <p>Correct Answer: <span class="correct"><?= htmlspecialchars($ans['Correct_Answer']) ?></span></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p>No answers found for this course.</p>
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
