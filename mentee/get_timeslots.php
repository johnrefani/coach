<?php

// *** FIX: Set timezone to Philippine Time (PHT) ***
date_default_timezone_set('Asia/Manila');

$course = $_GET['course'] ?? '';
$date = $_GET['date'] ?? '';

if ($course === '' || $date === '') {
  echo json_encode([]);
  exit;
}

require "../connection/db_connection.php";


if ($conn) {
    // This tells MySQL to interpret/return timestamps using the PHT offset
    $conn->query("SET time_zone = 'Asia/Manila'");
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
