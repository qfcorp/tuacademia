<?php
/*
 * (v22) CAMPEONATOS DE CARRERAS: automovilismo (autocross) y ciclismo.
 *
 * Un campeonato de carreras NO usa equipos ni fixture: tiene
 *   categorías     → por cilindrada y tipo de motor (autocross) o por edad / rama (ciclismo)
 *   participantes  → piloto o ciclista, con número, vehículo, cilindrada y aspiración (atmosférico / turbo)
 *   fechas         → cada carrera del campeonato, con una o varias mangas
 *   resultados     → tiempo por manga, conos tumbados, penalizaciones o bonificaciones en segundos y estado
 *
 * Tiempo final de una manga = tiempo + conos × penalización por cono + ajuste (segundos; negativo = bonificación).
 * Una fecha se califica con la MEJOR manga o con la SUMA de mangas. Cada categoría tiene su propio orden de llegada;
 * además hay una clasificación absoluta. El campeonato se puntúa por puesto (con descarte de peores fechas) o por tiempo total.
 */

function modalidades_carrera(): array
{
    return ['AUTOX' => ['Autocross (automovilismo)', '🏎', 'autocross'], 'CICLO' => ['Ciclismo de ruta', '🚴', 'ciclismo'], 'MTB' => ['Ciclismo de montaña (MTB)', '🚵', 'mtb']];
}

/** ¿El campeonato (o su modalidad) es de carreras? */
function es_carrera($t): bool
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return isset(modalidades_carrera()[$m]);
}

/** (v23) Condición SQL para dejar fuera los campeonatos de carreras (pantallas que solo sirven para deportes de equipo). */
function sql_sin_carreras(string $alias = ''): string
{
    $col = ($alias !== '' ? $alias . '.' : '') . 'modalidad';
    // (v25) también duelos (tenis, pádel, artes marciales, boxeo…) y bolos: todo lo individual
    $mods = array_keys(function_exists('modalidades_individuales') ? modalidades_individuales() : modalidades_carrera());
    return $col . " NOT IN ('" . implode("','", $mods) . "')";
}

function icono_carrera($t): string
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return modalidades_carrera()[$m][1] ?? '🏁';
}

/** Textos según el deporte de carreras. */
function textos_carrera($t): array
{
    $mod = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    if ($mod === 'MTB') return ['participante' => 'Ciclista', 'participantes' => 'Ciclistas', 'fecha' => 'Carrera', 'fechas' => 'Carreras', 'manga' => 'Bajada / tramo', 'vehiculo' => 'Bicicleta', 'con_cc' => false];
    return $mod === 'CICLO'
        ? ['participante' => 'Ciclista', 'participantes' => 'Ciclistas', 'fecha' => 'Carrera', 'fechas' => 'Carreras', 'manga' => 'Etapa', 'vehiculo' => 'Bicicleta', 'con_cc' => false]
        : ['participante' => 'Piloto', 'participantes' => 'Pilotos', 'fecha' => 'Fecha', 'fechas' => 'Fechas', 'manga' => 'Manga', 'vehiculo' => 'Vehículo', 'con_cc' => true];
}

/* ============================================================
 * TIEMPOS
 * ============================================================ */

/** "1:23.456", "83,456", "1:02:03.5" o "83" → milisegundos (null si está vacío o mal escrito). */
function carrera_parse_tiempo(string $s): ?int
{
    $s = trim(str_replace(',', '.', $s));
    if ($s === '') return null;
    $partes = explode(':', $s);
    if (count($partes) > 3) return null;
    $seg = 0.0;
    foreach ($partes as $p) {
        if (!preg_match('/^\d+(\.\d+)?$/', trim($p))) return null;
        $seg = $seg * 60 + (float)$p;
    }
    return (int)round($seg * 1000);
}

/** Milisegundos → "1:23.456" (con horas si pasa de 60 minutos). */
function carrera_fmt_tiempo(?int $ms): string
{
    if ($ms === null) return '—';
    $ms = max(0, $ms);
    $h = intdiv($ms, 3600000);
    $m = intdiv($ms % 3600000, 60000);
    $s = intdiv($ms % 60000, 1000);
    $milis = $ms % 1000;
    return $h > 0 ? sprintf('%d:%02d:%02d.%03d', $h, $m, $s, $milis) : ($m > 0 ? sprintf('%d:%02d.%03d', $m, $s, $milis) : sprintf('%d.%03d', $s, $milis));
}

/** Diferencia: "+1.234". */
function carrera_fmt_dif(?int $ms): string
{
    if ($ms === null) return '';
    return $ms <= 0 ? '' : '+' . carrera_fmt_tiempo($ms);
}

/* ============================================================
 * (v23) PENALIZACIONES CON NOMBRE
 * ============================================================ */

/** Catálogo típico de cada deporte: [nombre, segundos] (negativo = bonificación). Los conos van aparte. */
function carrera_penalidades_base(string $modalidad): array
{
    if ($modalidad === 'MTB') return [['Ayuda externa fuera de la zona técnica', 60], ['Recortar el circuito', 120], ['Conducta antideportiva', 30], ['Salida adelantada', 10]];
    return $modalidad === 'CICLO'
        ? [['Ayuda externa indebida (empuje)', 10], ['Corte de ruta', 60], ['Conducta antideportiva', 30], ['Bonificación: meta volante', -3], ['Bonificación: llegada (1.°)', -10]]
        : [['Salida en falso', 5], ['Puerta no pasada', 10], ['Salirse del trazado', 10], ['Exceder velocidad en boxes', 20], ['Conducta antideportiva', 30]];
}

function carrera_cargar_penalidades_base(array $t): int
{
    $n = 0;
    $orden = (int)valor('SELECT COALESCE(MAX(orden), 0) FROM carrera_penalidades WHERE torneo_id = ?', [$t['id']]);
    $ya = array_map('mb_strtolower', array_column(filas('SELECT nombre FROM carrera_penalidades WHERE torneo_id = ?', [$t['id']]), 'nombre'));
    foreach (carrera_penalidades_base((string)$t['modalidad']) as [$nom, $seg]) {
        if (in_array(mb_strtolower($nom), $ya, true)) continue;
        q('INSERT INTO carrera_penalidades (torneo_id, nombre, segundos, orden) VALUES (?,?,?,?)', [$t['id'], $nom, $seg, ++$orden]);
        $n++;
    }
    return $n;
}

/** Penalizaciones del campeonato: [id => fila]. */
function carrera_penalidades(int $tid, bool $solo_activas = false): array
{
    $r = [];
    foreach (filas('SELECT * FROM carrera_penalidades WHERE torneo_id = ?' . ($solo_activas ? ' AND activo = 1' : '') . ' ORDER BY orden, id', [$tid]) as $p) $r[(int)$p['id']] = $p;
    return $r;
}

/** Lee el JSON de penalizaciones de una manga: [id => veces]. */
function carrera_pens_de(array $r): array
{
    $d = json_decode((string)($r['pens'] ?? ''), true);
    if (!is_array($d)) return [];
    $o = [];
    foreach ($d as $k => $v) if ((int)$v > 0) $o[(int)$k] = min(99, (int)$v);
    return $o;
}

/** Segundos que suman las penalizaciones con nombre de una manga. */
function carrera_seg_pens(array $r, array $cfg): float
{
    $s = 0.0;
    foreach (carrera_pens_de($r) as $id => $veces) $s += $veces * (float)($cfg['pens'][$id]['segundos'] ?? 0);
    return $s;
}

/** Texto corto: "Salida en falso +5 s, Puerta no pasada ×2 +20 s". */
function carrera_texto_pens(array $r, array $cfg): string
{
    $t = [];
    foreach (carrera_pens_de($r) as $id => $veces) {
        if (!isset($cfg['pens'][$id])) continue;
        $seg = $veces * (float)$cfg['pens'][$id]['segundos'];
        $t[] = $cfg['pens'][$id]['nombre'] . ($veces > 1 ? ' ×' . $veces : '') . ' ' . ($seg > 0 ? '+' : '') . rtrim(rtrim(number_format($seg, 3, '.', ''), '0'), '.') . ' s';
    }
    return implode(', ', $t);
}

function carrera_estados(): array
{
    return ['ok' => 'Terminó', 'dnf' => 'No terminó (DNF)', 'dsq' => 'Descalificado (DSQ)', 'dns' => 'No largó (DNS)', 'otl' => 'Fuera de control (OTL)'];
}

/** Reglas del campeonato de carreras (con valores por omisión). */
function carrera_cfg(array $t): array
{
    $raw = preg_split('/[\s,;]+/', trim((string)($t['carrera_puntos'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $puntos = array_map('intval', $raw);
    if (!$puntos) $puntos = [25, 20, 16, 13, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];
    return [
        'puntos' => $puntos,
        'mangas' => max(1, min(10, (int)($t['carrera_mangas'] ?? 2))),
        'modo' => (($t['modalidad'] ?? '') === 'CICLO' || ($t['carrera_modo'] ?? 'mejor') === 'suma') ? 'suma' : 'mejor', // (v34) en ruta siempre se suman las etapas
        'pen_cono' => in_array($t['modalidad'] ?? '', ['CICLO', 'MTB'], true) ? 0.0 : max(0.0, (float)($t['carrera_pen_cono'] ?? 2)), // (v34) en ciclismo no hay conos
        'descarte' => max(0, min(10, (int)($t['carrera_descarte'] ?? 0))),
        'clasif' => ($t['carrera_clasif'] ?? 'puntos') === 'tiempo' ? 'tiempo' : 'puntos',
        'pens' => isset($t['id']) ? carrera_penalidades((int)$t['id']) : [],
    ];
}

/** Tiempo final de una manga en ms (null si no terminó o no tiene tiempo). */
function carrera_tiempo_final(array $r, array $cfg): ?int
{
    if (($r['estado'] ?? 'ok') !== 'ok' || $r['tiempo_ms'] === null || $r['tiempo_ms'] === '') return null;
    $ms = (int)$r['tiempo_ms'] + (int)round((int)$r['conos'] * $cfg['pen_cono'] * 1000) + (int)round((float)$r['ajuste_seg'] * 1000)
        + (int)round(carrera_seg_pens($r, $cfg) * 1000);
    return max(0, $ms);
}

/* ============================================================
 * CATEGORÍAS
 * ============================================================ */

function carrera_categorias(int $tid): array
{
    return filas('SELECT * FROM carrera_categorias WHERE torneo_id = ? ORDER BY orden, id', [$tid]);
}

/** Texto de una categoría: "1.301 a 1.600 cc · atmosférico". */
function carrera_texto_categoria(array $c): string
{
    $mn = (int)$c['cc_min'];
    $mx = (int)$c['cc_max'];
    $p = [];
    if ($mn > 0 && $mx > 0) $p[] = number_format($mn, 0, ',', '.') . ' a ' . number_format($mx, 0, ',', '.') . ' cc';
    elseif ($mx > 0) $p[] = 'hasta ' . number_format($mx, 0, ',', '.') . ' cc';
    elseif ($mn > 0) $p[] = 'más de ' . number_format($mn - 1, 0, ',', '.') . ' cc';
    if ($c['aspiracion'] === 'turbo') $p[] = 'turbo / sobrealimentado';
    elseif ($c['aspiracion'] === 'atmosferico') $p[] = 'atmosférico';
    if ($c['nota'] !== '') $p[] = $c['nota'];
    return implode(' · ', $p);
}

/** Categoría que le corresponde por cilindrada y aspiración (la primera que cumpla, en el orden de las categorías). */
function carrera_categoria_auto(int $tid, int $cc, string $asp): ?int
{
    foreach (carrera_categorias($tid) as $c) {
        if ($c['aspiracion'] === 'turbo' && $asp !== 'turbo') continue;
        if ($c['aspiracion'] === 'atmosferico' && $asp === 'turbo') continue;
        if ((int)$c['cc_min'] === 0 && (int)$c['cc_max'] === 0 && $c['aspiracion'] === '') continue; // categoría manual
        if ($cc > 0 || ((int)$c['cc_min'] === 0 && (int)$c['cc_max'] === 0)) {
            if ((int)$c['cc_min'] > 0 && $cc < (int)$c['cc_min']) continue;
            if ((int)$c['cc_max'] > 0 && $cc > (int)$c['cc_max']) continue;
            return (int)$c['id'];
        }
    }
    return null;
}

/** Categorías de fábrica. */
function carrera_categorias_base(string $modalidad): array
{
    if ($modalidad === 'MTB') {
        return [['Élite', 0, 0, ''], ['Damas élite', 0, 0, ''], ['Sub-23', 0, 0, ''], ['Júnior (17-18)', 0, 0, ''], ['Máster 30+', 0, 0, ''], ['Máster 40+', 0, 0, ''], ['Damas máster', 0, 0, '']];
    }
    if ($modalidad === 'CICLO') {
        return [['Élite', 0, 0, ''], ['Sub-23', 0, 0, ''], ['Juvenil', 0, 0, ''], ['Máster A', 0, 0, ''], ['Máster B', 0, 0, ''], ['Damas', 0, 0, '']];
    }
    return [['Turbo / sobrealimentados', 0, 0, 'turbo'], ['Hasta 1.300 cc', 0, 1300, 'atmosferico'], ['1.301 a 1.600 cc', 1301, 1600, 'atmosferico'],
        ['1.601 a 2.000 cc', 1601, 2000, 'atmosferico'], ['Más de 2.000 cc', 2001, 0, 'atmosferico']];
}

function carrera_cargar_categorias_base(array $t): int
{
    $n = (int)valor('SELECT COALESCE(MAX(orden), 0) FROM carrera_categorias WHERE torneo_id = ?', [$t['id']]);
    $existentes = array_column(carrera_categorias((int)$t['id']), 'nombre');
    $k = 0;
    foreach (carrera_categorias_base((string)$t['modalidad']) as [$nombre, $mn, $mx, $asp]) {
        if (in_array($nombre, $existentes, true)) continue;
        q('INSERT INTO carrera_categorias (torneo_id, nombre, orden, cc_min, cc_max, aspiracion) VALUES (?,?,?,?,?,?)', [$t['id'], $nombre, ++$n, $mn, $mx, $asp]);
        $k++;
    }
    return $k;
}

/* ============================================================
 * RESULTADOS DE UNA FECHA
 * ============================================================ */

function carrera_fechas(int $tid): array
{
    return filas('SELECT * FROM carrera_fechas WHERE torneo_id = ? ORDER BY num, id', [$tid]);
}

function carrera_mangas_fecha(array $f, array $cfg): int
{
    return (int)$f['mangas'] > 0 ? min(10, (int)$f['mangas']) : $cfg['mangas'];
}

/**
 * Resultado de cada participante en una fecha (solo los que tienen algún dato).
 * Cada fila: p (participante), mangas [num => ['final', 'estado', ...]], valor (ms o null), estado (ok|dnf|dsq|dns), mejor (mejor manga)
 */
function carrera_calcular_fecha(array $t, array $f): array
{
    if (($t['modalidad'] ?? '') === 'CICLO') return ciclo_calcular_fecha($t, $f); // (v34) reglas UCI de ruta
    $cfg = carrera_cfg($t);
    $fmt_mtb = mtb_formato($t, $f); // (v34) MTB: xco | mejor | suma
    if ($fmt_mtb !== '') $cfg['modo'] = $fmt_mtb === 'mejor' ? 'mejor' : 'suma';
    $nm = carrera_mangas_fecha($f, $cfg);
    $part = [];
    foreach (filas('SELECT * FROM carrera_participantes WHERE torneo_id = ?', [$t['id']]) as $p) $part[(int)$p['id']] = $p;
    $por = [];
    foreach (filas('SELECT * FROM carrera_resultados WHERE fecha_id = ? ORDER BY manga', [$f['id']]) as $r) {
        if (!isset($part[(int)$r['participante_id']])) continue;
        $por[(int)$r['participante_id']][(int)$r['manga']] = $r;
    }
    $out = [];
    foreach ($por as $pid => $rs) {
        $mangas = [];
        $validos = [];
        $estados = [];
        for ($m = 1; $m <= $nm; $m++) {
            $r = $rs[$m] ?? null;
            if (!$r) { $mangas[$m] = null; continue; }
            $fin = carrera_tiempo_final($r, $cfg);
            $mangas[$m] = ['final' => $fin, 'estado' => $r['estado'], 'tiempo_ms' => $r['tiempo_ms'], 'conos' => (int)$r['conos'], 'ajuste_seg' => (float)$r['ajuste_seg'],
                'pens_txt' => carrera_texto_pens($r, $cfg), 'motivo' => (string)($r['motivo'] ?? '')];
            if ($fin !== null) $validos[$m] = $fin;
            $estados[] = $r['estado'];
        }
        $valor = null;
        if ($cfg['modo'] === 'suma') {
            if (count($validos) === $nm) $valor = array_sum($validos);
        } elseif ($validos) {
            $valor = min($validos);
        }
        $estado = 'ok';
        if ($valor === null) {
            $estado = in_array('dsq', $estados, true) ? 'dsq' : (in_array('dnf', $estados, true) ? 'dnf' : (in_array('dns', $estados, true) && !$validos ? 'dns' : 'dnf'));
            if (!$estados) continue;
        }
        $motivo = '';
        if ($valor === null) foreach ($mangas as $g) if ($g && $g['estado'] === $estado && $g['motivo'] !== '') { $motivo = $g['motivo']; break; }
        $vueltas = 0;
        foreach ($rs as $r) $vueltas = max($vueltas, (int)($r['vueltas'] ?? 0));
        $out[] = ['p' => $part[$pid], 'mangas' => $mangas, 'valor' => $valor, 'estado' => $estado, 'mejor' => $validos ? min($validos) : null, 'motivo' => $motivo, 'vueltas' => $vueltas];
    }
    if ($fmt_mtb === 'xco') $out = mtb_aplicar_vueltas($f, $out);
    return $out;
}

/** Ordena, numera los puestos y calcula diferencias. Los que no terminaron van al final sin puesto. */
function carrera_ordenar(array $lista): array
{
    $ok = array_values(array_filter($lista, fn($x) => $x['valor'] !== null));
    $no = array_values(array_filter($lista, fn($x) => $x['valor'] === null));
    usort($ok, fn($a, $b) => isset($a['clave'], $b['clave']) ? $a['clave'] <=> $b['clave'] // (v34) desempates de ruta / vueltas de MTB
        : [$a['valor'], $a['mejor'] ?? PHP_INT_MAX, $a['p']['nombre']] <=> [$b['valor'], $b['mejor'] ?? PHP_INT_MAX, $b['p']['nombre']]);
    $orden_estado = ['otl' => 0, 'dnf' => 1, 'dns' => 2, 'dsq' => 3];
    usort($no, fn($a, $b) => [$orden_estado[$a['estado']] ?? 3, $a['p']['nombre']] <=> [$orden_estado[$b['estado']] ?? 3, $b['p']['nombre']]);
    $lider = $ok ? $ok[0]['valor'] : null;
    foreach ($ok as $i => &$x) {
        $x['pos'] = $i + 1;
        $x['dif_lider'] = $lider !== null && !isset($x['texto']) && !isset($ok[0]['texto']) ? $x['valor'] - $lider : null;
        $x['dif_ant'] = $i > 0 ? $x['valor'] - $ok[$i - 1]['valor'] : null;
    }
    unset($x);
    foreach ($no as &$x) { $x['pos'] = null; $x['dif_lider'] = null; $x['dif_ant'] = null; }
    unset($x);
    return array_merge($ok, $no);
}

/** Clasificación de una fecha: absoluta y por categoría (con puntos). */
function carrera_clasificacion_fecha(array $t, array $f): array
{
    $cfg = carrera_cfg($t);
    $lista = carrera_calcular_fecha($t, $f);
    $abs = carrera_ordenar($lista);
    $cats = [];
    foreach (carrera_categorias((int)$t['id']) as $c) $cats[(int)$c['id']] = ['cat' => $c, 'filas' => []];
    $sin = [];
    foreach ($lista as $x) {
        $cid = (int)($x['p']['categoria_id'] ?? 0);
        if (isset($cats[$cid])) $cats[$cid]['filas'][] = $x; else $sin[] = $x;
    }
    if ($sin) $cats[0] = ['cat' => ['id' => 0, 'nombre' => 'Sin categoría', 'cc_min' => 0, 'cc_max' => 0, 'aspiracion' => '', 'nota' => ''], 'filas' => $sin];
    foreach ($cats as $k => $c) {
        $cats[$k]['filas'] = carrera_ordenar($c['filas']);
        foreach ($cats[$k]['filas'] as $i => $x) $cats[$k]['filas'][$i]['puntos'] = $x['pos'] !== null ? ($cfg['puntos'][$x['pos'] - 1] ?? 0) : 0;
    }
    $cats = array_filter($cats, fn($c) => $c['filas']);
    return ['abs' => $abs, 'cats' => $cats];
}

/* ============================================================
 * CLASIFICACIÓN GENERAL DEL CAMPEONATO
 * ============================================================ */

/**
 * Clasificación general por categoría.
 *  'puntos': suma de puntos por fecha, descartando las N peores (empate: más primeros puestos, luego segundos...).
 *  'tiempo': más fechas terminadas primero; luego menor tiempo total.
 * Devuelve ['cfg', 'fechas' (con resultados), 'cats' => [id => ['cat', 'filas']]]
 */
function carrera_general(array $t): array
{
    $cfg = carrera_cfg($t);
    $fechas = [];
    $por_cat = []; // cat_id => participante_id => datos
    $cat_info = [];
    foreach (carrera_categorias((int)$t['id']) as $c) $cat_info[(int)$c['id']] = $c;
    foreach (carrera_fechas((int)$t['id']) as $f) {
        $cl = carrera_clasificacion_fecha($t, $f);
        if (!$cl['abs']) continue;
        $fechas[] = $f;
        foreach ($cl['cats'] as $cid => $c) {
            $cat_info[$cid] = $c['cat'];
            foreach ($c['filas'] as $x) {
                $pid = (int)$x['p']['id'];
                $d = &$por_cat[$cid][$pid];
                $d ??= ['p' => $x['p'], 'por_fecha' => [], 'pos' => []];
                $d['por_fecha'][(int)$f['id']] = ['puntos' => $x['puntos'], 'pos' => $x['pos'], 'valor' => $x['valor'], 'estado' => $x['estado']];
                if ($x['pos'] !== null) $d['pos'][] = $x['pos'];
                unset($d);
            }
        }
    }
    $nf = count($fechas);
    $cats = [];
    foreach ($por_cat as $cid => $parts) {
        $filas = [];
        foreach ($parts as $d) {
            $pts = [];
            $ok = 0;
            $total_ms = 0;
            foreach ($fechas as $f) {
                $r = $d['por_fecha'][(int)$f['id']] ?? null;
                $pts[] = $r['puntos'] ?? 0;
                if ($r && $r['valor'] !== null) { $ok++; $total_ms += $r['valor']; }
            }
            $descartadas = 0;
            $suma = array_sum($pts);
            if ($cfg['descarte'] > 0 && $nf > $cfg['descarte']) {
                $ord = $pts;
                sort($ord);
                $descartadas = $cfg['descarte'];
                $suma -= array_sum(array_slice($ord, 0, $descartadas));
            }
            $cuentas = array_count_values($d['pos']);
            $filas[] = ['p' => $d['p'], 'por_fecha' => $d['por_fecha'], 'puntos' => $suma, 'bruto' => array_sum($pts), 'descarte' => $descartadas,
                'fechas_ok' => $ok, 'total_ms' => $ok ? $total_ms : null, 'cuentas' => $cuentas];
        }
        usort($filas, function ($a, $b) use ($cfg) {
            if ($cfg['clasif'] === 'tiempo') return [$b['fechas_ok'], $a['total_ms'] ?? PHP_INT_MAX, $a['p']['nombre']] <=> [$a['fechas_ok'], $b['total_ms'] ?? PHP_INT_MAX, $b['p']['nombre']] ?: 0;
            if ($a['puntos'] !== $b['puntos']) return $b['puntos'] <=> $a['puntos'];
            for ($i = 1; $i <= 10; $i++) {
                $ca = $a['cuentas'][$i] ?? 0;
                $cb = $b['cuentas'][$i] ?? 0;
                if ($ca !== $cb) return $cb <=> $ca;
            }
            return strcmp($a['p']['nombre'], $b['p']['nombre']);
        });
        $lider_ms = $filas && $cfg['clasif'] === 'tiempo' ? $filas[0]['total_ms'] : null;
        foreach ($filas as $i => &$x) {
            $x['pos'] = $i + 1;
            $x['dif_ms'] = ($lider_ms !== null && $x['total_ms'] !== null && $x['fechas_ok'] === $filas[0]['fechas_ok']) ? $x['total_ms'] - $lider_ms : null;
        }
        unset($x);
        $cats[$cid] = ['cat' => $cat_info[$cid], 'filas' => $filas];
    }
    // en el orden de las categorías
    $orden = array_keys($cat_info);
    uksort($cats, fn($a, $b) => array_search($a, $orden, true) <=> array_search($b, $orden, true));
    return ['cfg' => $cfg, 'fechas' => $fechas, 'cats' => $cats];
}

/* ============================================================
 * HTML COMPARTIDO (panel y página pública)
 * ============================================================ */

function carrera_nombre_fecha(array $f, array $T): string
{
    return $f['nombre'] !== '' ? $f['nombre'] : $T['fecha'] . ' ' . (int)$f['num'];
}

function carrera_celda_participante(array $p, bool $con_veh = true): string
{
    $h = ($p['numero'] !== '' ? '<b>#' . e($p['numero']) . '</b> ' : '') . e($p['nombre']);
    $sub = array_filter([$p['club'], $con_veh ? trim($p['vehiculo'] . ((int)$p['cc'] > 0 ? ' ' . number_format((int)$p['cc'], 0, ',', '.') . ' cc' : '')) : '']);
    return $h . ($sub ? '<br><small class="suave">' . e(implode(' · ', $sub)) . '</small>' : '');
}

/** (v23) Detalle de lo que se sumó a una manga: conos, penalizaciones con nombre y ± segundos. */
function carrera_detalle_manga(?array $g): string
{
    if (!$g || $g['estado'] !== 'ok') return '';
    $d = [];
    if ($g['conos'] > 0) $d[] = $g['conos'] . ($g['conos'] === 1 ? ' cono' : ' conos');
    if (($g['pens_txt'] ?? '') !== '') $d[] = $g['pens_txt'];
    if ($g['ajuste_seg'] != 0) $d[] = ($g['ajuste_seg'] > 0 ? '+' : '') . rtrim(rtrim(number_format($g['ajuste_seg'], 3, '.', ''), '0'), '.') . ' s';
    return implode(' · ', $d);
}

/** Tabla de la clasificación de una fecha (una categoría o la absoluta). */
function carrera_html_tabla_fecha(array $filas, array $t, array $f, bool $con_puntos = true): string
{
    $cfg = carrera_cfg($t);
    $T = textos_carrera($t);
    $nm = carrera_mangas_fecha($f, $cfg);
    $fm = mtb_formato($t, $f);
    $sumar = $fm !== '' ? $fm !== 'mejor' : $cfg['modo'] === 'suma';
    ob_start(); ?>
    <div class="tabla-scroll"><table class="tabla-carrera">
      <tr><th>#</th><th><?= e($T['participante']) ?></th>
        <?php if ($nm > 1): for ($m = 1; $m <= $nm; $m++): ?><th class="num col-manga"><?= e($T['manga']) ?> <?= $m ?></th><?php endfor; endif; ?>
        <th class="num"><?= $sumar ? 'Total' : ($nm > 1 ? 'Mejor' : 'Tiempo') ?></th><th class="num">Dif.</th><?php if ($con_puntos): ?><th class="num">Pts</th><?php endif; ?></tr>
      <?php foreach ($filas as $x): ?>
        <tr>
          <td><?= $x['pos'] ?? '—' ?></td>
          <td><?= carrera_celda_participante($x['p']) ?><?php if ($nm > 1): // (v23) en el celular las mangas van debajo del nombre
              $res_m = [];
              for ($m = 1; $m <= $nm; $m++) { $g = $x['mangas'][$m] ?? null; if (!$g) continue; $d = carrera_detalle_manga($g);
                  $res_m[] = mb_substr($T['manga'], 0, 1) . $m . ' ' . ($g['estado'] !== 'ok' ? strtoupper($g['estado']) : carrera_fmt_tiempo($g['final'])) . ($d !== '' ? ' (' . $d . ')' : ''); } ?>
              <?php if ($res_m): ?><br><small class="suave solo-movil"><?= e(implode(' · ', $res_m)) ?></small><?php endif; endif; ?></td>
          <?php if ($nm > 1): for ($m = 1; $m <= $nm; $m++): $g = $x['mangas'][$m] ?? null; ?>
            <td class="num col-manga"><?php if (!$g): ?>—<?php elseif ($g['estado'] !== 'ok'): ?><span class="etiqueta roja" title="<?= e($g['motivo']) ?>"><?= e(strtoupper($g['estado'])) ?></span><?php if ($g['motivo'] !== ''): ?><br><small class="suave"><?= e($g['motivo']) ?></small><?php endif; ?>
              <?php else: $det = carrera_detalle_manga($g); ?><?= e(carrera_fmt_tiempo($g['final'])) ?><?php if ($det !== ''): ?><br><small class="suave"><?= e($det) ?></small><?php endif; endif; ?></td>
          <?php endfor; endif; ?>
          <td class="num"><?php if ($x['valor'] !== null): ?><b><?= e(carrera_fmt_tiempo($x['valor'])) ?></b><?php if (isset($x['texto'])): ?><br><small class="suave"><?= e($x['texto']) ?></small><?php endif; ?><?php if ($nm === 1 && ($det1 = carrera_detalle_manga($x['mangas'][1] ?? null)) !== ''): ?><br><small class="suave"><?= e($det1) ?></small><?php endif; ?><?php else: ?><span class="etiqueta roja" title="<?= e($x['motivo'] ?? '') ?>"><?= e(strtoupper($x['estado'])) ?></span><?php if (($x['motivo'] ?? '') !== ''): ?><br><small class="suave"><?= e($x['motivo']) ?></small><?php endif; ?><?php endif; ?></td>
          <td class="num suave"><?= e(carrera_fmt_dif($x['dif_lider'] ?? null)) ?></td>
          <?php if ($con_puntos): ?><td class="num pts"><?= (int)($x['puntos'] ?? 0) ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php return carrera_css_movil() . (string)ob_get_clean();
}

/** (v23) Estilos para ver las tablas de tiempos en el celular (se imprimen una sola vez por página). */
function carrera_css_movil(): string
{
    static $ya = false;
    if ($ya) return '';
    $ya = true;
    return '<style>.solo-movil{display:none}@media (max-width:640px){.col-manga{display:none}.solo-movil{display:inline}.tabla-carrera td,.tabla-carrera th{padding-left:6px;padding-right:6px}.tabla-carrera td:nth-child(2){white-space:normal;min-width:150px}.tabla-carrera td:nth-child(2) small{white-space:normal}}</style>';
}

/** Tabla de la clasificación general de una categoría. */
function carrera_html_tabla_general(array $filas, array $fechas, array $cfg, array $T): string
{
    $por_tiempo = $cfg['clasif'] === 'tiempo';
    ob_start(); ?>
    <div class="tabla-scroll"><table class="tabla-carrera">
      <tr><th>#</th><th><?= e($T['participante']) ?></th>
        <?php foreach ($fechas as $f): ?><th class="num<?= count($fechas) > 3 ? ' col-manga' : '' ?>" title="<?= e(carrera_nombre_fecha($f, $T)) ?>"><?= e($T['fecha'][0]) ?><?= (int)$f['num'] ?></th><?php endforeach; ?>
        <?php if ($por_tiempo): ?><th class="num"><?= e($T['fechas']) ?></th><th class="num">Tiempo total</th><th class="num">Dif.</th><?php else: ?><th class="num">Pts</th><?php endif; ?></tr>
      <?php foreach ($filas as $x): ?>
        <tr>
          <td><?= (int)$x['pos'] ?></td>
          <td><?= carrera_celda_participante($x['p']) ?><?php if (count($fechas) > 3): // (v23) en el celular, el detalle por fecha va debajo del nombre
              $res_f = [];
              foreach ($fechas as $f) { $r = $x['por_fecha'][(int)$f['id']] ?? null; if (!$r) continue;
                  $res_f[] = $T['fecha'][0] . (int)$f['num'] . ' ' . ($por_tiempo ? ($r['valor'] !== null ? carrera_fmt_tiempo($r['valor']) : strtoupper($r['estado'])) : (int)$r['puntos'] . ($r['pos'] ? ' (' . (int)$r['pos'] . '°)' : '')); } ?>
              <?php if ($res_f): ?><br><small class="suave solo-movil"><?= e(implode(' · ', $res_f)) ?></small><?php endif; endif; ?></td>
          <?php foreach ($fechas as $f): $r = $x['por_fecha'][(int)$f['id']] ?? null; ?>
            <td class="num<?= count($fechas) > 3 ? ' col-manga' : '' ?>"><?php if (!$r): ?>—<?php elseif ($por_tiempo): ?><?= $r['valor'] !== null ? e(carrera_fmt_tiempo($r['valor'])) : e(strtoupper($r['estado'])) ?>
              <?php else: ?><?= (int)$r['puntos'] ?><?= $r['pos'] ? '<small class="suave"> (' . (int)$r['pos'] . '°)</small>' : '<small class="suave"> ' . e(strtoupper($r['estado'])) . '</small>' ?><?php endif; ?></td>
          <?php endforeach; ?>
          <?php if ($por_tiempo): ?><td class="num"><?= (int)$x['fechas_ok'] ?></td><td class="num"><b><?= e(carrera_fmt_tiempo($x['total_ms'])) ?></b></td><td class="num suave"><?= e(carrera_fmt_dif($x['dif_ms'])) ?></td>
          <?php else: ?><td class="num pts"><?= (int)$x['puntos'] ?><?= $x['descarte'] > 0 ? '<sup title="Ya sin las ' . (int)$x['descarte'] . ' peor(es) fecha(s): total bruto ' . (int)$x['bruto'] . '">*</sup>' : '' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php return carrera_css_movil() . (string)ob_get_clean();
}

/* ============================================================
 * EJEMPLO (para probar o mostrar el sistema)
 * ============================================================ */

/** Carga categorías, participantes y una fecha con tiempos de ejemplo en un campeonato vacío. */
function carrera_cargar_ejemplo(array $t): void
{
    if ($t['modalidad'] === 'CICLO') { ciclo_cargar_ejemplo($t); return; } // (v34) vuelta por etapas con reglas UCI
    if ($t['modalidad'] === 'MTB') { mtb_cargar_ejemplo($t); return; }
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM carrera_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene participantes. El ejemplo solo se carga en uno vacío.');
    carrera_cargar_categorias_base($t);
    $auto = $t['modalidad'] !== 'CICLO';
    $gente = $auto
        ? [['7', 'Carlos Andrade', 'Quito Racing', 'Suzuki Swift', 1300, ''], ['12', 'Diego Proaño', 'Club Norte', 'Chevrolet Aveo', 1600, ''], ['21', 'Mateo Salazar', 'Quito Racing', 'Renault Clio', 1600, ''],
            ['33', 'Andrés Villacís', 'Los Chillos', 'Mazda 3', 2000, ''], ['44', 'Pablo Cevallos', 'Club Norte', 'Honda Civic', 1800, ''], ['55', 'Jorge Endara', 'Los Chillos', 'Subaru WRX', 2000, 'turbo'],
            ['77', 'Luis Tapia', 'Quito Racing', 'Mitsubishi Lancer Evo', 2000, 'turbo'], ['88', 'Iván Jaramillo', 'Club Norte', 'Ford Mustang', 4600, '']]
        : [['1', 'Santiago Guamán', 'Club Ciclista Pichincha', 'Ruta', 0, ''], ['2', 'Marcelo Chiliquinga', 'Los Andes', 'Ruta', 0, ''], ['3', 'Fernando Toapanta', 'Club Ciclista Pichincha', 'Ruta', 0, ''],
            ['4', 'Bryan Quishpe', 'Sierra Norte', 'Ruta', 0, ''], ['5', 'Kevin Tipán', 'Los Andes', 'Ruta', 0, ''], ['6', 'Wilson Llumiquinga', 'Sierra Norte', 'Ruta', 0, '']];
    $ids = [];
    foreach ($gente as $i => [$num, $nom, $club, $veh, $cc, $asp]) {
        $cat = $auto ? carrera_categoria_auto($tid, $cc, $asp) : (int)valor('SELECT id FROM carrera_categorias WHERE torneo_id = ? AND nombre = ?', [$tid, $i < 3 ? 'Élite' : 'Sub-23']);
        q('INSERT INTO carrera_participantes (torneo_id, categoria_id, numero, nombre, club, vehiculo, cc, aspiracion) VALUES (?,?,?,?,?,?,?,?)', [$tid, $cat ?: null, $num, $nom, $club, $veh, $cc, $asp]);
        $ids[] = (int)db()->lastInsertId();
    }
    // (v23) tres fechas con conos, penalizaciones con nombre, un DNF y un DSQ con motivo
    carrera_cargar_penalidades_base($t);
    $pen = array_column(filas('SELECT id, nombre FROM carrera_penalidades WHERE torneo_id = ?', [$tid]), 'id', 'nombre');
    $lugares = $auto ? [['Fecha 1 · Parqueadero del estadio', 'Quito'], ['Fecha 2 · Autódromo de Yahuarcocha (paddock)', 'Ibarra'], ['Fecha 3 · Circuito de Los Chillos', 'Sangolquí']]
        : [['Carrera 1 · Circuito del valle', 'Cayambe'], ['Carrera 2 · Subida al Cotopaxi', 'Latacunga'], ['Carrera 3 · Clásica de Quito', 'Quito']];
    mt_srand(2209);
    foreach ($lugares as $n => [$nom_f, $lugar]) {
        q('INSERT INTO carrera_fechas (torneo_id, num, nombre, fecha, lugar) VALUES (?,?,?,?,?)', [$tid, $n + 1, $nom_f, date('Y-m-d', strtotime('-' . (14 * (2 - $n)) . ' days')), $lugar]);
        $fid = (int)db()->lastInsertId();
        foreach ($ids as $k => $pid) {
            if ($auto) {
                for ($m = 1; $m <= 2; $m++) {
                    $base = 58000 + $k * 700 + mt_rand(0, 3200) - $m * 400;
                    $estado = 'ok'; $pens = []; $motivo = '';
                    if ($n === 0 && $k === 3 && $m === 2) { $estado = 'dnf'; $motivo = 'Falla mecánica (embrague)'; }
                    if ($n === 1 && $k === 2 && $m === 1 && isset($pen['Salida en falso'])) $pens[$pen['Salida en falso']] = 1;
                    if ($n === 2 && $k === 5 && $m === 2 && isset($pen['Puerta no pasada'])) $pens[$pen['Puerta no pasada']] = 1;
                    if ($n === 2 && $k === 7 && $m === 1) { $estado = 'dsq'; $motivo = 'Peso bajo el mínimo en la balanza'; }
                    if ($n === 2 && $k === 7 && $m === 2) { $estado = 'dsq'; $motivo = 'Peso bajo el mínimo en la balanza'; }
                    q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, conos, ajuste_seg, estado, pens, motivo) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$fid, $pid, $m, $estado === 'ok' ? $base : null, $estado === 'ok' ? (mt_rand(0, 9) < 7 ? 0 : mt_rand(1, 2)) : 0, 0, $estado, $pens ? json_encode(array_map('intval', $pens)) : '', $motivo]);
                }
            } else {
                $estado = ($n === 1 && $k === 4) ? 'dnf' : 'ok';
                $pens = [];
                if ($k === ($n + 1) % 6 && isset($pen['Bonificación: meta volante'])) $pens[$pen['Bonificación: meta volante']] = 2;
                if ($n === 2 && $k === 0 && isset($pen['Bonificación: llegada (1.°)'])) $pens[$pen['Bonificación: llegada (1.°)']] = 1;
                q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, conos, ajuste_seg, estado, pens, motivo) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$fid, $pid, 1, $estado === 'ok' ? 5400000 + $n * 600000 + $k * 7000 + mt_rand(0, 9000) : null, 0, 0, $estado, $pens ? json_encode(array_map('intval', $pens)) : '', $estado === 'dnf' ? 'Caída en el km 42' : '']);
            }
        }
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}
