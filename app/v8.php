<?php
/*
 * VERSIÓN 8 — Funciones nuevas:
 *   1) Llaves eliminatorias automáticas (octavos → cuartos → semifinal → final, con tercer lugar opcional)
 *   2) Pagos con comprobante enviados por el delegado
 *   3) Liquidación de árbitros (honorarios por partido)
 *   4) Estadísticas extra (asistencias, vallas invictas, arqueros, rachas, más partidos)
 *   5) Calendario para el celular (.ics)
 *   6) Portal del jugador (carnet digital en el celular)
 *   7) Reserva de canchas
 */

/* ============================================================
 * 1) LLAVES ELIMINATORIAS
 * ============================================================ */

/** Fase eliminatoria según cuántos equipos entran a la llave. */
function fase_por_equipos(int $n): string
{
    return match ($n) { 2 => 'final', 4 => 'semifinal', 8 => 'cuartos', default => 'octavos' };
}

function siguiente_fase(string $f): ?string
{
    return ['octavos' => 'cuartos', 'cuartos' => 'semifinal', 'semifinal' => 'final'][$f] ?? null;
}

/** Orden de siembra para que el 1 y el 2 solo se crucen en la final: n=8 → [1,8,4,5,2,7,3,6]. */
function orden_siembra(int $n): array
{
    $o = [1, 2];
    while (count($o) < $n) {
        $m = count($o) * 2 + 1;
        $nuevo = [];
        foreach ($o as $s) { $nuevo[] = $s; $nuevo[] = $m - $s; }
        $o = $nuevo;
    }
    return $o;
}

/** ¿El campeonato ya tiene llaves automáticas? */
function tiene_llaves(int $tid): bool
{
    return (int)valor('SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND llave_pos IS NOT NULL', [$tid]) > 0;
}

/**
 * Crea la primera ronda de la llave con los clasificados de la tabla.
 * $por_grupo: cuántos clasifican de cada grupo (o de la tabla única). El total debe ser 2, 4, 8 o 16.
 */
function crear_llaves(int $tid, int $por_grupo, bool $tercer, string $fecha = '', string $hora = '', string $cancha = ''): int
{
    if (tiene_llaves($tid)) throw new RuntimeException('Este campeonato ya tiene llaves. Si quiere hacerlas de nuevo, primero bórrelas.');
    $tabla = tabla_posiciones($tid);
    if (!$tabla) throw new RuntimeException('El campeonato no tiene equipos en la tabla.');
    $grupos = array_keys($tabla);
    $total = $por_grupo * count($grupos);
    if (!in_array($total, [2, 4, 8, 16], true)) {
        throw new RuntimeException('Clasifican ' . $total . ' equipos: para una llave deben ser 2, 4, 8 o 16. Cambie cuántos clasifican por grupo.');
    }
    foreach ($tabla as $g => $filas_g) {
        if (count($filas_g) < $por_grupo) throw new RuntimeException('El grupo ' . ($g !== '' ? $g : 'único') . ' no tiene ' . $por_grupo . ' equipos.');
    }
    // Siembra: primero todos los 1º, luego los 2º (rotando los grupos), etc.
    $siembra = [];
    for ($pos = 0; $pos < $por_grupo; $pos++) {
        $lista = [];
        foreach ($grupos as $gi => $g) $lista[] = ['id' => (int)$tabla[$g][$pos]['id'], 'grupo' => $g, 'pts' => $tabla[$g][$pos]['pts'], 'dg' => $tabla[$g][$pos]['dg']];
        if (count($grupos) === 1) { foreach ($lista as $x) $siembra[] = $x; continue; }
        usort($lista, fn($a, $b) => [$b['pts'], $b['dg']] <=> [$a['pts'], $a['dg']]);
        if ($pos % 2 === 1) $lista = array_reverse($lista);
        foreach ($lista as $x) $siembra[] = $x;
    }
    if (count($grupos) === 1) {
        // Tabla única: el orden de la tabla es la siembra
        $siembra = array_map(fn($f) => ['id' => (int)$f['id'], 'grupo' => ''], array_slice($tabla[$grupos[0]], 0, $total));
    }
    $orden = orden_siembra($total);
    $cruces = [];
    for ($i = 0; $i < $total; $i += 2) $cruces[] = [$siembra[$orden[$i] - 1], $siembra[$orden[$i + 1] - 1]];
    // Evita que dos equipos del mismo grupo se crucen en la primera ronda
    for ($i = 0; $i < count($cruces); $i++) {
        if ($cruces[$i][0]['grupo'] === '' || $cruces[$i][0]['grupo'] !== $cruces[$i][1]['grupo']) continue;
        for ($j = 0; $j < count($cruces); $j++) {
            if ($j === $i) continue;
            if ($cruces[$j][1]['grupo'] !== $cruces[$i][0]['grupo'] && $cruces[$i][1]['grupo'] !== $cruces[$j][0]['grupo']) {
                [$cruces[$i][1], $cruces[$j][1]] = [$cruces[$j][1], $cruces[$i][1]];
                break;
            }
        }
    }
    $fase = fase_por_equipos($total);
    $pdo = db();
    $pdo->beginTransaction();
    foreach ($cruces as $k => [$a, $b]) {
        q('INSERT INTO partidos (torneo_id, fase, grupo, jornada, local_id, visitante_id, fecha, hora, cancha, llave_pos) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$tid, $fase, '', 0, $a['id'], $b['id'], $fecha, $hora, $cancha, $k + 1]);
    }
    q('UPDATE torneos SET llaves_tercer = ? WHERE id = ?', [$tercer ? 1 : 0, $tid]);
    $pdo->commit();
    return count($cruces);
}

/** Borra las llaves que aún no se jugaron. */
function borrar_llaves(int $tid): int
{
    if ((int)valor("SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND llave_pos IS NOT NULL AND estado IN ('jugado','wo','en_vivo')", [$tid]) > 0) {
        throw new RuntimeException('No se pueden borrar: ya hay partidos de la llave jugados.');
    }
    $n = (int)valor('SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND llave_pos IS NOT NULL', [$tid]);
    foreach (filas('SELECT id FROM partidos WHERE torneo_id = ? AND llave_pos IS NOT NULL', [$tid]) as $x) eliminar_partido((int)$x['id']);
    return $n;
}

/**
 * Hace avanzar la llave: cuando se juegan los dos partidos de un cruce, crea el partido de la ronda siguiente
 * (y el tercer lugar con los perdedores de las semifinales). Si se corrige un resultado, corrige al clasificado.
 * Se llama sola cada vez que se cierra un partido. Devuelve cuántos partidos creó.
 */
function avanzar_llaves(int $tid): int
{
    if (!tiene_llaves($tid)) return 0;
    $t = reglas_torneo($tid, true);
    $creados = 0;
    foreach (['octavos', 'cuartos', 'semifinal'] as $fase) {
        $ps = [];
        foreach (filas('SELECT * FROM partidos WHERE torneo_id = ? AND fase = ? AND llave_pos IS NOT NULL ORDER BY llave_pos', [$tid, $fase]) as $p) $ps[(int)$p['llave_pos']] = $p;
        if (!$ps) continue;
        $sig = siguiente_fase($fase);
        for ($pos = 1; $pos <= max(array_keys($ps)); $pos += 2) {
            $a = $ps[$pos] ?? null;
            $b = $ps[$pos + 1] ?? null;
            if (!$a || !$b) continue;
            $ga = ganador_partido($a);
            $gb = ganador_partido($b);
            if (!$ga || !$gb) continue;
            $nueva = intdiv($pos + 1, 2);
            $existe = fila('SELECT * FROM partidos WHERE torneo_id = ? AND fase = ? AND llave_pos = ?', [$tid, $sig, $nueva]);
            if (!$existe) {
                q('INSERT INTO partidos (torneo_id, fase, grupo, jornada, local_id, visitante_id, llave_pos) VALUES (?,?,?,?,?,?,?)', [$tid, $sig, '', 0, $ga[0], $gb[0], $nueva]);
                $creados++;
            } elseif ($existe['estado'] === 'programado' && ((int)$existe['local_id'] !== $ga[0] || (int)$existe['visitante_id'] !== $gb[0])) {
                q('UPDATE partidos SET local_id = ?, visitante_id = ? WHERE id = ?', [$ga[0], $gb[0], $existe['id']]);
            }
            if ($fase === 'semifinal' && (int)$t['llaves_tercer'] === 1) {
                $ter = fila("SELECT * FROM partidos WHERE torneo_id = ? AND fase = 'tercer' AND llave_pos = 1", [$tid]);
                if (!$ter) {
                    q("INSERT INTO partidos (torneo_id, fase, grupo, jornada, local_id, visitante_id, llave_pos) VALUES (?, 'tercer', '', 0, ?, ?, 1)", [$tid, $ga[1], $gb[1]]);
                    $creados++;
                } elseif ($ter['estado'] === 'programado' && ((int)$ter['local_id'] !== $ga[1] || (int)$ter['visitante_id'] !== $gb[1])) {
                    q('UPDATE partidos SET local_id = ?, visitante_id = ? WHERE id = ?', [$ga[1], $gb[1], $ter['id']]);
                }
            }
        }
    }
    return $creados;
}

/** Llave para mostrar: [fase => [pos => partido completo]] en orden de rondas. */
function llave_torneo(int $tid): array
{
    $out = [];
    foreach (['octavos', 'cuartos', 'semifinal', 'final', 'tercer'] as $f) {
        $ps = partidos_torneo($tid, "AND p.fase = '$f' AND p.llave_pos IS NOT NULL");
        if ($ps) {
            usort($ps, fn($a, $b) => (int)$a['llave_pos'] <=> (int)$b['llave_pos']);
            $out[$f] = $ps;
        }
    }
    return $out;
}

/** Llave en HTML (página pública y panel). */
function html_llave(int $tid, string $enlace_base = ''): string
{
    $ll = llave_torneo($tid);
    if (!$ll) return '';
    $tercer = $ll['tercer'] ?? null;
    unset($ll['tercer']);
    $h = '<div class="llave">';
    foreach ($ll as $fase => $ps) {
        $h .= '<div class="llave-col"><h3>' . e(nombre_fase($fase)) . '</h3>';
        foreach ($ps as $p) {
            $g = ganador_partido($p);
            $cerrado = partido_cerrado($p);
            $fila = function (int $eid, string $nom, ?string $esc, string $col, $goles, $pen) use ($g, $cerrado) {
                $gana = $g && $g[0] === $eid;
                return '<div class="llave-eq' . ($gana ? ' gana' : ($cerrado ? ' pierde' : '')) . '">' . escudo($esc, $nom, $col) . '<span>' . e($nom) . '</span><b>' . ($cerrado ? (int)$goles . ($pen !== null ? '<small>(' . (int)$pen . ')</small>' : '') : '') . '</b></div>';
            };
            $cuerpo = $fila((int)$p['local_id'], $p['local_nombre'], $p['local_escudo'], $p['local_color'], $p['goles_local'], $p['penales_local'])
                . $fila((int)$p['visitante_id'], $p['visitante_nombre'], $p['visitante_escudo'], $p['visitante_color'], $p['goles_visitante'], $p['penales_visitante'])
                . (!$cerrado ? '<small class="llave-info">' . ($p['estado'] === 'en_vivo' ? '● EN VIVO' : e(trim(($p['fecha'] ? fecha_bonita($p['fecha']) : 'Por definir') . ' ' . $p['hora']))) . '</small>' : '');
            $h .= $enlace_base !== '' ? '<a class="llave-partido" href="' . e($enlace_base . (int)$p['id']) . '">' . $cuerpo . '</a>' : '<div class="llave-partido">' . $cuerpo . '</div>';
        }
        $h .= '</div>';
    }
    $h .= '</div>';
    if ($tercer) {
        $p = $tercer[0];
        $h .= '<h3>Tercer lugar</h3>' . tarjeta_partido($p, $enlace_base !== '' ? $enlace_base . (int)$p['id'] : '');
    }
    return $h;
}

/* ============================================================
 * 2) COMPROBANTES DE PAGO (el delegado sube la foto de su transferencia)
 * ============================================================ */

/** Guarda la foto del comprobante fuera de la web (data/comprobantes). Devuelve la ruta relativa a data/. */
function subir_comprobante(string $campo): string
{
    $f = $_FILES[$campo] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Tome o elija la foto del comprobante (captura de la transferencia o del depósito).');
    $sub = 'comprobantes/' . date('Ym');
    return $sub . '/' . guardar_imagen($f, RAIZ . '/data/' . $sub, 1600);
}

function comprobantes_pendientes(int $liga_id): int
{
    return (int)valor("SELECT COUNT(*) FROM comprobantes WHERE liga_id = ? AND estado = 'pendiente'", [$liga_id]);
}

/** Aprueba un comprobante: registra el pago con su recibo. Devuelve el id del movimiento. */
function aprobar_comprobante(array $c, float $monto, string $concepto, string $por): int
{
    if ($c['estado'] !== 'pendiente') throw new RuntimeException('Ese comprobante ya fue revisado.');
    $eq = fila('SELECT nombre FROM equipos WHERE id = ?', [$c['equipo_id']]);
    $mid = registrar_pago((int)$c['liga_id'], $c['torneo_id'] !== null ? (int)$c['torneo_id'] : null, (int)$c['equipo_id'], $monto,
        array_key_exists($concepto, conceptos_cargo()) ? $concepto : 'otro', 'Pago enviado en línea por el delegado' . ($c['nota'] !== '' ? ': ' . $c['nota'] : ''),
        $c['forma_pago'], 'Comprobante #' . (int)$c['id'] . ($c['referencia'] !== '' ? ' · ' . $c['referencia'] : ''), $c['fecha'] !== '' ? $c['fecha'] : date('Y-m-d'), $por,
        'comp:' . (int)$c['id']);
    q("UPDATE comprobantes SET estado = 'aprobado', monto = ?, movimiento_id = ?, revisado_por = ?, revisado_en = ? WHERE id = ?", [round($monto, 2), $mid, $por, date('Y-m-d H:i'), $c['id']]);
    return $mid;
}

/* ============================================================
 * 3) LIQUIDACIÓN DE ÁRBITROS
 * ============================================================ */

/** Honorario de una función en un campeonato. */
function honorario_rol(array $t, string $campo): float
{
    return (float)match ($campo) {
        'arbitro_id' => $t['pago_central'],
        'asistente1_id', 'asistente2_id' => $t['pago_asistente'],
        'cuarto_id' => $t['pago_cuarto'],
        default => 0,
    };
}

/**
 * Partidos jugados que se le deben a los árbitros (sin pagar todavía).
 * Devuelve [arbitro_id => ['arbitro' => fila, 'partidos' => [[partido, campo, rol, monto], ...], 'total' => float]].
 */
function honorarios_pendientes(int $liga_id, ?int $solo_arbitro = null): array
{
    $pagados = [];
    foreach (filas('SELECT pap.partido_id, pap.arbitro_id FROM pagos_arbitro_partidos pap JOIN pagos_arbitro pa ON pa.id = pap.pago_id WHERE pa.liga_id = ? AND pa.anulado = 0', [$liga_id]) as $x) {
        $pagados[$x['partido_id'] . ':' . $x['arbitro_id']] = true;
    }
    $arbitros = [];
    foreach (filas('SELECT * FROM arbitros WHERE liga_id = ?', [$liga_id]) as $a) $arbitros[(int)$a['id']] = $a;
    $out = [];
    $ps = filas("SELECT p.*, el.nombre AS local_nombre, ev.nombre AS visitante_nombre, t.nombre AS torneo FROM partidos p JOIN torneos t ON t.id = p.torneo_id
                 JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                 WHERE t.liga_id = ? AND p.estado IN ('jugado','wo') ORDER BY p.fecha, p.id", [$liga_id]);
    foreach ($ps as $p) {
        $t = reglas_torneo((int)$p['torneo_id']);
        foreach (roles_arbitro(num_arbitros_partido($p)) as $campo => $etq) {
            $aid = (int)($p[$campo] ?? 0);
            if (!$aid || !isset($arbitros[$aid]) || isset($pagados[$p['id'] . ':' . $aid])) continue;
            if ($solo_arbitro !== null && $aid !== $solo_arbitro) continue;
            $m = honorario_rol($t, $campo);
            if (!isset($out[$aid])) $out[$aid] = ['arbitro' => $arbitros[$aid], 'partidos' => [], 'total' => 0.0];
            $out[$aid]['partidos'][] = ['partido' => $p, 'campo' => $campo, 'rol' => $etq, 'monto' => $m];
            $out[$aid]['total'] += $m;
        }
    }
    return $out;
}

/** Paga al árbitro los partidos elegidos: guarda el pago y un gasto en Finanzas. Devuelve el id del pago. */
function pagar_arbitro(int $liga_id, int $arbitro_id, array $partido_ids, string $forma, string $fecha, string $nota, string $por): int
{
    $pend = honorarios_pendientes($liga_id, $arbitro_id)[$arbitro_id] ?? null;
    if (!$pend) throw new RuntimeException('Ese árbitro no tiene partidos pendientes de pago.');
    $ids = array_map('intval', $partido_ids);
    $elegidos = array_values(array_filter($pend['partidos'], fn($x) => in_array((int)$x['partido']['id'], $ids, true)));
    if (!$elegidos) throw new RuntimeException('Marque los partidos que va a pagar.');
    $total = round(array_sum(array_column($elegidos, 'monto')), 2);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $mid = null;
        if ($total > 0) {
            q("INSERT INTO movimientos (liga_id, tipo, concepto, descripcion, monto, fecha, forma_pago, registrado_por) VALUES (?, 'egreso', 'arbitros', ?, ?, ?, ?, ?)",
                [$liga_id, mb_substr('Honorarios de ' . $pend['arbitro']['nombre'] . ' (' . count($elegidos) . ' partidos)', 0, 200), $total, $fecha, $forma, $por]);
            $mid = (int)$pdo->lastInsertId();
        }
        q('INSERT INTO pagos_arbitro (liga_id, arbitro_id, monto, partidos, fecha, forma_pago, nota, movimiento_id, registrado_por) VALUES (?,?,?,?,?,?,?,?,?)',
            [$liga_id, $arbitro_id, $total, count($elegidos), $fecha, $forma, mb_substr($nota, 0, 200), $mid, $por]);
        $pid = (int)$pdo->lastInsertId();
        foreach ($elegidos as $x) {
            q('INSERT INTO pagos_arbitro_partidos (pago_id, partido_id, arbitro_id, rol, monto) VALUES (?,?,?,?,?)', [$pid, $x['partido']['id'], $arbitro_id, $x['rol'], $x['monto']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $pid;
}

/* ============================================================
 * 4) ESTADÍSTICAS EXTRA
 * ============================================================ */

function asistidores(int $tid, int $limite = 20): array
{
    return filas("SELECT j.id, j.nombres, j.apellidos, j.foto, j.foto_publica, j.numero, e.nombre AS equipo, e.escudo, e.color, COUNT(*) AS asistencias
                  FROM eventos ev JOIN partidos p ON p.id = ev.partido_id JOIN jugadores j ON j.id = ev.jugador_id JOIN equipos e ON e.id = ev.equipo_id
                  WHERE p.torneo_id = ? AND p.estado = 'jugado' AND ev.tipo = 'asistencia'
                  GROUP BY j.id ORDER BY asistencias DESC, j.apellidos LIMIT " . (int)$limite, [$tid]);
}

/** Partidos cerrados de un campeonato en orden. */
function partidos_cerrados(int $tid): array
{
    $ps = filas("SELECT * FROM partidos WHERE torneo_id = ? AND estado IN ('jugado','wo') AND goles_local IS NOT NULL", [$tid]);
    usort($ps, fn($a, $b) => strcmp(clave_orden_partido($a), clave_orden_partido($b)));
    return $ps;
}

/** Vallas invictas por equipo (partidos sin recibir goles). */
function vallas_invictas(int $tid): array
{
    $n = [];
    foreach (partidos_cerrados($tid) as $p) {
        if ((int)$p['goles_visitante'] === 0) $n[(int)$p['local_id']] = ($n[(int)$p['local_id']] ?? 0) + 1;
        if ((int)$p['goles_local'] === 0) $n[(int)$p['visitante_id']] = ($n[(int)$p['visitante_id']] ?? 0) + 1;
    }
    arsort($n);
    $out = [];
    foreach ($n as $eid => $c) {
        $e = fila('SELECT id, nombre, escudo, color FROM equipos WHERE id = ?', [$eid]);
        if ($e) $out[] = $e + ['vallas' => $c];
    }
    return $out;
}

/** Arqueros (posición que empieza con "arq" o "port"): partidos, goles recibidos estando presentes y vallas invictas. */
function arqueros(int $tid): array
{
    $cerrados = [];
    foreach (partidos_cerrados($tid) as $p) $cerrados[(int)$p['id']] = $p;
    if (!$cerrados) return [];
    $rows = filas("SELECT a.partido_id, a.equipo_id, j.id, j.nombres, j.apellidos, j.foto, j.foto_publica, e.nombre AS equipo
                   FROM alineaciones a JOIN jugadores j ON j.id = a.jugador_id JOIN equipos e ON e.id = a.equipo_id JOIN partidos p ON p.id = a.partido_id
                   WHERE p.torneo_id = ? AND (lower(j.posicion) LIKE 'arq%' OR lower(j.posicion) LIKE 'port%')", [$tid]);
    $out = [];
    foreach ($rows as $r) {
        $p = $cerrados[(int)$r['partido_id']] ?? null;
        if (!$p) continue;
        $recibidos = (int)$r['equipo_id'] === (int)$p['local_id'] ? (int)$p['goles_visitante'] : (int)$p['goles_local'];
        $k = (int)$r['id'];
        $out[$k] = ($out[$k] ?? ['id' => $k, 'nombres' => $r['nombres'], 'apellidos' => $r['apellidos'], 'foto' => $r['foto'], 'foto_publica' => $r['foto_publica'], 'equipo' => $r['equipo'], 'pj' => 0, 'recibidos' => 0, 'vallas' => 0]);
        $out[$k]['pj']++;
        $out[$k]['recibidos'] += $recibidos;
        if ($recibidos === 0) $out[$k]['vallas']++;
    }
    $out = array_values($out);
    usort($out, fn($a, $b) => [$a['recibidos'] / max(1, $a['pj']), -$a['pj']] <=> [$b['recibidos'] / max(1, $b['pj']), -$b['pj']]);
    return $out;
}

/** Rachas por equipo: racha actual (G/E/P seguidos), invicto actual y mejor racha de victorias. */
function rachas(int $tid): array
{
    $res = [];
    foreach (partidos_cerrados($tid) as $p) {
        foreach ([[(int)$p['local_id'], (int)$p['goles_local'], (int)$p['goles_visitante']], [(int)$p['visitante_id'], (int)$p['goles_visitante'], (int)$p['goles_local']]] as [$eid, $gf, $gc]) {
            $res[$eid][] = $gf > $gc ? 'G' : ($gf === $gc ? 'E' : 'P');
        }
    }
    $out = [];
    foreach ($res as $eid => $l) {
        $ult = end($l);
        $actual = 0;
        for ($i = count($l) - 1; $i >= 0 && $l[$i] === $ult; $i--) $actual++;
        $invicto = 0;
        for ($i = count($l) - 1; $i >= 0 && $l[$i] !== 'P'; $i--) $invicto++;
        $mejor = 0; $c = 0;
        foreach ($l as $r) { $c = $r === 'G' ? $c + 1 : 0; $mejor = max($mejor, $c); }
        $e = fila('SELECT id, nombre, escudo, color FROM equipos WHERE id = ?', [$eid]);
        if ($e) $out[] = $e + ['actual' => $actual, 'tipo' => $ult, 'invicto' => $invicto, 'mejor' => $mejor, 'ultimos' => array_slice($l, -5)];
    }
    usort($out, fn($a, $b) => [$b['invicto'], $b['mejor']] <=> [$a['invicto'], $a['mejor']]);
    return $out;
}

/** Jugadores con más partidos jugados (presentes en la vocalía). */
function mas_partidos(int $tid, int $limite = 15): array
{
    return filas("SELECT j.id, j.nombres, j.apellidos, j.foto, j.foto_publica, e.nombre AS equipo, COUNT(*) AS pj
                  FROM alineaciones a JOIN partidos p ON p.id = a.partido_id JOIN jugadores j ON j.id = a.jugador_id JOIN equipos e ON e.id = a.equipo_id
                  WHERE p.torneo_id = ? AND p.estado IN ('jugado','wo') GROUP BY j.id ORDER BY pj DESC, j.apellidos LIMIT " . (int)$limite, [$tid]);
}

/** Números generales del campeonato. */
function numeros_campeonato(int $tid): array
{
    $ps = partidos_cerrados($tid);
    $total = 0;
    $mayor = null;
    $goleada = null;
    foreach ($ps as $p) {
        $g = (int)$p['goles_local'] + (int)$p['goles_visitante'];
        $total += $g;
        if (!$mayor || $g > (int)$mayor['goles_local'] + (int)$mayor['goles_visitante']) $mayor = $p;
        $d = abs((int)$p['goles_local'] - (int)$p['goles_visitante']);
        if (!$goleada || $d > abs((int)$goleada['goles_local'] - (int)$goleada['goles_visitante'])) $goleada = $p;
    }
    $prog = (int)valor("SELECT COUNT(*) FROM partidos WHERE torneo_id = ?", [$tid]);
    return ['jugados' => count($ps), 'total_partidos' => $prog, 'goles' => $total, 'promedio' => count($ps) ? round($total / count($ps), 2) : 0,
        'mayor' => $mayor ? partido_completo((int)$mayor['id']) : null, 'goleada' => $goleada && abs((int)$goleada['goles_local'] - (int)$goleada['goles_visitante']) > 0 ? partido_completo((int)$goleada['id']) : null,
        'amarillas' => (int)valor("SELECT COUNT(*) FROM eventos ev JOIN partidos p ON p.id = ev.partido_id WHERE p.torneo_id = ? AND ev.tipo = 'amarilla'", [$tid]),
        'rojas' => (int)valor("SELECT COUNT(*) FROM eventos ev JOIN partidos p ON p.id = ev.partido_id WHERE p.torneo_id = ? AND ev.tipo = 'roja'", [$tid])];
}

/* ============================================================
 * 5) CALENDARIO (.ics) — se agrega al calendario del celular y se actualiza solo
 * ============================================================ */

function ics_texto(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
}

/** Enlace para suscribirse (webcal://) al calendario de un equipo o campeonato. */
function enlace_calendario(array $liga, string $que, int $id): string
{
    $url = url_base() . 'calendario.php?l=' . rawurlencode($liga['slug']) . '&' . $que . '=' . $id;
    return preg_replace('#^https?://#', 'webcal://', $url);
}

/* ============================================================
 * 6) PORTAL DEL JUGADOR
 * ============================================================ */

/** Código de acceso para que el jugador cree su clave (se envía por WhatsApp). */
function codigo_portal(array $j): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
    q('UPDATE jugadores SET portal_codigo = ? WHERE id = ?', [$c, $j['id']]);
    return $c;
}

function url_portal_jugador(string $codigo = ''): string
{
    return url_base() . 'mi.php' . ($codigo !== '' ? '?c=' . $codigo : '');
}

/** Mensaje de WhatsApp con el acceso del jugador. */
function mensaje_portal(array $j, string $codigo, array $liga): string
{
    return '¡Hola ' . trim((string)$j['nombres']) . '! Ya puedes ver tu carnet digital, tus partidos y tus estadísticas de ' . $liga['nombre'] . " en tu celular.\n\n"
        . '1) Abre: ' . url_portal_jugador($codigo) . "\n2) Crea tu clave de 4 a 6 números.\n3) Después entras con tu cédula y esa clave en " . url_portal_jugador();
}

/* ============================================================
 * 7) RESERVA DE CANCHAS
 * ============================================================ */

function minutos(string $h): int
{
    [$a, $b] = array_map('intval', explode(':', $h . ':0'));
    return $a * 60 + $b;
}

function hhmm(int $m): string
{
    return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60);
}

/** Turnos de una cancha en un día: [['ini','fin','libre'=>bool,'motivo'=>'']...]. */
function turnos_cancha(array $c, string $fecha): array
{
    $dur = max(30, (int)$c['duracion']);
    $ab = minutos($c['abre'] ?: '08:00');
    $ci = minutos($c['cierra'] ?: '22:00');
    if ($ci <= $ab) $ci = 24 * 60;
    $ocupado = [];
    foreach (filas("SELECT hora_inicio, hora_fin, cliente_nombre, estado FROM reservas WHERE cancha_id = ? AND fecha = ? AND estado <> 'cancelada'", [$c['id'], $fecha]) as $r) {
        $ocupado[] = [minutos($r['hora_inicio']), minutos($r['hora_fin']), 'Reservada'];
    }
    // Los partidos programados en esa cancha también la ocupan
    foreach (filas("SELECT p.hora, t.* FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.fecha = ? AND p.hora <> '' AND lower(p.cancha) = lower(?) AND p.estado <> 'suspendido'",
        [$c['liga_id'], $fecha, $c['nombre']]) as $p) {
        $i = minutos($p['hora']);
        $ocupado[] = [$i, $i + duracion_sugerida($p), 'Partido de la liga'];
    }
    $ahora = $fecha === date('Y-m-d') ? (int)date('G') * 60 + (int)date('i') : ($fecha < date('Y-m-d') ? 99999 : -1);
    $out = [];
    for ($m = $ab; $m + $dur <= $ci; $m += $dur) {
        $motivo = '';
        foreach ($ocupado as [$oi, $of, $txt]) if ($m < $of && $m + $dur > $oi) { $motivo = $txt; break; }
        if ($motivo === '' && $m <= $ahora) $motivo = 'Ya pasó';
        $out[] = ['ini' => hhmm($m), 'fin' => hhmm($m + $dur), 'libre' => $motivo === '', 'motivo' => $motivo];
    }
    return $out;
}

/** Crea una reserva comprobando que el horario esté libre. */
function crear_reserva(array $c, string $fecha, string $ini, int $turnos, string $nombre, string $tel, string $origen, string $estado = 'pendiente', string $nota = ''): int
{
    if (!strtotime($fecha)) throw new RuntimeException('Fecha no válida.');
    if (mb_strlen(trim($nombre)) < 3) throw new RuntimeException('Escriba el nombre de quien reserva.');
    if (strlen(preg_replace('/\D/', '', $tel)) < 7) throw new RuntimeException('Escriba un celular de contacto.');
    $turnos = max(1, min(6, $turnos));
    $dur = max(30, (int)$c['duracion']);
    $libres = [];
    foreach (turnos_cancha($c, $fecha) as $t) if ($t['libre']) $libres[$t['ini']] = true;
    for ($k = 0; $k < $turnos; $k++) {
        if (empty($libres[hhmm(minutos($ini) + $k * $dur)])) throw new RuntimeException('Ese horario ya no está libre. Elija otro.');
    }
    $fin = hhmm(minutos($ini) + $turnos * $dur);
    $precio = round((float)$c['precio_hora'] * $turnos * $dur / 60, 2);
    q('INSERT INTO reservas (liga_id, cancha_id, fecha, hora_inicio, hora_fin, cliente_nombre, cliente_telefono, estado, precio, origen, nota, codigo, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $c['liga_id'], $c['id'], date('Y-m-d', strtotime($fecha)), $ini, $fin, mb_substr(trim($nombre), 0, 80), mb_substr(trim($tel), 0, 30), $estado, $precio, $origen,
        mb_substr($nota, 0, 200), strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)), ip_cliente(),
    ]);
    return (int)db()->lastInsertId();
}

function nombre_estado_reserva(string $e): string
{
    return ['pendiente' => 'Por confirmar', 'confirmada' => 'Confirmada', 'pagada' => 'Pagada', 'cancelada' => 'Cancelada'][$e] ?? $e;
}
