<?php
/*
 * Lógica del campeonato: sorteo, fixture, tabla de posiciones,
 * goleadores, tarjetas y suspensiones.
 */

/* ============================================================
 * REGLAS DEL CAMPEONATO (modalidad, tiempos, puntos, tarjetas, árbitros...)
 * Cada campeonato tiene sus propias reglas; la liga solo da los valores por defecto.
 * ============================================================ */
function reglas_torneo(int $torneo_id, bool $recargar = false): ?array
{
    static $cache = [];
    if ($recargar || !array_key_exists($torneo_id, $cache)) {
        $cache[$torneo_id] = fila('SELECT * FROM torneos WHERE id = ?', [$torneo_id]);
    }
    return $cache[$torneo_id];
}

/** Resumen de reglas en una línea (para planillas y avisos). */
function resumen_reglas(array $r): string
{
    $d = deporte_de($r);
    if ($d === 'ecuavoley') {
        return nombre_modalidad($r['modalidad']) . ': ' . (int)$r['jugadores_cancha'] . ' en cancha (mín. ' . (int)$r['min_para_jugar'] . ') · máx. ' . (int)$r['max_suplentes']
            . ' suplentes · gana quien llega a ' . (int)$r['sets_ganar'] . ' sets · sets a ' . (int)$r['puntos_set'] . ' puntos (decisivo a ' . (int)$r['puntos_set_final'] . ') · '
            . (int)$r['num_arbitros'] . ' árbitro(s)';
    }
    if ($d === 'beisbol') { // (v36)
        return nombre_modalidad($r['modalidad']) . ': ' . (int)$r['jugadores_cancha'] . ' en el campo (mín. ' . (int)$r['min_para_jugar'] . ' para jugar) · ' . (int)$r['num_tiempos'] . ' entradas (si empatan, entradas extra) · '
            . (int)$r['num_arbitros'] . ' árbitro(s)';
    }
    if ($d === 'basquet') {
        return nombre_modalidad($r['modalidad']) . ': ' . (int)$r['jugadores_cancha'] . ' en cancha (mín. ' . (int)$r['min_para_jugar'] . ' para jugar) · máx. '
            . (int)$r['max_suplentes'] . ' suplentes · ' . (int)$r['num_tiempos'] . ' cuarto(s) de ' . (int)$r['minutos_tiempo'] . ' min'
            . ((int)$r['faltas_limite'] > 0 ? ' · ' . (int)$r['faltas_limite'] . ' faltas personales = sale del partido' : '') . ' · ' . (int)$r['num_arbitros'] . ' árbitro(s)';
    }
    return nombre_modalidad($r['modalidad']) . ': ' . (int)$r['jugadores_cancha'] . ' en cancha (mín. ' . (int)$r['min_para_jugar'] . ' para jugar) · máx. '
        . (int)$r['max_suplentes'] . ' suplentes · cambios: ' . ((int)$r['max_cambios'] === 0 ? 'ilimitados' : (int)$r['max_cambios'])
        . ' · ' . (int)$r['num_tiempos'] . ' tiempos de ' . (int)$r['minutos_tiempo'] . ' min · ' . (int)$r['num_arbitros'] . ' árbitro(s)'
        . ((int)$r['num_arbitros_final'] > 0 ? ' (' . (int)$r['num_arbitros_final'] . ' en eliminatorias)' : '');
}

/* ============================================================
 * SORTEO: reparte al azar los equipos inscritos en los grupos.
 * ============================================================ */
function realizar_sorteo(int $torneo_id): void
{
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$torneo_id]);
    $ids = array_map('intval', array_column(filas('SELECT equipo_id FROM torneo_equipos WHERE torneo_id = ?', [$torneo_id]), 'equipo_id'));
    shuffle($ids);
    $grupos = letras_grupos($t['formato'] === 'grupos' ? max(1, (int)$t['num_grupos']) : 1);
    $pdo = db();
    $pdo->beginTransaction();
    foreach ($ids as $i => $eq) {
        $g = count($grupos) === 1 ? ($t['formato'] === 'grupos' ? 'A' : '') : $grupos[$i % count($grupos)];
        q('UPDATE torneo_equipos SET grupo = ?, orden_sorteo = ? WHERE torneo_id = ? AND equipo_id = ?', [$g, $i + 1, $torneo_id, $eq]);
    }
    q("UPDATE torneos SET fecha_sorteo = ?, estado = CASE WHEN estado = 'inscripcion' THEN 'sorteado' ELSE estado END WHERE id = ?", [date('Y-m-d H:i:s'), $torneo_id]);
    $pdo->commit();
}

function letras_grupos(int $n): array
{
    $l = [];
    for ($i = 0; $i < $n; $i++) {
        $l[] = chr(65 + $i);
    }
    return $l;
}

/* ============================================================
 * FIXTURE: todos contra todos (método del círculo).
 * Devuelve jornadas: [ [ [local, visitante], ... ], ... ]
 * ============================================================ */
function todos_contra_todos(array $ids, int $vueltas = 1): array
{
    $ids = array_values($ids);
    if (count($ids) < 2) return [];
    if (count($ids) % 2 === 1) {
        $ids[] = null; // equipo "descansa"
    }
    $n = count($ids);
    $jornadas = [];
    $t = $ids;
    for ($r = 0; $r < $n - 1; $r++) {
        $j = [];
        for ($i = 0; $i < $n / 2; $i++) {
            $a = $t[$i];
            $b = $t[$n - 1 - $i];
            if ($a === null || $b === null) continue;
            // Se alterna la localía para que sea equilibrada
            if ($i === 0) {
                $j[] = ($r % 2 === 0) ? [$a, $b] : [$b, $a];
            } else {
                $j[] = ($i % 2 === 1) ? [$a, $b] : [$b, $a];
            }
        }
        $jornadas[] = $j;
        // Rotación: el primero queda fijo, el último pasa a la segunda posición
        $ultimo = array_pop($t);
        array_splice($t, 1, 0, [$ultimo]);
    }
    if ($vueltas >= 2) {
        $ida = $jornadas;
        foreach ($ida as $j) {
            $jornadas[] = array_map(fn($p) => [$p[1], $p[0]], $j);
        }
    }
    return $jornadas;
}

/**
 * Genera los partidos de la fase regular del torneo.
 * $fecha_inicio (Y-m-d) y $dias_entre son opcionales: sirven para poner fecha a cada jornada.
 */
function generar_fixture(int $torneo_id, string $fecha_inicio = '', int $dias_entre = 7, string $hora = '', string $cancha = ''): int
{
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$torneo_id]);
    $jugados = (int)valor("SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND fase = 'regular' AND estado IN ('jugado','wo')", [$torneo_id]);
    if ($jugados > 0) {
        throw new RuntimeException('No se puede regenerar el fixture: ya hay partidos jugados en la fase regular.');
    }
    $inscritos = filas('SELECT equipo_id, grupo FROM torneo_equipos WHERE torneo_id = ? ORDER BY grupo, orden_sorteo, equipo_id', [$torneo_id]);
    if (count($inscritos) < 2) {
        throw new RuntimeException('Se necesitan al menos 2 equipos inscritos.');
    }
    $por_grupo = [];
    foreach ($inscritos as $r) {
        $por_grupo[$r['grupo']][] = (int)$r['equipo_id'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    q("DELETE FROM partidos WHERE torneo_id = ? AND fase = 'regular'", [$torneo_id]);
    $total = 0;
    foreach ($por_grupo as $grupo => $ids) {
        $jornadas = todos_contra_todos($ids, (int)$t['vueltas']);
        foreach ($jornadas as $k => $partidos) {
            $num = $k + 1;
            $fecha = '';
            if ($fecha_inicio !== '' && strtotime($fecha_inicio)) {
                $fecha = date('Y-m-d', strtotime($fecha_inicio . ' +' . ($k * max(1, $dias_entre)) . ' days'));
            }
            foreach ($partidos as $p) {
                q('INSERT INTO partidos (torneo_id, fase, grupo, jornada, local_id, visitante_id, fecha, hora, cancha) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$torneo_id, 'regular', (string)$grupo, $num, $p[0], $p[1], $fecha, $hora, $cancha]);
                $total++;
            }
        }
    }
    q("UPDATE torneos SET estado = 'en_juego' WHERE id = ? AND estado IN ('inscripcion','sorteado')", [$torneo_id]);
    $pdo->commit();
    return $total;
}

/* ============================================================
 * PARTIDOS
 * ============================================================ */

/** Partidos del torneo con nombres y escudos de los equipos. */
function partidos_torneo(int $torneo_id, string $filtro = ''): array
{
    return filas("SELECT p.*, el.nombre AS local_nombre, el.escudo AS local_escudo, el.color AS local_color,
                         ev.nombre AS visitante_nombre, ev.escudo AS visitante_escudo, ev.color AS visitante_color
                  FROM partidos p
                  JOIN equipos el ON el.id = p.local_id
                  JOIN equipos ev ON ev.id = p.visitante_id
                  WHERE p.torneo_id = ? $filtro
                  ORDER BY CASE p.fase WHEN 'regular' THEN 0 WHEN 'octavos' THEN 1 WHEN 'cuartos' THEN 2 WHEN 'semifinal' THEN 3 WHEN 'tercer' THEN 4 WHEN 'final' THEN 5 ELSE 6 END,
                           p.jornada, p.grupo, (p.fecha = ''), p.fecha, p.hora, p.id", [$torneo_id]);
}

function partido_cerrado(array $p): bool
{
    return in_array($p['estado'], ['jugado', 'wo'], true);
}

/** Clave para ordenar partidos en el tiempo (fecha, hora, jornada, id). */
function clave_orden_partido(array $p): string
{
    $fecha = $p['fecha'] !== '' ? $p['fecha'] : '9999-12-31';
    return sprintf('%s %s %04d %08d', $fecha, $p['hora'] !== '' ? $p['hora'] : '99:99', (int)$p['jornada'], (int)$p['id']);
}

/* ============================================================
 * TABLA DE POSICIONES (solo fase regular)
 * Desempate: Puntos > Diferencia de gol > Goles a favor > Menos goles en contra > Nombre
 * ============================================================ */
function tabla_posiciones(int $torneo_id): array
{
    $t = reglas_torneo($torneo_id);
    if (!$t) return [];
    $equipos = filas('SELECT te.grupo, e.id, e.nombre, e.escudo, e.color FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id WHERE te.torneo_id = ?', [$torneo_id]);
    $tabla = [];
    $grupo_de = [];
    foreach ($equipos as $e) {
        $tabla[$e['id']] = ['id' => (int)$e['id'], 'nombre' => $e['nombre'], 'escudo' => $e['escudo'], 'color' => $e['color'], 'grupo' => $e['grupo'],
            'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'dg' => 0, 'pts' => 0, 'pf' => 0, 'pc' => 0, 'ultimos' => []];
        $grupo_de[$e['id']] = $e['grupo'];
    }
    $partidos = filas("SELECT * FROM partidos WHERE torneo_id = ? AND fase = 'regular' AND estado IN ('jugado','wo') AND goles_local IS NOT NULL AND goles_visitante IS NOT NULL", [$torneo_id]);
    usort($partidos, fn($a, $b) => strcmp(clave_orden_partido($a), clave_orden_partido($b)));
    // Ecuavóley: puntos de todos los sets (para desempatar por diferencia de puntos)
    $es_volley = deporte_de($t) === 'ecuavoley';
    $puntos_sets = [];
    if ($es_volley) {
        foreach (filas("SELECT s.partido_id, SUM(s.puntos_local) AS pl, SUM(s.puntos_visitante) AS pv FROM partido_sets s JOIN partidos p ON p.id = s.partido_id WHERE p.torneo_id = ? GROUP BY s.partido_id", [$torneo_id]) as $x) {
            $puntos_sets[(int)$x['partido_id']] = [(int)$x['pl'], (int)$x['pv']];
        }
    }
    foreach ($partidos as $p) {
        $l = (int)$p['local_id'];
        $v = (int)$p['visitante_id'];
        if (!isset($tabla[$l]) || !isset($tabla[$v])) continue;
        $gl = (int)$p['goles_local'];
        $gv = (int)$p['goles_visitante'];
        [$ptl, $ptv] = $puntos_sets[(int)$p['id']] ?? [0, 0];
        $tabla[$l]['pf'] += $ptl;
        $tabla[$l]['pc'] += $ptv;
        $tabla[$v]['pf'] += $ptv;
        $tabla[$v]['pc'] += $ptl;
        foreach ([[$l, $gl, $gv], [$v, $gv, $gl]] as [$id, $gf, $gc]) {
            $tabla[$id]['pj']++;
            $tabla[$id]['gf'] += $gf;
            $tabla[$id]['gc'] += $gc;
            if ($gf > $gc) {
                $tabla[$id]['pg']++;
                $tabla[$id]['pts'] += (int)$t['puntos_ganado'];
                $tabla[$id]['ultimos'][] = 'G';
            } elseif ($gf === $gc) {
                $tabla[$id]['pe']++;
                $tabla[$id]['pts'] += (int)$t['puntos_empate'];
                $tabla[$id]['ultimos'][] = 'E';
            } else {
                $tabla[$id]['pp']++;
                $tabla[$id]['pts'] += (int)$t['puntos_perdido'];
                $tabla[$id]['ultimos'][] = 'P';
            }
        }
    }
    // Resta de puntos por sanciones de la comisión disciplinaria
    foreach (filas('SELECT equipo_id, SUM(puntos) AS pts FROM sanciones_equipo WHERE torneo_id = ? GROUP BY equipo_id', [$torneo_id]) as $s) {
        if (isset($tabla[$s['equipo_id']])) {
            $tabla[$s['equipo_id']]['sancion'] = (int)$s['pts'];
            $tabla[$s['equipo_id']]['pts'] -= (int)$s['pts'];
        }
    }
    $grupos = [];
    foreach ($tabla as $fila) {
        $fila['sancion'] = $fila['sancion'] ?? 0;
        $fila['dg'] = $fila['gf'] - $fila['gc'];
        $fila['ultimos'] = array_slice($fila['ultimos'], -5);
        $grupos[$fila['grupo']][] = $fila;
    }
    ksort($grupos);
    foreach ($grupos as &$g) {
        usort($g, function ($a, $b) {
            // Ecuavóley: después de la diferencia de sets desempata la diferencia de puntos
            return [$b['pts'], $b['dg'], $b['pf'] - $b['pc'], $b['gf'], $a['gc'], $a['nombre']] <=> [$a['pts'], $a['dg'], $a['pf'] - $a['pc'], $a['gf'], $b['gc'], $b['nombre']];
        });
    }
    unset($g);
    return $grupos;
}

/* ============================================================
 * GOLEADORES Y TARJETAS
 * ============================================================ */
function goleadores(int $torneo_id, int $limite = 50): array
{
    return filas("SELECT j.id, j.nombres, j.apellidos, j.foto, j.foto_publica, j.numero, e.id AS equipo_id, e.nombre AS equipo, e.escudo, e.color,
                         SUM(" . sql_valor_evento('ev') . ") AS goles,
                         SUM(CASE WHEN ev.tipo = 'penal' THEN 1 ELSE 0 END) AS penales,
                         SUM(CASE WHEN ev.tipo = 'p3' THEN 1 ELSE 0 END) AS triples,
                         COUNT(DISTINCT ev.partido_id) AS partidos
                  FROM eventos ev
                  JOIN partidos p ON p.id = ev.partido_id
                  JOIN jugadores j ON j.id = ev.jugador_id
                  JOIN equipos e ON e.id = ev.equipo_id
                  WHERE p.torneo_id = ? AND p.estado = 'jugado' AND ev.tipo IN ('gol','penal','p1','p2','p3')
                  GROUP BY j.id
                  ORDER BY goles DESC, penales ASC, j.apellidos
                  LIMIT $limite", [$torneo_id]);
}

function tarjetas(int $torneo_id): array
{
    return filas("SELECT j.id, j.nombres, j.apellidos, j.foto, j.foto_publica, j.numero, e.id AS equipo_id, e.nombre AS equipo, e.escudo, e.color,
                         SUM(CASE WHEN ev.tipo = 'amarilla' THEN 1 ELSE 0 END) AS amarillas,
                         SUM(CASE WHEN ev.tipo = 'roja' THEN 1 ELSE 0 END) AS rojas
                  FROM eventos ev
                  JOIN partidos p ON p.id = ev.partido_id
                  JOIN jugadores j ON j.id = ev.jugador_id
                  JOIN equipos e ON e.id = ev.equipo_id
                  WHERE p.torneo_id = ? AND p.estado IN ('jugado','wo') AND ev.tipo IN ('amarilla','roja')
                  GROUP BY j.id
                  ORDER BY rojas DESC, amarillas DESC, j.apellidos", [$torneo_id]);
}

/** Tabla de juego limpio por equipo (amarilla = 1 punto, roja = 3 puntos; menos es mejor). */
function fair_play(int $torneo_id): array
{
    return filas("SELECT e.id, e.nombre, e.escudo, e.color,
                         SUM(CASE WHEN ev.tipo = 'amarilla' THEN 1 ELSE 0 END) AS amarillas,
                         SUM(CASE WHEN ev.tipo = 'roja' THEN 1 ELSE 0 END) AS rojas,
                         SUM(CASE WHEN ev.tipo = 'amarilla' THEN 1 WHEN ev.tipo = 'roja' THEN 3 ELSE 0 END) AS puntos
                  FROM eventos ev
                  JOIN partidos p ON p.id = ev.partido_id
                  JOIN equipos e ON e.id = ev.equipo_id
                  WHERE p.torneo_id = ? AND p.estado IN ('jugado','wo') AND ev.tipo IN ('amarilla','roja')
                  GROUP BY e.id
                  ORDER BY puntos ASC, e.nombre", [$torneo_id]);
}

/* ============================================================
 * SUSPENSIONES
 * Calcula automáticamente qué jugadores no pueden jugar el próximo partido:
 *  - Por acumulación de amarillas (según parámetros de la liga)
 *  - Por tarjeta roja directa o doble amarilla
 *  - Por sanciones manuales (tribunal / comisión disciplinaria)
 * Devuelve [jugador_id => ['pendientes'=>n, 'motivos'=>[...], ...]]
 * ============================================================ */
function suspensiones(int $torneo_id): array
{
    $t = reglas_torneo($torneo_id);
    if (!$t) return [];
    $n_amarillas = max(1, (int)$t['amarillas_suspension']);

    // Partidos cerrados de cada equipo, en orden cronológico
    $partidos = filas("SELECT * FROM partidos WHERE torneo_id = ? AND estado IN ('jugado','wo')", [$torneo_id]);
    usort($partidos, fn($a, $b) => strcmp(clave_orden_partido($a), clave_orden_partido($b)));
    $por_equipo = [];
    foreach ($partidos as $idx => $p) {
        $por_equipo[(int)$p['local_id']][] = $p;
        $por_equipo[(int)$p['visitante_id']][] = $p;
    }

    // Tarjetas por jugador y partido
    $ev = filas("SELECT ev.jugador_id, ev.equipo_id, ev.partido_id, ev.tipo FROM eventos ev JOIN partidos p ON p.id = ev.partido_id
                 WHERE p.torneo_id = ? AND p.estado IN ('jugado','wo') AND ev.tipo IN ('amarilla','roja')", [$torneo_id]);
    $tarj = [];
    $equipo_jugador = [];
    foreach ($ev as $x) {
        $jid = (int)$x['jugador_id'];
        $pid = (int)$x['partido_id'];
        $tarj[$jid][$pid][$x['tipo']] = ($tarj[$jid][$pid][$x['tipo']] ?? 0) + 1;
        $equipo_jugador[$jid] = (int)$x['equipo_id'];
    }

    $res = [];
    // (v18) Cada sanción guarda también cuántos partidos son en total y cuántos ya cumplió ("Cumplido 1 de 3")
    $agregar = function (int $jid, int $total, int $cumplidos, string $motivo) use (&$res) {
        $cumplidos = max(0, min($total, $cumplidos));
        $pendientes = $total - $cumplidos;
        if ($pendientes <= 0) return;
        if (!isset($res[$jid])) $res[$jid] = ['pendientes' => 0, 'motivos' => [], 'detalle' => []];
        $res[$jid]['pendientes'] += $pendientes;
        $res[$jid]['motivos'][] = $motivo;
        $res[$jid]['detalle'][] = ['motivo' => $motivo, 'total' => $total, 'cumplidos' => $cumplidos];
    };

    foreach ($tarj as $jid => $por_partido) {
        $eq = $equipo_jugador[$jid];
        $lista = $por_equipo[$eq] ?? [];
        $acumuladas = 0;
        foreach ($lista as $k => $p) {
            $pid = (int)$p['id'];
            if (!isset($por_partido[$pid])) continue;
            $am = $por_partido[$pid]['amarilla'] ?? 0;
            $ro = $por_partido[$pid]['roja'] ?? 0;
            $jugados_despues = count($lista) - $k - 1;
            $fecha = $p['fecha'] !== '' ? fecha_bonita($p['fecha']) : 'jornada ' . $p['jornada'];
            if ($t['doble_amarilla_roja'] && $am >= 2) {
                $agregar($jid, (int)$t['partidos_susp_roja'], $jugados_despues, 'Doble amarilla (' . $fecha . ')');
                $am = 0;
            }
            if ($ro > 0) {
                $agregar($jid, (int)$t['partidos_susp_roja'], $jugados_despues, 'Tarjeta roja (' . $fecha . ')');
            }
            $acumuladas += $am;
            while ($acumuladas >= $n_amarillas) {
                $acumuladas -= $n_amarillas;
                $agregar($jid, (int)$t['partidos_susp_amarillas'], $jugados_despues, $n_amarillas . ' amarillas acumuladas (' . $fecha . ')');
            }
        }
    }

    // Sanciones manuales
    $sanciones = filas('SELECT s.*, j.equipo_id FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id WHERE s.torneo_id = ?', [$torneo_id]);
    foreach ($sanciones as $s) {
        $lista = $por_equipo[(int)$s['equipo_id']] ?? [];
        $cumplidos = 0;
        foreach ($lista as $p) {
            if ($p['fecha'] !== '' && $p['fecha'] >= $s['desde']) $cumplidos++;
        }
        $agregar((int)$s['jugador_id'], (int)$s['partidos'], $cumplidos, 'Sanción: ' . ($s['motivo'] !== '' ? $s['motivo'] : 'disciplinaria'));
    }

    // (v28) Sanciones de la federación (por cédula, con fecha hasta): se respetan en todas las ligas afiliadas
    if (function_exists('suspensiones_federativas')) foreach (suspensiones_federativas($torneo_id) as $jid => $txt) $agregar($jid, 1, 0, $txt);

    if ($res) {
        $ids = implode(',', array_map('intval', array_keys($res)));
        foreach (filas("SELECT j.*, e.nombre AS equipo, e.escudo, e.color FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id IN ($ids)") as $j) {
            $res[(int)$j['id']]['jugador'] = $j;
        }
        uasort($res, fn($a, $b) => strcmp($a['jugador']['equipo'] ?? '', $b['jugador']['equipo'] ?? ''));
    }
    return $res;
}

/** Amarillas que lleva acumuladas cada jugador (para avisar "está a 1 amarilla de la suspensión"). */
function amarillas_acumuladas(int $torneo_id): array
{
    $t = reglas_torneo($torneo_id);
    $n = max(1, (int)$t['amarillas_suspension']);
    $rows = filas("SELECT ev.jugador_id, ev.partido_id, COUNT(*) AS c FROM eventos ev JOIN partidos p ON p.id = ev.partido_id
                   WHERE p.torneo_id = ? AND p.estado IN ('jugado','wo') AND ev.tipo = 'amarilla' GROUP BY ev.jugador_id, ev.partido_id", [$torneo_id]);
    $acc = [];
    foreach ($rows as $r) {
        $c = (int)$r['c'];
        if ($t['doble_amarilla_roja'] && $c >= 2) continue;
        $acc[(int)$r['jugador_id']] = ($acc[(int)$r['jugador_id']] ?? 0) + $c;
    }
    foreach ($acc as $k => $v) {
        $acc[$k] = $v % $n;
    }
    return $acc;
}

/* ============================================================
 * ESTADÍSTICAS DE UN JUGADOR
 * ============================================================ */
function estadisticas_jugador(int $jugador_id, ?int $torneo_id = null): array
{
    $filtro = $torneo_id ? 'AND p.torneo_id = ' . (int)$torneo_id : '';
    $r = fila("SELECT
                 SUM(" . sql_valor_evento('ev') . ") AS goles,
                 SUM(CASE WHEN ev.tipo = 'p3' THEN 1 ELSE 0 END) AS triples,
                 SUM(CASE WHEN ev.tipo = 'falta' THEN 1 ELSE 0 END) AS faltas,
                 SUM(CASE WHEN ev.tipo = 'asistencia' THEN 1 ELSE 0 END) AS asistencias,
                 SUM(CASE WHEN ev.tipo = 'penal' THEN 1 ELSE 0 END) AS penales,
                 SUM(CASE WHEN ev.tipo = 'autogol' THEN 1 ELSE 0 END) AS autogoles,
                 SUM(CASE WHEN ev.tipo = 'amarilla' THEN 1 ELSE 0 END) AS amarillas,
                 SUM(CASE WHEN ev.tipo = 'roja' THEN 1 ELSE 0 END) AS rojas,
                 COUNT(DISTINCT CASE WHEN ev.tipo IN ('gol','penal','p1','p2','p3') THEN ev.partido_id END) AS partidos_con_gol
               FROM eventos ev JOIN partidos p ON p.id = ev.partido_id
               WHERE ev.jugador_id = ? AND p.estado IN ('jugado','wo') $filtro", [$jugador_id]);
    return array_map(fn($v) => (int)$v, $r ?: []);
}

/** Marcador calculado a partir de los goles registrados (vocalía/admin, o el informe del árbitro). */
function marcador_desde_eventos(array $partido, string $tabla = 'eventos'): array
{
    $tabla = $tabla === 'informe_eventos' ? 'informe_eventos' : 'eventos';
    // Ecuavóley: el marcador son los sets ganados (el informe del árbitro trae su propio marcador)
    if (deporte_de(reglas_torneo((int)$partido['torneo_id']) ?? []) === 'ecuavoley') {
        if ($tabla === 'eventos') return sets_ganados((int)$partido['id']);
        $inf = informe_partido((int)$partido['id']);
        return [(int)($inf['goles_local'] ?? 0), (int)($inf['goles_visitante'] ?? 0)];
    }
    $ev = filas("SELECT equipo_id, tipo FROM $tabla WHERE partido_id = ?", [$partido['id']]);
    $gl = 0;
    $gv = 0;
    foreach ($ev as $x) {
        $eq = (int)$x['equipo_id'];
        if (valor_evento($x['tipo']) > 0) {
            if ($eq === (int)$partido['local_id']) $gl += valor_evento($x['tipo']); else $gv += valor_evento($x['tipo']);
        } elseif ($x['tipo'] === 'autogol') {
            // Autogol: suma al equipo contrario
            if ($eq === (int)$partido['local_id']) $gv++; else $gl++;
        }
    }
    return [$gl, $gv];
}

/* ============================================================
 * VERSIÓN 2: partido en vivo, árbitros, informe y comparación
 * ============================================================ */

/** Partido con equipos, campeonato y liga (para vocalía, árbitro e impresiones). */
function partido_completo(int $id): ?array
{
    return fila('SELECT p.*, el.nombre AS local_nombre, el.escudo AS local_escudo, el.color AS local_color,
                        ev.nombre AS visitante_nombre, ev.escudo AS visitante_escudo, ev.color AS visitante_color,
                        t.nombre AS torneo, t.temporada, t.liga_id,
                        a.nombre AS arbitro_nombre, a1.nombre AS asistente1_nombre, a2.nombre AS asistente2_nombre, a3.nombre AS cuarto_nombre
                 FROM partidos p
                 JOIN torneos t ON t.id = p.torneo_id
                 JOIN equipos el ON el.id = p.local_id
                 JOIN equipos ev ON ev.id = p.visitante_id
                 LEFT JOIN arbitros a ON a.id = p.arbitro_id
                 LEFT JOIN arbitros a1 ON a1.id = p.asistente1_id
                 LEFT JOIN arbitros a2 ON a2.id = p.asistente2_id
                 LEFT JOIN arbitros a3 ON a3.id = p.cuarto_id
                 WHERE p.id = ?', [$id]);
}

/** Nombre del árbitro central para mostrar (del catálogo o texto libre). */
function nombre_arbitro(array $p): string
{
    return (string)(($p['arbitro_nombre'] ?? '') !== '' ? $p['arbitro_nombre'] : ($p['arbitro'] ?? ''));
}

/**
 * Minuto actual de un partido en vivo.
 * Devuelve null si no está en vivo; si no, ['min'=>int, 'txt'=>"23'", 'periodo'=>n, 'pausa'=>bool, 'etiqueta'=>'1T'].
 */
function minuto_vivo(array $p, ?array $liga = null): ?array
{
    $liga = reglas_torneo((int)$p['torneo_id']);
    if (($p['estado'] ?? '') !== 'en_vivo' || (int)$p['vivo_periodo'] < 1) return null;
    $mt = max(1, (int)$liga['minutos_tiempo']);
    $per = (int)$p['vivo_periodo'];
    $dep = deporte_de($liga);
    if ($dep === 'ecuavoley') {
        $num = (int)valor('SELECT COALESCE(MAX(num), 1) FROM partido_sets WHERE partido_id = ?', [$p['id']]);
        return ['min' => 0, 'txt' => 'Set ' . $num, 'periodo' => $num, 'pausa' => false, 'etiqueta' => 'Set ' . $num];
    }
    if ($dep === 'beisbol') { // (v36) béisbol y softbol: sin reloj; el «minuto» de cada evento es la entrada
        return ['min' => $per, 'txt' => 'Entrada ' . $per, 'periodo' => $per, 'pausa' => (int)$p['vivo_pausa'] === 1, 'etiqueta' => $per . '.ª entrada'];
    }
    $etiqueta = $dep === 'basquet' ? ((int)$liga['num_tiempos'] === 1 ? 'Juego' : 'Q' . $per) : ((int)$liga['num_tiempos'] === 2 ? ($per === 1 ? '1T' : '2T') : $per . 'º tiempo');
    if ((int)$p['vivo_pausa'] === 1) {
        $fin = $per >= (int)$liga['num_tiempos'];
        return ['min' => $per * $mt, 'txt' => $fin ? 'Final' : ($dep === 'basquet' ? 'Descanso' : 'Entretiempo'), 'periodo' => $per, 'pausa' => true, 'etiqueta' => $etiqueta];
    }
    $transcurrido = intdiv(max(0, time() - (int)$p['vivo_desde']), 60) + 1;
    $base = ($per - 1) * $mt;
    $txt = $transcurrido > $mt ? ($base + $mt) . '+' . ($transcurrido - $mt) . "'" : ($base + $transcurrido) . "'";
    return ['min' => $base + min($transcurrido, $mt + 30), 'txt' => $txt, 'periodo' => $per, 'pausa' => false, 'etiqueta' => $etiqueta];
}

/** Recalcula y guarda el marcador del partido con los goles registrados en la vocalía. */
function actualizar_marcador(array $p): array
{
    [$gl, $gv] = marcador_desde_eventos($p);
    q('UPDATE partidos SET goles_local = ?, goles_visitante = ? WHERE id = ?', [$gl, $gv, $p['id']]);
    return [$gl, $gv];
}

/** Informe del árbitro de un partido (o null). */
function informe_partido(int $partido_id): ?array
{
    return fila('SELECT i.*, a.nombre AS arbitro FROM informes i LEFT JOIN arbitros a ON a.id = i.arbitro_id WHERE i.partido_id = ?', [$partido_id]);
}

/**
 * Compara lo que registró la VOCALÍA con el INFORME DEL ÁRBITRO.
 * Devuelve ['estado' => 'sin_arbitro'|'pendiente'|'borrador'|'enviado', 'diferencias' => [ [concepto, detalle, vocal, arbitro], ... ]]
 */
function comparar_informe(array $p): array
{
    $inf = informe_partido((int)$p['id']);
    if (!$inf) {
        return ['estado' => empty($p['arbitro_id']) ? 'sin_arbitro' : 'pendiente', 'diferencias' => []];
    }
    if ($inf['estado'] !== 'enviado') {
        return ['estado' => 'borrador', 'diferencias' => []];
    }
    $dif = [];
    // Marcador
    $mv = ($p['goles_local'] === null ? '—' : (int)$p['goles_local']) . ' - ' . ($p['goles_visitante'] === null ? '—' : (int)$p['goles_visitante']);
    $ma = ($inf['goles_local'] === null ? '—' : (int)$inf['goles_local']) . ' - ' . ($inf['goles_visitante'] === null ? '—' : (int)$inf['goles_visitante']);
    if ($mv !== $ma) {
        $dif[] = ['Marcador final', $p['local_nombre'] . ' vs ' . $p['visitante_nombre'], $mv, $ma];
    }
    // Hora de inicio
    if ($p['hora_inicio'] !== '' && $inf['hora_inicio'] !== '' && $p['hora_inicio'] !== $inf['hora_inicio']) {
        $d = abs(strtotime('2000-01-01 ' . $p['hora_inicio']) - strtotime('2000-01-01 ' . $inf['hora_inicio'])) / 60;
        $dif[] = ['Hora de inicio', 'Diferencia de ' . (int)$d . ' min', $p['hora_inicio'], $inf['hora_inicio']];
    }
    // Goles y tarjetas por jugador
    $conteo = function (string $tabla) use ($p) {
        $c = [];
        foreach (filas("SELECT jugador_id, tipo, COUNT(*) AS n FROM $tabla WHERE partido_id = ? GROUP BY jugador_id, tipo", [$p['id']]) as $r) {
            $c[$r['jugador_id'] . '|' . $r['tipo']] = (int)$r['n'];
        }
        return $c;
    };
    $v = $conteo('eventos');
    $a = $conteo('informe_eventos');
    $claves = array_unique(array_merge(array_keys($v), array_keys($a)));
    if ($claves) {
        $ids = implode(',', array_unique(array_map(fn($k) => (int)explode('|', $k)[0], $claves)));
        $nombres = [];
        foreach (filas("SELECT j.id, j.nombres, j.apellidos, j.numero, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id IN ($ids)") as $j) {
            $nombres[(int)$j['id']] = ($j['numero'] !== null ? '#' . (int)$j['numero'] . ' ' : '') . nombre_jugador($j) . ' (' . $j['equipo'] . ')';
        }
        $orden = array_flip(tipos_evento());
        usort($claves, fn($x, $y) => [$orden[explode('|', $x)[1]] ?? 9, $x] <=> [$orden[explode('|', $y)[1]] ?? 9, $y]);
        foreach ($claves as $k) {
            [$jid, $tipo] = explode('|', $k);
            $nv = $v[$k] ?? 0;
            $na = $a[$k] ?? 0;
            if ($nv !== $na) {
                $dif[] = [nombre_evento($tipo, deporte_de(reglas_torneo((int)$p['torneo_id']) ?? [])), $nombres[(int)$jid] ?? ('Jugador ' . $jid), (string)$nv, (string)$na];
            }
        }
    }
    return ['estado' => 'enviado', 'diferencias' => $dif];
}

/** Multas pendientes de pago de un campeonato (de jugadores y de equipos). */
function multas_pendientes(int $torneo_id): array
{
    $j = filas("SELECT 'jugador' AS clase, s.id, s.multa, s.motivo, s.desde AS fecha, e.nombre AS equipo, j.nombres, j.apellidos
                FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id JOIN equipos e ON e.id = j.equipo_id
                WHERE s.torneo_id = ? AND s.multa > 0 AND s.pagada = 0", [$torneo_id]);
    $e = filas("SELECT 'equipo' AS clase, s.id, s.multa, s.motivo, s.fecha, e.nombre AS equipo, '' AS nombres, '' AS apellidos
                FROM sanciones_equipo s JOIN equipos e ON e.id = s.equipo_id
                WHERE s.torneo_id = ? AND s.multa > 0 AND s.pagada = 0", [$torneo_id]);
    $todas = array_merge($j, $e);
    usort($todas, fn($a, $b) => strcmp($a['equipo'], $b['equipo']));
    return $todas;
}

/** Jugadores de un equipo para la vocalía/informe, marcando suspendidos y presentes. */
function jugadores_para_partido(array $p, int $equipo_id, array $susp): array
{
    $pres = array_map('intval', array_column(filas('SELECT jugador_id FROM alineaciones WHERE partido_id = ? AND equipo_id = ?', [$p['id'], $equipo_id]), 'jugador_id'));
    $lista = filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1 ORDER BY (numero IS NULL), numero, apellidos, nombres', [$equipo_id]);
    foreach ($lista as &$j) {
        $j['suspendido'] = isset($susp[(int)$j['id']]);
        $j['presente'] = in_array((int)$j['id'], $pres, true);
    }
    unset($j);
    return $lista;
}

/** Opciones <option> de jugadores de ambos equipos agrupadas por equipo. */
function opciones_jugadores_partido(array $p, array $jl, array $jv, bool $solo_presentes = false): string
{
    $h = '<option value="">— elegir jugador —</option>';
    foreach ([[$p['local_nombre'], $jl], [$p['visitante_nombre'], $jv]] as [$nom, $lista]) {
        $hay_pres = count(array_filter($lista, fn($j) => $j['presente'])) > 0;
        $h .= '<optgroup label="' . e($nom) . '">';
        foreach ($lista as $j) {
            if ($solo_presentes && $hay_pres && !$j['presente']) continue;
            $h .= '<option value="' . (int)$j['id'] . '">' . ($j['numero'] !== null ? '#' . (int)$j['numero'] . ' ' : '') . e(nombre_jugador($j)) . ($j['suspendido'] ? ' (SUSPENDIDO)' : '') . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}

/** Borra un partido junto con sus fotos internas. */
function eliminar_partido(int $id): void
{
    foreach (filas('SELECT archivo FROM fotos_partido WHERE partido_id = ?', [$id]) as $f) {
        borrar_foto_privada($f['archivo']);
    }
    q('DELETE FROM partidos WHERE id = ?', [$id]);
}

/* ============================================================
 * ÁRBITROS DEL PARTIDO Y CALIFICACIÓN
 * ============================================================ */

/**
 * Cuántos árbitros pitan un partido:
 *  1) lo definido en el propio partido (ej: una final con 4), si no
 *  2) en eliminatorias (octavos, cuartos, semifinal, tercer lugar, final) lo que diga "árbitros en eliminatorias" del campeonato, si no
 *  3) los "árbitros por partido" del campeonato.
 */
function num_arbitros_partido(array $p): int
{
    if (!empty($p['num_arbitros'])) return max(1, min(4, (int)$p['num_arbitros']));
    $r = reglas_torneo((int)$p['torneo_id']);
    if (($p['fase'] ?? 'regular') !== 'regular' && (int)$r['num_arbitros_final'] > 0) return (int)$r['num_arbitros_final'];
    return max(1, min(4, (int)$r['num_arbitros']));
}

/** Árbitros asignados al partido según cuántos pitan. [campo, etiqueta, id, nombre] */
function arbitros_del_partido(array $p): array
{
    $nombres = ['arbitro_id' => $p['arbitro_nombre'] ?? '', 'asistente1_id' => $p['asistente1_nombre'] ?? '', 'asistente2_id' => $p['asistente2_nombre'] ?? '', 'cuarto_id' => $p['cuarto_nombre'] ?? ''];
    $lista = [];
    foreach (roles_arbitro(num_arbitros_partido($p)) as $campo => $etq) {
        $lista[] = [$campo, $etq, $p[$campo] ? (int)$p[$campo] : null, (string)$nombres[$campo]];
    }
    return $lista;
}

/** ¿Falta calificar a algún árbitro de un partido terminado? (solo si el campeonato usa calificación) */
function calificacion_pendiente(array $p): bool
{
    $r = reglas_torneo((int)$p['torneo_id']);
    if ($r['calificar_arbitro'] === 'no' || !in_array($p['estado'], ['jugado', 'wo'], true)) return false;
    foreach (arbitros_del_partido($p) as [$c, $e, $id, $n]) {
        if ($id && !fila('SELECT id FROM calificaciones WHERE partido_id = ? AND arbitro_id = ?', [$p['id'], $id])) return true;
    }
    return false;
}

/** Guarda la calificación de un árbitro (desde el panel o desde la vocalía de un directivo). */
function guardar_calificacion(array $p, int $arbitro_id, array $datos, string $por): void
{
    $ids = array_filter(array_map(fn($x) => $x[2], arbitros_del_partido($p)));
    if (!in_array($arbitro_id, $ids, true)) throw new RuntimeException('Ese árbitro no pitó este partido.');
    $nota = (int)($datos['nota'] ?? 0);
    if ($nota < 1 || $nota > 10) throw new RuntimeException('Elija una nota del 1 al 10.');
    $sub = fn($k) => isset($datos[$k]) && $datos[$k] !== '' ? max(1, min(5, (int)$datos[$k])) : null;
    q('INSERT INTO calificaciones (partido_id, arbitro_id, nota, puntualidad, presentacion, criterio, control, comentario, calificado_por)
       VALUES (?,?,?,?,?,?,?,?,?)
       ON CONFLICT(partido_id, arbitro_id) DO UPDATE SET nota = excluded.nota, puntualidad = excluded.puntualidad, presentacion = excluded.presentacion,
         criterio = excluded.criterio, control = excluded.control, comentario = excluded.comentario, calificado_por = excluded.calificado_por, creado = CURRENT_TIMESTAMP',
        [$p['id'], $arbitro_id, $nota, $sub('puntualidad'), $sub('presentacion'), $sub('criterio'), $sub('control'), mb_substr((string)($datos['comentario'] ?? ''), 0, 1000), $por]);
}

/** Formulario de calificación (se usa en el panel y en la vocalía). */
function form_calificacion(array $p, string $accion_url = ''): string
{
    $aspectos = ['puntualidad' => 'Puntualidad', 'presentacion' => 'Presentación', 'criterio' => 'Criterio / aplicación del reglamento', 'control' => 'Control del partido'];
    ob_start();
    foreach (arbitros_del_partido($p) as [$campo, $etq, $id, $nombre]) {
        if (!$id) continue;
        $c = fila('SELECT * FROM calificaciones WHERE partido_id = ? AND arbitro_id = ?', [$p['id'], $id]) ?? [];
        ?>
        <form method="post" <?= $accion_url !== '' ? 'action="' . e($accion_url) . '"' : '' ?> class="tarjeta" style="background:transparent">
          <?= csrf_campo() ?><input type="hidden" name="accion" value="calificar"><input type="hidden" name="arbitro_id" value="<?= $id ?>">
          <h3 style="margin-top:0"><?= e($etq) ?>: <?= e($nombre) ?> <?= $c ? '<span class="estado-chip chip-ok">calificado ' . (int)$c['nota'] . '/10</span>' : '<span class="estado-chip chip-pend">sin calificar</span>' ?></h3>
          <div class="campos">
            <div><label>Nota general (1 a 10) *</label><select name="nota" required><option value="">—</option><?php for ($i = 10; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= (int)($c['nota'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
            <?php foreach ($aspectos as $k => $txt): ?>
              <div><label><?= e($txt) ?> (1-5)</label><select name="<?= $k ?>"><option value="">—</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= (int)($c[$k] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
            <?php endforeach; ?>
            <div class="campo-ancho"><label>Comentario</label><input type="text" name="comentario" maxlength="1000" value="<?= e($c['comentario'] ?? '') ?>"></div>
          </div>
          <?php if ($c): ?><p class="ayuda">Última calificación por <?= e($c['calificado_por']) ?> (<?= e($c['creado']) ?>)</p><?php endif; ?>
          <button class="btn btn-sec"><?= $c ? 'Actualizar calificación' : 'Guardar calificación' ?></button>
        </form>
        <?php
    }
    return (string)ob_get_clean();
}

/* ============================================================
 * CARNET DIGITAL CON QR
 * ============================================================ */

/** Código secreto del carnet del jugador (se crea la primera vez). */
function carnet_token(array $j): string
{
    if (!empty($j['carnet_token'])) return $j['carnet_token'];
    do {
        $t = bin2hex(random_bytes(10));
    } while (valor('SELECT id FROM jugadores WHERE carnet_token = ?', [$t]));
    q('UPDATE jugadores SET carnet_token = ? WHERE id = ?', [$t, $j['id']]);
    return $t;
}

function url_carnet(array $j): string
{
    return url_base() . 'carnet.php?c=' . carnet_token($j);
}

/** Cédula parcialmente oculta para mostrar en páginas públicas. */
function cedula_oculta(string $c): string
{
    $c = trim($c);
    if ($c === '') return '';
    return str_repeat('•', max(0, strlen($c) - 4)) . substr($c, -4);
}

/**
 * Estado del jugador para jugar HOY: activo, suspendido en algún campeonato en curso de su equipo,
 * y deuda del equipo (si el campeonato bloquea o avisa por deuda).
 * Devuelve ['estado' => 'habilitado'|'suspendido'|'baja', 'detalle' => [...], 'deuda' => [...]]
 */
function estado_jugador(array $j): array
{
    if (!$j['activo']) return ['estado' => 'baja', 'detalle' => ['Dado de baja por la liga'], 'deuda' => []];
    $det = [];
    $deuda = [];
    $torneos = filas("SELECT t.* FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$j['equipo_id']]);
    foreach ($torneos as $t) {
        $s = suspensiones((int)$t['id'])[(int)$j['id']] ?? null;
        if ($s) $det[] = $t['nombre'] . ': suspendido ' . (int)$s['pendientes'] . ' partido(s) — ' . implode('; ', $s['motivos']);
        if ($t['bloqueo_deuda'] !== 'no') {
            $saldo = saldo_equipo((int)$j['equipo_id']);
            if ($saldo > (float)$t['deuda_maxima'] + 0.001) $deuda[] = ['torneo' => $t['nombre'], 'saldo' => $saldo, 'modo' => $t['bloqueo_deuda']];
        }
    }
    return ['estado' => $det ? 'suspendido' : 'habilitado', 'detalle' => $det, 'deuda' => $deuda];
}

/* ============================================================
 * FINANZAS
 * Cargos automáticos (se crean solos, una sola vez):
 *   - Inscripción de cada equipo al campeonato (cuota del campeonato)
 *   - Arbitraje por partido jugado (cada equipo paga su parte; en W.O. paga todo el que no se presentó)
 *   - Multas de jugadores (se cargan a su equipo) y multas a equipos
 * Pagos, cargos manuales y egresos los registra el tesorero.
 * ============================================================ */

function conceptos_cargo(): array
{
    return ['inscripcion' => 'Inscripción', 'arbitraje' => 'Arbitraje', 'multa' => 'Multa', 'garantia' => 'Garantía', 'carnet' => 'Carnets', 'pase' => 'Pase de jugador', 'reserva' => 'Alquiler de cancha (reserva)', 'otro' => 'Otro'];
}

function conceptos_egreso(): array
{
    // (v18) Las categorías de gasto están en contabilidad.php (ligas y escuelas comparten las claves)
    return categorias_gasto('liga') + categorias_gasto('escuela');
}

function formas_pago(): array
{
    return ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'deposito' => 'Depósito', 'tarjeta' => 'Tarjeta / Plux', 'payphone' => 'Tarjeta / Payphone', 'otro' => 'Otro']; // (TuAcademia) DeUna y Payphone
}

/** Crea los cargos automáticos que falten de un campeonato y quita los que ya no corresponden. */
function sincronizar_cargos(int $torneo_id): void
{
    $t = reglas_torneo($torneo_id);
    if (!$t) return;
    $deseados = [];
    $agregar = function (string $ref, int $equipo, string $concepto, string $desc, float $monto, string $fecha) use (&$deseados, $t) {
        if ($monto <= 0) return;
        $deseados[$ref] = [$equipo, $concepto, $desc, round($monto, 2), $fecha ?: date('Y-m-d')];
    };
    if ((float)$t['cuota_inscripcion'] > 0) {
        foreach (filas('SELECT equipo_id FROM torneo_equipos WHERE torneo_id = ?', [$torneo_id]) as $r) {
            $agregar("insc:$torneo_id:{$r['equipo_id']}", (int)$r['equipo_id'], 'inscripcion', 'Inscripción ' . $t['nombre'], (float)$t['cuota_inscripcion'], substr($t['creado'], 0, 10));
        }
    }
    if ((float)$t['arbitraje_por_equipo'] > 0) {
        $ps = filas("SELECT p.*, el.nombre AS ln, ev.nombre AS vn FROM partidos p JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                     WHERE p.torneo_id = ? AND p.estado IN ('jugado','wo')", [$torneo_id]);
        foreach ($ps as $p) {
            $desc = 'Arbitraje ' . $p['ln'] . ' vs ' . $p['vn'] . ($p['fecha'] ? ' (' . $p['fecha'] . ')' : '');
            $m = (float)$t['arbitraje_por_equipo'];
            if ($p['estado'] === 'wo' && $p['wo_ganador']) {
                $perdedor = (int)$p['wo_ganador'] === (int)$p['local_id'] ? (int)$p['visitante_id'] : (int)$p['local_id'];
                $agregar("arb:{$p['id']}:$perdedor", $perdedor, 'arbitraje', $desc . ' — W.O.', $m * 2, $p['fecha']);
            } else {
                $agregar("arb:{$p['id']}:{$p['local_id']}", (int)$p['local_id'], 'arbitraje', $desc, $m, $p['fecha']);
                $agregar("arb:{$p['id']}:{$p['visitante_id']}", (int)$p['visitante_id'], 'arbitraje', $desc, $m, $p['fecha']);
            }
        }
    }
    foreach (filas('SELECT s.*, j.equipo_id, j.nombres, j.apellidos FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id WHERE s.torneo_id = ? AND s.multa > 0', [$torneo_id]) as $s) {
        $agregar("multa_j:{$s['id']}", (int)$s['equipo_id'], 'multa', 'Multa a ' . trim($s['nombres'] . ' ' . $s['apellidos']) . ($s['motivo'] ? ': ' . $s['motivo'] : ''), (float)$s['multa'], $s['desde']);
    }
    foreach (filas('SELECT * FROM sanciones_equipo WHERE torneo_id = ? AND multa > 0', [$torneo_id]) as $s) {
        $agregar("multa_e:{$s['id']}", (int)$s['equipo_id'], 'multa', 'Multa al equipo' . ($s['motivo'] ? ': ' . $s['motivo'] : ''), (float)$s['multa'], $s['fecha']);
    }
    $existentes = array_column(filas("SELECT ref FROM movimientos WHERE torneo_id = ? AND tipo = 'cargo' AND ref IS NOT NULL", [$torneo_id]), 'ref');
    foreach ($deseados as $ref => [$eq, $conc, $desc, $monto, $fecha]) {
        if (in_array($ref, $existentes, true)) continue;
        q("INSERT OR IGNORE INTO movimientos (liga_id, torneo_id, equipo_id, tipo, concepto, descripcion, monto, fecha, ref, registrado_por)
           VALUES (?,?,?, 'cargo', ?,?,?,?,?, 'Automático')", [$t['liga_id'], $torneo_id, $eq, $conc, mb_substr($desc, 0, 200), $monto, $fecha, $ref]);
    }
    // Cargos automáticos cuyo origen ya no existe (partido anulado, sanción quitada, equipo retirado)
    foreach ($existentes as $ref) {
        if (!isset($deseados[$ref])) {
            q("DELETE FROM movimientos WHERE ref = ? AND tipo = 'cargo' AND anulado = 0", [$ref]);
        }
    }
}

function sincronizar_liga(int $liga_id): void
{
    foreach (filas('SELECT id FROM torneos WHERE liga_id = ?', [$liga_id]) as $t) {
        sincronizar_cargos((int)$t['id']);
    }
}

/** Saldo del equipo: lo que debe (positivo) o su saldo a favor (negativo). */
function saldo_equipo(int $equipo_id): float
{
    return round((float)valor("SELECT COALESCE(SUM(CASE WHEN tipo = 'cargo' THEN monto WHEN tipo = 'pago' THEN -monto ELSE 0 END), 0)
                                FROM movimientos WHERE equipo_id = ? AND anulado = 0", [$equipo_id]), 2);
}

/** Registra un pago y le asigna número de recibo. Devuelve el id del movimiento. */
function registrar_pago(int $liga_id, ?int $torneo_id, int $equipo_id, float $monto, string $concepto, string $desc, string $forma, string $comprobante, string $fecha, string $por, ?string $ref = null): int
{
    if ($monto <= 0) throw new RuntimeException('El valor del pago debe ser mayor a cero.');
    $pdo = db();
    $pdo->beginTransaction();
    $num = (int)valor('SELECT recibo_siguiente FROM ligas WHERE id = ?', [$liga_id]);
    q('UPDATE ligas SET recibo_siguiente = recibo_siguiente + 1 WHERE id = ?', [$liga_id]);
    q("INSERT INTO movimientos (liga_id, torneo_id, equipo_id, tipo, concepto, descripcion, monto, fecha, forma_pago, comprobante, recibo, ref, registrado_por)
       VALUES (?,?,?, 'pago', ?,?,?,?,?,?,?,?,?)", [$liga_id, $torneo_id, $equipo_id, $concepto, mb_substr($desc, 0, 200), round($monto, 2), $fecha, $forma, mb_substr($comprobante, 0, 100), $num, $ref, $por]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    return $id;
}

/* ============================================================
 * INSCRIPCIÓN EN LÍNEA (delegados)
 * ============================================================ */

/** Valida una cédula ecuatoriana (10 dígitos, provincia y dígito verificador). */
function cedula_ec_valida(string $c): bool
{
    if (!preg_match('/^\d{10}$/', $c)) return false;
    $prov = (int)substr($c, 0, 2);
    if (($prov < 1 || $prov > 24) && $prov !== 30) return false;
    if ((int)$c[2] > 5) return false;
    $suma = 0;
    for ($i = 0; $i < 9; $i++) {
        $d = (int)$c[$i] * ($i % 2 === 0 ? 2 : 1);
        $suma += $d > 9 ? $d - 9 : $d;
    }
    $verif = (10 - $suma % 10) % 10;
    return $verif === (int)$c[9];
}

/** Revisa la cédula o pasaporte. Devuelve '' si está bien o el mensaje de error. */
function error_documento(string $c): string
{
    if ($c === '') return 'La cédula es obligatoria.';
    if (preg_match('/^\d{10}$/', $c)) return cedula_ec_valida($c) ? '' : 'La cédula ' . $c . ' no es válida (revise los números).';
    if (preg_match('/^[A-Za-z0-9-]{5,20}$/', $c)) return ''; // pasaporte de extranjeros
    return 'Escriba la cédula (10 números) o el pasaporte sin espacios.';
}

/** Campeonato activo del equipo con inscripción de jugadores en línea abierta (o null). */
function inscripcion_abierta(int $equipo_id): ?array
{
    return fila("SELECT t.* FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id
                 WHERE te.equipo_id = ? AND t.estado <> 'finalizado' AND t.jugadores_hasta <> '' AND t.jugadores_hasta >= ?
                 ORDER BY t.jugadores_hasta DESC LIMIT 1", [$equipo_id, date('Y-m-d')]);
}

/** Cupo del equipo: [máximo, activos, solicitudes pendientes, libres]. */
function cupo_equipo(int $equipo_id, array $liga): array
{
    $mx = limites_plantilla_equipo($equipo_id, $liga)[1];
    $act = (int)valor('SELECT COUNT(*) FROM jugadores WHERE equipo_id = ? AND activo = 1', [$equipo_id]);
    $pend = (int)valor("SELECT COUNT(*) FROM solicitudes_jugador WHERE equipo_id = ? AND tipo = 'alta' AND estado = 'pendiente'", [$equipo_id]);
    return [$mx, $act, $pend, max(0, $mx - $act - $pend)];
}

/** Igual que en el panel: límites de plantilla del campeonato activo del equipo (o de la liga). */
function limites_plantilla_equipo(int $equipo_id, array $liga): array
{
    $r = fila("SELECT MAX(t.min_plantilla) AS mn, MAX(t.max_plantilla) AS mx FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id
               WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$equipo_id]);
    if ($r && $r['mx'] !== null) return [(int)$r['mn'], (int)$r['mx']];
    return [(int)$liga['min_plantilla'], (int)$liga['max_plantilla']];
}

/** ¿La cédula ya está en uso en la liga? Devuelve el texto del problema o ''. */
function cedula_ocupada(string $cedula, int $liga_id, int $excluir_solicitud = 0): string
{
    $j = fila('SELECT j.id, e.nombre FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.cedula = ? AND j.activo = 1', [$liga_id, $cedula]);
    if ($j) return 'Esa cédula ya está inscrita en el equipo ' . $j['nombre'] . '. Si cambia de equipo, debe pedir el pase a la liga.';
    $s = fila("SELECT e.nombre FROM solicitudes_jugador s JOIN equipos e ON e.id = s.equipo_id WHERE e.liga_id = ? AND s.cedula = ? AND s.estado = 'pendiente' AND s.tipo = 'alta' AND s.id <> ?", [$liga_id, $cedula, $excluir_solicitud]);
    if ($s) return 'Ya hay una solicitud pendiente con esa cédula (equipo ' . $s['nombre'] . ').';
    return '';
}

/** Teléfono de Ecuador en formato para WhatsApp (09xxxxxxxx → 5939xxxxxxxx). */
function telefono_wa(string $t): string
{
    $d = preg_replace('/\D/', '', $t);
    if (preg_match('/^0(9\d{8})$/', $d, $m)) return '593' . $m[1];
    return strlen($d) >= 10 ? $d : '';
}
