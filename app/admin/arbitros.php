<?php
/* Árbitros de la liga: catálogo, acceso a su propio sistema de informes y estadísticas */
$liga = requiere_liga();
$lid = (int)$liga['id'];

function arbitro_de_liga(int $id, int $lid): array
{
    $a = fila('SELECT * FROM arbitros WHERE id = ? AND liga_id = ?', [$id, $lid]);
    if (!$a) throw new RuntimeException('Árbitro no encontrado.');
    return $a;
}

/** Crea o vincula la cuenta de acceso del árbitro. */
function dar_acceso_arbitro(array $a, string $email, string $clave, int $lid): string
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escriba un correo válido para el acceso del árbitro.');
    $u = fila('SELECT * FROM usuarios WHERE lower(email) = ?', [$email]);
    if ($u) {
        if ($u['rol'] !== 'arbitro') throw new RuntimeException('Ese correo ya pertenece a un administrador. Use otro correo para el árbitro.');
        q('UPDATE arbitros SET usuario_id = ? WHERE id = ?', [$u['id'], $a['id']]);
        $otras = (int)valor('SELECT COUNT(*) FROM arbitros WHERE usuario_id = ? AND liga_id <> ?', [$u['id'], $lid]);
        if ($clave !== '' && $otras === 0) {
            if (strlen($clave) < 6) throw new RuntimeException('La contraseña debe tener al menos 6 caracteres.');
            q('UPDATE usuarios SET clave = ?, activo = 1 WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
            bitacora_evento('usuarios', 'Cambió la contraseña del árbitro ' . $a['nombre'], $lid, (int)$u['id']);
            return 'Acceso actualizado.';
        }
        return $otras > 0 ? 'Ese árbitro ya tenía cuenta (también pita en otra liga). Entra con su misma contraseña de siempre.' : 'Acceso vinculado.';
    }
    if (strlen($clave) < 6) throw new RuntimeException('Escriba una contraseña de al menos 6 caracteres para el árbitro.');
    q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id) VALUES (?,?,?, 'arbitro', ?)", [$a['nombre'], $email, password_hash($clave, PASSWORD_DEFAULT), $lid]);
    q('UPDATE arbitros SET usuario_id = ? WHERE id = ?', [(int)db()->lastInsertId(), $a['id']]);
    return 'Acceso creado. Entréguele al árbitro su correo y contraseña.';
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'crear' || $accion === 'editar') {
        $nombre = post('nombre');
        if ($nombre === '') throw new RuntimeException('Escriba el nombre del árbitro.');
        $cat = in_array(post('categoria'), ['', 'Central', 'Asistente', 'Central y asistente'], true) ? post('categoria') : '';
        $datos = [mb_substr($nombre, 0, 80), mb_substr(post('cedula'), 0, 20), mb_substr(post('telefono'), 0, 30), $cat];
        if ($accion === 'crear') {
            exigir_autorizacion(); // (v24)
            q('INSERT INTO arbitros (liga_id, nombre, cedula, telefono, categoria) VALUES (?,?,?,?,?)', array_merge([$lid], $datos));
            $a = arbitro_de_liga((int)db()->lastInsertId(), $lid);
            registrar_autorizacion_tercero('arbitro', $lid, $a['nombre'], 'la designación de partidos, informes y pago de honorarios', ['ref_tabla' => 'arbitros', 'ref_id' => (int)$a['id'], 'documento' => $a['cedula']]);
            $msg = 'Árbitro registrado.';
            if (post('email') !== '') $msg .= ' ' . dar_acceso_arbitro($a, post('email'), post('clave'), $lid);
            flash($msg);
        } else {
            $a = arbitro_de_liga((int)post('id'), $lid);
            q('UPDATE arbitros SET nombre = ?, cedula = ?, telefono = ?, categoria = ?, activo = ? WHERE id = ?', array_merge($datos, [post('activo') === '1' ? 1 : 0, $a['id']]));
            if ($a['usuario_id']) q('UPDATE usuarios SET nombre = ? WHERE id = ? AND rol = ?', [$datos[0], $a['usuario_id'], 'arbitro']);
            flash('Árbitro actualizado.');
        }
    } elseif ($accion === 'acceso') {
        $a = arbitro_de_liga((int)post('id'), $lid);
        flash(dar_acceso_arbitro($a, post('email'), post('clave'), $lid));
    } elseif ($accion === 'quitar_acceso') {
        $a = arbitro_de_liga((int)post('id'), $lid);
        q('UPDATE arbitros SET usuario_id = NULL WHERE id = ?', [$a['id']]);
        if ($a['usuario_id'] && (int)valor('SELECT COUNT(*) FROM arbitros WHERE usuario_id = ?', [$a['usuario_id']]) === 0) {
            q("DELETE FROM usuarios WHERE id = ? AND rol = 'arbitro'", [$a['usuario_id']]);
        }
        flash('Se quitó el acceso del árbitro.');
    } elseif ($accion === 'eliminar') {
        $a = arbitro_de_liga((int)post('id'), $lid);
        $n = (int)valor('SELECT COUNT(*) FROM partidos WHERE arbitro_id = ? OR asistente1_id = ? OR asistente2_id = ? OR cuarto_id = ?', [$a['id'], $a['id'], $a['id'], $a['id']]);
        if ($n > 0) throw new RuntimeException('Este árbitro tiene ' . $n . ' partidos asignados. Desactívelo en vez de eliminarlo (así se conserva el historial).');
        q('DELETE FROM arbitros WHERE id = ?', [$a['id']]);
        if ($a['usuario_id'] && (int)valor('SELECT COUNT(*) FROM arbitros WHERE usuario_id = ?', [$a['usuario_id']]) === 0) {
            q("DELETE FROM usuarios WHERE id = ? AND rol = 'arbitro'", [$a['usuario_id']]);
        }
        flash('Árbitro eliminado.');
    }
    ir('admin.php?p=arbitros');
}

$arbitros = filas("SELECT a.*, u.email,
        (SELECT COUNT(*) FROM partidos p WHERE p.arbitro_id = a.id AND p.estado IN ('jugado','wo')) AS dirigidos,
        (SELECT COUNT(*) FROM partidos p WHERE (p.asistente1_id = a.id OR p.asistente2_id = a.id OR p.cuarto_id = a.id) AND p.estado IN ('jugado','wo')) AS asistidos,
        (SELECT ROUND(AVG(c.nota), 1) FROM calificaciones c WHERE c.arbitro_id = a.id) AS promedio,
        (SELECT COUNT(*) FROM calificaciones c WHERE c.arbitro_id = a.id) AS n_calif,
        (SELECT COUNT(*) FROM partidos p WHERE p.arbitro_id = a.id AND p.estado IN ('jugado','en_vivo')
            AND NOT EXISTS (SELECT 1 FROM informes i WHERE i.partido_id = p.id AND i.estado = 'enviado')) AS pendientes,
        (SELECT COUNT(*) FROM informe_eventos ie JOIN partidos p ON p.id = ie.partido_id WHERE p.arbitro_id = a.id AND ie.tipo = 'amarilla') AS amarillas,
        (SELECT COUNT(*) FROM informe_eventos ie JOIN partidos p ON p.id = ie.partido_id WHERE p.arbitro_id = a.id AND ie.tipo = 'roja') AS rojas
    FROM arbitros a LEFT JOIN usuarios u ON u.id = a.usuario_id WHERE a.liga_id = ? ORDER BY a.activo DESC, a.nombre", [$lid]);
$editar = get('editar') !== '' ? arbitro_de_liga((int)get('editar'), $lid) : null;
$cats = ['', 'Central', 'Asistente', 'Central y asistente'];

cabecera_admin('Árbitros');
?>
<h1>Árbitros</h1>
<p class="suave">Registre a los árbitros de la liga. Si les da <b>acceso</b> (correo y contraseña), cada árbitro entra a <b><?= e(url_base()) ?>arbitro.php</b> desde su celular y llena el informe de los partidos que pitó.</p>

<?php if ($editar): ?>
<div class="tarjeta">
  <h2>Editar árbitro</h2>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="80" value="<?= e($editar['nombre']) ?>"></div>
      <div><label>Cédula</label><input type="text" name="cedula" maxlength="20" value="<?= e($editar['cedula']) ?>"></div>
      <div><label>Teléfono</label><input type="text" name="telefono" maxlength="30" value="<?= e($editar['telefono']) ?>"></div>
      <div><label>Categoría</label><select name="categoria"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>" <?= $editar['categoria'] === $c ? 'selected' : '' ?>><?= $c === '' ? '—' : e($c) ?></option><?php endforeach; ?></select></div>
      <div><label class="check"><input type="checkbox" name="activo" value="1" <?= $editar['activo'] ? 'checked' : '' ?>> Activo</label></div>
    </div>
    <button class="btn" type="submit">Guardar</button> <a class="btn btn-sec" href="admin.php?p=arbitros">Cancelar</a>
  </form>
  <h3>Acceso al sistema de informes</h3>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="acceso"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
    <div class="campos">
      <div><label>Correo (usuario)</label><input type="email" name="email" required value="<?= e(valor('SELECT email FROM usuarios WHERE id = ?', [$editar['usuario_id'] ?? 0]) ?: '') ?>"></div>
      <div><label><?= $editar['usuario_id'] ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña (mín. 6)' ?></label><input type="text" name="clave" minlength="6"></div>
    </div>
    <button class="btn btn-sec" type="submit"><?= $editar['usuario_id'] ? 'Actualizar acceso' : 'Dar acceso' ?></button>
  </form>
  <?php if ($editar['usuario_id']): ?>
  <form method="post" style="margin-top:10px" onsubmit="return confirm('¿Quitar el acceso de este árbitro?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_acceso"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><button class="btn btn-peligro btn-chico">Quitar acceso</button></form>
  <?php endif; ?>
  <form method="post" style="margin-top:10px" onsubmit="return confirm('¿Eliminar este árbitro?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar árbitro</button></form>
</div>
<?php endif; ?>

<?php if ($arbitros): ?>
<div class="tabla-scroll"><table>
  <tr><th>Árbitro</th><th>Categoría</th><th>Teléfono</th><th>Acceso</th><th class="num">Partidos dirigidos</th><th class="num">Como asistente / 4º</th><th class="num">Calificación promedio</th><th class="num">Informes pendientes</th><th class="num"><span class="tarjeta-am"></span></th><th class="num"><span class="tarjeta-ro"></span></th><th></th></tr>
  <?php foreach ($arbitros as $a): ?>
  <tr style="<?= $a['activo'] ? '' : 'opacity:.5' ?>">
    <td><strong><?= e($a['nombre']) ?></strong><?= $a['activo'] ? '' : ' <span class="etiqueta gris">inactivo</span>' ?></td>
    <td><?= e($a['categoria'] ?: '—') ?></td>
    <td><?= e($a['telefono']) ?></td>
    <td><?= $a['email'] ? e($a['email']) : '<span class="suave">sin acceso</span>' ?></td>
    <td class="num"><?= (int)$a['dirigidos'] ?></td>
    <td class="num"><?= (int)$a['asistidos'] ?></td>
    <td class="num"><?= $a['n_calif'] ? '<b>' . e($a['promedio']) . '</b>/10 <small>(' . (int)$a['n_calif'] . ')</small>' : '—' ?></td>
    <td class="num"><?= (int)$a['pendientes'] ? '<span class="etiqueta roja">' . (int)$a['pendientes'] . '</span>' : '0' ?></td>
    <td class="num"><?= (int)$a['amarillas'] ?></td>
    <td class="num"><?= (int)$a['rojas'] ?></td>
    <td><a class="btn btn-sec btn-chico" href="admin.php?p=arbitros&amp;editar=<?= (int)$a['id'] ?>">Editar / acceso</a></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<p class="suave" style="font-size:.85rem">Tarjetas = las que el árbitro reportó en sus informes. Calificación = promedio de las notas de la directiva/comisión (uso interno, el árbitro no la ve).</p>
<?php else: ?>
<div class="tarjeta">Aún no hay árbitros registrados.</div>
<?php endif; ?>

<div class="tarjeta" style="margin-top:20px">
  <h2>Nuevo árbitro</h2>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="80"></div>
      <div><label>Cédula</label><input type="text" name="cedula" maxlength="20"></div>
      <div><label>Teléfono</label><input type="text" name="telefono" maxlength="30"></div>
      <div><label>Categoría</label><select name="categoria"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?= $c === '' ? '—' : e($c) ?></option><?php endforeach; ?></select></div>
      <div><label>Correo para su acceso</label><input type="email" name="email"><div class="ayuda">Opcional. Con esto el árbitro podrá llenar sus informes.</div></div>
      <div><label>Contraseña (mín. 6)</label><input type="text" name="clave" minlength="6"></div>
    </div>
    <?= html_autorizacion('tercero', 'la designación de partidos, informes y pago de honorarios') ?>
    <button class="btn" type="submit">Registrar árbitro</button>
  </form>
</div>
<?php
pie_admin();
