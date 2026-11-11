#!/usr/bin/env python3
# SOLO PRUEBAS. Demos automáticas de 3 días (10 nov 2026, app/demos_auto.php) — la parte del programa:
#   formulario «Solicitar demo» con 6 datos obligatorios (un solo teléfono: el celular) (rechazo campo por campo, correo malo, conserva lo escrito),
#   pedido guardado con las claves del contrato (celular y telefono iguales) y visible en «solicitudes» / «solicitud» del conector v2,
#   aviso al instante a un Panel SIMULADO (token v2 tipo «demo» bien firmado) y, si el Panel no contesta, el pedido se guarda igual,
#   /manual.pdf y /primeros_pasos.pdf públicos, tarjeta de la demo solo en cuentas demo, botones en Ayuda, demo de 3 días.
# Sirve para TuVocalía y TuAcademia (mismo código).
# Uso (TuVocalía):  TA_PUERTO=8221 bash pruebas/tukan_central/reiniciar.sh && TA_PUERTO=8221 python3 pruebas/demos_auto/prueba_demos_auto.py
# Uso (TuAcademia): bash pruebas/v38/reiniciar.sh && python3 pruebas/demos_auto/prueba_demos_auto.py
# Usa también TA_PUERTO+7 (Panel simulado) y TA_PUERTO+8 (Panel que no contesta).
import base64, hashlib, hmac, json, os, re, secrets, socket, subprocess, sys, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import requests

PUERTO = int(os.environ.get('TA_PUERTO', '8196'))
B = 'http://127.0.0.1:%d/' % PUERTO
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ENV = os.path.join(REPO, 'data', 'tukan_central.env')
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
SECRETO = secrets.token_hex(32)
ok, fallos = 0, []


def chk(cond, nombre, extra=''):
    global ok
    if cond:
        ok += 1
        print('  OK   ', nombre)
    else:
        fallos.append(nombre)
        print('  FALLA', nombre, extra)


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout[-800:], r.stderr[-800:])
    return r.stdout.strip()


def pj(codigo):
    return json.loads(php('echo json_encode(' + codigo + ', JSON_UNESCAPED_UNICODE);') or 'null')


def b64(b):
    return base64.urlsafe_b64encode(b).decode().rstrip('=')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def ip():
    return '10.%d.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250), 1 + secrets.randbelow(250))


PRODUCTO = php('echo tc_producto()[0];')
ACADEMIA = php('echo tc_producto()[2] ? "1" : "0";') == '1'
INST = PRODUCTO
NOMBRE_PDF = 'TuAcademia' if ACADEMIA else 'TuVocalia'
print('Producto:', PRODUCTO, '· puerto', PUERTO)

# ---------- Panel SIMULADO: revisa la firma como el Panel y guarda cada aviso ----------
AVISOS = []
MODO = {'demora': 0}


class Panel(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_POST(self):
        cuerpo = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        if MODO['demora']:
            time.sleep(MODO['demora'])
        tok = self.headers.get('X-Tukan-Token', '')
        partes = tok.split('.')
        bien = len(partes) == 2 and hmac.compare_digest(b64(hmac.new(SECRETO.encode(), partes[0].encode(), hashlib.sha256).digest()), partes[1])
        p = {}
        if bien:
            p = json.loads(base64.urlsafe_b64decode(partes[0] + '=' * (-len(partes[0]) % 4)))
        AVISOS.append({'ruta': self.path, 'firma': bien, 'p': p, 'cuerpo': cuerpo, 'tipo_c': self.headers.get('Content-Type', '')})
        try:
            self.send_response(202 if bien else 401)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(b'{"ok":true}' if bien else b'{"ok":false}')
        except Exception:
            pass


srv = ThreadingHTTPServer(('127.0.0.1', PUERTO + 7), Panel)
threading.Thread(target=srv.serve_forever, daemon=True).start()

# Panel que acepta la conexión y nunca contesta
mudo = socket.socket()
mudo.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
mudo.bind(('127.0.0.1', PUERTO + 8))
mudo.listen(20)


def conectar(url):
    with open(ENV, 'w') as f:
        f.write('TUKAN_CENTRAL_ID=%s\nTUKAN_CENTRAL_SECRET=%s\nTUKAN_CENTRAL_URL=%s\n' % (INST, SECRETO, url))


conectar('http://127.0.0.1:%d' % (PUERTO + 7))

# ---------- Formulario ----------
CAMPOS = {'contacto': 'Ana Pérez', 'organizacion': 'Academia Los Pinos' if ACADEMIA else 'Liga Barrial Los Pinos',
          'celular': '099 123 4567', 'direccion': 'Av. Amazonas N24-10 y Colón', 'ciudad': 'Quito', 'email': '  Ana.Perez@Ejemplo.COM '}
s = requests.Session()
s.trust_env = False
r = s.get(B + 'index.php?v=solicitar')
chk(r.status_code == 200, 'el formulario abre')
for campo in CAMPOS:
    chk(re.search(r'<input[^>]*name="%s"[^>]*required' % campo, r.text) is not None, 'el campo «%s» es obligatorio en el navegador (required)' % campo)
chk('Le enviaremos la clave de su demo a este correo: escríbalo bien.' in r.text, 'el formulario dice que la clave llega al correo y que lo escriba bien')
chk('La demo dura 3 días' in r.text and 'Demo de 3 días' in r.text, 'el formulario dice que la demo dura 3 días')
chk(re.search(r'name="acepto" value="1" required', r.text) is not None, 'la casilla de la política de privacidad sigue obligatoria')
chk('name="tamano"' in r.text and 'name="mensaje"' in r.text and 'name="ref"' in r.text, 'los campos propios (tamaño, mensaje, vendedor) siguen')
chk(pj('(int)ajuste("dias_demo")') == 3 and pj('dias_demo()') == 3, 'el ajuste dias_demo quedó en 3 (migración de una sola vez)')
php('q("UPDATE ajustes SET valor = \'10\' WHERE clave = \'dias_demo\'"); q("DELETE FROM ajustes WHERE clave = \'demos_auto_3dias\'"); esquema_demos_auto(db());')
chk(pj('(int)valor("SELECT valor FROM ajustes WHERE clave = \'dias_demo\'")') == 10, 'la migración no pisa un valor que Tukán ya cambió (10 queda 10)')
php('q("UPDATE ajustes SET valor = \'7\' WHERE clave = \'dias_demo\'"); q("DELETE FROM ajustes WHERE clave = \'demos_auto_3dias\'"); esquema_demos_auto(db());')
chk(pj('(int)valor("SELECT valor FROM ajustes WHERE clave = \'dias_demo\'")') == 3, 'la migración pasa el 7 de siempre a 3')
php('q("UPDATE ajustes SET valor = \'7\' WHERE clave = \'dias_demo\'"); esquema_demos_auto(db());')
chk(pj('(int)valor("SELECT valor FROM ajustes WHERE clave = \'dias_demo\'")') == 7, 'la migración es de una sola vez (si después ponen 7, queda 7)')
php('q("UPDATE ajustes SET valor = \'3\' WHERE clave = \'dias_demo\'");')
# «3 días en todo» (Dimitry, 10 nov 2026): también dias_prueba, con migración de una sola vez que respeta lo que Tukán cambió
chk(pj('(int)ajuste("dias_prueba")') == 3, 'el ajuste dias_prueba quedó en 3 (antes 30 en TuVocalía y 7 en TuAcademia)')
for viejo, esperado in (('30', 3), ('7', 3), ('15', 15)):
    php('q("UPDATE ajustes SET valor = \'%s\' WHERE clave = \'dias_prueba\'"); q("DELETE FROM ajustes WHERE clave = \'demos_auto_3dias\'"); esquema_demos_auto(db());' % viejo)
    chk(pj('(int)valor("SELECT valor FROM ajustes WHERE clave = \'dias_prueba\'")') == esperado, 'dias_prueba %s → %d' % (viejo, esperado))
php('q("UPDATE ajustes SET valor = \'3\' WHERE clave IN (\'dias_prueba\', \'dias_demo\')");')

cuenta = lambda: int(php('echo (int)valor("SELECT COUNT(*) FROM solicitudes_demo");') or 0)


def enviar(datos, sesion=None):
    ses = sesion or s
    r0 = ses.get(B + 'index.php?v=solicitar')
    d = {'csrf': csrf(r0.text), 'sitio': '', 'tipo': 'escuela' if ACADEMIA else 'liga', 'tamano': '80 alumnos', 'mensaje': 'Quiero probar', 'acepto': '1'}
    d.update(datos)
    t = time.time()
    r1 = ses.post(B + 'index.php?v=solicitar', data=d, headers={'CF-Connecting-IP': ip()}, timeout=30)
    return r1, time.time() - t


antes = cuenta()
r, _ = enviar({k: '' for k in CAMPOS} | {'acepto': ''})
chk(cuenta() == antes, 'formulario vacío: no se guarda nada')
for campo in list(CAMPOS) + ['acepto']:
    chk('id="error-%s"' % campo in r.text, 'formulario vacío: mensaje junto al campo «%s»' % campo)
chk('Revise los datos marcados en rojo' in r.text, 'formulario vacío: aviso general arriba')

for campo in CAMPOS:
    datos = dict(CAMPOS)
    datos[campo] = '   '
    r, _ = enviar(datos)
    errs = re.findall(r'id="error-([a-z_]+)"', r.text)
    chk(cuenta() == antes and errs == [campo], 'falta «%s»: se rechaza solo por ese campo' % campo, str(errs))
    otro = 'organizacion' if campo != 'organizacion' else 'contacto'
    chk('value="%s"' % CAMPOS[otro] in r.text, 'falta «%s»: conserva lo escrito en los demás campos' % campo)

for malo in ['ana@', 'ana arroba ejemplo.com', 'ana@ejemplo', '@ejemplo.com']:
    r, _ = enviar(CAMPOS | {'email': malo})
    chk(cuenta() == antes and 'id="error-email"' in r.text and 'El correo no es válido' in r.text, 'correo malo «%s»: rechazado' % malo)
for malo in ['12345', '0812345678', '09123', '+59381234567', 'celular']:
    r, _ = enviar(CAMPOS | {'celular': malo})
    chk(cuenta() == antes and re.findall(r'id="error-([a-z_]+)"', r.text) == ['celular'], 'celular malo «%s»: rechazado' % malo)
chk('name="telefono"' not in r.text and 'id="f-telefono"' not in r.text and 'Teléfono de contacto' not in r.text and 'Celular / WhatsApp *' in r.text,
    'el formulario pide UN solo teléfono: «Celular / WhatsApp» (ya no hay teléfono fijo)')
r, _ = enviar(CAMPOS | {'acepto': ''})
chk(cuenta() == antes and re.findall(r'id="error-([a-z_]+)"', r.text) == ['acepto'], 'sin aceptar la política de privacidad: rechazado')

# ---------- Pedido completo + aviso al instante ----------
AVISOS.clear()
r, seg = enviar(CAMPOS)
chk(cuenta() == antes + 1, 'pedido completo: se guarda')
sid = int(php('echo (int)valor("SELECT MAX(id) FROM solicitudes_demo");'))
fila = pj('fila("SELECT * FROM solicitudes_demo WHERE id = %d")' % sid)
chk(fila['contacto'] == 'Ana Pérez' and fila['organizacion'] == CAMPOS['organizacion'] and 'telefono_fijo' not in fila and fila['telefono'] == '0991234567'
    and fila['direccion'] == CAMPOS['direccion'] and fila['ciudad'] == 'Quito' and fila['email'] == 'ana.perez@ejemplo.com', 'pedido completo: guarda los 6 datos (celular solo con números, correo en minúsculas y sin espacios)', str(fila))
chk('¡Listo! En unos minutos le llega al correo su clave, el manual y la guía de primeros pasos. Revise también la carpeta de spam.' in r.text, 'página de gracias con el texto del contrato')
chk(len(AVISOS) == 1, 'aviso al instante: llegó un aviso al Panel simulado', str(len(AVISOS)))
if AVISOS:
    a = AVISOS[0]
    p = a['p']
    chk(a['ruta'] == '/demo_aviso.php' and a['tipo_c'].startswith('application/json'), 'aviso: POST /demo_aviso.php con JSON')
    chk(a['firma'] and p.get('v') == 2 and p.get('tipo') == 'demo' and p.get('inst') == INST and p.get('rol') in ('dueno', 'soporte'), 'aviso: token v2 tipo «demo» firmado con el secreto del Panel', str(p))
    chk(isinstance(p.get('iat'), int) and isinstance(p.get('exp'), int) and 0 < p['exp'] - p['iat'] <= 60 and re.fullmatch(r'[0-9a-f]{32}', p.get('nonce', '')) is not None
        and '@' in p.get('email', '') and p.get('nombre'), 'aviso: token con iat, exp (máximo 60 s), nonce, email y nombre')
    chk(p.get('bh') == b64(hashlib.sha256(a['cuerpo']).digest()), 'aviso: bh = sha256 del cuerpo exacto')
    chk(json.loads(a['cuerpo']) == {'accion': 'demo_pedida', 'id': 'demo_pedida:%d' % sid}, 'aviso: cuerpo {"accion":"demo_pedida","id":"demo_pedida:<n>"}', a['cuerpo'][:200])
chk(fila['aviso_panel'].startswith('202 '), 'aviso: queda anotado (202) en la solicitud', fila['aviso_panel'])


# ---------- El conector v2: lo que lee el Panel ----------
def token2(tipo, cuerpo):
    ahora = int(time.time())
    pl = {'v': 2, 'tipo': tipo, 'inst': INST, 'email': 'dimitry@tukanec.com', 'nombre': 'Dimitry', 'rol': 'dueno', 'iat': ahora, 'exp': ahora + 60,
          'nonce': secrets.token_hex(16), 'bh': b64(hashlib.sha256(cuerpo).digest())}
    a0 = b64(json.dumps(pl, separators=(',', ':')).encode())
    return a0 + '.' + b64(hmac.new(SECRETO.encode(), a0.encode(), hashlib.sha256).digest())


def post2(accion, cuerpo):
    raw = json.dumps(cuerpo).encode()
    return requests.post(B + 'tukan_central.php', params={'accion': accion}, data=raw, timeout=30,
                         headers={'Content-Type': 'application/json', 'X-Tukan-Token': token2(accion, raw), 'CF-Connecting-IP': ip()})


ESPERADO = {'nombre': 'Ana Pérez', 'nombre_comercial': CAMPOS['organizacion'], 'telefono': '0991234567', 'celular': '0991234567',
            'direccion': CAMPOS['direccion'], 'ciudad': 'Quito', 'email': 'ana.perez@ejemplo.com'}
rs = post2('solicitudes', {'tipos': ['demo_pedida'], 'por_pagina': 200})
lista = {x['id']: x for x in rs.json().get('solicitudes', [])} if rs.status_code == 200 else {}
sol = lista.get('demo_pedida:%d' % sid, {})
det = sol.get('detalle', {})
chk(rs.status_code == 200 and sol.get('tipo') == 'demo_pedida', 'conector «solicitudes»: el pedido sale como demo_pedida', rs.text[:300])
chk({k: det.get(k) for k in ESPERADO} == ESPERADO, 'conector «solicitudes»: detalle con las claves exactas del contrato (telefono = celular)', str(det))
chk(det.get('tamano') == '80 alumnos' and det.get('mensaje') == 'Quiero probar' and det.get('tipo') == ('escuela' if ACADEMIA else 'liga'), 'conector: siguen los datos propios (tipo, tamaño, mensaje)')
r1 = post2('solicitud', {'id': 'demo_pedida:%d' % sid})
chk(r1.status_code == 200 and {k: r1.json().get('solicitud', {}).get('detalle', {}).get(k) for k in ESPERADO} == ESPERADO, 'conector «solicitud»: la misma solicitud con las mismas claves')
crear = next((b for b in sol.get('acciones_posibles', []) if b.get('accion') == 'crear_demo'), {})
dias = next((c for c in crear.get('campos', []) if c.get('campo') == 'dias'), {})
chk(dias.get('valor') == 3, 'conector: el botón «Crear demo» trae 3 días', str(dias))

# ---------- Si el Panel no contesta, el pedido igual se guarda ----------
conectar('http://127.0.0.1:%d' % (PUERTO + 8))
n0 = cuenta()
r, seg = enviar(CAMPOS | {'email': 'lento@ejemplo.com'})
chk(cuenta() == n0 + 1 and '¡Listo! En unos minutos' in r.text, 'Panel que no contesta: el pedido se guarda y sale la página de gracias')
chk(seg < 8, 'Panel que no contesta: espera como máximo unos 5 segundos (%.1f s)' % seg)
chk(pj('valor("SELECT aviso_panel FROM solicitudes_demo WHERE id = (SELECT MAX(id) FROM solicitudes_demo)")').startswith('sin respuesta'), 'Panel que no contesta: queda anotado «sin respuesta»')
libre = socket.socket(); libre.bind(('127.0.0.1', 0)); puerto_libre = libre.getsockname()[1]; libre.close()
conectar('http://127.0.0.1:%d' % puerto_libre)
n0 = cuenta()
r, seg = enviar(CAMPOS | {'email': 'apagado@ejemplo.com'})
chk(cuenta() == n0 + 1 and '¡Listo! En unos minutos' in r.text and seg < 6, 'Panel apagado: el pedido se guarda y sale la página de gracias al momento')
os.remove(ENV)
AVISOS.clear()
n0 = cuenta()
r, seg = enviar(CAMPOS | {'email': 'sinpanel@ejemplo.com'})
chk(cuenta() == n0 + 1 and '¡Listo! En unos minutos' in r.text and not AVISOS
    and pj('valor("SELECT aviso_panel FROM solicitudes_demo WHERE id = (SELECT MAX(id) FROM solicitudes_demo)")') == '', 'sin conectar al Panel: se guarda y no se avisa a nadie')
MODO['demora'] = 0

# ---------- Manual y primeros pasos (públicos, sin sesión) ----------
for ruta, nombre in [('manual.pdf', 'Manual_%s%s.pdf' % (NOMBRE_PDF, '' if ACADEMIA else '_Ligas')),
                     ('primeros_pasos.pdf', 'Primeros_Pasos_%s%s.pdf' % (NOMBRE_PDF, '' if ACADEMIA else '_Ligas'))]:
    rp = requests.get(B + ruta, timeout=30)
    chk(rp.status_code == 200 and rp.headers.get('Content-Type', '').startswith('application/pdf') and rp.content[:5] == b'%PDF-', '/%s: 200 application/pdf sin sesión' % ruta, '%s %s' % (rp.status_code, rp.headers.get('Content-Type')))
    chk(nombre in rp.headers.get('Content-Disposition', ''), '/%s: se descarga como %s' % (ruta, nombre), rp.headers.get('Content-Disposition', ''))
    chk('Set-Cookie' not in rp.headers, '/%s: no abre sesión' % ruta)
if not ACADEMIA:
    for ruta, tipo, nombre in [('manual.pdf', 'escuela', 'Manual_TuVocalia_Escuelas.pdf'), ('primeros_pasos.pdf', 'escuela', 'Primeros_Pasos_TuVocalia_Escuelas.pdf'),
                               ('manual.pdf', 'federacion', 'Manual_TuVocalia_Federaciones.pdf'), ('primeros_pasos.pdf', 'federacion', 'Primeros_Pasos_TuVocalia_Federaciones.pdf')]:
        rp = requests.get(B + ruta, params={'tipo': tipo}, timeout=30)
        chk(rp.status_code == 200 and rp.content[:5] == b'%PDF-' and nombre in rp.headers.get('Content-Disposition', ''), '/%s?tipo=%s: %s' % (ruta, tipo, nombre))
else:
    rp = requests.get(B + 'manual.pdf', params={'tipo': 'escuela'}, timeout=30)
    chk(rp.status_code == 200 and rp.content[:5] == b'%PDF-', '/manual.pdf?tipo=escuela: el mismo manual de academias')


# ---------- Dentro del programa: tarjeta de la demo y botones en Ayuda ----------
def entrar(email):
    se = requests.Session()
    se.trust_env = False
    r0 = se.get(B + 'admin.php?p=login')
    se.post(B + 'admin.php?p=login', data={'csrf': csrf(r0.text), 'email': email, 'clave': 'clave12345'})
    return se


cuentas = [('escuela@prueba.com', 'escuela')] if ACADEMIA else [('liga@prueba.com', 'liga'), ('escuela@prueba.com', 'escuela')]
for email, tipo in cuentas:
    lid = php('echo (int)valor("SELECT liga_id FROM usuarios WHERE email = ?", ["%s"]);' % email)
    php('q("UPDATE ligas SET es_prueba = 0 WHERE id = ?", [%s]);' % lid)
    se = entrar(email)
    ri = se.get(B + 'admin.php')
    chk(ri.status_code == 200 and 'id="tarjeta-demo"' not in ri.text, '%s pagada: el inicio NO muestra la tarjeta de la demo' % tipo)
    ra = se.get(B + 'admin.php?p=ayuda')
    chk('id="btn-manual-pdf"' in ra.text and 'id="btn-primeros-pasos-pdf"' in ra.text, '%s pagada: Ayuda tiene los dos botones de descarga' % tipo)
    php('q("UPDATE ligas SET es_prueba = 1, vence = ? WHERE id = ?", [date("Y-m-d", strtotime("+3 days")), %s]);' % lid)
    dm = php('echo date("d/m", strtotime("+3 days"));')
    ri = se.get(B + 'admin.php')
    chk('id="tarjeta-demo"' in ri.text and ('Su demo dura hasta el %s. Descargue el manual y la guía de primeros pasos' % dm) in ri.text, '%s en demo: el inicio muestra «Su demo dura hasta el %s…»' % (tipo, dm))
    hrefs = re.findall(r'<a class="btn[^"]*" id="btn-(?:manual|primeros-pasos)-pdf" href="([^"]+)"', ri.text)
    chk(len(hrefs) == 2, '%s en demo: la tarjeta trae los dos botones' % tipo)
    for h in hrefs:
        rp = se.get(B + h.replace('&amp;', '&'))
        chk(rp.status_code == 200 and rp.content[:5] == b'%PDF-', '%s en demo: el botón %s descarga el PDF' % (tipo, h))
    ra = se.get(B + 'admin.php?p=ayuda')
    chk('id="btn-manual-pdf"' in ra.text and 'id="btn-primeros-pasos-pdf"' in ra.text, '%s en demo: Ayuda tiene los dos botones' % tipo)
    php('q("UPDATE ligas SET es_prueba = 0, vence = ? WHERE id = ?", [date("Y-m-d", strtotime("+90 days")), %s]);' % lid)

# ---------- La demo que el súper crea a mano dentro del programa también es de 3 días ----------
sup = entrar('super@prueba.com')
rn = sup.get(B + 'admin.php?p=ligas&nueva=1')
chk(re.search(r'name="dias_prueba" min="1" max="365" value="3"', rn.text) is not None, 'súper → Crear cuenta: «Días de la demo» viene con 3')
d = {'csrf': csrf(rn.text), 'accion': 'crear', 'nombre': 'Demo A Mano', 'modalidad': 'F7', 'tipo': 'escuela' if ACADEMIA else 'liga', 'admin_nombre': 'Pepe Mano',
     'admin_email': 'pepe.mano@ejemplo.com', 'admin_clave': 'Clave' + secrets.token_hex(5), 'telefono': '0998887766', 'vigencia': 'prueba', 'dias_prueba': '3', 'color': '#123456'}
sup.post(B + 'admin.php?p=ligas&nueva=1', data=d)
lm = pj('fila("SELECT l.vence, l.es_prueba FROM ligas l JOIN usuarios u ON u.liga_id = l.id WHERE u.email = \'pepe.mano@ejemplo.com\'")')
chk(lm and lm['es_prueba'] == 1 and lm['vence'] == php('echo date("Y-m-d", strtotime("+3 days"));'), 'súper → Crear cuenta en demo: vence en 3 días', str(lm))
d.update({'csrf': csrf(sup.get(B + 'admin.php?p=ligas&nueva=1').text), 'nombre': 'Demo Sin Dias', 'admin_email': 'sin.dias@ejemplo.com', 'dias_prueba': ''})
sup.post(B + 'admin.php?p=ligas&nueva=1', data=d)
lm = pj('fila("SELECT l.vence FROM ligas l JOIN usuarios u ON u.liga_id = l.id WHERE u.email = \'sin.dias@ejemplo.com\'")')
chk(lm and lm['vence'] == php('echo date("Y-m-d", strtotime("+3 days"));'), 'súper → Crear cuenta en demo sin escribir los días: 3 días', str(lm))
if not ACADEMIA:
    chk(re.search(r'name="dias" min="1" max="90" value="3"', sup.get(B + 'admin.php?p=modulos&s=demo').text) is not None, 'súper → Módulos deportivos: la demo de un deporte viene con 3 días')
chk(re.search(r'name="dias_demo" min="1" max="365" value="3"', sup.get(B + 'admin.php?p=ajustes').text) is not None, 'súper → Ajustes: «Días de la demo» en 3')
aj = pj('tc2_planes()["ajustes"]')
chk(aj and aj.get('dias_prueba') == 3 and aj.get('dias_demo') == 3, 'Panel: los ajustes que recibe dicen dias_prueba 3 y dias_demo 3', str(aj))

# ---------- Pantallas en el navegador ----------
try:
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        nav = pw.chromium.launch()
        pg = nav.new_page(viewport={'width': 1100, 'height': 1000}, locale='es-EC')
        pg.goto(B + 'index.php?v=solicitar')
        n0 = cuenta()
        pg.click('button[type=submit]')
        pg.wait_for_timeout(600)
        chk(cuenta() == n0 and 'v=solicitar' in pg.url and pg.evaluate('document.querySelector("#f-organizacion").validity.valueMissing'),
            'navegador: con el formulario vacío no deja enviar (campos required)')
        pg.fill('#f-email', 'ana@')
        chk(pg.evaluate('!document.querySelector("#f-email").checkValidity()'), 'navegador: el correo malo no pasa')
        pg.fill('#f-celular', 'abc')
        chk(pg.evaluate('!document.querySelector("#f-celular").checkValidity()'), 'navegador: el celular con letras no pasa')
        # sin la revisión del navegador: el servidor rechaza y pone el mensaje junto al campo
        pg.evaluate('document.querySelector("form[action=\'index.php?v=solicitar\']").noValidate = true')
        pg.fill('#f-organizacion', CAMPOS['organizacion'])
        pg.fill('#f-email', 'ana@')
        pg.click('button[type=submit]')
        pg.wait_for_load_state('networkidle')
        cajas = pg.evaluate('''() => { const e = document.querySelector("#error-email"), i = document.querySelector("#f-email");
            return e && i ? [e.getBoundingClientRect().top - i.getBoundingClientRect().bottom, getComputedStyle(e).color] : null; }''')
        chk(cajas is not None and 0 <= cajas[0] < 40 and cajas[1] == 'rgb(179, 38, 30)', 'navegador: el error del correo sale en rojo justo debajo del campo', str(cajas))
        chk(pg.input_value('#f-organizacion') == CAMPOS['organizacion'], 'navegador: conserva lo escrito después del error')
        pg.screenshot(path=os.path.join(REPO, 'data', 'prueba_demos_formulario.png'), full_page=True)
        nav.close()
except ImportError:
    chk(False, 'falta playwright para las pruebas de pantallas')

log = os.path.join(REPO, 'pruebas', 'v38' if ACADEMIA else 'tukan_central', 'web.log')
txt = open(log, encoding='utf-8', errors='ignore').read() if os.path.exists(log) else ''
chk(not ERRORES.search(txt), 'el servidor web no registró errores de PHP', (ERRORES.search(txt).group(0) + ' ' + txt[max(0, ERRORES.search(txt).start() - 300):ERRORES.search(txt).start() + 300]) if ERRORES.search(txt) else '')
srv.shutdown()
print('\nDemos automáticas — correctas: %d · fallas: %d' % (ok, len(fallos)))
sys.exit(1 if fallos else 0)
