<?php
/*
 * TuAcademia (Tukán Software) — sistema para academias de baile, música, natación y deportes.
 * Sale de TuVocalía v38 (ligas, campeonatos y escuelas deportivas). Archivo de arranque: se incluye al inicio de cada página.
 */
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('RAIZ', dirname(__DIR__));
define('PUBLIC_DIR', RAIZ . '/public');
define('UPLOADS_DIR', PUBLIC_DIR . '/uploads');

$cfg = [
    'nombre_sitio' => 'TuAcademia',
    'db'           => RAIZ . '/data/ligas.sqlite',
    'zona'         => 'America/Guayaquil',
    // PRODUCTO: 'academia' = TuAcademia (solo academias y escuelas; ligas, campeonatos, árbitros y vocales APAGADOS)
    //           'ligas'    = como TuVocalía (todo encendido). Se cambia en app/config.php: return ['producto' => 'ligas'];
    'producto'     => 'academia',
    // App de Google Play: APAGADA hasta que se decida publicarla (como en TuSalón). Se enciende en app/config.php: 'google_play' => true
    'google_play'  => false,
];
if (is_file(APP_DIR . '/config.php')) {
    $cfg = array_merge($cfg, require APP_DIR . '/config.php');
}
define('CFG', $cfg);
/** Producto que se está ejecutando: 'academia' (TuAcademia) o 'ligas' (todo lo de TuVocalía encendido). */
define('PRODUCTO', in_array($cfg['producto'] ?? 'academia', ['academia', 'ligas'], true) ? $cfg['producto'] : 'academia');
date_default_timezone_set($cfg['zona']);

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !defined('TC_SIN_SESION')) { // (Tukán Central) el estado no abre sesión
    $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // Sesiones propias en data/sesiones con 12 horas de duración (un partido o una jornada completa sin que se cierre)
    $dir_ses = RAIZ . '/data/sesiones';
    if (!is_dir($dir_ses)) @mkdir($dir_ses, 0770, true);
    if (is_dir($dir_ses) && is_writable($dir_ses)) {
        session_save_path($dir_ses);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '200');
    }
    ini_set('session.gc_maxlifetime', '43200');
    session_name('tuacademia');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}

require APP_DIR . '/db.php';
require APP_DIR . '/helpers.php';
require APP_DIR . '/deportes.php';
require APP_DIR . '/auditoria.php';
require APP_DIR . '/ajustes.php';
require APP_DIR . '/seguridad.php';
require APP_DIR . '/logica.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/documentos.php';
require APP_DIR . '/avisos.php';
require APP_DIR . '/competencia.php';
require APP_DIR . '/perfiles.php';
require APP_DIR . '/extras.php';
require APP_DIR . '/v8.php';
require APP_DIR . '/v9.php';
require APP_DIR . '/v10.php';
require APP_DIR . '/escuelas.php';
require APP_DIR . '/multideporte.php';
require APP_DIR . '/escuela_v36.php'; // (v36) deportes nuevos en las escuelas
require APP_DIR . '/carreras.php'; // (v22)
require APP_DIR . '/ciclismo_ruta.php'; // (v34) ruta UCI y MTB
require APP_DIR . '/demos.php';
require APP_DIR . '/demo_completa.php';
require APP_DIR . '/v12.php';
require APP_DIR . '/plux.php';
require APP_DIR . '/v17.php';
require APP_DIR . '/demo_v17.php';
require APP_DIR . '/v18.php';
require APP_DIR . '/contabilidad.php';
require APP_DIR . '/demo_v18.php';
require APP_DIR . '/demo_multideporte.php';
require APP_DIR . '/demo_carreras.php';
require APP_DIR . '/lopdp.php';
require APP_DIR . '/duelos.php';
require APP_DIR . '/bolos.php'; // (v25)
require APP_DIR . '/pruebas.php'; // (v26) natación / atletismo
require APP_DIR . '/ciclismo_pista.php'; // (v34) pista, BMX y freestyle (UCI)
require APP_DIR . '/pruebas_v36.php'; // (v36) atletismo ampliado, carreras de ruta, pesas y triatlón
require_once APP_DIR . '/modulos_deporte.php'; // (v35) módulos deportivos
require APP_DIR . '/individuales.php'; // (v25) carreras + duelos + bolos
require APP_DIR . '/individuales_extra.php'; // (v26) inscripción, imágenes, en vivo, copiar
require APP_DIR . '/v27.php'; // (v27) soporte, ayuda, novedades, primeros pasos, seguimiento de demos, cuentas pagadas
require APP_DIR . '/cargas.php'; // (v27) subida masiva desde Excel
require APP_DIR . '/federacion.php'; // (v28) federaciones
require APP_DIR . '/videos.php'; // (v28) videos del jugador y del alumno
require APP_DIR . '/negocio_liga.php'; // (v28) indicadores del negocio y referidos
require APP_DIR . '/entradas.php'; // (v28) venta de entradas con QR
require APP_DIR . '/v29.php'; // (v29) fixture completo imprimible y esquema del campeonato (plan Profesional)
require APP_DIR . '/v30.php'; // (v30) perfil Asistente con permisos a medida y manuales en 5 idiomas
require APP_DIR . '/v31.php'; // (v31) escuelas de taekwondo, karate y judo: cinturones, pesos, formas y exámenes de grado
require APP_DIR . '/v33.php'; // (v33) correos automáticos, contratar por transferencia, demo sin gracia, SEO, cabeceras
require APP_DIR . '/olimpiadas.php'; // (v37) olimpiadas y juegos multideportivos
require APP_DIR . '/play.php'; // (v38) apps Android y cobro con Google Play
require APP_DIR . '/academia.php'; // (TuAcademia) baile, música, natación, niveles, recitales, clases particulares, DeUna, Payphone y mensajes de pensiones
require APP_DIR . '/demo_v28.php';
require APP_DIR . '/menu.php';
require APP_DIR . '/directorio.php';
require APP_DIR . '/vista_previa.php';
require APP_DIR . '/idioma.php'; // (v28) idiomas: inglés, francés, portugués e italiano
require APP_DIR . '/tukan_central.php'; // (Tukán Central) conector con el Panel Tukán Central
cabeceras_seguridad(); // (v33)
acad_cortar_portales_liga(); // (TuAcademia) vocal, árbitro, delegado, jugador y entradas: solo en el producto de ligas
vigilar_vista_previa();
iniciar_traduccion(); // (v28) si el visitante eligió otro idioma, la página se traduce al final
