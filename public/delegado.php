<?php
/*
 * PORTAL DEL DELEGADO (cada equipo entra con su correo y contraseña)
 * Ve su plantilla y quién está habilitado, inscribe jugadores en línea (la liga aprueba),
 * pide bajas, ve sus partidos y su estado de cuenta con recibos.
 */
require __DIR__ . '/../app/bootstrap.php';

$u = usuario();
if (!$u) ir('admin.php?p=login');
if ($u['rol'] !== 'delegado') ir('admin.php');
csrf_verificar();

$eq = fila('SELECT * FROM equipos WHERE id = ?', [(int)$u['equipo_id']]);
if (!$eq) {
    $_SESSION = [];
    exit('Su usuario no tiene un equipo asignado. Comuníquese con la liga.');
}
$liga = fila('SELECT * FROM ligas WHERE id = ?', [$eq['liga_id']]);
cortar_liga_publica($liga, true, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible» (su personal: «Tu cuenta está pausada»)
exigir_modulo_portal($liga, 'inscripcion'); // (v12) módulo del plan
$eid = (int)$eq['id'];
$s = get('s', 'plantilla');
if (!in_array($s, ['plantilla', 'inscribir', 'pase', 'solicitudes', 'partidos', 'cuenta', 'clave', 'pedidos'], true)) $s = 'plantilla';
// (v8) Foto de un comprobante enviado por este equipo
if (get('comprobante') !== '') {
    $cp = fila('SELECT * FROM comprobantes WHERE id = ? AND equipo_id = ?', [(int)get('comprobante'), $eid]);
    $abs = $cp ? RAIZ . '/data/' . $cp['archivo'] : '';
    if (!$cp || !is_file($abs) || str_contains($cp['archivo'], '..')) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    readfile($abs);
    exit;
}
$url = 'delegado.php?s=' . $s;
// (v10) Adjunto de un pedido de este equipo
if (get('pedido_archivo') !== '') {
    $pa = fila('SELECT * FROM pedidos_delegado WHERE id = ? AND equipo_id = ?', [(int)get('pedido_archivo'), $eid]);
    $abs = $pa && $pa['archivo'] !== '' ? RAIZ . '/data/' . $pa['archivo'] : '';
    if (!$pa || !is_file($abs) || str_contains($pa['archivo'], '..')) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    readfile($abs);
    exit;
}

/* ---------- Documentos imprimibles de su propio equipo ---------- */
if (get('recibo') !== '') {
    $m = movimiento_recibo((int)get('recibo'), (int)$liga['id']);
    if (!$m || (int)$m['equipo_id'] !== $eid) exit('Recibo no encontrado.');
    doc_recibo($m, $liga, 'delegado.php?s=cuenta');
    exit;
}
if (get('estado_cuenta') === '1') {
    sincronizar_liga((int)$liga['id']);
    doc_estado_cuenta($eq, $liga, 'delegado.php?s=cuenta');
    exit;
}

if (es_post()) {
    try {
        $accion = post('accion');
        if (!in_array($accion, ['clave', 'tg_desconectar', 'pagar_tarjeta'], true)) exigir_suscripcion($liga);
        if ($accion === 'pagar_tarjeta') {
            // (v12) Pago con tarjeta del estado de cuenta (TuAcademia cobra y transfiere a la liga)
            if (!org_cobra_con_tarjeta($liga)) throw new RuntimeException('La liga no recibe pagos con tarjeta por ahora.');
            $monto = round((float)str_replace(',', '.', post('monto')), 2);
            $token = crear_pago_linea($liga, [
                'destino' => 'organizacion', 'concepto' => 'equipo', 'detalle' => 'Abono al estado de cuenta de ' . $eq['nombre'], 'equipo_id' => $eid, 'monto_base' => $monto,
                'pagador_nombre' => $u['nombre'], 'pagador_email' => $u['email'], 'pagador_telefono' => (string)$eq['telefono'], 'datos' => ['volver' => 'delegado.php?s=cuenta'],
            ]);
            ir(url_pago($token));
        }
        if ($accion === 'tg_desconectar') {
            q("UPDATE usuarios SET tg_chat = '' WHERE id = ?", [$u['id']]);
            flash('Telegram desconectado.');
            ir('delegado.php?s=clave');
        }
        if ($accion === 'comprobante') {
            // (v8) El delegado envía la foto de su transferencia o depósito; la liga la aprueba y el recibo sale solo
            $monto = round((float)str_replace(',', '.', post('monto')), 2);
            if ($monto <= 0) throw new RuntimeException('Escriba el valor que pagó.');
            $archivo = subir_comprobante('foto');
            $tsel = (int)post('torneo_id');
            q('INSERT INTO comprobantes (liga_id, equipo_id, usuario_id, torneo_id, monto, fecha, forma_pago, referencia, archivo, nota) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                $liga['id'], $eid, $u['id'], $tsel && fila('SELECT id FROM torneos WHERE id = ? AND liga_id = ?', [$tsel, $liga['id']]) ? $tsel : null, $monto,
                post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d'),
                in_array(post('forma_pago'), ['transferencia', 'deposito', 'otro'], true) ? post('forma_pago') : 'transferencia',
                mb_substr(post('referencia'), 0, 60), $archivo, mb_substr(post('nota'), 0, 200),
            ]);
            aviso_admins($liga, '💵 <b>Comprobante de pago</b>' . "\n" . e($eq['nombre']) . ' envió ' . dinero($monto) . '. Revíselo en Finanzas → Comprobantes.', 'comp:' . db()->lastInsertId());
            flash('Comprobante enviado. La tesorería lo revisará y, al aprobarlo, su recibo aparecerá aquí.');
            ir('delegado.php?s=cuenta');
        }
        if ($accion === 'pedido') {
            // (v10) Pedido a la liga: reprogramar un partido, descargo o apelación de una sanción
            $tipo = post('tipo');
            if (!isset(tipos_pedido()[$tipo])) throw new RuntimeException('Elija qué quiere pedir.');
            if ((int)valor("SELECT COUNT(*) FROM pedidos_delegado WHERE equipo_id = ? AND estado = 'pendiente'", [$eid]) >= 5) throw new RuntimeException('Ya tiene 5 pedidos esperando respuesta. Espere a que la liga los revise.');
            $motivo = trim(mb_substr(post('motivo'), 0, 2000));
            if (mb_strlen($motivo) < 10) throw new RuntimeException('Explique el motivo (al menos una frase).');
            $d = ['liga_id' => $liga['id'], 'equipo_id' => $eid, 'usuario_id' => $u['id'], 'tipo' => $tipo, 'partido_id' => null, 'sancion_id' => null, 'sancion_equipo_id' => null,
                'fecha_propuesta' => '', 'hora_propuesta' => '', 'motivo' => $motivo, 'archivo' => '', 'creado' => date('Y-m-d H:i:s')];
            $sobre = $tipo === 'reprogramacion' ? 'p:' . (int)post('partido_id') : post('sobre');
            if (preg_match('/^p:(\d+)$/', $sobre, $m) && (int)$m[1]) {
                $pp = fila('SELECT p.* FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE p.id = ? AND t.liga_id = ? AND (p.local_id = ? OR p.visitante_id = ?)', [(int)$m[1], $liga['id'], $eid, $eid]) ?? throw new RuntimeException('Partido no encontrado.');
                if ($tipo === 'reprogramacion' && partido_cerrado($pp)) throw new RuntimeException('Ese partido ya se jugó.');
                if ($tipo === 'reprogramacion' && fila("SELECT id FROM pedidos_delegado WHERE partido_id = ? AND tipo = 'reprogramacion' AND estado = 'pendiente'", [$pp['id']])) throw new RuntimeException('Ya hay un pedido de reprogramación pendiente para ese partido.');
                $d['partido_id'] = (int)$pp['id'];
            } elseif (preg_match('/^s:(\d+)$/', $sobre, $m)) {
                $d['sancion_id'] = (int)(fila('SELECT s.id FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id WHERE s.id = ? AND j.equipo_id = ?', [(int)$m[1], $eid])['id'] ?? 0) ?: throw new RuntimeException('Sanción no encontrada.');
            } elseif (preg_match('/^se:(\d+)$/', $sobre, $m)) {
                $d['sancion_equipo_id'] = (int)(fila('SELECT id FROM sanciones_equipo WHERE id = ? AND equipo_id = ?', [(int)$m[1], $eid])['id'] ?? 0) ?: throw new RuntimeException('Sanción no encontrada.');
            } elseif ($tipo !== 'descargo') {
                throw new RuntimeException($tipo === 'reprogramacion' ? 'Elija el partido que quiere reprogramar.' : 'Elija la sanción que quiere apelar.');
            }
            if ($tipo === 'reprogramacion') {
                $d['fecha_propuesta'] = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '';
                $d['hora_propuesta'] = hora_valida(post('hora'));
            }
            if (!empty($_FILES['archivo']) && ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $sub = 'pedidos/' . date('Ym');
                $d['archivo'] = $sub . '/' . guardar_imagen($_FILES['archivo'], RAIZ . '/data/' . $sub, 1600);
            }
            q('INSERT INTO pedidos_delegado (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
            $pid_n = (int)db()->lastInsertId();
            aviso_admins($liga, '📨 <b>Nuevo pedido: ' . e(nombre_tipo_pedido($tipo)) . '</b>' . "\n" . e($eq['nombre']) . ' · ' . e(detalle_pedido($d)) . "\n" . e(mb_substr($motivo, 0, 300)) . "\n\nRespóndalo en Campeonatos → Pedidos.", 'ped:' . $pid_n);
            flash('Pedido enviado a la liga. Verá la respuesta aquí mismo.');
            ir('delegado.php?s=pedidos');
        }
        if ($accion === 'cancelar_pedido') {
            q("UPDATE pedidos_delegado SET estado = 'cancelado' WHERE id = ? AND equipo_id = ? AND estado = 'pendiente'", [(int)post('id'), $eid]);
            flash('Pedido cancelado.');
            ir('delegado.php?s=pedidos');
        }
        if ($accion === 'cancelar_comprobante') {
            q("UPDATE comprobantes SET estado = 'cancelado' WHERE id = ? AND equipo_id = ? AND estado = 'pendiente'", [(int)post('id'), $eid]);
            flash('Comprobante cancelado.');
            ir('delegado.php?s=cuenta');
        }
        if ($accion === 'portal') {
            // (v8) Código para que el jugador entre a su portal (carnet digital en su celular)
            $jj = fila('SELECT * FROM jugadores WHERE id = ? AND equipo_id = ? AND activo = 1', [(int)post('jugador_id'), $eid]) ?? throw new RuntimeException('Jugador no encontrado.');
            $_SESSION['portal_msg'] = [(int)$jj['id'], codigo_portal($jj)];
            ir('delegado.php?s=plantilla#portal');
        }
        if ($accion === 'pase') {
            if (!(int)$liga['pases_delegados']) throw new RuntimeException('La liga no recibe pedidos de pase en línea. Consulte con la directiva.');
            if (!inscripcion_abierta($eid)) throw new RuntimeException('Los pases en línea solo se piden mientras la inscripción de jugadores está abierta.');
            $cedula = strtoupper(preg_replace('/\s+/', '', post('cedula')));
            $j = fila('SELECT j.*, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.cedula = ? AND j.activo = 1', [$liga['id'], $cedula]);
            if (!$j) throw new RuntimeException('No hay ningún jugador activo de la liga con esa cédula. Si es nuevo, inscríbalo con "+ Inscribir jugador".');
            if ((int)$j['equipo_id'] === $eid) throw new RuntimeException(nombre_jugador($j) . ' ya es de su equipo.');
            if (fila("SELECT id FROM pases WHERE jugador_id = ? AND estado = 'pendiente'", [$j['id']])) throw new RuntimeException('Ya hay un pedido de pase pendiente para ese jugador.');
            [$mx, $act, $pend, $libres] = cupo_equipo($eid, $liga);
            if ($libres <= 0) throw new RuntimeException('Su equipo no tiene cupos libres.');
            validar_categoria_jugador($eid, $j, (int)$j['id'], true);
            q("INSERT INTO pases (liga_id, jugador_id, origen_id, destino_id, estado, motivo, costo, usuario_id, solicitado_por) VALUES (?,?,?,?, 'pendiente', ?,?,?,?)",
                [$liga['id'], $j['id'], $j['equipo_id'], $eid, mb_substr(post('motivo'), 0, 300), (float)$liga['costo_pase'], $u['id'], $u['nombre'] . ' (delegado de ' . $eq['nombre'] . ')']);
            $pase_id = (int)db()->lastInsertId();
            aviso_admins($liga, '🔁 <b>Pedido de pase</b>' . "
" . e(nombre_jugador($j)) . ': ' . e($j['equipo']) . ' → ' . e($eq['nombre']) . "
Revíselo en Inscripciones.", 'pasepend:' . $pase_id);
            aviso_delegados($liga, (int)$j['equipo_id'], '🔁 ' . e($eq['nombre']) . ' pidió el pase de <b>' . e(nombre_jugador($j)) . '</b>. La liga lo revisará.', 'pasepend_o:' . $pase_id);
            flash('Pedido de pase enviado para ' . nombre_jugador($j) . '. La liga lo revisará' . ((float)$liga['costo_pase'] > 0 ? ' (valor del pase: ' . dinero($liga['costo_pase']) . ')' : '') . '.');
            ir('delegado.php?s=solicitudes');
        }
        if ($accion === 'inscribir') {
            $t = inscripcion_abierta($eid);
            if (!$t) throw new RuntimeException('La inscripción en línea está cerrada. Consulte con la liga.');
            [$mx, $act, $pend, $libres] = cupo_equipo($eid, $liga);
            if ($libres <= 0) throw new RuntimeException("No hay cupos: su equipo tiene $act jugadores y $pend solicitudes pendientes (máximo $mx).");
            $nombres = post('nombres');
            $apellidos = post('apellidos');
            if (mb_strlen($nombres) < 2 || mb_strlen($apellidos) < 2) throw new RuntimeException('Escriba nombres y apellidos completos.');
            $cedula = strtoupper(preg_replace('/\s+/', '', post('cedula')));
            if ($err = error_documento($cedula)) throw new RuntimeException($err);
            if ($err = cedula_ocupada($cedula, (int)$liga['id'])) throw new RuntimeException($err . ((int)$liga['pases_delegados'] ? ' Use la pestaña "Pedir pase".' : ''));
            $fn = post('fecha_nac');
            if ($fn === '' || !strtotime($fn) || strtotime($fn) > time()) throw new RuntimeException('Escriba una fecha de nacimiento válida.');
            $notas = validar_categoria_jugador($eid, ['nombres' => $nombres, 'apellidos' => $apellidos, 'fecha_nac' => date('Y-m-d', strtotime($fn))], 0, true);
            $cons = leer_consentimiento_post(date('Y-m-d', strtotime($fn)));
            $numero = entero_o_null(post('numero'));
            if ($numero !== null) {
                if (fila('SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ? AND activo = 1', [$eid, $numero])
                    || fila("SELECT id FROM solicitudes_jugador WHERE equipo_id = ? AND numero = ? AND estado = 'pendiente' AND tipo = 'alta'", [$eid, $numero])) {
                    throw new RuntimeException('El número ' . $numero . ' ya lo usa otro jugador del equipo.');
                }
            }
            if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('La foto del jugador es obligatoria (de frente, se usa para el carnet).');
            $foto = subir_imagen('foto', 'jugadores', 600);
            $d = array_merge([
                'equipo_id' => $eid, 'usuario_id' => $u['id'], 'tipo' => 'alta', 'nombres' => mb_substr($nombres, 0, 80), 'apellidos' => mb_substr($apellidos, 0, 80),
                'cedula' => $cedula, 'numero' => $numero, 'posicion' => in_array(post('posicion'), ['', 'Arquero', 'Defensa', 'Mediocampista', 'Delantero'], true) ? post('posicion') : '',
                'fecha_nac' => date('Y-m-d', strtotime($fn)), 'foto' => $foto,
            ], $cons);
            q('INSERT INTO solicitudes_jugador (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
            $sol_id = (int)db()->lastInsertId();
            registrar_consentimiento_jugador('delegado_jugador', (int)$liga['id'], $d, 'solicitudes_jugador', $sol_id); // (v24)
            aviso_admins($liga, '📝 <b>Nueva inscripción de jugador</b>' . "
" . e($nombres . ' ' . $apellidos) . ' · ' . e($eq['nombre']) . "
Revísela en Inscripciones.", 'solj:' . $sol_id);
            flash('Solicitud enviada: ' . $nombres . ' ' . $apellidos . '. La liga la revisará y usted verá la respuesta en "Mis solicitudes".' . ($notas ? ' (' . implode(' · ', $notas) . ')' : ''));
            ir('delegado.php?s=solicitudes');
        }
        if ($accion === 'baja') {
            $j = fila('SELECT * FROM jugadores WHERE id = ? AND equipo_id = ? AND activo = 1', [(int)post('jugador_id'), $eid]);
            if (!$j) throw new RuntimeException('Jugador no encontrado.');
            if (fila("SELECT id FROM solicitudes_jugador WHERE jugador_id = ? AND tipo = 'baja' AND estado = 'pendiente'", [$j['id']])) throw new RuntimeException('Ya hay una solicitud de baja pendiente para ese jugador.');
            q('INSERT INTO solicitudes_jugador (equipo_id, usuario_id, tipo, jugador_id, nombres, apellidos, cedula, motivo) VALUES (?,?,?,?,?,?,?,?)',
                [$eid, $u['id'], 'baja', $j['id'], $j['nombres'], $j['apellidos'], $j['cedula'], mb_substr(post('motivo'), 0, 300)]);
            flash('Solicitud de baja enviada para ' . nombre_jugador($j) . '.');
            ir('delegado.php?s=solicitudes');
        }
        if ($accion === 'cancelar_pase') {
            q("UPDATE pases SET estado = 'cancelado' WHERE id = ? AND destino_id = ? AND estado = 'pendiente'", [(int)post('id'), $eid]);
            flash('Pedido de pase cancelado.');
            ir('delegado.php?s=solicitudes');
        }
        if ($accion === 'cancelar') {
            q("UPDATE solicitudes_jugador SET estado = 'cancelada' WHERE id = ? AND equipo_id = ? AND estado = 'pendiente'", [(int)post('id'), $eid]);
            flash('Solicitud cancelada.');
            ir('delegado.php?s=solicitudes');
        }
        if ($accion === 'clave') {
            if (!password_verify(post('actual'), $u['clave'])) throw new RuntimeException('La contraseña actual no es correcta.');
            if (strlen(post('nueva')) < 8) throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
            q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash(post('nueva'), PASSWORD_DEFAULT), $u['id']]);
            bitacora_evento('usuarios', 'Cambió su propia contraseña (delegado de ' . $eq['nombre'] . ')', (int)$liga['id'], (int)$u['id']);
            flash('Contraseña cambiada.');
            ir('delegado.php');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    ir($url);
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($eq['nombre']) ?> · Delegado</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/estilo.css?v=330">
<?= cabecera_app() ?>
</head>
<body class="admin" style="--acento: <?= e($liga['color']) ?>">
<header class="barra"><div class="barra-in">
  <a class="marca" href="delegado.php">🧑‍💼 Delegado <small><?= e($u['nombre']) ?></small></a>
  <nav class="menu" style="display:flex;flex-direction:row;width:auto;padding:0;flex-wrap:wrap"><a href="delegado.php?s=clave">👤 Mi cuenta</a><a href="admin.php?p=salir">Salir</a></nav>
</div>
<div class="subbarra"><div class="barra-in"><?= escudo($eq['escudo'], $eq['nombre'], $eq['color'], 'mini') ?> <strong><?= e($eq['nombre']) ?></strong> · <?= e($liga['nombre']) ?></div></div>
</header>
<main class="contenedor">
<?php $sus = estado_suscripcion($liga); if ($sus['estado'] === 'vencida'): ?><div class="aviso aviso-error">La liga tiene su suscripción vencida: por ahora no se pueden enviar solicitudes.</div><?php endif; ?>
<?php foreach (tomar_flash() as [$tipo, $msg]): ?><div class="aviso aviso-<?= e($tipo) ?>"><?= e($msg) ?></div><?php endforeach; ?>
<?php
$abierta = inscripcion_abierta($eid);
$tcat = torneos_categoria_equipo($eid);
$n_pend = (int)valor("SELECT COUNT(*) FROM solicitudes_jugador WHERE equipo_id = ? AND estado = 'pendiente'", [$eid]);
?>
<nav class="pestanas">
  <?php $tabs = ['plantilla' => 'Mi plantilla', 'inscribir' => '+ Inscribir jugador'];
  if ((int)$liga['pases_delegados']) $tabs['pase'] = '🔁 Pedir pase';
  $n_pend += (int)valor("SELECT COUNT(*) FROM pases WHERE destino_id = ? AND estado = 'pendiente'", [$eid]);
  $n_ped = (int)valor("SELECT COUNT(*) FROM pedidos_delegado WHERE equipo_id = ? AND estado = 'pendiente'", [$eid]);
  $tabs += ['solicitudes' => 'Mis solicitudes' . ($n_pend ? " ($n_pend)" : ''), 'partidos' => 'Partidos', 'pedidos' => '📨 Pedidos a la liga' . ($n_ped ? " ($n_ped)" : ''), 'cuenta' => 'Estado de cuenta'];
  foreach ($tabs as $k => $txt): ?>
    <a href="delegado.php?s=<?= $k ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'plantilla'):
    $jug = filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1 ORDER BY (numero IS NULL), numero, apellidos', [$eid]);
    $susp = [];
    foreach (filas("SELECT t.id FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$eid]) as $t) {
        foreach (suspensiones((int)$t['id']) as $jid => $x) $susp[$jid] = $x;
    }
    [$mx, $act, $pend, $libres] = cupo_equipo($eid, $liga);
    $pm = $_SESSION['portal_msg'] ?? null;
    unset($_SESSION['portal_msg']);
    if ($pm && ($jp = fila('SELECT * FROM jugadores WHERE id = ? AND equipo_id = ?', [$pm[0], $eid]))):
        $msg_p = mensaje_portal($jp, $pm[1], $liga); ?>
  <div class="tarjeta" id="portal" style="border:2px solid var(--acento)">
    <h3 style="margin-top:0">🔑 Acceso de <?= e(nombre_jugador($jp)) ?> a su carnet digital</h3>
    <p>Envíele este mensaje (el código sirve una sola vez):</p>
    <pre class="mensaje"><?= e($msg_p) ?></pre>
    <a class="btn" target="_blank" href="https://wa.me/?text=<?= rawurlencode($msg_p) ?>">📲 Enviar por WhatsApp</a>
  </div>
<?php endif; ?>
  <p class="suave"><?= $act ?> jugadores activos · máximo <?= $mx ?> · <?= $pend ?> solicitudes pendientes · <?= $abierta ? '<b>Inscripción en línea abierta hasta el ' . e($abierta['jugadores_hasta']) . '</b>' : 'Inscripción en línea cerrada' ?></p>
  <?php foreach ($tcat as $tc): ?><p class="aviso aviso-info">👶 <?= e($tc['nombre']) ?>: <?= e(texto_categoria($tc)) ?></p><?php endforeach; ?>
  <div class="jugadores">
    <?php foreach ($jug as $j): $sp = $susp[(int)$j['id']] ?? null; ?>
      <div class="jugador-card">
        <?php if ($j['numero'] !== null): ?><span class="dorsal"><?= (int)$j['numero'] ?></span><?php endif; ?>
        <?= foto_jugador($j, 'foto-card' . ($j['foto'] ? '' : ' foto-vacia')) ?>
        <div class="datos">
          <span class="nombre"><?= e(nombre_jugador($j)) ?></span>
          <?= $sp ? '<span class="etiqueta roja">Suspendido ' . (int)$sp['pendientes'] . '</span>' : '<span class="etiqueta verde">Habilitado</span>' ?><?php foreach ($tcat as $tc) echo etiqueta_categoria(situacion_categoria($tc, $j)); ?>
          <div class="acciones" style="margin-top:6px">
            <a class="btn btn-sec btn-chico" target="_blank" href="<?= e(url_carnet($j)) ?>">Carnet</a>
            <form class="inline" method="post" onsubmit="var m=prompt('Motivo de la baja de <?= e(addslashes(nombre_jugador($j))) ?>:'); if(!m) return false; this.motivo.value=m; return true;">
              <?= csrf_campo() ?><input type="hidden" name="accion" value="baja"><input type="hidden" name="jugador_id" value="<?= (int)$j['id'] ?>"><input type="hidden" name="motivo" value="">
              <button class="btn btn-sec btn-chico">Pedir baja</button></form>
            <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="portal"><input type="hidden" name="jugador_id" value="<?= (int)$j['id'] ?>"><button class="btn btn-sec btn-chico" title="Carnet digital en el celular del jugador">🔑 Acceso</button></form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$jug): ?><p class="suave">Su equipo aún no tiene jugadores aprobados.</p><?php endif; ?>

<?php elseif ($s === 'inscribir'):
    [$mx, $act, $pend, $libres] = cupo_equipo($eid, $liga);
?>
  <?php if (!$abierta): ?>
    <div class="aviso aviso-error">La inscripción en línea está <b>cerrada</b>. La liga la abre en cada campeonato con una fecha límite.</div>
  <?php elseif ($libres <= 0): ?>
    <div class="aviso aviso-error">No quedan cupos: <?= $act ?> jugadores + <?= $pend ?> pendientes (máximo <?= $mx ?>).</div>
  <?php else: ?>
  <div class="aviso aviso-info">Inscripción abierta hasta el <b><?= e($abierta['jugadores_hasta']) ?></b> (<?= e($abierta['nombre']) ?>). Cupos libres: <b><?= $libres ?></b>. La liga revisa cada solicitud antes de aprobarla.</div>
  <div class="tarjeta">
    <h2 style="margin-top:0">Inscribir jugador</h2>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="inscribir">
      <div class="campos">
        <div><label>Nombres *</label><input type="text" name="nombres" required maxlength="80"></div>
        <div><label>Apellidos *</label><input type="text" name="apellidos" required maxlength="80"></div>
        <div><label>Cédula o pasaporte *</label><input type="text" name="cedula" required maxlength="20" inputmode="numeric"><div class="ayuda">Se verifica que la cédula ecuatoriana sea válida.</div></div>
        <div><label>Fecha de nacimiento *</label><input type="date" name="fecha_nac" required max="<?= date('Y-m-d') ?>"></div>
        <div><label>Número de camiseta</label><input type="number" name="numero" min="0" max="99"></div>
        <div><label>Posición</label><select name="posicion"><option value="">—</option><option>Arquero</option><option>Defensa</option><option>Mediocampista</option><option>Delantero</option></select></div>
        <div class="campo-ancho"><label>Foto de frente *</label><input type="file" name="foto" accept="image/*" required><div class="ayuda">Tipo carnet, rostro visible, sin gorra ni lentes oscuros. Se usa para verificar la identidad en cada partido.</div></div>
      </div>
      <?= html_consentimiento([], 'c-del') ?>
      <button class="btn btn-grande">Enviar solicitud a la liga</button>
    </form>
  </div>
  <?php endif; ?>

<?php elseif ($s === 'pase'): ?>
  <?php if (!$abierta): ?>
    <div class="aviso aviso-error">Los pases en línea solo se piden mientras la inscripción de jugadores está <b>abierta</b>.</div>
  <?php else: ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">🔁 Pedir el pase de un jugador de otro equipo</h2>
    <p class="suave">Escriba la cédula del jugador. La liga revisa el pedido y avisa a su equipo actual.<?= (float)$liga['costo_pase'] > 0 ? ' Valor del pase: <b>' . dinero($liga['costo_pase']) . '</b> (se carga a su equipo al aprobarse).' : '' ?></p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="pase">
      <div class="campos">
        <div><label>Cédula del jugador *</label><input type="text" name="cedula" required maxlength="20" inputmode="numeric"></div>
        <div class="campo-ancho"><label>Motivo / comentario</label><input type="text" name="motivo" maxlength="300"></div>
      </div>
      <button class="btn btn-grande">Enviar pedido de pase</button></form>
  </div>
  <?php endif; ?>

<?php elseif ($s === 'solicitudes'):
    $sols = filas('SELECT * FROM solicitudes_jugador WHERE equipo_id = ? ORDER BY id DESC LIMIT 100', [$eid]);
    $chips = ['pendiente' => 'chip-pend', 'aprobado' => 'chip-ok', 'rechazado' => 'chip-mal', 'cancelada' => '', 'cancelado' => ''];
    $mis_pases = filas('SELECT ps.*, j.nombres, j.apellidos, eo.nombre AS origen FROM pases ps JOIN jugadores j ON j.id = ps.jugador_id LEFT JOIN equipos eo ON eo.id = ps.origen_id WHERE ps.destino_id = ? ORDER BY ps.id DESC LIMIT 50', [$eid]);
?>
  <?php if ($mis_pases): ?>
  <h2>Pedidos de pase</h2>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Jugador</th><th>Viene de</th><th>Estado</th><th>Respuesta</th><th></th></tr>
    <?php foreach ($mis_pases as $x): ?>
      <tr><td><?= e(substr($x['creado'], 0, 16)) ?></td><td><?= e(nombre_jugador($x)) ?></td><td><?= e($x['origen'] ?? '—') ?></td>
        <td><span class="estado-chip <?= $chips[$x['estado']] ?? '' ?>"><?= e(ucfirst($x['estado'])) ?></span></td><td style="white-space:normal"><?= e($x['respuesta']) ?></td>
        <td><?php if ($x['estado'] === 'pendiente'): ?><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_pase"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">Cancelar</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <h2>Inscripciones y bajas</h2>
  <?php endif; ?>
  <?php if (!$sols): ?><p class="suave">No ha enviado solicitudes.</p><?php endif; ?>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Tipo</th><th>Jugador</th><th>Estado</th><th>Respuesta de la liga</th><th></th></tr>
    <?php foreach ($sols as $x): ?>
      <tr><td><?= e(substr($x['creado'], 0, 16)) ?></td><td><?= $x['tipo'] === 'alta' ? 'Inscripción' : 'Baja' ?></td>
        <td><?= e(nombre_jugador($x)) ?> <small><?= e($x['cedula']) ?></small></td>
        <td><span class="estado-chip <?= $chips[$x['estado']] ?? '' ?>"><?= e(ucfirst($x['estado'])) ?></span></td>
        <td style="white-space:normal"><?= e($x['respuesta'] ?: ($x['motivo'] ?: '')) ?></td>
        <td><?php if ($x['estado'] === 'pendiente'): ?><form class="inline" method="post" onsubmit="return confirm('¿Cancelar esta solicitud?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">Cancelar</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>

<?php elseif ($s === 'partidos'):
    $ts = filas("SELECT t.id FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$eid]);
    $prox = [];
    $ult = [];
    foreach ($ts as $t) {
        foreach (partidos_torneo((int)$t['id'], "AND (p.local_id = $eid OR p.visitante_id = $eid)") as $p) {
            if (partido_cerrado($p)) $ult[] = $p; else $prox[] = $p;
        }
    }
?>
  <p><a class="btn btn-sec btn-chico" href="<?= e(enlace_calendario($liga, 'e', $eid)) ?>">📅 Agregar los partidos a mi calendario</a> <small class="suave">(se actualiza solo en el celular)</small></p>
  <h2>Próximos partidos</h2>
  <?php if (!$prox): ?><p class="suave">No hay partidos programados.</p><?php endif; ?>
  <?php foreach (array_slice($prox, 0, 10) as $p) echo tarjeta_partido($p, 'index.php?l=' . rawurlencode($liga['slug']) . '&p=' . (int)$p['id']); ?>
  <h2>Resultados</h2>
  <?php foreach (array_slice(array_reverse($ult), 0, 10) as $p) echo tarjeta_partido($p, 'index.php?l=' . rawurlencode($liga['slug']) . '&p=' . (int)$p['id']); ?>

<?php elseif ($s === 'pedidos'):
    // (v10) Pedidos a la liga
    $mis_ped = filas('SELECT * FROM pedidos_delegado WHERE equipo_id = ? ORDER BY id DESC LIMIT 50', [$eid]);
    $ts_eq = filas("SELECT t.id FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$eid]);
    $por_jugar = [];
    $jugados_eq = [];
    foreach ($ts_eq as $tt) {
        foreach (partidos_torneo((int)$tt['id'], "AND (p.local_id = $eid OR p.visitante_id = $eid)") as $p) {
            if (partido_cerrado($p)) $jugados_eq[] = $p; else $por_jugar[] = $p;
        }
    }
    $jugados_eq = array_slice(array_reverse($jugados_eq), 0, 10);
    $sanc_j = filas("SELECT s.*, j.nombres, j.apellidos, t.nombre AS torneo FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id JOIN torneos t ON t.id = s.torneo_id WHERE j.equipo_id = ? AND t.estado <> 'finalizado' ORDER BY s.id DESC", [$eid]);
    $sanc_e = filas("SELECT s.*, t.nombre AS torneo FROM sanciones_equipo s JOIN torneos t ON t.id = s.torneo_id WHERE s.equipo_id = ? AND t.estado <> 'finalizado' ORDER BY s.id DESC", [$eid]);
    $txt_p = fn($p) => ($p['fase'] === 'regular' ? 'Fecha ' . (int)$p['jornada'] : nombre_fase($p['fase'])) . ': ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'] . ($p['fecha'] !== '' ? ' · ' . fecha_bonita($p['fecha']) . ($p['hora'] !== '' ? ' ' . $p['hora'] : '') : '');
?>
  <p class="suave">Envíe a la liga un pedido por escrito. La liga lo aprueba o lo rechaza con una respuesta, y todo queda registrado.</p>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">📅 Reprogramar un partido</h2>
      <?php if (!$por_jugar): ?><p class="suave">No tiene partidos por jugar.</p><?php else: ?>
      <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="pedido"><input type="hidden" name="tipo" value="reprogramacion">
        <p><label>Partido *</label><select name="partido_id" required><option value="">— elegir —</option><?php foreach ($por_jugar as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($txt_p($p)) ?></option><?php endforeach; ?></select></p>
        <div class="campos"><div><label>Fecha que propone</label><input type="date" name="fecha" min="<?= date('Y-m-d') ?>"></div><div><label>Hora que propone</label><input type="time" name="hora"></div></div>
        <p><label>Motivo *</label><textarea name="motivo" required rows="3" maxlength="2000" placeholder="Explique por qué necesitan cambiar la fecha."></textarea></p>
        <p><label>Documento o foto (opcional)</label><input type="file" name="archivo" accept="image/*"></p>
        <button class="btn">Enviar pedido</button></form>
      <?php endif; ?>
    </div>
    <div class="tarjeta">
      <h2 style="margin-top:0">⚖️ Descargo o apelación</h2>
      <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="pedido">
        <p><label>Tipo *</label><select name="tipo" required><option value="apelacion">Apelación de una sanción</option><option value="descargo">Descargo (explicación a la comisión)</option></select></p>
        <p><label>¿Sobre qué?</label><select name="sobre">
          <option value="">— General —</option>
          <?php if ($sanc_j || $sanc_e): ?><optgroup label="Sanciones"><?php foreach ($sanc_j as $x): ?><option value="s:<?= (int)$x['id'] ?>"><?= e(nombre_jugador($x) . ': ' . (int)$x['partidos'] . ' partido(s)' . ($x['motivo'] !== '' ? ' — ' . mb_substr($x['motivo'], 0, 60) : '')) ?></option><?php endforeach; ?>
            <?php foreach ($sanc_e as $x): ?><option value="se:<?= (int)$x['id'] ?>"><?= e('Al equipo: ' . ((int)$x['puntos'] ? '−' . (int)$x['puntos'] . ' pts ' : '') . ((float)$x['multa'] > 0 ? 'multa ' . dinero($x['multa']) . ' ' : '') . mb_substr($x['motivo'], 0, 60)) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
          <?php if ($jugados_eq): ?><optgroup label="Partidos jugados"><?php foreach ($jugados_eq as $p): ?><option value="p:<?= (int)$p['id'] ?>"><?= e($txt_p($p) . ' (' . (int)$p['goles_local'] . '-' . (int)$p['goles_visitante'] . ')') ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        </select></p>
        <p><label>Su explicación *</label><textarea name="motivo" required rows="4" maxlength="2000" placeholder="Cuente lo que pasó y qué pide a la comisión."></textarea></p>
        <p><label>Prueba: foto o captura (opcional)</label><input type="file" name="archivo" accept="image/*"></p>
        <button class="btn">Enviar a la comisión</button></form>
    </div>
  </div>
  <h2>Mis pedidos</h2>
  <?php if (!$mis_ped): ?><p class="suave">Todavía no ha enviado pedidos.</p><?php endif; ?>
  <?php foreach ($mis_ped as $x): ?>
    <div class="tarjeta pedido <?= e($x['estado']) ?>" style="padding:10px 14px">
      <div class="fila-titulo" style="margin:0"><b><?= e(nombre_tipo_pedido($x['tipo'])) ?></b><span><?= chip_estado_pedido($x['estado']) ?> <small class="suave"><?= e(substr($x['creado'], 0, 16)) ?></small></span></div>
      <p style="margin:.3rem 0"><small><?= e(detalle_pedido($x)) ?><?= $x['fecha_propuesta'] !== '' ? ' · propone ' . e(fecha_bonita($x['fecha_propuesta']) . ' ' . $x['hora_propuesta']) : '' ?></small></p>
      <p style="margin:.3rem 0;white-space:pre-wrap"><?= e($x['motivo']) ?></p>
      <?php if ($x['archivo'] !== ''): ?><p style="margin:.2rem 0"><a target="_blank" href="delegado.php?pedido_archivo=<?= (int)$x['id'] ?>">📎 ver adjunto</a></p><?php endif; ?>
      <?php if ($x['respuesta'] !== ''): ?><div class="aviso <?= $x['estado'] === 'aprobado' ? 'aviso-ok' : 'aviso-error' ?>" style="margin:.4rem 0 0"><b>Respuesta de la liga:</b> <?= e($x['respuesta']) ?></div><?php endif; ?>
      <?php if ($x['estado'] === 'pendiente'): ?><form class="inline" method="post" onsubmit="return confirm('¿Cancelar este pedido?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_pedido"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">Cancelar</button></form><?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($s === 'cuenta'):
    sincronizar_liga((int)$liga['id']);
    $saldo = saldo_equipo($eid);
    $movs = filas('SELECT m.*, t.nombre AS torneo FROM movimientos m LEFT JOIN torneos t ON t.id = m.torneo_id WHERE m.equipo_id = ? AND m.anulado = 0 ORDER BY m.fecha DESC, m.id DESC', [$eid]);
?>
  <div class="tarjeta"><p style="font-size:1.3rem;margin:0"><?= $saldo > 0 ? 'Su equipo debe: <span class="saldo-debe">' . dinero($saldo) . '</span>' : ($saldo < 0 ? 'Saldo a favor: <span class="saldo-ok">' . dinero(-$saldo) . '</span>' : '<span class="saldo-ok">Al día ✓</span>') ?></p>
    <p style="margin:.5rem 0 0"><a class="btn btn-sec btn-chico" target="_blank" href="delegado.php?estado_cuenta=1">🖨 Imprimir estado de cuenta</a></p></div>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Detalle</th><th class="num">Cargo</th><th class="num">Pago</th><th></th></tr>
    <?php foreach ($movs as $m): ?>
      <tr><td><?= e($m['fecha']) ?></td><td style="white-space:normal"><?= e($m['descripcion']) ?></td>
        <td class="num"><?= $m['tipo'] === 'cargo' ? dinero($m['monto']) : '' ?></td><td class="num"><?= $m['tipo'] === 'pago' ? dinero($m['monto']) : '' ?></td>
        <td><?php if ($m['tipo'] === 'pago'): ?><a class="btn btn-sec btn-chico" target="_blank" href="delegado.php?recibo=<?= (int)$m['id'] ?>">Recibo</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php $comps = filas('SELECT * FROM comprobantes WHERE equipo_id = ? ORDER BY id DESC LIMIT 20', [$eid]);
  $tor_eq = filas("SELECT t.id, t.nombre FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$eid]); ?>
  <?php if (org_cobra_con_tarjeta($liga)): ?>
  <div class="tarjeta" style="margin-top:14px;border:2px solid var(--acento)">
    <h2 style="margin-top:0">💳 Pagar con tarjeta</h2>
    <p class="suave" style="margin-top:0">Crédito o débito, desde su celular. El pago se aplica solo y el recibo sale al instante.</p>
    <form method="post" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="pagar_tarjeta">
      <label>Valor ($) <input type="number" name="monto" min="1" step="0.01" required value="<?= $saldo > 0 ? e(number_format($saldo, 2, '.', '')) : '' ?>" style="width:120px"></label>
      <button class="btn">Pagar con tarjeta</button></form>
    <?= $saldo > 0 ? aviso_comision_cliente($liga, $saldo) : '' ?>
  </div>
  <?php endif; ?>
  <div class="tarjeta" style="margin-top:14px">
    <h2 style="margin-top:0">📤 Enviar comprobante de pago</h2>
    <p class="suave" style="margin-top:0">¿Pagó por transferencia o depósito? Envíe la foto del comprobante. La tesorería lo revisa y le emite el recibo.</p>
    <?php if (($liga['datos_pago'] ?? '') !== ''): ?><div class="aviso aviso-info"><b>Datos para pagar:</b><br><?= nl2br(e($liga['datos_pago'])) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="comprobante">
      <div class="campos">
        <div><label>Valor pagado ($) *</label><input type="number" name="monto" min="0.01" step="0.01" required value="<?= $saldo > 0 ? e(number_format($saldo, 2, '.', '')) : '' ?>"></div>
        <div><label>Fecha del pago</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
        <div><label>Forma</label><select name="forma_pago"><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option><option value="otro">Otro</option></select></div>
        <div><label>N° de comprobante (opcional)</label><input type="text" name="referencia" maxlength="60"></div>
        <?php if ($tor_eq): ?><div><label>Campeonato</label><select name="torneo_id"><option value="">—</option><?php foreach ($tor_eq as $te_): ?><option value="<?= (int)$te_['id'] ?>"><?= e($te_['nombre']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="campo-ancho"><label>Foto del comprobante *</label><input type="file" name="foto" accept="image/*" required></div>
        <div class="campo-ancho"><label>Nota (qué está pagando)</label><input type="text" name="nota" maxlength="200" placeholder="Ej: inscripción y arbitraje de la fecha 3"></div>
      </div>
      <button class="btn">Enviar comprobante</button>
    </form>
    <?php if ($comps): ?>
    <h3>Mis comprobantes</h3>
    <div class="tabla-scroll"><table><tr><th>Fecha</th><th class="num">Valor</th><th>Estado</th><th></th></tr>
      <?php foreach ($comps as $c): ?><tr><td><?= e($c['fecha']) ?></td><td class="num"><?= dinero($c['monto']) ?></td>
        <td><?= ['pendiente' => '<span class="estado-chip chip-pend">En revisión</span>', 'aprobado' => '<span class="estado-chip chip-ok">Aprobado</span>', 'rechazado' => '<span class="estado-chip chip-mal">Rechazado</span>', 'cancelado' => '<span class="estado-chip">Cancelado</span>'][$c['estado']] ?? e($c['estado']) ?><?= $c['respuesta'] !== '' ? '<br><small>' . e($c['respuesta']) . '</small>' : '' ?></td>
        <td class="acciones"><a class="btn btn-sec btn-chico" target="_blank" href="delegado.php?comprobante=<?= (int)$c['id'] ?>">Ver foto</a>
          <?php if ($c['estado'] === 'aprobado' && $c['movimiento_id']): ?><a class="btn btn-sec btn-chico" target="_blank" href="delegado.php?recibo=<?= (int)$c['movimiento_id'] ?>">Recibo</a><?php endif; ?>
          <?php if ($c['estado'] === 'pendiente'): ?><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar_comprobante"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico">Cancelar</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

<?php else: /* clave */ ?>
  <?= tg_tarjeta_usuario($u, 'delegado.php?s=clave') ?>
  <div class="tarjeta login" style="margin-top:10px"><h1>Cambiar contraseña</h1>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="clave">
      <p><label>Contraseña actual</label><input type="password" name="actual" required></p>
      <p><label>Nueva contraseña (mín. 8)</label><input type="password" name="nueva" required minlength="8"></p>
      <button class="btn">Cambiar</button></form></div>
<?php endif; ?>
</main>
<footer class="pie">Portal del delegado · <?= e($liga['nombre']) ?> · QF Producciones</footer>
</body>
</html>
