<?php
/*
 * VERSIÓN 29 — FIXTURE COMPLETO IMPRIMIBLE Y ESQUEMA DEL CAMPEONATO (solo plan Profesional)
 *
 *  - Módulo nuevo 'fixture_pro' (lista de módulos de la v12). Lo tiene el plan Profesional (todos los módulos).
 *    NO lo tienen Básico, Torneo, la liga de demostración ni las cuentas en demo de 7 días (decisión del cliente:
 *    "pueden pedir solo para hacer el modelo del campeonato y listo"). El superadministrador puede regalarlo a una liga
 *    en Ligas → Editar → Módulos extra.
 *  - ESQUEMA DEL CAMPEONATO desde el primer día: fase regular (grupos, equipos, jornadas y partidos) y la llave de la
 *    fase final con casilleros "1° Grupo A vs 2° Grupo B" → "Ganador Semifinal 1 vs Ganador Semifinal 2", que se llenan
 *    solos con los equipos reales a medida que se juega. La fase final prevista se guarda en torneos.llaves_por_grupo
 *    (cuántos clasifican por grupo; 0 = sin fase final) y torneos.llaves_tercer.
 *  - FIXTURE COMPLETO en PDF: todas las jornadas (fecha, hora, cancha, local, resultado, visitante, quién descansa) y las
 *    rondas finales, con resultados o en blanco para llenar a mano. En el panel y en la página pública.
 */

/* ============================================================
 * 1) ¿PUEDE USARLO ESTA LIGA?
 * ============================================================ */

/**
 * Motivo por el que la liga NO puede usar el fixture imprimible y el esquema ('' = sí puede).
 * $publico = true: para la página pública (no cuenta que el visitante sea el superadministrador).
 */
function fixture_pro_motivo(?array $liga, bool $publico = false): string
{
    if (!$publico && es_super()) return '';
    if (!$liga) return 'Elija primero una liga.';
    if (es_escuela($liga)) return 'El fixture imprimible y el esquema del campeonato son para ligas y campeonatos.';
    if (!empty($liga['es_demo'])) return 'El fixture completo para imprimir y el esquema del campeonato son exclusivos del plan Profesional: no están en la demostración.';
    if (!empty($liga['es_prueba']) || estado_suscripcion($liga)['estado'] === 'prueba') {
        return 'El fixture completo para imprimir y el esquema del campeonato son exclusivos del plan Profesional: se activan al contratarlo (no están en la demo de 7 días).';
    }
    if (!modulo_activo($liga, 'fixture_pro')) return 'Su plan no incluye el fixture completo para imprimir ni el esquema del campeonato: son exclusivos del plan Profesional.';
    return '';
}

function fixture_pro_activo(?array $liga, bool $publico = false): bool
{
    return fixture_pro_motivo($liga, $publico) === '';
}

/** Caja "🔒 exclusivo del plan Profesional" con el botón para contratar (panel). */
function html_bloqueo_fixture_pro(array $liga, string $motivo): string
{
    $wa = telefono_wa((string)ajuste('ventas_whatsapp'));
    $h = '<p style="margin:.3rem 0">🔒 ' . e($motivo) . '</p>'
        . '<p class="suave" style="margin:.3rem 0 .6rem">Con el plan Profesional imprime el fixture completo de cada campeonato (con o sin resultados) y ve el esquema de todo el torneo desde el primer día: grupos, jornadas y la llave de la fase final con los cruces, que se llenan solos con los clasificados.</p>'
        . '<div class="acciones">';
    $h .= empty($liga['es_demo']) ? '<a class="btn btn-chico" href="admin.php?p=mi_plan">⭐ Ver el plan Profesional</a>' : '<a class="btn btn-chico" href="' . e(url_empezar()) . '">Solicitar mi cuenta</a>';
    if ($wa) $h .= ' <a class="btn btn-sec btn-chico" target="_blank" rel="noopener" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode('Hola, quiero el plan Profesional de ' . marca() . ' para ' . $liga['nombre']) . '">WhatsApp de ventas</a>';
    return $h . '</div>';
}

/** Pantalla completa de bloqueo (si alguien abre el documento directo por la dirección). */
function pantalla_bloqueo_fixture_pro(array $liga, string $motivo, string $volver): void
{
    cabecera_admin('Fixture y esquema del campeonato');
    echo '<p><a href="' . e($volver) . '">← Volver</a></p><h1>🗺 Fixture y esquema del campeonato</h1><div class="tarjeta" style="max-width:720px">'
        . html_bloqueo_fixture_pro($liga, $motivo) . '</div>';
    pie_admin();
}

/* ============================================================
 * 2) DATOS DEL ESQUEMA
 * ============================================================ */

/** Nombre corto de una ronda para los casilleros: "Cuartos", "Semifinal"... */
function ronda_corta(string $fase): string
{
    return ['octavos' => 'Octavos', 'cuartos' => 'Cuartos', 'semifinal' => 'Semifinal', 'final' => 'Final', 'tercer' => 'Tercer lugar'][$fase] ?? nombre_fase($fase);
}

/** Rondas de la llave desde la primera hasta la final, según cuántos equipos entran (2, 4, 8 o 16). */
function rondas_llave(int $total): array
{
    $r = [];
    for ($f = fase_por_equipos($total); $f !== null; $f = siguiente_fase($f)) $r[] = $f;
    return $r;
}

/** Equipos inscritos por grupo ('' = tabla única). Si el campeonato es por grupos y aún no hay sorteo, quedan los grupos previstos vacíos. */
function grupos_esquema(array $t): array
{
    $eqs = filas('SELECT te.grupo, e.id, e.nombre, e.escudo, e.color FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id
                  WHERE te.torneo_id = ? ORDER BY te.grupo, te.orden_sorteo, e.nombre', [$t['id']]);
    $g = [];
    foreach ($eqs as $e) $g[(string)$e['grupo']][] = $e;
    $sin_sorteo = false;
    $por_grupos = $t['formato'] === 'grupos' && (int)$t['num_grupos'] > 1;
    if ($por_grupos && (!$g || array_keys($g) === [''])) {
        $sin_sorteo = true;
        $sueltos = $g[''] ?? [];
        $g = [];
        foreach (letras_grupos((int)$t['num_grupos']) as $l) $g[$l] = [];
        if ($sueltos) $g['?'] = $sueltos;
    }
    if (!$g) $g[''] = [];
    ksort($g);
    return ['grupos' => $g, 'sin_sorteo' => $sin_sorteo];
}

/** Jornadas y partidos que tendrá un grupo de $n equipos (todos contra todos). */
function jornadas_previstas(int $n, int $vueltas): array
{
    if ($n < 2) return [0, 0];
    return [($n % 2 === 0 ? $n - 1 : $n) * $vueltas, intdiv($n * ($n - 1), 2) * $vueltas];
}

/**
 * Cruces previstos de la primera ronda, con el mismo método que crear_llaves() (v8): primero todos los 1º, luego los 2º
 * (rotando los grupos), siembra 1 contra el último y sin cruces del mismo grupo en la primera ronda.
 * Devuelve [[['pos' => 1, 'grupo' => 'A'], ['pos' => 2, 'grupo' => 'B']], ...].
 */
function cruces_previstos(array $letras, int $por_grupo): array
{
    $n = count($letras);
    $total = $por_grupo * $n;
    $siembra = [];
    if ($n <= 1) {
        for ($i = 1; $i <= $total; $i++) $siembra[] = ['pos' => $i, 'grupo' => ''];
    } else {
        for ($pos = 0; $pos < $por_grupo; $pos++) {
            $lista = array_map(fn($l) => ['pos' => $pos + 1, 'grupo' => (string)$l], $letras);
            if ($pos % 2 === 1) $lista = array_reverse($lista);
            foreach ($lista as $x) $siembra[] = $x;
        }
    }
    $orden = orden_siembra($total);
    $cruces = [];
    for ($i = 0; $i < $total; $i += 2) $cruces[] = [$siembra[$orden[$i] - 1], $siembra[$orden[$i + 1] - 1]];
    for ($i = 0; $i < count($cruces); $i++) {
        if ($cruces[$i][0]['grupo'] === '' || $cruces[$i][0]['grupo'] !== $cruces[$i][1]['grupo']) continue;
        for ($j = 0; $j < count($cruces); $j++) {
            if ($j === $i) continue;
            if ($cruces[$j][1]['grupo'] !== $cruces[$i][0]['grupo'] && $cruces[$i][1]['grupo'] !== $cruces[$j][0]['grupo']) {
                [$cruces[$i][1], $cruces[$j][1]] = [$cruces[$j][1], $cruces[$i][1]];
                break;
            }
        }
    }
    return $cruces;
}

/** Opciones válidas de "clasifican por grupo" para $n grupos: [por_grupo => total]. */
function opciones_clasifican(int $n_grupos): array
{
    $r = [];
    foreach ([1, 2, 4, 8, 16] as $k) {
        $tot = $k * max(1, $n_grupos);
        if (in_array($tot, [2, 4, 8, 16], true)) $r[$k] = $tot;
    }
    return $r;
}

/**
 * Todo el esquema del campeonato:
 *  'grupos' => [letra => [equipos]], 'sin_sorteo', 'regular' => [letra => [jornadas, partidos]], 'jornadas', 'partidos',
 *  'desde', 'hasta', 'final' => null | ['total', 'por_grupo', 'tercer', 'real', 'rondas' => [fase => [pos => casillero]], 'tercer_partido' => casillero|null]
 * Un casillero: ['etiqueta' => 'Semifinal 1', 'a' => lado, 'b' => lado, 'partido' => fila|null];
 * un lado: ['equipo' => [id, nombre, escudo, color]] o ['texto' => '1° Grupo A'].
 */
function esquema_campeonato(array $t): array
{
    $tid = (int)$t['id'];
    $ge = grupos_esquema($t);
    $grupos = $ge['grupos'];
    $vueltas = max(1, (int)$t['vueltas']);
    $regular = [];
    $reales = filas("SELECT grupo, MAX(jornada) AS j, COUNT(*) AS n, MIN(NULLIF(fecha, '')) AS desde, MAX(NULLIF(fecha, '')) AS hasta FROM partidos WHERE torneo_id = ? AND fase = 'regular' GROUP BY grupo", [$tid]);
    $por_grupo_real = [];
    foreach ($reales as $r) $por_grupo_real[(string)$r['grupo']] = $r;
    $jor = 0;
    $tot = 0;
    $n_sueltos = count($grupos['?'] ?? []);
    $n_letras = count(array_filter(array_keys($grupos), fn($l) => $l !== '?'));
    foreach ($grupos as $l => $eqs) {
        if ($l === '?') continue;
        if (isset($por_grupo_real[$l])) {
            $regular[$l] = [(int)$por_grupo_real[$l]['j'], (int)$por_grupo_real[$l]['n'], true];
        } else {
            // Sin fixture todavía: con los equipos del grupo, o (sin sorteo) repartidos en partes iguales
            $n = $ge['sin_sorteo'] ? (int)ceil($n_sueltos / max(1, $n_letras)) : count($eqs);
            [$j, $p] = jornadas_previstas($n, $vueltas);
            $regular[$l] = [$j, $p, false];
        }
        $jor = max($jor, $regular[$l][0]);
        $tot += $regular[$l][1];
    }
    $rango = fila("SELECT MIN(NULLIF(fecha, '')) AS desde, MAX(NULLIF(fecha, '')) AS hasta FROM partidos WHERE torneo_id = ?", [$tid]) ?? [];
    return [
        'grupos' => $grupos, 'sin_sorteo' => $ge['sin_sorteo'], 'regular' => $regular, 'jornadas' => $jor, 'partidos' => $tot,
        'desde' => $rango['desde'] ?? null, 'hasta' => $rango['hasta'] ?? null,
        'final' => esquema_fase_final($t, array_values(array_filter(array_keys($grupos), fn($l) => $l !== '?'))),
    ];
}

/** Fase final del esquema: la llave real si ya se creó, o la prevista (torneos.llaves_por_grupo) con casilleros. */
function esquema_fase_final(array $t, array $letras): ?array
{
    $tid = (int)$t['id'];
    $reales = [];
    foreach (partidos_torneo($tid, 'AND p.llave_pos IS NOT NULL') as $p) $reales[$p['fase']][(int)$p['llave_pos']] = $p;
    $n_grupos = max(1, count($letras));
    $real = false;
    if ($reales) {
        $real = true;
        $primera = null;
        foreach (['octavos', 'cuartos', 'semifinal', 'final'] as $f) if (!empty($reales[$f])) { $primera = $f; break; }
        if ($primera === null) return null;
        $total = ['octavos' => 16, 'cuartos' => 8, 'semifinal' => 4, 'final' => 2][$primera];
        $por_grupo = intdiv($total, $n_grupos);
        $tercer = (int)$t['llaves_tercer'] === 1;
        $cruces = [];
    } else {
        $por_grupo = (int)($t['llaves_por_grupo'] ?? 0);
        if ($por_grupo < 1) return null;
        $total = $por_grupo * $n_grupos;
        if (!in_array($total, [2, 4, 8, 16], true)) return null;
        $tercer = (int)$t['llaves_tercer'] === 1;
        $cruces = cruces_previstos($letras ?: [''], $por_grupo);
    }
    $lado_equipo = fn(array $p, string $l) => ['equipo' => ['id' => (int)$p[$l . '_id'], 'nombre' => $p[$l . '_nombre'], 'escudo' => $p[$l . '_escudo'], 'color' => $p[$l . '_color']]];
    $txt_siembra = fn(array $s) => ['texto' => $s['pos'] . '° ' . ($s['grupo'] === '' ? 'de la tabla' : 'Grupo ' . $s['grupo'])];
    // ¿Quién sale del casillero $pos de la ronda $fase? (equipo si ya se jugó, si no "Ganador Cuartos 3")
    $salida = function (string $fase, int $pos, bool $ganador) use ($reales, $lado_equipo) {
        $p = $reales[$fase][$pos] ?? null;
        if ($p && ($g = ganador_partido($p))) {
            $id = $ganador ? $g[0] : $g[1];
            return $lado_equipo($p, (int)$p['local_id'] === $id ? 'local' : 'visitante');
        }
        return ['texto' => ($ganador ? 'Ganador ' : 'Perdedor ') . ronda_corta($fase) . ' ' . $pos];
    };
    $rondas = [];
    $anterior = null;
    foreach (rondas_llave($total) as $i => $fase) {
        $n = intdiv($total, 2 ** ($i + 1));
        for ($pos = 1; $pos <= $n; $pos++) {
            $p = $reales[$fase][$pos] ?? null;
            if ($p) {
                $a = $lado_equipo($p, 'local');
                $b = $lado_equipo($p, 'visitante');
            } elseif ($anterior === null) {
                [$a, $b] = $cruces ? [$txt_siembra($cruces[$pos - 1][0]), $txt_siembra($cruces[$pos - 1][1])] : [['texto' => 'Por definir'], ['texto' => 'Por definir']];
            } else {
                $a = $salida($anterior, 2 * $pos - 1, true);
                $b = $salida($anterior, 2 * $pos, true);
            }
            $rondas[$fase][$pos] = ['etiqueta' => ronda_corta($fase) . ($n > 1 ? ' ' . $pos : ''), 'a' => $a, 'b' => $b, 'partido' => $p];
        }
        $anterior = $fase;
    }
    $tercer_partido = null;
    if ($tercer && $total >= 4) {
        $p = $reales['tercer'][1] ?? null;
        $tercer_partido = ['etiqueta' => 'Tercer lugar', 'partido' => $p,
            'a' => $p ? $lado_equipo($p, 'local') : $salida('semifinal', 1, false), 'b' => $p ? $lado_equipo($p, 'visitante') : $salida('semifinal', 2, false)];
    }
    return ['total' => $total, 'por_grupo' => $por_grupo, 'tercer' => $tercer, 'real' => $real, 'rondas' => $rondas, 'tercer_partido' => $tercer_partido];
}

/** Texto de la ruta del campeonato: "Fase regular (7 jornadas) → Cuartos de final → Semifinal → Final". */
function texto_ruta_campeonato(array $esq): string
{
    $r = ['Fase regular' . ($esq['jornadas'] ? ' (' . $esq['jornadas'] . ' jornada' . ($esq['jornadas'] === 1 ? '' : 's') . ')' : '')];
    if ($esq['final']) foreach (array_keys($esq['final']['rondas']) as $f) $r[] = nombre_fase($f);
    return implode(' → ', $r);
}

/* ============================================================
 * 3) HTML DEL ESQUEMA (panel, página pública y documento)
 * ============================================================ */

function html_lado_esquema(array $lado, ?array $p): string
{
    if (isset($lado['texto'])) return '<div class="llave-eq esq-casilla"><span>' . e($lado['texto']) . '</span></div>';
    $q = $lado['equipo'];
    $marcador = '';
    $clase = '';
    if ($p && partido_cerrado($p)) {
        $loc = (int)$p['local_id'] === (int)$q['id'];
        $goles = $loc ? $p['goles_local'] : $p['goles_visitante'];
        $pen = $loc ? $p['penales_local'] : $p['penales_visitante'];
        $marcador = (int)$goles . ($pen !== null ? '<small>(' . (int)$pen . ')</small>' : '');
        $g = ganador_partido($p);
        $clase = $g ? ($g[0] === (int)$q['id'] ? ' gana' : ' pierde') : '';
    }
    return '<div class="llave-eq' . $clase . '">' . escudo($q['escudo'], $q['nombre'], $q['color']) . '<span>' . e($q['nombre']) . '</span><b>' . $marcador . '</b></div>';
}

function html_casillero_esquema(array $c, string $enlace_base = ''): string
{
    $p = $c['partido'];
    $info = '';
    if ($p && !partido_cerrado($p)) $info = $p['estado'] === 'en_vivo' ? '● EN VIVO' : trim(($p['fecha'] ? fecha_bonita($p['fecha']) : 'Por definir') . ' ' . e($p['hora']));
    $cuerpo = '<small class="esq-etq">' . e($c['etiqueta']) . '</small>' . html_lado_esquema($c['a'], $p) . html_lado_esquema($c['b'], $p) . ($info !== '' ? '<small class="llave-info">' . $info . '</small>' : '');
    $clase = 'llave-partido' . ($p ? '' : ' esq-previsto');
    return $p && $enlace_base !== '' ? '<a class="' . $clase . '" href="' . e($enlace_base . (int)$p['id']) . '">' . $cuerpo . '</a>' : '<div class="' . $clase . '">' . $cuerpo . '</div>';
}

/**
 * Esquema completo en HTML. $enlace_base: para enlazar los partidos reales ('admin.php?p=partido&id=' o el público).
 * $doc = true: versión para imprimir (sin enlaces).
 */
function html_esquema_campeonato(array $t, string $enlace_base = '', bool $doc = false): string
{
    $esq = esquema_campeonato($t);
    $n_eq = array_sum(array_map('count', $esq['grupos']));
    $con_grupos = count(array_filter(array_keys($esq['grupos']), fn($l) => $l !== '' && $l !== '?')) > 0;
    $h = '<div class="esquema">';
    $h .= '<div class="esq-ruta">' . implode('<span class="esq-flecha">→</span>', array_map(fn($x) => '<span class="esq-paso">' . e($x) . '</span>', explode(' → ', texto_ruta_campeonato($esq)))) . '</div>';
    $resumen = [$n_eq . ' equipo' . ($n_eq === 1 ? '' : 's')];
    if ($con_grupos) $resumen[] = count($esq['regular']) . ' grupos';
    $resumen[] = 'todos contra todos' . ($con_grupos ? ' dentro de cada grupo' : '') . ', ' . ((int)$t['vueltas'] === 2 ? 'ida y vuelta' : 'solo ida');
    $resumen[] = $esq['partidos'] . ' partidos en la fase regular';
    if ($esq['desde']) $resumen[] = 'del ' . fecha_bonita($esq['desde']) . ' al ' . fecha_bonita($esq['hasta']);
    $h .= '<p class="esq-resumen">' . implode(' · ', $resumen) . '</p>';

    // Fase regular: un cuadro por grupo
    $h .= '<h3 class="esq-titulo">1. Fase regular</h3>';
    if ($esq['sin_sorteo']) $h .= '<p class="suave" style="margin:.2rem 0 .5rem">Los grupos se llenan al hacer el sorteo (o al asignarlos a mano).</p>';
    $h .= '<div class="esq-grupos">';
    foreach ($esq['grupos'] as $l => $eqs) {
        if ($l === '?') continue;
        [$j, $p, $hay] = $esq['regular'][$l] ?? [0, 0, false];
        $h .= '<div class="esq-grupo"><div class="esq-grupo-cab"><b>' . ($l === '' ? 'Tabla única' : 'Grupo ' . e($l)) . '</b><small>' . ($j ? $j . ' jornada' . ($j === 1 ? '' : 's') . ' · ' . $p . ' partidos' . ($hay ? '' : ' (previstos)') : 'sin partidos aún') . '</small></div>';
        if ($eqs) {
            $h .= '<ol>';
            foreach ($eqs as $e) $h .= '<li>' . escudo($e['escudo'], $e['nombre'], $e['color'], 'escudo') . '<span>' . e($e['nombre']) . '</span></li>';
            $h .= '</ol>';
        } else {
            $h .= '<p class="suave" style="margin:.4rem 0">Por sortear</p>';
        }
        $h .= '</div>';
    }
    $h .= '</div>';
    if (!empty($esq['grupos']['?'])) $h .= '<p class="suave">Sin grupo asignado todavía: ' . e(implode(', ', array_column($esq['grupos']['?'], 'nombre'))) . '</p>';

    // Fase final
    $f = $esq['final'];
    $h .= '<h3 class="esq-titulo">2. Fase final</h3>';
    if (!$f) {
        $h .= '<p class="suave">Sin fase final: el campeón es el primero de la tabla' . ($doc ? '' : ' (o defina abajo cuántos clasifican a la llave)') . '.</p>';
    } else {
        $ng = max(1, count($esq['regular']));
        $h .= '<p class="esq-resumen">Clasifican ' . ($ng > 1 ? $f['por_grupo'] . ' por grupo' : 'los ' . $f['total'] . ' primeros de la tabla') . ' → ' . $f['total'] . ' equipos'
            . ($f['tercer'] ? ' · con partido por el tercer lugar' : '') . ($f['real'] ? '' : ' · <i>cruces previstos</i>') . '</p>';
        $h .= '<div class="llave esq-llave">';
        foreach ($f['rondas'] as $fase => $casilleros) {
            $h .= '<div class="llave-col"><h3>' . e(nombre_fase($fase)) . '</h3>';
            foreach ($casilleros as $c) $h .= html_casillero_esquema($c, $doc ? '' : $enlace_base);
            // El tercer lugar va debajo de la final (ocupa menos espacio y cabe en una hoja)
            if ($fase === 'final' && $f['tercer_partido']) $h .= html_casillero_esquema($f['tercer_partido'], $doc ? '' : $enlace_base);
            $h .= '</div>';
        }
        $h .= '<div class="llave-col esq-campeon"><h3>Campeón</h3><div class="llave-partido esq-trofeo">🏆<br>' . e(campeon_esquema($f)) . '</div></div>';
        $h .= '</div>';
        if (!$f['real'] && $ng > 1) $h .= '<p class="ayuda">Los cruces entre grupos son los previstos: al terminar la fase regular el sistema ordena a los clasificados por puntos y arma la llave definitiva (los equipos de un mismo grupo no se cruzan en la primera ronda).</p>';
    }
    return $h . '</div>';
}

/** Nombre del campeón si ya se jugó la final; si no, "Ganador de la final". */
function campeon_esquema(array $f): string
{
    $fin = $f['rondas']['final'][1] ?? null;
    if ($fin && $fin['partido'] && ($g = ganador_partido($fin['partido']))) {
        foreach (['a', 'b'] as $k) if (isset($fin[$k]['equipo']) && $fin[$k]['equipo']['id'] === $g[0]) return $fin[$k]['equipo']['nombre'];
    }
    return 'Ganador de la final';
}

/* ============================================================
 * 4) DOCUMENTOS PARA IMPRIMIR / PDF
 * ============================================================ */

/** Estilos extra de los documentos del fixture y el esquema. */
function estilos_doc_fixture(bool $horizontal = false): string
{
    return '<style>' . ($horizontal ? '@page { size: landscape; margin: 10mm; }' : '@page { margin: 10mm; }') . '
  table.tabla-fixture { table-layout: fixed; width: 100%; }
  table.tabla-fixture td { overflow: hidden; text-overflow: ellipsis; }
  table.tabla-fixture td.res { overflow: visible; white-space: normal; line-height: 1.15; }
  .doc .llave-partido, .doc .esq-tercero, .doc .esq-grupo { break-inside: avoid; page-break-inside: avoid; }
  .doc .esq-llave { break-inside: avoid; page-break-inside: avoid; }
  .doc .esq-grupo li { padding: 1px 0; } .doc .llave-col { gap: 6px; } .doc .esq-titulo { margin: 8px 0 4px; }
  .jornada-doc { break-inside: avoid; page-break-inside: avoid; margin-bottom: 8px; }
  .jornada-doc h3 { font-size: 12px; margin: 10px 0 3px; text-transform: uppercase; }
  table.pl td.res { text-align: center; font-weight: 800; white-space: nowrap; width: 70px; }
  table.pl td.res.blanco { color: #999; font-weight: 400; }
  table.pl td.eqr { text-align: right; } table.pl td.casilla-txt { font-style: italic; color: #333; }
  .descansa-doc { font-size: 11px; margin: 2px 0 0; }
  .doc .esquema .llave { flex-wrap: wrap; overflow: visible; }
  .doc .esquema .llave-partido { box-shadow: none; border: 1px solid #000; }
  .doc .esquema .esq-grupo { border: 1px solid #000; }
  .doc .esq-paso { border: 1px solid #000; background: #fff; color: #000; }
  .opciones-doc { margin: -4px 0 12px; font-size: 12px; }
  @media print { .contenedor.doc { padding: 0 2px !important; } }
</style>';
}

/**
 * Fixture completo para imprimir: datos del campeonato, esquema y todas las jornadas.
 * $resultados = false: en blanco para llenar a mano.
 */
function doc_fixture(array $t, array $liga, string $volver, string $url_doc, bool $resultados = true): void
{
    $tid = (int)$t['id'];
    $T = textos_deporte($t);
    $esq = esquema_campeonato($t);
    $partidos = partidos_torneo($tid);
    $inscritos = filas('SELECT e.id, e.nombre FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id WHERE te.torneo_id = ? ORDER BY e.nombre', [$tid]);
    doc_inicio('Fixture ' . $t['nombre'] . ' ' . $t['temporada'], $volver);
    echo estilos_doc_fixture();
    $sep = str_contains($url_doc, '?') ? '&' : '?';
    echo '<p class="opciones-doc no-imprimir">' . ($resultados
        ? '📝 <a href="' . e($url_doc . $sep . 'blanco=1') . '">Ver en blanco (sin resultados, para llenar a mano)</a>'
        : '✅ <a href="' . e(preg_replace('/[?&]blanco=1/', '', $url_doc)) . '">Ver con resultados</a>') . '</p>';
    doc_cabecera_financiera($liga, 'FIXTURE OFICIAL DEL CAMPEONATO');
    $con_grupos = count($esq['regular']) > 1;
    ?>
  <h2 style="margin-top:10px"><?= e($t['nombre']) ?> <?= e($t['temporada']) ?> <small>· <?= e(nombre_modalidad($t['modalidad'])) ?><?= $t['categoria'] !== '' ? ' · ' . e($t['categoria']) : '' ?></small></h2>
  <div class="meta">
    <div><b>Equipos:</b> <?= count($inscritos) ?></div>
    <div><b>Formato:</b> <?= $con_grupos ? count($esq['regular']) . ' grupos' : 'Tabla única' ?>, <?= (int)$t['vueltas'] === 2 ? 'ida y vuelta' : 'solo ida' ?></div>
    <div><b>Jornadas:</b> <?= (int)$esq['jornadas'] ?> · <b>Partidos:</b> <?= count($partidos) ?: $esq['partidos'] ?></div>
    <div><b>Fechas:</b> <?= $esq['desde'] ? e(fecha_bonita($esq['desde'])) . ' al ' . e(fecha_bonita($esq['hasta'])) : 'por definir' ?></div>
  </div>

  <h2>1. Esquema del campeonato</h2>
  <?= html_esquema_campeonato($t, '', true) ?>

  <h2 style="break-before:page;page-break-before:always">2. Fixture completo</h2>
  <?php
    if (!$partidos && !$esq['final']) echo '<p>El fixture todavía no se ha generado.</p>';
    // Agrupa por jornada (fase regular) y por ronda (fase final)
    $bloques = [];
    foreach ($partidos as $p) {
        $k = $p['fase'] === 'regular' ? 'j' . str_pad((string)(int)$p['jornada'], 3, '0', STR_PAD_LEFT) : 'z' . array_search($p['fase'], ['octavos', 'cuartos', 'semifinal', 'tercer', 'final'], true) . $p['fase'];
        $bloques[$k][] = $p;
    }
    // Rondas finales previstas que aún no existen: se agregan como casilleros "Ganador Cuartos 1 vs Ganador Cuartos 2"
    $previstos = [];
    if ($esq['final']) {
        $rondas = $esq['final']['rondas'] + ($esq['final']['tercer_partido'] ? ['tercer' => [1 => $esq['final']['tercer_partido']]] : []);
        foreach ($rondas as $fase => $cs) {
            foreach ($cs as $c) {
                if ($c['partido']) continue;
                $k = 'z' . array_search($fase, ['octavos', 'cuartos', 'semifinal', 'tercer', 'final'], true) . $fase;
                $previstos[$k][] = $c;
                $bloques[$k] = $bloques[$k] ?? [];
            }
        }
    }
    ksort($bloques);
    $nom_lado = fn(array $lado) => isset($lado['texto']) ? $lado['texto'] : $lado['equipo']['nombre'];
    foreach ($bloques as $k => $ps) {
        $es_regular = $k[0] === 'j';
        $titulo = $es_regular ? 'Jornada ' . (int)substr($k, 1) : nombre_fase(preg_replace('/^z\d*/', '', $k));
        $fechas = array_unique(array_filter(array_column($ps, 'fecha')));
        sort($fechas);
        echo '<div class="jornada-doc"><h3>' . e($titulo) . ($fechas ? ' · ' . e(count($fechas) === 1 ? fecha_bonita($fechas[0]) : fecha_bonita($fechas[0]) . ' al ' . fecha_bonita(end($fechas))) : '') . '</h3>';
        $anchos = $con_grupos && $es_regular ? [5, 13, 8, 18, 23, 10, 23] : [13, 8, 21, 24, 10, 24];
        echo '<table class="pl tabla-fixture"><colgroup>' . implode('', array_map(fn($w) => '<col style="width:' . $w . '%">', $anchos)) . '</colgroup><tr>' . ($con_grupos && $es_regular ? '<th>Gr.</th>' : '') . '<th>Fecha</th><th>Hora</th><th>Cancha</th><th>Local</th><th>' . ($resultados ? 'Resultado' : e($T['goles'])) . '</th><th>Visitante</th></tr>';
        foreach ($ps as $p) {
            $cerrado = partido_cerrado($p);
            if ($resultados && ($cerrado || $p['estado'] === 'en_vivo')) {
                $res = (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . ($p['penales_local'] !== null ? '<br><small>pen ' . (int)$p['penales_local'] . '-' . (int)$p['penales_visitante'] . '</small>' : '') . ($p['estado'] === 'wo' ? ' <small>W.O.</small>' : '') . ($p['estado'] === 'en_vivo' ? ' <small>en vivo</small>' : '');
                $cls = 'res';
            } elseif ($resultados && in_array($p['estado'], ['suspendido', 'postergado'], true)) {
                $res = e(nombre_estado_partido($p['estado']));
                $cls = 'res blanco';
            } else {
                $res = $resultados ? 'vs' : '____ - ____';
                $cls = 'res blanco';
            }
            echo '<tr>' . ($con_grupos && $es_regular ? '<td>' . e($p['grupo']) . '</td>' : '') . '<td>' . ($p['fecha'] !== '' ? fecha_bonita($p['fecha']) : '—') . '</td><td>' . e($p['hora'] ?: '—') . '</td><td>' . e($p['cancha'] ?: '—') . '</td>'
                . '<td class="eqr">' . e($p['local_nombre']) . '</td><td class="' . $cls . '">' . $res . '</td><td>' . e($p['visitante_nombre']) . '</td></tr>';
        }
        foreach ($previstos[$k] ?? [] as $c) {
            echo '<tr><td>—</td><td>—</td><td>—</td><td class="eqr casilla-txt">' . e($nom_lado($c['a'])) . '</td><td class="res blanco">' . ($resultados ? 'vs' : '____ - ____') . '</td><td class="casilla-txt">' . e($nom_lado($c['b'])) . '</td></tr>';
        }
        echo '</table>';
        if ($es_regular) {
            $juegan = [];
            foreach ($ps as $p) { $juegan[] = (int)$p['local_id']; $juegan[] = (int)$p['visitante_id']; }
            $desc = array_filter($inscritos, fn($e) => !in_array((int)$e['id'], $juegan, true));
            if ($desc) echo '<p class="descansa-doc"><b>Descansa:</b> ' . e(implode(', ', array_column($desc, 'nombre'))) . '</p>';
        }
        echo '</div>';
    }
    ?>
  <p style="font-size:11px;color:#555;margin-top:14px">Fixture generado el <?= date('d/m/Y H:i') ?> con <?= e(marca()) ?>. Las fechas, horas y canchas pueden cambiar: consulte siempre la página oficial del campeonato.</p>
    <?php
    doc_fin();
}

/** Solo el esquema del campeonato (una hoja horizontal). */
function doc_esquema(array $t, array $liga, string $volver): void
{
    doc_inicio('Esquema ' . $t['nombre'] . ' ' . $t['temporada'], $volver);
    echo estilos_doc_fixture(true);
    doc_cabecera_financiera($liga, 'ESQUEMA DEL CAMPEONATO');
    echo '<h2 style="margin-top:10px">' . e($t['nombre']) . ' ' . e($t['temporada']) . ' <small>· ' . e(nombre_modalidad($t['modalidad'])) . ($t['categoria'] !== '' ? ' · ' . e($t['categoria']) : '')
        . ' · generado el ' . date('d/m/Y H:i') . ' con ' . e(marca()) . '</small></h2>';
    echo html_esquema_campeonato($t, '', true);
    doc_fin();
}
