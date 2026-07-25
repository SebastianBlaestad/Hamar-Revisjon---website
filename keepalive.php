<?php
/**
 * Månedlig vedlikehold for kontaktskjemaet. Gjør to ting:
 *
 * 1. Sletter henvendelser eldre enn tolv måneder fra henvendelser.txt.
 *    Personvernerklæringen lover sletting senest innen tolv måneder, og det er
 *    denne rutinen som holder lovnaden.
 *
 * 2. Holder Brevo-API-nøkkelen aktiv. Brevo deaktiverer nøkler som ikke er
 *    brukt på 90 dager, og skjemaet kan gå lenger enn det mellom henvendelser,
 *    så et harmløst oppslag mot Brevo regnes som aktivitet.
 *
 * Legges i samme mappe som config.php (/home/H/hamarrev/), altså UTENFOR www.
 *
 * Kjøres av cron, f.eks. den 1. i måneden kl 03:00:
 *   0 3 1 * * php /home/H/hamarrev/keepalive.php
 *
 * Skriver bare når noe faktisk ble slettet, eller når noe feiler. Ellers er den
 * stille, slik at cron ikke sender e-post uten grunn.
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

/**
 * Fjerner henvendelser eldre enn $maxAgeDays fra arkivet.
 *
 * Filen skrives av send.php med LOCK_EX, og vi tar samme lås her, slik at en
 * henvendelse som kommer inn midt i ryddingen ikke går tapt.
 *
 * Returnerer antall slettede oppføringer.
 */
function pruneArchive($path, $maxAgeDays) {
    if (!file_exists($path)) {
        return 0; // ingen henvendelser ennå
    }

    $handle = @fopen($path, 'c+');
    if (!$handle) {
        fail("kunne ikke apne $path for rydding");
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        fail("kunne ikke lase $path");
    }

    $size = filesize($path);
    $contents = $size > 0 ? fread($handle, $size) : '';

    // send.php skriver en linje med 60 bindestreker foran hver oppføring
    $separator = str_repeat('-', 60);
    $blocks = explode($separator . "\n", $contents);

    $cutoff = time() - ($maxAgeDays * 24 * 60 * 60);
    $kept = array();
    $removed = 0;

    foreach ($blocks as $block) {
        if (trim($block) === '') {
            continue;
        }

        // Første linje i hver oppføring er "YYYY-MM-DD HH:MM:SS"
        $stamp = null;
        if (preg_match('/^\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $block, $m)) {
            $stamp = strtotime($m[1]);
        }

        // Oppføringer uten lesbar dato beholdes. Bedre å la noe ligge enn å
        // slette en henvendelse vi ikke klarte å tolke.
        if ($stamp === null || $stamp === false || $stamp >= $cutoff) {
            $kept[] = $block;
        } else {
            $removed++;
        }
    }

    if ($removed > 0) {
        $rebuilt = '';
        foreach ($kept as $block) {
            $rebuilt .= $separator . "\n" . $block;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $rebuilt);
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $removed;
}

// Rydding først, slik at den skjer selv om Brevo skulle være utilgjengelig
$archivePath = dirname(__FILE__) . '/henvendelser.txt';
$removedCount = pruneArchive($archivePath, 365);
if ($removedCount > 0) {
    echo date('Y-m-d H:i:s') . " slettet $removedCount henvendelse(r) eldre enn 12 maneder\n";
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
