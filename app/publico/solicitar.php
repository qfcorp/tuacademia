<?php
/* (v14) SOLICITAR UNA DEMO: la única forma de empezar (no hay prueba gratis). QF decide a quién se la crea. */
$planes = filas('SELECT * FROM planes WHERE activo = 1 ORDER BY orden, precio_mensual');
$errores = [];
$enviada = null;
$tipo = es_academia() || post('tipo', get('tipo')) === 'escuela' ? 'escuela' : 'liga'; // (TuAcademia) siempre academia
$recomienda = recomendador_visitante(); // (v28) llegó con el enlace de una liga o escuela que nos recomienda

if (es_post()) {
    csrf_verificar();
    if (post('sitio') !== '') exit; // campo trampa contra robots
    if (demasiados_intentos('solicitud_demo', 5, '-1 day')) $errores[] = 'Ya recibimos varias solicitudes desde su conexión hoy. Le contactaremos pronto.';
    if (mb_strlen(post('organizacion')) < 3) $errores[] = es_academia() ? 'Escriba el nombre de su academia.' : 'Escriba el nombre de su liga o escuela.';
    if (mb_strlen(post('contacto')) < 3) $errores[] = 'Escriba su nombre.';
    if (strlen(preg_replace('/\D/', '', post('telefono'))) < 9) $errores[] = 'Escriba su número de celular (WhatsApp) para contactarle.';
    if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo no es válido.';
    if (post('acepto') !== '1') $errores[] = 'Debe aceptar que le contactemos y la política de privacidad.';
    $vend = vendedor_del_visitante(post('ref'));
    if (post('ref') !== '' && !$vend) $errores[] = 'El código de vendedor "' . post('ref') . '" no existe. Revíselo o déjelo vacío.';
    $plan = (int)post('plan_id') && fila('SELECT id FROM planes WHERE id = ? AND activo = 1', [(int)post('plan_id')]) ? (int)post('plan_id') : null;
    if (!$errores) {
        contexto_auditoria(['usuario' => 'Solicitud de demo: ' . mb_substr(post('contacto'), 0, 60), 'rol' => 'publico', 'origen' => 'publico']);
        q('INSERT INTO solicitudes_demo (tipo, organizacion, ciudad, contacto, telefono, email, tamano, plan_id, mensaje, vendedor_id, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $tipo, mb_substr(post('organizacion'), 0, 120), mb_substr(post('ciudad'), 0, 80), mb_substr(post('contacto'), 0, 80), mb_substr(post('telefono'), 0, 30),
            mb_substr(mb_strtolower(post('email')), 0, 120), mb_substr(post('tamano'), 0, 40), $plan, mb_substr(post('mensaje'), 0, 600), $vend['id'] ?? null, ip_cliente(),
        ]);
        $sid = (int)db()->lastInsertId();
        if ($deps_sol = leer_deportes_post()) q('UPDATE solicitudes_demo SET deportes = ? WHERE id = ?', [implode(',', $deps_sol), $sid]); // (v35)
        if ($rec = recomendador_visitante()) q('UPDATE solicitudes_demo SET recomendado_por = ? WHERE id = ?', [$rec['id'], $sid]); // (v28) referido
        registrar_consentimiento('demo', null, post('contacto'), 'titular', ['ref_tabla' => 'solicitudes_demo', 'ref_id' => $sid,
            'texto' => 'Acepto que ' . ajuste('empresa', 'QF Producciones') . ' me contacte por WhatsApp, llamada o correo sobre esta solicitud, y la política de privacidad (datos guardados en servidores fuera del Ecuador).']); // (v24)
        anotar_intento('solicitud_demo');
        // Aviso al superadministrador (y al vendedor, si tiene Telegram) 
        $txt = '📝 <b>Solicitud de demo</b> (' . ($tipo === 'escuela' ? 'escuela' : 'liga') . ")\n" . e(post('organizacion')) . (post('ciudad') !== '' ? ' (' . e(post('ciudad')) . ')' : '')
            . (($deps_sol ?? []) ? "\n🧩 " . e(texto_modulos($deps_sol)) : '') . "\n👤 " . e(post('contacto')) . ' · ' . e(post('telefono')) . (post('email') !== '' ? "\n✉ " . e(mb_strtolower(post('email'))) : '') . ($vend ? "\n🤝 Vendedor: " . e($vend['nombre']) : '')
            . (($rc = recomendador_visitante()) ? "\n🤝 Recomendada por: " . e($rc['nombre']) : '') . "\n\nAtender: " . url_base() . 'admin.php?p=demos'; // (v27) enlace directo
        foreach (filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''") as $su) tg_encolar(null, $su['tg_chat'], $txt, '', 'demo:' . $sid . ':' . $su['tg_chat']);
        if ($vend && ($uv = fila("SELECT tg_chat FROM usuarios WHERE vendedor_id = ? AND rol = 'vendedor' AND activo = 1 AND tg_chat <> ''", [$vend['id']]))) tg_encolar(null, $uv['tg_chat'], $txt, '', 'demo-v:' . $sid);
        correos_solicitud_demo(fila('SELECT * FROM solicitudes_demo WHERE id = ?', [$sid])); // (v33) confirmación al interesado y aviso al superadministrador
        $_SESSION['demo_enviada'] = $sid;
        ir('index.php?v=solicitar&listo=1');
    }
}
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
    <p><b>📅 Demo de <?= (int)ajuste('dias_demo', 7) ?> días con todos los módulos</b>: le preparamos el acceso y le ayudamos a cargar su <?= es_academia() ? 'academia' : 'liga o escuela' ?>.</p>
    <p>Le mostramos el sistema con un asesor y le creamos su acceso de prueba para <?= es_academia() ? 'su academia' : ($tipo === 'escuela' ? 'su escuela' : 'su liga') ?>, con sus propios datos.</p>
    <ul class="lista-check">
      <li>Demo guiada por WhatsApp o videollamada</li>
      <li>Acceso de prueba preparado para usted</li>
      <li>Le ayudamos a cargar sus <?= $tipo === 'escuela' ? (es_academia() ? 'alumnos y cursos' : 'alumnos y grupos') : 'equipos y su primer campeonato' ?></li>
      <li>Sin compromiso: elige su plan cuando esté listo</li>
    </ul>
    <?php if (!es_academia() && liga_demo()): ?><p>¿Quiere mirar primero? <a href="index.php?v=demo">👀 Vea la liga de demostración</a>.</p><?php endif; ?>
  </div>
  <div class="tarjeta">
  <?php if ($enviada): ?>
    <h2 style="margin-top:0">✓ ¡Solicitud recibida!</h2>
    <p>Gracias, <?= e($enviada['contacto']) ?>. Le contactaremos al <b><?= e($enviada['telefono']) ?></b> para coordinar la demo de <b><?= e($enviada['organizacion']) ?></b>.</p>
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
        <div class="campo-ancho"><label><?= es_academia() ? 'Nombre de la academia *' : 'Nombre de la liga o escuela *' ?></label><input type="text" name="organizacion" required maxlength="120" value="<?= e(post('organizacion')) ?>"></div>
        <div><label>Ciudad / sector</label><input type="text" name="ciudad" maxlength="80" value="<?= e(post('ciudad')) ?>"></div>
        <div><label>Tamaño aproximado</label><input type="text" name="tamano" maxlength="40" value="<?= e(post('tamano')) ?>" placeholder="<?= es_academia() ? 'Ej: 80 alumnos, 3 profesores' : 'Ej: 16 equipos · 80 alumnos' ?>"></div>
        <div><label>Su nombre *</label><input type="text" name="contacto" required maxlength="80" value="<?= e(post('contacto')) ?>" autocomplete="name"></div>
        <div><label>Celular / WhatsApp *</label><input type="tel" name="telefono" required maxlength="30" value="<?= e(post('telefono')) ?>" autocomplete="tel"></div>
        <div class="campo-ancho"><label>Correo</label><input type="email" name="email" value="<?= e(post('email')) ?>" autocomplete="email"></div>
        <?php if ($planes): ?><div><label>Plan que le interesa</label><select name="plan_id"><option value="">Todavía no sé</option><?php foreach ($planes as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= (int)post('plan_id', get('plan')) === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['nombre']) ?> — <?= e(texto_precio_principal($pl)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php $vref = vendedor_del_visitante(get('ref')) ?? vendedor_del_visitante(); ?>
        <div><label>Código de vendedor (opcional)</label><input type="text" name="ref" maxlength="20" value="<?= e(post('ref', $vref['codigo'] ?? '')) ?>" style="text-transform:uppercase" placeholder="Si alguien le recomendó"><?php if ($vref): ?><div class="ayuda">Le atiende: <?= e($vref['nombre']) ?></div><?php endif; ?></div>
        <div class="campo-ancho"><label>¿Algo que debamos saber?</label><textarea name="mensaje" rows="3" maxlength="600" placeholder="<?= es_academia() ? 'Ej: cuándo es su próximo recital, qué le interesa más' : 'Ej: cuándo empieza su campeonato, qué le interesa más' ?>"><?= e(post('mensaje')) ?></textarea></div>
        <div class="campo-ancho"><label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required> Acepto que <?= e(ajuste('empresa', 'QF Producciones')) ?> me contacte por WhatsApp, llamada o correo sobre esta solicitud, y la <a href="index.php?v=privacidad" target="_blank">política de privacidad</a> (datos guardados en servidores fuera del Ecuador).</label></div>
      </div>
      <button class="btn btn-grande" type="submit">📝 Solicitar mi demo</button>
    </form>
    <p class="suave" style="text-align:center">¿Ya tiene cuenta? <a href="admin.php?p=login">Ingresar</a></p>
  <?php endif; ?>
  </div>
</div>
<?php
pie_publico();
