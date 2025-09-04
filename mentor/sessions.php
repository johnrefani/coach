<?php
session_start();

// Database connection
require '../connection/db_connection.php';
// SESSION CHECK: Verify user is logged in and is a Mentor
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentor') {
    header("Location: ../login.php"); // Redirect to a generic login page or mentor login
    exit();
}

$mentor_id = $_SESSION['user_id'];
$mentor_username = $_SESSION['username'];

// Fetch all mentees for the dropdown list
$mentee_list = [];
$mentee_sql = "SELECT user_id, first_name, last_name FROM users WHERE user_type = 'Mentee'";
$mentee_result = $conn->query($mentee_sql);
if ($mentee_result) {
    while ($row = $mentee_result->fetch_assoc()) {
        $mentee_list[] = $row;
    }
}

// Fetch available quizzes based on courses
$quiz_list = [];
$quiz_sql = "SELECT Course_Title FROM courses";
$quiz_result = $conn->query($quiz_sql);
if ($quiz_result) {
    while ($row = $quiz_result->fetch_assoc()) {
        $quiz_list[] = $row['Course_Title'];
    }
}


// Handle quiz assignment form submission
$assignment_message = '';
if (isset($_POST['assign_quiz'])) {
    $menteeUserId = $_POST['mentee_user_id'];
    $courseTitle = $_POST['course_title'];

    // Insert the quiz assignment into the `quizassignments` table
    $sql = "INSERT INTO quizassignments (user_id, Course_Title) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $menteeUserId, $courseTitle);

    if ($stmt->execute()) {
        $assignment_message = "Quiz assigned successfully!";
    } else {
        $assignment_message = "Error assigning quiz: " . $stmt->error;
    }
    $stmt->close();
}
  
// Fetch current Mentor's details from the `users` table
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS Mentor_Name, icon, area_of_expertise FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mentor_id);
$stmt->execute();
$result = $stmt->get_result();
  
if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $_SESSION['mentor_name'] = $row['Mentor_Name'];
    $_SESSION['mentor_expertise'] = $row['area_of_expertise'];
    $_SESSION['mentor_icon'] = (!empty($row['icon'])) ? $row['icon'] : "../uploads/img/default_pfp.png";
} else {
    // Fallback if mentor details are not found
    $_SESSION['mentor_name'] = "Unknown Mentor";
    $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
    $_SESSION['mentor_expertise'] = "";
}
$stmt->close();

// Get the courses assigned to this mentor (uses mentor's full name)
$mentorName = $_SESSION['mentor_name'];
$assignedCourses = [];
$coursesResult = $conn->query("SELECT Course_Title FROM courses WHERE Assigned_Mentor = '$mentorName'");
if ($coursesResult) {
    while ($courseRow = $coursesResult->fetch_assoc()) {
        $assignedCourses[] = $courseRow['Course_Title'];
    }
}

$message = "";

// Handle form submission for new session request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_title'], $_POST['available_date'], $_POST['start_time'], $_POST['end_time'])) {
    $course = $_POST['course_title'];
    $date = $_POST['available_date'];
    $startTime = $_POST['start_time'];
    $endTime = $_POST['end_time'];

    if (!in_array($course, $assignedCourses)) {
        $message = "⚠️ You don't have permission to add sessions for this course.";
    } else {
        $startTime12hr = date("g:i A", strtotime($startTime));
        $endTime12hr = date("g:i A", strtotime($endTime));
        $timeSlot = $startTime12hr . " - " . $endTime12hr;
        $today = date('Y-m-d');

        if ($date < $today) {
            $message = "⚠️ Cannot set sessions for past dates.";
        } else {
            // Check for duplicate pending sessions
            $stmt = $conn->prepare("SELECT * FROM pending_sessions WHERE user_id = ? AND Course_Title = ? AND Session_Date = ? AND Time_Slot = ? AND Status = 'pending'");
            $stmt->bind_param("isss", $mentor_id, $course, $date, $timeSlot);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $message = "⚠️ You already have a pending session request for this time slot.";
            } else {
                // Check for duplicate approved sessions
                $stmt_approved = $conn->prepare("SELECT * FROM sessions WHERE Course_Title = ? AND Session_Date = ? AND Time_Slot = ?");
                $stmt_approved->bind_param("sss", $course, $date, $timeSlot);
                $stmt_approved->execute();
                $result_approved = $stmt_approved->get_result();
                
                if ($result_approved->num_rows > 0) {
                    $message = "⚠️ Session time slot already exists for this date.";
                } else {
                    // Submit for approval
                    $stmt_insert = $conn->prepare("INSERT INTO pending_sessions (user_id, Course_Title, Session_Date, Time_Slot) VALUES (?, ?, ?, ?)");
                    $stmt_insert->bind_param("isss", $mentor_id, $course, $date, $timeSlot);
                    if ($stmt_insert->execute()) {
                        $message = "✅ Session submitted for approval. An administrator will review your request.";
                    } else {
                        $message = "❌ Error submitting session: " . $stmt_insert->error;
                    }
                    $stmt_insert->close();
                }
                $stmt_approved->close();
            }
            $stmt->close();
        }
    }
}

// Handle cancellation of pending session
if (isset($_POST['cancel_pending_id'])) {
    $pendingId = $_POST['cancel_pending_id'];
    
    // Verify this pending session belongs to the logged-in mentor
    $stmt = $conn->prepare("SELECT * FROM pending_sessions WHERE Pending_ID = ? AND user_id = ?");
    $stmt->bind_param("ii", $pendingId, $mentor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt_delete = $conn->prepare("DELETE FROM pending_sessions WHERE Pending_ID = ?");
        $stmt_delete->bind_param("i", $pendingId);
        if ($stmt_delete->execute()) {
            $message = "✅ Pending session request cancelled.";
        } else {
            $message = "❌ Error cancelling session: " . $stmt_delete->error;
        }
        $stmt_delete->close();
    } else {
        $message = "⚠️ You don't have permission to cancel this session request.";
    }
    $stmt->close();
}

// Fetch pending sessions for this mentor using user_id
$pendingSessions = [];
$stmt = $conn->prepare("SELECT * FROM pending_sessions WHERE user_id = ? ORDER BY Session_Date ASC, Time_Slot ASC");
$stmt->bind_param("i", $mentor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pendingSessions[] = $row;
}
$stmt->close();

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
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $approvedSessions[] = $row;
    }
}
$stmt->close();

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
if ($forumsResult) {
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
    $stmt->close();
}

// Get participants for each forum using the new `users` table
$forumParticipants = [];
foreach ($forums as $forum) {
    $stmt = $conn->prepare("
        SELECT 
            u.username, 
            CONCAT(u.first_name, ' ', u.last_name) as display_name,
            u.user_type
        FROM forum_participants fp
        JOIN users u ON fp.user_id = u.user_id
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
    $stmt->close();
}

// Fetch mentee scores with names from the `users` table
$mentee_scores_query = "
    SELECT 
        u.first_name,
        u.last_name,
        s.Course_Title,
        s.Score,
        s.Total_Questions,
        s.Date_Taken
    FROM menteescores s
    JOIN users u ON s.user_id = u.user_id
    ORDER BY s.Date_Taken DESC
";
$mentee_scores_result = mysqli_query($conn, $mentee_scores_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/sessions.css"/>
    <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
    <title>Manage Sessions - COACH</title>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
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
      <li class="navList active">
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
    </ul>

    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout()">
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

    <div class="container">
        <h1 class="section-title">Manage Sessions</h1>
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
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
                    <form method="POST" action="sessions.php">
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
                
                <div class="note">
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
                                <span class="status-badge status-<?= strtolower($session['Status']) ?>"><?= ucfirst($session['Status']) ?></span>
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
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this session request?');" action="sessions.php">
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
                                                <span class="participant-badge <?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $participant['user_type']))); ?>">
                                                    <?php echo htmlspecialchars($participant['user_type']); ?>
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
                                        <span><?php echo $forumMessageCounts[$forum['id']] ?? 0; ?> messages</span>
                                    </div>
                                </div>
                                <a href="forum-chat.php?view=forum&forum_id=<?php echo $forum['id']; ?>" class="card-button">
                                    <ion-icon name="enter-outline"></ion-icon>
                                    Join Forum
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-content" id="assign-tab">
            <div class="assign-activities">
                <h2>Assign Quiz to Mentee</h2>

                <?php if ($assignment_message): ?>
                    <div class="message"><?php echo htmlspecialchars($assignment_message); ?></div>
                <?php endif; ?>

                <form method="POST" action="sessions.php">
                    <label for="mentee">Select Mentee:</label>
                    <select name="mentee_user_id" id="mentee" required>
                        <option value="">-- Choose a Mentee --</option>
                        <?php foreach ($mentee_list as $mentee): ?>
                            <option value="<?php echo htmlspecialchars($mentee['user_id']); ?>">
                                <?php echo htmlspecialchars($mentee['first_name']) . " " . htmlspecialchars($mentee['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="quiz">Select Quiz:</label>
                    <select name="course_title" id="quiz" required>
                        <option value="">-- Choose a Quiz --</option>
                        <?php foreach ($assignedCourses as $course): ?>
                            <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="assign_quiz">Assign Quiz</button>
                </form>
            </div>
        </div>

       <div class="tab-content" id="score-tab">
            <div class="score-activities">
                <h2>Mentee Scores</h2>

                <?php
                $hasData = false;
                if ($mentee_scores_result && mysqli_num_rows($mentee_scores_result) > 0):
                    ob_start(); // Start output buffering to capture table rows
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
                            <?php while ($row = mysqli_fetch_assoc($mentee_scores_result)): ?>
                                <?php if (in_array($row['Course_Title'], $assignedCourses)): ?>
                                    <?php $hasData = true; ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['Course_Title']); ?></td>
                                        <td><?php echo htmlspecialchars($row['Score']); ?></td>
                                        <td><?php echo htmlspecialchars($row['Total_Questions']); ?></td>
                                        <td><?php echo date("F j, Y, g:i a", strtotime($row['Date_Taken'])); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php 
                    $tableOutput = ob_get_clean(); // Get buffer content
                    if ($hasData) {
                        echo $tableOutput; // Display table only if it has relevant data
                    }
                endif; 
                ?>

                <?php if (!$hasData): ?>
                    <div class="no-data">No scores found for your assigned courses.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
    
<script src="admin.js"></script>
<script>
function confirmLogout() {
    if (confirm("Are you sure you want to log out?")) {
        window.location.href = "../logout.php";
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.tab');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            tabContents.forEach(content => content.classList.remove('active'));
            
            const tabId = tab.getAttribute('data-tab') + '-tab';
            document.getElementById(tabId).classList.add('active');
        });
    });
});
</script>
</body>
</html>