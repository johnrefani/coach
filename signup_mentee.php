<?php
// Connect to your database
require 'connection/db_connection.php';

// Process AJAX request for username check
if (isset($_POST['check_username'])) {
    $username = $_POST['check_username'];
    
    // Prepare statement to prevent SQL injection
    // Query the unified 'users' table
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    // Return result as JSON and stop the script
    header('Content-Type: application/json');
    echo json_encode(['exists' => ($row['count'] > 0)]);
    exit;
}

// Check if the form was submitted for registration
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['check_username'])) {
    // Get and sanitize form data
    $firstName = trim($_POST['fname']);
    $lastName = trim($_POST['lname']);
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm-password'];
    $email = trim($_POST['email']);
    $contactNumber = $_POST['full-contact'];
    $fullAddress = trim($_POST['address']);
    $student = isset($_POST['student']) ? $_POST['student'] : '';
    $studentYearLevel = trim($_POST['grade']);
    $occupation = trim($_POST['occupation']);
    $toLearn = trim($_POST['learning']);
    $terms = isset($_POST['terms']) ? 1 : 0;
    $consent = isset($_POST['consent']) ? 1 : 0;
    
    // Define the user_type for a new mentee
    $userType = 'Mentee';

    // Validate passwords match
    if ($password !== $confirmPassword) {
        echo "<script>alert('Passwords do not match.'); window.history.back();</script>";
        exit();
    }

    // Hash the password for security
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert into the unified 'users' table
    $sql = "INSERT INTO users (first_name, last_name, dob, gender, username, password, email, contact_number, full_address, student, student_year_level, occupation, to_learn, user_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    // Bind all 14 parameters
    $stmt->bind_param("ssssssssssssss", $firstName, $lastName, $dob, $gender, $username, $hashedPassword, $email, $contactNumber, $fullAddress, $student, $studentYearLevel, $occupation, $toLearn, $userType);

    if ($stmt->execute()) {
        echo "<script>
            alert('Registration successful! You will now be redirected to the login page.');
            window.location.href = 'login.php';
        </script>";
    } else {
        // Provide a more specific error for debugging if a username already exists
        if ($conn->errno == 1062) { // 1062 is the MySQL error number for a duplicate entry
            echo "<script>alert('Error: This username or email is already taken.'); window.history.back();</script>";
        } else {
            echo "Error: " . $stmt->error;
        }
    }

    $stmt->close();
    $conn->close();
    exit; // Stop the script after processing the form
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Signup Page</title>
    <link rel="stylesheet" href="css/signupstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
</head>
<body>
    <video autoplay muted loop id="bg-video">
        <source src="img/bgcode.mp4" type="video/mp4">
        Your browser does not support HTML5 video.
    </video>

    <div class="page-content">
        <section class="welcomeb">
            <div class="welcome-container">
                <div class="welcome-box">
                    <h3 class="typing-gradient">
                        <span class="typed-text">Welcome, Mentee! Start your path.</span>
                    </h3>
                </div>
            </div>
        </section>

        <div class="container">
            <h1>SIGN UP</h1>
            <p>Register now and unlock new opportunities.</p>
            <form action="" method="POST">
                <div class="top-section">
                    <div class="form-box">
                        <h2>Personal Information</h2>
                        <label for="fname">First Name</label>
                        <input type="text" id="fname" name="fname" placeholder="First Name" required>
                        <label for="lname">Last Name</label>
                        <input type="text" id="lname" name="lname" placeholder="Last Name" required>
                        <label for="dob">Date of Birth</label>
                        <input type="date" id="dob" name="dob" required>
                        <span id="dob-error" class="error-message"></span>
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender" required>
                            <option value="" disabled selected>Select Gender</option>
                            <option>Male</option>
                            <option>Female</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-box">
                        <h2>Username and Password</h2>
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" placeholder="Username" required>
                        <label for="password">Password</label>
                        <div class="password-field-container">
                            <input type="password" id="password" name="password" placeholder="Password" required>
                            <div id="password-popup" class="password-popup">
                                <p>Password must:</p>
                                <ul>
                                    <li id="length-check">Be at least 8 characters long</li>
                                    <li id="uppercase-check">Include at least one uppercase letter</li>
                                    <li id="lowercase-check">Include at least one lowercase letter</li>
                                    <li id="number-check">Include at least one number</li>
                                    <li id="special-check">Include at least one special character (!@#$%^&*)</li>
                                </ul>
                            </div>
                        </div>
                        <span id="password-error" class="error-message"></span>
                        <label for="confirm-password">Confirm Password</label>
                        <input type="password" id="confirm-password" name="confirm-password" placeholder="Confirm Password" required>
                        <span id="confirm-password-error" class="error-message"></span>
                    </div>
                </div>
                <div class="form-box full-width">
                    <h2>Contact Information</h2>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="youremail@gmail.com" required>
                    <label for="contact">Contact Number</label>
                    <div class="phone-input-container">
                        <input type="tel" id="contact" name="contact" placeholder="9XXXXXXXXX" required pattern="9[0-9]{9}" title="Please enter a valid Philippine mobile number starting with 9 followed by 9 digits">
                        <input type="hidden" id="full-contact" name="full-contact">
                    </div>
                    <label for="address">Full Address</label>
                    <input type="text" id="address" name="address" placeholder="Street, City, Province, ZIP" required>
                    <label>Are you still a student?</label>
                    <div class="student-options">
                        <input type="radio" id="student-yes" name="student" value="yes" required>
                        <label for="student-yes">Yes</label>
                        <input type="radio" id="student-no" name="student" value="no">
                        <label for="student-no">No</label>
                    </div>
                    <label for="grade">If yes, what grade are you?</label>
                    <input type="text" id="grade" name="grade" placeholder="e.g. Grade 12">
                    <label for="occupation">If not, what is your occupation?</label>
                    <input type="text" id="occupation" name="occupation" placeholder="e.g. IT Specialist">
                    <label for="learning">What do you want to learn with Coach?</label>
                    <textarea id="learning" name="learning" rows="3" placeholder="Type your answer here..." required></textarea>
                </div>
                <div class="terms-container">
                    <label>
                        <input type="checkbox" id="terms" required>
                        <span>
                            I agree to the <a href="#">Terms & Conditions</a> and <a href="#">Data Privacy Policy</a>.
                        </span>
                    </label>
                    <label>
                        <input type="checkbox" id="consent" required>
                        <span>
                            I consent to receive updates and communications from COACH, trusting that all shared information will be used responsibly to support my growth and development. I understand that COACH values my privacy and that I can opt out of communications at any time.
                        </span>
                    </label>
                </div>
                <div class="form-buttons">
                    <button type="button" class="cancel-btn"><a href="login_mentee.php" style="color: #290c26;">Cancel</a></button>
                    <button type="submit" class="submit-btn">Register</button>
                </div>
            </form>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // DOM elements for DOB validation
                const dobInput = document.getElementById('dob');
                const dobError = document.getElementById('dob-error');

                // DOM elements for password validation
                const passwordInput = document.getElementById('password');
                const confirmPasswordInput = document.getElementById('confirm-password');
                const passwordError = document.getElementById('password-error');
                const confirmPasswordError = document.getElementById('confirm-password-error');
                const passwordPopup = document.getElementById('password-popup');

                // DOM elements for username validation
                const usernameInput = document.getElementById('username');
                const usernameError = document.createElement('span');
                usernameError.id = 'username-error';
                usernameError.className = 'error-message';
                usernameInput.parentNode.insertBefore(usernameError, usernameInput.nextSibling);

                // Password requirement checkers
                const lengthCheck = document.getElementById('length-check');
                const uppercaseCheck = document.getElementById('uppercase-check');
                const lowercaseCheck = document.getElementById('lowercase-check');
                const numberCheck = document.getElementById('number-check');
                const specialCheck = document.getElementById('special-check');

                const form = document.querySelector('form');

                // Username validation
                let usernameTimer;
                let isUsernameValid = true;

                function checkUsername() {
                    const username = usernameInput.value.trim();

                    if (username.length < 3) {
                        usernameError.textContent = "Username must be at least 3 characters.";
                        usernameError.style.color = "#f44336";
                        usernameInput.classList.add('invalid-input');
                        usernameInput.classList.remove('valid-input');
                        isUsernameValid = false;
                        return;
                    }

                    // Show loading indicator
                    usernameError.textContent = "Checking username...";
                    usernameError.style.color = "#2196F3";

                    // Create form data
                    const formData = new FormData();
                    formData.append('check_username', username);

                    // Send AJAX request to the same page by using an empty string for the URL
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! Status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.exists) {
                            usernameError.textContent = "This username is already taken.";
                            usernameError.style.color = "#f44336";
                            usernameInput.classList.add('invalid-input');
                            usernameInput.classList.remove('valid-input');
                            isUsernameValid = false;
                        } else {
                            usernameError.textContent = "Username is available!";
                            usernameError.style.color = "#4CAF50";
                            usernameInput.classList.add('valid-input');
                            usernameInput.classList.remove('invalid-input');
                            isUsernameValid = true;
                        }
                    })
                    .catch(error => {
                        usernameError.textContent = "Error checking username. Please try again.";
                        usernameError.style.color = "#f44336";
                        console.error('Error:', error);
                        isUsernameValid = false;
                    });
                }

                usernameInput.addEventListener('input', function() {
                    // Clear any existing error message while typing
                    if (this.value.trim().length === 0) {
                        usernameError.textContent = "";
                        usernameInput.classList.remove('valid-input', 'invalid-input');
                    }
                    // Clear the previous timer
                    clearTimeout(usernameTimer);
                    // Set a new timer to check username after typing stops for 500ms
                    usernameTimer = setTimeout(checkUsername, 500);
                });

                // Calculate dates for DOB validation
                const today = new Date();
                const minAllowedYear = today.getFullYear() - 100; // Minimum year (100 years ago)
                const maxAllowedYear = today.getFullYear() - 10; // Maximum year (10 years ago)

                // Set the last day of the latest year they can select
                const maxDate = new Date(maxAllowedYear, 11, 31).toISOString().split('T')[0]; // December 31 of max year
                const minDate = new Date(minAllowedYear, 0, 1).toISOString().split('T')[0]; // January 1 of min year

                // Set attributes for the date input
                dobInput.setAttribute('max', maxDate);
                dobInput.setAttribute('min', minDate);

                // Date of birth validation function
                function validateDOB() {
                    const selectedDate = new Date(dobInput.value);
                    const today = new Date();
                    const selectedYear = selectedDate.getFullYear();
                    const currentYear = today.getFullYear();
                    if (selectedDate > today) {
                        dobError.textContent = "Date of birth cannot be in the future.";
                        return false;
                    } else if (selectedYear > currentYear - 10) {
                        dobError.textContent = "You must be at least 10 years old to register.";
                        return false;
                    } else {
                        dobError.textContent = "";
                        return true;
                    }
                }

                function validatePassword() {
                    const password = passwordInput.value;
                    let isValid = true;
                    const hasValidLength = password.length >= 8;
                    lengthCheck.className = hasValidLength ? 'valid' : 'invalid';
                    isValid = isValid && hasValidLength;
                    const hasUppercase = /[A-Z]/.test(password);
                    uppercaseCheck.className = hasUppercase ? 'valid' : 'invalid';
                    isValid = isValid && hasUppercase;
                    const hasLowercase = /[a-z]/.test(password);
                    lowercaseCheck.className = hasLowercase ? 'valid' : 'invalid';
                    isValid = isValid && hasLowercase;
                    const hasNumber = /[0-9]/.test(password);
                    numberCheck.className = hasNumber ? 'valid' : 'invalid';
                    isValid = isValid && hasNumber;
                    const hasSpecial = /[!@#$%^&*]/.test(password);
                    specialCheck.className = hasSpecial ? 'valid' : 'invalid';
                    isValid = isValid && hasSpecial;
                    if (!isValid && password.length > 0) {
                        passwordError.textContent = "Please meet all password requirements.";
                    } else {
                        passwordError.textContent = "";
                    }
                    return isValid;
                }

                function validateConfirmPassword() {
                    const password = passwordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    if (confirmPassword.length > 0 && password !== confirmPassword) {
                        confirmPasswordError.textContent = "Passwords do not match.";
                        return false;
                    } else {
                        confirmPasswordError.textContent = "";
                        return true;
                    }
                }
                
                function showPasswordPopup() {
                    passwordPopup.style.display = 'block';
                }

                function hidePasswordPopup() {
                    if (validatePassword() || passwordInput.value.length === 0) {
                        passwordPopup.style.display = 'none';
                    }
                }
                
                dobInput.addEventListener('change', validateDOB);
                dobInput.addEventListener('blur', validateDOB);
                passwordInput.addEventListener('focus', showPasswordPopup);
                passwordInput.addEventListener('keyup', validatePassword);
                passwordInput.addEventListener('blur', hidePasswordPopup);
                confirmPasswordInput.addEventListener('keyup', validateConfirmPassword);
                confirmPasswordInput.addEventListener('blur', validateConfirmPassword);
                
                form.addEventListener('submit', function(event) {
                    const isDOBValid = validateDOB();
                    const isPasswordValid = validatePassword();
                    const isConfirmPasswordValid = validateConfirmPassword();
                    if (!isDOBValid || !isPasswordValid || !isConfirmPasswordValid || !isUsernameValid) {
                        event.preventDefault();
                        if (!isUsernameValid) {
                            usernameInput.focus();
                            if (usernameError.textContent === "") {
                                usernameError.textContent = "Please enter a valid username.";
                            }
                        }
                    }
                });

                // Phone number handling
                const contactInput = document.getElementById('contact');
                const fullContactInput = document.getElementById('full-contact');
                
                form.addEventListener('submit', function(event) {
                    const phoneNumber = contactInput.value.trim();
                    if (phoneNumber) {
                        fullContactInput.value = '+63' + phoneNumber;
                    }
                });
                
                contactInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '');
                    if (this.value.startsWith('0')) {
                        this.value = this.value.substring(1);
                    }
                    if (this.value.length > 10) {
                        this.value = this.value.slice(0, 10);
                    }
                });

                // Progressive field enabling logic
                const fieldSequence = ['fname', 'lname', 'dob', 'gender', 'username', 'password', 'confirm-password', 'email', 'contact', 'address', 'learning'];
                const formFields = {};
                fieldSequence.forEach(id => formFields[id] = document.getElementById(id));
                const studentYes = document.getElementById('student-yes');
                const studentNo = document.getElementById('student-no');
                const gradeField = document.getElementById('grade');
                const occupationField = document.getElementById('occupation');

                function isFieldValid(field) {
                    if (!field) return false;
                    if (field.tagName === 'SELECT') return field.value !== '' && !field.disabled;
                    if (field.type === 'radio') {
                        const name = field.name;
                        const radioGroup = document.querySelectorAll(`input[name="${name}"]`);
                        return Array.from(radioGroup).some(radio => radio.checked);
                    }
                    return field.value.trim() !== '' && !field.disabled;
                }

                function updateFieldAvailability() {
                    let allPreviousValid = true;
                    for (let i = 0; i < fieldSequence.length; i++) {
                        const fieldId = fieldSequence[i];
                        const field = formFields[fieldId];
                        if (!field) continue;
                        field.disabled = !allPreviousValid;
                        if (i < fieldSequence.length - 1) {
                            allPreviousValid = allPreviousValid && isFieldValid(field);
                        }
                    }
                    if (studentYes && studentNo && gradeField && occupationField) {
                        const addressField = formFields['address'];
                        const canSelectStudentStatus = addressField && isFieldValid(addressField);
                        studentYes.disabled = !canSelectStudentStatus;
                        studentNo.disabled = !canSelectStudentStatus;
                        gradeField.disabled = !(canSelectStudentStatus && studentYes.checked);
                        occupationField.disabled = !(canSelectStudentStatus && studentNo.checked);
                    }
                }
                
                fieldSequence.forEach(id => {
                    const field = formFields[id];
                    if (field) {
                        field.addEventListener('input', updateFieldAvailability);
                        field.addEventListener('change', updateFieldAvailability);
                    }
                });
                
                if (studentYes && studentNo) {
                    studentYes.addEventListener('change', updateFieldAvailability);
                    studentNo.addEventListener('change', updateFieldAvailability);
                }
                
                updateFieldAvailability();
                
                const style = document.createElement('style');
                style.textContent = `
                    input:disabled, select:disabled, textarea:disabled {
                        background-color: #f8f8f8; cursor: not-allowed; opacity: 0.7;
                    }
                    input:disabled::placeholder, textarea:disabled::placeholder {
                        color: #ccc;
                    }`;
                document.head.appendChild(style);
            });
        </script>
</body>
</html>