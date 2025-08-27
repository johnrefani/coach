<?php
session_start(); // Start the session
// Connect to database
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/admin_dashboardstyle.css"/>
    <link rel="stylesheet" href="css/admin_mentorsstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Manage Mentors</title>
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
                <a href="CoachAdmin.php"> <ion-icon name="home-outline"></ion-icon>
                    <span class="links">Home</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminCourses.php"> <ion-icon name="book-outline"></ion-icon>
                    <span class="links">Courses</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminMentees.php"> <ion-icon name="person-outline"></ion-icon>
                    <span class="links">Mentees</span>
                </a>
            </li>
             <li class="navList active">
                <a href="CoachAdminMentors.php"> <ion-icon name="people-outline"></ion-icon>
                    <span class="links">Mentors</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminSession.php"> <ion-icon name="calendar-outline"></ion-icon>
                    <span class="links">Sessions</span>
                </a>
            </li>
            <li class="navList"> <a href="CoachAdminFeedback.php"> <ion-icon name="star-outline"></ion-icon>
                    <span class="links">Feedback</span>
                </a>
            </li>
            <li class="navList">
                <a href="admin-sessions.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
                    <span class="links">Channels</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminActivities.php"> <ion-icon name="clipboard"></ion-icon>
                    <span class="links">Activities</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminResource.php"> <ion-icon name="library-outline"></ion-icon>
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

      <h1>Manage Mentors</h1>
    <div class="dashboard">
        <div class="top-bar">
  <button id="btnMentors">
    <ion-icon name="people-outline"></ion-icon>
    <span>Coach Mentors</span> <span id="approvedCount">0</span>
  </button>
  <button id="btnApplicants">
    <ion-icon name="person-add-outline"></ion-icon>
    <span>Applicants</span> <span id="applicantCount">0</span>
  </button>
  <button id="btnRejected">
    <ion-icon name="close-circle-outline"></ion-icon>
    <span>Rejected</span> <span id="rejectedCount">0</span>
  </button>
</div>


        <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search Mentors / Applicants...">
        <button onclick="searchMentors()" class="search-btn"><ion-icon name="search-outline"></ion-icon></button>
    </div>

        <div id="tableContainer"></div>
        <div id="detailView" class="hidden"></div>
    </div>

<script>
const mentorData = <?php
            $sql = "SELECT * FROM applications";
            $result = $conn->query($sql);
            $data = [];

            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }

            echo json_encode($data);
            $conn->close();
        ?>;

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
<script src="admin_mentors.js"></script>
<script src="admin.js"></script>
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

</body>
</html>
