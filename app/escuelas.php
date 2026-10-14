<?php
/*
 * VERSIÓN 11 — MÓDULO DE ESCUELAS (fútbol y otros deportes).
 * Una escuela es una fila de "ligas" con tipo = 'escuela'. Todo lo demás va en tablas esc_*.
 */

function es_escuela(?array $l): bool
{
    return $l !== null && ($l['tipo'] ?? 'liga') === 'escuela';
}

/** (v19) Deportes de una escuela: vienen del catálogo multideporte (multideporte.php). */
function deportes_escuela(): array
{
    return array_map(fn($x) => $x[0], catalogo_deportes());
}

/* ============================================================
 * CATEGORÍAS (Sub-N por año de nacimiento, o abierta)
 * ============================================================ */

/** "Sub-N" de un alumno: N = año actual − año de nacimiento (ej. nacido en 2016 → Sub-10 en 2026). */
function sub_de(string $fecha_nac, ?int $anio = null): string
{
    if (!preg_match('/^(\d{4})-/', $fecha_nac, $m)) return '—';
    return 'Sub-' . max(1, ($anio ?? (int)date('Y')) - (int)$m[1]);
}

function texto_categoria_grupo(array $g): string
{
    if ($g['categoria'] === 'abierta' || !$g['anio_desde']) return 'Categoría abierta';
    $a = (int)date('Y');
    $d = (int)$g['anio_desde'];
    $h = (int)($g['anio_hasta'] ?: $d);
    [$d, $h] = [min($d, $h), max($d, $h)];
    return $d === $h ? 'Sub-' . ($a - $d) . ' (nacidos en ' . $d . ')' : 'Sub-' . ($a - $h) . ' a Sub-' . ($a - $d) . ' (nacidos ' . $d . '–' . $h . ')';
}

/** Grupo sugerido para una fecha de nacimiento (el primero cuyo rango de años la incluya). */
function grupo_sugerido(int $lid, string $fecha_nac): ?array
{
    if (!preg_match('/^(\d{4})-/', $fecha_nac, $m)) return null;
    $y = (int)$m[1];
    foreach (filas("SELECT * FROM esc_grupos WHERE liga_id = ? AND activo = 1 AND categoria = 'sub' ORDER BY id", [$lid]) as $g) {
        $d = (int)$g['anio_desde'];
        $h = (int)($g['anio_hasta'] ?: $d);
        if ($y >= min($d, $h) && $y <= max($d, $h)) return $g;
    }
    return fila("SELECT * FROM esc_grupos WHERE liga_id = ? AND activo = 1 AND categoria = 'abierta' ORDER BY id LIMIT 1", [$lid]);
}

function dias_semana(): array
{
    return [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
}

function texto_horario(array $g): string
{
    $d = array_filter(array_map('intval', explode(',', (string)$g['dias'])));
    $t = implode(', ', array_map(fn($x) => dias_semana()[$x] ?? '', $d));
    return trim($t . ($g['hora_inicio'] !== '' ? ' · ' . $g['hora_inicio'] . ($g['hora_fin'] !== '' ? '–' . $g['hora_fin'] : '') : '')) ?: 'Sin horario';
}

/* ============================================================
 * DATOS INICIALES DE UNA ESCUELA NUEVA
 * ============================================================ */

function tests_por_defecto(): array
{
    return [
        ['Velocidad 30 metros', 's', 'menor', 'fisico', 'Salida parado, cronometrar 30 m.'],
        ['Salto horizontal', 'cm', 'mayor', 'fisico', 'Salto a pies juntos desde parado.'],
        ['Salto vertical', 'cm', 'mayor', 'fisico', 'Salto con contramovimiento.'],
        ['Abdominales 1 minuto', 'rep', 'mayor', 'fisico', ''],
        ['Flexibilidad (sit and reach)', 'cm', 'mayor', 'fisico', ''],
        ['Resistencia Yo-Yo', 'm', 'mayor', 'fisico', 'Metros recorridos en el test Yo-Yo intermitente.'],
        ['Conducción en slalom', 's', 'menor', 'tecnico', 'Conducir el balón entre 6 conos ida y vuelta.'],
        ['Dominio del balón (toques)', 'rep', 'mayor', 'tecnico', 'Toques seguidos sin que caiga el balón.'],
    ];
}

function habilidades_por_defecto(): array
{
    return ['Control', 'Pase', 'Conducción', 'Regate', 'Remate', 'Cabeceo', 'Defensa', 'Visión de juego', 'Actitud y disciplina'];
}

/** Crea sede, tests y habilidades de ejemplo si la escuela aún no los tiene. */
function preparar_escuela(int $lid): void
{
    if (!valor('SELECT COUNT(*) FROM esc_sedes WHERE liga_id = ?', [$lid])) {
        q('INSERT INTO esc_sedes (liga_id, nombre) VALUES (?, ?)', [$lid, 'Sede principal']);
    }
    // (v19) Tests, habilidades y ejercicios de fábrica de cada deporte que la escuela enseña (y los generales)
    if ($liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid])) preparar_deportes_escuela($liga);
}

/* ============================================================
 * ALUMNOS
 * ============================================================ */

function alumno_de_escuela(int $id, int $lid): array
{
    return fila('SELECT a.*, g.nombre AS grupo, g.categoria, g.anio_desde, g.anio_hasta, g.mensualidad AS grupo_mensualidad, g.sede_id
                 FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.id = ? AND a.liga_id = ?', [$id, $lid])
        ?? throw new RuntimeException('Alumno no encontrado.');
}

function nombre_alumno(array $a): string
{
    return trim($a['nombres'] . ' ' . $a['apellidos']);
}

function foto_alumno(array $a, string $clase = 'foto'): string
{
    if ($a['foto'] !== '') return '<img class="' . e($clase) . '" src="' . e($a['foto']) . '" alt="' . e(nombre_alumno($a)) . '" loading="lazy">';
    return '<span class="' . e($clase) . ' foto-vacia">' . e(iniciales(nombre_alumno($a))) . '</span>';
}

/** Lee y valida los campos del formulario de alumno. */
function leer_alumno_post(int $lid): array
{
    $d = [];
    foreach (['nombres' => 60, 'apellidos' => 60, 'genero' => 10, 'posicion' => 30, 'pie' => 12, 'direccion' => 160, 'colegio' => 100,
        'instrumento' => 60, 'talla_vestuario' => 20, 'carril' => 20, // (TuAcademia)
        'representante_nombre' => 80, 'representante_telefono' => 30, 'representante_email' => 120, 'parentesco' => 30,
        'tipo_sangre' => 5, 'alergias' => 200, 'condiciones' => 200, 'medicamentos' => 200, 'seguro' => 100,
        'emergencia_nombre' => 80, 'emergencia_telefono' => 30, 'notas' => 300] as $k => $max) {
        $d[$k] = mb_substr(trim((string)post($k)), 0, $max);
    }
    if (mb_strlen($d['nombres']) < 2) throw new RuntimeException('Escriba los nombres del alumno.');
    $d['cedula'] = strtoupper(preg_replace('/[\s.]+/', '', post('cedula')));
    if ($d['cedula'] !== '' && ($err = error_documento($d['cedula']))) throw new RuntimeException('Cédula del alumno: ' . $err);
    $d['representante_cedula'] = strtoupper(preg_replace('/[\s.]+/', '', post('representante_cedula')));
    if ($d['representante_cedula'] !== '' && ($err = error_documento($d['representante_cedula']))) throw new RuntimeException('Cédula del representante: ' . $err);
    $fn = post('fecha_nac');
    $d['fecha_nac'] = $fn !== '' && strtotime($fn) ? date('Y-m-d', strtotime($fn)) : '';
    if ($d['fecha_nac'] === '') throw new RuntimeException('Escriba la fecha de nacimiento (sirve para la categoría Sub).');
    if (es_menor($d['fecha_nac']) && (mb_strlen($d['representante_nombre']) < 5 || $d['representante_cedula'] === '' || strlen(preg_replace('/\D/', '', $d['representante_telefono'])) < 7)) {
        throw new RuntimeException('Es menor de edad: escriba el nombre, la cédula y el teléfono del representante.');
    }
    $gid = (int)post('grupo_id');
    $d['grupo_id'] = $gid && fila('SELECT id FROM esc_grupos WHERE id = ? AND liga_id = ?', [$gid, $lid]) ? $gid : null;
    $d['beca'] = max(0, min(100, (float)str_replace(',', '.', post('beca'))));
    $d['mensualidad_especial'] = post('mensualidad_especial') === '' ? null : max(0, round((float)str_replace(',', '.', post('mensualidad_especial')), 2));
    $d['dia_vence'] = post('dia_vence') === '' ? null : entero(post('dia_vence'), 1, 28);
    $d['fecha_ingreso'] = post('fecha_ingreso') !== '' && strtotime(post('fecha_ingreso')) ? date('Y-m-d', strtotime(post('fecha_ingreso'))) : date('Y-m-d');
    $d['foto_publica'] = post('foto_publica') === '1' ? 1 : 0;
    return $d;
}

/** Carnet del alumno (token para el QR). */
function carnet_alumno(array $a): string
{
    if ($a['carnet_token'] !== '') return $a['carnet_token'];
    $t = bin2hex(random_bytes(10));
    q('UPDATE esc_alumnos SET carnet_token = ? WHERE id = ?', [$t, $a['id']]);
    return $t;
}

function url_carnet_alumno(array $a): string
{
    return url_base() . 'carnet.php?a=' . carnet_alumno($a);
}

/* ============================================================
 * PAGOS (mensualidades con vencimiento, matrícula, uniformes…)
 * ============================================================ */

function tipos_cargo_escuela(): array
{
    if (PRODUCTO === 'academia') { // (TuAcademia) pensión, vestuario, recital, paquete de clases
        return ['mensualidad' => 'Pensión', 'matricula' => 'Matrícula', 'vestuario' => 'Vestuario', 'recital' => 'Recital / presentación', 'paquete' => 'Paquete de clases', 'uniforme' => 'Uniforme',
            'material' => 'Material (libros, partituras)', 'torneo' => 'Torneo / viaje', 'tienda' => 'Tienda', 'seguro' => 'Seguro', 'examen' => 'Examen de nivel', 'otro' => 'Otro'];
    }
    return ['mensualidad' => 'Mensualidad', 'matricula' => 'Matrícula', 'uniforme' => 'Uniforme', 'torneo' => 'Torneo / viaje', 'tienda' => 'Tienda', 'seguro' => 'Seguro', 'examen' => 'Examen de grado', 'otro' => 'Otro'];
}

/** Valor base de la mensualidad del alumno: especial > la de su grupo > la general de la escuela. */
function mensualidad_base(array $a, array $liga): float
{
    if ($a['mensualidad_especial'] !== null && $a['mensualidad_especial'] !== '') return (float)$a['mensualidad_especial'];
    $g = $a['grupo_id'] ? fila('SELECT mensualidad FROM esc_grupos WHERE id = ?', [$a['grupo_id']]) : null;
    if ($g && $g['mensualidad'] !== null) return (float)$g['mensualidad'];
    return (float)$liga['esc_mensualidad'];
}

/** Descuento (%) del alumno: beca + descuento por hermano (a partir del 2º hijo activo del mismo representante). */
function descuento_alumno(array $a, array $liga): float
{
    $pct = (float)$a['beca'];
    if ((float)$liga['esc_desc_hermano'] > 0 && $a['representante_cedula'] !== '') {
        $primero = (int)valor("SELECT MIN(id) FROM esc_alumnos WHERE liga_id = ? AND representante_cedula = ? AND estado = 'activo'", [$a['liga_id'], $a['representante_cedula']]);
        if ($primero && $primero !== (int)$a['id']) $pct += (float)$liga['esc_desc_hermano'];
    }
    return min(100, $pct);
}

function nombre_mes(string $periodo): string
{
    $m = ['01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril', '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto', '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'];
    return ($m[substr($periodo, 5, 2)] ?? '') . ' ' . substr($periodo, 0, 4);
}

/** Crea la mensualidad del mes para cada alumno activo (no se repite). Devuelve cuántas creó. */
function generar_mensualidades(array $liga, ?string $periodo = null): int
{
    $periodo = $periodo ?? date('Y-m');
    $fin = date('Y-m-t', strtotime($periodo . '-01'));
    $n = 0;
    foreach (filas("SELECT * FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo' AND (fecha_ingreso = '' OR fecha_ingreso <= ?)", [$liga['id'], $fin]) as $a) {
        $dia = min((int)($a['dia_vence'] ?: $liga['esc_dia_vence'] ?: 5), (int)date('t', strtotime($periodo . '-01')));
        if (!fila("SELECT id FROM esc_cargos WHERE alumno_id = ? AND tipo = 'mensualidad' AND periodo = ? AND COALESCE(grupo_id, 0) = 0", [$a['id'], $periodo])) {
            $base = mensualidad_base($a, $liga);
            if ($base > 0) {
                $desc = round($base * descuento_alumno($a, $liga) / 100, 2);
                q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, descuento, vence, estado) VALUES (?,?, 'mensualidad', ?, ?, ?, ?, ?, ?)", [
                    $liga['id'], $a['id'], $periodo, (PRODUCTO === 'academia' ? 'Pensión de ' : 'Mensualidad de ') . nombre_mes($periodo), $base, $desc, sprintf('%s-%02d', $periodo, $dia), $desc >= $base ? 'pagado' : 'pendiente',
                ]);
                $n++;
            }
        }
        // (v21) Multideporte: una mensualidad por cada grupo extra (otro deporte), desde el mes en que entró,
        // con el descuento por segundo deporte de la escuela (la beca y el descuento por hermano solo van en la principal).
        foreach (filas("SELECT ag.grupo_id, ag.desde, g.nombre, g.mensualidad FROM esc_alumno_grupos ag JOIN esc_grupos g ON g.id = ag.grupo_id
                        WHERE ag.alumno_id = ? AND g.activo = 1 AND (ag.desde = '' OR substr(ag.desde, 1, 7) <= ?)", [$a['id'], $periodo]) as $gx) {
            if (fila("SELECT id FROM esc_cargos WHERE alumno_id = ? AND tipo = 'mensualidad' AND periodo = ? AND grupo_id = ?", [$a['id'], $periodo, $gx['grupo_id']])) continue;
            $base = ($gx['mensualidad'] !== null && $gx['mensualidad'] !== '') ? (float)$gx['mensualidad'] : (float)$liga['esc_mensualidad'];
            if ($base <= 0) continue;
            $desc = round($base * min(100, (float)($liga['esc_desc_segundo'] ?? 0)) / 100, 2);
            q("INSERT INTO esc_cargos (liga_id, alumno_id, grupo_id, tipo, periodo, descripcion, monto, descuento, vence, estado) VALUES (?,?,?, 'mensualidad', ?, ?, ?, ?, ?, ?)", [
                $liga['id'], $a['id'], $gx['grupo_id'], $periodo, (PRODUCTO === 'academia' ? 'Pensión de ' : 'Mensualidad de ') . nombre_mes($periodo) . ' · ' . $gx['nombre'], $base, $desc, sprintf('%s-%02d', $periodo, $dia), $desc >= $base ? 'pagado' : 'pendiente',
            ]);
            $n++;
        }
    }
    return $n;
}

/** Cargo suelto (matrícula, uniforme, torneo…) para uno o varios alumnos. */
function crear_cargo_escuela(array $liga, int $alumno_id, string $tipo, string $descripcion, float $monto, string $vence, string $por): int
{
    q('INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, vence, registrado_por) VALUES (?,?,?,?,?,?,?,?)', [
        $liga['id'], $alumno_id, array_key_exists($tipo, tipos_cargo_escuela()) ? $tipo : 'otro', substr($vence ?: date('Y-m-d'), 0, 7), mb_substr($descripcion, 0, 150), round($monto, 2), $vence ?: date('Y-m-d'), $por,
    ]);
    return (int)db()->lastInsertId();
}

/** Semáforo de pagos de un alumno: al_dia / por_vencer (5 días) / vencido. */
function estado_pagos(int $alumno_id): array
{
    $hoy = date('Y-m-d');
    $r = fila("SELECT COALESCE(SUM(monto - descuento), 0) AS deuda,
                      COALESCE(SUM(CASE WHEN vence < ? THEN monto - descuento END), 0) AS vencido,
                      SUM(CASE WHEN vence < ? THEN 1 ELSE 0 END) AS n_vencidos,
                      SUM(CASE WHEN vence >= ? AND vence <= ? THEN 1 ELSE 0 END) AS n_por_vencer,
                      MIN(vence) AS proximo
               FROM esc_cargos WHERE alumno_id = ? AND estado = 'pendiente'", [$hoy, $hoy, $hoy, date('Y-m-d', strtotime('+5 days')), $alumno_id]);
    $estado = (int)$r['n_vencidos'] > 0 ? 'vencido' : ((int)$r['n_por_vencer'] > 0 ? 'por_vencer' : 'al_dia');
    return ['estado' => $estado, 'deuda' => (float)$r['deuda'], 'vencido' => (float)$r['vencido'], 'n_vencidos' => (int)$r['n_vencidos'], 'proximo' => $r['proximo']];
}

function chip_pago(string $estado): string
{
    return ['al_dia' => '<span class="semaforo s-verde">Al día</span>', 'por_vencer' => '<span class="semaforo s-amarillo">Por vencer</span>', 'vencido' => '<span class="semaforo s-rojo">Vencido</span>'][$estado] ?? '';
}

/** Cobra uno o varios cargos pendientes: los marca pagados con un mismo número de recibo. */
function cobrar_cargos(array $liga, array $ids, string $forma, string $por, string $fecha = ''): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) throw new RuntimeException('Marque lo que va a cobrar.');
    $in = implode(',', $ids);
    $n = (int)valor("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente' AND id IN ($in)", [$liga['id']]);
    if (!$n) throw new RuntimeException('Esos valores ya estaban pagados.');
    $pdo = db();
    $pdo->beginTransaction();
    $recibo = (int)valor('SELECT recibo_siguiente FROM ligas WHERE id = ?', [$liga['id']]);
    q('UPDATE ligas SET recibo_siguiente = recibo_siguiente + 1 WHERE id = ?', [$liga['id']]);
    q("UPDATE esc_cargos SET estado = 'pagado', pagado_en = ?, forma_pago = ?, recibo = ?, registrado_por = ?, comprobante_estado = CASE WHEN comprobante <> '' THEN 'aprobado' ELSE comprobante_estado END
       WHERE liga_id = ? AND estado = 'pendiente' AND id IN ($in)", [$fecha ?: date('Y-m-d'), array_key_exists($forma, formas_pago()) ? $forma : 'efectivo', $recibo, $por, $liga['id']]);
    $pdo->commit();
    acad_tg_pago_recibido($liga, $recibo, $ids); // (TuAcademia, 14-oct) aviso por Telegram a la familia (nunca falla)
    return $recibo;
}

/* ============================================================
 * ASISTENCIA Y CLASES
 * ============================================================ */

function estados_asistencia(): array
{
    return ['P' => 'Presente', 'T' => 'Tarde', 'J' => 'Justificó', 'A' => 'Faltó'];
}

/** Porcentaje de asistencia de un alumno (presente o tarde) en los últimos N días. */
function asistencia_alumno(int $aid, int $dias = 90): array
{
    $r = fila("SELECT COUNT(*) AS total, SUM(CASE WHEN x.estado IN ('P','T') THEN 1 ELSE 0 END) AS vino
               FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE x.alumno_id = ? AND s.fecha >= ?", [$aid, date('Y-m-d', strtotime("-$dias days"))]);
    $t = (int)$r['total'];
    return ['total' => $t, 'vino' => (int)$r['vino'], 'pct' => $t ? (int)round(100 * (int)$r['vino'] / $t) : null];
}

/** Alumnos de un grupo que pueden entrenar (activos). */
function alumnos_grupo(int $gid): array
{
    // (v19) Incluye a los alumnos que tienen este grupo como deporte extra
    return filas("SELECT * FROM esc_alumnos a WHERE " . sql_en_grupo('a', $gid) . " AND a.estado = 'activo' ORDER BY a.apellidos, a.nombres");
}

/* ============================================================
 * PROFESORES: cuánto se les paga
 * ============================================================ */

function tipos_pago_profesor(): array
{
    return ['mensual' => 'Sueldo fijo al mes', 'clase' => 'Por clase dictada', 'alumno' => 'Por alumno activo'];
}

/** Liquidación del mes de un profesor: clases que dictó, alumnos en sus grupos y cuánto se le debe. */
function liquidacion_profesor(array $p, string $periodo): array
{
    $clases = (int)valor("SELECT COUNT(*) FROM esc_sesiones WHERE profesor_id = ? AND profesor_asistio = 1 AND estado = 'dictada' AND substr(fecha, 1, 7) = ?", [$p['id'], $periodo]);
    $faltas = (int)valor("SELECT COUNT(*) FROM esc_sesiones WHERE profesor_id = ? AND profesor_asistio = 0 AND estado = 'dictada' AND substr(fecha, 1, 7) = ?", [$p['id'], $periodo]);
    $alumnos = (int)valor("SELECT COUNT(DISTINCT a.id) FROM esc_alumnos a JOIN esc_grupos g ON (g.id = a.grupo_id OR g.id IN (SELECT grupo_id FROM esc_alumno_grupos WHERE alumno_id = a.id)) WHERE g.profesor_id = ? AND a.estado = 'activo'", [$p['id']]);
    $monto = match ($p['tipo_pago']) {
        'clase' => $clases * (float)$p['valor_pago'],
        'alumno' => $alumnos * (float)$p['valor_pago'],
        default => (float)$p['valor_pago'],
    };
    // (v12) Con rol de pago se compara lo que le correspondía (honorario), no el neto después de descuentos
    $pagado = (float)valor('SELECT COALESCE(SUM(CASE WHEN base > 0 OR bonos > 0 THEN base ELSE monto END), 0) FROM esc_pagos_profesor WHERE profesor_id = ? AND periodo = ? AND anulado = 0', [$p['id'], $periodo]);
    $dias = array_column(filas("SELECT DISTINCT fecha FROM esc_sesiones WHERE profesor_id = ? AND profesor_asistio = 1 AND estado = 'dictada' AND substr(fecha, 1, 7) = ? ORDER BY fecha", [$p['id'], $periodo]), 'fecha');
    return ['clases' => $clases, 'faltas' => $faltas, 'alumnos' => $alumnos, 'monto' => round($monto, 2), 'pagado' => $pagado, 'saldo' => round($monto - $pagado, 2), 'dias' => $dias];
}

/* ============================================================
 * MEDIDAS, TESTS Y EVALUACIÓN TÉCNICA
 * ============================================================ */

function imc(?float $peso, ?float $talla_cm): ?float
{
    if (!$peso || !$talla_cm) return null;
    $m = $talla_cm / 100;
    return round($peso / ($m * $m), 1);
}

function campos_medicion(): array
{
    return ['peso' => ['Peso', 'kg'], 'talla' => ['Talla', 'cm'], 'cintura' => ['Cintura', 'cm'], 'cadera' => ['Cadera', 'cm'], 'brazo' => ['Brazo', 'cm'],
        'muslo' => ['Muslo', 'cm'], 'grasa' => ['% grasa', '%'], 'envergadura' => ['Envergadura', 'cm']];
}

/** Resultados de un test en un grupo o en toda la escuela, el mejor de cada alumno, ordenados (ranking). */
function ranking_test(array $t, int $lid, ?int $gid = null): array
{
    $agg = $t['mejor'] === 'menor' ? 'MIN' : 'MAX';
    $rows = filas("SELECT a.id, a.nombres, a.apellidos, a.fecha_nac, a.foto, a.grupo_id, $agg(r.valor) AS valor
                   FROM esc_resultados r JOIN esc_alumnos a ON a.id = r.alumno_id
                   WHERE r.test_id = ? AND a.liga_id = ? AND a.estado = 'activo'" . ($gid ? ' AND ' . sql_en_grupo('a', (int)$gid) : '') . "
                   GROUP BY a.id ORDER BY valor " . ($t['mejor'] === 'menor' ? 'ASC' : 'DESC'), [$t['id'], $lid]);
    return $rows;
}

/** Valores de la última evaluación técnica: [habilidad_id => valor]. */
function ultima_eval_tecnica(int $aid, ?array $ids_habs = null): ?array
{
    // (v19) Con $ids_habs, la última evaluación que califica alguna de esas habilidades (la de ese deporte)
    foreach (filas('SELECT * FROM esc_eval_tecnica WHERE alumno_id = ? ORDER BY fecha DESC, id DESC LIMIT 40', [$aid]) as $e) {
        $e['valores'] = json_decode($e['valores'], true) ?: [];
        if ($ids_habs === null || array_intersect(array_map('intval', array_keys($e['valores'])), $ids_habs)) return $e;
    }
    return null;
}

/** (v19) Las 2 últimas evaluaciones técnicas del alumno en un deporte (según sus habilidades). */
function evals_tecnica_deporte(int $aid, array $habs, int $n = 2, string $hasta = ''): array
{
    $ids = array_map(fn($h) => (int)$h['id'], $habs);
    $r = [];
    foreach (filas('SELECT * FROM esc_eval_tecnica WHERE alumno_id = ?' . ($hasta ? ' AND fecha <= ?' : '') . ' ORDER BY fecha DESC, id DESC LIMIT 60', $hasta ? [$aid, $hasta] : [$aid]) as $e) {
        if (array_intersect(array_map('intval', array_keys((array)json_decode($e['valores'], true))), $ids)) $r[] = $e;
        if (count($r) >= $n) break;
    }
    return $r;
}

/** Gráfico de araña (radar) en SVG, valores de 1 a $max. */
function svg_radar(array $etiquetas, array $valores, int $max = 5, ?array $anteriores = null): string
{
    $n = count($etiquetas);
    if ($n < 3) return '';
    $cx = 160; $cy = 150; $r = 100;
    $pt = function (int $i, float $v) use ($n, $cx, $cy, $r, $max) {
        $ang = -M_PI / 2 + 2 * M_PI * $i / $n;
        return [$cx + cos($ang) * $r * $v / $max, $cy + sin($ang) * $r * $v / $max];
    };
    $h = '<svg viewBox="-80 0 480 300" class="radar" role="img" aria-label="Evaluación técnica">';
    for ($k = 1; $k <= $max; $k++) {
        $p = [];
        for ($i = 0; $i < $n; $i++) { [$x, $y] = $pt($i, $k); $p[] = round($x, 1) . ',' . round($y, 1); }
        $h .= '<polygon points="' . implode(' ', $p) . '" fill="none" stroke="#dfe5e2"/>';
    }
    for ($i = 0; $i < $n; $i++) {
        [$x, $y] = $pt($i, $max);
        $h .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . round($x, 1) . '" y2="' . round($y, 1) . '" stroke="#dfe5e2"/>';
        [$lx, $ly] = $pt($i, $max + 0.9);
        $anc = abs($lx - $cx) < 8 ? 'middle' : ($lx > $cx ? 'start' : 'end');
        $h .= '<text x="' . round($lx, 1) . '" y="' . round($ly + 4, 1) . '" font-size="10" text-anchor="' . $anc . '" fill="#44504c">' . e(mb_substr($etiquetas[$i], 0, 16)) . '</text>';
    }
    foreach ([[$anteriores, '#9aa6a2', '0.12', '4 3'], [$valores, 'var(--acento)', '0.28', '']] as [$vals, $col, $op, $dash]) {
        if (!$vals) continue;
        $p = [];
        for ($i = 0; $i < $n; $i++) { [$x, $y] = $pt($i, (float)($vals[$i] ?? 0)); $p[] = round($x, 1) . ',' . round($y, 1); }
        $h .= '<polygon points="' . implode(' ', $p) . '" fill="' . $col . '" fill-opacity="' . $op . '" stroke="' . $col . '" stroke-width="2"' . ($dash ? ' stroke-dasharray="' . $dash . '"' : '') . '/>';
    }
    return $h . '</svg>';
}

/** Gráfico de línea simple en SVG: [[etiqueta, valor], ...]. */
function svg_linea(array $puntos, string $unidad = ''): string
{
    $puntos = array_values(array_filter($puntos, fn($p) => $p[1] !== null && $p[1] !== ''));
    if (count($puntos) < 2) return '';
    $vals = array_map(fn($p) => (float)$p[1], $puntos);
    $min = min($vals); $max = max($vals);
    if ($max - $min < 0.001) { $max += 1; $min -= 1; }
    $w = 320; $h = 140; $pad = 28;
    $n = count($puntos);
    $x = fn($i) => $pad + ($w - 2 * $pad) * $i / max(1, $n - 1);
    $y = fn($v) => $h - $pad - ($h - 2 * $pad) * ($v - $min) / ($max - $min);
    $s = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="linea" role="img">';
    $p = [];
    foreach ($vals as $i => $v) $p[] = round($x($i), 1) . ',' . round($y($v), 1);
    $s .= '<polyline points="' . implode(' ', $p) . '" fill="none" stroke="var(--acento)" stroke-width="2.5"/>';
    foreach ($vals as $i => $v) {
        $s .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="3.5" fill="var(--acento)"/>';
        $s .= '<text x="' . round($x($i), 1) . '" y="' . round($y($v) - 8, 1) . '" font-size="10" text-anchor="middle" fill="#15201c">' . e(rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')) . '</text>';
        $s .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" font-size="9" text-anchor="middle" fill="#6b7572">' . e(date('d/m/y', strtotime($puntos[$i][0]))) . '</text>';
    }
    return $s . '</svg>';
}

/* ============================================================
 * PORTAL DE LA FAMILIA (representante)
 * ============================================================ */

function codigo_familia(int $lid, string $cedula): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
    q('INSERT INTO esc_familias (liga_id, cedula, codigo) VALUES (?,?,?) ON CONFLICT(liga_id, cedula) DO UPDATE SET codigo = excluded.codigo', [$lid, $cedula, $c]);
    return $c;
}

function mensaje_familia(array $a, array $liga, string $codigo): string
{
    return 'Hola ' . $a['representante_nombre'] . ', ya puede ver en su celular la asistencia, los pagos, las evaluaciones y el carnet de ' . $a['nombres'] . ' en ' . $liga['nombre'] . ".\n\n"
        . '1) Abra: ' . url_base() . 'familia.php?c=' . $codigo . "\n2) Cree su clave de 4 a 6 números.\n3) Después entra con su cédula y esa clave en " . url_base() . 'familia.php';
}

/* ============================================================
 * CONEXIÓN CON UNA LIGA DE TUACADEMIA (opcional, por grupo)
 * ============================================================ */

/** Campeonatos de ligas de TuAcademia con inscripción de equipos abierta hoy. */
function campeonatos_abiertos(): array
{
    return filas("SELECT t.id, t.nombre, t.temporada, t.equipos_hasta, t.categoria, l.id AS liga_id, l.nombre AS liga, l.ciudad
                  FROM torneos t JOIN ligas l ON l.id = t.liga_id
                  WHERE l.activa = 1 AND COALESCE(l.tipo, 'liga') = 'liga' AND COALESCE(l.es_demo, 0) = 0 AND t.equipos_hasta <> '' AND t.equipos_hasta >= ? AND t.estado <> 'finalizado'
                  ORDER BY l.nombre, t.nombre", [date('Y-m-d')]);
}

/**
 * Al aprobar la liga el equipo que mandó una escuela, se cargan los alumnos activos del grupo como jugadores
 * (la escuela ya tiene la autorización de datos de cada uno).
 */
function cargar_alumnos_en_equipo(int $grupo_id, int $equipo_id, string $escuela): int
{
    $n = 0;
    foreach (alumnos_grupo($grupo_id) as $a) {
        if ($a['cedula'] !== '' && fila('SELECT j.id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.cedula = ? AND j.activo = 1 AND e.liga_id = (SELECT liga_id FROM equipos WHERE id = ?)', [$a['cedula'], $equipo_id])) continue;
        $menor = $a['fecha_nac'] !== '' && es_menor($a['fecha_nac']);
        q('INSERT INTO jugadores (equipo_id, nombres, apellidos, cedula, posicion, fecha_nac, foto, consentimiento, consentimiento_por, representante_nombre, representante_cedula, representante_telefono, foto_publica)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$equipo_id, $a['nombres'], $a['apellidos'], $a['cedula'], $a['posicion'], $a['fecha_nac'], $a['foto'], $a['consentimiento'] ?: date('Y-m-d H:i:s'),
            ($menor ? 'representante' : 'jugador') . ' (vía escuela ' . $escuela . ')', $a['representante_nombre'], $a['representante_cedula'], $a['representante_telefono'], (int)$a['foto_publica']]);
        $n++;
    }
    q('UPDATE esc_grupos SET equipo_destino_id = ? WHERE id = ?', [$equipo_id, $grupo_id]);
    return $n;
}

/* ============================================================
 * AYUDAS PARA LAS PANTALLAS
 * ============================================================ */

/** Grupos que ve el usuario: el perfil "Profesor" solo ve los suyos. */
function grupos_visibles(int $lid): array
{
    $sql = 'SELECT g.*, s.nombre AS sede, p.nombre AS profesor, (SELECT COUNT(*) FROM esc_alumnos a WHERE (a.grupo_id = g.id OR a.id IN (SELECT alumno_id FROM esc_alumno_grupos WHERE grupo_id = g.id)) AND a.estado = \'activo\') AS n_alumnos
            FROM esc_grupos g LEFT JOIN esc_sedes s ON s.id = g.sede_id LEFT JOIN esc_profesores p ON p.id = g.profesor_id WHERE g.liga_id = ? AND g.activo = 1';
    $par = [$lid];
    if (perfil_actual() === 'profesor') {
        $sql .= ' AND g.profesor_id IN (SELECT id FROM esc_profesores WHERE usuario_id = ?)';
        $par[] = (int)usuario()['id'];
    }
    return filas($sql . ' ORDER BY s.nombre, g.anio_desde DESC, g.nombre', $par);
}

function grupo_de_escuela(int $gid, int $lid): array
{
    $g = fila('SELECT * FROM esc_grupos WHERE id = ? AND liga_id = ?', [$gid, $lid]) ?? throw new RuntimeException('Grupo no encontrado.');
    if (perfil_actual() === 'profesor' && !fila('SELECT id FROM esc_profesores WHERE id = ? AND usuario_id = ?', [$g['profesor_id'], usuario()['id']])) {
        throw new RuntimeException('Ese grupo no es suyo.');
    }
    return $g;
}

function opciones_grupos(array $grupos, ?int $sel = null, bool $vacio = true): string
{
    $h = $vacio ? '<option value="">— sin grupo —</option>' : '';
    foreach ($grupos as $g) {
        $ico = isset($g['liga_id']) ? icono_deporte_escuela(deporte_grupo($g)) . ' ' : ''; // (v21) ícono del deporte del grupo
        $h .= '<option value="' . (int)$g['id'] . '"' . ((int)$sel === (int)$g['id'] ? ' selected' : '') . '>' . e($ico . $g['nombre'] . (isset($g['sede']) && $g['sede'] ? ' · ' . $g['sede'] : '')) . '</option>';
    }
    return $h;
}

/** Pestañas de la ficha del alumno. */
function pestanas_alumno(int $aid, string $activa): void
{
    $t = ['datos' => 'Datos', 'desarrollo' => '📈 Desarrollo', 'salud' => '🩺 Salud y lesiones', 'pagos' => '💵 Pagos', 'asistencia' => '📅 Asistencia y diario', 'medidas' => '📏 Medidas', 'tests' => '⏱ Tests', 'tecnica' => '⭐ Técnica', 'libreta' => '📘 Informe'];
    // (v17) Divisiones formativas: pestaña del club (partidos, maduración, cuerpo técnico, valoración y colegio)
    if (function_exists('formativas_activas') && formativas_activas(liga_trabajo())) $t = array_slice($t, 0, 2, true) + ['club' => '🏆 Club'] + array_slice($t, 2, null, true);
    if (!puede('cobros')) unset($t['pagos']);
    echo '<nav class="pestanas">';
    foreach ($t as $k => $v) echo '<a class="' . ($activa === $k ? 'activa' : '') . '" href="admin.php?p=alumno&amp;id=' . $aid . '&amp;s=' . $k . '">' . e($v) . '</a>';
    echo '</nav>';
}

/** Periodos de la libreta: trimestres del año (ej. 2026-T3). */
function periodo_libreta(?string $fecha = null): string
{
    $t = strtotime($fecha ?? date('Y-m-d'));
    return date('Y', $t) . '-T' . (int)ceil((int)date('n', $t) / 3);
}

function rango_periodo_libreta(string $p): array
{
    [$y, $t] = [(int)substr($p, 0, 4), (int)substr($p, -1)];
    $ini = sprintf('%04d-%02d-01', $y, ($t - 1) * 3 + 1);
    return [$ini, date('Y-m-t', strtotime($ini . ' +2 months'))];
}

function nombre_periodo_libreta(string $p): string
{
    return ['1' => 'Enero – Marzo', '2' => 'Abril – Junio', '3' => 'Julio – Septiembre', '4' => 'Octubre – Diciembre'][substr($p, -1)] . ' ' . substr($p, 0, 4);
}

/* ============================================================
 * DOCUMENTOS IMPRIMIBLES DE LA ESCUELA
 * ============================================================ */

/** (46) Libreta del alumno del trimestre: asistencia, medidas, tests, técnica y comentario del profesor. */
function doc_libreta_alumno(array $a, array $liga, string $per, string $volver): void
{
    // (v17) El informe de desarrollo con gráficos reemplaza a la libreta simple
    if (function_exists('doc_informe_alumno')) { doc_informe_alumno($a, $liga, $per, $volver); return; }
    [$ini, $fin] = rango_periodo_libreta($per);
    $aid = (int)$a['id'];
    $asis = fila("SELECT COUNT(*) AS t, SUM(CASE WHEN x.estado IN ('P','T') THEN 1 ELSE 0 END) AS v, SUM(CASE WHEN x.estado = 'J' THEN 1 ELSE 0 END) AS j
                  FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE x.alumno_id = ? AND s.fecha BETWEEN ? AND ?", [$aid, $ini, $fin]);
    $med = fila('SELECT * FROM esc_mediciones WHERE alumno_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 1', [$aid, $fin]);
    $med_ant = $med ? fila('SELECT * FROM esc_mediciones WHERE alumno_id = ? AND fecha < ? ORDER BY fecha DESC LIMIT 1', [$aid, $med['fecha']]) : null;
    $tests = filas('SELECT * FROM esc_tests WHERE liga_id = ? AND activo = 1 ORDER BY orden, nombre', [$liga['id']]);
    $habs = filas('SELECT * FROM esc_habilidades WHERE liga_id = ? AND activo = 1 ORDER BY orden, id', [$liga['id']]);
    $ev = fila('SELECT * FROM esc_eval_tecnica WHERE alumno_id = ? AND fecha <= ? ORDER BY fecha DESC, id DESC LIMIT 1', [$aid, $fin]);
    $evv = $ev ? (json_decode($ev['valores'], true) ?: []) : [];
    $lb = fila('SELECT * FROM esc_libretas WHERE alumno_id = ? AND periodo = ?', [$aid, $per]);
    $prof = $a['grupo_id'] ? fila('SELECT p.nombre FROM esc_grupos g JOIN esc_profesores p ON p.id = g.profesor_id WHERE g.id = ?', [$a['grupo_id']]) : null;
    $fmt = fn($v) => $v === null || $v === '' ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    doc_inicio('Libreta ' . nombre_alumno($a), $volver);
    ?>
  <div class="cab"><?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo-grande') ?><div><h1><?= e($liga['nombre']) ?></h1><div>LIBRETA DEL ALUMNO · <?= e(mb_strtoupper(nombre_periodo_libreta($per))) ?></div></div></div>
  <div class="perfil" style="margin:10px 0"><?= foto_alumno($a, 'foto-grande') ?><div>
    <h2 style="margin:0"><?= e(nombre_alumno($a)) ?></h2>
    <div><?= e(edad_o_categoria($a, $liga)) ?> · <?= e($a['grupo'] ?? '') ?><?= $prof ? ' · Profesor: ' . e($prof['nombre']) : '' ?></div>
  </div></div>
  <h2>Asistencia</h2>
  <p>Vino a <b><?= (int)$asis['v'] ?></b> de <b><?= (int)$asis['t'] ?></b> entrenamientos<?= (int)$asis['t'] ? ' (<b>' . round(100 * (int)$asis['v'] / (int)$asis['t']) . '%</b>)' : '' ?><?= (int)$asis['j'] ? ' · ' . (int)$asis['j'] . ' falta(s) justificada(s)' : '' ?>.</p>
  <?php if ($med): ?>
  <h2>Medidas corporales</h2>
  <table class="pl"><tr><th></th><?php foreach (campos_medicion() as [$n, $u]): ?><th><?= e($n) ?> (<?= e($u) ?>)</th><?php endforeach; ?><th>IMC</th></tr>
    <?php foreach (array_filter([$med_ant, $med]) as $m): ?><tr><td><?= e(date('d/m/Y', strtotime($m['fecha']))) ?></td><?php foreach (array_keys(campos_medicion()) as $k): ?><td><?= $fmt($m[$k]) ?></td><?php endforeach; ?><td><?= imc($m['peso'] !== null ? (float)$m['peso'] : null, $m['talla'] !== null ? (float)$m['talla'] : null) ?? '—' ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php $filas_t = [];
  foreach ($tests as $t) {
      $r = filas('SELECT valor, fecha FROM esc_resultados WHERE alumno_id = ? AND test_id = ? AND fecha <= ? ORDER BY fecha DESC LIMIT 2', [$aid, $t['id'], $fin]);
      if ($r) $filas_t[] = [$t, $r[0], $r[1] ?? null];
  }
  if ($filas_t): ?>
  <h2>Tests de rendimiento</h2>
  <table class="pl"><tr><th>Test</th><th>Anterior</th><th>Actual</th><th>Evolución</th></tr>
    <?php foreach ($filas_t as [$t, $act, $ant]): $mejoro = $ant ? ($t['mejor'] === 'menor' ? $act['valor'] < $ant['valor'] : $act['valor'] > $ant['valor']) : null; ?>
    <tr><td><?= e($t['nombre']) ?> (<?= e($t['unidad']) ?>)</td><td><?= $ant ? $fmt($ant['valor']) : '—' ?></td><td><b><?= $fmt($act['valor']) ?></b></td><td><?= $mejoro === null ? '' : ($mejoro ? '▲ mejoró' : ($act['valor'] == $ant['valor'] ? '= igual' : '▼ bajó')) ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if ($ev && $habs): ?>
  <h2>Evaluación técnica <small>(<?= e(date('d/m/Y', strtotime($ev['fecha']))) ?>)</small></h2>
  <div style="display:grid;grid-template-columns:320px 1fr;gap:14px;align-items:center">
    <div><?= svg_radar(array_column($habs, 'nombre'), array_map(fn($h) => (float)($evv[$h['id']] ?? 0), $habs)) ?></div>
    <table class="pl"><?php foreach ($habs as $h): ?><tr><td><?= e($h['nombre']) ?></td><td><?= str_repeat('★', (int)($evv[$h['id']] ?? 0)) . str_repeat('☆', 5 - (int)($evv[$h['id']] ?? 0)) ?></td></tr><?php endforeach; ?></table>
  </div>
  <?php endif; ?>
  <h2>Comentario del profesor</h2>
  <div style="border:1px solid #999;min-height:70px;padding:8px;white-space:pre-wrap"><?= e($lb['comentario'] ?? ($ev['comentario'] ?? '')) ?></div>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Profesor<?= $prof ? ': ' . e($prof['nombre']) : '' ?></div><div>Representante</div></div>
    <?php
    doc_fin();
}

/** Recibo de pago de la escuela (uno o varios cargos con el mismo número). */
function doc_recibo_escuela(array $liga, int $n, string $volver): void
{
    $cs = filas("SELECT c.*, a.nombres, a.apellidos, a.representante_nombre, a.representante_cedula, a.cedula FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.recibo = ? AND c.estado = 'pagado' ORDER BY c.alumno_id, c.vence", [$liga['id'], $n]);
    if (!$cs) throw new RuntimeException('Recibo no encontrado.');
    $a = $cs[0];
    doc_inicio('Recibo ' . str_pad((string)$n, 6, '0', STR_PAD_LEFT), $volver);
    doc_cabecera_financiera($liga, 'RECIBO DE PAGO N° ' . str_pad((string)$n, 6, '0', STR_PAD_LEFT));
    ?>
  <div class="meta" style="grid-template-columns:1fr 1fr">
    <div><b>Alumno:</b> <?= e(nombre_alumno($a)) ?></div><div><b>Fecha:</b> <?= e($a['pagado_en']) ?></div>
    <div><b>Representante:</b> <?= e($a['representante_nombre']) ?></div><div><b>C.I.:</b> <?= e($a['representante_cedula'] ?: $a['cedula']) ?></div>
    <div><b>Forma de pago:</b> <?= e(formas_pago()[$a['forma_pago']] ?? $a['forma_pago']) ?></div><div><b>Registró:</b> <?= e($a['registrado_por']) ?></div>
  </div>
  <table class="pl"><tr><th>Concepto</th><th>Valor</th><th>Descuento</th><th>Total</th></tr>
    <?php foreach ($cs as $c): ?><tr><td><?= e($c['descripcion']) ?><?= count(array_unique(array_column($cs, 'alumno_id'))) > 1 ? ' · ' . e(nombre_alumno($c)) : '' ?></td><td><?= dinero($c['monto']) ?></td><td><?= $c['descuento'] > 0 ? dinero($c['descuento']) : '' ?></td><td><?= dinero($c['monto'] - $c['descuento']) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="3" style="text-align:right"><b>TOTAL PAGADO</b></td><td><b><?= dinero(array_sum(array_map(fn($c) => $c['monto'] - $c['descuento'], $cs))) ?></b></td></tr>
  </table>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Recibí conforme · <?= e($liga['nombre']) ?></div><div>Representante</div></div>
    <?php
    doc_fin();
}
