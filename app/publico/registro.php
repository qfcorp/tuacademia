<?php
/* PRUEBA GRATIS: cualquier organizador crea su liga en 2 minutos (queda con período de prueba) */
$abierto = (int)ajuste('registro_abierto', 0) === 1;
// (v14) Sin prueba automática: se pide la demo
if (!$abierto) {
    ir('index.php?v=solicitar' . (get('tipo') === 'escuela' ? '&tipo=escuela' : '') . (get('plan') !== '' ? '&plan=' . (int)get('plan') : '') . (get('ref') !== '' ? '&ref=' . rawurlencode(get('ref')) : ''));
}
$dias = (int)ajuste('dias_prueba', 7);
$planes = filas('SELECT * FROM planes WHERE activo = 1 ORDER BY orden, precio_mensual');
$errores = [];

$recomienda = recomendador_visitante(); // (v28) referido
if ($abierto && es_post()) {
    csrf_verificar();
    if (post('sitio') !== '') exit; // campo trampa contra robots
    if (demasiados_intentos('registro', 3, '-1 day')) $errores[] = 'Ya se crearon varias ligas desde su conexión hoy. Si necesita otra, escríbanos.';
    if (post('clave') !== post('clave2')) $errores[] = 'Las dos contraseñas no coinciden.';
    if (post('acepto') !== '1') $errores[] = 'Debe aceptar las condiciones y la política de privacidad.';
    if (strlen(preg_replace('/\D/', '', post('telefono'))) < 9) $errores[] = 'Escriba un número de celular (WhatsApp) para ayudarle con su ' . (es_academia() ? 'academia.' : 'liga.');
    // (v9) Código del vendedor que le recomendó el sistema (escrito o recordado por el enlace)
    $vend = vendedor_del_visitante(post('ref'));
    if (post('ref') !== '' && !$vend) $errores[] = 'El código de vendedor "' . post('ref') . '" no existe. Revíselo o déjelo vacío.';
    if (!$errores) {
        try {
            contexto_auditoria(['usuario' => 'Registro de prueba: ' . mb_substr(post('admin_nombre'), 0, 60) . ' <' . mb_strtolower(post('email')) . '>', 'rol' => 'publico', 'origen' => 'publico']);
            $plan = (int)post('plan_id');
            $r = crear_liga_completa([
                'nombre' => post('nombre'), 'ciudad' => post('ciudad'), 'modalidad' => post('modalidad'), 'color' => post('color'),
                'admin_nombre' => post('admin_nombre'), 'admin_email' => post('email'), 'admin_clave' => post('clave'), 'telefono' => post('telefono'),
                'plan_id' => fila('SELECT id FROM planes WHERE id = ? AND activo = 1', [$plan]) ? $plan : null,
                'vence' => date('Y-m-d', strtotime('+' . $dias . ' days')), 'es_prueba' => 1, 'crear_campeonato' => true,
                'tipo' => es_academia() ? 'escuela' : post('tipo', get('tipo')), 'deporte_escuela' => post('deporte_escuela'), 'deportes' => isset($_POST['deportes']) ? leer_deportes_post() : null, // (v35) · (TuAcademia) siempre academia
                'vendedor_id' => $vend ? (int)$vend['id'] : null, 'origen' => 'registro', // (Tukán Central v2)
            ]);
            registrar_consentimiento('registro', (int)$r['liga_id'], post('admin_nombre'), 'titular', ['ref_tabla' => 'usuarios', 'ref_id' => (int)$r['usuario_id'],
                'texto' => 'Acepto las condiciones del servicio y la política de privacidad, incluido el almacenamiento de los datos en servidores fuera del Ecuador (' . txt_alojamiento() . '). Mi organización será responsable de los datos de sus participantes.']); // (v24)
            anotar_intento('registro');
            if ($rec = recomendador_visitante()) anotar_referido((int)$r['liga_id'], (int)$rec['id']); // (v28) referido
            // Aviso al superadministrador (si tiene su Telegram conectado)
            foreach (filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''") as $s) {
                tg_encolar(null, $s['tg_chat'], '🆕 <b>' . (es_academia() ? 'Nueva academia' : 'Nueva liga') . ' en prueba gratis</b>' . "\n" . e(post('nombre')) . ' (' . e(post('ciudad')) . ')' . "\n👤 " . e(post('admin_nombre')) . ' · ' . e(post('telefono')) . "\n✉ " . e(mb_strtolower(post('email'))) . ($vend ? "\n🤝 Vendedor: " . e($vend['nombre']) : ''));
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = $r['usuario_id'];
            bitacora_evento('sesion', 'Creó su ' . (es_academia() ? 'academia' : 'liga') . ' en prueba gratis e inició sesión', $r['liga_id'], $r['usuario_id']);
            flash('¡Bienvenido! Su ' . (es_academia() ? 'academia' : 'liga') . ' ya está creada con ' . $dias . ' días de prueba gratis. Siga los primeros pasos de abajo.');
            ir('admin.php');
        } catch (RuntimeException $ex) {
            $errores[] = $ex->getMessage();
        }
    }
}
cabecera_publica('Prueba gratis · ' . marca());
?>
<div class="registro">
  <div>
    <h1>Pruebe <?= e(marca()) ?> gratis por <?= $dias ?> días</h1>
    <?php if (es_academia()): ?>
    <p>Cree su academia ahora y úsela completa: alumnos, cursos, asistencia, pensiones con recordatorio por WhatsApp, niveles con certificado, recitales y portal de padres. <b>Sin tarjeta de crédito.</b></p>
    <?php else: ?>
    <p>Cree su liga ahora y úsela completa: equipos, campeonatos, sorteo, fixture, vocalía en vivo, carnets, árbitros y más. <b>Sin tarjeta de crédito.</b></p>
    <?php endif; ?>
    <ul class="lista-check">
      <li>Su <?= es_academia() ? 'academia' : 'liga' ?> lista en 2 minutos</li>
      <li>Página pública con su propia dirección</li>
      <li>Acompañamiento por WhatsApp</li>
      <li>Al terminar la prueba elige un plan y conserva todos sus datos</li>
    </ul>
  </div>
  <div class="tarjeta">
    <?php if (!$abierto): ?>
      <h2>Registro cerrado</h2><p>Por ahora las ligas nuevas se crean con un asesor. <?php $wa = telefono_wa((string)ajuste('ventas_whatsapp')); if ($wa): ?><a target="_blank" href="https://wa.me/<?= e($wa) ?>">Escríbanos por WhatsApp</a>.<?php endif; ?></p>
    <?php else: ?>
    <?php foreach ($errores as $er): ?><div class="aviso aviso-error"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" action="index.php?v=registro">
      <?= csrf_campo() ?>
      <input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
      <h2 style="margin-top:0">Su <?= es_academia() ? 'academia' : 'liga' ?></h2>
      <div class="campos">
        <?php $es_e = es_academia() || post('tipo', get('tipo')) === 'escuela'; ?>
        <?php if (es_academia()): // (TuAcademia) siempre es una academia: no se pregunta el tipo ?>
        <input type="hidden" name="tipo" value="escuela">
        <div><label>Disciplina principal *</label><select name="deporte_escuela"><?= opciones_disciplinas(post('deporte_escuela', 'ballet')) ?></select><div class="ayuda">Después puede crear cursos de otras disciplinas.</div></div>
        <?php else: ?>
        <div><label>Tipo de cuenta</label><select name="tipo" onchange="document.querySelectorAll('.solo-escuela').forEach(function (x) { x.style.display = this.value === 'escuela' ? '' : 'none'; }, this); document.querySelectorAll('.solo-liga').forEach(function (x) { x.style.display = this.value === 'escuela' ? 'none' : ''; }, this)">
          <option value="liga" <?= $es_e ? '' : 'selected' ?>>🏆 Liga (campeonatos)</option><option value="escuela" <?= $es_e ? 'selected' : '' ?>>🎓 Escuela deportiva (alumnos)</option></select></div>
        <div class="solo-escuela" style="<?= $es_e ? '' : 'display:none' ?>"><label>Deporte de la escuela</label><select name="deporte_escuela"><?php foreach (deportes_escuela() as $k => $v): ?><option value="<?= $k ?>" <?= post('deporte_escuela') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="campo-ancho"><label><?= es_academia() ? '¿Qué disciplinas enseña? *' : '¿Qué deportes? *' ?></label><?= html_casillas_modulos(es_post() ? leer_deportes_post() : (get('modulo') !== '' && modulo_info(get('modulo')) ? [get('modulo')] : [es_academia() ? 'baile' : 'futbol']), 'deportes') ?><div class="ayuda"><?= es_academia() ? 'Durante la prueba tiene las disciplinas que marque. Después puede agregar más con descuento.' : 'Durante la prueba tiene los deportes que marque. Después puede agregar más con descuento.' ?></div></div>
        <div class="campo-ancho"><label><?= es_academia() ? 'Nombre de la academia *' : 'Nombre de la liga o escuela *' ?></label><input type="text" name="nombre" required maxlength="120" value="<?= e(post('nombre')) ?>" placeholder="<?= es_academia() ? 'Ej: Academia de Danza Allegro · Escuela de Música Do Re Mi' : 'Ej: Liga Barrial La Floresta · Escuela de Fútbol Los Pumas' ?>"></div>
        <div><label>Ciudad / sector</label><input type="text" name="ciudad" maxlength="80" value="<?= e(post('ciudad')) ?>"></div>
        <?php if (es_academia()): ?><input type="hidden" name="modalidad" value="F7"><?php else: ?>
        <div><label>Modalidad</label><select name="modalidad"><?= opciones_modalidad(post('modalidad', 'F7')) ?></select></div>
        <?php endif; ?>
        <div><label>Color de la <?= es_academia() ? 'academia' : 'liga' ?></label><input type="color" name="color" value="<?= e(color_valido(post('color'), '#0b6e4f')) ?>"></div>
        <?php if ($planes): ?><div><label>Plan que le interesa</label><select name="plan_id"><?php foreach ($planes as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= (int)get('plan', post('plan_id')) === (int)$pl['id'] || (!get('plan') && !post('plan_id') && $pl['destacado']) ? 'selected' : '' ?>><?= e($pl['nombre']) ?> — <?= e(texto_precio_principal($pl)) ?></option><?php endforeach; ?></select><div class="ayuda">Durante la prueba tiene todo. Puede cambiar de plan después.</div></div><?php endif; ?>
      </div>
      <h2>Sus datos (administrador)</h2>
      <div class="campos">
        <div><label>Su nombre completo *</label><input type="text" name="admin_nombre" required maxlength="80" value="<?= e(post('admin_nombre')) ?>" autocomplete="name"></div>
        <div><label>Celular / WhatsApp *</label><input type="tel" name="telefono" required maxlength="30" value="<?= e(post('telefono')) ?>" autocomplete="tel"></div>
        <div class="campo-ancho"><label>Correo (será su usuario) *</label><input type="email" name="email" required value="<?= e(post('email')) ?>" autocomplete="email"></div>
        <div><label>Contraseña (mín. 8) *</label><input type="password" name="clave" required minlength="8" autocomplete="new-password"></div>
        <div><label>Repita la contraseña *</label><input type="password" name="clave2" required minlength="8" autocomplete="new-password"></div>
        <?php $vref = vendedor_del_visitante(get('ref')) ?? vendedor_del_visitante(); ?>
        <div><label>Código de vendedor (opcional)</label><input type="text" name="ref" maxlength="20" value="<?= e(post('ref', $vref['codigo'] ?? '')) ?>" style="text-transform:uppercase" placeholder="Si alguien le recomendó"><?php if ($vref): ?><div class="ayuda">Le atiende: <?= e($vref['nombre']) ?></div><?php endif; ?></div>
        <div class="campo-ancho"><label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required> Acepto las condiciones del servicio y la <a href="index.php?v=privacidad" target="_blank">política de privacidad</a>, incluido el almacenamiento de los datos en servidores fuera del Ecuador. Mi organización será responsable de los datos de sus participantes.</label></div>
      </div>
      <button class="btn btn-grande" type="submit">Crear mi <?= es_academia() ? 'academia' : 'liga' ?> gratis</button>
    </form>
    <p class="suave" style="text-align:center">¿Ya tiene cuenta? <a href="admin.php?p=login">Ingresar</a></p>
    <?php endif; ?>
  </div>
</div>
<?php
pie_publico();
