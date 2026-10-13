<?php
/* (v26) Página pública de una competencia de NATACIÓN o ATLETISMO: resultados por prueba, programa, medallero, puntos por club y mejores marcas */
$Tp = textos_pruebas($t);
$tidp = (int)$t['id'];
$bt = $base . '&t=' . $tidp;
$url_t = url_torneo($liga, $t);
$evs_p = pruebas_eventos($tidp);
$part_p = pruebas_participantes($tidp);
$pestp = ['resultados' => 'Resultados', 'programa' => 'Programa', 'medallero' => 'Medallero', 'puntos' => 'Puntos por club', 'mejores' => 'Ganadores', 'participantes' => $Tp['participantes']];
$es_diploma = get('v') === 'diploma';
if (!empty($docs_pub)) $pestp['documentos'] = '📄 Documentos';
if (insc_indiv_abierta($t)) $pestp['inscribirse'] = '📝 Inscribirse';
$vp = ['posiciones' => 'resultados', 'inicio' => 'resultados', 'fixture' => 'programa', 'equipos' => 'participantes'][get('v')] ?? get('v');
if (!isset($pestp[$vp])) $vp = $es_diploma ? 'diploma' : 'resultados';
?>
<div class="fila-titulo">
  <h1 style="margin:.2rem 0"><?= icono_individual($t) ?> <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?></small></h1>
  <?php if (count($torneos) > 1): ?>
    <form method="get"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>">
      <select name="t" onchange="this.form.submit()" aria-label="Campeonato">
        <?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $tidp ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?>
      </select></form>
  <?php endif; ?>
</div>
<p class="suave" style="margin:-4px 0 6px;font-size:.85rem">🔗 <a href="<?= e($url_t) ?>"><?= e(preg_replace('#^https?://#', '', $url_t)) ?></a>
  <button type="button" class="btn btn-sec btn-mini" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($url_t) ?>'); this.textContent='✓ copiado'">copiar</button></p>
<?= html_pestanas_publicas($pestp, $vp, $bt) ?>
<?= html_en_vivo_individual($liga, $t) ?>
<?php
if ($vp === 'inscribirse'):
    echo html_form_inscripcion_indiv($liga, $t, $base);

elseif ($vp === 'documentos'): ?>
  <h2>📄 Documentos</h2>
  <?php foreach ($docs_pub as $d): ?>
    <a class="tarjeta partido-link eq-celda" style="padding:12px;margin-bottom:8px" href="<?= e($d['archivo']) ?>" target="_blank" rel="noopener">
      <span style="font-size:1.8rem"><?= icono_documento($d['archivo']) ?></span>
      <span style="flex:1"><strong><?= e($d['titulo']) ?></strong><br><small><?= e(tipos_documento()[$d['tipo']] ?? 'Documento') ?> · publicado el <?= e(fecha_bonita(substr($d['creado'], 0, 10))) ?></small></span>
      <span class="btn btn-sec btn-chico">Ver</span>
    </a>
  <?php endforeach; ?>

<?php elseif ($vp === 'diploma'): // (v36) diploma imprimible de un corredor / atleta
    $ev = fila('SELECT * FROM prueba_eventos WHERE id = ? AND torneo_id = ?', [(int)get('ev'), $tidp]);
    $r = null;
    if ($ev) foreach (prueba_resultados($ev, $part_p) as $x) if ((int)$x['p']['id'] === (int)get('part') && $x['puesto'] !== null) $r = $x;
    if (!$ev || !$r): ?><div class="tarjeta">No hay diploma: ese participante no tiene resultado en esa prueba.</div>
    <?php else: ?>
      <div class="diploma">
        <div class="dip-liga"><?= e($liga['nombre']) ?></div>
        <div class="dip-titulo">DIPLOMA</div>
        <p>Otorgado a</p>
        <div class="dip-nombre"><?= e($r['p']['nombre']) ?></div>
        <p>por su participación en <b><?= e($t['nombre']) ?></b> · <?= e($ev['nombre']) ?><?= $ev['fecha'] !== '' ? ', el ' . e(fecha_bonita($ev['fecha'])) : '' ?></p>
        <div class="dip-datos"><span><b><?= (int)$r['puesto'] ?>.º</b><br>general</span><?php if (isset($r['puesto_grupo'])): ?><span><b><?= (int)$r['puesto_grupo'] ?>.º</b><br><?= e($r['grupo']) ?></span><?php endif; ?><span><b><?= e($r['texto']) ?></b><br><?= e(pv_titulo_columna($ev)) ?></span></div>
        <?php if ($r['p']['numero'] !== ''): ?><p class="suave">Dorsal <?= e($r['p']['numero']) ?></p><?php endif; ?>
      </div>
      <p class="no-imprimir" style="text-align:center"><button class="btn" onclick="window.print()">🖨 Imprimir o guardar en PDF</button></p>
      <style>.diploma{border:6px double var(--acento,#1b5e20);border-radius:14px;padding:34px 24px;text-align:center;max-width:820px;margin:10px auto;background:#fff;color:#222}
        .dip-liga{font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--acento,#1b5e20)}.dip-titulo{font-size:2.4rem;font-weight:800;letter-spacing:.2em;margin:.4rem 0}
        .dip-nombre{font-size:2rem;font-weight:700;border-bottom:2px solid #ccc;display:inline-block;padding:0 24px 6px;margin:.3rem 0 .6rem}
        .dip-datos{display:flex;justify-content:center;gap:28px;flex-wrap:wrap;margin:14px 0}.dip-datos span{font-size:.85rem}.dip-datos b{font-size:1.5rem}
        @media print{header,nav,footer,.pestanas,.no-imprimir,.fila-titulo,.suave a{display:none!important}.diploma{border-color:#333}}</style>
    <?php endif; ?>

<?php elseif ($vp === 'programa'): ?>
  <h2>📋 Programa de pruebas</h2>
  <?php if (!$evs_p): ?><div class="tarjeta">Todavía no se publica el programa.</div><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>N.°</th><th>Prueba</th><th>Rama y categoría</th><th>Día y hora</th><th class="num">Inscritos</th><th></th></tr>
    <?php foreach ($evs_p as $ev): $n_ins = (int)valor('SELECT COUNT(*) FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]); $con = prueba_con_marcas((int)$ev['id']); ?>
      <tr><td><?= (int)$ev['num'] ?></td><td><b><?= e($ev['nombre']) ?></b><?= $ev['nota'] !== '' ? '<br><small class="suave">' . e($ev['nota']) . '</small>' : '' ?></td>
        <td><?= e(trim((pruebas_ramas()[$ev['rama']] ?? '') . ' · ' . $ev['categoria'], ' ·')) ?></td>
        <td><?= e(trim(($ev['fecha'] !== '' ? fecha_corta($ev['fecha']) : '') . ' ' . $ev['hora'])) ?: '—' ?></td>
        <td class="num"><?= $n_ins ?></td>
        <td><?= $con ? '<a href="' . e($bt . '&v=resultados&ev=' . (int)$ev['id']) . '">Resultados</a>' : '<span class="suave">por disputarse</span>' ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

<?php elseif ($vp === 'medallero'): ?>
  <h2>🏅 Medallero</h2>
  <?= pruebas_html_medallero(pruebas_medallero($t), $Tp['participantes'] . ' con medalla') ?>

<?php elseif ($vp === 'puntos'):
    $pc = pruebas_puntos_clubes($t); ?>
  <h2>🏢 Puntos por club</h2>
  <p class="suave">Cada puesto suma puntos para su club: <?= e(implode(', ', array_map(fn($i, $p) => ($i + 1) . '.° ' . $p, array_keys(pruebas_cfg($t)['puntos']), pruebas_cfg($t)['puntos']))) ?>.</p>
  <?php if (!$pc): ?><div class="tarjeta">Todavía no hay resultados.</div><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>#</th><th>Club</th><th class="num">🥇</th><th class="num">🥈</th><th class="num">🥉</th><th class="num">Puntos</th></tr>
    <?php foreach ($pc as $i => $c): ?><tr><td><?= $i + 1 ?></td><td><b><?= e($c['club']) ?></b></td><td class="num"><?= $c['oro'] ?></td><td class="num"><?= $c['plata'] ?></td><td class="num"><?= $c['bronce'] ?></td><td class="num pts"><?= $c['puntos'] ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>

<?php elseif ($vp === 'mejores'): ?>
  <h2>⭐ Ganador de cada prueba</h2>
  <?= pruebas_html_mejores(pruebas_mejores($t)) ?>

<?php elseif ($vp === 'participantes'): ?>
  <h2>👥 <?= e($Tp['participantes']) ?> <small class="suave">(<?= count(array_filter($part_p, fn($p) => (int)$p['activo'])) ?>)</small></h2>
  <?php if (!$part_p): ?><div class="tarjeta">Aún no hay inscritos.</div><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th><?= e($Tp['participante']) ?></th><th>Club</th><th>Rama</th><th class="num">Pruebas</th></tr>
    <?php foreach ($part_p as $p): if (!(int)$p['activo']) continue; ?>
      <tr><td><?= e($p['nombre']) ?></td><td><?= e($p['club']) ?></td><td><?= e(['M' => 'Varón', 'F' => 'Dama'][$p['rama']] ?? '') ?></td>
        <td class="num"><?= (int)valor('SELECT COUNT(*) FROM prueba_resultados WHERE participante_id = ?', [$p['id']]) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

<?php else: /* resultados */
    if (!$evs_p): ?><div class="tarjeta">Todavía no se publica el programa de pruebas.</div><?php else:
    $evid = (int)get('ev');
    $con_res = array_values(array_filter($evs_p, fn($ev) => prueba_con_marcas((int)$ev['id'])));
    $ev = null;
    foreach ($evs_p as $x) if ((int)$x['id'] === $evid) $ev = $x;
    $ev = $ev ?? ($con_res ? end($con_res) : $evs_p[0]); ?>
  <form method="get" class="acciones" style="margin:8px 0">
    <input type="hidden" name="l" value="<?= e($liga['slug']) ?>"><input type="hidden" name="t" value="<?= $tidp ?>"><input type="hidden" name="v" value="resultados">
    <label>Prueba: <select name="ev" onchange="this.form.submit()">
      <?php foreach ($evs_p as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === (int)$ev['id'] ? 'selected' : '' ?>><?= prueba_con_marcas((int)$x['id']) ? '✓ ' : '' ?><?= e(prueba_nombre($x)) ?></option><?php endforeach; ?>
    </select></label>
  </form>
  <h2><?= e($ev['nombre']) ?> <small class="suave"><?= e(trim((pruebas_ramas()[$ev['rama']] ?? '') . ' · ' . $ev['categoria'], ' ·')) ?><?= $ev['fecha'] !== '' ? ' · ' . e(fecha_bonita($ev['fecha'])) : '' ?><?= $ev['hora'] !== '' ? ' ' . e($ev['hora']) : '' ?></small></h2>
  <?php $filas_ev = prueba_resultados($ev, $part_p);
    // (v36) carreras con muchos corredores: buscar por nombre o dorsal, y diploma de cada uno
    $es_masiva = in_array((string)($ev['formato'] ?? ''), ['ruta', 'segmentos'], true);
    $buscar = mb_strtolower(trim(get('buscar')));
    if ($es_masiva && $buscar !== '') $filas_ev = array_values(array_filter($filas_ev, fn($r) => str_contains(mb_strtolower($r['p']['nombre']), $buscar) || mb_strtolower((string)$r['p']['numero']) === $buscar));
    if ($es_masiva): ?>
      <form method="get" class="acciones" style="margin:6px 0"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>"><input type="hidden" name="t" value="<?= $tidp ?>"><input type="hidden" name="v" value="resultados"><input type="hidden" name="ev" value="<?= (int)$ev['id'] ?>">
        <input type="search" name="buscar" value="<?= e(get('buscar')) ?>" placeholder="🔎 Buscar por nombre o dorsal" style="max-width:280px"> <button class="btn btn-sec btn-chico">Buscar</button></form>
      <?php if ($buscar !== '' && $filas_ev): ?><div class="tarjeta" style="margin-bottom:8px"><?php foreach (array_slice($filas_ev, 0, 10) as $r): if ($r['puesto'] === null) continue; ?>
        <p style="margin:.2rem 0">🏃 <b><?= e($r['p']['nombre']) ?></b> · <?= (int)$r['puesto'] ?>.º general · <?= e($r['texto']) ?> · <?= e($r['grupo'] ?? '') ?>: <?= (int)($r['puesto_grupo'] ?? 0) ?>.º
          · <a href="<?= e($bt . '&v=diploma&ev=' . (int)$ev['id'] . '&part=' . (int)$r['p']['id']) ?>" target="_blank">📄 Diploma</a></p><?php endforeach; ?></div><?php endif; ?>
    <?php endif;
    if (!$filas_ev): ?><div class="tarjeta"><?= $buscar !== '' ? 'No se encontró a nadie con ese nombre o dorsal.' : 'Todavía no hay inscritos en esta prueba.' ?></div>
  <?php else: ?><div class="tarjeta"><?= prueba_html_resultados($t, $ev, $filas_ev) ?></div>
    <?php if (es_ciclo_pista($t) && ($det = pista_html_detalle($t, $ev, $part_p)) !== ''): ?><div class="tarjeta" style="margin-top:12px"><?= $det ?></div><?php endif; ?>
    <?php if (es_pruebas_v36($t) && ($det = pv_html_detalle($t, $ev, $part_p)) !== ''): ?><div class="tarjeta" style="margin-top:12px"><?= $det ?></div><?php endif; ?>
  <?php endif; ?>
  <?php if (count($con_res) > 1): ?>
    <h3>Otras pruebas con resultados</h3>
    <div class="acciones" style="flex-wrap:wrap"><?php foreach (array_reverse($con_res) as $x): if ((int)$x['id'] === (int)$ev['id']) continue; ?><a class="btn btn-sec btn-chico" href="<?= e($bt . '&v=resultados&ev=' . (int)$x['id']) ?>"><?= e(prueba_nombre($x, false)) ?></a> <?php endforeach; ?></div>
  <?php endif; ?>
<?php endif; endif;
