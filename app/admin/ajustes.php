<?php
/* AJUSTES GENERALES DEL SISTEMA (solo superadministrador): marca, venta, protección de datos, seguridad y bot de Telegram */
if (!es_super()) ir('admin.php');
$yo = usuario();

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'generales') {
        $cambios = [];
        foreach (['marca' => 60, 'empresa' => 80, 'ventas_whatsapp' => 30, 'ventas_email' => 120, 'landing_titulo' => 160, 'landing_subtitulo' => 400, 'responsable_nombre' => 120, 'responsable_email' => 120, 'ventas_datos_pago' => 800, 'nota_dominio' => 400] as $k => $max) {
            $v = mb_substr(post($k), 0, $max);
            if ($v !== (string)ajuste($k)) { guardar_ajuste($k, $v); $cambios[] = $k; }
        }
        foreach (['dias_prueba' => [1, 365], 'dias_gracia' => [0, 60], 'dias_gracia_demo' => [0, 60], 'dias_demo' => [1, 365], 'combo_descuento' => [0, 90], 'referidos_meses' => [0, 12]] as $k => [$mn, $mx]) {
            $v = (string)entero(post($k), $mn, $mx);
            if ($v !== (string)ajuste($k)) { guardar_ajuste($k, $v); $cambios[] = $k; }
        }
        foreach (['registro_abierto', 'exigir_2fa'] as $k) {
            $v = post($k) === '1' ? '1' : '0';
            if ($v !== (string)ajuste($k)) { guardar_ajuste($k, $v); $cambios[] = $k; }
        }
        if ($cambios) bitacora_evento('ajustes', 'Cambió los ajustes: ' . implode(', ', $cambios));
        flash('Ajustes guardados.');
    } elseif ($accion === 'telegram') {
        $token = trim(post('tg_token'));
        if ($token === '') {
            guardar_ajuste('tg_token', '');
            guardar_ajuste('tg_usuario', '');
            bitacora_evento('telegram', 'Desactivó el bot de Telegram');
            flash('Bot de Telegram desactivado.');
        } else {
            if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $token)) throw new RuntimeException('El token no tiene el formato correcto. Cópielo completo desde @BotFather (ej: 123456789:ABCdef...).');
            guardar_ajuste('tg_token', $token);
            $r = tg_api('getMe');
            if (!($r['ok'] ?? false)) {
                guardar_ajuste('tg_token', '');
                throw new RuntimeException('Telegram no aceptó el token (' . ($r['description'] ?? 'sin respuesta') . '). Revise que esté bien copiado y que el servidor tenga internet.');
            }
            tg_api('deleteWebhook');
            guardar_ajuste('tg_usuario', (string)($r['result']['username'] ?? ''));
            bitacora_evento('telegram', 'Configuró el bot de Telegram @' . ($r['result']['username'] ?? ''));
            flash('Bot conectado: @' . ($r['result']['username'] ?? '') . '. Ahora conecte su Telegram personal en "Mi cuenta" y pruebe.');
        }
    } elseif ($accion === 'tg_prueba') {
        if ($yo['tg_chat'] === '') throw new RuntimeException('Primero conecte su Telegram personal en "Mi cuenta".');
        tg_encolar(null, $yo['tg_chat'], '🔔 Mensaje de prueba de ' . e(marca()) . '. ¡Los avisos funcionan!');
        $n = tg_procesar_cola();
        flash($n ? 'Mensaje de prueba enviado. Revise su Telegram.' : 'El mensaje quedó en cola; se enviará en el próximo minuto. Si no llega, revise la sección "Estado" de abajo.');
    } elseif ($accion === 'videos') {
        // (v28) Dónde se guardan los videos de jugadores y alumnos
        $url = rtrim(trim(post('videos_url')), '/');
        if ($url !== '' && !preg_match('#^https://[a-z0-9.\-]+(/[\w\-/]*)?$#i', $url)) throw new RuntimeException('La dirección debe empezar con https:// (ej: https://videos-tuacademia.tukanec.com)');
        guardar_ajuste('videos_url', $url);
        if (post('videos_clave') !== '') guardar_ajuste('videos_clave', mb_substr(trim(post('videos_clave')), 0, 200));
        if ($url === '') guardar_ajuste('videos_clave', '');
        bitacora_evento('ajustes', 'Cambió el lugar donde se guardan los videos: ' . ($url ?: 'este servidor'));
        flash($url !== '' ? 'Listo: los videos nuevos se guardarán en ' . $url . '. Pruebe la conexión.' : 'Listo: los videos se guardarán en este servidor.');
    } elseif ($accion === 'videos_probar') {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $r = json_decode((string)@file_get_contents(rtrim((string)ajuste('videos_url'), '/') . '/index.php', false, $ctx), true);
        if (!($r['ok'] ?? false)) throw new RuntimeException('No responde el receptor de videos en ' . ajuste('videos_url') . '. Revise que la laptop esté encendida y el túnel de Cloudflare activo.');
        flash('✅ El receptor de videos responde. Espacio libre en la laptop: ' . $r['libre_gb'] . ' GB.');
    } elseif ($accion === 'smtp') {
        // (v10) Correo saliente para "Olvidé mi contraseña"
        guardar_ajuste('smtp_host', mb_substr(trim(post('smtp_host')), 0, 120));
        guardar_ajuste('smtp_puerto', (string)entero(post('smtp_puerto'), 1, 65535));
        guardar_ajuste('smtp_seguridad', in_array(post('smtp_seguridad'), ['tls', 'ssl', 'no'], true) ? post('smtp_seguridad') : 'tls');
        guardar_ajuste('smtp_usuario', mb_substr(trim(post('smtp_usuario')), 0, 120));
        if (post('smtp_clave') !== '') guardar_ajuste('smtp_clave', mb_substr(post('smtp_clave'), 0, 200));
        if (post('smtp_borrar_clave') === '1') guardar_ajuste('smtp_clave', '');
        guardar_ajuste('smtp_remitente', mb_substr(trim(post('smtp_remitente')), 0, 120));
        bitacora_evento('ajustes', 'Cambió la configuración del correo saliente (SMTP)');
        flash('Correo saliente guardado. Pruébelo con el botón "Enviarme un correo de prueba".');
    } elseif ($accion === 'smtp_prueba') {
        smtp_enviar($yo['email'], 'Prueba de correo de ' . marca(), 'Si le llegó este mensaje, el correo saliente de ' . marca() . ' funciona. Los usuarios ya pueden recuperar su contraseña por correo.');
        flash('Correo de prueba enviado a ' . $yo['email'] . '. Revise su bandeja (y la carpeta de spam).');
    } elseif ($accion === 'demo') {
        // (v10/v15) Liga y escuela de demostración con todos los portales
        guardar_ajuste('demo_activa', post('demo_activa') === '1' ? '1' : '0');
        guardar_ajuste('demo_guardar', post('demo_guardar') === '1' ? '1' : '0');
        if (post('demo_activa') === '1' && (post('crear') === '1' || (!es_academia() && !liga_demo()) || !escuela_demo())) {
            crear_demos();
            guardar_ajuste('demo_reinicio', date('Y-m-d'));
            bitacora_evento('demo', es_academia() ? 'Creó / reinició la academia de demostración' : 'Creó / reinició la liga y la escuela de demostración');
            flash(es_academia() ? 'Academia de demostración lista. El botón "Ver demo" ya aparece en la página de venta. Se reinicia sola cada madrugada.' : 'Demostraciones listas (liga y escuela). El botón "Ver demo" ya aparece en la página de venta. Se reinician solas cada madrugada.');
        } elseif (post('demo_activa') !== '1') {
            sin_auditoria(fn() => q('DELETE FROM ligas WHERE es_demo = 1'));
            bitacora_evento('demo', 'Desactivó y borró las demostraciones');
            flash('Demostraciones desactivadas y borradas.');
        } else {
            flash('Ajustes de la demostración guardados.');
        }
    } elseif ($accion === 'pasarela') {
        // (v12) Cobros con tarjeta (Plux)
        $modo = in_array(post('pasarela_modo'), ['apagado', 'prueba', 'plux'], true) ? post('pasarela_modo') : 'apagado';
        if ($modo === 'plux' && (!plux_configurado() && (post('plux_clave_secreta') === '' || post('plux_llave_publica') === ''))) throw new RuntimeException('Para usar Plux escriba primero sus datos (ID de establecimiento, ID de cliente, clave secreta y llave pública).');
        foreach (['comision_credito', 'comision_debito', 'iva_pct', 'comision_tuvocalia'] as $k) guardar_ajuste($k, (string)max(0, min(50, round((float)str_replace(',', '.', post($k)), 2))));
        guardar_ajuste('plux_ambiente', post('plux_ambiente') === 'produccion' ? 'produccion' : 'sandbox');
        foreach (['plux_id_establecimiento', 'plux_id_cliente'] as $k) guardar_ajuste($k, mb_substr(trim(post($k)), 0, 200));
        foreach (['plux_clave_secreta', 'plux_llave_publica'] as $k) if (post($k) !== '') guardar_ajuste($k, mb_substr(trim(post($k)), 0, 5000));
        // (v13) dirección de producción que entrega Plux, y si el cobro lleva IVA
        $url_prod = trim(post('plux_url_produccion'));
        if ($url_prod !== '' && !preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}/#i', rtrim($url_prod, '/') . '/')) throw new RuntimeException('La dirección de producción de Plux debe empezar con https://');
        guardar_ajuste('plux_url_produccion', mb_substr($url_prod, 0, 200));
        guardar_ajuste('plux_iva_suscripcion', post('plux_iva_suscripcion') === '1' ? '1' : '0');
        guardar_ajuste('plux_iva_cobros', post('plux_iva_cobros') === '1' ? '1' : '0');
        if ($modo === 'plux' && !plux_listo()) {
            $modo = 'prueba';
            flash('Los datos de Plux quedaron guardados, pero falta la dirección de la API de PRODUCCIÓN de Plux: el sistema sigue en MODO PRUEBA.', 'error');
        }
        guardar_ajuste('pasarela_modo', $modo);
        bitacora_evento('ajustes', 'Cambió la configuración de cobros con tarjeta (modo: ' . $modo . ')');
        flash('Cobros con tarjeta guardados. Modo: ' . $modo . '.');
    } elseif ($accion === 'tg_reintentar') {
        q("UPDATE telegram_cola SET intentos = 0 WHERE enviado = '' AND intentos >= 5");
        flash('Los mensajes fallidos se volverán a intentar.');
    }
    ir('admin.php?p=ajustes');
}

$pend = (int)valor("SELECT COUNT(*) FROM telegram_cola WHERE enviado = '' AND intentos < 5");
$fallidos = filas("SELECT * FROM telegram_cola WHERE enviado = '' AND intentos >= 5 ORDER BY id DESC LIMIT 10");
$enviados = (int)valor("SELECT COUNT(*) FROM telegram_cola WHERE enviado >= datetime('now', '-1 day')");
$ultima_tarea = (string)ajuste('tarea_ultima', '');
cabecera_admin('Ajustes');
?>
<h1>⚙️ Ajustes del sistema</h1>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="generales">
  <h2 style="margin-top:0">Marca y venta</h2>
  <div class="campos">
    <div><label>Nombre del sistema (marca)</label><input type="text" name="marca" maxlength="60" value="<?= e(ajuste('marca')) ?>"></div>
    <div><label>Empresa</label><input type="text" name="empresa" maxlength="80" value="<?= e(ajuste('empresa')) ?>"></div>
    <div><label>WhatsApp de ventas</label><input type="tel" name="ventas_whatsapp" maxlength="30" value="<?= e(ajuste('ventas_whatsapp')) ?>" placeholder="0991234567"><div class="ayuda">Botón de WhatsApp en la página de venta y en los avisos de renovación.</div></div>
    <div><label>Correo de ventas</label><input type="email" name="ventas_email" maxlength="120" value="<?= e(ajuste('ventas_email')) ?>"></div>
    <div class="campo-ancho"><label>Título de la página de venta</label><input type="text" name="landing_titulo" maxlength="160" value="<?= e(ajuste('landing_titulo')) ?>"></div>
    <div class="campo-ancho"><label>Datos para que las ligas le paguen (cuenta bancaria, etc.)</label><textarea name="ventas_datos_pago" maxlength="800" rows="3" placeholder="Banco Pichincha · Cuenta de ahorros N° 2200000000 · A nombre de ... · Cédula/RUC ..."><?= e(ajuste('ventas_datos_pago')) ?></textarea><div class="ayuda">Aparecen en el aviso de vencimiento (5 días antes) en el panel de cada liga y por Telegram, junto con el valor de su plan.</div></div>
    <div class="campo-ancho"><label>(v16) Nota del dominio gratis (sale con * en los planes)</label><textarea name="nota_dominio" maxlength="400" rows="2"><?= e(ajuste('nota_dominio')) ?></textarea></div>
    <div><label>(v16) Descuento del combo liga + escuela (%)</label><input type="number" name="combo_descuento" min="0" max="90" step="1" value="<?= e((string)combo_descuento()) ?>"><div class="ayuda">Se aplica a los dos planes cuando la misma persona tiene una liga y una escuela enlazadas.</div></div>
    <div class="campo-ancho"><label>Texto bajo el título</label><textarea name="landing_subtitulo" maxlength="400" rows="2"><?= e(ajuste('landing_subtitulo')) ?></textarea></div>
    <div class="campo-ancho"><label>Cómo empiezan los clientes nuevos</label><div class="ayuda" style="margin-bottom:4px">Recomendado: <b>demo por solicitud</b>. El interesado llena "Solicitar demo" y usted decide a quién le crea la cuenta (menú <a href="admin.php?p=demos">Demos</a>).</div>
      <label class="check"><input type="checkbox" name="registro_abierto" value="1" <?= prueba_automatica() ? 'checked' : '' ?>> Permitir la prueba gratis automática (cualquiera crea su liga sin solicitud)</label></div>
    <div><label>Meses gratis por cada recomendado que contrata</label><input type="number" name="referidos_meses" min="0" max="12" value="<?= (int)ajuste('referidos_meses', 1) ?>"><div class="ayuda">Programa «Recomiende y gane». 0 = sin premio.</div></div>
    <div><label>Días de la demo (por defecto)</label><input type="number" name="dias_demo" min="1" max="365" value="<?= (int)ajuste('dias_demo', 7) ?>"><div class="ayuda">Al crear la cuenta desde una solicitud. Puede cambiarlo en cada una.</div></div>
    <div><label>Días de la prueba automática</label><input type="number" name="dias_prueba" min="1" max="365" value="<?= (int)ajuste('dias_prueba', 7) ?>"><div class="ayuda">Solo si la prueba automática está permitida.</div></div>
    <div><label>Días de gracia después de vencer (cuentas pagadas)</label><input type="number" name="dias_gracia" min="0" max="60" value="<?= (int)ajuste('dias_gracia', 7) ?>"><div class="ayuda">Luego el panel de la liga queda en solo lectura (la página pública sigue visible).</div></div>
    <div><label>Días de gracia de las demos</label><input type="number" name="dias_gracia_demo" min="0" max="60" value="<?= (int)ajuste('dias_gracia_demo', 0) ?>"><div class="ayuda">(v33) Recomendado 0: la demo se bloquea el día que vence y se le ofrece contratar (no pierde lo que cargó).</div></div>
    <div class="campo-ancho"><label>Dirección pública del sistema</label><input type="text" name="url_sitio" value="<?= e(ajuste('url_sitio')) ?>" placeholder="https://tuacademia.tukanec.com/" readonly><div class="ayuda">(v33) Se guarda sola cuando usted entra al panel. La usan los correos y avisos que salen de la tarea automática.</div></div>
  </div>
  <h2>Protección de datos (LOPDP)</h2>
  <div class="campos">
    <div><label>Encargado del tratamiento</label><input type="text" name="responsable_nombre" maxlength="120" value="<?= e(ajuste('responsable_nombre')) ?>"></div>
    <div><label>Correo para pedidos de datos</label><input type="email" name="responsable_email" maxlength="120" value="<?= e(ajuste('responsable_email')) ?>"></div>
  </div>
  <p class="ayuda">Aparecen en la <a href="index.php?v=privacidad" target="_blank">política de privacidad</a>.</p>
  <h2>Seguridad</h2>
  <label class="check"><input type="checkbox" name="exigir_2fa" value="1" <?= (int)ajuste('exigir_2fa', 0) ? 'checked' : '' ?>> Exigir doble verificación (código en el celular) a todos los administradores</label>
  <p class="ayuda">Si la activa, cada administrador deberá configurarla la próxima vez que ingrese. Active primero la suya en "Mi cuenta".</p>
  <button class="btn">Guardar ajustes</button>
</form>

<form method="post" class="tarjeta" id="pasarela">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="pasarela">
  <h2 style="margin-top:0">💳 Cobros con tarjeta (Plux)</h2>
  <p class="suave" style="margin-top:0">Las ligas y escuelas le pagan su suscripción con tarjeta, y sus equipos y familias les pagan a ellas con <?= e(marca()) ?> de intermediario. Reporte y transferencias en <a href="admin.php?p=pasarela">💳 Cobros</a>.</p>
  <div class="campos">
    <div><label>Modo</label><select name="pasarela_modo">
      <?php foreach (['apagado' => 'Apagado (no aparecen botones de pago)', 'prueba' => 'Prueba (solo usted aprueba pagos simulados)', 'plux' => 'Plux (pagos reales)'] as $k => $t): ?><option value="<?= $k ?>" <?= pasarela_modo() === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div><label>Comisión tarjeta de CRÉDITO (%)</label><input type="number" name="comision_credito" step="0.01" min="0" value="<?= e(ajuste('comision_credito')) ?>"><div class="ayuda">Plan Basic de Plux: 4,02% + IVA</div></div>
    <div><label>Comisión tarjeta de DÉBITO (%)</label><input type="number" name="comision_debito" step="0.01" min="0" value="<?= e(ajuste('comision_debito')) ?>"><div class="ayuda">Plan Basic de Plux: 2% + IVA</div></div>
    <div><label>IVA (%)</label><input type="number" name="iva_pct" step="0.01" min="0" value="<?= e(ajuste('iva_pct')) ?>"></div>
    <div><label>Gestión administrativa <?= e(marca()) ?> (%)</label><input type="number" name="comision_tuvocalia" step="0.01" min="0" value="<?= e(ajuste('comision_tuvocalia')) ?>"><div class="ayuda">Se cobra solo en los pagos de equipos y familias a sus ligas y escuelas.</div></div>
  </div>
  <h3>Datos de Plux</h3>
  <div class="campos">
    <div><label>Ambiente</label><select name="plux_ambiente"><option value="sandbox" <?= ajuste('plux_ambiente') !== 'produccion' ? 'selected' : '' ?>>Pruebas (sandbox)</option><option value="produccion" <?= ajuste('plux_ambiente') === 'produccion' ? 'selected' : '' ?>>Producción</option></select></div>
    <div><label>ID de establecimiento</label><input type="text" name="plux_id_establecimiento" value="<?= e(ajuste('plux_id_establecimiento')) ?>" autocomplete="off"></div>
    <div><label>ID de cliente</label><input type="text" name="plux_id_cliente" value="<?= e(ajuste('plux_id_cliente')) ?>" autocomplete="off"></div>
    <div><label>Clave secreta</label><input type="password" name="plux_clave_secreta" autocomplete="new-password" placeholder="<?= ajuste('plux_clave_secreta') !== '' ? '•••••• (guardada; escriba para cambiarla)' : '' ?>"></div>
    <div class="campo-ancho"><label>Llave pública (RSA)</label><textarea name="plux_llave_publica" rows="2" placeholder="<?= ajuste('plux_llave_publica') !== '' ? '(guardada; pegue otra para cambiarla)' : '-----BEGIN PUBLIC KEY-----' ?>"></textarea></div>
    <div class="campo-ancho"><label>Dirección de la API de PRODUCCIÓN</label><input type="url" name="plux_url_produccion" value="<?= e(ajuste('plux_url_produccion')) ?>" placeholder="https://…/intv1/"><div class="ayuda">Solo para el ambiente Producción: la entrega Plux al aprobar la certificación. La de pruebas ya está puesta (<?= e(PLUX_URL_SANDBOX) ?>).</div></div>
    <div><label class="check"><input type="checkbox" name="plux_iva_suscripcion" value="1" <?= (int)ajuste('plux_iva_suscripcion', 1) === 1 ? 'checked' : '' ?>> Suscripciones con IVA</label><div class="ayuda">Lo que las ligas le pagan a <?= e(marca()) ?> se envía a Plux como valor con IVA incluido.</div></div>
    <div><label class="check"><input type="checkbox" name="plux_iva_cobros" value="1" <?= (int)ajuste('plux_iva_cobros', 0) === 1 ? 'checked' : '' ?>> Cobros de las organizaciones con IVA</label><div class="ayuda">Pagos de equipos y familias. Normalmente sin IVA (valor 0%).</div></div>
  </div>
  <p class="ayuda">Conexión con Plux: <?= plux_listo() ? '✅ lista (' . (ajuste('plux_ambiente') === 'produccion' ? 'PRODUCCIÓN' : 'pruebas / sandbox') . ')' : '⏳ falta la dirección de producción' ?> · Datos: <?= plux_configurado() ? '✅ completos' : 'incompletos' ?>. Con el modo Plux en ambiente de pruebas, use las tarjetas de prueba que le dio Plux.</p>
  <button class="btn btn-sec">Guardar cobros con tarjeta</button>
</form>

<?php $demo = liga_demo(); ?>
<form method="post" class="tarjeta" id="demo">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="demo">
  <h2 style="margin-top:0">👀 Demostraciones (liga y escuela)</h2>
  <p>Una <b>liga</b> y una <b>escuela</b> de ejemplo, llenas de datos, para presentar el sistema. En la página <a target="_blank" href="index.php?v=demo">Ver demo</a> se entra con un clic a cada panel: <b>administrador, árbitro, delegado, vocal y jugador</b> (liga) y <b>dueño, entrenador, familia y carnet del alumno</b> (escuela). Se borran y se vuelven a crear solas cada madrugada.</p>
  <label class="check"><input type="checkbox" name="demo_activa" value="1" <?= (int)ajuste('demo_activa', 0) === 1 ? 'checked' : '' ?>> Activar las demostraciones</label>
  <label class="check"><input type="checkbox" name="demo_guardar" value="1" <?= demo_guardar() ? 'checked' : '' ?>> Permitir probar y guardar en la demo (registrar goles en la vocalía, tomar asistencia, cobrar…). Todo se borra cada madrugada.</label>
  <?php $edemo = escuela_demo(); if ($demo): ?><p class="suave">✅ Activa: <a target="_blank" href="<?= e(url_liga($demo)) ?>"><?= e($demo['nombre']) ?> ↗</a><?= $edemo ? ' · <a target="_blank" href="' . e(url_liga($edemo)) . '">' . e($edemo['nombre']) . ' ↗</a>' : '' ?> · <a target="_blank" href="index.php?v=demo">página "Ver demo" ↗</a> · creada <?= e((string)ajuste('demo_creada', '')) ?></p>
  <label class="check" style="font-weight:400"><input type="checkbox" name="crear" value="1"> Reiniciarlas ahora (borra y crea de nuevo la liga y la escuela)</label><?php endif; ?>
  <button class="btn btn-sec" style="margin-top:8px">Guardar</button>
</form>

<form method="post" class="tarjeta" id="correo">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="smtp">
  <h2 style="margin-top:0">📧 Correo saliente (para "Olvidé mi contraseña")</h2>
  <p class="suave" style="margin-top:0">Si un usuario olvida su contraseña, el código le llega por <b>Telegram</b> (si lo conectó en "Mi cuenta"). Si no tiene Telegram, se envía por <b>correo</b> con estos datos. Con Gmail: servidor <code>smtp.gmail.com</code>, puerto <code>587</code>, TLS, su correo y una <b>contraseña de aplicación</b> (Cuenta de Google → Seguridad → Contraseñas de aplicaciones).</p>
  <div class="campos">
    <div><label>Servidor SMTP</label><input type="text" name="smtp_host" maxlength="120" value="<?= e(ajuste('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
    <div><label>Puerto</label><input type="number" name="smtp_puerto" min="1" max="65535" value="<?= (int)ajuste('smtp_puerto', 587) ?>"></div>
    <div><label>Seguridad</label><select name="smtp_seguridad"><?php foreach (['tls' => 'TLS (puerto 587)', 'ssl' => 'SSL (puerto 465)', 'no' => 'Ninguna'] as $k => $v): ?><option value="<?= $k ?>" <?= ajuste('smtp_seguridad', 'tls') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div><label>Usuario</label><input type="text" name="smtp_usuario" maxlength="120" value="<?= e(ajuste('smtp_usuario')) ?>" autocomplete="off"></div>
    <div><label>Contraseña</label><input type="password" name="smtp_clave" maxlength="200" autocomplete="new-password" placeholder="<?= ajuste('smtp_clave') !== '' ? '•••••••• (guardada; escriba para cambiarla)' : '' ?>"><?php if (ajuste('smtp_clave') !== ''): ?><label class="check" style="font-weight:400"><input type="checkbox" name="smtp_borrar_clave" value="1"> borrar la guardada</label><?php endif; ?></div>
    <div><label>Correo remitente</label><input type="email" name="smtp_remitente" maxlength="120" value="<?= e(ajuste('smtp_remitente')) ?>" placeholder="notificaciones@tukanec.com"></div>
  </div>
  <button class="btn btn-sec">Guardar correo</button>
</form>
<?php if (smtp_configurado()): ?><form method="post" style="margin:-8px 0 14px"><?= csrf_campo() ?><input type="hidden" name="accion" value="smtp_prueba"><button class="btn btn-sec btn-chico">Enviarme un correo de prueba (<?= e($yo['email']) ?>)</button></form><?php endif; ?>

<?php $push_ok = push_disponible(); ?>
<div class="tarjeta" id="push">
  <h2 style="margin-top:0">🔔 Notificaciones "Seguir a mi equipo"</h2>
  <?php if ($push_ok): ?>
    <p>✅ Funcionando. En la página pública de cada equipo aparece el botón <b>Seguir a este equipo</b>: el hincha recibe en su celular los goles, el resultado final y los horarios.
      Celulares suscritos: <b><?= (int)valor('SELECT COUNT(*) FROM push_suscripciones') ?></b> · Enviadas en 24 h: <b><?= (int)valor("SELECT COUNT(*) FROM push_cola WHERE enviado >= datetime('now', '-1 day', 'localtime')") ?></b> · En cola: <?= (int)valor("SELECT COUNT(*) FROM push_cola WHERE enviado = '' AND intentos < 5") ?></p>
  <?php else: ?>
    <p class="aviso aviso-error">No disponible: el servidor necesita PHP con OpenSSL (curva P-256) y cURL.</p>
  <?php endif; ?>
</div>

<?php $uv = (array)fila("SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS b FROM videos WHERE estado = 'listo'"); ?>
<div class="tarjeta" id="videos">
  <h2 style="margin-top:0">🎬 Videos de jugadores y alumnos</h2>
  <p>Ahora se guardan en: <b><?= videos_modo() === 'laptop' ? e(ajuste('videos_url')) . ' (laptop)' : 'este servidor' ?></b> · Videos disponibles: <?= (int)$uv['n'] ?> (<?= texto_bytes((int)$uv['b']) ?>) · Se borran solos a los <?= VIDEO_DIAS ?> días.</p>
  <p class="suave">Para guardarlos en la laptop (disco grande): instale el receptor con <code>sudo bash vps/laptop_videos.sh</code> en la laptop, publíquelo en el túnel de Cloudflare (ej: videos-tuacademia.tukanec.com) y escriba aquí la dirección y la clave que le mostró el instalador.</p>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="videos">
    <div class="campos">
      <div><label>Dirección del receptor (vacío = este servidor)</label><input name="videos_url" value="<?= e(ajuste('videos_url')) ?>" placeholder="https://videos-tuacademia.tukanec.com"></div>
      <div><label>Clave del receptor</label><input name="videos_clave" value="" autocomplete="off" placeholder="<?= ajuste('videos_clave') !== '' ? '•••••••• (guardada; escriba solo para cambiarla)' : 'la clave que mostró el instalador' ?>"></div>
    </div>
    <button class="btn btn-sec">Guardar</button></form>
  <?php if (videos_modo() === 'laptop'): ?><form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="videos_probar"><button class="btn btn-sec btn-chico">Probar la conexión</button></form><?php endif; ?>
</div>

<div class="tarjeta" id="telegram">
  <h2 style="margin-top:0">📲 Bot de Telegram (avisos automáticos)</h2>
  <?php if (tg_activo()): ?>
    <p>✅ Conectado: <b>@<?= e(ajuste('tg_usuario')) ?></b> · En cola: <?= $pend ?> · Enviados últimas 24 h: <?= $enviados ?> · Tarea automática: <?= $ultima_tarea ? 'funcionando (última vez ' . e($ultima_tarea) . ')' : '<b style="color:var(--roja)">no se ha ejecutado</b> (revise la instalación)' ?></p>
    <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_prueba"><button class="btn btn-sec btn-chico">Enviarme un mensaje de prueba</button></form>
  <?php else: ?>
    <p>Para activar los avisos por Telegram (opcional):</p>
    <ol>
      <li>En Telegram busque <b>@BotFather</b> y escríbale <code>/newbot</code>.</li>
      <li>Póngale un nombre (ej: <i>TuAcademia Avisos</i>) y un usuario que termine en <i>bot</i> (ej: <i>TuAcademiaBot</i>).</li>
      <li>BotFather le da un <b>token</b> (algo como <code>123456789:ABCdef…</code>). Cópielo y péguelo aquí abajo.</li>
    </ol>
  <?php endif; ?>
  <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="telegram">
    <div class="campos"><div class="campo-ancho"><label>Token del bot</label><input type="text" name="tg_token" value="<?= e(ajuste('tg_token')) ?>" autocomplete="off" placeholder="123456789:ABC..."><div class="ayuda">Déjelo vacío y guarde para desactivar los avisos.</div></div></div>
    <button class="btn btn-sec">Guardar token</button></form>
  <?php if ($fallidos): ?>
    <h3>Mensajes que no se pudieron enviar</h3>
    <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Destino</th><th>Error</th></tr>
      <?php foreach ($fallidos as $f): ?><tr><td><?= e($f['creado']) ?></td><td><?= e($f['chat_id']) ?></td><td style="white-space:normal"><?= e($f['error']) ?></td></tr><?php endforeach; ?></table></div>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_reintentar"><button class="btn btn-sec btn-chico">Reintentar</button></form>
  <?php endif; ?>
</div>
<?php
pie_admin();
