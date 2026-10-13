<?php
/*
 * (v37) OLIMPIADAS — JUEGOS MULTIDEPORTIVOS (módulo «olimpiadas»).
 *
 * Un EVENTO (olimp_eventos) agrupa varios campeonatos de la cuenta, cada uno de un deporte (DISCIPLINAS: olimp_disciplinas,
 * un campeonato pertenece a un solo evento). Las DELEGACIONES (empresas, bancos, colegios…) se registran una vez y tienen
 * un enlace propio (portal, con token) para inscribir a sus DEPORTISTAS (olimp_deportistas: una sola ficha por cédula en el
 * evento) en varias disciplinas (olimp_inscripciones). Al aprobarse, cada inscripción crea al competidor en el campeonato
 * (jugador del equipo de la delegación, participante del duelo, de las pruebas o de la carrera; columna olimp_dep_id).
 *
 * MEDALLERO GENERAL y TABLA DE PUNTOS por delegación: suma de todas las disciplinas (cada categoría o prueba reparte
 * sus puestos), con los puntos por puesto, el factor de cada disciplina, puntos por participar y puntos extra que se
 * configuran en cada evento. Administran: el súper, los administradores de la cuenta, los asistentes con la sección
 * «Olimpiadas» y los ADMINISTRADORES DEL EVENTO (perfil «evento», solo sus eventos: olimp_admins).
 */

const OLIMP_PUNTOS_FABRICA = [10, 7, 5, 4, 3, 2, 1];

/* ============================================================
 * EVENTOS Y CONFIGURACIÓN
 * ============================================================ */

function olimp_cfg_fabrica(): array
{
    return [
        'puntos' => OLIMP_PUNTOS_FABRICA, 'clasificacion' => 'puntos', 'participacion' => 0, 'puntuan' => 0,
        'max_disciplinas' => 0, 'edad_min' => 0, 'edad_max' => 0, 'aprobacion' => 'manual', 'exigir_requisitos' => 1,
        'insc_abierta' => 1, 'insc_hasta' => '', 'cruces' => 'avisar', 'provisional' => 0, 'publico' => 1, 'max_delegacion' => 0,
    ];
}

/** Configuración del evento (lo guardado sobre los valores de fábrica). */
function olimp_cfg(array $ev): array
{
    $c = array_merge(olimp_cfg_fabrica(), json_decode((string)($ev['config'] ?? ''), true) ?: []);
    $c['puntos'] = olimp_leer_puntos(is_array($c['puntos']) ? implode(',', $c['puntos']) : (string)$c['puntos']);
    foreach (['participacion', 'puntuan', 'max_disciplinas', 'edad_min', 'edad_max', 'exigir_requisitos', 'insc_abierta', 'provisional', 'publico', 'max_delegacion'] as $k) $c[$k] = (int)$c[$k];
    $c['participacion'] = max(0, min(1000, $c['participacion']));
    if (!in_array($c['clasificacion'], ['puntos', 'medallas'], true)) $c['clasificacion'] = 'puntos';
    if (!in_array($c['aprobacion'], ['manual', 'auto'], true)) $c['aprobacion'] = 'manual';
    if (!in_array($c['cruces'], ['avisar', 'bloquear'], true)) $c['cruces'] = 'avisar';
    return $c;
}

/** "10, 7, 5, 4" → [10, 7, 5, 4] (hasta 32 puestos; vacío = los de fábrica). */
function olimp_leer_puntos(string $s): array
{
    $v = [];
    foreach (preg_split('/[\s,;]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $x) {
        if (!is_numeric(str_replace(',', '.', $x))) continue;
        $v[] = max(0, min(10000, round((float)str_replace(',', '.', $x), 2)));
        if (count($v) >= 32) break;
    }
    return $v ?: OLIMP_PUNTOS_FABRICA;
}

function olimp_num(float $x): string
{
    return rtrim(rtrim(number_format($x, 2, ',', '.'), '0'), ',');
}

function olimp_estados(): array
{
    return ['preparacion' => 'En preparación', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'];
}

function olimp_evento(int $id, ?int $liga_id = null): ?array
{
    return $liga_id === null ? fila('SELECT * FROM olimp_eventos WHERE id = ?', [$id]) : fila('SELECT * FROM olimp_eventos WHERE id = ? AND liga_id = ?', [$id, $liga_id]);
}

function olimp_eventos_liga(int $lid): array
{
    return filas('SELECT * FROM olimp_eventos WHERE liga_id = ? ORDER BY (estado = \'finalizado\'), fecha_ini DESC, id DESC', [$lid]);
}

/** ¿La cuenta tiene el módulo de olimpiadas? */
function olimp_activo(?array $liga): bool
{
    return $liga && !es_escuela($liga) && function_exists('liga_tiene_modulo') && liga_tiene_modulo($liga, 'olimpiadas');
}

/** Eventos que puede manejar el usuario conectado (el administrador del evento, solo los suyos). */
function olimp_eventos_usuario(array $liga): array
{
    if (perfil_actual() === 'evento') {
        return filas('SELECT e.* FROM olimp_eventos e JOIN olimp_admins a ON a.evento_id = e.id WHERE e.liga_id = ? AND a.usuario_id = ? ORDER BY e.fecha_ini DESC, e.id DESC', [$liga['id'], (int)usuario()['id']]);
    }
    return olimp_eventos_liga((int)$liga['id']);
}

function olimp_ids_eventos_usuario(): array
{
    $u = usuario();
    if (!$u) return [];
    return array_map('intval', array_column(filas('SELECT evento_id FROM olimp_admins WHERE usuario_id = ?', [(int)$u['id']]), 'evento_id'));
}

/** Evento al que pertenece un campeonato (o null). */
function olimp_evento_de_torneo(int $tid): ?array
{
    return fila('SELECT e.* FROM olimp_eventos e JOIN olimp_disciplinas d ON d.evento_id = e.id WHERE d.torneo_id = ?', [$tid]);
}

/**
 * (v37) Perfil «Administrador del evento»: solo entra a la sección Olimpiadas de sus eventos y a los campeonatos,
 * partidos y equipos de las disciplinas de esos eventos.
 */
function olimp_puede_pagina_evento(string $p, bool $editar): bool
{
    if (in_array($p, ['inicio', 'seguridad', 'clave', 'salir', 'login', 'soporte', 'novedades', 'requisito_doc', 'mi_rol'], true)) return !$editar || in_array($p, ['seguridad', 'clave', 'soporte'], true) || $p === 'requisito_doc';
    $ids = olimp_ids_eventos_usuario();
    if (!$ids) return $p === 'olimpiadas' && !$editar;
    $in = implode(',', $ids);
    if ($p === 'olimpiadas') {
        $id = (int)get('id');
        return $id === 0 ? !$editar : in_array($id, $ids, true);
    }
    $tid = 0;
    if (in_array($p, ['torneo', 'carrera', 'duelo', 'bolos', 'pruebas', 'indiv', 'programar'], true)) $tid = (int)get('id');
    elseif ($p === 'imprimir') $tid = in_array(get('doc'), ['temporada', 'fixture', 'esquema'], true) || get('j') !== '' ? (int)get('id') : (int)valor('SELECT torneo_id FROM partidos WHERE id = ?', [(int)get('id')]);
    elseif (in_array($p, ['partido', 'planilla'], true)) $tid = (int)valor('SELECT torneo_id FROM partidos WHERE id = ?', [(int)get('id')]);
    elseif (in_array($p, ['equipo', 'jugador'], true)) {
        $eid = $p === 'equipo' ? (int)get('id') : (int)valor('SELECT equipo_id FROM jugadores WHERE id = ?', [(int)get('id')]);
        return $eid > 0 && (bool)valor("SELECT 1 FROM olimp_equipos oe JOIN olimp_disciplinas d ON d.id = oe.disciplina_id WHERE oe.equipo_id = ? AND d.evento_id IN ($in)", [$eid]);
    } else return false;
    return $tid > 0 && (bool)valor("SELECT 1 FROM olimp_disciplinas WHERE torneo_id = ? AND evento_id IN ($in)", [$tid]);
}

/** ¿El usuario conectado puede manejar este evento? */
function olimp_puede_evento(array $ev, bool $editar = false): bool
{
    if (es_super()) return true;
    if (perfil_actual() === 'evento') return in_array((int)$ev['id'], olimp_ids_eventos_usuario(), true);
    return puede('olimpiadas', $editar);
}

function olimp_slug_libre(int $lid, string $nombre, int $excluir = 0): string
{
    $base = mb_substr(slugify($nombre), 0, 60);
    $s = $base;
    $i = 2;
    while (fila('SELECT id FROM olimp_eventos WHERE liga_id = ? AND slug = ? AND id <> ?', [$lid, $s, $excluir])) $s = $base . '-' . $i++;
    return $s;
}

function olimp_crear_evento(array $liga, string $nombre, string $fecha_ini = '', string $fecha_fin = '', string $sede = ''): int
{
    $nombre = mb_substr(trim($nombre), 0, 100);
    if ($nombre === '') throw new RuntimeException('Escriba el nombre del evento.');
    $fi = $fecha_ini !== '' && strtotime($fecha_ini) ? date('Y-m-d', strtotime($fecha_ini)) : '';
    $ff = $fecha_fin !== '' && strtotime($fecha_fin) ? date('Y-m-d', strtotime($fecha_fin)) : $fi;
    if ($fi !== '' && $ff < $fi) throw new RuntimeException('La fecha final no puede ser antes de la inicial.');
    $req = requisitos_modulo('olimpiadas');
    q('INSERT INTO olimp_eventos (liga_id, nombre, slug, sede, fecha_ini, fecha_fin, color, config, requisitos) VALUES (?,?,?,?,?,?,?,?,?)',
        [$liga['id'], $nombre, olimp_slug_libre((int)$liga['id'], $nombre), mb_substr(trim($sede), 0, 120), $fi, $ff, color_valido((string)($liga['color'] ?? '')),
         json_encode(olimp_cfg_fabrica(), JSON_UNESCAPED_UNICODE), json_encode($req, JSON_UNESCAPED_UNICODE)]);
    return (int)db()->lastInsertId();
}

/** Borra el evento (no borra los campeonatos: quedan sueltos en Campeonatos). */
function olimp_borrar_evento(array $ev): void
{
    foreach (filas('SELECT id FROM olimp_deportistas WHERE evento_id = ?', [$ev['id']]) as $d) borrar_requisitos('olimp_deportistas', (int)$d['id']);
    q('UPDATE jugadores SET olimp_dep_id = NULL WHERE olimp_dep_id IN (SELECT id FROM olimp_deportistas WHERE evento_id = ?)', [$ev['id']]);
    foreach (['duelo_participantes', 'prueba_participantes', 'carrera_participantes'] as $tb) q("UPDATE $tb SET olimp_dep_id = NULL WHERE olimp_dep_id IN (SELECT id FROM olimp_deportistas WHERE evento_id = ?)", [$ev['id']]);
    q('DELETE FROM olimp_eventos WHERE id = ?', [$ev['id']]);
}

/* ============================================================
 * REQUISITOS DEL EVENTO (datos y documentos de cada deportista)
 * ============================================================ */

/** Clave de requisitos del evento (se usa como «módulo» en requisitos_datos / requisitos_docs). */
function olimp_mod_req(array $ev): string
{
    return 'olimp_e' . (int)$ev['id'];
}

function olimp_requisitos_evento(int $evid): array
{
    $ev = fila('SELECT requisitos FROM olimp_eventos WHERE id = ?', [$evid]);
    $r = json_decode((string)($ev['requisitos'] ?? ''), true);
    return is_array($r) && isset($r['campos']) ? requisitos_normalizar($r, 'olimpiadas') : requisitos_modulo('olimpiadas');
}

/* ============================================================
 * DISCIPLINAS (campeonatos del evento)
 * ============================================================ */

/** Disciplinas del evento con su campeonato (t_*). */
function olimp_disciplinas(int $evid): array
{
    $r = [];
    foreach (filas('SELECT d.*, t.nombre AS t_nombre, t.temporada AS t_temporada, t.modalidad, t.estado AS t_estado, t.categoria AS t_categoria, t.slug AS t_slug
                    FROM olimp_disciplinas d JOIN torneos t ON t.id = d.torneo_id WHERE d.evento_id = ? ORDER BY d.orden, d.fecha, d.hora_ini, d.id', [$evid]) as $d) {
        $r[(int)$d['id']] = $d;
    }
    return $r;
}

function olimp_torneo(array $d): array
{
    static $cache = [];
    return $cache[(int)$d['torneo_id']] ??= (fila('SELECT * FROM torneos WHERE id = ?', [$d['torneo_id']]) ?? []);
}

/** Nombre a mostrar de la disciplina. */
function olimp_nombre_disciplina(array $d): string
{
    return trim((string)$d['nombre']) !== '' ? (string)$d['nombre'] : (string)$d['t_nombre'];
}

/** Ícono del deporte de la disciplina. */
function olimp_icono(array $d): string
{
    $m = (string)($d['modalidad'] ?? '');
    if (isset(modalidades_individuales()[$m])) return icono_individual($m);
    return modulo_info(modulo_de_modalidad($m))['icono'] ?? '🏅';
}

/** Tipo de disciplina: equipo | duelo | pruebas | carrera (incluye bolos). */
function olimp_tipo(array $t): string
{
    if (!es_individual($t)) return 'equipo';
    if (es_duelo($t)) return 'duelo';
    if (es_pruebas($t)) return 'pruebas';
    return 'carrera';
}

function olimp_texto_tipo(string $tipo): string
{
    return ['equipo' => 'Por equipos', 'duelo' => 'Individual / parejas (llaves)', 'pruebas' => 'Pruebas (marcas)', 'carrera' => 'Carrera / jornadas'][$tipo] ?? $tipo;
}

function olimp_ramas(): array
{
    return ['' => 'Abierta (todos)', 'M' => 'Varones', 'F' => 'Damas', 'X' => 'Mixta'];
}

/** Vincula un campeonato de la cuenta al evento. */
function olimp_vincular_torneo(array $ev, int $tid, string $nombre = ''): int
{
    $t = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [$tid, $ev['liga_id']]) ?? throw new RuntimeException('Campeonato no encontrado.');
    if ($otro = olimp_evento_de_torneo($tid)) throw new RuntimeException('«' . $t['nombre'] . '» ya es disciplina de ' . $otro['nombre'] . '.');
    $orden = (int)valor('SELECT COALESCE(MAX(orden), 0) + 1 FROM olimp_disciplinas WHERE evento_id = ?', [$ev['id']]);
    $rama = '';
    $cat = mb_strtolower((string)$t['categoria']);
    if (preg_match('/femen|damas|mujer/u', $cat . ' ' . mb_strtolower($t['nombre']))) $rama = 'F';
    elseif (preg_match('/mascul|varon|hombre/u', $cat . ' ' . mb_strtolower($t['nombre']))) $rama = 'M';
    q('INSERT INTO olimp_disciplinas (evento_id, torneo_id, nombre, rama, orden, fecha) VALUES (?,?,?,?,?,?)', [$ev['id'], $tid, mb_substr($nombre, 0, 80), $rama, $orden, (string)$ev['fecha_ini']]);
    return (int)db()->lastInsertId();
}

/** Quita la disciplina del evento (el campeonato queda en Campeonatos con sus datos). */
function olimp_desvincular(array $d): void
{
    q('DELETE FROM olimp_inscripciones WHERE disciplina_id = ?', [$d['id']]);
    q('DELETE FROM olimp_equipos WHERE disciplina_id = ?', [$d['id']]);
    q('DELETE FROM olimp_disciplinas WHERE id = ?', [$d['id']]);
}

/** Opciones de inscripción de la disciplina: categorías (duelos y carreras) o pruebas. */
function olimp_opciones_disciplina(array $d): array
{
    $t = olimp_torneo($d);
    return match (olimp_tipo($t)) {
        'duelo' => array_map(fn($c) => ['id' => (int)$c['id'], 'nombre' => $c['nombre'], 'rama' => (string)$c['rama']], duelo_categorias((int)$t['id'])),
        'carrera' => array_map(fn($c) => ['id' => (int)$c['id'], 'nombre' => $c['nombre'], 'rama' => ''], carrera_categorias((int)$t['id'])),
        'pruebas' => array_map(fn($e) => ['id' => (int)$e['id'], 'nombre' => prueba_nombre($e, false), 'rama' => (string)$e['rama']], pruebas_eventos((int)$t['id'])),
        default => [],
    };
}

/** ¿Se cruzan los horarios de dos disciplinas? (misma fecha y horas que se pisan; sin hora no se compara) */
function olimp_se_cruzan(array $a, array $b): bool
{
    if ((int)$a['id'] === (int)$b['id'] || $a['fecha'] === '' || $a['fecha'] !== $b['fecha'] || $a['hora_ini'] === '' || $b['hora_ini'] === '') return false;
    $fin = fn(array $x) => $x['hora_fin'] !== '' ? $x['hora_fin'] : date('H:i', strtotime($x['hora_ini']) + 7200);
    return $a['hora_ini'] < $fin($b) && $b['hora_ini'] < $fin($a);
}

/* ============================================================
 * DELEGACIONES
 * ============================================================ */

function olimp_delegaciones(int $evid, bool $solo_activas = false): array
{
    $r = [];
    foreach (filas('SELECT * FROM olimp_delegaciones WHERE evento_id = ?' . ($solo_activas ? ' AND activo = 1' : '') . ' ORDER BY nombre', [$evid]) as $x) $r[(int)$x['id']] = $x;
    return $r;
}

function olimp_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Enlace del portal de la delegación. */
function olimp_url_portal(array $liga, array $ev, array $del): string
{
    return url_base() . 'index.php?l=' . rawurlencode($liga['slug']) . '&o=' . (int)$ev['id'] . '&k=' . $del['token'];
}

function olimp_url_publica(array $liga, array $ev): string
{
    return url_base() . 'index.php?l=' . rawurlencode($liga['slug']) . '&o=' . (int)$ev['id'];
}

/** Clave para comparar nombres de clubes con delegaciones (sin tildes, mayúsculas ni espacios de más). */
function olimp_clave_nombre(string $s): string
{
    return trim((string)preg_replace('/\s+/', ' ', strtr(mb_strtolower(trim($s), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'])));
}

/** Índice nombre/sigla de delegación → id (para los competidores escritos a mano con su club). */
function olimp_indice_delegaciones(array $dels): array
{
    $ix = [];
    foreach ($dels as $id => $d) {
        $ix[olimp_clave_nombre($d['nombre'])] = $id;
        if (trim((string)$d['sigla']) !== '') $ix[olimp_clave_nombre($d['sigla'])] ??= $id;
    }
    return $ix;
}

/* ============================================================
 * DEPORTISTAS E INSCRIPCIONES
 * ============================================================ */

function olimp_estados_deportista(): array
{
    return ['pendiente' => 'Por aprobar', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado'];
}

function olimp_deportistas(int $evid, ?int $delegacion_id = null): array
{
    $r = [];
    foreach (filas('SELECT * FROM olimp_deportistas WHERE evento_id = ?' . ($delegacion_id ? ' AND delegacion_id = ' . (int)$delegacion_id : '') . ' ORDER BY apellidos, nombres', [$evid]) as $x) $r[(int)$x['id']] = $x;
    return $r;
}

function olimp_nombre_dep(array $d): string
{
    return trim($d['nombres'] . ' ' . $d['apellidos']);
}

function olimp_inscripciones_dep(int $dep_id): array
{
    return filas('SELECT * FROM olimp_inscripciones WHERE deportista_id = ? ORDER BY id', [$dep_id]);
}

/**
 * Guarda (crea o actualiza) un deportista. $datos: nombres, apellidos, cedula, sexo, fecha_nac, telefono, email, cargo.
 * Valida cédula única en el evento y la edad del evento. Devuelve el id.
 */
function olimp_guardar_deportista(array $ev, int $delegacion_id, array $datos, int $id = 0): int
{
    $cfg = olimp_cfg($ev);
    $nom = mb_substr(trim((string)($datos['nombres'] ?? '')), 0, 60);
    $ape = mb_substr(trim((string)($datos['apellidos'] ?? '')), 0, 60);
    $ced = mb_substr(preg_replace('/[^0-9A-Za-z-]/', '', (string)($datos['cedula'] ?? '')), 0, 20);
    $sexo = in_array($datos['sexo'] ?? '', ['M', 'F'], true) ? $datos['sexo'] : '';
    $nac = ($datos['fecha_nac'] ?? '') !== '' && strtotime((string)$datos['fecha_nac']) ? date('Y-m-d', strtotime((string)$datos['fecha_nac'])) : '';
    if ($nom === '' || $ape === '') throw new RuntimeException('Escriba nombres y apellidos.');
    if (strlen($ced) < 5) throw new RuntimeException('Escriba la cédula o pasaporte.');
    if ($sexo === '') throw new RuntimeException('Elija si es varón o dama.');
    if ($nac === '') throw new RuntimeException('Escriba la fecha de nacimiento.');
    if ($nac > date('Y-m-d')) throw new RuntimeException('La fecha de nacimiento no puede ser futura.');
    $ed = edad_en_fecha($nac, (string)($ev['fecha_ini'] ?: date('Y-m-d')));
    if ($cfg['edad_min'] > 0 && $ed !== null && $ed < $cfg['edad_min']) throw new RuntimeException('En este evento la edad mínima es ' . $cfg['edad_min'] . ' años (' . $nom . ' tendrá ' . $ed . ').');
    if ($cfg['edad_max'] > 0 && $ed !== null && $ed > $cfg['edad_max']) throw new RuntimeException('En este evento la edad máxima es ' . $cfg['edad_max'] . ' años.');
    if (!fila('SELECT id FROM olimp_delegaciones WHERE id = ? AND evento_id = ?', [$delegacion_id, $ev['id']])) throw new RuntimeException('Elija la delegación.');
    $otro = fila('SELECT d.id, d.delegacion_id, g.nombre AS del FROM olimp_deportistas d JOIN olimp_delegaciones g ON g.id = d.delegacion_id WHERE d.evento_id = ? AND upper(d.cedula) = upper(?) AND d.id <> ?', [$ev['id'], $ced, $id]);
    if ($otro) throw new RuntimeException('La cédula ' . $ced . ' ya está inscrita en este evento' . ((int)$otro['delegacion_id'] !== $delegacion_id ? ' por ' . $otro['del'] : '') . '. Una persona solo puede representar a una delegación.');
    if ($cfg['max_delegacion'] > 0 && $id === 0 && (int)valor("SELECT COUNT(*) FROM olimp_deportistas WHERE delegacion_id = ? AND estado <> 'rechazado'", [$delegacion_id]) >= $cfg['max_delegacion']) {
        throw new RuntimeException('Cada delegación puede inscribir hasta ' . $cfg['max_delegacion'] . ' deportistas.');
    }
    $v = [$nom, $ape, $ced, $sexo, $nac, mb_substr(trim((string)($datos['telefono'] ?? '')), 0, 20), mb_substr(trim((string)($datos['email'] ?? '')), 0, 120), mb_substr(trim((string)($datos['cargo'] ?? '')), 0, 80)];
    if ($id > 0) {
        $ant = fila('SELECT * FROM olimp_deportistas WHERE id = ? AND evento_id = ?', [$id, $ev['id']]) ?? throw new RuntimeException('Deportista no encontrado.');
        q('UPDATE olimp_deportistas SET nombres = ?, apellidos = ?, cedula = ?, sexo = ?, fecha_nac = ?, telefono = ?, email = ?, cargo = ?, delegacion_id = ? WHERE id = ?', array_merge($v, [$delegacion_id, $id]));
        olimp_propagar_deportista(fila('SELECT * FROM olimp_deportistas WHERE id = ?', [$id]), (int)$ant['delegacion_id'] !== $delegacion_id);
        return $id;
    }
    $estado = $cfg['aprobacion'] === 'auto' ? 'aprobado' : 'pendiente';
    q('INSERT INTO olimp_deportistas (nombres, apellidos, cedula, sexo, fecha_nac, telefono, email, cargo, evento_id, delegacion_id, estado) VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge($v, [$ev['id'], $delegacion_id, $estado]));
    return (int)db()->lastInsertId();
}

/** Cambios de nombre, cédula, fecha o delegación de un deportista: se copian a sus fichas en los campeonatos. */
function olimp_propagar_deportista(array $dep, bool $cambio_delegacion = false): void
{
    $del = fila('SELECT * FROM olimp_delegaciones WHERE id = ?', [$dep['delegacion_id']]);
    $nombre = olimp_nombre_dep($dep);
    q('UPDATE jugadores SET nombres = ?, apellidos = ?, cedula = ?, fecha_nac = ? WHERE olimp_dep_id = ?', [$dep['nombres'], $dep['apellidos'], $dep['cedula'], $dep['fecha_nac'], $dep['id']]);
    q('UPDATE duelo_participantes SET nombre = ?, club = ?, nacimiento = ? WHERE olimp_dep_id = ?', [$nombre, $del['nombre'] ?? '', $dep['fecha_nac'], $dep['id']]);
    q('UPDATE prueba_participantes SET nombre = ?, club = ?, rama = ?, nacimiento = ? WHERE olimp_dep_id = ?', [$nombre, $del['nombre'] ?? '', $dep['sexo'], $dep['fecha_nac'], $dep['id']]);
    q('UPDATE carrera_participantes SET nombre = ?, club = ?, nacimiento = ? WHERE olimp_dep_id = ?', [$nombre, $del['nombre'] ?? '', $dep['fecha_nac'], $dep['id']]);
    if ($cambio_delegacion) {
        // el jugador pasa al equipo de su nueva delegación en cada disciplina por equipos
        foreach (filas('SELECT i.*, d.evento_id FROM olimp_inscripciones i JOIN olimp_disciplinas d ON d.id = i.disciplina_id WHERE i.deportista_id = ? AND i.ref_tabla = \'jugadores\'', [$dep['id']]) as $i) {
            $disc = fila('SELECT d.*, t.modalidad FROM olimp_disciplinas d JOIN torneos t ON t.id = d.torneo_id WHERE d.id = ?', [$i['disciplina_id']]);
            $eq = olimp_equipo_de($disc, $del);
            q('UPDATE jugadores SET equipo_id = ? WHERE id = ?', [$eq, $i['ref_id']]);
        }
    }
}

/** Disciplinas (distintas) en las que está inscrito un deportista. */
function olimp_disciplinas_de(int $dep_id): array
{
    return array_map('intval', array_column(filas('SELECT DISTINCT disciplina_id FROM olimp_inscripciones WHERE deportista_id = ?', [$dep_id]), 'disciplina_id'));
}

/** Avisos de cruce de horario de un deportista: lista de "Fútbol y Natación (sáb 10 oct 09:00)". */
function olimp_cruces_deportista(int $dep_id, array $discs): array
{
    $mis = array_values(array_filter(array_map(fn($id) => $discs[$id] ?? null, olimp_disciplinas_de($dep_id))));
    $r = [];
    for ($i = 0; $i < count($mis); $i++) for ($j = $i + 1; $j < count($mis); $j++) {
        if (olimp_se_cruzan($mis[$i], $mis[$j])) $r[] = olimp_nombre_disciplina($mis[$i]) . ' y ' . olimp_nombre_disciplina($mis[$j]) . ' (' . fecha_bonita($mis[$i]['fecha']) . ' ' . $mis[$i]['hora_ini'] . ')';
    }
    return $r;
}

/**
 * Inscribe a un deportista en una disciplina. $op: categoria (duelos y carreras), eventos (pruebas), pareja (duelo en parejas).
 * $admin = true: lo hace el organizador (puede pasar la fecha límite). Devuelve [id de la inscripción, avisos].
 */
function olimp_inscribir(array $ev, array $dep, array $d, array $op = [], bool $admin = false): array
{
    $cfg = olimp_cfg($ev);
    $t = olimp_torneo($d);
    $tipo = olimp_tipo($t);
    $avisos = [];
    if ((int)$d['evento_id'] !== (int)$ev['id'] || (int)$dep['evento_id'] !== (int)$ev['id']) throw new RuntimeException('Disciplina no válida.');
    if ($dep['estado'] === 'rechazado') throw new RuntimeException(olimp_nombre_dep($dep) . ' fue rechazado: no se puede inscribir.');
    if (!$admin) {
        if (!$cfg['insc_abierta']) throw new RuntimeException('Las inscripciones de este evento están cerradas. Comuníquese con la organización.');
        if ($cfg['insc_hasta'] !== '' && date('Y-m-d') > $cfg['insc_hasta']) throw new RuntimeException('Las inscripciones cerraron el ' . fecha_bonita($cfg['insc_hasta']) . '.');
        if ($t['estado'] === 'finalizado') throw new RuntimeException(olimp_nombre_disciplina($d) . ' ya terminó.');
    }
    $nom_disc = olimp_nombre_disciplina($d);
    if ($d['rama'] === 'M' && $dep['sexo'] !== 'M') throw new RuntimeException($nom_disc . ' es solo de varones.');
    if ($d['rama'] === 'F' && $dep['sexo'] !== 'F') throw new RuntimeException($nom_disc . ' es solo de damas.');
    $cat = (int)($op['categoria'] ?? 0);
    $eventos = array_values(array_unique(array_map('intval', (array)($op['eventos'] ?? []))));
    $opciones = [];
    foreach (olimp_opciones_disciplina($d) as $o) $opciones[$o['id']] = $o;
    if ($tipo === 'duelo') {
        if ($cat === 0) $cat = (int)duelo_categoria_auto((int)$t['id'], $dep['sexo'], null, (string)$dep['fecha_nac']);
        if (!isset($opciones[$cat])) throw new RuntimeException('Elija la categoría de ' . $nom_disc . '.');
        if (in_array($opciones[$cat]['rama'], ['M', 'F'], true) && $opciones[$cat]['rama'] !== $dep['sexo']) throw new RuntimeException('La categoría ' . $opciones[$cat]['nombre'] . ' es de ' . ($opciones[$cat]['rama'] === 'F' ? 'damas' : 'varones') . '.');
    } elseif ($tipo === 'carrera') {
        if ($opciones && !isset($opciones[$cat])) throw new RuntimeException('Elija la categoría de ' . $nom_disc . '.');
    } elseif ($tipo === 'pruebas') {
        $eventos = array_values(array_filter($eventos, fn($x) => isset($opciones[$x])));
        if (!$eventos) throw new RuntimeException('Marque al menos una prueba de ' . $nom_disc . '.');
        foreach ($eventos as $x) if (in_array($opciones[$x]['rama'], ['M', 'F'], true) && $opciones[$x]['rama'] !== $dep['sexo']) throw new RuntimeException($opciones[$x]['nombre'] . ' es de ' . ($opciones[$x]['rama'] === 'F' ? 'damas' : 'varones') . '.');
        $cat = 0;
    } else {
        $cat = 0;
    }
    $ya = fila('SELECT * FROM olimp_inscripciones WHERE deportista_id = ? AND disciplina_id = ? AND categoria_id = ?', [$dep['id'], $d['id'], $cat]);
    if ($ya && $tipo !== 'pruebas') throw new RuntimeException(olimp_nombre_dep($dep) . ' ya está inscrito en ' . $nom_disc . ($cat && isset($opciones[$cat]) ? ' (' . $opciones[$cat]['nombre'] . ')' : '') . '.');
    $mis = olimp_disciplinas_de((int)$dep['id']);
    if ($cfg['max_disciplinas'] > 0 && !in_array((int)$d['id'], $mis, true) && count($mis) >= $cfg['max_disciplinas']) {
        throw new RuntimeException('Cada deportista puede participar en hasta ' . $cfg['max_disciplinas'] . ' disciplina(s); ' . olimp_nombre_dep($dep) . ' ya tiene ' . count($mis) . '.');
    }
    // cupo por delegación en la disciplina (en duelos y carreras, por categoría)
    if ((int)$d['max_por_delegacion'] > 0 && !$ya) {
        $n = (int)valor('SELECT COUNT(*) FROM olimp_inscripciones i JOIN olimp_deportistas p ON p.id = i.deportista_id WHERE i.disciplina_id = ? AND i.categoria_id = ? AND p.delegacion_id = ?', [$d['id'], $cat, $dep['delegacion_id']]);
        if ($n >= (int)$d['max_por_delegacion']) throw new RuntimeException('Cada delegación puede inscribir hasta ' . (int)$d['max_por_delegacion'] . ' en ' . $nom_disc . ($cat && isset($opciones[$cat]) ? ' · ' . $opciones[$cat]['nombre'] : '') . '.');
    }
    if ($tipo === 'equipo') {
        $max = (int)$t['max_plantilla'];
        $n = (int)valor('SELECT COUNT(*) FROM olimp_inscripciones i JOIN olimp_deportistas p ON p.id = i.deportista_id WHERE i.disciplina_id = ? AND p.delegacion_id = ?', [$d['id'], $dep['delegacion_id']]);
        if ($max > 0 && $n >= $max) throw new RuntimeException('El equipo de la delegación en ' . $nom_disc . ' ya tiene ' . $n . ' jugadores (máximo ' . $max . ').');
    }
    // cruces de horario
    $discs = olimp_disciplinas((int)$ev['id']);
    foreach ($mis as $otra) {
        if (isset($discs[$otra], $discs[(int)$d['id']]) && olimp_se_cruzan($discs[$otra], $discs[(int)$d['id']])) {
            $txt = 'Se cruza el horario de ' . $nom_disc . ' con ' . olimp_nombre_disciplina($discs[$otra]) . ' (' . fecha_bonita($d['fecha']) . ' ' . $d['hora_ini'] . ').';
            if ($cfg['cruces'] === 'bloquear') throw new RuntimeException($txt . ' No se puede inscribir en las dos.');
            $avisos[] = $txt;
        }
    }
    if ($ya && $tipo === 'pruebas') {
        // agrega las pruebas nuevas a la inscripción
        $antes = json_decode((string)$ya['eventos'], true) ?: [];
        q('UPDATE olimp_inscripciones SET eventos = ? WHERE id = ?', [json_encode(array_values(array_unique(array_merge($antes, $eventos)))), $ya['id']]);
        $iid = (int)$ya['id'];
    } else {
        q('INSERT INTO olimp_inscripciones (evento_id, deportista_id, disciplina_id, categoria_id, eventos, pareja) VALUES (?,?,?,?,?,?)',
            [$ev['id'], $dep['id'], $d['id'], $cat, json_encode($eventos), mb_substr(trim((string)($op['pareja'] ?? '')), 0, 80)]);
        $iid = (int)db()->lastInsertId();
    }
    if ($dep['estado'] === 'aprobado') $avisos = array_merge($avisos, olimp_materializar($iid));
    return [$iid, $avisos];
}

/** Equipo de la delegación en una disciplina por equipos (lo crea e inscribe en el campeonato si no existe). */
function olimp_equipo_de(array $d, array $del): int
{
    $eq = (int)valor('SELECT equipo_id FROM olimp_equipos WHERE disciplina_id = ? AND delegacion_id = ?', [$d['id'], $del['id']]);
    if ($eq && fila('SELECT id FROM equipos WHERE id = ?', [$eq])) return $eq;
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$d['torneo_id']]);
    $abrev = mb_strtoupper(mb_substr(trim((string)$del['sigla']) !== '' ? (string)$del['sigla'] : iniciales((string)$del['nombre']), 0, 4));
    q('INSERT INTO equipos (liga_id, nombre, abreviatura, escudo, color, delegado, telefono) VALUES (?,?,?,?,?,?,?)',
        [$t['liga_id'], mb_substr($del['nombre'], 0, 80), $abrev, (string)$del['logo'], color_valido((string)$del['color'], '#333333'), mb_substr((string)$del['contacto'], 0, 80), mb_substr((string)$del['telefono'], 0, 20)]);
    $eq = (int)db()->lastInsertId();
    q('INSERT OR REPLACE INTO olimp_equipos (disciplina_id, delegacion_id, equipo_id) VALUES (?,?,?)', [$d['id'], $del['id'], $eq]);
    if (!fila('SELECT 1 FROM torneo_equipos WHERE torneo_id = ? AND equipo_id = ?', [$t['id'], $eq])) q('INSERT INTO torneo_equipos (torneo_id, equipo_id) VALUES (?, ?)', [$t['id'], $eq]);
    return $eq;
}

/**
 * Crea al deportista en el campeonato de la disciplina (si todavía no está). Devuelve avisos.
 * Equipos: jugador del equipo de su delegación. Duelos / pruebas / carreras: competidor con su delegación como club.
 */
function olimp_materializar(int $insc_id): array
{
    $i = fila('SELECT * FROM olimp_inscripciones WHERE id = ?', [$insc_id]);
    if (!$i) return [];
    $dep = fila('SELECT * FROM olimp_deportistas WHERE id = ?', [$i['deportista_id']]);
    $d = fila('SELECT d.*, t.modalidad, t.nombre AS t_nombre FROM olimp_disciplinas d JOIN torneos t ON t.id = d.torneo_id WHERE d.id = ?', [$i['disciplina_id']]);
    if (!$dep || !$d || $dep['estado'] !== 'aprobado') return [];
    $del = fila('SELECT * FROM olimp_delegaciones WHERE id = ?', [$dep['delegacion_id']]);
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$d['torneo_id']]);
    $tipo = olimp_tipo($t);
    $avisos = [];
    $eventos = json_decode((string)$i['eventos'], true) ?: [];
    if ($i['ref_id'] !== null && $i['ref_tabla'] !== '' && fila('SELECT id FROM ' . $i['ref_tabla'] . ' WHERE id = ?', [$i['ref_id']])) {
        // ya existe: en pruebas, agrega las pruebas nuevas
        if ($tipo === 'pruebas') {
            $p = fila('SELECT * FROM prueba_participantes WHERE id = ?', [$i['ref_id']]);
            foreach ($eventos as $evid) {
                $pe = fila('SELECT * FROM prueba_eventos WHERE id = ? AND torneo_id = ?', [(int)$evid, $t['id']]);
                if (!$pe) continue;
                if (($m = prueba_no_puede($pe, $p, $t)) !== '') { $avisos[] = prueba_nombre($pe, false) . ': ' . $m; continue; }
                q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id) VALUES (?, ?)', [$pe['id'], $p['id']]);
            }
        }
        return $avisos;
    }
    if ($tipo === 'equipo') {
        $eq = olimp_equipo_de($d, $del);
        q('INSERT INTO jugadores (equipo_id, nombres, apellidos, cedula, fecha_nac, olimp_dep_id) VALUES (?,?,?,?,?,?)', [$eq, $dep['nombres'], $dep['apellidos'], $dep['cedula'], $dep['fecha_nac'], $dep['id']]);
        $ref = ['jugadores', (int)db()->lastInsertId()];
    } else {
        $ins = ['nombre' => olimp_nombre_dep($dep), 'pareja' => (string)$i['pareja'], 'club' => (string)$del['nombre'], 'ciudad' => '', 'rama' => $dep['sexo'], 'nacimiento' => $dep['fecha_nac'],
            'peso' => null, 'grado' => '', 'categoria_id' => $i['categoria_id'] ? (int)$i['categoria_id'] : null, 'extra' => json_encode(['eventos' => $eventos])];
        $pid = aprobar_inscripcion_indiv_crear($t, $ins, $i['categoria_id'] ? (int)$i['categoria_id'] : null);
        $tabla = $tipo === 'duelo' ? 'duelo_participantes' : ($tipo === 'pruebas' ? 'prueba_participantes' : 'carrera_participantes');
        q("UPDATE $tabla SET olimp_dep_id = ? WHERE id = ?", [$dep['id'], $pid]);
        $ref = [$tabla, $pid];
        if ($tipo === 'pruebas') {
            $n = (int)valor('SELECT COUNT(*) FROM prueba_resultados WHERE participante_id = ?', [$pid]);
            if ($n < count($eventos)) $avisos[] = olimp_nombre_dep($dep) . ': no cumple la edad o la rama de ' . (count($eventos) - $n) . ' prueba(s) de ' . $d['t_nombre'] . '.';
        }
    }
    q('UPDATE olimp_inscripciones SET ref_tabla = ?, ref_id = ?, estado = ? WHERE id = ?', [$ref[0], $ref[1], 'inscrito', $insc_id]);
    return $avisos;
}

/** ¿El competidor ya tiene resultados? (entonces no se puede borrar) */
function olimp_tiene_resultados(string $tabla, int $id): bool
{
    return match ($tabla) {
        'jugadores' => (bool)valor('SELECT 1 FROM eventos WHERE jugador_id = ? LIMIT 1', [$id]),
        'duelo_participantes' => (bool)valor('SELECT 1 FROM duelo_combates WHERE a_id = ? OR b_id = ? LIMIT 1', [$id, $id]),
        'prueba_participantes' => (bool)valor("SELECT 1 FROM prueba_resultados WHERE participante_id = ? AND (marca IS NOT NULL OR estado <> 'ok') LIMIT 1", [$id]),
        'carrera_participantes' => (bool)valor('SELECT 1 FROM carrera_resultados WHERE participante_id = ? LIMIT 1', [$id]),
        default => false,
    };
}

/** Quita una inscripción (y al competidor del campeonato si todavía no tiene resultados). */
function olimp_quitar_inscripcion(int $insc_id): void
{
    $i = fila('SELECT * FROM olimp_inscripciones WHERE id = ?', [$insc_id]) ?? throw new RuntimeException('Inscripción no encontrada.');
    if ($i['ref_id'] !== null && $i['ref_tabla'] !== '' && fila('SELECT id FROM ' . $i['ref_tabla'] . ' WHERE id = ?', [$i['ref_id']])) {
        if (olimp_tiene_resultados($i['ref_tabla'], (int)$i['ref_id'])) {
            throw new RuntimeException('Ya compitió o ya está en el sorteo: no se puede quitar. Márquelo como retirado en el campeonato.');
        }
        if ($i['ref_tabla'] === 'prueba_participantes') q('DELETE FROM prueba_resultados WHERE participante_id = ?', [$i['ref_id']]);
        q('DELETE FROM ' . $i['ref_tabla'] . ' WHERE id = ?', [$i['ref_id']]);
    }
    q('DELETE FROM olimp_inscripciones WHERE id = ?', [$insc_id]);
}

/** Aprueba a un deportista (revisa los requisitos obligatorios) y lo pasa a sus disciplinas. Devuelve avisos. */
function olimp_aprobar(array $ev, array $dep, bool $forzar = false): array
{
    $cfg = olimp_cfg($ev);
    if ($cfg['exigir_requisitos'] && !$forzar && ($f = requisitos_faltan('olimp_deportistas', (int)$dep['id'], olimp_mod_req($ev)))) {
        throw new RuntimeException(olimp_nombre_dep($dep) . ': falta ' . implode(', ', $f) . '. Complete su ficha o apruébelo igual con «Aprobar sin requisitos».');
    }
    q("UPDATE olimp_deportistas SET estado = 'aprobado', nota = '' WHERE id = ?", [$dep['id']]);
    $av = [];
    foreach (olimp_inscripciones_dep((int)$dep['id']) as $i) {
        try {
            $av = array_merge($av, olimp_materializar((int)$i['id']));
        } catch (RuntimeException $e) {
            $av[] = $e->getMessage();
        }
    }
    return $av;
}

/** Rechaza (con motivo) o devuelve a «por aprobar»: se quita de los campeonatos donde todavía no compitió. */
function olimp_rechazar(array $dep, string $motivo): void
{
    foreach (olimp_inscripciones_dep((int)$dep['id']) as $i) {
        if ($i['ref_id'] !== null && $i['ref_tabla'] !== '' && fila('SELECT id FROM ' . $i['ref_tabla'] . ' WHERE id = ?', [$i['ref_id']])) {
            if (olimp_tiene_resultados($i['ref_tabla'], (int)$i['ref_id'])) throw new RuntimeException(olimp_nombre_dep($dep) . ' ya compitió: no se puede rechazar.');
        }
    }
    foreach (olimp_inscripciones_dep((int)$dep['id']) as $i) {
        if ($i['ref_id'] !== null && $i['ref_tabla'] !== '' && fila('SELECT id FROM ' . $i['ref_tabla'] . ' WHERE id = ?', [$i['ref_id']])) {
            if ($i['ref_tabla'] === 'prueba_participantes') q('DELETE FROM prueba_resultados WHERE participante_id = ?', [$i['ref_id']]);
            q('DELETE FROM ' . $i['ref_tabla'] . ' WHERE id = ?', [$i['ref_id']]);
        }
        q("UPDATE olimp_inscripciones SET ref_tabla = '', ref_id = NULL, estado = 'pendiente' WHERE id = ?", [$i['id']]);
    }
    q("UPDATE olimp_deportistas SET estado = 'rechazado', nota = ? WHERE id = ?", [mb_substr(trim($motivo), 0, 200), $dep['id']]);
}

function olimp_borrar_deportista(array $dep): void
{
    foreach (olimp_inscripciones_dep((int)$dep['id']) as $i) olimp_quitar_inscripcion((int)$i['id']);
    borrar_requisitos('olimp_deportistas', (int)$dep['id']);
    q('DELETE FROM olimp_deportistas WHERE id = ?', [$dep['id']]);
}

/** Texto corto de una inscripción: "Tenis de mesa · Individual varones" / "Atletismo · 100 m, Salto largo". */
function olimp_texto_inscripcion(array $i, array $discs): string
{
    $d = $discs[(int)$i['disciplina_id']] ?? null;
    if (!$d) return '—';
    $txt = olimp_nombre_disciplina($d);
    $ops = [];
    foreach (olimp_opciones_disciplina($d) as $o) $ops[$o['id']] = $o['nombre'];
    if ((int)$i['categoria_id'] && isset($ops[(int)$i['categoria_id']])) $txt .= ' · ' . $ops[(int)$i['categoria_id']];
    $evs = array_values(array_filter(array_map(fn($x) => $ops[(int)$x] ?? null, json_decode((string)$i['eventos'], true) ?: [])));
    if ($evs) $txt .= ' · ' . implode(', ', $evs);
    if (trim((string)$i['pareja']) !== '') $txt .= ' (con ' . $i['pareja'] . ')';
    return $txt;
}

/* ============================================================
 * RESULTADOS: PUESTOS DE CADA DISCIPLINA, MEDALLERO Y PUNTOS
 * ============================================================ */

/**
 * Puestos de un campeonato por equipos: ['terminada' => bool, 'puestos' => [equipo_id => puesto]].
 * Final (y partido por el 3.º); si no hubo partido por el 3.º, los dos perdedores de semifinal son 3.º. Sin llaves: la tabla
 * (un solo grupo), cuando ya se jugaron todos los partidos. El campeón y subcampeón cargados a mano mandan.
 */
function olimp_puestos_equipos(array $t): array
{
    $tid = (int)$t['id'];
    $r = ['terminada' => false, 'puestos' => []];
    $cerrado = fn(array $p) => in_array($p['estado'], ['jugado', 'wo'], true);
    $pls = filas("SELECT * FROM partidos WHERE torneo_id = ? AND fase <> 'regular' ORDER BY id", [$tid]);
    $final = null;
    $tercer = null;
    foreach ($pls as $p) {
        if ($p['fase'] === 'final') $final = $p;
        if ($p['fase'] === 'tercer') $tercer = $p;
    }
    if ($final) {
        $orden = ['semifinal' => 3, 'cuartos' => 5, 'octavos' => 9];
        foreach ($pls as $p) {
            if (!isset($orden[$p['fase']]) || !$cerrado($p) || !($g = ganador_partido($p))) continue;
            $r['puestos'][$g[1]] ??= $orden[$p['fase']];
        }
        if ($cerrado($final) && ($g = ganador_partido($final))) {
            $r['puestos'][$g[0]] = 1;
            $r['puestos'][$g[1]] = 2;
            $r['terminada'] = true;
        }
        if ($tercer) {
            if ($cerrado($tercer) && ($g = ganador_partido($tercer))) { $r['puestos'][$g[0]] = 3; $r['puestos'][$g[1]] = 4; }
            else $r['terminada'] = false;
        }
    } else {
        $tabla = tabla_posiciones($tid);
        $pend = (int)valor("SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND estado NOT IN ('jugado','wo','suspendido')", [$tid]);
        $total = (int)valor('SELECT COUNT(*) FROM partidos WHERE torneo_id = ?', [$tid]);
        if (count($tabla) === 1) {
            foreach (array_values($tabla)[0] as $i => $f) if ($f['pj'] > 0) $r['puestos'][(int)$f['id']] = $i + 1;
            $r['terminada'] = $total > 0 && $pend === 0;
        }
    }
    if (!empty($t['campeon_id'])) {
        $r['puestos'][(int)$t['campeon_id']] = 1;
        if (!empty($t['subcampeon_id'])) $r['puestos'][(int)$t['subcampeon_id']] = 2;
        $r['terminada'] = true;
    }
    if ($t['estado'] === 'finalizado' && $r['puestos']) $r['terminada'] = true;
    return $r;
}

/**
 * Resultados por disciplina: lista de conjuntos (cada categoría o prueba reparte sus puestos).
 * Cada conjunto: ['disc' => id, 'nombre' => texto, 'terminada' => bool, 'filas' => [['puesto', 'del' => id|null, 'quien' => texto]]].
 */
function olimp_conjuntos(array $ev, array $d, array $dels, array $ix): array
{
    $t = olimp_torneo($d);
    if (!$t) return [];
    $del_de = [];
    $quien_del = function (?int $dep_id, string $club) use (&$del_de, $ix): ?int {
        if ($dep_id) {
            $del_de[$dep_id] ??= (int)valor('SELECT delegacion_id FROM olimp_deportistas WHERE id = ?', [$dep_id]);
            if ($del_de[$dep_id]) return $del_de[$dep_id];
        }
        return $ix[olimp_clave_nombre($club)] ?? null;
    };
    $nombre = olimp_nombre_disciplina($d);
    // podio cargado a mano por el organizador
    $manual = json_decode((string)$d['manual'], true);
    if (is_array($manual) && $manual) {
        $filas = [];
        foreach ($manual as $x) if (isset($dels[(int)($x['del'] ?? 0)])) $filas[] = ['puesto' => max(1, (int)$x['puesto']), 'del' => (int)$x['del'], 'quien' => $dels[(int)$x['del']]['nombre']];
        usort($filas, fn($a, $b) => $a['puesto'] <=> $b['puesto']);
        return [['disc' => (int)$d['id'], 'nombre' => $nombre, 'terminada' => true, 'manual' => true, 'filas' => $filas]];
    }
    $out = [];
    switch (olimp_tipo($t)) {
        case 'equipo':
            $pu = olimp_puestos_equipos($t);
            $map = [];
            foreach (filas('SELECT equipo_id, delegacion_id FROM olimp_equipos WHERE disciplina_id = ?', [$d['id']]) as $x) $map[(int)$x['equipo_id']] = (int)$x['delegacion_id'];
            $filas = [];
            asort($pu['puestos']);
            foreach ($pu['puestos'] as $eid => $ps) {
                $eq = fila('SELECT nombre FROM equipos WHERE id = ?', [$eid]);
                $filas[] = ['puesto' => $ps, 'del' => $map[$eid] ?? ($ix[olimp_clave_nombre((string)($eq['nombre'] ?? ''))] ?? null), 'quien' => (string)($eq['nombre'] ?? '')];
            }
            $out[] = ['disc' => (int)$d['id'], 'nombre' => $nombre, 'terminada' => $pu['terminada'], 'filas' => $filas];
            break;
        case 'duelo':
            $part = duelo_participantes((int)$t['id']);
            foreach (duelo_categorias((int)$t['id']) as $cat) {
                $pu = duelo_puestos($t, $cat);
                if (!$pu['puestos']) continue;
                asort($pu['puestos']);
                $filas = [];
                foreach ($pu['puestos'] as $pid => $ps) {
                    $p = $part[$pid] ?? null;
                    if (!$p) continue;
                    $filas[] = ['puesto' => (int)$ps, 'del' => $quien_del($p['olimp_dep_id'] ?? null ? (int)$p['olimp_dep_id'] : null, (string)$p['club']), 'quien' => $p['nombre'] . ($p['pareja'] !== '' ? ' / ' . $p['pareja'] : '')];
                }
                $out[] = ['disc' => (int)$d['id'], 'nombre' => $nombre . ' · ' . $cat['nombre'], 'terminada' => $pu['terminada'], 'filas' => $filas];
            }
            break;
        case 'pruebas':
            $part = pruebas_participantes((int)$t['id']);
            foreach (pruebas_eventos((int)$t['id']) as $pe) {
                $filas = [];
                foreach (prueba_resultados($pe, $part) as $x) {
                    if ($x['puesto'] === null) continue;
                    $filas[] = ['puesto' => (int)$x['puesto'], 'del' => $quien_del(!empty($x['p']['olimp_dep_id']) ? (int)$x['p']['olimp_dep_id'] : null, (string)$x['p']['club']), 'quien' => (string)$x['p']['nombre']];
                }
                if (!$filas) continue;
                $out[] = ['disc' => (int)$d['id'], 'nombre' => $nombre . ' · ' . prueba_nombre($pe, false), 'terminada' => (bool)array_filter($filas, fn($f) => $f['puesto'] === 1), 'filas' => $filas];
            }
            break;
        default: // carreras y bolos: la clasificación general por categoría, al finalizar el campeonato
            $gen = es_bolos($t) ? bolos_general($t)['cats'] : array_values(carrera_general($t)['cats']);
            foreach ($gen as $c) {
                $filas = [];
                foreach ($c['filas'] as $x) {
                    if (empty($x['pos'])) continue;
                    if (!es_bolos($t) && ($x['puntos'] ?? 0) <= 0 && ($x['fechas_ok'] ?? 0) === 0) continue;
                    $filas[] = ['puesto' => (int)$x['pos'], 'del' => $quien_del(!empty($x['p']['olimp_dep_id']) ? (int)$x['p']['olimp_dep_id'] : null, (string)$x['p']['club']), 'quien' => (string)$x['p']['nombre']];
                }
                if (!$filas) continue;
                $out[] = ['disc' => (int)$d['id'], 'nombre' => $nombre . ' · ' . $c['cat']['nombre'], 'terminada' => $t['estado'] === 'finalizado', 'filas' => $filas];
            }
    }
    return $out;
}

/**
 * Tabla general del evento. Devuelve:
 *  'tabla' => [['del' => fila, 'oro', 'plata', 'bronce', 'puntos', 'pts_puestos', 'pts_part', 'pts_extra', 'disciplinas' => n, 'pos']],
 *  'conjuntos' => [...] (con 'pts' por fila), 'cfg', 'pendientes' => n (categorías que todavía no terminan)
 */
function olimp_tabla(array $ev): array
{
    $cfg = olimp_cfg($ev);
    $dels = olimp_delegaciones((int)$ev['id']);
    $ix = olimp_indice_delegaciones($dels);
    $discs = olimp_disciplinas((int)$ev['id']);
    $tabla = [];
    foreach ($dels as $id => $x) $tabla[$id] = ['del' => $x, 'oro' => 0, 'plata' => 0, 'bronce' => 0, 'puntos' => 0.0, 'pts_puestos' => 0.0, 'pts_part' => 0.0, 'pts_extra' => 0.0, 'disciplinas' => []];
    $conjuntos = [];
    $pendientes = 0;
    foreach ($discs as $d) {
        $factor = max(0, (float)$d['factor']);
        foreach (olimp_conjuntos($ev, $d, $dels, $ix) as $c) {
            foreach ($c['filas'] as $f) if ($f['del'] !== null && isset($tabla[$f['del']])) $tabla[$f['del']]['disciplinas'][(int)$d['id']] = true;
            $cuenta = $c['terminada'] || $cfg['provisional'];
            if (!$c['terminada']) $pendientes++;
            $vistos = [];
            foreach ($c['filas'] as $k => $f) {
                $c['filas'][$k]['pts'] = 0.0;
                if (!$cuenta || $f['del'] === null || !isset($tabla[$f['del']])) continue;
                $vistos[$f['del']] = ($vistos[$f['del']] ?? 0) + 1;
                if ($cfg['puntuan'] > 0 && $vistos[$f['del']] > $cfg['puntuan']) continue; // solo puntúan los mejores N de cada delegación
                if ($f['puesto'] <= 3) $tabla[$f['del']][['oro', 'plata', 'bronce'][$f['puesto'] - 1]]++;
                $pts = ($cfg['puntos'][$f['puesto'] - 1] ?? 0) * $factor;
                $c['filas'][$k]['pts'] = $pts;
                $tabla[$f['del']]['pts_puestos'] += $pts;
            }
            $c['cuenta'] = $cuenta;
            $conjuntos[] = $c;
        }
        // las inscripciones también cuentan como participación aunque todavía no haya resultados
        foreach (filas('SELECT DISTINCT p.delegacion_id FROM olimp_inscripciones i JOIN olimp_deportistas p ON p.id = i.deportista_id WHERE i.disciplina_id = ? AND p.estado = \'aprobado\'', [$d['id']]) as $x) {
            if (isset($tabla[(int)$x['delegacion_id']])) $tabla[(int)$x['delegacion_id']]['disciplinas'][(int)$d['id']] = true;
        }
    }
    foreach (filas('SELECT delegacion_id, SUM(puntos) AS s FROM olimp_puntos_extra WHERE evento_id = ? GROUP BY delegacion_id', [$ev['id']]) as $x) {
        if (isset($tabla[(int)$x['delegacion_id']])) $tabla[(int)$x['delegacion_id']]['pts_extra'] = (float)$x['s'];
    }
    foreach ($tabla as &$x) {
        $x['pts_part'] = $cfg['participacion'] * count($x['disciplinas']);
        $x['disciplinas'] = count($x['disciplinas']);
        $x['puntos'] = round($x['pts_puestos'] + $x['pts_part'] + $x['pts_extra'], 2);
    }
    unset($x);
    $tabla = array_values(array_filter($tabla, fn($x) => (int)$x['del']['activo'] === 1 || $x['puntos'] != 0 || $x['oro'] + $x['plata'] + $x['bronce'] > 0));
    $clave = $cfg['clasificacion'] === 'medallas'
        ? fn($x) => [$x['oro'], $x['plata'], $x['bronce'], $x['puntos']]
        : fn($x) => [$x['puntos'], $x['oro'], $x['plata'], $x['bronce']];
    usort($tabla, fn($a, $b) => $clave($b) <=> $clave($a) ?: strcmp($a['del']['nombre'], $b['del']['nombre']));
    $ant = null;
    foreach ($tabla as $i => &$x) {
        $x['pos'] = $ant !== null && $clave($x) === $clave($ant) ? $ant['pos'] : $i + 1;
        $ant = $x;
    }
    unset($x);
    return ['tabla' => $tabla, 'conjuntos' => $conjuntos, 'cfg' => $cfg, 'pendientes' => $pendientes, 'discs' => $discs, 'dels' => $dels];
}

/* ============================================================
 * HTML COMPARTIDO (panel y página pública)
 * ============================================================ */

function olimp_html_logo(array $x, string $clase = 'escudo'): string
{
    return escudo((string)($x['logo'] ?? ''), (string)$x['nombre'], color_valido((string)($x['color'] ?? ''), '#0b6e4f'), $clase);
}

/** Tabla general (medallero y puntos). */
function olimp_html_tabla(array $res, string $enlace_del = ''): string
{
    $cfg = $res['cfg'];
    if (!$res['tabla']) return '<p class="suave">Todavía no hay delegaciones.</p>';
    $con_part = $cfg['participacion'] > 0;
    $con_extra = (bool)array_filter($res['tabla'], fn($x) => $x['pts_extra'] != 0);
    ob_start(); ?>
    <div class="tabla-scroll"><table class="olimp-tabla">
      <tr><th>#</th><th>Delegación</th><th class="num" title="Oro">🥇</th><th class="num" title="Plata">🥈</th><th class="num" title="Bronce">🥉</th><th class="num">Puntos</th><th class="num olimp-opc">Total</th>
        <?php if ($con_part): ?><th class="num olimp-opc" title="Puntos por participar">Particip.</th><?php endif; ?><?php if ($con_extra): ?><th class="num olimp-opc" title="Puntos extra">Extra</th><?php endif; ?></tr>
      <?php foreach ($res['tabla'] as $x): $tm = $x['oro'] + $x['plata'] + $x['bronce']; ?>
        <tr<?= $x['pos'] === 1 && ($x['puntos'] > 0 || $tm > 0) ? ' class="olimp-lider"' : '' ?>>
          <td><b><?= $x['pos'] ?></b></td>
          <td><span class="eq-celda"><?= olimp_html_logo($x['del']) ?> <?= $enlace_del !== '' ? '<a href="' . e($enlace_del . (int)$x['del']['id']) . '">' . e($x['del']['nombre']) . '</a>' : e($x['del']['nombre']) ?></span></td>
          <td class="num"><?= $x['oro'] ?></td><td class="num"><?= $x['plata'] ?></td><td class="num"><?= $x['bronce'] ?></td>
          <td class="num pts"><b><?= olimp_num($x['puntos']) ?></b></td><td class="num olimp-opc"><?= $tm ?></td>
          <?php if ($con_part): ?><td class="num olimp-opc"><?= olimp_num($x['pts_part']) ?></td><?php endif; ?><?php if ($con_extra): ?><td class="num olimp-opc"><?= olimp_num($x['pts_extra']) ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <p class="ayuda"><span><?= $cfg['clasificacion'] === 'medallas' ? 'Clasificación por medallas (oros, luego platas y bronces; después los puntos).' : 'Clasificación por puntos (si empatan, más oros, platas y bronces).' ?></span>
      <span>Puntos por puesto:</span> <span><?= e(implode(' · ', array_map(fn($p, $i) => ($i + 1) . '.º = ' . olimp_num((float)$p), $cfg['puntos'], array_keys($cfg['puntos'])))) ?>.</span>
      <?php if ($cfg['puntuan'] > 0): ?><span>En cada categoría puntúan los <?= $cfg['puntuan'] ?> mejores de cada delegación.</span><?php endif; ?>
      <?php if ($con_part): ?><span><?= $cfg['participacion'] ?> puntos por cada disciplina en la que participa.</span><?php endif; ?>
      <?php if ($res['pendientes'] && !$cfg['provisional']): ?><span>Las categorías que todavía se juegan suman al terminar.</span><?php endif; ?></p>
    <?php return (string)ob_get_clean();
}

/** Podios de cada categoría o prueba (las terminadas primero). */
function olimp_html_podios(array $res, ?int $solo_disc = null, ?int $solo_del = null): string
{
    $cs = array_values(array_filter($res['conjuntos'], fn($c) => ($solo_disc === null || $c['disc'] === $solo_disc) && ($solo_del === null || array_filter($c['filas'], fn($f) => $f['del'] === $solo_del))));
    if (!$cs) return '<p class="suave">Todavía no hay resultados.</p>';
    usort($cs, fn($a, $b) => [$b['terminada'], $a['nombre']] <=> [$a['terminada'], $b['nombre']]);
    $dels = $res['dels'];
    ob_start(); ?>
    <div class="rejilla-2" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
    <?php foreach ($cs as $c): ?>
      <div class="tarjeta" style="margin:0;min-width:0">
        <h3 style="margin:0 0 6px;font-size:1rem"><?= e($c['nombre']) ?> <?= $c['terminada'] ? '' : '<span class="etiqueta gris">en juego</span>' ?><?= !empty($c['manual']) ? ' <span class="etiqueta gris">podio a mano</span>' : '' ?></h3>
        <table style="font-size:.9rem">
          <?php foreach (array_slice($c['filas'], 0, $solo_del ? 50 : 8) as $f): if ($solo_del && $f['del'] !== $solo_del) continue; ?>
            <tr><td style="width:2.4em"><?= $f['puesto'] <= 3 ? icono_medalla(medalla_de_puesto($f['puesto'])) : $f['puesto'] . '.º' ?></td>
              <td><?= e($f['quien']) ?><?php if ($f['del'] !== null && isset($dels[$f['del']]) && $dels[$f['del']]['nombre'] !== $f['quien']): ?><br><small class="suave"><?= e($dels[$f['del']]['nombre']) ?></small><?php elseif ($f['del'] === null): ?><br><small class="suave">sin delegación</small><?php endif; ?></td>
              <td class="num"><?= !empty($f['pts']) ? '<small>' . olimp_num($f['pts']) . ' pts</small>' : '' ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
    </div>
    <?php return (string)ob_get_clean();
}

/** Programa general (disciplinas por fecha y hora). */
function olimp_html_programa(array $discs, ?callable $enlace = null): string
{
    if (!$discs) return '<p class="suave">Todavía no hay disciplinas.</p>';
    $por_dia = [];
    foreach ($discs as $d) $por_dia[$d['fecha'] !== '' ? $d['fecha'] : 'zzzz'][] = $d;
    ksort($por_dia);
    ob_start();
    foreach ($por_dia as $f => $l): usort($l, fn($a, $b) => [$a['hora_ini'], $a['id']] <=> [$b['hora_ini'], $b['id']]); ?>
      <h3><?= $f === 'zzzz' ? 'Fecha por definir' : e(fecha_bonita($f)) ?></h3>
      <div class="olimp-prog">
        <?php foreach ($l as $d): ?>
          <div class="olimp-prog-fila"><span class="olimp-prog-hora"><?= $d['hora_ini'] !== '' ? e($d['hora_ini']) . ($d['hora_fin'] !== '' ? ' a ' . e($d['hora_fin']) : '') : '—' ?></span>
            <span class="olimp-prog-nom"><?= olimp_icono($d) ?> <b><?= $enlace ? '<a href="' . e($enlace($d)) . '">' . e(olimp_nombre_disciplina($d)) . '</a>' : e(olimp_nombre_disciplina($d)) ?></b><?= $d['rama'] !== '' ? ' <small class="suave">' . e(olimp_ramas()[$d['rama']]) . '</small>' : '' ?></span>
            <span class="olimp-prog-lugar"><?= $d['lugar'] !== '' ? '📍 ' . e($d['lugar']) : '' ?></span>
            <span class="olimp-prog-est"><?= $d['t_estado'] === 'finalizado' ? '<span class="etiqueta">✓ terminó</span>' : ($d['t_estado'] === 'en_juego' ? '<span class="etiqueta">en juego</span>' : '') ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach;
    return (string)ob_get_clean();
}

function olimp_css(): string
{
    return '<style>.olimp-lider td{background:color-mix(in srgb,var(--acento,#0b6e4f) 12%,transparent)}.olimp-cab{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.olimp-cab .escudo{width:64px;height:64px;font-size:1.4rem}'
        . '.olimp-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin:10px 0}.olimp-kpis div{background:var(--tarjeta,#fff);border:1px solid var(--borde,#ddd);border-radius:10px;padding:10px;text-align:center}.olimp-kpis b{display:block;font-size:1.5rem}'
        . '.olimp-prog{background:var(--tarjeta,#fff);border:1px solid var(--borde,#ddd);border-radius:10px}.olimp-prog-fila{display:grid;grid-template-columns:9.5em 1fr 30% 7.5em;gap:8px;align-items:center;padding:9px 10px;border-bottom:1px solid var(--borde,#e5e5e5)}.olimp-prog-fila:last-child{border-bottom:0}'
        . '.olimp-lista-dep{display:flex;flex-direction:column;gap:0;background:var(--tarjeta,#fff);border:1px solid var(--borde,#ddd);border-radius:10px}.olimp-lista-dep>div{padding:10px 12px;border-bottom:1px solid var(--borde,#e5e5e5)}.olimp-lista-dep>div:last-child{border-bottom:0}'
        . '@media (max-width:640px){.olimp-opc{display:none}.olimp-tabla th,.olimp-tabla td{padding:6px 4px;white-space:normal}.olimp-tabla .eq-celda{white-space:normal}.olimp-prog-fila{grid-template-columns:1fr auto;gap:2px 8px}.olimp-prog-hora{grid-column:1/-1;font-size:.85rem;color:var(--suave,#666)}.olimp-prog-lugar{grid-column:1;font-size:.85rem}.olimp-prog-est{grid-column:2;grid-row:2}}</style>';
}

/* ============================================================
 * DEMO
 * ============================================================ */

/**
 * Evento de ejemplo en una cuenta: «Olimpiadas Interempresas» con 5 delegaciones, deportistas y 5 disciplinas
 * (fútbol 7 con final, vóley por tabla, tenis de mesa, natación y ajedrez) con resultados. Devuelve el id del evento.
 */
function olimp_cargar_ejemplo(array $liga, string $sufijo = ''): int
{
    $lid = (int)$liga['id'];
    $evid = olimp_crear_evento($liga, 'Olimpiadas Interempresas 2026' . $sufijo, date('Y-m-d', strtotime('-6 days')), date('Y-m-d', strtotime('+8 days')), 'Complejo deportivo central');
    $ev = olimp_evento($evid);
    $cfg = olimp_cfg_fabrica();
    $cfg['participacion'] = 2;
    $cfg['aprobacion'] = 'auto';
    $cfg['exigir_requisitos'] = 0;
    q("UPDATE olimp_eventos SET config = ?, estado = 'en_juego', descripcion = ? WHERE id = ?", [json_encode($cfg), 'Juegos deportivos entre empresas: cada empresa es una delegación y suma puntos en todas las disciplinas.', $evid]);
    $ev = olimp_evento($evid);
    $nombres_del = [['Banco Andino', 'BAN', '#1f5fae'], ['Banco del Valle', 'BDV', '#0b8a4b'], ['Cooperativa Progreso', 'CPR', '#d97706'], ['Seguros Cóndor', 'SCO', '#9333ea'], ['Mutualista Norte', 'MNO', '#dc2626']];
    $dels = [];
    foreach ($nombres_del as [$n, $s, $c]) {
        q('INSERT INTO olimp_delegaciones (evento_id, nombre, sigla, color, contacto, telefono, token) VALUES (?,?,?,?,?,?,?)', [$evid, $n, $s, $c, 'Coordinador de deportes', '0990000000', olimp_token()]);
        $dels[] = fila('SELECT * FROM olimp_delegaciones WHERE id = ?', [(int)db()->lastInsertId()]);
    }
    // disciplinas: se crean los campeonatos
    $crear = function (string $nombre, string $mod, array $extra = []) use ($liga, $lid, $ev): array {
        $datos = array_merge(['liga_id' => $lid, 'nombre' => $nombre, 'temporada' => date('Y'), 'formato' => 'liga', 'num_grupos' => 1, 'vueltas' => 1, 'modalidad' => $mod, 'estado' => 'en_juego'],
            array_intersect_key(presets_modalidad()[$mod] ?? [], array_flip(['jugadores_cancha', 'min_para_jugar', 'max_suplentes', 'minutos_tiempo', 'min_plantilla', 'max_plantilla', 'num_tiempos', 'puntos_ganado', 'puntos_empate', 'puntos_perdido', 'sets_ganar', 'puntos_set', 'puntos_set_final'])), $extra);
        if (isset(modalidades_duelo()[$mod])) $datos += duelo_reglas_fabrica($mod);
        q('INSERT INTO torneos (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($datos))) . ')', $datos);
        $tid = (int)db()->lastInsertId();
        guardar_slug_torneo(fila('SELECT * FROM torneos WHERE id = ?', [$tid]), '');
        $did = olimp_vincular_torneo($ev, $tid);
        return fila('SELECT d.*, t.modalidad, t.nombre AS t_nombre FROM olimp_disciplinas d JOIN torneos t ON t.id = d.torneo_id WHERE d.id = ?', [$did]);
    };
    $hoy = date('Y-m-d');
    $f = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
    $d_fut = $crear('Fútbol 7 Interempresas' . $sufijo, 'F7');
    $d_vol = $crear('Vóley mixto Interempresas' . $sufijo, 'VOLEY');
    $d_tm = $crear('Tenis de mesa Interempresas' . $sufijo, 'TMESA');
    $d_nat = $crear('Natación Interempresas' . $sufijo, 'NATAC', ['carrera_puntos' => '9,7,6,5,4,3,2,1']);
    $d_aj = $crear('Ajedrez Interempresas' . $sufijo, 'AJED');
    $prog = [[$d_fut, $f(-5), '09:00', '13:00', 'Cancha sintética 1', 1.5, ''], [$d_vol, $f(-4), '09:00', '12:00', 'Coliseo', 1, 'X'], [$d_tm, $f(-3), '14:00', '18:00', 'Salón de tenis de mesa', 1, ''],
        [$d_nat, $f(-2), '08:00', '11:00', 'Piscina olímpica', 1, ''], [$d_aj, $f(2), '15:00', '19:00', 'Salón de ajedrez', 1, '']];
    foreach ($prog as [$d, $fe, $hi, $hf, $lu, $fa, $ra]) q('UPDATE olimp_disciplinas SET fecha = ?, hora_ini = ?, hora_fin = ?, lugar = ?, factor = ?, rama = ? WHERE id = ?', [$fe, $hi, $hf, $lu, $fa, $ra, $d['id']]);
    // categorías y pruebas
    $t_tm = fila('SELECT * FROM torneos WHERE id = ?', [$d_tm['torneo_id']]);
    q('INSERT INTO duelo_categorias (torneo_id, nombre, orden, formato, rama) VALUES (?,?,?,?,?)', [$t_tm['id'], 'Individual varones', 1, 'llave', 'M']);
    $cat_tm_v = (int)db()->lastInsertId();
    q('INSERT INTO duelo_categorias (torneo_id, nombre, orden, formato, rama) VALUES (?,?,?,?,?)', [$t_tm['id'], 'Individual damas', 2, 'liga', 'F']);
    $cat_tm_d = (int)db()->lastInsertId();
    $t_aj = fila('SELECT * FROM torneos WHERE id = ?', [$d_aj['torneo_id']]);
    q('INSERT INTO duelo_categorias (torneo_id, nombre, orden, formato, rama) VALUES (?,?,?,?,?)', [$t_aj['id'], 'Abierto', 1, 'suizo', '']);
    $cat_aj = (int)db()->lastInsertId();
    $t_nat = fila('SELECT * FROM torneos WHERE id = ?', [$d_nat['torneo_id']]);
    $evs_nat = [];
    foreach ([['50 m libre', 'M'], ['50 m libre', 'F'], ['50 m pecho', 'M'], ['50 m pecho', 'F']] as $k => [$pn, $pr]) {
        q('INSERT INTO prueba_eventos (torneo_id, num, nombre, medida, rama, edad_min, edad_max, categoria, fecha) VALUES (?,?,?,?,?,?,?,?,?)', [$t_nat['id'], $k + 1, $pn, 'tiempo', $pr, 0, 0, '', $f(-2)]);
        $evs_nat[$pr][] = (int)db()->lastInsertId();
    }
    // deportistas: 8 por delegación (4 varones y 4 damas)
    $nv = ['Andrés', 'Carlos', 'Diego', 'Fernando', 'Jorge', 'Luis', 'Mateo', 'Pablo', 'Ricardo', 'Santiago'];
    $nd = ['Ana', 'Carla', 'Daniela', 'Fernanda', 'Gabriela', 'Lucía', 'María', 'Paola', 'Sofía', 'Valeria'];
    $ap = ['Andrade', 'Benítez', 'Cevallos', 'Espinoza', 'Guerrero', 'Jaramillo', 'Mena', 'Ortiz', 'Pazmiño', 'Ruiz', 'Salazar', 'Torres', 'Vaca', 'Zambrano'];
    $deps = [];
    $n = 0;
    foreach ($dels as $di => $del) {
        for ($k = 0; $k < 8; $k++) {
            $sexo = $k < 5 ? 'M' : 'F';
            $nom = $sexo === 'M' ? $nv[($di * 3 + $k) % 10] : $nd[($di * 3 + $k) % 10];
            $id = olimp_guardar_deportista($ev, (int)$del['id'], ['nombres' => $nom, 'apellidos' => $ap[($di * 5 + $k * 3) % 14] . ' ' . $ap[($di + $k * 7) % 14], 'cedula' => sprintf('17%08d', 10000 + $n++),
                'sexo' => $sexo, 'fecha_nac' => (1975 + ($di * 7 + $k * 3) % 25) . '-0' . (1 + $k % 9) . '-1' . $k, 'cargo' => ['Analista', 'Cajero', 'Asesor comercial', 'Jefe de agencia', 'Contador'][($di + $k) % 5]]);
            $deps[$di][$sexo][] = fila('SELECT * FROM olimp_deportistas WHERE id = ?', [$id]);
        }
    }
    $ins = fn(array $dep, array $d, array $op = []) => olimp_inscribir($ev, $dep, $d, $op, true);
    foreach ($dels as $di => $del) {
        foreach ($deps[$di]['M'] as $dep) $ins($dep, $d_fut);
        foreach (array_merge(array_slice($deps[$di]['M'], 0, 3), array_slice($deps[$di]['F'], 0, 3)) as $dep) $ins($dep, $d_vol);
        $ins($deps[$di]['M'][0], $d_tm, ['categoria' => $cat_tm_v]);
        $ins($deps[$di]['M'][1], $d_tm, ['categoria' => $cat_tm_v]);
        $ins($deps[$di]['F'][0], $d_tm, ['categoria' => $cat_tm_d]);
        $ins($deps[$di]['M'][2], $d_nat, ['eventos' => $evs_nat['M']]);
        $ins($deps[$di]['F'][1], $d_nat, ['eventos' => $evs_nat['F']]);
        $ins($deps[$di]['M'][3], $d_aj, ['categoria' => $cat_aj]);
        $ins($deps[$di]['F'][2], $d_aj, ['categoria' => $cat_aj]);
    }
    // FÚTBOL 7: semifinales, tercer puesto y final
    $eqs = [];
    foreach ($dels as $del) $eqs[] = (int)valor('SELECT equipo_id FROM olimp_equipos WHERE disciplina_id = ? AND delegacion_id = ?', [$d_fut['id'], $del['id']]);
    $part = function (int $tid, string $fase, int $j, int $l, int $v, int $gl, int $gv, string $fecha, ?int $pl = null, ?int $pv = null): void {
        q("INSERT INTO partidos (torneo_id, fase, jornada, local_id, visitante_id, fecha, hora, estado, goles_local, goles_visitante, penales_local, penales_visitante) VALUES (?,?,?,?,?,?,?, 'jugado', ?,?,?,?)",
            [$tid, $fase, $j, $l, $v, $fecha, '10:00', $gl, $gv, $pl, $pv]);
    };
    $tf = (int)$d_fut['torneo_id'];
    // fase de grupos corta: todos contra todos de 5 (10 partidos)
    $res = [[2, 1], [0, 0], [3, 1], [1, 2], [2, 2], [4, 0], [1, 0], [0, 1], [2, 0], [1, 3]];
    $k = 0;
    for ($a = 0; $a < 5; $a++) for ($b = $a + 1; $b < 5; $b++) { [$gl, $gv] = $res[$k++]; $part($tf, 'regular', 1 + intdiv($k, 3), $eqs[$a], $eqs[$b], $gl, $gv, $f(-5)); }
    $part($tf, 'semifinal', 1, $eqs[0], $eqs[3], 2, 1, $f(-5));
    $part($tf, 'semifinal', 1, $eqs[1], $eqs[2], 1, 1, $f(-5), 4, 3);
    $part($tf, 'tercer', 1, $eqs[3], $eqs[2], 3, 2, $f(-5));
    $part($tf, 'final', 1, $eqs[0], $eqs[1], 0, 2, $f(-5));
    // goles de ejemplo para la tabla de goleadores
    foreach (filas("SELECT p.id, p.local_id, p.goles_local FROM partidos p WHERE p.torneo_id = ? AND p.goles_local > 0", [$tf]) as $p) {
        $js = filas('SELECT id FROM jugadores WHERE equipo_id = ? ORDER BY id LIMIT 3', [$p['local_id']]);
        for ($g = 0; $g < (int)$p['goles_local'] && $js; $g++) q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto) VALUES (?,?,?, 'gol', ?)", [$p['id'], $js[$g % count($js)]['id'], $p['local_id'], 5 + $g * 9]);
    }
    q("UPDATE torneos SET estado = 'finalizado' WHERE id = ?", [$tf]);
    // VÓLEY: todos contra todos, todos jugados (por sets)
    $tv = (int)$d_vol['torneo_id'];
    $eqv = [];
    foreach ($dels as $del) $eqv[] = (int)valor('SELECT equipo_id FROM olimp_equipos WHERE disciplina_id = ? AND delegacion_id = ?', [$d_vol['id'], $del['id']]);
    $sv = [[2, 0], [2, 1], [0, 2], [2, 0], [1, 2], [2, 1], [2, 0], [0, 2], [2, 1], [2, 0]];
    $k = 0;
    for ($a = 0; $a < 5; $a++) for ($b = $a + 1; $b < 5; $b++) { [$gl, $gv] = $sv[$k++]; $part($tv, 'regular', 1 + intdiv($k, 3), $eqv[$a], $eqv[$b], $gl, $gv, $f(-4)); }
    q("UPDATE torneos SET estado = 'finalizado' WHERE id = ?", [$tv]);
    // TENIS DE MESA: varones por llave (sorteo y resultados) y damas todos contra todos
    $t_tm = fila('SELECT * FROM torneos WHERE id = ?', [$t_tm['id']]);
    foreach ([$cat_tm_v, $cat_tm_d] as $cid) {
        $cat = fila('SELECT * FROM duelo_categorias WHERE id = ?', [$cid]);
        duelo_sortear($t_tm, $cat);
        duelo_jugar_azar($t_tm, $cat, 99);
    }
    q("UPDATE torneos SET estado = 'finalizado' WHERE id = ?", [$t_tm['id']]);
    // NATACIÓN: tiempos
    $t_nat = fila('SELECT * FROM torneos WHERE id = ?', [$t_nat['id']]);
    $tiempos = [31.2, 29.8, 33.5, 30.4, 32.9, 36.1, 34.7, 38.2, 35.0, 37.4];
    $k = 0;
    foreach (filas('SELECT r.id, e.nombre FROM prueba_resultados r JOIN prueba_eventos e ON e.id = r.evento_id WHERE e.torneo_id = ? ORDER BY r.id', [$t_nat['id']]) as $r) {
        $base = str_contains($r['nombre'], 'pecho') ? 8 : 0;
        q('UPDATE prueba_resultados SET marca = ? WHERE id = ?', [(int)round(($tiempos[$k++ % 10] + $base) * 1000), $r['id']]);
    }
    q("UPDATE torneos SET estado = 'finalizado' WHERE id = ?", [$t_nat['id']]);
    // AJEDREZ: queda por jugar (para ver una disciplina pendiente); se arma la ronda 1
    $t_aj = fila('SELECT * FROM torneos WHERE id = ?', [$t_aj['id']]);
    duelo_sortear($t_aj, fila('SELECT * FROM duelo_categorias WHERE id = ?', [$cat_aj]));
    // puntos extra: desfile inaugural
    q('INSERT INTO olimp_puntos_extra (evento_id, delegacion_id, puntos, motivo, por) VALUES (?,?,?,?,?)', [$evid, $dels[2]['id'], 5, 'Mejor delegación en el desfile inaugural', 'Organización']);
    q('INSERT INTO olimp_puntos_extra (evento_id, delegacion_id, puntos, motivo, por) VALUES (?,?,?,?,?)', [$evid, $dels[4]['id'], 3, 'Mejor barra', 'Organización']);
    return $evid;
}

/** (v37) Campeonato recién creado desde la pantalla del evento (campo «olimpiada»): queda como disciplina y vuelve al evento. */
function olimp_tras_crear_torneo(int $tid): void
{
    $evid = (int)post('olimpiada');
    if ($evid <= 0 || !($liga = liga_trabajo())) return;
    $ev = olimp_evento($evid, (int)$liga['id']);
    if (!$ev || !olimp_puede_evento($ev, true)) return;
    $did = olimp_vincular_torneo($ev, $tid);
    q('UPDATE olimp_disciplinas SET fecha = ?, hora_ini = ?, hora_fin = ?, lugar = ?, rama = ? WHERE id = ?', [
        post('d_fecha') !== '' && strtotime(post('d_fecha')) ? date('Y-m-d', strtotime(post('d_fecha'))) : (string)$ev['fecha_ini'],
        preg_match('/^\d{2}:\d{2}$/', post('d_hora_ini')) ? post('d_hora_ini') : '', preg_match('/^\d{2}:\d{2}$/', post('d_hora_fin')) ? post('d_hora_fin') : '',
        mb_substr(post('d_lugar'), 0, 80), array_key_exists(post('d_rama'), olimp_ramas()) ? post('d_rama') : '', $did]);
    bitacora_evento('campeonatos', 'Creó la disciplina ' . post('nombre') . ' en ' . $ev['nombre'], (int)$liga['id']);
    flash('Disciplina creada: «' . post('nombre') . '». Las delegaciones ya pueden inscribir a su gente. Para armar el fixture, sorteos o el programa de pruebas, toque «Abrir campeonato».');
    ir('admin.php?p=olimpiadas&id=' . $evid . '&s=disciplinas');
}

/* ============================================================
 * FORMULARIOS COMPARTIDOS (panel y portal de la delegación)
 * ============================================================ */

/** Campos de la ficha del deportista (sin <form>). Con $dels (panel) se elige la delegación. */
function olimp_html_campos_deportista(?array $dep, array $ev, ?array $dels = null, bool $publico = false): string
{
    $v = fn(string $k) => e((string)($dep[$k] ?? ''));
    ob_start(); ?>
    <div class="campos">
      <?php if ($dels !== null): ?>
      <div><label>Delegación *</label><select name="delegacion_id" required><option value="">—</option><?php foreach ($dels as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)($dep['delegacion_id'] ?? 0) === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nombre']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div><label>Nombres *</label><input type="text" name="nombres" required maxlength="60" value="<?= $v('nombres') ?>"></div>
      <div><label>Apellidos *</label><input type="text" name="apellidos" required maxlength="60" value="<?= $v('apellidos') ?>"></div>
      <div><label>Cédula o pasaporte *</label><input type="text" name="cedula" required maxlength="20" value="<?= $v('cedula') ?>" inputmode="numeric"></div>
      <div><label>Sexo *</label><select name="sexo" required><option value="">—</option><option value="M" <?= ($dep['sexo'] ?? '') === 'M' ? 'selected' : '' ?>>Varón</option><option value="F" <?= ($dep['sexo'] ?? '') === 'F' ? 'selected' : '' ?>>Dama</option></select></div>
      <div><label>Fecha de nacimiento *</label><input type="date" name="fecha_nac" required value="<?= $v('fecha_nac') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div><label>Cargo o área en la empresa</label><input type="text" name="cargo" maxlength="80" value="<?= $v('cargo') ?>" placeholder="Ej: Analista de crédito"></div>
      <div><label>Celular</label><input type="tel" name="telefono" maxlength="20" value="<?= $v('telefono') ?>" inputmode="numeric"></div>
      <div><label>Correo</label><input type="email" name="email" maxlength="120" value="<?= $v('email') ?>"></div>
    </div>
    <?= html_requisitos_form(olimp_mod_req($ev), 'olimp_deportistas', (int)($dep['id'] ?? 0), $publico, false) ?>
    <?php if (olimp_cfg($ev)['exigir_requisitos'] && olimp_cfg($ev)['aprobacion'] === 'manual'): ?><p class="ayuda">Puede guardar ahora y subir los documentos después: la organización aprueba a cada deportista cuando tiene completo lo obligatorio (*).</p><?php endif; ?>
    <?php return (string)ob_get_clean();
}

/** Lee del formulario los datos del deportista. */
function olimp_leer_deportista_post(): array
{
    return ['nombres' => post('nombres'), 'apellidos' => post('apellidos'), 'cedula' => post('cedula'), 'sexo' => post('sexo'), 'fecha_nac' => post('fecha_nac'),
        'telefono' => post('telefono'), 'email' => post('email'), 'cargo' => post('cargo')];
}

/** Campos para inscribir a un deportista en una disciplina (elige disciplina y, según el deporte, categoría o pruebas). */
function olimp_html_campos_inscripcion(array $discs, array $dep, string $sufijo = ''): string
{
    $id = 'oi' . (int)$dep['id'] . $sufijo;
    $ya = [];
    foreach (olimp_inscripciones_dep((int)$dep['id']) as $i) $ya[(int)$i['disciplina_id']][] = $i;
    ob_start(); ?>
    <div class="campos" id="<?= e($id) ?>">
      <div><label>Disciplina</label><select name="disciplina_id" required onchange="olimpDisc('<?= e($id) ?>', this.value)"><option value="">— elija —</option>
        <?php foreach ($discs as $d):
            $t = olimp_torneo($d);
            if ($d['rama'] === 'M' && $dep['sexo'] !== 'M' || $d['rama'] === 'F' && $dep['sexo'] !== 'F') continue;
            $tipo = olimp_tipo($t);
            if (isset($ya[(int)$d['id']]) && in_array($tipo, ['equipo', 'carrera'], true)) continue; ?>
          <option value="<?= (int)$d['id'] ?>"><?= olimp_icono($d) ?> <?= e(olimp_nombre_disciplina($d)) ?><?= $d['fecha'] !== '' ? ' · ' . e(fecha_bonita($d['fecha'])) . ($d['hora_ini'] !== '' ? ' ' . e($d['hora_ini']) : '') : '' ?></option>
        <?php endforeach; ?></select></div>
      <?php foreach ($discs as $d): $t = olimp_torneo($d); $tipo = olimp_tipo($t); $ops = array_filter(olimp_opciones_disciplina($d), fn($o) => !in_array($o['rama'], ['M', 'F'], true) || $o['rama'] === $dep['sexo']); if ($tipo === 'equipo') continue; ?>
        <div class="olimp-op" data-disc="<?= (int)$d['id'] ?>" style="display:none;grid-column:1/-1">
          <?php if ($tipo === 'pruebas'): ?>
            <label>Pruebas de <?= e(olimp_nombre_disciplina($d)) ?></label>
            <?php if (!$ops): ?><p class="suave">Todavía no hay pruebas en el programa.</p><?php endif; ?>
            <?php foreach ($ops as $o): ?><label class="check" style="font-weight:400;display:inline-flex;margin-right:12px"><input type="checkbox" name="eventos[]" value="<?= (int)$o['id'] ?>"> <?= e($o['nombre']) ?></label><?php endforeach; ?>
          <?php elseif ($ops): ?>
            <label>Categoría</label><select name="categoria_<?= (int)$d['id'] ?>"><?= $tipo === 'duelo' ? '<option value="0">Automática (por edad y sexo)</option>' : '' ?><?php foreach ($ops as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['nombre']) ?></option><?php endforeach; ?></select>
            <?php if ($tipo === 'duelo'): ?><label style="margin-top:6px">Pareja (solo dobles)</label><input type="text" name="pareja_<?= (int)$d['id'] ?>" maxlength="80" placeholder="Nombre de su compañero o compañera"><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <script>function olimpDisc(c, v) { document.querySelectorAll('#' + c + ' .olimp-op').forEach(function (x) { x.style.display = x.getAttribute('data-disc') === v ? '' : 'none'; x.querySelectorAll('input,select').forEach(function (i) { i.disabled = x.getAttribute('data-disc') !== v; }); }); }</script>
    <?php return (string)ob_get_clean();
}

/** Lee del formulario de inscripción las opciones de la disciplina elegida. */
function olimp_leer_inscripcion_post(): array
{
    $did = (int)post('disciplina_id');
    return ['categoria' => (int)post('categoria_' . $did), 'eventos' => array_map('intval', (array)($_POST['eventos'] ?? [])), 'pareja' => post('pareja_' . $did)];
}

/** (v37) Aviso en la página pública de la liga: eventos en curso (portada) o «parte de …» (campeonato que es disciplina). */
function olimp_html_aviso_publico(array $liga, ?array $t, string $base, bool $portada): string
{
    $h = '';
    $ev_t = $t ? olimp_evento_de_torneo((int)$t['id']) : null;
    if ($ev_t && olimp_cfg($ev_t)['publico']) {
        return '<div class="aviso aviso-info no-imprimir">🏅 Este campeonato es parte de <b>' . e($ev_t['nombre']) . '</b>. <a href="' . e($base . '&o=' . (int)$ev_t['id']) . '">Ver el medallero general →</a></div>';
    }
    if (!$portada) return '';
    foreach (filas("SELECT * FROM olimp_eventos WHERE liga_id = ? AND (estado <> 'finalizado' OR fecha_fin >= ?) ORDER BY fecha_ini LIMIT 3", [$liga['id'], date('Y-m-d', strtotime('-30 days'))]) as $ev) {
        if (!olimp_cfg($ev)['publico']) continue;
        $h .= '<a class="tarjeta partido-link olimp-cab no-imprimir" style="padding:10px 14px;margin:8px 0;text-decoration:none;color:inherit" href="' . e($base . '&o=' . (int)$ev['id']) . '">' . olimp_html_logo($ev)
            . '<span style="flex:1"><b>🏅 ' . e($ev['nombre']) . '</b><br><small class="suave">' . e(olimp_estados()[$ev['estado']] ?? '') . ($ev['fecha_ini'] !== '' ? ' · ' . e(fecha_bonita($ev['fecha_ini'])) . ($ev['fecha_fin'] !== '' && $ev['fecha_fin'] !== $ev['fecha_ini'] ? ' al ' . e(fecha_bonita($ev['fecha_fin'])) : '') : '') . '</small></span><span class="btn btn-sec btn-chico">Medallero →</span></a>';
    }
    return $h !== '' ? olimp_css() . $h : '';
}
