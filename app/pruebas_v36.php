<?php
/*
 * (v36) ATLETISMO AMPLIADO, CARRERAS DE RUTA / TRAIL / CROSS, LEVANTAMIENTO DE PESAS y TRIATLÓN (sobre el motor de pruebas: pruebas.php)
 *
 * Formatos de cada prueba (prueba_eventos.formato) que agrega este archivo:
 *   series_final → carreras de velocidad y vallas: series (hasta 8 por serie) y final con los Q (primeros de cada serie) y q (mejores tiempos).
 *                  Con una sola serie, esa serie es la final. Viento por serie (100, 200, vallas cortas).
 *   intentos     → saltos horizontales y lanzamientos (World Athletics): 3 intentos y 3 más para los 8 mejores. Cada intento: la marca,
 *                  X (nulo) o – (pasa). Gana la mejor marca; si empatan, la segunda mejor, y así. Viento por intento en largo y triple.
 *   alturas      → salto alto y garrocha: por cada altura hasta 3 intentos (O = pasó, X = falló, – = no saltó). Gana la mayor altura;
 *                  si empatan, menos intentos en esa altura y luego menos fallos en todo el concurso.
 *   combinadas   → heptatlón y decatlón con la tabla de puntos de World Athletics (A·(B−T)^C en carreras, A·(M−B)^C en saltos y lanzamientos).
 *   ruta         → carreras de ruta, trail y cross: tiempo oficial (pistola) y neto (chip), clasificación general, por rama y por
 *                  grupo de edad, pegar los tiempos del cronometraje (dorsal; tiempo) y clasificación por equipos del cross (suman los 4 primeros).
 *   pesas        → levantamiento de pesas (IWF): peso corporal, 3 intentos de arranque y 3 de envión (85 = válido, x85 = nulo).
 *                  Total = mejor arranque + mejor envión; si empatan, el de menor peso corporal.
 *   segmentos    → triatlón, duatlón y acuatlón: tiempo de cada segmento (natación, T1, ciclismo, T2, carrera) y total; grupos de edad.
 * Datos: prueba_eventos.config (JSON), prueba_resultados.datos (JSON) y prueba_series (series y final del formato series_final).
 * Además, en «marca» (carreras directas) se puede anotar el viento de cada uno.
 */

function pv_mods(): array
{
    return ['ATLET', 'RUTA', 'PESAS', 'TRIAT'];
}

function es_pruebas_v36($t): bool
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return in_array($m, pv_mods(), true);
}

/** Formatos de este archivo. */
function pv_formatos_todos(): array
{
    return ['series_final', 'intentos', 'alturas', 'combinadas', 'ruta', 'pesas', 'segmentos'];
}

function pv_es_formato(array $ev): bool
{
    return in_array((string)($ev['formato'] ?? ''), pv_formatos_todos(), true);
}

function pv_formatos(string $mod): array
{
    return match ($mod) {
        'ATLET' => ['marca' => 'Por marca (final directa)', 'series_final' => 'Series y final (velocidad y vallas)', 'intentos' => 'Concurso con intentos (largo, triple, lanzamientos)',
            'alturas' => 'Concurso por alturas (salto alto, garrocha)', 'combinadas' => 'Pruebas combinadas (heptatlón, decatlón)'],
        'RUTA' => ['ruta' => 'Carrera con clasificación general, por rama y por edad'],
        'PESAS' => ['pesas' => 'Arranque y envión (3 intentos cada uno)'],
        'TRIAT' => ['segmentos' => 'Por segmentos (natación, transiciones, ciclismo, carrera)'],
        default => ['marca' => 'Por marca'],
    };
}

/** Pruebas típicas: [nombre, medida, formato]. */
function pv_catalogo(string $m): array
{
    return match ($m) {
        'ATLET' => [
            ['60 m planos', 'tiempo', 'series_final'], ['80 m planos', 'tiempo', 'series_final'], ['100 m planos', 'tiempo', 'series_final'], ['150 m planos', 'tiempo', 'series_final'],
            ['200 m planos', 'tiempo', 'series_final'], ['300 m planos', 'tiempo', 'marca'], ['400 m planos', 'tiempo', 'series_final'], ['600 m', 'tiempo', 'marca'], ['800 m', 'tiempo', 'marca'],
            ['1.000 m', 'tiempo', 'marca'], ['1.500 m', 'tiempo', 'marca'], ['2.000 m', 'tiempo', 'marca'], ['3.000 m', 'tiempo', 'marca'], ['5.000 m', 'tiempo', 'marca'], ['10.000 m', 'tiempo', 'marca'],
            ['60 m vallas', 'tiempo', 'series_final'], ['80 m vallas', 'tiempo', 'series_final'], ['100 m vallas (damas)', 'tiempo', 'series_final'], ['110 m vallas (varones)', 'tiempo', 'series_final'],
            ['300 m vallas', 'tiempo', 'marca'], ['400 m vallas', 'tiempo', 'series_final'], ['2.000 m con obstáculos', 'tiempo', 'marca'], ['3.000 m con obstáculos', 'tiempo', 'marca'],
            ['Marcha 3.000 m', 'tiempo', 'marca'], ['Marcha 5.000 m', 'tiempo', 'marca'], ['Marcha 10.000 m', 'tiempo', 'marca'], ['Marcha 20 km', 'tiempo', 'marca'],
            ['Posta 4 × 100 m', 'tiempo', 'marca'], ['Posta 4 × 400 m', 'tiempo', 'marca'], ['Posta 4 × 400 m mixta', 'tiempo', 'marca'], ['Relevo sueco (100-200-300-400)', 'tiempo', 'marca'],
            ['Salto largo', 'metros', 'intentos'], ['Salto triple', 'metros', 'intentos'], ['Salto alto', 'metros', 'alturas'], ['Salto con garrocha', 'metros', 'alturas'],
            ['Lanzamiento de bala', 'metros', 'intentos'], ['Lanzamiento de disco', 'metros', 'intentos'], ['Lanzamiento de jabalina', 'metros', 'intentos'],
            ['Lanzamiento de martillo', 'metros', 'intentos'], ['Lanzamiento de pelota', 'metros', 'intentos'],
            ['Heptatlón (damas)', 'metros', 'combinadas'], ['Decatlón (varones)', 'metros', 'combinadas'],
        ],
        'RUTA' => [['5K', 'tiempo', 'ruta'], ['10K', 'tiempo', 'ruta'], ['15K', 'tiempo', 'ruta'], ['21K (media maratón)', 'tiempo', 'ruta'], ['42K (maratón)', 'tiempo', 'ruta'],
            ['Carrera infantil', 'tiempo', 'ruta'], ['Trail corto', 'tiempo', 'ruta'], ['Trail largo', 'tiempo', 'ruta'], ['Cross country', 'tiempo', 'ruta'], ['Caminata', 'tiempo', 'ruta']],
        // IWF: categorías de peso corporal (la rama va en el nombre)
        'PESAS' => array_merge(
            array_map(fn($k) => ['Damas ' . $k . ' kg', 'metros', 'pesas'], ['48', '53', '58', '63', '69', '77', '86', '+86']),
            array_map(fn($k) => ['Varones ' . $k . ' kg', 'metros', 'pesas'], ['60', '65', '71', '79', '88', '94', '110', '+110'])),
        'TRIAT' => [['Triatlón súper sprint (400 m · 10 km · 2,5 km)', 'tiempo', 'segmentos'], ['Triatlón sprint (750 m · 20 km · 5 km)', 'tiempo', 'segmentos'],
            ['Triatlón olímpico (1,5 km · 40 km · 10 km)', 'tiempo', 'segmentos'], ['Duatlón sprint (5 km · 20 km · 2,5 km)', 'tiempo', 'segmentos'],
            ['Acuatlón (2,5 km · 1 km · 2,5 km)', 'tiempo', 'segmentos'], ['Triatlón infantil', 'tiempo', 'segmentos']],
        default => [],
    };
}

/** Categorías de edad: [nombre, edad_min, edad_max]. */
function pv_categorias_base(string $m): array
{
    return match ($m) {
        // World Athletics: Sub 18 = 16 y 17, Sub 20 = 18 y 19
        'ATLET' => [['Sub 12 (hasta 11)', 0, 11], ['Sub 14 (12 y 13)', 12, 13], ['Sub 16 (14 y 15)', 14, 15], ['Sub 18 (16 y 17)', 16, 17], ['Sub 20 (18 y 19)', 18, 19], ['Mayores (20+)', 20, 0], ['Máster (35+)', 35, 0]],
        'RUTA' => [['General (todas las edades)', 0, 0], ['Menores (hasta 15)', 0, 15]],
        'PESAS' => [['Infantil (hasta 14)', 0, 14], ['Juvenil (13 a 17)', 13, 17], ['Júnior (15 a 20)', 15, 20], ['Sénior (15+)', 15, 0], ['Máster (35+)', 35, 0]],
        'TRIAT' => [['General (todas las edades)', 0, 0], ['Infantil (hasta 13)', 0, 13], ['Juvenil (14 a 17)', 14, 17]],
        default => [],
    };
}

/** Pesas: la rama va en el nombre de la prueba («Damas 58 kg»). */
function pv_rama_fija(string $mod, string $nombre): ?string
{
    if ($mod !== 'PESAS') return null;
    return str_starts_with($nombre, 'Damas') ? 'F' : (str_starts_with($nombre, 'Varones') ? 'M' : null);
}

function pv_json(?string $s): array
{
    $d = json_decode((string)$s, true);
    return is_array($d) ? $d : [];
}

/** ¿La prueba lleva viento (100, 200, vallas cortas, largo y triple)? */
function pv_con_viento_nombre(string $n): bool
{
    $n = mb_strtolower($n);
    if (str_contains($n, 'marcha') || str_contains($n, 'posta') || str_contains($n, 'relevo')) return false;
    return (bool)preg_match('/^(100|200|150|80) m|100 m vallas|110 m vallas|80 m vallas|salto largo|salto triple/u', $n);
}

/** Reglas de la prueba (con los valores de World Athletics por omisión). */
function pv_cfg(array $ev): array
{
    $d = pv_json($ev['config'] ?? '');
    $f = (string)($ev['formato'] ?? 'marca');
    $n = (string)($ev['nombre'] ?? '');
    $nl = mb_strtolower($n);
    $base = match ($f) {
        'series_final' => ['por_serie' => 8, 'pasan' => 2, 'pasan_tiempo' => 2, 'viento' => pv_con_viento_nombre($n) ? 1 : 0],
        'intentos' => ['intentos' => 3, 'extra' => 3, 'finalistas' => 8, 'viento' => pv_con_viento_nombre($n) ? 1 : 0],
        'alturas' => ['alturas' => str_contains($nl, 'garrocha') ? '2,00 2,20 2,40 2,60 2,80 3,00 3,20 3,40 3,60 3,80 4,00' : '1,10 1,15 1,20 1,25 1,30 1,35 1,40 1,45 1,50 1,55 1,60 1,65 1,70 1,75 1,80'],
        'combinadas' => ['tipo' => str_contains($nl, 'decat') ? 'decatlon' : 'heptatlon'],
        'ruta' => ['neto' => 1, 'cats' => str_contains($nl, 'infantil') ? '6-7,8-9,10-11,12-13,14-15' : '16-19,20-29,30-39,40-49,50-59,60', 'equipos' => str_contains($nl, 'cross') ? 4 : 0, 'premiados' => 3],
        'segmentos' => ['segmentos' => str_contains($nl, 'duatl') ? 'Carrera 1,T1,Ciclismo,T2,Carrera 2' : (str_contains($nl, 'acuatl') ? 'Carrera 1,T1,Natación,T2,Carrera 2' : 'Natación,T1,Ciclismo,T2,Carrera'),
            'cats' => str_contains($nl, 'infantil') ? '6-7,8-9,10-11,12-13' : '16-19,20-24,25-29,30-34,35-39,40-44,45-49,50-54,55-59,60'],
        'pesas' => ['intentos' => 3],
        default => ['viento' => pv_con_viento_nombre($n) && in_array((string)($ev['medida'] ?? ''), ['tiempo', 'metros'], true) ? 1 : 0],
    };
    foreach ($base as $k => $v) if (isset($d[$k]) && $d[$k] !== '') $base[$k] = is_int($v) ? (int)$d[$k] : (string)$d[$k];
    return $base;
}

/** Lee del formulario la configuración del formato. */
function pv_cfg_post(string $formato): string
{
    $c = match ($formato) {
        'series_final' => ['por_serie' => entero(post('cfg_por_serie', '8'), 2, 12), 'pasan' => entero(post('cfg_pasan', '2'), 0, 8), 'pasan_tiempo' => entero(post('cfg_pasan_tiempo', '2'), 0, 8), 'viento' => post('cfg_viento') === '1' ? 1 : 0],
        'intentos' => ['intentos' => entero(post('cfg_intentos', '3'), 1, 6), 'extra' => entero(post('cfg_extra', '3'), 0, 3), 'finalistas' => entero(post('cfg_finalistas', '8'), 0, 20), 'viento' => post('cfg_viento') === '1' ? 1 : 0],
        'alturas' => ['alturas' => implode(' ', array_map(fn($x) => number_format($x, 2, ',', ''), pv_alturas_leer(post('cfg_alturas'))))],
        'combinadas' => ['tipo' => post('cfg_tipo') === 'decatlon' ? 'decatlon' : 'heptatlon'],
        'ruta' => ['neto' => post('cfg_neto') === '0' ? 0 : 1, 'cats' => pv_cats_limpiar(post('cfg_cats')), 'equipos' => entero(post('cfg_equipos', '0'), 0, 10), 'premiados' => entero(post('cfg_premiados', '3'), 1, 10)],
        'segmentos' => ['segmentos' => implode(',', array_slice(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 20), explode(',', post('cfg_segmentos'))), fn($x) => $x !== ''), 0, 8)), 'cats' => pv_cats_limpiar(post('cfg_cats'))],
        'pesas' => [],
        default => ['viento' => post('cfg_viento') === '1' ? 1 : 0],
    };
    foreach ($c as $k => $v) if ($v === '') unset($c[$k]);
    return $c ? json_encode($c, JSON_UNESCAPED_UNICODE) : '';
}

/** "1,10 1,15 1.20" → [1.10, 1.15, 1.20] (ordenadas, sin repetir). */
function pv_alturas_leer(string $s): array
{
    $r = [];
    foreach (preg_split('/[\s;]+/', trim(str_replace(',', '.', $s)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $x) {
        if (!preg_match('/^\d(\.\d{1,2})?$/', $x)) throw new RuntimeException("«{$x}» no es una altura válida: escriba las alturas en metros separadas por espacios (ej: 1,20 1,25 1,30).");
        $r[] = round((float)$x, 2);
    }
    $r = array_values(array_unique($r));
    sort($r);
    return $r;
}

/** Grupos de edad "20-29, 30-39, 60" (60 = 60 o más). */
function pv_cats_limpiar(string $s): string
{
    $r = [];
    foreach (preg_split('/[\s,;]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $x) {
        if (!preg_match('/^(\d{1,2})(?:-(\d{1,2}))?$/', $x, $m)) throw new RuntimeException("«{$x}» no es un grupo de edad válido: escriba rangos como 20-29, separados por comas (el último puede ser 60 = 60 o más).");
        $r[] = isset($m[2]) ? (int)$m[1] . '-' . max((int)$m[1], (int)$m[2]) : (string)(int)$m[1];
    }
    return implode(',', $r);
}

/** Etiqueta del grupo de edad para una edad: "30 a 39", "60 o más", "Hasta 15". */
function pv_grupo_edad(string $cats, ?int $edad): string
{
    if ($edad === null) return 'Sin edad';
    $rangos = [];
    foreach (explode(',', $cats) as $x) {
        if (!preg_match('/^(\d+)(?:-(\d+))?$/', trim($x), $m)) continue;
        $rangos[] = [(int)$m[1], isset($m[2]) ? (int)$m[2] : 999];
    }
    if (!$rangos) return 'General';
    if ($edad < $rangos[0][0]) return 'Hasta ' . ($rangos[0][0] - 1);
    foreach ($rangos as [$a, $b]) if ($edad >= $a && $edad <= $b) return $b >= 999 ? $a . ' o más' : $a . ' a ' . $b;
    return 'Otras edades';
}

/** Tiempo de ruta: "1:23:45" o "45:12" (sin milésimas si no hay). */
function pv_fmt_tiempo(?int $ms, bool $centesimas = false): string
{
    if ($ms === null) return '';
    $h = intdiv($ms, 3600000);
    $m = intdiv($ms % 3600000, 60000);
    $s = intdiv($ms % 60000, 1000);
    $c = intdiv($ms % 1000, 10);
    $t = $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    return $centesimas || $c ? $t . sprintf('.%02d', $c) : $t;
}

function pv_fmt_m(?float $v): string
{
    return $v === null ? '' : number_format($v, 2, ',', '.');
}

/** "+1,2" */
function pv_fmt_viento($v): string
{
    if ($v === null || $v === '') return '';
    $f = (float)$v;
    return ($f > 0 ? '+' : ($f < 0 ? '−' : '')) . number_format(abs($f), 1, ',', '');
}

function pv_leer_viento(string $s): ?float
{
    $s = trim(str_replace(['−', ','], ['-', '.'], $s));
    if ($s === '') return null;
    if (!preg_match('/^[+-]?\d{1,2}(\.\d)?$/', $s)) throw new RuntimeException("«{$s}» no es un viento válido: escriba los metros por segundo como +1,2 o −0,5.");
    return round((float)$s, 1);
}

/** Texto del viento con aviso: marca con más de +2,0 m/s no vale para récord. */
function pv_viento_txt($v): string
{
    if ($v === null || $v === '') return '';
    return 'viento ' . pv_fmt_viento($v) . ((float)$v > 2.0 ? ' (no vale para récord)' : '');
}

/* ============================================================
 * PRUEBAS COMBINADAS (tabla de World Athletics)
 * ============================================================ */

/** Pruebas de cada combinada: clave => [nombre, tipo (t = carrera en segundos, s = salto en cm, l = lanzamiento en m), A, B, C]. */
function pv_combinada(string $tipo): array
{
    if ($tipo === 'decatlon') return [
        '100' => ['100 m', 't', 25.4347, 18, 1.81], 'largo' => ['Salto largo', 's', 0.14354, 220, 1.4], 'bala' => ['Bala', 'l', 51.39, 1.5, 1.05],
        'alto' => ['Salto alto', 's', 0.8465, 75, 1.42], '400' => ['400 m', 't', 1.53775, 82, 1.81], '110v' => ['110 m vallas', 't', 5.74352, 28.5, 1.92],
        'disco' => ['Disco', 'l', 12.91, 4, 1.1], 'garrocha' => ['Garrocha', 's', 0.2797, 100, 1.35], 'jabalina' => ['Jabalina', 'l', 10.14, 7, 1.08], '1500' => ['1.500 m', 't', 0.03768, 480, 1.85],
    ];
    return [
        '100v' => ['100 m vallas', 't', 9.23076, 26.7, 1.835], 'alto' => ['Salto alto', 's', 1.84523, 75, 1.348], 'bala' => ['Bala', 'l', 56.0211, 1.5, 1.05],
        '200' => ['200 m', 't', 4.99087, 42.5, 1.81], 'largo' => ['Salto largo', 's', 0.188807, 210, 1.41], 'jabalina' => ['Jabalina', 'l', 15.9803, 3.8, 1.04], '800' => ['800 m', 't', 0.11193, 254, 1.88],
    ];
}

/** Puntos de una marca (tiempo en ms o metros). */
function pv_pts_combinada(array $x, ?float $valor): int
{
    if ($valor === null) return 0;
    [, $tipo, $A, $B, $C] = $x;
    if ($tipo === 't') {
        $seg = $valor / 1000;
        return $seg < $B ? (int)floor($A * (($B - $seg) ** $C)) : 0;
    }
    $p = $tipo === 's' ? $valor * 100 : $valor;
    return $p > $B ? (int)floor($A * (($p - $B) ** $C)) : 0;
}

/** Lee la marca escrita de una prueba de la combinada: ms para carreras, metros para saltos y lanzamientos. */
function pv_leer_marca_comb(array $x, string $s): ?float
{
    $s = trim($s);
    if ($s === '') return null;
    if ($x[1] === 't') {
        $ms = carrera_parse_tiempo($s);
        if ($ms === null) throw new RuntimeException("{$x[0]}: «{$s}» no es un tiempo válido (ej: 11.45 o 2:15.30).");
        return (float)$ms;
    }
    return prueba_parse_marca('metros', $s);
}

/* ============================================================
 * RESULTADOS
 * ============================================================ */

/** Intento de un concurso: número (metros), 'X' (nulo), '-' (pasa) o '' (no hizo). */
function pv_leer_intento(string $s): string
{
    $s = strtoupper(trim(str_replace(['–', '—'], '-', $s)));
    if ($s === '' || $s === '-' || $s === 'X') return $s;
    $v = prueba_parse_marca('metros', $s);
    return number_format((float)$v, 2, '.', '');
}

/** Mejor marca y lista de marcas válidas ordenadas (para desempatar con la segunda mejor, etc.). */
function pv_mejores_intentos(array $int): array
{
    $v = [];
    foreach ($int as $x) if ($x !== '' && $x !== 'X' && $x !== '-') $v[] = (float)$x;
    rsort($v);
    return $v;
}

/** Alturas: [mejor altura, intentos en esa altura, fallos totales hasta esa altura, texto]. */
function pv_resumen_alturas(array $h): array
{
    ksort($h, SORT_NUMERIC);
    $mejor = null;
    $int_mejor = 0;
    $fallos = 0;
    $fallos_hasta = 0;
    $txt = [];
    foreach ($h as $alt => $s) {
        $s = (string)$s;
        if ($s === '') continue;
        $txt[] = number_format((float)$alt, 2, ',', '') . ' ' . $s;
        $x = substr_count($s, 'X');
        $fallos += $x;
        if (str_contains($s, 'O')) {
            $mejor = (float)$alt;
            $int_mejor = strpos($s, 'O') + 1 - substr_count(substr($s, 0, (int)strpos($s, 'O')), '-');
            $fallos_hasta = $fallos;
        }
    }
    return [$mejor, $int_mejor, $fallos_hasta, implode(' · ', $txt)];
}

/** Pesas: [mejor (kg o null), texto]. "85" = válido, "x85" / "85x" = nulo, "-" = no lo intentó. */
function pv_mejor_pesas(array $int): array
{
    $m = null;
    $t = [];
    foreach ($int as $x) {
        $x = strtolower(trim((string)$x));
        if ($x === '') continue;
        $nulo = str_contains($x, 'x');
        $kg = (int)preg_replace('/\D/', '', $x);
        $t[] = $x === '-' ? '–' : ($nulo ? '✗' . $kg : (string)$kg);
        if (!$nulo && $kg > 0) $m = max($m ?? 0, $kg);
    }
    return [$m, implode(' ', $t)];
}

function pv_leer_pesas(string $s): string
{
    $s = strtolower(trim(str_replace(['✗', '×'], 'x', $s)));
    if ($s === '' || $s === '-') return $s;
    if (!preg_match('/^x?\d{1,3}x?$/', $s)) throw new RuntimeException("«{$s}» no vale: escriba los kilos (ej: 85) y, si fue nulo, con una x (ej: x85).");
    return (str_contains($s, 'x') ? 'x' : '') . (int)preg_replace('/\D/', '', $s);
}

/** Pesos de la categoría de pesas sacados del nombre («Varones 71 kg» → hasta 71, «Damas +86 kg» → más de 86). */
function pv_limites_pesas(string $nombre): array
{
    if (preg_match('/\+(\d+)/', $nombre, $m)) return [(float)$m[1], null];
    if (preg_match('/(\d+)\s*kg/', $nombre, $m)) return [null, (float)$m[1]];
    return [null, null];
}

/** Ordena filas con su clave y les pone el puesto (las claves iguales comparten puesto). */
function pv_ordenar(array $filas, array $orden): array
{
    $con = [];
    foreach ($orden as $pid => $k) if (isset($filas[$pid])) $con[] = $filas[$pid] + ['_k' => $k];
    usort($con, fn($a, $b) => $a['_k'] <=> $b['_k']);
    $ant = null;
    foreach ($con as $i => &$r) {
        $r['puesto'] = ($ant !== null && $ant['_k'] == $r['_k']) ? $ant['puesto'] : $i + 1;
        $ant = $r;
    }
    unset($r);
    foreach ($con as &$r) unset($r['_k']);
    unset($r);
    $sin = array_values(array_filter($filas, fn($r) => !isset($orden[(int)$r['participante_id']])));
    $orden_est = ['ok' => 0, 'dq' => 1, 'dnf' => 2, 'dns' => 3];
    usort($sin, fn($a, $b) => [$orden_est[$a['estado']] ?? 9, $a['p']['nombre']] <=> [$orden_est[$b['estado']] ?? 9, $b['p']['nombre']]);
    return array_merge($con, $sin);
}

/** Igual que prueba_resultados() con los formatos de este archivo. */
function pv_resultados(array $ev, array $part): array
{
    $f = (string)$ev['formato'];
    $cfg = pv_cfg($ev);
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$ev['torneo_id']]) ?? [];
    $filas = [];
    foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) {
        if (!isset($part[(int)$r['participante_id']])) continue;
        $filas[(int)$r['participante_id']] = $r + ['p' => $part[(int)$r['participante_id']], 'puesto' => null, 'texto' => '', 'detalle' => ''];
    }
    $orden = [];
    switch ($f) {
        case 'intentos':
            foreach ($filas as $pid => &$r) {
                $d = pv_json($r['datos']);
                $int = array_map('strval', (array)($d['i'] ?? []));
                $vi = (array)($d['w'] ?? []);
                if (!array_filter($int, fn($x) => $x !== '')) continue;
                $m = pv_mejores_intentos($int);
                $txt = [];
                foreach ($int as $i => $x) if ($x !== '') $txt[] = ($x === 'X' ? 'X' : ($x === '-' ? '–' : pv_fmt_m((float)$x))) . (isset($vi[$i]) && $vi[$i] !== '' && $x !== 'X' && $x !== '-' ? ' (' . pv_fmt_viento($vi[$i]) . ')' : '');
                $r['detalle'] = implode(' · ', $txt);
                if ($r['estado'] !== 'ok') continue;
                if (!$m) { $r['texto'] = 'NM'; $r['detalle'] = 'Sin marca válida · ' . $r['detalle']; continue; }
                $r['marca'] = $m[0];
                $iv = array_search(number_format($m[0], 2, '.', ''), $int, true);
                $r['texto'] = pv_fmt_m($m[0]) . ' m' . ($iv !== false && isset($vi[$iv]) && $vi[$iv] !== '' ? ' (' . pv_fmt_viento($vi[$iv]) . ')' : '');
                $orden[$pid] = array_map(fn($x) => -$x, array_pad($m, 6, 0.0));
            }
            unset($r);
            break;
        case 'alturas':
            foreach ($filas as $pid => &$r) {
                $d = pv_json($r['datos']);
                if (empty($d['h'])) continue;
                [$mejor, $int, $fallos, $txt] = pv_resumen_alturas((array)$d['h']);
                $r['detalle'] = $txt;
                if ($r['estado'] !== 'ok') continue;
                if ($mejor === null) { $r['texto'] = 'NM'; continue; }
                $r['marca'] = $mejor;
                $r['texto'] = pv_fmt_m($mejor) . ' m';
                $orden[$pid] = [-$mejor, $int, $fallos];
            }
            unset($r);
            break;
        case 'combinadas':
            $pr = pv_combinada($cfg['tipo']);
            foreach ($filas as $pid => &$r) {
                $d = pv_json($r['datos']);
                $m = (array)($d['m'] ?? []);
                if (!array_filter($m, fn($x) => $x !== '' && $x !== null)) continue;
                $total = 0;
                $det = [];
                foreach ($pr as $k => $x) {
                    if (!isset($m[$k]) || $m[$k] === '' || $m[$k] === null) continue;
                    $pts = pv_pts_combinada($x, (float)$m[$k]);
                    $total += $pts;
                    $det[] = $x[0] . ' ' . ($x[1] === 't' ? prueba_fmt_marca('tiempo', $m[$k]) : pv_fmt_m((float)$m[$k])) . ' (' . $pts . ')';
                }
                $r['detalle'] = implode(' · ', $det);
                $r['texto'] = number_format($total, 0, ',', '.') . ' pts';
                $r['marca'] = $total;
                if ($r['estado'] === 'ok') $orden[$pid] = [-$total];
            }
            unset($r);
            break;
        case 'pesas':
            [$pmin, $pmax] = pv_limites_pesas((string)$ev['nombre']);
            foreach ($filas as $pid => &$r) {
                $d = pv_json($r['datos']);
                [$ma, $ta] = pv_mejor_pesas((array)($d['a'] ?? []));
                [$me, $te] = pv_mejor_pesas((array)($d['e'] ?? []));
                $pc = isset($d['pc']) && $d['pc'] !== '' ? (float)$d['pc'] : null;
                if ($ta === '' && $te === '' && $pc === null) continue;
                $fuera = $pc !== null && (($pmax !== null && $pc > $pmax) || ($pmin !== null && $pc <= $pmin));
                $r['detalle'] = trim(($pc !== null ? 'PC ' . number_format($pc, 2, ',', '') . ' kg' . ($fuera ? ' (fuera de la categoría)' : '') . ' · ' : '') . 'Arranque: ' . ($ta ?: '—') . ' · Envión: ' . ($te ?: '—'));
                if ($r['estado'] !== 'ok') continue;
                if ($ma === null || $me === null) { $r['texto'] = $ta !== '' && $te !== '' ? 'Sin total' : ''; continue; }
                $total = $ma + $me;
                $r['marca'] = $total;
                $r['texto'] = $total . ' kg (' . $ma . ' + ' . $me . ')';
                $orden[$pid] = [-$total, $pc ?? 999, -$me];
            }
            unset($r);
            break;
        case 'segmentos':
            $seg = array_values(array_filter(array_map('trim', explode(',', (string)$cfg['segmentos']))));
            foreach ($filas as $pid => &$r) {
                $d = pv_json($r['datos']);
                $s = (array)($d['s'] ?? []);
                if (!array_filter($s, fn($x) => $x !== null && $x !== '')) continue;
                $total = 0;
                $det = [];
                $completo = true;
                foreach ($seg as $i => $nom) {
                    if (!isset($s[$i]) || $s[$i] === '' || $s[$i] === null) { $completo = false; continue; }
                    $total += (int)$s[$i];
                    $det[] = $nom . ' ' . pv_fmt_tiempo((int)$s[$i]);
                }
                $edad = prueba_edad((string)$r['p']['nacimiento'], $t);
                $ge = (['M' => 'Varones ', 'F' => 'Damas '][$r['p']['rama']] ?? '') . pv_grupo_edad((string)$cfg['cats'], $edad);
                $r['grupo'] = $ge;
                $r['detalle'] = implode(' · ', $det);
                if ($r['estado'] !== 'ok' || !$completo) continue;
                $r['marca'] = $total;
                $r['texto'] = pv_fmt_tiempo($total);
                $orden[$pid] = [$total];
            }
            unset($r);
            break;
        case 'ruta':
            foreach ($filas as $pid => &$r) {
                $d = pv_json($r['datos']);
                $neto = isset($d['neto']) && $d['neto'] !== '' ? (int)$d['neto'] : null;
                $of = $r['marca'] !== null ? (int)$r['marca'] : null;
                $vale = (int)$cfg['neto'] && $neto !== null ? $neto : ($of ?? $neto);
                $edad = prueba_edad((string)$r['p']['nacimiento'], $t);
                $r['grupo'] = (['M' => 'Varones ', 'F' => 'Damas '][$r['p']['rama']] ?? '') . pv_grupo_edad((string)$cfg['cats'], $edad);
                $r['tiempo_ms'] = $vale;
                if ($vale === null) continue;
                $r['texto'] = pv_fmt_tiempo($vale);
                $r['detalle'] = trim(($of !== null && $neto !== null ? 'oficial ' . pv_fmt_tiempo($of) . ' · neto ' . pv_fmt_tiempo($neto) : '') . ($r['p']['numero'] !== '' ? ' · dorsal ' . $r['p']['numero'] : ''), ' ·');
                if ($r['estado'] === 'ok') $orden[$pid] = [$vale];
            }
            unset($r);
            break;
        case 'series_final':
            $se = pv_series($ev);
            foreach ($filas as $pid => &$r) {
                if (isset($se['info'][$pid])) { $r['texto'] = $se['info'][$pid][0]; $r['detalle'] = $se['info'][$pid][1]; }
            }
            unset($r);
            foreach ($se['orden'] as $pid => $k) if (isset($filas[$pid]) && $filas[$pid]['estado'] === 'ok') $orden[$pid] = $k;
            break;
    }
    $res = pv_ordenar($filas, $orden);
    // ruta y segmentos: puesto en su rama y en su grupo de edad
    if (in_array($f, ['ruta', 'segmentos'], true)) {
        $n_rama = $n_grupo = [];
        foreach ($res as &$r) {
            if ($r['puesto'] === null) continue;
            $ra = (string)$r['p']['rama'];
            $n_rama[$ra] = ($n_rama[$ra] ?? 0) + 1;
            $n_grupo[$r['grupo']] = ($n_grupo[$r['grupo']] ?? 0) + 1;
            $r['puesto_rama'] = $n_rama[$ra];
            $r['puesto_grupo'] = $n_grupo[$r['grupo']];
            $r['detalle'] = trim($r['grupo'] . ': ' . $n_grupo[$r['grupo']] . '.º · ' . $r['detalle'], ' ·');
        }
        unset($r);
    }
    return $res;
}

/* ============================================================
 * SERIES Y FINAL (velocidad y vallas)
 * ============================================================ */

/**
 * Estado de las series y la final: ['fases' => ['r1' => [serie => filas], 'f' => [1 => filas]], 'info' => [pid => [texto, detalle]],
 * 'orden' => [pid => clave], 'completa_r1' => bool, 'completa_f' => bool, 'una_serie' => bool, 'cfg']
 */
function pv_series(array $ev): array
{
    $cfg = pv_cfg($ev);
    $fases = [];
    foreach (filas('SELECT * FROM prueba_series WHERE evento_id = ? ORDER BY fase DESC, serie, carril, id', [$ev['id']]) as $x) $fases[$x['fase']][(int)$x['serie']][] = $x;
    $hecho = fn($x) => $x['estado'] !== 'ok' || $x['tiempo_ms'] !== null;
    $comp = function (string $fase) use ($fases, $hecho): bool {
        if (empty($fases[$fase])) return false;
        foreach ($fases[$fase] as $s) foreach ($s as $x) if (!$hecho($x)) return false;
        return true;
    };
    $r = ['fases' => $fases, 'info' => [], 'orden' => [], 'completa_r1' => $comp('r1'), 'completa_f' => $comp('f'), 'una_serie' => count($fases['r1'] ?? []) === 1, 'cfg' => $cfg];
    foreach ($fases['r1'] ?? [] as $s => $lista) foreach ($lista as $x) {
        $d = pv_json($x['datos']);
        $txt = $x['estado'] !== 'ok' ? strtoupper($x['estado']) : ($x['tiempo_ms'] !== null ? prueba_fmt_marca('tiempo', $x['tiempo_ms']) : '');
        $r['info'][(int)$x['participante_id']] = [$txt, 'Serie ' . $s . ($txt !== '' ? ': ' . $txt : '') . (($d['q'] ?? '') !== '' ? ' (' . $d['q'] . ')' : '') . (($d['v'] ?? '') !== '' ? ' · ' . pv_viento_txt($d['v']) : '')];
    }
    foreach ($fases['f'] ?? [] as $lista) foreach ($lista as $x) {
        $d = pv_json($x['datos']);
        $txt = $x['estado'] !== 'ok' ? strtoupper($x['estado']) : ($x['tiempo_ms'] !== null ? prueba_fmt_marca('tiempo', $x['tiempo_ms']) : '');
        $pid = (int)$x['participante_id'];
        $r['info'][$pid] = [$txt, 'Final' . (($d['v'] ?? '') !== '' ? ' · ' . pv_viento_txt($d['v']) : '') . ' · ' . ($r['info'][$pid][1] ?? '')];
    }
    // puestos: con una sola serie, cuando termina; con final, cuando termina la final (los demás, por su tiempo en las series)
    if ($r['una_serie'] && $r['completa_r1']) {
        foreach (reset($fases['r1']) as $x) if ($x['estado'] === 'ok' && $x['tiempo_ms'] !== null) $r['orden'][(int)$x['participante_id']] = [0, (int)$x['tiempo_ms']];
    } elseif ($r['completa_f']) {
        $en_final = [];
        foreach ($fases['f'][1] as $x) {
            $en_final[(int)$x['participante_id']] = true;
            if ($x['estado'] === 'ok' && $x['tiempo_ms'] !== null) $r['orden'][(int)$x['participante_id']] = [0, (int)$x['tiempo_ms']];
        }
        foreach ($fases['r1'] as $lista) foreach ($lista as $x) {
            $pid = (int)$x['participante_id'];
            if (isset($en_final[$pid]) || $x['estado'] !== 'ok' || $x['tiempo_ms'] === null) continue;
            $r['orden'][$pid] = [1, (int)$x['tiempo_ms']];
        }
    }
    return $r;
}

/** Arma las series en zigzag (por la marca de referencia, si la tiene) con hasta por_serie atletas. */
function pv_series_armar(array $ev): string
{
    if ((int)valor('SELECT COUNT(*) FROM prueba_series WHERE evento_id = ?', [$ev['id']])) throw new RuntimeException('Las series ya están armadas. Para rehacerlas, bórrelas primero.');
    $cfg = pv_cfg($ev);
    $ins = filas("SELECT * FROM prueba_resultados WHERE evento_id = ? AND estado <> 'dns' ORDER BY CASE WHEN marca IS NULL THEN 1 ELSE 0 END, marca, id", [$ev['id']]);
    if (count($ins) < 2) throw new RuntimeException('Inscriba al menos 2 atletas en esta prueba.');
    $sin = array_values(array_filter($ins, fn($x) => $x['marca'] === null));
    $con = array_values(array_filter($ins, fn($x) => $x['marca'] !== null));
    shuffle($sin);
    $lista = array_merge($con, $sin);
    $ns = (int)ceil(count($lista) / max(2, (int)$cfg['por_serie']));
    $series = array_fill(1, $ns, []);
    foreach ($lista as $i => $x) {
        $f = intdiv($i, $ns);
        $c = $i % $ns;
        $series[$f % 2 ? $ns - $c : $c + 1][] = (int)$x['participante_id'];
    }
    // carriles: los más rápidos al centro (3, 4, 5, 6) como en World Athletics
    $orden_carril = [4, 5, 3, 6, 2, 7, 1, 8, 9, 10, 11, 12];
    foreach ($series as $s => $pids) foreach ($pids as $i => $pid) {
        q('INSERT INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril) VALUES (?,?,?,1,?,?)', [$ev['id'], 'r1', $s, $pid, $orden_carril[$i] ?? $i + 1]);
    }
    return $ns === 1 ? 'Una sola serie: es la final.' : "Se armaron $ns series.";
}

/** Final: los Q (primeros «pasan» de cada serie) y los q (mejores tiempos que siguen). */
function pv_final_armar(array $ev): string
{
    $se = pv_series($ev);
    if ($se['una_serie']) throw new RuntimeException('Con una sola serie no hay final: esa serie es la final.');
    if (!$se['completa_r1']) throw new RuntimeException('Faltan tiempos de las series.');
    if (!empty($se['fases']['f'])) throw new RuntimeException('La final ya está armada.');
    $cfg = $se['cfg'];
    $Q = [];
    $resto = [];
    foreach ($se['fases']['r1'] as $s => $lista) {
        $ok = array_values(array_filter($lista, fn($x) => $x['estado'] === 'ok' && $x['tiempo_ms'] !== null));
        usort($ok, fn($a, $b) => (int)$a['tiempo_ms'] <=> (int)$b['tiempo_ms']);
        foreach ($ok as $i => $x) $i < (int)$cfg['pasan'] ? $Q[] = $x : $resto[] = $x;
    }
    usort($resto, fn($a, $b) => (int)$a['tiempo_ms'] <=> (int)$b['tiempo_ms']);
    $q = array_slice($resto, 0, max(0, min((int)$cfg['pasan_tiempo'], (int)$cfg['por_serie'] - count($Q))));
    foreach ($Q as $x) q('UPDATE prueba_series SET datos = ? WHERE id = ?', [json_encode(array_merge(pv_json($x['datos']), ['q' => 'Q'])), $x['id']]);
    foreach ($q as $x) q('UPDATE prueba_series SET datos = ? WHERE id = ?', [json_encode(array_merge(pv_json($x['datos']), ['q' => 'q'])), $x['id']]);
    $fin = array_merge($Q, $q);
    usort($fin, fn($a, $b) => (int)$a['tiempo_ms'] <=> (int)$b['tiempo_ms']);
    $orden_carril = [4, 5, 3, 6, 2, 7, 1, 8, 9, 10, 11, 12];
    foreach ($fin as $i => $x) q('INSERT INTO prueba_series (evento_id, fase, serie, manga, participante_id, carril) VALUES (?,?,1,1,?,?)', [$ev['id'], 'f', (int)$x['participante_id'], $orden_carril[$i] ?? $i + 1]);
    return 'Final armada con ' . count($fin) . ' atletas (' . count($Q) . ' por puesto y ' . count($q) . ' por tiempo).';
}

/* ============================================================
 * ACCIONES DEL PANEL
 * ============================================================ */

function pv_accion(array $t, array $ev, string $sub): string
{
    $f = (string)$ev['formato'];
    $cfg = pv_cfg($ev);
    $part = pruebas_participantes((int)$t['id']);
    $ins = array_map('intval', array_column(filas('SELECT participante_id FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]), 'participante_id'));
    $nom = fn(int $pid) => $part[$pid]['nombre'] ?? '';
    $est = function (int $pid): string {
        $e = (string)($_POST['est'][$pid] ?? 'ok');
        return isset(pruebas_estados()[$e]) ? $e : 'ok';
    };
    $guardar = function (int $pid, array $d, string $estado, ?float $marca = null) use ($ev) {
        $vacio = !array_filter($d, fn($v) => is_array($v) ? (bool)array_filter($v, fn($y) => $y !== '' && $y !== null) : ($v !== '' && $v !== null));
        q('UPDATE prueba_resultados SET datos = ?, estado = ?, marca = ?, nota = ? WHERE evento_id = ? AND participante_id = ?',
            [$vacio ? '' : json_encode($d, JSON_UNESCAPED_UNICODE), $estado, $marca, mb_substr(trim((string)($_POST['nota'][$pid] ?? '')), 0, 120), $ev['id'], $pid]);
        return !$vacio || $estado !== 'ok';
    };
    $n = 0;
    switch ($sub) {
        case 'intentos_guardar':
            $total = (int)$cfg['intentos'] + (int)$cfg['extra'];
            foreach ($ins as $pid) {
                $i = $w = [];
                for ($k = 0; $k < $total; $k++) {
                    try {
                        $i[$k] = pv_leer_intento((string)($_POST['i'][$pid][$k] ?? ''));
                        $w[$k] = (int)$cfg['viento'] ? (string)(pv_leer_viento((string)($_POST['w'][$pid][$k] ?? '')) ?? '') : '';
                    } catch (RuntimeException $e) {
                        throw new RuntimeException($nom($pid) . ', intento ' . ($k + 1) . ': ' . $e->getMessage());
                    }
                }
                while ($i && end($i) === '') { array_pop($i); array_pop($w); }
                $m = pv_mejores_intentos($i);
                if ($guardar($pid, ['i' => $i, 'w' => (int)$cfg['viento'] ? $w : []], $est($pid), $m[0] ?? null)) $n++;
            }
            return "Intentos guardados ($n atletas). Los puestos ya están calculados.";
        case 'alturas_guardar':
            $alturas = pv_alturas_leer((string)$cfg['alturas']);
            foreach ($ins as $pid) {
                $h = [];
                foreach ($alturas as $a) {
                    $k = number_format($a, 2, '.', '');
                    $v = strtoupper(str_replace(['0', '–', '—', ' '], ['O', '-', '-', ''], (string)($_POST['h'][$pid][$k] ?? '')));
                    if ($v === '') continue;
                    if (!preg_match('/^(-|X{0,2}O|X{1,3}|X{0,2}-)$/', $v)) throw new RuntimeException($nom($pid) . ' en ' . pv_fmt_m($a) . " m: «{$v}» no vale. Escriba O (pasó), XO, XXO, X, XX, XXX (falló) o - (no saltó).");
                    $h[$k] = $v;
                }
                $mejor = pv_resumen_alturas($h)[0];
                if ($guardar($pid, ['h' => $h], $est($pid), $mejor)) $n++;
            }
            return "Alturas guardadas ($n atletas).";
        case 'alturas_lista':
            $c = pv_json($ev['config']);
            $c['alturas'] = implode(' ', array_map(fn($x) => number_format($x, 2, ',', ''), pv_alturas_leer(post('alturas'))));
            q('UPDATE prueba_eventos SET config = ? WHERE id = ?', [json_encode($c, JSON_UNESCAPED_UNICODE), $ev['id']]);
            return 'Alturas del concurso guardadas.';
        case 'comb_guardar':
            $pr = pv_combinada($cfg['tipo']);
            foreach ($ins as $pid) {
                $m = [];
                foreach ($pr as $k => $x) {
                    try { $v = pv_leer_marca_comb($x, (string)($_POST['m'][$pid][$k] ?? '')); } catch (RuntimeException $e) { throw new RuntimeException($nom($pid) . ': ' . $e->getMessage()); }
                    if ($v !== null) $m[$k] = $v;
                }
                $tot = 0;
                foreach ($m as $k => $v) $tot += pv_pts_combinada($pr[$k], $v);
                if ($guardar($pid, ['m' => $m], $est($pid), $m ? (float)$tot : null)) $n++;
            }
            return "Marcas guardadas ($n atletas). Los puntos se calculan con la tabla de World Athletics.";
        case 'pesas_guardar':
            $ni = (int)$cfg['intentos'];
            foreach ($ins as $pid) {
                $a = $e = [];
                for ($k = 0; $k < $ni; $k++) {
                    try {
                        $a[] = pv_leer_pesas((string)($_POST['a'][$pid][$k] ?? ''));
                        $e[] = pv_leer_pesas((string)($_POST['e'][$pid][$k] ?? ''));
                    } catch (RuntimeException $ex) { throw new RuntimeException($nom($pid) . ': ' . $ex->getMessage()); }
                }
                foreach ([$a, $e] as $lista) {
                    $ant = 0;
                    foreach ($lista as $x) {
                        $kg = (int)preg_replace('/\D/', '', $x);
                        if ($kg && $kg < $ant) throw new RuntimeException($nom($pid) . ': el peso de un intento no puede ser menor que el del anterior (' . $ant . ' kg).');
                        if ($kg) $ant = $kg;
                    }
                }
                $pc = trim(str_replace(',', '.', (string)($_POST['pc'][$pid] ?? '')));
                if ($pc !== '' && (!is_numeric($pc) || (float)$pc <= 20 || (float)$pc > 250)) throw new RuntimeException($nom($pid) . ': el peso corporal va en kilos (ej: 70,45).');
                [$ma] = pv_mejor_pesas($a);
                [$me] = pv_mejor_pesas($e);
                if ($guardar($pid, ['pc' => $pc === '' ? '' : round((float)$pc, 2), 'a' => $a, 'e' => $e], $est($pid), $ma !== null && $me !== null ? (float)($ma + $me) : null)) $n++;
            }
            return "Intentos guardados ($n pesistas).";
        case 'seg_guardar':
            $seg = array_values(array_filter(array_map('trim', explode(',', (string)$cfg['segmentos']))));
            foreach ($ins as $pid) {
                $s = [];
                foreach ($seg as $i => $nombre_s) {
                    $txt = trim((string)($_POST['s'][$pid][$i] ?? ''));
                    if ($txt === '') { $s[$i] = null; continue; }
                    $ms = carrera_parse_tiempo($txt);
                    if ($ms === null) throw new RuntimeException($nom($pid) . ", {$nombre_s}: «{$txt}» no es un tiempo válido (ej: 12:34 o 1:05:20).");
                    $s[$i] = $ms;
                }
                $tot = array_sum(array_filter($s, fn($x) => $x !== null));
                if ($guardar($pid, ['s' => $s], $est($pid), $tot ?: null)) $n++;
            }
            return "Tiempos guardados ($n).";
        case 'ruta_guardar':
            foreach ($ins as $pid) {
                $of = trim((string)($_POST['of'][$pid] ?? ''));
                $ne = trim((string)($_POST['ne'][$pid] ?? ''));
                $msof = $of === '' ? null : carrera_parse_tiempo($of);
                $msne = $ne === '' ? null : carrera_parse_tiempo($ne);
                if ($of !== '' && $msof === null || $ne !== '' && $msne === null) throw new RuntimeException($nom($pid) . ': escriba el tiempo como 45:12 o 1:23:45.');
                $dorsal = mb_substr(trim((string)($_POST['dorsal'][$pid] ?? '')), 0, 6);
                if ($dorsal !== '' && $dorsal !== (string)$part[$pid]['numero']) {
                    if (fila('SELECT id FROM prueba_participantes WHERE torneo_id = ? AND numero = ? AND id <> ?', [$t['id'], $dorsal, $pid])) throw new RuntimeException("El dorsal $dorsal ya lo tiene otro corredor.");
                    q('UPDATE prueba_participantes SET numero = ? WHERE id = ?', [$dorsal, $pid]);
                }
                if ($guardar($pid, ['neto' => $msne ?? ''], $est($pid), $msof !== null ? (float)$msof : null)) $n++;
            }
            return "Tiempos guardados ($n corredores).";
        case 'ruta_pegar':
            // una línea por corredor: dorsal; tiempo oficial; tiempo neto (opcional). Sirve la exportación del cronometraje (CSV).
            $por_dorsal = [];
            foreach ($part as $p) if ($p['numero'] !== '') $por_dorsal[mb_strtolower($p['numero'])] = $p;
            $no = [];
            foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['pegar'] ?? '')) as $linea) {
                $c = array_values(array_filter(array_map('trim', preg_split('/[;\t,]+/', trim($linea))), fn($x) => $x !== ''));
                if (count($c) < 2 || !preg_match('/\d/', $c[1])) continue;
                $p = $por_dorsal[mb_strtolower($c[0])] ?? null;
                if (!$p) { $no[] = $c[0]; continue; }
                $of = carrera_parse_tiempo($c[1]);
                $ne = isset($c[2]) ? carrera_parse_tiempo($c[2]) : null;
                if ($of === null) { $no[] = $c[0]; continue; }
                q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id) VALUES (?, ?)', [$ev['id'], $p['id']]);
                $r = fila('SELECT * FROM prueba_resultados WHERE evento_id = ? AND participante_id = ?', [$ev['id'], $p['id']]);
                $d = pv_json($r['datos']);
                if ($ne !== null) $d['neto'] = $ne;
                q("UPDATE prueba_resultados SET marca = ?, estado = 'ok', datos = ? WHERE id = ?", [(float)$of, $d ? json_encode($d) : '', $r['id']]);
                $n++;
            }
            return "Se cargaron $n tiempos." . ($no ? ' Dorsales que no se encontraron: ' . implode(', ', array_slice($no, 0, 20)) . '.' : '');
        case 'ruta_todos':
            foreach ($part as $p) {
                if (!(int)$p['activo'] || prueba_no_puede($ev, $p, $t) !== '') continue;
                q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id) VALUES (?, ?)', [$ev['id'], $p['id']]);
                $n += (int)db()->query('SELECT changes()')->fetchColumn();
            }
            return "Se agregaron $n corredores a esta carrera.";
        case 'dorsales':
            $sig = (int)valor("SELECT COALESCE(MAX(CAST(numero AS INTEGER)), 0) FROM prueba_participantes WHERE torneo_id = ? AND numero GLOB '[0-9]*'", [$t['id']]);
            foreach (filas("SELECT * FROM prueba_participantes WHERE torneo_id = ? AND numero = '' ORDER BY id", [$t['id']]) as $p) { q('UPDATE prueba_participantes SET numero = ? WHERE id = ?', [(string)++$sig, $p['id']]); $n++; }
            return "Se asignaron $n dorsales.";
        case 'series_armar':
            return pv_series_armar($ev);
        case 'final_armar':
            return pv_final_armar($ev);
        case 'series_borrar':
            $fase = post('fase');
            if ($fase === 'f') q("DELETE FROM prueba_series WHERE evento_id = ? AND fase = 'f'", [$ev['id']]);
            else q('DELETE FROM prueba_series WHERE evento_id = ?', [$ev['id']]);
            if ($fase === 'f') foreach (filas("SELECT * FROM prueba_series WHERE evento_id = ? AND fase = 'r1'", [$ev['id']]) as $x) { $d = pv_json($x['datos']); unset($d['q']); q('UPDATE prueba_series SET datos = ? WHERE id = ?', [$d ? json_encode($d) : '', $x['id']]); }
            return $fase === 'f' ? 'Se borró la final.' : 'Se borraron las series.';
        case 'series_guardar':
            $se = pv_series($ev);
            foreach ($se['fases'] as $fase => $series) foreach ($series as $s => $lista) {
                try { $v = pv_leer_viento((string)($_POST['v'][$fase][$s] ?? '')); } catch (RuntimeException $e) { throw new RuntimeException(($fase === 'f' ? 'Final' : 'Serie ' . $s) . ': ' . $e->getMessage()); }
                foreach ($lista as $x) {
                    $pid = (int)$x['participante_id'];
                    $txt = trim((string)($_POST['tm'][$fase][$s][$pid] ?? ''));
                    $e = (string)($_POST['es'][$fase][$s][$pid] ?? 'ok');
                    $e = isset(pruebas_estados()[$e]) ? $e : 'ok';
                    $ms = $txt === '' ? null : carrera_parse_tiempo($txt);
                    if ($txt !== '' && $ms === null) throw new RuntimeException($nom($pid) . ": «{$txt}» no es un tiempo válido (ej: 11.45).");
                    $d = pv_json($x['datos']);
                    if ($v !== null) $d['v'] = $v; else unset($d['v']);
                    q('UPDATE prueba_series SET tiempo_ms = ?, estado = ?, carril = ?, datos = ? WHERE id = ?', [$e === 'ok' ? $ms : null, $e, entero($_POST['ca'][$fase][$s][$pid] ?? $x['carril'], 0, 12), $d ? json_encode($d) : '', $x['id']]);
                    if ($ms !== null || $e !== 'ok') $n++;
                }
            }
            // si cambió quién pasa y la final no tiene tiempos, se rearma
            $se2 = pv_series($ev);
            if (!empty($se2['fases']['f']) && !array_filter(array_merge(...array_values($se2['fases']['f'])), fn($x) => $x['tiempo_ms'] !== null || $x['estado'] !== 'ok')) {
                q("DELETE FROM prueba_series WHERE evento_id = ? AND fase = 'f'", [$ev['id']]);
                foreach (filas("SELECT * FROM prueba_series WHERE evento_id = ? AND fase = 'r1'", [$ev['id']]) as $x) { $d = pv_json($x['datos']); unset($d['q']); q('UPDATE prueba_series SET datos = ? WHERE id = ?', [$d ? json_encode($d) : '', $x['id']]); }
                if ($se2['completa_r1']) pv_final_armar(fila('SELECT * FROM prueba_eventos WHERE id = ?', [$ev['id']]));
            }
            return "Tiempos guardados ($n).";
    }
    throw new RuntimeException('Acción no válida para esta prueba.');
}

/* ============================================================
 * HTML
 * ============================================================ */

function pv_titulo_columna(array $ev): string
{
    return match ((string)($ev['formato'] ?? 'marca')) {
        'intentos', 'alturas' => 'Mejor marca',
        'combinadas' => 'Puntos',
        'pesas' => 'Total',
        'segmentos', 'ruta' => 'Tiempo',
        'series_final' => 'Tiempo',
        default => $ev['medida'] === 'metros' ? 'Marca' : 'Tiempo',
    };
}

/** Campos de formato y reglas en el formulario de la prueba. */
function pv_html_cfg_campos(array $t, ?array $ev): string
{
    $forms = pv_formatos((string)$t['modalidad']);
    $fo = (string)($ev['formato'] ?? array_key_first($forms));
    if (!isset($forms[$fo])) $fo = array_key_first($forms);
    $c = fn(string $f) => pv_cfg(['formato' => $f, 'config' => $fo === $f ? ($ev['config'] ?? '') : '', 'nombre' => $ev['nombre'] ?? '', 'medida' => $ev['medida'] ?? 'tiempo']);
    $uid = 'pvc' . (int)($ev['id'] ?? 0) . mt_rand(10, 99);
    ob_start(); ?>
    <div class="campo-ancho" id="<?= $uid ?>">
      <label>Formato de la prueba</label>
      <select name="formato" onchange="var c=document.getElementById('<?= $uid ?>');c.querySelectorAll('[data-fo]').forEach(function(d){d.style.display=d.getAttribute('data-fo').split(' ').indexOf(this.value)>=0?'':'none'},this)">
        <?php foreach ($forms as $k => $x): ?><option value="<?= $k ?>" <?= $fo === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?>
      </select>
      <?php $v = $c('marca'); ?>
      <div class="campos" data-fo="marca" style="<?= $fo === 'marca' && isset($forms['marca']) && (string)$t['modalidad'] === 'ATLET' ? '' : 'display:none' ?>;margin-top:6px">
        <div><label class="check" style="font-weight:400"><input type="checkbox" name="cfg_viento" value="1" <?= (int)($v['viento'] ?? 0) ? 'checked' : '' ?>> Anotar el viento (100 m, 200 m, vallas cortas)</label></div>
      </div>
      <?php $v = $c('series_final'); ?>
      <div class="campos" data-fo="series_final" style="<?= $fo === 'series_final' ? '' : 'display:none' ?>;margin-top:6px">
        <div><label>Atletas por serie</label><input type="number" name="cfg_por_serie" value="<?= (int)$v['por_serie'] ?>" min="2" max="12"><div class="ayuda">8 carriles = 8 por serie.</div></div>
        <div><label>Pasan por puesto (Q)</label><input type="number" name="cfg_pasan" value="<?= (int)$v['pasan'] ?>" min="0" max="8"><div class="ayuda">Los primeros de cada serie.</div></div>
        <div><label>Pasan por tiempo (q)</label><input type="number" name="cfg_pasan_tiempo" value="<?= (int)$v['pasan_tiempo'] ?>" min="0" max="8"><div class="ayuda">Los mejores tiempos que siguen.</div></div>
        <div><label class="check" style="font-weight:400"><input type="checkbox" name="cfg_viento" value="1" <?= (int)$v['viento'] ? 'checked' : '' ?>> Anotar el viento de cada serie</label></div>
      </div>
      <?php $v = $c('intentos'); ?>
      <div class="campos" data-fo="intentos" style="<?= $fo === 'intentos' ? '' : 'display:none' ?>;margin-top:6px">
        <div><label>Intentos</label><input type="number" name="cfg_intentos" value="<?= (int)$v['intentos'] ?>" min="1" max="6"><div class="ayuda">World Athletics: 3.</div></div>
        <div><label>Intentos de la final</label><input type="number" name="cfg_extra" value="<?= (int)$v['extra'] ?>" min="0" max="3"><div class="ayuda">3 más para los mejores; 0 = sin final.</div></div>
        <div><label>Pasan a la final</label><input type="number" name="cfg_finalistas" value="<?= (int)$v['finalistas'] ?>" min="0" max="20"><div class="ayuda">Los 8 mejores.</div></div>
        <div><label class="check" style="font-weight:400"><input type="checkbox" name="cfg_viento" value="1" <?= (int)$v['viento'] ? 'checked' : '' ?>> Anotar el viento (largo y triple)</label></div>
      </div>
      <?php $v = $c('alturas'); ?>
      <div class="campos" data-fo="alturas" style="<?= $fo === 'alturas' ? '' : 'display:none' ?>;margin-top:6px">
        <div class="campo-ancho"><label>Alturas del concurso (metros)</label><input type="text" name="cfg_alturas" value="<?= e($v['alturas']) ?>"><div class="ayuda">Separadas por espacios. Ej: 1,20 1,25 1,30</div></div>
      </div>
      <?php $v = $c('combinadas'); ?>
      <div class="campos" data-fo="combinadas" style="<?= $fo === 'combinadas' ? '' : 'display:none' ?>;margin-top:6px">
        <div><label>Combinada</label><select name="cfg_tipo"><option value="heptatlon" <?= $v['tipo'] === 'heptatlon' ? 'selected' : '' ?>>Heptatlón (7 pruebas)</option><option value="decatlon" <?= $v['tipo'] === 'decatlon' ? 'selected' : '' ?>>Decatlón (10 pruebas)</option></select></div>
      </div>
      <?php $v = $c('ruta'); ?>
      <div class="campos" data-fo="ruta" style="<?= $fo === 'ruta' ? '' : 'display:none' ?>;margin-top:6px">
        <div><label>Clasificar por</label><select name="cfg_neto"><option value="1" <?= (int)$v['neto'] ? 'selected' : '' ?>>Tiempo neto (chip)</option><option value="0" <?= !(int)$v['neto'] ? 'selected' : '' ?>>Tiempo oficial (pistola)</option></select><div class="ayuda">World Athletics: el oficial para los primeros puestos.</div></div>
        <div class="campo-ancho"><label>Grupos de edad</label><input type="text" name="cfg_cats" value="<?= e($v['cats']) ?>"><div class="ayuda">Ej: 20-29, 30-39, 40-49, 50-59, 60 (60 = 60 o más). Cada grupo, por rama.</div></div>
        <div><label>Cross: suman por equipo</label><input type="number" name="cfg_equipos" value="<?= (int)$v['equipos'] ?>" min="0" max="10"><div class="ayuda">4 = los 4 primeros de cada club (gana el que suma menos). 0 = sin equipos.</div></div>
        <div><label>Premiados por grupo</label><input type="number" name="cfg_premiados" value="<?= (int)$v['premiados'] ?>" min="1" max="10"></div>
      </div>
      <?php $v = $c('segmentos'); ?>
      <div class="campos" data-fo="segmentos" style="<?= $fo === 'segmentos' ? '' : 'display:none' ?>;margin-top:6px">
        <div class="campo-ancho"><label>Segmentos (en orden)</label><input type="text" name="cfg_segmentos" value="<?= e($v['segmentos']) ?>"><div class="ayuda">Separados por comas. Ej: Natación, T1, Ciclismo, T2, Carrera</div></div>
        <div class="campo-ancho"><label>Grupos de edad</label><input type="text" name="cfg_cats" value="<?= e($v['cats']) ?>"></div>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}

/** Bloque del panel para cargar los resultados de una prueba con formato de este archivo. */
function pv_html_admin(array $t, array $ev, array $part, string $url): string
{
    $f = (string)$ev['formato'];
    $cfg = pv_cfg($ev);
    $T = textos_pruebas($t);
    $filas = prueba_resultados($ev, $part);
    $ins = $filas;
    usort($ins, fn($a, $b) => [(int)$a['carril'] ?: 999, $a['p']['numero'] === '' ? 99999 : (int)$a['p']['numero'], $a['p']['nombre']] <=> [(int)$b['carril'] ?: 999, $b['p']['numero'] === '' ? 99999 : (int)$b['p']['numero'], $b['p']['nombre']]);
    $hid = csrf_campo() . '<input type="hidden" name="accion" value="pista"><input type="hidden" name="evento_id" value="' . (int)$ev['id'] . '">';
    $sel_est = fn(int $pid, string $e) => '<select name="est[' . $pid . ']">' . implode('', array_map(fn($k) => '<option value="' . $k . '"' . ($e === $k ? ' selected' : '') . '>' . e($k === 'ok' ? 'Compitió' : strtoupper($k)) . '</option>', array_keys(pruebas_estados()))) . '</select>';
    $nota = fn(int $pid, string $v) => '<input type="text" name="nota[' . $pid . ']" value="' . e($v) . '" maxlength="120" style="min-width:90px">';
    $nombre = fn(array $r) => '<td style="white-space:normal;min-width:120px">' . e($r['p']['nombre']) . '<br><small class="suave">' . e(trim(($r['p']['numero'] !== '' ? '#' . $r['p']['numero'] . ' · ' : '') . $r['p']['club'], ' ·')) . '</small></td>';
    ob_start();
    if ($f === 'intentos'):
        $total = (int)$cfg['intentos'] + (int)$cfg['extra'];
        // finalistas: los mejores después de los primeros intentos
        $mejores = [];
        foreach ($ins as $r) {
            $int = array_slice(array_map('strval', (array)(pv_json($r['datos'])['i'] ?? [])), 0, (int)$cfg['intentos']);
            if ($m = pv_mejores_intentos($int)) $mejores[(int)$r['participante_id']] = $m;
        }
        uasort($mejores, fn($a, $b) => array_pad($b, 6, 0) <=> array_pad($a, 6, 0));
        $fin = array_slice(array_keys($mejores), 0, (int)$cfg['finalistas']); ?>
      <p class="ayuda"><b>Concurso (World Athletics):</b> en cada intento escriba la marca en metros (ej: <b>5,62</b>), <b>X</b> si fue nulo o <b>-</b> si pasó. <?= (int)$cfg['extra'] ? 'Después de ' . (int)$cfg['intentos'] . ' intentos, los ' . (int)$cfg['finalistas'] . ' mejores hacen ' . (int)$cfg['extra'] . ' más (sus casillas se marcan en verde).' : '' ?> Gana la mejor marca; si empatan, decide la segunda mejor.<?= (int)$cfg['viento'] ? ' Debajo de cada marca, el viento (ej: +1,2).' : '' ?></p>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="intentos_guardar">
        <div class="tabla-scroll"><table class="tabla-grilla"><tr><th>#</th><th><?= e($T['participante']) ?></th><?php for ($k = 1; $k <= $total; $k++): ?><th><?= $k ?>.º</th><?php endfor; ?><th>Mejor</th><th>Estado</th><th>Nota</th></tr>
          <?php foreach ($ins as $r): $pid = (int)$r['participante_id']; $d = pv_json($r['datos']); $i = (array)($d['i'] ?? []); $w = (array)($d['w'] ?? []); ?>
            <tr><td><?= $r['puesto'] ?? '' ?></td><?= $nombre($r) ?>
              <?php for ($k = 0; $k < $total; $k++): $x = (string)($i[$k] ?? ''); $es_fin = $k >= (int)$cfg['intentos']; ?>
                <td style="<?= $es_fin && in_array($pid, $fin, true) ? 'background:#e8f6ea' : ($es_fin ? 'opacity:.5' : '') ?>"><input type="text" name="i[<?= $pid ?>][<?= $k ?>]" value="<?= e($x === '' || $x === 'X' || $x === '-' ? $x : pv_fmt_m((float)$x)) ?>" style="width:56px" inputmode="decimal">
                  <?php if ((int)$cfg['viento']): ?><br><input type="text" name="w[<?= $pid ?>][<?= $k ?>]" value="<?= e(isset($w[$k]) && $w[$k] !== '' ? pv_fmt_viento($w[$k]) : '') ?>" style="width:56px;font-size:.75rem" placeholder="viento"><?php endif; ?></td>
              <?php endfor; ?>
              <td class="num"><b><?= e($r['texto']) ?></b></td><td><?= $sel_est($pid, $r['estado']) ?></td><td><?= $nota($pid, $r['nota']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar intentos</button>
      </form>
    <?php elseif ($f === 'alturas'):
        $alturas = pv_alturas_leer((string)$cfg['alturas']); ?>
      <p class="ayuda"><b>Salto alto y garrocha (World Athletics):</b> en cada altura escriba lo que hizo: <b>O</b> pasó al 1.º, <b>XO</b> al 2.º, <b>XXO</b> al 3.º, <b>XXX</b> quedó eliminado, <b>X</b> o <b>XX</b> y luego pasó a la siguiente (<b>X-</b>), o <b>-</b> si no saltó esa altura. Gana la mayor altura; si empatan, el de menos intentos en esa altura y luego el de menos fallos en todo el concurso.</p>
      <form method="post" style="margin-bottom:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center"><?= $hid ?><input type="hidden" name="sub" value="alturas_lista"><label style="margin:0">Alturas:</label><input type="text" name="alturas" value="<?= e($cfg['alturas']) ?>" style="min-width:260px;flex:1"><button class="btn btn-sec btn-chico">Cambiar alturas</button></form>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="alturas_guardar">
        <div class="tabla-scroll"><table class="tabla-grilla"><tr><th>#</th><th><?= e($T['participante']) ?></th><?php foreach ($alturas as $a): ?><th><?= e(pv_fmt_m($a)) ?></th><?php endforeach; ?><th>Mejor</th><th>Estado</th><th>Nota</th></tr>
          <?php foreach ($ins as $r): $pid = (int)$r['participante_id']; $h = (array)(pv_json($r['datos'])['h'] ?? []); ?>
            <tr><td><?= $r['puesto'] ?? '' ?></td><?= $nombre($r) ?>
              <?php foreach ($alturas as $a): $k = number_format($a, 2, '.', ''); ?><td><input type="text" name="h[<?= $pid ?>][<?= $k ?>]" value="<?= e((string)($h[$k] ?? '')) ?>" style="width:44px;text-transform:uppercase" maxlength="3"></td><?php endforeach; ?>
              <td class="num"><b><?= e($r['texto']) ?></b></td><td><?= $sel_est($pid, $r['estado']) ?></td><td><?= $nota($pid, $r['nota']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar alturas</button>
      </form>
    <?php elseif ($f === 'combinadas'):
        $pr = pv_combinada($cfg['tipo']); ?>
      <p class="ayuda"><b><?= $cfg['tipo'] === 'decatlon' ? 'Decatlón' : 'Heptatlón' ?> (tabla de puntos de World Athletics):</b> escriba la marca de cada prueba: tiempos como <b>11.45</b> o <b>2:15.30</b>, saltos y lanzamientos en metros (<b>6,12</b>). Los puntos se calculan solos y gana el que suma más.</p>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="comb_guardar">
        <div class="tabla-scroll"><table class="tabla-grilla"><tr><th>#</th><th><?= e($T['participante']) ?></th><?php foreach ($pr as $x): ?><th><?= e($x[0]) ?></th><?php endforeach; ?><th>Total</th><th>Estado</th><th>Nota</th></tr>
          <?php foreach ($ins as $r): $pid = (int)$r['participante_id']; $m = (array)(pv_json($r['datos'])['m'] ?? []); ?>
            <tr><td><?= $r['puesto'] ?? '' ?></td><?= $nombre($r) ?>
              <?php foreach ($pr as $k => $x): $val = isset($m[$k]) ? ($x[1] === 't' ? prueba_fmt_marca('tiempo', $m[$k]) : pv_fmt_m((float)$m[$k])) : ''; ?>
                <td><input type="text" name="m[<?= $pid ?>][<?= e($k) ?>]" value="<?= e($val) ?>" style="width:62px" inputmode="decimal"><?php if (isset($m[$k])): ?><br><small class="suave"><?= pv_pts_combinada($x, (float)$m[$k]) ?> pts</small><?php endif; ?></td>
              <?php endforeach; ?>
              <td class="num"><b><?= e($r['texto']) ?></b></td><td><?= $sel_est($pid, $r['estado']) ?></td><td><?= $nota($pid, $r['nota']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar marcas</button>
      </form>
    <?php elseif ($f === 'pesas'):
        $ni = (int)$cfg['intentos'];
        [$pmin, $pmax] = pv_limites_pesas((string)$ev['nombre']); ?>
      <p class="ayuda"><b>Pesas (IWF):</b> escriba el peso corporal del pesaje<?= $pmax ? ' (hasta ' . $pmax . ' kg)' : ($pmin ? ' (más de ' . $pmin . ' kg)' : '') ?> y los kilos de cada intento: <b>85</b> si fue válido, <b>x85</b> si fue nulo, <b>-</b> si no lo hizo. Cada intento debe ser igual o mayor que el anterior. Total = mejor arranque + mejor envión; si empatan, gana el de menor peso corporal.</p>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="pesas_guardar">
        <div class="tabla-scroll"><table class="tabla-grilla"><tr><th>#</th><th><?= e($T['participante']) ?></th><th>Peso corporal</th><?php for ($k = 1; $k <= $ni; $k++): ?><th>Arr. <?= $k ?></th><?php endfor; ?><?php for ($k = 1; $k <= $ni; $k++): ?><th>Env. <?= $k ?></th><?php endfor; ?><th>Total</th><th>Estado</th><th>Nota</th></tr>
          <?php foreach ($ins as $r): $pid = (int)$r['participante_id']; $d = pv_json($r['datos']); ?>
            <tr><td><?= $r['puesto'] ?? '' ?></td><?= $nombre($r) ?>
              <td><input type="text" name="pc[<?= $pid ?>]" value="<?= isset($d['pc']) && $d['pc'] !== '' ? e(number_format((float)$d['pc'], 2, ',', '')) : '' ?>" style="width:62px" inputmode="decimal"></td>
              <?php foreach (['a', 'e'] as $tipo): for ($k = 0; $k < $ni; $k++): ?><td><input type="text" name="<?= $tipo ?>[<?= $pid ?>][<?= $k ?>]" value="<?= e((string)(($d[$tipo] ?? [])[$k] ?? '')) ?>" style="width:50px"></td><?php endfor; endforeach; ?>
              <td class="num"><b><?= e($r['texto']) ?></b></td><td><?= $sel_est($pid, $r['estado']) ?></td><td><?= $nota($pid, $r['nota']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar intentos</button>
      </form>
    <?php elseif ($f === 'segmentos'):
        $seg = array_values(array_filter(array_map('trim', explode(',', (string)$cfg['segmentos'])))); ?>
      <p class="ayuda"><b>Por segmentos:</b> escriba el tiempo de cada parte (ej: <b>12:34</b> o <b>1:05:20</b>). El total se suma solo; solo clasifica quien tiene todos los segmentos. Grupos de edad: <?= e($cfg['cats']) ?>.</p>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="seg_guardar">
        <div class="tabla-scroll"><table class="tabla-grilla"><tr><th>#</th><th><?= e($T['participante']) ?></th><?php foreach ($seg as $s): ?><th><?= e($s) ?></th><?php endforeach; ?><th>Total</th><th>Estado</th><th>Nota</th></tr>
          <?php foreach ($ins as $r): $pid = (int)$r['participante_id']; $s = (array)(pv_json($r['datos'])['s'] ?? []); ?>
            <tr><td><?= $r['puesto'] ?? '' ?></td><?= $nombre($r) ?>
              <?php foreach ($seg as $i => $x): ?><td><input type="text" name="s[<?= $pid ?>][<?= $i ?>]" value="<?= isset($s[$i]) && $s[$i] !== null && $s[$i] !== '' ? e(pv_fmt_tiempo((int)$s[$i])) : '' ?>" style="width:70px"></td><?php endforeach; ?>
              <td class="num"><b><?= e($r['texto']) ?></b></td><td><?= $sel_est($pid, $r['estado']) ?></td><td><?= $nota($pid, $r['nota']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar tiempos</button>
      </form>
    <?php elseif ($f === 'ruta'): ?>
      <p class="ayuda"><b>Carrera de ruta:</b> escriba el tiempo <b>oficial</b> (desde el disparo) y el <b>neto</b> (chip, desde que cruzó la salida). Clasifica por <?= (int)$cfg['neto'] ? 'tiempo neto' : 'tiempo oficial' ?>. Grupos de edad: <?= e($cfg['cats']) ?> (por rama).<?= (int)$cfg['equipos'] ? ' Por equipos suman los ' . (int)$cfg['equipos'] . ' primeros de cada club.' : '' ?></p>
      <div class="rejilla-2" style="margin-bottom:10px">
        <form method="post" class="tarjeta" style="padding:10px"><?= $hid ?><input type="hidden" name="sub" value="ruta_pegar">
          <b>📋 Pegar los tiempos del cronometraje</b>
          <p class="ayuda" style="margin:.3rem 0">Una línea por corredor: <b>dorsal; tiempo oficial; tiempo neto</b> (el neto es opcional). Sirve copiar las columnas del Excel del chip.</p>
          <textarea name="pegar" rows="4" placeholder="101; 38:12; 38:05&#10;102; 41:30.5"></textarea>
          <button class="btn btn-chico" style="margin-top:6px">Cargar tiempos</button></form>
        <div class="tarjeta" style="padding:10px">
          <form method="post"><?= $hid ?><input type="hidden" name="sub" value="ruta_todos"><button class="btn btn-sec btn-chico">➕ Inscribir a todos los corredores en esta carrera</button></form>
          <form method="post" style="margin-top:6px"><?= $hid ?><input type="hidden" name="sub" value="dorsales"><button class="btn btn-sec btn-chico">#️⃣ Dar dorsal a los que no tienen</button></form>
        </div>
      </div>
      <form method="post"><?= $hid ?><input type="hidden" name="sub" value="ruta_guardar">
        <div class="tabla-scroll"><table class="tabla-grilla"><tr><th>#</th><th>Dorsal</th><th><?= e($T['participante']) ?></th><th>Oficial</th><th>Neto (chip)</th><th>Grupo</th><th>Estado</th><th>Nota</th></tr>
          <?php foreach ($ins as $r): $pid = (int)$r['participante_id']; $d = pv_json($r['datos']); ?>
            <tr><td><?= $r['puesto'] ?? '' ?></td><td><input type="text" name="dorsal[<?= $pid ?>]" value="<?= e($r['p']['numero']) ?>" style="width:56px" maxlength="6"></td><?= $nombre($r) ?>
              <td><input type="text" name="of[<?= $pid ?>]" value="<?= $r['marca'] !== null ? e(pv_fmt_tiempo((int)$r['marca'])) : '' ?>" style="width:78px"></td>
              <td><input type="text" name="ne[<?= $pid ?>]" value="<?= isset($d['neto']) && $d['neto'] !== '' ? e(pv_fmt_tiempo((int)$d['neto'])) : '' ?>" style="width:78px"></td>
              <td><small><?= e(($r['grupo'] ?? '') . (isset($r['puesto_grupo']) ? ' · ' . $r['puesto_grupo'] . '.º' : '')) ?></small></td>
              <td><?= $sel_est($pid, $r['estado']) ?></td><td><?= $nota($pid, $r['nota']) ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar tiempos</button>
      </form>
    <?php elseif ($f === 'series_final'):
        $se = pv_series($ev); ?>
      <?php if (!$se['fases']): ?>
        <p class="ayuda"><b>Series y final (World Athletics):</b> se arman series de hasta <?= (int)$cfg['por_serie'] ?> atletas (los más rápidos, por la marca de referencia si la anotó, al centro). A la final pasan los <?= (int)$cfg['pasan'] ?> primeros de cada serie (Q) y los <?= (int)$cfg['pasan_tiempo'] ?> mejores tiempos que siguen (q). Con <?= (int)$cfg['por_serie'] ?> atletas o menos, la serie es la final.</p>
        <form method="post"><?= $hid ?><input type="hidden" name="sub" value="series_armar"><button class="btn">🏁 Armar las series</button></form>
      <?php else: ?>
        <form method="post"><?= $hid ?><input type="hidden" name="sub" value="series_guardar">
          <?php foreach (['f', 'r1'] as $fase): if (empty($se['fases'][$fase])) continue; ?>
            <h4 style="margin:.6rem 0 .3rem"><?= $fase === 'f' ? '🏆 Final' : ($se['una_serie'] ? '🏆 Final (serie única)' : 'Series') ?></h4>
            <div class="rejilla-2">
            <?php foreach ($se['fases'][$fase] as $s => $lista): $vt = pv_json($lista[0]['datos'])['v'] ?? ''; ?>
              <div class="tarjeta" style="padding:8px"><b><?= $fase === 'f' ? 'Final' : 'Serie ' . $s ?></b>
                <?php if ((int)$cfg['viento']): ?> · Viento <input type="text" name="v[<?= $fase ?>][<?= $s ?>]" value="<?= e($vt !== '' ? pv_fmt_viento($vt) : '') ?>" style="width:60px" placeholder="+0,0"><?php endif; ?>
                <div class="tabla-scroll"><table class="tabla-grilla"><tr><th>Carril</th><th><?= e($T['participante']) ?></th><th>Tiempo</th><th>Estado</th></tr>
                  <?php foreach ($lista as $x): $pid = (int)$x['participante_id']; $q = pv_json($x['datos'])['q'] ?? ''; ?>
                    <tr><td><input type="number" name="ca[<?= $fase ?>][<?= $s ?>][<?= $pid ?>]" value="<?= (int)$x['carril'] ?>" min="0" max="12" style="width:50px"></td>
                      <td><?= e($part[$pid]['nombre'] ?? '?') ?><?= $q !== '' ? ' <span class="etiqueta">' . e($q) . '</span>' : '' ?></td>
                      <td><input type="text" name="tm[<?= $fase ?>][<?= $s ?>][<?= $pid ?>]" value="<?= $x['tiempo_ms'] !== null ? e(prueba_fmt_marca('tiempo', $x['tiempo_ms'])) : '' ?>" style="width:70px" inputmode="decimal"></td>
                      <td><select name="es[<?= $fase ?>][<?= $s ?>][<?= $pid ?>]"><?php foreach (pruebas_estados() as $k => $y): ?><option value="<?= $k ?>" <?= $x['estado'] === $k ? 'selected' : '' ?>><?= e($k === 'ok' ? 'Corrió' : strtoupper($k)) ?></option><?php endforeach; ?></select></td></tr>
                  <?php endforeach; ?>
                </table></div></div>
            <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <button class="btn" style="margin-top:8px">💾 Guardar tiempos</button>
        </form>
        <?php if (!$se['una_serie'] && $se['completa_r1'] && empty($se['fases']['f'])): ?>
          <form method="post" style="margin-top:8px"><?= $hid ?><input type="hidden" name="sub" value="final_armar"><button class="btn">🏆 Armar la final (Q y q)</button></form>
        <?php endif; ?>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Borrar?')"><?= $hid ?><input type="hidden" name="sub" value="series_borrar"><input type="hidden" name="fase" value="<?= !empty($se['fases']['f']) ? 'f' : '' ?>"><button class="btn btn-peligro btn-chico"><?= !empty($se['fases']['f']) ? 'Borrar la final' : 'Borrar las series' ?></button></form>
      <?php endif; ?>
    <?php endif; ?>
    <style>.tabla-grilla td,.tabla-grilla th{padding:4px 5px}.tabla-grilla input{padding:4px}</style>
    <?php return (string)ob_get_clean();
}

/** Detalle público (series, grupos de edad y equipos) debajo de la tabla de resultados. */
function pv_html_detalle(array $t, array $ev, array $part): string
{
    $f = (string)$ev['formato'];
    $cfg = pv_cfg($ev);
    $T = textos_pruebas($t);
    if ($f === 'ruta' || $f === 'segmentos') {
        $res = array_values(array_filter(prueba_resultados($ev, $part), fn($r) => $r['puesto'] !== null));
        if (!$res) return '';
        $grupos = [];
        foreach ($res as $r) $grupos[$r['grupo']][] = $r;
        ksort($grupos);
        ob_start(); ?>
        <h3>Por grupo de edad</h3>
        <div class="rejilla-2" style="grid-template-columns:repeat(auto-fill,minmax(330px,1fr))">
        <?php foreach ($grupos as $g => $lista): ?>
          <div style="min-width:0"><b><?= e($g) ?></b>
            <div class="tabla-scroll"><table><tr><th>#</th><th><?= e($T['participante']) ?></th><th class="num">Tiempo</th><th class="num">Gral.</th></tr>
              <?php foreach (array_slice($lista, 0, max(3, (int)($cfg['premiados'] ?? 3) * 3)) as $i => $r): ?>
                <tr><td><?= $i < (int)($cfg['premiados'] ?? 3) ? icono_medalla(medalla_de_puesto($i + 1)) ?: ($i + 1) : $i + 1 ?></td><td><?= e($r['p']['nombre']) ?><?= $r['p']['numero'] !== '' ? ' <small class="suave">#' . e($r['p']['numero']) . '</small>' : '' ?></td><td class="num"><?= e($r['texto']) ?></td><td class="num"><?= (int)$r['puesto'] ?></td></tr>
              <?php endforeach; ?>
            </table></div></div>
        <?php endforeach; ?>
        </div>
        <?php if ($f === 'ruta' && (int)$cfg['equipos'] > 0 && ($eq = pv_equipos_cross($ev, $res, (int)$cfg['equipos']))): ?>
          <h3>Por equipos (suman los <?= (int)$cfg['equipos'] ?> primeros de cada club)</h3>
          <div class="tabla-scroll"><table><tr><th>#</th><th>Club</th><th>Puestos</th><th class="num">Suma</th></tr>
            <?php foreach ($eq as $i => $x): ?><tr><td><?= $i + 1 ?></td><td><?= e($x['club']) ?></td><td><small><?= e(implode(' + ', $x['puestos'])) ?></small></td><td class="num"><b><?= $x['suma'] ?></b></td></tr><?php endforeach; ?>
          </table></div>
        <?php endif;
        return (string)ob_get_clean();
    }
    if ($f === 'series_final') {
        $se = pv_series($ev);
        if (!$se['fases'] || $se['una_serie']) return '';
        ob_start(); ?>
        <h3>Series</h3>
        <div class="rejilla-2">
        <?php foreach ($se['fases']['r1'] as $s => $lista):
            usort($lista, fn($a, $b) => [$a['estado'] !== 'ok' || $a['tiempo_ms'] === null ? 1 : 0, (int)$a['tiempo_ms']] <=> [$b['estado'] !== 'ok' || $b['tiempo_ms'] === null ? 1 : 0, (int)$b['tiempo_ms']]);
            $vt = pv_json($lista[0]['datos'])['v'] ?? ''; ?>
          <div><b>Serie <?= $s ?></b><?= $vt !== '' ? ' <small class="suave">' . e(pv_viento_txt($vt)) . '</small>' : '' ?>
            <div class="tabla-scroll"><table><tr><th>#</th><th><?= e($T['participante']) ?></th><th class="num">Tiempo</th></tr>
              <?php foreach ($lista as $i => $x): $q = pv_json($x['datos'])['q'] ?? ''; ?><tr><td><?= $x['estado'] === 'ok' && $x['tiempo_ms'] !== null ? $i + 1 : '' ?></td><td><?= e($part[(int)$x['participante_id']]['nombre'] ?? '') ?></td>
                <td class="num"><?= e($x['estado'] !== 'ok' ? strtoupper($x['estado']) : ($x['tiempo_ms'] !== null ? prueba_fmt_marca('tiempo', $x['tiempo_ms']) : '')) ?><?= $q !== '' ? ' <b>' . e($q) . '</b>' : '' ?></td></tr><?php endforeach; ?>
            </table></div></div>
        <?php endforeach; ?>
        </div>
        <?php return (string)ob_get_clean();
    }
    return '';
}

/** Cross country: suman los N primeros de cada club (gana el que suma menos; equipos incompletos no clasifican). */
function pv_equipos_cross(array $ev, array $res, int $n): array
{
    $c = [];
    foreach ($res as $r) {
        $club = trim((string)$r['p']['club']);
        if ($club === '') continue;
        if (count($c[$club] ?? []) < $n) $c[$club][] = (int)$r['puesto'];
    }
    $eq = [];
    foreach ($c as $club => $ps) if (count($ps) === $n) $eq[] = ['club' => $club, 'puestos' => $ps, 'suma' => array_sum($ps)];
    usort($eq, fn($a, $b) => [$a['suma'], end($a['puestos'])] <=> [$b['suma'], end($b['puestos'])]);
    return $eq;
}

/* ============================================================
 * EJEMPLO
 * ============================================================ */

function pv_cargar_ejemplo(array $t): void
{
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM prueba_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene competidores. El ejemplo solo se carga en uno vacío.');
    $m = (string)$t['modalidad'];
    $anio = preg_match('/(\d{4})/', (string)$t['temporada'], $a) ? (int)$a[1] : (int)date('Y');
    mt_srand(3600 + strlen($m));
    $clubes = match ($m) { 'PESAS' => ['Club Halterofilia Pichincha', 'Concentración Deportiva', 'Club Fuerza Norte'], 'TRIAT' => ['Tri Club Quito', 'Andes Tri Team', 'Club Cumbayá Tri'],
        default => ['Club Atlético Quito', 'Liga Cantonal Rumiñahui', 'Corredores del Sur', 'Running Club Norte'] };
    $v = ['Mateo Andrade', 'Sebastián Vera', 'Nicolás Paredes', 'Martín Salazar', 'Daniel Cevallos', 'Joaquín Mena', 'Tomás Herrera', 'Emilio Castillo', 'Gabriel Ortiz', 'Samuel Proaño', 'Lucas Tapia', 'Pablo Ayala'];
    $d = ['Valentina Ruiz', 'Camila Guamán', 'Isabella Zambrano', 'Sofía Morales', 'Emilia Chiriboga', 'Antonella Vaca', 'Paula Benítez', 'Daniela Ochoa', 'Mariana Cueva', 'Andrea Lasso'];
    $alta = function (string $nom, string $rama, int $edad, int $i) use ($tid, $clubes, $anio) {
        q('INSERT INTO prueba_participantes (torneo_id, numero, nombre, club, ciudad, rama, nacimiento) VALUES (?,?,?,?,?,?,?)',
            [$tid, (string)(($rama === 'M' ? 100 : 200) + $i), $nom, $clubes[$i % count($clubes)], 'Quito', $rama, ($anio - $edad) . '-0' . (1 + $i % 9) . '-1' . ($i % 9)]);
        return (int)db()->lastInsertId();
    };
    $ins = fn(int $evid, int $pid) => q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id) VALUES (?, ?)', [$evid, $pid]);
    $ev_de = fn(string $nombre, string $rama = '') => fila('SELECT * FROM prueba_eventos WHERE torneo_id = ? AND nombre = ?' . ($rama !== '' ? ' AND rama = ?' : '') . ' ORDER BY id LIMIT 1', $rama !== '' ? [$tid, $nombre, $rama] : [$tid, $nombre]);
    if ($m === 'ATLET') {
        // Sub 18: 100 m (dos series y final), largo con intentos, alto por alturas, bala; Mayores: heptatlón y decatlón
        pruebas_crear_programa($t, ['100 m planos', 'Salto largo', 'Salto alto', 'Lanzamiento de bala', '1.500 m'], ['M', 'F'], [3], date('Y-m-d'));
        pruebas_crear_programa($t, ['Heptatlón (damas)'], ['F'], [5], date('Y-m-d'));
        pruebas_crear_programa($t, ['Decatlón (varones)'], ['M'], [5], date('Y-m-d'));
        $pv = $pd = [];
        foreach (array_slice($v, 0, 11) as $i => $n) $pv[] = $alta($n, 'M', 16 + $i % 2, $i);
        foreach (array_slice($d, 0, 6) as $i => $n) $pd[] = $alta($n, 'F', 16 + $i % 2, $i);
        $dec = [$alta('Andrés Mejía', 'M', 24, 20), $alta('Felipe Ríos', 'M', 22, 21), $alta('Diego Larrea', 'M', 27, 22)];
        $hep = [$alta('Fernanda Guerrero', 'F', 23, 30), $alta('Paula Benítez', 'F', 21, 31), $alta('Daniela Ochoa', 'F', 25, 32)];
        $t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
        foreach (pruebas_eventos($tid) as $ev) {
            $lista = $ev['nombre'] === 'Decatlón (varones)' ? $dec : ($ev['nombre'] === 'Heptatlón (damas)' ? $hep : ($ev['rama'] === 'M' ? $pv : $pd));
            foreach ($lista as $pid) $ins((int)$ev['id'], $pid);
        }
        // 100 m varones: 11 atletas → 2 series y final
        $ev = $ev_de('100 m planos', 'M');
        pv_series_armar($ev);
        foreach (filas("SELECT * FROM prueba_series WHERE evento_id = ? AND fase = 'r1'", [$ev['id']]) as $x) q('UPDATE prueba_series SET tiempo_ms = ?, datos = ? WHERE id = ?', [11200 + mt_rand(0, 900), json_encode(['v' => (int)$x['serie'] === 1 ? 1.2 : 2.4]), $x['id']]);
        pv_final_armar(fila('SELECT * FROM prueba_eventos WHERE id = ?', [$ev['id']]));
        foreach (filas("SELECT * FROM prueba_series WHERE evento_id = ? AND fase = 'f'", [$ev['id']]) as $x) q('UPDATE prueba_series SET tiempo_ms = ?, datos = ? WHERE id = ?', [11050 + mt_rand(0, 700), json_encode(['v' => 0.8]), $x['id']]);
        // 100 m damas: una serie (es la final)
        $ev = $ev_de('100 m planos', 'F');
        pv_series_armar($ev);
        foreach (filas("SELECT * FROM prueba_series WHERE evento_id = ?", [$ev['id']]) as $x) q('UPDATE prueba_series SET tiempo_ms = ? WHERE id = ?', [12600 + mt_rand(0, 1200), $x['id']]);
        // largo: 3 intentos + 3 de la final
        foreach (['M' => 5.6, 'F' => 4.7] as $ra => $b) {
            $ev = $ev_de('Salto largo', $ra);
            foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) {
                $i = $w = [];
                for ($k = 0; $k < 6; $k++) { $x = mt_rand(0, 4) === 0 ? 'X' : number_format($b + mt_rand(-40, 60) / 100, 2, '.', ''); $i[] = $x; $w[] = $x === 'X' ? '' : (string)(mt_rand(-10, 25) / 10); }
                $mm = pv_mejores_intentos($i);
                q('UPDATE prueba_resultados SET datos = ?, marca = ? WHERE id = ?', [json_encode(['i' => $i, 'w' => $w]), $mm[0] ?? null, $r['id']]);
            }
        }
        // alto: alturas
        foreach (['M' => '1.60', 'F' => '1.40'] as $ra => $base) {
            $ev = $ev_de('Salto alto', $ra);
            $alts = $ra === 'M' ? ['1.50', '1.55', '1.60', '1.65', '1.70', '1.75', '1.80'] : ['1.30', '1.35', '1.40', '1.45', '1.50', '1.55'];
            q('UPDATE prueba_eventos SET config = ? WHERE id = ?', [json_encode(['alturas' => implode(' ', array_map(fn($x) => str_replace('.', ',', $x), $alts))]), $ev['id']]);
            foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) {
                $h = [];
                $tope = mt_rand(2, count($alts) - 1);
                foreach ($alts as $k => $a) { if ($k < $tope) $h[$a] = ['O', 'O', 'XO', 'O', 'XXO'][mt_rand(0, 4)]; elseif ($k === $tope) { $h[$a] = 'XXX'; break; } }
                q('UPDATE prueba_resultados SET datos = ?, marca = ? WHERE id = ?', [json_encode(['h' => $h]), pv_resumen_alturas($h)[0], $r['id']]);
            }
        }
        // bala varones con intentos; damas por jugar
        $ev = $ev_de('Lanzamiento de bala', 'M');
        foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) {
            $i = [];
            for ($k = 0; $k < 3; $k++) $i[] = mt_rand(0, 5) === 0 ? 'X' : number_format(11 + mt_rand(-150, 200) / 100, 2, '.', '');
            q('UPDATE prueba_resultados SET datos = ?, marca = ? WHERE id = ?', [json_encode(['i' => $i]), pv_mejores_intentos($i)[0] ?? null, $r['id']]);
        }
        // 1.500 m varones con tiempo directo
        $ev = $ev_de('1.500 m', 'M');
        foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) q('UPDATE prueba_resultados SET marca = ? WHERE id = ?', [262000 + mt_rand(0, 40000), $r['id']]);
        // combinadas
        $ev = $ev_de('Decatlón (varones)');
        foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $k => $r) {
            $mm = ['100' => 11100 + $k * 150, 'largo' => 6.9 - $k * 0.2, 'bala' => 13.2 - $k * 0.5, 'alto' => 1.94 - $k * 0.06, '400' => 50200 + $k * 700, '110v' => 15100 + $k * 300,
                'disco' => 40.1 - $k * 2, 'garrocha' => 4.4 - $k * 0.2, 'jabalina' => 55.3 - $k * 3, '1500' => 278000 + $k * 6000];
            q('UPDATE prueba_resultados SET datos = ? WHERE id = ?', [json_encode(['m' => $mm]), $r['id']]);
        }
        $ev = $ev_de('Heptatlón (damas)');
        foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $k => $r) {
            $mm = ['100v' => 14200 + $k * 200, 'alto' => 1.70 - $k * 0.03, 'bala' => 12.5 - $k * 0.4, '200' => 25300 + $k * 300, 'largo' => 5.9 - $k * 0.1, 'jabalina' => 40.2 - $k * 2];
            q('UPDATE prueba_resultados SET datos = ? WHERE id = ?', [json_encode(['m' => $mm]), $r['id']]); // falta el 800 m: «en juego»
        }
    } elseif ($m === 'RUTA') {
        pruebas_crear_programa($t, ['10K', 'Carrera infantil'], ['X'], [0], date('Y-m-d'));
        $ev = $ev_de('10K');
        $nombres = array_merge($v, $d, ['Rosa Pilataxi', 'Luis Quishpe', 'Carmen Tituaña', 'Jorge Andrango', 'Elena Cachiguango', 'Marco Lema', 'Lucía Farinango', 'Raúl Simbaña']);
        foreach ($nombres as $i => $n) {
            $rama = in_array($n, $d, true) || in_array($n, ['Rosa Pilataxi', 'Carmen Tituaña', 'Elena Cachiguango', 'Lucía Farinango'], true) ? 'F' : 'M';
            $pid = $alta($n, $rama, 18 + ($i * 7) % 50, $i);
            q('UPDATE prueba_participantes SET numero = ? WHERE id = ?', [(string)(1000 + $i), $pid]);
            $ins((int)$ev['id'], $pid);
            if ($i < count($nombres) - 3) {
                $of = 2100000 + mt_rand(0, 1500000) + ($rama === 'F' ? 240000 : 0);
                q('UPDATE prueba_resultados SET marca = ?, datos = ? WHERE evento_id = ? AND participante_id = ?', [$of, json_encode(['neto' => $of - mt_rand(2000, 45000)]), $ev['id'], $pid]);
            }
        }
        q("UPDATE prueba_eventos SET config = ? WHERE id = ?", [json_encode(['neto' => 1, 'cats' => '18-29,30-39,40-49,50-59,60', 'equipos' => 3]), $ev['id']]);
    } elseif ($m === 'PESAS') {
        pruebas_crear_programa($t, ['Varones 71 kg', 'Damas 58 kg'], ['M', 'F'], [3], date('Y-m-d'));
        foreach (['Varones 71 kg' => ['M', $v, 68, 95], 'Damas 58 kg' => ['F', $d, 55, 70]] as $nom => [$ra, $nn, $pc, $base]) {
            $ev = $ev_de($nom);
            foreach (array_slice($nn, 0, 6) as $i => $n) {
                $pid = $alta($n, $ra, 20 + $i, $i);
                $ins((int)$ev['id'], $pid);
                $a1 = $base + mt_rand(-8, 8);
                $e1 = (int)round($a1 * 1.22);
                $aa = [(string)$a1, (mt_rand(0, 2) ? '' : 'x') . ($a1 + 3), (string)($a1 + 5)];
                $ee = [(string)$e1, (string)($e1 + 4), (mt_rand(0, 1) ? 'x' : '') . ($e1 + 7)];
                q('UPDATE prueba_resultados SET datos = ? WHERE evento_id = ? AND participante_id = ?', [json_encode(['pc' => $pc + mt_rand(0, 300) / 100, 'a' => $aa, 'e' => $ee]), $ev['id'], $pid]);
            }
        }
    } elseif ($m === 'TRIAT') {
        pruebas_crear_programa($t, ['Triatlón sprint (750 m · 20 km · 5 km)'], ['X'], [0], date('Y-m-d'));
        $ev = $ev_de('Triatlón sprint (750 m · 20 km · 5 km)');
        foreach (array_merge(array_slice($v, 0, 8), array_slice($d, 0, 6)) as $i => $n) {
            $ra = $i < 8 ? 'M' : 'F';
            $pid = $alta($n, $ra, 22 + $i * 3, $i);
            $ins((int)$ev['id'], $pid);
            $s = [720000 + mt_rand(0, 300000), 60000 + mt_rand(0, 60000), 2100000 + mt_rand(0, 600000), 40000 + mt_rand(0, 40000), 1200000 + mt_rand(0, 500000)];
            if ($i === 5) $s[4] = null; // no terminó la carrera: DNF
            q('UPDATE prueba_resultados SET datos = ?, estado = ? WHERE evento_id = ? AND participante_id = ?', [json_encode(['s' => $s]), $i === 5 ? 'dnf' : 'ok', $ev['id'], $pid]);
        }
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}
