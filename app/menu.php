<?php
/*
 * MENÚ DEL PANEL (una sola lista para todo)
 * La misma lista arma: el menú agrupado de arriba (computadora), la barra fija de abajo (celular)
 * y los botones grandes del Resumen. Cada opción respeta los permisos del perfil y los módulos del plan.
 */

/**
 * Grupos del menú: [['id', 'titulo', 'ico', 'items' => [['url', 'texto', 'ico', 'activos' => [...], 'badge' => int, 'desc', 'principal' => bool]]]].
 * Solo trae las opciones que el usuario puede ver.
 */
function menu_panel(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $u = usuario();
    if (!$u) return $cache = [];
    $liga = liga_trabajo();
    $it = fn(string $url, string $texto, string $ico, array $activos, string $desc = '', int $badge = 0, bool $principal = false) =>
        compact('url', 'texto', 'ico', 'activos', 'desc', 'badge', 'principal');
    $g = [];

    // (v37) Administrador de evento: solo sus olimpiadas
    if ($liga && perfil_actual() === 'evento') {
        $g['olimpiadas'] = ['titulo' => 'Olimpiadas', 'ico' => '🏅', 'items' => [$it('admin.php?p=olimpiadas', 'Olimpiadas', '🏅', ['olimpiadas', 'inicio', 'torneo', 'carrera', 'duelo', 'bolos', 'pruebas', 'partido', 'equipo', 'jugador', 'programar'], 'Mis eventos', 0, true)]];
        $g['cuenta'] = ['titulo' => 'Mi cuenta', 'ico' => '👤', 'items' => [
            $it('admin.php?p=seguridad', 'Mi cuenta', '👤', ['seguridad', 'clave'], 'Contraseña y doble verificación'),
            $it('admin.php?p=soporte', 'Ayuda y soporte', '🎫', ['soporte'], 'Tickets y pedidos de funcionalidad'),
            $it('admin.php?p=salir', 'Salir', '🚪', ['salir'])]];
        return $cache = $g;
    }
    if ($liga) {
        $g['inicio'] = ['titulo' => 'Resumen', 'ico' => '🏠', 'items' => [$it('admin.php', 'Resumen', '🏠', ['inicio', 'escuela_inicio'], 'Lo más importante de hoy', 0, true)]];
    }
    // (v28) Cuenta de una FEDERACIÓN: su panel de ligas afiliadas primero
    if ($liga && es_federacion($liga) && puede('config')) {
        $np = (int)valor("SELECT COUNT(*) FROM ligas WHERE federacion_id = ? AND federacion_estado = 'pendiente'", [$liga['id']]);
        $g['federacion'] = ['titulo' => 'Federación', 'ico' => '🏛', 'items' => [$it('admin.php?p=federacion', 'Federación', '🏛', ['federacion'], 'Ligas afiliadas, partidos, sanciones y equipos', $np, true)]];
    }
    if ($liga && es_escuela($liga)) {
        $lid = (int)$liga['id'];
        $al = [];
        $va = vocab_academia($liga); // (TuAcademia) cursos / grupos, clases / entrenamientos
        if (puede('alumnos')) {
            $al[] = $it('admin.php?p=alumnos', 'Alumnos', '🧒', ['alumnos', 'alumno', 'esc_carnets'], 'Fichas, fotos, carnets e inscripciones', (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'pendiente'", [$lid]), true);
            $al[] = $it('admin.php?p=grupos', $va['cursos'] . ' y horarios', '🗓', ['grupos'], es_academia() ? 'Disciplinas, niveles, sedes, días y horas' : 'Categorías, sedes, días y horas');
        }
        if (puede('clases')) $al[] = $it('admin.php?p=asistencia', 'Asistencia', '✅', ['asistencia'], 'Tomar lista de la clase de hoy', 0, true);
        if (puede('clases') && modulo_menu($liga, 'acad_particulares')) $al[] = $it('admin.php?p=particulares', 'Clases particulares', '🎹', ['particulares'], 'Paquetes de clases, horario 1 a 1 y saldo de clases'); // (TuAcademia)
        if (puede('clases') && modulo_menu($liga, 'esc_entrenamientos')) $al[] = $it('admin.php?p=planificacion', acad_estilo($liga) === 'deporte' ? 'Entrenamientos' : 'Planificar clases', acad_estilo($liga) === 'deporte' ? '🏃' : '📝', ['planificacion'], 'Planificar clases y ejercicios');
        if (puede('evaluaciones') && modulo_menu($liga, 'esc_evaluaciones')) $al[] = $it('admin.php?p=evaluaciones', 'Evaluaciones', '📊', ['evaluaciones'], 'Tests, medidas y técnica');
        if ((puede('alumnos') || puede('clases')) && modulo_menu($liga, 'videos')) $al[] = $it('admin.php?p=videos', 'Videos', '🎬', ['videos'], 'Videos para cada alumno (se borran a los 10 días)'); // (v28)
        if (puede('formativas') && formativas_activas($liga)) $al[] = $it('admin.php?p=formativas', 'Club', '🏆', ['formativas'], 'Divisiones formativas y partidos');
        // (v21) Multideporte: combates, partidos de tenis, cinturones y pruebas con medallas
        if (puede('evaluaciones') && (escuela_tiene_tipo($liga, 'combate') || escuela_tiene_tipo($liga, 'marcas'))) $al[] = $it('admin.php?p=competencias', 'Competencias', '🥇', ['competencias'], 'Combates, cinturones, marcas y medallas');
        // (v31) Taekwondo, karate y judo: exámenes de grado, cinturones y poomsae / kata · (TuAcademia) niveles de cualquier disciplina
        $sist_n = sistemas_nivel_escuela($liga);
        $solo_marc = $sist_n && !array_filter($sist_n, fn($x) => !es_clave_marcial($x));
        if (puede('evaluaciones') && $sist_n && (es_academia() || $solo_marc || disciplinas_de_escuela($liga)) && (!es_academia() || modulo_menu($liga, 'acad_niveles'))) $al[] = $it('admin.php?p=examenes', $solo_marc ? 'Exámenes de grado' : 'Niveles y exámenes', $solo_marc ? '🥋' : '🎖️', ['examenes'], $solo_marc ? 'Cinturones, ' . mb_strtolower(vocabulario_escuela($liga)['formas']) . ' y certificados' : 'Niveles de cada alumno, exámenes y certificados con QR');
        if (puede('alumnos') && modulo_menu($liga, 'acad_recitales')) $al[] = $it('admin.php?p=recitales', $va['eventos'], '🎭', ['recitales'], 'Recitales, presentaciones, ensayos y cobro de vestuario'); // (TuAcademia)
        if ($al) $g['alumnos'] = ['titulo' => 'Alumnos', 'ico' => '🎓', 'items' => $al];
        $di = [];
        if (puede('cobros')) $di[] = $it('admin.php?p=cobros', 'Pagos', '💵', ['cobros', 'esc_recibo'], es_academia() ? 'Pensiones, cobros, comprobantes y recibos' : 'Mensualidades, cobros y recibos', (int)valor("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente' AND comprobante_estado = 'pendiente'", [$lid]), true);
        if (puede('cobros')) $di[] = $it('admin.php?p=mensajes', 'Mensajes de hoy', '📲', ['mensajes'], 'Recordatorios de pensiones por WhatsApp', acad_mensajes_pendientes($liga), true); // (TuAcademia)
        if (puede('cobros') && es_academia() && modulo_menu($liga, 'avisos')) $di[] = $it('admin.php?p=avisos_padres', 'Avisos por Telegram', '🔔', ['avisos_padres'], 'Avisos automáticos y comunicados a los padres'); // (TuAcademia, 14-oct)
        if (puede('cobros') && modulo_menu($liga, 'contabilidad')) $di[] = $it('admin.php?p=contabilidad', 'Contabilidad', '📒', ['contabilidad'], 'Ingresos, gastos y facturas del SRI');
        if (puede('profesores') && modulo_menu($liga, 'esc_profesores')) $di[] = $it('admin.php?p=profesores', 'Profesores', '🧑‍🏫', ['profesores'], 'Entrenadores y su rol de pago');
        if (puede('tienda') && modulo_menu($liga, 'esc_tienda')) $di[] = $it('admin.php?p=tienda', 'Tienda', '🛒', ['tienda'], 'Uniformes y productos');
        if ($di) $g['dinero'] = ['titulo' => 'Dinero', 'ico' => '💵', 'items' => $di];
        $cf = [];
        if (puede('config')) $cf[] = $it('admin.php?p=escuela_config', 'Parámetros', '⚙️', ['escuela_config', 'config'], 'Datos de la escuela, sedes y precios');
    } elseif ($liga) {
        $lid = (int)$liga['id'];
        $co = [];
        if (puede('campeonatos') || puede('disciplina')) $co[] = $it('admin.php?p=torneos', 'Campeonatos', '🏆', ['torneos', 'torneo', 'carrera', 'duelo', 'bolos', 'pruebas', 'indiv', 'partido', 'sorteo', 'programar', 'acumuladas', 'planilla'], 'Fixture, resultados, tablas y sanciones', 0, true);
        if (puede('equipos')) $co[] = $it('admin.php?p=equipos', 'Equipos', '👕', ['equipos', 'equipo', 'jugador', 'importar', 'carnets'], 'Clubes, jugadores y carnets', 0, true);
        if (puede('olimpiadas') && olimp_activo($liga)) $co[] = $it('admin.php?p=olimpiadas', 'Olimpiadas', '🏅', ['olimpiadas'], 'Juegos multideportivos: delegaciones y medallero'); // (v37)
        if (puede('arbitros') && modulo_menu($liga, 'arbitros')) $co[] = $it('admin.php?p=arbitros', 'Árbitros', '🟨', ['arbitros'], 'Designaciones y pagos');
        if (puede('equipos') && modulo_menu($liga, 'videos')) $co[] = $it('admin.php?p=videos', 'Videos', '🎬', ['videos'], 'Videos para cada jugador (se borran a los 10 días)'); // (v28)
        if (puede('inscripciones') && modulo_menu($liga, 'inscripcion')) {
            $n = (int)valor("SELECT (SELECT COUNT(*) FROM solicitudes_jugador s JOIN equipos e ON e.id = s.equipo_id WHERE e.liga_id = ? AND s.estado = 'pendiente') + (SELECT COUNT(*) FROM solicitudes_equipo WHERE liga_id = ? AND estado = 'pendiente') + (SELECT COUNT(*) FROM pases WHERE liga_id = ? AND estado = 'pendiente')", [$lid, $lid, $lid]);
            $co[] = $it('admin.php?p=inscripciones', 'Inscripciones', '📝', ['inscripciones'], 'Jugadores, equipos y pases por aprobar', $n);
        }
        if ((puede('campeonatos') || puede('disciplina')) && (($n = pedidos_pendientes($lid)) > 0 || get('p') === 'pedidos')) $co[] = $it('admin.php?p=pedidos', 'Pedidos', '📨', ['pedidos'], 'Reprogramaciones, descargos y apelaciones', $n);
        if ($co) $g['competencia'] = ['titulo' => 'Competencia', 'ico' => '⚽', 'items' => $co];
        $di = [];
        if (puede('finanzas') && modulo_menu($liga, 'finanzas')) $di[] = $it('admin.php?p=finanzas', 'Finanzas', '💵', ['finanzas', 'recibo', 'liquidacion'], 'Cobros, recibos y estados de cuenta', comprobantes_pendientes($lid), true);
        if (puede('finanzas') && modulo_menu($liga, 'contabilidad')) $di[] = $it('admin.php?p=contabilidad', 'Contabilidad', '📒', ['contabilidad'], 'Ingresos, gastos y facturas del SRI');
        if (puede('finanzas') && entradas_activas($liga)) $di[] = $it('admin.php?p=entradas', 'Entradas', '🎟', ['entradas'], 'Venta de entradas con QR y lector de la puerta', (int)valor("SELECT COUNT(*) FROM bol_ordenes WHERE liga_id = ? AND estado = 'por_verificar'", [$lid])); // (v28)
        if (puede('reservas') && modulo_menu($liga, 'reservas') && (int)valor('SELECT COUNT(*) FROM canchas WHERE liga_id = ?', [$lid]) > 0) {
            $di[] = $it('admin.php?p=reservas', 'Reservas', '🏟', ['reservas'], 'Alquiler de canchas', (int)valor("SELECT COUNT(*) FROM reservas WHERE liga_id = ? AND estado = 'pendiente' AND fecha >= ?", [$lid, date('Y-m-d')]));
        }
        if ($di) $g['dinero'] = ['titulo' => 'Dinero', 'ico' => '💵', 'items' => $di];
        if (puede('comunicacion') && (modulo_menu($liga, 'redes') || modulo_menu($liga, 'publico'))) {
            $g['comunicacion'] = ['titulo' => 'Redes y público', 'ico' => '📣', 'items' => [
                $it('admin.php?p=' . (modulo_menu($liga, 'redes') ? 'redes' : 'patrocinadores'), 'Redes y público', '📣', ['redes', 'patrocinadores', 'votaciones', 'polla', 'widgets'], 'Imágenes, patrocinadores, votaciones y polla'),
            ]];
        }
        $cf = [];
        if (puede('config')) $cf[] = $it('admin.php?p=config', 'Parámetros', '⚙️', ['config'], 'Datos de la liga, canchas y reglas');
    }
    if ($liga) {
        if (!es_super() && puede('usuarios')) $cf[] = $it('admin.php?p=usuarios', 'Usuarios', '👥', ['usuarios'], 'Quién entra al panel y con qué perfil');
        if (puede('bitacora')) $cf[] = $it('admin.php?p=bitacora', 'Bitácora', '📜', ['bitacora'], 'Quién hizo cada cambio');
        if ((puede('equipos') || puede('alumnos') || puede('campeonatos')) && acad_requisitos_aplican($liga)) $cf[] = $it('admin.php?p=requisitos', 'Requisitos y documentos', '📋', ['requisitos'], 'Quién debe datos o documentos de su deporte'); // (v35)
        if (puede('config')) $cf[] = $it('admin.php?p=datos', 'Protección de datos', '🔒', ['datos'], 'Autorizaciones y solicitudes (LOPDP)', solicitudes_derechos_pendientes((int)$liga['id']));
        if (!es_super() && puede('config')) $cf[] = $it('admin.php?p=mi_plan', 'Mi plan', '⭐', ['mi_plan'], 'Plan, módulos y pagos');
        // (v27) Subida masiva y descarga de todos los datos (cuentas pagadas; la pantalla explica si no está disponible)
        if (puede('config')) $cf[] = $it('admin.php?p=cargas', 'Subir datos desde Excel', '📥', ['cargas', 'importar'], es_escuela($liga) ? 'Sedes, profesores, grupos y alumnos' : 'Equipos, jugadores, árbitros, canchas y competidores');
        if (puede('config')) $cf[] = $it('admin.php?p=mis_datos', 'Descargar mis datos', '📦', ['mis_datos'], 'Todo en un Excel');
        // (v28) Federación: la cuenta de la federación la tiene arriba; las ligas, para afiliarse
        if (puede('config') || puede('finanzas') || puede('cobros')) $cf[] = $it('admin.php?p=indicadores', 'Indicadores', '📈', ['indicadores'], 'Cómo va su ' . (es_escuela($liga) ? 'escuela' : 'liga') . ' mes a mes');
        if (!es_super() && puede('config')) $cf[] = $it('admin.php?p=referidos', 'Recomiende y gane', '🤝', ['referidos'], 'Meses de descuento por cada cuenta que recomiende');
        if (puede('config') && !es_federacion($liga) && !es_academia()) $cf[] = $it('admin.php?p=federacion', 'Federación', '🏛', ['federacion'], ($fd = federacion_de($liga)) ? 'Afiliada a ' . $fd['nombre'] : 'Afiliarse a una federación');
        if ($cf) $g['config'] = ['titulo' => 'Configuración', 'ico' => '⚙️', 'items' => $cf];
    }
    if (es_super()) {
        $s = [];
        if (!$liga) $s[] = $it('admin.php?p=bitacora&amp;todas=1', 'Bitácora', '📜', ['bitacora'], 'Todos los cambios del sistema');
        if (!$liga) $s[] = $it('admin.php?p=datos', 'Protección de datos', '🔒', ['datos'], 'Autorizaciones y solicitudes de todo el sistema', solicitudes_derechos_pendientes(null));
        $s[] = $it('admin.php?p=negocio', 'Negocio', '📈', ['negocio'], 'Ingresos y clientes', 0, true);
        $s[] = $it('admin.php?p=ligas', es_academia() ? 'Academias' : 'Ligas y escuelas', es_academia() ? '🎓' : '🏆', ['ligas'], 'Clientes, entrar a su panel', 0, true);
        $s[] = $it('admin.php?p=suscripciones', 'Ventas', '🧾', ['suscripciones', 'planes'], 'Suscripciones, planes y comprobantes', pagos_por_revisar(), true); // (v33) comprobantes por revisar
        $s[] = $it('admin.php?p=vendedores', 'Vendedores', '🤝', ['vendedores'], 'Comisiones');
        $s[] = $it('admin.php?p=modulos', es_academia() ? 'Disciplinas' : 'Módulos deportivos', '🧩', ['modulos'], es_academia() ? 'Precios y requisitos de baile, música, natación y deportes' : 'Precios, requisitos, reglas y demos por deporte', 0, true); // (v35)
        $s[] = $it('admin.php?p=demos', 'Demos', '📝', ['demos'], 'Solicitudes de demo', solicitudes_demo_nuevas(), true);
        $s[] = $it('admin.php?p=embudo', 'Embudo de demos', '📈', ['embudo'], 'Seguimiento: uso, días y WhatsApp');
        $s[] = $it('admin.php?p=soporte', 'Soporte', '🎫', ['soporte'], 'Tickets y pedidos de funcionalidad', tickets_pendientes_super(), true);
        $s[] = $it('admin.php?p=novedades', 'Novedades', '🆕', ['novedades'], 'Lo nuevo de cada versión');
        $s[] = $it('admin.php?p=portales', 'Ver portales', '👀', ['portales'], 'Ver cada panel como lo ve cada persona', 0, true);
        $s[] = $it('admin.php?p=usuarios', 'Usuarios', '👥', ['usuarios'], 'Todos los usuarios');
        $s[] = $it('admin.php?p=ajustes', 'Ajustes', '⚙️', ['ajustes'], 'Marca, precios y demostraciones');
        $s[] = $it('admin.php?p=pasarela', 'Cobros', '💳', ['pasarela'], 'Pagos con tarjeta');
        $s[] = $it('admin.php?p=google_play', 'Google Play', '📱', ['google_play'], 'Apps Android, precios y compras de Google Play'); // (v38)
        $g['super'] = ['titulo' => 'Superadministrador', 'ico' => '🛠', 'items' => $s];
    }
    $cu = [];
    if ($liga && !es_super() && profesor_del_usuario($u)) $cu[] = $it('admin.php?p=mi_rol', 'Mi rol de pago', '💰', ['mi_rol'], 'Mis clases y mis pagos', 0, true);
    $cu[] = $it('admin.php?p=seguridad', 'Mi cuenta', '👤', ['seguridad', 'clave'], 'Contraseña y doble verificación');
    // (v27) Ayuda, soporte y novedades
    if (!es_super() && $liga && in_array($u['rol'], ['liga'], true)) {
        $cu[] = $it('admin.php?p=soporte', 'Ayuda y soporte', '🎫', ['soporte'], 'Tickets y pedidos de funcionalidad', tickets_sin_leer_liga((int)$liga['id']));
        $cu[] = $it('admin.php?p=novedades', 'Novedades', '🆕', ['novedades'], 'Lo nuevo de cada versión', novedades_sin_ver());
    }
    $cu[] = $it('admin.php?p=salir', 'Salir', '🚪', ['salir']);
    $g['cuenta'] = ['titulo' => 'Mi cuenta', 'ico' => '👤', 'items' => $cu];
    return $cache = $g;
}

function menu_item_activo(array $item): bool
{
    return in_array(get('p', 'inicio'), $item['activos'], true);
}

function menu_badge(int $n): string
{
    return $n > 0 ? ' <span class="etiqueta roja">' . $n . '</span>' : '';
}

/** Menú de arriba: grupos con submenú de botones con ícono (en el celular se abre como hoja con «Más»). */
function html_menu_panel(): string
{
    $h = '';
    foreach (menu_panel() as $id => $gr) {
        $items = $gr['items'];
        $activo = (bool)array_filter($items, 'menu_item_activo');
        if (count($items) === 1) {
            $i = $items[0];
            $h .= '<a class="menu-top' . ($activo ? ' activo' : '') . '" href="' . $i['url'] . '"><span class="menu-ico">' . $i['ico'] . '</span> ' . e($i['texto']) . menu_badge($i['badge']) . '</a>';
            continue;
        }
        $badge = array_sum(array_column($items, 'badge'));
        $h .= '<div class="menu-grupo' . ($id === 'cuenta' ? ' menu-grupo-der' : '') . '"><button type="button" class="menu-top' . ($activo ? ' activo' : '') . '" aria-expanded="false">'
            . '<span class="menu-ico">' . $gr['ico'] . '</span> ' . e($gr['titulo']) . menu_badge($badge) . ' <span class="menu-flecha">▾</span></button><div class="menu-sub">';
        foreach ($items as $i) {
            $h .= '<a class="menu-boton' . (menu_item_activo($i) ? ' activo' : '') . '" href="' . $i['url'] . '"><span class="menu-boton-ico">' . $i['ico'] . '</span><span><b>' . e($i['texto']) . menu_badge($i['badge']) . '</b>'
                . ($i['desc'] !== '' ? '<small>' . e($i['desc']) . '</small>' : '') . '</span></a>';
        }
        $h .= '</div></div>';
    }
    return $h;
}

/** Barra fija de abajo en el celular: las 4 opciones principales + «Más» (abre el menú completo). */
function html_barra_abajo(): string
{
    $pri = [];
    foreach (menu_panel() as $gr) foreach ($gr['items'] as $i) if ($i['principal']) $pri[] = $i;
    $pri = array_slice($pri, 0, 4);
    if (!$pri) return '';
    $h = '<nav class="barra-abajo no-imprimir" aria-label="Accesos rápidos">';
    foreach ($pri as $i) {
        $h .= '<a href="' . $i['url'] . '"' . (menu_item_activo($i) ? ' class="activo"' : '') . '><span>' . $i['ico'] . ($i['badge'] ? '<i>' . $i['badge'] . '</i>' : '') . '</span>' . e($i['texto']) . '</a>';
    }
    return $h . '<button type="button" onclick="document.body.classList.toggle(\'menu-abierto\');window.scrollTo(0,0)"><span>☰</span>Más</button></nav>';
}

/** Botones grandes del Resumen, agrupados como el menú (sin «Resumen» ni «Mi cuenta»). */
function html_botones_inicio(): string
{
    $h = '<div class="botones-inicio no-imprimir">';
    foreach (menu_panel() as $id => $gr) {
        if (in_array($id, ['inicio', 'cuenta'], true)) continue;
        $h .= '<section><h2 class="botones-titulo">' . $gr['ico'] . ' ' . e($gr['titulo']) . '</h2><div class="botones-rejilla">';
        foreach ($gr['items'] as $i) {
            $h .= '<a class="boton-grande" href="' . $i['url'] . '"><span class="boton-grande-ico">' . $i['ico'] . '</span><b>' . e($i['texto']) . '</b>'
                . ($i['desc'] !== '' ? '<small>' . e($i['desc']) . '</small>' : '') . ($i['badge'] ? '<span class="boton-grande-badge">' . $i['badge'] . '</span>' : '') . '</a>';
        }
        $h .= '</div></section>';
    }
    return $h . '</div>';
}

/** Script del menú: abre y cierra los submenús con clic (y los cierra al tocar afuera o con Esc). */
function script_menu_panel(): string
{
    return <<<'JS'
<script>
(function () {
  function cerrar(excepto) {
    document.querySelectorAll('.menu-grupo.abierto').forEach(function (g) { if (g !== excepto) { g.classList.remove('abierto'); g.querySelector('button').setAttribute('aria-expanded', 'false'); } });
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('.menu-grupo > button');
    if (b) { var g = b.parentNode; cerrar(g); var ab = g.classList.toggle('abierto'); b.setAttribute('aria-expanded', ab ? 'true' : 'false'); return; }
    if (!ev.target.closest('.menu-sub')) cerrar(null);
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { cerrar(null); document.body.classList.remove('menu-abierto'); } });
})();
</script>
JS;
}

/** Ícono de cada pestaña de la página pública de la liga. */
function ico_pestana_publica(string $k): string
{
    return [
        'inicio' => '🏠', 'envivo' => '🔴', 'posiciones' => '📋', 'fixture' => '📅', 'goleadores' => '⚽', 'tarjetas' => '🟨', 'equipos' => '👕',
        'votar' => '🗳', 'polla' => '🎯', 'sorteo' => '🎲', 'inscribir' => '📝', 'historia' => '🏆', 'llaves' => '🔀', 'estadisticas' => '📈',
        'acumulada' => '🧮', 'general' => '🏁', 'fechas' => '📅', 'participantes' => '👥', 'documentos' => '📄', 'buscar' => '🔎', 'reservar' => '🏟',
        'programacion' => '📅', 'medallero' => '🏅', 'jornadas' => '📅', 'clubes' => '🏢', // (v25) duelos y bolos
        'inscribirse' => '📝', 'resultados' => '⏱', 'programa' => '📋', 'puntos' => '🏢', 'mejores' => '⭐', // (v26)
        'entradas' => '🎟', 'federacion' => '🏛', // (v28)
    ][$k] ?? '•';
}

/** (v19) Pestañas de la página pública como botones con ícono grande (estilo app), fijas arriba al bajar. */
function html_pestanas_publicas(array $pestanas, string $activa, string $base): string
{
    $h = '<nav class="pestanas pestanas-ico">';
    foreach ($pestanas as $k => $txt) {
        $largo = preg_replace('/^[^\p{L}\p{N}]+/u', '', (string)$txt); // el ícono va aparte
        $corto = ['fixture' => 'Fixture', 'tarjetas' => str_starts_with($largo, 'Faltas') ? 'Faltas' : 'Tarjetas', 'inscribir' => 'Inscribirse', 'reservar' => 'Reservar cancha'][$k] ?? $largo;
        $h .= '<a class="' . ($activa === $k ? 'activa' : '') . ($k === 'envivo' ? ' vivo' : '') . '" href="' . e($base . '&v=' . $k) . '" title="' . e($largo) . '"><span class="pest-ico">' . ico_pestana_publica($k) . '</span>' . e($corto) . '</a>';
    }
    return $h . '</nav>';
}
