<?php
/*
 * VERSIÓN 6 — Doble verificación (código de 6 números en el celular).
 * Compatible con Google Authenticator, Microsoft Authenticator, Authy, etc. (estándar TOTP, RFC 6238).
 */

function base32_codificar(string $bin): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $trozo) $out .= $abc[bindec(str_pad($trozo, 5, '0'))];
    return $out;
}

function base32_decodificar(string $txt): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $txt = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $txt));
    $bits = '';
    foreach (str_split($txt) as $c) {
        $p = strpos($abc, $c);
        if ($p === false) continue;
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}

function totp_nuevo_secreto(): string
{
    return base32_codificar(random_bytes(20));
}

/** Código de 6 dígitos para un momento dado (por defecto, ahora). */
function totp_codigo(string $secreto, ?int $momento = null): string
{
    $contador = intdiv($momento ?? time(), 30);
    $bin = pack('N*', 0) . pack('N*', $contador);
    $hash = hash_hmac('sha1', $bin, base32_decodificar($secreto), true);
    $o = ord($hash[19]) & 0xf;
    $num = ((ord($hash[$o]) & 0x7f) << 24) | ((ord($hash[$o + 1]) & 0xff) << 16) | ((ord($hash[$o + 2]) & 0xff) << 8) | (ord($hash[$o + 3]) & 0xff);
    return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Verifica un código aceptando ±1 intervalo (30 s) por diferencias de hora del celular. */
function totp_verificar(string $secreto, string $codigo): bool
{
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6 || $secreto === '') return false;
    foreach ([-30, 0, 30] as $d) {
        if (hash_equals(totp_codigo($secreto, time() + $d), $codigo)) return true;
    }
    return false;
}

function totp_uri(string $secreto, string $cuenta): string
{
    $emisor = marca();
    return 'otpauth://totp/' . rawurlencode($emisor . ':' . $cuenta) . '?secret=' . $secreto . '&issuer=' . rawurlencode($emisor) . '&digits=6&period=30';
}

/** Genera 8 códigos de respaldo (se muestran UNA vez; se guardan cifrados). Devuelve [lista_visible, json_para_guardar]. */
function codigos_respaldo(): array
{
    $vis = [];
    $hash = [];
    for ($i = 0; $i < 8; $i++) {
        $c = strtoupper(substr(bin2hex(random_bytes(5)), 0, 4) . '-' . substr(bin2hex(random_bytes(5)), 0, 4));
        $vis[] = $c;
        $hash[] = password_hash($c, PASSWORD_DEFAULT);
    }
    return [$vis, json_encode($hash)];
}

/** Usa un código de respaldo (queda gastado). */
function usar_codigo_respaldo(array $u, string $codigo): bool
{
    $codigo = strtoupper(trim($codigo));
    $lista = json_decode($u['totp_respaldo'] ?: '[]', true) ?: [];
    foreach ($lista as $i => $h) {
        if (password_verify($codigo, $h)) {
            unset($lista[$i]);
            q('UPDATE usuarios SET totp_respaldo = ? WHERE id = ?', [json_encode(array_values($lista)), $u['id']]);
            return true;
        }
    }
    return false;
}

/** ¿Este usuario debe usar doble verificación obligatoriamente? */
function doble_verificacion_obligatoria(array $u): bool
{
    return in_array($u['rol'], ['super', 'liga'], true) && (int)ajuste('exigir_2fa', 0) === 1 && !es_usuario_demo($u);
}

/* ============================================================
 * PROTECCIÓN DE DATOS PERSONALES (LOPDP Ecuador)
 * Consentimiento al inscribir; si el jugador es menor de 18, lo autoriza su representante legal.
 * La foto solo se publica si se autoriza (en menores, nunca por defecto).
 * ============================================================ */

function es_menor(?string $fecha_nac): bool
{
    $e = edad($fecha_nac);
    return $e !== null && $e < 18;
}

/** Campos del formulario: autorización de datos (y representante si es menor). $j = datos actuales (al editar). */
function html_consentimiento(array $j = [], string $id = 'c'): string
{
    $menor = es_menor($j['fecha_nac'] ?? '');
    $tiene = ($j['consentimiento'] ?? '') !== '';
    $foto = array_key_exists('foto_publica', $j) ? (int)$j['foto_publica'] === 1 : !$menor;
    ob_start(); ?>
    <fieldset class="consentimiento" id="<?= e($id) ?>">
      <legend>🔒 Protección de datos</legend>
      <?php if ($tiene): ?><p class="ayuda" style="margin-top:0">Autorización registrada el <?= e($j['consentimiento']) ?> (<?= $j['consentimiento_por'] === 'representante' ? 'por su representante' : 'por el jugador' ?>).</p><?php endif; ?>
      <div class="rep" style="<?= $menor ? '' : 'display:none' ?>">
        <p class="aviso aviso-info" style="margin:0 0 10px">Es <b>menor de edad</b>: se necesitan los datos de su padre, madre o representante legal, que es quien autoriza.</p>
        <div class="campos">
          <div><label>Nombre del representante *</label><input type="text" name="representante_nombre" maxlength="80" value="<?= e($j['representante_nombre'] ?? '') ?>"></div>
          <div><label>Cédula del representante *</label><input type="text" name="representante_cedula" maxlength="20" inputmode="numeric" value="<?= e($j['representante_cedula'] ?? '') ?>"></div>
          <div><label>Teléfono del representante *</label><input type="tel" name="representante_telefono" maxlength="30" value="<?= e($j['representante_telefono'] ?? '') ?>"></div>
        </div>
      </div>
      <label class="check" style="font-weight:400"><input type="checkbox" name="consentimiento" value="1" required <?= $tiene ? 'checked' : '' ?>> <span class="txt-adulto" style="<?= $menor ? 'display:none' : '' ?>">El jugador autorizó</span><span class="txt-menor" style="<?= $menor ? '' : 'display:none' ?>">Su representante autorizó</span>&nbsp;el uso de sus datos para la organización del campeonato, según la <a href="index.php?v=privacidad" target="_blank">política de privacidad</a>, incluido su almacenamiento en servidores fuera del Ecuador.</label>
      <label class="check" style="font-weight:400;margin-top:6px"><input type="checkbox" name="foto_publica" value="1" <?= $foto ? 'checked' : '' ?>> Autoriza publicar su foto en la página pública de la liga</label>
      <p class="ayuda">La foto siempre se usa en el carnet y en la vocalía para verificar identidad. La cédula completa y los datos del representante nunca se publican.</p>
    </fieldset>
    <script>
    (function () {
      var caja = document.getElementById(<?= json_encode($id) ?>), form = caja.closest('form'), fn = form && form.querySelector('[name=fecha_nac]');
      if (!fn) return;
      function revisar() {
        var menor = false;
        if (fn.value) { var n = new Date(fn.value + 'T00:00:00'), h = new Date(), e = h.getFullYear() - n.getFullYear(); if (h.getMonth() < n.getMonth() || (h.getMonth() === n.getMonth() && h.getDate() < n.getDate())) e--; menor = e < 18; }
        caja.querySelector('.rep').style.display = menor ? '' : 'none';
        caja.querySelector('.txt-menor').style.display = menor ? '' : 'none';
        caja.querySelector('.txt-adulto').style.display = menor ? 'none' : '';
        caja.querySelectorAll('.rep input').forEach(function (i) { i.required = menor; });
      }
      fn.addEventListener('change', revisar); revisar();
    })();
    </script>
    <?php
    return (string)ob_get_clean();
}

/** Lee y valida la autorización. Devuelve los campos para guardar en jugadores / solicitudes. */
function leer_consentimiento_post(string $fecha_nac, array $actual = []): array
{
    if (post('consentimiento') !== '1') throw new RuntimeException('Falta marcar la autorización de uso de datos del jugador (o de su representante si es menor de edad).');
    $menor = es_menor($fecha_nac);
    $d = ['representante_nombre' => '', 'representante_cedula' => '', 'representante_telefono' => ''];
    if ($menor) {
        $d['representante_nombre'] = mb_substr(post('representante_nombre'), 0, 80);
        $d['representante_cedula'] = strtoupper(preg_replace('/\s+/', '', post('representante_cedula')));
        $d['representante_telefono'] = mb_substr(post('representante_telefono'), 0, 30);
        if (mb_strlen($d['representante_nombre']) < 5) throw new RuntimeException('El jugador es menor de edad: escriba el nombre completo de su representante.');
        if ($err = error_documento($d['representante_cedula'])) throw new RuntimeException('Cédula del representante: ' . $err);
        if (strlen(preg_replace('/\D/', '', $d['representante_telefono'])) < 7) throw new RuntimeException('Escriba el teléfono del representante.');
    }
    $mismo = ($actual['consentimiento'] ?? '') !== '' && ($actual['consentimiento_por'] ?? '') === ($menor ? 'representante' : 'jugador')
        && ($actual['representante_cedula'] ?? '') === $d['representante_cedula'];
    return $d + [
        'consentimiento' => $mismo ? $actual['consentimiento'] : date('Y-m-d H:i:s'),
        'consentimiento_por' => $menor ? 'representante' : 'jugador',
        'foto_publica' => post('foto_publica') === '1' ? 1 : 0,
    ];
}
