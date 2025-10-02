<?php

// *** FIX: Set timezone to Philippine Time (PHT) ***
date_default_timezone_set('Asia/Manila');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$courseTitle = $_GET['course'] ?? '';

$events = [];

if ($courseTitle !== '') {
  $stmt = $conn->prepare("SELECT DISTINCT Session_Date FROM sessions WHERE Course_Title = ?");
  $stmt->bind_param("s", $courseTitle);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($row = $result->fetch_assoc()) {
    $events[] = [
      'title' => 'Available',
      'start' => $row['Session_Date'],
      'display' => 'background' // just highlight dates
    ];
  }
}

echo json_encode($events);
?>
