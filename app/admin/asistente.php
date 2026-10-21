<?php
/*
 * (21 oct 2026) MÓDULO EXTRA «ASISTENTE IA» (asistente_ia, por cotización). Lo que propone la IA SIEMPRE se revisa antes de usarlo.
 *  Ligas y federaciones:  ?f=cronica&partido=ID  Crónica del partido para redes (resultado, goleadores y tarjetas guardadas)
 *                         ?f=hoja&partido=ID     Leer la foto de la hoja de vocalía en papel (propone goles y tarjetas para revisar)
 *  Escuelas y academias:  ?f=informe&alumno=ID   Informe del avance del alumno para los padres (asistencia, nivel, notas del profesor)
 *                         ?f=cobro&alumno=ID     Mensaje de cobro amable (lo pendiente de la familia)
 * Si no se puede usar la IA (lo que sea): el mensaje neutro con el enlace para hacerlo a mano.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$escuela = es_escuela($liga) || ia_modo($liga) === 'academia';
$funciones = $escuela
    ? ['informe' => ['📈', 'Informe del avance del alumno para los padres', puede('alumnos') || puede('clases') || puede('evaluaciones')],
       'cobro' => ['💬', 'Mensaje de cobro amable', puede('cobros')]]
    : ['cronica' => ['📰', 'Crónica del partido para redes', puede('partidos') || puede('campeonatos') || puede('comunicacion')],
       'hoja' => ['📷', 'Leer la foto de la hoja de vocalía en papel', puede('partidos', true)]];
$f = get('f');
if (!isset($funciones[$f])) $f = (string)array_key_first(array_filter($funciones, fn($x) => $x[2]));
if ($f !== '' && !$funciones[$f][2]) {
    flash('Su perfil (' . nombre_perfil(perfil_actual()) . ') no tiene acceso a esa función.', 'error');
    ir('admin.php?p=asistente');
}
$voc = $escuela && function_exists('vocabulario_escuela') ? vocabulario_escuela($liga) : [];
$aviso = '';       // mensaje neutro (HTML ya escapado)
$texto = null;     // crónica, informe o mensaje propuestos
$propuestos = null; // hoja de vocalía: eventos propuestos
$nota = '';
$p = null;
$a = null;
if (in_array($f, ['cronica', 'hoja'], true) && (int)(post('partido') ?: get('partido')) > 0) $p = partido_de_liga((int)(post('partido') ?: get('partido')), $lid);
if (in_array($f, ['informe', 'cobro'], true) && (int)(post('alumno') ?: get('alumno')) > 0) $a = alumno_de_escuela((int)(post('alumno') ?: get('alumno')), $lid);
$url_partido = $p ? 'admin.php?p=partido&id=' . (int)$p['id'] : '';
$url_alumno = $a ? 'admin.php?p=alumno&id=' . (int)$a['id'] : '';

if (es_post()) {
    $accion = post('accion');
    $nota = trim(mb_substr(post('nota'), 0, 300));
    if ($accion === 'cronica' && $p) {
        $r = ia_cronica_partido($liga, $p, $nota);
        if ($r['ok']) $texto = $r['texto'];
        else $aviso = ia_neutro_html('texto', $url_partido, 'el partido');
    } elseif ($accion === 'hoja' && $p) {
        $file = $_FILES['foto'] ?? null;
        $ok = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) && filesize($file['tmp_name']) > 0;
        $r = $ok ? ia_leer_hoja_vocalia($liga, $p, (string)file_get_contents($file['tmp_name']), $nota) : ['ok' => false];
        if ($r['ok'] && $r['eventos']) $propuestos = $r;
        else $aviso = ia_neutro_html('foto', $url_partido . '#eventos', 'goles y tarjetas del partido');
    } elseif ($accion === 'hoja_guardar' && $p) {
        $reglas = reglas_torneo((int)$p['torneo_id']);
        $tipos = ia_tipos_hoja($reglas);
        $max_min = (int)$reglas['num_tiempos'] * (int)$reglas['minutos_tiempo'] + 30;
        $n = 0;
        $anot = false;
        foreach ((array)($_POST['ev'] ?? []) as $e) {
            if (!is_array($e) || empty($e['usar'])) continue;
            $tipo = (string)($e['tipo'] ?? '');
            $jid = (int)($e['jugador'] ?? 0);
            if (!in_array($tipo, $tipos, true) || !$jid) continue;
            $j = fila('SELECT j.id, j.equipo_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ? AND e.liga_id = ?', [$jid, $lid]);
            if (!$j || !in_array((int)$j['equipo_id'], [(int)$p['local_id'], (int)$p['visitante_id']], true)) continue;
            $min = isset($e['minuto']) && $e['minuto'] !== '' ? entero($e['minuto'], 0, $max_min) : null;
            q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, nota, origen, registrado) VALUES (?,?,?,?,?,?, 'admin', ?)",
                [$p['id'], $j['id'], $j['equipo_id'], $tipo, $min, 'Hoja de vocalía (foto, revisada)', date('Y-m-d H:i:s')]);
            $n++;
            if (es_anotacion($tipo)) $anot = true;
        }
        if (!$n) throw new RuntimeException('No marcó ningún gol ni tarjeta para guardar.');
        $msg = "Se guardaron $n gol(es) y tarjeta(s) de la hoja de vocalía.";
        if ($anot && post('marcador') === '1' && in_array($p['estado'], ['programado', 'jugado', 'en_vivo'], true)) {
            [$gl, $gv] = marcador_desde_eventos($p);
            q("UPDATE partidos SET estado = CASE WHEN estado = 'programado' THEN 'jugado' ELSE estado END, goles_local = ?, goles_visitante = ?, fecha = CASE WHEN fecha = '' THEN ? ELSE fecha END WHERE id = ?", [$gl, $gv, date('Y-m-d'), $p['id']]);
            $msg .= " Marcador: $gl - $gv.";
        }
        bitacora_evento('partidos', 'Guardó ' . $n . ' evento(s) leídos de la foto de la hoja de vocalía con el Asistente IA (revisados)', $lid, (int)$p['id']);
        flash($msg);
        ir($url_partido . '#eventos');
    } elseif ($accion === 'informe' && $a) {
        $r = ia_informe_alumno($liga, $a, $nota);
        if ($r['ok']) $texto = $r['texto'];
        else $aviso = ia_neutro_html('texto', $url_alumno, 'la ficha de ' . nombre_alumno($a));
    } elseif ($accion === 'cobro' && $a) {
        $r = ia_cobro_amable($liga, $a, $nota);
        if ($r['ok']) $texto = $r['texto'];
        elseif (!empty($r['sin_pendientes'])) flash(nombre_alumno($a) . ' no tiene valores pendientes.', 'ok');
        else $aviso = ia_neutro_html('texto', 'admin.php?p=cobros', 'Pagos');
    }
}

$partidos = !$escuela ? filas("SELECT p.id, p.fecha, p.estado, p.goles_local, p.goles_visitante, el.nombre AS local, ev.nombre AS visitante, t.nombre AS torneo FROM partidos p
    JOIN torneos t ON t.id = p.torneo_id JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
    WHERE t.liga_id = ? AND p.estado IN ('jugado', 'en_vivo', 'programado') ORDER BY CASE WHEN p.estado = 'programado' THEN 1 ELSE 0 END, p.fecha DESC, p.id DESC LIMIT 60", [$lid]) : [];
$alumnos = $escuela ? filas("SELECT id, nombres, apellidos FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo' ORDER BY nombres, apellidos LIMIT 500", [$lid]) : [];
cabecera_admin('Asistente IA');
?>
<h1>✨ Asistente IA</h1>
<p class="suave">Le ayuda a escribir y a leer fotos. <b>Usted revisa siempre</b> lo que propone antes de guardarlo o enviarlo.</p>
<nav class="pestanas"><?php foreach ($funciones as $k => [$ico, $tit, $puede]): if (!$puede) continue; ?><a class="<?= $k === $f ? 'activa' : '' ?>" href="admin.php?p=asistente&amp;f=<?= $k ?>"><?= $ico ?> <?= e($tit) ?></a><?php endforeach; ?></nav>
<?php if ($aviso !== ''): ?><div class="aviso aviso-info" id="aviso-ia">⚠️ <?= $aviso ?></div><?php endif; ?>

<?php if ($f === 'cronica' || $f === 'hoja'): ?>
<div class="tarjeta" style="max-width:760px">
  <h2 style="margin-top:0"><?= $funciones[$f][0] ?> <?= e($funciones[$f][1]) ?></h2>
  <p class="suave" style="margin-top:0"><?= $f === 'cronica' ? 'Escribe una crónica corta para Facebook e Instagram con el resultado, los goleadores y las tarjetas guardadas del partido.' : 'Tome una foto clara de la hoja de vocalía en papel. El asistente propone los goles y las tarjetas; usted los revisa y guarda los que estén bien.' ?></p>
  <form method="post" action="admin.php?p=asistente&amp;f=<?= $f ?>" <?= $f === 'hoja' ? 'enctype="multipart/form-data"' : '' ?>>
    <?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $f ?>">
    <label>Partido</label>
    <select name="partido" required><option value="">— Elegir partido —</option><?php foreach ($partidos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $p && (int)$p['id'] === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['local'] . ' vs ' . $x['visitante'] . ($x['goles_local'] !== null ? ' (' . $x['goles_local'] . '-' . $x['goles_visitante'] . ')' : '') . ' · ' . $x['torneo'] . ($x['fecha'] !== '' ? ' · ' . $x['fecha'] : '')) ?></option><?php endforeach; ?></select>
    <?php if ($f === 'hoja'): ?><label>Foto de la hoja de vocalía</label><input type="file" name="foto" accept="image/*,application/pdf" capture="environment" required><?php endif; ?>
    <label>Nota (opcional)</label><input type="text" name="nota" maxlength="300" value="<?= e($nota) ?>" placeholder="<?= $f === 'cronica' ? 'Ej.: era la final, llovió todo el partido' : 'Ej.: la segunda página está borrosa' ?>">
    <button class="btn" style="margin-top:8px">✨ <?= $f === 'cronica' ? 'Escribir la crónica' : 'Leer la foto' ?></button>
  </form>
</div>
<?php elseif ($f === 'informe' || $f === 'cobro'): ?>
<div class="tarjeta" style="max-width:760px">
  <h2 style="margin-top:0"><?= $funciones[$f][0] ?> <?= e($funciones[$f][1]) ?></h2>
  <p class="suave" style="margin-top:0"><?= $f === 'informe' ? 'Arma un mensaje para los padres con la asistencia, el nivel y las notas del profesor.' : 'Arma un recordatorio cordial con los valores pendientes de la familia, listo para WhatsApp.' ?></p>
  <form method="post" action="admin.php?p=asistente&amp;f=<?= $f ?>">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $f ?>">
    <label>Alumno</label>
    <select name="alumno" required><option value="">— Elegir alumno —</option><?php foreach ($alumnos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $a && (int)$a['id'] === (int)$x['id'] ? 'selected' : '' ?>><?= e(trim($x['nombres'] . ' ' . $x['apellidos'])) ?></option><?php endforeach; ?></select>
    <label>Nota (opcional)</label><input type="text" name="nota" maxlength="300" value="<?= e($nota) ?>" placeholder="<?= $f === 'informe' ? 'Ej.: mencione que se presenta en el recital de diciembre' : 'Ej.: recordar que pueden pagar por DeUna' ?>">
    <button class="btn" style="margin-top:8px">✨ Escribir el mensaje</button>
  </form>
</div>
<?php endif; ?>

<?php if ($texto !== null):
    $tel = $a ? telefono_wa((string)$a['representante_telefono']) : '';
    $wa = 'https://wa.me/' . $tel . '?text=' . rawurlencode($texto); ?>
<div class="tarjeta" id="propuesta" style="max-width:760px">
  <h2 style="margin-top:0">Revise el texto</h2>
  <textarea id="txt-propuesta" rows="10" maxlength="3000"><?= e($texto) ?></textarea>
  <div class="acciones" style="margin-top:8px">
    <button class="btn btn-sec" type="button" onclick="var t=document.getElementById('txt-propuesta');t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent='✔ Copiado'">📋 Copiar</button>
    <a class="btn btn-wa" id="wa-propuesta" target="_blank" rel="noopener" href="<?= e($wa) ?>"
       onclick="this.href='https://wa.me/<?= e($tel) ?>?text='+encodeURIComponent(document.getElementById('txt-propuesta').value)">📲 <?= $a ? 'Enviar por WhatsApp a ' . e($a['representante_nombre'] !== '' ? $a['representante_nombre'] : 'la familia') : 'Compartir por WhatsApp' ?></a>
  </div>
  <p class="suave" style="margin-bottom:0">Puede cambiar el texto antes de copiarlo o enviarlo.</p>
</div>
<?php endif; ?>

<?php if ($propuestos !== null && $p):
    $reglas = reglas_torneo((int)$p['torneo_id']);
    $dep = deporte_de($reglas);
    $nl = (string)valor('SELECT nombre FROM equipos WHERE id = ?', [$p['local_id']]);
    $nv = (string)valor('SELECT nombre FROM equipos WHERE id = ?', [$p['visitante_id']]);
    $opc = function (?int $sel) use ($propuestos, $nl, $nv): string {
        $h = '<option value="">— Elegir —</option>';
        foreach ([[$nl, $propuestos['nomina_local']], [$nv, $propuestos['nomina_visitante']]] as [$eq, $js]) {
            $h .= '<optgroup label="' . e($eq) . '">';
            foreach ($js as $j) $h .= '<option value="' . (int)$j['id'] . '"' . ($sel === (int)$j['id'] ? ' selected' : '') . '>' . ($j['numero'] !== null ? '#' . (int)$j['numero'] . ' ' : '') . e($j['nombre']) . '</option>';
            $h .= '</optgroup>';
        }
        return $h;
    }; ?>
<div class="tarjeta" id="propuestos">
  <h2 style="margin-top:0">Revise los goles y las tarjetas de <?= e($nl . ' vs ' . $nv) ?></h2>
  <p class="suave" style="margin-top:0">Elija el jugador correcto si no se reconoció. Solo se guardan los marcados.</p>
  <form method="post" action="admin.php?p=asistente&amp;f=hoja">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="hoja_guardar"><input type="hidden" name="partido" value="<?= (int)$p['id'] ?>">
    <div class="tabla-scroll"><table>
      <tr><th>Guardar</th><th>Leído en la hoja</th><th>Jugador</th><th>Tipo</th><th>Minuto</th></tr>
      <?php foreach ($propuestos['eventos'] as $i => $e): ?>
      <tr>
        <td><input type="checkbox" name="ev[<?= $i ?>][usar]" value="1" <?= $e['jugador_id'] ? 'checked' : '' ?> aria-label="Guardar"></td>
        <td><small><?= e(($e['equipo'] === 'local' ? $nl : $nv) . ' · ' . ($e['numero'] !== null ? '#' . $e['numero'] . ' ' : '') . $e['nombre']) ?></small></td>
        <td><select name="ev[<?= $i ?>][jugador]"><?= $opc($e['jugador_id']) ?></select></td>
        <td><select name="ev[<?= $i ?>][tipo]"><?php foreach (ia_tipos_hoja($reglas) as $t): ?><option value="<?= e($t) ?>" <?= $t === $e['tipo'] ? 'selected' : '' ?>><?= e(nombre_evento($t, $dep)) ?></option><?php endforeach; ?></select></td>
        <td><input type="number" name="ev[<?= $i ?>][minuto]" min="0" max="200" value="<?= $e['minuto'] !== null ? (int)$e['minuto'] : '' ?>" style="width:5em"></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <label class="check"><input type="checkbox" name="marcador" value="1" checked> Actualizar el marcador con los goles guardados</label>
    <button class="btn">Guardar los marcados</button> <a class="btn btn-sec" href="<?= e($url_partido) ?>#eventos">Cancelar</a>
  </form>
</div>
<?php endif; ?>
<?php
pie_admin();
