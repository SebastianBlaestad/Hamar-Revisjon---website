<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable displaying errors
ini_set('log_errors', 1); // Log errors to a file
ini_set('error_log', __DIR__ . '/error_log.txt'); // Logs errors to a file

// Load configuration from .env (getenv() alone does not read this file)
function env($key) {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    static $envFile = null;
    if ($envFile === null) {
        $path = __DIR__ . '/.env';
        $envFile = is_readable($path)
            ? parse_ini_file($path, false, INI_SCANNER_RAW)
            : array();
        if ($envFile === false) {
            $envFile = array();
        }
    }

    return isset($envFile[$key]) ? trim($envFile[$key]) : '';
}

if (empty($_POST)) {
    error_log("POST data is empty.\n", 3, 'error_log.txt');
    echo json_encode(array("error" => "Ingen data ble sendt."));
    exit();
}

if (!isset($_POST['g-recaptcha-response'])) {
    error_log("CAPTCHA response is missing.\n", 3, 'error_log.txt');
    echo json_encode(array("error" => "CAPTCHA response mangler."));
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $captcha = $_POST['g-recaptcha-response'];

    // Check CAPTCHA
    if (!$captcha) {
        echo json_encode(array("error" => "Vennligst fullfør CAPTCHA."));
        exit();
    }

    // Verify CAPTCHA with Google
    $secretKey = env("RECAPTCHA_SECRET_KEY");
    if (!$secretKey) {
        error_log("RECAPTCHA_SECRET_KEY is not configured.");
        echo json_encode(array("error" => "Skjemaet er ikke riktig satt opp. Kontakt oss på e-post."));
        exit();
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
        'secret' => $secretKey,
        'response' => $captcha,
    )));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log("cURL Error: $error_msg\n", 3, 'error_log.txt');
        echo json_encode(array("error" => "Kunne ikke kontakte CAPTCHA-serveren."));
        curl_close($ch);
        exit();
    }

    curl_close($ch);
    $responseKeys = json_decode($response, true);

    if (empty($responseKeys['success'])) {
        $codes = isset($responseKeys['error-codes'])
            ? implode(', ', (array) $responseKeys['error-codes'])
            : 'ingen feilkode';
        error_log("reCAPTCHA verification failed: $codes");
        echo json_encode(array("error" => "CAPTCHA validering feilet. Vennligst prøv igjen."));
        exit();
    }

    // Sanitize user input
    $name = htmlspecialchars($_POST['name']);
    $email = htmlspecialchars($_POST['email']);
    $message = htmlspecialchars($_POST['message']);

    // Validate email address
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(array("error" => "Ugyldig e-postadresse."));
        exit();
    }

    // Email details
    $to = env("EMAIL_RECIPIENT");
    if (!$to) {
        error_log("EMAIL_RECIPIENT is not configured.");
        echo json_encode(array("error" => "Skjemaet er ikke riktig satt opp. Kontakt oss på e-post."));
        exit();
    }

    $subject = "Ny melding fra kontaktskjemaet";
    $body = "Du har mottatt en ny melding:\n\n" .
            "Navn: $name\n" .
            "E-post: $email\n\n" .
            "Melding:\n$message\n";

    $headers = "From: noreply@hamarrevisjon.no\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    // Attempt to send the email
    if (mail($to, $subject, $body, $headers)) {
        file_put_contents('response_log.txt', "Response sent: Success\n", FILE_APPEND);
        echo json_encode(array("success" => true));
        exit();
    } else {
        file_put_contents('response_log.txt', "Response sent: Mail error\n", FILE_APPEND);
        echo json_encode(array("error" => "Kunne ikke sende e-posten. Vennligst prøv igjen senere."));
        exit();
    }
}
?>