#!/usr/bin/env python3
"""
Pruebas del ANEXO B (programas modulares) y de «nada se da gratis, todo se pide demo», contra php -S.
El mismo archivo sirve para TuVocalía y TuAcademia (en TuAcademia en los dos modos: academia y ligas).

Antes (base nueva):
  TuVocalía:   TA_PUERTO=8410 bash pruebas/tukan_central/reiniciar.sh
  TuAcademia:  TA_PUERTO=8412 TA_SIM=8413 bash pruebas/v38/reiniciar.sh            (modo academia)
               TA_PRODUCTO=ligas TA_PUERTO=8412 TA_SIM=8413 bash pruebas/v38/reiniciar.sh
Correr:        TA_PUERTO=8410 python3 pruebas/tukan_central/prueba_modulos.py

Prueba:
 1. Mapa: TODA página del panel (admin.php?p=…) tiene su módulo o dice que es base; TODO archivo de public/ está en el mapa
    de portales, y los que son de un módulo lo revisan de verdad (exigir_modulo… / modulo_activo).
 2. Catálogo: cada módulo tiene precio, grupo y dependencias que existen; sin círculos.
 3. Cuentas de los módulos: activos = (plan − quitados) + extra, dependencias, descuentos, precio acordado, demo con todo,
    opciones_precio (Mi plan, cotizar) con los extras; apagar no borra datos.
 4. Bloqueo real: con un módulo apagado, su página manda a Mi plan, el menú no lo muestra y su portal dice «No disponible»;
    al encenderlo vuelve todo como estaba.
 5. Mi plan: «Mis módulos», «Pedir este módulo» → pedido; el súper lo ve en Ligas → Editar y lo aprueba; interruptores del súper.
 6. Súper: catálogo de funciones (precio) y pedidos.
 7. Sin prueba gratis: el registro abierto ya no existe (lleva a pedir la demo) y las páginas de venta no ofrecen nada gratis.
Al final revisa que php -S no haya registrado errores de PHP.
"""
import json, os, re, subprocess, sys
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
ok, fallos = 0, []


def chk(cond, nombre):
    global ok
    if cond:
        ok += 1
    else:
        fallos.append(nombre)
        print('FALLA:', nombre)


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout[-1500:], r.stderr[-1500:])
    return r.stdout.strip()


def pj(codigo):
    return json.loads(php('echo json_encode(' + codigo + ', JSON_UNESCAPED_UNICODE);') or 'null')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def entrar(email, clave='clave12345'):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': clave})
    return s


def postear(s, url, datos):
    r = s.get(B + url)
    datos = dict(datos)
    datos['csrf'] = csrf(r.text)
    return s.post(B + url, data=datos, allow_redirects=False)


ACADEMIA = php('echo function_exists("es_academia") && es_academia() ? "1" : "0";') == '1'
T = 'escuela' if ACADEMIA else 'liga'
print('Producto:', php('echo marca();'), '(modo academia)' if ACADEMIA else '(modo ligas)', '· puerto', PUERTO)

# ---------- 1) Mapa de páginas y portales ----------
admin = open(os.path.join(REPO, 'public', 'admin.php'), encoding='utf-8').read()
bloque = admin[admin.index('$validas = ['):admin.index('];', admin.index('$validas = ['))]
paginas = re.findall(r"'([a-z_0-9]+)'", bloque)
base = set(pj('paginas_base()'))
mapa = pj('array_combine($x = ' + json.dumps(paginas).replace('[', 'array(').replace(']', ')') + ', array_map("modulo_de_pagina", $x))')
sin = [p for p in paginas if not mapa.get(p) and p not in base]
chk(len(paginas) >= 70 and not sin, 'mapa: las %d páginas del panel tienen módulo o son base %s' % (len(paginas), sin))
mods_sis = set(pj('array_keys(modulos_sistema())'))
malos = {p: m for p, m in mapa.items() if m and m not in mods_sis}
chk(not malos, 'mapa: cada página apunta a un módulo que existe ' + str(malos))
portales = pj('mapa_portales()')
archivos = sorted(f for f in os.listdir(os.path.join(REPO, 'public')) if f.endswith('.php'))
faltan = [f for f in archivos if f not in portales]
chk(not faltan, 'mapa: los %d archivos de public/ están en el mapa de portales %s' % (len(archivos), faltan))
sin_revisar = []
for f in archivos:
    m = portales.get(f, '')
    if m.startswith('base') or m.startswith('firmado:') or not m:
        continue
    t = open(os.path.join(REPO, 'public', f), encoding='utf-8').read()
    if not re.search(r"(exigir_modulo_portal|exigir_modulo_corto|modulo_activo|modulo_encendido)\(.*'" + re.escape(m) + "'", t) and 'es_academia()) exigir_modulo_portal' not in t:
        sin_revisar.append(f + ' → ' + m)
chk(not sin_revisar, 'mapa: cada portal de un módulo revisa su módulo ' + str(sin_revisar))
chk('exigir_modulo_panel($liga, \'arbitros\'' in open(os.path.join(REPO, 'app', 'admin', 'imprimir.php'), encoding='utf-8').read() or "get('doc') === 'informe' ? 'arbitros'" in open(os.path.join(REPO, 'app', 'admin', 'imprimir.php'), encoding='utf-8').read(),
    'mapa: los PDF de la vocalía llena y del informe del árbitro revisan su módulo')
for archivo, buscar in [('app/avisos.php', "modulo_activo($liga, 'avisos')"), ('app/extras.php', "'publico')"), ('app/publico/liga.php', "modulo_activo($liga, 'reservas')"),
                        ('app/publico/liga.php', "modulo_activo($liga, 'publico')"), ('public/familia.php', "modulo_activo($liga, 'esc_evaluaciones')")]:
    chk(buscar in open(os.path.join(REPO, archivo), encoding='utf-8').read(), 'mapa: %s revisa %s' % (archivo, buscar))

# ---------- 2) Catálogo ----------
cat = pj('catalogo_modulos()')
chk(set(cat) == mods_sis, 'catálogo: tiene todos los módulos del sistema')
chk(all(isinstance(x['precio_mensual'], (int, float)) and x['precio_mensual'] > 0 and x['grupo'] in pj('grupos_modulos()') and all(d in cat for d in x['depende_de']) for x in cat.values()),
    'catálogo: precio, grupo y dependencias válidas en cada módulo')
chk(all(k not in pj('dependencias_modulo("' + k + '", catalogo_modulos())') for k in cat), 'catálogo: sin círculos de dependencias')
r = php('try { guardar_catalogo_modulos([["id" => "reservas", "depende_de" => ["finanzas"]], ["id" => "finanzas", "depende_de" => ["reservas"]]], [], "prueba"); echo "guardo"; } catch (RuntimeException $e) { echo "no: ", $e->getMessage(); }')
chk(r.startswith('no:') and 'círculo' in r, 'catálogo: no deja guardar dependencias en círculo') if 'reservas' in cat else chk(True, 'catálogo: (sin reservas)')
php('q("DELETE FROM modulos_catalogo");')

# ---------- 3) Cuentas de los módulos ----------
CUENTA = pj("fila(\"SELECT l.id, l.slug, u.email FROM ligas l JOIN usuarios u ON u.liga_id = l.id AND u.rol = 'liga' WHERE COALESCE(l.es_demo, 0) = 0 AND l.tipo = ? ORDER BY l.id LIMIT 1\", ['" + T + "'])")
LID = str(CUENTA['id'])
PL = php("echo (int)valor(\"SELECT id FROM planes WHERE activo = 1 AND tipo_cuenta IN ('ambos', '" + T + "') AND precio_mensual > 0 AND modulos <> '*' ORDER BY precio_mensual, id LIMIT 1\");")
php("q(\"UPDATE ligas SET plan_id = ?, es_prueba = 0, vence = ?, modulos_extra = '', modulos_quitados = '', precio_acordado = NULL, modulos_elegidos = 0 WHERE id = ?\", [" + PL + ", date('Y-m-d', strtotime('+60 days')), " + LID + "]);")
L = "fila('SELECT * FROM ligas WHERE id = " + LID + "')"
mc = pj('modulos_cliente(' + L + ')')
catT = pj('catalogo_modulos("' + T + '")')
chk(mc['activos'] == [k for k in catT if k in mc['del_plan']] and not mc['extra'] and mc['mensual'] == mc['precio_plan'] + mc['precio_deportes'], 'cuenta con plan: activos = los del plan, mensual = plan')
fuera = [k for k in catT if k not in mc['del_plan'] and not catT[k]['depende_de']]
APAGAR = ('carnets' if T == 'escuela' else 'redes') if ('carnets' if T == 'escuela' else 'redes') in mc['del_plan'] else mc['del_plan'][0]
EXTRA = fuera[0]
r = pj('cambiar_modulos_cuenta(' + L + ', ["' + EXTRA + '"], ["' + APAGAR + '"], ["descuentos" => ["' + APAGAR + '" => 1.5], "por" => "prueba"])')
chk(r['despues']['extra'] == [EXTRA] and r['despues']['quitados'] == [APAGAR] and r['despues']['mensual'] == round(mc['mensual'] + catT[EXTRA]['precio_mensual'] - 1.5, 2) and not r['sin_cambios'],
    'cambiar: extra + quitado con descuento → mensual = plan + extra − descuento')
chk(php('echo modulo_activo(' + L + ', "' + APAGAR + '") ? 1 : 0;') == '0' and php('echo modulo_activo(' + L + ', "' + EXTRA + '") ? 1 : 0;') == '1', 'modulo_activo respeta quitados y extra')
ops = pj('opciones_precio(plan_de_liga(' + L + '), ' + L + ')')
o1 = ops.get('mensual') or list(ops.values())[0]
chk(abs(o1['extra_modulos'] - (catT[EXTRA]['precio_mensual'] - 1.5) * o1['meses'] * o1['rebaja']) < 0.02 + mc['precio_deportes'] * o1['meses'], 'opciones_precio (Mi plan, cotizar, registrar pago) suma los extras y resta los descuentos')
chk(pj('cambiar_modulos_cuenta(' + L + ', ["' + EXTRA + '"], [], [])')['sin_cambios'] is True, 'cambiar: repetir lo mismo → sin cambios')
dep = next((k for k in catT if catT[k]['depende_de']), None)
if dep:
    r = pj('cambiar_modulos_cuenta(' + L + ', ["' + dep + '"], [], [])')
    chk(set(catT[dep]['depende_de']) <= set(r['despues']['activos']), 'cambiar: encender ' + dep + ' enciende lo que necesita')
    e = php('try { cambiar_modulos_cuenta(' + L + ', [], ' + json.dumps(catT[dep]['depende_de']).replace('[', '[').replace(']', ']') + ', []); echo "si"; } catch (RuntimeException $e) { echo "no: ", $e->getMessage(); }')
    chk(e.startswith('no:') and 'depende' in e, 'cambiar: no se apaga una dependencia de otro encendido')
    php('cambiar_modulos_cuenta(' + L + ', [], ["' + dep + '"], []);')
r = pj('cambiar_modulos_cuenta(' + L + ', [], [], ["precio_acordado" => 12])')
chk(r['despues']['mensual'] == 12 and pj('array_values(opciones_precio(plan_de_liga(' + L + '), ' + L + '))[0]')['por_mes'] == 12, 'precio acordado: manda en el mensual y en los precios')
php('cambiar_modulos_cuenta(' + L + ', [], [], ["precio_acordado" => null]);')
e = php('try { cambiar_modulos_cuenta(' + L + ', ["no_existe"], [], []); echo "si"; } catch (RuntimeException $e) { echo "no"; }')
chk(e == 'no', 'cambiar: un módulo que no existe se rechaza')
# la demo tiene todo; si el súper elige, se respeta
php("q(\"DELETE FROM ligas WHERE slug = 'demo-mods-x'\");")
php("q(\"INSERT INTO ligas (nombre, slug, vence, es_prueba, activa, es_demo, tipo, plan_id) VALUES ('Demo Mods', 'demo-mods-x', ?, 1, 1, 0, '" + T + "', " + PL + ")\", [date('Y-m-d', strtotime('+5 days'))]);")
D = "fila(\"SELECT * FROM ligas WHERE slug = 'demo-mods-x'\")"
chk(set(pj('modulos_cliente(' + D + ')')['activos']) == set(catT) and pj('modulos_cliente(' + D + ')')['todos_en_demo'] is True, 'demo: tiene todos los módulos')
r = pj('cambiar_modulos_cuenta(' + D + ', [], ["' + APAGAR + '"], [])')
chk(set(r['despues']['activos']) == set(catT) - {APAGAR}, 'demo: si el súper apaga uno, se apaga solo ese (lo demás sigue)')

# ---------- 4) Bloqueo real (panel, menú y portal) ----------
pag = {'carnets': 'esc_carnets', 'redes': 'redes'}.get(APAGAR, APAGAR)
filas_antes = php("echo (int)valor('SELECT COUNT(*) FROM usuarios WHERE liga_id = " + LID + "') + (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = " + LID + "') + (int)valor('SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = " + LID + "');")
s = entrar(CUENTA['email'])
r = s.get(B + 'admin.php?p=' + pag, allow_redirects=False)
chk(r.status_code in (302, 303) and 'mi_plan' in r.headers.get('Location', ''), 'apagado: la página de ' + APAGAR + ' manda a Mi plan')
ini = s.get(B + 'admin.php').text
chk('p=' + pag + '"' not in ini and "p=" + pag + "'" not in ini, 'apagado: el menú no muestra ' + pag)
if T == 'liga':
    php("q('UPDATE ligas SET modulos_quitados = ? WHERE id = " + LID + "', [json_encode(['publico' => 0])]);")
    r = requests.get(B + 'widget.php', params={'l': CUENTA['slug'], 'w': 'tabla'})
    chk(r.status_code == 403 and 'No disponible' in r.text, 'apagado: el widget (Patrocinadores y público) dice «No disponible»')
    php("q(\"UPDATE ligas SET modulos_quitados = '' WHERE id = " + LID + "\");")
    php('cambiar_modulos_cuenta(' + L + ', [], ["' + APAGAR + '"], []);')
php('cambiar_modulos_cuenta(' + L + ', ["' + APAGAR + '"], [], []);')
r = s.get(B + 'admin.php?p=' + pag, allow_redirects=False)
chk(r.status_code == 200, 'encendido otra vez: la página abre (%s %s)' % (r.status_code, r.headers.get('Location')))
chk(php("echo (int)valor('SELECT COUNT(*) FROM usuarios WHERE liga_id = " + LID + "') + (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = " + LID + "') + (int)valor('SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = " + LID + "');") == filas_antes,
    'apagar y encender no borra ni cambia datos')

# ---------- 5) Mi plan y pedido ----------
mp = s.get(B + 'admin.php?p=mi_plan').text
chk('Mis módulos' in mp and 'Pedir este módulo' in mp and 'Encendidos' in mp and 'Puede agregar' in mp, 'Mi plan: módulos encendidos y para agregar con «Pedir este módulo»')
pedir = next(k for k in catT if k not in pj('modulos_cliente(' + L + ')')['activos'])
r = postear(s, 'admin.php?p=mi_plan', {'accion': 'pedir_modulo', 'modulo': pedir})
pid = php("echo (int)valor(\"SELECT id FROM modulos_pedidos WHERE liga_id = " + LID + " AND encender = '" + pedir + "' AND estado = 'pendiente'\");")
chk(r.status_code in (302, 303) and pid != '0', 'Mi plan: «Pedir este módulo» crea el pedido')
r = postear(s, 'admin.php?p=mi_plan', {'accion': 'pedir_modulo', 'modulo': pedir})
chk('Ya pidió eso' in s.get(B + 'admin.php?p=mi_plan').text, 'Mi plan: el mismo pedido dos veces → avisa que ya está pedido')
chk('Pedido, por revisar' in s.get(B + 'admin.php?p=mi_plan').text, 'Mi plan: el módulo pedido dice «Pedido, por revisar»')
su = entrar('super@prueba.com')
ed = su.get(B + 'admin.php?p=ligas&editar=' + LID).text
chk('Pedidos de módulos' in ed and 'Módulos de ' in ed and 'class="interruptor"' in ed, 'súper: Ligas → Editar muestra los pedidos y los interruptores')
r = postear(su, 'admin.php?p=ligas&editar=' + LID, {'accion': 'pedido_modulos_aprobar', 'id': LID, 'pedido': pid})
chk(php("echo valor('SELECT estado FROM modulos_pedidos WHERE id = " + pid + "');") == 'aprobada' and php('echo modulo_activo(' + L + ', "' + pedir + '") ? 1 : 0;') == '1', 'súper: aprobar el pedido enciende el módulo')
# interruptores: dejar solo los del plan (apaga el pedido) con precio acordado
act = pj('modulos_cliente(' + L + ')')
datos = {'accion': 'modulos_cuenta', 'id': LID, 'precio_acordado': '9,90', 'motivo': 'prueba'}
for k in act['del_plan']:
    datos['mod[' + k + ']'] = '1'
r = postear(su, 'admin.php?p=ligas&editar=' + LID, datos)
mc2 = pj('modulos_cliente(' + L + ')')
chk(set(mc2['activos']) == set(act['del_plan']) and mc2['precio_acordado'] == 9.9, 'súper: los interruptores guardan lo encendido y el precio acordado')
php("q(\"UPDATE ligas SET precio_acordado = NULL WHERE id = " + LID + "\");")
chk('Mis módulos' in s.get(B + 'admin.php?p=mi_plan').text, 'Mi plan sigue abriendo')

# ---------- 6) Súper: catálogo de funciones y pedidos ----------
fp = su.get(B + 'admin.php?p=modulos&s=funciones').text
chk('Funciones que se encienden y apagan' in fp and 'name="precio[' + EXTRA + ']"' in fp, 'súper: catálogo de funciones con precios')
datos = {'accion': 'catalogo'}
for k, x in cat.items():
    datos['precio[' + k + ']'] = str(x['precio_mensual'])
    datos['grupo[' + k + ']'] = x['grupo']
    datos['orden[' + k + ']'] = str(x['orden'])
datos['precio[' + EXTRA + ']'] = '6.75'
postear(su, 'admin.php?p=modulos&s=funciones', datos)
chk(pj('catalogo_modulos()')[EXTRA]['precio_mensual'] == 6.75, 'súper: cambiar el precio de un módulo en el catálogo')
chk('Pedidos de módulos' in su.get(B + 'admin.php?p=modulos&s=pedidos').text, 'súper: pestaña de pedidos de módulos')
php('q("DELETE FROM modulos_catalogo");')

# ---------- 7) Sin prueba gratis: todo se pide demo ----------
r = requests.get(B + 'index.php', params={'v': 'registro', 'tipo': 'escuela', 'ref': 'ABC'}, allow_redirects=False)
chk(r.status_code == 301 and 'v=solicitar' in r.headers.get('Location', '') and 'tipo=escuela' in r.headers.get('Location', '') and 'ref=ABC' in r.headers.get('Location', ''), 'registro abierto: ya no existe, lleva a pedir la demo')
r = requests.post(B + 'index.php?v=registro', data={'nombre': 'X'}, allow_redirects=False)
chk(r.status_code == 301, 'registro abierto: un POST viejo tampoco crea cuentas')
chk(not os.path.exists(os.path.join(REPO, 'app', 'publico', 'registro.php')) and php('echo function_exists("prueba_automatica") ? 1 : 0;') == '0', 'registro abierto: el código se quitó')
GRATIS = re.compile(r'(prueba gratis|demo gratis|gratis por \d|días gratis|probar gratis|empezar gratis|pruébelo gratis|crear mi \w+ gratis|meses? gratis|dominio (propio )?gratis|free trial|free demo|free month|free \d+-day)', re.I)
for url in ['index.php', 'escuelas', 'index.php?v=solicitar', 'comparar', 'manual', 'index.php?v=demo', 'admin.php?p=login', 'index.php?lang=en', 'index.php?v=solicitar&lang=en',
            'index.php?lang=pt', 'index.php?lang=it', 'index.php?lang=fr']:
    t = requests.get(B + url).text
    m = GRATIS.search(t)
    chk(not m, 'sin «gratis» en ' + url + (' → ' + t[max(0, m.start() - 60):m.end() + 30] if m else ''))
pl1 = php("$p = fila('SELECT * FROM planes WHERE id = " + PL + "'); echo rawurlencode($p['slug'] !== '' ? $p['slug'] : (string)$p['id']);")
t = requests.get(B + 'planes/' + pl1).text
if 'Solicitar' not in t:
    t = requests.get(B + 'index.php', params={'v': 'plan', 'plan': pl1}).text
chk(not GRATIS.search(t) and ('Solicitar' in t or 'demo' in t), 'folleto del plan: sin «gratis», con «Solicitar demo»')
sol = requests.get(B + 'index.php?v=solicitar').text
chk('Solicite su demo' in sol, 'solicitar demo: es la única puerta de entrada')
aj = su.get(B + 'admin.php?p=ajustes').text
chk('registro_abierto' not in aj and 'prueba gratis automática' not in aj and 'No hay prueba gratis' in aj, 'ajustes: ya no se puede abrir la prueba gratis')
mp = s.get(B + 'admin.php?p=mi_plan').text
chk(not re.search(r'meses? gratis|dominio gratis', mp, re.I), 'Mi plan: sin «meses gratis» ni «dominio gratis»')
ref = s.get(B + 'admin.php?p=referidos').text
chk(not re.search(r'meses? gratis|demo gratis', ref, re.I), 'Recomiende y gane: sin «meses gratis»')

# ---------- php -S sin errores ----------
for log in [os.path.join(DIR, 'web.log'), os.path.join(REPO, 'pruebas', 'v38', 'web.log')]:
    if os.path.exists(log) and os.path.getmtime(log) > __import__('time').time() - 3600:
        t = open(log, errors='replace').read()
        m = ERRORES.search(t)
        chk(not m, 'php -S sin errores de PHP (' + os.path.relpath(log, REPO) + ')')
        if m:
            print('   →', t[max(0, m.start() - 200):m.end() + 400])
        break
php("q(\"DELETE FROM ligas WHERE slug = 'demo-mods-x'\");")
print()
print('Resultado: %d/%d bien' % (ok, ok + len(fallos)))
if fallos:
    print('Fallaron:', *fallos, sep='\n - ')
sys.exit(1 if fallos else 0)
