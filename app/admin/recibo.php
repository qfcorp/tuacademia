<?php
/* Recibo de pago o estado de cuenta imprimible (PDF) */
$liga = requiere_liga();
$lid = (int)$liga['id'];
if (get('estado') !== '') {
    $eq = equipo_de_liga((int)get('estado'), $lid);
    sincronizar_liga($lid);
    doc_estado_cuenta($eq, $liga, 'admin.php?p=finanzas&equipo=' . (int)$eq['id']);
    return;
}
$m = movimiento_recibo((int)get('id'), $lid);
if (!$m) throw new RuntimeException('Recibo no encontrado.');
doc_recibo($m, $liga, 'admin.php?p=finanzas&equipo=' . (int)$m['equipo_id']);
