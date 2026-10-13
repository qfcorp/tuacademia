<?php
/*
 * (v28) Receptor de videos en el MISMO servidor (cuando no hay laptop configurada en Ajustes → Videos).
 * Todo va firmado: solo funciona con enlaces generados por el sistema.
 */
require __DIR__ . '/../app/bootstrap.php';
require_once APP_DIR . '/videos.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // no bloquear la sesión mientras se envía el video
while (ob_get_level() > 0) ob_end_clean(); // el video sale directo (sin traducción ni memoria extra)
@set_time_limit(300);
vr_atender(['dir' => videos_dir_local(), 'clave' => videos_clave('local'), 'max_mb' => VIDEO_MAX_MB, 'max_seg' => VIDEO_MAX_SEG, 'dias' => VIDEO_DIAS]);
