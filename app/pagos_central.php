<?php
/*
 * (5 nov 2026) PAGOS CON TARJETA POR EL PANEL TUKÁN CENTRAL (Tukán Pagos + Plux) — pedido de Dimitry: «TuVocalía y TuAcademia
 * deben unirse… todo debe unirse, con sus diferentes precios y formas».
 *
 * «Mi plan» sigue calculando el precio como siempre (opciones_precio: mensual, trimestral, semestral, anual, meses de regalo,
 * combo liga + escuela, módulos deportivos, precio acordado). En vez de cobrar con el Plux propio, le pide el cobro al Panel
 * (POST {TUKAN_CENTRAL_URL}/plux_api.php, token v2 de tipo «plux» firmado con TUKAN_CENTRAL_SECRET). El Panel lo crea en
 * Tukán Pagos y, cuando Plux aprueba, registra el pago aquí mismo con «registrar_pago» (protocolo v2) y emite la factura.
 *   - Pago de una sola vez (los meses que elige) o suscripción (Plux cobra sola cada mes, 3, 6 o 12 meses).
 *   - El precio de los planes es SIN IVA: con tarjeta se suma el IVA (lo calcula Tukán Pagos).
 *   - Si el Panel no tiene encendidos los pagos con Plux para este programa, «Mi plan» sigue como antes (transferencia o el
 *     Plux propio si estaba prendido). Los cobros de las ligas y escuelas a sus equipos y familias NO pasan por aquí.
 */

/** Pide algo al Panel. Devuelve [http, json|null] (http 0 = no conecta o no está conectado). */
function pc_llamar(string $accion, array $datos, int $tiempo = 15): array
{
    if (!function_exists('tc_config') || !tc_activo()) return [0, null];
    $c = tc_config();
    if (($c['url'] ?? '') === '' || ($c['id'] ?? '') === '' || ($c['secreto'] ?? '') === '') return [0, null];
    $cuerpo = json_encode(['accion' => $accion] + $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $u = function_exists('usuario') ? (usuario() ?: []) : [];
    $email = filter_var($u['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $u['email'] : 'sistema@tukanec.com';
    $ahora = time();
    $p = ['v' => 2, 'tipo' => 'plux', 'inst' => (string)$c['id'], 'email' => $email, 'nombre' => mb_substr((string)($u['nombre'] ?? marca()), 0, 80), 'rol' => 'dueno',
          'iat' => $ahora, 'exp' => $ahora + 60, 'nonce' => bin2hex(random_bytes(16)), 'bh' => tc_b64(hash('sha256', $cuerpo, true))];
    $ch = curl_init(rtrim((string)$c['url'], '/') . '/plux_api.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $tiempo,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Tukan-Token: ' . tc_firmar($p, (string)$c['secreto'])]]);
    $r = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    if ($r === false) return [0, null];
    $j = json_decode((string)$r, true);
    return [$http, is_array($j) ? $j : null];
}

/** Estado de los pagos con tarjeta de esta cuenta en el Panel (una vez por página). null = el Panel no contesta o no está conectado. */
function pc_estado(array $liga): ?array
{
    static $cache = [];
    $id = (int)$liga['id'];
    if (!array_key_exists($id, $cache)) {
        [$http, $j] = pc_llamar('estado', ['cliente_id' => (string)$id], 6);
        $cache[$id] = $http === 200 && is_array($j) && !empty($j['ok']) ? $j : null;
    }
    return $cache[$id];
}

/** ¿Esta cuenta paga su plan con tarjeta por el Panel (Tukán Pagos)? */
function pc_activo(array $liga): bool
{
    $e = pc_estado($liga);
    return $e !== null && !empty($e['activo']);
}

/** La suscripción con tarjeta viva de la cuenta (si tiene). */
function pc_suscripcion(array $liga): ?array
{
    foreach ((pc_estado($liga)['cobros'] ?? []) as $c) if (($c['tipo'] ?? '') === 'suscripcion' && in_array($c['estado'] ?? '', ['pendiente', 'rechazado', 'activa', 'en_gracia'], true)) return $c;
    return null;
}

/**
 * Pide al Panel el cobro del plan con el precio que calculó «Mi plan» ($op de opciones_precio). $auto = suscripción que se cobra sola.
 * Devuelve el cobro (con url_pago) o lanza el error para mostrarlo.
 */
function pc_crear(array $liga, array $yo, array $pl, array $op, bool $auto): array
{
    $meses = (int)$op['meses'];
    $periodo = (string)($op['periodo'] ?? '');
    $desc = 'Plan ' . $pl['nombre'] . ' · ' . ($auto ? 'suscripción ' . mb_strtolower($op['nombre']) : $op['nombre'] . ' (' . $meses . ' mes' . ($meses === 1 ? '' : 'es') . ')')
        . (!empty($op['combo']) ? ' · combo' : '') . ' · ' . $liga['nombre'];
    $ruc = preg_replace('/\D/', '', (string)($liga['ruc'] ?? ''));
    [$http, $j] = pc_llamar('crear', [
        'tipo' => $auto ? 'suscripcion' : 'cobro', 'cliente_id' => (string)$liga['id'], 'nombre' => (string)$liga['nombre'],
        'plan' => (string)$pl['id'], 'plan_nombre' => (string)$pl['nombre'], 'periodo' => $periodo !== '' ? $periodo : null, 'meses' => $meses,
        'subtotal' => round((float)$op['precio'], 2), 'descripcion' => mb_substr($desc, 0, 90),
        'comprador' => ['nombre' => (string)($yo['nombre'] ?? ''), 'correo' => (string)($yo['email'] ?? ''), 'telefono' => (string)($liga['contacto_telefono'] ?? '')],
        'fiscal' => in_array(strlen($ruc), [10, 13], true) ? ['identificacion' => $ruc, 'razon_social' => (string)$liga['nombre'], 'correo' => (string)($yo['email'] ?? '')] : [],
        'volver' => rtrim(url_base(), '/') . '/admin.php?p=mi_plan',
    ], 30);
    if ($http === 0 || $j === null) throw new RuntimeException('No se pudo conectar con el sistema de pagos. Intente en unos minutos o pague por transferencia.');
    if (empty($j['ok'])) throw new RuntimeException((string)($j['error'] ?? 'No se pudo crear el pago.'));
    $c = $j['cobro'] ?? [];
    if (!is_string($c['url_pago'] ?? null) || !preg_match('#^https?://#', $c['url_pago'])) throw new RuntimeException('El sistema de pagos no devolvió el enlace. Intente otra vez.');
    return $c;
}

/**
 * Cancela la suscripción con tarjeta (conserva lo ya pagado). (6 nov 2026) Se puede cancelar el mes hasta 2 días antes del
 * vencimiento; después, el cobro de esa fecha sigue y se cancela sola luego: devuelve ['programada' => true].
 */
function pc_cancelar(array $liga, int $id): array
{
    [$http, $j] = pc_llamar('cancelar', ['cliente_id' => (string)$liga['id'], 'id' => $id], 20);
    if ($http === 0 || $j === null) throw new RuntimeException('No se pudo conectar con el sistema de pagos. Intente en unos minutos.');
    if (empty($j['ok'])) throw new RuntimeException((string)($j['error'] ?? 'No se pudo cancelar.'));
    return ($j['cobro'] ?? []) + ['programada' => !empty($j['programada'])];
}

/** dd/mm/aaaa de una fecha aaaa-mm-dd (o '' si no hay). */
function pc_fecha(?string $f): string
{
    $t = $f ? strtotime(substr($f, 0, 10)) : false;
    return $t ? date('d/m/Y', $t) : '';
}

/** Bloque de «Mi plan»: suscripción con tarjeta, pagos esperando y el regreso de la página de pago. */
function html_pagos_central(array $liga, bool $puede): string
{
    $e = pc_estado($liga);
    if (!$e || empty($e['activo'])) return '';
    $h = '';
    $vuelta = (string)($_GET['plux'] ?? '');
    if ($vuelta === 'aprobado') $h .= '<div class="aviso aviso-ok">✅ ¡Pago recibido! Su plan se actualiza en unos segundos (recargue la página). La factura le llega al correo.</div>';
    elseif ($vuelta !== '') $h .= '<div class="aviso aviso-info">El pago no se completó. Puede intentarlo otra vez con otra tarjeta.</div>';
    if (!empty($e['pruebas'])) $h .= '<p class="aviso aviso-info">Pagos con tarjeta en <b>modo de prueba</b>: no se cobra de verdad.</p>';
    $vivos = array_filter($e['cobros'] ?? [], fn($c) => in_array($c['estado'] ?? '', ['pendiente', 'rechazado', 'activa', 'en_gracia', 'vencida'], true));
    if (!$vivos) return $h;
    $h .= '<div class="tarjeta" id="pagos-tarjeta"><h2 style="margin-top:0">💳 Pagos con tarjeta</h2><ul>';
    foreach ($vivos as $c) {
        $h .= '<li><b>' . e((string)$c['descripcion']) . '</b> — ' . e((string)$c['estado_texto']) . ' · ' . dinero((float)$c['total']) . ' con IVA';
        if (($c['tipo'] ?? '') === 'suscripcion') {
            $h .= $c['cobra_sola'] ? ' · <small>se cobra sola a su tarjeta</small>' : '';
            if (!empty($c['pagado_hasta'])) $h .= ' · <small>pagada hasta el ' . e(pc_fecha($c['pagado_hasta'])) . '</small>';
            if (!empty($c['cancelacion_programada']) && $c['estado'] !== 'cancelada') {
                $h .= '<br><small>🗓️ <b>Cancelación programada:</b> pidió cancelar después de la fecha límite, así que el cobro del ' . e(pc_fecha($c['cancelacion_programada']))
                    . ' sigue y después se cancela sola.</small>';
            } elseif (!empty($c['cancelar_hasta']) && in_array($c['estado'], ['activa', 'en_gracia'], true)) {
                $h .= '<br><small>Para que no se cobre el siguiente período, cancele hasta el <b>' . e(pc_fecha($c['cancelar_hasta'])) . '</b> (2 días antes del cobro).</small>';
            }
        }
        if (!empty($c['url_pago'])) $h .= ' <a class="btn btn-chico" href="' . e((string)$c['url_pago']) . '">Pagar ahora</a>';
        if ($puede && ($c['tipo'] ?? '') === 'suscripcion' && in_array($c['estado'], ['pendiente', 'rechazado', 'activa', 'en_gracia'], true) && empty($c['cancelacion_programada'])) {
            $tarde = !empty($c['cancelar_hasta']) && date('Y-m-d') > $c['cancelar_hasta'];
            $msg = $tarde ? '¿Cancelar la suscripción con tarjeta? Ya pasó la fecha límite (' . pc_fecha($c['cancelar_hasta']) . '): el cobro del ' . pc_fecha($c['pagado_hasta'] ?? '') . ' sigue y después se cancela.'
                : '¿Cancelar la suscripción con tarjeta? Conserva lo que ya pagó.';
            $h .= ' <form method="post" style="display:inline" onsubmit="return confirm(' . e(json_encode($msg, JSON_UNESCAPED_UNICODE)) . ')">' . csrf_campo()
                . '<input type="hidden" name="accion" value="cancelar_tarjeta"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="btn btn-sec btn-chico">Cancelar suscripción</button></form>';
        }
        $h .= '</li>';
    }
    return $h . '</ul></div>';
}
