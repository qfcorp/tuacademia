<?php
/*
 * Crea (o actualiza) el SUPERADMINISTRADOR desde la terminal.
 * Uso:  sudo -u www-data php /var/www/ligas/app/crear_admin.php
 */
if (PHP_SAPI !== 'cli') {
    exit('Solo desde la terminal.');
}
require __DIR__ . '/bootstrap.php';

function preguntar(string $texto): string
{
    echo $texto;
    $r = fgets(STDIN);
    return trim((string)$r);
}

echo "\n=== Crear superadministrador del Sistema de Ligas ===\n\n";
$nombre = preguntar('Nombre completo: ');
$email = mb_strtolower(preguntar('Correo (será su usuario): '), 'UTF-8');
$clave = preguntar('Contraseña (mínimo 8 caracteres): ');

if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($clave) < 8) {
    echo "\nERROR: datos inválidos (revise el correo y que la contraseña tenga 8 o más caracteres). No se creó nada.\n";
    exit(1);
}
$existe = fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email]);
if ($existe) {
    q("UPDATE usuarios SET nombre = ?, clave = ?, rol = 'super', liga_id = NULL, equipo_id = NULL, activo = 1, totp_secreto = '', totp_activo = 0, totp_respaldo = '' WHERE id = ?", [$nombre, password_hash($clave, PASSWORD_DEFAULT), $existe['id']]);
    echo "\nLISTO: el usuario ya existía; se actualizó su contraseña, se le quitó la doble verificación (puede volver a activarla en Mi cuenta) y quedó como superadministrador.\n";
} else {
    q("INSERT INTO usuarios (nombre, email, clave, rol) VALUES (?,?,?, 'super')", [$nombre, $email, password_hash($clave, PASSWORD_DEFAULT)]);
    echo "\nLISTO: superadministrador creado. Ya puede entrar al panel con $email\n";
}
