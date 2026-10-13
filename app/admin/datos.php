<?php
/*
 * (v24) PROTECCIÓN DE DATOS (LOPDP)
 *  - Solicitudes: lo que piden los titulares (ver, corregir, borrar…). Responder, cambiar estado, días transcurridos (plazo 15 días).
 *  - Autorizaciones: prueba de cada autorización (fecha, IP, texto aceptado, versión). Filtro y descarga en Excel.
 *  - Superadministrador sin liga elegida: ve todo el sistema y puede cambiar la versión de la política.
 */
$u = usuario();
$liga = liga_trabajo();
if (!es_super() && !$liga) ir('admin.php');
$lid = $liga ? (int)$liga['id'] : null;
$editar = puede('config', true);
$s = get('s') === 'autorizaciones' ? 'autorizaciones' : (get('s') === 'ajustes' && es_super() ? 'ajustes' : 'solicitudes');
$filtro_liga = $lid !== null ? ['liga_id = ?', [$lid]] : ['1 = 1', []];

if (es_post()) {
    if (!$editar) throw new RuntimeException('Su perfil no puede cambiar esto.');
    $accion = post('accion');
    if ($accion === 'responder') {
        $sol = fila('SELECT * FROM solicitudes_derechos WHERE id = ? AND ' . $filtro_liga[0], array_merge([(int)post('id')], $filtro_liga[1])) ?? throw new RuntimeException('Solicitud no encontrada.');
        $estado = array_key_exists(post('estado'), estados_derecho()) ? post('estado') : $sol['estado'];
        responder_solicitud_derecho($sol, $estado, post('respuesta'), $u['nombre']); // (Tukán Central v2) la misma función que usa el panel central
        flash('Solicitud N.º ' . $sol['id'] . ': ' . estados_derecho()[$estado] . '.');
        ir('admin.php?p=datos&estado=' . rawurlencode(get('estado', 'abiertas')));
    } elseif ($accion === 'revocar') {
        $c = fila('SELECT * FROM consentimientos WHERE id = ? AND ' . $filtro_liga[0], array_merge([(int)post('id')], $filtro_liga[1])) ?? throw new RuntimeException('Autorización no encontrada.');
        q('UPDATE consentimientos SET revocado = ? WHERE id = ?', [date('Y-m-d H:i:s'), $c['id']]);
        flash('Autorización marcada como retirada. Recuerde dejar de usar esos datos o borrarlos.');
        ir('admin.php?p=datos&s=autorizaciones');
    } elseif ($accion === 'ajustes' && es_super()) {
        $ver = trim(post('version'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ver)) throw new RuntimeException('La versión debe ser una fecha: AAAA-MM-DD.');
        guardar_ajuste('privacidad_version', $ver);
        guardar_ajuste('privacidad_alojamiento', mb_substr(trim(post('alojamiento')), 0, 200));
        flash('Política actualizada. Las nuevas autorizaciones quedarán con la versión ' . $ver . '.');
        ir('admin.php?p=datos&s=ajustes');
    }
}

/* ---------- Excel de autorizaciones ---------- */
$w = [$filtro_liga[0]];
$prm = $filtro_liga[1];
if (get('formulario') !== '' && isset(formularios_consentimiento()[get('formulario')])) { $w[] = 'formulario = ?'; $prm[] = get('formulario'); }
if (get('texto') !== '') { $w[] = '(titular LIKE ? OR representante LIKE ? OR usuario LIKE ?)'; $t = '%' . get('texto') . '%'; array_push($prm, $t, $t, $t); }
if (get('desde') !== '' && strtotime(get('desde'))) { $w[] = 'creado >= ?'; $prm[] = get('desde') . ' 00:00:00'; }
if (get('hasta') !== '' && strtotime(get('hasta'))) { $w[] = 'creado <= ?'; $prm[] = get('hasta') . ' 23:59:59'; }
$where = implode(' AND ', $w);
$quien_txt = ['titular' => 'La persona', 'representante' => 'Su representante', 'organizacion' => 'La organización (declara tenerla)'];

if ($s === 'autorizaciones' && get('excel') === '1') {
    require_once APP_DIR . '/excel.php';
    bitacora_evento('exportacion', 'Descargó el registro de autorizaciones de datos personales');
    $filas_x = [['Fecha', 'Formulario', 'Titular', 'Documento', 'Quién autorizó', 'Representante', 'Registrado por', 'Versión', 'IP', 'Retirada', 'Texto aceptado']];
    foreach (filas("SELECT * FROM consentimientos WHERE $where ORDER BY id DESC LIMIT 50000", $prm) as $c) {
        $filas_x[] = [$c['creado'], formularios_consentimiento()[$c['formulario']] ?? $c['formulario'], $c['titular'], $c['documento'], $quien_txt[$c['por']] ?? $c['por'],
            $c['representante'], $c['usuario'], $c['version'], $c['ip'], $c['revocado'], $c['texto']];
    }
    $bin = crear_xlsx($filas_x, 'Autorizaciones', [18, 26, 28, 12, 22, 24, 30, 11, 15, 18, 90]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="autorizaciones_datos_' . date('Ymd') . '.xlsx"');
    echo $bin;
    exit;
}

$abiertas = $lid !== null ? solicitudes_derechos_pendientes($lid) : solicitudes_derechos_pendientes(null);
cabecera_admin('Protección de datos');
$url_pub = $liga ? url_liga($liga) : url_base();
?>
<h1>🔒 Protección de datos personales</h1>
<p class="suave">Ley Orgánica de Protección de Datos Personales (LOPDP). Aquí queda la <b>prueba de cada autorización</b> y se atienden las <b>solicitudes</b> de las personas (ver, corregir o borrar sus datos). Plazo para responder: <b>15 días</b>.
  Formulario público: <a href="<?= e($liga ? 'index.php?l=' . rawurlencode($liga['slug']) . '&v=derechos' : 'index.php?v=derechos') ?>" target="_blank">Mis datos ↗</a> ·
  <a href="<?= e($liga ? 'index.php?l=' . rawurlencode($liga['slug']) . '&v=privacidad' : 'index.php?v=privacidad') ?>" target="_blank">Política de privacidad ↗</a> (versión <?= e(version_privacidad()) ?>)</p>
<nav class="pestanas">
  <a class="<?= $s === 'solicitudes' ? 'activa' : '' ?>" href="admin.php?p=datos">📨 Solicitudes<?= $abiertas ? menu_badge($abiertas) : '' ?></a>
  <a class="<?= $s === 'autorizaciones' ? 'activa' : '' ?>" href="admin.php?p=datos&amp;s=autorizaciones">✅ Autorizaciones</a>
  <?php if (es_super()): ?><a class="<?= $s === 'ajustes' ? 'activa' : '' ?>" href="admin.php?p=datos&amp;s=ajustes">⚙️ Política</a><?php endif; ?>
</nav>

<?php if ($s === 'solicitudes'):
    $est = get('estado', 'abiertas');
    $ws = [$filtro_liga[0]];
    $ps = $filtro_liga[1];
    if ($est === 'abiertas') $ws[] = "estado IN ('nueva','en_proceso')";
    elseif (isset(estados_derecho()[$est])) { $ws[] = 'estado = ?'; $ps[] = $est; }
    $lista = filas('SELECT * FROM solicitudes_derechos WHERE ' . implode(' AND ', $ws) . ' ORDER BY id DESC LIMIT 200', $ps);
?>
<p>
  <?php foreach (['abiertas' => 'Por atender'] + estados_derecho() + ['todas' => 'Todas'] as $k => $v): ?>
    <a class="btn <?= $est === $k ? '' : 'btn-sec' ?> btn-chico" href="admin.php?p=datos&amp;estado=<?= e($k) ?>"><?= e($v) ?></a>
  <?php endforeach; ?>
</p>
<?php if (!$lista): ?><div class="tarjeta"><p class="suave" style="margin:0">No hay solicitudes aquí. Cuando alguien llene el formulario <b>Mis datos</b>, aparecerá en esta lista y le llegará un aviso.</p></div><?php endif; ?>
<?php foreach ($lista as $sol):
    $dias = dias_solicitud($sol);
    $abierta = in_array($sol['estado'], ['nueva', 'en_proceso'], true);
    $wa = telefono_wa($sol['telefono']);
    $lg = !$lid && $sol['liga_id'] ? fila('SELECT id, nombre FROM ligas WHERE id = ?', [(int)$sol['liga_id']]) : null;
    $donde = $sol['liga_id'] ? buscar_titular((int)$sol['liga_id'], $sol['cedula'], $sol['en_nombre_de'] !== '' ? $sol['en_nombre_de'] : $sol['nombre']) : [];
?>
<div class="tarjeta">
  <div class="fila-titulo">
    <h3 style="margin:0">N.º <?= (int)$sol['id'] ?> · <?= e(tipos_derecho()[$sol['tipo']] ?? $sol['tipo']) ?></h3>
    <span class="etiqueta"><?= e(estados_derecho()[$sol['estado']] ?? $sol['estado']) ?></span>
  </div>
  <p style="margin:.4rem 0">👤 <b><?= e($sol['nombre']) ?></b> · 🪪 <?= e($sol['cedula']) ?><?= $sol['en_nombre_de'] !== '' ? ' · en nombre de <b>' . e($sol['en_nombre_de']) . '</b>' : '' ?>
    <?= $sol['email'] !== '' ? ' · ✉ <a href="mailto:' . e($sol['email']) . '">' . e($sol['email']) . '</a>' : '' ?><?= $sol['telefono'] !== '' ? ' · 📞 ' . e($sol['telefono']) : '' ?><?= $lg ? ' · 🏆 ' . e($lg['nombre']) : '' ?></p>
  <p style="margin:.4rem 0">Recibida: <?= e(substr($sol['creado'], 0, 16)) ?> ·
    <?php if ($abierta): ?><b style="color:<?= $dias > 15 ? '#c0392b' : ($dias >= 10 ? '#d68910' : 'inherit') ?>"><?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></b><?= $dias > 15 ? ' ⚠️ <b>fuera de plazo</b>' : ' (quedan ' . max(0, 15 - $dias) . ')' ?>
    <?php else: ?>respondida en <?= $dias ?> día<?= $dias === 1 ? '' : 's' ?> por <?= e($sol['respondido_por']) ?><?php endif; ?></p>
  <blockquote style="margin:.5rem 0;padding:.5rem .8rem;background:var(--fondo2,#f4f6f5);border-radius:8px">“<?= nl2br(e($sol['detalle'])) ?>”</blockquote>
  <?php if ($donde): ?>
    <p style="margin:.4rem 0"><b>Dónde están sus datos:</b>
    <?php foreach ($donde as $d): ?><br>· <?= e($d['que']) ?>: <?= $lid ? '<a href="' . e($d['url']) . '">' . e($d['nombre']) . '</a>' : e($d['nombre']) ?><?php endforeach; ?></p>
  <?php elseif ($sol['liga_id']): ?>
    <p class="suave" style="margin:.4rem 0">No se encontró a nadie con esa cédula o nombre. Revise si está escrito distinto.</p>
  <?php endif; ?>
  <?php if ($sol['tipo'] === 'eliminacion' || $sol['tipo'] === 'revocar'): ?>
    <p class="ayuda">💡 Para borrar a un jugador sin perder las estadísticas del campeonato, abra su ficha y use <b>"Anonimizar"</b>. Para alumnos, profesores o competidores, borre o corrija sus datos en su ficha.</p>
  <?php endif; ?>
  <?php if ($sol['respuesta'] !== '' && !$abierta): ?><p style="margin:.4rem 0"><b>Respuesta:</b> <?= nl2br(e($sol['respuesta'])) ?></p><?php endif; ?>
  <?php if ($editar): ?>
  <details <?= $abierta ? 'open' : '' ?>><summary><?= $abierta ? 'Responder' : 'Cambiar respuesta' ?></summary>
    <form method="post" action="admin.php?p=datos&amp;estado=<?= e($est) ?>">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="responder"><input type="hidden" name="id" value="<?= (int)$sol['id'] ?>">
      <div class="campos">
        <div><label>Estado</label><select name="estado"><?php foreach (estados_derecho() as $k => $v): ?><option value="<?= e($k) ?>" <?= $sol['estado'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="campo-ancho"><label>Respuesta que se le dio (queda como prueba)</label><textarea name="respuesta" rows="3" maxlength="2000" placeholder="Ej: Se corrigió su fecha de nacimiento el 05/10. Se le avisó por WhatsApp."><?= e($sol['respuesta']) ?></textarea></div>
      </div>
      <button class="btn" type="submit">💾 Guardar</button>
      <?php if ($wa): ?><a class="btn btn-wa" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $sol['nombre'] . ', le escribimos por su solicitud de protección de datos N.º ' . $sol['id'] . '. ') ?>">💬 WhatsApp</a><?php endif; ?>
      <?php if ($sol['email'] !== ''): ?><a class="btn btn-sec" href="mailto:<?= e($sol['email']) ?>?subject=<?= rawurlencode('Su solicitud de protección de datos N.º ' . $sol['id']) ?>">✉ Correo</a><?php endif; ?>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php elseif ($s === 'autorizaciones'):
    $por_pagina = 100;
    $pag = max(1, (int)get('pag', '1'));
    $total = (int)valor("SELECT COUNT(*) FROM consentimientos WHERE $where", $prm);
    $lista = filas("SELECT * FROM consentimientos WHERE $where ORDER BY id DESC LIMIT $por_pagina OFFSET " . (($pag - 1) * $por_pagina), $prm);
    $q = http_build_query(['p' => 'datos', 's' => 'autorizaciones', 'formulario' => get('formulario'), 'texto' => get('texto'), 'desde' => get('desde'), 'hasta' => get('hasta')]);
?>
<form method="get" action="admin.php" class="tarjeta">
  <input type="hidden" name="p" value="datos"><input type="hidden" name="s" value="autorizaciones">
  <div class="campos">
    <div><label>Formulario</label><select name="formulario"><option value="">Todos</option><?php foreach (formularios_consentimiento() as $k => $v): ?><option value="<?= e($k) ?>" <?= get('formulario') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Nombre</label><input type="text" name="texto" value="<?= e(get('texto')) ?>" placeholder="Buscar persona"></div>
    <div><label>Desde</label><input type="date" name="desde" value="<?= e(get('desde')) ?>"></div>
    <div><label>Hasta</label><input type="date" name="hasta" value="<?= e(get('hasta')) ?>"></div>
  </div>
  <button class="btn" type="submit">🔍 Filtrar</button>
  <a class="btn btn-sec" href="admin.php?<?= e($q) ?>&amp;excel=1">⬇️ Descargar Excel</a>
</form>
<p class="suave"><?= $total ?> autorización<?= $total === 1 ? '' : 'es' ?> registrada<?= $total === 1 ? '' : 's' ?>. Cada una guarda fecha y hora, IP, navegador, el texto exacto que se aceptó y la versión de la política. Los documentos se muestran ocultos (solo los 4 últimos dígitos).</p>
<?php if (!$lista): ?><div class="tarjeta"><p class="suave" style="margin:0">Todavía no hay autorizaciones con este filtro. Se registran solas cada vez que alguien marca la casilla de datos personales (inscripción de jugadores, alumnos, reservas, pagos, etc.).</p></div><?php else: ?>
<div class="tabla-scroll"><table>
  <thead><tr><th>Fecha</th><th>Formulario</th><th>Titular</th><th>Quién autorizó</th><th>Versión</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lista as $c): ?>
    <tr<?= $c['revocado'] !== '' ? ' style="opacity:.55"' : '' ?>>
      <td><?= e(substr($c['creado'], 0, 16)) ?></td>
      <td><?= e(formularios_consentimiento()[$c['formulario']] ?? $c['formulario']) ?></td>
      <td><b><?= e($c['titular']) ?></b><?= $c['documento'] !== '' ? '<br><small class="suave">' . e($c['documento']) . '</small>' : '' ?></td>
      <td><?= e($quien_txt[$c['por']] ?? $c['por']) ?><?= $c['representante'] !== '' ? '<br><small>' . e($c['representante']) . '</small>' : '' ?><?= $c['usuario'] !== '' ? '<br><small class="suave">' . e($c['usuario']) . '</small>' : '' ?></td>
      <td><?= e($c['version']) ?><?= $c['revocado'] !== '' ? '<br><small style="color:#c0392b">Retirada ' . e(substr($c['revocado'], 0, 10)) . '</small>' : '' ?></td>
      <td><details><summary>Ver</summary><small><?= e($c['texto']) ?><br>IP: <?= e($c['ip']) ?> · <?= e($c['agente']) ?></small>
        <?php if ($editar && $c['revocado'] === ''): ?><form method="post" action="admin.php?p=datos&amp;s=autorizaciones" onsubmit="return confirm('¿Marcar esta autorización como retirada?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="revocar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico" type="submit">Marcar como retirada</button></form><?php endif; ?>
      </details></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($total > $por_pagina): ?><p><?php if ($pag > 1): ?><a class="btn btn-sec btn-chico" href="admin.php?<?= e($q) ?>&amp;pag=<?= $pag - 1 ?>">← Anteriores</a><?php endif; ?>
  Página <?= $pag ?> de <?= (int)ceil($total / $por_pagina) ?>
  <?php if ($pag * $por_pagina < $total): ?><a class="btn btn-sec btn-chico" href="admin.php?<?= e($q) ?>&amp;pag=<?= $pag + 1 ?>">Siguientes →</a><?php endif; ?></p><?php endif; ?>
<?php endif; ?>

<?php else: /* ajustes de la política (solo superadministrador) */ ?>
<form method="post" action="admin.php?p=datos&amp;s=ajustes" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="ajustes">
  <div class="campos">
    <div><label>Versión de la política (fecha)</label><input type="text" name="version" value="<?= e(version_privacidad()) ?>" pattern="\d{4}-\d{2}-\d{2}" required>
      <div class="ayuda">Cámbiela solo cuando cambie el texto de la política. Cada autorización guarda la versión que se aceptó.</div></div>
    <div class="campo-ancho"><label>Dónde se guardan los datos</label><input type="text" name="alojamiento" value="<?= e(txt_alojamiento()) ?>" maxlength="200">
      <div class="ayuda">Aparece en la política y en las casillas de autorización (transferencia internacional).</div></div>
  </div>
  <button class="btn" type="submit">💾 Guardar</button>
</form>
<?php endif; ?>
<?php
pie_admin();
