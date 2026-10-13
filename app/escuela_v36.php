<?php
/*
 * (v36) Deportes nuevos en las ESCUELAS: squash, ajedrez, e-sports, levantamiento de pesas, triatlón, béisbol, softbol y balonmano.
 * Lo de fábrica (tests, habilidades, ejercicios, métodos de victoria) de cada uno. multideporte.php lo usa en sus «default».
 */

/** Deportes nuevos para catalogo_deportes(): clave => [nombre, ícono, familia, tipo de club]. */
function catalogo_deportes_v36(): array
{
    return [
        'squash' => ['Squash', '🎾', 'squash', 'combate'],
        'ajedrez' => ['Ajedrez', '♟️', 'ajedrez', 'combate'],
        'esports' => ['E-sports (videojuegos)', '🎮', 'esports', 'combate'],
        'pesas' => ['Levantamiento de pesas', '🏋️', 'pesas', 'marcas'],
        'triatlon' => ['Triatlón', '🏊', 'triatlon', 'marcas'],
        'beisbol' => ['Béisbol', '⚾', 'beisbol', 'equipo'],
        'softbol' => ['Softbol', '🥎', 'beisbol', 'equipo'],
        'balonmano' => ['Balonmano (handball)', '🤾', 'balonmano', 'equipo'],
    ];
}

function nombre_especifico_v36(string $f): ?array
{
    return [
        'squash' => ['🎾', 'Saque, volea y paredes'], 'ajedrez' => ['♟️', 'Aperturas y finales'], 'esports' => ['🎮', 'Táctica del juego'],
        'pesas' => ['🏋️', 'Arranque y envión'], 'triatlon' => ['🔁', 'Transiciones'], 'beisbol' => ['⚾', 'Pitcheo y receptor'], 'balonmano' => ['🧤', 'Porteros'],
    ][$f] ?? null;
}

/** Tests: [nombre, unidad, mejor, tipo, descripción]. */
function tests_fabrica_v36(string $f): array
{
    return match ($f) {
        'squash' => [
            ['Saques al cuadro (de 10)', 'rep', 'mayor', 'tecnico', 'Diez saques al cuarto de cancha contrario por encima de la línea de saque.'],
            ['Drives paralelos sin fallar', 'rep', 'mayor', 'tecnico', 'Golpes seguidos de drive pegados a la pared lateral, sin que la pelota se detenga.'],
            ['Boasts al blanco (de 10)', 'rep', 'mayor', 'tecnico', 'Golpe a tres paredes que cae en la zona marcada adelante.'],
            ['Ghosting 6 esquinas (1 min)', 'rep', 'mayor', 'fisico', 'Desplazamientos a las 6 esquinas desde la T sin pelota, contando toques en un minuto.'],
        ],
        'ajedrez' => [
            ['Problemas de mate en 1 (de 10)', 'rep', 'mayor', 'tecnico', 'Diez posiciones con mate en una jugada, 5 minutos.'],
            ['Problemas de mate en 2 (de 10)', 'rep', 'mayor', 'tecnico', 'Diez posiciones con mate en dos jugadas, 15 minutos.'],
            ['Táctica: ejercicios resueltos (de 20)', 'rep', 'mayor', 'tecnico', 'Clavadas, horquillas, ataques dobles y descubiertos.'],
            ['Rating de partidas rápidas', 'pts', 'mayor', 'tecnico', 'ELO de la plataforma en línea o de los torneos internos.'],
        ],
        'esports' => [
            ['Partidos ganados (de 10)', 'rep', 'mayor', 'tecnico', 'Diez partidos contra la máquina en dificultad Leyenda.'],
            ['Goles a favor (10 partidos)', 'rep', 'mayor', 'tecnico', 'Goles anotados en una serie de 10 partidos.'],
            ['Tiempo de reacción', 'ms', 'menor', 'fisico', 'Prueba de reacción en línea (promedio de 5).'],
        ],
        'pesas' => [
            ['Arranque (1RM técnico)', 'kg', 'mayor', 'tecnico', 'Mayor peso levantado con buena técnica en arranque.'],
            ['Envión (1RM técnico)', 'kg', 'mayor', 'tecnico', 'Mayor peso levantado con buena técnica en dos tiempos.'],
            ['Sentadilla trasera', 'kg', 'mayor', 'fisico', 'Máximo a una repetición o estimado con 3 repeticiones.'],
            ['Salto vertical', 'cm', 'mayor', 'fisico', 'Mejor de 3 saltos con contramovimiento.'],
            ['Peso corporal', 'kg', 'menor', 'fisico', 'Para la categoría de peso.'],
        ],
        'triatlon' => [
            ['400 m natación', 's', 'menor', 'tecnico', 'Tiempo en piscina, estilo libre.'],
            ['Ciclismo 10 km', 's', 'menor', 'fisico', 'Contrarreloj en circuito cerrado o rodillo.'],
            ['Carrera 2,5 km', 's', 'menor', 'fisico', 'Tiempo en pista o circuito plano.'],
            ['Transición T1 (simulada)', 's', 'menor', 'tecnico', 'Del agua a la bici: casco, zapatillas y salida.'],
        ],
        'beisbol' => [
            ['Carrera de home a primera', 's', 'menor', 'fisico', 'Desde el swing hasta pisar la primera base.'],
            ['Lanzamiento de precisión (de 10)', 'rep', 'mayor', 'tecnico', 'Lanzamientos a la zona de strike desde la distancia de la categoría.'],
            ['Velocidad del lanzamiento', 'km/h', 'mayor', 'tecnico', 'Con radar o aplicación.'],
            ['Batazos de línea (de 10)', 'rep', 'mayor', 'tecnico', 'Lanzamientos suaves; se cuentan los batazos de línea.'],
        ],
        'balonmano' => [
            ['Lanzamiento a portería (de 10)', 'rep', 'mayor', 'tecnico', 'Lanzamientos en apoyo desde 9 m a las esquinas.'],
            ['Velocidad 20 m', 's', 'menor', 'fisico', 'Salida de pie.'],
            ['Lanzamiento de balón medicinal', 'm', 'mayor', 'fisico', 'Balón de 2 kg por encima de la cabeza, sentado.'],
        ],
        default => [],
    };
}

function habilidades_fabrica_v36(string $f): array
{
    return match ($f) {
        'squash' => ['Saque', 'Drive (paralelo)', 'Cruzado', 'Volea', 'Boast y dejada', 'Volver a la T', 'Táctica de juego'],
        'ajedrez' => ['Aperturas', 'Táctica', 'Estrategia', 'Finales', 'Cálculo', 'Manejo del reloj', 'Conducta en la partida'],
        'esports' => ['Control del juego', 'Defensa', 'Ataque y definición', 'Táctica y formaciones', 'Concentración', 'Juego limpio'],
        'pesas' => ['Posición de arranque', 'Primer tirón', 'Segundo tirón', 'Recepción', 'Envión (jerk)', 'Movilidad', 'Fuerza base'],
        'triatlon' => ['Natación en aguas abiertas', 'Ciclismo en grupo', 'Carrera', 'Transiciones', 'Ritmo y dosificación', 'Seguridad vial'],
        'beisbol' => ['Bateo', 'Fildeo', 'Lanzamiento', 'Recepción', 'Corrido de bases', 'Lectura del juego'],
        'balonmano' => ['Pase y recepción', 'Bote', 'Lanzamiento', 'Fintas', 'Defensa', 'Contraataque', 'Portería'],
        default => [],
    };
}

/** Ejercicios: [nombre, tipo, objetivo, descripción, minutos, jugadores, materiales, edades]. */
function ejercicios_fabrica_v36(string $f): array
{
    return match ($f) {
        'squash' => [
            ['Peloteo de calentamiento', 'calentamiento', 'Calentar la pelota y el cuerpo', 'Drives paralelos suaves de cada lado hasta que la pelota tome temperatura.', 8, 'Parejas', 'Raquetas, pelotas de punto amarillo', 'Todas'],
            ['Drive paralelo pegado a la pared', 'tecnico', 'Precisión del drive', 'Series de drives que pasen por el carril lateral; contar los que no salen del carril.', 12, 'Individual o parejas', 'Pelotas, conos', 'Todas'],
            ['Boast y drive', 'tecnico', 'Movimiento adelante-atrás', 'Uno juega boast, el otro drive paralelo; cambian a los 3 minutos.', 12, 'Parejas', 'Pelotas', 'Sub-11 en adelante'],
            ['Ghosting a la T', 'fisico', 'Volver siempre a la T', 'Desplazamientos sin pelota a las esquinas que marca el entrenador, regresando a la T.', 10, 'Todo el grupo', '—', 'Sub-11 en adelante'],
            ['Saque y devolución', 'arquero', 'Saque alto y devolución paralela', 'Series de saques lob y devolución con volea o drive.', 12, 'Parejas', 'Pelotas', 'Todas'],
            ['Partidos a 11', 'juego', 'Competir con las reglas', 'Partidos a 11 puntos con diferencia de 2; el ganador sube de cancha.', 20, 'Parejas', 'Raquetas, gafas protectoras', 'Todas'],
        ],
        'ajedrez' => [
            ['Mates elementales', 'tecnico', 'Dar mate con dama y torre', 'Mate de dama y rey contra rey, luego torre y rey; contra reloj.', 15, 'Parejas', 'Tableros, relojes', 'Todas'],
            ['Táctica del día', 'tecnico', 'Ver jugadas tácticas', 'Diez posiciones en la pizarra: horquillas, clavadas y ataques dobles.', 15, 'Todo el grupo', 'Pizarra mural', 'Todas'],
            ['Principios de apertura', 'tactico', 'Desarrollar, controlar el centro y enrocar', 'Partidas de 10 jugadas comentadas.', 20, 'Parejas', 'Tableros', 'Todas'],
            ['Finales de peones', 'arquero', 'Regla del cuadrado y oposición', 'Posiciones de rey y peón contra rey.', 15, 'Parejas', 'Tableros', 'Sub-10 en adelante'],
            ['Partidas rápidas (blitz)', 'juego', 'Decidir con poco tiempo', 'Partidas de 5 minutos; el ganador sube de mesa.', 25, 'Parejas', 'Tableros, relojes', 'Todas'],
        ],
        'esports' => [
            ['Calentamiento de manos y vista', 'calentamiento', 'Evitar lesiones', 'Estiramiento de muñecas y dedos; pausa visual 20-20-20.', 5, 'Todo el grupo', '—', 'Todas'],
            ['Habilidades: regates', 'tecnico', 'Dominar 3 regates', 'Modo práctica: repetir cada regate 10 veces.', 15, 'Individual', 'Consola o PC', 'Todas'],
            ['Defensa 1 vs 1', 'tactico', 'Contener sin cometer faltas', 'Partidos cortos donde solo cuenta no recibir goles.', 15, 'Parejas', 'Consolas', 'Todas'],
            ['Análisis de un partido', 'tactico', 'Aprender de los errores', 'Ver la repetición de un partido y anotar 3 mejoras.', 15, 'Todo el grupo', 'Pantalla', 'Todas'],
            ['Torneo relámpago', 'juego', 'Competir', 'Partidos de 6 minutos con eliminación directa.', 30, '4 a 16', 'Consolas', 'Todas'],
        ],
        'pesas' => [
            ['Movilidad con palo', 'calentamiento', 'Hombros, cadera y tobillos', 'Pases de hombros, sentadilla con palo arriba y dorsiflexión.', 10, 'Todo el grupo', 'Palos de PVC', 'Todas'],
            ['Arranque por partes', 'tecnico', 'Técnica del arranque', 'Colgado, desde rodilla y desde el piso, con barra técnica.', 20, 'Individual', 'Barra técnica, discos', 'Sub-12 en adelante'],
            ['Cargada y envión', 'tecnico', 'Técnica del envión', 'Cargada colgada y split jerk con poco peso.', 20, 'Individual', 'Barra, discos', 'Sub-12 en adelante'],
            ['Sentadilla frontal', 'fisico', 'Fuerza de piernas', '5 series de 3 repeticiones al 70 %.', 15, 'Individual', 'Barra, rack', 'Sub-14 en adelante'],
            ['Simulacro de competencia', 'juego', 'Practicar los 3 intentos', 'Tres intentos de arranque y tres de envión con jueces.', 30, 'Todo el grupo', 'Tarima, barra, discos', 'Sub-14 en adelante'],
        ],
        'triatlon' => [
            ['Nado con orientación', 'tecnico', 'Mirar al frente sin perder el ritmo', 'Series de 50 m levantando la vista cada 6 brazadas.', 15, 'Por andariveles', '—', 'Todas'],
            ['Transiciones', 'arquero', 'T1 y T2 rápidas', 'Practicar salida del agua, casco, bici y luego bici a correr.', 15, 'Todo el grupo', 'Bicicletas, cascos', 'Todas'],
            ['Ladrillo bici-carrera', 'fisico', 'Correr con piernas cansadas', '10 minutos de bici fuerte y 5 de carrera, 2 veces.', 30, 'Todo el grupo', 'Bicicletas o rodillos', 'Sub-12 en adelante'],
            ['Rodaje en grupo', 'tactico', 'Ir a rueda con seguridad', 'Rodar en fila respetando distancias y señales.', 30, 'Grupos de 6', 'Bicicletas, cascos', 'Sub-12 en adelante'],
        ],
        'beisbol' => [
            ['Lanzar y recibir', 'calentamiento', 'Calentar el brazo', 'Lanzamientos en parejas aumentando la distancia.', 10, 'Parejas', 'Pelotas, guantes', 'Todas'],
            ['Bateo en el tee', 'tecnico', 'Swing nivelado', 'Series de 10 batazos desde el tee.', 15, 'Filas', 'Tee, bates, pelotas', 'Todas'],
            ['Rodados al cuadro', 'tecnico', 'Fildear y tirar a primera', 'El entrenador batea rodados; tiro a primera.', 15, 'Por posiciones', 'Bates, pelotas', 'Todas'],
            ['Corrido de bases', 'fisico', 'Pisar bien las bases', 'Carreras de home a segunda y de primera a tercera.', 10, 'Todo el grupo', 'Bases', 'Todas'],
            ['Partido de 3 entradas', 'juego', 'Aplicar lo entrenado', 'Partido corto rotando posiciones.', 30, '2 equipos', 'Equipo completo', 'Todas'],
        ],
        'balonmano' => [
            ['Pases en movimiento', 'calentamiento', 'Pase y recepción', 'Pases en carrera por parejas a lo ancho de la cancha.', 10, 'Parejas', 'Balones', 'Todas'],
            ['Lanzamiento en suspensión', 'tecnico', 'Lanzar en el aire', 'Tres pasos, salto y lanzamiento desde 9 m.', 15, 'Filas', 'Balones, portería', 'Sub-11 en adelante'],
            ['Defensa 6-0', 'tactico', 'Defender en línea', 'Desplazamientos defensivos en la línea de 6 m.', 15, '6 a 12', 'Conos', 'Sub-12 en adelante'],
            ['Porteros: reacción', 'arquero', 'Reflejos del portero', 'Lanzamientos a distintas alturas desde 7 m.', 12, 'Porteros', 'Balones', 'Todas'],
            ['Partido 7 vs 7', 'juego', 'Aplicar lo entrenado', 'Partido con reglas oficiales, 2 tiempos de 10 minutos.', 25, '14', 'Balones, petos', 'Todas'],
        ],
        default => [],
    };
}

function metodos_combate_v36(string $f): ?array
{
    return ['squash' => ['Games', 'Retiro del rival', 'W.O.'], 'ajedrez' => ['Partida', 'Abandono', 'Tiempo', 'Tablas'], 'esports' => ['Marcador', 'Penales', 'W.O.']][$f] ?? null;
}

function texto_combate_v36(string $f): ?array
{
    return ['squash' => ['🎾', 'Partidos', 'partido', 'Marcador (games)'], 'ajedrez' => ['♟️', 'Partidas', 'partida', 'Resultado (1-0, ½-½, 0-1)'], 'esports' => ['🎮', 'Partidos', 'partido', 'Marcador (goles)']][$f] ?? null;
}
