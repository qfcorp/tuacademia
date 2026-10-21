#!/usr/bin/env python3
"""Pruebas de la v38 (apps Android y Google Play) contra php -S + simulador de Google.
(TuAcademia) Se corren con el producto de ligas encendido: TA_PRODUCTO=ligas bash pruebas/v38/reiniciar.sh"""
import json, re, subprocess, sys, time, os
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DIR = os.path.dirname(os.path.abspath(__file__))
SIM = os.path.join(DIR, 'sim_estado.json')
ok = 0
fallos = []
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')


def chk(cond, nombre):
    global ok
    if cond:
        ok += 1
    else:
        fallos.append(nombre)
        print('FALLA:', nombre)


def limpio(r, nombre):
    chk(r.status_code < 500 and not ERRORES.search(r.text), nombre + ' sin errores PHP (' + str(r.status_code) + ')')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html) or re.search(r'data-csrf="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def entrar(email, origen=None):
    s = requests.Session()
    if origen:
        r = s.get(B + 'app.php?origen=' + origen)
        limpio(r, 'app.php?origen=' + origen)
    r = s.get(B + 'admin.php?p=login')
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': 'clave12345'})
    chk('salir' in r.text.lower(), 'login ' + email)
    return s


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout, r.stderr)
    return r.stdout.strip()


def sim():
    e = json.load(open(SIM)) if os.path.exists(SIM) else {}
    for k in ('tokens', 'subs'):
        if not isinstance(e.get(k), dict):
            e[k] = {}
    for k in ('log', 'emitidos'):
        e.setdefault(k, [])
    return e


def sim_guardar(e):
    json.dump(e, open(SIM, 'w'))


def sim_token(tok, producto, dias=30, estado='SUBSCRIPTION_STATE_ACTIVE', precio=12.0, ack=False, prueba=False, vence=None):
    e = sim()
    e['tokens'][tok] = {'producto': producto, 'estado': estado, 'vence': vence if vence else int(time.time()) + dias * 86400,
                        'pedido': 'GPA.3311-' + tok[-6:], 'precio': precio, 'ack': ack, 'prueba': prueba, 'inicio': int(time.time())}
    sim_guardar(e)


# ---------- 1. Superadministrador: página de Google Play ----------
su = entrar('super@prueba.com')
r = su.get(B + 'admin.php?p=google_play')
limpio(r, 'google_play')
chk('Productos para Google Play' in r.text and 'plan_1_1m' in r.text and 'modulo_ciclismo' in r.text, 'lista de productos')
chk('$12.00' in r.text and '$18.00' in r.text and '$8.50' in r.text, 'precios de Google +20 % redondeados')
chk('Google Play' in su.get(B + 'admin.php').text, 'menú del súper con Google Play')
r = su.post(B + 'admin.php?p=google_play', data={'csrf': csrf(r.text), 'accion': 'ajustes', 'play_activo': '1', 'play_factor': '20', 'play_paquete': 'com.tukan.tuacademia', 'play_idioma': 'es-419',
                                                 'play_huellas': 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99'})
limpio(r, 'guardar ajustes')
chk('guardados' in r.text, 'ajustes guardados')
r = su.post(B + 'admin.php?p=google_play', data={'csrf': csrf(r.text), 'accion': 'cuenta'}, files={'archivo': ('mal.json', b'{"type":"otra"}', 'application/json')})
chk('no es una clave de cuenta de servicio' in r.text, 'rechaza un JSON que no es cuenta de servicio')
r = su.get(B + 'admin.php?p=google_play')
r = su.post(B + 'admin.php?p=google_play', data={'csrf': csrf(r.text), 'accion': 'cuenta'}, files={'archivo': ('cuenta.json', open(os.path.join(DIR, 'cuenta_servicio.json'), 'rb'), 'application/json')})
limpio(r, 'subir cuenta')
chk('tuacademia-play@tuacademia-prueba.iam.gserviceaccount.com' in r.text, 'cuenta de servicio guardada')
chk(oct(os.stat(REPO + '/data/google_play_cuenta.json').st_mode)[-3:] == '600', 'archivo de la cuenta con permisos 600')
r = su.post(B + 'admin.php?p=google_play', data={'csrf': csrf(r.text), 'accion': 'probar'})
chk('Conexión correcta' in r.text, 'probar la conexión (token OAuth firmado RS256 aceptado)')
r = su.post(B + 'admin.php?p=google_play', data={'csrf': csrf(r.text), 'accion': 'crear'})
limpio(r, 'crear productos')
creados = r.text.count('creado a')
TOTAL = int(php('echo count(play_productos());'))
chk(creados == TOTAL and TOTAL == 33, 'crea los %d productos (%d)' % (TOTAL, creados))  # (TuAcademia) 31 de TuVocalía + baile y música
e = sim()
chk(len(e['subs']) == TOTAL and all(s['basePlans'][0]['state'] == 'ACTIVE' for s in e['subs'].values()), 'todos activados en Google')
p1 = e['subs'].get('plan_1_1m', {})
ec = [x for x in p1.get('basePlans', [{}])[0].get('regionalConfigs', []) if x['regionCode'] == 'EC']
chk(ec and ec[0]['price'] == {'currencyCode': 'USD', 'units': '12', 'nanos': 0}, 'plan_1_1m a $12 en Ecuador')
chk(p1.get('basePlans', [{}])[0].get('autoRenewingBasePlanType', {}).get('billingPeriodDuration') == 'P1M', 'período mensual')
chk(e['subs'].get('plan_1_12m', {}).get('basePlans', [{}])[0].get('autoRenewingBasePlanType', {}).get('billingPeriodDuration') == 'P1Y', 'período anual')
chk(e['subs'].get('plan_4_3m', {}).get('basePlans', [{}])[0].get('autoRenewingBasePlanType', {}).get('billingPeriodDuration') == 'P3M', 'Torneo trimestral')
m8 = [x for x in e['subs'].get('modulo_ciclismo', {}).get('basePlans', [{}])[0].get('regionalConfigs', []) if x['regionCode'] == 'EC']
chk(m8 and m8[0]['price'] == {'currencyCode': 'USD', 'units': '8', 'nanos': 500000000}, 'deporte adicional a $8,50')
r = su.post(B + 'admin.php?p=google_play', data={'csrf': csrf(r.text), 'accion': 'crear'})
chk(r.text.count('ya existe') == TOTAL and 'creado a' not in r.text, 'segunda vez: no duplica')
al = requests.get(B + 'assetlinks.php').json()
chk(len(al) == 2 and al[0]['target']['package_name'] == 'com.tukan.tuacademia' and al[1]['target']['package_name'] == 'com.tukan.tuacademia.directo', 'assetlinks con las 2 apps')
chk('AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99' in al[0]['target']['sha256_cert_fingerprints'], 'assetlinks con la huella de Google')
chk(al[1]['target']['sha256_cert_fingerprints'][0].startswith('11:22:33'), 'assetlinks con nuestra huella (la de app/config.php)')

# ---------- 2. Web normal vs app de Google Play ----------
php('guardar_ajuste("ventas_whatsapp", "0986833077");')
web = entrar('liga@prueba.com', 'web')
r = web.get(B + 'admin.php?p=mi_plan')
limpio(r, 'mi_plan web')
chk('play-caja' not in r.text and ('contratar' in r.text), 'web: sin Google Play, con contratar/transferencia')
chk('$10.00' in r.text, 'web: precios normales')
app = entrar('liga@prueba.com', 'play')
chk(app.cookies.get('tv_app') == 'play', 'cookie tv_app=play')
r = app.get(B + 'admin.php?p=mi_plan')
limpio(r, 'mi_plan en la app de Play')
chk('play-caja' in r.text and 'plan_1_1m' in r.text and 'assets/play.js' in r.text, 'app: bloque de Google Play')
chk('$12.00' in r.text and '$18.00' in r.text and '$120.00' in r.text, 'app: precios de Google')
chk('name="accion" value="comprobante"' not in r.text and 'Pagar con tarjeta' not in r.text and 'Datos para pagar' not in r.text, 'app: sin transferencia ni tarjeta')
chk('modulo_ciclismo' in r.text and '$8.50' in r.text, 'app: deportes adicionales con precio de Google')
chk('plan_5_1m' not in r.text, 'app: una liga no ve planes de escuela')
r2 = app.post(B + 'admin.php?p=mi_plan', data={'csrf': csrf(r.text), 'accion': 'comprobante', 'plan_meses': '1:1'})
chk('se paga con Google Play' in r2.text, 'app: rechaza el comprobante por POST')
r = app.get(B + 'admin.php')
chk('Renovar con Google Play' in r.text and 'Enviar el comprobante por WhatsApp' not in r.text, 'app: aviso de vencimiento solo con Google Play')
r = web.get(B + 'admin.php')
chk('Renovar con Google Play' not in r.text, 'web: aviso de vencimiento normal')
r = app.get(B + 'index.php')
limpio(r, 'portada en la app')
# (20 oct 2026) regla del dueño: la portada nunca muestra precios del software (ni los de Google ni los normales)
chk(not re.search(r'\$\s?\d', re.sub(r'(?is)<(script|style)\b.*?</\1>', ' ', r.text)), 'portada en la app sin precios del software')
r = web.get(B + 'index.php')
chk(not re.search(r'\$\s?\d', re.sub(r'(?is)<(script|style)\b.*?</\1>', ' ', r.text)) and 'cotizaci' in r.text.lower(), 'portada en la web sin precios del software (por cotización)')
pl = app.get(B + 'planes/basico')
limpio(pl, 'folleto del plan en la app')
chk('Quiero este plan' not in pl.text, 'app: sin "Quiero este plan" por WhatsApp')
chk('Quiero este plan' in web.get(B + 'planes/basico').text, 'web: con "Quiero este plan"')
img = app.get(B + 'imagen.php?tipo=plan&plan=1')
chk(img.status_code == 200 and img.headers.get('Content-Type', '').startswith('image/'), 'imagen del plan (precios de la web)')
r = app.get(B + 'app.php')
chk('Instalar aplicación' not in r.text and 'Descargar la app' not in r.text, 'app.php dentro de la app: sin instalar/descargar')
r = web.get(B + 'app.php')
chk('Descargar la app para Android' in r.text, 'app.php en la web: botón del APK')
chk("tv_app=web" in r.text and 'android-app' in r.text, 'guardián de la cookie en la página')
sup_app = requests.Session()
sup_app.get(B + 'app.php?origen=play')
r = sup_app.get(B + 'admin.php?p=login')
sup_app.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': 'super@prueba.com', 'clave': 'clave12345'})
chk('$10.00' in sup_app.get(B + 'admin.php?p=planes').text, 'el súper en la app ve precios normales')

# ---------- 3. Compras ----------
r = app.get(B + 'admin.php?p=mi_plan')
tk = csrf(r.text)
x = app.post(B + 'play.php', data={'accion': 'verificar', 'producto': 'plan_1_1m', 'token': 'tokenprueba-aaaaaaaaaa1'})
chk(x.status_code == 400, 'play.php sin csrf → 400')
x = requests.post(B + 'play.php', data={'csrf': 'x', 'accion': 'verificar'})
chk(x.status_code == 400, 'play.php sin sesión → rechaza')
sim_token('tokenprueba-plan-000001', 'plan_1_1m', dias=30, precio=12.0)
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'verificar', 'producto': 'plan_1_1m', 'token': 'tokenprueba-plan-000001'}).json()
chk(x.get('ok') and 'activo hasta' in x.get('mensaje', ''), 'compra del plan verificada: ' + json.dumps(x, ensure_ascii=False))
esperado = time.strftime('%Y-%m-%d', time.localtime(time.time() + 30 * 86400))
liga = json.loads(php('echo json_encode(fila("SELECT vence, plan_id, es_prueba FROM ligas WHERE id = 1"));'))
chk(liga['vence'] == esperado and int(liga['plan_id']) == 1 and int(liga['es_prueba']) == 0, 'liga activa hasta lo que dice Google (' + str(liga) + ')')
pago = json.loads(php('echo json_encode(fila("SELECT * FROM pagos_suscripcion WHERE liga_id = 1 ORDER BY id DESC LIMIT 1"));'))
chk(pago['forma_pago'] == 'google_play' and float(pago['monto']) == 12.0 and pago['hasta'] == esperado, 'pago registrado como Google Play $12')
chk(sim()['tokens']['tokenprueba-plan-000001'].get('ack') is True, 'compra reconocida en Google (acknowledge)')
c = json.loads(php('echo json_encode(fila("SELECT * FROM play_compras WHERE token = ?", ["tokenprueba-plan-000001"]));'))
chk(int(c['reconocida']) == 1 and c['estado'] == 'SUBSCRIPTION_STATE_ACTIVE', 'compra guardada y reconocida')
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'verificar', 'producto': 'plan_1_1m', 'token': 'tokenprueba-plan-000001'}).json()
n_pagos = int(php('echo valor("SELECT COUNT(*) FROM pagos_suscripcion WHERE liga_id = 1");'))
chk(x.get('ok') and n_pagos == 1, 'mandar la misma compra 2 veces no duplica el pago')
chk(sim()['tokens']['tokenprueba-plan-000001'].get('acks') == 1, 'no reconoce 2 veces')
r = app.get(B + 'admin.php?p=mi_plan')
limpio(r, 'mi_plan después de pagar')
chk('Su plan se paga con <b>Google Play</b>' in r.text, 'mi_plan muestra el plan de Google Play')
chk('Google Play' in r.text and 'Mis pagos' in r.text, 'mis pagos con forma Google Play')
# Otra cuenta no puede usar la misma compra
esc = entrar('escuela@prueba.com', 'play')
r = esc.get(B + 'admin.php?p=mi_plan')
limpio(r, 'mi_plan escuela en la app')
chk('plan_5_1m' in r.text and 'plan_1_1m' not in r.text, 'escuela ve solo planes de escuela')
tke = csrf(r.text)
x = esc.post(B + 'play.php', data={'csrf': tke, 'accion': 'verificar', 'producto': 'plan_1_1m', 'token': 'tokenprueba-plan-000001'}).json()
chk(not x.get('ok') and 'otra cuenta' in x.get('error', ''), 'la compra de otra cuenta se rechaza')
sim_token('tokenprueba-plan-000002', 'plan_1_1m')
x = esc.post(B + 'play.php', data={'csrf': tke, 'accion': 'verificar', 'producto': 'plan_1_1m', 'token': 'tokenprueba-plan-000002'}).json()
chk(not x.get('ok') and 'no es para escuelas' in x.get('error', ''), 'una escuela no compra un plan de liga')
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'verificar', 'producto': 'plan_2_1m', 'token': 'tokenprueba-plan-000002'}).json()
chk(not x.get('ok') and 'no corresponde' in x.get('error', ''), 'producto distinto al de la compra se rechaza')
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'verificar', 'producto': 'no_existe', 'token': 'tokenprueba-plan-000003'}).json()
chk(not x.get('ok'), 'producto inexistente se rechaza')
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'verificar', 'producto': 'plan_1_1m', 'token': 'no-existe-en-google-123'}).json()
chk(not x.get('ok') and 'Google no reconoce' in x.get('error', ''), 'token falso se rechaza')
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'verificar', 'producto': 'plan_1_1m', 'token': 'malo <script>'}).json()
chk(not x.get('ok'), 'token con caracteres raros se rechaza')
# Deporte adicional
sim_token('tokenprueba-modulo-0001', 'modulo_ciclismo', dias=30, precio=8.5)
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'verificar', 'producto': 'modulo_ciclismo', 'token': 'tokenprueba-modulo-0001'}).json()
chk(x.get('ok') and 'Ciclismo' in x.get('mensaje', ''), 'compra del deporte adicional')
dep = php('echo fila("SELECT deportes FROM ligas WHERE id = 1")["deportes"];')
chk(dep == 'futbol,ciclismo', 'ciclismo agregado a la cuenta (' + dep + ')')
chk(int(php('echo valor("SELECT COUNT(*) FROM pagos_suscripcion WHERE liga_id = 1 AND plan_id IS NULL AND forma_pago = \'google_play\'");')) == 1, 'pago del deporte registrado')
r = app.get(B + 'admin.php?p=mi_plan')
chk('Activo con Google Play hasta' in r.text, 'mi_plan: deporte activo con Google Play')
# Restaurar compras
sim_token('tokenprueba-restaura-01', 'modulo_bolos', dias=30, precio=8.5)
x = app.post(B + 'play.php', data={'csrf': tk, 'accion': 'restaurar', 'compras': json.dumps([{'itemId': 'modulo_bolos', 'purchaseToken': 'tokenprueba-restaura-01'}, {'itemId': 'plan_1_1m', 'purchaseToken': 'tokenprueba-plan-000001'}])}).json()
chk(x.get('ok') and x.get('revisadas') == 2, 'restaurar compras del celular: ' + json.dumps(x, ensure_ascii=False))
chk('bolos' in php('echo fila("SELECT deportes FROM ligas WHERE id = 1")["deportes"];'), 'restaurar agrega bolos')
# Renovación (Google adelanta el vencimiento)
e = sim()
e['tokens']['tokenprueba-plan-000001']['vence'] += 30 * 86400
e['tokens']['tokenprueba-plan-000001']['pedido'] = 'GPA.3311-renov1'
sim_guardar(e)
php('$c = fila("SELECT * FROM play_compras WHERE token = ?", ["tokenprueba-plan-000001"]); echo play_aplicar((int)$c["id"], play_consultar($c["token"]));')
esperado2 = time.strftime('%Y-%m-%d', time.localtime(time.time() + 60 * 86400))
chk(php('echo fila("SELECT vence FROM ligas WHERE id = 1")["vence"];') == esperado2, 'renovación: la liga se extiende 1 mes más')
chk(int(php('echo valor("SELECT COUNT(*) FROM pagos_suscripcion WHERE liga_id = 1 AND plan_id = 1");')) == 2, 'renovación: segundo pago registrado')
# Revisión automática: deporte vencido se quita
e = sim()
e['tokens']['tokenprueba-modulo-0001']['estado'] = 'SUBSCRIPTION_STATE_EXPIRED'
e['tokens']['tokenprueba-modulo-0001']['vence'] = int(time.time()) - 3600
sim_guardar(e)
php('q("UPDATE play_compras SET vence_ts = ? WHERE token = ?", [time() - 3600, "tokenprueba-modulo-0001"]); print_r(play_revisar());')
dep = php('echo fila("SELECT deportes FROM ligas WHERE id = 1")["deportes"];')
chk('ciclismo' not in dep and 'futbol' in dep and 'bolos' in dep, 'deporte vencido en Google se quita (' + dep + ')')
# Tarea programada: corre cada 6 horas
php('guardar_ajuste("play_revision_ts", "0");')
subprocess.run(['php', 'app/tareas.php'], cwd=REPO, capture_output=True)
chk(int(php('echo ajuste("play_revision_ts");') or 0) > time.time() - 120, 'tareas.php ejecuta la revisión de Google Play')
# Compra de prueba (licencia de prueba): $0
sim_token('tokenprueba-testcomp-01', 'plan_5_1m', dias=1, precio=12.0, prueba=True)
x = esc.post(B + 'play.php', data={'csrf': tke, 'accion': 'verificar', 'producto': 'plan_5_1m', 'token': 'tokenprueba-testcomp-01'}).json()
chk(x.get('ok'), 'compra de prueba de Google (licencia de prueba)')
pg = json.loads(php('echo json_encode(fila("SELECT * FROM pagos_suscripcion WHERE liga_id = 2 ORDER BY id DESC LIMIT 1"));'))
chk(float(pg['monto']) == 0 and 'PRUEBA' in pg['nota'], 'compra de prueba registrada en $0 con nota PRUEBA')

# ---------- 4. Súper: compras y descarga del APK ----------
r = su.get(B + 'admin.php?p=google_play')
limpio(r, 'google_play con compras')
chk('tokenprueba' not in r.text and 'plan_1_1m' in r.text and 'Liga Prueba Play' in r.text, 'lista de compras (sin mostrar tokens)')
r = su.post(B + 'admin.php?p=google_play', data={'csrf': csrf(r.text), 'accion': 'revisar'})
chk('Compras revisadas en Google' in r.text, 'revisar todas ahora')
r = su.get(B + 'admin.php?p=suscripciones')
limpio(r, 'ventas con pagos de Google Play')
chk('Google Play' in r.text, 'ventas muestra la forma Google Play')
r = requests.get(B + 'descargar.php')
limpio(r, 'descargar.php')
chk('Cómo instalar el APK' in r.text, 'página de descarga')
r = requests.get(B + 'descargar.php?apk=1')
chk(r.status_code == 200 and r.headers.get('Content-Type') == 'application/vnd.android.package-archive' and r.content[:2] == b'PK', 'descarga del APK')
r = requests.get(B + 'manifest.php').json()
chk(r.get('id') == 'app.php' and r.get('start_url') == 'app.php?origen=web', 'manifest PWA con id y origen web')
nologin = requests.get(B + 'admin.php?p=google_play', allow_redirects=True)
chk('Productos para Google Play' not in nologin.text, 'google_play no se ve sin sesión')
liga_adm = entrar('liga@prueba.com')
chk('Productos para Google Play' not in liga_adm.get(B + 'admin.php?p=google_play').text, 'un admin de liga no entra a google_play')
# Desactivado: en la app se avisa y no se cobra
php('guardar_ajuste("play_activo", "0");')
r = app.get(B + 'admin.php?p=mi_plan')
chk('estará disponible muy pronto' in r.text and 'data-activo="0"' in r.text, 'cobro apagado: aviso en la app')
php('guardar_ajuste("play_activo", "1");')

log_web = open(os.path.join(DIR, 'web.log')).read()
chk(not ERRORES.search(log_web), 'registro de php -S sin errores PHP')
print('\nRESULTADO prueba38: %d/%d' % (ok, ok + len(fallos)))
sys.exit(1 if fallos else 0)
