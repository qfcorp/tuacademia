<?php
/* Simulador de las APIs de Google (OAuth + Google Play Developer API) para las pruebas de la v38. */
$dir = __DIR__;
$estado_f = "$dir/sim_estado.json";
$E = is_file($estado_f) ? json_decode(file_get_contents($estado_f), true) : [];
$E += ['tokens' => [], 'subs' => [], 'log' => [], 'emitidos' => [], 'payphone' => []];
$guardar = function () use (&$E, $estado_f) { foreach (["tokens", "subs", "payphone"] as $k) $E[$k] = (object)$E[$k]; file_put_contents($estado_f, json_encode($E, JSON_PRETTY_PRINT)); foreach (["tokens", "subs", "payphone"] as $k) $E[$k] = (array)$E[$k]; };
$metodo = $_SERVER['REQUEST_METHOD'];
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cuerpo_txt = file_get_contents('php://input');
$cuerpo = json_decode($cuerpo_txt, true);
$E['log'][] = $metodo . ' ' . $_SERVER['REQUEST_URI'];
header('Content-Type: application/json');
$r = function (int $c, array $j) use ($guardar) { $guardar(); http_response_code($c); echo json_encode($j); exit; };
$b64d = fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));

if ($ruta === '/token') {
    parse_str($cuerpo_txt, $f);
    if (($f['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') $r(400, ['error' => 'unsupported_grant_type']);
    $p = explode('.', (string)($f['assertion'] ?? ''));
    if (count($p) !== 3) $r(400, ['error' => 'invalid_grant', 'error_description' => 'JWT mal formado']);
    $pub = file_get_contents("$dir/cuenta_publica.pem");
    if (openssl_verify($p[0] . '.' . $p[1], $b64d($p[2]), $pub, OPENSSL_ALGO_SHA256) !== 1) $r(400, ['error' => 'invalid_grant', 'error_description' => 'Firma inválida']);
    $pl = json_decode($b64d($p[1]), true);
    if (($pl['scope'] ?? '') !== 'https://www.googleapis.com/auth/androidpublisher' || ($pl['exp'] ?? 0) < time()) $r(400, ['error' => 'invalid_scope']);
    $tok = 'ya29.sim-' . bin2hex(random_bytes(6));
    $E['emitidos'][] = $tok;
    $r(200, ['access_token' => $tok, 'expires_in' => 3599, 'token_type' => 'Bearer']);
}
// (TuAcademia) Simulador de Payphone: POST /api/button/V2/Confirm {id, clientTxId} con Authorization: Bearer TOKEN-DE-LA-ACADEMIA.
// El estado de cada pago lo pone la prueba en $E['payphone'][id] = ['estado' => 'Approved'|'Canceled', 'monto' => centavos, 'token' => '...'].
if ($ruta === '/api/button/V2/Confirm') {
    $E += ['payphone' => []];
    $pid = (string)($cuerpo['id'] ?? '');
    $pp = $E['payphone'][$pid] ?? null;
    if (!$pp) $r(404, ['message' => 'Transacción no encontrada']);
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $pp['token']) $r(401, ['message' => 'Token inválido']);
    $E['payphone'][$pid]['confirmado'] = ($E['payphone'][$pid]['confirmado'] ?? 0) + 1;
    $r(200, ['transactionStatus' => $pp['estado'], 'amount' => $pp['monto'], 'clientTransactionId' => (string)($cuerpo['clientTxId'] ?? ''), 'transactionId' => (int)$pid, 'statusCode' => $pp['estado'] === 'Approved' ? 3 : 2]);
}
// (TuAcademia, 14-oct) Telegram simulado: POST /botTOKEN/METODO. La prueba pone en $E['telegram']:
//   'falla' => true (responde 500, como si Telegram estuviera caído), 'updates' => [...] (lo que devuelve getUpdates).
// Cada sendMessage / sendPhoto queda guardado en $E['telegram']['enviados'].
if (preg_match('#^/bot([^/]+)/(\w+)$#', $ruta, $m)) {
    $E += ['telegram' => []];
    $E['telegram'] += ['enviados' => [], 'updates' => [], 'falla' => false];
    if (!empty($E['telegram']['falla'])) $r(500, ['ok' => false, 'description' => 'Telegram simulado caído']);
    if ($m[2] === 'getUpdates') { $u = $E['telegram']['updates']; $E['telegram']['updates'] = []; $r(200, ['ok' => true, 'result' => $u]); }
    if ($m[2] === 'sendMessage' || $m[2] === 'sendPhoto') {
        $E['telegram']['enviados'][] = ['chat_id' => (string)($cuerpo['chat_id'] ?? ''), 'text' => (string)($cuerpo['text'] ?? $cuerpo['caption'] ?? ''), 'token' => $m[1]];
        $r(200, ['ok' => true, 'result' => ['message_id' => count($E['telegram']['enviados'])]]);
    }
    if ($m[2] === 'getMe') $r(200, ['ok' => true, 'result' => ['id' => 1, 'is_bot' => true, 'username' => 'TuAcademiaBot']]);
    $r(404, ['ok' => false, 'description' => 'método no simulado']);
}
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer (.+)$/', $auth, $m) || !in_array($m[1], $E['emitidos'], true)) $r(401, ['error' => ['code' => 401, 'message' => 'Request had invalid authentication credentials.']]);
if (!preg_match('#^/androidpublisher/v3/applications/([^/]+)(/.*)$#', $ruta, $m)) $r(404, ['error' => ['message' => 'ruta desconocida']]);
[$pkg, $resto] = [$m[1], $m[2]];
if ($pkg !== 'com.tukan.tuacademia') $r(404, ['error' => ['code' => 404, 'message' => 'Package not found: ' . $pkg]]);
$resto = rawurldecode($resto);

if ($metodo === 'GET' && preg_match('#^/purchases/subscriptionsv2/tokens/(.+)$#', $resto, $m)) {
    $t = $E['tokens'][$m[1]] ?? null;
    if (!$t) $r(400, ['error' => ['code' => 400, 'message' => 'Invalid Value']]);
    $j = ['kind' => 'androidpublisher#subscriptionPurchaseV2', 'regionCode' => 'EC', 'startTime' => gmdate('Y-m-d\TH:i:s.000\Z', $t['inicio'] ?? time()),
        'subscriptionState' => $t['estado'], 'latestOrderId' => $t['pedido'], 'acknowledgementState' => $t['ack'] ? 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED' : 'ACKNOWLEDGEMENT_STATE_PENDING',
        'lineItems' => [['productId' => $t['producto'], 'expiryTime' => gmdate('Y-m-d\TH:i:s.123\Z', $t['vence']), 'latestSuccessfulOrderId' => $t['pedido'],
            'autoRenewingPlan' => ['autoRenewEnabled' => $t['estado'] === 'SUBSCRIPTION_STATE_ACTIVE', 'recurringPrice' => ['currencyCode' => 'USD', 'units' => (string)floor($t['precio']), 'nanos' => (int)round(($t['precio'] - floor($t['precio'])) * 1e9)]],
            'offerDetails' => ['basePlanId' => 'base-1m']]]];
    if (!empty($t['prueba'])) $j['testPurchase'] = (object)[];
    $r(200, $j);
}
if ($metodo === 'POST' && preg_match('#^/purchases/subscriptions/([^/]+)/tokens/(.+):acknowledge$#', $resto, $m)) {
    if (!isset($E['tokens'][$m[2]]) || $E['tokens'][$m[2]]['producto'] !== $m[1]) $r(400, ['error' => ['message' => 'The purchase token does not match the product ID.']]);
    $E['tokens'][$m[2]]['ack'] = true;
    $E['tokens'][$m[2]]['acks'] = ($E['tokens'][$m[2]]['acks'] ?? 0) + 1;
    $r(200, []);
}
if ($metodo === 'GET' && $resto === '/subscriptions') $r(200, ['subscriptions' => array_values($E['subs'])]);
if ($metodo === 'POST' && $resto === '/pricing:convertRegionPrices') {
    $u = (float)($cuerpo['price']['units'] ?? 0) + (int)($cuerpo['price']['nanos'] ?? 0) / 1e9;
    if (($cuerpo['price']['currencyCode'] ?? '') !== 'USD' || $u <= 0) $r(400, ['error' => ['message' => 'precio inválido']]);
    $conv = [];
    foreach (['EC' => ['USD', 1], 'US' => ['USD', 1], 'CO' => ['COP', 4100], 'MX' => ['MXN', 18], 'BR' => ['BRL', 5.4], 'AR' => ['ARS', 980]] as $rc => [$mon, $tasa]) {
        $v = round($u * $tasa, 2);
        $conv[$rc] = ['regionCode' => $rc, 'price' => ['currencyCode' => $mon, 'units' => (string)floor($v), 'nanos' => (int)round(($v - floor($v)) * 1e9)], 'taxAmount' => ['currencyCode' => $mon]];
    }
    $r(200, ['convertedRegionPrices' => $conv, 'convertedOtherRegionsPrice' => ['usdPrice' => $cuerpo['price'], 'eurPrice' => ['currencyCode' => 'EUR', 'units' => (string)floor($u * 0.92), 'nanos' => 0]]]);
}
if ($metodo === 'POST' && $resto === '/subscriptions') {
    $id = $_GET['productId'] ?? '';
    if (($_GET['regionsVersion_version'] ?? $_GET['regionsVersion.version'] ?? '') === '') $r(400, ['error' => ['message' => 'regionsVersion.version is required']]);
    if (!preg_match('/^[a-z0-9][a-z0-9._]{0,39}$/', $id) || ($cuerpo['productId'] ?? '') !== $id) $r(400, ['error' => ['message' => 'productId inválido: ' . $id]]);
    if (isset($E['subs'][$id])) $r(409, ['error' => ['message' => 'already exists']]);
    $l = $cuerpo['listings'][0] ?? [];
    if (mb_strlen($l['title'] ?? '') < 1 || mb_strlen($l['title']) > 55) $r(400, ['error' => ['message' => 'title length']]);
    if (mb_strlen($l['description'] ?? '') > 80) $r(400, ['error' => ['message' => 'description length']]);
    foreach ($l['benefits'] ?? [] as $bf) if (mb_strlen($bf) > 40) $r(400, ['error' => ['message' => 'benefit length']]);
    if (count($l['benefits'] ?? []) > 4) $r(400, ['error' => ['message' => 'too many benefits']]);
    $bp = $cuerpo['basePlans'][0] ?? [];
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $bp['basePlanId'] ?? '')) $r(400, ['error' => ['message' => 'basePlanId inválido']]);
    if (!in_array($bp['autoRenewingBasePlanType']['billingPeriodDuration'] ?? '', ['P1W', 'P1M', 'P3M', 'P6M', 'P1Y'], true)) $r(400, ['error' => ['message' => 'billingPeriodDuration']]);
    if (count($bp['regionalConfigs'] ?? []) < 6) $r(400, ['error' => ['message' => 'Faltan regiones']]);
    $bp['state'] = 'DRAFT';
    $cuerpo['basePlans'][0] = $bp;
    $E['subs'][$id] = $cuerpo;
    $r(200, $cuerpo);
}
if ($metodo === 'GET' && preg_match('#^/subscriptions/([^/]+)$#', $resto, $m)) {
    if (!isset($E['subs'][$m[1]])) $r(404, ['error' => ['code' => 404, 'message' => 'Requested entity was not found.']]);
    $r(200, $E['subs'][$m[1]]);
}
if ($metodo === 'POST' && preg_match('#^/subscriptions/([^/]+)/basePlans/([^/]+):activate$#', $resto, $m)) {
    if (!isset($E['subs'][$m[1]]) || $E['subs'][$m[1]]['basePlans'][0]['basePlanId'] !== $m[2]) $r(404, ['error' => ['message' => 'no existe']]);
    $E['subs'][$m[1]]['basePlans'][0]['state'] = 'ACTIVE';
    $r(200, $E['subs'][$m[1]]);
}
$r(404, ['error' => ['message' => 'no simulado: ' . $metodo . ' ' . $resto]]);
