<?php
/* (v12) COBROS CON TARJETA de la liga o escuela: activar, quién paga la comisión, cuenta para las transferencias,
 * reporte de sus pagos (solo los suyos) y liquidaciones recibidas de TuVocalía. */
$liga = requiere_liga();
$lid = (int)$liga['id'];

if (es_post() && post('accion') === 'config') {
    if (!puede('config', true) && !puede('finanzas', true) && !puede('cobros', true)) throw new RuntimeException('Su perfil no puede cambiar esta configuración.');
    $activar = post('pagos_tarjeta') === '1';
    if ($activar && trim(post('cuenta_liquidacion')) === '') throw new RuntimeException('Escriba la cuenta bancaria donde ' . marca() . ' le transferirá lo cobrado.');
    q('UPDATE ligas SET pagos_tarjeta = ?, pagos_comision = ?, cuenta_liquidacion = ? WHERE id = ?', [
        $activar ? 1 : 0, post('pagos_comision') === 'cliente' ? 'cliente' : 'organizacion', mb_substr(post('cuenta_liquidacion'), 0, 500), $lid,
    ]);
    flash($activar ? 'Cobros con tarjeta activados: sus ' . (es_escuela($liga) ? 'familias ven el botón "Pagar con tarjeta" en el portal de padres.' : 'delegados ven el botón "Pagar con tarjeta" en su estado de cuenta.') : 'Cobros con tarjeta desactivados.');
    ir('admin.php?p=pagos_linea');
}

$desde = get('desde') !== '' && strtotime(get('desde')) ? date('Y-m-d', strtotime(get('desde'))) : date('Y-m-01', strtotime('-2 months'));
$hasta = get('hasta') !== '' && strtotime(get('hasta')) ? date('Y-m-d', strtotime(get('hasta'))) : date('Y-m-d');
$estado = in_array(get('estado'), ['pagado', 'todos'], true) ? get('estado') : 'pagado';
$pagos = filas("SELECT pl.*, e.nombre AS equipo FROM pagos_linea pl LEFT JOIN equipos e ON e.id = pl.equipo_id
                WHERE pl.liga_id = ? AND pl.destino = 'organizacion' AND substr(COALESCE(NULLIF(pl.pagado_en, ''), pl.creado), 1, 10) BETWEEN ? AND ? " . ($estado === 'pagado' ? "AND pl.estado = 'pagado'" : '') . '
                ORDER BY pl.id DESC LIMIT 500', [$lid, $desde, $hasta]);
$tot = ['total' => 0, 'pasarela' => 0, 'tuv' => 0, 'neto' => 0];
foreach ($pagos as $x) if ($x['estado'] === 'pagado') { $tot['total'] += $x['total']; $tot['pasarela'] += $x['comision_pasarela']; $tot['tuv'] += $x['comision_tuvocalia']; $tot['neto'] += $x['neto']; }
$res = resumen_cobros_org($lid);
$liqs = filas('SELECT * FROM liquidaciones_org WHERE liga_id = ? ORDER BY id DESC LIMIT 24', [$lid]);
$activo_sistema = pasarela_activa();

if (get('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pagos_tarjeta_' . $desde . '_' . $hasta . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['N°', 'Fecha', 'Pagador', 'Detalle', 'Tarjeta', 'Valor', 'Recargo', 'Total cobrado', 'Comisión tarjeta + IVA', 'Gestión ' . marca(), 'Neto para la organización', 'Estado', 'Aplicado', 'Liquidación'], ';');
    foreach ($pagos as $x) fputcsv($f, [$x['id'], $x['pagado_en'] ?: $x['creado'], $x['pagador_nombre'], $x['detalle'], $x['tipo_tarjeta'], $x['monto_base'], $x['recargo'], $x['total'], $x['comision_pasarela'], $x['comision_tuvocalia'], $x['neto'], nombre_estado_pago_linea($x['estado']), $x['aplicado'], $x['liquidacion_id'] ?: 'Pendiente'], ';');
    fclose($f);
    exit;
}
cabecera_admin('Cobros con tarjeta');
?>
<p><a href="admin.php?p=<?= es_escuela($liga) ? 'cobros' : 'finanzas' ?>">← <?= es_escuela($liga) ? 'Pagos' : 'Finanzas' ?></a></p>
<h1>💳 Cobros con tarjeta</h1>
<p class="suave">Sus <?= es_escuela($liga) ? 'familias' : 'equipos' ?> pagan con tarjeta de crédito o débito desde su celular. <?= e(marca()) ?> recibe el dinero por medio de Plux, aplica el pago y emite el recibo al instante, y le transfiere lo cobrado a su cuenta. Costo: <?= e(texto_tasas()) ?>.</p>
<?php if (!$activo_sistema): ?><div class="aviso aviso-info">El cobro con tarjeta se está terminando de conectar con Plux. Ya puede dejarlo configurado; el botón de pago aparecerá cuando esté listo.</div><?php endif; ?>

<div class="cifras">
  <div class="cifra"><b><?= dinero($res['cobrado']) ?></b>Cobrado con tarjeta (total)</div>
  <div class="cifra"><b><?= dinero($res['comisiones']) ?></b>Comisiones</div>
  <div class="cifra"><b><?= dinero($res['neto']) ?></b>Neto para su <?= es_escuela($liga) ? 'escuela' : 'liga' ?></div>
  <div class="cifra"><b style="color:var(--acento)"><?= dinero($res['por_liquidar']) ?></b>Por transferirle (<?= (int)$res['n_por_liquidar'] ?> pagos)</div>
</div>

<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="config">
  <h2 style="margin-top:0">Configuración</h2>
  <label class="check"><input type="checkbox" name="pagos_tarjeta" value="1" <?= (int)$liga['pagos_tarjeta'] === 1 ? 'checked' : '' ?>> Recibir pagos con tarjeta de mis <?= es_escuela($liga) ? 'familias' : 'equipos' ?></label>
  <p style="margin:.6rem 0 .2rem"><b>¿Quién paga el costo de la tarjeta?</b></p>
  <label class="check" style="font-weight:400"><input type="radio" name="pagos_comision" value="organizacion" <?= $liga['pagos_comision'] !== 'cliente' ? 'checked' : '' ?>> Lo asume la <?= es_escuela($liga) ? 'escuela' : 'liga' ?> (el que paga ve el valor exacto; a usted le llega el valor menos la comisión)</label>
  <label class="check" style="font-weight:400"><input type="radio" name="pagos_comision" value="cliente" <?= $liga['pagos_comision'] === 'cliente' ? 'checked' : '' ?>> Se le suma al que paga (ej: debe <?= dinero(20) ?> → paga <?= dinero(calcular_cobro(20, 'cliente')['total']) ?>; a usted le llegan sus <?= dinero(20) ?>)</label>
  <p><label>Cuenta donde le transferimos lo cobrado *</label><textarea name="cuenta_liquidacion" rows="3" maxlength="500" placeholder="Banco · tipo y número de cuenta · titular · cédula o RUC"><?= e($liga['cuenta_liquidacion']) ?></textarea></p>
  <button class="btn">Guardar</button>
</form>

<h2>Reporte de pagos</h2>
<form method="get" class="acciones"><input type="hidden" name="p" value="pagos_linea">
  <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label> <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
  <select name="estado"><option value="pagado" <?= $estado === 'pagado' ? 'selected' : '' ?>>Solo pagados</option><option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos (incluye rechazados y sin completar)</option></select>
  <button class="btn btn-sec btn-chico">Ver</button> <a class="btn btn-sec btn-chico" href="admin.php?p=pagos_linea&amp;desde=<?= e($desde) ?>&amp;hasta=<?= e($hasta) ?>&amp;estado=<?= e($estado) ?>&amp;csv=1">⬇ Excel (CSV)</a></form>
<?php if (!$pagos): ?><p class="suave">No hay pagos en esas fechas.</p><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th>N°</th><th>Fecha</th><th>Pagó</th><th>Detalle</th><th class="num">Total cobrado</th><th class="num">Comisiones</th><th class="num">Neto</th><th>Estado</th><th>Transferido</th></tr>
  <?php foreach ($pagos as $x): ?>
    <tr><td><?= (int)$x['id'] ?></td><td><?= e(substr($x['pagado_en'] ?: $x['creado'], 0, 16)) ?></td><td><?= e($x['pagador_nombre']) ?><?= $x['equipo'] ? '<br><small>' . e($x['equipo']) . '</small>' : '' ?></td>
      <td style="white-space:normal;font-size:.85rem"><?= e($x['detalle']) ?><?= $x['aplicado'] !== '' ? '<br><small class="suave">' . e($x['aplicado']) . '</small>' : '' ?></td>
      <td class="num"><?= dinero($x['total']) ?><?= $x['tipo_tarjeta'] ? '<br><small>' . e($x['tipo_tarjeta'] === 'debito' ? 'débito' : 'crédito') . '</small>' : '' ?></td>
      <td class="num"><?= $x['estado'] === 'pagado' ? dinero((float)$x['comision_pasarela'] + (float)$x['comision_tuvocalia']) : '—' ?></td>
      <td class="num"><b><?= $x['estado'] === 'pagado' ? dinero($x['neto']) : '—' ?></b></td><td><?= chip_pago_linea($x['estado']) ?></td>
      <td><?= $x['liquidacion_id'] ? 'Sí (N° ' . (int)$x['liquidacion_id'] . ')' : ($x['estado'] === 'pagado' ? 'Pendiente' : '') ?></td></tr>
  <?php endforeach; ?>
  <tr><th colspan="4">Totales (pagados)</th><th class="num"><?= dinero($tot['total']) ?></th><th class="num"><?= dinero($tot['pasarela'] + $tot['tuv']) ?></th><th class="num"><?= dinero($tot['neto']) ?></th><th colspan="2"></th></tr>
</table></div>
<p class="suave" style="font-size:.85rem">Comisiones = tarjeta (<?= e(texto_tasas()) ?>).</p>
<?php endif; ?>

<?php if ($liqs): ?>
<h2>Transferencias recibidas de <?= e(marca()) ?></h2>
<div class="tabla-scroll"><table><tr><th>N°</th><th>Fecha</th><th class="num">Pagos</th><th class="num">Cobrado</th><th class="num">Comisiones</th><th class="num">Transferido</th><th>Referencia</th></tr>
  <?php foreach ($liqs as $l): ?><tr><td><?= (int)$l['id'] ?></td><td><?= e($l['fecha']) ?></td><td class="num"><?= (int)$l['pagos'] ?></td><td class="num"><?= dinero($l['cobrado']) ?></td><td class="num"><?= dinero($l['comisiones']) ?></td><td class="num"><b><?= dinero($l['neto']) ?></b></td><td><?= e(trim($l['forma'] . ' ' . $l['referencia'])) ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
