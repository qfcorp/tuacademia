<?php
/*
 * Conector con el Panel Tukán Central (protocolos v1 y v2). Toda la lógica está en app/tukan_central.php.
 *   v1  GET  /tukan_central.php?accion=sso&t=TOKEN     → entra como superadministrador y abre el panel (o la pantalla de «ir»)
 *   v1  GET  /tukan_central.php?accion=estado&t=TOKEN  → cifras del programa en JSON
 *   v2  POST /tukan_central.php?accion=<acción>        → JSON firmado (cabecera X-Tukan-Token): capacidades, clientes, cliente,
 *            cotizar, planes, solicitudes, solicitud, adjunto, finanzas, ejecutar
 * Si el conector no está configurado responde 404.
 */
if (($_GET['accion'] ?? '') !== 'sso') define('TC_SIN_SESION', true); // solo el SSO abre sesión; lo demás no pone cookie
require __DIR__ . '/../app/bootstrap.php';
tc_atender();
