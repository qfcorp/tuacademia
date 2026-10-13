<?php
/*
 * TAREA AUTOMÁTICA (la ejecuta el servidor cada minuto: /etc/cron.d/ligas-tareas)
 *  - Lee los mensajes que le escriben al bot de Telegram (vinculación de usuarios y canales)
 *  - Envía los avisos pendientes por Telegram
 *  - Una vez al día: avisos de suscripciones por vencer y limpieza
 *  - (v10) Envía las notificaciones "Seguir a mi equipo" y reinicia la liga de demostración cada madrugada
 * Manual:  sudo -u www-data php /var/www/ligas/app/tareas.php
 */
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/bootstrap.php';

// Evita que dos tareas corran al mismo tiempo
$candado = fopen(RAIZ . '/data/tareas.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) exit;

guardar_ajuste('tarea_ultima', date('Y-m-d H:i'));
if (tg_activo()) {
    tg_leer_mensajes();
    tg_procesar_cola();
}
// (v28) Compras de entradas con tarjeta que no se completaron: liberan su cupo
try { expirar_ordenes_entradas(); } catch (Throwable $ex) { error_log('entradas: ' . $ex->getMessage()); }
try { play_tarea(); } catch (Throwable $ex) { error_log('google play: ' . $ex->getMessage()); } // (v38) renovaciones de Google Play cada 6 horas
// (v10) Notificaciones "Seguir a mi equipo" pendientes
try {
    push_procesar_cola(200);
} catch (Throwable $ex) {
    error_log('push: ' . $ex->getMessage());
}
// (v10) La liga de demostración se vuelve a crear cada madrugada (después de las 3:00)
if ((int)ajuste('demo_activa', 0) === 1 && (int)date('G') >= 3 && ajuste('demo_reinicio') !== date('Y-m-d')) {
    guardar_ajuste('demo_reinicio', date('Y-m-d'));
    try {
        crear_demos(); // (v15) liga y escuela de demostración, con todos los portales
    } catch (Throwable $ex) {
        error_log('demo: ' . $ex->getMessage());
    }
}
if ((int)date('G') >= 8 && ajuste('tarea_diaria') !== date('Y-m-d')) {
    guardar_ajuste('tarea_diaria', date('Y-m-d'));
    avisos_diarios();
    // (v27) Seguimiento de demos: aviso al superadministrador en el día 5 y el día 7
    try { avisos_demos(); } catch (Throwable $ex) { error_log('demos: ' . $ex->getMessage()); }
    // (v28) Videos de jugadores y alumnos: se borran a los 10 días
    try { limpiar_videos(); } catch (Throwable $ex) { error_log('videos: ' . $ex->getMessage()); }
    // (v11) Mensualidades del mes de cada escuela (se crean una sola vez)
    foreach (filas("SELECT * FROM ligas WHERE tipo = 'escuela' AND activa = 1") as $esc) {
        try { generar_mensualidades($esc); } catch (Throwable $ex) { error_log('escuela: ' . $ex->getMessage()); }
    }
    q("DELETE FROM acciones_offline WHERE creado < datetime('now', '-60 days')");
    q("DELETE FROM intentos WHERE creado < datetime('now', '-2 days')");
    q("DELETE FROM push_cola WHERE creado < datetime('now', '-7 days')");
    q("DELETE FROM recuperaciones WHERE creado < datetime('now', '-2 days')");
    if (tg_activo()) tg_procesar_cola();
}
flock($candado, LOCK_UN);
