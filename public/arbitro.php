<?php
/*
 * SISTEMA DEL ÁRBITRO
 * Cada árbitro entra con su correo y contraseña (se los da la liga) y llena el informe
 * de los partidos que pitó: hora de inicio, marcador, goles, tarjetas e incidentes.
 */
require __DIR__ . '/../app/bootstrap.php';

$u = usuario();
if (!$u) ir('admin.php?p=login');
if ($u['rol'] !== 'arbitro') ir('admin.php');
csrf_verificar();

$mis = array_map('intval', array_column(filas('SELECT id FROM arbitros WHERE usuario_id = ? AND activo = 1', [$u['id']]), 'id'));
$in = $mis ? implode(',', $mis) : '0';

// (v13) Factura de sus honorarios: la ve y la puede subir él mismo (foto o PDF)
if (get('factura') !== '') {
    $pa = fila('SELECT factura FROM pagos_arbitro WHERE id = ? AND arbitro_id IN (' . $in . ')', [(int)get('factura')]);
    servir_factura((string)($pa['factura'] ?? ''));
}
if (es_post() && post('accion') === 'factura') {
    try {
        $pa = fila('SELECT * FROM pagos_arbitro WHERE id = ? AND anulado = 0 AND arbitro_id IN (' . $in . ')', [(int)post('pago_id')]) ?? throw new RuntimeException('Pago no encontrado.');
        adjuntar_factura('pagos_arbitro', $pa, 'factura', post('factura_numero'), $u['nombre']);
        flash('Su factura quedó guardada. La liga ya la puede ver.');
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    ir('arbitro.php');
}

function cabecera_arbitro(string $titulo): void
{
    $u = usuario();
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Árbitros</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/estilo.css?v=330">
<?= cabecera_app() ?>
</head>
<body class="admin">
<header class="barra"><div class="barra-in">
  <a class="marca" href="arbitro.php">🟨 Informes arbitrales <small><?= e($u['nombre']) ?></small></a>
  <nav class="menu" style="display:flex;flex-direction:row;width:auto;padding:0;flex-wrap:wrap"><a href="arbitro.php">Mis partidos</a><a href="arbitro.php?clave=1">👤 Mi cuenta</a><a href="admin.php?p=salir">Salir</a></nav>
</div></header>
<main class="contenedor">
<?php foreach (tomar_flash() as [$tipo, $msg]): ?><div class="aviso aviso-<?= e($tipo) ?>"><?= e($msg) ?></div><?php endforeach;
}

/* ---------- Mi cuenta: contraseña y Telegram ---------- */
if (get('clave') === '1') {
    if (es_post() && post('accion') === 'tg_desconectar') {
        q("UPDATE usuarios SET tg_chat = '' WHERE id = ?", [$u['id']]);
        flash('Telegram desconectado.');
        ir('arbitro.php?clave=1');
    }
    if (es_post()) {
        if (!password_verify(post('actual'), $u['clave'])) {
            flash('La contraseña actual no es correcta.', 'error');
        } elseif (strlen(post('nueva')) < 8) {
            flash('La nueva contraseña debe tener al menos 8 caracteres.', 'error');
        } else {
            q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash(post('nueva'), PASSWORD_DEFAULT), $u['id']]);
            bitacora_evento('usuarios', 'Cambió su propia contraseña', null, (int)$u['id']);
            flash('Contraseña cambiada.');
            ir('arbitro.php');
        }
        ir('arbitro.php?clave=1');
    }
    cabecera_arbitro('Mi cuenta');
    echo tg_tarjeta_usuario($u, 'arbitro.php?clave=1');
    ?>
  <div class="tarjeta login" style="margin-top:10px"><h1>Cambiar contraseña</h1>
    <form method="post"><?= csrf_campo() ?>
      <p><label>Contraseña actual</label><input type="password" name="actual" required></p>
      <p><label>Nueva contraseña (mín. 8)</label><input type="password" name="nueva" required minlength="8"></p>
      <button class="btn">Cambiar</button></form></div>
    <?php
    pie_admin();
    exit;
}

/* ---------- Lista de mis partidos ---------- */
if (get('p') === '') {
    $partidos = filas("SELECT p.*, el.nombre AS local_nombre, el.escudo AS local_escudo, el.color AS local_color,
                              ev.nombre AS visitante_nombre, ev.escudo AS visitante_escudo, ev.color AS visitante_color,
                              l.nombre AS liga, t.nombre AS torneo, i.estado AS informe_estado,
                              CASE WHEN p.arbitro_id IN ($in) THEN 'Central' WHEN p.cuarto_id IN ($in) THEN 'Cuarto árbitro' ELSE 'Asistente' END AS funcion
                       FROM partidos p JOIN torneos t ON t.id = p.torneo_id JOIN ligas l ON l.id = t.liga_id
                       JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                       LEFT JOIN informes i ON i.partido_id = p.id
                       WHERE p.arbitro_id IN ($in) OR p.asistente1_id IN ($in) OR p.asistente2_id IN ($in) OR p.cuarto_id IN ($in)
                       ORDER BY (p.fecha = ''), p.fecha DESC, p.hora DESC");
    $pend = array_filter($partidos, fn($x) => $x['funcion'] === 'Central' && in_array($x['estado'], ['jugado', 'en_vivo'], true) && $x['informe_estado'] !== 'enviado');
    cabecera_arbitro('Mis partidos');
    ?>
  <h1>Mis partidos</h1>
  <?php if (!$mis): ?><div class="aviso aviso-info">Su cuenta todavía no está vinculada a ninguna liga como árbitro activo.</div><?php endif; ?>
  <?php if ($pend): ?><div class="aviso aviso-error">Tiene <b><?= count($pend) ?></b> informe(s) pendiente(s) de enviar.</div><?php endif; ?>
  <?php if (!$partidos && $mis): ?><p class="suave">Todavía no le han asignado partidos.</p><?php endif; ?>
  <?php // (v8) Honorarios: lo que la liga le debe y lo que ya le pagó
  $hon_pend = 0.0; $hon_n = 0;
  foreach (filas('SELECT id, liga_id FROM arbitros WHERE id IN (' . $in . ')') as $ar) {
      $hp = honorarios_pendientes((int)$ar['liga_id'], (int)$ar['id'])[(int)$ar['id']] ?? null;
      if ($hp) { $hon_pend += $hp['total']; $hon_n += count($hp['partidos']); }
  }
  $hon_pag = filas('SELECT pa.*, l.nombre AS liga FROM pagos_arbitro pa JOIN ligas l ON l.id = pa.liga_id WHERE pa.arbitro_id IN (' . $in . ') AND pa.anulado = 0 ORDER BY pa.id DESC LIMIT 10');
  if ($hon_n || $hon_pag): ?>
  <details class="tarjeta"><summary><b>💵 Mis honorarios</b> · por cobrar: <b><?= dinero($hon_pend) ?></b> (<?= $hon_n ?> partido<?= $hon_n === 1 ? '' : 's' ?>)</summary>
    <?php if ($hon_pag): ?><div class="tabla-scroll"><table style="margin-top:8px"><tr><th>Fecha</th><th>Liga</th><th class="num">Partidos</th><th class="num">Recibido</th><th>Mi factura</th></tr>
      <?php foreach ($hon_pag as $hp): ?><tr><td><?= e($hp['fecha']) ?></td><td><?= e($hp['liga']) ?></td><td class="num"><?= (int)$hp['partidos'] ?></td><td class="num"><?= dinero($hp['monto']) ?></td>
        <td><?= html_factura($hp, 'arbitro.php?factura=' . (int)$hp['id'], '<input type="hidden" name="pago_id" value="' . (int)$hp['id'] . '">', true) ?></td></tr><?php endforeach; ?></table></div>
      <p class="suave" style="margin:.3rem 0 0">Si usted factura, suba la foto o el PDF de la factura de cada pago (🧾).</p><?php endif; ?>
  </details>
  <?php endif; ?>
  <?php foreach ($partidos as $x):
      $chip = $x['funcion'] !== 'Central' ? '<span class="estado-chip">' . e($x['funcion']) . '</span>'
          : ($x['informe_estado'] === 'enviado' ? '<span class="estado-chip chip-ok">Informe enviado</span>'
          : (in_array($x['estado'], ['jugado', 'en_vivo'], true) ? '<span class="estado-chip chip-mal">Informe pendiente</span>' : '<span class="estado-chip chip-pend">Por jugar</span>'));
  ?>
    <div style="margin-bottom:4px"><small><?= e($x['liga'] . ' · ' . $x['torneo']) ?></small> <?= $chip ?></div>
    <?= tarjeta_partido($x, $x['funcion'] === 'Central' ? 'arbitro.php?p=' . (int)$x['id'] : '') ?>
  <?php endforeach;
    pie_admin();
    exit;
}

/* ---------- Informe de un partido ---------- */
$p = partido_completo((int)get('p'));
if (!$p || !in_array((int)$p['arbitro_id'], $mis, true)) {
    flash('Ese partido no le corresponde (solo el árbitro central llena el informe).', 'error');
    ir('arbitro.php');
}
$pid = (int)$p['id'];
$liga = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]);
cortar_liga_publica($liga, true, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible» (su personal: «Tu cuenta está pausada»)
exigir_modulo_portal($liga, 'arbitros'); // (v12) módulo del plan
$url = 'arbitro.php?p=' . $pid;
$reglas = reglas_torneo((int)$p['torneo_id']);
$max_minuto = (int)$reglas['num_tiempos'] * (int)$reglas['minutos_tiempo'] + 30;
$dep = deporte_de($reglas);
$T = textos_deporte($dep);

if (get('imprimir') === '1') {
    doc_informe($p, $liga, $url);
    exit;
}

$inf = informe_partido($pid);
$enviado = $inf && $inf['estado'] === 'enviado';

if (es_post()) {
    try {
        if ($enviado) throw new RuntimeException('El informe ya fue enviado. Si necesita corregirlo, pida a la liga que lo reabra.');
        exigir_suscripcion($liga);
        if (!$inf) {
            q('INSERT INTO informes (partido_id, arbitro_id) VALUES (?,?)', [$pid, $p['arbitro_id']]);
        }
        $accion = post('accion');
        if ($accion === 'guardar' || $accion === 'enviar') {
            $gl = entero_o_null(post('goles_local'));
            $gv = entero_o_null(post('goles_visitante'));
            $hi = hora_valida(post('hora_inicio'));
            q('UPDATE informes SET arbitro_id = ?, hora_inicio = ?, hora_fin = ?, goles_local = ?, goles_visitante = ?, incidentes = ?, actualizado = ? WHERE partido_id = ?', [
                $p['arbitro_id'], $hi, hora_valida(post('hora_fin')), $gl, $gv, mb_substr(post('incidentes'), 0, 5000), date('Y-m-d H:i:s'), $pid,
            ]);
            if ($accion === 'enviar') {
                if ($hi === '') throw new RuntimeException('Guardado, pero para ENVIAR falta la hora de inicio del partido.');
                if ($gl === null || $gv === null) throw new RuntimeException('Guardado, pero para ENVIAR falta el marcador final.');
                [$cl, $cv] = marcador_desde_eventos($p, 'informe_eventos');
                if (($cl !== $gl || $cv !== $gv) && post('confirmo') !== '1') {
                    throw new RuntimeException("Guardado. Ojo: los goles que registró suman $cl - $cv pero el marcador dice $gl - $gv. Corrija, o marque la casilla de confirmación y envíe de nuevo.");
                }
                q("UPDATE informes SET estado = 'enviado', enviado_en = ? WHERE partido_id = ?", [date('Y-m-d H:i'), $pid]);
                $cmp = comparar_informe(partido_completo($pid));
                if ($cmp['diferencias']) aviso_admins($liga, '⚠️ <b>El informe del árbitro no coincide con la vocalía</b>' . "\n" . e($p['local_nombre'] . ' vs ' . $p['visitante_nombre']) . ': ' . count($cmp['diferencias']) . ' diferencia(s).' . "\nRevíselo en el panel.", 'dif:' . $pid . ':' . date('YmdHi'));
                flash('Informe enviado a la liga. ¡Gracias!');
            } else {
                flash('Informe guardado (borrador). Recuerde presionar "Enviar informe" al terminar.');
            }
        } elseif ($accion === 'evento') {
            $tipo = post('tipo');
            if (!in_array($tipo, tipos_evento_de($reglas), true)) throw new RuntimeException('Elija el tipo.');
            $j = fila('SELECT * FROM jugadores WHERE id = ? AND equipo_id IN (?,?)', [(int)post('jugador_id'), $p['local_id'], $p['visitante_id']]);
            if (!$j) throw new RuntimeException('Elija el jugador de la lista.');
            $min = post('minuto') === '' ? null : entero(post('minuto'), 0, $max_minuto);
            $cant = entero(post('cantidad', '1'), 1, 20);
            for ($i = 0; $i < $cant; $i++) {
                q('INSERT INTO informe_eventos (partido_id, jugador_id, equipo_id, tipo, minuto, nota) VALUES (?,?,?,?,?,?)', [$pid, $j['id'], $j['equipo_id'], $tipo, $min, mb_substr(post('nota'), 0, 200)]);
            }
            [$cl, $cv] = marcador_desde_eventos($p, 'informe_eventos');
            q('UPDATE informes SET goles_local = ?, goles_visitante = ? WHERE partido_id = ?', [$cl, $cv, $pid]);
            flash(nombre_evento($tipo, $dep) . ' registrado: ' . nombre_jugador($j) . " · marcador del informe $cl - $cv");
        } elseif ($accion === 'borrar_evento') {
            q('DELETE FROM informe_eventos WHERE id = ? AND partido_id = ?', [(int)post('evento_id'), $pid]);
            [$cl, $cv] = marcador_desde_eventos($p, 'informe_eventos');
            q('UPDATE informes SET goles_local = ?, goles_visitante = ? WHERE partido_id = ?', [$cl, $cv, $pid]);
            flash('Registro borrado.');
        } elseif ($accion === 'fotos') {
            $rutas = subir_fotos_privadas('fotos');
            if (!$rutas) throw new RuntimeException('Elija al menos una foto.');
            foreach ($rutas as $r) {
                q('INSERT INTO fotos_partido (partido_id, archivo, descripcion, subido_por) VALUES (?,?,?,?)', [$pid, $r, mb_substr(post('descripcion'), 0, 200), 'Árbitro: ' . $u['nombre']]);
            }
            flash(count($rutas) . ' foto(s) guardada(s).');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    ir($url);
}

$susp = [];
$jl = jugadores_para_partido($p, (int)$p['local_id'], $susp);
$jv = jugadores_para_partido($p, (int)$p['visitante_id'], $susp);
$iev = filas('SELECT ie.*, j.nombres, j.apellidos, j.numero FROM informe_eventos ie JOIN jugadores j ON j.id = ie.jugador_id WHERE ie.partido_id = ? ORDER BY (ie.minuto IS NULL), ie.minuto, ie.id', [$pid]);
$fotos = filas('SELECT * FROM fotos_partido WHERE partido_id = ? ORDER BY id DESC', [$pid]);
[$cl, $cv] = marcador_desde_eventos($p, 'informe_eventos');

cabecera_arbitro('Informe');
?>
<p><a href="arbitro.php">← Mis partidos</a></p>
<p class="suave" style="margin-bottom:4px"><?= e($liga['nombre'] . ' · ' . $p['torneo']) ?> · <?= $p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : e(nombre_fase($p['fase'])) ?></p>
<?= tarjeta_partido($p) ?>

<?php if ($enviado): ?>
  <div class="aviso aviso-ok">✓ Informe enviado el <?= e($inf['enviado_en']) ?>. Si necesita corregirlo, pida a la liga que lo reabra.</div>
<?php else: ?>
  <div class="aviso aviso-info">Registre los goles y tarjetas que usted vio, la hora de inicio y el informe. Presione <b>Enviar informe</b> al final. La liga comparará su informe con la vocalía.</div>
<?php endif; ?>

<div class="rejilla-2">
  <div class="tarjeta">
    <h2 style="margin-top:0"><?= $dep === 'basquet' ? 'Canastas y faltas' : ($dep === 'ecuavoley' ? 'Tarjetas' : 'Goles y tarjetas') ?></h2>
    <ul class="eventos">
      <?php foreach ($iev as $x): ?>
        <li><span class="minuto"><?= $x['minuto'] !== null ? (int)$x['minuto'] . "'" : '—' ?></span><span><?= icono_evento($x['tipo'], $dep) ?></span>
          <span><?= e(nombre_jugador($x)) ?><?= $x['numero'] !== null ? ' #' . (int)$x['numero'] : '' ?><br><small><?= (int)$x['equipo_id'] === (int)$p['local_id'] ? e($p['local_nombre']) : e($p['visitante_nombre']) ?><?= $x['nota'] !== '' ? ' · ' . e($x['nota']) : '' ?></small></span>
          <?php if (!$enviado): ?><form class="inline" method="post" style="margin-left:auto" onsubmit="return confirm('¿Borrar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_evento"><input type="hidden" name="evento_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></li>
      <?php endforeach; ?>
      <?php if (!$iev): ?><li class="suave">Sin registros todavía.</li><?php endif; ?>
    </ul>
    <?php if (!$enviado): ?>
    <h3>Agregar</h3>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="evento">
      <p><label>Jugador</label><select name="jugador_id" required><?= opciones_jugadores_partido($p, $jl, $jv) ?></select></p>
      <div class="campos">
        <div><label>Tipo</label><select name="tipo"><?= opciones_tipo_evento($reglas) ?></select></div>
        <div><label>Minuto</label><input type="number" name="minuto" min="0" max="<?= $max_minuto ?>" inputmode="numeric"></div>
        <div><label>Cantidad</label><input type="number" name="cantidad" min="1" max="20" value="1"></div>
        <div class="campo-ancho"><label>Motivo / detalle</label><input type="text" name="nota" maxlength="200" placeholder="Ej: roja directa por agresión a un rival"></div>
      </div>
      <button class="btn">Agregar</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="tarjeta">
    <h2 style="margin-top:0">Datos del informe</h2>
    <form method="post">
      <?= csrf_campo() ?>
      <div class="campos">
        <div><label>Hora de inicio *</label><input type="time" name="hora_inicio" value="<?= e($inf['hora_inicio'] ?? '') ?>" <?= $enviado ? 'disabled' : '' ?>></div>
        <div><label>Hora de finalización</label><input type="time" name="hora_fin" value="<?= e($inf['hora_fin'] ?? '') ?>" <?= $enviado ? 'disabled' : '' ?>></div>
        <div><label><?= e($T['goles']) ?> <?= e($p['local_nombre']) ?> *</label><input type="number" name="goles_local" min="0" max="300" value="<?= e($inf['goles_local'] ?? $cl) ?>" <?= $enviado ? 'disabled' : '' ?>></div>
        <div><label><?= e($T['goles']) ?> <?= e($p['visitante_nombre']) ?> *</label><input type="number" name="goles_visitante" min="0" max="300" value="<?= e($inf['goles_visitante'] ?? $cv) ?>" <?= $enviado ? 'disabled' : '' ?>></div>
        <div class="campo-ancho"><label>Informe del árbitro / incidentes</label><textarea name="incidentes" maxlength="5000" style="min-height:140px" placeholder="Describa expulsiones, agresiones, faltas graves, comportamiento de jugadores, dirigentes o barra..." <?= $enviado ? 'disabled' : '' ?>><?= e($inf['incidentes'] ?? '') ?></textarea></div>
      </div>
      <?php if (!$enviado): ?>
        <label class="check" style="font-weight:400;margin-bottom:10px"><input type="checkbox" name="confirmo" value="1"> Confirmo el marcador aunque no coincida con los goles registrados</label>
        <div class="acciones">
          <button class="btn btn-sec" name="accion" value="guardar">Guardar borrador</button>
          <button class="btn" name="accion" value="enviar" onclick="return confirm('Después de enviar ya no podrá modificar el informe. ¿Enviar?')">Enviar informe</button>
        </div>
      <?php endif; ?>
    </form>
    <p style="margin-top:14px"><a class="btn btn-sec" target="_blank" href="<?= e($url) ?>&amp;imprimir=1">🖨 Imprimir / PDF del informe</a></p>
  </div>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">Fotos internas 🔒</h2>
  <?php if (!$enviado): ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="fotos">
    <div class="campos"><div><input type="file" name="fotos[]" accept="image/*" multiple required></div><div><input type="text" name="descripcion" maxlength="200" placeholder="Descripción"></div></div>
    <button class="btn btn-sec">Subir fotos</button>
  </form>
  <?php endif; ?>
  <?php if ($fotos): ?><div class="galeria" style="margin-top:10px"><?php foreach ($fotos as $f): ?><figure><a href="foto.php?id=<?= (int)$f['id'] ?>" target="_blank"><img src="foto.php?id=<?= (int)$f['id'] ?>" alt=""></a><figcaption><?= e($f['descripcion'] ?: '—') ?><br><small><?= e($f['subido_por']) ?></small></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
</div>
<?php
pie_admin();
