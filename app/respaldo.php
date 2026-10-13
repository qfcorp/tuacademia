<?php
/*
 * Respaldo diario de la base de datos y de las fotos/escudos.
 * Lo ejecuta automáticamente el cron (/etc/cron.d/ligas-respaldo).
 * Manual:  sudo -u www-data php /var/www/tuacademia/app/respaldo.php
 */
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/bootstrap.php';

$destino = $argv[1] ?? '/var/backups/ligas';
if (!is_dir($destino) && !@mkdir($destino, 0770, true)) {
    fwrite(STDERR, "No se pudo crear la carpeta de respaldo $destino\n");
    exit(1);
}
$fecha = date('Ymd_His');
$archivo = "$destino/ligas_$fecha.sqlite";
db()->exec('VACUUM INTO ' . db()->quote($archivo));
$fotos = "$destino/uploads_$fecha.tar.gz";
exec('tar -czf ' . escapeshellarg($fotos) . ' -C ' . escapeshellarg(RAIZ) . ' public/uploads' . (is_dir(RAIZ . '/data/fotos') ? ' data/fotos' : '') . ' 2>/dev/null');

// Borrar respaldos de más de 30 días
foreach (glob("$destino/{ligas_*.sqlite,uploads_*.tar.gz}", GLOB_BRACE) ?: [] as $f) {
    if (filemtime($f) < time() - 30 * 86400) @unlink($f);
}
echo "Respaldo OK: $archivo\n";
