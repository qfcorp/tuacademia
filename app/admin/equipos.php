<?php
/* Lista de equipos de la liga + crear equipo */
$liga = requiere_liga();
$lid = (int)$liga['id'];

if (es_post()) {
    $nombre = post('nombre');
    if ($nombre === '') throw new RuntimeException('Escriba el nombre del equipo.');
    verificar_limite_plan($liga, 'equipo');
    if (fila('SELECT id FROM equipos WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $nombre])) throw new RuntimeException('Ya existe un equipo con ese nombre.');
    $escudo = subir_imagen('escudo', 'escudos', 400) ?? '';
    q('INSERT INTO equipos (liga_id, nombre, abreviatura, escudo, color, delegado, telefono) VALUES (?,?,?,?,?,?,?)', [
        $lid, mb_substr($nombre, 0, 80), mb_strtoupper(mb_substr(post('abreviatura'), 0, 4)), $escudo,
        color_valido(post('color'), '#333333'), mb_substr(post('delegado'), 0, 80), mb_substr(post('telefono'), 0, 30),
    ]);
    $id = (int)db()->lastInsertId();
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', post('fundado')) && post('fundado') <= date('Y-m-d')) q('UPDATE equipos SET fundado = ? WHERE id = ?', [post('fundado'), $id]); // (v18)
    flash('Equipo creado. Ahora agregue sus jugadores.');
    ir('admin.php?p=equipo&id=' . $id);
}

$equipos = filas('SELECT e.*, (SELECT COUNT(*) FROM jugadores j WHERE j.equipo_id = e.id AND j.activo = 1) AS n_jug
                  FROM equipos e WHERE e.liga_id = ? ORDER BY e.nombre', [$lid]);
cabecera_admin('Equipos');
?>
<div class="fila-titulo">
  <h1>Equipos (<?= count($equipos) ?>)</h1>
  <span class="acciones"><?php if (puede('equipos', true)): ?><a class="btn btn-sec" href="admin.php?p=importar">📥 Importar desde Excel</a> <?php endif; ?><a class="btn" href="#nuevo">+ Nuevo equipo</a></span>
</div>
<?php if (!$equipos): ?><div class="tarjeta">Aún no hay equipos. Cree el primero abajo.</div><?php endif; ?>
<div class="rejilla">
  <?php foreach ($equipos as $e):
      $n = (int)$e['n_jug'];
      [$mn, $mx] = limites_plantilla((int)$e['id'], $liga);
      $estado = $n < $mn ? '<span class="etiqueta roja">faltan ' . ($mn - $n) . '</span>' : '<span class="etiqueta verde">completo</span>';
  ?>
    <a class="tarjeta partido-link" href="admin.php?p=equipo&amp;id=<?= (int)$e['id'] ?>">
      <div class="eq-celda"><?= escudo($e['escudo'], $e['nombre'], $e['color']) ?> <strong><?= e($e['nombre']) ?></strong></div>
      <p class="suave" style="margin:.5rem 0 0"><?= $n ?> / <?= $mx ?> jugadores <?= $estado ?></p>
    </a>
  <?php endforeach; ?>
</div>

<div class="tarjeta" id="nuevo" style="margin-top:20px">
  <h2>Nuevo equipo</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_campo() ?>
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="80"></div>
      <div><label>Abreviatura</label><input type="text" name="abreviatura" maxlength="4" placeholder="Ej: LDU"></div>
      <div><label>Escudo</label><input type="file" name="escudo" accept="image/*"></div>
      <div><label>Color</label><input type="color" name="color" value="#333333"></div>
      <div><label>Delegado / responsable</label><input type="text" name="delegado" maxlength="80"></div>
      <div><label>Teléfono</label><input type="text" name="telefono" maxlength="30"></div>
      <div><label>Fecha de fundación <small>(opcional)</small></label><input type="date" name="fundado" max="<?= date('Y-m-d') ?>"></div>
    </div>
    <button class="btn" type="submit">Crear equipo</button>
  </form>
</div>
<?php
pie_admin();
