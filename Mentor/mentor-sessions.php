<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// SESSION CHECK
if (!isset($_SESSION['applicant_username'])) {
    header("Location: login_mentor.php");
    exit();
}

// Fetch all mentees' names (First Name, Last Name)
$sql = "SELECT Username, First_Name, Last_Name FROM mentee_profiles";
$result = $conn->query($sql);

// Fetch available quizzes (you can adjust this query to fit your needs)
$quiz_sql = "SELECT Course_Title FROM courses";  // Assuming quizzes are linked to courses
$quiz_result = $conn->query($quiz_sql);

$mentee_list = [];
$quiz_list = [];

while ($row = $result->fetch_assoc()) {
    $mentee_list[] = $row;
}

while ($row = $quiz_result->fetch_assoc()) {
    $quiz_list[] = $row['Course_Title'];
}

// Check if form is submitted for quiz assignment
$assignment_message = '';
if (isset($_POST['assign_quiz'])) {
    $menteeUsername = $_POST['mentee_username'];
    $courseTitle = $_POST['course_title'];

    // Insert the quiz assignment into the database
    $sql = "INSERT INTO QuizAssignments (Mentee_Username, Course_Title, Date_Assigned) VALUES (?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $menteeUsername, $courseTitle);

    if ($stmt->execute()) {
        $assignment_message = "Quiz assigned successfully!";
    } else {
        $assignment_message = "Error assigning quiz: " . $stmt->error;
    }

    $stmt->close();
}
  
// FETCH Mentor_Name AND Mentor_Icon BASED ON Applicant_Username
$applicantUsername = $_SESSION['applicant_username'];
$sql = "SELECT CONCAT(First_Name, ' ', Last_Name) AS Mentor_Name, Mentor_Icon, AreaofExpertise FROM applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $applicantUsername);
$stmt->execute();
$result = $stmt->get_result();
  
if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $_SESSION['mentor_name'] = $row['Mentor_Name'];
    $_SESSION['mentor_expertise'] = $row['AreaofExpertise'];
  
    // Check if Mentor_Icon exists and is not empty
    if (isset($row['Mentor_Icon']) && !empty($row['Mentor_Icon'])) {
        $_SESSION['mentor_icon'] = $row['Mentor_Icon'];
    } else {
        $_SESSION['mentor_icon'] = "img/default_pfp.png";
    }
} else {
    $_SESSION['mentor_name'] = "Unknown Mentor";
    $_SESSION['mentor_icon'] = "img/default_pfp.png";
    $_SESSION['mentor_expertise'] = "";
}

// Get the courses assigned to this mentor
$mentorName = $_SESSION['mentor_name'];
$assignedCourses = [];
$coursesResult = $conn->query("SELECT Course_Title FROM courses WHERE Assigned_Mentor = '$mentorName'");
if ($coursesResult && $coursesResult->num_rows > 0) {
    while ($courseRow = $coursesResult->fetch_assoc()) {
        $assignedCourses[] = $courseRow['Course_Title'];
    }
}

$message = "";

// Create pending_sessions table if it doesn't exist
$conn->query("
CREATE TABLE IF NOT EXISTS pending_sessions (
    Pending_ID INT AUTO_INCREMENT PRIMARY KEY,
    Mentor_Username VARCHAR(70) NOT NULL,
    Course_Title VARCHAR(250) NOT NULL,
    Session_Date DATE NOT NULL,
    Time_Slot VARCHAR(200) NOT NULL,
    Submission_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    Admin_Notes TEXT NULL
)");

// Handle form submission for new session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_title'], $_POST['available_date'], $_POST['start_time'], $_POST['end_time'])) {
    $course = $_POST['course_title'];
    $date = $_POST['available_date'];
    $startTime = $_POST['start_time'];
    $endTime = $_POST['end_time'];

    // Verify this course is assigned to this mentor
    if (!in_array($course, $assignedCourses)) {
        $message = "⚠️ You don't have permission to add sessions for this course.";
    } else {
        // Convert 24-hour format to 12-hour format with AM/PM
        $startTime12hr = date("g:i A", strtotime($startTime));
        $endTime12hr = date("g:i A", strtotime($endTime));

        $timeSlot = $startTime12hr . " - " . $endTime12hr;
        $today = date('Y-m-d');

        if ($date < $today) {
            $message = "⚠️ Cannot set sessions for past dates.";
        } else {
            // Check for duplicate time slot in pending sessions
            $stmt = $conn->prepare("SELECT * FROM pending_sessions WHERE Mentor_Username = ? AND Course_Title = ? AND Session_Date = ? AND Time_Slot = ? AND Status = 'pending'");
            $stmt->bind_param("ssss", $applicantUsername, $course, $date, $timeSlot);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $message = "⚠️ You already have a pending session request for this time slot.";
            } else {
                // Check for duplicate time slot in approved sessions
                $stmt = $conn->prepare("SELECT * FROM sessions WHERE Course_Title = ? AND Session_Date = ? AND Time_Slot = ?");
                $stmt->bind_param("sss", $course, $date, $timeSlot);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $message = "⚠️ Session time slot already exists for this date.";
                } else {
                    // Submit for approval
                    $stmt = $conn->prepare("INSERT INTO pending_sessions (Mentor_Username, Course_Title, Session_Date, Time_Slot) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $applicantUsername, $course, $date, $timeSlot);
                    if ($stmt->execute()) {
                        $message = "✅ Session submitted for approval. An administrator will review your request.";
                    } else {
                        $message = "❌ Error submitting session: " . $stmt->error;
                    }
                }
            }
        }
    }
}

// Handle cancellation of pending session
if (isset($_POST['cancel_pending_id'])) {
    $pendingId = $_POST['cancel_pending_id'];
    
    // Verify this pending session belongs to this mentor
    $stmt = $conn->prepare("SELECT * FROM pending_sessions WHERE Pending_ID = ? AND Mentor_Username = ?");
    $stmt->bind_param("is", $pendingId, $applicantUsername);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM pending_sessions WHERE Pending_ID = ?");
        $stmt->bind_param("i", $pendingId);
        if ($stmt->execute()) {
            $message = "✅ Pending session request cancelled.";
        } else {
            $message = "❌ Error cancelling session: " . $stmt->error;
        }
    } else {
        $message = "⚠️ You don't have permission to cancel this session request.";
    }
}

// Fetch pending sessions for this mentor
$pendingSessions = [];
$stmt = $conn->prepare("SELECT * FROM pending_sessions WHERE Mentor_Username = ? ORDER BY Session_Date ASC, Time_Slot ASC");
$stmt->bind_param("s", $applicantUsername);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pendingSessions[] = $row;
}

// Fetch approved sessions for this mentor
$approvedSessions = [];
$sql = "SELECT s.* FROM sessions s 
        JOIN courses c ON s.Course_Title = c.Course_Title 
        WHERE c.Assigned_Mentor = ? 
        ORDER BY s.Session_Date ASC, s.Time_Slot ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $mentorName);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $approvedSessions[] = $row;
    }
}

// Get forums for this mentor
$forums = [];
$forumsResult = $conn->query("
SELECT f.*, COUNT(fp.id) as current_users 
FROM forum_chats f 
LEFT JOIN forum_participants fp ON f.id = fp.forum_id 
JOIN courses c ON f.course_title = c.Course_Title 
WHERE c.Assigned_Mentor = '$mentorName'
GROUP BY f.id 
ORDER BY f.session_date ASC, f.time_slot ASC
");
if ($forumsResult && $forumsResult->num_rows > 0) {
    while ($row = $forumsResult->fetch_assoc()) {
        $forums[] = $row;
    }
}

// Get message counts for each forum
$forumMessageCounts = [];
foreach ($forums as $forum) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM chat_messages WHERE chat_type = 'forum' AND forum_id = ?");
    $stmt->bind_param("i", $forum['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $forumMessageCounts[$forum['id']] = $row['count'];
}

// Get participants for each forum
$forumParticipants = [];
foreach ($forums as $forum) {
    $stmt = $conn->prepare("
        SELECT fp.username, 
               CASE 
                 WHEN a.Admin_Username IS NOT NULL THEN a.Admin_Name
                 WHEN ap.Applicant_Username IS NOT NULL THEN CONCAT(ap.First_Name, ' ', ap.Last_Name)
                 ELSE CONCAT(mp.First_Name, ' ', mp.Last_Name)
               END as display_name,
               CASE 
                 WHEN a.Admin_Username IS NOT NULL THEN 1 
                 WHEN ap.Applicant_Username IS NOT NULL THEN 2
                 ELSE 0 
               END as user_type
        FROM forum_participants fp
        LEFT JOIN admins a ON fp.username = a.Admin_Username
        LEFT JOIN applications ap ON fp.username = ap.Applicant_Username
        LEFT JOIN mentee_profiles mp ON fp.username = mp.Username
        WHERE fp.forum_id = ?
    ");
    $stmt->bind_param("i", $forum['id']);
    $stmt->execute();
    $participantsResult = $stmt->get_result();
    $participants = [];
    if ($participantsResult->num_rows > 0) {
        while ($row = $participantsResult->fetch_assoc()) {
            $participants[] = $row;
        }
    }
    $forumParticipants[$forum['id']] = $participants;
}

// Fetch mentee scores with names
$query = "
    SELECT 
        p.First_Name,
        p.Last_Name,
        s.Course_Title,
        s.Score,
        s.Total_Questions,
        s.Date_Taken
    FROM menteescores s
    JOIN mentee_profiles p ON s.Username = p.Username
    ORDER BY s.Date_Taken DESC
";

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/admin_dashboardstyle.css"/>
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Manage Sessions - COACH</title>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        /* Additional Styles */
        .container {
            padding: 20px;
            margin-top: 60px;
        }
        
        .section-title {
            margin-top: -10px;
             margin-bottom: 30px;
            font-size: 45px;
            color: var(--primary-color, #6a0dad);
             text-align: center;
            text-shadow: 1px 1px 2px #caa0ff;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
            color: var(--text-color-light);
        }
        
        .tab.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
             padding: 10px 20px; 
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background-color: var(--dash-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
        
        .card-actions button,
        .card-actions a {
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .card-actions button:hover,
        .card-actions a:hover {
            color: #5a2460;
        }
        
        .card-content {
            margin-bottom: 15px;
        }
        
        .card-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--text-color-light);
            font-size: 0.9rem;
        }
        
        .card-detail ion-icon {
            font-size: 16px;
            color: var(--primary-color);
        }
        
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color);
        }
        
        .card-stats {
            display: flex;
            gap: 15px;
        }
        
        .stat {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-color-light);
            font-size: 0.8rem;
        }
        
        .card-button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }
        
        .card-button:hover {
            background-color: #5a2460;
        }
        
        .create-button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .create-button:hover {
            background-color: #5a2460;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            visibility: hidden;
            opacity: 0;
            transition: visibility 0s linear 0.25s, opacity 0.25s;
        }
        
        .modal-overlay.active {
            visibility: visible;
            opacity: 1;
            transition-delay: 0s;
        }
        
        .modal-content {
            background-color: var(--dash-color);
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            font-size: 1.2rem;
            color: var(--text-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-color-light);
        }
        
        .modal-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .form-group label {
            font-size: 0.9rem;
            color: var(--text-color);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 1rem;
            background-color: var(--dash-color);
            color: var(--text-color);
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        .modal-actions button {
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .modal-actions .cancel-btn {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .modal-actions .submit-btn {
            background-color: var(--primary-color);
            border: none;
            color: white;
        }
        
        .participants-list {
            margin-top: 10px;
        }
        
        .participant {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .participant:last-child {
            border-bottom: none;
        }
        
        .participant-name {
            flex: 1;
            font-size: 0.9rem;
            color: var(--text-color);
        }
        
        .participant-badge {
            background-color: #e6f5f0;
            color: #2e7d32;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
        }
        
        .participant-badge.admin {
            background-color: #f0e6f5;
            color: var(--primary-color);
        }
        
        .participant-badge.mentor {
            background-color: #e6f0f5;
            color: #2962ff;
        }
        
        .alert {
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert.success {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .alert.error {
            background-color: #ffebee;
            color: #c62828;
        }
        
        .alert ion-icon {
            font-size: 20px;
        }
        
        /* Session Scheduler Styles */
        .session-scheduler {
            margin-bottom: 30px;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
            align-items: center;
        }
        
        label {
            font-weight: 600;
            margin-right: 5px;
        }
        
        input[type="date"],
        input[type="time"],
        select {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        
        button {
            background-color: #6a2c70;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        button:hover {
            background-color: #5a2460;
        }
        
        .message {
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 15px 0;
            border-left: 4px solid #6a2c70;
        }
        
        .session-block {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .session-block h4 {
            color: #6a2c70;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .session-block strong {
            display: block;
            margin: 10px 0 5px;
            color: #333;
        }
        
        .session-block ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        
        .session-block li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .slot-input {
            padding: 5px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-right: 10px;
            width: 150px;
        }
        
        .inline-form {
            display: inline;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .admin-notes {
            margin-top: 10px;
            padding: 8px;
            background-color: #f8f9fa;
            border-left: 3px solid #6a2c70;
            font-style: italic;
        }

        
       /* ETO CSS */
        .mentee-list, .quiz-list {
            margin-top: 20px;
        }
        .mentee-list li, .quiz-list li {
            margin-bottom: 10px;
            list-style-type: none;
        }
        select {
            width: 100%;
            padding: 10px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        button {
            background: linear-gradient(to right, #5d2c69, #8a5a96);;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #5d2c69;
        }
        .message {
            text-align: center;
            font-size: 18px;
            margin-top: 20px;
            color: green;
        }

                table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 18px;
            text-align: center;
            border-bottom: 2px solid #e0ccff;
        }

        th {
            background: linear-gradient(to right, #5d2c69, #8a5a96);
            color: white;
            font-size: 18px;
        }

        td {
            font-size: 17px;
            color: #4b0082;
        }

        tr:hover {
            background-color: #f0dfff;
            transition: background-color 0.3s ease;
        }

        .no-data {
            text-align: center;
            color: #888;
            font-size: 18px;
            padding: 20px;
        }

        h1 {
            text-align: center;
            color: #6a0dad;
            margin-top: 30px;
        }
        
        @media (max-width: 768px) {
            .card-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                text-align: center;
                padding: 10px;
            }
            
            .form-row {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
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
      <a href="CoachMentorPFP.php?username=<?= urlencode($_SESSION['applicant_username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

  <div class="menu-items">
    <ul class="navLinks">
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentor.php'">
          <ion-icon name="home-outline"></ion-icon>
          <span class="links">Home</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorCourses.php'">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Course</span>
        </a>
      </li>
      <li class="navList active">
        <a href="#" onclick="window.location='mentor-sessions.php'">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorFeedback.php'">
          <ion-icon name="star-outline"></ion-icon>
          <span class="links">Feedbacks</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorActivities.php'">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorResource.php'">
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
        <img src="img/logo.png" alt="Logo">
    </div>

    <div class="container">
        <h1 class="section-title">Manage Sessions</h1>
        
        <?php if ($message): ?>
            <div class="message"><?= $message ?></div>
        <?php endif; ?>
        
        <div class="tabs">
            <div class="tab active" data-tab="scheduler">Session Scheduler</div>
            <div class="tab" data-tab="pending">Pending Approvals</div>
            <div class="tab" data-tab="approved">Approved Sessions</div>
            <div class="tab" data-tab="forums">Session Forums</div>
            <div class="tab" data-tab="assign">Assign Activities</div>
            <div class="tab" data-tab="score">Activity Scores</div>
        </div>
        
        <div class="tab-content active" id="scheduler-tab">
            <div class="session-scheduler">
                <h2>Request New Session</h2>
                
                <?php if (empty($assignedCourses)): ?>
                    <p>You don't have any assigned courses yet. Please contact an administrator.</p>
                <?php else: ?>
                    <form method="POST">
                        <div class="form-row">
                            <label>Course:</label>
                            <select name="course_title" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach ($assignedCourses as $course): ?>
                                    <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label>Date:</label>
                            <input type="date" name="available_date" required min="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="form-row">
                            <label>Start Time:</label>
                            <input type="time" name="start_time" required>

                            <label>End Time:</label>
                            <input type="time" name="end_time" required>

                            <button type="submit" name="add_session">Submit for Approval</button>
                        </div>
                    </form>
                <?php endif; ?>
                
                <div class="note" style="margin-top: 15px; padding: 10px; background-color: #f8f9fa; border-left: 3px solid #6a2c70;">
                    <p><strong>Note:</strong> All session requests must be approved by an administrator before they become active.</p>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="pending-tab">
            <h2>Pending Session Requests</h2>
            
            <?php if (empty($pendingSessions)): ?>
                <p>You don't have any pending session requests.</p>
            <?php else: ?>
                <div class="card-grid">
                    <?php foreach ($pendingSessions as $session): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><?= htmlspecialchars($session['Course_Title']) ?></h3>
                                <span class="status-badge status-<?= $session['Status'] ?>"><?= ucfirst($session['Status']) ?></span>
                            </div>
                            <div class="card-content">
                                <div class="card-detail">
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <span><?= date('F j, Y', strtotime($session['Session_Date'])) ?></span>
                                </div>
                                <div class="card-detail">
                                    <ion-icon name="time-outline"></ion-icon>
                                    <span><?= htmlspecialchars($session['Time_Slot']) ?></span>
                                </div>
                                <div class="card-detail">
                                    <ion-icon name="hourglass-outline"></ion-icon>
                                    <span>Submitted: <?= date('M j, Y g:i A', strtotime($session['Submission_Date'])) ?></span>
                                </div>
                                
                                <?php if ($session['Status'] === 'rejected' && !empty($session['Admin_Notes'])): ?>
                                    <div class="admin-notes">
                                        <strong>Admin Notes:</strong> <?= htmlspecialchars($session['Admin_Notes']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($session['Status'] === 'pending'): ?>
                                <div class="card-footer">
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this session request?');">
                                        <input type="hidden" name="cancel_pending_id" value="<?= $session['Pending_ID'] ?>">
                                        <button type="submit" class="card-button" style="background-color: #dc3545;">
                                            <ion-icon name="close-outline"></ion-icon>
                                            Cancel Request
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-content" id="approved-tab">
            <h2>Approved Sessions</h2>
            
            <?php if (empty($approvedSessions)): ?>
                <p>You don't have any approved sessions yet.</p>
            <?php else: ?>
                <div class="card-grid">
                    <?php foreach ($approvedSessions as $session): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><?= htmlspecialchars($session['Course_Title']) ?></h3>
                                <span class="status-badge status-approved">Approved</span>
                            </div>
                            <div class="card-content">
                                <div class="card-detail">
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <span><?= date('F j, Y', strtotime($session['Session_Date'])) ?></span>
                                </div>
                                <div class="card-detail">
                                    <ion-icon name="time-outline"></ion-icon>
                                    <span><?= htmlspecialchars($session['Time_Slot']) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="tab-content" id="forums-tab">
            <h2>Session Forums</h2>
            <p class="description">Forums are automatically created when your session requests are approved.</p>
            
            <div class="card-grid">
                <?php if (empty($forums)): ?>
                    <p>No forums available yet.</p>
                <?php else: ?>
                    <?php foreach ($forums as $forum): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><?php echo htmlspecialchars($forum['title']); ?></h3>
                            </div>
                            <div class="card-content">
                                <div class="card-detail">
                                    <ion-icon name="book-outline"></ion-icon>
                                    <span><?php echo htmlspecialchars($forum['course_title']); ?></span>
                                </div>
                                <div class="card-detail">
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <span><?php echo date('F j, Y', strtotime($forum['session_date'])); ?></span>
                                </div>
                                <div class="card-detail">
                                    <ion-icon name="time-outline"></ion-icon>
                                    <span><?php echo htmlspecialchars($forum['time_slot']); ?></span>
                                </div>
                                <div class="card-detail">
                                    <ion-icon name="people-outline"></ion-icon>
                                    <span>Participants: <?php echo $forum['current_users']; ?>/<?php echo $forum['max_users']; ?></span>
                                </div>
                                
                                <?php if (!empty($forumParticipants[$forum['id']])): ?>
                                    <div class="participants-list">
                                        <h4>Participants:</h4>
                                        <?php foreach ($forumParticipants[$forum['id']] as $participant): ?>
                                            <div class="participant">
                                                <span class="participant-name"><?php echo htmlspecialchars($participant['display_name']); ?></span>
                                                <span class="participant-badge <?php echo $participant['user_type'] == 1 ? 'admin' : ($participant['user_type'] == 2 ? 'mentor' : ''); ?>">
                                                    <?php 
                                                    if ($participant['user_type'] == 1) {
                                                        echo 'Admin';
                                                    } elseif ($participant['user_type'] == 2) {
                                                        echo 'Mentor';
                                                    } else {
                                                        echo 'Mentee';
                                                    }
                                                    ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <div class="card-stats">
                                    <div class="stat">
                                        <ion-icon name="chatbubble-outline"></ion-icon>
                                        <span><?php echo isset($forumMessageCounts[$forum['id']]) ? $forumMessageCounts[$forum['id']] : 0; ?> messages</span>
                                    </div>
                                </div>
                                <a href="forum-chat-mentor.php?view=forum&forum_id=<?php echo $forum['id']; ?>" class="card-button">
                                    <ion-icon name="enter-outline"></ion-icon>
                                    Join Forum
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <div class="tab-content" id="assign-tab">
            <div class="assign-activities">
                <h2>Assign Quiz to Mentee</h2>

        <!-- Show success or error message -->
        <?php if ($assignment_message): ?>
            <div class="message"><?php echo htmlspecialchars($assignment_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="mentor-sessions.php">
            <!-- Select Mentee -->
            <label for="mentee">Select Mentee:</label>
            <select name="mentee_username" id="mentee" required>
                <option value="">-- Choose a Mentee --</option>
                <?php foreach ($mentee_list as $mentee): ?>
                    <option value="<?php echo htmlspecialchars($mentee['Username']); ?>">
                        <?php echo htmlspecialchars($mentee['First_Name']) . " " . htmlspecialchars($mentee['Last_Name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Select Quiz -->
            <label for="quiz">Select Quiz:</label>
            <select name="course_title" id="quiz" required>
                <option value="">-- Choose a Quiz --</option>
                <?php foreach ($assignedCourses as $course): ?>
                                    <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                                <?php endforeach; ?>
            </select>

            <!-- Submit Button -->
            <button type="submit" name="assign_quiz">Assign Quiz</button>
        </form>
            </div>
        </div>

       <div class="tab-content" id="score-tab">
    <div class="score-activities">
        <h2>Mentee Scores</h2>

        <?php
        $hasData = false; // Flag to check if any assigned course data is found
        if (mysqli_num_rows($result) > 0):
        ?>
            <table>
                <thead>
                    <tr>
                        <th>Mentee Name</th>
                        <th>Course Title</th>
                        <th>Score</th>
                        <th>Total Questions</th>
                        <th>Date Taken</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <?php if (in_array($row['Course_Title'], $assignedCourses)): ?>
                            <?php $hasData = true; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['First_Name'] . ' ' . $row['Last_Name']); ?></td>
                                <td><?php echo htmlspecialchars($row['Course_Title']); ?></td>
                                <td><?php echo $row['Score']; ?></td>
                                <td><?php echo $row['Total_Questions']; ?></td>
                                <td><?php echo date("F j, Y, g:i a", strtotime($row['Date_Taken'])); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!$hasData): ?>
            <div class="no-data">No scores found for your assigned courses.</div>
        <?php endif; ?>
    </div>
</div>

</section>
    
<script src="admin.js"></script>
<script>
function confirmLogout() {
    var confirmation = confirm("Are you sure you want to log out?");
    if (confirmation) {
        window.location.href = "logout.php";
    }
    return false;
}

// Tab switching
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.tab');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            // Remove active class from all tabs
            tabs.forEach(t => t.classList.remove('active'));
            
            // Add active class to clicked tab
            tab.classList.add('active');
            
            // Hide all tab content
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Show the corresponding tab content
            const tabId = tab.getAttribute('data-tab') + '-tab';
            document.getElementById(tabId).classList.add('active');
        });
    });
});
</script>
</body>
</html>