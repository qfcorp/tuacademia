<?php
/*
 * VERSIÓN 6 — Ajustes generales, planes, suscripciones de las ligas (prueba gratis, vencimiento)
 * y dominio propio por liga.
 */

/** Lee un ajuste general del sistema (tabla ajustes). */
function ajuste(string $clave, $def = '')
{
    static $cache = null;
    if ($cache === null || $clave === '__recargar') {
        $cache = [];
        try {
            foreach (db()->query('SELECT clave, valor FROM ajustes')->fetchAll() as $r) $cache[$r['clave']] = $r['valor'];
        } catch (Throwable) {
            $cache = [];
        }
        if ($clave === '__recargar') return null;
    }
    if (array_key_exists($clave, $cache)) return $cache[$clave];
    return ajustes_por_defecto()[$clave] ?? $def;
}

function guardar_ajuste(string $clave, $valor): void
{
    q('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor', [$clave, (string)$valor]);
    ajuste('__recargar');
}

/** Nombre comercial del sistema (se cambia en Ajustes). */
function marca(): string
{
    $m = (string)ajuste('marca');
    return $m !== '' ? $m : CFG['nombre_sitio'];
}

/* ============================================================
 * SUSCRIPCIONES
 * vence = '' → sin vencimiento (ligas propias de QF o de cortesía).
 * es_prueba = 1 → está en su demo (ya no hay prueba gratis).
 * Vencida: pasados los días de gracia el panel de la liga queda en "solo lectura".
 * ============================================================ */

function plan_de_liga(array $liga): ?array
{
    return !empty($liga['plan_id']) ? fila('SELECT * FROM planes WHERE id = ?', [$liga['plan_id']]) : null;
}

/**
 * Estado de la suscripción de una liga.
 * ['estado' => 'sin_limite'|'prueba'|'activa'|'gracia'|'vencida', 'dias' => días que faltan (negativo si ya venció), 'texto' => ...]
 */
function estado_suscripcion(array $liga): array
{
    if (($liga['vence'] ?? '') === '') return ['estado' => 'sin_limite', 'dias' => null, 'texto' => 'Sin vencimiento'];
    $hoy = new DateTime('today');
    $vence = DateTime::createFromFormat('Y-m-d', $liga['vence']) ?: new DateTime('today');
    $vence->setTime(0, 0);
    $dias = (int)$hoy->diff($vence)->format('%r%a');
    if ($dias >= 0) {
        $e = !empty($liga['es_prueba']) ? 'prueba' : 'activa';
        $txt = ($e === 'prueba' ? 'Demo' : 'Activa') . ' hasta el ' . $liga['vence'] . ' (' . ($dias === 0 ? 'vence hoy' : 'faltan ' . $dias . ' día' . ($dias === 1 ? '' : 's')) . ')';
        return ['estado' => $e, 'dias' => $dias, 'texto' => $txt];
    }
    $gracia = dias_gracia_de($liga); // (v33) la demo no tiene días de gracia (salvo que el superadministrador lo cambie)
    if ($gracia > 0 && -$dias <= $gracia) {
        return ['estado' => 'gracia', 'dias' => $dias, 'texto' => 'Venció el ' . $liga['vence'] . '. Quedan ' . ($gracia + $dias) . ' día(s) de gracia antes de bloquear los cambios.'];
    }
    if (!empty($liga['es_prueba'])) return ['estado' => 'vencida', 'dias' => $dias, 'texto' => 'Su demo terminó el ' . $liga['vence'] . '. El panel está en solo lectura: contrate su plan en Mi plan y sigue con todo lo que cargó.']; // (v33)
    return ['estado' => 'vencida', 'dias' => $dias, 'texto' => 'Suscripción vencida desde el ' . $liga['vence'] . '. El panel está en modo solo lectura hasta renovar.'];
}

/** ¿La liga puede hacer cambios? (el superadministrador siempre puede) */
function liga_puede_editar(?array $liga): bool
{
    if (!$liga || es_super()) return true;
    if (liga_suspendida($liga)) return false; // (Tukán Central v2) cuenta suspendida por Tukán: no puede trabajar
    return estado_suscripcion($liga)['estado'] !== 'vencida';
}

/** Lanza error si la liga tiene la suscripción vencida (para vocalía, árbitro y delegado). */
function exigir_suscripcion(array $liga): void
{
    if (liga_suspendida($liga)) throw new RuntimeException(texto_suspension($liga)); // (Tukán Central v2)
    if (estado_suscripcion($liga)['estado'] === 'vencida') {
        throw new RuntimeException('La suscripción de ' . $liga['nombre'] . ' está vencida: por ahora no se pueden guardar cambios. La liga debe renovar su plan.');
    }
}

/**
 * (v7) Límites de una liga: los que puso el superadministrador en la liga o, si están vacíos, los de su plan.
 * 0 = ilimitado. Modo de campeonatos: 'activos' (a la vez, sin contar los finalizados) o 'total' (todos los que se creen).
 */
function limites_liga(array $liga): array
{
    $plan = plan_de_liga($liga);
    $max_c = $liga['max_campeonatos'] ?? null;
    $max_e = $liga['max_equipos'] ?? null;
    $origen_c = $max_c !== null ? 'liga' : ($plan ? 'plan' : 'sin');
    $origen_e = $max_e !== null ? 'liga' : ($plan ? 'plan' : 'sin');
    $max_c = $max_c !== null ? (int)$max_c : ($plan ? (int)$plan['max_campeonatos'] : 0);
    $max_e = $max_e !== null ? (int)$max_e : ($plan ? (int)$plan['max_equipos'] : 0);
    $modo = ($liga['modo_campeonatos'] ?? '') === 'total' ? 'total' : 'activos';
    $usados_c = $modo === 'total'
        ? (int)valor('SELECT COUNT(*) FROM torneos WHERE liga_id = ?', [$liga['id']])
        : (int)valor("SELECT COUNT(*) FROM torneos WHERE liga_id = ? AND estado <> 'finalizado'", [$liga['id']]);
    return [
        'campeonatos' => $max_c, 'modo' => $modo, 'usados_campeonatos' => $usados_c, 'origen_campeonatos' => $origen_c,
        'equipos' => $max_e, 'usados_equipos' => (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = ?', [$liga['id']]), 'origen_equipos' => $origen_e,
        'plan' => $plan,
    ];
}

/** Texto para mostrar: "Puede crear 3 campeonatos a la vez · lleva 1". */
function texto_limite_campeonatos(array $liga): string
{
    $l = limites_liga($liga);
    if ($l['campeonatos'] === 0) return 'Campeonatos ilimitados · lleva ' . $l['usados_campeonatos'] . ($l['modo'] === 'total' ? ' en total' : ' en curso');
    $quedan = max(0, $l['campeonatos'] - $l['usados_campeonatos']);
    return 'Puede crear ' . $l['campeonatos'] . ' campeonato' . ($l['campeonatos'] === 1 ? '' : 's') . ($l['modo'] === 'total' ? ' en total' : ' a la vez (en curso)')
        . ' · lleva ' . $l['usados_campeonatos'] . ' · ' . ($quedan > 0 ? 'le quedan ' . $quedan : 'no le quedan más');
}

/** Revisa los límites (de la liga o de su plan) antes de crear equipos o campeonatos. */
function verificar_limite_plan(array $liga, string $que): void
{
    if (es_super()) return;
    $l = limites_liga($liga);
    $quien = $l['origen_' . ($que === 'equipo' ? 'equipos' : 'campeonatos')] === 'plan' ? 'Su plan ' . $l['plan']['nombre'] : 'Su liga';
    if ($que === 'equipo' && $l['equipos'] > 0 && $l['usados_equipos'] >= $l['equipos']) {
        throw new RuntimeException($quien . ' permite hasta ' . $l['equipos'] . ' equipos. Para agregar más, pida a ' . ajuste('empresa') . ' que amplíe el límite.');
    }
    if ($que === 'campeonato' && $l['campeonatos'] > 0 && $l['usados_campeonatos'] >= $l['campeonatos']) {
        throw new RuntimeException($quien . ' permite ' . $l['campeonatos'] . ' campeonato(s)' . ($l['modo'] === 'total' ? ' en total' : ' a la vez: finalice uno')
            . ' o pida a ' . ajuste('empresa') . ' que amplíe el límite.');
    }
}

/** Crea una liga completa con su administrador (lo usa el superadministrador al crear cuentas y demos). */
function crear_liga_completa(array $d): array
{
    $nombre = trim($d['nombre'] ?? '');
    if (PRODUCTO === 'academia') { $d['tipo'] = 'escuela'; $d['crear_campeonato'] = false; } // (TuAcademia) toda cuenta nueva es una academia
    if (mb_strlen($nombre) < 3) throw new RuntimeException(PRODUCTO === 'academia' ? 'Escriba el nombre de la academia.' : 'Escriba el nombre de la liga.');
    $email = mb_strtolower(trim($d['admin_email'] ?? ''), 'UTF-8');
    // (v16) Combo liga + escuela: si no se escribe administrador, el dueño entra con el mismo usuario de la otra cuenta
    $combo = !empty($d['combo_con']) ? fila('SELECT * FROM ligas WHERE id = ?', [(int)$d['combo_con']]) : null;
    $sin_admin = $combo && $email === '' && trim($d['admin_nombre'] ?? '') === '';
    if ($sin_admin) {
        $d['admin_nombre'] = $combo['contacto_nombre'];
        $email = mb_strtolower((string)$combo['contacto_email']);
        $d['telefono'] = ($d['telefono'] ?? '') !== '' ? $d['telefono'] : $combo['contacto_telefono'];
    } else {
    if (mb_strlen(trim($d['admin_nombre'] ?? '')) < 3) throw new RuntimeException(PRODUCTO === 'academia' ? 'Escriba el nombre del administrador de la academia.' : 'Escriba el nombre del administrador de la liga.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escriba un correo válido para el administrador (será su usuario).');
    if (strlen($d['admin_clave'] ?? '') < 8) throw new RuntimeException('La contraseña del administrador debe tener al menos 8 caracteres.');
    if (fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email])) throw new RuntimeException('Ese correo ya tiene una cuenta en el sistema. Use otro.');
    }
    $modalidad = in_array($d['modalidad'] ?? 'F7', modalidades(), true) ? $d['modalidad'] : 'F7';
    // (v35) Módulos deportivos de la cuenta (null = todos). La modalidad y el deporte principal deben ser de un módulo elegido.
    $deportes = isset($d['deportes']) && is_array($d['deportes']) ? array_values(array_intersect(array_keys(modulos_deporte_base()), $d['deportes'])) : null;
    if ($deportes !== null && !$deportes) throw new RuntimeException(PRODUCTO === 'academia' ? 'Elija al menos una disciplina (baile, música, natación, fútbol…).' : 'Elija al menos un módulo deportivo (fútbol, ciclismo, combate…).');
    // (TuAcademia) si eligió la disciplina principal pero no marcó su módulo, se agrega solo
    if ($deportes !== null && ($d['tipo'] ?? '') === 'escuela' && ($kd = modulo_de_deporte_escuela((string)($d['deporte_escuela'] ?? ''))) !== '' && !in_array($kd, $deportes, true) && PRODUCTO === 'academia') $deportes[] = $kd;
    if ($deportes !== null) {
        $tmp = ['deportes' => implode(',', $deportes)];
        if (($d['tipo'] ?? '') === 'escuela') {
            if (!deporte_escuela_permitido(['deportes' => implode(',', $deportes)], (string)($d['deporte_escuela'] ?? 'futbol'))) throw new RuntimeException('La disciplina principal debe ser de una de las elegidas.');
        } elseif (!modalidad_permitida($tmp, $modalidad)) {
            $equipo = filtrar_modalidades($tmp, modalidades());
            $modalidad = $equipo[0] ?? $modalidad; // sin deportes de equipo: la modalidad por defecto no se usa
            if (!$equipo) $d['crear_campeonato'] = false;
        }
    }
    $slug = slugify(trim((string)($d['slug'] ?? '')) !== '' ? (string)$d['slug'] : $nombre);
    if (in_array($slug, rutas_reservadas(), true)) $slug .= '-liga';
    $base = $slug;
    $n = 2;
    while (fila('SELECT id FROM ligas WHERE slug = ?', [$slug])) $slug = $base . '-' . $n++;
    $reglas = presets_modalidad()[$modalidad] ?? presets_modalidad()['F7'];
    $datos = array_merge([
        'nombre' => mb_substr($nombre, 0, 120), 'slug' => $slug, 'ciudad' => mb_substr(trim($d['ciudad'] ?? ''), 0, 80),
        'color' => color_valido($d['color'] ?? '#0b6e4f'), 'modalidad' => $modalidad, 'activa' => 1,
        'plan_id' => !empty($d['plan_id']) ? (int)$d['plan_id'] : null, 'vence' => $d['vence'] ?? '', 'es_prueba' => !empty($d['es_prueba']) ? 1 : 0,
        'vendedor_id' => !empty($d['vendedor_id']) ? (int)$d['vendedor_id'] : null,
        'contacto_nombre' => mb_substr(trim($d['admin_nombre']), 0, 80), 'contacto_telefono' => mb_substr(trim($d['telefono'] ?? ''), 0, 30), 'contacto_email' => $email,
        // (v7) límites propios de la liga (null = los del plan)
        'max_campeonatos' => isset($d['max_campeonatos']) && $d['max_campeonatos'] !== '' && $d['max_campeonatos'] !== null ? max(0, (int)$d['max_campeonatos']) : null,
        'max_equipos' => isset($d['max_equipos']) && $d['max_equipos'] !== '' && $d['max_equipos'] !== null ? max(0, (int)$d['max_equipos']) : null,
        'modo_campeonatos' => ($d['modo_campeonatos'] ?? '') === 'total' ? 'total' : '',
        // (v11) Liga o escuela
        'tipo' => ($d['tipo'] ?? '') === 'escuela' ? 'escuela' : 'liga',
        'deporte_escuela' => array_key_exists($d['deporte_escuela'] ?? '', deportes_escuela()) ? $d['deporte_escuela'] : 'futbol',
        'deportes' => $deportes === null ? '*' : implode(',', $deportes),
        // (Tukán Central v2) de dónde vino la cuenta: registro (prueba gratis), demo_pedida, super o central
        'origen' => in_array($d['origen'] ?? '', ['registro', 'demo_pedida', 'super', 'central', 'google_play'], true) ? $d['origen'] : 'super',
    ], $reglas);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO ligas (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($datos))) . ')', $datos);
        $lid = (int)$pdo->lastInsertId();
        $uid = 0;
        if (!$sin_admin) {
            q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id, perfil) VALUES (?,?,?, 'liga', ?, 'admin')", [mb_substr(trim($d['admin_nombre']), 0, 80), $email, password_hash($d['admin_clave'], PASSWORD_DEFAULT), $lid]);
            $uid = (int)$pdo->lastInsertId();
        }
        if ($combo) enlazar_combo($lid, (int)$combo['id']);
        if (($d['tipo'] ?? '') === 'escuela') {
            preparar_escuela($lid);
        } elseif (!empty($d['crear_campeonato'])) {
            $t = array_merge(['liga_id' => $lid, 'nombre' => 'Campeonato ' . date('Y'), 'temporada' => date('Y')], $reglas, ['modalidad' => $modalidad]);
            q('INSERT INTO torneos (' . implode(', ', array_keys($t)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($t))) . ')', $t);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['liga_id' => $lid, 'usuario_id' => $uid, 'slug' => $slug];
}

/** Direcciones que no pueden usarse como nombre corto de una liga (tuacademia.tukanec.com/NOMBRE). */
function rutas_reservadas(): array
{
    return ['admin', 'app', 'vocal', 'arbitro', 'delegado', 'carnet', 'foto', 'imagen', 'index', 'assets', 'uploads', 'telegram', 'sw', 'manifest', 'ligas', 'planes', 'registro', 'privacidad', 'precios', 'mi', 'widget', 'calendario', 'reservas', 'reservar', 'vendedor', 'vendedores', 'demo', 'push', 'olvide', 'familia', 'escuelas', 'comparar', 'pagar', 'solicitar-demo', 'solicitar', 'nuestras-escuelas', 'directorio'];
}

/** Dirección pública bonita de la liga: https://tuacademia.tukanec.com/nombre-liga (o su dominio propio). */
function url_liga(array $liga): string
{
    if (($liga['dominio'] ?? '') !== '') return 'https://' . $liga['dominio'] . '/';
    return url_base() . rawurlencode($liga['slug']);
}

/** Liga a la que pertenece el dominio con el que se entró (dominio propio), o null. */
function liga_por_dominio(): ?array
{
    static $hecho = false, $liga = null;
    if ($hecho) return $liga;
    $hecho = true;
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/^www\./', '', $host);
    if ($host === '' || !str_contains($host, '.')) return null;
    // (Anexo A) una cuenta PAUSADA se sigue reconociendo por su dominio (muestra «temporalmente no disponible»); la de la papelera no
    $liga = fila("SELECT * FROM ligas WHERE dominio <> '' AND (lower(dominio) = ? OR lower(dominio) = ?) AND (activa = 1 OR (es_demo = 0 AND suspendida_tipo <> '' AND COALESCE(eliminada, '') = ''))", [$host, 'www.' . $host]);
    return $liga;
}

/** Normaliza un dominio escrito por el usuario (quita https://, www., barras). */
function limpiar_dominio(string $d): string
{
    $d = strtolower(trim($d));
    $d = preg_replace('#^https?://#', '', $d);
    $d = preg_replace('#/.*$#', '', $d);
    $d = preg_replace('/^www\./', '', $d);
    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d) ? $d : '';
}

/** Límite simple de intentos por IP (registro, formularios públicos). */
function demasiados_intentos(string $tipo, int $max, string $ventana = '-1 hour'): bool
{
    q("DELETE FROM intentos WHERE creado < datetime('now', '-2 days')");
    return (int)valor('SELECT COUNT(*) FROM intentos WHERE tipo = ? AND ip = ? AND creado >= datetime(\'now\', ?)', [$tipo, ip_cliente(), $ventana]) >= $max;
}

function anotar_intento(string $tipo): void
{
    q('INSERT INTO intentos (tipo, ip) VALUES (?, ?)', [$tipo, ip_cliente()]);
}
