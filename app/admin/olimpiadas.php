<?php
/*
 * (v37) 🏅 OLIMPIADAS — juegos multideportivos.
 *  Sin id: lista de eventos y crear uno. Con id: pestañas Resumen · Disciplinas · Delegaciones · Deportistas · Medallero · Configuración.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$activo = olimp_activo($liga);
$es_evento = perfil_actual() === 'evento';
$id = (int)get('id');
$ev = $id > 0 ? olimp_evento($id, $lid) : null;
if ($id > 0 && !$ev) { flash('Evento no encontrado.', 'error'); ir('admin.php?p=olimpiadas'); }
if ($ev && !olimp_puede_evento($ev)) { flash('No tiene acceso a ese evento.', 'error'); ir('admin.php?p=olimpiadas'); }
$tabs = ['resumen' => '🏠 Resumen', 'disciplinas' => '🏆 Disciplinas', 'delegaciones' => '🏢 Delegaciones', 'deportistas' => '👥 Deportistas', 'medallero' => '🥇 Medallero y puntos', 'config' => '⚙️ Configuración'];
$s = isset($tabs[get('s')]) ? get('s') : 'resumen';
$url = 'admin.php?p=olimpiadas' . ($ev ? '&id=' . (int)$ev['id'] : '');
$editar = $ev ? olimp_puede_evento($ev, true) : (!$es_evento && puede('olimpiadas', true));

if (es_post()) {
    $accion = post('accion');
    if (!$activo && !es_super()) throw new RuntimeException('Su cuenta no tiene el módulo 🏅 Olimpiadas. Agréguelo en ⭐ Mi plan.');
    if (!$editar) throw new RuntimeException('Puede ver este evento, pero no hacer cambios.');

    /* ---------- crear evento ---------- */
    if (!$ev) {
        if ($accion !== 'crear') throw new RuntimeException('Acción no válida.');
        if (!$activo) throw new RuntimeException('Esta cuenta no tiene el módulo 🏅 Olimpiadas: no se pueden crear eventos.');
        $nid = olimp_crear_evento($liga, post('nombre'), post('fecha_ini'), post('fecha_fin'), post('sede'));
        bitacora_evento('campeonatos', 'Creó el evento de olimpiadas ' . post('nombre'), $lid);
        flash('Evento creado. Paso 1: agregue las disciplinas (los deportes). Paso 2: registre las delegaciones y envíeles su enlace para inscribir a su gente.');
        ir('admin.php?p=olimpiadas&id=' . $nid . '&s=disciplinas');
    }
    $evid = (int)$ev['id'];
    $cfg = olimp_cfg($ev);
    $dels = olimp_delegaciones($evid);
    $discs = olimp_disciplinas($evid);
    $disc_post = function () use ($discs): array {
        return $discs[(int)post('disciplina_id')] ?? throw new RuntimeException('Disciplina no encontrada.');
    };
    $dep_post = function () use ($evid): array {
        return fila('SELECT * FROM olimp_deportistas WHERE id = ? AND evento_id = ?', [(int)post('dep_id'), $evid]) ?? throw new RuntimeException('Deportista no encontrado.');
    };
    $del_post = function () use ($dels): array {
        return $dels[(int)post('delegacion_id')] ?? throw new RuntimeException('Delegación no encontrada.');
    };
    $volver = post('volver') !== '' && str_starts_with(post('volver'), 'admin.php?p=olimpiadas&id=' . $evid) ? post('volver') : $url . '&s=' . $s;

    /* ---------- configuración ---------- */
    if ($accion === 'config') {
        $nombre = mb_substr(trim(post('nombre')), 0, 100);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre del evento.');
        $fi = post('fecha_ini') !== '' && strtotime(post('fecha_ini')) ? date('Y-m-d', strtotime(post('fecha_ini'))) : '';
        $ff = post('fecha_fin') !== '' && strtotime(post('fecha_fin')) ? date('Y-m-d', strtotime(post('fecha_fin'))) : $fi;
        if ($fi !== '' && $ff < $fi) throw new RuntimeException('La fecha final no puede ser antes de la inicial.');
        $c = [
            'puntos' => olimp_leer_puntos(post('puntos')), 'clasificacion' => post('clasificacion') === 'medallas' ? 'medallas' : 'puntos',
            'participacion' => entero(post('participacion'), 0, 1000), 'puntuan' => entero(post('puntuan'), 0, 50), 'max_disciplinas' => entero(post('max_disciplinas'), 0, 50),
            'edad_min' => entero(post('edad_min'), 0, 99), 'edad_max' => entero(post('edad_max'), 0, 120), 'aprobacion' => post('aprobacion') === 'auto' ? 'auto' : 'manual',
            'exigir_requisitos' => post('exigir_requisitos') === '1' ? 1 : 0, 'insc_abierta' => post('insc_abierta') === '1' ? 1 : 0,
            'insc_hasta' => post('insc_hasta') !== '' && strtotime(post('insc_hasta')) ? date('Y-m-d', strtotime(post('insc_hasta'))) : '',
            'cruces' => post('cruces') === 'bloquear' ? 'bloquear' : 'avisar', 'provisional' => post('provisional') === '1' ? 1 : 0, 'publico' => post('publico') === '1' ? 1 : 0,
            'max_delegacion' => entero(post('max_delegacion'), 0, 5000),
        ];
        if ($c['edad_max'] > 0 && $c['edad_min'] > $c['edad_max']) throw new RuntimeException('La edad mínima no puede ser mayor que la máxima.');
        $logo = subir_imagen('logo', 'olimpiadas', 500);
        q('UPDATE olimp_eventos SET nombre = ?, descripcion = ?, sede = ?, fecha_ini = ?, fecha_fin = ?, color = ?, estado = ?, config = ?' . ($logo ? ', logo = ?' : '') . ' WHERE id = ?',
            array_merge([$nombre, mb_substr(trim(post('descripcion')), 0, 600), mb_substr(trim(post('sede')), 0, 120), $fi, $ff, color_valido(post('color')),
                array_key_exists(post('estado'), olimp_estados()) ? post('estado') : 'preparacion', json_encode($c, JSON_UNESCAPED_UNICODE)], $logo ? [$logo] : [], [$evid]));
        if (post('quitar_logo') === '1' && !$logo) q("UPDATE olimp_eventos SET logo = '' WHERE id = ?", [$evid]);
        bitacora_evento('campeonatos', 'Cambió la configuración del evento ' . $nombre . ' (puntos: ' . implode(',', $c['puntos']) . ')', $lid);
        flash('Configuración guardada.');
        ir($url . '&s=config');
    }
    if ($accion === 'requisitos') {
        $campos = [];
        foreach (requisitos_campos_catalogo() as $cc => $x) { $v = (int)($_POST['campo'][$cc] ?? 0); if ($v > 0) $campos[$cc] = min(2, $v); }
        $docs = [];
        foreach ((array)($_POST['doc_nombre'] ?? []) as $i => $n) {
            $n = trim((string)$n);
            if ($n === '') continue;
            $clave = trim((string)($_POST['doc_clave'][$i] ?? '')) ?: substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $n) ?: 'doc')), 0, 24);
            $docs[] = ['clave' => $clave, 'nombre' => $n, 'obligatorio' => isset($_POST['doc_obl'][$i]) ? 1 : 0, 'vence' => isset($_POST['doc_vence'][$i]) ? 1 : 0];
        }
        q('UPDATE olimp_eventos SET requisitos = ? WHERE id = ?', [json_encode(requisitos_normalizar(['campos' => $campos, 'docs' => $docs], 'olimpiadas'), JSON_UNESCAPED_UNICODE), $evid]);
        flash('Datos y documentos de los deportistas guardados.');
        ir($url . '&s=config#requisitos');
    }
    if ($accion === 'admin_agregar' || $accion === 'admin_crear') {
        if ($es_evento) throw new RuntimeException('Los administradores del evento los asigna el administrador de la cuenta.');
        if ($accion === 'admin_crear') {
            exigir_autorizacion();
            $uid = crear_usuario_liga($lid, post('u_nombre'), post('u_email'), post('u_clave'), 'evento');
            registrar_autorizacion_tercero('usuario', $lid, post('u_nombre'), 'darle acceso al panel del evento ' . $ev['nombre'] . ' y registrar sus acciones (bitácora)', ['ref_tabla' => 'usuarios', 'ref_id' => $uid]);
            $msg = 'Usuario creado con el perfil «Administrador de evento». Entréguele su correo y contraseña: al entrar verá solo este evento.';
        } else {
            $uid = (int)post('usuario_id');
            $u = fila("SELECT * FROM usuarios WHERE id = ? AND liga_id = ? AND rol = 'liga'", [$uid, $lid]) ?? throw new RuntimeException('Elija el usuario.');
            $msg = $u['nombre'] . ' ya puede administrar este evento' . (($u['perfil'] ?? '') === 'evento' ? '.' : ' (con su perfil ' . nombre_perfil((string)$u['perfil']) . ').');
        }
        q('INSERT OR IGNORE INTO olimp_admins (evento_id, usuario_id) VALUES (?, ?)', [$evid, $uid]);
        bitacora_evento('usuarios', 'Asignó un administrador al evento ' . $ev['nombre'], $lid, $uid);
        flash($msg);
        ir($url . '&s=config#admins');
    }
    if ($accion === 'admin_quitar') {
        if ($es_evento) throw new RuntimeException('Los administradores del evento los asigna el administrador de la cuenta.');
        q('DELETE FROM olimp_admins WHERE evento_id = ? AND usuario_id = ?', [$evid, (int)post('usuario_id')]);
        flash('Ya no administra este evento.');
        ir($url . '&s=config#admins');
    }
    if ($accion === 'borrar_evento') {
        if ($es_evento) throw new RuntimeException('Solo el administrador de la cuenta puede borrar el evento.');
        if (post('confirmar') !== mb_strtoupper('BORRAR')) throw new RuntimeException('Para borrar el evento escriba BORRAR.');
        olimp_borrar_evento($ev);
        bitacora_evento('campeonatos', 'Borró el evento de olimpiadas ' . $ev['nombre'], $lid);
        flash('Evento borrado. Sus campeonatos siguen en Campeonatos.');
        ir('admin.php?p=olimpiadas');
    }

    /* ---------- disciplinas ---------- */
    if ($accion === 'disc_vincular') {
        $did = olimp_vincular_torneo($ev, (int)post('torneo_id'), post('nombre'));
        flash('Disciplina agregada.');
        ir($url . '&s=disciplinas#d' . $did);
    }
    if ($accion === 'disc_nueva') {
        // se crea el campeonato con las reglas de fábrica del deporte (pantalla de Campeonatos) y vuelve aquí
        $m = post('modalidad');
        if (!in_array($m, modalidades_todas(), true)) throw new RuntimeException('Elija el deporte.');
        exigir_modalidad($liga, $m);
        if (trim(post('nombre')) === '') $_POST['nombre'] = nombre_modalidad($m) . ' · ' . $ev['nombre'];
        $_POST['temporada'] = $ev['fecha_ini'] !== '' ? substr($ev['fecha_ini'], 0, 4) : date('Y');
        $_POST['olimpiada'] = (string)$evid;
        if (isset(modalidades_carrera()[$m])) $_POST['accion'] = 'nuevo_carrera';
        elseif (isset(modalidades_duelo()[$m])) $_POST['accion'] = 'nuevo_duelo';
        elseif ($m === 'BOLOS') $_POST['accion'] = 'nuevo_bolos';
        elseif (isset(modalidades_pruebas()[$m])) $_POST['accion'] = 'nuevo_pruebas';
        else {
            $_POST['accion'] = 'nuevo_equipos';
            foreach (presets_modalidad()[$m] ?? [] as $k => $v) $_POST[$k] = (string)$v;
            $_POST['formato'] = post('formato') === 'grupos' ? 'grupos' : 'liga';
            $_POST['vueltas'] = '1';
        }
        require APP_DIR . '/admin/torneos.php'; // crea el campeonato y vuelve al evento (olimp_tras_crear_torneo)
        exit;
    }
    if ($accion === 'disc_guardar') {
        $d = $disc_post();
        $f = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '';
        $hi = preg_match('/^\d{2}:\d{2}$/', post('hora_ini')) ? post('hora_ini') : '';
        $hf = preg_match('/^\d{2}:\d{2}$/', post('hora_fin')) ? post('hora_fin') : '';
        if ($hi !== '' && $hf !== '' && $hf <= $hi) throw new RuntimeException('La hora final debe ser después de la inicial.');
        q('UPDATE olimp_disciplinas SET nombre = ?, factor = ?, rama = ?, fecha = ?, hora_ini = ?, hora_fin = ?, lugar = ?, max_por_delegacion = ?, orden = ? WHERE id = ?', [
            mb_substr(trim(post('nombre')), 0, 80), max(0, min(100, round((float)str_replace(',', '.', post('factor', '1')), 2))), array_key_exists(post('rama'), olimp_ramas()) ? post('rama') : '',
            $f, $hi, $hf, mb_substr(trim(post('lugar')), 0, 80), entero(post('max_por_delegacion'), 0, 500), entero(post('orden'), 0, 999), $d['id']]);
        flash('Disciplina guardada.');
        ir($url . '&s=disciplinas#d' . $d['id']);
    }
    if ($accion === 'disc_quitar') {
        $d = $disc_post();
        olimp_desvincular($d);
        flash('«' . olimp_nombre_disciplina($d) . '» ya no es parte del evento (el campeonato sigue en Campeonatos con sus resultados).');
        ir($url . '&s=disciplinas');
    }
    if ($accion === 'disc_manual') {
        $d = $disc_post();
        $podio = [];
        foreach ((array)($_POST['manual_del'] ?? []) as $i => $did) {
            $did = (int)$did;
            if ($did <= 0 || !isset($dels[$did])) continue;
            $podio[] = ['puesto' => entero((string)($_POST['manual_pos'][$i] ?? ($i + 1)), 1, 99), 'del' => $did];
        }
        q('UPDATE olimp_disciplinas SET manual = ? WHERE id = ?', [$podio ? json_encode($podio) : '', $d['id']]);
        flash($podio ? 'Podio a mano guardado: manda sobre los resultados del campeonato.' : 'Se quitó el podio a mano: se usan los resultados del campeonato.');
        ir($url . '&s=disciplinas#d' . $d['id']);
    }
    if ($accion === 'disc_equipos') {
        $d = $disc_post();
        if (olimp_tipo(olimp_torneo($d)) !== 'equipo') throw new RuntimeException('Solo para disciplinas por equipos.');
        $n = 0;
        foreach ($dels as $x) if ((int)$x['activo'] === 1) { olimp_equipo_de($d, $x); $n++; }
        flash($n . ' equipos (uno por delegación) en «' . olimp_nombre_disciplina($d) . '». Ya puede armar el fixture.');
        ir($url . '&s=disciplinas#d' . $d['id']);
    }

    /* ---------- delegaciones ---------- */
    if ($accion === 'del_guardar') {
        $did = (int)post('delegacion_id');
        $nombre = mb_substr(trim(post('nombre')), 0, 80);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre de la delegación (empresa, banco, colegio…).');
        if (fila('SELECT id FROM olimp_delegaciones WHERE evento_id = ? AND lower(nombre) = lower(?) AND id <> ?', [$evid, $nombre, $did])) throw new RuntimeException('Ya existe una delegación con ese nombre.');
        $logo = subir_imagen('logo', 'olimpiadas', 400);
        $d = [$nombre, mb_strtoupper(mb_substr(trim(post('sigla')), 0, 6)), color_valido(post('color'), '#333333'), mb_substr(trim(post('contacto')), 0, 80), mb_substr(trim(post('telefono')), 0, 20), mb_substr(trim(post('email')), 0, 120)];
        if ($did > 0) {
            $ant = $del_post();
            q('UPDATE olimp_delegaciones SET nombre = ?, sigla = ?, color = ?, contacto = ?, telefono = ?, email = ?' . ($logo ? ', logo = ?' : '') . ' WHERE id = ?', array_merge($d, $logo ? [$logo] : [], [$did]));
            // el nombre, color y logo pasan a sus equipos y a sus competidores
            foreach (filas('SELECT equipo_id FROM olimp_equipos WHERE delegacion_id = ?', [$did]) as $x) q('UPDATE equipos SET nombre = ?, color = ?' . ($logo ? ', escudo = ?' : '') . ' WHERE id = ?', array_merge([$nombre, $d[2]], $logo ? [$logo] : [], [$x['equipo_id']]));
            if ($ant['nombre'] !== $nombre) foreach (['duelo_participantes', 'prueba_participantes', 'carrera_participantes'] as $tb) q("UPDATE $tb SET club = ? WHERE olimp_dep_id IN (SELECT id FROM olimp_deportistas WHERE delegacion_id = ?)", [$nombre, $did]);
            flash('Delegación guardada.');
        } else {
            q('INSERT INTO olimp_delegaciones (nombre, sigla, color, contacto, telefono, email, logo, evento_id, token) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($d, [$logo ?? '', $evid, olimp_token()]));
            $did = (int)db()->lastInsertId();
            flash('Delegación creada. Envíele su enlace (botón 📲) para que inscriba a su gente.');
        }
        ir($url . '&s=delegaciones#g' . $did);
    }
    if ($accion === 'del_token') {
        $x = $del_post();
        q('UPDATE olimp_delegaciones SET token = ? WHERE id = ?', [olimp_token(), $x['id']]);
        flash('Enlace nuevo para ' . $x['nombre'] . ': el anterior ya no funciona.');
        ir($url . '&s=delegaciones#g' . $x['id']);
    }
    if ($accion === 'del_activo') {
        $x = $del_post();
        q('UPDATE olimp_delegaciones SET activo = 1 - activo WHERE id = ?', [$x['id']]);
        flash((int)$x['activo'] ? $x['nombre'] . ' quedó retirada (no aparece en la tabla si no tiene puntos y su enlace ya no inscribe).' : $x['nombre'] . ' quedó activa.');
        ir($url . '&s=delegaciones');
    }
    if ($accion === 'del_borrar') {
        $x = $del_post();
        if ((int)valor('SELECT COUNT(*) FROM olimp_deportistas WHERE delegacion_id = ?', [$x['id']])) throw new RuntimeException('La delegación tiene deportistas: retírela (botón ⏸) o borre primero a sus deportistas.');
        q('DELETE FROM olimp_delegaciones WHERE id = ?', [$x['id']]);
        flash('Delegación borrada.');
        ir($url . '&s=delegaciones');
    }

    /* ---------- deportistas ---------- */
    if ($accion === 'dep_guardar') {
        $depid = (int)post('dep_id');
        if ($depid === 0) exigir_autorizacion();
        $nuevo = olimp_guardar_deportista($ev, (int)post('delegacion_id'), olimp_leer_deportista_post(), $depid);
        guardar_requisitos($lid, olimp_mod_req($ev), 'olimp_deportistas', $nuevo);
        if ($depid === 0) registrar_autorizacion_tercero('olimp_deportista', $lid, post('nombres') . ' ' . post('apellidos'), 'su participación en ' . $ev['nombre'], ['ref_tabla' => 'olimp_deportistas', 'ref_id' => $nuevo]);
        flash($depid ? 'Datos guardados.' : 'Deportista registrado. Ahora inscríbalo en sus disciplinas.');
        ir($url . '&s=deportistas&dep=' . $nuevo);
    }
    if ($accion === 'dep_aprobar' || $accion === 'dep_aprobar_forzar') {
        $dep = $dep_post();
        $av = olimp_aprobar($ev, $dep, $accion === 'dep_aprobar_forzar');
        flash(olimp_nombre_dep($dep) . ' aprobado' . ($av ? '. Avisos: ' . implode(' · ', $av) : ': ya está en los campeonatos de sus disciplinas.'), $av ? 'error' : 'ok');
        ir($volver);
    }
    if ($accion === 'dep_aprobar_todos') {
        $ok = 0;
        $no = [];
        foreach (filas("SELECT * FROM olimp_deportistas WHERE evento_id = ? AND estado = 'pendiente'" . ((int)post('delegacion_id') ? ' AND delegacion_id = ' . (int)post('delegacion_id') : ''), [$evid]) as $dep) {
            try { olimp_aprobar($ev, $dep); $ok++; } catch (RuntimeException $e) { $no[] = olimp_nombre_dep($dep); }
        }
        flash($ok . ' aprobado(s).' . ($no ? ' Les faltan requisitos: ' . implode(', ', array_slice($no, 0, 15)) . (count($no) > 15 ? '…' : '') . '.' : ''), $no ? 'error' : 'ok');
        ir($volver);
    }
    if ($accion === 'dep_rechazar') {
        $dep = $dep_post();
        olimp_rechazar($dep, post('motivo'));
        flash(olimp_nombre_dep($dep) . ' rechazado.');
        ir($volver);
    }
    if ($accion === 'dep_pendiente') {
        $dep = $dep_post();
        q("UPDATE olimp_deportistas SET estado = 'pendiente', nota = '' WHERE id = ?", [$dep['id']]);
        flash(olimp_nombre_dep($dep) . ' volvió a «por aprobar».');
        ir($volver);
    }
    if ($accion === 'dep_borrar') {
        $dep = $dep_post();
        olimp_borrar_deportista($dep);
        flash(olimp_nombre_dep($dep) . ' eliminado del evento.');
        ir($url . '&s=deportistas');
    }
    if ($accion === 'insc_agregar') {
        $dep = $dep_post();
        [, $av] = olimp_inscribir($ev, $dep, $disc_post(), olimp_leer_inscripcion_post(), true);
        flash('Inscrito.' . ($av ? ' ⚠ ' . implode(' · ', $av) : '') . ($dep['estado'] !== 'aprobado' ? ' Pasará al campeonato cuando lo apruebe.' : ''), $av ? 'error' : 'ok');
        ir($url . '&s=deportistas&dep=' . $dep['id']);
    }
    if ($accion === 'insc_quitar') {
        $i = fila('SELECT * FROM olimp_inscripciones WHERE id = ? AND evento_id = ?', [(int)post('insc_id'), $evid]) ?? throw new RuntimeException('Inscripción no encontrada.');
        olimp_quitar_inscripcion((int)$i['id']);
        flash('Inscripción quitada.');
        ir($url . '&s=deportistas&dep=' . (int)$i['deportista_id']);
    }

    /* ---------- puntos extra ---------- */
    if ($accion === 'extra_agregar') {
        $x = $del_post();
        $pts = round((float)str_replace(',', '.', post('puntos')), 2);
        if ($pts == 0.0 || abs($pts) > 10000) throw new RuntimeException('Escriba los puntos (pueden ser negativos para una sanción).');
        if (trim(post('motivo')) === '') throw new RuntimeException('Escriba el motivo (ej: desfile inaugural, juego limpio, sanción).');
        q('INSERT INTO olimp_puntos_extra (evento_id, delegacion_id, puntos, motivo, por) VALUES (?,?,?,?,?)', [$evid, $x['id'], $pts, mb_substr(trim(post('motivo')), 0, 120), usuario()['nombre']]);
        bitacora_evento('campeonatos', 'Puntos extra a ' . $x['nombre'] . ' en ' . $ev['nombre'] . ': ' . olimp_num($pts) . ' (' . post('motivo') . ')', $lid);
        flash(olimp_num($pts) . ' puntos para ' . $x['nombre'] . '.');
        ir($url . '&s=medallero#extra');
    }
    if ($accion === 'extra_borrar') {
        q('DELETE FROM olimp_puntos_extra WHERE id = ? AND evento_id = ?', [(int)post('extra_id'), $evid]);
        flash('Puntos quitados.');
        ir($url . '&s=medallero#extra');
    }
    throw new RuntimeException('Acción no válida.');
}

/* ============================================================
 * PANTALLAS
 * ============================================================ */
cabecera_admin($ev ? $ev['nombre'] : 'Olimpiadas');
echo olimp_css();

if (!$ev):
    $eventos = olimp_eventos_usuario($liga); ?>
  <h1>🏅 Olimpiadas y juegos multideportivos</h1>
  <p class="suave" style="margin-top:0">Organice olimpiadas interempresas, interbancarias, intercolegiales o de barrio: varios deportes a la vez, cada empresa o colegio como una <b>delegación</b> que inscribe a su gente con su propio enlace, un <b>medallero general</b> y una <b>tabla de puntos</b> que usted configura.</p>
  <?php if (!$activo): ?>
    <div class="aviso aviso-info">🔒 Su cuenta no tiene el módulo <b>🏅 Olimpiadas y juegos multideportivos</b>. <?= !$es_evento && puede('config') ? '<a href="admin.php?p=mi_plan">Agréguelo en ⭐ Mi plan</a>.' : 'Pídale al administrador que lo agregue.' ?></div>
  <?php endif; ?>
  <?php if ($es_evento && !$eventos): ?><div class="aviso aviso-info">Todavía no le asignaron ningún evento. Pídale al administrador de la cuenta que lo agregue en el evento → ⚙️ Configuración → Administradores del evento.</div><?php endif; ?>
  <?php foreach ($eventos as $x): $cx = olimp_cfg($x); $nd = (int)valor('SELECT COUNT(*) FROM olimp_disciplinas WHERE evento_id = ?', [$x['id']]); $ng = (int)valor('SELECT COUNT(*) FROM olimp_delegaciones WHERE evento_id = ?', [$x['id']]); $np = (int)valor('SELECT COUNT(*) FROM olimp_deportistas WHERE evento_id = ?', [$x['id']]); $pend = (int)valor("SELECT COUNT(*) FROM olimp_deportistas WHERE evento_id = ? AND estado = 'pendiente'", [$x['id']]); ?>
    <a class="tarjeta partido-link olimp-cab" style="padding:14px;margin-bottom:10px;text-decoration:none;color:inherit" href="admin.php?p=olimpiadas&amp;id=<?= (int)$x['id'] ?>">
      <?= olimp_html_logo($x) ?>
      <span style="flex:1;min-width:200px"><b style="font-size:1.1rem"><?= e($x['nombre']) ?></b> <span class="etiqueta<?= $x['estado'] === 'finalizado' ? ' gris' : '' ?>"><?= e(olimp_estados()[$x['estado']] ?? $x['estado']) ?></span><br>
        <small class="suave"><?= $x['fecha_ini'] !== '' ? e(fecha_bonita($x['fecha_ini'])) . ($x['fecha_fin'] !== '' && $x['fecha_fin'] !== $x['fecha_ini'] ? ' al ' . e(fecha_bonita($x['fecha_fin'])) : '') . ' · ' : '' ?><?= $nd ?> disciplinas · <?= $ng ?> delegaciones · <?= $np ?> deportistas<?= $pend ? ' · <b>' . $pend . ' por aprobar</b>' : '' ?></small></span>
      <span class="btn btn-sec btn-chico">Abrir</span>
    </a>
  <?php endforeach; ?>
  <?php if ($activo && $editar): ?>
  <div class="tarjeta" style="margin-top:14px">
    <h2 style="margin-top:0">+ Nuevo evento</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
      <div class="campos">
        <div class="campo-ancho"><label>Nombre del evento *</label><input type="text" name="nombre" required maxlength="100" placeholder="Ej: Olimpiadas Interbancarias 2026"></div>
        <div><label>Desde</label><input type="date" name="fecha_ini"></div>
        <div><label>Hasta</label><input type="date" name="fecha_fin"></div>
        <div><label>Sede principal</label><input type="text" name="sede" maxlength="120" placeholder="Ej: Complejo deportivo del Banco Central"></div>
      </div>
      <button class="btn">Crear evento</button>
    </form>
  </div>
  <?php endif;
    pie_admin();
    return;
endif;

/* ---------- evento ---------- */
$evid = (int)$ev['id'];
$cfg = olimp_cfg($ev);
$dels = olimp_delegaciones($evid);
$discs = olimp_disciplinas($evid);
$deps = olimp_deportistas($evid);
$pend = array_filter($deps, fn($x) => $x['estado'] === 'pendiente');
$url_pub = olimp_url_publica($liga, $ev);
?>
<p><a href="admin.php?p=olimpiadas">← Olimpiadas</a></p>
<div class="olimp-cab">
  <?= olimp_html_logo($ev) ?>
  <div style="flex:1;min-width:220px">
    <h1 style="margin:0"><?= e($ev['nombre']) ?> <small><?= e(olimp_estados()[$ev['estado']] ?? '') ?></small></h1>
    <p class="suave" style="margin:2px 0"><?= $ev['fecha_ini'] !== '' ? '📅 ' . e(fecha_bonita($ev['fecha_ini'])) . ($ev['fecha_fin'] !== '' && $ev['fecha_fin'] !== $ev['fecha_ini'] ? ' al ' . e(fecha_bonita($ev['fecha_fin'])) : '') : '📅 Fechas por definir' ?><?= $ev['sede'] !== '' ? ' · 📍 ' . e($ev['sede']) : '' ?></p>
  </div>
  <div class="acciones"><?php if ($cfg['publico']): ?><a class="btn btn-sec btn-chico" target="_blank" href="<?= e($url_pub) ?>">Ver página pública ↗</a> <button type="button" class="btn btn-sec btn-chico" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($url_pub) ?>'); this.textContent='✓ copiado'">📋 Copiar enlace</button><?php else: ?><span class="etiqueta gris">página pública oculta</span><?php endif; ?></div>
</div>
<?php if (!$activo): ?><div class="aviso aviso-info">🔒 La cuenta ya no tiene el módulo Olimpiadas: puede ver el evento, pero no cambiarlo.</div><?php endif; ?>
<nav class="pestanas">
  <?php foreach ($tabs as $k => $t): ?><a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($t) ?><?= $k === 'deportistas' && $pend ? ' <span class="etiqueta roja">' . count($pend) . '</span>' : '' ?></a><?php endforeach; ?>
</nav>
<?php

if ($s === 'resumen'):
    $res = olimp_tabla($ev);
    $ninsc = (int)valor('SELECT COUNT(*) FROM olimp_inscripciones WHERE evento_id = ?', [$evid]); ?>
  <div class="olimp-kpis">
    <div><b><?= count($discs) ?></b>disciplinas</div><div><b><?= count($dels) ?></b>delegaciones</div><div><b><?= count($deps) ?></b>deportistas</div>
    <div><b><?= $ninsc ?></b>inscripciones</div><div><b><?= count($pend) ?></b>por aprobar</div>
    <div><b><?= count(array_filter($res['conjuntos'], fn($c) => $c['terminada'])) ?> / <?= count($res['conjuntos']) ?></b>categorías terminadas</div>
  </div>
  <?php if (!$discs || !$dels): ?>
  <div class="tarjeta" style="border:2px solid var(--acento)">
    <h2 style="margin-top:0">Primeros pasos</h2>
    <ol>
      <li><?= $discs ? '✅' : '' ?> <a href="<?= e($url . '&s=disciplinas') ?>">Agregue las disciplinas</a>: cree el campeonato de cada deporte (fútbol, vóley, natación…) o use uno que ya tenga.</li>
      <li><?= $dels ? '✅' : '' ?> <a href="<?= e($url . '&s=delegaciones') ?>">Registre las delegaciones</a> (cada empresa, banco o colegio) y envíeles su enlace por WhatsApp.</li>
      <li>Cada delegación inscribe a su gente; usted <a href="<?= e($url . '&s=deportistas') ?>">aprueba a los deportistas</a> y pasan solos a cada campeonato.</li>
      <li>Revise en <a href="<?= e($url . '&s=config') ?>">⚙️ Configuración</a> los puntos por puesto y las reglas del evento.</li>
    </ol>
  </div>
  <?php endif; ?>
  <h2>🥇 Tabla general</h2>
  <?= olimp_html_tabla($res, $url . '&s=delegaciones#g') ?>
  <h2>📅 Programa</h2>
  <?= olimp_html_programa($discs, fn($d) => (es_individual(olimp_torneo($d)) ? url_admin_individual(olimp_torneo($d)) : 'admin.php?p=torneo&id=' . (int)$d['torneo_id'])) ?>

<?php elseif ($s === 'disciplinas'):
    $sueltos = filas('SELECT t.id, t.nombre, t.temporada, t.modalidad FROM torneos t WHERE t.liga_id = ? AND t.estado <> \'finalizado\' AND NOT EXISTS (SELECT 1 FROM olimp_disciplinas d WHERE d.torneo_id = t.id) ORDER BY t.id DESC', [$lid]);
    $mods_ok = array_values(array_filter(modalidades_todas(), fn($m) => modalidad_permitida($liga, $m))); ?>
  <p class="suave" style="margin-top:0">Cada disciplina es un campeonato de la cuenta con sus propias reglas, fixture, llaves o pruebas. Aquí se define su fecha, horario y lugar (para el programa y para avisar cruces de horario), si es solo de varones o damas, cuánto vale en la tabla (factor) y el cupo por delegación.</p>
  <?php foreach ($discs as $d): $t = olimp_torneo($d); $tipo = olimp_tipo($t); $nin = (int)valor('SELECT COUNT(*) FROM olimp_inscripciones WHERE disciplina_id = ?', [$d['id']]); $manual = json_decode((string)$d['manual'], true) ?: []; ?>
    <div class="tarjeta" id="d<?= (int)$d['id'] ?>" style="margin-bottom:12px">
      <div class="fila-titulo"><h2 style="margin:0"><?= olimp_icono($d) ?> <?= e(olimp_nombre_disciplina($d)) ?> <small><?= e(nombre_modalidad((string)$d['modalidad'])) ?> · <?= e(olimp_texto_tipo($tipo)) ?></small></h2>
        <a class="btn btn-chico" href="<?= e(es_individual($t) ? url_admin_individual($t) : 'admin.php?p=torneo&id=' . (int)$t['id']) ?>">Abrir campeonato →</a></div>
      <p class="suave" style="margin:4px 0"><?= $nin ?> inscripciones · campeonato «<?= e($t['nombre']) ?>» (<?= e(['inscripcion' => 'inscripción', 'en_juego' => 'en juego', 'finalizado' => 'finalizado'][$t['estado']] ?? $t['estado']) ?>)<?= (float)$d['factor'] != 1.0 ? ' · vale ×' . olimp_num((float)$d['factor']) : '' ?><?= $manual ? ' · <b>podio a mano</b>' : '' ?></p>
      <?php if ($editar): ?>
      <details><summary>✏️ Datos de la disciplina</summary>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="disc_guardar"><input type="hidden" name="disciplina_id" value="<?= (int)$d['id'] ?>">
          <div class="campos">
            <div><label>Nombre en el evento</label><input type="text" name="nombre" maxlength="80" value="<?= e($d['nombre']) ?>" placeholder="<?= e($t['nombre']) ?>"></div>
            <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($d['fecha']) ?>"></div>
            <div><label>Desde (hora)</label><input type="time" name="hora_ini" value="<?= e($d['hora_ini']) ?>"></div>
            <div><label>Hasta (hora)</label><input type="time" name="hora_fin" value="<?= e($d['hora_fin']) ?>"></div>
            <div><label>Lugar</label><input type="text" name="lugar" maxlength="80" value="<?= e($d['lugar']) ?>"></div>
            <div><label>Rama</label><select name="rama"><?php foreach (olimp_ramas() as $k => $txt): ?><option value="<?= e($k) ?>" <?= $d['rama'] === $k ? 'selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?></select></div>
            <div><label>Factor de puntos</label><input type="text" inputmode="decimal" name="factor" value="<?= e(olimp_num((float)$d['factor'])) ?>"><div class="ayuda">1 = normal · 2 = vale doble · 0 = no suma puntos (solo medallas).</div></div>
            <div><label>Cupo por delegación</label><input type="number" name="max_por_delegacion" min="0" max="500" value="<?= (int)$d['max_por_delegacion'] ?>"><div class="ayuda">0 = sin límite. En duelos y carreras, por categoría.</div></div>
            <div><label>Orden</label><input type="number" name="orden" min="0" max="999" value="<?= (int)$d['orden'] ?>"></div>
          </div>
          <button class="btn btn-chico">Guardar</button>
        </form>
      </details>
      <?php if ($tipo === 'equipo'): ?>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="disc_equipos"><input type="hidden" name="disciplina_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sec btn-chico" title="Crea el equipo de cada delegación aunque todavía no tenga jugadores">👕 Un equipo por delegación</button></form>
      <?php endif; ?>
      <details><summary>🥇 Podio a mano<?= $manual ? ' (activo)' : '' ?></summary>
        <p class="ayuda">Solo si el resultado no sale del campeonato (por ejemplo, una disciplina que se jugó fuera del sistema). Manda sobre los resultados. Déjelo vacío para usar los del campeonato.</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="disc_manual"><input type="hidden" name="disciplina_id" value="<?= (int)$d['id'] ?>">
          <table><tr><th>Puesto</th><th>Delegación</th></tr>
          <?php for ($i = 0; $i < max(6, count($manual) + 2); $i++): $m = $manual[$i] ?? ['puesto' => $i + 1, 'del' => 0]; ?>
            <tr><td><input type="number" name="manual_pos[<?= $i ?>]" min="1" max="99" value="<?= (int)$m['puesto'] ?>" style="width:70px"></td>
              <td><select name="manual_del[<?= $i ?>]"><option value="0">—</option><?php foreach ($dels as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$m['del'] === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nombre']) ?></option><?php endforeach; ?></select></td></tr>
          <?php endfor; ?></table>
          <button class="btn btn-sec btn-chico">Guardar podio</button>
        </form>
      </details>
      <form method="post" style="margin-top:6px" onsubmit="return confirm('¿Quitar esta disciplina del evento? El campeonato y sus resultados quedan en Campeonatos; se borran las inscripciones del evento.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="disc_quitar"><input type="hidden" name="disciplina_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sec btn-mini">Quitar del evento</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$discs): ?><div class="aviso aviso-info">Todavía no hay disciplinas. Cree la primera abajo.</div><?php endif; ?>
  <?php if ($editar && $activo): ?>
  <div class="tarjeta" style="margin-top:14px">
    <h2 style="margin-top:0">+ Nueva disciplina</h2>
    <p class="suave" style="margin-top:0">Se crea el campeonato del deporte con sus reglas de fábrica (después puede cambiarlas en «Abrir campeonato»). Solo aparecen los deportes de su plan.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="disc_nueva">
      <div class="campos">
        <div><label>Deporte *</label><select name="modalidad" required><option value="">— elija —</option><?php foreach ($mods_ok as $m): ?><option value="<?= e($m) ?>"><?= e(nombre_modalidad($m)) ?></option><?php endforeach; ?></select></div>
        <div class="campo-ancho"><label>Nombre</label><input type="text" name="nombre" maxlength="100" placeholder="Ej: Fútbol 7 masculino"></div>
        <div><label>Rama</label><select name="d_rama"><?php foreach (olimp_ramas() as $k => $txt): ?><option value="<?= e($k) ?>"><?= e($txt) ?></option><?php endforeach; ?></select></div>
        <div><label>Fecha</label><input type="date" name="d_fecha" value="<?= e($ev['fecha_ini']) ?>"></div>
        <div><label>Desde</label><input type="time" name="d_hora_ini"></div>
        <div><label>Hasta</label><input type="time" name="d_hora_fin"></div>
        <div><label>Lugar</label><input type="text" name="d_lugar" maxlength="80"></div>
      </div>
      <button class="btn">Crear disciplina</button>
    </form>
    <?php if ($sueltos): ?>
    <h3>…o use un campeonato que ya tiene</h3>
    <form method="post" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="disc_vincular">
      <select name="torneo_id" required><option value="">— elija —</option><?php foreach ($sueltos as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['nombre'] . ' ' . $x['temporada'] . ' · ' . nombre_modalidad($x['modalidad'])) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sec">Agregar al evento</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php elseif ($s === 'delegaciones'):
    $edit_del = (int)get('del') ? ($dels[(int)get('del')] ?? null) : null;
    $res = olimp_tabla($ev);
    $med = [];
    foreach ($res['tabla'] as $x) $med[(int)$x['del']['id']] = $x; ?>
  <p class="suave" style="margin-top:0">Cada delegación (empresa, banco, colegio…) tiene su <b>enlace propio</b>: con él su coordinador registra a su gente, sube los documentos y la inscribe en las disciplinas, sin usuario ni contraseña. Comparta el enlace solo con el coordinador de esa delegación.</p>
  <?php foreach ($dels as $x): $np = count(array_filter($deps, fn($d) => (int)$d['delegacion_id'] === (int)$x['id'])); $npend = count(array_filter($pend, fn($d) => (int)$d['delegacion_id'] === (int)$x['id'])); $link = olimp_url_portal($liga, $ev, $x); $m = $med[(int)$x['id']] ?? null; ?>
    <div class="tarjeta" id="g<?= (int)$x['id'] ?>" style="margin-bottom:10px<?= (int)$x['activo'] ? '' : ';opacity:.6' ?>">
      <div class="olimp-cab"><?= olimp_html_logo($x) ?>
        <div style="flex:1;min-width:200px"><b style="font-size:1.05rem"><?= e($x['nombre']) ?></b><?= $x['sigla'] !== '' ? ' <small class="suave">(' . e($x['sigla']) . ')</small>' : '' ?><?= (int)$x['activo'] ? '' : ' <span class="etiqueta gris">retirada</span>' ?><br>
          <small class="suave"><?= $np ?> deportistas<?= $npend ? ' · <b>' . $npend . ' por aprobar</b>' : '' ?><?= $x['contacto'] !== '' ? ' · ' . e($x['contacto']) : '' ?><?= $x['telefono'] !== '' ? ' · ' . e($x['telefono']) : '' ?></small>
          <?php if ($m): ?><br><small>🥇 <?= $m['oro'] ?> · 🥈 <?= $m['plata'] ?> · 🥉 <?= $m['bronce'] ?> · <b><?= olimp_num($m['puntos']) ?> pts</b> (<?= $m['pos'] ?>.º)</small><?php endif; ?></div>
        <div class="acciones">
          <a class="btn btn-sec btn-chico" href="<?= e($url . '&s=deportistas&del=' . (int)$x['id']) ?>">👥 Deportistas</a>
          <?php if ($editar): ?>
            <button type="button" class="btn btn-sec btn-chico" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($link) ?>'); this.textContent='✓ copiado'">📋 Copiar enlace</button>
            <?php $wa = telefono_wa((string)$x['telefono']); $msg = 'Hola, este es el enlace de ' . $x['nombre'] . ' para inscribir a sus deportistas en ' . $ev['nombre'] . ': ' . $link; ?>
            <a class="btn btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($msg) ?>">📲 WhatsApp</a>
            <a class="btn btn-sec btn-chico" href="<?= e($url . '&s=delegaciones&del=' . (int)$x['id']) ?>#form-del">✏️</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($editar): ?>
      <details style="margin-top:6px"><summary class="suave">Más opciones</summary>
        <p><small>Enlace: <code style="word-break:break-all"><?= e($link) ?></code></small></p>
        <form method="post" style="display:inline" onsubmit="return confirm('¿Cambiar el enlace? El anterior deja de funcionar.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="del_token"><input type="hidden" name="delegacion_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-mini">🔄 Enlace nuevo</button></form>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="del_activo"><input type="hidden" name="delegacion_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-mini"><?= (int)$x['activo'] ? '⏸ Retirar' : '▶ Activar' ?></button></form>
        <?php if (!$np): ?><form method="post" style="display:inline" onsubmit="return confirm('¿Borrar la delegación?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="del_borrar"><input type="hidden" name="delegacion_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-mini">🗑 Borrar</button></form><?php endif; ?>
      </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$dels): ?><div class="aviso aviso-info">Todavía no hay delegaciones. Cree la primera abajo.</div><?php endif; ?>
  <?php if ($editar && $activo): $x = $edit_del ?? ['id' => 0, 'nombre' => '', 'sigla' => '', 'color' => '#1f5fae', 'contacto' => '', 'telefono' => '', 'email' => '', 'logo' => '']; ?>
  <div class="tarjeta" id="form-del" style="margin-top:14px">
    <h2 style="margin-top:0"><?= $x['id'] ? '✏️ ' . e($x['nombre']) : '+ Nueva delegación' ?></h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="del_guardar"><input type="hidden" name="delegacion_id" value="<?= (int)$x['id'] ?>">
      <div class="campos">
        <div class="campo-ancho"><label>Nombre *</label><input type="text" name="nombre" required maxlength="80" value="<?= e($x['nombre']) ?>" placeholder="Ej: Banco del Pacífico"></div>
        <div><label>Sigla</label><input type="text" name="sigla" maxlength="6" value="<?= e($x['sigla']) ?>" placeholder="Ej: BDP"></div>
        <div><label>Color</label><input type="color" name="color" value="<?= e(color_valido($x['color'], '#1f5fae')) ?>"></div>
        <div><label>Logo</label><input type="file" name="logo" accept="image/*"><?= $x['logo'] ? '<div class="ayuda"><img src="' . e($x['logo']) . '" class="mini" alt=""> ya tiene logo</div>' : '' ?></div>
        <div><label>Coordinador (nombre)</label><input type="text" name="contacto" maxlength="80" value="<?= e($x['contacto']) ?>"></div>
        <div><label>Celular del coordinador</label><input type="tel" name="telefono" maxlength="20" value="<?= e($x['telefono']) ?>" inputmode="numeric"></div>
        <div><label>Correo</label><input type="email" name="email" maxlength="120" value="<?= e($x['email']) ?>"></div>
      </div>
      <button class="btn"><?= $x['id'] ? 'Guardar' : 'Crear delegación' ?></button> <?= $x['id'] ? '<a class="btn btn-sec" href="' . e($url . '&s=delegaciones') . '">Cancelar</a>' : '' ?>
    </form>
  </div>
  <?php endif; ?>

<?php elseif ($s === 'deportistas'):
    $dep = (int)get('dep') ? ($deps[(int)get('dep')] ?? null) : null;
    if ($dep):
        $ins = olimp_inscripciones_dep((int)$dep['id']);
        $falta = requisitos_faltan('olimp_deportistas', (int)$dep['id'], olimp_mod_req($ev));
        $cr = olimp_cruces_deportista((int)$dep['id'], $discs);
        $volver = $url . '&s=deportistas&dep=' . (int)$dep['id']; ?>
  <p><a href="<?= e($url . '&s=deportistas&del=' . (int)$dep['delegacion_id']) ?>">← Deportistas de <?= e($dels[(int)$dep['delegacion_id']]['nombre'] ?? '') ?></a></p>
  <div class="tarjeta">
    <h2 style="margin-top:0"><?= e(olimp_nombre_dep($dep)) ?> <span class="etiqueta<?= $dep['estado'] === 'aprobado' ? '' : ($dep['estado'] === 'rechazado' ? ' roja' : ' gris') ?>"><?= e(olimp_estados_deportista()[$dep['estado']]) ?></span></h2>
    <p class="suave" style="margin-top:0"><?= e($dels[(int)$dep['delegacion_id']]['nombre'] ?? '') ?> · CI <?= e($dep['cedula']) ?> · <?= $dep['sexo'] === 'F' ? 'Dama' : 'Varón' ?> · <?= edad($dep['fecha_nac']) ?> años<?= $dep['nota'] !== '' ? ' · Motivo: ' . e($dep['nota']) : '' ?></p>
    <?php if ($falta): ?><div class="aviso aviso-error">⚠ Le falta: <?= e(implode(', ', $falta)) ?></div><?php endif; ?>
    <?php foreach ($cr as $c): ?><div class="aviso aviso-error">⏰ Cruce de horario: <?= e($c) ?></div><?php endforeach; ?>
    <?php if ($editar): ?>
    <div class="acciones">
      <?php if ($dep['estado'] !== 'aprobado'): ?>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_aprobar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn">✓ Aprobar</button></form>
        <?php if ($falta && $cfg['exigir_requisitos']): ?><form method="post" style="display:inline" onsubmit="return confirm('¿Aprobar aunque le falten requisitos?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_aprobar_forzar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-sec">Aprobar sin requisitos</button></form><?php endif; ?>
      <?php endif; ?>
      <?php if ($dep['estado'] !== 'rechazado'): ?>
        <form method="post" style="display:inline" onsubmit="var m=prompt('Motivo del rechazo (lo verá la delegación):'); if(m===null) return false; this.motivo.value=m; return true;"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_rechazar"><input type="hidden" name="motivo" value=""><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-sec">✕ Rechazar</button></form>
      <?php else: ?>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_pendiente"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-sec">↺ Volver a «por aprobar»</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <h3>Disciplinas</h3>
    <?php if (!$ins): ?><p class="suave">Todavía no está inscrito en ninguna disciplina.</p><?php endif; ?>
    <ul>
      <?php foreach ($ins as $i): ?>
        <li><?= e(olimp_texto_inscripcion($i, $discs)) ?> <?= $i['ref_id'] !== null ? '<span class="etiqueta">✓ en el campeonato</span>' : '<span class="etiqueta gris">pasa al aprobarlo</span>' ?>
          <?php if ($editar): ?><form method="post" style="display:inline" onsubmit="return confirm('¿Quitar esta inscripción?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="insc_quitar"><input type="hidden" name="insc_id" value="<?= (int)$i['id'] ?>"><button class="btn btn-sec btn-mini">quitar</button></form><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($editar && $dep['estado'] !== 'rechazado' && $discs): ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="insc_agregar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>">
      <?= olimp_html_campos_inscripcion($discs, $dep) ?>
      <button class="btn btn-chico">+ Inscribir</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($editar): ?>
  <div class="tarjeta" style="margin-top:14px">
    <h3 style="margin-top:0">✏️ Datos y documentos</h3>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_guardar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>">
      <?= olimp_html_campos_deportista($dep, $ev, $dels) ?>
      <button class="btn">Guardar</button>
    </form>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Eliminar a este deportista del evento? Se quita de los campeonatos donde todavía no compitió.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_borrar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>"><button class="btn btn-sec btn-mini">🗑 Eliminar del evento</button></form>
  </div>
  <?php endif; ?>
    <?php else:
        $fdel = (int)get('del');
        $fest = array_key_exists(get('estado'), olimp_estados_deportista()) ? get('estado') : '';
        $q = mb_strtolower(trim(get('q')));
        $lista = array_filter($deps, fn($x) => (!$fdel || (int)$x['delegacion_id'] === $fdel) && ($fest === '' || $x['estado'] === $fest)
            && ($q === '' || str_contains(mb_strtolower(olimp_nombre_dep($x) . ' ' . $x['cedula']), $q)));
        $ins_por = [];
        foreach (filas('SELECT * FROM olimp_inscripciones WHERE evento_id = ?', [$evid]) as $i) $ins_por[(int)$i['deportista_id']][] = $i;
        $volver = $url . '&s=deportistas' . ($fdel ? '&del=' . $fdel : '') . ($fest !== '' ? '&estado=' . $fest : ''); ?>
  <form method="get" class="acciones" style="margin:8px 0"><input type="hidden" name="p" value="olimpiadas"><input type="hidden" name="id" value="<?= $evid ?>"><input type="hidden" name="s" value="deportistas">
    <select name="del" onchange="this.form.submit()" style="width:auto"><option value="0">Todas las delegaciones</option><?php foreach ($dels as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fdel === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nombre']) ?></option><?php endforeach; ?></select>
    <select name="estado" onchange="this.form.submit()" style="width:auto"><option value="">Todos</option><?php foreach (olimp_estados_deportista() as $k => $t): ?><option value="<?= e($k) ?>" <?= $fest === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <input type="search" name="q" value="<?= e(get('q')) ?>" placeholder="Nombre o cédula" style="width:auto;flex:1;min-width:160px"><button class="btn btn-sec btn-chico">Buscar</button>
  </form>
  <?php if ($editar && array_filter($lista, fn($x) => $x['estado'] === 'pendiente')): ?>
    <form method="post" style="margin-bottom:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_aprobar_todos"><input type="hidden" name="delegacion_id" value="<?= $fdel ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-chico">✓ Aprobar a todos los que tienen sus requisitos completos<?= $fdel ? ' de esta delegación' : '' ?></button></form>
  <?php endif; ?>
  <div class="tabla-scroll"><table>
    <tr><th>Deportista</th><th>Delegación</th><th>Disciplinas</th><th>Requisitos</th><th>Estado</th></tr>
    <?php foreach ($lista as $x): $cr = olimp_cruces_deportista((int)$x['id'], $discs); ?>
      <tr><td><a href="<?= e($url . '&s=deportistas&dep=' . (int)$x['id']) ?>"><b><?= e(olimp_nombre_dep($x)) ?></b></a><br><small class="suave">CI <?= e(enmascarar_documento($x['cedula'])) ?> · <?= $x['sexo'] === 'F' ? 'D' : 'V' ?> · <?= edad($x['fecha_nac']) ?> años</small></td>
        <td><?= e($dels[(int)$x['delegacion_id']]['nombre'] ?? '') ?></td>
        <td style="white-space:normal"><small><?= e(implode(' · ', array_map(fn($i) => olimp_texto_inscripcion($i, $discs), $ins_por[(int)$x['id']] ?? []))) ?: '<span class="suave">sin disciplinas</span>' ?></small><?= $cr ? '<br><span class="etiqueta roja" title="' . e(implode(' · ', $cr)) . '">⏰ cruce de horario</span>' : '' ?></td>
        <td><?= html_estado_requisitos('olimp_deportistas', (int)$x['id'], olimp_mod_req($ev)) ?></td>
        <td><span class="etiqueta<?= $x['estado'] === 'aprobado' ? '' : ($x['estado'] === 'rechazado' ? ' roja' : ' gris') ?>"><?= e(olimp_estados_deportista()[$x['estado']]) ?></span>
          <?php if ($editar && $x['estado'] === 'pendiente'): ?><form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_aprobar"><input type="hidden" name="dep_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-mini">✓</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php if (!$lista): ?><p class="suave">No hay deportistas<?= $fdel || $fest !== '' || $q !== '' ? ' con ese filtro' : '. Las delegaciones los inscriben con su enlace, o regístrelos usted abajo' ?>.</p><?php endif; ?>
  <?php if ($editar && $activo && $dels): ?>
  <div class="tarjeta" style="margin-top:14px">
    <h2 style="margin-top:0">+ Registrar deportista</h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_guardar"><input type="hidden" name="dep_id" value="0">
      <?= olimp_html_campos_deportista(['delegacion_id' => $fdel], $ev, $dels) ?>
      <?= html_autorizacion('tercero', 'su participación en ' . $ev['nombre'], 'index.php?l=' . rawurlencode($liga['slug']) . '&v=privacidad') ?>
      <button class="btn">Registrar</button>
    </form>
  </div>
  <?php endif;
    endif;

elseif ($s === 'medallero'):
    $res = olimp_tabla($ev);
    $extras = filas('SELECT x.*, d.nombre AS del FROM olimp_puntos_extra x JOIN olimp_delegaciones d ON d.id = x.delegacion_id WHERE x.evento_id = ? ORDER BY x.id DESC', [$evid]); ?>
  <h2>🥇 Tabla general</h2>
  <?= olimp_html_tabla($res) ?>
  <h2>🏅 Podios por disciplina</h2>
  <?= olimp_html_podios($res) ?>
  <h2 id="extra">➕ Puntos extra</h2>
  <p class="suave" style="margin-top:0">Para lo que no es una competencia: desfile inaugural, mejor barra, juego limpio, puntualidad… También negativos (sanciones).</p>
  <?php if ($extras): ?>
  <div class="tabla-scroll"><table><tr><th>Delegación</th><th class="num">Puntos</th><th>Motivo</th><th>Fecha</th><th></th></tr>
    <?php foreach ($extras as $x): ?><tr><td><?= e($x['del']) ?></td><td class="num"><b><?= olimp_num((float)$x['puntos']) ?></b></td><td><?= e($x['motivo']) ?></td><td><small><?= e(substr($x['creado'], 0, 10)) ?> · <?= e($x['por']) ?></small></td>
      <td><?php if ($editar): ?><form method="post" onsubmit="return confirm('¿Quitar estos puntos?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="extra_borrar"><input type="hidden" name="extra_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-mini">quitar</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php if ($editar && $dels && $activo): ?>
  <form method="post" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="extra_agregar">
    <select name="delegacion_id" required><option value="">Delegación</option><?php foreach ($dels as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['nombre']) ?></option><?php endforeach; ?></select>
    <input type="text" name="puntos" inputmode="decimal" placeholder="Puntos (ej: 5 o -3)" required style="width:140px">
    <input type="text" name="motivo" maxlength="120" placeholder="Motivo" required>
    <button class="btn btn-chico">Agregar</button>
  </form>
  <?php endif; ?>

<?php else: /* config */
    $admins = filas('SELECT u.* FROM olimp_admins a JOIN usuarios u ON u.id = a.usuario_id WHERE a.evento_id = ? ORDER BY u.nombre', [$evid]);
    $usuarios_cuenta = filas("SELECT * FROM usuarios WHERE liga_id = ? AND rol = 'liga' AND activo = 1 ORDER BY nombre", [$lid]);
    $req = olimp_requisitos_evento($evid);
    $cat = requisitos_campos_catalogo(); ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="config">
  <fieldset<?= $editar ? '' : ' disabled' ?>>
    <legend>Datos del evento</legend>
    <div class="campos">
      <div class="campo-ancho"><label>Nombre *</label><input type="text" name="nombre" required maxlength="100" value="<?= e($ev['nombre']) ?>"></div>
      <div><label>Desde</label><input type="date" name="fecha_ini" value="<?= e($ev['fecha_ini']) ?>"></div>
      <div><label>Hasta</label><input type="date" name="fecha_fin" value="<?= e($ev['fecha_fin']) ?>"></div>
      <div><label>Sede principal</label><input type="text" name="sede" maxlength="120" value="<?= e($ev['sede']) ?>"></div>
      <div><label>Estado</label><select name="estado"><?php foreach (olimp_estados() as $k => $t): ?><option value="<?= e($k) ?>" <?= $ev['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div><label>Color</label><input type="color" name="color" value="<?= e(color_valido($ev['color'])) ?>"></div>
      <div><label>Logo del evento</label><input type="file" name="logo" accept="image/*"><?= $ev['logo'] ? '<div class="ayuda"><img src="' . e($ev['logo']) . '" class="mini" alt=""> <label style="display:inline;font-weight:400"><input type="checkbox" name="quitar_logo" value="1"> quitar</label></div>' : '' ?></div>
      <div class="campo-ancho"><label>Descripción (página pública)</label><textarea name="descripcion" rows="2" maxlength="600"><?= e($ev['descripcion']) ?></textarea></div>
      <div><label class="check" style="margin-top:28px"><input type="checkbox" name="publico" value="1" <?= $cfg['publico'] ? 'checked' : '' ?>> Página pública del evento visible</label></div>
    </div>
  </fieldset>
  <fieldset<?= $editar ? '' : ' disabled' ?>>
    <legend>🥇 Puntos y clasificación</legend>
    <div class="campos">
      <div class="campo-ancho"><label>Puntos por puesto (1.º, 2.º, 3.º…)</label><input type="text" name="puntos" value="<?= e(implode(', ', array_map(fn($x) => olimp_num((float)$x), $cfg['puntos']))) ?>"><div class="ayuda">Separados por comas. Ej: <b>10, 7, 5, 4, 3, 2, 1</b> u olímpico <b>3, 2, 1</b>. Los puestos que no estén en la lista no suman. Se aplica en cada categoría o prueba de cada disciplina (multiplicado por el factor de la disciplina).</div></div>
      <div><label>Gana el evento</label><select name="clasificacion"><option value="puntos" <?= $cfg['clasificacion'] === 'puntos' ? 'selected' : '' ?>>El que suma más puntos</option><option value="medallas" <?= $cfg['clasificacion'] === 'medallas' ? 'selected' : '' ?>>El que tiene más oros (medallero olímpico)</option></select></div>
      <div><label>Puntos por participar</label><input type="number" name="participacion" min="0" max="1000" value="<?= (int)$cfg['participacion'] ?>"><div class="ayuda">Por cada disciplina en la que la delegación tiene deportistas aprobados. 0 = no.</div></div>
      <div><label>Puntúan por delegación</label><input type="number" name="puntuan" min="0" max="50" value="<?= (int)$cfg['puntuan'] ?>"><div class="ayuda">En cada categoría o prueba solo suman los N mejores de cada delegación (y sus medallas). 0 = todos.</div></div>
      <div><label class="check" style="margin-top:28px"><input type="checkbox" name="provisional" value="1" <?= $cfg['provisional'] ? 'checked' : '' ?>> Contar también lo que todavía se juega</label><div class="ayuda">Sin marcar, cada categoría suma cuando termina.</div></div>
    </div>
  </fieldset>
  <fieldset<?= $editar ? '' : ' disabled' ?>>
    <legend>📝 Inscripciones y reglas</legend>
    <div class="campos">
      <div><label class="check" style="margin-top:28px"><input type="checkbox" name="insc_abierta" value="1" <?= $cfg['insc_abierta'] ? 'checked' : '' ?>> Las delegaciones pueden inscribir con su enlace</label></div>
      <div><label>Inscripciones hasta</label><input type="date" name="insc_hasta" value="<?= e($cfg['insc_hasta']) ?>"><div class="ayuda">Vacío = sin fecha límite. Usted puede inscribir después.</div></div>
      <div><label>Aprobación de deportistas</label><select name="aprobacion"><option value="manual" <?= $cfg['aprobacion'] === 'manual' ? 'selected' : '' ?>>La organización los aprueba</option><option value="auto" <?= $cfg['aprobacion'] === 'auto' ? 'selected' : '' ?>>Quedan aprobados al registrarse</option></select></div>
      <div><label class="check" style="margin-top:28px"><input type="checkbox" name="exigir_requisitos" value="1" <?= $cfg['exigir_requisitos'] ? 'checked' : '' ?>> Para aprobar, exigir los datos y documentos obligatorios</label></div>
      <div><label>Máximo de disciplinas por deportista</label><input type="number" name="max_disciplinas" min="0" max="50" value="<?= (int)$cfg['max_disciplinas'] ?>"><div class="ayuda">0 = sin límite.</div></div>
      <div><label>Máximo de deportistas por delegación</label><input type="number" name="max_delegacion" min="0" max="5000" value="<?= (int)$cfg['max_delegacion'] ?>"><div class="ayuda">0 = sin límite.</div></div>
      <div><label>Edad mínima</label><input type="number" name="edad_min" min="0" max="99" value="<?= (int)$cfg['edad_min'] ?>"><div class="ayuda">Al primer día del evento. 0 = no.</div></div>
      <div><label>Edad máxima</label><input type="number" name="edad_max" min="0" max="120" value="<?= (int)$cfg['edad_max'] ?>"></div>
      <div><label>Cruces de horario</label><select name="cruces"><option value="avisar" <?= $cfg['cruces'] === 'avisar' ? 'selected' : '' ?>>Avisar, pero dejar inscribir</option><option value="bloquear" <?= $cfg['cruces'] === 'bloquear' ? 'selected' : '' ?>>No dejar inscribir en dos disciplinas a la misma hora</option></select></div>
    </div>
  </fieldset>
  <?php if ($editar): ?><button class="btn">Guardar configuración</button><?php endif; ?>
  </form>

  <div class="tarjeta" id="requisitos" style="margin-top:14px">
    <h2 style="margin-top:0">📋 Datos y documentos de cada deportista</h2>
    <p class="suave" style="margin-top:0">Se piden en la ficha del deportista (aquí y en el enlace de la delegación). Ej: certificado laboral o carnet de la empresa para comprobar que es empleado. Lo obligatorio que falte se marca «⚠ falta».</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="requisitos">
    <fieldset<?= $editar ? '' : ' disabled' ?> style="border:0;padding:0">
      <div class="tabla-scroll"><table>
        <tr><th>Dato</th><th>No se pide</th><th>Se pide</th><th>Obligatorio</th></tr>
        <?php foreach ($cat as $c => [$lab]): $v = $req['campos'][$c] ?? 0; ?>
          <tr><td><?= e($lab) ?></td><?php foreach ([0, 1, 2] as $o): ?><td style="text-align:center"><input type="radio" name="campo[<?= e($c) ?>]" value="<?= $o ?>" <?= $v === $o ? 'checked' : '' ?> aria-label="<?= e($lab) ?>"></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </table></div>
      <h3>Documentos</h3>
      <div class="tabla-scroll"><table>
        <tr><th>Documento</th><th>Obligatorio</th><th>Tiene vencimiento</th></tr>
        <?php foreach (array_merge($req['docs'], array_fill(0, 3, ['clave' => '', 'nombre' => '', 'obligatorio' => 0, 'vence' => 0])) as $i => $d): ?>
          <tr><td><input type="hidden" name="doc_clave[<?= $i ?>]" value="<?= e($d['clave']) ?>"><input type="text" name="doc_nombre[<?= $i ?>]" value="<?= e($d['nombre']) ?>" maxlength="80" placeholder="<?= $d['clave'] === '' ? 'Nuevo documento (ej: Rol de pagos)' : '' ?>" style="min-width:240px"></td>
            <td style="text-align:center"><input type="checkbox" name="doc_obl[<?= $i ?>]" value="1" <?= $d['obligatorio'] ? 'checked' : '' ?>></td>
            <td style="text-align:center"><input type="checkbox" name="doc_vence[<?= $i ?>]" value="1" <?= $d['vence'] ? 'checked' : '' ?>></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php if ($editar): ?><button class="btn">Guardar datos y documentos</button><?php endif; ?>
    </fieldset>
    </form>
  </div>

  <div class="tarjeta" id="admins" style="margin-top:14px">
    <h2 style="margin-top:0">👤 Administradores del evento</h2>
    <p class="suave" style="margin-top:0">Pueden manejar <b>solo este evento</b>: configuración, disciplinas (y los resultados de sus campeonatos), delegaciones, deportistas y medallero. No ven el resto de la cuenta. El súper y los administradores de la cuenta pueden todo.</p>
    <?php if ($admins): ?>
      <table><?php foreach ($admins as $u): ?><tr><td><b><?= e($u['nombre']) ?></b> <small class="suave"><?= e($u['email']) ?> · <?= e(nombre_perfil((string)$u['perfil'])) ?><?= (int)$u['activo'] ? '' : ' · desactivado' ?></small></td>
        <td><?php if (!$es_evento && $editar): ?><form method="post" onsubmit="return confirm('¿Quitarle este evento?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="admin_quitar"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sec btn-mini">quitar</button></form><?php endif; ?></td></tr><?php endforeach; ?></table>
    <?php else: ?><p>Todavía no hay administradores propios del evento.</p><?php endif; ?>
    <?php if (!$es_evento && $editar && $activo): ?>
      <h3>+ Crear un usuario para este evento</h3>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="admin_crear">
        <div class="campos">
          <div><label>Nombre *</label><input type="text" name="u_nombre" required maxlength="80"></div>
          <div><label>Correo (para entrar) *</label><input type="email" name="u_email" required maxlength="120" autocomplete="off"></div>
          <div><label>Contraseña (mínimo 8) *</label><input type="text" name="u_clave" required minlength="8" maxlength="72" autocomplete="new-password"></div>
        </div>
        <?= html_autorizacion('tercero', 'darle acceso al panel del evento y registrar sus acciones', 'index.php?l=' . rawurlencode($liga['slug']) . '&v=privacidad') ?>
        <button class="btn btn-chico">Crear y asignar</button>
      </form>
      <?php $otros = array_filter($usuarios_cuenta, fn($u) => !in_array((int)$u['id'], array_map(fn($a) => (int)$a['id'], $admins), true) && ($u['perfil'] ?? '') !== 'admin'); if ($otros): ?>
      <h3>…o asígnele el evento a un usuario que ya existe</h3>
      <form method="post" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="admin_agregar">
        <select name="usuario_id" required><option value="">— elija —</option><?php foreach ($otros as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['nombre'] . ' · ' . nombre_perfil((string)$u['perfil'])) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sec btn-chico">Asignar</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if (!$es_evento && $editar): ?>
  <details class="tarjeta" style="margin-top:14px"><summary><b>🗑 Borrar el evento</b></summary>
    <p>Se borran las delegaciones, deportistas, inscripciones y puntos extra del evento. <b>Los campeonatos no se borran</b> (quedan en Campeonatos con sus resultados).</p>
    <form method="post" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_evento"><input type="text" name="confirmar" placeholder="Escriba BORRAR" required><button class="btn btn-sec">Borrar el evento</button></form>
  </details>
  <?php endif; ?>
<?php endif;
pie_admin();
