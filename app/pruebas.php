<?php
/*
 * (v26) COMPETENCIAS POR PRUEBAS: NATACIÓN y ATLETISMO
 *
 * Un campeonato tiene un PROGRAMA de pruebas numeradas ("Prueba 3 · 50 m libre · Damas 11 y 12 años").
 * Cada prueba es por rama y por edad, y se mide en TIEMPO (gana el menor) o en METROS (gana el mayor: saltos y lanzamientos).
 * Los competidores se inscriben en las pruebas que les tocan; se carga la marca de cada uno (o DNS / DNF / DQ),
 * y el sistema da los puestos, las medallas, los puntos por club y las mejores marcas.
 *
 * Tablas: prueba_eventos, prueba_participantes, prueba_resultados (vea esquema_v23 en db.php).
 * La marca se guarda en milisegundos (tiempo) o en metros (distancia).
 * La edad para la categoría es la que el competidor cumple en el año del campeonato (año − año de nacimiento).
 */

function modalidades_pruebas(): array
{
    return ['NATAC' => ['Natación', '🏊', 'pruebas'], 'ATLET' => ['Atletismo', '🏃', 'pruebas'],
        // (v34) ciclismo con reglas UCI: ver ciclismo_pista.php
        'PISTA' => ['Ciclismo de pista', '🚴', 'pruebas'], 'BMX' => ['BMX Racing', '🚲', 'pruebas'], 'BMXFS' => ['BMX Freestyle', '🤸', 'pruebas'],
        // (v36) carreras de ruta / trail / cross, levantamiento de pesas y triatlón: ver pruebas_v36.php
        'RUTA' => ['Carreras de ruta, trail y cross', '🏃', 'pruebas'], 'PESAS' => ['Levantamiento de pesas', '🏋️', 'pruebas'], 'TRIAT' => ['Triatlón, duatlón y acuatlón', '🏊', 'pruebas']];
}

function es_pruebas($t): bool
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return isset(modalidades_pruebas()[$m]);
}

function textos_pruebas($t): array
{
    $mod = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    if ($mod === 'PISTA') return ['participante' => 'Ciclista', 'participantes' => 'Ciclistas', 'lugar' => 'Velódromo', 'carril' => 'Orden'];
    if ($mod === 'BMX') return ['participante' => 'Piloto', 'participantes' => 'Pilotos', 'lugar' => 'Pista', 'carril' => 'Partidor'];
    if ($mod === 'BMXFS') return ['participante' => 'Rider', 'participantes' => 'Riders', 'lugar' => 'Park', 'carril' => 'Orden'];
    if ($mod === 'RUTA') return ['participante' => 'Corredor', 'participantes' => 'Corredores', 'lugar' => 'Circuito', 'carril' => 'Dorsal'];
    if ($mod === 'PESAS') return ['participante' => 'Pesista', 'participantes' => 'Pesistas', 'lugar' => 'Tarima', 'carril' => 'Orden'];
    if ($mod === 'TRIAT') return ['participante' => 'Triatleta', 'participantes' => 'Triatletas', 'lugar' => 'Circuito', 'carril' => 'Orden'];
    $nat = $mod === 'NATAC';
    return $nat
        ? ['participante' => 'Nadador', 'participantes' => 'Nadadores', 'lugar' => 'Piscina', 'carril' => 'Carril']
        : ['participante' => 'Atleta', 'participantes' => 'Atletas', 'lugar' => 'Pista', 'carril' => 'Carril'];
}

function pruebas_estados(): array
{
    return ['ok' => 'Con marca', 'dns' => 'DNS (no se presentó)', 'dnf' => 'DNF (no terminó)', 'dq' => 'DQ (descalificado)'];
}

function pruebas_cfg(array $t): array
{
    $raw = preg_split('/[\s,;]+/', trim((string)($t['carrera_puntos'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $p = array_map('intval', $raw);
    return ['puntos' => $p ?: [9, 7, 6, 5, 4, 3, 2, 1]];
}

/** Pruebas típicas de cada deporte: [nombre, medida]. */
function pruebas_catalogo(string $m): array
{
    if (function_exists('pista_catalogo') && ($c = pista_catalogo($m))) return $c; // (v34) [nombre, medida, formato]
    if (function_exists('pv_catalogo') && ($c = pv_catalogo($m))) return $c; // (v36) atletismo, ruta, pesas, triatlón
    if ($m === 'ATLET') {
        return [['60 m planos', 'tiempo'], ['80 m planos', 'tiempo'], ['100 m planos', 'tiempo'], ['200 m planos', 'tiempo'], ['400 m planos', 'tiempo'],
            ['800 m', 'tiempo'], ['1.500 m', 'tiempo'], ['3.000 m', 'tiempo'], ['Posta 4 × 100 m', 'tiempo'],
            ['Salto largo', 'metros'], ['Salto alto', 'metros'], ['Lanzamiento de bala', 'metros'], ['Lanzamiento de jabalina', 'metros'], ['Lanzamiento de disco', 'metros']];
    }
    return [['25 m libre', 'tiempo'], ['50 m libre', 'tiempo'], ['100 m libre', 'tiempo'], ['200 m libre', 'tiempo'], ['25 m espalda', 'tiempo'], ['50 m espalda', 'tiempo'],
        ['25 m pecho', 'tiempo'], ['50 m pecho', 'tiempo'], ['25 m mariposa', 'tiempo'], ['50 m mariposa', 'tiempo'], ['100 m combinado', 'tiempo'], ['Relevo 4 × 50 m libre', 'tiempo']];
}

/** Categorías de edad típicas: [nombre, edad_min, edad_max]. */
function pruebas_categorias_base(string $m = ''): array
{
    if ($m !== '' && function_exists('pista_categorias_base') && ($c = pista_categorias_base($m))) return $c; // (v34)
    if ($m !== '' && function_exists('pv_categorias_base') && ($c = pv_categorias_base($m))) return $c; // (v36)
    return [['Hasta 10 años', 0, 10], ['11 y 12 años', 11, 12], ['13 y 14 años', 13, 14], ['15 a 17 años', 15, 17], ['Mayores (18+)', 18, 0]];
}

function pruebas_ramas(): array
{
    return ['M' => 'Varones', 'F' => 'Damas', 'X' => 'Mixto'];
}

function pruebas_eventos(int $tid): array
{
    return filas('SELECT * FROM prueba_eventos WHERE torneo_id = ? ORDER BY num, id', [$tid]);
}

function prueba_nombre(array $ev, bool $con_num = true): string
{
    $cat = trim((pruebas_ramas()[$ev['rama']] ?? '') . ' ' . $ev['categoria']);
    return ($con_num ? 'Prueba ' . (int)$ev['num'] . ' · ' : '') . $ev['nombre'] . ($cat !== '' ? ' · ' . $cat : '');
}

function pruebas_participantes(int $tid, bool $solo_activos = false): array
{
    $r = [];
    foreach (filas('SELECT * FROM prueba_participantes WHERE torneo_id = ?' . ($solo_activos ? ' AND activo = 1' : '') . ' ORDER BY nombre', [$tid]) as $p) $r[(int)$p['id']] = $p;
    return $r;
}

/** Edad que cumple en el año del campeonato. */
function prueba_edad(string $nac, array $t): ?int
{
    if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $nac, $m)) return null;
    $anio = preg_match('/(\d{4})/', (string)$t['temporada'], $a) ? (int)$a[1] : (int)date('Y');
    return $anio - (int)$m[1];
}

/** ¿El competidor puede entrar a la prueba por su rama y su edad? Devuelve '' si sí, o el motivo. */
function prueba_no_puede(array $ev, array $p, array $t): string
{
    if ($ev['rama'] !== '' && $ev['rama'] !== 'X' && $p['rama'] !== '' && $p['rama'] !== $ev['rama']) return 'es de la rama ' . mb_strtolower(pruebas_ramas()[$ev['rama']] ?? '');
    if ((int)$ev['edad_min'] > 0 || (int)$ev['edad_max'] > 0) {
        $e = prueba_edad((string)$p['nacimiento'], $t);
        if ($e === null) return 'falta su fecha de nacimiento';
        if ((int)$ev['edad_min'] > 0 && $e < (int)$ev['edad_min']) return 'es para ' . (int)$ev['edad_min'] . ' años o más';
        if ((int)$ev['edad_max'] > 0 && $e > (int)$ev['edad_max']) return 'es hasta ' . (int)$ev['edad_max'] . ' años';
    }
    return '';
}

/** "1:05.30", "28.47" (centésimas) o "4,85 m". Lanza error si está mal escrito. */
function prueba_parse_marca(string $medida, string $txt): ?float
{
    $txt = trim($txt);
    if ($txt === '') return null;
    if ($medida === 'puesto') { // (v34) scratch, eliminación: puesto de llegada
        $v = ltrim(preg_replace('/[°ºo.\s]+$/u', '', $txt), '#');
        if (!ctype_digit($v) || (int)$v < 1 || (int)$v > 999) throw new RuntimeException("«{$txt}» no es un puesto válido: escriba 1, 2, 3…");
        return (float)(int)$v;
    }
    if ($medida === 'metros') {
        $v = str_replace(',', '.', $txt);
        if (!preg_match('/^\d{1,3}(\.\d{1,3})?$/', $v)) throw new RuntimeException("«{$txt}» no es una distancia válida: escriba los metros como 4,85.");
        return round((float)$v, 3);
    }
    $ms = carrera_parse_tiempo($txt);
    if ($ms === null) throw new RuntimeException("«{$txt}» no es un tiempo válido: escriba 1:05.30 o 28.47.");
    return (float)$ms;
}

function prueba_fmt_marca(string $medida, $v): string
{
    if ($v === null || $v === '') return '';
    if ($medida === 'puesto') return (int)$v . '.°';
    if ($medida === 'metros') return rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . ' m';
    $ms = (int)round((float)$v);
    $m = intdiv($ms, 60000);
    $s = intdiv($ms % 60000, 1000);
    $c = intdiv($ms % 1000, 10);
    return $m > 0 ? sprintf('%d:%02d.%02d', $m, $s, $c) : sprintf('%d.%02d', $s, $c);
}

/**
 * Resultados de una prueba con su puesto: los que tienen marca, ordenados (empates = mismo puesto);
 * después DNS / DNF / DQ y los inscritos sin marca.
 */
function prueba_resultados(array $ev, ?array $part = null): array
{
    $part = $part ?? pruebas_participantes((int)$ev['torneo_id']);
    if (function_exists('pv_es_formato') && pv_es_formato($ev)) return pv_resultados($ev, $part); // (v36)
    if (($ev['formato'] ?? 'marca') !== 'marca' && ($ev['formato'] ?? '') !== '' && function_exists('pista_resultados')) return pista_resultados($ev, $part); // (v34)
    $filas = [];
    foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) {
        if (!isset($part[(int)$r['participante_id']])) continue;
        $vi = ($r['datos'] ?? '') !== '' && function_exists('pv_viento_txt') ? (json_decode((string)$r['datos'], true)['v'] ?? '') : ''; // (v36) viento
        $filas[] = $r + ['p' => $part[(int)$r['participante_id']], 'puesto' => null] + ($vi !== '' ? ['detalle' => pv_viento_txt($vi)] : []);
    }
    $con = array_values(array_filter($filas, fn($r) => $r['estado'] === 'ok' && $r['marca'] !== null));
    $sin = array_values(array_filter($filas, fn($r) => !($r['estado'] === 'ok' && $r['marca'] !== null)));
    usort($con, fn($a, $b) => $ev['medida'] === 'metros' ? (float)$b['marca'] <=> (float)$a['marca'] : (float)$a['marca'] <=> (float)$b['marca']);
    $ant = null;
    foreach ($con as $i => &$r) {
        $r['puesto'] = ($ant !== null && (float)$r['marca'] === (float)$ant['marca']) ? $ant['puesto'] : $i + 1;
        $ant = $r;
    }
    unset($r);
    $orden_est = ['ok' => 0, 'dq' => 1, 'dnf' => 2, 'dns' => 3];
    usort($sin, fn($a, $b) => [$orden_est[$a['estado']] ?? 9, (int)$a['serie'], (int)$a['carril'], $a['p']['nombre']] <=> [$orden_est[$b['estado']] ?? 9, (int)$b['serie'], (int)$b['carril'], $b['p']['nombre']]);
    return array_merge($con, $sin);
}

/** ¿La prueba ya tiene marcas? */
function prueba_con_marcas(int $evento_id): bool
{
    return (int)valor("SELECT COUNT(*) FROM prueba_resultados WHERE evento_id = ? AND (marca IS NOT NULL OR estado <> 'ok' OR datos <> '')", [$evento_id]) > 0
        || (int)valor('SELECT COUNT(*) FROM prueba_series WHERE evento_id = ? AND (puesto IS NOT NULL OR estado <> \'ok\' OR datos <> \'\')', [$evento_id]) > 0; // (v34) series, cuadros y jueces
}

/**
 * Medallero y puntos de todo el campeonato.
 * ['clubes' => [[club, oro, plata, bronce, puntos]], 'personas' => [[p, oro, plata, bronce]], 'podios' => [ev_id => [puesto => [filas]]]]
 */
function pruebas_medallero(array $t): array
{
    $cfg = pruebas_cfg($t);
    $part = pruebas_participantes((int)$t['id']);
    $clubes = [];
    $personas = [];
    $podios = [];
    foreach (pruebas_eventos((int)$t['id']) as $ev) {
        foreach (prueba_resultados($ev, $part) as $r) {
            if ($r['puesto'] === null) continue;
            $club = trim((string)$r['p']['club']) ?: 'Sin club';
            $clubes[$club] ??= ['club' => $club, 'oro' => 0, 'plata' => 0, 'bronce' => 0, 'puntos' => 0];
            $clubes[$club]['puntos'] += $cfg['puntos'][$r['puesto'] - 1] ?? 0;
            if ($r['puesto'] > 3) continue;
            $m = ['oro', 'plata', 'bronce'][$r['puesto'] - 1];
            $clubes[$club][$m]++;
            $pid = (int)$r['p']['id'];
            $personas[$pid] ??= ['p' => $r['p'], 'oro' => 0, 'plata' => 0, 'bronce' => 0];
            $personas[$pid][$m]++;
            $podios[(int)$ev['id']][$r['puesto']][] = $r;
        }
    }
    $clubes = array_values($clubes);
    usort($clubes, fn($a, $b) => [$b['oro'], $b['plata'], $b['bronce'], $b['puntos'], $a['club']] <=> [$a['oro'], $a['plata'], $a['bronce'], $a['puntos'], $b['club']]);
    $personas = array_values($personas);
    usort($personas, fn($a, $b) => [$b['oro'], $b['plata'], $b['bronce'], $a['p']['nombre']] <=> [$a['oro'], $a['plata'], $a['bronce'], $b['p']['nombre']]);
    return ['clubes' => $clubes, 'personas' => $personas, 'podios' => $podios];
}

/** Clubes ordenados por puntos. */
function pruebas_puntos_clubes(array $t): array
{
    $c = pruebas_medallero($t)['clubes'];
    usort($c, fn($a, $b) => [$b['puntos'], $b['oro'], $a['club']] <=> [$a['puntos'], $a['oro'], $b['club']]);
    return $c;
}

/** Mejor marca de cada prueba (por nombre, rama y categoría). */
function pruebas_mejores(array $t): array
{
    $part = pruebas_participantes((int)$t['id']);
    $r = [];
    foreach (pruebas_eventos((int)$t['id']) as $ev) {
        $res = prueba_resultados($ev, $part);
        if (!$res || $res[0]['puesto'] !== 1) continue;
        $r[] = ['ev' => $ev, 'r' => $res[0]];
    }
    return $r;
}

/** Crea las pruebas del programa: cada prueba elegida × cada rama × cada categoría. Devuelve cuántas creó. */
function pruebas_crear_programa(array $t, array $pruebas, array $ramas, array $cats, string $fecha = ''): int
{
    $tid = (int)$t['id'];
    $num = (int)valor('SELECT COALESCE(MAX(num), 0) FROM prueba_eventos WHERE torneo_id = ?', [$tid]);
    $cat_cat = pruebas_categorias_base((string)$t['modalidad']);
    $catalogo = array_column(pruebas_catalogo((string)$t['modalidad']), 1, 0);
    $formatos = array_column(array_map(fn($x) => [$x[0], $x[2] ?? 'marca'], pruebas_catalogo((string)$t['modalidad'])), 1, 0);
    $n = 0;
    foreach ($cats as $ci) {
        if (!isset($cat_cat[(int)$ci])) continue;
        [$cnom, $emin, $emax] = $cat_cat[(int)$ci];
        foreach ($pruebas as $pn) {
            if (!isset($catalogo[$pn])) continue;
            foreach ($ramas as $ra) {
                if (!isset(pruebas_ramas()[$ra])) continue;
                if (function_exists('pv_rama_fija') && ($rf = pv_rama_fija((string)$t['modalidad'], (string)$pn)) !== null && $ra !== $rf) continue; // (v36) pesas: la rama va en el nombre
                if (fila('SELECT id FROM prueba_eventos WHERE torneo_id = ? AND nombre = ? AND rama = ? AND categoria = ?', [$tid, $pn, $ra, $cnom])) continue;
                q('INSERT INTO prueba_eventos (torneo_id, num, nombre, medida, rama, edad_min, edad_max, categoria, fecha, formato) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$tid, ++$num, $pn, $catalogo[$pn], $ra, $emin, $emax, $cnom, $fecha, $formatos[$pn] ?? 'marca']);
                $n++;
            }
        }
    }
    return $n;
}

/** Inscribe a un competidor en una prueba (si puede). */
function prueba_inscribir(array $t, array $ev, array $p): void
{
    if ($motivo = prueba_no_puede($ev, $p, $t)) throw new RuntimeException($p['nombre'] . ' no puede entrar a ' . prueba_nombre($ev, false) . ': ' . $motivo . '.');
    q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id) VALUES (?, ?)', [$ev['id'], $p['id']]);
}

/* ============================================================
 * HTML (panel y página pública)
 * ============================================================ */

function prueba_html_resultados(array $t, array $ev, array $filas): string
{
    $T = textos_pruebas($t);
    $med = ['', '🥇', '🥈', '🥉'];
    ob_start(); ?>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th><?= e($T['participante']) ?></th><th class="num"><?= function_exists('pv_es_formato') && pv_es_formato($ev) ? e(pv_titulo_columna($ev)) : (function_exists('pista_titulo_columna') ? e(pista_titulo_columna($ev)) : ($ev['medida'] === 'metros' ? 'Marca' : 'Tiempo')) ?></th></tr>
      <?php foreach ($filas as $r): ?>
        <tr><td><?= $r['puesto'] !== null ? ($r['puesto'] <= 3 ? $med[$r['puesto']] : (int)$r['puesto']) : '—' ?></td>
          <td style="white-space:normal"><?= e($r['p']['nombre']) ?><?= $r['p']['club'] !== '' ? '<br><small class="suave">' . e($r['p']['club']) . '</small>' : '' ?><?= $r['nota'] !== '' ? '<br><small class="suave">' . e($r['nota']) . '</small>' : '' ?></td>
          <td class="num"><b><?= $r['estado'] === 'ok' ? e($r['texto'] ?? prueba_fmt_marca($ev['medida'], $r['marca'])) ?: '<span class="suave">por correr</span>' : e(strtoupper($r['estado'])) ?></b><?= ($r['detalle'] ?? '') !== '' ? '<br><small class="suave">' . e($r['detalle']) . '</small>' : '' ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php return (string)ob_get_clean();
}

function pruebas_html_medallero(array $med, string $titulo_personas = 'Competidores con medalla'): string
{
    ob_start();
    if (!$med['clubes']): ?><p class="suave">Todavía no hay resultados.</p><?php return (string)ob_get_clean(); endif; ?>
    <h3>Medallero por club</h3>
    <div class="tabla-scroll"><table class="medallero">
      <tr><th>#</th><th>Club</th><th class="num">🥇</th><th class="num">🥈</th><th class="num">🥉</th><th class="num">Puntos</th></tr>
      <?php foreach ($med['clubes'] as $i => $c): ?>
        <tr><td><?= $i + 1 ?></td><td><?= e($c['club']) ?></td><td class="num"><?= $c['oro'] ?></td><td class="num"><?= $c['plata'] ?></td><td class="num"><?= $c['bronce'] ?></td><td class="num"><b><?= $c['puntos'] ?></b></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($med['personas']): ?>
      <h3><?= e($titulo_personas) ?></h3>
      <div class="tabla-scroll"><table>
        <tr><th>#</th><th>Nombre</th><th>Club</th><th class="num">🥇</th><th class="num">🥈</th><th class="num">🥉</th></tr>
        <?php foreach (array_slice($med['personas'], 0, 30) as $i => $x): ?>
          <tr><td><?= $i + 1 ?></td><td><?= e($x['p']['nombre']) ?></td><td><?= e($x['p']['club']) ?></td><td class="num"><?= $x['oro'] ?></td><td class="num"><?= $x['plata'] ?></td><td class="num"><?= $x['bronce'] ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif;
    return (string)ob_get_clean();
}

function pruebas_html_mejores(array $mejores): string
{
    if (!$mejores) return '<p class="suave">Todavía no hay resultados.</p>';
    ob_start(); ?>
    <div class="tabla-scroll"><table>
      <tr><th>Prueba</th><th>Ganador</th><th class="num">Marca</th></tr>
      <?php foreach ($mejores as $x): ?>
        <tr><td style="white-space:normal"><?= e($x['ev']['nombre']) ?><br><small class="suave"><?= e(trim((pruebas_ramas()[$x['ev']['rama']] ?? '') . ' ' . $x['ev']['categoria'])) ?></small></td>
          <td style="white-space:normal">🥇 <?= e($x['r']['p']['nombre']) ?><?= $x['r']['p']['club'] !== '' ? '<br><small class="suave">' . e($x['r']['p']['club']) . '</small>' : '' ?></td><td class="num"><b><?= e($x['r']['texto'] ?? prueba_fmt_marca($x['ev']['medida'], $x['r']['marca'])) ?></b></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php return (string)ob_get_clean();
}

/* ============================================================
 * EJEMPLO PARA PROBAR
 * ============================================================ */

function pruebas_cargar_ejemplo(array $t): void
{
    if (es_ciclo_pista($t)) { pista_cargar_ejemplo($t); return; } // (v34)
    if (es_pruebas_v36($t)) { pv_cargar_ejemplo($t); return; } // (v36)
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM prueba_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene competidores. El ejemplo solo se carga en uno vacío.');
    $nat = $t['modalidad'] === 'NATAC';
    $pr = $nat ? ['50 m libre', '50 m espalda', '50 m pecho', '100 m libre'] : ['100 m planos', '400 m planos', 'Salto largo', 'Lanzamiento de bala'];
    $anio = preg_match('/(\d{4})/', (string)$t['temporada'], $a) ? (int)$a[1] : (int)date('Y');
    pruebas_crear_programa($t, $pr, ['M', 'F'], [1, 2], date('Y-m-d'));
    $clubes = $nat ? ['Club Delfines', 'Tiburones UIO', 'Academia Acuática Norte'] : ['Club Atlético Quito', 'Liga Cantonal Rumiñahui', 'Escuela Correcaminos'];
    $v = ['Mateo Andrade', 'Sebastián Vera', 'Nicolás Paredes', 'Martín Salazar', 'Daniel Cevallos', 'Joaquín Mena', 'Tomás Herrera', 'Emilio Castillo'];
    $d = ['Valentina Ruiz', 'Camila Guamán', 'Isabella Zambrano', 'Sofía Morales', 'Emilia Chiriboga', 'Antonella Vaca', 'Paula Benítez', 'Daniela Ochoa'];
    mt_srand(2600 + strlen($t['modalidad']));
    foreach ([['M', $v], ['F', $d]] as [$rama, $nombres]) {
        foreach ($nombres as $i => $nom) {
            $edad = $i < 4 ? 11 + ($i % 2) : 13 + ($i % 2);
            q('INSERT INTO prueba_participantes (torneo_id, numero, nombre, club, ciudad, rama, nacimiento) VALUES (?,?,?,?,?,?,?)',
                [$tid, (string)(($rama === 'M' ? 100 : 200) + $i), $nom, $clubes[$i % 3], 'Quito', $rama, ($anio - $edad) . '-0' . (1 + $i % 9) . '-15']);
        }
    }
    $part = pruebas_participantes($tid);
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
    $base = $nat ? ['50 m libre' => 33000, '50 m espalda' => 40000, '50 m pecho' => 45000, '100 m libre' => 75000]
                 : ['100 m planos' => 14200, '400 m planos' => 68000, 'Salto largo' => 4.2, 'Lanzamiento de bala' => 7.5];
    $evs = pruebas_eventos($tid);
    foreach ($evs as $k => $ev) {
        $carril = 0;
        foreach ($part as $p) {
            if (prueba_no_puede($ev, $p, $t) !== '') continue;
            q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id, serie, carril) VALUES (?,?,?,?)', [$ev['id'], $p['id'], 1, ++$carril]);
            if ($k >= count($evs) - 2) continue; // las últimas dos pruebas quedan por correr
            $b = $base[$ev['nombre']];
            $edad = prueba_edad($p['nacimiento'], $t);
            if ($ev['medida'] === 'metros') {
                $marca = round($b * (1 + ($edad - 11) * 0.05) * (0.85 + mt_rand(0, 30) / 100), 2);
            } else {
                $marca = round($b * (1 - ($edad - 11) * 0.03) * (0.92 + mt_rand(0, 16) / 100), -1);
            }
            $estado = mt_rand(0, 25) === 0 ? 'dq' : 'ok';
            q('UPDATE prueba_resultados SET marca = ?, estado = ?, nota = ? WHERE evento_id = ? AND participante_id = ?',
                [$estado === 'ok' ? $marca : null, $estado, $estado === 'dq' ? ($nat ? 'Viraje irregular' : 'Salida en falso') : '', $ev['id'], $p['id']]);
        }
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}
