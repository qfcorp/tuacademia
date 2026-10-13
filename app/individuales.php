<?php
/*
 * (v25) DEPORTES INDIVIDUALES: todo lo que NO es de equipos.
 *   Carreras (carreras.php): AUTOX, CICLO        → página admin.php?p=carrera
 *   Duelos   (duelos.php):   TENIS, PADEL, TMESA, MARC, BOXEO → admin.php?p=duelo
 *   Bolos    (bolos.php):    BOLOS                → admin.php?p=bolos
 *   Pruebas  (pruebas.php):  NATAC, ATLET (v26)   → admin.php?p=pruebas
 * Las pantallas de deportes de equipo (fixture, tablas acumuladas, redes, widgets, votaciones, boletín,
 * importar, copiar campeonato) usan sql_sin_carreras() / es_individual() para dejarlos fuera.
 */

function modalidades_individuales(): array
{
    return modalidades_carrera() + modalidades_duelo() + modalidades_bolos() + modalidades_pruebas();
}

function es_individual($t): bool
{
    return es_carrera($t) || es_duelo($t) || es_bolos($t) || es_pruebas($t);
}

/** Página del panel que administra el campeonato individual ('carrera' | 'duelo' | 'bolos'). */
function pagina_individual($t): string
{
    return es_duelo($t) ? 'duelo' : (es_bolos($t) ? 'bolos' : (es_pruebas($t) ? 'pruebas' : 'carrera'));
}

function url_admin_individual(array $t): string
{
    return 'admin.php?p=' . pagina_individual($t) . '&id=' . (int)$t['id'];
}

function icono_individual($t): string
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return modalidades_individuales()[$m][1] ?? '🏅';
}

/** Resumen corto para tarjetas: "12 jugadores · 3 jornadas", "24 competidores · 5 categorías". */
function resumen_individual(array $t): string
{
    $tid = (int)$t['id'];
    if (es_duelo($t)) {
        $T = textos_duelo($t);
        $np = (int)valor('SELECT COUNT(*) FROM duelo_participantes WHERE torneo_id = ?', [$tid]);
        $nj = (int)valor("SELECT COUNT(*) FROM duelo_combates WHERE torneo_id = ? AND estado = 'jugado'", [$tid]);
        return $np . ' ' . mb_strtolower($np === 1 ? $T['participante'] : $T['participantes']) . ' · ' . $nj . ' ' . mb_strtolower($nj === 1 ? $T['combate'] : $T['combates']) . (str_ends_with($T['combates'], 'as') ? ' jugadas' : ' jugados');
    }
    if (es_pruebas($t)) {
        $Tp = textos_pruebas($t);
        $np = (int)valor('SELECT COUNT(*) FROM prueba_participantes WHERE torneo_id = ?', [$tid]);
        $ne = (int)valor('SELECT COUNT(*) FROM prueba_eventos WHERE torneo_id = ?', [$tid]);
        return $np . ' ' . mb_strtolower($Tp['participantes']) . ' · ' . $ne . ' pruebas';
    }
    $T = es_bolos($t) ? textos_bolos() : textos_carrera($t);
    $np = (int)valor('SELECT COUNT(*) FROM carrera_participantes WHERE torneo_id = ?', [$tid]);
    $nf = (int)valor('SELECT COUNT(*) FROM carrera_fechas WHERE torneo_id = ?', [$tid]);
    return $np . ' ' . mb_strtolower($T['participantes']) . ' · ' . $nf . ' ' . mb_strtolower($T['fechas']);
}

/** Líder o campeones de un campeonato individual (para el resumen del panel): ['Categoría' => 'Nombre', ...] */
function lideres_individual(array $t): array
{
    $r = [];
    if (es_duelo($t)) {
        $part = duelo_participantes((int)$t['id']);
        foreach (duelo_categorias((int)$t['id']) as $cat) {
            $m = duelo_medallas_categoria($t, $cat);
            if ($m['oro'] && isset($part[$m['oro'][0]])) $r[$cat['nombre']] = '🥇 ' . duelo_nombre($part[$m['oro'][0]]);
            elseif (in_array($cat['formato'], ['liga', 'suizo'], true) && duelo_combates((int)$cat['id'])) {
                $tab = duelo_tabla_liga($t, (int)$cat['id']);
                if ($tab && $tab[0]['pj'] > 0) $r[$cat['nombre']] = '1.° ' . duelo_nombre($tab[0]['p']);
            } elseif ($cat['formato'] === 'escalera' && ($esc = duelo_escalera((int)$t['id'], (int)$cat['id']))) {
                $r[$cat['nombre']] = '1.° ' . duelo_nombre($esc[0]); // (v36) puntero de la escalera
            }
        }
        return $r;
    }
    if (es_pruebas($t)) {
        foreach (array_slice(pruebas_medallero($t)['clubes'], 0, 3) as $i => $c) $r[($i + 1) . '.° por clubes'] = $c['club'] . ' (' . $c['oro'] . ' 🥇 · ' . $c['puntos'] . ' pts)';
        return $r;
    }
    if (es_bolos($t)) {
        foreach (bolos_general($t)['cats'] as $c) if ($c['filas']) $r[$c['cat']['nombre']] = '🥇 ' . $c['filas'][0]['p']['nombre'];
        return $r;
    }
    foreach (carrera_general($t)['cats'] as $c) if ($c['filas']) $r[$c['cat']['nombre']] = '🥇 ' . $c['filas'][0]['p']['nombre'];
    return $r;
}
