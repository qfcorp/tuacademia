<?php
/* (v12) COBROS CON TARJETA — superadministrador: todo lo que entró por Plux, lo que se gana TuAcademia,
 * lo que hay que transferir a cada liga o escuela (liquidaciones) y las suscripciones pagadas con tarjeta. */
if (!es_super()) ir('admin.php');
$yo = usuario();

if (es_post() && post('accion') === 'liquidar') {
    $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('liga_id')]) ?? throw new RuntimeException('Organización no encontrada.');
    $pend = filas("SELECT * FROM pagos_linea WHERE liga_id = ? AND destino = 'organizacion' AND estado = 'pagado' AND liquidacion_id IS NULL", [$l['id']]);
    if (!$pend) throw new RuntimeException('No hay pagos por transferir a esa organización.');
    $cobrado = array_sum(array_column($pend, 'total'));
    $com = array_sum(array_map(fn($x) => (float)$x['comision_pasarela'] + (float)$x['comision_tuvocalia'], $pend));
    $neto = array_sum(array_column($pend, 'neto'));
    $pdo = db();
    $pdo->beginTransaction();
    q('INSERT INTO liquidaciones_org (liga_id, pagos, cobrado, comisiones, neto, fecha, forma, referencia, nota, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        $l['id'], count($pend), round($cobrado, 2), round($com, 2), round($neto, 2), post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d'),
        array_key_exists(post('forma'), formas_pago()) ? post('forma') : 'transferencia', mb_substr(post('referencia'), 0, 80), mb_substr(post('nota'), 0, 200), $yo['nombre'],
    ]);
    $liq = (int)$pdo->lastInsertId();
    q('UPDATE pagos_linea SET liquidacion_id = ? WHERE id IN (' . implode(',', array_map('intval', array_column($pend, 'id'))) . ')', [$liq]);
    $pdo->commit();
    aviso_admins($l, '🏦 <b>' . e(marca()) . ' le transfirió ' . dinero($neto) . '</b>' . "\nPor " . count($pend) . ' pago(s) con tarjeta. Detalle en Cobros con tarjeta.', 'liq:' . $liq);
    flash('Transferencia registrada: ' . dinero($neto) . ' a ' . $l['nombre'] . ' (' . count($pend) . ' pagos).');
    ir('admin.php?p=pasarela');
}

$modo = pasarela_modo();
$t = fila("SELECT COALESCE(SUM(total), 0) AS cobrado, COALESCE(SUM(comision_pasarela), 0) AS plux, COALESCE(SUM(comision_tuvocalia), 0) AS tuv,
                  COALESCE(SUM(CASE WHEN destino = 'organizacion' AND liquidacion_id IS NULL THEN neto END), 0) AS por_liquidar,
                  COALESCE(SUM(CASE WHEN destino = 'tuvocalia' THEN total END), 0) AS suscripciones
           FROM pagos_linea WHERE estado = 'pagado'");
$orgs = filas("SELECT l.id, l.nombre, l.tipo, l.cuenta_liquidacion, l.pagos_comision, COUNT(pl.id) AS n, SUM(pl.total) AS cobrado, SUM(pl.comision_tuvocalia) AS tuv,
                      SUM(CASE WHEN pl.liquidacion_id IS NULL THEN pl.neto ELSE 0 END) AS por_liquidar, SUM(CASE WHEN pl.liquidacion_id IS NULL THEN 1 ELSE 0 END) AS n_pend
               FROM pagos_linea pl JOIN ligas l ON l.id = pl.liga_id WHERE pl.destino = 'organizacion' AND pl.estado = 'pagado' GROUP BY l.id ORDER BY por_liquidar DESC, l.nombre");
$ultimos = filas('SELECT pl.*, l.nombre AS org FROM pagos_linea pl JOIN ligas l ON l.id = pl.liga_id ORDER BY pl.id DESC LIMIT 100');
$liqs = filas('SELECT lq.*, l.nombre AS org FROM liquidaciones_org lq JOIN ligas l ON l.id = lq.liga_id ORDER BY lq.id DESC LIMIT 50');
cabecera_admin('Cobros con tarjeta');
?>
<div class="fila-titulo"><h1>💳 Cobros con tarjeta</h1><a class="btn btn-sec btn-chico" href="admin.php?p=ajustes#pasarela">⚙️ Configurar (modo, Plux y comisiones)</a></div>
<p>Modo actual: <b><?= ['apagado' => 'Apagado (no se muestran botones de pago)', 'prueba' => 'PRUEBA (solo usted puede aprobar pagos simulados)', 'plux' => 'Plux (pagos reales)'][$modo] ?></b> · <?= e(texto_tasas()) ?></p>
<div class="cifras">
  <div class="cifra"><b><?= dinero($t['cobrado']) ?></b>Cobrado con tarjeta</div>
  <div class="cifra"><b><?= dinero($t['tuv']) ?></b>Ganancia <?= e(marca()) ?> (gestión administrativa)</div>
  <div class="cifra"><b><?= dinero($t['suscripciones']) ?></b>Suscripciones pagadas con tarjeta</div>
  <div class="cifra"><b><?= dinero($t['plux']) ?></b>Comisiones de Plux (con IVA)</div>
  <div class="cifra"><b style="color:var(--roja)"><?= dinero($t['por_liquidar']) ?></b>Por transferir a las organizaciones</div>
</div>

<h2>Por organización</h2>
<?php if (!$orgs): ?><p class="suave">Todavía no hay cobros de ligas o escuelas a sus equipos o familias.</p><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th>Organización</th><th class="num">Pagos</th><th class="num">Cobrado</th><th class="num">Ganancia <?= e(marca()) ?></th><th class="num">Por transferir</th><th>Cuenta para transferir</th><th></th></tr>
  <?php foreach ($orgs as $o): ?>
    <tr><td><b><?= e($o['nombre']) ?></b><br><small><?= $o['tipo'] === 'escuela' ? 'Escuela' : 'Liga' ?> · comisión la paga <?= $o['pagos_comision'] === 'cliente' ? 'el cliente' : 'la organización' ?></small></td>
      <td class="num"><?= (int)$o['n'] ?></td><td class="num"><?= dinero($o['cobrado']) ?></td><td class="num"><?= dinero($o['tuv']) ?></td>
      <td class="num"><b><?= dinero($o['por_liquidar']) ?></b><br><small><?= (int)$o['n_pend'] ?> pagos</small></td>
      <td style="white-space:normal;font-size:.85rem"><?= nl2br(e($o['cuenta_liquidacion'] ?: '— sin cuenta —')) ?></td>
      <td><?php if ((float)$o['por_liquidar'] > 0): ?>
        <form method="post" onsubmit="return confirm('¿Registrar la transferencia de <?= e(dinero($o['por_liquidar'])) ?> a <?= e(addslashes($o['nombre'])) ?>?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="liquidar"><input type="hidden" name="liga_id" value="<?= (int)$o['id'] ?>">
          <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" style="width:140px"> <select name="forma"><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option><option value="otro">Otro</option></select>
          <input type="text" name="referencia" maxlength="80" placeholder="N° de transferencia" style="width:150px"> <button class="btn btn-chico">Registrar transferencia</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<h2>Últimos pagos</h2>
<?php if (!$ultimos): ?><p class="suave">Todavía no hay pagos con tarjeta.</p><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th>N°</th><th>Fecha</th><th>Organización</th><th>Concepto</th><th>Pagó</th><th class="num">Total</th><th class="num">Plux</th><th class="num"><?= e(marca()) ?></th><th class="num">Neto</th><th>Estado</th></tr>
  <?php foreach ($ultimos as $x): ?>
    <tr><td><a target="_blank" href="pagar.php?t=<?= e($x['token']) ?>"><?= (int)$x['id'] ?></a></td><td><?= e(substr($x['pagado_en'] ?: $x['creado'], 0, 16)) ?></td><td><?= e($x['org']) ?></td>
      <td style="white-space:normal;font-size:.85rem"><?= e(nombre_concepto_pago($x['concepto'])) ?><br><small class="suave"><?= e($x['detalle']) ?></small></td><td><?= e($x['pagador_nombre']) ?></td>
      <td class="num"><?= dinero($x['total']) ?></td><td class="num"><?= $x['estado'] === 'pagado' ? dinero($x['comision_pasarela']) : '—' ?></td><td class="num"><?= $x['estado'] === 'pagado' ? dinero($x['comision_tuvocalia']) : '—' ?></td>
      <td class="num"><?= $x['estado'] === 'pagado' ? dinero($x['neto']) : '—' ?></td><td><?= chip_pago_linea($x['estado']) ?><?= $x['pasarela'] === 'prueba' ? ' <span class="etiqueta amarilla">prueba</span>' : '' ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if ($liqs): ?>
<h2>Transferencias hechas a las organizaciones</h2>
<div class="tabla-scroll"><table><tr><th>N°</th><th>Fecha</th><th>Organización</th><th class="num">Pagos</th><th class="num">Cobrado</th><th class="num">Comisiones</th><th class="num">Transferido</th><th>Referencia</th><th>Registró</th></tr>
  <?php foreach ($liqs as $l): ?><tr><td><?= (int)$l['id'] ?></td><td><?= e($l['fecha']) ?></td><td><?= e($l['org']) ?></td><td class="num"><?= (int)$l['pagos'] ?></td><td class="num"><?= dinero($l['cobrado']) ?></td><td class="num"><?= dinero($l['comisiones']) ?></td><td class="num"><b><?= dinero($l['neto']) ?></b></td><td><?= e(trim($l['forma'] . ' ' . $l['referencia'])) ?></td><td><?= e($l['registrado_por']) ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
