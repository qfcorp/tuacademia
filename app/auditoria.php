<?php
/*
 * BITÁCORA DE AUDITORÍA
 * Registra automáticamente TODO cambio en la base de datos (crear, modificar, eliminar) con:
 * quién lo hizo, su rol, desde dónde (panel, vocalía, árbitro, delegado, público), la IP,
 * la fecha y hora, y cómo estaban los datos ANTES y DESPUÉS.
 * Funciona con "disparadores" de la base de datos: ningún cambio se escapa aunque venga de cualquier pantalla.
 * La bitácora está protegida: no se puede modificar ni borrar desde el sistema.
 */

/** Columnas que nunca se guardan en la bitácora (contraseñas y códigos secretos). */
function columnas_ocultas(): array
{
    return ['clave', 'carnet_token', 'vocal_token', 'totp_secreto', 'totp_respaldo', 'tg_codigo', 'pin', 'token', 'portal_pin', 'portal_codigo'];
}

function ip_cliente(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    return preg_replace('/[^0-9a-fA-F:.]/', '', (string)$ip);
}

/**
 * Define quién está actuando (lo llaman la vocalía y otras páginas sin usuario).
 * Si la auditoría ya está activa, actualiza el contexto al momento.
 */
function contexto_auditoria(?array $nuevo = null): array
{
    static $ctx = null;
    if ($nuevo !== null) {
        $ctx = array_merge(['usuario_id' => null, 'usuario' => '', 'rol' => '', 'liga_id' => null, 'origen' => ''], $nuevo);
        if (auditoria_activa()) {
            $st = db()->prepare('UPDATE _ctx SET usuario_id = ?, usuario = ?, rol = ?, liga_id = ?, ip = ?, origen = ?');
            $st->execute([$ctx['usuario_id'], $ctx['usuario'], $ctx['rol'], $ctx['liga_id'], ip_cliente(), $ctx['origen']]);
        }
        return $ctx;
    }
    if ($ctx !== null) return $ctx;
    if (PHP_SAPI === 'cli') {
        return ['usuario_id' => null, 'usuario' => 'Sistema (terminal / tarea automática)', 'rol' => 'sistema', 'liga_id' => null, 'origen' => 'sistema'];
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $origen = ['admin.php' => 'panel', 'arbitro.php' => 'arbitro', 'delegado.php' => 'delegado', 'vocal.php' => 'vocal'][$script] ?? 'publico';
    $u = usuario();
    if ($u) {
        $liga = $u['rol'] === 'super' ? ($_SESSION['liga_trabajo'] ?? null) : $u['liga_id'];
        return ['usuario_id' => (int)$u['id'], 'usuario' => $u['nombre'] . ' <' . $u['email'] . '>', 'rol' => $u['rol'], 'liga_id' => $liga ? (int)$liga : null, 'origen' => $origen];
    }
    return ['usuario_id' => null, 'usuario' => 'Visitante', 'rol' => 'publico', 'liga_id' => null, 'origen' => $origen];
}

function auditoria_activa(?bool $marcar = null): bool
{
    static $activa = false;
    if ($marcar !== null) $activa = $marcar;
    return $activa;
}

/** Crea (solo para esta conexión) el contexto y los disparadores que anotan cada cambio. */
function activar_auditoria(): void
{
    if (auditoria_activa()) return;
    auditoria_activa(true);
    $pdo = db();
    $c = contexto_auditoria();
    $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS _ctx (usuario_id INTEGER, usuario TEXT, rol TEXT, liga_id INTEGER, ip TEXT, origen TEXT)');
    $pdo->exec('DELETE FROM _ctx');
    $st = $pdo->prepare('INSERT INTO _ctx VALUES (?,?,?,?,?,?)');
    $st->execute([$c['usuario_id'], $c['usuario'], $c['rol'], $c['liga_id'], ip_cliente(), $c['origen']]);

    $tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'bitacora'")->fetchAll(PDO::FETCH_COLUMN);
    // Tablas técnicas que no se auditan (colas, contadores) y ajustes (se anotan como evento, sin mostrar claves secretas)
    // (v7) también las vistas de patrocinadores, los votos del público y la polla: cambian a cada rato y no son datos de la liga
    $sin_auditoria = ['bol_escaneos', 'bol_items', 'telegram_cola', 'acciones_offline', 'intentos', 'ajustes', 'patrocinio_vistas', 'votos', 'polla_participantes', 'polla_pronosticos',
        // (v10) códigos de recuperación de contraseña y suscripciones a notificaciones del público
        'recuperaciones', 'push_suscripciones', 'push_seguimientos', 'push_cola',
        // (v11) códigos y claves del portal de las familias de una escuela
        'esc_familias',
        // (v24) el registro de autorizaciones ya es en sí mismo una prueba con fecha, IP y texto
        'consentimientos',
        // (v27) las conversaciones de soporte ya guardan quién escribió y cuándo
        'soporte_mensajes',
        // (Tukán Central) nonces de un solo uso de los tokens del panel central
        'tukan_nonces'];
    foreach ($tablas as $t) {
        if (!preg_match('/^[a-z_]+$/', $t) || in_array($t, $sin_auditoria, true)) continue;
        $cols = array_filter(array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name'), fn($x) => !in_array($x, columnas_ocultas(), true));
        // (v8) El JSON se arma concatenando texto con json_quote(): json_object() tiene un límite de argumentos
        // (127 en SQLite) y tablas como "ligas" ya tienen más de 60 columnas.
        $json = fn(string $fila) => "('{' || " . implode(" || ',' || ", array_map(fn($col) => "'\"$col\":' || json_quote($fila.\"$col\")", $cols)) . " || '}')";
        $liga = fn(string $fila) => $t === 'ligas' ? "COALESCE(c.liga_id, $fila.rowid)" : (in_array('liga_id', $cols, true) ? "COALESCE(c.liga_id, $fila.liga_id)" : 'c.liga_id');
        $base = "INSERT INTO bitacora (usuario_id, usuario, rol, liga_id, ip, origen, tabla, operacion, registro_id, antes, despues)";
        $pdo->exec("CREATE TEMP TRIGGER IF NOT EXISTS aud_{$t}_i AFTER INSERT ON main.$t BEGIN
            $base SELECT c.usuario_id, c.usuario, c.rol, {$liga('NEW')}, c.ip, c.origen, '$t', 'crear', NEW.rowid, NULL, {$json('NEW')} FROM _ctx c; END");
        $pdo->exec("CREATE TEMP TRIGGER IF NOT EXISTS aud_{$t}_u AFTER UPDATE ON main.$t WHEN {$json('OLD')} IS NOT {$json('NEW')} BEGIN
            $base SELECT c.usuario_id, c.usuario, c.rol, {$liga('NEW')}, c.ip, c.origen, '$t', 'modificar', NEW.rowid, {$json('OLD')}, {$json('NEW')} FROM _ctx c; END");
        $pdo->exec("CREATE TEMP TRIGGER IF NOT EXISTS aud_{$t}_d AFTER DELETE ON main.$t BEGIN
            $base SELECT c.usuario_id, c.usuario, c.rol, {$liga('OLD')}, c.ip, c.origen, '$t', 'eliminar', OLD.rowid, {$json('OLD')}, NULL FROM _ctx c; END");
    }
}

/** Anota un evento que no es un cambio de datos (inicio de sesión, descarga, intento fallido...). */
function bitacora_evento(string $tipo, string $descripcion, ?int $liga_id = null, ?int $registro_id = null): void
{
    try {
        $c = contexto_auditoria();
        $st = db()->prepare("INSERT INTO bitacora (usuario_id, usuario, rol, liga_id, ip, origen, tabla, operacion, registro_id, descripcion) VALUES (?,?,?,?,?,?,?, 'evento', ?, ?)");
        $st->execute([$c['usuario_id'], $c['usuario'], $c['rol'], $liga_id ?? $c['liga_id'], ip_cliente(), $c['origen'], $tipo, $registro_id, mb_substr($descripcion, 0, 500)]);
    } catch (Throwable) {
        // la bitácora nunca debe impedir el trabajo
    }
}

/* ---------- Presentación ---------- */

function nombre_tabla(string $t): string
{
    return [
        'ligas' => 'Liga', 'usuarios' => 'Usuario', 'equipos' => 'Equipo', 'jugadores' => 'Jugador', 'torneos' => 'Campeonato',
        'torneo_equipos' => 'Inscripción de equipo', 'partidos' => 'Partido', 'eventos' => 'Gol/tarjeta', 'sanciones' => 'Sanción a jugador',
        'sanciones_equipo' => 'Sanción a equipo', 'arbitros' => 'Árbitro', 'vocales_partido' => 'Vocal', 'alineaciones' => 'Jugador presente',
        'informes' => 'Informe arbitral', 'informe_eventos' => 'Gol/tarjeta del informe', 'fotos_partido' => 'Foto interna',
        'movimientos' => 'Movimiento de dinero', 'calificaciones' => 'Calificación de árbitro', 'figuras' => 'Figura de la fecha',
        'solicitudes_jugador' => 'Solicitud de jugador', 'solicitudes_equipo' => 'Solicitud de equipo', 'sesion' => 'Sesión', 'exportacion' => 'Descarga',
        'planes' => 'Plan de venta', 'pagos_suscripcion' => 'Pago de suscripción', 'canchas' => 'Cancha', 'pases' => 'Pase de jugador',
        'carnet' => 'Carnet', 'ajustes' => 'Ajustes del sistema', 'seguridad' => 'Seguridad', 'datos_personales' => 'Datos personales',
        'telegram' => 'Telegram', 'suscripcion' => 'Suscripción', 'programacion' => 'Programación',
        'patrocinadores' => 'Patrocinador', 'partido_sets' => 'Set (ecuavóley)', 'votaciones' => 'Votación', 'votacion_opciones' => 'Opción de votación',
        'polla' => 'Polla', 'importacion' => 'Importación Excel',
        'comprobantes' => 'Comprobante de pago', 'pagos_arbitro' => 'Pago a árbitro', 'pagos_arbitro_partidos' => 'Partido pagado a árbitro',
        'reservas' => 'Reserva de cancha', 'portal_jugador' => 'Portal del jugador', 'llaves' => 'Llaves eliminatorias',
        'vendedores' => 'Vendedor', 'comisiones' => 'Comisión de vendedor', 'copia' => 'Copia de campeonato',
        'solicitudes_demo' => 'Solicitud de demo', 'esc_alumnos' => 'Alumno', 'esc_grupos' => 'Grupo de entrenamiento', 'esc_profesores' => 'Profesor', 'esc_sedes' => 'Sede',
        'esc_sesiones' => 'Clase (plan y asistencia)', 'esc_asistencia' => 'Asistencia de alumno', 'esc_cargos' => 'Cobro de escuela', 'esc_pagos_profesor' => 'Pago a profesor',
        'esc_mediciones' => 'Medidas corporales', 'esc_tests' => 'Test de rendimiento', 'esc_resultados' => 'Resultado de test', 'esc_habilidades' => 'Habilidad técnica',
        'esc_eval_tecnica' => 'Evaluación técnica', 'esc_lesiones' => 'Lesión', 'esc_libretas' => 'Libreta', 'esc_productos' => 'Producto de tienda', 'esc_ventas' => 'Venta de tienda',
        'familia' => 'Portal de la familia', 'escuela' => 'Escuela',
        'esc_ejercicios' => 'Ejercicio de la biblioteca', 'esc_sesion_ejercicios' => 'Ejercicio de una clase', 'esc_sesion_fotos' => 'Foto de una clase',
        'esc_notas_clase' => 'Nota del profesor al alumno', 'esc_ciclos' => 'Ciclo de entrenamiento', 'esc_jornadas' => 'Jornada de evaluación', 'esc_metas' => 'Meta del alumno',
        'esc_baremos' => 'Semáforo por edad', 'esc_staff_notas' => 'Nota del cuerpo técnico', 'esc_partidos' => 'Partido de la categoría', 'esc_partido_jugadores' => 'Jugador en un partido',
        'esc_valoraciones' => 'Valoración del jugador', 'esc_academico' => 'Seguimiento escolar', 'esc_lesion_seguimiento' => 'Seguimiento de lesión',
        'tablas_acumuladas' => 'Tabla acumulada', 'pedidos_delegado' => 'Pedido de delegado', 'demo' => 'Liga de demostración',
        'recuperacion' => 'Recuperar contraseña', 'push' => 'Notificaciones',
        'carrera_categorias' => 'Categoría de carreras', 'carrera_participantes' => 'Piloto / ciclista', 'carrera_fechas' => 'Fecha de carreras',
        'carrera_resultados' => 'Tiempo de carrera', 'carrera_penalidades' => 'Penalización de carreras',
        'solicitudes_derechos' => 'Solicitud de protección de datos', 'duelo_categorias' => 'Categoría de duelos', 'duelo_participantes' => 'Competidor (duelos)',
        'duelo_combates' => 'Combate / partido individual',
        // (v26)
        'prueba_eventos' => 'Prueba (natación / atletismo)', 'prueba_participantes' => 'Nadador / atleta', 'prueba_resultados' => 'Marca de una prueba',
        'inscripciones_indiv' => 'Inscripción en línea (deporte individual)',
        'soporte_tickets' => 'Ticket de soporte', 'soporte_mensajes' => 'Mensaje de soporte', 'novedades' => 'Novedad del sistema', // (v27)
        'fed_sanciones' => 'Sanción federativa', 'videos' => 'Video de jugador o alumno', 'referidos' => 'Recomendación (referido)', // (v28)
        'bol_ventas' => 'Venta de entradas', 'bol_localidades' => 'Localidad (entradas)', 'bol_ordenes' => 'Compra de entradas', 'bol_items' => 'Detalle de compra de entradas', 'bol_tickets' => 'Entrada (boleto)',
        'esc_alumno_grupos' => 'Alumno en otro grupo', 'esc_competencias' => 'Competencia del alumno', 'esc_grados' => 'Cinturón / grado',
        'liga_documentos' => 'Documento de la liga', 'compras' => 'Factura recibida (SRI)',
        'pagos_linea' => 'Pago con tarjeta', 'liquidaciones_org' => 'Liquidación de cobros con tarjeta', 'pagos' => 'Pagos en línea',
    ][$t] ?? ucfirst(str_replace('_', ' ', $t));
}

function nombre_operacion(string $o): string
{
    return ['crear' => 'Creó', 'modificar' => 'Modificó', 'eliminar' => 'Eliminó', 'evento' => ''][$o] ?? $o;
}

/** Nombre legible del registro (jugador, equipo, partido...). */
function etiqueta_registro(string $tabla, array $d): string
{
    static $eq = [], $jug = [];
    $equipo = function ($id) use (&$eq) {
        if (!$id) return '';
        return $eq[$id] ??= (string)valor('SELECT nombre FROM equipos WHERE id = ?', [$id]);
    };
    $jugador = function ($id) use (&$jug) {
        if (!$id) return '';
        return $jug[$id] ??= (string)valor("SELECT nombres || ' ' || apellidos FROM jugadores WHERE id = ?", [$id]);
    };
    if ($tabla === 'pedidos_delegado' && isset($d['tipo'])) return nombre_tipo_pedido((string)$d['tipo']) . ' · ' . $equipo($d['equipo_id'] ?? 0);
    if (isset($d['local_id'], $d['visitante_id'])) return $equipo($d['local_id']) . ' vs ' . $equipo($d['visitante_id']);
    if (isset($d['nombres'])) return trim($d['nombres'] . ' ' . ($d['apellidos'] ?? ''));
    if ($tabla === 'partido_sets' && isset($d['partido_id'])) {
        $pp = fila('SELECT local_id, visitante_id FROM partidos WHERE id = ?', [$d['partido_id']]);
        return 'Set ' . (int)$d['num'] . ' (' . (int)$d['puntos_local'] . '-' . (int)$d['puntos_visitante'] . ')' . ($pp ? ' · ' . $equipo($pp['local_id']) . ' vs ' . $equipo($pp['visitante_id']) : '');
    }
    if (isset($d['titulo'])) return (string)$d['titulo'];
    if (isset($d['nombre'])) return (string)$d['nombre'];
    if (isset($d['tipo'], $d['jugador_id']) && in_array($tabla, ['eventos', 'informe_eventos'], true)) return nombre_evento((string)$d['tipo']) . ' de ' . $jugador($d['jugador_id']);
    if (isset($d['jugador_id'])) return $jugador($d['jugador_id']);
    if (isset($d['descripcion']) && $d['descripcion'] !== '') return (string)$d['descripcion'];
    if (isset($d['equipo_id'])) return $equipo($d['equipo_id']);
    return '';
}

function valor_legible($v): string
{
    if ($v === null || $v === '') return '—';
    if (is_bool($v)) return $v ? 'sí' : 'no';
    return mb_strimwidth((string)$v, 0, 80, '…');
}

/** Lista de cambios "campo: antes → después" (o los datos principales al crear/eliminar). */
function detalle_cambios(array $r): array
{
    $antes = $r['antes'] ? (json_decode($r['antes'], true) ?: []) : [];
    $despues = $r['despues'] ? (json_decode($r['despues'], true) ?: []) : [];
    $ignorar = ['creado', 'actualizado', 'vivo_desde'];
    $out = [];
    if ($r['operacion'] === 'modificar') {
        foreach ($despues as $k => $v) {
            if (in_array($k, $ignorar, true)) continue;
            if (($antes[$k] ?? null) !== $v) $out[] = [str_replace('_', ' ', $k), valor_legible($antes[$k] ?? null), valor_legible($v)];
        }
    } else {
        $d = $r['operacion'] === 'crear' ? $despues : $antes;
        foreach ($d as $k => $v) {
            if (in_array($k, $ignorar, true) || $v === null || $v === '' || $v === 0) continue;
            $out[] = [str_replace('_', ' ', $k), '', valor_legible($v)];
            if (count($out) >= 10) break;
        }
    }
    return $out;
}
