<?php
/*
 * (10 nov 2026) DEMOS AUTOMÁTICAS DE 3 DÍAS — la parte del programa (contrato fijo con el Panel Tukán Central).
 * Pedido de Dimitry: el interesado llena TODOS sus datos, la clave le llega a su correo (así sabemos que es verdadero) junto con
 * el manual y la guía de primeros pasos, y dentro de la demo tiene dónde bajarlos. El Panel crea la demo solo; aquí:
 *   1) El formulario «Solicitar demo» pide 6 datos obligatorios: nombre, nombre comercial, celular (un solo teléfono), dirección, ciudad y
 *      correo (demo_validar_pedido). El Panel los ve en la solicitud «demo_pedida» con esas claves exactas (tukan_central.php).
 *   2) Apenas se guarda el pedido se avisa al Panel al instante (demo_avisar_panel): POST {Panel}/demo_aviso.php firmado con el
 *      token v2 tipo «demo». Máximo 5 segundos; si el Panel no contesta no pasa nada (lo verá en su revisión de cada 15 minutos).
 *   3) /manual.pdf y /primeros_pasos.pdf públicos (demo_servir_pdf), con ?tipo=escuela|federacion para los otros manuales.
 *   4) Tarjeta «Su demo dura hasta el dd/mm» en el inicio mientras la cuenta es demo, y los dos botones en Ayuda (para todos).
 *   5) «3 días en todo»: TODA demo dura 3 días (la del formulario, la que crea el Panel y la que el súper crea a mano). Los ajustes
 *      dias_demo (7 → 3) y dias_prueba (30 o 7 → 3) cambian una sola vez (esquema_demos_auto), sin pisar otro valor que Tukán haya puesto.
 *      Alargar la demo sigue igual.
 */

const DEMO_DIAS_AUTO = 3;
const DEMO_CAMPOS = ['nombre', 'nombre_comercial', 'celular', 'direccion', 'ciudad', 'email']; // (10 nov, 19:45) un solo teléfono: el celular

/**
 * Columnas nuevas de solicitudes_demo (direccion, aviso_panel) y paso único de dias_demo 7 → 3.
 * En la tabla, «telefono» es el celular / WhatsApp (como siempre). No se pide teléfono fijo: con un teléfono es suficiente (Dimitry).
 */
function esquema_demos_auto(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(solicitudes_demo)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!$cols) return;
    foreach (['direccion' => "TEXT NOT NULL DEFAULT ''", 'aviso_panel' => "TEXT NOT NULL DEFAULT ''"] as $c => $def) {
        if (in_array($c, $cols, true)) continue;
        try {
            $pdo->exec("ALTER TABLE solicitudes_demo ADD COLUMN $c $def");
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'duplicate column')) throw $e; // otro pedido la agregó al mismo tiempo
        }
    }
    // Una sola vez: la demo pasa a 3 días. Solo si sigue el valor de siempre (7) o no hay valor; si Tukán puso otro, se respeta.
    if ($pdo->query("SELECT 1 FROM ajustes WHERE clave = 'demos_auto_3dias'")->fetchColumn() === false) {
        $pdo->exec("UPDATE ajustes SET valor = '" . DEMO_DIAS_AUTO . "' WHERE clave = 'dias_demo' AND valor IN ('7', '')");
        // «3 días en todo» (Dimitry, 10 nov 2026): también dias_prueba (antes 30 en TuVocalía y 7 en TuAcademia), si sigue el de siempre
        $pdo->exec("UPDATE ajustes SET valor = '" . DEMO_DIAS_AUTO . "' WHERE clave = 'dias_prueba' AND valor IN ('30', '7', '')");
        $pdo->exec("INSERT OR IGNORE INTO ajustes (clave, valor) VALUES ('dias_prueba', '" . DEMO_DIAS_AUTO . "')");
        $pdo->exec("INSERT OR IGNORE INTO ajustes (clave, valor) VALUES ('dias_demo', '" . DEMO_DIAS_AUTO . "')");
        $pdo->exec("INSERT OR IGNORE INTO ajustes (clave, valor) VALUES ('demos_auto_3dias', '" . date('Y-m-d H:i') . "')");
    }
}

/** Días de TODA demo: formulario, Panel y la que crea el súper a mano (ajuste dias_demo; 3 por defecto). */
function dias_demo(): int
{
    $d = (int)ajuste('dias_demo', DEMO_DIAS_AUTO);
    return $d > 0 ? $d : DEMO_DIAS_AUTO;
}

/** Deja solo números y un + al inicio (quita espacios, guiones, puntos y paréntesis). */
function demo_limpiar_telefono(string $t): string
{
    $t = trim($t);
    $mas = str_starts_with($t, '+');
    $d = preg_replace('/[\s\-\.\(\)\/]/u', '', $t);
    if ($mas) $d = '+' . ltrim(substr($d, 1), '+');
    return $d;
}

/**
 * Revisa los datos del formulario. Devuelve [datos limpios, errores por campo]. Claves del formulario (POST):
 * contacto (nombre), organizacion (nombre comercial), celular, direccion, ciudad, email.
 */
function demo_validar_pedido(array $post): array
{
    $t = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($post[$k] ?? ''))), 0, $max);
    $d = [
        'contacto' => $t('contacto', 80), 'organizacion' => $t('organizacion', 120),
        'celular' => demo_limpiar_telefono((string)($post['celular'] ?? '')), 'direccion' => $t('direccion', 160), 'ciudad' => $t('ciudad', 80),
        'email' => mb_strtolower(preg_replace('/\s+/u', '', (string)($post['email'] ?? ''))),
    ];
    $err = [];
    if (mb_strlen($d['contacto']) < 3) $err['contacto'] = 'Escriba su nombre y apellido.';
    if (mb_strlen($d['organizacion']) < 3) $err['organizacion'] = function_exists('es_academia') && es_academia() ? 'Escriba el nombre de su academia.' : 'Escriba el nombre de su liga o escuela.';
    if ($d['celular'] === '') $err['celular'] = 'Escriba su celular.';
    elseif (!preg_match('/^\+?\d{9,15}$/', $d['celular'])) $err['celular'] = 'El celular debe tener solo números (de 9 a 15), puede empezar con +. Ej: 0991234567.';
    elseif (str_starts_with($d['celular'], '0') && !preg_match('/^09\d{8}$/', $d['celular'])) $err['celular'] = 'Un celular de Ecuador tiene 10 números y empieza con 09. Ej: 0991234567.';
    elseif (preg_match('/^\+?593/', $d['celular']) && !preg_match('/^\+?5939\d{8}$/', $d['celular'])) $err['celular'] = 'Con el código del país, el celular de Ecuador es +5939 y 8 números. Ej: +593991234567.';
    if (mb_strlen($d['direccion']) < 5) $err['direccion'] = 'Escriba la dirección de su ' . (function_exists('es_academia') && es_academia() ? 'academia' : 'liga o escuela') . '.';
    if (mb_strlen($d['ciudad']) < 3) $err['ciudad'] = 'Escriba la ciudad.';
    if ($d['email'] === '') $err['email'] = 'Escriba su correo: ahí le llega la clave de su demo.';
    elseif (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 120) $err['email'] = 'El correo no es válido. Revíselo: ahí le llega la clave de su demo.';
    return [$d, $err];
}

/** Los datos del contrato (claves exactas) a partir de una fila de solicitudes_demo. «telefono» va con el mismo valor del celular (compatibilidad). */
function demo_campos_contrato(array $s): array
{
    return [
        'nombre' => trim((string)$s['contacto']), 'nombre_comercial' => trim((string)$s['organizacion']), 'telefono' => (string)$s['telefono'],
        'celular' => (string)$s['telefono'], 'direccion' => trim((string)($s['direccion'] ?? '')), 'ciudad' => trim((string)$s['ciudad']), 'email' => (string)$s['email'],
    ];
}

/**
 * Aviso al instante al Panel: POST {TUKAN_CENTRAL_URL}/demo_aviso.php con X-Tukan-Token (token v2 tipo «demo», firmado con el
 * secreto del Panel, bh = sha256 del cuerpo) y cuerpo {"accion":"demo_pedida","id":"demo_pedida:<n>"}. Máximo 5 segundos.
 * Nunca lanza error: devuelve el código HTTP (0 = sin conectar al Panel o sin respuesta) y lo anota en solicitudes_demo.aviso_panel.
 */
function demo_avisar_panel(int $sid): int
{
    try {
        if (!function_exists('tc_config') || !tc_activo() || !function_exists('curl_init')) return 0;
        $cfg = tc_config();
        $cuerpo = json_encode(['accion' => 'demo_pedida', 'id' => 'demo_pedida:' . $sid], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ahora = time();
        $token = tc_firmar(['v' => 2, 'tipo' => 'demo', 'inst' => $cfg['id'], 'email' => 'sistema@tukanec.com', 'nombre' => 'Pedido de demo', 'rol' => 'dueno',
            'iat' => $ahora, 'exp' => $ahora + 60, 'nonce' => bin2hex(random_bytes(16)), 'bh' => tc_b64(hash('sha256', (string)$cuerpo, true))], $cfg['secreto']);
        $ch = curl_init($cfg['url'] . '/demo_aviso.php');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
            CURLOPT_NOSIGNAL => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Tukan-Token: ' . $token]]);
        $r = curl_exec($ch);
        $http = $r === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        q('UPDATE solicitudes_demo SET aviso_panel = ? WHERE id = ?', [($http ?: 'sin respuesta') . ' · ' . date('Y-m-d H:i:s'), $sid]);
        return $http;
    } catch (Throwable $e) {
        error_log('Aviso de demo al Panel: ' . $e->getMessage());
        return 0;
    }
}

/* ---------- Manual y primeros pasos en PDF (públicos) ---------- */

/** Tipo de manual pedido: ligas (el general) | escuelas | federaciones. Acepta escuela, escuelas, federacion, federaciones, liga, ligas. */
function demo_tipo_pdf(string $tipo): string
{
    $tipo = strtolower(trim($tipo));
    if (in_array($tipo, ['escuela', 'escuelas', 'academia', 'academias'], true)) return 'escuelas';
    if (in_array($tipo, ['federacion', 'federaciones'], true)) return 'federaciones';
    return 'ligas';
}

/** Tipo de manual de una cuenta (para los botones del panel). */
function demo_tipo_de_cuenta(?array $l): string
{
    if ($l && function_exists('es_escuela') && es_escuela($l)) return 'escuelas';
    if ($l && function_exists('es_federacion') && es_federacion($l)) return 'federaciones';
    return 'ligas';
}

/** Archivo y nombre de descarga de cada PDF: [ruta, nombre] o null si no existe. $cual = manual | primeros_pasos. */
function demo_pdf(string $cual, string $tipo = ''): ?array
{
    if (function_exists('es_academia') && es_academia()) { // TuAcademia: un solo manual y una sola guía (docs/), para cualquier ?tipo
        $f = $cual === 'manual' ? RAIZ . '/docs/Manual_TuAcademia.pdf' : RAIZ . '/docs/TuAcademia_Primeros_Pasos.pdf';
        return is_file($f) ? [$f, $cual === 'manual' ? 'Manual_TuAcademia.pdf' : 'Primeros_Pasos_TuAcademia.pdf'] : null;
    }
    $t = demo_tipo_pdf($tipo);
    $T = ucfirst($t);
    $f = $cual === 'manual' ? RAIZ . '/public/assets/manual/Manual-TuVocalia-' . $T . '.pdf' : RAIZ . '/docs/TuVocalia_' . $T . '_Primeros_Pasos.pdf';
    $nombre = ($cual === 'manual' ? 'Manual_TuVocalia_' : 'Primeros_Pasos_TuVocalia_') . $T . '.pdf';
    return is_file($f) ? [$f, $nombre] : null;
}

/** Dirección de cada PDF desde el panel o la web. */
function demo_url_pdf(string $cual, string $tipo = 'ligas'): string
{
    return ($cual === 'manual' ? 'manual.pdf' : 'primeros_pasos.pdf') . ($tipo !== 'ligas' ? '?tipo=' . rawurlencode($tipo) : '');
}

/** Responde /manual.pdf o /primeros_pasos.pdf (sin sesión): 200 application/pdf con nombre claro, o 404 si no hay ese PDF. */
function demo_servir_pdf(string $cual, string $tipo): never
{
    $pdf = demo_pdf($cual, $tipo);
    while (ob_get_level() > 0) ob_end_clean();
    header_remove('Set-Cookie');
    header('X-Content-Type-Options: nosniff');
    if (!$pdf) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No hay ese documento.';
        exit;
    }
    [$f, $nombre] = $pdf;
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: public, max-age=3600');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($f);
    exit;
}

/* ---------- Dentro del panel ---------- */

/** Los dos botones de descarga (manual y primeros pasos) del tipo de la cuenta. */
function html_botones_manual_pdf(?array $l): string
{
    $t = demo_tipo_de_cuenta($l);
    $h = '';
    if (demo_pdf('manual', $t)) $h .= '<a class="btn btn-chico" id="btn-manual-pdf" href="' . e(demo_url_pdf('manual', $t)) . '" download>📘 Descargar el manual</a> ';
    if (demo_pdf('primeros_pasos', $t)) $h .= '<a class="btn btn-sec btn-chico" id="btn-primeros-pasos-pdf" href="' . e(demo_url_pdf('primeros_pasos', $t)) . '" download>🚀 Guía de primeros pasos</a>';
    return $h !== '' ? '<div class="acciones" style="margin-top:8px;flex-wrap:wrap;gap:8px">' . $h . '</div>' : '';
}

/** ¿La cuenta está en su demo? (es_prueba = 1; no las demostraciones del sistema). */
function cuenta_en_demo(?array $l): bool
{
    return $l !== null && !empty($l['es_prueba']) && empty($l['es_demo']);
}

/** Tarjeta del inicio mientras la cuenta es demo: «Su demo dura hasta el dd/mm. Descargue el manual y la guía de primeros pasos».
 *  (10 nov 2026, identidad gráfica común de Tukán) Es la tira de arriba del dashboard: días que quedan y «Ver Mi plan». */
function html_tarjeta_demo(?array $l): string
{
    if (!cuenta_en_demo($l)) return '';
    $v = (string)($l['vence'] ?? '');
    $hasta = preg_match('/^\d{4}-(\d{2})-(\d{2})/', $v, $m) ? $m[2] . '/' . $m[1] : '';
    $s = estado_suscripcion($l);
    $dias = $s['estado'] === 'prueba' ? ((int)$s['dias'] === 0 ? 'vence hoy' : 'le quedan ' . (int)$s['dias'] . ' día' . ((int)$s['dias'] === 1 ? '' : 's')) : 'terminó';
    $plan = function_exists('en_app_android') && en_app_android() || !puede('config') ? '' // (28 oct 2026) en la app de Android no se contrata
        : '<a class="tk-boton chico" href="admin.php?p=mi_plan#contratar">⭐ Ver Mi plan</a>';
    return '<div class="tk-tira tarjeta-demo" id="tarjeta-demo"><div>'
        . '<span>⏳ <b>Demo:</b> ' . e($dias) . '.</span> '
        . '<b>🎁 ' . ($hasta !== '' ? 'Su demo dura hasta el ' . e($hasta) . '.' : 'Está en su demo.') . ' Descargue el manual y la guía de primeros pasos</b>'
        . html_botones_manual_pdf($l) . '</div>' . $plan . '</div>';
}
