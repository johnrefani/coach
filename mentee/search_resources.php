<?php
header("Content-Type: text/html; charset=UTF-8");

$servername = "localhost";
$username = "root";
$password = ""; // update if needed
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$query = isset($_POST['query']) ? trim($_POST['query']) : "";

// Query to fetch resources
if ($query === "") {
  $stmt = $conn->prepare("SELECT * FROM resources");
} else {
  $stmt = $conn->prepare("SELECT * FROM resources WHERE Resource_Title LIKE CONCAT('%', ?, '%') OR Resource_Type LIKE CONCAT('%', ?, '%')");
  $stmt->bind_param("ss", $query, $query);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  while ($resource = $result->fetch_assoc()) {
    echo '<div class="course-card">';
    if (!empty($resource['Resource_Icon'])) {
      echo '<img src="uploads/' . htmlspecialchars($resource['Resource_Icon']) . '" alt="Resource Icon">';
    }
    echo '<h2>' . htmlspecialchars($resource['Resource_Title']) . '</h2>';
    echo '<p><strong>' . htmlspecialchars($resource['Resource_Type']) . '</strong></p>';
    echo '<button class="choose-btn">View</button>';
    echo '</div>';
  }
} else {
  echo '<p>No results found.</p>';
}

$stmt->close();
$conn->close();
?>
