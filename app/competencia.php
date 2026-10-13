<?php
/*
 * VERSIÓN 6 — Categorías por edad (con "pasados" autorizados), pases entre equipos,
 * historial (trayectoria, campeones, goleadores históricos) y programación automática.
 */

/* ============================================================
 * CATEGORÍAS POR EDAD
 * Cada campeonato puede ser de una categoría (Sub-12, Sub-17, Máster +40...):
 *   edad_min / edad_max  → edades permitidas (0 = sin límite)
 *   edad_corte           → fecha en la que se mide la edad (por defecto 31 de diciembre del año de la temporada,
 *                          que equivale a categorizar por AÑO DE NACIMIENTO)
 *   pasados_plantilla    → cuántos jugadores fuera de la edad ("pasados") puede inscribir cada equipo
 *   pasados_partido      → cuántos pasados pueden estar presentes en un mismo partido (0 = sin límite extra)
 *   pasados_max_anios    → cuántos años fuera de la edad puede tener un pasado (0 = sin límite)
 * ============================================================ */

function torneo_con_categoria(array $t): bool
{
    return (int)($t['edad_min'] ?? 0) > 0 || (int)($t['edad_max'] ?? 0) > 0;
}

function fecha_corte_torneo(array $t): string
{
    if (($t['edad_corte'] ?? '') !== '') return $t['edad_corte'];
    $anio = preg_match('/(19|20)\d{2}/', (string)($t['temporada'] ?? ''), $m) ? $m[0] : date('Y');
    return $anio . '-12-31';
}

function edad_en_fecha(string $nac, string $corte): ?int
{
    try {
        $a = new DateTime($nac);
        $b = new DateTime($corte);
        if ($a > $b) return 0;
        return $a->diff($b)->y;
    } catch (Exception) {
        return null;
    }
}

/** Texto de la categoría: "Sub-12 (hasta 12 años al 2026-12-31) · 2 pasados". */
function texto_categoria(array $t): string
{
    if (!torneo_con_categoria($t)) return 'Libre (sin límite de edad)';
    $mn = (int)$t['edad_min'];
    $mx = (int)$t['edad_max'];
    $rango = $mn > 0 && $mx > 0 ? "de $mn a $mx años" : ($mx > 0 ? "hasta $mx años" : "desde $mn años");
    $txt = ($t['categoria'] !== '' ? $t['categoria'] . ': ' : '') . $rango . ' (edad al ' . fecha_corte_torneo($t) . ')';
    $txt .= ' · pasados permitidos: ' . (int)$t['pasados_plantilla'] . ' por equipo';
    if ((int)$t['pasados_partido'] > 0) $txt .= ', ' . (int)$t['pasados_partido'] . ' por partido';
    if ((int)$t['pasados_max_anios'] > 0) $txt .= ', máximo ' . (int)$t['pasados_max_anios'] . ' año(s) fuera de la edad';
    return $txt;
}

/**
 * Situación de un jugador en la categoría del campeonato.
 * estado: libre | ok | pasado | fuera | sin_fecha
 */
function situacion_categoria(array $t, array $j): array
{
    if (!torneo_con_categoria($t)) return ['estado' => 'libre', 'edad' => null, 'exceso' => 0, 'texto' => ''];
    if (($j['fecha_nac'] ?? '') === '') return ['estado' => 'sin_fecha', 'edad' => null, 'exceso' => 0, 'texto' => 'Sin fecha de nacimiento'];
    $edad = edad_en_fecha($j['fecha_nac'], fecha_corte_torneo($t));
    if ($edad === null) return ['estado' => 'sin_fecha', 'edad' => null, 'exceso' => 0, 'texto' => 'Fecha de nacimiento no válida'];
    $mn = (int)$t['edad_min'];
    $mx = (int)$t['edad_max'];
    $exceso = 0;
    if ($mx > 0 && $edad > $mx) $exceso = $edad - $mx;
    if ($mn > 0 && $edad < $mn) $exceso = $mn - $edad;
    if ($exceso === 0) return ['estado' => 'ok', 'edad' => $edad, 'exceso' => 0, 'texto' => $edad . ' años'];
    $lim = (int)$t['pasados_max_anios'];
    if ($lim > 0 && $exceso > $lim) {
        return ['estado' => 'fuera', 'edad' => $edad, 'exceso' => $exceso, 'texto' => $edad . ' años: ' . $exceso . ' año(s) fuera de la categoría (máximo ' . $lim . ')'];
    }
    return ['estado' => 'pasado', 'edad' => $edad, 'exceso' => $exceso, 'texto' => $edad . ' años (pasado)'];
}

/** Etiqueta HTML corta para listas (PASADO, FUERA DE EDAD...). */
function etiqueta_categoria(array $sit): string
{
    return match ($sit['estado']) {
        'pasado' => ' <span class="etiqueta amarilla" title="' . e($sit['texto']) . '">PASADO ' . (int)$sit['edad'] . '</span>',
        'fuera' => ' <span class="etiqueta roja" title="' . e($sit['texto']) . '">FUERA DE EDAD</span>',
        'sin_fecha' => ' <span class="etiqueta gris">sin fecha nac.</span>',
        default => '',
    };
}

/** Campeonatos activos (no finalizados) de un equipo que tienen categoría por edad. */
function torneos_categoria_equipo(int $equipo_id): array
{
    return array_values(array_filter(
        filas("SELECT t.* FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$equipo_id]),
        'torneo_con_categoria'
    ));
}

/**
 * Revisa si un jugador puede estar en la plantilla del equipo según la categoría de sus campeonatos.
 * Lanza error si no puede. Devuelve notas (ej: "Pasado autorizado 2 de 3").
 * $j necesita fecha_nac; $excluir = id del jugador (al editar o transferir) para no contarlo dos veces.
 */
function validar_categoria_jugador(int $equipo_id, array $j, int $excluir = 0, bool $contar_solicitudes = false): array
{
    $notas = [];
    foreach (torneos_categoria_equipo($equipo_id) as $t) {
        $sit = situacion_categoria($t, $j);
        if ($sit['estado'] === 'sin_fecha') throw new RuntimeException('Falta la fecha de nacimiento: "' . $t['nombre'] . '" es un campeonato por categoría (' . texto_categoria($t) . ').');
        if ($sit['estado'] === 'fuera') throw new RuntimeException('No puede jugar "' . $t['nombre'] . '": ' . $sit['texto'] . '.');
        if ($sit['estado'] === 'pasado') {
            $pasados = 0;
            foreach (filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1 AND id <> ?', [$equipo_id, $excluir]) as $o) {
                if (situacion_categoria($t, $o)['estado'] === 'pasado') $pasados++;
            }
            if ($contar_solicitudes) {
                foreach (filas("SELECT * FROM solicitudes_jugador WHERE equipo_id = ? AND tipo = 'alta' AND estado = 'pendiente'", [$equipo_id]) as $o) {
                    if (situacion_categoria($t, $o)['estado'] === 'pasado') $pasados++;
                }
            }
            $max = (int)$t['pasados_plantilla'];
            if ($pasados >= $max) {
                throw new RuntimeException('No se puede inscribir: ' . nombre_jugador($j) . ' tiene ' . $sit['texto'] . ' y el equipo ya tiene ' . $pasados . ' pasado(s). En "' . $t['nombre'] . '" se permiten ' . $max . ' por equipo.');
            }
            $notas[] = 'Pasado autorizado en ' . $t['nombre'] . ' (' . ($pasados + 1) . ' de ' . $max . ')';
        }
    }
    return $notas;
}

/** Resumen por equipo de un campeonato con categoría: pasados, fuera de edad y sin fecha. */
function resumen_categoria_torneo(array $t): array
{
    if (!torneo_con_categoria($t)) return [];
    $res = [];
    foreach (filas('SELECT e.id, e.nombre FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id WHERE te.torneo_id = ? ORDER BY e.nombre', [$t['id']]) as $e) {
        $r = ['equipo' => $e, 'pasados' => [], 'fuera' => [], 'sin_fecha' => []];
        foreach (filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1', [$e['id']]) as $j) {
            $s = situacion_categoria($t, $j);
            $clave = ['pasado' => 'pasados', 'fuera' => 'fuera', 'sin_fecha' => 'sin_fecha'][$s['estado']] ?? null;
            if ($clave) $r[$clave][] = $j + ['sit' => $s];
        }
        $r['excede'] = count($r['pasados']) > (int)$t['pasados_plantilla'];
        $res[] = $r;
    }
    return $res;
}

/* ============================================================
 * PASES (transferencias de jugadores entre equipos de la liga)
 * ============================================================ */

/** Ejecuta un pase aprobado. Devuelve el id del pase. */
function ejecutar_pase(array $liga, array $j, int $destino_id, string $motivo, float $costo, string $por, ?int $pase_id = null, bool $forzar = false): int
{
    $lid = (int)$liga['id'];
    $destino = fila('SELECT * FROM equipos WHERE id = ? AND liga_id = ?', [$destino_id, $lid]);
    if (!$destino) throw new RuntimeException('Elija el equipo de destino.');
    if ((int)$j['equipo_id'] === $destino_id) throw new RuntimeException('El jugador ya pertenece a ese equipo.');
    if (!$j['activo']) throw new RuntimeException('El jugador está dado de baja. Actívelo antes de hacer el pase.');
    if (!$forzar) {
        $est = estado_jugador($j);
        if ($est['estado'] === 'suspendido') throw new RuntimeException('El jugador tiene una suspensión pendiente (' . implode(' ', $est['detalle']) . '). Debe cumplirla antes del pase, o marque "Autorizar aunque tenga suspensión".');
    }
    [, $max] = limites_plantilla_equipo($destino_id, $liga);
    $act = (int)valor('SELECT COUNT(*) FROM jugadores WHERE equipo_id = ? AND activo = 1', [$destino_id]);
    if ($act >= $max) throw new RuntimeException($destino['nombre'] . ' ya tiene el máximo de ' . $max . ' jugadores.');
    validar_categoria_jugador($destino_id, $j, (int)$j['id']);
    $numero = $j['numero'];
    if ($numero !== null && fila('SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ? AND activo = 1', [$destino_id, $numero])) $numero = null;
    $origen_id = (int)$j['equipo_id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE jugadores SET equipo_id = ?, numero = ? WHERE id = ?', [$destino_id, $numero, $j['id']]);
        if ($pase_id) {
            q("UPDATE pases SET estado = 'aprobado', costo = ?, revisado_por = ?, fecha = ?, respuesta = CASE WHEN respuesta = '' THEN 'Aprobado' ELSE respuesta END WHERE id = ?", [$costo, $por, date('Y-m-d'), $pase_id]);
        } else {
            q("INSERT INTO pases (liga_id, jugador_id, origen_id, destino_id, estado, motivo, costo, solicitado_por, revisado_por, fecha, usuario_id) VALUES (?,?,?,?, 'aprobado', ?,?,?,?,?,?)",
                [$lid, $j['id'], $origen_id, $destino_id, mb_substr($motivo, 0, 300), $costo, $por, $por, date('Y-m-d'), usuario()['id'] ?? null]);
            $pase_id = (int)$pdo->lastInsertId();
        }
        if ($costo > 0) {
            q("INSERT OR IGNORE INTO movimientos (liga_id, equipo_id, tipo, concepto, descripcion, monto, fecha, ref, registrado_por) VALUES (?,?, 'cargo', 'pase', ?,?,?,?,?)",
                [$lid, $destino_id, mb_substr('Pase de ' . nombre_jugador($j), 0, 200), round($costo, 2), date('Y-m-d'), 'pase:' . $pase_id, $por]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $origen = (string)valor('SELECT nombre FROM equipos WHERE id = ?', [$origen_id]);
    foreach ([$origen_id, $destino_id] as $eid) {
        aviso_delegados($liga, $eid, '🔁 <b>Pase aprobado</b>' . "\n" . e(nombre_jugador($j)) . ': ' . e($origen) . ' → ' . e($destino['nombre']) . ($costo > 0 ? "\nValor del pase: " . dinero($costo) . ' (cargo a ' . e($destino['nombre']) . ')' : ''), 'pase:' . $pase_id . ':' . $eid);
    }
    return $pase_id;
}

/* ============================================================
 * HISTORIAL: trayectoria del jugador, campeones y goleadores históricos
 * ============================================================ */

/** Por cada campeonato: equipo, partidos jugados (presentes), goles y tarjetas. */
function trayectoria_jugador(int $jid): array
{
    $rows = [];
    foreach (filas("SELECT p.torneo_id, a.equipo_id, COUNT(*) AS pj FROM alineaciones a JOIN partidos p ON p.id = a.partido_id
                    WHERE a.jugador_id = ? AND p.estado IN ('jugado','wo') GROUP BY p.torneo_id, a.equipo_id", [$jid]) as $r) {
        $rows[$r['torneo_id'] . ':' . $r['equipo_id']] = ['torneo_id' => (int)$r['torneo_id'], 'equipo_id' => (int)$r['equipo_id'], 'pj' => (int)$r['pj'], 'goles' => 0, 'amarillas' => 0, 'rojas' => 0];
    }
    foreach (filas("SELECT p.torneo_id, ev.equipo_id, SUM(" . sql_valor_evento('ev') . ") AS goles,
                           SUM(CASE WHEN ev.tipo = 'amarilla' THEN 1 ELSE 0 END) AS amarillas, SUM(CASE WHEN ev.tipo = 'roja' THEN 1 ELSE 0 END) AS rojas
                    FROM eventos ev JOIN partidos p ON p.id = ev.partido_id WHERE ev.jugador_id = ? AND p.estado IN ('jugado','wo') GROUP BY p.torneo_id, ev.equipo_id", [$jid]) as $r) {
        $k = $r['torneo_id'] . ':' . $r['equipo_id'];
        $rows[$k] = ($rows[$k] ?? ['torneo_id' => (int)$r['torneo_id'], 'equipo_id' => (int)$r['equipo_id'], 'pj' => 0]) + ['goles' => 0, 'amarillas' => 0, 'rojas' => 0];
        $rows[$k]['goles'] = (int)$r['goles'];
        $rows[$k]['amarillas'] = (int)$r['amarillas'];
        $rows[$k]['rojas'] = (int)$r['rojas'];
    }
    if (!$rows) return [];
    $tor = [];
    foreach (filas('SELECT id, nombre, temporada, categoria, campeon_id FROM torneos WHERE id IN (' . implode(',', array_unique(array_map(fn($r) => $r['torneo_id'], $rows))) . ')') as $t) $tor[(int)$t['id']] = $t;
    $eqs = [];
    foreach (filas('SELECT id, nombre, escudo, color FROM equipos WHERE id IN (' . implode(',', array_unique(array_map(fn($r) => $r['equipo_id'], $rows))) . ')') as $e) $eqs[(int)$e['id']] = $e;
    $out = [];
    foreach ($rows as $r) {
        if (!isset($tor[$r['torneo_id']])) continue;
        $r['torneo'] = $tor[$r['torneo_id']];
        $r['equipo'] = $eqs[$r['equipo_id']] ?? ['nombre' => '—', 'escudo' => '', 'color' => '#777'];
        $r['campeon'] = (int)$r['torneo']['campeon_id'] === $r['equipo_id'];
        $out[] = $r;
    }
    usort($out, fn($a, $b) => $b['torneo_id'] <=> $a['torneo_id']);
    return $out;
}

/** Pases del jugador (historial de equipos). */
function pases_jugador(int $jid): array
{
    return filas("SELECT ps.*, eo.nombre AS origen, ed.nombre AS destino FROM pases ps LEFT JOIN equipos eo ON eo.id = ps.origen_id JOIN equipos ed ON ed.id = ps.destino_id
                  WHERE ps.jugador_id = ? AND ps.estado = 'aprobado' ORDER BY ps.fecha DESC, ps.id DESC", [$jid]);
}

/** Ganador y perdedor de un partido cerrado (con penales). [ganador, perdedor] o null si empató sin penales. */
function ganador_partido(array $p): ?array
{
    if (!partido_cerrado($p) || $p['goles_local'] === null) return null;
    $gl = (int)$p['goles_local'] * 100 + (int)($p['penales_local'] ?? 0);
    $gv = (int)$p['goles_visitante'] * 100 + (int)($p['penales_visitante'] ?? 0);
    if ($gl === $gv) return null;
    return $gl > $gv ? [(int)$p['local_id'], (int)$p['visitante_id']] : [(int)$p['visitante_id'], (int)$p['local_id']];
}

/** Campeón y subcampeón sugeridos: el ganador de la final, o 1º y 2º de la tabla si no hubo final. */
function campeon_sugerido(int $tid): array
{
    $final = fila("SELECT * FROM partidos WHERE torneo_id = ? AND fase = 'final' AND estado IN ('jugado','wo') ORDER BY id DESC LIMIT 1", [$tid]);
    if ($final && ($g = ganador_partido($final))) return $g;
    $tabla = tabla_posiciones($tid);
    if (count($tabla) === 1) {
        $g = array_values($tabla)[0];
        if (count($g) >= 2 && $g[0]['pj'] > 0) return [(int)$g[0]['id'], (int)$g[1]['id']];
    }
    return [null, null];
}

/** Palmarés de la liga: campeonatos finalizados con campeón, subcampeón y goleador. */
function palmares_liga(int $lid): array
{
    $out = [];
    foreach (filas("SELECT t.*, c.nombre AS campeon, c.escudo AS campeon_escudo, c.color AS campeon_color, s.nombre AS subcampeon
                    FROM torneos t LEFT JOIN equipos c ON c.id = t.campeon_id LEFT JOIN equipos s ON s.id = t.subcampeon_id
                    WHERE t.liga_id = ? AND (t.estado = 'finalizado' OR t.campeon_id IS NOT NULL) ORDER BY t.id DESC", [$lid]) as $t) {
        $t['goleador'] = goleadores((int)$t['id'], 1)[0] ?? null;
        $out[] = $t;
    }
    return $out;
}

/** Títulos por equipo (cuántas veces campeón). */
function titulos_por_equipo(int $lid): array
{
    return filas("SELECT e.id, e.nombre, e.escudo, e.color, COUNT(*) AS titulos FROM torneos t JOIN equipos e ON e.id = t.campeon_id
                  WHERE t.liga_id = ? GROUP BY e.id ORDER BY titulos DESC, e.nombre", [$lid]);
}

/** Goleadores de todos los tiempos de la liga. */
function goleadores_historicos(int $lid, int $limite = 20): array
{
    // (v7) Solo se suman campeonatos del mismo deporte de la liga (no se mezclan goles con puntos de básquet)
    $dep = deporte_de(fila('SELECT modalidad FROM ligas WHERE id = ?', [$lid]) ?? []);
    $mods = array_values(array_filter(modalidades(), fn($m) => deporte_de($m) === $dep || ($dep === 'futbol' && deporte_de($m) === 'indor')));
    $in = implode(',', array_map(fn($m) => "'" . $m . "'", $mods));
    return filas("SELECT j.id, j.nombres, j.apellidos, j.foto, j.foto_publica, e.nombre AS equipo, e.escudo, e.color,
                         SUM(" . sql_valor_evento('ev') . ") AS goles, COUNT(DISTINCT p.torneo_id) AS campeonatos
                  FROM eventos ev JOIN partidos p ON p.id = ev.partido_id JOIN torneos t ON t.id = p.torneo_id
                  JOIN jugadores j ON j.id = ev.jugador_id JOIN equipos e ON e.id = j.equipo_id
                  WHERE t.liga_id = ? AND t.modalidad IN ($in) AND p.estado = 'jugado' AND ev.tipo IN ('gol','penal','p1','p2','p3') AND j.anonimizado = 0
                  GROUP BY j.id ORDER BY goles DESC, j.apellidos LIMIT " . (int)$limite, [$lid]);
}

/* ============================================================
 * PROGRAMACIÓN AUTOMÁTICA DE FECHAS, HORAS, CANCHAS Y ÁRBITROS
 * ============================================================ */

/** Grupos de partidos que se pueden programar: jornadas de la fase regular y fases eliminatorias. */
function bloques_programables(int $tid): array
{
    $b = [];
    foreach (filas("SELECT fase, jornada, COUNT(*) AS n, SUM(CASE WHEN fecha = '' OR hora = '' THEN 1 ELSE 0 END) AS sin_prog,
                           SUM(CASE WHEN estado IN ('jugado','wo','en_vivo') THEN 1 ELSE 0 END) AS jugados, MIN(fecha) AS fecha
                    FROM partidos WHERE torneo_id = ? GROUP BY fase, CASE WHEN fase = 'regular' THEN jornada ELSE 0 END
                    ORDER BY CASE fase WHEN 'regular' THEN 0 WHEN 'octavos' THEN 1 WHEN 'cuartos' THEN 2 WHEN 'semifinal' THEN 3 WHEN 'tercer' THEN 4 WHEN 'final' THEN 5 ELSE 6 END, jornada", [$tid]) as $r) {
        $clave = $r['fase'] === 'regular' ? 'j:' . (int)$r['jornada'] : 'f:' . $r['fase'];
        $b[$clave] = ['clave' => $clave, 'titulo' => $r['fase'] === 'regular' ? 'Jornada ' . (int)$r['jornada'] : nombre_fase($r['fase']),
            'n' => (int)$r['n'], 'sin_prog' => (int)$r['sin_prog'], 'jugados' => (int)$r['jugados'], 'fecha' => (string)$r['fecha']];
    }
    return $b;
}

function partidos_de_bloque(int $tid, string $clave): array
{
    if (preg_match('/^j:(\d+)$/', $clave, $m)) return partidos_torneo($tid, "AND p.fase = 'regular' AND p.jornada = " . (int)$m[1]);
    if (preg_match('/^f:([a-z]+)$/', $clave, $m) && in_array($m[1], fases(), true)) return partidos_torneo($tid, "AND p.fase = '" . $m[1] . "'");
    return [];
}

function minutos_de_hora(string $h): int
{
    [$a, $b] = array_map('intval', explode(':', $h . ':0'));
    return $a * 60 + $b;
}

function hora_de_minutos(int $m): string
{
    return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60);
}

/** Duración sugerida de cada turno: tiempos de juego + descanso + margen, redondeado a 5 minutos. */
function duracion_sugerida(array $t): int
{
    $d = (int)$t['num_tiempos'] * (int)$t['minutos_tiempo'] + (deporte_de($t) === 'beisbol' ? 0 : max(0, (int)$t['num_tiempos'] - 1) * 10) + 15; // (v36) en béisbol no hay descanso entre entradas
    return (int)(ceil($d / 5) * 5);
}

/**
 * Arma una propuesta de programación (NO guarda nada).
 * $o = [bloques[], fecha, dias_entre, canchas[], hora_desde, hora_hasta, duracion, incluir_programados, asignar_arbitros, arbitros[], evitar_repetir]
 * Devuelve ['filas' => [...], 'avisos' => [...]]; cada fila: partido, fecha, hora, cancha, arbitros [campo => [id, nombre]]
 */
function proponer_programacion(array $t, array $o): array
{
    $tid = (int)$t['id'];
    $lid = (int)$t['liga_id'];
    $dur = max(20, (int)$o['duracion']);
    $desde = minutos_de_hora($o['hora_desde']);
    $hasta = minutos_de_hora($o['hora_hasta']);
    if ($hasta < $desde) throw new RuntimeException('La hora del último partido debe ser después de la primera.');
    if (!$o['canchas']) throw new RuntimeException('Elija al menos una cancha.');
    if (!$o['bloques']) throw new RuntimeException('Elija qué jornadas o fases va a programar.');
    if (!strtotime($o['fecha'])) throw new RuntimeException('Elija la fecha.');
    $avisos = [];
    $filas_out = [];
    $ocupado_eq = [];   // equipo => [[fecha, inicio], ...] de esta propuesta
    $ocupado_arb = [];  // arbitro => [[fecha, inicio], ...]
    $carga = [];        // arbitro => partidos asignados en el campeonato
    foreach (filas("SELECT arbitro_id, asistente1_id, asistente2_id, cuarto_id FROM partidos WHERE torneo_id = ?", [$tid]) as $r) {
        foreach ($r as $v) if ($v) $carga[(int)$v] = ($carga[(int)$v] ?? 0) + 1;
    }
    $choca = fn(array $lista, string $f, int $ini) => (bool)array_filter($lista, fn($x) => $x[0] === $f && abs($x[1] - $ini) < $dur);

    // Historial de horarios de cada equipo (para repartir los turnos temprano/tarde)
    $sum_h = [];
    $n_h = [];
    $ids_nuevos = [];
    foreach ($o['bloques'] as $bq) foreach (partidos_de_bloque($tid, $bq) as $p) $ids_nuevos[(int)$p['id']] = true;
    foreach (filas("SELECT id, local_id, visitante_id, hora FROM partidos WHERE torneo_id = ? AND hora <> ''", [$tid]) as $r) {
        if (isset($ids_nuevos[(int)$r['id']])) continue;
        foreach ([(int)$r['local_id'], (int)$r['visitante_id']] as $e) {
            $sum_h[$e] = ($sum_h[$e] ?? 0) + minutos_de_hora($r['hora']);
            $n_h[$e] = ($n_h[$e] ?? 0) + 1;
        }
    }
    $prom = fn(int $e) => isset($n_h[$e]) ? $sum_h[$e] / $n_h[$e] : ($desde + $hasta) / 2;

    // Partidos del resto de la liga ya programados (otros campeonatos): los árbitros no pueden estar en dos lados
    $externos = filas("SELECT p.fecha, p.hora, p.arbitro_id, p.asistente1_id, p.asistente2_id, p.cuarto_id, p.local_id, p.visitante_id, p.id
                       FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.fecha <> '' AND p.hora <> '' AND p.estado NOT IN ('jugado','wo')", [$lid]);

    $arbitros = $o['asignar_arbitros'] ? filas('SELECT id, nombre FROM arbitros WHERE liga_id = ? AND activo = 1 AND id IN (' . (implode(',', array_map('intval', $o['arbitros'] ?: [0]))) . ') ORDER BY nombre', [$lid]) : [];
    if ($o['asignar_arbitros'] && !$arbitros) $avisos[] = 'No eligió árbitros disponibles: los partidos quedan sin árbitro.';
    $nombre_arb = array_column($arbitros, 'nombre', 'id');

    foreach (array_values($o['bloques']) as $k => $bq) {
        $fecha = date('Y-m-d', strtotime($o['fecha'] . ' +' . ($k * max(0, (int)$o['dias_entre'])) . ' days'));
        $ps = array_values(array_filter(partidos_de_bloque($tid, $bq), fn($p) => !in_array($p['estado'], ['jugado', 'wo', 'en_vivo'], true)
            && ($o['incluir_programados'] || $p['fecha'] === '' || $p['hora'] === '')));
        if (!$ps) continue;
        // Turnos disponibles: cada hora en cada cancha
        $turnos = [];
        for ($m = $desde; $m <= $hasta; $m += $dur) foreach (array_values($o['canchas']) as $ci => $c) $turnos[] = ['min' => $m, 'cancha' => $c, 'ci' => $ci];
        usort($turnos, fn($a, $b) => [$a['min'], $a['ci']] <=> [$b['min'], $b['ci']]);
        // Primero los equipos que venían jugando tarde (así les toca temprano), y viceversa
        usort($ps, fn($a, $b) => ($prom((int)$b['local_id']) + $prom((int)$b['visitante_id'])) <=> ($prom((int)$a['local_id']) + $prom((int)$a['visitante_id'])));
        // Árbitros que pitaron a cada equipo en el bloque anterior (para no repetir)
        $previos = [];
        if ($o['evitar_repetir'] && preg_match('/^j:(\d+)$/', $bq, $mm)) {
            foreach (filas("SELECT * FROM partidos WHERE torneo_id = ? AND fase = 'regular' AND jornada = ?", [$tid, (int)$mm[1] - 1]) as $r) {
                foreach (['arbitro_id', 'asistente1_id', 'asistente2_id', 'cuarto_id'] as $c) {
                    if ($r[$c]) { $previos[(int)$r['local_id']][(int)$r[$c]] = true; $previos[(int)$r['visitante_id']][(int)$r[$c]] = true; }
                }
            }
        }
        $usados = [];
        foreach ($ps as $p) {
            $l = (int)$p['local_id'];
            $v = (int)$p['visitante_id'];
            $elegido = null;
            foreach ($turnos as $i => $tu) {
                if (isset($usados[$i])) continue;
                if ($choca($ocupado_eq[$l] ?? [], $fecha, $tu['min']) || $choca($ocupado_eq[$v] ?? [], $fecha, $tu['min'])) continue;
                $choque_ext = false;
                foreach ($externos as $x) {
                    if (isset($ids_nuevos[(int)$x['id']]) || $x['fecha'] !== $fecha || abs(minutos_de_hora($x['hora']) - $tu['min']) >= $dur) continue;
                    if (in_array((int)$x['local_id'], [$l, $v], true) || in_array((int)$x['visitante_id'], [$l, $v], true)) { $choque_ext = true; break; }
                }
                if ($choque_ext) continue;
                $elegido = $i;
                break;
            }
            $fila = ['partido' => $p, 'fecha' => $fecha, 'hora' => '', 'cancha' => '', 'arbitros' => []];
            if ($elegido === null) {
                $avisos[] = 'No alcanzan los turnos para ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'] . ' (' . $fecha . '). Agregue canchas, amplíe el horario o prográmelo a mano.';
                $filas_out[] = $fila;
                continue;
            }
            $usados[$elegido] = true;
            $tu = $turnos[$elegido];
            $fila['hora'] = hora_de_minutos($tu['min']);
            $fila['cancha'] = $tu['cancha'];
            $ocupado_eq[$l][] = [$fecha, $tu['min']];
            $ocupado_eq[$v][] = [$fecha, $tu['min']];
            if ($arbitros) {
                $roles = roles_arbitro(num_arbitros_partido($p));
                $cands = [];
                foreach ($arbitros as $a) {
                    $aid = (int)$a['id'];
                    if ($choca($ocupado_arb[$aid] ?? [], $fecha, $tu['min'])) continue;
                    $ext = false;
                    foreach ($externos as $x) {
                        if (isset($ids_nuevos[(int)$x['id']]) || $x['fecha'] !== $fecha || abs(minutos_de_hora($x['hora']) - $tu['min']) >= $dur) continue;
                        if (in_array($aid, array_map('intval', [$x['arbitro_id'], $x['asistente1_id'], $x['asistente2_id'], $x['cuarto_id']]), true)) { $ext = true; break; }
                    }
                    if ($ext) continue;
                    $repite = isset($previos[$l][$aid]) || isset($previos[$v][$aid]) ? 1 : 0;
                    $cands[] = [$repite, $carga[$aid] ?? 0, mt_rand(0, 999), $aid];
                }
                sort($cands);
                foreach (array_keys($roles) as $idx => $campo) {
                    if (!isset($cands[$idx])) {
                        $avisos[] = 'Faltan árbitros libres para ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'] . ' a las ' . $fila['hora'] . ' (' . $roles[$campo] . ').';
                        break;
                    }
                    $aid = $cands[$idx][3];
                    $fila['arbitros'][$campo] = [$aid, $nombre_arb[$aid] ?? ''];
                    $ocupado_arb[$aid][] = [$fecha, $tu['min']];
                    $carga[$aid] = ($carga[$aid] ?? 0) + 1;
                }
            }
            $filas_out[] = $fila;
        }
    }
    usort($filas_out, fn($a, $b) => [$a['fecha'], $a['hora'] === '' ? '99' : $a['hora'], $a['cancha']] <=> [$b['fecha'], $b['hora'] === '' ? '99' : $b['hora'], $b['cancha']]);
    if (!$filas_out) $avisos[] = 'No hay partidos pendientes de programar en lo que eligió (marque "reprogramar también los que ya tienen fecha" si quiere cambiarlos).';
    return ['filas' => $filas_out, 'avisos' => array_values(array_unique($avisos))];
}
