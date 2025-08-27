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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentor Dashboard - Assign Quiz</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f3e5f5;
            color: #4a148c;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 700px;
            margin: 50px auto;
            background: #ffffff;
            border-radius: 8px;
            padding: 20px 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        h2 {
            text-align: center;
            color: #6a1b9a;
        }
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
            background-color: #8e24aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #7b1fa2;
        }
        .message {
            text-align: center;
            font-size: 18px;
            margin-top: 20px;
            color: green;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Assign Quiz to Mentee</h2>

        <!-- Show success or error message -->
        <?php if ($assignment_message): ?>
            <div class="message"><?php echo htmlspecialchars($assignment_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="CoachMentorAssessment.php">
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
                <?php foreach ($quiz_list as $quiz): ?>
                    <option value="<?php echo htmlspecialchars($quiz); ?>">
                        <?php echo htmlspecialchars($quiz); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Submit Button -->
            <button type="submit" name="assign_quiz">Assign Quiz</button>
        </form>
    </div>
</body>
</html>
