<?php
/* (v12) MI ROL DE PAGO: el profesor ve sus grupos, los días que dio clase, lo que le corresponde este mes y sus roles de pago. */
$liga = requiere_liga();
$yo = usuario();
$prof = profesor_del_usuario($yo);
if (!$prof) {
    flash('Su usuario no está vinculado a un profesor de la escuela.', 'error');
    ir('admin.php');
}
// (v13) Su factura: la ve y, si todavía no la subió la escuela, la puede subir él mismo
if (get('factura') !== '') {
    $pp = fila('SELECT factura FROM esc_pagos_profesor WHERE id = ? AND profesor_id = ?', [(int)get('factura'), $prof['id']]);
    servir_factura((string)($pp['factura'] ?? ''));
}
if (es_post() && post('accion') === 'factura') {
    $pp = fila('SELECT * FROM esc_pagos_profesor WHERE id = ? AND profesor_id = ? AND anulado = 0', [(int)post('pago_id'), $prof['id']]) ?? throw new RuntimeException('Rol de pago no encontrado.');
    adjuntar_factura('esc_pagos_profesor', $pp, 'factura', post('factura_numero'), $yo['nombre']);
    flash('Su factura quedó guardada. La escuela ya la puede ver.');
    ir('admin.php?p=mi_rol');
}
if (get('rol') !== '') {
    $pp = fila('SELECT * FROM esc_pagos_profesor WHERE id = ? AND profesor_id = ?', [(int)get('rol'), $prof['id']]) ?? throw new RuntimeException('Rol de pago no encontrado.');
    doc_rol_pago($pp, $prof, $liga, 'admin.php?p=mi_rol', 'admin.php?p=mi_rol&factura=' . (int)$pp['id']);
    exit;
}
$periodo = preg_match('/^\d{4}-\d{2}$/', get('mes')) ? get('mes') : date('Y-m');
$liq = liquidacion_profesor($prof, $periodo);
$grupos = filas("SELECT g.*, (SELECT COUNT(*) FROM esc_alumnos a WHERE (a.grupo_id = g.id OR a.id IN (SELECT alumno_id FROM esc_alumno_grupos WHERE grupo_id = g.id)) AND a.estado = 'activo') AS n FROM esc_grupos g WHERE g.profesor_id = ? AND g.activo = 1 ORDER BY g.nombre", [$prof['id']]);
$roles = filas('SELECT * FROM esc_pagos_profesor WHERE profesor_id = ? AND anulado = 0 ORDER BY periodo DESC, id DESC LIMIT 36', [$prof['id']]);
$total_anio = (float)valor("SELECT COALESCE(SUM(monto), 0) FROM esc_pagos_profesor WHERE profesor_id = ? AND anulado = 0 AND substr(periodo, 1, 4) = ?", [$prof['id'], date('Y')]);
cabecera_admin('Mi rol de pago');
?>
<h1>💰 Mi rol de pago</h1>
<div class="cifras">
  <div class="cifra"><b><?= $liq['clases'] ?></b>Clases dictadas en <?= e(nombre_mes($periodo)) ?></div>
  <div class="cifra"><b><?= array_sum(array_column($grupos, 'n')) ?></b>Alumnos en mis grupos</div>
  <div class="cifra"><b><?= dinero($liq['monto']) ?></b>Me corresponde en <?= e(nombre_mes($periodo)) ?></div>
  <div class="cifra"><b><?= dinero(max(0, $liq['saldo'])) ?></b>Por cobrar del mes</div>
  <div class="cifra"><b><?= dinero($total_anio) ?></b>Recibido en <?= date('Y') ?></div>
</div>
<form method="get" class="acciones"><input type="hidden" name="p" value="mi_rol"><label>Mes: <input type="month" name="mes" value="<?= e($periodo) ?>" onchange="this.form.submit()"></label></form>
<div class="rejilla-2">
  <div class="tarjeta">
    <h2 style="margin-top:0">Cómo se me paga</h2>
    <p><?= e(tipos_pago_profesor()[$prof['tipo_pago']] ?? $prof['tipo_pago']) ?>: <b><?= dinero($prof['valor_pago']) ?></b><?= (float)$prof['iess_pct'] > 0 ? ' · aporte IESS ' . e((string)(float)$prof['iess_pct']) . '%' : '' ?></p>
    <h3>Días que di clase en <?= e(nombre_mes($periodo)) ?></h3>
    <p><?= $liq['dias'] ? e(implode(' · ', array_map(fn($d) => date('d/m', strtotime($d)), $liq['dias']))) : '<span class="suave">Todavía no hay clases registradas este mes.</span>' ?><?= $liq['faltas'] ? '<br><small class="suave">Clases marcadas sin profesor: ' . $liq['faltas'] . '</small>' : '' ?></p>
  </div>
  <div class="tarjeta">
    <h2 style="margin-top:0">Mis grupos</h2>
    <?php if (!$grupos): ?><p class="suave">Todavía no tiene grupos asignados.</p><?php endif; ?>
    <?php foreach ($grupos as $g): ?><p style="margin:.3rem 0">• <b><?= e($g['nombre']) ?></b> — <?= e(texto_categoria_grupo($g)) ?> · <?= e(texto_horario($g)) ?> · <?= (int)$g['n'] ?> alumnos</p><?php endforeach; ?>
  </div>
</div>
<h2>Mis roles de pago</h2>
<?php if (!$roles): ?><p class="suave">Todavía no hay pagos registrados.</p><?php else: ?>
<p class="suave" style="margin-top:0">Si usted factura sus servicios, suba aquí la foto o el PDF de la factura de cada mes (🧾).</p>
<div class="tabla-scroll"><table>
  <tr><th>Mes</th><th>Fecha de pago</th><th class="num">Ingresos</th><th class="num">Descuentos</th><th class="num">Neto recibido</th><th>Mi factura</th><th></th></tr>
  <?php foreach ($roles as $r): $ing = (float)$r['base'] > 0 || (float)$r['bonos'] > 0 ? (float)$r['base'] + (float)$r['bonos'] : (float)$r['monto']; ?>
    <tr><td><?= e(nombre_mes($r['periodo'])) ?></td><td><?= e($r['fecha']) ?></td><td class="num"><?= dinero($ing) ?></td><td class="num"><?= dinero((float)$r['iess'] + (float)$r['anticipos'] + (float)$r['otros_desc']) ?></td><td class="num"><b><?= dinero($r['monto']) ?></b></td>
      <td><?= html_factura($r, 'admin.php?p=mi_rol&factura=' . (int)$r['id'], '<input type="hidden" name="pago_id" value="' . (int)$r['id'] . '">', true) ?></td>
      <td><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=mi_rol&amp;rol=<?= (int)$r['id'] ?>">🖨 Ver rol de pago</a></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
