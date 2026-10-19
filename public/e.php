<?php
/*
 * (v28) Lo que se abre si alguien escanea el QR de una ENTRADA con la cámara normal del celular.
 * Solo informa si la entrada es válida; NO la marca como usada (eso lo hace el lector de la puerta).
 */
require __DIR__ . '/../app/bootstrap.php';
$t = preg_match('/^T[0-9a-f]{20}$/', get('c')) ? fila('SELECT t.*, l.nombre AS loc, l.tipo, v.titulo, v.fecha, v.hora, v.liga_id FROM bol_tickets t JOIN bol_localidades l ON l.id = t.localidad_id JOIN bol_ventas v ON v.id = t.venta_id WHERE t.codigo = ?', [get('c')]) : null;
$liga = $t ? fila('SELECT * FROM ligas WHERE id = ?', [$t['liga_id']]) : null;
exigir_modulo_portal($liga, 'entradas'); // (Anexo B)
cortar_liga_publica($liga, false, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible»
cabecera_publica('Entrada', $liga);
if (!$t): ?>
  <div class="tarjeta login"><h1>✗ Entrada no válida</h1><p>Este código no es una entrada de <?= e(marca()) ?>.</p></div>
<?php else:
  $col = ['valido' => '#1a9e5c', 'usado' => '#6b7572', 'anulado' => '#d7263d'][$t['estado']] ?? '#6b7572'; ?>
  <div class="tarjeta login" style="text-align:center">
    <div class="estado-grande" style="background:<?= $col ?>"><?= ['valido' => '✓ ENTRADA VÁLIDA', 'usado' => 'YA SE USÓ', 'anulado' => '✗ ANULADA'][$t['estado']] ?? '' ?></div>
    <h2><?= $t['tipo'] === 'parqueo' ? '🚗 ' : '🎟 ' ?><?= e($t['loc']) ?></h2>
    <p><b><?= e($t['titulo']) ?></b><br><?= e(fecha_bonita($t['fecha'])) ?> <?= e($t['hora']) ?></p>
    <p class="suave">A nombre de <?= e($t['titular']) ?><?= $t['placa'] !== '' ? ' · placa ' . e($t['placa']) : '' ?><?= $t['estado'] === 'usado' ? '<br>Ingresó: ' . e(substr($t['usado_en'], 0, 16)) . ($t['puerta'] !== '' ? ' · ' . e($t['puerta']) : '') : '' ?></p>
    <p class="suave" style="font-size:.8rem">La entrada se valida en la puerta con el lector de la liga.</p>
  </div>
<?php endif;
pie_publico();
