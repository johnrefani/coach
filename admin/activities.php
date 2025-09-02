<?php
session_start();

// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

$currentUsername = $_SESSION['username'];
$stmtUser = $conn->prepare("SELECT user_id, first_name, last_name, icon, user_type FROM users WHERE username = ?");
$stmtUser->bind_param("s", $currentUsername);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();

if ($resultUser->num_rows > 0) {
    $user = $resultUser->fetch_assoc();
    if (!in_array($user['user_type'], ['Admin', 'Super Admin'])) {
        header("Location: ../login.php");
        exit();
    }
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['admin_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['admin_icon'] = $user['icon'] ?: '../uploads/img/default-admin.png';
} else {
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$stmtUser->close();


// Handle Approve/Reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action']) && isset($_POST['item_id']) && $_POST['action'] === 'Approved') {
    $itemID = $_POST['item_id'];
    $stmt = $conn->prepare("UPDATE mentee_assessment SET Status = 'Approved' WHERE Item_ID = ?");
    $stmt->bind_param("i", $itemID);
    if ($stmt->execute()) {
        echo "<script>alert('Status updated successfully!'); window.location.href=window.location.href;</script>";
    } else {
        echo "<script>alert('Error updating status: " . $stmt->error . "');</script>";
    }
    $stmt->close();
    exit();

  } elseif (isset($_POST['confirm_reject'], $_POST['item_id'], $_POST['rejection_reason'])) {
    $itemID = $_POST['item_id'];
    $reason = $_POST['rejection_reason'];

    $stmt = $conn->prepare("UPDATE mentee_assessment SET Status = 'Rejected', Reason = ? WHERE Item_ID = ?");
    $stmt->bind_param("si", $reason, $itemID);

    if ($stmt->execute()) {
      // Fetch user details for email notification
      $query = $conn->prepare("SELECT u.email, u.first_name, u.last_name FROM mentee_assessment ma JOIN users u ON ma.user_id = u.user_id WHERE ma.Item_ID = ?");
      $query->bind_param("i", $itemID);
      $query->execute();
      $result = $query->get_result();

      if ($row = $result->fetch_assoc()) {
          $email = $row['email'];
          $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
          
          // You can implement your email sending logic here
          // For example:
          // $subject = "Question Rejection Notification";
          // $message = "Dear " . $fullName . ",\n\nYour submitted question has been rejected.\nReason: " . $reason;
          // mail($email, $subject, $message);
      }
      $query->close();
      
      echo "<script>alert('Question rejected successfully.'); window.location.href=window.location.href;</script>";
    } else {
      echo "<script>alert('Error updating status: " . $stmt->error . "');</script>";
    }
    $stmt->close();
    exit();
  }
}

// Fetch all questions
$courseQuery = "SELECT DISTINCT Course_Title FROM mentee_assessment ORDER BY Course_Title";
$courseResult = $conn->query($courseQuery);

$questions = [];
if ($courseResult) {
    while ($row = $courseResult->fetch_assoc()) {
      $course = $row['Course_Title'];
      $questions[$course] = ['Under Review' => [], 'Approved' => [], 'Rejected' => []];

      $stmt = $conn->prepare("SELECT ma.*, u.username as creator_username FROM mentee_assessment ma LEFT JOIN users u ON ma.user_id = u.user_id WHERE ma.Course_Title = ?");
      $stmt->bind_param("s", $course);
      $stmt->execute();
      $result = $stmt->get_result();

      while ($q = $result->fetch_assoc()) {
        if (isset($questions[$course][$q['Status']])) {
            $questions[$course][$q['Status']][] = $q;
        }
      }
      $stmt->close();
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/activities.css">
    <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
    <title>Manage Activities</title>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 50%; max-width: 500px; border-radius: 5px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title { margin: 0; }
        .rejection-textarea { width: 100%; min-height: 100px; padding: 10px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .btn-confirm { background-color: #dc3545; color: white; }
        .btn-cancel { background-color: #6c757d; color: white; }
        .status-label { padding: 3px 8px; border-radius: 4px; font-size: 0.9em; font-weight: bold; color: white; }
        .UnderReview { background-color: #ffc107; color: #212529;}
        .Approved { background-color: #28a745; }
        .Rejected { background-color: #dc3545; }
        .hidden { display: none; }
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
      <img src="<?php echo htmlspecialchars($_SESSION['admin_icon']); ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name"><?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>
  <div class="menu-items">
    <ul class="navLinks">
        <li><a href="dashboard.php"><ion-icon name="home-outline"></ion-icon><span class="links">Home</span></a></li>
        <li><a href="courses.php"><ion-icon name="book-outline"></ion-icon><span class="links">Courses</span></a></li>
        <li><a href="manage_mentees.php"><ion-icon name="person-outline"></ion-icon><span class="links">Mentees</span></a></li>
        <li><a href="manage_mentors.php"><ion-icon name="people-outline"></ion-icon><span class="links">Mentors</span></a></li>
        <li><a href="manage_session.php"><ion-icon name="calendar-outline"></ion-icon><span class="links">Sessions</span></a></li>
        <li><a href="feedbacks.php"><ion-icon name="star-outline"></ion-icon><span class="links">Feedback</span></a></li>
        <li><a href="channels.php"><ion-icon name="chatbubbles-outline"></ion-icon><span class="links">Channels</span></a></li>
        <li class="active"><a href="activities.php"><ion-icon name="clipboard-outline"></ion-icon><span class="links">Activities</span></a></li>
        <li><a href="resource.php"><ion-icon name="library-outline"></ion-icon><span class="links">Resource Library</span></a></li>
    </ul>
    <ul class="bottom-link">
        <li class="logout-link"><a href="#" onclick="confirmLogout()"><ion-icon name="log-out-outline"></ion-icon><span>Logout</span></a></li>
    </ul>
  </div>
</nav>

<section class="dashboard">
    <div class="top">
      <ion-icon class="navToggle" name="menu-outline"></ion-icon>
      <img src="../uploads/img/logo.png" alt="Logo">
    </div>

    <h1>Admin Assessment Review</h1>

    <div class="button-wrapper">
        <?php foreach (array_keys($questions) as $courseTitle): ?>
            <button class="course-btn" onclick="toggleCourse('<?= md5($courseTitle) ?>')"><?= htmlspecialchars($courseTitle) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="filter-container" style="margin-bottom: 20px;">
        <label for="statusFilter">Filter by Status:</label>
        <select id="statusFilter" onchange="filterQuestions()">
            <option value="All">All</option>
            <option value="Under Review">Under Review</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
        </select>
    </div>

    <?php foreach ($questions as $courseTitle => $statuses): ?>
      <div id="course-<?= md5($courseTitle) ?>" class="hidden">
        <h3><?= htmlspecialchars($courseTitle) ?> - Questions</h3>
        <?php foreach (['Under Review', 'Approved', 'Rejected'] as $status): ?>
          <?php if (!empty($statuses[$status])): ?>
            <?php foreach ($statuses[$status] as $q): ?>
              <div class="question-box">
                <p><strong>Created By:</strong> <?= htmlspecialchars($q['CreatedBy']) ?> (<em><?= htmlspecialchars($q['creator_username'] ?? 'N/A') ?></em>)</p>
                <p><strong>Question:</strong> <?= htmlspecialchars($q['Question']) ?></p>
                <ul>
                  <li>A. <?= htmlspecialchars($q['Choice1']) ?></li>
                  <li>B. <?= htmlspecialchars($q['Choice2']) ?></li>
                  <li>C. <?= htmlspecialchars($q['Choice3']) ?></li>
                  <li>D. <?= htmlspecialchars($q['Choice4']) ?></li>
                </ul>
                <p><strong>Correct Answer:</strong> <?= htmlspecialchars($q['Correct_Answer']) ?></p>
                <p>Status: <span class="status-label <?= str_replace(' ', '', $q['Status']) ?>"><?= $q['Status'] ?></span></p>

                <?php if ($q['Status'] === 'Rejected' && !empty($q['Reason'])): ?>
                  <p><strong>Rejection Reason:</strong> <?= htmlspecialchars($q['Reason']) ?></p>
                <?php endif; ?>

                <?php if ($q['Status'] === 'Under Review'): ?>
                  <div style="margin-top: 10px;">
                    <form method="post" style="display: inline-block;">
                      <input type="hidden" name="item_id" value="<?= $q['Item_ID'] ?>">
                      <button type="submit" name="action" value="Approved" class="btn-action btn-approve">Approve</button>
                    </form>
                    <button type="button" onclick="showRejectModal(<?= $q['Item_ID'] ?>)" class="btn-action btn-reject">Reject</button>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div id="rejectModal" class="modal">
      <div class="modal-content">
        <div class="modal-header"><h3 class="modal-title">Provide Reason for Rejection</h3></div>
        <form method="post">
          <input type="hidden" id="reject_item_id" name="item_id">
          <textarea class="rejection-textarea" name="rejection_reason" placeholder="Please provide a reason for rejection..." required></textarea>
          <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="hideRejectModal()">Cancel</button>
            <button type="submit" name="confirm_reject" class="btn-confirm">Confirm Rejection</button>
          </div>
        </form>
      </div>
    </div>
</section>

<script>
  let currentVisibleCourse = null;

  function toggleCourse(courseId) {
    const selected = document.getElementById("course-" + courseId);
    if (currentVisibleCourse === courseId) {
      selected.classList.add("hidden");
      currentVisibleCourse = null;
    } else {
      document.querySelectorAll('[id^="course-"]').forEach(sec => sec.classList.add('hidden'));
      selected.classList.remove("hidden");
      currentVisibleCourse = courseId;
      filterQuestions();
    }
  }

  function confirmLogout() {
    if (confirm("Are you sure you want to log out?")) {
      window.location.href = "../logout.php";
    }
  }

  function showRejectModal(itemId) {
    document.getElementById('reject_item_id').value = itemId;
    document.getElementById('rejectModal').style.display = 'block';
  }

  function hideRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
  }

  window.onclick = function(event) {
    if (event.target == document.getElementById('rejectModal')) {
      hideRejectModal();
    }
  }

  function filterQuestions() {
    const statusFilter = document.getElementById('statusFilter').value;
    if (currentVisibleCourse) {
        const currentCourseDiv = document.getElementById("course-" + currentVisibleCourse);
        currentCourseDiv.querySelectorAll('.question-box').forEach(box => {
            const status = box.querySelector('.status-label').innerText.trim();
            box.style.display = (statusFilter === 'All' || status === statusFilter) ? 'block' : 'none';
        });
    }
  }
</script>

</body>
</html>
<?php $conn->close(); ?>
