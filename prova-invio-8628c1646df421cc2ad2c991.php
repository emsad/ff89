<?php
// File temporaneo di prova: manda UNA email a un indirizzo fisso per controllare la consegna su Gmail. Da cancellare dopo la prova.
header('Content-Type: text/plain; charset=utf-8'); header('X-Robots-Tag: noindex');
require __DIR__ . '/posta-lib.php';
$testo = "Ciao Fabio,\n\nquesta è un'email di prova inviata dalla casella report@fabiofregoni.com, come quelle che riceveranno i clienti con il report dell'analisi.\n\nSe la leggi in Posta in arrivo, la consegna funziona.\n\nFabio Fregoni\nfabiofregoni.com\n";
echo ff_invia_email('report@fabiofregoni.com', 'fabiofrego89@gmail.com', 'Prova invio report — fabiofregoni.com', $testo, ['Fabio Fregoni', 'info@fabiofregoni.com']) ? 'inviata' : 'errore';
