<?php
/*
 * (21 oct 2026) AYUDA → PREGUNTAS FRECUENTES (solo el superadministrador). Las respuestas que escribió la IA salen primero en
 * «Por revisar». Aprobar, Editar (queda aprobada y «manual»), Borrar y Agregar pregunta. Con CSRF y en la bitácora.
 * Cada pregunta es de un modo (ligas, escuelas, federaciones, academia) o de «Todos».
 */
if (!es_super()) ir('admin.php?p=ayuda');
$faq = ia_faq();
$modos = ia_nombres_modo();
$filtros = ['sin_aprobar' => 'Por revisar', '' => 'Todas', 'automatica' => 'Automáticas', 'manual' => 'Escritas a mano'];
$filtro = array_key_exists(get('f', 'sin_aprobar'), $filtros) ? get('f', 'sin_aprobar') : 'sin_aprobar';
$volver = 'admin.php?p=ayuda_faq&f=' . rawurlencode($filtro);

if (es_post()) {
    $accion = post('accion');
    $id = (int)post('id');
    $f = $id ? $faq->obtener($id) : null;
    if (in_array($accion, ['aprobar', 'editar', 'borrar'], true) && !$f) throw new RuntimeException('Esa pregunta ya no existe.');
    $preg = trim(mb_substr(post('pregunta'), 0, 500));
    $resp = trim(mb_substr(post('respuesta'), 0, 8000));
    $modo = array_key_exists(post('modo'), $modos) ? post('modo') : '';
    if ($accion === 'aprobar') {
        $faq->aprobar($id);
        bitacora_evento('ayuda_faq', 'Aprobó la pregunta frecuente #' . $id . ': ' . mb_substr($f['pregunta'], 0, 200));
        flash('Pregunta aprobada.');
    } elseif ($accion === 'editar' || $accion === 'agregar') {
        if ($preg === '' || $resp === '') throw new RuntimeException('Escriba la pregunta y la respuesta.');
        if ($accion === 'editar') {
            $faq->editar($id, $preg, $resp);
            q('UPDATE tukan_ia_faq SET modo = ? WHERE id = ?', [$modo, $id]);
            bitacora_evento('ayuda_faq', 'Editó la pregunta frecuente #' . $id . ': ' . mb_substr($preg, 0, 200));
            flash('Pregunta guardada (queda aprobada y escrita a mano).');
        } else {
            $nid = (int)$faq->agregar($preg, $resp);
            q('UPDATE tukan_ia_faq SET modo = ? WHERE id = ?', [$modo, $nid]);
            bitacora_evento('ayuda_faq', 'Agregó la pregunta frecuente #' . $nid . ': ' . mb_substr($preg, 0, 200));
            flash('Pregunta agregada (queda aprobada).');
        }
    } elseif ($accion === 'borrar') {
        $faq->borrar($id);
        bitacora_evento('ayuda_faq', 'Borró la pregunta frecuente #' . $id . ': ' . mb_substr($f['pregunta'], 0, 200));
        flash('Pregunta borrada.');
    }
    ir($volver);
}

$lista = $faq->listar($filtro);
$modo_de = array_column(filas('SELECT id, modo FROM tukan_ia_faq'), 'modo', 'id');
$res = $faq->resumen();
$editar = (int)get('editar') ? $faq->obtener((int)get('editar')) : null;
$nueva = get('nueva') === '1';
$estado = tukan_ia_estado();
cabecera_admin('Preguntas frecuentes');
?>
<p><a href="admin.php?p=ayuda">← Ayuda</a></p>
<div class="fila-titulo"><h1>❓ Ayuda → Preguntas frecuentes</h1><a class="btn" href="admin.php?p=ayuda_faq&amp;nueva=1&amp;f=<?= e($filtro) ?>#form-faq">+ Agregar pregunta</a></div>
<p class="suave">Cada respuesta sirve para todas las cuentas de su modo. Las que escribió la IA salen primero en «Por revisar». Una respuesta mala se <b>edita</b>: desde ahí sale la buena para todos.</p>
<p><b><?= (int)$res['total'] ?></b> preguntas · <?= (int)$res['sin_aprobar'] ?> por revisar · <?= (int)$res['automaticas'] ?> automáticas · <?= (int)$res['manuales'] ?> escritas a mano · <?= (int)$res['usos'] ?> usos
  · IA: <?= $estado['configurada'] ? ($estado['pausada'] ? 'en pausa' : 'encendida') : 'sin configurar' ?></p>
<nav class="pestanas"><?php foreach ($filtros as $k => $t): ?><a class="<?= $k === $filtro ? 'activa' : '' ?>" href="admin.php?p=ayuda_faq&amp;f=<?= e($k) ?>"><?= e($t) ?></a><?php endforeach; ?></nav>

<?php if ($editar || $nueva): ?>
<div class="tarjeta" id="form-faq" style="max-width:760px">
  <h2 style="margin-top:0"><?= $editar ? 'Editar la pregunta #' . (int)$editar['id'] : 'Agregar pregunta' ?></h2>
  <form method="post" action="<?= e($volver) ?>">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $editar ? 'editar' : 'agregar' ?>"><?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
    <div class="campos">
      <div class="campo-ancho"><label>Pregunta</label><input type="text" name="pregunta" required maxlength="500" value="<?= e($editar['pregunta'] ?? '') ?>"></div>
      <div class="campo-ancho"><label>Respuesta</label><textarea name="respuesta" rows="8" required maxlength="8000"><?= e($editar['respuesta'] ?? '') ?></textarea><div class="ayuda">Puede usar **negritas** y pasos 1. 2. 3. Al guardar queda aprobada.</div></div>
      <div><label>Para</label><select name="modo"><?php foreach ($modos as $k => $n): ?><option value="<?= e($k) ?>" <?= ($editar ? ($modo_de[$editar['id']] ?? '') : '') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    </div>
    <button class="btn">Guardar</button> <a class="btn btn-sec" href="<?= e($volver) ?>">Cancelar</a>
  </form>
</div>
<?php endif; ?>

<?php if (!$lista): ?><div class="tarjeta vacio"><?= $filtro === 'sin_aprobar' ? 'No hay preguntas por revisar.' : 'Todavía no hay preguntas guardadas.' ?></div><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th>Pregunta</th><th>Respuesta</th><th>Usos</th><th>Origen</th><th>Para</th><th></th></tr>
  <?php foreach ($lista as $f): ?>
  <tr>
    <td><b><?= e($f['pregunta']) ?></b><?= (int)$f['aprobada'] ? '' : ' <span class="etiqueta amarilla">Por revisar</span>' ?></td>
    <td><small><?= e(mb_strimwidth((string)$f['respuesta'], 0, 180, '…')) ?></small></td>
    <td class="num"><?= (int)$f['usos'] ?></td>
    <td><?= $f['origen'] === 'manual' ? 'Escrita a mano' : 'Automática' ?></td>
    <td><?= e($modos[$modo_de[$f['id']] ?? ''] ?? '') ?></td>
    <td style="white-space:nowrap">
      <?php if (!(int)$f['aprobada']): ?><form method="post" action="<?= e($volver) ?>" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-chico">Aprobar</button></form><?php endif; ?>
      <a class="btn btn-chico btn-sec" href="<?= e($volver) ?>&amp;editar=<?= (int)$f['id'] ?>#form-faq">Editar</a>
      <form method="post" action="<?= e($volver) ?>" style="display:inline" onsubmit="return confirm('¿Borrar esta pregunta frecuente?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-chico btn-peligro">Borrar</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php
pie_admin();
