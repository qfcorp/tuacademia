<?php
/*
 * Usuarios del panel.
 *  - Superadministrador: todos los usuarios de todas las ligas.
 *  - (v7) Administrador de una liga: los usuarios de SU liga, con su perfil (secretario, tesorero, comisión, comunicación, consulta).
 */
$yo = usuario();
$super = es_super();
$liga_propia = $super ? null : requiere_liga();

/** Usuario que se puede administrar desde aquí (el admin de liga solo toca los de su liga). */
$usuario_editable = function (int $id) use ($super, $liga_propia): array {
    $u = $super ? fila('SELECT * FROM usuarios WHERE id = ?', [$id])
                : fila("SELECT * FROM usuarios WHERE id = ? AND liga_id = ? AND rol = 'liga'", [$id, $liga_propia['id']]);
    if (!$u) throw new RuntimeException('Usuario no encontrado.');
    return $u;
};

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'crear') {
        exigir_autorizacion(); // (v24)
        if ($super && post('rol') === 'super') {
            $email = mb_strtolower(post('email'), 'UTF-8');
            if (post('nombre') === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Nombre y correo válido son obligatorios.');
            if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            if (fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email])) throw new RuntimeException('Ese correo ya está registrado.');
            q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id) VALUES (?,?,?, 'super', NULL)", [post('nombre'), $email, password_hash(post('clave'), PASSWORD_DEFAULT)]);
        } else {
            $liga_id = $super ? (int)post('liga_id') : (int)$liga_propia['id'];
            if (!fila('SELECT id FROM ligas WHERE id = ?', [$liga_id])) throw new RuntimeException('Elija la liga del usuario.');
            $nuevo_id = crear_usuario_liga($liga_id, post('nombre'), post('email'), post('clave'), post('perfil', 'admin'));
            // (v30) Asistente: los permisos que eligió el administrador al crearlo
            if (post('perfil') === 'asistente') q('UPDATE usuarios SET permisos = ? WHERE id = ?', [leer_permisos_asistente(fila('SELECT * FROM ligas WHERE id = ?', [$liga_id])), $nuevo_id]);
        }
        registrar_autorizacion_tercero('usuario', $super && post('rol') === 'super' ? null : $liga_id, post('nombre'), 'darle acceso al panel y registrar sus acciones (bitácora)', ['ref_tabla' => 'usuarios']); // (v24)
        flash('Usuario creado. Entréguele su correo y contraseña.' . (post('perfil') === 'asistente' && $super ? ' Ahora elija sus permisos con el botón ⚙ Permisos.' : ''));
    } else {
        $u = $usuario_editable((int)post('id'));
        if ($accion === 'clave') {
            if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash(post('clave'), PASSWORD_DEFAULT), $u['id']]);
            bitacora_evento('usuarios', 'Cambió la contraseña del usuario ' . $u['email'], $u['liga_id'] !== null ? (int)$u['liga_id'] : null, (int)$u['id']);
            flash('Contraseña cambiada.');
        } elseif ($accion === 'perfil') {
            if ((int)$u['id'] === (int)$yo['id']) throw new RuntimeException('No puede cambiar su propio perfil (pídaselo a otro administrador).');
            q('UPDATE usuarios SET perfil = ? WHERE id = ?', [isset(perfiles()[post('perfil')]) ? post('perfil') : 'admin', $u['id']]);
            // (v17) Área del cuerpo técnico (preparador físico, psicólogo, médico…): define qué notas confidenciales ve
            if (post('area') !== '' || post('perfil') === 'staff') q('UPDATE usuarios SET area = ? WHERE id = ?', [array_key_exists(post('area'), areas_staff()) ? post('area') : '', $u['id']]);
            flash('Perfil de ' . $u['nombre'] . ': ' . nombre_perfil(post('perfil')) . '.' . (post('perfil') === 'asistente' ? ' Ahora elija qué puede ver y cambiar con el botón ⚙ Permisos.' : ''));
        } elseif ($accion === 'permisos') {
            // (v30) Permisos a medida del asistente
            if (($u['perfil'] ?? '') !== 'asistente') throw new RuntimeException('Solo los usuarios con perfil Asistente tienen permisos a medida.');
            $lg = fila('SELECT * FROM ligas WHERE id = ?', [(int)$u['liga_id']]);
            q('UPDATE usuarios SET permisos = ? WHERE id = ?', [leer_permisos_asistente($lg), $u['id']]);
            $u2 = fila('SELECT * FROM usuarios WHERE id = ?', [$u['id']]);
            bitacora_evento('usuarios', 'Cambió los permisos del asistente ' . $u['email'] . ': ' . resumen_permisos_asistente($lg, $u2), (int)$u['liga_id'], (int)$u['id']);
            flash('Permisos de ' . $u['nombre'] . ' guardados. ' . resumen_permisos_asistente($lg, $u2));
        } elseif ($accion === 'quitar_2fa') {
            q("UPDATE usuarios SET totp_secreto = '', totp_activo = 0, totp_respaldo = '' WHERE id = ?", [$u['id']]);
            bitacora_evento('seguridad', 'Quitó la doble verificación del usuario ' . $u['email'] . ' (perdió el celular)', $u['liga_id'] !== null ? (int)$u['liga_id'] : null, (int)$u['id']);
            flash('Doble verificación quitada. El usuario puede ingresar solo con su contraseña y volver a activarla.');
        } elseif ($accion === 'activo') {
            if ((int)$u['id'] === (int)$yo['id']) throw new RuntimeException('No puede desactivarse a sí mismo.');
            q('UPDATE usuarios SET activo = 1 - activo WHERE id = ?', [$u['id']]);
            flash('Estado del usuario cambiado.');
        } elseif ($accion === 'eliminar') {
            if ((int)$u['id'] === (int)$yo['id']) throw new RuntimeException('No puede eliminarse a sí mismo.');
            q('DELETE FROM usuarios WHERE id = ?', [$u['id']]);
            flash('Usuario eliminado.');
        }
    }
    ir('admin.php?p=usuarios');
}

if ($super) {
    $usuarios = filas('SELECT u.*, l.nombre AS liga FROM usuarios u LEFT JOIN ligas l ON l.id = u.liga_id ORDER BY u.rol DESC, l.nombre, u.nombre');
    $ligas = filas('SELECT id, nombre FROM ligas ORDER BY nombre');
} else {
    $usuarios = filas("SELECT u.*, NULL AS liga FROM usuarios u WHERE u.liga_id = ? AND u.rol = 'liga' ORDER BY (u.perfil <> 'admin'), u.nombre", [$liga_propia['id']]);
    $ligas = [];
}
$etiqueta_rol = function (array $u): string {
    return match ($u['rol']) {
        'super' => '<span class="etiqueta verde">Superadmin</span>',
        'arbitro' => 'Árbitro',
        'delegado' => 'Delegado de ' . e((string)valor('SELECT nombre FROM equipos WHERE id = ?', [$u['equipo_id']])),
        default => e(nombre_perfil((string)$u['perfil'])),
    };
};
cabecera_admin('Usuarios');
?>
<h1>Usuarios<?= $super ? '' : ' de ' . e($liga_propia['nombre']) ?></h1>
<?php if ($super): ?>
<p class="suave">Cada liga tiene sus propios usuarios. Un usuario de liga solo ve los datos de su liga, y según su <b>perfil</b> solo ve ciertos menús. También puede administrar los usuarios de una liga desde <b>Ligas → Editar</b>.</p>
<?php else: ?>
<p class="suave">Cree un usuario para cada persona <?= $liga_propia && es_escuela($liga_propia) ? 'que trabaja en la ' . (es_academia() ? 'academia' : 'escuela') : 'de la directiva' ?>. Cada <b>perfil</b> ve solo sus menús:</p>
<div class="tabla-scroll"><table>
  <?php foreach ($super ? perfiles() : perfiles_para($liga_propia) as [$pn, $pd]): ?><tr><td><b><?= e($pn) ?></b></td><td><?= e($pd) ?></td></tr><?php endforeach; ?>
</table></div>
<?php if ($liga_propia && es_escuela($liga_propia)): // (arreglos 14-oct) ?><p class="suave">Los <b>profesores</b> también se pueden crear desde Profesores → el profesor → Acceso del profesor. Los <b>padres</b> no necesitan usuario: entran al portal de padres con su cédula.</p>
<?php else: ?><p class="suave">Los <b>árbitros</b> se crean en Árbitros → Acceso y los <b>delegados</b> en Equipos → el equipo → Acceso del delegado.</p><?php endif; ?>
<?php endif; ?>
<div class="tabla-scroll"><table>
  <tr><th>Nombre</th><th>Correo</th><th>Rol / perfil</th><?php if ($super): ?><th>Liga</th><?php endif; ?><th>Estado</th><th>Seguridad</th><th>Acciones</th></tr>
  <?php foreach ($usuarios as $u): ?>
  <tr>
    <td><?= e($u['nombre']) ?><?= (int)$u['id'] === (int)$yo['id'] ? ' <small class="suave">(usted)</small>' : '' ?></td>
    <td><?= e($u['email']) ?></td>
    <td><?php if ($u['rol'] === 'liga' && (int)$u['id'] !== (int)$yo['id']): ?>
      <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="perfil"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <select name="perfil" onchange="this.form.submit()" aria-label="Perfil"><?php foreach ($super ? perfiles() : perfiles_para($liga_propia) as $k => [$pn]): ?><option value="<?= $k ?>" <?= $u['perfil'] === $k ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select>
        <?php if ($u['perfil'] === 'staff'): ?><select name="area" onchange="this.form.submit()" aria-label="Área"><option value="">— área —</option><?php foreach (areas_staff() as $k => [$ic, $an]): ?><option value="<?= $k ?>" <?= ($u['area'] ?? '') === $k ? 'selected' : '' ?>><?= $ic ?> <?= e($an) ?></option><?php endforeach; ?></select><?php endif; ?></form>
      <?php if ($u['perfil'] === 'asistente'): $lg_u = $super ? fila('SELECT * FROM ligas WHERE id = ?', [(int)$u['liga_id']]) : $liga_propia; // (v30) ?>
        <div style="margin-top:6px"><a class="btn btn-sec btn-chico" href="#permisos-<?= (int)$u['id'] ?>" onclick="document.getElementById('permisos-<?= (int)$u['id'] ?>').open = true">⚙ Permisos</a></div>
        <div class="suave" style="font-size:.8rem;max-width:360px;white-space:normal"><?= e(resumen_permisos_asistente($lg_u, $u)) ?></div>
      <?php endif; ?>
      <?php else: ?><?= $etiqueta_rol($u) ?><?php endif; ?></td>
    <?php if ($super): ?><td><?= e($u['liga'] ?? '—') ?></td><?php endif; ?>
    <td><?= $u['activo'] ? 'Activo' : '<span class="etiqueta gris">Inactivo</span>' ?><?= $u['ultimo_ingreso'] ? '<br><small>' . e($u['ultimo_ingreso']) . '</small>' : '' ?></td>
    <td><?= $u['totp_activo'] ? '🔐 Doble verif.' : '—' ?><?= $u['tg_chat'] !== '' ? ' · 📲' : '' ?></td>
    <td class="acciones">
      <form class="inline" method="post" onsubmit="var c=prompt('Nueva contraseña (mínimo 8 caracteres):'); if(!c) return false; this.clave.value=c; return true;">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="clave"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="clave" value="">
        <button class="btn btn-sec btn-chico">Cambiar clave</button>
      </form>
      <?php if ((int)$u['id'] !== (int)$yo['id']): ?>
      <?php if ($u['totp_activo']): ?><form class="inline" method="post" onsubmit="return confirm('¿Quitar la doble verificación de este usuario? (use esto si perdió su celular)')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_2fa"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sec btn-chico">Quitar doble verif.</button></form><?php endif; ?>
      <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="activo"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sec btn-chico"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
      <form class="inline" method="post" onsubmit="return confirm('¿Eliminar este usuario?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>

<?php // (v30) Permisos de cada asistente, a lo ancho de la página
foreach ($usuarios as $u): if (($u['perfil'] ?? '') !== 'asistente' || $u['rol'] !== 'liga') continue;
  $lg_u = $super ? fila('SELECT * FROM ligas WHERE id = ?', [(int)$u['liga_id']]) : $liga_propia; ?>
<details class="tarjeta permisos-asistente" id="permisos-<?= (int)$u['id'] ?>" style="margin-top:14px">
  <summary><strong>⚙ Permisos de <?= e($u['nombre']) ?> (Asistente)</strong> <small class="suave" style="margin-left:8px"><?= e(resumen_permisos_asistente($lg_u, $u)) ?></small></summary>
  <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="permisos"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    <?= html_permisos_asistente($lg_u, $u) ?>
    <button class="btn">Guardar permisos</button>
  </form>
</details>
<?php endforeach; ?>

<div class="tarjeta" style="margin-top:20px">
  <h2>Nuevo usuario</h2>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
    <div class="campos">
      <div><label>Nombre</label><input type="text" name="nombre" required></div>
      <div><label>Correo (será su usuario)</label><input type="email" name="email" required></div>
      <div><label>Contraseña (mín. 8)</label><input type="text" name="clave" required minlength="8" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10)) ?>"></div>
      <?php if ($super): ?>
      <div><label>Tipo</label><select name="rol" onchange="document.getElementById('caja-liga').style.display = this.value === 'super' ? 'none' : ''"><option value="liga">Usuario de una liga</option><option value="super">Superadministrador (ve todo)</option></select></div>
      <?php endif; ?>
      <div id="caja-liga" style="display:contents">
        <?php if ($super): ?><div><label>Liga</label><select name="liga_id"><option value="">— elegir —</option><?php foreach ($ligas as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['nombre']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div><label>Perfil</label><select name="perfil" onchange="var c = document.getElementById('caja-permisos'); if (c) c.hidden = this.value !== 'asistente'"><?php foreach ($super ? perfiles() : perfiles_para($liga_propia) as $k => [$pn, $pd]): ?><option value="<?= $k ?>" <?= !$super && $k === 'secretario' ? 'selected' : '' ?>><?= e($pn) ?> — <?= e($pd) ?></option><?php endforeach; ?></select></div>
      </div>
    </div>
    <?php if (!$super): // (v30) permisos del asistente al crearlo ?>
    <div id="caja-permisos" hidden>
      <h3 style="margin:12px 0 4px">🧑‍💼 ¿Qué puede hacer este asistente?</h3>
      <?= html_permisos_asistente($liga_propia) ?>
    </div>
    <?php endif; ?>
    <?= html_autorizacion('tercero', 'darle acceso al panel y registrar sus acciones (bitácora)') ?>
    <button class="btn" type="submit">Crear usuario</button>
  </form>
</div>
<?php
pie_admin();
