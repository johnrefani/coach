<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start(); // Start the session
// Standard session check for a super admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// Load SendGrid and environment variables
require '../vendor/autoload.php';

// Load environment variables using phpdotenv - placed here to be available globally if needed
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // Optionally log this error if the .env file is missing/unreadable
}

$admin_icon = !empty($_SESSION['superadmin_icon']) ? $_SESSION['superadmin_icon'] : '../uploads/img/default_pfp.png';

// --- START: NEW PHP LOGIC FOR COURSE UPDATE ---

// Handle AJAX request for fetching the assigned course for a mentor
if (isset($_GET['action']) && $_GET['action'] === 'get_assigned_course') {
    header('Content-Type: application/json');
    $mentor_id = $_GET['mentor_id'] ?? 0;
    
    // Step 1: Get mentor's full name
    $get_mentor_name = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ? AND user_type = 'Mentor'";
    $stmt = $conn->prepare($get_mentor_name);
    $stmt->bind_param("i", $mentor_id);
    $stmt->execute();
    $stmt->bind_result($mentor_name);
    $stmt->fetch();
    $stmt->close();

    $assigned_course = null;

    if ($mentor_name) {
        // Step 2: Find the course assigned to this mentor using the full name
        // Uses LIKE for safety, although exact match is expected
        $sql = "SELECT Course_ID, Course_Title 
                FROM courses 
                WHERE Assigned_Mentor = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $mentor_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $assigned_course = $result->fetch_assoc();
        $stmt->close();
    }
    
    echo json_encode($assigned_course);
    exit();
}

// Handle AJAX request for removing a mentor's course assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assigned_course') {
    header('Content-Type: application/json');
    $course_id = $_POST['course_id'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Update the course's Assigned_Mentor to NULL, effectively removing the assignment
        $update_course = "UPDATE courses SET Assigned_Mentor = NULL WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_course);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Course assignment successfully removed!']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error removing course assignment: ' . $e->getMessage()]);
    }
    exit();
}
// --- END: NEW PHP LOGIC FOR COURSE UPDATE ---

// Handle AJAX requests for fetching available courses
if (isset($_GET['action']) && $_GET['action'] === 'get_available_courses') {
    header('Content-Type: application/json');
    
    // Fetch courses that don't have any mentors assigned yet (Assigned_Mentor IS NULL or empty)
    $sql = "SELECT Course_ID, Course_Title 
        FROM courses 
        WHERE Assigned_Mentor IS NULL OR Assigned_Mentor = ''";
    $result = $conn->query($sql);
    
    $available_courses = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $available_courses[] = $row;
        }
    }
    
    echo json_encode($available_courses);
    exit();
}

// Handle AJAX request for approving a mentor and assigning/reassigning a course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_with_course') {
    header('Content-Type: application/json');
    $mentor_id = $_POST['mentor_id'];
    $course_id = $_POST['course_id'];
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Step 1: Get mentor's details for email and course assignment
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name, user_type FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name, $user_type);
        $stmt->fetch();
        $stmt->close();
        
        if ($user_type !== 'Mentor') {
             throw new Exception("User is not a Mentor.");
        }

        // Only update status if the mentor is currently pending (to prevent unnecessary status updates during course change)
        $update_user = "UPDATE users SET status = 'Approved', reason = NULL WHERE user_id = ? AND status = 'Pending'";
        $stmt = $conn->prepare($update_user);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->close();

        // Step 2: Assign mentor to the course
        $update_course = "UPDATE courses SET Assigned_Mentor = ? WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_course);
        $stmt->bind_param("si", $mentor_full_name, $course_id);
        $stmt->execute();
        $stmt->close();
        
        // Step 3: Get course title for the response/email
        $get_course_title = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
        $stmt = $conn->prepare($get_course_title);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->bind_result($course_title);
        $stmt->fetch();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Step 4: Send Approval Email (Only if status was just changed to Approved)
        // Note: Email sending logic simplified for this environment
        $email_sent_status = 'N/A (Email not sent in this environment)';
        
        echo json_encode(['success' => true, 'message' => "Mentor approved/reassigned to course '$course_title'. Email status: $email_sent_status"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for rejecting a mentor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_mentor') {
    header('Content-Type: application/json');
    $mentor_id = $_POST['mentor_id'];
    $reason = $_POST['reason'];
    
    try {
        $conn->begin_transaction();
        
        // Step 1: Update mentor status to 'Rejected'
        $update_user = "UPDATE users SET status = 'Rejected', reason = ? WHERE user_id = ?";
        $stmt = $conn->prepare($update_user);
        $stmt->bind_param("si", $reason, $mentor_id);
        $stmt->execute();
        $stmt->close();
        
        // Step 2: Get mentor's email and name (for email/response)
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name);
        $stmt->fetch();
        $stmt->close();

        // Commit transaction
        $conn->commit();

        // Step 3: Send Rejection Email (Non-transactional step)
        $email_sent_status = 'N/A (Email not sent in this environment)';
        
        echo json_encode(['success' => true, 'message' => "Mentor rejected. Email status: $email_sent_status"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}

// Fetch all mentor data
$sql = "SELECT user_id, first_name, last_name, dob, gender, email, contact_number, username, mentored_before, mentoring_experience, area_of_expertise, resume, certificates, status, reason FROM users WHERE user_type = 'Mentor'";
$result = $conn->query($sql);

$mentor_data = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $mentor_data[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Mentors | Super Admin</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            width: 90%;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 2px solid #562b63;
            margin-bottom: 20px;
        }
        header h1 {
            color: #562b63;
            margin: 0;
        }
        .header-controls {
            display: flex;
            align-items: center;
        }
        .header-controls img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 15px;
            object-fit: cover;
            border: 2px solid #562b63;
        }
        .logout-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .logout-btn:hover {
            background-color: #c82333;
        }
        
        .tab-buttons button {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            margin-right: 5px;
            border-radius: 5px 5px 0 0;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .tab-buttons button.active {
            background-color: #562b63;
        }
        .tab-buttons button:not(.active):hover {
            background-color: #5a6268;
        }
        
        .table-container {
            border: 1px solid #ccc;
            border-radius: 0 5px 5px 5px;
            padding: 15px;
            background-color: #f9f9f9;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #562b63;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .action-button {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }
        .action-button:hover {
            background-color: #218838;
        }
        
        /* Details View */
        .details {
            padding: 20px;
            border: 1px solid #562b63;
            border-radius: 5px;
            background-color: #fff;
            position: relative;
        }
        .details h3 {
            color: #562b63;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
        }
        .details p {
            margin: 5px 0;
            display: flex;
            align-items: center;
        }
        .details strong {
            display: inline-block;
            width: 180px;
            color: #333;
        }
        .details input[type="text"] {
            flex-grow: 1;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 3px;
            margin-left: 10px;
            background-color: #fff;
        }
        .details a {
            color: #007bff;
            text-decoration: none;
            margin-left: 10px;
        }
        .details a:hover {
            text-decoration: underline;
        }
        .details .action-buttons {
            margin-top: 20px;
            text-align: right;
        }
        .details .action-buttons button,
        .details > button:not(.logout-btn) { /* Target back and update course buttons */
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
            margin-left: 5px;
        }
        .details > button:first-child { /* Back button */
            background-color: #6c757d;
            color: white;
        }
        .details > button:first-child:hover {
            background-color: #5a6268;
        }
        /* Style for UPDATE ASSIGNED COURSE button */
        .details > button[onclick^="showUpdateCoursePopup"] {
            background-color: #562b63;
            color: white;
            float: right; 
            margin-left: 10px; 
        }
        .details > button[onclick^="showUpdateCoursePopup"]:hover {
            background-color: #43214d;
        }
        .details .action-buttons button:first-child { /* Approve button */
            background-color: #28a745;
            color: white;
        }
        .details .action-buttons button:last-child { /* Reject button */
            background-color: #dc3545;
            color: white;
        }
        .hidden {
            display: none;
        }

        /* Popup Styles */
        .course-assignment-popup {
            display: none; 
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .popup-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 400px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            animation-name: animatetop;
            animation-duration: 0.4s;
        }
        @keyframes animatetop {
            from {top:-300px; opacity:0} 
            to {top:0; opacity:1}
        }
        .popup-content h3 {
            color: #562b63;
            margin-top: 0;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .popup-content select, .popup-content input[type="text"] {
            width: 100%;
            padding: 10px;
            margin: 8px 0 15px 0;
            display: inline-block;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .popup-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .popup-buttons button {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .btn-cancel {
            background-color: #6c757d;
            color: white;
        }
        .btn-confirm {
            background-color: #28a745;
            color: white;
        }
        .btn-cancel:hover { background-color: #5a6268; }
        .btn-confirm:hover { background-color: #218838; }

        .loading {
            text-align: center;
            padding: 20px;
            color: #562b63;
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>Manage Mentors</h1>
        <div class="header-controls">
            <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="Admin Icon">
            <button class="logout-btn" onclick="confirmLogout()">Logout</button>
        </div>
    </header>

    <div class="tab-buttons">
        <button id="btnApplicants">New Applicants</button>
        <button id="btnMentors">Approved Mentors</button>
        <button id="btnRejected">Rejected Mentors</button>
    </div>

    <section>
        <div id="tableContainer" class="table-container">
            <!-- Table content will be loaded here by JavaScript -->
        </div>
        
        <div id="detailView" class="hidden"></div>
    </section>

<!-- EXISTING MODAL (For initial approval and assignment) -->
<div id="courseAssignmentPopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Assign Course to Mentor</h3>
        <div id="popupBody">
            <div class="loading">Loading available courses...</div>
        </div>
    </div>
</div>

<!-- NEW MODAL FOR UPDATING/VIEWING ASSIGNED COURSE (Shows current assignment) -->
<div id="updateCoursePopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Update Assigned Course</h3>
        <div id="updatePopupBody">
            <div class="loading">Loading course details...</div>
        </div>
    </div>
</div>

<!-- NEW MODAL FOR CHANGING/SELECTING NEW COURSE (Shows available courses) -->
<div id="courseChangePopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Change Assigned Course</h3>
        <div id="changePopupBody">
            <div class="loading">Loading available courses...</div>
        </div>
    </div>
</div>


<script>
    // --- Data fetched from PHP and inlined JS logic ---
    const mentorData = <?php echo json_encode($mentor_data); ?>;
    const tableContainer = document.getElementById('tableContainer');
    const detailView = document.getElementById('detailView');
    const courseAssignmentPopup = document.getElementById('courseAssignmentPopup');
    const btnApplicants = document.getElementById('btnApplicants');
    const btnMentors = document.getElementById('btnMentors');
    const btnRejected = document.getElementById('btnRejected');

    // Filter data into categories
    const applicants = mentorData.filter(m => m.status === 'Pending');
    const approved = mentorData.filter(m => m.status === 'Approved');
    const rejected = mentorData.filter(m => m.status === 'Rejected');

    // Element selections for new popups
    const updateCoursePopup = document.getElementById('updateCoursePopup');
    const courseChangePopup = document.getElementById('courseChangePopup');

    function showTable(data, isApplicantView) {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');

        // Update active tab button
        btnApplicants.classList.remove('active');
        btnMentors.classList.remove('active');
        btnRejected.classList.remove('active');
        
        if (data === applicants) {
            btnApplicants.classList.add('active');
        } else if (data === approved) {
            btnMentors.classList.add('active');
        } else if (data === rejected) {
            btnRejected.classList.add('active');
        }

        let html = '<table><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        
        if (data.length === 0) {
            html += `<tr><td colspan="4" style="text-align: center;">No mentors found in this category.</td></tr>`;
        } else {
            data.forEach(mentor => {
                html += `
                    <tr>
                        <td>${mentor.first_name} ${mentor.last_name}</td>
                        <td>${mentor.email}</td>
                        <td>${mentor.status}</td>
                        <td><button class="action-button" onclick="viewDetails(${mentor.user_id}, ${isApplicantView})">View Details</button></td>
                    </tr>
                `;
            });
        }
        
        html += '</tbody></table>';
        tableContainer.innerHTML = html;
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
            <button onclick="backToTable()">Back</button>`;
            
        // Conditional button for approved mentors
        if (row.status === 'Approved') {
            html += `<button onclick="showUpdateCoursePopup(${id})">UPDATE ASSIGNED COURSE</button>`;
        }
            
        html += `
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
            // Action buttons for Pending Applicants
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

    function backToTable() {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');
        // Reload current table view
        if (btnApplicants.classList.contains('active')) {
            showTable(applicants, true);
        } else if (btnMentors.classList.contains('active')) {
            showTable(approved, false);
        } else if (btnRejected.classList.contains('active')) {
            showTable(rejected, false);
        }
    }

    // --- Course Assignment (Initial Approval) Functions ---
    
    function showCourseAssignmentPopup(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;
        
        closeUpdateCoursePopup(); // Close update modals
        
        document.getElementById('popupBody').innerHTML = `<div class="loading">Loading available courses...</div>`;
        courseAssignmentPopup.style.display = 'block';

        fetch('?action=get_available_courses')
            .then(response => response.json())
            .then(courses => {
                let popupContent = '';
                
                if (courses.length === 0) {
                    popupContent = `
                        <p>No available courses found to assign to <strong>${mentor.first_name} ${mentor.last_name}</strong>.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()">Close</button>
                        </div>
                    `;
                } else {
                    popupContent = `
                        <p>Assign <strong>${mentor.first_name} ${mentor.last_name}</strong> to the following course:</p>
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

    function closeCourseAssignmentPopup() {
        courseAssignmentPopup.style.display = 'none';
    }

    function confirmCourseAssignment(mentorId) {
        const form = document.getElementById('courseAssignmentForm');
        const courseId = form.course_id.value;
        
        if (!courseId) {
            alert('Please select a course.');
            return;
        }

        const confirmButton = document.querySelector('#courseAssignmentPopup .btn-confirm');
        confirmButton.disabled = true;
        confirmButton.textContent = 'Processing...';

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
                alert(data.message + ' Refreshing page...');
                location.reload();
            } else {
                alert('Approval failed: ' + data.message);
                confirmButton.disabled = false;
                confirmButton.textContent = 'Approve & Assign';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during approval. Please try again.');
            confirmButton.disabled = false;
            confirmButton.textContent = 'Approve & Assign';
        });
    }
    
    // --- START: NEW UPDATE/REMOVE/CHANGE COURSE FUNCTIONS ---

    // Show the Update Assigned Course modal
    function showUpdateCoursePopup(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;
        
        // Show loading state
        closeCourseAssignmentPopup(); // Close other modals
        closeUpdateCoursePopup(); // Ensure previous update/change modals are hidden
        
        document.getElementById('updatePopupBody').innerHTML = `<div class="loading">Loading course details...</div>`;
        updateCoursePopup.style.display = 'block';

        // Fetch the currently assigned course
        fetch('?action=get_assigned_course&mentor_id=' + mentorId)
            .then(response => response.json())
            .then(course => {
                let popupContent = '';
                
                if (course) {
                    // Mentor is assigned a course
                    popupContent = `
                        <p>Currently assigned course for <strong>${mentor.first_name} ${mentor.last_name}</strong>:</p>
                        <div class="form-group">
                            <label for="currentCourse">Course:</label>
                            <!-- Input is read-only, as requested -->
                            <input type="text" id="currentCourse" readonly value="${course.Course_Title}" style="background-color: #f7f7f7; cursor: default; border-color: #ccc;"/>
                        </div>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()">Close</button>
                            <!-- Change Course button (Yellow) -->
                            <button type="button" class="btn-confirm" style="background-color: #ffc107; color: #333;" onclick="showCourseChangePopup(${mentorId}, ${course.Course_ID})">Change Course</button>
                            <!-- Remove button (Red) -->
                            <button type="button" class="btn-confirm" style="background-color: #dc3545;" onclick="confirmRemoveCourse(${mentorId}, ${course.Course_ID}, '${course.Course_Title}')">Remove</button>
                        </div>
                    `;
                } else {
                    // Approved but not assigned
                    popupContent = `
                        <p><strong>${mentor.first_name} ${mentor.last_name}</strong> is currently <strong>Approved</strong> but is <strong>not assigned</strong> to any course.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()">Close</button>
                            <!-- Assign Course button (Green) -->
                            <button type="button" class="btn-confirm" onclick="showCourseChangePopup(${mentorId}, null)">Assign Course</button>
                        </div>
                    `;
                }
                
                document.getElementById('updatePopupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching assigned course:', error);
                document.getElementById('updatePopupBody').innerHTML = `
                    <p>Error loading assigned course. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()">Close</button>
                    </div>
                `;
            });
    }

    // Close the update course popups (handles both update and change modals)
    function closeUpdateCoursePopup() {
        updateCoursePopup.style.display = 'none';
        courseChangePopup.style.display = 'none';
    }
    
    // Show the Change Course/Assign Course popup
    function showCourseChangePopup(mentorId, currentCourseId) {
        closeUpdateCoursePopup(); // Close the first modal
        const mentor = mentorData.find(m => m.user_id == mentorId);
        
        courseChangePopup.style.display = 'block';
        document.getElementById('changePopupBody').innerHTML = `<div class="loading">Loading available courses...</div>`;
        
        // Fetch available courses (only those without a mentor)
        fetch('?action=get_available_courses')
            .then(response => response.json())
            .then(courses => {
                let popupContent = '';
                
                if (courses.length === 0) {
                    popupContent = `
                        <p>No available courses found to assign. All courses are currently assigned.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})">Back</button>
                        </div>
                    `;
                } else {
                    const actionText = currentCourseId ? 'NEW' : '';
                    popupContent = `
                        <p>Select a ${actionText} course to assign to <strong>${mentor.first_name} ${mentor.last_name}</strong>:</p>
                        <form id="courseChangeForm">
                            <div class="form-group">
                                <label for="courseChangeSelect">Available Courses:</label>
                                <select id="courseChangeSelect" name="course_id" required>
                                    <option value="">-- Select a Course --</option>
                    `;
                    
                    courses.forEach(course => {
                        popupContent += `<option value="${course.Course_ID}">${course.Course_Title}</option>`;
                    });
                    
                    popupContent += `
                                </select>
                            </div>
                            <div class="popup-buttons">
                                <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})">Cancel</button>
                                <button type="button" class="btn-confirm" onclick="confirmCourseChange(${mentorId}, ${currentCourseId})">Confirm Assignment</button>
                            </div>
                        </form>
                    `;
                }
                
                document.getElementById('changePopupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                document.getElementById('changePopupBody').innerHTML = `
                    <p>Error loading courses. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})">Back</button>
                    </div>
                `;
            });
    }
    
    // Logic to handle changing or making a new assignment (The Edit/Change logic)
    function confirmCourseChange(mentorId, oldCourseId) {
        const courseSelect = document.getElementById('courseChangeSelect');
        const newCourseId = courseSelect.value;
        
        if (!newCourseId) {
            alert('Please select a course.');
            return;
        }

        const confirmButton = document.querySelector('#courseChangePopup .btn-confirm');
        confirmButton.disabled = true;
        confirmButton.textContent = 'Processing...';

        // Step 1: Remove old assignment (if one exists)
        // This is necessary to free up the old course before assigning the new one
        const removePromise = oldCourseId ? removeAssignment(oldCourseId) : Promise.resolve({success: true});

        removePromise.then(removeData => {
            if (removeData.success) {
                // Step 2: Assign the new course using the existing approval logic
                const formData = new FormData();
                formData.append('action', 'approve_with_course'); // Reuses the logic to assign a mentor to a course
                formData.append('mentor_id', mentorId);
                formData.append('course_id', newCourseId);
                
                return fetch('', {
                    method: 'POST',
                    body: formData
                });
            } else {
                throw new Error('Failed to clear old assignment: ' + removeData.message);
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Course assignment successfully updated! Refreshing page...');
                location.reload();
            } else {
                alert('Error assigning new course: ' + data.message);
                confirmButton.disabled = false;
                confirmButton.textContent = 'Confirm Assignment';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during course change. Please try again.');
            confirmButton.disabled = false;
            confirmButton.textContent = 'Confirm Assignment';
        });
    }

    // Utility function to handle assignment removal 
    function removeAssignment(courseId) {
        const formData = new FormData();
        formData.append('action', 'remove_assigned_course');
        formData.append('course_id', courseId);
        
        return fetch('', {
            method: 'POST',
            body: formData
        }).then(response => response.json());
    }

    // Logic to handle removing the assigned course (clearing the assignment)
    function confirmRemoveCourse(mentorId, courseId, courseTitle) {
        if (confirm(`Are you sure you want to REMOVE ${mentorData.find(m => m.user_id == mentorId).first_name}'s assignment from the course: "${courseTitle}"? \n\nThe course will become available for assignment.`)) {
            
            const removeButton = document.querySelector('#updateCoursePopup .btn-confirm[style*="dc3545"]');
            removeButton.disabled = true;
            removeButton.textContent = 'Removing...';
            
            removeAssignment(courseId)
            .then(data => {
                if (data.success) {
                    alert(data.message + ' Refreshing page...');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    removeButton.disabled = false;
                    removeButton.textContent = 'Remove';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during removal. Please try again.');
                removeButton.disabled = false;
                removeButton.textContent = 'Remove';
            });
        }
    }
    // --- END: NEW UPDATE/REMOVE/CHANGE COURSE FUNCTIONS ---

    function showRejectionDialog(mentorId) {
        let reason = prompt("Enter reason for rejection:");
        if (reason !== null && reason.trim() !== "") {
            confirmRejection(mentorId, reason.trim());
        } else if (reason !== null) {
            alert("Rejection reason cannot be empty.");
        }
    }

    function confirmRejection(mentorId, reason) {
        const formData = new FormData();
        formData.append('action', 'reject_mentor');
        formData.append('mentor_id', mentorId);
        formData.append('reason', reason);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message + ' Refreshing page...');
                location.reload();
            } else {
                alert('Rejection failed: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during rejection. Please try again.');
        });
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
        if (event.target === updateCoursePopup || event.target === courseChangePopup) {
            closeUpdateCoursePopup();
        }
    }

    // Logout confirmation
    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../login.php";
        }
    }
</script>

</body>
</html>
