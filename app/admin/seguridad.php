<?php
/* MI CUENTA: contraseña, doble verificación (código en el celular) y Telegram personal */
$u = usuario();

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'mi_clave') {
        if (!password_verify(post('actual'), $u['clave'])) throw new RuntimeException('La contraseña actual no es correcta.');
        if (strlen(post('nueva')) < 8) throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
        if (post('nueva') !== post('nueva2')) throw new RuntimeException('Las dos contraseñas nuevas no coinciden.');
        q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash(post('nueva'), PASSWORD_DEFAULT), $u['id']]);
        bitacora_evento('usuarios', 'Cambió su propia contraseña', null, (int)$u['id']);
        flash('Su contraseña fue cambiada.');
    } elseif ($accion === '2fa_iniciar') {
        $_SESSION['2fa_nuevo'] = totp_nuevo_secreto();
    } elseif ($accion === '2fa_confirmar') {
        $sec = (string)($_SESSION['2fa_nuevo'] ?? '');
        if ($sec === '' || !totp_verificar($sec, post('codigo'))) throw new RuntimeException('El código no coincide. Revise que la hora del celular esté bien y escriba el código que aparece ahora.');
        [$visibles, $guardar] = codigos_respaldo();
        q('UPDATE usuarios SET totp_secreto = ?, totp_activo = 1, totp_respaldo = ? WHERE id = ?', [$sec, $guardar, $u['id']]);
        unset($_SESSION['2fa_nuevo']);
        $_SESSION['2fa_respaldo_ver'] = $visibles;
        bitacora_evento('seguridad', 'Activó la doble verificación', $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
        flash('¡Doble verificación activada! Guarde los códigos de respaldo.');
    } elseif ($accion === '2fa_desactivar') {
        if (doble_verificacion_obligatoria($u)) throw new RuntimeException('La doble verificación es obligatoria para los administradores: no se puede desactivar.');
        if (!password_verify(post('clave'), $u['clave'])) throw new RuntimeException('Contraseña incorrecta.');
        q("UPDATE usuarios SET totp_secreto = '', totp_activo = 0, totp_respaldo = '' WHERE id = ?", [$u['id']]);
        bitacora_evento('seguridad', 'Desactivó la doble verificación', $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
        flash('Doble verificación desactivada.');
    } elseif ($accion === '2fa_nuevos_codigos') {
        [$visibles, $guardar] = codigos_respaldo();
        q('UPDATE usuarios SET totp_respaldo = ? WHERE id = ?', [$guardar, $u['id']]);
        $_SESSION['2fa_respaldo_ver'] = $visibles;
        bitacora_evento('seguridad', 'Generó nuevos códigos de respaldo', $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
        flash('Nuevos códigos de respaldo generados (los anteriores ya no sirven).');
    } elseif ($accion === 'tg_desconectar') {
        q("UPDATE usuarios SET tg_chat = '' WHERE id = ?", [$u['id']]);
        flash('Telegram desconectado.');
    }
    ir('admin.php?p=seguridad');
}

$respaldo = $_SESSION['2fa_respaldo_ver'] ?? null;
unset($_SESSION['2fa_respaldo_ver']);
$nuevo = $_SESSION['2fa_nuevo'] ?? '';
$u = fila('SELECT * FROM usuarios WHERE id = ?', [$u['id']]);
$restantes = count(json_decode($u['totp_respaldo'] ?: '[]', true) ?: []);
cabecera_admin('Mi cuenta');
?>
<h1>👤 Mi cuenta y seguridad</h1>
<p class="suave"><?= e($u['nombre']) ?> · <?= e($u['email']) ?><?= $u['ultimo_ingreso'] ? ' · último ingreso ' . e($u['ultimo_ingreso']) : '' ?></p>

<?php if ($respaldo): ?>
<div class="tarjeta" style="border:3px solid var(--acento)">
  <h2 style="margin-top:0">🔑 Sus códigos de respaldo</h2>
  <p>Si pierde el celular, cada uno de estos códigos sirve <b>una sola vez</b> para ingresar. <b>Anótelos en papel o tómeles foto y guárdelos</b>: no se volverán a mostrar.</p>
  <div class="codigos"><?php foreach ($respaldo as $c): ?><code><?= e($c) ?></code><?php endforeach; ?></div>
  <button class="btn btn-sec btn-chico" onclick="window.print()">🖨 Imprimir</button>
</div>
<?php endif; ?>

<div class="tarjeta">
  <h2 style="margin-top:0">🔐 Doble verificación</h2>
  <?php if ((int)$u['totp_activo'] === 1): ?>
    <p>✅ <b>Activada.</b> Al ingresar, además de la contraseña se pide el código de 6 números de su celular. Le quedan <b><?= $restantes ?></b> códigos de respaldo.</p>
    <div class="acciones">
      <form method="post" class="inline" onsubmit="return confirm('Los códigos de respaldo anteriores dejarán de servir. ¿Continuar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="2fa_nuevos_codigos"><button class="btn btn-sec btn-chico">Generar nuevos códigos de respaldo</button></form>
    </div>
    <?php if (!doble_verificacion_obligatoria($u)): ?>
    <details style="margin-top:12px"><summary>Desactivar la doble verificación</summary>
      <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="2fa_desactivar">
        <p><label>Escriba su contraseña para confirmar</label><input type="password" name="clave" required></p>
        <button class="btn btn-peligro btn-chico">Desactivar</button></form>
    </details>
    <?php endif; ?>
  <?php elseif ($nuevo !== ''): ?>
    <ol class="pasos">
      <li>Instale en su celular la aplicación gratuita <b>Google Authenticator</b> (o Microsoft Authenticator).</li>
      <li>En la aplicación toque <b>+</b> → <b>Escanear código QR</b> y apunte a este código:
        <div id="qr2fa" class="qr-2fa"></div>
        <p class="ayuda">¿No puede escanear? Elija "Ingresar clave de configuración" y escriba: <code style="word-break:break-all"><?= e(trim(chunk_split($nuevo, 4, ' '))) ?></code> (tipo: basada en el tiempo).</p></li>
      <li>Escriba el código de 6 números que muestra la aplicación:
        <form method="post" style="max-width:280px"><?= csrf_campo() ?><input type="hidden" name="accion" value="2fa_confirmar">
          <p><input type="text" name="codigo" required inputmode="numeric" maxlength="7" placeholder="123456" style="font-size:1.4rem;letter-spacing:.2em;text-align:center" autocomplete="one-time-code"></p>
          <button class="btn">Activar</button></form></li>
    </ol>
    <script src="assets/js/qrcode.js"></script>
    <script>
    (function () { var q = qrcode(0, 'M'); q.addData(<?= json_encode(totp_uri($nuevo, $u['email'])) ?>); q.make();
      document.getElementById('qr2fa').innerHTML = q.createSvgTag({ cellSize: 5, margin: 2 }); })();
    </script>
  <?php else: ?>
    <p>Protege su cuenta aunque alguien conozca su contraseña: al ingresar también se pide un código de 6 números que cambia cada 30 segundos en su celular.<?= doble_verificacion_obligatoria($u) ? ' <b>Es obligatoria para los administradores.</b>' : ' Recomendada para administradores.' ?></p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="2fa_iniciar"><button class="btn">Activar doble verificación</button></form>
  <?php endif; ?>
</div>

<?= tg_tarjeta_usuario($u, 'admin.php?p=seguridad') ?>

<div class="tarjeta">
  <h2 style="margin-top:0">🔒 Cambiar mi contraseña</h2>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="mi_clave">
    <div class="campos">
      <div><label>Contraseña actual</label><input type="password" name="actual" required autocomplete="current-password"></div>
      <div><label>Nueva contraseña (mín. 8)</label><input type="password" name="nueva" required minlength="8" autocomplete="new-password"></div>
      <div><label>Repita la nueva</label><input type="password" name="nueva2" required minlength="8" autocomplete="new-password"></div>
    </div>
    <button class="btn btn-sec" type="submit">Cambiar contraseña</button>
  </form>
</div>
<?php
pie_admin();
