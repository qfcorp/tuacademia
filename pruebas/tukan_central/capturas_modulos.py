#!/usr/bin/env python3
"""
Capturas de «Mi plan → Mis módulos» (cliente) y de «Ligas → Editar → Módulos de la cuenta» (súper) en el celular (390 px)
y en la computadora (1280 px). También deja las imágenes del manual en public/assets/manual/modulos/.
Antes: la base de pruebas (reiniciar.sh) y php -S en TA_PUERTO.   Uso: TA_PUERTO=8410 python3 pruebas/tukan_central/capturas_modulos.py [carpeta]
Revisa además que las dos pantallas no tengan scroll de lado en el celular.
"""
import os, re, subprocess, sys
from playwright.sync_api import sync_playwright

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
SALIDA = sys.argv[1] if len(sys.argv) > 1 else os.path.join(DIR, 'capturas')
MANUAL = os.path.join(REPO, 'public', 'assets', 'manual', 'modulos')
os.makedirs(SALIDA, exist_ok=True)
os.makedirs(MANUAL, exist_ok=True)
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


T = 'escuela' if php('echo function_exists("es_academia") && es_academia() ? 1 : 0;') == '1' else 'liga'
cuenta = php("$c = fila(\"SELECT l.id, u.email FROM ligas l JOIN usuarios u ON u.liga_id = l.id AND u.rol = 'liga' WHERE COALESCE(l.es_demo, 0) = 0 AND l.tipo = ? ORDER BY l.id LIMIT 1\", ['" + T + "']); echo $c['id'], ' ', $c['email'];").split()
LID, EMAIL = cuenta[0], cuenta[1]
PL = php("echo (int)valor(\"SELECT id FROM planes WHERE activo = 1 AND tipo_cuenta IN ('ambos', '" + T + "') AND precio_mensual > 0 AND modulos <> '*' ORDER BY precio_mensual, id LIMIT 1\");")
# la cuenta con su plan, un módulo extra y uno pedido (para que se vea todo)
php("q(\"UPDATE ligas SET plan_id = ?, es_prueba = 0, vence = ?, modulos_extra = '', modulos_quitados = '', precio_acordado = NULL, modulos_elegidos = 0 WHERE id = ?\", [" + PL + ", date('Y-m-d', strtotime('+60 days')), " + LID + "]);"
    "$l = fila('SELECT * FROM ligas WHERE id = " + LID + "'); $c = catalogo_modulos(tipo_modulos($l)); $m = modulos_cliente($l);"
    "$fuera = array_values(array_filter(array_keys($c), fn($k) => !in_array($k, $m['del_plan'], true) && !$c[$k]['depende_de']));"
    "cambiar_modulos_cuenta($l, [$fuera[0]], [], ['por' => 'capturas']);"
    "q(\"DELETE FROM modulos_pedidos WHERE liga_id = " + LID + "\"); crear_pedido_modulos(fila('SELECT * FROM ligas WHERE id = " + LID + "'), 'Dueño', [$fuera[1]], [], 'Lo necesitamos para este mes');")

with sync_playwright() as p:
    nav = p.chromium.launch()
    for ancho, nombre in [(390, 'celular'), (1280, 'computadora')]:
        for quien, email, url, ancla, archivo in [('cliente', EMAIL, 'admin.php?p=mi_plan', '#modulos', 'mi_plan'),
                                                  ('super', 'super@prueba.com', 'admin.php?p=ligas&editar=' + LID, '#modulos-cuenta', 'super_modulos')]:
            ctx = nav.new_context(viewport={'width': ancho, 'height': 900}, device_scale_factor=2 if ancho == 390 else 1)
            pg = ctx.new_page()
            pg.goto(B + 'admin.php?p=login')
            pg.fill('input[name=email]', email)
            pg.fill('input[name=clave]', 'clave12345')
            pg.click('button[type=submit], form button')
            pg.wait_for_load_state('networkidle')
            pg.goto(B + url)
            el = pg.locator(ancla)
            chk(el.count() == 1, '%s %s: se ve %s' % (quien, nombre, ancla))
            if not el.count():
                ctx.close()
                continue
            ancho_doc = pg.evaluate('document.documentElement.scrollWidth')
            chk(ancho_doc <= ancho + 1, '%s %s: sin scroll de lado (%s px)' % (quien, nombre, ancho_doc))
            el.scroll_into_view_if_needed()
            pg.screenshot(path=os.path.join(SALIDA, '%s_%s_%s.png' % (os.path.basename(REPO), archivo, nombre)), full_page=False)
            el.screenshot(path=os.path.join(SALIDA, '%s_%s_%s_bloque.png' % (os.path.basename(REPO), archivo, nombre)))
            if ancho == 1280:
                el.screenshot(path=os.path.join(MANUAL, archivo + '.jpg'), type='jpeg', quality=80)
            ctx.close()
    nav.close()
print('\nRESULTADO capturas_modulos: %d/%d · en %s' % (ok, ok + len(fallos), SALIDA))
sys.exit(1 if fallos else 0)
