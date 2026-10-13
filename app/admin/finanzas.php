<?php
/* FINANZAS DE LA LIGA: cuentas por equipo, pagos con recibo, cargos, egresos y caja */
$liga = requiere_liga();
$lid = (int)$liga['id'];
sincronizar_liga($lid);
$u = usuario();
$por = $u['nombre'];
$equipos = filas('SELECT id, nombre, escudo, color FROM equipos WHERE liga_id = ? ORDER BY nombre', [$lid]);
$torneos = filas('SELECT id, nombre, temporada FROM torneos WHERE liga_id = ? ORDER BY id DESC', [$lid]);
$ver = get('ver', get('equipo') !== '' ? 'equipo' : 'resumen');

// (v8) Foto de un comprobante enviado por un delegado (se guarda fuera de la web)
if (get('comprobante') !== '') {
    $cp = fila('SELECT * FROM comprobantes WHERE id = ? AND liga_id = ?', [(int)get('comprobante'), $lid]);
    $abs = $cp ? RAIZ . '/data/' . $cp['archivo'] : '';
    if (!$cp || !is_file($abs) || str_contains($cp['archivo'], '..')) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    readfile($abs);
    exit;
}

function torneo_valido(string $v, int $lid): ?int
{
    $id = (int)$v;
    return $id && fila('SELECT id FROM torneos WHERE id = ? AND liga_id = ?', [$id, $lid]) ? $id : null;
}

if (es_post()) {
    $accion = post('accion');
    $fecha = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
    $monto = round((float)str_replace(',', '.', post('monto')), 2);
    if ($accion === 'pago') {
        $eq = equipo_de_liga((int)post('equipo_id'), $lid);
        $conc = array_key_exists(post('concepto'), conceptos_cargo()) ? post('concepto') : 'otro';
        $id = registrar_pago($lid, torneo_valido(post('torneo_id'), $lid), (int)$eq['id'], $monto, $conc, post('descripcion') ?: 'Pago de ' . conceptos_cargo()[$conc],
            array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', post('comprobante'), $fecha, $por);
        flash('Pago registrado. Recibo N° ' . str_pad((string)valor('SELECT recibo FROM movimientos WHERE id = ?', [$id]), 6, '0', STR_PAD_LEFT) . '.');
        ir('admin.php?p=finanzas&equipo=' . (int)$eq['id'] . '&recibo=' . $id);
    }
    if ($accion === 'comp_aprobar') {
        $c = fila('SELECT * FROM comprobantes WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('Comprobante no encontrado.');
        if ($monto <= 0) throw new RuntimeException('El valor debe ser mayor a cero.');
        $mid = aprobar_comprobante($c, $monto, post('concepto'), $por);
        aviso_delegados($liga, (int)$c['equipo_id'], '✅ Su pago de ' . dinero($monto) . ' fue aprobado. Recibo N° ' . str_pad((string)valor('SELECT recibo FROM movimientos WHERE id = ?', [$mid]), 6, '0', STR_PAD_LEFT) . '.', 'compok:' . (int)$c['id']);
        flash('Comprobante aprobado: pago registrado con recibo N° ' . str_pad((string)valor('SELECT recibo FROM movimientos WHERE id = ?', [$mid]), 6, '0', STR_PAD_LEFT) . '.');
        ir('admin.php?p=finanzas&ver=comprobantes');
    }
    if ($accion === 'comp_rechazar') {
        $c = fila("SELECT * FROM comprobantes WHERE id = ? AND liga_id = ? AND estado = 'pendiente'", [(int)post('id'), $lid]) ?? throw new RuntimeException('Comprobante no encontrado.');
        if (mb_strlen(post('respuesta')) < 3) throw new RuntimeException('Escriba el motivo del rechazo (el delegado lo verá).');
        q("UPDATE comprobantes SET estado = 'rechazado', respuesta = ?, revisado_por = ?, revisado_en = ? WHERE id = ?", [mb_substr(post('respuesta'), 0, 200), $por, date('Y-m-d H:i'), $c['id']]);
        aviso_delegados($liga, (int)$c['equipo_id'], '❌ Su comprobante de ' . dinero($c['monto']) . ' fue rechazado: ' . e(post('respuesta')), 'compno:' . (int)$c['id']);
        flash('Comprobante rechazado. El delegado verá el motivo.');
        ir('admin.php?p=finanzas&ver=comprobantes');
    }
    if ($accion === 'cargo') {
        $eq = equipo_de_liga((int)post('equipo_id'), $lid);
        if ($monto <= 0) throw new RuntimeException('El valor debe ser mayor a cero.');
        $conc = array_key_exists(post('concepto'), conceptos_cargo()) ? post('concepto') : 'otro';
        q("INSERT INTO movimientos (liga_id, torneo_id, equipo_id, tipo, concepto, descripcion, monto, fecha, registrado_por) VALUES (?,?,?, 'cargo', ?,?,?,?,?)",
            [$lid, torneo_valido(post('torneo_id'), $lid), $eq['id'], $conc, mb_substr(post('descripcion') ?: conceptos_cargo()[$conc], 0, 200), $monto, $fecha, $por]);
        flash('Cargo registrado a ' . $eq['nombre'] . '.');
        ir('admin.php?p=finanzas&equipo=' . (int)$eq['id']);
    }
    if ($accion === 'egreso') {
        if ($monto <= 0) throw new RuntimeException('El valor debe ser mayor a cero.');
        $conc = array_key_exists(post('concepto'), conceptos_egreso()) ? post('concepto') : 'otro';
        q("INSERT INTO movimientos (liga_id, torneo_id, tipo, concepto, descripcion, monto, fecha, forma_pago, comprobante, registrado_por) VALUES (?,?, 'egreso', ?,?,?,?,?,?,?)",
            [$lid, torneo_valido(post('torneo_id'), $lid), $conc, mb_substr(post('descripcion') ?: conceptos_egreso()[$conc], 0, 200), $monto, $fecha,
             array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', mb_substr(post('comprobante'), 0, 100), $por]);
        flash('Gasto registrado.');
        ir('admin.php?p=finanzas&ver=egresos');
    }
    if ($accion === 'anular') {
        $m = fila('SELECT * FROM movimientos WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        if (!$m) throw new RuntimeException('Movimiento no encontrado.');
        q('UPDATE movimientos SET anulado = 1, descripcion = ? WHERE id = ?', [mb_substr($m['descripcion'] . ' [ANULADO por ' . $por . ' el ' . date('Y-m-d H:i') . post('motivo') . ']', 0, 400), $m['id']]);
        if ($m['ref'] && preg_match('/^pago_multa_(j|e):(\d+)$/', $m['ref'], $mm)) {
            q('UPDATE ' . ($mm[1] === 'j' ? 'sanciones' : 'sanciones_equipo') . ' SET pagada = 0 WHERE id = ?', [(int)$mm[2]]);
            q('UPDATE movimientos SET ref = NULL WHERE id = ?', [$m['id']]);
        }
        flash('Movimiento anulado (queda registrado como anulado).');
        ir($_SERVER['HTTP_REFERER'] ?? 'admin.php?p=finanzas');
    }
    ir('admin.php?p=finanzas');
}

/* Descarga en Excel (v18: archivo .xlsx real, con los valores como números) */
if (get('csv') === '1') {
    require_once APP_DIR . '/excel.php';
    bitacora_evento('exportacion', 'Descargó en Excel todos los movimientos de dinero');
    $movs = filas('SELECT m.*, e.nombre AS equipo, t.nombre AS torneo FROM movimientos m LEFT JOIN equipos e ON e.id = m.equipo_id LEFT JOIN torneos t ON t.id = m.torneo_id WHERE m.liga_id = ? ORDER BY m.fecha, m.id', [$lid]);
    $filas_x = [['Fecha', 'Tipo', 'Concepto', 'Equipo', 'Campeonato', 'Descripción', 'Valor', 'Forma de pago', 'Comprobante / factura', 'Proveedor', 'Recibo', 'Anulado', 'Registrado por']];
    foreach ($movs as $m) {
        $conc = $m['tipo'] === 'egreso' ? (conceptos_egreso()[$m['concepto']] ?? $m['concepto']) : (conceptos_cargo()[$m['concepto']] ?? $m['concepto']);
        $filas_x[] = [$m['fecha'], $m['tipo'] === 'egreso' ? 'Gasto' : ucfirst($m['tipo']), $conc, $m['equipo'] ?? '', $m['torneo'] ?? '', $m['descripcion'], (float)$m['monto'], formas_pago()[$m['forma_pago']] ?? '',
            ($m['factura'] ?? '') !== '' ? $m['factura'] : $m['comprobante'], $m['proveedor'] ?? '', $m['recibo'] !== null ? (int)$m['recibo'] : '', $m['anulado'] ? 'Sí' : '', $m['registrado_por']];
    }
    $bin = crear_xlsx($filas_x, 'Movimientos', [11, 9, 22, 22, 20, 44, 12, 14, 20, 24, 8, 8, 22]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="finanzas_' . $liga['slug'] . '_' . date('Ymd') . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

$tot = fila("SELECT
    COALESCE(SUM(CASE WHEN tipo = 'cargo' THEN monto END), 0) AS cargos,
    COALESCE(SUM(CASE WHEN tipo = 'pago' THEN monto END), 0) AS pagos,
    COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto END), 0) AS egresos
    FROM movimientos WHERE liga_id = ? AND anulado = 0", [$lid]);
$por_equipo = filas("SELECT e.id, e.nombre, e.escudo, e.color,
        COALESCE(SUM(CASE WHEN m.tipo = 'cargo' THEN m.monto END), 0) AS cargos,
        COALESCE(SUM(CASE WHEN m.tipo = 'pago' THEN m.monto END), 0) AS pagos
    FROM equipos e LEFT JOIN movimientos m ON m.equipo_id = e.id AND m.anulado = 0
    WHERE e.liga_id = ? GROUP BY e.id ORDER BY (COALESCE(SUM(CASE WHEN m.tipo = 'cargo' THEN m.monto END), 0) - COALESCE(SUM(CASE WHEN m.tipo = 'pago' THEN m.monto END), 0)) DESC, e.nombre", [$lid]);
$por_cobrar = array_sum(array_map(fn($x) => max(0, $x['cargos'] - $x['pagos']), $por_equipo));

function form_pago(array $equipos, array $torneos, ?int $equipo_id = null, float $sugerido = 0): void
{
    ?>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="pago">
    <div class="campos">
      <div><label>Equipo</label><select name="equipo_id" required><option value="">— elegir —</option><?= opciones_equipos($equipos, $equipo_id) ?></select></div>
      <div><label>Valor ($)</label><input type="number" name="monto" min="0.01" step="0.01" required value="<?= $sugerido > 0 ? e(number_format($sugerido, 2, '.', '')) : '' ?>"></div>
      <div><label>Por concepto de</label><select name="concepto"><?php foreach (conceptos_cargo() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>N° de comprobante / transferencia</label><input type="text" name="comprobante" maxlength="100"></div>
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
      <div><label>Campeonato</label><select name="torneo_id"><option value="">—</option><?php foreach ($torneos as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre'] . ' ' . $t['temporada']) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Detalle</label><input type="text" name="descripcion" maxlength="200" placeholder="Ej: abono inscripción + arbitrajes fecha 3"></div>
    </div>
    <button class="btn" type="submit">Registrar pago y generar recibo</button>
  </form>
    <?php
}

cabecera_admin('Finanzas');
?>
<div class="fila-titulo">
  <h1>💵 Finanzas</h1>
  <span class="acciones"><?php if (modulo_menu($liga, 'pagos_tarjeta')): ?><a class="btn btn-sec btn-chico" href="admin.php?p=pagos_linea">💳 Cobros con tarjeta</a> <?php endif; ?><a class="btn btn-sec btn-chico" href="admin.php?p=liquidacion">🧑‍⚖️ Pago a árbitros</a> <a class="btn btn-sec btn-chico" href="admin.php?p=finanzas&amp;csv=1">⬇ Descargar todo en Excel</a><?php if (modulo_menu($liga, 'contabilidad')): ?> <a class="btn btn-sec btn-chico" href="admin.php?p=contabilidad">📒 Contabilidad y SRI</a><?php endif; ?></span>
</div>
<nav class="pestanas">
  <?php $n_comp = comprobantes_pendientes($lid);
  foreach (['resumen' => 'Cuentas por equipo', 'pago' => '+ Registrar pago', 'comprobantes' => '📤 Comprobantes' . ($n_comp ? ' (' . $n_comp . ')' : ''), 'egresos' => 'Gastos de la liga', 'movimientos' => 'Todos los movimientos'] as $k => $txt): ?>
    <a class="<?= $ver === $k ? 'activa' : '' ?>" href="admin.php?p=finanzas&amp;ver=<?= $k ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<div class="cifras">
  <div class="cifra"><b class="saldo-debe"><?= dinero($por_cobrar) ?></b>Por cobrar a equipos</div>
  <div class="cifra"><b><?= dinero($tot['pagos']) ?></b>Cobrado</div>
  <div class="cifra"><b><?= dinero($tot['egresos']) ?></b>Gastos</div>
  <div class="cifra"><b class="<?= $tot['pagos'] - $tot['egresos'] >= 0 ? 'saldo-ok' : 'saldo-debe' ?>"><?= dinero($tot['pagos'] - $tot['egresos']) ?></b>En caja (cobrado − gastos)</div>
</div>

<?php if ($ver === 'equipo'):
    $eq = equipo_de_liga((int)get('equipo'), $lid);
    $movs = filas('SELECT m.*, t.nombre AS torneo FROM movimientos m LEFT JOIN torneos t ON t.id = m.torneo_id WHERE m.equipo_id = ? ORDER BY m.fecha, m.id', [$eq['id']]);
    $saldo = saldo_equipo((int)$eq['id']);
    if (get('recibo') !== ''): ?>
      <div class="aviso aviso-ok">✓ Pago registrado. <a class="btn btn-chico" target="_blank" href="admin.php?p=recibo&amp;id=<?= (int)get('recibo') ?>">🖨 Imprimir / PDF del recibo</a></div>
    <?php endif; ?>
  <div class="perfil tarjeta">
    <?= escudo($eq['escudo'], $eq['nombre'], $eq['color'], 'escudo-grande') ?>
    <div><h2 style="margin:0"><?= e($eq['nombre']) ?></h2>
      <p style="font-size:1.2rem;margin:.3rem 0"><?= $saldo > 0 ? 'Debe: <span class="saldo-debe">' . dinero($saldo) . '</span>' : ($saldo < 0 ? 'Saldo a favor: <span class="saldo-ok">' . dinero(-$saldo) . '</span>' : '<span class="saldo-ok">Al día ✓</span>') ?></p>
      <a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=recibo&amp;estado=<?= (int)$eq['id'] ?>">🖨 Imprimir estado de cuenta</a>
    </div>
  </div>
  <h2>Estado de cuenta</h2>
  <?php if (!$movs): ?><p class="suave">Sin movimientos.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Detalle</th><th>Campeonato</th><th class="num">Cargo</th><th class="num">Pago</th><th class="num">Saldo</th><th></th></tr>
    <?php $run = 0; foreach ($movs as $m):
        if (!$m['anulado']) $run += $m['tipo'] === 'cargo' ? $m['monto'] : -$m['monto']; ?>
      <tr style="<?= $m['anulado'] ? 'opacity:.45;text-decoration:line-through' : '' ?>">
        <td><?= e($m['fecha']) ?></td>
        <td style="white-space:normal;min-width:220px"><?= e($m['descripcion']) ?><?= $m['recibo'] ? ' <small>(Recibo ' . str_pad((string)$m['recibo'], 6, '0', STR_PAD_LEFT) . ')</small>' : '' ?><?= $m['ref'] && $m['tipo'] === 'cargo' ? ' <small class="suave">auto</small>' : '' ?></td>
        <td><?= e($m['torneo'] ?? '') ?></td>
        <td class="num"><?= $m['tipo'] === 'cargo' ? dinero($m['monto']) : '' ?></td>
        <td class="num"><?= $m['tipo'] === 'pago' ? dinero($m['monto']) : '' ?></td>
        <td class="num <?= $run > 0 ? 'saldo-debe' : 'saldo-ok' ?>"><?= dinero($run) ?></td>
        <td class="acciones">
          <?php if ($m['tipo'] === 'pago' && !$m['anulado']): ?><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=recibo&amp;id=<?= (int)$m['id'] ?>">Recibo</a><?php endif; ?>
          <?php if (!$m['anulado']): ?><form class="inline" method="post" onsubmit="var r=prompt('Motivo de la anulación:'); if(r===null) return false; this.motivo.value=' — '+r; return true;"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="motivo" value=""><button class="btn btn-sec btn-chico">Anular</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <div class="rejilla-2" style="margin-top:16px">
    <div class="tarjeta"><h2 style="margin-top:0">Registrar pago</h2><?php form_pago($equipos, $torneos, (int)$eq['id'], max(0, $saldo)); ?></div>
    <div class="tarjeta"><h2 style="margin-top:0">Cargo manual</h2>
      <p class="suave" style="font-size:.85rem">Para cobros que no son automáticos (ej: garantía, carnets, otros).</p>
      <form method="post">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="cargo"><input type="hidden" name="equipo_id" value="<?= (int)$eq['id'] ?>">
        <div class="campos">
          <div><label>Concepto</label><select name="concepto"><?php foreach (conceptos_cargo() as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'otro' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
          <div><label>Valor ($)</label><input type="number" name="monto" min="0.01" step="0.01" required></div>
          <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Campeonato</label><select name="torneo_id"><option value="">—</option><?php foreach ($torneos as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?></select></div>
          <div class="campo-ancho"><label>Detalle</label><input type="text" name="descripcion" maxlength="200"></div>
        </div>
        <button class="btn btn-sec" type="submit">Agregar cargo</button>
      </form>
    </div>
  </div>

<?php elseif ($ver === 'pago'): ?>
  <div class="tarjeta"><h2 style="margin-top:0">Registrar pago de un equipo</h2><?php form_pago($equipos, $torneos); ?></div>

<?php elseif ($ver === 'egresos'):
    $egr = filas("SELECT m.*, t.nombre AS torneo FROM movimientos m LEFT JOIN torneos t ON t.id = m.torneo_id WHERE m.liga_id = ? AND m.tipo = 'egreso' ORDER BY m.fecha DESC, m.id DESC", [$lid]);
?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Registrar gasto de la liga</h2>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="egreso">
      <div class="campos">
        <div><label>Concepto</label><select name="concepto"><?php foreach (categorias_gasto('liga') as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label>Valor ($)</label><input type="number" name="monto" min="0.01" step="0.01" required></div>
        <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
        <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label>N° factura / comprobante</label><input type="text" name="comprobante" maxlength="100"></div>
        <div><label>Campeonato</label><select name="torneo_id"><option value="">—</option><?php foreach ($torneos as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="campo-ancho"><label>Detalle</label><input type="text" name="descripcion" maxlength="200" placeholder="Ej: pago árbitros fecha 3"></div>
      </div>
      <button class="btn" type="submit">Registrar gasto</button>
    </form>
  </div>
  <?php if ($egr): ?>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Concepto</th><th>Detalle</th><th>Comprobante</th><th class="num">Valor</th><th></th></tr>
    <?php foreach ($egr as $m): ?>
      <tr style="<?= $m['anulado'] ? 'opacity:.45;text-decoration:line-through' : '' ?>"><td><?= e($m['fecha']) ?></td><td><?= e(conceptos_egreso()[$m['concepto']] ?? $m['concepto']) ?></td><td><?= e($m['descripcion']) ?></td><td><?= e($m['comprobante']) ?></td><td class="num"><?= dinero($m['monto']) ?></td>
        <td><?php if (!$m['anulado']): ?><form class="inline" method="post" onsubmit="var r=prompt('Motivo de la anulación:'); if(r===null) return false; this.motivo.value=' — '+r; return true;"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="motivo" value=""><button class="btn btn-sec btn-chico">Anular</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

<?php elseif ($ver === 'comprobantes'):
    $comps = filas('SELECT c.*, e.nombre AS equipo, u.nombre AS delegado FROM comprobantes c JOIN equipos e ON e.id = c.equipo_id LEFT JOIN usuarios u ON u.id = c.usuario_id
                    WHERE c.liga_id = ? ORDER BY (c.estado = \'pendiente\') DESC, c.id DESC LIMIT 100', [$lid]);
?>
  <p class="suave">Los delegados envían desde su portal la foto de su transferencia o depósito. Revise que el dinero llegó a la cuenta y apruébelo: el pago y el recibo se registran solos. Los datos de la cuenta para pagar se escriben en <a href="admin.php?p=config">Parámetros</a>.</p>
  <?php if (!$comps): ?><div class="tarjeta">Todavía no hay comprobantes.</div><?php endif; ?>
  <?php foreach ($comps as $c): ?>
    <div class="tarjeta comprobante">
      <a href="admin.php?p=finanzas&amp;comprobante=<?= (int)$c['id'] ?>" target="_blank"><img src="admin.php?p=finanzas&amp;comprobante=<?= (int)$c['id'] ?>" alt="Comprobante" loading="lazy"></a>
      <div>
        <h3 style="margin:0"><?= e($c['equipo']) ?> · <?= dinero($c['monto']) ?> <?= ['pendiente' => '<span class="estado-chip chip-pend">Por revisar</span>', 'aprobado' => '<span class="estado-chip chip-ok">Aprobado</span>', 'rechazado' => '<span class="estado-chip chip-mal">Rechazado</span>', 'cancelado' => '<span class="estado-chip">Cancelado</span>'][$c['estado']] ?? '' ?></h3>
        <p class="suave" style="margin:.3rem 0"><?= e(formas_pago()[$c['forma_pago']] ?? $c['forma_pago']) ?> del <?= e($c['fecha']) ?><?= $c['referencia'] !== '' ? ' · N° ' . e($c['referencia']) : '' ?> · enviado por <?= e($c['delegado'] ?? 'delegado') ?><?= $c['nota'] !== '' ? '<br>' . e($c['nota']) : '' ?>
          <?= $c['estado'] !== 'pendiente' && $c['revisado_por'] !== '' ? '<br>Revisó: ' . e($c['revisado_por']) . ' (' . e($c['revisado_en']) . ')' . ($c['respuesta'] !== '' ? ' · ' . e($c['respuesta']) : '') : '' ?></p>
        <?php if ($c['estado'] === 'pendiente' && puede('finanzas', true)): ?>
          <form method="post" class="acciones" style="align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="comp_aprobar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <div><label>Valor recibido</label><input type="number" name="monto" min="0.01" step="0.01" value="<?= e(number_format((float)$c['monto'], 2, '.', '')) ?>" style="max-width:120px"></div>
            <div><label>Concepto</label><select name="concepto"><?php foreach (conceptos_cargo() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <button class="btn">✓ Aprobar y emitir recibo</button></form>
          <form method="post" class="acciones" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="comp_rechazar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="text" name="respuesta" maxlength="200" placeholder="Motivo del rechazo (ej: no llegó el dinero)" style="max-width:320px"><button class="btn btn-sec">Rechazar</button></form>
        <?php elseif ($c['movimiento_id']): ?><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=recibo&amp;id=<?= (int)$c['movimiento_id'] ?>">🖨 Recibo</a><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

<?php elseif ($ver === 'movimientos'):
    $movs = filas('SELECT m.*, e.nombre AS equipo FROM movimientos m LEFT JOIN equipos e ON e.id = m.equipo_id WHERE m.liga_id = ? ORDER BY m.fecha DESC, m.id DESC LIMIT 300', [$lid]);
?>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Tipo</th><th>Equipo</th><th>Detalle</th><th class="num">Valor</th><th>Registró</th></tr>
    <?php foreach ($movs as $m): ?>
      <tr style="<?= $m['anulado'] ? 'opacity:.45;text-decoration:line-through' : '' ?>"><td><?= e($m['fecha']) ?></td>
        <td><?= $m['tipo'] === 'pago' ? '<span class="etiqueta verde">Pago</span>' : ($m['tipo'] === 'egreso' ? '<span class="etiqueta roja">Gasto</span>' : '<span class="etiqueta">Cargo</span>') ?></td>
        <td><?= e($m['equipo'] ?? '—') ?></td><td><?= e($m['descripcion']) ?></td><td class="num"><?= dinero($m['monto']) ?></td><td><small><?= e($m['registrado_por']) ?></small></td></tr>
    <?php endforeach; ?>
  </table></div>

<?php else: /* resumen */ ?>
  <p class="suave">Los cobros de <b>inscripción</b>, <b>arbitraje</b> y <b>multas</b> se cargan solos según las reglas de dinero de cada campeonato. Aquí solo registra los pagos.</p>
  <div class="tabla-scroll"><table>
    <tr><th>Equipo</th><th class="num">Total cargos</th><th class="num">Total pagado</th><th class="num">Saldo</th><th></th></tr>
    <?php foreach ($por_equipo as $x): $sal = round($x['cargos'] - $x['pagos'], 2); ?>
      <tr><td><div class="eq-celda"><?= escudo($x['escudo'], $x['nombre'], $x['color']) ?> <?= e($x['nombre']) ?></div></td>
        <td class="num"><?= dinero($x['cargos']) ?></td><td class="num"><?= dinero($x['pagos']) ?></td>
        <td class="num <?= $sal > 0 ? 'saldo-debe' : 'saldo-ok' ?>"><?= $sal > 0 ? 'Debe ' . dinero($sal) : ($sal < 0 ? 'A favor ' . dinero(-$sal) : 'Al día') ?></td>
        <td><a class="btn btn-sec btn-chico" href="admin.php?p=finanzas&amp;equipo=<?= (int)$x['id'] ?>">Ver cuenta / cobrar</a></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif;
pie_admin();
