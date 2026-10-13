<?php
/*
 * VERSIÓN 12 — Funciones nuevas:
 *   1) Planes por MÓDULOS: cada plan dice qué módulos trae, cuántos torneos, equipos por torneo y alumnos,
 *      y tiene precio por mes, trimestre, semestre y año. El sistema oculta y bloquea lo que el plan no incluye.
 *   2) Cobros con TARJETA (Plux) en los dos sentidos:
 *        - la liga o escuela paga su suscripción a TuVocalía;
 *        - los equipos (delegados) y las familias pagan a su liga o escuela, con TuVocalía de intermediario
 *          (comisión de la tarjeta + IVA + gestión administrativa de TuVocalía), con liquidaciones a cada organización.
 *   3) Rol de pago de los profesores (ingresos, bonos, IESS, anticipos, otros descuentos y neto).
 */

/* ============================================================
 * 1) MÓDULOS Y PLANES
 * ============================================================ */

/**
 * Módulos que se pueden activar por plan: clave => [ícono, nombre, para ('liga'|'escuela'|'ambos'), qué incluye].
 * (v16.1) Con $tipo = 'liga' o 'escuela' devuelve solo los de ese tipo, y para escuelas con nombres y textos de escuela.
 */
function modulos_sistema(string $tipo = ''): array
{
    $todos = modulos_sistema_base();
    if ($tipo !== 'liga' && $tipo !== 'escuela') return $todos;
    $r = array_filter($todos, fn($m) => $m[2] === 'ambos' || $m[2] === $tipo);
    if ($tipo === 'escuela') {
        $esc = [
            'carnets' => ['Carnets con QR del alumno', 'Carnet del alumno con foto y código QR, carnet digital en el celular de los padres e imagen para redes.'],
            'avisos' => ['Avisos por Telegram', 'Mensualidades por vencer, pagos, asistencia y novedades llegan solos al celular de los padres, profesores y directivos.'],
            'pagos_tarjeta' => ['Cobros con tarjeta', 'Los padres pagan las mensualidades con tarjeta de crédito o débito desde su celular; el recibo sale solo y usted ve cada pago en su reporte.'],
            'dominio' => ['Dominio propio', 'La página de su escuela con su propia dirección (www.suescuela.com) en vez de tuvocalia.com/su-escuela.'],
        ];
        foreach ($esc as $k => [$n, $d]) if (isset($r[$k])) { $r[$k][1] = $n; $r[$k][3] = $d; }
    }
    return $r;
}

function modulos_sistema_base(): array
{
    return [
        'vocalia' => ['📱', 'Vocalía en vivo', 'liga', 'El vocal registra goles, tarjetas, cambios y reloj desde el celular (funciona aunque se caiga el internet). El público ve el marcador en vivo, se escanea el carnet QR y sale la hoja de vocalía en PDF.'],
        'carnets' => ['🪪', 'Carnets con QR y portal del jugador', 'ambos', 'Carnet con foto y código QR para verificar identidad en la cancha, carnet digital en el celular del jugador o alumno y cromo para redes.'],
        'inscripcion' => ['📝', 'Inscripción en línea y portal del delegado', 'liga', 'Los equipos se inscriben por internet; cada delegado inscribe a sus jugadores, pide pases, reprogramaciones y apelaciones y ve su estado de cuenta.'],
        'finanzas' => ['💵', 'Finanzas y recibos', 'liga', 'Inscripciones, arbitrajes y multas automáticas, pagos con recibo, comprobantes de transferencia, egresos y estado de cuenta por equipo.'],
        'arbitros' => ['🧑‍⚖️', 'Árbitros', 'liga', 'Designación, informe del árbitro comparado con la vocalía, calificación, portal del árbitro y liquidación de honorarios.'],
        'redes' => ['🖼️', 'Imágenes para redes', 'liga', 'Resultados, tabla, goleadores, programación y figura de la fecha en imágenes listas para Facebook e Instagram, con su patrocinador.'],
        // (v29) Exclusivo del plan Profesional: no está en Básico, Torneo, la demo ni la demo de 7 días
        'fixture_pro' => ['🗺️', 'Fixture imprimible y esquema del campeonato', 'liga', 'El fixture completo de cada campeonato en PDF (con resultados o en blanco para llenar a mano) y el esquema de todo el torneo desde el primer día: grupos, jornadas y la llave de la fase final con los cruces (1° Grupo A vs 2° Grupo B), que se llenan solos con los clasificados.'],
        'publico' => ['🤝', 'Patrocinadores y público', 'liga', 'Banners de patrocinadores con reporte de vistas, votación del público, polla de pronósticos y widget para su página web.'],
        'reservas' => ['🏟', 'Reserva de canchas', 'liga', 'El público reserva sus canchas por horas desde el celular y el cobro entra a finanzas.'],
        'avisos' => ['📲', 'Avisos por Telegram', 'ambos', 'Programación, resultados, suspensiones, pagos y vencimientos llegan solos al celular de delegados, árbitros, profesores y directivos.'],
        'pagos_tarjeta' => ['💳', 'Cobros con tarjeta', 'ambos', 'Sus equipos y familias le pagan con tarjeta de crédito o débito desde su celular; el sistema aplica el pago, emite el recibo y usted ve todo en su reporte de pagos.'],
        'dominio' => ['🌐', 'Dominio propio', 'ambos', 'Su página con su propia dirección (www.suliga.com) en vez de tuvocalia.com/su-liga.'],
        'esc_familias' => ['👨‍👩‍👦', 'Portal de padres e inscripción en línea', 'escuela', 'Los padres inscriben a sus hijos por internet y ven asistencia, pagos, recibos, evaluaciones y libreta desde el celular.'],
        'esc_profesores' => ['🧑‍🏫', 'Profesores y rol de pago', 'escuela', 'Grupos, días que dictaron clase, pago mensual, por clase o por alumno, liquidación y rol de pago que cada profesor ve en su celular.'],
        'esc_entrenamientos' => ['📅', 'Planificación de entrenamientos', 'escuela', 'El profesor planifica las clases por adelantado con la biblioteca de ejercicios de la escuela; cada clase guarda ejercicios, minutos, intensidad, fotos y una nota para cada alumno, y los padres ven el diario de entrenamientos de su hijo.'],
        'esc_evaluaciones' => ['📏', 'Evaluaciones, desarrollo y libreta', 'escuela', 'Jornadas de evaluación desde el celular, medidas, tests con semáforo por edad, ranking y récords, evaluación técnica, metas, insignias, gráficos de desarrollo del alumno e informe trimestral en PDF.'],
        'esc_formativas' => ['🏆', 'Divisiones formativas (clubes)', 'escuela', 'Para clubes y academias: partidos con convocatoria y minutos jugados, notas del cuerpo técnico por área, valoración del jugador, seguimiento escolar, maduración y pico de crecimiento, y lesiones con fases de regreso. Se activa en Parámetros.'],
        'esc_tienda' => ['🛍', 'Tienda e inventario', 'escuela', 'Uniformes, balones y más: ventas con recibo, cargo a la cuenta del alumno y control de stock.'],
        // (v18)
        // (v27) Solo en cuentas pagadas de los planes completos (no en la demo ni en la prueba gratis)
        // (v28)
        'entradas' => ['🎟', 'Venta de entradas con QR', 'liga', 'Venda las entradas de sus partidos por internet (tarjeta o transferencia), en taquilla y cortesías, con parqueadero opcional. Cada entrada tiene su QR y en la puerta se valida con el celular, aunque no haya internet.'],
        'videos' => ['🎬', 'Videos del jugador y del alumno', 'ambos', 'Suba videos cortos (hasta 2 minutos) de cada jugador o alumno: su gol, una jugada, su técnica. Le llegan a su portal para verlos y descargarlos; se borran solos a los 10 días.'],
        'carga_masiva' => ['📥', 'Subida masiva desde Excel', 'ambos', 'Plantillas de Excel para subir de una sola vez equipos, jugadores, árbitros, canchas y competidores (ligas) o sedes, profesores, grupos y alumnos (escuelas).'],
        'contabilidad' => ['📒', 'Contabilidad con importador del SRI', 'ambos', 'Gastos con o sin factura, facturas recibidas importadas del SRI (XML, TXT o clave de acceso) que usted elige incluir, resumen de ingresos y gastos por mes y por campeonato, y reportes en PDF y Excel para su contador.'],
    ];
}

/** Lo que TODOS los planes traen siempre (no es un módulo que se pueda quitar). */
function funciones_base(string $tipo = 'liga'): array
{
    if ($tipo === 'escuela') {
        return ['Alumnos con foto, ficha médica y representante', 'Categorías Sub-N automáticas o abiertas', 'Grupos, horarios y sedes', 'Asistencia y plan de clase',
            'Mensualidades con fecha de vencimiento, becas y descuento por hermanos', 'Recibos y semáforo de pagos', 'Página pública de la escuela', 'Usuarios con perfiles y bitácora de auditoría'];
    }
    return ['Equipos y jugadores con foto', 'Sorteo y fixture automático (todos contra todos, grupos, ida y vuelta)', 'Llaves eliminatorias automáticas', 'Tabla de posiciones, goleadores y tarjetas',
        'Suspensiones automáticas y sanciones', 'Programación de fechas, horas y canchas', 'Página pública de la liga y de cada campeonato', 'Boletín oficial de la fecha y tabla acumulada',
        'Fútbol 5 al 11, indor, ecuavóley, vóley y básquet', 'Usuarios con perfiles y bitácora de auditoría'];
}

/** Módulo al que pertenece cada página del panel ('' = no depende de ningún módulo). */
function modulo_de_pagina(string $p): string
{
    return [
        'arbitros' => 'arbitros', 'liquidacion' => 'arbitros', 'finanzas' => 'finanzas', 'recibo' => 'finanzas',
        'carnets' => 'carnets', 'esc_carnets' => 'carnets', 'inscripciones' => 'inscripcion', 'redes' => 'redes',
        'patrocinadores' => 'publico', 'votaciones' => 'publico', 'polla' => 'publico', 'widgets' => 'publico', 'reservas' => 'reservas',
        'pagos_linea' => 'pagos_tarjeta', 'profesores' => 'esc_profesores', 'evaluaciones' => 'esc_evaluaciones', 'tienda' => 'esc_tienda',
        'planificacion' => 'esc_entrenamientos', 'formativas' => 'esc_formativas', 'contabilidad' => 'contabilidad',
        'cargas' => 'carga_masiva', 'importar' => 'carga_masiva', // (v27)
        'videos' => 'videos', 'entradas' => 'entradas', // (v28)
    ][$p] ?? '';
}

function nombre_modulo(string $m): string
{
    return modulos_sistema()[$m][1] ?? $m;
}

/** Módulos de un plan (lista de claves). */
function modulos_plan(?array $pl): array
{
    if (!$pl) return array_keys(modulos_sistema());
    $m = trim((string)($pl['modulos'] ?? '*'));
    if ($m === '*' || $m === '') return array_keys(modulos_sistema());
    return array_values(array_intersect(array_keys(modulos_sistema()), array_map('trim', explode(',', $m))));
}

/** Módulos que tiene una liga o escuela: los de su plan + los que le regaló el superadministrador. En prueba gratis, la demo o sin plan: todos. */
function modulos_liga(?array $liga): array
{
    static $cache = [];
    if (!$liga) return array_keys(modulos_sistema());
    $k = $liga['id'] . ':' . ($liga['plan_id'] ?? '') . ':' . ($liga['modulos_extra'] ?? '') . ':' . ($liga['vence'] ?? '') . ':' . ($liga['es_prueba'] ?? '');
    if (isset($cache[$k])) return $cache[$k];
    if (!empty($liga['es_demo']) || empty($liga['plan_id']) || estado_suscripcion($liga)['estado'] === 'prueba') return $cache[$k] = array_keys(modulos_sistema());
    $extra = array_filter(array_map('trim', explode(',', (string)($liga['modulos_extra'] ?? ''))));
    return $cache[$k] = array_values(array_unique(array_merge(modulos_plan(plan_de_liga($liga)), array_intersect($extra, array_keys(modulos_sistema())))));
}

function modulo_activo(?array $liga, string $m): bool
{
    if ($m === '') return true;
    return in_array($m, modulos_liga($liga), true);
}

/** Para el menú del panel: el superadministrador ve todo. */
function modulo_menu(?array $liga, string $m): bool
{
    return es_super() || modulo_activo($liga, $m);
}

/** Portales (vocal, árbitro, delegado, jugador, familia): si el plan no incluye el módulo, muestra un aviso y termina. */
function exigir_modulo_portal(?array $liga, string $m): void
{
    if (!$liga || modulo_activo($liga, $m)) return;
    http_response_code(403);
    cabecera_publica('No disponible', $liga);
    echo '<div class="tarjeta login"><h1>No disponible</h1><p>El plan de <b>' . e($liga['nombre']) . '</b> no incluye <b>' . e(nombre_modulo($m)) . '</b>. Comuníquese con la liga o escuela.</p></div>';
    pie_publico();
    exit;
}

/** Períodos de pago: clave => [meses, nombre, "por mes"...]. */
function periodos_plan(): array
{
    return ['mensual' => [1, 'Mensual', 'al mes'], 'trimestral' => [3, 'Trimestral', 'por 3 meses'], 'semestral' => [6, 'Semestral', 'por 6 meses'], 'anual' => [12, 'Anual', 'al año']];
}

/**
 * Precios que ofrece un plan: lista de ['periodo', 'meses', 'precio', 'por_mes', 'gratis' (meses regalados frente al mensual),
 * 'dominio' (v16: el pago anual trae dominio gratis), 'combo' y 'precio_normal' (v16: con $liga en combo, 30% menos)].
 */
function opciones_precio(array $pl, ?array $liga = null, ?array $deportes = null): array
{
    if (function_exists('en_app_play') && en_app_play()) return opciones_precio_play($pl); // (v38) en la app de Google Play, precios de Google
    $r = [];
    $pm = (float)$pl['precio_mensual'];
    $f = $liga ? factor_combo($liga) : 1.0;
    // (v35) módulos deportivos adicionales: los de la cuenta (o los elegidos al contratar), con la misma rebaja del período
    $ds = $deportes ?? ($liga && empty($liga['es_demo']) ? deportes_cobrados($liga) : []);
    $extra_mes = $ds && function_exists('precio_modulos') ? precio_modulos($ds)['extra'] : 0.0;
    foreach (periodos_plan() as $k => [$meses, $nom, $txt]) {
        $precio = (float)($pl['precio_' . $k] ?? 0);
        if ($precio <= 0) continue;
        $gratis = $pm > 0 && $meses > 1 ? (int)round($meses - $precio / $pm) : 0;
        $rebaja = $pm > 0 ? $precio / ($pm * $meses) : 1.0;
        $extra = round($extra_mes * $meses * $rebaja, 2);
        $final = round(($precio + $extra) * $f, 2);
        $r[$k] = ['periodo' => $k, 'nombre' => $nom, 'texto' => $txt, 'meses' => $meses, 'precio' => $final, 'por_mes' => round($final / $meses, 2), 'gratis' => max(0, $gratis),
                  'dominio' => $meses >= 12 && (int)($pl['dominio_anual'] ?? 0) === 1, 'combo' => $f < 1, 'precio_normal' => $precio + $extra, 'extra_modulos' => $extra,
                  'base' => $precio, 'rebaja' => $rebaja, 'factor' => $f];
    }
    return $r;
}

/** Precio del plan para N meses (usa el período exacto si existe; si no, el mensual × meses). Con $liga aplica el combo. */
function precio_plan(array $pl, int $meses, ?array $liga = null): float
{
    foreach (opciones_precio($pl, $liga) as $o) if ($o['meses'] === $meses) return $o['precio'];
    return round((float)$pl['precio_mensual'] * $meses * ($liga ? factor_combo($liga) : 1), 2);
}

/* ---------- (v16) COMBO LIGA + ESCUELA: la misma persona con una liga y una escuela paga 30% menos en los dos planes ---------- */

function combo_descuento(): float
{
    return max(0, min(90, (float)str_replace(',', '.', (string)ajuste('combo_descuento', 30))));
}

/** La otra cuenta del combo (liga ↔ escuela), si el enlace es mutuo y la otra cuenta está activa. */
function combo_de(array $liga): ?array
{
    $cid = (int)($liga['combo_id'] ?? 0);
    if (!$cid || $cid === (int)$liga['id']) return null;
    $o = fila('SELECT * FROM ligas WHERE id = ? AND combo_id = ? AND activa = 1', [$cid, $liga['id']]);
    return $o && (($o['tipo'] ?? 'liga') === 'escuela') !== (($liga['tipo'] ?? 'liga') === 'escuela') ? $o : null;
}

function factor_combo(array $liga): float
{
    return combo_de($liga) ? round(1 - combo_descuento() / 100, 4) : 1.0;
}

/** Enlaza (o desenlaza con 0) una liga con una escuela como combo. */
function enlazar_combo(int $liga_id, int $otra_id): void
{
    $l = fila('SELECT * FROM ligas WHERE id = ?', [$liga_id]) ?? throw new RuntimeException('Cuenta no encontrada.');
    // quita enlaces anteriores de las dos cuentas
    q('UPDATE ligas SET combo_id = NULL WHERE combo_id IN (?, ?) OR id IN (?, ?)', [$liga_id, $otra_id ?: -1, $liga_id, $otra_id ?: -1]);
    if (!$otra_id) return;
    $o = fila('SELECT * FROM ligas WHERE id = ?', [$otra_id]) ?? throw new RuntimeException('La cuenta del combo no existe.');
    if ((($o['tipo'] ?? 'liga') === 'escuela') === (($l['tipo'] ?? 'liga') === 'escuela')) throw new RuntimeException('El combo es de una LIGA con una ESCUELA.');
    q('UPDATE ligas SET combo_id = ? WHERE id = ?', [$otra_id, $liga_id]);
    q('UPDATE ligas SET combo_id = ? WHERE id = ?', [$liga_id, $otra_id]);
}

/** Texto corto del combo para la página de venta. */
function texto_combo(): string
{
    $p = rtrim(rtrim(number_format(combo_descuento(), 2, ',', ''), '0'), ',');
    return 'Combo liga + escuela: ' . $p . '% de descuento en los dos planes';
}

/** (v16) Nota del dominio gratis (con asterisco). */
function nota_dominio(): string
{
    return trim((string)ajuste('nota_dominio'));
}

/** Precio principal para mostrar: "$10 al mes" o "$10 por 3 meses". */
function texto_precio_principal(array $pl): string
{
    $o = opciones_precio($pl);
    if (!$o) return 'Consultar';
    $p = reset($o);
    return dinero($p['precio']) . ' ' . $p['texto'];
}

/** Límites del plan en palabras. */
function textos_limites_plan(array $pl, string $para = ''): array
{
    $t = [];
    $para = $para !== '' ? $para : ($pl['tipo_cuenta'] ?? 'ambos');
    if ($para === 'escuela') {
        $t[] = (int)($pl['max_alumnos'] ?? 0) ? 'Hasta ' . (int)$pl['max_alumnos'] . ' alumnos' : 'Alumnos ilimitados';
        $o = opciones_precio($pl);
        if (count($o) === 1) $t[] = 'Duración: ' . reset($o)['meses'] . ' meses';
        if ((int)($pl['dominio_anual'] ?? 0)) $t[] = '🌐 Dominio propio gratis pagando 1 año*';
        return $t;
    }
    $mc = (int)$pl['max_campeonatos'];
    $t[] = $mc ? $mc . ' torneo' . ($mc === 1 ? '' : 's') . ' a la vez' : 'Torneos ilimitados';
    $met = (int)($pl['max_equipos_torneo'] ?? 0);
    $me = (int)$pl['max_equipos'];
    // (v16) una sola línea de equipos: si todo es ilimitado no se repite "hasta N equipos"
    if (!$met && !$me) $t[] = 'Equipos ilimitados';
    elseif ($met && (!$me || $me === $met)) $t[] = 'Hasta ' . $met . ' equipos' . ($mc === 1 ? '' : ' por torneo');
    else { $t[] = $met ? 'Hasta ' . $met . ' equipos por torneo' : 'Equipos por torneo sin límite'; if ($me) $t[] = 'Hasta ' . $me . ' equipos en la liga'; }
    if ($para === 'ambos') $t[] = (int)($pl['max_alumnos'] ?? 0) ? 'Hasta ' . (int)$pl['max_alumnos'] . ' alumnos (escuelas)' : 'Alumnos ilimitados (escuelas)';
    $o = opciones_precio($pl);
    if (count($o) === 1) $t[] = 'Duración: ' . reset($o)['meses'] . ' mes' . (reset($o)['meses'] === 1 ? '' : 'es');
    if ((int)($pl['dominio_anual'] ?? 0)) $t[] = '🌐 Dominio propio gratis pagando 1 año*';
    return $t;
}

function url_plan(array $pl): string
{
    return url_base() . 'planes/' . rawurlencode($pl['slug'] !== '' ? $pl['slug'] : (string)$pl['id']);
}

function plan_por_slug(string $s): ?array
{
    return fila('SELECT * FROM planes WHERE (slug = ? OR id = ?) AND activo = 1', [$s, ctype_digit($s) ? (int)$s : 0]);
}

/** (v12) Revisa el máximo de equipos POR TORNEO del plan antes de inscribir. */
function verificar_equipos_torneo(array $liga, int $tid, int $total_despues): void
{
    if (es_super() || !empty($liga['es_demo'])) return;
    $pl = plan_de_liga($liga);
    $max = $pl ? (int)($pl['max_equipos_torneo'] ?? 0) : 0;
    if ($max > 0 && $total_despues > $max) {
        throw new RuntimeException('Su plan ' . $pl['nombre'] . ' permite hasta ' . $max . ' equipos por torneo (quedarían ' . $total_despues . '). Para más equipos cambie de plan en "Mi plan".');
    }
}

/** (v12) Revisa el máximo de alumnos del plan (escuelas). */
function verificar_alumnos_plan(array $liga): void
{
    if (es_super() || !empty($liga['es_demo'])) return;
    $pl = plan_de_liga($liga);
    $max = $pl ? (int)($pl['max_alumnos'] ?? 0) : 0;
    if ($max > 0 && (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado IN ('activo','pendiente')", [$liga['id']]) >= $max) {
        throw new RuntimeException('Su plan ' . $pl['nombre'] . ' permite hasta ' . $max . ' alumnos. Para más alumnos cambie de plan en "Mi plan".');
    }
}

/**
 * Registra el pago de la suscripción de una liga o escuela (lo usan Ventas y el pago con tarjeta).
 * Suma la vigencia desde el vencimiento actual (si aún no vence) o desde hoy. Devuelve ['hasta', 'pago_id', 'comision_id'].
 */
function registrar_pago_suscripcion(array $l, ?int $plan, int $meses, float $monto, string $forma, string $nota, string $fecha, string $por, ?string $hasta_fijo = null): array
{
    $base = ($l['vence'] !== '' && $l['vence'] >= date('Y-m-d') && !$l['es_prueba']) ? $l['vence'] : date('Y-m-d');
    $hasta = date('Y-m-d', strtotime($base . ' +' . $meses . ' months'));
    $desde = date('Y-m-d', strtotime($base . ($base === date('Y-m-d') ? '' : ' +1 day')));
    if ($hasta_fijo !== null) { // (v38) Google Play: la fecha la pone Google
        $hasta = $hasta_fijo;
        $desde = date('Y-m-d');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO pagos_suscripcion (liga_id, plan_id, monto, meses, desde, hasta, forma_pago, nota, fecha, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)', [
            $l['id'], $plan, round($monto, 2), $meses, $desde, $hasta, array_key_exists($forma, formas_pago()) || $forma === 'google_play' ? $forma : 'transferencia', mb_substr($nota, 0, 200), $fecha, $por,
        ]);
        $pago_id = (int)$pdo->lastInsertId();
        q('UPDATE ligas SET vence = ?, es_prueba = 0, plan_id = ? WHERE id = ?', [$hasta, $plan, $l['id']]);
        $com_id = generar_comision($pago_id);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if (function_exists('premiar_referido')) premiar_referido($l); // (v28) quien la recomendó gana meses gratis
    foreach (tg_chats_admins((int)$l['id']) as $c) tg_encolar((int)$l['id'], $c, '✅ <b>Gracias por su pago</b>' . "\nSu suscripción de " . e(marca()) . ' para ' . e($l['nombre']) . ' está activa hasta el <b>' . $hasta . '</b>.');
    // (v16) Pago de 1 año en un plan con dominio gratis: aviso al superadministrador para registrarle su dominio
    $pl = $plan ? fila('SELECT * FROM planes WHERE id = ?', [$plan]) : null;
    if ($pl && $meses >= 12 && (int)($pl['dominio_anual'] ?? 0) === 1) {
        bitacora_evento('ventas', 'Pagó 1 año del plan ' . $pl['nombre'] . ': tiene derecho a DOMINIO GRATIS (' . $l['nombre'] . ')', (int)$l['id'], $pago_id);
        foreach (array_column(filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''"), 'tg_chat') as $c) {
            tg_encolar(null, $c, '🌐 <b>Dominio gratis</b>' . "\n" . e($l['nombre']) . ' pagó 1 año del plan ' . e($pl['nombre']) . '. Regístrele su dominio y conéctelo (Ligas → Editar → Dominio propio).');
        }
    }
    return ['hasta' => $hasta, 'pago_id' => $pago_id, 'comision_id' => $com_id];
}

/* ============================================================
 * 2) COBROS CON TARJETA (Plux) — TuVocalía de intermediario
 * ============================================================ */

function pasarela_modo(): string
{
    $m = (string)ajuste('pasarela_modo', 'apagado');
    return in_array($m, ['apagado', 'prueba', 'plux'], true) ? $m : 'apagado';
}

/** ¿Se puede pagar con tarjeta ahora? (en modo prueba solo el superadministrador completa pagos) */
function pasarela_activa(): bool
{
    return pasarela_modo() !== 'apagado';
}

/** Porcentajes vigentes (de Ajustes). */
function tasas_pasarela(): array
{
    $n = fn(string $k, float $d) => max(0, (float)str_replace(',', '.', (string)ajuste($k, (string)$d)));
    return ['credito' => $n('comision_credito', 4.02), 'debito' => $n('comision_debito', 2.00), 'iva' => $n('iva_pct', 15), 'tuvocalia' => $n('comision_tuvocalia', 1.00)];
}

/** Texto para explicar el costo: "Crédito 4,02% + IVA · Débito 2% + IVA · Gestión TuVocalía 1%". */
function texto_tasas(): string
{
    $t = tasas_pasarela();
    $f = fn(float $x) => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');
    return 'Tarjeta de crédito ' . $f($t['credito']) . '% + IVA · Tarjeta de débito ' . $f($t['debito']) . '% + IVA · Gestión administrativa de ' . marca() . ' ' . $f($t['tuvocalia']) . '%';
}

/** ¿La organización cobra con tarjeta a sus equipos o familias? */
function org_cobra_con_tarjeta(?array $liga): bool
{
    return $liga && pasarela_activa() && (int)($liga['pagos_tarjeta'] ?? 0) === 1 && modulo_activo($liga, 'pagos_tarjeta');
}

/**
 * Cuánto se cobra a la tarjeta. Si la comisión la paga el cliente, se le suma lo necesario para que la organización reciba
 * completo el valor con la tarifa más alta (crédito). Para la suscripción (destino TuVocalía) no hay gestión administrativa.
 */
function calcular_cobro(float $base, string $quien, string $destino = 'organizacion'): array
{
    $t = tasas_pasarela();
    $r = $t['credito'] * (1 + $t['iva'] / 100) / 100 + ($destino === 'organizacion' ? $t['tuvocalia'] / 100 : 0);
    $base = round($base, 2);
    $total = $quien === 'cliente' && $r < 0.5 ? round($base / (1 - $r), 2) : $base;
    return ['base' => $base, 'recargo' => round($total - $base, 2), 'total' => $total];
}

/** Comisiones reales cuando ya se sabe el tipo de tarjeta. */
function comisiones_pago(float $total, string $tipo_tarjeta, string $destino): array
{
    $t = tasas_pasarela();
    $pct = $tipo_tarjeta === 'debito' ? $t['debito'] : $t['credito'];
    $pas = round($total * $pct * (1 + $t['iva'] / 100) / 100, 2);
    $tuv = $destino === 'organizacion' ? round($total * $t['tuvocalia'] / 100, 2) : 0.0;
    return ['pasarela' => $pas, 'tuvocalia' => $tuv, 'neto' => round($total - $pas - $tuv, 2)];
}

/** Crea el pago pendiente y devuelve su código (token) para pagar.php. */
function crear_pago_linea(array $liga, array $d): string
{
    $quien = $d['destino'] === 'tuvocalia' ? 'organizacion' : (($liga['pagos_comision'] ?? '') === 'cliente' ? 'cliente' : 'organizacion');
    $c = calcular_cobro((float)$d['monto_base'], $quien, $d['destino']);
    if ($c['base'] < 1) throw new RuntimeException('El valor a pagar debe ser de al menos $1.');
    if ($c['total'] > 5000) throw new RuntimeException('El valor es muy alto para un pago con tarjeta. Comuníquese con la organización.');
    $token = bin2hex(random_bytes(16));
    q('INSERT INTO pagos_linea (token, liga_id, destino, concepto, detalle, datos, equipo_id, plan_id, meses, pagador_nombre, pagador_email, pagador_cedula, pagador_telefono,
                                monto_base, recargo, total, quien_comision, estado, pasarela, ip, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, \'pendiente\', ?,?,?)', [
        $token, $liga['id'], $d['destino'], $d['concepto'], mb_substr($d['detalle'], 0, 300), json_encode($d['datos'] ?? [], JSON_UNESCAPED_UNICODE),
        $d['equipo_id'] ?? null, $d['plan_id'] ?? null, (int)($d['meses'] ?? 0),
        mb_substr($d['pagador_nombre'] ?? '', 0, 100), mb_substr($d['pagador_email'] ?? '', 0, 120), mb_substr($d['pagador_cedula'] ?? '', 0, 20), mb_substr($d['pagador_telefono'] ?? '', 0, 30),
        $c['base'], $c['recargo'], $c['total'], $quien, pasarela_modo(), ip_cliente(), date('Y-m-d H:i:s'),
    ]);
    return $token;
}

function url_pago(string $token): string
{
    return 'pagar.php?t=' . $token;
}

/** Marca un pago como aprobado, calcula comisiones y lo aplica a la deuda. Se puede llamar dos veces sin duplicar. */
function confirmar_pago_linea(array $pg, string $tipo_tarjeta, string $pasarela_id, string $respuesta): array
{
    $pdo = db();
    $pdo->beginTransaction();
    $pg = fila('SELECT * FROM pagos_linea WHERE id = ?', [$pg['id']]);
    if ($pg['estado'] === 'pagado') { $pdo->commit(); return $pg; }
    $com = comisiones_pago((float)$pg['total'], $tipo_tarjeta, $pg['destino']);
    q("UPDATE pagos_linea SET estado = 'pagado', tipo_tarjeta = ?, pasarela_id = ?, respuesta = ?, comision_pasarela = ?, comision_tuvocalia = ?, neto = ?, pagado_en = ? WHERE id = ?",
        [$tipo_tarjeta === 'debito' ? 'debito' : 'credito', mb_substr($pasarela_id, 0, 100), mb_substr($respuesta, 0, 2000), $com['pasarela'], $com['tuvocalia'], $com['neto'], date('Y-m-d H:i:s'), $pg['id']]);
    $pdo->commit();
    aplicar_pago_linea((int)$pg['id']);
    return fila('SELECT * FROM pagos_linea WHERE id = ?', [$pg['id']]);
}

function rechazar_pago_linea(array $pg, string $respuesta): void
{
    q("UPDATE pagos_linea SET estado = 'rechazado', respuesta = ? WHERE id = ? AND estado = 'pendiente'", [mb_substr($respuesta, 0, 2000), $pg['id']]);
}

/** Aplica el pago aprobado: renueva la suscripción, abona al estado de cuenta del equipo o cobra las mensualidades del alumno. */
function aplicar_pago_linea(int $id): void
{
    $pg = fila('SELECT * FROM pagos_linea WHERE id = ?', [$id]);
    if (!$pg || $pg['estado'] !== 'pagado' || $pg['aplicado'] !== '') return;
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$pg['liga_id']]);
    $datos = json_decode($pg['datos'], true) ?: [];
    $ref = 'Pago en línea N° ' . $pg['id'] . ($pg['pasarela_id'] !== '' ? ' · ' . $pg['pasarela_id'] : '');
    $fecha = substr($pg['pagado_en'], 0, 10);
    // Marca primero para que un segundo aviso de la pasarela no lo aplique dos veces
    if (q("UPDATE pagos_linea SET aplicado = 'aplicando' WHERE id = ? AND aplicado = ''", [$id])->rowCount() !== 1) return;
    $txt = '';
    try {
        if ($pg['concepto'] === 'suscripcion') {
            $r = registrar_pago_suscripcion($liga, $pg['plan_id'] ? (int)$pg['plan_id'] : null, max(1, (int)$pg['meses']), (float)$pg['total'], 'tarjeta', $ref, $fecha, 'Pago en línea (' . $pg['pagador_nombre'] . ')');
            $txt = 'Suscripción renovada hasta el ' . $r['hasta'];
            foreach (array_column(filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''"), 'tg_chat') as $c) {
                tg_encolar(null, $c, '💳 <b>Pago con tarjeta de suscripción</b>' . "\n" . e($liga['nombre']) . ' · ' . dinero($pg['total']) . ' · activa hasta ' . $r['hasta']);
            }
        } elseif ($pg['concepto'] === 'equipo' && $pg['equipo_id']) {
            $mid = registrar_pago((int)$liga['id'], null, (int)$pg['equipo_id'], (float)$pg['monto_base'], 'otro', 'Pago con tarjeta en línea' . ($pg['pagador_nombre'] !== '' ? ' (' . $pg['pagador_nombre'] . ')' : ''),
                'tarjeta', $ref, $fecha, 'Pago en línea', 'linea:' . $pg['id']);
            $rec = (int)valor('SELECT recibo FROM movimientos WHERE id = ?', [$mid]);
            $txt = 'Recibo N° ' . $rec;
            $datos['movimiento_id'] = $mid;
            $eq = fila('SELECT nombre FROM equipos WHERE id = ?', [$pg['equipo_id']]);
            aviso_admins($liga, '💳 <b>Pago con tarjeta</b>' . "\n" . e($eq['nombre'] ?? '') . ' pagó ' . dinero($pg['monto_base']) . '. ' . e($txt), 'plin:' . $pg['id']);
        } elseif ($pg['concepto'] === 'escuela') {
            $ids = array_map('intval', (array)($datos['cargos'] ?? []));
            $pend = $ids ? array_column(filas("SELECT id FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente' AND id IN (" . implode(',', $ids) . ')', [$liga['id']]), 'id') : [];
            if ($pend) {
                $rec = cobrar_cargos($liga, $pend, 'tarjeta', 'Pago en línea N° ' . $pg['id'], $fecha);
                $txt = 'Recibo N° ' . $rec;
                $datos['recibo'] = $rec;
            } else {
                $txt = 'Los valores ya estaban pagados: la escuela debe revisar este pago (saldo a favor).';
            }
            aviso_admins($liga, '💳 <b>Pago con tarjeta</b> de una familia: ' . dinero($pg['monto_base']) . '. ' . e($txt), 'plin:' . $pg['id']);
        } elseif ($pg['concepto'] === 'entradas') {
            // (v28) Compra de entradas: queda pagada y se emiten los boletos con QR
            $o = fila('SELECT * FROM bol_ordenes WHERE id = ? AND liga_id = ?', [(int)($datos['orden_id'] ?? 0), $liga['id']]);
            if ($o && in_array($o['estado'], ['pendiente', 'expirada'], true)) {
                pagar_orden_entradas($o, 'Pago con tarjeta');
                $txt = 'Entradas emitidas (compra ' . $o['codigo'] . ')';
                aviso_admins($liga, '🎟 <b>Entradas pagadas con tarjeta</b>' . "\n" . e($o['nombre']) . ' · ' . dinero($pg['monto_base']), 'plin:' . $pg['id']);
            } else {
                $txt = 'La compra de entradas ya no estaba pendiente: revise este pago.';
            }
        } elseif ($pg['concepto'] === 'inscripcion') {
            // (v26) Inscripción en línea de un deporte individual: queda marcada como pagada (la organización la aprueba)
            $ins = fila('SELECT i.*, t.nombre AS torneo FROM inscripciones_indiv i JOIN torneos t ON t.id = i.torneo_id WHERE i.id = ? AND i.liga_id = ?', [(int)($datos['inscripcion_id'] ?? 0), $liga['id']]);
            if ($ins) {
                q('UPDATE inscripciones_indiv SET pagado = ? WHERE id = ?', [$pg['pagado_en'] ?: date('Y-m-d H:i:s'), $ins['id']]);
                $txt = 'Inscripción pagada: ' . $ins['nombre'];
                aviso_admins($liga, '💳 <b>Inscripción pagada con tarjeta</b>' . "\n" . e($ins['nombre']) . ' · ' . e($ins['torneo']) . ' · ' . dinero($pg['monto_base']), 'plin:' . $pg['id']);
            } else {
                $txt = 'La inscripción ya no existe: revise este pago.';
            }
        }
        q('UPDATE pagos_linea SET aplicado = ?, datos = ? WHERE id = ?', [mb_substr($txt ?: 'Aplicado', 0, 200), json_encode($datos, JSON_UNESCAPED_UNICODE), $id]);
    } catch (Throwable $ex) {
        q('UPDATE pagos_linea SET aplicado = ? WHERE id = ?', ['ERROR: ' . mb_substr($ex->getMessage(), 0, 180), $id]);
        bitacora_evento('pagos', 'No se pudo aplicar el pago en línea N° ' . $id . ': ' . $ex->getMessage(), (int)$pg['liga_id'], $id);
    }
}

function nombre_estado_pago_linea(string $e): string
{
    return ['pendiente' => 'Sin completar', 'pagado' => 'Pagado', 'rechazado' => 'Rechazado', 'anulado' => 'Anulado'][$e] ?? $e;
}

function chip_pago_linea(string $e): string
{
    return '<span class="estado-chip ' . (['pagado' => 'chip-ok', 'rechazado' => 'chip-mal', 'pendiente' => 'chip-pend'][$e] ?? '') . '">' . e(nombre_estado_pago_linea($e)) . '</span>';
}

function nombre_concepto_pago(string $c): string
{
    return ['suscripcion' => 'Suscripción ' . marca(), 'equipo' => 'Pago de equipo', 'escuela' => 'Pago de alumno', 'inscripcion' => 'Inscripción', 'entradas' => 'Entradas'][$c] ?? $c;
}

/** Totales de cobros con tarjeta de una organización (lo que TuVocalía le debe transferir). */
function resumen_cobros_org(int $liga_id): array
{
    $r = fila("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS cobrado, COALESCE(SUM(comision_pasarela + comision_tuvocalia), 0) AS comisiones, COALESCE(SUM(neto), 0) AS neto,
                      COALESCE(SUM(CASE WHEN liquidacion_id IS NULL THEN neto END), 0) AS por_liquidar, COALESCE(SUM(CASE WHEN liquidacion_id IS NULL THEN 1 END), 0) AS n_por_liquidar
               FROM pagos_linea WHERE liga_id = ? AND destino = 'organizacion' AND estado = 'pagado'", [$liga_id]);
    return array_map('floatval', $r);
}

/** Botón de pago con tarjeta (formulario) para delegados y familias. */
function aviso_comision_cliente(array $liga, float $base): string
{
    if (($liga['pagos_comision'] ?? '') !== 'cliente') return '';
    $c = calcular_cobro($base, 'cliente');
    return '<p class="ayuda">Al pagar con tarjeta se suma el costo de la transacción: ' . dinero($c['recargo']) . ' (total ' . dinero($c['total']) . ').</p>';
}

/* ============================================================
 * 3) ROL DE PAGO DE LOS PROFESORES
 * ============================================================ */

/** Calcula el rol: base + bonos − IESS − anticipos − otros = neto. */
function calcular_rol(array $prof, float $base, float $bonos, float $anticipos, float $otros): array
{
    $bruto = round($base + $bonos, 2);
    $iess = round($bruto * (float)($prof['iess_pct'] ?? 0) / 100, 2);
    $desc = round($iess + $anticipos + $otros, 2);
    return ['base' => round($base, 2), 'bonos' => round($bonos, 2), 'bruto' => $bruto, 'iess' => $iess, 'anticipos' => round($anticipos, 2), 'otros' => round($otros, 2), 'descuentos' => $desc, 'neto' => round($bruto - $desc, 2)];
}

/** Profesor vinculado al usuario conectado (o null). */
function profesor_del_usuario(?array $u): ?array
{
    if (!$u || empty($u['liga_id'])) return null;
    return fila('SELECT * FROM esc_profesores WHERE usuario_id = ? AND liga_id = ?', [$u['id'], $u['liga_id']]);
}

/** Documento: ROL DE PAGO (formato Ecuador) de un pago a profesor. */
function doc_rol_pago(array $pp, array $prof, array $liga, string $volver, string $url_factura = ''): void
{
    $base = (float)$pp['base'] > 0 || (float)$pp['bonos'] > 0 ? (float)$pp['base'] : (float)$pp['monto']; // pagos anteriores a v12: todo era base
    $bonos = (float)$pp['bonos'];
    $bruto = $base + $bonos;
    $desc = (float)$pp['iess'] + (float)$pp['anticipos'] + (float)$pp['otros_desc'];
    $modo = tipos_pago_profesor()[$prof['tipo_pago']] ?? $prof['tipo_pago'];
    doc_inicio('Rol de pago · ' . $prof['nombre'] . ' · ' . nombre_mes($pp['periodo']), $volver);
    doc_cabecera_financiera($liga, 'ROL DE PAGO N° ' . str_pad((string)$pp['id'], 5, '0', STR_PAD_LEFT));
    ?>
  <?php if ($pp['anulado']): ?><div class="marcador-doc" style="color:#b00;border-color:#b00">ANULADO</div><?php endif; ?>
  <div class="meta" style="grid-template-columns:1fr 1fr">
    <div><b>Nombre:</b> <?= e($prof['nombre']) ?></div><div><b>C.I.:</b> <?= e($prof['cedula'] ?: '—') ?></div>
    <div><b>Cargo:</b> <?= e($prof['cargo'] ?: 'Profesor') ?><?= $prof['especialidad'] !== '' ? ' · ' . e($prof['especialidad']) : '' ?></div><div><b>Período:</b> <?= e(nombre_mes($pp['periodo'])) ?></div>
    <div><b>Forma de pago acordada:</b> <?= e($modo) ?><?= (float)$prof['valor_pago'] > 0 ? ' (' . dinero($prof['valor_pago']) . ')' : '' ?></div><div><b>Fecha de pago:</b> <?= e($pp['fecha']) ?></div>
    <div><b>Clases dictadas en el mes:</b> <?= (int)$pp['clases'] ?></div><div><b>Alumnos en sus grupos:</b> <?= (int)$pp['alumnos'] ?></div>
  </div>
  <table class="pl" style="margin-top:10px">
    <tr><th style="width:50%">INGRESOS</th><th class="num">Valor</th><th style="width:50%">DESCUENTOS</th><th class="num">Valor</th></tr>
    <tr><td>Honorario / sueldo del mes</td><td class="num"><?= dinero($base) ?></td><td>Aporte personal IESS<?= (float)$prof['iess_pct'] > 0 ? ' (' . e(rtrim(rtrim(number_format((float)$prof['iess_pct'], 2, '.', ''), '0'), '.')) . '%)' : '' ?></td><td class="num"><?= dinero($pp['iess']) ?></td></tr>
    <tr><td>Bonos y extras<?= $pp['bonos_detalle'] !== '' ? ': ' . e($pp['bonos_detalle']) : '' ?></td><td class="num"><?= dinero($bonos) ?></td><td>Anticipos</td><td class="num"><?= dinero($pp['anticipos']) ?></td></tr>
    <tr><td></td><td></td><td>Otros descuentos<?= $pp['desc_detalle'] !== '' ? ': ' . e($pp['desc_detalle']) : '' ?></td><td class="num"><?= dinero($pp['otros_desc']) ?></td></tr>
    <tr><th>TOTAL INGRESOS</th><th class="num"><?= dinero($bruto) ?></th><th>TOTAL DESCUENTOS</th><th class="num"><?= dinero($desc) ?></th></tr>
  </table>
  <div class="marcador-doc">NETO A RECIBIR: <?= dinero($pp['monto']) ?></div>
  <p><b>Forma de pago:</b> <?= e(nombre_forma_pago($pp['forma_pago'])) ?><?= $pp['nota'] !== '' ? ' · ' . e($pp['nota']) : '' ?></p>
  <p style="font-size:11px">Recibí conforme el valor neto indicado por los servicios prestados en el período.</p>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Recibí conforme<br><?= e($prof['nombre']) ?><br>C.I. <?= e($prof['cedula']) ?></div><div>Entregué conforme<br><?= e($liga['nombre']) ?></div></div>
  <p style="font-size:11px;color:#555">Generado el <?= date('d/m/Y H:i') ?> con <?= e(marca()) ?>.</p>
  <?php if ($url_factura !== '') doc_factura($pp, $url_factura); ?>
    <?php
    doc_fin();
}

/* ============================================================
 * (v13) FACTURA DEL ÁRBITRO O DEL PROFESOR
 * Foto o PDF de la factura que entrega el árbitro o el profesor al cobrar. Se guarda fuera de la web (data/facturas/AAAAMM).
 * ============================================================ */

/** Guarda la factura subida en el campo $campo. Devuelve la ruta relativa a data/, o '' si no eligieron archivo. */
function subir_factura(string $campo): string
{
    $f = $_FILES[$campo] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo subir la factura (código ' . $f['error'] . '). Puede que sea muy pesada.');
    $sub = 'facturas/' . date('Ym');
    $cab = (string)@file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($cab === '%PDF-') {
        if ($f['size'] > 10 * 1024 * 1024) throw new RuntimeException('El PDF de la factura pesa más de 10 MB.');
        if (!is_dir(RAIZ . '/data/' . $sub)) mkdir(RAIZ . '/data/' . $sub, 0775, true);
        $nombre = date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.pdf';
        if (!move_uploaded_file($f['tmp_name'], RAIZ . '/data/' . $sub . '/' . $nombre) && !rename($f['tmp_name'], RAIZ . '/data/' . $sub . '/' . $nombre)) {
            throw new RuntimeException('No se pudo guardar el PDF de la factura.');
        }
        return $sub . '/' . $nombre;
    }
    return $sub . '/' . guardar_imagen($f, RAIZ . '/data/' . $sub, 1800);
}

function borrar_factura(string $ruta): void
{
    if ($ruta !== '' && !str_contains($ruta, '..') && str_starts_with($ruta, 'facturas/') && is_file(RAIZ . '/data/' . $ruta)) @unlink(RAIZ . '/data/' . $ruta);
}

function factura_es_pdf(string $ruta): bool
{
    return str_ends_with(strtolower($ruta), '.pdf');
}

/** Muestra el archivo de la factura (privado: cada página revisa antes que el pago sea de quien lo pide). */
function servir_factura(string $ruta): void
{
    $abs = RAIZ . '/data/' . $ruta;
    if ($ruta === '' || str_contains($ruta, '..') || !str_starts_with($ruta, 'facturas/') || !is_file($abs)) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (factura_es_pdf($ruta) ? 'application/pdf' : (mime_content_type($abs) ?: 'image/jpeg')));
    header('Content-Disposition: inline; filename="factura_' . basename($ruta) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($abs);
    exit;
}

/** Limpia el número de factura (formato SRI 001-001-000000123, pero se acepta lo que escriban). */
function limpiar_numero_factura(string $n): string
{
    $n = trim(preg_replace('/\s+/', ' ', $n));
    if (preg_match('/^(\d{1,3})[-. ](\d{1,3})[-. ](\d{1,9})$/', $n, $m) || preg_match('/^(\d{3})(\d{3})(\d{9})$/', $n, $m)) {
        return str_pad($m[1], 3, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 3, '0', STR_PAD_LEFT) . '-' . str_pad($m[3], 9, '0', STR_PAD_LEFT);
    }
    return mb_substr($n, 0, 40);
}

/** Guarda (o cambia) la factura de un pago ya registrado. $tabla: pagos_arbitro o esc_pagos_profesor. */
function adjuntar_factura(string $tabla, array $pago, string $campo, string $numero, string $por): bool
{
    if (!in_array($tabla, ['pagos_arbitro', 'esc_pagos_profesor'], true)) throw new RuntimeException('Tabla no válida.');
    $ruta = subir_factura($campo);
    $numero = limpiar_numero_factura($numero);
    if ($ruta === '' && $numero === (string)$pago['factura_numero']) throw new RuntimeException('Tome o elija la foto (o el PDF) de la factura.');
    if ($ruta !== '') {
        borrar_factura((string)$pago['factura']);
        q("UPDATE $tabla SET factura = ?, factura_numero = ?, factura_subida = ? WHERE id = ?", [$ruta, $numero, mb_substr(date('Y-m-d H:i') . ' · ' . $por, 0, 120), $pago['id']]);
    } else {
        q("UPDATE $tabla SET factura_numero = ? WHERE id = ?", [$numero, $pago['id']]);
    }
    return true;
}

/** Botón "ver factura" o formulario corto para subirla. $url_ver: enlace al archivo; $ocultos: campos hidden del formulario. */
function html_factura(array $pago, string $url_ver, string $ocultos, bool $puede_subir): string
{
    $h = '';
    if ((string)$pago['factura'] !== '') {
        $h .= '<a class="btn btn-sec btn-chico" target="_blank" href="' . e($url_ver) . '">' . (factura_es_pdf($pago['factura']) ? '📄' : '🧾') . ' Factura' . ($pago['factura_numero'] !== '' ? ' ' . e($pago['factura_numero']) : '') . '</a>';
    } elseif ($pago['factura_numero'] !== '') {
        $h .= '<small>Fact. ' . e($pago['factura_numero']) . '</small> ';
    }
    if ($puede_subir && empty($pago['anulado'])) {
        $h .= '<details class="factura-subir"><summary>' . ((string)$pago['factura'] !== '' ? 'Cambiar' : '＋ Subir factura') . '</summary>'
            . '<form method="post" enctype="multipart/form-data">' . csrf_campo() . '<input type="hidden" name="accion" value="factura">' . $ocultos
            . '<input type="file" name="factura" accept="image/*,application/pdf" required>'
            . '<input type="text" name="factura_numero" maxlength="40" placeholder="N° factura (opcional)" value="' . e($pago['factura_numero']) . '">'
            . '<button class="btn btn-chico">Guardar</button></form></details>';
    }
    return $h;
}

/** Bloque para el documento impreso: número de factura y la imagen (si es foto). */
function doc_factura(array $pago, string $url_ver): void
{
    if ((string)$pago['factura'] === '' && (string)$pago['factura_numero'] === '') return;
    echo '<div class="doc-factura"><p><b>Factura del proveedor:</b> ' . ($pago['factura_numero'] !== '' ? 'N° ' . e($pago['factura_numero']) : 'adjunta')
        . ((string)$pago['factura'] !== '' ? ' · <a href="' . e($url_ver) . '" target="_blank">ver archivo</a>' : '') . '</p>';
    if ((string)$pago['factura'] !== '' && !factura_es_pdf($pago['factura'])) {
        echo '<style>@media print{.doc-factura img{page-break-before:always;max-height:96vh}}</style><img src="' . e($url_ver) . '" alt="Factura" style="max-width:100%;max-height:900px;border:1px solid #ccc;margin-top:6px">';
    }
    echo '</div>';
}
