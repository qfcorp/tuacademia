<?php
/* INSCRIPCIONES EN LÍNEA: la liga revisa, aprueba o rechaza lo que envían los delegados y los equipos nuevos */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];

function solicitud_jugador_de_liga(int $id, int $lid): array
{
    $s = fila('SELECT s.*, e.nombre AS equipo, e.telefono AS tel_equipo FROM solicitudes_jugador s JOIN equipos e ON e.id = s.equipo_id WHERE s.id = ? AND e.liga_id = ?', [$id, $lid]);
    if (!$s) throw new RuntimeException('Solicitud no encontrada.');
    if ($s['estado'] !== 'pendiente') throw new RuntimeException('Esa solicitud ya fue resuelta.');
    return $s;
}

if (es_post()) {
    $accion = post('accion');
    $ahora = date('Y-m-d H:i');

    if ($accion === 'aprobar_jugador') {
        $s = solicitud_jugador_de_liga((int)post('id'), $lid);
        if ($s['tipo'] === 'baja') {
            q('UPDATE jugadores SET activo = 0 WHERE id = ? AND equipo_id = ?', [$s['jugador_id'], $s['equipo_id']]);
            aviso_delegados($liga, (int)$s['equipo_id'], '✅ Baja aprobada: ' . e(nombre_jugador($s)), 'solj_ok:' . $s['id']);
            q("UPDATE solicitudes_jugador SET estado = 'aprobado', respuesta = ?, revisado_por = ?, revisado_en = ? WHERE id = ?", [mb_substr(post('respuesta') ?: 'Baja aprobada', 0, 300), $por, $ahora, $s['id']]);
            flash('Baja aprobada: ' . nombre_jugador($s) . ' quedó inactivo (se conservan sus estadísticas).');
            ir('admin.php?p=inscripciones');
        }
        if ($err = cedula_ocupada($s['cedula'], $lid, (int)$s['id'])) throw new RuntimeException($err);
        [$mx, $act] = cupo_equipo((int)$s['equipo_id'], $liga);
        if ($act >= $mx) throw new RuntimeException('El equipo ya tiene el máximo de ' . $mx . ' jugadores activos.');
        $numero = entero_o_null(post('numero'));
        if ($numero !== null && fila('SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ? AND activo = 1', [$s['equipo_id'], $numero])) {
            throw new RuntimeException('El número ' . $numero . ' ya lo usa otro jugador del equipo. Cámbielo antes de aprobar.');
        }
        $pos = in_array(post('posicion'), ['', 'Arquero', 'Defensa', 'Mediocampista', 'Delantero'], true) ? post('posicion') : $s['posicion'];
        $notas = validar_categoria_jugador((int)$s['equipo_id'], $s);
        q('INSERT INTO jugadores (equipo_id, nombres, apellidos, cedula, numero, posicion, fecha_nac, foto, consentimiento, consentimiento_por, representante_nombre, representante_cedula, representante_telefono, foto_publica)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$s['equipo_id'], $s['nombres'], $s['apellidos'], $s['cedula'], $numero, $pos, $s['fecha_nac'], $s['foto'],
             $s['consentimiento'], $s['consentimiento_por'], $s['representante_nombre'], $s['representante_cedula'], $s['representante_telefono'], (int)$s['foto_publica']]);
        $jid = (int)db()->lastInsertId();
        aviso_delegados($liga, (int)$s['equipo_id'], '✅ <b>Jugador aprobado</b>' . "
" . e(nombre_jugador($s)) . ' ya está inscrito en ' . e($s['equipo']) . '.', 'solj_ok:' . $s['id']);
        q("UPDATE solicitudes_jugador SET estado = 'aprobado', jugador_id = ?, numero = ?, respuesta = ?, revisado_por = ?, revisado_en = ? WHERE id = ?",
            [$jid, $numero, mb_substr(post('respuesta') ?: 'Aprobado', 0, 300), $por, $ahora, $s['id']]);
        flash('Jugador aprobado e inscrito en ' . $s['equipo'] . ': ' . nombre_jugador($s) . '. Ya puede imprimir su carnet.' . ($notas ? ' ' . implode(' · ', $notas) : ''));
        ir('admin.php?p=inscripciones');
    }

    if ($accion === 'rechazar_jugador') {
        $s = solicitud_jugador_de_liga((int)post('id'), $lid);
        if (mb_strlen(post('respuesta')) < 3) throw new RuntimeException('Escriba el motivo del rechazo (el delegado lo verá).');
        q("UPDATE solicitudes_jugador SET estado = 'rechazado', respuesta = ?, revisado_por = ?, revisado_en = ? WHERE id = ?", [mb_substr(post('respuesta'), 0, 300), $por, $ahora, $s['id']]);
        aviso_delegados($liga, (int)$s['equipo_id'], '❌ <b>Solicitud rechazada</b>' . "
" . e(nombre_jugador($s)) . "
Motivo: " . e(post('respuesta')), 'solj_no:' . $s['id']);
        if ($s['tipo'] === 'alta') borrar_imagen($s['foto']);
        flash('Solicitud rechazada.');
        ir('admin.php?p=inscripciones');
    }

    if ($accion === 'aprobar_pase' || $accion === 'rechazar_pase') {
        $ps = fila("SELECT * FROM pases WHERE id = ? AND liga_id = ? AND estado = 'pendiente'", [(int)post('id'), $lid]) ?? throw new RuntimeException('Pedido de pase no encontrado o ya resuelto.');
        $j = fila('SELECT * FROM jugadores WHERE id = ?', [$ps['jugador_id']]) ?? throw new RuntimeException('Jugador no encontrado.');
        if ($accion === 'rechazar_pase') {
            if (mb_strlen(post('respuesta')) < 3) throw new RuntimeException('Escriba el motivo del rechazo.');
            q("UPDATE pases SET estado = 'rechazado', respuesta = ?, revisado_por = ?, fecha = ? WHERE id = ?", [mb_substr(post('respuesta'), 0, 300), $por, date('Y-m-d'), $ps['id']]);
            aviso_delegados($liga, (int)$ps['destino_id'], '❌ Pase rechazado: ' . e(nombre_jugador($j)) . "
Motivo: " . e(post('respuesta')), 'pase_no:' . $ps['id']);
            flash('Pedido de pase rechazado.');
            ir('admin.php?p=inscripciones');
        }
        if ((int)$j['equipo_id'] !== (int)$ps['origen_id']) throw new RuntimeException('El jugador ya cambió de equipo desde que se pidió el pase. Rechace este pedido.');
        if (post('respuesta') !== '') q('UPDATE pases SET respuesta = ? WHERE id = ?', [mb_substr(post('respuesta'), 0, 300), $ps['id']]);
        ejecutar_pase($liga, $j, (int)$ps['destino_id'], $ps['motivo'], max(0, round((float)str_replace(',', '.', post('costo')), 2)), $por, (int)$ps['id'], post('forzar') === '1');
        flash('Pase aprobado: ' . nombre_jugador($j) . '.');
        ir('admin.php?p=inscripciones');
    }

    if ($accion === 'aprobar_equipo' || $accion === 'rechazar_equipo') {
        $s = fila("SELECT * FROM solicitudes_equipo WHERE id = ? AND liga_id = ? AND estado = 'pendiente'", [(int)post('id'), $lid]);
        if (!$s) throw new RuntimeException('Solicitud no encontrada o ya resuelta.');
        if ($accion === 'rechazar_equipo') {
            if (mb_strlen(post('respuesta')) < 3) throw new RuntimeException('Escriba el motivo del rechazo.');
            q("UPDATE solicitudes_equipo SET estado = 'rechazado', respuesta = ?, clave = '', revisado_por = ?, revisado_en = ? WHERE id = ?", [mb_substr(post('respuesta'), 0, 300), $por, $ahora, $s['id']]);
            borrar_imagen($s['escudo']);
            flash('Solicitud del equipo rechazada.');
            ir('admin.php?p=inscripciones');
        }
        if (fila('SELECT id FROM equipos WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $s['nombre']])) throw new RuntimeException('Ya existe un equipo llamado "' . $s['nombre'] . '" en la liga.');
        verificar_limite_plan($liga, 'equipo');
        if ($s['torneo_id']) verificar_equipos_torneo($liga, (int)$s['torneo_id'], (int)valor('SELECT COUNT(*) FROM torneo_equipos WHERE torneo_id = ?', [$s['torneo_id']]) + 1); // (v12)
        if (fila('SELECT id FROM usuarios WHERE lower(email) = lower(?)', [$s['email']])) throw new RuntimeException('El correo ' . $s['email'] . ' ya tiene una cuenta. Rechace la solicitud y pida otro correo.');
        $pdo = db();
        $pdo->beginTransaction();
        q('INSERT INTO equipos (liga_id, nombre, abreviatura, escudo, color, delegado, telefono) VALUES (?,?,?,?,?,?,?)',
            [$lid, $s['nombre'], $s['abreviatura'], $s['escudo'], $s['color'], $s['delegado'], $s['telefono']]);
        $eid = (int)$pdo->lastInsertId();
        if ($s['torneo_id'] && fila('SELECT id FROM torneos WHERE id = ? AND liga_id = ?', [$s['torneo_id'], $lid])) {
            q('INSERT OR IGNORE INTO torneo_equipos (torneo_id, equipo_id) VALUES (?,?)', [$s['torneo_id'], $eid]);
        }
        q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id, equipo_id) VALUES (?,?,?, 'delegado', ?, ?)", [$s['delegado'], mb_strtolower($s['email']), $s['clave'], $lid, $eid]);
        q("UPDATE solicitudes_equipo SET estado = 'aprobado', equipo_id = ?, clave = '', respuesta = ?, revisado_por = ?, revisado_en = ? WHERE id = ?", [$eid, mb_substr(post('respuesta') ?: 'Aprobado', 0, 300), $por, $ahora, $s['id']]);
        // (v11) Si el equipo lo mandó una escuela de TuAcademia, sus alumnos pasan como jugadores
        $n_esc = 0;
        if (!empty($s['escuela_grupo_id']) && ($g_esc = fila('SELECT g.*, l.nombre AS escuela FROM esc_grupos g JOIN ligas l ON l.id = g.liga_id WHERE g.id = ?', [$s['escuela_grupo_id']]))) {
            $n_esc = cargar_alumnos_en_equipo((int)$g_esc['id'], $eid, $g_esc['escuela']);
        }
        $pdo->commit();
        if ($n_esc) flash($n_esc . ' jugador(es) cargado(s) desde la escuela.');
        flash('Equipo ' . $s['nombre'] . ' aprobado. El delegado ya puede entrar con su correo y la contraseña que eligió, e inscribir a sus jugadores.');
        ir('admin.php?p=inscripciones');
    }
    ir('admin.php?p=inscripciones');
}

$altas = filas("SELECT s.*, e.nombre AS equipo, u.nombre AS delegado_u FROM solicitudes_jugador s JOIN equipos e ON e.id = s.equipo_id LEFT JOIN usuarios u ON u.id = s.usuario_id
                WHERE e.liga_id = ? AND s.estado = 'pendiente' ORDER BY s.tipo, s.id", [$lid]);
$pases_pend = filas("SELECT ps.*, j.nombres, j.apellidos, j.cedula, j.foto, j.fecha_nac, j.numero, eo.nombre AS origen, ed.nombre AS destino FROM pases ps JOIN jugadores j ON j.id = ps.jugador_id
                      LEFT JOIN equipos eo ON eo.id = ps.origen_id JOIN equipos ed ON ed.id = ps.destino_id WHERE ps.liga_id = ? AND ps.estado = 'pendiente' ORDER BY ps.id", [$lid]);
$equipos_sol = filas("SELECT s.*, t.nombre AS torneo FROM solicitudes_equipo s LEFT JOIN torneos t ON t.id = s.torneo_id WHERE s.liga_id = ? AND s.estado = 'pendiente' ORDER BY s.id", [$lid]);
$hist = filas("SELECT * FROM (
        SELECT 'jugador' AS clase, s.id, s.tipo, s.nombres || ' ' || s.apellidos AS nombre, e.nombre AS equipo, e.telefono, s.estado, s.respuesta, s.revisado_por, s.revisado_en FROM solicitudes_jugador s JOIN equipos e ON e.id = s.equipo_id WHERE e.liga_id = ? AND s.estado IN ('aprobado','rechazado')
        UNION ALL
        SELECT 'equipo', id, 'equipo', nombre, delegado, telefono, estado, respuesta, revisado_por, revisado_en FROM solicitudes_equipo WHERE liga_id = ? AND estado IN ('aprobado','rechazado')
      ) ORDER BY revisado_en DESC LIMIT 60", [$lid, $lid]);
$abiertas = filas("SELECT nombre, jugadores_hasta, equipos_hasta FROM torneos WHERE liga_id = ? AND estado <> 'finalizado' AND (jugadores_hasta >= ? OR equipos_hasta >= ?)", [$lid, date('Y-m-d'), date('Y-m-d')]);

cabecera_admin('Inscripciones');
?>
<h1>📝 Inscripciones en línea</h1>
<?php if ($abiertas): ?>
  <div class="aviso aviso-info">Abiertas: <?php foreach ($abiertas as $i => $a): ?><?= $i ? ' · ' : '' ?><b><?= e($a['nombre']) ?></b><?= $a['jugadores_hasta'] >= date('Y-m-d') ? ' — jugadores hasta ' . e($a['jugadores_hasta']) : '' ?><?= $a['equipos_hasta'] >= date('Y-m-d') ? ' — equipos nuevos hasta ' . e($a['equipos_hasta']) . ' (<a target="_blank" href="index.php?l=' . e($liga['slug']) . '&amp;v=inscribir">formulario público</a>)' : '' ?><?php endforeach; ?></div>
<?php else: ?>
  <div class="aviso aviso-info">No hay inscripciones abiertas. Se abren en <b>Campeonatos → el campeonato → Configuración → Inscripción en línea</b>, poniendo una fecha límite.</div>
<?php endif; ?>

<h2>Jugadores (<?= count($altas) ?> pendientes)</h2>
<?php if (!$altas): ?><p class="suave">No hay solicitudes de jugadores pendientes.</p><?php endif; ?>
<div class="rejilla-2">
<?php foreach ($altas as $s):
    $doc_ok = $s['tipo'] === 'baja' || error_documento($s['cedula']) === '';
    $dup = $s['tipo'] === 'alta' ? cedula_ocupada($s['cedula'], $lid, (int)$s['id']) : '';
    [$mx, $act] = cupo_equipo((int)$s['equipo_id'], $liga);
?>
  <div class="tarjeta">
    <p style="margin:0 0 8px"><span class="etiqueta <?= $s['tipo'] === 'alta' ? 'verde' : 'roja' ?>"><?= $s['tipo'] === 'alta' ? 'INSCRIPCIÓN' : 'BAJA' ?></span> <b><?= e($s['equipo']) ?></b> <small class="suave">· enviado por <?= e($s['delegado_u'] ?? 'delegado') ?> · <?= e(substr($s['creado'], 0, 16)) ?></small></p>
    <div class="perfil">
      <?php if ($s['tipo'] === 'alta'): ?><a href="<?= e($s['foto']) ?>" target="_blank"><?= foto_jugador($s, 'foto-grande') ?></a><?php endif; ?>
      <div>
        <h3 style="margin:0"><?= e(nombre_jugador($s)) ?></h3>
        <p style="margin:.3rem 0">C.I. <b><?= e($s['cedula']) ?></b> <?= $doc_ok ? '<span class="etiqueta verde">válida</span>' : '<span class="etiqueta roja">no válida</span>' ?></p>
        <?php if ($s['tipo'] === 'alta'): ?>
          <p style="margin:.3rem 0">Nacimiento: <?= e($s['fecha_nac']) ?> (<?= edad($s['fecha_nac']) ?> años) · N° <?= $s['numero'] !== null ? (int)$s['numero'] : '—' ?> · <?= e($s['posicion'] ?: 'sin posición') ?></p>
          <p style="margin:.3rem 0" class="suave">Plantilla: <?= $act ?>/<?= $mx ?><?php foreach (torneos_categoria_equipo((int)$s['equipo_id']) as $tc) echo etiqueta_categoria(situacion_categoria($tc, $s)); ?></p>
          <p style="margin:.3rem 0" class="suave">🔒 <?= $s['consentimiento'] !== '' ? 'Autorizó ' . ($s['consentimiento_por'] === 'representante' ? 'su representante: ' . e($s['representante_nombre']) . ' (C.I. ' . e($s['representante_cedula']) . ', ' . e($s['representante_telefono']) . ')' : 'el jugador') . ' · foto pública: ' . ($s['foto_publica'] ? 'sí' : 'no') : 'Sin autorización registrada' ?></p>
        <?php else: ?>
          <p><b>Motivo:</b> <?= e($s['motivo']) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($dup): ?><div class="aviso aviso-error"><?= e($dup) ?></div><?php endif; ?>
    <form method="post" style="margin-top:10px">
      <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <?php if ($s['tipo'] === 'alta'): ?>
      <div class="campos">
        <div><label>Número</label><input type="number" name="numero" min="0" max="99" value="<?= e($s['numero']) ?>"></div>
        <div><label>Posición</label><select name="posicion"><?php foreach (['', 'Arquero', 'Defensa', 'Mediocampista', 'Delantero'] as $po): ?><option value="<?= e($po) ?>" <?= $s['posicion'] === $po ? 'selected' : '' ?>><?= $po === '' ? '—' : e($po) ?></option><?php endforeach; ?></select></div>
      </div>
      <?php endif; ?>
      <p><label>Respuesta al delegado</label><input type="text" name="respuesta" maxlength="300" placeholder="Obligatoria si rechaza (ej: la foto no se ve bien)"></p>
      <div class="acciones">
        <button class="btn" name="accion" value="aprobar_jugador" <?= $dup ? 'disabled' : '' ?>>✓ Aprobar</button>
        <button class="btn btn-peligro" name="accion" value="rechazar_jugador">✗ Rechazar</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>

<?php if ($pases_pend): ?>
<h2>🔁 Pedidos de pase (<?= count($pases_pend) ?>)</h2>
<div class="rejilla-2">
<?php foreach ($pases_pend as $ps): $est = estado_jugador(fila('SELECT * FROM jugadores WHERE id = ?', [$ps['jugador_id']])); ?>
  <div class="tarjeta">
    <div class="perfil"><?= foto_jugador($ps, 'foto-grande') ?>
      <div><h3 style="margin:0"><?= e(nombre_jugador($ps)) ?></h3>
        <p style="margin:.3rem 0"><?= e($ps['origen'] ?? '—') ?> → <b><?= e($ps['destino']) ?></b></p>
        <p class="suave" style="margin:.3rem 0">C.I. <?= e($ps['cedula']) ?> · <?= edad($ps['fecha_nac']) !== null ? edad($ps['fecha_nac']) . ' años' : 'sin fecha nac.' ?> · pedido por <?= e($ps['solicitado_por']) ?></p>
        <?php if ($ps['motivo'] !== ''): ?><p style="margin:.3rem 0">“<?= e($ps['motivo']) ?>”</p><?php endif; ?>
        <?php if ($est['estado'] === 'suspendido'): ?><p class="aviso aviso-error" style="margin:.3rem 0">Tiene suspensión pendiente: <?= e(implode(' ', $est['detalle'])) ?></p><?php endif; ?></div></div>
    <form method="post" style="margin-top:10px">
      <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$ps['id'] ?>">
      <div class="campos"><div><label>Valor del pase ($)</label><input type="number" name="costo" min="0" step="0.01" value="<?= e(number_format((float)$ps['costo'], 2, '.', '')) ?>"></div>
        <div class="campo-ancho"><label>Respuesta</label><input type="text" name="respuesta" maxlength="300" placeholder="Obligatoria si rechaza"></div></div>
      <?php if ($est['estado'] === 'suspendido'): ?><label class="check" style="font-weight:400"><input type="checkbox" name="forzar" value="1"> Autorizar aunque tenga suspensión</label><?php endif; ?>
      <div class="acciones"><button class="btn" name="accion" value="aprobar_pase">✓ Aprobar pase</button><button class="btn btn-peligro" name="accion" value="rechazar_pase">✗ Rechazar</button></div>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h2>Equipos nuevos (<?= count($equipos_sol) ?> pendientes)</h2>
<?php if (!$equipos_sol): ?><p class="suave">No hay equipos pendientes.</p><?php endif; ?>
<div class="rejilla-2">
<?php foreach ($equipos_sol as $s): ?>
  <div class="tarjeta">
    <div class="perfil"><?= escudo($s['escudo'], $s['nombre'], $s['color'], 'escudo-grande') ?>
      <div><h3 style="margin:0"><?= e($s['nombre']) ?></h3>
        <p style="margin:.3rem 0">Campeonato: <b><?= e($s['torneo'] ?? '—') ?></b></p>
        <?php if (!empty($s['escuela_grupo_id']) && ($ge = fila('SELECT g.nombre, l.nombre AS escuela, (SELECT COUNT(*) FROM esc_alumnos a WHERE (a.grupo_id = g.id OR a.id IN (SELECT alumno_id FROM esc_alumno_grupos WHERE grupo_id = g.id)) AND a.estado = \'activo\') AS n FROM esc_grupos g JOIN ligas l ON l.id = g.liga_id WHERE g.id = ?', [$s['escuela_grupo_id']]))): ?>
        <p style="margin:.3rem 0">🎓 Viene de la escuela <b><?= e($ge['escuela']) ?></b> (grupo <?= e($ge['nombre']) ?>): al aprobar se cargan <b><?= (int)$ge['n'] ?></b> jugador(es) con sus fotos y autorizaciones.</p>
        <?php endif; ?>
        <p style="margin:.3rem 0">Delegado: <b><?= e($s['delegado']) ?></b> · C.I. <?= e($s['cedula']) ?> <?= error_documento($s['cedula']) === '' ? '<span class="etiqueta verde">válida</span>' : '<span class="etiqueta roja">no válida</span>' ?></p>
        <p style="margin:.3rem 0">📞 <?= e($s['telefono']) ?> · ✉ <?= e($s['email']) ?></p>
        <p class="suave" style="margin:.3rem 0">Enviado <?= e(substr($s['creado'], 0, 16)) ?> · IP <?= e($s['ip']) ?></p></div></div>
    <form method="post" style="margin-top:10px">
      <?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <p><label>Respuesta</label><input type="text" name="respuesta" maxlength="300" placeholder="Obligatoria si rechaza"></p>
      <div class="acciones"><button class="btn" name="accion" value="aprobar_equipo">✓ Aprobar equipo y crear acceso del delegado</button><button class="btn btn-peligro" name="accion" value="rechazar_equipo">✗ Rechazar</button></div>
    </form>
  </div>
<?php endforeach; ?>
</div>

<?php if ($hist): ?>
<h2>Resueltas recientemente</h2>
<div class="tabla-scroll"><table>
  <tr><th>Fecha</th><th>Tipo</th><th>Nombre</th><th>Equipo / delegado</th><th>Resultado</th><th>Revisó</th><th></th></tr>
  <?php foreach ($hist as $h):
      $wa = telefono_wa((string)$h['telefono']);
      $msg = $liga['nombre'] . ': la solicitud de ' . ($h['tipo'] === 'equipo' ? 'inscripción del equipo ' : ($h['tipo'] === 'baja' ? 'baja de ' : 'inscripción de ')) . $h['nombre'] . ' fue ' . strtoupper($h['estado']) . ($h['respuesta'] ? '. ' . $h['respuesta'] : '') . '. Ingrese a ' . url_base() . 'admin.php?p=login';
  ?>
    <tr><td><?= e($h['revisado_en']) ?></td><td><?= e(['alta' => 'Jugador', 'baja' => 'Baja', 'equipo' => 'Equipo'][$h['tipo']] ?? $h['tipo']) ?></td><td><?= e($h['nombre']) ?></td><td><?= e($h['equipo']) ?></td>
      <td><span class="estado-chip <?= $h['estado'] === 'aprobado' ? 'chip-ok' : 'chip-mal' ?>"><?= e(ucfirst($h['estado'])) ?></span> <small><?= e($h['respuesta']) ?></small></td><td><?= e($h['revisado_por']) ?></td>
      <td><?php if ($wa): ?><a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($msg) ?>">📲 Avisar</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php
pie_admin();
