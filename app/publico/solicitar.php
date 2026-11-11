<?php
/* (v14) SOLICITAR UNA DEMO: la única forma de empezar (no hay prueba gratis). QF decide a quién se la crea.
 * (10 nov 2026) Demos automáticas de 3 días: 6 datos obligatorios (nombre, nombre comercial, celular, dirección, ciudad
 * y correo), con el error junto a cada campo; al guardar se avisa al Panel Tukán al instante (app/demos_auto.php). */
$planes = filas('SELECT * FROM planes WHERE activo = 1 ORDER BY orden, precio_mensual');
$errores = [];     // errores generales (arriba)
$err = [];         // errores de cada campo (junto al campo)
$enviada = null;
$tipo = es_academia() || post('tipo', get('tipo')) === 'escuela' ? 'escuela' : 'liga'; // (TuAcademia) siempre academia
$recomienda = recomendador_visitante(); // (v28) llegó con el enlace de una liga o escuela que nos recomienda

if (es_post()) {
    csrf_verificar();
    if (post('sitio') !== '') exit; // campo trampa contra robots
    if (demasiados_intentos('solicitud_demo', 5, '-1 day')) $errores[] = 'Ya recibimos varias solicitudes desde su conexión hoy. Le contactaremos pronto.';
    [$dp, $err] = demo_validar_pedido($_POST); // (10 nov 2026) los 6 datos obligatorios
    if (post('acepto') !== '1') $err['acepto'] = 'Debe aceptar que le contactemos y la política de privacidad.';
    $vend = vendedor_del_visitante(post('ref'));
    if (post('ref') !== '' && !$vend) $err['ref'] = 'El código de vendedor "' . post('ref') . '" no existe. Revíselo o déjelo vacío.';
    $plan = (int)post('plan_id') && fila('SELECT id FROM planes WHERE id = ? AND activo = 1', [(int)post('plan_id')]) ? (int)post('plan_id') : null;
    if ($err && !$errores) $errores[] = 'Revise los datos marcados en rojo: todos son obligatorios.';
    if (!$errores && !$err) {
        contexto_auditoria(['usuario' => 'Solicitud de demo: ' . mb_substr($dp['contacto'], 0, 60), 'rol' => 'publico', 'origen' => 'publico']);
        // «telefono» es el celular / WhatsApp (un solo teléfono)
        q('INSERT INTO solicitudes_demo (tipo, organizacion, ciudad, contacto, telefono, direccion, email, tamano, plan_id, mensaje, vendedor_id, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
            $tipo, $dp['organizacion'], $dp['ciudad'], $dp['contacto'], $dp['celular'], $dp['direccion'],
            $dp['email'], mb_substr(post('tamano'), 0, 40), $plan, mb_substr(post('mensaje'), 0, 600), $vend['id'] ?? null, ip_cliente(),
        ]);
        $sid = (int)db()->lastInsertId();
        if ($deps_sol = leer_deportes_post()) q('UPDATE solicitudes_demo SET deportes = ? WHERE id = ?', [implode(',', $deps_sol), $sid]); // (v35)
        if ($rec = recomendador_visitante()) q('UPDATE solicitudes_demo SET recomendado_por = ? WHERE id = ?', [$rec['id'], $sid]); // (v28) referido
        registrar_consentimiento('demo', null, $dp['contacto'], 'titular', ['ref_tabla' => 'solicitudes_demo', 'ref_id' => $sid,
            'texto' => 'Acepto que ' . ajuste('empresa', 'QF Producciones') . ' me contacte por WhatsApp, llamada o correo sobre esta solicitud, y la política de privacidad (datos guardados en servidores fuera del Ecuador).']); // (v24)
        anotar_intento('solicitud_demo');
        // Aviso al superadministrador (y al vendedor, si tiene Telegram) 
        $txt = '📝 <b>Solicitud de demo</b> (' . ($tipo === 'escuela' ? 'escuela' : 'liga') . ")\n" . e($dp['organizacion']) . ' (' . e($dp['ciudad']) . ')'
            . (($deps_sol ?? []) ? "\n🧩 " . e(texto_modulos($deps_sol)) : '') . "\n👤 " . e($dp['contacto']) . ' · ' . e($dp['celular']) . "\n✉ " . e($dp['email']) . ($vend ? "\n🤝 Vendedor: " . e($vend['nombre']) : '')
            . (($rc = recomendador_visitante()) ? "\n🤝 Recomendada por: " . e($rc['nombre']) : '') . "\n\nAtender: " . url_base() . 'admin.php?p=demos'; // (v27) enlace directo
        foreach (filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''") as $su) tg_encolar(null, $su['tg_chat'], $txt, '', 'demo:' . $sid . ':' . $su['tg_chat']);
        if ($vend && ($uv = fila("SELECT tg_chat FROM usuarios WHERE vendedor_id = ? AND rol = 'vendedor' AND activo = 1 AND tg_chat <> ''", [$vend['id']]))) tg_encolar(null, $uv['tg_chat'], $txt, '', 'demo-v:' . $sid);
        try { correos_solicitud_demo(fila('SELECT * FROM solicitudes_demo WHERE id = ?', [$sid])); } catch (Throwable $ex) { error_log('correo solicitud de demo: ' . $ex->getMessage()); } // (v33) confirmación al interesado y aviso al superadministrador
        demo_avisar_panel($sid); // (10 nov 2026) aviso al instante al Panel Tukán (máximo 5 s; si falla, no pasa nada)
        $_SESSION['demo_enviada'] = $sid;
        ir('index.php?v=solicitar&listo=1');
    }
}
/** Clase y mensaje de error de un campo. */
$ec = fn(string $k) => isset($err[$k]) ? ' campo-con-error' : '';
$em = fn(string $k) => isset($err[$k]) ? '<div class="error-campo" id="error-' . $k . '">' . e($err[$k]) . '</div>' : '';
if (get('listo') === '1' && !empty($_SESSION['demo_enviada'])) {
    $enviada = fila('SELECT * FROM solicitudes_demo WHERE id = ?', [(int)$_SESSION['demo_enviada']]);
}
$wa = telefono_wa((string)ajuste('ventas_whatsapp'));
cabecera_publica('Solicitar demo · ' . marca());
?>
<div class="registro">
  <div>
    <h1>Solicite su demo de <?= e(marca()) ?></h1>
    <?php if ($recomienda): ?><div class="aviso aviso-info">🤝 Le recomendó <b><?= e($recomienda['nombre']) ?></b>. ¡Bienvenido!</div><?php endif; ?>
    <p><b>📅 Demo de <?= dias_demo() ?> días con todos los módulos</b>: la clave le llega a su correo, junto con el manual y la guía de primeros pasos.</p>
    <p>Prepare su demo para <?= es_academia() ? 'su academia' : ($tipo === 'escuela' ? 'su escuela' : 'su liga') ?> y pruébela con sus propios datos.</p>
    <ul class="lista-check">
      <li>La demo dura <?= dias_demo() ?> días</li>
      <li>Clave, manual y guía de primeros pasos en su correo</li>
      <li>Le ayudamos a cargar sus <?= $tipo === 'escuela' ? (es_academia() ? 'alumnos y cursos' : 'alumnos y grupos') : 'equipos y su primer campeonato' ?> por WhatsApp</li>
      <li>Sin compromiso: elige su plan cuando esté listo</li>
    </ul>
    <?php if (!es_academia() && liga_demo()): ?><p>¿Quiere mirar primero? <a href="index.php?v=demo">👀 Vea la liga de demostración</a>.</p><?php endif; ?>
  </div>
  <div class="tarjeta">
  <?php if ($enviada): ?>
    <h2 style="margin-top:0" id="demo-gracias">✓ ¡Solicitud recibida!</h2>
    <p><b>¡Listo! Estamos preparando su demo: toma entre 5 y 10 minutos aproximadamente. Cuando esté lista le llega al correo su clave, el manual y la guía de primeros pasos. Revise también la carpeta de spam.</b></p>
    <p>Gracias, <?= e($enviada['contacto']) ?>. Lo enviamos a <b><?= e($enviada['email']) ?></b> para la demo de <b><?= e($enviada['organizacion']) ?></b>.</p>
    <?php if ($wa): ?><a class="btn btn-grande btn-wa" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, acabo de solicitar una demo de ' . marca() . ' para ' . $enviada['organizacion'] . '. Mi nombre es ' . $enviada['contacto'] . '.') ?>">💬 Escribirnos ahora por WhatsApp</a><?php endif; ?>
  <?php else: ?>
    <?php foreach ($errores as $er): ?><div class="aviso aviso-error"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" action="index.php?v=solicitar">
      <?= csrf_campo() ?>
      <input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
      <div class="campos">
        <?php if (es_academia()): ?><input type="hidden" name="tipo" value="escuela"><?php else: ?>
        <div class="campo-ancho"><label>¿Qué organiza?</label><select name="tipo"><option value="liga" <?= $tipo === 'liga' ? 'selected' : '' ?>>🏆 Una liga o campeonatos</option><option value="escuela" <?= $tipo === 'escuela' ? 'selected' : '' ?>>🎓 Una escuela deportiva</option></select></div>
        <?php endif; ?>
        <div class="campo-ancho"><label><?= es_academia() ? '¿Qué disciplinas enseña?' : '¿Qué deportes?' ?> <small class="suave">(marque todos los que necesite)</small></label><?= html_casillas_modulos(leer_deportes_post() ?: (get('modulo') !== '' && modulo_info(get('modulo')) ? [get('modulo')] : []), 'deportes') ?></div>
        <div class="campo-ancho"><div class="aviso aviso-info" style="margin:0">✉ <b>Le enviaremos la clave de su demo a este correo: escríbalo bien.</b> La demo dura <?= dias_demo() ?> días. Preparar su demo toma entre 5 y 10 minutos aproximadamente. Todos los datos con * son obligatorios.</div></div>
        <div class="campo-ancho<?= $ec('organizacion') ?>"><label for="f-organizacion"><?= es_academia() ? 'Nombre de la academia *' : 'Nombre de la liga o escuela *' ?></label><input type="text" id="f-organizacion" name="organizacion" required minlength="3" maxlength="120" value="<?= e(post('organizacion')) ?>" autocomplete="organization"><?= $em('organizacion') ?></div>
        <div class="<?= $ec('contacto') ?>"><label for="f-contacto">Su nombre y apellido *</label><input type="text" id="f-contacto" name="contacto" required minlength="3" maxlength="80" value="<?= e(post('contacto')) ?>" autocomplete="name"><?= $em('contacto') ?></div>
        <div class="<?= $ec('celular') ?>"><label for="f-celular">Celular / WhatsApp *</label><input type="tel" id="f-celular" name="celular" required maxlength="20" pattern="\+?[0-9 \(\)\-]{9,22}" value="<?= e(post('celular')) ?>" autocomplete="tel" placeholder="0991234567"><?= $em('celular') ?></div>
        <div class="campo-ancho<?= $ec('email') ?>"><label for="f-email">Correo *</label><input type="email" id="f-email" name="email" required maxlength="120" value="<?= e(post('email')) ?>" autocomplete="email" placeholder="ahí le llega la clave"><?= $em('email') ?></div>
        <div class="campo-ancho<?= $ec('direccion') ?>"><label for="f-direccion">Dirección *</label><input type="text" id="f-direccion" name="direccion" required minlength="5" maxlength="160" value="<?= e(post('direccion')) ?>" autocomplete="street-address" placeholder="Calle, número y referencia"><?= $em('direccion') ?></div>
        <div class="<?= $ec('ciudad') ?>"><label for="f-ciudad">Ciudad *</label><input type="text" id="f-ciudad" name="ciudad" required minlength="3" maxlength="80" value="<?= e(post('ciudad')) ?>" autocomplete="address-level2"><?= $em('ciudad') ?></div>
        <div><label for="f-tamano">Tamaño aproximado</label><input type="text" id="f-tamano" name="tamano" maxlength="40" value="<?= e(post('tamano')) ?>" placeholder="<?= es_academia() ? 'Ej: 80 alumnos, 3 profesores' : 'Ej: 16 equipos · 80 alumnos' ?>"></div>
        <?php if ($planes): ?><div><label>Plan que le interesa</label><select name="plan_id"><option value="">Todavía no sé</option><?php foreach ($planes as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= (int)post('plan_id', get('plan')) === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['nombre']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php $vref = vendedor_del_visitante(get('ref')) ?? vendedor_del_visitante(); ?>
        <div class="<?= $ec('ref') ?>"><label>Código de vendedor (opcional)</label><input type="text" name="ref" maxlength="20" value="<?= e(post('ref', $vref['codigo'] ?? '')) ?>" style="text-transform:uppercase" placeholder="Si alguien le recomendó"><?php if ($vref): ?><div class="ayuda">Le atiende: <?= e($vref['nombre']) ?></div><?php endif; ?><?= $em('ref') ?></div>
        <div class="campo-ancho"><label>¿Algo que debamos saber?</label><textarea name="mensaje" rows="3" maxlength="600" placeholder="<?= es_academia() ? 'Ej: cuándo es su próximo recital, qué le interesa más' : 'Ej: cuándo empieza su campeonato, qué le interesa más' ?>"><?= e(post('mensaje')) ?></textarea></div>
        <div class="campo-ancho<?= $ec('acepto') ?>"><label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required <?= post('acepto') === '1' ? 'checked' : '' ?>> Acepto que <?= e(ajuste('empresa', 'QF Producciones')) ?> me contacte por WhatsApp, llamada o correo sobre esta solicitud, y la <a href="index.php?v=privacidad" target="_blank">política de privacidad</a> (datos guardados en servidores fuera del Ecuador).</label><?= $em('acepto') ?></div>
      </div>
      <button class="btn btn-grande" type="submit">📝 Solicitar mi demo</button>
    </form>
    <p class="suave" style="text-align:center">¿Ya tiene cuenta? <a href="admin.php?p=login">Ingresar</a></p>
  <?php endif; ?>
  </div>
</div>
<?php
pie_publico();
