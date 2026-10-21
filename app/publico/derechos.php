<?php
/*
 * (v24) EJERCER MIS DERECHOS (LOPDP): acceso, rectificación, eliminación, oposición, suspensión, portabilidad
 * y retirar la autorización. Llega a la bandeja "Protección de datos" del panel de la liga/escuela
 * (o del superadministrador si se envía desde la página general).
 */
$liga = $liga ?? null;
$errores = [];
$numero = null;
$accion = $liga ? 'index.php?l=' . rawurlencode($liga['slug']) . '&v=derechos' : 'index.php?v=derechos';
$url_priv = $liga ? 'index.php?l=' . rawurlencode($liga['slug']) . '&v=privacidad' : 'index.php?v=privacidad';
$ligas_lista = $liga ? [] : filas("SELECT id, nombre FROM ligas WHERE activa = 1 AND es_demo = 0 ORDER BY nombre");

if (es_post()) {
    csrf_verificar();
    if (post('sitio') !== '') exit; // campo trampa contra robots
    try {
        if (demasiados_intentos('derechos', 5, '-1 day')) throw new RuntimeException('Ya recibimos varias solicitudes desde su conexión hoy. Le responderemos pronto.');
        if (post('acepto') !== '1') throw new RuntimeException('Marque la casilla para que podamos usar estos datos para responderle.');
        $destino = $liga;
        if (!$destino && (int)post('liga_id') > 0) $destino = fila('SELECT * FROM ligas WHERE id = ? AND activa = 1', [(int)post('liga_id')]) ?: null;
        contexto_auditoria(['usuario' => 'Solicitud de derechos: ' . mb_substr(post('nombre'), 0, 60), 'rol' => 'publico', 'origen' => 'publico']);
        $numero = crear_solicitud_derecho($destino, $_POST);
        anotar_intento('derechos');
        // Aviso al superadministrador cuando no se eligió organización
        if (!$destino) {
            foreach (filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''") as $su) {
                tg_encolar(null, $su['tg_chat'], '🔒 <b>Solicitud de protección de datos</b> N.º ' . $numero . "\n" . e(tipos_derecho()[post('tipo')] ?? '') . "\n" . e(post('nombre')), '', 'der:' . $numero . ':' . $su['tg_chat']);
            }
        }
    } catch (RuntimeException $ex) {
        $errores[] = $ex->getMessage();
    }
}
cabecera_publica('Ejercer mis derechos' . ($liga ? ' · ' . $liga['nombre'] : ''), $liga);
?>
<div class="tarjeta legal" style="max-width:760px;margin:0 auto">
  <h1>🔒 Ejercer mis derechos sobre mis datos</h1>
<?php if ($numero): ?>
  <div class="aviso aviso-ok">✓ Recibimos su solicitud <b>N.º <?= (int)$numero ?></b>. Le responderemos por correo o celular en un plazo máximo de 15 días. Guarde este número.</div>
  <p><a href="<?= e($liga ? url_liga($liga) : 'index.php') ?>">← Volver</a></p>
<?php else: ?>
  <p>Usted (o el representante de un menor de edad) puede pedir <?= $liga ? 'a <b>' . e($liga['nombre']) . '</b>' : '' ?> ver, corregir o borrar sus datos personales, entre otros derechos. No tiene costo. <a href="<?= e($url_priv) ?>" target="_blank">Ver la política de privacidad</a>.</p>
  <?php foreach ($errores as $er): ?><div class="aviso aviso-error"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" action="<?= e($accion) ?>">
    <?= csrf_campo() ?>
    <input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
    <div class="campos">
      <div class="campo-ancho"><label>¿Qué quiere hacer? *</label>
        <select name="tipo" required><option value="">— Elija —</option>
        <?php foreach (tipos_derecho() as $k => $txt): ?><option value="<?= e($k) ?>" <?= post('tipo') === $k ? 'selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?>
        </select></div>
      <?php if (!$liga && $ligas_lista): ?>
      <div class="campo-ancho"><label>¿En qué liga o escuela está registrado?</label>
        <select name="liga_id"><option value="0">No sé / es sobre <?= e(marca()) ?></option>
        <?php foreach ($ligas_lista as $lg): ?><option value="<?= (int)$lg['id'] ?>" <?= (int)post('liga_id') === (int)$lg['id'] ? 'selected' : '' ?>><?= e($lg['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div><label>Su nombre completo *</label><input type="text" name="nombre" required maxlength="120" value="<?= e(post('nombre')) ?>" autocomplete="name"></div>
      <div><label>Cédula o pasaporte *</label><input type="text" name="cedula" required maxlength="20" value="<?= e(post('cedula')) ?>"><div class="ayuda">Para comprobar que es el titular.</div></div>
      <div><label>Correo</label><input type="email" name="email" maxlength="120" value="<?= e(post('email')) ?>" autocomplete="email"></div>
      <div><label>Celular / WhatsApp</label><input type="tel" name="telefono" maxlength="30" value="<?= e(post('telefono')) ?>" autocomplete="tel"><div class="ayuda">Escriba al menos el correo o el celular.</div></div>
      <div class="campo-ancho"><label>Si lo pide por un menor de edad: nombre del menor</label><input type="text" name="en_nombre_de" maxlength="120" value="<?= e(post('en_nombre_de')) ?>" placeholder="Déjelo vacío si es sobre usted"></div>
      <div class="campo-ancho"><label>¿Qué necesita? *</label><textarea name="detalle" rows="4" maxlength="1500" required placeholder="Ej: corregir mi fecha de nacimiento · borrar la foto de mi hijo · enviarme una copia de mis datos"><?= e(post('detalle')) ?></textarea></div>
      <div class="campo-ancho"><label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required> Autorizo el uso de estos datos solo para atender mi solicitud.</label></div>
    </div>
    <button class="btn btn-grande" type="submit">📨 Enviar solicitud</button>
  </form>
<?php endif; ?>
</div>
<?php
pie_publico();
