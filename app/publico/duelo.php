<?php
/* (v25) Página pública de un campeonato de DUELOS: llaves y tablas por categoría, programación, medallero e inscritos */
$Td = textos_duelo($t);
$tidd = (int)$t['id'];
$bt = $base . '&t=' . $tidd;
$url_t = url_torneo($liga, $t);
$cats_d = duelo_categorias($tidd);
$part_d = duelo_participantes($tidd);
$pestd = ['llaves' => 'Llaves y tablas', 'programacion' => 'Programación', 'medallero' => 'Medallero', 'participantes' => $Td['participantes']];
if (duelo_ranking((int)$liga['id'], (string)$t['modalidad'])) $pestd['ranking'] = '🏆 Ranking'; // (v36) ranking del circuito
if (!empty($docs_pub)) $pestd['documentos'] = '📄 Documentos';
if (insc_indiv_abierta($t)) $pestd['inscribirse'] = '📝 Inscribirse'; // (v26) inscripción en línea
$vd = ['posiciones' => 'llaves', 'inicio' => 'llaves', 'fixture' => 'programacion', 'equipos' => 'participantes'][get('v')] ?? get('v');
if (!isset($pestd[$vd])) $vd = 'llaves';
?>
<div class="fila-titulo">
  <h1 style="margin:.2rem 0"><?= icono_duelo($t) ?> <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?></small></h1>
  <?php if (count($torneos) > 1): ?>
    <form method="get"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>">
      <select name="t" onchange="this.form.submit()" aria-label="Campeonato">
        <?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $tidd ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?>
      </select></form>
  <?php endif; ?>
</div>
<p class="suave" style="margin:-4px 0 6px;font-size:.85rem">🔗 <a href="<?= e($url_t) ?>"><?= e(preg_replace('#^https?://#', '', $url_t)) ?></a>
  <button type="button" class="btn btn-sec btn-mini" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($url_t) ?>'); this.textContent='✓ copiado'">copiar</button></p>
<?= html_pestanas_publicas($pestd, $vd, $bt) ?>
<?= duelo_css() ?>
<?= html_en_vivo_individual($liga, $t) ?>
<?php
if ($vd === 'inscribirse'):
    echo html_form_inscripcion_indiv($liga, $t, $base);

elseif ($vd === 'documentos'): ?>
  <h2>📄 Documentos</h2>
  <?php foreach ($docs_pub as $d): ?>
    <a class="tarjeta partido-link eq-celda" style="padding:12px;margin-bottom:8px" href="<?= e($d['archivo']) ?>" target="_blank" rel="noopener">
      <span style="font-size:1.8rem"><?= icono_documento($d['archivo']) ?></span>
      <span style="flex:1"><strong><?= e($d['titulo']) ?></strong><br><small><?= e(tipos_documento()[$d['tipo']] ?? 'Documento') ?> · publicado el <?= e(fecha_bonita(substr($d['creado'], 0, 10))) ?></small></span>
      <span class="btn btn-sec btn-chico">Ver</span>
    </a>
  <?php endforeach; ?>

<?php elseif ($vd === 'participantes'):
    $grupos = [];
    foreach ($part_d as $p) if ((int)$p['activo']) $grupos[(int)($p['categoria_id'] ?? 0)][] = $p;
    if (!$grupos): ?><div class="tarjeta">Aún no hay <?= e(mb_strtolower($Td['participantes'])) ?> inscritos.</div><?php endif;
    foreach (array_merge($cats_d, [['id' => 0, 'nombre' => 'Sin categoría']]) as $c): if (empty($grupos[(int)$c['id']])) continue; ?>
      <h2><?= e($c['nombre']) ?> <small class="suave"><?= $c['id'] ? e(duelo_texto_categoria($c)) : '' ?></small></h2>
      <div class="tabla-scroll"><table>
        <tr><th><?= e($Td['participante']) ?></th><th>Club</th><?php if ($t['modalidad'] === 'MARC'): ?><th>Grado</th><?php endif; ?></tr>
        <?php foreach ($grupos[(int)$c['id']] as $p): ?>
          <tr><td><?= e(duelo_nombre($p)) ?><?= (int)$p['siembra'] ? ' <span class="suave">[' . (int)$p['siembra'] . ']</span>' : '' ?></td><td><?= e($p['club']) ?></td><?php if ($t['modalidad'] === 'MARC'): ?><td><?= e($p['grado']) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endforeach;

elseif ($vd === 'programacion'):
    $nombres_c = array_column($cats_d, 'nombre', 'id');
    $todos = filas("SELECT * FROM duelo_combates WHERE torneo_id = ? AND estado IN ('pendiente','jugado') AND a_id IS NOT NULL AND b_id IS NOT NULL ORDER BY CASE WHEN fecha = '' THEN 1 ELSE 0 END, fecha, hora, id", [$tidd]);
    $pend = array_values(array_filter($todos, fn($c) => $c['estado'] === 'pendiente'));
    $jug = array_reverse(array_values(array_filter($todos, fn($c) => $c['estado'] === 'jugado')));
    if (!$todos): ?><div class="tarjeta">Todavía no se sortean las categorías.</div><?php endif; ?>
    <?php if ($pend): ?><h2>Por jugar</h2><div class="tarjeta"><?= duelo_html_lista($t, $pend, $part_d, $nombres_c) ?></div><?php endif; ?>
    <?php if ($jug): ?><h2>Resultados</h2><div class="tarjeta"><?= duelo_html_lista($t, array_slice($jug, 0, 60), $part_d, $nombres_c) ?></div><?php endif; ?>

<?php elseif ($vd === 'ranking'): ?>
  <h2>🏆 Ranking del circuito de <?= e(mb_strtolower(nombre_modalidad($t['modalidad']))) ?></h2>
  <div class="tarjeta"><?= duelo_html_ranking(duelo_ranking((int)$liga['id'], (string)$t['modalidad'])) ?></div>

<?php elseif ($vd === 'medallero'): ?>
  <div class="tarjeta"><?= duelo_html_medallero($t, duelo_medallero($t)) ?></div>

<?php else: /* llaves */
    $con = array_values(array_filter($cats_d, fn($c) => (int)valor('SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ?', [$c['id']]) > 0
        || ($c['formato'] === 'escalera' && (int)valor('SELECT COUNT(*) FROM duelo_participantes WHERE categoria_id = ? AND activo = 1 AND siembra > 0', [$c['id']]) > 0)));
    if (!$con): ?><div class="tarjeta"><h2>Próximamente</h2><p>Todavía no se sortean las categorías de este campeonato.</p></div>
    <?php else:
        $sel = null;
        foreach ($con as $c) if ((int)$c['id'] === (int)get('c')) $sel = $c;
        $sel = $sel ?? $con[0]; ?>
      <p><?php foreach ($con as $c): ?><a class="btn <?= (int)$c['id'] === (int)$sel['id'] ? '' : 'btn-sec' ?> btn-chico" href="<?= e($bt . '&v=llaves&c=' . (int)$c['id']) ?>"><?= e($c['nombre']) ?></a> <?php endforeach; ?></p>
      <h2><?= e($sel['nombre']) ?> <small class="suave"><?= e(duelo_formatos()[$sel['formato']] ?? '') ?><?= duelo_texto_categoria($sel) !== '' ? ' · ' . e(duelo_texto_categoria($sel)) : '' ?></small></h2>
      <?php $m = duelo_medallas_categoria($t, $sel);
      if ($m['oro']): ?><div class="podio" style="margin-bottom:10px"><?php foreach (['oro', 'plata', 'bronce'] as $med) foreach ($m[$med] as $pid): if (!isset($part_d[$pid])) continue; ?><span><?= icono_medalla($med) ?> <b><?= e(duelo_nombre($part_d[$pid])) ?></b></span><?php endforeach; ?></div><?php endif; ?>
      <div class="tarjeta"><?= duelo_html_categoria($t, $sel, duelo_participantes($tidd, (int)$sel['id'])) ?></div>
    <?php endif;
endif;
