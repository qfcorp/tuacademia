<?php
/* Inicio de sesión (con doble verificación opcional: código de 6 números del celular) */
$error = '';
$solo_central = get('solo_central') === '1' && tc_solo_central(); // (Tukán Central) el super entra solo desde el panel central
$hay_usuarios = (int)valor('SELECT COUNT(*) FROM usuarios') > 0;
$paso2 = isset($_SESSION['2fa_uid']) && (time() - (int)($_SESSION['2fa_hora'] ?? 0)) < 300;
if (!$paso2) unset($_SESSION['2fa_uid'], $_SESSION['2fa_hora'], $_SESSION['2fa_fallos']);
if (get('cancelar') === '1') {
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_hora'], $_SESSION['2fa_fallos']);
    ir('admin.php?p=login');
}

function iniciar_sesion(array $u, string $como): never
{
    if ($u['rol'] === 'super' && tc_solo_central()) { // (Tukán Central) TUKAN_SOLO_CENTRAL=1
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_hora'], $_SESSION['2fa_fallos']);
        ir('admin.php?p=login&solo_central=1');
    }
    session_regenerate_id(true);
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_hora'], $_SESSION['2fa_fallos'], $_SESSION['liga_trabajo'], $_SESSION['tukan_central']);
    $_SESSION['uid'] = (int)$u['id'];
    q('UPDATE usuarios SET ultimo_ingreso = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    bitacora_evento('sesion', 'Inició sesión' . $como, $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
    ir('admin.php');
}

// (v10) "Olvidé mi contraseña": código de 6 números por Telegram o por correo
if (get('recuperar') !== '' && $hay_usuarios) {
    $err_r = '';
    if (es_post() && post('accion') === 'recup_enviar') {
        if (demasiados_intentos('recuperar', 5, '-1 hour')) {
            $err_r = 'Demasiados pedidos desde su conexión. Espere una hora o pida ayuda al administrador de su liga.';
        } else {
            anotar_intento('recuperar');
            $email_r = mb_strtolower(post('email'), 'UTF-8');
            $ur = filter_var($email_r, FILTER_VALIDATE_EMAIL) ? fila('SELECT * FROM usuarios WHERE lower(email) = ? AND activo = 1', [$email_r]) : null;
            if ($ur && !es_usuario_demo($ur) && !($ur['rol'] === 'super' && tc_solo_central())) recuperar_enviar_codigo($ur); // (Tukán Central)
            usleep(300000);
            $_SESSION['recup_email'] = $email_r;
            ir('admin.php?p=login&recuperar=2');
        }
    }
    if (es_post() && post('accion') === 'recup_cambiar') {
        if (demasiados_intentos('recuperar_codigo', 10, '-15 minutes')) {
            $err_r = 'Demasiados intentos. Espere 15 minutos.';
        } elseif (post('nueva') !== post('nueva2')) {
            $err_r = 'Las dos contraseñas nuevas no coinciden.';
        } else {
            try {
                recuperar_cambiar_clave(post('email'), post('codigo'), post('nueva'));
                unset($_SESSION['recup_email']);
                flash('¡Listo! Su contraseña fue cambiada. Ingrese con la nueva.');
                ir('admin.php?p=login');
            } catch (RuntimeException $ex) {
                anotar_intento('recuperar_codigo');
                $err_r = $ex->getMessage();
            }
        }
    }
    $paso_r = get('recuperar') === '2' || post('accion') === 'recup_cambiar' ? 2 : 1;
    cabecera_admin('Recuperar contraseña');
    ?>
<div class="login tarjeta">
  <h1>🔑 Recuperar contraseña</h1>
  <?php if ($err_r): ?><div class="aviso aviso-error"><?= e($err_r) ?></div><?php endif; ?>
  <?php if ($paso_r === 1): ?>
    <p>Escriba el correo con el que ingresa. Le enviaremos un <b>código de 6 números</b> por <b>Telegram</b> (si lo conectó en "Mi cuenta") o por <b>correo</b>.</p>
    <form method="post" action="admin.php?p=login&amp;recuperar=1">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="recup_enviar">
      <p><label>Correo</label><input type="email" name="email" required autofocus autocomplete="username" value="<?= e(post('email')) ?>"></p>
      <button class="btn btn-grande">Enviarme el código</button>
    </form>
  <?php else: ?>
    <div class="aviso aviso-info">Si ese correo tiene una cuenta, le enviamos un código de 6 números por Telegram o por correo (revise también la carpeta de spam). Vale <b>15 minutos</b>.</div>
    <form method="post" action="admin.php?p=login&amp;recuperar=2">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="recup_cambiar">
      <p><label>Correo</label><input type="email" name="email" required autocomplete="username" value="<?= e(post('email') !== '' ? post('email') : (string)($_SESSION['recup_email'] ?? '')) ?>"></p>
      <p><label>Código que le llegó</label><input type="text" name="codigo" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" style="font-size:1.4rem;letter-spacing:.2em;text-align:center"></p>
      <p><label>Contraseña nueva (mínimo 8)</label><input type="password" name="nueva" required minlength="8" autocomplete="new-password"></p>
      <p><label>Repita la contraseña nueva</label><input type="password" name="nueva2" required minlength="8" autocomplete="new-password"></p>
      <button class="btn btn-grande">Cambiar contraseña</button>
    </form>
    <p class="ayuda">¿No le llegó nada en 2 minutos? Su cuenta quizás no tiene Telegram conectado. Pida al administrador de su liga (o a <?= e(ajuste('empresa')) ?>) que le ponga una contraseña nueva. <a href="admin.php?p=login&amp;recuperar=1">Pedir otro código</a></p>
  <?php endif; ?>
  <p><a href="admin.php?p=login">← Volver a ingresar</a></p>
</div>
<?php
    pie_admin();
    return;
}

if (es_post() && $hay_usuarios) {
    if (demasiados_intentos('login', 10, '-15 minutes')) {
        $error = 'Demasiados intentos fallidos desde su conexión. Espere 15 minutos e intente de nuevo.';
    } elseif ($paso2) {
        // Paso 2: código del celular o código de respaldo
        $u = fila('SELECT * FROM usuarios WHERE id = ? AND activo = 1', [(int)$_SESSION['2fa_uid']]);
        $codigo = post('codigo');
        if ($u && totp_verificar($u['totp_secreto'], $codigo)) {
            iniciar_sesion($u, ' (con doble verificación)');
        }
        if ($u && strlen(preg_replace('/\W/', '', $codigo)) === 8 && usar_codigo_respaldo($u, $codigo)) {
            bitacora_evento('seguridad', 'Usó un código de respaldo para ingresar', $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
            iniciar_sesion($u, ' (con código de respaldo)');
        }
        anotar_intento('login');
        $_SESSION['2fa_fallos'] = (int)($_SESSION['2fa_fallos'] ?? 0) + 1;
        bitacora_evento('sesion', 'Código de doble verificación INCORRECTO', $u && $u['liga_id'] ? (int)$u['liga_id'] : null, $u ? (int)$u['id'] : null);
        if ($_SESSION['2fa_fallos'] >= 5) {
            unset($_SESSION['2fa_uid'], $_SESSION['2fa_hora'], $_SESSION['2fa_fallos']);
            $paso2 = false;
            $error = 'Demasiados códigos incorrectos. Vuelva a escribir su correo y contraseña.';
        } else {
            $error = 'El código no es correcto. Revise la hora de su celular y use el código que aparece ahora.';
        }
    } else {
        $email = mb_strtolower(post('email'), 'UTF-8');
        $u = fila('SELECT * FROM usuarios WHERE lower(email) = ? AND activo = 1', [$email]);
        if ($u && $u['rol'] === 'super' && tc_solo_central()) {
            // (Tukán Central) TUKAN_SOLO_CENTRAL=1: el superadministrador no entra con clave (ni se revisa la clave)
            anotar_intento('login');
            bitacora_evento('sesion', 'Ingreso local del superadministrador BLOQUEADO: solo entra desde el Panel Tukán Central', null, (int)$u['id']);
            $solo_central = true;
        } elseif ($u && password_verify(post('clave'), $u['clave'])) {
            if ((int)$u['totp_activo'] === 1 && $u['totp_secreto'] !== '') {
                $_SESSION['2fa_uid'] = (int)$u['id'];
                $_SESSION['2fa_hora'] = time();
                $_SESSION['2fa_fallos'] = 0;
                ir('admin.php?p=login');
            }
            iniciar_sesion($u, '');
        }
        if (!$solo_central) {
            usleep(800000); // frena intentos masivos
            anotar_intento('login');
            bitacora_evento('sesion', 'Intento de ingreso FALLIDO con el correo ' . mb_substr($email, 0, 80), $u && $u['liga_id'] ? (int)$u['liga_id'] : null, $u ? (int)$u['id'] : null);
            $error = 'Correo o contraseña incorrectos.';
        }
    }
}
if (usuario()) {
    ir('admin.php');
}
cabecera_admin('Ingresar');
?>
<div class="login tarjeta">
  <?php if (!$hay_usuarios): ?>
    <h1>Ingresar</h1>
    <div class="aviso aviso-info">Todavía no existe ningún usuario. Cree el superadministrador desde la terminal del servidor (vea la guía de instalación).</div>
  <?php elseif ($paso2): ?>
    <h1>🔐 Doble verificación</h1>
    <?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>
    <p>Abra la aplicación de códigos de su celular (Google Authenticator, Microsoft Authenticator…) y escriba el código de 6 números de <b><?= e(marca()) ?></b>.</p>
    <form method="post" action="admin.php?p=login">
      <?= csrf_campo() ?>
      <p><label>Código</label><input type="text" name="codigo" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="123456" style="font-size:1.5rem;letter-spacing:.2em;text-align:center"></p>
      <button class="btn btn-grande" type="submit">Verificar</button>
    </form>
    <p class="ayuda">¿Perdió el celular? Escriba uno de sus <b>códigos de respaldo</b> (formato XXXX-XXXX). Si tampoco los tiene, pida al superadministrador que le quite la doble verificación.</p>
    <p><a href="admin.php?p=login&amp;cancelar=1">← Volver</a></p>
  <?php else: ?>
    <h1>Ingresar</h1>
    <?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($solo_central): // (Tukán Central) ?>
    <div class="aviso aviso-error" id="solo-central">🔒 El <b>superadministrador</b> ya no entra aquí con su clave: entra solo desde el <b>Panel Tukán Central</b>.
      <a href="<?= e(tc_config()['url']) ?>"><b>Ir al Panel Tukán →</b></a><br><small>Los demás usuarios siguen entrando aquí como siempre.</small></div>
    <?php endif; ?>
    <form method="post" action="admin.php?p=login">
      <?= csrf_campo() ?>
      <p><label>Correo</label><input type="email" name="email" required autofocus autocomplete="username" value="<?= e(post('email')) ?>"></p>
      <p><label>Contraseña</label><input type="password" name="clave" required autocomplete="current-password"></p>
      <button class="btn btn-grande" type="submit">Ingresar</button>
    </form>
    <p style="text-align:center;margin:.6rem 0 0"><a href="admin.php?p=login&amp;recuperar=1">¿Olvidó su contraseña?</a></p>
    <p class="ayuda">Aquí ingresan con su correo y contraseña: administradores de <b>ligas</b> y de <b>escuelas deportivas</b>, profesores, árbitros y delegados. Los padres de familia de una escuela ingresan en <a href="familia.php">el portal de la familia</a>.</p>
    <p class="suave" style="text-align:center">¿Su liga o escuela aún no usa <?= e(marca()) ?>? <a href="<?= e(url_empezar()) ?>"><?= prueba_automatica() ? 'Pruébelo gratis' : 'Solicite una demo' ?></a></p>
  <?php endif; ?>
</div>
<?php
pie_admin();
