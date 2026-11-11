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

atender_ocultar_pasos($liga); // (10 nov 2026) «Ocultar» de Primeros pasos

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
$en_curso = count(array_filter($torneos, fn($t) => $t['estado'] !== 'finalizado'));
$proximos = $ultimos = $susp = [];
if ($activo) {
    $proximos = partidos_torneo((int)$activo['id'], "AND p.estado NOT IN ('jugado','wo')");
    $ultimos = array_reverse(partidos_torneo((int)$activo['id'], "AND p.estado IN ('jugado','wo')"));
    $susp = suspensiones((int)$activo['id']);
}
$que = es_federacion($liga) ? 'Su federación' : 'Su liga';

cabecera_admin('Resumen');
// (10 nov 2026) Identidad gráfica común de Tukán: saludo, aviso, primeros pasos, cifras, tarjetas del día y accesos rápidos
echo tk_saludo($que . ' de un vistazo: partidos, alertas y lo que falta por hacer');
echo tk_tira_inicio($liga); // demo (días que quedan, manual, «Ver Mi plan») o la novedad sin ver
echo html_primeros_pasos($liga); // (v27) guía con barra de avance
?>
<div class="tk-cifras">
  <?= tk_cifra('Equipos', (string)$n_equipos, 'Clubes inscritos en ' . e($liga['nombre']), 'programa') ?>
  <?= tk_cifra('Jugadores activos', (string)$n_jugadores, 'En todos los equipos', 'ok') ?>
  <?= tk_cifra('Campeonatos', (string)count($torneos), $en_curso . ' en curso') ?>
  <?= tk_cifra($activo ? 'Campeonato actual' : 'Sin campeonato', $activo ? (int)$activo['jugadores_cancha'] . ' vs ' . (int)$activo['jugadores_cancha'] : '—', $activo ? e(nombre_modalidad($activo['modalidad'])) . ' (campeonato actual)' : 'Cree su primer campeonato', 'aviso') ?>
</div>

<?php if ($vivos || $con_dif || $sin_informe || $sin_calificar): ?>
<div class="tk-columnas">
  <?php if ($vivos): ?>
  <section class="tk-tarjeta mal"><?= tk_cabeza('🔴 En vivo ahora', 'Partidos que se están jugando en este momento') ?>
    <?php foreach ($vivos as $r) { $pp = partido_completo((int)$r['id']); echo tarjeta_partido($pp, 'admin.php?p=partido&id=' . (int)$pp['id'] . '&s=vocalia'); } ?></section>
  <?php endif; ?>
  <?php if ($con_dif): ?>
  <section class="tk-tarjeta aviso"><?= tk_cabeza('⚠️ Vocalía y árbitro no coinciden', 'Revise las diferencias y elija la versión oficial') ?>
    <ul class="tk-lista"><?php foreach ($con_dif as [$pp, $n]): ?><li><a href="admin.php?p=partido&amp;id=<?= (int)$pp['id'] ?>&amp;s=arbitro"><?= e($pp['local_nombre'] . ' vs ' . $pp['visitante_nombre']) ?></a> — <?= $n ?> diferencia(s)</li><?php endforeach; ?></ul></section>
  <?php endif; ?>
  <?php if ($sin_calificar): ?>
  <section class="tk-tarjeta aviso"><?= tk_cabeza('⭐ Falta calificar al árbitro (obligatorio)', 'Partidos jugados sin la calificación del árbitro') ?>
    <ul class="tk-lista"><?php foreach ($sin_calificar as $pp): ?><li><a href="admin.php?p=partido&amp;id=<?= (int)$pp['id'] ?>&amp;s=arbitro#calificacion"><?= e($pp['local_nombre'] . ' vs ' . $pp['visitante_nombre']) ?></a> — <?= e(nombre_arbitro($pp)) ?></li><?php endforeach; ?></ul></section>
  <?php endif; ?>
  <?php if ($sin_informe): ?>
  <section class="tk-tarjeta aviso"><?= tk_cabeza('📝 Falta el informe del árbitro', 'Partidos jugados sin el informe arbitral') ?>
    <ul class="tk-lista"><?php foreach ($sin_informe as $pp): ?><li><a href="admin.php?p=partido&amp;id=<?= (int)$pp['id'] ?>&amp;s=arbitro"><?= e($pp['local_nombre'] . ' vs ' . $pp['visitante_nombre']) ?></a> — <?= e(nombre_arbitro($pp)) ?></li><?php endforeach; ?></ul></section>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="tk-columnas">
  <section class="tk-tarjeta"><?= $activo ? tk_cabeza('📅 Próximos partidos', 'Campeonato: ' . $activo['nombre'], 'admin.php?p=torneo&id=' . (int)$activo['id'], 'Administrar campeonato') : tk_cabeza('📅 Próximos partidos', 'Los partidos que vienen') ?>
    <?php if (!$activo): ?>
      <div class="tk-vacio"><b>Todavía no tiene campeonatos.</b>Cree el campeonato, haga el sorteo y aquí verá los próximos partidos.<?php if (puede('campeonatos', true)): ?><br><br><a class="tk-boton" href="admin.php?p=torneos">＋ Crear el primero</a><?php endif; ?></div>
    <?php elseif (!$proximos): ?>
      <div class="tk-vacio"><b>No hay partidos pendientes.</b>Cuando programe la siguiente fecha, aparece aquí.</div>
    <?php else: foreach (array_slice($proximos, 0, 6) as $p) echo tarjeta_partido($p, 'admin.php?p=partido&id=' . (int)$p['id']); endif; ?>
  </section>
  <section class="tk-tarjeta ok"><?= tk_cabeza('🏁 Últimos resultados', $activo ? 'Campeonato: ' . $activo['nombre'] : 'Los partidos ya jugados', $activo ? 'admin.php?p=torneo&id=' . (int)$activo['id'] : '', $activo ? 'Ver todos los resultados' : '', 'secundario') ?>
    <?php if (!$ultimos): ?>
      <div class="tk-vacio"><b>Aún no hay resultados.</b>Cuando se juegue el primer partido con la vocalía, el resultado sale aquí.</div>
    <?php else: foreach (array_slice($ultimos, 0, 6) as $p) echo tarjeta_partido($p, 'admin.php?p=partido&id=' . (int)$p['id']); endif; ?>
  </section>
</div>

<?php if ($susp): ?>
<section class="tk-tarjeta mal"><?= tk_cabeza('🚫 Jugadores suspendidos para su próximo partido', 'Campeonato: ' . $activo['nombre'], 'admin.php?p=torneo&id=' . (int)$activo['id'], 'Ver campeonato', 'peligro') ?>
  <div class="tk-tabla-caja"><table class="tk-tabla tarjetas">
    <thead><tr><th>Jugador</th><th>Equipo</th><th class="num">Partidos</th><th>Motivo</th></tr></thead><tbody>
    <?php foreach ($susp as $s): ?>
      <tr><td data-titulo="Jugador"><?= e(nombre_jugador($s['jugador'])) ?></td><td data-titulo="Equipo"><?= e($s['jugador']['equipo']) ?></td><td data-titulo="Partidos" class="num"><?= (int)$s['pendientes'] ?></td><td data-titulo="Motivo" style="white-space:normal;min-width:220px"><?= html_motivos_suspension($s) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if ((int)($liga['mostrar_cumples'] ?? 1) === 1) echo html_cumples($liga, '', true); // (v18) ?>

<?php if ($carreras_act): // (v23/v25) ?>
<section class="tk-tarjeta info"><?= tk_cabeza('🏅 Deportes individuales', 'Carreras, duelos, bolos y pruebas en curso', 'admin.php?p=torneos', 'Ver todos los campeonatos', 'secundario') ?>
<div class="rejilla-2">
<?php foreach (array_slice($carreras_act, 0, 6) as $ct): ?>
  <div class="tk-grupo-hoy">
    <b><?= icono_individual($ct) ?> <?= e($ct['nombre']) ?></b>
    <p class="suave" style="margin:.2rem 0"><?= e(nombre_modalidad($ct['modalidad'])) ?> · <?= e(resumen_individual($ct)) ?></p>
    <?php foreach (array_slice(lideres_individual($ct), 0, 4, true) as $cat_n => $lider): ?>
      <p style="margin:.2rem 0"><b><?= e($cat_n) ?>:</b> <?= e($lider) ?></p>
    <?php endforeach; ?>
    <a class="tk-boton chico secundario" href="<?= e(url_admin_individual($ct)) ?>">Administrar</a>
  </div>
<?php endforeach; ?>
</div>
<?php if (count($carreras_act) > 6): ?><p class="tk-linea-dato"><a href="admin.php?p=torneos">… y <?= count($carreras_act) - 6 ?> campeonato(s) individual(es) más en Campeonatos</a></p><?php endif; ?>
</section>
<?php endif; ?>

<?= html_accesos_rapidos() // (10 nov 2026) 3 a 6 botones a lo más usado ?>
<?php
pie_admin();
