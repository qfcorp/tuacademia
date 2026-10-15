<?php
/*
 * VOCALÍA DESDE EL CELULAR
 * El vocal abre el enlace secreto del partido: vocal.php?k=XXXX (no necesita usuario).
 * Registra quién es, los jugadores presentes, el cronómetro y los goles/tarjetas EN VIVO.
 */
require __DIR__ . '/../app/bootstrap.php';

$k = get('k');
$p = null;
if (strlen($k) === 32 && ctype_xdigit($k)) {
    $id = valor('SELECT id FROM partidos WHERE vocal_token = ?', [$k]);
    $p = $id ? partido_completo((int)$id) : null;
}
if (!$p) {
    http_response_code(404);
    cabecera_publica('Vocalía');
    echo '<div class="tarjeta"><h1>Enlace no válido</h1><p>Este enlace de vocalía no existe o fue cambiado. Pida el enlace nuevo al administrador de la liga.</p></div>';
    pie_publico();
    exit;
}
$pid = (int)$p['id'];
$liga = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]);
cortar_liga_publica($liga, true, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible» (su personal: «Tu cuenta está pausada»)
exigir_modulo_portal($liga, 'vocalia'); // (v12) módulo del plan
$_SESSION['vocal_partidos'] = array_values(array_unique(array_merge($_SESSION['vocal_partidos'] ?? [], [$pid])));
$url = 'vocal.php?k=' . $k;
$reglas = reglas_torneo((int)$p['torneo_id']);
$max_minuto = (int)$reglas['num_tiempos'] * (int)$reglas['minutos_tiempo'] + 30;
$max_presentes = (int)$reglas['jugadores_cancha'] + (int)$reglas['max_suplentes'];
$dep = deporte_de($reglas);
$T = textos_deporte($dep);
// Deudas de los equipos (si el campeonato avisa o bloquea por deuda)
sincronizar_cargos((int)$p['torneo_id']);
$deudas = [];
if ($reglas['bloqueo_deuda'] !== 'no') {
    foreach ([[(int)$p['local_id'], $p['local_nombre']], [(int)$p['visitante_id'], $p['visitante_nombre']]] as [$eid, $enom]) {
        $sal = saldo_equipo($eid);
        if ($sal > (float)$reglas['deuda_maxima'] + 0.001) $deudas[] = [$enom, $sal];
    }
}

if (get('imprimir') === '1') {
    doc_vocalia($p, $liga, $url);
    exit;
}

// La vocalía NO usa el control de formularios por sesión: el enlace secreto (k) ya identifica al vocal.
// Así las acciones guardadas sin internet se pueden enviar después aunque la sesión del celular haya vencido.
$vocales = filas('SELECT v.*, e.nombre AS equipo FROM vocales_partido v LEFT JOIN equipos e ON e.id = v.equipo_id WHERE v.partido_id = ? ORDER BY v.id', [$pid]);
// Quién actúa en la bitácora: el vocal (con su nombre) desde el enlace de este partido
contexto_auditoria([
    'usuario' => ($vocales ? 'Vocal: ' . $vocales[0]['nombre'] . ($vocales[0]['tipo'] === 'equipo' ? ' (delegado ' . ($vocales[0]['equipo'] ?? '') . ')' : ' (directivo)') : 'Vocal sin registrar') . ' · partido #' . $pid,
    'rol' => 'vocal', 'liga_id' => (int)$p['liga_id'], 'origen' => 'vocal',
]);

if (es_post()) {
    // Acciones que llegan desde la cola sin internet: cada una trae un código único (cid) para no repetirse
    // y la hora real en que se hizo (ts).
    $cid = preg_match('/^[a-z0-9-]{4,60}$/', post('cid')) ? post('cid') : '';
    if ($cid !== '' && fila('SELECT cid FROM acciones_offline WHERE cid = ?', [$cid])) {
        ir($url);
    }
    $ts = (int)post('ts');
    $ahora = ($ts > time() - 12 * 3600 && $ts < time() + 120) ? $ts : time();
    $fuera_de_linea = $ts > 0 && $ts < time() - 30;
    $accion = post('accion');
    try {
        if ($p['vocal_cerrada']) throw new RuntimeException('La vocalía ya está cerrada. Si necesita corregir algo, pida al administrador que la reabra.');
        exigir_suscripcion($liga);
        $firma = $vocales ? 'Vocal: ' . $vocales[0]['nombre'] : 'Vocal';
        $cat = torneo_con_categoria($reglas) ? $reglas : null;
        $max_pasados = $cat ? ((int)$cat['pasados_partido'] > 0 ? (int)$cat['pasados_partido'] : (int)$cat['pasados_plantilla']) : 0;

        if ($accion === 'registrar_vocal') {
            $nombre = post('nombre');
            if (mb_strlen($nombre) < 3) throw new RuntimeException('Escriba su nombre completo.');
            $tipo = post('tipo') === 'equipo' ? 'equipo' : 'directivo';
            $eq = null;
            if ($tipo === 'equipo') {
                $eq = (int)post('equipo_id');
                if (!fila('SELECT id FROM equipos WHERE id = ? AND liga_id = ?', [$eq, $p['liga_id']])) throw new RuntimeException('Elija de qué equipo es delegado.');
            }
            q('INSERT INTO vocales_partido (partido_id, nombre, tipo, equipo_id) VALUES (?,?,?,?)', [$pid, mb_substr($nombre, 0, 80), $tipo, $eq]);
            flash('Bienvenido, ' . $nombre . '. Ahora marque los jugadores presentes.');
        }

        elseif ($accion === 'alineacion') {
            $susp = suspensiones((int)$p['torneo_id']);
            $marcados = array_map('intval', (array)($_POST['presentes'] ?? []));
            $validos = [];
            foreach (filas('SELECT id, equipo_id FROM jugadores WHERE activo = 1 AND equipo_id IN (?,?)', [$p['local_id'], $p['visitante_id']]) as $j) {
                $validos[(int)$j['id']] = (int)$j['equipo_id'];
            }
            $rechazados = 0;
            $por_equipo = [];
            foreach ($marcados as $jid) {
                if (isset($validos[$jid]) && !isset($susp[$jid])) $por_equipo[$validos[$jid]] = ($por_equipo[$validos[$jid]] ?? 0) + 1;
            }
            foreach ($por_equipo as $eq => $n) {
                if ($n > $max_presentes) {
                    throw new RuntimeException('Marcó ' . $n . ' jugadores de ' . ($eq === (int)$p['local_id'] ? $p['local_nombre'] : $p['visitante_nombre']) . '. El máximo en este campeonato es ' . $max_presentes . ' (' . (int)$reglas['jugadores_cancha'] . ' en cancha + ' . (int)$reglas['max_suplentes'] . ' suplentes). No se guardó.');
                }
            }
            if ($cat) {
                $pas = [];
                foreach ($marcados as $jid) {
                    if (!isset($validos[$jid])) continue;
                    $sit = situacion_categoria($cat, fila('SELECT * FROM jugadores WHERE id = ?', [$jid]));
                    if ($sit['estado'] === 'fuera') throw new RuntimeException(nombre_jugador(fila('SELECT * FROM jugadores WHERE id = ?', [$jid])) . ' no puede jugar esta categoría: ' . $sit['texto'] . '. No se guardó.');
                    if ($sit['estado'] === 'pasado') $pas[$validos[$jid]] = ($pas[$validos[$jid]] ?? 0) + 1;
                }
                foreach ($pas as $eq => $n) {
                    if ($n > $max_pasados) throw new RuntimeException(($eq === (int)$p['local_id'] ? $p['local_nombre'] : $p['visitante_nombre']) . ' marcó ' . $n . ' pasados; en esta categoría se permiten ' . $max_pasados . ' por partido. No se guardó.');
                }
            }
            q('DELETE FROM alineaciones WHERE partido_id = ?', [$pid]);
            foreach ($marcados as $jid) {
                if (!isset($validos[$jid])) continue;
                if (isset($susp[$jid])) { $rechazados++; continue; }
                q('INSERT OR IGNORE INTO alineaciones (partido_id, jugador_id, equipo_id) VALUES (?,?,?)', [$pid, $jid, $validos[$jid]]);
            }
            flash('Jugadores presentes guardados.' . ($rechazados ? " ($rechazados suspendido(s) no fueron incluidos.)" : ''));
        }

        elseif ($accion === 'presente_uno') {
            $j = fila('SELECT * FROM jugadores WHERE id = ? AND activo = 1 AND equipo_id IN (?,?)', [(int)post('jugador_id'), $p['local_id'], $p['visitante_id']]);
            if (!$j) throw new RuntimeException('Ese jugador no pertenece a ninguno de los dos equipos o está dado de baja.');
            if (isset(suspensiones((int)$p['torneo_id'])[(int)$j['id']])) throw new RuntimeException(nombre_jugador($j) . ' está SUSPENDIDO: no puede jugar.');
            $n = (int)valor('SELECT COUNT(*) FROM alineaciones WHERE partido_id = ? AND equipo_id = ?', [$pid, $j['equipo_id']]);
            $ya = (bool)valor('SELECT COUNT(*) FROM alineaciones WHERE partido_id = ? AND jugador_id = ?', [$pid, $j['id']]);
            if (!$ya && $n >= $max_presentes) throw new RuntimeException('El equipo ya tiene ' . $n . ' presentes (máximo ' . $max_presentes . ').');
            if ($cat && !$ya) {
                $sit = situacion_categoria($cat, $j);
                if ($sit['estado'] === 'fuera') throw new RuntimeException(nombre_jugador($j) . ' no puede jugar esta categoría: ' . $sit['texto'] . '.');
                if ($sit['estado'] === 'pasado') {
                    $n_pas = 0;
                    foreach (filas('SELECT j.* FROM alineaciones a JOIN jugadores j ON j.id = a.jugador_id WHERE a.partido_id = ? AND a.equipo_id = ?', [$pid, $j['equipo_id']]) as $o) {
                        if (situacion_categoria($cat, $o)['estado'] === 'pasado') $n_pas++;
                    }
                    if ($n_pas >= $max_pasados) throw new RuntimeException('El equipo ya tiene ' . $n_pas . ' pasados presentes (máximo ' . $max_pasados . ' por partido). ' . nombre_jugador($j) . ' no puede ingresar.');
                }
            }
            q('INSERT OR IGNORE INTO alineaciones (partido_id, jugador_id, equipo_id) VALUES (?,?,?)', [$pid, $j['id'], $j['equipo_id']]);
            flash('✓ ' . nombre_jugador($j) . ' verificado y marcado como presente.');
        }

        elseif ($accion === 'iniciar' && $p['estado'] === 'en_vivo') {
            // ya estaba iniciado (acción repetida): no se hace nada
        }

        elseif ($accion === 'iniciar') {
            if (in_array($p['estado'], ['jugado', 'wo'], true)) throw new RuntimeException('El partido ya terminó.');
            if ($reglas['bloqueo_deuda'] === 'bloquear' && $deudas) {
                throw new RuntimeException('No se puede iniciar: ' . implode(', ', array_map(fn($d) => $d[0] . ' debe ' . dinero($d[1]), $deudas)) . '. La liga no permite jugar con deudas mayores a ' . dinero($reglas['deuda_maxima']) . '. Deben pagar al tesorero o el administrador debe iniciar el partido desde el panel.');
            }
            $hora = $fuera_de_linea ? date('H:i', $ahora) : (hora_valida(post('hora_inicio')) ?: date('H:i', $ahora));
            [$gl, $gv] = marcador_desde_eventos($p);
            q("UPDATE partidos SET estado = 'en_vivo', hora_inicio = ?, vivo_periodo = 1, vivo_desde = ?, vivo_pausa = 0, goles_local = ?, goles_visitante = ?,
               fecha = CASE WHEN fecha = '' THEN ? ELSE fecha END WHERE id = ?", [$hora, $ahora, $gl, $gv, date('Y-m-d', $ahora), $pid]);
            aviso_inicio($pid);
            flash('¡Partido iniciado a las ' . $hora . '! Ya se ve EN VIVO en la página pública.');
        }

        elseif ($accion === 'fin_periodo') {
            if ($p['estado'] === 'en_vivo' && !(int)$p['vivo_pausa']) {
                q('UPDATE partidos SET vivo_pausa = 1 WHERE id = ?', [$pid]);
                flash('Fin del tiempo ' . (int)$p['vivo_periodo'] . '.');
            }
        }

        elseif ($accion === 'siguiente_periodo') {
            if ($p['estado'] === 'en_vivo' && (int)$p['vivo_pausa'] && (int)$p['vivo_periodo'] < (int)$reglas['num_tiempos']) {
                $sig = (int)$p['vivo_periodo'] + 1;
                q('UPDATE partidos SET vivo_periodo = ?, vivo_desde = ?, vivo_pausa = 0 WHERE id = ?', [$sig, $ahora, $pid]);
                flash('Comenzó el tiempo ' . $sig . '.');
            }
        }

        elseif ($accion === 'finalizar') {
            if (!in_array($p['estado'], ['jugado', 'wo'], true)) {
                if ($dep === 'ecuavoley') {
                    // El set que quedó abierto con un ganador se cierra; si quedó en 0-0 se borra
                    q('DELETE FROM partido_sets WHERE partido_id = ? AND cerrado = 0 AND puntos_local = 0 AND puntos_visitante = 0', [$pid]);
                    [$sg_l, $sg_v] = sets_ganados($pid);
                    if (max($sg_l, $sg_v) >= (int)$reglas['sets_ganar']) q('DELETE FROM partido_sets WHERE partido_id = ? AND cerrado = 0', [$pid]);
                    else q('UPDATE partido_sets SET cerrado = 1 WHERE partido_id = ? AND cerrado = 0 AND puntos_local <> puntos_visitante', [$pid]);
                }
                [$gl, $gv] = marcador_desde_eventos($p);
                q("UPDATE partidos SET estado = 'jugado', hora_fin = ?, vivo_pausa = 1, goles_local = ?, goles_visitante = ?,
                   fecha = CASE WHEN fecha = '' THEN ? ELSE fecha END WHERE id = ?", [$fuera_de_linea ? date('H:i', $ahora) : (hora_valida(post('hora_fin')) ?: date('H:i', $ahora)), $gl, $gv, date('Y-m-d', $ahora), $pid]);
                aviso_resultado($pid);
                flash("Partido finalizado: $gl - $gv. Revise todo y al final presione \"Cerrar vocalía\".");
            }
        }

        elseif ($accion === 'horas') {
            q('UPDATE partidos SET hora_inicio = ?, hora_fin = ? WHERE id = ?', [hora_valida(post('hora_inicio')), hora_valida(post('hora_fin')), $pid]);
            flash('Horas guardadas.');
        }

        elseif ($accion === 'evento') {
            $tipo = post('tipo');
            if (!in_array($tipo, tipos_evento_de($reglas), true)) throw new RuntimeException('Elija el tipo de registro (botones de colores).');
            $j = fila('SELECT * FROM jugadores WHERE id = ? AND equipo_id IN (?,?)', [(int)post('jugador_id'), $p['local_id'], $p['visitante_id']]);
            if (!$j) throw new RuntimeException('Primero elija el jugador de la lista.');
            $min = post('minuto') === '' ? null : entero(post('minuto'), 0, $max_minuto);
            q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, nota, origen, registrado) VALUES (?,?,?,?,?,?, 'vocal', ?)",
                [$pid, $j['id'], $j['equipo_id'], $tipo, $min, mb_substr(post('nota'), 0, 200), date('Y-m-d H:i:s', $ahora)]);
            $ev_id = (int)db()->lastInsertId();
            if ($p['estado'] === 'programado') {
                q("UPDATE partidos SET estado = 'en_vivo', vivo_periodo = CASE WHEN vivo_periodo = 0 THEN 1 ELSE vivo_periodo END,
                   vivo_desde = CASE WHEN vivo_desde = 0 THEN ? ELSE vivo_desde END, hora_inicio = CASE WHEN hora_inicio = '' THEN ? ELSE hora_inicio END WHERE id = ?", [$ahora, date('H:i', $ahora), $pid]);
            }
            [$gl, $gv] = actualizar_marcador($p);
            if (!in_array($p['estado'], ['jugado', 'wo'], true)) aviso_gol($pid, $ev_id);
            $aviso_faltas = '';
            if ($tipo === 'falta' && (int)$reglas['faltas_limite'] > 0) {
                $nf = (int)valor("SELECT COUNT(*) FROM eventos WHERE partido_id = ? AND jugador_id = ? AND tipo = 'falta'", [$pid, $j['id']]);
                if ($nf >= (int)$reglas['faltas_limite']) $aviso_faltas = ' · ⚠ LLEGÓ A ' . $nf . ' FALTAS: debe salir del partido.';
                else $aviso_faltas = ' · lleva ' . $nf . ' falta(s)';
            }
            flash(nombre_evento($tipo, $dep) . ': ' . nombre_jugador($j) . ($min !== null ? " ($min')" : '') . (es_anotacion($tipo) ? " · Marcador $gl - $gv" : '') . $aviso_faltas, $aviso_faltas !== '' && str_contains($aviso_faltas, '⚠') ? 'error' : 'ok');
        }

        elseif ($accion === 'punto') {
            // Ecuavóley: +1 / -1 punto en el set que se está jugando
            if ($dep !== 'ecuavoley') throw new RuntimeException('Esta acción es solo para ecuavóley.');
            if (in_array($p['estado'], ['jugado', 'wo'], true)) throw new RuntimeException('El partido ya terminó.');
            [$sg_l, $sg_v] = sets_ganados($pid);
            if (max($sg_l, $sg_v) >= (int)$reglas['sets_ganar']) throw new RuntimeException('El partido ya tiene ganador (' . $sg_l . ' - ' . $sg_v . ' en sets). Presione "Finalizar partido".');
            $lado = post('lado') === 'v' ? 'puntos_visitante' : 'puntos_local';
            $delta = post('delta') === '-1' ? -1 : 1;
            $st = set_actual($pid);
            q("UPDATE partido_sets SET $lado = MAX(0, $lado + ?) WHERE partido_id = ? AND num = ?", [$delta, $pid, $st['num']]);
            if ($p['estado'] !== 'en_vivo') {
                q("UPDATE partidos SET estado = 'en_vivo', vivo_periodo = 1, vivo_desde = ?, vivo_pausa = 0, hora_inicio = CASE WHEN hora_inicio = '' THEN ? ELSE hora_inicio END,
                   fecha = CASE WHEN fecha = '' THEN ? ELSE fecha END WHERE id = ?", [$ahora, date('H:i', $ahora), date('Y-m-d', $ahora), $pid]);
            }
            $st = fila('SELECT * FROM partido_sets WHERE partido_id = ? AND num = ?', [$pid, $st['num']]);
            $meta = puntos_para_set($reglas, (int)$st['num']);
            $pl = (int)$st['puntos_local'];
            $pv = (int)$st['puntos_visitante'];
            $fin_set = max($pl, $pv) >= $meta && abs($pl - $pv) >= 2;
            flash('Set ' . (int)$st['num'] . ': ' . $pl . ' - ' . $pv . ($fin_set ? ' · ¿Terminó el set? Presione "Cerrar set".' : ''));
        }

        elseif ($accion === 'cerrar_set') {
            if ($dep !== 'ecuavoley') throw new RuntimeException('Esta acción es solo para ecuavóley.');
            $st = fila('SELECT * FROM partido_sets WHERE partido_id = ? AND cerrado = 0 ORDER BY num DESC LIMIT 1', [$pid]);
            if (!$st) throw new RuntimeException('No hay un set en juego.');
            if ((int)$st['puntos_local'] === (int)$st['puntos_visitante']) throw new RuntimeException('El set está empatado: no se puede cerrar.');
            q('UPDATE partido_sets SET cerrado = 1 WHERE partido_id = ? AND num = ?', [$pid, $st['num']]);
            [$gl, $gv] = actualizar_marcador($p);
            $ganador = (int)$st['puntos_local'] > (int)$st['puntos_visitante'] ? $p['local_nombre'] : $p['visitante_nombre'];
            $termina = max($gl, $gv) >= (int)$reglas['sets_ganar'];
            flash('Set ' . (int)$st['num'] . ' para ' . $ganador . ' (' . (int)$st['puntos_local'] . '-' . (int)$st['puntos_visitante'] . '). Sets: ' . $gl . ' - ' . $gv . ($termina ? '. ¡' . $ganador . ' ganó el partido! Presione "Finalizar partido".' : ''));
        }

        elseif ($accion === 'borrar_evento') {
            q('DELETE FROM eventos WHERE id = ? AND partido_id = ?', [(int)post('evento_id'), $pid]);
            actualizar_marcador($p);
            flash('Evento borrado.');
        }

        elseif ($accion === 'observaciones') {
            q('UPDATE partidos SET vocal_obs = ? WHERE id = ?', [mb_substr(post('vocal_obs'), 0, 3000), $pid]);
            flash('Observaciones guardadas.');
        }

        elseif ($accion === 'fotos') {
            $rutas = subir_fotos_privadas('fotos');
            if (!$rutas) throw new RuntimeException('Tome o elija al menos una foto.');
            foreach ($rutas as $r) {
                q('INSERT INTO fotos_partido (partido_id, archivo, descripcion, subido_por) VALUES (?,?,?,?)', [$pid, $r, mb_substr(post('descripcion'), 0, 200), $firma]);
            }
            flash(count($rutas) . ' foto(s) guardada(s) (solo uso interno).');
        }

        elseif ($accion === 'calificar') {
            if ($reglas['calificar_arbitro'] === 'no') throw new RuntimeException('Este campeonato no califica árbitros.');
            $directivo = array_values(array_filter($vocales, fn($v) => $v['tipo'] === 'directivo'));
            if (!$directivo) throw new RuntimeException('Solo un vocal DIRECTIVO de la liga puede calificar al árbitro (no los delegados de equipo).');
            guardar_calificacion($p, (int)post('arbitro_id'), $_POST, 'Vocal directivo: ' . $directivo[0]['nombre']);
            flash('Calificación del árbitro guardada.');
        }

        elseif ($accion === 'cerrar') {
            if (!in_array($p['estado'], ['jugado', 'wo'], true)) throw new RuntimeException('Primero presione "Finalizar partido".');
            if (!$vocales) throw new RuntimeException('Falta registrar el nombre del vocal.');
            $hay_directivo = (bool)array_filter($vocales, fn($v) => $v['tipo'] === 'directivo');
            if ($reglas['calificar_arbitro'] === 'obligatorio' && $hay_directivo && calificacion_pendiente($p)) {
                throw new RuntimeException('En este campeonato la calificación del árbitro es obligatoria. Califíquelo antes de cerrar la vocalía.');
            }
            q('UPDATE partidos SET vocal_cerrada = 1, vocal_cerrada_en = ? WHERE id = ?', [date('Y-m-d H:i'), $pid]);
            aviso_admins($liga, '📋 Vocalía cerrada: ' . e($p['local_nombre'] . ' ' . $p['goles_local'] . ' - ' . $p['goles_visitante'] . ' ' . $p['visitante_nombre']) . ' (' . e($vocales[0]['nombre']) . ')', 'cerr:' . $pid);
            aviso_resultado($pid);
            flash('Vocalía cerrada y enviada a la liga. ¡Gracias!');
        }
    } catch (RuntimeException $ex) {
        flash(($fuera_de_linea ? 'Acción guardada sin internet (' . date('H:i', $ahora) . ') no aplicada: ' : '') . $ex->getMessage(), 'error');
    }
    if ($cid !== '') {
        q('INSERT OR IGNORE INTO acciones_offline (cid, partido_id, accion) VALUES (?,?,?)', [$cid, $pid, mb_substr($accion, 0, 30)]);
    }
    $ancla = ['punto' => '#sets', 'cerrar_set' => '#sets', 'evento' => '#registrar', 'borrar_evento' => '#cronologia', 'alineacion' => '#control', 'fotos' => '#fotos', 'observaciones' => '#obs', 'calificar' => '#calificar', 'presente_uno' => '#verificar'][post('accion')] ?? '';
    ir($url . $ancla);
}

/* ---------- Pantalla ---------- */
$susp = suspensiones((int)$p['torneo_id']);
$jl = jugadores_para_partido($p, (int)$p['local_id'], $susp);
$jv = jugadores_para_partido($p, (int)$p['visitante_id'], $susp);
$eventos = filas('SELECT ev.*, j.nombres, j.apellidos, j.numero FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.partido_id = ? ORDER BY ev.id DESC', [$pid]);
$fotos = filas('SELECT * FROM fotos_partido WHERE partido_id = ? ORDER BY id DESC', [$pid]);
$n_pres = count(array_filter($jl, fn($j) => $j['presente'])) + count(array_filter($jv, fn($j) => $j['presente']));
$vivo = minuto_vivo($p, $liga);
$cerrada = (bool)$p['vocal_cerrada'];
$terminado = in_array($p['estado'], ['jugado', 'wo'], true);
$ultimo_periodo = (int)$p['vivo_periodo'] >= (int)$reglas['num_tiempos'];
$pres_l = count(array_filter($jl, fn($j) => $j['presente']));
$pres_v = count(array_filter($jv, fn($j) => $j['presente']));
$es_directivo = (bool)array_filter($vocales, fn($v) => $v['tipo'] === 'directivo');
$equipos_liga = filas('SELECT id, nombre FROM equipos WHERE liga_id = ? ORDER BY nombre', [$p['liga_id']]);
$nombres_previos = array_column(filas('SELECT DISTINCT v.nombre FROM vocales_partido v JOIN partidos pa ON pa.id = v.partido_id JOIN torneos t ON t.id = pa.torneo_id WHERE t.liga_id = ? ORDER BY v.nombre LIMIT 200', [$p['liga_id']]), 'nombre');
$cat = torneo_con_categoria($reglas) ? $reglas : null;
$sets = $dep === 'ecuavoley' ? sets_partido($pid) : [];
$set_abierto = null;
foreach ($sets as $st_) if (!(int)$st_['cerrado']) $set_abierto = $st_;
$faltas = [];
if ($dep === 'basquet') foreach (filas("SELECT jugador_id, COUNT(*) AS n FROM eventos WHERE partido_id = ? AND tipo = 'falta' GROUP BY jugador_id", [$pid]) as $f_) $faltas[(int)$f_['jugador_id']] = (int)$f_['n'];
// Datos de los carnets de ambos equipos: permiten verificar con el QR aunque no haya internet
$mapa = [];
foreach ([$jl, $jv] as $lista) {
    foreach ($lista as $j) {
        $sit = $cat ? situacion_categoria($cat, $j) : ['estado' => 'libre', 'texto' => ''];
        $mapa[(int)$j['id']] = ['tk' => (string)($j['carnet_token'] ?? ''), 'n' => nombre_jugador($j), 'num' => $j['numero'], 'e' => (int)$j['equipo_id'],
            'foto' => $j['foto'], 'ced' => $j['cedula'], 'edad' => edad($j['fecha_nac']), 'susp' => $j['suspendido'], 'pres' => $j['presente'], 'cat' => $sit['estado'], 'cat_txt' => $sit['texto']];
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Vocalía <?= e($p['local_nombre'] . ' vs ' . $p['visitante_nombre']) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/estilo.css?v=330">
<?= cabecera_app() ?>
</head>
<body class="vocal-body" style="--acento: <?= e($liga['color']) ?>">
<main class="contenedor">
  <div class="marcador-grande">
    <div class="nom"><?= escudo($p['local_escudo'], $p['local_nombre'], $p['local_color']) ?><br><?= e($p['local_nombre']) ?></div>
    <div class="goles"><?= (int)$p['goles_local'] ?> - <?= (int)$p['goles_visitante'] ?></div>
    <div class="nom"><?= escudo($p['visitante_escudo'], $p['visitante_nombre'], $p['visitante_color']) ?><br><?= e($p['visitante_nombre']) ?></div>
    <div class="reloj" id="reloj"
         data-estado="<?= e($p['estado']) ?>" data-periodo="<?= (int)$p['vivo_periodo'] ?>" data-desde="<?= (int)$p['vivo_desde'] ?>"
         data-pausa="<?= (int)$p['vivo_pausa'] ?>" data-mt="<?= (int)$reglas['minutos_tiempo'] ?>" data-ahora="<?= time() ?>">
      <?= $terminado ? 'FINAL' : ($vivo ? e($dep === 'ecuavoley' ? $vivo['txt'] : $vivo['etiqueta'] . ' · ' . $vivo['txt']) : 'Por iniciar' . ($p['hora'] ? ' · ' . e($p['hora']) : '')) ?>
    </div>
    <?php if ($dep === 'ecuavoley'): ?><div class="sets-mini" id="sets-mini"><?= e(detalle_sets($pid)) ?></div><?php endif; ?>
  </div>

  <?php foreach (tomar_flash() as [$tipo, $msg]): ?><div class="aviso aviso-<?= e($tipo) ?>"><?= e($msg) ?></div><?php endforeach; ?>

  <div class="tarjeta" style="padding:10px 14px">
    <strong>Vocalía</strong> · <?= e($liga['nombre']) ?> · <?= e($p['torneo']) ?> · <?= $p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : e(nombre_fase($p['fase'])) ?><br>
    <small><?= implode(' · ', array_map(fn($x) => e($x[1]) . ': ' . e(($x[0] === 'arbitro_id' ? nombre_arbitro($p) : $x[3]) ?: 'sin asignar'), arbitros_del_partido($p))) ?></small><br>
    <small><?= e(resumen_reglas($reglas)) ?> · máx. <?= $max_presentes ?> presentes por equipo</small>
    <?php if ($vocales): ?><br><small>Vocal: <?= e(implode(', ', array_map(fn($v) => $v['nombre'] . ' (' . ($v['tipo'] === 'equipo' ? 'delegado ' . ($v['equipo'] ?? '') : 'directivo') . ')', $vocales))) ?></small><?php endif; ?>
  </div>

<?php if ($cerrada): ?>
  <div class="aviso aviso-ok">✓ Vocalía cerrada el <?= e($p['vocal_cerrada_en']) ?>. Si hay que corregir algo, el administrador puede reabrirla.</div>
  <a class="btn btn-grande" href="<?= e($url) ?>&amp;imprimir=1">🖨 Ver / imprimir / PDF la hoja de vocalía</a>
<?php elseif (!$vocales): ?>
  <div class="tarjeta">
    <h2>¿Quién llena la vocalía?</h2>
    <p class="suave" style="margin-top:0">Hágalo <b>con internet</b> (antes del partido). Después la vocalía sigue funcionando aunque se pierda la señal.</p>
    <form method="post" data-online="1">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="registrar_vocal">
      <p><label>Su nombre completo</label><input type="text" name="nombre" required maxlength="80" list="nombres-previos" autocomplete="name"></p>
      <datalist id="nombres-previos"><?php foreach ($nombres_previos as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
      <p><label>Usted es</label>
        <select name="tipo" id="tipo-vocal" onchange="document.getElementById('eq-vocal').style.display = this.value === 'equipo' ? '' : 'none'">
          <option value="directivo">Directivo de la liga</option>
          <option value="equipo">Delegado de un equipo</option>
        </select></p>
      <p id="eq-vocal" style="display:none"><label>¿De qué equipo?</label><select name="equipo_id"><option value="">— elegir —</option><?= opciones_equipos($equipos_liga) ?></select></p>
      <button class="btn btn-grande" type="submit">Continuar</button>
    </form>
  </div>
<?php else: ?>

  <?php foreach ($deudas as [$dn, $ds]): ?>
    <div class="aviso aviso-error">💵 <b><?= e($dn) ?></b> tiene una deuda de <b><?= dinero($ds) ?></b> con la liga.<?= $reglas['bloqueo_deuda'] === 'bloquear' ? ' <b>No puede jugar hasta pagar</b> (o que el administrador lo autorice).' : '' ?></div>
  <?php endforeach; ?>

  <!-- VERIFICAR JUGADOR (carnet QR / cédula) -->
  <div class="tarjeta" id="verificar">
    <h2 style="margin-top:0">🪪 Verificar jugador</h2>
    <button type="button" class="btn btn-grande" onclick="abrirLector()">📷 Escanear carnet (QR)</button>
    <form method="get" style="display:flex;gap:8px;margin-top:6px">
      <input type="hidden" name="k" value="<?= e($k) ?>">
      <input type="text" name="buscar" placeholder="Cédula, número o nombre" value="<?= e(get('buscar')) ?>" inputmode="search" required>
      <button class="btn btn-sec">Buscar</button>
    </form>
    <?php
    $encontrados = [];
    if (get('verificar') !== '' && preg_match('/^[0-9a-f]{20}$/', get('verificar'))) {
        $encontrados = filas('SELECT j.*, e.nombre AS equipo, e.escudo, e.color FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.carnet_token = ?', [get('verificar')]);
        if (!$encontrados) echo '<div class="aviso aviso-error" style="margin-top:10px">✗ CARNET NO VÁLIDO (no existe o fue anulado).</div>';
    } elseif (get('buscar') !== '') {
        $b = get('buscar');
        $encontrados = filas("SELECT j.*, e.nombre AS equipo, e.escudo, e.color FROM jugadores j JOIN equipos e ON e.id = j.equipo_id
                              WHERE j.equipo_id IN (?,?) AND (j.cedula = ? OR CAST(j.numero AS TEXT) = ? OR (j.nombres || ' ' || j.apellidos) LIKE ?)
                              ORDER BY j.equipo_id, j.numero LIMIT 10", [$p['local_id'], $p['visitante_id'], $b, $b, '%' . $b . '%']);
        if (!$encontrados) echo '<div class="aviso aviso-error" style="margin-top:10px">No se encontró ningún jugador de estos dos equipos con "' . e($b) . '".</div>';
    }
    $susp_v = $encontrados ? suspensiones((int)$p['torneo_id']) : [];
    foreach ($encontrados as $vj):
        $del_partido = in_array((int)$vj['equipo_id'], [(int)$p['local_id'], (int)$p['visitante_id']], true);
        $ya = (bool)valor('SELECT COUNT(*) FROM alineaciones WHERE partido_id = ? AND jugador_id = ?', [$pid, $vj['id']]);
        $es_susp = isset($susp_v[(int)$vj['id']]);
        [$color, $txt] = !$vj['activo'] ? ['#6b7572', '✗ DADO DE BAJA'] : (!$del_partido ? ['#d7263d', '✗ NO ES DE ESTE PARTIDO'] : ($es_susp ? ['#d7263d', '✗ SUSPENDIDO'] : ['#1a9e5c', '✓ HABILITADO']));
    ?>
      <div class="tarjeta verif-vocal" style="margin-top:12px;border-color:<?= $color ?>">
        <div class="estado-grande" style="background:<?= $color ?>;font-size:1.2rem"><?= $txt ?></div>
        <div class="carnet-foto"><?= foto_jugador($vj, 'foto-verif') ?></div>
        <h3 style="text-align:center;margin:.4rem 0 0"><?= $vj['numero'] !== null ? '#' . (int)$vj['numero'] . ' ' : '' ?><?= e(nombre_jugador($vj)) ?><?= $cat ? etiqueta_categoria(situacion_categoria($cat, $vj)) : '' ?></h3>
        <p style="text-align:center;margin:.2rem 0"><b><?= e($vj['equipo']) ?></b><?= $vj['cedula'] !== '' ? ' · C.I. ' . e($vj['cedula']) : '' ?><?= edad($vj['fecha_nac']) !== null ? ' · ' . edad($vj['fecha_nac']) . ' años' : '' ?></p>
        <?php if ($es_susp): ?><p class="suave" style="text-align:center"><?= e(implode('; ', $susp_v[(int)$vj['id']]['motivos'])) ?></p><?php endif; ?>
        <p class="suave" style="text-align:center;font-size:.85rem">Compare la foto con la persona y con su cédula física.</p>
        <?php if ($del_partido && $vj['activo'] && !$es_susp && !$cerrada): ?>
          <?php if ($ya): ?><p style="text-align:center"><span class="estado-chip chip-ok">Ya está marcado como presente</span></p>
          <?php else: ?>
          <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="presente_uno"><input type="hidden" name="jugador_id" value="<?= (int)$vj['id'] ?>">
            <button class="btn btn-grande" style="background:#1a9e5c">✓ Es él — marcar presente</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- CONTROL DEL PARTIDO -->
  <div class="tarjeta" id="control">
    <h2 style="margin-top:0">Control del partido</h2>
    <div id="control-botones">
    <?php if ($p['estado'] === 'programado' || $p['estado'] === 'postergado' || $p['estado'] === 'suspendido'): ?>
      <?php if ($n_pres > 0):
          foreach ([[$p['local_nombre'], $pres_l], [$p['visitante_nombre'], $pres_v]] as [$nom, $n]):
              if ($n < (int)$reglas['min_para_jugar']): ?>
        <div class="aviso aviso-error"><?= e($nom) ?> tiene solo <?= $n ?> jugadores presentes. El mínimo para empezar es <?= (int)$reglas['min_para_jugar'] ?>: si no completa, corresponde W.O. (lo registra el administrador).</div>
      <?php endif; endforeach; endif; ?>
      <form method="post">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="iniciar">
        <p><label>Hora de inicio</label><input type="time" name="hora_inicio" value="<?= date('H:i') ?>"></p>
        <button class="btn btn-grande" style="background:#1a9e5c" onclick="return confirm('¿Iniciar el partido ahora?')">▶ Iniciar partido</button>
      </form>
    <?php elseif ($p['estado'] === 'en_vivo' && $dep === 'ecuavoley'): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="finalizar">
          <button class="btn btn-grande btn-peligro" onclick="return confirm('¿Finalizar el partido?')">🏁 Finalizar partido</button></form>
    <?php elseif ($p['estado'] === 'en_vivo'): ?>
      <?php if (!$p['vivo_pausa']): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $ultimo_periodo ? 'finalizar' : 'fin_periodo' ?>">
          <?php if ($ultimo_periodo): ?><input type="hidden" name="hora_fin" value="">
            <button class="btn btn-grande btn-peligro" onclick="return confirm('¿Finalizar el partido?')">🏁 Finalizar partido</button>
          <?php else: ?>
            <button class="btn btn-grande" style="background:#b7791f" onclick="return confirm('¿Terminar el <?= e($T['periodo']) ?> <?= (int)$p['vivo_periodo'] ?>?')">⏸ Fin del <?= (int)$p['vivo_periodo'] ?>º <?= e($T['periodo']) ?></button>
          <?php endif; ?>
        </form>
      <?php else: ?>
        <?php if (!$ultimo_periodo): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="siguiente_periodo">
          <button class="btn btn-grande" style="background:#1a9e5c">▶ Iniciar <?= (int)$p['vivo_periodo'] + 1 ?>º <?= e($T['periodo']) ?></button></form>
        <?php endif; ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="finalizar">
          <button class="btn btn-grande btn-peligro" onclick="return confirm('¿Finalizar el partido?')">🏁 Finalizar partido</button></form>
      <?php endif; ?>
    <?php else: ?>
      <p><b>Partido terminado.</b></p>
    <?php endif; ?>
    </div>
    <details style="margin-top:8px">
      <summary>Corregir horas de inicio / fin</summary>
      <form method="post" style="margin-top:8px">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="horas">
        <div class="campos">
          <div><label>Hora de inicio</label><input type="time" name="hora_inicio" value="<?= e($p['hora_inicio']) ?>"></div>
          <div><label>Hora de fin</label><input type="time" name="hora_fin" value="<?= e($p['hora_fin']) ?>"></div>
        </div>
        <button class="btn btn-sec">Guardar horas</button>
      </form>
    </details>
    <details style="margin-top:8px" <?= $n_pres === 0 && $p['estado'] === 'programado' ? 'open' : '' ?>>
      <summary><b>Jugadores presentes</b> (<?= e($p['local_nombre']) ?>: <?= $pres_l ?> · <?= e($p['visitante_nombre']) ?>: <?= $pres_v ?> · máx. <?= $max_presentes ?>)</summary>
      <form method="post" class="lista-presentes" style="margin-top:8px">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="alineacion">
        <?php foreach ([[$p['local_nombre'], $jl], [$p['visitante_nombre'], $jv]] as [$nom, $lista]): ?>
          <h3><?= e($nom) ?></h3>
          <?php if (!$lista): ?><p class="suave">Sin jugadores inscritos.</p><?php endif; ?>
          <?php foreach ($lista as $j): $sit = $cat ? situacion_categoria($cat, $j) : ['estado' => 'libre']; $bloq = $j['suspendido'] || $sit['estado'] === 'fuera'; ?>
            <label style="<?= $bloq ? 'color:var(--roja)' : '' ?>">
              <input type="checkbox" name="presentes[]" value="<?= (int)$j['id'] ?>" <?= $j['presente'] ? 'checked' : '' ?> <?= $bloq ? 'disabled' : '' ?>>
              <?= foto_jugador($j) ?> <span><?= $j['numero'] !== null ? '<b>#' . (int)$j['numero'] . '</b> ' : '' ?><?= e(nombre_jugador($j)) ?><?= $j['suspendido'] ? ' — <b>SUSPENDIDO</b>' : '' ?><?= $cat ? etiqueta_categoria($sit) : '' ?></span>
            </label>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <button class="btn btn-grande" style="margin-top:10px">Guardar presentes</button>
      </form>
    </details>
  </div>

  <?php if ($dep === 'ecuavoley'): $sa = $set_abierto ?? ['num' => count($sets) + 1, 'puntos_local' => 0, 'puntos_visitante' => 0]; ?>
  <!-- ECUAVÓLEY: PUNTOS DEL SET -->
  <div class="tarjeta" id="sets">
    <h2 style="margin-top:0">🏐 Set <span id="set-num"><?= (int)$sa['num'] ?></span> <small class="suave">a <?= puntos_para_set($reglas, (int)$sa['num']) ?> puntos · gana quien llega a <?= (int)$reglas['sets_ganar'] ?> sets</small></h2>
    <div class="set-tablero">
      <?php foreach ([['l', $p['local_nombre'], 'puntos_local'], ['v', $p['visitante_nombre'], 'puntos_visitante']] as [$lado, $nom, $col]): ?>
      <div class="set-equipo">
        <div class="set-nombre"><?= e($nom) ?></div>
        <div class="set-puntos" id="pts-<?= $lado ?>"><?= (int)$sa[$col] ?></div>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="punto"><input type="hidden" name="lado" value="<?= $lado ?>"><input type="hidden" name="delta" value="1">
          <button class="btn btn-grande" style="background:#1a9e5c;font-size:1.6rem">+1</button></form>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="punto"><input type="hidden" name="lado" value="<?= $lado ?>"><input type="hidden" name="delta" value="-1">
          <button class="btn btn-sec btn-chico">−1 corregir</button></form>
      </div>
      <?php endforeach; ?>
    </div>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cerrar_set">
      <button class="btn btn-grande" style="background:#b7791f" onclick="return confirm('¿Cerrar este set? Se suma al que va ganando.')">✓ Cerrar set</button></form>
    <?php if ($sets): ?><p class="suave" style="margin-bottom:0">Sets: <?php foreach ($sets as $st_): ?><span class="etiqueta<?= (int)$st_['cerrado'] ? '' : ' amarilla' ?>"><?= (int)$st_['num'] ?>º <?= (int)$st_['puntos_local'] ?>-<?= (int)$st_['puntos_visitante'] ?></span> <?php endforeach; ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- REGISTRAR GOL / TARJETA / CANASTA -->
  <div class="tarjeta" id="registrar">
    <h2 style="margin-top:0"><?= e($T['registrar']) ?></h2>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="evento">
      <p><label>Jugador</label><select name="jugador_id" id="sel-jugador" required style="font-size:1.05rem;padding:12px"><?= opciones_jugadores_partido($p, $jl, $jv, false) ?></select></p>
      <div class="campos" style="grid-template-columns:<?= $T['con_reloj'] ? '1fr 2fr' : '1fr' ?>">
        <?php if ($T['con_reloj']): ?><div><label>Minuto</label><input type="number" name="minuto" id="minuto" min="0" max="<?= $max_minuto ?>" inputmode="numeric" oninput="this.dataset.manual=1"></div>
        <?php elseif ($dep === 'beisbol'): // (v36) béisbol y softbol: la entrada en que pasó ?><div><label>Entrada</label><input type="number" name="minuto" id="minuto" min="1" max="30" value="<?= max(1, (int)$p['vivo_periodo']) ?>" inputmode="numeric" oninput="this.dataset.manual=1"></div><?php endif; ?>
        <div><label>Nota (opcional)</label><input type="text" name="nota" maxlength="200" placeholder="Ej: <?= $dep === 'basquet' ? 'expulsión por agresión' : 'roja por agresión' ?>"></div>
      </div>
      <div class="botonera">
        <?php foreach (botones_evento($reglas) as [$tipo_b, $txt_b, $cls_b, $conf_b]): ?>
          <button class="<?= $cls_b ?>" name="tipo" value="<?= $tipo_b ?>"<?= $conf_b !== '' ? ' onclick="return confirm(' . e(json_encode($conf_b)) . ')"' : '' ?>><?= e($txt_b) ?></button>
        <?php endforeach; ?>
      </div>
      <?php if ($faltas && (int)$reglas['faltas_limite'] > 0): ?>
        <p class="suave" style="margin-bottom:0">Faltas personales: <?php foreach ($faltas as $jid_f => $nf): $jj = fila('SELECT nombres, apellidos, numero FROM jugadores WHERE id = ?', [$jid_f]); ?><span class="etiqueta<?= $nf >= (int)$reglas['faltas_limite'] ? ' roja' : '' ?>"><?= $jj && $jj['numero'] !== null ? '#' . (int)$jj['numero'] . ' ' : '' ?><?= e($jj ? $jj['apellidos'] : '') ?>: <?= $nf ?></span> <?php endforeach; ?></p>
      <?php endif; ?>
    </form>
  </div>

  <!-- CRONOLOGÍA -->
  <div class="tarjeta" id="cronologia">
    <h2 style="margin-top:0">Lo registrado</h2>
    <?php if (!$eventos): ?><p class="suave">Aún no hay registros.</p><?php endif; ?>
    <ul class="eventos">
      <?php foreach ($eventos as $x): ?>
        <li><span class="minuto"><?= $x['minuto'] !== null ? e(fmt_minuto_evento($x['minuto'], $dep)) : '—' ?></span><span><?= icono_evento($x['tipo'], $dep) ?></span>
          <span><?= e(nombre_jugador($x)) ?><?= $x['numero'] !== null ? ' #' . (int)$x['numero'] : '' ?><br><small><?= (int)$x['equipo_id'] === (int)$p['local_id'] ? e($p['local_nombre']) : e($p['visitante_nombre']) ?><?= $x['nota'] !== '' ? ' · ' . e($x['nota']) : '' ?></small></span>
          <form class="inline" method="post" style="margin-left:auto" onsubmit="return confirm('¿Borrar este registro?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_evento"><input type="hidden" name="evento_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- OBSERVACIONES -->
  <div class="tarjeta" id="obs">
    <h2 style="margin-top:0">Observaciones del vocal</h2>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="observaciones">
      <textarea name="vocal_obs" maxlength="3000" placeholder="Incidentes, retrasos, cambios, comportamiento de la barra..."><?= e($p['vocal_obs']) ?></textarea>
      <button class="btn btn-sec" style="margin-top:8px">Guardar observaciones</button>
    </form>
  </div>

  <!-- FOTOS -->
  <div class="tarjeta" id="fotos">
    <h2 style="margin-top:0">Fotos internas 🔒</h2>
    <p class="suave" style="font-size:.85rem">Solo las ve la liga (no el público). Ej: foto de la planilla física firmada.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="fotos">
      <p><input type="file" name="fotos[]" accept="image/*" multiple required></p>
      <p><input type="text" name="descripcion" maxlength="200" placeholder="Descripción (opcional)"></p>
      <button class="btn btn-sec">Subir fotos</button>
    </form>
    <?php if ($fotos): ?><div class="galeria" style="margin-top:10px"><?php foreach ($fotos as $f): ?><figure><a href="foto.php?id=<?= (int)$f['id'] ?>" target="_blank"><img src="foto.php?id=<?= (int)$f['id'] ?>" alt=""></a><figcaption><?= e($f['descripcion'] ?: '—') ?></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
  </div>

  <?php if ($reglas['calificar_arbitro'] !== 'no' && $terminado && $es_directivo): ?>
  <div class="tarjeta" id="calificar">
    <h2 style="margin-top:0">Calificar al árbitro <?= $reglas['calificar_arbitro'] === 'obligatorio' ? '<span class="estado-chip chip-mal">obligatorio</span>' : '<span class="estado-chip">opcional</span>' ?></h2>
    <p class="suave" style="font-size:.85rem">Uso interno de la liga: el árbitro no ve esta calificación.</p>
    <?= form_calificacion($p, $url . '#calificar') ?>
  </div>
  <?php endif; ?>

  <!-- CERRAR -->
  <div class="tarjeta">
    <a class="btn btn-sec btn-grande" href="<?= e($url) ?>&amp;imprimir=1">🖨 Ver / imprimir / PDF la hoja de vocalía</a>
    <?php if ($terminado): ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cerrar">
      <button class="btn btn-grande" onclick="return confirm('Al cerrar ya no podrá modificar la vocalía. ¿Está todo correcto?')">✓ Cerrar y enviar vocalía</button></form>
    <?php else: ?><p class="suave" style="text-align:center">Al terminar el partido podrá cerrar la vocalía.</p><?php endif; ?>
    <details style="margin-top:6px"><summary>¿Hay otro vocal? Agregarlo</summary>
      <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="registrar_vocal">
        <p><input type="text" name="nombre" required maxlength="80" placeholder="Nombre" list="nombres-previos"></p>
        <datalist id="nombres-previos"><?php foreach ($nombres_previos as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
        <p><select name="tipo"><option value="directivo">Directivo de la liga</option><option value="equipo">Delegado de un equipo</option></select></p>
        <p><select name="equipo_id"><option value="">Equipo (si es delegado)</option><?= opciones_equipos($equipos_liga) ?></select></p>
        <button class="btn btn-sec">Agregar vocal</button></form>
    </details>
  </div>
<?php endif; ?>
</main>
<div id="banner-cola" class="banner-cola" style="display:none"></div>
<div id="lector">
  <p><b>Apunte la cámara al código QR del carnet</b></p>
  <video id="video" playsinline muted></video>
  <canvas id="lienzo" style="display:none"></canvas>
  <p id="lector-msg"></p>
  <button type="button" class="btn btn-peligro" onclick="cerrarLector()">Cancelar</button>
</div>
<script>
/* ===== Datos del partido para trabajar sin internet ===== */
var VOCAL = {
  url: <?= json_encode($url) ?>, k: <?= json_encode($k) ?>, csrf: <?= json_encode(csrf_token()) ?>,
  jug: <?= json_encode((object)$mapa, JSON_UNESCAPED_UNICODE) ?>,
  local: { id: <?= (int)$p['local_id'] ?>, n: <?= json_encode($p['local_nombre'], JSON_UNESCAPED_UNICODE) ?> },
  visita: { id: <?= (int)$p['visitante_id'] ?>, n: <?= json_encode($p['visitante_nombre'], JSON_UNESCAPED_UNICODE) ?> },
  est: { estado: <?= json_encode($p['estado']) ?>, periodo: <?= (int)$p['vivo_periodo'] ?>, desde: <?= (int)$p['vivo_desde'] ?>, pausa: <?= (int)$p['vivo_pausa'] ?>,
         mt: <?= (int)$reglas['minutos_tiempo'] ?>, nt: <?= (int)$reglas['num_tiempos'] ?>, ahora: <?= time() ?> },
  cerrada: <?= $cerrada ? 'true' : 'false' ?>,
  dep: <?= json_encode($dep) ?>, periodo: <?= json_encode($T['periodo']) ?>
};
VOCAL.desfase = Date.now() / 1000 - VOCAL.est.ahora;
VOCAL.porToken = {};
Object.keys(VOCAL.jug).forEach(function (id) { var j = VOCAL.jug[id]; if (j.tk) VOCAL.porToken[j.tk] = id; });

/* Guarda esta vocalía en "Mis vocalías" (para abrirla desde la app instalada) */
try {
  var rec = JSON.parse(localStorage.getItem('vocalias_recientes') || '[]').filter(function (x) { return x.k !== VOCAL.k; });
  rec.unshift({ k: VOCAL.k, t: VOCAL.local.n + ' vs ' + VOCAL.visita.n, f: new Date().toISOString().slice(0, 10) });
  localStorage.setItem('vocalias_recientes', JSON.stringify(rec.slice(0, 12)));
} catch (e) {}

/* ===== Cola de acciones sin internet ===== */
var COLA = 'vocal_cola_' + VOCAL.k, enviando = false;
function leerCola() { try { return JSON.parse(localStorage.getItem(COLA) || '[]'); } catch (e) { return []; } }
function escribirCola(c) { try { localStorage.setItem(COLA, JSON.stringify(c)); } catch (e) { alert('El celular no tiene espacio para guardar. Libere espacio.'); } banner(); }
function uid() { return 'x' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10); }
function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
function banner() {
  var b = document.getElementById('banner-cola'), n = leerCola().length;
  if (!n && navigator.onLine) { b.style.display = 'none'; return; }
  b.style.display = 'block';
  b.className = 'banner-cola ' + (navigator.onLine ? 'enviando' : 'sin-red');
  b.textContent = navigator.onLine ? '⏫ Enviando ' + n + ' acción(es) guardada(s)…'
    : '📴 Sin internet' + (n ? ' · ' + n + ' acción(es) guardada(s) en este celular. Se enviarán solas cuando vuelva la señal.' : ' · puede seguir registrando: todo se guarda en el celular.');
}
function aviso(txt) {
  var a = document.createElement('div'); a.className = 'toast'; a.textContent = txt; document.body.appendChild(a);
  setTimeout(function () { a.remove(); }, 3500);
}
function enviarItem(item) {
  var fd = new FormData(); item.d.forEach(function (p) { fd.append(p[0], p[1]); });
  return fetch(VOCAL.url, { method: 'POST', body: fd, redirect: 'manual', credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
    if (r.type === 'opaqueredirect' || (r.status >= 200 && r.status < 500)) return true;
    throw new Error('servidor ' + r.status);
  });
}
function sincronizar() {
  if (enviando || !navigator.onLine) { banner(); return; }
  var c = leerCola();
  if (!c.length) { banner(); return; }
  enviando = true; banner();
  enviarItem(c[0]).then(function () {
    var c2 = leerCola(); c2.shift(); escribirCola(c2); enviando = false;
    if (c2.length) sincronizar(); else { aviso('✓ Todo enviado'); location.href = VOCAL.url; }
  }).catch(function () { enviando = false; banner(); });
}
function ancla(acc) { return { punto: '#sets', cerrar_set: '#sets', evento: '#registrar', borrar_evento: '#cronologia', alineacion: '#control', observaciones: '#obs', calificar: '#calificar', presente_uno: '#verificar' }[acc] || ''; }

/* ===== Cambios que se ven al instante (aunque no haya internet) ===== */
function aplicar(d, nuevo) {
  var g = function (k) { for (var i = 0; i < d.length; i++) if (d[i][0] === k) return d[i][1]; return ''; };
  var acc = g('accion'), e = VOCAL.est, ts = parseInt(g('ts'), 10) || Math.floor(Date.now() / 1000 - VOCAL.desfase);
  if (acc === 'evento') {
    var j = VOCAL.jug[g('jugador_id')], tipo = g('tipo');
    if (!j) return;
    var iconos = { gol: VOCAL.dep === 'balonmano' ? '🤾' : '⚽', penal: VOCAL.dep === 'balonmano' ? '🤾(7m)' : '⚽(p)', autogol: '⚽(a.g.)', amarilla: VOCAL.dep === 'basquet' ? 'T' : (VOCAL.dep === 'beisbol' ? '⚠' : '🟨'), roja: VOCAL.dep === 'basquet' || VOCAL.dep === 'beisbol' ? '⛔' : '🟥', p1: '🏀+1', p2: '🏀+2', p3: '🏀+3', falta: '✋', asistencia: '🅰',
      exclusion: '⏱2\'', carrera: '⚾', jonron: '💥HR', hit: '🏏', error: '❌E', ponche: 'K' };
    var valores = { gol: 1, penal: 1, p1: 1, p2: 2, p3: 3, carrera: 1, jonron: 1 };
    var li = document.createElement('li'); li.className = 'pendiente';
    li.innerHTML = '<span class="minuto">' + (g('minuto') !== '' ? esc(g('minuto')) + (VOCAL.dep === 'beisbol' ? '.ª' : "'") : '—') + '</span><span>' + iconos[tipo] + '</span><span>' + esc(j.n) + (j.num !== null ? ' #' + esc(j.num) : '') +
      '<br><small>' + esc(j.e === VOCAL.local.id ? VOCAL.local.n : VOCAL.visita.n) + ' · ⏳ guardado en el celular</small></span>';
    var ul = document.querySelector('ul.eventos'); if (ul) ul.insertBefore(li, ul.firstChild);
    var vacio = document.querySelector('#cronologia > p.suave'); if (vacio) vacio.style.display = 'none';
    if (valores[tipo] || tipo === 'autogol') {
      var m = document.querySelector('.marcador-grande .goles'), p = m.textContent.split('-').map(function (x) { return parseInt(x, 10) || 0; });
      var local = (j.e === VOCAL.local.id) !== (tipo === 'autogol');
      var suma = valores[tipo] || 1;
      if (local) p[0] += suma; else p[1] += suma;
      m.textContent = p[0] + ' - ' + p[1];
    }
    if (e.estado === 'programado') { e.estado = 'en_vivo'; e.periodo = 1; e.desde = ts; e.pausa = 0; controles(); }
  } else if (acc === 'presente_uno') {
    var cb = document.querySelector('.lista-presentes input[value="' + g('jugador_id') + '"]'); if (cb) cb.checked = true;
    if (VOCAL.jug[g('jugador_id')]) VOCAL.jug[g('jugador_id')].pres = true;
    var bt = document.querySelector('.verif-vocal form'); if (bt && nuevo) bt.outerHTML = '<p style="text-align:center"><span class="estado-chip chip-pend">✓ Presente (guardado en el celular)</span></p>';
  } else if (acc === 'punto') {
    var el = document.getElementById('pts-' + (g('lado') === 'v' ? 'v' : 'l'));
    if (el) el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + (g('delta') === '-1' ? -1 : 1));
    if (e.estado === 'programado') { e.estado = 'en_vivo'; e.periodo = 1; e.desde = ts; e.pausa = 0; controles(); }
  } else if (acc === 'cerrar_set') {
    var a = document.getElementById('pts-l'), b = document.getElementById('pts-v'), n = document.getElementById('set-num');
    if (a && b) {
      var pa = parseInt(a.textContent, 10) || 0, pb = parseInt(b.textContent, 10) || 0;
      if (pa !== pb) {
        var m2 = document.querySelector('.marcador-grande .goles'), s2 = m2.textContent.split('-').map(function (x) { return parseInt(x, 10) || 0; });
        if (pa > pb) s2[0]++; else s2[1]++;
        m2.textContent = s2[0] + ' - ' + s2[1];
        var mini = document.getElementById('sets-mini'); if (mini) mini.textContent = (mini.textContent ? mini.textContent + ', ' : '') + pa + '-' + pb;
        a.textContent = '0'; b.textContent = '0'; if (n) n.textContent = (parseInt(n.textContent, 10) || 1) + 1;
      }
    }
  } else if (acc === 'iniciar') { if (e.estado !== 'en_vivo') { e.estado = 'en_vivo'; e.periodo = 1; e.desde = ts; e.pausa = 0; controles(); } }
  else if (acc === 'fin_periodo') { e.pausa = 1; controles(); }
  else if (acc === 'siguiente_periodo') { if (e.pausa && e.periodo < e.nt) { e.periodo++; e.desde = ts; e.pausa = 0; } controles(); }
  else if (acc === 'finalizar') { e.estado = 'jugado'; e.pausa = 1; controles(); }
  else if (acc === 'borrar_evento') {
    var inp = document.querySelector('ul.eventos input[name=evento_id][value="' + g('evento_id') + '"]');
    if (inp) inp.closest('li').style.display = 'none';
  }
}
function formu(accion, texto, estilo, confirmar) {
  return '<form method="post"><input type="hidden" name="csrf" value="' + esc(VOCAL.csrf) + '"><input type="hidden" name="accion" value="' + accion + '">' +
    '<button class="btn btn-grande" style="' + estilo + '"' + (confirmar ? ' onclick="return confirm(\'' + confirmar + '\')"' : '') + '>' + texto + '</button></form>';
}
function controles() {
  var c = document.getElementById('control-botones'), e = VOCAL.est, h = '';
  if (!c) return;
  if (e.estado === 'en_vivo' && VOCAL.dep === 'ecuavoley') {
    h = formu('finalizar', '🏁 Finalizar partido', 'background:var(--roja)', '¿Finalizar el partido?');
  } else if (e.estado === 'en_vivo') {
    if (!e.pausa) h = e.periodo >= e.nt ? formu('finalizar', '🏁 Finalizar partido', 'background:var(--roja)', '¿Finalizar el partido?')
                                        : formu('fin_periodo', '⏸ Fin del ' + e.periodo + 'º ' + VOCAL.periodo, 'background:#b7791f', '¿Terminar el ' + VOCAL.periodo + ' ' + e.periodo + '?');
    else h = (e.periodo < e.nt ? formu('siguiente_periodo', '▶ Iniciar ' + (e.periodo + 1) + 'º ' + VOCAL.periodo, 'background:#1a9e5c', '') : '') + formu('finalizar', '🏁 Finalizar partido', 'background:var(--roja)', '¿Finalizar el partido?');
  } else if (e.estado === 'jugado' || e.estado === 'wo') h = '<p><b>Partido terminado.</b> Cuando vuelva el internet podrá cerrar la vocalía.</p>';
  else h = formu('iniciar', '▶ Iniciar partido', 'background:#1a9e5c', '¿Iniciar el partido ahora?');
  c.innerHTML = h;
  tick();
}

/* ===== Envío de formularios: con internet se envían; sin internet se guardan en el celular ===== */
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  if (ev.defaultPrevented) return;
  if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') {
    if (!navigator.onLine && f.querySelector('[name=buscar]')) { ev.preventDefault(); buscarLocal(f.querySelector('[name=buscar]').value); }
    return;
  }
  if (f.dataset.online === '1' || (f.enctype || '').indexOf('multipart') >= 0) {
    if (!navigator.onLine) { ev.preventDefault(); alert((f.enctype || '').indexOf('multipart') >= 0 ? 'Las fotos necesitan internet. Súbalas cuando vuelva la señal.' : 'Este paso necesita internet. Conéctese e intente de nuevo.'); }
    return;
  }
  ev.preventDefault();
  var fd = new FormData(f);
  if (ev.submitter && ev.submitter.name) fd.append(ev.submitter.name, ev.submitter.value);
  fd.append('cid', uid());
  fd.append('ts', String(Math.floor(Date.now() / 1000 - VOCAL.desfase)));
  var item = { d: [], t: Date.now() };
  fd.forEach(function (v, k) { if (typeof v === 'string') item.d.push([k, v]); });
  var acc = fd.get('accion');
  var guardarLocal = function () {
    var c = leerCola(); c.push(item); escribirCola(c); aplicar(item.d, true);
    if (acc === 'evento') { var mi = document.getElementById('minuto'); if (mi) delete mi.dataset.manual; var nt = f.querySelector('[name=nota]'); if (nt) nt.value = ''; }
    aviso('💾 Guardado en el celular. Se enviará cuando vuelva el internet.');
  };
  if (navigator.onLine && !leerCola().length) {
    f.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    enviarItem(item).then(function () { location.href = VOCAL.url + ancla(acc); })
      .catch(function () { f.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); guardarLocal(); });
  } else {
    guardarLocal();
    sincronizar();
  }
});

/* ===== Verificación de jugadores sin internet ===== */
function tarjetaJugador(id) {
  var j = VOCAL.jug[id];
  if (!j) return '';
  var color = '#1a9e5c', txt = '✓ HABILITADO';
  if (j.susp) { color = '#d7263d'; txt = '✗ SUSPENDIDO'; }
  else if (j.cat === 'fuera') { color = '#d7263d'; txt = '✗ FUERA DE EDAD'; }
  var h = '<div class="tarjeta verif-vocal" style="margin-top:12px;border-color:' + color + '"><div class="estado-grande" style="background:' + color + ';font-size:1.2rem">' + txt + '</div>' +
    '<div class="carnet-foto">' + (j.foto ? '<img class="foto-verif" src="' + esc(j.foto) + '" alt="">' : '<span class="foto-verif foto-vacia">?</span>') + '</div>' +
    '<h3 style="text-align:center;margin:.4rem 0 0">' + (j.num !== null ? '#' + esc(j.num) + ' ' : '') + esc(j.n) + (j.cat === 'pasado' ? ' <span class="etiqueta amarilla">PASADO</span>' : '') + '</h3>' +
    '<p style="text-align:center;margin:.2rem 0"><b>' + esc(j.e === VOCAL.local.id ? VOCAL.local.n : VOCAL.visita.n) + '</b>' + (j.ced ? ' · C.I. ' + esc(j.ced) : '') + (j.edad !== null ? ' · ' + esc(j.edad) + ' años' : '') + '</p>' +
    (j.cat_txt && j.cat !== 'ok' && j.cat !== 'libre' ? '<p class="suave" style="text-align:center">' + esc(j.cat_txt) + '</p>' : '') +
    '<p class="suave" style="text-align:center;font-size:.85rem">Compare la foto con la persona y con su cédula física.' + (navigator.onLine ? '' : ' (Datos guardados en el celular)') + '</p>';
  if (!j.susp && j.cat !== 'fuera' && !VOCAL.cerrada) {
    h += j.pres ? '<p style="text-align:center"><span class="estado-chip chip-ok">Ya está marcado como presente</span></p>'
      : '<form method="post"><input type="hidden" name="csrf" value="' + esc(VOCAL.csrf) + '"><input type="hidden" name="accion" value="presente_uno"><input type="hidden" name="jugador_id" value="' + id + '"><button class="btn btn-grande" style="background:#1a9e5c">✓ Es él — marcar presente</button></form>';
  }
  return h + '</div>';
}
function mostrarEnVerificar(html) {
  var z = document.getElementById('verif-local');
  if (!z) { z = document.createElement('div'); z.id = 'verif-local'; var v = document.getElementById('verificar'); if (!v) return; v.appendChild(z); }
  z.innerHTML = html;
  document.getElementById('verificar').scrollIntoView();
}
function buscarLocal(b) {
  b = (b || '').toLowerCase().trim();
  var ids = Object.keys(VOCAL.jug).filter(function (id) { var j = VOCAL.jug[id]; return j.ced === b || String(j.num) === b || j.n.toLowerCase().indexOf(b) >= 0; }).slice(0, 10);
  mostrarEnVerificar(ids.length ? ids.map(tarjetaJugador).join('') : '<div class="aviso aviso-error" style="margin-top:10px">No se encontró ningún jugador de estos dos equipos con "' + esc(b) + '".</div>');
}

/* ===== Lector de QR ===== */
var flujo = null, buscando = false, detector = null;
function tokenDe(texto) { var m = /[?&]c=([0-9a-f]{20})/.exec(texto || ''); return m ? m[1] : null; }
function irA(token) {
  cerrarLector();
  var id = VOCAL.porToken[token];
  if (id) { mostrarEnVerificar(tarjetaJugador(id)); return; }
  if (navigator.onLine) { location.href = VOCAL.url + '&verificar=' + token + '#verificar'; return; }
  mostrarEnVerificar('<div class="aviso aviso-error" style="margin-top:10px">✗ Ese carnet NO es de ninguno de los dos equipos de este partido (o fue anulado). Sin internet no se puede consultar más.</div>');
}
function cargarJsQR(cb) { if (window.jsQR) return cb(); var s = document.createElement('script'); s.src = 'assets/js/jsQR.js'; s.onload = cb; document.head.appendChild(s); }
function abrirLector() {
  var caja = document.getElementById('lector'), v = document.getElementById('video'), msg = document.getElementById('lector-msg');
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { alert('Este celular no permite usar la cámara aquí. Use la búsqueda por cédula.'); return; }
  caja.style.display = 'flex'; msg.textContent = 'Iniciando cámara...';
  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
    flujo = s; v.srcObject = s; v.play(); buscando = true; msg.textContent = '';
    if ('BarcodeDetector' in window) { try { detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; } }
    if (detector) { buscarNativo(); } else { cargarJsQR(buscarJs); }
  }).catch(function () { msg.textContent = 'No se pudo abrir la cámara. Dé permiso a la cámara o use la búsqueda por cédula.'; });
}
function buscarNativo() {
  if (!buscando) return;
  detector.detect(document.getElementById('video')).then(function (r) {
    var t = r.length ? tokenDe(r[0].rawValue) : null;
    if (t) irA(t); else if (r.length) document.getElementById('lector-msg').textContent = 'Ese QR no es un carnet de la liga.';
    setTimeout(buscarNativo, 250);
  }).catch(function () { detector = null; cargarJsQR(buscarJs); });
}
function buscarJs() {
  if (!buscando) return;
  var v = document.getElementById('video'), c = document.getElementById('lienzo');
  if (v.readyState === v.HAVE_ENOUGH_DATA) {
    c.width = v.videoWidth; c.height = v.videoHeight;
    var ctx = c.getContext('2d'); ctx.drawImage(v, 0, 0, c.width, c.height);
    var r = jsQR(ctx.getImageData(0, 0, c.width, c.height).data, c.width, c.height);
    if (r) { var t = tokenDe(r.data); if (t) return irA(t); document.getElementById('lector-msg').textContent = 'Ese QR no es un carnet de la liga.'; }
  }
  requestAnimationFrame(buscarJs);
}
function cerrarLector() {
  buscando = false;
  if (flujo) { flujo.getTracks().forEach(function (t) { t.stop(); }); flujo = null; }
  document.getElementById('lector').style.display = 'none';
}

/* ===== Reloj del partido (funciona sin internet) ===== */
function tick() {
  var r = document.getElementById('reloj'), e = VOCAL.est, input = document.getElementById('minuto');
  if (!r) return;
  if (e.estado === 'jugado' || e.estado === 'wo') { r.textContent = 'FINAL'; return; }
  if (e.estado !== 'en_vivo' || e.periodo < 1) return;
  if (VOCAL.dep === 'ecuavoley') { var sn = document.getElementById('set-num'); r.textContent = '● En juego · Set ' + (sn ? sn.textContent : ''); return; }
  if (VOCAL.dep === 'beisbol') { r.textContent = '● ' + (e.pausa ? 'Fin de la ' : '') + e.periodo + '.ª entrada'; if (input && !input.dataset.manual) input.value = e.periodo; return; }
  var ahora = Date.now() / 1000 - VOCAL.desfase, min, txt;
  if (e.pausa) { min = e.periodo * e.mt; txt = e.periodo >= e.nt ? 'Final del partido' : 'Pausa · fin del ' + e.periodo + 'º ' + VOCAL.periodo; }
  else {
    var seg = Math.max(0, ahora - e.desde), m = Math.floor(seg / 60) + 1, base = (e.periodo - 1) * e.mt;
    min = base + Math.min(m, e.mt + 30);
    txt = e.periodo + 'º ' + VOCAL.periodo + ' · ' + (m > e.mt ? (base + e.mt) + '+' + (m - e.mt) : (base + m)) + "' (" + Math.floor(seg / 60) + ':' + ('0' + Math.floor(seg % 60)).slice(-2) + ')';
  }
  r.textContent = '● ' + txt;
  if (input && !input.dataset.manual) input.value = min;
}

/* ===== Arranque ===== */
leerCola().forEach(function (it) { aplicar(it.d, false); });
setInterval(tick, 1000); tick();
window.addEventListener('online', function () { banner(); sincronizar(); });
window.addEventListener('offline', banner);
setInterval(sincronizar, 15000);
banner(); sincronizar();
// Con internet, guarda en el celular las fotos de los jugadores (para verificar aunque se caiga la señal)
if (navigator.onLine && 'serviceWorker' in navigator) {
  setTimeout(function () { Object.keys(VOCAL.jug).forEach(function (id) { var f = VOCAL.jug[id].foto; if (f) fetch(f).catch(function () {}); }); }, 2500);
}
</script>
</body>
</html>
