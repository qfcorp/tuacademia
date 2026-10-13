<?php
/* Página de inicio del panel */
$liga = liga_trabajo();

if (!$liga) {
    if (es_super()) {
        ir('admin.php?p=ligas');
    }
    cabecera_admin('Inicio');
    echo '<div class="aviso aviso-error">Su usuario no tiene una liga asignada. Pida al administrador que le asigne una.</div>';
    pie_admin();
    return;
}

$lid = (int)$liga['id'];
$n_equipos = (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = ?', [$lid]);
$n_jugadores = (int)valor('SELECT COUNT(*) FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.activo = 1', [$lid]);
$torneos = filas('SELECT * FROM torneos WHERE liga_id = ? ORDER BY id DESC', [$lid]);
// (v23) Los campeonatos de carreras van aparte: el "campeonato actual" del resumen es de un deporte de equipo
$carreras_act = array_values(array_filter($torneos, fn($t) => es_individual($t) && $t['estado'] !== 'finalizado')); // (v25) carreras, duelos y bolos
$de_equipo = array_values(array_filter($torneos, fn($t) => !es_individual($t)));
$activo = null;
foreach ($de_equipo as $t) {
    if ($t['estado'] !== 'finalizado') { $activo = $t; break; }
}
$activo = $activo ?? ($de_equipo[0] ?? null);

cabecera_admin('Resumen');
?>
<h1>Resumen de <?= e($liga['nombre']) ?></h1>
<div class="cifras">
  <div class="cifra"><b><?= $n_equipos ?></b>Equipos</div>
  <div class="cifra"><b><?= $n_jugadores ?></b>Jugadores activos</div>
  <div class="cifra"><b><?= count($torneos) ?></b>Campeonatos</div>
  <div class="cifra"><b><?= $activo ? (int)$activo['jugadores_cancha'] . ' vs ' . (int)$activo['jugadores_cancha'] : '—' ?></b><?= $activo ? e(nombre_modalidad($activo['modalidad'])) . ' (campeonato actual)' : 'Sin campeonato' ?></div>
</div>
<?= html_botones_inicio() // (v19) botones grandes con ícono ?>

<?= html_banner_novedades() // (v27) ?>
<?= html_primeros_pasos($liga) // (v27) guía con barra de avance ?>

<?php
// Alertas: partidos en vivo, informes arbitrales pendientes o con diferencias
$vivos = filas("SELECT p.id FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado = 'en_vivo'", [$lid]);
$revisar = filas("SELECT p.id FROM partidos p JOIN torneos t ON t.id = p.torneo_id
                  WHERE t.liga_id = ? AND p.estado IN ('jugado','wo') AND p.revisado = 0 AND p.arbitro_id IS NOT NULL
                  ORDER BY p.fecha DESC LIMIT 40", [$lid]);
$con_dif = [];
$sin_informe = [];
$sin_calificar = [];
foreach (filas("SELECT p.id FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND t.calificar_arbitro = 'obligatorio'
                AND p.estado IN ('jugado','wo') ORDER BY p.fecha DESC LIMIT 60", [$lid]) as $r) {
    $pp = partido_completo((int)$r['id']);
    if (calificacion_pendiente($pp)) $sin_calificar[] = $pp;
}
foreach ($revisar as $r) {
    $pp = partido_completo((int)$r['id']);
    $c = comparar_informe($pp);
    if ($c['estado'] === 'enviado' && $c['diferencias']) $con_dif[] = [$pp, count($c['diferencias'])];
    elseif (in_array($c['estado'], ['pendiente', 'borrador'], true)) $sin_informe[] = $pp;
}
if ($vivos || $con_dif || $sin_informe || $sin_calificar): ?>
<div class="rejilla-2">
  <?php if ($vivos): ?>
  <div class="tarjeta"><h3 style="margin-top:0">🔴 En vivo ahora</h3>
    <?php foreach ($vivos as $r) { $pp = partido_completo((int)$r['id']); echo tarjeta_partido($pp, 'admin.php?p=partido&id=' . (int)$pp['id'] . '&s=vocalia'); } ?></div>
  <?php endif; ?>
  <?php if ($con_dif): ?>
  <div class="tarjeta"><h3 style="margin-top:0">⚠️ Vocalía y árbitro no coinciden</h3>
    <ul><?php foreach ($con_dif as [$pp, $n]): ?><li><a href="admin.php?p=partido&amp;id=<?= (int)$pp['id'] ?>&amp;s=arbitro"><?= e($pp['local_nombre'] . ' vs ' . $pp['visitante_nombre']) ?></a> — <?= $n ?> diferencia(s)</li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($sin_calificar): ?>
  <div class="tarjeta"><h3 style="margin-top:0">⭐ Falta calificar al árbitro (obligatorio)</h3>
    <ul><?php foreach ($sin_calificar as $pp): ?><li><a href="admin.php?p=partido&amp;id=<?= (int)$pp['id'] ?>&amp;s=arbitro#calificacion"><?= e($pp['local_nombre'] . ' vs ' . $pp['visitante_nombre']) ?></a> — <?= e(nombre_arbitro($pp)) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($sin_informe): ?>
  <div class="tarjeta"><h3 style="margin-top:0">📝 Falta el informe del árbitro</h3>
    <ul><?php foreach ($sin_informe as $pp): ?><li><a href="admin.php?p=partido&amp;id=<?= (int)$pp['id'] ?>&amp;s=arbitro"><?= e($pp['local_nombre'] . ' vs ' . $pp['visitante_nombre']) ?></a> — <?= e(nombre_arbitro($pp)) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ((int)($liga['mostrar_cumples'] ?? 1) === 1) echo html_cumples($liga, '', true); // (v18) ?>

<?php if ($carreras_act): // (v23/v25) ?>
<h2>🏅 Deportes individuales</h2>
<div class="rejilla-2">
<?php foreach ($carreras_act as $ct): ?>
  <div class="tarjeta">
    <h3 style="margin-top:0"><?= icono_individual($ct) ?> <?= e($ct['nombre']) ?></h3>
    <p class="suave" style="margin:.2rem 0"><?= e(nombre_modalidad($ct['modalidad'])) ?> · <?= e(resumen_individual($ct)) ?></p>
    <?php foreach (array_slice(lideres_individual($ct), 0, 4, true) as $cat_n => $lider): ?>
      <p style="margin:.2rem 0"><b><?= e($cat_n) ?>:</b> <?= e($lider) ?></p>
    <?php endforeach; ?>
    <a class="btn btn-sec btn-chico" href="<?= e(url_admin_individual($ct)) ?>">Administrar</a>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($activo):
    $proximos = partidos_torneo((int)$activo['id'], "AND p.estado NOT IN ('jugado','wo')");
    $ultimos = array_reverse(partidos_torneo((int)$activo['id'], "AND p.estado IN ('jugado','wo')"));
    $susp = suspensiones((int)$activo['id']);
?>
<div class="fila-titulo">
  <h2>Campeonato: <?= e($activo['nombre']) ?></h2>
  <a class="btn btn-sec btn-chico" href="admin.php?p=torneo&amp;id=<?= (int)$activo['id'] ?>">Administrar campeonato</a>
</div>
<div class="rejilla-2">
  <div>
    <h3>Próximos partidos</h3>
    <?php if (!$proximos): ?><p class="suave">No hay partidos pendientes.</p><?php endif; ?>
    <?php foreach (array_slice($proximos, 0, 6) as $p) echo tarjeta_partido($p, 'admin.php?p=partido&id=' . (int)$p['id']); ?>
  </div>
  <div>
    <h3>Últimos resultados</h3>
    <?php if (!$ultimos): ?><p class="suave">Aún no hay resultados.</p><?php endif; ?>
    <?php foreach (array_slice($ultimos, 0, 6) as $p) echo tarjeta_partido($p, 'admin.php?p=partido&id=' . (int)$p['id']); ?>
  </div>
</div>
<?php if ($susp): ?>
  <h3>Jugadores suspendidos para su próximo partido</h3>
  <div class="tabla-scroll"><table>
    <tr><th>Jugador</th><th>Equipo</th><th class="num">Partidos</th><th>Motivo</th></tr>
    <?php foreach ($susp as $s): ?>
      <tr><td><?= e(nombre_jugador($s['jugador'])) ?></td><td><?= e($s['jugador']['equipo']) ?></td><td class="num"><?= (int)$s['pendientes'] ?></td><td style="white-space:normal;min-width:220px"><?= html_motivos_suspension($s) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
<?php endif; ?>
<?php
pie_admin();
