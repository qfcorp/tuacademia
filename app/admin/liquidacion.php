<?php
/* (v8) Liquidación de árbitros: cuánto se le debe a cada árbitro por los partidos que pitó, pago y recibo */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];

// (v13) Factura que entregó el árbitro (foto o PDF, privada)
if (get('factura') !== '') {
    $pa = fila('SELECT factura FROM pagos_arbitro WHERE id = ? AND liga_id = ?', [(int)get('factura'), $lid]);
    servir_factura((string)($pa['factura'] ?? ''));
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'pagar') {
        $aid = (int)post('arbitro_id');
        if (!fila('SELECT id FROM arbitros WHERE id = ? AND liga_id = ?', [$aid, $lid])) throw new RuntimeException('Árbitro no encontrado.');
        $fecha = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
        $factura = subir_factura('factura'); // (v13) opcional
        try {
            $pid = pagar_arbitro($lid, $aid, (array)($_POST['partidos'] ?? []), array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', $fecha, post('nota'), $por);
        } catch (Throwable $ex) {
            borrar_factura($factura);
            throw $ex;
        }
        if ($factura !== '' || post('factura_numero') !== '') {
            q('UPDATE pagos_arbitro SET factura = ?, factura_numero = ?, factura_subida = ? WHERE id = ?', [$factura, limpiar_numero_factura(post('factura_numero')), $factura !== '' ? date('Y-m-d H:i') . ' · ' . $por : '', $pid]);
        }
        $pa = fila('SELECT * FROM pagos_arbitro WHERE id = ?', [$pid]);
        if ($ch = tg_chat_arbitro($aid)) tg_encolar($lid, $ch, '💵 <b>' . e($liga['nombre']) . '</b> le registró un pago de ' . dinero($pa['monto']) . ' por ' . (int)$pa['partidos'] . ' partido(s).', '', 'parb:' . $pid);
        flash('Pago registrado: ' . dinero($pa['monto']) . ' por ' . (int)$pa['partidos'] . ' partido(s). Quedó como gasto en Finanzas.');
        ir('admin.php?p=liquidacion&recibo=' . $pid);
    }
    if ($accion === 'factura') {
        $pa = fila('SELECT * FROM pagos_arbitro WHERE id = ? AND liga_id = ? AND anulado = 0', [(int)post('id'), $lid]) ?? throw new RuntimeException('Pago no encontrado.');
        adjuntar_factura('pagos_arbitro', $pa, 'factura', post('factura_numero'), $por);
        flash('Factura guardada en el pago N° ' . (int)$pa['id'] . '.');
        ir('admin.php?p=liquidacion');
    }
    if ($accion === 'anular') {
        $pa = fila('SELECT * FROM pagos_arbitro WHERE id = ? AND liga_id = ? AND anulado = 0', [(int)post('id'), $lid]) ?? throw new RuntimeException('Pago no encontrado.');
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE pagos_arbitro SET anulado = 1 WHERE id = ?', [$pa['id']]);
        q('DELETE FROM pagos_arbitro_partidos WHERE pago_id = ?', [$pa['id']]);
        if ($pa['movimiento_id']) q('UPDATE movimientos SET anulado = 1 WHERE id = ?', [$pa['movimiento_id']]);
        $pdo->commit();
        flash('Pago anulado: esos partidos vuelven a quedar pendientes.');
        ir('admin.php?p=liquidacion');
    }
}

/* Comprobante de pago al árbitro (imprimible, para que lo firme) */
if (get('recibo') !== '') {
    $pa = fila('SELECT pa.*, a.nombre, a.cedula FROM pagos_arbitro pa JOIN arbitros a ON a.id = pa.arbitro_id WHERE pa.id = ? AND pa.liga_id = ?', [(int)get('recibo'), $lid]) ?? throw new RuntimeException('Pago no encontrado.');
    $det = filas('SELECT pap.*, p.fecha, p.jornada, p.fase, el.nombre AS l, ev.nombre AS v, t.nombre AS torneo FROM pagos_arbitro_partidos pap JOIN partidos p ON p.id = pap.partido_id
                  JOIN torneos t ON t.id = p.torneo_id JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id WHERE pap.pago_id = ? ORDER BY p.fecha, p.id', [$pa['id']]);
    doc_inicio('Pago a árbitro ' . $pa['nombre'], 'admin.php?p=liquidacion');
    doc_cabecera_financiera($liga, 'LIQUIDACIÓN DE HONORARIOS DE ARBITRAJE N° ' . str_pad((string)$pa['id'], 5, '0', STR_PAD_LEFT));
    ?>
  <?php if ($pa['anulado']): ?><div class="marcador-doc" style="color:#b00;border-color:#b00">PAGO ANULADO</div><?php endif; ?>
  <div class="meta" style="grid-template-columns:1fr 1fr">
    <div><b>Árbitro:</b> <?= e($pa['nombre']) ?></div><div><b>C.I.:</b> <?= e($pa['cedula']) ?></div>
    <div><b>Fecha de pago:</b> <?= e($pa['fecha']) ?></div><div><b>Forma de pago:</b> <?= e(formas_pago()[$pa['forma_pago']] ?? $pa['forma_pago']) ?></div>
    <?php if ($pa['nota'] !== ''): ?><div style="grid-column:1/-1"><b>Nota:</b> <?= e($pa['nota']) ?></div><?php endif; ?>
  </div>
  <table class="pl"><tr><th>Fecha</th><th>Campeonato</th><th>Partido</th><th>Función</th><th style="width:90px">Valor</th></tr>
    <?php foreach ($det as $d): ?><tr><td><?= e($d['fecha']) ?></td><td><?= e($d['torneo']) ?></td><td><?= e($d['l'] . ' vs ' . $d['v']) ?></td><td><?= e($d['rol']) ?></td><td><?= dinero($d['monto']) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="4" style="text-align:right"><b>TOTAL (<?= (int)$pa['partidos'] ?> partidos)</b></td><td><b><?= dinero($pa['monto']) ?></b></td></tr>
  </table>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Recibí conforme: <?= e($pa['nombre']) ?></div><div>Tesorería · <?= e($pa['registrado_por']) ?></div></div>
  <?php doc_factura($pa, 'admin.php?p=liquidacion&factura=' . (int)$pa['id']); ?>
    <?php
    doc_fin();
    exit;
}

$pend = honorarios_pendientes($lid);
$ver = get('arbitro') !== '' ? (int)get('arbitro') : 0;
$pagos = filas('SELECT pa.*, a.nombre FROM pagos_arbitro pa JOIN arbitros a ON a.id = pa.arbitro_id WHERE pa.liga_id = ? ORDER BY pa.id DESC LIMIT 60', [$lid]);
$sin_tarifa = (int)valor('SELECT COUNT(*) FROM torneos WHERE liga_id = ? AND estado <> \'finalizado\' AND pago_central = 0 AND pago_asistente = 0 AND pago_cuarto = 0', [$lid]);
cabecera_admin('Pago a árbitros');
?>
<p><a href="admin.php?p=finanzas">← Finanzas</a></p>
<h1>🧑‍⚖️ Pago a árbitros</h1>
<p class="suave">El sistema suma lo que se le debe a cada árbitro por los partidos jugados, según su función (central, asistente, cuarto). El valor por partido se pone en cada campeonato: <b>Configuración → 💵 Dinero</b>. Al pagar, queda como gasto en Finanzas y se imprime el comprobante para que el árbitro lo firme.</p>
<?php if ($sin_tarifa): ?><div class="aviso aviso-info"><?= $sin_tarifa ?> campeonato(s) en curso no tienen valor de arbitraje por partido: sus partidos aparecen con $0,00.</div><?php endif; ?>

<h2>Pendiente de pago</h2>
<?php if (!$pend): ?><div class="tarjeta">No hay partidos pendientes de pago a árbitros. ✓</div><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th>Árbitro</th><th class="num">Partidos</th><th class="num">Se le debe</th><th></th></tr>
  <?php foreach ($pend as $aid => $x): ?><tr><td><b><?= e($x['arbitro']['nombre']) ?></b></td><td class="num"><?= count($x['partidos']) ?></td><td class="num pts"><?= dinero($x['total']) ?></td>
    <td><a class="btn btn-sec btn-chico" href="admin.php?p=liquidacion&amp;arbitro=<?= (int)$aid ?>#pagar">Ver y pagar</a></td></tr><?php endforeach; ?>
  <tr><td><b>Total</b></td><td class="num"><?= array_sum(array_map(fn($x) => count($x['partidos']), $pend)) ?></td><td class="num pts"><?= dinero(array_sum(array_column($pend, 'total'))) ?></td><td></td></tr>
</table></div>
<?php endif; ?>

<?php if ($ver && isset($pend[$ver])): $x = $pend[$ver]; ?>
<div class="tarjeta" id="pagar" style="border:2px solid var(--acento)">
  <h2 style="margin-top:0">Pagar a <?= e($x['arbitro']['nombre']) ?></h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="pagar"><input type="hidden" name="arbitro_id" value="<?= (int)$ver ?>">
    <div class="tabla-scroll"><table>
      <tr><th><input type="checkbox" checked onclick="document.querySelectorAll('.chk-p').forEach(function (c) { c.checked = this.checked; }, this); sumar()"></th><th>Fecha</th><th>Partido</th><th>Función</th><th class="num">Valor</th></tr>
      <?php foreach ($x['partidos'] as $d): ?><tr><td><input type="checkbox" class="chk-p" name="partidos[]" value="<?= (int)$d['partido']['id'] ?>" data-m="<?= e($d['monto']) ?>" checked onchange="sumar()"></td>
        <td><?= e($d['partido']['fecha']) ?></td><td><?= e($d['partido']['local_nombre'] . ' vs ' . $d['partido']['visitante_nombre']) ?> <small class="suave"><?= e($d['partido']['torneo']) ?></small></td><td><?= e($d['rol']) ?></td><td class="num"><?= dinero($d['monto']) ?></td></tr><?php endforeach; ?>
    </table></div>
    <p style="font-size:1.2rem">Total a pagar: <b id="total-arb"><?= dinero($x['total']) ?></b></p>
    <div class="campos">
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
      <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Nota</label><input type="text" name="nota" maxlength="200"></div>
      <div><label>🧾 Factura del árbitro (opcional)</label><input type="file" name="factura" accept="image/*,application/pdf"><div class="ayuda">Foto o PDF, si le entregó factura. También se puede subir después.</div></div>
      <div><label>N° de factura (opcional)</label><input type="text" name="factura_numero" maxlength="40" placeholder="001-001-000000123"></div>
    </div>
    <button class="btn" onclick="return confirm('¿Registrar el pago?')">💵 Registrar pago e imprimir comprobante</button>
  </form>
</div>
<script>
function sumar() {
  var t = 0; document.querySelectorAll('.chk-p').forEach(function (c) { if (c.checked) t += parseFloat(c.dataset.m) || 0; });
  document.getElementById('total-arb').textContent = '$' + t.toFixed(2);
}
</script>
<?php endif; ?>

<?php if ($pagos): ?>
<h2>Pagos realizados</h2>
<div class="tabla-scroll"><table>
  <tr><th>N°</th><th>Fecha</th><th>Árbitro</th><th class="num">Partidos</th><th class="num">Valor</th><th>Factura</th><th></th></tr>
  <?php foreach ($pagos as $pa): ?><tr style="<?= $pa['anulado'] ? 'opacity:.45;text-decoration:line-through' : '' ?>"><td><?= (int)$pa['id'] ?></td><td><?= e($pa['fecha']) ?></td><td><?= e($pa['nombre']) ?></td><td class="num"><?= (int)$pa['partidos'] ?></td><td class="num"><?= dinero($pa['monto']) ?></td>
    <td><?= html_factura($pa, 'admin.php?p=liquidacion&factura=' . (int)$pa['id'], '<input type="hidden" name="id" value="' . (int)$pa['id'] . '">', puede('finanzas', true)) ?></td>
    <td class="acciones"><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=liquidacion&amp;recibo=<?= (int)$pa['id'] ?>">🖨 Comprobante</a>
      <?php if (!$pa['anulado'] && puede('finanzas', true)): ?><form class="inline" method="post" onsubmit="return confirm('¿Anular este pago? Los partidos vuelven a quedar pendientes.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$pa['id'] ?>"><button class="btn btn-sec btn-chico">Anular</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
