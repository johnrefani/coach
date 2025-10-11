<?php
session_start(); // Start the session

require '../connection/db_connection.php';

// --- INITIALIZATION ---
$mentorUsername = $_SESSION['username'] ?? null;
$mentorFullName = "";
$mentorIcon = "../uploads/img/default_pfp.png";
$requestMessage = ""; 

// SESSION CHECK
if (!isset($mentorUsername) || ($_SESSION['user_type'] ?? '') !== 'Mentor') {
  header("Location: ../login.php");
  exit();
}

// FETCH Mentor's Name AND Icon 
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS mentor_name, icon AS mentor_icon FROM users WHERE username = ? AND user_type = 'Mentor'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $mentorUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $mentorFullName = $row['mentor_name'];
  $mentorIcon = !empty($row['mentor_icon']) ? $row['mentor_icon'] : "../uploads/img/default_pfp.png";
  $_SESSION['mentor_name'] = $mentorFullName;
  $_SESSION['mentor_icon'] = $mentorIcon;
} else {
  // Fallback if user data is missing
  $mentorFullName = "Unknown Mentor";
  $_SESSION['mentor_name'] = $mentorFullName;
  $_SESSION['mentor_icon'] = $mentorIcon;
}
$stmt->close();


// --- REQUEST SUBMISSION HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $requestType = $_POST['request_type'];
    $reason = $_POST['reason'];
    // Ensure course_id is an integer or NULL, matching the database schema
    $currentCourseId = ($requestType === 'Course Change' && !empty($_POST['course_id'])) ? (int)$_POST['course_id'] : NULL;

    // Sanitize inputs
    $reason = trim($reason);

    if (!empty($reason) && in_array($requestType, ['Resignation', 'Course Change'])) {
        
        // Prepare dynamic query based on whether current_course_id is set
        // Note: The mentor_requests table now uses the column name 'username'
        if ($currentCourseId !== NULL) {
            $insertQuery = "INSERT INTO mentor_requests (username, request_type, current_course_id, reason) VALUES (?, ?, ?, ?)";
            $stmtInsert = $conn->prepare($insertQuery);
            $stmtInsert->bind_param("siss", $mentorUsername, $requestType, $currentCourseId, $reason);
        } else {
            $insertQuery = "INSERT INTO mentor_requests (username, request_type, reason) VALUES (?, ?, ?)";
            $stmtInsert = $conn->prepare($insertQuery);
            $stmtInsert->bind_param("sss", $mentorUsername, $requestType, $reason);
        }

        if ($stmtInsert->execute()) {
            $requestMessage = "✅ Your **" . htmlspecialchars($requestType) . " Request** has been submitted successfully and is pending review.";
        } else {
            $requestMessage = "❌ Error submitting request: " . $conn->error;
        }
        $stmtInsert->close();
    } else {
        $requestMessage = "⚠️ Please select a valid request type and provide a reason.";
    }
}


// FETCH COURSES ASSIGNED TO THIS MENTOR
// We must select Course_ID for the modal's functionality
$queryCourses = "SELECT Course_ID, Course_Title, Course_Description, Skill_Level, Course_Icon FROM courses WHERE Assigned_Mentor = ?";
$stmtCourses = $conn->prepare($queryCourses);
$stmtCourses->bind_param("s", $mentorFullName);
$stmtCourses->execute();
$coursesResult = $stmtCourses->get_result();

// Save all courses for the dropdown in the modal
$allCourses = [];
if ($coursesResult->num_rows > 0) {
    while ($course = $coursesResult->fetch_assoc()) {
        $allCourses[] = $course;
    }
    // Note: $coursesResult is not used below, so no need to reset pointer
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" /> 
  <link rel="stylesheet" href="../superadmin/css/clock.css"/>
  <link rel="stylesheet" href="css/navigation.css"/>
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>Courses | Mentor</title>
  <style>
    /* ------------------------------------------- */
    /* PROFESSIONAL COURSE LAYOUT STYLES */
    /* ------------------------------------------- */

    /* General Layout */
    .dashboard .main-content {
        padding: 20px 30px;
        min-height: calc(100vh - 80px); 
    }

    .assigned-heading {
        font-size: 2.5em;
        color: #6d4c90; /* Professional primary color */
        border-bottom: 3px solid #f2e3fb;
        padding-bottom: 10px;
        margin-bottom: 40px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* Course Container (Horizontal Scroll/Wrap) */
    .courses-container {
        display: flex; /* Use flex for horizontal arrangement */
        flex-wrap: wrap; /* Allow wrapping if space is tight */
        gap: 25px;
        margin-bottom: 40px;
        padding-bottom: 10px;
    }

    /* Course Card Style */
    .course-card {
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08); /* Stronger, cleaner shadow */
        transition: transform 0.3s, box-shadow 0.3s;
        display: flex;
        flex-direction: row; /* Horizontal layout for content inside card */
        width: 100%;
        max-width: 700px; /* Limit width for a clean look */
        border: 1px solid #eee;
    }

    .course-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
    }
    
    .course-card > img {
        width: 150px; /* Fixed width for the icon/image */
        height: 150px;
        object-fit: cover;
        border-right: 1px solid #eee;
    }

    .course-content-wrapper {
        display: flex;
        flex-direction: column;
        flex-grow: 1;
        padding: 20px;
    }

    .course-info {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .course-info h3 {
        margin: 0;
        font-size: 1.4em;
        color: #333;
        font-weight: 700;
    }

    .skill-level {
        display: inline-block;
        background-color: #ff6f61; /* Secondary color for distinction */
        color: white;
        padding: 4px 12px;
        border-radius: 4px;
        font-size: 0.85em;
        font-weight: 600;
        align-self: flex-start;
        margin-bottom: 10px;
        text-transform: capitalize;
    }

    .course-description {
        color: #555;
        font-size: 0.95em;
        margin-bottom: 15px;
        line-height: 1.5;
        flex-grow: 1;
    }

    .appeal-change-btn {
        background-color: #6d4c90; /* Primary color for action */
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        transition: background-color 0.3s;
        align-self: flex-start; /* Align button to the start */
        text-transform: uppercase;
        font-size: 0.9em;
    }

    .appeal-change-btn:hover {
        background-color: #5b3c76;
    }
    
    /* Fallback for no courses assigned */
    .courses-container > div[style] {
        width: 100%;
        max-width: 700px;
        margin-left: auto;
        margin-right: auto;
    }

    /* Course Details/Reminder Block */
    .course-details {
        background-color: #e6f7ff; 
        padding: 30px;
        border-radius: 10px;
        margin-top: 20px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        border-left: 5px solid #00aaff;
        margin-bottom: 40px;
    }

    .course-details h2 {
        color: #00aaff;
        font-size: 1.8em;
        margin-top: 0;
        margin-bottom: 10px;
        font-weight: 600;
    }

    .course-reminder {
        color: #333;
        line-height: 1.6;
        margin-bottom: 20px;
    }

    .start-course-btn {
        background-color: #00aaff;
        color: white;
        padding: 12px 25px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: bold;
        transition: background-color 0.3s;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .start-course-btn:hover {
        background-color: #0088cc;
    }

    /* --------------------------------------- */
    /* RESIGNATION/APPEAL REQUEST STYLES */
    /* --------------------------------------- */
    .request-section {
        background-color: #f7f7f7;
        padding: 25px;
        border-radius: 8px;
        margin-top: 30px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        border-left: 5px solid #ff6f61; /* Use a prominent color */
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .request-section h3 {
        color: #6d4c90;
        margin-top: 0;
        font-size: 1.5em;
    }
    .request-section p {
        color: #555;
        margin-bottom: 0;
    }
    .request-section button {
        background-color: #ff6f61; 
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: bold;
        transition: background-color 0.3s;
    }
    .request-section button:hover {
        background-color: #e55a4f;
    }
    /* Modal Styles */
    .modal {
        display: none; 
        position: fixed; 
        z-index: 1000; 
        left: 0;
        top: 0;
        width: 100%; 
        height: 100%; 
        overflow: auto; 
        background-color: rgba(0,0,0,0.6); /* Slightly darker overlay */
    }
    .modal-content {
        background-color: #fff;
        margin: 10% auto; 
        padding: 30px;
        border-radius: 10px;
        width: 90%; 
        max-width: 500px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        position: relative;
    }
    .close-btn {
        color: #aaa;
        float: right;
        font-size: 32px;
        font-weight: normal;
        line-height: 1;
        position: absolute;
        top: 15px;
        right: 25px;
    }
    .close-btn:hover, .close-btn:focus {
        color: #6d4c90;
        text-decoration: none;
        cursor: pointer;
    }
    .modal-content h3 {
        color: #6d4c90;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
        margin-bottom: 20px;
        font-weight: 600;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #333;
        font-size: 0.95em;
    }
    .form-group select, .form-group textarea {
        width: 100%;
        padding: 12px;
        margin-bottom: 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        box-sizing: border-box;
        font-size: 1em;
    }
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    .modal-submit-btn {
        background-color: #6d4c90;
        color: white;
        padding: 12px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: bold;
        transition: background-color 0.3s;
        width: 100%;
        margin-top: 10px;
    }
    .modal-submit-btn:hover {
        background-color: #5b3c76;
    }
    #course_id_group {
        display: none; 
    }
    .status-message {
        padding: 15px;
        margin-bottom: 25px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.95em;
    }
    .status-message.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .status-message.error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    .status-message.warning {
        background-color: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }
    /* Responsive adjustment for small screens */
    @media (max-width: 600px) {
        .course-card {
            flex-direction: column; /* Stack vertically on small screens */
            max-width: 100%;
        }
        .course-card > img {
            width: 100%;
            height: auto;
            max-height: 150px;
        }
        .request-section {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
    }

  </style>
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
  <h2 class="assigned-heading">Assigned Courses</h2>

    <?php if (!empty($requestMessage)): ?>
        <div class="status-message <?= strpos($requestMessage, '✅') !== false ? 'success' : (strpos($requestMessage, '❌') !== false ? 'error' : 'warning') ?>">
            <?= $requestMessage ?>
        </div>
    <?php endif; ?>

    <div class="courses-container">
  <?php if (!empty($allCourses)): ?>
    <?php foreach($allCourses as $course): ?>
      <div class="course-card">
        <img src="../uploads/<?= htmlspecialchars($course['Course_Icon']) ?>" alt="Course Icon">
        
        <div class="course-content-wrapper">
            <div class="course-info">
                <h3><?= htmlspecialchars($course['Course_Title']) ?></h3>
                <div class="skill-level"><?= htmlspecialchars($course['Skill_Level']) ?></div>
            </div>
            
            <div class="course-description">
                <?= htmlspecialchars($course['Course_Description']) ?>
            </div>

            <button class="appeal-change-btn" 
                    data-course-id="<?= htmlspecialchars($course['Course_ID']) ?>" 
                    data-course-title="<?= htmlspecialchars($course['Course_Title']) ?>" 
                    onclick="openRequestModal('Course Change', this.getAttribute('data-course-id'))">
                Appeal Course Change
            </button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div style="text-align: center; color: #6d4c90; font-size: 18px; background: #f2e3fb; padding: 30px; border-radius: 12px;">
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

<div class="request-section">
    <div>
        <h3>Need to make a change?</h3>
        <p>You can formally request a change of course assignment or submit your resignation as a mentor.</p>
    </div>
    <button id="openRequestModal" onclick="openRequestModal()">Submit a Request</button>
</div>
</section>

<div id="mentorRequestModal" class="modal">
  <div class="modal-content">
    <span class="close-btn" onclick="closeRequestModal()">&times;</span>
    <h3>Mentor Request Form</h3>
    <form method="POST" action="courses.php">
      <div class="form-group">
        <label for="request_type">Request Type:</label>
        <select id="request_type" name="request_type" required onchange="toggleCourseSelection(this.value)">
          <option value="">-- Select Request Type --</option>
          <option value="Resignation">Resignation (Complete Withdrawal)</option>
          <option value="Course Change">Course Change (Appeal to be assigned a different course)</option>
        </select>
      </div>

      <div class="form-group" id="course_id_group">
        <label for="course_id">Course to Change From:</label>
        <select id="course_id" name="course_id">
          <option value="">-- Select Course (Optional) --</option>
          <?php foreach($allCourses as $course): ?>
            <option value="<?= htmlspecialchars($course['Course_ID']) ?>">
                <?= htmlspecialchars($course['Course_Title']) ?> (<?= htmlspecialchars($course['Skill_Level']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <small style="color:#888;">Select the specific course you wish to appeal a change for, if applicable.</small>
      </div>

      <div class="form-group">
        <label for="reason">Reason for Request:</label>
        <textarea id="reason" name="reason" rows="5" placeholder="Clearly state your reason for the request. For course change, suggest a suitable replacement if possible." required></textarea>
      </div>

      <button type="submit" name="submit_request" class="modal-submit-btn">Submit Request</button>
    </form>
  </div>
</div>
<?php
  // Close the final statement and the connection
  if (isset($stmtCourses) && $stmtCourses) {
    $stmtCourses->close(); 
  }
  $conn->close();
?>

<script src="admin.js"></script>
<script src="js/navigation.js"></script>
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script>
    // JavaScript for Modal
    const modal = document.getElementById("mentorRequestModal");
    const requestTypeSelect = document.getElementById("request_type");
    const courseIdGroup = document.getElementById("course_id_group");
    const courseIdSelect = document.getElementById("course_id");

    function openRequestModal(type = '', courseId = '') {
      modal.style.display = "block";
      if (type) {
          requestTypeSelect.value = type;
          toggleCourseSelection(type, courseId);
      } else {
          requestTypeSelect.value = '';
          toggleCourseSelection('');
      }
    }

    function closeRequestModal() {
      modal.style.display = "none";
    }

    function toggleCourseSelection(type, courseId = '') {
      if (type === 'Course Change') {
          courseIdGroup.style.display = 'block';
          if (courseId) {
            courseIdSelect.value = courseId;
          } else {
            courseIdSelect.value = ''; // Clear if opening from the main button
          }
      } else {
          courseIdGroup.style.display = 'none';
          courseIdSelect.value = '';
      }
    }
    
    // Close the modal if the user clicks anywhere outside of it
    window.onclick = function(event) {
      if (event.target == modal) {
        closeRequestModal();
      }
    }

    // --- Navigation/Logout Script (Added for completeness) ---

    // The navLinks and defaultTab variables might be defined in navigation.js, 
    // but the logic below is necessary if they are not. Assuming basic functionality:
    
    function confirmLogout(event) {
        event.preventDefault(); 
        document.getElementById('logoutDialog').style.display = 'block';
    }

    document.getElementById('cancelLogout').onclick = function() {
        document.getElementById('logoutDialog').style.display = 'none';
    };

    document.getElementById('confirmLogoutBtn').onclick = function() {
        window.location.href = '../logout.php'; 
    };

    // If navLinks is not defined globally by navigation.js, this will error.
    // If it is defined, the nav logic below is redundant and should be managed by the external script.
    // Assuming `navigation.js` handles the nav links, I will comment out the partial logic here.
    /*
    const navLinks = document.querySelectorAll(".navLinks .navList");
    const defaultTab = document.querySelector(".navLinks .navList.active");

    navLinks.forEach(link => link.classList.remove("active"));
    if (defaultTab) {
        defaultTab.classList.add("active");
    }
    */
    
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