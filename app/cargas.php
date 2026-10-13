<?php
/*
 * (v27) SUBIDA MASIVA DESDE EXCEL (solo cuentas pagadas de los planes completos: módulo "carga_masiva").
 *
 *  Ligas:    equipos y jugadores (pantalla Importar, que ya existía), árbitros, canchas y competidores de un campeonato individual
 *            (carreras, bolos, tenis/pádel/tenis de mesa/artes marciales/boxeo, natación y atletismo).
 *  Escuelas: sedes, profesores, grupos y alumnos (en ese orden: cada uno usa lo anterior).
 *
 *  Cada tipo tiene: plantilla (.xlsx con hoja "Datos" y hoja "Instrucciones"), revisión fila por fila (nada se guarda)
 *  y confirmación (con la casilla de autorización de datos personales cuando corresponde).
 */
require_once APP_DIR . '/excel.php';

/** Máximo de filas por archivo. */
const CARGA_MAX_FILAS = 3000;

/**
 * Tipos de carga de la cuenta. clave => [ícono, título, descripción, datos personales (bool)].
 * 'equipos' en una liga va a la pantalla Importar (equipos + jugadores).
 */
function tipos_carga(array $liga): array
{
    if (es_escuela($liga)) {
        return [
            'sedes' => ['📍', 'Sedes', 'Los lugares donde entrenan (nombre y dirección).', false],
            'profesores' => ['🧑‍🏫', 'Profesores', 'Nombre, cédula, teléfono, correo, especialidad y cómo se les paga.', true],
            'grupos' => ['👥', 'Grupos', 'Deporte, categoría Sub o abierta, sede, profesor, días, horario, cupo y mensualidad.', false],
            'alumnos' => ['🎓', 'Alumnos', 'Datos del alumno, su grupo, representante, ficha médica y beca.', true],
        ];
    }
    return [
        'equipos' => ['⚽', 'Equipos y jugadores', 'Un jugador por fila con el nombre de su equipo (si el equipo no existe, se crea).', true],
        'arbitros' => ['🧑‍⚖️', 'Árbitros', 'Nombre, cédula, teléfono y si es central o asistente.', true],
        'canchas' => ['🏟', 'Canchas', 'Nombre, dirección y, si las alquila, precio por hora y horario.', false],
        'competidores' => ['🏅', 'Competidores de un campeonato individual', 'Carreras, bolos, tenis, pádel, tenis de mesa, artes marciales, boxeo, natación y atletismo.', true],
    ];
}

/**
 * Columnas de cada tipo: clave => [título, ancho, ejemplo 1, ejemplo 2, obligatoria, cómo llenarla, otros nombres aceptados].
 * $t: campeonato individual (solo para 'competidores': las columnas cambian según el deporte).
 */
function columnas_carga(string $tipo, array $liga, ?array $t = null): array
{
    $anio = (int)date('Y');
    switch ($tipo) {
        case 'arbitros':
            return [
                'nombre' => ['Nombre completo *', 28, 'Luis Andrade Pérez', 'María Torres', true, 'Nombre y apellido del árbitro.', ['nombres', 'arbitro', 'nombre-del-arbitro']],
                'cedula' => ['Cédula', 14, '1712345675', '1715975320', false, '10 números (o pasaporte).', ['ci', 'documento', 'cedula-o-pasaporte']],
                'telefono' => ['Teléfono', 16, '0991234567', '0987654321', false, 'Celular (para WhatsApp).', ['celular', 'whatsapp']],
                'categoria' => ['Categoría', 20, 'Central', 'Asistente', false, 'Escriba: Central, Asistente o Central y asistente.', ['tipo', 'funcion']],
            ];
        case 'canchas':
            return [
                'nombre' => ['Nombre de la cancha *', 26, 'Cancha 1', 'Cancha sintética Norte', true, 'Como la conoce la gente.', ['cancha', 'nombre']],
                'direccion' => ['Dirección', 34, 'Av. Principal y Calle 5', 'Parque La Carolina', false, '', ['ubicacion']],
                'reservable' => ['¿Se alquila por horas? (SI/NO)', 14, 'NO', 'SI', false, 'SI = aparece en la reserva de canchas.', ['reservable', 'alquiler', 'se-alquila']],
                'precio_hora' => ['Precio por hora', 12, '', '25', false, 'Solo el número (ej: 25).', ['precio', 'valor-hora']],
                'abre' => ['Abre (hora)', 10, '', '08:00', false, 'Formato 24 horas: 08:00.', ['desde', 'hora-apertura']],
                'cierra' => ['Cierra (hora)', 10, '', '22:00', false, 'Formato 24 horas: 22:00.', ['hasta', 'hora-cierre']],
                'duracion' => ['Minutos por turno', 10, '', '60', false, 'Ej: 60.', ['duracion', 'minutos']],
            ];
        case 'sedes':
            return [
                'nombre' => ['Nombre de la sede *', 28, 'Sede Norte', 'Complejo Los Chillos', true, '', ['sede', 'nombre']],
                'direccion' => ['Dirección', 40, 'Av. 6 de Diciembre y Portugal', 'Vía a Amaguaña km 3', false, '', ['ubicacion']],
            ];
        case 'profesores':
            return [
                'nombre' => ['Nombre completo *', 28, 'Carlos Mena', 'Andrea Vásquez', true, '', ['nombres', 'profesor', 'entrenador']],
                'cedula' => ['Cédula', 14, '1720345675', '1720356789', false, '10 números (o pasaporte).', ['ci', 'documento']],
                'telefono' => ['Teléfono', 16, '0991112233', '0984445566', false, '', ['celular', 'whatsapp']],
                'email' => ['Correo', 26, 'carlos@correo.com', 'andrea@correo.com', false, '', ['correo', 'e-mail', 'mail']],
                'especialidad' => ['Especialidad', 20, 'Fútbol formativo', 'Natación', false, '', ['deporte']],
                'cargo' => ['Cargo', 16, 'Profesor', 'Coordinadora', false, 'Sale en el rol de pago.', []],
                'tipo_pago' => ['Cómo se le paga', 18, 'Mensual', 'Por clase', false, 'Escriba: Mensual, Por clase o Por alumno.', ['tipo-de-pago', 'pago', 'forma-de-pago']],
                'valor_pago' => ['Valor', 10, '450', '12', false, 'Sueldo del mes, valor por clase o por alumno (solo el número).', ['valor-pago', 'sueldo', 'monto']],
                'iess_pct' => ['% aporte IESS', 10, '9.45', '0', false, '9.45 si está afiliado; 0 si factura.', ['iess', 'aporte-iess']],
            ];
        case 'grupos':
            return [
                'nombre' => ['Nombre del grupo *', 24, 'Sub-10 mañana', 'Natación adultos', true, '', ['grupo', 'nombre']],
                'deporte' => ['Deporte', 16, 'Fútbol', 'Natación', false, 'Fútbol, Indor, Básquet, Vóley, Ecuavóley, Natación, Atletismo, Tenis, Pádel, Tenis de mesa, Boxeo, Artes marciales, Bolos, Ciclismo u Otro.', []],
                'categoria' => ['Categoría (Sub o Abierta)', 14, 'Sub', 'Abierta', false, 'Sub = por año de nacimiento; Abierta = cualquier edad.', ['tipo']],
                'anio_desde' => ['Nacidos desde (año)', 12, (string)($anio - 10), '', false, 'Solo para Sub. Ej: ' . ($anio - 10) . '.', ['ano-desde', 'anio', 'ano', 'desde']],
                'anio_hasta' => ['Nacidos hasta (año)', 12, (string)($anio - 10), '', false, 'Si es un solo año, repita el mismo.', ['ano-hasta', 'hasta']],
                'sede' => ['Sede', 18, 'Sede Norte', 'Sede Norte', false, 'Nombre de una sede. Si no existe, se crea.', []],
                'profesor' => ['Profesor', 20, 'Carlos Mena', 'Andrea Vásquez', false, 'Nombre de un profesor ya cargado.', ['entrenador']],
                'dias' => ['Días', 16, 'Lun, Mié, Vie', 'Sáb', false, 'Separados por coma: Lun, Mar, Mié, Jue, Vie, Sáb, Dom.', ['dias-de-entrenamiento', 'horario-dias']],
                'hora_inicio' => ['Hora inicio', 10, '16:00', '09:00', false, 'Formato 24 horas.', ['inicio', 'desde-hora']],
                'hora_fin' => ['Hora fin', 10, '17:30', '10:00', false, 'Formato 24 horas.', ['fin', 'hasta-hora']],
                'cancha' => ['Cancha / piscina', 16, 'Cancha 2', 'Piscina', false, '', ['lugar']],
                'cupo' => ['Cupo', 8, '25', '15', false, 'Máximo de alumnos (0 = sin límite).', ['cupo-maximo']],
                'mensualidad' => ['Mensualidad del grupo', 12, '', '45', false, 'Vacío = la mensualidad general de la escuela.', ['mensualidad', 'valor', 'pension']],
            ];
        case 'alumnos':
            return [
                'nombres' => ['Nombres *', 20, 'Mateo', 'Valentina', true, '', ['nombre', 'nombres-del-alumno']],
                'apellidos' => ['Apellidos *', 22, 'Andrade Ruiz', 'Paredes León', true, '', ['apellido']],
                'cedula' => ['Cédula', 14, '1719283747', '', false, '10 números (o pasaporte).', ['ci', 'documento']],
                'fecha_nac' => ['Fecha de nacimiento * (día/mes/año)', 18, '15/03/' . ($anio - 10), '02/08/' . ($anio - 8), true, 'Ej: 15/03/2016. Sirve para la categoría Sub.', ['fecha-de-nacimiento', 'nacimiento', 'fecha-nacimiento']],
                'genero' => ['Género (M/F)', 10, 'M', 'F', false, '', ['sexo', 'genero']],
                'grupo' => ['Grupo', 18, 'Sub-10 mañana', '', false, 'Nombre de un grupo. Vacío = el grupo que le toca por su año de nacimiento.', []],
                'representante_nombre' => ['Representante *', 24, 'Rosa Ruiz', 'Juan Paredes', false, 'Obligatorio si es menor de 18.', ['representante', 'nombre-del-representante', 'padre-o-madre']],
                'representante_cedula' => ['Cédula del representante *', 16, '1712345675', '1715975320', false, 'Obligatoria si es menor de 18.', ['cedula-representante']],
                'representante_telefono' => ['Teléfono del representante *', 16, '0991234567', '0987654321', false, 'Obligatorio si es menor de 18.', ['telefono-representante', 'telefono', 'celular']],
                'representante_email' => ['Correo del representante', 24, 'rosa@correo.com', '', false, '', ['correo', 'email', 'correo-del-representante']],
                'parentesco' => ['Parentesco', 12, 'Madre', 'Padre', false, '', []],
                'direccion' => ['Dirección', 26, '', '', false, '', []],
                'colegio' => ['Colegio / escuela', 20, '', '', false, '', ['colegio', 'unidad-educativa']],
                'tipo_sangre' => ['Tipo de sangre', 8, 'O+', '', false, '', ['sangre']],
                'alergias' => ['Alergias', 18, '', '', false, '', []],
                'condiciones' => ['Condiciones médicas', 18, '', '', false, '', ['enfermedades']],
                'medicamentos' => ['Medicamentos', 18, '', '', false, '', []],
                'seguro' => ['Seguro médico', 16, '', '', false, '', []],
                'emergencia_nombre' => ['Contacto de emergencia', 20, '', '', false, '', ['emergencia']],
                'emergencia_telefono' => ['Teléfono de emergencia', 16, '', '', false, '', []],
                'beca' => ['Beca (%)', 8, '', '50', false, 'Porcentaje de descuento, ej: 50.', ['descuento']],
                'mensualidad_especial' => ['Mensualidad especial', 12, '', '', false, 'Solo si paga un valor distinto al de su grupo.', []],
                'fecha_ingreso' => ['Fecha de ingreso', 14, '', '', false, 'Vacío = hoy.', ['ingreso']],
                'foto_publica' => ['¿Publicar su foto? (SI/NO)', 12, 'NO', 'NO', false, '', ['foto-publica']],
            ];
        case 'competidores':
            $c = ['nombre' => ['Nombre completo *', 26, 'Mateo Andrade', 'Valentina Ruiz', true, '', ['nombres', 'competidor', 'deportista', 'piloto', 'jugador']]];
            $duelo = $t && es_duelo($t);
            $pruebas = $t && es_pruebas($t);
            $carrera = $t && es_carrera($t);
            $bolos = $t && es_bolos($t);
            if ($duelo && $t['modalidad'] === 'PADEL') $c['pareja'] = ['Pareja', 24, 'Felipe Ríos', 'Paula Benítez', false, 'Nombre del compañero o compañera.', ['companero']];
            if ($carrera || $pruebas || $bolos) $c['numero'] = ['Número', 8, '7', '21', false, 'Número del competidor (dorsal).', ['dorsal', 'no', 'n']];
            $c['club'] = ['Club / equipo', 20, 'Club Quito', 'Academia Norte', false, '', ['equipo', 'academia']];
            $c['ciudad'] = ['Ciudad', 14, 'Quito', 'Quito', false, '', []];
            if ($duelo || $pruebas) {
                $c['rama'] = ['Rama (M/F)', 10, 'M', 'F', $pruebas, 'M = varones, F = damas.', ['sexo', 'genero']];
                $c['nacimiento'] = ['Fecha de nacimiento (día/mes/año)', 18, '15/03/2012', '02/08/2011', false, 'Sirve para la categoría por edad.', ['fecha-de-nacimiento', 'fecha-nacimiento']];
            }
            if (!$pruebas) $c['categoria'] = ['Categoría', 18, '', '', false, 'Nombre de una categoría del campeonato. Vacío = la que le toque automáticamente.', []];
            if ($duelo && $t['modalidad'] === 'BOXEO') $c['peso'] = ['Peso (kg)', 10, '52.5', '48', false, 'Ej: 52.5', []];
            if ($duelo && $t['modalidad'] === 'MARC') {
                $c['peso'] = ['Peso (kg)', 10, '', '', false, 'Si compite por peso.', []];
                $c['grado'] = ['Grado / cinturón', 14, 'Azul', 'Verde', false, '', ['cinturon']];
            }
            if ($carrera) {
                $c['vehiculo'] = ['Vehículo', 18, 'Chevrolet Aveo', 'Suzuki Forsa', false, '', ['auto', 'moto', 'bicicleta']];
                if ($t['modalidad'] === 'AUTOX') {
                    $c['cc'] = ['Cilindraje (cc)', 10, '1600', '1300', false, 'Sirve para la categoría automática.', ['cilindraje']];
                    $c['aspiracion'] = ['Aspiración', 14, 'Normal', 'Turbo', false, 'Normal o Turbo.', []];
                }
            }
            if ($bolos) $c['promedio'] = ['Promedio', 10, '165', '140', false, 'Para calcular el hándicap.', ['promedio-de-juego']];
            if ($pruebas) $c['pruebas'] = ['Pruebas', 30, '1, 3', 'todas', false, 'Números de las pruebas del programa separados por coma, o "todas".', ['eventos', 'pruebas-a-nadar', 'pruebas-a-correr']];
            return $c;
    }
    return [];
}

/** (v7) Columnas de la plantilla de equipos y jugadores (pantalla Importar): clave => [título en el Excel, ancho, ejemplo 1, ejemplo 2]. */
function columnas_importacion_base(): array
{
    return [
        'equipo' => ['Equipo *', 22, 'Deportivo Norte', 'Deportivo Norte'],
        'abreviatura' => ['Abreviatura', 12, 'DNO', 'DNO'],
        'delegado' => ['Delegado del equipo', 24, 'Carlos Pérez', 'Carlos Pérez'],
        'telefono' => ['Teléfono del delegado', 18, '0991234567', '0991234567'],
        'nombres' => ['Nombres del jugador *', 22, 'Juan Andrés', 'Mateo'],
        'apellidos' => ['Apellidos del jugador', 22, 'Gómez Rivera', 'Pérez Luna'],
        'cedula' => ['Cédula', 14, '1710034065', '1700000373'],
        'numero' => ['Número de camiseta', 10, '10', '7'],
        'posicion' => ['Posición', 14, 'Delantero', 'Volante'],
        'fecha_nac' => ['Fecha de nacimiento (día/mes/año)', 18, '15/03/1995', '02/08/2011'],
        'foto_publica' => ['¿Publicar su foto? (SI/NO)', 12, 'SI', 'NO'],
        'representante_nombre' => ['Representante (si es menor de 18)', 26, '', 'Rosa Luna'],
        'representante_cedula' => ['Cédula del representante', 16, '', '1710034065'],
        'representante_telefono' => ['Teléfono del representante', 16, '', '0987654321'],
    ];
}

/** Plantilla .xlsx: hoja "Datos" (títulos + 2 ejemplos) y hoja "Instrucciones". */
function plantilla_carga(string $tipo, array $liga, ?array $t = null): string
{
    $cols = columnas_carga($tipo, $liga, $t);
    $datos = [array_column($cols, 0), array_map('strval', array_column($cols, 2)), array_map('strval', array_column($cols, 3))];
    [, $titulo] = tipos_carga($liga)[$tipo];
    $ins = [['Columna', '¿Obligatoria?', 'Cómo llenarla'],
        ['1. Escriba un registro por fila en la hoja "Datos". Borre los 2 ejemplos.', '', ''],
        ['2. No cambie los títulos de la primera fila.', '', ''],
        ['3. Guarde el archivo y súbalo en ' . marca() . ' → Subir datos desde Excel → ' . $titulo . '.', '', ''],
        ['4. Antes de guardar, el sistema le muestra la lista para revisar. Lo que tenga errores no se guarda.', '', ''],
        ['', '', '']];
    foreach ($cols as $c) $ins[] = [str_replace(' *', '', $c[0]), $c[4] ? 'SÍ' : 'no', $c[5]];
    if ($tipo === 'competidores' && $t) {
        $ins[] = ['', '', ''];
        $ins[] = ['Campeonato: ' . $t['nombre'] . ' ' . $t['temporada'], '', ''];
        if (es_pruebas($t)) {
            $ins[] = ['Pruebas del programa (use el número):', '', ''];
            foreach (pruebas_eventos((int)$t['id']) as $ev) $ins[] = ['Prueba ' . $ev['num'], '', $ev['nombre'] . ($ev['rama'] !== '' ? ' · ' . ($ev['rama'] === 'F' ? 'damas' : 'varones') : '') . ($ev['categoria'] !== '' ? ' · ' . $ev['categoria'] : '')];
        } else {
            $cats = es_duelo($t) ? duelo_categorias((int)$t['id']) : carrera_categorias((int)$t['id']);
            if ($cats) {
                $ins[] = ['Categorías del campeonato (escriba el nombre exacto):', '', ''];
                foreach ($cats as $k) $ins[] = [$k['nombre'], '', ''];
            }
        }
    }
    if ($tipo === 'alumnos') {
        $grupos = filas('SELECT nombre FROM esc_grupos WHERE liga_id = ? AND activo = 1 ORDER BY nombre', [$liga['id']]);
        if ($grupos) {
            $ins[] = ['', '', ''];
            $ins[] = ['Grupos de la escuela (escriba el nombre exacto):', '', ''];
            foreach ($grupos as $g) $ins[] = [$g['nombre'], '', ''];
        }
    }
    if ($tipo === 'grupos') {
        $profes = filas('SELECT nombre FROM esc_profesores WHERE liga_id = ? AND activo = 1 ORDER BY nombre', [$liga['id']]);
        if ($profes) {
            $ins[] = ['', '', ''];
            $ins[] = ['Profesores cargados:', '', ''];
            foreach ($profes as $p) $ins[] = [$p['nombre'], '', ''];
        }
    }
    return crear_xlsx_libro([
        ['nombre' => 'Datos', 'filas' => $datos, 'anchos' => array_column($cols, 1)],
        ['nombre' => 'Instrucciones', 'filas' => $ins, 'anchos' => [60, 14, 80]],
    ]);
}

/** Texto en minúsculas sin tildes ni signos (para comparar nombres). */
function clave_texto(string $s): string
{
    return slugify(trim($s));
}

function si_no(string $v, bool $defecto): bool
{
    $v = clave_texto($v);
    if (in_array($v, ['si', 's', '1', 'x', 'true', 'yes'], true)) return true;
    if (in_array($v, ['no', 'n', '0', 'false'], true)) return false;
    return $defecto;
}

function numero_excel(string $v): ?float
{
    $v = trim(str_replace([' ', '$', '%'], '', $v));
    if ($v === '') return null;
    if (str_contains($v, ',') && !str_contains($v, '.')) $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

/** Hora de Excel (08:00, 8:00, 0.333333) → HH:MM o ''. */
function hora_excel(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    if (is_numeric($v) && (float)$v < 1) {
        $min = (int)round((float)$v * 1440);
        return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60);
    }
    if (preg_match('/^(\d{1,2})(?::|h|\.)?(\d{2})?/', $v, $m)) return hora_valida(sprintf('%02d:%02d', (int)$m[1], (int)($m[2] ?? 0)));
    return '';
}

function documento_excel(string $v): string
{
    $c = strtoupper(preg_replace('/[\s.\-]+/', '', $v));
    if (preg_match('/^\d{9}$/', $c)) $c = '0' . $c; // Excel quita el cero de adelante
    return $c;
}

/** Días de la semana "Lun, Mié, Vie" o "1,3,5" → "1,3,5". */
function dias_excel(string $v): string
{
    $mapa = ['l' => 1, 'lu' => 1, 'lun' => 1, 'lunes' => 1, 'ma' => 2, 'mar' => 2, 'martes' => 2, 'mi' => 3, 'mie' => 3, 'miercoles' => 3, 'x' => 3,
        'j' => 4, 'ju' => 4, 'jue' => 4, 'jueves' => 4, 'v' => 5, 'vi' => 5, 'vie' => 5, 'viernes' => 5, 's' => 6, 'sa' => 6, 'sab' => 6, 'sabado' => 6,
        'd' => 7, 'do' => 7, 'dom' => 7, 'domingo' => 7];
    $out = [];
    foreach (preg_split('/(?:[,;\/\s]+|\by\b)+/u', mb_strtolower(trim($v))) as $p) {
        $k = clave_texto($p);
        if ($k === '') continue;
        if (ctype_digit($k) && (int)$k >= 1 && (int)$k <= 7) $out[] = (int)$k;
        elseif (isset($mapa[$k])) $out[] = $mapa[$k];
    }
    $out = array_values(array_unique($out));
    sort($out);
    return implode(',', $out);
}

function deporte_excel(string $v, string $defecto): string
{
    $k = clave_texto($v);
    if ($k === '') return $defecto;
    foreach (catalogo_deportes() as $clave => $d) {
        if ($k === $clave || $k === str_replace('_', '-', $clave) || $k === clave_texto($d[0]) || str_starts_with(clave_texto($d[0]), $k . '-')) return $clave;
    }
    $alias = ['futbol-sala' => 'indor', 'basket' => 'basquet', 'baloncesto' => 'basquet', 'volley' => 'voley', 'voleibol' => 'voley', 'karate' => 'karate',
        'taekwondo' => 'taekwondo', 'judo' => 'judo', 'artes-marciales' => 'artes_marciales', 'ping-pong' => 'tenis_mesa', 'tenis-de-mesa' => 'tenis_mesa', 'padel' => 'padel', 'bicicleta' => 'ciclismo'];
    return $alias[$k] ?? 'otro';
}

/** Posición de cada columna del archivo según los títulos de la primera fila. */
function posiciones_carga(array $titulos, array $cols): array
{
    $pos = [];
    foreach ($titulos as $i => $t) {
        $k = clave_texto(str_replace('*', '', (string)$t));
        if ($k === '') continue;
        foreach ($cols as $clave => $c) {
            if (isset($pos[$clave])) continue;
            $opciones = array_merge([clave_texto(str_replace('*', '', $c[0])), str_replace('_', '-', $clave)], $c[6] ?? []);
            if (in_array($k, $opciones, true)) { $pos[$clave] = $i; break; }
        }
    }
    return $pos;
}

/**
 * Revisa el archivo: no guarda nada. Devuelve ['filas' => [['n', 'd', 'errores', 'notas', 'omitir'], ...], 'ok', 'errores', 'omitidos', 'aviso'].
 */
function revisar_carga(string $tipo, array $filas_excel, array $liga, ?array $t = null): array
{
    $cols = columnas_carga($tipo, $liga, $t);
    if (!$cols) throw new RuntimeException('Tipo de carga no válido.');
    if (count($filas_excel) < 2) throw new RuntimeException('El archivo está vacío: debe tener la fila de títulos y al menos una fila con datos.');
    if (count($filas_excel) > CARGA_MAX_FILAS + 1) throw new RuntimeException('El archivo tiene más de ' . CARGA_MAX_FILAS . ' filas. Divídalo en varios archivos.');
    $pos = posiciones_carga(array_shift($filas_excel), $cols);
    foreach ($cols as $k => $c) {
        if ($c[4] && !isset($pos[$k])) throw new RuntimeException('No se encontró la columna "' . str_replace(' *', '', $c[0]) . '" en la primera fila. Use la plantilla que se descarga en esta página.');
    }
    $lid = (int)$liga['id'];
    $vistos = [];
    $out = [];
    foreach ($filas_excel as $idx => $f) {
        if (implode('', array_map(fn($x) => trim((string)$x), $f)) === '') continue;
        $v = fn(string $k, int $max = 120) => isset($pos[$k]) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($f[$pos[$k]] ?? ''))), 0, $max) : '';
        $r = ['n' => $idx + 2, 'd' => [], 'errores' => [], 'notas' => [], 'omitir' => false];
        $dup = function (string $clave, string $txt) use (&$vistos, &$r) {
            if ($clave === '') return;
            if (isset($vistos[$clave])) $r['errores'][] = $txt . ' repetido en el archivo (fila ' . $vistos[$clave] . ').';
            else $vistos[$clave] = $r['n'];
        };
        switch ($tipo) {
            case 'arbitros':
                $d = ['nombre' => $v('nombre', 80), 'cedula' => documento_excel($v('cedula', 20)), 'telefono' => $v('telefono', 30), 'categoria' => ''];
                $cat = clave_texto($v('categoria'));
                $d['categoria'] = ['central' => 'Central', 'asistente' => 'Asistente', 'central-y-asistente' => 'Central y asistente', 'ambos' => 'Central y asistente'][$cat] ?? '';
                if ($cat !== '' && $d['categoria'] === '') $r['notas'][] = 'categoría "' . $v('categoria') . '" no reconocida (queda vacía)';
                if (mb_strlen($d['nombre']) < 3) $r['errores'][] = 'Falta el nombre.';
                if ($d['cedula'] !== '' && ($err = error_documento($d['cedula']))) $r['errores'][] = $err;
                $dup($d['cedula'] !== '' ? 'c:' . $d['cedula'] : 'n:' . clave_texto($d['nombre']), $d['cedula'] !== '' ? 'Cédula' : 'Nombre');
                if (!$r['errores'] && (($d['cedula'] !== '' && fila('SELECT id FROM arbitros WHERE liga_id = ? AND cedula = ?', [$lid, $d['cedula']]))
                    || fila('SELECT id FROM arbitros WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']]))) { $r['omitir'] = true; $r['notas'][] = 'ya está registrado (se omite)'; }
                break;
            case 'canchas':
                $d = ['nombre' => $v('nombre', 60), 'direccion' => $v('direccion', 160), 'reservable' => si_no($v('reservable'), false) ? 1 : 0,
                    'precio_hora' => max(0, round((float)numero_excel($v('precio_hora')), 2)), 'abre' => hora_excel($v('abre')), 'cierra' => hora_excel($v('cierra')),
                    'duracion' => ($x = (int)numero_excel($v('duracion'))) > 0 ? min(300, max(15, $x)) : 60];
                if (mb_strlen($d['nombre']) < 2) $r['errores'][] = 'Falta el nombre de la cancha.';
                if ($v('precio_hora') !== '' && numero_excel($v('precio_hora')) === null) $r['errores'][] = 'Precio no válido.';
                if ($d['reservable'] && ($d['abre'] === '' || $d['cierra'] === '')) $r['notas'][] = 'sin horario: se usa 08:00 a 22:00';
                $dup('n:' . clave_texto($d['nombre']), 'Nombre');
                if (!$r['errores'] && fila('SELECT id FROM canchas WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) { $r['omitir'] = true; $r['notas'][] = 'ya existe (se omite)'; }
                break;
            case 'sedes':
                $d = ['nombre' => $v('nombre', 80), 'direccion' => $v('direccion', 160)];
                if (mb_strlen($d['nombre']) < 2) $r['errores'][] = 'Falta el nombre de la sede.';
                $dup('n:' . clave_texto($d['nombre']), 'Nombre');
                if (!$r['errores'] && fila('SELECT id FROM esc_sedes WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) { $r['omitir'] = true; $r['notas'][] = 'ya existe (se omite)'; }
                break;
            case 'profesores':
                $tp = clave_texto($v('tipo_pago'));
                $d = ['nombre' => $v('nombre', 80), 'cedula' => documento_excel($v('cedula', 20)), 'telefono' => $v('telefono', 30), 'email' => mb_strtolower($v('email', 120)),
                    'especialidad' => $v('especialidad', 80), 'cargo' => $v('cargo', 60) ?: 'Profesor',
                    'tipo_pago' => str_contains($tp, 'clase') ? 'clase' : (str_contains($tp, 'alumno') ? 'alumno' : 'mensual'),
                    'valor_pago' => max(0, round((float)numero_excel($v('valor_pago')), 2)), 'iess_pct' => max(0, min(30, round((float)numero_excel($v('iess_pct')), 2))), 'activo' => 1];
                if (mb_strlen($d['nombre']) < 3) $r['errores'][] = 'Falta el nombre.';
                if ($d['cedula'] !== '' && ($err = error_documento($d['cedula']))) $r['errores'][] = $err;
                if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $r['errores'][] = 'Correo no válido.';
                if ($v('valor_pago') !== '' && numero_excel($v('valor_pago')) === null) $r['errores'][] = 'Valor no válido.';
                $dup($d['cedula'] !== '' ? 'c:' . $d['cedula'] : 'n:' . clave_texto($d['nombre']), $d['cedula'] !== '' ? 'Cédula' : 'Nombre');
                if (!$r['errores'] && (($d['cedula'] !== '' && fila('SELECT id FROM esc_profesores WHERE liga_id = ? AND cedula = ?', [$lid, $d['cedula']]))
                    || fila('SELECT id FROM esc_profesores WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']]))) { $r['omitir'] = true; $r['notas'][] = 'ya está registrado (se omite)'; }
                break;
            case 'grupos':
                $cat = str_starts_with(clave_texto($v('categoria')), 'sub') || ($v('categoria') === '' && $v('anio_desde') !== '') ? 'sub' : 'abierta';
                $desde = (int)numero_excel($v('anio_desde'));
                $hasta = (int)numero_excel($v('anio_hasta')) ?: $desde;
                $d = ['nombre' => $v('nombre', 60), 'deporte' => deporte_excel($v('deporte'), (string)($liga['deporte_escuela'] ?: 'futbol')), 'categoria' => $cat,
                    'anio_desde' => $cat === 'sub' ? $desde : null, 'anio_hasta' => $cat === 'sub' ? $hasta : null, 'sede' => $v('sede', 80), 'profesor' => $v('profesor', 80),
                    'dias' => dias_excel($v('dias')), 'hora_inicio' => hora_excel($v('hora_inicio')), 'hora_fin' => hora_excel($v('hora_fin')), 'cancha' => $v('cancha', 60),
                    'cupo' => max(0, min(500, (int)numero_excel($v('cupo')))), 'mensualidad' => $v('mensualidad') === '' ? null : max(0, round((float)numero_excel($v('mensualidad')), 2))];
                if (mb_strlen($d['nombre']) < 2) $r['errores'][] = 'Falta el nombre del grupo.';
                if ($cat === 'sub' && ($desde < 1950 || $desde > (int)date('Y') || $hasta < 1950 || $hasta > (int)date('Y'))) $r['errores'][] = 'Para una categoría Sub escriba el año de nacimiento (ej: ' . ((int)date('Y') - 10) . ').';
                if ($v('dias') !== '' && $d['dias'] === '') $r['errores'][] = 'Días no reconocidos ("' . $v('dias') . '"). Escriba: Lun, Mié, Vie.';
                if ($v('deporte') !== '' && $d['deporte'] === 'otro' && clave_texto($v('deporte')) !== 'otro') $r['notas'][] = 'deporte "' . $v('deporte') . '" no reconocido: queda como Otro';
                if ($d['sede'] !== '' && !fila('SELECT id FROM esc_sedes WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['sede']])) $r['notas'][] = 'se crea la sede ' . $d['sede'];
                if ($d['profesor'] !== '' && !fila('SELECT id FROM esc_profesores WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['profesor']])) $r['notas'][] = 'profesor "' . $d['profesor'] . '" no encontrado (queda sin profesor)';
                $dup('n:' . clave_texto($d['nombre']), 'Nombre');
                if (!$r['errores'] && fila('SELECT id FROM esc_grupos WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) { $r['omitir'] = true; $r['notas'][] = 'ya existe (se omite)'; }
                break;
            case 'alumnos':
                $fn = fecha_excel($v('fecha_nac'));
                $fi = fecha_excel($v('fecha_ingreso'));
                $g = clave_texto($v('genero'));
                $d = ['nombres' => $v('nombres', 60), 'apellidos' => $v('apellidos', 60), 'cedula' => documento_excel($v('cedula', 20)), 'fecha_nac' => (string)$fn,
                    'genero' => in_array($g, ['m', 'masculino', 'hombre', 'varon'], true) ? 'M' : (in_array($g, ['f', 'femenino', 'mujer', 'dama'], true) ? 'F' : ''),
                    'grupo' => $v('grupo', 60), 'representante_nombre' => $v('representante_nombre', 80), 'representante_cedula' => documento_excel($v('representante_cedula', 20)),
                    'representante_telefono' => $v('representante_telefono', 30), 'representante_email' => mb_strtolower($v('representante_email', 120)), 'parentesco' => $v('parentesco', 30),
                    'direccion' => $v('direccion', 160), 'colegio' => $v('colegio', 100), 'tipo_sangre' => mb_strtoupper($v('tipo_sangre', 5)), 'alergias' => $v('alergias', 200),
                    'condiciones' => $v('condiciones', 200), 'medicamentos' => $v('medicamentos', 200), 'seguro' => $v('seguro', 100),
                    'emergencia_nombre' => $v('emergencia_nombre', 80), 'emergencia_telefono' => $v('emergencia_telefono', 30),
                    'beca' => max(0, min(100, (float)numero_excel($v('beca')))), 'mensualidad_especial' => $v('mensualidad_especial') === '' ? null : max(0, round((float)numero_excel($v('mensualidad_especial')), 2)),
                    'fecha_ingreso' => $fi ?: date('Y-m-d'), 'foto_publica' => si_no($v('foto_publica'), false) ? 1 : 0];
                if (mb_strlen($d['nombres']) < 2) $r['errores'][] = 'Faltan los nombres.';
                if (mb_strlen($d['apellidos']) < 2) $r['errores'][] = 'Faltan los apellidos.';
                if ($fn === null || $fn === '') $r['errores'][] = 'Fecha de nacimiento ' . ($fn === null ? 'no válida ("' . $v('fecha_nac') . '")' : 'vacía') . '. Escríbala así: 15/03/2016.';
                if ($fi === null) $r['errores'][] = 'Fecha de ingreso no válida.';
                if ($d['cedula'] !== '' && ($err = error_documento($d['cedula']))) $r['errores'][] = 'Cédula del alumno: ' . $err;
                if ($d['representante_cedula'] !== '' && ($err = error_documento($d['representante_cedula']))) $r['errores'][] = 'Cédula del representante: ' . $err;
                if ($d['representante_email'] !== '' && !filter_var($d['representante_email'], FILTER_VALIDATE_EMAIL)) $r['errores'][] = 'Correo del representante no válido.';
                if ($fn && es_menor($fn) && (mb_strlen($d['representante_nombre']) < 5 || $d['representante_cedula'] === '' || strlen(preg_replace('/\D/', '', $d['representante_telefono'])) < 7)) {
                    $r['errores'][] = 'Es menor de edad: falta el nombre, la cédula o el teléfono del representante.';
                }
                if ($d['grupo'] !== '') {
                    $gr = fila('SELECT id, nombre FROM esc_grupos WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['grupo']]);
                    if (!$gr) $r['errores'][] = 'No existe el grupo "' . $d['grupo'] . '". Súbalo primero en la plantilla de Grupos o deje la columna vacía.';
                } elseif ($fn && ($gs = grupo_sugerido($lid, $fn))) {
                    $d['grupo'] = $gs['nombre'];
                    $r['notas'][] = 'grupo por su edad: ' . $gs['nombre'];
                } elseif ($fn) {
                    $r['notas'][] = 'sin grupo';
                }
                $dup($d['cedula'] !== '' ? 'c:' . $d['cedula'] : 'n:' . clave_texto($d['nombres'] . ' ' . $d['apellidos'] . ' ' . $d['fecha_nac']), $d['cedula'] !== '' ? 'Cédula' : 'Alumno');
                if (!$r['errores'] && (($d['cedula'] !== '' && fila("SELECT id FROM esc_alumnos WHERE liga_id = ? AND cedula = ? AND estado <> 'retirado'", [$lid, $d['cedula']]))
                    || fila("SELECT id FROM esc_alumnos WHERE liga_id = ? AND lower(nombres) = lower(?) AND lower(apellidos) = lower(?) AND fecha_nac = ? AND estado <> 'retirado'", [$lid, $d['nombres'], $d['apellidos'], $d['fecha_nac']]))) {
                    $r['omitir'] = true;
                    $r['notas'][] = 'ya está inscrito (se omite)';
                }
                if ($fn && es_menor($fn)) $r['notas'][] = 'menor (con representante)';
                break;
            case 'competidores':
                if (!$t) throw new RuntimeException('Elija el campeonato.');
                $tid = (int)$t['id'];
                $rama = clave_texto($v('rama'));
                $nac = fecha_excel($v('nacimiento'));
                $d = ['nombre' => $v('nombre', 80), 'pareja' => $v('pareja', 80), 'numero' => $v('numero', 6), 'club' => $v('club', 80), 'ciudad' => $v('ciudad', 60),
                    'rama' => in_array($rama, ['m', 'masculino', 'varones', 'varon', 'hombre'], true) ? 'M' : (in_array($rama, ['f', 'femenino', 'damas', 'dama', 'mujer'], true) ? 'F' : ''),
                    'nacimiento' => (string)$nac, 'peso' => numero_excel($v('peso')), 'grado' => $v('grado', 40), 'categoria' => $v('categoria', 60), 'categoria_id' => null,
                    'extra' => []];
                if (mb_strlen($d['nombre']) < 3) $r['errores'][] = 'Falta el nombre.';
                if ($nac === null) $r['errores'][] = 'Fecha de nacimiento no válida ("' . $v('nacimiento') . '"). Escríbala así: 15/03/2012.';
                if (es_pruebas($t) && $d['rama'] === '') $r['errores'][] = 'Escriba la rama: M o F.';
                if ($t['modalidad'] === 'PADEL' && $d['pareja'] === '') $r['notas'][] = 'sin pareja';
                if ($d['categoria'] !== '') {
                    $tabla = es_duelo($t) ? 'duelo_categorias' : 'carrera_categorias';
                    $cid = valor("SELECT id FROM $tabla WHERE torneo_id = ? AND lower(nombre) = lower(?)", [$tid, $d['categoria']]);
                    if ($cid) $d['categoria_id'] = (int)$cid;
                    else $r['errores'][] = 'No existe la categoría "' . $d['categoria'] . '" en el campeonato (o deje la columna vacía).';
                }
                if (es_carrera($t)) $d['extra'] = ['vehiculo' => $v('vehiculo', 60), 'cc' => (int)numero_excel($v('cc')), 'aspiracion' => mb_strtolower($v('aspiracion')) === 'turbo' ? 'turbo' : ($v('aspiracion') !== '' ? 'normal' : '')];
                if (es_bolos($t)) $d['extra'] = ['promedio' => (int)numero_excel($v('promedio'))];
                if (es_pruebas($t)) {
                    $evs = pruebas_eventos($tid);
                    $pedidas = clave_texto($v('pruebas'));
                    $ids = [];
                    $p = ['rama' => $d['rama'], 'nacimiento' => $d['nacimiento']];
                    if (in_array($pedidas, ['todas', 'todos', 'todo'], true)) {
                        foreach ($evs as $ev) if (prueba_no_puede($ev, $p, $t) === '') $ids[] = (int)$ev['id'];
                    } elseif ($pedidas !== '') {
                        foreach (preg_split('/[,;\s]+/', $v('pruebas')) as $x) {
                            if (trim($x) === '') continue;
                            $ev = null;
                            foreach ($evs as $e) if ((string)$e['num'] === trim($x) || clave_texto($e['nombre']) === clave_texto($x)) { $ev = $e; break; }
                            if (!$ev) { $r['errores'][] = 'No existe la prueba ' . $x . ' en el programa.'; continue; }
                            if (($no = prueba_no_puede($ev, $p, $t)) !== '') { $r['notas'][] = 'prueba ' . $ev['num'] . ': ' . $no; continue; }
                            $ids[] = (int)$ev['id'];
                        }
                    }
                    if (!$ids && !$r['errores']) $r['notas'][] = 'sin pruebas (inscríbalo después)';
                    else if ($ids) $r['notas'][] = count($ids) . ' prueba(s)';
                    $d['extra'] = ['eventos' => array_values(array_unique($ids))];
                }
                $dup('n:' . clave_texto($d['nombre'] . '|' . $d['pareja']), 'Competidor');
                $tabla_p = es_duelo($t) ? 'duelo_participantes' : (es_pruebas($t) ? 'prueba_participantes' : 'carrera_participantes');
                if (!$r['errores'] && fila("SELECT id FROM $tabla_p WHERE torneo_id = ? AND lower(nombre) = lower(?)", [$tid, $d['nombre']])) { $r['omitir'] = true; $r['notas'][] = 'ya está inscrito (se omite)'; }
                break;
        }
        $r['d'] = $d ?? [];
        $out[] = $r;
    }
    if (!$out) throw new RuntimeException('No se encontraron datos en el archivo.');
    $ok = count(array_filter($out, fn($r) => !$r['errores'] && !$r['omitir']));
    $aviso = '';
    if ($tipo === 'alumnos' && !es_super() && empty($liga['es_demo'])) {
        $pl = plan_de_liga($liga);
        $max = $pl ? (int)($pl['max_alumnos'] ?? 0) : 0;
        $ya = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado IN ('activo','pendiente')", [$lid]);
        if ($max > 0 && $ya + $ok > $max) $aviso = 'Su plan ' . $pl['nombre'] . ' permite hasta ' . $max . ' alumnos y ya tiene ' . $ya . ': el archivo trae ' . $ok . ' nuevos. Quite algunos o cambie de plan en "Mi plan".';
    }
    return ['filas' => $out, 'ok' => $ok, 'errores' => count(array_filter($out, fn($r) => (bool)$r['errores'])),
        'omitidos' => count(array_filter($out, fn($r) => !$r['errores'] && $r['omitir'])), 'aviso' => $aviso];
}

/** Guarda las filas buenas de la revisión. Devuelve cuántos registros creó. */
function guardar_carga(string $tipo, array $rev, array $liga, ?array $t = null): int
{
    $lid = (int)$liga['id'];
    $pdo = db();
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rev['filas'] as $r) {
            if ($r['errores'] || $r['omitir']) continue;
            $d = $r['d'];
            switch ($tipo) {
                case 'arbitros':
                    if (fila('SELECT id FROM arbitros WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) continue 2;
                    q('INSERT INTO arbitros (liga_id, nombre, cedula, telefono, categoria) VALUES (?,?,?,?,?)', [$lid, $d['nombre'], $d['cedula'], $d['telefono'], $d['categoria']]);
                    registrar_autorizacion_tercero('arbitro', $lid, $d['nombre'], 'la designación de partidos, informes y pago de honorarios', ['ref_tabla' => 'arbitros', 'ref_id' => (int)$pdo->lastInsertId(), 'documento' => $d['cedula']]);
                    break;
                case 'canchas':
                    if (fila('SELECT id FROM canchas WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) continue 2;
                    q('INSERT INTO canchas (liga_id, nombre, direccion, orden, reservable, precio_hora, abre, cierra, duracion) VALUES (?,?,?,?,?,?,?,?,?)', [
                        $lid, $d['nombre'], $d['direccion'], (int)valor('SELECT COUNT(*) FROM canchas WHERE liga_id = ?', [$lid]) + 1, $d['reservable'], $d['precio_hora'],
                        $d['abre'] ?: '08:00', $d['cierra'] ?: '22:00', $d['duracion']]);
                    break;
                case 'sedes':
                    if (fila('SELECT id FROM esc_sedes WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) continue 2;
                    q('INSERT INTO esc_sedes (liga_id, nombre, direccion) VALUES (?,?,?)', [$lid, $d['nombre'], $d['direccion']]);
                    break;
                case 'profesores':
                    if (fila('SELECT id FROM esc_profesores WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) continue 2;
                    $d['liga_id'] = $lid;
                    q('INSERT INTO esc_profesores (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
                    registrar_autorizacion_tercero('profesor', $lid, $d['nombre'], 'la gestión de la escuela (clases, asistencia y rol de pago)', ['ref_tabla' => 'esc_profesores', 'ref_id' => (int)$pdo->lastInsertId(), 'documento' => $d['cedula']]);
                    break;
                case 'grupos':
                    if (fila('SELECT id FROM esc_grupos WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['nombre']])) continue 2;
                    $sede = null;
                    if ($d['sede'] !== '') {
                        $sede = valor('SELECT id FROM esc_sedes WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['sede']]);
                        if (!$sede) { q('INSERT INTO esc_sedes (liga_id, nombre) VALUES (?, ?)', [$lid, $d['sede']]); $sede = $pdo->lastInsertId(); }
                    }
                    $prof = $d['profesor'] !== '' ? valor('SELECT id FROM esc_profesores WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['profesor']]) : null;
                    $g = ['liga_id' => $lid, 'nombre' => $d['nombre'], 'categoria' => $d['categoria'], 'anio_desde' => $d['anio_desde'], 'anio_hasta' => $d['anio_hasta'],
                        'sede_id' => $sede ? (int)$sede : null, 'profesor_id' => $prof ? (int)$prof : null, 'dias' => $d['dias'], 'hora_inicio' => $d['hora_inicio'], 'hora_fin' => $d['hora_fin'],
                        'cancha' => $d['cancha'], 'cupo' => $d['cupo'], 'mensualidad' => $d['mensualidad'], 'activo' => 1, 'deporte' => $d['deporte']];
                    q('INSERT INTO esc_grupos (' . implode(', ', array_keys($g)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($g))) . ')', $g);
                    preparar_deporte($lid, $d['deporte']);
                    break;
                case 'alumnos':
                    if ($d['cedula'] !== '' && fila("SELECT id FROM esc_alumnos WHERE liga_id = ? AND cedula = ? AND estado <> 'retirado'", [$lid, $d['cedula']])) continue 2;
                    $gid = $d['grupo'] !== '' ? valor('SELECT id FROM esc_grupos WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $d['grupo']]) : null;
                    $a = $d;
                    unset($a['grupo']);
                    $a += ['liga_id' => $lid, 'grupo_id' => $gid ? (int)$gid : null, 'estado' => 'activo', 'origen' => 'excel', 'consentimiento' => date('Y-m-d H:i:s'),
                        'consentimiento_por' => es_menor($d['fecha_nac']) ? 'representante' : 'alumno'];
                    q('INSERT INTO esc_alumnos (' . implode(', ', array_keys($a)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($a))) . ')', $a);
                    registrar_consentimiento_alumno($lid, $a, (int)$pdo->lastInsertId(), false, $liga['nombre']);
                    break;
                case 'competidores':
                    $ins = ['nombre' => $d['nombre'], 'pareja' => $d['pareja'], 'club' => $d['club'], 'ciudad' => $d['ciudad'], 'rama' => $d['rama'], 'nacimiento' => $d['nacimiento'],
                        'peso' => $d['peso'], 'grado' => $d['grado'], 'categoria_id' => $d['categoria_id'], 'extra' => json_encode($d['extra'])];
                    $pid = aprobar_inscripcion_indiv($t, $ins, $d['categoria_id']);
                    if ($d['numero'] !== '' && !es_duelo($t)) q('UPDATE ' . (es_pruebas($t) ? 'prueba_participantes' : 'carrera_participantes') . ' SET numero = ? WHERE id = ?', [$d['numero'], $pid]);
                    $tabla = es_duelo($t) ? 'duelo_participantes' : (es_pruebas($t) ? 'prueba_participantes' : 'carrera_participantes');
                    registrar_autorizacion_tercero('participante_' . pagina_individual($t), $lid, $d['nombre'] . ($d['pareja'] !== '' ? ' / ' . $d['pareja'] : ''), 'la organización del campeonato ' . $t['nombre'], ['ref_tabla' => $tabla, 'ref_id' => $pid]);
                    break;
            }
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    if ($tipo === 'alumnos' && $n > 0) generar_mensualidades($liga);
    return $n;
}

/** Campeonatos individuales de la liga (para la carga de competidores). */
function torneos_para_competidores(int $lid): array
{
    $mods = array_keys(modalidades_individuales());
    return filas("SELECT * FROM torneos WHERE liga_id = ? AND estado <> 'finalizado' AND modalidad IN ('" . implode("','", $mods) . "') ORDER BY id DESC", [$lid]);
}
