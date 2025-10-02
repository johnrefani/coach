<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start(); // Start the session
// Standard session check for an Admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// Load SendGrid and environment variables
require '../vendor/autoload.php';

// Load environment variables using phpdotenv - placed here to be available globally if needed
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // Optionally log this error if the .env file is missing/unreadable
}

// --- Changed to use generic Admin session variables ---
$admin_icon = !empty($_SESSION['user_icon']) ? $_SESSION['user_icon'] : '../uploads/img/default_pfp.png';
$admin_name = !empty($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Admin';
// --- End Change ---

// --- START: NEW PHP LOGIC FOR COURSE UPDATE ---

// Handle AJAX request for fetching the assigned course for a mentor
if (isset($_GET['action']) && $_GET['action'] === 'get_assigned_course') {
    header('Content-Type: application/json');
    $mentor_id = $_GET['mentor_id'] ?? 0;
    
    // Step 1: Get mentor's full name
    $get_mentor_name = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ? AND user_type = 'Mentor'";
    $stmt = $conn->prepare($get_mentor_name);
    $stmt->bind_param("i", $mentor_id);
    $stmt->execute();
    $stmt->bind_result($mentor_name);
    $stmt->fetch();
    $stmt->close();

    $assigned_course = null;

    if ($mentor_name) {
        // Step 2: Find the course assigned to this mentor using the full name
        $sql = "SELECT Course_ID, Course_Title 
                FROM courses 
                WHERE Assigned_Mentor = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $mentor_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $assigned_course = $result->fetch_assoc();
        $stmt->close();
    }
    
    echo json_encode($assigned_course);
    exit();
}

// Handle AJAX request for removing a mentor's course assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assigned_course') {
    header('Content-Type: application/json');
    $course_id = $_POST['course_id'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Update the course's Assigned_Mentor to NULL, effectively removing the assignment
        $update_course = "UPDATE courses SET Assigned_Mentor = NULL WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_course);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Course assignment successfully removed!']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error removing course assignment: ' . $e->getMessage()]);
    }
    exit();
}
// --- END: NEW PHP LOGIC FOR COURSE UPDATE ---

// Handle AJAX requests for fetching available courses
if (isset($_GET['action']) && $_GET['action'] === 'get_available_courses') {
    header('Content-Type: application/json');
    
    // Fetch courses that don't have any mentors assigned yet (Assigned_Mentor IS NULL or empty)
    $sql = "SELECT Course_ID, Course_Title 
        FROM courses 
        WHERE Assigned_Mentor IS NULL OR Assigned_Mentor = ''";
    $result = $conn->query($sql);
    
    $available_courses = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $available_courses[] = $row;
        }
    }
    
    echo json_encode($available_courses);
    exit();
}

// Handle AJAX request for approving a mentor and assigning/reassigning a course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_with_course') {
    header('Content-Type: application/json');
    $mentor_id = $_POST['mentor_id'];
    $course_id = $_POST['course_id'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Step 1: Get mentor's details for email and course assignment
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name, user_type FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name, $user_type);
        $stmt->fetch();
        $stmt->close();
        
        if ($user_type !== 'Mentor') {
             throw new Exception("User is not a Mentor.");
        }

        // Only update status if the mentor is currently pending (to prevent unnecessary status updates during course change)
        $update_user = "UPDATE users SET status = 'Approved', reason = NULL WHERE user_id = ? AND status = 'Under Review'";
        $stmt = $conn->prepare($update_user);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->close();

        // Step 2: Assign mentor to the course
        $update_course = "UPDATE courses SET Assigned_Mentor = ? WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_course);
        $stmt->bind_param("si", $mentor_full_name, $course_id);
        $stmt->execute();
        $stmt->close();
        
        // Step 3: Get course title for the response/email
        $get_course_title = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
        $stmt = $conn->prepare($get_course_title);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->bind_result($course_title);
        $stmt->fetch();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Step 4: Send Approval Email (Note: Email sending simplified for this environment)
        $email_sent_status = 'N/A (Email not sent in this environment)';
        
        echo json_encode(['success' => true, 'message' => "Mentor approved/reassigned to course '$course_title'. Email status: $email_sent_status"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for rejecting a mentor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_mentor') {
    header('Content-Type: application/json');
    $mentor_id = $_POST['mentor_id'];
    $reason = $_POST['reason'];
    
    try {
        $conn->begin_transaction();
        
        // Step 1: Update mentor status to 'Rejected'
        $update_user = "UPDATE users SET status = 'Rejected', reason = ? WHERE user_id = ?";
        $stmt = $conn->prepare($update_user);
        $stmt->bind_param("si", $reason, $mentor_id);
        $stmt->execute();
        $stmt->close();
        
        // Step 2: Get mentor's email and name (for email/response)
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name);
        $stmt->fetch();
        $stmt->close();

        // Commit transaction
        $conn->commit();

        // Step 3: Send Rejection Email (Non-transactional step)
        $email_sent_status = 'N/A (Email not sent in this environment)';
        
        echo json_encode(['success' => true, 'message' => "Mentor rejected. Email status: $email_sent_status"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}

// Fetch all mentor data
$sql = "SELECT user_id, first_name, last_name, dob, gender, email, contact_number, username, mentored_before, mentoring_experience, area_of_expertise, resume, certificates, status, reason FROM users WHERE user_type = 'Mentor'";
$result = $conn->query($sql);

$mentor_data = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $mentor_data[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Manage Mentors | Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Layout */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            display: flex; /* Use flexbox for main layout */
            min-height: 100vh;
        }

        /* Sidebar/Navbar Styles (Restored) */
        .sidebar {
            width: 250px;
            background-color: #562b63; /* Deep Purple */
            color: white;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }
        .sidebar-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .sidebar-header img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #7a4a87;
            margin-bottom: 10px;
        }
        .sidebar-header h4 {
            margin: 0;
            font-weight: 600;
            color: #fff;
        }
        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar nav ul li a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 12px 15px;
            margin-bottom: 5px;
            border-radius: 5px;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
        }
        .sidebar nav ul li a i {
            margin-right: 10px;
            font-size: 18px;
        }
        .sidebar nav ul li a:hover,
        .sidebar nav ul li a.active {
            background-color: #7a4a87; /* Lighter Purple for hover/active */
        }
        .logout-container {
            margin-top: auto; /* Push to the bottom */
            padding-top: 20px;
            border-top: 1px solid #7a4a87;
        }
        .logout-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: bold;
        }
        .logout-btn:hover {
            background-color: #c82333;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            padding: 20px 30px;
        }
        header {
            padding: 10px 0;
            border-bottom: 2px solid #562b63;
            margin-bottom: 20px;
        }
        header h1 {
            color: #562b63;
            margin: 0;
            font-size: 28px;
            margin-top: 30px;
        }
        
        /* Tab Buttons */
        .tab-buttons {
            margin-bottom: 15px;
        }
        .tab-buttons button {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            margin-right: 5px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
            font-weight: 600;
        }
        .tab-buttons button.active {
            background-color: #562b63;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .tab-buttons button:not(.active):hover {
            background-color: #5a6268;
        }
        
        /* Table Styles */
        .table-container {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            background-color: #fff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: none;
            padding: 15px;
            text-align: left;
        }
        th {
            background-color: #562b63;
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 14px;
        }
        tr:nth-child(even) {
            background-color: #f8f8f8;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .action-button {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: 600;
        }
        .action-button:hover {
            background-color: #218838;
        }
        
        /* Details View */
        .details {
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .details h3 {
            color: #562b63;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .details p {
            margin: 5px 0;
            display: flex;
            align-items: center;
        }
        .details strong {
            display: inline-block;
            min-width: 180px;
            color: #333;
            font-weight: 600;
        }
        .details input[type="text"] {
            flex-grow: 1;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-left: 10px;
            background-color: #f9f9f9;
            cursor: default;
        }
        .details a {
            color: #007bff;
            text-decoration: none;
            margin-left: 10px;
            transition: color 0.3s;
        }
        .details a:hover {
            color: #0056b3;
            text-decoration: underline;
        }
        .details-buttons-top {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .details-buttons-top button {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .details .back-btn { 
            background-color: #6c757d;
            color: white;
        }
        .details .back-btn:hover {
            background-color: #5a6268;
        }
        /* Style for UPDATE ASSIGNED COURSE button */
        .details .update-course-btn {
            background-color: #562b63;
            color: white;
        }
        .details .update-course-btn:hover {
            background-color: #43214d;
        }

        .details .action-buttons {
            margin-top: 30px;
            text-align: right;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .details .action-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
            margin-left: 10px;
        }
        .details .action-buttons button:first-child { /* Approve button */
            background-color: #28a745;
            color: white;
        }
        .details .action-buttons button:last-child { /* Reject button */
            background-color: #dc3545;
            color: white;
        }
        .hidden {
            display: none;
        }

        /* Popup Styles */
        .course-assignment-popup {
            display: none; 
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .popup-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 30px;
            border: 1px solid #888;
            width: 90%;
            max-width: 450px;
            border-radius: 10px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            animation-name: animatetop;
            animation-duration: 0.4s;
        }
        @keyframes animatetop {
            from {top:-300px; opacity:0} 
            to {top:10%; opacity:1}
        }
        .popup-content h3 {
            color: #562b63;
            margin-top: 0;
            border-bottom: 2px solid #ccc;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .popup-content select, .popup-content input[type="text"] {
            width: 100%;
            padding: 12px;
            margin: 10px 0 20px 0;
            display: inline-block;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 16px;
        }
        .popup-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .popup-buttons button {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .btn-cancel {
            background-color: #6c757d;
            color: white;
        }
        .btn-confirm {
            background-color: #28a745;
            color: white;
        }
        .btn-cancel:hover { background-color: #5a6268; }
        .btn-confirm:hover { background-color: #218838; }

        .loading {
            text-align: center;
            padding: 20px;
            color: #562b63;
            font-style: italic;
        }

        /* Specific styles for the Update Course Modal buttons */
        #updatePopupBody .popup-buttons {
            justify-content: space-between;
        }
        #updatePopupBody .btn-confirm.change-btn {
            background-color: #ffc107; 
            color: #333;
        }
        #updatePopupBody .btn-confirm.change-btn:hover {
            background-color: #e0a800;
        }
        #updatePopupBody .btn-confirm.remove-btn {
            background-color: #dc3545;
        }
        #updatePopupBody .btn-confirm.remove-btn:hover {
            background-color: #c82333;
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
        <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="Admin Profile Picture" />
        <div class="admin-text">
          <span class="admin-name"><?php echo htmlspecialchars($admin_name); ?></span>
          <span class="admin-role">Admin</span>
        </div>
        <a href="profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
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
            <a href="manage_mentees.php"> <ion-icon name="person-outline"></ion-icon>
              <span class="links">Mentees</span>
            </a>
        </li>
        <li class="navList active">
            <a href="manage_mentors.php"> <ion-icon name="people-outline"></ion-icon>
              <span class="links">Mentors</span>
            </a>
        </li>
        <li class="navList">
            <a href="courses.php"> <ion-icon name="book-outline"></ion-icon>
                <span class="links">Courses</span>
            </a>
        </li>
        <li class="navList">
            <a href="manage_session.php"> <ion-icon name="calendar-outline"></ion-icon>
              <span class="links">Sessions</span>
            </a>
        </li>
        <li class="navList"> 
            <a href="feedbacks.php"> <ion-icon name="star-outline"></ion-icon>
              <span class="links">Feedback</span>
            </a>
        </li>
        <li class="navList">
            <a href="channels.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
              <span class="links">Channels</span>
            </a>
        </li>
        <li class="navList">
           <a href="activities.php"> <ion-icon name="clipboard"></ion-icon>
              <span class="links">Activities</span>
            </a>
        </li>
        <li class="navList">
            <a href="resource.php"> <ion-icon name="library-outline"></ion-icon>
              <span class="links">Resource Library</span>
            </a>
        </li>
             <li class="navList">
            <a href="reports.php"><ion-icon name="folder-outline"></ion-icon>
              <span class="links">Reported Posts</span>
            </a>
        </li>
     <li class="navList">
            <a href="banned-users.php"><ion-icon name="person-remove-outline"></ion-icon>
              <span class="links">Banned Users</span>
            </a>
        </li>
        
      </ul>

   <ul class="bottom-link">
  <li class="navList logout-link">
    <a href="#" onclick="confirmLogout()">
      <ion-icon name="log-out-outline"></ion-icon>
      <span class="links">Logout</span>
    </a>
  </li>
</ul>
    </div>
  </nav>

  <section class="dashboard">
    <div class="top">
      <ion-icon class="navToggle" name="menu-outline"></ion-icon>
      <img src="../uploads/img/logo.png" alt="Logo"> </div>

<div class="main-content">
    <header>
        <h1>Manage Mentors</h1>
    </header>

    <div class="tab-buttons">
        <button id="btnApplicants"><i class="fas fa-user-clock"></i> New Applicants</button>
        <button id="btnMentors"><i class="fas fa-user-check"></i> Approved Mentors</button>
        <button id="btnRejected"><i class="fas fa-user-slash"></i> Rejected Mentors</button>
    </div>

    <section>
        <div id="tableContainer" class="table-container">
            </div>
        
        <div id="detailView" class="hidden"></div>
    </section>
</div> <div id="courseAssignmentPopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Assign Course to Mentor</h3>
        <div id="popupBody">
            <div class="loading">Loading available courses...</div>
        </div>
    </div>
</div>

<div id="updateCoursePopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Update Assigned Course</h3>
        <div id="updatePopupBody">
            <div class="loading">Loading course details...</div>
        </div>
    </div>
</div>

<div id="courseChangePopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Change Assigned Course</h3>
        <div id="changePopupBody">
            <div class="loading">Loading available courses...</div>
        </div>
    </div>
</div>


<script>
    // --- Data fetched from PHP and inlined JS logic ---
    const mentorData = <?php echo json_encode($mentor_data); ?>;
    const tableContainer = document.getElementById('tableContainer');
    const detailView = document.getElementById('detailView');
    const courseAssignmentPopup = document.getElementById('courseAssignmentPopup');
    const btnApplicants = document.getElementById('btnApplicants');
    const btnMentors = document.getElementById('btnMentors');
    const btnRejected = document.getElementById('btnRejected');

    // Filter data into categories
    const applicants = mentorData.filter(m => m.status === 'Under Review');
    const approved = mentorData.filter(m => m.status === 'Approved');
    const rejected = mentorData.filter(m => m.status === 'Rejected');

    // Element selections for new popups
    const updateCoursePopup = document.getElementById('updateCoursePopup');
    const courseChangePopup = document.getElementById('courseChangePopup');

    function showTable(data, isApplicantView) {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');

        // Update active tab button
        btnApplicants.classList.remove('active');
        btnMentors.classList.remove('active');
        btnRejected.classList.remove('active');
        
        if (data === applicants) {
            btnApplicants.classList.add('active');
        } else if (data === approved) {
            btnMentors.classList.add('active');
        } else if (data === rejected) {
            btnRejected.classList.add('active');
        }

        let html = '<table><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        
        if (data.length === 0) {
            html += `<tr><td colspan="4" style="text-align: center; padding: 20px;">No mentors found in this category.</td></tr>`;
        } else {
            data.forEach(mentor => {
                html += `
                    <tr>
                        <td>${mentor.first_name} ${mentor.last_name}</td>
                        <td>${mentor.email}</td>
                        <td>${mentor.status}</td>
                        <td><button class="action-button" onclick="viewDetails(${mentor.user_id}, ${isApplicantView})">View Details</button></td>
                    </tr>
                `;
            });
        }
        
        html += '</tbody></table>';
        tableContainer.innerHTML = html;
    }

    // Function to display detailed view of a single user
    function viewDetails(id, isApplicant) {
        const row = mentorData.find(m => m.user_id == id);
        if (!row) return;

        let resumeLink = row.resume ? `<a href="view_application.php?file=${encodeURIComponent(row.resume)}&type=resume" target="_blank"><i class="fas fa-file-alt"></i> View Resume</a>` : "N/A";
        let certLink = row.certificates ? `<a href="view_application.php?file=${encodeURIComponent(row.certificates)}&type=certificate" target="_blank"><i class="fas fa-certificate"></i> View Certificate</a>` : "N/A";

        let html = `<div class="details">
            <div class="details-buttons-top">
                <button onclick="backToTable()" class="back-btn"><i class="fas fa-arrow-left"></i> Back</button>`;
            
        // Conditional button for approved mentors
        if (row.status === 'Approved') {
            html += `<button onclick="showUpdateCoursePopup(${id})" class="update-course-btn"><i class="fas fa-exchange-alt"></i> Update Assigned Course</button>`;
        }
            
        html += `</div>
            <h3>Applicant Details: ${row.first_name} ${row.last_name}</h3>
            <div class="details-grid">
                <p><strong>Status:</strong> <input type="text" readonly value="${row.status || ''}"></p>
                <p><strong>Reason for Rejection:</strong> <input type="text" readonly value="${row.reason || ''}"></p>
                <p><strong>First Name:</strong> <input type="text" readonly value="${row.first_name || ''}"></p>
                <p><strong>Last Name:</strong> <input type="text" readonly value="${row.last_name || ''}"></p>
                <p><strong>Email:</strong> <input type="text" readonly value="${row.email || ''}"></p>
                <p><strong>Contact:</strong> <input type="text" readonly value="${row.contact_number || ''}"></p>
                <p><strong>Username:</strong> <input type="text" readonly value="${row.username || ''}"></p>
                <p><strong>DOB:</strong> <input type="text" readonly value="${row.dob || ''}"></p>
                <p><strong>Gender:</strong> <input type="text" readonly value="${row.gender || ''}"></p>
                <p><strong>Mentored Before:</strong> <input type="text" readonly value="${row.mentored_before || ''}"></p>
                <p><strong>Experience (Years):</strong> <input type="text" readonly value="${row.mentoring_experience || ''}"></p>
                <p><strong>Expertise:</strong> <input type="text" readonly value="${row.area_of_expertise || ''}"></p>
            </div>
            <p style="grid-column: 1 / -1; margin-top: 20px;"><strong>Application Files:</strong> ${resumeLink} | ${certLink}</p>`;

        if (isApplicant) {
            // Action buttons for Pending Applicants
            html += `<div class="action-buttons">
               <button onclick="showCourseAssignmentPopup(${id})"><i class="fas fa-check-circle"></i> Approve & Assign Course</button>
            <button onclick="showRejectionDialog(${id})"><i class="fas fa-times-circle"></i> Reject</button>
                
            </div>`;
        }

        html += '</div>';
        detailView.innerHTML = html;
        detailView.classList.remove('hidden');
        tableContainer.classList.add('hidden');
    }

    function backToTable() {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');
        // Reload current table view
        if (btnApplicants.classList.contains('active')) {
            showTable(applicants, true);
        } else if (btnMentors.classList.contains('active')) {
            showTable(approved, false);
        } else if (btnRejected.classList.contains('active')) {
            showTable(rejected, false);
        }
    }

    // --- Course Assignment (Initial Approval) Functions ---
    
    function showCourseAssignmentPopup(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;
        
        closeUpdateCoursePopup(); // Close update modals
        
        document.getElementById('popupBody').innerHTML = `<div class="loading"><i class="fas fa-sync fa-spin"></i> Loading available courses...</div>`;
        courseAssignmentPopup.style.display = 'block';

        fetch('?action=get_available_courses')
            .then(response => response.json())
            .then(courses => {
                let popupContent = '';
                
                if (courses.length === 0) {
                    popupContent = `
                        <p>No available courses found to assign to <strong>${mentor.first_name} ${mentor.last_name}</strong>. All courses are currently assigned.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()"><i class="fas fa-times"></i> Close</button>
                        </div>
                    `;
                } else {
                    popupContent = `
                        <p>Assign <strong>${mentor.first_name} ${mentor.last_name}</strong> to the following course:</p>
                        <form id="courseAssignmentForm">
                            <div class="form-group">
                                <label for="courseSelect">Available Courses:</label>
                                <select id="courseSelect" name="course_id" required>
                                    <option value="">-- Select a Course --</option>
                    `;
                    
                    courses.forEach(course => {
                        popupContent += `<option value="${course.Course_ID}">${course.Course_Title}</option>`;
                    });
                    
                    popupContent += `
                                </select>
                            </div>
                            <div class="popup-buttons">
                                <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()"><i class="fas fa-times"></i> Cancel</button>
                                <button type="button" class="btn-confirm" onclick="confirmCourseAssignment(${mentorId})"><i class="fas fa-check"></i> Approve & Assign</button>
                            </div>
                        </form>
                    `;
                }
                
                document.getElementById('popupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                document.getElementById('popupBody').innerHTML = `
                    <p>Error loading courses. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()"><i class="fas fa-times"></i> Close</button>
                    </div>
                `;
            });
    }

    function closeCourseAssignmentPopup() {
        courseAssignmentPopup.style.display = 'none';
    }

    function confirmCourseAssignment(mentorId) {
        const form = document.getElementById('courseAssignmentForm');
        const courseId = form.course_id.value;
        
        if (!courseId) {
            alert('Please select a course.');
            return;
        }

        const confirmButton = document.querySelector('#courseAssignmentPopup .btn-confirm');
        confirmButton.disabled = true;
        confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const formData = new FormData();
        formData.append('action', 'approve_with_course');
        formData.append('mentor_id', mentorId);
        formData.append('course_id', courseId);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message + ' Refreshing page...');
                location.reload();
            } else {
                alert('Approval failed: ' + data.message);
                confirmButton.disabled = false;
                confirmButton.innerHTML = '<i class="fas fa-check"></i> Approve & Assign';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during approval. Please try again.');
            confirmButton.disabled = false;
            confirmButton.innerHTML = '<i class="fas fa-check"></i> Approve & Assign';
        });
    }
    
    // --- START: NEW UPDATE/REMOVE/CHANGE COURSE FUNCTIONS ---

    // Show the Update Assigned Course modal
    function showUpdateCoursePopup(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;
        
        // Show loading state
        closeCourseAssignmentPopup(); // Close other modals
        closeUpdateCoursePopup(); // Ensure previous update/change modals are hidden
        
        document.getElementById('updatePopupBody').innerHTML = `<div class="loading"><i class="fas fa-sync fa-spin"></i> Loading course details...</div>`;
        updateCoursePopup.style.display = 'block';

        // Fetch the currently assigned course
        fetch('?action=get_assigned_course&mentor_id=' + mentorId)
            .then(response => response.json())
            .then(course => {
                let popupContent = '';
                
                if (course) {
                    // Mentor is assigned a course
                    popupContent = `
                        <p>Currently assigned course for <strong>${mentor.first_name} ${mentor.last_name}</strong>:</p>
                        <div class="form-group">
                            <label for="currentCourse">Course Title:</label>
                            <input type="text" id="currentCourse" readonly value="${course.Course_Title}" title="Course ID: ${course.Course_ID}"/>
                        </div>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()"><i class="fas fa-times"></i> Close</button>
                            <button type="button" class="btn-confirm change-btn" onclick="showCourseChangePopup(${mentorId}, ${course.Course_ID})"><i class="fas fa-exchange-alt"></i> Change Course</button>
                            <button type="button" class="btn-confirm remove-btn" onclick="confirmRemoveCourse(${mentorId}, ${course.Course_ID}, '${course.Course_Title}')"><i class="fas fa-trash-alt"></i> Remove</button>
                        </div>
                    `;
                } else {
                    // Approved but not assigned
                    popupContent = `
                        <p><strong>${mentor.first_name} ${mentor.last_name}</strong> is currently <strong>Approved</strong> but is <strong>not assigned</strong> to any course.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()"><i class="fas fa-times"></i> Close</button>
                            <button type="button" class="btn-confirm" onclick="showCourseChangePopup(${mentorId}, null)"><i class="fas fa-plus"></i> Assign Course</button>
                        </div>
                    `;
                }
                
                document.getElementById('updatePopupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching assigned course:', error);
                document.getElementById('updatePopupBody').innerHTML = `
                    <p>Error loading assigned course. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()"><i class="fas fa-times"></i> Close</button>
                    </div>
                `;
            });
    }

    // Close the update course popups (handles both update and change modals)
    function closeUpdateCoursePopup() {
        updateCoursePopup.style.display = 'none';
        courseChangePopup.style.display = 'none';
    }
    
    // Show the Change Course/Assign Course popup
    function showCourseChangePopup(mentorId, currentCourseId) {
        closeUpdateCoursePopup(); // Close the first modal
        const mentor = mentorData.find(m => m.user_id == mentorId);
        
        courseChangePopup.style.display = 'block';
        document.getElementById('changePopupBody').innerHTML = `<div class="loading"><i class="fas fa-sync fa-spin"></i> Loading available courses...</div>`;
        
        // Fetch available courses (only those without a mentor)
        fetch('?action=get_available_courses')
            .then(response => response.json())
            .then(courses => {
                let popupContent = '';
                
                if (courses.length === 0) {
                    popupContent = `
                        <p>No available courses found to assign. All courses are currently assigned.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})"><i class="fas fa-arrow-left"></i> Back</button>
                        </div>
                    `;
                } else {
                    const actionText = currentCourseId ? 'NEW' : '';
                    popupContent = `
                        <p>Select a ${actionText} course to assign to <strong>${mentor.first_name} ${mentor.last_name}</strong>:</p>
                        <form id="courseChangeForm">
                            <div class="form-group">
                                <label for="courseChangeSelect">Available Courses:</label>
                                <select id="courseChangeSelect" name="course_id" required>
                                    <option value="">-- Select a Course --</option>
                    `;
                    
                    courses.forEach(course => {
                        popupContent += `<option value="${course.Course_ID}">${course.Course_Title}</option>`;
                    });
                    
                    popupContent += `
                                </select>
                            </div>
                            <div class="popup-buttons">
                                <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})"><i class="fas fa-times"></i> Cancel</button>
                                <button type="button" class="btn-confirm" onclick="confirmCourseChange(${mentorId}, ${currentCourseId})"><i class="fas fa-check"></i> Confirm Assignment</button>
                            </div>
                        </form>
                    `;
                }
                
                document.getElementById('changePopupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                document.getElementById('changePopupBody').innerHTML = `
                    <p>Error loading courses. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})"><i class="fas fa-arrow-left"></i> Back</button>
                    </div>
                `;
            });
    }
    
    // Logic to handle changing or making a new assignment (The Edit/Change logic)
    function confirmCourseChange(mentorId, oldCourseId) {
        const courseSelect = document.getElementById('courseChangeSelect');
        const newCourseId = courseSelect.value;
        
        if (!newCourseId) {
            alert('Please select a course.');
            return;
        }

        const confirmButton = document.querySelector('#courseChangePopup .btn-confirm');
        confirmButton.disabled = true;
        confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        // Step 1: Remove old assignment (if one exists)
        const removePromise = oldCourseId && oldCourseId !== 'null' ? removeAssignment(oldCourseId) : Promise.resolve({success: true});

        removePromise.then(removeData => {
            if (removeData.success) {
                // Step 2: Assign the new course using the existing approval logic
                const formData = new FormData();
                formData.append('action', 'approve_with_course'); // Reuses the logic to assign a mentor to a course
                formData.append('mentor_id', mentorId);
                formData.append('course_id', newCourseId);
                
                return fetch('', {
                    method: 'POST',
                    body: formData
                });
            } else {
                throw new Error('Failed to clear old assignment: ' + removeData.message);
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Course assignment successfully updated! Refreshing page...');
                location.reload();
            } else {
                alert('Error assigning new course: ' + data.message);
                confirmButton.disabled = false;
                confirmButton.innerHTML = '<i class="fas fa-check"></i> Confirm Assignment';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during course change. Please try again.');
            confirmButton.disabled = false;
            confirmButton.innerHTML = '<i class="fas fa-check"></i> Confirm Assignment';
        });
    }

    // Utility function to handle assignment removal 
    function removeAssignment(courseId) {
        const formData = new FormData();
        formData.append('action', 'remove_assigned_course');
        formData.append('course_id', courseId);
        
        return fetch('', {
            method: 'POST',
            body: formData
        }).then(response => response.json());
    }

    // Logic to handle removing the assigned course (clearing the assignment)
    function confirmRemoveCourse(mentorId, courseId, courseTitle) {
        if (confirm(`Are you sure you want to REMOVE ${mentorData.find(m => m.user_id == mentorId).first_name}'s assignment from the course: "${courseTitle}"? \n\nThe course will become available for assignment.`)) {
            
            const removeButton = document.querySelector('#updateCoursePopup .btn-confirm.remove-btn');
            removeButton.disabled = true;
            removeButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removing...';
            
            removeAssignment(courseId)
            .then(data => {
                if (data.success) {
                    alert(data.message + ' Refreshing page...');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    removeButton.disabled = false;
                    removeButton.innerHTML = '<i class="fas fa-trash-alt"></i> Remove';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during removal. Please try again.');
                removeButton.disabled = false;
                removeButton.innerHTML = '<i class="fas fa-trash-alt"></i> Remove';
            });
        }
    }
    // --- END: NEW UPDATE/REMOVE/CHANGE COURSE FUNCTIONS ---

    function showRejectionDialog(mentorId) {
        let reason = prompt("Enter reason for rejection:");
        if (reason !== null && reason.trim() !== "") {
            confirmRejection(mentorId, reason.trim());
        } else if (reason !== null) {
            alert("Rejection reason cannot be empty.");
        }
    }

    function confirmRejection(mentorId, reason) {
        const formData = new FormData();
        formData.append('action', 'reject_mentor');
        formData.append('mentor_id', mentorId);
        formData.append('reason', reason);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message + ' Refreshing page...');
                location.reload();
            } else {
                alert('Rejection failed: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during rejection. Please try again.');
        });
    }

    // Button click handlers
    btnMentors.onclick = () => {
        showTable(approved, false);
    };

    btnApplicants.onclick = () => {
        showTable(applicants, true);
    };

    btnRejected.onclick = () => {
        showTable(rejected, false);
    };

    // Initial view: show applicants by default if there are any, otherwise show mentors
    document.addEventListener('DOMContentLoaded', () => {
        if (applicants.length > 0) {
            showTable(applicants, true);
        } else {
            showTable(approved, false);
        }
    });

    // Close popup when clicking outside of it
    window.onclick = function(event) {
        if (event.target === courseAssignmentPopup) {
            closeCourseAssignmentPopup();
        }
        if (event.target === updateCoursePopup || event.target === courseChangePopup) {
            closeUpdateCoursePopup();
        }
    }

    // Logout confirmation
    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../login.php";
        }
    }

    // Navigation Toggle
    const navBar = document.querySelector("nav");
    const navToggle = document.querySelector(".navToggle");
    if (navToggle) {
        navToggle.addEventListener('click', () => {
            navBar.classList.toggle('close');
        });
    }
</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
</body>
</html>