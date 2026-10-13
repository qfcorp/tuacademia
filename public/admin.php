<?php
/*
 * Panel de administración (todas las páginas pasan por aquí).
 * Dirección: /admin.php
 */
require __DIR__ . '/../app/bootstrap.php';

$p = get('p', 'inicio');
$validas = ['login', 'salir', 'inicio', 'ligas', 'usuarios', 'clave', 'config', 'equipos', 'equipo', 'jugador', 'torneos', 'torneo', 'partido', 'planilla',
    'arbitros', 'imprimir', 'carnets', 'finanzas', 'recibo', 'redes', 'bitacora', 'inscripciones',
    'seguridad', 'programar', 'planes', 'suscripciones', 'ajustes',
    'importar', 'patrocinadores', 'votaciones', 'polla',
    'liquidacion', 'widgets', 'reservas', 'vendedores',
    'negocio', 'pedidos', 'acumuladas',
    'mi_plan', 'pagos_linea', 'pasarela', 'mi_rol',
    'alumnos', 'alumno', 'grupos', 'asistencia', 'evaluaciones', 'cobros', 'esc_recibo', 'profesores', 'tienda', 'escuela_config', 'esc_carnets',
    'planificacion', 'formativas', 'contabilidad', 'competencias', 'carrera', 'duelo', 'bolos', 'pruebas', 'indiv',
    'demos', 'portales', 'datos',
    'soporte', 'novedades', 'embudo', 'cargas', 'mis_datos', // (v27)
    'federacion', 'videos', 'indicadores', 'referidos', 'entradas', // (v28)
    'examenes', // (v31)
    'modulos', 'requisito_doc', 'requisitos', // (v35)
    'olimpiadas', // (v37)
    'google_play', // (v38)
    'mensajes', 'recitales', 'particulares']; // (TuAcademia)
if (!in_array($p, $validas, true)) {
    $p = 'inicio';
}

csrf_verificar();
// (TuAcademia) las páginas de ligas no se abren (el código sigue ahí para PRODUCTO = 'ligas')
if (PRODUCTO === 'academia' && in_array($p, paginas_solo_ligas(), true)) $p = $p === 'config' && liga_trabajo() && es_escuela(liga_trabajo()) ? 'escuela_config' : 'inicio';

if ($p === 'login') {
    require APP_DIR . '/admin/login.php';
    exit;
}
if ($p === 'salir') {
    if (usuario()) bitacora_evento('sesion', 'Cerró sesión');
    $_SESSION = [];
    session_destroy();
    ir('admin.php?p=login');
}

$u = requiere_login();
recordar_url_sitio(); // (v33)
if ($u['rol'] === 'arbitro') {
    ir('arbitro.php');
}
if ($u['rol'] === 'delegado') {
    ir('delegado.php');
}
if ($u['rol'] === 'vendedor') {
    ir('vendedor.php');
}
// (v16) Combo liga + escuela: el administrador pasa de una cuenta a la otra con su mismo usuario
if (get('combo') !== '' && $u['rol'] === 'liga') {
    $propia = (int)($u['liga_propia'] ?? $u['liga_id']);
    $destino = (int)get('combo');
    if ($destino === $propia) {
        unset($_SESSION['liga_combo']);
    } elseif (($pl = fila('SELECT * FROM ligas WHERE id = ?', [$propia])) && ($ot = combo_de($pl)) && (int)$ot['id'] === $destino && ($u['perfil'] ?? 'admin') === 'admin') {
        $_SESSION['liga_combo'] = $destino;
        bitacora_evento('sesion', 'Pasó a la cuenta en combo ' . $ot['nombre'], $destino, (int)$u['id']);
    } else {
        flash('No tiene acceso a esa cuenta.', 'error');
    }
    ir('admin.php');
}
// (v10) Liga de demostración: se puede mirar todo, pero no se guarda nada
// (v15) Si se permite guardar en la demo (se borra cada madrugada), solo se bloquea la cuenta y los usuarios
if (es_post() && !es_super() && es_usuario_demo($u) && (!demo_guardar() || in_array($p, ['seguridad', 'clave', 'usuarios', 'mi_plan', 'pasarela', 'pagos_linea', 'soporte', 'cargas', 'mis_datos'], true))) {
    flash('Esta es la demostración: puede mirar todo, pero los cambios no se guardan. Solicite su demo para probarlo con sus datos.', 'error');
    ir($_SERVER['HTTP_REFERER'] ?? 'admin.php');
}
// Doble verificación obligatoria para administradores (si el superadministrador la exige)
if (empty($u['vista_super']) && doble_verificacion_obligatoria($u) && (int)$u['totp_activo'] !== 1 && $p !== 'seguridad') {
    flash('Por seguridad, antes de continuar active la doble verificación (código en su celular).', 'error');
    ir('admin.php?p=seguridad');
}
// (v37) El administrador de un evento entra directo a sus olimpiadas
if ($p === 'inicio' && perfil_actual() === 'evento') $p = 'olimpiadas';
// (v7) Perfil del usuario de la liga: solo entra a sus menús; "Solo consulta" no puede guardar nada
if (!puede_pagina($p)) {
    flash('Su perfil (' . nombre_perfil(perfil_actual()) . ') no tiene acceso a esa sección.', 'error');
    ir('admin.php');
}
// (v12) Módulos del plan: si el plan de la liga o escuela no incluye esa sección, se ofrece cambiar de plan
if (!es_super() && ($mod_p = modulo_de_pagina($p)) !== '' && liga_trabajo() && !modulo_activo(liga_trabajo(), $mod_p)) {
    flash('Su plan no incluye «' . nombre_modulo($mod_p) . '». Puede cambiar de plan aquí.', 'error');
    ir('admin.php?p=mi_plan');
}
if (es_post() && !in_array($p, ['seguridad', 'clave'], true) && !puede_pagina($p, true, post('accion'))) {
    flash('Su perfil (' . nombre_perfil(perfil_actual()) . ') puede ver esta sección, pero no hacer cambios aquí.', 'error');
    ir($_SERVER['HTTP_REFERER'] ?? 'admin.php');
}
// Suscripción vencida: el panel de la liga queda en solo lectura
if (es_post() && !in_array($p, ['seguridad', 'mi_plan', 'soporte', 'novedades'], true) && !liga_puede_editar(liga_trabajo())) { // (v12) con la suscripción vencida igual puede pagar en Mi plan
    flash(estado_suscripcion(liga_trabajo())['texto'] . ' Comuníquese con ' . ajuste('empresa') . ' para renovar.', 'error');
    ir($_SERVER['HTTP_REFERER'] ?? 'admin.php');
}
// (v35) Campeonato de un módulo deportivo que la cuenta no tiene (se lo quitaron o no lo renovó): se puede ver, no cambiar
if (liga_trabajo() && in_array($p, ['torneo', 'carrera', 'duelo', 'bolos', 'pruebas', 'indiv'], true) && (int)get('id') > 0) {
    $mod_t = (string)valor('SELECT modalidad FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('id'), (int)liga_trabajo()['id']]);
    if ($mod_t !== '' && !modalidad_permitida(liga_trabajo(), $mod_t)) {
        if (es_post() && !es_super()) {
            flash('Este campeonato es del módulo ' . nombre_modulo_deporte(modulo_de_modalidad($mod_t)) . ', que su cuenta no tiene: puede verlo pero no cambiarlo. Agregue el módulo en ⭐ Mi plan.', 'error');
            ir($_SERVER['HTTP_REFERER'] ?? 'admin.php?p=torneos');
        }
        if (!es_post()) flash('🔒 Este campeonato es del módulo ' . nombre_modulo_deporte(modulo_de_modalidad($mod_t)) . ', que su cuenta no tiene. Puede verlo, pero para cambiarlo agregue el módulo en ⭐ Mi plan.', 'error');
    }
}
require APP_DIR . '/admin/comun.php';
// (v11) En una escuela, el "Resumen" es el de la escuela
if ($p === 'inicio' && es_escuela(liga_trabajo())) $p = 'escuela_inicio';

try {
    require APP_DIR . '/admin/' . $p . '.php';
} catch (RuntimeException $ex) {
    flash($ex->getMessage(), 'error');
    ir($_SERVER['HTTP_REFERER'] ?? 'admin.php');
}
