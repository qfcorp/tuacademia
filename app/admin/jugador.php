<?php
/* Ficha de un jugador: editar, pase a otro equipo, carnet, trayectoria, datos personales (LOPDP) */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$j = jugador_de_liga((int)get('id'), $lid);
$jid = (int)$j['id'];
$mod_req = modulo_de_equipo((int)$j['equipo_id'], $liga); // (v35) requisitos del módulo deportivo

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        if ($j['anonimizado']) throw new RuntimeException('Este jugador fue anonimizado a pedido suyo: sus datos ya no se pueden editar.');
        $nombres = post('nombres');
        if ($nombres === '') throw new RuntimeException('Escriba los nombres.');
        $eid = (int)$j['equipo_id'];
        $activo = post('activo') === '1' ? 1 : 0;
        if ($activo && !$j['activo']) {
            $n = (int)valor('SELECT COUNT(*) FROM jugadores WHERE equipo_id = ? AND activo = 1 AND id <> ?', [$eid, $jid]);
            if ($n >= limites_plantilla($eid, $liga)[1]) throw new RuntimeException('El equipo ya tiene el máximo de jugadores permitido.');
        }
        $numero = entero_o_null(post('numero'));
        if ($numero !== null && fila('SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ? AND activo = 1 AND id <> ?', [$eid, $numero, $jid])) {
            throw new RuntimeException('El número ' . $numero . ' ya lo usa otro jugador del equipo.');
        }
        $cedula = mb_substr(preg_replace('/[^0-9A-Za-z-]/', '', post('cedula')), 0, 20);
        if ($cedula !== '') {
            $dup = fila('SELECT j.id, e.nombre FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.cedula = ? AND j.activo = 1 AND j.id <> ?', [$lid, $cedula, $jid]);
            if ($dup) throw new RuntimeException('Esa cédula ya está inscrita en el equipo ' . $dup['nombre'] . '.');
        }
        $fn = post('fecha_nac');
        $fecha_nac = ($fn !== '' && strtotime($fn)) ? date('Y-m-d', strtotime($fn)) : '';
        $notas = [];
        if ($activo && ($fecha_nac !== $j['fecha_nac'] || !$j['activo'])) {
            $notas = validar_categoria_jugador($eid, ['fecha_nac' => $fecha_nac] + $j, $jid);
        }
        $cons = leer_consentimiento_post($fecha_nac, $j);
        $foto = subir_imagen('foto', 'jugadores', 600);
        if ($foto) borrar_imagen($j['foto']);
        if (post('quitar_foto') === '1' && !$foto) {
            borrar_imagen($j['foto']);
            $foto = '';
        }
        $d = array_merge([
            'nombres' => mb_substr($nombres, 0, 80), 'apellidos' => mb_substr(post('apellidos'), 0, 80), 'cedula' => $cedula, 'numero' => $numero,
            'posicion' => in_array(post('posicion'), ['', 'Arquero', 'Defensa', 'Mediocampista', 'Delantero'], true) ? post('posicion') : '',
            'fecha_nac' => $fecha_nac, 'foto' => $foto ?? $j['foto'], 'activo' => $activo,
        ], $cons);
        q('UPDATE jugadores SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d))) . ' WHERE id = :id', $d + ['id' => $jid]);
        if ($cons['consentimiento'] !== $j['consentimiento'] || (int)$cons['foto_publica'] !== (int)$j['foto_publica']) registrar_consentimiento_jugador('jugador', $lid, $d, 'jugadores', $jid); // (v24)
        guardar_requisitos($lid, $mod_req, 'jugadores', $jid); // (v35)
        flash('Jugador actualizado.' . ($notas ? ' ' . implode(' · ', $notas) : ''));
        ir('admin.php?p=jugador&id=' . $jid);
    } elseif ($accion === 'pase') {
        $costo = max(0, round((float)str_replace(',', '.', post('costo')), 2));
        $destino = (int)post('destino_id');
        ejecutar_pase($liga, $j, $destino, post('motivo'), $costo, usuario()['nombre'], null, post('forzar') === '1');
        flash('Pase realizado: ' . nombre_jugador($j) . ' ahora juega en ' . valor('SELECT nombre FROM equipos WHERE id = ?', [$destino]) . '.' . ($costo > 0 ? ' Se cargó ' . dinero($costo) . ' al equipo que lo recibe.' : ''));
        ir('admin.php?p=jugador&id=' . $jid);
    } elseif ($accion === 'portal') {
        $_SESSION['portal_msg'] = [$jid, codigo_portal($j)];
        bitacora_evento('portal_jugador', 'Generó el acceso al portal del jugador para ' . nombre_jugador($j), $lid, $jid);
        ir('admin.php?p=jugador&id=' . $jid . '#portal');
    } elseif ($accion === 'nuevo_carnet') {
        q("UPDATE jugadores SET carnet_token = '' WHERE id = ?", [$jid]);
        carnet_token(fila('SELECT * FROM jugadores WHERE id = ?', [$jid]));
        bitacora_evento('carnet', 'Anuló el carnet y generó un QR nuevo para ' . nombre_jugador($j) . ' (' . $j['equipo'] . ')', $lid, $jid);
        flash('Se generó un QR nuevo. El carnet anterior ya NO es válido: imprima el nuevo.');
        ir('admin.php?p=jugador&id=' . $jid);
    } elseif ($accion === 'anonimizar') {
        if (post('confirmar') !== 'ELIMINAR') throw new RuntimeException('Escriba ELIMINAR en mayúsculas para confirmar.');
        borrar_imagen($j['foto']);
        q("UPDATE jugadores SET nombres = 'Jugador', apellidos = ?, cedula = '', fecha_nac = '', foto = '', numero = NULL, posicion = '', activo = 0, anonimizado = 1,
           carnet_token = '', representante_nombre = '', representante_cedula = '', representante_telefono = '', foto_publica = 0 WHERE id = ?", ['anónimo #' . $jid, $jid]);
        foreach (filas("SELECT id, foto FROM solicitudes_jugador WHERE jugador_id = ? OR (cedula <> '' AND cedula = ?)", [$jid, $j['cedula']]) as $sj) {
            borrar_imagen($sj['foto']);
            q("UPDATE solicitudes_jugador SET nombres = 'Jugador', apellidos = 'anónimo', cedula = '', fecha_nac = '', foto = '', representante_nombre = '', representante_cedula = '', representante_telefono = '' WHERE id = ?", [$sj['id']]);
        }
        bitacora_evento('datos_personales', 'Eliminó los datos personales del jugador #' . $jid . ' a su pedido (derecho de eliminación, LOPDP). Se conservan sus estadísticas de forma anónima.', $lid, $jid);
        flash('Datos personales eliminados. Las estadísticas quedan como "Jugador anónimo #' . $jid . '".');
        ir('admin.php?p=equipo&id=' . (int)$j['equipo_id']);
    } elseif ($accion === 'eliminar') {
        $n = (int)valor('SELECT COUNT(*) FROM eventos WHERE jugador_id = ?', [$jid]) + (int)valor('SELECT COUNT(*) FROM informe_eventos WHERE jugador_id = ?', [$jid]) + (int)valor('SELECT COUNT(*) FROM alineaciones WHERE jugador_id = ?', [$jid]);
        if ($n > 0) throw new RuntimeException('Este jugador tiene partidos, goles o tarjetas registrados. En vez de eliminarlo, desmarque "Jugador activo" (queda de baja y se conservan las estadísticas), o use "Eliminar datos personales".');
        borrar_imagen($j['foto']);
        q('DELETE FROM jugadores WHERE id = ?', [$jid]);
        flash('Jugador eliminado.');
        ir('admin.php?p=equipo&id=' . (int)$j['equipo_id']);
    }
}

/* Ficha de datos personales (derecho de acceso y portabilidad) */
if (get('datos') === '1') {
    bitacora_evento('datos_personales', 'Descargó la ficha de datos personales de ' . nombre_jugador($j), $lid, $jid);
    doc_inicio('Datos personales · ' . nombre_jugador($j), 'admin.php?p=jugador&id=' . $jid);
    echo '<h1>Ficha de datos personales</h1><p>' . e($liga['nombre']) . ' · emitida el ' . date('Y-m-d H:i') . '</p><table>';
    foreach (['nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'cedula' => 'Cédula / pasaporte', 'fecha_nac' => 'Fecha de nacimiento', 'numero' => 'Número', 'posicion' => 'Posición',
                 'equipo' => 'Equipo actual', 'activo' => 'Activo', 'creado' => 'Registrado el', 'consentimiento' => 'Autorización de datos', 'consentimiento_por' => 'Autorizó',
                 'representante_nombre' => 'Representante', 'representante_cedula' => 'Cédula del representante', 'representante_telefono' => 'Teléfono del representante', 'foto_publica' => 'Foto pública autorizada'] as $k => $t) {
        $v = $j[$k] ?? '';
        if (in_array($k, ['activo', 'foto_publica'], true)) $v = $v ? 'Sí' : 'No';
        echo '<tr><th style="text-align:left">' . e($t) . '</th><td>' . e((string)$v) . '</td></tr>';
    }
    echo '</table><h2>Trayectoria deportiva</h2><table><tr><th>Campeonato</th><th>Equipo</th><th>PJ</th><th>Goles</th><th>Amarillas</th><th>Rojas</th></tr>';
    foreach (trayectoria_jugador($jid) as $r) echo '<tr><td>' . e($r['torneo']['nombre'] . ' ' . $r['torneo']['temporada']) . '</td><td>' . e($r['equipo']['nombre']) . '</td><td>' . $r['pj'] . '</td><td>' . $r['goles'] . '</td><td>' . $r['amarillas'] . '</td><td>' . $r['rojas'] . '</td></tr>';
    echo '</table><h2>Pases</h2><ul>';
    foreach (pases_jugador($jid) as $ps) echo '<li>' . e($ps['fecha'] . ': ' . ($ps['origen'] ?? '—') . ' → ' . $ps['destino']) . '</li>';
    echo '</ul><p style="font-size:12px">Documento emitido en cumplimiento del derecho de acceso y portabilidad (Ley Orgánica de Protección de Datos Personales).</p>';
    doc_fin();
    exit;
}

$equipos = filas('SELECT id, nombre FROM equipos WHERE liga_id = ? AND id <> ? ORDER BY nombre', [$lid, $j['equipo_id']]);
$st = estadisticas_jugador($jid);
$tray = trayectoria_jugador($jid);
$pases = pases_jugador($jid);
$tcat = torneos_categoria_equipo((int)$j['equipo_id']);
cabecera_admin(nombre_jugador($j));
?>
<p><a href="admin.php?p=equipo&amp;id=<?= (int)$j['equipo_id'] ?>">← <?= e($j['equipo']) ?></a></p>
<div class="perfil tarjeta">
  <?= foto_jugador($j, 'foto-grande') ?>
  <div>
    <h1 style="margin:0"><?= e(nombre_jugador($j)) ?></h1>
    <p class="suave"><?= e($j['equipo']) ?><?= $j['numero'] !== null ? ' · #' . (int)$j['numero'] : '' ?><?= edad($j['fecha_nac']) !== null ? ' · ' . edad($j['fecha_nac']) . ' años' : '' ?><?php foreach ($tcat as $tc) echo etiqueta_categoria(situacion_categoria($tc, $j)); ?></p>
    <?php if (!$j['anonimizado']): ?>
    <p class="acciones"><a class="btn btn-chico" target="_blank" href="admin.php?p=carnets&amp;jugador=<?= $jid ?>">🪪 Imprimir carnet</a><?php if (modulo_menu($liga, 'videos')): ?> <a class="btn btn-sec btn-chico" href="admin.php?p=videos&amp;id=<?= $jid ?>#subir">🎬 Subir video</a><?php endif; ?>
      <a class="btn btn-sec btn-chico" target="_blank" href="<?= e(url_carnet($j)) ?>">Ver verificación del QR ↗</a>
      <a class="btn btn-sec btn-chico" href="admin.php?p=bitacora&amp;jugador=<?= $jid ?>">🔎 Historial de cambios</a></p>
    <?php endif; ?>
    <p>⚽ <?= $st['goles'] ?? 0 ?> goles · <span class="tarjeta-am"></span> <?= $st['amarillas'] ?? 0 ?> · <span class="tarjeta-ro"></span> <?= $st['rojas'] ?? 0 ?> <small>(en todos los campeonatos)</small></p>
  </div>
</div>

<?php if ($tray || $pases): ?>
<div class="tarjeta">
  <h2 style="margin-top:0">📜 Trayectoria</h2>
  <?php if ($tray): ?>
  <div class="tabla-scroll"><table>
    <tr><th>Campeonato</th><th>Equipo</th><th class="num">PJ</th><th class="num">Goles</th><th class="num"><span class="tarjeta-am"></span></th><th class="num"><span class="tarjeta-ro"></span></th></tr>
    <?php foreach ($tray as $r): ?><tr><td><?= e($r['torneo']['nombre'] . ' ' . $r['torneo']['temporada']) ?><?= $r['campeon'] ? ' 🏆' : '' ?></td><td><?= e($r['equipo']['nombre']) ?></td><td class="num"><?= $r['pj'] ?></td><td class="num"><?= $r['goles'] ?></td><td class="num"><?= $r['amarillas'] ?></td><td class="num"><?= $r['rojas'] ?></td></tr><?php endforeach; ?>
  </table></div>
  <p class="ayuda">PJ = partidos en los que la vocalía lo marcó presente.</p>
  <?php endif; ?>
  <?php if ($pases): ?><h3>Pases</h3><ul><?php foreach ($pases as $ps): ?><li><?= e($ps['fecha']) ?>: <?= e($ps['origen'] ?? '—') ?> → <b><?= e($ps['destino']) ?></b><?= $ps['costo'] > 0 ? ' (' . dinero($ps['costo']) . ')' : '' ?><?= $ps['motivo'] !== '' ? ' · ' . e($ps['motivo']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($j['anonimizado']): ?>
  <div class="aviso aviso-info">Los datos personales de este jugador fueron eliminados a su pedido. Solo se conservan sus estadísticas de forma anónima.</div>
<?php else: ?>
<div class="tarjeta">
  <h2>Editar jugador</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar">
    <div class="campos">
      <div><label>Nombres *</label><input type="text" name="nombres" required maxlength="80" value="<?= e($j['nombres']) ?>"></div>
      <div><label>Apellidos</label><input type="text" name="apellidos" maxlength="80" value="<?= e($j['apellidos']) ?>"></div>
      <div><label>Cédula</label><input type="text" name="cedula" maxlength="20" value="<?= e($j['cedula']) ?>"></div>
      <div><label>Número</label><input type="number" name="numero" min="0" max="99" value="<?= e($j['numero']) ?>"></div>
      <div><label>Posición</label><select name="posicion"><?php foreach (['', 'Arquero', 'Defensa', 'Mediocampista', 'Delantero'] as $po): ?><option value="<?= e($po) ?>" <?= $j['posicion'] === $po ? 'selected' : '' ?>><?= $po === '' ? '—' : e($po) ?></option><?php endforeach; ?></select></div>
      <div><label>Fecha de nacimiento</label><input type="date" name="fecha_nac" value="<?= e($j['fecha_nac']) ?>"></div>
      <div><label>Nueva foto</label><input type="file" name="foto" accept="image/*">
        <?php if ($j['foto']): ?><label class="check" style="margin-top:6px;font-weight:400"><input type="checkbox" name="quitar_foto" value="1"> Quitar foto actual</label><?php endif; ?></div>
      <div><label class="check"><input type="checkbox" name="activo" value="1" <?= $j['activo'] ? 'checked' : '' ?>> Jugador activo</label><div class="ayuda">Desmarque para darlo de baja sin perder sus estadísticas.</div></div>
    </div>
    <?= html_requisitos_form($mod_req, 'jugadores', $jid) ?>
    <?= html_consentimiento($j, 'c-edit') ?>
    <button class="btn" type="submit">Guardar</button>
  </form>
</div>

<div class="tarjeta" id="pase">
  <h2 style="margin-top:0">🔁 Pase a otro equipo</h2>
  <form method="post" onsubmit="return confirm('¿Hacer el pase ahora?')">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="pase">
    <div class="campos">
      <div><label>Equipo que lo recibe</label><select name="destino_id" required><option value="">— elegir —</option><?= opciones_equipos($equipos) ?></select></div>
      <div><label>Valor del pase ($)</label><input type="number" name="costo" min="0" step="0.01" value="<?= e(number_format((float)$liga['costo_pase'], 2, '.', '')) ?>"><div class="ayuda">Se carga al equipo que lo recibe. 0 = sin costo.</div></div>
      <div class="campo-ancho"><label>Motivo / observación</label><input type="text" name="motivo" maxlength="300"></div>
      <div class="campo-ancho"><label class="check" style="font-weight:400"><input type="checkbox" name="forzar" value="1"> Autorizar aunque tenga una suspensión pendiente</label></div>
    </div>
    <p class="ayuda">Sus goles y tarjetas anteriores quedan con su equipo anterior. El sistema revisa el cupo y la categoría del equipo que lo recibe.</p>
    <button class="btn btn-sec">Hacer el pase</button>
  </form>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">Carnet y datos personales</h2>
  <div class="acciones">
    <form method="post" class="inline" onsubmit="return confirm('El carnet actual dejará de ser válido (por pérdida o robo). ¿Generar uno nuevo?')">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_carnet">
      <button class="btn btn-sec btn-chico" type="submit">Anular carnet y generar QR nuevo</button>
    </form>
    <a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=jugador&amp;id=<?= $jid ?>&amp;datos=1">📄 Ficha de datos personales (acceso / portabilidad)</a>
    <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="portal"><button class="btn btn-sec btn-chico">🔑 Acceso al carnet digital del jugador</button></form>
  </div>
  <?php $pm = $_SESSION['portal_msg'] ?? null; unset($_SESSION['portal_msg']);
  if ($pm && (int)$pm[0] === $jid): $msg_p = mensaje_portal($j, $pm[1], $liga); ?>
  <div class="aviso aviso-info" id="portal" style="margin-top:10px"><b>Envíele este mensaje al jugador</b> (el código sirve una vez):<pre class="mensaje"><?= e($msg_p) ?></pre>
    <a class="btn btn-chico" target="_blank" href="https://wa.me/?text=<?= rawurlencode($msg_p) ?>">📲 Enviar por WhatsApp</a></div>
  <?php endif; ?>
  <?php if ($j['portal_ultimo'] !== ''): ?><p class="suave" style="margin-bottom:0">Último ingreso al portal: <?= e($j['portal_ultimo']) ?></p><?php endif; ?>
  <details style="margin-top:14px"><summary><b>Eliminar datos personales</b> (derecho de eliminación)</summary>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('Se borrarán nombre, cédula, foto y datos del representante. No se puede deshacer. ¿Continuar?')">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="anonimizar">
      <p class="suave">Úselo cuando el jugador (o su representante) pida que se borren sus datos. Se conservan los goles, tarjetas y resultados de forma anónima, para no alterar las tablas.</p>
      <p><label>Escriba ELIMINAR para confirmar</label><input type="text" name="confirmar" required></p>
      <button class="btn btn-peligro btn-chico">Eliminar datos personales</button>
    </form>
  </details>
  <form method="post" style="margin-top:16px" onsubmit="return confirm('¿Eliminar definitivamente este jugador?')">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
    <button class="btn btn-peligro btn-chico" type="submit">Eliminar jugador (solo si nunca jugó)</button>
  </form>
</div>
<?php endif;
pie_admin();
