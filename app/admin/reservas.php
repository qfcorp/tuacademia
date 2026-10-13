<?php
/* (v8) Reserva de canchas: agenda del día, reservas del público o por teléfono, cobro y configuración de horarios y precios */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
$fecha = get('fecha') !== '' && strtotime(get('fecha')) ? date('Y-m-d', strtotime(get('fecha'))) : date('Y-m-d');
$s = get('s') === 'config' ? 'config' : 'agenda';

$reserva_de_liga = function (int $id) use ($lid): array {
    return fila('SELECT r.*, c.nombre AS cancha FROM reservas r JOIN canchas c ON c.id = r.cancha_id WHERE r.id = ? AND r.liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Reserva no encontrada.');
};

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'config') {
        q('UPDATE ligas SET reservas_activas = ?, reservas_texto = ?, reservas_whatsapp = ? WHERE id = ?', [post('reservas_activas') === '1' ? 1 : 0, mb_substr(post('reservas_texto'), 0, 600), mb_substr(post('reservas_whatsapp'), 0, 30), $lid]);
        foreach ((array)($_POST['cancha'] ?? []) as $cid => $c) {
            if (!is_array($c)) continue;
            q('UPDATE canchas SET reservable = ?, precio_hora = ?, abre = ?, cierra = ?, duracion = ? WHERE id = ? AND liga_id = ?', [
                !empty($c['reservable']) ? 1 : 0, max(0, round((float)str_replace(',', '.', (string)($c['precio_hora'] ?? 0)), 2)),
                hora_valida((string)($c['abre'] ?? '')) ?: '08:00', hora_valida((string)($c['cierra'] ?? '')) ?: '22:00', entero($c['duracion'] ?? 60, 30, 240), (int)$cid, $lid,
            ]);
        }
        flash('Configuración de reservas guardada.');
        ir('admin.php?p=reservas&s=config');
    }
    if ($accion === 'crear') {
        $c = fila('SELECT * FROM canchas WHERE id = ? AND liga_id = ?', [(int)post('cancha_id'), $lid]) ?? throw new RuntimeException('Elija la cancha.');
        $id = crear_reserva($c, post('fecha'), post('hora'), entero(post('turnos'), 1, 6), post('nombre'), post('telefono'), 'panel', 'confirmada', post('nota'));
        flash('Reserva creada y confirmada.');
        ir('admin.php?p=reservas&fecha=' . rawurlencode(post('fecha')) . '#r' . $id);
    }
    $r = $reserva_de_liga((int)post('id'));
    if ($accion === 'confirmar') {
        q("UPDATE reservas SET estado = 'confirmada' WHERE id = ? AND estado = 'pendiente'", [$r['id']]);
        flash('Reserva confirmada. Avísele al cliente por WhatsApp.');
    } elseif ($accion === 'cancelar') {
        q("UPDATE reservas SET estado = 'cancelada', nota = ? WHERE id = ?", [mb_substr(trim($r['nota'] . ' ' . post('motivo')), 0, 200), $r['id']]);
        flash('Reserva cancelada: el horario quedó libre.');
    } elseif ($accion === 'cobrar') {
        $monto = round((float)str_replace(',', '.', post('monto')), 2);
        if ($monto <= 0) throw new RuntimeException('Escriba el valor cobrado.');
        $num = (int)valor('SELECT recibo_siguiente FROM ligas WHERE id = ?', [$lid]);
        q('UPDATE ligas SET recibo_siguiente = recibo_siguiente + 1 WHERE id = ?', [$lid]);
        q("INSERT INTO movimientos (liga_id, tipo, concepto, descripcion, monto, fecha, forma_pago, recibo, ref, registrado_por) VALUES (?, 'pago', 'reserva', ?, ?, ?, ?, ?, ?, ?)", [
            $lid, mb_substr('Reserva ' . $r['cancha'] . ' ' . $r['fecha'] . ' ' . $r['hora_inicio'] . ' · ' . $r['cliente_nombre'], 0, 200), $monto, date('Y-m-d'),
            array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', $num, 'res:' . $r['id'] . ':' . time(), $por,
        ]);
        $pagado = round((float)$r['pagado'] + $monto, 2);
        q('UPDATE reservas SET pagado = ?, estado = CASE WHEN ? >= precio THEN \'pagada\' ELSE CASE WHEN estado = \'pendiente\' THEN \'confirmada\' ELSE estado END END WHERE id = ?', [$pagado, $pagado, $r['id']]);
        flash('Cobro registrado (' . dinero($monto) . '). Quedó como ingreso en Finanzas.');
    }
    ir('admin.php?p=reservas&fecha=' . $r['fecha'] . '#r' . $r['id']);
}

$canchas = filas('SELECT * FROM canchas WHERE liga_id = ? AND activa = 1 ORDER BY orden, nombre', [$lid]);
$reservables = array_values(array_filter($canchas, fn($c) => (int)$c['reservable'] === 1));
cabecera_admin('Reservas de canchas');
?>
<h1>🏟 Reservas de canchas</h1>
<nav class="pestanas">
  <a href="admin.php?p=reservas" class="<?= $s === 'agenda' ? 'activa' : '' ?>">Agenda</a>
  <a href="admin.php?p=reservas&amp;s=config" class="<?= $s === 'config' ? 'activa' : '' ?>">Horarios y precios</a>
</nav>

<?php if ($s === 'config'): ?>
  <p class="suave">Alquile sus canchas por hora cuando no hay partidos de la liga. El público reserva desde la página de la liga (pestaña <b>🏟 Reservar cancha</b>) y usted confirma. Los partidos programados en una cancha la bloquean solos. Las canchas se crean en <a href="admin.php?p=config#canchas">Parámetros</a>.</p>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="config">
    <label class="check"><input type="checkbox" name="reservas_activas" value="1" <?= $liga['reservas_activas'] ? 'checked' : '' ?>> <b>Recibir reservas del público</b> en la página de la liga</label>
    <div class="campos" style="margin-top:10px">
      <div><label>WhatsApp para reservas</label><input type="tel" name="reservas_whatsapp" maxlength="30" value="<?= e($liga['reservas_whatsapp']) ?>" placeholder="09..."><div class="ayuda">El cliente le escribe aquí para confirmar o pagar.</div></div>
      <div class="campo-ancho"><label>Condiciones (se muestran al reservar)</label><textarea name="reservas_texto" rows="3" maxlength="600" placeholder="Ej: Se confirma con un anticipo del 50% por transferencia. Cancelaciones con 24 horas de anticipación."><?= e($liga['reservas_texto']) ?></textarea></div>
    </div>
    <?php if (!$canchas): ?><div class="aviso aviso-info">Primero cree sus canchas en <a href="admin.php?p=config#canchas">Parámetros → Canchas</a>.</div><?php else: ?>
    <div class="tabla-scroll"><table>
      <tr><th>Cancha</th><th>Se alquila</th><th>Precio por hora ($)</th><th>Abre</th><th>Cierra</th><th>Turno (min)</th></tr>
      <?php foreach ($canchas as $c): ?><tr><td><b><?= e($c['nombre']) ?></b></td>
        <td><input type="checkbox" name="cancha[<?= (int)$c['id'] ?>][reservable]" value="1" <?= $c['reservable'] ? 'checked' : '' ?>></td>
        <td><input type="number" name="cancha[<?= (int)$c['id'] ?>][precio_hora]" min="0" step="0.5" value="<?= e(number_format((float)$c['precio_hora'], 2, '.', '')) ?>" style="max-width:100px"></td>
        <td><input type="time" name="cancha[<?= (int)$c['id'] ?>][abre]" value="<?= e($c['abre']) ?>"></td>
        <td><input type="time" name="cancha[<?= (int)$c['id'] ?>][cierra]" value="<?= e($c['cierra']) ?>"></td>
        <td><select name="cancha[<?= (int)$c['id'] ?>][duracion]"><?php foreach ([30, 60, 90, 120] as $d): ?><option value="<?= $d ?>" <?= (int)$c['duracion'] === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <button class="btn">Guardar</button>
  </form>
<?php else:
    $reservas = filas("SELECT r.*, c.nombre AS cancha FROM reservas r JOIN canchas c ON c.id = r.cancha_id WHERE r.liga_id = ? AND r.fecha = ? ORDER BY r.hora_inicio, c.orden", [$lid, $fecha]);
    $pend = (int)valor("SELECT COUNT(*) FROM reservas WHERE liga_id = ? AND estado = 'pendiente' AND fecha >= ?", [$lid, date('Y-m-d')]);
    $mes = fila("SELECT COUNT(*) AS n, COALESCE(SUM(pagado),0) AS cobrado FROM reservas WHERE liga_id = ? AND estado <> 'cancelada' AND substr(fecha,1,7) = ?", [$lid, substr($fecha, 0, 7)]);
?>
  <?php if (!$reservables): ?><div class="aviso aviso-info">Todavía no hay canchas para alquilar. Configúrelas en <a href="admin.php?p=reservas&amp;s=config">Horarios y precios</a>.</div><?php endif; ?>
  <div class="cifras">
    <div class="cifra"><b><?= $pend ?></b>Por confirmar</div>
    <div class="cifra"><b><?= (int)$mes['n'] ?></b>Reservas en el mes</div>
    <div class="cifra"><b><?= dinero($mes['cobrado']) ?></b>Cobrado en el mes</div>
  </div>
  <form method="get" class="acciones" style="margin:10px 0"><input type="hidden" name="p" value="reservas">
    <a class="btn btn-sec btn-chico" href="admin.php?p=reservas&amp;fecha=<?= date('Y-m-d', strtotime($fecha . ' -1 day')) ?>">◀</a>
    <input type="date" name="fecha" value="<?= e($fecha) ?>" onchange="this.form.submit()">
    <a class="btn btn-sec btn-chico" href="admin.php?p=reservas&amp;fecha=<?= date('Y-m-d', strtotime($fecha . ' +1 day')) ?>">▶</a>
    <b><?= e(fecha_bonita($fecha)) ?></b></form>

  <?php if ($reservables): ?>
  <div class="agenda-canchas">
    <?php foreach ($reservables as $c): ?>
      <div class="agenda-col"><h3><?= e($c['nombre']) ?> <small class="suave"><?= dinero($c['precio_hora']) ?>/h</small></h3>
        <?php foreach (turnos_cancha($c, $fecha) as $tu): ?>
          <div class="turno <?= $tu['libre'] ? 'libre' : 'ocupado' ?>"><b><?= e($tu['ini']) ?></b> <?= $tu['libre'] ? 'Libre' : e($tu['motivo']) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h2>Reservas del día</h2>
  <?php if (!$reservas): ?><p class="suave">No hay reservas este día.</p><?php endif; ?>
  <?php foreach ($reservas as $r): $wa = telefono_wa($r['cliente_telefono']); ?>
    <div class="tarjeta" id="r<?= (int)$r['id'] ?>" style="<?= $r['estado'] === 'cancelada' ? 'opacity:.55' : '' ?>">
      <div class="fila-titulo"><h3 style="margin:0"><?= e($r['hora_inicio'] . ' – ' . $r['hora_fin']) ?> · <?= e($r['cancha']) ?></h3>
        <span class="estado-chip <?= ['pendiente' => 'chip-pend', 'confirmada' => 'chip-ok', 'pagada' => 'chip-ok', 'cancelada' => 'chip-mal'][$r['estado']] ?? '' ?>"><?= e(nombre_estado_reserva($r['estado'])) ?></span></div>
      <p style="margin:.4rem 0"><b><?= e($r['cliente_nombre']) ?></b> · <?= $wa ? '<a target="_blank" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode('Hola ' . $r['cliente_nombre'] . ', le escribimos de ' . $liga['nombre'] . ' por su reserva de ' . $r['cancha'] . ' el ' . fecha_bonita($r['fecha']) . ' a las ' . $r['hora_inicio'] . ' (código ' . $r['codigo'] . ').') . '">📲 ' . e($r['cliente_telefono']) . '</a>' : e($r['cliente_telefono']) ?>
        · Valor <?= dinero($r['precio']) ?> · Pagado <?= dinero($r['pagado']) ?> · Código <?= e($r['codigo']) ?> · <?= $r['origen'] === 'publico' ? 'reservó por internet' : 'registrada en el panel' ?><?= $r['nota'] !== '' ? '<br><small>' . e($r['nota']) . '</small>' : '' ?></p>
      <?php if ($r['estado'] !== 'cancelada' && puede('reservas', true)): ?>
      <div class="acciones">
        <?php if ($r['estado'] === 'pendiente'): ?><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-chico">✓ Confirmar</button></form><?php endif; ?>
        <?php if ((float)$r['pagado'] < (float)$r['precio'] || (float)$r['precio'] == 0.0): ?>
        <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cobrar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="number" name="monto" min="0.01" step="0.01" value="<?= e(number_format(max(0, (float)$r['precio'] - (float)$r['pagado']), 2, '.', '')) ?>" style="max-width:90px">
          <select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
          <button class="btn btn-sec btn-chico">💵 Cobrar</button></form><?php endif; ?>
        <form class="inline" method="post" onsubmit="var m=prompt('Motivo de la cancelación (opcional):'); if(m===null) return false; this.motivo.value=m; return true;"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="motivo" value=""><button class="btn btn-peligro btn-chico">Cancelar</button></form>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($reservables && puede('reservas', true)): ?>
  <div class="tarjeta" style="margin-top:16px">
    <h2 style="margin-top:0">+ Nueva reserva (por teléfono o en persona)</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
      <div class="campos">
        <div><label>Cancha</label><select name="cancha_id"><?php foreach ($reservables as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
        <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($fecha) ?>"></div>
        <div><label>Hora de inicio</label><input type="time" name="hora" required></div>
        <div><label>Turnos seguidos</label><input type="number" name="turnos" min="1" max="6" value="1"></div>
        <div><label>Cliente</label><input type="text" name="nombre" required maxlength="80"></div>
        <div><label>Celular</label><input type="tel" name="telefono" required maxlength="30"></div>
        <div class="campo-ancho"><label>Nota</label><input type="text" name="nota" maxlength="200"></div>
      </div>
      <button class="btn">Guardar reserva</button>
    </form>
  </div>
  <?php endif; ?>
<?php endif;
pie_admin();
