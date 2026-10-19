<?php
/*
 * (Anexo B, 18 oct 2026) PROGRAMAS MODULARES: encender y apagar funciones por cliente para cobrar más o menos.
 * El MISMO archivo sirve para TuVocalía y TuAcademia. Contrato: tukan-central/docs/PROTOCOLO_V2.md, Anexo B.
 *
 * - Base: lo que todo cliente tiene siempre (funciones_base() de app/v12.php). No es un módulo y no se apaga.
 * - Módulo: los de modulos_sistema() (app/v12.php: nombre, ícono y texto para vender) + lo de este archivo:
 *   precio_mensual (USD), depende_de (otros módulos que deben estar encendidos) y grupo. Los valores de fábrica están
 *   en modulos_de_fabrica(); el súper usuario los cambia (tabla modulos_catalogo, solo guarda lo cambiado).
 * - Plan: precio + módulos (planes.modulos, «*» = todos).
 * - Por cliente (tabla ligas): modulos_extra (encendidos además del plan, se cobran aparte), modulos_quitados
 *   (JSON {módulo: descuento $}: módulos del plan que se apagaron), precio_acordado (fijo, o NULL) y modulos_elegidos
 *   (1 = el súper eligió los módulos: se respetan también durante la demo; 0 = en la demo tiene todo).
 *   Activos = (módulos del plan − quitados) + extra, sin los que les falta una dependencia.
 * - Precio mensual = plan + Σ extra + deportes adicionales − Σ descuentos (o el precio acordado).
 * - Apagar NUNCA borra datos: solo se esconden menús, páginas, portales, PDF, tareas y avisos del módulo.
 * - Pedidos desde «Mi plan» (tabla modulos_pedidos) → solicitud «cambio_modulos» del Panel Tukán Central.
 * - Mapa de páginas y portales: modulo_de_pagina() (app/v12.php), paginas_base() y modulo_de_portal() de aquí. La prueba
 *   pruebas/tukan_central/prueba_modulos.py revisa que TODA página del panel y TODO portal tenga su módulo o diga que es base.
 */

/* ============================================================
 * 1) BASE DE DATOS (se crea sola; no usa PRAGMA user_version)
 * ============================================================ */

function esquema_modulos(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(ligas)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!$cols) return;
    foreach (['modulos_quitados' => "TEXT NOT NULL DEFAULT ''", 'precio_acordado' => 'REAL', 'modulos_elegidos' => 'INTEGER NOT NULL DEFAULT 0',
              'modulos_extra' => "TEXT NOT NULL DEFAULT ''"] as $c => $def) {
        if (in_array($c, $cols, true)) continue;
        try {
            $pdo->exec("ALTER TABLE ligas ADD COLUMN $c $def");
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'duplicate column')) throw $e;
        }
    }
    // (18 oct 2026) la nota del dominio ya no dice «gratis» (se cambia una sola vez el texto de fábrica guardado)
    try {
        $pdo->exec("UPDATE ajustes SET valor = replace(valor, 'El dominio gratis se da al pagar', 'El dominio propio viene incluido al pagar') WHERE clave = 'nota_dominio' AND valor LIKE 'El dominio gratis se da al pagar%'");
    } catch (PDOException) {
    }
    // novedad para los clientes (se ve una sola vez) y el texto viejo de «meses gratis» de los referidos
    try {
        $pdo->exec("UPDATE novedades SET texto = replace(texto, 'gane meses gratis', 'gane meses de descuento') WHERE texto LIKE '%gane meses gratis%'");
        $pdo->prepare("INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?, ?, 'todos', 'admin.php?p=mi_plan#modulos', 'anexo_b', ? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)")
            ->execute(['🧩 Mis módulos', 'En ⭐ Mi plan → Mis módulos ve las funciones que tiene encendidas y las que puede agregar, con su precio. Toque «Pedir este módulo» y se lo encendemos (normalmente el mismo día). Si un módulo se apaga, sus datos no se borran.', date('Y-m-d'), '🧩 Mis módulos']);
    } catch (PDOException) {
    }
    $hay = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('modulos_catalogo', 'modulos_pedidos')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('modulos_catalogo', $hay, true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS modulos_catalogo (
            clave TEXT PRIMARY KEY,
            precio_mensual REAL,
            depende_de TEXT,
            grupo TEXT,
            descripcion TEXT NOT NULL DEFAULT '',
            orden INTEGER,
            actualizado TEXT NOT NULL DEFAULT '',
            por TEXT NOT NULL DEFAULT ''
        )");
    }
    if (!in_array('modulos_pedidos', $hay, true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS modulos_pedidos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
            encender TEXT NOT NULL DEFAULT '',
            apagar TEXT NOT NULL DEFAULT '',
            mensaje TEXT NOT NULL DEFAULT '',
            estado TEXT NOT NULL DEFAULT 'pendiente',
            pedido_por TEXT NOT NULL DEFAULT '',
            creado TEXT NOT NULL,
            atendido_por TEXT NOT NULL DEFAULT '',
            atendido_en TEXT NOT NULL DEFAULT '',
            respuesta TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_modulos_pedidos_liga ON modulos_pedidos (liga_id, estado)');
    }
}

/* ============================================================
 * 2) CATÁLOGO
 * ============================================================ */

/** Grupos para ordenar en pantalla: clave => nombre. */
function grupos_modulos(): array
{
    return ['partidos' => 'Partidos y campeonatos', 'personas' => 'Jugadores, alumnos y familias', 'clases' => 'Clases y avance',
            'dinero' => 'Dinero y cobros', 'difusion' => 'Difusión y público', 'equipo' => 'Equipo de trabajo', 'herramientas' => 'Herramientas'];
}

/** Valores de fábrica: módulo => [precio mensual USD, depende_de, grupo, orden]. */
function modulos_de_fabrica(): array
{
    return [
        'vocalia' => [5, [], 'partidos', 10], 'fixture_pro' => [3, [], 'partidos', 12], 'arbitros' => [4, [], 'partidos', 14],
        'carnets' => [3, [], 'personas', 20], 'inscripcion' => [4, [], 'personas', 22], 'esc_familias' => [4, [], 'personas', 24],
        'esc_entrenamientos' => [3, [], 'clases', 30], 'esc_evaluaciones' => [4, [], 'clases', 32], 'esc_formativas' => [5, ['esc_evaluaciones'], 'clases', 34],
        'acad_niveles' => [4, [], 'clases', 36], 'acad_recitales' => [3, [], 'clases', 38], 'acad_particulares' => [3, [], 'clases', 39],
        'finanzas' => [4, [], 'dinero', 40], 'pagos_tarjeta' => [5, [], 'dinero', 42], 'pago_payphone' => [4, ['esc_familias'], 'dinero', 43],
        'reservas' => [4, ['finanzas'], 'dinero', 44], 'entradas' => [5, [], 'dinero', 46], 'esc_tienda' => [3, [], 'dinero', 47], 'contabilidad' => [4, [], 'dinero', 48],
        'redes' => [3, [], 'difusion', 50], 'publico' => [3, [], 'difusion', 52], 'avisos' => [3, [], 'difusion', 54], 'videos' => [3, [], 'difusion', 56], 'dominio' => [2, [], 'difusion', 58],
        'esc_profesores' => [4, [], 'equipo', 60],
        'carga_masiva' => [2, [], 'herramientas', 70],
    ];
}

/** Lo que el súper cambió del catálogo (tabla modulos_catalogo). */
function modulos_catalogo_guardado(bool $recargar = false): array
{
    static $c = null;
    if ($c !== null && !$recargar) return $c;
    try {
        return $c = array_column(filas('SELECT * FROM modulos_catalogo'), null, 'clave');
    } catch (Throwable) {
        return $c = [];
    }
}

/** Tipo de cuenta para los módulos: 'liga' o 'escuela'. */
function tipo_modulos(?array $l): string
{
    return ($l['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
}

/**
 * Catálogo de módulos: id => [id, nombre, icono, descripcion, precio_mensual, depende_de, grupo, grupo_nombre, orden, para].
 * Con $tipo ('liga' | 'escuela') solo los de ese tipo y las dependencias que existen en ese tipo. Ordenado por grupo y orden.
 */
function catalogo_modulos(string $tipo = ''): array
{
    $fab = modulos_de_fabrica();
    $g = modulos_catalogo_guardado();
    $grupos = grupos_modulos();
    $orden_g = array_flip(array_keys($grupos));
    $r = [];
    foreach (modulos_sistema($tipo) as $k => $m) {
        [$ico, $nom, $para, $desc] = $m;
        [$precio, $dep, $grupo, $orden] = $fab[$k] ?? [3, [], 'herramientas', 90];
        $x = $g[$k] ?? null;
        if ($x) {
            if ($x['precio_mensual'] !== null && $x['precio_mensual'] !== '') $precio = (float)$x['precio_mensual'];
            if ($x['depende_de'] !== null) $dep = array_values(array_filter(array_map('trim', explode(',', (string)$x['depende_de']))));
            if ($x['grupo'] !== null && isset($grupos[$x['grupo']])) $grupo = (string)$x['grupo'];
            if (trim((string)$x['descripcion']) !== '') $desc = (string)$x['descripcion'];
            if ($x['orden'] !== null && $x['orden'] !== '') $orden = (int)$x['orden'];
        }
        $r[$k] = ['id' => $k, 'nombre' => $nom, 'icono' => $ico, 'descripcion' => $desc, 'precio_mensual' => round((float)$precio, 2), 'depende_de' => $dep,
                  'grupo' => $grupo, 'grupo_nombre' => $grupos[$grupo] ?? $grupo, 'orden' => $orden, 'para' => $para];
    }
    foreach ($r as $k => $m) $r[$k]['depende_de'] = array_values(array_filter($m['depende_de'], fn($d) => $d !== $k && isset($r[$d])));
    uasort($r, fn($a, $b) => [($orden_g[$a['grupo']] ?? 99), $a['orden'], $a['id']] <=> [($orden_g[$b['grupo']] ?? 99), $b['orden'], $b['id']]);
    return $r;
}

/** Todas las dependencias (directas y de las dependencias) de un módulo. */
function dependencias_modulo(string $id, array $cat): array
{
    $r = [];
    $pend = $cat[$id]['depende_de'] ?? [];
    while ($pend) {
        $d = array_shift($pend);
        if (isset($r[$d]) || $d === $id || !isset($cat[$d])) continue;
        $r[$d] = true;
        foreach ($cat[$d]['depende_de'] as $x) $pend[] = $x;
    }
    return array_keys($r);
}

/* ============================================================
 * 3) MÓDULOS DE UN CLIENTE
 * ============================================================ */

/** Módulos del plan de una cuenta (solo los de su tipo). */
function modulos_del_plan_cuenta(?array $l, ?array $pl = null, ?array $cat = null): array
{
    $cat ??= catalogo_modulos(tipo_modulos($l));
    $pl ??= $l ? plan_de_liga($l) : null;
    return array_values(array_intersect(array_keys($cat), modulos_plan($pl)));
}

/** Extra guardados de una cuenta (lista). */
function modulos_extra_cuenta(?array $l): array
{
    return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($l['modulos_extra'] ?? ''))))));
}

/** Quitados de una cuenta: [módulo => descuento $ por mes]. */
function modulos_quitados_cuenta(?array $l): array
{
    $j = json_decode((string)($l['modulos_quitados'] ?? ''), true);
    if (!is_array($j)) return [];
    $r = [];
    foreach ($j as $k => $v) if (is_string($k) && $k !== '') $r[$k] = round(max(0, (float)$v), 2);
    return $r;
}

function precio_acordado_cuenta(?array $l): ?float
{
    $v = $l['precio_acordado'] ?? null;
    return $v === null || $v === '' ? null : round((float)$v, 2);
}

/** Activos = (del plan − quitados) + extra, en el orden del catálogo, sin los que les falta una dependencia. */
function calcular_activos(array $del_plan, array $extra, array $quitados, array $cat): array
{
    $set = array_flip(array_merge(array_diff($del_plan, array_keys($quitados)), $extra));
    $r = array_values(array_filter(array_keys($cat), fn($k) => isset($set[$k])));
    // sin dependencias que falten (un plan armado a mano no regala lo que no paga): se repite hasta que no cambie
    do {
        $antes = count($r);
        $s = array_flip($r);
        $r = array_values(array_filter($r, fn($k) => !array_diff($cat[$k]['depende_de'], array_keys($s))));
    } while (count($r) !== $antes);
    return $r;
}

/** Precio mensual de un plan (el mensual, o el más barato por mes de sus períodos). */
function precio_mensual_plan(?array $pl): float
{
    if (!$pl) return 0.0;
    if ((float)$pl['precio_mensual'] > 0) return round((float)$pl['precio_mensual'], 2);
    $min = null;
    foreach (periodos_plan() as $k => [$meses]) {
        $p = (float)($pl['precio_' . $k] ?? 0);
        if ($p > 0) $min = $min === null ? $p / $meses : min($min, $p / $meses);
    }
    return round((float)$min, 2);
}

/** ¿La cuenta tiene todo encendido por ser demo (prueba o demo del sistema) sin módulos elegidos por el súper? */
function cuenta_con_todo(?array $l): bool
{
    if (!$l) return true;
    if (!empty($l['es_demo'])) return true;
    if ((int)($l['modulos_elegidos'] ?? 0) === 1) return false;
    return empty($l['plan_id']) || estado_suscripcion($l)['estado'] === 'prueba';
}

/**
 * Módulos de un cliente (Anexo B.2, igual en el conector y en Mi plan):
 * {del_plan, extra, quitados, activos, precio_plan, precio_extra, precio_deportes, descuento, precio_acordado, mensual, todos_en_demo}.
 * Con $pl: cómo quedaría con ese plan (para cotizar otro plan).
 */
function modulos_cliente(array $l, ?array $pl = null): array
{
    $cat = catalogo_modulos(tipo_modulos($l));
    $pl ??= plan_de_liga($l);
    $del_plan = modulos_del_plan_cuenta($l, $pl, $cat);
    $extra = array_values(array_filter(modulos_extra_cuenta($l), fn($k) => isset($cat[$k]) && !in_array($k, $del_plan, true)));
    $quit = array_filter(modulos_quitados_cuenta($l), fn($k) => in_array($k, $del_plan, true), ARRAY_FILTER_USE_KEY);
    $todo = cuenta_con_todo($l);
    $activos = $todo ? array_keys($cat) : calcular_activos($del_plan, $extra, $quit, $cat);
    $p_plan = precio_mensual_plan($pl);
    $p_extra = round(array_sum(array_map(fn($k) => $cat[$k]['precio_mensual'], $extra)), 2);
    $p_dep = empty($l['es_demo']) && function_exists('precio_modulos') ? (float)precio_modulos(deportes_cobrados($l))['extra'] : 0.0;
    $desc = round(array_sum($quit), 2);
    $acordado = precio_acordado_cuenta($l);
    return [
        'del_plan' => $del_plan, 'extra' => $extra, 'quitados' => array_keys($quit), 'descuentos' => (object)$quit, 'activos' => $activos,
        'precio_plan' => $p_plan, 'precio_extra' => $p_extra, 'precio_deportes' => round($p_dep, 2), 'descuento' => $desc,
        'precio_acordado' => $acordado, 'mensual' => $acordado ?? round(max(0, $p_plan + $p_extra + $p_dep - $desc), 2),
        'todos_en_demo' => $todo && empty($l['es_demo']),
    ];
}

/** ¿La cuenta cambió algo de su plan (extra, quitados o precio acordado)? */
function modulos_personalizados(array $l): bool
{
    $m = modulos_cliente($l);
    return $m['extra'] || $m['quitados'] || $m['precio_acordado'] !== null;
}

/**
 * Lo que se suma (o resta) por mes al precio de un plan por los módulos de la cuenta: extra que ese plan no trae − descuento
 * de los quitados que ese plan sí trae. Lo usa opciones_precio() (app/v12.php) para Mi plan, cotizar y registrar pago.
 */
function modulos_ajuste_mes(?array $l, ?array $pl): float
{
    if (!$l || !empty($l['es_demo']) || empty($l['id'])) return 0.0;
    $cat = catalogo_modulos(tipo_modulos($l));
    $del = modulos_del_plan_cuenta($l, $pl, $cat);
    $s = 0.0;
    foreach (modulos_extra_cuenta($l) as $k) if (isset($cat[$k]) && !in_array($k, $del, true)) $s += $cat[$k]['precio_mensual'];
    foreach (modulos_quitados_cuenta($l) as $k => $d) if (in_array($k, $del, true)) $s -= $d;
    return round($s, 2);
}

/** Nombres de una lista de módulos: «📱 Vocalía en vivo, 🪪 Carnets». */
function nombres_modulos(array $ids, ?array $cat = null): string
{
    $cat ??= catalogo_modulos();
    return implode(', ', array_map(fn($k) => isset($cat[$k]) ? $cat[$k]['icono'] . ' ' . $cat[$k]['nombre'] : $k, $ids));
}

/**
 * Enciende y apaga módulos de una cuenta (Mi plan aprobado, Ligas → Editar y el Panel Tukán Central).
 * $o: precio_acordado (si viene la clave: número o null para quitarlo), descuentos [módulo => $] para los quitados,
 *     motivo, por (quién). Valida dependencias (no se apaga uno del que depende otro encendido) y enciende las
 *     dependencias que falten. NUNCA borra datos. Devuelve [antes, despues, encendidos, apagados, auto, sin_cambios, texto].
 */
function cambiar_modulos_cuenta(array $l, array $encender, array $apagar, array $o = []): array
{
    $cat = catalogo_modulos(tipo_modulos($l));
    $antes = modulos_cliente($l);
    foreach ([$encender, $apagar] as $lista) {
        foreach ($lista as $k) {
            if (!is_string($k) || !isset($cat[$k])) throw new RuntimeException('El módulo «' . (is_scalar($k) ? (string)$k : '?') . '» no existe para ' . (tipo_modulos($l) === 'escuela' ? 'escuelas y academias' : 'ligas') . '.');
        }
    }
    $encender = array_values(array_unique($encender));
    $apagar = array_values(array_unique($apagar));
    if ($ambos = array_intersect($encender, $apagar)) throw new RuntimeException('No se puede encender y apagar a la vez: ' . nombres_modulos($ambos, $cat) . '.');
    $del_plan = $antes['del_plan'];
    // en la demo sin módulos elegidos tiene TODO: se parte de lo que ve (todo encendido) para que solo cambie lo pedido
    $extra = $antes['todos_en_demo'] ? array_values(array_diff(array_keys($cat), $del_plan)) : $antes['extra'];
    $quit = $antes['todos_en_demo'] ? [] : (array)$antes['descuentos'];
    $activos_antes = $antes['activos'];
    $auto = [];
    foreach ($encender as $k) {
        foreach (array_merge([$k], dependencias_modulo($k, $cat)) as $x) {
            if (in_array($x, $apagar, true)) throw new RuntimeException('No se puede apagar ' . nombres_modulos([$x], $cat) . ': lo necesita ' . nombres_modulos([$k], $cat) . '.');
            if ($x !== $k && !in_array($x, $activos_antes, true) && !in_array($x, $encender, true)) $auto[] = $x;
            if (in_array($x, $del_plan, true)) unset($quit[$x]);
            elseif (!in_array($x, $extra, true)) $extra[] = $x;
        }
    }
    $desc_in = is_array($o['descuentos'] ?? null) ? $o['descuentos'] : [];
    foreach ($desc_in as $k => $v) {
        if (!isset($cat[$k]) || !is_numeric($v) || (float)$v < 0 || (float)$v > 10000) throw new RuntimeException('Descuento no válido para ' . (isset($cat[$k]) ? $cat[$k]['nombre'] : (string)$k) . '.');
    }
    foreach ($apagar as $k) {
        if (in_array($k, $del_plan, true)) $quit[$k] = round((float)($desc_in[$k] ?? $quit[$k] ?? 0), 2);
        $extra = array_values(array_diff($extra, [$k]));
    }
    foreach ($desc_in as $k => $v) if (isset($quit[$k])) $quit[$k] = round((float)$v, 2);
    $despues_activos = calcular_activos($del_plan, $extra, $quit, $cat);
    // nadie que estaba encendido puede quedar sin lo que necesita (si se cae, es porque se apagó una dependencia suya)
    foreach (array_diff($activos_antes, $despues_activos, $apagar) as $k) {
        $falta = array_intersect(dependencias_modulo($k, $cat), $apagar);
        if ($falta) throw new RuntimeException('No se puede apagar ' . nombres_modulos(array_values($falta), $cat) . ': ' . nombres_modulos([$k], $cat) . ' depende de él. Apague también ' . $cat[$k]['nombre'] . '.');
    }
    $acordado = precio_acordado_cuenta($l);
    if (array_key_exists('precio_acordado', $o)) {
        $v = $o['precio_acordado'];
        if ($v === null || $v === '') $acordado = null;
        elseif (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000) throw new RuntimeException('El precio acordado debe ser un número de 0 a 100000 (o vacío para quitarlo).');
        else $acordado = round((float)$v, 2);
    }
    $orden = fn(array $ks) => implode(',', array_values(array_filter(array_keys($cat), fn($k) => in_array($k, $ks, true))));
    $json = function (array $q): string { ksort($q); return $q ? json_encode(array_map(fn($x) => round((float)$x, 2), $q), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) : ''; };
    $extra_txt = $orden($extra);
    $quit_txt = $json($quit);
    $sin = $extra_txt === $orden(modulos_extra_cuenta($l)) && $quit_txt === $json(modulos_quitados_cuenta($l))
        && $acordado === precio_acordado_cuenta($l) && (int)($l['modulos_elegidos'] ?? 0) === 1;
    if (!$sin) {
        q('UPDATE ligas SET modulos_extra = ?, modulos_quitados = ?, precio_acordado = ?, modulos_elegidos = 1 WHERE id = ?', [$extra_txt, $quit_txt, $acordado, $l['id']]);
    }
    $l2 = fila('SELECT * FROM ligas WHERE id = ?', [$l['id']]) ?? $l;
    $despues = modulos_cliente($l2);
    $enc = array_values(array_diff($despues['activos'], $activos_antes));
    $apg = array_values(array_diff($activos_antes, $despues['activos']));
    $partes = [];
    if ($enc) $partes[] = 'encendió ' . nombres_modulos($enc, $cat);
    if ($apg) $partes[] = 'apagó ' . nombres_modulos($apg, $cat);
    if ($auto) $partes[] = 'también encendió (los necesita) ' . nombres_modulos(array_values(array_unique($auto)), $cat);
    if ($acordado !== $antes['precio_acordado']) $partes[] = $acordado === null ? 'quitó el precio acordado' : 'precio acordado ' . dinero($acordado) . ' al mes';
    $texto = $partes ? ucfirst(implode('; ', $partes)) . '. Mensual: ' . dinero($despues['mensual']) . '.' : 'Los módulos ya estaban así.';
    if (!$sin) {
        bitacora_evento('ventas', 'Módulos de ' . $l['nombre'] . ': ' . $texto . (($o['motivo'] ?? '') !== '' ? ' Motivo: ' . mb_substr((string)$o['motivo'], 0, 200) : '') . (($o['por'] ?? '') !== '' ? ' (' . $o['por'] . ')' : ''), (int)$l['id']);
    }
    return ['antes' => $antes, 'despues' => $despues, 'encendidos' => $enc, 'apagados' => $apg, 'auto' => array_values(array_unique($auto)), 'sin_cambios' => $sin, 'texto' => $texto];
}

/**
 * Guarda el catálogo (precio, descripción, orden, grupo, dependencias) y los módulos de cada plan.
 * $mods: [{id, precio_mensual?, descripcion?, orden?, grupo?, depende_de?}] · $planes: [{codigo, modulos: [ids] | "*"}].
 * Devuelve la cantidad de cambios. Revisa que las dependencias no formen un círculo.
 */
function guardar_catalogo_modulos(array $mods, array $planes, string $por): int
{
    $cat = catalogo_modulos();
    $g = modulos_catalogo_guardado(true);
    $n = 0;
    foreach ($mods as $i => $m) {
        if (!is_array($m) || !is_string($m['id'] ?? null) || !isset($cat[$m['id']])) throw new RuntimeException('Módulo ' . ($i + 1) . ': no existe.');
        $k = $m['id'];
        $fila = $g[$k] ?? ['precio_mensual' => null, 'depende_de' => null, 'grupo' => null, 'descripcion' => '', 'orden' => null];
        $nuevo = $fila;
        if (array_key_exists('precio_mensual', $m)) {
            if (!is_numeric($m['precio_mensual']) || (float)$m['precio_mensual'] < 0 || (float)$m['precio_mensual'] > 10000) throw new RuntimeException('Precio no válido para ' . $cat[$k]['nombre'] . ' (0 a 10000).');
            $nuevo['precio_mensual'] = round((float)$m['precio_mensual'], 2);
        }
        if (array_key_exists('descripcion', $m)) {
            if (!is_string($m['descripcion']) || mb_strlen($m['descripcion']) > 400) throw new RuntimeException('La descripción de ' . $cat[$k]['nombre'] . ' puede tener hasta 400 caracteres.');
            $nuevo['descripcion'] = trim(strip_tags($m['descripcion']));
        }
        if (array_key_exists('orden', $m)) {
            if (!is_numeric($m['orden']) || (int)$m['orden'] < 0 || (int)$m['orden'] > 999) throw new RuntimeException('Orden no válido para ' . $cat[$k]['nombre'] . ' (0 a 999).');
            $nuevo['orden'] = (int)$m['orden'];
        }
        if (array_key_exists('grupo', $m)) {
            if (!is_string($m['grupo']) || !isset(grupos_modulos()[$m['grupo']])) throw new RuntimeException('Grupo no válido para ' . $cat[$k]['nombre'] . ' (' . implode(', ', array_keys(grupos_modulos())) . ').');
            $nuevo['grupo'] = $m['grupo'];
        }
        if (array_key_exists('depende_de', $m)) {
            if (!is_array($m['depende_de']) || array_diff($m['depende_de'], array_keys($cat)) || in_array($k, $m['depende_de'], true)) throw new RuntimeException('Dependencias no válidas para ' . $cat[$k]['nombre'] . '.');
            $nuevo['depende_de'] = implode(',', array_values(array_unique($m['depende_de'])));
        }
        if ($nuevo == $fila && isset($g[$k])) continue;
        if ($nuevo == $fila) continue;
        q('INSERT OR REPLACE INTO modulos_catalogo (clave, precio_mensual, depende_de, grupo, descripcion, orden, actualizado, por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$k, $nuevo['precio_mensual'], $nuevo['depende_de'], $nuevo['grupo'], (string)$nuevo['descripcion'], $nuevo['orden'], date('Y-m-d H:i:s'), mb_substr($por, 0, 120)]);
        $n++;
    }
    modulos_catalogo_guardado(true);
    // sin círculos de dependencias
    $cat2 = catalogo_modulos();
    foreach ($cat2 as $k => $x) {
        if (in_array($k, dependencias_modulo($k, $cat2), true)) throw new RuntimeException('Las dependencias forman un círculo en ' . $x['nombre'] . '.');
        foreach ($x['depende_de'] as $d) if (in_array($k, dependencias_modulo($d, $cat2), true)) throw new RuntimeException('Las dependencias forman un círculo entre ' . $x['nombre'] . ' y ' . $cat2[$d]['nombre'] . '.');
    }
    foreach ($planes as $i => $p) {
        if (!is_array($p)) throw new RuntimeException('Plan ' . ($i + 1) . ': no válido.');
        $cod = $p['codigo'] ?? null;
        $pl = (is_string($cod) || is_int($cod)) && ctype_digit((string)$cod) ? fila('SELECT * FROM planes WHERE id = ?', [(int)$cod]) : null;
        if (!$pl) throw new RuntimeException('Plan ' . ($i + 1) . ': no existe.');
        $ms = $p['modulos'] ?? null;
        if ($ms === '*') $txt = '*';
        elseif (is_array($ms) && !array_diff($ms, array_keys($cat2))) $txt = count(array_unique($ms)) === count($cat2) ? '*' : implode(',', array_values(array_filter(array_keys($cat2), fn($k) => in_array($k, $ms, true))));
        else throw new RuntimeException('Módulos no válidos en el plan ' . $pl['nombre'] . '.');
        if (array_key_exists('precio_mensual', $p)) {
            if (!is_numeric($p['precio_mensual']) || (float)$p['precio_mensual'] < 0 || (float)$p['precio_mensual'] > 100000) throw new RuntimeException('Precio no válido en el plan ' . $pl['nombre'] . '.');
            if (round((float)$p['precio_mensual'], 2) !== round((float)$pl['precio_mensual'], 2)) {
                q('UPDATE planes SET precio_mensual = ? WHERE id = ?', [round((float)$p['precio_mensual'], 2), $pl['id']]);
                $n++;
            }
        }
        if ($txt !== (string)$pl['modulos']) {
            q('UPDATE planes SET modulos = ? WHERE id = ?', [$txt, $pl['id']]);
            $n++;
        }
    }
    if ($n) bitacora_evento('ventas', 'Cambió el catálogo de módulos y planes (' . $n . ' cambio' . ($n === 1 ? '' : 's') . ') · ' . $por);
    return $n;
}

/* ============================================================
 * 4) PEDIDOS DESDE «MI PLAN» (solicitud cambio_modulos)
 * ============================================================ */

/** El cliente pide encender módulos (y, si quiere, apagar). Crea el pedido y avisa al súper. Devuelve el id. */
function crear_pedido_modulos(array $l, string $quien, array $encender, array $apagar = [], string $mensaje = ''): int
{
    $cat = catalogo_modulos(tipo_modulos($l));
    $act = modulos_cliente($l)['activos'];
    $encender = array_values(array_unique(array_filter($encender, fn($k) => is_string($k) && isset($cat[$k]))));
    $apagar = array_values(array_unique(array_filter($apagar, fn($k) => is_string($k) && isset($cat[$k]) && in_array($k, $act, true))));
    if (!$encender && !$apagar) throw new RuntimeException('Elija el módulo que quiere.');
    if (cuenta_con_todo($l) && !$apagar && !array_diff($encender, $act)) {
        // en la demo ya tiene todo encendido: igual se anota (es lo que quiere contratar)
    }
    $ya = fila("SELECT id FROM modulos_pedidos WHERE liga_id = ? AND estado = 'pendiente' AND encender = ? AND apagar = ?", [$l['id'], implode(',', $encender), implode(',', $apagar)]);
    if ($ya) throw new RuntimeException('Ya pidió eso y está por revisar. Le avisamos apenas lo atendamos.');
    if ((int)valor("SELECT COUNT(*) FROM modulos_pedidos WHERE liga_id = ? AND estado = 'pendiente'", [$l['id']]) >= 10) throw new RuntimeException('Tiene 10 pedidos por revisar. Espere a que los atendamos.');
    q('INSERT INTO modulos_pedidos (liga_id, encender, apagar, mensaje, pedido_por, creado) VALUES (?, ?, ?, ?, ?, ?)',
        [$l['id'], implode(',', $encender), implode(',', $apagar), mb_substr(trim(strip_tags($mensaje)), 0, 500), mb_substr($quien, 0, 160), date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    $precio = round(array_sum(array_map(fn($k) => $cat[$k]['precio_mensual'], array_diff($encender, $act))), 2);
    bitacora_evento('ventas', 'Pidió módulos desde Mi plan: ' . nombres_modulos($encender ?: $apagar, $cat) . ($apagar && $encender ? ' (y quitar ' . nombres_modulos($apagar, $cat) . ')' : ($apagar ? ' (quitar)' : '')), (int)$l['id'], $id);
    if (function_exists('aviso_super')) {
        aviso_super('🧩 <b>Pedido de módulos</b>' . "\n" . e($l['nombre']) . ': ' . e(nombres_modulos($encender, $cat)) . ($apagar ? "\nQuitar: " . e(nombres_modulos($apagar, $cat)) : '')
            . ($precio > 0 ? "\n+" . dinero($precio) . ' al mes' : '') . "\nRevíselo en el Panel Tukán Central o en Ligas → Editar.", 'modped:' . $id);
    }
    return $id;
}

function estados_pedido_modulos(): array
{
    return ['pendiente' => 'Por revisar', 'aprobada' => 'Aprobado', 'rechazada' => 'No aprobado'];
}

/** Aprueba un pedido: enciende/apaga (con las mismas reglas) y le avisa al cliente. */
function aprobar_pedido_modulos(int $id, string $por, array $o = []): array
{
    $p = fila('SELECT * FROM modulos_pedidos WHERE id = ?', [$id]) ?? throw new RuntimeException('Ese pedido ya no existe.');
    if ($p['estado'] !== 'pendiente') throw new RuntimeException('Ese pedido ya fue atendido (' . (estados_pedido_modulos()[$p['estado']] ?? $p['estado']) . ').');
    $l = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]) ?? throw new RuntimeException('La cuenta ya no existe.');
    $enc = $o['encender'] ?? array_values(array_filter(explode(',', (string)$p['encender'])));
    $apg = $o['apagar'] ?? array_values(array_filter(explode(',', (string)$p['apagar'])));
    $r = cambiar_modulos_cuenta($l, $enc, $apg, ['por' => $por, 'motivo' => 'Pedido #' . $id . ' desde Mi plan'] + array_intersect_key($o, array_flip(['precio_acordado', 'descuentos'])));
    q("UPDATE modulos_pedidos SET estado = 'aprobada', atendido_por = ?, atendido_en = ?, respuesta = ? WHERE id = ?", [mb_substr($por, 0, 160), date('Y-m-d H:i:s'), mb_substr((string)($o['nota'] ?? $r['texto']), 0, 500), $id]);
    foreach (tg_chats_admins((int)$l['id']) as $c) tg_encolar((int)$l['id'], $c, '🧩 <b>Módulos activados</b>' . "\n" . e($r['texto']) . "\nVer: " . url_base() . 'admin.php?p=mi_plan', '', 'modok:' . $id . ':' . $c);
    return $r + ['liga' => $l, 'pedido' => $p];
}

function rechazar_pedido_modulos(int $id, string $por, string $motivo): array
{
    $p = fila('SELECT * FROM modulos_pedidos WHERE id = ?', [$id]) ?? throw new RuntimeException('Ese pedido ya no existe.');
    if ($p['estado'] !== 'pendiente') throw new RuntimeException('Ese pedido ya fue atendido (' . (estados_pedido_modulos()[$p['estado']] ?? $p['estado']) . ').');
    $motivo = trim(strip_tags($motivo));
    if (mb_strlen($motivo) < 3) throw new RuntimeException('Escriba el motivo para el cliente.');
    q("UPDATE modulos_pedidos SET estado = 'rechazada', atendido_por = ?, atendido_en = ?, respuesta = ? WHERE id = ?", [mb_substr($por, 0, 160), date('Y-m-d H:i:s'), mb_substr($motivo, 0, 500), $id]);
    $l = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]);
    bitacora_evento('ventas', 'No aprobó el pedido de módulos #' . $id . ': ' . $motivo . ' (' . $por . ')', (int)$p['liga_id'], $id);
    if ($l) foreach (tg_chats_admins((int)$l['id']) as $c) tg_encolar((int)$l['id'], $c, '🧩 <b>Pedido de módulos no aprobado</b>' . "\n" . e($motivo), '', 'modno:' . $id . ':' . $c);
    return ['pedido' => $p, 'liga' => $l];
}

/* ============================================================
 * 5) MAPA DE PÁGINAS Y PORTALES (qué es base y qué es de un módulo)
 * ============================================================ */

/** Páginas del panel (admin.php?p=…) que son BASE (no dependen de ningún módulo) o solo del súper usuario. */
function paginas_base(): array
{
    return [
        // entrar y cuenta
        'login', 'salir', 'inicio', 'clave', 'seguridad', 'mis_datos', 'usuarios', 'bitacora', 'config', 'mi_plan', 'soporte', 'novedades', 'datos', 'referidos', 'indicadores',
        // liga: equipos, jugadores, campeonatos (base)
        'equipos', 'equipo', 'jugador', 'torneos', 'torneo', 'partido', 'planilla', 'programar', 'acumuladas', 'competencias', 'carrera', 'duelo', 'bolos', 'pruebas', 'indiv',
        'federacion', 'olimpiadas', 'requisitos', 'requisito_doc', 'examenes', 'imprimir',
        // escuela (base)
        'alumnos', 'alumno', 'grupos', 'asistencia', 'cobros', 'esc_recibo', 'escuela_config', 'escuela_inicio', 'mensajes',
        // solo el súper usuario de Tukán
        'ligas', 'planes', 'suscripciones', 'ajustes', 'vendedores', 'negocio', 'demos', 'portales', 'embudo', 'modulos', 'pasarela', 'google_play',
    ];
}

/**
 * Portales y archivos de public/: módulo que exigen, o 'base'. Los que dicen «firmado:…» solo funcionan con enlaces que
 * crean las páginas de ese módulo. pagar/plux: el cobro de la SUSCRIPCIÓN es base; el de equipos y familias exige el módulo.
 */
function mapa_portales(): array
{
    return [
        'admin.php' => 'base (cada página: modulo_de_pagina / paginas_base)', 'index.php' => 'base (las pestañas de cada módulo se revisan adentro)',
        'app.php' => 'base', 'calendario.php' => 'base', 'foto.php' => 'base', 'manifest.php' => 'base', 'push.php' => 'base', 'sitemap.php' => 'base',
        'tukan_central.php' => 'base', 'vendedor.php' => 'base', 'descargar.php' => 'base', 'assetlinks.php' => 'base', 'play.php' => 'base (app de Google Play)',
        'vocal.php' => 'vocalia', 'arbitro.php' => 'arbitros', 'delegado.php' => 'inscripcion', 'mi.php' => 'carnets', 'carnet.php' => 'carnets', 'familia.php' => 'esc_familias',
        'e.php' => 'entradas', 'puerta.php' => 'entradas', 'widget.php' => 'publico', 'imagen.php' => 'redes', 'certificado.php' => 'acad_niveles',
        'pagar.php' => 'pagos_tarjeta', 'plux_pagar.php' => 'pagos_tarjeta', 'plux_3ds.php' => 'firmado:pagos_tarjeta', 'payphone.php' => 'firmado:pago_payphone',
        'video.php' => 'firmado:videos',
    ];
}

function modulo_de_portal(string $archivo): string
{
    $m = mapa_portales()[$archivo] ?? '';
    if ($m === '' || str_starts_with($m, 'base')) return '';
    return str_starts_with($m, 'firmado:') ? substr($m, 8) : $m;
}

/** Pestañas de la página pública de una liga que son de un módulo: v => módulo. */
function modulo_de_vista_publica(string $v): string
{
    return ['votar' => 'publico', 'polla' => 'publico', 'reservar' => 'reservas', 'entradas' => 'entradas', 'fixture_pdf' => 'fixture_pro', 'esquema' => 'fixture_pro'][$v] ?? '';
}

/**
 * ¿La cuenta tiene encendido el módulo? Si el módulo no es de su tipo (p. ej. «Patrocinadores» en una escuela), se respeta
 * lo de siempre: lo tiene si su plan lo trae (así no se le quita nada a nadie con el modelo nuevo).
 */
function modulo_encendido(?array $liga, string $m): bool
{
    if (!$liga || $m === '') return true;
    if (!isset(catalogo_modulos(tipo_modulos($liga))[$m])) return cuenta_con_todo($liga) || in_array($m, modulos_plan(plan_de_liga($liga)), true) || in_array($m, modulos_extra_cuenta($liga), true);
    return modulo_activo($liga, $m);
}

/** Dentro del panel: si la cuenta no tiene el módulo, error con el texto de siempre (admin.php lo muestra y vuelve). */
function exigir_modulo_panel(?array $liga, string $m): void
{
    if (!$liga || $m === '' || es_super() || modulo_encendido($liga, $m)) return;
    throw new RuntimeException('Su plan no incluye «' . nombre_modulo($m) . '». Puede pedirlo en ⭐ Mi plan.');
}

/** Para los portales que no muestran página (JSON o imagen): 403 corto si la cuenta no tiene el módulo. */
function exigir_modulo_corto(?array $liga, string $m, bool $json = false): void
{
    if (!$liga || $m === '' || modulo_encendido($liga, $m)) return;
    http_response_code(403);
    header('Cache-Control: no-store');
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'status' => 'failed', 'message' => 'No disponible en el plan de ' . $liga['nombre'] . '.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    exit('No disponible en el plan de ' . $liga['nombre'] . '.');
}

/* ============================================================
 * 6) TEXTOS DE VENTA (18 oct 2026: «nada se da gratis, todo se pide demo»)
 * ============================================================ */

/** Ahorro de pagar varios meses juntos: «ahorra 1 mes» / «ahorra 2 meses» (antes decía «meses gratis»). */
function texto_ahorro(int|float|string $meses): string
{
    $n = (int)$meses;
    return 'ahorra ' . $n . ' mes' . ($n === 1 ? '' : 'es');
}

/* ============================================================
 * 7) PANTALLAS
 * ============================================================ */

/**
 * Interruptores de módulos de una cuenta (Ligas → Editar, solo el súper): encendido/apagado, precio, descuento del
 * quitado, precio acordado y total. Se guarda con accion=modulos_cuenta (app/admin/ligas.php).
 */
function html_interruptores_modulos(array $l): string
{
    $cat = catalogo_modulos(tipo_modulos($l));
    $m = modulos_cliente($l);
    $act = array_flip($m['activos']);
    $plan = array_flip($m['del_plan']);
    $desc = (array)$m['descuentos'];
    $pl = plan_de_liga($l);
    ob_start();
    ?>
    <div class="tarjeta" id="modulos-cuenta" style="margin-top:14px">
      <h2 style="margin-top:0">🧩 Módulos de <?= e($l['nombre']) ?></h2>
      <p class="suave" style="margin-top:0">Plan: <b><?= $pl ? e($pl['nombre']) . ' (' . dinero($m['precio_plan']) . ' al mes)' : 'sin plan' ?></b>. Apagar un módulo <b>no borra nada</b>: solo se esconde; si lo vuelve a encender, todo sigue como estaba.
        <?= $m['todos_en_demo'] ? '<br>Ahora está en <b>demo con todos los módulos</b>. Si guarda aquí, desde ya ve solo los que deje encendidos.' : '' ?></p>
      <form method="post" action="admin.php?p=ligas" onsubmit="return confirm('¿Guardar los módulos de <?= e(addslashes($l['nombre'])) ?>?')">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="modulos_cuenta"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <?php $grupo = null; foreach ($cat as $k => $x): if ($x['grupo'] !== $grupo): $grupo = $x['grupo']; ?><h3 class="mod-grupo"><?= e($x['grupo_nombre']) ?></h3><?php endif; $en_plan = isset($plan[$k]); ?>
          <div class="mod-fila<?= isset($act[$k]) ? ' mod-on' : '' ?>">
            <label class="interruptor"><input type="checkbox" name="mod[<?= e($k) ?>]" value="1" <?= isset($act[$k]) ? 'checked' : '' ?>><span></span></label>
            <div class="mod-texto"><b><?= $x['icono'] ?> <?= e($x['nombre']) ?></b> <?= $en_plan ? '<span class="etiqueta verde">en su plan</span>' : '<span class="etiqueta">+' . dinero($x['precio_mensual']) . ' al mes</span>' ?>
              <?= $x['depende_de'] ? '<small class="suave">· necesita ' . e(nombres_modulos($x['depende_de'], $cat)) . '</small>' : '' ?></div>
            <?php if ($en_plan): ?><div class="mod-desc"><label class="suave" style="font-size:.8rem">Descuento si se apaga ($)</label><input type="number" name="desc[<?= e($k) ?>]" min="0" step="0.01" value="<?= e((string)($desc[$k] ?? 0)) ?>" style="width:90px"></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="campos" style="margin-top:12px">
          <div><label>Precio acordado ($ al mes)</label><input type="number" name="precio_acordado" min="0" step="0.01" value="<?= $m['precio_acordado'] === null ? '' : e((string)$m['precio_acordado']) ?>" placeholder="vacío = el calculado"><div class="ayuda">Si lo llena, se cobra ese valor fijo en vez del calculado.</div></div>
          <div><label>Motivo (queda en la bitácora)</label><input type="text" name="motivo" maxlength="200"></div>
        </div>
        <p class="mod-total">Plan <?= dinero($m['precio_plan']) ?> + extra <?= dinero($m['precio_extra']) ?><?= $m['precio_deportes'] > 0 ? ' + deportes ' . dinero($m['precio_deportes']) : '' ?> − descuentos <?= dinero($m['descuento']) ?> = <b><?= dinero($m['mensual']) ?> al mes</b><?= $m['precio_acordado'] !== null ? ' (precio acordado)' : '' ?></p>
        <button class="btn">Guardar módulos</button>
      </form>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Pedidos de módulos por revisar de una cuenta (para Ligas → Editar), con Aprobar / No aprobar. */
function html_pedidos_modulos(array $l): string
{
    $ps = filas("SELECT * FROM modulos_pedidos WHERE liga_id = ? ORDER BY (estado = 'pendiente') DESC, id DESC LIMIT 10", [$l['id']]);
    if (!$ps) return '';
    $cat = catalogo_modulos(tipo_modulos($l));
    ob_start();
    ?>
    <div class="tarjeta" id="pedidos-modulos" style="margin-top:14px">
      <h2 style="margin-top:0">🧩 Pedidos de módulos (desde Mi plan)</h2>
      <?php foreach ($ps as $p): ?>
        <div class="mod-fila" style="flex-wrap:wrap">
          <div class="mod-texto"><b>#<?= (int)$p['id'] ?></b> · <?= e(substr((string)$p['creado'], 0, 16)) ?> · <?= e(nombres_modulos(array_filter(explode(',', (string)$p['encender'])), $cat)) ?><?= $p['apagar'] !== '' ? ' · quitar: ' . e(nombres_modulos(explode(',', (string)$p['apagar']), $cat)) : '' ?>
            <?= $p['mensaje'] !== '' ? '<br><small>«' . e($p['mensaje']) . '»</small>' : '' ?> <span class="etiqueta<?= $p['estado'] === 'pendiente' ? ' amarilla' : ($p['estado'] === 'aprobada' ? ' verde' : ' gris') ?>"><?= e(estados_pedido_modulos()[$p['estado']] ?? $p['estado']) ?></span>
            <?= $p['respuesta'] !== '' ? '<br><small class="suave">' . e($p['respuesta']) . '</small>' : '' ?></div>
          <?php if ($p['estado'] === 'pendiente'): ?>
            <form method="post" action="admin.php?p=ligas" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="pedido_modulos_aprobar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="pedido" value="<?= (int)$p['id'] ?>"><button class="btn btn-chico">Aprobar</button></form>
            <form method="post" action="admin.php?p=ligas" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="pedido_modulos_rechazar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="pedido" value="<?= (int)$p['id'] ?>"><input type="text" name="motivo" required minlength="3" maxlength="300" placeholder="Motivo para el cliente" style="width:180px"> <button class="btn btn-sec btn-chico">No aprobar</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Mi plan: módulos encendidos y los que puede agregar con su precio y el botón «Pedir este módulo». */
function html_mi_plan_modulos(array $l, bool $puede_pedir): string
{
    $cat = catalogo_modulos(tipo_modulos($l));
    $m = modulos_cliente($l);
    $act = array_flip($m['activos']);
    $pend = [];
    foreach (filas("SELECT encender FROM modulos_pedidos WHERE liga_id = ? AND estado = 'pendiente'", [$l['id']]) as $p) foreach (array_filter(explode(',', (string)$p['encender'])) as $k) $pend[$k] = true;
    ob_start();
    ?>
    <div class="tarjeta" id="modulos" style="margin-top:14px">
      <h2 style="margin-top:0">🧩 Mis módulos</h2>
      <?php if ($m['todos_en_demo']): ?><p class="aviso aviso-info">Está en <b>demo</b>: tiene todos los módulos encendidos para que vea todo. Al contratar queda con los de su plan y los que pida aquí.</p><?php endif; ?>
      <p class="suave" style="margin-top:0">Su plan trae lo básico siempre. Cada módulo es una función que se enciende o se apaga; si apaga uno, <b>sus datos no se borran</b>.</p>
      <?php foreach ([true, false] as $encendidos):
          $lista = array_filter($cat, fn($x) => isset($act[$x['id']]) === $encendidos);
          if (!$lista) continue; ?>
        <h3><?= $encendidos ? '✅ Encendidos' : '➕ Puede agregar' ?></h3>
        <div class="rejilla-mods">
          <?php foreach ($lista as $k => $x): ?>
            <div class="mod-tarjeta<?= $encendidos ? ' mod-on' : '' ?>">
              <div class="mod-cab"><span class="mod-ico"><?= $x['icono'] ?></span> <b><?= e($x['nombre']) ?></b></div>
              <p><?= e($x['descripcion']) ?></p>
              <?php if ($x['depende_de']): ?><p class="suave" style="font-size:.82rem">Necesita: <?= e(nombres_modulos($x['depende_de'], $cat)) ?></p><?php endif; ?>
              <div class="mod-pie">
                <?php if ($encendidos): ?><span class="etiqueta verde"><?= in_array($k, $m['del_plan'], true) ? 'En su plan' : 'Extra · ' . dinero($x['precio_mensual']) . ' al mes' ?></span>
                <?php else: ?><b><?= dinero($x['precio_mensual']) ?> al mes</b>
                  <?php if (isset($pend[$k])): ?><span class="etiqueta amarilla">Pedido, por revisar</span>
                  <?php elseif ($puede_pedir): ?><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="pedir_modulo"><input type="hidden" name="modulo" value="<?= e($k) ?>"><button class="btn btn-chico">Pedir este módulo</button></form><?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <p class="mod-total">Su mensual: plan <?= dinero($m['precio_plan']) ?><?= $m['precio_extra'] > 0 ? ' + módulos extra ' . dinero($m['precio_extra']) : '' ?><?= $m['precio_deportes'] > 0 ? ' + deportes ' . dinero($m['precio_deportes']) : '' ?><?= $m['descuento'] > 0 ? ' − descuentos ' . dinero($m['descuento']) : '' ?> = <b><?= dinero($m['mensual']) ?> al mes</b><?= $m['precio_acordado'] !== null ? ' (precio acordado con ' . e(ajuste('empresa', 'QF Producciones')) . ')' : '' ?></p>
    </div>
    <?php
    return (string)ob_get_clean();
}
