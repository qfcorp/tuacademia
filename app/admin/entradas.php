<?php
/*
 * (v28) ENTRADAS: abrir la venta de un partido, localidades y parqueadero, compras (aprobar transferencias),
 * taquilla y cortesías, lector de la puerta y reporte.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
if (!entradas_activas($liga)) {
    cabecera_admin('Entradas');
    echo '<h1>🎟 Venta de entradas</h1><div class="tarjeta"><p>La venta de entradas está <b>apagada</b>. Si quiere vender las entradas de sus partidos (con QR, tarjeta o transferencia, taquilla, cortesías y parqueadero), actívela con la casilla en <a href="admin.php?p=config#entradas">Parámetros → Venta de entradas</a>.</p></div>';
    pie_admin();
    return;
}
$por = usuario()['nombre'];
$v = (int)get('v') ? venta_entradas((int)get('v'), $lid) : null;

// Comprobante de transferencia (privado)
if (get('comp') !== '') {
    $o = fila('SELECT comprobante FROM bol_ordenes WHERE id = ? AND liga_id = ?', [(int)get('comp'), $lid]);
    $ruta = (string)($o['comprobante'] ?? '');
    $abs = RAIZ . '/data/' . $ruta;
    if ($ruta === '' || str_contains($ruta, '..') || !str_starts_with($ruta, 'fotos/') || !is_file($abs)) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}
// Reporte en Excel
if ($v && get('excel') === '1') {
    require_once APP_DIR . '/excel.php';
    $f = [['Compra', 'Fecha', 'Comprador', 'Cédula', 'Teléfono', 'Canal', 'Pago', 'Estado', 'Total', 'IVA incluido', 'Imp. municipal incluido']];
    foreach (filas('SELECT * FROM bol_ordenes WHERE venta_id = ? ORDER BY id', [$v['id']]) as $o) $f[] = [$o['codigo'], $o['creado'], $o['nombre'], $o['cedula'], $o['telefono'], $o['canal'], $o['metodo'], estados_orden_entrada()[$o['estado']] ?? $o['estado'], (float)$o['total'], (float)$o['iva'], (float)$o['imp_municipal']];
    $t = [['Código', 'Localidad', 'Titular', 'Placa', 'Estado', 'Ingresó', 'Puerta']];
    foreach (filas('SELECT t.*, l.nombre AS loc FROM bol_tickets t JOIN bol_localidades l ON l.id = t.localidad_id WHERE t.venta_id = ? ORDER BY t.id', [$v['id']]) as $x) $t[] = [$x['codigo'], $x['loc'], $x['titular'], $x['placa'], $x['estado'], $x['usado_en'], $x['puerta']];
    $bin = crear_xlsx_libro([['nombre' => 'Compras', 'filas' => $f, 'anchos' => [22, 18, 26, 14, 14, 10, 14, 18, 10, 10, 12]], ['nombre' => 'Entradas', 'filas' => $t, 'anchos' => [26, 18, 26, 10, 10, 18, 12]]]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="entradas_' . slugify($v['titulo']) . '.xlsx"');
    echo $bin;
    exit;
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'crear') {
        $pid = (int)post('partido_id');
        $p = $pid ? fila("SELECT p.*, el.nombre AS l, ev.nombre AS vv, t.nombre AS torneo FROM partidos p JOIN torneos t ON t.id = p.torneo_id LEFT JOIN equipos el ON el.id = p.local_id LEFT JOIN equipos ev ON ev.id = p.visitante_id WHERE p.id = ? AND t.liga_id = ?", [$pid, $lid]) : null;
        $titulo = trim(post('titulo')) ?: ($p ? ($p['l'] ?? '') . ' vs ' . ($p['vv'] ?? '') . ' · ' . $p['torneo'] : '');
        if (mb_strlen($titulo) < 3) throw new RuntimeException('Elija el partido o escriba el nombre del evento.');
        $fecha = post('fecha') ?: ($p['fecha'] ?? '');
        q("INSERT INTO bol_ventas (liga_id, partido_id, titulo, fecha, hora, lugar, descripcion, estado, venta_hasta, max_por_orden, puerta_clave) VALUES (?,?,?,?,?,?,?, 'borrador', ?,?,?)", [
            $lid, $p['id'] ?? null, mb_substr($titulo, 0, 120), $fecha, post('hora') ?: ($p['hora'] ?? ''), mb_substr(post('lugar') ?: ($p['cancha'] ?? ''), 0, 120), mb_substr(post('descripcion'), 0, 500),
            $fecha !== '' ? $fecha . ' ' . (post('hora') ?: ($p['hora'] ?? '23:59')) : '', entero(post('max_por_orden') ?: 10, 1, 100), bin2hex(random_bytes(10))]);
        $nid = (int)db()->lastInsertId();
        q("INSERT INTO bol_localidades (venta_id, nombre, tipo, precio, cupo, orden) VALUES (?, 'General', 'entrada', ?, ?, 1)", [$nid, max(0, round((float)str_replace(',', '.', post('precio') ?: '2'), 2)), entero(post('cupo') ?: 0, 0, 100000)]);
        if ((int)$liga['entradas_parqueo'] === 1 && post('con_parqueo') === '1') q("INSERT INTO bol_localidades (venta_id, nombre, tipo, precio, cupo, orden) VALUES (?, 'Parqueadero', 'parqueo', 2, 30, 9)", [$nid]);
        flash('Venta creada en BORRADOR. Revise las localidades y precios, y ábrala al público.');
        ir('admin.php?p=entradas&v=' . $nid);
    }
    if (!$v) throw new RuntimeException('Venta no encontrada.');
    if ($accion === 'datos') {
        q('UPDATE bol_ventas SET titulo = ?, fecha = ?, hora = ?, lugar = ?, descripcion = ?, venta_hasta = ?, max_por_orden = ? WHERE id = ?', [mb_substr(post('titulo'), 0, 120), post('fecha'), post('hora'),
            mb_substr(post('lugar'), 0, 120), mb_substr(post('descripcion'), 0, 500), trim(post('venta_hasta_f') . ' ' . post('venta_hasta_h')), entero(post('max_por_orden'), 1, 100), $v['id']]);
        flash('Datos guardados.');
    } elseif ($accion === 'estado') {
        $nuevo = in_array(post('estado'), ['borrador', 'abierta', 'cerrada'], true) ? post('estado') : 'borrador';
        if ($nuevo === 'abierta' && !localidades_venta((int)$v['id'])) throw new RuntimeException('Agregue al menos una localidad.');
        q('UPDATE bol_ventas SET estado = ? WHERE id = ?', [$nuevo, $v['id']]);
        flash(['abierta' => '✅ Venta ABIERTA al público.', 'cerrada' => 'Venta cerrada: ya no se vende por internet (la puerta sigue validando).', 'borrador' => 'Venta en borrador (no se ve en la página).'][$nuevo]);
    } elseif ($accion === 'localidad') {
        $nom = mb_substr(trim(post('nombre')), 0, 60);
        if (mb_strlen($nom) < 2) throw new RuntimeException('Escriba el nombre de la localidad (ej: General, Tribuna, Palco).');
        $tipo = post('tipo') === 'parqueo' && (int)$liga['entradas_parqueo'] === 1 ? 'parqueo' : 'entrada';
        $d = [$nom, $tipo, max(0, round((float)str_replace(',', '.', post('precio')), 2)), entero(post('cupo'), 0, 100000)];
        if ((int)post('id')) q('UPDATE bol_localidades SET nombre = ?, tipo = ?, precio = ?, cupo = ? WHERE id = ? AND venta_id = ?', array_merge($d, [(int)post('id'), $v['id']]));
        else q('INSERT INTO bol_localidades (venta_id, nombre, tipo, precio, cupo, orden) VALUES (?,?,?,?,?,?)', array_merge([$v['id']], $d, [(int)valor('SELECT COUNT(*) FROM bol_localidades WHERE venta_id = ?', [$v['id']]) + 1]));
        flash('Localidad guardada.');
    } elseif ($accion === 'borrar_localidad') {
        if (valor('SELECT COUNT(*) FROM bol_items WHERE localidad_id = ?', [(int)post('id')])) throw new RuntimeException('Esa localidad ya tiene ventas: ponga el cupo en 1 o cierre la venta.');
        q('DELETE FROM bol_localidades WHERE id = ? AND venta_id = ?', [(int)post('id'), $v['id']]);
        flash('Localidad borrada.');
    } elseif ($accion === 'aprobar' || $accion === 'rechazar') {
        $o = fila("SELECT * FROM bol_ordenes WHERE id = ? AND venta_id = ? AND estado = 'por_verificar'", [(int)post('id'), $v['id']]) ?? throw new RuntimeException('Compra no encontrada.');
        if ($accion === 'aprobar') {
            pagar_orden_entradas($o, $por);
            flash('Compra aprobada: se emitieron las entradas. Envíeselas al comprador por WhatsApp.');
        } else {
            q("UPDATE bol_ordenes SET estado = 'rechazada', revisado_por = ?, nota = ? WHERE id = ?", [$por, mb_substr(post('motivo'), 0, 200), $o['id']]);
            flash('Compra rechazada.');
        }
    } elseif ($accion === 'anular') {
        $o = fila('SELECT * FROM bol_ordenes WHERE id = ? AND venta_id = ?', [(int)post('id'), $v['id']]) ?? throw new RuntimeException('Compra no encontrada.');
        q("UPDATE bol_ordenes SET estado = 'anulada', nota = ? WHERE id = ?", [mb_substr('Anulada por ' . $por . '. ' . post('motivo'), 0, 200), $o['id']]);
        q("UPDATE bol_tickets SET estado = 'anulado' WHERE orden_id = ? AND estado = 'valido'", [$o['id']]);
        flash('Compra anulada: sus entradas ya no pasan en la puerta.');
    } elseif ($accion === 'taquilla' || $accion === 'cortesia') {
        $cant = array_map('intval', (array)($_POST['cant'] ?? []));
        [$items, $total] = leer_pedido_entradas($v, $cant, post('placas'), true);
        $o = crear_orden_entradas($liga, $v, $items, $total, ['nombre' => post('nombre') ?: ($accion === 'cortesia' ? 'Cortesía' : 'Taquilla'), 'cedula' => post('cedula'), 'telefono' => post('telefono')],
            $accion, $accion === 'cortesia' ? 'cortesia' : 'efectivo');
        bitacora_evento('entradas', ($accion === 'cortesia' ? 'Cortesía' : 'Venta en taquilla') . ': ' . array_sum(array_column($items, 'cantidad')) . ' entrada(s) · ' . $v['titulo'], $lid, (int)$o['id']);
        flash(($accion === 'cortesia' ? '🎁 Cortesía emitida' : '💵 Venta registrada') . '. Muestre o envíe las entradas.');
        ir('admin.php?p=entradas&v=' . (int)$v['id'] . '&orden=' . (int)$o['id']);
    } elseif ($accion === 'nueva_clave') {
        q('UPDATE bol_ventas SET puerta_clave = ? WHERE id = ?', [bin2hex(random_bytes(10)), $v['id']]);
        flash('Enlace del lector cambiado: el anterior ya no funciona.');
    }
    ir('admin.php?p=entradas&v=' . (int)$v['id']);
}

cabecera_admin('Entradas');

/* ====================== LISTA DE VENTAS ====================== */
if (!$v):
    $ventas = filas('SELECT * FROM bol_ventas WHERE liga_id = ? ORDER BY CASE estado WHEN \'abierta\' THEN 0 WHEN \'borrador\' THEN 1 ELSE 2 END, fecha DESC, id DESC LIMIT 100', [$lid]);
    $prox = filas("SELECT p.id, p.fecha, p.hora, p.cancha, el.nombre AS l, ev.nombre AS vv, t.nombre AS torneo FROM partidos p JOIN torneos t ON t.id = p.torneo_id LEFT JOIN equipos el ON el.id = p.local_id LEFT JOIN equipos ev ON ev.id = p.visitante_id
                   WHERE t.liga_id = ? AND p.estado IN ('programado','postergado') AND p.fecha >= ? ORDER BY p.fecha, p.hora LIMIT 60", [$lid, date('Y-m-d')]); ?>
  <h1>🎟 Venta de entradas</h1>
  <details class="tarjeta" <?= $ventas ? '' : 'open' ?>><summary><b>+ Abrir la venta de un partido o evento</b></summary>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
      <div class="campos">
        <div class="campo-ancho"><label>Partido</label><select name="partido_id"><option value="">— Otro evento (escriba el nombre abajo) —</option>
          <?php foreach ($prox as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e(fecha_bonita($p['fecha'])) ?> <?= e($p['hora']) ?> · <?= e(($p['l'] ?? '?') . ' vs ' . ($p['vv'] ?? '?')) ?> · <?= e($p['torneo']) ?></option><?php endforeach; ?></select></div>
        <div class="campo-ancho"><label>Nombre (si no es un partido, o para cambiarlo)</label><input name="titulo" maxlength="120" placeholder="Ej: Gran final · Clausura 2026"></div>
        <div><label>Precio de la entrada General ($)</label><input name="precio" value="2" inputmode="decimal"></div>
        <div><label>Cupo (0 = sin límite)</label><input type="number" name="cupo" value="0" min="0"></div>
        <div><label>Máximo por compra</label><input type="number" name="max_por_orden" value="10" min="1"></div>
        <?php if ((int)$liga['entradas_parqueo'] === 1): ?><div><label class="check" style="margin-top:28px"><input type="checkbox" name="con_parqueo" value="1" checked> Con parqueadero</label></div><?php endif; ?>
      </div>
      <button class="btn">Crear (queda en borrador)</button></form></details>
  <?php if (!$ventas): ?><div class="tarjeta vacio">Todavía no hay ventas de entradas.</div><?php endif; ?>
  <?php foreach ($ventas as $x): $r = resumen_venta($x); $vend = array_sum(array_column($r['localidades'], 'vendidas')); ?>
    <a class="tarjeta" style="display:block;text-decoration:none;color:inherit" href="admin.php?p=entradas&amp;v=<?= (int)$x['id'] ?>">
      <div class="fila-titulo" style="margin:0"><b>🎟 <?= e($x['titulo']) ?></b><span class="estado-chip <?= $x['estado'] === 'abierta' ? 'chip-ok' : ($x['estado'] === 'borrador' ? 'chip-pend' : '') ?>"><?= e(['abierta' => 'Abierta', 'borrador' => 'Borrador', 'cerrada' => 'Cerrada'][$x['estado']] ?? $x['estado']) ?></span></div>
      <small class="suave"><?= e(fecha_bonita($x['fecha'])) ?> <?= e($x['hora']) ?> · <?= $vend ?> vendidas · <?= dinero($r['total']) ?><?= $r['por_revisar'] ? ' · <b style="color:var(--roja)">' . $r['por_revisar'] . ' por revisar</b>' : '' ?></small></a>
  <?php endforeach; ?>

<?php
/* ====================== UNA VENTA ====================== */
else:
    $r = resumen_venta($v);
    $locs = localidades_venta((int)$v['id']);
    $f_estado = get('estado', 'por_verificar');
    $ordenes = filas('SELECT o.*, (SELECT COUNT(*) FROM bol_tickets t WHERE t.orden_id = o.id) AS n FROM bol_ordenes o WHERE o.venta_id = ?' . ($f_estado !== 'todas' ? ' AND o.estado = ?' : '') . ' ORDER BY o.id DESC LIMIT 300', $f_estado !== 'todas' ? [$v['id'], $f_estado] : [$v['id']]);
    $url_pub = url_base() . 'index.php?l=' . rawurlencode($liga['slug']) . '&v=entradas&venta=' . (int)$v['id'];
    $url_puerta = url_base() . 'puerta.php?k=' . $v['puerta_clave'];
    $vista = (int)get('orden') ? fila('SELECT * FROM bol_ordenes WHERE id = ? AND venta_id = ?', [(int)get('orden'), $v['id']]) : null; ?>
  <p><a href="admin.php?p=entradas">← Ventas de entradas</a></p>
  <div class="fila-titulo"><h1 style="margin:0">🎟 <?= e($v['titulo']) ?></h1>
    <form method="post" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado">
      <?php if ($v['estado'] !== 'abierta'): ?><button class="btn" name="estado" value="abierta">✅ Abrir al público</button><?php else: ?><button class="btn btn-sec" name="estado" value="cerrada">Cerrar la venta</button><?php endif; ?>
      <?php if ($v['estado'] === 'abierta'): ?><a class="btn btn-sec" target="_blank" href="<?= e($url_pub) ?>">Ver como comprador ↗</a><?php endif; ?></form></div>
  <p class="suave"><?= e(fecha_bonita($v['fecha'])) ?> <?= e($v['hora']) ?><?= $v['lugar'] !== '' ? ' · ' . e($v['lugar']) : '' ?> · Estado: <b><?= e(['abierta' => 'abierta al público', 'borrador' => 'borrador (no se ve)', 'cerrada' => 'cerrada'][$v['estado']]) ?></b></p>

  <?php if ($vista): $tks = filas('SELECT t.*, l.nombre AS loc FROM bol_tickets t JOIN bol_localidades l ON l.id = t.localidad_id WHERE t.orden_id = ?', [$vista['id']]); $wa = telefono_wa($vista['telefono']); ?>
    <div class="tarjeta" style="border:2px solid var(--acento)"><h2 style="margin-top:0">Entradas de <?= e($vista['nombre']) ?> (<?= count($tks) ?>)</h2>
      <p><a class="btn" target="_blank" href="<?= e(url_mis_entradas($liga, $vista)) ?>">🎟 Abrir las entradas (QR) para mostrar o imprimir</a>
        <?php if ($wa): ?> <a class="btn btn-sec" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Sus entradas para ' . $v['titulo'] . ': ' . url_mis_entradas($liga, $vista)) ?>">💬 Enviar por WhatsApp</a><?php endif; ?></p></div>
  <?php endif; ?>

  <div class="cifras">
    <div class="cifra"><b><?= array_sum(array_column($r['localidades'], 'vendidas')) ?></b>Entradas vendidas</div>
    <div class="cifra"><b><?= array_sum(array_column($r['localidades'], 'ingresaron')) ?></b>Ya ingresaron</div>
    <div class="cifra"><b><?= dinero($r['total']) ?></b>Recaudado</div>
    <div class="cifra"><b><?= $r['por_revisar'] ?></b>Transferencias por revisar</div>
  </div>

  <div class="tarjeta">
    <h2 style="margin-top:0">📱 Lector de la puerta</h2>
    <p>Abra este enlace en el celular de cada persona de la puerta (no lo publique). Escanea el QR de cada entrada: <b>verde pasa, rojo no pasa</b>. Funciona sin internet y sincroniza cuando vuelve la señal.</p>
    <div class="acciones"><input readonly value="<?= e($url_puerta) ?>" onclick="this.select()" style="flex:1;min-width:240px">
      <a class="btn btn-sec btn-chico" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('Lector de entradas para ' . $v['titulo'] . ' (solo para el personal de la puerta): ' . $url_puerta) ?>">💬 Enviar</a>
      <form method="post" class="inline" onsubmit="return confirm('¿Cambiar el enlace? El anterior dejará de funcionar.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="nueva_clave"><button class="btn btn-sec btn-chico">Cambiar enlace</button></form></div>
  </div>

  <div class="tarjeta">
    <h2 style="margin-top:0">🏟 Localidades<?= (int)$liga['entradas_parqueo'] === 1 ? ' y parqueadero' : '' ?></h2>
    <table><tr><th>Localidad</th><th>Precio</th><th>Cupo</th><th>Vendidas</th><th>Ingresaron</th><th>Quedan</th><th></th></tr>
      <?php foreach ($r['localidades'] as $l): ?>
        <tr><td><?= $l['tipo'] === 'parqueo' ? '🚗 ' : '' ?><b><?= e($l['nombre']) ?></b></td><td><?= dinero($l['precio']) ?></td><td><?= (int)$l['cupo'] ?: '∞' ?></td><td><?= (int)$l['vendidas'] ?></td><td><?= (int)$l['ingresaron'] ?></td><td><?= $l['libres'] === null ? '∞' : (int)$l['libres'] ?></td>
          <td><details><summary class="btn btn-sec btn-mini">Editar</summary><form method="post" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="localidad"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="tipo" value="<?= e($l['tipo']) ?>">
            <input name="nombre" value="<?= e($l['nombre']) ?>"> <input name="precio" value="<?= e((string)$l['precio']) ?>" style="max-width:80px"> <input type="number" name="cupo" value="<?= (int)$l['cupo'] ?>" style="max-width:90px"> <button class="btn btn-chico">Guardar</button></form>
            <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_localidad"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn-sec btn-mini">Borrar</button></form></details></td></tr>
      <?php endforeach; ?></table>
    <form method="post" class="acciones" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="localidad">
      <input name="nombre" placeholder="Nueva: Tribuna, Palco, Niños…" required> <input name="precio" placeholder="Precio" style="max-width:90px" required> <input type="number" name="cupo" placeholder="Cupo" style="max-width:90px">
      <?php if ((int)$liga['entradas_parqueo'] === 1): ?><select name="tipo"><option value="entrada">Entrada</option><option value="parqueo">🚗 Parqueadero</option></select><?php endif; ?>
      <button class="btn btn-sec">+ Agregar</button></form>
  </div>

  <div class="rejilla-2">
    <?php foreach (['taquilla' => ['💵 Vender en taquilla (efectivo)', 'Vender'], 'cortesia' => ['🎁 Cortesías (gratis)', 'Emitir cortesía']] as $acc => [$tit, $boton]): ?>
    <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $acc ?>">
      <h2 style="margin-top:0"><?= $tit ?></h2>
      <?php foreach ($locs as $l): ?><label style="display:flex;justify-content:space-between;gap:8px;align-items:center"><span><?= $l['tipo'] === 'parqueo' ? '🚗 ' : '' ?><?= e($l['nombre']) ?><?= $acc === 'taquilla' ? ' · ' . dinero($l['precio']) : '' ?></span><input type="number" name="cant[<?= (int)$l['id'] ?>]" min="0" max="50" value="0" style="max-width:80px"></label><?php endforeach; ?>
      <input name="nombre" placeholder="Nombre (opcional)" style="margin-top:6px"><input name="telefono" placeholder="Celular para enviarle las entradas (opcional)">
      <?php if (array_filter($locs, fn($l) => $l['tipo'] === 'parqueo')): ?><input name="placas" placeholder="Placas del parqueadero (ej: PBA-1234)"><?php endif; ?>
      <button class="btn btn-sec" style="margin-top:6px"><?= $boton ?></button></form>
    <?php endforeach; ?>
  </div>

  <div class="tarjeta">
    <div class="fila-titulo" style="margin:0"><h2 style="margin:0">🧾 Compras</h2>
      <span class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=entradas&amp;v=<?= (int)$v['id'] ?>&amp;excel=1">⬇ Excel</a></span></div>
    <nav class="pestanas" style="margin-top:8px"><?php foreach (['por_verificar' => 'Por revisar', 'pagada' => 'Pagadas', 'pendiente' => 'Pagando', 'todas' => 'Todas'] as $k => $n): ?><a class="<?= $f_estado === $k ? 'activa' : '' ?>" href="admin.php?p=entradas&amp;v=<?= (int)$v['id'] ?>&amp;estado=<?= $k ?>"><?= e($n) ?></a><?php endforeach; ?></nav>
    <?php if (!$ordenes): ?><p class="suave">No hay compras con ese filtro.</p><?php endif; ?>
    <?php foreach ($ordenes as $o): $items = filas('SELECT i.*, l.nombre FROM bol_items i JOIN bol_localidades l ON l.id = i.localidad_id WHERE i.orden_id = ?', [$o['id']]); ?>
      <div class="fila-titulo" style="margin:8px 0;border-bottom:1px solid var(--borde);padding-bottom:8px">
        <span><b><?= e($o['nombre'] ?: '—') ?></b> <small class="suave"><?= e($o['cedula']) ?> · <?= e($o['telefono']) ?> · <?= e(nombre_metodo_entrada($o['metodo'])) ?> · <?= e(substr($o['creado'], 0, 16)) ?></small><br>
          <?= e(implode(' · ', array_map(fn($i) => $i['cantidad'] . ' × ' . $i['nombre'] . ($i['placas'] !== '' ? ' (' . $i['placas'] . ')' : ''), $items))) ?> = <b><?= dinero($o['total']) ?></b> <?= chip_orden_entrada($o['estado']) ?></span>
        <span class="acciones">
          <?php if ($o['comprobante'] !== ''): ?><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=entradas&amp;v=<?= (int)$v['id'] ?>&amp;comp=<?= (int)$o['id'] ?>">🧾 Comprobante</a><?php endif; ?>
          <?php if ($o['estado'] === 'por_verificar'): ?>
            <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn-chico">✓ Aprobar</button></form>
            <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input name="motivo" placeholder="Motivo" style="max-width:130px"><button class="btn btn-sec btn-chico">Rechazar</button></form>
          <?php endif; ?>
          <?php if ($o['estado'] === 'pagada'): ?><a class="btn btn-sec btn-chico" href="admin.php?p=entradas&amp;v=<?= (int)$v['id'] ?>&amp;orden=<?= (int)$o['id'] ?>">🎟 Entradas</a>
            <form method="post" class="inline" onsubmit="return confirm('¿Anular esta compra? Sus entradas dejarán de pasar en la puerta.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sec btn-mini">Anular</button></form><?php endif; ?>
        </span></div>
    <?php endforeach; ?>
  </div>

  <div class="tarjeta"><h2 style="margin-top:0">💰 Resumen</h2>
    <table><?php foreach ($r['por_metodo'] as $m => $x): ?><tr><td><?= e(nombre_metodo_entrada($m)) ?></td><td class="num"><?= (int)$x['n'] ?> compra(s)</td><td class="num"><b><?= dinero($x['t']) ?></b></td></tr><?php endforeach; ?>
      <tr><td><b>Total recaudado</b></td><td></td><td class="num"><b><?= dinero($r['total']) ?></b></td></tr>
      <?php if ($r['iva'] > 0): ?><tr><td>IVA incluido</td><td></td><td class="num"><?= dinero($r['iva']) ?></td></tr><?php endif; ?>
      <?php if ($r['municipal'] > 0): ?><tr><td>Impuesto municipal incluido</td><td></td><td class="num"><?= dinero($r['municipal']) ?></td></tr><?php endif; ?>
      <tr><td>Lecturas rechazadas en la puerta (usadas, anuladas o falsas)</td><td></td><td class="num"><?= (int)$r['rechazos'] ?></td></tr></table></div>

  <details class="tarjeta"><summary><b>✏️ Datos de la venta</b></summary>
    <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
      <div class="campos">
        <div class="campo-ancho"><label>Nombre</label><input name="titulo" value="<?= e($v['titulo']) ?>"></div>
        <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($v['fecha']) ?>"></div><div><label>Hora</label><input type="time" name="hora" value="<?= e($v['hora']) ?>"></div>
        <div class="campo-ancho"><label>Lugar</label><input name="lugar" value="<?= e($v['lugar']) ?>"></div>
        <div><label>Vender hasta (día)</label><input type="date" name="venta_hasta_f" value="<?= e(substr($v['venta_hasta'], 0, 10)) ?>"></div><div><label>Hora</label><input type="time" name="venta_hasta_h" value="<?= e(substr($v['venta_hasta'], 11, 5)) ?>"></div>
        <div><label>Máximo por compra</label><input type="number" name="max_por_orden" value="<?= (int)$v['max_por_orden'] ?>"></div>
        <div class="campo-ancho"><label>Información para el comprador</label><textarea name="descripcion" rows="2"><?= e($v['descripcion']) ?></textarea></div>
      </div><button class="btn btn-sec">Guardar</button></form></details>
<?php endif;
pie_admin();
