<?php
session_start();

require '../connection/db_connection.php';

// SESSION CHECK - UPDATED for the new 'users' table structure
// We now check for a general 'username' and ensure the 'user_type' is 'Mentor'.
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'Mentor') {
  // Redirect to a general login page if the user is not a logged-in mentor.
  header("Location: ../login.php"); // CHNAGE: Redirect to your main login page
  exit();
}

// FETCH Mentor_Name AND icon BASED ON username from the 'users' table
$username = $_SESSION['username']; // CHANGE: Using the new 'username' session variable
// CHANGE: The SQL query now targets the 'users' table instead of 'applications'.
// Column names are updated from First_Name, Last_Name, Mentor_Icon to first_name, last_name, icon.
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS Mentor_Name, icon FROM users WHERE username = ? AND user_type = 'Mentor'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username); // CHANGE: Binding the new $username variable
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_name'] = $row['Mentor_Name'];

  // Check if icon exists and is not empty
  // CHANGE: Updated column name from 'Mentor_Icon' to 'icon'
  if (isset($row['icon']) && !empty($row['icon'])) {
    $_SESSION['mentor_icon'] = $row['icon'];
  } else {
    // Provide a default icon if none is set
    $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
  }
} else {
  // Handle case where mentor is not found, though session check should prevent this
  $_SESSION['mentor_name'] = "Unknown Mentor";
  $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
}

// NO CHANGE NEEDED BELOW THIS POINT FOR DATA FETCHING
// The following queries correctly use the mentor's full name, which we retrieved above.

// FETCH Course_Title AND Skill_Level BASED ON Mentor_Name
$mentorName = $_SESSION['mentor_name'];
$courseSql = "SELECT Course_Title, Skill_Level FROM courses WHERE Assigned_Mentor = ?";
$courseStmt = $conn->prepare($courseSql);
$courseStmt->bind_param("s", $mentorName);
$courseStmt->execute();
$courseResult = $courseStmt->get_result();

if ($courseResult->num_rows === 1) {
    $courseRow = $courseResult->fetch_assoc();
    $_SESSION['course_title'] = $courseRow['Course_Title'];
    $_SESSION['skill_level'] = $courseRow['Skill_Level'];
} else {
    $_SESSION['course_title'] = "No Assigned Course";
    $_SESSION['skill_level'] = "-";
}

$courseStmt->close();

// Query to count approved resources uploaded by the mentor
$resourceSql = "SELECT COUNT(*) AS approved_count FROM resources WHERE UploadedBy = ? AND Status = 'Approved'";
$resourceStmt = $conn->prepare($resourceSql);
$resourceStmt->bind_param("s", $mentorName);
$resourceStmt->execute();
$resourceResult = $resourceStmt->get_result();

if ($resourceResult->num_rows === 1) {
    $resourceRow = $resourceResult->fetch_assoc();
    $approvedResourcesCount = $resourceRow['approved_count'];
} else {
    $approvedResourcesCount = 0;
}

$resourceStmt->close();

// FETCH Number of Sessions BASED ON Course_Title
$courseTitle = $_SESSION['course_title'];
$sessionCount = 0;

if ($courseTitle !== "No Assigned Course") {
    $sessionSql = "SELECT COUNT(*) AS session_count FROM sessions WHERE Course_Title = ?";
    $sessionStmt = $conn->prepare($sessionSql);
    $sessionStmt->bind_param("s", $courseTitle);
    $sessionStmt->execute();
    $sessionResult = $sessionStmt->get_result();

    if ($sessionResult->num_rows === 1) {
        $sessionRow = $sessionResult->fetch_assoc();
        $sessionCount = $sessionRow['session_count'];
    }

    $sessionStmt->close();
}

// FETCH Number of Feedbacks BASED ON Session_Mentor
$feedbackCount = 0;
$feedbackSql = "SELECT COUNT(*) AS feedback_count FROM feedback WHERE Session_Mentor = ?";
$feedbackStmt = $conn->prepare($feedbackSql);
$feedbackStmt->bind_param("s", $mentorName);
$feedbackStmt->execute();
$feedbackResult = $feedbackStmt->get_result();

if ($feedbackResult->num_rows === 1) {
    $feedbackRow = $feedbackResult->fetch_assoc();
    $feedbackCount = $feedbackRow['feedback_count'];
}

$feedbackStmt->close();

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/achievement.css" />
  <link rel="stylesheet" href="css/navigation.css"/>
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>Achievement | Mentor</title>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['mentor_icon']); ?>" alt="Mentor Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['mentor_name']); ?>
        </span>
        <span class="admin-role">Mentor</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
    <ul class="navLinks">
      <li class="navList">
        <a href="dashboard.php">
          <ion-icon name="home-outline"></ion-icon>
          <span class="links">Home</span>
        </a>
      </li>
      <li class="navList">
        <a href="courses.php">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Course</span>
        </a>
      </li>
      <li class="navList">
        <a href="sessions.php">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="feedbacks.php">
          <ion-icon name="star-outline"></ion-icon>
          <span class="links">Feedbacks</span>
        </a>
      </li>
      <li class="navList">
        <a href="activities.php">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList">
        <a href="resource.php">
          <ion-icon name="library-outline"></ion-icon>
          <span class="links">Resource Library</span>
        </a>
      </li>
      <li class="navList active">
        <a href="achievement.php">
          <ion-icon name="trophy-outline"></ion-icon>
          <span class="links">Achievement</span>
        </a>
      </li>
    </ul>
    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout(event)">
          <ion-icon name="log-out-outline"></ion-icon>
          Logout
        </a>
      </li>
    </ul>
  </div>
</nav>

<section class="achievement-container">
    <h1 class="page-title">My Achievements</h1>
    <p class="page-description">
        Celebrate your milestones! Below are the tiers for achieving certificates and recognition here on COACH. Click on any tier to see the full description and requirements.
    </p>

    <div class="achievement-tiers">

        <div class="tier-card tier-certified">
            <span class="tier-icon">🥉</span>
            <h2 class="tier-title">Certified Mentor</h2>
            <p class="tier-description">
                The foundational level recognizing mentors who have successfully completed the core training modules and mentored their first batch of users. This tier establishes you as a recognized and capable mentor in the COACH community.
            </p>
            <button class="tier-button certified-button">View Details</button>
        </div>

        <div class="tier-card tier-distinguished">
            <span class="tier-icon">🥈</span>
            <h2 class="tier-title">Distinguished Mentor</h2>
            <p class="tier-description">
                Awarded to mentors who have demonstrated consistent excellence, successfully guided multiple mentees, and received high feedback scores. You are a proven asset to our community.
            </p>
            <button class="tier-button distinguished-button">View Details</button>
        </div>

        <div class="tier-card tier-elite">
            <span class="tier-icon">👑</span>
            <h2 class="tier-title">Elite Mentor</h2>
            <p class="tier-description">
                The highest tier reserved for top-performing mentors who have contributed significantly to the platform, perhaps by creating resources or training new mentors. This status grants special recognition and privileges.
            </p>
            <button class="tier-button elite-button">View Details</button>
        </div>

    </div>
</section>
  
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
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