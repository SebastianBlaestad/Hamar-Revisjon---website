<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable displaying errors
ini_set('log_errors', 1); // Log errors to a file
ini_set('error_log', __DIR__ . '/error_log.txt'); // Logs errors to a file

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
    $secretKey = getenv("RECAPTCHA_SECRET_KEY");
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

    if (!$responseKeys['success']) {
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
    $to = getenv("EMAIL_RECIPIENT");
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