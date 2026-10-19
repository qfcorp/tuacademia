<?php
/* Parámetros de la liga (datos, reglas por defecto, canchas, pases y avisos por Telegram) */
$liga = requiere_liga();
$lid = (int)$liga['id'];

if (es_post() && post('accion') === 'idioma_liga') { guardar_idioma_liga($liga); ir('admin.php?p=config#idioma'); } // (v28)
if (es_post()) {
    $accion = post('accion');
    if ($accion === 'cancha_nueva') {
        if (mb_strlen(post('nombre')) < 2) throw new RuntimeException('Escriba el nombre de la cancha.');
        q('INSERT INTO canchas (liga_id, nombre, direccion, orden) VALUES (?,?,?,?)', [$lid, mb_substr(post('nombre'), 0, 60), mb_substr(post('direccion'), 0, 160), (int)valor('SELECT COUNT(*) FROM canchas WHERE liga_id = ?', [$lid]) + 1]);
        flash('Cancha agregada.');
        ir('admin.php?p=config#canchas');
    }
    if ($accion === 'cancha_activa') {
        q('UPDATE canchas SET activa = 1 - activa WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        ir('admin.php?p=config#canchas');
    }
    if ($accion === 'cancha_eliminar') {
        q('DELETE FROM canchas WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        flash('Cancha eliminada (los partidos conservan el nombre que ya tenían).');
        ir('admin.php?p=config#canchas');
    }
    // (v18) Documentos de la liga y cumpleañeros
    if ($accion === 'doc_subir') {
        $titulo = trim(post('titulo'));
        if (mb_strlen($titulo) < 3) throw new RuntimeException('Escriba el nombre del documento (ej: Reglamento 2026).');
        $tid_doc = (int)post('torneo_id');
        if ($tid_doc && !fila('SELECT id FROM torneos WHERE id = ? AND liga_id = ?', [$tid_doc, $lid])) $tid_doc = 0;
        $ruta = subir_documento('archivo', $lid);
        q('INSERT INTO liga_documentos (liga_id, torneo_id, titulo, tipo, archivo, publico, subido_por) VALUES (?,?,?,?,?,?,?)', [
            $lid, $tid_doc ?: null, mb_substr($titulo, 0, 120), array_key_exists(post('tipo'), tipos_documento()) ? post('tipo') : 'otro', $ruta, post('publico') === '1' ? 1 : 0, usuario()['nombre']]);
        flash('Documento "' . $titulo . '" publicado.');
        ir('admin.php?p=config#documentos');
    }
    if ($accion === 'doc_visible') {
        q('UPDATE liga_documentos SET publico = 1 - publico WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        ir('admin.php?p=config#documentos');
    }
    if ($accion === 'doc_borrar') {
        $d = fila('SELECT * FROM liga_documentos WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        if ($d) {
            q('DELETE FROM liga_documentos WHERE id = ?', [$d['id']]);
            if (str_starts_with($d['archivo'], 'uploads/docs/' . $lid . '/') && !str_contains($d['archivo'], '..')) @unlink(PUBLIC_DIR . '/' . $d['archivo']);
            flash('Documento eliminado.');
        }
        ir('admin.php?p=config#documentos');
    }
    if ($accion === 'entradas') {
        // (v28) Venta de entradas: todo opcional, con casillas
        if (!modulo_activo($liga, 'entradas')) throw new RuntimeException('Su plan no incluye la venta de entradas.');
        $num = fn(string $k) => max(0, min(30, round((float)str_replace(',', '.', post($k)), 2)));
        q('UPDATE ligas SET entradas_activas = ?, entradas_tarjeta = ?, entradas_transferencia = ?, entradas_parqueo = ?, entradas_iva = ?, entradas_municipal = ?, entradas_condiciones = ? WHERE id = ?', [
            post('entradas_activas') === '1' ? 1 : 0, post('entradas_tarjeta') === '1' ? 1 : 0, post('entradas_transferencia') === '1' ? 1 : 0, post('entradas_parqueo') === '1' ? 1 : 0,
            post('con_iva') === '1' ? $num('entradas_iva') : 0, post('con_municipal') === '1' ? $num('entradas_municipal') : 0, mb_substr(trim(post('entradas_condiciones')), 0, 1000), $lid]);
        flash(post('entradas_activas') === '1' ? 'Venta de entradas activada. Ábrala para cada partido en el menú Dinero → Entradas.' : 'Venta de entradas desactivada.');
        ir('admin.php?p=config#entradas');
    }
    if ($accion === 'cumples') {
        q('UPDATE ligas SET mostrar_cumples = ? WHERE id = ?', [post('mostrar_cumples') === '1' ? 1 : 0, $lid]);
        flash('Listo: ' . (post('mostrar_cumples') === '1' ? 'se muestran' : 'ya no se muestran') . ' los cumpleañeros y aniversarios.');
        ir('admin.php?p=config#cumples');
    }
    if ($accion === 'telegram') {
        $o = [];
        foreach (array_keys(tg_opciones([])) as $k) $o[$k] = post('tg_' . $k) === '1' ? 1 : 0;
        q('UPDATE ligas SET tg_opciones = ? WHERE id = ?', [json_encode($o), $lid]);
        flash('Avisos por Telegram guardados.');
        ir('admin.php?p=config#telegram');
    }
    if ($accion === 'tg_codigo') {
        q('UPDATE ligas SET tg_codigo = ? WHERE id = ?', [bin2hex(random_bytes(4)), $lid]);
        ir('admin.php?p=config#telegram');
    }
    if ($accion === 'tg_quitar') {
        q("UPDATE ligas SET tg_chat = '', tg_chat_nombre = '' WHERE id = ?", [$lid]);
        flash('Canal de Telegram desvinculado.');
        ir('admin.php?p=config#telegram');
    }
    if ($accion === 'tg_prueba') {
        if ($liga['tg_chat'] === '') throw new RuntimeException('Primero vincule el canal o grupo.');
        exigir_modulo_panel($liga, 'avisos'); // (Anexo B)
        tg_encolar($lid, $liga['tg_chat'], '🔔 Prueba de avisos de <b>' . e($liga['nombre']) . '</b>. ¡Funciona!');
        tg_procesar_cola();
        flash('Mensaje de prueba enviado al canal.');
        ir('admin.php?p=config#telegram');
    }
    guardar_liga($lid);
    flash('Parámetros de la liga guardados. Se usarán en los próximos campeonatos que cree (los campeonatos existentes conservan sus propias reglas).');
    ir('admin.php?p=config');
}

$liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
$canchas = filas('SELECT * FROM canchas WHERE liga_id = ? ORDER BY activa DESC, orden, nombre', [$lid]);
$tgo = tg_opciones($liga);
cabecera_admin('Parámetros');
?>
<h1>Parámetros de la liga</h1>
<p class="suave">Datos de la liga y reglas <b>por defecto</b>. Cada campeonato copia estas reglas al crearse y después se pueden cambiar solo para ese campeonato (Campeonatos → el campeonato → Configuración). Su contraseña y la doble verificación están en <a href="admin.php?p=seguridad">👤 Mi cuenta</a>.</p>
<p>Página pública de la liga: <a href="<?= e(url_liga($liga)) ?>" target="_blank"><b><?= e(url_liga($liga)) ?></b></a></p>

<div class="tarjeta" id="documentos">
  <h2 style="margin-top:0">📄 Documentos de la liga</h2>
  <p class="suave">Suba el reglamento, las bases del campeonato, resoluciones o el calendario (PDF o imagen, hasta 10 MB). Los que marque como públicos salen en la pestaña <b>Documentos</b> de la página de la liga.</p>
  <?php $docs = documentos_liga($lid, null, false); $torneos_doc = filas('SELECT id, nombre, temporada FROM torneos WHERE liga_id = ? ORDER BY id DESC', [$lid]); ?>
  <?php if ($docs): ?><div class="tabla-scroll"><table>
    <tr><th>Documento</th><th>Campeonato</th><th>Visible</th><th></th></tr>
    <?php foreach ($docs as $d): ?><tr>
      <td><?= icono_documento($d['archivo']) ?> <a href="<?= e($d['archivo']) ?>" target="_blank" rel="noopener"><?= e($d['titulo']) ?></a><br><small class="suave"><?= e(tipos_documento()[$d['tipo']] ?? '') ?> · <?= e(fecha_bonita(substr($d['creado'], 0, 10))) ?></small></td>
      <td><?= e($d['torneo'] ?? 'Toda la liga') ?></td>
      <td><?= $d['publico'] ? '<span class="etiqueta verde">Público</span>' : '<span class="etiqueta">Oculto</span>' ?></td>
      <td class="acciones"><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="doc_visible"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sec btn-chico"><?= $d['publico'] ? 'Ocultar' : 'Publicar' ?></button></form>
        <form class="inline" method="post" onsubmit="return confirm('¿Eliminar el documento?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="doc_borrar"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form></td>
    </tr><?php endforeach; ?>
  </table></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="doc_subir">
    <div class="campos">
      <div><label>Nombre del documento</label><input type="text" name="titulo" maxlength="120" required placeholder="Reglamento 2026"></div>
      <div><label>Tipo</label><select name="tipo"><?php foreach (tipos_documento() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Campeonato</label><select name="torneo_id"><option value="0">Toda la liga</option><?php foreach ($torneos_doc as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?></select></div>
      <div><label>Archivo (PDF o imagen)</label><input type="file" name="archivo" accept="application/pdf,image/jpeg,image/png,image/webp" required></div>
    </div>
    <label class="check"><input type="checkbox" name="publico" value="1" checked> Mostrarlo en la página pública</label>
    <button class="btn">⬆ Subir documento</button>
  </form>
</div>

<?php if (modulo_activo($liga, 'entradas')): $ea = (int)($liga['entradas_activas'] ?? 0) === 1; ?>
<div class="tarjeta" id="entradas">
  <h2 style="margin-top:0">🎟 Venta de entradas <small>(opcional)</small></h2>
  <p class="suave">Venda por adelantado las entradas de sus partidos, también a los hinchas. Cada entrada tiene su QR y en la puerta se valida con el celular. Si no marca la casilla, no aparece nada de esto.</p>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="entradas">
    <label class="check"><input type="checkbox" name="entradas_activas" value="1" <?= $ea ? 'checked' : '' ?> onchange="document.getElementById('ent-op').hidden = !this.checked"> <b>Vender entradas para los partidos</b></label>
    <div id="ent-op" <?= $ea ? '' : 'hidden' ?> style="margin:8px 0 0 26px">
      <label class="check"><input type="checkbox" name="entradas_parqueo" value="1" <?= (int)($liga['entradas_parqueo'] ?? 0) ? 'checked' : '' ?>> Vender también <b>parqueadero</b> (pide la placa del vehículo)</label>
      <label class="check"><input type="checkbox" name="entradas_tarjeta" value="1" <?= (int)($liga['entradas_tarjeta'] ?? 0) ? 'checked' : '' ?> <?= org_cobra_con_tarjeta($liga) ? '' : 'disabled' ?>> Cobrar con <b>tarjeta</b> de crédito o débito<?= org_cobra_con_tarjeta($liga) ? '' : ' <small class="suave">(primero active los cobros con tarjeta)</small>' ?></label>
      <label class="check"><input type="checkbox" name="entradas_transferencia" value="1" <?= (int)($liga['entradas_transferencia'] ?? 1) ? 'checked' : '' ?>> Aceptar <b>transferencia</b> (el comprador sube el comprobante y usted lo aprueba)</label>
      <div class="campos" style="margin-top:6px">
        <div><label class="check"><input type="checkbox" name="con_iva" value="1" <?= (float)($liga['entradas_iva'] ?? 0) > 0 ? 'checked' : '' ?>> El precio incluye <b>IVA</b> de</label><input type="number" step="0.01" name="entradas_iva" value="<?= e((string)((float)($liga['entradas_iva'] ?? 0) ?: 15)) ?>" style="max-width:90px"> %</div>
        <div><label class="check"><input type="checkbox" name="con_municipal" value="1" <?= (float)($liga['entradas_municipal'] ?? 0) > 0 ? 'checked' : '' ?>> Incluye <b>impuesto municipal</b> a espectáculos de</label><input type="number" step="0.01" name="entradas_municipal" value="<?= e((string)((float)($liga['entradas_municipal'] ?? 0) ?: 10)) ?>" style="max-width:90px"> %</div>
        <div class="campo-ancho"><label>Condiciones que verá el comprador (opcional)</label><textarea name="entradas_condiciones" rows="2" placeholder="Ej: No hay devoluciones. Niños menores de 5 años no pagan."><?= e($liga['entradas_condiciones'] ?? '') ?></textarea></div>
      </div>
      <p class="ayuda">Los impuestos solo se muestran desglosados en el reporte; consulte con su contador si su liga debe cobrarlos.</p>
    </div>
    <button class="btn btn-sec btn-chico" style="margin-top:8px">Guardar</button>
  </form>
</div>
<?php endif; ?>

<div class="tarjeta" id="cumples">
  <h2 style="margin-top:0">🎂 Cumpleañeros y aniversarios</h2>
  <p class="suave">La página de la liga y su Resumen muestran los jugadores que cumplen años hoy (con su edad) y los equipos que se acercan a su aniversario (ponga la <b>fecha de fundación</b> en la ficha de cada equipo). En <a href="admin.php?p=redes">Redes</a> sale la imagen de los cumpleañeros del día.</p>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cumples">
    <label class="check"><input type="checkbox" name="mostrar_cumples" value="1" <?= (int)($liga['mostrar_cumples'] ?? 1) === 1 ? 'checked' : '' ?>> Mostrar cumpleañeros y aniversarios</label>
    <button class="btn btn-sec btn-chico">Guardar</button>
  </form>
</div>

<div class="tarjeta" id="canchas">
  <h2 style="margin-top:0">🏟 Canchas</h2>
  <p class="suave">Se usan en la programación automática de las jornadas.</p>
  <?php if ($canchas): ?>
  <div class="tabla-scroll"><table>
    <tr><th>Cancha</th><th>Dirección / referencia</th><th>Estado</th><th></th></tr>
    <?php foreach ($canchas as $c): ?>
      <tr><td><b><?= e($c['nombre']) ?></b></td><td><?= e($c['direccion']) ?></td><td><?= $c['activa'] ? 'Disponible' : '<span class="etiqueta gris">No disponible</span>' ?></td>
        <td class="acciones"><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancha_activa"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico"><?= $c['activa'] ? 'Desactivar' : 'Activar' ?></button></form>
          <form class="inline" method="post" onsubmit="return confirm('¿Eliminar la cancha?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancha_eliminar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancha_nueva">
    <div class="campos"><div><label>Nombre</label><input type="text" name="nombre" required maxlength="60" placeholder="Ej: Cancha 1"></div><div><label>Dirección (opcional)</label><input type="text" name="direccion" maxlength="160"></div></div>
    <button class="btn btn-sec btn-chico">+ Agregar cancha</button></form>
</div>

<?php if (tg_activo()): ?>
<div class="tarjeta" id="telegram">
  <h2 style="margin-top:0">📲 Avisos por Telegram <small>(opcional)</small></h2>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="telegram">
    <label class="check"><input type="checkbox" name="tg_activo" value="1" <?= $tgo['activo'] ? 'checked' : '' ?>> <b>Usar avisos por Telegram en esta liga</b></label>
    <div class="campos" style="margin-top:10px">
      <div><b>En el canal o grupo de la liga</b>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_resultados" value="1" <?= $tgo['resultados'] ? 'checked' : '' ?>> Resultados finales</label>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_fotos" value="1" <?= $tgo['fotos'] ? 'checked' : '' ?>> …con la imagen del resultado</label>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_programacion" value="1" <?= $tgo['programacion'] ? 'checked' : '' ?>> Programación de cada jornada</label>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_inicio" value="1" <?= $tgo['inicio'] ? 'checked' : '' ?>> Inicio de cada partido</label>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_goles" value="1" <?= $tgo['goles'] ? 'checked' : '' ?>> Cada gol en vivo</label></div>
      <div><b>Mensajes privados</b>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_delegados" value="1" <?= $tgo['delegados'] ? 'checked' : '' ?>> Delegados: su partido, suspensiones, pases y respuestas</label>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_arbitros" value="1" <?= $tgo['arbitros'] ? 'checked' : '' ?>> Árbitros: sus designaciones</label>
        <label class="check" style="font-weight:400"><input type="checkbox" name="tg_admins" value="1" <?= $tgo['admins'] ? 'checked' : '' ?>> Administradores: inscripciones nuevas, vocalías cerradas, diferencias con el árbitro</label>
        <p class="ayuda">Cada persona conecta su Telegram desde su propia cuenta ("Conectar mi Telegram").</p></div>
    </div>
    <button class="btn btn-sec">Guardar avisos</button>
  </form>
  <h3>Canal o grupo público de la liga</h3>
  <?php if ($liga['tg_chat'] !== ''): ?>
    <p>✅ Vinculado: <b><?= e($liga['tg_chat_nombre'] ?: $liga['tg_chat']) ?></b></p>
    <div class="acciones">
      <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_prueba"><button class="btn btn-sec btn-chico">Enviar mensaje de prueba</button></form>
      <form class="inline" method="post" onsubmit="return confirm('¿Desvincular el canal?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_quitar"><button class="btn btn-sec btn-chico">Desvincular</button></form>
    </div>
  <?php elseif ($liga['tg_codigo'] !== ''): ?>
    <ol class="pasos">
      <li>Cree en Telegram un <b>canal</b> (o grupo) para la liga, si aún no lo tiene.</li>
      <li>Agregue al bot <b>@<?= e(ajuste('tg_usuario')) ?></b> como <b>administrador</b> del canal (en el canal: Administradores → Agregar administrador → busque el bot).</li>
      <li>Publique en el canal este mensaje exacto: <code class="copiar">/vincular <?= e($liga['tg_codigo']) ?></code></li>
      <li>En menos de un minuto aparecerá aquí como vinculado (recargue la página).</li>
    </ol>
  <?php else: ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_codigo"><button class="btn btn-sec btn-chico">Vincular un canal o grupo</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php formulario_liga($liga, 'admin.php?p=config'); ?>
<?= html_tarjeta_idioma_liga($liga) // (v28) ?>
<?= html_tarjeta_afiliacion($liga) // (v28) ?>
<?php
pie_admin();
