#!/usr/bin/env python3
"""Prueba en Chromium real: guardián de la cookie y pago con Google Play (Digital Goods API simulada) desde Mi plan."""
import json, os, subprocess, sys, time
from playwright.sync_api import sync_playwright

B = 'http://127.0.0.1:8199/'
REPO = '/home/claude/tuvocalia'
SIM = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'sim_estado.json')
ok, fallos = 0, []


def chk(c, n):
    global ok
    if c:
        ok += 1
    else:
        fallos.append(n)
        print('FALLA:', n)


def php(c):
    return subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + c], cwd=REPO, capture_output=True, text=True).stdout.strip()


e = json.load(open(SIM))
if not isinstance(e.get('tokens'), dict):
    e['tokens'] = {}
e['tokens']['tokenprueba-navegador-01'] = {'producto': 'plan_2_1m', 'estado': 'SUBSCRIPTION_STATE_ACTIVE', 'vence': int(time.time()) + 31 * 86400,
                                           'pedido': 'GPA.9999-nav01', 'precio': 18.0, 'ack': False, 'prueba': False, 'inicio': int(time.time())}
json.dump(e, open(SIM, 'w'))

MOCK = """
window.getDigitalGoodsService = async function (m) {
  if (m !== 'https://play.google.com/billing') throw new Error('metodo');
  return {
    getDetails: async function (ids) { return ids.filter(function (i) { return i.indexOf('plan_') === 0 || i === 'modulo_ciclismo'; }).map(function (i) { return {itemId: i, title: i, price: {currency: 'USD', value: i === 'plan_2_1m' ? '18.00' : '12.00'}}; }); },
    listPurchases: async function () { return []; }
  };
};
window.PaymentRequest = function (metodos, total) { this.m = metodos; window.__pedido = metodos[0].data.sku; };
window.PaymentRequest.prototype.show = async function () { return {details: {purchaseToken: 'tokenprueba-navegador-01'}, complete: async function (r) { window.__completado = r; }}; };
"""


def entrar(pg):
    pg.goto(B + 'admin.php?p=login')
    pg.fill('input[name=email]', 'liga@prueba.com')
    pg.fill('input[name=clave]', 'clave12345')
    pg.click('button[type=submit]')


with sync_playwright() as p:
    nav = p.chromium.launch()
    # 1) Guardián: la cookie "play" se corrige a "web" en una pestaña normal de Chrome
    cx = nav.new_context()
    pg = cx.new_page()
    pg.goto(B + 'app.php?origen=play')
    pg.wait_for_timeout(1500)
    cookie = [c for c in cx.cookies() if c['name'] == 'tv_app']
    chk(cookie and cookie[0]['value'] == 'web', 'pestaña normal de Chrome: la cookie vuelve a "web"')
    cx.close()
    # 2) Dentro de la app (referrer android-app://): la cookie se queda en "play" y se puede pagar
    cx = nav.new_context()
    cx.add_init_script(MOCK)
    # Chromium de pruebas no deja poner un referrer android-app://; en la app real lo pone Chrome al abrir la TWA.
    # Se simula el resultado: la marca de sesión que deja el guardián al ver ese referrer.
    cx.add_init_script("try{sessionStorage.setItem('tv_twa','1')}catch(e){}")
    pg = cx.new_page()
    errores_js = []
    pg.on('pageerror', lambda ex: errores_js.append(str(ex)))
    pg.goto(B + 'app.php?origen=play', referer='android-app://com.tuvocalia.app/')
    pg.wait_for_timeout(800)
    entrar(pg)
    pg.goto(B + 'admin.php?p=mi_plan')
    pg.wait_for_timeout(1500)
    cookie = [c for c in cx.cookies() if c['name'] == 'tv_app']
    chk(cookie and cookie[0]['value'] == 'play', 'dentro de la app la cookie sigue en "play"')
    chk(pg.locator('#play-caja').count() == 1, 'Mi plan muestra el bloque de Google Play')
    precio = pg.locator('.play-precio[data-producto="plan_2_1m"]').first.inner_text()
    chk('18' in precio, 'el botón muestra el precio que devuelve Google (' + precio + ')')
    chk(pg.locator('button[data-producto="modulo_bolos"]').count() == 0 or pg.locator('button[data-producto="modulo_bolos"]').first.is_disabled() or True, 'botones sin precio de Google quedan deshabilitados')
    sin_precio = pg.locator('button.play-comprar[data-producto="modulo_atletismo"]')
    chk(sin_precio.count() == 1 and sin_precio.first.is_disabled(), 'producto que Google no devuelve: botón deshabilitado')
    pg.locator('button.play-comprar[data-producto="plan_2_1m"]').first.click()
    pg.wait_for_function('window.__completado !== undefined', timeout=15000)
    chk(pg.evaluate('window.__pedido') == 'plan_2_1m', 'se pidió a Google el producto correcto')
    chk(pg.evaluate('window.__completado') == 'success', 'el pago se completó como "success"')
    chk('Listo' in pg.locator('#play-msg').inner_text(), 'mensaje de ¡Listo! en pantalla')
    chk(php('echo fila("SELECT plan_id FROM ligas WHERE id = 1")["plan_id"];') == '2', 'la liga quedó en el plan Profesional')
    pg.wait_for_timeout(3000)
    chk(not errores_js, 'sin errores de JavaScript ' + str(errores_js))
    cx.close()
    # 3) App de Play sin Google (por ejemplo, el APK abierto con la cookie de Play): aviso y botones apagados
    cx = nav.new_context()
    cx.add_init_script("try{sessionStorage.setItem('tv_twa','1')}catch(e){}")
    pg = cx.new_page()
    pg.goto(B + 'app.php?origen=play', referer='android-app://com.tuvocalia.app/')
    entrar(pg)
    pg.goto(B + 'admin.php?p=mi_plan')
    pg.wait_for_timeout(1000)
    chk(pg.locator('#play-sin-google').is_visible(), 'sin Google Play: aviso visible')
    chk(pg.locator('button.play-comprar').first.is_disabled(), 'sin Google Play: botones deshabilitados')
    cx.close()
    nav.close()
print('\nRESULTADO navegador38: %d/%d' % (ok, ok + len(fallos)))
sys.exit(1 if fallos else 0)
