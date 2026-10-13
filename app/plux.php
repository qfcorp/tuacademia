<?php
/*
 * (v13) CONEXIÓN CON PLUX (PagoPlux, pasarela de pagos con tarjeta de Ecuador).
 * Adaptado del código que ya funciona en TuPass (Santa Fe): plux_pago.php, plux_procesar_pago.php y plux_retorno_3ds.php.
 *
 * Cómo funciona:
 *  1. pagar.php muestra el formulario de tarjeta. En el navegador, los datos de la tarjeta se cifran con AES-256-ECB
 *     usando una clave al azar de 32 caracteres, y esa clave se cifra con la llave pública RSA de Plux (igual que TuPass).
 *     El servidor de TuVocalía nunca ve el número de la tarjeta sin cifrar y no guarda nada de la tarjeta.
 *  2. plux_pagar.php (JSON) llama a la API de Plux. Respuestas: 0 aprobado · 100 pide código OTP · 103 pide validación 3DS del banco.
 *  3. plux_3ds.php recibe el regreso del banco (pti, pcc, ptk) y confirma con Plux.
 *  Aprobado → confirmar_pago_linea() (no duplica aunque llegue dos veces) → aplica el pago (suscripción, equipo o escuela).
 *
 * Las claves se escriben en Ajustes → Cobros con tarjeta (se guardan en la tabla ajustes, que no pasa por la bitácora).
 */

/* Direcciones de la API de Plux (no son secretas; son las mismas de TuPass, confirmadas el 28-sep-2026). */
const PLUX_URL_SANDBOX = 'https://apipre.pagoplux.com/intv1/';
const PLUX_RUTA_PAGO = 'credentials/paymentCardResource';
const PLUX_RUTA_3DS = 'integrations/dataTransactionThreeDsResource';
const PLUX_SEPARADOR_AUTH = ':';         // Authorization: Basic base64(idCliente:claveSecreta)
const PLUX_ENCABEZADO_CLAVE = 'simetricKey';

/** ¿Están escritas las claves de Plux? */
function plux_configurado(): bool
{
    foreach (['plux_id_establecimiento', 'plux_id_cliente', 'plux_clave_secreta', 'plux_llave_publica'] as $k) {
        if (trim((string)ajuste($k)) === '') return false;
    }
    return true;
}

/** ¿La conexión está programada? (direcciones de la API completas) */
function plux_listo(): bool
{
    return PLUX_RUTA_PAGO !== '' && PLUX_RUTA_3DS !== '' && plux_url_base() !== '';
}

/** Dirección de la API: pruebas (sandbox) o la de producción que entrega Plux (se escribe en Ajustes). */
function plux_url_base(): string
{
    if (ajuste('plux_ambiente') !== 'produccion') return PLUX_URL_SANDBOX;
    $u = trim((string)ajuste('plux_url_produccion'));
    return preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}/#i', $u) ? rtrim($u, '/') . '/' : '';
}

/** Llama a la API de Plux. Devuelve el JSON decodificado; en error de red: code -1. */
function plux_llamar(string $ruta, array $body, ?string $clave_cifrada = null): array
{
    if (defined('PLUX_SIMULADOR')) return (PLUX_SIMULADOR)($ruta, $body, $clave_cifrada); // solo para las pruebas automáticas
    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(trim((string)ajuste('plux_id_cliente')) . PLUX_SEPARADOR_AUTH . trim((string)ajuste('plux_clave_secreta'))),
    ];
    if ($clave_cifrada !== null) $headers[] = PLUX_ENCABEZADO_CLAVE . ': ' . $clave_cifrada;
    $ch = curl_init(plux_url_base() . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    unset($ch); // (v24) curl_close() ya no hace nada desde PHP 8 y PHP 8.5 lo marca como obsoleto
    if ($resp === false) return ['code' => -1, 'description' => 'No se pudo conectar con Plux: ' . $err];
    $j = json_decode((string)$resp, true);
    return is_array($j) ? $j : ['code' => -1, 'description' => 'Respuesta no válida de Plux', 'raw' => mb_substr((string)$resp, 0, 500)];
}

/** Montos para Plux: con IVA va en baseAmount12 (total con IVA incluido), sin IVA en baseAmount0 (igual que TuPass). */
function plux_montos(array $pg): array
{
    $con_iva = $pg['destino'] === 'tuvocalia' ? (int)ajuste('plux_iva_suscripcion', 1) === 1 : (int)ajuste('plux_iva_cobros', 0) === 1;
    $t = round((float)$pg['total'], 2);
    return $con_iva ? ['baseAmount0' => 0.00, 'baseAmount12' => $t] : ['baseAmount0' => $t, 'baseAmount12' => 0.00];
}

/** Lee de la respuesta de Plux el N° de transacción y si fue débito o crédito (para la comisión). */
function plux_datos_respuesta(array $r): array
{
    $d = is_array($r['detail'] ?? null) ? $r['detail'] : [];
    $id = '';
    foreach (['id_transaccion', 'idTransaccion', 'idTransaction', 'transactionId', 'id', 'token', 'voucher', 'numeroAutorizacion', 'authorizationCode'] as $k) {
        if (isset($d[$k]) && is_scalar($d[$k]) && (string)$d[$k] !== '') { $id = (string)$d[$k]; break; }
    }
    $txt = mb_strtolower(json_encode($d, JSON_UNESCAPED_UNICODE) ?: '');
    $tipo = (str_contains($txt, 'debit') || str_contains($txt, 'débito')) ? 'debito' : 'credito'; // si no se sabe, se cobra la comisión de crédito
    return ['id' => mb_substr($id, 0, 100), 'tipo' => $tipo];
}

/** Guarda la última respuesta de Plux en el pago (sin datos de la tarjeta). */
function plux_anotar(array $pg, string $txt): void
{
    q("UPDATE pagos_linea SET respuesta = ? WHERE id = ? AND estado = 'pendiente'", [mb_substr($txt, 0, 2000), $pg['id']]);
}

/** Resultado de Plux → aprobado / otp / 3ds / fallido. Aplica el pago si fue aprobado. */
function plux_procesar_respuesta(array $pg, array $r, string $origen): array
{
    $code = (int)($r['code'] ?? -1);
    $limpio = $r;
    unset($limpio['raw']);
    $json = json_encode($limpio, JSON_UNESCAPED_UNICODE) ?: '';
    if ($code === 0) {
        $dr = plux_datos_respuesta($r);
        confirmar_pago_linea($pg, $dr['tipo'], $dr['id'] !== '' ? $dr['id'] : 'PLUX-' . $pg['id'], $origen . ' · ' . $json);
        bitacora_evento('pagos', 'Pago con tarjeta APROBADO por Plux N° ' . $pg['id'] . ' por ' . dinero($pg['total']), (int)$pg['liga_id'], (int)$pg['id']);
        return ['status' => 'success'];
    }
    if ($code === 100) {
        plux_anotar($pg, 'Esperando código OTP · ' . $json);
        return ['status' => 'otp_required', 'detail' => $r['detail'] ?? null];
    }
    if ($code === 103) {
        plux_anotar($pg, 'Esperando validación 3DS del banco · ' . date('Y-m-d H:i:s') . ' · ' . $json);
        return ['status' => '3ds_required', 'detail' => $r['detail'] ?? null];
    }
    $msg = is_string($r['detail'] ?? null) && $r['detail'] !== '' ? $r['detail'] : (string)($r['description'] ?? 'El pago no fue aprobado.');
    plux_anotar($pg, 'Intento no aprobado (' . $code . ') · ' . $json);
    return ['status' => 'failed', 'message' => mb_substr($msg, 0, 300), 'code' => $code];
}

/** Arma el pedido a Plux con la tarjeta ya cifrada en el navegador. */
function plux_cuerpo_pago(array $pg, array $in): array
{
    $card = is_array($in['card'] ?? null) ? $in['card'] : [];
    $buyer = is_array($in['buyer'] ?? null) ? $in['buyer'] : [];
    $t = fn($v, int $n = 100) => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $n);
    foreach (['number', 'expirationYear', 'expirationMonth', 'cvv'] as $k) {
        if (!isset($card[$k]) || !is_string($card[$k]) || !preg_match('#^[A-Za-z0-9+/=]{8,400}$#', $card[$k])) throw new RuntimeException('Datos de la tarjeta incompletos.');
    }
    $cedula = preg_replace('/[^0-9A-Za-z]/', '', (string)($in['cedula'] ?? ''));
    if (strlen($cedula) < 5) throw new RuntimeException('Escriba la cédula o RUC del titular de la tarjeta.');
    $email = $t($buyer['email'] ?? '', 120);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escriba un correo válido.');
    if ($t($buyer['firstName'] ?? '') === '' || $t($buyer['lastName'] ?? '') === '') throw new RuntimeException('Escriba nombres y apellidos del titular.');
    $pl = plux_montos($pg);
    $body = [
        'card' => ['number' => $card['number'], 'expirationYear' => $card['expirationYear'], 'expirationMonth' => $card['expirationMonth'], 'cvv' => $card['cvv']],
        'buyer' => ['documentNumber' => $cedula, 'firstName' => $t($buyer['firstName']), 'lastName' => $t($buyer['lastName']), 'phone' => $t($buyer['phone'] ?? '', 30), 'email' => $email],
        'currency' => 'USD',
        'baseAmount0' => $pl['baseAmount0'],
        'baseAmount12' => $pl['baseAmount12'],
        'installments' => '0',
        'interests' => '0',
        'description' => mb_substr(nombre_concepto_pago($pg['concepto']) . ' N° ' . $pg['id'] . ' · ' . marca(), 0, 100),
        'shippingAddress' => ['country' => 'Ecuador', 'city' => $t($buyer['city'] ?? '', 60) ?: 'Quito', 'street' => $t($buyer['street'] ?? '', 120) ?: 'S/N', 'number' => $t($buyer['number'] ?? '', 20) ?: 'S/N'],
        'clientIp' => ip_cliente(),
        'idEstablecimiento' => base64_encode(trim((string)ajuste('plux_id_establecimiento'))),
        'urlRetorno3ds' => url_base() . 'plux_3ds.php',
    ];
    $otp = $in['paramsOtp'] ?? null;
    if (is_array($otp)) {
        $body['paramsOtp'] = [];
        foreach (['idTransaction', 'sessionId', 'tkn', 'tknky', 'tkniv', 'otpCode'] as $k) if (isset($otp[$k]) && is_scalar($otp[$k])) $body['paramsOtp'][$k] = mb_substr((string)$otp[$k], 0, 2000);
    }
    return $body;
}

/** Formulario de pago con Plux (va dentro de pagar.php). */
function plux_html_pago(array $pg): string
{
    if (!plux_listo() || !plux_configurado()) {
        return '<div class="aviso aviso-info">El pago con tarjeta (Plux) se está terminando de conectar. Por ahora pague por transferencia y envíe su comprobante.</div>';
    }
    $partes = preg_split('/\s+/', trim((string)$pg['pagador_nombre']), 2);
    $u = usuario();
    $email = $pg['pagador_email'] !== '' ? $pg['pagador_email'] : ($u['email'] ?? '');
    $llave = trim((string)ajuste('plux_llave_publica'));
    if (!str_contains($llave, 'BEGIN')) $llave = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $llave), 64, "\n") . "-----END PUBLIC KEY-----";
    ob_start();
    ?>
<div id="plux-caja" class="plux-caja" data-token="<?= e($pg['token']) ?>" data-csrf="<?= e(csrf_token()) ?>">
  <?php if (ajuste('plux_ambiente') !== 'produccion'): ?><div class="aviso aviso-info"><b>Plux en AMBIENTE DE PRUEBAS:</b> use solo las tarjetas de prueba de Plux; no se cobra dinero real.</div><?php endif; ?>
  <div id="plux-paso-datos">
    <h2 style="margin:14px 0 6px">Datos de la tarjeta</h2>
    <div class="campos">
      <div class="campo-ancho"><label>Número de la tarjeta</label><input type="text" id="plux-numero" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000"></div>
      <div><label>Mes de vencimiento</label><select id="plux-mes" autocomplete="cc-exp-month"><option value="">Mes</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= sprintf('%02d', $m) ?>"><?= sprintf('%02d', $m) ?></option><?php endfor; ?></select></div>
      <div><label>Año de vencimiento</label><select id="plux-anio" autocomplete="cc-exp-year"><option value="">Año</option><?php for ($a = (int)date('Y'); $a <= (int)date('Y') + 12; $a++): ?><option value="<?= $a ?>"><?= $a ?></option><?php endfor; ?></select></div>
      <div><label>CVV (3 o 4 números atrás)</label><input type="password" id="plux-cvv" inputmode="numeric" autocomplete="cc-csc" maxlength="4"></div>
    </div>
    <h2 style="margin:14px 0 6px">Titular de la tarjeta</h2>
    <div class="campos">
      <div><label>Nombres</label><input type="text" id="plux-nombres" maxlength="60" autocomplete="given-name" value="<?= e($partes[0] ?? '') ?>"></div>
      <div><label>Apellidos</label><input type="text" id="plux-apellidos" maxlength="60" autocomplete="family-name" value="<?= e($partes[1] ?? '') ?>"></div>
      <div><label>Cédula o RUC</label><input type="text" id="plux-cedula" inputmode="numeric" maxlength="13" value="<?= e($pg['pagador_cedula']) ?>"></div>
      <div><label>Celular</label><input type="tel" id="plux-telefono" maxlength="15" autocomplete="tel" value="<?= e($pg['pagador_telefono']) ?>"></div>
      <div><label>Correo</label><input type="email" id="plux-email" maxlength="120" autocomplete="email" value="<?= e($email) ?>"></div>
      <div><label>Ciudad</label><input type="text" id="plux-ciudad" maxlength="60" value="Quito"></div>
      <div class="campo-ancho"><label>Dirección</label><input type="text" id="plux-direccion" maxlength="120" placeholder="Calle y número"></div>
    </div>
    <label class="check" style="font-weight:400;margin-top:10px"><input type="checkbox" id="plux-acepta"> 🔒 <?= e(texto_autorizacion_pago()) ?> <a href="index.php?v=privacidad" target="_blank">Política de privacidad</a>.</label>
    <button type="button" class="btn btn-grande" id="plux-pagar" style="margin-top:12px;width:100%">🔒 Pagar <?= dinero($pg['total']) ?></button>
  </div>
  <div id="plux-paso-otp" style="display:none">
    <h2 style="margin:14px 0 6px">Código de su banco</h2>
    <p>Su banco le envió un código (SMS o correo). Escríbalo aquí:</p>
    <input type="text" id="plux-otp" inputmode="numeric" maxlength="10" autocomplete="one-time-code" style="font-size:1.4rem;text-align:center">
    <button type="button" class="btn btn-grande" id="plux-otp-btn" style="margin-top:10px;width:100%">Confirmar código</button>
  </div>
  <div id="plux-mensaje" class="plux-mensaje" aria-live="polite"></div>
</div>
<script src="assets/js/crypto-js.min.js"></script>
<script src="assets/js/jsencrypt.min.js"></script>
<script>
(function () {
  var LLAVE = <?= json_encode($llave) ?>;
  var caja = document.getElementById('plux-caja'), estado = {}, ocupado = false;
  function $(id) { return document.getElementById(id); }
  function msg(t, tipo) { var m = $('plux-mensaje'); m.className = 'plux-mensaje aviso aviso-' + (tipo || 'info'); m.textContent = t; }
  function claveAlAzar() {
    var c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', r = '', a = new Uint32Array(32);
    window.crypto.getRandomValues(a);
    for (var i = 0; i < 32; i++) r += c.charAt(a[i] % c.length);
    return r;
  }
  function aes(t, k) { return CryptoJS.AES.encrypt(CryptoJS.enc.Utf8.parse(String(t)), CryptoJS.enc.Utf8.parse(k), { mode: CryptoJS.mode.ECB, padding: CryptoJS.pad.Pkcs7 }).toString(); }
  function luhn(n) { var s = 0, d = false; for (var i = n.length - 1; i >= 0; i--) { var x = +n[i]; if (d) { x *= 2; if (x > 9) x -= 9; } s += x; d = !d; } return s % 10 === 0; }
  function datos() {
    var num = $('plux-numero').value.replace(/\D/g, ''), mes = $('plux-mes').value, anio = $('plux-anio').value, cvv = $('plux-cvv').value.replace(/\D/g, '');
    if (num.length < 13 || num.length > 19 || !luhn(num)) { msg('Revise el número de la tarjeta.', 'error'); return null; }
    if (!mes || !anio) { msg('Elija el mes y el año de vencimiento.', 'error'); return null; }
    if (cvv.length < 3) { msg('Escriba el CVV (3 o 4 números de atrás de la tarjeta).', 'error'); return null; }
    if (!$('plux-nombres').value.trim() || !$('plux-apellidos').value.trim()) { msg('Escriba nombres y apellidos del titular.', 'error'); return null; }
    if ($('plux-cedula').value.replace(/\D/g, '').length < 10) { msg('Escriba la cédula (10 números) o RUC (13).', 'error'); return null; }
    if (!/^\S+@\S+\.\S+$/.test($('plux-email').value.trim())) { msg('Escriba un correo válido.', 'error'); return null; }
    if (!$('plux-acepta').checked) { msg('Marque la casilla de autorización de uso de sus datos para procesar el pago.', 'error'); return null; }
    var k = claveAlAzar(), rsa = new JSEncrypt();
    rsa.setPublicKey(LLAVE);
    var kc = rsa.encrypt(k);
    if (!kc) { msg('No se pudo preparar el pago (llave de Plux). Avise a ' + <?= json_encode(marca()) ?> + '.', 'error'); return null; }
    estado = {
      cedula: $('plux-cedula').value.trim(),
      card: { number: aes(num, k), expirationYear: aes(anio, k), expirationMonth: aes(mes, k), cvv: aes(cvv, k) },
      buyer: { firstName: $('plux-nombres').value.trim(), lastName: $('plux-apellidos').value.trim(), phone: $('plux-telefono').value.trim(), email: $('plux-email').value.trim(),
               city: $('plux-ciudad').value.trim() || 'Quito', street: $('plux-direccion').value.trim() || 'S/N', number: 'S/N' },
      simetricKeyCifrada: kc
    };
    $('plux-cvv').value = '';
    return estado;
  }
  function enviar(body) {
    if (ocupado) return; ocupado = true;
    $('plux-pagar').disabled = true; $('plux-otp-btn').disabled = true;
    msg('Procesando el pago, no cierre esta ventana…', 'info');
    body.csrf = caja.dataset.csrf;
    fetch('plux_pagar.php?t=' + caja.dataset.token, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(respuesta)
      .catch(function () { ocupado = false; $('plux-pagar').disabled = false; $('plux-otp-btn').disabled = false; msg('Error de conexión. Revise su internet e intente de nuevo.', 'error'); });
  }
  function respuesta(r) {
    ocupado = false; $('plux-pagar').disabled = false; $('plux-otp-btn').disabled = false;
    if (r.status === 'success') { msg('✅ Pago aprobado. Un momento…', 'ok'); location.reload(); return; }
    if (r.status === 'otp_required') { estado.detailOtp = r.detail || {}; $('plux-paso-datos').style.display = 'none'; $('plux-paso-otp').style.display = 'block'; msg('Escriba el código que le envió su banco.', 'info'); return; }
    if (r.status === '3ds_required') {
      var d = r.detail || {}; msg('Lo llevamos a la validación de su banco…', 'info');
      if (d.customFormChallenge) {
        var f = document.createElement('form'); f.method = 'POST'; f.action = d.url;
        (d.parameters || []).forEach(function (p) { var i = document.createElement('input'); i.type = 'hidden'; i.name = p.name; i.value = p.value; f.appendChild(i); });
        document.body.appendChild(f); f.submit();
      } else { location.href = d.url; }
      return;
    }
    msg('❌ ' + (r.message || 'El pago no fue aprobado.') + ' Puede intentar de nuevo o con otra tarjeta.', 'error');
    $('plux-paso-otp').style.display = 'none'; $('plux-paso-datos').style.display = 'block';
  }
  $('plux-numero').addEventListener('input', function () { var v = this.value.replace(/\D/g, '').slice(0, 19); this.value = v.replace(/(\d{4})(?=\d)/g, '$1 '); });
  $('plux-pagar').addEventListener('click', function () { var d = datos(); if (d) enviar({ cedula: d.cedula, card: d.card, buyer: d.buyer, simetricKeyCifrada: d.simetricKeyCifrada, acepta_datos: true }); });
  $('plux-otp-btn').addEventListener('click', function () {
    var c = $('plux-otp').value.trim(); if (!c) { msg('Escriba el código.', 'error'); return; }
    var o = estado.detailOtp || {};
    enviar({ cedula: estado.cedula, card: estado.card, buyer: estado.buyer, simetricKeyCifrada: estado.simetricKeyCifrada,
             paramsOtp: { idTransaction: o.idTransaction, sessionId: o.sessionId, tkn: o.tkn, tknky: o.tknky, tkniv: o.tkniv, otpCode: c } });
  });
})();
</script>
    <?php
    return (string)ob_get_clean();
}
