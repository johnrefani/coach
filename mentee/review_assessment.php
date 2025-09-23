<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// --- ACCESS CONTROL ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    header("Location: login.php");
    exit();
}

// --- FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

// Redirect if mentee not logged in or required GET parameters missing
if (!isset($_SESSION['username']) || 
    !isset($_GET['course_title']) || 
    !isset($_GET['activity_title']) || 
    !isset($_GET['difficulty_level'])) {
    header("Location: activities.php");
    exit();
}

$menteeUsername = $_SESSION['username'];
$menteeUserId = $_SESSION['user_id'];
$courseTitle = $_GET['course_title'];
$activityTitle = $_GET['activity_title'];
$difficultyLevel = $_GET['difficulty_level'];

// Fetch mentee profile
$firstName = '';
$menteeIcon = '';
$stmt = $conn->prepare("SELECT First_Name, icon FROM users WHERE Username = ?");
$stmt->bind_param("s", $menteeUsername);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['First_Name'];
    $menteeIcon = $row['icon'];
}
$stmt->close();

// ✅ Fetch only the latest attempt’s answers for this activity
$sql = "SELECT Question, Selected_Answer, Correct_Answer, Is_Correct
        FROM mentee_answers
        WHERE user_id = ?
          AND Course_Title = ?
          AND Activity_Title = ?
          AND Difficulty_Level = ?
          AND Attempt_Number = (
              SELECT MAX(Attempt_Number)
              FROM mentee_answers
              WHERE user_id = ?
                AND Course_Title = ?
                AND Activity_Title = ?
                AND Difficulty_Level = ?
          )";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "isssisss",
    $menteeUserId, $courseTitle, $activityTitle, $difficultyLevel,
    $menteeUserId, $courseTitle, $activityTitle, $difficultyLevel
);
$stmt->execute();
$result = $stmt->get_result();

$answers = [];
while ($row = $result->fetch_assoc()) {
    $row['Question_Text'] = $row['Question'] ?? "Question not found.";
    $answers[] = $row;
}
$stmt->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/courses.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<title>Review Activity - <?= htmlspecialchars($courseTitle) ?></title>
<style>
body { margin-top: 30px; font-family: Arial, sans-serif; }
.container { max-width: 900px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
h2 { color: #6a1b9a; margin-bottom: 15px; }
.question-box { background-color: #ede7f6; border: 1px solid #d1c4e9; padding: 15px; margin-bottom: 15px; border-radius: 6px; }
.correct { color: green; font-weight: bold; }
.incorrect { color: red; font-weight: bold; }
.back-btn { background-color: #7b1fa2; color: white; padding: 10px 15px; border: none; border-radius: 4px; text-decoration: none; display: inline-block; margin-bottom: 15px; }
.back-btn:hover { background-color: #6a1b9a; }
</style>
</head>
<body>

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
      <li><a href="course.php#resourcelibrary">Resource Library</a></li>
      <li><a href="activities.php">Activities</a></li>
      <li><a href="forum-chat.php">Sessions</a></li>
      <li><a href="forums.php">Forums</a></li>
    </ul>
  </div>

  <div class="nav-profile">
    <a href="#" id="profile-icon">
      <?php if (!empty($menteeIcon)): ?>
        <img src="<?= htmlspecialchars($menteeIcon) ?>" alt="User Icon" style="width: 35px; height: 35px; border-radius: 50%;">
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
            <img src="<?= htmlspecialchars($menteeIcon) ?>" alt="User Icon" style="width: 40px; height: 40px; border-radius: 50%;">
          <?php else: ?>
            <ion-icon name="person-circle-outline" style="font-size: 40px;"></ion-icon>
          <?php endif; ?>
        </div>
        <div class="user-name"><?= htmlspecialchars($firstName) ?></div>
      </div>
      <ul class="sub-menu-items">
        <li><a href="profile.php">Profile</a></li>
        <li><a href="taskprogress.php">Progress</a></li>
        <li><a href="#" onclick="confirmLogout()">Logout</a></li>
      </ul>
    </div>
  </div>
</nav>
</section>

<div class="container">
  <a href="activities.php" class="back-btn">&larr; Back to Activities</a>
  <h2>Review of <?= htmlspecialchars($courseTitle) ?> - <?= htmlspecialchars($activityTitle) ?> (<?= htmlspecialchars($difficultyLevel) ?>)</h2>

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
    <p>No answers found for this activity.</p>
  <?php endif; ?>
</div>

<script src="js/mentee.js"></script>

<script>
const profileIcon = document.getElementById("profile-icon");
const profileMenu = document.getElementById("profile-menu");

if (profileIcon && profileMenu) {
  profileIcon.addEventListener("click", function (e) {
    e.preventDefault();
    profileMenu.classList.toggle("show");
    profileMenu.classList.toggle("hide");
  });

  document.addEventListener("click", function (e) {
    if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target)) {
      profileMenu.classList.remove("show");
      profileMenu.classList.add("hide");
    }
  });
}

function confirmLogout() {
  if (confirm("Are you sure you want to log out?")) {
    window.location.href = "../login.php";
  }
}
</script>

</body>
</html>
