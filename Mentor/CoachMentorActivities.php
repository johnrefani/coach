<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['applicant_username'])) {
  header("Location: login_mentor.php");
  exit();
}


$applicantUsername = $_SESSION['applicant_username'];
$sql = "SELECT CONCAT(First_Name, ' ', Last_Name) AS Mentor_Name, Mentor_Icon FROM applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $applicantUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_name'] = $row['Mentor_Name'];
} else {
  $_SESSION['mentor_name'] = "Unknown Mentor";
}
$stmt->close();

$mentorName = $_SESSION['mentor_name'];

// Get the courses assigned to this mentor
$assignedCourses = [];
$stmt = $conn->prepare("SELECT Course_Title FROM courses WHERE Assigned_Mentor = ? OR Assigned_Mentor = ?");
$stmt->bind_param("ss", $mentorUsername, $mentorName);
$stmt->execute();
$result = $stmt->get_result();
while ($courseRow = $result->fetch_assoc()) {
    $assignedCourses[] = $courseRow['Course_Title'];
}
$stmt->close();



// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $course_title = $_POST['course_title'] ?? '';
  $difficulty_level = $_POST['difficulty_level'] ?? '';
  $question = $_POST['question'];
  $choice1 = $_POST['choice1'];
  $choice2 = $_POST['choice2'];
  $choice3 = $_POST['choice3'];
  $choice4 = $_POST['choice4'];
  $correct_answer = $_POST['correct_answer'];

  // Status is set to 'Under Review'
  $status = 'Under Review';

  // Insert into mentee_assessment table with Status
  $insert = $conn->prepare("INSERT INTO mentee_assessment (Applicant_Username, CreatedBy, Course_Title, Difficulty_Level, Question, Choice1, Choice2, Choice3, Choice4, Correct_Answer, Status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $insert->bind_param("sssssssssss", $applicantUsername, $mentorName, $course_title, $difficulty_level, $question, $choice1, $choice2, $choice3, $choice4, $correct_answer, $status);

  if ($insert->execute()) {
    $success_message = "Question successfully added!";
  } else {
    $error_message = "Error: " . $insert->error;
  }
  $insert->close();
}

// Fetch courses for dropdown
$courses_result = $conn->query("SELECT Course_Title FROM courses");
$courses = [];
while ($row = $courses_result->fetch_assoc()) {
  $courses[] = $row['Course_Title'];
}

$mentorUsername = $_SESSION['applicant_username']; // fallback or assign directly


// Step 1: Get mentor's full name from applications
$nameQuery = "SELECT First_Name, Last_Name FROM applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($nameQuery);
$stmt->bind_param("s", $mentorUsername);
$stmt->execute();
$nameResult = $stmt->get_result();
$mentorFullName = '';
if ($nameRow = $nameResult->fetch_assoc()) {
  $mentorFullName = $nameRow['First_Name'] . ' ' . $nameRow['Last_Name'];
}
$stmt->close();

// Step 2: Get courses assigned to this mentor
$courseQuery = "SELECT DISTINCT Course_Title FROM courses WHERE Assigned_Mentor = ? OR Assigned_Mentor = ?";
$stmt = $conn->prepare($courseQuery);
$stmt->bind_param("ss", $mentorUsername, $mentorFullName);
$stmt->execute();
$courseResult = $stmt->get_result();

$questions = [];
while ($row = $courseResult->fetch_assoc()) {
  $course = $row['Course_Title'];
  $questions[$course] = ['Under Review' => [], 'Approved' => [], 'Rejected' => []];

  // Step 3: Fetch questions from mentee_assessment for this course
  $qstmt = $conn->prepare("SELECT * FROM mentee_assessment WHERE Course_Title = ?");
  $qstmt->bind_param("s", $course);
  $qstmt->execute();
  $qresult = $qstmt->get_result();

  while ($q = $qresult->fetch_assoc()) {
    $questions[$course][$q['Status']][] = $q;
  }
  $qstmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/mentor_dashboardstyle.css" />
  <link rel="stylesheet" href="css/admin_coursesstyle.css" />
  <link rel="stylesheet" href="css/mentor_actsstyle.css">
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>Mentor Dashboard</title>
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
      <li class="navList">
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
      <li class="navList active">
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
      <img src="img/logo.png" alt="Logo"> </div>

  <div class="container">
    <h2>Create New Assessment Item</h2>

    <?php if (isset($success_message)) echo "<div class='message success'>$success_message</div>"; ?>
    <?php if (isset($error_message)) echo "<div class='message error'>$error_message</div>"; ?>

    <form method="POST">
      <label for="course_title">Course:</label>
      <select id="course_title" name="course_title" required>
        <option value="" disabled selected>Select a course</option>
        <?php foreach ($assignedCourses as $course): ?>
                                    <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                                <?php endforeach; ?>
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
      <textarea name="correct_answer" rows="4" cols="50" required placeholder="Enter the correct answer here"></textarea>

      <button type="submit">Add Question</button>
    </form>
  </div>


<!-- Course Buttons -->
<div class="button-wrapper">
  <?php foreach ($questions as $courseTitle => $statuses): ?>
    <button class="course-btn" onclick="toggleCourse('<?= md5($courseTitle) ?>')">
      <?= htmlspecialchars($courseTitle) ?>
    </button>
  <?php endforeach; ?>
</div>

<!-- Course Question Panels -->
<?php foreach ($questions as $courseTitle => $statuses): ?>
  <div id="course-<?= md5($courseTitle) ?>" class="hidden">
    <h3><?= htmlspecialchars($courseTitle) ?> - Questions</h3>

    <?php foreach (['Under Review', 'Approved', 'Rejected'] as $status): ?>
      <?php if (!empty($statuses[$status])): ?>
        <h4><?= $status ?></h4>
        <?php foreach ($statuses[$status] as $q): ?>
          <div class="question-box">
            <p><strong>Question:</strong> <?= htmlspecialchars($q['Question']) ?></p>
            <ul>
              <li>A. <?= htmlspecialchars($q['Choice1']) ?></li>
              <li>B. <?= htmlspecialchars($q['Choice2']) ?></li>
              <li>C. <?= htmlspecialchars($q['Choice3']) ?></li>
              <li>D. <?= htmlspecialchars($q['Choice4']) ?></li>
            </ul>
            <p><strong>Correct Answer:</strong> <?= htmlspecialchars($q['Correct_Answer']) ?></p>
            <p>Status: <span class="status-label <?= str_replace(' ', '', $q['Status']) ?>"><?= $q['Status'] ?></span></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>



  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script src="admin.js"></script>
  <script>

let currentVisibleCourse = null;

function toggleCourse(courseId) {
  const selected = document.getElementById("course-" + courseId);

  // If the clicked course is currently visible, hide it
  if (currentVisibleCourse === courseId) {
    selected.classList.add("hidden");
    currentVisibleCourse = null;
  } else {
    // Hide all other sections
    const allSections = document.querySelectorAll('[id^="course-"]');
    allSections.forEach(sec => sec.classList.add('hidden'));

    // Show the selected course
    selected.classList.remove("hidden");
    currentVisibleCourse = courseId;
  }
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
