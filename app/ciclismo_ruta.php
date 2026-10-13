<?php
/*
 * (v34) CICLISMO DE RUTA y MTB con las reglas de la UCI (sobre el motor de carreras: carreras.php)
 *
 * RUTA (modalidad CICLO): cada CARRERA (carrera_fechas) tiene una o varias ETAPAS (= mangas). Cada etapa (ciclo_etapas) es:
 *   linea      → etapa / carrera en línea: orden de llegada, MISMO TIEMPO para el grupo (cortes de menos de 1 s, UCI 2.6.015),
 *                bonificaciones en meta (10-6-4 s por omisión) y puntos por puesto en meta.
 *   cri        → contrarreloj individual: tiempo de cada ciclista, sin bonificaciones ni mismo tiempo.
 *   cre        → contrarreloj por equipos: el tiempo del equipo se toma en el 3.er corredor que cruza; los que llegan antes que él
 *                reciben ese tiempo, los descolgados el suyo.
 *   criterium  → critérium: se gana por PUNTOS de los sprints (más la llegada); el tiempo sirve solo para la general.
 * Fuera de control (OTL): si la etapa tiene límite (% del tiempo del ganador), el que llega fuera queda eliminado de la carrera.
 * Por ciclista y etapa se cargan, además del tiempo: bonificación de sprints intermedios (segundos), puntos de sprints y puntos de montaña.
 *
 * CLASIFICACIONES de cada carrera (por categoría): general por tiempo (suma de etapas − bonificaciones; empate: suma de puestos de
 * etapa y luego puesto en la última etapa), puntos (meta + sprints; empate: más etapas ganadas, luego la general), montaña (empate: la
 * general), mejor joven (los de hasta N años, por omisión 22 = sub-23) y equipos (por club: suma de los 3 mejores tiempos de cada etapa).
 *
 * MTB (modalidad MTB): cada carrera tiene un FORMATO:
 *   xco    → cross-country (XCO / XCC / maratón): gana quien completa más VUELTAS y, entre ellos, el menor tiempo. Los que el
 *            comisario retira por la regla del 80 % o los doblados quedan clasificados con sus vueltas de menos (−1 v, −2 v…).
 *   mejor  → descenso (DH): vale la mejor de las bajadas.
 *   suma   → enduro: suma de los tramos cronometrados.
 */

function ciclo_tipos_etapa(): array
{
    return ['linea' => 'En línea', 'cri' => 'Contrarreloj individual', 'cre' => 'Contrarreloj por equipos', 'criterium' => 'Critérium (por puntos)'];
}

function mtb_formatos(): array
{
    return ['xco' => 'Cross-country (XCO / XCC / maratón): por vueltas y tiempo', 'mejor' => 'Descenso (DH): vale la mejor bajada', 'suma' => 'Enduro: suma de los tramos'];
}

/** Lista "10,6,4" → [10, 6, 4]. */
function ciclo_lista_num(string $s): array
{
    return array_values(array_map('intval', array_filter(preg_split('/[\s,;]+/', trim($s)) ?: [], fn($x) => $x !== '' && is_numeric($x))));
}

/** Configuración de la etapa $m de una carrera (con valores por omisión si no se guardó). */
/** (Si la etapa no se configuró, no hay bonificaciones en meta: así las carreras cargadas antes de la v34 no cambian sus tiempos.) */
function ciclo_etapa(array $f, int $m): array
{
    static $cache = [];
    $k = (int)$f['id'] . ':' . $m;
    if (!isset($cache[$k])) {
        $e = fila('SELECT * FROM ciclo_etapas WHERE fecha_id = ? AND manga = ?', [$f['id'], $m]);
        $cache[$k] = $e ?: ['id' => 0, 'fecha_id' => (int)$f['id'], 'manga' => $m, 'tipo' => 'linea', 'nombre' => '', 'km' => 0, 'limite_pct' => 0,
            'bonif_meta' => '', 'puntos_meta' => '15,12,10,8,6,5,4,3,2,1', 'mismo_tiempo' => 1];
    }
    return $cache[$k];
}

function ciclo_olvidar_etapas(): void
{
    // (se usa en las pruebas automáticas; en una petición normal la configuración no cambia después de leerla)
}

function ciclo_nombre_etapa(array $f, int $m, int $nm): string
{
    $e = ciclo_etapa($f, $m);
    $base = $nm > 1 ? 'Etapa ' . $m : 'Carrera';
    return $base . ($e['nombre'] !== '' ? ' · ' . $e['nombre'] : '') . ($e['tipo'] !== 'linea' ? ' (' . (ciclo_tipos_etapa()[$e['tipo']] ?? '') . ')' : '');
}

/** Edad que cumple en el año de la temporada (o null). */
function ciclo_edad(array $p, array $t): ?int
{
    if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', (string)($p['nacimiento'] ?? ''), $m)) return null;
    $anio = preg_match('/(\d{4})/', (string)$t['temporada'], $a) ? (int)$a[1] : (int)date('Y');
    return $anio - (int)$m[1];
}

/** ¿Entra en la clasificación del mejor joven? (por edad; si no tiene fecha de nacimiento, por el nombre de su categoría). */
function ciclo_es_joven(array $p, array $t, array $cats): bool
{
    $lim = max(0, (int)($t['ciclo_joven'] ?? 22));
    if ($lim === 0) return false;
    $e = ciclo_edad($p, $t);
    if ($e !== null) return $e <= $lim;
    $cn = mb_strtolower((string)($cats[(int)($p['categoria_id'] ?? 0)]['nombre'] ?? ''));
    return (bool)preg_match('/sub.?2[0-3]|juvenil|junior|júnior|prejuvenil|infantil|menores/u', $cn);
}

/**
 * Resultado de UNA etapa: filas ordenadas por llegada con el tiempo de clasificación (mismo tiempo / CRE), puesto, bonificación,
 * puntos y montaña. Cada fila: p, r (resultado crudo), crudo (ms con penalizaciones), clasif (ms), pos, bonif (s), pts, mont, estado, motivo.
 */
function ciclo_calcular_etapa(array $t, array $f, int $m, array $part, ?array $cfg = null): array
{
    $cfg = $cfg ?? carrera_cfg($t);
    $e = ciclo_etapa($f, $m);
    $ok = [];
    $no = [];
    foreach (filas('SELECT * FROM carrera_resultados WHERE fecha_id = ? AND manga = ?', [$f['id'], $m]) as $r) {
        $p = $part[(int)$r['participante_id']] ?? null;
        if (!$p) continue;
        $crudo = carrera_tiempo_final($r, $cfg);
        $x = ['p' => $p, 'r' => $r, 'crudo' => $crudo, 'clasif' => $crudo, 'pos' => null, 'bonif' => 0.0, 'pts' => (int)$r['pts_sprint'], 'mont' => (int)$r['pts_montana'],
              'estado' => $crudo === null ? ($r['estado'] === 'ok' ? 'dnf' : $r['estado']) : 'ok', 'motivo' => (string)($r['motivo'] ?? ''), 'sprint_bonif' => (float)$r['bonif_seg']];
        if ($crudo === null) $no[] = $x; else $ok[] = $x;
    }
    usort($ok, fn($a, $b) => [$a['crudo'], $a['p']['nombre']] <=> [$b['crudo'], $b['p']['nombre']]);
    // Mismo tiempo: si entre un ciclista y el anterior hay menos de 1 segundo, recibe el tiempo del anterior (el del grupo)
    if (in_array($e['tipo'], ['linea', 'criterium'], true) && (int)$e['mismo_tiempo'] === 1) {
        foreach ($ok as $i => &$x) {
            if ($i > 0 && $x['crudo'] - $ok[$i - 1]['crudo'] < 1000) $x['clasif'] = $ok[$i - 1]['clasif'];
        }
        unset($x);
    }
    // Contrarreloj por equipos: el tiempo del equipo es el del 3.er corredor que cruza la meta
    if ($e['tipo'] === 'cre') {
        $por_eq = [];
        foreach ($ok as $i => $x) $por_eq[mb_strtolower(trim((string)$x['p']['club'])) ?: '#' . $x['p']['id']][] = $i;
        foreach ($por_eq as $idx) {
            if (count($idx) < 3) continue;
            $t3 = $ok[$idx[2]]['crudo'];
            foreach ($idx as $i) if ($ok[$i]['crudo'] <= $t3) $ok[$i]['clasif'] = $t3;
        }
        usort($ok, fn($a, $b) => [$a['clasif'], $a['crudo']] <=> [$b['clasif'], $b['crudo']]);
    }
    // Fuera de control: más del % del tiempo del ganador
    if ((float)$e['limite_pct'] > 0 && $ok) {
        $lim = (int)round($ok[0]['clasif'] * (1 + (float)$e['limite_pct'] / 100));
        foreach ($ok as $i => $x) {
            if ($x['clasif'] > $lim) {
                $x['estado'] = 'otl';
                $x['motivo'] = $x['motivo'] !== '' ? $x['motivo'] : 'Llegó fuera del tiempo límite (' . rtrim(rtrim(number_format((float)$e['limite_pct'], 1, ',', ''), '0'), ',') . ' % del ganador)';
                $no[] = $x;
                unset($ok[$i]);
            }
        }
        $ok = array_values($ok);
    }
    $bon = $e['tipo'] === 'linea' ? ciclo_lista_num((string)$e['bonif_meta']) : [];
    $ptm = $e['tipo'] === 'cre' ? [] : ciclo_lista_num((string)$e['puntos_meta']);
    foreach ($ok as $i => &$x) {
        $x['pos'] = $i + 1;
        $x['bonif'] = (float)($bon[$i] ?? 0) + $x['sprint_bonif'];
        $x['pts'] += $ptm[$i] ?? 0;
    }
    unset($x);
    foreach ($no as &$x) { $x['bonif'] = 0.0; } unset($x);
    // Critérium: el orden de la etapa lo dan los puntos (empate: la llegada)
    if ($e['tipo'] === 'criterium') {
        usort($ok, fn($a, $b) => [-$a['pts'], $a['pos']] <=> [-$b['pts'], $b['pos']]);
        foreach ($ok as $i => &$x) $x['pos_etapa'] = $i + 1;
        unset($x);
    } else {
        foreach ($ok as &$x) $x['pos_etapa'] = $x['pos'];
        unset($x);
    }
    $orden_est = ['otl' => 0, 'dnf' => 1, 'dns' => 2, 'dsq' => 3];
    usort($no, fn($a, $b) => [$orden_est[$a['estado']] ?? 9, $a['p']['nombre']] <=> [$orden_est[$b['estado']] ?? 9, $b['p']['nombre']]);
    return ['etapa' => $e, 'ok' => $ok, 'no' => $no];
}

/**
 * Resultado de una carrera de ruta en el formato de carrera_calcular_fecha(): la "valor" es el tiempo de la general
 * (suma de los tiempos de clasificación de las etapas menos las bonificaciones). Solo entra a la general quien terminó todas las etapas.
 */
function ciclo_calcular_fecha(array $t, array $f): array
{
    $cfg = carrera_cfg($t);
    $nm = carrera_mangas_fecha($f, $cfg);
    $part = [];
    foreach (filas('SELECT * FROM carrera_participantes WHERE torneo_id = ?', [$t['id']]) as $p) $part[(int)$p['id']] = $p;
    $por = [];
    $etapas = [];
    for ($m = 1; $m <= $nm; $m++) {
        $et = ciclo_calcular_etapa($t, $f, $m, $part, $cfg);
        $etapas[$m] = $et;
        foreach (array_merge($et['ok'], $et['no']) as $x) $por[(int)$x['p']['id']][$m] = $x;
    }
    $solo_crit = $nm === 1 && ($etapas[1]['etapa']['tipo'] ?? '') === 'criterium';
    $out = [];
    foreach ($por as $pid => $xs) {
        $mangas = [];
        $total = 0;
        $bonif = 0.0;
        $completo = true;
        $estado = 'ok';
        $motivo = '';
        $suma_pos = 0;
        for ($m = 1; $m <= $nm; $m++) {
            $x = $xs[$m] ?? null;
            if (!$x) { $mangas[$m] = null; $completo = false; continue; }
            $r = $x['r'];
            $mangas[$m] = ['final' => $x['estado'] === 'ok' ? $x['clasif'] : null, 'estado' => $x['estado'], 'tiempo_ms' => $r['tiempo_ms'], 'conos' => 0, 'ajuste_seg' => (float)$r['ajuste_seg'],
                'pens_txt' => carrera_texto_pens($r, $cfg), 'motivo' => $x['motivo'], 'pos' => $x['pos_etapa'] ?? null, 'bonif' => $x['bonif'], 'pts' => $x['pts'], 'mont' => $x['mont']];
            if ($x['estado'] !== 'ok') {
                $completo = false;
                if ($estado === 'ok') { $estado = $x['estado']; $motivo = $x['motivo']; }
                continue;
            }
            $total += $x['clasif'];
            $bonif += $x['bonif'];
            $suma_pos += (int)$x['pos'];
        }
        $valor = $completo ? max(0, $total - (int)round($bonif * 1000)) : null;
        if (!$completo && $estado === 'ok') $estado = 'dnf';
        $fila = ['p' => $part[$pid], 'mangas' => $mangas, 'valor' => $valor, 'estado' => $estado, 'mejor' => null, 'motivo' => $motivo, 'bonif' => $bonif, 'suma_pos' => $suma_pos,
                 'ult_pos' => $mangas[$nm]['pos'] ?? 999];
        if ($valor !== null) {
            $fila['clave'] = [$valor, $suma_pos, $fila['ult_pos'], $part[$pid]['nombre']];
            if ($solo_crit) {
                $fila['clave'] = [-(int)$mangas[1]['pts'], (int)($xs[1]['pos'] ?? 999), $part[$pid]['nombre']];
                $fila['texto'] = (int)$mangas[1]['pts'] . ' pts';
            }
        }
        $out[] = $fila;
    }
    return $out;
}

/**
 * Clasificaciones de una carrera de ruta por categoría: etapas, general (la da carrera_clasificacion_fecha), puntos, montaña,
 * mejor joven y equipos. Devuelve [cat_id => ['cat', 'puntos', 'montana', 'joven', 'equipos']] y 'etapas' => [m => resultado].
 */
function ciclo_clasificaciones(array $t, array $f): array
{
    $cfg = carrera_cfg($t);
    $nm = carrera_mangas_fecha($f, $cfg);
    $cats = [];
    foreach (carrera_categorias((int)$t['id']) as $c) $cats[(int)$c['id']] = $c;
    $cl = carrera_clasificacion_fecha($t, $f);
    $part = [];
    foreach (filas('SELECT * FROM carrera_participantes WHERE torneo_id = ?', [$t['id']]) as $p) $part[(int)$p['id']] = $p;
    $etapas = [];
    for ($m = 1; $m <= $nm; $m++) $etapas[$m] = ciclo_calcular_etapa($t, $f, $m, $part, $cfg);
    $out = [];
    foreach ($cl['cats'] as $cid => $c) {
        $gen = $c['filas'];
        $pos_gen = [];
        foreach ($gen as $x) if ($x['pos'] !== null) $pos_gen[(int)$x['p']['id']] = $x['pos'];
        $en_carrera = array_keys($pos_gen); // solo los que siguen en carrera (terminaron todas las etapas)
        // Puntos y montaña
        $pts = [];
        $mont = [];
        $ganadas = [];
        foreach ($etapas as $m => $et) {
            foreach ($et['ok'] as $x) {
                $pid = (int)$x['p']['id'];
                if (!in_array($pid, $en_carrera, true)) continue;
                $pts[$pid] = ($pts[$pid] ?? 0) + $x['pts'];
                if ($x['mont'] > 0) $mont[$pid] = ($mont[$pid] ?? 0) + $x['mont'];
                if (($x['pos_etapa'] ?? 0) === 1) $ganadas[$pid] = ($ganadas[$pid] ?? 0) + 1;
            }
        }
        $mk = function (array $tabla, bool $con_ganadas) use ($part, $pos_gen, $ganadas): array {
            $l = [];
            foreach ($tabla as $pid => $v) if ($v > 0) $l[] = ['p' => $part[$pid], 'valor' => $v, 'ganadas' => $ganadas[$pid] ?? 0, 'gen' => $pos_gen[$pid] ?? 999];
            usort($l, fn($a, $b) => $con_ganadas ? [-$a['valor'], -$a['ganadas'], $a['gen']] <=> [-$b['valor'], -$b['ganadas'], $b['gen']] : [-$a['valor'], $a['gen']] <=> [-$b['valor'], $b['gen']]);
            foreach ($l as $i => &$x) $x['pos'] = $i + 1;
            unset($x);
            return $l;
        };
        // Mejor joven: la general solo con los jóvenes
        $joven = [];
        foreach ($gen as $x) if ($x['pos'] !== null && ciclo_es_joven($x['p'], $t, $cats)) $joven[] = $x;
        $lider_j = $joven ? $joven[0]['valor'] : null;
        foreach ($joven as $i => &$x) { $x['pos_j'] = $i + 1; $x['dif_j'] = $x['valor'] - $lider_j; }
        unset($x);
        // Equipos: suma de los 3 mejores tiempos de cada etapa (por club); el equipo con menos de 3 en una etapa queda fuera
        $eq = [];
        $fuera = [];
        foreach ($etapas as $m => $et) {
            $tiempos = [];
            foreach ($et['ok'] as $x) {
                if ((int)($x['p']['categoria_id'] ?? 0) !== (int)$cid && !((int)$cid === 0 && !isset($cats[(int)($x['p']['categoria_id'] ?? 0)]))) continue;
                $club = trim((string)$x['p']['club']);
                if ($club === '') continue;
                $tiempos[$club][] = ['ms' => $x['clasif'], 'pos' => $x['pos']];
            }
            $clubes_cat = [];
            foreach ($part as $p) if ((int)($p['categoria_id'] ?? 0) === (int)$cid && trim((string)$p['club']) !== '') $clubes_cat[trim((string)$p['club'])] = true;
            foreach (array_keys($clubes_cat) as $club) {
                $ts = $tiempos[$club] ?? [];
                usort($ts, fn($a, $b) => $a['ms'] <=> $b['ms']);
                if (count($ts) < 3) { $fuera[$club] = true; continue; }
                $eq[$club]['ms'] = ($eq[$club]['ms'] ?? 0) + $ts[0]['ms'] + $ts[1]['ms'] + $ts[2]['ms'];
                $eq[$club]['pos'] = ($eq[$club]['pos'] ?? 0) + $ts[0]['pos'] + $ts[1]['pos'] + $ts[2]['pos'];
            }
        }
        $equipos = [];
        foreach ($eq as $club => $d) if (!isset($fuera[$club])) $equipos[] = ['club' => $club, 'ms' => $d['ms'], 'suma_pos' => $d['pos']];
        usort($equipos, fn($a, $b) => [$a['ms'], $a['suma_pos'], $a['club']] <=> [$b['ms'], $b['suma_pos'], $b['club']]);
        foreach ($equipos as $i => &$x) { $x['pos'] = $i + 1; $x['dif'] = $x['ms'] - $equipos[0]['ms']; }
        unset($x);
        $out[$cid] = ['cat' => $c['cat'], 'general' => $gen, 'puntos' => $mk($pts, true), 'montana' => $mk($mont, false), 'joven' => $joven, 'equipos' => $equipos];
    }
    return ['cats' => $out, 'etapas' => $etapas, 'nm' => $nm];
}

/* ============================================================
 * MTB
 * ============================================================ */

/** Formato de una carrera de MTB (o '' para las de otro deporte). */
function mtb_formato(array $t, array $f): string
{
    if (($t['modalidad'] ?? '') !== 'MTB') return '';
    $fo = (string)($f['formato'] ?? '');
    return isset(mtb_formatos()[$fo]) ? $fo : 'xco';
}

/** Ajusta el cálculo de una carrera XCO: más vueltas primero; los doblados o retirados por el 80 % quedan con sus vueltas de menos. */
function mtb_aplicar_vueltas(array $f, array $lista): array
{
    $total = (int)($f['vueltas_total'] ?? 0);
    $max = 0;
    foreach ($lista as $x) $max = max($max, (int)($x['vueltas'] ?? 0));
    $total = max($total, $max);
    foreach ($lista as &$x) {
        if ($x['valor'] === null) continue;
        $v = (int)($x['vueltas'] ?? 0);
        if ($v <= 0) $v = $total; // sin vueltas escritas: se asume que completó la carrera
        $menos = max(0, $total - $v);
        $x['clave'] = [$menos, $x['valor'], $x['p']['nombre']];
        $x['vueltas_menos'] = $menos;
        if ($menos > 0) $x['texto'] = '−' . $menos . ' ' . ($menos === 1 ? 'vuelta' : 'vueltas');
    }
    unset($x);
    return $lista;
}

/* ============================================================
 * HTML: clasificaciones de ruta
 * ============================================================ */

function ciclo_html_etapa(array $et, array $T, int $m, int $nm, array $f): string
{
    $e = $et['etapa'];
    $crit = $e['tipo'] === 'criterium';
    $filas = $et['ok'];
    if ($crit) usort($filas, fn($a, $b) => $a['pos_etapa'] <=> $b['pos_etapa']);
    $lider = $filas ? min(array_column($filas, 'clasif')) : null;
    $hay_mont = (bool)array_filter($filas, fn($x) => $x['mont'] > 0);
    ob_start(); ?>
    <div class="tabla-scroll"><table class="tabla-carrera">
      <tr><th>#</th><th><?= e($T['participante']) ?></th><?php if ($crit): ?><th class="num">Puntos</th><?php endif; ?><th class="num">Tiempo</th><th class="num">Dif.</th>
        <?php if ($e['tipo'] === 'linea'): ?><th class="num col-manga" title="Bonificación (meta + sprints)">Bonif.</th><?php endif; ?><?php if (!$crit && $e['tipo'] !== 'cre'): ?><th class="num col-manga">Pts</th><?php endif; ?><?php if ($hay_mont): ?><th class="num col-manga">⛰</th><?php endif; ?></tr>
      <?php foreach ($filas as $x): ?>
        <tr><td><?= (int)$x['pos_etapa'] ?></td><td><?= carrera_celda_participante($x['p'], false) ?></td>
          <?php if ($crit): ?><td class="num pts"><?= (int)$x['pts'] ?></td><?php endif; ?>
          <td class="num"><b><?= e(carrera_fmt_tiempo($x['clasif'])) ?></b><?= $x['clasif'] !== $x['crudo'] ? '<br><small class="suave" title="Tiempo real (recibe el tiempo del grupo)">' . e(carrera_fmt_tiempo($x['crudo'])) . '</small>' : '' ?></td>
          <td class="num suave"><?= $x['clasif'] === $lider ? ($x['pos_etapa'] > 1 ? 'm.t.' : '') : e(carrera_fmt_dif($x['clasif'] - $lider)) ?></td>
          <?php if ($e['tipo'] === 'linea'): ?><td class="num col-manga"><?= $x['bonif'] > 0 ? '−' . e(rtrim(rtrim(number_format($x['bonif'], 1, ',', ''), '0'), ',')) . ' s' : '' ?></td><?php endif; ?>
          <?php if (!$crit && $e['tipo'] !== 'cre'): ?><td class="num col-manga"><?= $x['pts'] ?: '' ?></td><?php endif; ?>
          <?php if ($hay_mont): ?><td class="num col-manga"><?= $x['mont'] ?: '' ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      <?php foreach ($et['no'] as $x): ?>
        <tr><td>—</td><td><?= carrera_celda_participante($x['p'], false) ?><?= $x['motivo'] !== '' ? '<br><small class="suave">' . e($x['motivo']) . '</small>' : '' ?></td><?php if ($crit): ?><td></td><?php endif; ?>
          <td class="num"><span class="etiqueta roja"><?= e(strtoupper($x['estado'])) ?></span></td><td></td>
          <?php if ($e['tipo'] === 'linea'): ?><td class="col-manga"></td><?php endif; ?><?php if (!$crit && $e['tipo'] !== 'cre'): ?><td class="col-manga"></td><?php endif; ?><?php if ($hay_mont): ?><td class="col-manga"></td><?php endif; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <p class="ayuda" style="margin-top:6px"><?= e(ciclo_ayuda_etapa($e)) ?></p>
    <?php return carrera_css_movil() . (string)ob_get_clean();
}

function ciclo_ayuda_etapa(array $e): string
{
    $t = [];
    if ($e['tipo'] === 'linea') {
        if ((int)$e['mismo_tiempo'] === 1) $t[] = 'm.t. = mismo tiempo (llegó en el grupo: menos de 1 s con el de adelante)';
        if (ciclo_lista_num((string)$e['bonif_meta'])) $t[] = 'bonificación en meta ' . implode('-', ciclo_lista_num((string)$e['bonif_meta'])) . ' s';
    }
    if ($e['tipo'] === 'cre') $t[] = 'el tiempo de cada equipo se toma en su 3.er corredor';
    if ($e['tipo'] === 'criterium') $t[] = 'gana quien suma más puntos en los sprints y la llegada';
    if ($e['tipo'] !== 'cre' && ciclo_lista_num((string)$e['puntos_meta'])) $t[] = 'puntos en meta ' . implode('-', array_slice(ciclo_lista_num((string)$e['puntos_meta']), 0, 6)) . (count(ciclo_lista_num((string)$e['puntos_meta'])) > 6 ? '…' : '');
    if ((float)$e['limite_pct'] > 0) $t[] = 'fuera de control: más de ' . rtrim(rtrim(number_format((float)$e['limite_pct'], 1, ',', ''), '0'), ',') . ' % del tiempo del ganador';
    return $t ? ucfirst(implode(' · ', $t)) . '.' : '';
}

function ciclo_html_puntos(array $l, string $col, string $T_part): string
{
    if (!$l) return '<p class="suave">Sin puntos todavía.</p>';
    ob_start(); ?>
    <div class="tabla-scroll"><table><tr><th>#</th><th><?= e($T_part) ?></th><th class="num"><?= e($col) ?></th></tr>
      <?php foreach ($l as $x): ?><tr><td><?= (int)$x['pos'] ?></td><td><?= carrera_celda_participante($x['p'], false) ?></td><td class="num pts"><?= (int)$x['valor'] ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php return (string)ob_get_clean();
}

function ciclo_html_joven(array $l, string $T_part): string
{
    if (!$l) return '<p class="suave">No hay ciclistas jóvenes en la general.</p>';
    ob_start(); ?>
    <div class="tabla-scroll"><table><tr><th>#</th><th><?= e($T_part) ?></th><th class="num">Tiempo</th><th class="num">Dif.</th></tr>
      <?php foreach ($l as $x): ?><tr><td><?= (int)$x['pos_j'] ?></td><td><?= carrera_celda_participante($x['p'], false) ?></td><td class="num"><b><?= e(carrera_fmt_tiempo($x['valor'])) ?></b></td><td class="num suave"><?= e(carrera_fmt_dif($x['dif_j'])) ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php return (string)ob_get_clean();
}

function ciclo_html_equipos(array $l): string
{
    if (!$l) return '<p class="suave">Para la clasificación por equipos cada club necesita al menos 3 ciclistas que terminen cada etapa (escriba el club de cada ciclista).</p>';
    ob_start(); ?>
    <div class="tabla-scroll"><table><tr><th>#</th><th>Equipo (club)</th><th class="num">Tiempo</th><th class="num">Dif.</th></tr>
      <?php foreach ($l as $x): ?><tr><td><?= (int)$x['pos'] ?></td><td><?= e($x['club']) ?></td><td class="num"><b><?= e(carrera_fmt_tiempo($x['ms'])) ?></b></td><td class="num suave"><?= e(carrera_fmt_dif($x['dif'])) ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php return (string)ob_get_clean();
}

/** Bloque completo de una carrera de ruta: etapas y clasificaciones (panel y página pública). */
function ciclo_html_carrera(array $t, array $f): string
{
    $T = textos_carrera($t);
    $cfg = carrera_cfg($t);
    $cl = ciclo_clasificaciones($t, $f);
    $nm = $cl['nm'];
    if (!array_filter($cl['etapas'], fn($et) => $et['ok'] || $et['no'])) return '';
    $edad_j = (int)($t['ciclo_joven'] ?? 22);
    ob_start();
    foreach ($cl['cats'] as $c):
        $hay_pts = (bool)$c['puntos'];
        $hay_mont = (bool)$c['montana']; ?>
      <div class="tarjeta" style="margin-bottom:14px">
        <h3 style="margin-top:0"><?= e($c['cat']['nombre']) ?></h3>
        <h4 style="margin:.4rem 0">🟡 General<?= $nm > 1 ? ' (tiempo acumulado − bonificaciones)' : '' ?></h4>
        <?= carrera_html_tabla_fecha($c['general'], $t, $f) ?>
        <?php if ($nm > 1 || $hay_pts || $hay_mont): ?>
        <div class="rejilla-2" style="margin-top:10px">
          <?php if ($hay_pts): ?><div><h4 style="margin:.4rem 0">🟢 Puntos</h4><?= ciclo_html_puntos($c['puntos'], 'Pts', $T['participante']) ?></div><?php endif; ?>
          <?php if ($hay_mont): ?><div><h4 style="margin:.4rem 0">🔴 Montaña</h4><?= ciclo_html_puntos($c['montana'], 'Pts', $T['participante']) ?></div><?php endif; ?>
          <?php if ($edad_j > 0 && $c['joven'] && count($c['joven']) < count(array_filter($c['general'], fn($x) => $x['pos'] !== null))): ?><div><h4 style="margin:.4rem 0">⚪ Mejor joven (hasta <?= $edad_j ?> años)</h4><?= ciclo_html_joven($c['joven'], $T['participante']) ?></div><?php endif; ?>
          <?php if ($c['equipos']): ?><div><h4 style="margin:.4rem 0">👥 Equipos</h4><?= ciclo_html_equipos($c['equipos']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach;
    if ($nm > 1): ?>
      <h3>Resultados de cada etapa</h3>
      <?php foreach ($cl['etapas'] as $m => $et): if (!$et['ok'] && !$et['no']) continue; ?>
        <details class="tarjeta" style="margin-bottom:10px"<?= $m === max(array_keys(array_filter($cl['etapas'], fn($x) => $x['ok']))) ? ' open' : '' ?>><summary><b><?= e(ciclo_nombre_etapa($f, $m, $nm)) ?></b><?= (float)$et['etapa']['km'] > 0 ? ' <span class="suave">· ' . e(rtrim(rtrim(number_format((float)$et['etapa']['km'], 1, ',', ''), '0'), ',')) . ' km</span>' : '' ?><?= $et['ok'] ? ' <span class="suave">· ganó ' . e($et['ok'][0]['p']['nombre']) . '</span>' : '' ?></summary>
          <?= ciclo_html_etapa($et, $T, $m, $nm, $f) ?></details>
      <?php endforeach;
    endif;
    return (string)ob_get_clean();
}

/* ============================================================
 * EJEMPLO DE MTB
 * ============================================================ */

function mtb_cargar_ejemplo(array $t): void
{
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM carrera_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene participantes. El ejemplo solo se carga en uno vacío.');
    carrera_cargar_categorias_base($t);
    $cat = fn(string $n) => (int)valor('SELECT id FROM carrera_categorias WHERE torneo_id = ? AND nombre = ?', [$tid, $n]) ?: null;
    $gente = [['1', 'Andrés Cusme', 'MTB Pichincha', 'Élite'], ['2', 'Jonathan Caicedo', 'Andes Bike', 'Élite'], ['3', 'Sebastián Novoa', 'MTB Pichincha', 'Élite'],
              ['4', 'Luis Guerra', 'Andes Bike', 'Élite'], ['5', 'Kevin Pozo', 'Sierra Trail', 'Élite'], ['6', 'Diego Lema', 'Sierra Trail', 'Élite'],
              ['11', 'Ana Rosero', 'MTB Pichincha', 'Damas élite'], ['12', 'Paola Yépez', 'Andes Bike', 'Damas élite'], ['13', 'Diana Quinga', 'Sierra Trail', 'Damas élite']];
    $ids = [];
    foreach ($gente as [$num, $nom, $club, $c]) {
        q('INSERT INTO carrera_participantes (torneo_id, categoria_id, numero, nombre, club, ciudad, vehiculo) VALUES (?,?,?,?,?,?,?)', [$tid, $cat($c), $num, $nom, $club, 'Quito', 'Doble suspensión']);
        $ids[] = (int)db()->lastInsertId();
    }
    mt_srand(3434);
    // XCO: 5 vueltas; el 6 retirado por el 80 % con 3 vueltas; una dama doblada
    q("INSERT INTO carrera_fechas (torneo_id, num, nombre, fecha, lugar, mangas, formato, vueltas_total) VALUES (?, 1, 'XCO · Parque Metropolitano', ?, 'Quito', 1, 'xco', 5)", [$tid, date('Y-m-d', strtotime('-14 days'))]);
    $f1 = (int)db()->lastInsertId();
    foreach ($ids as $k => $pid) {
        $v = 5; $ms = 5100000 + $k * 95000 + mt_rand(0, 60000); $motivo = '';
        if ($k === 5) { $v = 3; $ms = 3400000; $motivo = 'Retirado por la regla del 80 %'; }
        if ($k === 8) { $v = 4; $ms = 5300000; $motivo = 'Doblada'; }
        q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, estado, vueltas, motivo) VALUES (?,?,1,?,?,?,?)', [$f1, $pid, $ms, 'ok', $v, $motivo]);
    }
    // DH: 2 bajadas, vale la mejor
    q("INSERT INTO carrera_fechas (torneo_id, num, nombre, fecha, lugar, mangas, formato) VALUES (?, 2, 'Descenso · Lloa', ?, 'Lloa', 2, 'mejor')", [$tid, date('Y-m-d', strtotime('-7 days'))]);
    $f2 = (int)db()->lastInsertId();
    foreach ($ids as $k => $pid) {
        for ($m = 1; $m <= 2; $m++) {
            $est = ($k === 2 && $m === 1) ? 'dnf' : 'ok';
            q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, estado, motivo) VALUES (?,?,?,?,?,?)', [$f2, $pid, $m, $est === 'ok' ? 182000 + $k * 2400 + mt_rand(0, 4000) - $m * 1500 : null, $est, $est === 'dnf' ? 'Caída en el tramo 2' : '']);
        }
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}

/* ============================================================
 * EJEMPLO DE RUTA (vuelta por etapas)
 * ============================================================ */

function ciclo_cargar_ejemplo(array $t): void
{
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM carrera_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene participantes. El ejemplo solo se carga en uno vacío.');
    carrera_cargar_categorias_base($t);
    carrera_cargar_penalidades_base($t);
    $anio = preg_match('/(\d{4})/', (string)$t['temporada'], $a) ? (int)$a[1] : (int)date('Y');
    $elite = (int)valor("SELECT id FROM carrera_categorias WHERE torneo_id = ? AND nombre = 'Élite'", [$tid]);
    $gente = [['1', 'Santiago Guamán', 'Pichincha Pro Team', 27], ['2', 'Marcelo Chiliquinga', 'Andes Cycling', 31], ['3', 'Fernando Toapanta', 'Pichincha Pro Team', 21],
              ['4', 'Bryan Quishpe', 'Sierra Norte', 20], ['5', 'Kevin Tipán', 'Andes Cycling', 24], ['6', 'Wilson Llumiquinga', 'Sierra Norte', 29],
              ['7', 'Jefferson Cepeda', 'Pichincha Pro Team', 22], ['8', 'Alexander Cadena', 'Andes Cycling', 26], ['9', 'Richard Ochoa', 'Sierra Norte', 19]];
    $ids = [];
    foreach ($gente as [$num, $nom, $club, $edad]) {
        q('INSERT INTO carrera_participantes (torneo_id, categoria_id, numero, nombre, club, ciudad, vehiculo, nacimiento) VALUES (?,?,?,?,?,?,?,?)', [$tid, $elite ?: null, $num, $nom, $club, 'Quito', 'Ruta', ($anio - $edad) . '-05-10']);
        $ids[] = (int)db()->lastInsertId();
    }
    q("INSERT INTO carrera_fechas (torneo_id, num, nombre, fecha, lugar, mangas) VALUES (?, 1, 'Vuelta a Pichincha', ?, 'Pichincha', 3)", [$tid, date('Y-m-d', strtotime('-10 days'))]);
    $fid = (int)db()->lastInsertId();
    $etapas = [[1, 'cri', 'Prólogo · Quito', 8, 0, '', '10,8,6,4,2'], [2, 'linea', 'Cayambe – Ibarra', 142, 12, '10,6,4', '15,12,10,8,6,5,4,3,2,1'], [3, 'linea', 'Subida a Papallacta', 96, 15, '10,6,4', '15,12,10,8,6,5,4,3,2,1']];
    foreach ($etapas as [$m, $tipo, $nom, $km, $lim, $bon, $pts]) {
        q('INSERT INTO ciclo_etapas (fecha_id, manga, tipo, nombre, km, limite_pct, bonif_meta, puntos_meta, mismo_tiempo) VALUES (?,?,?,?,?,?,?,?,1)', [$fid, $m, $tipo, $nom, $km, $lim, $bon, $pts]);
    }
    mt_srand(3412);
    foreach ($ids as $k => $pid) {
        // Prólogo: contrarreloj
        q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, estado) VALUES (?,?,1,?,?)', [$fid, $pid, 612000 + $k * 3100 + mt_rand(0, 2500), 'ok']);
        // Etapa 2: llegada en grupo (los 5 primeros con menos de 1 s entre ellos), sprints y montaña
        $t2 = $k < 5 ? 12600000 + $k * 300 : 12600000 + 45000 + $k * 2000 + mt_rand(0, 900);
        $est2 = 'ok';
        $mo2 = '';
        if ($k === 8) { $t2 = 12600000 * 1.14; $mo2 = ''; }
        q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, estado, bonif_seg, pts_sprint, pts_montana, motivo) VALUES (?,?,2,?,?,?,?,?,?)',
            [$fid, $pid, (int)$t2, $est2, $k === 2 ? 3 : ($k === 6 ? 2 : 0), $k === 2 ? 5 : ($k === 6 ? 3 : 0), $k === 3 ? 10 : ($k === 1 ? 6 : 0), $mo2]);
        // Etapa 3: llegada en alto, separaciones grandes
        if ($k === 8) continue; // quedó fuera de control en la etapa 2
        q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, estado, pts_montana) VALUES (?,?,3,?,?,?)', [$fid, $pid, 9000000 + [3, 0, 5, 1, 7, 6, 2, 4][$k] * 21000 + mt_rand(0, 3000), 'ok', [10, 6, 4, 2, 1, 0, 0, 0][$k] ?? 0]);
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}
