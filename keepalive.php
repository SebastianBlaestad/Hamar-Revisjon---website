<?php
/**
 * Holder Brevo-API-nøkkelen aktiv.
 *
 * Brevo deaktiverer API-nøkler som ikke er brukt på 90 dager. Kontaktskjemaet
 * kan gå lenger enn det mellom henvendelser, så dette skriptet gjør et
 * harmløst oppslag mot Brevo med jevne mellomrom for å regne som aktivitet.
 *
 * Legges i samme mappe som config.php (/home/H/hamarrev/), altså UTENFOR www.
 *
 * Kjøres av cron, f.eks. den 1. i måneden kl 03:00:
 *   0 3 1 * * php /home/H/hamarrev/keepalive.php
 *
 * Skriver ingenting ved suksess, slik at cron holder seg stille. Ved feil
 * skriver den til stderr og avslutter med kode 1, så cron varsler deg.
 */

if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "Dette skriptet kan bare kjøres fra kommandolinjen.\n";
    exit(1);
}

function fail($msg) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " keepalive FEILET: $msg\n");
    exit(1);
}

// Let etter config.php i samme mappe, deretter i www/
$candidates = array(
    dirname(__FILE__) . '/config.php',
    dirname(__FILE__) . '/www/config.php',
);

$config = null;
foreach ($candidates as $path) {
    if (is_readable($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $config = $loaded;
            break;
        }
    }
}

if ($config === null) {
    fail('fant ikke config.php (lette i: ' . implode(', ', $candidates) . ')');
}

if (empty($config['BREVO_API_KEY'])) {
    fail('BREVO_API_KEY mangler i config.php');
}

$ch = curl_init('https://api.brevo.com/v3/account');
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'accept: application/json',
    'api-key: ' . $config['BREVO_API_KEY'],
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_errno($ch) ? curl_error($ch) : '';
curl_close($ch);

if ($curlErr !== '') {
    fail("kunne ikke kontakte Brevo: $curlErr");
}

if ($httpCode === 401) {
    fail('Brevo svarte 401. Nøkkelen er deaktivert eller slettet - '
        . 'aktiver den på nytt i Brevo under SMTP & API -> API keys.');
}

if ($httpCode !== 200) {
    fail("Brevo svarte HTTP $httpCode: " . substr((string) $response, 0, 300));
}

// Suksess: ingen utskrift, så cron holder seg stille.
exit(0);
