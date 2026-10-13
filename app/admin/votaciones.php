<?php
/* (v7) Votación del público: jugador del partido, gol de la fecha o votación libre */
$liga = requiere_liga();
$lid = (int)$liga['id'];

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'crear') {
        $vid = crear_votacion($liga, [
            'tipo' => post('tipo'), 'partido_id' => post('partido_id'), 'torneo_id' => post('torneo_id'), 'jornada' => post('jornada'),
            'titulo' => post('titulo'), 'premio' => post('premio'), 'patrocinador_id' => post('patrocinador_id'), 'cierra' => post('cierra'), 'opciones' => post('opciones'),
        ]);
        flash('Votación creada y abierta al público. Comparta el enlace por WhatsApp o redes.');
        ir('admin.php?p=votaciones&ver=' . $vid);
    }
    $vt = fila('SELECT * FROM votaciones WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('Votación no encontrada.');
    if ($accion === 'cerrar') {
        $res = resultados_votacion((int)$vt['id']);
        $gan = $res['opciones'][0] ?? null;
        q("UPDATE votaciones SET estado = 'cerrada', ganador_opcion_id = ? WHERE id = ?", [$gan && $gan['votos'] > 0 ? $gan['id'] : null, $vt['id']]);
        flash('Votación cerrada.' . ($gan && $gan['votos'] > 0 ? ' Ganador: ' . $gan['texto'] . ' (' . $gan['votos'] . ' votos).' : ''));
    } elseif ($accion === 'abrir') {
        q("UPDATE votaciones SET estado = 'abierta', cierra = '' WHERE id = ?", [$vt['id']]);
        flash('Votación abierta otra vez.');
    } elseif ($accion === 'figura') {
        // El ganador del "jugador del partido" o del "gol de la fecha" pasa a ser la figura de la jornada (imagen para redes)
        $res = resultados_votacion((int)$vt['id']);
        $gan = $res['opciones'][0] ?? null;
        if (!$gan || !$gan['jugador_id'] || !$vt['torneo_id']) throw new RuntimeException('Esta votación no tiene un jugador ganador.');
        $jornada = (int)$vt['jornada'] ?: (int)valor('SELECT jornada FROM partidos WHERE id = ?', [$vt['partido_id']]);
        q('INSERT INTO figuras (torneo_id, jornada, jugador_id, motivo) VALUES (?,?,?,?) ON CONFLICT(torneo_id, jornada) DO UPDATE SET jugador_id = excluded.jugador_id, motivo = excluded.motivo',
            [$vt['torneo_id'], $jornada, $gan['jugador_id'], 'Elegido por el público (' . $gan['pct'] . '% de los votos)']);
        flash('Listo: ' . $gan['texto'] . ' es la figura de la jornada ' . $jornada . '. Descargue la imagen en "Imágenes para redes".');
        ir('admin.php?p=redes&t=' . (int)$vt['torneo_id'] . '&j=' . $jornada);
    } elseif ($accion === 'eliminar') {
        q('DELETE FROM votaciones WHERE id = ?', [$vt['id']]);
        flash('Votación eliminada.');
        ir('admin.php?p=votaciones');
    }
    ir('admin.php?p=votaciones&ver=' . (int)$vt['id']);
}

$torneos = filas("SELECT * FROM torneos WHERE liga_id = ? AND " . sql_sin_carreras() . " ORDER BY (estado = 'finalizado'), id DESC", [$lid]);
$partidos = filas("SELECT p.id, p.jornada, p.fecha, p.fase, el.nombre AS l, ev.nombre AS v, t.nombre AS torneo FROM partidos p JOIN torneos t ON t.id = p.torneo_id
                   JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                   WHERE t.liga_id = ? AND p.estado IN ('jugado','en_vivo') ORDER BY p.fecha DESC, p.id DESC LIMIT 60", [$lid]);
$pats = patrocinadores_activos($lid);
$lista = filas('SELECT v.*, (SELECT COUNT(*) FROM votos x WHERE x.votacion_id = v.id) AS n FROM votaciones v WHERE v.liga_id = ? ORDER BY v.id DESC LIMIT 50', [$lid]);
$ver = get('ver') !== '' ? fila('SELECT * FROM votaciones WHERE id = ? AND liga_id = ?', [(int)get('ver'), $lid]) : null;
$enlace_pub = url_liga($liga) . (($liga['dominio'] ?? '') !== '' ? '?' : '?') . 'v=votar';
cabecera_admin('Votaciones');
subnav_publico('votaciones');
?>
<h1>🗳 Votación del público</h1>
<p class="suave">El público vota desde la página de la liga (pestaña <b>🗳 Votaciones</b>), sin registrarse: un voto por celular. Úselo para el <b>jugador del partido</b> o el <b>gol de la fecha</b>, con un premio del patrocinador. Enlace para compartir: <a href="<?= e($enlace_pub) ?>" target="_blank"><?= e($enlace_pub) ?></a></p>

<?php if ($ver): $res = resultados_votacion((int)$ver['id']); ?>
<div class="tarjeta" style="border:2px solid var(--acento)">
  <h2 style="margin-top:0"><?= e($ver['titulo']) ?> <?= votacion_abierta($ver) ? '<span class="estado-chip chip-ok">Abierta</span>' : '<span class="estado-chip">Cerrada</span>' ?></h2>
  <p class="suave"><?= e(tipos_votacion()[$ver['tipo']] ?? '') ?> · <?= (int)$res['total'] ?> voto(s)<?= $ver['cierra'] !== '' ? ' · cierra ' . e($ver['cierra']) : '' ?><?= $ver['premio'] !== '' ? ' · 🎁 ' . e($ver['premio']) : '' ?></p>
  <?php foreach ($res['opciones'] as $i => $o): ?>
    <div class="barra-voto"><div class="barra-voto-txt"><b><?= e($o['texto']) ?></b> <small><?= e($o['detalle']) ?></small><span><?= (int)$o['pct'] ?>% · <?= (int)$o['votos'] ?></span></div><div class="barra-voto-fondo"><div style="width:<?= (int)$o['pct'] ?>%"></div></div></div>
  <?php endforeach; ?>
  <?php if (puede('comunicacion', true)): ?>
  <div class="acciones" style="margin-top:12px">
    <?php $wa = rawurlencode('🗳 ' . $ver['titulo'] . ($ver['premio'] !== '' ? "\n🎁 " . $ver['premio'] : '') . "\n¡Vota aquí! 👉 " . $enlace_pub); ?>
    <a class="btn" target="_blank" href="https://wa.me/?text=<?= $wa ?>">📲 Compartir por WhatsApp</a>
    <?php if (votacion_abierta($ver)): ?>
      <form class="inline" method="post" onsubmit="return confirm('¿Cerrar la votación? Ya no se aceptan más votos.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="cerrar"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>"><button class="btn btn-sec">Cerrar votación</button></form>
    <?php else: ?>
      <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="abrir"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>"><button class="btn btn-sec">Volver a abrir</button></form>
      <?php if ($ver['tipo'] !== 'libre' && $res['total'] > 0): ?><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="figura"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>"><button class="btn btn-sec">⭐ Hacerlo figura de la jornada (imagen)</button></form><?php endif; ?>
    <?php endif; ?>
    <form class="inline" method="post" onsubmit="return confirm('¿Eliminar la votación y sus votos?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>"><button class="btn btn-peligro">Eliminar</button></form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($lista): ?>
<h2>Votaciones</h2>
<div class="tabla-scroll"><table>
  <tr><th>Votación</th><th>Tipo</th><th>Estado</th><th class="num">Votos</th><th></th></tr>
  <?php foreach ($lista as $x): ?><tr><td><b><?= e($x['titulo']) ?></b></td><td><small><?= e(tipos_votacion()[$x['tipo']] ?? '') ?></small></td><td><?= votacion_abierta($x) ? '<span class="estado-chip chip-ok">Abierta</span>' : '<span class="estado-chip">Cerrada</span>' ?></td><td class="num"><?= (int)$x['n'] ?></td><td><a class="btn btn-sec btn-chico" href="admin.php?p=votaciones&amp;ver=<?= (int)$x['id'] ?>">Ver resultados</a></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if (puede('comunicacion', true)): ?>
<div class="tarjeta" style="margin-top:20px">
  <h2 style="margin-top:0">+ Nueva votación</h2>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
    <div class="campos">
      <div class="campo-ancho"><label>¿Qué se vota?</label>
        <select name="tipo" id="tipo-vot" onchange="mostrarTipo()"><?php foreach (tipos_votacion() as $k => $txt): ?><option value="<?= $k ?>"><?= e($txt) ?></option><?php endforeach; ?></select></div>
      <div class="solo-partido campo-ancho"><label>Partido</label><select name="partido_id"><option value="">— elegir —</option>
        <?php foreach ($partidos as $pp): ?><option value="<?= (int)$pp['id'] ?>"><?= e($pp['l'] . ' vs ' . $pp['v']) ?> · <?= e($pp['torneo']) ?> · <?= $pp['fase'] === 'regular' ? 'J' . (int)$pp['jornada'] : e(nombre_fase($pp['fase'])) ?> · <?= fecha_bonita($pp['fecha']) ?></option><?php endforeach; ?></select>
        <div class="ayuda">Las opciones son los jugadores presentes (si la vocalía los marcó) o las dos plantillas.</div></div>
      <div class="solo-gol"><label>Campeonato</label><select name="torneo_id"><?php foreach ($torneos as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre'] . ' ' . $t['temporada']) ?></option><?php endforeach; ?></select></div>
      <div class="solo-gol"><label>Jornada</label><input type="number" name="jornada" min="1" max="200" value="1"><div class="ayuda">Las opciones son los goles de esa jornada (en básquet, los triples).</div></div>
      <div class="solo-libre campo-ancho"><label>Opciones (una por línea)</label><textarea name="opciones" rows="4" placeholder="Opción 1&#10;Opción 2&#10;Opción 3"></textarea></div>
      <div class="campo-ancho"><label>Título <span class="suave solo-no-libre">(opcional: se pone solo)</span></label><input type="text" name="titulo" maxlength="150" placeholder="Ej: ¿Quién fue el mejor jugador de la final?"></div>
      <div><label>Premio (opcional)</label><input type="text" name="premio" maxlength="200" placeholder="Ej: Una pizza de Pizzería Don Luis"></div>
      <div><label>Auspiciante (opcional)</label><select name="patrocinador_id"><option value="">— ninguno —</option><?php foreach ($pats as $pt): ?><option value="<?= (int)$pt['id'] ?>"><?= e($pt['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label>Se cierra sola el</label><input type="datetime-local" name="cierra" value="<?= e(date('Y-m-d\TH:i', strtotime('+3 days 20:00'))) ?>"><div class="ayuda">Vacío = hasta que usted la cierre.</div></div>
    </div>
    <button class="btn">Crear votación</button>
  </form>
</div>
<script>
function mostrarTipo() {
  var t = document.getElementById('tipo-vot').value;
  document.querySelectorAll('.solo-partido').forEach(function (e) { e.style.display = t === 'partido' ? '' : 'none'; });
  document.querySelectorAll('.solo-gol').forEach(function (e) { e.style.display = t === 'gol' ? '' : 'none'; });
  document.querySelectorAll('.solo-libre').forEach(function (e) { e.style.display = t === 'libre' ? '' : 'none'; });
  document.querySelectorAll('.solo-no-libre').forEach(function (e) { e.style.display = t === 'libre' ? 'none' : ''; });
}
mostrarTipo();
</script>
<?php endif;
pie_admin();
