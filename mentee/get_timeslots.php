<?php
$course = $_GET['course'] ?? '';
$date = $_GET['date'] ?? '';

if ($course === '' || $date === '') {
  echo json_encode([]);
  exit;
}

$conn = new mysqli("localhost", "root", "", "coach");
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$stmt = $conn->prepare("SELECT Time_Slot FROM sessions WHERE Course_Title = ? AND Session_Date = ?");
$stmt->bind_param("ss", $course, $date);
$stmt->execute();
$result = $stmt->get_result();

$slots = [];
while ($row = $result->fetch_assoc()) {
  $slots[] = $row['Time_Slot'];
}

echo json_encode($slots);
?>
