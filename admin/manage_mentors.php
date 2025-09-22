<?php
session_start(); // Start the session
// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// Load PHPMailer
require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$admin_icon = !empty($_SESSION['user_icon']) ? $_SESSION['user_icon'] : '../uploads/img/default_pfp.png';

// Handle AJAX requests for fetching available courses
if (isset($_GET['action']) && $_GET['action'] === 'get_available_courses') {
    header('Content-Type: application/json');
    
    // Fetch courses that don't have any mentors assigned yet
    $sql = "SELECT Course_ID, Course_Title 
        FROM courses 
        WHERE Assigned_Mentor IS NULL 
           OR Assigned_Mentor = ''";

    $result = $conn->query($sql);
    $courses = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
    }
    
    echo json_encode($courses);
    exit();
}

// Handle mentor approval with course assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_with_course') {
    $mentor_id = $_POST['mentor_id'];
    $course_id = $_POST['course_id'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Update mentor status to Approved
        $update_mentor = "UPDATE users SET status = 'Approved' WHERE user_id = ? AND user_type = 'Mentor'";
        $stmt1 = $conn->prepare($update_mentor);
        $stmt1->bind_param("i", $mentor_id);
        $stmt1->execute();
        
        // Step 1: Get mentor's full name from users table
        $get_mentor_name = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor_name);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_name);
        $stmt->fetch();
        $stmt->close();

        // Step 2: Save the full name into Assigned_Mentor
        $update_course = "UPDATE courses SET Assigned_Mentor = ? WHERE Course_ID = ?";
        $stmt2 = $conn->prepare($update_course);
        $stmt2->bind_param("si", $mentor_name, $course_id);
        $stmt2->execute();
        $stmt2->close();
        
        // Get mentor and course details for email
        $get_mentor = "SELECT first_name, last_name, email FROM users WHERE user_id = ?";
        $stmt3 = $conn->prepare($get_mentor);
        $stmt3->bind_param("i", $mentor_id);
        $stmt3->execute();
        $mentor_result = $stmt3->get_result();
        $mentor_data = $mentor_result->fetch_assoc();
        
        $get_course = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
        $stmt4 = $conn->prepare($get_course);
        $stmt4->bind_param("i", $course_id);
        $stmt4->execute();
        $course_result = $stmt4->get_result();
        $course_data = $course_result->fetch_assoc();
        
        // Commit transaction
        $conn->commit();
        
        // -------- Send Email via PHPMailer --------
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'coach.hub2025@gmail.com';       // 🔹 replace with your Gmail
            $mail->Password   = 'ehke bope zjkj pwds';   // <-- your App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients
            $mail->setFrom('yourgmail@gmail.com', 'COACH Team');
            $mail->addAddress($mentor_data['email'], $mentor_data['first_name'] . " " . $mentor_data['last_name']);

            // Content
$mail->isHTML(true);
$mail->Subject = "Application Approved - Course Assignment";
$mail->Body    = "
<html>
<head>
  <style>
    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: rgb(241, 223, 252); }
    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
    .content { padding: 20px; background-color: #f9f9f9; }
    .course-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
  </style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <h2>Mentor Application Approved</h2>
    </div>
    <div class='content'>
      <p>Dear <b>" . htmlspecialchars($mentor_data['first_name']) . " " . htmlspecialchars($mentor_data['last_name']) . "</b>,</p>
      <p>Congratulations! Your mentor application has been <b>approved</b>. 🎉</p>
      
      <p>You have been assigned to the following course:</p>
      <div class='course-box'>
        <p><strong>Course Title:</strong> " . htmlspecialchars($course_data['Course_Title']) . "</p>
      </div>

      <p>Please log in to your account at <a href='https://coach-hub.online/login.php'>COACH</a> to access your assigned course and start mentoring.</p>
      <p>We’re excited to have you on board. Best of luck in guiding your mentees!</p>
    </div>
    <div class='footer'>
      <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
    </div>
  </div>
</body>
</html>
";
            $mail->send();

            echo json_encode(['success' => true, 'message' => 'Mentor approved, course assigned, and email sent!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Mentor approved and course assigned, but email could not be sent. Error: ' . $mail->ErrorInfo]);
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/mentors.css">
    <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
    <title>Manage Mentors</title>
    <style>
        /* Popup Styles */
        .course-assignment-popup {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .popup-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: none;
            border-radius: 10px;
            width: 400px;
            max-width: 90%;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .popup-content h3 {
            margin-top: 0;
            color: #333;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            background-color: white;
        }
        
        .popup-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }
        
        .popup-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        
        .btn-cancel {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background-color: #5a6268;
        }
        
        .btn-confirm {
            background-color: #28a745;
            color: white;
        }
        
        .btn-confirm:hover {
            background-color: #218838;
        }
        
        .btn-confirm:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
        
        .loading {
            text-align: center;
            color: #666;
            padding: 20px;
        }
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
      <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="Admin Icon">
      <div class="admin-text">
        <span class="admin-name"><?php echo htmlspecialchars($_SESSION['user_full_name']); ?></span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline"></ion-icon>
      </a>
    </div>
  </div>

    <div class="menu-items">
      <ul class="navLinks">
        <li class="navList">
          <a href="dashboard.php">
            <ion-icon name="home-outline"></ion-icon>
            <span class="links">Home</span>
          </a>
        </li>
        <li class="navList">
          <a href="moderators.php">
            <ion-icon name="lock-closed-outline"></ion-icon>
            <span class="links">Moderators</span>
          </a>
        </li>
        <li class="navList">
            <a href="manage_mentees.php"> <ion-icon name="person-outline"></ion-icon>
              <span class="links">Mentees</span>
            </a>
        </li>
        <li class="navList active">
            <a href="manage_mentors.php"> <ion-icon name="people-outline"></ion-icon>
              <span class="links">Mentors</span>
            </a>
        </li>
        <li class="navList">
            <a href="courses.php"> <ion-icon name="book-outline"></ion-icon>
                <span class="links">Courses</span>
            </a>
        </li>
        <li class="navList">
            <a href="manage_session.php"> <ion-icon name="calendar-outline"></ion-icon>
              <span class="links">Sessions</span>
            </a>
        </li>
        <li class="navList"> 
            <a href="feedbacks.php"> <ion-icon name="star-outline"></ion-icon>
              <span class="links">Feedback</span>
            </a>
        </li>
        <li class="navList">
            <a href="channels.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
              <span class="links">Channels</span>
            </a>
        </li>
        <li class="navList">
           <a href="activities.php"> <ion-icon name="clipboard"></ion-icon>
              <span class="links">Activities</span>
            </a>
        </li>
        <li class="navList">
            <a href="resource.php"> <ion-icon name="library-outline"></ion-icon>
              <span class="links">Resource Library</span>
            </a>
        </li>
        <li class="navList">
            <a href="reports.php"><ion-icon name="folder-outline"></ion-icon>
              <span class="links">Reported Posts</span>
            </a>
        </li>
        <li class="navList">
            <a href="banned-users.php"><ion-icon name="person-remove-outline"></ion-icon>
              <span class="links">Banned Users</span>
            </a>
        </li>
      </ul>

   <ul class="bottom-link">
  <li class="navList logout-link">
    <a href="#" onclick="confirmLogout()">
      <ion-icon name="log-out-outline"></ion-icon>
      <span class="links">Logout</span>
    </a>
  </li>
</ul>
    </div>
  </nav>

  <section class="dashboard">
    <div class="top">
      <ion-icon class="navToggle" name="menu-outline"></ion-icon>
      <img src="../uploads/img/logo.png" alt="Logo"> </div>

      <h1>Manage Mentors</h1>
    <div class="dashboard">
        <div class="top-bar">
            <button id="btnMentors">
                <ion-icon name="people-outline"></ion-icon>
                <span>Coach Mentors</span> <span id="approvedCount">0</span>
            </button>
            <button id="btnApplicants">
                <ion-icon name="person-add-outline"></ion-icon>
                <span>Applicants</span> <span id="applicantCount">0</span>
            </button>
            <button id="btnRejected">
                <ion-icon name="close-circle-outline"></ion-icon>
                <span>Rejected</span> <span id="rejectedCount">0</span>
            </button>
        </div>

        <div class="search-box">
            <input type="text" id="searchInput" placeholder="Search by ID or Name...">
            <button onclick="searchMentors()" class="search-btn"><ion-icon name="search-outline"></ion-icon></button>
        </div>

        <div id="tableContainer"></div>
        <div id="detailView" class="hidden"></div>
    </div>
</section>

<!-- Course Assignment Popup -->
<div id="courseAssignmentPopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Assign Course to Mentor</h3>
        <div id="popupBody">
            <div class="loading">Loading available courses...</div>
        </div>
    </div>
</div>

<script>
    // --- Data fetched from PHP and inlined JS logic ---

    // Fetch mentor data from the new 'users' table
    const mentorData = <?php
        // The SQL query is updated to select users with the 'Mentor' type
        $sql = "SELECT * FROM users WHERE user_type = 'Mentor'";
        $result = $conn->query($sql);
        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode($data);
        $conn->close();
    ?>;

    // --- Start of inlined and updated admin_mentors.js ---
    
    // Element selections
    const btnMentors = document.getElementById('btnMentors');
    const btnApplicants = document.getElementById('btnApplicants');
    const btnRejected = document.getElementById("btnRejected");
    const tableContainer = document.getElementById('tableContainer');
    const detailView = document.getElementById('detailView');
    const approvedCount = document.getElementById('approvedCount');
    const applicantCount = document.getElementById('applicantCount');
    const rejectedCount = document.getElementById("rejectedCount");
    const searchInput = document.getElementById('searchInput');
    const courseAssignmentPopup = document.getElementById('courseAssignmentPopup');

    // Filter data based on status (using new snake_case 'status' column)
    let approved = mentorData.filter(m => m.status === "Approved");
    let applicants = mentorData.filter(m => m.status === "Under Review");
    let rejected = mentorData.filter(m => m.status === "Rejected");

    // Update counts
    approvedCount.textContent = approved.length;
    applicantCount.textContent = applicants.length;
    rejectedCount.textContent = rejected.length;

    let currentTable = null;

    // Function to search mentors by ID or name
    function searchMentors() {
        const input = searchInput.value.toLowerCase();
        const rows = document.querySelectorAll('table tbody tr.data-row');

        rows.forEach(row => {
            const id = row.querySelector('td:first-child').innerText.toLowerCase();
            const firstName = row.querySelector('.first-name')?.innerText.toLowerCase() || '';
            const lastName = row.querySelector('.last-name')?.innerText.toLowerCase() || '';

            if (id.includes(input) || firstName.includes(input) || lastName.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    searchInput.addEventListener('input', searchMentors);

    // Function to display the table of mentors/applicants
    function showTable(data, isApplicant = false) {
        currentData = data;
        let html = `<table><thead>
            <tr><th>ID</th><th>First Name</th><th>Last Name</th><th>Action</th></tr>
        </thead><tbody>`;

        data.forEach(row => {
            // Updated to use new column names: user_id, first_name, last_name
            html += `<tr class="data-row">
                <td>${row.user_id}</td>
                <td class="first-name">${row.first_name}</td>
                <td class="last-name">${row.last_name}</td>
                <td><button onclick="viewDetails(${row.user_id}, ${isApplicant})">View</button></td>
            </tr>`;
        });

        html += '</tbody></table>';
        tableContainer.innerHTML = html;
        tableContainer.classList.remove('hidden');
        detailView.classList.add('hidden');
    }

    // Function to display detailed view of a single user
    function viewDetails(id, isApplicant) {
        // Updated to find user by 'user_id'
        const row = mentorData.find(m => m.user_id == id);
        if (!row) return;

        // Updated to use 'resume' and 'certificates' columns
        let resumeLink = row.resume ? `<a href="view_application.php?file=${encodeURIComponent(row.resume)}&type=resume" target="_blank">View Resume</a>` : "N/A";
        let certLink = row.certificates ? `<a href="view_application.php?file=${encodeURIComponent(row.certificates)}&type=certificate" target="_blank">View Certificate</a>` : "N/A";

        // HTML structure updated with all snake_case column names
        let html = `<div class="details">
            <button onclick="backToTable()">Back</button>
            <h3>${row.first_name} ${row.last_name}</h3>
            <p><strong>First Name:</strong> <input type="text" readonly value="${row.first_name || ''}"></p>
            <p><strong>Last Name:</strong> <input type="text" readonly value="${row.last_name || ''}"></p>
            <p><strong>DOB:</strong> <input type="text" readonly value="${row.dob || ''}"></p>
            <p><strong>Gender:</strong> <input type="text" readonly value="${row.gender || ''}"></p>
            <p><strong>Email:</strong> <input type="text" readonly value="${row.email || ''}"></p>
            <p><strong>Contact:</strong> <input type="text" readonly value="${row.contact_number || ''}"></p>
            <p><strong>Username:</strong> <input type="text" readonly value="${row.username || ''}"></p>
            <p><strong>Mentored Before:</strong> <input type="text" readonly value="${row.mentored_before || ''}"></p>
            <p><strong>Experience:</strong> <input type="text" readonly value="${row.mentoring_experience || ''}"></p>
            <p><strong>Expertise:</strong> <input type="text" readonly value="${row.area_of_expertise || ''}"></p>
            <p><strong>Resume:</strong> ${resumeLink}</p>
            <p><strong>Certificates:</strong> ${certLink}</p>
            <p><strong>Status:</strong> <input type="text" readonly value="${row.status || ''}"></p>
            <p><strong>Reason for Rejection:</strong> <input type="text" readonly value="${row.reason || ''}"></p>`;

        if (isApplicant) {
            // Pass the user_id to the action functions
            html += `<div class="action-buttons">
                <button onclick="showCourseAssignmentPopup(${id})">Approve & Assign Course</button>
                <button onclick="showRejectionDialog(${id})">Reject</button>
            </div>`;
        }

        html += '</div>';
        detailView.innerHTML = html;
        detailView.classList.remove('hidden');
        tableContainer.classList.add('hidden');
    }
    
    // Go back to the table view
    function backToTable() {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');
    }

    // Show course assignment popup
    function showCourseAssignmentPopup(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;

        courseAssignmentPopup.style.display = 'block';
        
        // Fetch available courses
        fetch('?action=get_available_courses')
            .then(response => response.json())
            .then(courses => {
                let popupContent = '';
                
                if (courses.length === 0) {
                    popupContent = `
                        <p>No available courses found. All courses have mentors assigned.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()">Close</button>
                        </div>
                    `;
                } else {
                    popupContent = `
                        <p>Please select a course to assign to <strong>${mentor.first_name} ${mentor.last_name}</strong>:</p>
                        <form id="courseAssignmentForm">
                            <div class="form-group">
                                <label for="courseSelect">Available Courses:</label>
                                <select id="courseSelect" name="course_id" required>
                                    <option value="">-- Select a Course --</option>
                    `;
                    
                    courses.forEach(course => {
                        popupContent += `<option value="${course.Course_ID}">${course.Course_Title}</option>`;
                    });
                    
                    popupContent += `
                                </select>
                            </div>
                            <div class="popup-buttons">
                                <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()">Cancel</button>
                                <button type="button" class="btn-confirm" onclick="confirmCourseAssignment(${mentorId})">Approve & Assign</button>
                            </div>
                        </form>
                    `;
                }
                
                document.getElementById('popupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                document.getElementById('popupBody').innerHTML = `
                    <p>Error loading courses. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()">Close</button>
                    </div>
                `;
            });
    }

    // Close course assignment popup
    function closeCourseAssignmentPopup() {
        courseAssignmentPopup.style.display = 'none';
    }

    // Confirm course assignment
    function confirmCourseAssignment(mentorId) {
        const courseSelect = document.getElementById('courseSelect');
        const courseId = courseSelect.value;
        
        if (!courseId) {
            alert('Please select a course.');
            return;
        }
        
        const confirmButton = document.querySelector('.btn-confirm');
        confirmButton.disabled = true;
        confirmButton.textContent = 'Processing...';
        
        // Send approval request with course assignment
        const formData = new FormData();
        formData.append('action', 'approve_with_course');
        formData.append('mentor_id', mentorId);
        formData.append('course_id', courseId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
                confirmButton.disabled = false;
                confirmButton.textContent = 'Approve & Assign';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
            confirmButton.disabled = false;
            confirmButton.textContent = 'Approve & Assign';
        });
    }

    // Show the rejection reason dialog
    function showRejectionDialog(id) {
        // Find user by 'user_id' and use snake_case columns
        const row = mentorData.find(m => m.user_id == id);
        const prefix = row.gender && row.gender.toLowerCase() === 'female' ? 'Ms.' : 'Mr.';
        
        const dialog = document.createElement('div');
        dialog.className = 'rejection-dialog';
        dialog.innerHTML = `
            <div class="rejection-content">
            <h3>Rejection Reason</h3>
            <p>Please provide a reason for rejecting ${prefix} ${row.first_name} ${row.last_name}'s application:</p>
            <textarea id="rejectionReason" placeholder="Enter rejection reason here..."></textarea>
            <div class="dialog-buttons">
                <button id="cancelReject">Cancel</button>
                <button id="confirmReject">Confirm Rejection</button>
            </div>
            </div>
        `;
        document.body.appendChild(dialog);
        
        document.getElementById('cancelReject').addEventListener('click', () => {
            document.body.removeChild(dialog);
        });
        
        document.getElementById('confirmReject').addEventListener('click', () => {
            const reason = document.getElementById('rejectionReason').value.trim();
            if (reason === '') {
                alert('Please provide a rejection reason.');
                return;
            }
            updateStatusWithReason(id, 'Rejected', reason);
            document.body.removeChild(dialog);
        });
    }

    // Update status without a reason (for approvals) - keeping for backward compatibility
    function updateStatus(id, newStatus) {
        fetch('update_mentor_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&status=${newStatus}`
        })
        .then(response => response.text())
        .then(msg => {
            alert(msg);
            location.reload();
        }).catch(error => console.error('Error:', error));
    }

    // Update status with a rejection reason
    function updateStatusWithReason(id, newStatus, reason) {
        fetch('update_mentor_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&status=${newStatus}&reason=${encodeURIComponent(reason)}`
        })
        .then(response => response.text())
        .then(msg => {
            alert(msg);
            location.reload();
        }).catch(error => console.error('Error:', error));
    }

    // Button click handlers
    btnMentors.onclick = () => {
        showTable(approved, false);
    };

    btnApplicants.onclick = () => {
        showTable(applicants, true);
    };

    btnRejected.onclick = () => {
        showTable(rejected, false);
    };
    
    // Initial view: show applicants by default if there are any, otherwise show mentors
    document.addEventListener('DOMContentLoaded', () => {
        if (applicants.length > 0) {
            showTable(applicants, true);
        } else {
            showTable(approved, false);
        }
    });

    // Close popup when clicking outside of it
    window.onclick = function(event) {
        if (event.target === courseAssignmentPopup) {
            closeCourseAssignmentPopup();
        }
    }

    // --- End of inlined admin_mentors.js ---

    // Logout confirmation
    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../logout.php";
        }
    }
</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

</body>
</html>