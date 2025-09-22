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

if (!isset($_SESSION['username'])) {
  header("Location: login_mentee.php");
  exit();
}

$username = $_SESSION['username'];
$courseTitle = $_GET['course_title'] ?? $_POST['course_title'] ?? '';

if (!$courseTitle) {
  die("Course title not specified.");
}

$check = $conn->prepare("SELECT Score, Total_Questions, Date_Taken FROM menteescores WHERE Username = ? AND Course_Title = ?");
$check->bind_param("ss", $username, $courseTitle);
$check->execute();
$checkResult = $check->get_result();
$existing = $checkResult->fetch_assoc();
$check->close();

if ($existing) {
  echo "<div class='container' style='max-width:800px;margin:auto;background:white;padding:30px;border-radius:10px;text-align:center;'>";
  echo "<h2 style='color:#6a1b9a;'>You already submitted this assessment.</h2>";
  echo "<p><strong>Score:</strong> {$existing['Score']} / {$existing['Total_Questions']}</p>";
  echo "<p><strong>Date Taken:</strong> {$existing['Date_Taken']}</p>";
  echo "<a href='CoachMenteeActivities.php' class='btn back-btn' style='text-decoration:none;padding:10px 20px;background:#7b1fa2;color:white;border-radius:5px;'>Back to Activities</a>";
  echo "</div>";
  exit();
}

$sessionKey = $username . '_' . $courseTitle;

if (!isset($_SESSION['assessment'][$sessionKey])) {
  $stmt = $conn->prepare("SELECT * FROM mentee_assessment WHERE Course_Title = ? AND Status = 'approved' ORDER BY RAND() LIMIT 10");
  $stmt->bind_param("s", $courseTitle);
  $stmt->execute();
  $result = $stmt->get_result();
  $_SESSION['assessment'][$sessionKey] = [
    'questions' => $result->fetch_all(MYSQLI_ASSOC),
    'index' => 0,
    'answers' => [],
    'started' => false
  ];
  $stmt->close();
}

$quiz = &$_SESSION['assessment'][$sessionKey];

// Handle "Proceed" button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proceed'])) {
  $quiz['started'] = true;
}


// Handle answer submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer'])) {
  $quiz['answers'][$quiz['index']] = $_POST['answer'];
  $quiz['index']++;
}

if (isset($_GET['question_index'])) {
  $quiz['index'] = (int) $_GET['question_index'];
}

$questions = $quiz['questions'];
$index = $quiz['index'];
$total = count($questions);
$completed = $index >= $total;
?>

<!DOCTYPE html>
<html>
<head>
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>Assessment - <?= htmlspecialchars($courseTitle) ?></title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background: #f3e5f5; padding: 20px; color: #4a148c; }
    .container { max-width: 900px; margin: auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 6px 15px rgba(0,0,0,0.15); }
    .btn { background: #8e24aa; color: white; padding: 12px 25px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
    .btn:hover { background: #6a1b9a; }
    .choice-container { background: #f3e5f5; border: 2px solid #ce93d8; border-radius: 10px; padding: 12px 16px; margin-bottom: 15px; cursor: pointer; }
    .choice-container:hover { background: #e1bee7; border-color: #ab47bc; }
    label { font-size: 18px; }
    .progress-bar-container { background: #e1bee7; border-radius: 20px; height: 20px; margin-bottom: 30px; }
    .progress-bar { background: #8e24aa; height: 100%; border-radius: 20px; transition: width 0.3s ease; }
    .button-container { display: flex; justify-content: space-between; }
    .back-btn { margin-left: 0; text-decoration: none; }
    .next-btn { margin-right: 0; }
    .review-box { border-left: 4px solid #ccc; padding-left: 10px; margin-bottom: 15px; }
  </style>
</head>
<body>
<div class="container">
<?php if (!$quiz['started']): ?>
  <div style="background-color:#ede7f6; border-left:6px solid #7b1fa2; padding:20px; border-radius:10px; margin-bottom:25px;">
    <h2 style="margin-top:0; color:#4a148c;">Welcome to the <strong><?= htmlspecialchars($courseTitle) ?></strong> Assessment!</h2>
    <p style="font-size:17px;">Before you begin, kindly review the following reminders:</p>
    <ul style="font-size:16px; color:#4a148c; line-height:1.8; list-style: none; padding-left: 0;">
      <li style="margin-bottom:8px;"><span style="color:#6a1b9a;">✔</span> Ensure a stable internet connection.</li>
      <li style="margin-bottom:8px;"><span style="color:#6a1b9a;">✔</span> Do not refresh or close the browser during the assessment.</li>
      <li style="margin-bottom:8px;"><span style="color:#6a1b9a;">✔</span> You can only submit once. Choose your answers wisely.</li>
      <li style="margin-bottom:8px;"><span style="color:#6a1b9a;">✔</span> There are <strong><?= $total ?></strong> questions in this quiz.</li>
    </ul>
  </div>
  <form method="POST">
    <input type="hidden" name="course_title" value="<?= htmlspecialchars($courseTitle) ?>">
    <button type="submit" name="proceed" class="btn">Proceed to Assessment</button>
  </form>
<?php elseif ($completed): ?>
  <?php
    $score = 0;
    $output = "";

    foreach ($questions as $i => $q) {
      $correct = trim($q['Correct_Answer']);
      $given = isset($quiz['answers'][$i]) ? trim($quiz['answers'][$i]) : 'No answer';
      $isCorrect = strcasecmp($correct, $given) === 0;
      if ($isCorrect) $score++;

      $output .= "<div class='review-box'>
        <strong>Q" . ($i+1) . ":</strong> " . htmlspecialchars($q['Question']) . "<br>
        Your Answer: <span style='color:" . ($isCorrect ? "green" : "red") . "'>" . htmlspecialchars($given) . "</span><br>
        Correct Answer: <strong>" . htmlspecialchars($correct) . "</strong>
      </div>";
    }

    $stmt = $conn->prepare("INSERT INTO menteescores (Username, Course_Title, Score, Total_Questions, Date_Taken) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("ssii", $username, $courseTitle, $score, $total);
    $stmt->execute();
    $stmt->close();

    $insertAnswer = $conn->prepare("INSERT INTO mentee_answers (Username, Course_Title, Question, Selected_Answer, Correct_Answer, Is_Correct) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($questions as $i => $q) {
      $questionText = $q['Question'];
      $correct = trim($q['Correct_Answer']);
      $given = isset($quiz['answers'][$i]) ? trim($quiz['answers'][$i]) : 'No answer';
      $isCorrect = strcasecmp($correct, $given) === 0 ? 1 : 0;

      $insertAnswer->bind_param("sssssi", $username, $courseTitle, $questionText, $given, $correct, $isCorrect);
      $insertAnswer->execute();
    }
    $insertAnswer->close();

    unset($_SESSION['assessment'][$sessionKey]);
  ?>
  <h1>Assessment Complete</h1>
  <h1>You scored <strong><?= $score ?></strong> out of <strong><?= $total ?></strong>.</h1>
  <h3>Review:</h3>
  <?= $output ?>
  <div class="button-container">
    <a href="CoachMenteeActivities.php" class="btn back-btn">Back to Dashboard</a>
  </div>
<?php else: ?>
  <?php $progressPercent = round(($index / $total) * 100); ?>
  <h2><?= htmlspecialchars($courseTitle) ?> - Question <?= $index + 1 ?> of <?= $total ?></h2>
  <div class="progress-bar-container">
    <div class="progress-bar" style="width: <?= $progressPercent ?>%;"></div>
  </div>
  <form method="POST" action="CoachMenteeAssessment.php">
    <input type="hidden" name="course_title" value="<?= htmlspecialchars($courseTitle) ?>">
    <h2><strong><?= htmlspecialchars($questions[$index]['Question']) ?></strong></h2>
    <?php
      $savedAnswer = $quiz['answers'][$index] ?? null;
      foreach (['Choice1', 'Choice2', 'Choice3', 'Choice4'] as $choice):
        $choiceText = htmlspecialchars($questions[$index][$choice]);
        $isChecked = ($savedAnswer === $questions[$index][$choice]) ? "checked" : "";
    ?>
      <div class="choice-container">
        <label>
          <input type="radio" name="answer" value="<?= $choiceText ?>" <?= $isChecked ?> required>
          <?= $choiceText ?>
        </label>
      </div>
    <?php endforeach; ?>
    <div class="button-container">
      <?php if ($index > 0): ?>
        <a href="CoachMenteeAssessment.php?course_title=<?= htmlspecialchars($courseTitle) ?>&question_index=<?= $index - 1 ?>" class="btn back-btn">Back</a>
      <?php endif; ?>
      <button type="submit" class="btn next-btn"><?= ($index + 1 == $total) ? 'Finish' : 'Next' ?></button>
    </div>
  </form>
<?php endif; ?>
</div>
</body>
</html>
