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

/* ---------- reCAPTCHA consent ----------
   Ekomloven § 3-15 krever samtykke før noe lagres i besøkendes nettleser.
   reCAPTCHA lagrer data, så api.js lastes aldri ved sidelast — det injiseres
   først når besøkende trykker "Godta". Resten av siden lagrer ingenting
   klientsiden, så dette er det eneste som krever samtykke.
   ------------------------------------------------------------------------- */
const CONSENT_KEY = 'hr-recaptcha-consent';
const CONSENT_MAX_AGE_MS = 365 * 24 * 60 * 60 * 1000; // 12 måneder
const RECAPTCHA_SITE_KEY = '6LeRW7YqAAAAAJz7wTeUBLmbI4b9wsqkrfcBQeKo';

let recaptchaWidgetId = null;
let recaptchaLoading = false;

/* Gir 'granted', 'denied' eller null. Alt som er eldre enn 12 måneder, eller
   som ikke kan tolkes, regnes som ingen valg — aldri som en standardverdi. */
function readConsent() {
  let raw = null;
  try {
    raw = window.localStorage.getItem(CONSENT_KEY);
  } catch (e) {
    return null; // lagring blokkert: behandles som ingen valg
  }
  if (!raw) return null;

  let parsed = null;
  try {
    parsed = JSON.parse(raw);
  } catch (e) {
    clearConsent();
    return null;
  }

  const isValid = parsed &&
    (parsed.consent === 'granted' || parsed.consent === 'denied');
  const isFresh = parsed && typeof parsed.ts === 'number' &&
    (Date.now() - parsed.ts) < CONSENT_MAX_AGE_MS;

  if (!isValid || !isFresh) {
    clearConsent();
    return null;
  }
  return parsed.consent;
}

function writeConsent(value) {
  try {
    window.localStorage.setItem(CONSENT_KEY, JSON.stringify({
      v: 1,
      consent: value,
      ts: Date.now(),
    }));
  } catch (e) {
    // Lagring blokkert: valget gjelder bare denne sidevisningen
  }
}

function clearConsent() {
  try {
    window.localStorage.removeItem(CONSENT_KEY);
  } catch (e) { /* ingenting å rydde */ }
}

/* Fjerner det vi kan nå. Cookies satt på google.com tilhører et annet origin og
   kan ikke slettes herfra — bare nettleseren eller Google kan det. Vi hindrer at
   scriptet lastes igjen, fjerner rammene det har lagt inn, og rydder alt på vårt
   eget domene. */
function removeRecaptcha() {
  recaptchaWidgetId = null;
  recaptchaLoading = false;

  document.querySelectorAll('script[data-recaptcha]').forEach(function (el) {
    if (el.parentNode) el.parentNode.removeChild(el);
  });
  document.querySelectorAll('iframe[src*="recaptcha"], .grecaptcha-badge').forEach(function (el) {
    if (el.parentNode) el.parentNode.removeChild(el);
  });

  const slot = document.querySelector('#recaptcha-widget');
  if (slot) slot.innerHTML = '';

  try {
    delete window.grecaptcha;
  } catch (e) {
    window.grecaptcha = undefined;
  }

  // Rydder cookies på vårt eget domene som ser ut som reCAPTCHAs
  document.cookie.split(';').forEach(function (part) {
    const name = part.split('=')[0].trim();
    if (name.indexOf('_GRECAPTCHA') === 0 || name.indexOf('rc::') === 0) {
      document.cookie = name + '=; Max-Age=0; path=/';
    }
  });
}

function renderRecaptcha() {
  recaptchaLoading = false;

  const slot = document.querySelector('#recaptcha-widget');
  if (!slot || recaptchaWidgetId !== null) return;
  if (!window.grecaptcha || !window.grecaptcha.render) return;

  slot.innerHTML = '';
  recaptchaWidgetId = window.grecaptcha.render(slot, {
    sitekey: RECAPTCHA_SITE_KEY,
  });

  // Sendeknappen åpnes først når widgeten faktisk er klar. Uten dette rekker
  // besøkende å trykke Send i sekundet mellom samtykke og ferdig lastet script,
  // og får en feilmelding som ser ut som at noe er galt.
  const form = document.querySelector('.contact-form');
  const submit = form ? form.querySelector('button[type="submit"]') : null;
  if (submit) submit.disabled = false;
}

/* Eksplisitt rendering, ikke auto-rendering på klassen g-recaptcha. Det lar oss
   laste og fjerne widgeten flere ganger i samme økt når besøkende endrer valg,
   uten å måtte laste siden på nytt. */
function loadRecaptcha() {
  if (recaptchaWidgetId !== null || recaptchaLoading) return;
  if (!document.querySelector('#recaptcha-widget')) return;

  if (window.grecaptcha && window.grecaptcha.render) {
    renderRecaptcha();
    return;
  }

  recaptchaLoading = true;
  window.hrRecaptchaOnload = renderRecaptcha;

  const script = document.createElement('script');
  script.src = 'https://www.google.com/recaptcha/api.js' +
    '?onload=hrRecaptchaOnload&render=explicit&hl=no';
  script.async = true;
  script.defer = true;
  script.setAttribute('data-recaptcha', '');
  script.onerror = function () {
    recaptchaLoading = false;
    const slot = document.querySelector('#recaptcha-widget');
    if (slot) {
      slot.innerHTML = '<p class="consent__error">Robotsjekken kunne ikke lastes. ' +
        'Last siden på nytt, eller send oss en e-post.</p>';
    }
  };
  document.body.appendChild(script);
}

/* Forhåndsutfyller e-postlenken med det besøkende allerede har skrevet, slik at
   teksten ikke går tapt for den som avviser. */
function updateDeclinedMailto() {
  const link = document.querySelector('#consent-mailto');
  const form = document.querySelector('.contact-form');
  if (!link || !form) return;

  const to = link.getAttribute('data-email');
  if (!to) return;

  const nameField = form.querySelector('#name');
  const messageField = form.querySelector('#message');
  const name = nameField ? nameField.value.trim() : '';
  const message = messageField ? messageField.value.trim() : '';

  const params = ['subject=' + encodeURIComponent('Henvendelse fra nettsiden')];
  if (name || message) {
    params.push('body=' + encodeURIComponent(
      (name ? 'Navn: ' + name + '\n\n' : '') + message
    ));
  }
  link.href = 'mailto:' + to + '?' + params.join('&');
}

function applyConsentState() {
  const box = document.querySelector('#consent');
  const form = document.querySelector('.contact-form');
  if (!box || !form) return; // ikke kontaktsiden

  const status = document.querySelector('#consent-status');
  const declined = document.querySelector('#consent-declined');
  const slot = document.querySelector('#recaptcha-widget');
  const submit = form.querySelector('button[type="submit"]');
  const state = readConsent();

  if (state === 'granted') {
    box.hidden = true;
    form.hidden = false;
    if (status) status.hidden = false;
    if (declined) declined.hidden = true;
    if (slot) slot.hidden = false;
    // Åpnes av renderRecaptcha() når widgeten er ferdig lastet
    if (submit) submit.disabled = (recaptchaWidgetId === null);
    loadRecaptcha();
    return;
  }

  if (state === 'denied') {
    // Skjemaet skjules, ikke fjernes, slik at teksten er der om noen ombestemmer seg
    box.hidden = true;
    form.hidden = true;
    if (status) status.hidden = true;
    if (slot) slot.hidden = true;
    if (declined) {
      declined.hidden = false;
      updateDeclinedMailto();
    }
    removeRecaptcha();
    return;
  }

  // Ingen valg tatt: skjemaet vises, men kan ikke sendes før valget er gjort
  box.hidden = false;
  form.hidden = false;
  if (status) status.hidden = true;
  if (declined) declined.hidden = true;
  if (slot) slot.hidden = true;
  if (submit) submit.disabled = true;
  removeRecaptcha();
}

const consentAccept = document.querySelector('#consent-accept');
const consentDecline = document.querySelector('#consent-decline');
const consentChange = document.querySelector('#consent-change');
const consentReconsider = document.querySelector('#consent-reconsider');
const consentManage = document.querySelector('#consent-manage');

if (consentAccept) {
  consentAccept.addEventListener('click', function () {
    writeConsent('granted');
    applyConsentState();
  });
}

if (consentDecline) {
  consentDecline.addEventListener('click', function () {
    writeConsent('denied');
    applyConsentState();
  });
}

// Tilbake til uavgjort, slik at ingenting er forhåndsvalgt neste gang
if (consentChange) {
  consentChange.addEventListener('click', function () {
    clearConsent();
    removeRecaptcha();
    applyConsentState();
  });
}

if (consentReconsider) {
  consentReconsider.addEventListener('click', function () {
    writeConsent('granted');
    applyConsentState();
  });
}

// Bunntekstlenken finnes på alle sider, også de uten kontaktskjema
if (consentManage) {
  consentManage.addEventListener('click', function () {
    clearConsent();
    removeRecaptcha();

    const box = document.querySelector('#consent');
    if (box) {
      applyConsentState();
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
      const note = document.querySelector('#consent-manage-note');
      if (note) {
        note.textContent = 'Personvernvalget er nullstilt. Du blir spurt på nytt ' +
          'neste gang du bruker kontaktskjemaet.';
      }
    }
  });
}

applyConsentState();

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

    // CAPTCHA validation. Uten samtykke finnes widgeten ikke i det hele tatt.
    // Serveren avviser uansett innsending uten gyldig token — dette er bare
    // for å gi besøkende en forståelig beskjed før den turen.
    let recaptchaResponse = '';
    if (readConsent() !== 'granted') {
      errorMessages.push('* Du må godta Google reCAPTCHA for å sende skjemaet.');
      hasError = true;
    } else if (typeof grecaptcha === 'undefined' || recaptchaWidgetId === null) {
      errorMessages.push('* Robotsjekken er ikke ferdig lastet. Prøv igjen om et øyeblikk.');
      hasError = true;
    } else {
      recaptchaResponse = grecaptcha.getResponse(recaptchaWidgetId);
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
          if (typeof grecaptcha !== 'undefined' && recaptchaWidgetId !== null) grecaptcha.reset(recaptchaWidgetId);
        } else if (data.success) {
          window.location.href = 'takk.html';
        }
      })
      .catch(function (error) {
        console.error('Error:', error);
        errorContainer.innerHTML = 'Noe gikk galt. Vennligst prøv igjen senere.';
        errorContainer.style.display = 'block';
        if (typeof grecaptcha !== 'undefined' && recaptchaWidgetId !== null) grecaptcha.reset(recaptchaWidgetId);
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
