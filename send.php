<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable displaying errors
ini_set('log_errors', 1); // Log errors to a file
ini_set('error_log', dirname(__FILE__) . '/error_log.txt'); // Logs errors to a file

// The server has no date.timezone set and falls back to UTC. Without this, a
// submission at 01:30 Norwegian summer time is stamped with the previous date
// in henvendelser.txt, which the twelve-month pruning then reads.
date_default_timezone_set('Europe/Oslo');

// Read configuration. Tries, in order: server environment variables,
// config.php, then .env. getenv() alone reads none of the files.
function env($key) {
    static $config = null;

    if ($config === null) {
        $config = array();

        // Prefer config.php outside the webroot — no HTTP request can reach it
        $configPaths = array(
            dirname(dirname(__FILE__)) . '/config.php',
            dirname(__FILE__) . '/config.php',
        );

        $found = false;
        foreach ($configPaths as $configPath) {
            if (!is_readable($configPath)) {
                continue;
            }
            $found = true;

            $loaded = require $configPath;
            if (is_array($loaded)) {
                // Drop blanks and the placeholders shipped in config.php
                foreach ($loaded as $k => $v) {
                    $v = trim($v);
                    if ($v !== '' && strpos($v, 'LIM_INN_') !== 0) {
                        $config[$k] = $v;
                    }
                }
            }
            break;
        }

        if (!$found) {
            error_log('config.php not found in: ' . implode(', ', $configPaths));
        }

        // Fallback: .env. INI_SCANNER_RAW needs PHP 5.3+, so guard on it.
        $envPath = dirname(__FILE__) . '/.env';
        if (is_readable($envPath)) {
            $parsed = defined('INI_SCANNER_RAW')
                ? parse_ini_file($envPath, false, INI_SCANNER_RAW)
                : parse_ini_file($envPath, false);
            if (is_array($parsed)) {
                $config = array_merge($parsed, $config);
            }
        }
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return trim($value);
    }

    return isset($config[$key]) ? trim($config[$key]) : '';
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

    // Clean up user input. The mail is plain text, so strip tags rather than
    // HTML-escape: htmlspecialchars() would turn æøå-adjacent quotes and
    // ampersands into &amp; etc. in the message Elin reads.
    $name    = trim(strip_tags($_POST['name']));
    $email   = trim(strip_tags($_POST['email']));
    $message = trim(strip_tags($_POST['message']));

    // Reject header injection attempts in the fields that reach mail headers
    if (preg_match('/[\r\n]/', $name . $email)) {
        error_log('Rejected submission: newline in name or email field.');
        echo json_encode(array("error" => "Ugyldig innhold i navn eller e-post."));
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(array("error" => "Ugyldig e-postadresse."));
        exit();
    }

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

    // Keep a copy outside the webroot before sending, so a submission is never
    // lost if delivery fails. Contains personal data - must not be web-readable.
    // keepalive.php prunes entries older than twelve months and takes the same
    // lock, so LOCK_EX here keeps a submission from being lost mid-prune.
    $archivePath = dirname(dirname(__FILE__)) . '/henvendelser.txt';
    $archive = str_repeat('-', 60) . "\n"
        . date('Y-m-d H:i:s') . "\n"
        . "Navn: $name\nE-post: $email\n\n$message\n";
    @file_put_contents($archivePath, $archive, FILE_APPEND | LOCK_EX);

    // Send via Brevo's HTTP API. mail() cannot be used: this server is not an
    // authorised sender for hamarrevisjon.no, and the domain's DMARC policy is
    // p=reject, so Microsoft 365 rejects anything it sends (550 5.7.509).
    // Brevo DKIM-signs as our own domain, which satisfies DMARC via DKIM
    // alignment without changing the domain's SPF record.
    $apiKey = env("BREVO_API_KEY");
    if (!$apiKey) {
        error_log("BREVO_API_KEY is not configured.");
        echo json_encode(array("error" => "Skjemaet er ikke riktig satt opp. Kontakt oss på e-post."));
        exit();
    }

    $from = env("EMAIL_FROM");
    if (!$from || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = $to;
    }

    $payload = array(
        'sender'      => array('name' => 'Hamar Revisjon AS', 'email' => $from),
        'to'          => array(array('email' => $to)),
        'replyTo'     => array('email' => $email, 'name' => $name),
        'subject'     => $subject,
        'textContent' => $body,
    );

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'accept: application/json',
        'content-type: application/json',
        'api-key: ' . $apiKey,
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $apiResponse = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError   = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    // Brevo answers 201 Created on success. The log lives outside the webroot,
    // next to config.php, so it can neither be fetched over HTTP nor end up in
    // git - no .gitignore entry needed to keep it out.
    if ($httpCode === 201) {
        error_log(date('Y-m-d H:i:s') . " Brevo OK to=$to from=$from\n",
            3, dirname(dirname(__FILE__)) . '/response_log.txt');
        echo json_encode(array("success" => true));
        exit();
    }

    if ($curlError !== '') {
        error_log("Brevo request failed: $curlError");
    } else {
        error_log("Brevo returned HTTP $httpCode: " . substr((string) $apiResponse, 0, 500));
    }

    // app.js renders this with innerHTML, so a mailto link works. The text is
    // ours, never user input. Pre-fill the subject and body so the visitor does
    // not have to retype the message they already wrote.
    // Address itself is left unencoded - some mail clients mishandle %40
    $mailto = 'mailto:' . $to
        . '?subject=' . rawurlencode($subject)
        . '&body=' . rawurlencode("Navn: $name\n\n$message");

    echo json_encode(array("error" =>
        'Meldingen kunne ikke sendes akkurat nå. '
        . '<a href="' . htmlspecialchars($mailto, ENT_QUOTES) . '">'
        . 'Klikk her for å sende den som e-post i stedet</a> '
        . '– da beholder du teksten du har skrevet.'
    ));
    exit();
}
?>