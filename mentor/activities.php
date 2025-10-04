<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require '../connection/db_connection.php';
// SESSION CHECK: Verify user is logged in and is a Mentor
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentor') {
    header("Location: ../login.php");
    exit();
}

// --- MODIFICATION STARTS HERE ---

$mentor_id = $_SESSION['user_id'];
$mentor_username = $_SESSION['username'];

// Fetch current Mentor's details from the `users` table to populate session
$sql = "SELECT CONCAT(first_name, ' ', last_name) AS Mentor_Name, icon FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mentor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_name'] = $row['Mentor_Name'];
  $_SESSION['mentor_icon'] = (!empty($row['icon'])) ? $row['icon'] : "../uploads/img/default_pfp.png";
} else {
  // Fallback if details are not found
  $_SESSION['mentor_name'] = "Unknown Mentor";
  $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
}
$stmt->close();

$mentorName = $_SESSION['mentor_name'];

// Get the courses assigned to this mentor (by name, as per existing schema)
$assignedCourses = [];
$stmt = $conn->prepare("SELECT Course_Title FROM courses WHERE Assigned_Mentor = ?");
$stmt->bind_param("s", $mentorName);
$stmt->execute();
$result = $stmt->get_result();
while ($courseRow = $result->fetch_assoc()) {
    $assignedCourses[] = $courseRow['Course_Title'];
}
$stmt->close();

// Handle form submission for new assessment item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question'])) {
  $course_title = $_POST['course_title'] ?? '';
  $activity_title = $_POST['activity_title'] ?? ''; // ✅ NEW
  $difficulty_level = $_POST['difficulty_level'] ?? '';
  $question = $_POST['question'];
  $choice1 = $_POST['choice1'];
  $choice2 = $_POST['choice2'];
  $choice3 = $_POST['choice3'];
  $choice4 = $_POST['choice4'];
  $correct_answer = $_POST['correct_answer'];
  $status = 'Under Review'; // Default status for new submissions

  // Insert into mentee_assessment table using user_id
  $insert_sql = "INSERT INTO mentee_assessment 
      (user_id, CreatedBy, Course_Title, Activity_Title, Difficulty_Level, Question, Choice1, Choice2, Choice3, Choice4, Correct_Answer, Status) 
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
  $insert_stmt = $conn->prepare($insert_sql);
  $insert_stmt->bind_param(
      "isssssssssss",
      $mentor_id, $mentorName, $course_title, $activity_title, $difficulty_level,
      $question, $choice1, $choice2, $choice3, $choice4, $correct_answer, $status
  );

  if ($insert_stmt->execute()) {
    $success_message = "Question successfully submitted for review!";
  } else {
    $error_message = "Error: " . $insert_stmt->error;
  }
  $insert_stmt->close();
}

// Fetch and display existing questions for the mentor's assigned courses
$questions = [];
foreach ($assignedCourses as $course) {
    $questions[$course] = ['Under Review' => [], 'Approved' => [], 'Rejected' => []];

    // Fetch questions from mentee_assessment for this course
    $qstmt = $conn->prepare("SELECT * FROM mentee_assessment WHERE Course_Title = ?");
    $qstmt->bind_param("s", $course);
    $qstmt->execute();
    $qresult = $qstmt->get_result();

    while ($q = $qresult->fetch_assoc()) {
        if (isset($questions[$course][$q['Status']])) {
            $questions[$course][$q['Status']][] = $q;
        }
    }
    $qstmt->close();
}
// --- MODIFICATION ENDS HERE ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/courses.css" />
  <link rel="stylesheet" href="css/navigation.css"/>
  <link rel="stylesheet" href="css/activities.css">
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>Activities | Mentor</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #f3e5f5;
      color: #4a148c;
      margin: 0;
      padding: 0;
    }
    .container {
      width: 700px;
      max-width: 900%;
      margin: 50px auto;
      background: #ffffff;
      border-radius: 8px;
      padding: 20px 30px;
      box-shadow: 0 4px 12px rgba(244, 198, 247, 0.7);
      margin-bottom: 70px;
    }
    h2 {
       margin-top: 60px;
    margin-bottom: 30px;
    font-size: 35px;
    color: var(--primary-color, #6a0dad);
    text-align: center;
    text-shadow: 1px 1px 2px #caa0ff;
    }
    label {
      font-weight: bold;
      display: block;
      margin-top: 10px;
    }
    input[type="text"], select, textarea {
      width: 100%;
      padding: 10px;
      margin-top: 5px;
      border-radius: 4px;
      border: 1px solid #ccc;
      resize: none; /* Prevent resizing */
    }

    button {
      
     background: linear-gradient(135deg, #4b2354, #8a5a96);
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      margin-top: 20px;
      cursor: pointer;
    }
    button:hover {
      background-color: #4b2354;
    }
    .message {
      margin-top: 15px;
      padding: 10px;
      border-radius: 4px;
    }
    .success {
      background-color: #e1bee7;
      color: #4a148c;
    }
    .error {
      background-color: #f8bbd0;
      color: #b71c1c;
    }
    .cancelLogout{
      background-color: #dfdddeff;
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
          <span class="admin-name"><?php echo htmlspecialchars($_SESSION['mentor_name']); ?></span>
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
        <li class="navList active">
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

    <div class="container">
      <h2>Create New Activity Item</h2>
      <?php if (isset($success_message)) echo "<div class='message success'>$success_message</div>"; ?>
      <?php if (isset($error_message)) echo "<div class='message error'>$error_message</div>"; ?>

      <form method="POST" action="activities.php">
        <label for="course_title">Course:</label>
        <select id="course_title" name="course_title" required>
          <option value="" disabled selected>Select an assigned course</option>
          <?php foreach ($assignedCourses as $course): ?>
            <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
          <?php endforeach; ?>
        </select>

        <!-- ✅ NEW Activity Title Field -->
        <label for="activity_title">Activity Title:</label>
        <select id="activity_title" name="activity_title" required>
          <option value="" disabled selected>Select activity</option>
          <option value="ACTIVITY 1">ACTIVITY 1</option>
          <option value="ACTIVITY 2">ACTIVITY 2</option>
          <option value="ACTIVITY 3">ACTIVITY 3</option>
        </select>


        <label for="difficulty_level">Difficulty Level:</label>
        <select id="difficulty_level" name="difficulty_level" required>
          <option value="" disabled selected>Select difficulty level</option>
          <option value="Beginner">Beginner</option>
          <option value="Intermediate">Intermediate</option>
          <option value="Advanced">Advanced</option>
        </select>

        <label for="question">Question</label>
        <textarea name="question" rows="3" required></textarea>

        <label for="choice1">Choice 1</label>
        <input type="text" name="choice1" required>

        <label for="choice2">Choice 2</label>
        <input type="text" name="choice2" required>

        <label for="choice3">Choice 3</label>
        <input type="text" name="choice3" required>

        <label for="choice4">Choice 4</label>
        <input type="text" name="choice4" required>

        <label for="correct_answer">Correct Answer</label>
        <textarea name="correct_answer" rows="2" required placeholder="Enter the correct answer text here"></textarea>

        <button type="submit">Add Question</button>
      </form>
    </div>

    <div class="button-wrapper">
      <?php foreach ($questions as $courseTitle => $statuses): ?>
        <?php if (!empty(array_merge(...array_values($statuses)))): // Only show button if there are questions ?>
          <button class="course-btn" onclick="toggleCourse('<?= md5($courseTitle) ?>')">
            <?= htmlspecialchars($courseTitle) ?> Questions
          </button>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php foreach ($questions as $courseTitle => $statuses): ?>
      <div id="course-<?= md5($courseTitle) ?>" class="hidden course-panel">
        <h3><?= htmlspecialchars($courseTitle) ?> - Submitted Questions</h3>

        <?php foreach (['Under Review', 'Approved', 'Rejected'] as $status): ?>
          <?php if (!empty($statuses[$status])): ?>
            <h4><?= $status ?></h4>
            <?php foreach ($statuses[$status] as $q): ?>
              <div class="question-box">
                <p><strong>Activity Title:</strong> <?= htmlspecialchars($q['Activity_Title']) ?></p>
                <p><strong>Question:</strong> <?= htmlspecialchars($q['Question']) ?></p>
                <ul>
                  <li>A. <?= htmlspecialchars($q['Choice1']) ?></li>
                  <li>B. <?= htmlspecialchars($q['Choice2']) ?></li>
                  <li>C. <?= htmlspecialchars($q['Choice3']) ?></li>
                  <li>D. <?= htmlspecialchars($q['Choice4']) ?></li>
                </ul>
                <p><strong>Correct Answer:</strong> <?= htmlspecialchars($q['Correct_Answer']) ?></p>
                <?php if ($status === 'Rejected' && !empty($q['Reason'])): ?>
                    <p class="reason-text"><strong>Reason for Rejection:</strong> <?= htmlspecialchars($q['Reason']) ?></p>
                <?php endif; ?>
                <p>Status: <span class="status-label <?= strtolower(str_replace(' ', '-', $q['Status'])) ?>"><?= $q['Status'] ?></span></p>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  </section>

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <script src="admin.js"></script>
  <script src="js/navigation.js"></script>
  <script>
    let currentVisibleCourse = null;

    function toggleCourse(courseId) {
      const selected = document.getElementById("course-" + courseId);
      const allSections = document.querySelectorAll('.course-panel');

      if (currentVisibleCourse === courseId) {
        selected.classList.add("hidden");
        currentVisibleCourse = null;
      } else {
        allSections.forEach(sec => sec.classList.add('hidden'));
        selected.classList.remove("hidden");
        currentVisibleCourse = courseId;
      }
    }

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