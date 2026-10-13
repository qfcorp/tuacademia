<?php
/* Página pública de un jugador */
$j = fila('SELECT j.*, e.nombre AS equipo, e.escudo, e.color, e.id AS eid FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ? AND e.liga_id = ?', [(int)get('j'), $lid]);
if (!$j) { ir($base); }
$st = estadisticas_jugador((int)$j['id']);
$Tj = textos_deporte($liga);
cabecera_publica(nombre_jugador($j) . ' · ' . $liga['nombre'], $liga);
?>
<p><a href="<?= e($base . '&e=' . (int)$j['eid']) ?>">← <?= e($j['equipo']) ?></a></p>
<div class="perfil tarjeta">
  <?= foto_jugador($j, 'foto-grande') ?>
  <div>
    <h1 style="margin:0"><?= $j['numero'] !== null ? '<span class="etiqueta verde">#' . (int)$j['numero'] . '</span> ' : '' ?><?= e(nombre_jugador($j)) ?></h1>
    <p class="eq-celda" style="margin:.5rem 0"><?= escudo($j['escudo'], $j['equipo'], $j['color']) ?> <?= e($j['equipo']) ?></p>
    <p class="suave" style="margin:0"><?= e($j['posicion']) ?><?= edad($j['fecha_nac']) !== null ? ' · ' . edad($j['fecha_nac']) . ' años' : '' ?></p>
  </div>
</div>
<div class="cifras">
  <?php if ($Tj['con_goleadores']): ?><div class="cifra"><b><?= $st['goles'] ?? 0 ?></b><?= e($Tj['goles']) ?></div><?php endif; ?>
  <?php if ($Tj['deporte'] === 'basquet'): ?><div class="cifra"><b><?= $st['triples'] ?? 0 ?></b>Triples</div>
  <?php elseif ($Tj['con_goleadores']): ?><div class="cifra"><b><?= $st['penales'] ?? 0 ?></b>De penal</div><?php endif; ?>
  <div class="cifra"><b><?= $st['amarillas'] ?? 0 ?></b><?= icono_evento('amarilla', $Tj['deporte']) ?> <?= e($Tj['amarilla']) ?></div>
  <div class="cifra"><b><?= $st['rojas'] ?? 0 ?></b><?= icono_evento('roja', $Tj['deporte']) ?> <?= e($Tj['roja']) ?></div>
</div>
<?php if (function_exists('imagettftext')): ?>
<div class="tarjeta" style="text-align:center">
  <h2 style="margin-top:0">📸 Mi cromo</h2>
  <p class="suave" style="margin-top:0">Tarjeta con foto y estadísticas para compartir en Instagram, Facebook o WhatsApp.</p>
  <img src="imagen.php?tipo=cromo&amp;j=<?= (int)$j['id'] ?>" alt="Cromo de <?= e(nombre_jugador($j)) ?>" style="max-width:320px;width:100%;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.25)" loading="lazy">
  <div class="acciones" style="justify-content:center;margin-top:10px">
    <a class="btn" href="imagen.php?tipo=cromo&amp;j=<?= (int)$j['id'] ?>&amp;descargar=1">⬇ Descargar</a>
    <button class="btn btn-sec" type="button" onclick="compartirCromo()">📤 Compartir</button>
  </div>
</div>
<script>
function compartirCromo() {
  var url = 'imagen.php?tipo=cromo&j=<?= (int)$j['id'] ?>';
  fetch(url).then(function (r) { return r.blob(); }).then(function (b) {
    var f = new File([b], 'cromo.jpg', { type: 'image/jpeg' });
    if (navigator.canShare && navigator.canShare({ files: [f] })) return navigator.share({ files: [f], title: <?= json_encode(nombre_jugador($j)) ?> });
    location.href = url + '&descargar=1';
  }).catch(function () { location.href = url + '&descargar=1'; });
}
</script>
<?php endif; ?>
<?php $tray = trayectoria_jugador((int)$j['id']); $pases = pases_jugador((int)$j['id']);
if ($tray): ?>
<h2>Trayectoria</h2>
<div class="tabla-scroll"><table>
  <tr><th>Campeonato</th><th>Equipo</th><th class="num">PJ</th><th class="num"><?= e($Tj['goles']) ?></th><th class="num"><?= icono_evento('amarilla', $Tj['deporte']) ?></th><th class="num"><?= icono_evento('roja', $Tj['deporte']) ?></th></tr>
  <?php foreach ($tray as $r): ?><tr><td><?= e($r['torneo']['nombre'] . ' ' . $r['torneo']['temporada']) ?><?= $r['campeon'] ? ' 🏆' : '' ?></td><td><?= e($r['equipo']['nombre']) ?></td><td class="num"><?= $r['pj'] ?: '—' ?></td><td class="num"><?= $r['goles'] ?></td><td class="num"><?= $r['amarillas'] ?></td><td class="num"><?= $r['rojas'] ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif;
if ($pases): ?>
<h2>Pases</h2>
<ul><?php foreach ($pases as $ps): ?><li><?= e($ps['fecha']) ?>: <?= e($ps['origen'] ?? '—') ?> → <b><?= e($ps['destino']) ?></b></li><?php endforeach; ?></ul>
<?php endif;
pie_publico();
