<?php
/* (v10) TABLAS ACUMULADAS: suma de varios campeonatos (ej: Apertura + Clausura) en una tabla general */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$torneos = filas('SELECT * FROM torneos WHERE liga_id = ? AND ' . sql_sin_carreras() . ' ORDER BY id DESC', [$lid]);
$ids_validos = array_map('intval', array_column($torneos, 'id'));

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        $sel = array_values(array_intersect(array_map('intval', (array)($_POST['torneos'] ?? [])), $ids_validos));
        sort($sel);
        if (mb_strlen(post('nombre')) < 3) throw new RuntimeException('Escriba el nombre de la tabla (ej: Tabla acumulada 2026).');
        if (count($sel) < 2) throw new RuntimeException('Marque al menos 2 campeonatos para sumar.');
        $datos = [mb_substr(post('nombre'), 0, 100), implode(',', $sel), post('publica') === '1' ? 1 : 0, mb_substr(post('nota'), 0, 500)];
        if ((int)post('id')) {
            q('UPDATE tablas_acumuladas SET nombre = ?, torneos = ?, publica = ?, nota = ? WHERE id = ? AND liga_id = ?', array_merge($datos, [(int)post('id'), $lid]));
            flash('Tabla acumulada guardada.');
            ir('admin.php?p=acumuladas&id=' . (int)post('id'));
        }
        q('INSERT INTO tablas_acumuladas (nombre, torneos, publica, nota, liga_id) VALUES (?,?,?,?,?)', array_merge($datos, [$lid]));
        flash('Tabla acumulada creada.' . (post('publica') === '1' ? ' Ya aparece en la página pública (pestaña "Σ Acumulada").' : ''));
        ir('admin.php?p=acumuladas&id=' . db()->lastInsertId());
    }
    if ($accion === 'eliminar') {
        q('DELETE FROM tablas_acumuladas WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        flash('Tabla acumulada eliminada (los campeonatos no se tocan).');
        ir('admin.php?p=acumuladas');
    }
    ir('admin.php?p=acumuladas');
}

$lista = filas('SELECT * FROM tablas_acumuladas WHERE liga_id = ? ORDER BY id DESC', [$lid]);
$ver = get('id') !== '' ? fila('SELECT * FROM tablas_acumuladas WHERE id = ? AND liga_id = ?', [(int)get('id'), $lid]) : null;
$editar = get('editar') !== '' ? fila('SELECT * FROM tablas_acumuladas WHERE id = ? AND liga_id = ?', [(int)get('editar'), $lid]) : null;
$T = textos_deporte($liga);

if ($ver && get('imprimir') === '1') {
    $filas_t = tabla_acumulada($ver);
    doc_inicio($ver['nombre'], 'admin.php?p=acumuladas&id=' . (int)$ver['id']);
    doc_cabecera_financiera($liga, mb_strtoupper($ver['nombre']));
    ?>
  <p>Suma de: <b><?= e(implode(' + ', array_map(fn($x) => trim($x['nombre'] . ' ' . $x['temporada']), torneos_acumulada($ver)))) ?></b> (fase regular).</p>
  <table class="pl"><tr><th>#</th><th>Equipo</th><th>PJ</th><th>G</th><?php if ($T['con_empate']): ?><th>E</th><?php endif; ?><th>P</th><th><?= e($T['gf']) ?></th><th><?= e($T['gc']) ?></th><th><?= e($T['dg']) ?></th><th>Pts</th></tr>
    <?php foreach ($filas_t as $i => $f): ?><tr><td><?= $i + 1 ?></td><td><?= e($f['nombre']) ?></td><td><?= $f['pj'] ?></td><td><?= $f['pg'] ?></td><?php if ($T['con_empate']): ?><td><?= $f['pe'] ?></td><?php endif; ?><td><?= $f['pp'] ?></td><td><?= $f['gf'] ?></td><td><?= $f['gc'] ?></td><td><?= $f['dg'] ?></td><td><b><?= $f['pts'] ?></b></td></tr><?php endforeach; ?>
  </table>
  <?php if ($ver['nota'] !== ''): ?><p><?= nl2br(e($ver['nota'])) ?></p><?php endif; ?>
  <p style="font-size:11px;color:#555">Generado el <?= date('d/m/Y H:i') ?> con <?= e(marca()) ?>.</p>
    <?php
    doc_fin();
    return;
}

cabecera_admin('Tablas acumuladas');
$form = $editar ?? ['id' => 0, 'nombre' => 'Tabla acumulada ' . date('Y'), 'torneos' => '', 'publica' => 1, 'nota' => ''];
$marcados = array_map('intval', explode(',', (string)$form['torneos']));
?>
<p><a href="admin.php?p=torneos">← Campeonatos</a></p>
<h1>Σ Tablas acumuladas</h1>
<p class="suave">Sume dos o más campeonatos (por ejemplo <b>Apertura + Clausura</b>) en una tabla general: se suman puntos, partidos, goles y descuentos por sanción de la fase regular de cada uno. Sirve para definir la final del año, los descensos o los cupos.</p>

<?php if ($ver): $filas_t = tabla_acumulada($ver); ?>
<div class="tarjeta">
  <div class="fila-titulo" style="margin:0"><h2 style="margin:0"><?= e($ver['nombre']) ?> <?= $ver['publica'] ? '<span class="etiqueta verde">pública</span>' : '<span class="etiqueta gris">solo interna</span>' ?></h2>
    <div class="acciones"><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=acumuladas&amp;id=<?= (int)$ver['id'] ?>&amp;imprimir=1">🖨 Imprimir / PDF</a>
      <?php if (puede('campeonatos', true)): ?><a class="btn btn-sec btn-chico" href="admin.php?p=acumuladas&amp;editar=<?= (int)$ver['id'] ?>#form">Editar</a><?php endif; ?></div></div>
  <p class="suave">Suma de: <?= e(implode(' + ', array_map(fn($x) => trim($x['nombre'] . ' ' . $x['temporada']), torneos_acumulada($ver)))) ?></p>
  <div class="tabla-scroll"><table>
    <tr><th>#</th><th>Equipo</th><th class="num">PJ</th><th class="num">G</th><?php if ($T['con_empate']): ?><th class="num">E</th><?php endif; ?><th class="num">P</th><th class="num"><?= e($T['gf']) ?></th><th class="num"><?= e($T['gc']) ?></th><th class="num"><?= e($T['dg']) ?></th><th class="num">Pts</th></tr>
    <?php foreach ($filas_t as $i => $f): ?><tr><td><?= $i + 1 ?></td><td><div class="eq-celda"><?= escudo($f['escudo'], $f['nombre'], $f['color']) ?> <?= e($f['nombre']) ?></div></td><td class="num"><?= $f['pj'] ?></td><td class="num"><?= $f['pg'] ?></td><?php if ($T['con_empate']): ?><td class="num"><?= $f['pe'] ?></td><?php endif; ?><td class="num"><?= $f['pp'] ?></td><td class="num"><?= $f['gf'] ?></td><td class="num"><?= $f['gc'] ?></td><td class="num"><?= $f['dg'] > 0 ? '+' : '' ?><?= $f['dg'] ?></td><td class="num pts"><?= $f['pts'] ?><?= $f['sancion'] ? '<sup style="color:var(--roja)" title="Puntos restados por sanción">*</sup>' : '' ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php if ($ver['nota'] !== ''): ?><p class="aviso aviso-info"><?= nl2br(e($ver['nota'])) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($lista): ?>
<div class="tabla-scroll"><table>
  <tr><th>Tabla</th><th>Campeonatos que suma</th><th>Página pública</th><th></th></tr>
  <?php foreach ($lista as $a): ?>
    <tr><td><a href="admin.php?p=acumuladas&amp;id=<?= (int)$a['id'] ?>"><b><?= e($a['nombre']) ?></b></a></td>
      <td style="white-space:normal"><?= e(implode(' + ', array_column(torneos_acumulada($a), 'nombre'))) ?></td>
      <td><?= $a['publica'] ? 'Sí' : 'No' ?></td>
      <td class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=acumuladas&amp;id=<?= (int)$a['id'] ?>">Ver</a>
        <?php if (puede('campeonatos', true)): ?><form class="inline" method="post" onsubmit="return confirm('¿Eliminar esta tabla acumulada? Los campeonatos no se tocan.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sec btn-chico">Eliminar</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if (puede('campeonatos', true)): ?>
<div class="tarjeta" id="form">
  <h2 style="margin-top:0"><?= $editar ? 'Editar ' . e($editar['nombre']) : '+ Nueva tabla acumulada' ?></h2>
  <?php if (count($torneos) < 2): ?><p class="suave">Necesita al menos 2 campeonatos para sumarlos.</p><?php else: ?>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
    <div class="campos"><div class="campo-ancho"><label>Nombre *</label><input type="text" name="nombre" required maxlength="100" value="<?= e($form['nombre']) ?>"></div></div>
    <p><b>Campeonatos que se suman</b> (marque 2 o más):</p>
    <?php foreach ($torneos as $x): ?><label class="check" style="font-weight:400"><input type="checkbox" name="torneos[]" value="<?= (int)$x['id'] ?>" <?= in_array((int)$x['id'], $marcados, true) ? 'checked' : '' ?>> <?= e($x['nombre'] . ' ' . $x['temporada']) ?><?= $x['categoria'] !== '' ? ' · ' . e($x['categoria']) : '' ?></label><?php endforeach; ?>
    <p><label>Nota (opcional, se muestra debajo de la tabla)</label><textarea name="nota" rows="2" maxlength="500" placeholder="Ej: Los 2 primeros juegan la final del año. Los 2 últimos descienden."><?= e($form['nota']) ?></textarea></p>
    <label class="check"><input type="checkbox" name="publica" value="1" <?= $form['publica'] ? 'checked' : '' ?>> Mostrarla en la página pública (pestaña "Σ Acumulada")</label>
    <button class="btn" style="margin-top:8px">Guardar</button>
  </form>
  <?php endif; ?>
</div>
<?php endif;
pie_admin();
