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
FUERA = pj('modulos_fuera_de_planes()')
chk(all(isinstance(x['precio_mensual'], (int, float)) and (x['precio_mensual'] > 0 or x['id'] in FUERA) and x['grupo'] in pj('grupos_modulos()') and all(d in cat for d in x['depende_de']) for x in cat.values()),
    'catálogo: precio, grupo y dependencias válidas en cada módulo (el Asistente IA: precio 0, por cotización)')
chk(FUERA == ['asistente_ia'] and cat.get('asistente_ia', {}).get('grupo') == 'extras' and 'asistente_ia' not in pj('modulos_plan(null)'), 'catálogo: «Asistente IA» en Extras y fuera de los planes')
chk(all(k not in pj('dependencias_modulo("' + k + '", catalogo_modulos())') for k in cat), 'catálogo: sin círculos de dependencias')
r = php('try { guardar_catalogo_modulos([["id" => "reservas", "depende_de" => ["finanzas"]], ["id" => "finanzas", "depende_de" => ["reservas"]]], [], "prueba"); echo "guardo"; } catch (RuntimeException $e) { echo "no: ", $e->getMessage(); }')
chk(r.startswith('no:') and 'círculo' in r, 'catálogo: no deja guardar dependencias en círculo') if 'reservas' in cat else chk(True, 'catálogo: (sin reservas)')
php('q("DELETE FROM modulos_catalogo");')

# ---------- 3) Cuentas de los módulos ----------
# (28 oct 2026) las secciones 3 a 8 usan el Básico como antes de los planes de $20 y $45 (con sus módulos y cada extra a su precio
# del catálogo); los planes de $20 y $45 se prueban en la sección 9
BASICO_VIEJO = "UPDATE planes SET modulos = 'carnets,esc_familias,avisos,acad_niveles', modulos_elegir = 0, precio_modulo_extra = NULL WHERE slug = 'academia-basico'"
if ACADEMIA:
    php('q("' + BASICO_VIEJO + '");')
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
chk(set(pj('modulos_cliente(' + D + ')')['activos']) == set(catT) - {'asistente_ia'} and pj('modulos_cliente(' + D + ')')['todos_en_demo'] is True, 'demo: tiene todos los módulos (menos el Asistente IA, que se cotiza)')
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
chk('Mis módulos' in mp and 'Solicitar este módulo' in mp and 'Pedir cotización' in mp and 'Encendidos' in mp and 'Puede agregar' in mp, 'Mi plan: módulos encendidos y para agregar con «Pedir cotización» / «Solicitar este módulo»')
pedir = next(k for k in catT if k not in pj('modulos_cliente(' + L + ')')['activos'])
r = postear(s, 'admin.php?p=mi_plan', {'accion': 'pedir_modulo', 'modulo': pedir})
pid = php("echo (int)valor(\"SELECT id FROM modulos_pedidos WHERE liga_id = " + LID + " AND encender = '" + pedir + "' AND estado = 'pendiente'\");")
chk(r.status_code in (302, 303) and pid != '0', 'Mi plan: «Solicitar este módulo» crea el pedido (cambio_modulos, llega al Panel Tukán Central)')
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
chk('registro_abierto' not in aj and 'prueba gratis automática' not in aj and 'No hay registro abierto' in aj, 'ajustes: ya no se puede abrir la prueba gratis')
mp = s.get(B + 'admin.php?p=mi_plan').text
chk(not re.search(r'meses? gratis|dominio gratis', mp, re.I), 'Mi plan: sin «meses gratis» ni «dominio gratis»')
ref = s.get(B + 'admin.php?p=referidos').text
chk(not re.search(r'meses? gratis|demo gratis', ref, re.I), 'Recomiende y gane: sin «meses gratis»')

# ---------- 8) Regla del dueño (20 oct 2026): los precios del software solo los ve Tukán; nunca «gratis» ----------
def visible(html):
    html = re.sub(r'(?is)<(script|style)\b.*?</\1>', ' ', html)
    return re.sub(r'<[^>]+>', ' ', html)


PRECIO = re.compile(r'\$\s?\d[\d.,]*')
PALABRA_GRATIS = re.compile(r'gratis|gratuit|\bfree\b', re.I)
mp = s.get(B + 'admin.php?p=mi_plan').text
v = visible(mp)
propios = pj('(function () { $l = ' + L + '; $r = [dinero(modulos_cliente($l)["mensual"])]; foreach (opciones_precio(plan_de_liga($l), $l) as $o) $r[] = dinero($o["precio"]); return $r; })()')
ajenos = sorted(set(PRECIO.findall(v)) - set(propios))
chk(propios[0] in v, 'Mi plan: muestra lo que ya paga por su plan (%s)' % propios[0])
chk(not ajenos, 'Mi plan: ningún precio de otros planes, módulos ni deportes (%s)' % ajenos)
chk(not any(dinero in v for dinero in pj('array_map("dinero", array_values(array_filter(array_column(catalogo_modulos("' + T + '"), "precio_mensual"))))')
            if dinero not in propios), 'Mi plan: ningún precio del catálogo de módulos')
chk('data-precio' not in mp and 'Por cotización' in v, 'Mi plan: sin precios escondidos (data-precio); lo que no tiene es «Por cotización»')
chk(not PALABRA_GRATIS.search(v), 'Mi plan: no dice «gratis»')
falta = pj('array_values(array_diff(array_keys(modulos_deporte(true)), deportes_liga(' + L + ')))')
if falta:
    r = postear(s, 'admin.php?p=mi_plan', {'accion': 'pedir_cotizacion', 'que': 'deporte:' + falta[0]})
    t = pj('fila("SELECT tipo, asunto FROM soporte_tickets WHERE liga_id = ' + LID + ' ORDER BY id DESC")')
    chk(t and t['tipo'] == 'pagos' and t['asunto'].startswith('Cotización: '), 'Mi plan: «Pedir cotización» de un deporte o disciplina crea el ticket «Pagos, plan o factura» (%s)' % t)
otro = php("echo (int)valor(\"SELECT id FROM planes WHERE activo = 1 AND tipo_cuenta IN ('ambos', '" + T + "') AND id <> " + PL + " AND precio_mensual > 0 ORDER BY id LIMIT 1\");")
if otro != '0':
    r = postear(s, 'admin.php?p=mi_plan', {'accion': 'pedir_cotizacion', 'que': 'plan:' + otro})
    chk(pj('valor("SELECT COUNT(*) FROM soporte_tickets WHERE liga_id = ' + LID + ' AND asunto LIKE \'Cotización: plan%\'")') == 1, 'Mi plan: «Pedir cotización» de otro plan crea el ticket')
    r = postear(s, 'admin.php?p=mi_plan', {'accion': 'comprobante', 'plan_meses': otro + ':1', 'forma_pago': 'transferencia', 'nota': ''})
    chk('pida su cotización' in (r.text + s.get(B + 'admin.php?p=mi_plan').text) and pj('valor("SELECT COUNT(*) FROM solicitudes_pago WHERE liga_id = ' + LID + ' AND plan_id = ' + otro + '")') == 0,
        'Mi plan: no puede contratar otro plan con un precio (se cotiza)')
chk('$' not in pj('texto_valor_renovacion(' + D + ')') and 'cotizamos' in pj('texto_valor_renovacion(' + D + ')'), 'aviso y correo de vencimiento de una demo: sin precio, «se lo cotizamos»')
chk(PRECIO.search(pj('texto_valor_renovacion(' + L + ')')), 'aviso de vencimiento de una cuenta pagada: el valor de su propio plan')
chk(PRECIO.search(visible(su.get(B + 'admin.php?p=planes').text)), 'el súper usuario sí ve los precios de los planes')
# palabra «gratis» o «free» en ninguna pantalla del cliente ni pública (español)
con = []
for ses, url in [(requests, 'index.php'), (requests, 'index.php?v=solicitar'), (requests, 'admin.php?p=login'), (requests, 'index.php?v=demo'), (requests, 'index.php?v=derechos'),
                 (requests, 'index.php?l=' + CUENTA['slug']), (s, 'admin.php'), (s, 'admin.php?p=mi_plan'), (s, 'admin.php?p=referidos'), (s, 'admin.php?p=soporte'),
                 (s, 'admin.php?p=seguridad'), (s, 'admin.php?p=polla'), (s, 'admin.php?p=entradas')]:
    if PALABRA_GRATIS.search(visible(ses.get(B + url).text)): con.append(url)
chk(not con, 'ninguna pantalla dice «gratis» ni «free» (%s)' % con)
# manuales del cliente (5 idiomas): sin precios del software ni «gratis»
malos = []
for f in sorted(os.listdir(os.path.join(REPO, 'app', 'manuales'))):
    t = open(os.path.join(REPO, 'app', 'manuales', f), encoding='utf-8').read()
    t = re.sub(r'(?i)free text|texte libre|texto livre|testo libero', '', t)
    if re.search(r'\$\s?\d+(\.\d+)?\s*(al mes|por \d+ meses|al año|per month|/mes|par mois|por mês|al mese)|Pedir este módulo|\d+\s?% (menos|less|de moins|in meno)', t) or PALABRA_GRATIS.search(t):
        malos.append(f)
chk(not malos, 'manuales del cliente sin precios del software ni «gratis» (%s)' % malos)
lg = visible(requests.get(B + 'admin.php?p=login').text)
if ACADEMIA:
    chk('dueño o la dueña de la academia, la secretaría y los profesores' in lg and 'árbitros' not in lg and 'delegados' not in lg, 'ingreso (academia): dueño, secretaría y profesores; sin árbitros ni delegados')
else:
    chk('profesores, árbitros y delegados' in lg and 'secretaría' not in lg, 'ingreso (ligas): el texto de TuVocalía sigue igual')

# ---------- 9) Planes de $20 y $45 (decisión de Dimitry, 28 oct 2026) ----------
chk(pj('modulos_fuera_del_cupo()') == ['asistente_ia'], 'cupo: el Asistente IA no cuenta entre los módulos a elegir')
if not ACADEMIA:
    chk(php("echo ajuste('planes_20_45');") == '' and pj('catalogo_modulos()')['asistente_ia']['precio_mensual'] == 0
        and php("echo (int)valor('SELECT COUNT(*) FROM planes WHERE modulos_elegir > 0');") == '0', '(ligas) los planes de $20 y $45 son solo de TuAcademia: en modo ligas nada cambia')
else:
    NUEVO = "UPDATE planes SET modulos = '-', modulos_elegir = 3, precio_modulo_extra = 5 WHERE slug = 'academia-basico'"
    php('q("' + NUEVO + '");')
    PB = php("echo (int)valor(\"SELECT id FROM planes WHERE slug = 'academia-basico'\");")
    PC = php("echo (int)valor(\"SELECT id FROM planes WHERE slug = 'academia-completo'\");")
    planes = {x['slug']: x for x in pj("filas(\"SELECT * FROM planes WHERE slug IN ('academia-basico', 'academia-completo')\")")}
    b, c = planes['academia-basico'], planes['academia-completo']
    chk([b['precio_mensual'], b['precio_semestral'], b['precio_anual'], b['precio_trimestral'], b['modulos_elegir'], b['precio_modulo_extra']] == [20, 100, 200, 0, 3, 5]
        and [c['precio_mensual'], c['precio_semestral'], c['precio_anual'], c['modulos'], c['modulos_elegir'], c['precio_modulo_extra']] == [45, 225, 450, '*,asistente_ia', 0, None],
        'planes: Básico $20/$100/$200 con 3 módulos a elegir y $5 cada uno más; Completo $45/$225/$450 con todo y el Asistente IA')
    chk(pj("valor(\"SELECT COUNT(*) FROM planes WHERE activo = 1 AND tipo_cuenta IN ('escuela', 'ambos')\")") == 2, 'planes: solo dos planes visibles (Básico y Completo)')
    chk(pj('catalogo_modulos("escuela")')['asistente_ia']['precio_mensual'] == 5, 'Asistente IA: $5 al mes (antes $0 por cotización)')
    chk(pj('modulos_plan(fila("SELECT * FROM planes WHERE id = ' + PB + '"))') == [], 'Básico: el plan no trae módulos (planes.modulos = «-»): el cliente los elige')
    # --- la migración, una sola vez, con cuentas que ya existían ---
    # (la marca se borra recién al correr la migración: si no, la primera conexión la corre sola antes de crear las cuentas)
    php("q(\"" + BASICO_VIEJO + "\"); q('UPDATE planes SET precio_mensual = 19, precio_semestral = 95, precio_anual = 190 WHERE id = " + PB + "');"
        "q('UPDATE planes SET precio_mensual = 29, precio_semestral = 145, precio_anual = 290 WHERE id = " + PC + "');"
        "q(\"INSERT OR REPLACE INTO modulos_catalogo (clave, precio_mensual, actualizado, por) VALUES ('asistente_ia', 0, '', 'prueba')\");")
    vieja = {}
    otro_barato = php("echo (int)valor(\"SELECT id FROM planes WHERE slug = 'escuela-basico'\");")
    otro_todo = php("echo (int)valor(\"SELECT id FROM planes WHERE slug = 'escuela-completo'\");")
    for n, pl, prueba, ex, qu, el in [('Mig Basico Paga', PB, 0, 'videos', '{"avisos":1.5}', 0), ('Mig Basico Demo', PB, 1, '', '', 0), ('Mig Plan Barato', otro_barato, 0, '', '', 0),
                                     ('Mig Plan Todo', otro_todo, 0, '', '', 0), ('Mig Completo', PC, 0, '', '', 0)]:
        vieja[n] = php("q(\"INSERT INTO ligas (nombre, slug, vence, es_prueba, activa, es_demo, tipo, plan_id, modulos_extra, modulos_quitados, modulos_elegidos) VALUES (?, ?, ?, ?, 1, 0, 'escuela', ?, ?, ?, ?)\", "
                       "[" + json.dumps(n) + ", 'mig-" + str(len(vieja)) + "-' . bin2hex(random_bytes(3)), date('Y-m-d', strtotime('+30 days')), " + str(prueba) + ", " + str(pl) + ", " + json.dumps(ex) + ", " + json.dumps(qu) + ", " + str(el) + "]); echo db()->lastInsertId();")
    php("q(\"DELETE FROM ajustes WHERE clave = 'planes_20_45'\"); planes_20_45(db());")
    LG = lambda i: pj('(function () { $l = fila("SELECT * FROM ligas WHERE id = ' + vieja[i] + '"); $m = modulos_cliente($l); return ["plan" => (string)$l["plan_id"], "extra" => $l["modulos_extra"], "quit" => $l["modulos_quitados"], "mensual" => $m["mensual"], "elegidos" => $m["elegidos"], "por_elegir" => $m["por_elegir"]]; })()')
    x = LG('Mig Basico Paga')
    chk(x['plan'] == PB and set(x['extra'].split(',')) == {'carnets', 'esc_familias', 'acad_niveles', 'videos'} and x['quit'] == '' and len(x['elegidos']) == 3 and x['mensual'] == 25,
        'migración: quien pagaba el Básico conserva sus módulos (menos el que se le quitó) como extras: 3 elegidos + 1 a $5 = $25 ' + str(x))
    x = LG('Mig Basico Demo')
    chk(x['plan'] == PB and x['extra'] == '' and x['por_elegir'] == 3, 'migración: una demo con todo encendido no recibe extras ' + str(x))
    x = LG('Mig Plan Barato')
    chk(x['plan'] == PB and len(x['extra'].split(',')) == 4 and x['mensual'] == 25, 'migración: una cuenta en un plan más barato pasa al Básico con sus módulos como extras ' + str(x))
    x = LG('Mig Plan Todo')
    chk(x['plan'] == PC and x['mensual'] == 45, 'migración: una cuenta en un plan con todo (o intermedio) pasa al Completo ' + str(x))
    x = LG('Mig Completo')
    chk(x['plan'] == PC and x['mensual'] == 45, 'migración: el Completo queda en $45 ' + str(x))
    chk(pj('catalogo_modulos("escuela")')['asistente_ia']['precio_mensual'] == 5 and php("echo ajuste('planes_20_45') !== '' || valor(\"SELECT valor FROM ajustes WHERE clave = 'planes_20_45'\") ? 1 : 0;") == '1',
        'migración: el Asistente IA pasa a $5 y queda la marca «planes_20_45»')
    php("q('UPDATE planes SET precio_mensual = 22 WHERE id = " + PB + "'); planes_20_45(db());")
    chk(php("echo valor('SELECT precio_mensual FROM planes WHERE id = " + PB + "');") == '22' and set(LG('Mig Basico Paga')['extra'].split(',')) == {'carnets', 'esc_familias', 'acad_niveles', 'videos'},
        'migración: una sola vez (no pisa lo que cambie Dimitry ni duplica extras)')
    php("q('UPDATE planes SET precio_mensual = 20 WHERE id = " + PB + "'); q(\"DELETE FROM ligas WHERE slug LIKE 'mig-%'\");")
    # --- el precio con módulos a elegir ---
    php("q(\"UPDATE ligas SET plan_id = " + PB + ", es_prueba = 0, vence = ?, modulos_extra = '', modulos_quitados = '', precio_acordado = NULL, modulos_elegidos = 1, deportes = '*' WHERE id = " + LID + "\", [date('Y-m-d', strtotime('+60 days'))]);")
    regulares = [k for k in catT if k != 'asistente_ia' and not catT[k]['depende_de']]
    def poner(extra, deportes='*'):
        php("q('UPDATE ligas SET modulos_extra = ?, deportes = ? WHERE id = " + LID + "', [" + json.dumps(','.join(extra)) + ", " + json.dumps(deportes) + "]);")
        return pj('modulos_cliente(' + L + ')')
    m9 = poner([])
    chk([m9['mensual'], m9['por_elegir'], m9['elegidos']] == [20, 3, []], 'Básico sin módulos: $20 y le quedan 3 por elegir')
    m9 = poner(regulares[:3])
    chk([m9['mensual'], m9['por_elegir'], len(m9['elegidos'])] == [20, 0, 3], 'Básico con 3 módulos elegidos (cualquiera, sin importar su precio del catálogo): sigue en $20')
    m9 = poner(regulares[:5])
    chk(m9['mensual'] == 30 and sorted(m9['precio_por_extra'].values()) == [0, 0, 0, 5, 5], 'Básico con 5 módulos: $20 + 2 × $5 = $30')
    m9 = poner(regulares[:1] + ['asistente_ia'])
    chk([m9['mensual'], m9['por_elegir'], m9['precio_por_extra'].get('asistente_ia')] == [25, 2, 5], 'Asistente IA en el Básico: $5 aparte y no gasta uno de los 3')
    disc = pj('array_keys(modulos_deporte(true))')[:2]
    m9 = poner(regulares[:1], ','.join(disc))
    dep_extra = pj('precio_modulos(' + json.dumps(disc).replace('[', 'array(').replace(']', ')') + ')')['extra']
    chk(len(disc) < 2 or ([m9['precio_deportes'], m9['mensual'], m9['por_elegir']] == [dep_extra, round(20 + dep_extra, 2), 2] and dep_extra > 0),
        'disciplinas adicionales: se cobran como siempre y aparte (no gastan los módulos a elegir) ' + str([m9['precio_deportes'], m9['mensual']]))
    m9 = poner(regulares[:8])
    chk(m9['mensual'] == 45, 'Básico con 8 módulos llega a $45, lo mismo que el Completo')
    ops = pj('opciones_precio(plan_de_liga(' + L + '), ' + L + ')')
    chk([ops['mensual']['precio'], ops['semestral']['precio'], ops['anual']['precio']] == [45, 225, 450], 'opciones_precio (renovar): 5 módulos más × $5 con la misma rebaja del período ' + str([o['precio'] for o in ops.values()]))
    php("q('UPDATE ligas SET plan_id = " + PC + " WHERE id = " + LID + "');")
    m9 = pj('modulos_cliente(' + L + ')')
    chk(m9['mensual'] == 45 and 'asistente_ia' in m9['activos'] and m9['extra'] == [] and m9['modulos_elegir'] == 0, 'Completo: $45 con todos los módulos y el Asistente IA')
    php("q('UPDATE ligas SET plan_id = " + PB + " WHERE id = " + LID + "');")
    # --- Mi plan (cliente) ---
    poner(regulares[:4] + ['asistente_ia'])
    mp = s.get(B + 'admin.php?p=mi_plan').text
    v = re.sub(r'\s+', ' ', visible(mp))
    chk('Su plan trae 3 módulos a su elección' in v and 'y ya los eligió' in v and v.count('Elegido · Incluido') == 3, 'Mi plan: «Su plan trae 3 módulos a su elección» y los elegidos dicen «Incluido»')
    chk('Extra · $5.00 al mes' in v and re.search(r'data-total-mensual>\$30.00', mp), 'Mi plan: el cuarto módulo y el Asistente IA, $5 cada uno; total $30')
    poner(regulares[:1])
    mp = s.get(B + 'admin.php?p=mi_plan').text
    v = re.sub(r'\s+', ' ', visible(mp))
    chk('data-por-elegir="2"' in mp and 'le quedan 2 por elegir' in v and 'Puede elegirlo en su plan' in v and 'Elegir este módulo' in v, 'Mi plan: con 1 elegido le quedan 2 y los demás dicen «Puede elegirlo en su plan»')
    agregar = v[v.index('Puede agregar'):v.index('Lo que paga hoy')] if 'Puede agregar' in v and 'Lo que paga hoy' in v else '$'
    chk('$' not in agregar, 'Mi plan: ningún precio de lo que NO tiene (regla de Tukán)')
    chk(re.search(r'Asistente IA.{0,400}?Pedir cotización', agregar, re.S), 'Mi plan: el Asistente IA no se elige entre los 3 (se cotiza)')
    elegir = regulares[1]
    r = postear(s, 'admin.php?p=mi_plan', {'accion': 'pedir_modulo', 'modulo': elegir})
    chk(php("echo (int)valor(\"SELECT COUNT(*) FROM modulos_pedidos WHERE liga_id = " + LID + " AND encender = '" + elegir + "' AND estado = 'pendiente'\");") == '1', 'Mi plan: «Elegir este módulo» crea el pedido para Tukán')
    php("q(\"DELETE FROM modulos_pedidos WHERE liga_id = " + LID + " AND estado = 'pendiente'\");")
    # --- súper: Ligas → Editar y Planes y precios ---
    ed = su.get(B + 'admin.php?p=ligas&editar=' + LID).text
    chk('elegido · incluido' in ed and 'entra en los 3 a elegir' in ed and '3 módulos a elección del cliente' in ed, 'súper: Ligas → Editar dice los elegidos y los que entran en los 3')
    pg = su.get(B + 'admin.php?p=planes&editar=' + PB).text
    chk('name="modulos_elegir"' in pg and 'name="precio_modulo_extra"' in pg and 'Módulos que elige el cliente' in pg and 'Cada módulo más' in pg, 'súper: Planes y precios con «Módulos que elige el cliente» y «Cada módulo más»')
    pl = pj('fila("SELECT * FROM planes WHERE id = ' + PB + '")')
    datos = {'accion': 'guardar', 'id': PB, 'nombre': pl['nombre'], 'slug': pl['slug'], 'tipo_cuenta': pl['tipo_cuenta'], 'eslogan': pl['eslogan'], 'precio_mensual': '20', 'precio_trimestral': '0',
             'precio_semestral': '100', 'precio_anual': '200', 'max_campeonatos': '0', 'max_equipos_torneo': '0', 'max_equipos': '0', 'max_alumnos': '0', 'descripcion': pl['descripcion'],
             'orden': str(pl['orden']), 'activo': '1', 'dominio_anual': '1', 'modulos_elegir': '4', 'precio_modulo_extra': '4'}
    postear(su, 'admin.php?p=planes&editar=' + PB, datos)
    pl2 = pj('fila("SELECT * FROM planes WHERE id = ' + PB + '")')
    chk([pl2['modulos_elegir'], pl2['precio_modulo_extra'], pl2['modulos']] == [4, 4, '-'], 'súper: guardar 4 módulos a elegir a $4 (sin módulos marcados queda «-», no «todos») ' + str([pl2['modulos_elegir'], pl2['precio_modulo_extra'], pl2['modulos']]))
    datos.update({'modulos_elegir': '3', 'precio_modulo_extra': ''})
    postear(su, 'admin.php?p=planes&editar=' + PB, datos)
    chk(pj('fila("SELECT * FROM planes WHERE id = ' + PB + '")')['precio_modulo_extra'] is None, 'súper: «Cada módulo más» vacío = el precio del catálogo')
    php('q("' + NUEVO + '");')
    # --- páginas públicas: sin precios, con los módulos a elegir ---
    t = visible(requests.get(B + 'index.php').text)
    chk('3 módulos a su elección' in t and not PRECIO.search(t), 'portada: «3 módulos a su elección» en el Básico y sin precios')
    t = visible(requests.get(B + 'comparar').text)
    chk('Módulos a su elección' in t and not PRECIO.search(t), 'comparar: fila «Módulos a su elección» y sin precios')
    php("q(\"UPDATE ligas SET modulos_extra = '', deportes = '*', modulos_elegidos = 0 WHERE id = " + LID + "\");")

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
