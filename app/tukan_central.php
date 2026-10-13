<?php
/*
 * TUKÁN CENTRAL — conector con el Panel Tukán Central (panel.tukanec.com), protocolo v1.
 * El mismo archivo sirve para TuVocalía y TuAcademia (se distinguen por la constante PRODUCTO).
 *
 * - Entrada pública: public/tukan_central.php  (?accion=sso&t=...  y  ?accion=estado&t=...)
 * - Configuración, con los nombres del protocolo (el primero que tenga valor gana):
 *     1) variables de entorno  TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET, TUKAN_CENTRAL_URL, TUKAN_SOLO_CENTRAL
 *     2) data/tukan_central.env  (líneas CLAVE=valor; lo escribe vps/conectar_central.sh; data/ no va a GitHub)
 *     3) app/config.php          (return ['TUKAN_CENTRAL_ID' => '...', ...])
 * - Sin ID o sin secreto (o secreto de menos de 32 caracteres) el conector está APAGADO: tukan_central.php da 404
 *   y todo sigue como antes (TUKAN_SOLO_CENTRAL tampoco hace nada).
 * - La tabla de nonces se crea sola la primera vez (no usa PRAGMA user_version para no chocar con otras ramas).
 */

const TC_PROTOCOLO = 1;
const TC_LIMITE_MINUTO = 30;

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

/** Tabla de nonces de un solo uso (se crea sola). */
function tc_preparar(): void
{
    static $listo = false;
    if ($listo) return;
    $listo = true;
    db()->exec('CREATE TABLE IF NOT EXISTS tukan_nonces (nonce TEXT PRIMARY KEY, exp INTEGER NOT NULL, creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
}

/**
 * Valida el token en el orden del protocolo §3. Devuelve el contenido o lanza RuntimeException con el motivo.
 * (El límite de 30 pedidos por minuto por IP se revisa antes, en tc_atender(), para que cuente todos los pedidos.)
 */
function tc_validar(string $token, string $accion): array
{
    $cfg = tc_config();
    // 1) dos partes y firma correcta
    $partes = explode('.', $token);
    if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') throw new RuntimeException('Token mal formado.');
    $firma = tc_b64(hash_hmac('sha256', $partes[0], $cfg['secreto'], true));
    if (!hash_equals($firma, $partes[1])) throw new RuntimeException('Firma incorrecta.');
    $json = tc_unb64($partes[0]);
    $p = $json === false ? null : json_decode($json, true);
    if (!is_array($p)) throw new RuntimeException('Token ilegible.');
    // 2) versión, tipo e instancia
    if (($p['v'] ?? null) !== 1) throw new RuntimeException('Versión de protocolo no soportada.');
    if (($p['tipo'] ?? '') !== $accion) throw new RuntimeException('El token no es para esta acción.');
    if (!is_string($p['inst'] ?? null) || !hash_equals($cfg['id'], $p['inst'])) throw new RuntimeException('El token es de otra instancia.');
    // 3) tiempo (30 s de tolerancia de reloj, máximo 60 s de vida)
    $iat = $p['iat'] ?? null;
    $exp = $p['exp'] ?? null;
    if (!is_int($iat) || !is_int($exp)) throw new RuntimeException('Token sin fechas válidas.');
    $ahora = time();
    if ($ahora > $exp + 30) throw new RuntimeException('El token venció. Vuelva a intentarlo desde el panel.');
    if ($iat > $ahora + 30) throw new RuntimeException('El token tiene fecha futura (revise la hora del servidor).');
    if ($exp < $iat || $exp - $iat > 60) throw new RuntimeException('El token dura más de lo permitido.');
    // 4) nonce de un solo uso
    $nonce = $p['nonce'] ?? null;
    if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/i', $nonce)) throw new RuntimeException('Token sin nonce válido.');
    tc_preparar();
    $pdo = db();
    $pdo->prepare('DELETE FROM tukan_nonces WHERE exp < ?')->execute([$ahora - 120]);
    $st = $pdo->prepare('INSERT OR IGNORE INTO tukan_nonces (nonce, exp) VALUES (?, ?)');
    $st->execute([strtolower($nonce), $exp]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Este token ya se usó. Vuelva a intentarlo desde el panel.');
    // 5) correo válido
    if (!is_string($p['email'] ?? null) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Correo inválido en el token.');
    return $p;
}

/* ---------- Respuestas ---------- */

function tc_json(int $codigo, array $datos): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tc_pagina_error(int $codigo, string $mensaje): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($codigo);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
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

/** Atiende public/tukan_central.php. */
function tc_atender(): never
{
    if (!tc_activo()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('No encontrado');
    }
    $accion = get('accion');
    if (!in_array($accion, ['sso', 'estado'], true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('No encontrado');
    }
    $falla = fn(string $m) => $accion === 'estado' ? tc_json(401, ['ok' => false, 'error' => $m]) : tc_pagina_error(401, $m);
    // 6) máximo 30 pedidos por minuto por IP (se cuentan todos, también los rechazados)
    if (demasiados_intentos('tukan_central', TC_LIMITE_MINUTO, '-1 minute')) $falla('Demasiados pedidos desde esta conexión. Espere un minuto.');
    anotar_intento('tukan_central');
    try {
        $p = tc_validar(get('t'), $accion);
    } catch (RuntimeException $ex) {
        $falla($ex->getMessage());
    }
    if ($accion === 'estado') tc_json(200, tc_estado());
    tc_sso($p);
}

/* ---------- SSO (protocolo §4) ---------- */

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
        tc_pagina_error(403, 'El correo ' . $email . ' ya es de un usuario de un cliente (no es superadministrador). Use otro correo en el Panel Tukán o cambie ese usuario.');
    }
    if ($u && (int)$u['activo'] !== 1) {
        bitacora_evento('seguridad', 'Panel Tukán Central: se negó la entrada con ' . $email . ' porque ese superadministrador está desactivado', null, (int)$u['id']);
        tc_pagina_error(403, 'El superadministrador ' . $email . ' está desactivado en este programa.');
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
    bitacora_evento('sesion', 'Entró desde el Panel Tukán Central', null, (int)$u['id']);
    ir('admin.php');
}

/* ---------- Estado (protocolo §5) ---------- */

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
 * Cifras del programa. Cada cuenta de cliente (fila de «ligas») es un negocio. En modo academia (TuAcademia) solo cuentan
 * las ACADEMIAS (tipo = 'escuela': toda cuenta que se crea en TuAcademia es academia); una liga que quede de la base de
 * TuVocalía no es un cliente de TuAcademia y no cuenta. Las demos del sistema (es_demo = 1) no cuentan.
 * Estados según estado_suscripcion() del programa:
 *   suspendidos = cuenta desactivada por el superadministrador (activa = 0)
 *   prueba      = demo / prueba gratis vigente (es_prueba = 1)
 *   por_vencer  = pagada y vence en 7 días o menos
 *   activos     = pagada con más de 7 días, o sin vencimiento
 *   vencidos    = ya venció (en días de gracia o bloqueada en solo lectura), pagada o de prueba
 * pagos_vencidos = solo las pagadas que vencieron (desde = fecha de vencimiento); las pruebas vencidas no son pagos.
 * Ingresos = pagos de suscripción registrados (a mano, comprobante, tarjeta, Google Play) por fecha de pago.
 */
function tc_estado(): array
{
    [$clave, $nombre, $acad] = tc_producto();
    $neg = ['total' => 0, 'prueba' => 0, 'activos' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'suspendidos' => 0];
    $pruebas7 = [];
    $vencidos = [];
    // (TuAcademia) en modo academia solo las academias son clientes
    $solo = $acad ? " AND COALESCE(l.tipo, 'liga') = 'escuela'" : '';
    foreach (filas("SELECT * FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0$solo ORDER BY l.id") as $l) {
        $neg['total']++;
        if ((int)($l['activa'] ?? 1) !== 1) {
            $neg['suspendidos']++;
            continue;
        }
        $s = estado_suscripcion($l);
        switch ($s['estado']) {
            case 'prueba':
                $neg['prueba']++;
                if ($s['dias'] <= 7) $pruebas7[] = ['nombre' => $l['nombre'], 'vence' => $l['vence']];
                break;
            case 'activa':
                $s['dias'] <= 7 ? $neg['por_vencer']++ : $neg['activos']++;
                break;
            case 'gracia':
            case 'vencida':
                $neg['vencidos']++;
                if (empty($l['es_prueba'])) $vencidos[] = ['nombre' => $l['nombre'], 'desde' => $l['vence']];
                break;
            default: // sin_limite
                $neg['activos']++;
        }
    }
    usort($pruebas7, fn($a, $b) => strcmp($a['vence'], $b['vence']));
    usort($vencidos, fn($a, $b) => strcmp($a['desde'], $b['desde']));

    $ingresos = function (string $ym) use ($solo): ?float {
        try {
            return round((float)valor("SELECT COALESCE(SUM(p.monto), 0) FROM pagos_suscripcion p JOIN ligas l ON l.id = p.liga_id
                                       WHERE COALESCE(l.es_demo, 0) = 0$solo AND substr(p.fecha, 1, 7) = ?", [$ym]), 2);
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
            'Escuelas'            => tc_cuenta("SELECT COUNT(*) FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0 AND COALESCE(l.tipo, 'liga') = 'escuela'"),
            'Alumnos activos'     => tc_cuenta("SELECT COUNT(*) FROM esc_alumnos a {$r('a.liga_id')} AND a.estado = 'activo'"),
            'Usuarios'            => tc_cuenta('SELECT COUNT(*) FROM usuarios u LEFT JOIN ligas l ON l.id = u.liga_id WHERE u.activo = 1 AND COALESCE(l.es_demo, 0) = 0'),
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
