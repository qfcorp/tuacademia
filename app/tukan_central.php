<?php
/*
 * TUKÁN CENTRAL — conector con el Panel Tukán Central (panel.tukanec.com), protocolos v1 y v2.
 * El MISMO archivo sirve para TuVocalía y TuAcademia (se distinguen por la constante PRODUCTO y por app/academia.php).
 * Contrato: tukan-central/docs/PROTOCOLO.md (v1) y PROTOCOLO_V2.md (v2).
 *
 * - Entrada pública: public/tukan_central.php
 *     v1 (GET, token en ?t=):         accion=sso (con «ir» opcional, enlace directo) · accion=estado
 *     v2 (POST JSON, token en la cabecera X-Tukan-Token, con hash del cuerpo «bh»):
 *        capacidades · clientes · cliente · cotizar · planes · solicitudes · solicitud · adjunto · finanzas · ejecutar
 * - Configuración, con los nombres del protocolo (el primero que tenga valor gana):
 *     1) variables de entorno  TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET, TUKAN_CENTRAL_URL, TUKAN_SOLO_CENTRAL
 *     2) data/tukan_central.env  (líneas CLAVE=valor; lo escribe vps/conectar_central.sh; data/ no va a GitHub)
 *     3) app/config.php          (return ['TUKAN_CENTRAL_ID' => '...', ...])
 * - Sin ID o sin secreto (o secreto de menos de 32 caracteres) el conector está APAGADO: tukan_central.php da 404
 *   (código «sin_configurar») y todo sigue como antes (TUKAN_SOLO_CENTRAL tampoco hace nada).
 * - Tablas propias (se crean solas, ver esquema_tukan_central() en app/db.php): tukan_nonces, tukan_central_operaciones,
 *   tukan_central_atenciones, ligas_borradas; columnas ligas.origen, ligas.notas_admin, ligas.suspendida_motivo y (Anexo A) suspendida_tipo,
 *   suspendida_en y eliminada* (papelera).
 * - (Anexo A, 15 oct 2026) pausar · reactivar · borrar_cliente · restaurar_cliente (app/papelera.php): la pausa reutiliza la suspensión
 *   real con tipo y motivo; borrar = copia completa en data/borrados/ + papelera de 30 días + borrado definitivo por la tarea automática.
 *   Las cuentas de la papelera no salen en estado, clientes (salvo con estado «papelera»), solicitudes ni finanzas (sus pagos de
 *   antes de borrarla sí quedan en los meses pasados).
 * - Las reglas de negocio son las del programa (app/cuentas_super.php y las funciones de siempre): el panel solo pide.
 * - Todo «ejecutar» deja una línea en la bitácora («Panel Tukán Central · Nombre <correo>», operación tukan_central).
 */

const TC_PROTOCOLO = 1;          // estado y sso siguen siendo v1
const TC_PROTOCOLO_MAX = 2;
const TC_REVISION = 2;           // sube cuando se agregan cosas al v2 sin romper nada (2: Anexo A, pausar y papelera)
const TC_LIMITE_MINUTO = 120;    // pedidos por minuto por IP (todos)
const TC_LIMITE_RECHAZOS = 30;   // pedidos rechazados (401) por minuto por IP
const TC_ACCIONES_V2 = ['capacidades', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar'];
const TC_OPERACIONES = ['crear_demo', 'extender_prueba', 'registrar_pago', 'anular_pago', 'aprobar_comprobante', 'rechazar_comprobante',
    'suspender', 'reactivar', 'cambiar_plan', 'nueva_clave_dueno', 'nota_interna', 'atender_solicitud', 'responder_ticket',
    'pausar', 'borrar_cliente', 'restaurar_cliente']; // (Anexo A)
const TC_TIPOS_SOLICITUD = ['registro_nuevo', 'demo_pedida', 'comprobante', 'soporte', 'derechos_datos', 'pago_automatico',
    'seguimiento_prueba', 'renovacion', 'dominio_gratis', 'comision_vendedor', 'liquidacion_pendiente'];
const TC_ESTADOS = ['prueba', 'activo', 'por_vencer', 'vencido', 'suspendido'];
const TC_RUTAS_SSO = ['/admin.php'];

/** Error del protocolo: código de la tabla §3.2, mensaje en español sencillo, HTTP, campo y detalle opcionales. */
class TcError extends RuntimeException
{
    public function __construct(public string $codigo, string $mensaje, public int $http = 400, public ?string $campo = null, public array $detalle = [])
    {
        parent::__construct($mensaje);
    }
}

/** Configuración del conector. */
function tc_config(bool $recargar = false): array
{
    static $c = null;
    if ($c !== null && !$recargar) return $c;
    $archivo = [];
    $ruta = RAIZ . '/data/tukan_central.env';
    if (is_file($ruta) && is_readable($ruta)) {
        foreach (@file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
            [$k, $v] = explode('=', $l, 2);
            $archivo[trim($k)] = trim(trim($v), "\"'");
        }
    }
    $leer = function (string $k) use ($archivo): string {
        $v = getenv($k);
        if (is_string($v) && trim($v) !== '') return trim($v);
        if (($archivo[$k] ?? '') !== '') return $archivo[$k];
        return trim((string)(CFG[$k] ?? ''));
    };
    $url = $leer('TUKAN_CENTRAL_URL');
    if (!preg_match('#^https?://[^\s"\'<>]+$#', $url)) $url = 'https://panel.tukanec.com';
    $c = [
        'id'           => $leer('TUKAN_CENTRAL_ID'),
        'secreto'      => $leer('TUKAN_CENTRAL_SECRET'),
        'url'          => rtrim($url, '/'),
        'solo_central' => in_array(strtolower($leer('TUKAN_SOLO_CENTRAL')), ['1', 'si', 'sí', 'true', 'yes'], true),
    ];
    $c['activo'] = (bool)preg_match('/^[a-z0-9_-]{2,40}$/', $c['id']) && strlen($c['secreto']) >= 32;
    return $c;
}

/** ¿Está conectado al panel central? */
function tc_activo(): bool
{
    return tc_config()['activo'];
}

/** TUKAN_SOLO_CENTRAL=1 (y el conector encendido): el superadministrador ya no entra con su clave, solo desde el panel. */
function tc_solo_central(): bool
{
    $c = tc_config();
    return $c['activo'] && $c['solo_central'];
}

/** ¿La sesión actual vino del panel central? (para el enlace «← Panel Tukán») */
function tc_vino_de_central(): bool
{
    return !empty($_SESSION['tukan_central']) && es_super();
}

/** Enlace «← Panel Tukán» del panel del superadministrador (vacío si la sesión no vino del central). */
function tc_enlace_volver(): string
{
    if (!tc_vino_de_central()) return '';
    return '<a class="tc-volver" href="' . e(tc_config()['url']) . '" style="color:#fff;background:rgba(255,255,255,.14);border-radius:999px;padding:3px 10px;font-size:.85rem;text-decoration:none;white-space:nowrap" title="Volver al Panel Tukán Central">← Panel Tukán</a>';
}

/** Producto de esta instalación: [clave, nombre, ¿academia?]. */
function tc_producto(): array
{
    $acad = defined('PRODUCTO') && PRODUCTO === 'academia';           // modo academia: solo las academias son negocios, y cifras de academias en «extra»
    $es_tuacademia = $acad || is_file(APP_DIR . '/academia.php');      // app/academia.php solo existe en el código de TuAcademia
    return $es_tuacademia ? ['tuacademia', 'TuAcademia', $acad] : ['tuvocalia', 'TuVocalía', false];
}

/**
 * Filtro SQL sobre «l» (ligas) de las cuentas que son clientes: sin las de la PAPELERA (Anexo A) y, en modo academia (TuAcademia),
 * solo las academias (tipo escuela). $con_papelera: también las de la papelera (finanzas de meses pasados, «clientes» papelera).
 */
function tc_solo(bool $con_papelera = false): string
{
    return (tc_producto()[2] ? " AND COALESCE(l.tipo, 'liga') = 'escuela'" : '') . ($con_papelera ? '' : " AND COALESCE(l.eliminada, '') = ''");
}

/** (Anexo A) Pagos de una cuenta de la papelera: solo cuentan los de ANTES de borrarla (hasta ese día). $col = columna de la fecha del pago. */
function tc_pago_antes_de_borrar(string $col): string
{
    return " AND (COALESCE(l.eliminada, '') = '' OR substr($col, 1, 10) <= substr(l.eliminada, 1, 10))";
}

/* ---------- Token (protocolo §3) ---------- */

function tc_b64(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function tc_unb64(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/'), true);
}

/** Firma un token (lo usan las pruebas y la autoprueba de vps/conectar_central.sh). */
function tc_firmar(array $p, string $secreto): string
{
    $a = tc_b64(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $a . '.' . tc_b64(hash_hmac('sha256', $a, $secreto, true));
}

/** Tabla de nonces de un solo uso (se crea sola; las tablas v2 las crea esquema_tukan_central()). */
function tc_preparar(): void
{
    static $listo = false;
    if ($listo) return;
    $listo = true;
    db()->exec('CREATE TABLE IF NOT EXISTS tukan_nonces (nonce TEXT PRIMARY KEY, exp INTEGER NOT NULL, creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
}

/** Gasta un nonce (32 hex) de un solo uso; lanza TcError «nonce» si ya se usó o no es válido. */
function tc_usar_nonce(mixed $nonce, int $exp): void
{
    if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/i', $nonce)) throw new TcError('nonce', 'Token sin nonce válido.', 401);
    tc_preparar();
    $pdo = db();
    $pdo->prepare('DELETE FROM tukan_nonces WHERE exp < ?')->execute([time() - 120]);
    $st = $pdo->prepare('INSERT OR IGNORE INTO tukan_nonces (nonce, exp) VALUES (?, ?)');
    $st->execute([strtolower($nonce), $exp]);
    if ($st->rowCount() !== 1) throw new TcError('nonce', 'Este token ya se usó. Vuelva a intentarlo desde el panel.', 401);
}

/**
 * Valida un token v1 (sso y estado) en el orden del protocolo v1 §3. Devuelve el contenido o lanza TcError con su código.
 * (El límite por IP se revisa antes, en tc_atender(), para que cuente todos los pedidos.)
 */
function tc_validar(string $token, string $accion): array
{
    $cfg = tc_config();
    // 1) dos partes y firma correcta
    $partes = explode('.', $token);
    if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') throw new TcError('firma', 'Token mal formado.', 401);
    $firma = tc_b64(hash_hmac('sha256', $partes[0], $cfg['secreto'], true));
    if (!hash_equals($firma, $partes[1])) throw new TcError('firma', 'Firma incorrecta.', 401);
    $json = tc_unb64($partes[0]);
    $p = $json === false ? null : json_decode($json, true);
    if (!is_array($p)) throw new TcError('firma', 'Token ilegible.', 401);
    // 2) versión, tipo e instancia
    if (($p['v'] ?? null) !== 1) throw new TcError('version', 'Versión de protocolo no soportada.', 401);
    if (($p['tipo'] ?? '') !== $accion) throw new TcError('tipo', 'El token no es para esta acción.', 401);
    if (!is_string($p['inst'] ?? null) || !hash_equals($cfg['id'], $p['inst'])) throw new TcError('inst', 'El token es de otra instancia.', 401);
    // 3) tiempo (30 s de tolerancia de reloj, máximo 60 s de vida)
    $iat = $p['iat'] ?? null;
    $exp = $p['exp'] ?? null;
    if (!is_int($iat) || !is_int($exp)) throw new TcError('invalido', 'Token sin fechas válidas.', 401);
    $ahora = time();
    if ($ahora > $exp + 30) throw new TcError('vencido', 'El token venció. Vuelva a intentarlo desde el panel.', 401);
    if ($iat > $ahora + 30) throw new TcError('futuro', 'El token tiene fecha futura (revise la hora del servidor).', 401);
    if ($exp < $iat || $exp - $iat > 60) throw new TcError('invalido', 'El token dura más de lo permitido.', 401);
    // 4) nonce de un solo uso
    tc_usar_nonce($p['nonce'] ?? null, $exp);
    // 5) correo válido
    if (!is_string($p['email'] ?? null) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) throw new TcError('invalido', 'Correo inválido en el token.', 401);
    return $p;
}

/**
 * Valida un pedido v2 (protocolo v2 §3.1, pasos 3 a 14). Devuelve [payload del token, cuerpo JSON decodificado].
 */
function tc2_validar(string $accion): array
{
    $cfg = tc_config();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new TcError('invalido', 'Este pedido debe llegar por POST.', 405);
    $tipo_c = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''))[0]));
    if ($tipo_c !== 'application/json') throw new TcError('invalido', 'El contenido debe ser JSON (Content-Type: application/json).', 415);
    $cuerpo = (string)file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($cuerpo) > 65536) throw new TcError('invalido', 'El pedido es demasiado grande (máximo 64 KiB).', 413);
    $token = (string)($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? '');
    $partes = explode('.', $token);
    if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '' || !hash_equals(tc_b64(hash_hmac('sha256', $partes[0], $cfg['secreto'], true)), $partes[1])) {
        throw new TcError('firma', 'La firma del pedido no es correcta.', 401);
    }
    $json = tc_unb64($partes[0]);
    $p = $json === false ? null : json_decode($json, true);
    if (!is_array($p)) throw new TcError('firma', 'El pedido no es válido.', 401);
    if (($p['v'] ?? null) !== 2) throw new TcError('version', 'Versión de protocolo no soportada.', 401);
    if (($p['tipo'] ?? null) !== $accion) throw new TcError('tipo', 'El pedido no es para esta acción.', 401);
    if (!is_string($p['inst'] ?? null) || !hash_equals($cfg['id'], $p['inst'])) throw new TcError('inst', 'El pedido es de otra instalación.', 401);
    $iat = $p['iat'] ?? null;
    $exp = $p['exp'] ?? null;
    $ahora = time();
    $max = $accion === 'ejecutar' ? 30 : 60;
    if (!is_int($iat) || !is_int($exp) || $exp < $iat || $exp - $iat > $max) throw new TcError('invalido', 'El pedido dura más de lo permitido.', 401);
    if ($ahora > $exp + 30) throw new TcError('vencido', 'El pedido venció. Intente otra vez desde el Panel Tukán.', 401);
    if ($iat > $ahora + 30) throw new TcError('futuro', 'El pedido tiene fecha futura (revise la hora del servidor).', 401);
    if (!is_string($p['bh'] ?? null) || !hash_equals(tc_b64(hash('sha256', $cuerpo, true)), $p['bh'])) {
        throw new TcError('firma', 'El contenido del pedido cambió en el camino.', 401, null, ['parte' => 'cuerpo']);
    }
    tc_usar_nonce($p['nonce'] ?? null, $exp);
    if (!is_string($p['email'] ?? null) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) throw new TcError('invalido', 'Correo inválido en el pedido.', 401);
    $datos = json_decode($cuerpo, true, 9);
    if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) throw new TcError('invalido', 'El contenido no es un objeto JSON.', 400);
    $p['_cuerpo_hash'] = $p['bh'];
    return [$p, $datos];
}

/* ---------- Respuestas ---------- */

function tc_json(int $codigo, array|object $datos, array $cabeceras = []): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($cabeceras as $k => $v) header($k . ': ' . $v);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Cuerpo JSON de un error (protocolo v2 §3.2). */
function tc_error_json(TcError $e): array
{
    $r = ['ok' => false, 'protocolo' => TC_PROTOCOLO_MAX, 'codigo' => $e->codigo, 'error' => $e->getMessage()];
    if ($e->campo !== null) $r['campo'] = $e->campo;
    if ($e->detalle) $r['detalle'] = $e->detalle;
    return $r;
}

function tc_responder_error(TcError $e): never
{
    if ($e->http === 401) { try { anotar_intento('tukan_central_mal'); } catch (Throwable) {} }
    tc_json($e->http, tc_error_json($e), $e->codigo === 'limite' ? ['Retry-After' => '60'] : []);
}

function tc_pagina_error(int $codigo, string $mensaje, string $cod = ''): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($codigo);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($cod !== '') header('X-Tukan-Codigo: ' . $cod);
    if ($cod === 'limite') header('Retry-After: 60');
    $url = e(tc_config()['url']);
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>No se pudo entrar · Panel Tukán</title></head>'
        . '<body style="font-family:system-ui,sans-serif;background:#f4f6f5;margin:0;padding:40px 16px;color:#1d2a26">'
        . '<div style="max-width:460px;margin:0 auto;background:#fff;border-radius:14px;padding:26px;box-shadow:0 2px 10px rgba(0,0,0,.08)">'
        . '<h1 style="font-size:1.3rem;margin-top:0">No se pudo entrar desde el Panel Tukán</h1>'
        . '<p>' . e($mensaje) . '</p>'
        . '<p><a href="' . $url . '" style="display:inline-block;background:#0f1614;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">← Volver al Panel Tukán</a></p>'
        . '</div></body></html>';
    exit;
}

/** Respuesta correcta v2 (protocolo v2 §2.3). */
function tc2_ok(string $accion, array $campos): never
{
    tc_json(200, tc2_base($accion) + $campos);
}

function tc2_base(string $accion): array
{
    return ['ok' => true, 'protocolo' => TC_PROTOCOLO_MAX, 'accion' => $accion, 'producto' => tc_producto()[0], 'generado' => date('c')];
}

/** Atiende public/tukan_central.php. */
function tc_atender(): never
{
    $accion = get('accion');
    $es_sso = $accion === 'sso';
    if (!tc_activo()) {
        if ($es_sso) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Tukan-Codigo: sin_configurar');
            exit('No encontrado');
        }
        tc_json(404, tc_error_json(new TcError('sin_configurar', 'El conector con el Panel Tukán Central no está configurado.', 404)));
    }
    try {
        // límite por IP: 120 pedidos por minuto en total y 30 rechazados por minuto (cuentan también los rechazados)
        if (demasiados_intentos('tukan_central', TC_LIMITE_MINUTO, '-1 minute') || demasiados_intentos('tukan_central_mal', TC_LIMITE_RECHAZOS, '-1 minute')) {
            throw new TcError('limite', 'Demasiados pedidos desde esta conexión. Espere un minuto.', 429);
        }
        anotar_intento('tukan_central');
        if ($accion === 'sso' || $accion === 'estado') {
            $p = tc_validar(get('t'), $accion);
            if ($accion === 'estado') tc_json(200, tc_estado_completo());
            tc_sso($p);
        }
        if (!in_array($accion, TC_ACCIONES_V2, true)) {
            throw new TcError('no_permitido', 'Esta acción no está disponible en ' . tc_producto()[1] . '.', 403, null, ['motivo' => 'no_disponible']);
        }
        [$p, $datos] = tc2_validar($accion);
        tc2_despachar($accion, $p, $datos);
    } catch (TcError $e) {
        if ($es_sso) {
            if ($e->http === 401) { try { anotar_intento('tukan_central_mal'); } catch (Throwable) {} }
            tc_pagina_error($e->http, $e->getMessage(), $e->codigo);
        }
        tc_responder_error($e);
    } catch (Throwable $e) {
        error_log('tukan_central (' . $accion . '): ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
        if ($es_sso) tc_pagina_error(500, 'El programa tuvo un error interno. Intente en un momento.', 'interno');
        tc_json(500, tc_error_json(new TcError('interno', 'El programa tuvo un error interno. Intente en un momento.', 500)));
    }
}

/** Reparte las acciones v2. */
function tc2_despachar(string $accion, array $p, array $d): never
{
    match ($accion) {
        'capacidades' => tc2_ok('capacidades', tc2_capacidades()),
        'clientes'    => tc2_ok('clientes', tc2_clientes($d)),
        'cliente'     => tc2_ok('cliente', ['cliente' => tc2_cliente_ficha(tc2_id_requerido($d, 'id'))]),
        'cotizar'     => tc2_ok('cotizar', tc2_cotizar($d)),
        'planes'      => tc2_ok('planes', tc2_planes()),
        'solicitudes' => tc2_ok('solicitudes', tc2_solicitudes_pedido($d)),
        'solicitud'   => tc2_ok('solicitud', ['solicitud' => tc2_solicitud(tc2_id_requerido($d, 'id'), true) ?? throw new TcError('no_encontrado', 'Esa solicitud ya no existe en el programa.', 404)]),
        'adjunto'     => tc2_ok('adjunto', tc2_adjunto($d)),
        'finanzas'    => tc2_ok('finanzas', tc2_finanzas($d)),
        'ejecutar'    => tc2_ejecutar($p, $d),
    };
    exit;
}

/* ---------- SSO (protocolo §4; v2 §5.3: enlace directo «ir») ---------- */

/** ¿«ir» es una ruta permitida para el enlace directo? */
function tc_ir_valido(mixed $ir): bool
{
    if (!is_string($ir) || $ir === '' || strlen($ir) > 300) return false;
    if ($ir[0] !== '/' || str_starts_with($ir, '//') || str_contains($ir, '\\') || preg_match('/[\x00-\x1f\x7f]/', $ir)) return false;
    if (!preg_match("#^/[A-Za-z0-9._~!$&'()*+,;=:@%/?\\#-]*$#", $ir)) return false;
    foreach (TC_RUTAS_SSO as $r) {
        if ($ir === $r || str_starts_with($ir, $r . '?') || str_starts_with($ir, $r . '#')) return true;
    }
    return false;
}

function tc_sso(array $p): never
{
    $email = mb_strtolower(trim($p['email']), 'UTF-8');
    $nombre = trim(preg_replace('/\s+/', ' ', (string)($p['nombre'] ?? '')));
    if ($nombre === '') $nombre = (string)strstr($email, '@', true);
    $nombre = mb_substr($nombre, 0, 80);
    contexto_auditoria(['usuario_id' => null, 'usuario' => 'Panel Tukán Central <' . $email . '>', 'rol' => 'super', 'liga_id' => null, 'origen' => 'tukan_central']);
    $u = fila('SELECT * FROM usuarios WHERE lower(email) = ?', [$email]);
    if ($u && $u['rol'] !== 'super') {
        bitacora_evento('seguridad', 'Panel Tukán Central: se negó la entrada con ' . $email . ' porque ese correo es de un usuario que no es superadministrador', $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
        tc_pagina_error(403, 'El correo ' . $email . ' ya es de un usuario de un cliente (no es superadministrador). Use otro correo en el Panel Tukán o cambie ese usuario.', 'no_permitido');
    }
    if ($u && (int)$u['activo'] !== 1) {
        bitacora_evento('seguridad', 'Panel Tukán Central: se negó la entrada con ' . $email . ' porque ese superadministrador está desactivado', null, (int)$u['id']);
        tc_pagina_error(403, 'El superadministrador ' . $email . ' está desactivado en este programa.', 'no_permitido');
    }
    if (!$u) {
        // Clave aleatoria imposible de adivinar: este usuario solo entra desde el panel central (o con crear_admin.php)
        q("INSERT INTO usuarios (nombre, email, clave, rol) VALUES (?, ?, ?, 'super')", [$nombre !== '' ? $nombre : 'Super Tukán', $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        $u = fila('SELECT * FROM usuarios WHERE lower(email) = ?', [$email]);
        bitacora_evento('sesion', 'Superadministrador creado por el Panel Tukán Central', null, (int)$u['id']);
    }
    // Igual que el ingreso normal (admin/login.php → iniciar_sesion): id de sesión nuevo y usuario en la sesión
    session_regenerate_id(true);
    unset($_SESSION['2fa_uid'], $_SESSION['2fa_hora'], $_SESSION['2fa_fallos'], $_SESSION['liga_trabajo'],
        $_SESSION['vista_previa'], $_SESSION['liga_combo'], $_SESSION['jugador_portal'], $_SESSION['familia']);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['tukan_central'] = time();
    contexto_auditoria(['usuario_id' => (int)$u['id'], 'usuario' => $u['nombre'] . ' <' . $u['email'] . '>', 'rol' => 'super', 'liga_id' => null, 'origen' => 'tukan_central']);
    q('UPDATE usuarios SET ultimo_ingreso = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
    // (v2) enlace directo: solo rutas del panel (rutas_sso); si no es válido se abre el inicio, como en v1
    $ir = $p['ir'] ?? null;
    if ($ir === null || $ir === '') {
        bitacora_evento('sesion', 'Entró desde el Panel Tukán Central', null, (int)$u['id']);
        ir('admin.php');
    }
    if (tc_ir_valido($ir)) {
        bitacora_evento('sesion', 'Entró desde el Panel Tukán Central → ' . $ir, null, (int)$u['id']);
        ir($ir);
    }
    bitacora_evento('sesion', 'Entró desde el Panel Tukán Central (ir no válido: ' . mb_substr(preg_replace('/[^\x20-\x7e]/', '?', is_string($ir) ? $ir : json_encode($ir)), 0, 120) . ')', null, (int)$u['id']);
    ir('admin.php');
}

/* ---------- Estado (protocolo §5; v2 §2.4) ---------- */

/** Versión: commit corto de git (.git/HEAD), o app/VERSION (lo escribe vps_2_codigo.sh), o la versión de la base. */
function tc_version(): string
{
    $git = RAIZ . '/.git';
    if (is_file($git . '/HEAD')) {
        $h = trim((string)@file_get_contents($git . '/HEAD'));
        $hash = '';
        if (str_starts_with($h, 'ref: ')) {
            $ref = trim(substr($h, 5));
            if (preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) && is_file($git . '/' . $ref)) {
                $hash = trim((string)@file_get_contents($git . '/' . $ref));
            } elseif (is_file($git . '/packed-refs')) {
                foreach (@file($git . '/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                    if (str_ends_with($l, ' ' . $ref)) { $hash = strtok($l, ' '); break; }
                }
            }
        } else {
            $hash = $h;
        }
        if (preg_match('/^[0-9a-f]{7,40}$/', $hash)) return substr($hash, 0, 7);
    }
    if (is_file(APP_DIR . '/VERSION')) {
        $v = trim((string)@file_get_contents(APP_DIR . '/VERSION'));
        if ($v !== '') return mb_substr(preg_replace('/[^\w .:+-]/u', '', $v), 0, 40);
    }
    return 'bd' . (int)db()->query('PRAGMA user_version')->fetchColumn();
}

/** Número de una consulta, o null si esa tabla no existe en esta versión. */
function tc_cuenta(string $sql, array $p = []): ?int
{
    try {
        return (int)valor($sql, $p);
    } catch (Throwable) {
        return null;
    }
}

/**
 * Estado v2 de una cuenta (protocolo v2 §4.2), con la MISMA regla que cuenta el «estado» v1:
 *   suspendido = activa 0 · prueba = demo vigente (incluye «vence hoy») · por_vencer = pagada y vence en 0 a 7 días
 *   activo = pagada con más de 7 días, o sin vencimiento · vencido = ya venció (en gracia o en solo lectura), pagada o demo
 * Devuelve ['estado', 'detalle', 'en_gracia', 'bloqueado', 's' => estado_suscripcion()].
 */
function tc2_estado_de(array $l): array
{
    $s = estado_suscripcion($l);
    if ((int)($l['activa'] ?? 1) !== 1) return ['estado' => 'suspendido', 'detalle' => 'suspendida', 'en_gracia' => false, 'bloqueado' => true, 's' => $s];
    return match ($s['estado']) {
        'prueba' => ['estado' => 'prueba', 'detalle' => 'prueba', 'en_gracia' => false, 'bloqueado' => false, 's' => $s],
        'activa' => ['estado' => $s['dias'] <= 7 ? 'por_vencer' : 'activo', 'detalle' => 'activa', 'en_gracia' => false, 'bloqueado' => false, 's' => $s],
        'gracia' => ['estado' => 'vencido', 'detalle' => 'gracia', 'en_gracia' => true, 'bloqueado' => false, 's' => $s],
        'vencida' => ['estado' => 'vencido', 'detalle' => !empty($l['es_prueba']) ? 'prueba_vencida' : 'vencida', 'en_gracia' => false, 'bloqueado' => true, 's' => $s],
        default => ['estado' => 'activo', 'detalle' => 'sin_limite', 'en_gracia' => false, 'bloqueado' => false, 's' => $s],
    };
}

/**
 * Cifras del programa (protocolo v1 §5). Cada cuenta de cliente (fila de «ligas») es un negocio. En modo academia (TuAcademia) solo cuentan
 * las ACADEMIAS (tipo = 'escuela': toda cuenta que se crea en TuAcademia es academia); una liga que quede de la base de
 * TuVocalía no es un cliente de TuAcademia y no cuenta. Las demos del sistema (es_demo = 1) no cuentan.
 * Estados con tc2_estado_de() (los mismos de «clientes»).
 * pagos_vencidos = solo las pagadas que vencieron (desde = fecha de vencimiento); las pruebas vencidas no son pagos.
 * Ingresos = pagos de suscripción registrados (a mano, comprobante, tarjeta, Google Play) por fecha de pago.
 */
function tc_estado(): array
{
    [$clave, $nombre, $acad] = tc_producto();
    $neg = ['total' => 0, 'prueba' => 0, 'activos' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'suspendidos' => 0];
    $plural = ['prueba' => 'prueba', 'activo' => 'activos', 'por_vencer' => 'por_vencer', 'vencido' => 'vencidos', 'suspendido' => 'suspendidos'];
    $pruebas7 = [];
    $vencidos = [];
    $solo = tc_solo();
    foreach (filas("SELECT * FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0$solo ORDER BY l.id") as $l) {
        $neg['total']++;
        $x = tc2_estado_de($l);
        $neg[$plural[$x['estado']]]++;
        if ($x['estado'] === 'prueba' && $x['s']['dias'] <= 7) $pruebas7[] = ['nombre' => $l['nombre'], 'vence' => $l['vence']];
        if ($x['estado'] === 'vencido' && empty($l['es_prueba'])) $vencidos[] = ['nombre' => $l['nombre'], 'desde' => $l['vence']];
    }
    usort($pruebas7, fn($a, $b) => strcmp($a['vence'], $b['vence']));
    usort($vencidos, fn($a, $b) => strcmp($a['desde'], $b['desde']));

    $solo_p = tc_solo(true) . tc_pago_antes_de_borrar('p.fecha'); // (Anexo A) lo que pagó antes de ir a la papelera sigue contando
    $ingresos = function (string $ym) use ($solo_p): ?float {
        try {
            return round((float)valor("SELECT COALESCE(SUM(p.monto), 0) FROM pagos_suscripcion p JOIN ligas l ON l.id = p.liga_id
                                       WHERE COALESCE(l.es_demo, 0) = 0$solo_p AND substr(p.fecha, 1, 7) = ?", [$ym]), 2);
        } catch (Throwable) {
            return null;
        }
    };
    $mes = date('Y-m');
    $mes_ant = date('Y-m', strtotime('first day of last month'));
    $real = 'JOIN ligas l ON l.id = %s WHERE COALESCE(l.es_demo, 0) = 0' . $solo;
    $r = fn(string $col) => sprintf($real, $col);

    if ($acad) {
        // (TuAcademia) clases = clases de los cursos (esc_sesiones) + clases particulares dadas (acad_clases «asistio»);
        // asistencias = alumnos presentes o atrasados en las clases de los cursos + clases particulares a las que asistió el alumno;
        // pensiones = pensiones mensuales (esc_cargos tipo mensualidad) pagadas este mes, por fecha de pago
        $particulares = tc_cuenta("SELECT COUNT(*) FROM acad_clases c {$r('c.liga_id')} AND c.estado = 'asistio' AND substr(c.fecha, 1, 7) = ?", [$mes]) ?? 0;
        $sesiones = tc_cuenta("SELECT COUNT(*) FROM esc_sesiones s JOIN esc_grupos g ON g.id = s.grupo_id {$r('g.liga_id')} AND substr(s.fecha, 1, 7) = ?", [$mes]);
        $presentes = tc_cuenta("SELECT COUNT(*) FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id {$r('g.liga_id')} AND x.estado IN ('P', 'T') AND substr(s.fecha, 1, 7) = ?", [$mes]);
        $extra = [
            'Academias'                   => tc_cuenta("SELECT COUNT(*) FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0$solo"),
            'Alumnos activos'             => tc_cuenta("SELECT COUNT(*) FROM esc_alumnos a {$r('a.liga_id')} AND a.estado = 'activo'"),
            'Cursos activos'              => tc_cuenta("SELECT COUNT(*) FROM esc_grupos g {$r('g.liga_id')} AND g.activo = 1"),
            'Clases este mes'             => $sesiones === null ? null : $sesiones + $particulares,
            'Asistencias este mes'        => $presentes === null ? null : $presentes + $particulares,
            'Pensiones cobradas este mes' => tc_cuenta("SELECT COUNT(*) FROM esc_cargos c {$r('c.liga_id')} AND c.tipo = 'mensualidad' AND c.estado = 'pagado' AND substr(c.pagado_en, 1, 7) = ?", [$mes]),
            'Profesores'                  => tc_cuenta("SELECT COUNT(*) FROM esc_profesores pr {$r('pr.liga_id')} AND pr.activo = 1"),
            'Usuarios'                    => tc_cuenta("SELECT COUNT(*) FROM usuarios u LEFT JOIN ligas l ON l.id = u.liga_id WHERE u.activo = 1 AND (u.liga_id IS NULL OR (COALESCE(l.es_demo, 0) = 0$solo))"),
        ];
    } else {
        $extra = [
            'Campeonatos activos' => tc_cuenta("SELECT COUNT(*) FROM torneos t {$r('t.liga_id')} AND t.estado <> 'finalizado'"),
            'Equipos'             => tc_cuenta("SELECT COUNT(*) FROM equipos e {$r('e.liga_id')}"),
            'Partidos este mes'   => tc_cuenta("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id {$r('t.liga_id')} AND substr(p.fecha, 1, 7) = ?", [$mes]),
            'Escuelas'            => tc_cuenta("SELECT COUNT(*) FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0$solo AND COALESCE(l.tipo, 'liga') = 'escuela'"),
            'Alumnos activos'     => tc_cuenta("SELECT COUNT(*) FROM esc_alumnos a {$r('a.liga_id')} AND a.estado = 'activo'"),
            'Usuarios'            => tc_cuenta("SELECT COUNT(*) FROM usuarios u LEFT JOIN ligas l ON l.id = u.liga_id WHERE u.activo = 1 AND COALESCE(l.es_demo, 0) = 0 AND COALESCE(l.eliminada, '') = ''"),
        ];
    }
    $extra = array_filter($extra, fn($v) => $v !== null);

    return [
        'ok' => true,
        'protocolo' => TC_PROTOCOLO,
        'producto' => $clave,
        'nombre' => $nombre,
        'version' => tc_version(),
        'generado' => date('c'),
        'negocios' => $neg,
        'nuevos_30d' => tc_cuenta("SELECT COUNT(*) FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0$solo AND l.creado >= datetime('now', '-30 days')"),
        'ingresos_mes' => $ingresos($mes),
        'ingresos_mes_anterior' => $ingresos($mes_ant),
        'pruebas_vencen_7d' => array_slice($pruebas7, 0, 20),
        'pagos_vencidos' => array_slice($vencidos, 0, 20),
        'extra' => array_slice($extra, 0, 8, true),
    ];
}

/** Estado v1 con lo que agrega un conector v2 (v2 §2.4): protocolo_max, solicitudes_abiertas y su huella. */
function tc_estado_completo(): array
{
    $e = tc_estado();
    $e['protocolo_max'] = TC_PROTOCOLO_MAX;
    try {
        $abiertas = tc2_solicitudes_todas(['estado' => 'abiertas']);
        $e['solicitudes_abiertas'] = count($abiertas);
        $e['solicitudes_huella'] = tc2_huella($abiertas);
    } catch (Throwable $ex) {
        error_log('tukan_central estado/solicitudes: ' . $ex->getMessage());
    }
    return $e;
}

/** 16 hex: sha256 de los «id estado» de las solicitudes abiertas, ordenados. */
function tc2_huella(array $sols): string
{
    $x = array_map(fn($s) => $s['id'] . ' ' . $s['estado'], $sols);
    sort($x, SORT_STRING);
    return substr(hash('sha256', implode("\n", $x)), 0, 16);
}

/* ============================================================
 * v2 — TIPOS COMUNES (§4)
 * ============================================================ */

/** Momento ISO 8601 con zona. $utc: el texto está guardado en UTC (CURRENT_TIMESTAMP de SQLite); si no, en hora local. */
function tc2_momento(?string $t, bool $utc = false): ?string
{
    $t = trim((string)$t);
    if ($t === '') return null;
    try {
        $d = new DateTime($t, new DateTimeZone($utc ? 'UTC' : date_default_timezone_get()));
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $d->format('c');
    } catch (Throwable) {
        return null;
    }
}

/** Día (Y-m-d, hora de Ecuador) de un momento guardado. */
function tc2_dia(?string $t, bool $utc = false): ?string
{
    $m = tc2_momento($t, $utc);
    return $m === null ? null : substr($m, 0, 10);
}

function tc2_dinero(mixed $v): float
{
    return round((float)$v, 2);
}

/** Texto plano (sin HTML ni caracteres de control). */
function tc2_txt(mixed $s, int $max = 0): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', strip_tags((string)($s ?? ''))) ?? '');
    return $max > 0 ? mb_substr($s, 0, $max) : $s;
}

function tc2_wa(?string $tel): ?string
{
    $w = telefono_wa((string)$tel);
    return $w !== '' ? $w : null;
}

/** Nombre de la forma de pago. */
function tc2_nombre_metodo(string $f): string
{
    if (function_exists('nombre_forma_pago')) return nombre_forma_pago($f);
    return $f === 'google_play' ? 'Google Play' : (formas_pago()[$f] ?? $f);
}

/** Período de N meses: [clave, nombre]. */
function tc2_periodo_de(int $meses): array
{
    foreach (periodos_plan() as $k => [$m, $n]) if ($m === $meses) return [$k, $n];
    return [$meses . ' meses', $meses . ' meses'];
}

/** Botón genérico (§4.4). */
function tc2_boton(string $accion, string $etiqueta, string $estilo = 'normal', ?string $confirmar = null, array $datos = [], array $campos = []): array
{
    return ['accion' => $accion, 'etiqueta' => $etiqueta, 'estilo' => $estilo, 'confirmar' => $confirmar, 'datos' => (object)$datos, 'campos' => $campos];
}

function tc2_campo(string $campo, string $etiqueta, string $tipo, bool $requerido = false, array $extra = []): array
{
    return ['campo' => $campo, 'etiqueta' => $etiqueta, 'tipo' => $tipo, 'requerido' => $requerido] + $extra;
}

function tc2_boton_wa(?string $wa, string $texto, string $etiqueta = 'WhatsApp'): ?array
{
    return $wa ? ['accion' => 'whatsapp', 'etiqueta' => $etiqueta, 'estilo' => 'normal', 'whatsapp' => $wa, 'texto' => $texto] : null;
}

function tc2_boton_abrir(string $ir, string $etiqueta = ''): array
{
    return ['accion' => 'abrir', 'etiqueta' => $etiqueta !== '' ? $etiqueta : 'Ver en ' . tc_producto()[1], 'estilo' => 'normal', 'ir' => $ir];
}

/* ---------- Validación de los campos del cuerpo (422 «invalido» con «campo») ---------- */

function tc2_mal(string $campo, string $msg): TcError
{
    return new TcError('invalido', $msg, 422, $campo);
}

function tc2_id_valor(mixed $v): ?string
{
    if (is_int($v) && $v >= 0) $v = (string)$v;
    return is_string($v) && preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $v) ? $v : null;
}

function tc2_id_requerido(array $d, string $k, string $ruta = ''): string
{
    $v = tc2_id_valor($d[$k] ?? null);
    if ($v === null) throw tc2_mal($ruta !== '' ? $ruta : $k, 'Falta el identificador «' . $k . '» (o no es válido).');
    return $v;
}

function tc2_fecha_valida(mixed $v): bool
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return false;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

/** Fecha opcional ('' o null = sin filtro). */
function tc2_fecha_opcional(array $d, string $k, string $ruta = ''): string
{
    $v = $d[$k] ?? null;
    if ($v === null || $v === '') return '';
    if (!tc2_fecha_valida($v)) throw tc2_mal($ruta ?: $k, 'Escriba la fecha así: AAAA-MM-DD.');
    return $v;
}

function tc2_entero(array $d, string $k, int $def, int $min, int $max, string $ruta = ''): int
{
    $v = $d[$k] ?? null;
    if ($v === null || $v === '') return $def;
    if (is_string($v) && preg_match('/^-?\d+$/', $v)) $v = (int)$v;
    if (is_float($v) && floor($v) === $v) $v = (int)$v;
    if (!is_int($v) || $v < $min || $v > $max) throw tc2_mal($ruta ?: $k, 'Debe ser un número entero de ' . $min . ' a ' . $max . '.');
    return $v;
}

function tc2_texto(array $d, string $k, int $min, int $max, string $ruta, string $msg = ''): string
{
    $v = $d[$k] ?? '';
    if (is_int($v) || is_float($v)) $v = (string)$v;
    if (!is_string($v)) throw tc2_mal($ruta, $msg ?: 'Debe ser texto.');
    $v = tc2_txt($v);
    $n = mb_strlen($v);
    if ($n < $min || $n > $max) throw tc2_mal($ruta, $msg ?: ($min > 0 ? 'Escriba de ' . $min . ' a ' . $max . ' caracteres.' : 'Escriba hasta ' . $max . ' caracteres.'));
    return $v;
}

function tc2_dinero_campo(array $d, string $k, string $ruta, ?float $def = null): ?float
{
    $v = $d[$k] ?? null;
    if ($v === null || $v === '') return $def;
    if (is_string($v) && preg_match('/^\d+([.,]\d{1,2})?$/', trim($v))) $v = (float)str_replace(',', '.', trim($v));
    if (!is_int($v) && !is_float($v)) throw tc2_mal($ruta, 'Escriba el monto con números, por ejemplo 39,00.');
    if ($v < 0 || $v > 100000) throw tc2_mal($ruta, 'El monto debe ser de 0 a 100.000.');
    if (abs(round($v, 2) - $v) > 0.0000001) throw tc2_mal($ruta, 'El monto puede tener hasta 2 decimales.');
    return round((float)$v, 2);
}

/* ============================================================
 * v2 — CAPACIDADES (§5.1)
 * ============================================================ */

function tc2_capacidades(): array
{
    [$clave, $nombre, $acad] = tc_producto();
    $metodos = [];
    foreach (formas_pago() as $k => $v) $metodos[] = ['metodo' => $k, 'nombre' => $v];
    $periodos = [];
    foreach (periodos_plan() as $k => [$m]) $periodos[] = ['periodo' => $k, 'meses' => $m];
    $estados_detalle = ['prueba' => 'Demo', 'activa' => 'Activa', 'sin_limite' => 'Sin vencimiento', 'gracia' => 'En gracia',
        'vencida' => 'Vencida', 'prueba_vencida' => 'Demo terminada', 'suspendida' => 'Suspendida', 'pausada' => 'Pausada', 'papelera' => 'En la papelera'];
    return [
        'revision' => TC_REVISION, 'nombre' => $nombre, 'version' => tc_version(),
        'unico' => false, 'moneda' => 'USD', 'zona_horaria' => date_default_timezone_get(),
        'url_publica' => rtrim(url_base(), '/'),
        'acciones' => ['capacidades', 'estado', 'sso', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar'],
        'sso_ir' => true,
        'rutas_sso' => TC_RUTAS_SSO,
        'ejecutar' => TC_OPERACIONES,
        'tipos_solicitud' => TC_TIPOS_SOLICITUD,
        'estados_detalle' => $estados_detalle,
        'periodos' => $periodos,
        'meses_libres' => true,
        'metodos_pago' => $metodos,
        'planes_editar' => false,
        'filtros_clientes' => ['buscar', 'estados', 'estado', 'plan', 'tipo', 'vence_desde', 'vence_hasta', 'alta_desde', 'alta_hasta', 'sin_uso_dias', 'origen', 'ids', 'orden'],
        'formularios' => ['crear_demo' => tc2_form_crear_demo()],
        'limites' => ['por_pagina_max' => 200, 'meses_finanzas_max' => 24, 'dias_prueba_max' => 90, 'papelera_dias' => PAPELERA_DIAS, 'copia_dias' => PAPELERA_COPIA_DIAS],
        'tipos_pausa' => tc2_opciones(tipos_pausa()),
        'papelera' => true,
    ];
}

/** [clave => nombre] → [{valor, etiqueta}]. */
function tc2_opciones(array $x): array
{
    $r = [];
    foreach ($x as $k => $v) $r[] = ['valor' => (string)$k, 'etiqueta' => (string)$v];
    return $r;
}

/** Campos propios del formulario «Crear demo» (los comunes los pone el Central). */
function tc2_form_crear_demo(): array
{
    static $f = null;
    if ($f !== null) return $f;
    if (tc_producto()[2]) {
        $disc = [];
        foreach (deportes_escuela() as $k => $v) $disc[] = ['valor' => (string)$k, 'etiqueta' => (string)$v];
        return $f = [
            tc2_campo('ciudad', 'Ciudad', 'texto', false, ['max' => 80]),
            tc2_campo('disciplina', 'Disciplina principal', 'opcion', false, ['valor' => isset(deportes_escuela()['ballet']) ? 'ballet' : (string)array_key_first(deportes_escuela()), 'opciones' => $disc]),
        ];
    }
    return $f = [
        tc2_campo('tipo', 'Tipo de cuenta', 'opcion', true, ['valor' => 'liga', 'opciones' => [['valor' => 'liga', 'etiqueta' => 'Liga (campeonatos)'], ['valor' => 'escuela', 'etiqueta' => 'Escuela deportiva']]]),
        tc2_campo('ciudad', 'Ciudad', 'texto', false, ['max' => 80]),
    ];
}

/* ============================================================
 * v2 — CLIENTES (§4.3, §5.4, §5.5)
 * ============================================================ */

/** Nombre del tipo de cuenta. */
function tc2_tipo(array $l): array
{
    if (!empty($l['es_federacion'])) return ['federacion', 'Federación'];
    if (($l['tipo'] ?? 'liga') === 'escuela') return ['escuela', tc_producto()[2] ? 'Academia' : 'Escuela'];
    return ['liga', 'Liga'];
}

/**
 * Resúmenes de los clientes (§4.3), en orden de id. $ids: solo esos. Con pocas consultas (no una por cliente).
 */
function tc2_clientes_resumen(?array $ids = null, bool $papelera = false): array
{
    // (Anexo A) $papelera: SOLO las cuentas de la papelera (sin ella, nunca salen)
    $solo = tc_solo(true) . ($papelera ? " AND COALESCE(l.eliminada, '') <> ''" : " AND COALESCE(l.eliminada, '') = ''");
    $ligas = filas("SELECT l.*, p.nombre AS plan_nombre FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE COALESCE(l.es_demo, 0) = 0$solo ORDER BY l.id");
    if ($ids !== null) {
        $q = array_flip(array_map('strval', $ids));
        $ligas = array_values(array_filter($ligas, fn($l) => isset($q[(string)$l['id']])));
    }
    if (!$ligas) return [];
    $por = fn(string $sql, string $k = 'liga_id') => array_column(filas($sql), null, $k);
    $pagos = $por('SELECT liga_id, COUNT(*) AS n, SUM(monto) AS total, MIN(fecha) AS primero, MAX(fecha) AS ultimo FROM pagos_suscripcion GROUP BY liga_id');
    $ultimo = $por('SELECT ps.liga_id, ps.monto, ps.meses FROM pagos_suscripcion ps WHERE ps.id = (SELECT p2.id FROM pagos_suscripcion p2 WHERE p2.liga_id = ps.liga_id ORDER BY p2.fecha DESC, p2.id DESC LIMIT 1)');
    $act = $por('SELECT liga_id, MAX(ultimo_ingreso) AS u FROM usuarios WHERE liga_id IS NOT NULL GROUP BY liga_id');
    $cambio = $por("SELECT registro_id, MAX(fecha) AS f FROM bitacora WHERE tabla = 'ligas' AND registro_id IS NOT NULL GROUP BY registro_id", 'registro_id');
    $vend = array_column(filas('SELECT id, nombre FROM vendedores'), 'nombre', 'id');
    $alumnos = array_column(filas("SELECT liga_id, COUNT(*) AS n FROM esc_alumnos WHERE estado = 'activo' GROUP BY liga_id"), 'n', 'liga_id');
    $grupos = array_column(filas('SELECT liga_id, COUNT(*) AS n FROM esc_grupos GROUP BY liga_id'), 'n', 'liga_id');
    $equipos = array_column(filas('SELECT liga_id, COUNT(*) AS n FROM equipos GROUP BY liga_id'), 'n', 'liga_id');
    $torneos = array_column(filas('SELECT liga_id, COUNT(*) AS n FROM torneos GROUP BY liga_id'), 'n', 'liga_id');
    $de_registro = array_flip(array_column(filas("SELECT DISTINCT liga_id FROM consentimientos WHERE formulario = 'registro' AND liga_id IS NOT NULL"), 'liga_id'));
    $de_demo = array_flip(array_column(filas('SELECT DISTINCT liga_id FROM solicitudes_demo WHERE liga_id IS NOT NULL'), 'liga_id'));
    $admins = [];
    foreach (filas("SELECT liga_id, nombre, email FROM usuarios WHERE rol = 'liga' AND COALESCE(perfil, 'admin') = 'admin' AND liga_id IS NOT NULL ORDER BY id") as $u) $admins[$u['liga_id']] ??= $u;
    $acad = tc_producto()[2];
    $r = [];
    foreach ($ligas as $l) {
        $id = (int)$l['id'];
        $en_papelera = liga_eliminada($l);
        if ($en_papelera) $l['activa'] = (int)$l['eliminada_activa']; // el estado que tenía (y que vuelve a tener si se restaura)
        $x = tc2_estado_de($l);
        $s = $x['s'];
        [$tipo, $tipo_n] = tc2_tipo($l);
        $vence = ($l['vence'] ?? '') !== '' ? $l['vence'] : null;
        $pg = $pagos[$id] ?? null;
        $origen = (string)($l['origen'] ?? '');
        if ($origen === '') $origen = isset($de_registro[$id]) ? 'registro' : (isset($de_demo[$id]) ? 'demo_pedida' : 'desconocido');
        $adm = $admins[$id] ?? null;
        $nom = trim((string)$l['contacto_nombre']) !== '' ? (string)$l['contacto_nombre'] : (string)($adm['nombre'] ?? '');
        $mail = trim((string)$l['contacto_email']) !== '' ? (string)$l['contacto_email'] : (string)($adm['email'] ?? '');
        $tel = (string)$l['contacto_telefono'];
        $uso = $tipo === 'escuela'
            ? ['Alumnos' => (int)($alumnos[$id] ?? 0), $acad ? 'Cursos' : 'Grupos' => (int)($grupos[$id] ?? 0)]
            : ['Equipos' => (int)($equipos[$id] ?? 0), 'Campeonatos' => (int)($torneos[$id] ?? 0)];
        $ult = (string)($act[$id]['u'] ?? '');
        $mensual = !$en_papelera && $x['estado'] !== 'suspendido' && $s['estado'] === 'activa' && isset($ultimo[$id])
            ? round((float)$ultimo[$id]['monto'] / max(1, (int)$ultimo[$id]['meses']), 2) : 0.0;
        $tipo_pausa = (string)($l['suspendida_tipo'] ?? '');
        $texto = $x['estado'] === 'suspendido'
            ? ($tipo_pausa !== '' ? 'Pausada (' . (tipos_pausa()[$tipo_pausa] ?? $tipo_pausa) . ')' : 'Suspendida') . (($l['suspendida_motivo'] ?? '') !== '' ? ': ' . $l['suspendida_motivo'] : '') . ($vence ? ' (vence el ' . $vence . ')' : '')
            : $s['texto'];
        if ($x['estado'] === 'suspendido' && $tipo_pausa !== '') $x['detalle'] = 'pausada';
        if ($en_papelera) {
            $texto = 'En la papelera desde el ' . substr((string)$l['eliminada'], 0, 10) . '; se borra de verdad el ' . $l['eliminada_hasta'] . ($l['eliminada_motivo'] !== '' ? '. Motivo: ' . $l['eliminada_motivo'] : '');
            $x['detalle'] = 'papelera';
            $x['bloqueado'] = true;
        }
        $r[$id] = [
            'id' => (string)$id,
            'nombre' => (string)$l['nombre'],
            'tipo' => $tipo,
            'tipo_nombre' => $tipo_n,
            'ciudad' => (string)$l['ciudad'] !== '' ? (string)$l['ciudad'] : null,
            'plan' => $l['plan_id'] ? ['codigo' => (string)$l['plan_id'], 'nombre' => (string)($l['plan_nombre'] ?? '')] : null,
            'estado' => $x['estado'],
            'estado_detalle' => $x['detalle'],
            'estado_texto' => tc2_txt($texto),
            'en_gracia' => $x['en_gracia'],
            'bloqueado' => $x['bloqueado'],
            'fechas' => [
                'alta' => tc2_dia($l['creado'], true),
                'prueba_hasta' => !empty($l['es_prueba']) ? $vence : null,
                'activo_hasta' => empty($l['es_prueba']) ? $vence : null,
                'vence' => $vence,
                'dias_para_vencer' => $s['dias'],
            ],
            'dueno' => ['nombre' => $nom !== '' ? $nom : null, 'telefono' => $tel !== '' ? $tel : null, 'whatsapp' => tc2_wa($tel), 'email' => $mail !== '' ? $mail : null],
            'ultima_actividad' => tc2_momento($ult),
            'pagos' => [
                'total' => tc2_dinero($pg['total'] ?? 0), 'cantidad' => (int)($pg['n'] ?? 0), 'primer_pago' => $pg['primero'] ?? null,
                'ultimo_pago' => $pg['ultimo'] ?? null, 'ultimo_monto' => isset($ultimo[$id]) ? tc2_dinero($ultimo[$id]['monto']) : null,
            ],
            'mensual_estimado' => $mensual,
            'origen' => $origen,
            'vendedor' => !empty($l['vendedor_id']) && isset($vend[$l['vendedor_id']]) ? ['id' => (string)$l['vendedor_id'], 'nombre' => (string)$vend[$l['vendedor_id']]] : null,
            'uso' => $uso,
            'ir' => $en_papelera ? '/admin.php?p=ligas&papelera=1' : '/admin.php?p=ligas&editar=' . $id,
            'ir_trabajar' => $en_papelera ? null : '/admin.php?p=ligas&entrar=' . $id,
            'actualizado' => tc2_momento($cambio[$id]['f'] ?? '', true) ?? tc2_momento($l['creado'], true),
            // (Anexo A) pausa y papelera (null si no aplica)
            'pausa' => $x['estado'] === 'suspendido' && $tipo_pausa !== '' ? ['tipo' => $tipo_pausa, 'tipo_nombre' => tipos_pausa()[$tipo_pausa] ?? $tipo_pausa,
                        'motivo' => tc2_txt($l['suspendida_motivo']), 'motivo_cliente' => texto_motivo_pausa($l), 'desde' => tc2_momento($l['suspendida_en'] ?? '')] : null,
            'papelera' => $en_papelera ? ['desde' => tc2_momento($l['eliminada']), 'hasta' => (string)$l['eliminada_hasta'], 'motivo' => tc2_txt($l['eliminada_motivo']),
                        'por' => tc2_txt($l['eliminada_por']), 'copia' => (string)$l['eliminada_copia']] : null,
        ];
    }
    return $r;
}

/** Quita tildes y pasa a minúsculas (para buscar). */
function tc2_normalizar(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
}

/** Acción «clientes» (§5.4). */
function tc2_clientes(array $d): array
{
    // (Anexo A) los filtros pueden venir sueltos (como siempre) o dentro de «filtros»; «estado: papelera» lista la papelera
    if (isset($d['filtros'])) {
        if (!is_array($d['filtros']) || ($d['filtros'] !== [] && array_is_list($d['filtros']))) throw tc2_mal('filtros', '«filtros» debe ser un objeto.');
        $d += $d['filtros'];
    }
    $est1 = $d['estado'] ?? null;
    if ($est1 !== null && $est1 !== '' && !in_array($est1, array_merge(TC_ESTADOS, ['papelera']), true)) throw tc2_mal('estado', 'Estado válido: ' . implode(', ', TC_ESTADOS) . ' o papelera.');
    $papelera = $est1 === 'papelera' || (is_array($d['estados'] ?? null) && in_array('papelera', $d['estados'], true));
    if (is_array($d['estados'] ?? null)) $d['estados'] = array_values(array_diff($d['estados'], ['papelera']));
    if (!$papelera && is_string($est1) && $est1 !== '') $d['estados'] = array_values(array_unique(array_merge($d['estados'] ?? [], [$est1])));
    $pagina = tc2_entero($d, 'pagina', 1, 1, 100000);
    $por_pagina = tc2_entero($d, 'por_pagina', 50, 1, 200);
    $buscar = $d['buscar'] ?? '';
    if (!is_string($buscar) || mb_strlen($buscar) > 80) throw tc2_mal('buscar', 'La búsqueda puede tener hasta 80 caracteres.');
    $estados = $d['estados'] ?? [];
    if (!is_array($estados) || array_diff($estados, TC_ESTADOS)) throw tc2_mal('estados', 'Estados válidos: ' . implode(', ', TC_ESTADOS) . '.');
    $plan = $d['plan'] ?? '';
    $tipo = $d['tipo'] ?? '';
    $origen = $d['origen'] ?? '';
    foreach (['plan' => $plan, 'tipo' => $tipo, 'origen' => $origen] as $k => $v) {
        if (!is_string($v) && !is_int($v)) throw tc2_mal($k, 'Debe ser texto.');
    }
    $vd = tc2_fecha_opcional($d, 'vence_desde');
    $vh = tc2_fecha_opcional($d, 'vence_hasta');
    $ad = tc2_fecha_opcional($d, 'alta_desde');
    $ah = tc2_fecha_opcional($d, 'alta_hasta');
    $sin_uso = ($d['sin_uso_dias'] ?? null) === null || ($d['sin_uso_dias'] ?? '') === '' ? null : tc2_entero($d, 'sin_uso_dias', 0, 0, 3650);
    $ids = $d['ids'] ?? [];
    if (!is_array($ids) || count($ids) > 500) throw tc2_mal('ids', 'Lista de ids no válida.');
    $ids = array_map(fn($x) => tc2_id_valor($x) ?? throw tc2_mal('ids', 'Lista de ids no válida.'), $ids);
    $orden = $d['orden'] ?? 'vence';
    if (!in_array($orden, ['vence', 'alta', 'nombre', 'actividad'], true)) throw tc2_mal('orden', 'Orden válido: vence, alta, nombre o actividad.');

    $todos = tc2_clientes_resumen();
    $cont = ['total' => count($todos), 'prueba' => 0, 'activo' => 0, 'por_vencer' => 0, 'vencido' => 0, 'suspendido' => 0];
    foreach ($todos as $c) $cont[$c['estado']]++;
    $en_papelera = tc2_clientes_resumen(null, true);
    if ($papelera) {
        $todos = $en_papelera;
        foreach ($todos as $k => $c) $todos[$k]['acciones_posibles'] = [tc2_boton_restaurar($c)];
    }
    $b = tc2_normalizar(trim($buscar));
    $bd = preg_replace('/\D/', '', $buscar);
    $limite_uso = $sin_uso !== null ? date('c', strtotime('-' . $sin_uso . ' days')) : null;
    $extra = $b !== '' ? array_column(filas('SELECT id, slug, ruc FROM ligas'), null, 'id') : [];
    $lista = array_filter($todos, function ($c) use ($b, $bd, $estados, $plan, $tipo, $origen, $vd, $vh, $ad, $ah, $limite_uso, $ids, $extra) {
        if ($ids && !in_array($c['id'], $ids, true)) return false;
        if (!tc2_cumple_filtros($c, $estados, $plan, $tipo, $origen, $vd, $vh, $ad, $ah, $limite_uso)) return false;
        if ($b === '') return true;
        // nombre, ciudad, contacto, correo, teléfono, dirección corta y RUC (sin distinguir mayúsculas ni tildes)
        $e = $extra[(int)$c['id']] ?? [];
        $hay = tc2_normalizar(implode(' ', [$c['nombre'], (string)$c['ciudad'], (string)$c['dueno']['nombre'], (string)$c['dueno']['email'], (string)$c['dueno']['telefono'], (string)($e['slug'] ?? ''), (string)($e['ruc'] ?? '')]));
        if (str_contains($hay, $b)) return true;
        return strlen($bd) >= 4 && str_contains(preg_replace('/\D/', '', (string)$c['dueno']['telefono']), $bd);
    });
    $lista = array_values($lista);
    usort($lista, match ($orden) {
        'alta' => fn($x, $y) => strcmp((string)$y['fechas']['alta'], (string)$x['fechas']['alta']) ?: ((int)$y['id'] <=> (int)$x['id']),
        'nombre' => fn($x, $y) => strcmp(tc2_normalizar($x['nombre']), tc2_normalizar($y['nombre'])) ?: ((int)$x['id'] <=> (int)$y['id']),
        'actividad' => fn($x, $y) => strcmp((string)$x['ultima_actividad'], (string)$y['ultima_actividad']) ?: ((int)$x['id'] <=> (int)$y['id']),
        default => fn($x, $y) => (($x['fechas']['vence'] === null) <=> ($y['fechas']['vence'] === null)) ?: (strcmp((string)$x['fechas']['vence'], (string)$y['fechas']['vence']) ?: ((int)$x['id'] <=> (int)$y['id'])),
    });
    $total = count($lista);
    return [
        'total' => $total, 'pagina' => $pagina, 'por_pagina' => $por_pagina, 'paginas' => max(1, (int)ceil($total / $por_pagina)),
        'contadores' => $cont,
        'papelera_total' => count($en_papelera),
        'clientes' => array_slice($lista, ($pagina - 1) * $por_pagina, $por_pagina),
    ];
}

function tc2_cumple_filtros(array $c, array $estados, mixed $plan, mixed $tipo, mixed $origen, string $vd, string $vh, string $ad, string $ah, ?string $limite_uso): bool
{
    if ($estados && !in_array($c['estado'], $estados, true)) return false;
    if ((string)$plan !== '' && ($c['plan']['codigo'] ?? '') !== (string)$plan) return false;
    if ((string)$tipo !== '' && $c['tipo'] !== (string)$tipo) return false;
    if ((string)$origen !== '' && $c['origen'] !== (string)$origen) return false;
    $v = $c['fechas']['vence'];
    if ($vd !== '' && ($v === null || $v < $vd)) return false;
    if ($vh !== '' && ($v === null || $v > $vh)) return false;
    $a = (string)$c['fechas']['alta'];
    if ($ad !== '' && $a < $ad) return false;
    if ($ah !== '' && $a > $ah) return false;
    if ($limite_uso !== null && $c['ultima_actividad'] !== null && strtotime($c['ultima_actividad']) >= strtotime($limite_uso)) return false;
    return true;
}

/** Fila de «ligas» de un cliente (sin la demo del sistema ni la papelera; en modo academia solo academias), o null. $papelera: también las de la papelera. */
function tc2_liga(string $id, bool $papelera = false): ?array
{
    if (!ctype_digit($id)) return null;
    return fila('SELECT * FROM ligas l WHERE l.id = ? AND COALESCE(l.es_demo, 0) = 0' . tc_solo($papelera), [(int)$id]);
}

function tc2_liga_o_404(string $id, bool $papelera = false): array
{
    return tc2_liga($id, $papelera) ?? throw new TcError('no_encontrado', 'Ese cliente ya no existe en el programa (quizás lo borraron). Actualice la lista.', 404);
}

/** Planes que se le pueden poner a una cuenta: [codigo => fila]. */
function tc2_planes_para(array $l, bool $solo_activos = true): array
{
    $tipo = ($l['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
    $r = [];
    foreach (filas("SELECT * FROM planes WHERE COALESCE(NULLIF(tipo_cuenta, ''), 'ambos') IN ('ambos', ?) ORDER BY orden, precio_mensual, id", [$tipo]) as $pl) {
        if ($solo_activos && (int)$pl['activo'] !== 1 && (int)$pl['id'] !== (int)($l['plan_id'] ?? 0)) continue;
        $r[(string)$pl['id']] = $pl;
    }
    return $r;
}

/** Campos del formulario «Registrar pago» (§4.4). */
function tc2_campos_pago(array $l, ?array $sugerido = null): array
{
    $planes = tc2_planes_para($l);
    $pl = $planes[(string)($l['plan_id'] ?? '')] ?? (plan_para_renovar($l) ? ($planes[(string)plan_para_renovar($l)['id']] ?? null) : null) ?? (reset($planes) ?: null);
    $op_plan = array_map(fn($p) => ['valor' => (string)$p['id'], 'etiqueta' => (string)$p['nombre']], array_values($planes));
    $opciones = $pl ? opciones_precio($pl, $l) : [];
    $op_per = [];
    foreach ($opciones as $k => $o) $op_per[] = ['valor' => $k, 'etiqueta' => $o['nombre'] . ($o['gratis'] ? ' (' . $o['gratis'] . ' mes' . ($o['gratis'] === 1 ? '' : 'es') . ' gratis)' : '')];
    if (!$op_per) foreach (periodos_plan() as $k => [$m, $n]) $op_per[] = ['valor' => $k, 'etiqueta' => $n];
    $per = $sugerido['periodo'] ?? ($op_per[0]['valor'] ?? 'mensual');
    $monto = $pl && isset($opciones[$per]) ? tc2_dinero($opciones[$per]['precio']) : 0.0;
    $metodos = [];
    foreach (formas_pago() as $k => $v) $metodos[] = ['valor' => $k, 'etiqueta' => $v];
    return [
        tc2_campo('plan', 'Plan', 'opcion', true, ['valor' => $pl ? (string)$pl['id'] : null, 'opciones' => $op_plan]),
        tc2_campo('periodo', 'Tiempo', 'opcion', true, ['valor' => $per, 'opciones' => $op_per]),
        tc2_campo('monto', 'Valor recibido ($)', 'dinero', true, ['valor' => $monto, 'min' => 0, 'cotizar' => true]),
        tc2_campo('metodo', 'Forma de pago', 'opcion', true, ['valor' => 'transferencia', 'opciones' => $metodos]),
        tc2_campo('fecha', 'Fecha del pago', 'fecha', true, ['valor' => date('Y-m-d')]),
        tc2_campo('nota', 'Nota', 'texto', false, ['max' => 200, 'ayuda' => 'N° de transferencia, factura…']),
    ];
}

/** Textos de WhatsApp del programa para un cliente. */
function tc2_mensajes(array $l, array $c): array
{
    $quien = trim((string)($c['dueno']['nombre'] ?? '')) !== '' ? ' ' . $c['dueno']['nombre'] : '';
    $empresa = (string)ajuste('empresa');
    $valor = texto_valor_renovacion($l);
    $datos = trim((string)ajuste('ventas_datos_pago'));
    $s = estado_suscripcion($l);
    $m = ['bienvenida' => 'Hola' . $quien . ', le saluda ' . $empresa . ' de ' . marca() . '. Gracias por elegirnos para ' . $l['nombre'] . '. Cualquier duda, escríbanos por aquí.',
          'recordar_prueba' => null, 'recordar_renovacion' => null, 'vencido' => null];
    if ($c['estado'] === 'prueba') $m['recordar_prueba'] = mensaje_seguimiento_demo($l, (int)$s['dias']);
    if ($c['estado'] === 'vencido' && !empty($l['es_prueba'])) $m['recordar_prueba'] = mensaje_seguimiento_demo($l, -1);
    if (in_array($c['estado'], ['por_vencer', 'activo'], true) && $s['dias'] !== null) {
        $m['recordar_renovacion'] = 'Hola' . $quien . ', le saluda ' . $empresa . '. La suscripción de ' . marca() . ' para ' . $l['nombre'] . ' vence el ' . $l['vence']
            . ($s['dias'] === 0 ? ' (hoy)' : ' (faltan ' . $s['dias'] . ' día' . ($s['dias'] === 1 ? '' : 's') . ')') . '.' . ($valor !== '' ? ' ' . $valor . '.' : '')
            . ($datos !== '' ? "\n\nDatos para pagar:\n" . $datos : '') . "\n\n¿Le ayudamos a renovarla?";
    }
    if ($c['estado'] === 'vencido' && empty($l['es_prueba'])) {
        $m['vencido'] = 'Hola' . $quien . ', le saluda ' . $empresa . '. La suscripción de ' . $l['nombre'] . ' venció el ' . $l['vence'] . '. ¿Le ayudamos a renovarla para seguir usando el sistema?'
            . ($valor !== '' ? ' ' . $valor . '.' : '') . ($datos !== '' ? "\n\nDatos para pagar:\n" . $datos : '');
    }
    return $m;
}

/** Botones de la ficha de un cliente (§4.4). */
function tc2_acciones_cliente(array $l, array $c): array
{
    $id = (string)$l['id'];
    $n = (string)$l['nombre'];
    $b = [];
    $b[] = tc2_boton('registrar_pago', 'Registrar pago', 'principal', '¿Registrar el pago de ' . $n . '?' . ($c['estado'] === 'suspendido' ? ' (sigue suspendida hasta que la reactive)' : ''), ['cliente_id' => $id], tc2_campos_pago($l));
    if ($c['estado'] === 'prueba' || ($c['estado'] === 'vencido' && $c['pagos']['cantidad'] === 0)) {
        $b[] = tc2_boton('extender_prueba', 'Alargar prueba', 'normal', null, ['cliente_id' => $id], [tc2_campo('dias', 'Días más', 'dias', true, ['valor' => 7, 'min' => 1, 'max' => 90])]);
    }
    $planes = array_map(fn($p) => ['valor' => (string)$p['id'], 'etiqueta' => (string)$p['nombre']], array_values(tc2_planes_para($l)));
    $b[] = tc2_boton('cambiar_plan', 'Cambiar plan', 'normal', '¿Cambiar el plan de ' . $n . ' sin cobrar?', ['cliente_id' => $id],
        [tc2_campo('plan', 'Plan', 'opcion', true, ['valor' => $l['plan_id'] ? (string)$l['plan_id'] : null, 'opciones' => $planes])]);
    if ($c['estado'] === 'suspendido') {
        $b[] = tc2_boton('reactivar', 'Reactivar', 'principal', '¿Reactivar ' . $n . '?', ['cliente_id' => $id]);
    } else {
        $b[] = tc2_boton('suspender', 'Suspender', 'peligro', '¿Suspender ' . $n . '? No podrá trabajar y su página pública se oculta.', ['cliente_id' => $id],
            [tc2_campo('motivo', 'Motivo', 'texto', true, ['min' => 5, 'max' => 200])]);
    }
    // (Anexo A) pausar (con tipo y motivo; si ya está pausada, se puede cambiar el motivo) y borrar (a la papelera)
    $b[] = tc2_boton('pausar', $c['pausa'] ? 'Cambiar motivo de la pausa' : 'Pausar', 'peligro', $c['pausa'] ? null : '¿Pausar ' . $n . '? Sus usuarios verán «Tu cuenta está pausada» y su página pública queda temporalmente no disponible.',
        ['cliente_id' => $id], tc2_campos_pausa($c['pausa']));
    $b[] = tc2_boton('borrar_cliente', 'Borrar', 'peligro', '¿Borrar ' . $n . '? Se guarda una copia y queda ' . PAPELERA_DIAS . ' días en la papelera; después se borra de verdad.', ['cliente_id' => $id], [
        tc2_campo('confirmar', 'Escriba el nombre exacto para confirmar', 'texto', true, ['max' => 200, 'ayuda' => 'Nombre exacto: ' . $n . ' (o su id: ' . $id . ')', 'placeholder' => $n]),
        tc2_campo('motivo', 'Motivo del borrado', 'texto', true, ['min' => 3, 'max' => 300]),
    ]);
    if (usuario_dueno($l)) $b[] = tc2_boton('nueva_clave_dueno', 'Nueva clave del dueño', 'normal', '¿Crear una contraseña nueva para el dueño de ' . $n . '? La anterior deja de servir.', ['cliente_id' => $id]);
    $b[] = tc2_boton('nota_interna', 'Nota interna', 'normal', null, ['cliente_id' => $id],
        [tc2_campo('texto', 'Nota (el cliente no la ve)', 'texto_largo', false, ['valor' => (string)($l['notas_admin'] ?? ''), 'max' => 4000])]);
    $m = tc2_mensajes($l, $c);
    $txt = $m['recordar_prueba'] ?? $m['recordar_renovacion'] ?? $m['vencido'] ?? $m['bienvenida'];
    if ($w = tc2_boton_wa($c['dueno']['whatsapp'], $txt)) $b[] = $w;
    $b[] = tc2_boton_abrir($c['ir']);
    return $b;
}

/** (Anexo A) Campos de «Pausar». */
function tc2_campos_pausa(?array $pausa = null): array
{
    return [
        tc2_campo('tipo', 'Tipo de pausa', 'opcion', true, ['valor' => $pausa['tipo'] ?? 'falta_pago', 'opciones' => tc2_opciones(tipos_pausa()),
            'ayuda' => 'Con «En revisión» o «Uso indebido» el cliente ve un texto general, no el motivo.']),
        tc2_campo('motivo', 'Motivo (lo verá el cliente)', 'texto', true, ['valor' => $pausa['motivo'] ?? null, 'min' => 3, 'max' => 300]),
    ];
}

/** (Anexo A) Botón «Restaurar» de un cliente de la papelera. */
function tc2_boton_restaurar(array $c): array
{
    return tc2_boton('restaurar_cliente', 'Restaurar', 'principal', '¿Sacar ' . $c['nombre'] . ' de la papelera? Vuelve tal cual estaba.', ['cliente_id' => (string)$c['id']]);
}

/** Acción «cliente» (§5.5): resumen + ficha completa (también de un cliente de la papelera, con «Restaurar»). */
function tc2_cliente_ficha(string $id): array
{
    $l = tc2_liga_o_404($id, true);
    if (liga_eliminada($l)) {
        $c = tc2_clientes_resumen([(int)$l['id']], true)[(int)$l['id']];
        $c['acciones_posibles'] = [tc2_boton_restaurar($c)];
        $c['registro'] = tc2_registro_cliente((int)$l['id']);
        $c['notas_admin'] = (string)($l['notas_admin'] ?? '');
        return $c;
    }
    $c = tc2_clientes_resumen([(int)$l['id']])[(int)$l['id']];
    $lid = (int)$l['id'];
    $rec = !empty($l['referido_por']) ? fila('SELECT id, nombre FROM ligas WHERE id = ?', [(int)$l['referido_por']]) : null;
    $combo = combo_de($l);
    $pagadas = array_flip(array_column(filas("SELECT pago_suscripcion_id FROM comisiones WHERE liga_id = ? AND estado = 'pagada'", [$lid]), 'pago_suscripcion_id'));
    $de_comprobante = array_column(filas("SELECT pago_id, id FROM solicitudes_pago WHERE liga_id = ? AND pago_id IS NOT NULL", [$lid]), 'id', 'pago_id');
    $pagos = [];
    foreach (filas('SELECT ps.*, p.nombre AS plan_nombre FROM pagos_suscripcion ps LEFT JOIN planes p ON p.id = ps.plan_id WHERE ps.liga_id = ? ORDER BY ps.fecha DESC, ps.id DESC LIMIT 200', [$lid]) as $p) {
        [$per] = tc2_periodo_de((int)$p['meses']);
        $origen = isset($de_comprobante[$p['id']]) || str_contains((string)$p['nota'], 'comprobante #') ? 'comprobante'
            : ($p['forma_pago'] === 'google_play' ? 'google_play' : (str_starts_with((string)$p['registrado_por'], 'Pago en línea') ? 'tarjeta'
            : (str_starts_with((string)$p['registrado_por'], 'Panel Tukán Central') ? 'central' : 'manual')));
        $pagos[] = [
            'id' => (string)$p['id'], 'fecha' => $p['fecha'] !== '' ? $p['fecha'] : null, 'monto' => tc2_dinero($p['monto']),
            'plan' => $p['plan_id'] ? ['codigo' => (string)$p['plan_id'], 'nombre' => (string)($p['plan_nombre'] ?? '')] : null,
            'periodo' => $per, 'meses' => (int)$p['meses'], 'desde' => $p['desde'] !== '' ? $p['desde'] : null, 'hasta' => $p['hasta'] !== '' ? $p['hasta'] : null,
            'metodo' => (string)$p['forma_pago'], 'metodo_nombre' => tc2_nombre_metodo((string)$p['forma_pago']), 'nota' => tc2_txt($p['nota']),
            'registrado_por' => tc2_txt($p['registrado_por']), 'origen' => $origen, 'anulado' => false, 'puede_anular' => !isset($pagadas[$p['id']]),
        ];
    }
    $registro = tc2_registro_cliente($lid);
    $c += [
        'notas_admin' => (string)($l['notas_admin'] ?? ''),
        'usuarios' => (int)valor('SELECT COUNT(*) FROM usuarios WHERE liga_id = ? AND activo = 1', [$lid]),
        'recomendado_por' => $rec ? ['id' => (string)$rec['id'], 'nombre' => (string)$rec['nombre']] : null,
        'combo_con' => $combo ? ['id' => (string)$combo['id'], 'nombre' => (string)$combo['nombre']] : null,
        'dominio' => (string)($l['dominio'] ?? ''),
        'enlace_publico' => url_liga($l),
        'pagos_detalle' => $pagos,
        'solicitudes' => tc2_solicitudes_todas(['estado' => 'abiertas', 'cliente_id' => (string)$lid]),
        'registro' => $registro,
        'mensajes_sugeridos' => tc2_mensajes($l, $c),
        'acciones_posibles' => tc2_acciones_cliente($l, $c),
    ];
    return $c;
}

/** Últimos 20 eventos de la bitácora de un cliente (quién pausó, borró, cobró… y cuándo). */
function tc2_registro_cliente(int $lid): array
{
    $registro = [];
    foreach (filas("SELECT fecha, usuario, descripcion FROM bitacora WHERE liga_id = ? AND operacion = 'evento' ORDER BY id DESC LIMIT 20", [$lid]) as $b) {
        $registro[] = ['momento' => tc2_momento($b['fecha'], true), 'quien' => tc2_txt($b['usuario']), 'texto' => tc2_txt($b['descripcion'])];
    }
    return $registro;
}

/* ============================================================
 * v2 — COTIZAR Y PLANES (§5.6, §5.7)
 * ============================================================ */

/** Precio de N meses con la lógica del formulario de Ventas: el del período exacto, o múltiplos del año/semestre/trimestre, o el mensual × meses. */
function tc2_precio_meses(array $pl, array $l, int $meses): float
{
    $o = array_column(opciones_precio($pl, $l), null, 'meses');
    if (isset($o[$meses])) return tc2_dinero($o[$meses]['precio']);
    foreach ([12, 6, 3] as $m) if ($meses % $m === 0 && isset($o[$m])) return tc2_dinero($o[$m]['precio'] * $meses / $m);
    if (isset($o[1])) return tc2_dinero($o[1]['precio'] * $meses);
    return tc2_dinero((float)$pl['precio_mensual'] * $meses * factor_combo($l));
}

/** Lee plan + período/meses del pedido y calcula. Devuelve [plan, meses, periodo, monto, opción]. */
function tc2_leer_plan_meses(array $l, array $d, string $pre, bool $plan_requerido = true): array
{
    $codigo = tc2_id_valor($d['plan'] ?? null) ?? (!$plan_requerido && $l['plan_id'] ? (string)$l['plan_id'] : null);
    if ($codigo === null) throw tc2_mal($pre . 'plan', 'Elija el plan.');
    $pl = ctype_digit($codigo) ? plan_para_cuenta($l, (int)$codigo) : null;
    if (!$pl) throw tc2_mal($pre . 'plan', 'Ese plan no existe o no es para este tipo de cuenta.');
    $op = null;
    if (isset($d['periodo']) && $d['periodo'] !== '' && $d['periodo'] !== null) {
        if (!is_string($d['periodo']) || !isset(periodos_plan()[$d['periodo']])) throw tc2_mal($pre . 'periodo', 'Período no válido (mensual, trimestral, semestral o anual).');
        $per = $d['periodo'];
        $meses = periodos_plan()[$per][0];
        $ops = opciones_precio($pl, $l);
        if (!isset($ops[$per]) && !isset($d['meses'])) throw tc2_mal($pre . 'periodo', 'El plan ' . $pl['nombre'] . ' no se vende ' . mb_strtolower(periodos_plan()[$per][1]) . '.');
        $op = $ops[$per] ?? null;
    } elseif (isset($d['meses'])) {
        $meses = tc2_entero($d, 'meses', 1, 1, 36, $pre . 'meses');
        [$per] = tc2_periodo_de($meses);
    } else {
        throw tc2_mal($pre . 'periodo', 'Elija el tiempo (período o meses).');
    }
    $monto = $op ? tc2_dinero($op['precio']) : tc2_precio_meses($pl, $l, $meses);
    return [$pl, $meses, $per, $monto, $op];
}

function tc2_cotizar(array $d): array
{
    $l = tc2_liga_o_404(tc2_id_requerido($d, 'cliente_id'));
    [$pl, $meses, $per, $monto, $op] = tc2_leer_plan_meses($l, $d, '', false);
    [$desde, $hasta] = vigencia_pago($l, $meses, tc2_fecha_opcional($d, 'inicio') ?: null);
    $gratis = $op['gratis'] ?? 0;
    $desc = factor_combo($l) < 1 ? [['nombre' => 'Combo liga + escuela', 'porcentaje' => (float)combo_descuento()]] : [];
    return [
        'monto' => $monto, 'meses' => $meses, 'periodo' => $per, 'desde' => $desde, 'hasta' => $hasta,
        'detalle' => $pl['nombre'] . ' ' . mb_strtolower(tc2_periodo_de($meses)[1]) . ($gratis ? ' (paga ' . ($meses - $gratis) . ', recibe ' . $meses . ')' : ''),
        'incluye_dominio' => $meses >= 12 && (int)($pl['dominio_anual'] ?? 0) === 1,
        'descuentos' => $desc,
    ];
}

function tc2_planes(): array
{
    $acad = tc_producto()[2];
    $cuentas = array_column(filas("SELECT l.plan_id, COUNT(*) AS n FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0" . tc_solo() . " AND l.plan_id IS NOT NULL GROUP BY l.plan_id"), 'n', 'plan_id');
    $planes = [];
    foreach (filas('SELECT * FROM planes ORDER BY orden, precio_mensual, id') as $pl) {
        $para = (string)($pl['tipo_cuenta'] ?? '') !== '' ? (string)$pl['tipo_cuenta'] : 'ambos';
        if ($acad && $para === 'liga') continue; // (TuAcademia) los planes de ligas no se venden
        $precios = [];
        foreach (opciones_precio($pl) as $k => $o) {
            $precios[] = ['periodo' => $k, 'meses' => $o['meses'], 'precio' => tc2_dinero($o['precio']), 'meses_gratis' => $o['gratis'], 'incluye_dominio' => $o['dominio']];
        }
        $lim = [];
        if ($para !== 'liga') $lim['Alumnos'] = (int)($pl['max_alumnos'] ?? 0) ? (string)(int)$pl['max_alumnos'] : 'Ilimitados';
        if ($para !== 'escuela') {
            $lim['Torneos a la vez'] = (int)$pl['max_campeonatos'] ? (string)(int)$pl['max_campeonatos'] : 'Ilimitados';
            $lim['Equipos por torneo'] = (int)($pl['max_equipos_torneo'] ?? 0) ? (string)(int)$pl['max_equipos_torneo'] : 'Ilimitados';
            $lim['Equipos en la liga'] = (int)$pl['max_equipos'] ? (string)(int)$pl['max_equipos'] : 'Ilimitados';
        }
        $planes[] = [
            'codigo' => (string)$pl['id'], 'nombre' => (string)$pl['nombre'], 'activo' => (int)$pl['activo'] === 1,
            'para' => match ($para) { 'liga' => 'Ligas', 'escuela' => $acad ? 'Academias' : 'Escuelas', default => $acad ? 'Academias' : 'Ligas y escuelas' },
            'descripcion' => tc2_txt($pl['descripcion'] ?? ''),
            'precios' => $precios,
            'limites' => (object)$lim,
            'detalle' => array_map('tc2_txt', textos_limites_plan($pl, $para === 'ambos' ? '' : $para)),
            'clientes' => (int)($cuentas[$pl['id']] ?? 0),
        ];
    }
    return [
        'editable' => false,
        'planes' => $planes,
        'ajustes' => ['dias_prueba' => (int)ajuste('dias_prueba', tc_producto()[0] === 'tuacademia' ? 7 : 30), 'dias_demo' => (int)ajuste('dias_demo', 7), 'dias_gracia' => (int)ajuste('dias_gracia', 7),
                      'dias_gracia_demo' => (int)ajuste('dias_gracia_demo', 0), 'registro_abierto' => prueba_automatica()],
    ];
}

/* ============================================================
 * v2 — SOLICITUDES (§5.8 a §5.10)
 * ============================================================ */

/** Estado genérico → si está abierta. */
function tc2_abierta(string $estado): bool
{
    return in_array($estado, ['pendiente', 'en_proceso'], true);
}

/** Atenciones guardadas (recordatorios y avisos sin tabla propia). */
function tc2_atenciones(): array
{
    try {
        return array_column(filas('SELECT * FROM tukan_central_atenciones'), null, 'solicitud_id');
    } catch (Throwable) {
        return [];
    }
}

/** Botón «atender_solicitud». */
function tc2_boton_atender(string $sid, string $etiqueta = 'Marcar atendida', array $extra_campos = [], array $opciones = ['en_proceso' => 'En proceso', 'atendida' => 'Atendida', 'descartada' => 'Descartada']): array
{
    $op = [];
    foreach ($opciones as $k => $v) $op[] = ['valor' => $k, 'etiqueta' => $v];
    return tc2_boton('atender_solicitud', $etiqueta, 'normal', null, ['solicitud_id' => $sid], array_merge(
        [tc2_campo('resultado', 'Resultado', 'opcion', true, ['valor' => array_key_exists('atendida', $opciones) ? 'atendida' : array_key_first($opciones), 'opciones' => $op])],
        $extra_campos,
        [tc2_campo('nota', 'Nota', 'texto', false, ['max' => 300])]));
}

/** Arma una solicitud con todos sus campos (§5.8). */
function tc2_sol(array $x): array
{
    return $x + ['categoria' => 'pedido', 'actualizado' => $x['fecha'] ?? null, 'cliente_id' => null, 'quien' => null, 'resumen' => '', 'monto' => null,
                 'estado_producto' => $x['estado'], 'prioridad' => 'normal', 'vence_el' => null, 'detalle' => (object)[], 'adjuntos' => [], 'acciones_posibles' => [], 'ir' => null];
}

/**
 * Todas las solicitudes del programa con sus estados (las cerradas desde «desde»). Filtros: estado (abiertas|cerradas|todas),
 * tipos, cliente_id, desde. Cada una con la forma de §5.8; con $completo también el detalle largo (conversación del ticket).
 */
function tc2_solicitudes_todas(array $f = [], bool $completo = false, ?string $solo_id = null): array
{
    $estado_f = $f['estado'] ?? 'abiertas';
    $desde = $f['desde'] ?? date('Y-m-d', strtotime('-60 days'));
    $tipos = $f['tipos'] ?? [];
    $cid = isset($f['cliente_id']) && $f['cliente_id'] !== null && $f['cliente_id'] !== '' ? (string)$f['cliente_id'] : null;
    $solo = tc_solo();
    $nombre_prod = tc_producto()[1];
    $hoy = date('Y-m-d');
    $att = tc2_atenciones();
    $quiere = fn(string $t) => (!$tipos || in_array($t, $tipos, true)) && ($solo_id === null || str_starts_with($solo_id, $t . ':'));
    $clientes = null;
    $cli = function () use (&$clientes) { return $clientes ??= tc2_clientes_resumen(); };
    $ligas = array_column(filas("SELECT l.* FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0$solo"), null, 'id');
    $quien_liga = function (array $l): array {
        $tel = (string)$l['contacto_telefono'];
        return ['nombre' => tc2_txt($l['contacto_nombre']) ?: null, 'organizacion' => (string)$l['nombre'], 'telefono' => $tel !== '' ? $tel : null,
                'whatsapp' => tc2_wa($tel), 'email' => (string)$l['contacto_email'] !== '' ? (string)$l['contacto_email'] : null];
    };
    $estado_att = fn(string $sid) => isset($att[$sid]) ? (string)$att[$sid]['estado'] : 'pendiente';
    $r = [];

    // 1) Pedidos de demo (solicitudes_demo)
    if ($quiere('demo_pedida')) {
        $mapa = ['nueva' => 'pendiente', 'contactada' => 'en_proceso', 'creada' => 'atendida', 'descartada' => 'descartada'];
        foreach (filas("SELECT s.*, p.nombre AS plan_nombre, v.nombre AS vendedor FROM solicitudes_demo s LEFT JOIN planes p ON p.id = s.plan_id LEFT JOIN vendedores v ON v.id = s.vendedor_id
                        WHERE s.estado IN ('nueva', 'contactada') OR s.creado >= ? ORDER BY s.id DESC", [$desde]) as $s) {
            $sid = 'demo_pedida:' . $s['id'];
            $est = $mapa[$s['estado']] ?? 'pendiente';
            $wa = tc2_wa($s['telefono']);
            $acc = [];
            if ($w = tc2_boton_wa($wa, mensaje_contacto_demo($s))) $acc[] = $w;
            if ($s['estado'] !== 'creada') {
                $acc[] = tc2_boton('crear_demo', 'Crear demo', 'principal', '¿Crear la demo de ' . $s['organizacion'] . '?', ['solicitud_id' => $sid], array_merge([
                    tc2_campo('nombre', 'Nombre del negocio', 'texto', true, ['valor' => tc2_txt($s['organizacion'], 120), 'min' => 3, 'max' => 120]),
                    tc2_campo('contacto_nombre', 'Nombre del contacto', 'texto', true, ['valor' => tc2_txt($s['contacto'], 80), 'min' => 3, 'max' => 80]),
                    tc2_campo('email', 'Correo (será su usuario)', 'email', true, ['valor' => (string)$s['email']]),
                    tc2_campo('telefono', 'Celular', 'telefono', true, ['valor' => (string)$s['telefono']]),
                    tc2_campo('plan', 'Plan', 'opcion', false, ['valor' => $s['plan_id'] ? (string)$s['plan_id'] : null, 'opciones' => array_map(fn($p) => ['valor' => (string)$p['id'], 'etiqueta' => (string)$p['nombre']], array_values(tc2_planes_para(['tipo' => $s['tipo'] === 'escuela' || tc_producto()[2] ? 'escuela' : 'liga'])))]),
                    tc2_campo('dias', 'Días de prueba', 'dias', true, ['valor' => (int)ajuste('dias_demo', 7), 'min' => 1, 'max' => 90]),
                    tc2_campo('enviar_correo', 'Enviarle el correo de bienvenida', 'si_no', false, ['valor' => true]),
                ], array_map(fn($c) => $c['campo'] === 'tipo' ? ['valor' => $s['tipo'] === 'escuela' ? 'escuela' : 'liga'] + $c : ($c['campo'] === 'ciudad' ? ['valor' => tc2_txt($s['ciudad'], 80)] + $c : $c),
                    tc2_form_crear_demo())));
                $acc[] = tc2_boton_atender($sid, 'Marcar contactada', [], ['en_proceso' => 'Contactada', 'descartada' => 'Descartada']);
            }
            $acc[] = tc2_boton_abrir('/admin.php?p=demos&estado=' . ($s['estado'] === 'creada' ? 'creada' : $s['estado']));
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'demo_pedida', 'categoria' => 'pedido', 'titulo' => 'Pedido de demo: ' . tc2_txt($s['organizacion']),
                'fecha' => tc2_momento($s['creado'], true), 'actualizado' => tc2_momento($s['atendido_en']) ?? tc2_momento($s['creado'], true),
                'cliente_id' => $s['liga_id'] && isset($ligas[$s['liga_id']]) ? (string)$s['liga_id'] : null,
                'quien' => ['nombre' => tc2_txt($s['contacto']), 'organizacion' => tc2_txt($s['organizacion']), 'telefono' => (string)$s['telefono'] ?: null, 'whatsapp' => $wa, 'email' => (string)$s['email'] ?: null],
                'resumen' => implode(' · ', array_filter([$s['tipo'] === 'escuela' ? (tc_producto()[2] ? 'Academia' : 'Escuela') : 'Liga', tc2_txt($s['ciudad']), tc2_txt($s['tamano']), $s['plan_nombre'] ? 'Le interesa: ' . $s['plan_nombre'] : ''])),
                'estado' => $est, 'estado_producto' => (string)$s['estado'], 'prioridad' => $est === 'pendiente' ? 'alta' : 'normal',
                'detalle' => ['tipo' => (string)$s['tipo'], 'ciudad' => tc2_txt($s['ciudad']), 'tamano' => tc2_txt($s['tamano']),
                              'plan_interes' => $s['plan_id'] ? ['codigo' => (string)$s['plan_id'], 'nombre' => (string)$s['plan_nombre']] : null,
                              'deportes' => ($s['deportes'] ?? '') !== '' ? explode(',', (string)$s['deportes']) : [], 'mensaje' => tc2_txt($s['mensaje']),
                              'vendedor' => $s['vendedor'] ? (string)$s['vendedor'] : null, 'nota' => tc2_txt($s['nota']), 'atendido_por' => tc2_txt($s['atendido_por'])],
                'acciones_posibles' => $acc, 'ir' => '/admin.php?p=demos',
            ]);
        }
    }

    // 2) Registros nuevos (prueba gratis automática) de los últimos 30 días
    if ($quiere('registro_nuevo')) {
        $reg = array_flip(array_column(filas("SELECT DISTINCT liga_id FROM consentimientos WHERE formulario = 'registro' AND liga_id IS NOT NULL"), 'liga_id'));
        foreach ($ligas as $id => $l) {
            if (!(($l['origen'] ?? '') === 'registro' || isset($reg[$id]))) continue;
            if ((string)$l['creado'] < gmdate('Y-m-d H:i:s', strtotime('-30 days'))) continue;
            $sid = 'registro_nuevo:' . $id;
            $est = $estado_att($sid);
            $c = $cli()[$id] ?? null;
            if (!$c) continue;
            $acc = [];
            if ($w = tc2_boton_wa($c['dueno']['whatsapp'], tc2_mensajes($l, $c)['bienvenida'], 'WhatsApp de bienvenida')) $acc[] = $w;
            if ($c['estado'] === 'prueba' || ($c['estado'] === 'vencido' && !$c['pagos']['cantidad'])) $acc[] = tc2_boton('extender_prueba', 'Alargar prueba', 'normal', null, ['cliente_id' => (string)$id], [tc2_campo('dias', 'Días más', 'dias', true, ['valor' => 7, 'min' => 1, 'max' => 90])]);
            if ($c['estado'] !== 'suspendido') $acc[] = tc2_boton('registrar_pago', 'Registrar pago', 'normal', '¿Registrar el pago de ' . $l['nombre'] . '?', ['cliente_id' => (string)$id], tc2_campos_pago($l));
            $acc[] = tc2_boton_atender($sid);
            $acc[] = tc2_boton_abrir($c['ir']);
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'registro_nuevo', 'titulo' => 'Se registró solo: ' . $l['nombre'], 'fecha' => tc2_momento($l['creado'], true),
                'actualizado' => tc2_momento($att[$sid]['momento'] ?? '') ?? tc2_momento($l['creado'], true), 'cliente_id' => (string)$id, 'quien' => $quien_liga($l),
                'resumen' => $c['estado_texto'], 'estado' => $est, 'estado_producto' => $c['estado_detalle'], 'prioridad' => 'normal',
                'detalle' => ['estado_cliente' => $c['estado'], 'prueba_hasta' => $c['fechas']['prueba_hasta'], 'uso' => $c['uso'], 'nota' => (string)($att[$sid]['nota'] ?? '')],
                'acciones_posibles' => $acc, 'ir' => $c['ir'],
            ]);
        }
    }

    // 3) Comprobantes de pago (solicitudes_pago)
    if ($quiere('comprobante')) {
        $mapa = ['pendiente' => 'pendiente', 'aprobada' => 'atendida', 'rechazada' => 'descartada'];
        foreach (filas("SELECT s.*, p.nombre AS plan_nombre, l.nombre AS liga_nombre, l.plan_id AS plan_actual, pa.nombre AS plan_actual_nombre
                        FROM solicitudes_pago s JOIN ligas l ON l.id = s.liga_id LEFT JOIN planes p ON p.id = s.plan_id LEFT JOIN planes pa ON pa.id = l.plan_id
                        WHERE COALESCE(l.es_demo, 0) = 0$solo AND (s.estado = 'pendiente' OR s.creado >= ?) ORDER BY s.id DESC", [$desde]) as $s) {
            $sid = 'comprobante:' . $s['id'];
            $l = $ligas[$s['liga_id']] ?? null;
            if (!$l) continue;
            $est = $mapa[$s['estado']] ?? 'pendiente';
            $ds_s = ($s['deportes'] ?? '') !== '' ? explode(',', (string)$s['deportes']) : null;
            $cambia = (int)$s['plan_id'] !== (int)$l['plan_id'];
            if (!$cambia && $ds_s !== null) {
                $a1 = $ds_s; $a2 = deportes_cobrados($l);
                sort($a1); sort($a2);
                $cambia = $a1 !== $a2;
            }
            $quien = $quien_liga($l);
            $acc = [];
            if ($s['estado'] === 'pendiente') {
                $acc[] = tc2_boton('aprobar_comprobante', 'Aprobar y renovar', 'principal', '¿Aprobar y renovar ' . $l['nombre'] . ' por ' . (int)$s['meses'] . ' mes(es)?', ['solicitud_id' => $sid], [
                    tc2_campo('monto', 'Valor recibido ($)', 'dinero', true, ['valor' => tc2_dinero($s['monto']), 'min' => 0]),
                    tc2_campo('nota', 'Nota', 'texto', false, ['max' => 300])]);
                $acc[] = tc2_boton('rechazar_comprobante', 'Rechazar', 'peligro', null, ['solicitud_id' => $sid], [tc2_campo('motivo', 'Motivo (lo verá el cliente)', 'texto', true, ['min' => 5, 'max' => 300])]);
            }
            if ($w = tc2_boton_wa($quien['whatsapp'], 'Hola' . ($quien['nombre'] ? ' ' . $quien['nombre'] : '') . ', le saluda ' . ajuste('empresa') . '. Recibimos su comprobante de pago para ' . $l['nombre'] . ($s['estado'] === 'pendiente' ? ' y lo estamos revisando.' : '.'))) $acc[] = $w;
            $acc[] = tc2_boton_abrir('/admin.php?p=suscripciones#comprobantes');
            [, $per_n] = tc2_periodo_de((int)$s['meses']);
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'comprobante', 'titulo' => $cambia ? 'Comprobante y cambio de plan' : 'Comprobante de pago por revisar',
                'fecha' => tc2_momento($s['creado']), 'actualizado' => tc2_momento($s['atendido_en']) ?? tc2_momento($s['creado']), 'cliente_id' => (string)$l['id'], 'quien' => $quien,
                'resumen' => 'Plan ' . ($s['plan_nombre'] ?? '—') . ' · ' . (int)$s['meses'] . ' mes' . ((int)$s['meses'] === 1 ? '' : 'es') . ' · ' . str_replace('.', ',', dinero((float)$s['monto'])) . ' · ' . tc2_nombre_metodo((string)$s['forma_pago']),
                'monto' => tc2_dinero($s['monto']), 'estado' => $est, 'estado_producto' => (string)$s['estado'], 'prioridad' => 'alta',
                'detalle' => ['plan' => $s['plan_id'] ? ['codigo' => (string)$s['plan_id'], 'nombre' => (string)$s['plan_nombre']] : null, 'meses' => (int)$s['meses'], 'periodo_nombre' => $per_n,
                              'forma_pago' => (string)$s['forma_pago'], 'nota' => tc2_txt($s['nota']), 'enviado_por' => tc2_txt($s['enviado_por']), 'cambia_plan' => $cambia,
                              'plan_actual' => $s['plan_actual'] ? ['codigo' => (string)$s['plan_actual'], 'nombre' => (string)$s['plan_actual_nombre']] : null,
                              'deportes' => $ds_s, 'respuesta' => tc2_txt($s['respuesta']), 'atendido_por' => tc2_txt($s['atendido_por']), 'pago_id' => $s['pago_id'] ? (string)$s['pago_id'] : null],
                'adjuntos' => (string)$s['archivo'] !== '' ? [['id' => '1', 'nombre' => 'comprobante_' . $s['id'] . '.' . (pathinfo((string)$s['archivo'], PATHINFO_EXTENSION) ?: 'jpg'), 'tipo_mime' => tc2_mime_de((string)$s['archivo'])]] : [],
                'acciones_posibles' => $acc, 'ir' => '/admin.php?p=suscripciones#comprobantes',
            ]);
        }
    }

    // 4) Soporte (soporte_tickets)
    if ($quiere('soporte')) {
        foreach (filas("SELECT t.* FROM soporte_tickets t LEFT JOIN ligas l ON l.id = t.liga_id
                        WHERE (t.liga_id IS NULL OR (COALESCE(l.es_demo, 0) = 0$solo))
                          AND (t.sin_leer_super = 1 OR t.estado IN ('nuevo', 'en_proceso', 'esperando') OR t.actualizado >= ?) ORDER BY t.id DESC", [$desde]) as $t) {
            $sid = 'soporte:' . $t['id'];
            // nuevo → pendiente; en_proceso / esperando → en_proceso; resuelto / cerrado → atendida; con algo sin leer por Tukán, siempre pendiente
            $est = (int)$t['sin_leer_super'] === 1 ? 'pendiente' : match ($t['estado']) { 'nuevo' => 'pendiente', 'en_proceso', 'esperando' => 'en_proceso', default => 'atendida' };
            $l = $t['liga_id'] ? ($ligas[$t['liga_id']] ?? null) : null;
            $tel = (string)$t['telefono'] !== '' ? (string)$t['telefono'] : (string)($l['contacto_telefono'] ?? '');
            $quien = ['nombre' => tc2_txt($t['autor']) ?: null, 'organizacion' => $l ? (string)$l['nombre'] : null, 'telefono' => $tel !== '' ? $tel : null, 'whatsapp' => tc2_wa($tel), 'email' => (string)$t['email'] ?: null];
            $msgs = filas('SELECT * FROM soporte_mensajes WHERE ticket_id = ? ORDER BY id', [$t['id']]);
            $adj = [];
            foreach ($msgs as $m) if ((string)$m['adjunto'] !== '') $adj[] = ['id' => (string)$m['id'], 'nombre' => 'captura_' . $m['id'] . '.' . (pathinfo((string)$m['adjunto'], PATHINFO_EXTENSION) ?: 'jpg'), 'tipo_mime' => tc2_mime_de((string)$m['adjunto'])];
            $det = ['subtipo' => (string)$t['tipo'], 'asunto' => tc2_txt($t['asunto']), 'prioridad_producto' => (string)$t['prioridad'], 'pagina' => tc2_txt($t['pagina']),
                    'mensajes_cantidad' => count($msgs), 'ultimo_mensaje' => $msgs ? mb_substr(tc2_txt(end($msgs)['texto']), 0, 300) : ''];
            if ($completo) {
                $det['mensajes'] = array_map(fn($m) => ['de' => (string)$m['de'], 'autor' => tc2_txt($m['autor']), 'momento' => tc2_momento($m['creado']), 'texto' => tc2_txt($m['texto']),
                                                        'adjunto_id' => (string)$m['adjunto'] !== '' ? (string)$m['id'] : null], $msgs);
            }
            $estados_t = [];
            foreach (['en_proceso' => 'En proceso', 'esperando' => 'Esperando al cliente', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'] as $k => $v) $estados_t[] = ['valor' => $k, 'etiqueta' => $v];
            $acc = [tc2_boton('responder_ticket', 'Responder', 'principal', null, ['solicitud_id' => $sid], [
                        tc2_campo('texto', 'Respuesta (la verá el cliente)', 'texto_largo', true, ['min' => 1, 'max' => 6000]),
                        tc2_campo('estado', 'Dejar el ticket en', 'opcion', false, ['valor' => 'esperando', 'opciones' => $estados_t])]),
                    tc2_boton_atender($sid, 'Marcar resuelto', [], ['en_proceso' => 'En proceso', 'atendida' => 'Resuelto', 'descartada' => 'Cerrado'])];
            if ($w = tc2_boton_wa($quien['whatsapp'], 'Hola' . ($quien['nombre'] ? ' ' . $quien['nombre'] : '') . ', le saluda soporte de ' . marca() . ' por su ticket #' . $t['id'] . ' (' . tc2_txt($t['asunto']) . ').')) $acc[] = $w;
            $acc[] = tc2_boton_abrir('/admin.php?p=soporte&t=' . $t['id']);
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'soporte', 'titulo' => ($t['tipo'] === 'funcionalidad' ? 'Pedido de funcionalidad' : 'Ticket') . ' #' . $t['id'] . ': ' . tc2_txt($t['asunto']),
                'fecha' => tc2_momento($t['creado']), 'actualizado' => tc2_momento($t['actualizado']), 'cliente_id' => $l ? (string)$l['id'] : null, 'quien' => $quien,
                'resumen' => ($l ? $l['nombre'] . ' · ' : '') . (tipos_ticket()[$t['tipo']][1] ?? $t['tipo']), 'estado' => $est, 'estado_producto' => (string)$t['estado'],
                'prioridad' => in_array($t['prioridad'], ['urgente', 'alta'], true) ? 'alta' : ($t['prioridad'] === 'baja' ? 'baja' : 'normal'),
                'detalle' => $det, 'adjuntos' => $adj, 'acciones_posibles' => $acc, 'ir' => '/admin.php?p=soporte&t=' . $t['id'],
            ]);
        }
    }

    // 5) Derechos de datos (LOPDP) dirigidos a Tukán (sin liga)
    if ($quiere('derechos_datos')) {
        $mapa = ['nueva' => 'pendiente', 'en_proceso' => 'en_proceso', 'respondida' => 'atendida', 'rechazada' => 'descartada'];
        foreach (filas("SELECT * FROM solicitudes_derechos WHERE liga_id IS NULL AND (estado IN ('nueva', 'en_proceso') OR creado >= ?) ORDER BY id DESC", [$desde]) as $s) {
            $sid = 'derechos_datos:' . $s['id'];
            $est = $mapa[$s['estado']] ?? 'pendiente';
            $acc = [tc2_boton_atender($sid, 'Responder', [tc2_campo('respuesta', 'Respuesta que se le dio (queda como prueba)', 'texto_largo', false, ['min' => 5, 'max' => 2000])],
                ['en_proceso' => 'En proceso', 'atendida' => 'Respondida', 'descartada' => 'No procede']), tc2_boton_abrir('/admin.php?p=datos')];
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'derechos_datos', 'titulo' => 'Protección de datos: ' . (tipos_derecho()[$s['tipo']] ?? $s['tipo']),
                'fecha' => tc2_momento($s['creado']), 'actualizado' => tc2_momento($s['respondido_en']) ?? tc2_momento($s['creado']),
                'quien' => ['nombre' => tc2_txt($s['nombre']), 'organizacion' => null, 'telefono' => (string)$s['telefono'] ?: null, 'whatsapp' => tc2_wa($s['telefono']), 'email' => (string)$s['email'] ?: null],
                'resumen' => tc2_txt(mb_substr((string)$s['detalle'], 0, 160)), 'estado' => $est, 'estado_producto' => (string)$s['estado'], 'prioridad' => 'alta',
                'vence_el' => (string)$s['creado'] !== '' ? date('Y-m-d', strtotime(substr((string)$s['creado'], 0, 10) . ' +15 days')) : null,
                'detalle' => ['derecho' => (string)$s['tipo'], 'en_nombre_de' => tc2_txt($s['en_nombre_de']), 'detalle' => tc2_txt($s['detalle']), 'respuesta' => tc2_txt($s['respuesta']),
                              'respondido_por' => tc2_txt($s['respondido_por']), 'cedula' => enmascarar_documento((string)$s['cedula'])],
                'acciones_posibles' => $acc, 'ir' => '/admin.php?p=datos',
            ]);
        }
    }

    // 6) Pagos automáticos (tarjeta, Google Play) de los últimos 30 días
    if ($quiere('pago_automatico')) {
        foreach (filas("SELECT ps.*, p.nombre AS plan_nombre FROM pagos_suscripcion ps JOIN ligas l ON l.id = ps.liga_id LEFT JOIN planes p ON p.id = ps.plan_id
                        WHERE COALESCE(l.es_demo, 0) = 0$solo AND ps.fecha >= ? AND (ps.registrado_por LIKE 'Pago en línea%' OR ps.forma_pago = 'google_play') ORDER BY ps.id DESC",
                       [date('Y-m-d', strtotime('-30 days'))]) as $p) {
            $sid = 'pago_automatico:' . $p['id'];
            $l = $ligas[$p['liga_id']];
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'pago_automatico', 'categoria' => 'aviso', 'titulo' => 'Pagó solo: ' . $l['nombre'] . ' · ' . str_replace('.', ',', dinero((float)$p['monto'])),
                'fecha' => tc2_momento($p['creado'], true), 'actualizado' => tc2_momento($att[$sid]['momento'] ?? '') ?? tc2_momento($p['creado'], true), 'cliente_id' => (string)$l['id'], 'quien' => $quien_liga($l),
                'resumen' => ($p['plan_nombre'] ? 'Plan ' . $p['plan_nombre'] . ' · ' : '') . (int)$p['meses'] . ' mes(es) · ' . tc2_nombre_metodo((string)$p['forma_pago']) . ' · hasta el ' . $p['hasta'],
                'monto' => tc2_dinero($p['monto']), 'estado' => $estado_att($sid), 'prioridad' => 'baja',
                'detalle' => ['pago_id' => (string)$p['id'], 'metodo' => (string)$p['forma_pago'], 'hasta' => (string)$p['hasta'], 'registrado_por' => tc2_txt($p['registrado_por'])],
                'acciones_posibles' => [tc2_boton_atender($sid, 'Visto', [], ['atendida' => 'Visto', 'descartada' => 'Descartar']), tc2_boton_abrir('/admin.php?p=ligas&editar=' . $l['id'])],
                'ir' => '/admin.php?p=ligas&editar=' . $l['id'],
            ]);
        }
    }

    // 7) Seguimiento de demos (faltan 2 días, vence hoy, terminó sin pagar en los últimos 60 días) y 8) renovaciones
    if ($quiere('seguimiento_prueba') || $quiere('renovacion')) {
        foreach ($ligas as $id => $l) {
            if ((int)$l['activa'] !== 1 || (string)$l['vence'] === '') continue;
            $s = estado_suscripcion($l);
            $dias = (int)$s['dias'];
            $v8 = str_replace('-', '', (string)$l['vence']);
            $etapa = null;
            $tipo = null;
            if (!empty($l['es_prueba'])) {
                $tipo = 'seguimiento_prueba';
                $etapa = $dias === 2 ? 'faltan_2' : ($dias === 0 ? 'vence_hoy' : ($dias < 0 && $dias >= -60 ? 'terminada' : null));
            } else {
                $tipo = 'renovacion';
                $etapa = $dias >= 0 && $dias <= 5 ? 'vence_pronto' : ($dias < 0 && $dias >= -30 ? 'vencida' : null);
            }
            if ($etapa === null || !$quiere($tipo)) continue;
            $sid = $tipo . ':' . $id . ':' . $etapa . ':' . $v8;
            $c = $cli()[$id] ?? null;
            if (!$c) continue;
            $m = tc2_mensajes($l, $c);
            $acc = [];
            if ($tipo === 'seguimiento_prueba') {
                if ($w = tc2_boton_wa($c['dueno']['whatsapp'], mensaje_seguimiento_demo($l, $etapa === 'terminada' ? -1 : $dias))) $acc[] = $w;
                if (!$c['pagos']['cantidad'] || $c['estado'] === 'prueba') $acc[] = tc2_boton('extender_prueba', 'Alargar prueba (+7)', 'normal', null, ['cliente_id' => (string)$id], [tc2_campo('dias', 'Días más', 'dias', true, ['valor' => 7, 'min' => 1, 'max' => 90])]);
                $titulo = match ($etapa) { 'faltan_2' => 'A la demo le quedan 2 días: ', 'vence_hoy' => 'La demo termina hoy: ', default => 'La demo terminó sin pagar: ' } . $l['nombre'];
            } else {
                if ($w = tc2_boton_wa($c['dueno']['whatsapp'], $m['recordar_renovacion'] ?? $m['vencido'] ?? $m['bienvenida'])) $acc[] = $w;
                $titulo = ($etapa === 'vence_pronto' ? 'Plan por vencer: ' : 'No renovó: ') . $l['nombre'];
            }
            $acc[] = tc2_boton('registrar_pago', 'Registrar pago', 'principal', '¿Registrar el pago de ' . $l['nombre'] . '?', ['cliente_id' => (string)$id], tc2_campos_pago($l));
            if ($tipo === 'renovacion' && $etapa === 'vencida') {
                $acc[] = tc2_boton('suspender', 'Suspender', 'peligro', '¿Suspender ' . $l['nombre'] . '?', ['cliente_id' => (string)$id], [tc2_campo('motivo', 'Motivo', 'texto', true, ['valor' => 'No renovó su suscripción', 'min' => 5, 'max' => 200])]);
            }
            $acc[] = tc2_boton_atender($sid);
            $acc[] = tc2_boton_abrir($c['ir']);
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => $tipo, 'categoria' => 'recordatorio', 'titulo' => $titulo,
                'fecha' => date('c', strtotime($etapa === 'terminada' || $etapa === 'vencida' ? $l['vence'] . ' +1 day' : ($etapa === 'faltan_2' ? $l['vence'] . ' -2 days' : ($etapa === 'vence_pronto' ? $l['vence'] . ' -5 days' : $l['vence'])))),
                'actualizado' => tc2_momento($att[$sid]['momento'] ?? '') ?? date('c', strtotime($hoy)), 'cliente_id' => (string)$id, 'quien' => $quien_liga($l),
                'resumen' => $c['estado_texto'], 'monto' => null, 'estado' => $estado_att($sid), 'estado_producto' => $c['estado_detalle'],
                'prioridad' => in_array($etapa, ['vence_hoy', 'vencida'], true) ? 'alta' : 'normal', 'vence_el' => (string)$l['vence'],
                'detalle' => ['etapa' => $etapa, 'vence' => (string)$l['vence'], 'dias' => $dias, 'uso' => $c['uso'], 'ultima_actividad' => $c['ultima_actividad'], 'plan' => $c['plan'],
                              'mensual_estimado' => $c['mensual_estimado'], 'nota' => (string)($att[$sid]['nota'] ?? '')],
                'acciones_posibles' => $acc, 'ir' => $c['ir'],
            ]);
        }
    }

    // 9) Dominio gratis por registrar (pagó 12 meses o más de un plan con dominio y la cuenta no tiene dominio)
    if ($quiere('dominio_gratis')) {
        foreach (filas("SELECT ps.*, p.nombre AS plan_nombre FROM pagos_suscripcion ps JOIN planes p ON p.id = ps.plan_id JOIN ligas l ON l.id = ps.liga_id
                        WHERE COALESCE(l.es_demo, 0) = 0$solo AND ps.meses >= 12 AND p.dominio_anual = 1 AND COALESCE(l.dominio, '') = '' ORDER BY ps.id DESC") as $p) {
            $sid = 'dominio_gratis:' . $p['id'];
            $l = $ligas[$p['liga_id']];
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'dominio_gratis', 'categoria' => 'recordatorio', 'titulo' => 'Dominio gratis por registrar: ' . $l['nombre'],
                'fecha' => tc2_momento($p['creado'], true), 'actualizado' => tc2_momento($att[$sid]['momento'] ?? '') ?? tc2_momento($p['creado'], true), 'cliente_id' => (string)$l['id'], 'quien' => $quien_liga($l),
                'resumen' => 'Pagó ' . (int)$p['meses'] . ' meses del plan ' . $p['plan_nombre'] . ' el ' . $p['fecha'], 'monto' => tc2_dinero($p['monto']), 'estado' => $estado_att($sid),
                'detalle' => ['pago_id' => (string)$p['id'], 'plan' => ['codigo' => (string)$p['plan_id'], 'nombre' => (string)$p['plan_nombre']], 'nota' => (string)($att[$sid]['nota'] ?? '')],
                'acciones_posibles' => [tc2_boton_atender($sid, 'Dominio registrado', [tc2_campo('dominio', 'Dominio (ej. suliga.com)', 'texto', false, ['max' => 100, 'ayuda' => 'Para activarlo en el servidor: Ligas → Editar → Dominio propio.'])]),
                                        tc2_boton_abrir('/admin.php?p=ligas&editar=' . $l['id'])],
                'ir' => '/admin.php?p=ligas&editar=' . $l['id'],
            ]);
        }
    }

    // 10) Comisiones pendientes a vendedores
    if ($quiere('comision_vendedor')) {
        foreach (filas("SELECT c.*, v.nombre AS vendedor, v.telefono AS vtel, l.nombre AS liga_nombre FROM comisiones c JOIN vendedores v ON v.id = c.vendedor_id JOIN ligas l ON l.id = c.liga_id
                        WHERE c.estado = 'pendiente' AND COALESCE(l.es_demo, 0) = 0$solo ORDER BY c.id DESC") as $c) {
            $sid = 'comision_vendedor:' . $c['id'];
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'comision_vendedor', 'categoria' => 'recordatorio', 'titulo' => 'Comisión por pagar a ' . tc2_txt($c['vendedor']),
                'fecha' => tc2_momento($c['creado'], true), 'actualizado' => tc2_momento($att[$sid]['momento'] ?? '') ?? tc2_momento($c['creado'], true), 'cliente_id' => (string)$c['liga_id'],
                'quien' => ['nombre' => tc2_txt($c['vendedor']), 'organizacion' => (string)$c['liga_nombre'], 'telefono' => (string)$c['vtel'] ?: null, 'whatsapp' => tc2_wa($c['vtel']), 'email' => null],
                'resumen' => str_replace('.', ',', dinero((float)$c['comision'])) . ' por ' . $c['liga_nombre'] . ' (' . (float)$c['porcentaje'] . ' % de ' . str_replace('.', ',', dinero((float)$c['monto_pago'])) . ')',
                'monto' => tc2_dinero($c['comision']), 'estado' => $estado_att($sid), 'estado_producto' => 'pendiente', 'prioridad' => 'normal',
                'detalle' => ['vendedor' => tc2_txt($c['vendedor']), 'pago_id' => (string)$c['pago_suscripcion_id'], 'porcentaje' => (float)$c['porcentaje'], 'nota' => (string)($att[$sid]['nota'] ?? '')],
                'acciones_posibles' => [tc2_boton_abrir('/admin.php?p=vendedores', 'Ver en Vendedores'), tc2_boton_atender($sid)], 'ir' => '/admin.php?p=vendedores',
            ]);
        }
    }

    // 11) Dinero de cobros con tarjeta por transferir a una organización
    if ($quiere('liquidacion_pendiente')) {
        foreach (filas("SELECT p.liga_id, COUNT(*) AS n, SUM(p.total) AS cobrado, SUM(p.neto) AS neto, MAX(p.id) AS ultimo, MIN(p.pagado_en) AS desde, MAX(p.pagado_en) AS hasta
                        FROM pagos_linea p JOIN ligas l ON l.id = p.liga_id WHERE p.destino = 'organizacion' AND p.estado = 'pagado' AND p.liquidacion_id IS NULL
                        AND COALESCE(l.es_demo, 0) = 0$solo GROUP BY p.liga_id") as $x) {
            $sid = 'liquidacion_pendiente:' . $x['liga_id'] . ':' . $x['ultimo'];
            $l = $ligas[$x['liga_id']];
            $r[] = tc2_sol([
                'id' => $sid, 'tipo' => 'liquidacion_pendiente', 'categoria' => 'recordatorio', 'titulo' => 'Por transferir a ' . $l['nombre'] . ': ' . str_replace('.', ',', dinero((float)$x['neto'])),
                'fecha' => tc2_momento($x['hasta']), 'actualizado' => tc2_momento($att[$sid]['momento'] ?? '') ?? tc2_momento($x['hasta']), 'cliente_id' => (string)$l['id'], 'quien' => $quien_liga($l),
                'resumen' => (int)$x['n'] . ' cobro(s) con tarjeta sin liquidar · cobrado ' . str_replace('.', ',', dinero((float)$x['cobrado'])), 'monto' => tc2_dinero($x['neto']), 'estado' => $estado_att($sid),
                'detalle' => ['pagos' => (int)$x['n'], 'cobrado' => tc2_dinero($x['cobrado']), 'neto' => tc2_dinero($x['neto']), 'desde' => tc2_momento($x['desde']), 'hasta' => tc2_momento($x['hasta']), 'nota' => (string)($att[$sid]['nota'] ?? '')],
                'acciones_posibles' => [tc2_boton_abrir('/admin.php?p=pasarela', 'Ver en Cobros con tarjeta'), tc2_boton_atender($sid)], 'ir' => '/admin.php?p=pasarela',
            ]);
        }
    }

    // Filtros
    $r = array_values(array_filter($r, function ($s) use ($estado_f, $desde, $cid, $solo_id) {
        if ($solo_id !== null) return $s['id'] === $solo_id;
        if ($cid !== null && $s['cliente_id'] !== $cid) return false;
        $ab = tc2_abierta($s['estado']);
        if ($estado_f === 'abiertas') return $ab;
        $reciente = substr((string)($s['actualizado'] ?? $s['fecha']), 0, 10) >= $desde || substr((string)$s['fecha'], 0, 10) >= $desde;
        if ($estado_f === 'cerradas') return !$ab && $reciente;
        return $ab || $reciente;
    }));
    $prio = ['alta' => 0, 'normal' => 1, 'baja' => 2];
    usort($r, fn($a, $b) => (tc2_abierta($b['estado']) <=> tc2_abierta($a['estado'])) ?: (($prio[$a['prioridad']] ?? 1) <=> ($prio[$b['prioridad']] ?? 1)) ?: strcmp((string)$b['fecha'], (string)$a['fecha']) ?: strcmp($a['id'], $b['id']));
    return $r;
}

/** Tipo MIME de un archivo guardado en data/ (por la extensión si no se puede leer). */
function tc2_mime_de(string $rel): string
{
    $abs = RAIZ . '/data/' . $rel;
    $m = is_file($abs) ? (string)@mime_content_type($abs) : '';
    if ($m !== '') return $m;
    return match (strtolower(pathinfo($rel, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf', default => 'image/jpeg' };
}

/** Acción «solicitudes» (§5.8). */
function tc2_solicitudes_pedido(array $d): array
{
    $estado = $d['estado'] ?? 'abiertas';
    if (!in_array($estado, ['abiertas', 'cerradas', 'todas'], true)) throw tc2_mal('estado', 'Estado válido: abiertas, cerradas o todas.');
    $tipos = $d['tipos'] ?? [];
    if (!is_array($tipos) || array_diff($tipos, TC_TIPOS_SOLICITUD)) throw tc2_mal('tipos', 'Tipos válidos: ' . implode(', ', TC_TIPOS_SOLICITUD) . '.');
    $cid = $d['cliente_id'] ?? null;
    if ($cid !== null && $cid !== '' && tc2_id_valor($cid) === null) throw tc2_mal('cliente_id', 'Cliente no válido.');
    $desde = tc2_fecha_opcional($d, 'desde') ?: date('Y-m-d', strtotime('-60 days'));
    $pagina = tc2_entero($d, 'pagina', 1, 1, 100000);
    $por_pagina = tc2_entero($d, 'por_pagina', 100, 1, 200);
    $lista = tc2_solicitudes_todas(['estado' => $estado, 'tipos' => $tipos, 'cliente_id' => $cid === null || $cid === '' ? null : tc2_id_valor($cid), 'desde' => $desde]);
    $cont = [];
    foreach ($lista as $s) $cont[$s['tipo']] = ($cont[$s['tipo']] ?? 0) + 1;
    $total = count($lista);
    return ['total' => $total, 'pagina' => $pagina, 'por_pagina' => $por_pagina, 'paginas' => max(1, (int)ceil($total / $por_pagina)),
            'contadores' => (object)$cont, 'solicitudes' => array_slice($lista, ($pagina - 1) * $por_pagina, $por_pagina)];
}

/** Una solicitud por su id (abierta o cerrada), o null. */
function tc2_solicitud(string $id, bool $completo = false): ?array
{
    $tipo = explode(':', $id)[0];
    if (!in_array($tipo, TC_TIPOS_SOLICITUD, true)) return null;
    $r = tc2_solicitudes_todas(['estado' => 'todas', 'desde' => '0000-00-00', 'tipos' => [$tipo]], $completo, $id);
    return $r[0] ?? null;
}

/** Acción «adjunto» (§5.10): foto del comprobante o captura de un ticket, revisando que sea de ESA solicitud. */
function tc2_adjunto(array $d): array
{
    $sid = tc2_id_requerido($d, 'solicitud_id');
    $aid = tc2_id_requerido($d, 'adjunto_id');
    $no = fn() => new TcError('no_encontrado', 'Ese archivo ya no existe en el programa.', 404);
    $rel = '';
    if (preg_match('/^comprobante:(\d+)$/', $sid, $m) && $aid === '1') {
        $s = fila('SELECT s.archivo FROM solicitudes_pago s JOIN ligas l ON l.id = s.liga_id WHERE s.id = ? AND COALESCE(l.es_demo, 0) = 0' . tc_solo(), [(int)$m[1]]);
        $rel = (string)($s['archivo'] ?? '');
        $nombre = 'comprobante_' . $m[1];
    } elseif (preg_match('/^soporte:(\d+)$/', $sid, $m) && ctype_digit($aid)) {
        $s = fila('SELECT m.adjunto FROM soporte_mensajes m JOIN soporte_tickets t ON t.id = m.ticket_id LEFT JOIN ligas l ON l.id = t.liga_id
                   WHERE m.id = ? AND m.ticket_id = ? AND (t.liga_id IS NULL OR (COALESCE(l.es_demo, 0) = 0' . tc_solo() . '))', [(int)$aid, (int)$m[1]]);
        $rel = (string)($s['adjunto'] ?? '');
        $nombre = 'captura_' . $aid;
    } else {
        throw $no();
    }
    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || str_starts_with($rel, '/')) throw $no();
    $base = realpath(RAIZ . '/data');
    $abs = realpath(RAIZ . '/data/' . $rel);
    if ($base === false || $abs === false || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR) || !is_file($abs)) throw $no();
    $mime = (string)@mime_content_type($abs);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) throw new TcError('no_permitido', 'Ese tipo de archivo no se puede mostrar.', 403, null, ['motivo' => 'tipo']);
    $tam = (int)filesize($abs);
    if ($tam > 6 * 1024 * 1024) throw new TcError('no_permitido', 'El archivo es demasiado grande (más de 6 MiB).', 403, null, ['motivo' => 'tamano']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
    return ['nombre' => $nombre . '.' . $ext, 'tipo_mime' => $mime, 'tamano' => $tam, 'base64' => base64_encode((string)file_get_contents($abs))];
}

/* ============================================================
 * v2 — FINANZAS (§5.11)
 * ============================================================ */

function tc2_finanzas(array $d): array
{
    $mes_ok = fn($v) => is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v);
    $hasta = $d['hasta'] ?? '';
    $desde = $d['desde'] ?? '';
    if ($hasta === '' || $hasta === null) $hasta = date('Y-m');
    if (!$mes_ok($hasta)) throw tc2_mal('hasta', 'Escriba el mes así: AAAA-MM.');
    if ($desde === '' || $desde === null) $desde = date('Y-m', strtotime($hasta . '-01 -11 months'));
    if (!$mes_ok($desde)) throw tc2_mal('desde', 'Escriba el mes así: AAAA-MM.');
    if ($desde > $hasta) throw tc2_mal('desde', '«desde» debe ser antes que «hasta».');
    $meses = [];
    for ($m = $desde; $m <= $hasta; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $meses[] = $m;
        if (count($meses) > 24) throw tc2_mal('desde', 'Máximo 24 meses.');
    }
    $acad = tc_producto()[2];
    $solo = tc_solo(true); // (Anexo A) con la papelera, pero solo con lo que pagó ANTES de borrarla (tc_pago_antes_de_borrar)
    $ini = $desde . '-01';
    $fin = date('Y-m-t', strtotime($hasta . '-01'));
    $planes = array_column(filas('SELECT id, nombre FROM planes'), 'nombre', 'id');
    $pagos = filas("SELECT ps.*, l.eliminada AS _eliminada FROM pagos_suscripcion ps JOIN ligas l ON l.id = ps.liga_id WHERE COALESCE(l.es_demo, 0) = 0$solo" . tc_pago_antes_de_borrar('ps.fecha'));
    $primero = [];
    foreach ($pagos as $p) if ($p['fecha'] !== '' && (!isset($primero[$p['liga_id']]) || $p['fecha'] < $primero[$p['liga_id']])) $primero[$p['liga_id']] = $p['fecha'];
    $M = [];
    foreach ($meses as $m) $M[$m] = ['mes' => $m, 'ingresos' => 0.0, 'pagos' => 0, 'clientes_que_pagaron' => [], 'nuevos_pagadores' => 0, 'devengado' => 0.0,
                                     'por_plan' => [], 'por_periodo' => [], 'por_metodo' => [], 'otros_ingresos' => [], 'costos' => [], '_gp' => 0.0];
    foreach ($primero as $f) { $mm = substr($f, 0, 7); if (isset($M[$mm])) $M[$mm]['nuevos_pagadores']++; }
    foreach ($pagos as $p) {
        $monto = (float)$p['monto'];
        $mm = substr((string)$p['fecha'], 0, 7);
        if (isset($M[$mm])) {
            $x = &$M[$mm];
            $x['ingresos'] += $monto;
            $x['pagos']++;
            $x['clientes_que_pagaron'][$p['liga_id']] = true;
            $kp = $p['plan_id'] ? (string)$p['plan_id'] : '';
            $x['por_plan'][$kp] ??= ['plan' => $kp !== '' ? $kp : null, 'nombre' => $kp !== '' ? (string)($planes[$kp] ?? 'Plan ' . $kp) : 'Sin plan', 'monto' => 0.0, 'pagos' => 0];
            $x['por_plan'][$kp]['monto'] += $monto; $x['por_plan'][$kp]['pagos']++;
            [$per] = tc2_periodo_de((int)$p['meses']);
            $x['por_periodo'][$per] ??= ['periodo' => $per, 'meses' => (int)$p['meses'], 'monto' => 0.0, 'pagos' => 0];
            $x['por_periodo'][$per]['monto'] += $monto; $x['por_periodo'][$per]['pagos']++;
            $f = (string)$p['forma_pago'];
            $x['por_metodo'][$f] ??= ['metodo' => $f, 'nombre' => tc2_nombre_metodo($f), 'monto' => 0.0, 'pagos' => 0];
            $x['por_metodo'][$f]['monto'] += $monto; $x['por_metodo'][$f]['pagos']++;
            if ($f === 'google_play') $x['_gp'] += $monto;
            unset($x);
        }
        // devengado: el pago se reparte por días entre «desde» y «hasta»
        $a = strtotime((string)$p['desde'] ?: (string)$p['fecha']);
        $b = strtotime((string)$p['hasta'] ?: (string)$p['fecha']);
        if ((string)$p['_eliminada'] !== '' && $b) $b = min($b, strtotime(substr((string)$p['_eliminada'], 0, 10))); // (Anexo A) no devenga después de borrarla
        if (!$a) continue;
        if (!$b || $b <= $a) {
            $mm2 = date('Y-m', $a);
            if (isset($M[$mm2])) $M[$mm2]['devengado'] += $monto;
            continue;
        }
        $total_dias = ($b - $a) / 86400;
        if (date('Y-m-d', $b) < $ini || date('Y-m-d', $a) > $fin) continue;
        foreach ($meses as $m) {
            $ma = strtotime($m . '-01');
            $mb = strtotime($m . '-01 +1 month');
            $sol = min($b, $mb) - max($a, $ma);
            if ($sol > 0) $M[$m]['devengado'] += $monto * ($sol / 86400) / $total_dias;
        }
    }
    // otros ingresos y costos que el programa conoce
    $por_mes = fn(string $sql) => array_column(filas($sql, [$desde, $hasta]), 'v', 'm');
    $gestion = $por_mes("SELECT substr(p.pagado_en, 1, 7) AS m, SUM(p.comision_tuvocalia) AS v FROM pagos_linea p JOIN ligas l ON l.id = p.liga_id
                         WHERE p.destino = 'organizacion' AND p.estado = 'pagado' AND COALESCE(l.es_demo, 0) = 0$solo" . tc_pago_antes_de_borrar('p.pagado_en') . " AND substr(p.pagado_en, 1, 7) BETWEEN ? AND ? GROUP BY m");
    $pasarela = $por_mes("SELECT substr(p.pagado_en, 1, 7) AS m, SUM(p.comision_pasarela) AS v FROM pagos_linea p JOIN ligas l ON l.id = p.liga_id
                          WHERE p.destino = 'tuvocalia' AND p.estado = 'pagado' AND COALESCE(l.es_demo, 0) = 0$solo" . tc_pago_antes_de_borrar('p.pagado_en') . " AND substr(p.pagado_en, 1, 7) BETWEEN ? AND ? GROUP BY m");
    $vendedores = $por_mes("SELECT substr(c.creado, 1, 7) AS m, SUM(c.comision) AS v FROM comisiones c JOIN ligas l ON l.id = c.liga_id
                            WHERE c.estado <> 'anulada' AND COALESCE(l.es_demo, 0) = 0$solo" . tc_pago_antes_de_borrar('c.creado') . " AND substr(c.creado, 1, 7) BETWEEN ? AND ? GROUP BY m");
    $pct = rtrim(rtrim(number_format(tasas_pasarela()['tuvocalia'], 2, ',', ''), '0'), ',');
    $tot = ['ingresos' => 0.0, 'otros_ingresos' => 0.0, 'costos' => 0.0, 'pagos' => 0];
    $salida = [];
    foreach ($M as $m => $x) {
        if (($v = round((float)($gestion[$m] ?? 0), 2)) > 0) $x['otros_ingresos'][] = ['concepto' => 'gestion_cobros', 'nombre' => 'Gestión de cobros con tarjeta (' . $pct . ' %)', 'monto' => $v];
        if (($v = round((float)($pasarela[$m] ?? 0), 2)) > 0) $x['costos'][] = ['concepto' => 'comision_pasarela', 'categoria' => 'comisiones_pasarela', 'nombre' => 'Comisión de Plux en suscripciones', 'monto' => $v, 'estimado' => false];
        if (($v = round((float)($vendedores[$m] ?? 0), 2)) > 0) $x['costos'][] = ['concepto' => 'comision_vendedores', 'categoria' => 'sueldos_servicios', 'nombre' => 'Comisiones de vendedores', 'monto' => $v, 'estimado' => false];
        if (($v = round($x['_gp'] * 0.15, 2)) > 0) $x['costos'][] = ['concepto' => 'google_play', 'categoria' => 'tiendas_apps', 'nombre' => 'Comisión de Google Play (15 %, estimada)', 'monto' => $v, 'estimado' => true];
        $redo = fn(array $l) => array_values(array_map(fn($y) => ['monto' => round($y['monto'], 2)] + $y, $l));
        $fila = [
            'mes' => $m, 'ingresos' => round($x['ingresos'], 2), 'pagos' => $x['pagos'], 'clientes_que_pagaron' => count($x['clientes_que_pagaron']),
            'nuevos_pagadores' => $x['nuevos_pagadores'], 'devengado' => round($x['devengado'], 2),
            'por_plan' => $redo($x['por_plan']), 'por_periodo' => $redo($x['por_periodo']), 'por_metodo' => $redo($x['por_metodo']),
            'otros_ingresos' => $x['otros_ingresos'], 'costos' => $x['costos'],
        ];
        $tot['ingresos'] += $fila['ingresos'];
        $tot['pagos'] += $fila['pagos'];
        $tot['otros_ingresos'] += array_sum(array_column($fila['otros_ingresos'], 'monto'));
        $tot['costos'] += array_sum(array_column($fila['costos'], 'monto'));
        $salida[] = $fila;
    }
    $mrr = 0.0;
    foreach (tc2_clientes_resumen() as $c) $mrr += $c['mensual_estimado'];
    return [
        'moneda' => 'USD',
        'criterio' => 'Pagos de suscripción no anulados (los anulados se borran), por fecha de pago. Las demos del sistema no cuentan. De un cliente borrado (papelera) solo cuenta lo que pagó antes de borrarlo.' . ($acad ? ' Solo academias.' : ''),
        'desde' => $desde, 'hasta' => $hasta,
        'mrr' => round($mrr, 2),
        'meses' => $salida,
        'totales' => ['ingresos' => round($tot['ingresos'], 2), 'otros_ingresos' => round($tot['otros_ingresos'], 2), 'costos' => round($tot['costos'], 2), 'pagos' => $tot['pagos']],
    ];
}

/* ============================================================
 * v2 — EJECUTAR (§6, §7)
 * ============================================================ */

/**
 * Acción «ejecutar»: valida, revisa la clave de operación (tukan_central_operaciones) y hace la operación en UNA transacción
 * (BEGIN IMMEDIATE) junto con la respuesta guardada. Si se repite el mismo operacion_id con el mismo cuerpo, devuelve la
 * respuesta guardada (repetida: true) sin volver a hacer nada; con otro cuerpo → 409 conflicto.
 */
function tc2_ejecutar(array $p, array $d): never
{
    $op_id = $d['operacion_id'] ?? null;
    if (!is_string($op_id) || !preg_match('/^[0-9a-f]{32}$/', $op_id)) throw tc2_mal('operacion_id', 'Falta la clave de operación (32 caracteres hexadecimales en minúsculas).');
    $accion = $d['accion'] ?? null;
    if (!is_string($accion) || $accion === '') throw tc2_mal('accion', 'Falta la operación.');
    if (!in_array($accion, TC_OPERACIONES, true)) throw new TcError('no_permitido', 'Esa operación no está disponible en ' . tc_producto()[1] . '.', 403, 'accion', ['motivo' => 'no_disponible']);
    $datos = $d['datos'] ?? [];
    if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) throw tc2_mal('datos', '«datos» debe ser un objeto.');
    $email = mb_strtolower(trim((string)$p['email']), 'UTF-8');
    $nombre = tc2_txt(preg_replace('/\s+/', ' ', (string)($p['nombre'] ?? '')), 80);
    $quien = 'Panel Tukán Central · ' . ($nombre !== '' ? $nombre . ' ' : '') . '<' . $email . '>';
    $por = 'Panel Tukán Central (' . ($nombre !== '' ? $nombre : $email) . ')';
    $hash = (string)$p['_cuerpo_hash'];
    $pdo = db();
    contexto_auditoria(['usuario_id' => null, 'usuario' => $quien, 'rol' => 'super', 'liga_id' => null, 'origen' => 'tukan_central']);
    activar_auditoria();
    try {
        $pdo->exec("DELETE FROM tukan_central_operaciones WHERE creado < '" . date('Y-m-d H:i:s', strtotime('-90 days')) . "'");
        $pdo->transaccionInmediata();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'locked') || str_contains($e->getMessage(), 'busy')) throw new TcError('conflicto', 'El programa está ocupado con otra operación. Intente en unos segundos.', 409, null, ['motivo' => 'en_curso']);
        throw $e;
    }
    $previa = fila('SELECT * FROM tukan_central_operaciones WHERE operacion_id = ?', [$op_id]);
    if ($previa) {
        $pdo->rollBack();
        if (!hash_equals((string)$previa['cuerpo_hash'], $hash)) throw new TcError('conflicto', 'Esa operación ya se usó con otros datos. Abra el formulario otra vez.', 409, null, ['motivo' => 'operacion_id']);
        if ($previa['estado'] === 'en_curso') throw new TcError('conflicto', 'Esa operación todavía está en curso. Espere unos segundos.', 409, null, ['motivo' => 'en_curso']);
        $resp = json_decode((string)$previa['respuesta']) ?: new stdClass();
        $resp->repetida = true;
        tc_json((int)$previa['http'] ?: 200, $resp);
    }
    q("INSERT INTO tukan_central_operaciones (operacion_id, accion, cliente_id, cuerpo_hash, estado, email, creado) VALUES (?, ?, ?, ?, 'en_curso', ?, ?)",
        [$op_id, $accion, tc2_id_valor($d['cliente_id'] ?? null), $hash, $email, date('Y-m-d H:i:s')]);
    $ctx = ['op' => $op_id, 'accion' => $accion, 'quien' => $quien, 'por' => $por, 'email' => $email, 'nombre' => $nombre, 'cliente_id' => $d['cliente_id'] ?? $datos['cliente_id'] ?? null];
    try {
        $r = tc2_operacion($accion, $datos, $ctx);
        $resp = tc2_base('ejecutar') + [
            'operacion' => $accion, 'operacion_id' => $op_id, 'repetida' => false, 'sin_cambios' => (bool)($r['sin_cambios'] ?? false),
            'mensaje' => $r['mensaje'], 'resultado' => (object)($r['resultado'] ?? []), 'cliente' => null, 'solicitud' => null,
        ] + ($r['arriba'] ?? []); // (Anexo A) borrar_cliente: «copia» y «borrar_definitivo_el» también arriba
        // auditoría: una línea en la bitácora del programa con quién, qué, sobre qué cliente y la clave de operación
        $lid = $r['liga_id'] ?? null;
        bitacora_evento('tukan_central', mb_substr($r['bitacora'] ?? $r['mensaje'], 0, 420) . ' (op ' . substr($op_id, 0, 8) . ')', $lid, $r['registro_id'] ?? null);
        if ($lid) {
            $c = tc2_clientes_resumen([$lid]) ?: tc2_clientes_resumen([$lid], true); // (Anexo A) el de la papelera también
            $resp['cliente'] = $c[$lid] ?? null;
        }
        if (!empty($r['solicitud_id'])) $resp['solicitud'] = tc2_solicitud($r['solicitud_id']);
        $guardada = tc2_sin_claves($resp, $r['secretos'] ?? []);
        q("UPDATE tukan_central_operaciones SET estado = 'ok', http = 200, respuesta = ?, cliente_id = COALESCE(?, cliente_id), terminado = ? WHERE operacion_id = ?",
            [json_encode($guardada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $lid ? (string)$lid : null, date('Y-m-d H:i:s'), $op_id]);
        $pdo->commit();
        tc_json(200, $resp);
    } catch (TcError $e) {
        $pdo->rollBack();
        if ($e->http >= 500) throw $e;
        $cuerpo = tc_error_json($e);
        q("INSERT OR REPLACE INTO tukan_central_operaciones (operacion_id, accion, cliente_id, cuerpo_hash, estado, http, respuesta, email, creado, terminado) VALUES (?, ?, ?, ?, 'error', ?, ?, ?, ?, ?)",
            [$op_id, $accion, tc2_id_valor($d['cliente_id'] ?? null), $hash, $e->http, json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $email, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        throw $e;
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e; // error interno: la fila de la operación se va con el rollback (se puede reintentar)
    } catch (RuntimeException $e) {
        // reglas del programa (mensajes en español de sus funciones): datos que no se pueden usar
        $pdo->rollBack();
        $err = new TcError('invalido', $e->getMessage(), 422);
        $cuerpo = tc_error_json($err);
        q("INSERT OR REPLACE INTO tukan_central_operaciones (operacion_id, accion, cliente_id, cuerpo_hash, estado, http, respuesta, email, creado, terminado) VALUES (?, ?, ?, ?, 'error', 422, ?, ?, ?, ?)",
            [$op_id, $accion, tc2_id_valor($d['cliente_id'] ?? null), $hash, json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $email, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        throw $err;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Copia de la respuesta para guardar: sin claves (contraseñas) — §6.2 «claves de un solo uso». */
function tc2_sin_claves(array $resp, array $secretos): array
{
    $secretos = array_values(array_filter($secretos, fn($s) => is_string($s) && $s !== ''));
    $limpiar = function ($v) use (&$limpiar, $secretos) {
        if (is_array($v)) return array_map($limpiar, $v);
        if (is_string($v) && $secretos) return str_replace($secretos, '(oculta: use «Nueva clave del dueño»)', $v);
        return $v;
    };
    if (isset($resp['resultado']) && is_object($resp['resultado'])) $resp['resultado'] = (array)$resp['resultado'];
    $r = $limpiar($resp);
    if (isset($r['resultado']['acceso']) && is_array($r['resultado']['acceso'])) { $r['resultado']['acceso']['clave'] = null; $r['resultado']['acceso']['clave_oculta'] = true; }
    if (array_key_exists('clave', $r['resultado'] ?? [])) { $r['resultado']['clave'] = null; $r['resultado']['clave_oculta'] = true; }
    if (isset($r['resultado']['whatsapp_url']) && $secretos) $r['resultado']['whatsapp_url'] = null;
    return $r;
}

/** Cliente de la operación (cliente_id del pedido, arriba o en datos.cliente_id). $papelera: también uno de la papelera. */
function tc2_op_liga(array $ctx, bool $papelera = false): array
{
    $id = tc2_id_valor($ctx['cliente_id']);
    if ($id === null) throw tc2_mal('cliente_id', 'Falta el cliente.');
    $l = tc2_liga_o_404($id, $papelera);
    contexto_auditoria(['usuario_id' => null, 'usuario' => $ctx['quien'], 'rol' => 'super', 'liga_id' => (int)$l['id'], 'origen' => 'tukan_central']);
    return $l;
}

/** Solicitud de la operación (datos.solicitud_id) con el tipo esperado: [tipo, id numérico o resto, solicitud §5.8]. */
function tc2_op_solicitud(array $datos, array $tipos): array
{
    $sid = tc2_id_valor($datos['solicitud_id'] ?? null) ?? throw tc2_mal('datos.solicitud_id', 'Falta la solicitud.');
    $tipo = explode(':', $sid)[0];
    if (!in_array($tipo, $tipos, true)) {
        if ($tipo === 'comprobante') throw new TcError('no_permitido', 'Los comprobantes se atienden con «Aprobar» o «Rechazar».', 403, 'datos.solicitud_id', ['motivo' => 'usar_aprobar_rechazar']);
        throw new TcError('no_permitido', 'Esta operación no aplica a ese tipo de solicitud.', 403, 'datos.solicitud_id', ['motivo' => 'tipo']);
    }
    $sol = tc2_solicitud($sid) ?? throw new TcError('no_encontrado', 'Esa solicitud ya no existe en el programa (quizás ya pasó su fecha). Actualice la lista.', 404);
    return [$tipo, substr($sid, strlen($tipo) + 1), $sol];
}

/** Hace una operación. Devuelve ['mensaje', 'resultado', 'liga_id', 'solicitud_id', 'sin_cambios', 'secretos', 'bitacora', 'registro_id']. */
function tc2_operacion(string $accion, array $d, array $ctx): array
{
    $nprod = tc_producto()[1];
    switch ($accion) {
        case 'crear_demo':
            return tc2_op_crear_demo($d, $ctx);

        case 'extender_prueba':
            $l = tc2_op_liga($ctx);
            $dias = tc2_entero($d, 'dias', 0, 1, 90, 'datos.dias');
            if (!$dias) throw tc2_mal('datos.dias', 'Escriba cuántos días (1 a 90).');
            $x = tc2_estado_de($l);
            if ($x['estado'] === 'suspendido') throw new TcError('no_permitido', $l['nombre'] . ' está suspendida: primero reactívela.', 403, null, ['motivo' => 'suspendido']);
            if (!($x['estado'] === 'prueba' || ($x['estado'] === 'vencido' && !cuenta_tiene_pagos((int)$l['id'])))) {
                throw new TcError('no_permitido', $l['nombre'] . ' ya es un cliente que paga (o no tiene vencimiento): no se le alarga la prueba.', 403, null, ['motivo' => 'paga', 'estado' => $x['estado']]);
            }
            $hasta = extender_prueba_cuenta($l, $dias);
            return ['mensaje' => 'Demo de ' . $l['nombre'] . ' alargada hasta el ' . $hasta . '.', 'resultado' => ['prueba_hasta' => $hasta], 'liga_id' => (int)$l['id'],
                    'bitacora' => 'Alargó la demo de ' . $l['nombre'] . ' ' . $dias . ' día(s), hasta el ' . $hasta];

        case 'registrar_pago':
            $l = tc2_op_liga($ctx);
            [$pl, $meses, , $sugerido] = tc2_leer_plan_meses($l, $d, 'datos.', false);
            $monto = tc2_dinero_campo($d, 'monto', 'datos.monto', $sugerido);
            $metodo = $d['metodo'] ?? 'transferencia';
            if (!is_string($metodo) || !array_key_exists($metodo, formas_pago())) throw tc2_mal('datos.metodo', 'Forma de pago no válida (' . implode(', ', array_keys(formas_pago())) . ').');
            $fecha = $d['fecha'] ?? date('Y-m-d');
            if (!tc2_fecha_valida($fecha)) throw tc2_mal('datos.fecha', 'Escriba la fecha del pago así: AAAA-MM-DD.');
            if ($fecha > date('Y-m-d') || $fecha < date('Y-m-d', strtotime('-366 days'))) throw tc2_mal('datos.fecha', 'La fecha del pago debe ser de hoy o del último año.');
            $inicio = tc2_fecha_opcional($d, 'inicio', 'datos.inicio') ?: null;
            $nota = tc2_texto($d, 'nota', 0, 200, 'datos.nota');
            $dom = '';
            if (($d['dominio'] ?? '') !== '' && ($d['dominio'] ?? null) !== null) {
                if (!($meses >= 12 && (int)($pl['dominio_anual'] ?? 0) === 1)) throw tc2_mal('datos.dominio', 'El dominio gratis solo viene pagando 1 año de un plan con dominio.');
                $dom = limpiar_dominio(is_string($d['dominio']) ? $d['dominio'] : '');
                if ($dom === '') throw tc2_mal('datos.dominio', 'El dominio no es válido. Escríbalo así: suliga.com');
                if (fila('SELECT id FROM ligas WHERE dominio = ? AND id <> ?', [$dom, $l['id']])) throw tc2_mal('datos.dominio', 'Ese dominio ya lo usa otra cuenta.');
            }
            $r = venta_registrar_pago($l, (int)$pl['id'], $meses, (float)$monto, $metodo, $nota, $fecha, $ctx['por'], $inicio);
            if ($dom !== '') q('UPDATE ligas SET dominio = ? WHERE id = ?', [$dom, $l['id']]);
            $com = $r['comision'];
            return ['mensaje' => 'Pago registrado. ' . $l['nombre'] . ' queda activa hasta el ' . $r['hasta'] . '.' . (liga_suspendida($l) ? ' Sigue SUSPENDIDA: reactívela si corresponde.' : '') . ($com ? ' Comisión para ' . $com['nombre'] . ': ' . dinero((float)$com['comision']) . '.' : '')
                        . ($dom !== '' ? ' Falta activar el dominio ' . $dom . ' en el servidor (Ligas → Editar → Dominio propio).' : ''),
                    'resultado' => ['pago_id' => (string)$r['pago_id'], 'activo_hasta' => $r['hasta'], 'monto' => tc2_dinero($monto), 'meses' => $meses,
                                    'comision' => $com ? ['vendedor' => (string)$com['nombre'], 'monto' => tc2_dinero($com['comision'])] : null],
                    'liga_id' => (int)$l['id'], 'registro_id' => (int)$r['pago_id'],
                    'bitacora' => 'Pago registrado: ' . $pl['nombre'] . ' ' . $meses . ' mes(es) ' . str_replace('.', ',', dinero((float)$monto)) . ' (' . tc2_nombre_metodo($metodo) . '), activa hasta el ' . $r['hasta']];

        case 'anular_pago':
            $l = tc2_op_liga($ctx);
            $pid = tc2_id_requerido($d, 'pago_id', 'datos.pago_id');
            $motivo = tc2_texto($d, 'motivo', 5, 200, 'datos.motivo', 'Escriba el motivo (5 a 200 caracteres).');
            if (!ctype_digit($pid) || !fila('SELECT id FROM pagos_suscripcion WHERE id = ? AND liga_id = ?', [(int)$pid, $l['id']])) {
                throw new TcError('no_encontrado', 'Ese pago no existe en ' . $l['nombre'] . ' (quizás ya se anuló).', 404);
            }
            if (fila("SELECT id FROM comisiones WHERE pago_suscripcion_id = ? AND estado = 'pagada'", [(int)$pid])) {
                throw new TcError('no_permitido', 'Ese pago ya tiene una comisión PAGADA al vendedor. Primero revise la comisión en Vendedores.', 403, null, ['motivo' => 'comision_pagada']);
            }
            $an = anular_pago_suscripcion((int)$pid, (int)$l['id'], $motivo);
            return ['mensaje' => 'Pago anulado.' . ($an['cambio'] ? ' ' . $l['nombre'] . ' vuelve a vencer el ' . $an['vence'] . '.'
                                : ($an['sin_pagos'] ? ' No le quedan pagos: la fecha de vencimiento (' . ($an['vence'] ?: 'sin fecha') . ') no se cambió; revísela en ' . $nprod . ' (Ligas → Editar).' : ' La fecha de vencimiento no cambió (' . ($an['vence'] ?: 'sin fecha') . ').')),
                    'resultado' => ['activo_hasta' => $an['vence'] !== '' ? $an['vence'] : null, 'vence_cambio' => $an['cambio'], 'sin_pagos' => $an['sin_pagos']],
                    'liga_id' => (int)$l['id'], 'registro_id' => (int)$pid,
                    'bitacora' => 'Pago anulado: ' . str_replace('.', ',', dinero((float)$an['pago']['monto'])) . ' del ' . $an['pago']['fecha'] . '. Motivo: ' . $motivo];

        case 'aprobar_comprobante':
        case 'rechazar_comprobante':
            $sid = tc2_id_valor($d['solicitud_id'] ?? null) ?? throw tc2_mal('datos.solicitud_id', 'Falta la solicitud.');
            if (!preg_match('/^comprobante:(\d+)$/', $sid, $m)) throw new TcError('no_permitido', 'Esta operación es solo para comprobantes de pago.', 403, 'datos.solicitud_id', ['motivo' => 'tipo']);
            $s = fila('SELECT s.* FROM solicitudes_pago s JOIN ligas l ON l.id = s.liga_id WHERE s.id = ? AND COALESCE(l.es_demo, 0) = 0' . tc_solo(), [(int)$m[1]])
                ?? throw new TcError('no_encontrado', 'Ese comprobante ya no existe en el programa.', 404);
            if ($s['estado'] !== 'pendiente') throw new TcError('conflicto', 'Ese comprobante ya fue atendido (' . (estados_solicitud_pago()[$s['estado']] ?? $s['estado']) . ').', 409, null, ['motivo' => 'ya_atendida', 'estado' => $s['estado']]);
            $l = tc2_op_liga(['cliente_id' => (string)$s['liga_id']] + $ctx);
            if ($accion === 'aprobar_comprobante') {
                $monto = tc2_dinero_campo($d, 'monto', 'datos.monto', (float)$s['monto']);
                $nota = tc2_texto($d, 'nota', 0, 300, 'datos.nota');
                $r = aprobar_solicitud_pago((int)$s['id'], $ctx['por'], (float)$monto, $nota);
                return ['mensaje' => 'Comprobante aprobado: pago registrado y ' . $l['nombre'] . ' activa hasta el ' . $r['hasta'] . '. Le avisamos al cliente.',
                        'resultado' => ['pago_id' => (string)$r['pago_id'], 'activo_hasta' => $r['hasta'], 'monto' => tc2_dinero($monto)], 'liga_id' => (int)$l['id'], 'solicitud_id' => $sid,
                        'registro_id' => (int)$r['pago_id'], 'bitacora' => 'Aprobó el comprobante #' . $s['id'] . ' (' . str_replace('.', ',', dinero((float)$monto)) . '), activa hasta el ' . $r['hasta']];
            }
            $motivo = tc2_texto($d, 'motivo', 5, 300, 'datos.motivo', 'Escriba el motivo para el cliente (5 a 300 caracteres).');
            rechazar_solicitud_pago((int)$s['id'], $ctx['por'], $motivo);
            return ['mensaje' => 'Comprobante rechazado. El cliente recibió el motivo y puede volver a enviarlo.', 'resultado' => [], 'liga_id' => (int)$l['id'], 'solicitud_id' => $sid,
                    'registro_id' => (int)$s['id'], 'bitacora' => 'Rechazó el comprobante #' . $s['id'] . ': ' . $motivo];

        case 'suspender':
            $l = tc2_op_liga($ctx);
            $motivo = tc2_texto($d, 'motivo', 5, 200, 'datos.motivo', 'Escriba el motivo (5 a 200 caracteres).');
            $hecho = suspender_cuenta($l, $motivo);
            return ['mensaje' => $hecho ? $l['nombre'] . ' quedó suspendida: no puede trabajar y su página pública se ocultó. Sus datos se guardan.' : $l['nombre'] . ' ya estaba suspendida.',
                    'sin_cambios' => !$hecho, 'resultado' => ['estado' => 'suspendido'], 'liga_id' => (int)$l['id'], 'bitacora' => ($hecho ? 'Suspendió la cuenta: ' : 'Suspender (ya estaba suspendida): ') . $motivo];

        case 'reactivar':
            $l = tc2_op_liga($ctx);
            $hecho = reactivar_cuenta($l);
            $est = tc2_estado_de(fila('SELECT * FROM ligas WHERE id = ?', [$l['id']]))['estado'];
            return ['mensaje' => $hecho ? $l['nombre'] . ' está reactivada.' : $l['nombre'] . ' no estaba suspendida.', 'sin_cambios' => !$hecho, 'resultado' => ['estado' => $est],
                    'liga_id' => (int)$l['id'], 'bitacora' => $hecho ? 'Reactivó la cuenta' : 'Reactivar (no estaba suspendida)'];

        case 'cambiar_plan':
            $l = tc2_op_liga($ctx);
            $cod = tc2_id_valor($d['plan'] ?? null) ?? throw tc2_mal('datos.plan', 'Elija el plan.');
            $pl = ctype_digit($cod) ? plan_para_cuenta($l, (int)$cod) : null;
            if (!$pl) throw tc2_mal('datos.plan', 'Ese plan no existe o no es para este tipo de cuenta.');
            $hecho = cambiar_plan_cuenta($l, (int)$pl['id']);
            return ['mensaje' => $hecho ? 'Plan de ' . $l['nombre'] . ' cambiado a ' . $pl['nombre'] . ' (sin cobrar).' : $l['nombre'] . ' ya tenía el plan ' . $pl['nombre'] . '.', 'sin_cambios' => !$hecho,
                    'resultado' => ['plan' => ['codigo' => (string)$pl['id'], 'nombre' => (string)$pl['nombre']]], 'liga_id' => (int)$l['id'], 'bitacora' => 'Cambió el plan a ' . $pl['nombre'] . ($hecho ? '' : ' (ya lo tenía)')];

        case 'nueva_clave_dueno':
            $l = tc2_op_liga($ctx);
            if (!usuario_dueno($l)) throw new TcError('no_permitido', $l['nombre'] . ' no tiene un administrador activo. Créelo en ' . $nprod . ' (Ligas → Editar → Administradores).', 403, null, ['motivo' => 'sin_dueno']);
            $x = nueva_clave_dueno_cuenta($l);
            $msg = 'Hola ' . $x['usuario']['nombre'] . ', le saluda ' . ajuste('empresa') . '. Su contraseña nueva de ' . marca() . ' para ' . $l['nombre'] . " es:\n\n🔐 Panel: " . url_base() . "admin.php\n👤 Usuario: "
                . $x['usuario']['email'] . "\n🔑 Contraseña: " . $x['clave'] . "\n\nCámbiela al ingresar (Mi cuenta).";
            return ['mensaje' => 'Contraseña nueva para ' . $x['usuario']['email'] . '. Se muestra una sola vez.',
                    'resultado' => ['usuario' => (string)$x['usuario']['email'], 'clave' => $x['clave'], 'mensaje_whatsapp' => $msg], 'secretos' => [$x['clave']],
                    'liga_id' => (int)$l['id'], 'registro_id' => (int)$x['usuario']['id'], 'bitacora' => 'Contraseña temporal nueva para el dueño ' . $x['usuario']['email']];

        case 'nota_interna':
            $l = tc2_op_liga($ctx);
            $texto = $d['texto'] ?? '';
            if (!is_string($texto) || mb_strlen($texto) > 4000) throw tc2_mal('datos.texto', 'La nota puede tener hasta 4000 caracteres.');
            $texto = trim(str_replace("\r\n", "\n", strip_tags($texto)));
            if ($texto === (string)($l['notas_admin'] ?? '')) return ['mensaje' => 'La nota no cambió.', 'sin_cambios' => true, 'resultado' => [], 'liga_id' => (int)$l['id'], 'bitacora' => 'Nota interna (sin cambios)'];
            q('UPDATE ligas SET notas_admin = ? WHERE id = ?', [$texto, $l['id']]);
            return ['mensaje' => $texto === '' ? 'Nota interna borrada.' : 'Nota interna guardada.', 'resultado' => [], 'liga_id' => (int)$l['id'], 'bitacora' => $texto === '' ? 'Borró la nota interna' : 'Guardó la nota interna'];

        case 'atender_solicitud':
            return tc2_op_atender($d, $ctx);

        case 'pausar':
        case 'borrar_cliente':
        case 'restaurar_cliente':
            return tc2_op_papelera($accion, $d, $ctx);

        case 'responder_ticket':
            [, $tid, $sol] = tc2_op_solicitud($d, ['soporte']);
            $texto = $d['texto'] ?? '';
            if (!is_string($texto) || trim($texto) === '' || mb_strlen($texto) > 6000) throw tc2_mal('datos.texto', 'Escriba la respuesta (1 a 6000 caracteres).');
            $est = $d['estado'] ?? null;
            if ($est !== null && $est !== '' && !in_array($est, ['en_proceso', 'esperando', 'resuelto', 'cerrado'], true)) throw tc2_mal('datos.estado', 'Estado válido: en_proceso, esperando, resuelto o cerrado.');
            $t = fila('SELECT t.*, l.nombre AS liga FROM soporte_tickets t LEFT JOIN ligas l ON l.id = t.liga_id WHERE t.id = ?', [(int)$tid]) ?? throw new TcError('no_encontrado', 'Ese ticket ya no existe.', 404);
            if ($t['liga_id']) contexto_auditoria(['usuario_id' => null, 'usuario' => $ctx['quien'], 'rol' => 'super', 'liga_id' => (int)$t['liga_id'], 'origen' => 'tukan_central']);
            responder_ticket($t, 'soporte', trim(strip_tags($texto)), '', $est === '' ? null : $est);
            return ['mensaje' => 'Respuesta enviada al cliente (ticket #' . $t['id'] . ').', 'resultado' => [], 'liga_id' => $t['liga_id'] ? (int)$t['liga_id'] : null, 'solicitud_id' => 'soporte:' . $t['id'],
                    'registro_id' => (int)$t['id'], 'bitacora' => 'Respondió el ticket #' . $t['id'] . ($est ? ' (queda ' . $est . ')' : '')];
    }
    throw new TcError('no_permitido', 'Esa operación no está disponible.', 403, 'accion', ['motivo' => 'no_disponible']);
}

/**
 * (Anexo A) pausar · borrar_cliente · restaurar_cliente.
 *  - pausar: datos.tipo (falta_pago, pedido_cliente, revision, abuso, otro) y datos.motivo (3 a 300). Reutiliza la suspensión real.
 *  - borrar_cliente: datos.confirmar = nombre exacto del cliente (o su id) y datos.motivo (3 a 300). Copia + papelera de 30 días.
 *    Si ya estaba en la papelera: sin_cambios, con la misma copia y fecha.
 *  - restaurar_cliente: solo de la papelera (si no está en la papelera: sin_cambios; si ya se borró de verdad: 404).
 */
function tc2_op_papelera(string $accion, array $d, array $ctx): array
{
    $l = tc2_op_liga($ctx, $accion !== 'pausar');
    $n = (string)$l['nombre'];
    $lid = (int)$l['id'];
    if ($accion === 'pausar') {
        $tipo = $d['tipo'] ?? null;
        if (!is_string($tipo) || !isset(tipos_pausa()[$tipo])) throw tc2_mal('datos.tipo', 'Elija el tipo de pausa: ' . implode(', ', array_keys(tipos_pausa())) . '.');
        $motivo = tc2_texto($d, 'motivo', 3, 300, 'datos.motivo', 'Escriba el motivo (3 a 300 caracteres).');
        $antes = (string)($l['suspendida_tipo'] ?? '');
        $hecho = pausar_cuenta($l, $tipo, $motivo);
        $msg = !$hecho ? $n . ' ya estaba pausada con ese tipo y motivo.'
            : ($antes !== '' || liga_suspendida($l) ? 'Pausa de ' . $n . ' actualizada (' . tipos_pausa()[$tipo] . ').' : $n . ' quedó pausada (' . tipos_pausa()[$tipo] . '): sus usuarios ven «Tu cuenta está pausada» y su página pública está temporalmente no disponible. Sus datos se guardan.');
        return ['mensaje' => $msg, 'sin_cambios' => !$hecho, 'resultado' => ['estado' => 'suspendido', 'tipo' => $tipo, 'motivo' => $motivo], 'liga_id' => $lid,
                'bitacora' => ($hecho ? 'Pausó la cuenta (' . tipos_pausa()[$tipo] . '): ' : 'Pausar (ya estaba así): ') . $motivo];
    }
    if ($accion === 'borrar_cliente') {
        $conf = $d['confirmar'] ?? null;
        if (is_int($conf)) $conf = (string)$conf;
        if (!is_string($conf) || ($conf = trim($conf)) === '' || ($conf !== trim($n) && $conf !== (string)$lid)) {
            throw tc2_mal('datos.confirmar', 'Para confirmar, escriba el nombre exacto del cliente: ' . $n . ' (o su id: ' . $lid . ').');
        }
        $motivo = tc2_texto($d, 'motivo', 3, 300, 'datos.motivo', 'Escriba el motivo del borrado (3 a 300 caracteres).');
        $x = borrar_cuenta($l, $motivo, $ctx['por']);
        $arriba = ['copia' => $x['copia'], 'borrar_definitivo_el' => $x['borrar_definitivo_el']];
        if ($x['ya_estaba']) {
            return ['mensaje' => $n . ' ya estaba en la papelera (se borra de verdad el ' . $x['borrar_definitivo_el'] . ').', 'sin_cambios' => true,
                    'resultado' => $arriba + ['estado' => 'papelera'], 'arriba' => $arriba, 'liga_id' => $lid, 'bitacora' => 'Borrar (ya estaba en la papelera)'];
        }
        return ['mensaje' => $n . ' pasó a la papelera: nadie puede entrar y su página pública ya no existe. Se guardó una copia completa (' . $x['filas'] . ' filas). Puede restaurarla hasta el '
                    . date('Y-m-d', strtotime($x['borrar_definitivo_el'] . ' -1 day')) . '; el ' . $x['borrar_definitivo_el'] . ' se borra de verdad.',
                'resultado' => $arriba + ['estado' => 'papelera', 'filas' => $x['filas'], 'tablas' => (object)$x['conteo']], 'arriba' => $arriba, 'liga_id' => $lid,
                'bitacora' => 'Borró la cuenta (a la papelera hasta el ' . $x['borrar_definitivo_el'] . '; copia ' . $x['copia'] . '). Motivo: ' . $motivo];
    }
    // restaurar_cliente
    if (!liga_eliminada($l)) {
        return ['mensaje' => $n . ' no está en la papelera.', 'sin_cambios' => true, 'resultado' => ['estado' => tc2_estado_de($l)['estado']], 'liga_id' => $lid, 'bitacora' => 'Restaurar (no estaba en la papelera)'];
    }
    restaurar_cuenta($l, $ctx['por']);
    $l2 = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
    return ['mensaje' => $n . ' salió de la papelera y quedó tal cual estaba.', 'resultado' => ['estado' => tc2_estado_de($l2)['estado']], 'liga_id' => $lid,
            'bitacora' => 'Restauró la cuenta desde la papelera'];
}

/** crear_demo (§6.1, §6.3). */
function tc2_op_crear_demo(array $d, array $ctx): array
{
    $acad = tc_producto()[2];
    $nombre = tc2_texto($d, 'nombre', 3, 120, 'datos.nombre', 'Escriba el nombre del negocio (3 a 120 caracteres).');
    // el programa pide al menos 3 letras en el nombre del administrador
    $contacto = tc2_texto($d, 'contacto_nombre', 3, 80, 'datos.contacto_nombre', 'Escriba el nombre del contacto (3 a 80 caracteres).');
    $email = mb_strtolower(trim((string)(is_string($d['email'] ?? null) ? $d['email'] : '')), 'UTF-8');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) throw tc2_mal('datos.email', 'Escriba un correo válido (será su usuario).');
    if (fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email])) throw tc2_mal('datos.email', 'Ese correo ya tiene una cuenta en ' . tc_producto()[1] . '. Use otro.');
    $tel = is_string($d['telefono'] ?? null) ? trim($d['telefono']) : '';
    if (mb_strlen($tel) < 9 || mb_strlen($tel) > 30 || strlen(preg_replace('/\D/', '', $tel)) < 9) throw tc2_mal('datos.telefono', 'Escriba el celular (al menos 9 números).');
    $dias = tc2_entero($d, 'dias', (int)ajuste('dias_demo', 7), 1, 90, 'datos.dias');
    $extra = $d['extra'] ?? [];
    if (!is_array($extra) || ($extra !== [] && array_is_list($extra))) throw tc2_mal('datos.extra', '«extra» debe ser un objeto.');
    // los campos propios (formularios.crear_demo) pueden venir en «extra» o sueltos en «datos» (formulario de un pedido de demo)
    $extra += array_intersect_key($d, array_flip(['tipo', 'ciudad', 'disciplina', 'deporte_escuela']));
    $tipo = $acad ? 'escuela' : ($extra['tipo'] ?? 'liga');
    if (!in_array($tipo, ['liga', 'escuela'], true)) throw tc2_mal('datos.extra.tipo', 'Tipo de cuenta: liga o escuela.');
    $ciudad = tc2_texto($extra, 'ciudad', 0, 80, 'datos.extra.ciudad');
    $disc = $extra['disciplina'] ?? $extra['deporte_escuela'] ?? null;
    if ($disc !== null && $disc !== '' && (!is_string($disc) || !array_key_exists($disc, deportes_escuela()))) throw tc2_mal('datos.extra.disciplina', 'Disciplina no válida.');
    $sol = null;
    $sid = $d['solicitud_id'] ?? null;
    if ($sid !== null && $sid !== '') {
        if (!is_string($sid) || !preg_match('/^demo_pedida:(\d+)$/', $sid, $m)) throw tc2_mal('datos.solicitud_id', 'La solicitud debe ser «demo_pedida:<número>».');
        $sol = fila('SELECT * FROM solicitudes_demo WHERE id = ?', [(int)$m[1]]) ?? throw new TcError('no_encontrado', 'Esa solicitud de demo ya no existe.', 404);
        if ($sol['estado'] === 'creada') throw new TcError('conflicto', 'Esa solicitud de demo ya tiene su cuenta creada.', 409, null, ['motivo' => 'ya_atendida', 'estado' => 'creada']);
    }
    $plan = null;
    $cod = $d['plan'] ?? null;
    if ($cod !== null && $cod !== '') {
        $cod = tc2_id_valor($cod);
        $pl = $cod !== null && ctype_digit($cod) ? plan_para_cuenta(['tipo' => $tipo], (int)$cod) : null;
        if (!$pl) throw tc2_mal('datos.plan', 'Ese plan no existe o no es para este tipo de cuenta.');
        $plan = (int)$pl['id'];
    } elseif ($sol && $sol['plan_id'] && plan_para_cuenta(['tipo' => $tipo], (int)$sol['plan_id'])) {
        $plan = (int)$sol['plan_id'];
    }
    $deportes = null;
    if ($sol && ($sol['deportes'] ?? '') !== '') {
        $ds = array_values(array_intersect(array_keys(modulos_deporte_base()), explode(',', (string)$sol['deportes'])));
        if ($ds) $deportes = $ds;
    }
    $clave = clave_al_azar(10);
    $enviar = !array_key_exists('enviar_correo', $d) || $d['enviar_correo'] === true || $d['enviar_correo'] === 1 || $d['enviar_correo'] === '1';
    $datos = [
        'nombre' => $nombre, 'ciudad' => $ciudad, 'admin_nombre' => $contacto, 'admin_email' => $email, 'admin_clave' => $clave, 'telefono' => $tel,
        'plan_id' => $plan, 'vence' => date('Y-m-d', strtotime('+' . $dias . ' days')), 'es_prueba' => 1, 'crear_campeonato' => true,
        'tipo' => $tipo, 'modalidad' => 'F7', 'origen' => $sol ? 'demo_pedida' : 'central', 'solicitud_demo' => $sol ? (int)$sol['id'] : null, 'enviar_correo' => $enviar,
    ];
    if ($disc) $datos['deporte_escuela'] = $disc;
    if ($deportes !== null) {
        if ($tipo === 'escuela' && $disc && ($kd = function_exists('modulo_de_deporte_escuela') ? modulo_de_deporte_escuela((string)$disc) : '') !== '' && !in_array($kd, $deportes, true)) $deportes[] = $kd;
        $datos['deportes'] = $deportes;
    }
    $r = crear_demo_cuenta($datos, $ctx['por']);
    $l = $r['liga'];
    $wa = tc2_wa($tel);
    return [
        'mensaje' => 'Demo creada: ' . $l['nombre'] . ', hasta el ' . $l['vence'] . '.',
        'resultado' => [
            'cliente_id' => (string)$l['id'], 'prueba_hasta' => (string)$l['vence'],
            'acceso' => ['url_panel' => url_base() . 'admin.php', 'usuario' => $email, 'clave' => $clave],
            'enlace_publico' => url_liga($l), 'mensaje_whatsapp' => $r['mensaje_whatsapp'],
            'whatsapp_url' => $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($r['mensaje_whatsapp']) : null,
            'correo_enviado' => (bool)$r['correo_enviado'],
        ],
        'secretos' => [$clave], 'liga_id' => (int)$l['id'], 'solicitud_id' => $sol ? 'demo_pedida:' . $sol['id'] : null,
        'bitacora' => 'Creó la demo ' . $l['nombre'] . ' (' . $email . ') hasta el ' . $l['vence'] . ($sol ? ', del pedido de demo #' . $sol['id'] : ''),
    ];
}

/** atender_solicitud (§6.14): guarda el resultado en la tabla propia (demo, soporte, LOPDP) o en tukan_central_atenciones. */
function tc2_op_atender(array $d, array $ctx): array
{
    $res = $d['resultado'] ?? null;
    if (!in_array($res, ['en_proceso', 'atendida', 'descartada'], true)) throw tc2_mal('datos.resultado', 'Resultado: en_proceso, atendida o descartada.');
    $nota = tc2_texto($d, 'nota', 0, 300, 'datos.nota');
    [$tipo, $ref, $sol] = tc2_op_solicitud($d, array_diff(TC_TIPOS_SOLICITUD, ['comprobante']));
    $sid = $sol['id'];
    $lid = $sol['cliente_id'] !== null ? (int)$sol['cliente_id'] : null;
    if ($lid) contexto_auditoria(['usuario_id' => null, 'usuario' => $ctx['quien'], 'rol' => 'super', 'liga_id' => $lid, 'origen' => 'tukan_central']);
    $nombres = ['en_proceso' => 'en proceso', 'atendida' => 'atendida', 'descartada' => 'descartada'];
    $sin = fn(string $txt) => ['mensaje' => $txt, 'sin_cambios' => true, 'resultado' => [], 'liga_id' => $lid, 'solicitud_id' => $sid, 'bitacora' => 'Atender ' . $sid . ': ' . $txt];
    switch ($tipo) {
        case 'demo_pedida':
            $s = fila('SELECT * FROM solicitudes_demo WHERE id = ?', [(int)$ref]);
            $nuevo = $res === 'descartada' ? 'descartada' : 'contactada';
            if ($s['estado'] === 'creada') {
                if ($res === 'atendida') return $sin('Esa solicitud ya tiene su demo creada.');
                throw new TcError('conflicto', 'Esa solicitud ya tiene su demo creada.', 409, null, ['motivo' => 'ya_atendida', 'estado' => 'creada']);
            }
            if ($s['estado'] === $nuevo && ($nota === '' || $nota === $s['nota'])) return $sin('La solicitud ya estaba así.');
            q('UPDATE solicitudes_demo SET estado = ?, nota = ?, atendido_por = ?, atendido_en = ? WHERE id = ?', [$nuevo, $nota !== '' ? $nota : $s['nota'], $ctx['por'], date('Y-m-d H:i'), $s['id']]);
            $txt = 'Pedido de demo de ' . $s['organizacion'] . ': ' . (estados_solicitud_demo()[$nuevo] ?? $nuevo) . '.';
            break;
        case 'soporte':
            $t = fila('SELECT * FROM soporte_tickets WHERE id = ?', [(int)$ref]);
            $nuevo = ['en_proceso' => 'en_proceso', 'atendida' => 'resuelto', 'descartada' => 'cerrado'][$res];
            if ($t['estado'] === $nuevo && (int)$t['sin_leer_super'] === 0) return $sin('El ticket ya estaba así.');
            q('UPDATE soporte_tickets SET estado = ?, sin_leer_super = 0, actualizado = ?, resuelto_en = ? WHERE id = ?',
                [$nuevo, date('Y-m-d H:i:s'), in_array($nuevo, ['resuelto', 'cerrado'], true) ? date('Y-m-d H:i:s') : '', $t['id']]);
            $txt = 'Ticket #' . $t['id'] . ': ' . (estados_ticket()[$nuevo] ?? $nuevo) . '.';
            break;
        case 'derechos_datos':
            $s = fila('SELECT * FROM solicitudes_derechos WHERE id = ? AND liga_id IS NULL', [(int)$ref]);
            $nuevo = ['en_proceso' => 'en_proceso', 'atendida' => 'respondida', 'descartada' => 'rechazada'][$res];
            $resp = $d['respuesta'] ?? '';
            if (!is_string($resp)) throw tc2_mal('datos.respuesta', 'Escriba la respuesta.');
            if ($nuevo !== 'en_proceso' && mb_strlen(trim($resp)) < 5) throw tc2_mal('datos.respuesta', 'Escriba la respuesta que se le dio a la persona (queda como prueba).');
            if ($s['estado'] === $nuevo && trim($resp) === $s['respuesta']) return $sin('La solicitud ya estaba así.');
            responder_solicitud_derecho($s, $nuevo, $resp !== '' ? $resp : (string)$s['respuesta'], $ctx['por']);
            $txt = 'Solicitud de protección de datos N.º ' . $s['id'] . ': ' . (estados_derecho()[$nuevo] ?? $nuevo) . '.';
            break;
        default:
            if ($tipo === 'dominio_gratis' && ($d['dominio'] ?? '') !== '' && ($d['dominio'] ?? null) !== null) {
                $dom = is_string($d['dominio']) ? limpiar_dominio($d['dominio']) : '';
                if ($dom === '') throw tc2_mal('datos.dominio', 'El dominio no es válido. Escríbalo así: suliga.com');
                $nota = mb_substr('Dominio: ' . $dom . ($nota !== '' ? ' · ' . $nota : ''), 0, 300);
            }
            $ya = fila('SELECT * FROM tukan_central_atenciones WHERE solicitud_id = ?', [$sid]);
            if ($ya && $ya['estado'] === $res && ($nota === '' || $nota === (string)$ya['nota'])) return $sin('La solicitud ya estaba así.');
            q('INSERT OR REPLACE INTO tukan_central_atenciones (solicitud_id, tipo, estado, nota, email, nombre, momento) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$sid, $tipo, $res, $nota !== '' ? $nota : ($ya['nota'] ?? ''), $ctx['email'], $ctx['nombre'], date('Y-m-d H:i:s')]);
            $txt = $sol['titulo'] . ': ' . $nombres[$res] . '.';
    }
    return ['mensaje' => 'Listo. ' . $txt, 'resultado' => ['estado' => $res], 'liga_id' => $lid, 'solicitud_id' => $sid, 'bitacora' => 'Atendió ' . $sid . ' (' . $nombres[$res] . ')' . ($nota !== '' ? ': ' . $nota : '')];
}
