#!/usr/bin/env python3
"""
(13 nov 2026) App de iPhone (App Store, regla de Apple 3.1.1): app/app_ios.php.
(25 nov 2026) Secciones 7 a 10: superadministrador (pantallas del negocio de Tukán, crear/editar cuentas), revisión estricta de todas
las pantallas del dueño y del súper («$», USD, precio, plan, contratar, mejorar, URLs de pago), novedades, login, manual y PDF.
Con el User-Agent «TukanIOS» (o la cookie canal=ios que deja /app.php?origen=ios) NO se ve ningún precio del software, ni botones,
enlaces o formularios para pagar, contratar, cotizar, mejorar o cancelar, ni el WhatsApp de ventas ni dónde pagar.
Lo que la academia cobra a SUS alumnos (pensiones, inscripciones…) sí se sigue viendo. Sin TukanIOS todo sigue igual.

Antes:  bash pruebas/v38/reiniciar.sh                (modo academia)
Correr: python3 pruebas/app_iphone/prueba_app_iphone.py
"""
import json, os, re, subprocess, sys
import requests

PUERTO = int(os.environ.get('TA_PUERTO', '8196'))
B = 'http://127.0.0.1:%d/' % PUERTO
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
UA_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 TukanIOS/1.0.0'
UA_WEB = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
WA = '0990001111'
PRECIO = re.compile(r'\$\s?\d|\d+[.,]\d{2}\s?(USD|dólares)')
# Lo que nunca puede salir en el iPhone (en ninguna pantalla del panel)
PROHIBIDO = ['Contratar', 'Renovar ahora', 'Renovar o cambiar de plan', 'Ver planes y pagar', 'Pedir cotización', 'Solicitar este módulo',
             'WhatsApp de ventas', 'Datos para pagar:', 'Solicite su demo', 'Pida su demo', 'subir el comprobante', 'Pagar con tarjeta',
             'pagos.tukanec.com', 'Cancelar mi plan', 'Google Play', 'Agregue más deportes', 'cambiar de plan', 'agréguelo']
ok, fallos = 0, []


def chk(cond, nombre):
    global ok
    if cond:
        ok += 1
        print('  OK   ', nombre)
    else:
        fallos.append(nombre)
        print('  FALLA', nombre)


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout[-800:], r.stderr[-800:])
    return r.stdout.strip()


def val(sql, *p):
    return php('echo valor(%s, %s);' % (json.dumps(sql), json.dumps(list(p))))


def csrf(html):
    m = re.search(r'name="csrf" value="([^"]+)"', html)
    return m.group(1) if m else ''


def visible(html):
    """Texto que ve la persona (sin scripts ni estilos) + los href (para enlaces escondidos)."""
    sin = re.sub(r'<(script|style)\b.*?</\1>', ' ', html, flags=re.S)
    hrefs = ' '.join(re.findall(r'href="([^"]*)"', sin))
    return re.sub(r'<[^>]+>', ' ', sin) + ' ' + hrefs


def sesion(ua):
    s = requests.Session()
    s.headers['User-Agent'] = ua
    return s


def entrar(email, ua):
    s = sesion(ua)
    r = s.get(B + 'admin.php?p=login')
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': 'clave12345'})
    return s


def limpio(r):
    return r.status_code < 500 and not ERRORES.search(r.text)


def prohibidos(html):
    v = visible(html)
    return [x for x in PROHIBIDO if x in v] + (['wa.me ventas'] if '990001111' in html else []) + (['«Paga»'] if re.search(r'\bPaga\b', v) else [])


def enviar(s, accion, **datos):
    r = s.get(B + 'admin.php?p=alumnos')
    datos['csrf'] = csrf(r.text) or csrf(s.get(B + 'admin.php').text)
    datos['accion'] = accion
    return s.post(B + 'admin.php?p=mi_plan', data=datos)


assert 'SOLO PRUEBAS' in open(os.path.join(REPO, 'app/config.php')).read(), 'primero: bash pruebas/v38/reiniciar.sh'
assert not os.path.exists(os.path.join(REPO, 'data/.servidor'))

LIGA = val("SELECT liga_id FROM usuarios WHERE email = 'liga@prueba.com'")
chk(LIGA != '', 'hay una cuenta de prueba')
php('guardar_ajuste("ventas_whatsapp", "%s"); guardar_ajuste("ventas_datos_pago", "Banco Ejemplo, cuenta 0000000000");' % WA)
php('q("UPDATE ligas SET vence = ?, es_prueba = 0 WHERE id = %s", [date("Y-m-d", strtotime("+40 days"))]);' % LIGA)

print('\n== 1. Cómo se reconoce la app de iPhone')
chk(php('echo en_app_ios() ? 1 : 0;') == '0', 'en la consola (CLI) no es iPhone')
chk(php('$GLOBALS["__forzar_app_ios"] = 1; $GLOBALS["__forzar_app_play"] = 1; echo en_app_play() ? 1 : 0, en_app_tienda() ? 1 : 0;') == '01',
    'en el iPhone nunca se cobra con Google Play (en_app_play = no) y sí es app de tienda')
r = sesion(UA_IOS).get(B + 'app.php')
chk(limpio(r) and re.search(r'<html[^>]*class="[^"]*\btukan-ios\b', r.text), 'User-Agent con TukanIOS: <html class="tukan-ios">')
r = sesion(UA_WEB).get(B + 'app.php')
chk(limpio(r) and 'tukan-ios' not in r.text, 'Safari normal (sin TukanIOS): sin la clase tukan-ios')
s = sesion(UA_WEB)
r = s.get(B + 'app.php?origen=ios')
chk(s.cookies.get('canal') == 'ios' and 'tukan-ios' in r.text, '/app.php?origen=ios deja la cookie canal=ios (y la clase)')
r = s.get(B + 'app.php')
chk('tukan-ios' in r.text and '¿Cómo instalarla?' not in r.text, 'con la cookie sigue en modo iPhone (sin «Instalar en el celular»)')
s.get(B + 'app.php?origen=web')
r = s.get(B + 'app.php')
chk(s.cookies.get('canal') is None and 'tukan-ios' not in r.text, '?origen=web la quita')
r = sesion(UA_IOS).get(B + 'manifest.php')
chk(r.status_code == 200 and 'tukan-ios' not in r.text, 'lo que no es una página (manifest JSON) no se toca')

print('\n== 2. Mi plan en el iPhone: solo información neutra')
web = entrar('liga@prueba.com', UA_WEB)
ios = entrar('liga@prueba.com', UA_IOS)
r = web.get(B + 'admin.php?p=mi_plan')
chk(limpio(r) and 'Renovar o cambiar de plan' in r.text and 'mi-plan-ios' not in r.text, 'sin TukanIOS: Mi plan sigue igual (renovar o cambiar de plan)')
chk(PRECIO.search(visible(r.text)) is not None, 'sin TukanIOS: Mi plan muestra precios')
r = ios.get(B + 'admin.php?p=mi_plan')
chk(limpio(r) and 'id="mi-plan-ios"' in r.text, 'iPhone: Mi plan neutro')
chk(re.search(r'Tu plan: [^<]+\. Activo hasta el \d{4}-\d{2}-\d{2}\.', r.text) is not None, 'iPhone: «Tu plan: … Activo hasta …»')
chk(not PRECIO.search(visible(r.text)), 'iPhone: Mi plan sin ningún precio')
chk(not prohibidos(r.text), 'iPhone: Mi plan sin contratar, renovar, cotizar, pagar, cancelar ni WhatsApp de ventas %s' % prohibidos(r.text))
chk('<form' not in r.text.split('id="mi-plan-ios"')[1] and 'Mis pagos' not in r.text and 'Plux' not in r.text and 'transferencia' not in r.text.lower(),
    'iPhone: Mi plan sin formularios, historial de pagos, Plux ni transferencia')
chk('Módulos encendidos' in r.text, 'iPhone: sí muestra los módulos encendidos')

print('\n== 3. Las acciones de pago, cotización, cambio y cancelación se rechazan en el iPhone')
tk, pm, sp = val('SELECT COUNT(*) FROM soporte_tickets'), val('SELECT COUNT(*) FROM modulos_pedidos'), val('SELECT COUNT(*) FROM solicitudes_pago') if val("SELECT COUNT(*) FROM sqlite_master WHERE name = 'solicitudes_pago'") == '1' else ''
for accion, datos in (('pedir_cotizacion', {'que': 'plan'}), ('pedir_modulo', {'modulo': 'videos'}), ('comprobante', {'plan_meses': '1:1', 'forma_pago': 'transferencia'}),
                      ('pagar', {'plan_id': '1', 'meses': '1'}), ('cancelar_plan', {'confirmo': '1', 'motivo': 'x'}), ('seguir_plan', {}), ('cancelar_tarjeta', {'id': '1'})):
    r = enviar(ios, accion, **datos)
    chk(limpio(r) and 'Esta acción no está disponible en la app.' in r.text, 'iPhone: «%s» responde «Esta acción no está disponible en la app.»' % accion)
chk(val('SELECT COUNT(*) FROM soporte_tickets') == tk and val('SELECT COUNT(*) FROM modulos_pedidos') == pm, 'iPhone: no se creó ningún ticket de cotización ni pedido de módulo')
chk(php('$GLOBALS["__forzar_app_ios"] = 1; echo html_boton_cotizacion("plan") === "" ? "vacio" : "lleno";') == 'vacio', 'iPhone: el botón «Pedir cotización» no sale')
chk(php('$GLOBALS["__forzar_app_ios"] = 1; try { pedir_cotizacion(fila("SELECT * FROM ligas WHERE id = %s"), "plan"); echo "hizo"; } catch (RuntimeException $e) { echo $e->getMessage(); }' % LIGA) == 'Esta acción no está disponible en la app.',
    'iPhone: pedir_cotizacion() se niega aunque se llame directo')
r = enviar(web, 'pedir_cotizacion', que='plan')
chk(val('SELECT COUNT(*) FROM soporte_tickets') != tk, 'sin TukanIOS: pedir cotización sigue funcionando')

print('\n== 4. Avisos del panel: vencimiento, prueba terminada, vencida, suspendida, pausada')
php('q("UPDATE ligas SET vence = ?, es_prueba = 0 WHERE id = %s", [date("Y-m-d", strtotime("+3 days"))]);' % LIGA)
r = web.get(B + 'admin.php')
chk('aviso-vence' in r.text and 'Datos para pagar' in r.text and '990001111' in r.text, 'sin TukanIOS: el aviso de vencimiento trae datos para pagar y WhatsApp')
r = ios.get(B + 'admin.php')
i = r.text.find('aviso-vence')
bloque = r.text[i:r.text.find('</div>', i)] if i >= 0 else ''
chk(limpio(r) and bloque != '' and 'vence en 3 días' in bloque, 'iPhone: el aviso dice cuándo vence')
chk(not prohibidos(r.text) and 'renueve' not in bloque and not PRECIO.search(bloque), 'iPhone: sin valor, «renueve», datos para pagar, botones ni WhatsApp %s' % prohibidos(r.text))
php('q("UPDATE ligas SET vence = ?, es_prueba = 1 WHERE id = %s", [date("Y-m-d", strtotime("-2 days"))]);' % LIGA)
r = web.get(B + 'admin.php')
chk('Contratar mi plan' in r.text, 'sin TukanIOS: demo terminada con «Contratar mi plan»')
r = ios.get(B + 'admin.php')
chk(limpio(r) and 'Tu periodo de prueba terminó.' in r.text and not prohibidos(r.text), 'iPhone: «Tu periodo de prueba terminó.» sin contratar %s' % prohibidos(r.text))
r = ios.get(B + 'admin.php?p=mi_plan')
chk('Tu periodo de prueba terminó.' in r.text and not PRECIO.search(visible(r.text)) and not prohibidos(r.text), 'iPhone: Mi plan con la prueba terminada, neutro')
php('q("UPDATE ligas SET vence = ?, es_prueba = 0 WHERE id = %s", [date("Y-m-d", strtotime("-40 days"))]);' % LIGA)
r = ios.get(B + 'admin.php')
chk(limpio(r) and re.search(r'venció el \d{4}-\d{2}-\d{2}\.', r.text) and not prohibidos(r.text) and 'hasta renovar' not in r.text, 'iPhone: plan vencido con texto neutro (sin «hasta renovar»)')
php('q("UPDATE ligas SET vence = ?, es_prueba = 0, activa = 0, suspendida_tipo = \'\', suspendida_motivo = \'x\' WHERE id = %s", [date("Y-m-d", strtotime("+40 days"))]);' % LIGA)
r = web.get(B + 'admin.php')
chk(WA in r.text, 'sin TukanIOS: cuenta suspendida con el WhatsApp de ventas')
r = ios.get(B + 'admin.php')
chk(limpio(r) and 'cuenta-suspendida' in r.text and WA not in r.text, 'iPhone: cuenta suspendida sin el WhatsApp de ventas')
php('q("UPDATE ligas SET activa = 0, suspendida_tipo = \'falta_pago\', suspendida_motivo = \'Falta el pago\' WHERE id = %s");' % LIGA)
r = ios.get(B + 'admin.php')
chk('cuenta-pausada' in r.text and 'wa.me' not in r.text, 'iPhone: cuenta pausada sin el botón de WhatsApp')
php('q("UPDATE ligas SET activa = 1, suspendida_tipo = \'\', suspendida_motivo = \'\', es_prueba = 1 WHERE id = %s");' % LIGA)
r = ios.get(B + 'admin.php?p=cargas')
chk(limpio(r) and not prohibidos(r.text), 'iPhone: «solo cuentas pagadas» sin pagar ni WhatsApp de ventas %s' % prohibidos(r.text))
r = web.get(B + 'admin.php?p=cargas')
chk('Ver planes y pagar' in r.text, 'sin TukanIOS: «solo cuentas pagadas» con «Ver planes y pagar»')
php('q("UPDATE ligas SET es_prueba = 0 WHERE id = %s");' % LIGA)

print('\n== 5. Todas las pantallas del panel en el iPhone')
r = ios.get(B + 'admin.php')
paginas = sorted(set(re.findall(r'href="admin\.php\?p=([a-z_]+)"', r.text)) - {'salir'})
chk(len(paginas) >= 8, 'el menú del panel tiene pantallas (%d)' % len(paginas))
malas = {}
for p in paginas:
    r = ios.get(B + 'admin.php?p=' + p)
    if not limpio(r):
        malas[p] = 'error PHP'
    elif prohibidos(r.text):
        malas[p] = prohibidos(r.text)
chk(not malas, 'iPhone: ninguna pantalla del panel ofrece contratar, renovar, cotizar, pagar el software ni el WhatsApp de ventas %s' % malas)
esc = entrar('escuela@prueba.com', UA_IOS)
r = esc.get(B + 'admin.php')
malas = {}
for p in sorted(set(re.findall(r'href="admin\.php\?p=([a-z_]+)"', r.text)) - {'salir'}):
    r = esc.get(B + 'admin.php?p=' + p)
    if not limpio(r) or prohibidos(r.text):
        malas[p] = prohibidos(r.text) or 'error PHP'
chk(not malas, 'iPhone (segunda cuenta): ninguna pantalla con precios o pagos del software %s' % malas)

print('\n== 6. Páginas de venta y pagos')
s = sesion(UA_IOS)
for ruta, nombre in (('index.php', 'la portada de venta'), ('index.php?v=solicitar', 'pedir demo'), ('index.php?v=comparar', 'comparar planes'), ('index.php?v=plan&plan=1', 'el folleto de un plan')):
    r = s.get(B + ruta)
    chk(r.url.endswith('app.php') and r.history and r.history[0].status_code == 302, 'iPhone: ' + nombre + ' lleva al inicio de la app')
r = sesion(UA_WEB).get(B + 'index.php?v=solicitar')
chk(r.status_code == 200 and not r.history, 'sin TukanIOS: pedir demo sigue igual')
r = s.get(B + 'index.php?v=privacidad')
chk(r.status_code == 200 and not r.history, 'iPhone: la privacidad se ve')
r = s.get(B + 'index.php?v=manual')
chk(limpio(r) and 'Pida su demo' not in r.text, 'iPhone: el manual sin «Pida su demo»')
r = s.get(B + 'index.php?v=manual&m=escuelas')
chk(limpio(r) and re.search(r'[Aa]sistencia', r.text) and not re.search(r'Mi plan\b|Cancelar mi plan|cotizaci|Google Play', visible(r.text)), 'iPhone: el manual sin el capítulo de Mi plan, cancelar ni cotizar (lo demás sí)')
r = sesion(UA_WEB).get(B + 'index.php?v=manual&m=escuelas')
chk(limpio(r) and 'Mi plan' in r.text, 'sin TukanIOS: el manual sigue con el capítulo de Mi plan')
tok_s = php('echo crear_pago_linea(fila("SELECT * FROM ligas WHERE id = %s"), ["destino" => "tuvocalia", "concepto" => "suscripcion", "detalle" => "Plan de prueba", "meses" => 1, "monto_base" => 10, "pagador_nombre" => "Admin", "pagador_email" => "liga@prueba.com"]);' % LIGA)
php('q("UPDATE ligas SET modulos_extra = \'pagos_tarjeta\' WHERE id = %s");' % LIGA)  # cobros con tarjeta a sus alumnos
tok_e = php('$l = fila("SELECT * FROM ligas WHERE id = %s"); echo crear_pago_linea($l, ["destino" => "organizacion", "concepto" => "pension", "detalle" => "Pensión de noviembre", "monto_base" => 25, "pagador_nombre" => "Familia", "pagador_email" => "f@example.com"]);' % LIGA)
r = s.get(B + 'pagar.php?t=' + tok_s)
chk(limpio(r) and 'Este pago no está disponible en la app.' in r.text and not PRECIO.search(visible(r.text)), 'iPhone: el pago con tarjeta de la suscripción no se abre')
r = requests.post(B + 'plux_pagar.php?t=' + tok_s, json={'csrf': 'x'}, headers={'User-Agent': UA_IOS})
chk(r.headers.get('Content-Type', '').startswith('application/json'), 'plux_pagar.php sigue respondiendo JSON en el iPhone')
r = sesion(UA_WEB).get(B + 'pagar.php?t=' + tok_s)
chk(r.status_code == 200 and 'no está disponible en la app' not in r.text, 'sin TukanIOS: el pago de la suscripción sigue igual')
r = s.get(B + 'pagar.php?t=' + tok_e)
chk(limpio(r) and 'no está disponible en la app' not in r.text and 'Pensión de noviembre' in r.text and PRECIO.search(visible(r.text)),
    'iPhone: el cobro de la academia a SUS alumnos (pensión) sí se ve, con su valor')
r = requests.post(B + 'play.php', data={'csrf': 'x', 'accion': 'verificar'}, headers={'User-Agent': UA_IOS})
chk(r.status_code == 403 and 'no está disponible en la app' in r.text, 'iPhone: play.php (Google Play) se niega')

print('\n== 7. Superadministrador en el iPhone (23 nov 2026): sin las pantallas del negocio de Tukán')
NEGOCIO = ['negocio', 'suscripciones', 'planes', 'vendedores', 'modulos', 'embudo', 'ajustes', 'pasarela', 'referidos', 'google_play']
sup_ios = entrar('super@prueba.com', UA_IOS)
sup_web = entrar('super@prueba.com', UA_WEB)
r = sup_ios.get(B + 'admin.php')
menu_ios = set(re.findall(r'href="admin\.php\?p=([a-z_]+)', r.text))
chk(limpio(r) and not (menu_ios & set(NEGOCIO)), 'iPhone: el menú del súper no tiene Negocio, Ventas, Vendedores, Disciplinas (precios), Embudo, Ajustes, Cobros ni Google Play %s' % sorted(menu_ios & set(NEGOCIO)))
chk('ligas' in menu_ios, 'iPhone: el súper sí ve «Academias»')
r = sup_web.get(B + 'admin.php')
menu_web = set(re.findall(r'href="admin\.php\?p=([a-z_]+)', r.text))
chk(set(NEGOCIO) - {'planes', 'referidos'} <= menu_web, 'sin TukanIOS: el súper sigue viendo todas las pantallas del negocio %s' % sorted(set(NEGOCIO) - {'planes', 'referidos'} - menu_web))
for p_ in NEGOCIO:
    r = sup_ios.get(B + 'admin.php?p=' + p_)
    chk(limpio(r) and not re.search(r'p=' + p_ + r'\b', r.url) and 'no está disponible en la app' in r.text and not PRECIO.search(visible(r.text)),
        'iPhone: admin.php?p=%s no se abre (aviso neutro, sin precios)' % p_)
r = sup_web.get(B + 'admin.php?p=modulos')
chk(limpio(r) and PRECIO.search(visible(r.text)) and 'no está disponible en la app' not in r.text, 'sin TukanIOS: «Disciplinas» sigue con sus precios')
malas = {}
for p_ in sorted(menu_ios - {'salir'}):
    r = sup_ios.get(B + 'admin.php?p=' + p_)
    if not limpio(r):
        malas[p_] = 'error PHP'
    elif prohibidos(r.text):
        malas[p_] = prohibidos(r.text)
chk(not malas, 'iPhone: ninguna pantalla del menú del súper ofrece contratar ni pagar el software %s' % malas)

print('\n== 8. Súper en el iPhone: crear y editar cuentas (planes, precios de módulos, combos) solo en la web')
ESC = val("SELECT liga_id FROM usuarios WHERE email = 'escuela@prueba.com'")
r = sup_ios.get(B + 'admin.php?p=ligas')
chk(limpio(r) and 'p=ligas&amp;editar=' not in r.text and 'nueva=1' not in r.text and 'p=ligas&amp;entrar=' in r.text,
    'iPhone: la lista de academias sin «Editar» ni «+ Nueva», con «Entrar al panel»')
for ruta in ('admin.php?p=ligas&editar=' + ESC, 'admin.php?p=ligas&nueva=1', 'admin.php?p=ligas&nueva=1&tipo=escuela'):
    r = sup_ios.get(B + ruta)
    chk(limpio(r) and 'editar=' not in r.url and 'nueva=' not in r.url and 'no está disponible en la app' in r.text and not PRECIO.search(visible(r.text)),
        'iPhone: %s no se abre (sin planes ni precios)' % ruta.split('?')[1])
n_ligas = val('SELECT COUNT(*) FROM ligas')
sup_ios.get(B + 'admin.php?p=seguridad')
r = sup_ios.get(B + 'admin.php?p=seguridad')
r = sup_ios.post(B + 'admin.php?p=ligas&nueva=1', data={'csrf': csrf(r.text), 'accion': 'crear', 'nombre': 'Academia iPhone', 'admin_email': 'ip@example.com'})
chk(limpio(r) and 'no está disponible en la app' in r.text and val('SELECT COUNT(*) FROM ligas') == n_ligas, 'iPhone: crear una cuenta por POST se rechaza')
r = sup_web.get(B + 'admin.php?p=ligas')
chk('p=ligas&amp;editar=' in r.text and 'nueva=1' in r.text, 'sin TukanIOS: la lista sigue con «Editar» y «+ Nueva»')
r = sup_web.get(B + 'admin.php?p=ligas&editar=' + ESC)
chk(limpio(r) and PRECIO.search(visible(r.text)) and 'no está disponible en la app' not in r.text, 'sin TukanIOS: editar la cuenta sigue con sus planes y precios')
r = sup_web.get(B + 'admin.php?p=ligas&nueva=1')
chk(limpio(r) and PRECIO.search(visible(r.text)), 'sin TukanIOS: crear cuenta sigue con los precios de las disciplinas')
r = sup_ios.get(B + 'admin.php?p=mi_plan')
chk(limpio(r) and not PRECIO.search(visible(r.text)), 'iPhone: el súper sin academia elegida no ve precios en Mi plan')
chk(php('$GLOBALS["__forzar_app_ios"] = 1; $_SESSION["uid"] = valor("SELECT id FROM usuarios WHERE email = \'super@prueba.com\'"); echo ver_precios_software() ? 1 : 0;') == '0',
    'iPhone: ver_precios_software() da no aunque sea el súper')

print('\n== 9. Revisión estricta: ninguna pantalla del dueño ni del súper con precio, plan para contratar, mejorar o pagar el software')
# Pantallas donde SÍ hay dinero de la academia (pensiones, tienda, contabilidad, rol de pago…): ahí «$» y «precio» son de la academia
DINERO_ACADEMIA = {'inicio', 'cobros', 'grupos', 'indicadores', 'mensajes', 'contabilidad', 'tienda', 'profesores', 'particulares', 'recitales',
                   'escuela_config', 'tufactura', 'mi_rol', 'alumnos', 'esc_recibo', 'examenes', 'bitacora',
                   'finanzas', 'entradas', 'reservas', 'recibo', 'liquidacion', 'arbitros'}  # (modo ligas: el dinero de la liga)
ACADEMIA = php('echo PRODUCTO;') == 'academia'  # los PDF y algunos textos de la web solo existen en el producto academia
SIEMPRE = re.compile(r'\bUSD\b|contrat|Contrat|mejor(e|ar)\s+(su|el|de|mi)\s+plan|cambi(e|ar) de plan|\bplanes\b|Ver planes|pagos\.tukanec|cotizaci|Pedir este módulo|Solicitar este módulo|renueve|Renovar|renovar|Google Play|plux\.|payphone\.com|Recomiende y gane|meses de descuento|Solicite (una|su) demo|Pida su demo', re.I)
DINERO = re.compile(r'\$\s?\d|\bprecio', re.I)
# Frases neutras con «plan» que Apple aprobó en TuVocalía (informan, no venden)
NEUTRAS = re.compile(r'Mi plan|Tu plan|Su plan( actual)? no incluye|Parámetros y plan|Plan y módulos encendidos|Plan (Escuela )?(Básico|Completo)|Periodo de prueba|\bplan(es)? de (clase|entrenamiento)', re.I)
SOFTWARE = {'inicio', 'mi_plan', 'novedades', 'soporte', 'ayuda', 'ligas', 'demos', 'portales', 'escuela_config', 'avisos_padres', 'cargas', 'mis_datos', 'seguridad', 'usuarios', 'datos', 'indicadores'}


def estricto(s, paginas):
    malas = {}
    for p_ in sorted(paginas):
        r = s.get(B + 'admin.php?p=' + p_)
        v = visible(r.text)
        v = v[v.find('</header>') if '</header>' in v else 0:]
        if not limpio(r):
            malas[p_] = 'error PHP'
            continue
        m = SIEMPRE.search(v)
        if m:
            malas[p_] = m.group(0)
        elif p_ not in DINERO_ACADEMIA and DINERO.search(v):
            malas[p_] = DINERO.search(v).group(0)
        elif p_ in SOFTWARE and re.search(r'\bplan\b', NEUTRAS.sub('', v), re.I):
            i = re.search(r'\bplan\b', NEUTRAS.sub('', v), re.I).start()
            malas[p_] = '«plan»: …' + NEUTRAS.sub('', v)[max(0, i - 40):i + 20] + '…'
        elif p_ in SOFTWARE and re.search(r'mejorar', v, re.I):
            malas[p_] = 'mejorar'
    return malas


def menu(s):
    return set(re.findall(r'href="admin\.php\?p=([a-z_]+)', s.get(B + 'admin.php').text)) - {'salir'}


php('q("UPDATE ligas SET vence = ?, es_prueba = 1, activa = 1 WHERE id = %s", [date("Y-m-d", strtotime("+5 days"))]);' % ESC)  # en demo: tira de la demo
php('q("UPDATE ligas SET vence = ?, es_prueba = 0, activa = 1 WHERE id = %s", [date("Y-m-d", strtotime("+3 days"))]);' % LIGA)  # aviso de vencimiento
for email, nombre in (('liga@prueba.com', 'dueño (cuenta 1, por vencer)'), ('escuela@prueba.com', 'dueño (cuenta 2, en demo)')):
    s_ = entrar(email, UA_IOS)
    pags = menu(s_) | {'inicio', 'mi_plan'}
    m = estricto(s_, pags)
    chk(len(pags) >= 10 and not m, 'iPhone, %s: %d pantallas sin «$»/precio del software, USD, plan para contratar, mejorar ni pagos %s' % (nombre, len(pags), m))
s_ = entrar('super@prueba.com', UA_IOS)
pags = menu(s_)
m = estricto(s_, pags)
chk(len(pags) >= 6 and not m, 'iPhone, súper: %d pantallas limpias %s' % (len(pags), m))
s_.get(B + 'admin.php?p=ligas&entrar=' + ESC)
pags = menu(s_) | {'inicio', 'mi_plan'}
m = estricto(s_, pags)
chk(len(pags) >= 15 and not m, 'iPhone, súper dentro de una academia: %d pantallas limpias %s' % (len(pags), m))
m = estricto(entrar('liga@prueba.com', UA_WEB), {'inicio', 'mi_plan'})
chk('mi_plan' in m and 'inicio' in m, 'sin TukanIOS: la misma revisión sí encuentra el plan y sus pagos en la web (Resumen y Mi plan) %s' % m)
m = estricto(entrar('super@prueba.com', UA_WEB), {'negocio', 'suscripciones', 'modulos', 'ajustes'})
chk(len(m) == 4, 'sin TukanIOS: el súper sigue con precios en Negocio, Ventas, Disciplinas y Ajustes %s' % sorted(m))
s_web = entrar('escuela@prueba.com', UA_WEB)
r = s_web.get(B + 'admin.php')
chk(re.search(r'Contratar|Ver Mi plan', r.text) and ('manual.pdf' in r.text or not ACADEMIA), 'sin TukanIOS: el Resumen de la demo sigue con «Ver Mi plan» y los PDF')
r = entrar('escuela@prueba.com', UA_IOS).get(B + 'admin.php')
chk('tarjeta-demo' in r.text and 'manual.pdf' not in r.text and 'primeros_pasos.pdf' not in r.text, 'iPhone: la tira de la demo sin los PDF (traen el capítulo de Mi plan)')
r = entrar('liga@prueba.com', UA_WEB).get(B + 'admin.php?p=escuela_config')
chk('Ver planes' in r.text or 'plan Completo' in r.text or not ACADEMIA, 'sin TukanIOS: Parámetros sigue ofreciendo el plan Completo / Ver planes')
r = entrar('liga@prueba.com', UA_IOS).get(B + 'admin.php?p=escuela_config')
chk(limpio(r) and 'Ver planes' not in r.text and 'plan Completo' not in r.text, 'iPhone: Parámetros sin «Ver planes» ni «Está en el plan Completo»')

print('\n== 10. Novedades, login, soporte, manual, PDF y Ayuda en el iPhone')
php('q("INSERT INTO novedades (titulo, texto, para, enlace, version, publicada, fecha) VALUES (\'💵 Nuevo plan Pro\', \'Ahora el plan Pro cuesta $15 al mes: contrátelo en Mi plan.\', \'todos\', \'admin.php?p=mi_plan\', \'academia\', 1, ?)", [date("Y-m-d")]);')
php('q("INSERT INTO novedades (titulo, texto, para, enlace, version, publicada, fecha) VALUES (\'📅 Calendario nuevo\', \'Vea las clases de la semana.\', \'todos\', \'\', \'academia\', 1, ?)", [date("Y-m-d")]);')
r = entrar('escuela@prueba.com', UA_IOS).get(B + 'admin.php?p=novedades')
chk(limpio(r) and 'Nuevo plan Pro' not in r.text and 'Calendario nuevo' in r.text, 'iPhone: novedades sin la del plan (las demás sí)')
r = entrar('escuela@prueba.com', UA_WEB).get(B + 'admin.php?p=novedades')
chk('Nuevo plan Pro' in r.text, 'sin TukanIOS: la novedad del plan se ve')
r = sup_ios.get(B + 'admin.php?p=novedades')
chk('Nuevo plan Pro' not in r.text and 'Calendario nuevo' in r.text, 'iPhone: el súper tampoco ve la novedad del plan')
r = sesion(UA_IOS).get(B + 'admin.php?p=login')
chk(limpio(r) and 'Solicite una demo' not in r.text and 'solicitar' not in r.text, 'iPhone: el ingreso sin «Solicite una demo»')
r = sesion(UA_WEB).get(B + 'admin.php?p=login')
chk('Solicite una demo' in r.text, 'sin TukanIOS: el ingreso sigue con «Solicite una demo»')
r = entrar('escuela@prueba.com', UA_IOS).get(B + 'admin.php?p=soporte&nuevo=problema')
chk(limpio(r) and 'Pagos, plan o factura' not in r.text and 'Algo no funciona' in r.text, 'iPhone: nuevo ticket sin el tipo «Pagos, plan o factura»')
r = entrar('escuela@prueba.com', UA_WEB).get(B + 'admin.php?p=soporte&nuevo=problema')
chk('Pagos, plan o factura' in r.text, 'sin TukanIOS: nuevo ticket sigue con «Pagos, plan o factura»')
tk_cot = val("SELECT id FROM soporte_tickets WHERE liga_id = ? AND tipo = 'pagos' ORDER BY id LIMIT 1", LIGA)
ios_l = entrar('liga@prueba.com', UA_IOS)
r = ios_l.get(B + 'admin.php?p=soporte')
chk(tk_cot != '' and limpio(r) and 'Cotizaci' not in r.text and ('t=' + tk_cot) not in r.text, 'iPhone: la lista de tickets sin el de la cotización del plan')
r = ios_l.get(B + 'admin.php?p=soporte&t=' + tk_cot)
chk(limpio(r) and 'no está disponible en la app' in r.text and 'Cotizaci' not in r.text, 'iPhone: el ticket de la cotización no se abre')
r = ios_l.get(B + 'admin.php?p=bitacora')
chk(limpio(r) and 'Cotizaci' not in r.text, 'iPhone: la bitácora sin la cotización del plan')
web_l = entrar('liga@prueba.com', UA_WEB)
chk('Cotizaci' in web_l.get(B + 'admin.php?p=soporte').text and 'Cotizaci' in web_l.get(B + 'admin.php?p=bitacora').text, 'sin TukanIOS: el ticket y la bitácora de la cotización se ven')
for ruta in ('manual/escuelas', 'index.php?v=manual&m=escuelas&idioma=en', 'index.php?v=manual'):
    r = sesion(UA_IOS).get(B + ruta)
    v = visible(r.text)
    m = re.search(r'class="nota-demo"|Pida su|solicitar-demo|cuentas pagadas|meses de descuento|Descargar PDF|\.pdf"|Request your|Not using|Mi plan|My plan|cotizaci|quote|Solicitar este módulo|Contratar|Renovar|Google Play|pagos\.tukanec|hace falta el plan|planes completos', r.text)
    chk(limpio(r) and not m and '990001111' not in r.text, 'iPhone: %s sin Mi plan, cotizar, pedir demo, «cuentas pagadas», descuentos por recomendar ni PDF %s' % (ruta, m.group(0) if m else ''))
r = sesion(UA_WEB).get(B + 'manual/escuelas')
chk('class="nota-demo"' in r.text and 'Mi plan' in r.text, 'sin TukanIOS: el manual sigue con «Pida su demo» y Mi plan')
r = sesion(UA_IOS).get(B + 'manual.pdf')
chk(r.status_code == 404 and not r.headers.get('Content-Type', '').startswith('application/pdf'), 'iPhone: /manual.pdf no se descarga')
r = sesion(UA_WEB).get(B + 'manual.pdf')
chk(not ACADEMIA or (r.status_code == 200 and r.headers.get('Content-Type', '').startswith('application/pdf')), 'sin TukanIOS: /manual.pdf sigue descargándose')
txt = php('$GLOBALS["__forzar_app_ios"] = 1; echo ia_manual("academia");')
chk(txt != '' and not re.search(r'Hay dos planes|se cotiza|Solicitar este módulo|Pida su demo', txt), 'iPhone: la Ayuda (IA) lee el manual sin planes, cotizar ni pedir demo')
php('ia_faq()->guardar(["pregunta" => "cuanto cuesta el plan completo", "respuesta" => "El plan Completo cuesta $29 al mes; contrátelo en Mi plan.", "aprobada" => 1, "origen" => "manual"]);')
resp_ios = php('$GLOBALS["__forzar_app_ios"] = 1; $r = ia_ayuda(null, "cuanto cuesta el plan completo"); echo $r["fuente"] ?? "", "|", $r["respuesta"] ?? "";')
resp_web = php('$r = ia_ayuda(null, "cuanto cuesta el plan completo"); echo $r["fuente"] ?? "", "|", $r["respuesta"] ?? "";')
chk(resp_web.startswith('faq|') and '$29' in resp_web, 'sin TukanIOS: la pregunta frecuente con precio responde igual (%s)' % resp_web[:60])
chk('$29' not in resp_ios and 'no está disponible en la app' in resp_ios, 'iPhone: la Ayuda no da el precio del plan (respuesta neutra)')

log = os.path.join(REPO, 'pruebas', 'v38', 'web.log')
if os.path.exists(log):
    txt = open(log, encoding='utf-8', errors='replace').read()
    chk(not ERRORES.search(txt), 'el servidor web no registró errores de PHP')

print('\nRESULTADO prueba_app_iphone: %d/%d' % (ok, ok + len(fallos)))
if fallos:
    print('Fallas:\n - ' + '\n - '.join(fallos))
sys.exit(1 if fallos else 0)
