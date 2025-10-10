<?php
session_start(); // Start the session

require '../connection/db_connection.php';

// --- UPDATED LOGIC ---

// SESSION CHECK (Assuming login page now sets $_SESSION['username'])
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'Mentor') {
  header("Location: ../login.php");
  exit();
}

// FETCH Mentor's Name AND Icon FROM the new 'users' table
$mentorUsername = $_SESSION['username'];
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS mentor_name, icon AS mentor_icon FROM users WHERE username = ? AND user_type = 'Mentor'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $mentorUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_name'] = $row['mentor_name'];
  $_SESSION['mentor_icon'] = !empty($row['mentor_icon']) ? $row['mentor_icon'] : "../uploads/img/default_pfp.png";
} else {
  // Handle case where mentor is not found or user is not a mentor
  $_SESSION['mentor_name'] = "Unknown Mentor";
  $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
}
$stmt->close();


// FETCH COURSES ASSIGNED TO THIS MENTOR
$mentorFullName = $_SESSION['mentor_name'];
$queryCourses = "SELECT * FROM courses WHERE Assigned_Mentor = ?";
$stmtCourses = $conn->prepare($queryCourses);
$stmtCourses->bind_param("s", $mentorFullName);
$stmtCourses->execute();
$courses = $stmtCourses->get_result();

?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/courses.css" /> 
  <link rel="stylesheet" href="css/home.css" />
  <link rel="stylesheet" href="../superadmin/css/clock.css"/>
  <link rel="stylesheet" href="css/navigation.css"/>
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>Courses | Mentor</title>
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
      <li class="navList active">
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
            <li class="navList">
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


      <section class="dashboard">
  <div class="top">
    <ion-icon class="navToggle" name="menu-outline"></ion-icon>
    <img src="../uploads/img/logo.png" alt="Logo"> 
  </div>

  <div class="main-content">
  <h2 class="assigned-heading">Assigned Course</h2>

    <div class="courses-container">
  <?php if ($courses->num_rows > 0): ?>
    <?php while($course = $courses->fetch_assoc()): ?>
      <div class="course-card">
        <img src="../uploads/<?= htmlspecialchars($course['Course_Icon']) ?>" alt="Course Icon">
        
        <div class="course-description">
            <?= htmlspecialchars($course['Course_Description']) ?>
        </div>

        <div class="course-info">
      <h3><?= htmlspecialchars($course['Course_Title']) ?></h3>
      <div class="skill-level"><?= htmlspecialchars($course['Skill_Level']) ?></div>
    </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div style="grid-column: 1 / -1; text-align: center; color: #6d4c90; font-size: 18px; background: #f2e3fb; padding: 30px; border-radius: 12px;">
      You currently have no courses assigned.
    </div>
  <?php endif; ?>
</div>


<div class="course-details">
  <h2>Ready to Begin Your Course Journey</h2>
  <p class="course-reminder">
     Before starting, make sure you've reviewed the course modules and prepared all necessary resources. 
  Stay organized, be responsive to questions, and guide your mentees with clarity and patience.
  </p>
  <button class="start-course-btn">Start Course</button>
</div>

</section>

<?php
  // Close the final statement and the connection
  $stmtCourses->close();
  $conn->close();
?>

<script src="admin.js"></script>
<script src="js/navigation.js"></script>
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script>
  
            // Remove 'active' from all
      navLinks.forEach(link => link.classList.remove("active"));

      if (defaultTab) {
          defaultTab.classList.add("active");
      }

      updateVisibleSections();
      
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