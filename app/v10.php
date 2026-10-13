<?php
/*
 * VERSIÓN 10 — Funciones nuevas:
 *   1) Dirección propia de cada campeonato: tuacademia.tukanec.com/liga/campeonato (+ /tabla, /fixture, /en-vivo)
 *   2) Aviso de vencimiento 5 días antes (panel + Telegram) con el valor y los datos para pagar
 *   3) Liga de demostración que se reinicia sola cada noche
 *   4) Boletín oficial de la fecha (resultados, tabla, suspendidos, resoluciones, próxima fecha)
 *   5) Tabla acumulada (suma de varios campeonatos: Apertura + Clausura)
 *   6) Pedidos de los delegados: reprogramar un partido, descargo o apelación de una sanción
 *   7) Recuperar contraseña con código por Telegram o por correo (SMTP)
 *   8) "Seguir a mi equipo": notificaciones al celular (web push) de goles, resultados y horarios
 */

/* ============================================================
 * 1) DIRECCIÓN PROPIA DE CADA CAMPEONATO
 * ============================================================ */

/** Partes que pueden ir después del campeonato: /liga/campeonato/tabla → pestaña "posiciones". */
function subrutas_torneo(): array
{
    return [
        'tabla' => 'posiciones', 'posiciones' => 'posiciones', 'fixture' => 'fixture', 'resultados' => 'fixture',
        'en-vivo' => 'envivo', 'envivo' => 'envivo', 'goleadores' => 'goleadores', 'tarjetas' => 'tarjetas',
        'equipos' => 'equipos', 'llaves' => 'llaves', 'estadisticas' => 'estadisticas', 'boletin' => 'boletin', 'acumulada' => 'acumulada',
    ];
}

/** Nombre corto sugerido: "Campeonato Apertura" + "2026" → "campeonato-apertura-2026". */
function slug_torneo_base(string $nombre, string $temporada = ''): string
{
    $s = slugify($nombre);
    if ($s === 'liga') $s = 'campeonato';
    $temp = trim($temporada) !== '' ? slugify($temporada) : '';
    if ($temp !== '' && $temp !== 'liga' && !str_contains($s, $temp)) $s .= '-' . $temp;
    $s = trim(substr($s, 0, 60), '-');
    if (isset(subrutas_torneo()[$s]) || ctype_digit($s)) $s = 'campeonato-' . $s;
    return $s;
}

/** ¿Ese nombre corto está libre en la liga? */
function slug_torneo_libre(int $liga_id, string $slug, int $excluir = 0): bool
{
    return !fila("SELECT id FROM torneos WHERE liga_id = ? AND slug = ? AND id <> ?", [$liga_id, $slug, $excluir]);
}

/** Pone dirección a los campeonatos que aún no tienen (los recién creados). */
function completar_slugs_torneos(int $liga_id): void
{
    $faltan = filas("SELECT id, nombre, temporada FROM torneos WHERE liga_id = ? AND slug = '' ORDER BY id", [$liga_id]);
    foreach ($faltan as $t) {
        $base = slug_torneo_base($t['nombre'], $t['temporada']);
        $s = $base;
        $n = 2;
        while (!slug_torneo_libre($liga_id, $s, (int)$t['id'])) $s = $base . '-' . $n++;
        q('UPDATE torneos SET slug = ? WHERE id = ?', [$s, $t['id']]);
    }
}

/** Cambia la dirección de un campeonato (la escribe la liga). Vacío = automática. */
function guardar_slug_torneo(array $t, string $escrito): string
{
    $s = trim($escrito) === '' ? slug_torneo_base($t['nombre'], $t['temporada']) : slugify($escrito);
    $s = trim(substr($s, 0, 60), '-');
    if ($s === '' || isset(subrutas_torneo()[$s]) || ctype_digit($s)) throw new RuntimeException('Esa dirección no se puede usar. Escriba otra (ej: apertura-2026).');
    if (!slug_torneo_libre((int)$t['liga_id'], $s, (int)$t['id'])) throw new RuntimeException('Otro campeonato de la liga ya usa la dirección "' . $s . '". Escriba otra.');
    q('UPDATE torneos SET slug = ? WHERE id = ?', [$s, $t['id']]);
    return $s;
}

/** Dirección pública del campeonato: https://tuacademia.tukanec.com/liga/apertura-2026[/tabla]. */
function url_torneo(array $liga, array $t, string $sub = ''): string
{
    if (($t['slug'] ?? '') === '') {
        completar_slugs_torneos((int)$liga['id']);
        $t['slug'] = (string)valor('SELECT slug FROM torneos WHERE id = ?', [$t['id']]);
    }
    $u = ($liga['dominio'] ?? '') !== '' ? 'https://' . $liga['dominio'] . '/' . rawurlencode($t['slug']) : url_liga($liga) . '/' . rawurlencode($t['slug']);
    return $u . ($sub !== '' ? '/' . $sub : '');
}

/** Etiqueta <base> para que estilos y enlaces funcionen en direcciones con varias partes (/liga/campeonato/tabla). */
function etiqueta_base(): string
{
    return defined('BASE_RAIZ') ? '<base href="/">' : '';
}

/** Cuadro con la dirección del campeonato y botones para copiarla (panel). */
function html_direcciones_torneo(array $liga, array $t): string
{
    $partes = ['' => 'Campeonato', 'tabla' => 'Tabla', 'fixture' => 'Fixture', 'en-vivo' => 'En vivo'];
    $h = '<div class="direcciones-torneo">🔗 ';
    foreach ($partes as $sub => $txt) {
        $u = url_torneo($liga, $t, $sub);
        $h .= '<span class="dir-item"><a href="' . e($u) . '" target="_blank">' . e($sub === '' ? preg_replace('#^https?://#', '', $u) : '/' . $sub) . '</a>'
            . ' <button type="button" class="btn btn-sec btn-mini" onclick="navigator.clipboard && navigator.clipboard.writeText(\'' . e($u) . '\'); this.textContent=\'✓\'" title="Copiar ' . e($txt) . '">copiar</button></span>';
    }
    return $h . '</div>';
}

/* ============================================================
 * PRECIOS: "2 meses gratis" en el plan anual
 * ============================================================ */

/** Meses que se regalan al pagar el año (ej: $10/mes y $100/año → 2). */
function meses_gratis_plan(array $pl): int
{
    $m = (float)$pl['precio_mensual'];
    $a = (float)$pl['precio_anual'];
    if ($m <= 0 || $a <= 0) return 0;
    $g = (int)round(12 - $a / $m);
    return max(0, min(11, $g));
}

/** "o $100 al año (2 meses gratis)". */
function texto_precio_anual(array $pl): string
{
    if ((float)$pl['precio_anual'] <= 0) return '';
    $g = meses_gratis_plan($pl);
    return 'o ' . dinero($pl['precio_anual']) . ' al año' . ($g > 0 ? ' (' . $g . ' mes' . ($g === 1 ? '' : 'es') . ' gratis)' : '');
}

/* ============================================================
 * 2) AVISO DE VENCIMIENTO (5 días antes)
 * ============================================================ */

/** Plan con el que la liga renueva (el suyo, o el más barato si está en prueba sin plan). */
function plan_para_renovar(array $liga): ?array
{
    return plan_de_liga($liga) ?? fila("SELECT * FROM planes WHERE activo = 1 AND precio_mensual > 0 AND tipo_cuenta IN ('ambos', ?) ORDER BY precio_mensual, orden LIMIT 1", [($liga['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga']);
}

/** Texto con el valor a pagar: "$10 por mes o $100 por el año (2 meses gratis)". */
function texto_valor_renovacion(array $liga): string
{
    $pl = plan_para_renovar($liga);
    if (!$pl || !($o = opciones_precio($pl, $liga))) return ''; // (v16) con el descuento del combo si lo tiene
    // (v12) todos los períodos del plan: "$10 al mes · $50 por 6 meses (1 mes gratis) · $100 al año (2 meses gratis)"
    return 'Plan ' . $pl['nombre'] . (factor_combo($liga) < 1 ? ' (combo ' . (float)combo_descuento() . '%)' : '') . ': ' . implode(' · ', array_map(fn($x) => dinero($x['precio']) . ' ' . $x['texto'] . ($x['gratis'] ? ' (' . $x['gratis'] . ' mes' . ($x['gratis'] === 1 ? '' : 'es') . ' gratis)' : ''), $o));
}

/** ¿Hay que mostrar el aviso grande de vencimiento? (activa o en prueba, faltan 5 días o menos) */
function toca_aviso_vencimiento(array $liga): bool
{
    if (!empty($liga['es_demo'])) return false;
    $s = estado_suscripcion($liga);
    return in_array($s['estado'], ['activa', 'prueba'], true) && $s['dias'] !== null && $s['dias'] <= 5;
}

/** Aviso grande del panel con el valor, los datos para pagar y el botón de WhatsApp. */
function html_aviso_vencimiento(array $liga): string
{
    $s = estado_suscripcion($liga);
    $valor = texto_valor_renovacion($liga);
    $datos = trim((string)ajuste('ventas_datos_pago'));
    $wa = telefono_wa((string)ajuste('ventas_whatsapp'));
    $cuando = $s['dias'] === 0 ? 'vence HOY' : ($s['dias'] === 1 ? 'vence MAÑANA' : 'vence en ' . $s['dias'] . ' días');
    $h = '<div class="aviso-vence">'
        . '<div class="aviso-vence-tit">⏰ ' . ($s['estado'] === 'prueba' ? 'Su demo' : 'Su suscripción') . ' de ' . e(marca()) . ' ' . $cuando . ' (' . e($liga['vence']) . ')</div>'
        . '<p>Para que el panel siga funcionando sin interrupciones, renueve antes de esa fecha.</p>';
    if ($valor !== '') $h .= '<p class="aviso-vence-valor">💵 ' . e($valor) . '</p>';
    if (function_exists('en_app_play') && en_app_play()) { // (v38) en la app de Google Play solo se renueva con Google
        if (puede('config')) $h .= '<p style="margin:10px 0 0"><a class="btn" href="admin.php?p=mi_plan#play-caja">▶ Renovar con Google Play</a></p>';
        return $h . '</div>';
    }
    if ($datos !== '') $h .= '<div class="aviso-vence-datos"><b>Datos para pagar:</b><br>' . nl2br(e($datos)) . '</div>';
    if (pasarela_activa() && puede('config')) $h .= '<p style="margin:10px 0 0"><a class="btn" href="admin.php?p=mi_plan">💳 Renovar ahora con tarjeta</a></p>'; // (v12)
    elseif (puede('config')) $h .= '<p style="margin:10px 0 0"><a class="btn" href="admin.php?p=mi_plan#contratar">📤 ' . ($s['estado'] === 'prueba' ? 'Contratar mi plan' : 'Renovar') . ' y subir el comprobante</a></p>'; // (v33)
    if ($wa) {
        $msg = 'Hola, soy de la liga ' . $liga['nombre'] . '. Quiero renovar ' . marca() . '. Adjunto el comprobante de pago.';
        $h .= '<p style="margin:10px 0 0"><a class="btn btn-wa" target="_blank" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode($msg) . '">📲 Enviar el comprobante por WhatsApp</a></p>';
    }
    return $h . '</div>';
}

/** Texto para Telegram con el valor y los datos de pago. */
function texto_tg_renovacion(array $liga): string
{
    $t = '';
    if (($v = texto_valor_renovacion($liga)) !== '') $t .= "\n💵 " . e($v);
    if (($d = trim((string)ajuste('ventas_datos_pago'))) !== '') $t .= "\n\n<b>Datos para pagar:</b>\n" . e($d);
    return $t;
}

/* ============================================================
 * 3) LIGA DE DEMOSTRACIÓN
 * ============================================================ */

/** Ejecuta algo SIN anotarlo en la bitácora (la demo se borra y se crea cada noche). */
function sin_auditoria(callable $fn)
{
    $pdo = db();
    foreach ($pdo->query("SELECT name FROM sqlite_temp_master WHERE type = 'trigger' AND name LIKE 'aud\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_COLUMN) as $tr) {
        $pdo->exec('DROP TRIGGER IF EXISTS temp."' . str_replace('"', '', $tr) . '"');
    }
    auditoria_activa(true);
    try {
        return $fn();
    } finally {
        auditoria_activa(false);
    }
}

function liga_demo(): ?array
{
    return fila("SELECT * FROM ligas WHERE es_demo = 1 AND activa = 1 AND COALESCE(tipo, 'liga') = 'liga' AND COALESCE(es_federacion, 0) = 0 ORDER BY id DESC LIMIT 1");
}

/** ¿El usuario pertenece a la liga de demostración? */
function es_usuario_demo(?array $u): bool
{
    if (!$u || empty($u['liga_id'])) return false;
    return (int)valor('SELECT es_demo FROM ligas WHERE id = ?', [$u['liga_id']]) === 1;
}

/** Borra la demo anterior y crea una nueva con equipos, jugadores, resultados, sanciones y tabla acumulada. */
function crear_liga_demo(): int
{
    return sin_auditoria(function (): int {
        mt_srand(crc32(date('Y-m-d')));
        q("DELETE FROM ligas WHERE es_demo = 1 AND COALESCE(tipo, 'liga') = 'liga'");
        q("DELETE FROM usuarios WHERE email IN ('demo@demo.tuacademia.tukanec.com', 'arbitro@demo.tuacademia.tukanec.com', 'delegado@demo.tuacademia.tukanec.com')");
        $r = crear_liga_completa([
            'nombre' => 'Liga Demo ' . marca(), 'slug' => 'liga-demo', 'ciudad' => 'Quito', 'color' => '#0b6e4f', 'modalidad' => 'F7',
            'admin_nombre' => 'Visitante de la demo', 'admin_email' => 'demo@demo.tuacademia.tukanec.com', 'admin_clave' => bin2hex(random_bytes(12)),
        ]);
        $lid = (int)$r['liga_id'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q("UPDATE ligas SET es_demo = 1, vence = '', es_prueba = 0, color2 = '#f2b705', contacto_nombre = 'QF Producciones', polla_activa = 1 WHERE id = ?", [$lid]);
            q("UPDATE usuarios SET perfil = 'consulta' WHERE id = ?", [$r['usuario_id']]);
            q('INSERT INTO canchas (liga_id, nombre, direccion, activa, orden) VALUES (?,?,?,1,1), (?,?,?,1,2)', [$lid, 'Cancha 1', 'Parque central', $lid, 'Cancha 2', 'Parque central']);
            $arbitros = [];
            foreach (['Carlos Andrade', 'Luis Proaño', 'Marco Villacís'] as $a) {
                q("INSERT INTO arbitros (liga_id, nombre, categoria, activo) VALUES (?, ?, 'Primera', 1)", [$lid, $a]);
                $arbitros[] = (int)$pdo->lastInsertId();
            }
            $equipos = [
                ['Deportivo Los Andes', 'DLA', '#c62828'], ['Atlético Chimborazo', 'ATC', '#1565c0'], ['Unión Pichincha', 'UPI', '#2e7d32'],
                ['Real Cotopaxi', 'RCO', '#6a1b9a'], ['Juventud Tungurahua', 'JTU', '#ef6c00'], ['Sporting Imbabura', 'SIM', '#00838f'],
                ['Estrella del Sur', 'EDS', '#f9a825'], ['Club 10 de Agosto', 'C10', '#37474f'],
            ];
            $nombres = ['Juan', 'Carlos', 'Luis', 'Andrés', 'Diego', 'Jorge', 'Miguel', 'Pedro', 'Santiago', 'Mateo', 'David', 'Kevin', 'Bryan', 'Jonathan', 'Christian', 'Fernando', 'Pablo', 'Ricardo', 'Édison', 'Wilson', 'Byron', 'Darío', 'Iván', 'Marlon'];
            $apellidos = ['Pérez', 'Guamán', 'Quishpe', 'Morales', 'Chiriboga', 'Vera', 'Mendoza', 'Zambrano', 'Cedeño', 'Caicedo', 'Tenorio', 'Arboleda', 'Paredes', 'Vaca', 'Salazar', 'Cevallos', 'Ortiz', 'Montaño', 'Estrada', 'Valencia', 'Tapia', 'Herrera', 'Castillo', 'Ruiz'];
            $pos = ['Arquero', 'Defensa', 'Defensa', 'Defensa', 'Mediocampista', 'Mediocampista', 'Mediocampista', 'Delantero', 'Delantero', 'Defensa', 'Mediocampista', 'Delantero'];
            $eids = [];
            $plantel = [];
            foreach ($equipos as $i => [$nom, $abr, $col]) {
                q('INSERT INTO equipos (liga_id, nombre, abreviatura, color, delegado, telefono) VALUES (?,?,?,?,?,?)', [$lid, $nom, $abr, $col, $nombres[($i * 5) % 24] . ' ' . $apellidos[($i * 7) % 24], '099' . str_pad((string)mt_rand(1000000, 9999999), 7, '0')]);
                $eid = (int)$pdo->lastInsertId();
                $eids[] = $eid;
                for ($k = 0; $k < 12; $k++) {
                    q("INSERT INTO jugadores (equipo_id, nombres, apellidos, numero, posicion, fecha_nac, consentimiento, consentimiento_por, foto_publica) VALUES (?,?,?,?,?,?,?, 'Demostración', 1)", [
                        $eid, $nombres[mt_rand(0, 23)], $apellidos[mt_rand(0, 23)] . ' ' . $apellidos[mt_rand(0, 23)], $k + 1, $pos[$k],
                        sprintf('%04d-%02d-%02d', mt_rand(1985, 2004), mt_rand(1, 12), mt_rand(1, 28)), date('Y-m-d H:i:s'),
                    ]);
                    $plantel[$eid][] = ['id' => (int)$pdo->lastInsertId(), 'pos' => $pos[$k]];
                }
            }
            $reglas = presets_modalidad()['F7'];
            $anio = date('Y');
            $crear_torneo = function (string $nombre, string $estado) use ($lid, $reglas, $anio, $eids, $pdo): int {
                $t = array_merge(['liga_id' => $lid, 'nombre' => $nombre, 'temporada' => $anio, 'estado' => $estado], $reglas, ['modalidad' => 'F7']);
                q('INSERT INTO torneos (' . implode(', ', array_keys($t)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($t))) . ')', $t);
                $tid = (int)$pdo->lastInsertId();
                foreach ($eids as $k => $e) q('INSERT INTO torneo_equipos (torneo_id, equipo_id, orden_sorteo) VALUES (?,?,?)', [$tid, $e, $k + 1]);
                q('UPDATE torneos SET fecha_sorteo = ? WHERE id = ?', [date('Y-m-d H:i:s'), $tid]);
                return $tid;
            };
            // Partidos: todos contra todos; cada jornada un domingo
            $horas = ['09:00', '10:30', '12:00', '13:30'];
            $crear_fixture = function (int $tid, string $primer_domingo, int $jugar_hasta) use ($eids, $horas, $arbitros, $plantel, $pdo): void {
                $mezcla = $eids;
                shuffle($mezcla);
                foreach (todos_contra_todos($mezcla, 1) as $k => $partidos) {
                    $jornada = $k + 1;
                    $fecha = date('Y-m-d', strtotime($primer_domingo . ' +' . ($k * 7) . ' days'));
                    foreach (array_values($partidos) as $n => [$loc, $vis]) {
                        $cancha = $n % 2 === 0 ? 'Cancha 1' : 'Cancha 2';
                        $hora = $horas[$n % 4];
                        q("INSERT INTO partidos (torneo_id, fase, jornada, local_id, visitante_id, fecha, hora, cancha, arbitro_id) VALUES (?, 'regular', ?, ?, ?, ?, ?, ?, ?)",
                            [$tid, $jornada, $loc, $vis, $fecha, $hora, $cancha, $arbitros[$n % count($arbitros)]]);
                        $pid = (int)$pdo->lastInsertId();
                        if ($jornada > $jugar_hasta) continue;
                        // Resultado al azar con goles, asistencias y tarjetas coherentes
                        $gl = [0, 0, 1, 1, 1, 2, 2, 2, 3, 3, 4, 5][mt_rand(0, 11)];
                        $gv = [0, 0, 1, 1, 1, 2, 2, 3, 3, 4][mt_rand(0, 9)];
                        foreach ([[$loc, $gl], [$vis, $gv]] as [$eq, $goles]) {
                            $pl = $plantel[$eq];
                            $atacantes = array_values(array_filter($pl, fn($x) => $x['pos'] !== 'Arquero'));
                            $del = array_values(array_filter($pl, fn($x) => in_array($x['pos'], ['Delantero', 'Mediocampista'], true)));
                            for ($g = 0; $g < $goles; $g++) {
                                $autor = mt_rand(1, 10) <= 7 ? $del[mt_rand(0, count($del) - 1)] : $atacantes[mt_rand(0, count($atacantes) - 1)];
                                $min = mt_rand(1, 50);
                                q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, origen) VALUES (?,?,?,?,?, 'vocal')", [$pid, $autor['id'], $eq, mt_rand(1, 12) === 1 ? 'penal' : 'gol', $min]);
                                if (mt_rand(1, 3) > 1) {
                                    $asis = $atacantes[mt_rand(0, count($atacantes) - 1)];
                                    if ($asis['id'] !== $autor['id']) q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, origen) VALUES (?,?,?, 'asistencia', ?, 'vocal')", [$pid, $asis['id'], $eq, $min]);
                                }
                            }
                            for ($a = mt_rand(0, 3); $a > 0; $a--) {
                                q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, origen) VALUES (?,?,?, 'amarilla', ?, 'vocal')", [$pid, $pl[mt_rand(1, 11)]['id'], $eq, mt_rand(1, 50)]);
                            }
                            if (mt_rand(1, 14) === 1) q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, origen) VALUES (?,?,?, 'roja', ?, 'vocal')", [$pid, $pl[mt_rand(1, 11)]['id'], $eq, mt_rand(20, 50)]);
                        }
                        $fin = date('H:i', strtotime($hora) + 60 * 60);
                        q("UPDATE partidos SET estado = 'jugado', goles_local = ?, goles_visitante = ?, hora_inicio = ?, hora_fin = ?, vocal_cerrada = 1, vocal_cerrada_en = ?, revisado = 1 WHERE id = ?",
                            [$gl, $gv, $hora, $fin, $fecha . ' ' . $fin . ':00', $pid]);
                    }
                }
            };
            $domingo = date('Y-m-d', strtotime('sunday this week'));
            if ($domingo < date('Y-m-d')) $domingo = date('Y-m-d', strtotime('next sunday'));
            // Apertura: terminado, con campeón
            $ta = $crear_torneo('Apertura', 'finalizado');
            $crear_fixture($ta, date('Y-m-d', strtotime($domingo . ' -' . (7 * 13) . ' days')), 99);
            [$camp, $sub] = campeon_sugerido($ta);
            q('UPDATE torneos SET campeon_id = ?, subcampeon_id = ? WHERE id = ?', [$camp, $sub, $ta]);
            // Clausura: en juego (4 fechas jugadas, la 5ª este domingo)
            $tc = $crear_torneo('Clausura', 'en_juego');
            $crear_fixture($tc, date('Y-m-d', strtotime($domingo . ' -28 days')), 4);
            // Resoluciones de la comisión disciplinaria
            $jug_s = fila("SELECT ev.jugador_id FROM eventos ev JOIN partidos p ON p.id = ev.partido_id WHERE p.torneo_id = ? AND p.jornada = 4 AND ev.tipo = 'amarilla' LIMIT 1", [$tc]);
            $f4 = (string)valor('SELECT fecha FROM partidos WHERE torneo_id = ? AND jornada = 4 LIMIT 1', [$tc]);
            if ($jug_s) q('INSERT INTO sanciones (torneo_id, jugador_id, partidos, motivo, desde, multa, creado) VALUES (?,?,2,?,?,10,?)', [$tc, $jug_s['jugador_id'], 'Reclamo airado al árbitro después del partido (informe arbitral)', $f4, $f4 . ' 20:00:00']);
            q('INSERT INTO sanciones_equipo (torneo_id, equipo_id, puntos, multa, motivo, fecha, creado) VALUES (?,?,0,20,?,?,?)', [$tc, $eids[3], 'Indisciplina de la barra (lanzamiento de objetos)', $f4, $f4 . ' 20:00:00']);
            $fig = fila("SELECT ev.jugador_id, COUNT(*) AS n FROM eventos ev JOIN partidos p ON p.id = ev.partido_id WHERE p.torneo_id = ? AND p.jornada = 4 AND ev.tipo IN ('gol','penal') GROUP BY ev.jugador_id ORDER BY n DESC LIMIT 1", [$tc]);
            if ($fig) q('INSERT OR IGNORE INTO figuras (torneo_id, jornada, jugador_id, motivo) VALUES (?, 4, ?, ?)', [$tc, $fig['jugador_id'], 'Marcó ' . (int)$fig['n'] . ' gol(es)']);
            // Tabla acumulada del año
            q('INSERT INTO tablas_acumuladas (liga_id, nombre, torneos, publica) VALUES (?,?,?,1)', [$lid, 'Tabla acumulada ' . $anio, $ta . ',' . $tc]);
            // Pedidos de delegados (uno pendiente y uno resuelto)
            $prox = fila("SELECT * FROM partidos WHERE torneo_id = ? AND jornada = 5 ORDER BY id LIMIT 1", [$tc]);
            if ($prox) q("INSERT INTO pedidos_delegado (liga_id, equipo_id, tipo, partido_id, fecha_propuesta, hora_propuesta, motivo, creado) VALUES (?,?, 'reprogramacion', ?, ?, ?, ?, ?)",
                [$lid, $prox['local_id'], $prox['id'], date('Y-m-d', strtotime($prox['fecha'] . ' +7 days')), '10:30', 'Tres jugadores viajan por trabajo ese fin de semana. Pedimos jugar el siguiente domingo.', date('Y-m-d H:i:s')]);
            $sq = fila('SELECT s.id, j.equipo_id FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id WHERE s.torneo_id = ? LIMIT 1', [$tc]);
            if ($sq) q("INSERT INTO pedidos_delegado (liga_id, equipo_id, tipo, sancion_id, motivo, estado, respuesta, resuelto_por, resuelto_en, creado) VALUES (?,?, 'apelacion', ?, ?, 'rechazado', ?, 'Comisión disciplinaria', ?, ?)",
                [$lid, $sq['equipo_id'], $sq['id'], 'El jugador no insultó al árbitro; pedimos revisar el video.', 'Se revisó el informe arbitral y el video: la sanción se mantiene.', $f4 . ' 21:00:00', $f4 . ' 20:30:00']);
            completar_slugs_torneos($lid);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        guardar_ajuste('demo_creada', date('Y-m-d H:i'));
        return $lid;
    });
}

/* ============================================================
 * 4) BOLETÍN OFICIAL DE LA FECHA
 * ============================================================ */

/** Fechas (bloques) del campeonato: jornadas de la fase regular y fases finales. */
function bloques_boletin(int $tid): array
{
    $b = [];
    foreach (filas("SELECT fase, jornada, MIN(NULLIF(fecha,'')) AS desde, MAX(NULLIF(fecha,'')) AS hasta, COUNT(*) AS n, SUM(estado IN ('jugado','wo')) AS jugados
                    FROM partidos WHERE torneo_id = ? GROUP BY fase, CASE WHEN fase = 'regular' THEN jornada ELSE 0 END
                    ORDER BY CASE fase WHEN 'regular' THEN 0 WHEN 'octavos' THEN 1 WHEN 'cuartos' THEN 2 WHEN 'semifinal' THEN 3 WHEN 'tercer' THEN 4 WHEN 'final' THEN 5 ELSE 6 END, jornada", [$tid]) as $x) {
        $clave = $x['fase'] === 'regular' ? 'j' . (int)$x['jornada'] : 'f-' . $x['fase'];
        $b[$clave] = ['clave' => $clave, 'fase' => $x['fase'], 'jornada' => (int)$x['jornada'], 'nombre' => $x['fase'] === 'regular' ? 'Fecha ' . (int)$x['jornada'] : nombre_fase($x['fase']),
            'desde' => (string)$x['desde'], 'hasta' => (string)$x['hasta'], 'n' => (int)$x['n'], 'jugados' => (int)$x['jugados']];
    }
    return $b;
}

/** La fecha que corresponde mostrar: la pedida o la última con partidos jugados. */
function bloque_actual(array $bloques, string $pedido = ''): ?array
{
    if ($pedido !== '' && isset($bloques[$pedido])) return $bloques[$pedido];
    $ult = null;
    foreach ($bloques as $b) if ($b['jugados'] > 0) $ult = $b;
    return $ult ?? (reset($bloques) ?: null);
}

function partidos_de_bloque_boletin(int $tid, array $b): array
{
    return $b['fase'] === 'regular'
        ? partidos_torneo($tid, "AND p.fase = 'regular' AND p.jornada = " . (int)$b['jornada'])
        : partidos_torneo($tid, 'AND p.fase = ' . db()->quote($b['fase']));
}

/** Documento imprimible (y para compartir) con todo lo oficial de una fecha. */
function doc_boletin(array $t, array $liga, string $pedido, string $volver, bool $publico = false): void
{
    $tid = (int)$t['id'];
    $T = textos_deporte($t);
    $bloques = bloques_boletin($tid);
    $b = bloque_actual($bloques, $pedido);
    $claves = array_keys($bloques);
    $sig = null;
    if ($b) {
        $i = array_search($b['clave'], $claves, true);
        $sig = $bloques[$claves[$i + 1] ?? ''] ?? null;
    }
    $partidos = $b ? partidos_de_bloque_boletin($tid, $b) : [];
    $proximos = $sig ? partidos_de_bloque_boletin($tid, $sig) : [];
    // Período de las resoluciones: desde el día de esta fecha hasta el día antes de la siguiente (o 7 días)
    $desde = $b && $b['desde'] !== '' ? $b['desde'] : date('Y-m-d', strtotime('-7 days'));
    $hasta = $sig && $sig['desde'] !== '' && $sig['desde'] > $desde ? date('Y-m-d', strtotime($sig['desde'] . ' -1 day')) : date('Y-m-d', strtotime(max($desde, $b['hasta'] ?? $desde) . ' +6 days'));
    $sanc = filas("SELECT s.*, j.nombres, j.apellidos, e.nombre AS equipo FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id JOIN equipos e ON e.id = j.equipo_id
                   WHERE s.torneo_id = ? AND substr(s.creado, 1, 10) BETWEEN ? AND ? ORDER BY s.id", [$tid, $desde, $hasta]);
    $sanc_eq = filas('SELECT s.*, e.nombre AS equipo FROM sanciones_equipo s JOIN equipos e ON e.id = s.equipo_id WHERE s.torneo_id = ? AND substr(s.creado, 1, 10) BETWEEN ? AND ? ORDER BY s.id', [$tid, $desde, $hasta]);
    $pedidos = filas("SELECT pd.*, e.nombre AS equipo FROM pedidos_delegado pd JOIN equipos e ON e.id = pd.equipo_id
                      WHERE pd.liga_id = ? AND pd.tipo IN ('descargo','apelacion') AND pd.estado IN ('aprobado','rechazado') AND substr(pd.resuelto_en, 1, 10) BETWEEN ? AND ?
                        AND (pd.sancion_id IN (SELECT id FROM sanciones WHERE torneo_id = ?) OR pd.sancion_equipo_id IN (SELECT id FROM sanciones_equipo WHERE torneo_id = ?)
                             OR pd.partido_id IN (SELECT id FROM partidos WHERE torneo_id = ?) OR (pd.sancion_id IS NULL AND pd.sancion_equipo_id IS NULL AND pd.partido_id IS NULL))
                      ORDER BY pd.id", [$liga['id'], $desde, $hasta, $tid, $tid, $tid]);
    $repro = filas("SELECT pd.*, e.nombre AS equipo FROM pedidos_delegado pd JOIN equipos e ON e.id = pd.equipo_id WHERE pd.liga_id = ? AND pd.tipo = 'reprogramacion' AND pd.estado = 'aprobado'
                    AND pd.partido_id IN (SELECT id FROM partidos WHERE torneo_id = ?) AND substr(pd.resuelto_en, 1, 10) BETWEEN ? AND ?", [$liga['id'], $tid, $desde, $hasta]);
    $susp = suspensiones($tid);
    $tabla = tabla_posiciones($tid);
    $gol = $T['con_goleadores'] ? goleadores($tid, 5) : [];
    $figura = $b && $b['fase'] === 'regular' ? fila('SELECT f.*, j.nombres, j.apellidos, e.nombre AS equipo FROM figuras f JOIN jugadores j ON j.id = f.jugador_id JOIN equipos e ON e.id = j.equipo_id WHERE f.torneo_id = ? AND f.jornada = ?', [$tid, $b['jornada']]) : null;
    $num = $b ? (int)array_search($b['clave'], $claves, true) + 1 : 0;
    $titulo = 'Boletín oficial · ' . $t['nombre'] . ($b ? ' · ' . $b['nombre'] : '');
    $url_pub = url_torneo($liga, $t, 'boletin') . ($b ? '?f=' . $b['clave'] : '');
    doc_inicio($titulo, $volver);
    ?>
  <div class="no-imprimir" style="margin:-4px 0 12px">
    <?php if (count($bloques) > 1): ?>
      <form method="get" class="inline" style="display:inline">
        <?php foreach ($publico ? ['l' => $liga['slug'], 't' => $tid, 'v' => 'boletin'] : ['p' => 'imprimir', 'doc' => 'boletin', 'id' => $tid] as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
        <label>Fecha: <select name="f" onchange="this.form.submit()"><?php foreach ($bloques as $x): ?><option value="<?= e($x['clave']) ?>" <?= $b && $x['clave'] === $b['clave'] ? 'selected' : '' ?>><?= e($x['nombre']) ?><?= $x['desde'] !== '' ? ' (' . e(fecha_bonita($x['desde'])) . ')' : '' ?></option><?php endforeach; ?></select></label>
      </form>
    <?php endif; ?>
    <a class="btn btn-wa btn-chico" target="_blank" href="https://wa.me/?text=<?= rawurlencode('📰 ' . $titulo . "\n" . $url_pub) ?>">📲 Compartir por WhatsApp</a>
  </div>
  <?php doc_cabecera_financiera($liga, 'BOLETÍN OFICIAL' . ($num ? ' N° ' . $num : '')); ?>
  <h2 style="margin-top:10px"><?= e($t['nombre']) ?> <?= e($t['temporada']) ?> · <?= $b ? e($b['nombre']) : 'Sin partidos' ?>
    <?php if ($b && $b['desde'] !== ''): ?><small>(<?= e(fecha_bonita($b['desde'])) ?><?= $b['hasta'] !== $b['desde'] ? ' al ' . e(fecha_bonita($b['hasta'])) : '' ?>)</small><?php endif; ?></h2>

  <h2>1. Resultados</h2>
  <?php if (!$partidos): ?><p>No hay partidos en esta fecha.</p><?php else: ?>
  <table class="pl"><tr><th>Partido</th><th>Resultado</th><th>Cancha / hora</th></tr>
    <?php foreach ($partidos as $p): ?><tr><td><?= e($p['local_nombre'] . ' vs ' . $p['visitante_nombre']) ?><?= $p['grupo'] !== '' ? ' <small>(Grupo ' . e($p['grupo']) . ')</small>' : '' ?></td>
      <td><b><?= partido_cerrado($p) ? (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . ($p['estado'] === 'wo' ? ' (W.O.)' : '') . ($p['penales_local'] !== null ? ' (pen ' . (int)$p['penales_local'] . '-' . (int)$p['penales_visitante'] . ')' : '') : e(nombre_estado_partido($p['estado'])) ?></b></td>
      <td><?= e(trim($p['cancha'] . ' ' . $p['hora'])) ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if ($figura): ?><p>⭐ <b>Figura de la fecha:</b> <?= e(nombre_jugador($figura)) ?> (<?= e($figura['equipo']) ?>)<?= $figura['motivo'] !== '' ? ' — ' . e($figura['motivo']) : '' ?></p><?php endif; ?>

  <h2>2. Tabla de posiciones <small>(al <?= date('d/m/Y') ?>)</small></h2>
  <?php foreach ($tabla as $g => $filas_t): ?>
    <?php if ($g !== ''): ?><h3>Grupo <?= e($g) ?></h3><?php endif; ?>
    <table class="pl"><tr><th>#</th><th>Equipo</th><th>PJ</th><th>G</th><?php if ($T['con_empate']): ?><th>E</th><?php endif; ?><th>P</th><th><?= e($T['gf']) ?></th><th><?= e($T['gc']) ?></th><th><?= e($T['dg']) ?></th><th>Pts</th></tr>
      <?php foreach ($filas_t as $i => $f): ?><tr><td><?= $i + 1 ?></td><td><?= e($f['nombre']) ?><?= $f['sancion'] ? ' <small>(−' . (int)$f['sancion'] . ' pts por sanción)</small>' : '' ?></td><td><?= $f['pj'] ?></td><td><?= $f['pg'] ?></td><?php if ($T['con_empate']): ?><td><?= $f['pe'] ?></td><?php endif; ?><td><?= $f['pp'] ?></td><td><?= $f['gf'] ?></td><td><?= $f['gc'] ?></td><td><?= $f['dg'] ?></td><td><b><?= $f['pts'] ?></b></td></tr><?php endforeach; ?>
    </table>
  <?php endforeach; ?>
  <?php if ($gol): ?><p><b><?= e($T['goleadores']) ?>:</b> <?= e(implode(' · ', array_map(fn($g) => nombre_jugador($g) . ' (' . $g['equipo'] . ') ' . (int)$g['goles'], $gol))) ?></p><?php endif; ?>

  <h2>3. Jugadores suspendidos para la próxima fecha</h2>
  <?php if (!$susp): ?><p>No hay jugadores suspendidos.</p><?php else: ?>
  <table class="pl"><tr><th>Jugador</th><th>Equipo</th><th>Partidos que debe cumplir</th><th>Motivo</th></tr>
    <?php foreach ($susp as $x): ?><tr><td><?= e(nombre_jugador($x['jugador'])) ?></td><td><?= e($x['jugador']['equipo'] ?? '') ?></td><td><?= (int)$x['pendientes'] ?></td><td style="white-space:normal;min-width:220px"><?= html_motivos_suspension($x) ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>

  <h2>4. Resoluciones de la comisión <small>(<?= e(fecha_bonita($desde)) ?> al <?= e(fecha_bonita($hasta)) ?>)</small></h2>
  <?php if (!$sanc && !$sanc_eq && !$pedidos && !$repro): ?><p>No hubo resoluciones en este período.</p><?php else: ?>
  <table class="pl"><tr><th>Resolución</th><th>Detalle</th></tr>
    <?php foreach ($sanc as $x): ?><tr><td>Sanción a <?= e(nombre_jugador($x)) ?> (<?= e($x['equipo']) ?>)</td><td><?= (int)$x['partidos'] ?> partido(s)<?= (float)$x['multa'] > 0 ? ' · multa ' . dinero($x['multa']) : '' ?><?= $x['motivo'] !== '' ? ' · ' . e($x['motivo']) : '' ?></td></tr><?php endforeach; ?>
    <?php foreach ($sanc_eq as $x): ?><tr><td>Sanción al equipo <?= e($x['equipo']) ?></td><td><?= (int)$x['puntos'] ? '−' . (int)$x['puntos'] . ' puntos · ' : '' ?><?= (float)$x['multa'] > 0 ? 'multa ' . dinero($x['multa']) . ' · ' : '' ?><?= e($x['motivo']) ?></td></tr><?php endforeach; ?>
    <?php foreach ($pedidos as $x): ?><tr><td><?= e(nombre_tipo_pedido($x['tipo'])) ?> de <?= e($x['equipo']) ?>: <b><?= $x['estado'] === 'aprobado' ? 'ACEPTADA' : 'NEGADA' ?></b></td><td><?= e($x['respuesta']) ?></td></tr><?php endforeach; ?>
    <?php foreach ($repro as $x): $pp = fila('SELECT p.*, el.nombre AS l, ev.nombre AS v FROM partidos p JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id WHERE p.id = ?', [$x['partido_id']]); ?>
      <tr><td>Reprogramación pedida por <?= e($x['equipo']) ?>: <b>ACEPTADA</b></td><td><?= $pp ? e($pp['l'] . ' vs ' . $pp['v']) . ' → ' . e(trim(fecha_bonita($pp['fecha']) . ' ' . $pp['hora'])) : '' ?><?= $x['respuesta'] !== '' ? ' · ' . e($x['respuesta']) : '' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <h2>5. Próxima fecha<?= $sig ? ': ' . e($sig['nombre']) : '' ?></h2>
  <?php if (!$proximos): ?><p>Aún no está programada.</p><?php else: ?>
  <table class="pl"><tr><th>Día</th><th>Hora</th><th>Cancha</th><th>Partido</th><th>Árbitro</th></tr>
    <?php foreach ($proximos as $p): ?><tr><td><?= e($p['fecha'] !== '' ? fecha_bonita($p['fecha']) : 'Por definir') ?></td><td><?= e($p['hora'] ?: '—') ?></td><td><?= e($p['cancha'] ?: '—') ?></td><td><?= e($p['local_nombre'] . ' vs ' . $p['visitante_nombre']) ?></td><td><?= e(($p['arbitro_id'] ? (string)valor('SELECT nombre FROM arbitros WHERE id = ?', [$p['arbitro_id']]) : '') ?: (nombre_arbitro($p) ?: '—')) ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
  <div class="firmas" style="grid-template-columns:repeat(3,1fr)"><div>Presidente</div><div>Secretario</div><div>Comisión disciplinaria</div></div>
  <p style="font-size:11px;color:#555">Boletín generado el <?= date('d/m/Y H:i') ?> con <?= e(marca()) ?> · <?= e($url_pub) ?></p>
    <?php
    doc_fin();
}

/* ============================================================
 * 5) TABLA ACUMULADA
 * ============================================================ */

/** Ids de los campeonatos de una tabla acumulada (solo los de la liga). */
function torneos_acumulada(array $a): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$a['torneos'])))));
    if (!$ids) return [];
    $ok = filas('SELECT id, nombre, temporada FROM torneos WHERE liga_id = ? AND ' . sql_sin_carreras() . ' AND id IN (' . implode(',', $ids) . ') ORDER BY id', [$a['liga_id']]);
    return $ok;
}

/** Suma las tablas (fase regular) de varios campeonatos: puntos, partidos, goles, sanciones. */
function tabla_acumulada(array $a): array
{
    $suma = [];
    foreach (torneos_acumulada($a) as $t) {
        foreach (tabla_posiciones((int)$t['id']) as $grupo) {
            foreach ($grupo as $f) {
                $id = $f['id'];
                if (!isset($suma[$id])) $suma[$id] = ['id' => $id, 'nombre' => $f['nombre'], 'escudo' => $f['escudo'], 'color' => $f['color'], 'grupo' => '',
                    'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'dg' => 0, 'pts' => 0, 'pf' => 0, 'pc' => 0, 'sancion' => 0, 'ultimos' => [], 'campeonatos' => 0];
                foreach (['pj', 'pg', 'pe', 'pp', 'gf', 'gc', 'pts', 'pf', 'pc', 'sancion'] as $k) $suma[$id][$k] += (int)$f[$k];
                $suma[$id]['campeonatos']++;
                $suma[$id]['ultimos'] = array_slice(array_merge($suma[$id]['ultimos'], $f['ultimos']), -5);
            }
        }
    }
    foreach ($suma as &$f) $f['dg'] = $f['gf'] - $f['gc'];
    unset($f);
    $filas_t = array_values($suma);
    usort($filas_t, fn($x, $y) => [$y['pts'], $y['dg'], $y['gf'], $x['gc'], $x['nombre']] <=> [$x['pts'], $x['dg'], $x['gf'], $y['gc'], $y['nombre']]);
    return $filas_t;
}

/* ============================================================
 * 6) PEDIDOS DE LOS DELEGADOS
 * ============================================================ */

function tipos_pedido(): array
{
    return ['reprogramacion' => 'Reprogramar partido', 'descargo' => 'Descargo', 'apelacion' => 'Apelación de sanción'];
}

function nombre_tipo_pedido(string $t): string
{
    return tipos_pedido()[$t] ?? $t;
}

function nombre_estado_pedido(string $e): string
{
    return ['pendiente' => 'En revisión', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'cancelado' => 'Cancelado'][$e] ?? $e;
}

function chip_estado_pedido(string $e): string
{
    return '<span class="estado-chip ' . (['pendiente' => 'chip-pend', 'aprobado' => 'chip-ok', 'rechazado' => 'chip-mal'][$e] ?? '') . '">' . e(nombre_estado_pedido($e)) . '</span>';
}

function pedidos_pendientes(int $liga_id): int
{
    return (int)valor("SELECT COUNT(*) FROM pedidos_delegado WHERE liga_id = ? AND estado = 'pendiente'", [$liga_id]);
}

/** Texto de "sobre qué" es el pedido (partido o sanción). */
function detalle_pedido(array $pd): string
{
    if ($pd['partido_id']) {
        $p = partido_completo((int)$pd['partido_id']);
        if ($p) return ($p['fase'] === 'regular' ? 'Fecha ' . (int)$p['jornada'] : nombre_fase($p['fase'])) . ': ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre']
            . ($p['fecha'] !== '' ? ' · ' . fecha_bonita($p['fecha']) . ($p['hora'] !== '' ? ' ' . $p['hora'] : '') : '') . (partido_cerrado($p) ? ' (' . (int)$p['goles_local'] . '-' . (int)$p['goles_visitante'] . ')' : '');
    }
    if ($pd['sancion_id']) {
        $s = fila('SELECT s.*, j.nombres, j.apellidos FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id WHERE s.id = ?', [$pd['sancion_id']]);
        if ($s) return 'Sanción a ' . nombre_jugador($s) . ': ' . (int)$s['partidos'] . ' partido(s)' . ((float)$s['multa'] > 0 ? ', multa ' . dinero($s['multa']) : '') . ($s['motivo'] !== '' ? ' — ' . $s['motivo'] : '');
    }
    if ($pd['sancion_equipo_id']) {
        $s = fila('SELECT * FROM sanciones_equipo WHERE id = ?', [$pd['sancion_equipo_id']]);
        if ($s) return 'Sanción al equipo: ' . ((int)$s['puntos'] ? '−' . (int)$s['puntos'] . ' puntos, ' : '') . ((float)$s['multa'] > 0 ? 'multa ' . dinero($s['multa']) . ', ' : '') . $s['motivo'];
    }
    return 'General';
}

/* ============================================================
 * 7) RECUPERAR CONTRASEÑA (Telegram o correo)
 * ============================================================ */

function smtp_configurado(): bool
{
    return trim((string)ajuste('smtp_host')) !== '' && trim((string)ajuste('smtp_remitente')) !== '';
}

/** Envía un correo de texto por SMTP (sin librerías). Lanza RuntimeException si falla. */
function smtp_enviar(string $para, string $asunto, string $texto, string $html = ''): void // (v33) $html opcional: se envía texto + HTML
{
    $host = trim((string)ajuste('smtp_host'));
    $puerto = (int)ajuste('smtp_puerto', 587) ?: 587;
    $seg = (string)ajuste('smtp_seguridad', 'tls');
    $usuario = (string)ajuste('smtp_usuario');
    $clave = (string)ajuste('smtp_clave');
    $de = trim((string)ajuste('smtp_remitente'));
    if ($host === '' || $de === '') throw new RuntimeException('El correo saliente (SMTP) no está configurado.');
    if (!filter_var($para, FILTER_VALIDATE_EMAIL) || !filter_var($de, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Correo no válido.');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($seg === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $puerto, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException('No se pudo conectar al servidor de correo ' . $host . ':' . $puerto . ' (' . $errstr . ').');
    stream_set_timeout($fp, 20);
    $leer = function () use ($fp): array {
        $txt = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $txt .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return [(int)substr($txt, 0, 3), trim($txt)];
    };
    $cmd = function (string $c, array $esperado) use ($fp, $leer): string {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        [$cod, $txt] = $leer();
        if (!in_array($cod, $esperado, true)) throw new RuntimeException('El servidor de correo respondió: ' . mb_substr($txt, 0, 200));
        return $txt;
    };
    try {
        $cmd('', [220]);
        $yo = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $cmd('EHLO ' . $yo, [250]);
        if ($seg === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('No se pudo activar la conexión segura (TLS) con el servidor de correo.');
            $cmd('EHLO ' . $yo, [250]);
        }
        if ($usuario !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($usuario), [334]);
            $cmd(base64_encode($clave), [235]);
        }
        $cmd('MAIL FROM:<' . $de . '>', [250]);
        $cmd('RCPT TO:<' . $para . '>', [250, 251]);
        $cmd('DATA', [354]);
        $cab = 'From: =?UTF-8?B?' . base64_encode(marca()) . '?= <' . $de . ">\r\n"
            . 'To: <' . $para . ">\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($asunto) . "?=\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . substr(strrchr($de, '@'), 1) . ">\r\n"
            . "MIME-Version: 1.0\r\n";
        $b64 = fn(string $s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
        if ($html === '') {
            $cuerpo = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($texto);
        } else { // (v33) texto + HTML
            $lim = 'tv' . bin2hex(random_bytes(8));
            $cuerpo = "Content-Type: multipart/alternative; boundary=\"$lim\"\r\n\r\n--$lim\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($texto)
                . "\r\n--$lim\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n--$lim--";
        }
        fwrite($fp, $cab . $cuerpo . "\r\n.\r\n");
        $cmd('', [250]);
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/**
 * Crea un código de 6 números y lo envía por Telegram (si el usuario lo conectó) o por correo.
 * Devuelve el canal usado ('telegram', 'correo') o '' si no se pudo enviar.
 */
function recuperar_enviar_codigo(array $u): string
{
    $codigo = (string)random_int(100000, 999999);
    $canal = '';
    $texto = 'Su código para cambiar la contraseña de ' . marca() . ' es: ' . $codigo . "\nVale 15 minutos. Si usted no lo pidió, ignore este mensaje.";
    if ($u['tg_chat'] !== '' && tg_activo()) {
        tg_encolar($u['liga_id'] ? (int)$u['liga_id'] : null, $u['tg_chat'], '🔑 ' . e($texto), '', 'recup:' . $u['id'] . ':' . bin2hex(random_bytes(4)));
        tg_procesar_cola(10); // si no sale ahora, la tarea automática lo envía en el próximo minuto
        $canal = 'telegram';
    } elseif (smtp_configurado() && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        try {
            smtp_enviar($u['email'], 'Código para cambiar su contraseña', 'Hola ' . $u['nombre'] . ",\n\n" . $texto . "\n\n" . marca());
            $canal = 'correo';
        } catch (RuntimeException $ex) {
            bitacora_evento('recuperacion', 'No se pudo enviar el correo de recuperación a ' . $u['email'] . ': ' . $ex->getMessage(), $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
        }
    }
    if ($canal === '') return '';
    q('UPDATE recuperaciones SET usado = 1 WHERE usuario_id = ? AND usado = 0', [$u['id']]);
    q('INSERT INTO recuperaciones (usuario_id, codigo, canal, expira, ip) VALUES (?,?,?,?,?)', [$u['id'], password_hash($codigo, PASSWORD_DEFAULT), $canal, date('Y-m-d H:i:s', time() + 900), ip_cliente()]);
    bitacora_evento('recuperacion', 'Pidió un código para cambiar su contraseña (enviado por ' . $canal . ')', $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
    return $canal;
}

/** Revisa el código y cambia la contraseña. */
function recuperar_cambiar_clave(string $email, string $codigo, string $nueva): array
{
    $u = fila('SELECT * FROM usuarios WHERE lower(email) = ? AND activo = 1', [mb_strtolower(trim($email), 'UTF-8')]);
    $r = $u ? fila("SELECT * FROM recuperaciones WHERE usuario_id = ? AND usado = 0 AND expira >= ? ORDER BY id DESC LIMIT 1", [$u['id'], date('Y-m-d H:i:s')]) : null;
    if (!$u || !$r) throw new RuntimeException('El código venció o no es válido. Pida uno nuevo.');
    if ((int)$r['intentos'] >= 5) {
        q('UPDATE recuperaciones SET usado = 1 WHERE id = ?', [$r['id']]);
        throw new RuntimeException('Demasiados intentos con ese código. Pida uno nuevo.');
    }
    if (!password_verify(preg_replace('/\D/', '', $codigo), $r['codigo'])) {
        q('UPDATE recuperaciones SET intentos = intentos + 1 WHERE id = ?', [$r['id']]);
        throw new RuntimeException('El código no es correcto. Revise el mensaje que le llegó.');
    }
    if (strlen($nueva) < 8) throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
    q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
    q('UPDATE recuperaciones SET usado = 1 WHERE usuario_id = ?', [$u['id']]);
    bitacora_evento('recuperacion', 'Cambió su contraseña con un código de recuperación (' . $r['canal'] . ')', $u['liga_id'] ? (int)$u['liga_id'] : null, (int)$u['id']);
    if ($u['tg_chat'] !== '') tg_encolar($u['liga_id'] ? (int)$u['liga_id'] : null, $u['tg_chat'], '🔐 Su contraseña de ' . e(marca()) . ' fue cambiada. Si no fue usted, avise de inmediato al administrador.');
    return $u;
}

/* ============================================================
 * 8) SEGUIR A MI EQUIPO (notificaciones web push)
 * ============================================================ */

function b64u(string $b): string
{
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** Punto público (65 bytes) de una clave EC P-256. */
function ec_punto_publico($clave): string
{
    $d = openssl_pkey_get_details($clave);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** Clave pública en formato PEM a partir del punto de 65 bytes. */
function ec_pem_publica(string $punto): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $punto;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function ec_nueva_clave()
{
    $k = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    return $k ?: null;
}

/** Claves VAPID del servidor (se crean solas la primera vez). ['publica' => base64url, 'privada' => PEM] o null. */
function vapid_claves(): ?array
{
    $pub = (string)ajuste('vapid_publica', '');
    $priv = (string)ajuste('vapid_privada', '');
    if ($pub !== '' && $priv !== '') return ['publica' => $pub, 'privada' => $priv];
    if (!function_exists('openssl_pkey_new') || !function_exists('openssl_pkey_derive')) return null;
    $k = ec_nueva_clave();
    if (!$k || !openssl_pkey_export($k, $pem)) return null;
    guardar_ajuste('vapid_publica', b64u(ec_punto_publico($k)));
    guardar_ajuste('vapid_privada', $pem);
    return ['publica' => (string)ajuste('vapid_publica'), 'privada' => $pem];
}

function push_disponible(): bool
{
    return function_exists('curl_init') && vapid_claves() !== null;
}

/** Firma DER (ECDSA) → r||s de 64 bytes (formato JWT ES256). */
function der_a_firma_jwt(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $partes = [];
    for ($i = 0; $i < 2; $i++) {
        $pos++; // 0x02
        $len = ord($der[$pos++]);
        $v = ltrim(substr($der, $pos, $len), "\0");
        $partes[] = str_pad($v, 32, "\0", STR_PAD_LEFT);
        $pos += $len;
    }
    return $partes[0] . $partes[1];
}

function vapid_jwt(string $audiencia, string $privada): string
{
    $sub = (string)ajuste('ventas_email') !== '' ? 'mailto:' . ajuste('ventas_email') : 'mailto:soporte@tuacademia.tukanec.com';
    $ent = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $audiencia, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($ent, $firma, $privada, OPENSSL_ALGO_SHA256)) throw new RuntimeException('No se pudo firmar la notificación.');
    return $ent . '.' . b64u(der_a_firma_jwt($firma));
}

/** Cifra el mensaje para el celular (RFC 8291, aes128gcm). */
function push_cifrar(string $mensaje, string $p256dh, string $auth): string
{
    $ua = b64u_dec($p256dh);
    $secreto = b64u_dec($auth);
    if (strlen($ua) !== 65 || strlen($secreto) < 16) throw new RuntimeException('Suscripción con claves no válidas.');
    $eph = ec_nueva_clave() ?? throw new RuntimeException('OpenSSL no puede crear claves EC.');
    $as = ec_punto_publico($eph);
    $compartido = openssl_pkey_derive(openssl_pkey_get_public(ec_pem_publica($ua)), $eph, 32);
    if ($compartido === false) throw new RuntimeException('No se pudo calcular la clave compartida.');
    $ikm = hash_hkdf('sha256', $compartido, 32, "WebPush: info\0" . $ua . $as, $secreto);
    $sal = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
    $cifrado = openssl_encrypt($mensaje . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $sal . pack('N', 4096) . chr(65) . $as . $cifrado . $tag;
}

/** Solo se aceptan servicios de notificaciones conocidos (Google, Mozilla, Apple, Microsoft). */
function push_endpoint_valido(string $url): bool
{
    $p = parse_url($url);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || strlen($url) > 1000) return false;
    $h = strtolower($p['host']);
    foreach (['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', '.push.apple.com', '.notify.windows.com', '.push.services.mozilla.com'] as $ok) {
        if ($ok[0] === '.' ? str_ends_with($h, $ok) : $h === $ok) return true;
    }
    return false;
}

/** Envía una notificación. Devuelve el código HTTP (201 = entregada al servicio). */
function push_enviar(array $s, array $datos): array
{
    $v = vapid_claves() ?? throw new RuntimeException('No hay claves de notificación.');
    if (!push_endpoint_valido($s['endpoint'])) return [410, 'Servicio no permitido'];
    $p = parse_url($s['endpoint']);
    $cuerpo = push_cifrar(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $s['p256dh'], $s['auth']);
    $ch = curl_init($s['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . vapid_jwt($p['scheme'] . '://' . $p['host'], $v['privada']) . ', k=' . $v['publica'],
            'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 43200', 'Urgency: high',
        ],
    ]);
    $resp = curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    unset($ch); // (v24) curl_close() ya no hace nada desde PHP 8 y PHP 8.5 lo marca como obsoleto
    return [$cod, $err !== '' ? $err : mb_substr((string)$resp, 0, 200)];
}

/** Pone en cola una notificación para todos los que siguen a esos equipos. */
function push_encolar_equipos(array $equipo_ids, string $titulo, string $cuerpo, string $url, string $clave): int
{
    $equipo_ids = array_values(array_unique(array_filter(array_map('intval', $equipo_ids))));
    if (!$equipo_ids) return 0;
    $n = 0;
    try {
        $subs = filas('SELECT DISTINCT suscripcion_id FROM push_seguimientos WHERE equipo_id IN (' . implode(',', $equipo_ids) . ')');
        foreach ($subs as $s) {
            $st = q('INSERT OR IGNORE INTO push_cola (suscripcion_id, titulo, cuerpo, url, clave) VALUES (?,?,?,?,?)', [$s['suscripcion_id'], mb_substr($titulo, 0, 120), mb_substr($cuerpo, 0, 300), $url, $clave . ':' . $s['suscripcion_id']]);
            $n += $st->rowCount();
        }
    } catch (Throwable) {
        return 0; // las notificaciones nunca deben frenar la vocalía
    }
    if ($n) push_envio_al_terminar();
    return $n;
}

/** Envía la cola justo después de responder la página (sin hacer esperar al vocal). */
function push_envio_al_terminar(): void
{
    static $programado = false;
    if ($programado || PHP_SAPI === 'cli') return;
    $programado = true;
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try {
            push_procesar_cola(30);
        } catch (Throwable) {
        }
    });
}

/** Envía las notificaciones pendientes (la llama también la tarea de cada minuto). */
function push_procesar_cola(int $max = 100): int
{
    $pend = filas("SELECT c.*, s.endpoint, s.p256dh, s.auth FROM push_cola c JOIN push_suscripciones s ON s.id = c.suscripcion_id WHERE c.enviado = '' AND c.intentos < 5 ORDER BY c.id LIMIT " . (int)$max);
    if (!$pend || !push_disponible()) return 0;
    $enviados = 0;
    foreach ($pend as $c) {
        // Se "reserva" el mensaje para que dos procesos no lo envíen dos veces
        if (q("UPDATE push_cola SET intentos = intentos + 1 WHERE id = ? AND enviado = '' AND intentos = ?", [$c['id'], $c['intentos']])->rowCount() !== 1) continue;
        try {
            [$cod, $txt] = push_enviar($c, ['t' => $c['titulo'], 'b' => $c['cuerpo'], 'u' => $c['url']]);
        } catch (Throwable $ex) {
            [$cod, $txt] = [0, $ex->getMessage()];
        }
        if ($cod >= 200 && $cod < 300) {
            q('UPDATE push_cola SET enviado = ? WHERE id = ?', [date('Y-m-d H:i:s'), $c['id']]);
            q('UPDATE push_suscripciones SET ultimo_envio = ?, fallos = 0 WHERE id = ?', [date('Y-m-d H:i:s'), $c['suscripcion_id']]);
            $enviados++;
        } elseif (in_array($cod, [404, 410], true)) {
            q('DELETE FROM push_suscripciones WHERE id = ?', [$c['suscripcion_id']]); // el celular canceló o desinstaló
        } else {
            q('UPDATE push_cola SET error = ? WHERE id = ?', [mb_substr('HTTP ' . $cod . ' ' . $txt, 0, 300), $c['id']]);
            q('UPDATE push_suscripciones SET fallos = fallos + 1 WHERE id = ?', [$c['suscripcion_id']]);
        }
    }
    q("DELETE FROM push_suscripciones WHERE fallos >= 20");
    return $enviados;
}

/** Dirección (relativa) del partido para abrir al tocar la notificación. */
function push_url_partido(array $p): string
{
    $slug = (string)valor('SELECT l.slug FROM ligas l JOIN torneos t ON t.liga_id = l.id WHERE t.id = ?', [$p['torneo_id']]);
    return 'index.php?l=' . rawurlencode($slug) . '&p=' . (int)$p['id'];
}

function push_aviso_inicio(array $p): void
{
    push_encolar_equipos([$p['local_id'], $p['visitante_id']], '🟢 ¡Empezó el partido!', $p['local_nombre'] . ' vs ' . $p['visitante_nombre'] . ($p['cancha'] !== '' ? ' · ' . $p['cancha'] : ''), push_url_partido($p), 'ini:' . $p['id']);
}

function push_aviso_gol(array $p, int $evento_id): void
{
    $ev = fila('SELECT ev.*, j.nombres, j.apellidos FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.id = ?', [$evento_id]);
    if (!$ev || !in_array($ev['tipo'], ['gol', 'penal', 'autogol'], true)) return;
    $eq = (int)$ev['equipo_id'] === (int)$p['local_id'] ? $p['local_nombre'] : $p['visitante_nombre'];
    $pp = partido_completo((int)$p['id']) ?? $p;
    push_encolar_equipos([$p['local_id'], $p['visitante_id']], '⚽ ¡GOL de ' . $eq . '!' . ($ev['tipo'] === 'autogol' ? ' (autogol)' : ''),
        nombre_jugador($ev) . ($ev['minuto'] !== null ? ' ' . (int)$ev['minuto'] . "'" : '') . ' · ' . $pp['local_nombre'] . ' ' . (int)$pp['goles_local'] . ' - ' . (int)$pp['goles_visitante'] . ' ' . $pp['visitante_nombre'],
        push_url_partido($p), 'gol:' . $evento_id);
}

function push_aviso_resultado(array $p): void
{
    push_encolar_equipos([$p['local_id'], $p['visitante_id']], '🏁 Final' . ($p['estado'] === 'wo' ? ' (W.O.)' : ''),
        $p['local_nombre'] . ' ' . (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . ' ' . $p['visitante_nombre'] . ($p['penales_local'] !== null ? ' (pen ' . (int)$p['penales_local'] . '-' . (int)$p['penales_visitante'] . ')' : ''),
        push_url_partido($p), 'res:' . $p['id'] . ':' . $p['goles_local'] . '-' . $p['goles_visitante'] . ':' . $p['estado']);
}

function push_aviso_programacion(array $partido_ids): void
{
    foreach ($partido_ids as $id) {
        $p = partido_completo((int)$id);
        if (!$p || $p['fecha'] === '') continue;
        push_encolar_equipos([$p['local_id'], $p['visitante_id']], '📅 Próximo partido',
            $p['local_nombre'] . ' vs ' . $p['visitante_nombre'] . ' · ' . fecha_bonita($p['fecha']) . ($p['hora'] !== '' ? ' ' . $p['hora'] : '') . ($p['cancha'] !== '' ? ' · ' . $p['cancha'] : ''),
            push_url_partido($p), 'prog:' . $p['id'] . ':' . md5($p['fecha'] . $p['hora'] . $p['cancha']));
    }
}

/** Botón "Seguir a este equipo" (página pública del equipo). */
function html_seguir_equipo(array $eq): string
{
    $v = push_disponible() ? vapid_claves() : null;
    if (!$v) return '';
    ob_start(); ?>
<div class="seguir-equipo" id="seguir-box">
  <button type="button" class="btn" id="btn-seguir" data-eq="<?= (int)$eq['id'] ?>">🔔 Seguir a este equipo</button>
  <span id="seguir-msg" class="suave"></span>
  <p class="ayuda" style="margin:.4rem 0 0">Le llega un aviso al celular con cada gol, el resultado final y los horarios de <?= e($eq['nombre']) ?>. Gratis, sin Telegram ni WhatsApp.
    <span id="seguir-ios" style="display:none"><br>📱 <b>iPhone:</b> primero instale la aplicación: botón compartir <b>⬆</b> → <b>Agregar a inicio</b>, ábrala desde el ícono y vuelva a esta página.</span></p>
</div>
<script>
(function () {
  var CLAVE = <?= json_encode($v['publica']) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
  var btn = document.getElementById('btn-seguir'), msg = document.getElementById('seguir-msg'), eq = btn.getAttribute('data-eq');
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent), instalada = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    btn.disabled = true;
    if (ios && !instalada) document.getElementById('seguir-ios').style.display = '';
    else msg.textContent = 'Este navegador no permite notificaciones.';
    return;
  }
  function bytes(b) { var p = '='.repeat((4 - b.length % 4) % 4), s = atob((b + p).replace(/-/g, '+').replace(/_/g, '/')), a = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) a[i] = s.charCodeAt(i); return a; }
  function enviar(accion, sub) {
    var f = new FormData(); f.append('csrf', CSRF); f.append('accion', accion); f.append('equipo_id', eq); f.append('sub', JSON.stringify(sub));
    return fetch('push.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  var siguiendo = false;
  function pintar() { btn.textContent = siguiendo ? '🔕 Dejar de seguir' : '🔔 Seguir a este equipo'; btn.className = siguiendo ? 'btn btn-sec' : 'btn'; }
  navigator.serviceWorker.register('sw.js').then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
    return reg.pushManager.getSubscription();
  }).then(function (sub) {
    if (sub) enviar('estado', sub).then(function (r) { siguiendo = !!(r && r.siguiendo); pintar(); if (siguiendo) msg.textContent = '✓ Ya lo sigue en este celular.'; });
  }).catch(function () {});
  btn.addEventListener('click', function () {
    btn.disabled = true; msg.textContent = '';
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') throw new Error('Debe PERMITIR las notificaciones. Si las bloqueó, actívelas en la configuración del navegador (candado junto a la dirección).');
      return navigator.serviceWorker.ready;
    }).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (s) { return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(CLAVE) }); });
    }).then(function (sub) {
      return enviar(siguiendo ? 'dejar' : 'seguir', sub);
    }).then(function (r) {
      if (!r || !r.ok) throw new Error((r && r.error) || 'No se pudo guardar.');
      siguiendo = !!r.siguiendo; pintar();
      msg.textContent = siguiendo ? '✓ Listo. Le llegará un aviso de prueba en unos segundos.' : 'Ya no recibirá avisos de este equipo.';
    }).catch(function (e) { msg.textContent = '⚠️ ' + (e && e.message ? e.message : 'No se pudo activar.'); }).then(function () { btn.disabled = false; });
  });
})();
</script>
<?php
    return (string)ob_get_clean();
}
