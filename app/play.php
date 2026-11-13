<?php
/*
 * (v38) APPS ANDROID Y GOOGLE PLAY
 *
 * Hay 3 formas de usar TuAcademia en el celular:
 *  - Navegador / PWA (como siempre): precios normales.
 *  - APK directo (com.tukan.tuacademia.directo, se baja de tuacademia.tukanec.com/descargar.php): precios normales.
 *  - App de Google Play (com.tukansoftware.tuacademia; abre /app.php?origen=play): el plan se paga DENTRO de la app con Google Play (regla de Google),
 *    con precios más altos (ajuste play_factor, 20 % por defecto, redondeado hacia arriba a 0,50) porque Google cobra comisión.
 *
 * Cómo se sabe desde dónde entra: cada app abre /app.php?origen=play|apk|web y eso guarda la cookie tv_app.
 * En la app de Play se ocultan la transferencia, la tarjeta y "pague por WhatsApp" del plan (Google no lo permite).
 * Entradas, inscripciones, canchas y mensualidades de escuelas son servicios físicos: NO pasan por Google.
 *
 * Base de datos: la tabla play_compras la crea play_preparar() (al final de este archivo), sin tocar PRAGMA user_version,
 * para que esta parte se pueda unir a cualquier versión futura sin chocar con sus migraciones.
 *
 * Productos de Google Play (suscripciones, una por plan y período, más una mensual por cada deporte adicional):
 *   plan_<id>_<meses>m  (ej. plan_1_1m = Básico mensual)   ·   modulo_<clave> (ej. modulo_ciclismo)
 * Se crean solos desde Súper → 📱 Google Play con la cuenta de servicio (API de Google Play).
 * La compra la hace la página con la Digital Goods API (assets/play.js) y el servidor la verifica con la API
 * (purchases.subscriptionsv2), la reconoce (acknowledge) y activa el plan. Cada 6 horas revisa las renovaciones.
 */

/** (13 nov 2026) Paquete real de la app de Google Play (repositorio qfcorp/tukan-apps, apps/tuacademia/app.json). */
const PLAY_PAQUETE = 'com.tukansoftware.tuacademia';
const APK_PAQUETE = 'com.tukan.tuacademia.directo';
/**
 * (13 nov 2026) Huella SHA-256 de la LLAVE DE SUBIDA DE TUKÁN (tukan-apps/llave): con ella se firma el .aab de TuAcademia, como
 * todas las apps Tukán. Siempre va en /.well-known/assetlinks.json de la app de Google Play (cuando Google Play está encendido).
 * La de «Firma de apps» de Google se agrega después de la primera subida (Súper → 📱 Google Play, o sudo bash vps/app_android.sh HUELLA).
 */
const PLAY_HUELLA_SUBIDA = '39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC';
/**
 * Huella de la llave del APK directo (com.tukan.tuacademia.directo), opcional: app/config.php 'huella_firma' => 'AA:BB:...'.
 * Sin ella el APK directo no sale en assetlinks (la app de Google Play no la necesita: usa PLAY_HUELLA_SUBIDA).
 */
define('TV_HUELLA_FIRMA', strtoupper(trim((string)(CFG['huella_firma'] ?? ''))));

/** (TuAcademia) ¿La app de Google Play está encendida en este servidor? Apagada hasta que se decida (app/config.php 'google_play' => true). */
function play_permitido(): bool
{
    return !empty(CFG['google_play']);
}

/* ============================================================
 * 1) ¿DESDE DÓNDE ENTRA? (cookie tv_app)
 * ============================================================ */

function app_origen(): string
{
    $o = (string)($_COOKIE['tv_app'] ?? '');
    return in_array($o, ['play', 'apk', 'web'], true) ? $o : 'web';
}

/** La llama app.php con ?origen=play|apk|web (cada app abre esa dirección al iniciar). */
function fijar_origen_app(string $o): void
{
    if (!in_array($o, ['play', 'apk', 'web'], true) || headers_sent()) return;
    $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('tv_app', $o, ['expires' => time() + 400 * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => $https, 'httponly' => false]);
    $_COOKIE['tv_app'] = $o;
}

/** ¿Se está usando la app de Google Play? (el superadministrador siempre ve los precios normales) */
function en_app_play(): bool
{
    if (!empty($GLOBALS['__forzar_app_play'])) return true; // pruebas automáticas
    if (!play_permitido()) return false; // (TuAcademia) app de Google Play apagada: todo funciona como en la web
    if (defined('SIN_PRECIO_PLAY') || PHP_SAPI === 'cli') return false;
    return app_origen() === 'play' && !es_super();
}

/* ============================================================
 * 2) PRECIOS DE GOOGLE PLAY
 * ============================================================ */

/** Nombre de la forma de pago (las de siempre + Google Play, que solo se usa en suscripciones). */
function nombre_forma_pago(string $f): string
{
    return $f === 'google_play' ? 'Google Play' : (formas_pago()[$f] ?? $f);
}

function play_activo(): bool
{
    return play_permitido() && (string)ajuste('play_activo', '0') === '1';
}

function play_factor(): float
{
    return max(0, min(100, (float)str_replace(',', '.', (string)ajuste('play_factor', '20'))));
}

function play_paquete(): string
{
    $p = trim((string)ajuste('play_paquete', PLAY_PAQUETE));
    return preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $p) ? $p : PLAY_PAQUETE;
}

function play_idioma(): string
{
    $i = trim((string)ajuste('play_idioma', 'es-419'));
    return preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,4})?$/', $i) ? $i : 'es-419';
}

/** Enlace a la app en Google Play. */
function play_url(): string
{
    return 'https://play.google.com/store/apps/details?id=' . rawurlencode(play_paquete());
}

/**
 * Precio de la web → precio de Google Play: + factor %, redondeado hacia ARRIBA a 0,50 ($10 → $12, $15 → $18, $7 → $8,50).
 * (28 oct 2026) En TuAcademia, como en toda la familia Tukán: precio web ÷ 0,85 (Google se queda el 15 %), redondeado hacia arriba
 * a ,99 ($20 → $23,99, $100 → $117,99, $45 → $52,99). En modo ligas (TuVocalía) sigue el factor de siempre.
 */
function precio_play(float $web): float
{
    if ($web <= 0) return 0.0;
    if ((defined('PRODUCTO') ? PRODUCTO : 'academia') === 'academia') return round(ceil(round($web / 0.85 + 0.01, 6)) - 0.01, 2);
    $p = $web * (1 + play_factor() / 100);
    return ceil(round($p * 2, 6)) / 2;
}

/** Período ISO 8601 que acepta Google Play para N meses ('' si no se puede vender en Play). */
function play_periodo_iso(int $meses): string
{
    return [1 => 'P1M', 3 => 'P3M', 6 => 'P6M', 12 => 'P1Y'][$meses] ?? '';
}

function play_id_plan(int $plan_id, int $meses): string
{
    return 'plan_' . $plan_id . '_' . $meses . 'm';
}

function play_id_modulo(string $k): string
{
    return 'modulo_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($k));
}

/** Precio mensual en la web de un deporte ADICIONAL (siempre es el 2.º o más: lleva el descuento, igual que en precio_modulos()). */
function precio_web_modulo_extra(string $k): float
{
    $m = modulo_info($k);
    if (!$m) return 0.0;
    return round($m['precio'] * (1 - modulo_descuento() / 100), 2);
}

/** Precio que se muestra de un deporte adicional (en la app de Play, el de Google). */
function precio_mostrar_modulo_extra(string $k): float
{
    $w = precio_web_modulo_extra($k);
    return en_app_play() ? precio_play($w) : $w;
}

/**
 * Opciones de precio de un plan dentro de la app de Play (sin combo ni deportes adicionales: esos van aparte).
 * Mismo formato que opciones_precio() + 'play_id'.
 */
function opciones_precio_play(array $pl): array
{
    $r = [];
    $pm_web = (float)$pl['precio_mensual'];
    $pm = precio_play($pm_web);
    foreach (periodos_plan() as $k => [$meses, $nom, $txt]) {
        $web = (float)($pl['precio_' . $k] ?? 0);
        if ($web <= 0 || play_periodo_iso($meses) === '') continue;
        $precio = precio_play($web);
        $gratis = $pm > 0 && $meses > 1 ? (int)round($meses - $precio / $pm) : 0;
        $r[$k] = ['periodo' => $k, 'nombre' => $nom, 'texto' => $txt, 'meses' => $meses, 'precio' => $precio, 'por_mes' => round($precio / $meses, 2), 'gratis' => max(0, $gratis),
                  'dominio' => $meses >= 12 && (int)($pl['dominio_anual'] ?? 0) === 1, 'combo' => false, 'precio_normal' => $precio, 'extra_modulos' => 0.0,
                  'base' => $precio, 'rebaja' => $pm_web > 0 ? $web / ($pm_web * $meses) : 1.0, 'factor' => 1.0, 'play_id' => play_id_plan((int)$pl['id'], $meses), 'precio_web' => $web];
    }
    return $r;
}

/**
 * Todos los productos (suscripciones) que debe haber en Google Play:
 * id => ['id', 'tipo' => plan|modulo, 'plan_id', 'meses', 'modulo', 'titulo', 'descripcion', 'beneficios', 'periodo', 'precio_web', 'precio']
 */
function play_productos(): array
{
    $r = [];
    foreach (filas('SELECT * FROM planes WHERE activo = 1 ORDER BY tipo_cuenta, orden, precio_mensual') as $pl) {
        $para = ['liga' => 'ligas', 'escuela' => 'escuelas', 'ambos' => 'ligas y escuelas'][$pl['tipo_cuenta']] ?? 'ligas';
        foreach (opciones_precio_play($pl) as $o) {
            $id = $o['play_id'];
            $r[$id] = ['id' => $id, 'tipo' => 'plan', 'plan_id' => (int)$pl['id'], 'meses' => $o['meses'], 'modulo' => '',
                'titulo' => mb_substr('Plan ' . $pl['nombre'] . ' · ' . $o['nombre'], 0, 55),
                'descripcion' => mb_substr(marca() . ' para ' . $para . ': plan ' . $pl['nombre'] . ' ' . $o['texto'] . '.', 0, 80),
                'beneficios' => array_slice(array_map(fn($t) => mb_substr($t, 0, 40), textos_limites_plan($pl, $pl['tipo_cuenta'] === 'escuela' ? 'escuela' : 'liga')), 0, 4),
                'periodo' => play_periodo_iso($o['meses']), 'precio_web' => $o['precio_web'], 'precio' => $o['precio']];
        }
    }
    // (13 nov 2026) En TuAcademia en Google Play solo se venden los dos planes (Básico y Completo): las disciplinas adicionales no
    foreach (es_academia() ? [] : modulos_deporte(true) as $k => $m) {
        $w = precio_web_modulo_extra($k);
        if ($w <= 0) continue;
        $id = play_id_modulo($k);
        $r[$id] = ['id' => $id, 'tipo' => 'modulo', 'plan_id' => 0, 'meses' => 1, 'modulo' => $k,
            'titulo' => mb_substr('Deporte adicional: ' . $m['nombre'], 0, 55),
            'descripcion' => mb_substr('Agrega ' . $m['nombre'] . ' a su cuenta de ' . marca() . ' (mensual).', 0, 80),
            'beneficios' => [mb_substr($m['nombre'] . ' en la misma cuenta', 0, 40)],
            'periodo' => 'P1M', 'precio_web' => $w, 'precio' => precio_play($w)];
    }
    return $r;
}

function play_producto(string $id): ?array
{
    return play_productos()[$id] ?? null;
}

/* ============================================================
 * 3) CUENTA DE SERVICIO Y API DE GOOGLE PLAY
 * ============================================================ */

function play_archivo_cuenta(): string
{
    return RAIZ . '/data/google_play_cuenta.json';
}

/** Datos de la cuenta de servicio (JSON que se baja de Google Cloud). null si no está cargada o no sirve. */
function play_cuenta(): ?array
{
    $f = play_archivo_cuenta();
    if (!is_file($f)) return null;
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) && ($j['type'] ?? '') === 'service_account' && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
}

/** Guarda el JSON de la cuenta de servicio (lo sube el superadministrador). */
function play_guardar_cuenta(string $json): string
{
    $j = json_decode($json, true);
    if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key'])) {
        throw new RuntimeException('Ese archivo no es una clave de cuenta de servicio de Google (debe ser el .json que se descarga en Google Cloud → Cuentas de servicio → Claves).');
    }
    if (!openssl_pkey_get_private($j['private_key'])) throw new RuntimeException('La clave privada del archivo no es válida.');
    $f = play_archivo_cuenta();
    file_put_contents($f, json_encode($j, JSON_UNESCAPED_SLASHES));
    @chmod($f, 0600);
    @unlink(RAIZ . '/data/play_token.json');
    return (string)$j['client_email'];
}

/** Dirección base de la API (en pruebas, config.php puede apuntar a un simulador con 'play_api'). */
function play_api_base(): string
{
    return rtrim((string)(CFG['play_api'] ?? 'https://androidpublisher.googleapis.com'), '/');
}

/** Token de acceso OAuth (JWT firmado con la cuenta de servicio). Se guarda 50 minutos. */
function play_token(): string
{
    $c = play_cuenta() ?? throw new RuntimeException('Falta cargar la cuenta de servicio de Google Play (Súper → 📱 Google Play).');
    $cache = RAIZ . '/data/play_token.json';
    $g = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (is_array($g) && ($g['email'] ?? '') === $c['client_email'] && (int)($g['exp'] ?? 0) > time() + 60) return (string)$g['token'];
    $aud = (string)($c['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    if (!isset(CFG['play_api']) && !str_starts_with($aud, 'https://oauth2.googleapis.com/')) $aud = 'https://oauth2.googleapis.com/token';
    $ahora = time();
    $jwt = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . b64u(json_encode([
        'iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/androidpublisher', 'aud' => $aud, 'iat' => $ahora, 'exp' => $ahora + 3600,
    ]));
    if (!openssl_sign($jwt, $firma, $c['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('No se pudo firmar con la clave de la cuenta de servicio.');
    $jwt .= '.' . b64u($firma);
    [$cod, $r] = play_http('POST', $aud, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt], false, true);
    if ($cod !== 200 || empty($r['access_token'])) throw new RuntimeException('Google rechazó la cuenta de servicio (' . $cod . '): ' . play_error_texto($r));
    file_put_contents($cache, json_encode(['email' => $c['client_email'], 'token' => $r['access_token'], 'exp' => $ahora + min(3000, (int)($r['expires_in'] ?? 3600) - 60)]));
    @chmod($cache, 0600);
    return (string)$r['access_token'];
}

/** Petición HTTP (JSON). Devuelve [código, respuesta decodificada]. */
function play_http(string $metodo, string $url, ?array $cuerpo = null, bool $con_token = true, bool $formulario = false): array
{
    $h = ['Accept: application/json'];
    if ($con_token) $h[] = 'Authorization: Bearer ' . play_token();
    $ch = curl_init($url);
    $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10];
    if ($cuerpo !== null) {
        if ($formulario) {
            $op[CURLOPT_POSTFIELDS] = http_build_query($cuerpo);
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $h[] = 'Content-Type: application/json';
        }
    } elseif ($metodo === 'POST') {
        $op[CURLOPT_POSTFIELDS] = '';
    }
    $op[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $op);
    $txt = curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($txt === false) return [0, ['error' => ['message' => 'Sin conexión con Google: ' . $err]]];
    $j = json_decode((string)$txt, true);
    return [$cod, is_array($j) ? $j : ['crudo' => mb_substr((string)$txt, 0, 300)]];
}

function play_error_texto(array $r): string
{
    if (isset($r['error']['message'])) return (string)$r['error']['message'];
    if (isset($r['error_description'])) return (string)$r['error_description'];
    if (isset($r['error']) && is_string($r['error'])) return $r['error'];
    return mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 200);
}

function play_url_api(string $ruta): string
{
    return play_api_base() . '/androidpublisher/v3/applications/' . rawurlencode(play_paquete()) . $ruta;
}

/** Estado de una suscripción en Google (purchases.subscriptionsv2.get). */
function play_consultar(string $token): array
{
    [$cod, $r] = play_http('GET', play_url_api('/purchases/subscriptionsv2/tokens/' . rawurlencode($token)));
    if ($cod !== 200) throw new RuntimeException('Google no reconoce esa compra (' . $cod . '): ' . play_error_texto($r));
    return $r;
}

/** Reconoce la compra (si no se reconoce en 3 días, Google la devuelve). */
function play_reconocer(string $producto, string $token): void
{
    [$cod, $r] = play_http('POST', play_url_api('/purchases/subscriptions/' . rawurlencode($producto) . '/tokens/' . rawurlencode($token) . ':acknowledge'), ['developerPayload' => 'tuacademia']);
    if ($cod !== 200 && $cod !== 204) throw new RuntimeException('No se pudo reconocer la compra en Google (' . $cod . '): ' . play_error_texto($r));
}

/** "2026-11-12T20:15:00.123Z" → timestamp. */
function play_ts(string $iso): int
{
    $t = strtotime($iso);
    return $t ?: 0;
}

/** Datos útiles de la respuesta de Google para un producto: [vence_ts, renueva, pedido, monto, moneda]. */
function play_linea(array $sub, string $producto): ?array
{
    foreach ((array)($sub['lineItems'] ?? []) as $li) {
        if (($li['productId'] ?? '') !== $producto) continue;
        $p = $li['autoRenewingPlan']['recurringPrice'] ?? null;
        $monto = $p ? (float)($p['units'] ?? 0) + (int)($p['nanos'] ?? 0) / 1e9 : 0.0;
        return ['vence_ts' => play_ts((string)($li['expiryTime'] ?? '')), 'renueva' => !empty($li['autoRenewingPlan']['autoRenewEnabled']) ? 1 : 0,
                'pedido' => (string)($li['latestSuccessfulOrderId'] ?? $sub['latestOrderId'] ?? ''), 'monto' => round($monto, 2), 'moneda' => (string)($p['currencyCode'] ?? 'USD')];
    }
    return null;
}

/** Estados de Google en los que el cliente tiene derecho a usar lo que compró (hasta la fecha de vencimiento). */
function play_estado_vigente(string $estado): bool
{
    return in_array($estado, ['SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_CANCELED', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD'], true);
}

function play_nombre_estado(string $e): string
{
    return ['SUBSCRIPTION_STATE_ACTIVE' => 'Activa', 'SUBSCRIPTION_STATE_CANCELED' => 'Cancelada (vale hasta el vencimiento)', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'En período de gracia',
            'SUBSCRIPTION_STATE_ON_HOLD' => 'Suspendida (pago rechazado)', 'SUBSCRIPTION_STATE_PAUSED' => 'En pausa', 'SUBSCRIPTION_STATE_EXPIRED' => 'Vencida',
            'SUBSCRIPTION_STATE_PENDING' => 'Pago pendiente', 'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED' => 'Compra pendiente cancelada'][$e] ?? $e;
}

/* ============================================================
 * 4) REGISTRAR Y APLICAR COMPRAS
 * ============================================================ */

/**
 * Registra una compra hecha en la app (la manda assets/play.js con el purchaseToken) y la aplica a la cuenta.
 * Devuelve ['ok' => bool, 'mensaje' => texto].
 */
function play_registrar_compra(array $liga, ?array $u, string $producto, string $token): array
{
    if (!preg_match('/^[A-Za-z0-9._\-:]{10,600}$/', $token)) throw new RuntimeException('La compra que envió la app no es válida.');
    $ya = fila('SELECT * FROM play_compras WHERE token = ?', [$token]);
    if ($ya && (int)$ya['liga_id'] !== (int)$liga['id']) throw new RuntimeException('Esa compra de Google Play ya está registrada en otra cuenta. Escríbanos a soporte.');
    $pr = play_producto($producto);
    if (!$pr && $ya) $pr = ['tipo' => $ya['tipo'], 'plan_id' => (int)$ya['plan_id'], 'meses' => (int)$ya['meses'], 'modulo' => $ya['modulo']];
    if (!$pr) throw new RuntimeException('Ese producto no existe en ' . marca() . '.');
    if ($pr['tipo'] === 'plan') {
        $pl = fila('SELECT * FROM planes WHERE id = ?', [$pr['plan_id']]);
        $tipo = ($liga['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
        if (!$pl || !in_array($pl['tipo_cuenta'], ['ambos', $tipo], true)) throw new RuntimeException('Ese plan no es para ' . ($tipo === 'escuela' ? 'escuelas' : 'ligas') . '.');
    }
    $sub = play_consultar($token);
    if (!play_linea($sub, $producto)) throw new RuntimeException('La compra de Google no corresponde a ese producto.');
    if ($ya) {
        $cid = (int)$ya['id'];
    } else {
        q('INSERT INTO play_compras (liga_id, usuario_id, producto, tipo, plan_id, modulo, meses, token, prueba) VALUES (?,?,?,?,?,?,?,?,?)', [
            $liga['id'], $u['id'] ?? null, $producto, $pr['tipo'], $pr['plan_id'] ?: null, (string)$pr['modulo'], (int)$pr['meses'], $token, isset($sub['testPurchase']) ? 1 : 0,
        ]);
        $cid = (int)db()->lastInsertId();
        bitacora_evento('ventas', 'Compra en Google Play: ' . $producto . ' (' . $liga['nombre'] . ')', (int)$liga['id'], $cid);
    }
    $r = play_aplicar($cid, $sub);
    return ['ok' => true, 'mensaje' => $r];
}

/** Aplica lo que dice Google a la cuenta: activa o renueva el plan, agrega o quita el deporte. Devuelve un texto. */
function play_aplicar(int $cid, array $sub): string
{
    $c = fila('SELECT * FROM play_compras WHERE id = ?', [$cid]) ?? throw new RuntimeException('Compra no encontrada.');
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$c['liga_id']]) ?? throw new RuntimeException('Cuenta no encontrada.');
    $li = play_linea($sub, $c['producto']);
    $estado = (string)($sub['subscriptionState'] ?? '');
    if ($li === null) {
        q("UPDATE play_compras SET estado = ?, revisado = datetime('now') WHERE id = ?", [$estado ?: 'SIN_LINEA', $cid]);
        return 'Google no devolvió datos de este producto.';
    }
    $vence = date('Y-m-d', $li['vence_ts']);
    $vigente = play_estado_vigente($estado) && $li['vence_ts'] > time();
    q("UPDATE play_compras SET estado = ?, vence = ?, vence_ts = ?, renueva = ?, pedido = ?, monto = ?, moneda = ?, revisado = datetime('now') WHERE id = ?",
        [$estado, $vence, $li['vence_ts'], $li['renueva'], $li['pedido'], $li['monto'], $li['moneda'], $cid]);
    // Reconocer (obligatorio en 3 días)
    if ($vigente && ($sub['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_PENDING' && !(int)$c['reconocida']) {
        play_reconocer($c['producto'], $c['token']);
        q('UPDATE play_compras SET reconocida = 1 WHERE id = ?', [$cid]);
    } elseif (($sub['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED') {
        q('UPDATE play_compras SET reconocida = 1 WHERE id = ?', [$cid]);
    }
    $txt = 'Estado en Google: ' . play_nombre_estado($estado) . ' · vence ' . $vence;
    // ¿Período nuevo pagado? (la primera vez o una renovación: la fecha de vencimiento avanzó)
    if ($vigente && $li['vence_ts'] > (int)$c['aplicado_ts']) {
        $nota = 'Google Play ' . ($li['pedido'] !== '' ? $li['pedido'] : '#' . $cid) . (isset($sub['testPurchase']) ? ' (PRUEBA)' : '');
        $monto = isset($sub['testPurchase']) ? 0.0 : $li['monto'];
        if ($c['tipo'] === 'plan') {
            $hasta = max($vence, (string)$liga['vence'] !== '' && !(int)$liga['es_prueba'] ? (string)$liga['vence'] : $vence);
            registrar_pago_suscripcion($liga, $c['plan_id'] ? (int)$c['plan_id'] : null, max(1, (int)$c['meses']), $monto, 'google_play', $nota, date('Y-m-d'), 'Google Play', $hasta);
            $txt = '¡Listo! Su plan quedó activo hasta el ' . $hasta . '.';
        } else {
            $k = (string)$c['modulo'];
            $agregado = 0;
            $v = trim((string)($liga['deportes'] ?? '*'));
            $lista = $v === '' || $v === '*' ? deportes_cobrados($liga) : deportes_liga($liga);
            if (!in_array($k, $lista, true)) {
                $lista[] = $k;
                $agregado = 1;
            }
            if ($v === '' || $v === '*' || $agregado) q('UPDATE ligas SET deportes = ? WHERE id = ?', [implode(',', array_values(array_unique($lista))), $liga['id']]);
            if ($agregado) q('UPDATE play_compras SET modulo_agregado = 1 WHERE id = ?', [$cid]);
            q('INSERT INTO pagos_suscripcion (liga_id, plan_id, monto, meses, desde, hasta, forma_pago, nota, fecha, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                $liga['id'], null, round($monto, 2), 1, date('Y-m-d'), $vence, 'google_play', mb_substr('Deporte ' . nombre_modulo_deporte($k) . ' · ' . $nota, 0, 200), date('Y-m-d'), 'Google Play',
            ]);
            generar_comision((int)db()->lastInsertId());
            foreach (tg_chats_admins((int)$liga['id']) as $ch) tg_encolar((int)$liga['id'], $ch, '✅ <b>Deporte agregado</b>' . "\n" . e(nombre_modulo_deporte($k)) . ' quedó activo en ' . e($liga['nombre']) . ' (Google Play, hasta el ' . $vence . ').');
            $txt = '¡Listo! ' . nombre_modulo_deporte($k) . ' quedó activo hasta el ' . $vence . '.';
        }
        q('UPDATE play_compras SET aplicado_ts = ? WHERE id = ?', [$li['vence_ts'], $cid]);
        foreach (array_column(filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''"), 'tg_chat') as $ch) {
            tg_encolar(null, $ch, '📱 <b>Pago por Google Play</b>' . "\n" . e($liga['nombre']) . ' · ' . e($c['producto']) . ' · ' . dinero($monto) . ' · hasta ' . $vence);
        }
    }
    // Deporte que se pagaba por Google y ya venció: se quita (si se agregó por Google y no hay otra compra vigente del mismo deporte)
    if (!$vigente && $c['tipo'] === 'modulo' && (int)$c['modulo_agregado'] && $li['vence_ts'] < time()) {
        $otra = (int)valor('SELECT COUNT(*) FROM play_compras WHERE liga_id = ? AND modulo = ? AND id <> ? AND vence_ts > ?', [$liga['id'], $c['modulo'], $cid, time()]);
        if (!$otra) {
            $lista = array_values(array_diff(deportes_liga($liga), [$c['modulo']]));
            q('UPDATE ligas SET deportes = ? WHERE id = ?', [implode(',', $lista ?: ['futbol']), $liga['id']]);
            q('UPDATE play_compras SET modulo_agregado = 0 WHERE id = ?', [$cid]);
            bitacora_evento('ventas', 'Venció en Google Play el deporte ' . $c['modulo'] . ': se quitó de la cuenta (' . $liga['nombre'] . ')', (int)$liga['id'], $cid);
            $txt .= ' · se quitó el deporte de la cuenta';
        }
    }
    return $txt;
}

/** Revisa en Google las compras que pueden haber cambiado (renovación, cancelación, vencimiento). */
function play_revisar(bool $todas = false): array
{
    if (!play_cuenta()) return ['revisadas' => 0, 'errores' => 0];
    $sql = $todas ? "SELECT * FROM play_compras WHERE estado NOT IN ('SUBSCRIPTION_STATE_EXPIRED') OR vence_ts > ?"
                  : "SELECT * FROM play_compras WHERE (reconocida = 0 AND estado <> 'SUBSCRIPTION_STATE_EXPIRED') OR (estado <> 'SUBSCRIPTION_STATE_EXPIRED' AND vence_ts < ?) OR (modulo_agregado = 1 AND vence_ts < ?)";
    $lim = time() + 2 * 86400;
    $n = 0;
    $err = 0;
    foreach (filas($sql, $todas ? [time() - 30 * 86400] : [$lim, time()]) as $c) {
        try {
            play_aplicar((int)$c['id'], play_consultar($c['token']));
            $n++;
        } catch (Throwable $ex) {
            $err++;
            q("UPDATE play_compras SET revisado = datetime('now'), error = ? WHERE id = ?", [mb_substr($ex->getMessage(), 0, 300), $c['id']]);
        }
    }
    return ['revisadas' => $n, 'errores' => $err];
}

/** La llama tareas.php cada minuto: revisa cada 6 horas. */
function play_tarea(): void
{
    if (!play_cuenta()) return;
    $u = (int)ajuste('play_revision_ts', 0);
    if ($u > time() - 6 * 3600) return;
    guardar_ajuste('play_revision_ts', (string)time());
    play_revisar();
}

/* ============================================================
 * 5) CREAR LOS PRODUCTOS EN GOOGLE PLAY (API de monetización)
 * ============================================================ */

function play_precio_api(float $p): array
{
    $u = (int)floor($p + 1e-9);
    return ['currencyCode' => 'USD', 'units' => (string)$u, 'nanos' => (int)round(($p - $u) * 1e9)];
}

/**
 * Crea en Google Play las suscripciones que faltan y activa su plan base. Las que ya existen no se tocan
 * (si el precio de Google es distinto, se avisa: los precios de quienes ya pagan se cambian en Play Console).
 * Devuelve líneas de texto con lo que hizo.
 */
function play_crear_productos(): array
{
    $log = [];
    foreach (play_productos() as $p) {
        $id = $p['id'];
        [$cod, $ex] = play_http('GET', play_url_api('/subscriptions/' . rawurlencode($id)));
        if ($cod === 200) {
            $precio = null;
            foreach ((array)($ex['basePlans'] ?? []) as $bp) foreach ((array)($bp['regionalConfigs'] ?? []) as $rc) if (($rc['regionCode'] ?? '') === 'EC') $precio = (float)($rc['price']['units'] ?? 0) + (int)($rc['price']['nanos'] ?? 0) / 1e9;
            $log[] = '✔ ' . $id . ' ya existe' . ($precio !== null && abs($precio - $p['precio']) > 0.009 ? ' · ⚠ en Google cuesta ' . dinero($precio) . ' y aquí ' . dinero($p['precio']) . ' (cámbielo en Play Console si quiere)' : '');
            continue;
        }
        if ($cod !== 404) {
            $log[] = '✖ ' . $id . ': ' . play_error_texto($ex) . ' (' . $cod . ')';
            continue;
        }
        [$cc, $conv] = play_http('POST', play_url_api('/pricing:convertRegionPrices'), ['price' => play_precio_api($p['precio'])]);
        if ($cc !== 200 || empty($conv['convertedRegionPrices'])) {
            $log[] = '✖ ' . $id . ': no se pudieron calcular los precios por país (' . $cc . '): ' . play_error_texto($conv);
            continue;
        }
        $regiones = [];
        foreach ($conv['convertedRegionPrices'] as $rc => $d) {
            $precio_region = $rc === 'EC' || $rc === 'US' ? play_precio_api($p['precio']) : $d['price'];
            $regiones[] = ['regionCode' => $rc, 'newSubscriberAvailability' => true, 'price' => $precio_region];
        }
        $plan_base = 'base-' . strtolower(str_replace(['P', 'M', 'Y'], ['', 'm', 'y'], $p['periodo']));
        $cuerpo = [
            'packageName' => play_paquete(), 'productId' => $id,
            'listings' => [['languageCode' => play_idioma(), 'title' => $p['titulo'], 'description' => $p['descripcion'], 'benefits' => array_values(array_filter($p['beneficios']))]],
            'basePlans' => [[
                'basePlanId' => $plan_base,
                'autoRenewingBasePlanType' => ['billingPeriodDuration' => $p['periodo'], 'gracePeriodDuration' => 'P3D', 'resubscribeState' => 'RESUBSCRIBE_STATE_ACTIVE',
                    'prorationMode' => 'SUBSCRIPTION_PRORATION_MODE_CHARGE_ON_NEXT_BILLING_DATE', 'legacyCompatible' => true],
                'regionalConfigs' => $regiones,
                'otherRegionsConfig' => ['usdPrice' => play_precio_api($p['precio']), 'eurPrice' => $conv['convertedOtherRegionsPrice']['eurPrice'] ?? ['currencyCode' => 'EUR', 'units' => (string)ceil($p['precio']), 'nanos' => 0], 'newSubscriberAvailability' => true],
            ]],
        ];
        [$c2, $r2] = play_http('POST', play_url_api('/subscriptions') . '?productId=' . rawurlencode($id) . '&regionsVersion.version=2022%2F02', $cuerpo);
        if ($c2 !== 200) {
            $log[] = '✖ ' . $id . ': Google no lo creó (' . $c2 . '): ' . play_error_texto($r2);
            continue;
        }
        [$c3, $r3] = play_http('POST', play_url_api('/subscriptions/' . rawurlencode($id) . '/basePlans/' . rawurlencode($plan_base) . ':activate'), ['packageName' => play_paquete(), 'productId' => $id, 'basePlanId' => $plan_base]);
        $log[] = ($c3 === 200 ? '➕ ' : '⚠ ') . $id . ' creado a ' . dinero($p['precio']) . ($c3 === 200 ? ' y activado' : ' pero NO se activó: ' . play_error_texto($r3) . ' (actívelo en Play Console)');
    }
    bitacora_evento('ventas', 'Productos de Google Play revisados/creados (' . count($log) . ')');
    return $log;
}

/* ============================================================
 * 6) DIGITAL ASSET LINKS (para que la app abra el sitio sin barra de direcciones)
 * ============================================================ */

function play_huellas(): array
{
    $h = [PLAY_HUELLA_SUBIDA]; // (13 nov 2026) llave de subida de Tukán
    if (TV_HUELLA_FIRMA !== '') $h[] = TV_HUELLA_FIRMA;
    foreach (preg_split('/[\s,;]+/', strtoupper((string)ajuste('play_huellas', ''))) as $x) {
        $x = trim($x);
        if (preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $x)) $h[] = $x;
        elseif (preg_match('/^[0-9A-F]{64}$/', $x)) $h[] = implode(':', str_split($x, 2));
    }
    return array_values(array_unique($h));
}

function play_assetlinks(): array
{
    $rel = ['delegate_permission/common.handle_all_urls'];
    $r = [];
    // (13 nov 2026) la app de Google Play sale cuando Google Play está encendido (app/config.php 'google_play' => true; lo deja vps/app_android.sh)
    if (play_permitido()) $r[] = ['relation' => $rel, 'target' => ['namespace' => 'android_app', 'package_name' => play_paquete(), 'sha256_cert_fingerprints' => play_huellas()]];
    if (TV_HUELLA_FIRMA !== '') $r[] = ['relation' => $rel, 'target' => ['namespace' => 'android_app', 'package_name' => APK_PAQUETE, 'sha256_cert_fingerprints' => [TV_HUELLA_FIRMA]]];
    return $r;
}

/* ============================================================
 * 7) APK DIRECTO
 * ============================================================ */

function apk_archivo(): string
{
    return PUBLIC_DIR . '/descargas/tuacademia.apk';
}

function apk_disponible(): bool
{
    return is_file(apk_archivo());
}

/** Tarjeta "Llévelo en su celular" (página de la app y portada). En la app de Play no se muestra. */
function html_descargar_app(bool $compacta = false): string
{
    if (in_array(app_origen(), ['play', 'apk'], true) && !es_super()) return '';
    $h = '<div class="tarjeta app-descarga"><h2 style="margin-top:0">📲 ' . e(marca()) . ' en su celular</h2>';
    if (!$compacta) $h .= '<p class="suave" style="margin-top:0">Elija la forma que prefiera. Las tres funcionan igual.</p>';
    if (play_activo()) $h .= '<p><a class="btn btn-grande" href="' . e(play_url()) . '" target="_blank" rel="noopener">▶ Google Play (Android)</a></p>';
    if (apk_disponible()) $h .= '<p><a class="btn btn-grande btn-sec" href="descargar.php?apk=1">⬇ Descargar la app para Android (APK)</a></p>';
    $h .= '<p class="suave" style="margin:0"><b>Sin descargar nada:</b> Android (Chrome) menú ⋮ → <b>Instalar aplicación</b>. iPhone (Safari) botón compartir <b>⬆</b> → <b>Agregar a inicio</b>.' . (apk_disponible() ? ' <a href="descargar.php">Ayuda para instalar el APK</a>.' : '') . '</p>';
    return $h . '</div>';
}

/* ============================================================
 * 8) MI PLAN DENTRO DE LA APP DE GOOGLE PLAY
 * ============================================================ */

/** Compras vigentes de Google Play de una cuenta. */
function play_compras_vigentes(int $liga_id): array
{
    return filas("SELECT * FROM play_compras WHERE liga_id = ? AND vence_ts > ? AND estado IN ('SUBSCRIPTION_STATE_ACTIVE','SUBSCRIPTION_STATE_CANCELED','SUBSCRIPTION_STATE_IN_GRACE_PERIOD') ORDER BY vence_ts DESC", [$liga_id, time()]);
}

/** Bloque de Mi plan en la app de Play: planes y deportes adicionales con el botón de Google Play. */
function html_mi_plan_play(array $liga, array $planes): string
{
    $tipo = ($liga['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
    $vig = play_compras_vigentes((int)$liga['id']);
    $plan_vig = array_values(array_filter($vig, fn($c) => $c['tipo'] === 'plan'));
    $h = '<div id="play-caja" data-csrf="' . e(csrf_token()) . '" data-activo="' . (play_activo() ? '1' : '0') . '">';
    if (!play_activo()) {
        $h .= '<div class="aviso aviso-info">El pago dentro de la app estará disponible muy pronto. Su cuenta sigue funcionando con normalidad.</div>';
    }
    $h .= '<div class="aviso aviso-info" id="play-sin-google" style="display:none">Para pagar, abra ' . e(marca()) . ' con la app instalada desde Google Play.</div>';
    $h .= '<p id="play-msg" class="aviso" style="display:none"></p>';
    if ($plan_vig) {
        $c = $plan_vig[0];
        $h .= '<div class="aviso aviso-ok">📱 Su plan se paga con <b>Google Play</b> (' . e(play_nombre_estado($c['estado'])) . ', hasta el ' . e($c['vence']) . ($c['renueva'] ? ', se renueva solo' : '') . '). '
            . 'Para cambiar de plan o cancelar: <a href="https://play.google.com/store/account/subscriptions?package=' . e(play_paquete()) . '" target="_blank" rel="noopener">Suscripciones de Google Play</a>.</div>';
    }
    $h .= '<div class="planes">';
    foreach ($planes as $pl) {
        $ops = opciones_precio_play($pl);
        if (!$ops) continue;
        $h .= '<div class="plan"><h3>' . e($pl['nombre']) . '</h3><p class="suave" style="margin:0 0 6px">' . e((string)$pl['eslogan']) . '</p><ul>';
        foreach (textos_limites_plan($pl, $tipo) as $t) $h .= '<li>' . e($t) . '</li>';
        $h .= (es_academia() ? '' : '<li>' . e('Incluye 1 deporte') . '</li>') . '</ul>';
        foreach ($ops as $o) {
            $h .= '<button type="button" class="btn btn-grande play-comprar" style="margin-bottom:6px" data-producto="' . e($o['play_id']) . '">▶ <span class="play-precio" data-producto="' . e($o['play_id']) . '">' . dinero($o['precio']) . '</span> ' . e($o['texto'])
                . ($o['gratis'] ? ' <small>(' . texto_ahorro($o['gratis']) . ')</small>' : '') . '</button>';
        }
        $h .= '</div>';
    }
    $h .= '</div>';
    if (es_academia()) { // (13 nov 2026) en TuAcademia en Google Play solo se venden los dos planes
        return $h . '<p class="suave" style="font-size:.85rem">Los pagos los cobra Google Play y se renuevan solos hasta que los cancele en Google Play. Los precios pueden variar según su país; el valor exacto lo muestra Google antes de pagar.</p></div><script src="assets/play.js?v=380"></script>';
    }
    // Deportes adicionales
    $mods = modulos_deporte(true);
    $tiene = deportes_liga($liga);
    $h .= '<h2>🧩 Deportes adicionales</h2><p class="suave">Su plan incluye un deporte. Cada deporte adicional se paga aparte, cada mes, con Google Play.</p><div class="planes">';
    foreach ($mods as $k => $m) {
        if (precio_web_modulo_extra($k) <= 0) continue;
        $id = play_id_modulo($k);
        $c_mod = array_values(array_filter($vig, fn($c) => $c['tipo'] === 'modulo' && $c['modulo'] === $k));
        $h .= '<div class="plan"><h3>' . $m['icono'] . ' ' . e($m['nombre']) . '</h3>';
        if ($c_mod) $h .= '<p class="aviso aviso-ok" style="margin:0">✅ Activo con Google Play hasta el ' . e($c_mod[0]['vence']) . '</p>';
        elseif (in_array($k, $tiene, true) && (string)($liga['deportes'] ?? '*') !== '*') $h .= '<p class="suave" style="margin:0">✅ Ya está en su cuenta.</p>';
        else $h .= '<button type="button" class="btn play-comprar" data-producto="' . e($id) . '">▶ <span class="play-precio" data-producto="' . e($id) . '">' . dinero(precio_play(precio_web_modulo_extra($k))) . '</span> al mes</button>';
        $h .= '</div>';
    }
    $h .= '</div><p class="suave" style="font-size:.85rem">Los pagos los cobra Google Play y se renuevan solos hasta que los cancele en Google Play. Los precios pueden variar según su país; el valor exacto lo muestra Google antes de pagar.</p>';
    return $h . '</div><script src="assets/play.js?v=380"></script>';
}

/* ============================================================
 * 9) TABLA PROPIA (independiente del número de versión de la base)
 * La parte de las apps se guarda aparte y se une a cualquier versión futura, así que NO usa PRAGMA user_version:
 * crea su tabla la primera vez que se carga (y anota play_esquema = 1 en ajustes).
 * ============================================================ */

function play_preparar(): void
{
    static $listo = false;
    if ($listo) return;
    $listo = true;
    if ((string)ajuste('play_esquema', '0') === '1') return;
    db()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS play_compras (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    producto TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'plan',
    plan_id INTEGER REFERENCES planes(id) ON DELETE SET NULL,
    modulo TEXT NOT NULL DEFAULT '',
    meses INTEGER NOT NULL DEFAULT 1,
    token TEXT NOT NULL UNIQUE,
    pedido TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT '',
    vence TEXT NOT NULL DEFAULT '',
    vence_ts INTEGER NOT NULL DEFAULT 0,
    aplicado_ts INTEGER NOT NULL DEFAULT 0,
    renueva INTEGER NOT NULL DEFAULT 0,
    reconocida INTEGER NOT NULL DEFAULT 0,
    modulo_agregado INTEGER NOT NULL DEFAULT 0,
    prueba INTEGER NOT NULL DEFAULT 0,
    monto REAL NOT NULL DEFAULT 0,
    moneda TEXT NOT NULL DEFAULT 'USD',
    error TEXT NOT NULL DEFAULT '',
    revisado TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_play_compras_liga ON play_compras(liga_id);
SQL);
    // (TuAcademia) la novedad «ahora es app para Android» se publica cuando exista la app (todavía no hay llave ni APK)
    guardar_ajuste('play_esquema', '1');
}

/* ============================================================
 * 10) CUENTA PARA EL REVISOR DE GOOGLE (13 nov 2026)
 * Google pide una cuenta de prueba para revisar la app. Se arma una ACADEMIA DE EJEMPLO aparte («Academia Ejemplo Tukán», datos
 * inventados, con la misma receta de la Academia Demo) y el usuario revisor.google@tukanec.com (dueño/administrador de esa academia),
 * con el plan Completo activo por 1 año sin pagos a Tukán. La crea y la borra app/revisor_google.php (solo CLI; la clave sale una vez).
 * Nunca toca otra academia ni otro usuario.
 * ============================================================ */

const REVISOR_GOOGLE_CORREO = 'revisor.google@tukanec.com';
const REVISOR_GOOGLE_ACADEMIA = 'Academia Ejemplo Tukán';
const REVISOR_GOOGLE_SLUG = 'academia-ejemplo-tukan';

/** Academia de ejemplo del revisor (null si no existe). */
function revisor_google_academia(): ?array
{
    return fila('SELECT * FROM ligas WHERE slug = ?', [REVISOR_GOOGLE_SLUG]);
}

/** Plan Completo de TuAcademia (o, si no está, el plan activo de academias más caro). */
function revisor_google_plan(): ?array
{
    return fila("SELECT * FROM planes WHERE slug = 'academia-completo' AND activo = 1")
        ?? fila("SELECT * FROM planes WHERE activo = 1 AND tipo_cuenta IN ('escuela', 'ambos') ORDER BY precio_mensual DESC, id DESC LIMIT 1");
}

/** Revisa que el correo y la dirección del revisor no sean de otra cuenta (si lo son, se niega). */
function revisor_google_revisar(?array $l): void
{
    $u = fila('SELECT * FROM usuarios WHERE lower(email) = ?', [REVISOR_GOOGLE_CORREO]);
    if ($u && $u['rol'] === 'super') throw new RuntimeException('El correo ' . REVISOR_GOOGLE_CORREO . ' es de un superadministrador: no se toca.');
    if ($l && ($l['origen'] ?? '') !== 'google_play') throw new RuntimeException('La dirección ' . REVISOR_GOOGLE_SLUG . ' es de otra academia (no la del revisor): no se toca.');
    if ($u && (!$l || (int)$u['liga_id'] !== (int)$l['id'])) throw new RuntimeException('El correo ' . REVISOR_GOOGLE_CORREO . ' es de otra cuenta: no se toca.');
}

/**
 * Crea (o vuelve a crear con fechas de hoy y clave nueva) la academia de ejemplo y su usuario.
 * Devuelve ['clave', 'liga_id', 'alumnos', 'cursos', 'plan', 'vence'].
 */
function revisor_google_crear(): array
{
    $l = revisor_google_academia();
    revisor_google_revisar($l);
    if ($l) revisor_google_borrar();
    $clave = (strlen((string) getenv('REVISOR_CLAVE')) >= 8 ? (string) getenv('REVISOR_CLAVE') : 'Demo-' . random_int(100000, 999999));  // (13 nov 2026) REVISOR_CLAVE=... fija la clave
    $pl = revisor_google_plan();
    $vence = date('Y-m-d', strtotime('+1 year'));
    $lid = crear_academia_demo([
        'liga' => [
            'nombre' => REVISOR_GOOGLE_ACADEMIA, 'slug' => REVISOR_GOOGLE_SLUG, 'ciudad' => 'Ciudad Ejemplo', 'color' => '#5b2b8a',
            'admin_nombre' => 'Revisor de Google Play', 'admin_email' => REVISOR_GOOGLE_CORREO, 'admin_clave' => $clave,
            'plan_id' => $pl['id'] ?? null, 'vence' => $vence, 'origen' => 'google_play',
        ],
        'semilla' => 'revisor-google',
    ]);
    sin_auditoria(function () use ($lid): void {
        q("UPDATE ligas SET contacto_nombre = 'Revisión de Google Play', es_prueba = 0, es_demo = 0, notas_admin = ? WHERE id = ?",
            ['Cuenta de ejemplo para el revisor de Google Play (datos inventados, sin pagos a Tukán). Se borra con: php app/revisor_google.php borrar', $lid]);
        q("UPDATE usuarios SET perfil = 'admin', totp_activo = 0, totp_secreto = '' WHERE liga_id = ? AND lower(email) = ?", [$lid, REVISOR_GOOGLE_CORREO]);
        // Sin teléfonos que puedan ser de alguien de verdad (los botones de WhatsApp de «Mensajes de hoy» van a un número de ejemplo)
        q("UPDATE esc_alumnos SET representante_telefono = '0990000000', emergencia_telefono = '0990000000' WHERE liga_id = ?", [$lid]);
        q("UPDATE esc_profesores SET telefono = '' WHERE liga_id = ?", [$lid]);
    });
    bitacora_evento('ligas', 'Creó la cuenta de ejemplo para el revisor de Google Play (' . REVISOR_GOOGLE_CORREO . ')', $lid, $lid);
    return ['clave' => $clave, 'liga_id' => $lid, 'plan' => (string)($pl['nombre'] ?? ''), 'vence' => $vence,
        'alumnos' => (int)valor('SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ?', [$lid]),
        'cursos' => (int)valor('SELECT COUNT(*) FROM esc_grupos WHERE liga_id = ?', [$lid])];
}

/** Borra la academia de ejemplo del revisor y sus usuarios (solo esa: la de esa dirección y origen Google Play). Devuelve 1 o 0. */
function revisor_google_borrar(): int
{
    $l = revisor_google_academia();
    revisor_google_revisar($l);
    if (!$l) return 0;
    sin_auditoria(function () use ($l): void {
        q('DELETE FROM usuarios WHERE liga_id = ?', [$l['id']]);
        q('DELETE FROM ligas WHERE id = ?', [$l['id']]);
    });
    bitacora_evento('ligas', 'Borró la cuenta de ejemplo del revisor de Google Play (' . REVISOR_GOOGLE_CORREO . ')');
    return 1;
}

play_preparar();
