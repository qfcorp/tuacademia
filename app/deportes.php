<?php
/*
 * VERSIÓN 7 — Otros deportes: indor, ecuavóley y básquet (además del fútbol).
 *
 * El deporte sale de la MODALIDAD del campeonato (no hay que configurar nada más):
 *   F5 … F11, OTRO → fútbol     INDOR → indor (fútbol sala)
 *   ECUA → ecuavóley (se juega por sets)     BASQ / BASQ3 → básquet (puntos)
 *
 * Para no duplicar el sistema, los "goles" guardados en el partido (goles_local / goles_visitante) son:
 *   fútbol e indor → goles · básquet → puntos · ecuavóley → sets ganados (el detalle de cada set va en partido_sets).
 * Las tarjetas se guardan igual en todos los deportes ('amarilla' y 'roja'); en básquet se muestran como
 * "Falta técnica" y "Expulsión", así las suspensiones, la tabla de tarjetas y el fair play sirven para todos.
 */

/** Deporte de una modalidad (o de un campeonato/liga con columna modalidad). */
function deporte_de($x): string
{
    $m = is_array($x) ? (string)($x['modalidad'] ?? 'F7') : (string)$x;
    return match ($m) {
        'INDOR' => 'indor',
        'ECUA' => 'ecuavoley',
        'VOLEY' => 'ecuavoley', // (v26) vóley 6×6: el mismo motor por sets que el ecuavóley (otras reglas)
        'BASQ', 'BASQ3' => 'basquet',
        'BALON' => 'balonmano', // (v36) balonmano: motor de goles con exclusiones de 2 minutos
        'BEISB', 'SOFTB' => 'beisbol', // (v36) béisbol y softbol: carreras por entrada, hits y errores
        'AUTOX' => 'autocross', // (v22) campeonatos de carreras: ver carreras.php
        'CICLO', 'MTB', 'PISTA', 'BMX', 'BMXFS' => 'ciclismo', // (v34) ruta y MTB en carreras.php; pista y BMX en pruebas.php
        'TENIS', 'PADEL', 'TMESA', 'SQUASH' => 'raqueta', // (v25) duelos: ver duelos.php (v36: squash)
        'AJED' => 'ajedrez', // (v36)
        'ESPOR' => 'esports', // (v36)
        'MARC' => 'marciales',
        'BOXEO' => 'boxeo',
        'BOLOS' => 'bolos', // (v25) ver bolos.php
        'NATAC' => 'natacion', // (v26) ver pruebas.php
        'ATLET', 'RUTA' => 'atletismo', // (v36) carreras de ruta, trail y cross: módulo de atletismo
        'PESAS' => 'pesas', // (v36)
        'TRIAT' => 'triatlon', // (v36)
        default => 'futbol',
    };
}

function nombre_deporte(string $d): string
{
    return ['futbol' => 'Fútbol', 'indor' => 'Indor', 'ecuavoley' => 'Ecuavóley', 'basquet' => 'Básquet', 'autocross' => 'Automovilismo', 'ciclismo' => 'Ciclismo',
        'raqueta' => 'Deportes de raqueta', 'ajedrez' => 'Ajedrez', 'esports' => 'E-sports', 'pesas' => 'Levantamiento de pesas', 'triatlon' => 'Triatlón', 'balonmano' => 'Balonmano', 'beisbol' => 'Béisbol y softbol', 'marciales' => 'Artes marciales', 'boxeo' => 'Boxeo', 'bolos' => 'Bolos', 'natacion' => 'Natación', 'atletismo' => 'Atletismo'][$d] ?? $d;
}

function icono_deporte(string $d): string
{
    return ['balonmano' => '🤾', 'beisbol' => '⚾', 'ajedrez' => '♟️', 'esports' => '🎮', 'pesas' => '🏋️', 'triatlon' => '🏊', 'futbol' => '⚽', 'indor' => '⚽', 'ecuavoley' => '🏐', 'basquet' => '🏀', 'autocross' => '🏎', 'ciclismo' => '🚴', 'raqueta' => '🎾', 'marciales' => '🥋', 'boxeo' => '🥊', 'bolos' => '🎳', 'natacion' => '🏊', 'atletismo' => '🏃'][$d] ?? '⚽';
}

/** Textos que cambian según el deporte. */
function textos_deporte($x): array
{
    $d = in_array($x, ['futbol', 'indor', 'ecuavoley', 'basquet'], true) ? $x : deporte_de($x);
    $base = [
        'deporte' => $d, 'icono' => icono_deporte($d), 'gol' => 'Gol', 'goles' => 'Goles', 'goleadores' => 'Goleadores',
        'goleador' => 'Goleador', 'gf' => 'GF', 'gc' => 'GC', 'dg' => 'DG', 'a_favor' => 'goles a favor', 'en_contra' => 'goles en contra',
        'amarilla' => 'Amarilla', 'roja' => 'Roja', 'amarillas' => 'amarillas', 'tarjetas' => 'Tarjetas',
        'periodo' => 'tiempo', 'periodos' => 'tiempos', 'con_goleadores' => true, 'con_reloj' => true, 'con_empate' => true,
        'marcador' => 'goles', 'registrar' => 'Registrar gol o tarjeta', 'valla' => true,
    ];
    return match ($d) {
        'basquet' => array_merge($base, [
            'gol' => 'Canasta', 'goles' => 'Puntos', 'goleadores' => 'Anotadores', 'goleador' => 'Máximo anotador', 'gf' => 'PF', 'gc' => 'PC', 'dg' => 'Dif',
            'a_favor' => 'puntos a favor', 'en_contra' => 'puntos en contra', 'amarilla' => 'Falta técnica', 'roja' => 'Expulsión',
            'amarillas' => 'faltas técnicas', 'tarjetas' => 'Faltas técnicas y expulsiones', 'periodo' => 'cuarto', 'periodos' => 'cuartos',
            'con_empate' => false, 'marcador' => 'puntos', 'registrar' => 'Registrar canasta o falta', 'valla' => false,
        ]),
        'balonmano' => array_merge($base, [
            'amarilla' => 'Amonestación', 'roja' => 'Descalificación', 'tarjetas' => 'Amonestaciones, exclusiones y descalificaciones', 'registrar' => 'Registrar gol, exclusión o tarjeta',
        ]),
        'beisbol' => array_merge($base, [
            'gol' => 'Carrera', 'goles' => 'Carreras', 'goleadores' => 'Anotadores de carreras', 'goleador' => 'Más carreras', 'gf' => 'CA', 'gc' => 'CR', 'dg' => 'Dif',
            'a_favor' => 'carreras anotadas', 'en_contra' => 'carreras recibidas', 'amarilla' => 'Advertencia', 'roja' => 'Expulsión', 'amarillas' => 'advertencias',
            'tarjetas' => 'Advertencias y expulsiones', 'periodo' => 'entrada', 'periodos' => 'entradas', 'con_reloj' => false, 'con_empate' => false,
            'marcador' => 'carreras', 'registrar' => 'Registrar carrera, hit o error', 'valla' => false,
        ]),
        'ecuavoley' => array_merge($base, [
            'gol' => 'Set', 'goles' => 'Sets', 'goleadores' => '', 'goleador' => '', 'gf' => 'SG', 'gc' => 'SP', 'dg' => 'DS',
            'a_favor' => 'sets ganados', 'en_contra' => 'sets perdidos', 'periodo' => 'set', 'periodos' => 'sets',
            'con_goleadores' => false, 'con_reloj' => false, 'con_empate' => false, 'marcador' => 'sets', 'registrar' => 'Registrar tarjeta', 'valla' => false,
        ]),
        default => $base,
    };
}

/** Tipos de evento que se pueden registrar en un campeonato (según su modalidad). */
function tipos_evento_de($x): array
{
    $m = is_array($x) ? (string)($x['modalidad'] ?? 'F7') : (string)$x;
    return match (deporte_de($m)) {
        'basquet' => $m === 'BASQ3' ? ['p1', 'p2', 'asistencia', 'falta', 'amarilla', 'roja'] : ['p1', 'p2', 'p3', 'asistencia', 'falta', 'amarilla', 'roja'],
        'ecuavoley' => ['amarilla', 'roja'],
        'balonmano' => ['gol', 'penal', 'asistencia', 'amarilla', 'exclusion', 'roja'],
        'beisbol' => ['carrera', 'jonron', 'hit', 'error', 'ponche', 'amarilla', 'roja'],
        default => ['gol', 'penal', 'autogol', 'asistencia', 'amarilla', 'roja'],
    };
}

/** Puntos que suma cada evento al marcador de su propio equipo (el autogol suma al rival). */
function valor_evento(string $tipo): int
{
    return ['gol' => 1, 'penal' => 1, 'p1' => 1, 'p2' => 2, 'p3' => 3, 'carrera' => 1, 'jonron' => 1][$tipo] ?? 0;
}

/** Expresión SQL con el valor de un evento (para sumar goles o puntos). */
function sql_valor_evento(string $alias = 'ev'): string
{
    return "(CASE $alias.tipo WHEN 'gol' THEN 1 WHEN 'penal' THEN 1 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 WHEN 'p3' THEN 3 WHEN 'carrera' THEN 1 WHEN 'jonron' THEN 1 ELSE 0 END)";
}

/** Tipos que anotan (goles o canastas). */
function tipos_anotacion(): array
{
    return ['gol', 'penal', 'p1', 'p2', 'p3', 'carrera', 'jonron'];
}

function es_anotacion(string $tipo): bool
{
    return in_array($tipo, ['gol', 'penal', 'autogol', 'p1', 'p2', 'p3', 'carrera', 'jonron'], true);
}

/** Botones de la vocalía según el deporte: [tipo, texto, clase css, confirmación]. */
function botones_evento($reglas): array
{
    $b = [
        'gol' => ['⚽ GOL', 'b-gol', ''], 'penal' => ['⚽ PENAL', 'b-pen', ''], 'autogol' => ['AUTOGOL', 'b-ag', '¿Autogol? Se sumará al equipo contrario.'],
        'amarilla' => ['🟨 AMARILLA', 'b-am', ''], 'roja' => ['🟥 ROJA', 'b-ro', '¿Registrar tarjeta ROJA?'],
        'asistencia' => ['🅰 ASISTENCIA', 'b-asis', ''],
    ];
    if (deporte_de($reglas) === 'basquet') {
        $m3 = ($reglas['modalidad'] ?? '') === 'BASQ3';
        $b = [
            'p1' => ['🏀 +1', 'b-gol', ''], 'p2' => [$m3 ? '🏀 +2 (fuera del arco)' : '🏀 +2', 'b-gol', ''], 'p3' => ['🏀 +3 TRIPLE', 'b-gol', ''],
            'falta' => ['✋ FALTA', 'b-pen', ''], 'amarilla' => ['T  TÉCNICA', 'b-am', ''], 'roja' => ['⛔ EXPULSIÓN', 'b-ro', '¿Registrar EXPULSIÓN?'],
            'asistencia' => ['🅰 ASISTENCIA', 'b-asis', ''],
        ];
    }
    if (deporte_de($reglas) === 'balonmano') { // (v36)
        $b = ['gol' => ['🤾 GOL', 'b-gol', ''], 'penal' => ['🤾 GOL DE 7 M', 'b-pen', ''], 'asistencia' => ['🅰 ASISTENCIA', 'b-asis', ''],
            'amarilla' => ['🟨 AMONESTACIÓN', 'b-am', ''], 'exclusion' => ['⏱ 2 MINUTOS', 'b-pen', ''], 'roja' => ['🟥 DESCALIFICACIÓN', 'b-ro', '¿Registrar DESCALIFICACIÓN?']];
    } elseif (deporte_de($reglas) === 'beisbol') {
        $b = ['carrera' => ['⚾ CARRERA', 'b-gol', ''], 'jonron' => ['💥 JONRÓN', 'b-gol', ''], 'hit' => ['🏏 HIT', 'b-asis', ''], 'error' => ['❌ ERROR', 'b-pen', ''],
            'ponche' => ['K PONCHE', 'b-asis', ''], 'amarilla' => ['⚠ ADVERTENCIA', 'b-am', ''], 'roja' => ['⛔ EXPULSIÓN', 'b-ro', '¿Registrar EXPULSIÓN?']];
    }
    $out = [];
    foreach (tipos_evento_de($reglas) as $t) {
        if (isset($b[$t])) $out[] = [$t, $b[$t][0], $b[$t][1], $b[$t][2]];
    }
    return $out;
}

/** Opciones <option> del tipo de evento (panel e informe del árbitro). */
function opciones_tipo_evento($reglas): string
{
    $d = deporte_de($reglas);
    $h = '';
    foreach (tipos_evento_de($reglas) as $t) {
        $h .= '<option value="' . $t . '">' . e(strip_tags(icono_evento_txt($t, $d)) . ' ' . nombre_evento($t, $d)) . '</option>';
    }
    return $h;
}

function icono_evento_txt(string $t, string $d = ''): string
{
    if ($d === 'basquet' && $t === 'amarilla') return 'T';
    if ($d === 'basquet' && $t === 'roja') return '⛔';
    if ($d === 'balonmano' && in_array($t, ['gol', 'penal'], true)) return '🤾';
    return ['gol' => '⚽', 'penal' => '⚽', 'autogol' => '⚽', 'amarilla' => '🟨', 'roja' => '🟥', 'p1' => '🏀', 'p2' => '🏀', 'p3' => '🏀', 'falta' => '✋', 'asistencia' => '🅰',
        'exclusion' => '⏱', 'carrera' => '⚾', 'jonron' => '💥', 'hit' => '🏏', 'error' => '❌', 'ponche' => 'K'][$t] ?? '';
}

/** (v36) Minuto de un evento: "23'" o, en béisbol y softbol, la entrada ("3.ª"). */
function fmt_minuto_evento($min, string $dep): string
{
    if ($min === null || $min === '') return '';
    return $dep === 'beisbol' ? (int)$min . '.ª' : (int)$min . "'";
}

/**
 * (v36) Pizarra del béisbol / softbol: carreras por entrada (el «minuto» del evento es la entrada), hits y errores.
 * ['entradas' => n, 'L' => [entrada => carreras], 'V' => [...], 'R' => [l, v], 'H' => [l, v], 'E' => [l, v]]
 */
function linea_beisbol(array $p, array $reglas): array
{
    $n = max(1, (int)($reglas['num_tiempos'] ?? 9));
    $r = ['entradas' => $n, 'L' => [], 'V' => [], 'R' => [0, 0], 'H' => [0, 0], 'E' => [0, 0], 'sin_entrada' => [0, 0]];
    foreach (filas("SELECT equipo_id, tipo, minuto FROM eventos WHERE partido_id = ? AND tipo IN ('carrera','jonron','hit','error')", [$p['id']]) as $x) {
        $lado = (int)$x['equipo_id'] === (int)$p['local_id'] ? 0 : 1;
        $k = $lado === 0 ? 'L' : 'V';
        if (in_array($x['tipo'], ['carrera', 'jonron'], true)) {
            $r['R'][$lado]++;
            if ($x['minuto'] !== null && (int)$x['minuto'] > 0) { $e = (int)$x['minuto']; $r[$k][$e] = ($r[$k][$e] ?? 0) + 1; $r['entradas'] = max($r['entradas'], $e); }
            else $r['sin_entrada'][$lado]++;
        }
        if (in_array($x['tipo'], ['hit', 'jonron'], true)) $r['H'][$lado]++;
        if ($x['tipo'] === 'error') $r['E'][$lado]++;
    }
    return $r;
}

function html_linea_beisbol(array $p, array $reglas): string
{
    $l = linea_beisbol($p, $reglas);
    $h = '<div class="tabla-scroll"><table class="pizarra-beisbol" style="font-size:.85rem;text-align:center"><tr><th style="text-align:left">Equipo</th>';
    for ($e = 1; $e <= $l['entradas']; $e++) $h .= '<th>' . $e . '</th>';
    $h .= '<th>C</th><th>H</th><th>E</th></tr>';
    foreach ([[0, 'L', $p['local_nombre'] ?? 'Local'], [1, 'V', $p['visitante_nombre'] ?? 'Visitante']] as [$i, $k, $nom]) {
        $h .= '<tr><td style="text-align:left"><b>' . e($nom) . '</b></td>';
        for ($e = 1; $e <= $l['entradas']; $e++) $h .= '<td>' . (isset($l[$k][$e]) ? (int)$l[$k][$e] : '<span class="suave">0</span>') . '</td>';
        $h .= '<td><b>' . $l['R'][$i] . '</b></td><td>' . $l['H'][$i] . '</td><td>' . $l['E'][$i] . '</td></tr>';
    }
    return $h . '</table></div><p class="suave" style="font-size:.75rem;text-align:center">C = carreras · H = hits · E = errores</p>';
}

/* ============================================================
 * ECUAVÓLEY: SETS
 * ============================================================ */

function sets_partido(int $partido_id): array
{
    return filas('SELECT * FROM partido_sets WHERE partido_id = ? ORDER BY num', [$partido_id]);
}

/** Sets ganados por cada equipo (solo cuentan los sets cerrados). */
function sets_ganados(int $partido_id): array
{
    $l = 0;
    $v = 0;
    foreach (sets_partido($partido_id) as $s) {
        if (!(int)$s['cerrado']) continue;
        if ((int)$s['puntos_local'] > (int)$s['puntos_visitante']) $l++;
        elseif ((int)$s['puntos_visitante'] > (int)$s['puntos_local']) $v++;
    }
    return [$l, $v];
}

/** Texto con el detalle de sets: "15-12, 10-15, 15-9". */
function detalle_sets(int $partido_id): string
{
    $t = [];
    foreach (sets_partido($partido_id) as $s) {
        if ((int)$s['puntos_local'] + (int)$s['puntos_visitante'] === 0) continue;
        $t[] = (int)$s['puntos_local'] . '-' . (int)$s['puntos_visitante'];
    }
    return implode(', ', $t);
}

/** Puntos de un set para ganarlo (el set decisivo puede ser más corto). */
function puntos_para_set(array $reglas, int $num): int
{
    $maximo = max(1, (int)$reglas['sets_ganar']) * 2 - 1;
    return $num >= $maximo ? max(1, (int)$reglas['puntos_set_final']) : max(1, (int)$reglas['puntos_set']);
}

/** Guarda los sets escritos a mano (panel). $sets = [[pl, pv], ...] */
function guardar_sets(int $partido_id, array $sets): array
{
    q('DELETE FROM partido_sets WHERE partido_id = ?', [$partido_id]);
    $n = 0;
    foreach ($sets as [$pl, $pv]) {
        if ($pl === null || $pv === null || ($pl === 0 && $pv === 0)) continue;
        $n++;
        q('INSERT INTO partido_sets (partido_id, num, puntos_local, puntos_visitante, cerrado) VALUES (?,?,?,?,1)', [$partido_id, $n, $pl, $pv]);
    }
    return sets_ganados($partido_id);
}

/** Set que se está jugando en vivo (lo crea si no hay ninguno abierto). */
function set_actual(int $partido_id): array
{
    $s = fila('SELECT * FROM partido_sets WHERE partido_id = ? AND cerrado = 0 ORDER BY num DESC LIMIT 1', [$partido_id]);
    if ($s) return $s;
    $num = (int)valor('SELECT COALESCE(MAX(num), 0) FROM partido_sets WHERE partido_id = ?', [$partido_id]) + 1;
    q('INSERT INTO partido_sets (partido_id, num) VALUES (?, ?)', [$partido_id, $num]);
    return fila('SELECT * FROM partido_sets WHERE partido_id = ? AND num = ?', [$partido_id, $num]);
}

/* ============================================================
 * VALORES SUGERIDOS PARA LOS NUEVOS DEPORTES
 * ============================================================ */
function presets_otros_deportes(): array
{
    $sin = ['sets_ganar' => 2, 'puntos_set' => 15, 'puntos_set_final' => 15, 'faltas_limite' => 5];
    return [
        'INDOR' => $sin + ['max_cambios' => 0, 'num_tiempos' => 2, 'amarillas_suspension' => 3, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 5, 'min_para_jugar' => 3, 'max_suplentes' => 7, 'minutos_tiempo' => 20, 'min_plantilla' => 8, 'max_plantilla' => 14, 'num_arbitros' => 1,
            'puntos_ganado' => 3, 'puntos_empate' => 1, 'puntos_perdido' => 0, 'goles_wo' => 3],
        'ECUA' => ['sets_ganar' => 2, 'puntos_set' => 15, 'puntos_set_final' => 15, 'faltas_limite' => 0,
            'max_cambios' => 0, 'num_tiempos' => 3, 'amarillas_suspension' => 3, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 3, 'min_para_jugar' => 3, 'max_suplentes' => 2, 'minutos_tiempo' => 15, 'min_plantilla' => 3, 'max_plantilla' => 8, 'num_arbitros' => 1,
            'puntos_ganado' => 3, 'puntos_empate' => 0, 'puntos_perdido' => 0, 'goles_wo' => 2],
        'BASQ' => ['sets_ganar' => 2, 'puntos_set' => 15, 'puntos_set_final' => 15, 'faltas_limite' => 5,
            'max_cambios' => 0, 'num_tiempos' => 4, 'amarillas_suspension' => 2, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 5, 'min_para_jugar' => 5, 'max_suplentes' => 7, 'minutos_tiempo' => 10, 'min_plantilla' => 8, 'max_plantilla' => 15, 'num_arbitros' => 2,
            'puntos_ganado' => 2, 'puntos_empate' => 1, 'puntos_perdido' => 1, 'goles_wo' => 20],
        // (v26) Vóley 6×6: al mejor de 5 sets, sets a 25 y el quinto a 15
        'VOLEY' => ['sets_ganar' => 3, 'puntos_set' => 25, 'puntos_set_final' => 15, 'faltas_limite' => 0,
            'max_cambios' => 6, 'num_tiempos' => 5, 'amarillas_suspension' => 3, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 6, 'min_para_jugar' => 6, 'max_suplentes' => 6, 'minutos_tiempo' => 25, 'min_plantilla' => 6, 'max_plantilla' => 14, 'num_arbitros' => 2,
            'puntos_ganado' => 3, 'puntos_empate' => 0, 'puntos_perdido' => 0, 'goles_wo' => 3],
        'BASQ3' => ['sets_ganar' => 2, 'puntos_set' => 15, 'puntos_set_final' => 15, 'faltas_limite' => 0,
            'max_cambios' => 0, 'num_tiempos' => 1, 'amarillas_suspension' => 2, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 3, 'min_para_jugar' => 3, 'max_suplentes' => 1, 'minutos_tiempo' => 10, 'min_plantilla' => 4, 'max_plantilla' => 6, 'num_arbitros' => 1,
            'puntos_ganado' => 2, 'puntos_empate' => 1, 'puntos_perdido' => 1, 'goles_wo' => 21],
        // (v36) Balonmano (IHF): 7 en cancha, 2 tiempos de 30 minutos, cambios ilimitados, 2 puntos por ganar y 1 por empatar
        'BALON' => $sin + ['max_cambios' => 0, 'num_tiempos' => 2, 'amarillas_suspension' => 3, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 7, 'min_para_jugar' => 5, 'max_suplentes' => 9, 'minutos_tiempo' => 30, 'min_plantilla' => 7, 'max_plantilla' => 16, 'num_arbitros' => 2,
            'puntos_ganado' => 2, 'puntos_empate' => 1, 'puntos_perdido' => 0, 'goles_wo' => 10],
        // (v36) Béisbol: 9 entradas, sin empate (entradas extra), la tabla cuenta victorias; W.O. 9-0
        'BEISB' => $sin + ['max_cambios' => 0, 'num_tiempos' => 9, 'amarillas_suspension' => 3, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 9, 'min_para_jugar' => 9, 'max_suplentes' => 11, 'minutos_tiempo' => 15, 'min_plantilla' => 9, 'max_plantilla' => 25, 'num_arbitros' => 2,
            'puntos_ganado' => 1, 'puntos_empate' => 0, 'puntos_perdido' => 0, 'goles_wo' => 9],
        // (v36) Softbol: 7 entradas; W.O. 7-0
        'SOFTB' => $sin + ['max_cambios' => 0, 'num_tiempos' => 7, 'amarillas_suspension' => 3, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
            'jugadores_cancha' => 9, 'min_para_jugar' => 9, 'max_suplentes' => 11, 'minutos_tiempo' => 12, 'min_plantilla' => 9, 'max_plantilla' => 22, 'num_arbitros' => 2,
            'puntos_ganado' => 1, 'puntos_empate' => 0, 'puntos_perdido' => 0, 'goles_wo' => 7],
    ];
}

/** (v7) Opciones de modalidad agrupadas por deporte. */
function opciones_modalidad(string $sel = 'F7'): string
{
    $h = '';
    $grupos = [];
    // (v35) solo las modalidades de los módulos deportivos de la cuenta (y la que ya tiene elegida)
    $liga_m = function_exists('liga_trabajo') && function_exists('modalidad_permitida') ? liga_trabajo() : null;
    foreach (modalidades() as $m) if ($m === $sel || !$liga_m || modalidad_permitida($liga_m, $m)) $grupos[deporte_de($m)][] = $m;
    foreach ($grupos as $d => $ms) {
        $h .= '<optgroup label="' . e(icono_deporte($d) . ' ' . ($d === 'ecuavoley' ? 'Vóley y ecuavóley' : nombre_deporte($d))) . '">';
        foreach ($ms as $m) $h .= '<option value="' . $m . '"' . ($sel === $m ? ' selected' : '') . '>' . e(nombre_modalidad($m)) . ($liga_m && !modalidad_permitida($liga_m, $m) ? ' 🔒' : '') . '</option>';
        $h .= '</optgroup>';
    }
    return $h;
}
