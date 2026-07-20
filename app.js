/* ============================================================
   Hamar Revisjon AS — app.js
   Mobile nav, scroll effects, contact form, map.
   All blocks are guarded so the script works on every page.
   ============================================================ */

/* ---------- Mobile navigation ---------- */
const menu = document.querySelector('#mobile-menu');
const menuLinks = document.querySelector('.navbar__menu');

if (menu && menuLinks) {
  menu.addEventListener('click', function () {
    const isOpen = menu.classList.toggle('is-active');
    menuLinks.classList.toggle('active');
    menu.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    menu.setAttribute('aria-label', isOpen ? 'Lukk meny' : 'Åpne meny');
  });

  // Close the menu when a link is clicked
  menuLinks.querySelectorAll('.navbar__links').forEach(function (link) {
    link.addEventListener('click', function () {
      menu.classList.remove('is-active');
      menuLinks.classList.remove('active');
      menu.setAttribute('aria-expanded', 'false');
    });
  });
}

/* ---------- Navbar shadow on scroll ---------- */
const navbar = document.querySelector('.navbar');

if (navbar) {
  const onScroll = function () {
    navbar.classList.toggle('scrolled', window.scrollY > 8);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}

/* ---------- Subtle scroll reveal ---------- */
const revealElements = document.querySelectorAll('.reveal');

if (revealElements.length > 0) {
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    revealElements.forEach(function (el) {
      observer.observe(el);
    });
  } else {
    // Old browsers: show everything immediately
    revealElements.forEach(function (el) {
      el.classList.add('in-view');
    });
  }
}

/* ---------- Contact form ---------- */
const contactForm = document.querySelector('.contact-form');

if (contactForm) {
  contactForm.addEventListener('submit', function (e) {
    e.preventDefault(); // Handle validation and submission ourselves

    const form = e.target;
    const formData = new FormData(form);
    const errorContainer = form.querySelector('.error-messages');
    const captchaError = document.getElementById('captcha-error');
    const submitButton = form.querySelector('button[type="submit"]');

    let hasError = false;
    const errorMessages = [];

    // Clear previous errors
    errorContainer.innerHTML = '';
    errorContainer.style.display = 'none';
    if (captchaError) captchaError.style.display = 'none';

    // CAPTCHA validation
    let recaptchaResponse = '';
    if (typeof grecaptcha !== 'undefined') {
      recaptchaResponse = grecaptcha.getResponse();
      if (!recaptchaResponse) {
        if (captchaError) captchaError.style.display = 'block';
        hasError = true;
      }
    }

    // Field validation
    form.querySelectorAll('[required]').forEach(function (field) {
      const label = form.querySelector('label[for="' + field.id + '"]');
      const fieldName = label ? label.textContent.replace('*', '').trim() : field.name;

      if (!field.value.trim()) {
        errorMessages.push("* '" + fieldName + "' er obligatorisk.");
        field.classList.add('error');
        hasError = true;
      } else if (field.name === 'email' && !field.value.includes('@')) {
        errorMessages.push('* Ugyldig e-postadresse.');
        field.classList.add('error');
        hasError = true;
      } else {
        field.classList.remove('error');
      }
    });

    if (errorMessages.length > 0) {
      errorContainer.innerHTML = errorMessages.join('<br>');
      errorContainer.style.display = 'block';
    }

    if (hasError) return;

    formData.append('g-recaptcha-response', recaptchaResponse);

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Sender…';
    }

    fetch('send.php', {
      method: 'POST',
      body: formData,
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        if (data.error) {
          errorContainer.innerHTML = data.error;
          errorContainer.style.display = 'block';
          if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
        } else if (data.success) {
          window.location.href = 'takk.html';
        }
      })
      .catch(function (error) {
        console.error('Error:', error);
        errorContainer.innerHTML = 'Noe gikk galt. Vennligst prøv igjen senere.';
        errorContainer.style.display = 'block';
        if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
      })
      .finally(function () {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = 'Send melding';
        }
      });
  });
}

/* ---------- Map (Leaflet, contact page only) ---------- */
document.addEventListener('DOMContentLoaded', function () {
  const mapElement = document.getElementById('map');
  if (!mapElement || typeof L === 'undefined') return;

  const position = [60.79427487221413, 11.075972755642287]; // Østregate 23, Hamar

  const map = L.map('map', { scrollWheelZoom: false }).setView(position, 15);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);

  L.marker(position).addTo(map)
    .bindPopup('<strong>Hamar Revisjon AS</strong><br>Østregate 23, 2317 Hamar')
    .openPopup();
});
