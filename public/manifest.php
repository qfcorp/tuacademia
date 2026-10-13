<?php
/* Datos para instalar el sistema como aplicación en el celular (PWA) */
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => marca(),
    'short_name' => mb_substr(marca(), 0, 12),
    'description' => PRODUCTO === 'academia' ? 'Academias de baile, música, natación y deportes: alumnos, pensiones, asistencia y portal de padres' : 'Ligas de fútbol: vocalía en vivo, resultados, árbitros y delegados',
    'lang' => 'es',
    'id' => 'app.php', // (v38) misma identidad de siempre (antes start_url era app.php) para no duplicar la app instalada
    'start_url' => 'app.php?origen=web', // (v38) el navegador marca "web"; las apps de Android abren ?origen=play o ?origen=apk
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#0f1614',
    'theme_color' => '#0f1614',
    'icons' => [
        ['src' => 'assets/icono-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icono-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icono-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
