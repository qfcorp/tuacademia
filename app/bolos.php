<?php
/*
 * (v25) CAMPEONATOS DE BOLOS (boliche, bowling)
 *
 * Usa las mismas tablas que las carreras (para no duplicar):
 *   carrera_categorias   → categorías (Varones, Damas, Juvenil…)
 *   carrera_participantes→ jugadores (club, handicap inicial)
 *   carrera_fechas       → JORNADAS (cada una con sus líneas)
 *   carrera_resultados   → una fila por LÍNEA (manga = número de línea, pinos = 0 a 300)
 *
 * Reglas (columnas de torneos): bolos_lineas (líneas por jornada), bolos_hcp ('no' | 'fijo' | 'auto'),
 * bolos_hcp_base y bolos_hcp_pct (handicap automático = (base − promedio) × %), bolos_clasif
 * ('total' = suma de pinos, 'promedio', 'puntos' = puntos por puesto en cada jornada, con carrera_puntos).
 * Clasificación por clubes: suma de los 3 mejores de cada club.
 */

function modalidades_bolos(): array
{
    return ['BOLOS' => ['Bolos (boliche)', '🎳', 'bolos']];
}

function es_bolos($t): bool
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return isset(modalidades_bolos()[$m]);
}

function textos_bolos(): array
{
    return ['participante' => 'Jugador', 'participantes' => 'Jugadores', 'fecha' => 'Jornada', 'fechas' => 'Jornadas', 'manga' => 'Línea', 'mangas' => 'Líneas'];
}

/** Cuántos del mismo club suman para la clasificación por clubes. */
const BOLOS_POR_CLUB = 3;

function bolos_cfg(array $t): array
{
    $raw = preg_split('/[\s,;]+/', trim((string)($t['carrera_puntos'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $puntos = array_map('intval', $raw) ?: [25, 20, 16, 13, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];
    return [
        'lineas' => max(1, min(10, (int)($t['bolos_lineas'] ?? 3))),
        'hcp' => in_array($t['bolos_hcp'] ?? 'no', ['no', 'fijo', 'auto'], true) ? $t['bolos_hcp'] : 'no',
        'base' => max(100, min(300, (int)($t['bolos_hcp_base'] ?? 200))),
        'pct' => max(0, min(100, (int)($t['bolos_hcp_pct'] ?? 80))),
        'clasif' => in_array($t['bolos_clasif'] ?? 'total', ['total', 'promedio', 'puntos'], true) ? $t['bolos_clasif'] : 'total',
        'puntos' => $puntos,
    ];
}

function bolos_textos_hcp(): array
{
    return ['no' => 'Sin handicap (scratch)', 'fijo' => 'Handicap fijo de cada jugador', 'auto' => 'Handicap automático según su promedio'];
}

function bolos_textos_clasif(): array
{
    return ['total' => 'Total de pinos acumulado', 'promedio' => 'Mejor promedio por línea', 'puntos' => 'Puntos por puesto en cada jornada'];
}

function bolos_categorias_base(): array
{
    return [['Varones', ''], ['Damas', ''], ['Juvenil', 'Hasta 17 años'], ['Senior', '55 años o más']];
}

function bolos_cargar_categorias_base(array $t): int
{
    $n = (int)valor('SELECT COALESCE(MAX(orden), 0) FROM carrera_categorias WHERE torneo_id = ?', [$t['id']]);
    $ya = array_map('mb_strtolower', array_column(carrera_categorias((int)$t['id']), 'nombre'));
    $k = 0;
    foreach (bolos_categorias_base() as [$nom, $nota]) {
        if (in_array(mb_strtolower($nom), $ya, true)) continue;
        q('INSERT INTO carrera_categorias (torneo_id, nombre, orden, nota) VALUES (?,?,?,?)', [$t['id'], $nom, ++$n, $nota]);
        $k++;
    }
    return $k;
}

function bolos_lineas_jornada(array $f, array $cfg): int
{
    return (int)$f['mangas'] > 0 ? min(10, (int)$f['mangas']) : $cfg['lineas'];
}

/** Pinos de una línea: entero de 0 a 300 (null si está vacío). Lanza error si está mal escrito. */
function bolos_parse_pinos(string $s): ?int
{
    $s = trim($s);
    if ($s === '') return null;
    if (!ctype_digit($s) || (int)$s > 300) throw new RuntimeException("«{$s}» no es un puntaje válido: escriba los pinos de la línea, de 0 a 300.");
    return (int)$s;
}

/** Líneas jugadas por jornada: [fecha_id => [participante_id => [linea => pinos]]] */
function bolos_lineas_torneo(int $tid): array
{
    $r = [];
    foreach (filas('SELECT r.fecha_id, r.participante_id, r.manga, r.pinos FROM carrera_resultados r JOIN carrera_fechas f ON f.id = r.fecha_id
                    WHERE f.torneo_id = ? AND r.pinos IS NOT NULL', [$tid]) as $x) {
        $r[(int)$x['fecha_id']][(int)$x['participante_id']][(int)$x['manga']] = (int)$x['pinos'];
    }
    return $r;
}

/** Handicap por línea de un jugador en una jornada (según la regla del campeonato). */
function bolos_hcp_jugador(array $p, array $cfg, array $previas): int
{
    if ($cfg['hcp'] === 'no') return 0;
    if ($cfg['hcp'] === 'fijo') return max(0, (int)round((float)$p['handicap']));
    // automático: con al menos 3 líneas anteriores se calcula; si no, se usa el inicial
    if (count($previas) >= 3) {
        $prom = array_sum($previas) / count($previas);
        return max(0, (int)floor(($cfg['base'] - $prom) * $cfg['pct'] / 100));
    }
    return max(0, (int)round((float)$p['handicap']));
}

/**
 * Calcula todo el campeonato: por jornada y general.
 * Devuelve ['fechas' => [...], 'part' => [...], 'jornadas' => [fid => [pid => fila]], 'general' => [pid => fila]]
 */
function bolos_calcular(array $t): array
{
    $cfg = bolos_cfg($t);
    $tid = (int)$t['id'];
    $fechas = carrera_fechas($tid);
    $part = [];
    foreach (filas('SELECT * FROM carrera_participantes WHERE torneo_id = ? ORDER BY nombre', [$tid]) as $p) $part[(int)$p['id']] = $p;
    $lineas = bolos_lineas_torneo($tid);
    $previas = []; // pid => [pinos de jornadas anteriores]
    $jornadas = [];
    $gen = [];
    foreach ($fechas as $f) {
        $fid = (int)$f['id'];
        $nl = bolos_lineas_jornada($f, $cfg);
        $filas_j = [];
        foreach ($lineas[$fid] ?? [] as $pid => $ls) {
            if (!isset($part[$pid])) continue;
            $ls = array_filter($ls, fn($m) => $m <= $nl, ARRAY_FILTER_USE_KEY);
            if (!$ls) continue;
            ksort($ls);
            $hcp = bolos_hcp_jugador($part[$pid], $cfg, $previas[$pid] ?? []);
            $scr = array_sum($ls);
            $n = count($ls);
            $filas_j[$pid] = ['p' => $part[$pid], 'lineas' => $ls, 'n' => $n, 'scratch' => $scr, 'hcp' => $hcp, 'total' => $scr + $hcp * $n, 'alta' => max($ls), 'pts' => 0, 'pos' => 0];
        }
        // puestos y puntos por categoría
        $por_cat = [];
        foreach ($filas_j as $pid => $x) $por_cat[(int)($x['p']['categoria_id'] ?? 0)][] = $pid;
        foreach ($por_cat as $ids) {
            usort($ids, fn($a, $b) => [$filas_j[$b]['total'], $filas_j[$b]['alta']] <=> [$filas_j[$a]['total'], $filas_j[$a]['alta']]);
            foreach ($ids as $i => $pid) {
                $filas_j[$pid]['pos'] = $i + 1;
                $filas_j[$pid]['pts'] = $cfg['puntos'][$i] ?? 0;
            }
        }
        $jornadas[$fid] = $filas_j;
        foreach ($filas_j as $pid => $x) {
            foreach ($x['lineas'] as $pin) $previas[$pid][] = $pin;
            $g = &$gen[$pid];
            $g ??= ['p' => $part[$pid], 'jornadas' => [], 'n' => 0, 'scratch' => 0, 'hcp_total' => 0, 'total' => 0, 'pts' => 0, 'alta' => 0, 'serie' => 0, 'jugadas' => 0];
            $g['jornadas'][$fid] = $x;
            $g['n'] += $x['n'];
            $g['scratch'] += $x['scratch'];
            $g['hcp_total'] += $x['hcp'] * $x['n'];
            $g['total'] += $x['total'];
            $g['pts'] += $x['pts'];
            $g['alta'] = max($g['alta'], $x['alta']);
            $g['serie'] = max($g['serie'], $x['scratch']);
            $g['jugadas']++;
            unset($g);
        }
    }
    foreach ($gen as &$g) {
        $g['promedio'] = $g['n'] ? $g['scratch'] / $g['n'] : 0.0;
        $g['promedio_hcp'] = $g['n'] ? $g['total'] / $g['n'] : 0.0;
        $g['hcp_actual'] = bolos_hcp_jugador($g['p'], $cfg, $previas[(int)$g['p']['id']] ?? []);
    }
    unset($g);
    return ['cfg' => $cfg, 'fechas' => $fechas, 'part' => $part, 'jornadas' => $jornadas, 'general' => $gen];
}

/** Ordena la general según la regla del campeonato. */
function bolos_ordenar_general(array $filas, array $cfg): array
{
    usort($filas, function ($a, $b) use ($cfg) {
        $k = match ($cfg['clasif']) {
            'promedio' => [$b['promedio_hcp'], $b['total'], $b['alta']] <=> [$a['promedio_hcp'], $a['total'], $a['alta']],
            'puntos' => [$b['pts'], $b['total'], $b['alta']] <=> [$a['pts'], $a['total'], $a['alta']],
            default => [$b['total'], $b['alta'], $b['serie']] <=> [$a['total'], $a['alta'], $a['serie']],
        };
        return $k ?: strcmp($a['p']['nombre'], $b['p']['nombre']);
    });
    foreach ($filas as $i => &$x) $x['pos'] = $i + 1;
    unset($x);
    return $filas;
}

/** Agrupa filas por categoría en el orden de las categorías (las sin categoría al final). */
function bolos_por_categoria(array $t, array $filas): array
{
    $cats = carrera_categorias((int)$t['id']);
    $grupos = [];
    foreach ($filas as $x) $grupos[(int)($x['p']['categoria_id'] ?? 0)][] = $x;
    $r = [];
    foreach ($cats as $c) if (!empty($grupos[(int)$c['id']])) $r[] = ['cat' => $c, 'filas' => $grupos[(int)$c['id']]];
    if (!empty($grupos[0])) $r[] = ['cat' => ['id' => 0, 'nombre' => 'Sin categoría', 'nota' => ''], 'filas' => $grupos[0]];
    return $r;
}

/** Clasificación general por categoría. */
function bolos_general(array $t, ?array $calc = null): array
{
    $calc = $calc ?? bolos_calcular($t);
    $cats = [];
    foreach (bolos_por_categoria($t, array_values($calc['general'])) as $c) $cats[] = ['cat' => $c['cat'], 'filas' => bolos_ordenar_general($c['filas'], $calc['cfg'])];
    return ['cats' => $cats, 'fechas' => $calc['fechas'], 'cfg' => $calc['cfg']];
}

/** Clasificación de una jornada por categoría. */
function bolos_jornada(array $t, array $f, ?array $calc = null): array
{
    $calc = $calc ?? bolos_calcular($t);
    $filas = array_values($calc['jornadas'][(int)$f['id']] ?? []);
    $cats = [];
    foreach (bolos_por_categoria($t, $filas) as $c) {
        usort($c['filas'], fn($a, $b) => $a['pos'] <=> $b['pos']);
        $cats[] = $c;
    }
    return ['cats' => $cats, 'n' => count($filas), 'lineas' => bolos_lineas_jornada($f, $calc['cfg'])];
}

/** Clubes: suma de los BOLOS_POR_CLUB mejores totales de cada club. */
function bolos_clubes(array $calc): array
{
    $por = [];
    foreach ($calc['general'] as $g) {
        $club = trim((string)$g['p']['club']);
        if ($club === '') continue;
        $por[$club][] = $g;
    }
    $r = [];
    foreach ($por as $club => $lista) {
        usort($lista, fn($a, $b) => $b['total'] <=> $a['total']);
        $top = array_slice($lista, 0, BOLOS_POR_CLUB);
        $r[] = ['club' => $club, 'total' => array_sum(array_column($top, 'total')), 'jugadores' => count($lista), 'suman' => array_map(fn($x) => $x['p']['nombre'], $top)];
    }
    usort($r, fn($a, $b) => [$b['total'], $a['club']] <=> [$a['total'], $b['club']]);
    return $r;
}

/** Récords del campeonato: línea más alta, serie más alta y mejor promedio (sin handicap). */
function bolos_destacados(array $calc): array
{
    $alta = $serie = $prom = null;
    foreach ($calc['general'] as $g) {
        if (!$alta || $g['alta'] > $alta['v']) $alta = ['v' => $g['alta'], 'p' => $g['p']];
        if (!$serie || $g['serie'] > $serie['v']) $serie = ['v' => $g['serie'], 'p' => $g['p']];
        if ($g['n'] >= 3 && (!$prom || $g['promedio'] > $prom['v'])) $prom = ['v' => $g['promedio'], 'p' => $g['p']];
    }
    return ['alta' => $alta, 'serie' => $serie, 'promedio' => $prom];
}

function bolos_fmt_prom(float $x): string
{
    return number_format($x, 1, ',', '.');
}

/* ============================================================
 * HTML (panel y página pública)
 * ============================================================ */

function bolos_html_tabla_jornada(array $filas, int $nl, array $cfg): string
{
    $con_hcp = $cfg['hcp'] !== 'no';
    ob_start(); ?>
    <div class="tabla-scroll"><table class="tabla-bolos">
      <tr><th>#</th><th>Jugador</th><?php for ($m = 1; $m <= $nl; $m++): ?><th class="num col-manga">L<?= $m ?></th><?php endfor; ?>
        <th class="num">Pinos</th><?php if ($con_hcp): ?><th class="num" title="Handicap por línea">Hcp</th><?php endif; ?><th class="num">Total</th><?php if ($cfg['clasif'] === 'puntos'): ?><th class="num">Pts</th><?php endif; ?></tr>
      <?php foreach ($filas as $x): ?>
        <tr><td><?= (int)$x['pos'] ?></td>
          <td><?= e($x['p']['nombre']) ?><?= $x['p']['club'] !== '' ? '<br><small class="suave">' . e($x['p']['club']) . '</small>' : '' ?>
            <small class="solo-movil suave" style="display:none"><?= e(implode(' · ', $x['lineas'])) ?></small></td>
          <?php for ($m = 1; $m <= $nl; $m++): $v = $x['lineas'][$m] ?? null; ?><td class="num col-manga"<?= $v !== null && $v === $x['alta'] ? ' style="font-weight:700"' : '' ?>><?= $v ?? '—' ?></td><?php endfor; ?>
          <td class="num"><?= (int)$x['scratch'] ?></td><?php if ($con_hcp): ?><td class="num"><?= (int)$x['hcp'] ?></td><?php endif; ?>
          <td class="num pts"><?= (int)$x['total'] ?></td><?php if ($cfg['clasif'] === 'puntos'): ?><td class="num"><?= (int)$x['pts'] ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <?php return bolos_css() . (string)ob_get_clean();
}

function bolos_html_tabla_general(array $filas, array $fechas, array $cfg): string
{
    $con_hcp = $cfg['hcp'] !== 'no';
    $jug = array_values(array_filter($fechas, function ($f) use ($filas) { foreach ($filas as $x) if (isset($x['jornadas'][(int)$f['id']])) return true; return false; }));
    ob_start(); ?>
    <div class="tabla-scroll"><table class="tabla-bolos">
      <tr><th>#</th><th>Jugador</th><?php foreach ($jug as $i => $f): ?><th class="num col-manga" title="<?= e(carrera_nombre_fecha($f, textos_bolos())) ?>">J<?= $i + 1 ?></th><?php endforeach; ?>
        <th class="num col-manga">Líneas</th><th class="num" title="Promedio sin handicap">Prom.</th><?php if ($con_hcp): ?><th class="num" title="Handicap actual por línea">Hcp</th><?php endif; ?>
        <th class="num"><?= $cfg['clasif'] === 'puntos' ? 'Pinos' : 'Total' ?></th><?php if ($cfg['clasif'] === 'puntos'): ?><th class="num">Pts</th><?php endif; ?></tr>
      <?php foreach ($filas as $x): ?>
        <tr><td><?= (int)$x['pos'] ?></td>
          <td><?= e($x['p']['nombre']) ?><?= $x['p']['club'] !== '' ? '<br><small class="suave">' . e($x['p']['club']) . '</small>' : '' ?></td>
          <?php foreach ($jug as $f): $j = $x['jornadas'][(int)$f['id']] ?? null; ?><td class="num col-manga"><?= $j ? (int)$j['total'] . ($cfg['clasif'] === 'puntos' ? '<br><small class="suave">' . (int)$j['pts'] . ' pts</small>' : '') : '—' ?></td><?php endforeach; ?>
          <td class="num col-manga"><?= (int)$x['n'] ?></td><td class="num"><?= bolos_fmt_prom($x['promedio']) ?></td><?php if ($con_hcp): ?><td class="num"><?= (int)$x['hcp_actual'] ?></td><?php endif; ?>
          <td class="num<?= $cfg['clasif'] !== 'puntos' ? ' pts' : '' ?>"><?= (int)$x['total'] ?></td><?php if ($cfg['clasif'] === 'puntos'): ?><td class="num pts"><?= (int)$x['pts'] ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <?php return bolos_css() . (string)ob_get_clean();
}

function bolos_html_clubes(array $clubes): string
{
    if (!$clubes) return '<p class="suave">Escriba el club de cada jugador para ver la clasificación por clubes.</p>';
    ob_start(); ?>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th>Club</th><th class="num">Jugadores</th><th>Suman (los <?= BOLOS_POR_CLUB ?> mejores)</th><th class="num">Total</th></tr>
      <?php foreach ($clubes as $i => $c): ?>
        <tr><td><?= $i + 1 ?></td><td><b><?= e($c['club']) ?></b></td><td class="num"><?= (int)$c['jugadores'] ?></td><td><small><?= e(implode(', ', $c['suman'])) ?></small></td><td class="num pts"><?= (int)$c['total'] ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php return (string)ob_get_clean();
}

function bolos_html_destacados(array $d): string
{
    $h = '<div class="podio" style="margin:.4rem 0 1rem">';
    if ($d['alta']) $h .= '<span>🎳 <b>Línea más alta:</b> ' . (int)$d['alta']['v'] . ' · ' . e($d['alta']['p']['nombre']) . '</span>';
    if ($d['serie']) $h .= '<span>🔥 <b>Serie más alta:</b> ' . (int)$d['serie']['v'] . ' · ' . e($d['serie']['p']['nombre']) . '</span>';
    if ($d['promedio']) $h .= '<span>📈 <b>Mejor promedio:</b> ' . bolos_fmt_prom($d['promedio']['v']) . ' · ' . e($d['promedio']['p']['nombre']) . '</span>';
    return $h . '</div>';
}

function bolos_css(): string
{
    static $ya = false;
    if ($ya) return '';
    $ya = true;
    return '<style>.podio{display:flex;flex-wrap:wrap;gap:6px 16px}.podio span{white-space:nowrap}
      @media (max-width:700px){.tabla-bolos .col-manga{display:none}.tabla-bolos .solo-movil{display:block!important}}</style>';
}

/* ============================================================
 * EJEMPLO PARA PROBAR
 * ============================================================ */

function bolos_cargar_ejemplo(array $t): void
{
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM carrera_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene jugadores. El ejemplo solo se carga en uno vacío.');
    bolos_cargar_categorias_base($t);
    $cats = carrera_categorias($tid);
    $clubes = ['Bolera Quito', 'Club Los Andes', 'Strike Norte'];
    $jug = [
        [0, 'Andrés Villacís', 185, 10], [0, 'Pablo Recalde', 172, 18], [0, 'Esteban Mora', 160, 28], [0, 'Iván Cedeño', 198, 0], [0, 'Raúl Jácome', 150, 36], [0, 'Fabián Endara', 166, 24],
        [1, 'Carolina Silva', 158, 30], [1, 'Gabriela Pazmiño', 170, 20], [1, 'Lorena Ayala', 145, 40], [1, 'María José Erazo', 162, 26],
    ];
    mt_srand(2500);
    $ids = [];
    foreach ($jug as $i => [$ci, $nom, $prom, $hcp]) {
        q('INSERT INTO carrera_participantes (torneo_id, categoria_id, numero, nombre, club, ciudad, handicap) VALUES (?,?,?,?,?,?,?)',
            [$tid, $cats[$ci]['id'] ?? null, (string)($i + 1), $nom, $clubes[$i % 3], 'Quito', $hcp]);
        $ids[(int)db()->lastInsertId()] = $prom;
    }
    foreach ([[-28, 'Jornada 1 · Bolera del Quicentro'], [-14, 'Jornada 2 · Bolera del Quicentro'], [0, 'Jornada 3 · Bowling El Condado']] as $k => [$dias, $nom]) {
        q('INSERT INTO carrera_fechas (torneo_id, num, nombre, fecha, lugar, mangas) VALUES (?,?,?,?,?,0)', [$tid, $k + 1, $nom, date('Y-m-d', strtotime("$dias days")), $k < 2 ? 'Quicentro' : 'El Condado']);
        $fid = (int)db()->lastInsertId();
        foreach ($ids as $pid => $prom) {
            if ($k === 2 && mt_rand(0, 4) === 0) continue; // alguno faltó a la última jornada
            for ($l = 1; $l <= bolos_cfg($t)['lineas']; $l++) {
                $p = max(80, min(300, $prom + mt_rand(-35, 40)));
                q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, pinos, estado) VALUES (?,?,?,?,?)', [$fid, $pid, $l, $p, 'ok']);
            }
        }
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}
