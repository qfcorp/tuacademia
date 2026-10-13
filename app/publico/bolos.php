<?php
/* (v25) Página pública de un campeonato de BOLOS: clasificación general, jornadas, clubes y jugadores */
$Tb = textos_bolos();
$tidb = (int)$t['id'];
$bt = $base . '&t=' . $tidb;
$url_t = url_torneo($liga, $t);
$calc_b = bolos_calcular($t);
$cfg_b = $calc_b['cfg'];
$pestb = ['general' => 'Clasificación', 'jornadas' => 'Jornadas', 'clubes' => 'Clubes', 'participantes' => 'Jugadores'];
if (!empty($docs_pub)) $pestb['documentos'] = '📄 Documentos';
if (insc_indiv_abierta($t)) $pestb['inscribirse'] = '📝 Inscribirse'; // (v26) inscripción en línea
$vb = ['posiciones' => 'general', 'inicio' => 'general', 'fixture' => 'jornadas', 'equipos' => 'participantes'][get('v')] ?? get('v');
if (!isset($pestb[$vb])) $vb = 'general';
?>
<div class="fila-titulo">
  <h1 style="margin:.2rem 0">🎳 <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · Bolos</small></h1>
  <?php if (count($torneos) > 1): ?>
    <form method="get"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>">
      <select name="t" onchange="this.form.submit()" aria-label="Campeonato">
        <?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $tidb ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?>
      </select></form>
  <?php endif; ?>
</div>
<p class="suave" style="margin:-4px 0 6px;font-size:.85rem">🔗 <a href="<?= e($url_t) ?>"><?= e(preg_replace('#^https?://#', '', $url_t)) ?></a>
  <button type="button" class="btn btn-sec btn-mini" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($url_t) ?>'); this.textContent='✓ copiado'">copiar</button></p>
<?= html_pestanas_publicas($pestb, $vb, $bt) ?>
<?= html_en_vivo_individual($liga, $t) ?>
<?php
if ($vb === 'inscribirse'):
    echo html_form_inscripcion_indiv($liga, $t, $base);

elseif ($vb === 'documentos'): ?>
  <h2>📄 Documentos</h2>
  <?php foreach ($docs_pub as $d): ?>
    <a class="tarjeta partido-link eq-celda" style="padding:12px;margin-bottom:8px" href="<?= e($d['archivo']) ?>" target="_blank" rel="noopener">
      <span style="font-size:1.8rem"><?= icono_documento($d['archivo']) ?></span>
      <span style="flex:1"><strong><?= e($d['titulo']) ?></strong><br><small><?= e(tipos_documento()[$d['tipo']] ?? 'Documento') ?> · publicado el <?= e(fecha_bonita(substr($d['creado'], 0, 10))) ?></small></span>
      <span class="btn btn-sec btn-chico">Ver</span>
    </a>
  <?php endforeach; ?>

<?php elseif ($vb === 'participantes'):
    $grupos = bolos_por_categoria($t, array_map(fn($p) => ['p' => $p], array_values(array_filter($calc_b['part'], fn($p) => (int)$p['activo'] === 1))));
    if (!$grupos): ?><div class="tarjeta">Aún no hay jugadores inscritos.</div><?php endif;
    foreach ($grupos as $c): ?>
      <h2><?= e($c['cat']['nombre']) ?> <small class="suave"><?= e($c['cat']['nota'] ?? '') ?></small></h2>
      <div class="tabla-scroll"><table>
        <tr><th>Jugador</th><th>Club</th><th class="num">Promedio</th></tr>
        <?php foreach ($c['filas'] as $x): $g = $calc_b['general'][(int)$x['p']['id']] ?? null; ?>
          <tr><td><?= e($x['p']['nombre']) ?></td><td><?= e($x['p']['club']) ?></td><td class="num"><?= $g ? bolos_fmt_prom($g['promedio']) : '—' ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endforeach;

elseif ($vb === 'clubes'): ?>
  <p class="suave">Suma de los <?= BOLOS_POR_CLUB ?> mejores jugadores de cada club (total <?= $cfg_b['hcp'] !== 'no' ? 'con handicap' : 'de pinos' ?>).</p>
  <?= bolos_html_clubes(bolos_clubes($calc_b)) ?>

<?php elseif ($vb === 'jornadas'):
    $fechas_b = $calc_b['fechas'];
    if (!$fechas_b): ?><div class="tarjeta">Aún no hay jornadas programadas.</div>
    <?php else:
        $con_res = array_values(array_filter($fechas_b, fn($f) => !empty($calc_b['jornadas'][(int)$f['id']])));
        $sel = null;
        foreach ($fechas_b as $f) if ((int)$f['id'] === (int)get('c')) $sel = $f;
        $sel = $sel ?? ($con_res ? end($con_res) : $fechas_b[0]); ?>
      <p><?php foreach ($fechas_b as $f): ?><a class="btn <?= (int)$f['id'] === (int)$sel['id'] ? '' : 'btn-sec' ?> btn-chico" href="<?= e($bt . '&v=jornadas&c=' . (int)$f['id']) ?>"><?= e(carrera_nombre_fecha($f, $Tb)) ?></a> <?php endforeach; ?></p>
      <p class="suave"><?= $sel['fecha'] !== '' ? '📅 ' . e(fecha_bonita($sel['fecha'])) : '' ?> <?= $sel['lugar'] !== '' ? '📍 ' . e($sel['lugar']) : '' ?> <?= e($sel['nota']) ?></p>
      <?php $cl = bolos_jornada($t, $sel, $calc_b);
        if (!$cl['n']): ?><div class="tarjeta">Todavía no hay resultados de esta jornada.</div>
        <?php else: foreach ($cl['cats'] as $c): ?>
          <h2><?= e($c['cat']['nombre']) ?></h2><?= bolos_html_tabla_jornada($c['filas'], $cl['lineas'], $cfg_b) ?>
        <?php endforeach; endif;
    endif;

else: /* general */
    $g = bolos_general($t, $calc_b);
    if (!$g['cats']): ?><div class="tarjeta"><h2>Próximamente</h2><p>Aún no hay resultados publicados en este campeonato.</p></div>
    <?php else: ?>
      <?= bolos_html_destacados(bolos_destacados($calc_b)) ?>
      <p class="suave"><?= e(bolos_textos_clasif()[$cfg_b['clasif']]) ?> · <?= e(bolos_textos_hcp()[$cfg_b['hcp']]) ?>. J1, J2… = total de cada jornada.</p>
      <?php foreach ($g['cats'] as $c): ?>
        <h2><?= e($c['cat']['nombre']) ?></h2><?= bolos_html_tabla_general($c['filas'], $g['fechas'], $cfg_b) ?>
      <?php endforeach;
    endif;
endif;
