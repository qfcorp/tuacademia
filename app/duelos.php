<?php
/*
 * (v24) CAMPEONATOS DE DUELOS: deportes individuales (o de parejas) uno contra uno.
 *
 *   Raqueta (por sets / games): Tenis, Pádel, Tenis de mesa, Squash (v36) → marcador "6-4 3-6 7-6(5)" o "11-8 9-11 11-6"
 *   Artes marciales (por puntos): karate, taekwondo, judo                   → puntos de cada uno + método (Puntos, Ippon…)
 *   Boxeo (por rounds): decisión, KO, TKO…                                  → método + round en que terminó + tarjetas
 *   (v36) Ajedrez: 1-0, ½-½, 0-1 (sistema suizo, Buchholz)
 *   (v36) E-sports (videojuegos 1 vs 1: eFootball, FIFA…): goles, empate en todos contra todos, penales en la llave
 *
 * FORMATOS de cada CATEGORÍA (duelo_categorias.formato):
 *   llave        eliminación directa con byes, cabezas de serie y (opcional) partido por el 3.er puesto
 *   liga         todos contra todos
 *   grupos       (v36) grupos de 3 a 5 (todos contra todos) y luego llave con los primeros de cada grupo (reglas FCHS / WSF)
 *   consolacion  (v36) llave principal + llave de consolación (plate) para los que pierden su primer partido
 *   monrad       (v36) llave con todos los puestos: todos juegan todas las rondas (ganadores y perdedores siguen en sub-llaves)
 *   suizo        (v36) sistema suizo por rondas: cada ronda se empareja a los de igual puntaje sin repetir rival (ajedrez)
 *   escalera     (v36) escalera de desafíos (ranking del club): se desafía hasta N puestos arriba; si gana, sube al puesto del rival
 *
 * Tablas: duelo_categorias, duelo_participantes, duelo_combates (vea esquema_v22 y esquema_v32 en db.php).
 * duelo_combates.bloque: 0 = llave principal; en grupos = número de grupo (la llave final es el bloque 0);
 * en consolación 1 = llave de consolación; en Monrad = sub-llave (puestos). En la llave, el ganador del combate (ronda r, pos p)
 * pasa al combate (r+1, ⌈p/2⌉): al lado A si p es impar y al lado B si p es par (duelo_fuentes() dice de dónde viene cada lado).
 */

function modalidades_duelo(): array
{
    return [
        'TENIS' => ['Tenis', '🎾', 'sets'],
        'PADEL' => ['Pádel', '🎾', 'sets'],
        'TMESA' => ['Tenis de mesa', '🏓', 'sets'],
        'SQUASH' => ['Squash', '🎾', 'sets'], // (v36)
        'MARC' => ['Artes marciales (karate, taekwondo, judo)', '🥋', 'puntos'],
        'BOXEO' => ['Boxeo', '🥊', 'rounds'],
        'AJED' => ['Ajedrez', '♟️', 'ajedrez'], // (v36)
        'ESPOR' => ['E-sports (videojuegos)', '🎮', 'goles'], // (v36)
    ];
}

function es_duelo($t): bool
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return isset(modalidades_duelo()[$m]);
}

/** 'sets' | 'puntos' | 'rounds' | 'ajedrez' | 'goles' */
function tipo_duelo($t): string
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return modalidades_duelo()[$m][2] ?? 'sets';
}

function icono_duelo($t): string
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return modalidades_duelo()[$m][1] ?? '🏅';
}

function textos_duelo($t): array
{
    $m = is_array($t) ? (string)($t['modalidad'] ?? '') : (string)$t;
    return match ($m) {
        'PADEL' => ['participante' => 'Pareja', 'participantes' => 'Parejas', 'combate' => 'Partido', 'combates' => 'Partidos', 'lugar' => 'Cancha', 'dobles' => true],
        'TMESA' => ['participante' => 'Jugador', 'participantes' => 'Jugadores', 'combate' => 'Partido', 'combates' => 'Partidos', 'lugar' => 'Mesa', 'dobles' => true],
        'TENIS', 'SQUASH' => ['participante' => 'Jugador', 'participantes' => 'Jugadores', 'combate' => 'Partido', 'combates' => 'Partidos', 'lugar' => 'Cancha', 'dobles' => true],
        'BOXEO' => ['participante' => 'Boxeador', 'participantes' => 'Boxeadores', 'combate' => 'Pelea', 'combates' => 'Peleas', 'lugar' => 'Ring', 'dobles' => false],
        'AJED' => ['participante' => 'Jugador', 'participantes' => 'Jugadores', 'combate' => 'Partida', 'combates' => 'Partidas', 'lugar' => 'Tablero', 'dobles' => false],
        'ESPOR' => ['participante' => 'Jugador', 'participantes' => 'Jugadores', 'combate' => 'Partido', 'combates' => 'Partidos', 'lugar' => 'Consola', 'dobles' => true],
        default => ['participante' => 'Competidor', 'participantes' => 'Competidores', 'combate' => 'Combate', 'combates' => 'Combates', 'lugar' => 'Tatami / área', 'dobles' => false],
    };
}

/** (v36) Reglas con que nace un campeonato de cada deporte (el administrador las cambia en «Reglas y configuración»). */
function duelo_reglas_fabrica(string $m): array
{
    return match ($m) {
        // World Squash 2025: games a 11 con diferencia de 2, mejor de 5
        'SQUASH' => ['duelo_sets' => 5, 'duelo_bronce' => 'no', 'duelo_game_pts' => 11, 'duelo_game_dif' => 2],
        // ITTF: games a 11 con diferencia de 2, mejor de 5
        'TMESA' => ['duelo_sets' => 5, 'duelo_bronce' => 'no', 'duelo_game_pts' => 11, 'duelo_game_dif' => 2],
        'MARC', 'BOXEO' => ['duelo_sets' => 3, 'duelo_bronce' => 'doble'],
        // FIDE: 1 punto por ganar, ½ por tablas
        'AJED' => ['duelo_sets' => 1, 'duelo_bronce' => 'no', 'duelo_pts_gana' => 1, 'duelo_pts_empate' => 0, 'duelo_pts_pierde' => 0],
        'ESPOR' => ['duelo_sets' => 1, 'duelo_bronce' => 'partido', 'duelo_pts_gana' => 3, 'duelo_pts_empate' => 1, 'duelo_pts_pierde' => 0],
        default => ['duelo_sets' => 3, 'duelo_bronce' => 'no'],
    } + ['duelo_rounds' => 3];
}

/** Reglas del campeonato de duelos. */
function duelo_cfg(array $t): array
{
    $tipo = tipo_duelo($t);
    $gp = (int)($t['duelo_game_pts'] ?? 0);
    return [
        'tipo' => $tipo,
        'sets' => in_array((int)($t['duelo_sets'] ?? 3), [1, 3, 5, 7], true) ? (int)$t['duelo_sets'] : 3,
        'rounds' => max(1, min(15, (int)($t['duelo_rounds'] ?? 3))),
        'pts_gana' => (int)($t['duelo_pts_gana'] ?? 2),
        'pts_empate' => (int)($t['duelo_pts_empate'] ?? 1),
        'pts_pierde' => (int)($t['duelo_pts_pierde'] ?? 0),
        'bronce' => in_array($t['duelo_bronce'] ?? '', ['doble', 'partido', 'no'], true) ? $t['duelo_bronce'] : ($tipo === 'sets' ? 'no' : 'doble'),
        'empate' => $tipo !== 'sets', // en todos contra todos, combate, boxeo, ajedrez y e-sports pueden empatar; la raqueta no
        // (v36) puntos de cada game (0 = libre, como en tenis y pádel) y regla del final: 2 = diferencia de dos, 1 = punto de oro
        'game_pts' => in_array($gp, [0, 7, 9, 11, 15, 21], true) ? $gp : 0,
        'game_dif' => (int)($t['duelo_game_dif'] ?? 2) === 1 ? 1 : 2,
        // (v36) ranking del circuito
        'factor' => max(0, min(200, (int)($t['duelo_factor'] ?? 100))),
        'rank_pts' => duelo_rank_pts_leer((string)($t['duelo_rank_pts'] ?? '')),
    ];
}

/** Métodos de victoria según el deporte (el primero es «por el marcador»). */
function duelo_metodos(array $t): array
{
    return match (tipo_duelo($t)) {
        'sets' => ['Sets', 'Retiro', 'W.O.'],
        'puntos' => ['Puntos', 'Ippon', 'Superioridad', 'Decisión de jueces', 'Descalificación', 'Abandono', 'W.O.'],
        'rounds' => ['Decisión unánime', 'Decisión dividida', 'Decisión mayoritaria', 'KO', 'TKO / RSC', 'Descalificación', 'Abandono', 'W.O.'],
        'ajedrez' => ['Partida', 'Abandono', 'Tiempo', 'Desempate (armagedón)', 'W.O.'],
        'goles' => ['Marcador', 'Penales', 'Gol de oro', 'Desconexión', 'W.O.'],
    };
}

function duelo_formatos(): array
{
    return [
        'llave' => 'Llave (eliminación directa)',
        'liga' => 'Todos contra todos',
        'grupos' => 'Grupos y luego llave',
        'consolacion' => 'Llave con consolación',
        'monrad' => 'Llave con todos los puestos (Monrad)',
        'suizo' => 'Sistema suizo (por rondas)',
        'escalera' => 'Escalera de desafíos (ranking del club)',
    ];
}

/** (v36) Explicación corta de cada formato. */
function duelo_formato_ayuda(string $f): string
{
    return [
        'llave' => 'El que pierde queda fuera. Cabezas de serie separados y pases directos repartidos.',
        'liga' => 'Todos juegan contra todos; gana la tabla.',
        'grupos' => 'Grupos de 3 a 5 (todos contra todos) y los primeros de cada grupo pasan a una llave final. Es el formato de los nacionales de squash.',
        'consolacion' => 'Llave principal y una llave de consolación (plate) para los que pierden su primer partido: todos juegan al menos dos.',
        'monrad' => 'Nadie queda fuera: ganadores y perdedores siguen jugando en sub-llaves hasta definir todos los puestos (1.º al último).',
        'suizo' => 'Por rondas: en cada ronda se empareja a los de igual puntaje sin repetir rival. Ideal para ajedrez y para muchos inscritos.',
        'escalera' => 'Ranking permanente del club: se desafía a alguien hasta N puestos arriba; si gana, toma su puesto.',
    ][$f] ?? '';
}

function duelo_ramas(): array
{
    return ['' => 'Cualquiera', 'M' => 'Varones', 'F' => 'Damas', 'X' => 'Mixto'];
}

/** Categorías de fábrica: [nombre, formato, rama, peso_min, peso_max, edad_min, edad_max]. */
function duelo_categorias_base(string $m): array
{
    return match ($m) {
        'BOXEO' => [['Varones −57 kg', 'llave', 'M', 0, 57, 0, 0], ['Varones −63,5 kg', 'llave', 'M', 57, 63.5, 0, 0], ['Varones −71 kg', 'llave', 'M', 63.5, 71, 0, 0],
            ['Varones −80 kg', 'llave', 'M', 71, 80, 0, 0], ['Varones +80 kg', 'llave', 'M', 80, 0, 0, 0], ['Damas −57 kg', 'llave', 'F', 0, 57, 0, 0], ['Damas +57 kg', 'llave', 'F', 57, 0, 0, 0]],
        'MARC' => [['Infantil (8 a 11 años)', 'llave', '', 0, 0, 8, 11], ['Juvenil (12 a 15 años)', 'llave', '', 0, 0, 12, 15], ['Adultos varones −67 kg', 'llave', 'M', 0, 67, 16, 0],
            ['Adultos varones −75 kg', 'llave', 'M', 67, 75, 16, 0], ['Adultos varones +75 kg', 'llave', 'M', 75, 0, 16, 0], ['Adultos damas', 'llave', 'F', 0, 0, 16, 0]],
        'PADEL' => [['Parejas masculino', 'llave', 'M', 0, 0, 0, 0], ['Parejas femenino', 'llave', 'F', 0, 0, 0, 0], ['Parejas mixto', 'llave', 'X', 0, 0, 0, 0]],
        // (v36) squash: juveniles Sub 11 a Sub 19 (edad cumplida), categorías por nivel (1.ª a 4.ª, Damas A y B), máster y dobles
        'SQUASH' => [['Sub 11', 'grupos', '', 0, 0, 0, 10], ['Sub 13', 'grupos', '', 0, 0, 0, 12], ['Sub 15', 'grupos', '', 0, 0, 0, 14], ['Sub 17', 'grupos', '', 0, 0, 0, 16],
            ['Sub 19', 'grupos', '', 0, 0, 0, 18], ['Varones 1.ª (abierta)', 'grupos', 'M', 0, 0, 0, 0], ['Varones 2.ª', 'grupos', 'M', 0, 0, 0, 0], ['Varones 3.ª', 'grupos', 'M', 0, 0, 0, 0],
            ['Varones 4.ª', 'grupos', 'M', 0, 0, 0, 0], ['Damas A', 'grupos', 'F', 0, 0, 0, 0], ['Damas B', 'grupos', 'F', 0, 0, 0, 0], ['Máster +35', 'grupos', '', 0, 0, 35, 0],
            ['Máster +45', 'grupos', '', 0, 0, 45, 0], ['Dobles', 'llave', 'X', 0, 0, 15, 0]],
        // (v36) ajedrez: sistema suizo por categorías de edad (FIDE juveniles) y absoluto
        'AJED' => [['Sub 10', 'suizo', '', 0, 0, 0, 9], ['Sub 12', 'suizo', '', 0, 0, 0, 11], ['Sub 14', 'suizo', '', 0, 0, 0, 13], ['Sub 16', 'suizo', '', 0, 0, 0, 15],
            ['Sub 18', 'suizo', '', 0, 0, 0, 17], ['Absoluto', 'suizo', '', 0, 0, 0, 0], ['Femenino', 'suizo', 'F', 0, 0, 0, 0]],
        'ESPOR' => [['Individual (1 vs 1)', 'grupos', '', 0, 0, 0, 0], ['Parejas (2 vs 2)', 'llave', '', 0, 0, 0, 0]],
        default => [['Singles varones', 'llave', 'M', 0, 0, 0, 0], ['Singles damas', 'llave', 'F', 0, 0, 0, 0], ['Juvenil (hasta 17 años)', 'liga', '', 0, 0, 0, 17]],
    };
}

function duelo_cargar_categorias_base(array $t): int
{
    $n = 0;
    $ya = array_map('mb_strtolower', array_column(filas('SELECT nombre FROM duelo_categorias WHERE torneo_id = ?', [$t['id']]), 'nombre'));
    $orden = (int)valor('SELECT COALESCE(MAX(orden), 0) FROM duelo_categorias WHERE torneo_id = ?', [$t['id']]);
    foreach (duelo_categorias_base((string)$t['modalidad']) as [$nom, $fmt, $rama, $pmin, $pmax, $emin, $emax]) {
        if (in_array(mb_strtolower($nom), $ya, true)) continue;
        q('INSERT INTO duelo_categorias (torneo_id, nombre, orden, formato, rama, peso_min, peso_max, edad_min, edad_max) VALUES (?,?,?,?,?,?,?,?,?)',
            [$t['id'], $nom, ++$orden, $fmt, $rama, $pmin, $pmax, $emin, $emax]);
        $n++;
    }
    return $n;
}

function duelo_categorias(int $tid): array
{
    return filas('SELECT * FROM duelo_categorias WHERE torneo_id = ? ORDER BY orden, id', [$tid]);
}

/** "Varones · 63,5 a 71 kg · 16 años o más" */
function duelo_texto_categoria(array $c): string
{
    $p = [];
    if (($c['rama'] ?? '') !== '') $p[] = duelo_ramas()[$c['rama']] ?? '';
    $kg = fn($x) => rtrim(rtrim(number_format((float)$x, 1, ',', '.'), '0'), ',');
    if ((float)$c['peso_min'] > 0 && (float)$c['peso_max'] > 0) $p[] = 'más de ' . $kg($c['peso_min']) . ' hasta ' . $kg($c['peso_max']) . ' kg';
    elseif ((float)$c['peso_max'] > 0) $p[] = 'hasta ' . $kg($c['peso_max']) . ' kg';
    elseif ((float)$c['peso_min'] > 0) $p[] = 'más de ' . $kg($c['peso_min']) . ' kg';
    if ((int)$c['edad_min'] > 0 && (int)$c['edad_max'] > 0) $p[] = (int)$c['edad_min'] . ' a ' . (int)$c['edad_max'] . ' años';
    elseif ((int)$c['edad_max'] > 0) $p[] = 'hasta ' . (int)$c['edad_max'] . ' años';
    elseif ((int)$c['edad_min'] > 0) $p[] = (int)$c['edad_min'] . ' años o más';
    return implode(' · ', array_filter($p));
}

/** Edad cumplida a una fecha (AAAA-MM-DD). */
function duelo_edad(string $nac, string $a = ''): ?int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nac)) return null;
    $a = $a !== '' ? $a : date('Y-m-d');
    $e = (int)substr($a, 0, 4) - (int)substr($nac, 0, 4);
    if (substr($a, 5) < substr($nac, 5)) $e--;
    return $e;
}

/** Categoría que le toca a un participante por rama, peso y edad (la primera que calce). */
function duelo_categoria_auto(int $tid, string $rama, ?float $peso, string $nac): ?int
{
    $edad = duelo_edad($nac);
    foreach (duelo_categorias($tid) as $c) {
        if ($c['rama'] !== '' && $c['rama'] !== 'X' && $rama !== '' && $c['rama'] !== $rama) continue;
        if ($c['rama'] !== '' && $c['rama'] !== 'X' && $rama === '') continue;
        if ((float)$c['peso_max'] > 0 || (float)$c['peso_min'] > 0) {
            if (!$peso) continue;
            if ((float)$c['peso_min'] > 0 && $peso <= (float)$c['peso_min']) continue;
            if ((float)$c['peso_max'] > 0 && $peso > (float)$c['peso_max']) continue;
        }
        if ((int)$c['edad_min'] > 0 || (int)$c['edad_max'] > 0) {
            if ($edad === null) continue;
            if ((int)$c['edad_min'] > 0 && $edad < (int)$c['edad_min']) continue;
            if ((int)$c['edad_max'] > 0 && $edad > (int)$c['edad_max']) continue;
        }
        return (int)$c['id'];
    }
    return null;
}

/** Nombre a mostrar: "Ana Pérez / Luis Vera" en dobles. */
function duelo_nombre(?array $p): string
{
    if (!$p) return '';
    return $p['nombre'] . (($p['pareja'] ?? '') !== '' ? ' / ' . $p['pareja'] : '');
}

function duelo_participantes(int $tid, ?int $cid = null): array
{
    $r = [];
    $sql = 'SELECT * FROM duelo_participantes WHERE torneo_id = ?' . ($cid !== null ? ' AND categoria_id = ?' : '') . ' ORDER BY nombre';
    foreach (filas($sql, $cid !== null ? [$tid, $cid] : [$tid]) as $p) $r[(int)$p['id']] = $p;
    return $r;
}

/* ============================================================
 * MARCADOR POR SETS / GAMES
 * ============================================================ */

/**
 * "6-4 3-6 7-6(5)" → ['sets' => [[6,4,null],[3,6,null],[7,6,5]], 'sa' => 1, 'sb' => 2, 'ga' => 16, 'gb' => 16, 'texto' => '6-4 3-6 7-6(5)'].
 * Lanza error si está mal escrito.
 */
function duelo_parse_sets(string $s): array
{
    $s = trim(preg_replace('/\s+/', ' ', str_replace([',', ';', '/'], ' ', $s)));
    if ($s === '') return ['sets' => [], 'sa' => 0, 'sb' => 0, 'ga' => 0, 'gb' => 0, 'texto' => ''];
    $sets = [];
    $sa = $sb = $ga = $gb = 0;
    foreach (explode(' ', $s) as $x) {
        if (!preg_match('/^(\d{1,2})[-:](\d{1,2})(?:\((\d{1,2})\))?$/', $x, $m)) throw new RuntimeException("Marcador mal escrito: «{$x}». Escriba cada set como 6-4, separados por espacios (ej: 6-4 3-6 7-6).");
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a === $b) throw new RuntimeException("Un set no puede terminar empatado: «{$x}».");
        $sets[] = [$a, $b, isset($m[3]) ? (int)$m[3] : null];
        $a > $b ? $sa++ : $sb++;
        $ga += $a;
        $gb += $b;
    }
    if (count($sets) > 7) throw new RuntimeException('Demasiados sets en el marcador.');
    $txt = implode(' ', array_map(fn($x) => $x[0] . '-' . $x[1] . ($x[2] !== null ? '(' . $x[2] . ')' : ''), $sets));
    return ['sets' => $sets, 'sa' => $sa, 'sb' => $sb, 'ga' => $ga, 'gb' => $gb, 'texto' => $txt];
}

/**
 * (v36) Revisa que cada game termine bien según la regla (squash y tenis de mesa a 11 con diferencia de 2; dobles de squash
 * con punto de oro en 10-10). Con «Retiro» o «W.O.» el último game puede quedar a medias.
 */
function duelo_validar_games(array $cfg, array $ps, bool $ultimo_libre = false): void
{
    $P = $cfg['game_pts'];
    if ($P <= 0) return;
    $n = count($ps['sets']);
    foreach ($ps['sets'] as $i => [$a, $b]) {
        if ($ultimo_libre && $i === $n - 1) continue;
        $w = max($a, $b);
        $l = min($a, $b);
        $txt = $a . '-' . $b;
        if ($cfg['game_dif'] === 1) {
            if ($w !== $P) throw new RuntimeException("El game «{$txt}» no es válido: se juega a {$P} puntos (con punto de oro en " . ($P - 1) . '-' . ($P - 1) . ').');
            continue;
        }
        if ($w < $P) throw new RuntimeException("El game «{$txt}» no está terminado: se juega a {$P} puntos.");
        if ($w === $P && $l > $P - 2) throw new RuntimeException("El game «{$txt}» no es válido: desde " . ($P - 1) . '-' . ($P - 1) . ' se gana con 2 puntos de diferencia (ej: ' . ($P + 1) . '-' . ($P - 1) . ').');
        if ($w > $P && $w - $l !== 2) throw new RuntimeException("El game «{$txt}» no es válido: pasado {$P}, el game termina apenas alguien saca 2 puntos de diferencia (ej: " . ($w) . '-' . ($w - 2) . ').');
    }
}

/** Marcador visto desde el lado B ("6-4 3-6" → "4-6 6-3"). */
function duelo_invertir_sets(string $txt): string
{
    if (trim($txt) === '') return '';
    try {
        $p = duelo_parse_sets($txt);
    } catch (Throwable) {
        return $txt;
    }
    return implode(' ', array_map(fn($x) => $x[1] . '-' . $x[0] . ($x[2] !== null ? '(' . $x[2] . ')' : ''), $p['sets']));
}

/** (v36) Ejemplo de marcador para el formulario. */
function duelo_ejemplo_marcador(array $t): string
{
    $cfg = duelo_cfg($t);
    if ($cfg['game_pts'] > 0) {
        $P = $cfg['game_pts'];
        return 'Ej: ' . $P . '-8 ' . ($P - 2) . '-' . $P . ' ' . ($P + 1) . '-' . ($P - 1) . ' ' . $P . '-4';
    }
    return 'Ej: 6-4 3-6 7-6(5)';
}

/* ============================================================
 * LLAVES: estructura común (llave, consolación, Monrad, llave final de grupos)
 * ============================================================ */

function duelo_nombre_ronda(int $total, int $r): string
{
    return match ($total - $r) {
        0 => 'Final',
        1 => 'Semifinal',
        2 => 'Cuartos de final',
        3 => 'Octavos de final',
        4 => 'Dieciseisavos',
        default => 'Ronda ' . $r,
    };
}

/** Orden de los cabezas de serie en una llave de $s lugares: [1, 8, 4, 5, 2, 7, 3, 6] para 8. */
function duelo_orden_siembra(int $s): array
{
    $o = [1];
    while (count($o) < $s) {
        $n = count($o) * 2 + 1;
        $nuevo = [];
        foreach ($o as $x) { $nuevo[] = $x; $nuevo[] = $n - $x; }
        $o = $nuevo;
    }
    return $o;
}

function duelo_combates(int $cid): array
{
    return filas('SELECT * FROM duelo_combates WHERE categoria_id = ? ORDER BY bloque, ronda, tercer, pos, id', [$cid]);
}

/** (v36) Combates de un bloque (0 = llave principal). */
function duelo_combates_bloque(int $cid, int $bloque): array
{
    return filas('SELECT * FROM duelo_combates WHERE categoria_id = ? AND bloque = ? ORDER BY ronda, tercer, pos, id', [$cid, $bloque]);
}

/** ¿La categoría ya tiene resultados cargados (sin contar los pases directos)? */
function duelo_categoria_con_resultados(int $cid): bool
{
    return (int)valor("SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ? AND estado = 'jugado'", [$cid]) > 0;
}

/** (v36) ¿El formato usa una llave (con byes y rondas siguientes)? */
function duelo_es_llave(string $f): bool
{
    return in_array($f, ['llave', 'consolacion', 'monrad', 'grupos'], true);
}

/** Arma (o rehace) el sorteo de una categoría según su formato. Devuelve cuántos combates creó. */
function duelo_sortear(array $t, array $cat, bool $forzar = false): int
{
    if (duelo_categoria_con_resultados((int)$cat['id']) && !$forzar) throw new RuntimeException('Esta categoría ya tiene resultados. Para volver a sortear, primero borre los resultados.');
    $part = array_values(array_filter(duelo_participantes((int)$t['id'], (int)$cat['id']), fn($p) => (int)$p['activo'] === 1));
    if (count($part) < 2) throw new RuntimeException('La categoría «' . $cat['nombre'] . '» necesita al menos 2 inscritos para sortear.');
    q('DELETE FROM duelo_combates WHERE categoria_id = ?', [$cat['id']]);
    return match ($cat['formato']) {
        'liga' => duelo_crear_liga($t, $cat, $part),
        'grupos' => duelo_crear_grupos($t, $cat, $part),
        'consolacion' => duelo_crear_llave($t, $cat, $part, 'consolacion'),
        'monrad' => duelo_crear_llave($t, $cat, $part, 'monrad'),
        'suizo' => duelo_suizo_emparejar($t, $cat),
        'escalera' => duelo_escalera_armar($cat, $part),
        default => duelo_crear_llave($t, $cat, $part),
    };
}

/** Ubica a los participantes en una llave de $s lugares (cabezas de serie separados; los del mismo club no se cruzan al inicio). */
function duelo_ubicar(array $part, int $s, ?array $lista = null, string $clave_grupo = 'club'): array
{
    if ($lista === null) {
        $sem = array_values(array_filter($part, fn($p) => (int)$p['siembra'] > 0));
        usort($sem, fn($a, $b) => (int)$a['siembra'] <=> (int)$b['siembra']);
        $resto = array_values(array_filter($part, fn($p) => (int)$p['siembra'] <= 0));
        shuffle($resto);
        $lista = array_merge($sem, $resto);
        $libre = fn($p) => $p && (int)$p['siembra'] <= 0;
    } else {
        // llave final de grupos: se intercambia solo entre los del mismo nivel (2.º con 2.º…)
        $libre = fn($p) => $p && (int)($p['_nivel'] ?? 0) > 1;
    }
    $orden = duelo_orden_siembra($s);
    $slot = [];
    foreach ($orden as $i => $seed) $slot[$i] = $lista[$seed - 1] ?? null;
    $g = fn($p) => mb_strtolower(trim((string)($p[$clave_grupo] ?? '')));
    for ($vuelta = 0; $vuelta < 3; $vuelta++) {
        for ($i = 0; $i < $s; $i += 2) {
            $a = $slot[$i];
            $b = $slot[$i + 1];
            if (!$a || !$b || $g($a) === '' || $g($a) !== $g($b) || !$libre($b)) continue;
            for ($j = 0; $j < $s; $j += 2) {
                if ($j === $i) continue;
                foreach ([$j, $j + 1] as $k) {
                    $x = $slot[$k];
                    if (!$libre($x) || $g($x) === $g($a)) continue;
                    if ($lista !== null && isset($b['_nivel']) && (int)($x['_nivel'] ?? 0) !== (int)$b['_nivel']) continue;
                    $otro = $slot[$k === $j ? $j + 1 : $j];
                    if ($otro && $g($otro) !== '' && $g($otro) === $g($b)) continue;
                    [$slot[$i + 1], $slot[$k]] = [$slot[$k], $slot[$i + 1]];
                    continue 3;
                }
            }
        }
    }
    return $slot;
}

/**
 * Llave de eliminación ($tipo 'llave'), con consolación ('consolacion') o con todos los puestos ('monrad').
 * $lista: participantes ya ordenados por siembra (llave final de grupos). $bloque: bloque de la llave principal.
 */
function duelo_crear_llave(array $t, array $cat, array $part, string $tipo = 'llave', ?array $lista = null): int
{
    $n = count($part);
    $s = 2;
    while ($s < $n) $s *= 2;
    $rondas = (int)log($s, 2);
    $slot = duelo_ubicar($part, $s, $lista, $lista !== null ? '_grupo' : 'club');
    $ins = 'INSERT INTO duelo_combates (torneo_id, categoria_id, bloque, ronda, pos, tercer, a_id, b_id) VALUES (?,?,?,?,?,?,?,?)';
    $n_c = 0;
    for ($r = 1; $r <= $rondas; $r++) {
        $cant = $s >> $r;
        $bloques = $tipo === 'monrad' ? 1 << ($r - 1) : 1;
        for ($bl = 0; $bl < $bloques; $bl++) {
            for ($p = 1; $p <= $cant; $p++) {
                $a = $r === 1 ? ($slot[($p - 1) * 2]['id'] ?? null) : null;
                $b = $r === 1 ? ($slot[($p - 1) * 2 + 1]['id'] ?? null) : null;
                q($ins, [$t['id'], $cat['id'], $bl, $r, $p, 0, $a, $b]);
                $n_c++;
            }
        }
        // consolación: desde la 2.ª ronda, una llave paralela con los que perdieron su primer partido
        if ($tipo === 'consolacion' && $r >= 2) {
            for ($p = 1; $p <= $cant; $p++) { q($ins, [$t['id'], $cat['id'], 1, $r, $p, 0, null, null]); $n_c++; }
        }
    }
    if ($tipo !== 'monrad' && duelo_cfg($t)['bronce'] === 'partido' && $rondas >= 2) {
        q($ins, [$t['id'], $cat['id'], 0, $rondas, 1, 1, null, null]);
        $n_c++;
    }
    duelo_resolver_llave($t, (int)$cat['id']);
    return $n_c;
}

/** Todos contra todos (método del círculo). $bloque: número de grupo (0 = sin grupos). */
function duelo_crear_liga(array $t, array $cat, array $part, int $bloque = 0): int
{
    shuffle($part);
    $ids = array_map(fn($p) => (int)$p['id'], $part);
    if (count($ids) % 2) $ids[] = 0; // descansa
    $n = count($ids);
    $c = 0;
    for ($r = 1; $r < $n; $r++) {
        $pos = 0;
        for ($i = 0; $i < $n / 2; $i++) {
            $a = $ids[$i];
            $b = $ids[$n - 1 - $i];
            if ($a && $b) {
                q('INSERT INTO duelo_combates (torneo_id, categoria_id, bloque, ronda, pos, a_id, b_id) VALUES (?,?,?,?,?,?,?)', [$t['id'], $cat['id'], $bloque, $r, ++$pos, $r % 2 ? $a : $b, $r % 2 ? $b : $a]);
                $c++;
            }
        }
        $ids = array_merge([$ids[0]], [$ids[$n - 1]], array_slice($ids, 1, $n - 2));
    }
    return $c;
}

/**
 * (v36) De dónde sale cada lado de un combate: [[ronda, bloque, pos, 'g'|'p'], [...]] (g = ganador, p = perdedor),
 * o null si los lados se guardan tal cual (primera ronda, todos contra todos, suizo, escalera, grupos).
 */
function duelo_fuentes(string $formato, array $c): ?array
{
    $r = (int)$c['ronda'];
    $b = (int)$c['bloque'];
    $p = (int)$c['pos'];
    if ((int)$c['tercer'] || $r <= 1) return null;
    switch ($formato) {
        case 'llave':
        case 'grupos':
            if ($b !== 0) return null;
            return [[$r - 1, 0, 2 * $p - 1, 'g'], [$r - 1, 0, 2 * $p, 'g']];
        case 'consolacion':
            if ($b === 0) return [[$r - 1, 0, 2 * $p - 1, 'g'], [$r - 1, 0, 2 * $p, 'g']];
            if ($r === 2) return [[1, 0, 2 * $p - 1, 'p'], [1, 0, 2 * $p, 'p']];
            return [[$r - 1, 1, 2 * $p - 1, 'g'], [$r - 1, 1, 2 * $p, 'g']];
        case 'monrad':
            $k = $b & 1 ? 'p' : 'g';
            return [[$r - 1, $b >> 1, 2 * $p - 1, $k], [$r - 1, $b >> 1, 2 * $p, $k]];
    }
    return null;
}

/** Perdedor de un combate terminado (null si fue pase directo o quedó vacío). */
function duelo_perdedor(array $c): ?int
{
    if ($c['estado'] !== 'jugado' || $c['ganador_id'] === null || !$c['a_id'] || !$c['b_id']) return null;
    return (int)$c['ganador_id'] === (int)$c['a_id'] ? (int)$c['b_id'] : (int)$c['a_id'];
}

/**
 * Recorre la llave y la deja coherente: pone a cada ganador (o perdedor, en consolación y Monrad) en su lugar de la ronda
 * siguiente, resuelve los pases directos (bye) y llena el partido por el tercer puesto. Se llama después de cada resultado.
 */
function duelo_resolver_llave(array $t, int $cid): void
{
    $cat = fila('SELECT formato FROM duelo_categorias WHERE id = ?', [$cid]);
    $fmt = (string)($cat['formato'] ?? 'llave');
    if (!duelo_es_llave($fmt)) return;
    $cs = duelo_combates($cid);
    if (!$cs) return;
    $por = [];
    $tercer = null;
    $rondas = 0;
    foreach ($cs as $c) {
        if ($fmt === 'grupos' && (int)$c['bloque'] > 0) continue; // partidos de grupo
        if ((int)$c['tercer']) { $tercer = $c; continue; }
        $por[(int)$c['ronda']][(int)$c['bloque']][(int)$c['pos']] = $c;
        if ((int)$c['bloque'] === 0) $rondas = max($rondas, (int)$c['ronda']);
    }
    if (!$por) return;
    $id_o_null = fn($x) => $x !== null ? (int)$x : null;
    ksort($por);
    foreach ($por as $r => $bloques) {
        ksort($bloques);
        foreach ($bloques as $bl => $lista) {
            foreach ($lista as $p => $c) {
                $a = $id_o_null($c['a_id']);
                $b = $id_o_null($c['b_id']);
                $fu = duelo_fuentes($fmt, $c);
                if ($fu) {
                    $val = [];
                    $fin = [];
                    foreach ($fu as $i => [$fr, $fb, $fp, $k]) {
                        $f = $por[$fr][$fb][$fp] ?? null;
                        $fin[$i] = $f && in_array($f['estado'], ['jugado', 'bye', 'vacio'], true);
                        $val[$i] = !$f ? null : ($k === 'g' ? $id_o_null($f['ganador_id']) : duelo_perdedor($f));
                        if (!$fin[$i]) $val[$i] = null;
                    }
                    [$a, $b] = $val;
                    [$fin1, $fin2] = $fin;
                } else {
                    $fin1 = $fin2 = true;
                }
                $estado = $c['estado'];
                $ganador = $id_o_null($c['ganador_id']);
                if ($estado !== 'jugado') {
                    if ($fin1 && $fin2 && $a && !$b) { $estado = 'bye'; $ganador = $a; }
                    elseif ($fin1 && $fin2 && $b && !$a) { $estado = 'bye'; $ganador = $b; }
                    elseif ($fin1 && $fin2 && !$a && !$b) { $estado = 'vacio'; $ganador = null; }
                    else { $estado = 'pendiente'; $ganador = null; }
                }
                if ($a !== $id_o_null($c['a_id']) || $b !== $id_o_null($c['b_id']) || $estado !== $c['estado'] || $ganador !== $id_o_null($c['ganador_id'])) {
                    q('UPDATE duelo_combates SET a_id = ?, b_id = ?, estado = ?, ganador_id = ?, metodo = CASE WHEN ? = \'bye\' THEN \'Pase directo\' WHEN ? IN (\'pendiente\',\'vacio\') THEN \'\' ELSE metodo END WHERE id = ?',
                        [$a, $b, $estado, $ganador, $estado, $estado, $c['id']]);
                    $c = array_merge($c, ['a_id' => $a, 'b_id' => $b, 'estado' => $estado, 'ganador_id' => $ganador]);
                    $por[$r][$bl][$p] = $c;
                }
            }
        }
    }
    if ($tercer && $rondas >= 2) {
        $perd = [];
        foreach ([1, 2] as $p) {
            $sf = $por[$rondas - 1][0][$p] ?? null;
            $perd[] = $sf ? duelo_perdedor($sf) : null;
        }
        [$a, $b] = $perd;
        if ($tercer['estado'] !== 'jugado' && ($a !== $id_o_null($tercer['a_id']) || $b !== $id_o_null($tercer['b_id']))) {
            q("UPDATE duelo_combates SET a_id = ?, b_id = ?, estado = 'pendiente', ganador_id = NULL WHERE id = ?", [$a, $b, $tercer['id']]);
        }
    }
}

/** Los combates que dependen de este (ronda siguiente, consolación, tercer puesto o llave final) ya tienen resultado. */
function duelo_siguiente_jugado(array $c): bool
{
    if ((int)$c['tercer']) return false;
    $cat = fila('SELECT formato FROM duelo_categorias WHERE id = ?', [$c['categoria_id']]);
    $fmt = (string)($cat['formato'] ?? 'llave');
    if ($fmt === 'grupos' && (int)$c['bloque'] > 0) {
        return (int)valor("SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ? AND bloque = 0 AND estado = 'jugado'", [$c['categoria_id']]) > 0;
    }
    if (!duelo_es_llave($fmt)) return false;
    foreach (filas("SELECT * FROM duelo_combates WHERE categoria_id = ? AND ronda = ? AND estado = 'jugado'", [$c['categoria_id'], (int)$c['ronda'] + 1]) as $x) {
        foreach (duelo_fuentes($fmt, $x) ?? [] as [$fr, $fb, $fp]) if ($fr === (int)$c['ronda'] && $fb === (int)$c['bloque'] && $fp === (int)$c['pos']) return true;
    }
    if ((int)$c['bloque'] !== 0) return false;
    $ter = fila("SELECT * FROM duelo_combates WHERE categoria_id = ? AND tercer = 1 AND estado = 'jugado'", [$c['categoria_id']]);
    $max = (int)valor('SELECT MAX(ronda) FROM duelo_combates WHERE categoria_id = ? AND bloque = 0', [$c['categoria_id']]);
    return $ter && (int)$c['ronda'] === $max - 1;
}

/* ============================================================
 * (v36) GRUPOS Y LUEGO LLAVE
 * ============================================================ */

/** Letra del grupo: 1 → A. */
function duelo_letra_grupo(int $g): string
{
    return $g >= 1 && $g <= 26 ? chr(64 + $g) : (string)$g;
}

/** Reparte en grupos (de grupo_tam, los que sobran se suman a los primeros) en serpentina por siembra y arma el todos contra todos de cada grupo. */
function duelo_crear_grupos(array $t, array $cat, array $part): int
{
    $n = count($part);
    $tam = max(2, min(8, (int)($cat['grupo_tam'] ?? 3)));
    $G = max(1, min(intdiv($n, 2), intdiv($n, $tam)));
    $sem = array_values(array_filter($part, fn($p) => (int)$p['siembra'] > 0));
    usort($sem, fn($a, $b) => (int)$a['siembra'] <=> (int)$b['siembra']);
    $resto = array_values(array_filter($part, fn($p) => (int)$p['siembra'] <= 0));
    shuffle($resto);
    $grupos = array_fill(0, $G, []);
    foreach (array_merge($sem, $resto) as $i => $p) {
        $fila_s = intdiv($i, $G);
        $col = $i % $G;
        $grupos[$fila_s % 2 ? $G - 1 - $col : $col][] = $p;
    }
    $c = 0;
    foreach ($grupos as $g => $lista) $c += duelo_crear_liga($t, $cat, $lista, $g + 1);
    return $c;
}

/** Números de los grupos sorteados de una categoría. */
function duelo_grupos_de(int $cid): array
{
    return array_map('intval', array_column(filas('SELECT DISTINCT bloque FROM duelo_combates WHERE categoria_id = ? AND bloque > 0 ORDER BY bloque', [$cid]), 'bloque'));
}

/** ¿Se jugaron todos los partidos de grupo? */
function duelo_grupos_terminados(int $cid): bool
{
    return duelo_grupos_de($cid) && (int)valor("SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ? AND bloque > 0 AND estado <> 'jugado'", [$cid]) === 0;
}

/**
 * Arma la llave final con los primeros (grupo_pasan) de cada grupo: los 1.º como cabezas de serie y los 2.º, 3.º… después;
 * en la primera ronda no se cruzan dos del mismo grupo. Se llama sola cuando termina el último partido de grupo.
 */
function duelo_grupos_armar_llave(array $t, array $cat): int
{
    $cid = (int)$cat['id'];
    if (!duelo_grupos_terminados($cid)) throw new RuntimeException('Todavía faltan partidos de grupo.');
    if (duelo_combates_bloque($cid, 0)) {
        if ((int)valor("SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ? AND bloque = 0 AND estado = 'jugado'", [$cid])) throw new RuntimeException('La llave final ya tiene resultados.');
        q('DELETE FROM duelo_combates WHERE categoria_id = ? AND bloque = 0', [$cid]);
    }
    $pasan = max(1, min(8, (int)($cat['grupo_pasan'] ?? 2)));
    $niveles = [];
    foreach (duelo_grupos_de($cid) as $g) {
        foreach (array_slice(duelo_tabla_liga($t, $cid, $g), 0, $pasan) as $i => $x) {
            $niveles[$i][] = $x['p'] + ['_nivel' => $i + 1, '_grupo' => 'g' . $g];
        }
    }
    $lista = array_merge(...array_values($niveles ?: [[]]));
    if (count($lista) < 2) return 0; // un solo grupo con 1 clasificado: el campeón es el 1.º del grupo
    return duelo_crear_llave($t, $cat, $lista, 'llave', $lista);
}

/* ============================================================
 * (v36) SISTEMA SUIZO
 * ============================================================ */

/** Rondas del suizo: las de la categoría o, si es 0, ⌈log2(n)⌉ + 1 (mínimo 3). */
function duelo_suizo_rondas(array $cat, int $n): int
{
    $r = (int)($cat['rondas'] ?? 0);
    if ($r > 0) return $r;
    return max(3, (int)ceil(log(max(2, $n), 2)) + 1);
}

/** Puntos de un participante en un combate jugado (ajedrez: 1 / ½ / 0; otros: los de la configuración). */
function duelo_pts_de(array $t, array $c, int $pid): float
{
    $cfg = duelo_cfg($t);
    if ($c['estado'] === 'bye' && $c['a_id'] !== null && (int)$c['a_id'] === $pid) return $cfg['tipo'] === 'ajedrez' ? 1.0 : (float)$cfg['pts_gana'];
    if ($c['estado'] !== 'jugado') return 0.0;
    if ($cfg['tipo'] === 'ajedrez') {
        if ($c['ganador_id'] === null) return 0.5;
        return (int)$c['ganador_id'] === $pid ? 1.0 : 0.0;
    }
    if ($c['ganador_id'] === null) return (float)$cfg['pts_empate'];
    return (float)((int)$c['ganador_id'] === $pid ? $cfg['pts_gana'] : $cfg['pts_pierde']);
}

/**
 * Empareja la ronda siguiente del suizo: ordena por puntos (y por ELO / siembra), busca rival del mismo puntaje que no haya
 * enfrentado (mitad de arriba contra mitad de abajo), da el descanso (bye) al último sin bye y equilibra los colores (lado A = blancas).
 */
function duelo_suizo_emparejar(array $t, array $cat): int
{
    $cid = (int)$cat['id'];
    $cs = duelo_combates($cid);
    $r_act = $cs ? max(array_map(fn($c) => (int)$c['ronda'], $cs)) : 0;
    foreach ($cs as $c) if ((int)$c['ronda'] === $r_act && $c['estado'] === 'pendiente') throw new RuntimeException('Primero cargue todos los resultados de la ronda ' . $r_act . '.');
    $part = array_values(array_filter(duelo_participantes((int)$t['id'], $cid), fn($p) => (int)$p['activo'] === 1));
    $n = count($part);
    if ($n < 2) throw new RuntimeException('Se necesitan al menos 2 inscritos.');
    $total = duelo_suizo_rondas($cat, $n);
    if ($r_act >= $total) throw new RuntimeException('Ya se jugaron las ' . $total . ' rondas del suizo.');
    $pts = $rivales = $color = $bye = [];
    foreach ($part as $p) { $id = (int)$p['id']; $pts[$id] = 0.0; $rivales[$id] = []; $color[$id] = 0; $bye[$id] = false; }
    foreach ($cs as $c) {
        $a = $c['a_id'] !== null ? (int)$c['a_id'] : 0;
        $b = $c['b_id'] !== null ? (int)$c['b_id'] : 0;
        if ($a && isset($pts[$a])) { $pts[$a] += duelo_pts_de($t, $c, $a); if (!$b) $bye[$a] = true; else $color[$a]++; }
        if ($b && isset($pts[$b])) { $pts[$b] += duelo_pts_de($t, $c, $b); $color[$b]--; }
        if ($a && $b) { $rivales[$a][$b] = true; $rivales[$b][$a] = true; }
    }
    $elo = fn($p) => (int)preg_replace('/\D/', '', (string)$p['grado']);
    usort($part, fn($x, $y) => [$pts[(int)$y['id']], $elo($y), (int)$x['siembra'] ?: 999, $x['nombre']] <=> [$pts[(int)$x['id']], $elo($x), (int)$y['siembra'] ?: 999, $y['nombre']]);
    if ($r_act === 0 && !array_filter($part, fn($p) => $elo($p) > 0 || (int)$p['siembra'] > 0)) shuffle($part);
    $ids = array_map(fn($p) => (int)$p['id'], $part);
    $sin_pareja = null;
    if (count($ids) % 2) {
        for ($i = count($ids) - 1; $i >= 0; $i--) if (!$bye[$ids[$i]]) { $sin_pareja = $ids[$i]; break; }
        $sin_pareja ??= $ids[count($ids) - 1];
        $ids = array_values(array_diff($ids, [$sin_pareja]));
    }
    // candidatos de cada uno: primero los de su mismo puntaje desde la mitad (1 vs n/2+1), luego el resto en orden
    $intentos = 0;
    $emparejar = function (array $lista, bool $sin_repetir) use (&$emparejar, &$intentos, $pts, $rivales): ?array {
        if (!$lista) return [];
        if (++$intentos > 20000) return null;
        $p = array_shift($lista);
        $mismo = array_values(array_filter($lista, fn($q) => $pts[$q] == $pts[$p]));
        $mitad = (int)floor((count($mismo) + 1) / 2) - 1; // con 1 vs 2 del mismo grupo de 4 se toma el 3.º del grupo (1 vs 3)
        $orden = array_merge(array_slice($mismo, max(0, $mitad)), array_slice($mismo, 0, max(0, $mitad)), array_values(array_diff($lista, $mismo)));
        foreach ($orden as $q) {
            if ($sin_repetir && isset($rivales[$p][$q])) continue;
            $resto = $emparejar(array_values(array_diff($lista, [$q])), $sin_repetir);
            if ($resto !== null) return array_merge([[$p, $q]], $resto);
        }
        return null;
    };
    $pares = $emparejar($ids, true);
    if ($pares === null) { $intentos = 0; $pares = $emparejar($ids, false); }
    if ($pares === null) throw new RuntimeException('No se pudo emparejar la ronda.');
    $r = $r_act + 1;
    $pos = 0;
    foreach ($pares as [$p, $q]) {
        // blancas (lado A) para el que menos veces las tuvo; si están iguales, alterna por ronda
        $blanca = $color[$p] < $color[$q] ? $p : ($color[$q] < $color[$p] ? $q : ($r % 2 ? $p : $q));
        $negra = $blanca === $p ? $q : $p;
        q('INSERT INTO duelo_combates (torneo_id, categoria_id, bloque, ronda, pos, a_id, b_id) VALUES (?,?,?,?,?,?,?)', [$t['id'], $cid, 0, $r, ++$pos, $blanca, $negra]);
    }
    if ($sin_pareja) q("INSERT INTO duelo_combates (torneo_id, categoria_id, bloque, ronda, pos, a_id, b_id, estado, ganador_id, metodo) VALUES (?,?,?,?,?,?,NULL,'bye',?,'Descansa (bye)')", [$t['id'], $cid, 0, $r, ++$pos, $sin_pareja, $sin_pareja]);
    return $pos;
}

/* ============================================================
 * (v36) ESCALERA DE DESAFÍOS
 * ============================================================ */

/** Arma la escalera: cabezas de serie arriba y el resto al azar. El puesto se guarda en duelo_participantes.siembra. */
function duelo_escalera_armar(array $cat, array $part): int
{
    $sem = array_values(array_filter($part, fn($p) => (int)$p['siembra'] > 0));
    usort($sem, fn($a, $b) => (int)$a['siembra'] <=> (int)$b['siembra']);
    $resto = array_values(array_filter($part, fn($p) => (int)$p['siembra'] <= 0));
    shuffle($resto);
    foreach (array_merge($sem, $resto) as $i => $p) q('UPDATE duelo_participantes SET siembra = ? WHERE id = ?', [$i + 1, $p['id']]);
    return 0;
}

/** Escalera ordenada (puestos 1..n sin huecos; los nuevos entran al final, los retirados salen). */
function duelo_escalera(int $tid, int $cid): array
{
    $todos = duelo_participantes($tid, $cid);
    $act = array_values(array_filter($todos, fn($p) => (int)$p['activo'] === 1));
    usort($act, fn($a, $b) => [(int)$a['siembra'] ?: 9999, (int)$a['id']] <=> [(int)$b['siembra'] ?: 9999, (int)$b['id']]);
    foreach ($act as $i => &$p) {
        if ((int)$p['siembra'] !== $i + 1) { q('UPDATE duelo_participantes SET siembra = ? WHERE id = ?', [$i + 1, $p['id']]); $p['siembra'] = $i + 1; }
    }
    unset($p);
    foreach ($todos as $p) if (!(int)$p['activo'] && (int)$p['siembra']) q('UPDATE duelo_participantes SET siembra = 0 WHERE id = ?', [$p['id']]);
    return $act;
}

/** Crea un desafío: el retador (lado A) está más abajo y puede desafiar hasta escalera_salto puestos arriba. */
function duelo_escalera_desafio(array $t, array $cat, int $retador, int $defensor, string $fecha = '', string $hora = ''): int
{
    $cid = (int)$cat['id'];
    $esc = duelo_escalera((int)$t['id'], $cid);
    $pos = [];
    foreach ($esc as $p) $pos[(int)$p['id']] = (int)$p['siembra'];
    if (!isset($pos[$retador], $pos[$defensor])) throw new RuntimeException('Los dos tienen que estar activos en esta escalera.');
    if ($retador === $defensor) throw new RuntimeException('Elija dos jugadores distintos.');
    $salto = max(1, (int)($cat['escalera_salto'] ?? 3));
    if ($pos[$retador] <= $pos[$defensor]) throw new RuntimeException('El retador tiene que estar más abajo que el desafiado.');
    if ($pos[$retador] - $pos[$defensor] > $salto) throw new RuntimeException('Solo se puede desafiar hasta ' . $salto . ' puesto' . ($salto === 1 ? '' : 's') . ' más arriba.');
    if (fila("SELECT id FROM duelo_combates WHERE categoria_id = ? AND estado = 'pendiente' AND (a_id IN (?, ?) OR b_id IN (?, ?))", [$cid, $retador, $defensor, $retador, $defensor])) {
        throw new RuntimeException('Uno de los dos ya tiene un desafío pendiente.');
    }
    $r = (int)valor('SELECT COALESCE(MAX(ronda), 0) + 1 FROM duelo_combates WHERE categoria_id = ?', [$cid]);
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : '';
    $hora = preg_match('/^\d{2}:\d{2}$/', $hora) ? $hora : '';
    q('INSERT INTO duelo_combates (torneo_id, categoria_id, bloque, ronda, pos, a_id, b_id, fecha, hora) VALUES (?,?,0,?,1,?,?,?,?)', [$t['id'], $cid, $r, $retador, $defensor, $fecha, $hora]);
    return (int)db()->lastInsertId();
}

/** Aplica un desafío jugado: si gana el retador, toma el puesto del desafiado y los de en medio bajan uno. Guarda cómo estaba para poder deshacerlo. */
function duelo_escalera_aplicar(array $t, array $c): void
{
    $cid = (int)$c['categoria_id'];
    $esc = duelo_escalera((int)$t['id'], $cid);
    $antes = [];
    foreach ($esc as $p) $antes[(int)$p['id']] = (int)$p['siembra'];
    q('UPDATE duelo_combates SET antes = ? WHERE id = ?', [json_encode($antes), $c['id']]);
    $ret = (int)$c['a_id'];
    $def = (int)$c['b_id'];
    if ((int)$c['ganador_id'] !== $ret || !isset($antes[$ret], $antes[$def]) || $antes[$ret] <= $antes[$def]) return;
    $pd = $antes[$def];
    $pr = $antes[$ret];
    foreach ($antes as $id => $ps) if ($ps >= $pd && $ps < $pr) q('UPDATE duelo_participantes SET siembra = ? WHERE id = ?', [$ps + 1, $id]);
    q('UPDATE duelo_participantes SET siembra = ? WHERE id = ?', [$pd, $ret]);
}

/** Deshace el último desafío jugado (vuelve a los puestos de antes). */
function duelo_escalera_deshacer(array $c): void
{
    $ult = (int)valor("SELECT id FROM duelo_combates WHERE categoria_id = ? AND estado = 'jugado' ORDER BY ronda DESC, id DESC LIMIT 1", [$c['categoria_id']]);
    if ($ult !== (int)$c['id']) throw new RuntimeException('En la escalera solo se puede borrar el último desafío jugado (los puestos cambiaron después).');
    foreach (json_decode((string)$c['antes'], true) ?: [] as $id => $ps) q('UPDATE duelo_participantes SET siembra = ? WHERE id = ? AND categoria_id = ?', [(int)$ps, (int)$id, $c['categoria_id']]);
}

/* ============================================================
 * RESULTADOS
 * ============================================================ */

/**
 * Guarda el resultado de un combate. $d = ganador ('a'|'b'|'empate'|''), metodo, marcador, pts_a, pts_b, round_fin, nota.
 * Si no viene ni ganador ni marcador ni puntos, solo guarda la programación (fecha, hora, lugar).
 */
function duelo_guardar_resultado(array $t, array $c, array $d): string
{
    $cfg = duelo_cfg($t);
    $cat = fila('SELECT * FROM duelo_categorias WHERE id = ?', [$c['categoria_id']]);
    $fmt = (string)$cat['formato'];
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['fecha'] ?? '')) ? $d['fecha'] : '';
    $hora = preg_match('/^\d{2}:\d{2}$/', (string)($d['hora'] ?? '')) ? $d['hora'] : '';
    $lugar = mb_substr(trim((string)($d['lugar'] ?? '')), 0, 40);
    q('UPDATE duelo_combates SET fecha = ?, hora = ?, lugar = ? WHERE id = ?', [$fecha, $hora, $lugar, $c['id']]);
    $gan = (string)($d['ganador'] ?? '');
    $marc = trim((string)($d['marcador'] ?? ''));
    $pa = trim((string)($d['pts_a'] ?? ''));
    $pb = trim((string)($d['pts_b'] ?? ''));
    $metodo = (string)($d['metodo'] ?? '');
    $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 120);
    if ($gan === '' && $marc === '' && $pa === '' && $pb === '') return 'programado';
    if (!$c['a_id'] || !$c['b_id']) throw new RuntimeException('Este ' . mb_strtolower(textos_duelo($t)['combate']) . ' todavía no tiene a sus dos rivales.');
    if ($fmt === 'escalera' && $c['estado'] === 'jugado') throw new RuntimeException('Este desafío ya tiene resultado. Para corregirlo, borre el resultado y cárguelo de nuevo.');
    // con llave final de grupos ya jugada, no se cambian los partidos de grupo
    if ($fmt === 'grupos' && (int)$c['bloque'] > 0 && duelo_siguiente_jugado($c)) throw new RuntimeException('La llave final ya tiene resultados: no se pueden cambiar los partidos de grupo. Borre primero los resultados de la llave final.');
    $metodos = duelo_metodos($t);
    if (!in_array($metodo, $metodos, true) && $gan !== 'empate') $metodo = $metodos[0];
    $hay_empate = in_array($fmt, ['liga', 'grupos', 'suizo'], true) && !($fmt === 'grupos' && (int)$c['bloque'] === 0) && $cfg['empate'];
    $sa = $sb = null;
    if ($cfg['tipo'] === 'sets') {
        $ps = duelo_parse_sets($marc);
        $marc = $ps['texto'];
        if ($metodo === 'Sets') {
            $necesita = intdiv($cfg['sets'], 2) + 1;
            if (max($ps['sa'], $ps['sb']) !== $necesita || min($ps['sa'], $ps['sb']) >= $necesita) throw new RuntimeException("Con «mejor de {$cfg['sets']}» el ganador debe llevarse $necesita " . ($cfg['game_pts'] > 0 ? 'games' : 'sets') . ". Revise el marcador «{$marc}» o elija «Retiro» / «W.O.».");
            duelo_validar_games($cfg, $ps);
            $gan_calc = $ps['sa'] > $ps['sb'] ? 'a' : 'b';
            if ($gan !== '' && $gan !== $gan_calc) throw new RuntimeException('El ganador que eligió no coincide con el marcador (escriba siempre primero los games del lado A).');
            $gan = $gan_calc;
        } else {
            if ($marc !== '' && $metodo === 'Retiro') duelo_validar_games($cfg, $ps, true);
            if ($gan === '' || $gan === 'empate') throw new RuntimeException('Elija quién ganó (por ' . mb_strtolower($metodo) . ').');
        }
        $sa = $ps['sa'];
        $sb = $ps['sb'];
    } elseif ($cfg['tipo'] === 'puntos') {
        $sa = $pa === '' ? null : (float)str_replace(',', '.', $pa);
        $sb = $pb === '' ? null : (float)str_replace(',', '.', $pb);
        if ($sa !== null && ($sa < 0 || $sa > 999) || $sb !== null && ($sb < 0 || $sb > 999)) throw new RuntimeException('Los puntos van de 0 a 999.');
        if ($gan === '' && $metodo === 'Puntos') {
            if ($sa === null || $sb === null) throw new RuntimeException('Escriba los puntos de los dos.');
            if ($sa == $sb) throw new RuntimeException('Están empatados en puntos: elija el ganador y el método (por ejemplo «Decisión de jueces»)' . ($hay_empate ? ' o marque «Empate»' : '') . '.');
            $gan = $sa > $sb ? 'a' : 'b';
        }
    } elseif ($cfg['tipo'] === 'goles') {
        // (v36) e-sports: goles de cada uno; empate en todos contra todos; en la llave se define por penales o gol de oro
        $sa = $pa === '' ? null : max(0, min(99, (int)$pa));
        $sb = $pb === '' ? null : max(0, min(99, (int)$pb));
        if (in_array($metodo, ['Marcador', 'Gol de oro'], true)) {
            if ($sa === null || $sb === null) throw new RuntimeException('Escriba los goles de los dos.');
            if ($sa === $sb) {
                if ($metodo === 'Gol de oro') throw new RuntimeException('Con gol de oro no puede quedar empatado.');
                if (!$hay_empate) throw new RuntimeException('Quedaron empatados: elija «Penales» y quién ganó.');
                $gan = 'empate';
            } else {
                $calc = $sa > $sb ? 'a' : 'b';
                if ($gan !== '' && $gan !== $calc) throw new RuntimeException('El ganador que eligió no coincide con los goles.');
                $gan = $calc;
            }
        } elseif ($metodo === 'Penales') {
            if ($sa === null || $sb === null || $sa !== $sb) throw new RuntimeException('Los penales solo van cuando el partido terminó empatado: escriba los goles (iguales) y en «Penales» el resultado de la tanda (ej: 4-3).');
            if ($marc !== '' && !preg_match('/^(\d{1,2})\s*[-:]\s*(\d{1,2})$/', $marc, $mm)) throw new RuntimeException('Escriba la tanda de penales como 4-3.');
            if ($marc !== '') {
                if ((int)$mm[1] === (int)$mm[2]) throw new RuntimeException('La tanda de penales no puede quedar empatada.');
                $calc = (int)$mm[1] > (int)$mm[2] ? 'a' : 'b';
                if ($gan !== '' && $gan !== $calc) throw new RuntimeException('El ganador no coincide con la tanda de penales.');
                $gan = $calc;
                $marc = (int)$mm[1] . '-' . (int)$mm[2];
            }
            if ($gan === '' || $gan === 'empate') throw new RuntimeException('Elija quién ganó los penales.');
        } elseif ($gan === '' || $gan === 'empate') {
            throw new RuntimeException('Elija quién ganó (por ' . mb_strtolower($metodo) . ').');
        }
        if ($metodo !== 'Penales') $marc = '';
    } elseif ($cfg['tipo'] === 'ajedrez') {
        // (v36) ajedrez: 1-0, ½-½ (tablas), 0-1
        if ($gan === 'empate' && $metodo !== 'Partida') $metodo = 'Partida';
        if ($gan === '') throw new RuntimeException('Elija el resultado: ganan blancas, ganan negras o tablas.');
        [$sa, $sb] = $gan === 'a' ? [1.0, 0.0] : ($gan === 'b' ? [0.0, 1.0] : [0.5, 0.5]);
        $marc = '';
    }
    if ($gan === 'empate') {
        if (!$hay_empate) throw new RuntimeException($fmt === 'escalera' ? 'En un desafío siempre tiene que haber un ganador.' : 'En una llave siempre tiene que haber un ganador' . ($cfg['tipo'] === 'ajedrez' ? ' (si hubo tablas, cargue el desempate).' : '.'));
        $metodo = $cfg['tipo'] === 'ajedrez' ? 'Tablas' : 'Empate';
    }
    if (!in_array($gan, ['a', 'b', 'empate'], true)) throw new RuntimeException('Elija quién ganó.');
    if (duelo_es_llave($fmt) && !($fmt === 'grupos' && (int)$c['bloque'] > 0) && duelo_siguiente_jugado($c)) {
        $antes = $c['ganador_id'] !== null ? (int)$c['ganador_id'] : null;
        $ahora = $gan === 'a' ? (int)$c['a_id'] : (int)$c['b_id'];
        if ($antes !== $ahora) throw new RuntimeException('No se puede cambiar el ganador: la ronda siguiente ya se jugó. Borre primero ese resultado.');
    }
    if ($fmt === 'suizo') {
        $max = (int)valor('SELECT MAX(ronda) FROM duelo_combates WHERE categoria_id = ?', [$c['categoria_id']]);
        if ((int)$c['ronda'] < $max && $c['estado'] === 'jugado') {
            $nuevo = $gan === 'a' ? (int)$c['a_id'] : ($gan === 'b' ? (int)$c['b_id'] : null);
            if ($nuevo !== ($c['ganador_id'] !== null ? (int)$c['ganador_id'] : null)) throw new RuntimeException('Ya se emparejó la ronda siguiente con este resultado. Borre esa ronda antes de cambiarlo.');
        }
    }
    $ganador = $gan === 'a' ? $c['a_id'] : ($gan === 'b' ? $c['b_id'] : null);
    $round = $cfg['tipo'] === 'rounds' && (int)($d['round_fin'] ?? 0) > 0 ? min($cfg['rounds'], (int)$d['round_fin']) : 0;
    q("UPDATE duelo_combates SET estado = 'jugado', ganador_id = ?, metodo = ?, marcador = ?, pts_a = ?, pts_b = ?, round_fin = ?, nota = ? WHERE id = ?",
        [$ganador, $metodo, mb_substr($marc, 0, 80), $sa, $sb, $round, $nota, $c['id']]);
    $c = fila('SELECT * FROM duelo_combates WHERE id = ?', [$c['id']]);
    if ($fmt === 'escalera') duelo_escalera_aplicar($t, $c);
    if ($fmt === 'grupos' && (int)$c['bloque'] > 0) {
        // si cambió un partido de grupo con la llave final ya armada (sin resultados), se rearma
        if (duelo_grupos_terminados((int)$cat['id'])) duelo_grupos_armar_llave($t, $cat);
    } elseif (duelo_es_llave($fmt)) {
        duelo_resolver_llave($t, (int)$cat['id']);
    }
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$t['id']]);
    return 'jugado';
}

function duelo_borrar_resultado(array $t, array $c): void
{
    $cat = fila('SELECT * FROM duelo_categorias WHERE id = ?', [$c['categoria_id']]);
    $fmt = (string)$cat['formato'];
    if (duelo_siguiente_jugado($c)) throw new RuntimeException($fmt === 'grupos' && (int)$c['bloque'] > 0 ? 'La llave final ya tiene resultados: bórrelos primero.' : 'Primero borre el resultado de la ronda siguiente.');
    if ($fmt === 'suizo' && (int)$c['ronda'] < (int)valor('SELECT MAX(ronda) FROM duelo_combates WHERE categoria_id = ?', [$c['categoria_id']])) {
        throw new RuntimeException('Ya se emparejó la ronda siguiente. Borre primero esa ronda (botón «Borrar la última ronda»).');
    }
    if ($fmt === 'escalera') duelo_escalera_deshacer($c);
    q("UPDATE duelo_combates SET estado = 'pendiente', ganador_id = NULL, metodo = '', marcador = '', pts_a = NULL, pts_b = NULL, round_fin = 0, antes = '' WHERE id = ?", [$c['id']]);
    if ($fmt === 'grupos' && (int)$c['bloque'] > 0) q('DELETE FROM duelo_combates WHERE categoria_id = ? AND bloque = 0', [$cat['id']]); // la llave final se vuelve a armar al terminar los grupos
    elseif (duelo_es_llave($fmt)) duelo_resolver_llave($t, (int)$cat['id']);
}

/** (v36) Suizo: borra la última ronda (si no tiene resultados, o con confirmación). */
function duelo_suizo_borrar_ronda(array $cat): int
{
    $max = (int)valor('SELECT COALESCE(MAX(ronda), 0) FROM duelo_combates WHERE categoria_id = ?', [$cat['id']]);
    if (!$max) return 0;
    q('DELETE FROM duelo_combates WHERE categoria_id = ? AND ronda = ?', [$cat['id'], $max]);
    return $max;
}

/** Texto corto del resultado: "6-4 7-5", "8-3 (Puntos)", "KO R2", "1-0", "2-2 (4-3 pen.)". Visto desde el lado A. */
function duelo_texto_resultado(array $t, array $c): string
{
    if ($c['estado'] === 'bye') return $c['b_id'] === null && $c['metodo'] === 'Descansa (bye)' ? 'Descansa' : 'Pase directo';
    if ($c['estado'] !== 'jugado') return '';
    $tipo = tipo_duelo($t);
    $m = $c['metodo'];
    if ($tipo === 'sets') return trim($c['marcador'] . ($m !== 'Sets' ? ' ' . ($m === 'Retiro' ? '(ret.)' : '(' . $m . ')') : ''));
    if ($tipo === 'ajedrez') {
        $r = $c['ganador_id'] === null ? '½-½' : ((int)$c['ganador_id'] === (int)$c['a_id'] ? '1-0' : '0-1');
        return $r . (!in_array($m, ['Partida', 'Tablas'], true) ? ' (' . mb_strtolower($m) . ')' : '');
    }
    if ($tipo === 'goles') {
        $g = $c['pts_a'] !== null && $c['pts_b'] !== null ? (int)$c['pts_a'] . '-' . (int)$c['pts_b'] : '';
        if ($m === 'Penales') return trim($g . ' (' . ($c['marcador'] !== '' ? $c['marcador'] . ' ' : '') . 'pen.)');
        return trim($g . (in_array($m, ['Marcador', 'Empate'], true) ? '' : ' (' . mb_strtolower($m) . ')'));
    }
    if ($tipo === 'puntos') {
        $p = $c['pts_a'] !== null && $c['pts_b'] !== null ? rtrim(rtrim(number_format((float)$c['pts_a'], 1, ',', ''), '0'), ',') . '-' . rtrim(rtrim(number_format((float)$c['pts_b'], 1, ',', ''), '0'), ',') : '';
        return trim($p . ($m !== 'Puntos' ? ' ' . $m : ''));
    }
    return trim($m . ((int)$c['round_fin'] > 0 ? ' R' . (int)$c['round_fin'] : '') . ($c['marcador'] !== '' ? ' · ' . $c['marcador'] : ''));
}

/** (v36) Nombre de la instancia de un combate: "Grupo B · Fecha 2", "Semifinal", "Consolación · Final", "Puestos 5 a 8", "Ronda 3", "Desafío 7". */
function duelo_instancia(array $cat, array $c, array $combs): string
{
    $fmt = (string)$cat['formato'];
    $r = (int)$c['ronda'];
    $b = (int)$c['bloque'];
    if ((int)$c['tercer']) return 'Tercer puesto';
    $max0 = 0;
    foreach ($combs as $x) if ((int)$x['bloque'] === 0 && !(int)$x['tercer']) $max0 = max($max0, (int)$x['ronda']);
    return match ($fmt) {
        'liga' => 'Fecha ' . $r,
        'suizo' => 'Ronda ' . $r,
        'escalera' => 'Desafío ' . $r,
        'grupos' => $b > 0 ? 'Grupo ' . duelo_letra_grupo($b) . ' · Fecha ' . $r : 'Llave final · ' . duelo_nombre_ronda($max0, $r),
        'consolacion' => ($b === 1 ? 'Consolación · ' : '') . duelo_nombre_ronda($max0, $r),
        'monrad' => (function () use ($b, $r, $max0) {
            $tam = (1 << $max0) >> ($r - 1);
            return 'Ronda ' . $r . ' · puestos ' . ($b * $tam + 1) . ' a ' . (($b + 1) * $tam);
        })(),
        default => duelo_nombre_ronda($max0, $r),
    };
}

/* ============================================================
 * TABLA (todos contra todos, grupos, suizo) Y MEDALLERO
 * ============================================================ */

/** Tabla de posiciones. $bloque: un grupo (solo sus jugadores y partidos). */
function duelo_tabla_liga(array $t, int $cid, ?int $bloque = null): array
{
    $cfg = duelo_cfg($t);
    $cat = fila('SELECT formato FROM duelo_categorias WHERE id = ?', [$cid]);
    $suizo = ($cat['formato'] ?? '') === 'suizo';
    $combs = $bloque !== null ? duelo_combates_bloque($cid, $bloque) : duelo_combates($cid);
    $en_bloque = null;
    if ($bloque !== null) {
        $en_bloque = [];
        foreach ($combs as $c) { if ($c['a_id']) $en_bloque[(int)$c['a_id']] = true; if ($c['b_id']) $en_bloque[(int)$c['b_id']] = true; }
    }
    $tab = [];
    foreach (duelo_participantes((int)$t['id'], $cid) as $id => $p) {
        if (!(int)$p['activo'] && !isset($en_bloque[$id])) continue;
        if ($en_bloque !== null && !isset($en_bloque[$id])) continue;
        $tab[$id] = ['p' => $p, 'pj' => 0, 'g' => 0, 'e' => 0, 'pe' => 0, 'pts' => 0, 'af' => 0.0, 'ec' => 0.0, 'gf' => 0, 'gc' => 0, 'bh' => 0.0, 'rivales' => []];
    }
    $directo = [];
    foreach ($combs as $c) {
        // descanso del suizo: suma los puntos de una victoria
        if ($c['estado'] === 'bye' && $c['b_id'] === null && $c['a_id'] && isset($tab[(int)$c['a_id']]) && $suizo) {
            $tab[(int)$c['a_id']]['pts'] += duelo_pts_de($t, $c, (int)$c['a_id']);
            continue;
        }
        if ($c['estado'] !== 'jugado' || !$c['a_id'] || !$c['b_id']) continue;
        $a = (int)$c['a_id'];
        $b = (int)$c['b_id'];
        if (!isset($tab[$a], $tab[$b])) continue;
        foreach ([[$a, $b, 'a'], [$b, $a, 'b']] as [$yo, $el, $lado]) {
            $x = &$tab[$yo];
            $x['pj']++;
            $x['rivales'][] = $el;
            if ($c['ganador_id'] === null) $x['e']++;
            elseif ((int)$c['ganador_id'] === $yo) { $x['g']++; $directo[$yo][$el] = ($directo[$yo][$el] ?? 0) + 1; }
            else $x['pe']++;
            $x['pts'] += duelo_pts_de($t, $c, $yo);
            $mia = $lado === 'a' ? 'pts_a' : 'pts_b';
            $suya = $lado === 'a' ? 'pts_b' : 'pts_a';
            if ($cfg['tipo'] !== 'ajedrez') {
                $x['af'] += (float)($c[$mia] ?? 0);
                $x['ec'] += (float)($c[$suya] ?? 0);
            }
            if ($cfg['tipo'] === 'sets' && $c['marcador'] !== '') {
                try { $ps = duelo_parse_sets($c['marcador']); } catch (Throwable) { $ps = ['ga' => 0, 'gb' => 0]; }
                $x['gf'] += $lado === 'a' ? $ps['ga'] : $ps['gb'];
                $x['gc'] += $lado === 'a' ? $ps['gb'] : $ps['ga'];
            }
            unset($x);
        }
    }
    // Buchholz (suma de los puntos de los rivales): desempate del suizo y del ajedrez
    foreach ($tab as $id => &$x) foreach ($x['rivales'] as $r) $x['bh'] += $tab[$r]['pts'] ?? 0;
    unset($x);
    $usa_bh = $suizo || $cfg['tipo'] === 'ajedrez';
    $tab = array_values($tab);
    usort($tab, function ($x, $y) use ($directo, $usa_bh) {
        if ($x['pts'] != $y['pts']) return $y['pts'] <=> $x['pts'];
        if ($usa_bh && $x['bh'] != $y['bh']) return $y['bh'] <=> $x['bh'];
        $ix = (int)$x['p']['id'];
        $iy = (int)$y['p']['id'];
        $hx = $directo[$ix][$iy] ?? 0;
        $hy = $directo[$iy][$ix] ?? 0;
        if ($usa_bh && $x['g'] !== $y['g']) return $y['g'] <=> $x['g'];
        $dx = $x['af'] - $x['ec'];
        $dy = $y['af'] - $y['ec'];
        if ($dx != $dy) return $dy <=> $dx;
        if ($x['gf'] - $x['gc'] !== $y['gf'] - $y['gc']) return ($y['gf'] - $y['gc']) <=> ($x['gf'] - $x['gc']);
        if ($hx !== $hy) return $hy <=> $hx;
        return strcmp($x['p']['nombre'], $y['p']['nombre']);
    });
    foreach ($tab as $i => &$x) $x['pos'] = $i + 1;
    unset($x);
    return $tab;
}

/** "1,5" para medios puntos del ajedrez. */
function duelo_num(float $x): string
{
    return rtrim(rtrim(number_format($x, 1, ',', ''), '0'), ',');
}

/**
 * (v36) Puesto final de cada participante de la categoría: [pid => puesto] y 'terminada'.
 * Llave: 1.º campeón, 2.º finalista, 3.º (y 4.º si hay partido por el tercer puesto), luego 5.º para los que perdieron en cuartos, 9.º en octavos…
 * Monrad: todos los puestos. Todos contra todos / suizo / escalera: la tabla. Grupos: la llave final y luego los que no pasaron.
 */
function duelo_puestos(array $t, array $cat): array
{
    $cid = (int)$cat['id'];
    $fmt = (string)$cat['formato'];
    $cs = duelo_combates($cid);
    $r = ['puestos' => [], 'terminada' => false];
    if ($fmt === 'escalera') {
        foreach (duelo_escalera((int)$t['id'], $cid) as $p) $r['puestos'][(int)$p['id']] = (int)$p['siembra'];
        $r['terminada'] = $t['estado'] === 'finalizado';
        return $r;
    }
    if (!$cs) return $r;
    if (in_array($fmt, ['liga', 'suizo'], true)) {
        foreach (duelo_tabla_liga($t, $cid) as $x) $r['puestos'][(int)$x['p']['id']] = $x['pos'];
        $pend = array_filter($cs, fn($c) => $c['estado'] === 'pendiente');
        $r['terminada'] = !$pend && ($fmt === 'liga' || (int)max(array_column($cs, 'ronda')) >= duelo_suizo_rondas($cat, count($r['puestos'])));
        return $r;
    }
    if ($fmt === 'monrad') {
        $R = 0;
        foreach ($cs as $c) $R = max($R, (int)$c['ronda']);
        $fin = true;
        foreach ($cs as $c) {
            if ((int)$c['ronda'] !== $R) continue;
            if ($c['estado'] === 'pendiente') { $fin = false; continue; }
            $bl = (int)$c['bloque'];
            if ($c['ganador_id'] !== null) $r['puestos'][(int)$c['ganador_id']] = 2 * $bl + 1;
            if ($x = duelo_perdedor($c)) $r['puestos'][$x] = 2 * $bl + 2;
        }
        $r['terminada'] = $fin;
        return $r;
    }
    // llave, consolación y grupos (su llave final)
    $principal = array_values(array_filter($cs, fn($c) => (int)$c['bloque'] === 0));
    if ($fmt === 'grupos') {
        $pasan = max(1, (int)($cat['grupo_pasan'] ?? 2));
        $total_llave = 0;
        foreach (duelo_grupos_de($cid) as $g) $total_llave += min($pasan, count(duelo_tabla_liga($t, $cid, $g)));
        foreach (duelo_grupos_de($cid) as $g) foreach (duelo_tabla_liga($t, $cid, $g) as $i => $x) {
            if ($i >= $pasan) $r['puestos'][(int)$x['p']['id']] = $total_llave + 1 + ($i - $pasan) * count(duelo_grupos_de($cid));
        }
        if (!$principal) {
            // un solo grupo con un clasificado: campeón el 1.º del grupo
            if (duelo_grupos_terminados($cid) && $total_llave === 1) {
                foreach (duelo_grupos_de($cid) as $g) { $tab = duelo_tabla_liga($t, $cid, $g); if ($tab) $r['puestos'][(int)$tab[0]['p']['id']] = 1; }
                $r['terminada'] = true;
            }
            return $r;
        }
    }
    $R = 0;
    $final = null;
    $ter = null;
    foreach ($principal as $c) {
        if ((int)$c['tercer']) { $ter = $c; continue; }
        if ((int)$c['ronda'] > $R) { $R = (int)$c['ronda']; $final = $c; }
    }
    foreach ($principal as $c) {
        if ((int)$c['tercer'] || (int)$c['ronda'] === $R) continue;
        if ($x = duelo_perdedor($c)) $r['puestos'][$x] = (1 << ($R - (int)$c['ronda'])) + 1;
    }
    if ($final && in_array($final['estado'], ['jugado', 'bye'], true) && $final['ganador_id'] !== null) {
        $r['puestos'][(int)$final['ganador_id']] = 1;
        if ($x = duelo_perdedor($final)) $r['puestos'][$x] = 2;
        $r['terminada'] = true;
    }
    if ($ter) {
        if ($ter['estado'] === 'jugado' && $ter['ganador_id'] !== null) {
            $r['puestos'][(int)$ter['ganador_id']] = 3;
            if ($x = duelo_perdedor($ter)) $r['puestos'][$x] = 4;
        } else {
            $r['terminada'] = false;
        }
    }
    return $r;
}

/**
 * Medallas de una categoría: ['oro' => [pid], 'plata' => [...], 'bronce' => [...], 'terminada' => bool].
 * Llave: oro = campeón, plata = finalista; bronce según la regla (doble = los dos semifinalistas, partido = el del tercer puesto).
 * Todos contra todos / suizo / Monrad: los 3 primeros, solo cuando se jugó todo.
 */
function duelo_medallas_categoria(array $t, array $cat): array
{
    $r = ['oro' => [], 'plata' => [], 'bronce' => [], 'terminada' => false];
    $fmt = (string)$cat['formato'];
    if (in_array($fmt, ['liga', 'suizo', 'monrad', 'escalera'], true)) {
        $pu = duelo_puestos($t, $cat);
        if (!$pu['terminada']) return $r;
        asort($pu['puestos']);
        foreach ($pu['puestos'] as $pid => $ps) {
            if ($ps === 1) $r['oro'][] = $pid; elseif ($ps === 2) $r['plata'][] = $pid; elseif ($ps === 3) $r['bronce'][] = $pid;
        }
        $r['terminada'] = true;
        return $r;
    }
    $cs = array_values(array_filter(duelo_combates((int)$cat['id']), fn($c) => (int)$c['bloque'] === 0));
    if (!$cs) {
        if ($fmt === 'grupos') {
            $pu = duelo_puestos($t, $cat);
            foreach ($pu['puestos'] as $pid => $ps) if ($ps === 1) $r['oro'][] = $pid;
            $r['terminada'] = $pu['terminada'];
        }
        return $r;
    }
    $cfg = duelo_cfg($t);
    $max = 0;
    $fin = null;
    $ter = null;
    foreach ($cs as $c) {
        if ((int)$c['tercer']) { $ter = $c; continue; }
        if ((int)$c['ronda'] > $max) { $max = (int)$c['ronda']; $fin = $c; }
    }
    if ($fin && in_array($fin['estado'], ['jugado', 'bye'], true) && $fin['ganador_id'] !== null) {
        $r['oro'][] = (int)$fin['ganador_id'];
        if ($x = duelo_perdedor($fin)) $r['plata'][] = $x;
        $r['terminada'] = true;
    }
    if ($cfg['bronce'] === 'doble' && $max >= 2) {
        foreach ($cs as $c) if (!(int)$c['tercer'] && (int)$c['ronda'] === $max - 1 && ($x = duelo_perdedor($c))) $r['bronce'][] = $x;
    } elseif ($cfg['bronce'] === 'partido' && $ter && $ter['estado'] === 'jugado' && $ter['ganador_id'] !== null) {
        $r['bronce'][] = (int)$ter['ganador_id'];
    }
    if ($cfg['bronce'] === 'partido' && $ter && $ter['estado'] !== 'jugado') $r['terminada'] = false;
    return $r;
}

/** Medallero del campeonato: por categoría y por club. */
function duelo_medallero(array $t): array
{
    $part = duelo_participantes((int)$t['id']);
    $cats = [];
    $clubes = [];
    foreach (duelo_categorias((int)$t['id']) as $cat) {
        $m = duelo_medallas_categoria($t, $cat);
        if (!$m['oro'] && !$m['plata'] && !$m['bronce']) continue;
        $cats[] = ['cat' => $cat, 'm' => $m];
        foreach (['oro', 'plata', 'bronce'] as $tipo) {
            foreach ($m[$tipo] as $pid) {
                $club = trim((string)($part[$pid]['club'] ?? '')) ?: 'Sin club';
                $clubes[$club] ??= ['club' => $club, 'oro' => 0, 'plata' => 0, 'bronce' => 0];
                $clubes[$club][$tipo]++;
            }
        }
    }
    $clubes = array_values($clubes);
    usort($clubes, fn($a, $b) => [$b['oro'], $b['plata'], $b['bronce'], $a['club']] <=> [$a['oro'], $a['plata'], $a['bronce'], $b['club']]);
    return ['cats' => $cats, 'clubes' => $clubes, 'part' => $part];
}

/* ============================================================
 * (v36) RANKING DEL CIRCUITO (suma de varios campeonatos del mismo deporte)
 * ============================================================ */

/** Tramos de puestos del ranking: 1.º, 2.º, 3.º, 4.º, 5.º-8.º, 9.º-16.º, 17.º-32.º, 33.º en adelante. */
function duelo_rank_tramos(): array
{
    return ['1.º', '2.º', '3.º', '4.º', '5.º a 8.º', '9.º a 16.º', '17.º a 32.º', '33.º o más'];
}

function duelo_rank_pts_leer(string $s): array
{
    $base = [100, 70, 50, 40, 25, 15, 8, 4];
    $v = array_map('intval', preg_split('/[\s,;]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    foreach ($base as $i => $x) if (isset($v[$i])) $base[$i] = max(0, min(10000, $v[$i]));
    return $base;
}

function duelo_rank_puntos(int $puesto, array $tabla): int
{
    $i = match (true) { $puesto <= 1 => 0, $puesto === 2 => 1, $puesto === 3 => 2, $puesto === 4 => 3, $puesto <= 8 => 4, $puesto <= 16 => 5, $puesto <= 32 => 6, default => 7 };
    return $tabla[$i] ?? 0;
}

/**
 * Ranking de todos los campeonatos de un deporte de la cuenta: por categoría (mismo nombre), suma los puntos de cada campeonato
 * según el puesto final × el factor del campeonato (nacional 100 %, regional 50 %…). Solo cuenta categorías terminadas.
 */
function duelo_ranking(int $lid, string $modalidad): array
{
    $cats = [];
    foreach (filas('SELECT * FROM torneos WHERE liga_id = ? AND modalidad = ? ORDER BY id', [$lid, $modalidad]) as $t) {
        $cfg = duelo_cfg($t);
        if ($cfg['factor'] <= 0) continue;
        $part = duelo_participantes((int)$t['id']);
        foreach (duelo_categorias((int)$t['id']) as $cat) {
            if ($cat['formato'] === 'escalera') continue;
            $pu = duelo_puestos($t, $cat);
            if (!$pu['terminada']) continue;
            $kc = mb_strtolower(trim($cat['nombre']));
            $cats[$kc] ??= ['nombre' => $cat['nombre'], 'torneos' => [], 'filas' => []];
            $cats[$kc]['torneos'][(int)$t['id']] = $t['nombre'];
            foreach ($pu['puestos'] as $pid => $ps) {
                $p = $part[$pid] ?? null;
                if (!$p) continue;
                $kp = mb_strtolower(trim(duelo_nombre($p)));
                $pts = (int)round(duelo_rank_puntos($ps, $cfg['rank_pts']) * $cfg['factor'] / 100);
                $f = &$cats[$kc]['filas'][$kp];
                $f ??= ['nombre' => duelo_nombre($p), 'club' => $p['club'], 'pts' => 0, 'n' => 0, 'mejor' => 999, 'det' => []];
                $f['pts'] += $pts;
                $f['n']++;
                $f['mejor'] = min($f['mejor'], $ps);
                $f['det'][(int)$t['id']] = [$ps, $pts];
                if ($p['club'] !== '') $f['club'] = $p['club'];
                unset($f);
            }
        }
    }
    foreach ($cats as &$c) {
        $c['filas'] = array_values($c['filas']);
        usort($c['filas'], fn($a, $b) => [$b['pts'], $a['mejor'], $a['nombre']] <=> [$a['pts'], $b['mejor'], $b['nombre']]);
    }
    unset($c);
    return $cats;
}

function duelo_html_ranking(array $rk): string
{
    if (!$rk) return '<p class="suave">El ranking aparece cuando termina al menos una categoría de un campeonato (la final jugada o todas las fechas).</p>';
    $h = '';
    foreach ($rk as $c) {
        $h .= '<h3>' . e($c['nombre']) . ' <small class="suave">· ' . count($c['torneos']) . ' campeonato' . (count($c['torneos']) === 1 ? '' : 's') . '</small></h3><div class="tabla-scroll"><table><tr><th>#</th><th>Jugador</th><th>Club</th>';
        foreach ($c['torneos'] as $nom) $h .= '<th class="num" title="' . e($nom) . '">' . e(mb_strimwidth($nom, 0, 14, '…')) . '</th>';
        $h .= '<th class="num">Pts</th></tr>';
        foreach ($c['filas'] as $i => $f) {
            $h .= '<tr><td>' . ($i + 1) . '</td><td>' . e($f['nombre']) . '</td><td>' . e($f['club']) . '</td>';
            foreach (array_keys($c['torneos']) as $tid) $h .= '<td class="num">' . (isset($f['det'][$tid]) ? $f['det'][$tid][1] . ' <small class="suave">(' . $f['det'][$tid][0] . '.º)</small>' : '—') . '</td>';
            $h .= '<td class="num pts"><b>' . $f['pts'] . '</b></td></tr>';
        }
        $h .= '</table></div>';
    }
    return $h;
}

/* ============================================================
 * HTML COMPARTIDO (panel y página pública)
 * ============================================================ */

function duelo_css(): string
{
    static $ya = false;
    if ($ya) return '';
    $ya = true;
    return '<style>.llave-eq .sb{font-size:.72rem;opacity:.7;margin-left:4px}.llave-partido.bye{opacity:.6;border-style:dashed}.duelo-res{font-size:.78rem;color:var(--suave,#667);text-align:center}
      .medallero td.num{font-size:1.05rem}.podio{display:flex;flex-wrap:wrap;gap:6px 14px;margin:.3rem 0}.podio span{white-space:nowrap}
      .duelo-lista .duelo-item{display:flex;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid var(--borde,#e5e5e5)}
      .duelo-lista .duelo-item .lados{flex:1;min-width:0}.duelo-lista .duelo-item .lados div{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .duelo-lista .duelo-item .gana{font-weight:700}.duelo-lista .duelo-item .cuando{font-size:.78rem;color:var(--suave,#667);text-align:right;min-width:86px}
      .monrad-bloque{border-left:3px solid var(--borde,#e5e5e5);padding-left:6px;margin-bottom:10px}.monrad-bloque h4{margin:.2rem 0;font-size:.78rem;color:var(--suave,#667)}
      .escalera-pos{font-weight:700;font-size:1.05rem;min-width:28px;display:inline-block}</style>';
}

/** Una tarjeta de combate dentro de la llave. */
function duelo_html_caja(array $t, array $c, array $part, string $enlace = ''): string
{
    $res = duelo_texto_resultado($t, $c);
    $fila = function (?int $pid, string $lado) use ($c, $part) {
        $p = $pid ? ($part[$pid] ?? null) : null;
        $cls = $c['ganador_id'] !== null && $pid ? ((int)$c['ganador_id'] === $pid ? ' gana' : ' pierde') : '';
        $nom = $p ? e(duelo_nombre($p)) : '<span class="suave">' . ($c['estado'] === 'bye' || $c['estado'] === 'vacio' ? '—' : 'por definir') . '</span>';
        $sem = $p && (int)$p['siembra'] > 0 ? '<span class="sb">[' . (int)$p['siembra'] . ']</span>' : '';
        $club = $p && $p['club'] !== '' ? '<small class="suave" style="display:block;font-size:.7rem">' . e($p['club']) . '</small>' : '';
        return '<div class="llave-eq' . $cls . '"><span>' . $nom . $sem . $club . '</span></div>';
    };
    $info = trim(($c['fecha'] !== '' ? fecha_corta($c['fecha']) : '') . ($c['hora'] !== '' ? ' ' . $c['hora'] : '') . ($c['lugar'] !== '' ? ' · ' . $c['lugar'] : ''));
    $tag = $enlace !== '' ? 'a href="' . e($enlace) . '"' : 'div';
    return '<' . $tag . ' class="llave-partido' . ($c['estado'] === 'bye' ? ' bye' : '') . '">'
        . $fila($c['a_id'] !== null ? (int)$c['a_id'] : null, 'a') . $fila($c['b_id'] !== null ? (int)$c['b_id'] : null, 'b')
        . ($res !== '' ? '<div class="duelo-res">' . e($res) . '</div>' : ($info !== '' ? '<span class="llave-info">' . e($info) . '</span>' : ''))
        . '</' . ($enlace !== '' ? 'a' : 'div') . '>';
}

/** Llave de un bloque (0 = principal; 1 = consolación). */
function duelo_html_llave(array $t, array $cat, array $part, ?callable $enlace = null, int $bloque = 0): string
{
    $cs = array_values(array_filter(duelo_combates((int)$cat['id']), fn($c) => (int)$c['bloque'] === $bloque));
    $total = 0;
    foreach (duelo_combates((int)$cat['id']) as $c) if ((int)$c['bloque'] === 0 && !(int)$c['tercer']) $total = max($total, (int)$c['ronda']);
    if (!$cs) return '<p class="suave">Todavía no se sortea esta categoría.</p>';
    $por = [];
    $ter = null;
    foreach ($cs as $c) { if ((int)$c['tercer']) $ter = $c; else $por[(int)$c['ronda']][] = $c; }
    ksort($por);
    $h = duelo_css() . '<div class="llave">';
    foreach ($por as $r => $lista) {
        $h .= '<div class="llave-col"><h3>' . e(duelo_nombre_ronda($total, $r)) . '</h3>';
        foreach ($lista as $c) $h .= duelo_html_caja($t, $c, $part, $enlace ? $enlace($c) : '');
        if ($r === $total && $ter) $h .= '<h3 style="margin-top:10px">Tercer puesto</h3>' . duelo_html_caja($t, $ter, $part, $enlace ? $enlace($ter) : '');
        $h .= '</div>';
    }
    return $h . '</div>';
}

/** (v36) Llave Monrad: cada ronda con sus sub-llaves de puestos. */
function duelo_html_monrad(array $t, array $cat, array $part, ?callable $enlace = null): string
{
    $cs = duelo_combates((int)$cat['id']);
    if (!$cs) return '<p class="suave">Todavía no se sortea esta categoría.</p>';
    $R = max(array_map(fn($c) => (int)$c['ronda'], $cs));
    $s = 1 << $R;
    $por = [];
    foreach ($cs as $c) $por[(int)$c['ronda']][(int)$c['bloque']][] = $c;
    ksort($por);
    $h = duelo_css() . '<div class="llave">';
    foreach ($por as $r => $bloques) {
        ksort($bloques);
        $tam = $s >> ($r - 1);
        $h .= '<div class="llave-col"><h3>Ronda ' . $r . '</h3>';
        foreach ($bloques as $bl => $lista) {
            $h .= '<div class="monrad-bloque"><h4>' . ($r === $R ? 'Puestos ' . (2 * $bl + 1) . ' y ' . (2 * $bl + 2) : 'Puestos ' . ($bl * $tam + 1) . ' a ' . (($bl + 1) * $tam)) . '</h4>';
            foreach ($lista as $c) $h .= duelo_html_caja($t, $c, $part, $enlace ? $enlace($c) : '');
            $h .= '</div>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

/** (v36) Puestos finales (Monrad, grupos y llaves) en una lista. */
function duelo_html_puestos(array $t, array $cat, array $part): string
{
    $pu = duelo_puestos($t, $cat);
    if (!$pu['puestos']) return '';
    asort($pu['puestos']);
    $h = '<h3>Puestos' . ($pu['terminada'] ? '' : ' <small class="suave">(parcial)</small>') . '</h3><div class="tabla-scroll"><table><tr><th>Puesto</th><th>' . e(textos_duelo($t)['participante']) . '</th><th>Club</th></tr>';
    foreach ($pu['puestos'] as $pid => $ps) {
        $p = $part[$pid] ?? null;
        if (!$p) continue;
        $h .= '<tr><td>' . ($ps <= 3 ? icono_medalla(medalla_de_puesto($ps)) . ' ' : '') . $ps . '.º</td><td>' . e(duelo_nombre($p)) . '</td><td>' . e($p['club']) . '</td></tr>';
    }
    return $h . '</table></div>';
}

function duelo_html_tabla_liga(array $t, array $cat, ?int $bloque = null): string
{
    $tab = duelo_tabla_liga($t, (int)$cat['id'], $bloque);
    $cfg = duelo_cfg($t);
    $T = textos_duelo($t);
    $bh = $cat['formato'] === 'suizo' || $cfg['tipo'] === 'ajedrez';
    $dif = match ($cfg['tipo']) { 'sets' => $cfg['game_pts'] > 0 ? 'Games' : 'Sets', 'puntos' => 'Puntos', 'goles' => 'Goles', default => '' };
    $pasan = $cat['formato'] === 'grupos' && $bloque ? max(1, (int)($cat['grupo_pasan'] ?? 2)) : 0;
    ob_start(); ?>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th><?= e($T['participante']) ?></th><th class="num">PJ</th><th class="num">G</th><?php if ($cfg['empate']): ?><th class="num"><?= $cfg['tipo'] === 'ajedrez' ? 'T' : 'E' ?></th><?php endif; ?><th class="num">P</th>
        <?php if ($dif !== ''): ?><th class="num" title="<?= e($dif) ?> a favor y en contra"><?= e($dif) ?></th><?php endif; ?><?php if ($bh): ?><th class="num" title="Buchholz: suma de los puntos de sus rivales">Buch.</th><?php endif; ?><th class="num">Pts</th></tr>
      <?php foreach ($tab as $x): ?>
        <tr<?= $pasan && $x['pos'] <= $pasan ? ' style="background:var(--fondo-suave,#f3f8f3)"' : '' ?>><td><?= (int)$x['pos'] ?><?= $pasan && $x['pos'] <= $pasan ? ' ✓' : '' ?></td><td><?= e(duelo_nombre($x['p'])) ?><?= $x['p']['club'] !== '' ? '<br><small class="suave">' . e($x['p']['club']) . '</small>' : '' ?></td>
          <td class="num"><?= $x['pj'] ?></td><td class="num"><?= $x['g'] ?></td><?php if ($cfg['empate']): ?><td class="num"><?= $x['e'] ?></td><?php endif; ?><td class="num"><?= $x['pe'] ?></td>
          <?php if ($dif !== ''): ?><td class="num"><?= duelo_num($x['af']) ?>-<?= duelo_num($x['ec']) ?></td><?php endif; ?>
          <?php if ($bh): ?><td class="num"><?= duelo_num($x['bh']) ?></td><?php endif; ?>
          <td class="num pts"><?= duelo_num((float)$x['pts']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($pasan): ?><p class="ayuda">✓ Pasan a la llave final: los <?= $pasan ?> primeros.</p><?php endif; ?>
    <?php return (string)ob_get_clean();
}

/** Lista de combates (programación y resultados). $enlace: para el panel (abre el combate). */
function duelo_html_lista(array $t, array $combates, array $part, array $cats_nombre = [], ?callable $enlace = null, ?array $cat = null): string
{
    $h = duelo_css() . '<div class="duelo-lista">';
    foreach ($combates as $c) {
        if ($c['estado'] === 'vacio' || ($c['estado'] === 'bye' && $c['metodo'] !== 'Descansa (bye)')) continue;
        $a = $c['a_id'] ? ($part[(int)$c['a_id']] ?? null) : null;
        $b = $c['b_id'] ? ($part[(int)$c['b_id']] ?? null) : null;
        $ga = $c['ganador_id'] !== null && $a && (int)$c['ganador_id'] === (int)$a['id'] && $b;
        $gb = $c['ganador_id'] !== null && $b && (int)$c['ganador_id'] === (int)$b['id'];
        $res = duelo_texto_resultado($t, $c);
        $cuando = trim(($c['fecha'] !== '' ? fecha_corta($c['fecha']) : '') . ($c['hora'] !== '' ? ' ' . $c['hora'] : ''));
        $link = $enlace ? $enlace($c) : '';
        $etq = $cat ? '<span class="suave" style="min-width:58px;font-size:.78rem">' . e(duelo_instancia($cat, $c, $combates)) . '</span>' : '';
        $h .= ($link !== '' ? '<a class="duelo-item partido-link" style="text-decoration:none;color:inherit" href="' . e($link) . '">' : '<div class="duelo-item">') . $etq . '<div class="lados">'
            . ($cats_nombre ? '<small class="suave">' . e($cats_nombre[(int)$c['categoria_id']] ?? '') . '</small>' : '')
            . '<div class="' . ($ga ? 'gana' : '') . '">' . ($ga ? '🏆 ' : '') . e($a ? duelo_nombre($a) : 'por definir') . '</div>'
            . ($c['estado'] === 'bye' && !$c['b_id'] ? '<div class="suave">descansa</div>' : '<div class="' . ($gb ? 'gana' : '') . '">' . ($gb ? '🏆 ' : '') . e($b ? duelo_nombre($b) : 'por definir') . '</div>') . '</div>'
            . '<div class="cuando">' . ($res !== '' ? '<b>' . e($res) . '</b>' : e($cuando !== '' ? $cuando : 'por programar')) . ($c['lugar'] !== '' ? '<br>' . e($c['lugar']) : '') . '</div>' . ($link !== '' ? '</a>' : '</div>');
    }
    return $h . '</div>';
}

/** (v36) Escalera: puestos con su récord de desafíos. */
function duelo_html_escalera(array $t, array $cat): string
{
    $esc = duelo_escalera((int)$t['id'], (int)$cat['id']);
    if (!$esc) return '<p class="suave">Todavía no hay jugadores en la escalera.</p>';
    $rec = [];
    foreach (duelo_combates((int)$cat['id']) as $c) {
        if ($c['estado'] !== 'jugado') continue;
        foreach ([(int)$c['a_id'], (int)$c['b_id']] as $id) {
            $rec[$id] ??= ['g' => 0, 'p' => 0];
            $c['ganador_id'] !== null && (int)$c['ganador_id'] === $id ? $rec[$id]['g']++ : $rec[$id]['p']++;
        }
    }
    $h = duelo_css() . '<div class="tabla-scroll"><table><tr><th>Puesto</th><th>' . e(textos_duelo($t)['participante']) . '</th><th>Club</th><th class="num">Ganó</th><th class="num">Perdió</th></tr>';
    foreach ($esc as $p) {
        $r = $rec[(int)$p['id']] ?? ['g' => 0, 'p' => 0];
        $h .= '<tr><td><span class="escalera-pos">' . (int)$p['siembra'] . '</span></td><td>' . e(duelo_nombre($p)) . '</td><td>' . e($p['club']) . '</td><td class="num">' . $r['g'] . '</td><td class="num">' . $r['p'] . '</td></tr>';
    }
    return $h . '</table></div><p class="ayuda">Se puede desafiar hasta ' . max(1, (int)($cat['escalera_salto'] ?? 3)) . ' puestos más arriba. Si gana el retador, toma el puesto del desafiado y los de en medio bajan uno.</p>';
}

/** (v36) Todo lo de una categoría según su formato (panel con $enlace; página pública sin él). */
function duelo_html_categoria(array $t, array $cat, array $part, ?callable $enlace = null): string
{
    $cid = (int)$cat['id'];
    $T = textos_duelo($t);
    $fmt = (string)$cat['formato'];
    $combs = duelo_combates($cid);
    $h = duelo_css();
    switch ($fmt) {
        case 'liga':
            return $h . duelo_html_tabla_liga($t, $cat) . '<h3>' . e($T['combates']) . '</h3>' . duelo_html_lista($t, $combs, $part, [], $enlace, $cat);
        case 'suizo':
            $n = count(array_filter($part, fn($p) => (int)$p['activo'] === 1));
            $max = $combs ? max(array_map(fn($c) => (int)$c['ronda'], $combs)) : 0;
            $h .= '<p class="suave">Ronda ' . $max . ' de ' . duelo_suizo_rondas($cat, $n) . '.</p>' . duelo_html_tabla_liga($t, $cat);
            for ($r = $max; $r >= 1; $r--) $h .= '<h3>Ronda ' . $r . '</h3>' . duelo_html_lista($t, array_values(array_filter($combs, fn($c) => (int)$c['ronda'] === $r)), $part, [], $enlace);
            return $h;
        case 'escalera':
            $h .= duelo_html_escalera($t, $cat);
            $pend = array_values(array_filter($combs, fn($c) => $c['estado'] === 'pendiente'));
            $jug = array_reverse(array_values(array_filter($combs, fn($c) => $c['estado'] === 'jugado')));
            if ($pend) $h .= '<h3>Desafíos pendientes</h3>' . duelo_html_lista($t, $pend, $part, [], $enlace, $cat);
            if ($jug) $h .= '<h3>Últimos desafíos</h3>' . duelo_html_lista($t, array_slice($jug, 0, 30), $part, [], $enlace, $cat);
            return $h;
        case 'grupos':
            foreach (duelo_grupos_de($cid) as $g) {
                $h .= '<h3>Grupo ' . duelo_letra_grupo($g) . '</h3>' . duelo_html_tabla_liga($t, $cat, $g)
                    . '<details' . ($enlace ? ' open' : '') . '><summary class="suave">' . e($T['combates']) . ' del grupo ' . duelo_letra_grupo($g) . '</summary>' . duelo_html_lista($t, duelo_combates_bloque($cid, $g), $part, [], $enlace) . '</details>';
            }
            $h .= '<h3>🏆 Llave final</h3>';
            if (duelo_combates_bloque($cid, 0)) $h .= duelo_html_llave($t, $cat, $part, $enlace) . duelo_html_puestos($t, $cat, $part);
            elseif (duelo_grupos_terminados($cid)) $h .= duelo_html_puestos($t, $cat, $part);
            else $h .= '<p class="suave">Se arma sola cuando terminen todos los partidos de grupo: pasan los ' . max(1, (int)($cat['grupo_pasan'] ?? 2)) . ' primeros de cada grupo.</p>';
            return $h;
        case 'consolacion':
            return $h . duelo_html_llave($t, $cat, $part, $enlace) . '<h3>🥈 Consolación <small class="suave">(los que perdieron su primer partido)</small></h3>'
                . (array_filter($combs, fn($c) => (int)$c['bloque'] === 1) ? duelo_html_llave($t, $cat, $part, $enlace, 1) : '<p class="suave">Con menos de 3 inscritos no hay consolación.</p>');
        case 'monrad':
            return $h . duelo_html_monrad($t, $cat, $part, $enlace) . duelo_html_puestos($t, $cat, $part);
        default:
            return $h . duelo_html_llave($t, $cat, $part, $enlace);
    }
}

function duelo_html_medallero(array $t, array $med): string
{
    $part = $med['part'];
    ob_start();
    if (!$med['cats']): ?><p class="suave">Todavía no hay medallas: aparecen cuando se juegan las finales.</p><?php return (string)ob_get_clean(); endif; ?>
    <?php if (count($med['clubes']) > 1 || ($med['clubes'][0]['club'] ?? '') !== 'Sin club'): ?>
      <h3>Medallero por club</h3>
      <div class="tabla-scroll"><table class="medallero">
        <tr><th>#</th><th>Club</th><th class="num">🥇</th><th class="num">🥈</th><th class="num">🥉</th><th class="num">Total</th></tr>
        <?php foreach ($med['clubes'] as $i => $c): ?>
          <tr><td><?= $i + 1 ?></td><td><?= e($c['club']) ?></td><td class="num"><?= $c['oro'] ?></td><td class="num"><?= $c['plata'] ?></td><td class="num"><?= $c['bronce'] ?></td><td class="num"><b><?= $c['oro'] + $c['plata'] + $c['bronce'] ?></b></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
    <h3>Podio de cada categoría</h3>
    <?php foreach ($med['cats'] as $x): ?>
      <div class="tarjeta" style="margin-bottom:8px;padding:10px 14px"><b><?= e($x['cat']['nombre']) ?></b><?= !$x['m']['terminada'] ? ' <span class="etiqueta">en juego</span>' : '' ?>
        <div class="podio"><?php foreach (['oro', 'plata', 'bronce'] as $m) foreach ($x['m'][$m] as $pid): $p = $part[$pid] ?? null; if (!$p) continue; ?>
          <span><?= icono_medalla($m) ?> <?= e(duelo_nombre($p)) ?><?= $p['club'] !== '' ? ' <small class="suave">(' . e($p['club']) . ')</small>' : '' ?></span>
        <?php endforeach; ?></div></div>
    <?php endforeach;
    return (string)ob_get_clean();
}

/* ============================================================
 * EJEMPLO PARA PROBAR
 * ============================================================ */

/** Resultado al azar (válido según las reglas) para el ejemplo y las demos. */
function duelo_resultado_azar(array $t, array $cat, array $c): array
{
    $tipo = tipo_duelo($t);
    $cfg = duelo_cfg($t);
    $d = ['fecha' => date('Y-m-d', strtotime('-' . mt_rand(1, 6) . ' days')), 'hora' => sprintf('%02d:%02d', mt_rand(9, 18), mt_rand(0, 1) * 30), 'lugar' => textos_duelo($t)['lugar'] . ' ' . mt_rand(1, 3)];
    $empate_ok = in_array($cat['formato'], ['liga', 'grupos', 'suizo'], true) && !($cat['formato'] === 'grupos' && (int)$c['bloque'] === 0);
    if ($tipo === 'sets') {
        $gana_a = mt_rand(0, 9) < 6;
        $sets = [];
        $n = intdiv($cfg['sets'], 2) + 1;
        $wa = $wb = 0;
        while ($wa < $n && $wb < $n) {
            $a_gana = ($wa + $wb === 1 && mt_rand(0, 2) === 0) ? !$gana_a : $gana_a;
            if ($cfg['game_pts'] > 0) {
                $P = $cfg['game_pts'];
                if ($cfg['game_dif'] === 2 && mt_rand(0, 4) === 0) { $alto = $P + 1 + mt_rand(0, 2); $bajo = $alto - 2; }
                else { $alto = $P; $bajo = mt_rand(0, $cfg['game_dif'] === 1 ? $P - 1 : $P - 2); }
            } else {
                $alto = 6;
                $bajo = mt_rand(0, 4);
            }
            $sets[] = $a_gana ? "$alto-$bajo" : "$bajo-$alto";
            $a_gana ? $wa++ : $wb++;
        }
        return $d + ['metodo' => 'Sets', 'marcador' => implode(' ', $sets)];
    }
    if ($tipo === 'puntos') {
        $pa = mt_rand(0, 8);
        $pb = mt_rand(0, 8);
        if ($pa === $pb) $pa++;
        return $d + ['metodo' => mt_rand(0, 5) === 0 ? 'Ippon' : 'Puntos', 'pts_a' => $pa, 'pts_b' => $pb, 'ganador' => $pa > $pb ? 'a' : 'b'];
    }
    if ($tipo === 'ajedrez') {
        $x = mt_rand(0, 9);
        $g = $x < 4 ? 'a' : ($x < 7 ? 'b' : ($empate_ok ? 'empate' : 'a'));
        return $d + ['metodo' => $x === 3 ? 'Abandono' : 'Partida', 'ganador' => $g];
    }
    if ($tipo === 'goles') {
        $pa = mt_rand(0, 4);
        $pb = mt_rand(0, 4);
        if ($pa === $pb && !$empate_ok) return $d + ['metodo' => 'Penales', 'pts_a' => $pa, 'pts_b' => $pb, 'marcador' => mt_rand(0, 1) ? '5-4' : '3-4'];
        return $d + ['metodo' => 'Marcador', 'pts_a' => $pa, 'pts_b' => $pb];
    }
    $met = ['Decisión unánime', 'Decisión dividida', 'KO', 'TKO / RSC'][mt_rand(0, 3)];
    return $d + ['metodo' => $met, 'ganador' => mt_rand(0, 1) ? 'a' : 'b', 'round_fin' => str_starts_with($met, 'Decisión') ? 0 : mt_rand(1, 3), 'marcador' => str_starts_with($met, 'Decisión') ? '29-28 30-27 28-29' : ''];
}

/** Juega al azar los combates pendientes (con los dos rivales) de una categoría; $hasta_ronda limita. Devuelve cuántos jugó. */
function duelo_jugar_azar(array $t, array $cat, int $hasta_ronda = 99, int $max = 999): int
{
    $n = 0;
    for ($vuelta = 0; $vuelta < 12 && $n < $max; $vuelta++) {
        $hubo = false;
        foreach (duelo_combates((int)$cat['id']) as $c) {
            if ($n >= $max) break;
            if ($c['estado'] !== 'pendiente' || !$c['a_id'] || !$c['b_id'] || (int)$c['ronda'] > $hasta_ronda) continue;
            duelo_guardar_resultado($t, $c, duelo_resultado_azar($t, $cat, $c));
            $n++;
            $hubo = true;
        }
        if (!$hubo) break;
    }
    return $n;
}

function duelo_cargar_ejemplo(array $t): void
{
    $tid = (int)$t['id'];
    if ((int)valor('SELECT COUNT(*) FROM duelo_participantes WHERE torneo_id = ?', [$tid]) > 0) throw new RuntimeException('El campeonato ya tiene inscritos. El ejemplo solo se carga en uno vacío.');
    duelo_cargar_categorias_base($t);
    $m = (string)$t['modalidad'];
    $clubes = ['Club Quito', 'Academia Norte', 'Los Chillos', 'Club Cumbayá'];
    $nombres_v = ['Mateo Andrade', 'Sebastián Vera', 'Nicolás Paredes', 'Martín Salazar', 'Daniel Cevallos', 'Joaquín Mena', 'Tomás Herrera', 'Emilio Castillo', 'Gabriel Ortiz', 'Samuel Proaño', 'Lucas Tapia'];
    $nombres_d = ['Valentina Ruiz', 'Camila Guamán', 'Isabella Zambrano', 'Sofía Morales', 'Emilia Chiriboga', 'Antonella Vaca'];
    $parejas_v = ['Andrés Mejía', 'Felipe Ríos', 'Diego Larrea', 'Juan Pablo Egas', 'Santiago Bustos', 'Ricardo Viteri', 'Bruno Jaramillo'];
    $parejas_d = ['Paula Benítez', 'Daniela Ochoa', 'Mariana Cueva', 'Fernanda Guerrero', 'Andrea Lasso'];
    mt_srand(2400 + strlen($m));
    $cats = duelo_categorias($tid);
    $inscribir = function (string $nom, int $cid, array $extra = []) use ($tid, $clubes) {
        q('INSERT INTO duelo_participantes (torneo_id, categoria_id, nombre, pareja, club, ciudad, rama, nacimiento, peso, grado, siembra) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $tid, $cid, $nom, $extra['pareja'] ?? '', $extra['club'] ?? $clubes[mt_rand(0, 3)], 'Quito', $extra['rama'] ?? '', $extra['nac'] ?? '', $extra['peso'] ?? null, $extra['grado'] ?? '', $extra['siembra'] ?? 0]);
    };
    // (v36) squash, ajedrez y e-sports: la categoría principal con su formato de fábrica (grupos / suizo) y otra con un formato distinto
    $nuevos = in_array($m, ['SQUASH', 'AJED', 'ESPOR'], true);
    if ($nuevos) {
        $c1 = $cats[$m === 'SQUASH' ? 5 : ($m === 'AJED' ? 5 : 0)];
        $c2 = $cats[$m === 'SQUASH' ? 9 : ($m === 'AJED' ? 2 : 1)];
    } else {
        $c1 = $cats[0];
        $c2 = $cats[count($cats) > 5 ? 5 : 1];
    }
    $n1 = $nuevos ? 10 : 7;
    $todos_v = array_merge($nombres_v, ['Pablo Ayala', 'Iván Román']);
    foreach (array_slice($todos_v, 0, $n1) as $i => $n) {
        $ex = ['rama' => 'M', 'siembra' => $i < 2 ? $i + 1 : 0];
        if ($m === 'PADEL') $ex['pareja'] = $parejas_v[$i];
        if ($m === 'BOXEO') $ex['peso'] = 52 + $i * 0.6;
        if ($m === 'MARC') { $ex['nac'] = (date('Y') - 10) . '-0' . ($i + 1) . '-15'; $ex['grado'] = grados_referencia()[$i % 5]; }
        if ($m === 'AJED') { $ex['grado'] = (string)(2050 - $i * 45); $ex['siembra'] = 0; }
        $inscribir($n, (int)$c1['id'], $ex);
    }
    foreach (array_slice($nombres_d, 0, $nuevos ? 6 : 5) as $i => $n) {
        $ex = ['rama' => 'F'];
        if ($m === 'PADEL') $ex['pareja'] = $parejas_d[$i];
        if ($m === 'BOXEO') $ex['peso'] = 50 + $i;
        if ($m === 'MARC') { $ex['peso'] = 55 + $i; $ex['nac'] = '1998-05-1' . $i; $ex['grado'] = 'Negro 1.º Dan'; }
        if ($m === 'AJED') { $ex['nac'] = (date('Y') - 13) . '-03-1' . $i; $ex['grado'] = (string)(1500 - $i * 40); }
        if ($m === 'ESPOR') $ex['pareja'] = $parejas_d[$i % 5];
        $inscribir($n, (int)$c2['id'], $ex);
    }
    // segunda categoría: en los de siempre, todos contra todos; squash: Monrad; ajedrez: todos contra todos; e-sports: llave con 3.er puesto
    $f2 = match ($m) { 'SQUASH' => 'monrad', 'ESPOR' => 'llave', default => 'liga' };
    if ($c2['formato'] !== $f2) q('UPDATE duelo_categorias SET formato = ? WHERE id = ?', [$f2, $c2['id']]);
    $t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
    $c1 = fila('SELECT * FROM duelo_categorias WHERE id = ?', [$c1['id']]);
    $c2 = fila('SELECT * FROM duelo_categorias WHERE id = ?', [$c2['id']]);
    foreach ([$c1, $c2] as $cat) duelo_sortear($t, $cat);
    if ($nuevos) {
        if ($c1['formato'] === 'suizo') {
            // ajedrez: 3 rondas jugadas y la 4.ª emparejada
            for ($r = 1; $r <= 3; $r++) { duelo_jugar_azar($t, $c1); duelo_suizo_emparejar($t, $c1); }
        } else {
            duelo_jugar_azar($t, $c1, 99, (int)valor('SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ? AND bloque > 0', [$c1['id']])); // grupos completos: la llave final se arma sola…
            $rf = (int)valor('SELECT MAX(ronda) FROM duelo_combates WHERE categoria_id = ? AND bloque = 0', [$c1['id']]);
            duelo_jugar_azar($t, $c1, $rf - 1); // …y queda la final por jugar
        }
        $rf2 = (int)valor('SELECT MAX(ronda) FROM duelo_combates WHERE categoria_id = ?', [$c2['id']]);
        duelo_jugar_azar($t, $c2, max(1, $rf2 - 1)); // la última ronda (final, tercer puesto o puestos del Monrad) queda por jugar
    } else {
        // resultados: la primera ronda completa y las semifinales de la llave; casi todo el todos contra todos
        foreach (duelo_combates((int)$c1['id']) as $c) if ((int)$c['ronda'] === 1 && $c['estado'] === 'pendiente') duelo_guardar_resultado($t, fila('SELECT * FROM duelo_combates WHERE id = ?', [$c['id']]), duelo_resultado_azar($t, $c1, $c));
        foreach (duelo_combates((int)$c1['id']) as $c) if ((int)$c['ronda'] === 2 && $c['estado'] === 'pendiente' && $c['a_id'] && $c['b_id']) duelo_guardar_resultado($t, fila('SELECT * FROM duelo_combates WHERE id = ?', [$c['id']]), duelo_resultado_azar($t, $c1, $c));
        $cl = duelo_combates((int)$c2['id']);
        foreach (array_slice($cl, 0, max(1, count($cl) - 2)) as $c) duelo_guardar_resultado($t, $c, duelo_resultado_azar($t, $c2, $c));
        // artes marciales y boxeo: la llave termina (para mostrar el medallero completo); raqueta: la final queda por jugar
        if (in_array($m, ['MARC', 'BOXEO'], true)) {
            foreach ([3, 4] as $r) foreach (duelo_combates((int)$c1['id']) as $c) if ((int)$c['ronda'] === $r && $c['estado'] === 'pendiente' && $c['a_id'] && $c['b_id']) duelo_guardar_resultado($t, fila('SELECT * FROM duelo_combates WHERE id = ?', [$c['id']]), duelo_resultado_azar($t, $c1, $c));
        }
    }
    // lo que quede por jugar, programado para el próximo sábado
    foreach (duelo_combates((int)$c1['id']) as $c) if ($c['estado'] === 'pendiente') q('UPDATE duelo_combates SET fecha = ?, hora = ?, lugar = ? WHERE id = ?', [date('Y-m-d', strtotime('next saturday')), '16:00', textos_duelo($t)['lugar'] . ' central', $c['id']]);
    if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
}
