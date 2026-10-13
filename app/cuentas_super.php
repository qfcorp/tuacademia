<?php
/*
 * (Tukán Central v2) CUENTAS DE LOS CLIENTES — funciones del superadministrador que usan las pantallas del programa
 * (Ligas, Ventas, Embudo, Protección de datos) Y el conector con el Panel Tukán Central (app/tukan_central.php).
 * Así las dos partes aplican las mismas reglas. El mismo archivo sirve para TuVocalía y TuAcademia.
 *
 *  - vigencia_pago()             desde / hasta que deja un pago (la regla de registrar_pago_suscripcion)
 *  - crear_demo_cuenta()         crea una cuenta (demo o no) con su dueño, la solicitud de demo, el referido y el correo
 *  - mensaje_accesos_cuenta()    texto de WhatsApp con los accesos de una cuenta nueva
 *  - extender_prueba_cuenta()    alarga la demo (1 a 90 días); se niega si la cuenta ya paga
 *  - venta_registrar_pago()      registra un pago (vigencia, comisión, referidos, correo al cliente)
 *  - anular_pago_suscripcion()   borra un pago y devuelve la fecha de vencimiento a la del pago anterior
 *  - cambiar_plan_cuenta(), suspender_cuenta(), reactivar_cuenta(), nueva_clave_dueno_cuenta()
 *  - responder_solicitud_derecho()  responde una solicitud LOPDP (la usa Protección de datos)
 */

/** Plan que corresponde a una cuenta según su tipo: los de 'ambos' o los de su tipo (liga / escuela). */
function plan_para_cuenta(array $l, int $plan_id): ?array
{
    $tipo = ($l['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
    return fila("SELECT * FROM planes WHERE id = ? AND COALESCE(NULLIF(tipo_cuenta, ''), 'ambos') IN ('ambos', ?)", [$plan_id, $tipo]);
}

/**
 * Vigencia que deja un pago de N meses: se suma desde el vencimiento actual si aún no vence (y no es demo), o desde hoy.
 * Con $inicio (AAAA-MM-DD) empieza ese día. Devuelve [desde, hasta].
 */
function vigencia_pago(array $l, int $meses, ?string $inicio = null): array
{
    $hoy = date('Y-m-d');
    if ($inicio !== null && $inicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) {
        return [$inicio, date('Y-m-d', strtotime($inicio . ' +' . $meses . ' months'))];
    }
    $base = (($l['vence'] ?? '') !== '' && $l['vence'] >= $hoy && empty($l['es_prueba'])) ? $l['vence'] : $hoy;
    $hasta = date('Y-m-d', strtotime($base . ' +' . $meses . ' months'));
    $desde = date('Y-m-d', strtotime($base . ($base === $hoy ? '' : ' +1 day')));
    return [$desde, $hasta];
}

/** Contraseña al azar de 10 caracteres sin letras que se confunden (0/O, 1/l/I). */
function clave_al_azar(int $largo = 10): string
{
    $abc = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < $largo; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
    return $c;
}

/** Mensaje de WhatsApp con los accesos de una cuenta recién creada (el mismo de Ligas → Crear). */
function mensaje_accesos_cuenta(array $lc, string $admin, string $email, string $clave, string $combo = ''): string
{
    $es_esc = ($lc['tipo'] ?? 'liga') === 'escuela';
    if ($combo !== '') {
        return "¡Hola " . $admin . "! Su " . ($es_esc ? 'escuela' : 'liga') . " *" . $lc['nombre'] . "* ya está lista en " . marca() . " en COMBO con " . $combo . " (" . (float)combo_descuento() . "% de descuento en los dos planes).\n\n"
            . "🔐 Entre con su mismo usuario de siempre (" . $email . ") en " . url_base() . "admin.php y toque arriba \"🔁 Ir a " . $lc['nombre'] . "\".\n🌐 Página pública: " . url_liga($lc);
    }
    return "¡Hola " . $admin . "! Su " . ($es_esc ? 'escuela' : 'liga') . " *" . $lc['nombre'] . "* ya está lista en " . marca() . ".\n\n"
        . "🌐 Página pública: " . url_liga($lc) . "\n🔐 Panel: " . url_base() . "admin.php\n👤 Usuario: " . $email . "\n🔑 Contraseña: " . $clave
        . "\n\nLe recomendamos cambiar la contraseña al ingresar (Mi cuenta).";
}

/**
 * Crea una cuenta con su administrador (crear_liga_completa) y lo que viene con ella:
 *  - 'solicitud_demo' (id): la solicitud queda «Demo creada» con la cuenta, y la cuenta toma su referido (y su vendedor si no vino otro);
 *  - 'enviar_correo' (true por defecto): correo de bienvenida con los accesos;
 *  - 'origen': registro, demo_pedida, super o central (por defecto: demo_pedida si vino de una solicitud, si no super).
 * Devuelve lo de crear_liga_completa más 'liga' (la fila), 'correo_enviado' y 'mensaje_whatsapp'.
 */
function crear_demo_cuenta(array $d, string $por): array
{
    $sol = !empty($d['solicitud_demo']) ? fila('SELECT * FROM solicitudes_demo WHERE id = ?', [(int)$d['solicitud_demo']]) : null;
    if (!empty($d['solicitud_demo']) && !$sol) throw new RuntimeException('La solicitud de demo ya no existe.');
    if ($sol && $sol['estado'] === 'creada') throw new RuntimeException('Esa solicitud de demo ya tiene su cuenta creada.');
    if ($sol && empty($d['vendedor_id']) && !empty($sol['vendedor_id']) && fila('SELECT id FROM vendedores WHERE id = ?', [(int)$sol['vendedor_id']])) $d['vendedor_id'] = (int)$sol['vendedor_id'];
    if (($d['origen'] ?? '') === '') $d['origen'] = $sol ? 'demo_pedida' : 'super';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = crear_liga_completa($d);
        if ($sol) {
            if (!empty($sol['recomendado_por'])) anotar_referido((int)$r['liga_id'], (int)$sol['recomendado_por']); // (v28)
            q("UPDATE solicitudes_demo SET estado = 'creada', liga_id = ?, atendido_por = ?, atendido_en = ? WHERE id = ?", [$r['liga_id'], $por, date('Y-m-d H:i'), $sol['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$r['liga_id']]);
    $email = mb_strtolower(trim((string)($d['admin_email'] ?? '')), 'UTF-8');
    $correo = false;
    if ($r['usuario_id'] && ($d['enviar_correo'] ?? true)) {
        try {
            $correo = correo_bienvenida($liga, $email, (string)$d['admin_clave'], trim((string)$d['admin_nombre']));
        } catch (Throwable $ex) {
            error_log('correo de bienvenida: ' . $ex->getMessage());
        }
    }
    $r['liga'] = $liga;
    $r['correo_enviado'] = $correo;
    $r['mensaje_whatsapp'] = mensaje_accesos_cuenta($liga, trim((string)($d['admin_nombre'] ?? '')), $email, (string)($d['admin_clave'] ?? ''));
    return $r;
}

/** ¿La cuenta pagó alguna vez su suscripción? */
function cuenta_tiene_pagos(int $liga_id): bool
{
    return (int)valor('SELECT COUNT(*) FROM pagos_suscripcion WHERE liga_id = ?', [$liga_id]) > 0;
}

/** ¿La cuenta está suspendida por Tukán (activa = 0)? */
function liga_suspendida(?array $l): bool
{
    return $l !== null && array_key_exists('activa', $l) && (int)$l['activa'] !== 1;
}

/** Mensaje para la cuenta suspendida. */
function texto_suspension(array $l): string
{
    $wa = trim((string)ajuste('ventas_whatsapp'));
    return 'Cuenta suspendida. Comuníquese con ' . ajuste('empresa') . ($wa !== '' ? ' (WhatsApp ' . $wa . ')' : '') . ' para reactivarla. Sus datos siguen guardados.';
}

/**
 * Alarga la demo (prueba) de una cuenta: suma desde la fecha de fin si aún no terminó, o desde hoy, y reinicia los avisos de la demo.
 * Solo si está en demo, o si ya venció y NUNCA pagó (a una cuenta que paga no se la vuelve a poner en demo). Devuelve la nueva fecha.
 */
function extender_prueba_cuenta(array $l, int $dias): string
{
    if ($dias < 1 || $dias > 90) throw new RuntimeException('Elija de 1 a 90 días.');
    if (liga_suspendida($l)) throw new RuntimeException('La cuenta está suspendida: primero reactívela.');
    $s = estado_suscripcion($l);
    $vencida_sin_pagar = in_array($s['estado'], ['gracia', 'vencida'], true) && !cuenta_tiene_pagos((int)$l['id']);
    if ($s['estado'] !== 'prueba' && !$vencida_sin_pagar) {
        throw new RuntimeException($l['nombre'] . ' ya es una cuenta que paga (o no tiene vencimiento): no se le alarga la demo. Registre un pago o cambie la fecha en Ligas → Editar.');
    }
    $base = $l['vence'] !== '' && $l['vence'] >= date('Y-m-d') ? $l['vence'] : date('Y-m-d');
    $hasta = date('Y-m-d', strtotime($base . ' +' . $dias . ' days'));
    q("UPDATE ligas SET vence = ?, es_prueba = 1, demo_avisos = '' WHERE id = ?", [$hasta, $l['id']]);
    bitacora_evento('suscripcion', 'Alargó la demo de ' . $l['nombre'] . ' hasta el ' . $hasta, (int)$l['id']);
    return $hasta;
}

/**
 * Registra un pago de suscripción (Ventas y Panel Tukán Central): vigencia, comisión del vendedor, premio del referido,
 * aviso de dominio gratis y correo al cliente. Devuelve lo de registrar_pago_suscripcion() más 'comision' (fila con el nombre
 * del vendedor, o null).
 */
function venta_registrar_pago(array $l, ?int $plan, int $meses, float $monto, string $forma, string $nota, string $fecha, string $por, ?string $inicio = null): array
{
    $r = registrar_pago_suscripcion($l, $plan, $meses, $monto, $forma, $nota, $fecha, $por, inicio: $inicio);
    try {
        correo_pago_registrado(fila('SELECT * FROM ligas WHERE id = ?', [$l['id']]), fila('SELECT * FROM pagos_suscripcion WHERE id = ?', [$r['pago_id']]), $plan ? fila('SELECT * FROM planes WHERE id = ?', [$plan]) : null); // (v33)
    } catch (Throwable $ex) {
        error_log('correo de pago: ' . $ex->getMessage());
    }
    $r['comision'] = $r['comision_id'] ? fila('SELECT c.*, v.nombre FROM comisiones c JOIN vendedores v ON v.id = c.vendedor_id WHERE c.id = ?', [$r['comision_id']]) : null;
    return $r;
}

/**
 * Anula (borra) un pago de suscripción. Se niega si la comisión del vendedor ya se pagó (la pendiente se borra con el pago).
 * Si el pago era el que dejó la fecha de vencimiento actual, la fecha vuelve a la del pago anterior que queda; si no queda
 * ningún pago, la fecha no se toca (y se avisa). Devuelve ['vence' => fecha que queda, 'cambio' => bool, 'sin_pagos' => bool].
 */
function anular_pago_suscripcion(int $pago_id, ?int $liga_id, string $motivo): array
{
    $p = fila('SELECT * FROM pagos_suscripcion WHERE id = ?' . ($liga_id !== null ? ' AND liga_id = ?' : ''), $liga_id !== null ? [$pago_id, $liga_id] : [$pago_id]);
    if (!$p) throw new RuntimeException('Ese pago ya no existe.');
    if (fila("SELECT id FROM comisiones WHERE pago_suscripcion_id = ? AND estado = 'pagada'", [$pago_id])) {
        throw new RuntimeException('Ese pago ya tiene una comisión PAGADA al vendedor. Primero revise la comisión en Vendedores.');
    }
    $l = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM pagos_suscripcion WHERE id = ?', [$pago_id]);
        $queda = $l ? (string)valor("SELECT MAX(hasta) FROM pagos_suscripcion WHERE liga_id = ? AND hasta <> ''", [$l['id']]) : '';
        $cambio = false;
        if ($l && $p['hasta'] !== '' && $p['hasta'] === $l['vence'] && $queda !== '' && $queda !== $l['vence']) {
            q('UPDATE ligas SET vence = ? WHERE id = ?', [$queda, $l['id']]);
            $cambio = true;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $vence = $cambio ? $queda : (string)($l['vence'] ?? '');
    if ($l) bitacora_evento('ventas', 'Anuló el pago de ' . dinero((float)$p['monto']) . ' del ' . $p['fecha'] . ' (' . (int)$p['meses'] . ' mes(es))'
        . ($cambio ? '; la cuenta vuelve a vencer el ' . $queda : '') . ($motivo !== '' ? '. Motivo: ' . mb_substr($motivo, 0, 200) : ''), (int)$l['id'], $pago_id);
    return ['vence' => $vence, 'cambio' => $cambio, 'sin_pagos' => $queda === '', 'liga' => $l, 'pago' => $p];
}

/** Cambia el plan de una cuenta sin cobrar (cortesía o corrección). Devuelve false si ya tenía ese plan. */
function cambiar_plan_cuenta(array $l, int $plan_id): bool
{
    $pl = plan_para_cuenta($l, $plan_id) ?? throw new RuntimeException('Ese plan no existe o no es para ' . (($l['tipo'] ?? 'liga') === 'escuela' ? 'escuelas o academias' : 'ligas') . '.');
    if ((int)$l['plan_id'] === (int)$pl['id']) return false;
    q('UPDATE ligas SET plan_id = ? WHERE id = ?', [$pl['id'], $l['id']]);
    bitacora_evento('ventas', 'Cambió el plan de ' . $l['nombre'] . ' a ' . $pl['nombre'] . ' (sin cobrar)', (int)$l['id']);
    return true;
}

/** Suspende la cuenta: no puede trabajar y su página pública se oculta (los datos se guardan). false si ya estaba suspendida. */
function suspender_cuenta(array $l, string $motivo): bool
{
    if (liga_suspendida($l)) return false;
    q('UPDATE ligas SET activa = 0, suspendida_motivo = ? WHERE id = ?', [mb_substr(trim($motivo), 0, 200), $l['id']]);
    bitacora_evento('suscripcion', 'Suspendió la cuenta ' . $l['nombre'] . ': ' . mb_substr(trim($motivo), 0, 200), (int)$l['id']);
    return true;
}

/** Quita la suspensión (queda en el estado que le toque por fechas). false si no estaba suspendida. */
function reactivar_cuenta(array $l): bool
{
    if (!liga_suspendida($l)) return false;
    q("UPDATE ligas SET activa = 1, suspendida_motivo = '' WHERE id = ?", [$l['id']]);
    bitacora_evento('suscripcion', 'Reactivó la cuenta ' . $l['nombre'], (int)$l['id']);
    return true;
}

/** Administrador principal de la cuenta: primero el del correo de contacto, si no el administrador más antiguo. */
function usuario_dueno(array $l): ?array
{
    $email = mb_strtolower(trim((string)($l['contacto_email'] ?? '')), 'UTF-8');
    return fila("SELECT * FROM usuarios WHERE liga_id = ? AND rol = 'liga' AND COALESCE(perfil, 'admin') = 'admin' AND activo = 1
                 ORDER BY CASE WHEN lower(email) = ? THEN 0 ELSE 1 END, id LIMIT 1", [$l['id'], $email]);
}

/** Contraseña temporal nueva para el dueño (la anterior deja de servir). Devuelve ['usuario' => fila, 'clave' => texto]. */
function nueva_clave_dueno_cuenta(array $l): array
{
    $u = usuario_dueno($l) ?? throw new RuntimeException('La cuenta no tiene un administrador activo. Créelo en Ligas → Editar → Administradores.');
    $clave = clave_al_azar(10);
    q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    bitacora_evento('usuarios', 'Cambió la contraseña del usuario ' . $u['email'] . ' (clave temporal nueva para el dueño)', (int)$l['id'], (int)$u['id']);
    return ['usuario' => $u, 'clave' => $clave];
}

/**
 * Responde una solicitud de protección de datos (LOPDP). $estado: nueva, en_proceso, respondida o rechazada.
 * Al responder un retiro de autorización o un borrado, se marcan como retiradas las autorizaciones de esa persona.
 */
function responder_solicitud_derecho(array $sol, string $estado, string $resp, string $por): void
{
    if (!array_key_exists($estado, estados_derecho())) $estado = $sol['estado'];
    $resp = mb_substr(trim($resp), 0, 2000);
    $cerrar = in_array($estado, ['respondida', 'rechazada'], true);
    if ($cerrar && mb_strlen($resp) < 5) throw new RuntimeException('Escriba la respuesta que se le dio a la persona (queda como prueba).');
    q('UPDATE solicitudes_derechos SET estado = ?, respuesta = ?, respondido_por = ?, respondido_en = ? WHERE id = ?', [
        $estado, $resp, $cerrar ? $por : $sol['respondido_por'], $cerrar ? date('Y-m-d H:i:s') : $sol['respondido_en'], $sol['id'],
    ]);
    // Si la persona retiró su autorización, se marca en sus autorizaciones registradas
    if ($cerrar && $estado === 'respondida' && in_array($sol['tipo'], ['revocar', 'eliminacion'], true) && $sol['cedula'] !== '') {
        q("UPDATE consentimientos SET revocado = ? WHERE revocado = '' AND documento = ? AND " . ($sol['liga_id'] ? 'liga_id = ?' : 'liga_id IS NULL'),
            array_merge([date('Y-m-d H:i:s'), enmascarar_documento($sol['cedula'])], $sol['liga_id'] ? [(int)$sol['liga_id']] : []));
    }
}
