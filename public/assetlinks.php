<?php
/*
 * (v38) /.well-known/assetlinks.json — Digital Asset Links.
 * Le dice a Android que las apps com.tuvocalia.app (Google Play) y com.tuvocalia.directo (APK) son de este sitio,
 * para que se abran a pantalla completa (sin la barra de direcciones de Chrome).
 * nginx manda /.well-known/assetlinks.json a este archivo (lo agrega vps_2_codigo.sh).
 */
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');
echo json_encode(play_assetlinks(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
