<?php
/*
 * VERSIÓN 30
 *  - Perfil ASISTENTE (ligas y escuelas): está debajo del Administrador y el administrador elige, sección por sección,
 *    qué ve y en qué puede hacer cambios. Nunca puede administrar usuarios (así no puede darse más permisos).
 *    Los permisos se guardan en usuarios.permisos como JSON {"ver": [...], "editar": [...]} con las áreas de perfiles.php.
 *  - Manuales en español, inglés, francés, portugués e italiano (app/manuales/<manual>.<idioma>.html).
 */

/** Secciones que se le pueden dar a un asistente: área => [ícono, nombre, qué incluye]. */
function areas_asistente(?array $liga): array
{
    if ($liga && es_escuela($liga)) {
        return [
            'alumnos' => ['👦', 'Alumnos y grupos', 'Fichas de los alumnos, grupos, horarios, sedes y carnets.'],
            'clases' => ['✅', 'Asistencia y entrenamientos', 'Tomar lista y planificar las clases.'],
            'evaluaciones' => ['📊', 'Evaluaciones', 'Tests, medidas, técnica, competencias y libreta.'],
            'cobros' => ['💵', 'Pagos y contabilidad', 'Mensualidades, cobros, recibos, cobros con tarjeta y contabilidad.'],
            'profesores' => ['🧑‍🏫', 'Profesores', 'Profesores, días de clase y rol de pago.'],
            'tienda' => ['🛍', 'Tienda', 'Ventas, uniformes e inventario.'],
            'formativas' => ['🏆', 'Club (divisiones formativas)', 'Partidos, convocatorias y notas del cuerpo técnico.'],
            'bitacora' => ['📜', 'Bitácora', 'Ver quién hizo cada cambio.'],
            'config' => ['⚙️', 'Parámetros y plan', 'Datos de la escuela, Mi plan, protección de datos, subir datos desde Excel e indicadores.'],
        ];
    }
    return [
        'equipos' => ['👕', 'Equipos y jugadores', 'Equipos, jugadores, carnets y videos.'],
        'inscripciones' => ['📝', 'Inscripciones', 'Aprobar jugadores, equipos y pases que envían los delegados.'],
        'campeonatos' => ['🏆', 'Campeonatos', 'Campeonatos, sorteo, fixture, programación, tablas y pedidos de los delegados.'],
        'partidos' => ['⚽', 'Partidos y vocalía', 'Resultados, vocalía, hojas para imprimir e informes.'],
        'disciplina' => ['🟥', 'Sanciones', 'Sanciones a jugadores y equipos, multas y figura de la fecha.'],
        'arbitros' => ['🧑‍⚖️', 'Árbitros', 'Árbitros, designaciones y accesos.'],
        'finanzas' => ['💵', 'Finanzas y contabilidad', 'Cobros, comprobantes, recibos, pago a árbitros, egresos, contabilidad y venta de entradas.'],
        'comunicacion' => ['📣', 'Redes y público', 'Imágenes para redes, patrocinadores, votaciones, polla y widget.'],
        'reservas' => ['🏟', 'Reservas de canchas', 'Reservas del público y su cobro.'],
        'olimpiadas' => ['🏅', 'Olimpiadas', 'Eventos multideportivos: disciplinas, delegaciones, deportistas, medallero y configuración.'], // (v37)
        'bitacora' => ['📜', 'Bitácora', 'Ver quién hizo cada cambio.'],
        'config' => ['⚙️', 'Parámetros y plan', 'Datos de la liga, canchas, reglas, Mi plan, protección de datos, subir datos desde Excel e indicadores.'],
    ];
}

/** Permisos guardados de un asistente: ['ver' => [...], 'editar' => [...]] (lo que puede cambiar también lo puede ver). */
function permisos_asistente(?array $u): array
{
    $d = json_decode((string)($u['permisos'] ?? ''), true);
    $ver = array_values(array_filter((array)($d['ver'] ?? []), 'is_string'));
    $editar = array_values(array_filter((array)($d['editar'] ?? []), 'is_string'));
    $ver = array_values(array_unique(array_diff(array_merge($ver, $editar), ['usuarios', 'super'])));
    $editar = array_values(array_diff($editar, ['usuarios', 'super']));
    return ['ver' => $ver, 'editar' => $editar];
}

/** Lee del formulario (perm[área] = no | ver | editar) los permisos del asistente, solo con las áreas que existen para esa liga o escuela. */
function leer_permisos_asistente(?array $liga, string $campo = 'perm'): string
{
    $ver = [];
    $editar = [];
    $post = (array)($_POST[$campo] ?? []);
    foreach (array_keys(areas_asistente($liga)) as $a) {
        $v = (string)($post[$a] ?? 'no');
        if ($v === 'editar') { $ver[] = $a; $editar[] = $a; }
        elseif ($v === 'ver') $ver[] = $a;
    }
    return json_encode(['ver' => $ver, 'editar' => $editar]);
}

/** Texto corto de los permisos: "Ve y cambia: Equipos, Campeonatos · Solo ve: Finanzas". */
function resumen_permisos_asistente(?array $liga, ?array $u): string
{
    $p = permisos_asistente($u);
    $nom = fn(string $a) => areas_asistente($liga)[$a][1] ?? '';
    $ed = array_filter(array_map($nom, $p['editar']));
    $solo = array_filter(array_map($nom, array_diff($p['ver'], $p['editar'])));
    $partes = [];
    if ($ed) $partes[] = 'Ve y cambia: ' . implode(', ', $ed);
    if ($solo) $partes[] = 'Solo ve: ' . implode(', ', $solo);
    return $partes ? implode(' · ', $partes) : 'Todavía sin permisos: solo ve el Resumen.';
}

/** Tabla con una fila por sección y tres opciones: No ve · Solo ve · Ve y cambia. */
function html_permisos_asistente(?array $liga, ?array $u = null, string $campo = 'perm'): string
{
    $p = permisos_asistente($u);
    $h = '<div class="tabla-scroll"><table class="tabla-permisos"><tr><th>Sección</th><th class="num">No ve</th><th class="num">Solo ve</th><th class="num">Ve y cambia</th></tr>';
    foreach (areas_asistente($liga) as $a => [$ico, $nom, $desc]) {
        $val = in_array($a, $p['editar'], true) ? 'editar' : (in_array($a, $p['ver'], true) ? 'ver' : 'no');
        $h .= '<tr><td><b>' . $ico . ' ' . e($nom) . '</b><br><small class="suave">' . e($desc) . '</small></td>';
        foreach (['no', 'ver', 'editar'] as $op) {
            $h .= '<td class="num"><input type="radio" name="' . e($campo) . '[' . $a . ']" value="' . $op . '" ' . ($val === $op ? 'checked' : '') . ' aria-label="' . e($nom) . ': ' . ['no' => 'No ve', 'ver' => 'Solo ve', 'editar' => 'Ve y cambia'][$op] . '"></td>';
        }
        $h .= '</tr>';
    }
    return $h . '</table></div><p class="ayuda">El asistente nunca puede crear ni cambiar usuarios. Puede cambiar estos permisos cuando quiera; valen desde la siguiente página que abra.</p>';
}
