<?php
/*
 * Conector con el Panel Tukán Central (protocolo v1). Toda la lógica está en app/tukan_central.php.
 *   /tukan_central.php?accion=sso&t=TOKEN     → entra como superadministrador y abre el panel
 *   /tukan_central.php?accion=estado&t=TOKEN  → cifras del programa en JSON
 * Si el conector no está configurado responde 404.
 */
if (($_GET['accion'] ?? '') === 'estado') define('TC_SIN_SESION', true); // el estado no necesita sesión ni cookie
require __DIR__ . '/../app/bootstrap.php';
tc_atender();
