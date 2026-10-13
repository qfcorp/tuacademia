<?php
/* (v11) PÁGINA PÚBLICA DE UNA ESCUELA: presentación, grupos y horarios por sede, e inscripción en línea (42) */
$lid = (int)$liga['id'];
$abierta = (int)$liga['esc_inscripcion'] === 1 && modulo_activo($liga, 'esc_familias'); // (v12) según el plan
$enviado = null;
if (get('v') === 'inscribirse' && es_post() && $abierta) {
    csrf_verificar();
    if (post('sitio') !== '') exit;
    contexto_auditoria(['usuario' => 'Inscripción en línea: ' . mb_substr(post('representante_nombre'), 0, 60), 'rol' => 'publico', 'liga_id' => $lid, 'origen' => 'publico']);
    try {
        if (demasiados_intentos('insc_escuela', 5, '-1 hour')) throw new RuntimeException('Demasiadas inscripciones seguidas desde esta conexión. Escríbanos por WhatsApp.');
        if (post('acepto') !== '1') throw new RuntimeException('Debe autorizar el uso de los datos para inscribir al alumno.');
        try { verificar_alumnos_plan($liga); } catch (RuntimeException) { throw new RuntimeException('Por ahora la escuela no recibe más inscripciones en línea. Comuníquese con la escuela.'); }
        $d = leer_alumno_post($lid);
        if ($d['representante_cedula'] === '' || strlen(preg_replace('/\D/', '', $d['representante_telefono'])) < 7) throw new RuntimeException('Escriba la cédula y el celular del representante.');
        if ($d['cedula'] !== '' && fila("SELECT id FROM esc_alumnos WHERE liga_id = ? AND cedula = ? AND estado IN ('activo','pendiente')", [$lid, $d['cedula']])) throw new RuntimeException('Ese alumno ya está inscrito o su inscripción está en revisión.');
        if (!$d['grupo_id'] && ($gs = grupo_sugerido($lid, $d['fecha_nac']))) $d['grupo_id'] = (int)$gs['id'];
        $d['beca'] = 0; $d['mensualidad_especial'] = null; $d['dia_vence'] = null; $d['fecha_ingreso'] = date('Y-m-d');
        $d += ['liga_id' => $lid, 'estado' => 'pendiente', 'origen' => 'publico', 'consentimiento' => date('Y-m-d H:i:s'), 'consentimiento_por' => es_menor($d['fecha_nac']) ? 'representante' : 'alumno'];
        if ($f = subir_imagen('foto', 'jugadores', 700)) $d['foto'] = $f;
        q('INSERT INTO esc_alumnos (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
        $aid_n = (int)db()->lastInsertId();
        registrar_consentimiento_alumno($lid, $d, $aid_n, true, $liga['nombre']); // (v24) prueba de la autorización
        anotar_intento('insc_escuela');
        aviso_admins($liga, '📝 <b>Nueva inscripción en línea</b>' . "\n" . e(trim($d['nombres'] . ' ' . $d['apellidos'])) . ' (' . e(sub_de($d['fecha_nac'])) . ')' . "\n👤 " . e($d['representante_nombre']) . ' · ' . e($d['representante_telefono']), 'insc:' . $aid_n);
        $_SESSION['insc_ok'] = trim($d['nombres'] . ' ' . $d['apellidos']);
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        $_SESSION['insc_form'] = $_POST;
    }
    ir($base . '&v=inscribirse');
}
$ok = $_SESSION['insc_ok'] ?? null;
unset($_SESSION['insc_ok']);
$prev = $_SESSION['insc_form'] ?? [];
unset($_SESSION['insc_form']);
$grupos = filas('SELECT g.*, s.nombre AS sede, s.direccion, p.nombre AS profesor FROM esc_grupos g LEFT JOIN esc_sedes s ON s.id = g.sede_id LEFT JOIN esc_profesores p ON p.id = g.profesor_id WHERE g.liga_id = ? AND g.activo = 1 ORDER BY s.nombre, g.anio_desde DESC, g.nombre', [$lid]);
$por_sede = [];
foreach ($grupos as $g) $por_sede[$g['sede'] ?? 'Sede'][] = $g;
$wa = telefono_wa((string)$liga['contacto_telefono']);
cabecera_publica($liga['nombre'], $liga);
foreach (tomar_flash() as [$tipo, $msg]) echo '<div class="aviso aviso-' . e($tipo) . '">' . e($msg) . '</div>';
?>
<div class="tarjeta" style="text-align:center">
  <h1 style="margin:.2rem 0">🎓 <?= e($liga['nombre']) ?></h1>
  <p class="suave" style="margin:0"><?php $deps_pub = deportes_de_escuela($liga); ?><?= count($deps_pub) > 1 ? 'Escuela deportiva: ' . e(texto_deportes($deps_pub)) : 'Escuela de ' . e(mb_strtolower(nombre_deporte_corto($deps_pub[0]))) ?><?= $liga['ciudad'] ? ' · ' . e($liga['ciudad']) : '' ?></p>
  <div class="acciones" style="justify-content:center;margin-top:10px">
    <?php if ($abierta): ?><a class="btn btn-grande" href="<?= e($base . '&v=inscribirse') ?>#inscripcion">📝 Inscribir a mi hijo/a</a><?php endif; ?>
    <?php if ($wa): ?><a class="btn btn-sec" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, quisiera información de ' . $liga['nombre']) ?>">💬 WhatsApp</a><?php endif; ?>
    <a class="btn btn-sec" href="familia.php">👨‍👩‍👦 Portal de padres</a>
  </div>
</div>

<h2>Grupos y horarios</h2>
<?php if (!$grupos): ?><p class="suave">Pronto publicaremos los horarios.</p><?php endif; ?>
<?php foreach ($por_sede as $sede => $gs): ?>
  <h3><?= e($sede) ?><?= $gs[0]['direccion'] ? ' <small class="suave">· 📍 ' . e($gs[0]['direccion']) . '</small>' : '' ?></h3>
  <div class="tabla-scroll"><table><tr><th>Grupo</th><th>Deporte</th><th>Categoría</th><th>Días y hora</th><th>Profesor</th></tr>
    <?php foreach ($gs as $g): ?><tr><td><b><?= e($g['nombre']) ?></b></td><td><?= chip_deporte(deporte_grupo($g, $liga)) ?></td><td><?= e(texto_categoria_grupo($g)) ?></td><td><?= e(texto_horario($g)) ?><?= $g['cancha'] ? ' · ' . e($g['cancha']) : '' ?></td><td><?= e($g['profesor'] ?? '') ?></td></tr><?php endforeach; ?>
  </table></div>
<?php endforeach; ?>

<?php if ($abierta): ?>
<div class="tarjeta" id="inscripcion" style="margin-top:16px">
  <h2 style="margin-top:0">📝 Inscripción en línea</h2>
  <?php if ($ok): ?>
    <div class="aviso aviso-ok">✓ ¡Recibimos la inscripción de <b><?= e($ok) ?></b>! La escuela la revisará y se comunicará con usted.</div>
  <?php else: ?>
    <?php if ($liga['esc_texto_inscripcion']): ?><div class="aviso aviso-info"><?= nl2br(e($liga['esc_texto_inscripcion'])) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e($base . '&v=inscribirse') ?>">
      <?= csrf_campo() ?><input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
      <?php $a = $prev; $grupos_form = $grupos; ?>
      <div class="campos">
        <div><label>Nombres del alumno *</label><input type="text" name="nombres" required maxlength="60" value="<?= e($a['nombres'] ?? '') ?>"></div>
        <div><label>Apellidos *</label><input type="text" name="apellidos" required maxlength="60" value="<?= e($a['apellidos'] ?? '') ?>"></div>
        <div><label>Cédula del alumno</label><input type="text" name="cedula" maxlength="20" value="<?= e($a['cedula'] ?? '') ?>"></div>
        <div><label>Fecha de nacimiento *</label><input type="date" name="fecha_nac" required value="<?= e($a['fecha_nac'] ?? '') ?>"></div>
        <div><label>Género</label><select name="genero"><option value="">—</option><option value="M">Masculino</option><option value="F">Femenino</option></select></div>
        <div><label>Grupo que prefiere</label><select name="grupo_id"><option value="">El que corresponda por su edad</option><?php foreach ($grupos as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e(icono_deporte_escuela(deporte_grupo($g, $liga)) . ' ' . $g['nombre'] . ' · ' . texto_horario($g)) ?></option><?php endforeach; ?></select></div>
        <div><label>Colegio</label><input type="text" name="colegio" maxlength="100" value="<?= e($a['colegio'] ?? '') ?>"></div>
        <div><label>Foto del alumno (de frente)</label><input type="file" name="foto" accept="image/*" capture="user"></div>
      </div>
      <h3>Representante</h3>
      <div class="campos">
        <div><label>Nombre *</label><input type="text" name="representante_nombre" required maxlength="80" value="<?= e($a['representante_nombre'] ?? '') ?>"></div>
        <div><label>Cédula *</label><input type="text" name="representante_cedula" required maxlength="20" value="<?= e($a['representante_cedula'] ?? '') ?>"></div>
        <div><label>Parentesco</label><input type="text" name="parentesco" maxlength="30" value="<?= e($a['parentesco'] ?? '') ?>"></div>
        <div><label>Celular (WhatsApp) *</label><input type="tel" name="representante_telefono" required maxlength="30" value="<?= e($a['representante_telefono'] ?? '') ?>"></div>
        <div><label>Correo</label><input type="email" name="representante_email" value="<?= e($a['representante_email'] ?? '') ?>"></div>
        <div class="campo-ancho"><label>Dirección</label><input type="text" name="direccion" maxlength="160" value="<?= e($a['direccion'] ?? '') ?>"></div>
      </div>
      <h3>Salud</h3>
      <div class="campos">
        <div><label>Tipo de sangre</label><select name="tipo_sangre"><option value="">—</option><?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $t): ?><option><?= $t ?></option><?php endforeach; ?></select></div>
        <div><label>Alergias</label><input type="text" name="alergias" maxlength="200" value="<?= e($a['alergias'] ?? '') ?>"></div>
        <div><label>Enfermedades o condiciones</label><input type="text" name="condiciones" maxlength="200" value="<?= e($a['condiciones'] ?? '') ?>"></div>
        <div><label>Contacto de emergencia (teléfono)</label><input type="tel" name="emergencia_telefono" maxlength="30" value="<?= e($a['emergencia_telefono'] ?? '') ?>"></div>
      </div>
      <label class="check" style="font-weight:400"><input type="checkbox" name="foto_publica" value="1"> Autorizo publicar la foto del alumno en la página de la escuela</label>
      <label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required> Autorizo a <?= e($liga['nombre']) ?> a registrar y usar los datos, fotos, medidas y evaluaciones del alumno para la gestión de la escuela, según la <a href="<?= e($base . '&v=privacidad') ?>" target="_blank">política de privacidad</a>, incluido su almacenamiento en servidores fuera del Ecuador.</label>
      <button class="btn btn-grande" style="margin-top:10px">Enviar inscripción</button>
    </form>
  <?php endif; ?>
</div>
<?php endif;
pie_publico();
