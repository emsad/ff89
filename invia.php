<?php
// Riceve le richieste dei form del sito e le manda a info@fabiofregoni.com.
// Copia di sicurezza di ogni richiesta in un file FUORI dalla cartella pubblica.
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

date_default_timezone_set('Europe/Rome');
const DESTINATARIO = 'info@fabiofregoni.com';
const MITTENTE = 'info@fabiofregoni.com';

function fine($ok, $codice = 200) {
    http_response_code($codice);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fine(false, 405);

// solo dal sito
$origine = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origine && !preg_match('~^https?://(([a-z0-9-]+\.)?fabiofregoni\.com|localhost|127\.0\.0\.1)(:\d+)?(/|$)~i', $origine)) fine(false, 403);

// anti-spam: campo nascosto che le persone non vedono, e tempo minimo sulla pagina
if (!empty($_POST['sito_web_2'])) fine(true);
if ((int)($_POST['_t'] ?? 0) < 4000) fine(true);

// limite: massimo 5 richieste ogni 10 minuti dallo stesso indirizzo
// fuori dalla cartella pubblica (public_html)
$cartella = (preg_replace('~/public_html(/.*)?$~', '', __DIR__) ?: dirname(__DIR__)) . '/richieste-sito';
if (!is_dir($cartella)) @mkdir($cartella, 0750, true);
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$lim = $cartella . '/limite-' . md5($ip) . '.txt';
$adesso = time();
$colpi = array_filter(array_map('intval', @file($lim, FILE_IGNORE_NEW_LINES) ?: []), fn($t) => $t > $adesso - 600);
if (count($colpi) >= 5) fine(false, 429);
$colpi[] = $adesso;
@file_put_contents($lim, implode("\n", $colpi));

function campo($k, $max = 2000) {
    $v = $_POST[$k] ?? '';
    if (is_array($v)) $v = implode(', ', $v);
    $v = trim(str_replace(["\r", "\0"], '', (string)$v));
    return mb_substr($v, 0, $max);
}

$dati = [
    'Nome' => campo('nome', 120),
    'Azienda' => campo('azienda', 160),
    'Email' => campo('email', 160),
    'Telefono' => campo('tel', 60),
    'Sito attuale' => campo('sito', 200),
    'Settore' => campo('settore', 120),
    'Cosa serve' => campo('servizio', 200),
    'Dove si blocca oggi' => campo('blocco', 4000),
    'Budget' => campo('budget', 80),
    'Quando partire' => campo('quando', 80),
    'Messaggio' => campo('messaggio', 4000),
];
$pagina = campo('pagina', 160);
$url = campo('url', 500);
$utm = array_filter(['source' => campo('utm_source', 120), 'medium' => campo('utm_medium', 120), 'campaign' => campo('utm_campaign', 160)]);

if ($dati['Nome'] === '' || !filter_var($dati['Email'], FILTER_VALIDATE_EMAIL)) fine(false, 422);
if (empty($_POST['privacy'])) fine(false, 422);

$righe = [];
foreach ($dati as $k => $v) if ($v !== '') $righe[] = str_pad($k . ':', 22) . $v;
$righe[] = '';
$righe[] = str_pad('Pagina:', 22) . ($pagina ?: '-');
$righe[] = str_pad('Indirizzo:', 22) . ($url ?: '-');
if ($utm) $righe[] = str_pad('Campagna (UTM):', 22) . implode(' / ', $utm);
$righe[] = str_pad('Ricevuta il:', 22) . date('d/m/Y H:i');
$testo = "Nuova richiesta dal sito fabiofregoni.com\n\n" . implode("\n", $righe) . "\n\nRispondi a questa email per scrivere direttamente a " . $dati['Nome'] . ".\n";

// copia di sicurezza (una riga JSON per richiesta)
@file_put_contents($cartella . '/richieste.jsonl', json_encode(['data' => date('c'), 'pagina' => $pagina, 'url' => $url, 'utm' => $utm] + $dati, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

$oggetto = 'Nuova richiesta: ' . $dati['Nome'] . ($dati['Azienda'] ? ' (' . $dati['Azienda'] . ')' : '') . ($pagina ? ' · ' . $pagina : '');
$oggetto = '=?UTF-8?B?' . base64_encode($oggetto) . '?=';
$nome = preg_replace('/[^\p{L}\p{N} .\'-]/u', '', $dati['Nome']);
$intestazioni = implode("\r\n", [
    'From: =?UTF-8?B?' . base64_encode('Sito fabiofregoni.com') . '?= <' . MITTENTE . '>',
    'Reply-To: =?UTF-8?B?' . base64_encode($nome) . '?= <' . $dati['Email'] . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$inviata = @mail(DESTINATARIO, $oggetto, $testo, $intestazioni, '-f' . MITTENTE);
fine((bool)$inviata, $inviata ? 200 : 500);
