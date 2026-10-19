<?php
/* (v12) TABLA COMPARATIVA: todos los planes lado a lado, función por función.  /comparar  (?para=escuelas) */
$tipo = es_academia() || get('para') === 'escuelas' ? 'escuela' : 'liga'; // (TuAcademia) solo academias
$planes = array_values(array_filter(filas("SELECT * FROM planes WHERE activo = 1 AND tipo_cuenta IN ('ambos', ?) ORDER BY orden, precio_mensual", [$tipo]), fn($p) => opciones_precio($p)));
$mods = modulos_sistema($tipo);
$celda = fn(bool $si) => $si ? '<span class="comp-si">✓</span>' : '<span class="comp-no">—</span>';
cabecera_publica('Comparar planes · ' . marca());
?>
<p><a href="<?= $tipo === 'escuela' && !es_academia() ? 'escuelas' : 'index.php' ?>#planes">← Volver</a></p>
<div class="fila-titulo"><h1>📊 Comparar planes <?= es_academia() ? '' : ($tipo === 'escuela' ? 'para escuelas' : 'para ligas') ?></h1>
  <nav class="pestanas" style="margin:0<?= es_academia() ? ';display:none' : '' ?>"><a class="<?= $tipo === 'liga' ? 'activa' : '' ?>" href="comparar">Ligas</a><a class="<?= $tipo === 'escuela' ? 'activa' : '' ?>" href="comparar?para=escuelas">Escuelas</a></nav></div>
<?php if (!$planes): ?><p class="suave">No hay planes publicados.</p><?php else: ?>
<div class="tabla-scroll"><table class="comparativa">
  <tr><th></th><?php foreach ($planes as $pl): ?><th class="<?= $pl['destacado'] ? 'comp-dest' : '' ?>"><?= e($pl['nombre']) ?><?= $pl['destacado'] ? '<br><small>Recomendado</small>' : '' ?></th><?php endforeach; ?></tr>
  <tr class="comp-grupo"><td colspan="<?= count($planes) + 1 ?>">💲 Precio</td></tr>
  <tr><td>Por módulos</td><?php foreach ($planes as $pl): ?><td class="num"><?= e(texto_cotizacion()) ?></td><?php endforeach; ?></tr>
  <tr class="comp-grupo"><td colspan="<?= count($planes) + 1 ?>">📏 Capacidad</td></tr>
  <tr><td>Torneos a la vez</td><?php foreach ($planes as $pl): ?><td class="num"><?= (int)$pl['max_campeonatos'] ?: 'Ilimitados' ?></td><?php endforeach; ?></tr>
  <?php if ($tipo === 'liga'): ?>
  <tr><td>Equipos por torneo</td><?php foreach ($planes as $pl): ?><td class="num"><?= (int)$pl['max_equipos_torneo'] ?: 'Sin límite' ?></td><?php endforeach; ?></tr>
  <tr><td>Equipos en la liga</td><?php foreach ($planes as $pl): ?><td class="num"><?= (int)$pl['max_equipos'] ?: 'Sin límite' ?></td><?php endforeach; ?></tr>
  <?php else: ?>
  <tr><td>Alumnos</td><?php foreach ($planes as $pl): ?><td class="num"><?= (int)$pl['max_alumnos'] ?: 'Sin límite' ?></td><?php endforeach; ?></tr>
  <?php endif; ?>
  <tr><td>🌐 Dominio propio incluido (pago de 1 año)*</td><?php foreach ($planes as $pl): ?><td class="num"><?= $celda((int)($pl['dominio_anual'] ?? 0) === 1) ?></td><?php endforeach; ?></tr>
  <tr class="comp-grupo"><td colspan="<?= count($planes) + 1 ?>">✅ Incluido en todos</td></tr>
  <?php foreach (funciones_base($tipo) as $f): ?><tr><td><?= e($f) ?></td><?php foreach ($planes as $pl): ?><td class="num"><?= $celda(true) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  <tr class="comp-grupo"><td colspan="<?= count($planes) + 1 ?>">🧩 Módulos</td></tr>
  <?php foreach ($mods as $k => [$ico, $nom, $para, $desc]): ?><tr><td title="<?= e($desc) ?>"><?= $ico ?> <?= e($nom) ?></td><?php foreach ($planes as $pl): ?><td class="num"><?= $celda(in_array($k, modulos_plan($pl), true)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  <tr><td></td><?php foreach ($planes as $pl): ?><td class="num"><a class="btn btn-chico<?= $pl['destacado'] ? '' : ' btn-sec' ?>" href="<?= e(url_plan($pl)) ?>">Ver detalle</a><br><a class="btn btn-chico" style="margin-top:4px" href="<?= e(url_empezar('', (int)$pl['id'])) ?>"><?= e(texto_empezar(true)) ?></a></td><?php endforeach; ?></tr>
</table></div>
<?php if (combo_descuento() > 0): ?><p><b>🤝 Combo liga + escuela:</b> la misma persona con una liga y una escuela, con el mismo usuario, tiene descuento en las dos. Se lo incluimos en su cotización.</p><?php endif; ?>
<p><b>Todo es por módulos:</b> usted elige los módulos y las disciplinas que necesita y paga solo por eso. Pida su demo y le enviamos una cotización a su medida.</p>
<?php if (nota_dominio() !== ''): ?><p class="suave" style="font-size:.85rem">* <?= e(nota_dominio()) ?></p><?php endif; ?>
<p class="suave">Pase el dedo o el mouse sobre cada módulo para ver qué incluye. Cobros con tarjeta: <?= e(texto_tasas_publico()) ?>.</p>
<?php endif;
pie_publico();
