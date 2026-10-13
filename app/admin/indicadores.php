<?php
/*
 * (v28) INDICADORES DEL NEGOCIO de la liga o escuela: cómo va (ingresos, crecimiento, cobranza, asistencia, ocupación).
 */
$liga = requiere_liga();
$esc = es_escuela($liga);
$ind = $esc ? indicadores_escuela($liga) : indicadores_liga($liga);
cabecera_admin('Indicadores');
?>
<h1>📈 Indicadores de <?= e($liga['nombre']) ?></h1>
<p class="suave">Cómo va su <?= $esc ? 'escuela' : 'liga' ?> mes a mes. Se calcula solo con lo que registra en el sistema.</p>
<div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
  <?php foreach ($ind['tarjetas'] as [$t, $v, $var]): ?>
    <div class="tarjeta dato"><small><?= e($t) ?></small><b><?= is_string($v) && str_contains($v, '$') ? e($v) : e((string)$v) ?></b>
      <?php if (is_int($var)): ?><span class="<?= $var >= 0 ? 'sube' : 'baja' ?>"><?= $var >= 0 ? '▲' : '▼' ?> <?= abs($var) ?>% vs. mes anterior</span><?php endif; ?></div>
  <?php endforeach; ?>
</div>
<?php foreach ($ind['series'] as $nom => $serie): ?>
  <div class="tarjeta"><h2 style="margin-top:0"><?= e($nom) ?> <small class="suave">· últimos 12 meses</small></h2><?= html_barras($serie, str_contains($nom, '$')) ?></div>
<?php endforeach; ?>
<?php if (!empty($ind['ocupacion'])): ?>
  <div class="tarjeta"><h2 style="margin-top:0">👥 Ocupación de los grupos</h2>
    <table><tr><th>Grupo</th><th>Alumnos</th><th>Cupo</th><th>Ocupación</th></tr>
    <?php foreach ($ind['ocupacion'] as [$g, $n, $cupo]): $pct = $cupo > 0 ? min(100, round($n * 100 / $cupo)) : null; ?>
      <tr><td><?= e($g) ?></td><td class="num"><?= $n ?></td><td class="num"><?= $cupo ?: '—' ?></td>
        <td><?php if ($pct !== null): ?><div class="barra-avance" style="margin:0;max-width:220px"><span style="width:<?= $pct ?>%;<?= $pct >= 90 ? 'background:var(--roja)' : '' ?>"></span></div><small><?= $pct ?>%<?= $pct >= 90 ? ' · casi lleno' : ($pct < 40 ? ' · tiene espacio' : '') ?></small><?php else: ?><small class="suave">sin cupo definido</small><?php endif; ?></td></tr>
    <?php endforeach; ?></table></div>
<?php endif; ?>
<?php if (!empty($ind['deudores'])): ?>
  <div class="tarjeta"><h2 style="margin-top:0">💵 Equipos que más deben</h2>
    <table><?php foreach ($ind['deudores'] as [$n, $s]): ?><tr><td><?= e($n) ?></td><td class="num"><b><?= dinero($s) ?></b></td></tr><?php endforeach; ?></table>
    <a class="btn btn-sec btn-chico" href="admin.php?p=finanzas">Ver finanzas</a></div>
<?php endif;
pie_admin();
