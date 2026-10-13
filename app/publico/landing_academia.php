<?php
/* (TuAcademia) PÁGINA DE VENTA para academias de baile, música, natación y deportes (portada: tuacademia.tukanec.com).
 * La de ligas (landing.php) queda guardada para PRODUCTO = 'ligas'. */
$planes = filas("SELECT * FROM planes WHERE activo = 1 AND tipo_cuenta IN ('escuela', 'ambos') ORDER BY orden, precio_mensual");
$planes_vista = array_values(array_filter($planes, fn($pl) => opciones_precio($pl)));
$wa = telefono_wa((string)ajuste('ventas_whatsapp'));
$registro = prueba_automatica();
$dias = (int)ajuste('dias_prueba', 14);
$enlace_wa = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hola, quiero información de ' . marca() . ' para mi academia') : '';
$mods_vista = modulos_sistema('escuela');
cabecera_publica(marca() . ' · Sistema para academias de baile, música, natación y deportes');

$para = [
    ['💃', 'Academias de baile', 'Ballet, contemporáneo, salsa, bachata, urbano, folclor, tap, árabe. Niveles de Iniciación a Pre-profesional, recitales por coreografía y cobro de vestuario con su talla.'],
    ['🎹', 'Escuelas de música', 'Piano, guitarra, canto, violín, batería, bajo, ukelele y teoría. Clases particulares con paquetes de 4, 8 o 12 clases, niveles 1 a 6 y recitales por obra.'],
    ['🏊', 'Escuelas de natación', 'Desde matronatación hasta competitivo, con carril de cada alumno, niveles por estilo, tests de 25 y 50 metros y festivales.'],
    ['⚽', 'Escuelas deportivas', 'Fútbol, básquet, vóley, artes marciales con cinturones, tenis y más, con categorías por edad, evaluaciones y portal de padres.'],
];
$beneficios = [
    ['📲', 'Cobre sin perseguir a nadie', 'Cada mañana, la lista de pensiones por vencer y vencidas con el mensaje de WhatsApp listo para cada padre. Un toque y se envía.'],
    ['💸', 'DeUna, transferencia o tarjeta', 'El padre paga por DeUna o transferencia y sube la foto del comprobante desde su celular; usted lo aprueba y sale el recibo. También con tarjeta por Payphone.'],
    ['🎖️', 'Niveles con certificado', 'Exámenes de promoción con acta y certificado con código QR que cualquiera puede verificar. Viene con los niveles de baile, música y natación.'],
    ['🎭', 'Recitales ordenados', 'Arme el recital por coreografía u obra, con los alumnos de cada una, sus tallas, los ensayos y el cobro de vestuario que llega al portal de cada familia.'],
    ['🎹', 'Clases particulares', 'Paquetes de clases con saldo: al marcar la asistencia, la clase se descuenta sola y el padre ve cuántas le quedan.'],
    ['👨‍👩‍👧', 'Padres informados', 'Cada familia ve en su celular la asistencia, lo que debe, los recibos, el nivel y las evaluaciones de sus hijos.'],
];
$funciones = [
    ['👦', 'Alumnos con ficha completa', 'Foto, fecha de nacimiento, ficha médica, representante, instrumento, talla de vestuario o carril.'],
    ['🗓', 'Cursos, horarios y salones', 'Cada curso con su disciplina, profesor, días, horas y lugar (salón, aula o piscina).'],
    ['✅', 'Asistencia desde el celular', 'El profesor toma lista en segundos y anota lo que se trabajó en la clase.'],
    ['💵', 'Pensiones con vencimiento', 'Se generan solas cada mes, con becas y descuento por hermanos. Semáforo de quién está al día.'],
    ['🧾', 'Recibos al instante', 'Cada pago con su recibo para imprimir o mandar por WhatsApp.'],
    ['🎖️', 'Niveles y certificados con QR', 'Niveles de fábrica que usted puede cambiar, exámenes con cobro, acta y certificado.'],
    ['🎭', 'Recitales y presentaciones', 'Coreografías u obras, participantes, tallas, ensayos y cobro de vestuario e inscripción.'],
    ['🎹', 'Paquetes de clases', 'Paquetes de 4, 8 o 12 clases con horario 1 a 1 y saldo que baja solo.'],
    ['📲', 'Mensajes de hoy por WhatsApp', 'Recordatorios de pensiones listos para enviar y marcados como enviados.'],
    ['🧑‍🏫', 'Profesores y rol de pago', 'Clases dictadas, pago mensual, por clase o por alumno, y rol de pago.'],
    ['📏', 'Evaluaciones y desarrollo', 'Tests de flexibilidad, lectura musical o 25 metros libre, con gráficos de avance.'],
    ['🪪', 'Carnet con QR', 'Carnet del alumno con foto y código QR, también en el celular de los padres.'],
    ['📝', 'Inscripción en línea', 'Los padres inscriben a sus hijos desde la página de la academia.'],
    ['🌐', 'Página de su academia', 'Sus cursos, horarios y el botón de inscripción, con su propia dirección.'],
    ['🛍', 'Tienda', 'Uniformes, mallas, zapatillas o libros: venta con recibo o a la cuenta del alumno.'],
    ['🔐', 'Seguro y privado', 'Datos de menores protegidos, autorización del representante y registro de cada cambio.'],
];
?>
<nav class="nav-venta"><div class="barra-in">
  <a href="#para-quien">Para quién</a><a href="#beneficios">Beneficios</a><a href="#planes">Planes</a><a href="#disciplinas">Disciplinas</a><a href="#preguntas">Preguntas</a>
  <a class="nav-cta" href="<?= e(url_empezar('escuela')) ?>"><?= e(texto_empezar(true)) ?></a>
</div></nav>

<section class="hero">
  <h1><?= e(ajuste('landing_titulo', 'El sistema para su academia')) ?></h1>
  <p><?= e(ajuste('landing_subtitulo', 'Baile, música, natación y deportes: alumnos, pensiones, asistencia, niveles con certificado, recitales y portal de padres.')) ?></p>
  <div class="hero-botones">
    <a class="btn btn-grande btn-blanco" href="<?= e(url_empezar('escuela')) ?>"><?= e(texto_empezar()) ?></a>
    <?php if ($enlace_wa): ?><a class="btn btn-grande btn-wa" target="_blank" href="<?= e($enlace_wa) ?>">💬 Escríbanos por WhatsApp</a><?php endif; ?>
    <?php if (escuela_demo()): ?><a class="btn btn-grande btn-sec-blanco" href="index.php?v=demo">👀 Ver demo</a><?php endif; ?>
  </div>
  <p class="hero-nota"><?= $registro ? 'Sin tarjeta de crédito · Lista en 2 minutos · ' : 'Demo guiada y acceso de prueba para su academia · ' ?>Soporte por WhatsApp en Ecuador</p>
</section>

<h2 class="titulo-seccion" id="para-quien">Hecho para su academia</h2>
<div class="funciones">
  <?php foreach ($para as [$ico, $tit, $txt]): ?><div class="funcion"><span class="funcion-ico"><?= $ico ?></span><h3><?= e($tit) ?></h3><p><?= e($txt) ?></p></div><?php endforeach; ?>
</div>

<h2 class="titulo-seccion" id="beneficios">¿Por qué <?= e(marca()) ?>?</h2>
<div class="funciones">
  <?php foreach ($beneficios as [$ico, $tit, $txt]): ?><div class="funcion"><span class="funcion-ico"><?= $ico ?></span><h3><?= e($tit) ?></h3><p><?= e($txt) ?></p></div><?php endforeach; ?>
</div>

<h2 class="titulo-seccion" id="como-funciona">Así de fácil</h2>
<div class="pasos-venta">
  <div><b>1</b><h3>Cree su academia</h3><p>Nombre, disciplinas, pensión y listo<?= $registro ? ' (gratis ' . $dias . ' días)' : ' (pida su demo y la preparamos con usted)' ?>.</p></div>
  <div><b>2</b><h3>Arme sus cursos</h3><p>Ballet infantil, Piano nivel 1, Natación bebés: con horario, salón y profesor.</p></div>
  <div><b>3</b><h3>Cargue alumnos</h3><p>Usted o los padres por internet, con foto, ficha médica y su nivel.</p></div>
  <div><b>4</b><h3>¡A clases!</h3><p>Asistencia, pensiones con recordatorio por WhatsApp, niveles y recitales.</p></div>
</div>

<?php if ($planes_vista): $periodos_hay = []; foreach ($planes_vista as $pl) foreach (opciones_precio($pl) as $k => $o) $periodos_hay[$k] = $o['nombre']; $orden_p = array_intersect(['mensual', 'trimestral', 'semestral', 'anual'], array_keys($periodos_hay)); ?>
<h2 class="titulo-seccion" id="planes">Planes</h2>
<?php if (count($orden_p) > 1): ?>
<div class="periodos" id="periodos">
  <?php foreach ($orden_p as $k): ?><button type="button" data-p="<?= $k ?>" class="<?= $k === 'mensual' ? 'activo' : '' ?>"><?= e($periodos_hay[$k]) ?><?php if ($k === 'semestral'): ?> <small>paga 5, recibe 6</small><?php elseif ($k === 'anual'): ?> <small>paga 10, recibe 12</small><?php endif; ?></button><?php endforeach; ?>
</div>
<?php endif; ?>
<div class="planes planes-v12">
  <?php foreach ($planes_vista as $pl): $ops = opciones_precio($pl); $mp = modulos_plan($pl); ?>
    <div class="plan<?= $pl['destacado'] ? ' plan-destacado' : '' ?>">
      <?php if ($pl['destacado']): ?><span class="plan-cinta">Recomendado</span><?php endif; ?>
      <h3><?= e($pl['nombre']) ?></h3>
      <?php if ($pl['eslogan'] !== ''): ?><p class="suave plan-eslogan"><?= e($pl['eslogan']) ?></p><?php endif; ?>
      <?php foreach (['mensual', 'trimestral', 'semestral', 'anual'] as $k): $o = $ops[$k] ?? reset($ops); ?>
        <div class="plan-precio-p" data-p="<?= $k ?>" <?= $k === 'mensual' ? '' : 'hidden' ?>>
          <p class="plan-precio"><?= dinero($o['precio']) ?><small> <?= e($o['texto']) ?></small></p>
          <p class="suave plan-equiv"><?= $o['meses'] > 1 ? 'Equivale a ' . dinero($o['por_mes']) . ' al mes' : '&nbsp;' ?><?= $o['gratis'] ? ' · <b>' . $o['gratis'] . ' mes' . ($o['gratis'] === 1 ? '' : 'es') . ' gratis</b>' : '' ?><?= !empty($o['dominio']) ? '<br><b class="plan-dominio">🌐 + dominio propio gratis*</b>' : '' ?></p>
        </div>
      <?php endforeach; ?>
      <ul class="plan-limites"><?php foreach (textos_limites_plan($pl, 'escuela') as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
      <ul class="plan-modulos">
        <?php foreach ($mods_vista as $k => [$ico, $nom]): $si = in_array($k, $mp, true); ?><li class="<?= $si ? 'si' : 'no' ?>"><?= $si ? '✓' : '✕' ?> <?= e($nom) ?></li><?php endforeach; ?>
        <?php foreach (array_filter(array_map('trim', explode("\n", $pl['descripcion']))) as $li): ?><li class="si">✓ <?= e($li) ?></li><?php endforeach; ?>
      </ul>
      <a class="btn btn-grande<?= $pl['destacado'] ? '' : ' btn-sec' ?>" href="<?= e(url_empezar('escuela', (int)$pl['id'])) ?>"><?= $registro ? 'Probar gratis ' . $dias . ' días' : 'Solicitar demo' ?></a>
      <a class="plan-folleto" href="<?= e(url_plan($pl)) ?>">Ver todo lo que incluye →</a>
    </div>
  <?php endforeach; ?>
</div>
<?php if (array_filter($planes_vista, fn($p) => (int)($p['dominio_anual'] ?? 0)) && nota_dominio() !== ''): ?><p class="suave" style="text-align:center;font-size:.85rem">* <?= e(nota_dominio()) ?></p><?php endif; ?>
<p class="suave" style="text-align:center">Todos los planes incluyen: <?= e(implode(' · ', funciones_base('escuela'))) ?>.</p>
<p class="suave" style="text-align:center">Precios en dólares. <?= $registro ? 'En la prueba gratis' : 'En la demo' ?> tiene todo. ¿Necesita algo especial? <?= $enlace_wa ? '<a target="_blank" href="' . e($enlace_wa) . '">Converse con nosotros</a>' : 'Consúltenos' ?>.</p>
<script>
(function () {
  var b = document.querySelectorAll('#periodos button');
  b.forEach(function (x) { x.addEventListener('click', function () {
    b.forEach(function (y) { y.classList.toggle('activo', y === x); });
    document.querySelectorAll('.plan-precio-p').forEach(function (p) { p.hidden = p.getAttribute('data-p') !== x.getAttribute('data-p'); });
  }); });
})();
</script>
<?php endif; ?>

<h2 class="titulo-seccion" id="disciplinas">Elija sus disciplinas</h2>
<p class="suave" style="text-align:center;margin-top:-6px">Su plan incluye una disciplina. Agregue las que necesite con <b><?= e(rtrim(rtrim(number_format(modulo_descuento(), 1, ',', ''), '0'), ',')) ?> % de descuento</b> cada una: todo queda en la misma cuenta, con un solo panel y una sola página.</p>
<div class="funciones modulos-deporte-landing">
  <?php $mods_d = modulos_deporte(true); uksort($mods_d, fn($a, $b) => (array_search($a, ['baile', 'musica', 'natacion']) === false ? 9 : array_search($a, ['baile', 'musica', 'natacion'])) <=> (array_search($b, ['baile', 'musica', 'natacion']) === false ? 9 : array_search($b, ['baile', 'musica', 'natacion'])));
  foreach ($mods_d as $k => $m): ?>
    <div class="funcion"><span class="funcion-ico"><?= $m['icono'] ?></span><h3><?= e($m['nombre']) ?></h3><p><?= e($m['descripcion']) ?></p>
      <p><b>+<?= dinero(precio_mostrar_modulo_extra($k)) ?>/mes</b> <small class="suave">como disciplina adicional</small></p></div>
  <?php endforeach; ?>
</div>

<h2 class="titulo-seccion">Todo lo que incluye</h2>
<div class="funciones">
  <?php foreach ($funciones as [$ico, $tit, $txt]): ?><div class="funcion"><span class="funcion-ico"><?= $ico ?></span><h3><?= e($tit) ?></h3><p><?= e($txt) ?></p></div><?php endforeach; ?>
</div>

<h2 class="titulo-seccion" id="preguntas">Preguntas frecuentes</h2>
<div class="faq">
  <details><summary>¿Cuánto cuesta y cómo pago?</summary><p>Desde <?= $planes_vista ? e(texto_precio_principal($planes_vista[0])) : 'poco' ?>. Puede pagar mensual, semestral (paga 5 meses y recibe 6) o anual (paga 10 y recibe 12, más dominio propio). <?= $registro ? 'Primero la prueba gratis de ' . $dias . ' días, sin tarjeta.' : 'Primero le preparamos una demo.' ?></p></details>
  <details><summary>¿Cómo funciona el recordatorio de pensiones?</summary><p>Cada día, en <b>Mensajes de hoy</b>, aparecen las pensiones que vencen en los próximos días y las vencidas, con el mensaje listo para cada padre. Toca «WhatsApp», se abre el chat con el mensaje escrito, lo envía y lo marca como enviado. No hace falta contratar nada aparte.</p></details>
  <details><summary>¿Los padres pueden pagar por DeUna?</summary><p>Sí. Usted pone el número de DeUna de la academia; el padre paga desde su app y sube la captura del comprobante en su portal. Usted lo revisa y con un toque sale el recibo.</p></details>
  <details><summary>¿Sirve para clases particulares de música?</summary><p>Sí: vende paquetes de 4, 8 o 12 clases, arma el horario 1 a 1 con cada profesor y, al marcar la asistencia, la clase se descuenta del paquete. El padre ve cuántas clases le quedan.</p></details>
  <details><summary>¿Puedo cambiar los niveles?</summary><p>Sí. Vienen niveles de fábrica (baile: Iniciación a Pre-profesional; música: Nivel 1 a 6; natación: de bebés a competitivo) y usted los cambia, agrega o quita cuando quiera.</p></details>
  <details><summary>¿Necesito instalar algo?</summary><p>No. Funciona en el navegador de cualquier computadora o celular. Si quiere, se instala como aplicación en el celular desde el mismo navegador (Agregar a pantalla de inicio).</p></details>
  <details><summary>¿Qué pasa con los datos de los alumnos?</summary><p>Se protegen según la Ley Orgánica de Protección de Datos Personales: autorización del representante en la inscripción, fotos públicas solo si se autorizan y registro de cada cambio. <a href="index.php?v=privacidad">Ver política de privacidad</a>.</p></details>
</div>

<section class="cta-final" id="soporte">
  <h2>¿Listo para ordenar su academia?</h2>
  <div class="hero-botones">
    <a class="btn btn-grande btn-blanco" href="<?= e(url_empezar('escuela')) ?>"><?= $registro ? 'Empezar gratis' : e(texto_empezar()) ?></a>
    <?php if ($enlace_wa): ?><a class="btn btn-grande btn-wa" target="_blank" href="<?= e($enlace_wa) ?>">💬 WhatsApp</a><?php endif; ?>
  </div>
  <p style="opacity:.85;margin:.6rem 0 0">Soporte directo por WhatsApp · Un producto de <?= e(ajuste('empresa', 'Tukán Software')) ?>, Ecuador</p>
</section>
<?php
pie_publico();
