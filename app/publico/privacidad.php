<?php
/*
 * Política de privacidad y protección de datos personales
 * (Ley Orgánica de Protección de Datos Personales del Ecuador — LOPDP y su Reglamento).
 * (v24) Cubre ligas, escuelas, deportes individuales (carreras, duelos, bolos), pagos con tarjeta,
 * transferencia internacional (servidores fuera del Ecuador), versión de la política y formulario de derechos.
 * La liga o escuela es la RESPONSABLE de los datos de sus participantes; la empresa del sistema es la ENCARGADA.
 */
$liga = $liga ?? null;
$empresa = (string)ajuste('responsable_nombre') ?: (string)ajuste('empresa');
$correo_emp = (string)ajuste('responsable_email');
$correo_liga = $liga ? (string)($liga['contacto_email'] ?? '') : '';
$escuela = $liga && es_escuela($liga);
$org = $liga ? ($escuela ? 'la escuela' : 'la liga') : 'la organización';
$url_derechos = $liga ? 'index.php?l=' . rawurlencode($liga['slug']) . '&v=derechos' : 'index.php?v=derechos';
$version = version_privacidad();
cabecera_publica('Política de privacidad' . ($liga ? ' · ' . $liga['nombre'] : ''), $liga);
?>
<div class="tarjeta legal">
  <h1>Política de privacidad y protección de datos personales</h1>
  <p class="suave">Versión <b><?= e($version) ?></b>. Aplica a <?= $liga ? ($escuela ? 'la escuela' : 'la liga') . ' <b>' . e($liga['nombre']) . '</b> y a ' : '' ?>la plataforma <b><?= e(marca()) ?></b>. Se rige por la Ley Orgánica de Protección de Datos Personales del Ecuador (LOPDP) y su Reglamento.</p>
  <p><a class="btn" href="<?= e($url_derechos) ?>">🔒 Ejercer mis derechos (ver, corregir o borrar mis datos)</a></p>

  <h2>1. ¿Quién es responsable de sus datos?</h2>
  <?php if ($liga): ?>
    <p><b>Responsable del tratamiento:</b> <?= e($liga['nombre']) ?><?= ($liga['ciudad'] ?? '') !== '' ? ' (' . e($liga['ciudad']) . ')' : '' ?>, que <?= $escuela ? 'administra la escuela deportiva' : 'organiza los campeonatos' ?><?= $correo_liga ? '. Contacto: ' . e($correo_liga) : '' ?>.</p>
    <p><b>Encargado del tratamiento:</b> <?= e($empresa) ?>, que provee la plataforma tecnológica <?= e(marca()) ?> y trata los datos solo por cuenta y según las instrucciones de <?= $org ?><?= $correo_emp ? ' (' . e($correo_emp) . ')' : '' ?>.</p>
  <?php else: ?>
    <p>Cada liga, escuela u organización que usa <?= e(marca()) ?> es <b>responsable</b> de los datos de sus participantes (jugadores, alumnos, competidores, delegados, árbitros y profesores). <b><?= e($empresa) ?></b> es el <b>encargado</b>: provee la plataforma y trata los datos solo por cuenta de cada organización<?= $correo_emp ? '. Contacto: ' . e($correo_emp) : '' ?>.</p>
    <p>Para los datos de quienes solicitan una demo o registran una organización, <b><?= e($empresa) ?></b> es el responsable.</p>
  <?php endif; ?>

  <h2>2. ¿Qué datos se recogen?</h2>
  <ul>
    <li><b>Jugadores y competidores</b> (fútbol y otros deportes de equipo, carreras de autos y ciclismo, tenis, pádel, tenis de mesa, artes marciales, boxeo, bolos…): nombres, cédula o pasaporte, fecha de nacimiento, número, categoría, club o equipo, fotografía, peso (solo en deportes por peso), tiempos, puntajes, estadísticas y sanciones deportivas.</li>
    <li><b>Alumnos de escuelas:</b> nombres, cédula, fecha de nacimiento, grupo y horario, asistencia, evaluaciones deportivas, pagos de pensiones y, si la escuela lo pide, datos de salud básicos para emergencias (tipo de sangre, alergias).</li>
    <li><b>Menores de edad:</b> además, nombre, cédula, teléfono y correo de su padre, madre o representante legal, que es quien autoriza el uso de los datos.</li>
    <li><b>Delegados, árbitros, profesores, vendedores y administradores:</b> nombre, correo, teléfono y cédula; registro de ingresos y acciones en el sistema (bitácora de seguridad) con fecha, hora e IP.</li>
    <li><b>Pagos:</b> cuotas, multas, pensiones e inscripciones. En los <b>pagos con tarjeta</b>, los datos de la tarjeta los recibe directamente la pasarela de pagos autorizada; <b><?= e(marca()) ?> no ve ni guarda el número de la tarjeta</b>, solo el resultado del pago.</li>
    <li><b>Otros formularios públicos:</b> reservas de canchas, inscripción de equipos, pollas y solicitudes de demo (nombre, teléfono y correo).</li>
    <li><b>Prueba de su autorización:</b> fecha y hora, dirección IP, navegador, el texto aceptado y la versión de esta política.</li>
  </ul>

  <h2>3. ¿Para qué se usan? (finalidad)</h2>
  <ul>
    <li>Organizar los campeonatos y la escuela: inscripción, categorías, fixture, llaves, resultados, tablas, tiempos, disciplina, asistencia, evaluaciones y carnets.</li>
    <li><b>Verificar la identidad</b> de cada participante (foto y carnet con código QR) y su <b>categoría</b> (edad, peso) para evitar suplantaciones.</li>
    <li>Publicar en la página de <?= $org ?> los resultados, tablas, llaves, medalleros, goleadores y sanciones deportivas.</li>
    <li>Administrar cobros, pagos y comprobantes.</li>
    <li>Enviar avisos (programación, suspensiones, pagos pendientes, respuestas) por WhatsApp, Telegram o correo.</li>
  </ul>
  <p>Los datos <b>no se venden ni se ceden</b> a terceros para publicidad.</p>

  <h2>4. Base legal</h2>
  <p>El <b>consentimiento</b> del titular (o de su representante legal si es menor de 18 años), que se registra con fecha, hora, IP y el texto aceptado; la relación deportiva o de servicio con <?= $org ?> (reglamento del campeonato o contrato de la escuela); y el cumplimiento de obligaciones legales (por ejemplo, facturación).</p>
  <p>Cuando la organización ingresa en el sistema los datos de otra persona (por ejemplo, al inscribir a un jugador o importar una lista), declara contar con la autorización de esa persona o de su representante, y esa declaración también queda registrada.</p>

  <h2>5. ¿Qué se publica y qué no?</h2>
  <ul>
    <li><b>Se publica:</b> nombre, club o equipo, número, categoría, edad, resultados, tiempos, estadísticas y sanciones deportivas.</li>
    <li><b>La foto</b> solo se publica si fue autorizada. En menores de edad, solo con autorización expresa del representante.</li>
    <li><b>Nunca se publica:</b> la cédula completa, la fecha de nacimiento exacta, el peso, teléfonos, correos, datos de salud ni datos del representante.</li>
    <li>La página de verificación del carnet (a la que se llega escaneando el QR) muestra la foto y la cédula parcialmente oculta, solo para identificar al participante.</li>
  </ul>

  <h2>6. Menores de edad</h2>
  <p>Los datos de menores de 18 años se tratan con especial cuidado: su inscripción requiere la autorización y los datos del representante legal, y su foto no se publica sin permiso expreso.</p>

  <h2>7. ¿Dónde se guardan? (transferencia internacional)</h2>
  <p>Los datos se guardan en <b><?= e(txt_alojamiento()) ?></b>, es decir, <b>fuera del Ecuador</b>. Estos proveedores aplican medidas de seguridad reconocidas internacionalmente y actúan solo como proveedores de infraestructura: no usan los datos para fines propios. Al aceptar esta política, usted autoriza esta transferencia internacional, que es necesaria para que el servicio funcione.</p>
  <p>Otros proveedores que intervienen solo cuando se usa la función: la pasarela de pagos con tarjeta, el Servicio de Rentas Internas (facturación electrónica) y los servicios de mensajería (WhatsApp, Telegram, correo).</p>

  <h2>8. ¿Cuánto tiempo se guardan?</h2>
  <p>Mientras la persona participe en <?= $org ?> y luego el tiempo necesario para conservar el historial deportivo (campeones, estadísticas) y cumplir obligaciones legales (por ejemplo, contables y tributarias). Se puede pedir la eliminación en cualquier momento (punto 9): en ese caso los datos personales se borran y solo quedan las estadísticas sin nombre.</p>

  <h2>9. Sus derechos</h2>
  <p>Usted (o el representante del menor) puede pedir en cualquier momento y sin costo: <b>acceso</b> a sus datos, <b>rectificación</b> de datos incorrectos, <b>eliminación</b>, <b>oposición</b> o <b>suspensión</b> del tratamiento, <b>portabilidad</b> (copia de sus datos) y <b>retirar la autorización</b> que dio.</p>
  <p><b>Cómo:</b> llene el <a href="<?= e($url_derechos) ?>">formulario de derechos</a><?= $correo_liga ? ', o escriba a ' . e($correo_liga) : '' ?><?= $correo_emp ? ($correo_liga ? ' o ' : ', o escriba a ') . e($correo_emp) : '' ?>. Le pediremos su cédula para comprobar que es el titular. Se responderá en el plazo que establece la ley (15 días).</p>

  <h2>10. Seguridad</h2>
  <p>Conexión cifrada (https), contraseñas cifradas, doble verificación para administradores, permisos por tipo de usuario, bitácora de auditoría que registra quién hizo cada cambio, fotos internas que no son públicas, protección contra ataques en la red de Cloudflare y respaldos diarios.</p>

  <h2>11. Cambios a esta política</h2>
  <p>Si la política cambia, se publicará aquí con una nueva versión. El sistema guarda qué versión aceptó cada persona.</p>

  <h2>12. Autoridad de control</h2>
  <p>Si considera que sus derechos no fueron atendidos, puede acudir a la Superintendencia de Protección de Datos Personales del Ecuador.</p>
  <p class="ayuda">Este texto es una plantilla general. Se recomienda que cada organización lo revise con su asesor legal.</p>
</div>
<?php
pie_publico();
