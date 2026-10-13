<?php
/*
 * (v34) CICLISMO DE PISTA, BMX RACING y BMX FREESTYLE con las reglas de la UCI (sobre el motor de pruebas: pruebas.php)
 *
 * Cada prueba (prueba_eventos) tiene un FORMATO:
 *   marca        → contrarreloj (kilómetro, 500 m, 200 m lanzados, time trial de BMX) o, con medida «puesto», scratch y eliminación.
 *   puntos       → carrera por puntos: sprints 5-3-2-1 y el último (la llegada) doble 10-6-4-2; ±20 por vuelta ganada o perdida.
 *                  Empate: el puesto en el sprint final.
 *   tempo        → tempo: 1 punto al primero de cada sprint (cada vuelta); ±20 por vuelta. Empate: el sprint final.
 *   omnium       → scratch, tempo y eliminación dan 40-38-36… puntos (del 21.° en adelante, 1); la carrera por puntos final
 *                  suma o resta sobre lo acumulado. Empate: el sprint final de la carrera por puntos.
 *   velocidad    → clasificatoria de 200 m lanzados (tiempo) y cuadro de duelos al mejor de 3 mangas (1/4, semifinal, final y bronce).
 *   persecucion  → clasificatoria por tiempo; los 2 mejores corren la final (oro y plata) y el 3.° y 4.° el bronce.
 *   keirin       → series de hasta 7; pasan los mejores de cada serie hasta una final de 6.
 *   series       → BMX Racing: motos de hasta 8 con 3 mangas (puntos = puesto; gana el que suma menos); pasan los 4 mejores de
 *                  cada moto a 1/4, semifinales (una manga) y la final de 8. Con 8 o menos pilotos no hay final: vale la suma de las 3 motos.
 *                  DNF = puntos igual al número que largó; REL o DQ = los que largaron + 2; DNS = los de la moto + 2.
 *   jueces       → BMX Freestyle (park, flatland): jueces de 0 a 100 por pasada; clasificatoria = promedio de las pasadas y final = la mejor.
 *
 * Tablas: prueba_eventos.formato / .config (JSON), prueba_resultados.datos (JSON de puntos, vueltas, puestos del omnium),
 *         prueba_series (cuadros, motos y pasadas: fase, serie, manga, participante, puesto, estado, tiempo, datos).
 */

function pista_mods(): array
{
    return ['PISTA', 'BMX', 'BMXFS'];
}

function es_ciclo_pista($t): bool
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return in_array($m, pista_mods(), true);
}

/** Formatos que puede usar cada deporte: [formato => nombre]. */
function pista_formatos(string $mod): array
{
    if ($mod === 'PISTA') return ['marca' => 'Por tiempo o por puesto (contrarreloj, scratch, eliminación)', 'velocidad' => 'Velocidad (clasificatoria y duelos)', 'keirin' => 'Keirin (series y final)',
        'persecucion' => 'Persecución (clasificatoria y finales)', 'puntos' => 'Carrera por puntos', 'tempo' => 'Tempo', 'omnium' => 'Omnium'];
    if ($mod === 'BMX') return ['series' => 'BMX Racing (motos, rondas y final)', 'marca' => 'Contrarreloj (time trial)'];
    if ($mod === 'BMXFS') return ['jueces' => 'Puntuación de jueces (park, flatland)'];
    return ['marca' => 'Por marca'];
}

function pista_catalogo(string $m): array
{
    if ($m === 'PISTA') return [['Velocidad individual', 'tiempo', 'velocidad'], ['Keirin', 'tiempo', 'keirin'], ['Kilómetro contrarreloj', 'tiempo', 'marca'],
        ['500 m contrarreloj', 'tiempo', 'marca'], ['Persecución individual', 'tiempo', 'persecucion'], ['Scratch', 'puesto', 'marca'], ['Carrera por puntos', 'tiempo', 'puntos'],
        ['Eliminación', 'puesto', 'marca'], ['Tempo', 'tiempo', 'tempo'], ['Omnium', 'tiempo', 'omnium']];
    if ($m === 'BMX') return [['BMX Racing', 'tiempo', 'series'], ['Contrarreloj (time trial)', 'tiempo', 'marca']];
    if ($m === 'BMXFS') return [['Park', 'tiempo', 'jueces'], ['Flatland', 'tiempo', 'jueces']];
    return [];
}

function pista_categorias_base(string $m): array
{
    if ($m === 'PISTA') return [['Infantil (13 y 14)', 13, 14], ['Menores (15 y 16)', 15, 16], ['Júnior (17 y 18)', 17, 18], ['Sub-23 (19 a 22)', 19, 22], ['Élite (19+)', 19, 0], ['Máster (30+)', 30, 0]];
    if ($m === 'BMX') return [['Hasta 8 años', 0, 8], ['9 y 10 años', 9, 10], ['11 y 12 años', 11, 12], ['13 y 14 años', 13, 14], ['15 y 16 años', 15, 16], ['Júnior (17 y 18)', 17, 18],
        ['Élite (19+)', 19, 0], ['Cruiser 30+', 30, 0]];
    if ($m === 'BMXFS') return [['Hasta 14 años', 0, 14], ['15 a 18 años', 15, 18], ['Élite (19+)', 19, 0]];
    return [];
}

/** Reglas de la prueba (con valores por omisión de la UCI). */
function pista_cfg(array $ev): array
{
    $d = json_decode((string)($ev['config'] ?? ''), true);
    $d = is_array($d) ? $d : [];
    $f = (string)($ev['formato'] ?? 'marca');
    $base = match ($f) {
        'puntos' => ['sprints' => 10, 'pts_sprint' => '5,3,2,1', 'pts_final' => '10,6,4,2', 'pts_vuelta' => 20],
        'tempo' => ['sprints' => 20, 'pts_sprint' => '1', 'pts_final' => '1', 'pts_vuelta' => 20],
        'velocidad' => ['cuadro' => 8],
        'keirin' => ['por_serie' => 7, 'final' => 6, 'mangas' => 1, 'pasan' => 0],
        'series' => ['por_serie' => 8, 'final' => 8, 'mangas' => 3, 'pasan' => 4],
        'jueces' => ['jueces' => 3, 'descarte' => 0, 'pasadas' => 2, 'q_modo' => 'promedio', 'finalistas' => 8],
        default => [],
    };
    foreach ($base as $k => $v) if (isset($d[$k]) && $d[$k] !== '') $base[$k] = is_int($v) ? (int)$d[$k] : (string)$d[$k];
    return $base;
}

/** Lee del formulario la configuración del formato (para guardar en prueba_eventos.config). */
function pista_cfg_post(string $formato): string
{
    $lista = function (string $v, string $que): string {
        $v = trim($v);
        if ($v !== '' && !preg_match('/^\d+([\s,;]+\d+)*$/', $v)) throw new RuntimeException("$que: escriba números separados por comas. Ej: 5,3,2,1");
        return implode(',', ciclo_lista_num($v));
    };
    $c = match ($formato) {
        'puntos', 'tempo' => ['sprints' => entero(post('cfg_sprints'), 1, 200), 'pts_sprint' => $lista(post('cfg_pts_sprint'), 'Puntos por sprint'),
            'pts_final' => $lista(post('cfg_pts_final'), 'Puntos del sprint final'), 'pts_vuelta' => entero(post('cfg_pts_vuelta'), 0, 100)],
        'velocidad' => ['cuadro' => in_array((int)post('cfg_cuadro'), [2, 4, 8], true) ? (int)post('cfg_cuadro') : 8],
        'keirin', 'series' => ['por_serie' => entero(post('cfg_por_serie'), 3, 12), 'final' => entero(post('cfg_final'), 2, 12), 'mangas' => entero(post('cfg_mangas'), 1, 3),
            'pasan' => entero(post('cfg_pasan'), 0, 8)],
        'jueces' => ['jueces' => entero(post('cfg_jueces'), 1, 9), 'descarte' => post('cfg_descarte') === '1' ? 1 : 0, 'pasadas' => entero(post('cfg_pasadas'), 1, 4),
            'q_modo' => post('cfg_q_modo') === 'mejor' ? 'mejor' : 'promedio', 'finalistas' => entero(post('cfg_finalistas'), 0, 30)],
        default => [],
    };
    foreach ($c as $k => $v) if ($v === '') unset($c[$k]);
    return $c ? json_encode($c) : '';
}

function pista_titulo_columna(array $ev): string
{
    return match ((string)($ev['formato'] ?? 'marca')) {
        'puntos', 'tempo', 'omnium' => 'Puntos',
        'velocidad' => 'Clasificatoria 200 m / ronda',
        'persecucion' => 'Clasificatoria / final',
        'keirin', 'series' => 'Ronda',
        'jueces' => 'Puntaje',
        default => $ev['medida'] === 'metros' ? 'Marca' : ($ev['medida'] === 'puesto' ? 'Llegada' : 'Tiempo'),
    };
}

function pista_json(?string $s): array
{
    $d = json_decode((string)$s, true);
    return is_array($d) ? $d : [];
}

/** Puntos del omnium por puesto en scratch, tempo y eliminación (UCI: 40, 38, 36… del 21.° en adelante 1). */
function pista_pts_omnium(int $puesto): int
{
    if ($puesto <= 0) return 0;
    return $puesto <= 20 ? 42 - 2 * $puesto : 1;
}

/** Puntos de una carrera por puntos o tempo con los datos de un ciclista: [total, detalle]. */
function pista_pts_carrera(array $d, array $cfg): array
{
    $ps = ciclo_lista_num((string)$cfg['pts_sprint']);
    $pf = ciclo_lista_num((string)$cfg['pts_final']);
    $sp = 0;
    foreach ((array)($d['s'] ?? []) as $pos) if ((int)$pos > 0) $sp += $ps[(int)$pos - 1] ?? 0;
    $lleg = (int)($d['lleg'] ?? 0);
    $fin = $lleg > 0 ? ($pf[$lleg - 1] ?? 0) : 0;
    $vg = (int)($d['vg'] ?? 0);
    $vp = (int)($d['vp'] ?? 0);
    $total = $sp + $fin + ($vg - $vp) * (int)$cfg['pts_vuelta'];
    $det = [];
    if ($sp) $det[] = 'sprints ' . $sp;
    if ($fin) $det[] = 'llegada ' . $fin;
    if ($vg) $det[] = '+' . $vg . ' v';
    if ($vp) $det[] = '−' . $vp . ' v';
    return [$total, implode(' · ', $det)];
}

/* ============================================================
 * RESULTADOS (puesto y texto de cada inscrito)
 * ============================================================ */

/** Igual que prueba_resultados() pero con los formatos de ciclismo. Cada fila: la de prueba_resultados + p, puesto, texto, detalle. */
function pista_resultados(array $ev, array $part): array
{
    $f = (string)$ev['formato'];
    $cfg = pista_cfg($ev);
    $filas = [];
    foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) {
        if (!isset($part[(int)$r['participante_id']])) continue;
        $filas[(int)$r['participante_id']] = $r + ['p' => $part[(int)$r['participante_id']], 'puesto' => null, 'texto' => '', 'detalle' => ''];
    }
    $orden = []; // pid => clave de orden (solo los clasificados)
    if (in_array($f, ['puntos', 'tempo', 'omnium'], true)) {
        foreach ($filas as $pid => &$r) {
            $d = pista_json($r['datos']);
            if ($r['estado'] !== 'ok' || !$d) continue;
            if ($f === 'omnium') {
                $po = [pista_pts_omnium((int)($d['sc'] ?? 0)), pista_pts_omnium((int)($d['te'] ?? 0)), pista_pts_omnium((int)($d['el'] ?? 0))];
                $total = array_sum($po) + (int)($d['pr'] ?? 0);
                $r['detalle'] = 'scratch ' . $po[0] . ' · tempo ' . $po[1] . ' · elim. ' . $po[2] . ' · puntos ' . ((int)($d['pr'] ?? 0) >= 0 ? '+' : '') . (int)($d['pr'] ?? 0);
            } else {
                [$total, $r['detalle']] = pista_pts_carrera($d, $cfg);
            }
            $r['texto'] = $total . ' pts';
            $orden[$pid] = [-$total, (int)($d['lleg'] ?? 0) ?: 999, $r['p']['nombre']];
        }
        unset($r);
    } elseif ($f === 'velocidad' || $f === 'persecucion') {
        $cu = pista_cuadro($ev);
        foreach ($filas as $pid => &$r) if ($r['estado'] === 'ok' && $r['marca'] !== null) $r['texto'] = prueba_fmt_marca('tiempo', $r['marca']);
        unset($r);
        foreach ($cu['ronda'] as $pid => $txt) if (isset($filas[$pid])) $filas[$pid]['detalle'] = $txt;
        if ($cu['final']) foreach ($cu['final'] as $pid => $pos) if (isset($filas[$pid])) $orden[$pid] = [$pos];
    } elseif ($f === 'keirin' || $f === 'series') {
        $se = pista_series($ev);
        foreach ($se['info'] as $pid => $txt) if (isset($filas[$pid])) $filas[$pid]['texto'] = $txt;
        if ($se['final']) foreach ($se['final'] as $pid => $pos) if (isset($filas[$pid])) $orden[$pid] = [$pos];
    } elseif ($f === 'jueces') {
        $ju = pista_jueces($ev);
        foreach ($ju['info'] as $pid => [$txt, $det]) if (isset($filas[$pid])) { $filas[$pid]['texto'] = $txt; $filas[$pid]['detalle'] = $det; }
        foreach ($ju['orden'] as $pid => $k) if (isset($filas[$pid]) && $filas[$pid]['estado'] === 'ok') $orden[$pid] = $k;
    }
    $con = [];
    foreach ($orden as $pid => $k) $con[] = $filas[$pid] + ['_k' => $k];
    usort($con, fn($a, $b) => $a['_k'] <=> $b['_k']);
    foreach ($con as $i => &$r) { $r['puesto'] = $i + 1; unset($r['_k']); }
    unset($r);
    $sin = array_values(array_filter($filas, fn($r) => !isset($orden[(int)$r['participante_id']])));
    $orden_est = ['ok' => 0, 'dq' => 1, 'dnf' => 2, 'dns' => 3];
    usort($sin, fn($a, $b) => [$orden_est[$a['estado']] ?? 9, $a['marca'] === null ? 1 : 0, (float)$a['marca'], $a['p']['nombre']] <=> [$orden_est[$b['estado']] ?? 9, $b['marca'] === null ? 1 : 0, (float)$b['marca'], $b['p']['nombre']]);
    return array_merge($con, $sin);
}

/* ============================================================
 * CUADRO DE DUELOS: velocidad (al mejor de 3) y persecución (finales)
 * ============================================================ */

function pista_nombre_fase(string $fase): string
{
    return ['c8' => '1/8 de final', 'c4' => '1/4 de final', 'sf' => 'Semifinal', 'f' => 'Final', 'b' => 'Bronce (3.° y 4.°)', 'q' => 'Clasificatoria'][$fase]
        ?? (preg_match('/^r(\d+)$/', $fase, $m) ? ((int)$m[1] === 1 ? 'Primera ronda' : 'Ronda ' . (int)$m[1]) : $fase);
}

/** Clasificados por tiempo (los que tienen marca), del más rápido al más lento: [pid => ms]. */
function pista_clasificatoria(array $ev): array
{
    $r = [];
    foreach (filas("SELECT participante_id, marca FROM prueba_resultados WHERE evento_id = ? AND estado = 'ok' AND marca IS NOT NULL ORDER BY marca", [$ev['id']]) as $x) $r[(int)$x['participante_id']] = (float)$x['marca'];
    return $r;
}

/**
 * Estado del cuadro de una prueba de velocidad o persecución.
 * ['partidos' => [fase => [serie => ['a', 'b', 'mangas' => [m => ganador|null, ...], 'tiempos' => [m => [pid => ms]], 'ganador', 'perdedor']]],
 *  'ronda' => [pid => texto], 'final' => [pid => puesto] (vacío si no terminó)]
 */
function pista_cuadro(array $ev): array
{
    $mejor_de = $ev['formato'] === 'velocidad' ? 3 : 1;
    $p = [];
    foreach (filas('SELECT * FROM prueba_series WHERE evento_id = ? ORDER BY fase, serie, carril, manga', [$ev['id']]) as $x) {
        $k = &$p[$x['fase']][(int)$x['serie']];
        $k ??= ['a' => null, 'b' => null, 'mangas' => [], 'tiempos' => [], 'ganador' => null, 'perdedor' => null];
        $pid = (int)$x['participante_id'];
        if ((int)$x['carril'] === 1) $k['a'] = $pid; else $k['b'] = $pid;
        if ((int)$x['puesto'] === 1) $k['mangas'][(int)$x['manga']] = $pid;
        if ($x['tiempo_ms'] !== null) $k['tiempos'][(int)$x['manga']][$pid] = (int)$x['tiempo_ms'];
        unset($k);
    }
    foreach ($p as $fase => &$ps) {
        foreach ($ps as &$m) {
            $g = array_count_values(array_values($m['mangas']));
            foreach ($g as $pid => $n) if ($n >= intdiv($mejor_de, 2) + 1) { $m['ganador'] = $pid; $m['perdedor'] = $pid === $m['a'] ? $m['b'] : $m['a']; }
            if ($m['b'] === null && $m['a'] !== null) { $m['ganador'] = $m['a']; } // pasa solo (sin rival)
        }
        unset($m);
    }
    unset($ps);
    $ronda = [];
    $orden_f = ['c8', 'c4', 'sf', 'b', 'f'];
    foreach ($orden_f as $fase) foreach ($p[$fase] ?? [] as $m) foreach ([$m['a'], $m['b']] as $pid) if ($pid) {
        $txt = pista_nombre_fase($fase);
        if ($m['ganador'] !== null) $txt .= $m['ganador'] === $pid ? ' · ganó' : ' · perdió';
        $ronda[$pid] = $txt;
    }
    $final = [];
    $fin = $p['f'][1] ?? null;
    $bro = $p['b'][1] ?? null;
    if ($fin && $fin['ganador'] !== null && (!$bro || $bro['ganador'] !== null)) {
        $qual = pista_clasificatoria($ev);
        $final[$fin['ganador']] = 1;
        if ($fin['perdedor']) $final[$fin['perdedor']] = 2;
        if ($bro) { $final[$bro['ganador']] = 3; if ($bro['perdedor']) $final[$bro['perdedor']] = 4; }
        // Los demás: los eliminados en cada ronda (más adelante = mejor) y, entre ellos, por su tiempo de clasificación
        $resto = [];
        foreach ($qual as $pid => $ms) {
            if (isset($final[$pid])) continue;
            $nivel = 9;
            foreach (['sf' => 1, 'c4' => 2, 'c8' => 3] as $fase => $n) foreach ($p[$fase] ?? [] as $m) if ($m['perdedor'] === $pid) $nivel = min($nivel, $n);
            $resto[$pid] = [$nivel, $ms];
        }
        asort($resto);
        $pos = count($final);
        foreach (array_keys($resto) as $pid) $final[$pid] = ++$pos;
    }
    return ['partidos' => $p, 'ronda' => $ronda, 'final' => $final, 'mejor_de' => $mejor_de];
}

/** Arma la primera ronda del cuadro con los mejores tiempos de la clasificatoria. */
function pista_cuadro_armar(array $ev): string
{
    if ((int)valor('SELECT COUNT(*) FROM prueba_series WHERE evento_id = ?', [$ev['id']])) throw new RuntimeException('El cuadro ya está armado. Bórrelo primero si quiere armarlo de nuevo.');
    $q = array_keys(pista_clasificatoria($ev));
    if (count($q) < 2) throw new RuntimeException('Primero cargue los tiempos de la clasificatoria (al menos 2 con tiempo).');
    $mangas = $ev['formato'] === 'velocidad' ? 3 : 1;
    $ins = function (string $fase, int $serie, int $a, ?int $b) use ($ev, $mangas) {
        for ($m = 1; $m <= $mangas; $m++) {
            q('INSERT INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril) VALUES (?,?,?,?,?,1)', [$ev['id'], $fase, $serie, $m, $a]);
            if ($b) q('INSERT INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril) VALUES (?,?,?,?,?,2)', [$ev['id'], $fase, $serie, $m, $b]);
        }
    };
    if ($ev['formato'] === 'persecucion') {
        $ins('f', 1, $q[0], $q[1]);
        if (count($q) >= 4) $ins('b', 1, $q[2], $q[3]);
        return count($q) >= 4 ? 'Final (1.° contra 2.°) y bronce (3.° contra 4.°) armados.' : 'Final armada (1.° contra 2.°).';
    }
    $k = (int)pista_cfg($ev)['cuadro'];
    while ($k > 2 && count($q) < $k) $k = intdiv($k, 2);
    $fase = ['8' => 'c4', '4' => 'sf', '2' => 'f'][(string)$k];
    for ($i = 0; $i < intdiv($k, 2); $i++) $ins($fase, $i + 1, $q[$i], $q[$k - 1 - $i]);
    return 'Cuadro armado: ' . pista_nombre_fase($fase) . ' con los ' . $k . ' mejores tiempos (1.° contra ' . $k . '.°, 2.° contra ' . ($k - 1) . '.°…).';
}

/** Después de cargar mangas: si una ronda terminó, arma la siguiente. */
function pista_cuadro_avanzar(array $ev): void
{
    $cu = pista_cuadro($ev);
    $p = $cu['partidos'];
    $mangas = $ev['formato'] === 'velocidad' ? 3 : 1;
    $lista = fn(string $fase) => $p[$fase] ?? [];
    $hecha = fn(string $fase) => $lista($fase) && !array_filter($lista($fase), fn($m) => $m['ganador'] === null);
    $ins = function (string $fase, int $serie, int $a, ?int $b) use ($ev, $mangas) {
        for ($m = 1; $m <= $mangas; $m++) {
            q('INSERT OR IGNORE INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril) VALUES (?,?,?,?,?,1)', [$ev['id'], $fase, $serie, $m, $a]);
            if ($b) q('INSERT OR IGNORE INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril) VALUES (?,?,?,?,?,2)', [$ev['id'], $fase, $serie, $m, $b]);
        }
    };
    if ($hecha('c4') && !$lista('sf')) {
        $g = array_map(fn($m) => $m['ganador'], $lista('c4'));
        $ins('sf', 1, $g[1], $g[4] ?? null); // ganador de 1 contra 8 con el de 4 contra 5
        $ins('sf', 2, $g[2], $g[3] ?? null);
    }
    if ($hecha('sf') && !$lista('f')) {
        $sf = $lista('sf');
        $ins('f', 1, $sf[1]['ganador'], $sf[2]['ganador'] ?? null);
        if (isset($sf[2]) && $sf[1]['perdedor'] && $sf[2]['perdedor']) $ins('b', 1, $sf[1]['perdedor'], $sf[2]['perdedor']);
    }
}

/* ============================================================
 * SERIES: BMX Racing (motos) y keirin
 * ============================================================ */

/** Puntos BMX de una manga: puesto; DNF = los que largaron; REL o DQ = largaron + 2; DNS = los de la moto + 2. null si falta cargar. */
function pista_pts_manga(array $x, int $largaron, int $en_serie): ?int
{
    return match ($x['estado']) {
        'dnf' => $largaron,
        'rel', 'dq' => $largaron + 2,
        'dns' => $en_serie + 2,
        default => $x['puesto'] !== null ? (int)$x['puesto'] : null,
    };
}

/**
 * Estado de las series de una prueba de BMX o keirin.
 * ['fases' => [fase => [serie => ['pids' => [...], 'orden' => [pid => ['pts', 'pos', 'mangas' => [m => texto]]], 'completa' => bool]]],
 *  'info' => [pid => texto], 'final' => [pid => puesto] (vacío si no terminó), 'ultima' => fase]
 */
function pista_series(array $ev): array
{
    $cfg = pista_cfg($ev);
    $rows = filas('SELECT * FROM prueba_series WHERE evento_id = ? ORDER BY fase, serie, manga, carril', [$ev['id']]);
    $raw = [];
    foreach ($rows as $x) $raw[$x['fase']][(int)$x['serie']][(int)$x['manga']][(int)$x['participante_id']] = $x;
    $fases = [];
    foreach ($raw as $fase => $series) {
        foreach ($series as $s => $mangas) {
            $pids = [];
            foreach ($mangas as $m => $xs) foreach ($xs as $pid => $x) $pids[$pid] = (int)$x['carril'];
            asort($pids);
            $pids = array_keys($pids);
            $n = count($pids);
            $tot = array_fill_keys($pids, 0);
            $txt = [];
            $ult = [];
            $completa = true;
            foreach ($mangas as $m => $xs) {
                $largaron = count(array_filter($xs, fn($x) => $x['estado'] !== 'dns' && ($x['puesto'] !== null || $x['estado'] !== 'ok')));
                foreach ($pids as $pid) {
                    $x = $xs[$pid] ?? null;
                    $pt = $x ? pista_pts_manga($x, $largaron, $n) : null;
                    if ($pt === null) { $completa = false; continue; }
                    $tot[$pid] += $pt;
                    $txt[$pid][$m] = $x['estado'] !== 'ok' ? strtoupper($x['estado']) . ' (' . $pt . ')' : (string)$pt;
                    $ult[$pid] = $pt;
                }
            }
            $orden = $pids;
            usort($orden, fn($a, $b) => [$tot[$a], $ult[$a] ?? 99, array_search($a, $pids, true)] <=> [$tot[$b], $ult[$b] ?? 99, array_search($b, $pids, true)]);
            $o = [];
            foreach ($orden as $i => $pid) $o[$pid] = ['pts' => $tot[$pid], 'pos' => $i + 1, 'mangas' => $txt[$pid] ?? []];
            $fases[$fase][$s] = ['pids' => $pids, 'orden' => $o, 'completa' => $completa, 'mangas' => count($mangas)];
        }
    }
    uksort($fases, fn($a, $b) => pista_rango_fase($a) <=> pista_rango_fase($b));
    $info = [];
    foreach ($fases as $fase => $series) foreach ($series as $s => $se) foreach ($se['orden'] as $pid => $o) {
        $info[$pid] = pista_nombre_ronda($fase, count($series), $se['mangas'], $ev['formato']) . ($fase !== 'f' && count($series) > 1 ? ' ' . $s : '') . ($se['completa'] ? ' · ' . $o['pos'] . '.°' . ($se['mangas'] > 1 ? ' (' . $o['pts'] . ' pts)' : '') : ' · en curso');
    }
    $final = [];
    $ultima = $fases ? array_key_last($fases) : '';
    $termino = $ultima !== '' && !array_filter($fases[$ultima], fn($se) => !$se['completa'])
        && ($ultima === 'f' || ($ultima === 'r1' && count($fases['r1']) === 1)); // con una sola moto no hay final
    if ($termino) {
        $pos = 0;
        $ya = [];
        // Los que llegaron más lejos van primero; entre los eliminados en la misma ronda, por su puesto en la serie y luego sus puntos
        foreach (array_reverse(array_keys($fases)) as $fase) {
            $grupo = [];
            foreach ($fases[$fase] as $se) foreach ($se['orden'] as $pid => $o) if (!isset($ya[$pid])) $grupo[$pid] = [$o['pos'], $o['pts'], $pid];
            asort($grupo);
            foreach (array_keys($grupo) as $pid) { $final[$pid] = ++$pos; $ya[$pid] = true; }
        }
    }
    return ['fases' => $fases, 'info' => $info, 'final' => $final, 'ultima' => $ultima, 'cfg' => $cfg];
}

/** Nombre de una ronda de series según cuántas series tiene: motos, 1/8, 1/4, semifinal, final. */
function pista_nombre_ronda(string $fase, int $series, int $mangas, string $formato): string
{
    if ($fase === 'f') return 'Final';
    if ($fase === 'r1' && $formato === 'series' && $mangas > 1) return 'Motos';
    if ($fase === 'r1') return 'Primera ronda';
    return [2 => 'Semifinal', 4 => '1/4 de final', 8 => '1/8 de final'][$series] ?? pista_nombre_fase($fase);
}

function pista_rango_fase(string $f): int
{
    if ($f === 'f') return 999;
    return preg_match('/^r(\d+)$/', $f, $m) ? (int)$m[1] : 500;
}

/** Reparte a los corredores en series de hasta $tam (en zigzag, para equilibrar). Devuelve [serie => [pids]]. */
function pista_repartir(array $pids, int $tam): array
{
    $n = count($pids);
    $ns = max(1, (int)ceil($n / max(1, $tam)));
    $s = [];
    foreach (array_values($pids) as $i => $pid) {
        $vuelta = intdiv($i, $ns);
        $k = $vuelta % 2 === 0 ? $i % $ns : $ns - 1 - $i % $ns;
        $s[$k + 1][] = $pid;
    }
    ksort($s);
    return $s;
}

function pista_series_insertar(array $ev, string $fase, array $series, int $mangas): void
{
    foreach ($series as $s => $pids) foreach (array_values($pids) as $i => $pid) for ($m = 1; $m <= $mangas; $m++) {
        q('INSERT OR IGNORE INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril) VALUES (?,?,?,?,?,?)', [$ev['id'], $fase, $s, $m, $pid, $i + 1]);
    }
}

/** Arma la primera ronda (motos o series) con todos los inscritos que no están retirados. */
function pista_series_armar(array $ev): string
{
    if ((int)valor('SELECT COUNT(*) FROM prueba_series WHERE evento_id = ?', [$ev['id']])) throw new RuntimeException('Las series ya están armadas. Bórrelas primero si quiere armarlas de nuevo.');
    $cfg = pista_cfg($ev);
    $pids = array_map('intval', array_column(filas("SELECT r.participante_id FROM prueba_resultados r JOIN prueba_participantes p ON p.id = r.participante_id
        WHERE r.evento_id = ? AND r.estado <> 'dns' AND p.activo = 1 ORDER BY CASE WHEN r.marca IS NULL THEN 1 ELSE 0 END, r.marca, CAST(p.numero AS INTEGER), p.nombre", [$ev['id']]), 'participante_id'));
    if (count($pids) < 2) throw new RuntimeException('Inscriba al menos 2 en esta prueba.');
    $bmx = $ev['formato'] === 'series';
    if (!$bmx && count($pids) <= (int)$cfg['final']) {
        pista_series_insertar($ev, 'f', [1 => $pids], 1);
        return 'Son ' . count($pids) . ': van directo a la final.';
    }
    $series = pista_repartir($pids, (int)$cfg['por_serie']);
    pista_series_insertar($ev, 'r1', $series, $bmx ? (int)$cfg['mangas'] : 1);
    return count($series) === 1 ? 'Una sola moto con ' . count($pids) . ' pilotos y ' . (int)$cfg['mangas'] . ' mangas: gana el que sume menos puntos (no hay final).'
        : count($series) . ($bmx ? ' motos' : ' series') . ' armadas.';
}

/** Cuando una ronda terminó, arma la siguiente (o la final). */
function pista_series_avanzar(array $ev): void
{
    $se = pista_series($ev);
    $u = $se['ultima'];
    if ($u === '' || $u === 'f' || array_filter($se['fases'][$u], fn($x) => !$x['completa'])) return;
    $cfg = $se['cfg'];
    $bmx = $ev['formato'] === 'series';
    $ns = count($se['fases'][$u]);
    if ($u === 'r1' && $ns === 1 && $bmx) return; // una sola moto: no hay final
    $pasan = (int)$cfg['pasan'] ?: (int)ceil((int)$cfg['final'] / $ns);
    $q = [];
    foreach ($se['fases'][$u] as $s => $x) foreach ($x['orden'] as $pid => $o) if ($o['pos'] <= $pasan) $q[$pid] = [$o['pos'], $o['pts'], $s];
    asort($q);
    $q = array_keys($q);
    if (count($q) <= (int)$cfg['final']) {
        pista_series_insertar($ev, 'f', [1 => $q], 1);
        return;
    }
    $n = pista_rango_fase($u) + 1;
    pista_series_insertar($ev, 'r' . $n, pista_repartir($q, (int)$cfg['por_serie']), 1);
}

/* ============================================================
 * JUECES: BMX Freestyle (park, flatland)
 * ============================================================ */

/** Puntaje de una pasada: promedio de los jueces (sin la nota más alta y la más baja si así se configuró). */
function pista_puntaje(array $notas, array $cfg): ?float
{
    $n = array_values(array_map('floatval', array_filter($notas, fn($v) => $v !== '' && $v !== null)));
    if (!$n) return null;
    if ((int)$cfg['descarte'] === 1 && count($n) >= 3) {
        sort($n);
        array_shift($n);
        array_pop($n);
    }
    return round(array_sum($n) / count($n), 2);
}

/** ['q' => [pid => ['pasadas' => [m => puntaje], 'valor']], 'f' => igual, 'info' => [pid => [texto, detalle]], 'orden' => [pid => clave]] */
function pista_jueces(array $ev): array
{
    $cfg = pista_cfg($ev);
    $r = ['q' => [], 'f' => []];
    foreach (filas('SELECT * FROM prueba_series WHERE evento_id = ? ORDER BY fase, manga', [$ev['id']]) as $x) {
        $fase = $x['fase'] === 'f' ? 'f' : 'q';
        $pid = (int)$x['participante_id'];
        $r[$fase][$pid] ??= ['pasadas' => [], 'notas' => [], 'valor' => null, 'dns' => false];
        if ($x['estado'] === 'dns') { $r[$fase][$pid]['dns'] = true; continue; }
        $notas = pista_json($x['datos']);
        $r[$fase][$pid]['notas'][(int)$x['manga']] = $notas;
        $pt = pista_puntaje($notas, $cfg);
        if ($pt !== null) $r[$fase][$pid]['pasadas'][(int)$x['manga']] = $pt;
    }
    foreach (['q', 'f'] as $fase) foreach ($r[$fase] as $pid => &$x) {
        if (!$x['pasadas']) continue;
        $x['valor'] = $fase === 'f' || $cfg['q_modo'] === 'mejor' ? max($x['pasadas']) : round(array_sum($x['pasadas']) / max(count($x['pasadas']), (int)$cfg['pasadas']), 2);
    }
    unset($x);
    $fmt = fn(?float $v) => $v === null ? '' : number_format($v, 2, ',', '');
    $info = [];
    $orden = [];
    foreach ($r['q'] as $pid => $x) if ($x['valor'] !== null) {
        $info[$pid] = [$fmt($x['valor']), 'Clasificatoria: ' . implode(' · ', array_map($fmt, $x['pasadas']))];
        $orden[$pid] = [1, -$x['valor'], 0, 0]; // (mismo largo que la clave de la final: PHP compara primero el largo de los arreglos)
    }
    foreach ($r['f'] as $pid => $x) if ($x['valor'] !== null) {
        $otras = $x['pasadas'];
        rsort($otras);
        $info[$pid] = [$fmt($x['valor']), 'Final: ' . implode(' · ', array_map($fmt, $x['pasadas'])) . (isset($info[$pid]) ? ' · ' . $info[$pid][1] : '')];
        $orden[$pid] = [0, -$x['valor'], -($otras[1] ?? 0), -($r['q'][$pid]['valor'] ?? 0)];
    }
    return $r + ['info' => $info, 'orden' => $orden, 'cfg' => $cfg];
}

/* ============================================================
 * ACCIONES DEL PANEL
 * ============================================================ */

/** Procesa los formularios de ciclismo (accion = 'pista'). Devuelve el mensaje para el usuario. */
function pista_accion(array $t, array $ev, string $sub): string
{
    $f = (string)$ev['formato'];
    $part = pruebas_participantes((int)$t['id']);
    $ins = array_map('intval', array_column(filas('SELECT participante_id FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]), 'participante_id'));
    $n = 0;
    if ($sub === 'grilla' && in_array($f, ['puntos', 'tempo', 'omnium'], true)) {
        $cfg = pista_cfg($ev);
        foreach ($ins as $pid) {
            $est = (string)($_POST['est'][$pid] ?? 'ok');
            if (!isset(pruebas_estados()[$est])) $est = 'ok';
            $num = fn(string $k, int $min = 0, int $max = 999) => trim((string)($_POST[$k][$pid] ?? '')) === '' ? 0 : entero($_POST[$k][$pid], $min, $max);
            if ($f === 'omnium') {
                $pr = trim((string)($_POST['pr'][$pid] ?? ''));
                if ($pr !== '' && !preg_match('/^[−-]?\d{1,3}$/u', $pr)) throw new RuntimeException(($part[$pid]['nombre'] ?? '') . ': los puntos de la carrera por puntos son un número entero (puede ser negativo).');
                $d = ['sc' => $num('sc'), 'te' => $num('te'), 'el' => $num('el'), 'pr' => (int)str_replace('−', '-', $pr), 'lleg' => $num('lleg')];
            } else {
                $s = [];
                for ($i = 1; $i < (int)$cfg['sprints']; $i++) $s[] = trim((string)($_POST['s'][$pid][$i] ?? '')) === '' ? 0 : entero($_POST['s'][$pid][$i], 0, 99);
                while ($s && end($s) === 0) array_pop($s);
                $d = ['s' => $s, 'lleg' => $num('lleg'), 'vg' => $num('vg', 0, 20), 'vp' => $num('vp', 0, 20)];
            }
            $vacio = !array_filter($d, fn($v) => is_array($v) ? (bool)$v : $v !== 0);
            q('UPDATE prueba_resultados SET datos = ?, estado = ?, nota = ? WHERE evento_id = ? AND participante_id = ?',
                [$vacio ? '' : json_encode($d), $est, mb_substr(trim((string)($_POST['nota'][$pid] ?? '')), 0, 120), $ev['id'], $pid]);
            if (!$vacio || $est !== 'ok') $n++;
        }
        return "Resultados guardados ($n). Los puntos y puestos ya están calculados.";
    }
    if ($sub === 'cuadro_armar') return pista_cuadro_armar($ev);
    if ($sub === 'series_armar') return pista_series_armar($ev);
    if ($sub === 'borrar_series') {
        $fase = (string)post('fase');
        if ($fase === '') q('DELETE FROM prueba_series WHERE evento_id = ?', [$ev['id']]);
        else q('DELETE FROM prueba_series WHERE evento_id = ? AND fase = ?', [$ev['id'], $fase]);
        return $fase === '' ? 'Se borró todo el cuadro / las series. Puede armarlas de nuevo.' : 'Se borró la ronda «' . pista_nombre_fase($fase) . '».';
    }
    if ($sub === 'cuadro_guardar') {
        $cu = pista_cuadro($ev);
        foreach ($cu['partidos'] as $fase => $ps) foreach ($ps as $s => $m) {
            if (!$m['a'] || !$m['b']) continue;
            for ($k = 1; $k <= $cu['mejor_de']; $k++) {
                $g = (string)($_POST['g'][$fase][$s][$k] ?? '');
                $tA = carrera_parse_tiempo((string)($_POST['ta'][$fase][$s][$k] ?? ''));
                $tB = carrera_parse_tiempo((string)($_POST['tb'][$fase][$s][$k] ?? ''));
                if ($g === '' && $tA !== null && $tB !== null && $tA !== $tB) $g = $tA < $tB ? 'a' : 'b'; // persecución: gana el menor tiempo
                foreach (['a' => $m['a'], 'b' => $m['b']] as $lado => $pid) {
                    $pu = $g === '' ? null : ($g === $lado ? 1 : 2);
                    q('INSERT INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril, puesto, tiempo_ms) VALUES (?,?,?,?,?,?,?,?)
                       ON CONFLICT(evento_id, fase, serie, manga, participante_id) DO UPDATE SET puesto = excluded.puesto, tiempo_ms = excluded.tiempo_ms',
                        [$ev['id'], $fase, $s, $k, $pid, $lado === 'a' ? 1 : 2, $pu, $lado === 'a' ? $tA : $tB]);
                }
                if ($g !== '') $n++;
            }
        }
        // si se cambió un resultado de una ronda ya cerrada, la siguiente se rehace
        pista_cuadro_limpiar($ev);
        pista_cuadro_avanzar($ev);
        pista_cuadro_avanzar($ev);
        return "Mangas guardadas ($n). " . (pista_cuadro($ev)['final'] ? 'La prueba terminó: la clasificación final ya está lista.' : 'Cuando una ronda termina, el sistema arma la siguiente solo.');
    }
    if ($sub === 'series_guardar') {
        $se = pista_series($ev);
        $validos = ['DNF' => 'dnf', 'DNS' => 'dns', 'REL' => 'rel', 'DQ' => 'dq', 'DSQ' => 'dq'];
        foreach ($se['fases'] as $fase => $series) foreach ($series as $s => $x) {
            $usados = [];
            for ($m = 1; $m <= $x['mangas']; $m++) foreach ($x['pids'] as $pid) {
                $v = strtoupper(trim((string)($_POST['r'][$fase][$s][$m][$pid] ?? '')));
                $est = 'ok';
                $pu = null;
                if ($v !== '') {
                    if (isset($validos[$v])) $est = $validos[$v];
                    elseif (ctype_digit($v) && (int)$v >= 1 && (int)$v <= count($x['pids'])) {
                        $pu = (int)$v;
                        if (isset($usados[$m][$pu])) throw new RuntimeException(pista_nombre_fase($fase) . " $s, manga $m: el puesto $pu está repetido.");
                        $usados[$m][$pu] = true;
                    } else throw new RuntimeException(pista_nombre_fase($fase) . " $s, manga $m: «{$v}» no vale. Escriba el puesto (1 a " . count($x['pids']) . ') o DNF, DNS, REL, DQ.');
                }
                q('UPDATE prueba_series SET puesto = ?, estado = ? WHERE evento_id = ? AND fase = ? AND serie = ? AND manga = ? AND participante_id = ?', [$pu, $est, $ev['id'], $fase, $s, $m, $pid]);
                if ($v !== '') $n++;
            }
        }
        pista_series_limpiar($ev);
        pista_series_avanzar($ev);
        return "Resultados guardados ($n). " . (pista_series($ev)['final'] ? 'La prueba terminó: la clasificación final ya está lista.' : 'Cuando una ronda termina, el sistema arma la siguiente solo.');
    }
    if ($sub === 'jueces_guardar') {
        $cfg = pista_cfg($ev);
        foreach (['q', 'f'] as $fase) foreach ((array)($_POST['j'][$fase] ?? []) as $pid => $pasadas) {
            $pid = (int)$pid;
            if (!in_array($pid, $ins, true)) continue;
            $dns = isset($_POST['dns'][$fase][$pid]);
            for ($m = 1; $m <= (int)$cfg['pasadas']; $m++) {
                $notas = [];
                for ($k = 1; $k <= (int)$cfg['jueces']; $k++) {
                    $v = str_replace(',', '.', trim((string)($pasadas[$m][$k] ?? '')));
                    if ($v === '') continue;
                    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) throw new RuntimeException(($part[$pid]['nombre'] ?? '') . ": la nota «{$v}» debe estar entre 0 y 100.");
                    $notas[$k] = round((float)$v, 2);
                }
                if (!$notas && !$dns) { q('DELETE FROM prueba_series WHERE evento_id = ? AND fase = ? AND manga = ? AND participante_id = ?', [$ev['id'], $fase, $m, $pid]); continue; }
                q('INSERT INTO prueba_series (evento_id, fase, serie, manga, participante_id, estado, datos) VALUES (?,?,1,?,?,?,?)
                   ON CONFLICT(evento_id, fase, serie, manga, participante_id) DO UPDATE SET estado = excluded.estado, datos = excluded.datos',
                    [$ev['id'], $fase, $m, $pid, $dns ? 'dns' : 'ok', $notas ? json_encode($notas) : '']);
                $n++;
            }
        }
        return "Notas guardadas ($n pasadas). Los puntajes y puestos ya están calculados.";
    }
    if ($sub === 'final_armar') {
        $cfg = pista_cfg($ev);
        $ju = pista_jueces($ev);
        if ($ju['f']) throw new RuntimeException('La final ya tiene notas.');
        $q = array_filter($ju['q'], fn($x) => $x['valor'] !== null);
        uasort($q, fn($a, $b) => $b['valor'] <=> $a['valor']);
        $fin = array_slice(array_keys($q), 0, max(1, (int)$cfg['finalistas']));
        if (!$fin) throw new RuntimeException('Primero cargue las notas de la clasificatoria.');
        q("UPDATE prueba_eventos SET datos = ? WHERE id = ?", [json_encode(['finalistas' => $fin]), $ev['id']]);
        return 'Final armada con ' . count($fin) . ' riders (los mejores de la clasificatoria).';
    }
    throw new RuntimeException('Acción no válida para esta prueba.');
}

/** Si se corrigió una ronda ya cerrada del cuadro, borra las rondas siguientes que ya no corresponden. */
function pista_cuadro_limpiar(array $ev): void
{
    $cu = pista_cuadro($ev);
    $p = $cu['partidos'];
    $esperado = function (string $de) use ($p): array {
        $g = [];
        foreach ($p[$de] ?? [] as $m) if ($m['ganador'] !== null) $g[] = $m['ganador'];
        return $g;
    };
    foreach (['c4' => 'sf', 'sf' => 'f'] as $de => $a) {
        if (empty($p[$a])) continue;
        $en_a = [];
        foreach ($p[$a] as $m) { $en_a[] = $m['a']; if ($m['b']) $en_a[] = $m['b']; }
        $g = $esperado($de);
        if (array_diff($en_a, $g) || count($g) < count($p[$de] ?? [])) {
            $borrar = $a === 'sf' ? ['sf', 'f', 'b'] : ['f', 'b'];
            q('DELETE FROM prueba_series WHERE evento_id = ? AND fase IN (' . implode(',', array_fill(0, count($borrar), '?')) . ')', array_merge([$ev['id']], $borrar));
            return;
        }
    }
}

/** Igual para las series: si cambió quién pasa de una ronda, se borran las rondas posteriores. */
function pista_series_limpiar(array $ev): void
{
    $se = pista_series($ev);
    $fases = array_keys($se['fases']);
    for ($i = 0; $i < count($fases) - 1; $i++) {
        $u = $fases[$i];
        $sig = $fases[$i + 1];
        $incompleta = (bool)array_filter($se['fases'][$u], fn($x) => !$x['completa']);
        $pasan = (int)$se['cfg']['pasan'] ?: (int)ceil((int)$se['cfg']['final'] / count($se['fases'][$u]));
        $q = [];
        foreach ($se['fases'][$u] as $x) foreach ($x['orden'] as $pid => $o) if ($o['pos'] <= $pasan) $q[] = $pid;
        $en_sig = [];
        foreach ($se['fases'][$sig] as $x) $en_sig = array_merge($en_sig, $x['pids']);
        sort($q);
        sort($en_sig);
        if ($incompleta || $q !== $en_sig) {
            $borrar = array_slice($fases, $i + 1);
            q('DELETE FROM prueba_series WHERE evento_id = ? AND fase IN (' . implode(',', array_fill(0, count($borrar), '?')) . ')', array_merge([$ev['id']], $borrar));
            return;
        }
    }
}

/* ============================================================
 * HTML: formularios del panel
 * ============================================================ */

/** Campos de formato y reglas en el formulario de la prueba. */
function pista_html_cfg_campos(array $t, ?array $ev): string
{
    $fo = (string)($ev['formato'] ?? array_key_first(pista_formatos((string)$t['modalidad'])));
    $cfg = pista_cfg(['formato' => $fo, 'config' => $ev['config'] ?? '']);
    $todos = [];
    foreach (['puntos', 'tempo', 'velocidad', 'keirin', 'series', 'jueces'] as $x) $todos[$x] = pista_cfg(['formato' => $x, 'config' => $fo === $x ? ($ev['config'] ?? '') : '']);
    $uid = 'pcf' . (int)($ev['id'] ?? 0);
    ob_start(); ?>
    <div class="campo-ancho" id="<?= $uid ?>">
      <label>Formato de la prueba</label>
      <select name="formato" onchange="var c=document.getElementById('<?= $uid ?>');c.querySelectorAll('[data-fo]').forEach(function(d){d.style.display=d.getAttribute('data-fo').split(' ').indexOf(this.value)>=0?'':'none'},this)">
        <?php foreach (pista_formatos((string)$t['modalidad']) as $k => $x): ?><option value="<?= $k ?>" <?= $fo === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?>
      </select>
      <div class="campos" data-fo="puntos tempo" style="<?= in_array($fo, ['puntos', 'tempo'], true) ? '' : 'display:none' ?>;margin-top:6px">
        <?php $c = $todos[$fo === 'tempo' ? 'tempo' : 'puntos']; ?>
        <div><label>Número de sprints (con la llegada)</label><input type="number" name="cfg_sprints" value="<?= (int)$c['sprints'] ?>" min="1" max="200"><div class="ayuda">UCI: un sprint cada 10 vueltas en la carrera por puntos; en el tempo, uno por vuelta.</div></div>
        <div><label>Puntos por sprint</label><input type="text" name="cfg_pts_sprint" value="<?= e($c['pts_sprint']) ?>"><div class="ayuda">Puntos: 5,3,2,1 · Tempo: 1</div></div>
        <div><label>Puntos del sprint final</label><input type="text" name="cfg_pts_final" value="<?= e($c['pts_final']) ?>"><div class="ayuda">Puntos: 10,6,4,2 (doble) · Tempo: 1</div></div>
        <div><label>Puntos por vuelta ganada / perdida</label><input type="number" name="cfg_pts_vuelta" value="<?= (int)$c['pts_vuelta'] ?>" min="0" max="100"></div>
      </div>
      <div class="campos" data-fo="velocidad" style="<?= $fo === 'velocidad' ? '' : 'display:none' ?>;margin-top:6px">
        <div><label>Cuadro de duelos con los</label><select name="cfg_cuadro"><?php foreach ([8 => '8 mejores (1/4, semis y final)', 4 => '4 mejores (semis y final)', 2 => '2 mejores (solo final)'] as $k => $x): ?><option value="<?= $k ?>" <?= (int)$todos['velocidad']['cuadro'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="campos" data-fo="keirin series" style="<?= in_array($fo, ['keirin', 'series'], true) ? '' : 'display:none' ?>;margin-top:6px">
        <?php $c = $todos[$fo === 'keirin' ? 'keirin' : 'series']; ?>
        <div><label>Corredores por serie (moto)</label><input type="number" name="cfg_por_serie" value="<?= (int)$c['por_serie'] ?>" min="3" max="12"><div class="ayuda">BMX: 8 · Keirin: 7</div></div>
        <div><label>Corredores en la final</label><input type="number" name="cfg_final" value="<?= (int)$c['final'] ?>" min="2" max="12"><div class="ayuda">BMX: 8 · Keirin: 6</div></div>
        <div><label>Mangas en la primera ronda</label><input type="number" name="cfg_mangas" value="<?= (int)$c['mangas'] ?>" min="1" max="3"><div class="ayuda">BMX: 3 motos · Keirin: 1</div></div>
        <div><label>Pasan por serie</label><input type="number" name="cfg_pasan" value="<?= (int)$c['pasan'] ?>" min="0" max="8"><div class="ayuda">BMX: 4 · 0 = los necesarios para llenar la final</div></div>
      </div>
      <div class="campos" data-fo="jueces" style="<?= $fo === 'jueces' ? '' : 'display:none' ?>;margin-top:6px">
        <?php $c = $todos['jueces']; ?>
        <div><label>Jueces</label><input type="number" name="cfg_jueces" value="<?= (int)$c['jueces'] ?>" min="1" max="9"></div>
        <div><label>Pasadas por ronda</label><input type="number" name="cfg_pasadas" value="<?= (int)$c['pasadas'] ?>" min="1" max="4"><div class="ayuda">UCI: 2 pasadas de 60 segundos.</div></div>
        <div><label>Clasificatoria</label><select name="cfg_q_modo"><option value="promedio" <?= $c['q_modo'] === 'promedio' ? 'selected' : '' ?>>Promedio de las pasadas (UCI)</option><option value="mejor" <?= $c['q_modo'] === 'mejor' ? 'selected' : '' ?>>La mejor pasada</option></select></div>
        <div><label>Finalistas</label><input type="number" name="cfg_finalistas" value="<?= (int)$c['finalistas'] ?>" min="0" max="30"><div class="ayuda">0 = sin final. En la final vale la mejor pasada.</div></div>
        <div><label class="check" style="font-weight:400"><input type="checkbox" name="cfg_descarte" value="1" <?= (int)$c['descarte'] ? 'checked' : '' ?>> Quitar la nota más alta y la más baja (con 5 jueces o más)</label></div>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}

/** Bloque del panel para cargar los resultados de una prueba de ciclismo que no es por marca. */
function pista_html_admin(array $t, array $ev, array $part, string $url): string
{
    $f = (string)$ev['formato'];
    $cfg = pista_cfg($ev);
    $T = textos_pruebas($t);
    $filas = prueba_resultados($ev, $part);
    $ins = array_values(array_filter($filas, fn($r) => true));
    $hid = csrf_campo() . '<input type="hidden" name="accion" value="pista"><input type="hidden" name="evento_id" value="' . (int)$ev['id'] . '">';
    ob_start();
    if (in_array($f, ['puntos', 'tempo', 'omnium'], true)):
        usort($ins, fn($a, $b) => [(int)$a['carril'] ?: 99, $a['p']['nombre']] <=> [(int)$b['carril'] ?: 99, $b['p']['nombre']]); ?>
      <?php if ($f === 'omnium'): ?>
        <p class="ayuda"><b>Omnium (UCI):</b> escriba el puesto de cada ciclista en el <b>scratch</b>, el <b>tempo</b> y la <b>eliminación</b> (cada uno da 40, 38, 36… puntos; del 21.° en adelante, 1). En la <b>carrera por puntos</b> final escriba los puntos que ganó (sprints y vueltas: +20 o −20 por vuelta; puede ser negativo) y su puesto en el sprint final, que desempata.</p>
      <?php else: ?>
        <p class="ayuda"><b><?= $f === 'tempo' ? 'Tempo' : 'Carrera por puntos' ?> (UCI):</b> en cada sprint escriba el puesto en que pasó (1, 2, 3…) solo si sumó puntos; déjelo vacío si no. El último sprint es la llegada (puesto final, desempata). Puntos por sprint: <?= e(implode('-', ciclo_lista_num($cfg['pts_sprint']))) ?>; llegada: <?= e(implode('-', ciclo_lista_num($cfg['pts_final']))) ?>; vuelta ganada +<?= (int)$cfg['pts_vuelta'] ?>, perdida −<?= (int)$cfg['pts_vuelta'] ?>.</p>
      <?php endif; ?>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="grilla">
        <div class="tabla-scroll"><table class="tabla-grilla">
          <tr><th>#</th><th><?= e($T['participante']) ?></th>
            <?php if ($f === 'omnium'): ?><th>Scratch</th><th>Tempo</th><th>Elimin.</th><th>Pts carrera por puntos</th><th>Llegada</th>
            <?php else: for ($i = 1; $i < (int)$cfg['sprints']; $i++): ?><th class="num">S<?= $i ?></th><?php endfor; ?><th>Llegada</th><th title="Vueltas ganadas">V+</th><th title="Vueltas perdidas">V−</th><?php endif; ?>
            <th>Total</th><th>Estado</th><th>Nota</th></tr>
          <?php foreach ($ins as $r): $pid = (int)$r['participante_id']; $d = pista_json($r['datos']); $in = fn($n, $v, $w = 44) => '<input type="text" inputmode="numeric" name="' . $n . '" value="' . e((string)($v ?: '')) . '" style="width:' . $w . 'px">'; ?>
            <tr><td><?= $r['puesto'] ?? '' ?></td><td style="white-space:normal;min-width:130px"><?= e($r['p']['nombre']) ?><br><small class="suave"><?= e($r['p']['club']) ?></small></td>
              <?php if ($f === 'omnium'): ?>
                <td><?= $in("sc[$pid]", $d['sc'] ?? '') ?></td><td><?= $in("te[$pid]", $d['te'] ?? '') ?></td><td><?= $in("el[$pid]", $d['el'] ?? '') ?></td>
                <td><input type="text" name="pr[<?= $pid ?>]" value="<?= isset($d['pr']) && (int)$d['pr'] !== 0 ? (int)$d['pr'] : '' ?>" style="width:60px" placeholder="±"></td><td><?= $in("lleg[$pid]", $d['lleg'] ?? '') ?></td>
              <?php else: for ($i = 1; $i < (int)$cfg['sprints']; $i++): ?><td><?= $in("s[$pid][$i]", $d['s'][$i - 1] ?? '', 34) ?></td><?php endfor; ?>
                <td><?= $in("lleg[$pid]", $d['lleg'] ?? '') ?></td><td><?= $in("vg[$pid]", $d['vg'] ?? '', 34) ?></td><td><?= $in("vp[$pid]", $d['vp'] ?? '', 34) ?></td>
              <?php endif; ?>
              <td class="num"><b><?= e($r['texto']) ?></b></td>
              <td><select name="est[<?= $pid ?>]"><?php foreach (pruebas_estados() as $k => $x): ?><option value="<?= $k ?>" <?= $r['estado'] === $k ? 'selected' : '' ?>><?= e($k === 'ok' ? 'Compitió' : strtoupper($k)) ?></option><?php endforeach; ?></select></td>
              <td><input type="text" name="nota[<?= $pid ?>]" value="<?= e($r['nota']) ?>" maxlength="120" style="min-width:100px"></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar</button>
      </form>
    <?php elseif ($f === 'velocidad' || $f === 'persecucion'):
        $cu = pista_cuadro($ev); ?>
      <h3>Cuadro de <?= $f === 'velocidad' ? 'duelos (al mejor de 3 mangas)' : 'finales' ?></h3>
      <?php if (!$cu['partidos']): ?>
        <p class="ayuda"><?= $f === 'velocidad' ? 'Cuando tenga los tiempos de la clasificatoria (200 m lanzados), arme el cuadro: el 1.° corre contra el ' . (int)$cfg['cuadro'] . '.°, el 2.° contra el ' . ((int)$cfg['cuadro'] - 1) . '.°, etc. Gana el duelo quien gana 2 mangas.' : 'Cuando tenga los tiempos de la clasificatoria, arme las finales: el 1.° y el 2.° corren por el oro y la plata; el 3.° y el 4.° por el bronce.' ?></p>
        <form method="post"><?= $hid ?><input type="hidden" name="sub" value="cuadro_armar"><button class="btn">⚔ Armar el cuadro con la clasificatoria</button></form>
      <?php else: ?>
        <form method="post"><?= $hid ?><input type="hidden" name="sub" value="cuadro_guardar">
          <?php foreach (['c8', 'c4', 'sf', 'b', 'f'] as $fase): if (empty($cu['partidos'][$fase])) continue; ?>
            <h4 style="margin:.6rem 0 .3rem"><?= e(pista_nombre_fase($fase)) ?></h4>
            <?php foreach ($cu['partidos'][$fase] as $s => $m): $na = $part[$m['a']]['nombre'] ?? '?'; $nb = $m['b'] ? ($part[$m['b']]['nombre'] ?? '?') : '— (pasa solo)'; ?>
              <div class="duelo"><b><?= e($na) ?></b> vs <b><?= e($nb) ?></b><?= $m['ganador'] ? ' <span class="etiqueta">ganó ' . e($part[$m['ganador']]['nombre'] ?? '') . '</span>' : '' ?>
                <?php if ($m['b']): for ($k = 1; $k <= $cu['mejor_de']; $k++): $g = $m['mangas'][$k] ?? null; ?>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:3px 0"><span class="suave"><?= $cu['mejor_de'] > 1 ? 'Manga ' . $k : 'Resultado' ?>:</span>
                    <select name="g[<?= $fase ?>][<?= $s ?>][<?= $k ?>]"><option value="">—</option><option value="a" <?= $g === $m['a'] ? 'selected' : '' ?>>Ganó <?= e($na) ?></option><option value="b" <?= $g === $m['b'] ? 'selected' : '' ?>>Ganó <?= e($nb) ?></option></select>
                    <?php if ($f === 'persecucion'): ?>
                      <input type="text" name="ta[<?= $fase ?>][<?= $s ?>][<?= $k ?>]" value="<?= isset($m['tiempos'][$k][$m['a']]) ? e(carrera_fmt_tiempo($m['tiempos'][$k][$m['a']])) : '' ?>" placeholder="tiempo <?= e($na) ?>" style="width:110px" inputmode="decimal">
                      <input type="text" name="tb[<?= $fase ?>][<?= $s ?>][<?= $k ?>]" value="<?= isset($m['tiempos'][$k][$m['b']]) ? e(carrera_fmt_tiempo($m['tiempos'][$k][$m['b']])) : '' ?>" placeholder="tiempo <?= e($nb) ?>" style="width:110px" inputmode="decimal">
                    <?php endif; ?></div>
                <?php endfor; endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <p class="ayuda"><?= $f === 'persecucion' ? 'Escriba los tiempos (gana el menor) o elija el ganador si uno alcanzó al otro.' : 'Elija quién ganó cada manga; la 3.ª solo si van 1 a 1.' ?> Cuando una ronda termina, el sistema arma la siguiente.</p>
          <button class="btn">💾 Guardar mangas</button>
        </form>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Borrar todo el cuadro con sus resultados?')"><?= $hid ?><input type="hidden" name="sub" value="borrar_series"><input type="hidden" name="fase" value=""><button class="btn btn-peligro btn-chico">Borrar el cuadro</button></form>
      <?php endif; ?>
      <style>.duelo{border:1px solid var(--borde,#ddd);border-radius:8px;padding:6px 10px;margin:6px 0}</style>
    <?php elseif ($f === 'keirin' || $f === 'series'):
        $se = pista_series($ev); ?>
      <?php if (!$se['fases']): ?>
        <p class="ayuda"><?= $f === 'series' ? 'BMX (UCI): se arman motos de hasta ' . (int)$cfg['por_serie'] . ' pilotos con ' . (int)$cfg['mangas'] . ' mangas; en cada manga se escribe el puesto de llegada (son los puntos: gana el que suma menos). Pasan los ' . (int)$cfg['pasan'] . ' mejores de cada moto a la siguiente ronda (una sola manga) hasta la final de ' . (int)$cfg['final'] . '. Con ' . (int)$cfg['por_serie'] . ' o menos pilotos, una sola moto decide (no hay final).' : 'Keirin (UCI): series de hasta ' . (int)$cfg['por_serie'] . ' ciclistas; pasan los mejores de cada una hasta la final de ' . (int)$cfg['final'] . '.' ?>
          Los pilotos se reparten en zigzag según su tiempo en la columna «marca» (si la cargó) o su número.</p>
        <form method="post"><?= $hid ?><input type="hidden" name="sub" value="series_armar"><button class="btn">🏁 Armar <?= $f === 'series' ? 'las motos' : 'las series' ?></button></form>
      <?php else: ?>
        <p class="ayuda">En cada casilla escriba el <b>puesto de llegada</b> (1, 2, 3…) o <b>DNF</b> (no terminó), <b>DNS</b> (no largó), <b>REL</b> (relegado), <b>DQ</b> (descalificado). Puntos: DNF = los que largaron; REL o DQ = los que largaron + 2; DNS = los de la serie + 2.</p>
        <form method="post"><?= $hid ?><input type="hidden" name="sub" value="series_guardar">
          <?php foreach ($se['fases'] as $fase => $series): ?>
            <h4 style="margin:.7rem 0 .3rem"><?= e(pista_nombre_ronda($fase, count($series), (int)reset($series)['mangas'], $f)) ?></h4>
            <div class="rejilla-2">
            <?php foreach ($series as $s => $x):
                $raw = [];
                foreach (filas('SELECT * FROM prueba_series WHERE evento_id = ? AND fase = ? AND serie = ?', [$ev['id'], $fase, $s]) as $y) $raw[(int)$y['manga']][(int)$y['participante_id']] = $y; ?>
              <div class="tarjeta" style="padding:8px">
                <b><?= $fase === 'f' ? 'Final' : ($f === 'series' && $fase === 'r1' ? 'Moto ' : 'Serie ') . $s ?></b> <?= $x['completa'] ? '<span class="etiqueta">completa</span>' : '' ?>
                <div class="tabla-scroll"><table>
                  <tr><th><?= e($T['carril']) ?></th><th><?= e($T['participante']) ?></th><?php for ($m = 1; $m <= $x['mangas']; $m++): ?><th><?= $x['mangas'] > 1 ? 'M' . $m : 'Puesto' ?></th><?php endfor; ?><?php if ($x['mangas'] > 1): ?><th class="num">Pts</th><?php endif; ?><th class="num">#</th></tr>
                  <?php foreach ($x['pids'] as $i => $pid): $o = $x['orden'][$pid]; ?>
                    <tr><td><?= $i + 1 ?></td><td><?= e($part[$pid]['nombre'] ?? '?') ?></td>
                      <?php for ($m = 1; $m <= $x['mangas']; $m++): $y = $raw[$m][$pid] ?? null; $val = $y ? ($y['estado'] !== 'ok' ? strtoupper($y['estado']) : ($y['puesto'] !== null ? (string)$y['puesto'] : '')) : ''; ?>
                        <td><input type="text" name="r[<?= $fase ?>][<?= $s ?>][<?= $m ?>][<?= $pid ?>]" value="<?= e($val) ?>" style="width:46px" maxlength="3"></td>
                      <?php endfor; ?>
                      <?php if ($x['mangas'] > 1): ?><td class="num"><?= $x['completa'] ? (int)$o['pts'] : '' ?></td><?php endif; ?>
                      <td class="num"><?= $x['completa'] ? (int)$o['pos'] : '' ?></td></tr>
                  <?php endforeach; ?>
                </table></div>
              </div>
            <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <button class="btn" style="margin-top:8px">💾 Guardar resultados</button>
        </form>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Borrar todas las series con sus resultados?')"><?= $hid ?><input type="hidden" name="sub" value="borrar_series"><input type="hidden" name="fase" value=""><button class="btn btn-peligro btn-chico">Borrar todas las series</button></form>
      <?php endif; ?>
    <?php elseif ($f === 'jueces'):
        $ju = pista_jueces($ev);
        $fin = pista_json($ev['datos'] ?? '')['finalistas'] ?? [];
        $fin = array_values(array_filter(array_map('intval', (array)$fin), fn($pid) => isset($part[$pid])));
        $grilla = function (string $fase, array $pids) use ($ju, $cfg, $part, $T) { ?>
          <div class="tabla-scroll"><table class="tabla-grilla">
            <tr><th><?= e($T['participante']) ?></th><?php for ($m = 1; $m <= (int)$cfg['pasadas']; $m++): ?><th>Pasada <?= $m ?> <small class="suave">(<?= (int)$cfg['jueces'] ?> jueces)</small></th><?php endfor; ?><th class="num">Puntaje</th><th>DNS</th></tr>
            <?php foreach ($pids as $pid): $x = $ju[$fase][$pid] ?? ['notas' => [], 'pasadas' => [], 'valor' => null, 'dns' => false]; ?>
              <tr><td style="white-space:normal;min-width:120px"><?= e($part[$pid]['nombre'] ?? '?') ?><br><small class="suave"><?= e($part[$pid]['club'] ?? '') ?></small></td>
                <?php for ($m = 1; $m <= (int)$cfg['pasadas']; $m++): ?>
                  <td style="white-space:nowrap"><?php for ($k = 1; $k <= (int)$cfg['jueces']; $k++): ?><input type="text" inputmode="decimal" name="j[<?= $fase ?>][<?= $pid ?>][<?= $m ?>][<?= $k ?>]" value="<?= isset($x['notas'][$m][$k]) ? e(rtrim(rtrim(number_format((float)$x['notas'][$m][$k], 2, ',', ''), '0'), ',')) : '' ?>" style="width:46px" title="Juez <?= $k ?>"> <?php endfor; ?>
                    <?php if (isset($x['pasadas'][$m])): ?><br><small class="suave">= <?= e(number_format($x['pasadas'][$m], 2, ',', '')) ?></small><?php endif; ?></td>
                <?php endfor; ?>
                <td class="num"><b><?= $x['valor'] !== null ? e(number_format($x['valor'], 2, ',', '')) : '' ?></b></td>
                <td><input type="checkbox" name="dns[<?= $fase ?>][<?= $pid ?>]" value="1" <?= $x['dns'] ? 'checked' : '' ?>></td></tr>
            <?php endforeach; ?>
          </table></div>
        <?php };
        $pids_q = array_map(fn($r) => (int)$r['participante_id'], array_filter($filas, fn($r) => $r['estado'] !== 'dns')); ?>
      <p class="ayuda"><b>Freestyle (UCI):</b> cada juez pone una nota de 0 a 100 por pasada (impresión general: dificultad, variedad, ejecución, amplitud, flujo). El puntaje de la pasada es el promedio de los jueces<?= (int)$cfg['descarte'] ? ' sin la nota más alta y la más baja' : '' ?>. Clasificatoria: <?= $cfg['q_modo'] === 'mejor' ? 'la mejor pasada' : 'el promedio de las ' . (int)$cfg['pasadas'] . ' pasadas' ?>. <?= (int)$cfg['finalistas'] ? 'Final: los ' . (int)$cfg['finalistas'] . ' mejores; vale la mejor pasada.' : 'Sin final.' ?></p>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="jueces_guardar">
        <h4 style="margin:.5rem 0 .3rem">Clasificatoria</h4>
        <?php $grilla('q', $pids_q); ?>
        <?php if ($fin): ?><h4 style="margin:.8rem 0 .3rem">Final</h4><?php $grilla('f', $fin); endif; ?>
        <button class="btn" style="margin-top:8px">💾 Guardar notas</button>
      </form>
      <?php if ((int)$cfg['finalistas'] && !$fin): ?>
        <form method="post" style="margin-top:8px"><?= $hid ?><input type="hidden" name="sub" value="final_armar"><button class="btn btn-sec">🏆 Pasar a la final con los <?= (int)$cfg['finalistas'] ?> mejores</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <style>.tabla-grilla td,.tabla-grilla th{padding:4px 5px}.tabla-grilla input{padding:4px}</style>
    <?php return (string)ob_get_clean();
}

/** Detalle público (cuadro, series o notas) debajo de la tabla de resultados. */
function pista_html_detalle(array $t, array $ev, array $part): string
{
    $f = (string)$ev['formato'];
    $T = textos_pruebas($t);
    ob_start();
    if ($f === 'velocidad' || $f === 'persecucion'):
        $cu = pista_cuadro($ev);
        if (!$cu['partidos']) return '';
        $q = pista_clasificatoria($ev); ?>
      <h3>Clasificatoria<?= $f === 'velocidad' ? ' (200 m lanzados)' : '' ?></h3>
      <div class="tabla-scroll"><table><tr><th>#</th><th><?= e($T['participante']) ?></th><th class="num">Tiempo</th></tr>
        <?php $i = 0; foreach ($q as $pid => $ms): ?><tr><td><?= ++$i ?></td><td><?= e($part[$pid]['nombre'] ?? '') ?></td><td class="num"><?= e(prueba_fmt_marca('tiempo', $ms)) ?></td></tr><?php endforeach; ?>
      </table></div>
      <h3>Cuadro</h3>
      <?php foreach (['c8', 'c4', 'sf', 'b', 'f'] as $fase): if (empty($cu['partidos'][$fase])) continue; ?>
        <p style="margin:.4rem 0 .2rem"><b><?= e(pista_nombre_fase($fase)) ?></b></p>
        <?php foreach ($cu['partidos'][$fase] as $m):
            $mangas = [];
            foreach ($m['mangas'] as $k => $g) $mangas[] = ($cu['mejor_de'] > 1 ? 'M' . $k . ': ' : '') . ($part[$g]['nombre'] ?? '') . (isset($m['tiempos'][$k][$g]) ? ' ' . carrera_fmt_tiempo($m['tiempos'][$k][$g]) : ''); ?>
          <div class="duelo"><?= $m['ganador'] === $m['a'] ? '🏅 ' : '' ?><b><?= e($part[$m['a']]['nombre'] ?? '') ?></b> vs <?= $m['b'] && $m['ganador'] === $m['b'] ? '🏅 ' : '' ?><b><?= e($m['b'] ? ($part[$m['b']]['nombre'] ?? '') : '—') ?></b>
            <?= $mangas ? '<br><small class="suave">' . e(implode(' · ', $mangas)) . '</small>' : '' ?></div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <style>.duelo{border:1px solid var(--borde,#ddd);border-radius:8px;padding:6px 10px;margin:6px 0}</style>
    <?php elseif ($f === 'keirin' || $f === 'series'):
        $se = pista_series($ev);
        if (!$se['fases']) return '';
        foreach (array_reverse($se['fases'], true) as $fase => $series): ?>
        <h3><?= e(pista_nombre_ronda($fase, count($series), (int)reset($series)['mangas'], $f)) ?></h3>
        <div class="rejilla-2">
        <?php foreach ($series as $s => $x): ?>
          <div><?php if ($fase !== 'f'): ?><b><?= ($f === 'series' && $fase === 'r1' ? 'Moto ' : 'Serie ') . $s ?></b><?php endif; ?>
            <div class="tabla-scroll"><table><tr><th>#</th><th><?= e($T['participante']) ?></th><?php if ($x['mangas'] > 1): for ($m = 1; $m <= $x['mangas']; $m++): ?><th class="num">M<?= $m ?></th><?php endfor; ?><th class="num">Pts</th><?php endif; ?></tr>
              <?php foreach ($x['orden'] as $pid => $o): ?><tr><td><?= $x['completa'] ? (int)$o['pos'] : '' ?></td><td><?= e($part[$pid]['nombre'] ?? '') ?></td>
                <?php if ($x['mangas'] > 1): for ($m = 1; $m <= $x['mangas']; $m++): ?><td class="num"><?= e($o['mangas'][$m] ?? '') ?></td><?php endfor; ?><td class="num"><b><?= (int)$o['pts'] ?></b></td><?php endif; ?></tr><?php endforeach; ?>
            </table></div></div>
        <?php endforeach; ?>
        </div>
      <?php endforeach;
    elseif ($f === 'jueces'):
        return '<p class="suave" style="font-size:.85rem">Puntaje de 0 a 100 por pasada (promedio de los jueces). Debajo de cada rider, el detalle de sus pasadas.</p>';
    else:
        return '';
    endif;
    return (string)ob_get_clean();
}

/* ============================================================
 * EJEMPLO
 * ============================================================ */

function pista_cargar_ejemplo(array $t): void
{
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM prueba_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene competidores. El ejemplo solo se carga en uno vacío.');
    $m = (string)$t['modalidad'];
    $anio = preg_match('/(\d{4})/', (string)$t['temporada'], $a) ? (int)$a[1] : (int)date('Y');
    $cat = $m === 'PISTA' ? 4 : ($m === 'BMX' ? 6 : 2); // Élite
    $pruebas = array_column(pista_catalogo($m), 0);
    if ($m === 'PISTA') $pruebas = ['Velocidad individual', 'Keirin', 'Kilómetro contrarreloj', 'Persecución individual', 'Scratch', 'Carrera por puntos', 'Omnium'];
    pruebas_crear_programa($t, $pruebas, ['M'], [$cat], date('Y-m-d'));
    $clubes = $m === 'BMX' ? ['BMX Pichincha', 'Rampa Norte', 'Valle BMX'] : ($m === 'BMXFS' ? ['Skatepark Quito', 'Flat Crew', 'Park Sur'] : ['Velódromo Quito', 'Pista Azuay', 'Team Imbabura']);
    $nombres = ['Mateo Andrade', 'Sebastián Vera', 'Nicolás Paredes', 'Martín Salazar', 'Daniel Cevallos', 'Joaquín Mena', 'Tomás Herrera', 'Emilio Castillo', 'Andrés Villacís', 'Luis Tapia',
        'Diego Proaño', 'Jorge Endara'];
    if ($m === 'BMX') $nombres = array_merge($nombres, ['Kevin Pozo', 'Bryan Toapanta', 'Iván Jaramillo', 'Pablo Cevallos', 'Carlos Quishpe', 'Ángel Guamán']);
    foreach ($nombres as $i => $nom) {
        q('INSERT INTO prueba_participantes (torneo_id, numero, nombre, club, ciudad, rama, nacimiento) VALUES (?,?,?,?,?,?,?)',
            [$tid, (string)(10 + $i), $nom, $clubes[$i % 3], 'Quito', 'M', ($anio - 21 - $i % 6) . '-0' . (1 + $i % 9) . '-12']);
    }
    $part = pruebas_participantes($tid);
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
    mt_srand(3434 + strlen($m));
    foreach (pruebas_eventos($tid) as $ev) {
        $k = 0;
        foreach ($part as $p) q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id, carril) VALUES (?,?,?)', [$ev['id'], $p['id'], ++$k]);
        $pids = array_keys($part);
        $f = (string)$ev['formato'];
        if ($f === 'marca') {
            foreach ($pids as $i => $pid) {
                $v = $ev['medida'] === 'puesto' ? $i + 1 : ($ev['nombre'] === 'Kilómetro contrarreloj' ? 64000 + $i * 650 + mt_rand(0, 500) : 38000 + $i * 400 + mt_rand(0, 300));
                q('UPDATE prueba_resultados SET marca = ? WHERE evento_id = ? AND participante_id = ?', [$v, $ev['id'], $pid]);
            }
            if ($ev['medida'] === 'puesto') { // desordena un poco la llegada
                $orden = $pids;
                shuffle($orden);
                foreach ($orden as $i => $pid) q('UPDATE prueba_resultados SET marca = ? WHERE evento_id = ? AND participante_id = ?', [$i + 1, $ev['id'], $pid]);
            }
        } elseif ($f === 'puntos') {
            $cfg = pista_cfg($ev);
            foreach ($pids as $i => $pid) {
                $s = [];
                for ($x = 1; $x < (int)$cfg['sprints']; $x++) $s[] = ($x + $i) % 4 === 0 ? 1 + ($i % 4) : 0;
                $d = ['s' => $s, 'lleg' => $i + 1, 'vg' => $i === 3 ? 1 : 0, 'vp' => $i === 10 ? 1 : 0];
                q('UPDATE prueba_resultados SET datos = ? WHERE evento_id = ? AND participante_id = ?', [json_encode($d), $ev['id'], $pid]);
            }
        } elseif ($f === 'omnium') {
            foreach ($pids as $i => $pid) {
                $d = ['sc' => ($i * 5) % 12 + 1, 'te' => ($i * 7) % 12 + 1, 'el' => ($i * 11) % 12 + 1, 'pr' => [12, -20, 5, 8, 0, 23, 3, 1, 0, 6, -20, 2][$i], 'lleg' => ($i * 5 + 3) % 12 + 1];
                q('UPDATE prueba_resultados SET datos = ? WHERE evento_id = ? AND participante_id = ?', [json_encode($d), $ev['id'], $pid]);
            }
        } elseif ($f === 'velocidad' || $f === 'persecucion') {
            foreach ($pids as $i => $pid) q('UPDATE prueba_resultados SET marca = ? WHERE evento_id = ? AND participante_id = ?',
                [$f === 'velocidad' ? 10200 + $i * 90 + mt_rand(0, 60) : 268000 + $i * 1500 + mt_rand(0, 900), $ev['id'], $pid]);
            pista_cuadro_armar($ev);
            for ($r = 0; $r < 4; $r++) { // corre todas las rondas: gana el mejor clasificado salvo una sorpresa
                $cu = pista_cuadro($ev);
                foreach ($cu['partidos'] as $fase => $ps) foreach ($ps as $s => $mm) {
                    if ($mm['ganador'] !== null || !$mm['b']) continue;
                    $gana = ($fase === 'c4' && $s === 3) ? $mm['b'] : $mm['a'];
                    for ($k = 1; $k <= $cu['mejor_de'] && $k <= 2; $k++) {
                        q('UPDATE prueba_series SET puesto = CASE WHEN participante_id = ? THEN 1 ELSE 2 END, tiempo_ms = ? WHERE evento_id = ? AND fase = ? AND serie = ? AND manga = ?',
                            [$gana, $f === 'persecucion' ? 266000 + mt_rand(0, 3000) : null, $ev['id'], $fase, $s, $k]);
                    }
                }
                pista_cuadro_avanzar($ev);
            }
        } elseif ($f === 'keirin' || $f === 'series') {
            pista_series_armar($ev);
            for ($r = 0; $r < 5; $r++) {
                $se = pista_series($ev);
                foreach ($se['fases'] as $fase => $series) foreach ($series as $s => $x) {
                    if ($x['completa']) continue;
                    for ($mg = 1; $mg <= $x['mangas']; $mg++) {
                        $orden = $x['pids'];
                        if ($mg % 2 === 0) { $tmp = $orden[0]; $orden[0] = $orden[1] ?? $orden[0]; if (isset($orden[1])) $orden[1] = $tmp; }
                        foreach ($orden as $i => $pid) {
                            $est = ($fase === 'r1' && $s === 1 && $mg === 3 && $i === count($orden) - 1) ? 'dnf' : 'ok';
                            q('UPDATE prueba_series SET puesto = ?, estado = ? WHERE evento_id = ? AND fase = ? AND serie = ? AND manga = ? AND participante_id = ?',
                                [$est === 'ok' ? $i + 1 : null, $est, $ev['id'], $fase, $s, $mg, $pid]);
                        }
                    }
                }
                pista_series_avanzar($ev);
            }
        } elseif ($f === 'jueces') {
            $cfg = pista_cfg($ev);
            foreach ($pids as $i => $pid) for ($mg = 1; $mg <= (int)$cfg['pasadas']; $mg++) {
                $base = 88 - $i * 3.5 + mt_rand(-30, 30) / 10;
                $notas = [];
                for ($k = 1; $k <= (int)$cfg['jueces']; $k++) $notas[$k] = round(max(0, min(100, $base + mt_rand(-20, 20) / 10)), 1);
                q('INSERT INTO prueba_series (evento_id, fase, serie, manga, participante_id, datos) VALUES (?,?,1,?,?,?)', [$ev['id'], 'q', $mg, $pid, json_encode($notas)]);
            }
        }
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}
