<?php
/* Página pública de un equipo */
$eq = fila('SELECT * FROM equipos WHERE id = ? AND liga_id = ?', [(int)get('e'), $lid]);
if (!$eq) { ir($base); }
$jug = filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1 ORDER BY (numero IS NULL), numero, apellidos', [$eq['id']]);
$t = fila("SELECT t.* FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id AND te.equipo_id = ? WHERE t.liga_id = ?
           ORDER BY CASE t.estado WHEN 'en_juego' THEN 0 ELSE 1 END, t.id DESC LIMIT 1", [$eq['id'], $lid]);
cabecera_publica($eq['nombre'] . ' · ' . $liga['nombre'], $liga);
?>
<p><a href="<?= e($base . ($t ? '&t=' . (int)$t['id'] . '&v=equipos' : '')) ?>">← Equipos</a></p>
<div class="perfil tarjeta">
  <?= escudo($eq['escudo'], $eq['nombre'], $eq['color'], 'escudo-grande') ?>
  <div>
    <h1 style="margin:0"><?= e($eq['nombre']) ?></h1>
    <?php if (($eq['fundado'] ?? '') !== ''): ?><p class="suave" style="margin:.2rem 0">Fundado el <?= e(fecha_larga($eq['fundado'])) ?> · <?= (int)edad($eq['fundado']) ?> años</p><?php endif; ?>
    <p style="margin:.3rem 0"><a class="btn btn-sec btn-chico" href="<?= e(enlace_calendario($liga, 'e', (int)$eq['id'])) ?>">📅 Partidos en mi calendario</a></p>
    <?= html_seguir_equipo($eq) ?>
    <?php if ($t):
        $fila_t = null;
        foreach (tabla_posiciones((int)$t['id']) as $g) foreach ($g as $i => $f) if ((int)$f['id'] === (int)$eq['id']) { $fila_t = $f + ['pos' => $i + 1]; }
    ?>
      <p class="suave" style="margin:.3rem 0"><?= e($t['nombre']) ?><?= $fila_t && $fila_t['grupo'] !== '' ? ' · Grupo ' . e($fila_t['grupo']) : '' ?></p>
      <?php if ($fila_t): ?>
        <p style="margin:0"><b><?= $fila_t['pos'] ?>º lugar</b> · <?= $fila_t['pts'] ?> pts · <?= $fila_t['pg'] ?>G <?= $fila_t['pe'] ?>E <?= $fila_t['pp'] ?>P · <?= $fila_t['gf'] ?>:<?= $fila_t['gc'] ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<h2>Plantilla (<?= count($jug) ?>)</h2>
<?php if (!$jug): ?><p class="suave">Sin jugadores registrados.</p><?php endif; ?>
<div class="jugadores">
  <?php foreach ($jug as $j): ?>
    <a class="jugador-card" href="<?= e($base . '&j=' . $j['id']) ?>">
      <?php if ($j['numero'] !== null): ?><span class="dorsal"><?= (int)$j['numero'] ?></span><?php endif; ?>
      <?= foto_jugador($j, 'foto-card' . ($j['foto'] ? '' : ' foto-vacia')) ?>
      <div class="datos"><span class="nombre"><?= e(nombre_jugador($j)) ?></span><small><?= e($j['posicion'] ?: '') ?></small></div>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($t):
    $partidos = partidos_torneo((int)$t['id'], 'AND (p.local_id = ' . (int)$eq['id'] . ' OR p.visitante_id = ' . (int)$eq['id'] . ')');
    if ($partidos): ?>
  <h2>Partidos</h2>
  <?php foreach ($partidos as $p) echo tarjeta_partido($p, $base . '&t=' . (int)$t['id'] . '&p=' . (int)$p['id']); ?>
<?php endif; endif;
pie_publico();
