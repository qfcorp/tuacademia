#!/usr/bin/env python3
"""
(13 nov 2026) App de iPhone (App Store, regla de Apple 3.1.1): app/app_ios.php.
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

log = os.path.join(REPO, 'pruebas', 'v38', 'web.log')
if os.path.exists(log):
    txt = open(log, encoding='utf-8', errors='replace').read()
    chk(not ERRORES.search(txt), 'el servidor web no registró errores de PHP')

print('\nRESULTADO prueba_app_iphone: %d/%d' % (ok, ok + len(fallos)))
if fallos:
    print('Fallas:\n - ' + '\n - '.join(fallos))
sys.exit(1 if fallos else 0)
