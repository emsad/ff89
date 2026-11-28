<?php
// Modulo "Analisi gratuita": riceve i dati, salva i file FUORI dalla cartella pubblica,
// crea/aggiorna la scheda in Twenty (Richieste analisi) e manda un'email a Fabio.
// La chiave di Twenty sta in un file fuori dalla cartella pubblica: ../config-sito/twenty.php
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
date_default_timezone_set('Europe/Rome');

const DESTINATARIO = 'info@fabiofregoni.com';
const MITTENTE = 'info@fabiofregoni.com';
const TWENTY_URL = 'https://crm.fabiofregoni.com';
const MAX_FILE = 10;
const MAX_BYTE = 8 * 1024 * 1024;

function fine($dati, $codice = 200) { http_response_code($codice); echo json_encode($dati); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fine(['ok' => false], 405);

$origine = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origine && !preg_match('~^https?://(([a-z0-9-]+\.)?fabiofregoni\.com|localhost|127\.0\.0\.1)(:\d+)?(/|$)~i', $origine)) fine(['ok' => false], 403);

// anti-spam: campo nascosto e tempo minimo
if (!empty($_POST['sito_web_2'])) fine(['ok' => true]);
if ((int)($_POST['_t'] ?? 0) < 4000) fine(['ok' => true]);

$base = preg_replace('~/public_html(/.*)?$~', '', __DIR__) ?: dirname(__DIR__);
$cartella = $base . '/richieste-sito';
$archivio = $cartella . '/analisi';
if (!is_dir($archivio)) @mkdir($archivio, 0750, true);

// pulizia: cancella le richieste più vecchie di 90 giorni (file compresi)
if (mt_rand(1, 20) === 1) foreach (glob($archivio . '/*', GLOB_ONLYDIR) ?: [] as $vecchia) {
    if (filemtime($vecchia) < time() - 90 * 86400) { array_map('unlink', glob($vecchia . '/*') ?: []); @rmdir($vecchia); }
}

// limite: 8 invii ogni 10 minuti dallo stesso indirizzo
$lim = $cartella . '/limite-analisi-' . md5($_SERVER['REMOTE_ADDR'] ?? '') . '.txt';
$adesso = time();
$colpi = array_filter(array_map('intval', @file($lim, FILE_IGNORE_NEW_LINES) ?: []), fn($t) => $t > $adesso - 600);
if (count($colpi) >= 8) fine(['ok' => false], 429);
$colpi[] = $adesso; @file_put_contents($lim, implode("\n", $colpi));

function c($k, $max = 1000) {
    $v = $_POST[$k] ?? '';
    if (is_array($v)) $v = implode(', ', array_map('strval', $v));
    return mb_substr(trim(str_replace(["\r", "\0"], '', (string)$v)), 0, $max);
}
function link_url($v) {
    $v = trim($v); if ($v === '') return '';
    if (preg_match('~^@?[\w.]+$~', $v) && strpos($v, '.') === false) return $v; // es. @profilo
    return preg_match('~^https?://~i', $v) ? $v : 'https://' . ltrim($v, '/');
}

$fase = c('fase', 20) === 'completa' ? 'completa' : 'parziale';
$d = [
    'nome' => c('nome', 120), 'email' => c('email', 160), 'tel' => c('tel', 40), 'attivita' => c('attivita', 160),
    'piva' => strtoupper(preg_replace('~\s+~', '', c('piva', 20))), 'professione' => c('professione', 80), 'citta' => c('citta', 160),
    'struttura' => c('struttura', 80), 'servizio' => c('servizio', 300), 'obiettivo' => c('obiettivo', 80),
    'provenienza' => c('provenienza', 300), 'cliente_ideale' => c('cliente_ideale', 300), 'concorrente' => c('concorrente', 300),
    'sito' => c('sito', 300), 'no_sito' => c('no_sito', 2) === '1', 'scheda_google' => c('scheda_google', 300),
    'linkedin' => c('linkedin', 300), 'linkedin_pagina' => c('linkedin_pagina', 300), 'instagram' => c('instagram', 300),
    'facebook' => c('facebook', 300), 'altri_canali' => c('altri_canali', 300), 'pubblicita' => c('pubblicita', 200),
    'newsletter' => c('newsletter', 10), 'materiali' => c('materiali', 600),
    'privacy' => c('privacy', 2) === '1', 'materiali_ok' => c('materiali_ok', 2) === '1', 'marketing' => c('marketing', 2) === '1',
    'url' => c('url', 500), 'utm' => trim(implode(' / ', array_filter([c('utm_source', 100), c('utm_medium', 100), c('utm_campaign', 150)]))),
];

if ($d['nome'] === '' || !filter_var($d['email'], FILTER_VALIDATE_EMAIL) || $d['tel'] === '' || $d['attivita'] === '') fine(['ok' => false], 422);
if ($fase === 'completa' && (!$d['privacy'] || !$d['materiali_ok'] || $d['servizio'] === '')) fine(['ok' => false], 422);

// token della richiesta: lega lo step 1 all'invio finale
$token = preg_match('~^[a-f0-9]{32}$~', c('token', 40)) ? c('token', 40) : bin2hex(random_bytes(16));
$dir = $archivio . '/' . $token;
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$stato = is_file($dir . '/stato.json') ? (json_decode(file_get_contents($dir . '/stato.json'), true) ?: []) : [];

// file caricati
$salvati = [];
if ($fase === 'completa') {
    $ok_ext = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf', 'svg', 'ai', 'eps'];
    $lista = [];
    if (!empty($_FILES['file']['name']) && is_array($_FILES['file']['name'])) {
        foreach ($_FILES['file']['name'] as $i => $n) $lista[] = ['n' => $n, 'tmp' => $_FILES['file']['tmp_name'][$i], 'err' => $_FILES['file']['error'][$i], 'size' => $_FILES['file']['size'][$i], 'pref' => ''];
    }
    if (!empty($_FILES['logo']['name'])) $lista[] = ['n' => $_FILES['logo']['name'], 'tmp' => $_FILES['logo']['tmp_name'], 'err' => $_FILES['logo']['error'], 'size' => $_FILES['logo']['size'], 'pref' => 'LOGO-'];
    foreach (array_slice($lista, 0, MAX_FILE + 1) as $k => $f) {
        if ($f['err'] !== UPLOAD_ERR_OK || $f['size'] > MAX_BYTE) continue;
        $ext = strtolower(pathinfo($f['n'], PATHINFO_EXTENSION));
        if (!in_array($ext, $ok_ext, true)) continue;
        $nome = $f['pref'] . sprintf('%02d-', $k + 1) . substr(preg_replace('~[^a-zA-Z0-9._-]+~', '-', pathinfo($f['n'], PATHINFO_FILENAME)), 0, 60) . '.' . $ext;
        if (@move_uploaded_file($f['tmp'], $dir . '/' . $nome)) $salvati[] = $nome;
    }
}
$link_file = $salvati ? 'https://fabiofregoni.com/analisi-file.php?k=' . $token : '';

// testo completo della richiesta
$righe = [
    'Nome' => $d['nome'], 'Email' => $d['email'], 'Telefono' => $d['tel'], 'Attività' => $d['attivita'], 'Partita IVA' => $d['piva'],
    'Professione' => $d['professione'], 'Città e zona' => $d['citta'], 'Come lavora' => $d['struttura'],
    'Servizio da promuovere' => $d['servizio'], 'Obiettivo' => $d['obiettivo'], 'Clienti arrivano da' => $d['provenienza'],
    'Cliente ideale' => $d['cliente_ideale'], 'Concorrente che comunica bene' => $d['concorrente'],
    'Sito' => $d['no_sito'] ? 'Non ha un sito' : $d['sito'], 'Scheda Google' => $d['scheda_google'], 'LinkedIn' => $d['linkedin'],
    'LinkedIn pagina' => $d['linkedin_pagina'], 'Instagram' => $d['instagram'], 'Facebook' => $d['facebook'], 'Altri canali' => $d['altri_canali'],
    'Pubblicità' => $d['pubblicita'], 'Newsletter' => $d['newsletter'], 'Materiali usati' => $d['materiali'],
    'File caricati' => $salvati ? count($salvati) . ' — ' . $link_file : '', 'Consenso marketing' => $d['marketing'] ? 'Sì' : 'No',
    'Pagina' => $d['url'], 'Campagna (UTM)' => $d['utm'], 'Ricevuta il' => date('d/m/Y H:i'),
];
$md = ''; $txt = '';
foreach ($righe as $k => $v) if ($v !== '' && $v !== null) { $md .= '**' . $k . ':** ' . $v . "\n\n"; $txt .= str_pad($k . ':', 32) . $v . "\n"; }

// copia di sicurezza
@file_put_contents($dir . '/richiesta.json', json_encode(['fase' => $fase, 'file' => $salvati] + $d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Twenty
function twenty($metodo, $percorso, $corpo) {
    $cfg = null;
    foreach ([preg_replace('~/public_html(/.*)?$~', '', __DIR__) . '/config-sito/twenty.php', preg_replace('~(/public_html)(/.*)?$~', '$1', __DIR__) . '/config-sito/twenty.php'] as $p) if (is_file($p)) { $cfg = @include($p); break; }
    if ($cfg === null) { // crea il file vuoto da completare (fuori dalla cartella pubblica)
        $p = preg_replace('~/public_html(/.*)?$~', '', __DIR__) . '/config-sito';
        if (!is_dir($p)) @mkdir($p, 0750, true);
        if (!is_file($p . '/twenty.php')) @file_put_contents($p . '/twenty.php', "<?php\n// Chiave API di Twenty per il modulo analisi gratuita.\n// Incolla la chiave al posto di INCOLLA_QUI_LA_CHIAVE, tra gli apici, e salva.\nreturn ['twenty_key' => 'INCOLLA_QUI_LA_CHIAVE'];\n");
    }
    $chiave = is_array($cfg) ? trim($cfg['twenty_key'] ?? '') : '';
    if ($chiave === '' || $chiave === 'INCOLLA_QUI_LA_CHIAVE') return null;
    $ch = curl_init(TWENTY_URL . '/rest/' . $percorso);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chiave, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$r, true);
    if ($code >= 300) { @file_put_contents(preg_replace('~/public_html(/.*)?$~', '', __DIR__) . '/richieste-sito/twenty-errori.log', date('c') . " $code $r\n", FILE_APPEND); return null; }
    return $j;
}
$tel = preg_replace('~[^\d+]~', '', $d['tel']);
$tel = preg_replace('~^(\+39|0039)~', '', $tel);
$scheda = [
    'name' => $d['attivita'] . ' — ' . $d['nome'],
    'stato' => $fase === 'completa' ? 'NUOVA' : 'INCOMPLETA',
    'email' => ['primaryEmail' => $d['email']],
    'telefono' => ['primaryPhoneNumber' => $tel, 'primaryPhoneCallingCode' => '+39', 'primaryPhoneCountryCode' => 'IT'],
    'attivita' => $d['attivita'], 'partitaIva' => $d['piva'], 'professione' => $d['professione'], 'citta' => $d['citta'],
];
if ($fase === 'completa') {
    $scheda += [
        'obiettivo' => $d['obiettivo'], 'consensoMarketing' => $d['marketing'],
        'sito' => ['primaryLinkUrl' => $d['no_sito'] ? '' : link_url($d['sito']), 'primaryLinkLabel' => ''],
        'fileCaricati' => ['primaryLinkUrl' => $link_file, 'primaryLinkLabel' => $salvati ? count($salvati) . ' file' : ''],
        'dettagli' => ['markdown' => $md],
    ];
}
$id = $stato['twenty_id'] ?? '';
$ris = $id ? twenty('PATCH', 'richiesteAnalisi/' . $id, $scheda) : twenty('POST', 'richiesteAnalisi', $scheda);
if (!$id && is_array($ris)) {
    foreach (['data', 'createRichiestaAnalisi'] as $k) if (isset($ris[$k])) $ris = $ris[$k];
    if (isset($ris['createRichiestaAnalisi'])) $ris = $ris['createRichiestaAnalisi'];
    $id = $ris['id'] ?? '';
}
$stato['twenty_id'] = $id; $stato['aggiornata'] = date('c');
@file_put_contents($dir . '/stato.json', json_encode($stato));

// email a Fabio solo all'invio finale
if ($fase === 'completa') {
    $testo = "Nuova richiesta di analisi gratuita\n\n" . $txt
        . ($id ? "\nScheda in Twenty: " . TWENTY_URL . "/object/richiestaAnalisi/" . $id . "\n" : "\n(Scheda Twenty non creata: controlla la chiave.)\n")
        . "\nRispondi a questa email per scrivere a " . $d['nome'] . ".\n";
    $ogg = '=?UTF-8?B?' . base64_encode('Analisi gratuita: ' . $d['attivita'] . ' (' . $d['nome'] . ')') . '?=';
    $nome = preg_replace('/[^\p{L}\p{N} .\'-]/u', '', $d['nome']);
    $int = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode('Sito fabiofregoni.com') . '?= <' . MITTENTE . '>',
        'Reply-To: =?UTF-8?B?' . base64_encode($nome) . '?= <' . $d['email'] . '>',
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit',
    ]);
    $inviata = @mail(DESTINATARIO, $ogg, $testo, $int, '-f' . MITTENTE);
    fine(['ok' => (bool)($inviata || $id)]);
}
fine(['ok' => true, 'token' => $token]);
