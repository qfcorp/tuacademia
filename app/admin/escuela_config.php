<?php
/* (v11) Parámetros de la ESCUELA: datos, colores, cobros, inscripción en línea, sedes */
$liga = requiere_liga();
if (!es_escuela($liga)) ir('admin.php?p=config');
$lid = (int)$liga['id'];
preparar_escuela($lid);

if (es_post() && post('accion') === 'idioma_liga') { guardar_idioma_liga($liga); ir('admin.php?p=escuela_config#idioma'); } // (v28)
if (es_post()) {
    $accion = post('accion');
    if ($accion === 'datos') {
        if (mb_strlen(post('nombre')) < 3) throw new RuntimeException(es_academia() ? 'Escriba el nombre de la academia.' : 'Escriba el nombre de la escuela.');
        $d = [
            'nombre' => mb_substr(post('nombre'), 0, 120), 'ciudad' => mb_substr(post('ciudad'), 0, 80), 'color' => color_valido(post('color')),
            'color2' => post('sin_color2') === '1' ? '' : color_valido(post('color2'), ''),
            'deporte_escuela' => array_key_exists(post('deporte_escuela'), deportes_escuela()) ? post('deporte_escuela') : 'futbol',
            'contacto_nombre' => mb_substr(post('contacto_nombre'), 0, 80), 'contacto_telefono' => mb_substr(post('contacto_telefono'), 0, 30),
            'contacto_email' => mb_substr(post('contacto_email'), 0, 120), 'datos_pago' => mb_substr(post('datos_pago'), 0, 500),
        ];
        if ($d['deporte_escuela'] !== $liga['deporte_escuela']) exigir_deporte_escuela($liga, $d['deporte_escuela']); // (v35) módulos deportivos
        if ($logo = subir_imagen('logo', 'ligas', 600)) { borrar_imagen($liga['logo']); $d['logo'] = $logo; }
        q('UPDATE ligas SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d))) . ' WHERE id = :id', $d + ['id' => $lid]);
        flash(es_academia() ? 'Datos de la academia guardados.' : 'Datos de la escuela guardados.');
    } elseif ($accion === 'cobros') {
        q('UPDATE ligas SET esc_mensualidad = ?, esc_matricula = ?, esc_dia_vence = ?, esc_desc_hermano = ? WHERE id = ?', [
            max(0, round((float)str_replace(',', '.', post('esc_mensualidad')), 2)), max(0, round((float)str_replace(',', '.', post('esc_matricula')), 2)),
            entero(post('esc_dia_vence'), 1, 28), max(0, min(100, (float)str_replace(',', '.', post('esc_desc_hermano')))), $lid,
        ]);
        // (v21) Multideporte: descuento en la mensualidad del 2º deporte (grupo extra) del mismo alumno
        q('UPDATE ligas SET esc_desc_segundo = ? WHERE id = ?', [max(0, min(100, (float)str_replace(',', '.', post('esc_desc_segundo')))), $lid]);
        flash('Valores de cobro guardados. Las mensualidades nuevas usarán estos valores.');
    } elseif ($accion === 'cobros_linea') { // (TuAcademia) DeUna, Payphone y clases particulares
        $cel = preg_replace('/[^\d+ ]/', '', post('deuna_celular'));
        if ($cel !== '' && strlen(preg_replace('/\D/', '', $cel)) < 9) throw new RuntimeException('Escriba el número de celular de DeUna completo (10 dígitos).');
        $tk = trim(post('payphone_token')); $st = trim(post('payphone_store_id'));
        if (($tk === '') !== ($st === '')) throw new RuntimeException('Para Payphone necesita las dos claves: el Token y el Store ID.');
        q('UPDATE ligas SET deuna_celular = ?, deuna_titular = ?, payphone_token = ?, payphone_store_id = ?, acad_falta_descuenta = ? WHERE id = ?', [
            mb_substr(trim($cel), 0, 20), mb_substr(trim(post('deuna_titular')), 0, 80), mb_substr($tk, 0, 2000), mb_substr($st, 0, 80), post('acad_falta_descuenta') === '1' ? 1 : 0, $lid]);
        flash('Formas de pago guardadas.');
    } elseif ($accion === 'inscripcion') {
        q('UPDATE ligas SET esc_inscripcion = ?, esc_texto_inscripcion = ? WHERE id = ?', [post('esc_inscripcion') === '1' ? 1 : 0, mb_substr(post('esc_texto_inscripcion'), 0, 800), $lid]);
        flash(post('esc_inscripcion') === '1' ? 'Inscripción en línea abierta.' : 'Inscripción en línea cerrada.');
    } elseif ($accion === 'formativas') {
        // (v17) Modo club: divisiones formativas (partidos, maduración, cuerpo técnico, valoraciones, colegio)
        if (post('esc_formativas') === '1' && !modulo_activo($liga, 'esc_formativas') && !es_super()) throw new RuntimeException('Su plan no incluye las divisiones formativas. Puede cambiar de plan en Mi plan.');
        q('UPDATE ligas SET esc_formativas = ? WHERE id = ?', [post('esc_formativas') === '1' ? 1 : 0, $lid]);
        flash(post('esc_formativas') === '1' ? 'Divisiones formativas activadas: ahora tiene el menú 🏆 Club y la pestaña Club en la ficha de cada alumno.' : 'Divisiones formativas desactivadas (los datos se conservan).');
    } elseif ($accion === 'sede_nueva') {
        if (mb_strlen(post('nombre')) < 2) throw new RuntimeException('Escriba el nombre de la sede.');
        q('INSERT INTO esc_sedes (liga_id, nombre, direccion) VALUES (?,?,?)', [$lid, mb_substr(post('nombre'), 0, 80), mb_substr(post('direccion'), 0, 160)]);
        flash('Sede agregada.');
    } elseif ($accion === 'sede_guardar') {
        q('UPDATE esc_sedes SET nombre = ?, direccion = ?, activa = ? WHERE id = ? AND liga_id = ?', [mb_substr(post('nombre'), 0, 80), mb_substr(post('direccion'), 0, 160), post('activa') === '1' ? 1 : 0, (int)post('id'), $lid]);
        flash('Sede guardada.');
    }
    ir('admin.php?p=escuela_config');
}

$liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
$sedes = filas('SELECT s.*, (SELECT COUNT(*) FROM esc_grupos g WHERE g.sede_id = s.id AND g.activo = 1) AS n_grupos FROM esc_sedes s WHERE s.liga_id = ? ORDER BY s.activa DESC, s.nombre', [$lid]);
$enlace_insc = url_liga($liga) . '?v=inscribirse';
$ac = es_academia() ? 'academia' : 'escuela';
cabecera_admin('Parámetros de la ' . $ac);
$ed = puede('config', true);
?>
<h1>⚙️ Parámetros de la <?= $ac ?></h1>

<div class="tarjeta">
  <h2 style="margin-top:0">Datos de la <?= $ac ?></h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="120" value="<?= e($liga['nombre']) ?>"></div>
      <div><label>Ciudad / sector</label><input type="text" name="ciudad" maxlength="80" value="<?= e($liga['ciudad']) ?>"></div>
      <div><label><?= es_academia() ? 'Disciplina principal' : 'Deporte principal' ?></label><select name="deporte_escuela"><?= opciones_disciplinas((string)$liga['deporte_escuela'], fn($k) => deporte_escuela_permitido($liga, $k)) ?></select><div class="ayuda"><?= es_academia() ? 'Cada curso puede tener otra disciplina (Cursos y horarios → Disciplina).' : 'Cada grupo puede tener otro deporte (Grupos y horarios → Deporte).' ?></div></div>
      <div><label>Logo</label><input type="file" name="logo" accept="image/*"><?= $liga['logo'] ? '<div class="ayuda">' . escudo($liga['logo'], $liga['nombre'], $liga['color'], 'mini') . ' ya tiene logo</div>' : '' ?></div>
      <div><label>Color principal</label><input type="color" name="color" value="<?= e(color_valido($liga['color'])) ?>"></div>
      <div><label>Color secundario</label><input type="color" name="color2" value="<?= e(color_valido((string)$liga['color2'], '#0f1614')) ?>"><div class="ayuda"><label style="display:inline;font-weight:400"><input type="checkbox" name="sin_color2" value="1" <?= $liga['color2'] === '' ? 'checked' : '' ?>> no usar</label></div></div>
      <div><label>Contacto (nombre)</label><input type="text" name="contacto_nombre" maxlength="80" value="<?= e($liga['contacto_nombre']) ?>"></div>
      <div><label>Teléfono / WhatsApp</label><input type="tel" name="contacto_telefono" maxlength="30" value="<?= e($liga['contacto_telefono']) ?>"></div>
      <div><label>Correo</label><input type="email" name="contacto_email" value="<?= e($liga['contacto_email']) ?>"></div>
      <div class="campo-ancho"><label>Datos para pagar (banco, cuenta, a nombre de)</label><textarea name="datos_pago" rows="2" maxlength="500"><?= e($liga['datos_pago']) ?></textarea><div class="ayuda">Los ven las familias en su portal y en los recibos.</div></div>
    </div>
    <?php if ($ed): ?><button class="btn">Guardar</button><?php endif; ?>
  </form>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">💵 <?= es_academia() ? 'Pensiones' : 'Cobros' ?></h2>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cobros">
    <div class="campos">
      <div><label><?= es_academia() ? 'Pensión general ($)' : 'Mensualidad general ($)' ?></label><input type="number" name="esc_mensualidad" min="0" step="0.01" value="<?= e(number_format((float)$liga['esc_mensualidad'], 2, '.', '')) ?>"><div class="ayuda">Cada <?= es_academia() ? 'curso' : 'grupo' ?> o alumno puede tener su propio valor.</div></div>
      <div><label>Matrícula ($)</label><input type="number" name="esc_matricula" min="0" step="0.01" value="<?= e(number_format((float)$liga['esc_matricula'], 2, '.', '')) ?>"><div class="ayuda">Se cobra al aprobar un alumno nuevo.</div></div>
      <div><label>Día de vencimiento de la <?= es_academia() ? 'pensión' : 'mensualidad' ?></label><input type="number" name="esc_dia_vence" min="1" max="28" value="<?= (int)$liga['esc_dia_vence'] ?>"><div class="ayuda">Ej: 5 = vence el 5 de cada mes.</div></div>
      <div><label>Descuento por hermano (%)</label><input type="number" name="esc_desc_hermano" min="0" max="100" step="0.5" value="<?= e((string)(float)$liga['esc_desc_hermano']) ?>"><div class="ayuda">Desde el 2º hijo del mismo representante (misma cédula).</div></div>
      <div><label>Descuento por segundo deporte (%)</label><input type="number" name="esc_desc_segundo" min="0" max="100" step="0.5" value="<?= e((string)(float)($liga['esc_desc_segundo'] ?? 0)) ?>"><div class="ayuda">Cuando un alumno está en otro grupo (otro deporte), esa mensualidad lleva este descuento.</div></div>
    </div>
    <?php if ($ed): ?><button class="btn">Guardar</button><?php endif; ?>
  </form>
</div>

<?php if (es_academia()): // (TuAcademia) DeUna, Payphone y clases particulares ?>
<div class="tarjeta" id="cobros-linea">
  <h2 style="margin-top:0">📱 DeUna y pago con tarjeta</h2>
  <p class="suave" style="margin-top:0">Los padres ven estos datos en su portal y en el mensaje de cobro por WhatsApp. Pagan y suben la captura del comprobante; usted lo aprueba en <a href="admin.php?p=cobros&amp;ver=comprobantes">Pagos → Comprobantes</a>.</p>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cobros_linea">
    <div class="campos">
      <div><label>Celular de DeUna de la academia</label><input type="tel" name="deuna_celular" maxlength="20" value="<?= e($liga['deuna_celular'] ?? '') ?>" placeholder="Ej: 0991234567"></div>
      <div><label>A nombre de</label><input type="text" name="deuna_titular" maxlength="80" value="<?= e($liga['deuna_titular'] ?? '') ?>" placeholder="Como sale en DeUna"></div>
    </div>
    <h3>💳 Payphone (opcional)</h3>
    <p class="suave" style="margin-top:0">Para que los padres paguen con tarjeta. En su cuenta de Payphone: <b>Desarrolladores → Crear aplicación (tipo Web)</b>, ponga como <b>URL de respuesta</b> <code><?= e(url_base()) ?>payphone.php</code> y copie aquí el <b>Token</b> y el <b>Store ID</b>.<?= !modulo_activo($liga, 'pago_payphone') ? ' <b>Está en el plan Completo.</b>' : '' ?></p>
    <div class="campos">
      <div class="campo-ancho"><label>Token de Payphone</label><input type="password" name="payphone_token" maxlength="2000" value="<?= e($liga['payphone_token'] ?? '') ?>" autocomplete="off"></div>
      <div><label>Store ID</label><input type="text" name="payphone_store_id" maxlength="80" value="<?= e($liga['payphone_store_id'] ?? '') ?>" autocomplete="off"></div>
    </div>
    <h3>🎹 Clases particulares</h3>
    <label class="check"><input type="checkbox" name="acad_falta_descuenta" value="1" <?= (int)($liga['acad_falta_descuenta'] ?? 1) === 1 ? 'checked' : '' ?>> Si el alumno falta <b>sin avisar</b>, la clase se descuenta de su paquete</label>
    <?php if ($ed): ?><button class="btn" style="margin-top:8px">Guardar</button><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<div class="tarjeta" id="formativas"<?= es_academia() && !acad_muestra_posicion($liga) ? ' style="display:none"' : '' ?>>
  <h2 style="margin-top:0">🏆 Divisiones formativas (modo club)</h2>
  <p class="suave" style="margin-top:0">Para clubes y academias que compiten con sus categorías (Sub-10 a Sub-19). Agrega: <b>partidos con convocatoria y minutos jugados</b>, <b>maduración y pico de crecimiento</b>, <b>notas del cuerpo técnico por área</b> (preparación física, psicología, nutrición, médico), <b>valoraciones con potencial</b>, <b>seguimiento del colegio</b> y <b>lesiones con fases de regreso</b>. Si su escuela es recreativa, déjelo apagado: el menú queda más simple.</p>
  <?php if (!modulo_activo($liga, 'esc_formativas') && !es_super()): ?><p class="aviso aviso-info">Su plan actual no incluye esta sección. <a href="admin.php?p=mi_plan">Ver planes</a>.</p><?php endif; ?>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="formativas">
    <label class="check"><input type="checkbox" name="esc_formativas" value="1" <?= !empty($liga['esc_formativas']) ? 'checked' : '' ?>> Activar las divisiones formativas en esta escuela</label>
    <?php if ($ed): ?><button class="btn" style="margin-top:8px">Guardar</button><?php endif; ?>
  </form>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">📝 Inscripción en línea</h2>
  <p class="suave" style="margin-top:0">Los padres llenan la ficha desde la página de la <?= $ac ?>. Llega a <b>Alumnos → Por aprobar</b>.</p>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="inscripcion">
    <label class="check"><input type="checkbox" name="esc_inscripcion" value="1" <?= $liga['esc_inscripcion'] ? 'checked' : '' ?>> Inscripción en línea abierta</label>
    <p><label>Texto que ven los padres (requisitos, horarios, valores)</label><textarea name="esc_texto_inscripcion" rows="3" maxlength="800"><?= e($liga['esc_texto_inscripcion']) ?></textarea></p>
    <?php if ($ed): ?><button class="btn">Guardar</button><?php endif; ?>
  </form>
  <?php if ($liga['esc_inscripcion']): ?><p>Enlace para compartir: <a href="<?= e($enlace_insc) ?>" target="_blank"><?= e($enlace_insc) ?></a> · <a target="_blank" href="https://wa.me/?text=<?= rawurlencode('Inscripciones abiertas en ' . $liga['nombre'] . ' 👉 ' . $enlace_insc) ?>">📲 WhatsApp</a></p><?php endif; ?>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">🏟 Sedes</h2>
  <?php foreach ($sedes as $s): ?>
    <form method="post" class="acciones" style="margin-bottom:6px;align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="sede_guardar"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <div><label>Nombre</label><input type="text" name="nombre" value="<?= e($s['nombre']) ?>" maxlength="80"></div>
      <div><label>Dirección</label><input type="text" name="direccion" value="<?= e($s['direccion']) ?>" maxlength="160"></div>
      <label class="check"><input type="checkbox" name="activa" value="1" <?= $s['activa'] ? 'checked' : '' ?>> Activa</label>
      <small class="suave"><?= (int)$s['n_grupos'] ?> grupo(s)</small>
      <?php if ($ed): ?><button class="btn btn-sec btn-chico">Guardar</button><?php endif; ?>
    </form>
  <?php endforeach; ?>
  <?php if ($ed): ?>
  <form method="post" class="acciones" style="margin-top:10px;align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="sede_nueva">
    <div><label>Nueva sede</label><input type="text" name="nombre" maxlength="80" placeholder="Ej: Sede Norte"></div>
    <div><label>Dirección</label><input type="text" name="direccion" maxlength="160"></div>
    <button class="btn btn-sec">+ Agregar sede</button>
  </form>
  <?php endif; ?>
</div>
<?= html_tarjeta_idioma_liga($liga) // (v28) ?>
<?= html_tarjeta_afiliacion($liga) // (v28) ?>
<?php
pie_admin();
