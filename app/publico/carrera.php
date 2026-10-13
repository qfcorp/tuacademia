<?php
/* (v22) Página pública de un campeonato de carreras (autocross, ciclismo): clasificación general, fechas y participantes */
$Tc = textos_carrera($t);
$cfg = carrera_cfg($t);
$tidc = (int)$t['id'];
$bt = $base . '&t=' . $tidc;
$url_t = url_torneo($liga, $t);
$fechas_c = carrera_fechas($tidc);
$pestc = ['general' => 'Clasificación', 'fechas' => $Tc['fechas'], 'participantes' => $Tc['participantes']];
if (!empty($docs_pub)) $pestc['documentos'] = '📄 Documentos';
if (insc_indiv_abierta($t)) $pestc['inscribirse'] = '📝 Inscribirse'; // (v26) inscripción en línea // (v23) reglamento del campeonato de carreras
// (liga.php ya reinició $v a «inicio» porque no conoce estas pestañas: se lee de nuevo; /tabla y /fixture de la dirección corta también sirven)
$vc = ['posiciones' => 'general', 'inicio' => 'general', 'fixture' => 'fechas', 'equipos' => 'participantes'][get('v')] ?? get('v');
if (!isset($pestc[$vc])) $vc = 'general';
?>
<div class="fila-titulo">
  <h1 style="margin:.2rem 0"><?= icono_carrera($t) ?> <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?></small></h1>
  <?php if (count($torneos) > 1): ?>
    <form method="get"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>">
      <select name="t" onchange="this.form.submit()" aria-label="Campeonato">
        <?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $tidc ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?>
      </select></form>
  <?php endif; ?>
</div>
<p class="suave" style="margin:-4px 0 6px;font-size:.85rem">🔗 <a href="<?= e($url_t) ?>"><?= e(preg_replace('#^https?://#', '', $url_t)) ?></a>
  <button type="button" class="btn btn-sec btn-mini" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($url_t) ?>'); this.textContent='✓ copiado'">copiar</button></p>
<?= html_pestanas_publicas($pestc, $vc, $bt) ?>
<?= html_en_vivo_individual($liga, $t) ?>
<?php
if ($vc === 'inscribirse'):
    echo html_form_inscripcion_indiv($liga, $t, $base);

elseif ($vc === 'documentos'): ?>
  <h2>📄 Documentos</h2>
  <?php foreach ($docs_pub as $d): ?>
    <a class="tarjeta partido-link eq-celda" style="padding:12px;margin-bottom:8px" href="<?= e($d['archivo']) ?>" target="_blank" rel="noopener">
      <span style="font-size:1.8rem"><?= icono_documento($d['archivo']) ?></span>
      <span style="flex:1"><strong><?= e($d['titulo']) ?></strong><br><small><?= e(tipos_documento()[$d['tipo']] ?? 'Documento') ?><?= $d['torneo'] ? ' · ' . e($d['torneo']) : '' ?> · publicado el <?= e(fecha_bonita(substr($d['creado'], 0, 10))) ?></small></span>
      <span class="btn btn-sec btn-chico">Ver</span>
    </a>
  <?php endforeach; ?>
<?php elseif ($vc === 'participantes'):
    $cats_c = carrera_categorias($tidc);
    $part_c = filas('SELECT * FROM carrera_participantes WHERE torneo_id = ? AND activo = 1 ORDER BY CAST(numero AS INTEGER), nombre', [$tidc]);
    if (!$part_c): ?><div class="tarjeta">Aún no hay <?= e(mb_strtolower($Tc['participantes'])) ?> inscritos.</div><?php endif;
    $grupos = [];
    foreach ($part_c as $p) $grupos[(int)($p['categoria_id'] ?? 0)][] = $p;
    $nombres_cat = array_column($cats_c, null, 'id');
    $orden_c = array_merge(array_map('intval', array_column($cats_c, 'id')), [0]);
    foreach ($orden_c as $cid): if (empty($grupos[$cid])) continue; ?>
      <h2><?= e($cid ? $nombres_cat[$cid]['nombre'] : 'Sin categoría') ?> <small class="suave"><?= $cid ? e(carrera_texto_categoria($nombres_cat[$cid])) : '' ?></small></h2>
      <div class="tabla-scroll"><table>
        <tr><th>#</th><th><?= e($Tc['participante']) ?></th><th>Club</th><th><?= e($Tc['vehiculo']) ?></th></tr>
        <?php foreach ($grupos[$cid] as $p): ?>
          <tr><td><?= e($p['numero']) ?></td><td><?= e($p['nombre']) ?></td><td><?= e($p['club']) ?></td>
            <td><?= e($p['vehiculo']) ?><?= (int)$p['cc'] > 0 ? ' · ' . number_format((int)$p['cc'], 0, ',', '.') . ' cc' : '' ?><?= $p['aspiracion'] === 'turbo' ? ' · turbo' : '' ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endforeach;

elseif ($vc === 'fechas'):
    $con_res = array_values(array_filter($fechas_c, fn($f) => (int)valor('SELECT COUNT(*) FROM carrera_resultados WHERE fecha_id = ?', [$f['id']]) > 0));
    if (!$fechas_c): ?><div class="tarjeta">Aún no hay <?= e(mb_strtolower($Tc['fechas'])) ?> programadas.</div>
    <?php else:
        $sel = null;
        foreach ($fechas_c as $f) if ((int)$f['id'] === (int)get('c')) $sel = $f;
        $sel = $sel ?? ($con_res ? end($con_res) : $fechas_c[0]); ?>
      <p><?php foreach ($fechas_c as $f): ?><a class="btn <?= (int)$f['id'] === (int)$sel['id'] ? '' : 'btn-sec' ?> btn-chico" href="<?= e($bt . '&v=fechas&c=' . (int)$f['id']) ?>"><?= e(carrera_nombre_fecha($f, $Tc)) ?></a> <?php endforeach; ?></p>
      <p class="suave"><?= $sel['fecha'] !== '' ? '📅 ' . e(fecha_bonita($sel['fecha'])) : '' ?> <?= $sel['lugar'] !== '' ? '📍 ' . e($sel['lugar']) : '' ?> <?= e($sel['nota']) ?></p>
      <?php $cl = carrera_clasificacion_fecha($t, $sel);
        if (!$cl['abs']): ?><div class="tarjeta">Todavía no hay tiempos de esta <?= e(mb_strtolower($Tc['fecha'])) ?>.</div>
        <?php elseif ($t['modalidad'] === 'CICLO'): // (v34) ruta con reglas UCI: general, puntos, montaña, joven, equipos y etapas ?>
            <?= ciclo_html_carrera($t, $sel) ?>
            <p class="suave" style="font-size:.85rem">Reglas de la UCI: la general suma los tiempos de las etapas menos las bonificaciones; los que llegan en el mismo grupo (menos de 1 s) reciben el mismo tiempo (m.t.). OTL: fuera de control · DNF: no terminó · DSQ: descalificado · DNS: no largó.</p>
        <?php else:
            foreach ($cl['cats'] as $c): ?>
              <h2><?= e($c['cat']['nombre']) ?></h2><?= carrera_html_tabla_fecha($c['filas'], $t, $sel) ?>
            <?php endforeach; ?>
            <h2>Absoluta</h2><?= carrera_html_tabla_fecha($cl['abs'], $t, $sel, false) ?>
            <?php if ($t['modalidad'] === 'MTB'): ?>
            <p class="suave" style="font-size:.85rem"><?= e(mtb_formatos()[mtb_formato($t, $sel)]) ?>. En XCO gana quien completa más vueltas; los retirados por la regla del 80 % o doblados quedan clasificados con sus vueltas de menos. DNF: no terminó · DSQ: descalificado · DNS: no largó.</p>
            <?php else: ?>
            <p class="suave" style="font-size:.85rem">Tiempo final = tiempo de la manga + penalización por cono (<?= e(rtrim(rtrim(number_format($cfg['pen_cono'], 2, '.', ''), '0'), '.')) ?> s) ± otras penalizaciones o bonificaciones.
              <?= $cfg['modo'] === 'suma' ? 'Vale la suma de las mangas.' : 'Vale la mejor manga.' ?> DNF: no terminó · DSQ: descalificado · DNS: no largó.</p>
            <?php endif; ?>
        <?php endif;
    endif;

else: /* general */
    $g = carrera_general($t);
    if (!$g['cats']): ?><div class="tarjeta"><h2>Próximamente</h2><p>Aún no hay tiempos publicados en este campeonato.</p></div>
    <?php else: ?>
      <p class="suave"><?= $cfg['clasif'] === 'tiempo' ? 'Clasificación por tiempo total acumulado.' : 'Puntos por puesto en cada ' . e(mb_strtolower($Tc['fecha'])) . ($cfg['descarte'] ? ' (se descartan las ' . $cfg['descarte'] . ' peores)' : '') . '. Cada categoría puntúa aparte.' ?></p>
      <?php foreach ($g['cats'] as $c): ?>
        <h2><?= e($c['cat']['nombre']) ?></h2><?= carrera_html_tabla_general($c['filas'], $g['fechas'], $cfg, $Tc) ?>
      <?php endforeach;
    endif;
endif;
