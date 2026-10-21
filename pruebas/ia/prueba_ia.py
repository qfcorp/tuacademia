#!/usr/bin/env python3
"""
Pruebas de la AYUDA con preguntas frecuentes, del módulo «ASISTENTE IA», de ia_uso / ia_tope (Anexo C) y de
«Enviar por correo» en «Demo lista» (21 oct 2026). El mismo archivo sirve para TuVocalía y TuAcademia (modo academia y ligas).

Usa los servidores DE MENTIRA del Panel Tukán Central (tukan-central/tests): anthropic_falso.py (API de Anthropic) y
smtp_falso.py (correo, modo plano en 127.0.0.1). La configuración va en data/tukan-ia.env y data/tukan-correo.env, como en el
VPS de Docker (el contenedor no ve /etc). Carpeta del Panel: TUKAN_CENTRAL_DIR (por defecto ../tukan-central).

Antes (base nueva):
  TuVocalía:   TA_PUERTO=8431 bash pruebas/tukan_central/reiniciar.sh
  TuAcademia:  TA_PUERTO=8431 TA_SIM=8462 bash pruebas/v38/reiniciar.sh            (modo academia)
               TA_PRODUCTO=ligas TA_PUERTO=8431 TA_SIM=8462 bash pruebas/v38/reiniciar.sh
Correr:        TA_PUERTO=8431 python3 pruebas/ia/prueba_ia.py      (puertos de los falsos: TA_IA=8434, TA_SMTP=8435)
"""
import base64, email, email.policy, glob, hashlib, hmac, json, os, re, secrets, shutil, struct, subprocess, sys, tempfile, time, zlib
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
P_IA = int(os.environ.get('TA_IA', '8434'))
P_SMTP = int(os.environ.get('TA_SMTP', '8435'))
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TC = os.environ.get('TUKAN_CENTRAL_DIR', os.path.join(os.path.dirname(REPO), 'tukan-central'))
DATA = os.path.join(REPO, 'data')
IA_ENV = os.path.join(DATA, 'tukan-ia.env')
CORREO_ENV = os.path.join(DATA, 'tukan-correo.env')
CENTRAL_ENV = os.path.join(DATA, 'tukan_central.env')
CLAVE_IA = 'sk-ant-prueba-buena-0123456789'
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
PROHIBIDAS = re.compile(r'l[íi]mite|cupo|saldo|(?<!tarjeta de )cr[ée]dito(?! o d[ée]bito)|se acab[óo]|pas[óo] el tope|IA apagada', re.I)   # («tarjeta de crédito o débito» del manual sí)
ok, fallos = 0, []
TMP = tempfile.mkdtemp(prefix='prueba_ia_')
REG = os.path.join(TMP, 'ia.jsonl')
BUZON = os.path.join(TMP, 'buzon')
os.makedirs(BUZON)


def chk(cond, nombre, detalle=''):
    global ok
    if cond:
        ok += 1
        print('  OK   ', nombre)
    else:
        fallos.append(nombre)
        print('  FALLA', nombre, ('-> ' + str(detalle)[:500]) if detalle else '')


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout[-1500:], r.stderr[-1500:])
    return r.stdout.strip()


def pj(codigo):
    return json.loads(php('echo json_encode(' + codigo + ', JSON_UNESCAPED_UNICODE);') or 'null')


def val(sql, *p):
    return php('echo valor(' + json.dumps(sql, ensure_ascii=False) + ', ' + json.dumps(list(p), ensure_ascii=False) + ');')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def ip():
    return '10.%d.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250), 1 + secrets.randbelow(250))


def entrar(email_, clave='clave12345'):
    s = requests.Session()
    s.headers['CF-Connecting-IP'] = ip()
    if os.environ.get('TA_IDIOMA'):   # solo para juntar los textos que faltan traducir (config 'idioma_faltantes')
        s.cookies.set('idioma', os.environ['TA_IDIOMA'])
    r = s.get(B + 'admin.php?p=login')
    s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email_, 'clave': clave})
    return s


def enviar(s, url, datos, archivos=None, desde=None):
    r = s.get(B + (desde or url))
    datos = dict(datos)
    datos['csrf'] = csrf(r.text)
    return s.post(B + url, data=datos, files=archivos)


def llamadas():
    return sum(1 for _ in open(REG)) if os.path.exists(REG) else 0


def ultima():
    if not os.path.exists(REG):
        return {}
    l = open(REG).read().strip().splitlines()
    return json.loads(l[-1]) if l else {}


def ia_env(**mas):
    lineas = ['TUKAN_IA_CLAVE=' + CLAVE_IA, 'TUKAN_IA_URL=http://127.0.0.1:%d' % P_IA, 'TUKAN_IA_ESPERA_MS=0', 'TUKAN_IA_REINTENTOS=0', 'TUKAN_IA_TIMEOUT=10']
    lineas += ['%s=%s' % kv for kv in mas.items()]
    open(IA_ENV, 'w').write('\n'.join(lineas) + '\n')


def sin(f):
    if os.path.exists(f):
        os.remove(f)


def respuesta(r):
    m = re.search(r'<div class="ayuda-respuesta"[^>]*>(.*?)</div>', r.text, re.S)
    t = re.sub(r'<[^>]+>', '', m.group(1)) if m else ''
    return t.replace('&quot;', '"').replace('&#039;', "'").replace('&amp;', '&').replace('&lt;', '<').replace('&gt;', '>')


def png():
    def trozo(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + trozo(b'IHDR', struct.pack('>IIBBBBB', 2, 2, 8, 2, 0, 0, 0)) + trozo(b'IDAT', zlib.compress(b'\x00' + b'\xff' * 6 + b'\x00' + b'\x00' * 6)) + trozo(b'IEND', b'')


def gasto(lid, col='costo_usd'):
    v = val("SELECT COALESCE(SUM(%s), 0) FROM tukan_ia_gasto WHERE negocio_id = ? AND mes = ?" % col, str(lid), time.strftime('%Y-%m'))
    return float(v or 0)


pantallas = []   # todo lo que ve el cliente, para buscar las palabras prohibidas
procesos = []
try:
    # ---------- servidores de mentira ----------
    if not os.path.exists(os.path.join(TC, 'tests', 'anthropic_falso.py')):
        print('No encuentro', TC, '(clone qfcorp/tukan-central al lado o use TUKAN_CENTRAL_DIR)')
        sys.exit(2)
    procesos.append(subprocess.Popen([sys.executable, os.path.join(TC, 'tests', 'anthropic_falso.py'), str(P_IA), REG], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
    procesos.append(subprocess.Popen([sys.executable, os.path.join(TC, 'tests', 'smtp_falso.py'), '--puerto', str(P_SMTP), '--modo', 'plano', '--usuario', 'info@tukansoftware.com',
                                      '--clave', 'clave-buzon', '--dir', BUZON], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
    time.sleep(1)
    for f in (IA_ENV, CORREO_ENV):
        sin(f)

    ACADEMIA = php('echo function_exists("es_academia") && es_academia() ? "1" : "0";') == '1'
    PROG = php('echo tc_producto()[0];')
    NOMBRE = php('echo marca();')
    print('Producto:', NOMBRE, '(modo academia)' if ACADEMIA else '(modo ligas)', '· puerto', PUERTO)
    ESC_ID = val("SELECT liga_id FROM usuarios WHERE email = 'escuela@prueba.com'")
    LIGA_ID = '' if ACADEMIA else val("SELECT liga_id FROM usuarios WHERE email = 'liga@prueba.com'")
    PRINCIPAL = 'escuela@prueba.com' if ACADEMIA else 'liga@prueba.com'
    PRIN_ID = ESC_ID if ACADEMIA else LIGA_ID
    MODO = 'academia' if ACADEMIA else 'ligas'
    chk(ESC_ID != '' and (ACADEMIA or LIGA_ID != ''), 'hay cuentas de prueba (' + ('academia' if ACADEMIA else 'liga y escuela') + ')')

    print('\n== Tablas (se crean solas y se puede repetir)')
    tablas = php("echo implode(',', db()->query(\"SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'tukan_ia_%' ORDER BY name\")->fetchAll(PDO::FETCH_COLUMN));")
    chk(tablas == 'tukan_ia_faq,tukan_ia_gasto', 'tukan_ia_faq y tukan_ia_gasto existen', tablas)
    chk('modo' in php("echo implode(',', array_column(db()->query('PRAGMA table_info(tukan_ia_faq)')->fetchAll(), 'name'));") and
        'ia_tope_usd' in php("echo implode(',', array_column(db()->query('PRAGMA table_info(ligas)')->fetchAll(), 'name'));"), 'columnas tukan_ia_faq.modo y ligas.ia_tope_usd')
    chk(php("esquema_ia(db()); esquema_ia(db()); tukan_ia_crear_tablas(db()); echo 'ok';") == 'ok', 'esquema_ia() y tukan_ia_crear_tablas() se pueden repetir')
    chk(open(os.path.join(REPO, 'app', 'tukan_ia.php'), 'rb').read() == open(os.path.join(TC, 'compartido', 'tukan_ia.php'), 'rb').read()
        and open(os.path.join(REPO, 'app', 'tukan_correo.php'), 'rb').read() == open(os.path.join(TC, 'compartido', 'tukan_correo.php'), 'rb').read(),
        'las librerías son copias SIN cambios de tukan-central/compartido')
    if ACADEMIA:
        chk(open(os.path.join(REPO, 'app', 'manuales', 'academia.html'), 'rb').read() == open(os.path.join(REPO, 'docs', 'manual', 'manual.html'), 'rb').read(),
            'el manual de la Ayuda (app/manuales/academia.html) es igual a docs/manual/manual.html')

    # =====================================================================
    print('\n== Ayuda: para todos, sin IA configurada (manual tal cual)')
    a = entrar(PRINCIPAL)
    g = a.get(B + 'admin.php?p=ayuda')
    chk(g.status_code == 200 and '¿En qué le ayudamos?' in g.text and 'Escriba su pregunta' in g.text and 'placeholder="¿Cómo registro un pago?"' in g.text
        and 'maxlength="500"' in g.text and '>Preguntar<' in g.text, 'la cuenta abre Ayuda (200) con el cuadro de pregunta')
    chk('admin.php?p=ayuda' in a.get(B + 'admin.php').text, '«Ayuda» está en el menú')
    pantallas.append(g.text)
    r = a.post(B + 'admin.php?p=ayuda', data={'pregunta': 'hola'})
    chk(r.status_code == 400, 'POST sin CSRF -> 400', r.status_code)
    n0 = llamadas()
    PREG = '¿Cómo registro un pago?' if ACADEMIA else '¿Cómo creo un campeonato nuevo?'
    r = enviar(a, 'admin.php?p=ayuda', {'pregunta': PREG})
    t = respuesta(r)
    chk('Esto dice el manual de ' + NOMBRE in t and '099 640 8397' in t and llamadas() == n0, 'sin clave: el manual tal cual + WhatsApp, sin llamar a la IA', t[:300])
    chk('¿Le sirvió? Si no, escríbanos por WhatsApp' in r.text, 'debajo: «¿Le sirvió?…» con el WhatsApp')
    chk(gasto(PRIN_ID, 'ahorradas') >= 1 and gasto(PRIN_ID) == 0, 'se anota como ahorrada (sin costo)')
    pantallas += [r.text, t]

    print('\n== Ayuda con la IA de mentira: nueva, repetida, parecida (data/tukan-ia.env, como en el VPS)')
    ia_env()
    n0 = llamadas()
    r = enviar(a, 'admin.php?p=ayuda', {'pregunta': PREG + ' Paso a paso'})
    t1 = respuesta(r)
    u = ultima()
    chk(llamadas() == n0 + 1 and u.get('modelo') == 'claude-haiku-5-5' and u.get('clave_ok') is True and 'asistente de ayuda de ' + NOMBRE in u.get('system', '') and '<manual>' in u.get('texto', ''),
        'pregunta nueva: UNA llamada (modelo rápido) con secciones del manual', json.dumps(u, ensure_ascii=False)[:400])
    chk(t1.startswith('1. Abra el menu.'), 'muestra la respuesta de la IA', t1[:200])
    faq = pj("fila('SELECT * FROM tukan_ia_faq WHERE pregunta = ?', [" + json.dumps(PREG + ' Paso a paso', ensure_ascii=False) + "])")
    chk(faq and faq['origen'] == 'automatica' and int(faq['aprobada']) == 0 and faq['modo'] == MODO, 'queda como FAQ automática, sin aprobar, del modo ' + MODO, faq)
    chk(gasto(PRIN_ID, 'llamadas') == 1 and gasto(PRIN_ID, 'ayudas_nuevas') == 1 and gasto(PRIN_ID) > 0, 'gasto de la cuenta: 1 llamada, 1 ayuda nueva, costo > 0')
    n1 = llamadas()
    t2 = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': PREG + ' Paso a paso'}))
    chk(t2 == t1 and llamadas() == n1 and int(val('SELECT usos FROM tukan_ia_faq WHERE id = ?', faq['id'])) == 2, 'la misma otra vez: desde la FAQ, sin llamar')
    t3 = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': (PREG + ' paso a paso por favor').lower().replace('¿', '').replace('?', '')}))
    chk(t3 == t1 and llamadas() == n1, 'una parecida: desde la FAQ, sin llamar', t3[:200])
    chk(int(val("SELECT COUNT(*) FROM bitacora WHERE tabla = 'ayuda' AND descripcion LIKE ?", '%' + PREG + '%')) == 1, 'la pregunta nueva queda en la bitácora')
    if not ACADEMIA:
        e = entrar('escuela@prueba.com')
        t4 = respuesta(enviar(e, 'admin.php?p=ayuda', {'pregunta': PREG + ' Paso a paso'}))
        u = ultima()
        chk(llamadas() == n1 + 1 and pj("fila('SELECT modo FROM tukan_ia_faq ORDER BY id DESC LIMIT 1')")['modo'] == 'escuelas',
            'una ESCUELA no usa la FAQ de las ligas: pregunta con el manual de escuelas y la guarda como «escuelas»', t4[:200])
        fed = php("echo (int)crear_liga_completa(['nombre'=>'Federación IA','modalidad'=>'F7','admin_nombre'=>'Fed','admin_email'=>'fed.ia@prueba.com','admin_clave'=>'clave12345','tipo'=>'liga','vence'=>date('Y-m-d', strtotime('+30 days'))])['liga_id'];")
        php("q('UPDATE ligas SET es_federacion = 1 WHERE id = ?', [" + fed + "]);")
        chk(php("echo ia_modo(fila('SELECT * FROM ligas WHERE id = " + fed + "')) . ' ' . ia_modo(fila('SELECT * FROM ligas WHERE id = " + ESC_ID + "')) . ' ' . ia_modo(fila('SELECT * FROM ligas WHERE id = " + LIGA_ID + "'));") == 'federaciones escuelas ligas',
            'modo del manual: federaciones, escuelas y ligas')
        chk('federaciones' in php("echo strlen(ia_manual('federaciones')) > 1000 && strlen(ia_manual('escuelas')) > 1000 && strlen(ia_manual('ligas')) > 1000 ? 'federaciones' : 'no';"), 'los 3 manuales se leen como texto')

    print('\n== Ayuda: pausa, tope de la cuenta, 30 nuevas sin el módulo y tope del programa (solo manual)')
    a = entrar(PRINCIPAL)
    n1 = llamadas()
    ia_env(TUKAN_IA_PAUSA='1')
    tp = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': '¿Cómo cambio el logo?'}))
    chk(llamadas() == n1 and 'Esto dice el manual de' in tp, 'TUKAN_IA_PAUSA=1: manual, sin llamar', tp[:200])
    ia_env()
    php("q(\"UPDATE tukan_ia_gasto SET costo_usd = 0.50 WHERE negocio_id = ?\", ['" + PRIN_ID + "']);")
    tt = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': '¿Cómo agrego un usuario nuevo?'}))
    chk(llamadas() == n1 and 'Esto dice el manual de' in tt, 'la cuenta en su tope ($0.50 sin el módulo): manual, sin llamar', tt[:200])
    php("q(\"UPDATE tukan_ia_gasto SET costo_usd = 0.01, ayudas_nuevas = 30 WHERE negocio_id = ?\", ['" + PRIN_ID + "']);")
    t30 = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': '¿Cómo descargo mis datos en Excel?'}))
    chk(llamadas() == n1 and 'Esto dice el manual de' in t30, 'sin el módulo, después de 30 respuestas nuevas del mes: manual', t30[:200])
    php("q(\"UPDATE tukan_ia_gasto SET ayudas_nuevas = 1 WHERE negocio_id = ?\", ['" + PRIN_ID + "']);")
    ia_env(**{'TUKAN_IA_TOPE_PROGRAMA_' + PROG.upper(): '0.000001'})
    tpr = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': '¿Cómo activo la doble verificación?'}))
    chk(llamadas() == n1 and 'Esto dice el manual de' in tpr, 'el programa en su tope (TUKAN_IA_TOPE_PROGRAMA_' + PROG.upper() + '): manual', tpr[:200])
    ia_env()
    tz = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': 'xyzzy plugh'}))
    chk('No encontramos eso en el manual de ' + NOMBRE in tz and '099 640 8397' in tz, 'lo que no está en el manual: «No encontramos eso…» + WhatsApp', tz)
    pantallas += [tp, tt, t30, tpr, tz]

    print('\n== Ayuda: 10 preguntas por minuto')
    a2 = entrar(PRINCIPAL)
    bien = sum(1 for i in range(10) if 'Espere un minuto' not in respuesta(enviar(a2, 'admin.php?p=ayuda', {'pregunta': PREG + ' Paso a paso'})))
    t11 = respuesta(enviar(a2, 'admin.php?p=ayuda', {'pregunta': PREG + ' Paso a paso'}))
    chk(bien == 10 and 'Espere un minuto' in t11, '10 sí, la 11.ª en el mismo minuto: «Espere un minuto»', '%d · %s' % (bien, t11))

    # =====================================================================
    print('\n== Preguntas frecuentes del súper')
    a = entrar(PRINCIPAL)
    r = a.get(B + 'admin.php?p=ayuda_faq', allow_redirects=False)
    chk(r.status_code == 302 and 'ayuda_faq' not in (r.headers.get('Location') or ''), 'la cuenta NO entra (la manda fuera)', r.status_code)
    s = entrar('super@prueba.com')
    r = s.get(B + 'admin.php?p=ayuda_faq')
    chk(r.status_code == 200 and 'Por revisar' in r.text and 'Paso a paso' in r.text and 'Automática' in r.text and 'Aprobar' in r.text and 'Editar' in r.text and 'Borrar' in r.text,
        'el súper la abre: «Por revisar» con la FAQ automática y Aprobar / Editar / Borrar')
    chk('admin.php?p=ayuda' in s.get(B + 'admin.php').text, 'el súper también tiene «Ayuda» en el menú')
    r = s.post(B + 'admin.php?p=ayuda_faq', data={'accion': 'aprobar', 'id': faq['id']})
    chk(r.status_code == 400 and int(val('SELECT aprobada FROM tukan_ia_faq WHERE id = ?', faq['id'])) == 0, 'aprobar sin CSRF -> 400 y no cambia')
    enviar(s, 'admin.php?p=ayuda_faq', {'accion': 'aprobar', 'id': faq['id']})
    chk(int(val('SELECT aprobada FROM tukan_ia_faq WHERE id = ?', faq['id'])) == 1 and int(val("SELECT COUNT(*) FROM bitacora WHERE tabla = 'ayuda_faq' AND descripcion LIKE ?", 'Aprobó la pregunta frecuente #%s%%' % faq['id'])) == 1,
        'Aprobar -> aprobada y en la bitácora')
    enviar(s, 'admin.php?p=ayuda_faq&f=', {'accion': 'editar', 'id': faq['id'], 'pregunta': PREG + ' Paso a paso', 'respuesta': 'Vaya a **Configuración** y siga los pasos.', 'modo': MODO}, desde='admin.php?p=ayuda_faq&f=')
    chk(val("SELECT origen || aprobada || modo FROM tukan_ia_faq WHERE id = ?", faq['id']) == 'manual1' + MODO, 'Editar -> «manual», aprobada y con su modo')
    a = entrar(PRINCIPAL)
    r = enviar(a, 'admin.php?p=ayuda', {'pregunta': PREG + ' Paso a paso'})
    chk('Vaya a Configuración' in respuesta(r) and '<b>Configuración</b>' in r.text, 'la Ayuda ya responde lo editado (con negritas)', respuesta(r))
    enviar(s, 'admin.php?p=ayuda_faq&f=', {'accion': 'agregar', 'pregunta': '¿Atienden los domingos?', 'respuesta': 'Escríbanos por WhatsApp.', 'modo': ''}, desde='admin.php?p=ayuda_faq&f=')
    nid = val("SELECT id FROM tukan_ia_faq WHERE pregunta = '¿Atienden los domingos?'")
    chk(nid != '' and val("SELECT origen || aprobada FROM tukan_ia_faq WHERE id = ?", nid) == 'manual1', 'Agregar -> aprobada y «manual» (para todos)')
    enviar(s, 'admin.php?p=ayuda_faq&f=', {'accion': 'borrar', 'id': nid}, desde='admin.php?p=ayuda_faq&f=')
    chk(val('SELECT COUNT(*) FROM tukan_ia_faq WHERE id = ?', nid) == '0' and int(val("SELECT COUNT(*) FROM bitacora WHERE tabla = 'ayuda_faq' AND descripcion LIKE ?", 'Borró la pregunta frecuente #%s%%' % nid)) == 1, 'Borrar -> ya no está, en la bitácora')
    r = enviar(a, 'admin.php?p=ayuda_faq', {'accion': 'agregar', 'pregunta': 'x', 'respuesta': 'y'}, desde='admin.php?p=ayuda')
    chk(val("SELECT COUNT(*) FROM tukan_ia_faq WHERE pregunta = 'x'") == '0', 'la cuenta no puede agregar')

    # =====================================================================
    print('\n== Asistente IA: módulo extra por cotización')
    a = entrar(PRINCIPAL)
    r = a.get(B + 'admin.php?p=asistente', allow_redirects=False)
    chk(r.status_code == 302 and 'mi_plan' in (r.headers.get('Location') or ''), 'sin el módulo: la página manda a Mi plan', r.headers.get('Location'))
    chk('p=asistente' not in a.get(B + 'admin.php').text, 'sin el módulo: no sale en el menú')
    mp = a.get(B + 'admin.php?p=mi_plan').text
    i = mp.find('Asistente IA')
    chk(i > 0 and 'Pedir cotización' in mp and not re.search(r'Asistente IA.{0,500}\$\s?0', mp, re.S), 'Mi plan: «Asistente IA» sin precio (se pide cotización)')
    i2 = mp.find('Asistente IA')
    pantallas.append(mp[max(0, i2 - 300):i2 + 600])   # (el resto de Mi plan habla de los planes: «sin límite» de equipos, etc.)
    chk(float(php("echo json_encode(ia_tope_cuenta(fila('SELECT * FROM ligas WHERE id = " + PRIN_ID + "')));")) == 0.5, 'tope sugerido sin el módulo: $0.50')
    print('\n== El plan más alto trae el Asistente IA incluido (decisión del dueño); en los demás es extra')
    altos = pj("ia_planes_altos(db())")
    chk(altos and all('asistente_ia' in x['modulos'].split(',') for x in altos), 'migración: el plan más alto de ligas y de escuelas tiene «asistente_ia»', altos)
    otros = pj("filas(\"SELECT id, modulos FROM planes WHERE activo = 1\")")
    chk(sum(1 for x in otros if 'asistente_ia' in x['modulos'].split(',')) == len(altos), 'los demás planes no lo traen', otros)
    chk(php("ia_plan_alto(db()); esquema_ia(db()); echo 'ok';") == 'ok' and pj("filas(\"SELECT id, modulos FROM planes\")") == pj("filas(\"SELECT id, modulos FROM planes\")")
        and all(x['modulos'].count('asistente_ia') <= 1 for x in pj("filas('SELECT modulos FROM planes')")), 'la migración se puede repetir (no duplica)')
    ALTO = str(altos[0]['id']) if not ACADEMIA else str(next(x['id'] for x in altos))
    php("q(\"UPDATE planes SET modulos = REPLACE(modulos, ',asistente_ia', '') WHERE id = " + ALTO + "\"); esquema_ia(db());")
    chk('asistente_ia' not in val('SELECT modulos FROM planes WHERE id = ?', ALTO), 'si el súper se lo quita a mano al plan, la migración no lo vuelve a poner')
    php("q(\"UPDATE planes SET modulos = modulos || ',asistente_ia' WHERE id = " + ALTO + "\");")
    tipo_alto = val("SELECT COALESCE(NULLIF(tipo_cuenta, ''), 'ambos') FROM planes WHERE id = ?", ALTO)
    cta = PRIN_ID
    php("q('UPDATE ligas SET plan_id = ?, es_prueba = 0, vence = ?, modulos_extra = \\'\\', modulos_elegidos = 0 WHERE id = ?', [" + ALTO + ", date('Y-m-d', strtotime('+60 days')), " + cta + "]);")
    chk(php("echo modulo_activo(fila('SELECT * FROM ligas WHERE id = " + cta + "'), 'asistente_ia') ? 'si' : 'no';") == 'si'
        and float(php("echo json_encode(ia_tope_cuenta(fila('SELECT * FROM ligas WHERE id = " + cta + "')));")) == 4.0, 'una cuenta en el plan más alto tiene el Asistente IA y tope $4.00 (' + tipo_alto + ')')
    r = a.get(B + 'admin.php?p=asistente')
    chk(r.status_code == 200 and 'Asistente IA' in r.text, 'y entra al Asistente sin pedirlo')
    mp = a.get(B + 'admin.php?p=mi_plan').text
    chk(re.search(r'Asistente IA', mp) is not None, 'Mi plan lo muestra encendido')
    bajo = val("SELECT id FROM planes WHERE activo = 1 AND id <> ? AND COALESCE(NULLIF(tipo_cuenta, ''), 'ambos') IN ('ambos', ?) ORDER BY precio_mensual LIMIT 1", ALTO, 'escuela' if ACADEMIA else 'liga')
    php("q('UPDATE ligas SET plan_id = ? WHERE id = ?', [" + bajo + ", " + cta + "]);")
    chk(php("echo modulo_activo(fila('SELECT * FROM ligas WHERE id = " + cta + "'), 'asistente_ia') ? 'si' : 'no';") == 'no'
        and float(php("echo json_encode(ia_tope_cuenta(fila('SELECT * FROM ligas WHERE id = " + cta + "')));")) == 0.5, 'en un plan más bajo no lo tiene (tope de la Ayuda $0.50)')
    php("q('UPDATE ligas SET plan_id = NULL, es_prueba = 0 WHERE id = ?', [" + cta + "]);")
    php("cambiar_modulos_cuenta(fila('SELECT * FROM ligas WHERE id = " + PRIN_ID + "'), ['asistente_ia'], [], ['por' => 'prueba']);")
    chk(float(php("echo json_encode(ia_tope_cuenta(fila('SELECT * FROM ligas WHERE id = " + PRIN_ID + "')));")) == 4.0, 'con el módulo (comprado aparte) el tope sube a $4.00')
    r = a.get(B + 'admin.php?p=asistente')
    chk(r.status_code == 200 and ('Informe del avance del alumno para los padres' in r.text and 'Mensaje de cobro amable' in r.text if ACADEMIA else
        'Crónica del partido para redes' in r.text and 'Leer la foto de la hoja de vocalía en papel' in r.text), 'con el módulo: las 2 funciones de su modo')
    chk('p=asistente' in a.get(B + 'admin.php').text, 'con el módulo: «Asistente IA» en el menú')
    php("q('UPDATE tukan_ia_gasto SET costo_usd = 0, ayudas_nuevas = 0');")

    if not ACADEMIA:
        print('\n== Asistente IA (liga): crónica del partido y hoja de vocalía')
        L = LIGA_ID
        datos = pj("(function () { $l = " + L + "; q(\"INSERT INTO equipos (liga_id, nombre) VALUES (?, 'Halcones FC'), (?, 'Tigres SC')\", [$l, $l]);"
                   "$h = (int)valor(\"SELECT id FROM equipos WHERE liga_id = ? AND nombre = 'Halcones FC'\", [$l]); $t = (int)valor(\"SELECT id FROM equipos WHERE liga_id = ? AND nombre = 'Tigres SC'\", [$l]);"
                   "q(\"INSERT INTO jugadores (equipo_id, nombres, apellidos, numero) VALUES (?, 'Pedro', 'Goleador', 10), (?, 'Juan', 'Defensa', 4), (?, 'Mario', 'Tarjetas Rojas', 7), (?, 'Luis', 'Arquero', 1)\", [$h, $h, $t, $t]);"
                   "q(\"INSERT INTO torneos (liga_id, nombre, estado) VALUES (?, 'Copa IA', 'en_curso')\", [$l]); $to = (int)valor('SELECT MAX(id) FROM torneos');"
                   "q(\"INSERT INTO partidos (torneo_id, local_id, visitante_id, fecha, estado, goles_local, goles_visitante, jornada) VALUES (?, ?, ?, ?, 'jugado', 2, 1, 3)\", [$to, $h, $t, date('Y-m-d')]); $pa = (int)valor('SELECT MAX(id) FROM partidos');"
                   "$j = fn($n) => (int)valor('SELECT id FROM jugadores WHERE apellidos = ?', [$n]);"
                   "q(\"INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto) VALUES (?, ?, ?, 'gol', 12), (?, ?, ?, 'gol', 40), (?, ?, ?, 'gol', 55), (?, ?, ?, 'amarilla', 30)\","
                   " [$pa, $j('Goleador'), $h, $pa, $j('Goleador'), $h, $pa, $j('Tarjetas Rojas'), $t, $pa, $j('Defensa'), $h]);"
                   "q(\"INSERT INTO partidos (torneo_id, local_id, visitante_id, estado) VALUES (?, ?, ?, 'programado')\", [$to, $h, $t]); $p2 = (int)valor('SELECT MAX(id) FROM partidos');"
                   "return ['partido' => $pa, 'programado' => $p2, 'h' => $h, 't' => $t, 'goleador' => $j('Goleador'), 'rojas' => $j('Tarjetas Rojas'), 'defensa' => $j('Defensa')]; })()")
        PA = str(datos['partido'])
        chk('f=hoja&amp;partido=' + PA in a.get(B + 'admin.php?p=partido&id=' + PA).text, 'botones del Asistente en el partido')
        n1 = llamadas()
        CRON = 'Halcones FC ganó 2 a 1 con doblete de Pedro Goleador. #LigaIA'
        r = enviar(a, 'admin.php?p=asistente&f=cronica', {'accion': 'cronica', 'partido': PA, 'nota': 'RESPONDER:' + CRON}, desde='admin.php?p=asistente&f=cronica')
        u = ultima()
        chk(llamadas() == n1 + 1 and 'Revise el texto' in r.text and CRON in r.text and 'id="txt-propuesta"' in r.text, 'crónica: 1 llamada y el texto para revisar y copiar', r.text[-300:])
        chk('Resultado: Halcones FC 2 - 1 Tigres SC' in u.get('texto', '') and 'Gol: Pedro Goleador (Halcones FC), minuto 12' in u.get('texto', '') and 'Tarjeta amarilla: Juan Defensa' in u.get('texto', '')
            and '<partido>' in u.get('texto', ''), 'la crónica se pide con el resultado, los goleadores y las tarjetas guardadas', u.get('texto', '')[:600])
        chk('https://wa.me/?text=' in r.text, '«Compartir por WhatsApp» con el texto')
        pantallas.append(r.text)
        ia_env(TUKAN_IA_PAUSA='1')
        r = enviar(a, 'admin.php?p=asistente&f=cronica', {'accion': 'cronica', 'partido': PA}, desde='admin.php?p=asistente&f=cronica')
        chk(llamadas() == n1 + 1 and 'En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí: <a href="admin.php?p=partido&amp;id=' + PA + '">' in r.text,
            'crónica en pausa: mensaje neutro con el enlace al partido, sin llamar')
        ia_env()
        pantallas.append(r.text)
        HOJA = json.dumps({'eventos': [{'equipo': 'local', 'numero': 10, 'nombre': 'P. Goleador', 'tipo': 'gol', 'minuto': 5},
                                       {'equipo': 'visitante', 'numero': None, 'nombre': 'Mario Tarjetas Rojas', 'tipo': 'roja', 'minuto': 80},
                                       {'equipo': 'local', 'numero': 4, 'nombre': 'Juan', 'tipo': 'inventado', 'minuto': 3}]}, separators=(',', ':'))   # la nota es de 300 letras como máximo
        P2 = str(datos['programado'])
        ev0 = int(val('SELECT COUNT(*) FROM eventos WHERE partido_id = ?', P2))
        r = enviar(a, 'admin.php?p=asistente&f=hoja', {'accion': 'hoja', 'partido': P2, 'nota': 'RESPONDER:' + HOJA}, {'foto': ('hoja.png', png(), 'image/png')}, desde='admin.php?p=asistente&f=hoja')
        u = ultima()
        chk(llamadas() == n1 + 2 and u.get('modelo') == 'claude-sonnet-5-5' and u.get('imagenes') == ['image/png'] and '#10 Pedro Goleador' in u.get('texto', ''), 'hoja: modelo fuerte con la foto y las nóminas', 'llamadas %d · %s' % (llamadas() - n1, re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', r.text[r.text.find('<h1'):]))[:600]))
        tabla = r.text[r.text.find('id="propuestos"'):]
        chk('Revise los goles y las tarjetas' in r.text and tabla.count('[usar]') == 2 and 'inventado' not in tabla, 'propone 2 eventos para revisar (el tipo inventado no)', tabla.count('[usar]'))
        chk(re.search(r'<option value="%d" selected>#10 Pedro Goleador' % datos['goleador'], r.text) is not None and re.search(r'<option value="%d" selected>#7 Mario Tarjetas Rojas' % datos['rojas'], r.text) is not None,
            'reconoce a los jugadores por el número y por el nombre')
        chk(int(val('SELECT COUNT(*) FROM eventos WHERE partido_id = ?', P2)) == ev0, 'todavía no guarda nada')
        pantallas.append(r.text)
        r = enviar(a, 'admin.php?p=asistente&f=hoja', {'accion': 'hoja_guardar', 'partido': P2, 'marcador': '1',
                                                        'ev[0][usar]': '1', 'ev[0][jugador]': str(datos['goleador']), 'ev[0][tipo]': 'gol', 'ev[0][minuto]': '5',
                                                        'ev[1][jugador]': str(datos['rojas']), 'ev[1][tipo]': 'roja', 'ev[1][minuto]': '80'}, desde='admin.php?p=asistente&f=hoja')
        pp = pj("fila('SELECT estado, goles_local, goles_visitante FROM partidos WHERE id = " + P2 + "')")
        chk(int(val('SELECT COUNT(*) FROM eventos WHERE partido_id = ?', P2)) == ev0 + 1 and pp['estado'] == 'jugado' and int(pp['goles_local']) == 1 and int(pp['goles_visitante']) == 0,
            'guarda solo el marcado y actualiza el marcador (1-0, jugado)', pp)
        chk(int(val("SELECT COUNT(*) FROM bitacora WHERE descripcion LIKE 'Guardó 1 evento(s) leídos de la foto de la hoja de vocalía%'")) == 1, 'en la bitácora')
        n2 = llamadas()
        r = enviar(a, 'admin.php?p=asistente&f=hoja', {'accion': 'hoja', 'partido': P2}, {'foto': ('hoja.png', b'esto no es una foto', 'image/png')}, desde='admin.php?p=asistente&f=hoja')
        chk(llamadas() == n2 and 'En este momento no pudimos procesar la foto automáticamente; puede hacerlo a mano aquí: <a href="admin.php?p=partido&amp;id=' + P2 + '#eventos">' in r.text,
            'un archivo que no es foto: mensaje neutro con el enlace a cargarlo a mano, sin llamar')
        php("q(\"UPDATE tukan_ia_gasto SET costo_usd = 4 WHERE negocio_id = ?\", ['" + L + "']);")
        r = enviar(a, 'admin.php?p=asistente&f=hoja', {'accion': 'hoja', 'partido': P2, 'nota': 'RESPONDER:' + HOJA}, {'foto': ('hoja.png', png(), 'image/png')}, desde='admin.php?p=asistente&f=hoja')
        chk(llamadas() == n2 and 'no pudimos procesar la foto automáticamente' in r.text, 'la cuenta en su tope ($4.00 con el módulo): mensaje neutro, sin llamar')
        pantallas.append(r.text)
        php("q('UPDATE tukan_ia_gasto SET costo_usd = 0');")

    print('\n== Asistente IA (escuela/academia): informe para los padres y cobro amable')
    E = ESC_ID
    if not ACADEMIA:
        php("cambiar_modulos_cuenta(fila('SELECT * FROM ligas WHERE id = " + E + "'), ['asistente_ia'], [], ['por' => 'prueba']);")
    e = entrar('escuela@prueba.com')
    al = pj("(function () { $l = " + E + "; q(\"INSERT INTO esc_grupos (liga_id, nombre) VALUES (?, 'Grupo IA')\", [$l]); $g = (int)valor('SELECT MAX(id) FROM esc_grupos');"
            "q(\"INSERT INTO esc_alumnos (liga_id, grupo_id, nombres, apellidos, representante_nombre, representante_cedula, representante_telefono, estado) VALUES (?, ?, 'Sofía', 'Bailarina', 'Ana Madre', '1711111111', '0991234567', 'activo')\", [$l, $g]);"
            "$a = (int)valor('SELECT MAX(id) FROM esc_alumnos');"
            "q(\"INSERT INTO esc_alumnos (liga_id, grupo_id, nombres, apellidos, representante_nombre, representante_cedula, representante_telefono, estado) VALUES (?, ?, 'Tomás', 'Hermano', 'Ana Madre', '1711111111', '0991234567', 'activo')\", [$l, $g]);"
            "$b = (int)valor('SELECT MAX(id) FROM esc_alumnos');"
            "foreach (['-20 days' => 'P', '-13 days' => 'P', '-6 days' => 'A'] as $d => $est) { q('INSERT INTO esc_sesiones (grupo_id, fecha) VALUES (?, ?)', [$g, date('Y-m-d', strtotime($d))]); $s = (int)valor('SELECT MAX(id) FROM esc_sesiones');"
            " q('INSERT INTO esc_asistencia (sesion_id, alumno_id, estado) VALUES (?, ?, ?)', [$s, $a, $est]); }"
            "q(\"INSERT INTO esc_notas_clase (sesion_id, alumno_id, texto) VALUES (?, ?, 'Mejoró mucho los giros; debe practicar la postura.')\", [$s, $a]);"
            "q(\"INSERT INTO esc_grados (alumno_id, fecha, grado) VALUES (?, ?, 'Intermedio')\", [$a, date('Y-m-d')]);"
            "q(\"INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, vence, estado) VALUES (?, ?, 'mensualidad', '2026-10', 'Pensión octubre', 35, ?, 'pendiente'), (?, ?, 'mensualidad', '2026-10', 'Pensión octubre', 30, ?, 'pendiente')\","
            " [$l, $a, date('Y-m-d'), $l, $b, date('Y-m-d')]);"
            "return ['a' => $a, 'b' => $b]; })()")
    A = str(al['a'])
    chk('f=informe&amp;alumno=' + A in e.get(B + 'admin.php?p=alumno&id=' + A).text, 'botones del Asistente en la ficha del alumno')
    n1 = llamadas()
    INF = 'Querida familia: Sofía asistió a 2 de 3 clases y está en nivel Intermedio.'
    r = enviar(e, 'admin.php?p=asistente&f=informe', {'accion': 'informe', 'alumno': A, 'nota': 'RESPONDER:' + INF}, desde='admin.php?p=asistente&f=informe')
    u = ultima()
    chk(llamadas() == n1 + 1 and INF in r.text and 'Revise el texto' in r.text, 'informe: 1 llamada y el texto para revisar', r.text[-300:])
    chk('2 de 3 clases (67%)' in u.get('texto', '') and 'Intermedio' in u.get('texto', '') and 'Mejoró mucho los giros' in u.get('texto', '') and '<alumno>' in u.get('texto', ''),
        'el informe se pide con la asistencia, el nivel y las notas del profesor', u.get('texto', '')[:500])
    chk('https://wa.me/593991234567?text=' in r.text, '«Enviar por WhatsApp» al representante')
    pantallas.append(r.text)
    COB = 'Hola Ana, le recordamos con cariño las pensiones de octubre: $65.00.'
    r = enviar(e, 'admin.php?p=asistente&f=cobro', {'accion': 'cobro', 'alumno': A, 'nota': 'RESPONDER:' + COB}, desde='admin.php?p=asistente&f=cobro')
    u = ultima()
    chk(llamadas() == n1 + 2 and COB in r.text, 'cobro amable: 1 llamada y el mensaje para revisar', r.text[-300:])
    chk('Sofía: Pensión octubre $35.00' in u.get('texto', '') and 'Tomás: Pensión octubre $30.00' in u.get('texto', '') and 'Total: $65.00' in u.get('texto', ''), 'con lo pendiente de los dos hermanos y el total exacto', u.get('texto', '')[:500])
    pantallas.append(r.text)
    php("q(\"UPDATE esc_cargos SET estado = 'pagado' WHERE alumno_id IN (" + A + ", " + str(al['b']) + ")\");")
    r = enviar(e, 'admin.php?p=asistente&f=cobro', {'accion': 'cobro', 'alumno': A}, desde='admin.php?p=asistente&f=cobro')
    chk(llamadas() == n1 + 2 and 'no tiene valores pendientes' in r.text, 'sin pendientes: lo dice y no llama')
    ia_env(TUKAN_IA_PAUSA='1')
    r = enviar(e, 'admin.php?p=asistente&f=informe', {'accion': 'informe', 'alumno': A}, desde='admin.php?p=asistente&f=informe')
    chk(llamadas() == n1 + 2 and 'En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí: <a href="admin.php?p=alumno&amp;id=' + A + '">' in r.text,
        'informe en pausa: mensaje neutro con el enlace a la ficha, sin llamar')
    pantallas.append(r.text)
    ia_env()

    # =====================================================================
    print('\n== Panel Tukán Central: ia_uso e ia_tope (Anexo C)')
    SECRETO = secrets.token_hex(32)
    open(CENTRAL_ENV, 'w').write('TUKAN_CENTRAL_ID=%s\nTUKAN_CENTRAL_SECRET=%s\nTUKAN_CENTRAL_URL=https://panel.tukanec.com\n' % (PROG, SECRETO))

    def b64(b):
        return base64.urlsafe_b64encode(b).decode().rstrip('=')

    def post2(accion, cuerpo):
        raw = json.dumps(cuerpo, ensure_ascii=False).encode()
        ahora = int(time.time())
        p = {'v': 2, 'tipo': accion, 'inst': PROG, 'email': 'dimitry@tukanec.com', 'nombre': 'Dimitry', 'rol': 'dueno', 'iat': ahora, 'exp': ahora + (30 if accion == 'ejecutar' else 60),
             'nonce': secrets.token_hex(16), 'bh': b64(hashlib.sha256(raw).digest())}
        a_ = b64(json.dumps(p, separators=(',', ':')).encode())
        t = a_ + '.' + b64(hmac.new(SECRETO.encode(), a_.encode(), hashlib.sha256).digest())
        r = requests.post(B + 'tukan_central.php', params={'accion': accion}, data=raw, headers={'Content-Type': 'application/json', 'X-Tukan-Token': t, 'CF-Connecting-IP': ip()}, timeout=30)
        try:
            return r, r.json()
        except Exception:
            return r, {}

    r, c = post2('capacidades', {})
    chk('ia_uso' in c.get('acciones', []) and 'ia_tope' in c.get('ejecutar', []) and c.get('revision') == 4, 'capacidades: ia_uso, ia_tope y revisión 4', c.get('acciones'))
    r, d = post2('ia_uso', {'mes': '2020-01'})
    chk(r.status_code == 200 and d.get('accion') == 'ia_uso' and d.get('negocios') == [] and d.get('totales', {}).get('llamadas') == 0 and d.get('modulo') == 'asistente_ia',
        'ia_uso de un mes sin datos: listas vacías', r.text[:300])
    r, d = post2('ia_uso', {})
    neg = {x['cliente_id']: x for x in d.get('negocios', [])}
    chk(r.status_code == 200 and PRIN_ID in neg and neg[PRIN_ID]['tope'] == 4.0 and neg[PRIN_ID]['modulo'] is True and d.get('configurada') is True and d.get('faq', {}).get('total', 0) >= 1,
        'ia_uso del mes: la cuenta con su nombre, tope 4.0 y el módulo; resumen de FAQ', r.text[:400])
    if not ACADEMIA:
        php("q(\"UPDATE tukan_ia_gasto SET costo_usd = 0.2 WHERE negocio_id = ?\", ['" + ESC_ID + "']); q(\"UPDATE tukan_ia_gasto SET costo_usd = 0.1 WHERE negocio_id = ?\", ['" + LIGA_ID + "']);")
        r, d = post2('ia_uso', {'mes': time.strftime('%Y-%m')})
        ids = [x['cliente_id'] for x in d.get('negocios', [])]
        chk(len(ids) >= 2 and ids.index(ESC_ID) < ids.index(LIGA_ID), 'ia_uso con dos cuentas: la que más gastó primero', ids)
    chk(CLAVE_IA not in r.text and 'sk-ant' not in r.text, 'ia_uso NO trae la clave')
    r1, _ = post2('ia_uso', {'mes': '2026-13'})
    r2, _ = post2('ia_uso', {'mes': '2026-10', 'otro': 1})
    chk(r1.status_code == 422 and r2.status_code == 422, 'mes inválido y campo desconocido -> 422', '%s %s' % (r1.status_code, r2.status_code))
    r, fic = post2('cliente', {'id': PRIN_ID})
    it = next((x for x in fic.get('cliente', {}).get('acciones_posibles', []) if x['accion'] == 'ia_tope'), None)
    chk(it is not None and it['campos'][0]['campo'] == 'tope_usd' and it['campos'][0]['tipo'] == 'dinero' and it['campos'][0]['valor'] == 4.0, 'ficha: botón «Tope de IA del mes» (tope_usd, dinero)', it)
    op = secrets.token_hex(16)
    r, d = post2('ejecutar', {'operacion_id': op, 'accion': 'ia_tope', 'cliente_id': PRIN_ID, 'datos': {'tope_usd': 5}})
    chk(r.status_code == 200 and d.get('resultado', {}).get('tope_usd') == 5.0 and float(val('SELECT ia_tope_usd FROM ligas WHERE id = ?', PRIN_ID) or -9) == 5.0
        and int(val("SELECT COUNT(*) FROM bitacora WHERE tabla = 'tukan_central' AND descripcion LIKE ?", 'Tope de IA del mes: $4.00 → $5.00 (op %s)' % op[:8])) == 1, 'ia_tope 5: se guarda y queda en la bitácora', r.text[:300])
    r, d = post2('ejecutar', {'operacion_id': op, 'accion': 'ia_tope', 'cliente_id': PRIN_ID, 'datos': {'tope_usd': 5}})
    chk(d.get('repetida') is True and int(val("SELECT COUNT(*) FROM bitacora WHERE descripcion LIKE 'Tope de IA del mes:%'")) == 1, 'la misma operación otra vez: repetida, no se hace dos veces')
    r, d = post2('ejecutar', {'operacion_id': secrets.token_hex(16), 'accion': 'ia_tope', 'cliente_id': PRIN_ID, 'datos': {'tope_usd': None}})
    chk(r.status_code == 200 and d.get('resultado', {}).get('tope_usd', 'x') is None and php("echo json_encode(ia_tope_cuenta(fila('SELECT * FROM ligas WHERE id = " + PRIN_ID + "')));") == 'null', 'ia_tope null: sin tope propio')
    malos = [post2('ejecutar', {'operacion_id': secrets.token_hex(16), 'accion': 'ia_tope', 'cliente_id': PRIN_ID, 'datos': x})[0].status_code for x in ({'tope_usd': -1}, {'tope_usd': '5'}, {'otro': 1})]
    chk(malos == [422, 422, 422], 'ia_tope -1, texto o sin el campo -> 422', malos)
    post2('ejecutar', {'operacion_id': secrets.token_hex(16), 'accion': 'ia_tope', 'cliente_id': PRIN_ID, 'datos': {'tope_usd': 0}})
    a = entrar(PRINCIPAL)
    n1 = llamadas()
    t0 = respuesta(enviar(a, 'admin.php?p=ayuda', {'pregunta': '¿Cómo cambio mi contraseña?'}))
    chk(llamadas() == n1 and 'Esto dice el manual de' in t0, 'con tope 0 la Ayuda sigue con el manual (sin llamar)', t0[:200])
    php("q('UPDATE ligas SET ia_tope_usd = NULL WHERE id = " + PRIN_ID + "');")
    sin(CENTRAL_ENV)

    # =====================================================================
    print('\n== «Enviar por correo» en «Demo lista» (súper → Ligas → Crear)')
    s = entrar('super@prueba.com')
    php("guardar_ajuste('smtp_host', ''); guardar_ajuste('smtp_remitente', '');")
    sin(CORREO_ENV)

    def crear_demo(nombre, correo, clave):
        d = {'accion': 'crear', 'nombre': nombre, 'modalidad': 'F7', 'tipo': 'escuela' if ACADEMIA else 'liga', 'admin_nombre': 'Carla Cliente', 'admin_email': correo,
             'admin_clave': clave, 'telefono': '0998887766', 'vigencia': 'prueba', 'dias_prueba': '7', 'color': '#123456'}
        return enviar(s, 'admin.php?p=ligas&nueva=1', d, desde='admin.php?p=ligas&nueva=1')

    CLAVE1 = 'ClaveSecreta' + secrets.token_hex(4)
    r = crear_demo('Demo Correo Uno', 'carla.uno@ejemplo.com', CLAVE1)
    chk('Configure el correo de Tukán' in r.text and 'disabled' in r.text and 'configurar_correo.sh' in r.text, 'sin correo configurado: botón desactivado «Configure el correo de Tukán» y el comando', r.text[-500:])
    open(CORREO_ENV, 'w').write('TUKAN_SMTP_HOST=127.0.0.1\nTUKAN_SMTP_PUERTO=%d\nTUKAN_SMTP_SEGURIDAD=ninguna\nTUKAN_SMTP_USUARIO=info@tukansoftware.com\nTUKAN_SMTP_CLAVE=clave-buzon\n'
                                'TUKAN_CORREO_DE=info@tukansoftware.com\nTUKAN_CORREO_NOMBRE=Tukán Software\n' % P_SMTP)
    CLAVE2 = 'ClaveSecreta' + secrets.token_hex(4)
    r = crear_demo('Demo Correo Dos', 'carla.dos@ejemplo.com', CLAVE2)
    m = re.search(r'name="sello" value="([^"]+)"', r.text)
    sello = m.group(1) if m else ''
    chk('Enviar por correo' in r.text and 'value="carla.dos@ejemplo.com"' in r.text and 'Le llega «Su demo de ' + NOMBRE + ' está lista» con sus datos de entrada, desde info@tukansoftware.com.' in r.text,
        'con el correo (data/tukan-correo.env): «Enviar por correo», el campo lleno y la ayuda', r.text[-800:])
    crudo = base64.urlsafe_b64decode(sello + '=' * (-len(sello) % 4)) if sello else b''
    chk(sello != '' and CLAVE2 not in sello and CLAVE2.encode() not in crudo and not re.search(r'<input[^>]+type="hidden"[^>]+value="' + CLAVE2, r.text), 'la contraseña va SELLADA (no en claro en un campo oculto)')
    lid2 = val("SELECT id FROM ligas WHERE nombre = 'Demo Correo Dos'")
    ses = ''.join(open(f, errors='replace').read() for f in glob.glob(os.path.join(DATA, 'sesiones', 'sess_*')))
    chk(CLAVE2 not in ses, 'la contraseña no queda en la sesión')
    r = s.post(B + 'admin.php?p=ligas', data={'accion': 'demo_correo', 'sello': sello, 'para': 'carla.dos@ejemplo.com'})
    chk(r.status_code == 400 and not os.listdir(BUZON), 'POST sin CSRF -> 400 y no manda nada')
    a = entrar(PRINCIPAL)
    r = enviar(a, 'admin.php?p=ligas', {'accion': 'demo_correo', 'sello': sello, 'para': 'carla.dos@ejemplo.com'}, desde='admin.php')
    chk(not os.listdir(BUZON), 'una cuenta (no el súper) no puede enviarlo')
    r = enviar(s, 'admin.php?p=ligas', {'accion': 'demo_correo', 'sello': sello, 'para': 'no-es-correo'}, desde='admin.php?p=ligas&nueva=1')
    chk('Escriba un correo válido.' in r.text and 'name="sello" value="' + sello + '"' in r.text and not os.listdir(BUZON), 'correo inválido: el error y el formulario otra vez')
    r = enviar(s, 'admin.php?p=ligas', {'accion': 'demo_correo', 'sello': sello, 'para': 'carla.dos@ejemplo.com'}, desde='admin.php?p=ligas&nueva=1')
    chk('Correo enviado a carla.dos@ejemplo.com desde info@tukansoftware.com. Que revise también la carpeta de spam.' in r.text, 'enviado: «Correo enviado a … desde info@tukansoftware.com…»', r.text[-500:])
    emls = sorted(glob.glob(os.path.join(BUZON, '*.eml')))
    msj = email.message_from_bytes(open(emls[-1], 'rb').read(), policy=email.policy.default) if emls else None
    cuerpo = msj.get_body(preferencelist=('plain',)).get_content() if msj else ''
    chk(msj is not None and msj['Subject'] == 'Su demo de ' + NOMBRE + ' está lista' and 'carla.dos@ejemplo.com' in str(msj['To']) and 'info@tukansoftware.com' in str(msj['From'])
        and CLAVE2 in cuerpo and 'Usuario: carla.dos@ejemplo.com' in cuerpo and 'gratis' not in cuerpo.lower(), 'llegó «Su demo de … está lista» con usuario y contraseña (sin «gratis»)', cuerpo[:300])
    chk(int(val("SELECT COUNT(*) FROM bitacora WHERE descripcion = 'Envió la demo por correo a carla.dos@ejemplo.com'")) == 1, 'auditoría: «Envió la demo por correo a …»')
    chk(int(val("SELECT COUNT(*) FROM bitacora WHERE descripcion LIKE ? OR antes LIKE ? OR despues LIKE ?", '%' + CLAVE2 + '%', '%' + CLAVE2 + '%', '%' + CLAVE2 + '%')) == 0,
        'la contraseña no está en la bitácora')
    r = enviar(s, 'admin.php?p=ligas', {'accion': 'demo_correo', 'sello': sello, 'para': 'carla.dos@ejemplo.com'}, desde='admin.php?p=ligas&nueva=1')
    chk('ya caducó' in r.text and len(glob.glob(os.path.join(BUZON, '*.eml'))) == 1, 'el mismo envío otra vez: «ya caducó» (una sola vez)')
    malo = sello[:-4] + ('AAAA' if not sello.endswith('AAAA') else 'BBBB')
    r = enviar(s, 'admin.php?p=ligas', {'accion': 'demo_correo', 'sello': malo, 'para': 'carla.dos@ejemplo.com'}, desde='admin.php?p=ligas&nueva=1')
    chk('no es válido' in r.text and len(glob.glob(os.path.join(BUZON, '*.eml'))) == 1, 'sello cambiado: «no es válido» y no manda nada')
    # el mailer propio del programa (Ajustes → Correo saliente) también sirve, sin romper lo que existe
    sin(CORREO_ENV)
    php("guardar_ajuste('smtp_host', '127.0.0.1'); guardar_ajuste('smtp_puerto', '" + str(P_SMTP) + "'); guardar_ajuste('smtp_seguridad', 'no'); guardar_ajuste('smtp_usuario', 'info@tukansoftware.com');"
        "guardar_ajuste('smtp_clave', 'clave-buzon'); guardar_ajuste('smtp_remitente', 'notificaciones@tuvocalia.com');")
    CLAVE3 = 'ClaveSecreta' + secrets.token_hex(4)
    r = crear_demo('Demo Correo Tres', 'carla.tres@ejemplo.com', CLAVE3)
    m = re.search(r'name="sello" value="([^"]+)"', r.text)
    chk(m is not None and 'desde notificaciones@tuvocalia.com' in r.text, 'sin el correo de Tukán, usa el correo propio del programa (Ajustes)')
    antes = len(glob.glob(os.path.join(BUZON, '*.eml')))
    r = enviar(s, 'admin.php?p=ligas', {'accion': 'demo_correo', 'sello': m.group(1) if m else '', 'para': 'carla.tres@ejemplo.com'}, desde='admin.php?p=ligas&nueva=1')
    nuevos = sorted(glob.glob(os.path.join(BUZON, '*.eml')), key=os.path.getmtime)
    txt = open(nuevos[-1], 'rb').read().decode('utf-8', 'replace') if len(nuevos) > antes else ''
    chk('Correo enviado a carla.tres@ejemplo.com desde notificaciones@tuvocalia.com' in r.text and len(nuevos) > antes, 'se envía con el mailer propio')
    php("guardar_ajuste('smtp_host', ''); guardar_ajuste('smtp_remitente', ''); guardar_ajuste('smtp_clave', '');")

    # =====================================================================
    print('\n== Nunca «límite», «cupo» ni «saldo» al cliente')
    # Solo el contenido de cada pantalla: el menú es de los otros módulos (ej. «Clases particulares… saldo de clases», que no es de la IA)
    pantallas = [re.sub(r'<nav class="menu">.*?</nav>', ' ', x, flags=re.S) for x in pantallas]
    malas = [PROHIBIDAS.search(re.sub(r'<[^>]+>', ' ', x)).group(0) for x in pantallas if PROHIBIDAS.search(re.sub(r'<[^>]+>', ' ', x))]
    chk(not malas, 'ninguna pantalla ni respuesta de la Ayuda / Asistente (%d revisadas)' % len(pantallas), malas)
    fuentes = ['app/asistente_ia.php', 'app/admin/ayuda.php', 'app/admin/asistente.php']
    texto_cliente = ''.join(re.sub(r"\s*(//|\*|/\*).*", '', l) for f in fuentes for l in open(os.path.join(REPO, f), encoding='utf-8'))
    chk(not re.search(r"'[^']*\b(límite|cupo|saldo)\b[^']*'", texto_cliente, re.I), 'tampoco en los textos de las pantallas nuevas')

    print('\n== Traducciones (5 idiomas)')
    faltan = php("$f = []; foreach (['en', 'fr', 'pt', 'it'] as $i) foreach (['¿En qué le ayudamos?', 'Escriba su pregunta', 'Preguntar', 'Asistente IA', 'Revise el texto', 'Enviar por correo', 'Correo del cliente', 'Configure el correo de Tukán', 'Pregunte cómo se hace algo'] as $t) if (tr($t, $i) === $t) $f[] = \"$i: $t\"; echo implode(' | ', $f);")
    chk(faltan == '', 'los textos nuevos están en inglés, francés, portugués e italiano', faltan)
finally:
    for p in procesos:
        p.terminate()
    for f in (IA_ENV, CORREO_ENV):
        sin(f)
    shutil.rmtree(TMP, ignore_errors=True)

log = os.path.join(REPO, 'pruebas', 'tukan_central', 'web.log')
for lg in (log, os.path.join(REPO, 'pruebas', 'v38', 'web.log')):
    if os.path.exists(lg) and os.path.getmtime(lg) > time.time() - 3600:
        txt = open(lg, errors='replace').read()
        chk(not ERRORES.search(txt), 'php -S sin errores de PHP (%s)' % os.path.relpath(lg, REPO), (ERRORES.search(txt).group(0) + ' ' + txt[max(0, ERRORES.search(txt).start() - 200):ERRORES.search(txt).start() + 300]) if ERRORES.search(txt) else '')
print('\nIA y correo — correctas: %d · Fallas: %d' % (ok, len(fallos)))
sys.exit(1 if fallos else 0)
