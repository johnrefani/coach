<?php
session_start(); // Start the session
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Handle Approve/Reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['action']) && isset($_POST['item_id'])) {
    $itemID = $_POST['item_id'];
    $action = $_POST['action'];

    if ($action === 'Approved') {
      $stmt = $conn->prepare("UPDATE mentee_assessment SET Status = ? WHERE Item_ID = ?");
      $stmt->bind_param("si", $action, $itemID);

      if ($stmt->execute()) {
        echo "<script>
                alert('Status updated successfully!');
                window.location='CoachAdminActivities.php';
              </script>";
      } else {
        echo "<script>
                alert('Error updating status: " . $stmt->error . "');
              </script>";
      }
      $stmt->close();
    }
  } elseif (isset($_POST['confirm_reject']) && isset($_POST['item_id']) && isset($_POST['rejection_reason'])) {
    // Handle the rejection confirmation
    $itemID = $_POST['item_id'];
    $reason = $_POST['rejection_reason'];

    // Update the status and save the reason
    $stmt = $conn->prepare("UPDATE mentee_assessment SET Status = 'Rejected', Reason = ? WHERE Item_ID = ?");
    $stmt->bind_param("si", $reason, $itemID);

    if ($stmt->execute()) {
      // Get the applicant username and creator name to send an email
      $query = $conn->prepare("SELECT m.Applicant_Username, m.CreatedBy FROM mentee_assessment m WHERE m.Item_ID = ?");
      $query->bind_param("i", $itemID);
      $query->execute();
      $result = $query->get_result();

      if ($row = $result->fetch_assoc()) {
        $applicantUsername = $row['Applicant_Username'];
        $createdBy = $row['CreatedBy'];

        // Get the email from the Applications table
        $emailQuery = $conn->prepare("SELECT Email FROM Applications WHERE Applicant_Username = ?");
        $emailQuery->bind_param("s", $applicantUsername);
        $emailQuery->execute();
        $emailResult = $emailQuery->get_result();

        if ($emailRow = $emailResult->fetch_assoc()) {
          $email = $emailRow['Email'];

          // Send the email notification
          $subject = "Question Rejection Notification";
          $salutation = "Mr./Ms. " . $createdBy;
          $message = "Dear $salutation,\n\n";
          $message .= "We regret to inform you that your submitted question has been rejected for the following reason:\n\n";
          $message .= $reason . "\n\n";
          $message .= "Please review and make necessary changes for resubmission.\n\n";
          $message .= "Regards,\nCOACH Administration Team";

          $headers = "From: coach@example.com";

          // Attempt to send the email
          // mail($email, $subject, $message, $headers); // Uncomment this line to send email
        }
        $emailQuery->close();
      }
      $query->close();

      echo "<script>
              alert('Question rejected and notification email sent.');
              window.location='CoachAdminActivities.php';
            </script>";
    } else {
      echo "<script>
              alert('Error updating status: " . $stmt->error . "');
            </script>";
    }
    $stmt->close();
  }
}

// Fetch all questions grouped by Course_Title and Status
$courseQuery = "SELECT DISTINCT Course_Title FROM mentee_assessment ORDER BY Course_Title";
$courseResult = $conn->query($courseQuery);

$questions = [];
while ($row = $courseResult->fetch_assoc()) {
  $course = $row['Course_Title'];
  $questions[$course] = ['Under Review' => [], 'Approved' => [], 'Rejected' => []];

  $stmt = $conn->prepare("SELECT * FROM mentee_assessment WHERE Course_Title = ?");
  $stmt->bind_param("s", $course);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($q = $result->fetch_assoc()) {
    // Ensure the status key exists before pushing
    if (isset($questions[$course][$q['Status']])) {
        $questions[$course][$q['Status']][] = $q;
    } else {
        // Handle unexpected status if necessary, or add the status key
        $questions[$course][$q['Status']] = [$q];
    }
  }
  $stmt->close();
}

$conn->close();

if (!isset($_SESSION['admin_username'])) {
  header("Location: login_mentee.php");
  exit();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/admin_dashboardstyle.css"/>
    <link rel="stylesheet" href="css/admin_actsstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Manage Activities</title>
    <style>
        /* Modal styles for rejection reason */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            max-width: 500px;
            border-radius: 5px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            margin: 0;
            color: #333;
            font-size: 1.2em;
        }

        .rejection-textarea {
            width: 100%;
            min-height: 100px;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-confirm {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-cancel {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        }

        /* Status label styles */
        .status-label {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: bold;
        }

        .UnderReview {
            background-color:rgb(220, 189, 233);
            color:rgb(101, 38, 152);
        }

        .Approved {
            background-color:rgb(167, 40, 159);
            color: white;
        }

        .Rejected {
            background-color: #dc3545;
            color: white;
        }


        .filter-container label {
            font-weight: bold;
            color: #5a3e6a; /* Darker purple for the label text */
        }

        .filter-container select {
            padding: 8px;
            border: 1px solid #a070b0; /* Medium purple border */
            border-radius: 4px;
            background-color: #ffffff; /* White background for the select */
            color: #333; /* Default text color */
            cursor: pointer;
            outline: none; /* Remove default outline */
            appearance: none; /* Remove default arrow in some browsers */
            background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%235a3e6a%22%20d%3D%22M287%2C114.7L159.3%2C242.4c-4.5%2C4.5-10.6%2C6.7-16.7%2C6.7s-12.2-2.2-16.7-6.7L5.4%2C114.7c-9-9-9-23.5%2C0-32.5s23.5-9%2C32.5%2C0l121%2C121l121-121c9-9%2C23.5-9%2C32.5%2C0S296%2C105.7%2C287%2C114.7z%22%2F%3E%3C%2Fsvg%3E'); /* Custom arrow */
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 12px;
            padding-right: 30px; /* Make space for the custom arrow */
        }

        .filter-container select:focus {
            border-color: #7d4c8d; /* Slightly darker purple on focus */
            box-shadow: 0 0 5px rgba(160, 112, 176, 0.5); /* Soft purple shadow on focus */
        }

        
        .hidden {
            display: none;
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
      <img src="<?php echo htmlspecialchars($_SESSION['admin_icon']); ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
        </span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="CoachAdminPFP.php?username=<?= urlencode($_SESSION['admin_username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>
  </div>

<div class="menu-items">
        <ul class="navLinks">
            <li class="navList">
                <a href="CoachAdmin.php"> <ion-icon name="home-outline"></ion-icon>
                    <span class="links">Home</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminCourses.php"> <ion-icon name="book-outline"></ion-icon>
                    <span class="links">Courses</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminMentees.php"> <ion-icon name="person-outline"></ion-icon>
                    <span class="links">Mentees</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminMentors.php"> <ion-icon name="people-outline"></ion-icon>
                    <span class="links">Mentors</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminSession.php"> <ion-icon name="calendar-outline"></ion-icon>
                    <span class="links">Sessions</span>
                </a>
            </li>
            <li class="navList"> <a href="CoachAdminFeedback.php"> <ion-icon name="star-outline"></ion-icon>
                    <span class="links">Feedback</span>
                </a>
            </li>
            <li class="navList">
                <a href="admin-sessions.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
                    <span class="links">Channels</span>
                </a>
            </li>
             <li class="navList active">
                <a href="CoachAdminActivities.php"> <ion-icon name="clipboard"></ion-icon>
                    <span class="links">Activities</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminResource.php"> <ion-icon name="library-outline"></ion-icon>
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
      <img src="img/logo.png" alt="Logo">
    </div>

    <h1>Admin Assessment Review</h1>

    <div class="button-wrapper">
        <?php foreach ($questions as $courseTitle => $statuses): ?>
            <button class="course-btn" onclick="toggleCourse('<?= md5($courseTitle) ?>')">
              <?= htmlspecialchars($courseTitle) ?>
            </button>
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
                    <button type="button" onclick="showRejectModal(<?= $q['Item_ID'] ?>)" class="btn-action btn-reject" style="display: inline-block;">Reject</button>
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
        <div class="modal-header">
          <h3 class="modal-title">Provide Reason for Rejection</h3>
        </div>
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

<script src="admin_mentees.js"></script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
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

    // Apply the current filter after showing the course
    filterQuestions();
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

// Functions for the rejection modal
function showRejectModal(itemId) {
  document.getElementById('reject_item_id').value = itemId;
  document.getElementById('rejectModal').style.display = 'block';
}

function hideRejectModal() {
  document.getElementById('rejectModal').style.display = 'none';
}

// Close the modal if clicked outside the modal content
window.onclick = function(event) {
  const modal = document.getElementById('rejectModal');
  if (event.target == modal) {
    hideRejectModal();
  }
}

// New function to filter questions based on status
function filterQuestions() {
    const statusFilter = document.getElementById('statusFilter').value;
    const currentCourseDiv = currentVisibleCourse ? document.getElementById("course-" + currentVisibleCourse) : null;

    if (currentCourseDiv) {
        // Get all question boxes within the currently visible course
        const questionBoxes = currentCourseDiv.querySelectorAll('.question-box');

        questionBoxes.forEach(box => {
            const statusSpan = box.querySelector('.status-label');
            if (statusSpan) {
                const status = statusSpan.innerText.trim();

                if (statusFilter === 'All' || status === statusFilter) {
                    box.style.display = 'block'; // Show the question box
                } else {
                    box.style.display = 'none'; // Hide the question box
                }
            }
        });
    }
}

// Optional: You could call filterQuestions() here if you want a default filter on page load
// document.addEventListener('DOMContentLoaded', () => {
//     filterQuestions();
// });
</script>

</body>
</html>