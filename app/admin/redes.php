<?php
/* IMÁGENES PARA REDES SOCIALES: vista previa, descargar y compartir */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$torneos = filas("SELECT * FROM torneos WHERE liga_id = ? AND " . sql_sin_carreras() . " ORDER BY CASE estado WHEN 'en_juego' THEN 0 ELSE 1 END, id DESC", [$lid]);
if (!$torneos) {
    cabecera_admin('Redes');
    subnav_publico('redes');
    echo '<h1>Imágenes para redes</h1><div class="tarjeta">Primero cree un campeonato.</div>';
    pie_admin();
    return;
}
$t = null;
foreach ($torneos as $x) if ((int)$x['id'] === (int)get('t')) $t = $x;
$t = $t ?? $torneos[0];
$tid = (int)$t['id'];
$jornadas = array_map('intval', array_column(filas("SELECT DISTINCT jornada FROM partidos WHERE torneo_id = ? AND fase = 'regular' ORDER BY jornada", [$tid]), 'jornada'));
$ult_jugada = (int)valor("SELECT MAX(jornada) FROM partidos WHERE torneo_id = ? AND fase = 'regular' AND estado IN ('jugado','wo')", [$tid]);
$j = get('j') !== '' ? (int)get('j') : ($ult_jugada ?: ($jornadas[0] ?? 1));
$siguiente = null;
foreach ($jornadas as $x) if ($x > $j) { $siguiente = $x; break; }
$url = 'admin.php?p=redes&t=' . $tid . '&j=' . $j;

if (es_post()) {
    if (post('accion') === 'figura') {
        if (post('jugador_id') === '') {
            q('DELETE FROM figuras WHERE torneo_id = ? AND jornada = ?', [$tid, $j]);
            flash('Figura automática (el que más goles hizo en la jornada).');
        } else {
            $jug = jugador_de_liga((int)post('jugador_id'), $lid);
            q('INSERT INTO figuras (torneo_id, jornada, jugador_id, motivo) VALUES (?,?,?,?) ON CONFLICT(torneo_id, jornada) DO UPDATE SET jugador_id = excluded.jugador_id, motivo = excluded.motivo',
                [$tid, $j, $jug['id'], mb_substr(post('motivo'), 0, 60)]);
            flash('Figura de la fecha: ' . nombre_jugador($jug));
        }
    }
    ir($url . '#figura');
}

require APP_DIR . '/imagenes.php';
$partidos_j = partidos_torneo($tid, "AND p.fase = 'regular' AND p.jornada = " . $j);
$elim = partidos_torneo($tid, "AND p.fase <> 'regular'");
$grupos = array_keys(tabla_posiciones($tid));
$fig = figura_de_jornada($tid, $j);
$fig_manual = fila('SELECT * FROM figuras WHERE torneo_id = ? AND jornada = ?', [$tid, $j]);
$jugadores = filas('SELECT j.id, j.nombres, j.apellidos, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id JOIN torneo_equipos te ON te.equipo_id = e.id AND te.torneo_id = ? WHERE j.activo = 1 ORDER BY e.nombre, j.apellidos', [$tid]);

$tarjetas = [];
if ($partidos_j) $tarjetas[] = ['Resultados de la jornada ' . $j, "imagen.php?tipo=jornada&t=$tid&j=$j"];
foreach ($grupos as $g) $tarjetas[] = ['Tabla de posiciones' . ($g !== '' ? ' · Grupo ' . $g : ''), "imagen.php?tipo=tabla&t=$tid" . ($g !== '' ? '&g=' . rawurlencode((string)$g) : '')];
$tarjetas[] = ['Tabla de goleadores', "imagen.php?tipo=goleadores&t=$tid"];
if (cumpleaneros_liga($lid)) $tarjetas[] = ['🎂 Cumpleañeros de hoy', 'imagen.php?tipo=cumple&l=' . $lid]; // (v18)
if ($fig) $tarjetas[] = ['Figura de la fecha', "imagen.php?tipo=figura&t=$tid&j=$j"];
if ($siguiente) $tarjetas[] = ['Programación jornada ' . $siguiente, "imagen.php?tipo=programacion&t=$tid&j=$siguiente"];
foreach ($partidos_j as $p) $tarjetas[] = [$p['local_nombre'] . ' vs ' . $p['visitante_nombre'], 'imagen.php?tipo=resultado&p=' . (int)$p['id']];
foreach ($elim as $p) $tarjetas[] = [nombre_fase($p['fase']) . ': ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'], 'imagen.php?tipo=resultado&p=' . (int)$p['id']];

cabecera_admin('Redes');
?>
<?php subnav_publico('redes'); ?>
<h1>📣 Imágenes para redes sociales</h1>
<p class="suave">Se crean solas con los datos del sistema, el logo y el color de la liga y el patrocinador (se configura en <a href="admin.php?p=config">Parámetros</a>). Tamaño 1080×1350, ideal para Facebook, Instagram y estados de WhatsApp.</p>
<form method="get" class="tarjeta acciones">
  <input type="hidden" name="p" value="redes">
  <div><label>Campeonato</label><select name="t" onchange="this.form.j.value=''; this.form.submit()"><?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $tid ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?></select></div>
  <div><label>Jornada</label><select name="j" onchange="this.form.submit()"><?php foreach ($jornadas as $x): ?><option value="<?= $x ?>" <?= $x === $j ? 'selected' : '' ?>>Jornada <?= $x ?></option><?php endforeach; ?></select></div>
</form>

<div class="rejilla-redes">
  <?php foreach ($tarjetas as [$titulo, $src]): ?>
    <div class="tarjeta" style="padding:10px">
      <strong style="display:block;margin-bottom:6px"><?= e($titulo) ?></strong>
      <a href="<?= e($src) ?>" target="_blank"><img class="img-redes" src="<?= e($src) ?>" alt="" loading="lazy"></a>
      <div class="acciones" style="margin-top:8px">
        <a class="btn btn-chico" href="<?= e($src) ?>&amp;descargar=1">⬇ Descargar</a>
        <button class="btn btn-sec btn-chico" type="button" onclick="compartir('<?= e($src) ?>', '<?= e(addslashes($titulo)) ?>')">📲 Compartir</button>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="tarjeta" id="figura" style="margin-top:20px">
  <h2 style="margin-top:0">Figura de la fecha · Jornada <?= $j ?></h2>
  <p class="suave">Por defecto es el jugador con más goles en la jornada. Puede elegir otro (por ejemplo, el arquero que atajó un penal).</p>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="figura">
    <div class="campos">
      <div class="campo-ancho"><label>Jugador</label><select name="jugador_id"><option value="">Automático (máximo goleador de la jornada)</option>
        <?php foreach ($jugadores as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fig_manual && (int)$fig_manual['jugador_id'] === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['equipo'] . ' — ' . nombre_jugador($x)) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Texto destacado</label><input type="text" name="motivo" maxlength="60" value="<?= e($fig_manual['motivo'] ?? '') ?>" placeholder="Ej: ATAJÓ UN PENAL Y 3 MANO A MANO"></div>
    </div>
    <button class="btn btn-sec">Guardar figura</button>
  </form>
</div>
<script>
function compartir(src, titulo) {
  var url = new URL(src, location.href).href;
  if (navigator.canShare) {
    fetch(url).then(function (r) { return r.blob(); }).then(function (b) {
      var f = new File([b], 'imagen.jpg', { type: 'image/jpeg' });
      if (navigator.canShare({ files: [f] })) return navigator.share({ files: [f], title: titulo });
      throw new Error('no');
    }).catch(function () { window.open('https://wa.me/?text=' + encodeURIComponent(titulo + ' ' + url), '_blank'); });
  } else {
    window.open('https://wa.me/?text=' + encodeURIComponent(titulo + ' ' + url), '_blank');
  }
}
</script>
<?php
pie_admin();
