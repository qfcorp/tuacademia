<?php
/* PÁGINA DE VENTA del sistema (portada principal: tuacademia.tukanec.com) */
$planes = filas('SELECT * FROM planes WHERE activo = 1 ORDER BY orden, precio_mensual');
$ver_escuelas = ($v_global ?? '') === 'escuelas' || get('para') === 'escuelas';
$ligas_vitrina = filas("SELECT l.nombre, l.slug, l.logo, l.color, l.ciudad FROM ligas l WHERE l.activa = 1 AND l.es_demo = 0 AND (SELECT COUNT(*) FROM equipos e WHERE e.liga_id = l.id) > 0 ORDER BY l.id LIMIT 8");
$n_partidos = (int)valor("SELECT COUNT(*) FROM partidos WHERE estado IN ('jugado','wo')");
$wa = telefono_wa((string)ajuste('ventas_whatsapp'));
$registro = prueba_automatica();
$dias = (int)ajuste('dias_prueba', 7);
$enlace_wa = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hola, quiero información de ' . marca() . ($ver_escuelas ? ' para mi escuela deportiva' : ' para mi liga')) : '';

$funciones = [
    ['⚽', 'Fútbol, indor, ecuavóley y básquet', 'Fútbol 5, 7, 9 y 11, indor, ecuavóley por sets y básquet 5×5 o 3×3. Cada campeonato con sus propias reglas.'],
    ['🎲', 'Sorteo y fixture automático', 'Todos contra todos, grupos, ida y vuelta, eliminatorias. Sorteo animado para transmitir.'],
    ['📅', 'Programación automática', 'El sistema reparte horarios, canchas y árbitros sin choques, o lo hace usted a mano.'],
    ['📱', 'Vocalía desde el celular', 'Goles, tarjetas y reloj en vivo. Funciona aunque se caiga el internet en la cancha.'],
    ['🔴', 'Resultados en vivo', 'La página de la liga se actualiza sola: marcador, minuto y goleadores al instante.'],
    ['🪪', 'Carnet con QR', 'El vocal escanea el carnet y ve la foto: se acabaron las suplantaciones.'],
    ['👶', 'Categorías por edad', 'Sub-12, Sub-17, Máster... con control de edad y número de "pasados" autorizados.'],
    ['🟨', 'Tarjetas y suspensiones', 'Suspensiones automáticas por amarillas y rojas, más sanciones de la comisión.'],
    ['🧑‍⚖️', 'Árbitros con informe', 'Cada árbitro llena su informe; el sistema lo compara con la vocalía y avisa diferencias.'],
    ['💵', 'Finanzas y recibos', 'Inscripciones, arbitrajes, multas y pagos con recibo. Estado de cuenta por equipo.'],
    ['🖼️', 'Imágenes para redes', 'Resultados, tabla, goleadores y figura de la fecha listos para publicar, con su patrocinador.'],
    ['📲', 'Avisos por Telegram', 'Programación, resultados y suspensiones llegan solos al celular de delegados y árbitros.'],
    ['📝', 'Inscripción en línea', 'Los equipos se inscriben por internet y cada delegado registra a sus jugadores.'],
    ['🔁', 'Pases e historial', 'Pases entre equipos, trayectoria de cada jugador, campeones y goleadores históricos.'],
    ['🔎', 'Auditoría total', 'Quién hizo cada cambio, cuándo y desde dónde. Nada se borra a escondidas.'],
    ['🤝', 'Patrocinadores que pagan', 'Banners, "partido presentado por…" y logo en carnets e imágenes, con reporte de vistas para su patrocinador.'],
    ['🗳', 'Votación del público', 'Jugador del partido y gol de la fecha votados por los hinchas, con premio del auspiciante.'],
    ['🎯', 'Polla de la liga', 'Los hinchas pronostican los resultados gratis y compiten en una tabla. Sin apuestas.'],
    ['📸', 'Cromo del jugador', 'Cada jugador descarga su tarjeta con foto y estadísticas para compartir en Instagram.'],
    ['📥', 'Importar desde Excel', 'Cargue todos sus equipos y jugadores de una sola vez con una plantilla de Excel.'],
    ['👥', 'Usuarios con perfiles', 'Secretario, tesorero, comisión disciplinaria, comunicación: cada uno ve solo lo suyo.'],
    ['🏆', 'Llaves automáticas', 'Cuartos, semifinal y final se arman solos con la tabla, y el ganador avanza solo.'],
    ['🪪', 'Carnet digital del jugador', 'Cada jugador ve en su celular su carnet con QR, sus partidos y sus estadísticas.'],
    ['💳', 'Pagos con comprobante', 'El delegado sube la foto de su transferencia y el recibo sale al aprobarla.'],
    ['🧑‍⚖️', 'Pago a árbitros', 'Liquidación automática de honorarios por partido, con comprobante para firmar.'],
    ['🏟', 'Reserva de canchas', 'Alquile sus canchas por hora: el público reserva desde el celular.'],
    ['📰', 'Boletín oficial de la fecha', 'Resultados, tabla, suspendidos, resoluciones y la próxima fecha en un PDF listo para compartir.'],
    ['Σ', 'Tabla acumulada', 'Sume Apertura y Clausura (o las etapas que quiera) en una tabla general.'],
    ['📨', 'Pedidos de los delegados', 'Reprogramaciones, descargos y apelaciones por el sistema, con respuesta registrada.'],
    ['🔔', 'Seguir a mi equipo', 'Los hinchas reciben en el celular cada gol, el resultado y los horarios de su equipo.'],
    ['🎓', 'También para escuelas deportivas', 'Alumnos, asistencia, mensualidades con vencimiento, profesores, medidas, tests, libreta y portal para los padres.'],
    ['🔐', 'Seguro y privado', 'Doble verificación, datos de menores protegidos y cumplimiento de la ley de protección de datos.'],
];
// (v16.1) La vista de escuelas habla solo de escuelas (nada de ligas, árbitros ni patrocinadores)
if ($ver_escuelas) {
    $funciones = [
        ['👦', 'Alumnos con ficha completa', 'Foto, fecha de nacimiento, ficha médica, alergias, representante y contacto de emergencia.'],
        ['🏷', 'Categorías Sub-N automáticas', 'El sistema pone a cada alumno en su categoría por edad (Sub-8, Sub-10, Sub-12…) o en grupos abiertos.'],
        ['🗓', 'Grupos, horarios y sedes', 'Arme sus grupos con días, horas, cancha o sede y el profesor a cargo.'],
        ['✅', 'Asistencia desde el celular', 'El profesor toma lista en segundos y anota el plan de la clase.'],
        ['💵', 'Mensualidades con vencimiento', 'Se generan solas cada mes, con becas y descuento por hermanos. Semáforo de quién está al día.'],
        ['🧾', 'Recibos al instante', 'Cada pago con su recibo. Los padres pueden subir la foto de su transferencia.'],
        ['💳', 'Cobros con tarjeta', 'Los padres pagan con tarjeta desde su celular y el pago se aplica solo.'],
        ['👨‍👩‍👧', 'Portal de los padres', 'Cada familia ve a sus hijos, lo que debe, la asistencia, las evaluaciones y la libreta.'],
        ['📝', 'Inscripción en línea', 'Los padres inscriben a sus hijos desde la página de la escuela.'],
        ['🧑‍🏫', 'Profesores y rol de pago', 'Días que dieron clase, lo que se les paga, su rol de pago y la foto de su factura.'],
        ['📏', 'Medidas y tests', 'Peso, talla e IMC con gráfico, y tests de rendimiento con ranking.'],
        ['⭐', 'Evaluaciones y libreta', 'Evaluación técnica con gráfico de araña y libreta del período en PDF.'],
        ['🪪', 'Carnet del alumno', 'Carnet con foto y código QR, también en el celular de los padres.'],
        ['🛍', 'Tienda e inventario', 'Uniformes, balones y accesorios: venta a la cuenta del alumno y control de stock.'],
        ['📲', 'Avisos por Telegram', 'Mensualidades por vencer, pagos y novedades llegan solos al celular.'],
        ['🌐', 'Página pública de la escuela', 'Su escuela en internet con sus grupos, horarios y el botón de inscripción.'],
        ['👥', 'Usuarios con perfiles', 'Dueño, secretaría y profesores: cada uno ve solo lo suyo.'],
        ['🔐', 'Seguro y privado', 'Datos de menores protegidos, autorización del representante y bitácora de cada cambio.'],
    ];
}
cabecera_publica(marca() . ($ver_escuelas ? ' · Sistema para escuelas deportivas' : ' · Sistema para ligas y escuelas deportivas'));
$beneficios = [
    ['📋', 'Adiós a las hojas desordenadas', 'Equipos, jugadores, fixture, tabla, tarjetas y dinero en un solo lugar, en la nube y con respaldo diario.'],
    ['⚡', 'Todo se calcula solo', 'Tabla, goleadores, suspensiones por tarjetas, multas, arbitrajes y deudas de cada equipo, sin sumar a mano.'],
    ['🔴', 'Su liga en vivo', 'El vocal marca desde el celular y los hinchas ven el marcador al instante en la página de la liga.'],
    ['💳', 'Cobre más rápido', 'Los equipos y los padres pagan con tarjeta desde su celular, el recibo sale solo y usted ve cada pago en su reporte.'],
    ['🪪', 'Cero suplantaciones', 'Carnet con foto y código QR que el vocal escanea en la cancha.'],
    ['🏆', 'Imagen profesional', 'Página propia, boletín oficial, imágenes para redes con su patrocinador y avisos automáticos.'],
];
if ($ver_escuelas) {
    $beneficios = [
        ['📋', 'Adiós a los cuadernos', 'Alumnos, grupos, asistencia y mensualidades en un solo lugar, en la nube y con respaldo diario.'],
        ['⚡', 'Cobre a tiempo', 'Las mensualidades se generan solas, con su fecha de vencimiento, becas y descuento por hermanos.'],
        ['🚦', 'Sepa quién debe', 'Semáforo de pagos: al día, por vencer y vencidos, con un clic para escribirles.'],
        ['💳', 'Cobre más rápido', 'Los padres pagan con tarjeta desde su celular, el recibo sale solo y usted ve cada pago en su reporte.'],
        ['👨‍👩‍👧', 'Padres informados', 'Cada familia ve la asistencia, los pagos, las evaluaciones y la libreta de sus hijos.'],
        ['🧑‍🏫', 'Profesores ordenados', 'Asistencia desde el celular, plan de clase y su rol de pago cada mes.'],
    ];
}
$tipo_vista = $ver_escuelas ? 'escuela' : 'liga';
$planes_vista = array_values(array_filter($planes, fn($pl) => in_array($pl['tipo_cuenta'], ['ambos', $tipo_vista], true) && opciones_precio($pl)));
$mods_vista = modulos_sistema($tipo_vista); // (v16.1) textos propios para escuelas
?>
<nav class="nav-venta"><div class="barra-in">
  <a href="#beneficios">Beneficios</a><a href="#como-funciona">Cómo funciona</a><a href="#planes">Planes</a><a href="#modulos">Módulos</a><a href="#cobros">Cobros con tarjeta</a><a href="#preguntas">Preguntas</a>
  <a class="nav-cta" href="<?= e(url_empezar($ver_escuelas ? 'escuela' : '')) ?>"><?= e(texto_empezar(true)) ?></a>
</div></nav>

<section class="hero">
  <h1><?= e($ver_escuelas ? 'El sistema completo para su escuela deportiva' : ajuste('landing_titulo')) ?></h1>
  <p><?= e($ver_escuelas ? 'Alumnos, asistencia, mensualidades con vencimiento, profesores con rol de pago, evaluaciones, libreta y portal para los padres. Fútbol, básquet, vóley, natación y más.' : ajuste('landing_subtitulo')) ?></p>
  <div class="hero-botones">
    <a class="btn btn-grande btn-blanco" href="<?= e(url_empezar($ver_escuelas ? 'escuela' : '')) ?>"><?= e(texto_empezar()) ?></a>
    <?php if ($enlace_wa): ?><a class="btn btn-grande btn-wa" target="_blank" href="<?= e($enlace_wa) ?>">💬 Escríbanos por WhatsApp</a><?php endif; ?>
    <?php if (liga_demo()): ?><a class="btn btn-grande btn-sec-blanco" href="index.php?v=demo">👀 Ver demo</a><?php endif; ?>
  </div>
  <p class="hero-nota"><?= $registro ? 'Sin tarjeta de crédito · Lista en 2 minutos · ' : 'Demo guiada y acceso de prueba para su organización · ' ?>Soporte por WhatsApp en Ecuador</p>
  <div class="hero-tabs"><a class="<?= $ver_escuelas ? '' : 'activa' ?>" href="index.php#planes">⚽ Para ligas y campeonatos</a><a class="<?= $ver_escuelas ? 'activa' : '' ?>" href="escuelas#planes">🎓 Para escuelas deportivas</a></div>
</section>

<?php if ($ligas_vitrina && !$ver_escuelas): ?>
<section class="vitrina">
  <p class="suave" style="text-align:center;margin:0 0 10px">Ligas que ya lo usan<?= $n_partidos > 20 ? ' · ' . number_format($n_partidos) . ' partidos registrados' : '' ?></p>
  <div class="vitrina-logos">
    <?php foreach ($ligas_vitrina as $l): ?><a href="index.php?l=<?= e($l['slug']) ?>" title="<?= e($l['nombre']) ?>"><?= escudo($l['logo'], $l['nombre'], $l['color'], 'escudo-grande') ?><small><?= e($l['nombre']) ?></small></a><?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<h2 class="titulo-seccion" id="beneficios">¿Por qué <?= e(marca()) ?>?</h2>
<div class="funciones">
  <?php foreach ($beneficios as [$ico, $tit, $txt]): ?><div class="funcion"><span class="funcion-ico"><?= $ico ?></span><h3><?= e($tit) ?></h3><p><?= e($txt) ?></p></div><?php endforeach; ?>
</div>

<h2 class="titulo-seccion" id="como-funciona">Así de fácil</h2>
<div class="pasos-venta">
  <?php if ($ver_escuelas): ?>
  <div><b>1</b><h3>Cree su escuela</h3><p>Nombre, deporte, mensualidad y listo<?= $registro ? ' (gratis ' . $dias . ' días)' : ' (pida su demo y la preparamos con usted)' ?>.</p></div>
  <div><b>2</b><h3>Cargue alumnos</h3><p>Usted o los padres por internet, con foto y ficha médica.</p></div>
  <div><b>3</b><h3>Arme sus grupos</h3><p>Categorías Sub-N automáticas, horarios, sedes y profesores.</p></div>
  <div><b>4</b><h3>¡A entrenar!</h3><p>Asistencia, cobros con vencimiento y evaluaciones desde el celular.</p></div>
  <?php else: ?>
  <div><b>1</b><h3>Cree su liga</h3><p>Nombre, modalidad y listo<?= $registro ? ' (prueba gratis de ' . $dias . ' días)' : ' (pida su demo y la preparamos con usted)' ?>.</p></div>
  <div><b>2</b><h3>Cargue equipos</h3><p>Usted o cada delegado desde su celular, con foto y cédula.</p></div>
  <div><b>3</b><h3>Sorteo y fixture</h3><p>Un clic y el campeonato queda programado.</p></div>
  <div><b>4</b><h3>¡A jugar!</h3><p>Vocalía en vivo, tabla y goleadores automáticos.</p></div>
  <?php endif; ?>
</div>

<?php if ($planes_vista): $periodos_hay = []; foreach ($planes_vista as $pl) foreach (opciones_precio($pl) as $k => $o) $periodos_hay[$k] = $o['nombre']; $orden_p = array_intersect(['mensual', 'trimestral', 'semestral', 'anual'], array_keys($periodos_hay)); ?>
<h2 class="titulo-seccion" id="planes">Planes <?= $ver_escuelas ? 'para escuelas' : 'para ligas' ?></h2>
<?php if (count($orden_p) > 1): ?>
<div class="periodos" id="periodos">
  <?php foreach ($orden_p as $k): ?><button type="button" data-p="<?= $k ?>" class="<?= $k === 'mensual' ? 'activo' : '' ?>"><?= e($periodos_hay[$k]) ?><?php if ($k === 'semestral'): ?> <small>1 mes gratis</small><?php elseif ($k === 'anual'): ?> <small>2 meses gratis</small><?php endif; ?></button><?php endforeach; ?>
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
      <ul class="plan-limites"><?php foreach (textos_limites_plan($pl, $tipo_vista) as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
      <ul class="plan-modulos">
        <?php foreach ($mods_vista as $k => [$ico, $nom]): $si = in_array($k, $mp, true); ?><li class="<?= $si ? 'si' : 'no' ?>"><?= $si ? '✓' : '✕' ?> <?= e($nom) ?></li><?php endforeach; ?>
        <?php foreach (array_filter(array_map('trim', explode("\n", $pl['descripcion']))) as $li): ?><li class="si">✓ <?= e($li) ?></li><?php endforeach; ?>
      </ul>
      <a class="btn btn-grande<?= $pl['destacado'] ? '' : ' btn-sec' ?>" href="<?= e(url_empezar($ver_escuelas ? 'escuela' : '', (int)$pl['id'])) ?>"><?= $registro ? 'Probar gratis' : 'Solicitar demo' ?></a>
      <a class="plan-folleto" href="<?= e(url_plan($pl)) ?>">Ver todo lo que incluye →</a>
    </div>
  <?php endforeach; ?>
</div>
<?php // (v16) Combo liga + escuela y nota del dominio gratis
  $pl_liga = fila("SELECT * FROM planes WHERE activo = 1 AND tipo_cuenta IN ('liga','ambos') AND precio_mensual > 0 ORDER BY destacado DESC, precio_mensual DESC LIMIT 1");
  $pl_esc = fila("SELECT * FROM planes WHERE activo = 1 AND tipo_cuenta IN ('escuela','ambos') AND precio_mensual > 0 ORDER BY destacado DESC, precio_mensual DESC LIMIT 1");
  // (v38) en la app de Google Play no hay combo
  if (combo_descuento() > 0 && $pl_liga && $pl_esc && !en_app_play()): $suma = (float)$pl_liga['precio_mensual'] + (float)$pl_esc['precio_mensual']; ?>
<div class="tarjeta combo-venta">
  <h3 style="margin-top:0">🤝 <?= $ver_escuelas ? 'Combo escuela + liga: ' . e((string)(float)combo_descuento()) . '% de descuento en los dos planes' : e(texto_combo()) ?></h3>
  <p style="margin:.3rem 0"><?= $ver_escuelas ? '¿Su escuela también organiza campeonatos o tiene una liga?' : '¿Tiene una liga <b>y</b> una escuela?' ?> Contrate las dos con el mismo usuario y pague <b><?= e((string)(float)combo_descuento()) ?>% menos</b> en cada plan. Se mantiene 1 mes gratis en el semestre y 2 meses gratis en el año.</p>
  <p class="suave" style="margin:0">Ejemplo: <?= $ver_escuelas ? e($pl_esc['nombre']) . ' (' . dinero($pl_esc['precio_mensual']) . ') + ' . e($pl_liga['nombre']) . ' (' . dinero($pl_liga['precio_mensual']) . ')' : e($pl_liga['nombre']) . ' (' . dinero($pl_liga['precio_mensual']) . ') + ' . e($pl_esc['nombre']) . ' (' . dinero($pl_esc['precio_mensual']) . ')' ?> = <s><?= dinero($suma) ?></s> → <b><?= dinero(round($suma * (1 - combo_descuento() / 100), 2)) ?> al mes</b>.</p>
</div>
<?php endif; ?>
<?php if (array_filter($planes_vista, fn($p) => (int)($p['dominio_anual'] ?? 0)) && nota_dominio() !== ''): ?><p class="suave" style="text-align:center;font-size:.85rem">* <?= e(nota_dominio()) ?></p><?php endif; ?>
<p class="suave" style="text-align:center">Todos los planes incluyen: <?= e(implode(' · ', funciones_base($tipo_vista))) ?>.</p>
<p style="text-align:center"><a class="btn btn-sec" href="comparar<?= $ver_escuelas ? '?para=escuelas' : '' ?>">📊 Comparar todos los planes, función por función</a></p>
<p class="suave" style="text-align:center">Precios en dólares. <?= $registro ? 'En la prueba gratis' : 'En la demo' ?> tiene todos los módulos (el fixture para imprimir y el esquema del campeonato son exclusivos del plan Profesional). ¿Necesita algo especial? <?= $enlace_wa ? '<a target="_blank" href="' . e($enlace_wa) . '">Converse con nosotros</a>' : 'Consúltenos' ?>.</p>
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

<h2 class="titulo-seccion" id="deportes">Elija sus deportes</h2>
<p class="suave" style="text-align:center;margin-top:-6px">Su plan incluye un deporte. Agregue los que necesite con <b><?= e(rtrim(rtrim(number_format(modulo_descuento(), 1, ',', ''), '0'), ',')) ?> % de descuento</b> cada uno: todo queda en la misma cuenta, con un solo panel y una sola página pública.</p>
<div class="funciones modulos-deporte-landing">
  <?php foreach (modulos_deporte(true) as $k => $m): ?>
    <div class="funcion"><span class="funcion-ico"><?= $m['icono'] ?></span><h3><?= e($m['nombre']) ?></h3><p><?= e($m['descripcion']) ?></p>
      <p><b>+<?= dinero(precio_mostrar_modulo_extra($k)) ?>/mes</b> <small class="suave">como deporte adicional</small> · <a href="index.php?v=solicitar&amp;modulo=<?= e($k) ?>">pedir demo</a></p></div>
  <?php endforeach; ?>
</div>

<h2 class="titulo-seccion" id="modulos">Los módulos, uno por uno</h2>
<p class="suave" style="text-align:center;margin-top:-6px">Cada plan trae los suyos. Siempre puede subir de plan y se activan al instante.</p>
<div class="funciones modulos-detalle">
  <?php foreach ($mods_vista as $k => [$ico, $nom, $para, $desc]): ?>
    <div class="funcion"><span class="funcion-ico"><?= $ico ?></span><h3><?= e($nom) ?></h3><p><?= e($desc) ?></p>
      <p class="modulo-planes"><?php foreach ($planes_vista as $pl): if (in_array($k, modulos_plan($pl), true)): ?><span class="etiqueta verde"><?= e($pl['nombre']) ?></span> <?php endif; endforeach; ?></p></div>
  <?php endforeach; ?>
</div>

<section class="cobros-venta" id="cobros">
  <span class="etiqueta amarilla">NUEVO</span>
  <h2>💳 Cobre con tarjeta, sin trámites</h2>
  <p>Sus <?= $ver_escuelas ? 'familias pagan las mensualidades' : 'equipos pagan inscripciones, arbitrajes y multas' ?> con <b>tarjeta de crédito o débito</b> desde su celular. <?= e(marca()) ?> recibe el pago por medio de <b>Plux</b> (pasarela autorizada en Ecuador), lo aplica a la cuenta, emite el recibo al instante y le transfiere el dinero a su cuenta bancaria.</p>
  <div class="rejilla-3">
    <div><h3>⚡ Más rápido</h3><p>Nadie tiene que ir al banco ni mandarle fotos de transferencias. El pago se refleja solo.</p></div>
    <div><h3>📊 Reporte de pagos</h3><p>Entra a su panel y ve solo sus pagos: quién pagó, cuánto, la comisión y lo que le transferimos.</p></div>
    <div><h3>🤝 Usted decide</h3><p>La comisión la asume su <?= $ver_escuelas ? 'escuela' : 'liga' ?> o se le suma al que paga. Usted elige.</p></div>
  </div>
  <p class="suave"><b>Costo por transacción:</b> <?= e(texto_tasas()) ?>. Sin costo mensual adicional en los planes que lo incluyen. Su suscripción de <?= e(marca()) ?> también se puede pagar con tarjeta.</p>
</section>

<h2 class="titulo-seccion">Todo lo que incluye</h2>
<div class="funciones">
  <?php foreach ($funciones as [$ico, $tit, $txt]): ?>
    <div class="funcion"><span class="funcion-ico"><?= $ico ?></span><h3><?= e($tit) ?></h3><p><?= e($txt) ?></p></div>
  <?php endforeach; ?>
</div>

<h2 class="titulo-seccion" id="preguntas">Preguntas frecuentes</h2>
<div class="faq">
  <details><summary>¿Cuánto cuesta y cómo pago?</summary><p>Desde <?= $planes_vista ? e(texto_precio_principal($planes_vista[0])) : 'poco' ?>. Puede pagar mensual, semestral (1 mes gratis) o anual (2 meses gratis), por transferencia o con tarjeta. <?= $registro ? 'Primero la prueba gratis de ' . $dias . ' días, sin tarjeta.' : 'Primero le preparamos una demo para que lo conozca.' ?></p></details>
  <?php if (!$ver_escuelas): ?><details><summary>¿Qué es el plan Torneo?</summary><p>Para campeonatos cortos (relámpago, de verano, de barrio): un solo torneo de hasta 20 equipos durante 3 meses, con casi todos los módulos (el fixture para imprimir y el esquema del campeonato son del plan Profesional).</p></details><?php endif; ?>
  <?php if (combo_descuento() > 0): ?><details><summary>¿Y si tengo una liga y también una escuela?</summary><p>Con el combo liga + escuela maneja las dos con el mismo usuario y paga <?= e((string)(float)combo_descuento()) ?>% menos en cada plan.</p></details><?php endif; ?>
  <details><summary>¿Puedo cambiar de plan?</summary><p>Sí, cuando quiera, desde "Mi plan" en su panel. Los módulos nuevos se activan al instante y no pierde ningún dato.</p></details>
  <details><summary>¿Cómo funcionan los cobros con tarjeta?</summary><p><?= $ver_escuelas ? 'Los padres pagan' : 'Sus equipos pagan' ?> con tarjeta; el recibo sale solo. <?= e(marca()) ?> le transfiere lo cobrado menos la comisión de la tarjeta (<?= e(texto_tasas()) ?>). Usted elige si la comisión la asume su organización o el que paga.</p></details>
  <details><summary>¿Necesito instalar algo?</summary><p>No. Funciona en el navegador de cualquier computadora o celular. Si quiere, se instala como aplicación en el celular desde el mismo navegador (Agregar a pantalla de inicio).</p></details>
  <?php if ($ver_escuelas): ?>
  <details><summary>¿Para qué deportes sirve?</summary><p>Fútbol, básquet, vóley, natación y otros: cada escuela elige su deporte y sus tests de rendimiento.</p></details>
  <details><summary>¿Los padres tienen que instalar algo?</summary><p>No. Entran desde el celular con su código y ven a sus hijos, lo que deben y la asistencia. También pueden pagar con tarjeta.</p></details>
  <details><summary>¿Mi escuela puede tener su propia dirección web?</summary><p>Sí: su escuela tiene su dirección corta (<?= e(preg_replace('#^https?://#', '', url_base())) ?>su-escuela) y, pagando el plan Escuela Completo por 1 año, le damos un dominio propio (www.suescuela.com).</p></details>
  <?php else: ?>
  <details><summary>¿Y si en la cancha no hay internet?</summary><p>La vocalía sigue funcionando: guarda los goles, tarjetas y presentes en el celular y los envía solos cuando vuelve la señal.</p></details>
  <details><summary>¿Sirve para fútbol 11, indor, básquet y ecuavóley?</summary><p>Sí: fútbol 5, 6, 7, 8, 9, 11 o personalizado, indor, ecuavóley por sets y básquet. Cada campeonato define sus jugadores, tiempos, árbitros y reglas.</p></details>
  <details><summary>¿Mi liga puede tener su propia dirección web?</summary><p>Sí: cada liga y cada campeonato tienen su dirección corta (<?= e(preg_replace('#^https?://#', '', url_base())) ?>su-liga/apertura) y, pagando el plan Profesional por 1 año, le damos un dominio propio (www.suliga.com).</p></details>
  <?php endif; ?>
  <details><summary>¿Qué pasa con los datos de <?= $ver_escuelas ? 'los alumnos' : 'los jugadores y alumnos' ?>?</summary><p>Se protegen según la Ley Orgánica de Protección de Datos Personales: consentimiento en la inscripción, datos de menores con autorización del representante y fotos públicas solo si se autorizan. <a href="index.php?v=privacidad">Ver política de privacidad</a>.</p></details>
</div>

<section class="cta-final" id="soporte">
  <h2>¿Listo para ordenar su <?= $ver_escuelas ? 'escuela' : 'liga' ?>?</h2>
  <div class="hero-botones">
    <a class="btn btn-grande btn-blanco" href="<?= e(url_empezar($ver_escuelas ? 'escuela' : '')) ?>"><?= $registro ? 'Empezar gratis' : e(texto_empezar()) ?></a>
    <?php if ($enlace_wa): ?><a class="btn btn-grande btn-wa" target="_blank" href="<?= e($enlace_wa) ?>">💬 WhatsApp</a><?php endif; ?>
  </div>
  <p style="opacity:.85;margin:.6rem 0 0">Soporte directo por WhatsApp · Un producto de <?= e(ajuste('empresa')) ?>, Ecuador</p>
</section>
<?php
pie_publico();
