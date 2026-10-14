#!/usr/bin/env python3
"""
Pruebas de los arreglos del 14-oct-2026 en TuAcademia (contra php -S + simulador de Telegram).
Antes:  bash pruebas/v38/reiniciar.sh        (modo academia; crea el simulador de Google/Payphone/Telegram)
Correr: python3 pruebas/academia/prueba_arreglos_14oct.py

1. Prueba gratis = 7 días: ajuste, registro, portada, plan, manual, imagen; la migración v35 lo pone en 7 aunque estuviera en 14.
2. Textos de academia: «Resumen de la academia», perfiles sin equipos ni árbitros, sin botón Federación, Requisitos solo si aplica,
   carnet con edad (no Sub-7) en ballet, «pensión» en vez de «mensualidad» en las ayudas.
3. Fotos de la demo: de baile y música (no de fútbol) y la demo tiene clases con fotos.
4. Avisos por Telegram a los padres (Telegram simulado): conectar desde el portal (papá y mamá), pensión por vencer (días
   configurables), vencida, pago recibido, falta a clase (curso y particular), comunicados (todos / un curso), cada tipo se activa
   o desactiva, nunca se repite, si Telegram se cae nada se rompe y se reintenta, desconectar.
"""
import json, os, re, subprocess, sys, time
from datetime import date, timedelta
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
V38 = os.path.join(REPO, 'pruebas', 'v38')
SIM = os.path.join(V38, 'sim_estado.json')
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
ok, fallos = 0, []
HOY = date.today()


def chk(cond, nombre):
    global ok
    if cond:
        ok += 1
    else:
        fallos.append(nombre)
        print('FALLA:', nombre)


def limpio(r, nombre):
    chk(r.status_code < 500 and not ERRORES.search(r.text), nombre + ' sin errores PHP (' + str(r.status_code) + ')')
    m = ERRORES.search(r.text)
    if m:
        print('   →', r.text[max(0, m.start() - 100):m.end() + 300].replace('\n', ' '))


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html) or re.search(r'data-csrf="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout[-800:], r.stderr[-800:])
    return r.stdout.strip()


def pj(codigo):
    return json.loads(php('echo json_encode(' + codigo + ', JSON_UNESCAPED_UNICODE);') or 'null')


def entrar(email, clave='clave12345'):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': clave})
    chk('salir' in r.text.lower(), 'login ' + email)
    return s


def post(s, url, datos):
    r = s.get(B + url)
    datos = dict(datos)
    datos['csrf'] = csrf(r.text)
    return s.post(B + url, data=datos)


def sim():
    return json.load(open(SIM)) if os.path.exists(SIM) else {}


def sim_guardar(e):
    for k in ('tokens', 'subs', 'payphone'):
        e[k] = e.get(k) or {}
    json.dump(e, open(SIM, 'w'))


def tg_estado(**cambios):
    e = sim()
    e.setdefault('telegram', {})
    e['telegram'].setdefault('enviados', [])
    e['telegram'].setdefault('updates', [])
    e['telegram'].update(cambios)
    sim_guardar(e)


def tg_enviados():
    return sim().get('telegram', {}).get('enviados', [])


def tg_limpiar():
    tg_estado(enviados=[], updates=[], falla=False)


def tarea():
    """Lo que hace la tarea automática cada minuto con Telegram: leer /start y enviar la cola."""
    php('tg_leer_mensajes(); tg_procesar_cola(200);')


UPD = [1000]


def tg_start(chat, texto, nombre='Papá'):
    UPD[0] += 1
    e = sim()
    e.setdefault('telegram', {}).setdefault('updates', []).append(
        {'update_id': UPD[0], 'message': {'message_id': UPD[0], 'from': {'id': int(chat), 'first_name': nombre}, 'chat': {'id': int(chat), 'type': 'private', 'first_name': nombre}, 'text': texto}})
    sim_guardar(e)
    tarea()


def para(chat):
    return [m['text'] for m in tg_enviados() if m['chat_id'] == str(chat)]


anon = requests.Session()

# =====================================================================
# 1. PRUEBA GRATIS = 7 DÍAS
# =====================================================================
chk(pj('ajuste("dias_prueba")') in ('7', 7), 'ajuste dias_prueba = 7 en una base nueva')
chk(pj('ajustes_por_defecto()["dias_prueba"]') == 7, 'valor por defecto del código = 7')
# Una base vieja con 14: la migración v35 lo deja en 7
php('guardar_ajuste("dias_prueba", 14); db()->exec("PRAGMA user_version = 34"); esquema_v35(db()); db()->exec("PRAGMA user_version = 35");')
chk(pj('ajuste("dias_prueba")') == '7', 'migración v35: si estaba en 14 queda en 7')
r = anon.get(B + 'index.php?v=registro')
limpio(r, 'registro')
chk('gratis por 7 días' in r.text and '14 días' not in r.text and '30 días' not in r.text, 'registro: «gratis por 7 días»')
r = anon.get(B + 'index.php')
chk('7 días' in r.text and '14 días' not in r.text, 'portada: 7 días')
r = anon.get(B + 'index.php?v=manual&m=escuelas')
limpio(r, 'manual de academias')
chk('14 días' not in r.text, 'manual: ya no dice 14 días')
chk('7 días' in open(os.path.join(REPO, 'app/manuales/escuelas.html'), encoding='utf-8').read(), 'manual (archivo): prueba gratis de 7 días')
# Ningún valor por defecto de 30 o 14 días para la prueba en el código
codigo = subprocess.run(['grep', '-rnE', r"ajuste\('dias_prueba', (14|30)\)", 'app', 'public'], cwd=REPO, capture_output=True, text=True).stdout
chk(codigo.strip() == '', 'ningún ajuste(\'dias_prueba\', 14/30) en el código')
# Si el ajuste no existiera, todo dice 7
php('q("DELETE FROM ajustes WHERE clave = \'dias_prueba\'"); ajuste("__recargar");')
r = anon.get(B + 'index.php?v=registro')
chk('gratis por 7 días' in r.text, 'sin el ajuste guardado, el registro dice 7 días')
php('guardar_ajuste("dias_prueba", 7);')
chk('7 días' in pj('texto_empezar()') or 'Prueba gratis' in pj('texto_empezar()'), 'botón de empezar: ' + str(pj('texto_empezar()')))
# Script del VPS: existe, sin tildes y con sintaxis correcta
sh = os.path.join(REPO, 'vps/vps/poner_prueba_7_dias.sh')
chk(os.path.exists(sh) and subprocess.run(['bash', '-n', sh]).returncode == 0, 'vps/vps/poner_prueba_7_dias.sh existe y su sintaxis es correcta')
chk(all(ord(c) < 128 for c in open(sh, encoding='utf-8').read()), 'el script del VPS no tiene tildes ni ñ')

# =====================================================================
# 2. TEXTOS DE ACADEMIA
# =====================================================================
php('crear_demos();')
demo = pj('escuela_demo()')
chk(demo and demo['nombre'].startswith('Academia Demo'), 'academia de demostración creada')
LD = int(demo['id'])
php('$u = fila("SELECT id FROM usuarios WHERE email = \'escuela@demo.tuacademia.tukanec.com\'"); q("UPDATE usuarios SET clave = ? WHERE id = ?", [password_hash("clave12345", PASSWORD_DEFAULT), $u["id"]]);')
ad = entrar('escuela@demo.tuacademia.tukanec.com')
r = ad.get(B + 'admin.php')
limpio(r, 'resumen de la academia')
chk('<title>Resumen de la academia' in r.text and 'Resumen de la escuela' not in r.text, 'título: «Resumen de la academia»')
chk('p=federacion' not in r.text, 'menú sin «Federación»')
chk('p=requisitos' not in r.text, 'menú sin «Requisitos y documentos» (baile y música no piden nada obligatorio)')
chk('p=avisos_padres' in r.text and 'Avisos por Telegram' in r.text, 'menú con «Avisos por Telegram»')
r = ad.get(B + 'admin.php?p=usuarios')
limpio(r, 'usuarios')
chk(not re.search(r'equipos|campeonato|fixture|árbitro|delegado', r.text, re.I), 'Usuarios: los perfiles no hablan de equipos, campeonatos, fixture ni árbitros')
chk('Pagos: pensiones' in r.text and 'Toma asistencia' in r.text, 'Usuarios: descripciones de academia (tesorero, profesor)')
r = ad.get(B + 'admin.php?p=escuela_config')
limpio(r, 'parámetros')
chk('🏛 Federación' not in r.text, 'Parámetros sin la tarjeta de Federación')
chk('ensualidad' not in re.sub(r'<(input|select|textarea)[^>]*>', '', r.text), 'Parámetros: ninguna ayuda dice «mensualidad»')
chk('segunda disciplina' in r.text and 'esa pensión lleva este descuento' in r.text, 'Parámetros: descuento por segunda disciplina … «esa pensión»')
r = ad.get(B + 'admin.php?p=federacion')
chk('Afiliarse' not in r.text and 'FED-' not in r.text, 'la página de federación no se abre en TuAcademia')
r = ad.get(B + 'admin.php?p=cobros')
limpio(r, 'pagos')
chk('Crear pensiones de ese mes' in r.text and 'Crear mensualidades' not in r.text, 'Pagos: «Crear pensiones de ese mes»')
# Carnet en ballet: edad, no «Sub-7»
emilia = pj('fila("SELECT * FROM esc_alumnos WHERE liga_id = ? AND nombres = \'Emilia\'", [' + str(LD) + '])')
php('q("UPDATE esc_alumnos SET carnet_token = ? WHERE id = ?", ["' + 'a' * 20 + '", ' + str(emilia['id']) + ']);')
r = anon.get(B + 'carnet.php?a=' + 'a' * 20)
limpio(r, 'carnet de Emilia')
edad_emilia = pj('edad("' + emilia['fecha_nac'] + '")')
chk('Sub-' not in r.text and (str(edad_emilia) + ' años') in r.text, 'carnet en ballet: «%s años», sin «Sub-»' % edad_emilia)
r = ad.get(B + 'admin.php?p=esc_carnets&alumno=' + str(emilia['id']))
chk('Sub-' not in r.text and (str(edad_emilia) + ' años') in r.text, 'carnet para imprimir: edad, sin «Sub-»')
r = ad.get(B + 'admin.php?p=alumno&id=' + str(emilia['id']))
limpio(r, 'ficha de Emilia')
chk('Sub-' not in r.text, 'ficha del alumno de ballet sin «Sub-»')
# En una escuela de fútbol de TuAcademia se sigue usando «Sub-N»
php('$r = crear_liga_completa(["nombre" => "Escuela Fútbol Norte", "modalidad" => "F7", "admin_nombre" => "Admin Fútbol", "admin_email" => "futbol14@prueba.com", "admin_clave" => "clave12345", "deporte_escuela" => "futbol", "deportes" => ["futbol"]]);'
    ' $g = q("INSERT INTO esc_grupos (liga_id, nombre, categoria, deporte) VALUES (?, \'Sub-10\', \'sub\', \'futbol\')", [$r["liga_id"]]); $gid = db()->lastInsertId();'
    ' q("INSERT INTO esc_alumnos (liga_id, grupo_id, nombres, apellidos, fecha_nac, carnet_token) VALUES (?,?,\'Mateo\',\'Paz\',?,?)", [$r["liga_id"], $gid, (date("Y") - 10) . "-03-01", "' + 'b' * 20 + '"]);')
r = anon.get(B + 'carnet.php?a=' + 'b' * 20)
chk('Sub-10' in r.text, 'carnet en fútbol: sigue «Sub-10»')
# Requisitos sí aparece si la academia tiene artes marciales (piden certificado médico)
chk(pj('acad_requisitos_aplican(fila("SELECT * FROM ligas WHERE id = ?", [' + str(LD) + ']))') is False, 'requisitos no aplican en baile y música')
php('q("INSERT INTO esc_grupos (liga_id, nombre, categoria, deporte) VALUES (?, \'Taekwondo\', \'abierta\', \'taekwondo\')", [' + str(LD) + ']);')
chk(pj('acad_requisitos_aplican(fila("SELECT * FROM ligas WHERE id = ?", [' + str(LD) + ']))') is True, 'requisitos sí aplican si hay taekwondo')
php('q("DELETE FROM esc_grupos WHERE liga_id = ? AND deporte = \'taekwondo\'", [' + str(LD) + ']);')

# =====================================================================
# 3. FOTOS DE LA DEMO: baile y música
# =====================================================================
fotos = pj('array_column(filas("SELECT DISTINCT f.archivo FROM esc_sesion_fotos f JOIN esc_sesiones s ON s.id = f.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id WHERE g.liga_id = ?", [' + str(LD) + ']), "archivo")')
chk(len(fotos) >= 4, 'la demo tiene clases con fotos (%d)' % len(fotos))
for n in range(1, 7):
    ruta = os.path.join(REPO, 'app/demo/fotos_clase/clase%d.jpg' % n)
    chk(os.path.exists(ruta) and os.path.getsize(ruta) > 10000, 'foto de ejemplo clase%d.jpg' % n)
fuentes = ' '.join(open(os.path.join(REPO, 'app/demo/fuentes_ilustraciones/clase%d.html' % n), encoding='utf-8').read() for n in range(1, 7))
chk(all(x in fuentes for x in ['ballet', 'piano', 'salsa', 'guitarra', 'canto']) and 'slalom' not in fuentes.lower() and 'balón' not in fuentes.lower(), 'las fotos son de ballet, piano, salsa, guitarra y canto (no de fútbol)')
fam_ced = pj('valor("SELECT representante_cedula FROM esc_alumnos WHERE id = ?", [' + str(emilia['id']) + '])')

# =====================================================================
# 4. AVISOS POR TELEGRAM A LOS PADRES (Telegram simulado)
# =====================================================================
fp = requests.Session()
r = fp.get(B + 'familia.php')
r = fp.post(B + 'familia.php', data={'csrf': csrf(r.text), 'accion': 'ingresar', 'cedula': fam_ced, 'pin': pj('DEMO_CLAVE')})
chk('Emilia' in r.text, 'portal de padres de la familia demo')
chk('Conectar Telegram' not in r.text, 'sin bot configurado no aparece «Conectar Telegram» (no se rompe nada)')
r = ad.get(B + 'admin.php?p=avisos_padres')
limpio(r, 'avisos a padres sin bot')
chk('todavía no están encendidos' in r.text, 'pantalla de avisos explica que falta encender Telegram')

php('guardar_ajuste("tg_token", "123:ABC"); guardar_ajuste("tg_usuario", "TuAcademiaBot"); guardar_ajuste("tg_offset", 0);')
tg_limpiar()
r = fp.get(B + 'familia.php')
limpio(r, 'portal con Telegram')
chk('Avisos por Telegram' in r.text and 'Conectar Telegram' in r.text, 'portal: botón «Conectar Telegram»')
m = re.search(r'https://t\.me/TuAcademiaBot\?start=fam([a-f0-9]{16})', r.text)
chk(m is not None, 'enlace al bot con el código de la familia')
cod = m.group(1) if m else ''
fid = int(pj('valor("SELECT id FROM esc_familias WHERE liga_id = ? AND cedula = ?", [' + str(LD) + ', "' + fam_ced + '"])'))
# Papá se conecta
tg_start(5001, '/start fam' + cod, 'Carlos')
chk(pj('acad_tg_chats_familia(' + str(fid) + ')') == ['5001'], 'Telegram de papá conectado')
chk(any('Listo' in t and 'Academia Demo' in t for t in para(5001)), 'el bot le confirma a papá')
# Mamá con el mismo enlace
tg_start(5002, '/start fam' + cod, 'Patricia')
chk(pj('acad_tg_chats_familia(' + str(fid) + ')') == ['5001', '5002'], 'Telegram de mamá también conectado (mismo enlace)')
# Código inválido y desde un grupo
tg_start(5009, '/start fam' + '0' * 16, 'X')
chk(any('ya no es válido' in t for t in para(5009)), 'código inválido: mensaje claro')
r = fp.get(B + 'familia.php')
chk('Conectados' in r.text and 'Carlos' in r.text and 'Patricia' in r.text, 'portal muestra quién está conectado')
# No rompió la vinculación de usuarios del panel (/start CODIGO de 16 hex)
cu = pj('tg_enlace_usuario(fila("SELECT * FROM usuarios WHERE email = \'escuela@demo.tuacademia.tukanec.com\'"))')
tg_start(6001, '/start ' + cu.split('start=')[1], 'Dueña')
chk(pj('valor("SELECT tg_chat FROM usuarios WHERE email = \'escuela@demo.tuacademia.tukanec.com\'")') == '6001', 'la vinculación de usuarios del panel sigue funcionando')

liga = 'fila("SELECT * FROM ligas WHERE id = ' + str(LD) + '")'
# --- Pensión por vencer (3 días por defecto) ---
tg_limpiar()
php('q("DELETE FROM acad_tg_avisos");')
alumnas = pj('array_column(filas("SELECT id FROM esc_alumnos WHERE liga_id = ? AND representante_cedula = ?", [' + str(LD) + ', "' + fam_ced + '"]), "id")')
ids_in = ','.join(str(x) for x in alumnas)
php('q("UPDATE esc_cargos SET estado = \'pagado\' WHERE alumno_id IN (' + ids_in + ')");')
php('q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, vence, estado) VALUES (?,?, \'otro\', \'\', \'Pensión de prueba A\', 40, ?, \'pendiente\')", [' + str(LD) + ', ' + str(alumnas[0]) + ', date("Y-m-d", strtotime("+2 days"))]);')
php('q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, vence, estado) VALUES (?,?, \'otro\', \'\', \'Pensión de prueba B\', 55, ?, \'pendiente\')", [' + str(LD) + ', ' + str(alumnas[1]) + ', date("Y-m-d", strtotime("+6 days"))]);')
php('q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, vence, estado) VALUES (?,?, \'otro\', \'\', \'Pensión de prueba C\', 40, ?, \'pendiente\')", [' + str(LD) + ', ' + str(alumnas[0]) + ', date("Y-m-d", strtotime("-1 day"))]);')
res = pj('acad_tg_avisos_pensiones(' + liga + ')')
tarea()
t1 = para(5001)
chk(res.get('por_vencer') == 1 and res.get('vencida') == 1, 'pensiones: 1 aviso por vencer y 1 vencida (%s)' % res)
chk(any('Recordatorio de pago' in t and 'Pensión de prueba A' in t and 'Pensión de prueba B' not in t for t in t1), 'por vencer: la de 2 días sí, la de 6 días no (3 días antes)')
chk(any('Pago vencido' in t and 'Pensión de prueba C' in t for t in t1), 'vencida: aviso de la pensión vencida')
chk(any('DeUna al 0990000000' in t and 'familia.php' in t for t in t1), 'el aviso dice cómo pagar (DeUna) y el enlace al portal')
chk(len(para(5002)) == len(t1), 'mamá recibe lo mismo que papá')
# No se repite
n_antes = len(tg_enviados())
res2 = pj('acad_tg_avisos_pensiones(' + liga + ')')
tarea()
chk(res2 == {'por_vencer': 0, 'vencida': 0} and len(tg_enviados()) == n_antes, 'al correr otra vez no se repite ningún aviso')
# Días configurables: con 7 días entra la B (y la A no se repite)
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'opciones', 'activo': '1', 'dias': '7', 't_por_vencer': '1', 't_vencida': '1', 't_pago': '1', 't_falta': '1', 't_comunicado': '1'})
limpio(r, 'guardar opciones de avisos')
chk(pj('acad_tg_opciones(' + liga + ')["dias"]') == 7, 'días configurables guardados (7)')
tg_limpiar()
pj('acad_tg_avisos_pensiones(' + liga + ')')
tarea()
t2 = para(5001)
chk(len(t2) == 1 and 'Pensión de prueba B' in t2[0] and 'Pensión de prueba A' not in t2[0], 'con 7 días: llega solo la pensión B (la A ya se avisó)')
# Comprobante en revisión: no se recuerda
php('q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, descripcion, monto, vence, estado, comprobante_estado) VALUES (?,?, \'otro\', \'Pensión con comprobante\', 30, ?, \'pendiente\', \'pendiente\')", [' + str(LD) + ', ' + str(alumnas[0]) + ', date("Y-m-d", strtotime("+1 day"))]);')
tg_limpiar()
pj('acad_tg_avisos_pensiones(' + liga + ')')
tarea()
chk(not any('Pensión con comprobante' in t for t in para(5001)), 'si la familia ya subió el comprobante, no se le recuerda')

# --- Pago recibido ---
tg_limpiar()
cid_a = pj('valor("SELECT id FROM esc_cargos WHERE descripcion = \'Pensión de prueba A\'")')
r = post(ad, 'admin.php?p=alumno&id=' + str(alumnas[0]), {'accion': 'cobrar', 'cargos[]': [str(cid_a)], 'forma_pago': 'efectivo'})
limpio(r, 'cobrar desde la ficha')
chk(pj('valor("SELECT estado FROM esc_cargos WHERE id = ?", [' + str(cid_a) + '])') == 'pagado', 'pensión A cobrada')
tarea()
t3 = para(5001)
chk(any('Pago recibido' in t and 'Pensión de prueba A' in t and 'Recibo N°' in t and 'efectivo' in t for t in t3), 'aviso de pago recibido con el recibo y la forma de pago')
# Comprobante aprobado → pago recibido
tg_limpiar()
cid_c = pj('valor("SELECT id FROM esc_cargos WHERE descripcion = \'Pensión con comprobante\'")')
php('q("UPDATE esc_cargos SET comprobante = \'comprobantes/x.jpg\', comprobante_forma = \'deuna\' WHERE id = ?", [' + str(cid_c) + ']);')
r = post(ad, 'admin.php?p=cobros&ver=comprobantes', {'accion': 'aprobar_comp', 'id': str(cid_c)})
limpio(r, 'aprobar comprobante')
tarea()
chk(any('Pago recibido' in t and 'Pensión con comprobante' in t and 'deuna' in t.lower() for t in para(5001)), 'aprobar el comprobante avisa «Pago recibido» (DeUna)')

# --- Falta a clase (curso) ---
tg_limpiar()
g0 = pj('fila("SELECT g.* FROM esc_grupos g JOIN esc_alumnos a ON a.grupo_id = g.id WHERE a.id = ?", [' + str(emilia['id']) + '])')
otros = pj('array_column(alumnos_grupo(' + str(g0['id']) + '), "id")')
est = {'est[%d]' % a: ('A' if a == emilia['id'] else 'P') for a in otros}
r = ad.get(B + 'admin.php?p=asistencia&g=%d&fecha=%s' % (g0['id'], HOY))
d = {'csrf': csrf(r.text), 'accion': 'guardar', 'grupo_id': str(g0['id']), 'fecha': str(HOY), 'profesor_asistio': '1'}
d.update(est)
r = ad.post(B + 'admin.php?p=asistencia&g=%d&fecha=%s' % (g0['id'], HOY), data=d)
limpio(r, 'guardar asistencia')
tarea()
t4 = para(5001)
chk(len([t for t in t4 if 'Falta a clase' in t]) == 1 and 'Emilia no vino hoy' in ' '.join(t4) and g0['nombre'] in ' '.join(t4), 'aviso de falta: «Emilia no vino hoy a la clase de %s»' % g0['nombre'])
# Volver a guardar la misma asistencia no repite el aviso
r = ad.get(B + 'admin.php?p=asistencia&g=%d&fecha=%s' % (g0['id'], HOY))
d['csrf'] = csrf(r.text)
ad.post(B + 'admin.php?p=asistencia&g=%d&fecha=%s' % (g0['id'], HOY), data=d)
tarea()
chk(len([t for t in para(5001) if 'Falta a clase' in t]) == 1, 'guardar otra vez la asistencia no repite el aviso de falta')
# Una asistencia de hace una semana no avisa (sería un aviso viejo)
tg_limpiar()
vieja = str(HOY - timedelta(days=7))
r = ad.get(B + 'admin.php?p=asistencia&g=%d&fecha=%s' % (g0['id'], vieja))
d2 = dict(d, csrf=csrf(r.text), fecha=vieja)
ad.post(B + 'admin.php?p=asistencia&g=%d&fecha=%s' % (g0['id'], vieja), data=d2)
tarea()
chk(not any('Falta a clase' in t for t in para(5001)), 'asistencia de hace una semana: no manda aviso de falta')

# --- Falta a clase particular ---
tg_limpiar()
pj('marcar_clase_particular(' + liga + ', ' + str(alumnas[1]) + ', "falto", date("Y-m-d"), "16:00", null, "Escalas", "Prueba")')
tarea()
chk(any('Falta a clase particular' in t and '16:00' in t for t in para(5001)), 'falta a una clase particular: aviso')

# --- Comunicados ---
tg_limpiar()
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'comunicado', 'grupo_id': '0', 'titulo': 'Feriado', 'texto': 'Mañana no hay clases por el feriado.', 'token': 'abc123'})
limpio(r, 'enviar comunicado')
chk('Comunicado en camino a 1 familia' in r.text, 'comunicado para todas las familias (1 con Telegram)')
tarea()
chk(any('Feriado' in t and 'no hay clases' in t for t in para(5001)) and any('Feriado' in t for t in para(5002)), 'comunicado recibido por papá y mamá')
# Mismo token (doble clic): no se repite
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'comunicado', 'grupo_id': '0', 'titulo': 'Feriado', 'texto': 'Mañana no hay clases por el feriado.', 'token': 'abc123'})
tarea()
chk(len([t for t in para(5001) if 'Feriado' in t]) == 1, 'el mismo comunicado (doble toque) no se manda dos veces')
# Para un curso donde la familia no tiene hijos: no le llega
otro_g = pj('valor("SELECT id FROM esc_grupos WHERE liga_id = ? AND id NOT IN (SELECT grupo_id FROM esc_alumnos WHERE representante_cedula = ? AND grupo_id IS NOT NULL) AND activo = 1 LIMIT 1", [' + str(LD) + ', "' + fam_ced + '"])')
tg_limpiar()
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'comunicado', 'grupo_id': str(otro_g), 'titulo': 'Ensayo', 'texto': 'Ensayo de salsa el sábado.', 'token': 'def456'})
tarea()
chk(not any('Ensayo' in t for t in para(5001)), 'comunicado para otro curso: no le llega a esta familia')
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'comunicado', 'grupo_id': str(g0['id']), 'titulo': 'Ensayo ballet', 'texto': 'Ensayo de ballet el sábado.', 'token': 'ghi789'})
tarea()
chk(any('Ensayo ballet' in t for t in para(5001)), 'comunicado para el curso de su hija: sí le llega')

# --- Cada tipo se activa o desactiva ---
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'opciones', 'activo': '1', 'dias': '3', 't_por_vencer': '1', 't_vencida': '1', 't_comunicado': '1'})  # sin pago ni falta
chk(pj('acad_tg_opciones(' + liga + ')') == {'activo': 1, 'dias': 3, 'por_vencer': 1, 'vencida': 1, 'pago': 0, 'falta': 0, 'comunicado': 1}, 'opciones: pago y falta apagados')
tg_limpiar()
cid_b = pj('valor("SELECT id FROM esc_cargos WHERE descripcion = \'Pensión de prueba B\'")')
post(ad, 'admin.php?p=alumno&id=' + str(alumnas[1]), {'accion': 'cobrar', 'cargos[]': [str(cid_b)], 'forma_pago': 'efectivo'})
pj('marcar_clase_particular(' + liga + ', ' + str(alumnas[1]) + ', "falto", date("Y-m-d"), "17:00", null, "Escalas", "Prueba")')
tarea()
chk(pj('valor("SELECT estado FROM esc_cargos WHERE id = ?", [' + str(cid_b) + '])') == 'pagado' and not para(5001), 'con «pago» y «falta» apagados no llega nada (y el cobro se hizo igual)')
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'opciones', 'dias': '3', 't_por_vencer': '1'})  # todo apagado
chk(pj('acad_tg_disponible(' + liga + ')') is False, 'interruptor general apagado')
r = fp.get(B + 'familia.php')
chk('Conectar' not in r.text, 'con los avisos apagados el portal no muestra la tarjeta de Telegram')
post(ad, 'admin.php?p=avisos_padres', {'accion': 'opciones', 'activo': '1', 'dias': '3', 't_por_vencer': '1', 't_vencida': '1', 't_pago': '1', 't_falta': '1', 't_comunicado': '1'})
r = ad.get(B + 'admin.php?p=avisos_padres')
limpio(r, 'pantalla de avisos')
chk('1 / ' in r.text and 'Últimos avisos' in r.text and 'Pago recibido' in r.text, 'pantalla: familias conectadas y últimos avisos')

# --- Si Telegram falla, nada se rompe ---
tg_limpiar()
tg_estado(falla=True)
php('q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, descripcion, monto, vence, estado) VALUES (?,?, \'otro\', \'Pensión con Telegram caído\', 20, ?, \'pendiente\')", [' + str(LD) + ', ' + str(alumnas[0]) + ', date("Y-m-d")]);')
cid_f = pj('valor("SELECT id FROM esc_cargos WHERE descripcion = \'Pensión con Telegram caído\'")')
r = post(ad, 'admin.php?p=alumno&id=' + str(alumnas[0]), {'accion': 'cobrar', 'cargos[]': [str(cid_f)], 'forma_pago': 'transferencia'})
limpio(r, 'cobrar con Telegram caído')
chk(pj('valor("SELECT estado FROM esc_cargos WHERE id = ?", [' + str(cid_f) + '])') == 'pagado', 'con Telegram caído el cobro se guarda igual')
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'comunicado', 'grupo_id': '0', 'titulo': 'Prueba caída', 'texto': 'Mensaje con Telegram caído.', 'token': 'f00d01'})
limpio(r, 'comunicado con Telegram caído')
tarea()
pend = pj('fila("SELECT COUNT(*) AS n, MAX(intentos) AS i, MAX(error) AS e FROM telegram_cola WHERE enviado = \'\' AND chat_id IN (\'5001\', \'5002\')")')
chk(pend['n'] >= 2 and pend['i'] >= 1 and 'caído' in (pend['e'] or ''), 'los avisos quedan en la cola con el error para reintentar (%s)' % pend)
r = anon.get(B + 'index.php')
chk(r.status_code == 200, 'el sitio sigue funcionando con Telegram caído')
tg_estado(falla=False)
tarea()
chk(any('Pago recibido' in t and 'Telegram caído' in t for t in para(5001)) and any('Prueba caída' in t for t in para(5001)), 'cuando Telegram vuelve, se envían los avisos pendientes')
# Un token que no responde (sin red): acad_tg_avisar no lanza error
chk(pj('acad_tg_avisar(' + liga + ', 999999, "comunicado", ["x"], "hola")') == 0, 'familia sin Telegram: no hace nada y no da error')

# --- Desconectar ---
r = fp.get(B + 'familia.php')
r = fp.post(B + 'familia.php', data={'csrf': csrf(r.text), 'accion': 'tg_desconectar'})
limpio(r, 'desconectar Telegram')
chk(pj('acad_tg_chats_familia(' + str(fid) + ')') == [] and 'ya no recibirá avisos' in r.text, 'la familia se desconecta desde el portal')
tg_limpiar()
r = post(ad, 'admin.php?p=avisos_padres', {'accion': 'comunicado', 'grupo_id': '0', 'titulo': 'Después', 'texto': 'Ya no debería llegar.', 'token': 'beef02'})
tarea()
chk(not para(5001) and not para(5002), 'desconectada: ya no le llegan avisos')
tg_start(5001, '/start fam' + cod, 'Carlos')
chk(pj('acad_tg_chats_familia(' + str(fid) + ')') == [], 'el enlace viejo ya no sirve después de desconectar')

# --- Celular: la tarjeta de Telegram del portal y la pantalla de avisos sin errores ---
r = fp.get(B + 'familia.php')
chk('Conectar Telegram' in r.text, 'después de desconectar puede volver a conectar (enlace nuevo)')

# --- Textos de venta ---
r = anon.get(B + 'index.php')
chk('Avisos por Telegram' in r.text and 'falta a clase' in r.text, 'portada: describe los avisos por Telegram a los padres')
desc = pj('modulos_sistema("escuela")["avisos"][3]')
chk('portal' in desc and 'pensión por vencer' in desc and 'falta a clase' in desc and 'comunicados' in desc, 'módulo «Avisos por Telegram»: describe lo que hace')

# Errores de PHP en el registro del servidor
log = os.path.join(V38, 'web.log')
txt = open(log, encoding='utf-8', errors='replace').read() if os.path.exists(log) else ''
errs = [l for l in txt.splitlines() if ERRORES.search(l)]
chk(not errs, 'php -S sin errores de PHP' + (': ' + errs[0][:200] if errs else ''))

print('\nRESULTADO prueba_arreglos_14oct: %d/%d' % (ok, ok + len(fallos)))
sys.exit(1 if fallos else 0)
