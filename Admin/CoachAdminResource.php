<?php
session_start(); // Start the session
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

// FETCH RESOURCES
$resources = [];
$res = $conn->query("SELECT * FROM resources ORDER BY Resource_ID DESC");
if ($res && $res->num_rows > 0) {
  while ($row = $res->fetch_assoc()) {
    $resources[] = $row;
  }
}

// Count resources by status
$approvedCount = 0;
$pendingCount = 0;
$rejectedCount = 0;

foreach ($resources as $resource) {
    if ($resource['Status'] == 'Approved') {
        $approvedCount++;
    } elseif ($resource['Status'] == 'Under Review') {
        $pendingCount++;
    } elseif ($resource['Status'] == 'Rejected') {
        $rejectedCount++;
    }
}

$conn->close();

if (!isset($_SESSION['admin_username'])) {
  header("Location: login_mentee.php");
  exit();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
  <link rel="stylesheet" href="css/admin_resourcesstyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>Admin Dashboard</title>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['admin_icon']); ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
        </span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="CoachAdminPFP.php?username=<?= urlencode($_SESSION['admin_username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
    <ul class="navLinks">
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdmin.php'">
          <ion-icon name="home-outline"></ion-icon>
          <span class="links">Home</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminCourses.php'">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Courses</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminMentees.php'">
          <ion-icon name="person-outline"></ion-icon>
          <span class="links">Mentees</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminMentors.php'">
          <ion-icon name="people-outline"></ion-icon>
          <span class="links">Mentors</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminSession.php'">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='admin-sessions.php'">
          <ion-icon name="chatbubbles-outline"></ion-icon>
          <span class="links">Channels</span>
        </a>
      </li>
      <li class="navList"> <a href="CoachAdminFeedback.php"> <ion-icon name="star-outline"></ion-icon>
                    <span class="links">Feedback</span>
                </a>
            </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachAdminActivities.php'">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList active">
        <a href="#" onclick="window.location='CoachAdminResource.php'">
          <ion-icon name="library-outline"></ion-icon>
          <span class="links">Resource Library</span>
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
      <img src="img/logo.png" alt="Logo"> </div>

     <!-- Resource Library -->
<!-- Resource Library -->
    <div id="resourceLibraryContent" style="padding: 20px;">
    <h1 class="section-title" id="resourceTitle">Manage Resource Library</h1>

    <div class="dashboard">
      <div class="top-bar">
        <button class="filter-btn active" data-status="Approved">
          <ion-icon name="checkmark-circle-outline"></ion-icon>
          <span>Resources</span> <span id="approvedCount"><?php echo $approvedCount; ?></span>
        </button>
        <button class="filter-btn" data-status="Under Review">
          <ion-icon name="time-outline"></ion-icon>
          <span>Pending Resources</span> <span id="pendingresourceCount"><?php echo $pendingCount; ?></span>
        </button>
        <button class="filter-btn" data-status="Rejected">
          <ion-icon name="close-circle-outline"></ion-icon>
          <span>Rejected Resources</span> <span id="rejectedCount"><?php echo $rejectedCount; ?></span>
        </button>
      </div>

 
      <div class="category-bar">
        <button class="category-btn active" data-category="all">All</button>
        <button class="category-btn" data-category="HTML">HTML</button>
        <button class="category-btn" data-category="CSS">CSS</button>
        <button class="category-btn" data-category="Java">Java</button>
        <button class="category-btn" data-category="C#">C#</button>
        <button class="category-btn" data-category="JS">JavaScript</button>
        <button class="category-btn" data-category="PHP">PHP</button>
      </div>
 

      <div id="resourceContainer">
        <?php foreach ($resources as $resource): ?>
        <div class="resource-card"
             data-status="<?php echo htmlspecialchars($resource['Status']); ?>"
             data-category="<?php echo htmlspecialchars($resource['Category']); ?>">

          <?php if (!empty($resource['Resource_Icon']) && file_exists("uploads/" . $resource['Resource_Icon'])): ?>
            <img src="uploads/<?php echo htmlspecialchars($resource['Resource_Icon']); ?>" alt="Resource Icon" />
          <?php else: ?>
            <div class="no-image">No Image</div>
          <?php endif; ?>

          <h2><?php echo htmlspecialchars($resource['Resource_Title']); ?></h2>
          <p><strong>Type:</strong> <?php echo htmlspecialchars($resource['Resource_Type']); ?></p>
          <p><strong>Uploaded By:</strong> <?php echo htmlspecialchars($resource['UploadedBy']); ?></p>

          <?php if (!empty($resource['Resource_File']) && file_exists("uploads/" . $resource['Resource_File'])): ?>
            <p><strong>File:</strong> 
              <a href="view_resource_admin.php?file=<?php echo urlencode($resource['Resource_File']); ?>&title=<?php echo urlencode($resource['Resource_Title']); ?>" 
                 target="_blank" class="view-button">View</a>
            </p>
          <?php else: ?>
            <p><strong>File:</strong> No file uploaded or file not found</p>
          <?php endif; ?>

          <p class="status-label"><strong>Status:</strong> <?php echo htmlspecialchars($resource['Status']); ?></p>
          
          <?php if ($resource['Status'] === 'Rejected' && !empty($resource['Reason'])): ?>
            <p class="rejection-reason"><strong>Rejection Reason:</strong> <?php echo htmlspecialchars($resource['Reason']); ?></p>
          <?php endif; ?>

          <?php if ($resource['Status'] === 'Under Review'): ?>
          <div class="action-buttons">
            <form method="post" action="update_resource_status.php">
              <input type="hidden" name="resource_id" value="<?php echo $resource['Resource_ID']; ?>">
              <button type="submit" class="approve-btn purple-btn" name="action" value="Approved">Approve</button>
              <button type="submit" class="reject-btn purple-btn" name="action" value="Rejected">Reject</button>
            </form>
          </div>
          <?php endif; ?>

        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script src="admin_resource.js"></script>

  <script>
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