<?php
// signup_mentor.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start(); // Start session to potentially use for future improvements, though direct POST to assessment is used here

require 'connection/db_connection.php';

// Check username availability (AJAX request)
if (isset($_POST['check_username'])) {
    $username = $_POST['check_username'];
    
    // Updated query to check the unified 'users' table.
    // Using a prepared statement for better security.
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    echo json_encode(['exists' => $row['count'] > 0]);
    
    $stmt->close();
    $conn->close(); // Close connection for AJAX request
    exit;
}

// Fetch courses from database for the dropdown on page load
$courses = [];
$result = $conn->query("SELECT DISTINCT Course_Title FROM mentors_assessment");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row['Course_Title'];
    }
    $result->free(); // Free result set
} else {
    // Handle error if query fails
    error_log("Error fetching courses: " . $conn->error);
}

$conn->close(); // Close connection after fetching courses
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mentor Sign Up</title>
    <link rel="stylesheet" href="css/signupstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <style>
        .password-field-container {
            position: relative;
            width: 100%;
        }
        .password-popup {
            display: none; /* Hidden by default */
            position: absolute;
            right: 0;
            top: 100%; /* Position below the password field */
            margin-top: 5px;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 5px;
            padding: 15px;
            width: 100%; /* Full width of parent container */
            max-width: 300px;
            color: #fff;
            z-index: 100;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .password-popup p {
            margin-top: 0;
            margin-bottom: 8px;
            font-weight: bold;
        }
        .password-popup ul {
            margin: 0;
            padding-left: 20px;
        }
        .password-popup li {
            margin-bottom: 5px;
            transition: color 0.3s;
        }
        .password-popup li.valid {
            color: #4CAF50;
        }
        .password-popup li.invalid {
            color: #f44336;
        }
        .valid-input {
            border: 1px solid #4CAF50 !important;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.5) !important;
        }
        .invalid-input {
            border: 1px solid #f44336 !important;
            box-shadow: 0 0 5px rgba(244, 67, 54, 0.5) !important;
        }

        .phone-input-container {
            position: relative;
            width: 100%;
        }

        .phone-input-container input[type="tel"] {
            padding-left: 45px; /* Make space for the prefix */
            width: 100%;
        }

        .phone-input-container::before {
            content: "+63";
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: bold;
            color: #333;
            pointer-events: none; /* Makes the pseudo-element unclickable */
        }
    </style>
</head>
<body>
    

    <div class="page-content">
        <section class="welcomeb">
            <div class="welcome-container">
                <div class="welcome-box">
                    <h3 class="typing-gradient">
                       <span class="typed-text">Hi Mentor! Time to inspire.</span>
                    </h3>
                </div>
            </div>
        </section>

        <div class="container">
              <h1>SIGN UP</h1>
                <p>Join as a mentor and share your expertise.</p>
            <form action="mentor_assessment.php" method="POST" enctype="multipart/form-data" id="signupForm">
                <div class="top-section">
                    <div class="form-box">
                        <h2>Personal Information</h2>
                        <label for="fname">First Name</label>
                        <input type="text" id="fname" name="fname" placeholder="First Name" required>

                        <label for="lname">Last Name</label>
                        <input type="text" id="lname" name="lname" placeholder="Last Name" disabled required>

                        <label for="birthdate">Date of Birth</label>
                        <input type="date" id="birthdate" name="birthdate" disabled required>
                        <span id="dob-error" class="error-message"></span>

                        <label for="gender">Gender</label>
                        <select id="gender" name="gender" disabled required>
                            <option value="" disabled selected>Select Gender</option>
                            <option>Male</option>
                            <option>Female</option>
                            <option>Other</option>
                        </select>

                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="Email" disabled required>
                        <span id="email-error" class="error-message"></span>


                        <label for="contact">Contact Number</label>
                        <div class="phone-input-container">
                            <input type="tel" id="contact" name="contact" placeholder="9XXXXXXXXX" disabled required>
                            <span id="phone-error" class="error-message"></span>
                            <input type="hidden" id="full-contact" name="full-contact">
                        </div>
                    </div>

                    <div class="form-box">
                        <h2>Username and Password</h2>
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" placeholder="Username" disabled required>
                        <span id="username-error" class="error-message"></span>

                        <label for="password">Password</label>
                        <div class="password-field-container">
                            <input type="password" id="password" name="password" placeholder="Password" disabled required>
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
                        <input type="password" id="confirm-password" name="confirm-password" placeholder="Confirm Password" disabled required>
                        <span id="confirm-password-error" class="error-message"></span>
                    </div>
                </div>

                <div class="form-box full-width">
                    <h2>Mentoring Information</h2>

                    <label>Have you mentored or taught before?</label>
                    <div class="student-options">
                        <input type="radio" id="mentored-yes" name="mentored" value="yes" disabled required>
                        <label for="mentored-yes">Yes</label>
                        <input type="radio" id="mentored-no" name="mentored" value="no">
                        <label for="mentored-no">No</label>
                    </div>

                    <label for="experience">If yes, please describe your experience</label>
                    <textarea id="experience" name="experience" rows="3" placeholder="Share your mentoring or teaching background..."></textarea>

                    <label for="expertise">Area of Expertise</label>
                    <select id="expertise" name="expertise" disabled required>
                        <option value="" disabled selected>-- Select Course --</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="resume">Upload Resume</label>
                    <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx" disabled required>

                    <label for="certificates">Upload Certificates</label>
                    <input type="file" id="certificates" name="certificates[]" accept=".pdf,.jpg,.png,.doc,.docx" multiple disabled required>
                </div>

                <div class="terms-container">
                    <label>
                        <input type="checkbox" id="terms" disabled required>
                        <span>I agree to the <a href="#">Terms & Conditions</a> and <a href="#">Data Privacy Policy</a>.</span>
                    </label>
                    <label>
                        <input type="checkbox" id="consent" disabled required>
                        <span>I consent to receive updates and communications from COACH, trusting that all shared information will be used responsibly to support my growth and development. I understand that COACH values my privacy and that I can opt out of communications at any time.</span>
                    </label>
                </div>

                <div class="form-buttons">
                    <button type="button" class="cancel-btn"><a href="login_mentor.php" style="color: #290c26;">Cancel</a></button>
                    <button type="submit" class="submit-btn">Submit</button> </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // DOM elements for DOB validation
        const dobInput = document.getElementById('birthdate');
        const dobError = document.getElementById('dob-error');

        // DOM elements for password validation
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm-password');
        const passwordError = document.getElementById('password-error');
        const confirmPasswordError = document.getElementById('confirm-password-error');
        const passwordPopup = document.getElementById('password-popup');

        // DOM elements for username validation
        const usernameInput = document.getElementById('username');
        const usernameError = document.getElementById('username-error');

        // DOM elements for phone validation
        const contactInput = document.getElementById('contact');
        const fullContactInput = document.getElementById('full-contact');
        const phoneError = document.getElementById('phone-error');


        // Password requirement checkers
        const lengthCheck = document.getElementById('length-check');
        const uppercaseCheck = document.getElementById('uppercase-check');
        const lowercaseCheck = document.getElementById('lowercase-check');
        const numberCheck = document.getElementById('number-check');
        const specialCheck = document.getElementById('special-check');

        const form = document.getElementById('signupForm'); // Get the form by its ID

        // Make sure the popup is hidden initially
        passwordPopup.style.display = 'none';

        // Username validation
        let usernameTimer;
        let isUsernameValid = false; // Track username validity for form submission

        function checkUsername() {
            const username = usernameInput.value.trim();

            if (username.length === 0) {
                 usernameError.textContent = "Username is required.";
                 usernameError.style.color = "#f44336";
                 usernameInput.classList.remove('valid-input');
                 usernameInput.classList.add('invalid-input');
                 isUsernameValid = false;
                 return;
            }

            if (username.length < 3) {
                usernameError.textContent = "Username must be at least 3 characters.";
                usernameError.style.color = "#f44336";
                usernameInput.classList.remove('valid-input');
                usernameInput.classList.add('invalid-input');
                isUsernameValid = false;
                return;
            }

            // Show loading indicator
            usernameError.textContent = "Checking username...";
            usernameError.style.color = "#2196F3";
            usernameInput.classList.remove('valid-input', 'invalid-input'); // Remove previous classes

            // Create form data
            const formData = new FormData();
            formData.append('check_username', username);

            // Send AJAX request to the same page (signup_mentor.php)
            fetch('signup_mentor.php', {
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
                    usernameInput.classList.remove('valid-input');
                    usernameInput.classList.add('invalid-input');
                    isUsernameValid = false;
                } else {
                    usernameError.textContent = "Username is available!";
                    usernameError.style.color = "#4CAF50";
                    usernameInput.classList.remove('invalid-input');
                    usernameInput.classList.add('valid-input');
                    isUsernameValid = true;
                }
            })
            .catch(error => {
                usernameError.textContent = "Error checking username. Please try again.";
                usernameError.style.color = "#f44336";
                console.error('Error:', error);
                usernameInput.classList.remove('valid-input');
                usernameInput.classList.add('invalid-input');
                isUsernameValid = false;
            });
        }

        usernameInput.addEventListener('input', function() {
            // Clear the previous timer
            clearTimeout(usernameTimer);

            // Set a new timer to check username after typing stops for 500ms
            // or check immediately if input is empty
            if (this.value.trim().length > 0) {
                 usernameTimer = setTimeout(checkUsername, 500);
            } else {
                 checkUsername(); // Check immediately if field is cleared
            }
        });
         // Also check on blur in case user types quickly and leaves
         usernameInput.addEventListener('blur', checkUsername);


        // Calculate dates for DOB validation
        const today = new Date();
        const minAllowedYear = today.getFullYear() - 100; // Minimum year (100 years ago)
        const maxAllowedYear = today.getFullYear() - 18;  // Maximum year (18 years ago for mentors)

        // Set the last day of the latest year they can select
        const maxDate = new Date(maxAllowedYear, 11, 31).toISOString().split('T')[0]; // December 31 of max year
        const minDate = new Date(minAllowedYear, 0, 1).toISOString().split('T')[0];   // January 1 of min year

        // Set attributes for the date input
        dobInput.setAttribute('max', maxDate);
        dobInput.setAttribute('min', minDate);

        // Date of birth validation function
        function validateDOB() {
            if (!dobInput.value) {
                 dobError.textContent = "Date of birth is required.";
                 return false;
            }
            const selectedDate = new Date(dobInput.value);
            const today = new Date();

            const selectedYear = selectedDate.getFullYear();
            const currentYear = today.getFullYear();

            // Future date check
            if (selectedDate > today) {
                dobError.textContent = "Date of birth cannot be in the future.";
                return false;
            }
            // Calculate the minimum required birth year (mentors should be at least 18)
            else if (selectedYear > currentYear - 18) {
                dobError.textContent = "You must be at least 18 years old to register as a mentor.";
                return false;
            } else {
                dobError.textContent = "";
                return true;
            }
        }

       function validatePassword() {
    const password = passwordInput.value;
    let isValid = true;

    // Rules
    const rules = [
        { test: password.length >= 8, element: lengthCheck },
        { test: /[A-Z]/.test(password), element: uppercaseCheck },
        { test: /[a-z]/.test(password), element: lowercaseCheck },
        { test: /[0-9]/.test(password), element: numberCheck },
        { test: /[!@#$%^&*]/.test(password), element: specialCheck }
    ];

    // Apply validation checks
    rules.forEach(rule => {
        if (rule.test) {
            rule.element.className = 'valid';
        } else {
            rule.element.className = 'invalid';
            isValid = false;
        }
    });

    // Handle error messages and styling
    if (!isValid && password.length > 0) {
        passwordError.textContent = "Please meet all password requirements.";
        passwordInput.classList.remove('valid-input');
        passwordInput.classList.add('invalid-input');
    } else {
        passwordError.textContent = "";
        if (password.length > 0 && isValid) {
            passwordInput.classList.remove('invalid-input');
            passwordInput.classList.add('valid-input');
        } else {
            passwordInput.classList.remove('valid-input', 'invalid-input');
        }
    }

    return isValid;
}
        // Confirm password validation function
        function validateConfirmPassword() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (confirmPassword.length === 0 && password.length > 0) {
                 confirmPasswordError.textContent = "Please confirm your password.";
                 confirmPasswordInput.classList.remove('valid-input');
                 confirmPasswordInput.classList.add('invalid-input');
                 return false;
            } else if (confirmPassword.length > 0 && password !== confirmPassword) {
                confirmPasswordError.textContent = "Passwords do not match.";
                 confirmPasswordInput.classList.remove('valid-input');
                 confirmPasswordInput.classList.add('invalid-input');
                return false;
            } else {
                confirmPasswordError.textContent = "";
                 if (confirmPassword.length > 0 && password === confirmPassword) {
                     confirmPasswordInput.classList.remove('invalid-input');
                     confirmPasswordInput.classList.add('valid-input');
                 } else {
                      confirmPasswordInput.classList.remove('valid-input', 'invalid-input');
                 }
                return true;
            }
        }

        // Show password popup when the password field gets focus
        passwordInput.addEventListener('focus', function() {
            passwordPopup.style.display = 'block';
        });

        // Real-time password validation
        passwordInput.addEventListener('input', validatePassword);

        // Hide password popup when focus leaves the password field
        passwordInput.addEventListener('blur', function() {
            // Hide the popup when focus is lost
            passwordPopup.style.display = 'none';
            validatePassword(); // Validate on blur too
        });

        // Add event listeners for DOB
        dobInput.addEventListener('change', validateDOB);
        dobInput.addEventListener('blur', validateDOB);

        // Add event listeners for confirm password
        confirmPasswordInput.addEventListener('input', validateConfirmPassword);
        confirmPasswordInput.addEventListener('blur', validateConfirmPassword);


        // Phone number validation for Philippine numbers
        contactInput.addEventListener('input', function(e) {
            // Get the input value
            let inputValue = e.target.value;

            // Remove any non-digit characters except the leading 9
            inputValue = inputValue.replace(/[^0-9]/g, '');

            // Ensure it starts with 9 if not empty
            if (inputValue.length > 0 && !inputValue.startsWith('9')) {
                 inputValue = '9' + inputValue.substring(1); // Auto-correct if first digit is not 9
            }

            // Limit to 9 digits
            if (inputValue.length > 9) {
                inputValue = inputValue.slice(0, 9);
            }

            // Update the input field with cleaned value
            e.target.value = inputValue;

            // Also update the hidden field with the full number including +63
            if (inputValue.length > 0) {
                fullContactInput.value = '+63' + inputValue;
            } else {
                fullContactInput.value = '';
            }

            // Validate the phone number
            validatePhoneNumber(inputValue);
        });

        contactInput.addEventListener('blur', function() {
             validatePhoneNumber(this.value); // Validate on blur
        });


        function validatePhoneNumber(number) {
            // Philippine mobile numbers should be 9 digits after the +63
            // The first digit should be 9 for mobile numbers
            const isValid = number.length === 9 && number.startsWith('9');

            if (number.length === 0) {
                // Empty field
                phoneError.textContent = 'Phone number is required.';
                contactInput.classList.remove('valid-input');
                contactInput.classList.add('invalid-input');
                return false;
            } else if (!isValid) {
                 phoneError.textContent = 'Must be a valid 9-digit Philippine mobile number (starts with 9).';
                 contactInput.classList.remove('valid-input');
                 contactInput.classList.add('invalid-input');
                return false;
            } else {
                // Valid phone number
                phoneError.textContent = '';
                contactInput.classList.remove('invalid-input');
                contactInput.classList.add('valid-input');
                return true;
            }
        }


        // Field progression logic
        const fieldOrder = [
            'fname',
            'lname',
            'birthdate',
            'gender',
            'email',
            'contact', // Use 'contact' as it's the visible input
            'username',
            'password',
            'confirm-password',
            'mentored-yes', // radio - group name is 'mentored'
            'experience',
            'expertise',
            'resume',
            'certificates',
            'terms', // checkbox
            'consent' // checkbox
        ];

         // Function to enable the next field(s)
         function enableNextField(currentElementId) {
             const currentIndex = fieldOrder.indexOf(currentElementId);
             if (currentIndex === -1) return;

             const nextFieldId = fieldOrder[currentIndex + 1];
             if (!nextFieldId) return; // No next field

             // Handle radio buttons ('mentored')
             if (nextFieldId === 'mentored-yes') {
                  document.getElementsByName('mentored').forEach(el => el.disabled = false);
                 return; // Handled the radio group, stop here
             }
             // Handle checkboxes ('terms', 'consent')
             if (nextFieldId === 'terms') {
                  const termsCheckbox = document.getElementById('terms');
                  if (termsCheckbox) termsCheckbox.disabled = false;
                 return; // Handled the checkbox, stop here
             }
             if (nextFieldId === 'consent') {
                   // 'terms' must be checked to enable 'consent'
                   const termsCheckbox = document.getElementById('terms');
                   if (termsCheckbox && termsCheckbox.checked) {
                      const consentCheckbox = document.getElementById('consent');
                       if (consentCheckbox) consentCheckbox.disabled = false;
                   }
                   return; // Handled the checkbox, stop here
              }

             const nextField = document.getElementById(nextFieldId);
             if (nextField) {
                 nextField.disabled = false;
             }
         }


        fieldOrder.forEach((id) => {
            const currentField = document.getElementById(id);
            if (!currentField) return; // Skip if element not found

            // Add event listeners based on field type
            if (currentField.type === 'radio') {
                 // Listen to 'change' on radio buttons to enable the next field(s)
                 document.getElementsByName(currentField.name).forEach(radio => {
                     radio.addEventListener('change', function() {
                         if (this.checked) {
                             enableNextField(currentField.id); // Use the ID of the clicked radio
                              // Special handling for 'mentored' radio buttons to enable 'experience' and 'expertise'
                             if (currentField.name === 'mentored') {
                                 const experienceField = document.getElementById('experience');
                                 const expertiseField = document.getElementById('expertise');
                                 if (experienceField) experienceField.disabled = false;
                                 if (expertiseField) expertiseField.disabled = false;
                             }
                         }
                     });
                 });
            } else if (currentField.type === 'checkbox') {
                 // Listen to 'change' on checkboxes to enable the next field(s)
                 currentField.addEventListener('change', function() {
                     if (this.checked) {
                         enableNextField(currentField.id);
                          // Special handling for 'consent' checkbox to enable the submit button
                         if (currentField.id === 'consent') {
                              const submitButton = form.querySelector('.submit-btn');
                              if (submitButton) submitButton.disabled = !this.checked;
                         }
                     } else {
                          // If unchecked, disable subsequent fields that depend on it
                          const currentIndex = fieldOrder.indexOf(currentField.id);
                          if (currentIndex !== -1) {
                              for (let i = currentIndex + 1; i < fieldOrder.length; i++) {
                                  const dependentField = document.getElementById(fieldOrder[i]);
                                   if (dependentField) {
                                        if (dependentField.type === 'radio') {
                                             document.getElementsByName(dependentField.name).forEach(el => {
                                                 el.disabled = true;
                                                 el.checked = false; // Uncheck radio buttons
                                             });
                                         } else if (dependentField.type === 'checkbox') {
                                             dependentField.disabled = true;
                                             dependentField.checked = false; // Uncheck checkboxes
                                             if (dependentField.id === 'consent') { // Disable submit if consent unchecked
                                                 const submitButton = form.querySelector('.submit-btn');
                                                 if (submitButton) submitButton.disabled = true;
                                             }
                                         }
                                        else {
                                             dependentField.disabled = true;
                                             dependentField.value = ''; // Clear input fields
                                         }
                                   }
                              }
                          }
                     }
                 });
            }
             else if (currentField.type === 'file') {
                  // Listen to 'change' on file inputs
                 currentField.addEventListener('change', function() {
                     if (this.files.length > 0) {
                         enableNextField(currentField.id);
                     } else {
                          // If file is deselected, disable subsequent fields
                           const currentIndex = fieldOrder.indexOf(currentField.id);
                           if (currentIndex !== -1) {
                               for (let i = currentIndex + 1; i < fieldOrder.length; i++) {
                                   const dependentField = document.getElementById(fieldOrder[i]);
                                   if (dependentField) {
                                        if (dependentField.type === 'radio') {
                                             document.getElementsByName(dependentField.name).forEach(el => {
                                                 el.disabled = true;
                                                 el.checked = false;
                                             });
                                         } else if (dependentField.type === 'checkbox') {
                                             dependentField.disabled = true;
                                             dependentField.checked = false;
                                              if (dependentField.id === 'consent') {
                                                 const submitButton = form.querySelector('.submit-btn');
                                                 if (submitButton) submitButton.disabled = true;
                                             }
                                         }
                                        else {
                                             dependentField.disabled = true;
                                             dependentField.value = '';
                                         }
                                   }
                               }
                           }
                     }
                 });
             }
            else {
                 // Listen to 'input' on text, email, date, select, textarea
                 currentField.addEventListener('input', function() {
                     if (this.value.trim() !== '' && currentField.checkValidity()) { // Also check browser validity
                         enableNextField(currentField.id);
                         // Special handling for 'contact' field to enable 'username'
                          if (currentField.id === 'contact') {
                             const fullContact = document.getElementById('full-contact');
                              if (fullContact.value.trim() !== '' && validatePhoneNumber(this.value.trim())) {
                                 const usernameField = document.getElementById('username');
                                 if (usernameField) usernameField.disabled = false;
                              } else {
                                   const usernameField = document.getElementById('username');
                                   if (usernameField) usernameField.disabled = true; // Disable if contact invalid/empty
                              }
                         }
                     } else {
                         // If input is cleared or invalid, disable subsequent fields
                          const currentIndex = fieldOrder.indexOf(currentField.id);
                           if (currentIndex !== -1) {
                               for (let i = currentIndex + 1; i < fieldOrder.length; i++) {
                                   const dependentField = document.getElementById(fieldOrder[i]);
                                   if (dependentField) {
                                        if (dependentField.type === 'radio') {
                                             document.getElementsByName(dependentField.name).forEach(el => {
                                                 el.disabled = true;
                                                 el.checked = false;
                                             });
                                         } else if (dependentField.type === 'checkbox') {
                                             dependentField.disabled = true;
                                             dependentField.checked = false;
                                              if (dependentField.id === 'consent') {
                                                 const submitButton = form.querySelector('.submit-btn');
                                                 if (submitButton) submitButton.disabled = true;
                                             }
                                         }
                                        else {
                                             dependentField.disabled = true;
                                             dependentField.value = '';
                                             // Special handling to disable username if contact becomes invalid/empty
                                             if (dependentField.id === 'username') {
                                                  const usernameField = document.getElementById('username');
                                                  if (usernameField) usernameField.disabled = true;
                                             }
                                         }
                                   }
                               }
                           }
                     }
                 });
                  // Also check validation and enable on blur
                  currentField.addEventListener('blur', function() {
                       if (this.value.trim() !== '' && currentField.checkValidity()) {
                            enableNextField(currentField.id);
                       } else {
                             // If input is invalid or empty on blur, disable subsequent fields
                           const currentIndex = fieldOrder.indexOf(currentField.id);
                           if (currentIndex !== -1) {
                               for (let i = currentIndex + 1; i < fieldOrder.length; i++) {
                                   const dependentField = document.getElementById(fieldOrder[i]);
                                   if (dependentField) {
                                        if (dependentField.type === 'radio') {
                                             document.getElementsByName(dependentField.name).forEach(el => {
                                                 el.disabled = true;
                                                 el.checked = false;
                                             });
                                         } else if (dependentField.type === 'checkbox') {
                                             dependentField.disabled = true;
                                             dependentField.checked = false;
                                              if (dependentField.id === 'consent') {
                                                 const submitButton = form.querySelector('.submit-btn');
                                                 if (submitButton) submitButton.disabled = true;
                                             }
                                         }
                                        else {
                                             dependentField.disabled = true;
                                             dependentField.value = '';
                                              if (dependentField.id === 'username') {
                                                  const usernameField = document.getElementById('username');
                                                  if (usernameField) usernameField.disabled = true;
                                             }
                                         }
                                   }
                               }
                           }
                       }
                   });
            }
        });


        // Disable all fields except the first (fname) and the submit button initially
        fieldOrder.forEach((id) => {
            const field = document.getElementById(id);
             if (!field) return;

             if (id !== 'fname') {
                if (field.type === 'radio') {
                     document.getElementsByName(field.name).forEach(el => el.disabled = true);
                 } else if (field.type === 'checkbox') {
                     field.disabled = true;
                 }
                 else {
                     field.disabled = true;
                 }
             }
        });
         // Disable the submit button initially
         const submitButton = form.querySelector('.submit-btn');
         if(submitButton) {
             submitButton.disabled = true;
         }

         // Add form submission validation
         form.addEventListener('submit', function(event) {
             let formIsValid = true;

             // Basic check if all required fields are filled and not disabled
             form.querySelectorAll('[required]:not(:disabled)').forEach(input => {
                 if (!input.value.trim()) {
                     formIsValid = false;
                     // Optionally highlight the field or show a message
                     input.classList.add('invalid-input');
                 } else {
                      input.classList.remove('invalid-input');
                 }
             });

             // Specific validations
             if (!validateDOB()) formIsValid = false;
             if (!validatePassword()) formIsValid = false;
             if (!validateConfirmPassword()) formIsValid = false;
             if (!validatePhoneNumber(contactInput.value.trim())) formIsValid = false;
             if (!isUsernameValid) { // Check the AJAX username validity
                  usernameInput.classList.add('invalid-input');
                  formIsValid = false;
             } else {
                  usernameInput.classList.remove('invalid-input');
             }


             // Check terms and consent checkboxes
             const termsChecked = document.getElementById('terms').checked;
             const consentChecked = document.getElementById('consent').checked;
              if (!termsChecked || !consentChecked) {
                  alert("Please agree to the Terms & Conditions and Data Privacy Policy, and provide consent.");
                  formIsValid = false;
              }


             if (!formIsValid) {
                 event.preventDefault(); // Stop form submission
                 alert('Please fill out all required fields correctly.');
             }
         });
    });
    </script>
</body>
</html>