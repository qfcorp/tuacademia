<?php
/*
 * VERIFICACIÓN DE CARNET (lo que se abre al escanear el QR del carnet)
 * Muestra la foto, el equipo y si el jugador está HABILITADO para jugar hoy.
 * Tiene un reloj en vivo para que no se pueda mostrar una captura de pantalla vieja.
 */
require __DIR__ . '/../app/bootstrap.php';

// (v11) Carnet de un alumno de escuela: carnet.php?a=TOKEN
if (preg_match('/^[0-9a-f]{20}$/', get('a'))) {
    $al = fila("SELECT a.*, g.nombre AS grupo FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.carnet_token = ?", [get('a')]);
    $esc = $al ? fila('SELECT * FROM ligas WHERE id = ?', [$al['liga_id']]) : null;
    cortar_liga_publica($esc, false, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible»
    if (!$al || !$esc) {
        http_response_code(404);
        cabecera_publica('Carnet no válido');
        echo '<div class="tarjeta" style="text-align:center"><h1 style="color:var(--roja)">✗ CARNET NO VÁLIDO</h1><p>Este código no corresponde a ningún alumno, o el carnet fue anulado.</p></div>';
        pie_publico();
        exit;
    }
    $ep = estado_pagos((int)$al['id']);
    $ok = $al['estado'] === 'activo';
    $color = $ok ? '#1a9e5c' : '#6b7572';
    cabecera_publica('Carnet · ' . nombre_alumno($al), $esc);
    ?>
<div class="carnet-verif tarjeta" style="border-top:10px solid <?= $color ?>">
  <div class="estado-grande" style="background:<?= $color ?>"><?= $ok ? '✓ ALUMNO ACTIVO' : '✗ NO ACTIVO' ?></div>
  <div class="carnet-foto"><?= foto_alumno($al, 'foto-verif') ?></div>
  <h1 style="text-align:center;margin:.4rem 0"><?= e(nombre_alumno($al)) ?></h1>
  <p style="text-align:center;margin:0"><?= e($esc['nombre']) ?> · <?= e(edad_o_categoria($al, $esc)) // (arreglos 14-oct) ?><?= $al['grupo'] ? ' · ' . e($al['grupo']) : '' ?></p>
  <div style="text-align:center"><?= html_deportes_alumno($al, $esc) ?></div>
  <?php if ($al['cedula'] !== ''): ?><p style="text-align:center">C.I. <?= e(cedula_oculta($al['cedula'])) ?></p><?php endif; ?>
  <?php if ($al['tipo_sangre'] || $al['alergias']): ?><p style="text-align:center">🩺 <?= e(trim(($al['tipo_sangre'] ? 'Sangre ' . $al['tipo_sangre'] : '') . ($al['alergias'] ? ' · Alergias: ' . $al['alergias'] : ''))) ?></p><?php endif; ?>
  <p style="text-align:center">Emergencia: <a href="tel:<?= e($al['emergencia_telefono'] ?: $al['representante_telefono']) ?>"><?= e($al['emergencia_telefono'] ?: $al['representante_telefono']) ?></a></p>
  <p class="reloj-verif" id="reloj"></p>
</div>
<script>(function () { var r = document.getElementById('reloj'); function t() { var d = new Date(); r.textContent = d.toLocaleDateString('es-EC') + ' ' + d.toLocaleTimeString('es-EC'); } t(); setInterval(t, 1000); })();</script>
<?php
    pie_publico();
    exit;
}

$c = get('c');
$j = null;
if (preg_match('/^[0-9a-f]{20}$/', $c)) {
    $j = fila('SELECT j.*, e.nombre AS equipo, e.escudo, e.color, e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.carnet_token = ?', [$c]);
}
if (!$j) {
    http_response_code(404);
    cabecera_publica('Carnet no válido');
    echo '<div class="tarjeta" style="text-align:center"><h1 style="color:var(--roja)">✗ CARNET NO VÁLIDO</h1><p>Este código no corresponde a ningún jugador, o el carnet fue anulado por la liga.</p></div>';
    pie_publico();
    exit;
}
$liga = fila('SELECT * FROM ligas WHERE id = ?', [$j['liga_id']]);
cortar_liga_publica($liga, false, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible»
sincronizar_liga((int)$liga['id']);
$st = estado_jugador($j);
$colores = ['habilitado' => '#1a9e5c', 'suspendido' => '#d7263d', 'baja' => '#6b7572'];
$textos = ['habilitado' => '✓ HABILITADO', 'suspendido' => '✗ SUSPENDIDO', 'baja' => '✗ NO HABILITADO'];
cabecera_publica('Carnet · ' . nombre_jugador($j), $liga);
?>
<div class="carnet-verif tarjeta" style="border-top:10px solid <?= $colores[$st['estado']] ?>">
  <div class="estado-grande" style="background:<?= $colores[$st['estado']] ?>"><?= $textos[$st['estado']] ?></div>
  <div class="carnet-foto"><?= foto_jugador($j, 'foto-verif') ?></div>
  <h1 style="text-align:center;margin:.4rem 0 0"><?= e(nombre_jugador($j)) ?></h1>
  <p style="text-align:center;margin:.2rem 0" class="eq-celda" ><span style="margin:auto;display:flex;gap:8px;align-items:center"><?= escudo($j['escudo'], $j['equipo'], $j['color']) ?> <b><?= e($j['equipo']) ?></b><?= $j['numero'] !== null ? ' · #' . (int)$j['numero'] : '' ?></span></p>
  <p style="text-align:center" class="suave"><?= e($liga['nombre']) ?><?= $j['cedula'] !== '' ? ' · C.I. ' . e(cedula_oculta($j['cedula'])) : '' ?><?= edad($j['fecha_nac']) !== null ? ' · ' . edad($j['fecha_nac']) . ' años' : '' ?></p>
  <?php foreach ($st['detalle'] as $d): ?><div class="aviso aviso-error"><?= e($d) ?></div><?php endforeach; ?>
  <?php foreach ($st['deuda'] as $d): ?><div class="aviso aviso-error">Su equipo tiene una deuda pendiente con la liga (<?= e($d['torneo']) ?>).</div><?php endforeach; ?>
  <p class="reloj-verif" id="reloj"></p>
  <p class="suave" style="text-align:center;font-size:.8rem">Verificación en línea. Compare la FOTO con la persona. La hora de arriba debe ser la hora actual (así se detectan capturas de pantalla).</p>
</div>
<script>
(function () {
  var r = document.getElementById('reloj');
  function t() { var d = new Date(); r.textContent = 'Verificado: ' + d.toLocaleDateString('es-EC') + ' ' + d.toLocaleTimeString('es-EC'); }
  t(); setInterval(t, 1000);
})();
</script>
<?php
pie_publico();
