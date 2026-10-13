<?php
/*
 * (v38) COMPRAS DE GOOGLE PLAY (la llama assets/play.js desde la app de Google Play).
 *  accion=verificar  producto + token  → verifica la compra con Google, la reconoce y activa el plan o el deporte
 *  accion=restaurar  compras (JSON [{itemId, purchaseToken}]) → vuelve a revisar las compras que Google dice que tiene este celular
 * Solo el administrador de la cuenta (permiso de configuración), con la sesión iniciada.
 */
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$responder = function (array $r, int $cod = 200): never {
    http_response_code($cod);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!es_post()) $responder(['ok' => false, 'error' => 'Solo POST'], 405);
$t = $_POST['csrf'] ?? '';
if (!is_string($t) || !hash_equals(csrf_token(), $t)) $responder(['ok' => false, 'error' => 'La página venció. Recárguela e intente de nuevo.'], 400);
$u = usuario();
if (!$u) $responder(['ok' => false, 'error' => 'Inicie sesión otra vez.'], 401);
$liga = liga_trabajo();
if (!$liga || !puede('config', true) || es_usuario_demo($u) || !empty($liga['es_demo'])) $responder(['ok' => false, 'error' => 'Solo el administrador de la cuenta puede pagar el plan.'], 403);
contexto_auditoria(['usuario' => $u['nombre'], 'rol' => $u['rol'], 'origen' => 'google_play']);

try {
    $accion = post('accion');
    if ($accion === 'verificar') {
        $r = play_registrar_compra($liga, $u, post('producto'), post('token'));
        $responder(['ok' => true, 'mensaje' => $r['mensaje']]);
    }
    if ($accion === 'restaurar') {
        $lista = json_decode(post('compras'), true);
        $n = 0;
        $msgs = [];
        foreach (is_array($lista) ? array_slice($lista, 0, 20) : [] as $c) {
            $prod = (string)($c['itemId'] ?? '');
            $tok = (string)($c['purchaseToken'] ?? '');
            if ($prod === '' || $tok === '') continue;
            $ya = fila('SELECT liga_id FROM play_compras WHERE token = ?', [$tok]);
            if ($ya && (int)$ya['liga_id'] !== (int)$liga['id']) continue; // es de otra cuenta (otra persona usó este celular)
            try {
                play_registrar_compra($liga, $u, $prod, $tok);
                $n++;
            } catch (Throwable $ex) {
                $msgs[] = $prod . ': ' . $ex->getMessage();
            }
        }
        $responder(['ok' => true, 'revisadas' => $n, 'errores' => $msgs]);
    }
    $responder(['ok' => false, 'error' => 'Acción desconocida.'], 400);
} catch (Throwable $ex) {
    error_log('play: ' . $ex->getMessage());
    $responder(['ok' => false, 'error' => $ex->getMessage()]);
}
