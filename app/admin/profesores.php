<?php
/* (v11) PROFESORES: datos, grupos, alumnos, días que fueron, cuánto se les paga, pagos y acceso */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
$periodo = preg_match('/^\d{4}-\d{2}$/', get('mes')) ? get('mes') : date('Y-m');

$profesor = function (int $id) use ($lid): array {
    return fila('SELECT * FROM esc_profesores WHERE id = ? AND liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Profesor no encontrado.');
};

// (v13) Factura que entregó el profesor (foto o PDF, privada)
if (get('factura') !== '') {
    $pp = fila('SELECT factura FROM esc_pagos_profesor WHERE id = ? AND liga_id = ?', [(int)get('factura'), $lid]);
    servir_factura((string)($pp['factura'] ?? ''));
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = (int)post('id');
        $actual = $id ? $profesor($id) : null;
        if (mb_strlen(post('nombre')) < 3) throw new RuntimeException('Escriba el nombre del profesor.');
        $d = [
            'nombre' => mb_substr(post('nombre'), 0, 80), 'cedula' => mb_substr(post('cedula'), 0, 20), 'telefono' => mb_substr(post('telefono'), 0, 30),
            'email' => mb_substr(mb_strtolower(post('email')), 0, 120), 'especialidad' => mb_substr(post('especialidad'), 0, 80),
            'tipo_pago' => array_key_exists(post('tipo_pago'), tipos_pago_profesor()) ? post('tipo_pago') : 'mensual',
            'valor_pago' => max(0, round((float)str_replace(',', '.', post('valor_pago')), 2)), 'activo' => post('activo') === '1' ? 1 : 0,
            // (v12) Rol de pago: cargo y aporte personal al IESS (9,45% si está afiliado; 0 si factura sus servicios)
            'cargo' => mb_substr(post('cargo') ?: 'Profesor', 0, 60), 'iess_pct' => max(0, min(30, round((float)str_replace(',', '.', post('iess_pct')), 2))),
        ];
        if ($f = subir_imagen('foto', 'jugadores', 600)) { if ($actual) borrar_imagen($actual['foto']); $d['foto'] = $f; }
        if ($actual) {
            q('UPDATE esc_profesores SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d))) . ' WHERE id = :id', $d + ['id' => $id]);
        } else {
            exigir_autorizacion(); // (v24)
            $d['liga_id'] = $lid;
            q('INSERT INTO esc_profesores (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
            $id = (int)db()->lastInsertId();
            registrar_autorizacion_tercero('profesor', $lid, $d['nombre'], 'la gestión de la escuela (clases, asistencia y rol de pago)', ['ref_tabla' => 'esc_profesores', 'ref_id' => $id, 'documento' => $d['cedula']]);
        }
        flash('Profesor guardado.');
        ir('admin.php?p=profesores&ver=' . $id);
    }
    $p = $profesor((int)post('id'));
    if ($accion === 'pagar') {
        // (v12) Rol de pago: honorario + bonos − IESS − anticipos − otros descuentos = neto que se paga
        $mes = preg_match('/^\d{4}-\d{2}$/', post('periodo')) ? post('periodo') : date('Y-m');
        $liq = liquidacion_profesor($p, $mes);
        $num = fn(string $k) => max(0, round((float)str_replace(',', '.', post($k)), 2));
        $rol = calcular_rol($p, $num('base'), $num('bonos'), $num('anticipos'), $num('otros_desc'));
        if ($rol['bruto'] <= 0) throw new RuntimeException('Escriba el valor del honorario o sueldo.');
        if ($rol['neto'] <= 0) throw new RuntimeException('Los descuentos no pueden ser iguales o mayores que los ingresos.');
        $factura = subir_factura('factura'); // (v13) opcional
        q('INSERT INTO esc_pagos_profesor (liga_id, profesor_id, periodo, clases, alumnos, monto, base, bonos, bonos_detalle, iess, anticipos, otros_desc, desc_detalle, fecha, forma_pago, nota, registrado_por, factura, factura_numero, factura_subida)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $lid, $p['id'], $mes, $liq['clases'], $liq['alumnos'], $rol['neto'], $rol['base'], $rol['bonos'], mb_substr(post('bonos_detalle'), 0, 120), $rol['iess'], $rol['anticipos'], $rol['otros'],
            mb_substr(post('desc_detalle'), 0, 120), date('Y-m-d'), array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', mb_substr(post('nota'), 0, 200), $por,
            $factura, limpiar_numero_factura(post('factura_numero')), $factura !== '' ? date('Y-m-d H:i') . ' · ' . $por : '',
        ]);
        $nid = (int)db()->lastInsertId();
        if ($p['usuario_id'] && ($chat = (string)valor('SELECT tg_chat FROM usuarios WHERE id = ?', [$p['usuario_id']])) !== '') {
            tg_encolar($lid, $chat, '💰 <b>Su rol de pago de ' . e(nombre_mes($mes)) . '</b>' . "
Neto a recibir: <b>" . dinero($rol['neto']) . '</b>. Véalo en "Mi rol de pago" del sistema.');
        }
        flash('Rol de pago registrado: neto ' . dinero($rol['neto']) . ' a ' . $p['nombre'] . ' (' . nombre_mes($mes) . '). El profesor lo ve en "Mi rol de pago".');
        ir('admin.php?p=profesores&ver=' . (int)$p['id'] . '&mes=' . $mes . '&comprobante=' . $nid);
    } elseif ($accion === 'factura') {
        $pp = fila('SELECT * FROM esc_pagos_profesor WHERE id = ? AND profesor_id = ? AND anulado = 0', [(int)post('pago_id'), $p['id']]) ?? throw new RuntimeException('Pago no encontrado.');
        adjuntar_factura('esc_pagos_profesor', $pp, 'factura', post('factura_numero'), $por);
        flash('Factura guardada en el rol de pago de ' . nombre_mes($pp['periodo']) . '.');
    } elseif ($accion === 'anular_pago') {
        q('UPDATE esc_pagos_profesor SET anulado = 1 WHERE id = ? AND profesor_id = ?', [(int)post('pago_id'), $p['id']]);
        flash('Pago anulado.');
    } elseif ($accion === 'acceso') {
        $email = mb_strtolower(trim(post('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escriba un correo válido.');
        if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        $u = $p['usuario_id'] ? fila('SELECT * FROM usuarios WHERE id = ?', [$p['usuario_id']]) : null;
        if (fila('SELECT id FROM usuarios WHERE lower(email) = ? AND id <> ?', [$email, $u['id'] ?? 0])) throw new RuntimeException('Ese correo ya tiene una cuenta.');
        if ($u) {
            q('UPDATE usuarios SET email = ?, clave = ?, activo = 1 WHERE id = ?', [$email, password_hash(post('clave'), PASSWORD_DEFAULT), $u['id']]);
        } else {
            q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id, perfil) VALUES (?,?,?, 'liga', ?, 'profesor')", [$p['nombre'], $email, password_hash(post('clave'), PASSWORD_DEFAULT), $lid]);
            q('UPDATE esc_profesores SET usuario_id = ? WHERE id = ?', [(int)db()->lastInsertId(), $p['id']]);
        }
        $_SESSION['acceso_profesor'] = [$email, post('clave')];
        flash('Acceso del profesor listo. Entra en ' . url_base() . 'admin.php y solo ve sus grupos.');
    }
    ir('admin.php?p=profesores&ver=' . (int)$p['id'] . '&mes=' . $periodo);
}

/* (v12) Rol de pago del profesor (antes: comprobante de pago) */
if (get('comprobante') !== '') {
    $pp = fila('SELECT * FROM esc_pagos_profesor WHERE id = ? AND liga_id = ?', [(int)get('comprobante'), $lid]) ?? throw new RuntimeException('Pago no encontrado.');
    doc_rol_pago($pp, $profesor((int)$pp['profesor_id']), $liga, 'admin.php?p=profesores&ver=' . (int)$pp['profesor_id'], 'admin.php?p=profesores&factura=' . (int)$pp['id']);
    exit;
}

$lista = filas('SELECT p.*, (SELECT COUNT(*) FROM esc_grupos g WHERE g.profesor_id = p.id AND g.activo = 1) AS n_grupos,
                (SELECT COUNT(DISTINCT a.id) FROM esc_alumnos a JOIN esc_grupos g ON (g.id = a.grupo_id OR g.id IN (SELECT grupo_id FROM esc_alumno_grupos WHERE alumno_id = a.id)) WHERE g.profesor_id = p.id AND a.estado = \'activo\') AS n_alumnos
                FROM esc_profesores p WHERE p.liga_id = ? ORDER BY p.activo DESC, p.nombre', [$lid]);
$ver = get('ver') !== '' ? fila('SELECT * FROM esc_profesores WHERE id = ? AND liga_id = ?', [(int)get('ver'), $lid]) : null;
$ed = puede('profesores', true);
cabecera_admin('Profesores');
?>
<h1>🧑‍🏫 Profesores</h1>
<form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="profesores"><?php if ($ver): ?><input type="hidden" name="ver" value="<?= (int)$ver['id'] ?>"><?php endif; ?>
  <label>Mes: <input type="month" name="mes" value="<?= e($periodo) ?>" onchange="this.form.submit()"></label></form>
<?php if ($lista): ?>
<div class="tabla-scroll"><table>
  <tr><th>Profesor</th><th class="num">Grupos</th><th class="num">Alumnos</th><th>Pago</th><th class="num">Clases <?= e(substr(nombre_mes($periodo), 0, 3)) ?></th><th class="num">Se le debe</th><th></th></tr>
  <?php foreach ($lista as $p): $liq = liquidacion_profesor($p, $periodo); ?>
  <tr style="<?= $p['activo'] ? '' : 'opacity:.55' ?>"><td><b><?= e($p['nombre']) ?></b><?= $p['especialidad'] ? '<br><small>' . e($p['especialidad']) . '</small>' : '' ?></td><td class="num"><?= (int)$p['n_grupos'] ?></td><td class="num"><?= (int)$p['n_alumnos'] ?></td>
    <td><small><?= e(tipos_pago_profesor()[$p['tipo_pago']]) ?>: <?= dinero($p['valor_pago']) ?></small></td><td class="num"><?= $liq['clases'] ?><?= $liq['faltas'] ? ' <small class="suave">(' . $liq['faltas'] . ' faltas)</small>' : '' ?></td>
    <td class="num pts"><?= dinero(max(0, $liq['saldo'])) ?></td><td><a class="btn btn-sec btn-chico" href="admin.php?p=profesores&amp;ver=<?= (int)$p['id'] ?>&amp;mes=<?= e($periodo) ?>">Ver</a></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if ($ver): $liq = liquidacion_profesor($ver, $periodo);
    $grupos_p = filas('SELECT g.*, (SELECT COUNT(*) FROM esc_alumnos a WHERE (a.grupo_id = g.id OR a.id IN (SELECT alumno_id FROM esc_alumno_grupos WHERE grupo_id = g.id)) AND a.estado = \'activo\') AS n FROM esc_grupos g WHERE g.profesor_id = ? AND g.activo = 1', [$ver['id']]);
    $pagos = filas('SELECT * FROM esc_pagos_profesor WHERE profesor_id = ? ORDER BY id DESC LIMIT 24', [$ver['id']]);
    $ac = $_SESSION['acceso_profesor'] ?? null; unset($_SESSION['acceso_profesor']);
    $acceso = $ver['usuario_id'] ? fila('SELECT * FROM usuarios WHERE id = ?', [$ver['usuario_id']]) : null; ?>
<div class="tarjeta" style="border:2px solid var(--acento);margin-top:16px">
  <div class="fila-titulo"><h2 style="margin:0"><?= $ver['foto'] ? '<img src="' . e($ver['foto']) . '" alt="" class="mini"> ' : '' ?><?= e($ver['nombre']) ?></h2><small class="suave"><?= e($ver['telefono']) ?></small></div>
  <h3>Grupos (<?= count($grupos_p) ?>) · <?= array_sum(array_column($grupos_p, 'n')) ?> alumnos</h3>
  <?php foreach ($grupos_p as $g): ?><p style="margin:.2rem 0">• <b><?= e($g['nombre']) ?></b> — <?= e(texto_categoria_grupo($g)) ?> · <?= e(texto_horario($g)) ?> · <?= (int)$g['n'] ?> alumnos</p><?php endforeach; ?>
  <h3>Días que fue en <?= e(nombre_mes($periodo)) ?>: <?= $liq['clases'] ?> clase(s)<?= $liq['faltas'] ? ' · faltó ' . $liq['faltas'] : '' ?></h3>
  <p><?= $liq['dias'] ? e(implode(' · ', array_map(fn($d) => date('d/m', strtotime($d)), $liq['dias']))) : '<span class="suave">Todavía no hay clases registradas este mes.</span>' ?></p>
  <p class="suave" style="margin-top:0">Se cuenta cada clase en la que se tomó asistencia marcando que el profesor asistió.</p>
  <h3>Pago de <?= e(nombre_mes($periodo)) ?></h3>
  <p><?= e(tipos_pago_profesor()[$ver['tipo_pago']]) ?> de <?= dinero($ver['valor_pago']) ?> → le corresponde <b><?= dinero($liq['monto']) ?></b> · pagado <?= dinero($liq['pagado']) ?> · <b>saldo <?= dinero($liq['saldo']) ?></b></p>
  <?php if ($ed): ?>
  <form method="post" class="rol-form" id="rol-form" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>"><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
    <div class="campos">
      <div><label>Honorario / sueldo del mes</label><input type="number" name="base" min="0" step="0.01" value="<?= e(number_format(max(0, $liq['saldo']), 2, '.', '')) ?>"></div>
      <div><label>Bonos y extras</label><input type="number" name="bonos" min="0" step="0.01" value="0"></div>
      <div><label>Detalle del bono</label><input type="text" name="bonos_detalle" maxlength="120" placeholder="Ej: torneo del sábado"></div>
      <div><label>Aporte IESS</label><input type="text" id="rol-iess" readonly value="<?= e(rtrim(rtrim(number_format((float)$ver['iess_pct'], 2, '.', ''), '0'), '.')) ?>% " data-pct="<?= (float)$ver['iess_pct'] ?>"><div class="ayuda">Se cambia en los datos del profesor.</div></div>
      <div><label>Anticipos</label><input type="number" name="anticipos" min="0" step="0.01" value="0"></div>
      <div><label>Otros descuentos</label><input type="number" name="otros_desc" min="0" step="0.01" value="0"></div>
      <div><label>Detalle de los descuentos</label><input type="text" name="desc_detalle" maxlength="120" placeholder="Ej: préstamo, multa"></div>
      <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Nota</label><input type="text" name="nota" maxlength="200"></div>
      <div><label>🧾 Factura del profesor (opcional)</label><input type="file" name="factura" accept="image/*,application/pdf"><div class="ayuda">Foto o PDF, si le entregó factura. También se puede subir después.</div></div>
      <div><label>N° de factura (opcional)</label><input type="text" name="factura_numero" maxlength="40" placeholder="001-001-000000123"></div>
    </div>
    <p style="font-size:1.15rem">Neto a recibir: <b id="rol-neto">—</b></p>
    <button class="btn" onclick="return confirm('¿Registrar el pago y el rol de pago?')">💵 Pagar e imprimir rol de pago</button></form>
  <script>
  (function () {
    var f = document.getElementById('rol-form'), pct = parseFloat(document.getElementById('rol-iess').dataset.pct) || 0;
    function n(x) { return parseFloat(f.elements[x].value) || 0; }
    function calc() { var bruto = n('base') + n('bonos'), iess = Math.round(bruto * pct) / 100, neto = bruto - iess - n('anticipos') - n('otros_desc');
      document.getElementById('rol-iess').value = pct + '% = $' + iess.toFixed(2); document.getElementById('rol-neto').textContent = '$' + neto.toFixed(2); }
    f.addEventListener('input', calc); calc();
  })();
  </script>
  <?php endif; ?>
  <?php if ($pagos): ?><div class="tabla-scroll"><table style="margin-top:10px"><tr><th>Mes</th><th>Fecha</th><th class="num">Neto pagado</th><th>Factura</th><th></th></tr>
    <?php foreach ($pagos as $pp): ?><tr style="<?= $pp['anulado'] ? 'opacity:.5;text-decoration:line-through' : '' ?>"><td><?= e(nombre_mes($pp['periodo'])) ?></td><td><?= e($pp['fecha']) ?></td><td class="num"><?= dinero($pp['monto']) ?></td>
      <td><?= html_factura($pp, 'admin.php?p=profesores&factura=' . (int)$pp['id'], '<input type="hidden" name="id" value="' . (int)$ver['id'] . '"><input type="hidden" name="pago_id" value="' . (int)$pp['id'] . '">', $ed) ?></td>
      <td class="acciones"><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=profesores&amp;comprobante=<?= (int)$pp['id'] ?>">🖨 Rol de pago</a><?php if (!$pp['anulado'] && $ed): ?><form class="inline" method="post" onsubmit="return confirm('¿Anular?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular_pago"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>"><input type="hidden" name="pago_id" value="<?= (int)$pp['id'] ?>"><button class="btn btn-sec btn-chico">Anular</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

  <h3>Acceso del profesor</h3>
  <p class="suave" style="margin-top:0">Con su usuario toma la asistencia, anota el plan de clase y registra medidas, tests y evaluaciones <b>solo de sus grupos</b>. No ve los pagos de los alumnos; sí ve <b>su propio rol de pago</b> (menú "💰 Mi rol de pago").</p>
  <?php if ($ac): $msg = 'Hola ' . $ver['nombre'] . ', este es su acceso a ' . $liga['nombre'] . ":\n" . url_base() . "admin.php\nUsuario: " . $ac[0] . "\nContraseña: " . $ac[1]; ?>
    <div class="aviso aviso-info"><pre class="mensaje"><?= e($msg) ?></pre><a class="btn btn-chico" target="_blank" href="https://wa.me/<?= e(telefono_wa($ver['telefono']) ?: '') ?>?text=<?= rawurlencode($msg) ?>">📲 Enviar por WhatsApp</a></div>
  <?php endif; ?>
  <?php if ($ed): ?>
  <form method="post" class="campos"><?= csrf_campo() ?><input type="hidden" name="accion" value="acceso"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
    <div><label>Correo (usuario)</label><input type="email" name="email" required value="<?= e($acceso['email'] ?? $ver['email']) ?>"></div>
    <div><label>Contraseña (mín. 8)</label><input type="text" name="clave" required minlength="8" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10)) ?>"></div>
    <div><button class="btn btn-sec" style="margin-top:24px"><?= $acceso ? 'Cambiar acceso' : 'Crear acceso' ?></button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($ed): $e = $ver ?? ['id' => 0, 'nombre' => '', 'cedula' => '', 'telefono' => '', 'email' => '', 'especialidad' => '', 'tipo_pago' => 'mensual', 'valor_pago' => 0, 'activo' => 1, 'cargo' => 'Profesor', 'iess_pct' => 0]; ?>
<details class="tarjeta" style="margin-top:16px" <?= $lista ? '' : 'open' ?>><summary><b><?= $ver ? 'Editar datos de ' . e($ver['nombre']) : '+ Nuevo profesor' ?></b></summary>
  <form method="post" enctype="multipart/form-data" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="80" value="<?= e($e['nombre']) ?>"></div>
      <div><label>Cédula</label><input type="text" name="cedula" maxlength="20" value="<?= e($e['cedula']) ?>"></div>
      <div><label>Celular</label><input type="tel" name="telefono" maxlength="30" value="<?= e($e['telefono']) ?>"></div>
      <div><label>Correo</label><input type="email" name="email" value="<?= e($e['email']) ?>"></div>
      <div><label>Especialidad</label><input type="text" name="especialidad" maxlength="80" value="<?= e($e['especialidad']) ?>" placeholder="Ej: Preparador físico, arqueros"></div>
      <div><label>Foto</label><input type="file" name="foto" accept="image/*"></div>
      <div><label>Cómo se le paga</label><select name="tipo_pago"><?php foreach (tipos_pago_profesor() as $k => $v): ?><option value="<?= $k ?>" <?= $e['tipo_pago'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Valor ($)</label><input type="number" name="valor_pago" min="0" step="0.01" value="<?= e(number_format((float)$e['valor_pago'], 2, '.', '')) ?>"><div class="ayuda">Del mes, de cada clase o de cada alumno, según lo elegido.</div></div>
      <div><label>Cargo (sale en el rol de pago)</label><input type="text" name="cargo" maxlength="60" value="<?= e($e['cargo'] ?? 'Profesor') ?>"></div>
      <div><label>Aporte personal IESS (%)</label><input type="number" name="iess_pct" min="0" max="30" step="0.01" value="<?= e(number_format((float)($e['iess_pct'] ?? 0), 2, '.', '')) ?>"><div class="ayuda">9,45 si está afiliado por la escuela · 0 si factura sus servicios.</div></div>
    </div>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= $e['activo'] ? 'checked' : '' ?>> Activo</label>
    <?php if (!(int)$e['id']): ?><?= html_autorizacion('tercero', 'la gestión de la escuela (clases, asistencia y rol de pago)') ?><?php endif; ?>
    <button class="btn" style="margin-top:8px">Guardar profesor</button>
  </form>
</details>
<?php endif;
pie_admin();
