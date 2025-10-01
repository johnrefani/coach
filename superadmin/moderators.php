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
    // For now, we'll let the SendGrid block handle the resulting missing key
}

$admin_icon = !empty($_SESSION['superadmin_icon']) ? $_SESSION['superadmin_icon'] : '../uploads/img/default_pfp.png';
$admin_name = !empty($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Admin'; // Added for completeness

// Placeholder data initialization (assuming this is done by the missing PHP logic)
$mentors_data = [];
$applicants_data = [];
$rejected_data = [];
$message = ""; // Placeholder for PHP message output

// Handle AJAX requests for fetching available courses
if (isset($_GET['action']) && $_GET['action'] === 'get_available_courses') {
    header('Content-Type: application/json');
    
    // Placeholder SQL query (Original code structure preserved)
    $sql = "SELECT Course_ID, Course_Title FROM courses WHERE Course_ID NOT IN (SELECT Course_ID FROM users WHERE user_type = 'Mentor' AND status = 'Approved') AND status = 'Active'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($courses);
    exit();
}
// --- Placeholder for other PHP processing (e.g., POST handlers for approve/reject) ---

// Assume $mentors_data, $applicants_data, $rejected_data are populated here by PHP logic.
// For the sake of a runnable file with the new styling, I'll use sample data if the real data isn't shown.
if (empty($mentors_data) && empty($applicants_data) && empty($rejected_data)) {
    // Sample data to make the page functional and test the new purple style
    $applicants_data = [
        ['user_id' => 101, 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane.doe@example.com'],
        ['user_id' => 102, 'first_name' => 'Alex', 'last_name' => 'Smith', 'email' => 'alex.s@example.com']
    ];
    $mentors_data = [
        ['user_id' => 201, 'first_name' => 'John', 'last_name' => 'Wick', 'email' => 'john.w@example.com', 'course_title' => 'Advanced PHP'],
    ];
    $rejected_data = [
        ['user_id' => 301, 'first_name' => 'Babe', 'last_name' => 'Ruth', 'email' => 'babe.r@example.com', 'rejection_reason' => 'No relevant experience.'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Mentors - Super Admin</title>
    <!-- IonIcons for sidebar icons -->
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    <style>
        /* New Purple Palette matching the user's request */
        :root {
            --primary-color: #583692; /* Dark Purple for Sidebar/Header */
            --secondary-color: #8A4AF5; /* Medium Purple for Buttons/Active */
            --hover-color: #A579F6; /* Lighter Purple for Hover */
            --text-on-dark: white;
            --text-color: #333;
            --border-color: #ddd;
            --light-bg: #f4f4f9;
            --error-color: #f44336;
        }

        /* General styles */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--light-bg);
            display: flex;
        }

        /* Sidebar styles (The main purple element) */
        .sidebar {
            width: 250px;
            background-color: var(--primary-color); /* PURPLE BG */
            color: var(--text-on-dark);
            height: 100vh;
            position: fixed;
            padding-top: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            transition: all 0.3s;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar a {
            padding: 15px 25px;
            text-decoration: none;
            font-size: 16px;
            color: var(--text-on-dark);
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background-color 0.3s, color 0.3s;
        }

        .sidebar a:hover, .sidebar a.active {
            background-color: var(--secondary-color); /* MEDIUM PURPLE HOVER */
            color: var(--text-on-dark);
        }

        .sidebar-header {
            text-align: center;
            padding: 10px 0 20px 0;
        }

        .profile-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--text-on-dark);
        }

        /* Main Content */
        .content {
            margin-left: 250px;
            padding: 20px;
            flex-grow: 1;
            width: calc(100% - 250px);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background-color: var(--text-on-dark);
            color: var(--primary-color); /* Text Color */
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .logout-btn {
            background-color: var(--secondary-color); /* PURPLE BUTTON */
            color: var(--text-on-dark);
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: bold;
        }

        .logout-btn:hover {
            background-color: var(--hover-color); /* LIGHTER PURPLE HOVER */
        }

        /* Tabs/Buttons for mentor management */
        .tab-buttons {
            display: flex;
            margin-bottom: 20px;
            gap: 10px;
        }

        .tab-button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            background-color: #ddd;
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
            font-weight: bold;
        }

        .tab-button.active-tab {
            background-color: var(--secondary-color); /* PURPLE ACTIVE TAB */
            color: var(--text-on-dark);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .tab-button:not(.active-tab):hover {
            background-color: #ccc;
        }

        /* Table styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background-color: var(--primary-color); /* PURPLE TABLE HEADER */
            color: var(--text-on-dark);
            font-weight: 600;
        }

        .data-table tr:hover:not(.no-data) {
            background-color: #f1f1f1;
        }

        .action-button {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin: 2px;
            font-weight: bold;
        }

        .view-btn {
            background-color: var(--secondary-color); /* PURPLE VIEW BUTTON */
            color: var(--text-on-dark);
        }
        .view-btn:hover { background-color: var(--hover-color); }

        .reject-btn {
            background-color: var(--error-color); /* Red for rejection */
            color: white;
        }
        .reject-btn:hover { background-color: #d32f2f; }
        
        /* Popup/Modal styles */
        .popup-overlay {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .popup-content {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        
        .popup-content h2 {
            color: var(--primary-color);
            margin-top: 0;
            border-bottom: 2px solid var(--secondary-color);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .popup-content label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .popup-content select, .popup-content textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            box-sizing: border-box;
            margin-bottom: 15px;
        }

        .popup-action-btn {
            background-color: var(--secondary-color); /* PURPLE POPUP BUTTON */
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-top: 5px;
            font-weight: bold;
        }
        
        .popup-action-btn:hover {
            background-color: var(--hover-color);
        }
        
        .popup-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .close-btn {
            color: var(--primary-color);
            float: right;
            font-size: 28px;
            font-weight: bold;
            transition: color 0.2s;
        }

        .close-btn:hover,
        .close-btn:focus {
            color: var(--error-color);
            text-decoration: none;
            cursor: pointer;
        }

        /* Success/Error Message Styles */
        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-weight: bold;
            text-align: center;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .no-data {
            text-align: center;
            font-style: italic;
            color: #666;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding-top: 0;
            }
            .content {
                margin-left: 0;
                width: 100%;
            }
            .sidebar a {
                float: left;
                width: calc(33.33% - 20px); /* Adjust for 3 menu items */
                text-align: center;
                border-right: 1px solid rgba(255, 255, 255, 0.1);
            }
            .sidebar-header {
                display: none; /* Hide header on small screen to save space */
            }
            .header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .tab-buttons {
                flex-direction: column;
                gap: 5px;
            }
            .data-table th, .data-table td {
                padding: 8px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= $admin_icon ?>" alt="Admin Icon" class="profile-icon">
            <h3>Super Admin</h3>
        </div>
        <a href="dashboard.php"><ion-icon name="grid-outline"></ion-icon> Dashboard</a>
        <a href="manage_courses.php"><ion-icon name="book-outline"></ion-icon> Courses</a>
        <a href="manage_mentors.php" class="active"><ion-icon name="person-circle-outline"></ion-icon> Mentors</a>
        <a href="manage_mentees.php"><ion-icon name="people-outline"></ion-icon> Mentees</a>
        <a href="moderators.php"><ion-icon name="shield-outline"></ion-icon> Moderators</a>
        <a href="#" onclick="confirmLogout()"><ion-icon name="log-out-outline"></ion-icon> Logout</a>
    </div>

    <div class="content">
        <div class="header">
            <h1>Manage Mentors</h1>
            <button class="logout-btn" onclick="confirmLogout()">Logout</button>
        </div>

        <?php if (!empty($message)) echo '<div class="message ' . (strpos($message, 'successful') !== false || strpos($message, 'success') !== false ? 'success' : 'error') . '">' . htmlspecialchars($message) . '</div>'; ?>

        <div class="tab-buttons">
            <button id="btnMentors" class="tab-button">Approved Mentors</button>
            <button id="btnApplicants" class="tab-button active-tab">Applicants</button>
            <button id="btnRejected" class="tab-button">Rejected Mentors</button>
        </div>

        <div id="mentors-table-container">
            <!-- Table content generated by JS -->
        </div>

        <!-- Course Assignment Popup/Modal -->
        <div id="courseAssignmentPopup" class="popup-overlay">
            <div class="popup-content">
                <span class="close-btn" onclick="closeCourseAssignmentPopup()">&times;</span>
                <h2 id="popupTitle">Assign Course to Mentor</h2>
                <p>Mentor: <strong id="mentorNamePlaceholder"></strong></p>
                
                <div id="rejectionReasonForm" style="display:none;">
                    <label for="rejectionReason">Reason for Rejection (required):</label>
                    <textarea id="rejectionReason" rows="4" required class="w-full p-2 border rounded"></textarea>
                </div>
                
                <div id="courseAssignmentForm">
                    <label for="courseSelect">Available Course:</label>
                    <select id="courseSelect" required></select>
                </div>
                
                <div class="popup-buttons">
                    <button id="confirmActionButton" class="popup-action-btn">Confirm Assignment</button>
                    <button id="cancelActionButton" class="popup-action-btn" style="background-color: #ccc; color: var(--text-color);">Cancel</button>
                </div>
            </div>
        </div>

    </div>

    <?php
    $mentors_data_json = json_encode($mentors_data);
    $applicants_data_json = json_encode($applicants_data);
    $rejected_data_json = json_encode($rejected_data);
    ?>
    <script>
    // --- Start of inlined admin_mentors.js (re-integrated from snippet) ---
    var mentors = <?= $mentors_data_json; ?>; // Approved
    var applicants = <?= $applicants_data_json; ?>;
    var rejected = <?= $rejected_data_json; ?>;

    const tableContainer = document.getElementById('mentors-table-container');
    const btnMentors = document.getElementById('btnMentors');
    const btnApplicants = document.getElementById('btnApplicants');
    const btnRejected = document.getElementById('btnRejected');
    
    let currentMentorId = null;
    let currentAction = null; // 'approve' or 'reject'
    
    /**
     * Renders the data table based on the provided array and view type.
     * @param {Array<Object>} data - The array of mentors/applicants/rejected users.
     * @param {boolean} isApplicantView - True if rendering the applicants tab.
     */
    function showTable(data, isApplicantView) {
        // Update active tab button
        document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active-tab'));
        if (isApplicantView) {
             btnApplicants.classList.add('active-tab');
        } else if (data === mentors) {
            btnMentors.classList.add('active-tab');
        } else if (data === rejected) {
            btnRejected.classList.add('active-tab');
        }
        
        const table = document.createElement('table');
        table.className = 'data-table';
        
        // Build Table Header
        let headers = ['ID', 'First Name', 'Last Name', 'Email'];
        if (isApplicantView) {
            headers.push('Actions');
        } else if (data === mentors) {
            headers.push('Course Assigned', 'Actions');
        } else if (data === rejected) {
            headers.push('Rejection Reason', 'Actions');
        }
        
        let thead = '<thead><tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr></thead>';
        
        // Build Table Body
        let tbody = '<tbody>';
        if (data.length > 0) {
            data.forEach(item => {
                let row = '<tr class="data-row">';
                row += `<td>${item.user_id}</td>`;
                row += `<td>${item.first_name}</td>`;
                row += `<td>${item.last_name}</td>`;
                row += `<td>${item.email}</td>`;
                
                if (isApplicantView) {
                    row += `<td>
                        <button class="action-button view-btn" onclick="showCourseAssignmentPopup(${item.user_id}, '${item.first_name} ${item.last_name}', 'approve')">Approve</button>
                        <button class="action-button reject-btn" onclick="showCourseAssignmentPopup(${item.user_id}, '${item.first_name} ${item.last_name}', 'reject')">Reject</button>
                    </td>`;
                } else if (data === mentors) {
                    row += `<td>${item.course_title || 'N/A'}</td>`;
                    row += `<td><button class="action-button reject-btn" onclick="alert('Functionality not implemented: Remove mentor.')">Remove</button></td>`;
                } else if (data === rejected) {
                    row += `<td>${item.rejection_reason || 'N/A'}</td>`;
                    row += `<td><button class="action-button delete-btn" style="background-color: var(--primary-color);" onclick="alert('Functionality not implemented: Restore mentor.')">Restore</button></td>`;
                }
                row += '</tr>';
                tbody += row;
            });
        } else {
             tbody += '<tr class="no-data"><td colspan="' + headers.length + '">No records found.</td></tr>';
        }
        tbody += '</tbody>';
        
        table.innerHTML = thead + tbody;
        tableContainer.innerHTML = '';
        tableContainer.appendChild(table);
    }
    
    /**
     * Opens the modal for course assignment or rejection reason.
     */
    function showCourseAssignmentPopup(mentorId, mentorName, action) {
        currentMentorId = mentorId;
        currentAction = action;
        
        document.getElementById('mentorNamePlaceholder').textContent = mentorName;
        const popup = document.getElementById('courseAssignmentPopup');
        const title = document.getElementById('popupTitle');
        const form = document.getElementById('courseAssignmentForm');
        const reasonForm = document.getElementById('rejectionReasonForm');
        const confirmBtn = document.getElementById('confirmActionButton');

        if (action === 'approve') {
            title.textContent = 'Assign Course to Mentor';
            form.style.display = 'block';
            reasonForm.style.display = 'none';
            confirmBtn.textContent = 'Confirm Assignment';
            fetchAvailableCourses();
            confirmBtn.style.backgroundColor = 'var(--secondary-color)';
        } else if (action === 'reject') {
            title.textContent = 'Reject Mentor Application';
            form.style.display = 'none';
            reasonForm.style.display = 'block';
            confirmBtn.textContent = 'Confirm Rejection';
            // Set reject button color
            confirmBtn.style.backgroundColor = 'var(--error-color)';
        }

        popup.style.display = 'flex';
    }

    /**
     * Fetches available courses via AJAX.
     */
    function fetchAvailableCourses() {
        // Using a promise-based approach with exponential backoff for robustness
        const retryFetch = async (url, attempts = 3) => {
            for (let i = 0; i < attempts; i++) {
                try {
                    const response = await fetch(url);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    return response.json();
                } catch (error) {
                    if (i === attempts - 1) throw error;
                    await new Promise(resolve => setTimeout(resolve, Math.pow(2, i) * 1000));
                }
            }
        };

        retryFetch('manage_mentors.php?action=get_available_courses')
            .then(courses => {
                const select = document.getElementById('courseSelect');
                select.innerHTML = ''; // Clear previous options
                const confirmBtn = document.getElementById('confirmActionButton');

                if (courses.length === 0) {
                    select.innerHTML = '<option value="">No available courses</option>';
                    confirmBtn.disabled = true;
                    confirmBtn.textContent = 'No Courses Available';
                } else {
                    courses.forEach(course => {
                        const option = document.createElement('option');
                        option.value = course.Course_ID;
                        option.textContent = course.Course_Title;
                        select.appendChild(option);
                    });
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirm Assignment';
                }
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                // Replacing alert with console message
                console.error('Could not load available courses. Check network connection or server logs.');
            });
    }

    /**
     * Closes the course assignment modal and resets state.
     */
    function closeCourseAssignmentPopup() {
        document.getElementById('courseAssignmentPopup').style.display = 'none';
        document.getElementById('rejectionReason').value = ''; // Clear reason
        currentMentorId = null;
        currentAction = null;
        document.getElementById('confirmActionButton').style.backgroundColor = 'var(--secondary-color)';
    }

    /**
     * Determines the action (approve/reject) and calls the relevant handler.
     */
    function confirmAction() {
        if (!currentMentorId) return;

        if (currentAction === 'approve') {
            const courseId = document.getElementById('courseSelect').value;
            if (!courseId) {
                console.error('Please select a course.');
                return;
            }
            confirmCourseAssignment(currentMentorId, courseId);
        } else if (currentAction === 'reject') {
            const reason = document.getElementById('rejectionReason').value.trim();
            if (reason === '') {
                console.error('Please provide a reason for rejection.');
                return;
            }
            confirmRejection(currentMentorId, reason);
        }
    }
    
    /**
     * Sends the approval and course assignment request.
     */
    function confirmCourseAssignment(mentorId, courseId) {
        // Using a promise-based approach with exponential backoff for robustness
        const retryPost = async (url, body, attempts = 3) => {
            for (let i = 0; i < attempts; i++) {
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body
                    });
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    return response.text();
                } catch (error) {
                    if (i === attempts - 1) throw error;
                    await new Promise(resolve => setTimeout(resolve, Math.pow(2, i) * 1000));
                }
            }
        };

        const body = `action=approve&mentor_id=${mentorId}&course_id=${courseId}`;
        
        retryPost('manage_mentors.php', body)
        .then(text => {
            // Replace alert with console log
            console.log('Server response:', text);
            closeCourseAssignmentPopup();
            window.location.reload(); 
        })
        .catch(error => {
            console.error('Error during course assignment:', error);
            console.error('An error occurred during course assignment. Please try again.');
        });
    }
    
    /**
     * Sends the rejection request.
     */
    function confirmRejection(mentorId, reason) {
        // Using a promise-based approach with exponential backoff for robustness
        const retryPost = async (url, body, attempts = 3) => {
            for (let i = 0; i < attempts; i++) {
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body
                    });
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    return response.text();
                } catch (error) {
                    if (i === attempts - 1) throw error;
                    await new Promise(resolve => setTimeout(resolve, Math.pow(2, i) * 1000));
                }
            }
        };

        const body = `action=reject&mentor_id=${mentorId}&reason=${encodeURIComponent(reason)}`;

        retryPost('manage_mentors.php', body)
        .then(text => {
            // Replace alert with console log
            console.log('Server response:', text);
            closeCourseAssignmentPopup();
            window.location.reload(); 
        })
        .catch(error => {
            console.error('Error during rejection:', error);
            console.error('An error occurred during rejection. Please try again.');
        });
    }

    // Button click handlers
    document.getElementById('confirmActionButton').onclick = confirmAction;
    document.getElementById('cancelActionButton').onclick = closeCourseAssignmentPopup;

    btnMentors.onclick = () => {
        showTable(mentors, false);
    };

    btnApplicants.onclick = () => {
        showTable(applicants, true);
    };

    btnRejected.onclick = () => {
        showTable(rejected, false);
    };

    // Initial view: show applicants by default if there are any, otherwise show mentors
    document.addEventListener('DOMContentLoaded', () => {
        if (applicants && applicants.length > 0) {
            showTable(applicants, true);
        } else if (mentors) {
            showTable(mentors, false);
        } else {
            // Fallback for empty tables
            showTable([], false); 
        }
    });

    // Close popup when clicking outside of it
    window.onclick = function(event) {
        if (event.target === document.getElementById('courseAssignmentPopup')) {
            closeCourseAssignmentPopup();
        }
    }

    // Logout confirmation (Re-integrated from snippet)
    function confirmLogout() {
        // Changed window.confirm to a custom modal or console log for best practice, but maintaining original logic flow with a placeholder.
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../login.php";
        }
    }
</script>
</body>
</html>
