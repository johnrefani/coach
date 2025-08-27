<?php
session_start();

// CONNECT TO DATABASE
$servername = "localhost";
$username = "root";
$password = ""; // use your actual password if set
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// SESSION CHECK
if (!isset($_SESSION['admin_username'])) {
  header("Location: loginadmin.php");
  exit();
}

// FETCH Admin_Name AND Admin_Icon BASED ON USERNAME
$adminUsername = $_SESSION['admin_username'];
$sql = "SELECT Admin_Name, Admin_Icon FROM admins WHERE Admin_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $adminUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['admin_name'] = $row['Admin_Name'];
  
  // Check if Admin_Icon exists and is not empty
  if (isset($row['Admin_Icon']) && !empty($row['Admin_Icon'])) {
    $_SESSION['admin_icon'] = $row['Admin_Icon'];
  } else {
    $_SESSION['admin_icon'] = "img/default_pfp.png";
  }
} else {
  $_SESSION['admin_name'] = "Unknown User";
  $_SESSION['admin_icon'] = "img/default_pfp.png";
}

// GET COUNTS FOR DASHBOARD WIDGETS
// Count mentees
$menteeQuery = "SELECT COUNT(*) as mentee_count FROM mentee_profiles";
$menteeResult = $conn->query($menteeQuery);
$menteeCount = 0;
if ($menteeResult && $menteeRow = $menteeResult->fetch_assoc()) {
  $menteeCount = $menteeRow['mentee_count'];
}

// Count approved mentors
$mentorQuery = "SELECT COUNT(*) as mentor_count FROM applications WHERE Status = 'Approved'";
$mentorResult = $conn->query($mentorQuery);
$mentorCount = 0;
if ($mentorResult && $mentorRow = $mentorResult->fetch_assoc()) {
  $mentorCount = $mentorRow['mentor_count'];
}

// Count applicants (Under Review)
$applicantQuery = "SELECT COUNT(*) as applicant_count FROM applications WHERE Status = 'Under Review'";
$applicantResult = $conn->query($applicantQuery);
$applicantCount = 0;
if ($applicantResult && $applicantRow = $applicantResult->fetch_assoc()) {
  $applicantCount = $applicantRow['applicant_count'];
}

// Count resources
$resourceQuery = "SELECT COUNT(*) as resource_count FROM resources WHERE Status = 'Approved'";
$resourceResult = $conn->query($resourceQuery);
$resourceCount = 0;
if ($resourceResult && $resourceRow = $resourceResult->fetch_assoc()) {
  $resourceCount = $resourceRow['resource_count'];
}

// Count admins
$adminQuery = "SELECT COUNT(*) as admin_count FROM admins";
$adminResult = $conn->query($adminQuery);
$adminCount = 0;
if ($adminResult && $adminRow = $adminResult->fetch_assoc()) {
  $adminCount = $adminRow['admin_count'];
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
  <link rel="stylesheet" href="css/adminhomestyle.css" />
  <link rel="stylesheet" href="css/clockstyle.css" />
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
            <li class="navList active">
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
             <li class="navList">
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

    <div id="homeContent" style="padding: 20px;">
    <section class="widget-section">
  <h2>Moderator <span class="preview">Home page</span></h2>

  <section class="clock-section">
  <div class="clock-container">
    <div class="time">
      <span id="hours">00</span>:<span id="minutes">00</span>:<span id="seconds">00</span>
      <span id="ampm">AM</span>
    </div>
    <div class="date" id="date">
      Wed, 11 January 2023
    </div>
  </div>
</section>

<script>
  function updateClock() {
    const now = new Date();
    let hours = now.getHours();
    const minutes = now.getMinutes();
    const seconds = now.getSeconds();
    const ampm = hours >= 12 ? 'PM' : 'AM';

    hours = hours % 12;
    hours = hours ? hours : 12;

    document.getElementById('hours').textContent = String(hours).padStart(2, '0');
    document.getElementById('minutes').textContent = String(minutes).padStart(2, '0');
    document.getElementById('seconds').textContent = String(seconds).padStart(2, '0');
    document.getElementById('ampm').textContent = ampm;

    const options = { weekday: 'short', day: '2-digit', month: 'long', year: 'numeric' };
    document.getElementById('date').textContent = now.toLocaleDateString('en-US', options);
  }

  setInterval(updateClock, 1000);
  updateClock();
</script>

<div class="widget-grid">

      <!-- Second Row -->
      <div class="widget blue full">
      <img src="img/mentee.png" alt="Bookmark Icon" class="img-icon" />
      <div class="details">
        <h3><?php echo $menteeCount + 1; ?></h3>
        <p>MENTEE</p>
        <span class="note">Total Mentees</span>
      </div>
    </div>
    <div class="widget green full">
    <img src="img/mentor.png" alt="Bookmark Icon" class="img-icon" />
      <div class="details">
        <h3><?php echo $mentorCount; ?></h3>
        <p>MENTOR</p>
        <span class="note">Total Mentors</span>
      </div>
    </div>
    <div class="widget orange full">
    <img src="img/applicants.png" alt="Bookmark Icon" class="img-icon" />
      <div class="details">
        <h3><?php echo $applicantCount; ?></h3>
        <p>APPLICANTS</p>
        <span class="note">Total Applicants</span>
      </div>
    </div>
    <div class="widget red full">
    <img src="img/resources.png" alt="Bookmark Icon" class="img-icon" />
      <div class="details">
        <h3><?php echo $resourceCount; ?></h3>
        <p>RESOURCES</p>
        <span class="note">Total Courses</span>
      </div>
    </div>
  </div>
</section>

<section class="quick-links" style="margin-top: 170px;">
  <h3>Quick Links</h3>
  <div class="links-container">
    <a href="#" onclick="window.location='CoachAdminMentors.php'" class="quick-link">
      <span class="icon1">🗓️</span>
      <span>Approval Applicants</span>
    </a>
    <a href="#" onclick="window.location='CoachAdminMentees.php'" class="quick-link">
      <span class="icon1">🛡️</span>
      <span>Manage Mentees</span>
    </a>
  </div>
</section>

    </div>
    
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script src="admin.js"></script>

  <script>
    // Modal Logic
    function openEditModal(id, title, description, level) { // Removed image path - handle display separately if needed
      document.getElementById('editModal').style.display = 'block';
      document.getElementById('edit_id').value = id;
      document.getElementById('edit_title').value = title;
      document.getElementById('edit_description').value = description;
      document.getElementById('edit_level').value = level;
      document.getElementById('edit_image').value = ''; // Clear file input
      // Optional: Display current image name/thumbnail if needed (requires fetching it or passing it)
      // document.getElementById('current_image_display').innerHTML = `Current Image: ${imageFilename}`;
    }

    function closeEditModal() {
      document.getElementById('editModal').style.display = 'none';
       document.getElementById('editCourseForm').reset(); // Reset form on close
    }

    // Live Preview Logic for Add Form
    const titleInput = document.getElementById("title");
    const descriptionInput = document.getElementById("description");
    const levelSelect = document.getElementById("level");
    const imageInput = document.getElementById("image");
    const previewTitle = document.getElementById("previewTitle");
    const previewDescription = document.getElementById("previewDescription");
    const previewLevel = document.getElementById("previewLevel");
    const previewImage = document.getElementById("previewImage");

    if(titleInput) {
        titleInput.addEventListener("input", function() {
         previewTitle.textContent = this.value.trim() || "Course Title";
        });
    }
    if(descriptionInput) {
        descriptionInput.addEventListener("input", function() {
          previewDescription.textContent = this.value.trim() || "Course Description";
        });
    }
    if(levelSelect) {
        levelSelect.addEventListener("change", function() {
          previewLevel.textContent = this.value || "Skill Level";
        });
    }
    if(imageInput) {
        imageInput.addEventListener("change", function() {
          const file = this.files[0];
          if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
              previewImage.src = e.target.result;
              previewImage.style.display = "block";
            };
            reader.onerror = function() {
                console.error("Error reading file for preview.");
                previewImage.src = "";
                previewImage.style.display = "none";
            }
            reader.readAsDataURL(file);
          } else {
              previewImage.src = "";
              previewImage.style.display = "none";
          }
        });
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