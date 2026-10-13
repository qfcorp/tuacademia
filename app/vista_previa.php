<?php
/*
 * VISTA PREVIA DE PORTALES (solo superadministrador)
 * El superadministrador abre el portal del árbitro, del club (delegado), del jugador o la vocalía
 * tal como lo ve esa persona, para revisarlo o dar soporte. Es SOLO MIRAR: mientras dura la vista
 * previa no se guarda ningún cambio. Se sale con la cinta de arriba o entrando a admin.php.
 * Modo «Probar»: solo en una liga de demostración (se borra cada madrugada) sí se guardan los cambios,
 * para simular de verdad (iniciar una vocalía, llenar un informe arbitral, inscribir un jugador...).
 */

function tipos_vista_previa(): array
{
    return [
        'directivo' => ['Panel del directivo de la liga', '🏆'],
        'arbitro' => ['Portal de árbitros', '🟨'],
        'vocal' => ['Vocalía', '⏱'],
        'jugador' => ['Portal de jugadores', '🪪'],
        'delegado' => ['Portal de clubes (delegados)', '🧑‍💼'],
        'familia' => ['Portal de la familia (padres)', '👨‍👩‍👧'],
        'carnet' => ['Carnet del alumno (lo que ve quien escanea el QR)', '🪪'],
    ];
}

function vista_previa(): ?array
{
    $v = $_SESSION['vista_previa'] ?? null;
    return is_array($v) ? $v : null;
}

/** Abre la vista previa de un portal y devuelve la dirección a la que hay que ir. */
function iniciar_vista_previa(string $tipo, int $id, bool $probar = false): string
{
    if (!es_super()) throw new RuntimeException('Solo el superadministrador puede ver los portales.');
    $yo = usuario();
    $uid = null;
    switch ($tipo) {
        case 'directivo':
            $x = fila("SELECT u.id AS uid, u.nombre, u.perfil, u.liga_id, l.nombre AS liga FROM usuarios u JOIN ligas l ON l.id = u.liga_id WHERE u.id = ? AND u.rol = 'liga' AND u.activo = 1", [$id])
                ?? throw new RuntimeException('Ese directivo no existe o está desactivado.');
            $uid = (int)$x['uid'];
            $texto = ((string)($x['perfil'] ?? 'admin') === 'profesor' ? 'Entrenador ' : 'Usuario ') . $x['nombre'] . ' (' . nombre_perfil((string)($x['perfil'] ?? 'admin')) . ') · ' . $x['liga'];
            $url = 'admin.php';
            break;
        case 'familia':
            $x = fila("SELECT a.*, l.nombre AS escuela FROM esc_alumnos a JOIN ligas l ON l.id = a.liga_id WHERE a.id = ? AND a.representante_cedula <> ''", [$id])
                ?? throw new RuntimeException('Ese alumno no tiene la cédula de su representante.');
            // La familia existe aunque todavía no haya creado su clave (no se le pone clave ni código)
            q("INSERT INTO esc_familias (liga_id, cedula) VALUES (?, ?) ON CONFLICT(liga_id, cedula) DO NOTHING", [$x['liga_id'], $x['representante_cedula']]);
            $_SESSION['familia'] = (int)valor('SELECT id FROM esc_familias WHERE liga_id = ? AND cedula = ?', [$x['liga_id'], $x['representante_cedula']]);
            $texto = 'Familia de ' . nombre_alumno($x) . ($x['representante_nombre'] !== '' ? ' (' . $x['representante_nombre'] . ')' : '') . ' · ' . $x['escuela'];
            $url = 'familia.php';
            break;
        case 'carnet':
            $x = fila('SELECT a.*, l.nombre AS escuela FROM esc_alumnos a JOIN ligas l ON l.id = a.liga_id WHERE a.id = ?', [$id])
                ?? throw new RuntimeException('Alumno no encontrado.');
            $texto = 'Carnet de ' . nombre_alumno($x) . ' · ' . $x['escuela'];
            $url = 'carnet.php?a=' . carnet_alumno($x);
            break;
        case 'arbitro':
            $x = fila("SELECT a.nombre, a.liga_id, u.id AS uid FROM arbitros a JOIN usuarios u ON u.id = a.usuario_id AND u.rol = 'arbitro' AND u.activo = 1 WHERE a.id = ? AND a.activo = 1", [$id])
                ?? throw new RuntimeException('Ese árbitro no tiene usuario para entrar a su portal.');
            $uid = (int)$x['uid'];
            $texto = 'Árbitro ' . $x['nombre'];
            $url = 'arbitro.php';
            break;
        case 'delegado':
            $x = fila("SELECT u.id AS uid, u.nombre, e.nombre AS equipo, e.liga_id FROM usuarios u JOIN equipos e ON e.id = u.equipo_id WHERE u.id = ? AND u.rol = 'delegado' AND u.activo = 1", [$id])
                ?? throw new RuntimeException('Ese delegado no existe o está desactivado.');
            $uid = (int)$x['uid'];
            $texto = 'Club ' . $x['equipo'] . ' (delegado ' . $x['nombre'] . ')';
            $url = 'delegado.php';
            break;
        case 'jugador':
            $x = fila('SELECT j.*, e.nombre AS equipo, e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ? AND j.anonimizado = 0', [$id])
                ?? throw new RuntimeException('Jugador no encontrado.');
            $_SESSION['jugador_portal'] = (int)$x['id'];
            $texto = 'Jugador ' . nombre_jugador($x) . ' (' . $x['equipo'] . ')';
            $url = 'mi.php';
            break;
        case 'vocal':
            $x = fila('SELECT p.id, p.vocal_token, t.liga_id, l.nombre AS local, v.nombre AS visita FROM partidos p JOIN torneos t ON t.id = p.torneo_id
                       JOIN equipos l ON l.id = p.local_id JOIN equipos v ON v.id = p.visitante_id WHERE p.id = ?', [$id])
                ?? throw new RuntimeException('Partido no encontrado.');
            if ($x['vocal_token'] === '') {
                $x['vocal_token'] = nuevo_token();
                q('UPDATE partidos SET vocal_token = ? WHERE id = ?', [$x['vocal_token'], $x['id']]);
            }
            $texto = 'Vocalía ' . $x['local'] . ' vs ' . $x['visita'];
            $url = 'vocal.php?k=' . $x['vocal_token'];
            break;
        default:
            throw new RuntimeException('Portal no válido.');
    }
    if ($probar && (int)valor('SELECT es_demo FROM ligas WHERE id = ?', [(int)$x['liga_id']]) !== 1) {
        throw new RuntimeException('El modo «Probar» (que sí guarda) solo se puede usar en una liga de demostración. En las ligas reales use «Solo mirar».');
    }
    bitacora_evento('sesion', 'Abrió en vista previa (' . ($probar ? 'probar en la demo' : 'solo mirar') . '): ' . $texto, (int)$x['liga_id'], (int)$yo['id']);
    unset($_SESSION['liga_combo']);
    $_SESSION['vista_previa'] = ['tipo' => $tipo, 'uid' => $uid, 'super' => (int)$yo['id'], 'texto' => $texto, 'url' => $url, 'probar' => $probar];
    return $url;
}

function terminar_vista_previa(): void
{
    $v = vista_previa();
    if ($v && $v['tipo'] === 'jugador') unset($_SESSION['jugador_portal']);
    if ($v && $v['tipo'] === 'familia') unset($_SESSION['familia']);
    unset($_SESSION['vista_previa'], $_SESSION['liga_combo']);
}

/**
 * Se ejecuta en cada página: bloquea cualquier cambio mientras dura la vista previa,
 * pone la cinta de aviso arriba y la termina al volver al panel.
 */
function vigilar_vista_previa(): void
{
    $v = vista_previa();
    if (!$v || PHP_SAPI === 'cli') return;
    // Solo vale si quien la abrió sigue siendo el superadministrador conectado
    $real = (int)($_SESSION['uid'] ?? 0) === (int)$v['super'] ? fila("SELECT id FROM usuarios WHERE id = ? AND rol = 'super' AND activo = 1", [(int)$v['super']]) : null;
    if (!$real) {
        terminar_vista_previa();
        return;
    }
    // El panel del directivo se mira dentro de admin.php; los demás portales terminan al volver al panel
    $en_admin = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'admin.php';
    if (get('salir_vista') === '1' || ($en_admin && ($v['tipo'] !== 'directivo' || in_array(get('p'), ['salir', 'login'], true)))) {
        terminar_vista_previa();
        flash('Salió de la vista previa. Está de nuevo en su panel de superadministrador.');
        ir('admin.php?p=portales');
    }
    $probar = !empty($v['probar']);
    // Aun probando, nunca se cambian la contraseña, la doble verificación ni los usuarios de otra persona
    if (es_post() && $en_admin && in_array(get('p'), ['seguridad', 'clave', 'usuarios'], true)) {
        flash('Vista previa: la cuenta, la contraseña y los usuarios de esa persona no se cambian desde aquí.', 'error');
        ir($v['url']);
    }
    if (es_post() && !$probar) {
        flash('👀 Vista previa: está mirando el portal como lo ve la persona. Aquí no se guarda ningún cambio.', 'error');
        ir($v['url']);
    }
    ob_start(function (string $html) use ($v, $probar): string {
        $cinta = '<div class="demo-cinta no-imprimir" style="background:' . ($probar ? '#b45309' : '#6a1b9a') . ';position:sticky;top:0;z-index:999">'
            . ($probar ? '🧪 <b>Probando como el usuario</b> · ' : '👀 <b>Vista previa del superadministrador</b> · ')
            . e($v['texto']) . ($probar ? ' · liga de demostración: los cambios SÍ se guardan (se borran cada madrugada)' : ' · solo mirar, no se guardan cambios') . ' · <a href="' . e($v['url']) . '">Inicio del portal</a> · <a href="admin.php?salir_vista=1">✖ Salir y volver a mi panel</a></div>';
        return preg_replace_callback('/<body[^>]*>/i', fn($m) => $m[0] . $cinta, $html, 1) ?? $html;
    });
}
