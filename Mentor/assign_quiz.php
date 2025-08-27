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



// Handle form submission
if (isset($_POST['assign_quiz'])) {
    $menteeUsername = $_POST['mentee_username'];
    $courseTitle = $_POST['course_title'];

    // Insert the quiz assignment into the database
    $sql = "INSERT INTO QuizAssignments (Mentee_Username, Course_Title, Date_Assigned) VALUES (?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $menteeUsername, $courseTitle);

    if ($stmt->execute()) {
        echo "Quiz assigned successfully!";
    } else {
        echo "Error assigning quiz: " . $stmt->error;
    }

    $stmt->close();
}

$conn->close();
?>
