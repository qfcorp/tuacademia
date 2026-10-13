<?php
/* (v10) PEDIDOS DE LOS DELEGADOS: reprogramar partidos, descargos y apelaciones. La liga aprueba o rechaza y queda registrado. */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$yo = usuario();

// Foto o documento adjunto por el delegado
if (get('archivo') !== '') {
    $pd = fila('SELECT * FROM pedidos_delegado WHERE id = ? AND liga_id = ?', [(int)get('archivo'), $lid]);
    $abs = $pd && $pd['archivo'] !== '' ? RAIZ . '/data/' . $pd['archivo'] : '';
    if (!$pd || !is_file($abs) || str_contains($pd['archivo'], '..')) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    readfile($abs);
    exit;
}

if (es_post()) {
    $pd = fila("SELECT * FROM pedidos_delegado WHERE id = ? AND liga_id = ?", [(int)post('id'), $lid]) ?? throw new RuntimeException('Pedido no encontrado.');
    if ($pd['estado'] !== 'pendiente') throw new RuntimeException('Ese pedido ya fue respondido.');
    $accion = post('accion');
    $es_disc = in_array($pd['tipo'], ['descargo', 'apelacion'], true);
    if ($accion !== ($es_disc ? 'resolver_disciplina' : 'resolver_reprogramacion')) throw new RuntimeException('Acción no válida.');
    $aprobar = post('decision') === 'aprobar';
    $resp = mb_substr(post('respuesta'), 0, 1000);
    if (!$aprobar && mb_strlen($resp) < 5) throw new RuntimeException('Al rechazar, escriba el motivo para el delegado.');
    $eq = fila('SELECT * FROM equipos WHERE id = ?', [$pd['equipo_id']]);
    $extra = '';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $avisar_prog = null;
        $torneo_sync = null;
        if ($aprobar && $pd['tipo'] === 'reprogramacion' && $pd['partido_id'] && post('aplicar') === '1') {
            $p = partido_de_liga((int)$pd['partido_id'], $lid);
            if (partido_cerrado($p)) throw new RuntimeException('Ese partido ya se jugó: no se puede reprogramar.');
            $fecha = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '';
            $hora = hora_valida(post('hora'));
            q("UPDATE partidos SET fecha = ?, hora = ?, cancha = ?, estado = CASE WHEN estado IN ('suspendido','postergado') THEN 'programado' ELSE estado END WHERE id = ?",
                [$fecha, $hora, mb_substr(post('cancha'), 0, 60), $p['id']]);
            $extra = ' Nueva fecha: ' . ($fecha !== '' ? fecha_bonita($fecha) : 'por definir') . ($hora !== '' ? ' ' . $hora : '') . (post('cancha') !== '' ? ' · ' . post('cancha') : '') . '.';
            if (post('avisar') === '1') $avisar_prog = [(int)$p['torneo_id'], (int)$p['id']];
        }
        if ($aprobar && $pd['tipo'] === 'apelacion' && $pd['sancion_id'] && post('cambio') !== '') {
            $s = fila('SELECT s.* FROM sanciones s JOIN torneos t ON t.id = s.torneo_id WHERE s.id = ? AND t.liga_id = ?', [$pd['sancion_id'], $lid]);
            if ($s && post('cambio') === 'anular') {
                q('DELETE FROM sanciones WHERE id = ?', [$s['id']]);
                $extra = ' La sanción quedó ANULADA.';
            } elseif ($s && post('cambio') === 'reducir') {
                $n = entero(post('partidos'), 0, 99);
                $m = max(0, round((float)str_replace(',', '.', post('multa')), 2));
                q('UPDATE sanciones SET partidos = ?, multa = ? WHERE id = ?', [$n, $m, $s['id']]);
                q("DELETE FROM movimientos WHERE ref = ? AND tipo = 'cargo' AND anulado = 0", ['multa_j:' . $s['id']]);
                $extra = ' La sanción queda en ' . $n . ' partido(s)' . ($m > 0 ? ' y multa de ' . dinero($m) : '') . '.';
            }
        }
        if ($aprobar && $pd['tipo'] === 'apelacion' && $pd['sancion_equipo_id'] && post('cambio') !== '') {
            $s = fila('SELECT s.* FROM sanciones_equipo s JOIN torneos t ON t.id = s.torneo_id WHERE s.id = ? AND t.liga_id = ?', [$pd['sancion_equipo_id'], $lid]);
            if ($s && post('cambio') === 'anular') {
                q('DELETE FROM sanciones_equipo WHERE id = ?', [$s['id']]);
                $extra = ' La sanción al equipo quedó ANULADA.';
            } elseif ($s && post('cambio') === 'reducir') {
                $pts = entero(post('puntos'), 0, 99);
                $m = max(0, round((float)str_replace(',', '.', post('multa')), 2));
                q('UPDATE sanciones_equipo SET puntos = ?, multa = ? WHERE id = ?', [$pts, $m, $s['id']]);
                q("DELETE FROM movimientos WHERE ref = ? AND tipo = 'cargo' AND anulado = 0", ['multa_e:' . $s['id']]);
                $extra = ' La sanción queda en ' . ($pts ? '−' . $pts . ' puntos' : 'sin resta de puntos') . ($m > 0 ? ' y multa de ' . dinero($m) : ', sin multa') . '.';
            }
        }
        if (!empty($s)) $torneo_sync = (int)$s['torneo_id'];
        $resp_final = trim(($resp !== '' ? rtrim($resp, '. ') . '.' : '') . $extra);
        q('UPDATE pedidos_delegado SET estado = ?, respuesta = ?, resuelto_por = ?, resuelto_en = ? WHERE id = ?',
            [$aprobar ? 'aprobado' : 'rechazado', $resp_final, $yo['nombre'] . ' (' . nombre_perfil(perfil_actual()) . ')', date('Y-m-d H:i:s'), $pd['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // Avisos: al delegado que pidió; si se reprogramó, a los dos equipos, árbitros y canal
    aviso_delegados($liga, (int)$pd['equipo_id'], '📨 <b>Su pedido fue ' . ($aprobar ? 'APROBADO ✅' : 'RECHAZADO ❌') . '</b>' . "\n" . e(nombre_tipo_pedido($pd['tipo'])) . ': ' . e(detalle_pedido($pd))
        . ($resp_final !== '' ? "\n<b>Respuesta:</b> " . e($resp_final) : ''), 'pedres:' . $pd['id']);
    if (!empty($avisar_prog)) aviso_programacion($avisar_prog[0], [$avisar_prog[1]]);
    if ($torneo_sync) sincronizar_cargos($torneo_sync); // la multa del estado de cuenta queda con el valor nuevo
    flash('Pedido de ' . ($eq['nombre'] ?? 'equipo') . ' ' . ($aprobar ? 'aprobado' : 'rechazado') . '.' . $extra . ' El delegado ve la respuesta en su portal.');
    ir('admin.php?p=pedidos');
}

$filtro = get('ver', 'pendiente');
if (!in_array($filtro, ['pendiente', 'todos'], true)) $filtro = 'pendiente';
$pedidos = filas('SELECT pd.*, e.nombre AS equipo, e.escudo, e.color, u.nombre AS delegado FROM pedidos_delegado pd JOIN equipos e ON e.id = pd.equipo_id LEFT JOIN usuarios u ON u.id = pd.usuario_id
                  WHERE pd.liga_id = ? ' . ($filtro === 'pendiente' ? "AND pd.estado = 'pendiente'" : '') . ' ORDER BY (pd.estado = \'pendiente\') DESC, pd.id DESC LIMIT 200', [$lid]);
$n_pend = pedidos_pendientes($lid);
cabecera_admin('Pedidos de delegados');
?>
<p><a href="admin.php?p=torneos">← Campeonatos</a></p>
<div class="fila-titulo"><h1>📨 Pedidos de los delegados</h1>
  <nav class="pestanas" style="margin:0"><a class="<?= $filtro === 'pendiente' ? 'activa' : '' ?>" href="admin.php?p=pedidos">Por responder (<?= $n_pend ?>)</a><a class="<?= $filtro === 'todos' ? 'activa' : '' ?>" href="admin.php?p=pedidos&amp;ver=todos">Todos</a></nav></div>
<p class="suave">Los delegados piden desde su portal: <b>reprogramar un partido</b>, presentar un <b>descargo</b> o <b>apelar una sanción</b>. Usted aprueba o rechaza con una respuesta; todo queda registrado y sale en el boletín de la fecha.</p>
<?php if (!$pedidos): ?><div class="tarjeta"><p class="suave" style="margin:0"><?= $filtro === 'pendiente' ? 'No hay pedidos por responder.' : 'Todavía no hay pedidos.' ?></p></div><?php endif; ?>
<?php foreach ($pedidos as $pd):
    $es_disc = in_array($pd['tipo'], ['descargo', 'apelacion'], true);
    $puede = puede($es_disc ? 'disciplina' : 'campeonatos', true);
    $p = $pd['partido_id'] ? partido_completo((int)$pd['partido_id']) : null;
    $s = $pd['sancion_id'] ? fila('SELECT * FROM sanciones WHERE id = ?', [$pd['sancion_id']]) : null;
    $se = $pd['sancion_equipo_id'] ? fila('SELECT * FROM sanciones_equipo WHERE id = ?', [$pd['sancion_equipo_id']]) : null; ?>
  <div class="tarjeta pedido <?= e($pd['estado']) ?>">
    <div class="fila-titulo" style="margin:0">
      <h3 style="margin:0"><?= escudo($pd['escudo'], $pd['equipo'], $pd['color'], 'mini') ?> <?= e(nombre_tipo_pedido($pd['tipo'])) ?> · <?= e($pd['equipo']) ?></h3>
      <span><?= chip_estado_pedido($pd['estado']) ?> <small class="suave"><?= e(substr($pd['creado'], 0, 16)) ?></small></span>
    </div>
    <p style="margin:.4rem 0"><b>Sobre:</b> <?= e(detalle_pedido($pd)) ?></p>
    <?php if ($pd['tipo'] === 'reprogramacion' && ($pd['fecha_propuesta'] !== '' || $pd['hora_propuesta'] !== '')): ?><p style="margin:.3rem 0"><b>Propone:</b> <?= e(trim(($pd['fecha_propuesta'] !== '' ? fecha_bonita($pd['fecha_propuesta']) : '') . ' ' . $pd['hora_propuesta'])) ?></p><?php endif; ?>
    <div class="caja-texto" style="border:1px solid var(--borde);border-radius:8px;padding:8px 10px;white-space:pre-wrap;background:#fafcfb"><?= e($pd['motivo']) ?></div>
    <p class="suave" style="margin:.4rem 0 0;font-size:.85rem">Enviado por <?= e($pd['delegado'] ?? 'el delegado') ?><?php if ($pd['archivo'] !== ''): ?> · <a target="_blank" href="admin.php?p=pedidos&amp;archivo=<?= (int)$pd['id'] ?>">📎 ver adjunto</a><?php endif; ?></p>
    <?php if ($pd['estado'] !== 'pendiente'): ?>
      <p style="margin:.5rem 0 0"><b>Respuesta:</b> <?= e($pd['respuesta'] ?: '—') ?> <small class="suave">(<?= e($pd['resuelto_por']) ?>, <?= e(substr($pd['resuelto_en'], 0, 16)) ?>)</small></p>
    <?php elseif (!$puede): ?>
      <p class="suave">Lo responde <?= $es_disc ? 'la comisión disciplinaria' : 'la secretaría (campeonatos)' ?>.</p>
    <?php else: ?>
      <form method="post" style="margin-top:10px">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $es_disc ? 'resolver_disciplina' : 'resolver_reprogramacion' ?>"><input type="hidden" name="id" value="<?= (int)$pd['id'] ?>">
        <?php if ($pd['tipo'] === 'reprogramacion' && $p && !partido_cerrado($p)): ?>
          <fieldset><legend>Si aprueba</legend>
            <label class="check"><input type="checkbox" name="aplicar" value="1" checked> Cambiar el partido a esta fecha</label>
            <div class="campos">
              <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($pd['fecha_propuesta'] !== '' ? $pd['fecha_propuesta'] : $p['fecha']) ?>"></div>
              <div><label>Hora</label><input type="time" name="hora" value="<?= e($pd['hora_propuesta'] !== '' ? $pd['hora_propuesta'] : $p['hora']) ?>"></div>
              <div><label>Cancha</label><input type="text" name="cancha" maxlength="60" value="<?= e($p['cancha']) ?>"></div>
            </div>
            <label class="check"><input type="checkbox" name="avisar" value="1" checked> Avisar la nueva programación (Telegram y notificaciones)</label>
          </fieldset>
        <?php elseif ($pd['tipo'] === 'apelacion' && ($s || $se)): ?>
          <fieldset><legend>Si aprueba la apelación</legend>
            <label class="check" style="font-weight:400"><input type="radio" name="cambio" value="" checked> Solo registrar la respuesta (no cambiar la sanción)</label>
            <label class="check" style="font-weight:400"><input type="radio" name="cambio" value="reducir"> Cambiar la sanción a:
              <?php if ($s): ?><input type="number" name="partidos" min="0" max="99" value="<?= (int)$s['partidos'] ?>" style="width:70px"> partido(s)<?php else: ?><input type="number" name="puntos" min="0" max="99" value="<?= (int)$se['puntos'] ?>" style="width:70px"> puntos menos<?php endif; ?>
              y multa $ <input type="number" name="multa" min="0" step="0.01" value="<?= e(number_format((float)($s['multa'] ?? $se['multa']), 2, '.', '')) ?>" style="width:90px"></label>
            <label class="check" style="font-weight:400"><input type="radio" name="cambio" value="anular"> Anular la sanción</label>
          </fieldset>
        <?php endif; ?>
        <p><label>Respuesta para el delegado</label><textarea name="respuesta" rows="2" maxlength="1000" placeholder="Ej: Se aprueba. El partido se jugará el domingo 12 a las 10:30."></textarea></p>
        <div class="acciones">
          <button class="btn" name="decision" value="aprobar">✅ Aprobar</button>
          <button class="btn btn-peligro" name="decision" value="rechazar">❌ Rechazar</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach;
pie_admin();
