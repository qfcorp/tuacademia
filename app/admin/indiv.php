<?php
/*
 * (v26) Herramientas de los DEPORTES INDIVIDUALES (carreras, duelos, bolos, natación y atletismo):
 *   s=inscripciones  inscripciones en línea (aprobar / rechazar) y abrir o cerrar la inscripción
 *   s=imagenes       imágenes para redes (podios, clasificaciones, medallero)
 *   s=imprimir       hojas para imprimir o guardar en PDF (llaves, clasificación, medallero, planillas en blanco)
 *   s=copiar         copiar el campeonato para la nueva temporada
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$t = torneo_de_liga((int)get('id'), $lid);
$tid = (int)$t['id'];
if (!es_individual($t)) ir('admin.php?p=torneo&id=' . $tid);
$s = get('s', 'inscripciones');
if (!in_array($s, ['inscripciones', 'imagenes', 'imprimir', 'copiar'], true)) $s = 'inscripciones';
$url = 'admin.php?p=indiv&id=' . $tid;
$url_panel = url_admin_individual($t);

/* ============================================================
 * ACCIONES
 * ============================================================ */
if (es_post()) {
    $accion = post('accion');

    if ($accion === 'insc_config') {
        $hasta = post('hasta') !== '' && strtotime(post('hasta')) ? date('Y-m-d', strtotime(post('hasta'))) : '';
        $valor = round(max(0, (float)str_replace(',', '.', post('valor'))), 2);
        q('UPDATE torneos SET insc_indiv_hasta = ?, insc_indiv_valor = ?, insc_indiv_info = ? WHERE id = ?', [$hasta, $valor, mb_substr(trim(post('info')), 0, 400), $tid]);
        flash($hasta !== '' && $hasta >= date('Y-m-d') ? 'Inscripción en línea abierta hasta el ' . fecha_bonita($hasta) . '. Ya aparece la pestaña «Inscribirse» en la página pública.' : 'Inscripción en línea cerrada.');
        ir($url . '&s=inscripciones');
    }

    if ($accion === 'aprobar' || $accion === 'rechazar') {
        $ins = fila("SELECT * FROM inscripciones_indiv WHERE id = ? AND torneo_id = ? AND estado = 'pendiente'", [(int)post('iid'), $tid]) ?? throw new RuntimeException('Esa inscripción ya fue revisada.');
        $por = usuario()['nombre'] ?? '';
        if ($accion === 'aprobar') {
            $cats = insc_indiv_categorias($t);
            $cat = isset($cats[(int)post('categoria_id')]) ? (int)post('categoria_id') : null;
            $pid = aprobar_inscripcion_indiv($t, $ins, $cat);
            q("UPDATE inscripciones_indiv SET estado = 'aprobada', participante_id = ?, revisado_por = ?, revisado_en = ? WHERE id = ?", [$pid, $por, date('Y-m-d H:i'), $ins['id']]);
            flash('Inscripción aprobada: ' . $ins['nombre'] . ' ya está en la lista de competidores. Avísele por WhatsApp con el botón verde.');
        } else {
            q("UPDATE inscripciones_indiv SET estado = 'rechazada', motivo = ?, revisado_por = ?, revisado_en = ? WHERE id = ?", [mb_substr(trim(post('motivo')), 0, 200), $por, date('Y-m-d H:i'), $ins['id']]);
            borrar_requisitos('inscripciones_indiv', (int)$ins['id']); // (v35) sus documentos ya no se guardan
            flash('Inscripción rechazada.');
        }
        ir($url . '&s=inscripciones');
    }

    if ($accion === 'marcar_pagado') {
        $ins = fila('SELECT * FROM inscripciones_indiv WHERE id = ? AND torneo_id = ?', [(int)post('iid'), $tid]) ?? throw new RuntimeException('Inscripción no encontrada.');
        q('UPDATE inscripciones_indiv SET pagado = ? WHERE id = ?', [$ins['pagado'] === '' ? date('Y-m-d H:i') : '', $ins['id']]);
        flash($ins['pagado'] === '' ? 'Marcada como pagada.' : 'Se quitó la marca de pagada.');
        ir($url . '&s=inscripciones');
    }

    if ($accion === 'copiar') {
        $nid = copiar_individual($liga, $tid, post('nombre'), post('temporada'), post('con_inscritos') === '1');
        $nuevo = fila('SELECT * FROM torneos WHERE id = ?', [$nid]);
        flash('Campeonato copiado con sus categorías y reglas' . (post('con_inscritos') === '1' ? ' y los mismos competidores' : '') . '. Revise la configuración antes de empezar.');
        ir(url_admin_individual($nuevo));
    }
}

/* ============================================================
 * HOJAS PARA IMPRIMIR (página aparte, sin menú)
 * ============================================================ */
if ($s === 'imprimir' && get('doc') !== '') {
    $doc = get('doc');
    $volver = $url . '&s=imprimir';
    $sub = $t['nombre'] . ' ' . $t['temporada'];
    $css_print = '<style>.col-manga{display:table-cell!important}.solo-movil{display:none!important}.tabla-scroll{overflow:visible}
        .llave{flex-wrap:wrap}.doc table{width:100%;border-collapse:collapse}.doc table th,.doc table td{border:1px solid #000;padding:4px 5px;font-size:12px;color:#000}
        .doc table th{background:#eee;font-size:10px}.doc .tarjeta{box-shadow:none;border:1px solid #999}.hoja-cat{break-inside:avoid;page-break-inside:avoid}
        .blanco{min-width:70px;height:26px}.salto{break-before:page;page-break-before:always}</style>';
    $titulos = ['llaves' => 'LLAVES Y TABLAS', 'programa' => 'PROGRAMACIÓN', 'medallero' => 'MEDALLERO', 'inscritos' => 'PLANILLA DE INSCRITOS',
        'general' => 'CLASIFICACIÓN GENERAL', 'fecha' => 'RESULTADOS', 'planilla' => 'PLANILLA DE CONTROL', 'resultados' => 'RESULTADOS'];
    doc_inicio(($titulos[$doc] ?? 'Documento') . ' · ' . $sub, $volver);
    echo $css_print;
    doc_cabecera_financiera($liga, $titulos[$doc] ?? 'DOCUMENTO');
    echo '<h2 style="margin-top:8px">' . e($sub) . ' · ' . icono_individual($t) . ' ' . e(nombre_modalidad($t['modalidad'])) . '</h2>';

    if (es_duelo($t)) {
        echo duelo_css();
        $Td = textos_duelo($t);
        $part = duelo_participantes($tid);
        $cats = duelo_categorias($tid);
        $nombres_c = array_column($cats, 'nombre', 'id');
        if ($doc === 'llaves') {
            foreach ($cats as $c) {
                if (get('c') !== '' && (int)get('c') !== (int)$c['id']) continue;
                if (!duelo_combates((int)$c['id'])) continue;
                echo '<div class="hoja-cat"><h2>' . e($c['nombre']) . ' <small>' . e(duelo_texto_categoria($c)) . '</small></h2>';
                echo duelo_html_categoria($t, $c, duelo_participantes($tid, (int)$c['id'])); // (v36) todos los formatos
                echo '</div>';
            }
        } elseif ($doc === 'programa') {
            $pend = filas("SELECT * FROM duelo_combates WHERE torneo_id = ? AND estado = 'pendiente' AND a_id IS NOT NULL AND b_id IS NOT NULL ORDER BY CASE WHEN fecha = '' THEN 1 ELSE 0 END, fecha, hora, id", [$tid]);
            if (!$pend) echo '<p>No hay ' . e(mb_strtolower($Td['combates'])) . ' pendientes.</p>';
            else { ?>
              <table><tr><th>Día / hora</th><th>Categoría</th><th>Esquina / lado A</th><th>Esquina / lado B</th><th>Resultado</th><th>Ganador</th><th>Firma juez</th></tr>
              <?php foreach ($pend as $c): ?><tr><td><?= e(trim(($c['fecha'] !== '' ? fecha_corta($c['fecha']) : '') . ' ' . $c['hora'] . ($c['lugar'] !== '' ? ' · ' . $c['lugar'] : ''))) ?></td><td><?= e($nombres_c[(int)$c['categoria_id']] ?? '') ?></td>
                <td><?= e(duelo_nombre($part[(int)$c['a_id']] ?? null)) ?></td><td><?= e(duelo_nombre($part[(int)$c['b_id']] ?? null)) ?></td><td class="blanco"></td><td class="blanco"></td><td class="blanco"></td></tr><?php endforeach; ?>
              </table>
            <?php }
        } elseif ($doc === 'medallero') {
            echo duelo_html_medallero($t, duelo_medallero($t));
        } else { // inscritos
            $grupos = [];
            foreach ($part as $p) if ((int)$p['activo']) $grupos[(int)($p['categoria_id'] ?? 0)][] = $p;
            $con_peso = in_array($t['modalidad'], ['MARC', 'BOXEO'], true);
            foreach (array_merge($cats, [['id' => 0, 'nombre' => 'Sin categoría']]) as $c) {
                if (empty($grupos[(int)$c['id']])) continue; ?>
                <div class="hoja-cat"><h2><?= e($c['nombre']) ?> <small>(<?= count($grupos[(int)$c['id']]) ?>)</small></h2>
                <table><tr><th>#</th><th><?= e($Td['participante']) ?></th><th>Club</th><th>Edad</th><?php if ($con_peso): ?><th>Peso declarado</th><th>Peso oficial</th><?php endif; ?><th>Firma</th></tr>
                <?php foreach ($grupos[(int)$c['id']] as $i => $p): ?><tr><td><?= $i + 1 ?></td><td><?= e(duelo_nombre($p)) ?></td><td><?= e($p['club']) ?></td><td><?= $p['nacimiento'] !== '' ? (int)prueba_edad($p['nacimiento'], $t) : '' ?></td>
                  <?php if ($con_peso): ?><td><?= $p['peso'] !== null ? e(rtrim(rtrim(number_format((float)$p['peso'], 1, ',', ''), '0'), ',')) . ' kg' : '' ?></td><td class="blanco"></td><?php endif; ?><td class="blanco" style="min-width:120px"></td></tr><?php endforeach; ?>
                </table></div>
            <?php }
        }
    } elseif (es_pruebas($t)) {
        $Tp = textos_pruebas($t);
        $part = pruebas_participantes($tid);
        $evs = pruebas_eventos($tid);
        if (get('ev') !== '') $evs = array_values(array_filter($evs, fn($ev) => (int)$ev['id'] === (int)get('ev')));
        if ($doc === 'medallero') {
            echo pruebas_html_medallero(pruebas_medallero($t), $Tp['participantes'] . ' con medalla');
            echo '<h3>Puntos por club</h3><table><tr><th>#</th><th>Club</th><th>Puntos</th></tr>';
            foreach (pruebas_puntos_clubes($t) as $i => $c) echo '<tr><td>' . ($i + 1) . '</td><td>' . e($c['club']) . '</td><td><b>' . (int)$c['puntos'] . '</b></td></tr>';
            echo '</table>';
        } elseif ($doc === 'planilla') {
            foreach ($evs as $ev) {
                $filas_ev = prueba_resultados($ev, $part);
                if (!$filas_ev) continue;
                usort($filas_ev, fn($a, $b) => [(int)$a['serie'], (int)$a['carril'], $a['p']['nombre']] <=> [(int)$b['serie'], (int)$b['carril'], $b['p']['nombre']]); ?>
                <div class="hoja-cat"><h2><?= e(prueba_nombre($ev)) ?> <small><?= e(trim(($ev['fecha'] !== '' ? fecha_corta($ev['fecha']) : '') . ' ' . $ev['hora'])) ?></small></h2>
                <table><tr><th>Serie</th><th><?= e($Tp['carril']) ?></th><th><?= e($Tp['participante']) ?></th><th>Club</th><th><?= $ev['medida'] === 'metros' ? 'Marca (m)' : 'Tiempo' ?></th><th>Puesto</th><th>Obs.</th></tr>
                <?php foreach ($filas_ev as $r): ?><tr><td><?= (int)$r['serie'] ?: '' ?></td><td><?= (int)$r['carril'] ?: '' ?></td><td><?= e($r['p']['nombre']) ?></td><td><?= e($r['p']['club']) ?></td><td class="blanco"></td><td class="blanco" style="min-width:40px"></td><td class="blanco"></td></tr><?php endforeach; ?>
                </table></div>
            <?php }
        } else { // resultados
            $n = 0;
            foreach ($evs as $ev) {
                if (!prueba_con_marcas((int)$ev['id'])) continue;
                $n++;
                echo '<div class="hoja-cat"><h2>' . e(prueba_nombre($ev)) . '</h2>' . prueba_html_resultados($t, $ev, prueba_resultados($ev, $part)) . '</div>';
            }
            if (!$n) echo '<p>Todavía no hay resultados.</p>';
        }
    } else {
        // carreras y bolos (comparten fechas/jornadas y categorías)
        $bolos = es_bolos($t);
        $T = $bolos ? textos_bolos() : textos_carrera($t);
        $fechas = carrera_fechas($tid);
        $f = null;
        foreach ($fechas as $x) if ((int)$x['id'] === (int)get('f')) $f = $x;
        if ($doc === 'general') {
            if ($bolos) {
                $calc = bolos_calcular($t);
                $g = bolos_general($t, $calc);
                echo bolos_html_destacados(bolos_destacados($calc));
                foreach ($g['cats'] as $c) echo '<div class="hoja-cat"><h2>' . e($c['cat']['nombre']) . '</h2>' . bolos_html_tabla_general($c['filas'], $g['fechas'], $g['cfg']) . '</div>';
                if ($cl = bolos_clubes($calc)) echo '<div class="hoja-cat"><h2>Clubes</h2>' . bolos_html_clubes($cl) . '</div>';
            } else {
                $g = carrera_general($t);
                foreach ($g['cats'] as $c) echo '<div class="hoja-cat"><h2>' . e($c['cat']['nombre']) . '</h2>' . carrera_html_tabla_general($c['filas'], $g['fechas'], $g['cfg'], $T) . '</div>';
                if (!$g['cats']) echo '<p>Todavía no hay resultados.</p>';
            }
        } elseif ($doc === 'fecha' && $f) {
            echo '<h2>' . e(carrera_nombre_fecha($f, $T)) . '</h2>';
            if ($bolos) {
                $j = bolos_jornada($t, $f);
                $cfg = bolos_cfg($t);
                foreach ($j['cats'] as $c) echo '<div class="hoja-cat"><h3>' . e($c['cat']['nombre']) . '</h3>' . bolos_html_tabla_jornada($c['filas'], $j['lineas'], $cfg) . '</div>';
                if (!$j['cats']) echo '<p>Todavía no hay pinos cargados.</p>';
            } else {
                $cl = carrera_clasificacion_fecha($t, $f);
                foreach ($cl['cats'] as $c) echo '<div class="hoja-cat"><h3>' . e($c['cat']['nombre']) . '</h3>' . carrera_html_tabla_fecha($c['filas'], $t, $f) . '</div>';
                if (!$cl['cats']) echo '<p>Todavía no hay tiempos cargados.</p>';
            }
        } else { // planilla de control en blanco
            $n_col = $bolos ? ($f ? bolos_lineas_jornada($f, bolos_cfg($t)) : bolos_cfg($t)['lineas']) : ($f ? carrera_mangas_fecha($f, carrera_cfg($t)) : carrera_cfg($t)['mangas']);
            echo '<p><b>' . e($f ? carrera_nombre_fecha($f, $T) : 'Fecha: ____________________') . '</b>' . ($f && $f['fecha'] !== '' ? ' · ' . e(fecha_bonita($f['fecha'])) : '') . ($f && ($f['lugar'] ?? '') !== '' ? ' · ' . e($f['lugar']) : '') . '</p>';
            $cats = carrera_categorias($tid);
            $por = [];
            foreach (filas('SELECT * FROM carrera_participantes WHERE torneo_id = ? AND activo = 1 ORDER BY CAST(numero AS INTEGER), nombre', [$tid]) as $p) $por[(int)($p['categoria_id'] ?? 0)][] = $p;
            foreach (array_merge($cats, [['id' => 0, 'nombre' => 'Sin categoría']]) as $c) {
                if (empty($por[(int)$c['id']])) continue; ?>
                <div class="hoja-cat"><h3><?= e($c['nombre']) ?></h3>
                <table><tr><th>N.°</th><th><?= e($T['participante']) ?></th><th>Club</th><?php for ($m = 1; $m <= $n_col; $m++): ?><th><?= $bolos ? 'Línea ' . $m : e($T['manga']) . ' ' . $m . (($t['modalidad'] === 'AUTOX') ? ' (tiempo / conos)' : '') ?></th><?php endfor; ?><th><?= $bolos ? 'Total' : 'Obs.' ?></th></tr>
                <?php foreach ($por[(int)$c['id']] as $p): ?><tr><td><?= e((string)$p['numero']) ?></td><td><?= e($p['nombre']) ?></td><td><?= e($p['club']) ?></td><?php for ($m = 1; $m <= $n_col; $m++): ?><td class="blanco"></td><?php endfor; ?><td class="blanco"></td></tr><?php endforeach; ?>
                </table></div>
            <?php }
        }
    }
    echo '<p class="pie-doc">Impreso el ' . date('d/m/Y H:i') . ' · ' . e(url_torneo($liga, $t)) . '</p>';
    doc_fin();
    return;
}

/* ============================================================
 * PANTALLA
 * ============================================================ */
$estados_t = ['inscripcion' => 'Inscripciones', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'];
cabecera_admin($t['nombre']);
?>
<p><a href="<?= e($url_panel) ?>">← <?= e($t['nombre']) ?></a></p>
<div class="fila-titulo">
  <h1><?= icono_individual($t) ?> <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?></small></h1>
  <a class="btn btn-sec btn-chico" target="_blank" href="<?= e(url_torneo($liga, $t)) ?>">Ver página pública ↗</a>
</div>
<nav class="pestanas">
  <?php $np = insc_indiv_pendientes($tid);
  foreach (['inscripciones' => '📝 Inscripciones en línea' . ($np ? ' (' . $np . ')' : ''), 'imagenes' => '🖼 Imágenes para redes', 'imprimir' => '🖨 Imprimir y PDF', 'copiar' => '📋 Copiar para nueva temporada'] as $k => $txt): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'imagenes'):
    $imgs = imagenes_individual($t);
    $base_img = rtrim(url_base(), '/') . '/imagen.php?tipo=indiv&t=' . $tid; ?>
  <p class="suave">Imágenes listas para Instagram, Facebook y estados de WhatsApp (1080 × 1350). Se actualizan solas con cada resultado. Toque <b>Descargar</b> y súbala a sus redes.</p>
  <?php if (!$imgs): ?><div class="tarjeta">Todavía no hay resultados para hacer imágenes. Aparecen aquí cuando cargue los primeros resultados.</div><?php endif; ?>
  <div class="rejilla" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
    <?php foreach ($imgs as [$tit, $que, $sel]): $u = $base_img . '&que=' . $que . '&c=' . $sel; ?>
      <div class="tarjeta" style="padding:10px">
        <a href="<?= e($u) ?>" target="_blank"><img src="<?= e($u) ?>" loading="lazy" alt="<?= e($tit) ?>" style="width:100%;border-radius:8px;aspect-ratio:1080/1350;object-fit:cover;background:#ddd"></a>
        <p style="margin:6px 0;font-size:.9rem"><b><?= e($tit) ?></b></p>
        <a class="btn btn-chico" href="<?= e($u . '&descargar=1') ?>">⬇ Descargar</a>
        <a class="btn btn-wa btn-chico" target="_blank" href="https://wa.me/?text=<?= rawurlencode($tit . ' · ' . $t['nombre'] . "\n" . $u) ?>">📲 WhatsApp</a>
      </div>
    <?php endforeach; ?>
  </div>

<?php elseif ($s === 'imprimir'):
    $u = $url . '&s=imprimir&doc=';
    $docs = [];
    if (es_duelo($t)) {
        $docs[] = ['🔀 Llaves y tablas de todas las categorías', $u . 'llaves', 'El cuadro de cada categoría con los resultados cargados.'];
        $docs[] = ['📅 Programación con casillas para anotar', $u . 'programa', 'Los ' . mb_strtolower(textos_duelo($t)['combates']) . ' pendientes con espacio para el resultado y la firma del juez.'];
        $docs[] = ['🏅 Medallero', $u . 'medallero', 'Medallero por club y podio de cada categoría.'];
        $docs[] = ['📋 Planilla de inscritos' . (in_array($t['modalidad'], ['MARC', 'BOXEO'], true) ? ' y pesaje' : ''), $u . 'inscritos', 'Por categoría, con espacio para la firma' . (in_array($t['modalidad'], ['MARC', 'BOXEO'], true) ? ' y el peso oficial.' : '.')];
    } elseif (es_pruebas($t)) {
        $docs[] = ['⏱ Resultados de todas las pruebas', $u . 'resultados', 'Cada prueba con sus puestos y marcas.'];
        $docs[] = ['📋 Planillas de control (series y carriles)', $u . 'planilla', 'Una tabla por prueba con espacio para anotar tiempos o marcas.'];
        $docs[] = ['🏅 Medallero y puntos por club', $u . 'medallero', 'Medallas por club, por competidor y la tabla de puntos.'];
    } else {
        $T = es_bolos($t) ? textos_bolos() : textos_carrera($t);
        $docs[] = ['🏁 Clasificación general', $u . 'general', 'La general de cada categoría' . (es_bolos($t) ? ', los récords y los clubes.' : '.')];
        foreach (carrera_fechas($tid) as $f) {
            $docs[] = ['📅 Resultados · ' . carrera_nombre_fecha($f, $T), $u . 'fecha&f=' . (int)$f['id'], ''];
            $docs[] = ['📋 Planilla en blanco · ' . carrera_nombre_fecha($f, $T), $u . 'planilla&f=' . (int)$f['id'], es_bolos($t) ? 'Para anotar los pinos de cada línea a mano.' : 'Para anotar los tiempos de cada manga a mano.'];
        }
        if (!carrera_fechas($tid)) $docs[] = ['📋 Planilla en blanco', $u . 'planilla', 'Lista de ' . mb_strtolower($T['participantes']) . ' por categoría con casillas vacías.'];
    } ?>
  <p class="suave">Se abre una hoja lista para imprimir. Para guardarla en PDF elija <b>«Guardar como PDF»</b> como impresora.</p>
  <div class="rejilla">
    <?php foreach ($docs as [$tit, $link, $desc]): ?>
      <a class="tarjeta partido-link" href="<?= e($link) ?>" target="_blank"><strong><?= e($tit) ?></strong><?= $desc !== '' ? '<p class="suave" style="margin:.3rem 0 0">' . e($desc) . '</p>' : '' ?></a>
    <?php endforeach; ?>
  </div>

<?php elseif ($s === 'copiar'): ?>
  <div class="tarjeta">
    <h2>📋 Copiar para la nueva temporada</h2>
    <p class="suave">Crea un campeonato nuevo con las mismas categorías y reglas<?= es_pruebas($t) ? ' y el mismo programa de pruebas' : '' ?><?= es_carrera($t) ? ' y penalizaciones' : '' ?>. Los resultados no se copian.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="copiar">
      <div class="campos">
        <div><label>Nombre del nuevo campeonato *</label><input type="text" name="nombre" required maxlength="100" value="<?= e($t['nombre']) ?>"></div>
        <div><label>Temporada</label><input type="text" name="temporada" maxlength="30" value="<?= e(temporada_siguiente((string)$t['temporada'])) ?>"></div>
      </div>
      <label class="check" style="font-weight:400"><input type="checkbox" name="con_inscritos" value="1" checked> Copiar también a los <?= e(mb_strtolower(es_duelo($t) ? textos_duelo($t)['participantes'] : (es_pruebas($t) ? textos_pruebas($t)['participantes'] : (es_bolos($t) ? textos_bolos()['participantes'] : textos_carrera($t)['participantes'])))) ?> activos<?= es_bolos($t) ? ' (con el handicap que tienen ahora)' : '' ?></label>
      <button class="btn" style="margin-top:8px">Crear la copia</button>
    </form>
  </div>

<?php else: /* inscripciones */
    $lista = filas('SELECT * FROM inscripciones_indiv WHERE torneo_id = ? ORDER BY CASE estado WHEN \'pendiente\' THEN 0 WHEN \'aprobada\' THEN 1 ELSE 2 END, id DESC', [$tid]);
    $cats = insc_indiv_categorias($t);
    $valor = (float)$t['insc_indiv_valor'];
    $abierta = insc_indiv_abierta($t); ?>
  <div class="aviso <?= $abierta ? 'aviso-ok' : 'aviso-info' ?>">
    <?= $abierta ? '✅ La inscripción en línea está <b>abierta</b> hasta el ' . e(fecha_bonita($t['insc_indiv_hasta'])) . ($valor > 0 ? ' · valor ' . dinero($valor) . (org_cobra_con_tarjeta($liga) ? ' (se cobra con tarjeta al inscribirse)' : '') : ' · sin costo') . '. Enlace para compartir: <a href="' . e(url_torneo($liga, $t)) . '?v=inscribirse" target="_blank">' . e(url_torneo($liga, $t)) . '?v=inscribirse</a>'
      : '🔒 La inscripción en línea está <b>cerrada</b>. Ábrala abajo para que los competidores se inscriban solos desde la página pública.' ?>
  </div>
  <?php if (!$lista): ?><div class="tarjeta">Todavía no hay inscripciones en línea.</div><?php endif; ?>
  <?php foreach ($lista as $ins):
      $wa = telefono_wa($ins['telefono']);
      $msg_ok = 'Hola ' . $ins['nombre'] . ', su inscripción en ' . $t['nombre'] . ' fue aprobada. Vea la programación y los resultados aquí: ' . url_torneo($liga, $t);
      $msg_no = 'Hola ' . $ins['nombre'] . ', su inscripción en ' . $t['nombre'] . ' no pudo ser aprobada' . ($ins['motivo'] !== '' ? ': ' . $ins['motivo'] : '.'); ?>
    <div class="tarjeta" style="margin-bottom:10px;<?= $ins['estado'] === 'pendiente' ? 'border-left:5px solid var(--acento,#f5a623)' : '' ?>">
      <div class="fila-titulo" style="margin:0">
        <div><strong><?= e($ins['nombre']) ?><?= $ins['pareja'] !== '' ? ' / ' . e($ins['pareja']) : '' ?></strong>
          <span class="etiqueta <?= ['pendiente' => 'amarilla', 'aprobada' => '', 'rechazada' => 'roja'][$ins['estado']] ?? '' ?>"><?= e(ucfirst($ins['estado'])) ?></span>
          <?php if ($valor > 0 || $ins['pago_token'] !== ''): ?><span class="etiqueta <?= $ins['pagado'] !== '' ? '' : 'roja' ?>"><?= $ins['pagado'] !== '' ? '💵 Pagado' : 'Sin pago' ?></span><?php endif; ?>
          <br><small class="suave">Cédula <?= e($ins['cedula']) ?> · 📱 <?= e($ins['telefono']) ?><?= $ins['email'] !== '' ? ' · ' . e($ins['email']) : '' ?><?= $ins['club'] !== '' ? ' · 🏢 ' . e($ins['club']) : '' ?><?= $ins['ciudad'] !== '' ? ' · ' . e($ins['ciudad']) : '' ?> · enviada el <?= e(fecha_corta(substr($ins['creado'], 0, 10))) ?> <?= e(substr($ins['creado'], 11, 5)) ?></small>
          <?php if (($txt = texto_inscripcion_indiv($t, $ins)) !== ''): ?><br><small><?= e($txt) ?></small><?php endif; ?>
          <?php if ($ins['estado'] === 'rechazada' && $ins['motivo'] !== ''): ?><br><small class="suave">Motivo: <?= e($ins['motivo']) ?></small><?php endif; ?>
          <?php if ($ins['estado'] !== 'pendiente' && $ins['revisado_por'] !== ''): ?><br><small class="suave">Revisada por <?= e($ins['revisado_por']) ?> el <?= e($ins['revisado_en']) ?></small><?php endif; ?>
        </div>
        <div class="acciones">
          <?php if ($wa && $ins['estado'] !== 'pendiente'): ?><a class="btn btn-wa btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($ins['estado'] === 'aprobada' ? $msg_ok : $msg_no) ?>">📲 Avisar</a><?php endif; ?>
          <?php if ($valor > 0): ?><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="marcar_pagado"><input type="hidden" name="iid" value="<?= (int)$ins['id'] ?>"><button class="btn btn-sec btn-chico"><?= $ins['pagado'] !== '' ? 'Quitar pagado' : 'Marcar pagado' ?></button></form><?php endif; ?>
        </div>
      </div>
      <?php if ($ins['estado'] === 'pendiente'): ?>
        <div class="acciones" style="margin-top:8px;align-items:flex-end;flex-wrap:wrap">
          <form method="post" class="acciones" style="align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="iid" value="<?= (int)$ins['id'] ?>">
            <?php if ($cats): ?><label style="margin:0">Categoría<br><select name="categoria_id"><option value="0">Automática<?= es_duelo($t) ? ' (por rama, peso y edad)' : '' ?></option><?php foreach ($cats as $id => $n): ?><option value="<?= (int)$id ?>" <?= (int)$ins['categoria_id'] === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <button class="btn btn-chico">✓ Aprobar</button>
          </form>
          <form method="post" class="acciones" style="align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="iid" value="<?= (int)$ins['id'] ?>">
            <input type="text" name="motivo" maxlength="200" placeholder="Motivo (opcional)" style="width:200px">
            <button class="btn btn-peligro btn-chico">✗ Rechazar</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?= html_inscripcion_indiv_config($t) ?>
<?php endif;
pie_admin();
