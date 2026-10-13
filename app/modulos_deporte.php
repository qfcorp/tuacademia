<?php
/*
 * (v35) MÓDULOS DEPORTIVOS: el sistema se vende por deporte.
 *
 * Cada módulo agrupa modalidades de campeonato (torneos.modalidad) y deportes de escuela (grupos / ligas.deporte_escuela):
 *   futbol · basquet · voley · ecuavoley · ciclismo · combate · raqueta · natacion · atletismo · bolos · automovilismo
 * (cada uno con sus variantes; se agregan nuevos aquí: el resto del sistema los toma solo)
 * Cada cuenta (liga, escuela o federación) tiene sus módulos en ligas.deportes ('*' o vacío = todos; las demos del sistema, todos).
 * Puente: una cuenta con varios módulos lo ve todo junto (mismo panel, página pública, usuarios, finanzas y portales).
 *
 * El superadministrador edita en «🧩 Módulos deportivos» (tabla modulos_deporte): precio mensual, activo, descripción,
 * REQUISITOS (datos que se piden a cada participante y documentos obligatorios) y REGLAS por defecto de cada modalidad.
 * Precio: el plan incluye 1 módulo (ajuste modulo_incluido = 1) y cada módulo adicional suma su precio con el
 * descuento del ajuste modulo_descuento (% desde el 2.º módulo).
 */

/** Catálogo fijo: clave => [nombre, ícono, descripción, modalidades de campeonato, deportes de escuela, precio sugerido]. */
function modulos_deporte_base(): array
{
    return [
        'futbol' => ['Fútbol', '⚽', 'Fútbol 5, 6, 7, 8, 9 y 11, fútbol personalizado e indor (fútbol sala): campeonatos con vocalía en vivo, escuelas y clubes formativos.',
            ['F5', 'F6', 'F7', 'F8', 'F9', 'F11', 'OTRO', 'INDOR'], ['futbol', 'indor'], 10],
        'basquet' => ['Básquet', '🏀', 'Básquet 5×5 y 3×3: cuartos, faltas personales y técnicas, tabla y estadísticas.', ['BASQ', 'BASQ3'], ['basquet'], 10],
        'voley' => ['Vóley', '🏐', 'Vóley 6×6 por sets, con su tabla de posiciones y escuelas de vóley.', ['VOLEY'], ['voley'], 10],
        'ecuavoley' => ['Ecuavóley', '🏐', 'Ecuavóley por sets (tríos), con vocalía en vivo y tabla.', ['ECUA'], ['ecuavoley'], 10],
        // (v36)
        'balonmano' => ['Balonmano', '🤾', 'Balonmano (handball) 7 contra 7: goles, goles de 7 metros, exclusiones de 2 minutos, amonestaciones y descalificaciones, vocalía en vivo, tabla 2-1-0 y goleadores.',
            ['BALON'], ['balonmano'], 10],
        'beisbol' => ['Béisbol y softbol', '⚾', 'Béisbol (9 entradas) y softbol (7 entradas): carreras por entrada, hits, errores, jonrones y ponches, pizarra del partido, vocalía en vivo y tabla de ganados.',
            ['BEISB', 'SOFTB'], ['beisbol', 'softbol'], 10],
        'ciclismo' => ['Ciclismo', '🚴', 'Reglas de la UCI: ruta (etapas, mismo tiempo, bonificaciones, montaña, joven, equipos), MTB, pista, BMX Racing y BMX Freestyle.',
            ['CICLO', 'MTB', 'PISTA', 'BMX', 'BMXFS'], ['ciclismo'], 10],
        'combate' => ['Deportes de combate', '🥊', 'Boxeo, karate, judo y taekwondo: categorías por peso, edad y grado, llaves, medallero, cinturones y exámenes de grado.',
            ['BOXEO', 'MARC'], ['boxeo', 'taekwondo', 'karate', 'judo', 'artes_marciales'], 10],
        'raqueta' => ['Deportes de raqueta', '🎾', 'Tenis, pádel, tenis de mesa y squash: llaves con cabezas de serie, grupos, consolación, Monrad, escalera del club, marcador por sets o games a 11 y ranking del circuito.',
            ['TENIS', 'PADEL', 'TMESA', 'SQUASH'], ['tenis', 'padel', 'tenis_mesa', 'squash'], 10],
        'natacion' => ['Natación', '🏊', 'Pruebas por estilo y distancia (libre, espalda, pecho, mariposa, combinado y relevos), por edad y rama, con medallero y puntos por club.',
            ['NATAC'], ['natacion'], 10],
        'atletismo' => ['Atletismo', '🏃', 'Pista y campo con las reglas de World Athletics (series y final, viento, concursos con intentos, salto alto y garrocha por alturas, heptatlón y decatlón) y carreras de ruta, trail y cross con tiempo de chip, grupos de edad, diplomas y equipos.',
            ['ATLET', 'RUTA'], ['atletismo'], 10],
        'bolos' => ['Bolos', '🎳', 'Jornadas con líneas, handicap automático, récords y clasificación individual y por clubes.',
            ['BOLOS'], ['bolos'], 10],
        'automovilismo' => ['Automovilismo', '🏎', 'Autocross por mangas con categorías por cilindrada y motor, conos, penalizaciones y campeonato por puntos o tiempo.',
            ['AUTOX'], [], 10],
        // (v36) nuevos módulos
        'ajedrez' => ['Ajedrez', '♟️', 'Torneos por sistema suizo (Buchholz), todos contra todos o llave, con ELO, categorías Sub 10 a Sub 18 y ranking del circuito.',
            ['AJED'], ['ajedrez'], 10],
        'pesas' => ['Levantamiento de pesas', '🏋️', 'Competencias con las reglas de la IWF: categorías de peso corporal, 3 intentos de arranque y 3 de envión, total, medallero y puntos por club.',
            ['PESAS'], ['pesas'], 10],
        'triatlon' => ['Triatlón', '🏊', 'Triatlón, duatlón y acuatlón: tiempo por segmento y transiciones, clasificación general, por rama y por grupo de edad.',
            ['TRIAT'], ['triatlon'], 10],
        'esports' => ['E-sports', '🎮', 'Torneos de videojuegos 1 vs 1 o 2 vs 2 (eFootball, FIFA/FC y otros): grupos, llaves con penales, tabla por goles y ranking.',
            ['ESPOR'], ['esports'], 10],
        // (TuAcademia) academias de baile y de música
        'baile' => ['Baile y danza', '💃', 'Ballet, contemporáneo, jazz, salsa, bachata, ritmos latinos, urbano, folclor, tap y danza árabe: cursos por nivel, niveles con certificado, recitales con coreografías y cobro de vestuario.',
            [], ['ballet', 'danza_contemporanea', 'jazz', 'salsa', 'bachata', 'ritmos_latinos', 'baile_urbano', 'folclor', 'tap', 'danza_arabe', 'baile'], 10],
        'musica' => ['Música', '🎹', 'Piano, guitarra, canto, violín, batería, bajo, ukelele y teoría: cursos y clases particulares con paquetes, niveles 1 a 6 con certificado y recitales.',
            [], ['piano', 'guitarra', 'canto', 'violin', 'bateria', 'bajo', 'ukelele', 'teoria_musical', 'musica'], 10],
        // (v37) no es un deporte: junta campeonatos de varios deportes en un mismo evento (olimpiadas interempresas, intercolegiales…)
        'olimpiadas' => ['Olimpiadas y juegos multideportivos', '🏅', 'Olimpiadas interempresas, interbancarias o intercolegiales: varios deportes a la vez, delegaciones que inscriben a su gente con su propio enlace, una ficha por deportista, medallero general, tabla de puntos configurable y administradores por evento.',
            [], [], 15],
    ];
}

/** Módulos con lo que el superadministrador cambió (precio, activo, descripción, requisitos, reglas). Cada uno: [clave, nombre, icono, descripcion, modalidades, deportes, precio, activo, orden, requisitos, reglas]. */
function modulos_deporte(bool $solo_activos = false): array
{
    $cache = &$GLOBALS['__modulos_deporte_cache'];
    if ($cache === null) {
        $db = [];
        try {
            foreach (filas('SELECT * FROM modulos_deporte') as $r) $db[$r['clave']] = $r;
        } catch (Throwable $e) {
            $db = [];
        }
        $cache = [];
        $i = 0;
        foreach (modulos_deporte_base() as $k => [$nom, $ico, $desc, $mods, $deps, $precio]) {
            $r = $db[$k] ?? [];
            if (PRODUCTO === 'academia' && !$deps) $r['activo'] = 0; // (TuAcademia) automovilismo y olimpiadas son solo de ligas
            $cache[$k] = ['clave' => $k, 'nombre' => $nom, 'icono' => $ico, 'descripcion' => trim((string)($r['descripcion'] ?? '')) !== '' ? (string)$r['descripcion'] : $desc,
                'modalidades' => $mods, 'deportes' => $deps, 'precio' => isset($r['precio_mensual']) ? (float)$r['precio_mensual'] : (float)$precio,
                'activo' => isset($r['activo']) ? (int)$r['activo'] === 1 : true, 'orden' => isset($r['orden']) ? (int)$r['orden'] : ++$i,
                'requisitos' => requisitos_normalizar(json_decode((string)($r['requisitos'] ?? ''), true), $k), 'reglas' => json_decode((string)($r['reglas'] ?? ''), true) ?: []];
        }
        uasort($cache, fn($a, $b) => $a['orden'] <=> $b['orden']);
    }
    return $solo_activos ? array_filter($cache, fn($m) => $m['activo']) : $cache;
}

/** Olvida la caché (después de guardar en el panel del súper o en las pruebas). */
function modulos_deporte_recargar(): void
{
    $GLOBALS['__modulos_deporte_cache'] = null;
}

function modulo_info(string $k): ?array
{
    return modulos_deporte()[$k] ?? null;
}

function nombre_modulo_deporte(string $k): string
{
    $m = modulo_info($k);
    return $m ? $m['icono'] . ' ' . $m['nombre'] : $k;
}

/** Módulo al que pertenece una modalidad de campeonato ('' si no se conoce). */
function modulo_de_modalidad(string $mod): string
{
    foreach (modulos_deporte_base() as $k => $m) if (in_array($mod, $m[3], true)) return $k;
    return '';
}

/** Módulo al que pertenece un deporte de escuela (grupos, deporte principal). */
function modulo_de_deporte_escuela(string $d): string
{
    foreach (modulos_deporte_base() as $k => $m) if (in_array($d, $m[4], true)) return $k;
    return ''; // «Otro deporte»: se puede usar con cualquier módulo
}

/** Módulo de un campeonato. */
function modulo_de_torneo(array $t): string
{
    return modulo_de_modalidad((string)($t['modalidad'] ?? 'F7')) ?: 'futbol';
}

/** Todas las modalidades de campeonato que existen (equipos + individuales). */
function modalidades_todas(): array
{
    return array_values(array_unique(array_merge(modalidades(), array_keys(modalidades_individuales()))));
}

/** Módulos que tiene una cuenta. Demos del sistema, cuentas sin elegir ('' o '*') y sin cuenta: todos. */
function deportes_liga(?array $liga): array
{
    $todos = array_keys(modulos_deporte_base());
    if (!$liga || !empty($liga['es_demo'])) return $todos;
    $v = trim((string)($liga['deportes'] ?? '*'));
    if ($v === '' || $v === '*') return $todos;
    $r = array_values(array_intersect($todos, array_map('trim', explode(',', $v))));
    return $r ?: ['futbol'];
}

/**
 * Módulos que se le cobran a una cuenta. Si nunca se eligieron ('*', cuentas anteriores a la v35 o creadas sin elegir),
 * solo el de su deporte principal: tiene todos, pero no se le cobra por los que no eligió.
 */
function deportes_cobrados(?array $liga): array
{
    $v = trim((string)($liga['deportes'] ?? '*'));
    if ($liga && ($v === '' || $v === '*')) {
        $k = ($liga['tipo'] ?? 'liga') === 'escuela' ? modulo_de_deporte_escuela((string)($liga['deporte_escuela'] ?? 'futbol')) : modulo_de_modalidad((string)($liga['modalidad'] ?? 'F7'));
        return [$k ?: 'futbol'];
    }
    return deportes_liga($liga);
}

function liga_tiene_modulo(?array $liga, string $k): bool
{
    return in_array($k, deportes_liga($liga), true);
}

function modalidad_permitida(?array $liga, string $mod): bool
{
    $k = modulo_de_modalidad($mod);
    return $k === '' || liga_tiene_modulo($liga, $k);
}

function deporte_escuela_permitido(?array $liga, string $d): bool
{
    $k = modulo_de_deporte_escuela($d);
    return $k === '' || liga_tiene_modulo($liga, $k);
}

/** Filtra una lista de modalidades (o un arreglo modalidad => datos) a las que la cuenta tiene. */
function filtrar_modalidades(?array $liga, array $lista): array
{
    if (array_is_list($lista)) return array_values(array_filter($lista, fn($m) => modalidad_permitida($liga, (string)$m)));
    return array_filter($lista, fn($m) => modalidad_permitida($liga, (string)$m), ARRAY_FILTER_USE_KEY);
}

/** Exige que la cuenta tenga el módulo de una modalidad (para crear o cambiar campeonatos). */
function exigir_modalidad(?array $liga, string $mod): void
{
    if (modalidad_permitida($liga, $mod)) return;
    $k = modulo_de_modalidad($mod);
    throw new RuntimeException('Su cuenta no tiene el módulo ' . nombre_modulo_deporte($k) . '. Para usar ' . nombre_modalidad($mod)
        . ' agréguelo en ⭐ Mi plan o pídalo a ' . ajuste('empresa', 'QF Producciones') . '.');
}

function exigir_deporte_escuela(?array $liga, string $d): void
{
    if (deporte_escuela_permitido($liga, $d)) return;
    throw new RuntimeException('Su cuenta no tiene el módulo ' . nombre_modulo_deporte(modulo_de_deporte_escuela($d)) . '. Agréguelo en ⭐ Mi plan para usar ' . nombre_deporte_escuela($d) . '.');
}

/** Texto corto de una lista de módulos: "⚽ Fútbol + 🚴 Ciclismo". */
function texto_modulos(array $ks): string
{
    return implode(' + ', array_map('nombre_modulo_deporte', $ks));
}

/** Lee la lista de módulos enviada en un formulario (checkboxes name="deportes[]"). */
function leer_deportes_post(string $campo = 'deportes'): array
{
    $v = array_values(array_intersect(array_keys(modulos_deporte_base()), array_map('strval', (array)($_POST[$campo] ?? []))));
    return $v;
}

/* ============================================================
 * PRECIO
 * ============================================================ */

function modulo_descuento(): float
{
    return max(0, min(90, (float)str_replace(',', '.', (string)ajuste('modulo_descuento', 30))));
}

function modulo_incluido(): bool
{
    return (string)ajuste('modulo_incluido', '1') === '1';
}

/**
 * Precio mensual de los módulos: el más caro va primero; si el plan incluye un módulo, ese no se cobra;
 * los demás llevan el descuento desde el 2.º módulo. Devuelve ['extra' => $ por mes, 'detalle' => [[clave, precio, desc %, final]]].
 */
function precio_modulos(array $ks): array
{
    $mods = modulos_deporte();
    $ks = array_values(array_filter($ks, fn($k) => isset($mods[$k])));
    usort($ks, fn($a, $b) => [$mods[$b]['precio'], $mods[$a]['orden']] <=> [$mods[$a]['precio'], $mods[$b]['orden']]);
    $det = [];
    $total = 0.0;
    foreach ($ks as $i => $k) {
        $p = $mods[$k]['precio'];
        if ($i === 0 && modulo_incluido()) { $det[] = [$k, $p, 100.0, 0.0]; continue; }
        $d = $i === 0 ? 0.0 : modulo_descuento();
        $f = round($p * (1 - $d / 100), 2);
        $det[] = [$k, $p, $d, $f];
        $total += $f;
    }
    return ['extra' => round($total, 2), 'detalle' => $det];
}

/** Texto del detalle: "⚽ Fútbol: incluido en el plan · 🚴 Ciclismo: $7,00 (30 % menos)". */
function texto_precio_modulos(array $ks): string
{
    $t = [];
    foreach (precio_modulos($ks)['detalle'] as [$k, $p, $d, $f]) {
        $t[] = nombre_modulo_deporte($k) . ': ' . ($d >= 100 ? 'incluido en el plan' : dinero($f) . ' al mes' . ($d > 0 ? ' (' . rtrim(rtrim(number_format($d, 1, ',', ''), '0'), ',') . ' % menos)' : ''));
    }
    return implode(' · ', $t);
}

/* ============================================================
 * REQUISITOS (datos de inscripción y documentos obligatorios)
 * ============================================================ */

/** Datos que se pueden pedir: clave => [etiqueta, tipo (texto|numero|fecha|opcion), opciones, ayuda]. */
function requisitos_campos_catalogo(): array
{
    return [
        'sangre' => ['Tipo de sangre', 'opcion', ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'], ''],
        'emergencia_nombre' => ['Contacto de emergencia (nombre)', 'texto', [], ''],
        'emergencia_tel' => ['Contacto de emergencia (teléfono)', 'texto', [], ''],
        'alergias' => ['Alergias o condiciones médicas', 'texto', [], 'Escriba «ninguna» si no tiene.'],
        'seguro' => ['Seguro médico o de accidentes (aseguradora y póliza)', 'texto', [], ''],
        'licencia' => ['N.° de licencia federativa', 'texto', [], 'La licencia de la federación (UCI, FEB, etc.).'],
        'club_federado' => ['Club o asociación federada', 'texto', [], ''],
        'peso' => ['Peso (kg)', 'numero', [], 'El de la última pesada.'],
        'estatura' => ['Estatura (cm)', 'numero', [], ''],
        'grado' => ['Grado o cinturón', 'texto', [], ''],
        'bicicleta' => ['Bicicleta (marca y tipo)', 'texto', [], ''],
        'talla' => ['Talla de camiseta', 'opcion', ['XS', 'S', 'M', 'L', 'XL', 'XXL'], ''],
        'posicion' => ['Posición', 'texto', [], ''],
        'mano' => ['Mano hábil', 'opcion', ['Derecha', 'Izquierda', 'Ambas'], ''],
        'representante' => ['Representante (si es menor de edad)', 'texto', [], 'Nombre y cédula del padre, madre o tutor.'],
        // (v36)
        'gafas' => ['Gafas protectoras homologadas', 'opcion', ['Sí', 'No'], 'Squash: obligatorias para menores de 19 años y en dobles.'],
        'elo' => ['ELO / rating', 'numero', [], 'FIDE o nacional; vacío si no tiene.'],
        'gamertag' => ['Usuario del juego (gamertag / ID)', 'texto', [], ''],
        'plataforma' => ['Plataforma', 'opcion', ['PlayStation', 'Xbox', 'PC', 'Nintendo', 'Móvil'], ''],
        'marca_personal' => ['Mejor marca personal', 'texto', [], 'Ej: 10K en 45:30.'],
        'talla_calzado' => ['Talla de calzado', 'texto', [], ''],
    ];
}

/** Requisitos de fábrica de cada módulo: campos [clave => 0 no | 1 se pide | 2 obligatorio] y documentos. */
function requisitos_fabrica(string $k): array
{
    $d = fn(string $c, string $n, int $obl, int $vence = 0) => ['clave' => $c, 'nombre' => $n, 'obligatorio' => $obl, 'vence' => $vence];
    return match ($k) {
        'futbol' => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1, 'posicion' => 1, 'representante' => 1],
            'docs' => [$d('cedula', 'Copia de la cédula', 1), $d('medico', 'Certificado médico', 0, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'basquet', 'voley', 'ecuavoley', 'balonmano', 'beisbol' => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1, 'talla' => 1],
            'docs' => [$d('cedula', 'Copia de la cédula', 1), $d('medico', 'Certificado médico', 0, 1)]],
        'ciclismo' => ['campos' => ['licencia' => 2, 'club_federado' => 1, 'bicicleta' => 1, 'sangre' => 2, 'emergencia_nombre' => 2, 'emergencia_tel' => 2, 'seguro' => 2, 'alergias' => 1, 'representante' => 1],
            'docs' => [$d('licencia', 'Licencia federativa / UCI', 1, 1), $d('medico', 'Certificado médico', 1, 1), $d('seguro', 'Seguro de accidentes', 1, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'automovilismo' => ['campos' => ['licencia' => 2, 'club_federado' => 1, 'sangre' => 2, 'emergencia_nombre' => 2, 'emergencia_tel' => 2, 'seguro' => 2, 'alergias' => 1],
            'docs' => [$d('licencia', 'Licencia de piloto', 1, 1), $d('conducir', 'Licencia de conducir', 1, 1), $d('medico', 'Certificado médico', 1, 1), $d('seguro', 'Seguro de accidentes', 1, 1), $d('vehiculo', 'Revisión técnica del vehículo', 0, 1)]],
        'raqueta' => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1, 'mano' => 1, 'licencia' => 1, 'gafas' => 1],
            'docs' => [$d('medico', 'Certificado médico', 0, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'atletismo' => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1, 'licencia' => 1, 'club_federado' => 1, 'alergias' => 1, 'talla' => 1, 'marca_personal' => 1],
            'docs' => [$d('medico', 'Certificado médico', 0, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'baile' => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1, 'alergias' => 1, 'representante' => 1],
            'docs' => [$d('autorizacion', 'Autorización del representante (menores)', 0), $d('fotos', 'Autorización de fotos y videos del recital', 0)]],
        'musica' => ['campos' => ['emergencia_tel' => 1, 'representante' => 1],
            'docs' => [$d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'natacion' => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1, 'licencia' => 1, 'club_federado' => 1, 'alergias' => 1],
            'docs' => [$d('medico', 'Certificado médico', 0, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'combate' => ['campos' => ['peso' => 2, 'estatura' => 1, 'grado' => 2, 'sangre' => 2, 'emergencia_nombre' => 2, 'emergencia_tel' => 2, 'alergias' => 1, 'representante' => 1],
            'docs' => [$d('medico', 'Certificado médico de aptitud', 1, 1), $d('grado', 'Carné o certificado de grado', 0), $d('seguro', 'Seguro de accidentes', 0, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        // (v36)
        'ajedrez' => ['campos' => ['elo' => 1, 'licencia' => 1, 'club_federado' => 1, 'emergencia_tel' => 1, 'representante' => 1],
            'docs' => [$d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'pesas' => ['campos' => ['peso' => 2, 'sangre' => 1, 'emergencia_nombre' => 1, 'emergencia_tel' => 1, 'licencia' => 1, 'club_federado' => 1, 'representante' => 1],
            'docs' => [$d('medico', 'Certificado médico de aptitud', 1, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'triatlon' => ['campos' => ['licencia' => 1, 'club_federado' => 1, 'bicicleta' => 1, 'sangre' => 2, 'emergencia_nombre' => 2, 'emergencia_tel' => 2, 'seguro' => 1, 'talla' => 1, 'representante' => 1],
            'docs' => [$d('medico', 'Certificado médico', 1, 1), $d('seguro', 'Seguro de accidentes', 0, 1), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'esports' => ['campos' => ['gamertag' => 2, 'plataforma' => 1, 'emergencia_tel' => 1, 'representante' => 1],
            'docs' => [$d('cedula', 'Copia de la cédula', 0), $d('autorizacion', 'Autorización del representante (menores)', 0)]],
        'olimpiadas' => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1, 'talla' => 1, 'seguro' => 1],
            'docs' => [$d('cedula', 'Copia de la cédula', 1), $d('laboral', 'Certificado laboral o carnet de la empresa', 1), $d('medico', 'Certificado médico', 0, 1)]],
        default => ['campos' => ['emergencia_nombre' => 1, 'emergencia_tel' => 1, 'sangre' => 1],
            'docs' => [$d('medico', 'Certificado médico', 0, 1)]],
    };
}

/** Deja los requisitos en forma correcta (lo guardado o, si no hay, los de fábrica). */
function requisitos_normalizar($r, string $k): array
{
    if (!is_array($r) || !isset($r['campos'])) $r = requisitos_fabrica($k);
    $cat = requisitos_campos_catalogo();
    $campos = [];
    foreach ((array)$r['campos'] as $c => $v) if (isset($cat[$c]) && (int)$v > 0) $campos[$c] = min(2, (int)$v);
    $docs = [];
    foreach ((array)($r['docs'] ?? []) as $x) {
        $c = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($x['clave'] ?? '')));
        $n = trim((string)($x['nombre'] ?? ''));
        if ($c === '' || $n === '') continue;
        $docs[$c] = ['clave' => $c, 'nombre' => mb_substr($n, 0, 80), 'obligatorio' => (int)($x['obligatorio'] ?? 0) ? 1 : 0, 'vence' => (int)($x['vence'] ?? 0) ? 1 : 0];
    }
    return ['campos' => $campos, 'docs' => array_values($docs)];
}

function requisitos_modulo(string $k): array
{
    if ($k === '') return ['campos' => [], 'docs' => []];
    if (preg_match('/^olimp_e(\d+)$/', $k, $m) && function_exists('olimp_requisitos_evento')) return olimp_requisitos_evento((int)$m[1]); // (v37) requisitos de cada evento
    return modulos_deporte()[$k]['requisitos'] ?? requisitos_normalizar(null, $k);
}

/** Tipos de persona que llevan requisitos: tabla => nombre. */
function requisitos_tablas(): array
{
    return ['jugadores' => 'Jugador', 'carrera_participantes' => 'Competidor', 'duelo_participantes' => 'Competidor', 'prueba_participantes' => 'Competidor', 'esc_alumnos' => 'Alumno',
        'inscripciones_indiv' => 'Inscripción en línea', 'olimp_deportistas' => 'Deportista (olimpiadas)'];
}

/** Datos que la ficha de cada tabla ya tiene en su propio formulario: campo del requisito => columna (no se repiten). */
function requisitos_nativos(string $tabla): array
{
    return [
        'esc_alumnos' => ['sangre' => 'tipo_sangre', 'alergias' => 'alergias', 'seguro' => 'seguro', 'emergencia_nombre' => 'emergencia_nombre', 'emergencia_tel' => 'emergencia_telefono',
            'representante' => 'representante_nombre', 'posicion' => 'posicion'],
        'jugadores' => ['posicion' => 'posicion', 'representante' => 'representante_nombre'],
        'duelo_participantes' => ['peso' => 'peso', 'grado' => 'grado'],
        'inscripciones_indiv' => ['peso' => 'peso', 'grado' => 'grado'],
    ][$tabla] ?? [];
}

/** Datos y documentos guardados de una persona en un módulo. */
function requisitos_de(string $tabla, int $ref, string $mod): array
{
    $d = fila('SELECT datos FROM requisitos_datos WHERE tabla = ? AND ref_id = ? AND modulo = ?', [$tabla, $ref, $mod]);
    $docs = [];
    foreach (filas('SELECT * FROM requisitos_docs WHERE tabla = ? AND ref_id = ? AND modulo = ?', [$tabla, $ref, $mod]) as $x) $docs[$x['doc']] = $x;
    return ['datos' => json_decode((string)($d['datos'] ?? ''), true) ?: [], 'docs' => $docs];
}

/** Lo que le falta a una persona: lista de textos (vacía = completo). */
function requisitos_faltan(string $tabla, int $ref, string $mod): array
{
    $req = requisitos_modulo($mod);
    $g = requisitos_de($tabla, $ref, $mod);
    $cat = requisitos_campos_catalogo();
    $nat = requisitos_nativos($tabla);
    $fila = $nat && isset(requisitos_tablas()[$tabla]) ? (fila("SELECT * FROM $tabla WHERE id = ?", [$ref]) ?? []) : [];
    $f = [];
    foreach ($req['campos'] as $c => $v) {
        if ($v !== 2) continue;
        $val = isset($nat[$c]) ? (string)($fila[$nat[$c]] ?? '') : (string)($g['datos'][$c] ?? '');
        if ($c === 'representante' && isset($nat[$c])) continue; // solo aplica a menores: lo valida la ficha
        if (trim($val) === '') $f[] = $cat[$c][0];
    }
    foreach ($req['docs'] as $doc) {
        if (!$doc['obligatorio']) continue;
        $x = $g['docs'][$doc['clave']] ?? null;
        if (!$x) $f[] = $doc['nombre'];
        elseif ($doc['vence'] && $x['vence'] !== '' && $x['vence'] < date('Y-m-d')) $f[] = $doc['nombre'] . ' (vencido)';
    }
    return $f;
}

/** Etiqueta para listas: ✅ requisitos completos / ⚠ faltan N. */
function html_estado_requisitos(string $tabla, int $ref, string $mod): string
{
    $req = requisitos_modulo($mod);
    if (!$req['campos'] && !$req['docs']) return '';
    $f = requisitos_faltan($tabla, $ref, $mod);
    return $f ? '<span class="etiqueta roja" title="' . e('Falta: ' . implode(', ', $f)) . '">⚠ falta' . (count($f) > 1 ? 'n ' . count($f) : ' 1') . '</span>'
              : '<span class="etiqueta" title="Requisitos completos">✅ requisitos</span>';
}

/** Campos y documentos del módulo dentro de un formulario (que debe tener enctype="multipart/form-data" para los documentos). */
function html_requisitos_form(string $mod, string $tabla = '', int $ref = 0, bool $publico = false, bool $exigir = true): string
{
    $obl = $publico && $exigir; // (v37) en el portal de la delegación los documentos se pueden subir después
    $req = requisitos_modulo($mod);
    if (!$req['campos'] && !$req['docs']) return '';
    $g = $ref ? requisitos_de($tabla, $ref, $mod) : ['datos' => [], 'docs' => []];
    $cat = requisitos_campos_catalogo();
    $m = modulo_info($mod);
    if (!$m && preg_match('/^olimp_e(\d+)$/', $mod, $mm)) $m = ['icono' => '🏅', 'nombre' => (string)valor('SELECT nombre FROM olimp_eventos WHERE id = ?', [(int)$mm[1]])]; // (v37)
    ob_start(); ?>
    <fieldset class="requisitos-mod">
      <legend><?= e(($m['icono'] ?? '') . ' Requisitos de ' . ($m['nombre'] ?? $mod)) ?></legend>
      <?php if (array_diff_key($req['campos'], requisitos_nativos($tabla))): ?>
      <div class="campos">
        <?php foreach ($req['campos'] as $c => $v): if (isset(requisitos_nativos($tabla)[$c])) continue; [$lab, $tipo, $ops, $ayuda] = $cat[$c]; $val = (string)($g['datos'][$c] ?? ''); ?>
          <div><label><?= e($lab) ?><?= $v === 2 ? ' *' : '' ?></label>
            <?php if ($tipo === 'opcion'): ?>
              <select name="req[<?= e($c) ?>]"<?= $v === 2 && $obl ? ' required' : '' ?>><option value="">—</option><?php foreach ($ops as $o): ?><option value="<?= e($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
            <?php else: ?>
              <input type="<?= $tipo === 'numero' ? 'text' : 'text' ?>"<?= $tipo === 'numero' ? ' inputmode="decimal"' : '' ?> name="req[<?= e($c) ?>]" value="<?= e($val) ?>" maxlength="120"<?= $v === 2 && $obl ? ' required' : '' ?>>
            <?php endif; ?>
            <?php if ($ayuda !== ''): ?><div class="ayuda"><?= e($ayuda) ?></div><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($req['docs']): ?>
      <div class="campos">
        <?php foreach ($req['docs'] as $doc): $x = $g['docs'][$doc['clave']] ?? null; ?>
          <div><label><?= e($doc['nombre']) ?><?= $doc['obligatorio'] ? ' *' : '' ?></label>
            <input type="file" name="reqdoc_<?= e($doc['clave']) ?>" accept="image/*,application/pdf"<?= $doc['obligatorio'] && $obl && !$x ? ' required' : '' ?>>
            <?php if ($doc['vence']): ?><input type="date" name="reqvence[<?= e($doc['clave']) ?>]" value="<?= e($x['vence'] ?? '') ?>" title="Válido hasta" aria-label="Válido hasta" style="margin-top:4px"><div class="ayuda">Válido hasta (fecha de vencimiento).</div><?php endif; ?>
            <?php if ($x && !$publico): ?><div class="ayuda">✓ subido el <?= e(substr($x['subido'], 0, 10)) ?> · <a href="admin.php?p=requisito_doc&id=<?= (int)$x['id'] ?>" target="_blank">ver</a></div><?php elseif ($x): ?><div class="ayuda">✓ ya subido</div><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <p class="ayuda">Documentos en foto (JPG, PNG) o PDF, hasta 8 MB. Los campos con * son obligatorios para competir.</p>
      <?php endif; ?>
    </fieldset>
    <?php return (string)ob_get_clean();
}

/** Antes de guardar algo nuevo (inscripción en línea): revisa que vengan los datos y documentos obligatorios. */
function requisitos_validar_post(string $mod, string $tabla): void
{
    $req = requisitos_modulo($mod);
    $cat = requisitos_campos_catalogo();
    foreach ($req['campos'] as $c => $v) {
        if ($v !== 2 || isset(requisitos_nativos($tabla)[$c])) continue;
        if (trim((string)($_POST['req'][$c] ?? '')) === '') throw new RuntimeException('Falta: ' . $cat[$c][0] . '.');
    }
    foreach ($req['docs'] as $doc) {
        if (!$doc['obligatorio']) continue;
        $f = $_FILES['reqdoc_' . $doc['clave']] ?? null;
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) throw new RuntimeException('Falta subir: ' . $doc['nombre'] . ' (foto o PDF).');
    }
}

/** Guarda los campos y documentos enviados con html_requisitos_form(). Con $exigir, falla si faltan obligatorios. */
function guardar_requisitos(int $liga_id, string $mod, string $tabla, int $ref, bool $exigir = false): void
{
    if (!isset(requisitos_tablas()[$tabla]) || $ref <= 0) return;
    $req = requisitos_modulo($mod);
    if (!$req['campos'] && !$req['docs']) return;
    $cat = requisitos_campos_catalogo();
    $g = requisitos_de($tabla, $ref, $mod);
    $datos = $g['datos'];
    foreach ($req['campos'] as $c => $v) {
        if (!isset($_POST['req'][$c])) continue;
        $val = mb_substr(trim((string)$_POST['req'][$c]), 0, 120);
        if ($cat[$c][1] === 'opcion' && $val !== '' && !in_array($val, $cat[$c][2], true)) $val = '';
        if ($cat[$c][1] === 'numero' && $val !== '' && !is_numeric(str_replace(',', '.', $val))) throw new RuntimeException($cat[$c][0] . ': escriba solo el número.');
        $datos[$c] = $val;
    }
    if ($exigir) foreach ($req['campos'] as $c => $v) if ($v === 2 && !isset(requisitos_nativos($tabla)[$c]) && trim((string)($datos[$c] ?? '')) === '') throw new RuntimeException('Falta: ' . $cat[$c][0] . '.');
    q('INSERT INTO requisitos_datos (liga_id, tabla, ref_id, modulo, datos) VALUES (?,?,?,?,?) ON CONFLICT(tabla, ref_id, modulo) DO UPDATE SET datos = excluded.datos, actualizado = CURRENT_TIMESTAMP',
        [$liga_id, $tabla, $ref, $mod, json_encode($datos, JSON_UNESCAPED_UNICODE)]);
    foreach ($req['docs'] as $doc) {
        $f = $_FILES['reqdoc_' . $doc['clave']] ?? null;
        $vence = '';
        $vtxt = trim((string)($_POST['reqvence'][$doc['clave']] ?? ''));
        if ($vtxt !== '' && strtotime($vtxt)) $vence = date('Y-m-d', strtotime($vtxt));
        $ya = $g['docs'][$doc['clave']] ?? null;
        if ($f && ($f['error'] ?? 4) === UPLOAD_ERR_OK) {
            $ruta = requisitos_subir_archivo($f, $liga_id);
            if ($ya) {
                @unlink(RAIZ . '/data/' . $ya['archivo']);
                q('UPDATE requisitos_docs SET archivo = ?, vence = ?, subido = CURRENT_TIMESTAMP WHERE id = ?', [$ruta, $vence, $ya['id']]);
            } else {
                q('INSERT INTO requisitos_docs (liga_id, tabla, ref_id, modulo, doc, archivo, vence) VALUES (?,?,?,?,?,?,?)', [$liga_id, $tabla, $ref, $mod, $doc['clave'], $ruta, $vence]);
            }
        } elseif ($f && ($f['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No se pudo subir «' . $doc['nombre'] . '». Use una foto o un PDF de hasta 8 MB.');
        } elseif ($ya && $vence !== '' && $vence !== $ya['vence']) {
            q('UPDATE requisitos_docs SET vence = ? WHERE id = ?', [$vence, $ya['id']]);
        }
        if ($exigir && $doc['obligatorio'] && !$ya && !($f && ($f['error'] ?? 4) === UPLOAD_ERR_OK)) throw new RuntimeException('Falta subir: ' . $doc['nombre'] . '.');
    }
}

/** Guarda un documento privado (foto o PDF) en data/requisitos/. Devuelve la ruta relativa a data/. */
function requisitos_subir_archivo(array $f, int $liga_id): string
{
    if ((int)$f['size'] > 8 * 1024 * 1024) throw new RuntimeException('El documento pasa de 8 MB.');
    $mime = mime_content_type($f['tmp_name']) ?: '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? '';
    if ($ext === '') throw new RuntimeException('El documento debe ser una foto (JPG, PNG) o un PDF.');
    $dir = RAIZ . '/data/requisitos/' . $liga_id;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $nombre = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nombre) && !rename($f['tmp_name'], $dir . '/' . $nombre)) throw new RuntimeException('No se pudo guardar el documento.');
    return 'requisitos/' . $liga_id . '/' . $nombre;
}

/** Pasa los requisitos de una inscripción en línea a la ficha del competidor cuando se aprueba. */
function mover_requisitos(string $de_tabla, int $de_id, string $a_tabla, int $a_id): void
{
    q('UPDATE requisitos_datos SET tabla = ?, ref_id = ? WHERE tabla = ? AND ref_id = ?', [$a_tabla, $a_id, $de_tabla, $de_id]);
    q('UPDATE requisitos_docs SET tabla = ?, ref_id = ? WHERE tabla = ? AND ref_id = ?', [$a_tabla, $a_id, $de_tabla, $de_id]);
}

/** Borra los requisitos de una persona (cuando se elimina). */
function borrar_requisitos(string $tabla, int $ref): void
{
    foreach (filas('SELECT archivo FROM requisitos_docs WHERE tabla = ? AND ref_id = ?', [$tabla, $ref]) as $x) @unlink(RAIZ . '/data/' . $x['archivo']);
    q('DELETE FROM requisitos_docs WHERE tabla = ? AND ref_id = ?', [$tabla, $ref]);
    q('DELETE FROM requisitos_datos WHERE tabla = ? AND ref_id = ?', [$tabla, $ref]);
}

/** Módulo con el que se piden los requisitos de un jugador: el de los campeonatos de su equipo (o el de la modalidad de la liga). */
function modulo_de_equipo(int $equipo_id, array $liga): string
{
    $m = valor('SELECT t.modalidad FROM torneo_equipos te JOIN torneos t ON t.id = te.torneo_id WHERE te.equipo_id = ? ORDER BY t.id DESC LIMIT 1', [$equipo_id]);
    return modulo_de_modalidad((string)($m ?: ($liga['modalidad'] ?? 'F7'))) ?: 'futbol';
}

/* ============================================================
 * REGLAS POR DEFECTO DE CADA MODALIDAD
 * ============================================================ */

/** Reglas que el superadministrador puede fijar por modalidad: campo de torneos => [etiqueta, tipo (entero|texto|opcion), opciones]. */
function reglas_editables(string $mod): array
{
    $equipo = ['puntos_ganado' => ['Puntos por ganar', 'entero'], 'puntos_empate' => ['Puntos por empatar', 'entero'], 'puntos_perdido' => ['Puntos por perder', 'entero'],
        'goles_wo' => ['Goles / puntos del W.O.', 'entero'], 'jugadores_cancha' => ['Jugadores en cancha', 'entero'], 'min_para_jugar' => ['Mínimo para empezar', 'entero'],
        'max_suplentes' => ['Máximo de suplentes', 'entero'], 'max_cambios' => ['Máximo de cambios (0 = ilimitados)', 'entero'], 'min_plantilla' => ['Mínimo de inscritos', 'entero'],
        'max_plantilla' => ['Máximo de inscritos', 'entero'], 'num_tiempos' => ['Tiempos o cuartos', 'entero'], 'minutos_tiempo' => ['Minutos por tiempo', 'entero'],
        'amarillas_suspension' => ['Amarillas para suspensión', 'entero'], 'partidos_susp_amarillas' => ['Fechas de suspensión por amarillas', 'entero'],
        'partidos_susp_roja' => ['Fechas de suspensión por roja', 'entero'], 'num_arbitros' => ['Árbitros por partido', 'entero']];
    $sets = ['sets_ganar' => ['Sets para ganar', 'entero'], 'puntos_set' => ['Puntos por set', 'entero'], 'puntos_set_final' => ['Puntos del set decisivo', 'entero']];
    return match (true) {
        in_array($mod, ['F5', 'F6', 'F7', 'F8', 'F9', 'F11', 'OTRO', 'INDOR', 'BALON', 'BEISB', 'SOFTB'], true) => $equipo,
        in_array($mod, ['BASQ', 'BASQ3'], true) => $equipo + ['faltas_limite' => ['Faltas para salir', 'entero']],
        in_array($mod, ['VOLEY', 'ECUA'], true) => array_intersect_key($equipo, array_flip(['puntos_ganado', 'puntos_empate', 'puntos_perdido', 'goles_wo', 'jugadores_cancha', 'min_para_jugar', 'max_suplentes', 'max_cambios', 'min_plantilla', 'max_plantilla', 'amarillas_suspension', 'partidos_susp_roja', 'num_arbitros'])) + $sets,
        in_array($mod, ['CICLO', 'MTB', 'AUTOX'], true) => ['carrera_puntos' => ['Puntos por puesto (separados por comas)', 'texto'], 'carrera_mangas' => ['Etapas / mangas por fecha', 'entero'],
            'carrera_clasif' => ['Clasificación general', 'opcion', ['puntos' => 'Por puntos', 'tiempo' => 'Por tiempo total']], 'carrera_descarte' => ['Fechas que se descartan', 'entero']]
            + ($mod === 'CICLO' ? ['ciclo_joven' => ['Mejor joven: hasta cuántos años', 'entero']] : []),
        in_array($mod, ['TENIS', 'PADEL', 'TMESA', 'SQUASH'], true) => ['duelo_sets' => ['Sets / games del partido (3 o 5)', 'entero'], 'duelo_bronce' => ['Tercer puesto', 'opcion', ['no' => 'Sin tercer puesto', 'partido' => 'Partido por el 3.°', 'doble' => 'Dos bronces']]]
            + (in_array($mod, ['TMESA', 'SQUASH'], true) ? ['duelo_game_pts' => ['Puntos de cada game (11)', 'entero'], 'duelo_game_dif' => ['Fin del game', 'opcion', ['2' => 'Diferencia de 2', '1' => 'Punto de oro']]] : []),
        $mod === 'AJED' => ['duelo_bronce' => ['Tercer puesto (llave)', 'opcion', ['no' => 'Sin tercer puesto', 'partido' => 'Partida por el 3.°', 'doble' => 'Dos bronces']]],
        $mod === 'ESPOR' => ['duelo_pts_gana' => ['Puntos por ganar', 'entero'], 'duelo_pts_empate' => ['Puntos por empatar', 'entero'], 'duelo_bronce' => ['Tercer puesto', 'opcion', ['no' => 'Sin tercer puesto', 'partido' => 'Partido por el 3.°', 'doble' => 'Dos bronces']]],
        in_array($mod, ['BOXEO', 'MARC'], true) => ['duelo_rounds' => ['Rounds por combate', 'entero'], 'duelo_bronce' => ['Tercer puesto', 'opcion', ['no' => 'Sin tercer puesto', 'partido' => 'Combate por el 3.°', 'doble' => 'Dos bronces']]],
        $mod === 'BOLOS' => ['bolos_lineas' => ['Líneas por jornada', 'entero']],
        in_array($mod, ['NATAC', 'ATLET', 'PISTA', 'BMX', 'BMXFS', 'RUTA', 'PESAS', 'TRIAT'], true) => ['carrera_puntos' => ['Puntos por puesto para la clasificación por clubes', 'texto']],
        default => [],
    };
}

/** Reglas fijadas por el superadministrador para una modalidad (solo los campos válidos). */
function reglas_modulo_modalidad(string $mod): array
{
    $k = modulo_de_modalidad($mod);
    if ($k === '') return [];
    $r = (array)(modulos_deporte()[$k]['reglas'][$mod] ?? []);
    $ed = reglas_editables($mod);
    $o = [];
    foreach ($r as $c => $v) {
        if (!isset($ed[$c]) || $v === '' || $v === null) continue;
        $o[$c] = $ed[$c][1] === 'entero' ? (int)$v : (string)$v;
    }
    return $o;
}

/**
 * Aplica las reglas por defecto del módulo a un campeonato individual nuevo (carreras, duelos, bolos, pruebas).
 * Lo que el usuario eligió en el formulario (clasificación, líneas) se respeta. En deportes de equipo no hace falta:
 * presets_modalidad() ya trae las reglas del módulo y llenan el formulario.
 */
function aplicar_reglas_modulo(array $datos): array
{
    $mod = (string)($datos['modalidad'] ?? '');
    if (in_array($mod, modalidades(), true)) return $datos;
    $del_form = ['carrera_clasif' => 'clasif', 'bolos_lineas' => 'lineas', 'bolos_hcp' => 'hcp', 'bolos_clasif' => 'clasif'];
    foreach (reglas_modulo_modalidad($mod) as $c => $v) {
        if (isset($del_form[$c]) && isset($_POST[$del_form[$c]])) continue;
        $datos[$c] = $v;
    }
    return $datos;
}

/* ============================================================
 * HTML
 * ============================================================ */

/** Casillas para elegir módulos (crear cuenta, solicitar demo, registro, contratar). */
function html_casillas_modulos(array $sel, string $campo = 'deportes', bool $con_precio = false): string
{
    ob_start(); ?>
    <div class="casillas-modulos">
      <?php foreach (modulos_deporte(true) as $k => $m): ?>
        <label class="casilla-mod"><input type="checkbox" name="<?= e($campo) ?>[]" value="<?= e($k) ?>" data-precio="<?= e((string)$m['precio']) ?>" <?= in_array($k, $sel, true) ? 'checked' : '' ?>>
          <span><b><?= $m['icono'] ?> <?= e($m['nombre']) ?></b><?= $con_precio ? ' <small class="suave">' . dinero($m['precio']) . '/mes</small>' : '' ?><br><small class="suave"><?= e($m['descripcion']) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <style>.casillas-modulos{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;margin:6px 0}.casilla-mod{display:flex;gap:8px;align-items:flex-start;border:1px solid var(--borde,#d8dfdc);border-radius:10px;padding:8px 10px;font-weight:400;cursor:pointer}.casilla-mod input{margin-top:4px}.casilla-mod:has(input:checked){border-color:var(--acento,#0b6e4f);background:rgba(11,110,79,.06)}</style>
    <?php return (string)ob_get_clean();
}

/** Tarjeta "Agregue más deportes": los módulos que la cuenta no tiene (para Campeonatos, Grupos, Mi plan). */
function html_modulos_bloqueados(?array $liga): string
{
    if (!$liga) return '';
    $faltan = array_diff_key(modulos_deporte(true), array_flip(deportes_liga($liga)));
    if (!$faltan) return '';
    ob_start(); ?>
    <div class="tarjeta modulos-mas" style="margin-top:20px">
      <h2 style="margin-top:0">🧩 Agregue más deportes a su cuenta</h2>
      <p class="suave" style="margin-top:0">Su cuenta tiene <b><?= e(texto_modulos(deportes_liga($liga))) ?></b>. Cada módulo deportivo se agrega a su plan con <?= modulo_descuento() > 0 ? '<b>' . e(rtrim(rtrim(number_format(modulo_descuento(), 1, ',', ''), '0'), ',')) . ' % de descuento</b>' : 'su precio' ?> y queda todo en la misma cuenta: un solo panel, una sola página pública y los mismos usuarios.</p>
      <div class="rejilla-modulos-mas">
        <?php foreach ($faltan as $k => $m): ?>
          <div class="mod-bloq"><b><?= $m['icono'] ?> <?= e($m['nombre']) ?> 🔒</b><br><small class="suave"><?= e($m['descripcion']) ?></small><br><small><?= dinero($m['precio']) ?>/mes</small></div>
        <?php endforeach; ?>
      </div>
      <?php if (!es_super()): ?><p style="margin-bottom:0"><a class="btn btn-sec" href="admin.php?p=mi_plan#contratar">⭐ Agregar módulos en Mi plan</a></p><?php endif; ?>
    </div>
    <style>.rejilla-modulos-mas{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}.mod-bloq{border:1px dashed var(--borde,#cfd8d4);border-radius:10px;padding:8px 10px;opacity:.9}</style>
    <?php return (string)ob_get_clean();
}

/** Aviso dentro de un campeonato cuyo módulo la cuenta no tiene (solo lectura). */
function html_aviso_modulo_bloqueado(array $t): string
{
    $k = modulo_de_torneo($t);
    return '<div class="aviso aviso-error">🔒 Este campeonato es del módulo <b>' . e(nombre_modulo_deporte($k)) . '</b>, que su cuenta ya no tiene. Puede verlo, pero para cambiarlo agregue el módulo en <a href="admin.php?p=mi_plan#contratar">⭐ Mi plan</a>.</div>';
}
