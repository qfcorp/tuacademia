<?php
/*
 * (v28) FEDERACIONES: varias ligas y escuelas (de cualquier deporte) bajo un mismo "paraguas".
 *
 *  - La federación es una cuenta más (ligas.es_federacion = 1) con su propio panel, su página pública y sus propios
 *    campeonatos federativos (inter-ligas) de CUALQUIER disciplina: usa todo lo que ya tiene una liga.
 *  - Cada liga o escuela afiliada (ligas.federacion_id) sigue con sus torneos separados.
 *  - Afiliación: la federación comparte su código; la liga lo escribe en su panel y la federación aprueba.
 *  - La federación ve el resumen de todas sus ligas, busca jugadores por cédula en todas, ve los partidos de la semana,
 *    pone SANCIONES FEDERATIVAS (por cédula, con fecha hasta) que todas las ligas afiliadas respetan en la vocalía,
 *    y trae equipos de una liga afiliada a sus campeonatos federativos.
 */

function es_federacion(?array $l): bool
{
    return $l && !empty($l['es_federacion']);
}

/** Federación a la que está afiliada una liga (solo si la afiliación está aprobada). */
function federacion_de(?array $l): ?array
{
    if (!$l || empty($l['federacion_id']) || ($l['federacion_estado'] ?? '') !== 'afiliada') return null;
    return fila('SELECT * FROM ligas WHERE id = ? AND es_federacion = 1', [(int)$l['federacion_id']]);
}

/** Ligas y escuelas afiliadas. $estado: 'afiliada' | 'pendiente' | '' (todas). */
function ligas_de_federacion(int $fid, string $estado = 'afiliada'): array
{
    return filas('SELECT * FROM ligas WHERE federacion_id = ? AND id <> ? AND activa = 1' . ($estado !== '' ? ' AND federacion_estado = ?' : '') . ' ORDER BY COALESCE(tipo, \'liga\'), nombre',
        $estado !== '' ? [$fid, $fid, $estado] : [$fid, $fid]);
}

/** Ids de la federación + sus afiliadas (para consultas). */
function ids_federacion(int $fid): array
{
    return array_merge([$fid], array_map('intval', array_column(ligas_de_federacion($fid), 'id')));
}

/** Código para afiliarse (se crea la primera vez). */
function codigo_federacion(array $f): string
{
    if (($f['fed_codigo'] ?? '') !== '') return $f['fed_codigo'];
    do {
        $c = 'FED-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    } while (valor('SELECT id FROM ligas WHERE fed_codigo = ?', [$c]));
    q('UPDATE ligas SET fed_codigo = ? WHERE id = ?', [$c, $f['id']]);
    return $c;
}

/** La liga pide afiliarse con el código de la federación. */
function pedir_afiliacion(array $liga, string $codigo): array
{
    if (es_federacion($liga)) throw new RuntimeException('Una federación no se afilia a otra.');
    $f = fila('SELECT * FROM ligas WHERE fed_codigo = ? AND es_federacion = 1 AND activa = 1', [strtoupper(trim($codigo))]) ?? throw new RuntimeException('Código de federación no válido. Pídalo a su federación (empieza con FED-).');
    q("UPDATE ligas SET federacion_id = ?, federacion_estado = 'pendiente' WHERE id = ?", [$f['id'], $liga['id']]);
    aviso_admins($f, '🏛 <b>Pedido de afiliación</b>' . "\n" . e($liga['nombre']) . ' (' . (es_escuela($liga) ? 'escuela' : 'liga') . ')' . "\n\nAprobar: " . url_base() . 'admin.php?p=federacion&s=ligas', 'afil:' . $liga['id'] . ':' . $f['id']);
    bitacora_evento('federacion', 'Pidió afiliarse a ' . $f['nombre'], (int)$liga['id']);
    return $f;
}

/** Resumen numérico de una cuenta (para el panel de la federación). */
function resumen_cuenta_fed(array $l): array
{
    $lid = (int)$l['id'];
    if (es_escuela($l)) {
        return ['Alumnos' => (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo'", [$lid]),
            'Grupos' => (int)valor('SELECT COUNT(*) FROM esc_grupos WHERE liga_id = ? AND activo = 1', [$lid])];
    }
    return ['Equipos' => (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = ?', [$lid]),
        'Jugadores' => (int)valor('SELECT COUNT(*) FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.activo = 1', [$lid]),
        'Campeonatos activos' => (int)valor("SELECT COUNT(*) FROM torneos WHERE liga_id = ? AND estado <> 'finalizado'", [$lid]),
        'Partidos jugados' => (int)valor("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado IN ('jugado','wo')", [$lid])];
}

/** Partidos de todas las ligas de la federación entre dos fechas. */
function partidos_federacion(int $fid, string $desde, string $hasta, int $limite = 200): array
{
    $ids = implode(',', ids_federacion($fid));
    return filas("SELECT p.*, t.nombre AS torneo, t.modalidad, l.nombre AS liga, l.slug, l.id AS liga_id, el.nombre AS local_nombre, ev.nombre AS visitante_nombre, el.escudo AS local_escudo, ev.escudo AS visitante_escudo, el.color AS local_color, ev.color AS visitante_color
                  FROM partidos p JOIN torneos t ON t.id = p.torneo_id JOIN ligas l ON l.id = t.liga_id
                  LEFT JOIN equipos el ON el.id = p.local_id LEFT JOIN equipos ev ON ev.id = p.visitante_id
                  WHERE t.liga_id IN ($ids) AND p.fecha BETWEEN ? AND ? ORDER BY p.fecha, p.hora, l.nombre LIMIT " . (int)$limite, [$desde, $hasta]);
}

/** Busca personas por cédula o nombre en todas las ligas y escuelas de la federación. */
function buscar_en_federacion(int $fid, string $texto): array
{
    $texto = trim($texto);
    if (mb_strlen($texto) < 3) return [];
    $ids = implode(',', ids_federacion($fid));
    $ced = preg_replace('/\D/', '', $texto);
    $like = '%' . $texto . '%';
    $j = filas("SELECT 'Jugador' AS tipo, j.id, j.nombres || ' ' || j.apellidos AS nombre, j.cedula, j.fecha_nac, j.activo, e.nombre AS equipo, l.nombre AS liga, l.slug, e.liga_id
                FROM jugadores j JOIN equipos e ON e.id = j.equipo_id JOIN ligas l ON l.id = e.liga_id
                WHERE e.liga_id IN ($ids) AND (" . ($ced !== '' && strlen($ced) >= 6 ? 'j.cedula = ? OR ' : '') . "(j.nombres || ' ' || j.apellidos) LIKE ?) ORDER BY l.nombre LIMIT 100",
        $ced !== '' && strlen($ced) >= 6 ? [$ced, $like] : [$like]);
    $a = filas("SELECT 'Alumno' AS tipo, a.id, a.nombres || ' ' || a.apellidos AS nombre, a.cedula, a.fecha_nac, a.estado = 'activo' AS activo, g.nombre AS equipo, l.nombre AS liga, l.slug, a.liga_id
                FROM esc_alumnos a JOIN ligas l ON l.id = a.liga_id LEFT JOIN esc_grupos g ON g.id = a.grupo_id
                WHERE a.liga_id IN ($ids) AND (" . ($ced !== '' && strlen($ced) >= 6 ? 'a.cedula = ? OR ' : '') . "(a.nombres || ' ' || a.apellidos) LIKE ?) ORDER BY l.nombre LIMIT 100",
        $ced !== '' && strlen($ced) >= 6 ? [$ced, $like] : [$like]);
    return array_merge($j, $a);
}

/** Jugadores inscritos (activos) en más de una liga de la federación, por cédula. */
function jugadores_en_varias_ligas(int $fid): array
{
    $ids = implode(',', array_map('intval', array_column(ligas_de_federacion($fid), 'id')) ?: [0]); // solo las ligas afiliadas (no las copias de la federación)
    return filas("SELECT j.cedula, GROUP_CONCAT(j.nombres || ' ' || j.apellidos || ' (' || e.nombre || ' · ' || l.nombre || ')', ' | ') AS donde, COUNT(DISTINCT e.liga_id) AS n
                  FROM jugadores j JOIN equipos e ON e.id = j.equipo_id JOIN ligas l ON l.id = e.liga_id
                  WHERE e.liga_id IN ($ids) AND j.activo = 1 AND j.cedula <> '' GROUP BY j.cedula HAVING COUNT(DISTINCT e.liga_id) > 1 ORDER BY n DESC LIMIT 200");
}

/* ============================================================
 * SANCIONES FEDERATIVAS
 * ============================================================ */

/** Sanciones federativas vigentes para una liga (por cédula): [cedula => fila]. */
function sanciones_federativas_vigentes(array $liga): array
{
    static $cache = [];
    $fid = es_federacion($liga) ? (int)$liga['id'] : (int)(($liga['federacion_estado'] ?? '') === 'afiliada' ? ($liga['federacion_id'] ?? 0) : 0);
    if (!$fid) return [];
    if (isset($cache[$fid])) return $cache[$fid];
    $hoy = date('Y-m-d');
    $out = [];
    foreach (filas('SELECT * FROM fed_sanciones WHERE federacion_id = ? AND desde <= ? AND (hasta = \'\' OR hasta >= ?) AND anulada = 0 ORDER BY hasta DESC', [$fid, $hoy, $hoy]) as $s) {
        if (!isset($out[$s['cedula']])) $out[$s['cedula']] = $s;
    }
    return $cache[$fid] = $out;
}

/** Texto corto de una sanción federativa. */
function texto_sancion_federativa(array $s): string
{
    return 'Sanción de la federación' . ($s['motivo'] !== '' ? ': ' . $s['motivo'] : '') . ($s['hasta'] !== '' ? ' (hasta el ' . fecha_bonita($s['hasta']) . ')' : ' (indefinida)');
}

/**
 * Agrega a suspensiones() los jugadores del campeonato con sanción federativa vigente (los ve la vocalía, el carnet,
 * la planilla y la página pública igual que una tarjeta roja).
 */
function suspensiones_federativas(int $torneo_id): array
{
    $t = fila('SELECT t.id, l.* FROM torneos t JOIN ligas l ON l.id = t.liga_id WHERE t.id = ?', [$torneo_id]);
    if (!$t) return [];
    $sanc = sanciones_federativas_vigentes($t);
    if (!$sanc) return [];
    $ced = array_keys($sanc);
    $ph = implode(',', array_fill(0, count($ced), '?'));
    $out = [];
    foreach (filas("SELECT j.id, j.cedula FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.cedula IN ($ph)", array_merge([(int)$t['id']], $ced)) as $j) {
        $out[(int)$j['id']] = texto_sancion_federativa($sanc[$j['cedula']]);
    }
    return $out;
}

/* ============================================================
 * EQUIPOS PARA CAMPEONATOS FEDERATIVOS
 * ============================================================ */

/** Copia un equipo (con sus jugadores activos) de una liga afiliada a la cuenta de la federación. Devuelve el id nuevo. */
function traer_equipo_a_federacion(array $f, int $equipo_id, ?int $torneo_id = null): int
{
    $ids = array_map('intval', array_column(ligas_de_federacion((int)$f['id']), 'id'));
    $e = fila('SELECT e.*, l.nombre AS liga FROM equipos e JOIN ligas l ON l.id = e.liga_id WHERE e.id = ?', [$equipo_id]);
    if (!$e || !in_array((int)$e['liga_id'], $ids, true)) throw new RuntimeException('Ese equipo no es de una liga afiliada.');
    if (valor('SELECT id FROM equipos WHERE liga_id = ? AND origen_id = ?', [$f['id'], $equipo_id])) throw new RuntimeException('Ese equipo ya está en la federación.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO equipos (liga_id, nombre, abreviatura, escudo, color, delegado, telefono, origen_id) VALUES (?,?,?,?,?,?,?,?)', [
            $f['id'], mb_substr($e['nombre'] . ' (' . $e['liga'] . ')', 0, 80), $e['abreviatura'], $e['escudo'], $e['color'], $e['delegado'], $e['telefono'], $equipo_id]);
        $nuevo = (int)$pdo->lastInsertId();
        foreach (filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1', [$equipo_id]) as $j) {
            q('INSERT INTO jugadores (equipo_id, nombres, apellidos, cedula, numero, posicion, fecha_nac, foto, consentimiento, consentimiento_por, representante_nombre, representante_cedula, representante_telefono, foto_publica)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$nuevo, $j['nombres'], $j['apellidos'], $j['cedula'], $j['numero'], $j['posicion'], $j['fecha_nac'], $j['foto'],
                $j['consentimiento'], $j['consentimiento_por'], $j['representante_nombre'], $j['representante_cedula'], $j['representante_telefono'], $j['foto_publica']]);
            registrar_autorizacion_tercero('federacion', (int)$f['id'], trim($j['nombres'] . ' ' . $j['apellidos']), 'los campeonatos de la federación ' . $f['nombre'] . ' (datos recibidos de ' . $e['liga'] . ')', ['ref_tabla' => 'jugadores', 'ref_id' => (int)$pdo->lastInsertId(), 'documento' => $j['cedula']]);
        }
        if ($torneo_id && fila('SELECT id FROM torneos WHERE id = ? AND liga_id = ?', [$torneo_id, $f['id']])) q('INSERT OR IGNORE INTO torneo_equipos (torneo_id, equipo_id) VALUES (?, ?)', [$torneo_id, $nuevo]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    bitacora_evento('federacion', 'Trajo el equipo ' . $e['nombre'] . ' de ' . $e['liga'], (int)$f['id']);
    return $nuevo;
}

/** Tarjeta de afiliación para Parámetros de una liga o escuela. */
function html_tarjeta_afiliacion(array $liga): string
{
    if (es_federacion($liga) || !puede('config')) return '';
    $f = !empty($liga['federacion_id']) ? fila('SELECT * FROM ligas WHERE id = ?', [(int)$liga['federacion_id']]) : null;
    ob_start(); ?>
    <div class="tarjeta" id="federacion">
      <h2 style="margin-top:0">🏛 Federación</h2>
      <?php if ($f && $liga['federacion_estado'] === 'afiliada'): ?>
        <p>Su <?= es_escuela($liga) ? 'escuela' : 'liga' ?> está afiliada a <b><?= e($f['nombre']) ?></b>. La federación ve sus números, sus partidos y puede poner sanciones federativas que se aplican en sus campeonatos. Sus torneos siguen siendo solo suyos.</p>
        <p><a href="<?= e(url_liga($f)) ?>" target="_blank">Ver la página de la federación ↗</a></p>
        <form method="post" action="admin.php?p=federacion" onsubmit="return confirm('¿Dejar la federación?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="salir"><button class="btn btn-sec btn-chico">Dejar la federación</button></form>
      <?php elseif ($f && $liga['federacion_estado'] === 'pendiente'): ?>
        <p>⏳ Pidió afiliarse a <b><?= e($f['nombre']) ?></b>. Falta que la federación lo apruebe.</p>
        <form method="post" action="admin.php?p=federacion"><?= csrf_campo() ?><input type="hidden" name="accion" value="salir"><button class="btn btn-sec btn-chico">Cancelar el pedido</button></form>
      <?php else: ?>
        <p class="suave">¿Su <?= es_escuela($liga) ? 'escuela' : 'liga' ?> pertenece a una federación o asociación que también usa <?= e(marca()) ?>? Escriba el código que le da la federación (empieza con FED-).</p>
        <form method="post" action="admin.php?p=federacion" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="afiliar">
          <input name="codigo" required placeholder="FED-XXXXXX" style="max-width:180px;text-transform:uppercase"><button class="btn btn-sec">Pedir afiliación</button></form>
      <?php endif; ?>
    </div>
    <?php return (string)ob_get_clean();
}
