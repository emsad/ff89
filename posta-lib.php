<?php
// Funzioni comuni ai moduli del sito: lettura della configurazione privata e invio email
// tramite la casella info@ (SMTP autenticato Hostinger, così l'email parte firmata e non finisce in spam).
// Se la password non è impostata o l'invio SMTP fallisce, usa mail() come prima.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function ff_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [];
    $casa = preg_replace('~/public_html(/.*)?$~', '', __DIR__);
    foreach ([$casa . '/config-sito/twenty.php', preg_replace('~(/public_html)(/.*)?$~', '$1', __DIR__) . '/config-sito/twenty.php'] as $p) {
        if (is_file($p)) { $c = @include($p); if (is_array($c)) $cfg = $c; return $cfg; }
    }
    // crea il file da completare, fuori dalla cartella pubblica
    if (!is_dir($casa . '/config-sito')) @mkdir($casa . '/config-sito', 0750, true);
    @file_put_contents($casa . '/config-sito/twenty.php', "<?php\n// Configurazione privata dei moduli del sito.\nreturn [\n    'twenty_key' => 'INCOLLA_QUI_LA_CHIAVE',\n    'smtp_password' => 'INCOLLA_QUI_LA_PASSWORD',\n];\n");
    return $cfg;
}

function ff_valore($k) {
    $v = trim((string)(ff_config()[$k] ?? ''));
    return ($v === '' || strpos($v, 'INCOLLA_QUI') === 0) ? '' : $v;
}

// $da = indirizzo mittente (la casella), $a = destinatario, $rispondi = [nome, email] per il Reply-To
function ff_invia_email($da, $a, $oggetto, $testo, $rispondi = null) {
    $da = ff_valore('smtp_user') ?: 'report@fabiofregoni.com'; // casella dedicata agli invii automatici
    $ogg = '=?UTF-8?B?' . base64_encode($oggetto) . '?=';
    $int = [
        'From: =?UTF-8?B?' . base64_encode('Sito fabiofregoni.com') . '?= <' . $da . '>',
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
    ];
    if ($rispondi && filter_var($rispondi[1], FILTER_VALIDATE_EMAIL)) {
        $int[] = 'Reply-To: =?UTF-8?B?' . base64_encode($rispondi[0]) . '?= <' . $rispondi[1] . '>';
    }
    $corpo = chunk_split(base64_encode($testo));
    $pw = ff_valore('smtp_password');
    if ($pw !== '' && ff_smtp($da, $pw, $a, $ogg, $int, $corpo)) return true;
    return @mail($a, $ogg, $corpo, str_replace('<' . $da . '>', '<info@fabiofregoni.com>', implode("\r\n", $int)), '-finfo@fabiofregoni.com');
}

function ff_smtp($utente, $pw, $a, $ogg, $int, $corpo) {
    $s = @stream_socket_client('ssl://smtp.hostinger.com:465', $en, $es, 15);
    if (!$s) return false;
    stream_set_timeout($s, 15);
    $leggi = function () use ($s) { $r = ''; while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
    $cmd = function ($c, $ok) use ($s, $leggi) { if ($c !== null) fwrite($s, $c . "\r\n"); $r = $leggi(); return strpos($r, (string)$ok) === 0; };
    $dom = substr(strrchr($utente, '@'), 1);
    $ok = $cmd(null, 220) && $cmd('EHLO ' . $dom, 250) && $cmd('AUTH LOGIN', 334)
        && $cmd(base64_encode($utente), 334) && $cmd(base64_encode($pw), 235)
        && $cmd('MAIL FROM:<' . $utente . '>', 250) && $cmd('RCPT TO:<' . $a . '>', 250) && $cmd('DATA', 354);
    if ($ok) {
        $head = array_merge([
            'Date: ' . date('r'), 'To: <' . $a . '>', 'Subject: ' . $ogg,
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dom . '>',
        ], $int);
        $msg = implode("\n", $head) . "\n\n" . str_replace("\r\n", "\n", $corpo);
        $msg = str_replace("\n.", "\n..", rtrim($msg, "\n"));
        $msg = str_replace("\n", "\r\n", $msg);
        $ok = $cmd($msg . "\r\n.", 250);
    }
    @fwrite($s, "QUIT\r\n"); fclose($s);
    if (!$ok) @file_put_contents(preg_replace('~/public_html(/.*)?$~', '', __DIR__) . '/richieste-sito/smtp-errori.log', date('c') . " invio SMTP non riuscito\n", FILE_APPEND);
    return $ok;
}
