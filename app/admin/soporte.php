<?php
/*
 * (v27) SOPORTE: tickets y pedidos de funcionalidad.
 *  - Liga / escuela: abre tickets (problema, pregunta, funcionalidad, pagos…), ve las respuestas y contesta.
 *  - Superadministrador: ve todos, responde, cambia estado y prioridad; cada ticket nuevo le llega por Telegram.
 */
$u = usuario();
$super = es_super();
$liga = liga_trabajo();
if (!$super && !$liga) throw new RuntimeException('Elija primero una liga o escuela.');
$demo = !$super && $liga && !empty($liga['es_demo']);
$wa_ventas = telefono_wa((string)ajuste('ventas_whatsapp'));

// Captura adjunta (privada): solo quien puede ver el ticket
if (get('adjunto') !== '') {
    $m = fila('SELECT * FROM soporte_mensajes WHERE id = ?', [(int)get('adjunto')]);
    if (!$m) { http_response_code(404); exit('No encontrado'); }
    ticket_visible((int)$m['ticket_id']);
    $ruta = (string)$m['adjunto'];
    $abs = RAIZ . '/data/' . $ruta;
    if ($ruta === '' || str_contains($ruta, '..') || !str_starts_with($ruta, 'fotos/') || !is_file($abs)) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($abs);
    exit;
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'crear') {
        if ($super) throw new RuntimeException('El superadministrador responde los tickets; no los abre.');
        if ($demo) throw new RuntimeException('En la demostración no se abren tickets. Escríbanos por WhatsApp.');
        $tipo = post('tipo');
        $d = ['tipo' => $tipo, 'asunto' => post('asunto'), 'prioridad' => post('prioridad'), 'telefono' => post('telefono'), 'pagina' => post('pagina'), 'adjunto' => adjunto_ticket('captura')];
        if ($tipo === 'funcionalidad') {
            $resp = [];
            $txt = [];
            foreach (preguntas_funcionalidad() as $k => [$preg]) {
                $resp[$k] = mb_substr(trim(post($k)), 0, 2000);
                if (str_ends_with($preg, '*') && mb_strlen($resp[$k]) < 10) throw new RuntimeException('Responda: ' . rtrim($preg, ' *'));
                if ($resp[$k] !== '') $txt[] = '▸ ' . rtrim($preg, ' *') . "\n" . $resp[$k];
            }
            $imp = array_key_exists(post('importancia'), importancias_funcionalidad()) ? post('importancia') : 'me_ayudaria';
            $resp['importancia'] = $imp;
            $txt[] = '▸ Importancia: ' . importancias_funcionalidad()[$imp];
            $d['texto'] = implode("\n\n", $txt);
            $d['detalle'] = $resp;
            $d['prioridad'] = $imp === 'indispensable' ? 'alta' : 'normal';
        } else {
            $d['texto'] = post('texto');
        }
        $tid = crear_ticket($liga, $d);
        flash('¡Listo! Su ' . ($tipo === 'funcionalidad' ? 'pedido' : 'ticket') . ' #' . $tid . ' llegó a soporte. Le responderemos aquí mismo' . (tg_chats_admins((int)$liga['id']) ? ' y le avisaremos por Telegram' : '') . '.');
        ir('admin.php?p=soporte&t=' . $tid);
    }
    if ($accion === 'responder') {
        $t = ticket_visible((int)post('id'));
        if ($demo) throw new RuntimeException('En la demostración no se puede escribir a soporte.');
        $estado = $super ? post('estado') : null;
        responder_ticket($t, $super ? 'soporte' : 'cliente', mb_substr(post('texto'), 0, 6000), adjunto_ticket('captura'), $estado ?: null);
        if ($super && array_key_exists(post('prioridad'), prioridades_ticket())) q('UPDATE soporte_tickets SET prioridad = ? WHERE id = ?', [post('prioridad'), $t['id']]);
        flash($super ? 'Respuesta enviada' . (tg_chats_admins((int)$t['liga_id']) ? ' (y avisada por Telegram)' : '') . '.' : 'Mensaje enviado a soporte.');
        ir('admin.php?p=soporte&t=' . (int)$t['id']);
    }
    if ($accion === 'estado') {
        $t = ticket_visible((int)post('id'));
        $nuevo = post('estado');
        if (!$super && $nuevo !== 'resuelto') throw new RuntimeException('Acción no permitida.');
        if (!array_key_exists($nuevo, estados_ticket())) throw new RuntimeException('Estado no válido.');
        q('UPDATE soporte_tickets SET estado = ?, actualizado = ?, resuelto_en = ? WHERE id = ?', [$nuevo, date('Y-m-d H:i:s'), in_array($nuevo, ['resuelto', 'cerrado'], true) ? date('Y-m-d H:i:s') : '', $t['id']]);
        if ($super && array_key_exists(post('prioridad'), prioridades_ticket())) q('UPDATE soporte_tickets SET prioridad = ? WHERE id = ?', [post('prioridad'), $t['id']]);
        if (!$super) aviso_super('✅ La cuenta ' . e($t['liga'] ?? '') . ' marcó como resuelto el ticket #' . (int)$t['id'] . ': ' . e($t['asunto']), 'tres:' . $t['id']);
        flash('Ticket actualizado.');
        ir('admin.php?p=soporte&t=' . (int)$t['id']);
    }
    if ($accion === 'tg_prueba' && $super) {
        if (!tg_activo()) throw new RuntimeException('Primero ponga el token del bot en Ajustes → Bot de Telegram.');
        if (!chats_super()) throw new RuntimeException('Primero conecte su Telegram en Mi cuenta → Conectar Telegram.');
        aviso_super('🔔 <b>Prueba de avisos de ' . e(marca()) . '</b>' . "\nAsí le llegarán los pedidos de demo, los tickets y los pedidos de funcionalidad.", 'prueba_super:' . time());
        $n = tg_procesar_cola();
        flash($n ? 'Mensaje de prueba enviado. Revise su Telegram.' : 'El mensaje quedó en cola. Si en 2 minutos no llega, revise el estado de abajo.', $n ? 'ok' : 'error');
        ir('admin.php?p=soporte');
    }
}

$t = (int)get('t') ? ticket_visible((int)get('t')) : null;
if ($t) {
    q('UPDATE soporte_tickets SET ' . ($super ? 'sin_leer_super' : 'sin_leer_cliente') . ' = 0 WHERE id = ?', [$t['id']]);
}
cabecera_admin('Soporte');

/* ====================== UN TICKET ====================== */
if ($t):
    $msgs = filas('SELECT * FROM soporte_mensajes WHERE ticket_id = ? ORDER BY id', [$t['id']]);
    [$ico, $tipo_nom] = tipos_ticket()[$t['tipo']] ?? ['✉️', 'Otro'];
    $tel = $t['telefono'] !== '' ? $t['telefono'] : (string)$t['contacto_telefono'];
    $wa = $super ? telefono_wa($tel) : '';
    ?>
    <p><a href="admin.php?p=soporte">← <?= $super ? 'Todos los tickets' : 'Mis tickets' ?></a></p>
    <div class="fila-titulo"><h1 style="margin:0"><?= $ico ?> #<?= (int)$t['id'] ?> · <?= e($t['asunto']) ?></h1><?= chip_ticket($t['estado']) ?></div>
    <p class="suave"><?= e($tipo_nom) ?> · Prioridad: <?= e(prioridades_ticket()[$t['prioridad']] ?? $t['prioridad']) ?> · Abierto el <?= e(fecha_bonita($t['creado'])) ?> <?= e(substr($t['creado'], 11, 5)) ?>
      <?= $super ? ' · <b>' . e($t['liga'] ?? 'sin cuenta') . '</b> (' . e(($t['liga_tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga') . ') · ' . e($t['autor']) . ($t['email'] !== '' ? ' · ' . e($t['email']) : '') . ($tel !== '' ? ' · 📞 ' . e($tel) : '') : '' ?>
      <?= $t['pagina'] !== '' ? ' · Desde la pantalla: <code>' . e($t['pagina']) . '</code>' : '' ?></p>
    <?php if ($wa): ?><p><a class="btn btn-sec btn-chico" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $t['autor'] . ', le escribo de soporte de ' . marca() . ' por su ticket #' . $t['id'] . ': ' . $t['asunto']) ?>">💬 Escribirle por WhatsApp</a>
      <?php if ($t['liga_id']): ?> <a class="btn btn-sec btn-chico" href="admin.php?p=ligas&amp;entrar=<?= (int)$t['liga_id'] ?>">Ver su cuenta</a><?php endif; ?></p><?php endif; ?>

    <div class="conversacion">
      <?php foreach ($msgs as $m): $mio = ($m['de'] === 'soporte') === $super; ?>
        <div class="burbuja <?= $m['de'] === 'soporte' ? 'burbuja-soporte' : 'burbuja-cliente' ?> <?= $mio ? 'mia' : '' ?>">
          <div class="burbuja-cab"><b><?= $m['de'] === 'soporte' ? '🛟 ' . e($m['autor'] ?: 'Soporte') : '👤 ' . e($m['autor']) ?></b> <small class="suave"><?= e(fecha_bonita($m['creado'])) ?> <?= e(substr($m['creado'], 11, 5)) ?></small></div>
          <div style="white-space:pre-wrap"><?= e($m['texto']) ?></div>
          <?php if ($m['adjunto'] !== ''): ?><a href="admin.php?p=soporte&amp;adjunto=<?= (int)$m['id'] ?>" target="_blank"><img src="admin.php?p=soporte&amp;adjunto=<?= (int)$m['id'] ?>" alt="Captura" style="max-width:260px;border-radius:8px;margin-top:6px"></a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$demo): ?>
    <div class="tarjeta">
      <h2 style="margin-top:0"><?= $super ? 'Responder' : 'Escribir a soporte' ?></h2>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="responder"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <textarea name="texto" rows="4" placeholder="<?= $super ? 'Escriba la respuesta para la liga o escuela…' : 'Escriba aquí…' ?>"></textarea>
        <div class="campos">
          <div><label>Captura de pantalla (opcional)</label><input type="file" name="captura" accept="image/*"></div>
          <?php if ($super): ?>
            <div><label>Estado después de responder</label><select name="estado"><?php foreach (estados_ticket() as $k => $n): ?><option value="<?= $k ?>" <?= $k === ($t['estado'] === 'nuevo' ? 'esperando' : $t['estado']) ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div><label>Prioridad</label><select name="prioridad"><?php foreach (prioridades_ticket() as $k => $n): ?><option value="<?= $k ?>" <?= $k === $t['prioridad'] ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
        </div>
        <button class="btn">Enviar</button>
      </form>
      <?php if (!$super && !in_array($t['estado'], ['resuelto', 'cerrado'], true)): ?>
        <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="estado" value="resuelto">
          <button class="btn btn-sec btn-chico">✅ Ya está resuelto, gracias</button></form>
      <?php endif; ?>
      <?php if ($super): ?>
        <form method="post" style="margin-top:10px" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <select name="estado"><?php foreach (estados_ticket() as $k => $n): ?><option value="<?= $k ?>" <?= $k === $t['estado'] ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
          <select name="prioridad"><?php foreach (prioridades_ticket() as $k => $n): ?><option value="<?= $k ?>" <?= $k === $t['prioridad'] ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
          <button class="btn btn-sec btn-chico">Cambiar sin responder</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

<?php
/* ====================== NUEVO TICKET (liga / escuela) ====================== */
elseif (!$super && get('nuevo') !== ''):
    $tipo = array_key_exists(get('nuevo'), tipos_ticket()) ? get('nuevo') : 'problema';
    $desde = mb_substr(get('desde'), 0, 60);
    ?>
    <p><a href="admin.php?p=soporte">← Mis tickets</a></p>
    <?php if ($demo): ?>
      <h1>🎫 Soporte</h1>
      <div class="tarjeta"><p>En la <b>demostración</b> no se abren tickets. ¿Tiene una pregunta o quiere pedir una funcionalidad? Escríbanos y le ayudamos enseguida.</p>
        <?php if ($wa_ventas): ?><a class="btn" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa_ventas) ?>?text=<?= rawurlencode('Hola, estoy probando la demo de ' . marca() . ' y tengo una consulta: ') ?>">💬 Escribir por WhatsApp</a><?php endif; ?>
        <a class="btn btn-sec" href="<?= e(url_empezar()) ?>">Solicitar mi demo de <?= (int)ajuste('dias_demo', 7) ?> días</a></div>
    <?php elseif ($tipo === 'funcionalidad'): ?>
      <h1>💡 Pedir una funcionalidad nueva</h1>
      <p class="suave">Cuéntenos con detalle qué le gustaría que haga <?= e(marca()) ?>. Mientras más claro, más rápido lo podemos evaluar y desarrollar. Le avisamos aquí cuando tengamos novedades.</p>
      <form method="post" enctype="multipart/form-data" class="tarjeta">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="crear"><input type="hidden" name="tipo" value="funcionalidad"><input type="hidden" name="pagina" value="<?= e($desde) ?>">
        <label>Póngale un nombre corto *</label><input name="asunto" required maxlength="120" placeholder="Ej: Que el delegado suba el comprobante de pago desde el celular">
        <?php foreach (preguntas_funcionalidad() as $k => [$preg, $ayuda]): ?>
          <label style="margin-top:10px"><?= e($preg) ?></label>
          <textarea name="<?= $k ?>" rows="<?= in_array($k, ['que', 'para_que'], true) ? 4 : 2 ?>" <?= str_ends_with($preg, '*') ? 'required minlength="10"' : '' ?> placeholder="<?= e($ayuda) ?>"></textarea>
        <?php endforeach; ?>
        <label style="margin-top:10px">¿Qué tan importante es para usted?</label>
        <div><?php foreach (importancias_funcionalidad() as $k => $n): ?><label class="check" style="font-weight:400"><input type="radio" name="importancia" value="<?= $k ?>" <?= $k === 'me_ayudaria' ? 'checked' : '' ?>> <?= e($n) ?></label><?php endforeach; ?></div>
        <div class="campos" style="margin-top:10px">
          <div><label>Una imagen o captura de ejemplo (opcional)</label><input type="file" name="captura" accept="image/*"></div>
          <div><label>Su celular (por si necesitamos preguntarle algo)</label><input name="telefono" value="<?= e((string)($liga['contacto_telefono'] ?? '')) ?>"></div>
        </div>
        <button class="btn btn-grande">💡 Enviar mi pedido</button>
      </form>
    <?php else: ?>
      <h1>🎫 Nuevo ticket de soporte</h1>
      <form method="post" enctype="multipart/form-data" class="tarjeta">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="crear"><input type="hidden" name="pagina" value="<?= e($desde) ?>">
        <div class="campos">
          <div><label>¿Sobre qué es?</label><select name="tipo"><?php foreach (tipos_ticket() as $k => [$i, $n]): if ($k === 'funcionalidad') continue; ?><option value="<?= $k ?>" <?= $k === $tipo ? 'selected' : '' ?>><?= $i ?> <?= e($n) ?></option><?php endforeach; ?></select></div>
          <div><label>Prioridad</label><select name="prioridad"><?php foreach (prioridades_ticket() as $k => $n): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        </div>
        <label>Asunto *</label><input name="asunto" required maxlength="120" placeholder="Ej: No me deja imprimir los carnets">
        <label style="margin-top:10px">Cuéntenos qué pasa *</label>
        <textarea name="texto" rows="6" required minlength="10" placeholder="¿Qué estaba haciendo? ¿Qué esperaba que pasara y qué pasó? Si sale un mensaje de error, cópielo aquí."></textarea>
        <div class="campos" style="margin-top:10px">
          <div><label>Captura de pantalla (opcional, ayuda mucho)</label><input type="file" name="captura" accept="image/*"></div>
          <div><label>Su celular</label><input name="telefono" value="<?= e((string)($liga['contacto_telefono'] ?? '')) ?>"></div>
        </div>
        <?php if ($desde !== ''): ?><p class="suave" style="font-size:.85rem">Pantalla desde la que escribe: <code><?= e($desde) ?></code></p><?php endif; ?>
        <button class="btn btn-grande">🎫 Enviar a soporte</button>
      </form>
      <p class="suave">¿Quiere pedir algo nuevo para el sistema? Use <a href="admin.php?p=soporte&amp;nuevo=funcionalidad&amp;desde=<?= e(rawurlencode($desde)) ?>">💡 Pedir una funcionalidad</a>.</p>
    <?php endif; ?>

<?php
/* ====================== LISTA ====================== */
else:
    $f_estado = get('estado', $super ? 'abiertos' : 'todos');
    $f_tipo = array_key_exists(get('tipo'), tipos_ticket()) ? get('tipo') : '';
    $w = [];
    $par = [];
    if (!$super) { $w[] = 't.liga_id = ?'; $par[] = $liga['id']; }
    if ($f_estado === 'abiertos') $w[] = "t.estado NOT IN ('resuelto','cerrado')";
    elseif (array_key_exists($f_estado, estados_ticket())) { $w[] = 't.estado = ?'; $par[] = $f_estado; }
    if ($f_tipo !== '') { $w[] = 't.tipo = ?'; $par[] = $f_tipo; }
    if ($super && get('buscar') !== '') { $w[] = '(l.nombre LIKE ? OR t.asunto LIKE ? OR t.autor LIKE ?)'; $b = '%' . get('buscar') . '%'; array_push($par, $b, $b, $b); }
    $lista = filas('SELECT t.*, l.nombre AS liga, (SELECT COUNT(*) FROM soporte_mensajes m WHERE m.ticket_id = t.id) AS n_msg FROM soporte_tickets t LEFT JOIN ligas l ON l.id = t.liga_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY CASE t.prioridad WHEN 'urgente' THEN 0 WHEN 'alta' THEN 1 ELSE 2 END, t.actualizado DESC LIMIT 300", $par);
    ?>
    <div class="fila-titulo"><h1>🎫 <?= $super ? 'Tickets de soporte' : 'Soporte' ?></h1>
      <?php if (!$super && !$demo): ?><span class="acciones"><a class="btn" href="admin.php?p=soporte&amp;nuevo=problema">🎫 Nuevo ticket</a> <a class="btn btn-sec" href="admin.php?p=soporte&amp;nuevo=funcionalidad">💡 Pedir una funcionalidad</a></span><?php endif; ?></div>

    <?php if ($super): $ea = estado_avisos_super(); ?>
      <div class="tarjeta" id="avisos">
        <h2 style="margin-top:0">📲 Avisos a su Telegram (pedidos de demo, tickets, funcionalidades y demos por vencer)</h2>
        <?php if ($ea['ok']): ?>
          <p>✅ Funcionando<?= ajuste('tg_usuario') !== '' ? ' con el bot <b>@' . e(ajuste('tg_usuario')) . '</b>' : '' ?>. Tarea automática: última vez <?= e($ea['ultima']) ?> · Mensajes en cola: <?= (int)$ea['pendientes'] ?>.</p>
        <?php else: ?>
          <div class="aviso aviso-error"><b>Los avisos NO le están llegando:</b><ul style="margin:.3rem 0 0"><?php foreach ($ea['problemas'] as $pr): ?><li><?= e($pr) ?></li><?php endforeach; ?></ul></div>
          <p class="suave">Arréglelo en <a href="admin.php?p=ajustes#telegram">Ajustes → Bot de Telegram</a> y en <a href="admin.php?p=seguridad">Mi cuenta → Conectar Telegram</a>.</p>
        <?php endif; ?>
        <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_prueba"><button class="btn btn-sec btn-chico">Enviarme un aviso de prueba</button></form>
      </div>
      <form method="get" class="filtros">
        <input type="hidden" name="p" value="soporte">
        <select name="estado" onchange="this.form.submit()"><option value="abiertos" <?= $f_estado === 'abiertos' ? 'selected' : '' ?>>Abiertos</option><option value="todos" <?= $f_estado === 'todos' ? 'selected' : '' ?>>Todos</option>
          <?php foreach (estados_ticket() as $k => $n): ?><option value="<?= $k ?>" <?= $f_estado === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
        <select name="tipo" onchange="this.form.submit()"><option value="">Todos los tipos</option><?php foreach (tipos_ticket() as $k => [$i, $n]): ?><option value="<?= $k ?>" <?= $f_tipo === $k ? 'selected' : '' ?>><?= $i ?> <?= e($n) ?></option><?php endforeach; ?></select>
        <input name="buscar" value="<?= e(get('buscar')) ?>" placeholder="Buscar liga, asunto o persona"><button class="btn btn-sec btn-chico">Buscar</button>
      </form>
    <?php elseif ($demo): ?>
      <div class="aviso aviso-info">En la demostración no se abren tickets. <?php if ($wa_ventas): ?><a target="_blank" rel="noopener" href="https://wa.me/<?= e($wa_ventas) ?>">Escríbanos por WhatsApp</a> y le ayudamos.<?php endif; ?></div>
    <?php else: ?>
      <p class="suave">¿Algo no funciona o tiene una duda? Abra un ticket: le llega directo al equipo de <?= e(marca()) ?> y le respondemos aquí mismo. También puede ver el <a href="manual/<?= es_escuela($liga) ? 'escuelas' : 'ligas' ?>" target="_blank">manual</a>.</p>
    <?php endif; ?>

    <?php if (!$lista): ?>
      <div class="tarjeta vacio"><?= $super ? 'No hay tickets con ese filtro.' : 'Todavía no tiene tickets.' ?></div>
    <?php else: ?>
      <div class="tabla-scroll"><table>
        <tr><th>#</th><?= $super ? '<th>Cuenta</th>' : '' ?><th>Asunto</th><th>Estado</th><th>Prioridad</th><th>Actualizado</th></tr>
        <?php foreach ($lista as $x): $nuevo = $super ? (int)$x['sin_leer_super'] : (int)$x['sin_leer_cliente']; [$ico] = tipos_ticket()[$x['tipo']] ?? ['✉️']; ?>
          <tr style="<?= $nuevo ? 'font-weight:700' : '' ?>"><td><?= (int)$x['id'] ?></td>
            <?= $super ? '<td>' . e($x['liga'] ?? '—') . '</td>' : '' ?>
            <td><a href="admin.php?p=soporte&amp;t=<?= (int)$x['id'] ?>"><?= $ico ?> <?= e($x['asunto']) ?></a><?= $nuevo ? ' <span class="etiqueta roja">nuevo</span>' : '' ?> <small class="suave">(<?= (int)$x['n_msg'] ?>)</small></td>
            <td><?= chip_ticket($x['estado']) ?></td><td><?= e(prioridades_ticket()[$x['prioridad']] ?? '') ?></td><td><?= e(fecha_bonita($x['actualizado'])) ?> <?= e(substr($x['actualizado'], 11, 5)) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
<?php endif;
pie_admin();
