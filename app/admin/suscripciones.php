<?php
/* VENTAS (solo superadministrador): suscripciones de las ligas, pagos y renovaciones */
if (!es_super()) ir('admin.php');
$yo = usuario();

// (v33) Foto del comprobante enviado por un cliente
if (get('comprobante') !== '') {
    servir_comprobante(fila('SELECT * FROM solicitudes_pago WHERE id = ?', [(int)get('comprobante')]) ?? ['archivo' => '']);
}
if (es_post() && post('accion') === 'aprobar_comprobante') {
    $r = aprobar_solicitud_pago((int)post('id'), $yo['nombre'], max(0, round((float)str_replace(',', '.', post('monto')), 2)), post('nota'));
    flash('Comprobante aprobado: pago registrado y cuenta activa hasta el ' . $r['hasta'] . '. Le avisamos al cliente.');
    ir('admin.php?p=suscripciones#comprobantes');
}
if (es_post() && post('accion') === 'rechazar_comprobante') {
    rechazar_solicitud_pago((int)post('id'), $yo['nombre'], post('nota'));
    flash('Comprobante rechazado. El cliente recibió el motivo y puede volver a enviarlo.');
    ir('admin.php?p=suscripciones#comprobantes');
}
if (es_post() && post('accion') === 'pago') {
    $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('liga_id')]) ?? throw new RuntimeException('Elija la liga.');
    $meses = entero(post('meses'), 1, 36);
    $monto = max(0, round((float)str_replace(',', '.', post('monto')), 2));
    $plan = (int)post('plan_id') ?: ($l['plan_id'] ? (int)$l['plan_id'] : null);
    // (Tukán Central v2) la misma función que usa el Panel Tukán Central (vigencia, comisión, referidos y correo al cliente)
    $r = venta_registrar_pago($l, $plan, $meses, $monto, post('forma_pago'), post('nota'),
        post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d'), $yo['nombre']);
    [$hasta, $com] = [$r['hasta'], $r['comision']];
    flash('Pago registrado. ' . $l['nombre'] . ' queda activa hasta el ' . $hasta . '.' . ($com ? ' Comisión para ' . $com['nombre'] . ': ' . dinero($com['comision']) . '.' : ''));
    ir('admin.php?p=suscripciones');
}
if (es_post() && post('accion') === 'anular_pago') {
    // (Tukán Central v2) si el pago era el que dejó la fecha de vencimiento, la fecha vuelve a la del pago anterior
    $an = anular_pago_suscripcion((int)post('id'), null, 'Eliminado en Ventas');
    flash('Pago eliminado del historial.' . ($an['cambio'] ? ' ' . ($an['liga']['nombre'] ?? '') . ' vuelve a vencer el ' . $an['vence'] . ' (la fecha del pago anterior).'
        : ($an['sin_pagos'] && $an['liga'] ? ' No le quedan pagos: la fecha de vencimiento (' . ($an['vence'] ?: 'sin fecha') . ') no se cambió; ajústela en Ligas → Editar si hace falta.' : '')));
    ir('admin.php?p=suscripciones');
}

$ligas = filas('SELECT l.*, p.nombre AS plan, p.precio_mensual, p.precio_anual, (SELECT COUNT(*) FROM equipos e WHERE e.liga_id = l.id) AS n_eq,
                       (SELECT MAX(u.ultimo_ingreso) FROM usuarios u WHERE u.liga_id = l.id) AS ultimo
                FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE COALESCE(l.eliminada, \'\') = \'\' ORDER BY CASE WHEN l.vence = \'\' THEN 1 ELSE 0 END, l.vence'); // (Anexo A) sin la papelera
$por_estado = ['prueba' => 0, 'activa' => 0, 'gracia' => 0, 'vencida' => 0, 'sin_limite' => 0];
foreach ($ligas as &$l) {
    $l['sus'] = estado_suscripcion($l);
    $por_estado[$l['sus']['estado']]++;
}
unset($l);
$mes = (float)valor("SELECT COALESCE(SUM(monto),0) FROM pagos_suscripcion WHERE substr(fecha,1,7) = ?", [date('Y-m')]);
$anio = (float)valor("SELECT COALESCE(SUM(monto),0) FROM pagos_suscripcion WHERE substr(fecha,1,4) = ?", [date('Y')]);
$pagos = filas("SELECT ps.*, l.nombre AS liga, p.nombre AS plan FROM pagos_suscripcion ps JOIN ligas l ON l.id = ps.liga_id LEFT JOIN planes p ON p.id = ps.plan_id WHERE COALESCE(l.eliminada, '') = '' ORDER BY ps.id DESC LIMIT 50"); // (Anexo A) sin la papelera
$planes = filas('SELECT * FROM planes ORDER BY orden');
$sel = (int)get('liga');
$chip = ['sin_limite' => 'chip-ok', 'activa' => 'chip-ok', 'prueba' => 'chip-pend', 'gracia' => 'chip-mal', 'vencida' => 'chip-mal'];
$nom = ['sin_limite' => 'Sin vencimiento', 'activa' => 'Activa', 'prueba' => 'Prueba', 'gracia' => 'En gracia', 'vencida' => 'Vencida'];
cabecera_admin('Ventas');
?>
<div class="fila-titulo"><h1>💳 Ventas y suscripciones</h1><div class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=planes">Planes y precios</a> <a class="btn btn-sec btn-chico" href="admin.php?p=ligas&amp;nueva=1">+ Nueva liga</a> <a class="btn btn-sec btn-chico" target="_blank" href="index.php">Ver página de venta ↗</a></div></div>
<div class="cifras">
  <div class="cifra"><b><?= dinero($mes) ?></b>Cobrado este mes</div>
  <div class="cifra"><b><?= dinero($anio) ?></b>Cobrado en <?= date('Y') ?></div>
  <div class="cifra"><b><?= $por_estado['activa'] ?></b>Ligas pagando</div>
  <div class="cifra"><b><?= $por_estado['prueba'] ?></b>En demo</div>
  <div class="cifra"><b><?= $por_estado['gracia'] + $por_estado['vencida'] ?></b>Vencidas / en gracia</div>
</div>

<?= html_comprobantes_por_revisar() // (v33) ?>
<div class="tarjeta" id="pago">
  <h2 style="margin-top:0">Registrar pago de una liga (renovar)</h2>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="pago">
    <div class="campos">
      <div><label>Liga</label><select name="liga_id" required id="pg-liga"><option value="">— elegir —</option><?php foreach ($ligas as $l): ?><option value="<?= (int)$l['id'] ?>" data-plan="<?= (int)$l['plan_id'] ?>" data-combo="<?= e((string)factor_combo($l)) ?>" <?= $sel === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Plan</label><select name="plan_id" id="pg-plan"><?php foreach ($planes as $pl): ?><option value="<?= (int)$pl['id'] ?>" data-precios="<?= e(json_encode(array_map(fn($x) => $x['precio'], array_column(opciones_precio($pl), null, 'meses')))) ?>" data-m="<?= (float)$pl['precio_mensual'] ?>"><?= e($pl['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Meses que paga</label><select name="meses" id="pg-meses"><?php foreach ([1, 2, 3, 6, 12, 24] as $m): ?><option value="<?= $m ?>"><?= $m ?> mes<?= $m > 1 ? 'es' : '' ?><?= $m === 12 ? ' (anual)' : '' ?></option><?php endforeach; ?></select></div>
      <div><label>Valor recibido ($)</label><input type="number" name="monto" id="pg-monto" min="0" step="0.01" required></div>
      <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'transferencia' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Fecha del pago</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
      <div class="campo-ancho"><label>Nota</label><input type="text" name="nota" maxlength="200" placeholder="N° de transferencia, factura…"></div>
    </div>
    <p class="ayuda">Si la cuenta está en <b>combo liga + escuela</b>, el valor ya viene con el <?= e((string)(float)combo_descuento()) ?>% de descuento. La vigencia se suma desde la fecha de vencimiento actual (si aún no vence) o desde hoy. La liga sale de la demo.</p>
    <button class="btn">Registrar pago y renovar</button>
  </form>
</div>
<script>
(function () {
  var liga = document.getElementById('pg-liga'), plan = document.getElementById('pg-plan'), meses = document.getElementById('pg-meses'), monto = document.getElementById('pg-monto');
  function calc() { var o = plan.options[plan.selectedIndex]; if (!o) return; var m = parseInt(meses.value, 10), pm = parseFloat(o.dataset.m), pr = JSON.parse(o.dataset.precios || '{}');
    // (v12) usa el precio del período (trimestre, semestre, año); si no hay, el mensual × meses (el año × N para 24)
    var v = pr[m] !== undefined ? pr[m] : (m % 12 === 0 && pr[12] !== undefined ? pr[12] * m / 12 : (m % 6 === 0 && pr[6] !== undefined ? pr[6] * m / 6 : (m % 3 === 0 && pr[3] !== undefined ? pr[3] * m / 3 : pm * m)));
    // (v16) combo liga + escuela: descuento en el plan de esta cuenta
    var lo = liga.options[liga.selectedIndex], f = lo && lo.dataset.combo ? parseFloat(lo.dataset.combo) : 1;
    monto.value = (+v * (f > 0 ? f : 1)).toFixed(2); }
  liga.addEventListener('change', function () { var p = liga.options[liga.selectedIndex].dataset.plan; if (p && p !== '0') plan.value = p; calc(); });
  plan.addEventListener('change', calc); meses.addEventListener('change', calc);
  if (liga.value) liga.dispatchEvent(new Event('change')); else calc();
})();
</script>

<h2>Ligas</h2>
<div class="tabla-scroll"><table>
  <tr><th>Liga</th><th>Estado</th><th>Plan</th><th>Vence</th><th class="num">Equipos</th><th>Contacto</th><th>Último ingreso</th><th></th></tr>
  <?php foreach ($ligas as $l): $wa = telefono_wa((string)$l['contacto_telefono']); ?>
    <tr><td><b><?= e($l['nombre']) ?></b><br><small><?= e($l['ciudad']) ?></small></td>
      <td><span class="estado-chip <?= $chip[$l['sus']['estado']] ?>"><?= e($nom[$l['sus']['estado']]) ?></span><?= $l['sus']['dias'] !== null ? '<br><small>' . ($l['sus']['dias'] >= 0 ? 'faltan ' . $l['sus']['dias'] . ' día' . ($l['sus']['dias'] === 1 ? '' : 's') : 'hace ' . (-$l['sus']['dias']) . ' día' . ($l['sus']['dias'] === -1 ? '' : 's')) . '</small>' : '' ?></td>
      <td><?= e($l['plan'] ?? '—') ?></td><td><?= e($l['vence'] ?: '—') ?></td><td class="num"><?= (int)$l['n_eq'] ?></td>
      <td style="white-space:normal"><?= e($l['contacto_nombre']) ?><br><small><?= e($l['contacto_telefono']) ?> <?= e($l['contacto_email']) ?></small></td>
      <td><small><?= e($l['ultimo'] ?: 'nunca') ?></small></td>
      <td class="acciones">
        <a class="btn btn-chico" href="admin.php?p=suscripciones&amp;liga=<?= (int)$l['id'] ?>#pago">Registrar pago</a>
        <a class="btn btn-sec btn-chico" href="admin.php?p=ligas&amp;editar=<?= (int)$l['id'] ?>">Editar</a>
        <?php if ($wa && in_array($l['sus']['estado'], ['prueba', 'gracia', 'vencida'], true)): ?>
          <a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $l['contacto_nombre'] . ', le saluda ' . ajuste('empresa') . '. ' . ($l['sus']['estado'] === 'prueba' ? 'Su prueba gratis de ' . marca() . ' para ' . $l['nombre'] . ' termina el ' . $l['vence'] . '. ¿Le ayudamos a elegir su plan?' : 'La suscripción de ' . $l['nombre'] . ' venció el ' . $l['vence'] . '. ¿Le ayudamos a renovarla para seguir usando el sistema?')) ?>">📲 Recordar</a>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
</table></div>

<h2>Pagos recibidos</h2>
<?php if (!$pagos): ?><p class="suave">Aún no hay pagos registrados.</p><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th>Fecha</th><th>Liga</th><th>Plan</th><th class="num">Meses</th><th>Vigencia</th><th class="num">Valor</th><th>Forma</th><th>Nota</th><th></th></tr>
  <?php foreach ($pagos as $x): ?>
    <tr><td><?= e($x['fecha']) ?></td><td><?= e($x['liga']) ?></td><td><?= e($x['plan'] ?? '—') ?></td><td class="num"><?= (int)$x['meses'] ?></td><td><?= e($x['desde'] . ' → ' . $x['hasta']) ?></td>
      <td class="num"><?= dinero($x['monto']) ?></td><td><?= e(nombre_forma_pago($x['forma_pago'])) ?></td><td><?= e($x['nota']) ?></td>
      <td><form class="inline" method="post" onsubmit="return confirm('¿Eliminar este pago del historial?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular_pago"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
