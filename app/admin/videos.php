<?php
/*
 * (v28) VIDEOS DE JUGADORES Y ALUMNOS: subir (máx. 2 min), avisar y ver cuáles siguen disponibles (se borran a los 10 días).
 */
require_once APP_DIR . '/videos.php';
$liga = requiere_liga();
$lid = (int)$liga['id'];
$esc = es_escuela($liga);
$tipo = $esc ? 'alumno' : 'jugador';
$demo = !empty($liga['es_demo']) && !es_super();

if (es_post()) {
    $accion = post('accion');
    if (in_array($accion, ['iniciar', 'terminar'], true)) {
        // Respuestas en JSON para el subidor del navegador
        try {
            if ($demo) throw new RuntimeException('En la demostración no se suben videos. Mire el ejemplo en el portal del jugador o de los padres.');
            if ($accion === 'iniciar') {
                $r = iniciar_video($liga, $tipo, (int)post('persona_id'), post('titulo'), post('nota'), pathinfo(post('nombre_archivo'), PATHINFO_EXTENSION), (int)post('bytes'), (int)post('partido_id') ?: null);
            } else {
                $v = terminar_video($liga, (int)post('id'), (int)post('bytes'), post('duracion'), post('firma'));
                flash('🎬 Video listo. ' . (persona_video($v['tipo'], (int)$v['persona_id'])['nombre'] ?? '') . ' ya lo ve en su portal hasta el ' . fecha_bonita(substr($v['expira'], 0, 10)) . '.');
                $r = ['ok' => true, 'ir' => 'admin.php?p=videos&listo=' . (int)$v['id']];
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true] + $r);
        } catch (Throwable $ex) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $ex instanceof RuntimeException ? $ex->getMessage() : 'Error inesperado.']);
        }
        exit;
    }
    if ($accion === 'borrar') {
        $v = fila("SELECT * FROM videos WHERE id = ? AND liga_id = ? AND estado <> 'borrado'", [(int)post('id'), $lid]) ?? throw new RuntimeException('Video no encontrado.');
        borrar_video($v);
        flash('Video borrado.');
        ir('admin.php?p=videos');
    }
}

$personas = $esc
    ? filas("SELECT a.id, a.nombres || ' ' || a.apellidos AS nombre, COALESCE(g.nombre, 'Sin grupo') AS grupo FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.estado = 'activo' ORDER BY grupo, a.nombres", [$lid])
    : filas("SELECT j.id, j.nombres || ' ' || j.apellidos AS nombre, e.nombre AS grupo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.activo = 1 ORDER BY e.nombre, j.apellidos", [$lid]);
$sel = (int)get('id');
$lista = filas("SELECT * FROM videos WHERE liga_id = ? AND estado IN ('listo','subiendo') ORDER BY id DESC LIMIT 200", [$lid]);
$uso = uso_videos($lid);
cabecera_admin('Videos');
?>
<div class="fila-titulo"><h1>🎬 Videos de <?= $esc ? 'alumnos' : 'jugadores' ?></h1>
  <span class="suave"><?= $uso['n'] ?> video(s) disponibles · <?= texto_bytes($uso['bytes']) ?></span></div>
<p class="suave">Suba un video corto (máximo <b>2 minutos</b>) para un <?= $esc ? 'alumno: lo ven sus padres' : 'jugador: lo ve' ?> en su portal, con el botón para descargarlo. <b>Se borra solo a los <?= VIDEO_DIAS ?> días</b> y se les avisa la fecha.</p>

<?php if ($demo): ?>
  <div class="aviso aviso-info">👀 En la demostración no se suben videos. Vea cómo le llega al <?= $esc ? 'padre de familia' : 'jugador' ?>: <a href="index.php?v=demo">entre al portal del <?= $esc ? 'padre' : 'jugador' ?></a>.</div>
<?php else: ?>
<div class="tarjeta" id="subir">
  <h2 style="margin-top:0">⬆ Subir un video</h2>
  <form id="form-video" onsubmit="return subirVideo(event)">
    <div class="campos">
      <div><label><?= $esc ? 'Alumno' : 'Jugador' ?> *</label><select name="persona_id" required><option value="">— Elija —</option>
        <?php $g = null; foreach ($personas as $x): if ($x['grupo'] !== $g): if ($g !== null) echo '</optgroup>'; $g = $x['grupo']; ?><optgroup label="<?= e($g) ?>"><?php endif; ?>
          <option value="<?= (int)$x['id'] ?>" <?= $sel === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nombre']) ?></option>
        <?php endforeach; if ($g !== null) echo '</optgroup>'; ?></select></div>
      <div><label>Título</label><input name="titulo" maxlength="80" placeholder="<?= $esc ? 'Ej: Técnica de crol · 12 de octubre' : 'Ej: Su gol contra Los Andes' ?>"></div>
      <div class="campo-ancho"><label>Mensaje (opcional)</label><input name="nota" maxlength="300" placeholder="Ej: ¡Felicitaciones! Mira cómo mejoró tu pegada."></div>
      <div class="campo-ancho"><label>Video (MP4 o MOV del celular, máximo 2 minutos)</label><input type="file" name="video" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.m4v,.webm" required></div>
    </div>
    <div class="barra-avance" id="vid-barra" hidden><span style="width:0%"></span></div>
    <p id="vid-estado" class="suave" style="margin:.3rem 0"></p>
    <button class="btn" id="vid-btn">⬆ Subir video</button>
  </form>
</div>
<script>
async function subirVideo(ev) {
  ev.preventDefault();
  var f = ev.target, file = f.video.files[0], est = document.getElementById('vid-estado'), barra = document.getElementById('vid-barra'), btn = document.getElementById('vid-btn');
  var msg = function (t, mal) { est.textContent = t; est.style.color = mal ? 'var(--roja)' : ''; };
  if (!file) return false;
  if (file.size > <?= VIDEO_MAX_MB ?> * 1048576) { msg('El video pesa más de <?= VIDEO_MAX_MB ?> MB.', true); return false; }
  // Duración: el navegador la lee antes de subir (máximo 2 minutos)
  var dur = await new Promise(function (ok) { var v = document.createElement('video'); v.preload = 'metadata'; v.onloadedmetadata = function () { ok(v.duration); URL.revokeObjectURL(v.src); }; v.onerror = function () { ok(0); }; v.src = URL.createObjectURL(file); });
  if (dur && dur > <?= VIDEO_MAX_SEG ?> + 2) { msg('El video dura ' + Math.round(dur) + ' segundos. El máximo es 2 minutos: recórtelo en el celular y vuelva a intentar.', true); return false; }
  btn.disabled = true; barra.hidden = false; msg('Preparando…');
  var datos = new FormData(f); datos.delete('video'); datos.append('accion', 'iniciar'); datos.append('nombre_archivo', file.name); datos.append('bytes', file.size);
  datos.append('csrf', <?= json_encode(csrf_token()) ?>);
  try {
    var r = await (await fetch('admin.php?p=videos', { method: 'POST', body: datos, credentials: 'same-origin' })).json();
    if (!r.ok) throw new Error(r.error);
    var total = Math.ceil(file.size / r.pedazo);
    for (var i = 0; i < total; i++) {
      var intento = 0, ok = false;
      while (!ok) {
        try {
          var x = await (await fetch(r.subir + '&n=' + i, { method: 'POST', body: file.slice(i * r.pedazo, (i + 1) * r.pedazo), headers: { 'Content-Type': 'application/octet-stream' } })).json();
          if (!x.ok) throw new Error(x.error);
          ok = true;
        } catch (e) { if (++intento >= 3) throw e; await new Promise(function (s) { setTimeout(s, 1500); }); }
      }
      barra.firstElementChild.style.width = Math.round((i + 1) * 100 / total) + '%';
      msg('Subiendo… ' + Math.round((i + 1) * 100 / total) + '%');
    }
    msg('Terminando…');
    var fin = await (await fetch(r.fin + '&total=' + total, { method: 'POST' })).json();
    if (!fin.ok) throw new Error(fin.error);
    var t = new FormData(); t.append('accion', 'terminar'); t.append('id', r.id); t.append('bytes', fin.bytes); t.append('duracion', fin.duracion); t.append('firma', fin.firma); t.append('csrf', <?= json_encode(csrf_token()) ?>);
    var listo = await (await fetch('admin.php?p=videos', { method: 'POST', body: t, credentials: 'same-origin' })).json();
    if (!listo.ok) throw new Error(listo.error);
    location.href = listo.ir;
  } catch (e) { msg('No se pudo subir: ' + (e.message || e), true); btn.disabled = false; }
  return false;
}
</script>
<?php endif; ?>

<?php if (!$lista): ?><div class="tarjeta vacio">Todavía no hay videos.</div><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th><?= $esc ? 'Alumno' : 'Jugador' ?></th><th>Video</th><th>Se borra</th><th>Visto</th><th></th></tr>
  <?php foreach ($lista as $v): $p = persona_video($v['tipo'], (int)$v['persona_id']); $wa = telefono_wa($p['telefono'] ?? ''); ?>
    <tr class="<?= (int)get('listo') === (int)$v['id'] ? 'dif' : '' ?>"><td><b><?= e($p['nombre'] ?? '—') ?></b></td>
      <td><?= e($v['titulo']) ?><br><small class="suave"><?= $v['estado'] === 'subiendo' ? 'subiendo…' : texto_bytes((int)$v['bytes']) . ($v['duracion'] ? ' · ' . (int)round((float)$v['duracion']) . ' s' : '') ?> · <?= e($v['subido_por']) ?></small></td>
      <td><?= $v['estado'] === 'listo' ? e(fecha_bonita(substr($v['expira'], 0, 10))) . ' <small class="suave">(' . dias_video($v) . ' d)</small>' : '—' ?></td>
      <td><?= ($v['visto_en'] ?? '') !== '' ? '✅' : '—' ?></td>
      <td class="acciones" style="white-space:nowrap">
        <?php if ($v['estado'] === 'listo'): ?>
          <a class="btn btn-sec btn-chico" target="_blank" href="<?= e(enlace_video($v, 'ver', 60)) ?>">▶ Ver</a>
          <?php $txt = 'Hola, ' . ($esc ? 'hay un video nuevo de ' . ($p['nombre'] ?? '') : ($p['nombre'] ?? '') . ', tienes un video nuevo') . ' en ' . $liga['nombre'] . ': «' . $v['titulo'] . '». Míralo y descárgalo aquí: ' . ($p['portal'] ?? '') . ' (se borra el ' . fecha_bonita(substr($v['expira'], 0, 10)) . ').'; ?>
          <a class="btn btn-sec btn-chico" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($txt) ?>">💬 Avisar</a>
        <?php endif; ?>
        <form method="post" class="inline" onsubmit="return confirm('¿Borrar este video ahora?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn btn-sec btn-chico">Borrar</button></form>
      </td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
