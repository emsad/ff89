<?php
// Mostra i file caricati con il modulo "Analisi gratuita". Serve il codice segreto della richiesta (k).
header('X-Robots-Tag: noindex, nofollow');
$k = $_GET['k'] ?? '';
if (!preg_match('~^[a-f0-9]{32}$~', $k)) { http_response_code(404); exit('Non trovato'); }
$dir = (preg_replace('~/public_html(/.*)?$~', '', __DIR__) ?: dirname(__DIR__)) . '/richieste-sito/analisi/' . $k;
if (!is_dir($dir)) { http_response_code(404); exit('Non trovato'); }
$file = array_values(array_filter(scandir($dir), fn($f) => !in_array($f, ['.', '..', 'stato.json', 'richiesta.json'], true)));
$f = $_GET['f'] ?? '';
if ($f !== '') {
    $f = basename($f);
    if (!in_array($f, $file, true)) { http_response_code(404); exit('Non trovato'); }
    $tipi = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'svg' => 'image/svg+xml'];
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($tipi[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: ' . (isset($tipi[$ext]) && $ext !== 'svg' ? 'inline' : 'attachment') . '; filename="' . $f . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
    readfile($dir . '/' . $f); exit;
}
$dati = json_decode(@file_get_contents($dir . '/richiesta.json'), true) ?: [];
header('Content-Type: text/html; charset=utf-8');
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><title>File — ' . $e($dati['attivita'] ?? '') . '</title>';
echo '<style>body{font:15px/1.5 system-ui;margin:24px;background:#0a0a0a;color:#eee}h1{font-size:20px}a{color:#ff4d00}.g{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}.g a{display:block;background:#1a1a1a;border-radius:10px;padding:8px;text-decoration:none;color:#ccc;word-break:break-all}.g img{width:100%;height:160px;object-fit:contain;background:#111;border-radius:6px}</style>';
echo '<h1>' . $e($dati['attivita'] ?? '') . ' — ' . $e($dati['nome'] ?? '') . '</h1><p>' . count($file) . ' file. Cancellati automaticamente dopo 90 giorni.</p><div class="g">';
foreach ($file as $x) {
    $u = '?k=' . $k . '&f=' . rawurlencode($x);
    $img = preg_match('~\.(jpe?g|png|webp)$~i', $x) ? '<img src="' . $e($u) . '" alt="">' : '';
    echo '<a href="' . $e($u) . '" target="_blank">' . $img . $e($x) . '</a>';
}
echo '</div>';
