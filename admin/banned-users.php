<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Check if user is logged in and is an administrator
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Include database connection
include 'db.php'; // Ensure your db.php file connects to $conn

$page_title = "Admin: Banned Users & Appeals";
include 'header.php'; // Include the header HTML (assuming it exists)


// --- BAN MANAGEMENT POST HANDLERS ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle Unban Request (Existing Logic)
    if ($action === 'unban' && isset($_POST['username'])) {
        $username_to_unban = $_POST['username'];
        $stmt = $conn->prepare("DELETE FROM banned_users WHERE username = ?");
        $stmt->bind_param("s", $username_to_unban);

        if ($stmt->execute()) {
            $_SESSION['admin_success'] = "User **" . htmlspecialchars($username_to_unban) . "** has been successfully unbanned.";
        } else {
            $_SESSION['admin_error'] = "Error unbanning user: " . $stmt->error;
        }
        $stmt->close();
        
        header("Location: banned-users.php");
        exit();
    }
    
    // Handle Appeal Actions (Approve/Reject) (NEW LOGIC)
    elseif ($action === 'handle_appeal' && isset($_POST['appeal_id'], $_POST['status'])) {
        $appeal_id = intval($_POST['appeal_id']);
        $status = $_POST['status']; // 'approved' or 'rejected'

        // 1. Get the username associated with the appeal
        $stmt = $conn->prepare("SELECT username FROM ban_appeals WHERE id = ?");
        $stmt->bind_param("i", $appeal_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $appeal_user = $result->fetch_assoc();
        $stmt->close();

        if ($appeal_user) {
            $username_to_unban = $appeal_user['username'];

            // 2. Update the appeal status
            $stmt_appeal = $conn->prepare("UPDATE ban_appeals SET status = ? WHERE id = ?");
            $stmt_appeal->bind_param("si", $status, $appeal_id);
            $stmt_appeal->execute();
            $stmt_appeal->close();

            // 3. If approved, unban the user
            if ($status === 'approved') {
                // Delete the ban entry
                $stmt_unban = $conn->prepare("DELETE FROM banned_users WHERE username = ?");
                $stmt_unban->bind_param("s", $username_to_unban);
                $stmt_unban->execute();
                $stmt_unban->close();
                $_SESSION['admin_success'] = "Appeal for user **$username_to_unban** approved and user **unbanned**.";
            } else {
                $_SESSION['admin_success'] = "Appeal for user **$username_to_unban** rejected.";
            }
        } else {
            $_SESSION['admin_error'] = "Appeal not found.";
        }

        header("Location: banned-users.php");
        exit();
    }
}


// --- DATA FETCHING ---

// 1. Fetch all currently banned users (Existing Logic)
$banned_users = [];
$stmt = $conn->prepare("SELECT username, ban_reason, ban_date, ban_expires FROM banned_users ORDER BY ban_date DESC");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $banned_users[] = $row;
    }
}
$stmt->close();

// 2. Fetch all pending appeals (NEW LOGIC)
$appeals = [];
$stmt = $conn->prepare("SELECT id, username, reason, appeal_date FROM ban_appeals WHERE status = 'pending' ORDER BY appeal_date DESC");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $appeals[] = $row;
    }
}
$stmt->close();

?>

<style>
    /* Minimal CSS for readability, adjust as needed */
    .user-management-section {
        margin-top: 20px;
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .user-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    .user-table th, .user-table td {
        border: 1px solid #ddd;
        padding: 12px;
        text-align: center;
    }
    .user-table th {
        background-color: #f2f2f2;
        color: #333;
    }
    .action-btn {
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        color: white;
        cursor: pointer;
        font-weight: bold;
    }
    .success-message {
        background: #d4edda;
        color: #155724;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
        border: 1px solid #c3e6cb;
    }
    .error-message {
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
        border: 1px solid #f5c6cb;
    }
</style>

<div class="container">
    <h1 style="color: #6a0dad;">Admin Dashboard</h1>
    
    <?php 
    // Display Success/Error Messages
    if (isset($_SESSION['admin_success'])) {
        echo '<div class="success-message">' . $_SESSION['admin_success'] . '</div>';
        unset($_SESSION['admin_success']);
    }
    if (isset($_SESSION['admin_error'])) {
        echo '<div class="error-message">' . $_SESSION['admin_error'] . '</div>';
        unset($_SESSION['admin_error']);
    }
    ?>
    
    <h2 style="color: #6a0dad;">Currently Banned Users (<?php echo count($banned_users); ?>)</h2>

    <div class="user-management-section">
        <?php if (empty($banned_users)): ?>
            <p style="text-align: center; padding: 20px; color: #555;">No users are currently banned.</p>
        <?php else: ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Reason</th>
                        <th>Ban Date</th>
                        <th>Expires</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($banned_users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['ban_reason']); ?></td>
                            <td><?php echo date("Y-m-d", strtotime($user['ban_date'])); ?></td>
                            <td>
                                <?php 
                                    if ($user['ban_expires']) {
                                        echo date("Y-m-d", strtotime($user['ban_expires']));
                                    } else {
                                        echo "Permanent";
                                    }
                                ?>
                            </td>
                            <td>
                                <form action="banned-users.php" method="POST" style="display: inline-block;">
                                    <input type="hidden" name="action" value="unban">
                                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($user['username']); ?>">
                                    <button type="submit" class="action-btn" style="background-color: #007bff;" 
                                        onclick="return confirm('Are you sure you want to UNBAN <?php echo htmlspecialchars($user['username']); ?>?');">
                                        Unban
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    
    <hr style="margin: 40px 0;">

    <h2 style="color: #6a0dad;">Pending Ban Appeals (<?php echo count($appeals); ?>)</h2>

    <div class="user-management-section">
        <?php if (empty($appeals)): ?>
            <p style="text-align: center; padding: 20px; color: #555;">No pending ban appeals at this time. 🎉</p>
        <?php else: ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Reason</th>
                        <th>Appeal Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appeals as $appeal): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($appeal['id']); ?></td>
                            <td><?php echo htmlspecialchars($appeal['username']); ?></td>
                            <td style="max-width: 400px; text-align: left; font-size: 0.9em;">
                                <?php echo nl2br(htmlspecialchars($appeal['reason'])); ?>
                            </td>
                            <td><?php echo date("Y-m-d H:i", strtotime($appeal['appeal_date'])); ?></td>
                            <td>
                                <form action="banned-users.php" method="POST" style="display: inline-block;">
                                    <input type="hidden" name="action" value="handle_appeal">
                                    <input type="hidden" name="appeal_id" value="<?php echo $appeal['id']; ?>">
                                    <input type="hidden" name="status" value="approved">
                                    <button type="submit" class="action-btn" style="background-color: #28a745;" 
                                        onclick="return confirm('Are you sure you want to APPROVE this appeal and UNBAN the user?');">
                                        ✅ Approve
                                    </button>
                                </form>
                                <form action="banned-users.php" method="POST" style="display: inline-block; margin-left: 5px;">
                                    <input type="hidden" name="action" value="handle_appeal">
                                    <input type="hidden" name="appeal_id" value="<?php echo $appeal['id']; ?>">
                                    <input type="hidden" name="status" value="rejected">
                                    <button type="submit" class="action-btn" style="background-color: #dc3545;"
                                        onclick="return confirm('Are you sure you want to REJECT this appeal? This will not unban the user.');">
                                        ❌ Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<?php 
include 'footer.php'; // Include the footer HTML (assuming it exists)
// Close the database connection
$conn->close();
?>