<?php
session_start();

// ==========================================================
// --- NEW: ANTI-CACHING HEADERS (Security Block) ---
// These headers prevent the browser from caching the page, 
// forcing a server check on back button press.
// ==========================================================
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); 
// ==========================================================


// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Mentee'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    // FIX: Redirect to the correct unified login page (one directory up)
    header("Location: ../login.php");
    exit();
}

// --- FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

// SESSION CHECK
if (!isset($_SESSION['username'])) {
  // FIX: Use the correct unified login page path (one directory up)
  header("Location: ../login.php"); 
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


// Fetch assigned quizzes for this mentee
$sql = "SELECT * FROM quizassignments WHERE Mentee_ID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $menteeUserId);
$stmt->execute();
$result = $stmt->get_result();

$assignments = [];
while ($row = $result->fetch_assoc()) {
    $course = $row['Course_Title'];
    $activity = $row['Activity_Title'];

    // Fetch the most recent attempt for this course+activity
$scoreStmt = $conn->prepare("
    SELECT Score, Total_Questions, Date_Taken 
    FROM menteescores 
    WHERE user_id = ? 
      AND Course_Title = ? 
      AND Activity_Title = ? 
      AND Difficulty_Level = ?
    ORDER BY Date_Taken DESC LIMIT 1
");
$scoreStmt->bind_param("isss", $menteeUserId, $course, $activity, $row['Difficulty_Level']);

    $scoreStmt->execute();
    $scoreResult = $scoreStmt->get_result();
    $existingScore = $scoreResult->fetch_assoc();

    $row['already_taken'] = $existingScore ? true : false;
    $row['score_data'] = $existingScore;
    $assignments[] = $row;

    $scoreStmt->close();
}
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
  <title>Activities</title>
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

/* Logout Dialog Styles (based on rejection dialog) */
.logout-dialog {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.logout-content {
    background-color: white;
    padding: 2rem;
    border-radius: 8px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    text-align: center;
}

.logout-content h3 {
    margin-top: 0;
    color: #562b63;
    font-family: 'Ubuntu', sans-serif; 
    font-size: 1.5rem;
    border-bottom: 1px solid #eee;
    padding-bottom: 0.5rem;
    margin-bottom: 1rem;
}

.logout-content p {
    margin-bottom: 1.5rem;
    font-family: 'Ubuntu', sans-serif; 
    line-height: 1.4;
    font-size: 1rem;
}

.dialog-buttons {
    display: flex;
    justify-content: center;
    gap: 1rem;
}

.dialog-buttons button {
    padding: 0.6rem 1.2rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-family: 'Ubuntu', sans-serif; 
    font-size: 1rem;
}

#cancelLogout {
    background-color: #f5f5f5;
    color: #333;
}

#cancelLogout:hover {
    background-color: #e0e0e0;
}

#confirmLogoutBtn {
    background: linear-gradient(to right, #5d2c69, #8a5a96);
    color: white;
}

#confirmLogoutBtn:hover {
    background: #5d2c69;
}

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

  <!-- Activities Section -->

<div class="container">
  <h2>Assigned Activities</h2>
  <?php if (count($assignments) > 0): ?>
<?php foreach ($assignments as $assignment): ?>
  <div class="assignment-box">
    <h3><?= htmlspecialchars($assignment['Course_Title']) ?> - <?= htmlspecialchars($assignment['Activity_Title']) ?></h3>
    <p>Level: <?= htmlspecialchars($assignment['Difficulty_Level']) ?></p>
    <p>Date Assigned: <?= htmlspecialchars($assignment['Date_Assigned']) ?></p>

    <?php if ($assignment['already_taken']): ?>
      <p class="note">
        Latest Score: <?= htmlspecialchars($assignment['score_data']['Score']) ?> / <?= htmlspecialchars($assignment['score_data']['Total_Questions']) ?><br>
        Last Attempt: <?= htmlspecialchars($assignment['score_data']['Date_Taken']) ?>
      </p>

      <!-- Review Button -->
      <form action="review_assessment.php" method="get" class="review-form">
        <input type="hidden" name="course_title" value="<?= htmlspecialchars($assignment['Course_Title']) ?>">
        <input type="hidden" name="activity_title" value="<?= htmlspecialchars($assignment['Activity_Title']) ?>">
        <input type="hidden" name="difficulty_level" value="<?= htmlspecialchars($assignment['Difficulty_Level']) ?>">
        <button type="submit">Review</button>
      </form>

      <!-- Attempt Again Button -->
      <form action="assessment.php" method="get" class="review-form">
        <input type="hidden" name="course_title" value="<?= htmlspecialchars($assignment['Course_Title']) ?>">
        <input type="hidden" name="activity_title" value="<?= htmlspecialchars($assignment['Activity_Title']) ?>">
        <input type="hidden" name="difficulty_level" value="<?= htmlspecialchars($assignment['Difficulty_Level']) ?>">
        <button type="submit">Attempt Again</button>
      </form>

    <?php else: ?>
      <!-- First Attempt -->
      <form action="assessment.php" method="get">
        <input type="hidden" name="course_title" value="<?= htmlspecialchars($assignment['Course_Title']) ?>">
        <input type="hidden" name="activity_title" value="<?= htmlspecialchars($assignment['Activity_Title']) ?>">
        <input type="hidden" name="difficulty_level" value="<?= htmlspecialchars($assignment['Difficulty_Level']) ?>">
        <button type="submit">Check Activity</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

  <?php else: ?>
    <p>No assigned quizzes at the moment.</p>
  <?php endif; ?>
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
            // FIX: Redirect to the dedicated logout script in the parent folder (root)
            window.location.href = "../logout.php"; 
        });
    }
});
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
</div>

</body>
</html>
