<?php
/* (v38) 📱 GOOGLE PLAY — superadministrador: precios de la app de Google Play, cuenta de servicio, creación automática
 * de los productos (suscripciones), compras recibidas, huellas para la app y el APK directo. */
if (!es_super()) ir('admin.php');
$log = [];

if (es_post()) {
    $acc = post('accion');
    if ($acc === 'ajustes') {
        guardar_ajuste('play_activo', isset($_POST['play_activo']) ? '1' : '0');
        $f = (float)str_replace(',', '.', post('play_factor', '20'));
        if ($f < 0 || $f > 100) throw new RuntimeException('El aumento debe estar entre 0 y 100 %.');
        guardar_ajuste('play_factor', (string)$f);
        $pq = trim(post('play_paquete'));
        if ($pq !== '' && !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $pq)) throw new RuntimeException('El nombre del paquete no es válido (ejemplo: com.tukan.tuacademia).');
        guardar_ajuste('play_paquete', $pq !== '' ? $pq : PLAY_PAQUETE);
        guardar_ajuste('play_idioma', trim(post('play_idioma')) !== '' ? trim(post('play_idioma')) : 'es-419');
        guardar_ajuste('play_huellas', mb_substr(trim(post('play_huellas')), 0, 1000));
        flash('Ajustes de Google Play guardados.');
        ir('admin.php?p=google_play');
    }
    if ($acc === 'cuenta') {
        $json = '';
        if (!empty($_FILES['archivo']['tmp_name']) && is_uploaded_file($_FILES['archivo']['tmp_name'])) {
            if ((int)$_FILES['archivo']['size'] > 20000) throw new RuntimeException('El archivo es muy grande para ser una clave de cuenta de servicio.');
            $json = (string)file_get_contents($_FILES['archivo']['tmp_name']);
        } elseif (trim(post('json')) !== '') {
            $json = trim(post('json'));
        }
        if ($json === '') throw new RuntimeException('Elija el archivo .json de la cuenta de servicio (o pegue su contenido).');
        $email = play_guardar_cuenta($json);
        bitacora_evento('ajustes', 'Cuenta de servicio de Google Play cargada: ' . $email);
        flash('Cuenta de servicio guardada: ' . $email . '. Ahora toque «Probar la conexión».');
        ir('admin.php?p=google_play');
    }
    if ($acc === 'quitar_cuenta') {
        @unlink(play_archivo_cuenta());
        @unlink(RAIZ . '/data/play_token.json');
        bitacora_evento('ajustes', 'Cuenta de servicio de Google Play quitada');
        flash('Cuenta de servicio quitada.');
        ir('admin.php?p=google_play');
    }
    if ($acc === 'probar') {
        [$cod, $r] = play_http('GET', play_url_api('/subscriptions?pageSize=1'));
        if ($cod === 200) flash('✅ Conexión correcta con Google Play (app ' . play_paquete() . '). Ya puede crear los productos.');
        else flash('Google respondió ' . $cod . ': ' . play_error_texto($r) . ' — revise los pasos 3 y 4 de la guía de abajo.', 'error');
        ir('admin.php?p=google_play');
    }
    if ($acc === 'crear') {
        $log = play_crear_productos();
    }
    if ($acc === 'revisar') {
        $r = play_revisar(true);
        flash('Compras revisadas en Google: ' . $r['revisadas'] . ($r['errores'] ? ' · con error: ' . $r['errores'] : '') . '.', $r['errores'] ? 'error' : 'ok');
        ir('admin.php?p=google_play#compras');
    }
}

$cuenta = play_cuenta();
$productos = play_productos();
$compras = filas('SELECT c.*, l.nombre AS org FROM play_compras c JOIN ligas l ON l.id = c.liga_id ORDER BY c.id DESC LIMIT 200');
$tot = fila("SELECT COUNT(*) AS n, COALESCE(SUM(monto), 0) AS m FROM pagos_suscripcion WHERE forma_pago = 'google_play'");
cabecera_admin('Google Play');
?>
<h1>📱 Apps Android y Google Play</h1>
<?php if (!play_permitido()): ?>
<div class="aviso aviso-info">⏸ <b>La app de Google Play está APAGADA en este servidor</b> (todavía no se publica). Todo funciona como en la web: nadie ve precios de Google ni botones de Google Play.
  Se enciende cuando se decida, escribiendo <code>'google_play' => true</code> en <code>app/config.php</code>. Esta página sirve para dejar todo preparado.</div>
<?php endif; ?>
<?php if (TV_HUELLA_FIRMA === ''): ?>
<div class="aviso aviso-error">🔑 TuAcademia todavía no tiene su <b>llave de firma de Android</b>. Hay que generar una nueva (no se usa la de TuVocalía) y poner su huella SHA-256 en <code>app/config.php</code> (<code>'huella_firma' => '…'</code>). Los pasos están en BLOC_DE_NOTAS.md → «Apps Android».</div>
<?php endif; ?>
<p class="suave">Hay tres formas de usar <?= e(marca()) ?> en el celular: el <b>navegador</b> (como siempre), el <b>APK</b> que se baja de <a href="descargar.php" target="_blank">descargar.php</a> y la <b>app de Google Play</b>. En las dos primeras se paga como siempre, con los precios normales. En la de Google Play el plan solo se puede pagar con Google, con los precios de abajo (Google se queda con su comisión).</p>

<?php if ($log): ?>
<div class="tarjeta"><h2 style="margin-top:0">Resultado en Google Play</h2><ul style="margin:0"><?php foreach ($log as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="rejilla-2">
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="ajustes">
    <h2 style="margin-top:0">⚙️ Ajustes</h2>
    <label class="check"><input type="checkbox" name="play_activo" value="1" <?= play_activo() ? 'checked' : '' ?>> <b>Cobrar dentro de la app de Google Play</b> (márquelo cuando los productos ya estén creados y activos)</label>
    <label>Aumento sobre el precio de la web (%)</label><input name="play_factor" value="<?= e((string)play_factor()) ?>" inputmode="decimal">
    <p class="suave" style="margin:.2rem 0 .6rem">Se redondea hacia arriba a 0,50. Con 20 %: $10 → $12 y $15 → $18. Google cobra hoy el 15 % de las suscripciones en Ecuador.</p>
    <label>Paquete de la app en Google Play</label><input name="play_paquete" value="<?= e(play_paquete()) ?>">
    <label>Idioma de los productos en Google Play</label><input name="play_idioma" value="<?= e(play_idioma()) ?>">
    <p class="suave" style="margin:.2rem 0 .6rem">Debe ser el idioma principal de la ficha de la app (Español Latinoamérica = <b>es-419</b>).</p>
    <label>Huella SHA-256 de Google (firma de apps)</label>
    <textarea name="play_huellas" rows="3" placeholder="AB:CD:12:..."><?= e((string)ajuste('play_huellas', '')) ?></textarea>
    <p class="suave" style="margin:.2rem 0 .6rem">Play Console → su app → <b>Integridad de la app</b> → <b>Firma de apps</b> → copie el <b>SHA-256</b> del «Certificado de la clave de firma de apps». Sin esto, la app de Google Play muestra una barra de direcciones arriba.</p>
    <button class="btn">Guardar</button>
  </form>

  <div class="tarjeta">
    <h2 style="margin-top:0">🔑 Cuenta de servicio de Google</h2>
    <?php if ($cuenta): ?>
      <p>✅ Cargada: <b><?= e($cuenta['client_email']) ?></b></p>
      <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="probar"><button class="btn">🔌 Probar la conexión</button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('¿Crear en Google Play los productos que faltan?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear"><button class="btn">➕ Crear los productos en Google Play</button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('¿Quitar la cuenta de servicio?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_cuenta"><button class="btn btn-sec btn-chico">Quitar</button></form>
    <?php else: ?>
      <p class="aviso aviso-info">Todavía no está cargada. Sin ella no se pueden verificar las compras ni crear los productos (vea la guía de abajo).</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cuenta">
      <label>Archivo .json de la cuenta de servicio</label><input type="file" name="archivo" accept=".json,application/json">
      <button class="btn btn-sec" style="margin-top:6px"><?= $cuenta ? 'Cambiar' : 'Subir' ?></button>
    </form>
    <h3>📦 APK directo</h3>
    <p><?= apk_disponible() ? '✅ Disponible: <a href="descargar.php?apk=1">descargar.php?apk=1</a> (' . round(filesize(apk_archivo()) / 1048576, 1) . ' MB)' : '➖ Todavía no está en el servidor (public/descargas/tuacademia.apk).' ?></p>
    <p class="suave" style="margin:0">Huella de nuestra firma: <code style="font-size:.75rem;word-break:break-all"><?= TV_HUELLA_FIRMA !== '' ? e(TV_HUELLA_FIRMA) : '— (falta generar la llave)' ?></code></p>
    <p class="suave">Comprobar: <a href="/.well-known/assetlinks.json" target="_blank">/.well-known/assetlinks.json</a></p>
    <p style="margin:0"><b>Cobrado por Google Play:</b> <?= (int)$tot['n'] ?> pago(s) · <?= dinero($tot['m']) ?> (antes de la comisión de Google)</p>
  </div>
</div>

<h2>🛒 Productos para Google Play (<?= count($productos) ?>)</h2>
<p class="suave">Se crean solos con el botón «Crear los productos en Google Play». Si prefiere hacerlo a mano en Play Console (Monetizar → Suscripciones), use exactamente estos <b>ID</b> y precios.</p>
<div class="tabla-scroll"><table><tr><th>ID del producto</th><th>Qué es</th><th>Cada</th><th class="num">Web</th><th class="num">Google Play</th></tr>
<?php foreach ($productos as $p): ?><tr><td><code><?= e($p['id']) ?></code></td><td><?= e($p['titulo']) ?></td><td><?= e(['P1M' => '1 mes', 'P3M' => '3 meses', 'P6M' => '6 meses', 'P1Y' => '1 año'][$p['periodo']] ?? $p['periodo']) ?></td><td class="num"><?= dinero($p['precio_web']) ?></td><td class="num"><b><?= dinero($p['precio']) ?></b></td></tr><?php endforeach; ?>
</table></div>

<h2 id="compras">💳 Compras en Google Play</h2>
<form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="revisar"><button class="btn btn-sec btn-chico" <?= $cuenta ? '' : 'disabled' ?>>🔄 Revisar todas ahora en Google</button> <span class="suave">(se revisan solas cada 6 horas)</span></form>
<?php if ($compras): ?>
<div class="tabla-scroll"><table><tr><th>N°</th><th>Cuenta</th><th>Producto</th><th>Estado en Google</th><th>Vence</th><th class="num">Valor</th><th>Revisado</th></tr>
<?php foreach ($compras as $c): ?><tr><td><?= (int)$c['id'] ?><?= (int)$c['prueba'] ? ' <small>(prueba)</small>' : '' ?></td><td><?= e($c['org']) ?></td><td><code><?= e($c['producto']) ?></code></td><td><?= e(play_nombre_estado($c['estado'])) ?><?= (int)$c['renueva'] ? ' · se renueva' : '' ?><?= $c['error'] !== '' ? '<br><small class="error">' . e($c['error']) . '</small>' : '' ?></td><td><?= e($c['vence']) ?></td><td class="num"><?= dinero($c['monto']) ?></td><td><small><?= e($c['revisado']) ?></small></td></tr><?php endforeach; ?>
</table></div>
<?php else: ?><p class="suave">Todavía no hay compras.</p><?php endif; ?>

<div class="tarjeta" style="margin-top:16px">
  <h2 style="margin-top:0">📘 Guía rápida (una sola vez)</h2>
  <ol>
    <li><b>Play Console</b> → Crear app «TuAcademia», idioma <b>Español (Latinoamérica)</b>, App, Gratis.</li>
    <li>Subir <code>tuacademia-play.aab</code> a <b>Pruebas → Prueba cerrada</b> (así Google habilita las suscripciones) y crear el perfil de pagos (Configuración → Perfil de pagos).</li>
    <li><b>Google Cloud</b> (console.cloud.google.com) → crear un proyecto → activar <b>Google Play Android Developer API</b> → <b>Cuentas de servicio</b> → crear una → <b>Claves → Agregar clave → JSON</b> (se descarga el archivo).</li>
    <li><b>Play Console</b> → Usuarios y permisos → Invitar al correo de la cuenta de servicio → permisos de la app: <b>Ver información financiera</b>, <b>Administrar pedidos y suscripciones</b> y <b>Administrar productos y precios</b>.</li>
    <li>Aquí: subir el .json → <b>Probar la conexión</b> → <b>Crear los productos</b> → marcar <b>Cobrar dentro de la app</b> → Guardar.</li>
    <li>Copiar la huella SHA-256 de Google (cuadro de Ajustes) y guardar.</li>
  </ol>
</div>
<?php
pie_admin();
