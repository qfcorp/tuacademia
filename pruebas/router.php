<?php
/*
 * Solo para las pruebas con «php -S» (pruebas/tukan_central/reiniciar.sh): imita lo que hace nginx en el servidor
 * (try_files $uri $uri/ /index.php) con las direcciones de los PDF públicos, que no son archivos:
 *   /manual.pdf y /primeros_pasos.pdf → public/index.php (demos automáticas, 10 nov 2026).
 * Todo lo demás sigue igual que «php -S» sin router.
 */
$ruta = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($ruta === '/manual.pdf' || $ruta === '/primeros_pasos.pdf') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/index.php';
    chdir(__DIR__ . '/../public');
    require __DIR__ . '/../public/index.php';
    return true;
}
return false;
