<?php
/*
 * (v33) LISTO PARA VENDER — 4-oct-2026
 *  1. CORREOS AUTOMÁTICOS con plantilla (usa el correo saliente de Ajustes): bienvenida con los accesos, solicitud de demo
 *     recibida (al interesado y al superadministrador), avisos de vencimiento, pago registrado, comprobante aprobado/rechazado.
 *  2. CONTRATAR / RENOVAR POR TRANSFERENCIA desde Mi plan: el cliente elige plan y período, sube la foto del comprobante
 *     y el superadministrador lo aprueba con un clic en Ventas (registra el pago y renueva). Tabla solicitudes_pago (esquema_v29).
 *  3. DEMO SIN DÍAS DE GRACIA (ajuste dias_gracia_demo, por defecto 0): al vencer la demo el panel queda en solo lectura
 *     y se ofrece contratar. Las cuentas pagadas conservan sus días de gracia.
 *  4. COMPARTIR Y SEO: descripción, imagen (og:image), dirección canónica, robots.txt y sitemap.php.
 *  5. CABECERAS DE SEGURIDAD y dirección pública del sistema recordada para los correos y avisos que salen del cron.
 */

/* ============================================================
 * 1) CORREOS
 * ============================================================ */

/** Correos de los superadministradores activos (para avisos de ventas). */
function correos_super(): array
{
    $r = [];
    foreach (filas("SELECT email FROM usuarios WHERE rol = 'super' AND activo = 1") as $u) if (filter_var($u['email'], FILTER_VALIDATE_EMAIL)) $r[] = $u['email'];
    if (filter_var((string)ajuste('ventas_email'), FILTER_VALIDATE_EMAIL) && !in_array(ajuste('ventas_email'), $r, true)) $r[] = (string)ajuste('ventas_email');
    return $r;
}

/** Plantilla HTML sencilla (se ve bien en Gmail, Outlook y el celular). $parrafos: textos (se escapan); $boton: ['texto', 'url'] opcional. */
function correo_html(string $titulo, array $parrafos, ?array $boton = null, string $pie = ''): string
{
    $color = '#0b6e4f';
    $h = '<!doctype html><html><body style="margin:0;padding:0;background:#f3f4f2;font-family:Arial,Helvetica,sans-serif;color:#1f2a26">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f2;padding:24px 0"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">'
        . '<tr><td style="background:' . $color . ';padding:18px 24px;color:#fff;font-size:20px;font-weight:bold">' . e(marca()) . '<span style="font-size:13px;font-weight:normal;opacity:.85"> · Ligas y escuelas deportivas</span></td></tr>'
        . '<tr><td style="padding:24px 24px 8px"><h1 style="margin:0 0 12px;font-size:22px;color:#0f2a22">' . e($titulo) . '</h1>';
    foreach ($parrafos as $p) {
        if (is_array($p)) { // lista de pares [etiqueta, valor]
            $h .= '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:8px 0 14px;font-size:15px">';
            foreach ($p as [$k, $v]) $h .= '<tr><td style="padding:4px 12px 4px 0;color:#5c6b66">' . e($k) . '</td><td style="padding:4px 0"><b>' . e($v) . '</b></td></tr>';
            $h .= '</table>';
        } else {
            $h .= '<p style="margin:0 0 12px;font-size:15px;line-height:1.5">' . nl2br(e($p)) . '</p>';
        }
    }
    if ($boton) $h .= '<p style="margin:18px 0"><a href="' . e($boton[1]) . '" style="display:inline-block;background:' . $color . ';color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;font-size:15px">' . e($boton[0]) . '</a><br><span style="font-size:12px;color:#5c6b66">o copie esta dirección: ' . e($boton[1]) . '</span></p>';
    $h .= '</td></tr><tr><td style="padding:12px 24px 22px;font-size:12px;color:#7a8580;border-top:1px solid #e6e8e4">' . nl2br(e($pie !== '' ? $pie : pie_correo())) . '</td></tr></table></td></tr></table></body></html>';
    return $h;
}

function pie_correo(): string
{
    $wa = trim((string)ajuste('ventas_whatsapp'));
    return marca() . ' · ' . ajuste('empresa') . ($wa !== '' ? ' · WhatsApp ' . $wa : '') . "\n" . rtrim(url_base(), '/') . ' · Este correo se envió automáticamente; si tiene dudas, respóndalo o escríbanos por WhatsApp.';
}

/** Versión en texto plano del mismo correo (para clientes de correo sin HTML). */
function correo_texto(string $titulo, array $parrafos, ?array $boton = null, string $pie = ''): string
{
    $t = $titulo . "\n" . str_repeat('=', mb_strlen($titulo)) . "\n\n";
    foreach ($parrafos as $p) {
        if (is_array($p)) { foreach ($p as [$k, $v]) $t .= $k . ': ' . $v . "\n"; $t .= "\n"; }
        else $t .= $p . "\n\n";
    }
    if ($boton) $t .= $boton[0] . ': ' . $boton[1] . "\n\n";
    return $t . '--' . "\n" . ($pie !== '' ? $pie : pie_correo());
}

/**
 * Envía un correo con la plantilla. Nunca lanza error (si falla queda en la bitácora y en el log). Devuelve true si salió.
 * Si el correo saliente no está configurado, devuelve false sin hacer nada.
 */
function enviar_correo(string $para, string $asunto, string $titulo, array $parrafos, ?array $boton = null, ?int $liga_id = null): bool
{
    if (!smtp_configurado() || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    try {
        smtp_enviar($para, $asunto, correo_texto($titulo, $parrafos, $boton), correo_html($titulo, $parrafos, $boton));
        return true;
    } catch (Throwable $ex) {
        error_log('correo a ' . $para . ': ' . $ex->getMessage());
        try { bitacora_evento('correo', 'No se pudo enviar el correo «' . $asunto . '» a ' . $para . ': ' . $ex->getMessage(), $liga_id); } catch (Throwable) {}
        return false;
    }
}

/** Correo de bienvenida con los accesos (al crear una cuenta de liga, escuela o federación). */
function correo_bienvenida(array $liga, string $email, string $clave, string $nombre_admin): bool
{
    $tipo = ($liga['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : (!empty($liga['es_federacion']) ? 'federación' : 'liga');
    $s = estado_suscripcion($liga);
    $base = rtrim(url_base(), '/');
    $parrafos = [
        'Hola ' . $nombre_admin . ', ya está lista la cuenta de ' . $liga['nombre'] . ' en ' . marca() . '. Estos son sus datos para entrar:',
        [['Dirección del panel', $base . '/admin.php'], ['Usuario (correo)', $email], ['Contraseña', $clave !== '' ? $clave : '(la misma de su otra cuenta)']],
        $s['estado'] === 'prueba' ? '🎁 Su demo dura hasta el ' . $liga['vence'] . ' con todos los módulos. Cuando quiera seguir, elija su plan en el menú ⭐ Mi plan: no pierde nada de lo que cargue.' : ($s['estado'] === 'activa' ? 'Su plan está activo hasta el ' . $liga['vence'] . '.' : ''),
        'Su página pública' . ($tipo === 'escuela' ? ' (grupos, horarios e inscripción en línea)' : ' (resultados, tabla y fixture)') . ': ' . url_liga($liga),
        'Le recomendamos cambiar la contraseña la primera vez (menú 👤 Mi cuenta). El manual paso a paso está en ' . $base . '/manual/' . ($tipo === 'escuela' ? 'escuelas' : 'ligas') . ' y el botón ❓ Ayuda del panel abre la parte que explica cada pantalla.',
    ];
    return enviar_correo($email, 'Bienvenido a ' . marca() . ': accesos de ' . $liga['nombre'], '¡Bienvenido a ' . marca() . '!', array_values(array_filter($parrafos, fn($p) => $p !== '')), ['Entrar al panel', $base . '/admin.php'], (int)$liga['id']);
}

/** Al recibir una solicitud de demo: confirmación al interesado y aviso al superadministrador (además de Telegram). */
function correos_solicitud_demo(array $s): void
{
    $wa = trim((string)ajuste('ventas_whatsapp'));
    if (filter_var((string)$s['email'], FILTER_VALIDATE_EMAIL)) {
        enviar_correo((string)$s['email'], 'Recibimos su solicitud de demo de ' . marca(), 'Recibimos su solicitud', [
            'Hola ' . $s['contacto'] . ', gracias por su interés en ' . marca() . ' para ' . $s['organizacion'] . '.',
            'En las próximas horas le escribimos por WhatsApp' . ($s['telefono'] !== '' ? ' al ' . $s['telefono'] : '') . ' para coordinar una demostración corta y crearle su acceso de prueba de ' . (int)ajuste('dias_demo', 7) . ' días con todos los módulos.',
            'Mientras tanto puede mirar la liga de demostración y los manuales:',
        ], ['Ver la demostración', rtrim(url_base(), '/') . '/demo']);
    }
    foreach (correos_super() as $c) {
        enviar_correo($c, '📝 Solicitud de demo: ' . $s['organizacion'], 'Nueva solicitud de demo', [
            [['Tipo', $s['tipo'] === 'escuela' ? 'Escuela' : 'Liga / campeonato'], ['Organización', $s['organizacion'] . ($s['ciudad'] !== '' ? ' (' . $s['ciudad'] . ')' : '')], ['Contacto', $s['contacto']], ['Celular', $s['telefono']], ['Correo', $s['email'] !== '' ? $s['email'] : '—'], ['Tamaño', $s['tamano'] !== '' ? $s['tamano'] : '—']],
            $s['mensaje'] !== '' ? 'Mensaje: «' . $s['mensaje'] . '»' : '',
        ], ['Atender la solicitud', rtrim(url_base(), '/') . '/admin.php?p=demos']);
    }
}

/** Aviso de vencimiento por correo al contacto de la cuenta (lo llama avisos_diarios). */
function correo_vencimiento(array $l, array $s): void
{
    if (!filter_var((string)$l['contacto_email'], FILTER_VALIDATE_EMAIL)) return;
    $prueba = !empty($l['es_prueba']);
    $que = $prueba ? 'Su demo' : 'Su suscripción';
    $cuando = $s['dias'] > 0 ? 'vence en ' . $s['dias'] . ' día' . ($s['dias'] === 1 ? '' : 's') . ' (el ' . $l['vence'] . ')' : ($s['dias'] === 0 ? 'vence HOY' : 'venció ayer');
    $base = rtrim(url_base(), '/');
    $p = [
        'Hola' . (trim((string)$l['contacto_nombre']) !== '' ? ' ' . $l['contacto_nombre'] : '') . ', ' . mb_strtolower($que) . ' de ' . marca() . ' para ' . $l['nombre'] . ' ' . $cuando . '.',
        $prueba ? 'Todo lo que cargó queda guardado: al contratar su plan sigue exactamente donde se quedó. Si no renueva, el panel pasa a solo lectura y su página pública sigue visible.' : 'Para que el panel no pase a solo lectura, renueve antes del vencimiento.',
    ];
    if (($v = texto_valor_renovacion($l)) !== '') $p[] = $v . '.';
    if (($d = trim((string)ajuste('ventas_datos_pago'))) !== '') $p[] = "Datos para pagar por transferencia:\n" . $d . "\n\nDespués de pagar, suba la foto del comprobante en ⭐ Mi plan → Contratar o renovar; lo activamos el mismo día.";
    enviar_correo((string)$l['contacto_email'], ($s['dias'] >= 0 ? '⏰ ' : '⚠️ ') . $que . ' de ' . marca() . ' ' . $cuando, $que . ' ' . $cuando, $p, [$prueba ? 'Contratar mi plan' : 'Renovar ahora', $base . '/admin.php?p=mi_plan'], (int)$l['id']);
}

/** Confirmación de pago registrado (renovación hecha). */
function correo_pago_registrado(array $l, array $pago, ?array $plan): void
{
    if (!filter_var((string)$l['contacto_email'], FILTER_VALIDATE_EMAIL)) return;
    enviar_correo((string)$l['contacto_email'], '✅ Pago recibido: ' . $l['nombre'] . ' activa hasta el ' . $pago['hasta'], '¡Gracias por su pago!', [
        'Registramos su pago y la cuenta de ' . $l['nombre'] . ' en ' . marca() . ' queda activa.',
        [['Plan', $plan ? $plan['nombre'] : '—'], ['Meses', (string)(int)$pago['meses']], ['Valor', dinero((float)$pago['monto'])], ['Vigencia', $pago['desde'] . ' → ' . $pago['hasta']], ['Forma de pago', nombre_forma_pago($pago['forma_pago'])]],
        'Este correo le sirve como constancia. Si necesita factura, respóndanos con su RUC o cédula y razón social.',
    ], ['Ir a mi panel', rtrim(url_base(), '/') . '/admin.php?p=mi_plan'], (int)$l['id']);
}

/* ============================================================
 * 2) CONTRATAR / RENOVAR POR TRANSFERENCIA (comprobante)
 * ============================================================ */

function estados_solicitud_pago(): array
{
    return ['pendiente' => 'Por revisar', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada'];
}

/** Comprobantes pendientes de revisar (para el menú del superadministrador). */
function pagos_por_revisar(): int
{
    return (int)valor("SELECT COUNT(*) FROM solicitudes_pago WHERE estado = 'pendiente'");
}

/** El cliente envía su comprobante desde Mi plan. Devuelve la solicitud creada. */
function crear_solicitud_pago(array $liga, array $yo, int $plan_id, int $meses, string $forma, string $nota, ?array $deportes = null): array
{
    $tipo = ($liga['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
    $pl = fila("SELECT * FROM planes WHERE id = ? AND activo = 1 AND tipo_cuenta IN ('ambos', ?)", [$plan_id, $tipo]) ?? throw new RuntimeException('Elija el plan que quiere contratar.');
    if ($deportes !== null && !$deportes) throw new RuntimeException('Elija al menos un deporte (módulo deportivo).');
    $op = null;
    foreach (opciones_precio($pl, $liga, $deportes) as $o) if ($o['meses'] === $meses) $op = $o;
    if (!$op) throw new RuntimeException('Elija por cuánto tiempo va a pagar.');
    if (fila("SELECT id FROM solicitudes_pago WHERE liga_id = ? AND estado = 'pendiente'", [$liga['id']])) throw new RuntimeException('Ya tiene un comprobante por revisar. Le avisamos apenas lo aprobemos (normalmente el mismo día).');
    $fotos = subir_fotos_privadas('comprobante', 1600);
    if (!$fotos) throw new RuntimeException('Adjunte la foto o captura del comprobante de la transferencia (JPG o PNG).');
    q('INSERT INTO solicitudes_pago (liga_id, plan_id, meses, monto, forma_pago, archivo, nota, enviado_por) VALUES (?,?,?,?,?,?,?,?)', [
        $liga['id'], $pl['id'], $meses, $op['precio'], array_key_exists($forma, formas_pago()) ? $forma : 'transferencia', $fotos[0], mb_substr($nota, 0, 300), mb_substr($yo['nombre'] . ' <' . $yo['email'] . '>', 0, 160),
    ]);
    $id = (int)db()->lastInsertId();
    if ($deportes) q('UPDATE solicitudes_pago SET deportes = ? WHERE id = ?', [implode(',', $deportes), $id]); // (v35) módulos que contrata
    bitacora_evento('ventas', 'Envió el comprobante para ' . ($liga['es_prueba'] ? 'contratar' : 'renovar') . ' el plan ' . $pl['nombre'] . ' por ' . $meses . ' mes(es), ' . dinero($op['precio']), (int)$liga['id'], $id);
    $txt = '💵 <b>Comprobante de pago por revisar</b>' . "\n" . e($liga['nombre']) . ' (' . ($tipo === 'escuela' ? 'escuela' : 'liga') . ($liga['es_prueba'] ? ', en demo' : '') . ")\nPlan " . e($pl['nombre']) . ' · ' . $meses . ' mes(es) · <b>' . dinero($op['precio']) . '</b>'
        . ($deportes ? "\n🧩 " . e(texto_modulos($deportes)) : '') . ($nota !== '' ? "\n📝 " . e(mb_substr($nota, 0, 200)) : '') . "\n\nAprobar: " . url_base() . 'admin.php?p=suscripciones#comprobantes';
    aviso_super($txt, 'pago_rev:' . $id);
    foreach (correos_super() as $c) {
        enviar_correo($c, '💵 Comprobante por revisar: ' . $liga['nombre'] . ' · ' . dinero($op['precio']), 'Comprobante de pago por revisar', [
            [['Cuenta', $liga['nombre']], ['Plan', $pl['nombre']], ['Deportes', $deportes ? texto_modulos($deportes) : texto_modulos(deportes_cobrados($liga))], ['Meses', (string)$meses], ['Valor', dinero($op['precio'])], ['Enviado por', $yo['nombre'] . ' <' . $yo['email'] . '>'], ['Nota', $nota !== '' ? $nota : '—']],
            'Revise la foto del comprobante contra su cuenta bancaria y apruébelo con un clic: el sistema registra el pago, renueva la vigencia y le avisa al cliente.',
        ], ['Revisar en Ventas', rtrim(url_base(), '/') . '/admin.php?p=suscripciones#comprobantes']);
    }
    return fila('SELECT * FROM solicitudes_pago WHERE id = ?', [$id]);
}

/** El superadministrador aprueba: registra el pago (renueva) y avisa al cliente. */
function aprobar_solicitud_pago(int $id, string $por, float $monto, string $nota): array
{
    $s = fila("SELECT * FROM solicitudes_pago WHERE id = ? AND estado = 'pendiente'", [$id]) ?? throw new RuntimeException('Esa solicitud ya fue atendida.');
    $l = fila('SELECT * FROM ligas WHERE id = ?', [$s['liga_id']]) ?? throw new RuntimeException('La cuenta ya no existe.');
    $r = registrar_pago_suscripcion($l, (int)$s['plan_id'], (int)$s['meses'], $monto, $s['forma_pago'], trim(($nota !== '' ? $nota . ' · ' : '') . 'comprobante #' . $id), date('Y-m-d'), $por);
    if (($s['deportes'] ?? '') !== '') { // (v35) se activan los módulos deportivos que contrató
        $ds = array_values(array_intersect(array_keys(modulos_deporte_base()), explode(',', $s['deportes'])));
        if ($ds) q('UPDATE ligas SET deportes = ? WHERE id = ?', [count($ds) === count(modulos_deporte_base()) ? '*' : implode(',', $ds), $l['id']]);
    }
    q("UPDATE solicitudes_pago SET estado = 'aprobada', pago_id = ?, atendido_por = ?, atendido_en = ?, respuesta = ? WHERE id = ?", [$r['pago_id'], $por, date('Y-m-d H:i'), mb_substr($nota, 0, 300), $id]);
    $pago = fila('SELECT * FROM pagos_suscripcion WHERE id = ?', [$r['pago_id']]);
    $l2 = fila('SELECT * FROM ligas WHERE id = ?', [$l['id']]);
    correo_pago_registrado($l2, $pago, fila('SELECT * FROM planes WHERE id = ?', [(int)$s['plan_id']]));
    return $r;
}

/** El superadministrador rechaza (comprobante ilegible, valor distinto…): el cliente puede volver a enviar. */
function rechazar_solicitud_pago(int $id, string $por, string $motivo): void
{
    $s = fila("SELECT * FROM solicitudes_pago WHERE id = ? AND estado = 'pendiente'", [$id]) ?? throw new RuntimeException('Esa solicitud ya fue atendida.');
    if (mb_strlen(trim($motivo)) < 5) throw new RuntimeException('Escriba el motivo para que el cliente sepa qué corregir.');
    q("UPDATE solicitudes_pago SET estado = 'rechazada', atendido_por = ?, atendido_en = ?, respuesta = ? WHERE id = ?", [$por, date('Y-m-d H:i'), mb_substr($motivo, 0, 300), $id]);
    $l = fila('SELECT * FROM ligas WHERE id = ?', [$s['liga_id']]);
    if (!$l) return;
    bitacora_evento('ventas', 'Comprobante #' . $id . ' rechazado: ' . $motivo, (int)$l['id'], $id);
    foreach (tg_chats_admins((int)$l['id']) as $c) tg_encolar((int)$l['id'], $c, '⚠️ <b>Comprobante no aprobado</b>' . "\n" . e($motivo) . "\n\nPuede volver a enviarlo en Mi plan: " . url_base() . 'admin.php?p=mi_plan');
    if (filter_var((string)$l['contacto_email'], FILTER_VALIDATE_EMAIL)) {
        enviar_correo((string)$l['contacto_email'], 'Su comprobante de pago necesita una corrección', 'Comprobante no aprobado', [
            'Hola, revisamos el comprobante que envió para ' . $l['nombre'] . ' y no pudimos aprobarlo por este motivo:', '«' . $motivo . '»',
            'Puede volver a enviarlo desde ⭐ Mi plan → Contratar o renovar. Si tiene dudas, escríbanos por WhatsApp.',
        ], ['Volver a enviar', rtrim(url_base(), '/') . '/admin.php?p=mi_plan'], (int)$l['id']);
    }
}

/** Foto del comprobante (privada: solo el superadministrador y la propia cuenta). */
function servir_comprobante(array $s): never
{
    $abs = RAIZ . '/data/' . $s['archivo'];
    if ($s['archivo'] === '' || str_contains($s['archivo'], '..') || !is_file($abs)) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($abs);
    exit;
}

/** Formulario "Contratar o renovar por transferencia" (Mi plan). */
function html_contratar_transferencia(array $liga, array $planes): string
{
    $pend = fila("SELECT * FROM solicitudes_pago WHERE liga_id = ? AND estado = 'pendiente' ORDER BY id DESC", [$liga['id']]);
    $hist = filas("SELECT s.*, p.nombre AS plan FROM solicitudes_pago s LEFT JOIN planes p ON p.id = s.plan_id WHERE s.liga_id = ? ORDER BY s.id DESC LIMIT 6", [$liga['id']]);
    $datos = trim((string)ajuste('ventas_datos_pago'));
    $wa = telefono_wa((string)ajuste('ventas_whatsapp'));
    $actual = plan_de_liga($liga);
    $prueba = !empty($liga['es_prueba']);
    ob_start(); ?>
<div class="tarjeta" id="contratar">
  <h2 style="margin-top:0">💵 <?= $prueba ? 'Contratar mi plan' : 'Renovar o cambiar de plan' ?> por transferencia</h2>
  <?php if ($pend): $pp = fila('SELECT nombre FROM planes WHERE id = ?', [$pend['plan_id']]); ?>
    <div class="aviso aviso-info">⏳ <b>Su comprobante está en revisión</b> (plan <?= e($pp['nombre'] ?? '') ?>, <?= (int)$pend['meses'] ?> mes(es), <?= dinero((float)$pend['monto']) ?>, enviado el <?= e(substr($pend['creado'], 0, 16)) ?>). Le avisamos apenas lo aprobemos, normalmente el mismo día.<?= $wa ? ' Si es urgente, <a target="_blank" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode('Hola, envié el comprobante de ' . $liga['nombre'] . ' en ' . marca() . '. ¿Me lo pueden revisar?') . '">escríbanos por WhatsApp</a>.' : '' ?></div>
  <?php else: ?>
    <ol class="pasos-pago">
      <li><b>Elija el plan y el tiempo</b> que va a pagar.</li>
      <li><b>Transfiera</b> el valor<?= $datos !== '' ? ' a:<br><span class="datos-pago">' . nl2br(e($datos)) . '</span>' : ($wa ? ' (pida los datos de la cuenta por <a target="_blank" href="https://wa.me/' . e($wa) . '">WhatsApp</a>)' : '') ?></li>
      <li><b>Suba la foto o captura</b> del comprobante y envíe. Lo revisamos y su cuenta queda activa el mismo día<?= $prueba ? ', con todo lo que ya cargó' : '' ?>.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" class="form-contratar" data-desc="<?= e((string)modulo_descuento()) ?>" data-incluido="<?= modulo_incluido() ? 1 : 0 ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="comprobante">
      <label>🧩 Deportes que quiere tener <small class="suave">(el plan incluye uno; cada deporte adicional con <?= e(rtrim(rtrim(number_format(modulo_descuento(), 1, ',', ''), '0'), ',')) ?> % de descuento)</small></label>
      <?= html_casillas_modulos(deportes_cobrados($liga), 'deportes', true) ?>
      <div class="campos">
        <div class="campo-ancho"><label>Plan y tiempo</label>
          <select name="plan_meses" required>
            <?php foreach ($planes as $pl): foreach (opciones_precio($pl, $liga) as $o): $k = (int)$pl['id'] . ':' . $o['meses']; $sel = $actual && (int)$actual['id'] === (int)$pl['id'] && $o['meses'] === 1 && !$prueba; ?>
              <option value="<?= $k ?>" <?= $sel ? 'selected' : '' ?> data-base="<?= e((string)$o['base']) ?>" data-meses="<?= (int)$o['meses'] ?>" data-rebaja="<?= e((string)$o['rebaja']) ?>" data-factor="<?= e((string)$o['factor']) ?>"><?= e($pl['nombre']) ?> · <?= e($o['nombre']) ?>: <?= dinero($o['precio']) ?><?= $o['gratis'] ? ' (' . $o['gratis'] . ' mes' . ($o['gratis'] === 1 ? '' : 'es') . ' gratis)' : '' ?><?= $o['dominio'] ? ' + dominio gratis' : '' ?><?= $o['combo'] ? ' · combo' : '' ?></option>
            <?php endforeach; endforeach; ?>
          </select></div>
        <div><label>Forma de pago</label><select name="forma_pago"><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option><option value="efectivo">Efectivo</option><option value="otro">Otro</option></select></div>
        <div><label>Foto o captura del comprobante</label><input type="file" name="comprobante" accept="image/*" required></div>
        <div class="campo-ancho"><label>Nota (opcional)</label><input type="text" name="nota" maxlength="300" placeholder="N° de transferencia, banco, nombre de quien pagó, RUC para la factura…"></div>
      </div>
      <p class="total-contratar" style="font-size:1.1rem;margin:.4rem 0">Valor a transferir: <b class="total-valor">—</b> <small class="suave total-detalle"></small></p>
      <button class="btn btn-grande">📤 Enviar comprobante</button>
    </form>
    <script>
    (function () { // (v35) total con los deportes elegidos (mismo cálculo que el servidor)
      var f = document.querySelector('.form-contratar'); if (!f) return;
      var desc = parseFloat(f.dataset.desc) || 0, incl = f.dataset.incluido === '1';
      function calc() {
        var o = f.elements.plan_meses.options[f.elements.plan_meses.selectedIndex]; if (!o || !o.dataset.base) return;
        var p = [].slice.call(f.querySelectorAll('input[name="deportes[]"]:checked')).map(function (c) { return parseFloat(c.dataset.precio) || 0; }).sort(function (a, b) { return b - a; });
        var extra = 0; p.forEach(function (v, i) { if (i === 0 && incl) return; extra += Math.round(v * (1 - (i === 0 ? 0 : desc) / 100) * 100) / 100; });
        var m = parseInt(o.dataset.meses, 10), reb = parseFloat(o.dataset.rebaja), fac = parseFloat(o.dataset.factor);
        var total = Math.round((parseFloat(o.dataset.base) + Math.round(extra * m * reb * 100) / 100) * fac * 100) / 100;
        f.querySelector('.total-valor').textContent = p.length ? '$' + total.toFixed(2) : 'elija al menos un deporte';
        f.querySelector('.total-detalle').textContent = p.length ? '(' + p.length + ' deporte' + (p.length > 1 ? 's' : '') + ', ' + m + ' mes' + (m > 1 ? 'es' : '') + ')' : '';
      }
      f.addEventListener('change', calc); calc();
    })();
    </script>
  <?php endif; ?>
  <?php if ($hist): ?>
    <h3>Comprobantes enviados</h3>
    <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Plan</th><th class="num">Meses</th><th class="num">Valor</th><th>Estado</th><th>Respuesta</th></tr>
    <?php foreach ($hist as $h): ?><tr><td><?= e(substr($h['creado'], 0, 16)) ?></td><td><?= e($h['plan'] ?? '—') ?></td><td class="num"><?= (int)$h['meses'] ?></td><td class="num"><?= dinero((float)$h['monto']) ?></td>
      <td><span class="chip <?= ['pendiente' => 'chip-pend', 'aprobada' => 'chip-ok', 'rechazada' => 'chip-mal'][$h['estado']] ?? '' ?>"><?= e(estados_solicitud_pago()[$h['estado']] ?? $h['estado']) ?></span></td><td style="white-space:normal"><?= e($h['respuesta']) ?></td></tr><?php endforeach; ?>
    </table></div>
  <?php endif; ?>
</div>
<?php return (string)ob_get_clean();
}

/** Lista de comprobantes por revisar (Ventas, superadministrador). */
function html_comprobantes_por_revisar(): string
{
    $lista = filas("SELECT s.*, l.nombre AS liga, l.es_prueba, l.vence, l.tipo, p.nombre AS plan FROM solicitudes_pago s JOIN ligas l ON l.id = s.liga_id LEFT JOIN planes p ON p.id = s.plan_id WHERE s.estado = 'pendiente' ORDER BY s.id");
    $ult = filas("SELECT s.*, l.nombre AS liga, p.nombre AS plan FROM solicitudes_pago s JOIN ligas l ON l.id = s.liga_id LEFT JOIN planes p ON p.id = s.plan_id WHERE s.estado <> 'pendiente' ORDER BY s.atendido_en DESC LIMIT 8");
    ob_start(); ?>
<div class="tarjeta" id="comprobantes">
  <h2 style="margin-top:0">📤 Comprobantes enviados por los clientes <?= $lista ? '<span class="etiqueta roja">' . count($lista) . ' por revisar</span>' : '' ?></h2>
  <?php if (!$lista): ?><p class="suave">No hay comprobantes por revisar. Los clientes los envían desde ⭐ Mi plan → Contratar o renovar.</p><?php endif; ?>
  <?php foreach ($lista as $s): ?>
    <div class="comprobante-fila">
      <a href="admin.php?p=suscripciones&amp;comprobante=<?= (int)$s['id'] ?>" target="_blank"><img src="admin.php?p=suscripciones&amp;comprobante=<?= (int)$s['id'] ?>" alt="Comprobante" class="comprobante-mini"></a>
      <div style="flex:1">
        <p style="margin:0 0 4px"><b><?= e($s['liga']) ?></b> <small class="suave">(<?= ($s['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga' ?><?= $s['es_prueba'] ? ' · en demo' : '' ?> · vence <?= e($s['vence'] ?: '—') ?>)</small></p>
        <p style="margin:0 0 4px">Plan <b><?= e($s['plan'] ?? '—') ?></b><?= ($s['deportes'] ?? '') !== '' ? ' · 🧩 ' . e(texto_modulos(explode(',', $s['deportes']))) : '' ?> · <?= (int)$s['meses'] ?> mes(es) · <b><?= dinero((float)$s['monto']) ?></b> · <?= e(formas_pago()[$s['forma_pago']] ?? $s['forma_pago']) ?> · enviado el <?= e(substr($s['creado'], 0, 16)) ?> por <?= e($s['enviado_por']) ?></p>
        <?php if ($s['nota'] !== ''): ?><p style="margin:0 0 6px" class="suave">📝 <?= e($s['nota']) ?></p><?php endif; ?>
        <form method="post" class="acciones" style="gap:6px;align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <div><label style="font-size:.8rem">Valor recibido ($)</label><input type="number" name="monto" step="0.01" min="0" value="<?= e((string)(float)$s['monto']) ?>" style="width:110px"></div>
          <div style="flex:1;min-width:180px"><label style="font-size:.8rem">Nota o motivo (si rechaza)</label><input type="text" name="nota" maxlength="300" placeholder="Ej: no coincide el valor / comprobante ilegible"></div>
          <button class="btn btn-chico" name="accion" value="aprobar_comprobante" onclick="return confirm('¿Aprobar y renovar <?= e(addslashes($s['liga'])) ?> por <?= (int)$s['meses'] ?> mes(es)?')">✅ Aprobar y renovar</button>
          <button class="btn btn-sec btn-chico" name="accion" value="rechazar_comprobante">✖ Rechazar</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($ult): ?>
    <details><summary class="suave">Últimos atendidos</summary><div class="tabla-scroll"><table><tr><th>Fecha</th><th>Cuenta</th><th>Plan</th><th class="num">Valor</th><th>Estado</th><th>Por</th><th>Respuesta</th></tr>
      <?php foreach ($ult as $h): ?><tr><td><?= e($h['atendido_en']) ?></td><td><?= e($h['liga']) ?></td><td><?= e($h['plan'] ?? '—') ?> · <?= (int)$h['meses'] ?> m</td><td class="num"><?= dinero((float)$h['monto']) ?></td><td><?= e(estados_solicitud_pago()[$h['estado']] ?? $h['estado']) ?></td><td><?= e($h['atendido_por']) ?></td><td style="white-space:normal"><?= e($h['respuesta']) ?></td></tr><?php endforeach; ?>
    </table></div></details>
  <?php endif; ?>
</div>
<?php return (string)ob_get_clean();
}

/* ============================================================
 * 3) DEMO SIN GRACIA — aviso con botón para contratar
 * ============================================================ */

/** Días de gracia que corresponden a una cuenta (las demos no tienen, salvo que el superadministrador lo cambie). */
function dias_gracia_de(array $liga): int
{
    return !empty($liga['es_prueba']) ? max(0, (int)ajuste('dias_gracia_demo', 0)) : max(0, (int)ajuste('dias_gracia', 7));
}

/* ============================================================
 * 4) COMPARTIR Y SEO
 * ============================================================ */

/** Descripción para buscadores y para la vista previa de WhatsApp/Facebook (la página puede fijar $GLOBALS['meta_descripcion']). */
function meta_descripcion(?array $liga): string
{
    if (!empty($GLOBALS['meta_descripcion'])) return mb_substr((string)$GLOBALS['meta_descripcion'], 0, 300);
    if ($liga) {
        if (es_escuela($liga)) return 'Grupos, horarios, sedes e inscripción en línea de ' . $liga['nombre'] . ($liga['ciudad'] !== '' ? ' (' . $liga['ciudad'] . ')' : '') . '. Portal de la familia con asistencia, pagos y evaluaciones.';
        if (es_federacion($liga)) return 'Ligas y escuelas afiliadas, partidos de la semana y resultados de ' . $liga['nombre'] . ($liga['ciudad'] !== '' ? ' (' . $liga['ciudad'] . ')' : '') . '.';
        return 'Resultados en vivo, tabla de posiciones, fixture, goleadores y sanciones de ' . $liga['nombre'] . ($liga['ciudad'] !== '' ? ' (' . $liga['ciudad'] . ')' : '') . '.';
    }
    return mb_substr((string)ajuste('landing_subtitulo'), 0, 300) ?: 'Sistema para ligas, campeonatos y escuelas deportivas: fixture, vocalía en vivo, carnets con QR, finanzas y página pública.';
}

/** Imagen de la vista previa al compartir (logo de la liga si tiene; si no, la imagen de la marca). */
function meta_imagen(?array $liga): string
{
    $base = url_base();
    if ($liga && ($liga['logo'] ?? '') !== '' && is_file(PUBLIC_DIR . '/' . $liga['logo'])) return $base . ltrim($liga['logo'], '/');
    return $base . 'assets/og.png';
}

/** Dirección canónica de la página actual (sin parámetros de seguimiento). */
function url_canonica(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $p = parse_url($uri);
    $q = [];
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        foreach (array_keys($q) as $k) if (in_array($k, ['lang', 'rec', 'ref', 'utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'listo'], true)) unset($q[$k]);
    }
    return rtrim(url_base(), '/') . ($p['path'] ?? '/') . ($q ? '?' . http_build_query($q) : '');
}

/** Etiquetas <meta> para buscadores y redes (se imprimen en la cabecera pública). */
function html_metas_compartir(string $titulo, ?array $liga): string
{
    $d = meta_descripcion($liga);
    $img = meta_imagen($liga);
    $url = url_canonica();
    $noindex = $liga && (!empty($liga['es_demo']) || (int)($liga['activa'] ?? 1) !== 1);
    return '<meta name="description" content="' . e($d) . '">' . "\n"
        . '<link rel="canonical" href="' . e($url) . '">' . "\n"
        . ($noindex ? '<meta name="robots" content="noindex,follow">' . "\n" : '')
        . '<meta property="og:type" content="website">' . "\n"
        . '<meta property="og:site_name" content="' . e(marca()) . '">' . "\n"
        . '<meta property="og:url" content="' . e($url) . '">' . "\n"
        . '<meta property="og:description" content="' . e($d) . '">' . "\n"
        . '<meta property="og:image" content="' . e($img) . '">' . "\n"
        . '<meta property="og:locale" content="es_EC">' . "\n"
        . '<meta name="twitter:card" content="summary_large_image">' . "\n"
        . '<meta name="twitter:title" content="' . e($titulo) . '">' . "\n"
        . '<meta name="twitter:description" content="' . e($d) . '">' . "\n"
        . '<meta name="twitter:image" content="' . e($img) . '">' . "\n";
}

/** Mapa del sitio (sitemap.php): páginas de venta, manuales y las páginas públicas de las cuentas activas que están en el directorio. */
function xml_sitemap(): string
{
    $base = rtrim(url_base(), '/');
    $hoy = date('Y-m-d');
    $urls = [[$base . '/', '1.0', 'weekly'], [$base . '/escuelas', '0.9', 'weekly'], [$base . '/planes', '0.8', 'monthly'], [$base . '/comparar', '0.6', 'monthly'], [$base . '/demo', '0.8', 'weekly'],
             [$base . '/solicitar-demo', '0.9', 'monthly'], [$base . '/manual/ligas', '0.5', 'monthly'], [$base . '/manual/escuelas', '0.5', 'monthly'], [$base . '/privacidad', '0.2', 'yearly'], [$base . '/index.php?v=directorio', '0.5', 'weekly']];
    foreach (filas("SELECT slug, dominio FROM ligas WHERE activa = 1 AND es_demo = 0 AND en_directorio = 1 ORDER BY id") as $l) {
        $urls[] = [($l['dominio'] ?? '') !== '' ? 'https://' . $l['dominio'] . '/' : $base . '/' . rawurlencode($l['slug']), '0.6', 'daily'];
    }
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as [$u, $pr, $fr]) $x .= '  <url><loc>' . e($u) . '</loc><lastmod>' . $hoy . '</lastmod><changefreq>' . $fr . '</changefreq><priority>' . $pr . '</priority></url>' . "\n";
    return $x . '</urlset>' . "\n";
}

/* ============================================================
 * 5) CABECERAS DE SEGURIDAD y dirección pública recordada
 * ============================================================ */

/** Cabeceras HTTP de seguridad (todas las páginas; el widget y el calendario se pueden incrustar en otras webs). */
function cabeceras_seguridad(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), payment=()');
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, ['widget.php', 'calendario.php', 'imagen.php', 'foto.php'], true)) header('X-Frame-Options: SAMEORIGIN');
}

/**
 * Recuerda la dirección pública del sistema (https://tuvocalia.com/) la primera vez que entra el superadministrador,
 * para que los correos y avisos que salen de la tarea automática (sin navegador) tengan enlaces correctos.
 */
function recordar_url_sitio(): void
{
    if (PHP_SAPI === 'cli' || !es_super()) return;
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || preg_match('/^(localhost|127\.|10\.|192\.168\.|\[)/', $host) || !str_contains($host, '.')) return;
    $u = url_base();
    if (ajuste('url_sitio') !== $u) guardar_ajuste('url_sitio', $u);
}
