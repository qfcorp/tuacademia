<?php
/*
 * (v27) NOVEDADES: lo nuevo de cada versión. Todos las ven; el superadministrador las escribe.
 */
$super = es_super();
$liga = liga_trabajo();

if (es_post() && $super) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = (int)post('id');
        $titulo = mb_substr(trim(post('titulo')), 0, 120);
        if (mb_strlen($titulo) < 3) throw new RuntimeException('Escriba el título.');
        $d = [$titulo, mb_substr(trim(post('texto')), 0, 2000), in_array(post('para'), ['todos', 'ligas', 'escuelas'], true) ? post('para') : 'todos',
            mb_substr(trim(post('enlace')), 0, 200), mb_substr(trim(post('version')), 0, 20), post('publicada') === '1' ? 1 : 0, post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d')];
        if ($id) q('UPDATE novedades SET titulo = ?, texto = ?, para = ?, enlace = ?, version = ?, publicada = ?, fecha = ? WHERE id = ?', array_merge($d, [$id]));
        else q('INSERT INTO novedades (titulo, texto, para, enlace, version, publicada, fecha) VALUES (?,?,?,?,?,?,?)', $d);
        flash('Novedad guardada.');
        ir('admin.php?p=novedades');
    }
    if ($accion === 'borrar') {
        q('DELETE FROM novedades WHERE id = ?', [(int)post('id')]);
        flash('Novedad borrada.');
        ir('admin.php?p=novedades');
    }
}

$vista = (int)valor('SELECT novedad_vista FROM usuarios WHERE id = ?', [usuario()['id']]);
$lista = $super ? filas('SELECT * FROM novedades ORDER BY fecha DESC, id DESC LIMIT 200') : novedades_para($liga);
marcar_novedades_vistas();
$editar = $super && (int)get('editar') ? fila('SELECT * FROM novedades WHERE id = ?', [(int)get('editar')]) : null;
cabecera_admin('Novedades');
?>
<h1>🆕 Novedades de <?= e(marca()) ?></h1>
<p class="suave">Lo nuevo de cada versión. Si tiene una idea, <a href="admin.php?p=soporte&amp;nuevo=funcionalidad">pídanos una funcionalidad</a>.</p>

<?php if ($super): ?>
<details class="tarjeta" <?= $editar ? 'open' : '' ?>>
  <summary><b><?= $editar ? '✏️ Editar novedad' : '+ Nueva novedad' ?></b></summary>
  <form method="post" style="margin-top:10px">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">
    <div class="campos">
      <div class="campo-ancho"><label>Título (puede empezar con un emoji)</label><input name="titulo" required value="<?= e($editar['titulo'] ?? '') ?>"></div>
      <div class="campo-ancho"><label>Texto</label><textarea name="texto" rows="3"><?= e($editar['texto'] ?? '') ?></textarea></div>
      <div><label>Para</label><select name="para"><?php foreach (['todos' => 'Ligas y escuelas', 'ligas' => 'Solo ligas', 'escuelas' => 'Solo escuelas'] as $k => $n): ?><option value="<?= $k ?>" <?= ($editar['para'] ?? 'todos') === $k ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
      <div><label>Enlace (opcional)</label><input name="enlace" value="<?= e($editar['enlace'] ?? '') ?>" placeholder="manual/ligas#s28 o admin.php?p=cargas"></div>
      <div><label>Versión</label><input name="version" value="<?= e($editar['version'] ?? '') ?>" placeholder="v28"></div>
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($editar['fecha'] ?? date('Y-m-d')) ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="publicada" value="1" <?= ($editar['publicada'] ?? 1) ? 'checked' : '' ?>> Publicada (la ven las cuentas)</label>
    <button class="btn">Guardar</button> <?php if ($editar): ?><a class="btn btn-sec" href="admin.php?p=novedades">Cancelar</a><?php endif; ?>
  </form>
</details>
<?php endif; ?>

<?php if (!$lista): ?><div class="tarjeta vacio">Todavía no hay novedades.</div><?php endif; ?>
<?php foreach ($lista as $n): ?>
  <div class="tarjeta novedad <?= (int)$n['id'] > $vista ? 'novedad-nueva' : '' ?>">
    <div class="fila-titulo" style="margin:0"><h2 style="margin:0"><?= e($n['titulo']) ?><?= (int)$n['id'] > $vista ? ' <span class="etiqueta roja">nuevo</span>' : '' ?></h2>
      <small class="suave"><?= e($n['version']) ?> · <?= e(fecha_bonita($n['fecha'])) ?><?= $super ? ' · ' . e($n['para']) . ((int)$n['publicada'] ? '' : ' · <b>borrador</b>') : '' ?></small></div>
    <?php if ($n['texto'] !== ''): ?><p style="margin:.4rem 0"><?= nl2br(e($n['texto'])) ?></p><?php endif; ?>
    <?php if ($n['enlace'] !== ''): ?><a class="btn btn-sec btn-chico" href="<?= e($n['enlace']) ?>" <?= str_starts_with($n['enlace'], 'manual') ? 'target="_blank"' : '' ?>>Ver →</a><?php endif; ?>
    <?php if ($super): ?>
      <a class="btn btn-sec btn-chico" href="admin.php?p=novedades&amp;editar=<?= (int)$n['id'] ?>">Editar</a>
      <form method="post" class="inline" onsubmit="return confirm('¿Borrar esta novedad?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sec btn-chico">Borrar</button></form>
    <?php endif; ?>
  </div>
<?php endforeach;
pie_admin();
