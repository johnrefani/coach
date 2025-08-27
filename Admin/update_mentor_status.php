<?php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "coach";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) die("Connection failed");

$id = $_POST['id'];
$status = $_POST['status'];
$reason = isset($_POST['reason']) ? $_POST['reason'] : null;

// Fetch mentor's email, first name, last name, and gender based on the Mentor_ID
$query = $conn->prepare("SELECT Email, First_Name, Last_Name, Gender FROM applications WHERE Mentor_ID = ?");
$query->bind_param("i", $id);
$query->execute();
$query->bind_result($mentor_email, $firstName, $lastName, $gender);
$query->fetch();
$query->close();

// For rejected applications, add the reason to the database
if ($status === "Rejected" && $reason !== null) {
    $stmt = $conn->prepare("UPDATE applications SET Status = ?, Reason = ? WHERE Mentor_ID = ?");
    $stmt->bind_param("ssi", $status, $reason, $id);
} else {
    $stmt = $conn->prepare("UPDATE applications SET Status = ? WHERE Mentor_ID = ?");
    $stmt->bind_param("si", $status, $id);
}

if ($stmt->execute()) {
    echo "Status updated successfully.";

    // Compose full name with appropriate prefix
    $prefix = strtolower($gender) === 'female' ? 'Ms.' : 'Mr.';
    $fullName = "$prefix $firstName $lastName";

    // Send email notification
    $subject = "Your COACH Mentor Application Status";
    
    // Define email template colors and content
    $headerBgColor = "#512A72"; // Purple header background (from screenshot)
    $boxBorderColor = "#E5E5E5"; // Light gray border
    $textColor = "#333333"; // Dark gray for main text
    
    // Content based on status
    if ($status === "Rejected" && $reason !== null) {
        $statusMessage = "Your mentor application has been reviewed and unfortunately has been $status.";
        $additionalContent = '
            <div style="background-color: #F8F9FA; border: 1px solid #E5E5E5; border-radius: 4px; padding: 15px; margin: 20px 0;">
                <p style="margin: 5px 0;"><strong>Reason:</strong> '.$reason.'</p>
            </div>
            <p>Thank you for your interest in our mentorship program.</p>
        ';
    } else if ($status === "Approved") {
        $statusMessage = "Congratulations! Your mentor application has been $status.";
        $additionalContent = '
            <p>We are excited to welcome you to our team of mentors!</p>
        ';
    } else {
        $statusMessage = "Your mentor application status has been updated to: $status.";
        $additionalContent = '';
    }
    
    // HTML email template
    $message = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mentor Application Status</title>
    </head>
    <body style="font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; color: #333333;">
        <div style="max-width: 600px; margin: 0 auto; border: 1px solid #E5E5E5; border-radius: 4px;">
            <!-- Header -->
            <div style="background-color: '.$headerBgColor.'; padding: 20px; text-align: center; color: white; border-radius: 4px 4px 0 0;">
                <h1 style="margin: 0; font-size: 24px;">Welcome to COACH Mentor Panel</h1>
            </div>
            
            <!-- Content -->
            <div style="background-color: white; padding: 20px 30px;">
                <p>Dear '.$fullName.',</p>
                
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

    // Send the email
    if (mail($mentor_email, $subject, $message, $headers)) {
        echo " Email notification sent to mentor.";
    } else {
        echo " Failed to send email notification.";
    }
} else {
    echo "Failed to update status.";
}

$stmt->close();
$conn->close();
?>