<?php
/*
 * TuAcademia — lo propio de las ACADEMIAS (sale de TuVocalía v38).
 *
 *  1) Disciplinas nuevas: BAILE (ballet, contemporáneo, jazz, salsa, bachata, ritmos latinos, urbano, folclor, tap, árabe)
 *     y MÚSICA (piano, guitarra, canto, violín, batería, bajo, ukelele, teoría). Natación y los deportes ya existían.
 *     Cada disciplina trae sus tests, habilidades, ejercicios, niveles de fábrica y sus palabras (salón, aula, piscina…).
 *  2) NIVELES para cualquier disciplina: el sistema de cinturones y exámenes de grado de artes marciales (v31) se generalizó.
 *     Se guardan en las mismas tablas (esc_cinturones = niveles, esc_examenes, esc_grados con su disciplina).
 *  3) Recitales y presentaciones (admin/recitales.php), clases particulares con paquetes (admin/particulares.php),
 *     DeUna con comprobante, Payphone para los padres y «Mensajes de hoy» por WhatsApp (admin/mensajes.php).
 *
 * El producto se elige con la constante PRODUCTO (app/bootstrap.php): 'academia' apaga ligas, campeonatos, árbitros y vocales
 * (el código sigue ahí; con PRODUCTO = 'ligas' vuelve todo como en TuVocalía).
 */

function es_academia(): bool
{
    return PRODUCTO === 'academia';
}

/** Páginas del panel que son solo de ligas (campeonatos, equipos, árbitros, vocalía…): en TuAcademia no se abren. */
function paginas_solo_ligas(): array
{
    return ['equipos', 'equipo', 'jugador', 'torneos', 'torneo', 'partido', 'planilla', 'arbitros', 'imprimir', 'carnets', 'finanzas', 'recibo', 'redes', 'inscripciones',
        'programar', 'importar', 'patrocinadores', 'votaciones', 'polla', 'liquidacion', 'widgets', 'reservas', 'pedidos', 'acumuladas', 'carrera', 'duelo', 'bolos', 'pruebas', 'indiv',
        'federacion', 'entradas', 'olimpiadas', 'config'];
}

/** Portales públicos que son solo de ligas (vocal, árbitro, delegado, jugador, entradas): en TuAcademia muestran un aviso. */
function portales_solo_ligas(): array
{
    return ['vocal.php', 'arbitro.php', 'delegado.php', 'mi.php', 'e.php', 'puerta.php', 'widget.php', 'calendario.php', 'video.php'];
}

/** (TuAcademia) Corta los portales de ligas al cargar la página. */
function acad_cortar_portales_liga(): void
{
    if (!es_academia() || PHP_SAPI === 'cli') return;
    $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($s === 'video.php') return; // los videos del alumno también se ven por aquí
    if (!in_array($s, portales_solo_ligas(), true)) return;
    http_response_code(404);
    cabecera_publica('No disponible');
    echo '<div class="tarjeta login"><h1>No disponible</h1><p>Esta página es del sistema de ligas y campeonatos, que no está en ' . e(marca()) . '.</p><p><a class="btn" href="index.php">Ir al inicio</a></p></div>';
    pie_publico();
    exit;
}

/* ============================================================
 * 1) DISCIPLINAS
 * ============================================================ */

/** Disciplinas de academia para catalogo_deportes(): clave => [nombre, ícono, familia, tipo de club ('arte')]. */
function catalogo_disciplinas_academia(): array
{
    return [
        'ballet' => ['Ballet', '🩰', 'baile', 'arte'],
        'danza_contemporanea' => ['Danza contemporánea', '💃', 'baile', 'arte'],
        'jazz' => ['Jazz dance', '💃', 'baile', 'arte'],
        'salsa' => ['Salsa', '💃', 'baile', 'arte'],
        'bachata' => ['Bachata', '💃', 'baile', 'arte'],
        'ritmos_latinos' => ['Ritmos latinos', '💃', 'baile', 'arte'],
        'baile_urbano' => ['Baile urbano (hip hop, breaking)', '🕺', 'baile', 'arte'],
        'folclor' => ['Danza folclórica', '💃', 'baile', 'arte'],
        'tap' => ['Tap', '👞', 'baile', 'arte'],
        'danza_arabe' => ['Danza árabe', '💃', 'baile', 'arte'],
        'baile' => ['Baile (otro estilo)', '💃', 'baile', 'arte'],
        'piano' => ['Piano', '🎹', 'musica', 'arte'],
        'guitarra' => ['Guitarra', '🎸', 'musica', 'arte'],
        'canto' => ['Canto', '🎤', 'musica', 'arte'],
        'violin' => ['Violín', '🎻', 'musica', 'arte'],
        'bateria' => ['Batería', '🥁', 'musica', 'arte'],
        'bajo' => ['Bajo', '🎸', 'musica', 'arte'],
        'ukelele' => ['Ukelele', '🪕', 'musica', 'arte'],
        'teoria_musical' => ['Teoría y solfeo', '🎼', 'musica', 'arte'],
        'musica' => ['Música (otro instrumento)', '🎵', 'musica', 'arte'],
    ];
}

/** Grupos para las listas de elegir disciplina (optgroup): título => familias. */
function grupos_catalogo_disciplinas(): array
{
    return [
        '💃 Baile y danza' => ['baile'],
        '🎵 Música' => ['musica'],
        '🏊 Natación' => ['natacion'],
        '🥋 Artes marciales y combate' => ['artes_marciales', 'boxeo'],
        '⚽ Deportes' => null, // todo lo demás
    ];
}

/** <option> de disciplinas agrupadas. $permitida = fn(clave) → bool (por ejemplo, los módulos de la cuenta). */
function opciones_disciplinas(string $sel = '', ?callable $permitida = null): string
{
    $cat = catalogo_deportes();
    $usadas = [];
    $h = '';
    foreach (grupos_catalogo_disciplinas() as $tit => $fams) {
        $op = '';
        foreach ($cat as $k => [$n, $ic, $fam]) {
            if (isset($usadas[$k])) continue;
            if ($fams !== null && !in_array($fam, $fams, true)) continue;
            $usadas[$k] = true;
            if ($k !== $sel && $permitida && !$permitida($k)) continue;
            $op .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . $ic . ' ' . e($n) . '</option>';
        }
        if ($op !== '') $h .= '<optgroup label="' . e($tit) . '">' . $op . '</optgroup>';
    }
    return $h;
}

/** (TuAcademia) Descripción de cada disciplina para vender a academias (sin campeonatos ni vocalía). */
function descripcion_modulo_academia(string $k): string
{
    return [
        'futbol' => 'Escuelas de fútbol e indor: categorías por edad, evaluaciones técnicas y físicas, partidos de las divisiones formativas y portal de padres.',
        'basquet' => 'Escuelas de básquet: tests de tiro y dribbling, evaluación técnica y categorías por edad.',
        'voley' => 'Escuelas de vóley: saque, recepción y ataque, con tests y evaluaciones por edad.',
        'ecuavoley' => 'Escuelas de ecuavóley con tests, habilidades y ejercicios propios.',
        'balonmano' => 'Escuelas de balonmano con tests, habilidades y ejercicios propios.',
        'beisbol' => 'Escuelas de béisbol y softbol con bateo, fildeo y lanzamiento.',
        'ciclismo' => 'Escuelas de ciclismo: manejo, cadencia, subidas y seguridad vial.',
        'combate' => 'Taekwondo, karate, judo y boxeo: cinturones con exámenes de grado, poomsae y kata, categorías de peso y certificados con QR.',
        'raqueta' => 'Tenis, pádel, tenis de mesa y squash: clases por nivel, tests de saque y golpes, y clases particulares con paquetes.',
        'natacion' => 'Escuelas de natación: de matronatación a competitivo, carril de cada alumno, niveles por estilo, tests de 25 y 50 m y festivales.',
        'atletismo' => 'Escuelas de atletismo: velocidad, saltos y lanzamientos, con marcas y medallas.',
        'bolos' => 'Escuelas de bolos con línea de tiro, spares y concentración.',
        'ajedrez' => 'Escuelas de ajedrez: aperturas, táctica, finales y clases particulares.',
        'pesas' => 'Escuelas de levantamiento de pesas: técnica de arranque y envión.',
        'triatlon' => 'Escuelas de triatlón: natación, ciclismo, carrera y transiciones.',
        'esports' => 'Escuelas de e-sports con táctica, concentración y juego limpio.',
    ][$k] ?? '';
}

function es_familia_arte(string $fam): bool
{
    return in_array($fam, ['baile', 'musica'], true);
}

/** Estilo de palabras de la academia: 'arte' (solo baile / música), 'mixto' (artes y deportes) o 'deporte'. */
function acad_estilo(?array $liga): string
{
    if (!$liga || !es_escuela($liga)) return 'deporte';
    static $cache = [];
    $k = (int)$liga['id'] . ':' . ($liga['deporte_escuela'] ?? '');
    if (isset($cache[$k])) return $cache[$k];
    $arte = $dep = 0;
    foreach (deportes_de_escuela($liga) as $d) es_familia_arte(familia_deporte($d)) ? $arte++ : $dep++;
    return $cache[$k] = $arte && !$dep ? 'arte' : ($arte ? 'mixto' : 'deporte');
}

/** ¿La academia tiene alguna disciplina de esta familia? */
function acad_tiene_familia(?array $liga, string $fam): bool
{
    if (!$liga || !es_escuela($liga)) return false;
    foreach (deportes_de_escuela($liga) as $d) if (familia_deporte($d) === $fam) return true;
    return false;
}

/**
 * (arreglos 14-oct) ¿Tiene sentido «Requisitos y documentos»? En TuAcademia solo si alguna disciplina de sus cursos pide algo
 * OBLIGATORIO (por ejemplo certificado médico en artes marciales o ciclismo). Baile, música y natación no piden nada obligatorio.
 */
function acad_requisitos_aplican(?array $liga): bool
{
    if (!es_academia() || !$liga || !es_escuela($liga)) return true;
    foreach (deportes_de_escuela($liga) as $d) {
        $req = requisitos_modulo(modulo_de_deporte_escuela($d));
        if (in_array(2, $req['campos'], true) || array_filter($req['docs'], fn($x) => (int)$x['obligatorio'] === 1)) return true;
    }
    return false;
}

/**
 * (arreglos 14-oct) Edad o categoría que se muestra del alumno (carnet, portal de padres, ficha, libreta).
 * En TuAcademia, si el alumno no está en un deporte de equipo (ballet, piano, natación…) se muestra su edad real ("7 años");
 * en fútbol, básquet, vóley… se sigue mostrando la categoría "Sub-7". En TuVocalía (ligas) siempre "Sub-N".
 */
function edad_o_categoria(array $a, ?array $liga = null): string
{
    if (!es_academia() || empty($a['id'])) return sub_de((string)($a['fecha_nac'] ?? ''));
    try {
        foreach (deportes_alumno($a, $liga) as $d) if (tipo_club_deporte($d) === 'equipo' && familia_deporte($d) !== 'otro' && !es_familia_arte(familia_deporte($d))) return sub_de((string)$a['fecha_nac']);
    } catch (Throwable) {
    }
    $e = edad((string)($a['fecha_nac'] ?? ''));
    return $e === null ? '—' : $e . ($e === 1 ? ' año' : ' años');
}

/** ¿Se piden posición y pie hábil? Solo si la academia tiene deportes de equipo. */
function acad_muestra_posicion(?array $liga): bool
{
    if (!es_academia()) return true;
    if (!$liga) return false;
    foreach (deportes_de_escuela($liga) as $d) if (tipo_club_deporte($d) === 'equipo' && $d !== 'otro') return true;
    return false;
}

/** Palabras de cada familia (lugar de la clase, eventos, obras). */
function vocab_familia(string $fam): array
{
    return match ($fam) {
        'baile' => ['ico' => '💃', 'lugar' => 'Salón', 'lugar_ej' => 'Ej: Salón de espejos', 'evento' => 'Recital', 'eventos' => 'Recitales y presentaciones', 'obra' => 'Coreografía', 'obras' => 'Coreografías', 'quien' => 'Bailarín(a)'],
        'musica' => ['ico' => '🎹', 'lugar' => 'Aula', 'lugar_ej' => 'Ej: Aula 2 (piano)', 'evento' => 'Recital', 'eventos' => 'Recitales y audiciones', 'obra' => 'Obra', 'obras' => 'Obras', 'quien' => 'Músico'],
        'natacion' => ['ico' => '🏊', 'lugar' => 'Piscina', 'lugar_ej' => 'Ej: Piscina semiolímpica, carriles 1 a 3', 'evento' => 'Festival', 'eventos' => 'Festivales y muestras', 'obra' => 'Prueba', 'obras' => 'Pruebas', 'quien' => 'Nadador(a)'],
        'artes_marciales', 'boxeo' => ['ico' => '🥋', 'lugar' => 'Dojo', 'lugar_ej' => 'Ej: Dojo principal', 'evento' => 'Exhibición', 'eventos' => 'Exhibiciones', 'obra' => 'Número', 'obras' => 'Números', 'quien' => 'Alumno'],
        default => ['ico' => '🏅', 'lugar' => 'Cancha', 'lugar_ej' => 'Ej: Cancha 1, coliseo', 'evento' => 'Presentación', 'eventos' => 'Presentaciones y festivales', 'obra' => 'Número', 'obras' => 'Números', 'quien' => 'Alumno'],
    };
}

/** Palabras de la academia (según su disciplina principal o la que más grupos tiene). */
function vocab_academia(?array $liga): array
{
    $d = $liga && es_escuela($liga) ? (deportes_de_escuela($liga)[0] ?? 'futbol') : 'futbol';
    $v = vocab_familia(familia_deporte($d));
    $estilo = acad_estilo($liga);
    $v['curso'] = $estilo === 'deporte' ? 'Grupo' : 'Curso';
    $v['cursos'] = $estilo === 'deporte' ? 'Grupos' : 'Cursos';
    $v['clases'] = $estilo === 'deporte' ? 'Entrenamientos' : 'Clases';
    $v['academia'] = es_academia() ? 'academia' : 'escuela';
    return $v;
}

/** Lugar de clase sugerido para una disciplina (placeholder del formulario del grupo). */
function lugar_de_disciplina(string $d): string
{
    return vocab_familia(familia_deporte($d))['lugar'];
}

/* ---------- Lo de fábrica de baile y música (lo llaman las funciones de multideporte.php / escuela_v36.php) ---------- */

function nombre_especifico_academia(string $f): ?array
{
    return ['baile' => ['🌀', 'Giros y saltos'], 'musica' => ['🎼', 'Lectura musical']][$f] ?? null;
}

/** Tests: [nombre, unidad, mejor, tipo, descripción]. */
function tests_fabrica_academia(string $f): array
{
    return match ($f) {
        'baile' => [
            ['Flexibilidad: split (cm al piso)', 'cm', 'menor', 'fisico', 'Spagat frontal: centímetros entre la cadera y el piso (0 = split completo).'],
            ['Equilibrio en relevé', 's', 'mayor', 'tecnico', 'Segundos en media punta sobre un pie, brazos arriba, sin apoyarse.'],
            ['Giros seguidos', 'rep', 'mayor', 'tecnico', 'Giros limpios seguidos sobre un pie (pirouettes), terminando en posición.'],
            ['Saltos en 30 segundos', 'rep', 'mayor', 'fisico', 'Saltos con cambio de pie (changements) en 30 segundos, con buena técnica.'],
            ['Memoria coreográfica (de 10)', 'pts', 'mayor', 'tecnico', 'Se enseña una frase de 4 × 8 tiempos; el profesor califica de 1 a 10 cuánto recuerda sin ayuda.'],
        ],
        'musica' => [
            ['Escala sin errores (velocidad)', 'bpm', 'mayor', 'tecnico', 'Metrónomo más alto (negra) al que toca la escala de su nivel sin errores.'],
            ['Lectura a primera vista (de 10)', 'pts', 'mayor', 'tecnico', 'Lee un fragmento nuevo de su nivel; el profesor califica de 1 a 10.'],
            ['Pulso y ritmo (de 10)', 'pts', 'mayor', 'tecnico', 'Mantiene el pulso con el metrónomo en 4 compases con los ritmos de su nivel.'],
            ['Oído y afinación (de 10)', 'pts', 'mayor', 'tecnico', 'Repite intervalos o melodías cortas; el profesor califica de 1 a 10.'],
            ['Obras de memoria', 'rep', 'mayor', 'tecnico', 'Cantidad de obras del repertorio que toca o canta de memoria.'],
        ],
        default => [],
    };
}

function habilidades_fabrica_academia(string $f): array
{
    return match ($f) {
        'baile' => ['Técnica', 'Ritmo y musicalidad', 'Coordinación', 'Flexibilidad', 'Expresión y proyección', 'Memoria coreográfica', 'Trabajo en grupo'],
        'musica' => ['Lectura musical', 'Ritmo y pulso', 'Afinación', 'Técnica del instrumento', 'Postura', 'Interpretación', 'Práctica en casa'],
        default => [],
    };
}

/** Ejercicios: [nombre, tipo, objetivo, descripción, minutos, participantes, materiales, edades]. */
function ejercicios_fabrica_academia(string $f): array
{
    return match ($f) {
        'baile' => [
            ['Calentamiento articular con música', 'calentamiento', 'Preparar el cuerpo y entrar al ritmo', 'Movilidad de cuello, hombros, cadera, rodillas y tobillos al compás; luego desplazamientos suaves.', 10, 'Todo el curso', 'Parlante', 'Todas'],
            ['Barra: pliés, tendus y relevés', 'tecnico', 'Colocación, alineación y fuerza de pies', 'Secuencia de barra en primera, segunda y quinta posición, con brazos.', 15, 'Todo el curso', 'Barra, espejo', 'Todas'],
            ['Diagonales de giros', 'arquero', 'Giros con mirada fija (spot) y control', 'Diagonales de chainés y pirouettes en grupos de 3, corrigiendo el spot.', 12, 'Grupos de 3', '—', 'Desde 8 años'],
            ['Saltos en el centro', 'fisico', 'Fuerza de piernas y elevación', 'Sautés, changements y saltos de desplazamiento en series de 8.', 10, 'Todo el curso', '—', 'Todas'],
            ['Coreografía: frase nueva', 'tactico', 'Aprender 4 × 8 tiempos nuevos', 'Enseñar la frase por partes, repetir con conteo y luego con la música.', 20, 'Todo el curso', 'Parlante, música del recital', 'Todas'],
            ['Improvisación guiada', 'juego', 'Creatividad y expresión', 'Bailar libremente con consignas (lento, rápido, alto, bajo, como una pluma…).', 8, 'Todo el curso', 'Parlante', 'Todas'],
            ['Estiramiento y respiración', 'calma', 'Flexibilidad y vuelta a la calma', 'Estiramientos en el piso y respiración profunda; comentario de lo aprendido.', 8, 'Todo el curso', 'Colchonetas', 'Todas'],
        ],
        'musica' => [
            ['Calentamiento de dedos o de voz', 'calentamiento', 'Preparar manos o voz', 'Ejercicios de independencia de dedos (instrumento) o vocalizaciones suaves (canto).', 8, 'Individual o curso', 'Instrumento', 'Todas'],
            ['Escalas y arpegios con metrónomo', 'tecnico', 'Técnica y pulso parejo', 'Escala del nivel a velocidad cómoda, subiendo el metrónomo de a 4.', 10, 'Individual', 'Metrónomo', 'Todas'],
            ['Ritmo con palmas y lectura rítmica', 'fisico', 'Pulso y figuras rítmicas', 'Leer y palmear ritmos del nivel; luego tocarlos en una nota.', 8, 'Individual o curso', 'Tarjetas de ritmo', 'Todas'],
            ['Lectura a primera vista', 'arquero', 'Leer partituras nuevas', 'Leer un fragmento corto nuevo sin detenerse, aunque haya errores.', 8, 'Individual', 'Partituras de lectura', 'Desde 7 años'],
            ['Repertorio: pasaje difícil por partes', 'tactico', 'Resolver el pasaje más difícil de la obra', 'Separar manos, tocar lento, sumar de a un compás y luego unir.', 15, 'Individual', 'Partitura', 'Todas'],
            ['Tocar con pista o en ensamble', 'juego', 'Escuchar a otros y mantener el tempo', 'Tocar la obra con pista de acompañamiento o junto a compañeros.', 10, 'Individual o ensamble', 'Parlante, pistas', 'Todas'],
            ['Tarea para la casa', 'calma', 'Ordenar la práctica de la semana', 'Anotar qué practicar cada día y cuántos minutos.', 4, 'Individual', 'Cuaderno de tareas', 'Todas'],
        ],
        default => [],
    };
}

/** Tipos de ejercicio con las palabras de baile y música (null = los de siempre). */
function tipos_ejercicio_academia(string $f): ?array
{
    return match ($f) {
        'baile' => ['calentamiento' => ['🔥', 'Calentamiento'], 'tecnico' => ['🩰', 'Técnica (barra y centro)'], 'tactico' => ['🎶', 'Coreografía'], 'fisico' => ['💪', 'Flexibilidad y fuerza'],
            'juego' => ['🎭', 'Improvisación y juego'], 'arquero' => nombre_especifico_academia('baile'), 'calma' => ['🧘', 'Estiramiento y vuelta a la calma']],
        'musica' => ['calentamiento' => ['🔥', 'Calentamiento (dedos o voz)'], 'tecnico' => ['🎯', 'Técnica (escalas y estudios)'], 'tactico' => ['🎼', 'Repertorio'], 'fisico' => ['🥁', 'Ritmo y coordinación'],
            'juego' => ['🎵', 'Ensamble y juego musical'], 'arquero' => nombre_especifico_academia('musica'), 'calma' => ['📝', 'Cierre y tarea para la casa']],
        default => null,
    };
}

/* ============================================================
 * 2) NIVELES (cinturones, niveles de baile, música y natación)
 * ============================================================ */

/** Clave del sistema de niveles de una disciplina: las artes marciales usan la de v31 (taekwondo, karate, judo); el resto, su propia clave. */
function nivel_clave(string $d): string
{
    return es_deporte_marcial($d) ? disciplina_de($d) : $d;
}

function es_clave_marcial(string $c): bool
{
    return isset(disciplinas_marciales()[$c]);
}

function nombre_disciplina_nivel(string $c): string
{
    return es_clave_marcial($c) ? disciplinas_marciales()[$c] : nombre_deporte_corto($c);
}

/** Palabras del sistema de niveles (las de artes marciales no cambian). */
function vocabulario_nivel(string $c): array
{
    if (es_clave_marcial($c)) {
        return vocabulario_marcial($c) + ['nivel' => 'Cinturón', 'niveles' => 'Cinturones', 'examen' => 'Examen de grado', 'examenes' => 'Exámenes de grado', 'ico' => '🥋',
            'certificado' => 'CERTIFICADO DE GRADO', 'otorga' => 'aprobó el examen de grado', 'ej_nivel' => '8.º Kup', 'ej_nombre' => 'Amarillo', 'notas_ej' => 'Ej: traer el uniforme limpio y el carnet'];
    }
    $fam = familia_deporte($c);
    $v = vocab_familia($fam);
    $base = ['nivel' => 'Nivel', 'niveles' => 'Niveles', 'examen' => 'Examen de nivel', 'examenes' => 'Exámenes de nivel', 'ico' => '🎖️', 'certificado' => 'CERTIFICADO DE NIVEL',
        'otorga' => 'aprobó el examen de nivel', 'maestro' => 'Profesor(a)', 'lugar' => $v['lugar'], 'cinturon' => 'Nivel', 'combate' => 'Presentación', 'combates' => 'Presentaciones', 'ej_nivel' => '', 'ej_nombre' => 'Intermedio'];
    return match ($fam) {
        'baile' => $base + ['forma' => 'Variación', 'formas' => 'Variaciones', 'notas_ej' => 'Ej: venir con malla, medias y el cabello recogido'],
        'musica' => array_merge($base, ['ico' => '🎼', 'ej_nombre' => 'Nivel 3']) + ['forma' => 'Obra del examen', 'formas' => 'Obras del examen', 'notas_ej' => 'Ej: traer sus partituras y llegar 15 minutos antes'],
        'natacion' => array_merge($base, ['ico' => '🏊', 'ej_nombre' => 'Nivel 2 · Crol']) + ['forma' => 'Lo que debe lograr', 'formas' => 'Destrezas', 'notas_ej' => 'Ej: traer gorra, gafas y toalla'],
        default => $base + ['forma' => 'Requisito', 'formas' => 'Requisitos', 'notas_ej' => 'Ej: venir con el uniforme'],
    };
}

/** Niveles de fábrica: [grado, nombre, color, punta, lo que se evalúa]. Artes marciales: los cinturones de v31. */
function niveles_fabrica(string $c): array
{
    if (es_clave_marcial($c)) return cinturones_fabrica($c);
    return match (familia_deporte($c)) {
        'baile' => [
            ['', 'Iniciación', 'blanco', '', 'Postura, posiciones de pies y brazos, ritmo y coordinación básica'],
            ['', 'Básico', 'amarillo', '', 'Variación corta del nivel (4 × 8 tiempos) con buena postura'],
            ['', 'Intermedio', 'verde', '', 'Variación del nivel con giros y saltos'],
            ['', 'Avanzado', 'azul', '', 'Coreografía completa del nivel y trabajo de puntas o técnica avanzada'],
            ['', 'Pre-profesional', 'morado', '', 'Variación de repertorio y un solo propio'],
        ],
        'musica' => [
            ['', 'Nivel 1', 'blanco', '', 'Postura, notas y ritmos básicos; 2 piezas sencillas'],
            ['', 'Nivel 2', 'amarillo', '', 'Escalas mayores de una octava y 2 piezas'],
            ['', 'Nivel 3', 'naranja', '', 'Escalas mayores y menores, lectura a primera vista sencilla y 2 obras'],
            ['', 'Nivel 4', 'verde', '', 'Arpegios, 1 estudio y 2 obras de estilos distintos'],
            ['', 'Nivel 5', 'azul', '', 'Escalas en dos octavas, 1 estudio y 1 obra de concierto'],
            ['', 'Nivel 6', 'morado', '', 'Recital corto: 3 obras de memoria de estilos distintos'],
        ],
        'natacion' => [
            ['', 'Matronatación (bebés)', 'celeste', '', 'Juegos en el agua con mamá o papá y sumergir la cara'],
            ['', 'Adaptación al agua', 'blanco', '', 'Soplar burbujas, flotar con ayuda, entrar y salir solo de la piscina'],
            ['', 'Nivel 1 · Flotación', 'amarillo', '', 'Flotar de pecho y de espalda 10 segundos sin ayuda'],
            ['', 'Nivel 2 · Crol', 'naranja', '', '15 m de crol con respiración lateral'],
            ['', 'Nivel 3 · Espalda', 'verde', '', '25 m de espalda sin parar'],
            ['', 'Nivel 4 · Pecho', 'azul', '', '25 m de pecho con la patada correcta'],
            ['', 'Nivel 5 · Mariposa', 'morado', '', '15 m de mariposa y viraje de crol'],
            ['', 'Pre-competitivo', 'rojo', '', '200 m combinado y salida desde el bloque'],
            ['', 'Competitivo', 'negro', '', 'Marcas mínimas de la federación y participación en torneos'],
        ],
        default => [
            ['', 'Iniciación', 'blanco', '', 'Fundamentos básicos y gusto por la disciplina'],
            ['', 'Básico', 'amarillo', '', 'Domina los fundamentos del nivel'],
            ['', 'Intermedio', 'verde', '', 'Aplica la técnica en situaciones de juego o presentación'],
            ['', 'Avanzado', 'azul', '', 'Técnica completa y lectura del juego'],
            ['', 'Competitivo', 'negro', '', 'Representa a la academia en torneos'],
        ],
    };
}

/** Sistemas de niveles que usa la academia (uno por disciplina; las artes marciales, uno por estilo). */
function sistemas_nivel_escuela(array $liga): array
{
    $r = [];
    foreach (deportes_de_escuela($liga) as $d) {
        if ($d === 'otro' && !es_academia()) continue;
        $r[] = nivel_clave($d);
    }
    return array_values(array_unique($r));
}

/** Último nivel registrado del alumno en una disciplina (los grados viejos de artes marciales no tienen disciplina guardada). */
function nivel_actual(int $aid, string $c): ?array
{
    return fila("SELECT * FROM esc_grados WHERE alumno_id = ? AND (disciplina = ? OR (disciplina = '' AND ? = 1)) ORDER BY fecha DESC, id DESC LIMIT 1",
        [$aid, $c, es_clave_marcial($c) ? 1 : 0]);
}

/** Sistemas de niveles del alumno (según sus cursos). */
function claves_nivel_alumno(array $a, ?array $liga = null): array
{
    return array_values(array_unique(array_map('nivel_clave', array_filter(deportes_alumno($a, $liga), fn($d) => $d !== 'otro' || es_academia()))));
}

/** Etiqueta chiquita "🎖️ Ballet: Intermedio" de cada nivel del alumno (fichas, listas y portal de padres). */
function html_chips_nivel(array $a, ?array $liga = null): string
{
    $h = '';
    $claves = claves_nivel_alumno($a, $liga);
    foreach ($claves as $c) {
        $g = nivel_actual((int)$a['id'], $c);
        if (!$g) continue;
        $v = vocabulario_nivel($c);
        $h .= ' <span class="chip-deporte">' . $v['ico'] . ' ' . (count($claves) > 1 ? e(nombre_disciplina_nivel($c)) . ': ' : '') . e($g['grado']) . '</span>';
    }
    // Grados viejos sin disciplina de un alumno que ya no está en artes marciales
    if ($h === '' && !$claves && ($g = grado_actual((int)$a['id']))) $h = ' <span class="chip-deporte">🎖️ ' . e($g['grado']) . '</span>';
    return $h;
}

/* ============================================================
 * 3) PALABRAS EN PANTALLA (academias de baile y música)
 * Al final de cada página del panel, del portal de padres y de la página pública de una academia de baile o música,
 * se cambian algunas palabras deportivas por las de la academia ("deporte" → "disciplina", "entrenamiento" → "clase").
 * Solo en español y solo en los textos visibles (no en los datos que escribió el usuario dentro de campos).
 * ============================================================ */

function acad_vocabulario_activar(?array $liga): void
{
    if (!es_academia() || !$liga || !es_escuela($liga) || PHP_SAPI === 'cli') return;
    $GLOBALS['__acad_estilo'] = acad_estilo($liga);
    if (!empty($GLOBALS['__acad_ob'])) return;
    $GLOBALS['__acad_ob'] = true;
    ob_start(function (string $buf): string {
        $estilo = $GLOBALS['__acad_estilo'] ?? '';
        if ($estilo === '' || (function_exists('idioma_actual') && idioma_actual() !== 'es')) return $buf;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) return $buf;
            if (stripos($h, 'Content-Disposition:') === 0) return $buf;
        }
        return acad_cambiar_palabras($buf, $estilo);
    });
}

/** Reemplazos de palabras: [patrón, reemplazo]. 'deporte' → 'disciplina' siempre en academias; en baile y música, además, entrenamiento → clase. */
function acad_reemplazos(string $estilo): array
{
    $r = [
        ['/\b(el|del|al|Del|El) deporte\b/u', fn($m) => ['el' => 'la', 'El' => 'La', 'del' => 'de la', 'Del' => 'De la', 'al' => 'a la'][$m[1]] . ' disciplina'],
        ['/\b(un|Un|otro|Otro|este|Este|ese|Ese|segundo|Segundo|primer|Primer|nuevo|Nuevo|cada|Cada|su|Su|mismo) deporte\b/u', fn($m) => ['un' => 'una', 'Un' => 'Una', 'otro' => 'otra', 'Otro' => 'Otra', 'este' => 'esta', 'Este' => 'Esta', 'ese' => 'esa', 'Ese' => 'Esa', 'segundo' => 'segunda', 'Segundo' => 'Segunda', 'primer' => 'primera', 'Primer' => 'Primera', 'nuevo' => 'nueva', 'Nuevo' => 'Nueva', 'cada' => 'cada', 'Cada' => 'Cada', 'su' => 'su', 'Su' => 'Su', 'mismo' => 'misma'][$m[1]] . ' disciplina'],
        ['/\b(los|Los|sus|Sus|otros|Otros|varios|Varios|los demás) deportes\b/u', fn($m) => ['los' => 'las', 'Los' => 'Las', 'sus' => 'sus', 'Sus' => 'Sus', 'otros' => 'otras', 'Otros' => 'Otras', 'varios' => 'varias', 'Varios' => 'Varias', 'los demás' => 'las demás'][$m[1]] . ' disciplinas'],
        ['/\bdeporte principal\b/u', 'disciplina principal'], ['/\bDeporte principal\b/u', 'Disciplina principal'],
        ['/\bdeportes\b/u', 'disciplinas'], ['/\bDeportes\b/u', 'Disciplinas'], ['/\bdeporte\b/u', 'disciplina'], ['/\bDeporte\b/u', 'Disciplina'],
        ['/\bdisciplina adicional\b/u', 'disciplina adicional'],
        ['/\bmultideporte\b/u', 'de varias disciplinas'],
        ['/\bEscuela deportiva\b/u', 'Academia'], ['/\bescuela deportiva\b/u', 'academia'], ['/\bescuelas deportivas\b/u', 'academias'],
        // (arreglos 14-oct) «pensión» en vez de «mensualidad» y «la academia» en vez de «la escuela» en todos los textos del panel y del portal
        ['/\bMensualidades\b/u', 'Pensiones'], ['/\bmensualidades\b/u', 'pensiones'], ['/\bMensualidad\b/u', 'Pensión'], ['/\bmensualidad\b/u', 'pensión'],
        ['/\b(la|La|su|Su|esta|Esta|una|Una|toda|Toda|cada|Cada|misma|nueva|Nueva) escuela\b/u', '$1 academia'],
        ['/\b(las|Las|sus|Sus|otras|Otras) escuelas\b/u', '$1 academias'],
        ['/\bResumen de la escuela\b/u', 'Resumen de la academia'],
        ['/\bsegundo disciplina\b/u', 'segunda disciplina'],
    ];
    if ($estilo === 'arte' || $estilo === 'mixto') {
        $r = array_merge($r, [
            ['/\b(el|del|al|un|El|Un) entrenamiento\b/u', fn($m) => ['el' => 'la', 'del' => 'de la', 'al' => 'a la', 'un' => 'una', 'El' => 'La', 'Un' => 'Una'][$m[1]] . ' clase'],
            ['/\b(los|Los|sus|Sus) entrenamientos\b/u', fn($m) => ['los' => 'las', 'Los' => 'Las', 'sus' => 'sus', 'Sus' => 'Sus'][$m[1]] . ' clases'],
            ['/\bEntrenamientos\b/u', 'Clases'], ['/\bentrenamientos\b/u', 'clases'], ['/\bEntrenamiento\b/u', 'Clase'], ['/\bentrenamiento\b/u', 'clase'],
            ['/\bHoy entrenan\b/u', 'Hoy tienen clase'], ['/\bLo que entrenó\b/u', 'Lo que practicó'], ['/\bentrenó\b/u', 'practicó'], ['/\bentrena\b/u', 'tiene clase'], ['/\bentrenan\b/u', 'tienen clase'],
            ['/\bel entrenador\b/u', 'el profesor'], ['/\bEntrenadores\b/u', 'Profesores'], ['/\bentrenadores\b/u', 'profesores'],
        ]);
    }
    if ($estilo === 'arte') {
        $r[] = ['/\bSub-(\d{1,2})\b/u', '$1 años'];
        $r[] = ['/\bcancha\b/u', 'salón'];
    }
    return $r;
}

/** Cambia las palabras en los textos visibles del HTML (no en scripts, estilos, campos de texto ni valores de formularios). */
function acad_cambiar_palabras(string $html, string $estilo): string
{
    $rs = acad_reemplazos($estilo);
    $cambiar = function (string $t) use ($rs): string {
        if (!preg_match('/[Dd]eporte|[Ee]ntren|[Ee]scuela|Sub-|cancha|ensualidad/u', $t)) return $t;
        foreach ($rs as [$p, $x]) $t = is_callable($x) ? preg_replace_callback($p, $x, $t) : preg_replace($p, $x, $t);
        return $t;
    };
    $partes = preg_split('#(<(script|style|textarea|svg|code|pre|select)\b.*?</\2>)#si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    for ($i = 0, $n = count($partes); $i < $n; $i++) {
        $p = $partes[$i];
        if (preg_match('#^<(script|style|textarea|svg|code|pre|select)\b#i', $p)) {
            // En las listas de opciones se cambia el texto visible de cada <option>, no su valor
            if (preg_match('#^<select\b#i', $p)) $p = preg_replace_callback('/>([^<>]+)</u', fn($m) => '>' . $cambiar($m[1]) . '<', $p);
            $out .= $p;
            $i++;
            continue;
        }
        $p = preg_replace_callback('/>([^<>]+)</u', fn($m) => '>' . $cambiar($m[1]) . '<', $p);
        $p = preg_replace_callback('/\b(placeholder|title)="([^"]*)"/u', fn($m) => $m[1] . '="' . $cambiar($m[2]) . '"', $p);
        $out .= $p;
    }
    return $out;
}

/** Último cinturón de artes marciales del alumno (los niveles de otras disciplinas no cuentan aquí). */
function grado_marcial_actual(int $aid): ?array
{
    $ks = array_keys(disciplinas_marciales());
    return fila("SELECT * FROM esc_grados WHERE alumno_id = ? AND (disciplina = '' OR disciplina IN ('" . implode("','", $ks) . "')) ORDER BY fecha DESC, id DESC LIMIT 1", [$aid]);
}

/** Tarjeta «Niveles» del alumno para baile, música, natación y deportes (las artes marciales tienen su tarjeta de cinturón). */
function html_niveles_alumno(array $a, ?array $liga = null): string
{
    if (!modulo_activo($liga ?? fila('SELECT * FROM ligas WHERE id = ?', [(int)($a['liga_id'] ?? 0)]), 'acad_niveles')) return ''; // (Anexo B) módulo apagado: no se muestra (los datos siguen)
    $h = '';
    foreach (claves_nivel_alumno($a, $liga) as $c) {
        if (es_clave_marcial($c)) continue;
        $v = vocabulario_nivel($c);
        [$gr, $act, $sig] = nivel_alumno($a, $c);
        $h .= '<div class="tarjeta tarjeta-cinturon"><h3 style="margin-top:0">' . $v['ico'] . ' ' . e($v['nivel']) . ' en ' . e(nombre_disciplina_nivel($c)) . '</h3>';
        $h .= '<p style="margin:.2rem 0">' . ($gr ? html_cinturon($act, $act ? '' : $gr['grado']) . ' <small class="suave"><span>desde</span> <span>' . e(fecha_corta($gr['fecha'])) . '</span></small>' : '<span class="suave">Todavía sin nivel registrado.</span>') . '</p>';
        if ($sig) {
            $h .= '<p style="margin:.4rem 0">Próximo: ' . html_cinturon($sig) . '</p>';
            if ($sig['forma'] !== '') $h .= '<p style="margin:.2rem 0"><small class="suave"><span>Para subir:</span> <span>' . e($sig['forma']) . '</span></small></p>';
        }
        $h .= '</div>';
    }
    return $h;
}

/* ============================================================
 * 4) CAMPOS DEL ALUMNO SEGÚN LA DISCIPLINA
 * ============================================================ */

/** Tallas de vestuario (baile). */
function tallas_vestuario(): array
{
    return ['2', '4', '6', '8', '10', '12', '14', '16', 'XS', 'S', 'M', 'L', 'XL', 'XXL'];
}

/** Instrumentos que se sugieren (música). */
function instrumentos_sugeridos(): array
{
    return ['Piano', 'Teclado', 'Guitarra acústica', 'Guitarra eléctrica', 'Voz (canto)', 'Violín', 'Viola', 'Violonchelo', 'Batería', 'Percusión', 'Bajo', 'Ukelele', 'Flauta', 'Saxofón', 'Trompeta'];
}

/** Campos propios de la academia en la ficha del alumno: instrumento (música), talla de vestuario (baile), carril (natación). */
function html_campos_academia_alumno(array $a, ?array $liga): string
{
    $v = fn(string $k) => e((string)($a[$k] ?? ''));
    $h = '';
    $todo = !$liga || !es_escuela($liga);
    if ($todo || acad_tiene_familia($liga, 'musica')) {
        $h .= '<div><label>🎵 Instrumento</label><input type="text" name="instrumento" maxlength="60" list="lista-instrumentos" value="' . $v('instrumento') . '" placeholder="Ej: Piano"><datalist id="lista-instrumentos">';
        foreach (instrumentos_sugeridos() as $i) $h .= '<option value="' . e($i) . '">';
        $h .= '</datalist><div class="ayuda">¿Tiene instrumento en casa? Anótelo en Notas.</div></div>';
    } else $h .= '<input type="hidden" name="instrumento" value="' . $v('instrumento') . '">';
    if ($todo || acad_tiene_familia($liga, 'baile')) {
        $h .= '<div><label>👗 Talla de vestuario</label><select name="talla_vestuario"><option value="">—</option>';
        foreach (tallas_vestuario() as $t) $h .= '<option' . (($a['talla_vestuario'] ?? '') === $t ? ' selected' : '') . '>' . e($t) . '</option>';
        $h .= '</select><div class="ayuda">Para pedir el vestuario de los recitales.</div></div>';
    } else $h .= '<input type="hidden" name="talla_vestuario" value="' . $v('talla_vestuario') . '">';
    if ($todo || acad_tiene_familia($liga, 'natacion')) {
        $h .= '<div><label>🏊 Carril</label><input type="text" name="carril" maxlength="20" value="' . $v('carril') . '" placeholder="Ej: 2"><div class="ayuda">Carril donde nada en la clase.</div></div>';
    } else $h .= '<input type="hidden" name="carril" value="' . $v('carril') . '">';
    return $h;
}

/** Lista para elegir el nivel con el que entra un alumno nuevo (o el que tiene ahora). Se guarda con guardar_nivel_inicial(). */
function html_nivel_inicial_alumno(array $a, ?array $liga): string
{
    if (!$liga || !es_escuela($liga)) return '<input type="text" disabled value="—">';
    $h = '<select name="nivel_inicial"><option value="">— no cambiar —</option>';
    $claves = !empty($a['id']) ? claves_nivel_alumno($a, $liga) : sistemas_nivel_escuela($liga);
    foreach ($claves as $c) {
        $act = !empty($a['id']) ? nivel_actual((int)$a['id'], $c) : null;
        $h .= '<optgroup label="' . e(nombre_disciplina_nivel($c)) . '">';
        foreach (cinturones_escuela((int)$liga['id'], $c) as $n) {
            $h .= '<option value="' . (int)$n['id'] . '"' . ($act && (int)($act['cinturon_id'] ?? 0) === (int)$n['id'] ? ' selected' : '') . '>' . e(etiqueta_cinturon($n)) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h . '</select><div class="ayuda">Si ya sabe algo de la disciplina, elija su nivel (queda en su ficha y en el portal de los padres).</div>';
}

/** Guarda el nivel elegido en la ficha del alumno (si cambió). */
function guardar_nivel_inicial(int $aid, int $lid, string $por): void
{
    $nid = (int)post('nivel_inicial');
    if (!$nid) return;
    $n = fila('SELECT * FROM esc_cinturones WHERE id = ? AND liga_id = ?', [$nid, $lid]);
    if (!$n) return;
    $act = nivel_actual($aid, $n['disciplina']);
    if ($act && (int)($act['cinturon_id'] ?? 0) === $nid) return;
    q('INSERT INTO esc_grados (alumno_id, fecha, grado, examinador, notas, registrado_por, cinturon_id, disciplina) VALUES (?,?,?,?,?,?,?,?)',
        [$aid, date('Y-m-d'), mb_substr(etiqueta_cinturon($n), 0, 60), '', 'Nivel registrado en la ficha', $por, $nid, $n['disciplina']]);
}

/* ============================================================
 * 5) PALABRAS: pensión en vez de mensualidad (academias)
 * ============================================================ */

/* ============================================================
 * 6) MENSAJES DE HOY (recordatorio de pensiones por WhatsApp, a mano y con un toque)
 * ============================================================ */

/** Días antes del vencimiento en que aparece el recordatorio (Parámetros; 3 por defecto). */
function acad_dias_aviso(array $liga): int
{
    return max(0, min(15, (int)($liga['acad_dias_aviso'] ?? 3)));
}

/** Clave de la familia: la cédula del representante, o su celular, o el alumno. */
function acad_clave_familia(array $a): string
{
    if (trim((string)$a['representante_cedula']) !== '') return 'c:' . strtoupper(trim($a['representante_cedula']));
    $t = preg_replace('/\D/', '', (string)$a['representante_telefono']);
    return $t !== '' ? 't:' . $t : 'a:' . (int)$a['alumno_id'];
}

/**
 * Pensiones (y demás valores) que vencen en los próximos días o ya vencieron, agrupadas por familia.
 * Cada familia: ['clave', 'representante', 'telefono', 'wa', 'alumnos' => [...], 'cargos' => [...], 'total', 'vencido', 'enviado' (fila de hoy o null), 'ultimo' (fecha del último aviso)].
 */
function acad_mensajes_del_dia(array $liga, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $hasta = date('Y-m-d', strtotime($hoy . ' +' . acad_dias_aviso($liga) . ' days'));
    $cs = filas("SELECT c.*, c.id AS cargo_id, a.id AS alumno_id, a.nombres, a.apellidos, a.representante_nombre, a.representante_cedula, a.representante_telefono
                 FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id
                 WHERE c.liga_id = ? AND c.estado = 'pendiente' AND c.vence <= ? AND a.estado = 'activo' AND (c.monto - c.descuento) > 0
                   AND COALESCE(c.comprobante_estado, '') <> 'pendiente'
                 ORDER BY c.vence, a.apellidos", [(int)$liga['id'], $hasta]);
    $fam = [];
    foreach ($cs as $c) {
        $k = acad_clave_familia($c);
        if (!isset($fam[$k])) {
            $fam[$k] = ['clave' => $k, 'representante' => trim((string)$c['representante_nombre']) ?: nombre_alumno($c), 'telefono' => (string)$c['representante_telefono'],
                'wa' => telefono_wa((string)$c['representante_telefono']), 'alumnos' => [], 'cargos' => [], 'total' => 0.0, 'vencido' => false, 'enviado' => null, 'ultimo' => ''];
        }
        $fam[$k]['alumnos'][(int)$c['alumno_id']] = $c['nombres'];
        $fam[$k]['cargos'][] = $c;
        $fam[$k]['total'] += (float)$c['monto'] - (float)$c['descuento'];
        if ($c['vence'] < $hoy) $fam[$k]['vencido'] = true;
    }
    foreach ($fam as $k => &$f) {
        $f['total'] = round($f['total'], 2);
        $f['enviado'] = fila("SELECT * FROM acad_mensajes WHERE liga_id = ? AND clave = ? AND fecha = ? AND tipo = 'pension' ORDER BY id DESC LIMIT 1", [(int)$liga['id'], $k, $hoy]);
        $f['ultimo'] = (string)valor("SELECT MAX(fecha) FROM acad_mensajes WHERE liga_id = ? AND clave = ? AND tipo = 'pension' AND fecha < ?", [(int)$liga['id'], $k, $hoy]);
        $f['texto'] = acad_texto_recordatorio($liga, $f, $hoy);
    }
    unset($f);
    // Primero las que faltan enviar; dentro, primero las vencidas
    uasort($fam, fn($a, $b) => [$a['enviado'] ? 1 : 0, $a['vencido'] ? 0 : 1, $a['cargos'][0]['vence']] <=> [$b['enviado'] ? 1 : 0, $b['vencido'] ? 0 : 1, $b['cargos'][0]['vence']]);
    return $fam;
}

/** Cuántas familias faltan por avisar hoy (número rojo del menú). */
function acad_mensajes_pendientes(array $liga): int
{
    static $c = [];
    if (isset($c[(int)$liga['id']])) return $c[(int)$liga['id']];
    try {
        return $c[(int)$liga['id']] = count(array_filter(acad_mensajes_del_dia($liga), fn($f) => !$f['enviado'] && $f['wa'] !== ''));
    } catch (Throwable) {
        return 0;
    }
}

/** "lunes 15 de octubre" */
function acad_fecha_texto(string $f): string
{
    $d = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'][(int)date('N', strtotime($f))];
    $m = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][(int)date('n', strtotime($f))];
    return $d . ' ' . (int)date('j', strtotime($f)) . ' de ' . $m;
}

/** Mensaje de WhatsApp listo para la familia (amable y corto). */
function acad_texto_recordatorio(array $liga, array $f, ?string $hoy = null): string
{
    $hoy ??= date('Y-m-d');
    $nombre = trim(explode(' ', trim($f['representante']))[0] ?? '');
    $t = 'Hola' . ($nombre !== '' ? ' ' . $nombre : '') . ', le saludamos de ' . $liga['nombre'] . ' 😊' . "\n";
    if (count($f['cargos']) === 1) {
        $c = $f['cargos'][0];
        $cuando = $c['vence'] < $hoy ? 'venció el ' . date('d/m', strtotime($c['vence'])) : ($c['vence'] === $hoy ? 'vence hoy' : 'vence el ' . acad_fecha_texto($c['vence']));
        $t .= 'Le recordamos que ' . lcfirst_es($c['descripcion']) . ' de ' . $c['nombres'] . ' (' . dinero((float)$c['monto'] - (float)$c['descuento']) . ') ' . $cuando . '.';
    } else {
        $t .= 'Le recordamos estos valores pendientes:' . "\n";
        foreach ($f['cargos'] as $c) {
            $t .= '• ' . $c['descripcion'] . ' · ' . $c['nombres'] . ': ' . dinero((float)$c['monto'] - (float)$c['descuento']) . ($c['vence'] < $hoy ? ' (venció el ' . date('d/m', strtotime($c['vence'])) . ')' : ' (vence el ' . date('d/m', strtotime($c['vence'])) . ')') . "\n";
        }
        $t .= 'Total: ' . dinero($f['total']) . '.';
    }
    $formas = [];
    if (trim((string)($liga['deuna_celular'] ?? '')) !== '') $formas[] = 'DeUna al ' . trim($liga['deuna_celular']) . (trim((string)$liga['deuna_titular']) !== '' ? ' (' . trim($liga['deuna_titular']) . ')' : '');
    if (trim((string)$liga['datos_pago']) !== '') $formas[] = 'transferencia: ' . preg_replace('/\s+/', ' ', trim($liga['datos_pago']));
    if ($formas) $t .= "\n\n" . 'Puede pagar por ' . implode(' o por ', $formas) . '.';
    $t .= "\n" . 'Después suba la foto del comprobante en su portal de padres: ' . url_base() . 'familia.php';
    $t .= "\n\n" . '¡Muchas gracias!';
    return $t;
}

/** Primera letra en minúscula ("Pensión de octubre" → "la pensión de octubre"). */
function lcfirst_es(string $t): string
{
    $l = mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    if (preg_match('/^(pensión|mensualidad|matrícula|cuota)\b/u', $l)) return 'la ' . $l;
    if (preg_match('/^(vestuario|paquete|derecho|uniforme|material|seguro)\b/u', $l)) return 'el ' . $l;
    return 'el valor de ' . $l;
}

/** Marca el mensaje de una familia como enviado hoy. */
function acad_marcar_enviado(array $liga, string $clave, string $por, string $tipo = 'pension'): void
{
    $hoy = date('Y-m-d');
    if ($tipo === 'pension') {
        $fam = acad_mensajes_del_dia($liga, $hoy)[$clave] ?? throw new RuntimeException('Ya no hay valores pendientes para esa familia.');
        $dest = $fam['representante']; $tel = $fam['telefono']; $txt = $fam['texto']; $ids = implode(',', array_column($fam['cargos'], 'cargo_id'));
    } else {
        $dest = mb_substr(post('destinatario'), 0, 80); $tel = mb_substr(post('telefono'), 0, 30); $txt = ''; $ids = '';
    }
    if (fila('SELECT id FROM acad_mensajes WHERE liga_id = ? AND clave = ? AND fecha = ? AND tipo = ?', [(int)$liga['id'], $clave, $hoy, $tipo])) return;
    q('INSERT INTO acad_mensajes (liga_id, clave, tipo, destinatario, telefono, texto, cargos, fecha, enviado_por) VALUES (?,?,?,?,?,?,?,?,?)',
        [(int)$liga['id'], $clave, $tipo, $dest, $tel, $txt, $ids, $hoy, $por]);
}

/* ============================================================
 * 7) DEUNA Y COMPROBANTES DE LOS PADRES
 * ============================================================ */

function acad_tiene_deuna(?array $liga): bool
{
    return $liga && trim((string)($liga['deuna_celular'] ?? '')) !== '';
}

/**
 * El padre sube UN comprobante (DeUna o transferencia) para uno o varios valores pendientes de sus hijos.
 * Devuelve el código del grupo de comprobante.
 */
function acad_subir_comprobante_familia(array $liga, array $fam, array $ids, string $forma, string $ref): string
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) throw new RuntimeException('Marque los valores que pagó.');
    $cs = filas("SELECT c.* FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.id IN (" . implode(',', $ids) . ") AND a.liga_id = ? AND a.representante_cedula = ? AND c.estado = 'pendiente'",
        [(int)$fam['liga_id'], $fam['cedula']]);
    if (!$cs) throw new RuntimeException('Esos valores ya no están pendientes.');
    $forma = in_array($forma, ['deuna', 'transferencia', 'deposito'], true) ? $forma : 'transferencia';
    $ruta = subir_comprobante('foto');
    $grupo = 'g' . bin2hex(random_bytes(6));
    foreach ($cs as $c) {
        q("UPDATE esc_cargos SET comprobante = ?, comprobante_estado = 'pendiente', comprobante_forma = ?, comprobante_grupo = ?, comprobante_ref = ?, comprobante_fecha = ? WHERE id = ?",
            [$ruta, $forma, $grupo, mb_substr(trim($ref), 0, 60), date('Y-m-d H:i'), $c['id']]);
    }
    $tot = array_sum(array_map(fn($c) => (float)$c['monto'] - (float)$c['descuento'], $cs));
    aviso_admins($liga, ($forma === 'deuna' ? '📱 <b>Pago por DeUna</b>' : '📤 <b>Comprobante de pago</b>') . ' de una familia: ' . e(implode(' · ', array_column($cs, 'descripcion'))) . ' · ' . dinero($tot)
        . "\nRevisar: " . url_base() . 'admin.php?p=cobros&ver=comprobantes', 'escomp:' . $grupo);
    return $grupo;
}

/** Cargos de un comprobante (todo el grupo, o el cargo solo si es de antes). */
function acad_cargos_de_comprobante(int $lid, array $c): array
{
    if (($c['comprobante_grupo'] ?? '') !== '') return filas("SELECT * FROM esc_cargos WHERE liga_id = ? AND comprobante_grupo = ? AND estado = 'pendiente'", [$lid, $c['comprobante_grupo']]);
    return [$c];
}

/* ============================================================
 * 8) PAYPHONE (pago con tarjeta de los padres; como en TuSalón)
 * ============================================================ */

function acad_payphone_api(): string
{
    return rtrim((string)(CFG['payphone_api'] ?? 'https://pay.payphonetodoesposible.com'), '/');
}

function acad_tiene_payphone(?array $liga): bool
{
    return $liga && trim((string)($liga['payphone_token'] ?? '')) !== '' && trim((string)($liga['payphone_store_id'] ?? '')) !== '' && modulo_activo($liga, 'pago_payphone');
}

/** Prepara el pago con Payphone de los valores marcados: guarda la transacción y devuelve los datos para la cajita de Payphone. */
function acad_payphone_preparar(array $liga, array $fam, array $ids): array
{
    if (!acad_tiene_payphone($liga)) throw new RuntimeException('La academia no recibe pagos con tarjeta por ahora.');
    $ids = array_values(array_filter(array_map('intval', $ids)));
    $cs = $ids ? filas("SELECT c.* FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.id IN (" . implode(',', $ids) . ") AND a.liga_id = ? AND a.representante_cedula = ? AND c.estado = 'pendiente'",
        [(int)$fam['liga_id'], $fam['cedula']]) : [];
    if (!$cs) throw new RuntimeException('Marque los valores que va a pagar.');
    $total = round(array_sum(array_map(fn($c) => (float)$c['monto'] - (float)$c['descuento'], $cs)), 2);
    if ($total <= 0) throw new RuntimeException('No hay nada que pagar.');
    q("INSERT INTO acad_payphone (liga_id, familia_cedula, cargos, monto, cliente_tx) VALUES (?,?,?,?,?)", [(int)$liga['id'], $fam['cedula'], implode(',', array_column($cs, 'id')), $total, 'tmp' . bin2hex(random_bytes(8))]);
    $pid = (int)db()->lastInsertId();
    $tx = 'TA' . $pid . '-' . bin2hex(random_bytes(4));
    q('UPDATE acad_payphone SET cliente_tx = ? WHERE id = ?', [$tx, $pid]);
    $cent = (int)round($total * 100);
    return ['token' => trim($liga['payphone_token']), 'storeId' => trim($liga['payphone_store_id']), 'clientTransactionId' => $tx, 'amount' => $cent, 'amountWithoutTax' => $cent,
        'currency' => 'USD', 'reference' => mb_substr('Pago ' . $liga['nombre'], 0, 100), 'lang' => 'es', 'defaultMethod' => 'card', 'timeZone' => -5];
}

/**
 * Payphone devuelve al padre a payphone.php?id=...&clientTransactionId=... Se pregunta a Payphone si el pago fue aprobado y por cuánto;
 * si coincide, se cobran los valores con recibo. Devuelve [estado ('aprobado'|'cancelado'|'no_aprobado'), recibo|null, liga_id].
 * @param callable|null $http para pruebas: fn(string $url, array $cuerpo, string $token): ?array
 */
function acad_payphone_confirmar(int $id, string $tx, ?callable $http = null): array
{
    if (!preg_match('/^TA(\d+)-[a-f0-9]{8}$/', $tx, $m)) throw new RuntimeException('Pago no reconocido.');
    $p = fila('SELECT * FROM acad_payphone WHERE id = ? AND cliente_tx = ?', [(int)$m[1], $tx]) ?? throw new RuntimeException('Pago no reconocido.');
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]) ?? throw new RuntimeException('Pago no reconocido.');
    if ($p['estado'] === 'aprobado') return ['aprobado', $p['recibo'] ? (int)$p['recibo'] : null, (int)$liga['id']];
    $http ??= 'acad_payphone_http';
    $r = $http(acad_payphone_api() . '/api/button/V2/Confirm', ['id' => $id, 'clientTxId' => $tx], trim((string)$liga['payphone_token']));
    $estado = (string)($r['transactionStatus'] ?? '');
    $monto = (int)($r['amount'] ?? -1);
    q('UPDATE acad_payphone SET respuesta = ?, payphone_id = ? WHERE id = ?', [mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE) ?: '', 0, 2000), (string)($r['transactionId'] ?? $id), $p['id']]);
    if ($estado === 'Approved' && $monto === (int)round((float)$p['monto'] * 100) && ($r['clientTransactionId'] ?? $tx) === $tx) {
        $ids = array_map('intval', array_filter(explode(',', $p['cargos'])));
        $pend = $ids ? array_column(filas("SELECT id FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente' AND id IN (" . implode(',', $ids) . ')', [(int)$liga['id']]), 'id') : [];
        $recibo = $pend ? cobrar_cargos($liga, $pend, 'payphone', 'Payphone ' . ($r['transactionId'] ?? $id)) : null;
        q("UPDATE acad_payphone SET estado = 'aprobado', recibo = ? WHERE id = ?", [$recibo, $p['id']]);
        aviso_admins($liga, '💳 <b>Pago con tarjeta (Payphone)</b>: ' . dinero($p['monto']) . ($recibo ? ' · recibo N° ' . str_pad((string)$recibo, 6, '0', STR_PAD_LEFT) : ''), 'payphone:' . $p['id']);
        return ['aprobado', $recibo, (int)$liga['id']];
    }
    $e = $estado === 'Canceled' ? 'cancelado' : 'no_aprobado';
    q('UPDATE acad_payphone SET estado = ? WHERE id = ?', [$e, $p['id']]);
    return [$e, null, (int)$liga['id']];
}

function acad_payphone_http(string $url, array $cuerpo, string $token): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($cuerpo), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    $r = curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $cod >= 400) {
        error_log('TuAcademia Payphone: la confirmación falló (HTTP ' . $cod . ')');
        return null;
    }
    return json_decode((string)$r, true);
}

/* ============================================================
 * 9) RECITALES Y PRESENTACIONES
 * Un evento (recital, presentación, festival, audición) tiene NÚMEROS (coreografías u obras), cada uno con sus PARTICIPANTES.
 * Cobros: inscripción por alumno (una vez por evento, tipo 'recital') y vestuario por número (tipo 'vestuario').
 * Los cobros son cargos normales (esc_cargos): salen en Pagos, en Mensajes de hoy y en el portal de los padres.
 * ============================================================ */

function tipos_evento_academia(): array
{
    return ['recital' => '🎭 Recital', 'presentacion' => '🎤 Presentación', 'festival' => '🎉 Festival', 'audicion' => '🎼 Audición', 'muestra' => '👀 Clase abierta / muestra'];
}

function estados_evento_academia(): array
{
    return ['preparacion' => ['En preparación', 'chip-pend'], 'listo' => ['Listo', 'chip-ok'], 'realizado' => ['Realizado', ''], 'cancelado' => ['Cancelado', 'chip-mal']];
}

function evento_de_liga(int $id, int $lid): array
{
    return fila('SELECT * FROM acad_eventos WHERE id = ? AND liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Evento no encontrado.');
}

function numeros_evento(int $eid): array
{
    return filas('SELECT n.*, (SELECT COUNT(*) FROM acad_participantes p WHERE p.numero_id = n.id) AS n_part FROM acad_numeros n WHERE n.evento_id = ? ORDER BY n.orden, n.id', [$eid]);
}

function participantes_numero(int $nid): array
{
    return filas('SELECT p.*, a.nombres, a.apellidos, a.foto, a.talla_vestuario, a.instrumento, a.liga_id, a.representante_telefono FROM acad_participantes p JOIN esc_alumnos a ON a.id = p.alumno_id WHERE p.numero_id = ? ORDER BY a.apellidos, a.nombres', [$nid]);
}

/** Alumnos del evento (en algún número o inscritos), sin repetir. */
function alumnos_evento(int $eid): array
{
    return filas('SELECT DISTINCT a.* FROM esc_alumnos a WHERE a.id IN (SELECT p.alumno_id FROM acad_participantes p JOIN acad_numeros n ON n.id = p.numero_id WHERE n.evento_id = ?)
                  OR a.id IN (SELECT alumno_id FROM acad_inscritos WHERE evento_id = ?) ORDER BY a.apellidos, a.nombres', [$eid, $eid]);
}

/** Agrega alumnos a un número (y los inscribe en el evento). Devuelve cuántos agregó. */
function agregar_participantes(array $ev, array $num, array $ids, int $lid): int
{
    $n = 0;
    foreach (array_unique(array_map('intval', $ids)) as $aid) {
        $a = fila("SELECT * FROM esc_alumnos WHERE id = ? AND liga_id = ? AND estado = 'activo'", [$aid, $lid]);
        if (!$a) continue;
        q('INSERT OR IGNORE INTO acad_participantes (numero_id, alumno_id, talla) VALUES (?,?,?)', [$num['id'], $aid, (string)$a['talla_vestuario']]);
        q('INSERT OR IGNORE INTO acad_inscritos (evento_id, alumno_id) VALUES (?,?)', [$ev['id'], $aid]);
        $n++;
    }
    return $n;
}

/**
 * Crea los cobros que falten: inscripción (una por alumno) y vestuario (uno por alumno y número con valor).
 * No duplica: guarda el id del cargo en acad_inscritos / acad_participantes. Devuelve [inscripciones, vestuarios].
 */
function cobrar_evento(array $liga, array $ev, string $por): array
{
    $vence = $ev['vence_cobro'] !== '' ? $ev['vence_cobro'] : date('Y-m-d', strtotime($ev['fecha'] . ' -7 days'));
    if ($vence < date('Y-m-d')) $vence = date('Y-m-d', strtotime('+3 days'));
    $ni = $nv = 0;
    if ((float)$ev['valor_inscripcion'] > 0) {
        foreach (filas('SELECT * FROM acad_inscritos WHERE evento_id = ?', [$ev['id']]) as $i) {
            if ($i['cargo_id'] && fila("SELECT id FROM esc_cargos WHERE id = ? AND estado <> 'anulado'", [$i['cargo_id']])) continue;
            $cid = crear_cargo_escuela($liga, (int)$i['alumno_id'], 'recital', 'Inscripción: ' . $ev['nombre'], (float)$ev['valor_inscripcion'], $vence, $por);
            q('UPDATE acad_inscritos SET cargo_id = ? WHERE evento_id = ? AND alumno_id = ?', [$cid, $ev['id'], $i['alumno_id']]);
            $ni++;
        }
    }
    foreach (numeros_evento((int)$ev['id']) as $num) {
        $valor = $num['valor_vestuario'] !== null ? (float)$num['valor_vestuario'] : (float)$ev['valor_vestuario'];
        if ($valor <= 0) continue;
        foreach (participantes_numero((int)$num['id']) as $p) {
            if ($p['cargo_id'] && fila("SELECT id FROM esc_cargos WHERE id = ? AND estado <> 'anulado'", [$p['cargo_id']])) continue;
            $desc = 'Vestuario «' . $num['titulo'] . '» · ' . $ev['nombre'] . ($p['talla'] !== '' ? ' (talla ' . $p['talla'] . ')' : '');
            $cid = crear_cargo_escuela($liga, (int)$p['alumno_id'], 'vestuario', $desc, $valor, $vence, $por);
            q('UPDATE acad_participantes SET cargo_id = ? WHERE id = ?', [$cid, $p['id']]);
            $nv++;
        }
    }
    return [$ni, $nv];
}

/** Quita a un alumno de un número; si ya no está en ningún número, sale del evento. No deja borrar si ya pagó. */
function quitar_participante(array $ev, int $pid): void
{
    $p = fila('SELECT p.* FROM acad_participantes p JOIN acad_numeros n ON n.id = p.numero_id WHERE p.id = ? AND n.evento_id = ?', [$pid, $ev['id']]) ?? throw new RuntimeException('No encontrado.');
    if ($p['cargo_id']) {
        $c = fila('SELECT * FROM esc_cargos WHERE id = ?', [$p['cargo_id']]);
        if ($c && $c['estado'] === 'pagado') throw new RuntimeException('Ese alumno ya pagó el vestuario de este número: anule el pago primero.');
        if ($c) q("UPDATE esc_cargos SET estado = 'anulado' WHERE id = ? AND estado = 'pendiente'", [$c['id']]);
    }
    q('DELETE FROM acad_participantes WHERE id = ?', [$pid]);
    $sigue = (int)valor('SELECT COUNT(*) FROM acad_participantes p JOIN acad_numeros n ON n.id = p.numero_id WHERE n.evento_id = ? AND p.alumno_id = ?', [$ev['id'], $p['alumno_id']]);
    if (!$sigue) {
        $i = fila('SELECT * FROM acad_inscritos WHERE evento_id = ? AND alumno_id = ?', [$ev['id'], $p['alumno_id']]);
        if ($i && $i['cargo_id']) q("UPDATE esc_cargos SET estado = 'anulado' WHERE id = ? AND estado = 'pendiente'", [$i['cargo_id']]);
        q('DELETE FROM acad_inscritos WHERE evento_id = ? AND alumno_id = ?', [$ev['id'], $p['alumno_id']]);
    }
}

/** Próximos recitales de un alumno con sus números y ensayos (portal de padres y ficha). */
function eventos_alumno(int $aid, bool $solo_futuros = true): array
{
    $evs = filas("SELECT DISTINCT e.* FROM acad_eventos e WHERE e.estado <> 'cancelado'" . ($solo_futuros ? ' AND e.fecha >= ?' : '') . "
                  AND (e.id IN (SELECT evento_id FROM acad_inscritos WHERE alumno_id = ?) OR e.id IN (SELECT n.evento_id FROM acad_numeros n JOIN acad_participantes p ON p.numero_id = n.id WHERE p.alumno_id = ?))
                  ORDER BY e.fecha", $solo_futuros ? [date('Y-m-d'), $aid, $aid] : [$aid, $aid]);
    foreach ($evs as &$e) {
        $e['numeros'] = filas('SELECT n.*, p.talla FROM acad_numeros n JOIN acad_participantes p ON p.numero_id = n.id WHERE n.evento_id = ? AND p.alumno_id = ? ORDER BY n.orden', [$e['id'], $aid]);
        $ids_n = array_map(fn($n) => (int)$n['id'], $e['numeros']);
        $e['ensayos'] = filas('SELECT * FROM acad_ensayos WHERE evento_id = ? AND fecha >= ? AND (numero_id IS NULL' . ($ids_n ? ' OR numero_id IN (' . implode(',', $ids_n) . ')' : '') . ') ORDER BY fecha, hora_inicio', [$e['id'], date('Y-m-d')]);
    }
    unset($e);
    return $evs;
}

/** Tarjeta «Recitales» para el portal de los padres. */
function html_eventos_alumno(array $a): string
{
    if (!modulo_activo(fila('SELECT * FROM ligas WHERE id = ?', [(int)($a['liga_id'] ?? 0)]), 'acad_recitales')) return ''; // (Anexo B) módulo apagado: no se muestra (los datos siguen)
    $evs = eventos_alumno((int)$a['id']);
    if (!$evs) return '';
    $h = '<div class="tarjeta"><h3 style="margin-top:0">🎭 Próximos recitales y presentaciones</h3>';
    foreach ($evs as $e) {
        $h .= '<p style="margin:.4rem 0"><b>' . e($e['nombre']) . '</b> · ' . e(acad_fecha_texto($e['fecha'])) . ($e['hora'] !== '' ? ' · ' . e($e['hora']) : '') . ($e['lugar'] !== '' ? ' · 📍 ' . e($e['lugar']) : '') . '</p>';
        if ($e['numeros']) $h .= '<p class="suave" style="margin:.2rem 0">Participa en: ' . e(implode(', ', array_map(fn($n) => $n['titulo'] . ($n['talla'] !== '' ? ' (talla ' . $n['talla'] . ')' : ''), $e['numeros']))) . '</p>';
        if ($e['ensayos']) {
            $h .= '<ul style="margin:.2rem 0 .6rem">';
            foreach (array_slice($e['ensayos'], 0, 6) as $s) $h .= '<li>' . ($s['tipo'] === 'general' ? '⭐ Ensayo general' : 'Ensayo') . ': ' . e(acad_fecha_texto($s['fecha'])) . ($s['hora_inicio'] !== '' ? ' · ' . e($s['hora_inicio']) . ($s['hora_fin'] !== '' ? '–' . e($s['hora_fin']) : '') : '') . ($s['lugar'] !== '' ? ' · ' . e($s['lugar']) : '') . '</li>';
            $h .= '</ul>';
        }
    }
    return $h . '</div>';
}

/* ============================================================
 * 10) CLASES PARTICULARES CON PAQUETES DE CLASES
 * Paquete (4, 8, 12 clases) → se vende a un alumno (crea su cobro, tipo 'paquete') → cada clase que se marca
 * (asistió, o faltó sin avisar si la academia así lo decide) descuenta 1 del saldo. Horario 1 a 1 por alumno y profesor.
 * ============================================================ */

function estados_clase_particular(): array
{
    return ['asistio' => ['✅ Asistió', 'chip-ok', 1], 'falto' => ['❌ Faltó sin avisar', 'chip-mal', 1], 'aviso' => ['📞 Avisó que no viene', '', 0], 'cancelada' => ['🚫 La academia canceló', '', 0], 'repone' => ['🔁 Clase de reposición', 'chip-pend', 0]];
}

function paquetes_fabrica(): array
{
    return [['Paquete de 4 clases', 4, 60, 40], ['Paquete de 8 clases', 8, 110, 70], ['Paquete de 12 clases', 12, 150, 100]];
}

/** Carga los paquetes de 4, 8 y 12 clases si la academia todavía no tiene ninguno. */
function preparar_paquetes(int $lid): void
{
    if (valor('SELECT COUNT(*) FROM acad_paquetes WHERE liga_id = ?', [$lid])) return;
    foreach (paquetes_fabrica() as [$n, $c, $p, $v]) q('INSERT INTO acad_paquetes (liga_id, nombre, clases, precio, vigencia_dias, minutos) VALUES (?,?,?,?,?,?)', [$lid, $n, $c, $p, $v, 45]);
}

/** Paquete vigente (con saldo) del alumno; si hay varios, el que vence primero. */
function paquete_vigente(int $aid, string $disciplina = ''): ?array
{
    return fila("SELECT * FROM acad_paquetes_alumno WHERE alumno_id = ? AND estado = 'activo' AND clases_usadas < clases_total AND (vence = '' OR vence >= ?)"
        . ($disciplina !== '' ? " AND (disciplina = '' OR disciplina = ?)" : '') . ' ORDER BY vence, id LIMIT 1', $disciplina !== '' ? [$aid, date('Y-m-d'), $disciplina] : [$aid, date('Y-m-d')]);
}

/** Vende un paquete a un alumno: lo deja activo y crea el cobro. Devuelve el id del paquete del alumno. */
function vender_paquete(array $liga, int $aid, int $paquete_id, ?int $profesor_id, string $por, ?float $precio = null): int
{
    $lid = (int)$liga['id'];
    $pq = fila('SELECT * FROM acad_paquetes WHERE id = ? AND liga_id = ? AND activo = 1', [$paquete_id, $lid]) ?? throw new RuntimeException('Elija el paquete.');
    $a = fila("SELECT * FROM esc_alumnos WHERE id = ? AND liga_id = ? AND estado = 'activo'", [$aid, $lid]) ?? throw new RuntimeException('Elija el alumno.');
    if ($profesor_id && !fila('SELECT id FROM esc_profesores WHERE id = ? AND liga_id = ?', [$profesor_id, $lid])) $profesor_id = null;
    $precio = $precio !== null && $precio >= 0 ? round($precio, 2) : (float)$pq['precio'];
    $desde = date('Y-m-d');
    $vence = (int)$pq['vigencia_dias'] > 0 ? date('Y-m-d', strtotime('+' . (int)$pq['vigencia_dias'] . ' days')) : '';
    q('INSERT INTO acad_paquetes_alumno (liga_id, alumno_id, paquete_id, profesor_id, nombre, disciplina, clases_total, precio, uso, desde, vence, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$lid, $aid, $pq['id'], $profesor_id ?: null, $pq['nombre'], $pq['disciplina'], (int)$pq['clases'], $precio, $pq['uso'], $desde, $vence, $por]);
    $pa = (int)db()->lastInsertId();
    if ($precio > 0) {
        $cid = crear_cargo_escuela($liga, $aid, 'paquete', $pq['nombre'] . ' (' . (int)$pq['clases'] . ' clases)' . ($pq['disciplina'] !== '' ? ' · ' . nombre_deporte_corto($pq['disciplina']) : ''), $precio, date('Y-m-d', strtotime('+3 days')), $por);
        q('UPDATE acad_paquetes_alumno SET cargo_id = ? WHERE id = ?', [$cid, $pa]);
    }
    return $pa;
}

/**
 * Marca una clase particular (asistió, faltó…). Si el estado descuenta, baja 1 del paquete vigente.
 * Devuelve ['clase_id', 'descontado' => bool, 'saldo' => int|null, 'paquete' => array|null].
 */
function marcar_clase_particular(array $liga, int $aid, string $estado, string $fecha, string $hora, ?int $profesor_id, string $tema, string $por): array
{
    $lid = (int)$liga['id'];
    fila("SELECT id FROM esc_alumnos WHERE id = ? AND liga_id = ?", [$aid, $lid]) ?? throw new RuntimeException('Elija el alumno.');
    $est = estados_clase_particular()[$estado] ?? throw new RuntimeException('Elija si asistió o faltó.');
    $descuenta = (int)$est[2] === 1 && !($estado === 'falto' && (int)($liga['acad_falta_descuenta'] ?? 1) !== 1);
    if (fila('SELECT id FROM acad_clases WHERE alumno_id = ? AND fecha = ? AND hora = ?', [$aid, $fecha, $hora])) throw new RuntimeException('Esa clase ya está marcada (mismo día y hora).');
    $pq = $descuenta ? paquete_vigente($aid) : null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO acad_clases (liga_id, alumno_id, profesor_id, paquete_alumno_id, fecha, hora, estado, descuenta, tema, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$lid, $aid, $profesor_id ?: ($pq['profesor_id'] ?? null), $pq['id'] ?? null, $fecha, $hora, $estado, $descuenta && $pq ? 1 : 0, mb_substr($tema, 0, 200), $por]);
        $cid = (int)$pdo->lastInsertId();
        if ($descuenta && $pq) {
            q('UPDATE acad_paquetes_alumno SET clases_usadas = clases_usadas + 1 WHERE id = ?', [$pq['id']]);
            q("UPDATE acad_paquetes_alumno SET estado = 'terminado' WHERE id = ? AND clases_usadas >= clases_total", [$pq['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($estado === 'falto') acad_tg_falta_particular($liga, $aid, $fecha, $hora, $cid); // (TuAcademia, 14-oct) aviso por Telegram
    $pq2 = $pq ? fila('SELECT * FROM acad_paquetes_alumno WHERE id = ?', [$pq['id']]) : null;
    return ['clase_id' => $cid, 'descontado' => (bool)($descuenta && $pq), 'saldo' => $pq2 ? (int)$pq2['clases_total'] - (int)$pq2['clases_usadas'] : null, 'paquete' => $pq2, 'sin_paquete' => $descuenta && !$pq];
}

/** Borra una clase marcada por error (devuelve la clase al paquete). */
function borrar_clase_particular(int $lid, int $id): void
{
    $c = fila('SELECT * FROM acad_clases WHERE id = ? AND liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Clase no encontrada.');
    if ($c['paquete_alumno_id'] && (int)$c['descuenta'] === 1) {
        q('UPDATE acad_paquetes_alumno SET clases_usadas = MAX(0, clases_usadas - 1) WHERE id = ?', [$c['paquete_alumno_id']]);
        q("UPDATE acad_paquetes_alumno SET estado = 'activo' WHERE id = ? AND estado = 'terminado' AND clases_usadas < clases_total", [$c['paquete_alumno_id']]);
    }
    q('DELETE FROM acad_clases WHERE id = ?', [$id]);
}

/** Saldo de clases del alumno en todos sus paquetes vigentes. */
function saldo_clases(int $aid): int
{
    return (int)valor("SELECT COALESCE(SUM(clases_total - clases_usadas), 0) FROM acad_paquetes_alumno WHERE alumno_id = ? AND estado = 'activo' AND (vence = '' OR vence >= ?)", [$aid, date('Y-m-d')]);
}

/** Chip «🎹 Le quedan 3 clases» para la ficha del alumno. */
function html_paquete_alumno_chip(int $aid): string
{
    try {
        $pq = paquete_vigente($aid);
    } catch (Throwable) {
        return '';
    }
    if (!$pq) return '';
    $s = (int)$pq['clases_total'] - (int)$pq['clases_usadas'];
    return '<p style="margin:.2rem 0"><span class="chip-deporte' . ($s <= 1 ? ' chip-mal' : '') . '">🎹 ' . e($pq['nombre']) . ': le ' . ($s === 1 ? 'queda 1 clase' : 'quedan ' . $s . ' clases') . ($pq['vence'] !== '' ? ' · vence el ' . e(date('d/m', strtotime($pq['vence']))) : '') . '</span> <a class="btn btn-sec btn-chico" href="admin.php?p=particulares&amp;a=' . $aid . '">Ver clases</a></p>';
}

/** Tarjeta de clases particulares para el portal de los padres. */
function html_particulares_familia(array $a): string
{
    if (!modulo_activo(fila('SELECT * FROM ligas WHERE id = ?', [(int)($a['liga_id'] ?? 0)]), 'acad_particulares')) return ''; // (Anexo B) módulo apagado: no se muestra (los datos siguen)
    $aid = (int)$a['id'];
    $pqs = filas("SELECT p.*, pr.nombre AS profesor FROM acad_paquetes_alumno p LEFT JOIN esc_profesores pr ON pr.id = p.profesor_id WHERE p.alumno_id = ? AND (p.estado = 'activo' OR p.creado >= ?) ORDER BY p.id DESC LIMIT 3", [$aid, date('Y-m-d', strtotime('-60 days'))]);
    $hor = filas('SELECT h.*, pr.nombre AS profesor FROM acad_horario h LEFT JOIN esc_profesores pr ON pr.id = h.profesor_id WHERE h.alumno_id = ? AND h.activo = 1 ORDER BY h.dia, h.hora_inicio', [$aid]);
    if (!$pqs && !$hor) return '';
    $h = '<div class="tarjeta"><h3 style="margin-top:0">🎹 Clases particulares</h3>';
    foreach ($hor as $x) $h .= '<p style="margin:.2rem 0">🗓 ' . e(dias_semana()[(int)$x['dia']] ?? '') . ' ' . e($x['hora_inicio']) . ($x['hora_fin'] !== '' ? '–' . e($x['hora_fin']) : '') . ($x['profesor'] ? ' con ' . e($x['profesor']) : '') . ($x['lugar'] !== '' ? ' · ' . e($x['lugar']) : '') . '</p>';
    foreach ($pqs as $p) {
        $s = (int)$p['clases_total'] - (int)$p['clases_usadas'];
        $h .= '<p style="margin:.4rem 0"><b>' . e($p['nombre']) . '</b>: ' . (int)$p['clases_usadas'] . ' de ' . (int)$p['clases_total'] . ' clases usadas · <b>' . ($p['estado'] === 'activo' ? ($s === 1 ? 'le queda 1' : 'le quedan ' . $s) : 'terminado') . '</b>' . ($p['vence'] !== '' && $p['estado'] === 'activo' ? ' <small class="suave">(vence el ' . e(date('d/m/Y', strtotime($p['vence']))) . ')</small>' : '') . '</p>';
    }
    $ult = filas('SELECT * FROM acad_clases WHERE alumno_id = ? ORDER BY fecha DESC, hora DESC LIMIT 5', [$aid]);
    if ($ult) {
        $h .= '<table class="tabla-comp">';
        foreach ($ult as $c) $h .= '<tr><td>' . e(fecha_corta($c['fecha'])) . ' ' . e($c['hora']) . '</td><td>' . e(strip_tags(estados_clase_particular()[$c['estado']][0] ?? $c['estado'])) . ($c['tema'] !== '' ? '<br><small class="suave">' . e($c['tema']) . '</small>' : '') . '</td></tr>';
        $h .= '</table>';
    }
    return $h . '</div>';
}

/** Clases del horario 1 a 1 de un día (para marcar asistencia rápido). */
function horario_particular_del_dia(int $lid, string $fecha, ?int $profesor_id = null): array
{
    $dia = (int)date('N', strtotime($fecha));
    $sql = 'SELECT h.*, a.nombres, a.apellidos, a.foto, a.liga_id, a.instrumento, pr.nombre AS profesor, (SELECT c.id FROM acad_clases c WHERE c.alumno_id = h.alumno_id AND c.fecha = ? AND c.hora = h.hora_inicio) AS marcada,
                   (SELECT c.estado FROM acad_clases c WHERE c.alumno_id = h.alumno_id AND c.fecha = ? AND c.hora = h.hora_inicio) AS estado_marcado
            FROM acad_horario h JOIN esc_alumnos a ON a.id = h.alumno_id LEFT JOIN esc_profesores pr ON pr.id = h.profesor_id
            WHERE h.liga_id = ? AND h.dia = ? AND h.activo = 1 AND a.estado = \'activo\'';
    $par = [$fecha, $fecha, $lid, $dia];
    if ($profesor_id) { $sql .= ' AND h.profesor_id = ?'; $par[] = $profesor_id; }
    return filas($sql . ' ORDER BY h.hora_inicio, a.nombres', $par);
}

/** (TuAcademia) Tarjeta «Pagar» del portal de los padres: DeUna, transferencia con comprobante y tarjeta con Payphone, para todos sus hijos juntos. */
function html_pagar_familia(?array $liga, ?array $fam): string
{
    if (!$liga || !$fam) return '';
    $pend = filas("SELECT c.*, a.nombres FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE a.liga_id = ? AND a.representante_cedula = ? AND c.estado = 'pendiente' AND (c.monto - c.descuento) > 0 ORDER BY c.vence", [(int)$liga['id'], $fam['cedula']]);
    if (!$pend) return '';
    $libres = array_values(array_filter($pend, fn($c) => ($c['comprobante_estado'] ?? '') !== 'pendiente'));
    $revision = count($pend) - count($libres);
    $deuna = acad_tiene_deuna($liga);
    $pp = acad_tiene_payphone($liga);
    $caja = get('pagar') === 'tarjeta' ? ($_SESSION['payphone_caja'] ?? null) : null;
    ob_start(); ?>
  <div class="tarjeta" id="pagar" style="border:2px solid var(--acento)">
    <h2 style="margin-top:0">💵 Pagar</h2>
    <?php if ($revision): ?><p class="aviso aviso-info" style="margin:.2rem 0 .6rem">⏳ Tiene <?= $revision ?> valor(es) con comprobante en revisión.</p><?php endif; ?>
    <?php if ($caja): ?>
      <p>Total a pagar con tarjeta: <b><?= dinero($caja['amount'] / 100) ?></b></p>
      <link rel="stylesheet" href="https://cdn.payphonetodoesposible.com/box/v1.1/payphone-payment-box.css">
      <script type="module" src="https://cdn.payphonetodoesposible.com/box/v1.1/payphone-payment-box.js"></script>
      <div id="pp-button" style="margin:12px 0"></div>
      <script>
        window.addEventListener('load', function () {
          if (typeof PPaymentButtonBox === 'undefined') { document.getElementById('pp-button').textContent = 'No se pudo cargar Payphone. Recargue la página.'; return; }
          new PPaymentButtonBox(<?= json_encode($caja, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>).render('pp-button');
        });
      </script>
      <a class="btn btn-sec" href="familia.php#pagar">Prefiero DeUna o transferencia</a>
    <?php elseif ($libres): ?>
      <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?>
        <p style="margin:.2rem 0 .4rem"><b>1. Marque lo que va a pagar</b></p>
        <?php foreach ($libres as $c): ?><label class="check" style="font-weight:400"><input type="checkbox" name="cargos[]" value="<?= (int)$c['id'] ?>" checked> <?= e($c['nombres']) ?> · <?= e($c['descripcion']) ?> · <b><?= dinero((float)$c['monto'] - (float)$c['descuento']) ?></b><?= $c['vence'] < date('Y-m-d') ? ' <span class="semaforo s-rojo">vencido</span>' : '' ?><?= ($c['comprobante_estado'] ?? '') === 'rechazado' ? ' <span class="estado-chip chip-mal">comprobante rechazado</span>' : '' ?></label><?php endforeach; ?>
        <p style="margin:.8rem 0 .4rem"><b>2. Pague</b></p>
        <?php if ($deuna): ?><div class="aviso aviso-info" style="margin:.2rem 0">📱 <b>DeUna</b> al celular <b style="font-size:1.15rem"><?= e($liga['deuna_celular']) ?></b><?= trim((string)$liga['deuna_titular']) !== '' ? ' (' . e($liga['deuna_titular']) . ')' : '' ?></div><?php endif; ?>
        <?php if (trim((string)$liga['datos_pago']) !== ''): ?><div class="aviso aviso-info" style="margin:.2rem 0">🏦 <b>Transferencia:</b><br><?= nl2br(e($liga['datos_pago'])) ?></div><?php endif; ?>
        <p style="margin:.8rem 0 .4rem"><b>3. Suba la foto o captura del comprobante</b></p>
        <div class="campos">
          <div><label>¿Cómo pagó?</label><select name="forma"><?php if ($deuna): ?><option value="deuna">📱 DeUna</option><?php endif; ?><option value="transferencia">🏦 Transferencia</option><option value="deposito">Depósito</option></select></div>
          <div><label>Foto del comprobante</label><input type="file" name="foto" accept="image/*" required></div>
          <div><label>N° de comprobante <small>(opcional)</small></label><input type="text" name="referencia" maxlength="60"></div>
        </div>
        <button class="btn btn-grande" name="accion" value="comprobante_varios">📤 Enviar comprobante</button>
        <?php if ($pp): ?><p style="margin:.8rem 0 .2rem" class="suave">¿Prefiere tarjeta de crédito o débito?</p><button class="btn btn-sec btn-grande" name="accion" value="payphone" formnovalidate onclick="var f=this.form.querySelector('input[type=file]');if(f)f.required=false">💳 Pagar con tarjeta (Payphone)</button><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
    <?php
    return (string)ob_get_clean();
}

/* ============================================================
 * 11) ACADEMIA DE DEMOSTRACIÓN (en el producto academia reemplaza a la liga y la escuela de demostración)
 * Academia de baile y música con cursos, alumnos, niveles, un recital con coreografías, clases particulares,
 * pensiones (algunas vencidas para «Mensajes de hoy») y una familia con dos hijas para el portal de padres.
 * Se borra y se vuelve a crear cada madrugada (tareas.php → crear_demos()). Nombres y datos inventados.
 * ============================================================ */
/**
 * (arreglos 14-oct) Clases dictadas de las últimas 3 semanas en la academia de demostración, con asistencia, lo que se trabajó
 * y fotos de ejemplo de baile y música (ilustraciones propias en app/demo/fotos_clase: ballet, piano, salsa, guitarra, canto).
 */
function acad_demo_clases(int $lid, array $cursos, array $gids, array $alumnos, array $prof): void
{
    $dir = RAIZ . '/data/fotos/demo';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $foto = function (string $n) use ($dir): string {
        $src = APP_DIR . '/demo/fotos_clase/' . $n;
        // Se copia también si ya había una foto vieja con ese nombre (las de fútbol de antes)
        if (is_file($src) && (!is_file($dir . '/' . $n) || md5_file($src) !== md5_file($dir . '/' . $n))) @copy($src, $dir . '/' . $n);
        return is_file($dir . '/' . $n) ? 'fotos/demo/' . $n : '';
    };
    $fotos = ['ballet' => ['clase1.jpg', 'clase6.jpg'], 'salsa' => ['clase3.jpg'], 'piano' => ['clase2.jpg'], 'guitarra' => ['clase4.jpg', 'clase5.jpg']];
    $temas = ['ballet' => ['Pliés y tendus en la barra', 'Port de bras y equilibrio', 'Saltos pequeños en el centro'], 'salsa' => ['Paso básico y vueltas', 'Figuras en pareja', 'Coreografía del recital'],
        'piano' => ['Escalas de Do y Sol', 'Lectura a primera vista', 'Para Elisa, primera parte'], 'guitarra' => ['Acordes de Do, Sol y Re', 'Rasgueo de balada', 'Canción completa con voz']];
    foreach ($gids as $i => $g) {
        $dias = explode(',', (string)$cursos[$i][5]);
        $n = 0;
        for ($d = 21; $d >= 1; $d--) {
            $f = date('Y-m-d', strtotime("-$d days"));
            if (!in_array((string)date('N', strtotime($f)), $dias, true)) continue;
            $tema = $temas[$g['d']][$n % 3] ?? 'Clase';
            q("INSERT OR IGNORE INTO esc_sesiones (grupo_id, fecha, profesor_id, profesor_asistio, objetivo, contenido, visible_familia, estado, registrado_por) VALUES (?,?,?,1,?,?,1,'dictada','Demo')",
                [$g['id'], $f, $prof[$cursos[$i][4]], $tema, 'Calentamiento, trabajo del día y repaso para el recital.']);
            $sid = (int)valor('SELECT id FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g['id'], $f]);
            foreach ($alumnos[$g['id']] ?? [] as $k => $aid) q('INSERT OR IGNORE INTO esc_asistencia (sesion_id, alumno_id, estado) VALUES (?,?,?)', [$sid, $aid, ($k + $n) % 9 === 4 ? 'A' : (($k + $n) % 11 === 6 ? 'T' : 'P')]);
            $lista = $fotos[$g['d']] ?? [];
            if ($lista && $n % 2 === 0 && ($ruta = $foto($lista[intdiv($n, 2) % count($lista)])) !== '') {
                q("INSERT INTO esc_sesion_fotos (sesion_id, archivo, descripcion, publica, subido_por) VALUES (?,?,?,1,'Demo')", [$sid, $ruta, 'Así trabajamos hoy']);
            }
            $n++;
        }
    }
}

function crear_academia_demo(): int
{
    return sin_auditoria(function (): int {
        mt_srand(crc32('academia' . date('Y-m-d')));
        q("DELETE FROM ligas WHERE es_demo = 1");
        q("DELETE FROM usuarios WHERE email IN ('escuela@demo.tuacademia.tukanec.com', 'profesor@demo.tuacademia.tukanec.com', 'staff@demo.tuacademia.tukanec.com')");
        $r = crear_liga_completa(['nombre' => 'Academia Demo ' . marca(), 'slug' => 'academia-demo', 'ciudad' => 'Quito', 'color' => '#7b3fb0', 'modalidad' => 'F7', 'tipo' => 'escuela',
            'deporte_escuela' => 'ballet', 'admin_nombre' => 'Dueña de la academia', 'admin_email' => 'escuela@demo.tuacademia.tukanec.com', 'admin_clave' => bin2hex(random_bytes(12))]);
        $lid = (int)$r['liga_id'];
        $Y = (int)date('Y');
        $hoy = date('Y-m-d');
        q("UPDATE ligas SET es_demo = 1, vence = '', es_prueba = 0, color2 = '#f2b705', contacto_nombre = 'Academia Demo', contacto_telefono = '0990000000', esc_mensualidad = 40,
           esc_matricula = 20, esc_dia_vence = 5, esc_desc_hermano = 10, esc_inscripcion = 1, deuna_celular = '0990000000', deuna_titular = 'Academia Demo',
           esc_texto_inscripcion = 'Clases de lunes a sábado. Ballet: malla, medias y zapatillas. Música: traer su instrumento (piano en la academia).',
           datos_pago = 'Banco de ejemplo · Cuenta de ahorros 0000000000 · A nombre de Academia Demo' WHERE id = ?", [$lid]);
        $liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
        preparar_escuela($lid);
        $s1 = (int)valor('SELECT id FROM esc_sedes WHERE liga_id = ? ORDER BY id LIMIT 1', [$lid]);
        q("UPDATE esc_sedes SET nombre = 'Sede Norte', direccion = 'Av. de los Shyris y Portugal' WHERE id = ?", [$s1]);
        $prof = [];
        foreach ([['Lucía Mena', 'Ballet y contemporáneo', 'clase', 15], ['Andrés Salazar', 'Salsa y bachata', 'clase', 12], ['María José Vera', 'Piano y teoría', 'clase', 14], ['Diego Paredes', 'Guitarra y canto', 'alumno', 8]] as [$n, $esp, $tp, $v]) {
            q('INSERT INTO esc_profesores (liga_id, nombre, cedula, telefono, especialidad, tipo_pago, valor_pago) VALUES (?,?,?,?,?,?,?)', [$lid, $n, cedula_demo(), '099' . random_int(1000000, 9999999), $esp, $tp, $v]);
            $prof[] = (int)db()->lastInsertId();
        }
        q('UPDATE esc_profesores SET usuario_id = ? WHERE id = ?', [usuario_demo('profesor@demo.tuacademia.tukanec.com', 'Lucía Mena', 'liga', $lid, ['perfil' => 'profesor']), $prof[0]]);
        // [nombre, disciplina, desde, hasta, profesor, días, inicio, fin, lugar, pensión]
        $cursos = [
            ['Ballet infantil', 'ballet', $Y - 9, $Y - 5, 0, '2,4', '16:00', '17:00', 'Salón de espejos', 40.0],
            ['Ballet juvenil', 'ballet', $Y - 16, $Y - 10, 0, '1,3,5', '17:00', '18:30', 'Salón de espejos', 50.0],
            ['Salsa adultos', 'salsa', null, null, 1, '2,4', '19:30', '21:00', 'Salón 2', 35.0],
            ['Piano nivel 1', 'piano', null, null, 2, '1,3', '15:00', '16:00', 'Aula 1 (piano)', 55.0],
            ['Guitarra y canto', 'guitarra', null, null, 3, '6', '10:00', '11:30', 'Aula 2', 45.0],
        ];
        $gids = [];
        foreach ($cursos as [$n, $d, $de, $ha, $p, $dias, $hi, $hf, $lugar, $pen]) {
            q('INSERT INTO esc_grupos (liga_id, sede_id, nombre, categoria, anio_desde, anio_hasta, profesor_id, dias, hora_inicio, hora_fin, cancha, cupo, mensualidad, deporte) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$lid, $s1, $n, $de ? 'sub' : 'abierta', $de, $ha, $prof[$p], $dias, $hi, $hf, $lugar, 15, $pen, $d]);
            $gids[] = ['id' => (int)db()->lastInsertId(), 'd' => $d, 'de' => $de, 'ha' => $ha];
            preparar_deporte($lid, $d);
        }
        $nom_f = ['Sofía', 'Valentina', 'Isabella', 'Camila', 'Emilia', 'Luciana', 'Martina', 'Antonella', 'Renata', 'Victoria', 'Paula', 'Daniela'];
        $nom_m = ['Mateo', 'Santiago', 'Sebastián', 'Nicolás', 'Martín', 'Joaquín', 'Lucas', 'Gabriel'];
        $ape = ['Pérez', 'Andrade', 'Quishpe', 'Morales', 'Chiriboga', 'Vera', 'Mendoza', 'Zambrano', 'Cedeño', 'Paredes', 'Vaca', 'Salazar', 'Cevallos', 'Ortiz', 'Herrera'];
        $fam_ced = cedula_demo();
        $alumnos = [];
        foreach ($gids as $gi => $g) {
            $n = $g['d'] === 'piano' || $g['d'] === 'guitarra' ? 5 : 8;
            for ($k = 0; $k < $n; $k++) {
                $esFam = $gi === 0 && $k === 0 || $gi === 3 && $k === 0;
                $mujer = in_array($g['d'], ['ballet'], true) || $k % 2 === 0;
                $nombre = $esFam ? ($gi === 0 ? 'Emilia' : 'Renata') : ($mujer ? $nom_f[random_int(0, 11)] : $nom_m[random_int(0, 7)]);
                $ap = $esFam ? 'Andrade Proaño' : $ape[random_int(0, 14)] . ' ' . $ape[random_int(0, 14)];
                $anio = $g['de'] ? random_int((int)$g['de'], (int)$g['ha']) : ($g['d'] === 'salsa' ? random_int($Y - 45, $Y - 20) : random_int($Y - 15, $Y - 8));
                if ($esFam) $anio = $gi === 0 ? $Y - 7 : $Y - 11;
                $rep = $esFam ? 'Patricia Proaño' : ['María', 'Verónica', 'Gabriela', 'Diana', 'Paola'][random_int(0, 4)] . ' ' . explode(' ', $ap)[0];
                q("INSERT INTO esc_alumnos (liga_id, grupo_id, nombres, apellidos, cedula, fecha_nac, genero, representante_nombre, representante_cedula, representante_telefono, parentesco, tipo_sangre,
                   emergencia_nombre, emergencia_telefono, fecha_ingreso, estado, consentimiento, consentimiento_por, instrumento, talla_vestuario) VALUES (?,?,?,?,?,?,?,?,?,?, 'Madre', 'O+', ?,?,?, 'activo', ?, 'representante (demostración)', ?, ?)",
                    [$lid, $g['id'], $nombre, $ap, cedula_demo(), sprintf('%04d-%02d-%02d', $anio, random_int(1, 12), random_int(1, 28)), $mujer ? 'F' : 'M', $rep, $esFam ? $fam_ced : cedula_demo(),
                     '09' . random_int(80000000, 99999999), $rep, '09' . random_int(80000000, 99999999), date('Y-m-d', strtotime('-' . random_int(30, 300) . ' days')), date('Y-m-d H:i:s'),
                     $g['d'] === 'piano' ? 'Piano' : ($g['d'] === 'guitarra' ? 'Guitarra acústica' : ''), $g['d'] === 'ballet' ? ['6', '8', '10', '12', 'S'][random_int(0, 4)] : '']);
                $aid = (int)db()->lastInsertId();
                $alumnos[$g['id']][] = $aid;
                // Nivel de cada alumno (el primero o el segundo de su disciplina)
                $niv = cinturones_escuela($lid, $g['d']);
                if ($niv && random_int(0, 3)) {
                    $n0 = $niv[min(count($niv) - 1, random_int(0, 2))];
                    q('INSERT INTO esc_grados (alumno_id, fecha, grado, registrado_por, cinturon_id, disciplina, notas) VALUES (?,?,?,?,?,?,?)', [$aid, date('Y-m-d', strtotime('-' . random_int(60, 400) . ' days')), etiqueta_cinturon($n0), 'Demo', $n0['id'], $g['d'], 'Nivel de ingreso']);
                }
            }
        }
        acad_demo_clases($lid, $cursos, $gids, $alumnos, $prof); // (arreglos 14-oct) clases de las últimas semanas con asistencia y fotos de baile y música
        codigo_familia($lid, $fam_ced);
        q("UPDATE esc_familias SET pin = ? WHERE liga_id = ? AND cedula = ?", [password_hash(DEMO_CLAVE, PASSWORD_DEFAULT), $lid, $fam_ced]);
        // Pensiones de este mes y del anterior: casi todas pagadas, algunas vencidas y otras por vencer (para «Mensajes de hoy»)
        generar_mensualidades($liga, date('Y-m', strtotime('first day of last month')));
        generar_mensualidades($liga);
        $i = 0;
        foreach (filas("SELECT id, vence FROM esc_cargos WHERE liga_id = ? AND tipo = 'mensualidad' AND estado = 'pendiente' ORDER BY id", [$lid]) as $c) {
            $i++;
            if ($c['vence'] < $hoy && $i % 6 !== 0) cobrar_cargos($liga, [(int)$c['id']], ['efectivo', 'transferencia', 'deuna'][$i % 3], 'Demo', $c['vence']);
        }
        q("UPDATE esc_cargos SET vence = ? WHERE liga_id = ? AND estado = 'pendiente' AND tipo = 'mensualidad' AND id IN (SELECT id FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente' AND vence >= ? ORDER BY id LIMIT 4)",
            [date('Y-m-d', strtotime('+2 days')), $lid, $lid, $hoy]);
        // Recital con 3 coreografías, ensayos y cobro de vestuario
        q("INSERT INTO acad_eventos (liga_id, tipo, nombre, fecha, hora, lugar, descripcion, valor_inscripcion, valor_vestuario, publico, estado, registrado_por) VALUES (?, 'recital', ?, ?, '17:00', 'Teatro de la ciudad', ?, 10, 25, 1, 'preparacion', 'Demo')",
            [$lid, 'Recital de fin de año ' . $Y, date('Y-m-d', strtotime('+35 days')), 'Llegar 1 hora antes. Peinado con moño alto y maquillaje natural.']);
        $ev = fila('SELECT * FROM acad_eventos WHERE id = ?', [(int)db()->lastInsertId()]);
        foreach ([[0, 'El lago de los cisnes', 'ballet', 'Chaikovski', 'Tutú blanco'], [1, 'Variación de Paquita', 'ballet', 'Minkus', 'Tutú rojo'], [2, 'Salsa caleña', 'salsa', 'Grupo Niche', 'Vestido de flecos']] as $k => [$gi, $t, $d, $au, $ve]) {
            q('INSERT INTO acad_numeros (evento_id, orden, titulo, disciplina, grupo_id, profesor, autor, duracion, vestuario) VALUES (?,?,?,?,?,?,?,?,?)', [$ev['id'], $k + 1, $t, $d, $gids[$gi]['id'], '', $au, 4, $ve]);
            agregar_participantes($ev, fila('SELECT * FROM acad_numeros WHERE id = ?', [(int)db()->lastInsertId()]), array_slice($alumnos[$gids[$gi]['id']], 0, 6), $lid);
        }
        q("INSERT INTO acad_ensayos (evento_id, fecha, hora_inicio, hora_fin, lugar, tipo, notas) VALUES (?,?, '15:00', '18:00', 'Teatro de la ciudad', 'general', 'Con vestuario completo')", [$ev['id'], date('Y-m-d', strtotime('+33 days'))]);
        cobrar_evento($liga, $ev, 'Demo');
        // Clases particulares de piano: paquetes, horario 1 a 1 y clases marcadas
        preparar_paquetes($lid);
        $p8 = (int)valor('SELECT id FROM acad_paquetes WHERE liga_id = ? AND clases = 8', [$lid]);
        foreach (array_slice($alumnos[$gids[3]['id']], 0, 3) as $k => $aid) {
            $pa = vender_paquete($liga, $aid, $p8, $prof[2], 'Demo');
            q('INSERT INTO acad_horario (liga_id, alumno_id, profesor_id, dia, hora_inicio, hora_fin, lugar) VALUES (?,?,?,?,?,?,?)', [$lid, $aid, $prof[2], (int)date('N'), sprintf('%02d:00', 16 + $k), sprintf('%02d:45', 16 + $k), 'Aula 1 (piano)']);
            for ($c = 1; $c <= 2 + $k * 2; $c++) marcar_clase_particular($liga, $aid, 'asistio', date('Y-m-d', strtotime('-' . ($c * 7) . ' days')), sprintf('%02d:00', 16 + $k), $prof[2], ['Escalas y arpegios', 'Lectura a primera vista', 'Para Elisa, primera parte'][$c % 3], 'Demo');
        }
        return $lid;
    });
}
