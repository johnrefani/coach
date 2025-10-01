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
$selectedCategory = isset($_GET['category']) ? $_GET['category'] : ""; // Get selected category

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

// --- QUOTE LOGIC: Define and select a random motivational quote ---
$quotes = [
    '“Small progress each day adds up to big results.”',
    '“Every attempt is a step closer to success.”',
    '“Mistakes are proof you’re trying and learning.”',
    '“Consistency beats perfection—keep moving forward!”',
    '“Learning never stops; each challenge unlocks your potential.”',
    '“Your future self will thank you for not giving up today.”',
    '“Difficult roads often lead to beautiful destinations.”',
    '“Every expert was once where you are—don’t stop now!”',
    '“It doesn’t matter how slowly you go, as long as you don’t stop.”',
    '“You’re building skills today that will shape tomorrow.”'
];
$randomQuoteKey = array_rand($quotes);
$encouragementTip = $quotes[$randomQuoteKey];


// --- Determine Filter Type ---
$isCourseFilter = !empty($selectedCategory) && !in_array($selectedCategory, ['Activity 1', 'Activity 2', 'Activity 3', 'Beginner', 'Intermediate', 'Advanced']);
$isSpecificFilter = !empty($selectedCategory) && !$isCourseFilter; // Activity or Difficulty filter selected
$isAllSelected = empty($selectedCategory); // 'All' is when category is empty

// --- Set Context for Circles and Calculations ---
// If a specific course is selected, use it as a filter. Otherwise, use NULL to aggregate all data.
$courseFilterForCircles = $isCourseFilter ? $selectedCategory : null;


// --- 1. Passed Activities Circle: Total Passed ---
$passedWhereClause = "WHERE user_id = ? AND Score >= 15";
$passedParams = [$menteeUserId];

if ($isCourseFilter) {
    // If specific course is selected, filter total passed count by course title
    $passedWhereClause = "WHERE user_id = ? AND Course_Title = ? AND Score >= 15";
    $passedParams = [$menteeUserId, $selectedCategory];
}

$sqlPassedAll = "SELECT COUNT(*) as total_passed FROM menteescores $passedWhereClause";

$stmtPassedAll = $conn->prepare($sqlPassedAll);
if ($isCourseFilter) {
    $stmtPassedAll->bind_param("is", $passedParams[0], $passedParams[1]);
} else {
    $stmtPassedAll->bind_param("i", $passedParams[0]);
}

$stmtPassedAll->execute();
$resPassedAll = $stmtPassedAll->get_result();

$totalPassed = 0;
if ($row = $resPassedAll->fetch_assoc()) {
    $totalPassed = (int)$row['total_passed'];
}
$stmtPassedAll->close();

// Set the visual percentage for the Passed Activities circle (10% per activity, capped at 100%)
$passedVisualPercent = min($totalPassed * 10, 100); 


// --- 2. Difficulty Breakdown Logic (for Circles) ---
// This function calculates distinct passed activities for a given difficulty and OPTIONAL course filter.
function getDifficultyStats($conn, $userId, $difficulty, $courseTitle = null) {
    // Only apply Course_Title filter if $courseTitle is not null
    $whereCourse = $courseTitle ? "AND Course_Title = ?" : "";
    
    $sql = "SELECT Activity_Title, MAX(Score) as max_score
            FROM menteescores
            WHERE user_id = ? AND Difficulty_Level = ? $whereCourse
            GROUP BY Activity_Title";
    
    $stmt = $conn->prepare($sql);
    
    if ($courseTitle) {
        $stmt->bind_param("iss", $userId, $difficulty, $courseTitle);
    } else {
        $stmt->bind_param("is", $userId, $difficulty);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    $passed = 0;
    $totalDistinctActivities = 0;
    
    while ($row = $result->fetch_assoc()) {
        $totalDistinctActivities++; 
        if ($row['max_score'] >= 15) { // Assuming passing score is 15
            $passed++;
        }
    }
    $stmt->close();
    
    // Assume 3 distinct activities per level as the total base if no activities are found in the filtered context.
    $totalBase = max($totalDistinctActivities, 3); 
    
    $percent = ($totalBase > 0) 
               ? round(($passed / $totalBase) * 100) 
               : 0;

    // Return distinct activities passed, percentage, and total base for locking
    return [$passed, $percent, $totalBase];
}


// --- CALCULATIONS FOR CIRCLES 2, 3, 4 (Difficulty or Overall) ---

// Use $courseFilterForCircles (null for All/Activity/Difficulty filters, or course name for course filter)
list($passedBeginner, $percentBeginner, $totalBeginner) = getDifficultyStats($conn, $menteeUserId, 'Beginner', $courseFilterForCircles);
list($passedIntermediate, $percentIntermediate, $totalIntermediate) = getDifficultyStats($conn, $menteeUserId, 'Intermediate', $courseFilterForCircles);
list($passedAdvanced, $percentAdvanced, $totalAdvanced) = getDifficultyStats($conn, $menteeUserId, 'Advanced', $courseFilterForCircles);

// Locking condition: Pass 3 distinct activities in the previous level in the context ($courseFilterForCircles)
$UNLOCK_GOAL = 3; 
$intermediateLocked = ($passedBeginner < $UNLOCK_GOAL); 
$advancedLocked = $intermediateLocked || ($passedIntermediate < $UNLOCK_GOAL);


// --- Additional "All" Metrics (Only computed if $isAllSelected or if a non-course filter is used) ---
if ($isAllSelected || $isSpecificFilter) {
    // Overall Completion for the circle 2 position (using the ALL data)
    $totalDistinctPassed = $passedBeginner + $passedIntermediate + $passedAdvanced;
    $totalDistinctAvailable = $totalBeginner + $totalIntermediate + $totalAdvanced;
    $totalDistinctAvailable = max(1, $totalDistinctAvailable); 
    $overallProgressPercent = round(($totalDistinctPassed / $totalDistinctAvailable) * 100); 

    // Average Performance (Text Box 3)
    $sqlAvgScore = "SELECT AVG(max_score) as avg_score 
                    FROM (
                        SELECT MAX(Score) as max_score
                        FROM menteescores
                        WHERE user_id = ?
                        GROUP BY Course_Title, Activity_Title, Difficulty_Level
                    ) as T";
    $stmtAvgScore = $conn->prepare($sqlAvgScore);
    $stmtAvgScore->bind_param("i", $menteeUserId);
    $stmtAvgScore->execute();
    $resultAvgScore = $stmtAvgScore->get_result();
    $overallAverageScore = 0;
    if ($row = $resultAvgScore->fetch_assoc()) {
        $overallAverageScore = round($row['avg_score'] ?? 0, 1);
    }
    $stmtAvgScore->close();
    
    // Normalize the average score by the maximum possible score (assuming it's 20 based on passing score of 15)
    $MAX_SCORE = 20; 
    $overallAvgPercent = ($MAX_SCORE > 0) ? round(($overallAverageScore / $MAX_SCORE) * 100) : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/taskprogresstyle.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>My Progress</title>
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
            <li><a href="taskprogress.php">Progress</a></li>
            <li><a href="#" onclick="confirmLogout(event)">Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>
  </section>

  <div class="content-wrapper">
    <h1 style="text-align:center; margin-bottom: 20px;">Progress Tracker</h1>

    <div class="top-section">
      <div class="info-box profile-box">
        <img src="<?php echo !empty($menteeIcon) ? htmlspecialchars($menteeIcon) : 'https://via.placeholder.com/100'; ?>" alt="Profile" width="100" height="100">
        <h3><?php echo htmlspecialchars($firstName ?? 'Name'); ?></h3>
      </div>

  <div class="progress-info">
  <div class="info-box">
    <h4>Category</h4>
    <form method="GET" action="">
      <select name="category" onchange="this.form.submit()">
        <option value="" <?= ($selectedCategory == '') ? 'selected' : '' ?>>All</option>
        <optgroup label="Courses">
          <?php
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

<div class="info-box">
  <div class="circular-progress" style="--percent: <?= $passedVisualPercent ?>%;">
    <span class="progress-value"><?= $totalPassed ?></span>
  </div>
  <div class="label">PASSED ACTIVITIES</div>
</div>

<?php if ($isAllSelected || $isSpecificFilter): ?>
<div class="info-box">
  <div class="circle" style="--percent: <?= $overallProgressPercent ?>%;">
    <span><?= $overallProgressPercent ?>%</span>
  </div>
  <div class="label">OVERALL COMPLETION</div>
</div>

<div class="info-box text-box">
  <div class="metric-display">
    <span class="main-metric" style="color:#5c087d;"><?= $overallAvgPercent ?>%</span>
  </div>
  <div class="label">AVERAGE PERFORMANCE</div>
</div>

<div class="info-box text-box">
  <div class="text-tip">
    <?php echo htmlspecialchars($encouragementTip); ?>
  </div>
</div>

<?php else: ?>
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
      Tip: Pass at least <?= $UNLOCK_GOAL ?> distinct activities at the Beginner level in this course to unlock Intermediate.
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
      Tip: Pass at least <?= $UNLOCK_GOAL ?> distinct activities at the Intermediate level in this course to unlock Advanced.
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>


</div>
</div>

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
      // --- Table Filtering Logic (Re-used and maintained) ---
      
      $tableParams = [$menteeUserId];
      $tableTypes = "i";
      $sqlBase = "SELECT Course_Title, Activity_Title, Difficulty_Level, Attempt_Number, Score, Total_Questions, Date_Taken 
                  FROM menteescores 
                  WHERE user_id = ?";
      
      if (!empty($selectedCategory)) {
          $filterValue = $selectedCategory;

          if (in_array($selectedCategory, ['Beginner', 'Intermediate', 'Advanced'])) {
              // Difficulty filter
              $sqlBase .= " AND Difficulty_Level = ?";
              $tableParams[] = $filterValue;
              $tableTypes .= "s";

          } elseif (in_array($selectedCategory, ['Activity 1', 'Activity 2', 'Activity 3'])) {
              // Activity filter
              $sqlBase .= " AND Activity_Title = ?";
              $tableParams[] = $filterValue;
              $tableTypes .= "s";

          } else {
              // Course filter
              $sqlBase .= " AND Course_Title = ?";
              $tableParams[] = $filterValue;
              $tableTypes .= "s";
          }
      }
      
      $sqlBase .= " ORDER BY Date_Taken DESC";

      $stmt = $conn->prepare($sqlBase);
      // Bind parameters dynamically
      if (!empty($tableParams)) {
          $bind_names[] = $tableTypes;
          for ($i=0; $i<count($tableParams); $i++) {
              $bind_name = 'param'.$i;
              $$bind_name = $tableParams[$i];
              $bind_names[] = &$$bind_name;
          }
          // The use of call_user_func_array is necessary for dynamic parameter binding with bind_param
          call_user_func_array([$stmt, 'bind_param'], $bind_names);
      }
      // --- End Table Filtering Logic ---
      
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
                  ? "<span style='color:purple; font-weight:500;'>Passed</span>" 
                  : "<span style='color:red; font-weight:500;'>Failed</span>";

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
document.addEventListener("DOMContentLoaded", function() {
    // Select all necessary elements
    const profileIcon = document.getElementById("profile-icon");
    const profileMenu = document.getElementById("profile-menu");
    const logoutDialog = document.getElementById("logoutDialog");
    const cancelLogoutBtn = document.getElementById("cancelLogout");
    const confirmLogoutBtn = document.getElementById("confirmLogoutBtn");

    // --- Profile Menu Toggle Logic ---
    if (profileIcon && profileMenu) {
        profileIcon.addEventListener("click", function (e) {
            e.preventDefault();
            profileMenu.classList.toggle("show");
            profileMenu.classList.toggle("hide");
        });
        
        // Close menu when clicking outside
        document.addEventListener("click", function (e) {
            if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target) && !e.target.closest('#profile-menu')) {
                profileMenu.classList.remove("show");
                profileMenu.classList.add("hide");
            }
        });
    }

    // --- Logout Dialog Logic ---
    // Make confirmLogout function globally accessible for the onclick in HTML
    window.confirmLogout = function(e) { 
        if (e) e.preventDefault(); // FIX: Prevent the default anchor behavior (# in URL)
        if (logoutDialog) {
            logoutDialog.style.display = "flex";
        }
    }

    // FIX: Attach event listeners to the dialog buttons after DOM is loaded
    if (cancelLogoutBtn && logoutDialog) {
        cancelLogoutBtn.addEventListener("click", function(e) {
            e.preventDefault(); 
            logoutDialog.style.display = "none";
        });
    }

    if (confirmLogoutBtn) {
        confirmLogoutBtn.addEventListener("click", function(e) {
            e.preventDefault(); 
            // FIX: Use relative path to access logout.php in the parent directory
            window.location.href = "../login.php"; 
        });
    }
});
</script>
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

<div id="logoutDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3>Confirm Logout</h3>
        <p>Are you sure you want to log out?</p>
        <div class="dialog-buttons">
            <button id="cancelLogout" type="button">Cancel</button>
            <button id="confirmLogoutBtn" type="button">Logout</button>
        </div>
    </div>
</div>
</body>
</html>