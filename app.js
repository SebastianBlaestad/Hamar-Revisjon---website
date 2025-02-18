const menu = document.querySelector('#mobile-menu');
const menuLinks = document.querySelector('.navbar__menu');
const navLinks = document.querySelectorAll('.navbar__links');

menu.addEventListener('click', function() {
    menu.classList.toggle('is-active');
    menuLinks.classList.toggle('active');
});


navLinks.forEach(link => {
    link.addEventListener('click', function () {
        menu.classList.remove('is-active'); // Remove the active state from the menu
        menuLinks.classList.remove('active'); // Close the menu
    });
});


document.querySelector('.contact-form').addEventListener('submit', function (e) {
    e.preventDefault(); // Prevent form submission to handle validations

    const form = e.target;
    const formData = new FormData(form);
    const errorContainer = document.querySelector('.error-messages');
    const captchaError = document.getElementById('captcha-error');

    let hasError = false; // Flag to track errors
    const errorMessages = []; // Collect all error messages

    // Clear previous errors
    errorContainer.innerHTML = '';
    errorContainer.style.display = 'none';
    captchaError.style.display = 'none';

    // CAPTCHA Validation
    const recaptchaResponse = grecaptcha.getResponse();
    console.log('CAPTCHA Token:', recaptchaResponse);
    
    if (!recaptchaResponse) {
        captchaError.style.display = 'block';
        captchaError.textContent = 'Vennligst fullfør CAPTCHA.';
        hasError = true;
    } else {
        grecaptcha.reset(); // Reset CAPTCHA after processing
    }

    // Field Validation
    form.querySelectorAll('[required]').forEach(function (field) {
        const fieldName = field.previousElementSibling.textContent.trim();

        if (!field.value.trim()) {
            errorMessages.push(`* '${fieldName}' er obligatorisk.`);
            field.classList.add('error');
            hasError = true;
        } else {
            if (field.name === 'email' && !field.value.includes('@')) {
                errorMessages.push('* Ugyldig e-postadresse.');
                field.classList.add('error');
                hasError = true;
            } else {
                field.classList.remove('error');
            }
        }
    });

    // Display error messages if any exist
    if (errorMessages.length > 0) {
        errorContainer.innerHTML = errorMessages.join('<br>');
        errorContainer.style.display = 'block';
    }

    // Submit the form if no errors exist
    if (!hasError) {
        formData.append('g-recaptcha-response', recaptchaResponse);

        fetch('send.php', {
            method: 'POST',
            body: formData,
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                errorContainer.innerHTML = data.error;
                errorContainer.style.display = 'block';
            } else if (data.success) {
                window.location.href = 'takk.html';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            errorContainer.innerHTML = 'Noe gikk galt. Vennligst prøv igjen senere.';
            errorContainer.style.display = 'block';
        });
    }
});

document.addEventListener("DOMContentLoaded", function () {
    // Initialize the map
    const map = L.map('map').setView([60.79427487221413, 11.075972755642287], 13); // Centered on Hamar, Norway

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Add a marker
    L.marker([60.79427487221413, 11.075972755642287]).addTo(map)
        .bindPopup('Hamar Revisjon AS')
        .openPopup();
});
