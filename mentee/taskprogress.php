<?php
session_start();

// --- ACCESS CONTROL ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    header("Location: login.php");
    exit();
}

require '../connection/db_connection.php';

// Redirect if mentee not logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
  header("Location: login_mentee.php");
  exit();
}

$menteeUserId = $_SESSION['user_id'];
$username = $_SESSION['username']; 
$firstName = '';
$menteeIcon = '';

// Fetch First_Name and Mentee_Icon
$sql = "SELECT first_name, icon FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $menteeUserId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  $row = $result->fetch_assoc();
  $firstName = $row['first_name'];
  $menteeIcon = $row['icon'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/taskprogresstyle.css" />
    <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
  <title>Mentee Dashboard</title>
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
          <li><a href="resource_library">Resource Library</a></li>
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
            <li><a href="taskprogress.php">Progress</a></li>
            <li><a href="#" onclick="confirmLogout()">Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>
  </section>

  <div class="content-wrapper">
    <h1 style="text-align:center; margin-bottom: 20px;">Progress Tracker</h1>

    <div class="top-section">
      <!-- Profile Box -->
      <div class="info-box profile-box">
        <img src="<?php echo !empty($menteeIcon) ? htmlspecialchars($menteeIcon) : 'https://via.placeholder.com/100'; ?>" alt="Profile" width="100" height="100">
        <h3><?php echo htmlspecialchars($firstName ?? 'Name'); ?></h3>
      </div>

  <!-- Progress Info -->
<div class="progress-info">
  <!-- Category -->
  <div class="info-box">
    <h4>Category</h4>
    <form method="GET" action="">
      <select name="category" onchange="this.form.submit()">
        <option value="">All</option>
        <optgroup label="Courses">
          <?php
          $selectedCategory = isset($_GET['category']) ? $_GET['category'] : "";
          $sql = "SELECT DISTINCT Course_Title 
                  FROM menteescores 
                  WHERE user_id = ?
                  ORDER BY Course_Title ASC";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("i", $menteeUserId);
          $stmt->execute();
          $result = $stmt->get_result();

          while ($row = $result->fetch_assoc()) {
              $courseTitle = htmlspecialchars($row['Course_Title']);
              $selected = ($selectedCategory == $courseTitle) ? "selected" : "";
              echo "<option value='$courseTitle' $selected>$courseTitle</option>";
          }
          $stmt->close();
          ?>
        </optgroup>

        <optgroup label="Activities">
          <option value="Activity 1" <?= ($selectedCategory == 'Activity 1') ? 'selected' : '' ?>>Activity 1</option>
          <option value="Activity 2" <?= ($selectedCategory == 'Activity 2') ? 'selected' : '' ?>>Activity 2</option>
          <option value="Activity 3" <?= ($selectedCategory == 'Activity 3') ? 'selected' : '' ?>>Activity 3</option>
        </optgroup>

        <optgroup label="Difficulty Levels">
          <option value="Beginner" <?= ($selectedCategory == 'Beginner') ? 'selected' : '' ?>>Beginner</option>
          <option value="Intermediate" <?= ($selectedCategory == 'Intermediate') ? 'selected' : '' ?>>Intermediate</option>
          <option value="Advanced" <?= ($selectedCategory == 'Advanced') ? 'selected' : '' ?>>Advanced</option>
        </optgroup>
      </select>
    </form>
  </div>
</div>

<?php

// --- Overall Progress ---
$sqlPassedAll = "SELECT COUNT(*) as total_passed
                 FROM menteescores
                 WHERE user_id = ? AND Score >= 15";
$stmtPassedAll = $conn->prepare($sqlPassedAll);
$stmtPassedAll->bind_param("i", $menteeUserId);
$stmtPassedAll->execute();
$resPassedAll = $stmtPassedAll->get_result();

$totalPassed = 0;
if ($row = $resPassedAll->fetch_assoc()) {
    $totalPassed = (int)$row['total_passed'];
}
$stmtPassedAll->close();


$overallPercent = min($totalPassed * 10, 100); // 10% per passed activity, capped at 100%



// --- Difficulty Breakdown ---
function getDifficultyStats($conn, $userId, $difficulty) {
    $sql = "SELECT Activity_Title, MAX(CASE WHEN Score >= 15 THEN 1 ELSE 0 END) as passed
            FROM menteescores
            WHERE user_id = ? AND Difficulty_Level = ?
            GROUP BY Activity_Title";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $userId, $difficulty);
    $stmt->execute();
    $result = $stmt->get_result();

    $passed = 0;
    while ($row = $result->fetch_assoc()) {
        $passed += (int)$row['passed'];
    }
    $stmt->close();

    $percent = round(($passed / 3) * 100); // 3 activities per level

    return [$passed, $percent];
}

list($passedBeginner, $percentBeginner) = getDifficultyStats($conn, $menteeUserId, 'Beginner');
list($passedIntermediate, $percentIntermediate) = getDifficultyStats($conn, $menteeUserId, 'Intermediate');
list($passedAdvanced, $percentAdvanced) = getDifficultyStats($conn, $menteeUserId, 'Advanced');

// Locking condition → must pass ALL 3 activities in previous level
$intermediateLocked = ($passedBeginner < 3); 
$advancedLocked = $intermediateLocked || ($passedIntermediate < 3);
?>


<div class="info-box">
  <div class="circular-progress" style="--percent: <?= $totalPassed * 10 ?>%;">
    <span class="progress-value"><?= $totalPassed ?></span>
  </div>
  <div class="label">PASSED ACTIVITIES</div>
</div>


<div class="info-box">
  <div class="circle" style="--percent: <?= $percentBeginner ?>%;">
    <span><?= $percentBeginner ?>%</span>
  </div>
  <div class="label">Beginner</div>
</div>

<div class="info-box locked-level">
  <div class="circle" style="--percent: <?= $intermediateLocked ? 0 : $percentIntermediate ?>%;">
    <span><?= $intermediateLocked ? "LOCKED" : $percentIntermediate."%" ?></span>
  </div>
  <div class="label">Intermediate</div>

  <?php if ($intermediateLocked): ?>
    <div class="locked-tip">
      Tip: Pass at least 3 activities per level and enroll in the next session to unlock the next level. Keep learning and challenging yourself!
    </div>
  <?php endif; ?>
</div>

<div class="info-box locked-level">
  <div class="circle" style="--percent: <?= $advancedLocked ? 0 : $percentAdvanced ?>%;">
    <span><?= $advancedLocked ? "LOCKED" : $percentAdvanced."%" ?></span>
  </div>
  <div class="label">Advanced</div>

  <?php if ($advancedLocked): ?>
    <div class="locked-tip">
      Tip: Pass at least 3 activities per level and enroll in the next session to unlock the next level. Keep learning and challenging yourself!
    </div>
  <?php endif; ?>
</div>


</div>
</div>

<!-- Table -->
<div class="table-container">
  <table>
    <thead>
      <tr>
        <th>Course</th>
        <th>Activity</th>
        <th>Difficulty</th>
        <th>Attempt</th>
        <th>Date Taken</th>
        <th>Score</th>
        <th>Remarks</th>
      </tr>
    </thead>
    <tbody>
      <?php
      if (!empty($selectedCategory)) {
          // Detect category type
          if (in_array($selectedCategory, ['Beginner', 'Intermediate', 'Advanced'])) {
              // Difficulty filter
              $sql = "SELECT Course_Title, Activity_Title, Difficulty_Level, Attempt_Number, Score, Total_Questions, Date_Taken 
                      FROM menteescores 
                      WHERE user_id = ? AND Difficulty_Level = ?
                      ORDER BY Date_Taken ASC";
              $stmt = $conn->prepare($sql);
              $stmt->bind_param("is", $menteeUserId, $selectedCategory);

          } elseif (in_array($selectedCategory, ['Activity 1', 'Activity 2', 'Activity 3'])) {
              // Activity filter
              $sql = "SELECT Course_Title, Activity_Title, Difficulty_Level, Attempt_Number, Score, Total_Questions, Date_Taken 
                      FROM menteescores 
                      WHERE user_id = ? AND Activity_Title = ?
                      ORDER BY Date_Taken ASC";
              $stmt = $conn->prepare($sql);
              $stmt->bind_param("is", $menteeUserId, $selectedCategory);

          } else {
              // Course filter (default)
              $sql = "SELECT Course_Title, Activity_Title, Difficulty_Level, Attempt_Number, Score, Total_Questions, Date_Taken 
                      FROM menteescores 
                      WHERE user_id = ? AND Course_Title = ?
                      ORDER BY Date_Taken ASC";
              $stmt = $conn->prepare($sql);
              $stmt->bind_param("is", $menteeUserId, $selectedCategory);
          }
      } else {
          // No filter → show all
          $sql = "SELECT Course_Title, Activity_Title, Difficulty_Level, Attempt_Number, Score, Total_Questions, Date_Taken 
                  FROM menteescores 
                  WHERE user_id = ?
                  ORDER BY Date_Taken ASC";
          $stmt = $conn->prepare($sql);
          $stmt->bind_param("i", $menteeUserId);
      }

      $stmt->execute();
      $result = $stmt->get_result();

      if ($result->num_rows > 0) {
          while($row = $result->fetch_assoc()) {
              $course = htmlspecialchars($row['Course_Title']);
              $activity = htmlspecialchars($row['Activity_Title']);
              $difficulty = htmlspecialchars($row['Difficulty_Level']);
              $attempt = (int)$row['Attempt_Number'];
              $score = (int)$row['Score'];
              $total = (int)$row['Total_Questions'];
              $scorePercent = ($total > 0) ? round(($score / $total) * 100, 2) : 0;
              $dateTaken = date("m/d/y", strtotime($row['Date_Taken']));

              // Passing rule: score >= 15
              $status = ($score >= 15) 
                  ? "<span style='color:purple;'> Passed</span>" 
                  : "<span style='color:red;'> Failed</span>";

              echo "<tr>
                      <td>$course</td>
                      <td>$activity</td>
                      <td>$difficulty</td>
                      <td>Attempt #$attempt</td>
                      <td>$dateTaken</td>
                      <td>
                        <div class='score-bar'>
                          <span style='width:".$scorePercent."%'>$score</span>
                        </div>
                      </td>
                      <td>$status</td>
                    </tr>";
          }
      } else {
          echo "<tr><td colspan='7'>No records found.</td></tr>";
      }

      $stmt->close();
      $conn->close();
      ?>
    </tbody>
  </table>
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
    var confirmation = confirm("Are you sure you want to log out?");
    if (confirmation) {
      window.location.href = "login.php";
    }
  }
</script>
</body>
</html>
