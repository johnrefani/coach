<?php
session_start();

// --- Database configuration ---
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

// --- Establish DB connection ---
$conn = new mysqli($servername, $username, $password, $dbname);

// --- Check DB connection ---
if ($conn->connect_error) {
    http_response_code(500);
    echo "Database connection failed: " . $conn->connect_error;
    exit();
}

// --- Log received data for debugging ---
$log_message = "POST data received: " . print_r($_POST, true);
error_log($log_message);

// --- Get and validate POST data ---
$resourceID = isset($_POST['resource_id']) ? intval($_POST['resource_id']) : null;
$newStatus = isset($_POST['action']) ? trim($_POST['action']) : null;
$rejectionReason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;

// --- Log processed data ---
error_log("Processed data - Resource ID: $resourceID, New Status: $newStatus, Rejection Reason: $rejectionReason");

$validStatuses = ['Approved', 'Under Review', 'Rejected'];

if (!$resourceID || !in_array($newStatus, $validStatuses)) {
    http_response_code(400);
    echo "Invalid input: Resource ID = $resourceID, Status = $newStatus";
    exit();
}

// --- Get the Applicant_Username, Title, and UploadedBy from the resource ---
$applicantUsername = null;
$resourceTitle = null;
$uploadedBy = null;
$usernameStmt = $conn->prepare("SELECT Applicant_Username, Resource_Title, UploadedBy FROM resources WHERE Resource_ID = ?");
$usernameStmt->bind_param("i", $resourceID);
$usernameStmt->execute();
$usernameStmt->bind_result($applicantUsername, $resourceTitle, $uploadedBy);
$usernameStmt->fetch();
$usernameStmt->close();

// --- Log resource info ---
error_log("Resource info - Applicant: $applicantUsername, Title: $resourceTitle, Uploaded By: $uploadedBy");

// --- Get the applicant's email from the applications table ---
$email = null;
if ($applicantUsername) {
    $emailStmt = $conn->prepare("SELECT Email FROM applications WHERE Applicant_Username = ?");
    $emailStmt->bind_param("s", $applicantUsername);
    $emailStmt->execute();
    $emailStmt->bind_result($email);
    $emailStmt->fetch();
    $emailStmt->close();
    
    error_log("Applicant email: $email");
}

// --- Prepare and execute the SQL update ---
// Add rejection reason if provided
if ($newStatus === 'Rejected' && $rejectionReason) {
    $stmt = $conn->prepare("UPDATE resources SET Status = ?, Reason = ? WHERE Resource_ID = ?");
    if (!$stmt) {
        http_response_code(500);
        error_log("Failed to prepare statement: " . $conn->error);
        echo "Failed to prepare statement: " . $conn->error;
        exit();
    }
    $stmt->bind_param("ssi", $newStatus, $rejectionReason, $resourceID);
} else {
    $stmt = $conn->prepare("UPDATE resources SET Status = ? WHERE Resource_ID = ?");
    if (!$stmt) {
        http_response_code(500);
        error_log("Failed to prepare statement: " . $conn->error);
        echo "Failed to prepare statement: " . $conn->error;
        exit();
    }
    $stmt->bind_param("si", $newStatus, $resourceID);
}

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo "Status updated successfully";
        error_log("Status updated successfully for Resource ID: $resourceID");

        // --- Send email notification ---
        if ($email) {
            $subject = "Your Uploaded Resource Status Has Been Updated";
            
            // Add title case for Mr./Ms. based on name
            $namePrefix = "Mr./Ms.";
            
            // Define the header background color and email content
            $headerBgColor = "#512A72"; // Purple header background
            
            // Create appropriate message based on status
            if ($newStatus === 'Approved') {
                $statusMessage = "Congratulations! The status of your uploaded resource titled \"$resourceTitle\" has been approved and is now available in our resource library.";
                $additionalContent = '';
            } else if ($newStatus === 'Rejected' && $rejectionReason) {
                $statusMessage = "Thank you for contributing to our resource library. Unfortunately, the status of your uploaded resource titled \"$resourceTitle\" has been updated to: $newStatus.";
                $additionalContent = '
                    <div style="background-color: #F8F9FA; border: 1px solid #E5E5E5; border-radius: 4px; padding: 15px; margin: 20px 0;">
                        <p style="margin: 5px 0;"><strong>Reason for rejection:</strong> '.$rejectionReason.'</p>
                    </div>
                    <p>If you have any questions or would like to discuss this further, please don\'t hesitate to contact us.</p>
                ';
            } else {
                $statusMessage = "The status of your uploaded resource titled \"$resourceTitle\" has been updated to: $newStatus.";
                $additionalContent = '';
            }
            
            // Create HTML email template
            $message = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Resource Status Update</title>
            </head>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; color: #333333;">
                <div style="max-width: 600px; margin: 0 auto; border: 1px solid #E5E5E5; border-radius: 4px;">
                    <!-- Header -->
                    <div style="background-color: '.$headerBgColor.'; padding: 20px; text-align: center; color: white; border-radius: 4px 4px 0 0;">
                        <h1 style="margin: 0; font-size: 24px;">COACH Resource Update</h1>
                    </div>
                    
                    <!-- Content -->
                    <div style="background-color: white; padding: 20px 30px;">
                        <p>Dear '.$namePrefix.' '.$uploadedBy.',</p>
                        
                        <p>'.$statusMessage.'</p>
                        
                        '.$additionalContent.'
                        
                        <p>Best regards,<br>COACH Team</p>
                        
                        <p style="border-top: 1px solid #E5E5E5; margin-top: 20px; padding-top: 20px; font-size: 12px; color: #777777; text-align: center;">
                            © '.date("Y").' COACH. All rights reserved.
                        </p>
                    </div>
                </div>
            </body>
            </html>
            ';
            
            // Email headers for HTML email
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: COACH System <coach.hub2025@gmail.com>" . "\r\n";

            if (mail($email, $subject, $message, $headers)) {
                echo " | Email notification sent.";
                error_log("Email notification sent to: $email");
            } else {
                echo " | Failed to send email.";
                error_log("Failed to send email to: $email");
            }
        } else {
            echo " | Email not found for applicant.";
            error_log("Email not found for applicant: $applicantUsername");
        }
    } else {
        echo "No changes made (status may be the same or resource not found)";
        error_log("No changes made for Resource ID: $resourceID. Status may be the same or resource not found.");
    }
} else {
    http_response_code(500);
    echo "Failed to update status: " . $stmt->error;
    error_log("Failed to update status: " . $stmt->error);
}

$stmt->close();
$conn->close();
?>