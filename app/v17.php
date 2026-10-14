<?php
/*
 * VERSIÓN 17 — ESCUELAS:
 *   A) Entrenamientos: planificación por adelantado, biblioteca de ejercicios, clases con ejercicios, minutos,
 *      intensidad, fotos y nota para cada alumno; diario de entrenamientos para los padres.
 *   B) Desarrollo del alumno: gráficos (asistencia por mes, minutos por semana, perfil físico contra su categoría,
 *      técnica contra su grupo, crecimiento), récords, insignias, metas e informe trimestral.
 *   C) Jornadas de evaluación con planilla para el celular y semáforo por edad.
 *   D) Divisiones formativas (se activa por escuela): partidos y minutos, cuerpo técnico por área, valoraciones,
 *      seguimiento escolar, maduración (pico de crecimiento) y lesiones con fases de regreso.
 */

/* ============================================================
 * GENERALES
 * ============================================================ */

/** Número sin ceros de más: 5.30 → "5.3", 12.00 → "12". */
function fmt_num($v, int $dec = 2): string
{
    if ($v === null || $v === '') return '—';
    return rtrim(rtrim(number_format((float)$v, $dec, '.', ''), '0'), '.');
}

/** ¿La escuela usa el módulo de planificación de entrenamientos? */
function entrenamientos_activos(?array $liga): bool
{
    return es_escuela($liga) && modulo_activo($liga, 'esc_entrenamientos');
}

/** ¿La escuela activó las divisiones formativas (modo club) y su plan las incluye? */
function formativas_activas(?array $liga): bool
{
    return es_escuela($liga) && !empty($liga['esc_formativas']) && modulo_activo($liga, 'esc_formativas');
}

function fecha_corta(string $f): string
{
    return $f !== '' ? date('d/m/Y', strtotime($f)) : '';
}

/** "lun 12 oct" */
function dia_corto(string $f): string
{
    $d = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'][(int)date('N', strtotime($f))];
    $m = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][(int)date('n', strtotime($f))];
    return $d . ' ' . (int)date('j', strtotime($f)) . ' ' . $m;
}

/* ============================================================
 * GRÁFICOS (SVG, se ven igual en la pantalla, el celular y el PDF)
 * ============================================================ */

/**
 * Barras verticales. $datos: [[etiqueta, valor, texto_sobre_la_barra?, color?], ...]
 * $op: max, alto, color, vacio (texto si no hay datos), linea (valor de referencia) y linea_txt.
 */
function svg_barras(array $datos, array $op = []): string
{
    if (!$datos) return '<p class="suave">' . e($op['vacio'] ?? 'Todavía no hay datos.') . '</p>';
    $n = count($datos);
    $w = 360; $h = (int)($op['alto'] ?? 170); $pb = 26; $pt = 18; $pl = 6;
    $max = (float)($op['max'] ?? 0);
    foreach ($datos as $d) $max = max($max, (float)$d[1]);
    if ($max <= 0) $max = 1;
    $ancho = ($w - 2 * $pl) / $n;
    $bw = max(4, min(38, $ancho * 0.64));
    $s = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="grafico" role="img">';
    $s .= '<line x1="' . $pl . '" y1="' . ($h - $pb) . '" x2="' . ($w - $pl) . '" y2="' . ($h - $pb) . '" stroke="currentColor" stroke-opacity=".25"/>';
    foreach ($datos as $i => $d) {
        $v = max(0, (float)$d[1]);
        $bh = ($h - $pb - $pt) * $v / $max;
        $x = $pl + $ancho * $i + ($ancho - $bw) / 2;
        $y = $h - $pb - $bh;
        $col = $d[3] ?? ($op['color'] ?? 'var(--acento)');
        $s .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round(max($bh, $v > 0 ? 1.5 : 0), 1) . '" rx="3" fill="' . $col . '"/>';
        $txt = $d[2] ?? fmt_num($v, 1);
        if ($txt !== '' && $v > 0) $s .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 4, 1) . '" font-size="' . ($n > 10 ? 8 : 10) . '" text-anchor="middle" fill="currentColor">' . e((string)$txt) . '</text>';
        $s .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 9) . '" font-size="' . ($n > 10 ? 8 : 9.5) . '" text-anchor="middle" fill="currentColor" fill-opacity=".7">' . e((string)$d[0]) . '</text>';
    }
    if (isset($op['linea'])) {
        $yl = $h - $pb - ($h - $pb - $pt) * (float)$op['linea'] / $max;
        $s .= '<line x1="' . $pl . '" y1="' . round($yl, 1) . '" x2="' . ($w - $pl) . '" y2="' . round($yl, 1) . '" stroke="#e0a100" stroke-dasharray="4 3" stroke-width="1.5"/>';
        if (!empty($op['linea_txt'])) $s .= '<text x="' . ($pl + 2) . '" y="' . round(max(9, $yl - 3), 1) . '" font-size="9" text-anchor="start" fill="#b07d00" stroke="var(--tarjeta, #fff)" stroke-width="3" paint-order="stroke">' . e($op['linea_txt']) . '</text>';
    }
    return $s . '</svg>';
}

/**
 * Líneas por fecha (varias series). $series: [['nombre' =>, 'puntos' => [[fecha, valor], ...], 'color' =>, 'guiones' => bool, 'etiquetas' => bool], ...]
 */
function svg_lineas(array $series, array $op = []): string
{
    $series = array_values(array_filter(array_map(function ($se) {
        $se['puntos'] = array_values(array_filter($se['puntos'], fn($p) => $p[1] !== null && $p[1] !== '' && strtotime((string)$p[0])));
        usort($se['puntos'], fn($a, $b) => strcmp((string)$a[0], (string)$b[0]));
        return $se;
    }, $series), fn($se) => count($se['puntos']) > 0));
    $todos = array_merge(...array_map(fn($se) => $se['puntos'], $series ?: [['puntos' => []]]));
    if (count($todos) < 2) return '<p class="suave">' . e($op['vacio'] ?? 'Hace falta más de un registro para ver la evolución.') . '</p>';
    $ts = array_map(fn($p) => strtotime((string)$p[0]), $todos);
    $vals = array_map(fn($p) => (float)$p[1], $todos);
    [$t0, $t1] = [min($ts), max($ts)];
    if ($t1 === $t0) $t1 = $t0 + 86400;
    [$v0, $v1] = [min($vals), max($vals)];
    $rango = max($v1 - $v0, abs($v1) * 0.05, 0.5);
    $v0 -= $rango * 0.15; $v1 += $rango * 0.15;
    $w = 360; $h = (int)($op['alto'] ?? 160); $pl = 30; $pr = 14; $pt = 16; $pb = 24;
    $x = fn($t) => $pl + ($w - $pl - $pr) * ($t - $t0) / ($t1 - $t0);
    $y = fn($v) => $h - $pb - ($h - $pt - $pb) * ($v - $v0) / ($v1 - $v0);
    $s = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="grafico" role="img">';
    foreach ([$v0 + ($v1 - $v0) * 0.1, ($v0 + $v1) / 2, $v1 - ($v1 - $v0) * 0.1] as $gv) {
        $s .= '<line x1="' . $pl . '" y1="' . round($y($gv), 1) . '" x2="' . ($w - $pr) . '" y2="' . round($y($gv), 1) . '" stroke="currentColor" stroke-opacity=".12"/>';
        $s .= '<text x="' . ($pl - 4) . '" y="' . round($y($gv) + 3, 1) . '" font-size="8.5" text-anchor="end" fill="currentColor" fill-opacity=".6">' . e(fmt_num($gv, abs($v1 - $v0) < 5 ? 1 : 0)) . '</text>';
    }
    $s .= '<text x="' . $pl . '" y="' . ($h - 6) . '" font-size="8.5" fill="currentColor" fill-opacity=".6">' . e(date('d/m/y', $t0)) . '</text>';
    $s .= '<text x="' . ($w - $pr) . '" y="' . ($h - 6) . '" font-size="8.5" text-anchor="end" fill="currentColor" fill-opacity=".6">' . e(date('d/m/y', $t1)) . '</text>';
    foreach (array_reverse($series) as $se) {
        $col = $se['color'] ?? 'var(--acento)';
        $pts = array_map(fn($p) => round($x(strtotime((string)$p[0])), 1) . ',' . round($y((float)$p[1]), 1), $se['puntos']);
        if (count($pts) > 1) $s .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $col . '" stroke-width="' . (!empty($se['guiones']) ? '1.8' : '2.6') . '"' . (!empty($se['guiones']) ? ' stroke-dasharray="5 4"' : '') . '/>';
        foreach ($se['puntos'] as $p) {
            $cx = round($x(strtotime((string)$p[0])), 1); $cy = round($y((float)$p[1]), 1);
            $s .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . (!empty($se['guiones']) ? 2.5 : 3.6) . '" fill="' . $col . '"/>';
            if (!empty($se['etiquetas'])) $s .= '<text x="' . $cx . '" y="' . round($cy - 7, 1) . '" font-size="9.5" font-weight="700" text-anchor="middle" fill="currentColor">' . e(fmt_num($p[1])) . '</text>';
        }
    }
    $s .= '</svg>';
    if (count($series) > 1 || !empty($op['leyenda'])) $s .= leyenda_grafico($series);
    return $s;
}

/** Leyenda HTML de un gráfico: [['nombre' =>, 'color' =>, 'guiones' => bool], ...] */
function leyenda_grafico(array $series): string
{
    $h = '<div class="leyenda">';
    foreach ($series as $se) {
        $h .= '<span><i style="background:' . e($se['color'] ?? 'var(--acento)') . (!empty($se['guiones']) ? ';opacity:.7' : '') . '"></i>' . e($se['nombre'] ?? '') . '</span>';
    }
    return $h . '</div>';
}

/**
 * Radar con varias series: [['valores' => [...], 'nombre' =>, 'color' =>, 'relleno' => 0.25, 'guiones' => bool], ...] (la primera se dibuja arriba).
 */
function svg_radar_series(array $etiquetas, array $series, float $max = 5): string
{
    $n = count($etiquetas);
    if ($n < 3) return '';
    $cx = 160; $cy = 150; $r = 100;
    $pt = function (int $i, float $v) use ($n, $cx, $cy, $r, $max) {
        $ang = -M_PI / 2 + 2 * M_PI * $i / $n;
        $v = max(0, min($max, $v));
        return [$cx + cos($ang) * $r * $v / $max, $cy + sin($ang) * $r * $v / $max];
    };
    $h = '<svg viewBox="-80 0 480 300" class="radar" role="img">';
    for ($k = 1; $k <= 5; $k++) {
        $p = [];
        for ($i = 0; $i < $n; $i++) { [$x, $y] = $pt($i, $max * $k / 5); $p[] = round($x, 1) . ',' . round($y, 1); }
        $h .= '<polygon points="' . implode(' ', $p) . '" fill="none" stroke="currentColor" stroke-opacity=".15"/>';
    }
    for ($i = 0; $i < $n; $i++) {
        [$x, $y] = $pt($i, $max);
        $h .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . round($x, 1) . '" y2="' . round($y, 1) . '" stroke="currentColor" stroke-opacity=".15"/>';
        $ang = -M_PI / 2 + 2 * M_PI * $i / $n;
        [$lx, $ly] = [$cx + cos($ang) * ($r + 16), $cy + sin($ang) * ($r + 16)];
        $anc = abs($lx - $cx) < 8 ? 'middle' : ($lx > $cx ? 'start' : 'end');
        $h .= '<text x="' . round($lx, 1) . '" y="' . round($ly + 4, 1) . '" font-size="10.5" text-anchor="' . $anc . '" fill="currentColor">' . e(mb_substr($etiquetas[$i], 0, 18)) . '</text>';
    }
    foreach (array_reverse($series) as $se) {
        if (!$se['valores']) continue;
        $p = [];
        for ($i = 0; $i < $n; $i++) { [$x, $y] = $pt($i, (float)($se['valores'][$i] ?? 0)); $p[] = round($x, 1) . ',' . round($y, 1); }
        $col = $se['color'] ?? 'var(--acento)';
        $h .= '<polygon points="' . implode(' ', $p) . '" fill="' . $col . '" fill-opacity="' . ($se['relleno'] ?? 0.25) . '" stroke="' . $col . '" stroke-width="' . (!empty($se['guiones']) ? 1.6 : 2.4) . '"' . (!empty($se['guiones']) ? ' stroke-dasharray="5 4"' : '') . '/>';
    }
    $h .= '</svg>';
    $con = array_values(array_filter($series, fn($se) => $se['valores']));
    return $h . (count($con) > 1 ? leyenda_grafico($con) : '');
}

/** Barra de avance (0–100). */
function barra_avance(int $pct, string $color = ''): string
{
    $pct = max(0, min(100, $pct));
    return '<span class="avance"><span style="width:' . $pct . '%' . ($color ? ';background:' . e($color) : '') . '"></span></span>';
}

/* ============================================================
 * A) ENTRENAMIENTOS
 * ============================================================ */

/** (v21) Tipos de ejercicio; el tipo "específico" (antes Arqueros) y el de juego cambian según el deporte ('' = genérico). */
function tipos_ejercicio(string $familia = 'futbol'): array
{
    if ($acad = tipos_ejercicio_academia($familia)) return $acad; // (TuAcademia) baile y música
    $esp = $familia === '' ? ['⭐', 'Específico del deporte'] : nombre_especifico($familia);
    $individual = in_array($familia, ['boxeo', 'artes_marciales', 'natacion', 'atletismo', 'tenis', 'padel', 'tenis_mesa', 'bolos', 'ciclismo', 'squash', 'ajedrez', 'esports', 'pesas', 'triatlon'], true);
    return ['calentamiento' => ['🔥', 'Calentamiento'], 'tecnico' => ['🎯', 'Técnico'], 'tactico' => ['🧠', 'Táctico'], 'fisico' => ['💪', 'Físico / coordinación'],
        'juego' => ['🎮', $individual ? 'Juego / competencia' : 'Juego / partido'], 'arquero' => $esp, 'calma' => ['🧘', 'Vuelta a la calma']];
}

function fases_clase(): array
{
    return ['calentamiento' => 'Calentamiento', 'principal' => 'Parte principal', 'final' => 'Parte final'];
}

function estados_sesion(): array
{
    return ['planificada' => ['📝 Planificada', 'chip-pend'], 'dictada' => ['✓ Dictada', 'chip-ok'], 'suspendida' => ['✗ Suspendida', 'chip-mal']];
}

function chip_sesion(string $estado): string
{
    [$t, $c] = estados_sesion()[$estado] ?? [$estado, ''];
    return '<span class="estado-chip ' . $c . '">' . e($t) . '</span>';
}

function tipos_nota_clase(): array
{
    return ['bien' => ['👍', 'Muy bien'], 'mejorar' => ['🎯', 'A mejorar'], 'nota' => ['📝', 'Nota']];
}

function texto_intensidad(int $i): string
{
    if ($i <= 0) return '';
    return $i . '/10 · ' . ($i <= 3 ? 'suave' : ($i <= 6 ? 'moderada' : ($i <= 8 ? 'alta' : 'muy alta')));
}

/** Biblioteca inicial de ejercicios (la escuela puede cambiarla o agregar los suyos). */
function ejercicios_por_defecto(): array
{
    // [nombre, tipo, objetivo, descripción, minutos, jugadores, materiales, edades]
    return [
        ['Pilla-pilla con balón', 'calentamiento', 'Activación y conducción con cambios de dirección', 'Todos conducen su balón dentro de un cuadrado; 2 "pilladores" sin balón intentan tocar a los demás. Quien es tocado cambia de rol.', 10, 'Todo el grupo', 'Un balón por jugador, 4 conos', 'Sub-6 a Sub-12'],
        ['Movilidad articular y skipping', 'calentamiento', 'Preparar músculos y articulaciones', 'Trote suave, movilidad de tobillo, rodilla y cadera, skipping, talones a glúteos y 3 aceleraciones cortas.', 10, 'Todo el grupo', 'Conos', 'Todas'],
        ['Rondo 4 vs 1', 'tecnico', 'Pase y control orientado bajo presión', 'Cuatro jugadores en un cuadrado de 8 × 8 m mantienen el balón; uno en el centro intenta recuperarlo. Quien pierde el balón entra al medio.', 12, '5 por rondo', 'Un balón y 4 conos por rondo', 'Sub-8 en adelante'],
        ['Pase en triángulo', 'tecnico', 'Pase corto, perfil y desmarque', 'Tres conos en triángulo a 10 m. Pasar y seguir al cono siguiente; variantes a un toque y con la pierna menos hábil.', 12, 'Grupos de 3 a 5', 'Balones y 3 conos por grupo', 'Sub-8 en adelante'],
        ['Conducción en slalom', 'tecnico', 'Conducción con ambos perfiles', 'Conducir entre 6 conos separados 1,5 m usando interior y exterior; regresar a velocidad. Competencia por relevos al final.', 10, 'Filas de 4', 'Balones y 12 conos', 'Sub-6 en adelante'],
        ['Control orientado y remate', 'tecnico', 'Control hacia el espacio y definición', 'El entrenador pasa desde afuera del área; el jugador controla orientado hacia el arco y remata en máximo 2 toques.', 15, 'Filas de 5 + arquero', 'Balones, arco, conos', 'Sub-9 en adelante'],
        ['1 vs 1 con finalización', 'tecnico', 'Regate y definición', 'Atacante y defensor salen al mismo tiempo; el atacante busca superar al defensor y rematar. Si el defensor recupera, contraataca a mini-arcos.', 12, 'Parejas', 'Balones, arco, 2 mini-arcos', 'Sub-8 en adelante'],
        ['Juego de posesión 5 vs 5 + 2 comodines', 'tactico', 'Mantener la posesión y buscar el hombre libre', 'Cancha de 25 × 20 m. El equipo con el balón juega con 2 comodines. 10 pases seguidos = 1 punto.', 15, '12 jugadores', 'Petos de 3 colores, balones', 'Sub-10 en adelante'],
        ['Presión tras pérdida (5 segundos)', 'tactico', 'Reacción inmediata al perder el balón', 'Partido reducido: al perder el balón el equipo tiene 5 segundos para recuperarlo; si lo logra suma un punto extra.', 15, '8 a 12', 'Petos, balones, 4 mini-arcos', 'Sub-11 en adelante'],
        ['Salida desde el arco', 'tactico', 'Construcción desde atrás', 'El arquero inicia con los defensores contra 2 o 3 delanteros que presionan; el objetivo es pasar la línea media con control.', 15, '8 + arquero', 'Petos, balones, arco', 'Sub-11 en adelante'],
        ['Circuito de coordinación', 'fisico', 'Coordinación, ritmo y agilidad', 'Escalera de coordinación, vallas bajas, aros y slalom de conos, terminando con un pase a portería.', 12, 'Filas de 4', 'Escalera, vallas, aros, conos', 'Sub-6 a Sub-13'],
        ['Velocidad de reacción', 'fisico', 'Reaccionar a estímulos visuales y auditivos', 'Parejas enfrentadas; al color o número que dice el entrenador, uno persigue y el otro escapa a su línea (10 m).', 10, 'Parejas', 'Conos de colores', 'Todas'],
        ['Fuerza con el propio cuerpo', 'fisico', 'Estabilidad y prevención de lesiones', 'Circuito de plancha, sentadilla, puente de glúteo, zancadas y equilibrio a una pierna (30 s de trabajo, 30 s de pausa).', 12, 'Todo el grupo', 'Colchonetas', 'Sub-12 en adelante'],
        ['Partido reducido 4 vs 4', 'juego', 'Aplicar lo entrenado en situación de juego', 'Cancha de 30 × 20 m con mini-arcos, sin arquero. Reglas que refuerzan el tema del día (ej: gol vale doble tras 5 pases).', 20, '8 jugadores', 'Petos, balones, mini-arcos', 'Todas'],
        ['Partido 7 vs 7', 'juego', 'Juego libre con posiciones', 'Partido con reglas oficiales de la categoría; el entrenador corrige posicionamiento en las pausas.', 25, '14 jugadores', 'Petos, balones, arcos', 'Sub-8 en adelante'],
        ['Arqueros: blocaje y caídas', 'arquero', 'Técnica de blocaje y caída lateral', 'Blocaje de balones rasos y a media altura, caídas laterales desde rodillas y luego de pie.', 15, 'Arqueros', 'Balones, colchoneta', 'Todas'],
        ['Estiramientos y charla final', 'calma', 'Recuperación y reflexión del entrenamiento', 'Estiramientos suaves y ronda de preguntas: qué aprendimos hoy y qué mejorar.', 8, 'Todo el grupo', '—', 'Todas'],
    ];
}

/** Carga la biblioteca inicial si la escuela todavía no tiene ejercicios. */
function preparar_ejercicios(int $lid): void
{
    // (v19) La biblioteca inicial va por deporte (multideporte.php)
    if ($liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid])) preparar_deportes_escuela($liga);
}

/** Minutos de clase según el horario del grupo (por defecto 60). */
function duracion_grupo(?array $g): int
{
    if (!$g || $g['hora_inicio'] === '' || $g['hora_fin'] === '') return 60;
    $m = fn($h) => (int)substr($h, 0, 2) * 60 + (int)substr($h, 3, 2);
    $d = $m($g['hora_fin']) - $m($g['hora_inicio']);
    return $d > 0 && $d <= 300 ? $d : 60;
}

/** Fechas de entrenamiento de un grupo entre dos días (según sus días de la semana). */
function fechas_entrenamiento(array $g, string $desde, string $hasta): array
{
    $dias = array_filter(array_map('intval', explode(',', (string)$g['dias'])));
    $r = [];
    for ($t = strtotime($desde); $t <= strtotime($hasta); $t += 86400) {
        if (in_array((int)date('N', $t), $dias, true)) $r[] = date('Y-m-d', $t);
    }
    return $r;
}

function ejercicios_sesion(int $sid): array
{
    return filas('SELECT se.*, e.tipo, e.descripcion AS ej_descripcion, e.video, e.imagen FROM esc_sesion_ejercicios se LEFT JOIN esc_ejercicios e ON e.id = se.ejercicio_id WHERE se.sesion_id = ? ORDER BY se.orden, se.id', [$sid]);
}

/** Ciclo de trabajo vigente para un grupo en una fecha (primero el del grupo, luego el de toda la escuela). */
function ciclo_vigente(int $lid, ?int $gid, string $fecha): ?array
{
    return fila('SELECT * FROM esc_ciclos WHERE liga_id = ? AND (grupo_id = ? OR grupo_id IS NULL) AND desde <= ? AND hasta >= ? ORDER BY grupo_id IS NULL, desde DESC LIMIT 1', [$lid, $gid, $fecha, $fecha]);
}

/**
 * Guarda (o actualiza) el plan de una clase sin tocar la asistencia.
 * $d: objetivo, contenido, observaciones, duracion, intensidad, visible_familia, ciclo_id. $ejercicios: [[ejercicio_id, nombre, fase, minutos, notas], ...]
 */
function guardar_plan_sesion(array $g, string $fecha, array $d, array $ejercicios, string $por): int
{
    $ses = fila('SELECT * FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g['id'], $fecha]);
    if ($ses) {
        q("UPDATE esc_sesiones SET objetivo = ?, contenido = ?, observaciones = ?, duracion = ?, intensidad = ?, visible_familia = ?, ciclo_id = ?, registrado_por = ?, estado = CASE WHEN estado = 'suspendida' THEN 'planificada' ELSE estado END WHERE id = ?",
            [$d['objetivo'], $d['contenido'], $d['observaciones'], $d['duracion'], $d['intensidad'], $d['visible_familia'], $d['ciclo_id'], $por, $ses['id']]);
        $sid = (int)$ses['id'];
    } else {
        q("INSERT INTO esc_sesiones (grupo_id, fecha, profesor_id, profesor_asistio, objetivo, contenido, observaciones, duracion, intensidad, visible_familia, ciclo_id, estado, registrado_por)
           VALUES (?,?,?,1,?,?,?,?,?,?,?, 'planificada', ?)", [$g['id'], $fecha, $g['profesor_id'] ?: null, $d['objetivo'], $d['contenido'], $d['observaciones'], $d['duracion'], $d['intensidad'], $d['visible_familia'], $d['ciclo_id'], $por]);
        $sid = (int)db()->lastInsertId();
    }
    q('DELETE FROM esc_sesion_ejercicios WHERE sesion_id = ?', [$sid]);
    foreach (array_values($ejercicios) as $i => [$eid, $nom, $fase, $min, $notas]) {
        q('INSERT INTO esc_sesion_ejercicios (sesion_id, ejercicio_id, nombre, fase, minutos, notas, orden) VALUES (?,?,?,?,?,?,?)', [$sid, $eid ?: null, $nom, $fase, $min, $notas, $i]);
    }
    return $sid;
}

/** Lee del formulario las filas de ejercicios de una clase. */
function leer_ejercicios_post(int $lid): array
{
    $r = [];
    $ids = (array)($_POST['ej_id'] ?? []);
    foreach ($ids as $i => $eid) {
        $eid = (int)$eid;
        $nom = mb_substr(trim((string)($_POST['ej_nombre'][$i] ?? '')), 0, 120);
        $ej = $eid ? fila('SELECT * FROM esc_ejercicios WHERE id = ? AND liga_id = ?', [$eid, $lid]) : null;
        if (!$ej && $nom === '') continue;
        $fase = (string)($_POST['ej_fase'][$i] ?? 'principal');
        $r[] = [$ej ? (int)$ej['id'] : null, $ej ? $ej['nombre'] : $nom, array_key_exists($fase, fases_clase()) ? $fase : 'principal',
            entero($_POST['ej_min'][$i] ?? 0, 0, 240), mb_substr(trim((string)($_POST['ej_notas'][$i] ?? '')), 0, 200)];
    }
    return $r;
}

/** Copia el plan de una clase a otra fecha u otro grupo (no copia asistencia, notas ni fotos). */
function copiar_plan_sesion(array $ses, array $g_dest, string $fecha, string $por): int
{
    $ej = array_map(fn($x) => [$x['ejercicio_id'], $x['nombre'], $x['fase'], (int)$x['minutos'], $x['notas']], ejercicios_sesion((int)$ses['id']));
    return guardar_plan_sesion($g_dest, $fecha, ['objetivo' => $ses['objetivo'], 'contenido' => $ses['contenido'], 'observaciones' => '', 'duracion' => (int)$ses['duracion'],
        'intensidad' => (int)$ses['intensidad'], 'visible_familia' => (int)$ses['visible_familia'], 'ciclo_id' => $ses['ciclo_id']], $ej, $por);
}

function url_foto_clase(int $id): string
{
    return 'foto.php?c=' . $id;
}

/** Minutos de una clase: los que dijo el profesor, o el horario del grupo. */
function minutos_clase(array $s): int
{
    if ((int)($s['duracion'] ?? 0) > 0) return (int)$s['duracion'];
    return duracion_grupo(['hora_inicio' => $s['hora_inicio'] ?? '', 'hora_fin' => $s['hora_fin'] ?? '']);
}

/** Diario de entrenamientos de un alumno: clases en las que estuvo en la lista (más nuevas primero). */
function diario_alumno(int $aid, int $limite = 10, int $desde = 0, bool $familia = false): array
{
    $ses = filas("SELECT s.*, x.estado AS asis, g.nombre AS grupo, g.hora_inicio, g.hora_fin, p.nombre AS profesor
                  FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id LEFT JOIN esc_profesores p ON p.id = s.profesor_id
                  WHERE x.alumno_id = ? AND s.estado = 'dictada'" . ($familia ? ' AND s.visible_familia = 1' : '') . " ORDER BY s.fecha DESC LIMIT " . (int)$limite . ' OFFSET ' . (int)$desde, [$aid]);
    foreach ($ses as &$s) {
        $s['ejercicios'] = ejercicios_sesion((int)$s['id']);
        $s['fotos'] = filas('SELECT * FROM esc_sesion_fotos WHERE sesion_id = ?' . ($familia ? ' AND publica = 1' : '') . ' ORDER BY id', [$s['id']]);
        $s['nota'] = fila('SELECT * FROM esc_notas_clase WHERE sesion_id = ? AND alumno_id = ?' . ($familia ? ' AND visible_familia = 1' : ''), [$s['id'], $aid]);
    }
    return $ses;
}

/** Próximas clases planificadas del grupo del alumno. */
function proximas_clases(?int $gid, int $n = 3, bool $familia = true): array
{
    if (!$gid) return [];
    return filas("SELECT * FROM esc_sesiones WHERE grupo_id = ? AND fecha >= ? AND estado = 'planificada'" . ($familia ? ' AND visible_familia = 1' : '') . " ORDER BY fecha LIMIT " . (int)$n, [$gid, date('Y-m-d')]);
}

/** HTML del diario (lo usan la ficha del alumno y el portal de la familia). */
function html_diario(array $items, bool $familia): string
{
    if (!$items) return '<p class="suave">Todavía no hay clases registradas.</p>';
    $col = ['P' => ['s-verde', 'Vino'], 'T' => ['s-amarillo', 'Llegó tarde'], 'J' => ['s-gris', 'Faltó (justificado)'], 'A' => ['s-rojo', 'Faltó']];
    $h = '<div class="diario">';
    foreach ($items as $s) {
        [$c, $t] = $col[$s['asis']] ?? ['s-gris', $s['asis']];
        $h .= '<div class="diario-dia"><div class="diario-fecha"><b>' . e(dia_corto($s['fecha'])) . '</b><span class="semaforo ' . $c . '">' . e($t) . '</span></div><div class="diario-cuerpo">';
        $h .= '<div><b>' . e($s['objetivo'] !== '' ? $s['objetivo'] : 'Entrenamiento') . '</b> <small class="suave">· ' . minutos_clase($s) . ' min' . ((int)$s['intensidad'] ? ' · intensidad ' . e(texto_intensidad((int)$s['intensidad'])) : '') . ($s['profesor'] ? ' · ' . e($s['profesor']) : '') . '</small></div>';
        if ($s['ejercicios']) {
            $h .= '<ul class="diario-ej">';
            foreach ($s['ejercicios'] as $x) $h .= '<li>' . e((tipos_ejercicio()[$x['tipo'] ?? '']['0'] ?? '•') . ' ' . $x['nombre']) . ($x['minutos'] ? ' <small class="suave">' . (int)$x['minutos'] . ' min</small>' : '') . '</li>';
            $h .= '</ul>';
        } elseif ($s['contenido'] !== '') {
            $h .= '<p class="suave" style="margin:.2rem 0">' . e(mb_substr($s['contenido'], 0, 260)) . '</p>';
        }
        if ($s['nota']) {
            [$ic, $tn] = tipos_nota_clase()[$s['nota']['tipo']] ?? ['📝', 'Nota'];
            $h .= '<div class="nota-profe">' . $ic . ' <b>' . e($tn) . ':</b> ' . e($s['nota']['texto']) . '</div>';
        }
        if ($s['fotos']) {
            $h .= '<div class="fotos-clase">';
            foreach ($s['fotos'] as $f) $h .= '<a href="' . e(url_foto_clase((int)$f['id'])) . '" target="_blank"><img src="' . e(url_foto_clase((int)$f['id'])) . '" alt="Foto de la clase" loading="lazy"></a>';
            $h .= '</div>';
        }
        $h .= '</div></div>';
    }
    return $h . '</div>';
}

/**
 * Editor de los ejercicios de una clase (lo usan la planificación y la asistencia).
 * $filas: ejercicios_sesion() o [] (se muestra una fila vacía por fase).
 */
function html_editor_ejercicios(int $lid, array $filas, ?string $deporte = null): string
{
    preparar_ejercicios($lid);
    // (v21) Solo los ejercicios del deporte del grupo y los generales
    $bib = filas('SELECT id, nombre, tipo, duracion FROM esc_ejercicios WHERE liga_id = ? AND activo = 1' . ($deporte !== null ? ' AND ' . sql_deporte([$deporte]) : '') . ' ORDER BY tipo, nombre', [$lid]);
    $opc = '<option value="">— otro (escríbalo) —</option>';
    foreach (tipos_ejercicio($deporte !== null ? familia_deporte($deporte) : 'futbol') as $k => [$ic, $tn]) {
        $de = array_filter($bib, fn($x) => $x['tipo'] === $k);
        if (!$de) continue;
        $opc .= '<optgroup label="' . e($ic . ' ' . $tn) . '">';
        foreach ($de as $x) $opc .= '<option value="' . (int)$x['id'] . '" data-min="' . (int)$x['duracion'] . '">' . e($x['nombre']) . '</option>';
        $opc .= '</optgroup>';
    }
    $fila = function (?array $x, string $fase = 'principal') use ($opc): string {
        $sel = $x && $x['ejercicio_id'] ? str_replace('value="' . (int)$x['ejercicio_id'] . '"', 'value="' . (int)$x['ejercicio_id'] . '" selected', $opc) : $opc;
        $fases = '';
        foreach (fases_clase() as $k => $t) $fases .= '<option value="' . $k . '"' . (($x['fase'] ?? $fase) === $k ? ' selected' : '') . '>' . e($t) . '</option>';
        $libre = $x && !$x['ejercicio_id'];
        return '<div class="ej-fila"><div><select name="ej_id[]" onchange="ejCambio(this)">' . $sel . '</select>'
            . '<input type="text" name="ej_nombre[]" maxlength="120" placeholder="Nombre del ejercicio" value="' . e($libre ? $x['nombre'] : '') . '" style="margin-top:4px;' . ($x && !$libre ? 'display:none' : '') . '"></div>'
            . '<select name="ej_fase[]">' . $fases . '</select>'
            . '<input type="number" name="ej_min[]" min="0" max="240" placeholder="min" value="' . ($x && (int)$x['minutos'] ? (int)$x['minutos'] : '') . '" oninput="ejTotal()">'
            . '<input type="text" name="ej_notas[]" maxlength="200" placeholder="Variante o indicación (opcional)" value="' . e($x['notas'] ?? '') . '">'
            . '<button type="button" class="btn btn-sec btn-chico" title="Quitar" onclick="this.closest(\'.ej-fila\').remove(); ejTotal()">✕</button></div>';
    };
    $h = '<div id="ej-lista">';
    if ($filas) foreach ($filas as $x) $h .= $fila($x);
    else foreach (['calentamiento', 'principal', 'principal', 'final'] as $f) $h .= $fila(null, $f);
    $h .= '</div><template id="ej-plantilla">' . $fila(null) . '</template>'
        . '<div class="acciones" style="margin-top:6px"><button type="button" class="btn btn-sec btn-chico" onclick="ejAgregar()">+ Agregar ejercicio</button>'
        . '<span class="suave">Total: <b id="ej-total">0</b> min</span> <a class="suave" href="admin.php?p=planificacion&amp;ver=ejercicios" target="_blank">Ver la biblioteca de ejercicios</a></div>'
        . '<script>
function ejCambio(s){var f=s.closest(".ej-fila"),t=s.parentNode.querySelector("input"),m=f.querySelector("[name=\'ej_min[]\']"),o=s.options[s.selectedIndex];t.style.display=s.value?"none":"";if(s.value&&!m.value&&o.dataset.min)m.value=o.dataset.min;ejTotal();}
function ejAgregar(){var t=document.getElementById("ej-plantilla").content.cloneNode(true);document.getElementById("ej-lista").appendChild(t);}
function ejTotal(){var n=0;document.querySelectorAll("#ej-lista [name=\'ej_min[]\']").forEach(function(i){n+=parseInt(i.value||0,10);});var e=document.getElementById("ej-total");if(e)e.textContent=n;}
document.addEventListener("DOMContentLoaded",ejTotal);
</script>';
    return $h;
}

/* ============================================================
 * B) DESARROLLO DEL ALUMNO
 * ============================================================ */

/** Asistencia mes a mes: [[AAAA-MM, %, vino, total], ...] (solo meses con clases). */
function asistencia_mensual(int $aid, int $meses = 6): array
{
    $desde = date('Y-m-01', strtotime('-' . ($meses - 1) . ' months'));
    $r = filas("SELECT substr(s.fecha, 1, 7) AS mes, COUNT(*) AS t, SUM(CASE WHEN x.estado IN ('P','T') THEN 1 ELSE 0 END) AS v
                FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE x.alumno_id = ? AND s.fecha >= ? GROUP BY mes ORDER BY mes", [$aid, $desde]);
    return array_map(fn($x) => [$x['mes'], (int)round(100 * (int)$x['v'] / max(1, (int)$x['t'])), (int)$x['v'], (int)$x['t']], $r);
}

/** Minutos entrenados por semana (clases a las que vino): [[lunes de la semana, minutos], ...] */
function minutos_semanales(int $aid, int $semanas = 10): array
{
    $lunes = date('Y-m-d', strtotime('monday this week -' . ($semanas - 1) . ' weeks'));
    $r = [];
    for ($i = 0; $i < $semanas; $i++) $r[date('Y-m-d', strtotime($lunes . " +$i weeks"))] = 0;
    foreach (filas("SELECT s.fecha, s.duracion, g.hora_inicio, g.hora_fin FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id
                    WHERE x.alumno_id = ? AND x.estado IN ('P','T') AND s.fecha >= ?", [$aid, $lunes]) as $s) {
        $k = date('Y-m-d', strtotime('monday this week', strtotime($s['fecha'])));
        if (isset($r[$k])) $r[$k] += minutos_clase($s);
    }
    return array_map(fn($k, $v) => [$k, $v], array_keys($r), $r);
}

/** Categoría Sub-N de un alumno en la fecha de un resultado. */
function sub_en(string $fecha_nac, string $fecha): string
{
    return sub_de($fecha_nac, (int)substr($fecha, 0, 4));
}

/** Mejor valor de cada alumno por categoría en un test (para comparar y para el semáforo automático): [sub => [valores]] */
function distribucion_test(array $t): array
{
    static $cache = [];
    $k = (int)$t['id'];
    if (isset($cache[$k])) return $cache[$k];
    $mejor = [];
    foreach (filas("SELECT r.alumno_id, r.valor, r.fecha, a.fecha_nac FROM esc_resultados r JOIN esc_alumnos a ON a.id = r.alumno_id WHERE r.test_id = ? AND a.fecha_nac <> '' AND a.estado <> 'pendiente'", [$k]) as $r) {
        $sub = sub_en($r['fecha_nac'], $r['fecha']);
        $v = (float)$r['valor'];
        $ya = $mejor[$sub][$r['alumno_id']] ?? null;
        if ($ya === null || ($t['mejor'] === 'menor' ? $v < $ya : $v > $ya)) $mejor[$sub][$r['alumno_id']] = $v;
    }
    return $cache[$k] = array_map('array_values', $mejor);
}

/** Percentil (0–100, más alto = mejor) de un valor dentro de una lista. */
function percentil_en(array $valores, float $v, string $mejor): ?int
{
    $n = count($valores);
    if ($n < 2) return null;
    $peor = 0; $igual = 0;
    foreach ($valores as $x) {
        if (abs($x - $v) < 1e-9) $igual++;
        elseif ($mejor === 'menor' ? $x > $v : $x < $v) $peor++;
    }
    return (int)round(100 * ($peor + 0.5 * $igual) / $n);
}

/** Promedio de un test en una categoría. */
function promedio_categoria(array $t, string $sub): ?float
{
    $v = distribucion_test($t)[$sub] ?? [];
    return $v ? array_sum($v) / count($v) : null;
}

/**
 * Semáforo de un resultado según la edad: primero los valores que definió la escuela (Evaluaciones → Tests y habilidades),
 * si no, se compara con los alumnos de su misma categoría en la escuela (tercio superior = verde).
 * Devuelve ['color' => verde|amarillo|rojo, 'percentil' => ?int, 'fuente' => escuela|categoria] o null.
 */
function semaforo_test(array $t, float $v, array $a, string $fecha): ?array
{
    static $baremos = [];
    $lid = (int)$t['liga_id'];
    $baremos[$lid] ??= filas('SELECT * FROM esc_baremos WHERE liga_id = ?', [$lid]);
    $edad = $a['fecha_nac'] !== '' ? edad_en_fecha($a['fecha_nac'], $fecha) : null;
    $sub = $a['fecha_nac'] !== '' ? sub_en($a['fecha_nac'], $fecha) : '';
    $dist = distribucion_test($t)[$sub] ?? [];
    $pct = count($dist) >= 3 ? percentil_en($dist, $v, $t['mejor']) : null;
    if ($edad !== null) {
        foreach ($baremos[$lid] as $b) {
            if ((int)$b['test_id'] !== (int)$t['id'] || $edad < (int)$b['edad_desde'] || $edad > (int)$b['edad_hasta']) continue;
            if ($b['genero'] !== '' && $b['genero'] !== ($a['genero'] ?? '')) continue;
            $bien = fn($umbral) => $t['mejor'] === 'menor' ? $v <= (float)$umbral : $v >= (float)$umbral;
            return ['color' => $bien($b['verde']) ? 'verde' : ($bien($b['amarillo']) ? 'amarillo' : 'rojo'), 'percentil' => $pct, 'fuente' => 'escuela'];
        }
    }
    if (count($dist) < 5 || $pct === null) return null;
    return ['color' => $pct >= 67 ? 'verde' : ($pct >= 33 ? 'amarillo' : 'rojo'), 'percentil' => $pct, 'fuente' => 'categoria'];
}

function chip_semaforo(?array $s, bool $con_texto = true): string
{
    if (!$s) return '';
    $txt = ['verde' => 'Muy bien', 'amarillo' => 'En el promedio', 'rojo' => 'Por mejorar'][$s['color']];
    $cls = ['verde' => 's-verde', 'amarillo' => 's-amarillo', 'rojo' => 's-rojo'][$s['color']];
    $tit = $s['fuente'] === 'escuela' ? 'Según los valores de referencia de la escuela para su edad' : 'Comparado con los alumnos de su misma categoría (percentil ' . (int)$s['percentil'] . ')';
    return '<span class="semaforo ' . $cls . '" title="' . e($tit) . '">● ' . ($con_texto ? e($txt) : '') . '</span>';
}

/** % de mejora entre dos valores (positivo = mejoró, según el sentido del test). */
function mejora_pct(float $antes, float $ahora, string $mejor): ?float
{
    if (abs($antes) < 1e-9) return null;
    $p = ($ahora - $antes) / abs($antes) * 100;
    return round($mejor === 'menor' ? -$p : $p, 1);
}

/** Resumen de los tests de un alumno: una fila por test con resultados. */
function resumen_tests_alumno(array $a, int $lid, string $hasta = ''): array
{
    $r = [];
    foreach (filas('SELECT * FROM esc_tests WHERE liga_id = ? AND activo = 1 ORDER BY orden, nombre', [$lid]) as $t) {
        $res = filas('SELECT * FROM esc_resultados WHERE alumno_id = ? AND test_id = ?' . ($hasta ? ' AND fecha <= ?' : '') . ' ORDER BY fecha', $hasta ? [$a['id'], $t['id'], $hasta] : [$a['id'], $t['id']]);
        if (!$res) continue;
        $vals = array_map(fn($x) => (float)$x['valor'], $res);
        $ult = end($res);
        $ant = count($res) >= 2 ? $res[count($res) - 2] : null;
        $r[] = [
            'test' => $t, 'res' => $res, 'primero' => $res[0], 'anterior' => $ant, 'ultimo' => $ult,
            'mejor' => $t['mejor'] === 'menor' ? min($vals) : max($vals),
            'mejora_total' => count($res) >= 2 ? mejora_pct((float)$res[0]['valor'], (float)$ult['valor'], $t['mejor']) : null,
            'mejora_ultima' => $ant ? mejora_pct((float)$ant['valor'], (float)$ult['valor'], $t['mejor']) : null,
            'semaforo' => semaforo_test($t, (float)$ult['valor'], $a, $ult['fecha']),
            'promedio_cat' => $a['fecha_nac'] !== '' ? promedio_categoria($t, sub_en($a['fecha_nac'], $ult['fecha'])) : null,
        ];
    }
    return $r;
}

function flecha_mejora(?float $p): string
{
    if ($p === null) return '';
    if (abs($p) < 0.05) return '<span class="suave">= igual</span>';
    return $p > 0 ? '<span class="sube">▲ ' . fmt_num($p, 1) . '%</span>' : '<span class="baja">▼ ' . fmt_num(abs($p), 1) . '%</span>';
}

/** Promedio del grupo en cada habilidad técnica (última evaluación de cada alumno activo). */
function promedio_tecnica_grupo(?int $gid, array $habs): ?array
{
    if (!$gid) return null;
    $suma = []; $n = [];
    $ids_h = array_map(fn($h) => (int)$h['id'], $habs);
    foreach (alumnos_grupo($gid) as $a) {
        $e = ultima_eval_tecnica((int)$a['id'], $ids_h);
        if (!$e) continue;
        foreach ($habs as $h) if (isset($e['valores'][$h['id']])) { $suma[$h['id']] = ($suma[$h['id']] ?? 0) + (float)$e['valores'][$h['id']]; $n[$h['id']] = ($n[$h['id']] ?? 0) + 1; }
    }
    if (!$n) return null;
    return array_map(fn($h) => isset($n[$h['id']]) ? round($suma[$h['id']] / $n[$h['id']], 2) : 0, $habs);
}

/** Récords de la escuela por test y categoría (Sub-N en la fecha del resultado). */
function records_escuela(int $lid): array
{
    static $cache = [];
    if (isset($cache[$lid])) return $cache[$lid];
    $r = [];
    foreach (filas('SELECT * FROM esc_tests WHERE liga_id = ? AND activo = 1 ORDER BY orden, nombre', [$lid]) as $t) {
        $cats = []; $abs = null;
        foreach (filas("SELECT r.valor, r.fecha, a.id AS aid, a.nombres, a.apellidos, a.fecha_nac, a.estado FROM esc_resultados r JOIN esc_alumnos a ON a.id = r.alumno_id
                        WHERE r.test_id = ? AND a.fecha_nac <> '' AND a.estado <> 'pendiente'", [$t['id']]) as $x) {
            $sub = sub_en($x['fecha_nac'], $x['fecha']);
            $mej = fn($y) => $y === null || ($t['mejor'] === 'menor' ? (float)$x['valor'] < (float)$y['valor'] : (float)$x['valor'] > (float)$y['valor']);
            if ($mej($cats[$sub] ?? null)) $cats[$sub] = $x;
            if ($mej($abs)) $abs = $x;
        }
        uksort($cats, fn($a, $b) => (int)substr($a, 4) <=> (int)substr($b, 4));
        if ($cats) $r[(int)$t['id']] = ['test' => $t, 'cats' => $cats, 'abs' => $abs];
    }
    return $cache[$lid] = $r;
}

/**
 * Insignias que ganó el alumno (se calculan solas): [[ícono, nombre, detalle], ...]
 */
function insignias_alumno(array $a, array $liga): array
{
    $aid = (int)$a['id'];
    $r = [];
    // Asistencia perfecta el mes pasado (con al menos 4 clases)
    $mp = date('Y-m', strtotime('first day of last month'));
    $x = fila("SELECT COUNT(*) AS t, SUM(CASE WHEN x.estado IN ('P','T') THEN 1 ELSE 0 END) AS v FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE x.alumno_id = ? AND substr(s.fecha, 1, 7) = ?", [$aid, $mp]);
    if ((int)$x['t'] >= 4 && (int)$x['v'] === (int)$x['t']) $r[] = ['🎯', 'Asistencia perfecta', 'No faltó ninguna clase en ' . nombre_mes($mp) . '.'];
    // Constancia: 90% o más en los últimos 90 días
    $as = asistencia_alumno($aid);
    if ($as['total'] >= 12 && $as['pct'] >= 90) $r[] = ['🔥', 'Constancia', 'Vino al ' . $as['pct'] . '% de las clases de los últimos 3 meses.'];
    // Récord personal reciente y récord de la escuela
    $tests = resumen_tests_alumno($a, (int)$liga['id']);
    $rp = [];
    foreach ($tests as $t) {
        if (count($t['res']) >= 2 && (float)$t['ultimo']['valor'] === (float)$t['mejor'] && $t['ultimo']['fecha'] >= date('Y-m-d', strtotime('-60 days')) && ($t['mejora_ultima'] ?? 0) > 0) $rp[] = $t['test']['nombre'];
    }
    if ($rp) $r[] = ['📈', 'Récord personal', 'Superó su mejor marca en: ' . implode(', ', array_slice($rp, 0, 3)) . (count($rp) > 3 ? ' y ' . (count($rp) - 3) . ' más' : '') . '.'];
    $re = [];
    foreach (records_escuela((int)$liga['id']) as $rec) foreach ($rec['cats'] as $sub => $x) if ((int)$x['aid'] === $aid) $re[] = $rec['test']['nombre'] . ' (' . $sub . ')';
    if ($re) $r[] = ['🏆', 'Récord de la escuela', implode(', ', array_slice($re, 0, 3)) . (count($re) > 3 ? ' y ' . (count($re) - 3) . ' más' : '') . '.'];
    // Mayor mejora del grupo en la última jornada de evaluación
    if ($a['grupo_id'] && ($mm = mayor_mejora_grupo((int)$a['grupo_id'], (int)$liga['id'])) && (int)$mm['aid'] === $aid) $r[] = ['🚀', 'Mayor mejora del grupo', 'Mejoró en promedio ' . fmt_num($mm['pct'], 1) . '% en ' . $mm['jornada'] . '.'];
    // Técnica en alza
    $ev = filas('SELECT valores FROM esc_eval_tecnica WHERE alumno_id = ? ORDER BY fecha DESC, id DESC LIMIT 2', [$aid]);
    if (count($ev) === 2) {
        $pr = fn($j) => ($v = json_decode($j, true) ?: []) ? array_sum($v) / count($v) : 0;
        if ($pr($ev[0]['valores']) - $pr($ev[1]['valores']) >= 0.4) $r[] = ['⭐', 'Técnica en alza', 'Subió su evaluación técnica de ' . fmt_num($pr($ev[1]['valores']), 1) . ' a ' . fmt_num($pr($ev[0]['valores']), 1) . ' (de 5).'];
    }
    // Meta lograda
    if ($m = fila("SELECT texto FROM esc_metas WHERE alumno_id = ? AND estado = 'lograda' AND actualizado >= ? ORDER BY actualizado DESC LIMIT 1", [$aid, date('Y-m-d', strtotime('-150 days'))])) $r[] = ['🎖', 'Meta cumplida', $m['texto']];
    // Partidos (divisiones formativas)
    if (formativas_activas($liga)) {
        $g = (int)valor('SELECT COALESCE(SUM(pj.goles), 0) FROM esc_partido_jugadores pj JOIN esc_partidos p ON p.id = pj.partido_id WHERE pj.alumno_id = ? AND p.fecha >= ?', [$aid, date('Y-m-d', strtotime('-120 days'))]);
        if ($g >= 3) {
            // (v21) En básquet/vóley el campo "goles" guarda puntos
            $fam = familia_deporte(deporte_grupo($a['grupo_id'] ? fila('SELECT * FROM esc_grupos WHERE id = ?', [(int)$a['grupo_id']]) : null, $liga));
            if ($fam === 'futbol') $r[] = ['⚽', 'Goleador', $g . ' goles en los partidos de los últimos 4 meses.'];
            elseif (in_array($fam, ['basquet', 'voley'], true) && $g >= 20) $r[] = [$fam === 'basquet' ? '🏀' : '🏐', 'Anotador', $g . ' puntos en los partidos de los últimos 4 meses.'];
        }
    }
    // (v21) Combates, cinturones y medallas (boxeo, artes marciales, natación, atletismo, tenis)
    $desde = date('Y-m-d', strtotime('-120 days'));
    $gan = (int)valor("SELECT COUNT(*) FROM esc_competencias WHERE alumno_id = ? AND tipo = 'combate' AND resultado = 'gano' AND fecha >= ?", [$aid, $desde]);
    if ($gan) $r[] = ['🥊', $gan === 1 ? 'Ganó su combate' : 'Ganó ' . $gan . ' combates', 'En los últimos 4 meses.'];
    $rec = record_alumno($aid);
    if ($rec['n'] >= 3 && $rec['p'] === 0) $r[] = ['🛡', 'Invicto', 'Récord ' . $rec['g'] . '-' . $rec['p'] . '-' . $rec['e'] . ' sin derrotas.'];
    $med = filas("SELECT medalla, prueba FROM esc_competencias WHERE alumno_id = ? AND tipo = 'prueba' AND medalla <> '' AND fecha >= ? ORDER BY fecha DESC", [$aid, $desde]);
    if ($med) $r[] = [icono_medalla($med[0]['medalla']), 'Medallista', count($med) . ' medalla(s) en los últimos 4 meses (' . implode(', ', array_slice(array_unique(array_column($med, 'prueba')), 0, 3)) . ').'];
    if (($gr = grado_actual($aid)) && $gr['fecha'] >= $desde) { $vn = vocabulario_nivel($gr['disciplina'] !== '' ? $gr['disciplina'] : 'artes_marciales'); $r[] = [$vn['ico'], 'Nuevo ' . mb_strtolower($vn['nivel']), 'Subió a ' . $gr['grado'] . '.']; }
    return $r;
}

function html_insignias(array $ins): string
{
    if (!$ins) return '';
    $h = '<div class="insignias">';
    foreach ($ins as [$ic, $n, $d]) $h .= '<div class="insignia" title="' . e($d) . '"><span>' . $ic . '</span><div><b>' . e($n) . '</b><small>' . e($d) . '</small></div></div>';
    return $h . '</div>';
}

/** Alumno que más mejoró en su grupo en la última jornada de evaluación cerrada (promedio de % de mejora en los tests). */
function mayor_mejora_grupo(int $gid, int $lid): ?array
{
    static $cache = [];
    if (array_key_exists($gid, $cache)) return $cache[$gid];
    $j = fila("SELECT * FROM esc_jornadas WHERE liga_id = ? AND estado = 'cerrada' AND (grupos = '' OR ',' || grupos || ',' LIKE ?) ORDER BY fecha DESC LIMIT 1", [$lid, '%,' . $gid . ',%']);
    if (!$j) return $cache[$gid] = null;
    $best = null;
    foreach (alumnos_grupo($gid) as $a) {
        $ps = [];
        foreach (filas('SELECT r.valor, r.test_id, t.mejor FROM esc_resultados r JOIN esc_tests t ON t.id = r.test_id WHERE r.alumno_id = ? AND r.jornada_id = ?', [$a['id'], $j['id']]) as $x) {
            $ant = valor('SELECT valor FROM esc_resultados WHERE alumno_id = ? AND test_id = ? AND fecha < ? ORDER BY fecha DESC LIMIT 1', [$a['id'], $x['test_id'], $j['fecha']]);
            if ($ant !== null && ($p = mejora_pct((float)$ant, (float)$x['valor'], $x['mejor'])) !== null) $ps[] = max(-50, min(50, $p));
        }
        if (count($ps) >= 2) {
            $prom = array_sum($ps) / count($ps);
            if ($prom > 0 && (!$best || $prom > $best['pct'])) $best = ['aid' => (int)$a['id'], 'pct' => $prom, 'jornada' => $j['nombre']];
        }
    }
    return $cache[$gid] = $best;
}

function areas_meta(): array
{
    return ['tecnica' => ['🎯', 'Técnica'], 'fisica' => ['💪', 'Física'], 'tactica' => ['🧠', 'Táctica'], 'actitud' => ['🤝', 'Actitud y valores'], 'escolar' => ['🎒', 'Escolar'], 'salud' => ['🥗', 'Salud y hábitos']];
}

function estados_meta(): array
{
    return ['en_curso' => ['En curso', 'chip-pend'], 'lograda' => ['🎖 Lograda', 'chip-ok'], 'pausada' => ['En pausa', '']];
}

/** Lista de metas con barra de avance. */
function html_metas(array $metas, bool $editable, string $accion_url = ''): string
{
    if (!$metas) return '<p class="suave">Todavía no hay metas.</p>';
    $h = '';
    foreach ($metas as $m) {
        [$ic, $area] = areas_meta()[$m['area']] ?? ['🎯', $m['area']];
        [$et, $ec] = estados_meta()[$m['estado']] ?? [$m['estado'], ''];
        $h .= '<div class="meta-fila"><div class="meta-tit">' . $ic . ' <b>' . e($m['texto']) . '</b> <span class="estado-chip ' . $ec . '">' . e($et) . '</span>'
            . ($m['fecha_objetivo'] ? ' <small class="suave">· para el ' . e(fecha_corta($m['fecha_objetivo'])) . '</small>' : '') . '</div>'
            . '<div class="meta-avance">' . barra_avance((int)$m['avance'], $m['estado'] === 'lograda' ? '#1a9e5c' : '') . ' <b>' . (int)$m['avance'] . '%</b> <small class="suave">' . e($area) . '</small></div>'
            . ($m['comentario'] !== '' ? '<small>' . e($m['comentario']) . '</small>' : '');
        if ($editable && $accion_url !== '') {
            $h .= '<details class="meta-editar"><summary>Actualizar</summary><form method="post" action="' . e($accion_url) . '" class="acciones" style="align-items:flex-end">' . csrf_campo()
                . '<input type="hidden" name="accion" value="meta_avance"><input type="hidden" name="meta_id" value="' . (int)$m['id'] . '">'
                . '<div><label>Avance</label><input type="range" name="avance" min="0" max="100" step="10" value="' . (int)$m['avance'] . '" oninput="this.nextElementSibling.textContent = this.value + \'%\'"><output>' . (int)$m['avance'] . '%</output></div>'
                . '<div><label>Estado</label><select name="estado">';
            foreach (estados_meta() as $k => [$t]) $h .= '<option value="' . $k . '"' . ($m['estado'] === $k ? ' selected' : '') . '>' . e($t) . '</option>';
            $h .= '</select></div><div style="flex:1;min-width:180px"><label>Comentario</label><input type="text" name="comentario" maxlength="300" value="' . e($m['comentario']) . '"></div>'
                . '<button class="btn btn-sec btn-chico">Guardar</button><button class="btn btn-sec btn-chico" name="borrar" value="1" onclick="return confirm(\'¿Borrar esta meta?\')">Borrar</button></form></details>';
        }
        $h .= '</div>';
    }
    return $h;
}

/**
 * Panel de desarrollo del alumno (ficha y portal de los padres): cifras, insignias y gráficos.
 */
function panel_desarrollo(array $a, array $liga, bool $familia = false): string
{
    $aid = (int)$a['id'];
    $lid = (int)$liga['id'];
    $as = asistencia_alumno($aid);
    $anio = date('Y-01-01');
    $clases_anio = (int)valor("SELECT COUNT(*) FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE x.alumno_id = ? AND x.estado IN ('P','T') AND s.fecha >= ?", [$aid, $anio]);
    $min_anio = 0;
    foreach (filas("SELECT s.duracion, g.hora_inicio, g.hora_fin FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id WHERE x.alumno_id = ? AND x.estado IN ('P','T') AND s.fecha >= ?", [$aid, $anio]) as $s) $min_anio += minutos_clase($s);
    $tests = resumen_tests_alumno($a, $lid);
    $mejoro = count(array_filter($tests, fn($t) => ($t['mejora_total'] ?? 0) > 0));
    $con_hist = count(array_filter($tests, fn($t) => $t['mejora_total'] !== null));
    $ins = insignias_alumno($a, $liga);
    ob_start(); ?>
<div class="cifras">
  <div class="cifra"><b><?= $as['pct'] !== null ? $as['pct'] . '%' : '—' ?></b>Asistencia (3 meses)</div>
  <div class="cifra"><b><?= $clases_anio ?></b>Clases este año</div>
  <div class="cifra"><b><?= fmt_num($min_anio / 60, 1) ?> h</b>Entrenadas este año</div>
  <div class="cifra"><b><?= $con_hist ? $mejoro . ' de ' . $con_hist : '—' ?></b>Tests en los que mejoró</div>
  <div class="cifra"><b><?= count($ins) ?></b>Insignias</div>
</div>
<?= html_insignias($ins) ?>
<div class="rejilla-2">
  <div class="tarjeta"><h3 style="margin-top:0">📅 Asistencia por mes</h3>
    <?= svg_barras(array_map(fn($m) => [mb_substr(nombre_mes($m[0]), 0, 3), $m[1], $m[1] . '%', $m[1] >= 85 ? '#1a9e5c' : ($m[1] >= 65 ? '#e0a100' : '#d7263d')], asistencia_mensual($aid, 6)), ['max' => 100, 'vacio' => 'Todavía no hay asistencia registrada.']) ?></div>
  <div class="tarjeta"><h3 style="margin-top:0">⏱ Minutos entrenados por semana</h3>
    <?php $ms = minutos_semanales($aid, 10); $prom = array_sum(array_column($ms, 1)) / max(1, count(array_filter($ms, fn($x) => $x[1] > 0))); ?>
    <?= array_sum(array_column($ms, 1)) ? svg_barras(array_map(fn($x) => [date('d/m', strtotime($x[0])), $x[1], $x[1] ? (string)$x[1] : ''], $ms), ['linea' => $prom, 'linea_txt' => 'promedio ' . round($prom) . ' min']) : '<p class="suave">Todavía no hay clases en las últimas semanas.</p>' ?></div>
</div>
<?php if ($tests):
    $con_pct = array_values(array_filter($tests, fn($t) => $t['semaforo'] && $t['semaforo']['percentil'] !== null)); ?>
<div class="tarjeta">
  <h3 style="margin-top:0">💪 Tests: evolución y comparación con su categoría</h3>
  <div class="rejilla-2" style="align-items:center">
    <?php if (count($con_pct) >= 3): ?><div style="text-align:center"><?= svg_radar_series(array_map(fn($t) => $t['test']['nombre'], $con_pct), [
        ['valores' => array_map(fn($t) => (float)$t['semaforo']['percentil'], $con_pct), 'nombre' => nombre_alumno($a), 'color' => 'var(--acento)'],
        ['valores' => array_fill(0, count($con_pct), 50.0), 'nombre' => 'Promedio de su categoría', 'color' => '#e0a100', 'relleno' => 0.05, 'guiones' => true],
    ], 100) ?><small class="suave">Mientras más afuera, mejor que el resto de su categoría en la escuela.</small></div><?php endif; ?>
    <div class="tabla-scroll"><table>
      <tr><th>Test</th><th class="num">Primero</th><th class="num">Último</th><th>Mejora</th><th>Vs. su categoría</th></tr>
      <?php foreach ($tests as $t): ?><tr><td><?= e($t['test']['nombre']) ?> <small class="suave"><?= e($t['test']['unidad']) ?></small></td>
        <td class="num"><?= fmt_num($t['primero']['valor']) ?></td><td class="num"><b><?= fmt_num($t['ultimo']['valor']) ?></b></td>
        <td><?= flecha_mejora($t['mejora_total']) ?></td><td><?= chip_semaforo($t['semaforo']) ?></td></tr><?php endforeach; ?>
    </table></div>
  </div>
</div>
<div class="rejilla-3">
  <?php foreach ($tests as $t): if (count($t['res']) < 2) continue;
      $prom = [];
      if ($a['fecha_nac'] !== '') foreach ($t['res'] as $x) $prom[] = [$x['fecha'], promedio_categoria($t['test'], sub_en($a['fecha_nac'], $x['fecha']))]; ?>
    <div class="tarjeta"><h3 style="margin-top:0;font-size:1rem"><?= e($t['test']['nombre']) ?> <small class="suave"><?= e($t['test']['unidad']) ?> · <?= $t['test']['mejor'] === 'menor' ? 'menos es mejor' : 'más es mejor' ?></small></h3>
      <?= svg_lineas([['nombre' => $a['nombres'], 'puntos' => array_map(fn($x) => [$x['fecha'], $x['valor']], $t['res']), 'etiquetas' => true],
          ['nombre' => 'Promedio de su categoría', 'puntos' => $prom, 'color' => '#e0a100', 'guiones' => true]], ['alto' => 140]) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php // (v19) Una evaluación técnica por cada deporte del alumno
$deps_a = deportes_alumno($a, $liga);
foreach ($deps_a as $dep_a):
$habs = habilidades_deporte($lid, $dep_a);
$ev = evals_tecnica_deporte($aid, $habs, 2);
$gid_dep = null;
foreach (grupos_alumno($aid) as $gx) if (deporte_grupo($gx, $liga) === $dep_a) { $gid_dep = (int)$gx['id']; break; }
if ($ev && count($habs) >= 3):
    $v0 = json_decode($ev[0]['valores'], true) ?: []; $v1 = isset($ev[1]) ? (json_decode($ev[1]['valores'], true) ?: []) : null;
    $pg = promedio_tecnica_grupo($gid_dep, $habs); ?>
<div class="tarjeta">
  <h3 style="margin-top:0">⭐ Evaluación técnica<?= count($deps_a) > 1 ? ' · ' . icono_deporte_escuela($dep_a) . ' ' . e(nombre_deporte_corto($dep_a)) : '' ?> <small class="suave">(<?= e(fecha_corta($ev[0]['fecha'])) ?>, de 1 a 5)</small></h3>
  <div class="rejilla-2" style="align-items:center">
    <div style="text-align:center"><?= svg_radar_series(array_column($habs, 'nombre'), array_values(array_filter([
        ['valores' => array_map(fn($h) => (float)($v0[$h['id']] ?? 0), $habs), 'nombre' => 'Ahora', 'color' => 'var(--acento)'],
        $v1 !== null ? ['valores' => array_map(fn($h) => (float)($v1[$h['id']] ?? 0), $habs), 'nombre' => 'Evaluación anterior (' . fecha_corta($ev[1]['fecha']) . ')', 'color' => '#8a9591', 'relleno' => 0.08, 'guiones' => true] : null,
        $pg ? ['valores' => $pg, 'nombre' => 'Promedio de su grupo', 'color' => '#e0a100', 'relleno' => 0.04, 'guiones' => true] : null,
    ]))) ?></div>
    <div><table><?php foreach ($habs as $h): $x = (int)($v0[$h['id']] ?? 0); $y = $v1 !== null ? (int)($v1[$h['id']] ?? 0) : null; ?>
      <tr><td><?= e($h['nombre']) ?></td><td><span class="estrellas"><?= str_repeat('★', $x) . '<span>' . str_repeat('★', 5 - $x) . '</span>' ?></span></td><td><?= $y !== null && $x !== $y ? ($x > $y ? '<span class="sube">▲</span>' : '<span class="baja">▼</span>') : '' ?></td></tr><?php endforeach; ?></table>
      <?= $ev[0]['comentario'] !== '' ? '<p class="nota-profe">🧑‍🏫 ' . e($ev[0]['comentario']) . '</p>' : '' ?></div>
  </div>
</div>
<?php endif; endforeach; ?>
<?php $med = filas('SELECT * FROM esc_mediciones WHERE alumno_id = ? ORDER BY fecha', [$aid]);
if (count($med) >= 2): ?>
<div class="rejilla-3">
  <div class="tarjeta"><h3 style="margin-top:0;font-size:1rem">📏 Talla (cm)</h3><?= svg_lineas([['nombre' => 'Talla', 'puntos' => array_map(fn($m) => [$m['fecha'], $m['talla']], $med), 'etiquetas' => true]], ['alto' => 140]) ?></div>
  <div class="tarjeta"><h3 style="margin-top:0;font-size:1rem">⚖️ Peso (kg)</h3><?= svg_lineas([['nombre' => 'Peso', 'puntos' => array_map(fn($m) => [$m['fecha'], $m['peso']], $med), 'etiquetas' => true]], ['alto' => 140]) ?></div>
  <div class="tarjeta"><h3 style="margin-top:0;font-size:1rem">IMC</h3><?= svg_lineas([['nombre' => 'IMC', 'puntos' => array_map(fn($m) => [$m['fecha'], imc($m['peso'] !== null ? (float)$m['peso'] : null, $m['talla'] !== null ? (float)$m['talla'] : null)], $med), 'etiquetas' => true]], ['alto' => 140]) ?></div>
</div>
<?php endif; ?>
<?php if ($familia): $metas = filas("SELECT * FROM esc_metas WHERE alumno_id = ? AND visible_familia = 1 AND estado <> 'pausada' ORDER BY estado = 'lograda', actualizado DESC", [$aid]);
    if ($metas): ?><div class="tarjeta"><h3 style="margin-top:0">🎯 Metas de <?= e($a['nombres']) ?></h3><?= html_metas($metas, false) ?></div><?php endif;
endif; ?>
<?php
    return (string)ob_get_clean();
}

/**
 * Informe de desarrollo del alumno (reemplaza la libreta simple): documento para imprimir o guardar en PDF,
 * con gráficos, comparación con su categoría, insignias, metas y el comentario del profesor del trimestre.
 */
function doc_informe_alumno(array $a, array $liga, string $per, string $volver): void
{
    [$ini, $fin] = rango_periodo_libreta($per);
    $aid = (int)$a['id'];
    $asis = fila("SELECT COUNT(*) AS t, SUM(CASE WHEN x.estado IN ('P','T') THEN 1 ELSE 0 END) AS v, SUM(CASE WHEN x.estado = 'J' THEN 1 ELSE 0 END) AS j
                  FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE x.alumno_id = ? AND s.fecha BETWEEN ? AND ?", [$aid, $ini, $fin]);
    $clases = (int)valor("SELECT COUNT(DISTINCT s.id) FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE x.alumno_id = ? AND x.estado IN ('P','T') AND s.fecha BETWEEN ? AND ?", [$aid, $ini, $fin]);
    $lb = fila('SELECT * FROM esc_libretas WHERE alumno_id = ? AND periodo = ?', [$aid, $per]);
    $ev = fila('SELECT * FROM esc_eval_tecnica WHERE alumno_id = ? AND fecha <= ? ORDER BY fecha DESC, id DESC LIMIT 1', [$aid, $fin]);
    $prof = $a['grupo_id'] ? fila('SELECT p.nombre FROM esc_grupos g JOIN esc_profesores p ON p.id = g.profesor_id WHERE g.id = ?', [$a['grupo_id']]) : null;
    $med = fila('SELECT * FROM esc_mediciones WHERE alumno_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1', [$aid, $fin]);
    $med_ant = $med ? fila('SELECT * FROM esc_mediciones WHERE alumno_id = ? AND fecha < ? ORDER BY fecha DESC LIMIT 1', [$aid, $med['fecha']]) : null;
    // Ejercicios más trabajados en el trimestre (por minutos)
    $tipos = filas("SELECT COALESCE(e.tipo, 'otro') AS tipo, SUM(se.minutos) AS m FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_sesion_ejercicios se ON se.sesion_id = s.id
                    LEFT JOIN esc_ejercicios e ON e.id = se.ejercicio_id WHERE x.alumno_id = ? AND x.estado IN ('P','T') AND s.fecha BETWEEN ? AND ? GROUP BY 1 ORDER BY m DESC", [$aid, $ini, $fin]);
    doc_inicio('Informe ' . nombre_alumno($a), $volver);
    ?>
  <style>
    .doc .tarjeta, .doc .cifra { background: #fff !important; color: #000 !important; border-color: #bbb !important; padding: 10px; }
    .doc .cifra b { font-size: 1.35rem; }
    .doc .rejilla-2 { grid-template-columns: 1fr 1fr; } .doc .rejilla-3 { grid-template-columns: repeat(3, 1fr); }
    .doc .cifras { grid-template-columns: repeat(5, 1fr); }
    .doc .insignia { background: #fffaf0 !important; color: #000 !important; }
    .doc .grafico, .doc .radar { color: #222; }
    .doc h3 { font-size: 13px; }
    @media (max-width: 600px) { .doc .rejilla-2, .doc .rejilla-3, .doc .cifras { grid-template-columns: 1fr 1fr; } }
    @media print { .doc .tarjeta { break-inside: avoid; } }
  </style>
  <div class="cab"><?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo-grande') ?><div><h1><?= e($liga['nombre']) ?></h1><div>INFORME DE DESARROLLO DEL ALUMNO · <?= e(mb_strtoupper(nombre_periodo_libreta($per))) ?></div></div></div>
  <div class="perfil" style="margin:10px 0"><?= foto_alumno($a, 'foto-grande') ?><div>
    <h2 style="margin:0;font-size:18px"><?= e(nombre_alumno($a)) ?></h2>
    <div><?php $eoc = edad_o_categoria($a, $liga); ?><?= e($eoc) ?><?= $a['fecha_nac'] && str_starts_with($eoc, 'Sub-') ? ' · ' . edad($a['fecha_nac']) . ' años' : '' // (arreglos 14-oct) ?> · <?= e($a['grupo'] ?? '') ?><?= $prof ? ' · Profesor: ' . e($prof['nombre']) : '' ?><?= $a['posicion'] ? ' · ' . e($a['posicion']) : '' ?></div>
    <div>En el trimestre vino a <b><?= (int)$asis['v'] ?></b> de <b><?= (int)$asis['t'] ?></b> entrenamientos<?= (int)$asis['t'] ? ' (<b>' . round(100 * (int)$asis['v'] / (int)$asis['t']) . '%</b>)' : '' ?><?= (int)$asis['j'] ? ' · ' . (int)$asis['j'] . ' falta(s) justificada(s)' : '' ?>.</div>
  </div></div>
  <?= panel_desarrollo($a, $liga, true) ?>
  <?php if ($tipos): $tot = array_sum(array_column($tipos, 'm')); ?>
  <div class="tarjeta"><h3 style="margin-top:0">🏅 Qué entrenó en el trimestre</h3>
    <?php $tipos_a = tipos_ejercicio(familia_deporte(deportes_alumno($a, $liga)[0])); ?>
    <p style="margin:0"><?= implode(' · ', array_map(fn($x) => e(($tipos_a[$x['tipo']][1] ?? 'Otros')) . ' <b>' . round(100 * (int)$x['m'] / max(1, $tot)) . '%</b>', $tipos)) ?> <small>(<?= $clases ?> clases, <?= fmt_num($tot / 60, 1) ?> horas en ejercicios)</small></p></div>
  <?php endif; ?>
  <?php if ($med): ?>
  <div class="tarjeta"><h3 style="margin-top:0">📏 Medidas corporales</h3>
  <table class="pl"><tr><th></th><?php foreach (campos_medicion() as [$n, $u]): ?><th><?= e($n) ?> (<?= e($u) ?>)</th><?php endforeach; ?><th>IMC</th></tr>
    <?php foreach (array_filter([$med_ant, $med]) as $m): ?><tr><td><?= e(date('d/m/Y', strtotime($m['fecha']))) ?></td><?php foreach (array_keys(campos_medicion()) as $k): ?><td><?= e(fmt_num($m[$k], 1)) ?></td><?php endforeach; ?><td><?= imc($m['peso'] !== null ? (float)$m['peso'] : null, $m['talla'] !== null ? (float)$m['talla'] : null) ?? '—' ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <div class="tarjeta"><h3 style="margin-top:0">🧑‍🏫 Comentario del profesor</h3>
    <div style="min-height:60px;white-space:pre-wrap"><?= e($lb['comentario'] ?? ($ev['comentario'] ?? '')) ?></div></div>
  <p style="font-size:10px;color:#444">Semáforo de los tests: <b>verde</b> = en el tercio superior de su categoría (o sobre el valor de referencia de la escuela para su edad); <b>amarillo</b> = en el promedio; <b>rojo</b> = por mejorar. Cada niño se desarrolla a su ritmo: lo importante es que mejore respecto a sí mismo.</p>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Profesor<?= $prof ? ': ' . e($prof['nombre']) : '' ?></div><div>Representante</div></div>
    <?php
    doc_fin();
}

/* ============================================================
 * C) JORNADAS DE EVALUACIÓN
 * ============================================================ */

function jornada_de(int $id, int $lid): array
{
    return fila('SELECT * FROM esc_jornadas WHERE id = ? AND liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Jornada no encontrada.');
}

function tests_jornada(array $j): array
{
    $ids = array_filter(array_map('intval', explode(',', $j['tests'])));
    if (!$ids) return [];
    return filas('SELECT * FROM esc_tests WHERE liga_id = ? AND id IN (' . implode(',', $ids) . ') ORDER BY orden, nombre', [$j['liga_id']]);
}

/** Grupos de la jornada que ve el usuario. */
function grupos_jornada(array $j): array
{
    $ids = array_filter(array_map('intval', explode(',', $j['grupos'])));
    return array_values(array_filter(grupos_visibles((int)$j['liga_id']), fn($g) => !$ids || in_array((int)$g['id'], $ids, true)));
}

/** [evaluados, total] de un grupo (o de toda la jornada). */
function progreso_jornada(array $j, array $grupos): array
{
    $ev = 0; $tot = 0;
    foreach ($grupos as $g) {
        $al = alumnos_grupo((int)$g['id']);
        $tot += count($al);
        if ($al) $ev += (int)valor('SELECT COUNT(DISTINCT alumno_id) FROM esc_resultados WHERE jornada_id = ? AND alumno_id IN (' . implode(',', array_map(fn($a) => (int)$a['id'], $al)) . ')', [$j['id']]);
    }
    return [$ev, $tot];
}

/** Último resultado de un test antes de una fecha. */
function valor_anterior(int $aid, int $tid, string $fecha): ?float
{
    $v = valor('SELECT valor FROM esc_resultados WHERE alumno_id = ? AND test_id = ? AND fecha < ? ORDER BY fecha DESC LIMIT 1', [$aid, $tid, $fecha]);
    return $v === null ? null : (float)$v;
}

/** Jornadas cerradas hace poco en las que el alumno tiene resultados (para el aviso "nuevo" del portal). */
function jornadas_nuevas_alumno(int $aid): array
{
    return filas("SELECT DISTINCT j.* FROM esc_jornadas j JOIN esc_resultados r ON r.jornada_id = j.id WHERE r.alumno_id = ? AND j.estado = 'cerrada' AND j.cerrada_en >= ? ORDER BY j.fecha DESC", [$aid, date('Y-m-d', strtotime('-21 days'))]);
}

/** Mensaje de WhatsApp para avisar a una familia que ya está la evaluación. */
function mensaje_jornada_familia(array $a, array $j, array $liga, int $mejoro, int $total): string
{
    return 'Hola ' . trim($a['representante_nombre']) . ', ya están los resultados de ' . $j['nombre'] . ' de ' . $a['nombres'] . ' en ' . $liga['nombre'] . '.'
        . ($total ? ' Mejoró en ' . $mejoro . ' de ' . $total . ' pruebas. 💪' : '') . "\n\nPuede verlos con gráficos en el portal de padres: " . url_base() . 'familia.php';
}

/* ============================================================
 * D) DIVISIONES FORMATIVAS
 * ============================================================ */

function areas_staff(): array
{
    return ['entrenador' => ['🧑‍🏫', 'Entrenador'], 'preparador' => ['💪', 'Preparación física'], 'psicologia' => ['🧠', 'Psicología'], 'nutricion' => ['🥗', 'Nutrición'],
        'medico' => ['🩺', 'Médico / fisioterapia'], 'academico' => ['🎒', 'Tutor académico'], 'direccion' => ['🏛', 'Dirección deportiva']];
}

function fases_lesion(): array
{
    return ['aguda' => '1. Lesión (reposo)', 'tratamiento' => '2. Tratamiento', 'readaptacion' => '3. Readaptación', 'grupo' => '4. Vuelta al grupo', 'alta' => '5. Alta'];
}

function potenciales(): array
{
    return ['A' => ['A', 'Proyección alta', 's-verde'], 'B' => ['B', 'En desarrollo', 's-amarillo'], 'C' => ['C', 'Seguir observando', 's-gris']];
}

function recomendaciones_valoracion(): array
{
    return ['promover' => 'Promover a la categoría superior', 'mantener' => 'Mantener y seguir desarrollando', 'reforzar' => 'Plan de refuerzo individual', 'observar' => 'Observar de cerca', 'continuidad' => 'Evaluar su continuidad'];
}

function competencias_partido(): array
{
    return ['Liga', 'Torneo', 'Copa', 'Amistoso', 'Festival'];
}

/**
 * Maduración (fórmula de Mirwald, 2002): años que faltan o pasaron desde el pico de crecimiento (PHV).
 * Necesita talla, talla sentado, peso, fecha de nacimiento y género. Es orientativa (±1 año).
 */
function maduracion(array $a, ?array $m): ?array
{
    if (!$m || !$m['talla'] || !$m['talla_sentado'] || !$m['peso'] || $a['fecha_nac'] === '' || !in_array($a['genero'], ['M', 'F'], true)) return null;
    $edad = (strtotime($m['fecha']) - strtotime($a['fecha_nac'])) / (365.25 * 86400);
    if ($edad < 7 || $edad > 19) return null;
    [$H, $SH, $W] = [(float)$m['talla'], (float)$m['talla_sentado'], (float)$m['peso']];
    $LL = $H - $SH;
    if ($SH <= 0 || $LL <= 0) return null;
    $mo = $a['genero'] === 'M'
        ? -9.236 + 0.0002708 * ($LL * $SH) - 0.001663 * ($edad * $LL) + 0.007216 * ($edad * $SH) + 0.02292 * ($W / $H * 100)
        : -9.376 + 0.0001882 * ($LL * $SH) + 0.0022 * ($edad * $LL) + 0.005841 * ($edad * $SH) - 0.002658 * ($edad * $W) + 0.07693 * ($W / $H * 100);
    $estado = $mo < -1 ? 'pre' : ($mo <= 1 ? 'pico' : 'post');
    $txt = [
        'pre' => ['Antes del estirón', 's-gris', 'Etapa ideal para técnica, coordinación, velocidad y juego. Todavía no es momento de cargas de fuerza pesadas.'],
        'pico' => ['En el pico de crecimiento', 's-amarillo', 'Crece rápido: cuidar la carga (menos saltos e impactos repetidos, más técnica y movilidad) y vigilar dolores de rodilla o talón. Puede perder coordinación por un tiempo; es normal.'],
        'post' => ['Después del estirón', 's-verde', 'Buen momento para desarrollar fuerza y potencia de forma progresiva y supervisada.'],
    ][$estado];
    return ['edad' => $edad, 'offset' => $mo, 'aphv' => $edad - $mo, 'estado' => $estado, 'nombre' => $txt[0], 'clase' => $txt[1], 'consejo' => $txt[2], 'fecha' => $m['fecha']];
}

/** Última medición completa para calcular la maduración. */
function medicion_maduracion(int $aid): ?array
{
    return fila('SELECT * FROM esc_mediciones WHERE alumno_id = ? AND talla > 0 AND talla_sentado > 0 AND peso > 0 ORDER BY fecha DESC LIMIT 1', [$aid]);
}

/** Velocidad de crecimiento (cm por año) entre la última medición y una de 3 a 15 meses antes. */
function velocidad_crecimiento(int $aid): ?float
{
    $u = fila('SELECT fecha, talla FROM esc_mediciones WHERE alumno_id = ? AND talla > 0 ORDER BY fecha DESC LIMIT 1', [$aid]);
    if (!$u) return null;
    $p = fila('SELECT fecha, talla FROM esc_mediciones WHERE alumno_id = ? AND talla > 0 AND fecha <= ? AND fecha >= ? ORDER BY fecha DESC LIMIT 1',
        [$aid, date('Y-m-d', strtotime($u['fecha'] . ' -85 days')), date('Y-m-d', strtotime($u['fecha'] . ' -15 months'))]);
    if (!$p) return null;
    $anios = (strtotime($u['fecha']) - strtotime($p['fecha'])) / (365.25 * 86400);
    return $anios > 0 ? round(((float)$u['talla'] - (float)$p['talla']) / $anios, 1) : null;
}

/** Estatura adulta estimada por la talla de los padres (±8,5 cm) y % alcanzado. */
function estatura_adulta(array $a, ?float $talla_actual): ?array
{
    if (!$a['talla_padre'] || !$a['talla_madre'] || !in_array($a['genero'], ['M', 'F'], true)) return null;
    $pred = ((float)$a['talla_padre'] + (float)$a['talla_madre'] + ($a['genero'] === 'M' ? 13 : -13)) / 2;
    return ['pred' => round($pred, 1), 'pct' => $talla_actual ? round(100 * $talla_actual / $pred, 1) : null];
}

/** Resumen de partidos de un alumno. */
function resumen_partidos_alumno(int $aid, string $desde = ''): array
{
    $r = fila("SELECT COUNT(*) AS conv, SUM(CASE WHEN pj.minutos > 0 THEN 1 ELSE 0 END) AS pj, SUM(pj.titular) AS tit, COALESCE(SUM(pj.minutos), 0) AS min, COALESCE(SUM(pj.goles), 0) AS goles,
                      COALESCE(SUM(pj.asistencias), 0) AS asis, COALESCE(SUM(pj.amarillas), 0) AS ta, COALESCE(SUM(pj.rojas), 0) AS tr, AVG(pj.calificacion) AS cal
               FROM esc_partido_jugadores pj JOIN esc_partidos p ON p.id = pj.partido_id WHERE pj.alumno_id = ? AND pj.convocado = 1 AND p.goles_favor IS NOT NULL" . ($desde ? ' AND p.fecha >= ?' : ''), $desde ? [$aid, $desde] : [$aid]);
    return array_map(fn($v) => $v === null ? null : (is_numeric($v) ? $v + 0 : $v), $r);
}

function texto_resultado_partido(array $p): string
{
    if ($p['goles_favor'] === null) return 'Por jugar';
    $gf = (int)$p['goles_favor']; $gc = (int)$p['goles_contra'];
    return ($gf > $gc ? 'Ganó' : ($gf < $gc ? 'Perdió' : 'Empató')) . ' ' . $gf . '-' . $gc;
}
