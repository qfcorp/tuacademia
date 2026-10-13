<?php
/*
 * (v24) PROTECCIÓN DE DATOS PERSONALES (LOPDP Ecuador)
 *
 *  - Registro de AUTORIZACIONES con prueba: fecha y hora, IP, navegador, quién autorizó (el titular, su representante
 *    o la organización que declara tenerla), el TEXTO EXACTO aceptado y la VERSIÓN de la política. Tabla consentimientos.
 *  - Casillas estándar para los formularios (la persona escribe sus datos = "titular"; la organización escribe los de
 *    otra persona = "tercero"). Incluyen el aviso de que los datos se guardan fuera del Ecuador (transferencia internacional).
 *  - SOLICITUDES DE DERECHOS del titular (acceso, rectificación, eliminación, oposición, suspensión, portabilidad y retirar
 *    la autorización): formulario público y bandeja en el panel. Tabla solicitudes_derechos.
 */

function version_privacidad(): string
{
    $v = (string)ajuste('privacidad_version');
    return $v !== '' ? $v : '2026-10-01';
}

/** Dónde están los datos (para la política y los avisos). */
function txt_alojamiento(): string
{
    $t = (string)ajuste('privacidad_alojamiento');
    return $t !== '' ? $t : 'servidores de OVHcloud en Canadá, protegidos por la red de Cloudflare';
}

/**
 * Texto de la autorización.
 *  $quien 'titular': la persona escribe sus propios datos (o los de su hijo, como representante).
 *  $quien 'tercero': la organización escribe los datos de otra persona y declara tener su autorización.
 */
function texto_autorizacion(string $quien, string $para = 'la organización del campeonato'): string
{
    return $quien === 'tercero'
        ? 'Confirmo que la persona (o su representante legal, si es menor de edad) autorizó el uso de sus datos para ' . $para
          . ', según la política de privacidad, incluido su almacenamiento en servidores fuera del Ecuador.'
        : 'Autorizo el uso de mis datos (o los de mi representado) para ' . $para
          . ', según la política de privacidad, incluido su almacenamiento en servidores fuera del Ecuador (' . txt_alojamiento() . ').';
}

/** Casilla de autorización lista para poner en un formulario. */
function html_autorizacion(string $quien, string $para, string $url_privacidad = 'index.php?v=privacidad', string $campo = 'acepta_datos', bool $marcada = false): string
{
    $t = e(texto_autorizacion($quien, $para));
    $t = str_replace('política de privacidad', '<a href="' . e($url_privacidad) . '" target="_blank" rel="noopener">política de privacidad</a>', $t);
    return '<label class="check autorizacion-datos" style="font-weight:400"><input type="checkbox" name="' . e($campo) . '" value="1" required' . ($marcada ? ' checked' : '') . '> 🔒 ' . $t . '</label>';
}

function exigir_autorizacion(string $campo = 'acepta_datos', string $msg = 'Marque la casilla de autorización de uso de datos personales.'): void
{
    if (post($campo) !== '1') throw new RuntimeException($msg);
}

function enmascarar_documento(string $d): string
{
    $d = preg_replace('/\s+/', '', $d);
    return $d === '' ? '' : str_repeat('•', max(0, min(6, strlen($d) - 4))) . substr($d, -4);
}

/** Formularios que registran autorizaciones (para filtrar y mostrar). */
function formularios_consentimiento(): array
{
    return [
        'jugador' => 'Jugador inscrito', 'delegado_jugador' => 'Jugador inscrito por el delegado', 'importacion' => 'Importación desde Excel',
        'alumno' => 'Alumno de escuela', 'inscripcion_escuela' => 'Inscripción en línea (escuela)', 'inscripcion_equipo' => 'Inscripción de equipo (público)',
        'reserva' => 'Reserva de cancha', 'polla' => 'Polla', 'registro' => 'Registro de organización', 'demo' => 'Solicitud de demo',
        'pago' => 'Pago con tarjeta', 'participante_carrera' => 'Piloto / ciclista', 'participante_duelo' => 'Competidor (duelos)',
        'participante_bolos' => 'Jugador de bolos', 'participante_pruebas' => 'Nadador / atleta', 'inscripcion_individual' => 'Inscripción en línea (deportes individuales)', 'arbitro' => 'Árbitro', 'profesor' => 'Profesor', 'usuario' => 'Usuario del panel',
        'vendedor' => 'Vendedor', 'derechos' => 'Solicitud de derechos', 'olimp_deportista' => 'Deportista de olimpiadas', // (v37)
    ];
}

/**
 * Guarda la prueba de una autorización. $por: 'titular' | 'representante' | 'organizacion'.
 * $extra: ref_tabla, ref_id, documento, representante, texto, para.
 */
function registrar_consentimiento(string $formulario, ?int $liga_id, string $titular, string $por = 'titular', array $extra = []): int
{
    $u = function_exists('usuario') ? usuario() : null;
    $texto = (string)($extra['texto'] ?? texto_autorizacion($por === 'organizacion' ? 'tercero' : 'titular', (string)($extra['para'] ?? 'la organización del campeonato')));
    q('INSERT INTO consentimientos (liga_id, formulario, ref_tabla, ref_id, titular, documento, por, representante, texto, version, ip, agente, usuario, creado)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $liga_id ?: null, $formulario, (string)($extra['ref_tabla'] ?? ''), isset($extra['ref_id']) ? (int)$extra['ref_id'] : null,
        mb_substr(trim($titular), 0, 120), enmascarar_documento((string)($extra['documento'] ?? '')), $por,
        mb_substr(trim((string)($extra['representante'] ?? '')), 0, 120), $texto, version_privacidad(), ip_cliente(),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'consola' : '')), 0, 160),
        $u ? mb_substr($u['nombre'] . ' <' . $u['email'] . '>', 0, 120) : '', date('Y-m-d H:i:s'),
    ]);
    return (int)db()->lastInsertId();
}

/** Atajo: registra la autorización que la ORGANIZACIÓN declara tener al cargar los datos de otra persona. */
function registrar_autorizacion_tercero(string $formulario, ?int $liga_id, string $titular, string $para, array $extra = []): int
{
    return registrar_consentimiento($formulario, $liga_id, $titular, 'organizacion', $extra + ['texto' => texto_autorizacion('tercero', $para)]);
}

/* ============================================================
 * DERECHOS DEL TITULAR
 * ============================================================ */

function tipos_derecho(): array
{
    return [
        'acceso' => 'Acceso: saber qué datos míos tienen',
        'rectificacion' => 'Rectificación: corregir datos equivocados',
        'eliminacion' => 'Eliminación: borrar mis datos',
        'oposicion' => 'Oposición: que dejen de usar mis datos para algo',
        'suspension' => 'Suspensión: que paren el uso mientras se revisa',
        'portabilidad' => 'Portabilidad: una copia de mis datos',
        'revocar' => 'Retirar la autorización que di',
    ];
}

function estados_derecho(): array
{
    return ['nueva' => 'Nueva', 'en_proceso' => 'En proceso', 'respondida' => 'Respondida', 'rechazada' => 'No procede'];
}

function solicitudes_derechos_pendientes(?int $liga_id): int
{
    if ($liga_id === null) return (int)valor("SELECT COUNT(*) FROM solicitudes_derechos WHERE estado IN ('nueva','en_proceso')");
    return (int)valor("SELECT COUNT(*) FROM solicitudes_derechos WHERE liga_id = ? AND estado IN ('nueva','en_proceso')", [$liga_id]);
}

/** Crea una solicitud desde el formulario público. Devuelve el número de solicitud. */
function crear_solicitud_derecho(?array $liga, array $d): int
{
    $tipo = (string)($d['tipo'] ?? '');
    if (!isset(tipos_derecho()[$tipo])) throw new RuntimeException('Elija qué derecho quiere ejercer.');
    $nombre = mb_substr(trim((string)($d['nombre'] ?? '')), 0, 120);
    if (mb_strlen($nombre) < 5) throw new RuntimeException('Escriba su nombre completo.');
    $email = trim((string)($d['email'] ?? ''));
    $tel = mb_substr(trim((string)($d['telefono'] ?? '')), 0, 30);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
    if ($email === '' && strlen(preg_replace('/\D/', '', $tel)) < 7) throw new RuntimeException('Escriba un correo o un celular para responderle.');
    $detalle = mb_substr(trim((string)($d['detalle'] ?? '')), 0, 1500);
    if (mb_strlen($detalle) < 10) throw new RuntimeException('Cuente brevemente qué necesita (ej: "corregir mi fecha de nacimiento", "borrar la foto de mi hijo").');
    $doc = preg_replace('/\s+/', '', (string)($d['cedula'] ?? ''));
    if (strlen($doc) < 6) throw new RuntimeException('Escriba su número de cédula o pasaporte (para verificar que es el titular).');
    q('INSERT INTO solicitudes_derechos (liga_id, tipo, nombre, cedula, email, telefono, en_nombre_de, detalle, estado, ip, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        $liga ? (int)$liga['id'] : null, $tipo, $nombre, $doc, mb_substr($email, 0, 120), $tel, mb_substr(trim((string)($d['en_nombre_de'] ?? '')), 0, 120),
        $detalle, 'nueva', ip_cliente(), date('Y-m-d H:i:s'),
    ]);
    $id = (int)db()->lastInsertId();
    registrar_consentimiento('derechos', $liga ? (int)$liga['id'] : null, $nombre, 'titular', ['ref_tabla' => 'solicitudes_derechos', 'ref_id' => $id, 'documento' => $doc,
        'texto' => 'Autorizo el uso de estos datos solo para atender mi solicitud de protección de datos.']);
    if ($liga && function_exists('aviso_admins')) {
        aviso_admins($liga, '🔒 <b>Solicitud de protección de datos</b> N.º ' . $id . "\n" . e(tipos_derecho()[$tipo]) . "\n" . e($nombre) . "\nRespóndala en Configuración → Protección de datos.", 'der:' . $id);
    }
    return $id;
}

/** Días que lleva abierta una solicitud. */
function dias_solicitud(array $s): int
{
    $fin = in_array($s['estado'], ['respondida', 'rechazada'], true) && $s['respondido_en'] !== '' ? strtotime($s['respondido_en']) : time();
    return max(0, (int)floor(($fin - strtotime($s['creado'])) / 86400));
}

/**
 * Busca en la liga dónde están los datos de una persona (para atender una solicitud de derechos).
 * Por cédula (jugadores, alumnos, representantes, árbitros, profesores) y por nombre (pilotos y competidores individuales).
 * Devuelve [['que' => 'Jugador', 'nombre' => ..., 'url' => ...], ...]
 */
function buscar_titular(int $lid, string $cedula, string $nombre): array
{
    $r = [];
    $ced = preg_replace('/\s+/', '', $cedula);
    if (strlen($ced) >= 6) {
        foreach (filas('SELECT j.id, j.nombres, j.apellidos, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.cedula = ? AND j.anonimizado = 0', [$lid, $ced]) as $x) {
            $r[] = ['que' => 'Jugador (' . $x['equipo'] . ')', 'nombre' => $x['nombres'] . ' ' . $x['apellidos'], 'url' => 'admin.php?p=jugador&id=' . $x['id']];
        }
        foreach (filas('SELECT id, nombres, apellidos, cedula, representante_cedula FROM esc_alumnos WHERE liga_id = ? AND (cedula = ? OR representante_cedula = ?)', [$lid, $ced, $ced]) as $x) {
            $r[] = ['que' => $x['cedula'] === $ced ? 'Alumno' : 'Representante del alumno', 'nombre' => $x['nombres'] . ' ' . $x['apellidos'], 'url' => 'admin.php?p=alumno&id=' . $x['id']];
        }
        foreach (filas('SELECT id, nombre FROM arbitros WHERE liga_id = ? AND cedula = ?', [$lid, $ced]) as $x) $r[] = ['que' => 'Árbitro', 'nombre' => $x['nombre'], 'url' => 'admin.php?p=arbitros'];
        foreach (filas('SELECT id, nombre FROM esc_profesores WHERE liga_id = ? AND cedula = ?', [$lid, $ced]) as $x) $r[] = ['que' => 'Profesor', 'nombre' => $x['nombre'], 'url' => 'admin.php?p=profesores'];
        // (v26) Inscripciones en línea de deportes individuales
        foreach (filas('SELECT i.nombre, i.torneo_id, t.nombre AS torneo FROM inscripciones_indiv i JOIN torneos t ON t.id = i.torneo_id WHERE i.liga_id = ? AND i.cedula = ?', [$lid, $ced]) as $x) {
            $r[] = ['que' => 'Inscripción en línea en ' . $x['torneo'], 'nombre' => $x['nombre'], 'url' => 'admin.php?p=indiv&id=' . $x['torneo_id'] . '&s=inscripciones'];
        }
    }
    $nom = trim($nombre);
    if (mb_strlen($nom) >= 5) {
        $like = '%' . str_replace(['%', '_'], '', $nom) . '%';
        foreach (filas('SELECT cp.nombre, t.id AS tid, t.nombre AS torneo FROM carrera_participantes cp JOIN torneos t ON t.id = cp.torneo_id WHERE t.liga_id = ? AND cp.nombre LIKE ?', [$lid, $like]) as $x) {
            $r[] = ['que' => 'Competidor en ' . $x['torneo'], 'nombre' => $x['nombre'], 'url' => 'admin.php?p=carrera&id=' . $x['tid']];
        }
        foreach (filas('SELECT dp.nombre, t.id AS tid, t.nombre AS torneo FROM duelo_participantes dp JOIN torneos t ON t.id = dp.torneo_id WHERE t.liga_id = ? AND dp.nombre LIKE ?', [$lid, $like]) as $x) {
            $r[] = ['que' => 'Competidor en ' . $x['torneo'], 'nombre' => $x['nombre'], 'url' => 'admin.php?p=duelo&id=' . $x['tid']];
        }
        foreach (filas('SELECT pp.nombre, t.id AS tid, t.nombre AS torneo FROM prueba_participantes pp JOIN torneos t ON t.id = pp.torneo_id WHERE t.liga_id = ? AND pp.nombre LIKE ?', [$lid, $like]) as $x) {
            $r[] = ['que' => 'Competidor en ' . $x['torneo'], 'nombre' => $x['nombre'], 'url' => 'admin.php?p=pruebas&id=' . $x['tid'] . '&s=participantes'];
        }
    }
    return $r;
}

/**
 * Registra la autorización de un JUGADOR (casilla html_consentimiento): la marca la liga o el delegado,
 * declarando que el jugador (o su representante si es menor) autorizó.
 */
function registrar_consentimiento_jugador(string $formulario, int $liga_id, array $d, string $ref_tabla, int $ref_id): void
{
    $rep = ($d['consentimiento_por'] ?? '') === 'representante';
    $txt = ($rep ? 'Su representante autorizó' : 'El jugador autorizó') . ' el uso de sus datos para la organización del campeonato, según la política de privacidad, '
        . 'incluido su almacenamiento en servidores fuera del Ecuador (' . txt_alojamiento() . ').'
        . ((int)($d['foto_publica'] ?? 0) === 1 ? ' Autoriza publicar su foto.' : ' No autoriza publicar su foto.');
    registrar_consentimiento($formulario, $liga_id, trim(($d['nombres'] ?? '') . ' ' . ($d['apellidos'] ?? '')), $rep ? 'representante' : 'organizacion', [
        'ref_tabla' => $ref_tabla, 'ref_id' => $ref_id, 'documento' => (string)($d['cedula'] ?? ''),
        'representante' => $rep ? trim(($d['representante_nombre'] ?? '') . ' · ' . ($d['representante_cedula'] ?? '')) : '', 'texto' => $txt,
    ]);
}

/** Autorización de un ALUMNO de escuela. $publico: la llenó el representante en línea (titular); si no, la escuela declara tenerla. */
function registrar_consentimiento_alumno(int $liga_id, array $d, int $aid, bool $publico, string $nombre_escuela): void
{
    $menor = ($d['consentimiento_por'] ?? '') === 'representante';
    $para = 'la gestión de la escuela ' . $nombre_escuela . ' (datos, fotos, medidas y evaluaciones del alumno)';
    $texto = $publico
        ? 'Autorizo a ' . $nombre_escuela . ' a registrar y usar los datos, fotos, medidas y evaluaciones del alumno para la gestión de la escuela, según la política de privacidad, incluido su almacenamiento en servidores fuera del Ecuador (' . txt_alojamiento() . ').'
        : texto_autorizacion('tercero', $para);
    registrar_consentimiento($publico ? 'inscripcion_escuela' : 'alumno', $liga_id, trim(($d['nombres'] ?? '') . ' ' . ($d['apellidos'] ?? '')),
        $publico ? ($menor ? 'representante' : 'titular') : 'organizacion', [
            'ref_tabla' => 'esc_alumnos', 'ref_id' => $aid, 'documento' => (string)($d['cedula'] ?? ''),
            'representante' => $menor ? trim(($d['representante_nombre'] ?? '') . ' · ' . ($d['representante_cedula'] ?? '')) : '', 'texto' => $texto,
        ]);
}

/** Texto de la casilla del pago con tarjeta. */
function texto_autorizacion_pago(): string
{
    return 'Autorizo el uso de mis datos (nombre, cédula, correo y celular) para procesar este pago y emitir el comprobante. '
        . 'Los datos de la tarjeta los procesa la pasarela Plux y no se guardan en ' . marca() . '. Los demás datos se guardan en servidores fuera del Ecuador (' . txt_alojamiento() . ').';
}
