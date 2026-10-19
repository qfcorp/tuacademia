<?php
/*
 * VERSIÓN 27
 *  - Avisos al SUPERADMINISTRADOR por Telegram (pedidos de demo, tickets, pedidos de funcionalidad) y diagnóstico.
 *  - SOPORTE: tickets con conversación y pedidos de funcionalidad (admin.php?p=soporte).
 *  - AYUDA contextual: botón ❓ en cada pantalla que abre la sección del manual (/manual/ligas#sN).
 *  - NOVEDADES de cada versión (admin.php?p=novedades) y GUÍA DE PRIMEROS PASOS en el Resumen.
 *  - SEGUIMIENTO DE DEMOS (admin.php?p=embudo) y avisos automáticos de demos por vencer.
 *  - Funciones solo para CUENTAS PAGADAS (no demo ni prueba): subida masiva desde Excel y descargar todos mis datos.
 */

/* ============================================================
 * CUENTAS PAGADAS
 * ============================================================ */

/** ¿La cuenta es pagada (no es la demostración ni está en los días de prueba)? El superadministrador siempre puede. */
function cuenta_pagada(?array $liga): bool
{
    if (es_super()) return true;
    if (!$liga || !empty($liga['es_demo']) || !empty($liga['es_prueba'])) return false;
    return estado_suscripcion($liga)['estado'] !== 'prueba';
}

/** Mensaje cuando una función es solo para cuentas pagadas. '' si se puede usar. $modulo: además exige ese módulo del plan. */
function motivo_solo_pagadas(?array $liga, string $modulo = ''): string
{
    if (es_super()) return '';
    if (!$liga) return 'Elija primero una liga o escuela.';
    if (!empty($liga['es_demo'])) return 'Esta función no está disponible en la demostración: se activa al contratar ' . marca() . '.';
    if (!empty($liga['es_prueba']) || estado_suscripcion($liga)['estado'] === 'prueba') return 'Esta función se activa cuando su cuenta pasa de la demo a un plan pagado.';
    if ($modulo !== '' && !modulo_activo($liga, $modulo)) return 'Su plan no incluye «' . nombre_modulo($modulo) . '». Está en los planes completos (Profesional y Escuela Completo).';
    return '';
}

/** Pantalla completa "solo para cuentas pagadas" (para cargas y descarga de datos). */
function pantalla_solo_pagadas(string $titulo, string $motivo, string $volver = 'admin.php'): void
{
    cabecera_admin($titulo);
    $liga = liga_trabajo();
    $wa = telefono_wa((string)ajuste('ventas_whatsapp'));
    ?>
    <p><a href="<?= e($volver) ?>">← Volver</a></p>
    <h1><?= e($titulo) ?></h1>
    <div class="tarjeta" style="max-width:720px">
      <p style="font-size:1.1rem">🔒 <?= e($motivo) ?></p>
      <p class="suave">Con un plan pagado puede subir de una sola vez todos sus <?= $liga && es_escuela($liga) ? 'alumnos, grupos, profesores y sedes' : 'equipos, jugadores, árbitros, canchas y competidores' ?> desde Excel, y descargar una copia de todos sus datos cuando quiera.</p>
      <div class="acciones">
        <?php if ($liga && empty($liga['es_demo'])): ?><a class="btn" href="admin.php?p=mi_plan">Ver planes y pagar</a><?php else: ?><a class="btn" href="<?= e(url_empezar()) ?>">Solicitar mi cuenta</a><?php endif; ?>
        <?php if ($wa): ?><a class="btn btn-sec" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, quiero activar ' . marca() . ($liga ? ' para ' . $liga['nombre'] : '')) ?>" target="_blank" rel="noopener">WhatsApp de ventas</a><?php endif; ?>
      </div>
    </div>
    <?php
    pie_admin();
}

/* ============================================================
 * AVISOS AL SUPERADMINISTRADOR
 * ============================================================ */

function chats_super(): array
{
    return array_column(filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''"), 'tg_chat');
}

/** Avisa a todos los superadministradores por Telegram (si está configurado). $clave evita duplicados. */
function aviso_super(string $texto, string $clave): void
{
    foreach (chats_super() as $c) tg_encolar(null, $c, $texto, '', $clave . ':' . $c);
}

/** Diagnóstico de los avisos al superadministrador: ['ok' => bool, 'problemas' => [...], 'detalle' => [...]] */
function estado_avisos_super(): array
{
    $p = [];
    if (!tg_activo()) $p[] = 'Falta poner el token del bot en Ajustes → Bot de Telegram.';
    $yo = usuario();
    $mis = $yo && $yo['rol'] === 'super' ? (string)valor('SELECT tg_chat FROM usuarios WHERE id = ?', [$yo['id']]) : '';
    if (tg_activo() && !chats_super()) $p[] = 'Ningún superadministrador conectó su Telegram (Mi cuenta → Conectar Telegram).';
    elseif (tg_activo() && $yo && $yo['rol'] === 'super' && $mis === '') $p[] = 'Su usuario todavía no conectó su Telegram (Mi cuenta → Conectar Telegram).';
    $ultima = (string)ajuste('tarea_ultima');
    if ($ultima === '' || strtotime($ultima) < time() - 15 * 60) $p[] = 'La tarea automática no se ejecuta desde ' . ($ultima ?: 'nunca') . ': los avisos se quedan en cola sin salir.';
    return ['ok' => !$p, 'problemas' => $p, 'ultima' => $ultima,
        'pendientes' => tg_activo() ? (int)valor("SELECT COUNT(*) FROM telegram_cola WHERE enviado = '' AND intentos < 5") : 0];
}

/* ============================================================
 * SOPORTE: TICKETS Y PEDIDOS DE FUNCIONALIDAD
 * ============================================================ */

function tipos_ticket(): array
{
    return ['problema' => ['🐞', 'Algo no funciona'], 'pregunta' => ['❓', 'Tengo una pregunta (¿cómo se hace…?)'], 'funcionalidad' => ['💡', 'Pedir una funcionalidad nueva'],
        'pagos' => ['💳', 'Pagos, plan o factura'], 'otro' => ['✉️', 'Otro tema']];
}

function prioridades_ticket(): array
{
    return ['baja' => 'Baja', 'normal' => 'Normal', 'alta' => 'Alta', 'urgente' => 'Urgente (no puedo trabajar)'];
}

function estados_ticket(): array
{
    return ['nuevo' => 'Nuevo', 'en_proceso' => 'En proceso', 'esperando' => 'Esperando su respuesta', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'];
}

function chip_ticket(string $estado): string
{
    $c = ['nuevo' => 'chip-pend', 'en_proceso' => 'chip-pend', 'esperando' => '', 'resuelto' => 'chip-ok', 'cerrado' => 'chip-ok'][$estado] ?? '';
    return '<span class="estado-chip ' . $c . '">' . e(estados_ticket()[$estado] ?? $estado) . '</span>';
}

/** Ticket que el usuario puede ver (el superadministrador ve todos; la liga solo los suyos). */
function ticket_visible(int $id): array
{
    $t = fila('SELECT t.*, l.nombre AS liga, l.tipo AS liga_tipo, l.contacto_telefono FROM soporte_tickets t LEFT JOIN ligas l ON l.id = t.liga_id WHERE t.id = ?', [$id]);
    if (!$t) throw new RuntimeException('Ticket no encontrado.');
    if (!es_super()) {
        $liga = liga_trabajo();
        if (!$liga || (int)$t['liga_id'] !== (int)$liga['id']) throw new RuntimeException('Ticket no encontrado.');
    }
    return $t;
}

/** Preguntas del formulario "Pedir una funcionalidad". */
function preguntas_funcionalidad(): array
{
    return [
        'que' => ['¿Qué quiere que haga el sistema? *', 'Descríbalo con sus palabras, paso a paso si puede. Ej: «que el delegado pueda subir la foto del comprobante desde el celular y que a mí me llegue un aviso».'],
        'para_que' => ['¿Qué problema le resuelve o para qué le sirve? *', 'Ej: «hoy los delegados me mandan las fotos por WhatsApp y se me pierden».'],
        'quienes' => ['¿Quiénes lo usarían?', 'Ej: administrador, vocal, árbitro, delegados, padres, profesores, el público.'],
        'donde' => ['¿En qué pantalla o parte del sistema iría?', 'Ej: «en Finanzas» o «en la página pública de la liga».'],
        'como_hoy' => ['¿Cómo lo hace hoy, sin el sistema?', 'Ej: «en un Excel» o «a mano en un cuaderno».'],
        'ejemplo' => ['¿Lo ha visto en otro sistema o tiene un ejemplo?', 'Puede pegar un enlace o describirlo.'],
    ];
}

function importancias_funcionalidad(): array
{
    return ['me_ayudaria' => 'Me ayudaría', 'lo_necesito' => 'Lo necesito pronto', 'indispensable' => 'Es indispensable para seguir usando el sistema'];
}

/** Guarda la captura de un ticket (privada, fuera de la carpeta web). Devuelve la ruta relativa a data/ o ''. */
function adjunto_ticket(string $campo): string
{
    $r = subir_fotos_privadas($campo, 1800);
    return $r[0] ?? '';
}

/** Crea un ticket y avisa al superadministrador. Devuelve el id. */
function crear_ticket(array $liga, array $d): int
{
    $u = usuario();
    $tipo = array_key_exists($d['tipo'] ?? '', tipos_ticket()) ? $d['tipo'] : 'otro';
    $asunto = mb_substr(trim((string)($d['asunto'] ?? '')), 0, 120);
    $texto = trim((string)($d['texto'] ?? ''));
    if (mb_strlen($asunto) < 4) throw new RuntimeException('Escriba un asunto corto (ej: «No me deja imprimir los carnets»).');
    if (mb_strlen($texto) < 10) throw new RuntimeException('Cuéntenos con más detalle qué necesita.');
    $prio = array_key_exists($d['prioridad'] ?? '', prioridades_ticket()) ? $d['prioridad'] : 'normal';
    $tel = mb_substr(trim((string)($d['telefono'] ?? '')), 0, 30);
    q('INSERT INTO soporte_tickets (liga_id, usuario_id, autor, email, telefono, tipo, asunto, pagina, prioridad, estado, detalle, sin_leer_super, creado, actualizado) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?)', [
        $liga['id'], $u['id'] ?? null, mb_substr((string)($u['nombre'] ?? ''), 0, 80), mb_substr((string)($u['email'] ?? ''), 0, 120), $tel, $tipo, $asunto,
        mb_substr((string)($d['pagina'] ?? ''), 0, 120), $prio, 'nuevo', json_encode($d['detalle'] ?? [], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
    ]);
    $tid = (int)db()->lastInsertId();
    q('INSERT INTO soporte_mensajes (ticket_id, de, autor, usuario_id, texto, adjunto, creado) VALUES (?,?,?,?,?,?,?)',
        [$tid, 'cliente', mb_substr((string)($u['nombre'] ?? ''), 0, 80), $u['id'] ?? null, mb_substr($texto, 0, 6000), (string)($d['adjunto'] ?? ''), date('Y-m-d H:i:s')]);
    [$ico, $nom] = tipos_ticket()[$tipo];
    aviso_super($ico . ' <b>' . ($tipo === 'funcionalidad' ? 'Pedido de funcionalidad' : 'Ticket de soporte') . ' #' . $tid . '</b>'
        . "\n" . e($liga['nombre']) . ' · ' . e($u['nombre'] ?? '') . ($tel !== '' ? ' · 📞 ' . e($tel) : '')
        . "\n<b>" . e($asunto) . '</b>' . ($prio !== 'normal' ? ' · Prioridad: ' . e(prioridades_ticket()[$prio]) : '')
        . "\n" . e(mb_substr($texto, 0, 500))
        . "\n\nResponder: " . url_base() . 'admin.php?p=soporte&t=' . $tid, 'ticket:' . $tid);
    bitacora_evento('soporte', 'Abrió el ticket #' . $tid . ': ' . $asunto, (int)$liga['id']);
    return $tid;
}

/** Agrega un mensaje a un ticket. $de: 'cliente' | 'soporte'. */
function responder_ticket(array $t, string $de, string $texto, string $adjunto = '', ?string $estado = null): void
{
    $u = usuario();
    $texto = trim($texto);
    if ($texto === '' && $adjunto === '') throw new RuntimeException('Escriba su mensaje.');
    q('INSERT INTO soporte_mensajes (ticket_id, de, autor, usuario_id, texto, adjunto, creado) VALUES (?,?,?,?,?,?,?)',
        [$t['id'], $de, $de === 'soporte' ? 'Soporte ' . marca() : mb_substr((string)($u['nombre'] ?? ''), 0, 80), $u['id'] ?? null, mb_substr($texto, 0, 6000), $adjunto, date('Y-m-d H:i:s')]);
    if ($de === 'soporte') {
        $nuevo = $estado !== null && array_key_exists($estado, estados_ticket()) ? $estado : ($t['estado'] === 'nuevo' ? 'esperando' : $t['estado']);
        q('UPDATE soporte_tickets SET estado = ?, sin_leer_cliente = 1, sin_leer_super = 0, actualizado = ?, resuelto_en = ? WHERE id = ?',
            [$nuevo, date('Y-m-d H:i:s'), in_array($nuevo, ['resuelto', 'cerrado'], true) ? date('Y-m-d H:i:s') : '', $t['id']]);
        if ($t['liga_id']) {
            $liga = fila('SELECT * FROM ligas WHERE id = ?', [$t['liga_id']]);
            if ($liga) foreach (tg_chats_admins((int)$liga['id']) as $c) {
                tg_encolar((int)$liga['id'], $c, '💬 <b>Respuesta de soporte</b> a su ticket #' . (int)$t['id'] . ': ' . e($t['asunto']) . "\n" . e(mb_substr($texto, 0, 400)) . "\n\nVer: " . url_base() . 'admin.php?p=soporte&t=' . (int)$t['id'], '', 'tresp:' . $t['id'] . ':' . time() . ':' . $c);
            }
        }
    } else {
        $nuevo = in_array($t['estado'], ['resuelto', 'cerrado', 'esperando'], true) ? 'en_proceso' : $t['estado'];
        q('UPDATE soporte_tickets SET estado = ?, sin_leer_super = 1, sin_leer_cliente = 0, actualizado = ? WHERE id = ?', [$nuevo, date('Y-m-d H:i:s'), $t['id']]);
        aviso_super('💬 <b>Nuevo mensaje en el ticket #' . (int)$t['id'] . '</b>' . "\n" . e($t['liga'] ?? '') . ' · ' . e($u['nombre'] ?? '') . "\n<b>" . e($t['asunto']) . '</b>'
            . "\n" . e(mb_substr($texto, 0, 400)) . "\n\nResponder: " . url_base() . 'admin.php?p=soporte&t=' . (int)$t['id'], 'tmsg:' . $t['id'] . ':' . time());
    }
}

function tickets_pendientes_super(): int
{
    return (int)valor("SELECT COUNT(*) FROM soporte_tickets WHERE sin_leer_super = 1 AND estado NOT IN ('cerrado')");
}

function tickets_sin_leer_liga(int $lid): int
{
    return (int)valor('SELECT COUNT(*) FROM soporte_tickets WHERE liga_id = ? AND sin_leer_cliente = 1', [$lid]);
}

/* ============================================================
 * AYUDA CONTEXTUAL (manual en la web)
 * ============================================================ */

/** Sección del manual que explica una página del panel: [manual, ancla]. */
function ayuda_de_pagina(string $p, ?array $liga): array
{
    if ($liga && es_escuela($liga)) {
        $s = [
            'inicio' => 4, 'escuela_inicio' => 4, 'seguridad' => 3, 'clave' => 3, 'escuela_config' => 5, 'grupos' => 6, 'alumnos' => 7, 'alumno' => 7,
            'asistencia' => 8, 'planificacion' => 9, 'cobros' => 10, 'esc_recibo' => 10, 'pagos_linea' => 10, 'profesores' => 11, 'evaluaciones' => 12,
            'formativas' => 13, 'tienda' => 14, 'esc_carnets' => 15, 'usuarios' => 16, 'bitacora' => 16, 'mi_plan' => 17, 'competencias' => 21, 'examenes' => 27, 'datos' => 22,
            'soporte' => 23, 'novedades' => 23, 'cargas' => 24, 'mis_datos' => 24, 'videos' => 25, 'indicadores' => 26, 'referidos' => 26, 'federacion' => 26, 'requisitos' => 29,
            'mensajes' => 40, 'recitales' => 40, 'particulares' => 40, // (TuAcademia)
        ][$p] ?? 1;
        return ['escuelas', 's' . $s];
    }
    $s = [
        'inicio' => 4, 'seguridad' => 3, 'clave' => 3, 'config' => 5, 'equipos' => 6, 'equipo' => 6, 'jugador' => 6, 'importar' => 6,
        'torneos' => 7, 'torneo' => 7, 'programar' => 9, 'partido' => 10, 'planilla' => 10, 'imprimir' => 10, 'acumuladas' => 12,
        'arbitros' => 13, 'liquidacion' => 13, 'finanzas' => 14, 'recibo' => 14, 'pagos_linea' => 14, 'contabilidad' => 14, 'carnets' => 15,
        'redes' => 16, 'patrocinadores' => 16, 'votaciones' => 16, 'polla' => 16, 'widgets' => 16, 'inscripciones' => 17,
        'pedidos' => 18, 'reservas' => 18, 'usuarios' => 18, 'bitacora' => 18, 'mi_plan' => 19, 'carrera' => 21, 'duelo' => 22, 'bolos' => 23,
        'datos' => 24, 'pruebas' => 25, 'indiv' => 26, 'soporte' => 27, 'novedades' => 27, 'cargas' => 28, 'mis_datos' => 28,
        'federacion' => 29, 'entradas' => 30, 'videos' => 31, 'indicadores' => 32, 'referidos' => 32, 'modulos' => 35, 'requisitos' => 35, // (v35)
        'olimpiadas' => 37, // (v37) sección 36 del manual (ancla s37)
    ][$p] ?? 1;
    if (in_array($p, ['carrera', 'pruebas'], true) && (int)get('id') > 0) { // (v34) ciclismo UCI: sección 34 del manual
        $mod = (string)valor('SELECT modalidad FROM torneos WHERE id = ?', [(int)get('id')]);
        if (in_array($mod, ['CICLO', 'MTB', 'PISTA', 'BMX', 'BMXFS'], true)) $s = 34;
    }
    // (v36) squash, ajedrez, e-sports, atletismo, ruta, pesas, triatlón, balonmano y béisbol: sección 35 del manual (ancla s36)
    if (in_array($p, ['duelo', 'pruebas', 'torneo'], true) && (int)get('id') > 0) {
        $mod = (string)valor('SELECT modalidad FROM torneos WHERE id = ?', [(int)get('id')]);
        if (in_array($mod, ['SQUASH', 'AJED', 'ESPOR', 'ATLET', 'RUTA', 'PESAS', 'TRIAT', 'BALON', 'BEISB', 'SOFTB'], true)) $s = 36;
    }
    return ['ligas', 's' . $s];
}

/** Botón flotante "❓ Ayuda" con su menú (manual de esta pantalla, soporte, pedir funcionalidad, novedades). */
function html_boton_ayuda(): string
{
    $u = usuario();
    if (!$u || !in_array($u['rol'], ['liga', 'super'], true)) return '';
    $liga = liga_trabajo();
    $p = get('p', 'inicio');
    [$man, $ancla] = ayuda_de_pagina($p, $liga);
    $nov = novedades_sin_ver();
    $tk = es_super() ? tickets_pendientes_super() : ($liga ? tickets_sin_leer_liga((int)$liga['id']) : 0);
    $marca = $nov + $tk;
    ob_start(); ?>
    <div class="ayuda-flotante no-imprimir" id="ayuda-flotante">
      <div class="ayuda-menu" id="ayuda-menu" hidden>
        <a href="manual/<?= e($man) ?>#<?= e($ancla) ?>" target="_blank" rel="noopener">📘 <b>Ayuda de esta pantalla</b><small>Abre el manual en la parte que explica lo que está viendo</small></a>
        <a href="manual/<?= e($man) ?>" target="_blank" rel="noopener">📖 Manual completo<small>Para leer o descargar en PDF</small></a>
        <?php if (!es_super()): ?>
        <a href="admin.php?p=soporte&amp;nuevo=problema&amp;desde=<?= e(rawurlencode($p)) ?>">🎫 Pedir ayuda a soporte<small>Abra un ticket: le respondemos aquí mismo</small></a>
        <a href="admin.php?p=soporte&amp;nuevo=funcionalidad&amp;desde=<?= e(rawurlencode($p)) ?>">💡 Pedir una funcionalidad<small>Cuéntenos qué le gustaría que haga el sistema</small></a>
        <?php endif; ?>
        <a href="admin.php?p=soporte">💬 <?= es_super() ? 'Tickets de soporte' : 'Mis tickets' ?><?= $tk ? ' <span class="etiqueta roja">' . $tk . '</span>' : '' ?><small><?= es_super() ? 'Responder a las ligas y escuelas' : 'Ver las respuestas de soporte' ?></small></a>
        <a href="admin.php?p=novedades">🆕 Novedades<?= $nov ? ' <span class="etiqueta roja">' . $nov . '</span>' : '' ?><small>Lo nuevo de cada versión</small></a>
      </div>
      <button type="button" class="ayuda-boton" onclick="var m=document.getElementById('ayuda-menu');m.hidden=!m.hidden" aria-expanded="false" aria-controls="ayuda-menu">❓ Ayuda<?= $marca ? ' <span class="ayuda-punto">' . $marca . '</span>' : '' ?></button>
    </div>
    <script>document.addEventListener('click', function (ev) { var f = document.getElementById('ayuda-flotante'); if (f && !f.contains(ev.target)) document.getElementById('ayuda-menu').hidden = true; });</script>
    <?php return (string)ob_get_clean();
}

/* ============================================================
 * NOVEDADES
 * ============================================================ */

/** Novedades que le tocan a la cuenta actual (liga, escuela o todas). */
function novedades_para(?array $liga, int $limite = 50): array
{
    $para = $liga ? (es_escuela($liga) ? 'escuelas' : 'ligas') : '';
    $sql = 'SELECT * FROM novedades WHERE publicada = 1' . ($para !== '' && !es_super() ? " AND para IN ('todos', '" . $para . "')" : '')
        . (PRODUCTO === 'academia' ? " AND (version = 'academia' OR fecha >= '2026-10-13')" : '') // (TuAcademia) no se muestra el historial de TuVocalía
        . ' ORDER BY fecha DESC, id DESC LIMIT ' . (int)$limite;
    return filas($sql);
}

function novedades_sin_ver(): int
{
    $u = usuario();
    if (!$u) return 0;
    $vista = (int)valor('SELECT novedad_vista FROM usuarios WHERE id = ?', [$u['id']]);
    $n = 0;
    foreach (novedades_para(liga_trabajo()) as $x) if ((int)$x['id'] > $vista) $n++;
    return $n;
}

/** Aviso en el Resumen cuando hay novedades sin ver. */
function html_banner_novedades(): string
{
    $n = novedades_sin_ver();
    if (!$n) return '';
    $u = usuario();
    $vista = (int)valor('SELECT novedad_vista FROM usuarios WHERE id = ?', [$u['id']]);
    $ult = null;
    foreach (novedades_para(liga_trabajo(), 10) as $x) if ((int)$x['id'] > $vista) { $ult = $x; break; }
    if (!$ult) return '';
    return '<div class="aviso aviso-info banner-novedad">🆕 <span><b>Novedad:</b> ' . e($ult['titulo']) . ($n > 1 ? ' <small>y ' . ($n - 1) . ' más</small>' : '') . '</span> <a class="btn btn-chico" href="admin.php?p=novedades">Ver novedades</a></div>';
}

function marcar_novedades_vistas(): void
{
    $u = usuario();
    if ($u) q('UPDATE usuarios SET novedad_vista = (SELECT COALESCE(MAX(id), 0) FROM novedades) WHERE id = ?', [$u['id']]);
}

/* ============================================================
 * GUÍA DE PRIMEROS PASOS (Resumen)
 * ============================================================ */

/** Pasos de la puesta en marcha: [texto, hecho, enlace, ayuda]. */
function pasos_puesta_en_marcha(array $liga): array
{
    $lid = (int)$liga['id'];
    $c = fn(string $sql) => (int)valor($sql, [$lid]);
    if (es_escuela($liga)) {
        $pasos = [
            ['Logo, colores, mensualidad y día de vencimiento', $liga['logo'] !== '' && (float)$liga['esc_mensualidad'] > 0, 'admin.php?p=escuela_config', 'Parámetros de la escuela'],
            ['Sedes donde entrenan', $c('SELECT COUNT(*) FROM esc_sedes WHERE liga_id = ?') > 0, 'admin.php?p=escuela_config#sedes', ''],
            ['Profesores', $c('SELECT COUNT(*) FROM esc_profesores WHERE liga_id = ?') > 0, 'admin.php?p=profesores', ''],
            ['Grupos con su deporte, días y horario', $c('SELECT COUNT(*) FROM esc_grupos WHERE liga_id = ?') > 0, 'admin.php?p=grupos', ''],
            ['Alumnos (uno por uno, desde Excel o con la inscripción en línea)', $c("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo'") >= 3, 'admin.php?p=alumnos', ''],
            ['Tomar la primera asistencia', $c("SELECT COUNT(*) FROM esc_sesiones s JOIN esc_grupos g ON g.id = s.grupo_id WHERE g.liga_id = ? AND s.estado = 'dictada'") > 0, 'admin.php?p=asistencia', ''],
            ['Registrar el primer cobro', $c("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ? AND estado = 'pagado'") > 0, 'admin.php?p=cobros', ''],
            ['Compartir la página de la escuela con los padres', $c('SELECT COUNT(*) FROM esc_familias WHERE liga_id = ?') > 0, url_liga($liga), ''],
        ];
    } else {
        $pasos = [
            ['Logo, color y datos de la liga', $liga['logo'] !== '', 'admin.php?p=config', ''],
            ['Canchas donde se juega', $c('SELECT COUNT(*) FROM canchas WHERE liga_id = ?') > 0, 'admin.php?p=config#canchas', ''],
            ['Equipos (al menos 2)', $c('SELECT COUNT(*) FROM equipos WHERE liga_id = ?') >= 2, 'admin.php?p=equipos', ''],
            ['Jugadores (o que los inscriba cada delegado)', $c('SELECT COUNT(*) FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.activo = 1') > 0, 'admin.php?p=equipos', ''],
            ['Árbitros', $c('SELECT COUNT(*) FROM arbitros WHERE liga_id = ?') > 0, 'admin.php?p=arbitros', 'arbitros'],
            ['Campeonato con sus reglas', $c('SELECT COUNT(*) FROM torneos WHERE liga_id = ?') > 0, 'admin.php?p=torneos', ''],
            ['Sorteo y fixture', $c('SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ?') > 0, 'admin.php?p=torneos', ''],
            ['Programar la primera fecha (día, hora, cancha y árbitros)', $c("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.fecha <> '' AND p.hora <> ''") > 0, 'admin.php?p=torneos', ''],
            ['Primer partido con la vocalía en el celular', $c("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado IN ('jugado','wo')") > 0, 'admin.php?p=torneos', ''],
        ];
        $pasos = array_values(array_filter($pasos, fn($x) => $x[3] !== 'arbitros' || modulo_menu($liga, 'arbitros')));
    }
    return $pasos;
}

/** Tarjeta "Primeros pasos" con barra de avance (desaparece cuando todo está hecho). */
function html_primeros_pasos(array $liga): string
{
    $pasos = pasos_puesta_en_marcha($liga);
    $hechos = count(array_filter($pasos, fn($x) => $x[1]));
    if ($hechos >= count($pasos) || !puede('config', true)) return '';
    $pct = (int)round($hechos * 100 / max(1, count($pasos)));
    [$man] = ayuda_de_pagina('inicio', $liga);
    $sig = null;
    foreach ($pasos as $x) if (!$x[1]) { $sig = $x; break; }
    ob_start(); ?>
    <div class="tarjeta guia-pasos">
      <div class="fila-titulo" style="margin:0"><h2 style="margin:0">🚀 Primeros pasos <small class="suave"><?= $hechos ?> de <?= count($pasos) ?></small></h2>
        <a class="btn btn-sec btn-chico" href="manual/<?= e($man) ?>#s2" target="_blank">📘 Ver la guía completa</a></div>
      <div class="barra-avance" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pct ?>%"></span></div>
      <?php if ($sig): ?><p style="margin:.5rem 0">👉 <b>Siguiente paso:</b> <a href="<?= e($sig[2]) ?>"><?= e($sig[0]) ?></a></p><?php endif; ?>
      <ol class="pasos">
        <?php foreach ($pasos as [$txt, $ok, $link]): ?>
          <li style="<?= $ok ? 'color:var(--suave);text-decoration:line-through' : '' ?>"><?= $ok ? '✅' : '⬜' ?> <a href="<?= e($link) ?>"><?= e($txt) ?></a></li>
        <?php endforeach; ?>
      </ol>
      <p class="suave" style="margin:0 0 4px">Página pública: <a href="<?= e(url_liga($liga)) ?>" target="_blank"><?= e(url_liga($liga)) ?></a> · Instale el sistema en su celular: abra <a href="app.php">la aplicación</a> y elija «Instalar».</p>
      <?php if (motivo_solo_pagadas($liga, 'carga_masiva') === ''): ?><p class="suave" style="margin:0">📥 ¿Tiene sus datos en Excel? Súbalos todos de una vez en <a href="admin.php?p=cargas">Subir datos desde Excel</a>.</p><?php endif; ?>
    </div>
    <?php return (string)ob_get_clean();
}

/* ============================================================
 * SEGUIMIENTO DE DEMOS
 * ============================================================ */

/** Uso de una cuenta en demo (para saber si la está probando de verdad). */
function uso_cuenta(array $l): array
{
    $lid = (int)$l['id'];
    $ult = (string)valor('SELECT MAX(ultimo_ingreso) FROM usuarios WHERE liga_id = ?', [$lid]);
    if (($l['tipo'] ?? 'liga') === 'escuela') {
        $txt = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo'", [$lid]) . ' alumnos · ' . (int)valor('SELECT COUNT(*) FROM esc_grupos WHERE liga_id = ?', [$lid]) . ' grupos';
    } else {
        $txt = (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = ?', [$lid]) . ' equipos · ' . (int)valor('SELECT COUNT(*) FROM torneos WHERE liga_id = ?', [$lid]) . ' campeonatos';
    }
    $pasos = pasos_puesta_en_marcha($l);
    return ['ultimo' => $ult, 'texto' => $txt, 'avance' => count(array_filter($pasos, fn($x) => $x[1])) . '/' . count($pasos)];
}

/** Mensaje de WhatsApp para dar seguimiento a una demo. */
function mensaje_seguimiento_demo(array $l, int $dias): string
{
    $quien = trim((string)$l['contacto_nombre']) !== '' ? ' ' . $l['contacto_nombre'] : '';
    if ($dias > 0) {
        return 'Hola' . $quien . ', le saluda ' . ajuste('empresa') . ' de ' . marca() . '. ¿Cómo le va con la demo de ' . $l['nombre'] . '? Le quedan ' . $dias . ' día(s). '
            . 'Si quiere, le ayudo a cargar sus datos o le muestro algo en una videollamada corta. El manual está en ' . rtrim(url_base(), '/') . '/manual';
    }
    if ($dias < 0) {
        return 'Hola' . $quien . ', le saluda ' . ajuste('empresa') . ' de ' . marca() . '. Su demo de ' . $l['nombre'] . ' terminó, pero todo lo que cargó sigue guardado. '
            . 'Si elige su plan, le activamos la cuenta hoy mismo y sigue donde se quedó. ¿Le envío los datos para el pago?';
    }
    return 'Hola' . $quien . ', su demo de ' . marca() . ' para ' . $l['nombre'] . ' termina hoy. '
        . 'Para seguir usándola con todo lo que ya cargó, elija su plan y le activamos la cuenta ahora mismo. ¿Le envío los datos para el pago?';
}

/**
 * Avisos automáticos de las demos (una vez al día, desde tareas.php):
 *  - día 5 (faltan 2 días) y día 7 (vence hoy): Telegram al superadministrador con el enlace de WhatsApp listo,
 *    y correo al contacto de la cuenta si el correo saliente está configurado.
 */
function avisos_demos(): void
{
    foreach (filas("SELECT * FROM ligas WHERE es_prueba = 1 AND es_demo = 0 AND activa = 1 AND vence <> ''") as $l) {
        $s = estado_suscripcion($l);
        $etapa = $s['dias'] === 2 ? 'dia5' : ($s['dias'] === 0 ? 'dia7' : '');
        if ($etapa === '' || str_contains((string)$l['demo_avisos'], $etapa)) continue;
        q("UPDATE ligas SET demo_avisos = TRIM(demo_avisos || ' ' || ?) WHERE id = ?", [$etapa, $l['id']]);
        $uso = uso_cuenta($l);
        $wa = telefono_wa((string)$l['contacto_telefono']);
        $msg = mensaje_seguimiento_demo($l, (int)$s['dias']);
        aviso_super(($etapa === 'dia5' ? '⏳ <b>Demo en el día 5</b> (faltan 2 días)' : '🔔 <b>Demo vence HOY</b> (día 7)') . "\n" . e($l['nombre']) . ' · ' . e(($l['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga')
            . "\nUso: " . e($uso['texto']) . ' · primeros pasos ' . e($uso['avance']) . ' · último ingreso: ' . e($uso['ultimo'] ?: 'nunca')
            . ($l['contacto_nombre'] !== '' ? "\n👤 " . e($l['contacto_nombre']) : '') . ($l['contacto_telefono'] !== '' ? ' · 📞 ' . e($l['contacto_telefono']) : '')
            . ($wa ? "\n\nEscribirle por WhatsApp: https://wa.me/" . $wa . '?text=' . rawurlencode($msg) : ''), 'demo_' . $etapa . ':' . $l['id']);
        if (smtp_configurado() && filter_var((string)$l['contacto_email'], FILTER_VALIDATE_EMAIL)) {
            try {
                smtp_enviar((string)$l['contacto_email'], ($etapa === 'dia5' ? 'Le quedan 2 días de su demo de ' : 'Hoy termina su demo de ') . marca(), $msg . "\n\n" . ajuste('empresa') . (ajuste('ventas_whatsapp') !== '' ? ' · WhatsApp ' . ajuste('ventas_whatsapp') : ''));
            } catch (Throwable $ex) {
                error_log('demo correo: ' . $ex->getMessage());
            }
        }
    }
}

/* ============================================================
 * DESCARGAR TODOS MIS DATOS (Excel)
 * ============================================================ */

/** Libro de Excel con todos los datos de la liga o escuela. */
function libro_mis_datos(array $liga): string
{
    $lid = (int)$liga['id'];
    $hojas = [];
    $hoja = function (string $nombre, string $sql, array $par = []) use (&$hojas) {
        $rs = filas($sql, $par);
        if (!$rs) { $hojas[] = ['nombre' => $nombre, 'filas' => [['(sin datos)']]]; return; }
        $titulos = array_keys($rs[0]);
        $f = [$titulos];
        foreach ($rs as $r) $f[] = array_map(fn($v) => is_numeric($v) && !preg_match('/^0\d/', (string)$v) && strlen((string)$v) < 10 ? $v + 0 : (string)$v, array_values($r));
        $hojas[] = ['nombre' => mb_substr($nombre, 0, 31), 'filas' => $f, 'anchos' => array_fill(0, count($titulos), 16)];
    };
    $hoja('Cuenta', 'SELECT nombre, ciudad, tipo, contacto_nombre, contacto_telefono, contacto_email, ruc, vence, creado FROM ligas WHERE id = ?', [$lid]);
    if (es_escuela($liga)) {
        $hoja('Sedes', 'SELECT id, nombre, direccion FROM esc_sedes WHERE liga_id = ?', [$lid]);
        $hoja('Profesores', 'SELECT id, nombre, cedula, telefono, email, especialidad, cargo, tipo_pago, valor_pago, activo FROM esc_profesores WHERE liga_id = ?', [$lid]);
        $hoja('Grupos', 'SELECT g.id, g.nombre, g.deporte, g.categoria, g.anio_desde, g.anio_hasta, s.nombre AS sede, p.nombre AS profesor, g.dias, g.hora_inicio, g.hora_fin, g.cancha, g.cupo, g.mensualidad, g.activo FROM esc_grupos g LEFT JOIN esc_sedes s ON s.id = g.sede_id LEFT JOIN esc_profesores p ON p.id = g.profesor_id WHERE g.liga_id = ?', [$lid]);
        $hoja('Alumnos', 'SELECT a.id, a.nombres, a.apellidos, a.cedula, a.fecha_nac, a.genero, g.nombre AS grupo, a.representante_nombre, a.representante_cedula, a.representante_telefono, a.representante_email, a.parentesco, a.direccion, a.colegio, a.tipo_sangre, a.alergias, a.condiciones, a.medicamentos, a.emergencia_nombre, a.emergencia_telefono, a.beca, a.mensualidad_especial, a.fecha_ingreso, a.estado FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ?', [$lid]);
        $hoja('Cobros', 'SELECT c.id, a.nombres || \' \' || a.apellidos AS alumno, c.tipo, c.descripcion, c.monto, c.vence, c.estado, c.pagado_en FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ?', [$lid]);
        $hoja('Asistencia', 'SELECT s.fecha, g.nombre AS grupo, a.nombres || \' \' || a.apellidos AS alumno, x.estado FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id JOIN esc_alumnos a ON a.id = x.alumno_id WHERE g.liga_id = ? ORDER BY s.fecha DESC LIMIT 20000', [$lid]);
    } else {
        $hoja('Equipos', 'SELECT id, nombre, abreviatura, delegado, telefono, fundado, creado FROM equipos WHERE liga_id = ?', [$lid]);
        $hoja('Jugadores', 'SELECT j.id, e.nombre AS equipo, j.nombres, j.apellidos, j.cedula, j.numero, j.posicion, j.fecha_nac, j.representante_nombre, j.representante_cedula, j.representante_telefono, j.activo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ?', [$lid]);
        $hoja('Campeonatos', 'SELECT id, nombre, temporada, modalidad, formato, estado FROM torneos WHERE liga_id = ?', [$lid]);
        $hoja('Partidos', 'SELECT p.id, t.nombre AS campeonato, p.fase, p.jornada, p.fecha, p.hora, p.cancha, l.nombre AS local, v.nombre AS visitante, p.goles_local, p.goles_visitante, p.estado FROM partidos p JOIN torneos t ON t.id = p.torneo_id LEFT JOIN equipos l ON l.id = p.local_id LEFT JOIN equipos v ON v.id = p.visitante_id WHERE t.liga_id = ? ORDER BY p.fecha, p.hora', [$lid]);
        $hoja('Goles y tarjetas', 'SELECT ev.partido_id, ev.tipo, ev.minuto, j.nombres || \' \' || j.apellidos AS jugador, e.nombre AS equipo FROM eventos ev JOIN partidos p ON p.id = ev.partido_id JOIN torneos t ON t.id = p.torneo_id LEFT JOIN jugadores j ON j.id = ev.jugador_id LEFT JOIN equipos e ON e.id = j.equipo_id WHERE t.liga_id = ?', [$lid]);
        $hoja('Competidores individuales', "SELECT t.nombre AS campeonato, 'Carrera' AS tipo, c.numero, c.nombre, '' AS pareja, c.club, c.ciudad, '' AS rama, '' AS nacimiento, c.vehiculo AS detalle FROM carrera_participantes c JOIN torneos t ON t.id = c.torneo_id WHERE t.liga_id = ?
            UNION ALL SELECT t.nombre, 'Duelo', '', d.nombre, d.pareja, d.club, d.ciudad, d.rama, d.nacimiento, TRIM(d.peso || ' ' || d.grado) FROM duelo_participantes d JOIN torneos t ON t.id = d.torneo_id WHERE t.liga_id = ?
            UNION ALL SELECT t.nombre, 'Natación / atletismo', p.numero, p.nombre, '', p.club, p.ciudad, p.rama, p.nacimiento, '' FROM prueba_participantes p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ?", [$lid, $lid, $lid]);
        $hoja('Árbitros', 'SELECT id, nombre, cedula, telefono, categoria, activo FROM arbitros WHERE liga_id = ?', [$lid]);
        $hoja('Finanzas', 'SELECT m.id, m.fecha, m.tipo, m.concepto, m.monto, e.nombre AS equipo, m.recibo, m.forma_pago, m.anulado FROM movimientos m LEFT JOIN equipos e ON e.id = m.equipo_id WHERE m.liga_id = ? ORDER BY m.fecha', [$lid]);
        $hoja('Canchas', 'SELECT id, nombre, direccion, reservable, precio_hora FROM canchas WHERE liga_id = ?', [$lid]);
    }
    return crear_xlsx_libro($hojas);
}
