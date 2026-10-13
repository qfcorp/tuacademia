<?php
/* (v11) GRUPOS DE ENTRENAMIENTO: categoría (Sub-N o abierta), sede, profesor, días, horas, cupo, mensualidad
 * y, si se quiere, la conexión con un campeonato de una liga de TuVocalía (opcional, por grupo). */
$liga = requiere_liga();
$lid = (int)$liga['id'];
preparar_escuela($lid);

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = (int)post('id');
        if ($id) grupo_de_escuela($id, $lid);
        if (mb_strlen(post('nombre')) < 2) throw new RuntimeException('Escriba el nombre del grupo (ej: Sub-10 mañana).');
        $cat = post('categoria') === 'sub' ? 'sub' : 'abierta';
        $desde = post('anio_desde') !== '' ? entero(post('anio_desde'), 1950, (int)date('Y')) : null;
        $hasta = post('anio_hasta') !== '' ? entero(post('anio_hasta'), 1950, (int)date('Y')) : $desde;
        if ($cat === 'sub' && !$desde) throw new RuntimeException('Para una categoría Sub, escriba el año de nacimiento (ej: 2016).');
        $sede = (int)post('sede_id');
        $prof = (int)post('profesor_id');
        $d = [
            'nombre' => mb_substr(post('nombre'), 0, 60), 'categoria' => $cat, 'anio_desde' => $cat === 'sub' ? $desde : null, 'anio_hasta' => $cat === 'sub' ? $hasta : null,
            'sede_id' => $sede && fila('SELECT id FROM esc_sedes WHERE id = ? AND liga_id = ?', [$sede, $lid]) ? $sede : null,
            'profesor_id' => $prof && fila('SELECT id FROM esc_profesores WHERE id = ? AND liga_id = ?', [$prof, $lid]) ? $prof : null,
            'dias' => implode(',', array_values(array_filter(array_map('intval', (array)($_POST['dias'] ?? [])), fn($x) => $x >= 1 && $x <= 7))),
            'hora_inicio' => hora_valida(post('hora_inicio')), 'hora_fin' => hora_valida(post('hora_fin')), 'cancha' => mb_substr(post('cancha'), 0, 60),
            'cupo' => entero(post('cupo'), 0, 500), 'mensualidad' => post('mensualidad') === '' ? null : max(0, round((float)str_replace(',', '.', post('mensualidad')), 2)),
            'activo' => post('activo') === '1' ? 1 : 0,
            // (v19) Deporte del grupo
            'deporte' => isset(catalogo_deportes()[post('deporte')]) ? post('deporte') : ($liga['deporte_escuela'] ?: 'futbol'),
        ];
        $dep_antes = $id ? (string)valor('SELECT deporte FROM esc_grupos WHERE id = ? AND liga_id = ?', [$id, $lid]) : '';
        if ($d['deporte'] !== $dep_antes) exigir_deporte_escuela($liga, $d['deporte']); // (v35) módulos deportivos
        if ($id) {
            q('UPDATE esc_grupos SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d))) . ' WHERE id = :id', $d + ['id' => $id]);
        } else {
            $d['liga_id'] = $lid;
            q('INSERT INTO esc_grupos (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
        }
        preparar_deporte($lid, $d['deporte']); // tests, habilidades y ejercicios de fábrica de ese deporte
        flash('Grupo guardado.');
        ir('admin.php?p=grupos');
    }
    if ($accion === 'enviar_liga') {
        // (52, opcional) Mandar este grupo como equipo a un campeonato de una liga de TuVocalía
        $g = grupo_de_escuela((int)post('id'), $lid);
        $t = null;
        foreach (campeonatos_abiertos() as $c) if ((int)$c['id'] === (int)post('torneo_id')) $t = $c;
        if (!$t) throw new RuntimeException('Elija un campeonato con la inscripción abierta.');
        $email = mb_strtolower(trim(post('email')));
        if (mb_strlen(post('delegado')) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escriba el nombre y el correo del delegado del equipo.');
        if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña del delegado debe tener al menos 8 caracteres.');
        if (fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email])) throw new RuntimeException('Ese correo ya tiene una cuenta en TuVocalía. Use otro correo para el delegado de este equipo.');
        if (fila("SELECT id FROM solicitudes_equipo WHERE escuela_grupo_id = ? AND estado = 'pendiente'", [$g['id']])) throw new RuntimeException('Este grupo ya tiene una solicitud pendiente en una liga.');
        $nombre = mb_substr(trim(post('nombre_equipo')) ?: ($liga['nombre'] . ' ' . $g['nombre']), 0, 80);
        q('INSERT INTO solicitudes_equipo (liga_id, torneo_id, nombre, abreviatura, escudo, color, delegado, cedula, telefono, email, clave, ip, escuela_grupo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $t['liga_id'], $t['id'], $nombre, mb_strtoupper(mb_substr(slugify($liga['nombre']), 0, 3)), $liga['logo'] ?? '', $liga['color'], mb_substr(post('delegado'), 0, 80), '',
            mb_substr(post('telefono'), 0, 30), $email, password_hash(post('clave'), PASSWORD_DEFAULT), ip_cliente(), $g['id'],
        ]);
        bitacora_evento('escuela', 'Envió el grupo ' . $g['nombre'] . ' como equipo "' . $nombre . '" a ' . $t['liga'] . ' (' . $t['nombre'] . ')', $lid, (int)$g['id']);
        flash('Solicitud enviada a ' . $t['liga'] . '. Cuando la liga la apruebe, sus alumnos del grupo quedan inscritos como jugadores y el delegado puede entrar con ese correo.');
        ir('admin.php?p=grupos#g' . (int)$g['id']);
    }
    if ($accion === 'sincronizar') {
        // Alumnos nuevos del grupo → solicitudes de alta en el equipo de la liga (la liga las aprueba)
        $g = grupo_de_escuela((int)post('id'), $lid);
        $eq = $g['equipo_destino_id'] ? fila('SELECT * FROM equipos WHERE id = ?', [$g['equipo_destino_id']]) : null;
        if (!$eq) throw new RuntimeException('Este grupo no está conectado con ningún equipo de una liga.');
        $del = fila("SELECT id FROM usuarios WHERE equipo_id = ? AND rol = 'delegado' LIMIT 1", [$eq['id']]);
        $n = 0;
        foreach (alumnos_grupo((int)$g['id']) as $a) {
            $ya = $a['cedula'] !== '' ? fila('SELECT id FROM jugadores WHERE equipo_id = ? AND cedula = ?', [$eq['id'], $a['cedula']])
                                       : fila('SELECT id FROM jugadores WHERE equipo_id = ? AND lower(nombres) = lower(?) AND lower(apellidos) = lower(?)', [$eq['id'], $a['nombres'], $a['apellidos']]);
            $pend = $a['cedula'] !== '' && fila("SELECT id FROM solicitudes_jugador WHERE equipo_id = ? AND cedula = ? AND estado = 'pendiente'", [$eq['id'], $a['cedula']]);
            if ($ya || $pend) continue;
            q("INSERT INTO solicitudes_jugador (equipo_id, usuario_id, tipo, nombres, apellidos, cedula, posicion, fecha_nac, foto, motivo, consentimiento, consentimiento_por, representante_nombre, representante_cedula, representante_telefono, foto_publica)
               VALUES (?,?, 'alta', ?,?,?,?,?,?,?,?,?,?,?,?,?)", [$eq['id'], $del['id'] ?? null, $a['nombres'], $a['apellidos'], $a['cedula'], $a['posicion'], $a['fecha_nac'], $a['foto'],
                'Alumno de la escuela ' . $liga['nombre'], $a['consentimiento'] ?: date('Y-m-d H:i:s'), es_menor($a['fecha_nac']) ? 'representante' : 'jugador',
                $a['representante_nombre'], $a['representante_cedula'], $a['representante_telefono'], (int)$a['foto_publica']]);
            $n++;
        }
        flash($n ? $n . ' alumno(s) nuevo(s) enviado(s) a la liga para su aprobación.' : 'No hay alumnos nuevos para enviar: el equipo ya los tiene a todos.');
        ir('admin.php?p=grupos#g' . (int)$g['id']);
    }
    if ($accion === 'desconectar') {
        $g = grupo_de_escuela((int)post('id'), $lid);
        q('UPDATE esc_grupos SET equipo_destino_id = NULL WHERE id = ?', [$g['id']]);
        flash('El grupo quedó desconectado de la liga (el equipo en la liga no se borra).');
        ir('admin.php?p=grupos#g' . (int)$g['id']);
    }
}

$grupos = filas('SELECT g.*, s.nombre AS sede, p.nombre AS profesor, (SELECT COUNT(*) FROM esc_alumnos a WHERE (a.grupo_id = g.id OR a.id IN (SELECT alumno_id FROM esc_alumno_grupos WHERE grupo_id = g.id)) AND a.estado = \'activo\') AS n_alumnos
                 FROM esc_grupos g LEFT JOIN esc_sedes s ON s.id = g.sede_id LEFT JOIN esc_profesores p ON p.id = g.profesor_id WHERE g.liga_id = ? ORDER BY g.activo DESC, s.nombre, g.anio_desde DESC, g.nombre', [$lid]);
$sedes = filas('SELECT * FROM esc_sedes WHERE liga_id = ? AND activa = 1 ORDER BY nombre', [$lid]);
$profes = filas('SELECT * FROM esc_profesores WHERE liga_id = ? AND activo = 1 ORDER BY nombre', [$lid]);
$editar = get('editar') !== '' ? fila('SELECT * FROM esc_grupos WHERE id = ? AND liga_id = ?', [(int)get('editar'), $lid]) : null;
$abiertos = campeonatos_abiertos();
$ed = puede('alumnos', true);
cabecera_admin('Grupos y horarios');
?>
<h1>👥 Grupos y horarios</h1>
<p class="suave">Cada grupo tiene su <b>deporte</b>, su categoría (<b>Sub-N</b> por año de nacimiento, o <b>abierta</b>), su profesor, sus días y su horario. Una misma escuela puede tener grupos de varios deportes. En <?= (int)date('Y') ?>, un niño nacido en <?= (int)date('Y') - 10 ?> es <b>Sub-10</b>.</p>
<?php if (!$grupos): ?><div class="tarjeta">Todavía no hay grupos. Cree el primero abajo.</div><?php endif; ?>
<?php foreach ($grupos as $g):
    $eq = $g['equipo_destino_id'] ? fila('SELECT e.*, l.nombre AS liga FROM equipos e JOIN ligas l ON l.id = e.liga_id WHERE e.id = ?', [$g['equipo_destino_id']]) : null;
    $sol = fila("SELECT s.*, l.nombre AS liga, t.nombre AS torneo FROM solicitudes_equipo s JOIN ligas l ON l.id = s.liga_id LEFT JOIN torneos t ON t.id = s.torneo_id WHERE s.escuela_grupo_id = ? ORDER BY s.id DESC LIMIT 1", [$g['id']]); ?>
  <div class="tarjeta" id="g<?= (int)$g['id'] ?>" style="<?= $g['activo'] ? '' : 'opacity:.55' ?>">
    <div class="fila-titulo"><h2 style="margin:0"><?= e($g['nombre']) ?> <?= chip_deporte(deporte_grupo($g, $liga)) ?> <small class="suave"><?= e(texto_categoria_grupo($g)) ?></small></h2>
      <?php if ($ed): ?><a class="btn btn-sec btn-chico" href="admin.php?p=grupos&amp;editar=<?= (int)$g['id'] ?>#form">Editar</a><?php endif; ?></div>
    <p style="margin:.4rem 0">📅 <?= e(texto_horario($g)) ?><?= $g['cancha'] ? ' · ' . e($g['cancha']) : '' ?><?= $g['sede'] ? ' · 🏟 ' . e($g['sede']) : '' ?> · 🧑‍🏫 <?= e($g['profesor'] ?? 'sin profesor') ?>
      · 👦 <b><?= (int)$g['n_alumnos'] ?></b> alumno(s)<?= $g['cupo'] ? ' de ' . (int)$g['cupo'] : '' ?><?= $g['mensualidad'] !== null ? ' · ' . dinero($g['mensualidad']) . '/mes' : '' ?></p>
    <div class="acciones">
      <a class="btn btn-sec btn-chico" href="admin.php?p=alumnos&amp;g=<?= (int)$g['id'] ?>">Ver alumnos</a>
      <?php if (puede('clases', true)): ?><a class="btn btn-sec btn-chico" href="admin.php?p=asistencia&amp;g=<?= (int)$g['id'] ?>">📋 Asistencia</a><?php endif; ?>
      <a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=esc_carnets&amp;g=<?= (int)$g['id'] ?>">🪪 Carnets</a>
    </div>
    <?php if (in_array(deporte_grupo($g, $liga), ['futbol', 'indor', 'basquet', 'ecuavoley'], true) || $eq): // (v19) solo deportes que tienen ligas en TuVocalía ?>
    <details style="margin-top:8px" <?= $eq || ($sol && $sol['estado'] === 'pendiente') ? 'open' : '' ?>><summary><b>🏆 Jugar en una liga de TuVocalía</b> <small class="suave">(opcional)</small></summary>
      <?php if ($eq): ?>
        <p>✓ Conectado con el equipo <b><?= e($eq['nombre']) ?></b> de <b><?= e($eq['liga']) ?></b>.</p>
        <?php if ($ed): ?><div class="acciones">
          <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="sincronizar"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn btn-sec btn-chico">⇄ Enviar alumnos nuevos a la liga</button></form>
          <form class="inline" method="post" onsubmit="return confirm('¿Desconectar el grupo de esa liga?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="desconectar"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn btn-sec btn-chico">Desconectar</button></form>
        </div><?php endif; ?>
      <?php elseif ($sol && $sol['estado'] === 'pendiente'): ?>
        <p>⏳ Solicitud enviada a <b><?= e($sol['liga']) ?></b> (<?= e($sol['torneo'] ?? '') ?>) como "<?= e($sol['nombre']) ?>". Falta que la liga la apruebe.</p>
      <?php elseif (!$abiertos): ?>
        <p class="suave">Ahora no hay campeonatos de ligas de TuVocalía con la inscripción de equipos abierta.</p>
      <?php elseif ($ed): ?>
        <?php if ($sol && $sol['estado'] === 'rechazado'): ?><p class="suave">La última solicitud fue rechazada: <?= e($sol['respuesta']) ?></p><?php endif; ?>
        <p class="suave">No es obligatorio: cada grupo puede ir a una liga distinta, o a ninguna. Al aprobarlo la liga, los alumnos activos del grupo pasan como jugadores (con sus fotos y autorizaciones).</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="enviar_liga"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <div class="campos">
            <div class="campo-ancho"><label>Campeonato</label><select name="torneo_id" required><option value="">— elegir —</option><?php foreach ($abiertos as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['liga'] . ' · ' . $c['nombre'] . ' ' . $c['temporada'] . ($c['categoria'] ? ' · ' . $c['categoria'] : '') . ($c['ciudad'] ? ' (' . $c['ciudad'] . ')' : '') . ' · hasta ' . $c['equipos_hasta']) ?></option><?php endforeach; ?></select></div>
            <div><label>Nombre del equipo</label><input type="text" name="nombre_equipo" maxlength="80" value="<?= e($liga['nombre'] . ' ' . $g['nombre']) ?>"></div>
            <div><label>Delegado</label><input type="text" name="delegado" required maxlength="80" value="<?= e($g['profesor'] ?? '') ?>"></div>
            <div><label>Celular del delegado</label><input type="tel" name="telefono" maxlength="30"></div>
            <div><label>Correo del delegado (nuevo)</label><input type="email" name="email" required></div>
            <div><label>Contraseña del delegado</label><input type="text" name="clave" required minlength="8" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10)) ?>"></div>
          </div>
          <button class="btn btn-sec">Enviar solicitud a la liga</button>
        </form>
      <?php endif; ?>
    </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($ed): $e = $editar ?? ['id' => 0, 'nombre' => '', 'categoria' => 'sub', 'anio_desde' => (int)date('Y') - 10, 'anio_hasta' => (int)date('Y') - 10, 'sede_id' => $sedes[0]['id'] ?? null,
    'profesor_id' => null, 'dias' => '2,4', 'hora_inicio' => '16:00', 'hora_fin' => '17:30', 'cancha' => '', 'cupo' => 0, 'mensualidad' => null, 'activo' => 1, 'deporte' => $liga['deporte_escuela'] ?: 'futbol'];
    $dias_sel = array_map('intval', explode(',', (string)$e['dias'])); ?>
<div class="tarjeta" id="form" style="margin-top:16px">
  <h2 style="margin-top:0"><?= $editar ? 'Editar ' . e($editar['nombre']) : '+ Nuevo grupo' ?></h2>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="60" value="<?= e($e['nombre']) ?>" placeholder="Ej: Sub-10 tarde"></div>
      <div><label>Deporte</label><select name="deporte"><?php foreach (catalogo_deportes() as $k => [$n, $ic]): if ($k !== deporte_grupo($e, $liga) && !deporte_escuela_permitido($liga, $k)) continue; ?><option value="<?= $k ?>" <?= deporte_grupo($e, $liga) === $k ? 'selected' : '' ?>><?= $ic ?> <?= e($n) ?></option><?php endforeach; ?></select><div class="ayuda">Define los tests, las habilidades, los ejercicios y el club del grupo.</div></div>
      <div><label>Categoría</label><select name="categoria" onchange="document.getElementById('anios').style.display = this.value === 'sub' ? '' : 'none'"><option value="sub" <?= $e['categoria'] === 'sub' ? 'selected' : '' ?>>Sub (por año de nacimiento)</option><option value="abierta" <?= $e['categoria'] === 'abierta' ? 'selected' : '' ?>>Categoría abierta (todas las edades)</option></select></div>
      <div id="anios" style="<?= $e['categoria'] === 'sub' ? '' : 'display:none' ?>"><label>Nacidos entre los años</label><div class="acciones"><input type="number" name="anio_desde" min="1950" max="<?= date('Y') ?>" value="<?= e((string)$e['anio_desde']) ?>" style="max-width:90px"> y <input type="number" name="anio_hasta" min="1950" max="<?= date('Y') ?>" value="<?= e((string)$e['anio_hasta']) ?>" style="max-width:90px"></div><div class="ayuda">Un solo año = una sola categoría (ej: 2016 = Sub-<?= (int)date('Y') - 2016 ?>).</div></div>
      <div><label>Sede</label><select name="sede_id"><?php foreach ($sedes as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$e['sede_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Profesor</label><select name="profesor_id"><option value="">— sin asignar —</option><?php foreach ($profes as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$e['profesor_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Días de entrenamiento</label><div class="acciones"><?php foreach (dias_semana() as $k => $v): ?><label class="check" style="font-weight:400"><input type="checkbox" name="dias[]" value="<?= $k ?>" <?= in_array($k, $dias_sel, true) ? 'checked' : '' ?>> <?= $v ?></label><?php endforeach; ?></div></div>
      <div><label>Desde</label><input type="time" name="hora_inicio" value="<?= e($e['hora_inicio']) ?>"></div>
      <div><label>Hasta</label><input type="time" name="hora_fin" value="<?= e($e['hora_fin']) ?>"></div>
      <div><label>Lugar <small>(cancha, coliseo, piscina, ring…)</small></label><input type="text" name="cancha" maxlength="60" value="<?= e($e['cancha']) ?>"></div>
      <div><label>Cupo máximo</label><input type="number" name="cupo" min="0" max="500" value="<?= (int)$e['cupo'] ?>"><div class="ayuda">0 = sin límite.</div></div>
      <div><label>Mensualidad de este grupo ($)</label><input type="number" name="mensualidad" min="0" step="0.01" value="<?= $e['mensualidad'] !== null ? e(number_format((float)$e['mensualidad'], 2, '.', '')) : '' ?>" placeholder="la general"><div class="ayuda">Vacío = la de Parámetros (<?= dinero($liga['esc_mensualidad']) ?>).</div></div>
    </div>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= $e['activo'] ? 'checked' : '' ?>> Activo</label>
    <div class="acciones" style="margin-top:8px"><button class="btn">Guardar grupo</button><?php if ($editar): ?><a class="btn btn-sec" href="admin.php?p=grupos">Cancelar</a><?php endif; ?></div>
  </form>
</div>
<?php endif;
echo html_modulos_bloqueados($liga); // (v35)
pie_admin();
