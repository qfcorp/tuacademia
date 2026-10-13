<?php
/*
 * (v19) ESCUELA MULTIDEPORTE
 * Cada grupo tiene su deporte (esc_grupos.deporte; vacío = el deporte principal de la escuela).
 * Los tests, habilidades y ejercicios tienen deporte ('' = sirven para todos). Cada deporte pertenece a una
 * "familia" que define lo que viene de fábrica y cómo funciona su Club:
 *   equipo  → partidos (fútbol / indor, básquet, vóley / ecuavóley)
 *   combate → combates con rounds, categoría de peso y récord (boxeo, artes marciales + cinturones)
 *   marcas  → competencias con tiempo o distancia, puesto y medallas (natación, atletismo)
 * Un alumno puede estar en otros grupos además del principal (esc_alumno_grupos) y paga la mensualidad de cada uno.
 */

/** Deportes que puede tener un grupo: clave => [nombre, ícono, familia, tipo de club]. */
function catalogo_deportes(): array
{
    return [
        'futbol' => ['Fútbol', '⚽', 'futbol', 'equipo'],
        'indor' => ['Indor / fútbol sala', '⚽', 'futbol', 'equipo'],
        'basquet' => ['Básquet', '🏀', 'basquet', 'equipo'],
        'ecuavoley' => ['Ecuavóley', '🏐', 'voley', 'equipo'],
        'voley' => ['Vóley', '🏐', 'voley', 'equipo'],
        'boxeo' => ['Boxeo', '🥊', 'boxeo', 'combate'],
        // (v31) Taekwondo, karate y judo con sus cinturones, pesos, formas y exámenes de grado
        'taekwondo' => ['Taekwondo', '🥋', 'artes_marciales', 'combate'],
        'karate' => ['Karate', '🥋', 'artes_marciales', 'combate'],
        'judo' => ['Judo', '🥋', 'artes_marciales', 'combate'],
        'artes_marciales' => ['Artes marciales (otra)', '🥋', 'artes_marciales', 'combate'],
        'natacion' => ['Natación', '🏊', 'natacion', 'marcas'],
        'atletismo' => ['Atletismo', '🏃', 'atletismo', 'marcas'],
        'tenis' => ['Tenis', '🎾', 'tenis', 'combate'], // (v21) partidos individuales por sets: se registran como "Partidos" con rival y marcador
        // (v26) los mismos deportes que las ligas
        'padel' => ['Pádel', '🎾', 'padel', 'combate'],
        'tenis_mesa' => ['Tenis de mesa', '🏓', 'tenis_mesa', 'combate'],
        'bolos' => ['Bolos', '🎳', 'bolos', 'marcas'],
        'ciclismo' => ['Ciclismo', '🚴', 'ciclismo', 'marcas'],
    ] + catalogo_deportes_v36() + [ // (v36) squash, ajedrez, e-sports, pesas, triatlón, béisbol, softbol y balonmano
        'otro' => ['Otro deporte', '🏅', 'otro', 'equipo'],
    ];
}

function nombre_deporte_escuela(string $d): string
{
    return catalogo_deportes()[$d][0] ?? 'Deporte';
}

/** Nombre corto (sin la explicación entre paréntesis). */
function nombre_deporte_corto(string $d): string
{
    return trim(preg_replace("/\\s*\\(.*\\)$/", "", nombre_deporte_escuela($d)));
}

function icono_deporte_escuela(string $d): string
{
    return catalogo_deportes()[$d][1] ?? '🏅';
}

function familia_deporte(string $d): string
{
    return catalogo_deportes()[$d][2] ?? 'otro';
}

/** 'equipo' | 'combate' | 'marcas' */
function tipo_club_deporte(string $d): string
{
    return catalogo_deportes()[$d][3] ?? 'equipo';
}

/** Deporte de un grupo (si no tiene, el principal de la escuela). */
function deporte_grupo(?array $g, ?array $liga = null): string
{
    $d = (string)($g['deporte'] ?? '');
    if ($d !== '' && isset(catalogo_deportes()[$d])) return $d;
    $liga ??= $g ? fila('SELECT deporte_escuela FROM ligas WHERE id = ?', [(int)$g['liga_id']]) : null;
    $p = (string)($liga['deporte_escuela'] ?? 'futbol');
    return isset(catalogo_deportes()[$p]) ? $p : 'futbol';
}

/** Deportes (distintos) que se enseñan en la escuela, en orden de cantidad de grupos. */
function deportes_de_escuela(array $liga): array
{
    $r = [];
    foreach (filas('SELECT * FROM esc_grupos WHERE liga_id = ? AND activo = 1', [(int)$liga['id']]) as $g) {
        $d = deporte_grupo($g, $liga);
        $r[$d] = ($r[$d] ?? 0) + 1;
    }
    if (!$r) $r[deporte_grupo(null, $liga)] = 1;
    arsort($r);
    return array_keys($r);
}

function es_multideporte(array $liga): bool
{
    return count(deportes_de_escuela($liga)) > 1;
}

/** "Fútbol, Básquet y Boxeo" */
function texto_deportes(array $deportes): string
{
    $n = array_map('nombre_deporte_corto', $deportes);
    return count($n) > 1 ? implode(', ', array_slice($n, 0, -1)) . ' y ' . end($n) : (string)($n[0] ?? '');
}

/** Deportes de un alumno: el de su grupo principal y los de sus otros grupos. */
function deportes_alumno(array $a, ?array $liga = null): array
{
    $r = [];
    foreach (grupos_alumno((int)$a['id']) as $g) $r[] = deporte_grupo($g, $liga);
    if (!$r) $r[] = deporte_grupo(null, $liga ?? fila('SELECT deporte_escuela FROM ligas WHERE id = ?', [(int)$a['liga_id']]));
    return array_values(array_unique($r));
}

/** Familias (para filtrar tests, habilidades y ejercicios) de una lista de deportes. */
function familias_de(array $deportes): array
{
    return array_values(array_unique(array_map('familia_deporte', $deportes)));
}

/** Condición SQL "sirve para estos deportes" (los de deporte vacío sirven para todos). */
function sql_deporte(array $deportes, string $col = 'deporte'): string
{
    $f = familias_de($deportes);
    return "($col = ''" . ($f ? " OR $col IN ('" . implode("','", array_map(fn($x) => preg_replace('/[^a-z_]/', '', $x), $f)) . "')" : '') . ')';
}

/* ---------- Alumno en varios grupos (otros deportes) ---------- */

/** Grupo principal + otros grupos del alumno (cada uno con 'principal' = 1/0). */
function grupos_alumno(int $aid): array
{
    return filas("SELECT g.*, 1 AS principal FROM esc_grupos g JOIN esc_alumnos a ON a.grupo_id = g.id WHERE a.id = ?
                  UNION ALL SELECT g.*, 0 AS principal FROM esc_alumno_grupos ag JOIN esc_grupos g ON g.id = ag.grupo_id WHERE ag.alumno_id = ?
                  ORDER BY principal DESC, nombre", [$aid, $aid]);
}

/** Ids de los alumnos que están en un grupo como grupo EXTRA. */
function ids_extra_grupo(int $gid): array
{
    return array_map('intval', array_column(filas('SELECT alumno_id FROM esc_alumno_grupos WHERE grupo_id = ?', [$gid]), 'alumno_id'));
}

/* ---------- Tipos de ejercicio según el deporte ---------- */

/** Nombre del tipo "específico" (antes "Arqueros") según el deporte. */
function nombre_especifico(string $familia): array
{
    return match ($familia) {
        'futbol' => ['🧤', 'Arqueros'], 'basquet' => ['🏀', 'Por posición (base, pívot)'], 'voley' => ['🏐', 'Líbero y armador'],
        'boxeo' => ['🥊', 'Sparring'], 'artes_marciales' => ['🥋', 'Combate'], 'natacion' => ['🏊', 'Estilos y salidas'],
        'atletismo' => ['🏃', 'Por prueba'], 'tenis' => ['🎾', 'Saque y devolución'],
        'padel' => ['🎾', 'Red y paredes'], 'tenis_mesa' => ['🏓', 'Saque y efectos'], 'bolos' => ['🎳', 'Spares y línea de tiro'], 'ciclismo' => ['🚴', 'Pedaleo y curvas'],
        default => nombre_especifico_v36($familia) ?? ['⭐', 'Específico del deporte'],
    };
}

/* ============================================================
 * CATÁLOGO DE FÁBRICA POR DEPORTE
 * ============================================================ */

/** Tests: [nombre, unidad, mejor, tipo, descripción]. Familia '' = físicos para todos. */
function tests_fabrica(string $familia): array
{
    return match ($familia) {
        '' => [
            ['Velocidad 30 metros', 's', 'menor', 'fisico', 'Salida parado, cronometrar 30 m.'],
            ['Salto horizontal', 'cm', 'mayor', 'fisico', 'Salto a pies juntos desde parado.'],
            ['Salto vertical', 'cm', 'mayor', 'fisico', 'Salto con contramovimiento.'],
            ['Abdominales 1 minuto', 'rep', 'mayor', 'fisico', ''],
            ['Flexibilidad (sit and reach)', 'cm', 'mayor', 'fisico', ''],
            ['Resistencia Yo-Yo', 'm', 'mayor', 'fisico', 'Metros recorridos en el test Yo-Yo intermitente.'],
        ],
        'futbol' => [
            ['Conducción en slalom', 's', 'menor', 'tecnico', 'Conducir el balón entre 6 conos ida y vuelta.'],
            ['Dominio del balón (toques)', 'rep', 'mayor', 'tecnico', 'Toques seguidos sin que caiga el balón.'],
        ],
        'basquet' => [
            ['Tiros libres (encestados de 10)', 'rep', 'mayor', 'tecnico', 'Diez tiros libres seguidos; se anotan los encestados.'],
            ['Bandejas en 30 segundos', 'rep', 'mayor', 'tecnico', 'Bandejas alternando lado derecho e izquierdo; se cuentan las encestadas.'],
            ['Dribbling en zigzag', 's', 'menor', 'tecnico', 'Driblar entre 6 conos ida y vuelta cambiando de mano en cada cono.'],
            ['Tiro de media distancia (de 10)', 'rep', 'mayor', 'tecnico', 'Diez tiros desde 5 posiciones alrededor de la llave.'],
        ],
        'voley' => [
            ['Saques dentro (de 10)', 'rep', 'mayor', 'tecnico', 'Diez saques; se anotan los que caen dentro de la cancha.'],
            ['Recepción al blanco (de 10)', 'rep', 'mayor', 'tecnico', 'Recibir 10 saques y dejarlos en la zona del armador.'],
            ['Toques de dedos seguidos', 'rep', 'mayor', 'tecnico', 'Toques de dedos sobre la cabeza sin que caiga la pelota.'],
            ['Alcance en salto', 'cm', 'mayor', 'fisico', 'Altura alcanzada con una mano en salto con carrera.'],
        ],
        'boxeo' => [
            ['Golpes en el saco (30 s)', 'rep', 'mayor', 'tecnico', 'Golpes rectos limpios al saco durante 30 segundos.'],
            ['Salto de cuerda (1 minuto)', 'rep', 'mayor', 'fisico', 'Saltos completos en un minuto.'],
            ['Flexiones de brazos (1 minuto)', 'rep', 'mayor', 'fisico', 'Flexiones completas, pecho casi al piso.'],
        ],
        'artes_marciales' => [
            ['Patadas en la paleta (30 s)', 'rep', 'mayor', 'tecnico', 'Patadas circulares alternando piernas durante 30 segundos.'],
            ['Apertura de piernas (cm del piso)', 'cm', 'menor', 'fisico', 'Distancia de la cadera al piso en apertura lateral.'],
            ['Flexiones de brazos (1 minuto)', 'rep', 'mayor', 'fisico', 'Flexiones completas, pecho casi al piso.'],
        ],
        'natacion' => [
            ['25 m libre', 's', 'menor', 'tecnico', 'Salida desde el agua o el borde, cronometrar 25 m crol.'],
            ['50 m libre', 's', 'menor', 'tecnico', ''],
            ['25 m espalda', 's', 'menor', 'tecnico', ''],
            ['25 m pecho', 's', 'menor', 'tecnico', ''],
            ['Nado continuo 12 minutos', 'm', 'mayor', 'fisico', 'Metros nadados sin parar en 12 minutos (test de Cooper en el agua).'],
        ],
        'atletismo' => [
            ['60 m planos', 's', 'menor', 'tecnico', 'Salida de pie o de tacos, cronometrar 60 m.'],
            ['600 m', 's', 'menor', 'fisico', 'Tiempo en segundos para 600 m.'],
            ['Salto largo', 'cm', 'mayor', 'tecnico', 'Mejor de 3 intentos con carrera.'],
            ['Lanzamiento de pelota', 'm', 'mayor', 'tecnico', 'Pelota de 150 g, mejor de 3 intentos.'],
        ],
        'tenis' => [
            ['Saques dentro (de 10)', 'rep', 'mayor', 'tecnico', 'Diez saques al cuadro de saque; se anotan los buenos.'],
            ['Derechas cruzadas al blanco (de 10)', 'rep', 'mayor', 'tecnico', 'El entrenador alimenta 10 pelotas; se cuentan las que caen en la zona marcada.'],
            ['Reveses al blanco (de 10)', 'rep', 'mayor', 'tecnico', 'Igual que las derechas, con el revés.'],
            ['Peloteo sin fallar', 'rep', 'mayor', 'tecnico', 'Golpes seguidos desde el fondo con un compañero sin que la pelota se detenga.'],
            ['Test de la araña', 's', 'menor', 'fisico', 'Recoger 5 pelotas colocadas en la cancha y dejarlas en el centro, lo más rápido posible.'],
        ],
        'padel' => [
            ['Saques dentro (de 10)', 'rep', 'mayor', 'tecnico', 'Diez saques por debajo de la cintura al cuadro contrario; se anotan los buenos.'],
            ['Bandejas al blanco (de 10)', 'rep', 'mayor', 'tecnico', 'El entrenador lanza globos; se cuentan las bandejas que caen en la zona marcada.'],
            ['Salidas de pared (de 10)', 'rep', 'mayor', 'tecnico', 'Devolver la pelota después del rebote en la pared de fondo.'],
            ['Test de la araña', 's', 'menor', 'fisico', 'Recoger 5 pelotas de la cancha y dejarlas en el centro, lo más rápido posible.'],
        ],
        'tenis_mesa' => [
            ['Saques al blanco (de 10)', 'rep', 'mayor', 'tecnico', 'Diez saques a la zona marcada en la mesa.'],
            ['Peloteo de derecha sin fallar', 'rep', 'mayor', 'tecnico', 'Golpes seguidos de derecha en diagonal con un compañero.'],
            ['Peloteo de revés sin fallar', 'rep', 'mayor', 'tecnico', 'Igual que la derecha, con el revés.'],
            ['Desplazamiento lateral (30 s)', 'rep', 'mayor', 'fisico', 'Toques a dos conos separados 2 m en 30 segundos.'],
        ],
        'bolos' => [
            ['Promedio de 3 líneas', 'pts', 'mayor', 'tecnico', 'Promedio de pinos de tres líneas seguidas.'],
            ['Spares convertidos (de 10)', 'rep', 'mayor', 'tecnico', 'Diez intentos a pinos sueltos (7, 10, 4-7…); se cuentan los derribados.'],
            ['Primer tiro al bolsillo (de 10)', 'rep', 'mayor', 'tecnico', 'Primeros tiros que entran al bolsillo 1-3 (o 1-2 para zurdos).'],
        ],
        'ciclismo' => [
            ['Contrarreloj 2 km', 's', 'menor', 'fisico', 'Tiempo en un circuito plano de 2 km.'],
            ['Sprint 200 m lanzado', 's', 'menor', 'fisico', 'Tiempo en 200 m con salida lanzada.'],
            ['Slalom entre conos', 's', 'menor', 'tecnico', 'Recorrido de habilidad entre 10 conos sin tocarlos.'],
            ['Cadencia sostenida (rpm)', 'rpm', 'mayor', 'fisico', 'Pedaleo a cadencia alta durante 1 minuto.'],
        ],
        default => tests_fabrica_v36($familia),
    };
}

/** Habilidades de la evaluación técnica. Familia '' = para todos. */
function habilidades_fabrica(string $familia): array
{
    return match ($familia) {
        '' => ['Actitud y disciplina'],
        'futbol' => ['Control', 'Pase', 'Conducción', 'Regate', 'Remate', 'Cabeceo', 'Defensa', 'Visión de juego'],
        'basquet' => ['Dribbling', 'Pase', 'Tiro', 'Bandeja', 'Rebote', 'Defensa', 'Visión de juego'],
        'voley' => ['Saque', 'Recepción', 'Colocada (armado)', 'Remate', 'Bloqueo', 'Defensa', 'Desplazamientos'],
        'boxeo' => ['Guardia y postura', 'Jab', 'Directo', 'Ganchos y uppercuts', 'Esquivas y bloqueos', 'Desplazamientos', 'Resistencia en el round'],
        'artes_marciales' => ['Posiciones (bases)', 'Técnicas de puño', 'Patadas', 'Defensas', 'Formas (kata / poomsae)', 'Combate', 'Flexibilidad'],
        'natacion' => ['Crol (libre)', 'Espalda', 'Pecho', 'Mariposa', 'Salidas', 'Virajes', 'Respiración'],
        'atletismo' => ['Técnica de carrera', 'Salidas', 'Saltos', 'Lanzamientos', 'Ritmo y resistencia', 'Coordinación'],
        'tenis' => ['Saque', 'Derecha', 'Revés', 'Volea', 'Smash', 'Desplazamientos', 'Táctica de juego'],
        'padel' => ['Saque', 'Derecha', 'Revés', 'Volea', 'Bandeja y víbora', 'Salida de pared', 'Juego en pareja'],
        'tenis_mesa' => ['Saque', 'Derecha (drive)', 'Revés', 'Efectos (top y corte)', 'Bloqueo', 'Desplazamientos', 'Táctica de juego'],
        'bolos' => ['Postura y aproximación', 'Soltada de la bola', 'Línea de tiro', 'Primer tiro (strike)', 'Spares', 'Concentración y rutina'],
        'ciclismo' => ['Equilibrio y manejo', 'Pedaleo (cadencia)', 'Cambios de marcha', 'Curvas', 'Subidas', 'Rueda en grupo', 'Seguridad vial'],
        default => habilidades_fabrica_v36($familia),
    };
}

/** Ejercicios de fábrica: [nombre, tipo, objetivo, descripción, minutos, jugadores, materiales, edades]. */
function ejercicios_fabrica(string $familia): array
{
    if ($familia === 'futbol' || $familia === '') {
        $todos = ejercicios_por_defecto();
        $generales = ['Movilidad articular y skipping', 'Velocidad de reacción', 'Fuerza con el propio cuerpo', 'Estiramientos y charla final'];
        return array_values(array_filter($todos, fn($x) => ($familia === '') === in_array($x[0], $generales, true)));
    }
    return match ($familia) {
        'basquet' => [
            ['Dribbling en zigzag con ambas manos', 'tecnico', 'Dominio del balón con las dos manos', 'Driblar entre conos cambiando de mano (por delante, por detrás, entre piernas). Mirada al frente.', 10, 'Filas de 4', 'Un balón por jugador, 8 conos', 'Todas'],
            ['Tiro en 5 posiciones', 'tecnico', 'Mecánica de tiro y rutina', 'Cinco tiros desde cada posición alrededor de la llave; se cuenta cuántos entran por ronda.', 15, 'Parejas (uno tira, otro rebotea)', 'Balones, aro', 'Sub-9 en adelante'],
            ['Bandeja derecha e izquierda', 'tecnico', 'Dos pasos y bandeja por ambos lados', 'Filas en los costados; entrada con dos pasos y bandeja. Cambiar de lado cada 5 intentos.', 12, 'Dos filas', 'Balones, aro', 'Todas'],
            ['3 vs 2 continuo', 'tactico', 'Superioridad numérica y toma de decisiones', 'Tres atacan contra dos; quien recupera sale con dos compañeros hacia el otro aro.', 15, '9 a 12', 'Balones, cancha completa', 'Sub-10 en adelante'],
            ['Defensa en pasos laterales', 'fisico', 'Postura defensiva y desplazamiento', 'Desplazamientos laterales sin cruzar los pies entre dos líneas, con reacción a la señal del entrenador.', 10, 'Todo el grupo', 'Conos', 'Todas'],
            ['Pívots: juego de espaldas al aro', 'arquero', 'Recepción en el poste y giro', 'Recibir en el poste bajo, sentir al defensor, giro y tiro o pase al perímetro.', 12, 'Pívots y aleros', 'Balones, aro', 'Sub-12 en adelante'],
            ['Partido 3 vs 3 a medio campo', 'juego', 'Aplicar lo entrenado', 'Partidos cortos a 11 puntos; el ganador se queda.', 20, '6 a 12', 'Petos, balones', 'Todas'],
        ],
        'voley' => [
            ['Toque de dedos en parejas', 'tecnico', 'Técnica del toque y control', 'Parejas a 4 m; toque de dedos continuo, luego con desplazamiento lateral.', 10, 'Parejas', 'Una pelota por pareja', 'Todas'],
            ['Recepción de antebrazos al blanco', 'tecnico', 'Recepción del saque hacia la zona del armador', 'El entrenador saca y el jugador recibe hacia un aro colocado en la zona 3.', 12, 'Filas de 4', 'Pelotas, aro o cono', 'Sub-9 en adelante'],
            ['Saque por zonas', 'tecnico', 'Precisión del saque', 'La cancha contraria dividida en 6 zonas con conos; el entrenador canta la zona a la que hay que sacar.', 12, 'Todo el grupo', 'Pelotas, conos', 'Todas'],
            ['Ataque tras colocada en zona 4', 'tecnico', 'Carrera de ataque y remate', 'Colocada del armador a zona 4; carrera de 3 pasos, salto y remate a zona libre.', 15, 'Filas de 5 + armador', 'Pelotas, red', 'Sub-11 en adelante'],
            ['Recepción en W', 'tactico', 'Ubicación de los receptores', 'Cinco jugadores en W reciben el saque; rotan cada 5 recepciones.', 15, '6 a 12', 'Pelotas, red', 'Sub-11 en adelante'],
            ['Líbero: defensa de campo', 'arquero', 'Defensa baja y lectura del ataque', 'El entrenador ataca desde una mesa; el líbero defiende y dirige la pelota al armador.', 12, 'Líberos y defensores', 'Pelotas, mesa o cajón', 'Sub-12 en adelante'],
            ['Partido reducido 3 vs 3', 'juego', 'Juego con continuidad', 'Cancha reducida, obligatorio usar los tres toques.', 20, '6 a 12', 'Pelotas, red', 'Todas'],
        ],
        'boxeo' => [
            ['Salto de cuerda por rounds', 'calentamiento', 'Coordinación, ritmo y calentamiento', 'Tres rounds de 2 minutos con 30 s de pausa: pies juntos, alternado y doble salto.', 9, 'Todo el grupo', 'Cuerdas', 'Todas'],
            ['Sombra técnica', 'tecnico', 'Guardia, desplazamientos y combinaciones sin oponente', 'Tres rounds de 2 minutos frente al espejo; el entrenador canta las combinaciones (1-2, 1-2-3, jab-esquiva).', 9, 'Todo el grupo', 'Espejo o espacio libre', 'Todas'],
            ['Combinaciones en el saco', 'tecnico', 'Potencia y precisión', 'Rounds de 2 minutos en el saco con combinaciones marcadas; el último minuto a máxima velocidad.', 12, 'Uno por saco', 'Sacos, guantes, vendas', 'Sub-10 en adelante'],
            ['Trabajo de manoplas', 'tecnico', 'Precisión, distancia y reacción', 'El entrenador (o un compañero) marca con las manoplas los golpes y las esquivas.', 12, 'Parejas', 'Manoplas, guantes', 'Sub-10 en adelante'],
            ['Esquivas con cuerda', 'tecnico', 'Movimiento de cintura y cabeza', 'Una cuerda a la altura del hombro; avanzar esquivando en U bajo la cuerda y lanzando golpes.', 8, 'Filas', 'Una cuerda larga', 'Todas'],
            ['Circuito de fuerza y core', 'fisico', 'Fuerza de tronco y resistencia', 'Planchas, abdominales, flexiones y sentadillas por estaciones de 40 s.', 12, 'Todo el grupo', 'Colchonetas', 'Todas'],
            ['Sparring técnico controlado', 'arquero', 'Aplicar la técnica frente a un oponente', 'Rounds cortos a baja intensidad con casco y protector, con consignas del entrenador. Solo con autorización del representante.', 9, 'Parejas de peso parecido', 'Casco, guantes de 12–16 oz, protector bucal', 'Sub-13 en adelante'],
        ],
        'artes_marciales' => [
            ['Movilidad y estiramiento dinámico', 'calentamiento', 'Preparar cadera y piernas para patear', 'Rotaciones, balanceos de pierna y estiramiento dinámico progresivo.', 10, 'Todo el grupo', '—', 'Todas'],
            ['Bases y desplazamientos', 'tecnico', 'Posiciones firmes y cambios de guardia', 'Recorrer el tatami en las bases del estilo, con cambios de guardia a la voz.', 10, 'Todo el grupo', 'Tatami', 'Todas'],
            ['Patadas en la paleta', 'tecnico', 'Técnica y velocidad de patada', 'Parejas: uno sostiene la paleta y el otro patea (circular, frontal, lateral) a la señal.', 12, 'Parejas', 'Paletas', 'Todas'],
            ['Forma (kata / poomsae) por partes', 'tecnico', 'Memoria y precisión de la forma', 'Practicar la forma del grado en bloques de 4 movimientos y luego completa.', 15, 'Todo el grupo', '—', 'Todas'],
            ['Defensa personal: liberaciones', 'tactico', 'Liberarse de agarres de muñeca y ropa', 'Parejas practican liberaciones sencillas y salida segura.', 12, 'Parejas', 'Tatami', 'Sub-10 en adelante'],
            ['Combate por puntos controlado', 'arquero', 'Distancia, timing y control', 'Combates de 1 minuto a toque controlado, con protecciones.', 12, 'Parejas del mismo grado', 'Peto, casco, protectores', 'Sub-10 en adelante'],
        ],
        'natacion' => [
            ['Calentamiento en seco y nado suave', 'calentamiento', 'Activar hombros y respiración', 'Movilidad de hombros fuera del agua y 100–200 m de nado suave.', 10, 'Todo el grupo', '—', 'Todas'],
            ['Patada con tabla', 'tecnico', 'Patada eficiente desde la cadera', 'Series de 4 × 25 m patada de crol con tabla, cabeza alineada.', 12, 'Por andariveles', 'Tablas', 'Todas'],
            ['Crol: respiración bilateral', 'tecnico', 'Respirar a ambos lados cada 3 brazadas', 'Series cortas contando brazadas y respiraciones; corrección de la rotación.', 12, 'Por andariveles', '—', 'Sub-8 en adelante'],
            ['Espalda con rotación de hombros', 'tecnico', 'Brazada de espalda y posición', 'Nado de espalda con un brazo, luego completo, con mirada al techo.', 10, 'Por andariveles', '—', 'Todas'],
            ['Salidas y virajes', 'arquero', 'Salida desde el borde o el poyete y viraje', 'Practicar salidas cortas y virajes de voltereta o toque según el estilo.', 12, 'Por andariveles', '—', 'Sub-9 en adelante'],
            ['Series 4 × 25 m', 'fisico', 'Velocidad y resistencia', 'Cuatro repeticiones de 25 m con 30 s de pausa, anotando los tiempos.', 10, 'Por andariveles', 'Cronómetro', 'Sub-8 en adelante'],
            ['Juegos acuáticos', 'juego', 'Confianza y diversión en el agua', 'Relevos, buscar objetos en el fondo y juegos de flotación.', 10, 'Todo el grupo', 'Aros, pelotas', 'Sub-5 a Sub-10'],
        ],
        'atletismo' => [
            ['Técnica de carrera (ABC)', 'calentamiento', 'Postura y frecuencia de zancada', 'Skipping, talones, rodillas arriba y zancada, 3 × 20 m cada uno.', 12, 'Todo el grupo', 'Conos', 'Todas'],
            ['Salidas de pie y de tacos', 'tecnico', 'Reacción y primeros pasos', 'Salidas a la voz y a la palmada, 6 repeticiones de 15 m.', 10, 'Filas', 'Tacos (opcional)', 'Todas'],
            ['Series de velocidad 4 × 40 m', 'fisico', 'Velocidad máxima', 'Cuatro carreras de 40 m con pausa completa caminando.', 10, 'Todo el grupo', 'Cronómetro, conos', 'Sub-8 en adelante'],
            ['Salto largo: carrera y batida', 'arquero', 'Batida en la tabla y vuelo', 'Carreras cortas de 6 pasos a la tabla y caída en la arena; luego con carrera completa.', 15, 'Filas', 'Foso de arena', 'Todas'],
            ['Lanzamiento de pelota', 'tecnico', 'Técnica de lanzamiento por encima del hombro', 'Lanzar con 3 pasos de impulso; medir la mejor marca.', 12, 'Filas', 'Pelotas de 150 g, cinta métrica', 'Todas'],
            ['Relevos con testimonio', 'juego', 'Entrega del testimonio y trabajo en equipo', 'Relevos 4 × 50 m practicando la zona de entrega.', 15, 'Equipos de 4', 'Testimonios', 'Todas'],
        ],
        'tenis' => [
            ['Mini tenis y movilidad', 'calentamiento', 'Activar piernas y tacto con la pelota', 'Peloteo suave en el cuadro de saque, con desplazamientos cortos.', 10, 'Parejas', 'Raquetas, pelotas', 'Todas'],
            ['Derecha y revés cruzados', 'tecnico', 'Consistencia desde el fondo', 'Peloteo en diagonal apuntando a un cono; cambiar de diagonal cada 3 minutos.', 15, 'Parejas', 'Raquetas, pelotas, conos', 'Todas'],
            ['Saque por zonas', 'arquero', 'Precisión del saque', 'Series de saques a la T, al cuerpo y abiertos; se anotan los que entran.', 12, 'Filas', 'Canasta de pelotas', 'Sub-9 en adelante'],
            ['Volea en la red', 'tecnico', 'Voleas cortas y firmes', 'El entrenador alimenta desde el fondo; volea de derecha y revés hacia las esquinas.', 12, 'Filas', 'Pelotas', 'Sub-9 en adelante'],
            ['Carreras laterales con recogida', 'fisico', 'Desplazamiento y cambio de dirección', 'Desplazamientos laterales tocando conos en las líneas y recogida de pelotas.', 10, 'Todo el grupo', 'Conos', 'Todas'],
            ['Puntos jugados (tie-break)', 'juego', 'Aplicar lo entrenado en competencia', 'Tie-breaks a 7 puntos; el ganador sube de cancha.', 20, 'Parejas', 'Raquetas, pelotas', 'Todas'],
        ],
        'padel' => [
            ['Peloteo en la red', 'calentamiento', 'Tacto y reflejos', 'Voleas suaves en parejas desde la red, cada pareja en su lado.', 10, 'Parejas', 'Palas, pelotas', 'Todas'],
            ['Salida de pared de fondo', 'tecnico', 'Leer el rebote y devolver', 'El entrenador lanza a la pared; el jugador espera el rebote y devuelve cruzado.', 15, 'Filas', 'Pelotas', 'Todas'],
            ['Bandeja desde el globo', 'arquero', 'Mantener la red con la bandeja', 'Globos del entrenador; bandeja cortada a la reja lateral contraria.', 12, 'Filas', 'Pelotas', 'Sub-11 en adelante'],
            ['Defensa y subida a la red', 'tactico', 'Moverse en pareja', 'Pareja defiende desde el fondo y sube a la red tras un globo profundo.', 15, 'Parejas', 'Pelotas', 'Sub-11 en adelante'],
            ['Puntos 2 vs 2', 'juego', 'Aplicar lo entrenado', 'Puntos jugados con saque; la pareja ganadora se queda.', 20, '4 a 8', 'Palas, pelotas', 'Todas'],
        ],
        'tenis_mesa' => [
            ['Peloteo libre', 'calentamiento', 'Ritmo y tacto', 'Peloteo suave de derecha y de revés.', 8, 'Parejas', 'Paletas, pelotas', 'Todas'],
            ['Derecha en diagonal', 'tecnico', 'Consistencia del drive', 'Drive de derecha en diagonal sin fallar; contar golpes seguidos.', 12, 'Parejas', 'Paletas, pelotas', 'Todas'],
            ['Saques con efecto', 'arquero', 'Saques cortos y largos con corte y top', 'Series de 10 saques a zonas marcadas.', 12, 'Individual', 'Canasta de pelotas', 'Sub-9 en adelante'],
            ['Falkenberg', 'tecnico', 'Desplazamiento y cambio derecha-revés', 'Revés, derecha desde el lado del revés y derecha abierta, en bucle.', 12, 'Parejas', 'Paletas, pelotas', 'Sub-10 en adelante'],
            ['Partidos a 11', 'juego', 'Competir', 'Partidos cortos a 11 puntos; el ganador sube de mesa.', 20, 'Parejas', 'Mesas, paletas', 'Todas'],
        ],
        'bolos' => [
            ['Aproximación de 4 pasos sin bola', 'calentamiento', 'Ritmo de los pasos', 'Practicar los 4 pasos y la soltada sin bola frente a la línea.', 8, 'Todo el grupo', '—', 'Todas'],
            ['Tiro a una flecha', 'tecnico', 'Línea de tiro', 'Apuntar a una flecha del carril y repetir la misma línea 10 veces.', 15, 'Por carril', 'Bolas', 'Todas'],
            ['Spares de pino suelto', 'arquero', 'Convertir pinos sueltos', 'Colocar solo el pino 7 o el 10 y practicar el tiro cruzado.', 15, 'Por carril', 'Bolas', 'Todas'],
            ['Línea completa con anotación', 'juego', 'Competir y anotar', 'Jugar una línea completa anotando cada cuadro.', 20, 'Por carril', 'Bolas, hoja de anotación', 'Todas'],
        ],
        'ciclismo' => [
            ['Calentamiento en rodillo o vuelta suave', 'calentamiento', 'Activar piernas', '10 minutos de pedaleo suave aumentando la cadencia.', 10, 'Todo el grupo', 'Bicicletas, casco', 'Todas'],
            ['Slalom y frenado', 'tecnico', 'Manejo y frenado seguro', 'Recorrido entre conos con frenadas en una zona marcada.', 15, 'Todo el grupo', 'Conos, casco', 'Todas'],
            ['Curvas en circuito', 'tecnico', 'Trazada y posición del cuerpo', 'Circuito con curvas a ambos lados; mirada a la salida de la curva.', 15, 'Filas', 'Conos, casco', 'Sub-9 en adelante'],
            ['Series de sprint', 'fisico', 'Potencia y velocidad', 'Seis sprints de 100 m con recuperación pedaleando suave.', 15, 'Todo el grupo', 'Cronómetro, casco', 'Sub-10 en adelante'],
            ['Rodada en grupo', 'juego', 'Ir a rueda y relevos', 'Rodada en fila con relevos cada minuto y señales de seguridad.', 20, 'Grupos de 4 a 6', 'Bicicletas, casco', 'Sub-11 en adelante'],
        ],
        default => ejercicios_fabrica_v36($familia),
    };
}

/**
 * Carga lo de fábrica (tests, habilidades y ejercicios) de un deporte si la escuela todavía no tiene nada de él.
 * Se llama al crear un grupo de un deporte nuevo y al preparar la escuela.
 */
function preparar_deporte(int $lid, string $deporte): void
{
    $fam = $deporte === '' ? '' : familia_deporte($deporte);
    $ordt = (int)valor('SELECT COALESCE(MAX(orden), 0) FROM esc_tests WHERE liga_id = ?', [$lid]);
    if (!valor('SELECT COUNT(*) FROM esc_tests WHERE liga_id = ? AND deporte = ?', [$lid, $fam])) {
        foreach (tests_fabrica($fam) as $i => [$n, $u, $m, $t, $d]) {
            if (valor('SELECT COUNT(*) FROM esc_tests WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $n])) continue;
            q('INSERT INTO esc_tests (liga_id, nombre, unidad, mejor, tipo, descripcion, orden, deporte) VALUES (?,?,?,?,?,?,?,?)', [$lid, $n, $u, $m, $t, $d, $ordt + $i + 1, $fam]);
        }
    }
    if (!valor('SELECT COUNT(*) FROM esc_habilidades WHERE liga_id = ? AND deporte = ?', [$lid, $fam])) {
        $ordh = (int)valor('SELECT COALESCE(MAX(orden), 0) FROM esc_habilidades WHERE liga_id = ?', [$lid]);
        foreach (habilidades_fabrica($fam) as $i => $n) q('INSERT INTO esc_habilidades (liga_id, nombre, orden, deporte) VALUES (?,?,?,?)', [$lid, $n, $ordh + $i + 1, $fam]);
    }
    if (!valor('SELECT COUNT(*) FROM esc_ejercicios WHERE liga_id = ? AND deporte = ?', [$lid, $fam])) {
        foreach (ejercicios_fabrica($fam) as [$n, $t, $o, $d, $m, $j, $mat, $ed]) {
            q("INSERT INTO esc_ejercicios (liga_id, nombre, tipo, objetivo, descripcion, duracion, jugadores, materiales, edades, registrado_por, deporte) VALUES (?,?,?,?,?,?,?,?,?, 'Biblioteca inicial', ?)", [$lid, $n, $t, $o, $d, $m, $j, $mat, $ed, $fam]);
        }
    }
}

/** Todo lo de fábrica de los deportes que la escuela usa (y lo general). */
function preparar_deportes_escuela(array $liga): void
{
    preparar_deporte((int)$liga['id'], '');
    foreach (deportes_de_escuela($liga) as $d) preparar_deporte((int)$liga['id'], $d);
}

/** Etiqueta chiquita con el ícono y el deporte (para listas). */
function chip_deporte(string $d): string
{
    return '<span class="chip-deporte" title="' . e(nombre_deporte_escuela($d)) . '">' . icono_deporte_escuela($d) . ' ' . e(nombre_deporte_corto($d)) . '</span>';
}

/** Deporte al que pertenece un test/habilidad/ejercicio ('' = todos). */
function texto_familia(string $fam): string
{
    if ($fam === '') return 'Todos los deportes';
    foreach (catalogo_deportes() as $k => [$n, $i, $f]) if ($f === $fam) return $i . ' ' . ($fam === 'futbol' ? 'Fútbol / indor' : ($fam === 'voley' ? 'Vóley / ecuavóley' : nombre_deporte_corto($k)));
    return $fam;
}

/** Familias disponibles para asignar a un test o ejercicio (con su nombre). */
function familias_opciones(): array
{
    $r = ['' => 'Todos los deportes'];
    foreach (catalogo_deportes() as $k => [, , $f]) if ($f !== 'otro') $r[$f] ??= texto_familia($f);
    return $r;
}

/** SQL: el alumno (alias $a) está en el grupo o grupos indicados, como principal o como grupo extra. */
function sql_en_grupo(string $a, int|array $gids): string
{
    $ids = array_values(array_filter(array_map('intval', (array)$gids)));
    if (!$ids) return '0';
    $in = implode(',', $ids);
    return "($a.grupo_id IN ($in) OR $a.id IN (SELECT alumno_id FROM esc_alumno_grupos WHERE grupo_id IN ($in)))";
}

function alumno_en_grupo(int $aid, int $gid): bool
{
    return (bool)valor('SELECT COUNT(*) FROM esc_alumnos a WHERE a.id = ? AND ' . sql_en_grupo('a', $gid), [$aid]);
}

/** Ids de todos los grupos de un alumno (principal primero). */
function ids_grupos_alumno(array $a): array
{
    return array_map(fn($g) => (int)$g['id'], grupos_alumno((int)$a['id']));
}

/** Próximas clases planificadas de TODOS los grupos del alumno. */
function proximas_clases_alumno(array $a, int $n = 3, bool $familia = true): array
{
    $ids = ids_grupos_alumno($a);
    if (!$ids) return [];
    return filas("SELECT s.*, g.nombre AS grupo, g.deporte FROM esc_sesiones s JOIN esc_grupos g ON g.id = s.grupo_id WHERE s.grupo_id IN (" . implode(',', $ids) . ") AND s.fecha >= ? AND s.estado = 'planificada'"
        . ($familia ? ' AND s.visible_familia = 1' : '') . ' ORDER BY s.fecha, g.hora_inicio LIMIT ' . (int)$n, [date('Y-m-d')]);
}

/** Texto con los otros grupos del alumno (para fichas y listas): "También en: Básquet mixto (🏀)". */
function texto_otros_grupos(array $a, ?array $liga = null): string
{
    $o = array_values(array_filter(grupos_alumno((int)$a['id']), fn($g) => !(int)$g['principal']));
    if (!$o) return '';
    return 'También en: ' . implode(', ', array_map(fn($g) => icono_deporte_escuela(deporte_grupo($g, $liga)) . ' ' . $g['nombre'], $o));
}

/** Habilidades de la evaluación técnica de un deporte (las del deporte primero y las generales al final). */
function habilidades_deporte(int $lid, string $deporte): array
{
    return filas("SELECT * FROM esc_habilidades WHERE liga_id = ? AND activo = 1 AND " . sql_deporte([$deporte]) . " ORDER BY deporte = '', orden, id", [$lid]);
}

/** Tests activos que sirven para uno o varios deportes (y los generales). */
function tests_deportes(int $lid, array $deportes): array
{
    return filas('SELECT * FROM esc_tests WHERE liga_id = ? AND activo = 1 AND ' . sql_deporte($deportes) . ' ORDER BY orden, nombre', [$lid]);
}

/** (v21) Etiquetas de la ficha de un partido del Club según el deporte del grupo. */
function etiquetas_partido(string $fam): array
{
    return match ($fam) {
        'basquet' => ['marc' => 'Puntos', 'g' => 'Pts', 'a' => 'Asist.', 'c3' => 'Reb.', 'c4' => 'Faltas', 'tarjetas' => false, 'sets' => false],
        'voley' => ['marc' => 'Sets', 'g' => 'Pts', 'a' => 'Aces', 'c3' => 'Bloq.', 'c4' => '—', 'tarjetas' => false, 'sets' => true],
        'futbol' => ['marc' => 'Goles', 'g' => 'Goles', 'a' => 'Asist.', 'c3' => '🟨', 'c4' => '🟥', 'tarjetas' => true, 'sets' => false],
        default => ['marc' => 'Puntos', 'g' => 'Pts', 'a' => 'Asist.', 'c3' => '🟨', 'c4' => '🟥', 'tarjetas' => true, 'sets' => false],
    };
}

/** (v21) Chips de los deportes del alumno, "También en…" y su cinturón (para fichas y el portal de padres). */
function html_deportes_alumno(array $a, ?array $liga = null): string
{
    $h = '';
    $deps = deportes_alumno($a, $liga);
    if (count($deps) > 1 || ($liga && es_multideporte($liga))) $h .= implode(' ', array_map('chip_deporte', $deps));
    $o = texto_otros_grupos($a, $liga);
    if ($o !== '') $h .= ' <small class="suave">' . e($o) . '</small>';
    $gr = grado_actual((int)$a['id']);
    if ($gr) $h .= ' <span class="chip-deporte">🥋 ' . e($gr['grado']) . '</span>';
    return $h === '' ? '' : '<div class="deportes-alumno">' . $h . '</div>';
}

/** (v21) Último grado (cinturón) del alumno. */
function grado_actual(int $aid): ?array
{
    return fila('SELECT * FROM esc_grados WHERE alumno_id = ? ORDER BY fecha DESC, id DESC LIMIT 1', [$aid]);
}

/** (v21) ¿La escuela tiene grupos de este tipo de club ('combate' / 'marcas')? */
function escuela_tiene_tipo(array $liga, string $tipo): bool
{
    foreach (deportes_de_escuela($liga) as $d) if (tipo_club_deporte($d) === $tipo) return true;
    return false;
}

/** (v21) Categorías de peso de referencia (boxeo olímpico). */
function categorias_peso(string $genero = 'M'): array
{
    return $genero === 'F' ? ['48', '50', '52', '54', '57', '60', '63', '66', '70', '75', '81', '+81'] : ['48', '51', '54', '57', '60', '63.5', '67', '71', '75', '80', '86', '92', '+92'];
}

/** (v21) Categoría sugerida para un peso (kg). */
function categoria_para_peso(?float $kg, string $genero = 'M'): string
{
    if (!$kg) return '';
    foreach (categorias_peso($genero) as $c) if ($c[0] !== '+' && $kg <= (float)$c) return $c . ' kg';
    return end(categorias_peso($genero)) . ' kg';
}

function grados_referencia(): array
{
    return ['Blanco', 'Amarillo', 'Naranja', 'Verde', 'Azul', 'Morado', 'Marrón', 'Rojo', 'Negro 1.º Dan', 'Negro 2.º Dan', 'Negro 3.º Dan'];
}

function pruebas_referencia(string $fam): array
{
    if ($fam === 'bolos') return ['Línea', 'Serie de 3 líneas', 'Serie de 6 líneas'];
    if ($fam === 'ciclismo') return ['Contrarreloj 5 km', 'Contrarreloj 10 km', 'Ruta 20 km', 'Ruta 40 km', 'Circuito (vueltas)', 'BMX (manga)'];
    return $fam === 'natacion'
        ? ['25 m libre', '50 m libre', '100 m libre', '25 m espalda', '50 m espalda', '25 m pecho', '50 m pecho', '25 m mariposa', '50 m mariposa', '100 m combinado']
        : ['60 m', '80 m', '100 m', '200 m', '400 m', '600 m', '800 m', '1000 m', '1500 m', 'Salto largo', 'Salto alto', 'Lanzamiento de bala', 'Lanzamiento de pelota'];
}

/** (v21) "1:05.30" → 65.30 segundos; "4,85" → 4.85. */
function marca_a_valor(string $m): ?float
{
    $m = trim(str_replace(',', '.', $m));
    if ($m === '') return null;
    if (preg_match('/^(\d+):(\d{1,2}(?:\.\d+)?)$/', $m, $x)) return (int)$x[1] * 60 + (float)$x[2];
    return is_numeric($m) ? (float)$m : null;
}

/** (v21) Resultado de un combate / partido individual. */
function nombres_resultado(): array
{
    return ['gano' => '✅ Ganó', 'perdio' => '❌ Perdió', 'empate' => '➖ Empate', 'sin_decision' => '— Sin decisión'];
}

function metodos_combate(string $fam): array
{
    return match ($fam) {
        'boxeo' => ['Puntos', 'Decisión unánime', 'Decisión dividida', 'KO', 'TKO / RSC', 'Descalificación', 'Abandono'],
        'artes_marciales' => ['Puntos', 'Ippon', 'Superioridad', 'Decisión', 'Descalificación', 'Abandono'],
        'tenis', 'padel', 'tenis_mesa' => ['Sets', 'Retiro del rival', 'W.O.'],
        default => metodos_combate_v36($fam) ?? ['Puntos', 'Decisión'],
    };
}

/** (v21) Récord G-P-E de un alumno en combates / partidos individuales. */
function record_alumno(int $aid): array
{
    $r = fila("SELECT SUM(resultado = 'gano') AS g, SUM(resultado = 'perdio') AS p, SUM(resultado = 'empate') AS e, COUNT(*) AS n FROM esc_competencias WHERE alumno_id = ? AND tipo = 'combate'", [$aid]);
    return ['g' => (int)$r['g'], 'p' => (int)$r['p'], 'e' => (int)$r['e'], 'n' => (int)$r['n']];
}

/** (v21) Medallas de un alumno en pruebas. */
function medallas_alumno(int $aid): array
{
    $r = ['oro' => 0, 'plata' => 0, 'bronce' => 0];
    foreach (filas("SELECT medalla, COUNT(*) AS n FROM esc_competencias WHERE alumno_id = ? AND tipo = 'prueba' AND medalla <> '' GROUP BY medalla", [$aid]) as $x) $r[$x['medalla']] = (int)$x['n'];
    return $r;
}

function medalla_de_puesto(?int $p): string
{
    return [1 => 'oro', 2 => 'plata', 3 => 'bronce'][$p] ?? '';
}

function icono_medalla(string $m): string
{
    return ['oro' => '🥇', 'plata' => '🥈', 'bronce' => '🥉'][$m] ?? '';
}

/** (v21) Palabra para las competencias individuales según el deporte. */
function texto_combate(string $fam): array
{
    if ($fam === 'tenis_mesa') return ['🏓', 'Partidos', 'partido', 'Marcador (sets)'];
    if ($x = texto_combate_v36($fam)) return $x; // (v36)
    return in_array($fam, ['tenis', 'padel'], true) ? ['🎾', 'Partidos', 'partido', 'Marcador (sets)'] : ['🥊', 'Combates', 'combate', 'Rounds'];
}

/** (v21) Tarjeta con los combates / partidos individuales, las medallas y el cinturón del alumno (ficha y portal de padres). */
function html_competencias_alumno(array $a): string
{
    $aid = (int)$a['id'];
    $r = record_alumno($aid);
    $m = medallas_alumno($aid);
    $gr = grado_actual($aid);
    $ult = filas('SELECT * FROM esc_competencias WHERE alumno_id = ? ORDER BY fecha DESC, id DESC LIMIT 6', [$aid]);
    if (!$r['n'] && !array_sum($m) && !$gr && !$ult) return '';
    $h = '<div class="tarjeta"><h3 style="margin-top:0">🥇 Competencias</h3><div class="cifras">';
    if ($r['n']) $h .= '<div class="cifra"><b>' . $r['g'] . '-' . $r['p'] . '-' . $r['e'] . '</b>Récord (ganó-perdió-empató)</div>';
    if (array_sum($m)) $h .= '<div class="cifra"><b>🥇' . $m['oro'] . ' 🥈' . $m['plata'] . ' 🥉' . $m['bronce'] . '</b>Medallas</div>';
    if ($gr) $h .= '<div class="cifra"><b>🥋 ' . e($gr['grado']) . '</b>Cinturón desde ' . e(fecha_corta($gr['fecha'])) . '</div>';
    $h .= '</div>';
    if ($ult) {
        $h .= '<table class="tabla-comp">';
        foreach ($ult as $c) {
            $h .= '<tr><td>' . e(fecha_corta($c['fecha'])) . '</td><td>' . ($c['deporte'] ? icono_deporte_escuela($c['deporte']) . ' ' : '');
            if ($c['tipo'] === 'combate') {
                $h .= 'vs <b>' . e($c['rival']) . '</b>' . ($c['evento'] ? '<br><small class="suave">' . e($c['evento']) . '</small>' : '') . '<br>' . e(nombres_resultado()[$c['resultado']] ?? '')
                    . ($c['metodo'] ? ' · ' . e($c['metodo']) : '') . ($c['marca'] ? ' · ' . e($c['marca']) : '') . '</td>';
            } else {
                $h .= '<b>' . e($c['prueba']) . '</b>' . ($c['evento'] ? '<br><small class="suave">' . e($c['evento']) . '</small>' : '') . '<br><b>' . e($c['marca']) . ($c['unidad'] === 'm' ? ' m' : ($c['unidad'] === 'p' ? ' pts' : '')) . '</b>'
                    . ($c['puesto'] ? ' · ' . (int)$c['puesto'] . 'º ' . icono_medalla($c['medalla']) : '') . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
    }
    return $h . '</div>';
}
