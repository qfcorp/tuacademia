<?php
/*
 * (v28) Portada pública de una FEDERACIÓN: sus campeonatos federativos, los partidos de la semana de todas sus ligas
 * y las ligas y escuelas afiliadas (cada una con su propia página y sus torneos).
 */
$afiliadas = ligas_de_federacion($lid);
$propios = filas("SELECT * FROM torneos WHERE liga_id = ? ORDER BY CASE estado WHEN 'en_juego' THEN 0 WHEN 'sorteado' THEN 1 WHEN 'inscripcion' THEN 2 ELSE 3 END, id DESC LIMIT 30", [$lid]);
$semana = partidos_federacion($lid, date('Y-m-d'), date('Y-m-d', strtotime('+6 days')), 120);
cabecera_publica($liga['nombre'], $liga);
?>
<h1 style="margin-bottom:.2rem">🏛 <?= e($liga['nombre']) ?></h1>
<p class="suave"><?= count($afiliadas) ?> ligas y escuelas afiliadas<?= $liga['ciudad'] !== '' ? ' · ' . e($liga['ciudad']) : '' ?></p>

<?php if ($propios): ?>
  <h2>🏆 Campeonatos de la federación</h2>
  <div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
    <?php foreach ($propios as $t): ?>
      <a class="tarjeta" style="text-decoration:none;color:inherit" href="<?= e(url_torneo($liga, $t)) ?>">
        <b><?= es_individual($t) ? icono_individual($t) : textos_deporte($t)['icono'] ?> <?= e($t['nombre']) ?></b><br>
        <small class="suave"><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?><?= $t['estado'] === 'finalizado' ? ' · finalizado' : '' ?></small></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2>📅 Partidos de esta semana en la federación</h2>
<?php if (!$semana): ?><p class="suave">No hay partidos programados esta semana.</p><?php endif; ?>
<?php $dia = ''; foreach ($semana as $p): if ($p['fecha'] !== $dia): $dia = $p['fecha']; ?><h3><?= e(fecha_bonita($dia)) ?></h3><?php endif; ?>
  <div style="margin-bottom:4px"><small class="suave"><?= e($p['liga']) ?> · <?= e($p['torneo']) ?></small>
    <?= tarjeta_partido($p, 'index.php?l=' . rawurlencode($p['slug']) . '&t=' . (int)$p['torneo_id'] . '&p=' . (int)$p['id']) ?></div>
<?php endforeach; ?>

<h2>🏟 Ligas y escuelas afiliadas</h2>
<?php if (!$afiliadas): ?><p class="suave">Todavía no hay ligas afiliadas.</p><?php endif; ?>
<div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
  <?php foreach ($afiliadas as $m): ?>
    <a class="tarjeta" style="text-decoration:none;color:inherit;display:flex;gap:10px;align-items:center" href="<?= e(url_liga($m)) ?>">
      <?= escudo($m['logo'], $m['nombre'], $m['color'], 'logo-liga') ?>
      <span><b><?= e($m['nombre']) ?></b><br><small class="suave"><?= e(es_escuela($m) ? 'Escuela · ' . texto_deportes(deportes_de_escuela($m)) : nombre_modalidad($m['modalidad'])) ?><?= $m['ciudad'] !== '' ? ' · ' . e($m['ciudad']) : '' ?></small></span></a>
  <?php endforeach; ?>
</div>
<?php
pie_publico();
