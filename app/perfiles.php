<?php
/*
 * VERSIÓN 7 — Perfiles de los usuarios de una liga.
 * Todos tienen rol 'liga' (entran al panel de su liga); el PERFIL decide qué menús ven y qué pueden cambiar.
 * El superadministrador y el perfil "Administrador" pueden todo.
 */

/** Perfiles disponibles: clave => [nombre, descripción]. */
function perfiles(): array
{
    return [
        'admin' => ['Administrador', 'Todo: equipos, campeonatos, finanzas, redes, parámetros y usuarios de la liga.'],
        // (v30) Debajo del administrador: el administrador elige qué secciones ve y en cuáles hace cambios
        'asistente' => ['Asistente', 'Usted elige, sección por sección, qué ve y en qué puede hacer cambios (nunca usuarios).'],
        'secretario' => ['Secretario', 'Equipos, jugadores, carnets, inscripciones, campeonatos, fixture, partidos, árbitros y reservas de canchas.'],
        'tesorero' => ['Tesorero', 'Finanzas: cobros, comprobantes, recibos, pago a árbitros, egresos y reservas (ve equipos y campeonatos).'],
        'disciplina' => ['Comisión disciplinaria', 'Sanciones, multas y resultados de los partidos (ve campeonatos y equipos).'],
        'comunicacion' => ['Comunicación', 'Imágenes para redes, patrocinadores, votaciones y polla (ve campeonatos).'],
        'consulta' => ['Solo consulta', 'Ve todo, pero no puede cambiar nada.'],
        // (v11) Escuelas
        'profesor' => ['Profesor (escuelas)', 'Toma asistencia, planifica sus clases y registra medidas, tests, evaluaciones y partidos de sus grupos.'],
        // (v17) Clubes y academias con divisiones formativas
        'staff' => ['Cuerpo técnico (escuelas)', 'Preparador físico, psicólogo, nutricionista o médico: ve a los alumnos y registra evaluaciones, notas de su área, lesiones y maduración.'],
        // (v37) Olimpiadas: solo maneja los eventos que se le asignan (y sus campeonatos)
        'evento' => ['Administrador de evento (olimpiadas)', 'Solo los eventos de olimpiadas que se le asignan: configuración, disciplinas, delegaciones, deportistas, resultados de sus campeonatos y medallero.'],
    ];
}

/** (v17) Perfiles que tienen sentido para una liga o para una escuela (el superadministrador ve todos). */
function perfiles_para(?array $liga): array
{
    $todos = perfiles();
    if (!$liga) return $todos;
    $claves = es_escuela($liga) ? ['admin', 'asistente', 'secretario', 'tesorero', 'profesor', 'staff', 'consulta'] : ['admin', 'asistente', 'secretario', 'tesorero', 'disciplina', 'comunicacion', 'consulta', 'evento'];
    $r = array_intersect_key($todos, array_flip($claves));
    if (es_escuela($liga)) $r = array_replace($r, array_intersect_key(perfiles_escuela(), $r)); // (arreglos 14-oct) sin equipos, campeonatos ni árbitros
    return $r;
}

/** (arreglos 14-oct) Nombres y descripciones de los perfiles para una academia o escuela (lo que de verdad ve cada uno). */
function perfiles_escuela(): array
{
    $org = es_academia() ? 'academia' : 'escuela';
    $cursos = es_academia() ? 'cursos' : 'grupos';
    return [
        'admin' => ['Administrador', 'Todo: alumnos, ' . $cursos . ', asistencia, pagos, profesores, parámetros y usuarios de la ' . $org . '.'],
        'secretario' => ['Secretario', 'Alumnos, ' . $cursos . ', inscripciones, carnets, asistencia, evaluaciones y tienda (ve los pagos, pero no cobra).'],
        'tesorero' => ['Tesorero', 'Pagos: pensiones, cobros, comprobantes, recibos, gastos, profesores y tienda (ve a los alumnos).'],
        'profesor' => ['Profesor', 'Toma asistencia, planifica sus clases y registra evaluaciones y niveles de sus ' . $cursos . '.'],
        'staff' => ['Equipo de apoyo', 'Psicólogo, nutricionista, fisioterapeuta o médico: ve a los alumnos y registra evaluaciones y notas de su área.'],
    ];
}

function nombre_perfil(string $p): string
{
    if (($l = liga_trabajo()) && es_escuela($l) && isset(perfiles_escuela()[$p])) return perfiles_escuela()[$p][0]; // (arreglos 14-oct)
    return perfiles()[$p][0] ?? 'Administrador';
}

/** Área de cada página del panel ('*' = todos los usuarios; 'super' = solo superadministrador). */
function area_pagina(string $p): string
{
    return [
        'inicio' => '*', 'seguridad' => '*', 'clave' => '*', 'salir' => '*', 'login' => '*',
        'equipos' => 'equipos', 'equipo' => 'equipos', 'jugador' => 'equipos', 'carnets' => 'equipos', 'importar' => 'equipos',
        'inscripciones' => 'inscripciones',
        'torneos' => 'campeonatos', 'torneo' => 'campeonatos', 'carrera' => 'campeonatos', 'duelo' => 'campeonatos', 'bolos' => 'campeonatos', 'pruebas' => 'campeonatos', 'indiv' => 'campeonatos', 'programar' => 'campeonatos',
        'partido' => 'partidos', 'planilla' => 'partidos', 'imprimir' => 'partidos',
        'arbitros' => 'arbitros',
        'finanzas' => 'finanzas', 'recibo' => 'finanzas',
        'redes' => 'comunicacion', 'patrocinadores' => 'comunicacion', 'votaciones' => 'comunicacion', 'polla' => 'comunicacion', 'widgets' => 'comunicacion',
        'liquidacion' => 'finanzas', 'reservas' => 'reservas',
        'bitacora' => 'bitacora', 'config' => 'config', 'usuarios' => 'usuarios',
        'ligas' => 'super', 'planes' => 'super', 'suscripciones' => 'super', 'ajustes' => 'super', 'vendedores' => 'super', 'demos' => 'super', 'modulos' => 'super', 'requisito_doc' => '*', 'requisitos' => '*', // (v35)
        'negocio' => 'super', 'pedidos' => 'campeonatos', 'acumuladas' => 'campeonatos',
        // (v12)
        'mi_plan' => 'config', 'pagos_linea' => 'finanzas', 'pasarela' => 'super', 'google_play' => 'super', 'mi_rol' => '*',
        // (v11) Escuelas
        'alumnos' => 'alumnos', 'alumno' => 'alumnos', 'grupos' => 'alumnos', 'esc_carnets' => 'alumnos',
        'asistencia' => 'clases', 'evaluaciones' => 'evaluaciones', 'competencias' => 'evaluaciones', 'examenes' => 'evaluaciones',
        'cobros' => 'cobros', 'esc_recibo' => 'cobros', 'profesores' => 'profesores', 'tienda' => 'tienda', 'escuela_config' => 'config',
        // (v17)
        'planificacion' => 'clases', 'formativas' => 'formativas',
        // (v24) Protección de datos
        'datos' => 'config',
        // (v27) Soporte y novedades: todos; subida masiva y descarga de datos: configuración; embudo de demos: superadministrador
        'soporte' => '*', 'novedades' => '*', 'embudo' => 'super', 'cargas' => 'config', 'mis_datos' => 'config',
        // (v28)
        'federacion' => 'config', 'videos' => 'equipos', 'indicadores' => 'config', 'referidos' => 'config', 'entradas' => 'finanzas',
        // (v37)
        'olimpiadas' => 'olimpiadas',
        // (TuAcademia)
        'mensajes' => 'cobros', 'recitales' => 'alumnos', 'particulares' => 'clases', 'avisos_padres' => 'cobros',
    ][$p] ?? 'config';
}

/** Qué áreas ve y cuáles puede cambiar cada perfil. */
function permisos_perfil(string $perfil, ?array $u = null): array
{
    if ($perfil === 'asistente') return permisos_asistente($u ?? usuario()); // (v30) permisos a medida
    $todas = ['equipos', 'inscripciones', 'campeonatos', 'partidos', 'disciplina', 'arbitros', 'finanzas', 'comunicacion', 'reservas', 'bitacora', 'config', 'usuarios',
        'alumnos', 'clases', 'evaluaciones', 'cobros', 'profesores', 'tienda', 'formativas', 'olimpiadas'];
    // (v37) Administrador de evento: dentro de sus eventos maneja campeonatos, partidos, equipos y sanciones (lo limita olimp_puede_pagina_evento)
    if ($perfil === 'evento') return ['ver' => ['olimpiadas', 'campeonatos', 'partidos', 'equipos', 'disciplina'], 'editar' => ['olimpiadas', 'campeonatos', 'partidos', 'equipos', 'disciplina']];
    return match ($perfil) {
        'secretario' => ['ver' => ['equipos', 'inscripciones', 'campeonatos', 'partidos', 'disciplina', 'arbitros', 'reservas', 'alumnos', 'clases', 'evaluaciones', 'tienda', 'cobros', 'formativas', 'olimpiadas'],
                         'editar' => ['equipos', 'inscripciones', 'campeonatos', 'partidos', 'arbitros', 'reservas', 'alumnos', 'clases', 'evaluaciones', 'tienda', 'formativas', 'olimpiadas']],
        'tesorero' => ['ver' => ['finanzas', 'equipos', 'campeonatos', 'disciplina', 'reservas', 'alumnos', 'cobros', 'profesores', 'tienda'], 'editar' => ['finanzas', 'reservas', 'cobros', 'profesores', 'tienda']],
        'profesor' => ['ver' => ['alumnos', 'clases', 'evaluaciones', 'formativas'], 'editar' => ['clases', 'evaluaciones', 'formativas']],
        'staff' => ['ver' => ['alumnos', 'clases', 'evaluaciones', 'formativas'], 'editar' => ['evaluaciones', 'formativas']],
        'disciplina' => ['ver' => ['campeonatos', 'partidos', 'disciplina', 'equipos', 'arbitros'], 'editar' => ['disciplina', 'partidos']],
        'comunicacion' => ['ver' => ['comunicacion', 'campeonatos', 'partidos', 'equipos'], 'editar' => ['comunicacion']],
        'consulta' => ['ver' => array_values(array_diff($todas, ['usuarios'])), 'editar' => []],
        default => ['ver' => $todas, 'editar' => $todas],
    };
}

/** Perfil del usuario conectado ('super' para el superadministrador). */
function perfil_actual(): string
{
    $u = usuario();
    if (!$u) return '';
    if ($u['rol'] === 'super') return 'super';
    $p = (string)($u['perfil'] ?? 'admin');
    return isset(perfiles()[$p]) ? $p : 'admin';
}

/** ¿El usuario conectado puede ver (o cambiar) un área? */
function puede(string $area, bool $editar = false): bool
{
    $perfil = perfil_actual();
    if ($perfil === '') return false;
    if ($area === '*') return true;
    if ($perfil === 'super') return true;
    if ($area === 'super') return false;
    $perm = permisos_perfil($perfil);
    return in_array($area, $perm[$editar ? 'editar' : 'ver'], true);
}

/**
 * Permiso para una página y, al guardar, para la acción del formulario.
 * Algunas acciones de una página pertenecen a otra área (ej: las sanciones del campeonato son de la comisión disciplinaria).
 */
function puede_pagina(string $p, bool $editar = false, string $accion = ''): bool
{
    if (perfil_actual() === 'evento') return olimp_puede_pagina_evento($p, $editar); // (v37) solo sus eventos
    $area = area_pagina($p);
    // (v18) La contabilidad es de quien maneja el dinero: Finanzas en una liga, Pagos en una escuela
    if ($p === 'contabilidad') return puede('finanzas', $editar) || puede('cobros', $editar);
    // (v28) Videos: en una liga quien maneja equipos; en una escuela quien ve alumnos y quien da clases o evalúa
    if ($p === 'indicadores') return puede('config', $editar) || puede('finanzas', $editar) || puede('cobros', $editar); // (v28)
    if ($p === 'videos') return puede('equipos', $editar) || puede('alumnos', $editar) || puede('clases', $editar) || puede('evaluaciones', $editar);
    if (!$editar) {
        // La pestaña de disciplina del campeonato la ven también el tesorero (multas) y la comisión
        if ($p === 'torneo' && (puede('disciplina') || puede('finanzas'))) return true;
        // (v10) Los pedidos de los delegados los ve también la comisión disciplinaria (descargos y apelaciones)
        if ($p === 'pedidos' && puede('disciplina')) return true;
        // (v12) Los cobros con tarjeta los ve quien ve finanzas (ligas) o pagos (escuelas)
        if ($p === 'pagos_linea' && puede('cobros')) return true;
        return puede($area);
    }
    if ($p === 'torneo') {
        if (in_array($accion, ['sancion', 'sancion_equipo', 'quitar_sancion', 'quitar_sancion_equipo', 'figura', 'quitar_figura'], true)) return puede('disciplina', true);
        if ($accion === 'pagar_multa') return puede('disciplina', true) || puede('finanzas', true);
    }
    if ($p === 'partido' && in_array($accion, ['datos', 'eliminar'], true)) return puede('campeonatos', true);
    if ($p === 'pagos_linea') return puede('finanzas', true) || puede('cobros', true);
    // (v17) En la ficha del alumno, cada acción pertenece a su área (el profesor y el cuerpo técnico evalúan, la secretaría edita datos, el tesorero cobra)
    if ($p === 'alumno') {
        if (in_array($accion, ['medida', 'borrar_medida', 'test', 'tecnica', 'libreta', 'meta', 'meta_avance', 'lesion', 'alta', 'lesion_seg', 'padres_talla'], true)) return puede('evaluaciones', true) || puede('alumnos', true) || puede('formativas', true);
        if (in_array($accion, ['staff_nota', 'borrar_staff_nota', 'valoracion', 'academico'], true)) return puede('formativas', true);
        if (in_array($accion, ['cargo', 'cobrar', 'anular_cargo'], true)) return puede('cobros', true);
    }
    if ($p === 'pedidos') return $accion === 'resolver_disciplina' ? puede('disciplina', true) : puede('campeonatos', true);
    return puede($area, true);
}
