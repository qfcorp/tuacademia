<?php
/*
 * (21 oct 2026) AYUDA: «¿En qué le ayudamos?». Para TODOS (todos los planes y perfiles). Responde con las preguntas
 * frecuentes guardadas, la IA con el manual del modo de la cuenta (ligas, escuelas, federaciones o academia) o el manual
 * tal cual + WhatsApp (app/asistente_ia.php). Máximo 10 preguntas por minuto por usuario.
 */
$liga = liga_trabajo();
$pregunta = '';
$resp = null;
if (es_post()) {
    $pregunta = trim(mb_substr(post('pregunta'), 0, 500));
    if ($pregunta === '') {
        flash('Escriba su pregunta.', 'error');
        ir('admin.php?p=ayuda');
    }
    if (!ia_puede_preguntar()) {
        $resp = ['respuesta' => 'Recibimos muchas preguntas seguidas. Espere un minuto y vuelva a preguntar, por favor.', 'fuente' => 'espera'];
    } else {
        $resp = ia_ayuda($liga, $pregunta);
        if (($resp['fuente'] ?? '') === 'ia') bitacora_evento('ayuda', 'Preguntó en la Ayuda: ' . mb_substr($pregunta, 0, 200), $liga ? (int)$liga['id'] : null);
    }
}
$wa = '593996408397';
cabecera_admin('Ayuda');
?>
<h1>❓ ¿En qué le ayudamos?</h1>
<div class="tarjeta" style="max-width:760px">
  <form method="post" action="admin.php?p=ayuda#respuesta">
    <?= csrf_campo() ?>
    <label for="ayuda-pregunta">Escriba su pregunta</label>
    <textarea id="ayuda-pregunta" name="pregunta" rows="3" maxlength="500" required placeholder="¿Cómo registro un pago?"><?= e($pregunta) ?></textarea>
    <div class="ayuda">Le respondemos con el manual de <?= e(marca()) ?>.</div>
    <button class="btn" style="margin-top:8px">Preguntar</button>
  </form>
</div>
<?php if ($resp): ?>
<div class="tarjeta" id="respuesta" style="max-width:760px">
  <h2 style="margin-top:0"><?= e($pregunta) ?></h2>
  <div class="ayuda-respuesta" style="line-height:1.55"><?= ia_html((string)$resp['respuesta']) ?></div>
  <p class="suave" style="margin-bottom:0">¿Le sirvió? Si no, escríbanos por WhatsApp <a target="_blank" rel="noopener" href="https://wa.me/<?= $wa ?>?text=<?= rawurlencode('Hola, tengo una duda con ' . marca() . ': ' . $pregunta) ?>">099 640 8397</a>.</p>
</div>
<?php endif; ?>
<p class="suave">También puede leer los <a href="manual" target="_blank">manuales</a> o abrir un <a href="admin.php?p=soporte">ticket de soporte</a>.</p>
<?php if (es_super()): ?><div class="aviso aviso-info">🛠 Superadministrador: <a href="admin.php?p=ayuda_faq">revisar las preguntas frecuentes</a>.</div><?php endif; ?>
<?php
pie_admin();
