<?php
/* Ficha de un equipo: editar datos, ver y agregar jugadores */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$eq = equipo_de_liga((int)get('id'), $lid);
$eid = (int)$eq['id'];
[$min_pl, $max_pl] = limites_plantilla($eid, $liga);

function datos_jugador_form(): array
{
    $nombres = post('nombres');
    if ($nombres === '') throw new RuntimeException('Escriba los nombres del jugador.');
    $fn = post('fecha_nac');
    return [
        'nombres' => mb_substr($nombres, 0, 80),
        'apellidos' => mb_substr(post('apellidos'), 0, 80),
        'cedula' => mb_substr(preg_replace('/[^0-9A-Za-z-]/', '', post('cedula')), 0, 20),
        'numero' => entero_o_null(post('numero')),
        'posicion' => in_array(post('posicion'), ['', 'Arquero', 'Defensa', 'Mediocampista', 'Delantero'], true) ? post('posicion') : '',
        'fecha_nac' => ($fn !== '' && strtotime($fn)) ? date('Y-m-d', strtotime($fn)) : '',
    ];
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'editar_equipo') {
        $nombre = post('nombre');
        if ($nombre === '') throw new RuntimeException('El nombre no puede quedar vacío.');
        $escudo = subir_imagen('escudo', 'escudos', 400);
        if ($escudo) borrar_imagen($eq['escudo']);
        q('UPDATE equipos SET nombre = ?, abreviatura = ?, color = ?, delegado = ?, telefono = ?, escudo = ? WHERE id = ?', [
            mb_substr($nombre, 0, 80), mb_strtoupper(mb_substr(post('abreviatura'), 0, 4)), color_valido(post('color'), '#333333'),
            mb_substr(post('delegado'), 0, 80), mb_substr(post('telefono'), 0, 30), $escudo ?? $eq['escudo'], $eid,
        ]);
        // (v18) Fecha de fundación: para el aniversario del equipo en la página pública
        $fund = post('fundado');
        q('UPDATE equipos SET fundado = ? WHERE id = ?', [preg_match('/^\d{4}-\d{2}-\d{2}$/', $fund) && $fund <= date('Y-m-d') ? $fund : '', $eid]);
        flash('Equipo actualizado.');
    } elseif ($accion === 'nuevo_jugador') {
        $activos = (int)valor('SELECT COUNT(*) FROM jugadores WHERE equipo_id = ? AND activo = 1', [$eid]);
        if ($activos >= $max_pl) {
            throw new RuntimeException('El equipo ya tiene el máximo de ' . $max_pl . ' jugadores permitidos en su campeonato. Dé de baja a uno o cambie la regla del campeonato.');
        }
        $d = datos_jugador_form();
        if ($d['cedula'] !== '') {
            $dup = fila('SELECT j.id, e.nombre FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.cedula = ? AND j.activo = 1', [$lid, $d['cedula']]);
            if ($dup) throw new RuntimeException('Esa cédula ya está inscrita en el equipo ' . $dup['nombre'] . '.');
        }
        if ($d['numero'] !== null && fila('SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ? AND activo = 1', [$eid, $d['numero']])) {
            throw new RuntimeException('El número ' . $d['numero'] . ' ya lo usa otro jugador del equipo.');
        }
        $notas = validar_categoria_jugador($eid, $d);
        $d += leer_consentimiento_post($d['fecha_nac']);
        $d['foto'] = subir_imagen('foto', 'jugadores', 600) ?? '';
        $d['equipo_id'] = $eid;
        q('INSERT INTO jugadores (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
        registrar_consentimiento_jugador('jugador', $lid, $d, 'jugadores', (int)db()->lastInsertId()); // (v24) prueba de la autorización
        flash('Jugador agregado: ' . $d['nombres'] . ' ' . $d['apellidos'] . ($notas ? ' · ' . implode(' · ', $notas) : ''));
    } elseif ($accion === 'delegado_crear') {
        $email = mb_strtolower(post('email'), 'UTF-8');
        if (mb_strlen(post('nombre')) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escriba el nombre y un correo válido del delegado.');
        if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        if (fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email])) throw new RuntimeException('Ese correo ya tiene una cuenta en el sistema.');
        q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id, equipo_id) VALUES (?,?,?, 'delegado', ?, ?)", [mb_substr(post('nombre'), 0, 80), $email, password_hash(post('clave'), PASSWORD_DEFAULT), $lid, $eid]);
        flash('Acceso del delegado creado. Entréguele su correo y contraseña; entra en ' . url_base() . 'admin.php?p=login');
    } elseif ($accion === 'delegado_clave') {
        $d = fila("SELECT * FROM usuarios WHERE id = ? AND equipo_id = ? AND rol = 'delegado'", [(int)post('uid'), $eid]);
        if (!$d) throw new RuntimeException('Delegado no encontrado.');
        if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash(post('clave'), PASSWORD_DEFAULT), $d['id']]);
        bitacora_evento('usuarios', 'Cambió la contraseña del delegado ' . $d['email'] . ' (' . $eq['nombre'] . ')', $lid, (int)$d['id']);
        flash('Contraseña del delegado cambiada.');
    } elseif ($accion === 'delegado_activo') {
        q("UPDATE usuarios SET activo = 1 - activo WHERE id = ? AND equipo_id = ? AND rol = 'delegado'", [(int)post('uid'), $eid]);
        flash('Estado del acceso cambiado.');
    } elseif ($accion === 'delegado_eliminar') {
        q("DELETE FROM usuarios WHERE id = ? AND equipo_id = ? AND rol = 'delegado'", [(int)post('uid'), $eid]);
        flash('Acceso del delegado eliminado.');
    } elseif ($accion === 'eliminar_equipo') {
        $n = (int)valor('SELECT COUNT(*) FROM partidos WHERE local_id = ? OR visitante_id = ?', [$eid, $eid]);
        if ($n > 0) throw new RuntimeException('No se puede eliminar: el equipo tiene ' . $n . ' partidos registrados. Retírelo de los campeonatos primero.');
        foreach (filas('SELECT foto FROM jugadores WHERE equipo_id = ?', [$eid]) as $j) borrar_imagen($j['foto']);
        borrar_imagen($eq['escudo']);
        q('DELETE FROM equipos WHERE id = ?', [$eid]);
        flash('Equipo eliminado.');
        ir('admin.php?p=equipos');
    }
    ir('admin.php?p=equipo&id=' . $eid);
}

$jugadores = filas('SELECT * FROM jugadores WHERE equipo_id = ? ORDER BY activo DESC, (numero IS NULL), numero, apellidos', [$eid]);
$activos = count(array_filter($jugadores, fn($j) => $j['activo']));
$tcat = torneos_categoria_equipo($eid);
cabecera_admin($eq['nombre']);
?>
<p><a href="admin.php?p=equipos">← Equipos</a></p>
<div class="perfil tarjeta">
  <?= escudo($eq['escudo'], $eq['nombre'], $eq['color'], 'escudo-grande') ?>
  <div>
    <h1 style="margin:0"><?= e($eq['nombre']) ?></h1>
    <p class="suave" style="margin:.3rem 0">
      <?= $activos ?> jugadores activos (mín. <?= $min_pl ?>, máx. <?= $max_pl ?>)
      <?= $eq['delegado'] !== '' ? ' · Delegado: ' . e($eq['delegado']) : '' ?><?= $eq['telefono'] !== '' ? ' · ' . e($eq['telefono']) : '' ?>
    </p>
    <div class="acciones"><a class="btn btn-chico" target="_blank" href="admin.php?p=carnets&amp;equipo=<?= $eid ?>">🪪 Imprimir carnets del equipo</a> <a class="btn btn-sec btn-chico" href="admin.php?p=finanzas&amp;equipo=<?= $eid ?>">💵 Estado de cuenta</a> <a class="btn btn-sec btn-chico" href="admin.php?p=bitacora&amp;equipo=<?= $eid ?>">🔎 Historial</a> <a href="#editar">Editar datos del equipo</a></div>
  </div>
</div>

<h2>Jugadores</h2>
<?php foreach ($tcat as $tc): ?><p class="suave" style="margin-top:-6px">👶 <?= e($tc['nombre']) ?>: <?= e(texto_categoria($tc)) ?></p><?php endforeach; ?>
<?php if (!$jugadores): ?><p class="suave">Todavía no hay jugadores. Agréguelos con el formulario de abajo.</p><?php endif; ?>
<div class="jugadores">
  <?php $mod_req = modulo_de_equipo($eid, $liga); // (v35) requisitos del módulo deportivo
  foreach ($jugadores as $j): ?>
    <a class="jugador-card" href="admin.php?p=jugador&amp;id=<?= (int)$j['id'] ?>" style="<?= $j['activo'] ? '' : 'opacity:.5' ?>">
      <?php if ($j['numero'] !== null): ?><span class="dorsal"><?= (int)$j['numero'] ?></span><?php endif; ?>
      <?= foto_jugador($j, 'foto-card' . ($j['foto'] ? '' : ' foto-vacia')) ?>
      <div class="datos"><span class="nombre"><?= e(nombre_jugador($j)) ?></span><small><?= e($j['posicion'] ?: 'Sin posición') ?><?= $j['activo'] ? '' : ' · de baja' ?></small><?php foreach ($tcat as $tc) echo etiqueta_categoria(situacion_categoria($tc, $j)); ?><?= $j['activo'] && $j['consentimiento'] === '' ? ' <span class="etiqueta gris" title="Falta registrar la autorización de datos">sin autorización</span>' : '' ?><?= $j['activo'] ? ' ' . html_estado_requisitos('jugadores', (int)$j['id'], $mod_req) : '' ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="tarjeta" style="margin-top:20px" id="nuevo-jugador">
  <h2>Agregar jugador</h2>
  <?php if ($activos >= $max_pl): ?>
    <div class="aviso aviso-error">El equipo llegó al máximo de jugadores permitido (<?= $max_pl ?>).</div>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_jugador">
    <div class="campos">
      <div><label>Nombres *</label><input type="text" name="nombres" required maxlength="80"></div>
      <div><label>Apellidos</label><input type="text" name="apellidos" maxlength="80"></div>
      <div><label>Cédula</label><input type="text" name="cedula" maxlength="20" inputmode="numeric"></div>
      <div><label>Número de camiseta</label><input type="number" name="numero" min="0" max="99"></div>
      <div><label>Posición</label><select name="posicion"><option value="">—</option><option>Arquero</option><option>Defensa</option><option>Mediocampista</option><option>Delantero</option></select></div>
      <div><label>Fecha de nacimiento</label><input type="date" name="fecha_nac"></div>
      <div class="campo-ancho"><label>Foto del jugador</label><input type="file" name="foto" accept="image/*"><div class="ayuda">Desde el celular puede tomar la foto directamente.</div></div>
    </div>
    <?= html_consentimiento([], 'c-nuevo') ?>
    <button class="btn" type="submit">Agregar jugador</button>
  </form>
  <?php endif; ?>
</div>

<?php $delegados = filas("SELECT * FROM usuarios WHERE equipo_id = ? AND rol = 'delegado' ORDER BY id", [$eid]); ?>
<div class="tarjeta" id="delegados">
  <h2>🧑‍💼 Acceso del delegado</h2>
  <p class="suave">Con su correo y contraseña, el delegado entra a <b><?= e(url_base()) ?>admin.php?p=login</b> y desde su celular: inscribe jugadores (la liga los aprueba en <a href="admin.php?p=inscripciones">Inscripciones</a>), pide bajas, ve su plantilla, sus partidos y su estado de cuenta.</p>
  <?php if ($delegados): ?>
  <div class="tabla-scroll"><table>
    <tr><th>Nombre</th><th>Correo</th><th>Estado</th><th></th></tr>
    <?php foreach ($delegados as $d): ?>
      <tr><td><?= e($d['nombre']) ?></td><td><?= e($d['email']) ?></td><td><?= $d['activo'] ? 'Activo' : '<span class="etiqueta gris">Inactivo</span>' ?></td>
        <td class="acciones">
          <form class="inline" method="post" onsubmit="var c=prompt('Nueva contraseña (mínimo 8):'); if(!c) return false; this.clave.value=c; return true;"><?= csrf_campo() ?><input type="hidden" name="accion" value="delegado_clave"><input type="hidden" name="uid" value="<?= (int)$d['id'] ?>"><input type="hidden" name="clave" value=""><button class="btn btn-sec btn-chico">Cambiar clave</button></form>
          <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="delegado_activo"><input type="hidden" name="uid" value="<?= (int)$d['id'] ?>"><button class="btn btn-sec btn-chico"><?= $d['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          <form class="inline" method="post" onsubmit="return confirm('¿Eliminar este acceso?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="delegado_eliminar"><input type="hidden" name="uid" value="<?= (int)$d['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
        </td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <form method="post" style="margin-top:12px">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="delegado_crear">
    <div class="campos">
      <div><label>Nombre del delegado</label><input type="text" name="nombre" required maxlength="80" value="<?= $delegados ? '' : e($eq['delegado']) ?>"></div>
      <div><label>Correo (usuario)</label><input type="email" name="email" required></div>
      <div><label>Contraseña (mín. 8)</label><input type="text" name="clave" required minlength="8"></div>
    </div>
    <button class="btn btn-sec">+ Crear acceso de delegado</button>
  </form>
</div>

<div class="tarjeta" id="editar">
  <h2>Editar datos del equipo</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="editar_equipo">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="80" value="<?= e($eq['nombre']) ?>"></div>
      <div><label>Abreviatura</label><input type="text" name="abreviatura" maxlength="4" value="<?= e($eq['abreviatura']) ?>"></div>
      <div><label>Escudo (subir para cambiar)</label><input type="file" name="escudo" accept="image/*"></div>
      <div><label>Color</label><input type="color" name="color" value="<?= e(color_valido($eq['color'], '#333333')) ?>"></div>
      <div><label>Delegado</label><input type="text" name="delegado" maxlength="80" value="<?= e($eq['delegado']) ?>"></div>
      <div><label>Teléfono</label><input type="text" name="telefono" maxlength="30" value="<?= e($eq['telefono']) ?>"></div>
      <div><label>Fecha de fundación <small>(opcional)</small></label><input type="date" name="fundado" max="<?= date('Y-m-d') ?>" value="<?= e($eq['fundado'] ?? '') ?>"><div class="ayuda">Sale como "Aniversario de equipos" en la página de la liga.</div></div>
    </div>
    <button class="btn" type="submit">Guardar cambios</button>
  </form>
  <form method="post" style="margin-top:16px" onsubmit="return confirm('¿Eliminar el equipo y todos sus jugadores?')">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar_equipo">
    <button class="btn btn-peligro btn-chico" type="submit">Eliminar equipo</button>
  </form>
</div>
<?php
pie_admin();
