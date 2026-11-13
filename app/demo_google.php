<?php
/*
 * (13 nov 2026) Mismo programa que app/revisor_google.php (crear | estado | borrar), con el nombre que usa la guía de publicación
 * de tukan-apps (tienda/tuacademia/PUBLICAR_TUACADEMIA_CON_CLAUDE.md). Solo CLI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/revisor_google.php';
