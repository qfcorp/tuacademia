<?php
/* (v33) Mapa del sitio para buscadores: páginas de venta, manuales y páginas públicas de las cuentas activas del directorio. */
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
echo xml_sitemap();
