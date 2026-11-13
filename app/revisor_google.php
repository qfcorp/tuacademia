<?php
/*
 * (13 nov 2026) Cuenta de prueba para que Google revise la app de Android «TuAcademia» (com.tukansoftware.tuacademia) sin ver datos reales.
 * Arma la «Academia Ejemplo Tukán» aparte (datos inventados: cursos de baile y música, alumnos, asistencia con fotos, pensiones,
 * niveles, un recital y clases particulares) con el plan Completo activo por 1 año (sin pagos a Tukán) y el usuario
 * revisor.google@tukanec.com (dueño/administrador de esa academia). No toca ninguna otra academia ni usuario.
 *
 * En el VPS del ERP (Docker), copiar y pegar:
 *   Crear (o volver a crear con fechas de hoy y clave nueva):
 *     sudo docker exec -u www-data tuacademia-php php /var/www/tuacademia/app/revisor_google.php crear
 *   Ver si existe:
 *     sudo docker exec -u www-data tuacademia-php php /var/www/tuacademia/app/revisor_google.php estado
 *   Borrar (cuando ya no se necesite):
 *     sudo docker exec -u www-data tuacademia-php php /var/www/tuacademia/app/revisor_google.php borrar
 * La clave (Demo- y 6 numeros) sale UNA sola vez: cópiela en Play Console → Contenido de la app → Acceso a la app.
 * Los mensajes van sin tildes a propósito (se leen bien por SSH desde el celular).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$accion = $argv[1] ?? '';
$sin_tildes = fn(string $s): string => strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', '«' => '"', '»' => '"', '→' => '->']);
$academia = $sin_tildes(REVISOR_GOOGLE_ACADEMIA);
$sitio = rtrim((string)ajuste('url_sitio', ''), '/');
if ($sitio === '') $sitio = 'https://tuacademia.tukanec.com';
try {
    if ($accion === 'crear') {
        $r = revisor_google_crear();
        echo "\nListo: " . $academia . " (datos inventados) con " . $r['alumnos'] . " alumnos y " . $r['cursos'] . " cursos.\n";
        echo "Plan " . $sin_tildes($r['plan']) . " activo hasta el " . $r['vence'] . " (sin pagos a Tukan).\n";
        echo "No toca ninguna otra academia ni usuario.\n";
        echo "\nCopia esto en Google Play Console -> Contenido de la app -> Acceso a la app:\n";
        echo "  Usuario:    " . REVISOR_GOOGLE_CORREO . "\n";
        echo "  Contrasena: " . $r['clave'] . "\n";
        echo "\n(La contrasena no se guarda en ningun lado: si la pierdes, corre otra vez 'crear'.)\n";
        echo "Pruebala entrando en " . $sitio . "/admin.php?p=login\n\n";
        exit(0);
    }
    if ($accion === 'borrar') {
        $n = revisor_google_borrar();
        echo $n ? "Listo: se borro la " . $academia . " y el usuario " . REVISOR_GOOGLE_CORREO . ".\n" : "No habia cuenta del revisor: nada que borrar.\n";
        exit(0);
    }
    if ($accion === 'estado') {
        $l = revisor_google_academia();
        if (!$l) { echo "No hay cuenta del revisor. Para crearla: php " . __FILE__ . " crear\n"; exit(0); }
        $pl = plan_de_liga($l);
        echo "Existe: " . $academia . " (usuario " . REVISOR_GOOGLE_CORREO . "), plan " . $sin_tildes((string)($pl['nombre'] ?? 'sin plan')) . ", vence el " . $l['vence'] . ".\n";
        echo "La contrasena no se puede ver: si no la tienes, corre otra vez 'crear' (da una nueva).\n";
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nNo se pudo: " . $sin_tildes($e->getMessage()) . "\n");
    exit(1);
}
echo "Uso: php " . __FILE__ . " crear | estado | borrar\n";
exit(1);
