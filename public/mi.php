<?php
/*
 * (v8) PORTAL DEL JUGADOR — "Mi carnet"
 *   mi.php?c=CODIGO  → el jugador crea su clave (el código lo genera el delegado o la liga y se envía por WhatsApp)
 *   mi.php           → entra con su cédula y su clave; ve su carnet digital con QR, sus partidos, suspensiones y estadísticas.
 */
require __DIR__ . '/../app/bootstrap.php';
define('VISTA_PUBLICA', true);
csrf_verificar();

$jid = (int)($_SESSION['jugador_portal'] ?? 0);
$j = $jid ? fila('SELECT j.*, e.nombre AS equipo, e.escudo, e.color, e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ? AND j.anonimizado = 0', [$jid]) : null;
$contexto = fn(array $x) => contexto_auditoria(['usuario' => 'Jugador: ' . nombre_jugador($x), 'rol' => 'jugador', 'liga_id' => (int)$x['liga_id'], 'origen' => 'jugador']);
if ($j) $contexto($j);

if (es_post()) {
    $accion = post('accion');
    try {
        if ($accion === 'crear_clave') {
            $x = fila("SELECT j.*, e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.portal_codigo = ? AND j.portal_codigo <> '' AND j.activo = 1", [strtoupper(post('c'))]);
            if (!$x) throw new RuntimeException('El código ya se usó o no es válido. Pida uno nuevo a su delegado.');
            if (!preg_match('/^\d{4,6}$/', post('pin'))) throw new RuntimeException('La clave debe tener de 4 a 6 números.');
            if (post('pin') !== post('pin2')) throw new RuntimeException('Las dos claves no coinciden.');
            if ($x['cedula'] === '') throw new RuntimeException('Su ficha no tiene cédula. Pida a su delegado que la registre.');
            $contexto($x);
            q("UPDATE jugadores SET portal_pin = ?, portal_codigo = '', portal_ultimo = ? WHERE id = ?", [password_hash(post('pin'), PASSWORD_DEFAULT), date('Y-m-d H:i'), $x['id']]);
            bitacora_evento('portal_jugador', nombre_jugador($x) . ' creó su clave del portal del jugador', (int)$x['liga_id'], (int)$x['id']);
            session_regenerate_id(true);
            $_SESSION['jugador_portal'] = (int)$x['id'];
            flash('¡Listo! Desde ahora entra con su cédula y esta clave. Guarde esta página en la pantalla de inicio de su celular.');
            ir('mi.php');
        }
        if ($accion === 'ingresar') {
            if (demasiados_intentos('portal_jugador', 10, '-15 minutes')) throw new RuntimeException('Demasiados intentos. Espere 15 minutos.');
            $ced = strtoupper(preg_replace('/\s+/', '', post('cedula')));
            $cands = filas("SELECT j.*, e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.cedula = ? AND j.portal_pin <> '' AND j.activo = 1 AND j.anonimizado = 0", [$ced]);
            $ok = null;
            foreach ($cands as $x) if (password_verify(post('pin'), $x['portal_pin'])) { $ok = $x; break; }
            if (!$ok) {
                anotar_intento('portal_jugador');
                usleep(800000);
                throw new RuntimeException('Cédula o clave incorrecta. Si no tiene clave, pida a su delegado el "Acceso" de su carnet digital.');
            }
            session_regenerate_id(true);
            $_SESSION['jugador_portal'] = (int)$ok['id'];
            q('UPDATE jugadores SET portal_ultimo = ? WHERE id = ?', [date('Y-m-d H:i'), $ok['id']]);
            ir('mi.php');
        }
        if ($accion === 'salir') {
            unset($_SESSION['jugador_portal']);
            ir('mi.php');
        }
        if ($accion === 'cambiar_clave' && $j) {
            if (!password_verify(post('actual'), $j['portal_pin'])) throw new RuntimeException('La clave actual no es correcta.');
            if (!preg_match('/^\d{4,6}$/', post('pin'))) throw new RuntimeException('La clave nueva debe tener de 4 a 6 números.');
            $contexto($j);
            q('UPDATE jugadores SET portal_pin = ? WHERE id = ?', [password_hash(post('pin'), PASSWORD_DEFAULT), $j['id']]);
            bitacora_evento('portal_jugador', nombre_jugador($j) . ' cambió su clave del portal', (int)$j['liga_id'], (int)$j['id']);
            flash('Clave cambiada.');
            ir('mi.php');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        ir('mi.php' . (post('c') !== '' ? '?c=' . rawurlencode(post('c')) : ''));
    }
}

$liga = $j ? fila('SELECT * FROM ligas WHERE id = ?', [$j['liga_id']]) : null;
cortar_liga_publica($liga, false, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible»
exigir_modulo_portal($liga, 'carnets'); // (v12) módulo del plan
cabecera_publica($j ? 'Mi carnet · ' . nombre_jugador($j) : 'Mi carnet', $liga);
foreach (tomar_flash() as [$tipo, $msg]) echo '<div class="aviso aviso-' . e($tipo) . '">' . e($msg) . '</div>';

if (!$j):
    $cod = strtoupper(get('c'));
    $jc = $cod !== '' ? fila("SELECT j.nombres, j.apellidos FROM jugadores j WHERE j.portal_codigo = ? AND j.portal_codigo <> '' AND j.activo = 1", [$cod]) : null; ?>
  <div class="tarjeta login">
    <?php if ($jc): ?>
      <h1>Hola, <?= e(trim($jc['nombres'])) ?> 👋</h1>
      <p>Cree su clave de <b>4 a 6 números</b> para ver su carnet digital. Después entrará con su cédula y esta clave.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear_clave"><input type="hidden" name="c" value="<?= e($cod) ?>">
        <p><label>Clave (4 a 6 números)</label><input type="password" name="pin" required pattern="\d{4,6}" inputmode="numeric" maxlength="6"></p>
        <p><label>Repita la clave</label><input type="password" name="pin2" required pattern="\d{4,6}" inputmode="numeric" maxlength="6"></p>
        <button class="btn btn-grande">Crear mi clave</button></form>
    <?php else: ?>
      <?php if ($cod !== ''): ?><div class="aviso aviso-error">Ese código ya se usó o no es válido. Ingrese con su cédula y su clave, o pida un código nuevo a su delegado.</div><?php endif; ?>
      <h1>🪪 Mi carnet</h1>
      <p class="suave">Portal del jugador: su carnet digital, sus partidos y sus estadísticas.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ingresar">
        <p><label>Cédula</label><input type="text" name="cedula" required inputmode="numeric" maxlength="20" autocomplete="username"></p>
        <p><label>Clave</label><input type="password" name="pin" required inputmode="numeric" maxlength="6" autocomplete="current-password"></p>
        <button class="btn btn-grande">Entrar</button></form>
      <p class="suave" style="font-size:.85rem">¿Primera vez? Pida a su delegado el botón <b>🔑 Acceso</b> de su carnet: le llegará un enlace para crear su clave.</p>
    <?php endif; ?>
  </div>
<?php
    pie_publico();
    exit;
endif;

sincronizar_liga((int)$liga['id']);
$st = estado_jugador($j);
$colores = ['habilitado' => '#1a9e5c', 'suspendido' => '#d7263d', 'baja' => '#6b7572'];
$textos = ['habilitado' => '✓ HABILITADO', 'suspendido' => '✗ SUSPENDIDO', 'baja' => '✗ NO HABILITADO'];
$num = estadisticas_jugador((int)$j['id']);
$pj = (int)valor("SELECT COUNT(*) FROM alineaciones a JOIN partidos p ON p.id = a.partido_id WHERE a.jugador_id = ? AND p.estado IN ('jugado','wo')", [$j['id']]);
$T = textos_deporte($liga);
$eid = (int)$j['equipo_id'];
$prox = filas("SELECT p.*, el.nombre AS local_nombre, el.escudo AS local_escudo, el.color AS local_color, ev.nombre AS visitante_nombre, ev.escudo AS visitante_escudo, ev.color AS visitante_color
               FROM partidos p JOIN torneos t ON t.id = p.torneo_id JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
               WHERE (p.local_id = ? OR p.visitante_id = ?) AND p.estado IN ('programado','en_vivo','postergado') AND t.estado <> 'finalizado'
               ORDER BY (p.fecha = ''), p.fecha, p.hora LIMIT 5", [$eid, $eid]);
$base_pub = 'index.php?l=' . rawurlencode($liga['slug']);
?>
<div class="carnet-digital" style="--c: <?= e(color_valido($liga['color'])) ?>">
  <div class="cd-cab"><?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo') ?><div><b><?= e($liga['nombre']) ?></b><small>CARNET DIGITAL DE JUGADOR</small></div></div>
  <div class="cd-cuerpo">
    <div class="cd-foto"><?= foto_jugador(['foto_publica' => 1] + $j, 'foto-verif') ?></div>
    <div class="cd-datos">
      <div class="cd-nombre"><?= e(nombre_jugador($j)) ?></div>
      <div><?= escudo($j['escudo'], $j['equipo'], $j['color']) ?> <b><?= e($j['equipo']) ?></b></div>
      <?php if ($j['numero'] !== null): ?><div>Camiseta <b>#<?= (int)$j['numero'] ?></b></div><?php endif; ?>
      <?php if ($j['cedula'] !== ''): ?><div>C.I. <?= e($j['cedula']) ?></div><?php endif; ?>
      <?php if ($j['posicion'] !== ''): ?><div><?= e($j['posicion']) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="estado-grande" style="background:<?= $colores[$st['estado']] ?>"><?= $textos[$st['estado']] ?></div>
  <div class="cd-qr" id="qr" data-url="<?= e(url_carnet($j)) ?>"></div>
  <p class="reloj-verif" id="reloj"></p>
  <p class="suave" style="text-align:center;font-size:.8rem;margin:0 0 8px">El vocal escanea este QR en la cancha. La hora en vivo demuestra que no es una captura de pantalla.</p>
</div>
<?php foreach ($st['detalle'] as $d): ?><div class="aviso aviso-error"><?= e($d) ?></div><?php endforeach; ?>

<div class="cifras">
  <div class="cifra"><b><?= $pj ?></b>Partidos</div>
  <?php if ($T['con_goleadores']): ?><div class="cifra"><b><?= (int)($num['goles'] ?? 0) ?></b><?= e($T['goles']) ?></div><?php endif; ?>
  <div class="cifra"><b><?= (int)($num['asistencias'] ?? 0) ?></b>Asistencias</div>
  <div class="cifra"><b><?= (int)($num['amarillas'] ?? 0) ?></b><?= e($T['amarilla']) ?></div>
</div>

<?= modulo_activo($liga, 'videos') ? html_videos_portal('jugador', (int)$j['id']) : '' // (v28) ?>

<h2>Mis próximos partidos</h2>
<p><a class="btn btn-sec btn-chico" href="<?= e(enlace_calendario($liga, 'e', $eid)) ?>">📅 Agregar a mi calendario</a></p>
<?php if (!$prox): ?><p class="suave">No hay partidos programados.</p><?php endif; ?>
<?php foreach ($prox as $p) echo tarjeta_partido($p, $base_pub . '&p=' . (int)$p['id']); ?>

<div class="acciones" style="margin:16px 0">
  <a class="btn" href="<?= e($base_pub . '&j=' . (int)$j['id']) ?>">📸 Mi cromo y estadísticas</a>
  <a class="btn btn-sec" href="<?= e(url_liga($liga)) ?>">Página de la liga</a>
</div>
<details class="tarjeta"><summary>Cambiar mi clave</summary>
  <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cambiar_clave">
    <p><label>Clave actual</label><input type="password" name="actual" required inputmode="numeric" maxlength="6"></p>
    <p><label>Clave nueva (4 a 6 números)</label><input type="password" name="pin" required pattern="\d{4,6}" inputmode="numeric" maxlength="6"></p>
    <button class="btn btn-sec">Cambiar</button></form>
</details>
<form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="salir"><button class="btn btn-sec btn-chico">Salir</button></form>
<script src="assets/js/qrcode.js"></script>
<script>
(function () {
  var el = document.getElementById('qr'), q = qrcode(0, 'M');
  q.addData(el.getAttribute('data-url')); q.make();
  el.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
  var r = document.getElementById('reloj');
  function t() { var d = new Date(); r.textContent = d.toLocaleDateString('es-EC') + ' ' + d.toLocaleTimeString('es-EC'); }
  t(); setInterval(t, 1000);
})();
</script>
<?php
pie_publico();
