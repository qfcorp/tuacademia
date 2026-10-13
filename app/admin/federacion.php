<?php
/*
 * (v28) FEDERACIÓN
 *  - En una liga o escuela: afiliarse con el código de la federación o dejarla.
 *  - En la cuenta de la federación: resumen de todas sus ligas, afiliaciones, partidos de la semana, buscar personas,
 *    sanciones federativas y traer equipos de las ligas a los campeonatos federativos.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$fed = es_federacion($liga);
$por = usuario()['nombre'];

if (es_post()) {
    $accion = post('accion');
    // ---- Lado de la liga afiliada ----
    if ($accion === 'afiliar') {
        $f = pedir_afiliacion($liga, post('codigo'));
        flash('Pedido enviado a ' . $f['nombre'] . '. Cuando lo apruebe, su ' . (es_escuela($liga) ? 'escuela' : 'liga') . ' quedará afiliada.');
        ir('admin.php?p=federacion');
    }
    if ($accion === 'salir') {
        if ($liga['federacion_id']) bitacora_evento('federacion', 'Dejó la federación (id ' . (int)$liga['federacion_id'] . ')', $lid);
        q("UPDATE ligas SET federacion_id = NULL, federacion_estado = '' WHERE id = ?", [$lid]);
        flash('Listo: su cuenta ya no está en la federación.');
        ir('admin.php?p=federacion');
    }
    if (!$fed) throw new RuntimeException('Acción no permitida.');
    // ---- Lado de la federación ----
    if (in_array($accion, ['aprobar', 'rechazar', 'quitar'], true)) {
        $m = fila('SELECT * FROM ligas WHERE id = ? AND federacion_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('Cuenta no encontrada.');
        if ($accion === 'aprobar') {
            q("UPDATE ligas SET federacion_estado = 'afiliada' WHERE id = ?", [$m['id']]);
            aviso_admins($m, '🏛 Su afiliación a <b>' . e($liga['nombre']) . '</b> fue aprobada.', 'afil-ok:' . $m['id']);
            flash($m['nombre'] . ' quedó afiliada.');
        } else {
            q("UPDATE ligas SET federacion_id = NULL, federacion_estado = '' WHERE id = ?", [$m['id']]);
            flash($m['nombre'] . ($accion === 'rechazar' ? ': pedido rechazado.' : ' ya no está en la federación.'));
        }
        bitacora_evento('federacion', ucfirst($accion) . ' afiliación de ' . $m['nombre'], $lid);
        ir('admin.php?p=federacion&s=ligas');
    }
    if ($accion === 'nuevo_codigo') {
        q("UPDATE ligas SET fed_codigo = '' WHERE id = ?", [$lid]);
        codigo_federacion(fila('SELECT * FROM ligas WHERE id = ?', [$lid]));
        flash('Código nuevo creado. El anterior ya no sirve.');
        ir('admin.php?p=federacion&s=ligas');
    }
    if ($accion === 'sancion') {
        $ced = preg_replace('/[\s.\-]+/', '', post('cedula'));
        if (preg_match('/^\d{9}$/', $ced)) $ced = '0' . $ced;
        if ($ced === '' || ($err = error_documento($ced))) throw new RuntimeException('Cédula: ' . ($err ?? 'escríbala.'));
        $hasta = post('hasta') !== '' && strtotime(post('hasta')) ? date('Y-m-d', strtotime(post('hasta'))) : '';
        $desde = post('desde') !== '' && strtotime(post('desde')) ? date('Y-m-d', strtotime(post('desde'))) : date('Y-m-d');
        if ($hasta !== '' && $hasta < $desde) throw new RuntimeException('La fecha "hasta" es anterior a "desde".');
        $nombre = mb_substr(trim(post('nombre')), 0, 120);
        if ($nombre === '') {
            $x = buscar_en_federacion($lid, $ced);
            $nombre = $x[0]['nombre'] ?? '';
        }
        q('INSERT INTO fed_sanciones (federacion_id, cedula, nombre, motivo, desde, hasta, registrado_por) VALUES (?,?,?,?,?,?,?)', [$lid, $ced, $nombre, mb_substr(trim(post('motivo')), 0, 200), $desde, $hasta, $por]);
        // Aviso a las ligas donde juega
        foreach (filas('SELECT DISTINCT e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.cedula = ? AND j.activo = 1', [$ced]) as $x) {
            $l2 = fila('SELECT * FROM ligas WHERE id = ?', [$x['liga_id']]);
            if ($l2 && in_array((int)$l2['id'], ids_federacion($lid), true)) aviso_admins($l2, '⛔ <b>Sanción federativa</b>' . "\n" . e($nombre) . ' (' . e($ced) . ')' . "\n" . e(post('motivo')) . ($hasta !== '' ? "\nHasta: " . e($hasta) : ''), 'fsan:' . $ced . ':' . $l2['id'] . ':' . time());
        }
        flash('Sanción registrada: ' . ($nombre ?: $ced) . '. Las ligas afiliadas la verán en la vocalía y en el carnet.');
        ir('admin.php?p=federacion&s=sanciones');
    }
    if ($accion === 'anular_sancion') {
        q('UPDATE fed_sanciones SET anulada = 1 WHERE id = ? AND federacion_id = ?', [(int)post('id'), $lid]);
        flash('Sanción anulada.');
        ir('admin.php?p=federacion&s=sanciones');
    }
    if ($accion === 'traer_equipo') {
        if (post('autorizacion') !== '1') throw new RuntimeException('Marque que la liga le entregó los datos de los jugadores con su autorización.');
        $nuevo = traer_equipo_a_federacion($liga, (int)post('equipo_id'), (int)post('torneo_id') ?: null);
        flash('Equipo agregado a la federación con sus jugadores.');
        ir('admin.php?p=equipo&id=' . $nuevo);
    }
}

/* ====================== LIGA O ESCUELA (no federación) ====================== */
if (!$fed) {
    cabecera_admin('Federación');
    echo '<h1>🏛 Federación</h1>' . html_tarjeta_afiliacion($liga);
    if ($f = federacion_de($liga)) {
        $sanc = sanciones_federativas_vigentes($liga);
        $mias = $sanc ? filas('SELECT DISTINCT j.cedula FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.activo = 1 AND j.cedula IN (' . implode(',', array_fill(0, count($sanc), '?')) . ')', array_merge([$lid], array_keys($sanc))) : [];
        echo '<div class="tarjeta"><h2 style="margin-top:0">⛔ Sanciones federativas que afectan a su ' . (es_escuela($liga) ? 'escuela' : 'liga') . '</h2>';
        if (!$mias) echo '<p class="suave">Ninguno de sus jugadores tiene una sanción federativa vigente.</p>';
        foreach ($mias as $m) { $s = $sanc[$m['cedula']]; echo '<p>⛔ <b>' . e($s['nombre'] ?: $s['cedula']) . '</b> · ' . e(texto_sancion_federativa($s)) . '</p>'; }
        echo '</div>';
    }
    pie_admin();
    return;
}

/* ====================== CUENTA DE LA FEDERACIÓN ====================== */
$s = get('s', 'resumen');
$afiliadas = ligas_de_federacion($lid);
$pendientes = ligas_de_federacion($lid, 'pendiente');
$codigo = codigo_federacion($liga);
cabecera_admin('Federación');
?>
<h1>🏛 <?= e($liga['nombre']) ?></h1>
<nav class="pestanas">
  <?php foreach (['resumen' => '📊 Resumen', 'ligas' => '🏆 Ligas y escuelas' . ($pendientes ? ' (' . count($pendientes) . ')' : ''), 'partidos' => '📅 Partidos de la semana', 'buscar' => '🔎 Buscar persona', 'sanciones' => '⛔ Sanciones', 'equipos' => '👕 Equipos para campeonatos federativos'] as $k => $n): ?>
    <a class="<?= $s === $k ? 'activa' : '' ?>" href="admin.php?p=federacion&amp;s=<?= $k ?>"><?= e($n) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'resumen'):
    $tot = [];
    foreach ($afiliadas as $m) foreach (resumen_cuenta_fed($m) as $k => $v) $tot[$k] = ($tot[$k] ?? 0) + $v; ?>
  <div class="cifras">
    <div class="cifra"><b><?= count($afiliadas) ?></b>Ligas y escuelas afiliadas</div>
    <?php foreach ($tot as $k => $v): ?><div class="cifra"><b><?= $v ?></b><?= e($k) ?></div><?php endforeach; ?>
    <div class="cifra"><b><?= count(sanciones_federativas_vigentes($liga)) ?></b>Sanciones vigentes</div>
  </div>
  <?php if ($pendientes): ?><div class="aviso aviso-info">🏛 Hay <b><?= count($pendientes) ?></b> pedido(s) de afiliación por aprobar. <a href="admin.php?p=federacion&amp;s=ligas">Revisar</a></div><?php endif; ?>
  <?php if (!$afiliadas): ?>
    <div class="tarjeta"><h2 style="margin-top:0">Para empezar</h2>
      <ol><li>Comparta su código <b><?= e($codigo) ?></b> con las ligas y escuelas de su federación.</li>
        <li>Cada una lo escribe en su panel: <i>Configuración → Federación</i>.</li>
        <li>Usted aprueba los pedidos en la pestaña <b>Ligas y escuelas</b>.</li>
        <li>Sus campeonatos federativos (inter-ligas) de cualquier deporte se crean en <a href="admin.php?p=torneos">Campeonatos</a>, igual que en una liga.</li></ol></div>
  <?php else: ?>
    <div class="tabla-scroll"><table>
      <tr><th>Liga / escuela</th><th>Deporte</th><th>Números</th><th>Plan</th><th></th></tr>
      <?php foreach ($afiliadas as $m): $r = resumen_cuenta_fed($m); ?>
        <tr><td><b><?= e($m['nombre']) ?></b><br><small class="suave"><?= es_escuela($m) ? '🎓 escuela' : '🏆 liga' ?> · <?= e($m['ciudad']) ?></small></td>
          <td><?= e(es_escuela($m) ? texto_deportes(deportes_de_escuela($m)) : nombre_modalidad($m['modalidad'])) ?></td>
          <td><?= e(implode(' · ', array_map(fn($k, $v) => $v . ' ' . mb_strtolower($k), array_keys($r), $r))) ?></td>
          <td><small><?= e(estado_suscripcion($m)['texto']) ?></small></td>
          <td><a class="btn btn-sec btn-chico" href="<?= e(url_liga($m)) ?>" target="_blank">Página ↗</a></td></tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>

<?php elseif ($s === 'ligas'): ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Código de afiliación: <code style="font-size:1.3rem"><?= e($codigo) ?></code></h2>
    <p>Compártalo con las ligas y escuelas. Ellas lo escriben en <i>Configuración → Federación</i> y a usted le llega el pedido aquí (y por Telegram).</p>
    <?php $msg = 'Hola, para afiliar su liga o escuela a ' . $liga['nombre'] . ' en ' . marca() . ': entre a su panel → Configuración → Federación y escriba el código ' . $codigo; ?>
    <a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/?text=<?= rawurlencode($msg) ?>">💬 Enviar por WhatsApp</a>
    <form method="post" class="inline" onsubmit="return confirm('¿Crear un código nuevo? El anterior deja de servir.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_codigo"><button class="btn btn-sec btn-chico">Cambiar código</button></form>
  </div>
  <?php if ($pendientes): ?>
  <div class="tarjeta"><h2 style="margin-top:0">⏳ Pedidos por aprobar</h2>
    <?php foreach ($pendientes as $m): ?>
      <div class="fila-titulo" style="margin:6px 0"><span><b><?= e($m['nombre']) ?></b> <small class="suave"><?= es_escuela($m) ? 'escuela' : 'liga' ?> · <?= e($m['ciudad']) ?> · <?= e($m['contacto_nombre']) ?> <?= e($m['contacto_telefono']) ?></small></span>
        <span class="acciones"><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-chico">✓ Aprobar</button></form>
          <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sec btn-chico">Rechazar</button></form></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="tarjeta"><h2 style="margin-top:0">Afiliadas (<?= count($afiliadas) ?>)</h2>
    <?php if (!$afiliadas): ?><p class="suave">Todavía no hay ligas ni escuelas afiliadas.</p><?php endif; ?>
    <?php foreach ($afiliadas as $m): ?>
      <div class="fila-titulo" style="margin:6px 0"><span><?= escudo($m['logo'], $m['nombre'], $m['color'], 'escudo') ?> <b><?= e($m['nombre']) ?></b> <small class="suave"><?= es_escuela($m) ? 'escuela' : 'liga' ?> · <?= e($m['ciudad']) ?></small></span>
        <form method="post" class="inline" onsubmit="return confirm('¿Quitar de la federación?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sec btn-chico">Quitar</button></form></div>
    <?php endforeach; ?>
  </div>

<?php elseif ($s === 'partidos'):
    $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('desde')) ? get('desde') : date('Y-m-d');
    $hasta = date('Y-m-d', strtotime($desde . ' +6 days'));
    $ps = partidos_federacion($lid, $desde, $hasta); ?>
  <form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="federacion"><input type="hidden" name="s" value="partidos">
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>" onchange="this.form.submit()"></label> <span class="suave">hasta el <?= e(fecha_bonita($hasta)) ?></span></form>
  <?php if (!$ps): ?><div class="tarjeta vacio">No hay partidos programados en esos días.</div><?php endif; ?>
  <?php $dia = ''; foreach ($ps as $p): if ($p['fecha'] !== $dia): $dia = $p['fecha']; ?><h2><?= e(fecha_bonita($dia)) ?></h2><?php endif; ?>
    <div class="tarjeta" style="padding:8px 12px;margin-bottom:6px"><small class="suave"><?= e($p['liga']) ?> · <?= e($p['torneo']) ?></small><br>
      <b><?= e($p['hora']) ?></b> <?= e($p['local_nombre'] ?? 'Por definir') ?> <?= in_array($p['estado'], ['jugado', 'wo', 'en_vivo'], true) ? '<b>' . (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . '</b>' : 'vs' ?> <?= e($p['visitante_nombre'] ?? 'Por definir') ?>
      <?= $p['cancha'] !== '' ? ' · <small>' . e($p['cancha']) . '</small>' : '' ?> <?= $p['estado'] === 'en_vivo' ? '<span class="etiqueta roja">EN VIVO</span>' : '' ?></div>
  <?php endforeach; ?>

<?php elseif ($s === 'buscar'):
    $r = get('q') !== '' ? buscar_en_federacion($lid, get('q')) : [];
    $sanc = sanciones_federativas_vigentes($liga); ?>
  <form method="get" class="acciones"><input type="hidden" name="p" value="federacion"><input type="hidden" name="s" value="buscar">
    <input name="q" value="<?= e(get('q')) ?>" placeholder="Cédula o nombre" minlength="3" required style="max-width:300px"><button class="btn">Buscar en todas las ligas</button></form>
  <?php if (get('q') !== '' && !$r): ?><div class="tarjeta vacio">No se encontró a nadie con «<?= e(get('q')) ?>».</div><?php endif; ?>
  <?php if ($r): ?><div class="tabla-scroll"><table><tr><th>Persona</th><th>Cédula</th><th>Nacimiento</th><th>Dónde está</th><th>Estado</th></tr>
    <?php foreach ($r as $x): ?><tr><td><?= e($x['tipo']) ?>: <b><?= e($x['nombre']) ?></b></td><td><?= e($x['cedula']) ?></td><td><?= e($x['fecha_nac']) ?></td><td><?= e($x['equipo'] ?? '') ?> · <?= e($x['liga']) ?></td>
      <td><?= isset($sanc[$x['cedula']]) ? '⛔ ' . e(texto_sancion_federativa($sanc[$x['cedula']])) : ((int)$x['activo'] ? 'activo' : 'inactivo') ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  <?php $dup = jugadores_en_varias_ligas($lid); ?>
  <div class="tarjeta" style="margin-top:14px"><h2 style="margin-top:0">👥 Jugadores inscritos en más de una liga (<?= count($dup) ?>)</h2>
    <p class="suave">Misma cédula activa en dos o más ligas de la federación. Revise si su reglamento lo permite.</p>
    <?php foreach ($dup as $d): ?><p style="margin:4px 0"><b><?= e($d['cedula']) ?></b> · <?= e($d['donde']) ?></p><?php endforeach; ?>
  </div>

<?php elseif ($s === 'sanciones'):
    $lista = filas('SELECT * FROM fed_sanciones WHERE federacion_id = ? ORDER BY anulada, id DESC LIMIT 300', [$lid]); ?>
  <div class="tarjeta"><h2 style="margin-top:0">⛔ Nueva sanción federativa</h2>
    <p class="suave">La persona (por su cédula) queda <b>suspendida en todas las ligas afiliadas</b> hasta la fecha que ponga: le aparece al vocal, en el carnet, en la planilla y en la página pública, igual que una tarjeta roja.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="sancion">
      <div class="campos">
        <div><label>Cédula *</label><input name="cedula" required inputmode="numeric"></div>
        <div><label>Nombre (opcional: se busca por la cédula)</label><input name="nombre"></div>
        <div class="campo-ancho"><label>Motivo</label><input name="motivo" maxlength="200" placeholder="Ej: agresión al árbitro (resolución 12-2026)"></div>
        <div><label>Desde</label><input type="date" name="desde" value="<?= date('Y-m-d') ?>"></div>
        <div><label>Hasta (vacío = indefinida)</label><input type="date" name="hasta"></div>
      </div>
      <button class="btn">Registrar sanción</button></form></div>
  <?php if ($lista): ?><div class="tabla-scroll"><table><tr><th>Persona</th><th>Motivo</th><th>Desde</th><th>Hasta</th><th></th></tr>
    <?php foreach ($lista as $x): $vig = !$x['anulada'] && $x['desde'] <= date('Y-m-d') && ($x['hasta'] === '' || $x['hasta'] >= date('Y-m-d')); ?>
      <tr style="<?= $x['anulada'] ? 'text-decoration:line-through;color:var(--suave)' : '' ?>"><td><b><?= e($x['nombre'] ?: '—') ?></b><br><small><?= e($x['cedula']) ?></small></td><td><?= e($x['motivo']) ?></td><td><?= e($x['desde']) ?></td><td><?= e($x['hasta'] ?: 'indefinida') ?></td>
        <td><?= $vig ? '<span class="estado-chip chip-mal">vigente</span>' : ($x['anulada'] ? 'anulada' : '<span class="estado-chip">cumplida</span>') ?>
          <?php if (!$x['anulada']): ?><form method="post" class="inline" onsubmit="return confirm('¿Anular esta sanción?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular_sancion"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-mini">Anular</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table></div><?php endif; ?>

<?php elseif ($s === 'equipos'):
    $ligas_eq = array_values(array_filter($afiliadas, fn($m) => !es_escuela($m)));
    $sel = (int)get('liga');
    $torneos_f = filas("SELECT * FROM torneos WHERE liga_id = ? AND estado <> 'finalizado' AND " . sql_sin_carreras() . ' ORDER BY id DESC', [$lid]);
    $ya = array_map('intval', array_column(filas('SELECT origen_id FROM equipos WHERE liga_id = ? AND origen_id IS NOT NULL', [$lid]), 'origen_id')); ?>
  <p class="suave">Para un campeonato federativo (selecciones, campeones de cada liga, inter-ligas): traiga el equipo de una liga afiliada con todos sus jugadores activos. Queda como un equipo de la federación; los cambios después no afectan a la liga.
    Para deportes individuales use la <a href="admin.php?p=cargas&amp;tipo=competidores">subida desde Excel</a> o la inscripción en línea del campeonato.</p>
  <form method="get" class="acciones"><input type="hidden" name="p" value="federacion"><input type="hidden" name="s" value="equipos">
    <select name="liga" onchange="this.form.submit()"><option value="">— Elija la liga —</option><?php foreach ($ligas_eq as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $sel === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?></select></form>
  <?php if ($sel && in_array($sel, array_map('intval', array_column($ligas_eq, 'id')), true)):
      $eqs = filas('SELECT e.*, (SELECT COUNT(*) FROM jugadores j WHERE j.equipo_id = e.id AND j.activo = 1) AS n FROM equipos e WHERE e.liga_id = ? ORDER BY e.nombre', [$sel]); ?>
    <form method="post" class="tarjeta" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="traer_equipo">
      <div class="campos">
        <div><label>Equipo</label><select name="equipo_id" required><?php foreach ($eqs as $e): ?><option value="<?= (int)$e['id'] ?>" <?= in_array((int)$e['id'], $ya, true) ? 'disabled' : '' ?>><?= e($e['nombre']) ?> (<?= (int)$e['n'] ?> jugadores)<?= in_array((int)$e['id'], $ya, true) ? ' — ya está' : '' ?></option><?php endforeach; ?></select></div>
        <div><label>Inscribirlo en el campeonato (opcional)</label><select name="torneo_id"><option value="">— ninguno —</option><?php foreach ($torneos_f as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre'] . ' ' . $t['temporada']) ?></option><?php endforeach; ?></select></div>
      </div>
      <label class="check" style="font-weight:400"><input type="checkbox" name="autorizacion" value="1" required> 🔒 La liga nos entregó los datos de sus jugadores para los campeonatos de la federación, con la autorización de cada jugador o de su representante (Ley de Protección de Datos Personales).</label>
      <button class="btn" style="margin-top:8px">Traer equipo con sus jugadores</button></form>
  <?php endif; ?>
<?php endif;
pie_admin();
