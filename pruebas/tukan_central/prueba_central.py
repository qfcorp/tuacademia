#!/usr/bin/env python3
"""
Pruebas del conector con el Panel Tukán Central (protocolo v1, con los cambios compatibles de v2) contra php -S. El mismo archivo sirve para TuVocalía y TuAcademia
(en TuAcademia se corre en los dos modos: academia, que es el de verdad, y ligas).

Antes (base nueva):
  TuVocalía:   TA_PUERTO=8221 bash pruebas/tukan_central/reiniciar.sh
  TuAcademia:  TA_PUERTO=8221 TA_SIM=8222 bash pruebas/v38/reiniciar.sh              (modo academia)
               TA_PRODUCTO=ligas TA_PUERTO=8221 TA_SIM=8222 bash pruebas/v38/reiniciar.sh  (modo ligas)
Correr:        TA_PUERTO=8221 python3 pruebas/tukan_central/prueba_central.py

Prueba (protocolo §6 y más): sin configurar → 404; vps/conectar_central.sh (sin Docker) conecta, se prueba solo, se puede repetir,
rechaza datos malos y sus mensajes no tienen tildes; token correcto entra; firma mala, otra instancia, tipo cambiado, vencido, futuro,
demasiado largo, nonce repetido o malo, correo inválido, versión distinta y basura → rechazados (401, con «codigo»); límite de 30 rechazados por minuto por IP (429);
estado con todas las claves y las cifras exactas de los datos de prueba; SSO crea el super la primera vez y lo reutiliza después,
cambia el id de sesión, deja constancia en la bitácora y muestra «← Panel Tukán»; un correo de cliente no se convierte en super;
TUKAN_SOLO_CENTRAL=1 apaga el ingreso local del super con un mensaje claro y los clientes siguen entrando; el ingreso normal no cambia.
Al final revisa que php -S no haya registrado errores de PHP.
"""
import base64, hashlib, hmac, json, os, re, secrets, subprocess, sys, time
from datetime import date, datetime
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
ENV = os.path.join(REPO, 'data', 'tukan_central.env')
SCRIPT = os.path.join(REPO, 'vps', 'conectar_central.sh')
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
URL_CENTRAL = 'https://panel.tukanec.com'
SECRETO = secrets.token_hex(32)
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
        print('PHP CLI:', r.stdout[-800:], r.stderr[-800:])
    return r.stdout.strip()


def pj(codigo):
    return json.loads(php('echo json_encode(' + codigo + ', JSON_UNESCAPED_UNICODE);') or 'null')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def ip():
    return '10.%d.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250), 1 + secrets.randbelow(250))


def b64(b):
    return base64.urlsafe_b64encode(b).decode().rstrip('=')


def firmar(p, secreto=None):
    a = b64(json.dumps(p, separators=(',', ':'), ensure_ascii=False).encode())
    return a + '.' + b64(hmac.new((secreto or SECRETO).encode(), a.encode(), hashlib.sha256).digest())


INST = ''


def token(tipo='estado', secreto=None, **cambios):
    ahora = int(time.time())
    p = {'v': 1, 'tipo': tipo, 'inst': INST, 'email': 'dimitryfierro@gmail.com', 'nombre': 'Dimitry', 'iat': ahora, 'exp': ahora + 60, 'nonce': secrets.token_hex(16)}
    p.update(cambios)
    return firmar(p, secreto)


def pedir(accion, t, s=None, desde=None):
    return (s or requests).get(B + 'tukan_central.php', params={'accion': accion, 't': t}, headers={'CF-Connecting-IP': desde or ip()}, allow_redirects=False)


def conectar(*args, extra=None):
    env = dict(os.environ, TC_SIN_ROOT='1', TC_DESTINO=REPO, TC_CONTENEDOR='', TC_PUERTO=PUERTO, TC_SITIO='127.0.0.1')
    env.update(extra or {})
    r = subprocess.run(['bash', SCRIPT] + list(args), capture_output=True, text=True, env=env)
    return r.returncode, r.stdout + r.stderr


def ascii_limpio(txt):
    return all(ord(c) < 128 for c in re.sub(r'\x1b\[[0-9;]*m', '', txt))


def entrar(email, clave='clave12345'):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': clave}, headers={'CF-Connecting-IP': ip()})
    return s, r


def escribir_env(texto):
    with open(ENV, 'w') as f:
        f.write(texto)


PRODUCTO = php('echo tc_producto()[0];')
ACADEMIA = php('echo tc_producto()[2] ? "1" : "0";') == '1'
INST = PRODUCTO
SES = re.search(r"session_name\('([^']+)'\)", open(os.path.join(REPO, 'app', 'bootstrap.php')).read()).group(1)  # cookie de sesión
print('Producto:', PRODUCTO, '(modo academia)' if ACADEMIA else '(modo ligas)', '· puerto', PUERTO)

# ---------- 1) Sin configurar: 404 y todo igual ----------
if os.path.exists(ENV):
    os.remove(ENV)
for acc in ('estado', 'sso'):
    r = pedir(acc, token(acc))
    chk(r.status_code == 404, 'sin configurar: ' + acc + ' da 404')
escribir_env('TUKAN_CENTRAL_ID=%s\nTUKAN_CENTRAL_SECRET=%s\n' % (INST, 'a' * 31))
chk(pedir('estado', token(secreto='a' * 31)).status_code == 404, 'secreto de 31 caracteres: conector apagado (404)')
escribir_env('TUKAN_CENTRAL_SECRET=%s\n' % SECRETO)
chk(pedir('estado', token()).status_code == 404, 'sin ID: conector apagado (404)')
os.remove(ENV)
s, r = entrar('super@prueba.com')
chk('salir' in r.text.lower(), 'sin conector: el super entra con su clave como siempre')
chk('Panel Tukán' not in s.get(B + 'admin.php').text, 'sin conector: no aparece «← Panel Tukán»')

# ---------- 2) vps/conectar_central.sh (sin Docker) ----------
cod, out = conectar('Mala ID', SECRETO, URL_CENTRAL)
chk(cod == 1 and 'ERROR' in out and not os.path.exists(ENV), 'script: rechaza un ID malo y no escribe nada')
cod, out = conectar(INST, 'corto', URL_CENTRAL)
chk(cod == 1 and 'SECRETO' in out, 'script: rechaza un secreto corto')
cod, out = conectar(INST, SECRETO, 'javascript:alert(1)')
chk(cod == 1 and 'URL' in out, 'script: rechaza una URL mala')
cod, out = conectar(INST, SECRETO, URL_CENTRAL + '/')
chk(cod == 0 and 'OK:' in out, 'script: conecta y la autoprueba da OK')
if cod != 0:
    print(out)
chk(ascii_limpio(out), 'script: mensajes sin tildes ni ñ')
env1 = open(ENV).read()
chk('TUKAN_CENTRAL_ID=' + INST in env1 and 'TUKAN_CENTRAL_SECRET=' + SECRETO in env1 and 'TUKAN_CENTRAL_URL=' + URL_CENTRAL + '\n' in env1
    and 'TUKAN_SOLO_CENTRAL=0' in env1, 'script: data/tukan_central.env con los 4 valores (URL sin / final)')
chk(oct(os.stat(ENV).st_mode & 0o777) == '0o640', 'script: el archivo con el secreto queda 640')
cod, out = conectar(INST, SECRETO, URL_CENTRAL)
chk(cod == 0 and 'OK:' in out and open(ENV).read() == env1, 'script: repetirlo no cambia nada (idempotente)')
cod, out = conectar('--probar')
chk(cod == 0 and 'OK:' in out, 'script --probar: OK')
cod, out = conectar('--probar', extra={'TC_PUERTO': '1'})
chk(cod == 1 and 'ERROR' in out and ascii_limpio(out), 'script --probar sin nginx: error claro')
otro = secrets.token_hex(32)
escribir_env(env1.replace(SECRETO, otro))
cod, out = conectar('--probar')
chk(cod == 0, 'script --probar con el secreto que tiene el archivo (otro): OK')
escribir_env(env1)
cod, out = conectar()
chk(cod == 1 and 'Uso' in out, 'script sin datos: muestra el uso')

# ---------- 3) Datos de prueba para el estado ----------
FIX = r'''
$d = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
$c = fn(int $n) => gmdate('Y-m-d H:i:s', strtotime($n . ' days'));
q('DELETE FROM pagos_suscripcion');
q('UPDATE ligas SET es_demo = 0, activa = 1, es_prueba = 0, vence = ?, creado = ?', [$d(100), $c(-90)]);
$acad = tc_producto()[2];                                    // (TuAcademia) en modo academia solo las academias son negocios
$T = $acad ? 'escuela' : 'liga';
$solo = $acad ? " AND COALESCE(l.tipo, 'liga') = 'escuela'" : '';
$base = (int)valor("SELECT COUNT(*) FROM ligas l WHERE 1 = 1$solo");
$filas = [
  'A' => ['TC Activa Pagada', $d(60), 0, 1, 0, $c(-2)],
  'B' => ['TC Por Vencer', $d(3), 0, 1, 0, $c(-40)],
  'C' => ['TC Prueba Cinco', $d(5), 1, 1, 0, $c(-1)],
  'D' => ['TC Prueba Doce', $d(12), 1, 1, 0, $c(-2)],
  'E' => ['TC En Gracia', $d(-3), 0, 1, 0, $c(-100)],
  'F' => ['TC Vencida', $d(-30), 0, 1, 0, $c(-100)],
  'G' => ['TC Prueba Vencida', $d(-10), 1, 1, 0, $c(-20)],
  'H' => ['TC Suspendida', $d(50), 0, 0, 0, $c(-5)],
  'I' => ['TC Demo del Sistema', $d(5), 1, 1, 1, $c(-1)],
  'J' => ['TC Sin Limite', '', 0, 1, 0, $c(-200)],
];
$id = [];
foreach ($filas as $k => [$n, $v, $pr, $ac, $de, $cr]) {
  q('INSERT INTO ligas (nombre, slug, vence, es_prueba, activa, es_demo, creado, tipo) VALUES (?,?,?,?,?,?,?,?)', [$n, 'tc-' . strtolower($k) . '-' . bin2hex(random_bytes(3)), $v, $pr, $ac, $de, $cr, $T]);
  $id[$k] = (int)db()->lastInsertId();
}
// (TuAcademia) una liga que quedó de la base de TuVocalía: en modo academia NO es cliente (no cuenta en nada)
if ($acad) {
  q("INSERT INTO ligas (nombre, slug, vence, es_prueba, activa, es_demo, creado, tipo) VALUES ('TC Liga en Academia', ?, ?, 0, 1, 0, ?, 'liga')", ['tc-k-' . bin2hex(random_bytes(3)), $d(2), $c(-1)]);
  $id['K'] = (int)db()->lastInsertId();
}
$pg = fn(string $k, float $m, string $f) => q("INSERT INTO pagos_suscripcion (liga_id, monto, meses, fecha, forma_pago) VALUES (?,?,1,?, 'transferencia')", [$id[$k], $m, $f]);
$pg('A', 30.00, date('Y-m-d')); $pg('A', 12.50, date('Y-m-01')); $pg('A', 20.00, date('Y-m-15', strtotime('first day of last month')));
$pg('I', 99.00, date('Y-m-d')); $pg('B', 7.00, date('Y-m-10', strtotime('first day of -2 month')));
if ($acad) $pg('K', 55.00, date('Y-m-d'));
$mes = date('Y-m');
$antes = [
  'alumnos' => (int)valor("SELECT COUNT(*) FROM esc_alumnos a JOIN ligas l ON l.id = a.liga_id WHERE l.es_demo = 0$solo AND a.estado = 'activo'"),
  'equipos' => (int)valor('SELECT COUNT(*) FROM equipos e JOIN ligas l ON l.id = e.liga_id WHERE l.es_demo = 0'),
  'torneos' => (int)valor("SELECT COUNT(*) FROM torneos t JOIN ligas l ON l.id = t.liga_id WHERE l.es_demo = 0 AND t.estado <> 'finalizado'"),
  'usuarios' => (int)valor("SELECT COUNT(*) FROM usuarios u LEFT JOIN ligas l ON l.id = u.liga_id WHERE u.activo = 1 AND (u.liga_id IS NULL OR (COALESCE(l.es_demo, 0) = 0$solo))"),
  'cursos' => (int)valor("SELECT COUNT(*) FROM esc_grupos g JOIN ligas l ON l.id = g.liga_id WHERE l.es_demo = 0$solo AND g.activo = 1"),
  'clases' => (int)valor("SELECT COUNT(*) FROM esc_sesiones s JOIN esc_grupos g ON g.id = s.grupo_id JOIN ligas l ON l.id = g.liga_id WHERE l.es_demo = 0$solo AND substr(s.fecha, 1, 7) = ?", [$mes])
            + (int)valor("SELECT COUNT(*) FROM acad_clases c JOIN ligas l ON l.id = c.liga_id WHERE l.es_demo = 0$solo AND c.estado = 'asistio' AND substr(c.fecha, 1, 7) = ?", [$mes]),
  'asistencias' => (int)valor("SELECT COUNT(*) FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id JOIN ligas l ON l.id = g.liga_id WHERE l.es_demo = 0$solo AND x.estado IN ('P', 'T') AND substr(s.fecha, 1, 7) = ?", [$mes])
            + (int)valor("SELECT COUNT(*) FROM acad_clases c JOIN ligas l ON l.id = c.liga_id WHERE l.es_demo = 0$solo AND c.estado = 'asistio' AND substr(c.fecha, 1, 7) = ?", [$mes]),
  'pensiones' => (int)valor("SELECT COUNT(*) FROM esc_cargos c JOIN ligas l ON l.id = c.liga_id WHERE l.es_demo = 0$solo AND c.tipo = 'mensualidad' AND c.estado = 'pagado' AND substr(c.pagado_en, 1, 7) = ?", [$mes]),
  'profesores' => (int)valor("SELECT COUNT(*) FROM esc_profesores pr JOIN ligas l ON l.id = pr.liga_id WHERE l.es_demo = 0$solo AND pr.activo = 1"),
];
$al = [];
foreach (['A' => ['activo', 'activo', 'activo', 'retirado'], 'I' => ['activo', 'activo']] + ($acad ? ['K' => ['activo', 'activo']] : []) as $k => $estados)
  foreach ($estados as $i => $e) { q('INSERT INTO esc_alumnos (liga_id, nombres, estado) VALUES (?,?,?)', [$id[$k], 'Alumno ' . $k . $i, $e]); $al[$k][] = (int)db()->lastInsertId(); }
// (TuAcademia) profesores, cursos, clases, asistencia, clases particulares y pensiones: en A cuentan; en la demo (I) y en la liga K no
$hoy = date('Y-m-d'); $dia1 = date('Y-m-01'); $viejo = date('Y-m-d', strtotime('-400 days')); $mes_ant = date('Y-m-15', strtotime('first day of last month'));
foreach (['A', 'I'] + ($acad ? [2 => 'K'] : []) as $k) {
  foreach ([1, 1, 0] as $j => $act) q('INSERT INTO esc_profesores (liga_id, nombre, activo) VALUES (?,?,?)', [$id[$k], 'Profe ' . $k . $j, $act]);
  $gr = [];
  foreach ([1, 1, 0] as $j => $act) { q('INSERT INTO esc_grupos (liga_id, nombre, activo) VALUES (?,?,?)', [$id[$k], 'Curso ' . $k . $j, $act]); $gr[] = (int)db()->lastInsertId(); }
  $ses = [];
  foreach ([[$gr[0], $hoy], [$gr[1], $dia1], [$gr[0], $viejo]] as [$g, $f]) { q('INSERT INTO esc_sesiones (grupo_id, fecha) VALUES (?,?)', [$g, $f]); $ses[] = (int)db()->lastInsertId(); }
  foreach (array_slice(['P', 'T', 'A'], 0, count($al[$k])) as $j => $est) q('INSERT INTO esc_asistencia (sesion_id, alumno_id, estado) VALUES (?,?,?)', [$ses[0], $al[$k][$j], $est]);
  q("INSERT INTO esc_asistencia (sesion_id, alumno_id, estado) VALUES (?, ?, 'P')", [$ses[2], $al[$k][0]]);
  foreach ([[$hoy, '08:00', 'asistio'], [$hoy, '09:00', 'falto'], [$viejo, '08:00', 'asistio']] as [$f, $h, $est])
    q('INSERT INTO acad_clases (liga_id, alumno_id, fecha, hora, estado) VALUES (?,?,?,?,?)', [$id[$k], $al[$k][0], $f, $h, $est]);
  foreach ([['mensualidad', 'p1', 'pagado', $hoy], ['mensualidad', 'p2', 'pagado', $dia1], ['mensualidad', 'p3', 'pagado', $mes_ant], ['mensualidad', 'p4', 'pendiente', ''], ['uniforme', '', 'pagado', $hoy]] as [$tp, $per, $est, $pe])
    q('INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, monto, estado, pagado_en) VALUES (?,?,?,?,30,?,?)', [$id[$k], $al[$k][0], $tp, $per, $est, $pe]);
}
$eq = [];
foreach (['A', 'A', 'I', 'I', 'I'] as $i => $k) { q('INSERT INTO equipos (liga_id, nombre) VALUES (?,?)', [$id[$k], 'Equipo ' . $k . $i]); $eq[$k][] = (int)db()->lastInsertId(); }
q("INSERT INTO torneos (liga_id, nombre, estado) VALUES (?, 'TC Torneo en curso', 'en_curso')", [$id['A']]); $t1 = (int)db()->lastInsertId();
q("INSERT INTO torneos (liga_id, nombre, estado) VALUES (?, 'TC Torneo finalizado', 'finalizado')", [$id['A']]);
q("INSERT INTO torneos (liga_id, nombre, estado) VALUES (?, 'TC Torneo demo', 'en_curso')", [$id['I']]); $t3 = (int)db()->lastInsertId();
$mes_partidos = (int)valor("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id JOIN ligas l ON l.id = t.liga_id WHERE l.es_demo = 0 AND substr(p.fecha, 1, 7) = ?", [date('Y-m')]);
q('INSERT INTO partidos (torneo_id, local_id, visitante_id, fecha) VALUES (?,?,?,?)', [$t1, $eq['A'][0], $eq['A'][1], date('Y-m-d')]);
q('INSERT INTO partidos (torneo_id, local_id, visitante_id, fecha) VALUES (?,?,?,?)', [$t1, $eq['A'][1], $eq['A'][0], date('Y-m-d', strtotime('-400 days'))]);
q('INSERT INTO partidos (torneo_id, local_id, visitante_id, fecha) VALUES (?,?,?,?)', [$t3, $eq['I'][0], $eq['I'][1], date('Y-m-d')]);
$antes['partidos'] = $mes_partidos;
$antes['base'] = $base;
$antes['academia'] = $acad;
$antes['d'] = ['C' => $d(5), 'E' => $d(-3), 'F' => $d(-30)];
echo json_encode($antes);
'''
fx = json.loads(php(FIX))
N = fx['base']

# ---------- 4) Estado ----------
r = pedir('estado', token())
chk(r.status_code == 200 and r.headers.get('Content-Type', '').startswith('application/json'), 'estado: 200 application/json')
chk(SES + '=' not in r.headers.get('Set-Cookie', ''), 'estado: no abre sesión ni pone cookie')
e = r.json() if r.status_code == 200 else {}
CLAVES = {'ok', 'protocolo', 'producto', 'nombre', 'version', 'generado', 'negocios', 'nuevos_30d', 'ingresos_mes', 'ingresos_mes_anterior',
          'pruebas_vencen_7d', 'pagos_vencidos', 'extra'}
chk(CLAVES <= set(e.keys()), 'estado: tiene todas las claves del protocolo (' + ', '.join(sorted(CLAVES - set(e.keys()))) + ')')
chk(e.get('ok') is True and e.get('protocolo') == 1 and e.get('producto') == PRODUCTO, 'estado: ok, protocolo 1 y producto ' + PRODUCTO)
chk(e.get('nombre') == ('TuAcademia' if PRODUCTO == 'tuacademia' else 'TuVocalía'), 'estado: nombre del producto')
chk(isinstance(e.get('version'), str) and len(e.get('version', '')) >= 3, 'estado: versión (' + str(e.get('version')) + ')')
try:
    datetime.fromisoformat(e.get('generado', ''))
    gen_ok = re.search(r'[+-]\d\d:\d\d$', e['generado']) is not None
except Exception:
    gen_ok = False
chk(gen_ok, 'estado: generado en ISO 8601 con zona (' + str(e.get('generado')) + ')')
neg = e.get('negocios') or {}
esperado = {'total': N + 9, 'prueba': 2, 'activos': N + 2, 'por_vencer': 1, 'vencidos': 3, 'suspendidos': 1}
chk(neg == esperado, 'estado: negocios ' + json.dumps(neg) + ' == ' + json.dumps(esperado))
chk(neg.get('total') == sum(v for k, v in neg.items() if k != 'total'), 'estado: los estados suman el total')
chk(e.get('nuevos_30d') == 5, 'estado: nuevos_30d = 5 (' + str(e.get('nuevos_30d')) + ')')
chk(e.get('ingresos_mes') == 42.5, 'estado: ingresos_mes = 42.50 sin contar la demo (' + str(e.get('ingresos_mes')) + ')')
chk(e.get('ingresos_mes_anterior') == 20.0, 'estado: ingresos_mes_anterior = 20.00 (' + str(e.get('ingresos_mes_anterior')) + ')')
chk(e.get('pruebas_vencen_7d') == [{'nombre': 'TC Prueba Cinco', 'vence': fx['d']['C']}], 'estado: pruebas_vencen_7d ' + json.dumps(e.get('pruebas_vencen_7d'), ensure_ascii=False))
chk(e.get('pagos_vencidos') == [{'nombre': 'TC Vencida', 'desde': fx['d']['F']}, {'nombre': 'TC En Gracia', 'desde': fx['d']['E']}],
    'estado: pagos_vencidos (sin pruebas vencidas, el más antiguo primero) ' + json.dumps(e.get('pagos_vencidos'), ensure_ascii=False))
ex = e.get('extra') or {}
chk(3 <= len(ex) <= 8 and all(isinstance(v, (int, float, str)) for v in ex.values()), 'estado: extra con 3 a 8 cifras ' + json.dumps(ex, ensure_ascii=False))
chk(ex.get('Usuarios') == fx['usuarios'], 'estado: extra Usuarios = %s (%s)' % (fx['usuarios'], ex.get('Usuarios')))
chk(ex.get('Alumnos activos') == fx['alumnos'] + 3, 'estado: extra Alumnos activos = %s sin la demo (%s)' % (fx['alumnos'] + 3, ex.get('Alumnos activos')))
if ACADEMIA:
    # (TuAcademia) solo las academias reales (sin la demo del sistema ni la liga K que quedó de TuVocalía)
    for k, v in (('Academias', N + 9), ('Cursos activos', fx['cursos'] + 2), ('Clases este mes', fx['clases'] + 3), ('Asistencias este mes', fx['asistencias'] + 3),
                 ('Pensiones cobradas este mes', fx['pensiones'] + 2), ('Profesores', fx['profesores'] + 2)):
        chk(ex.get(k) == v, 'estado (academia): extra %s = %s (%s)' % (k, v, ex.get(k)))
    chk(list(ex.keys()) == ['Academias', 'Alumnos activos', 'Cursos activos', 'Clases este mes', 'Asistencias este mes', 'Pensiones cobradas este mes', 'Profesores', 'Usuarios'],
        'estado (academia): las 8 cifras en orden ' + ', '.join(ex.keys()))
    chk('TC Liga en Academia' not in json.dumps(e, ensure_ascii=False), 'estado (academia): la liga que quedó de TuVocalía no sale en ninguna lista')
else:
    chk(ex.get('Equipos') == fx['equipos'] + 2, 'estado: extra Equipos = %s sin la demo (%s)' % (fx['equipos'] + 2, ex.get('Equipos')))
    chk(ex.get('Campeonatos activos') == fx['torneos'] + 1, 'estado: extra Campeonatos activos = %s (%s)' % (fx['torneos'] + 1, ex.get('Campeonatos activos')))
    chk(ex.get('Partidos este mes') == fx['partidos'] + 1, 'estado: extra Partidos este mes = %s (%s)' % (fx['partidos'] + 1, ex.get('Partidos este mes')))
    chk(isinstance(ex.get('Escuelas'), int), 'estado: extra Escuelas')
txt = json.dumps(e, ensure_ascii=False)
chk('@' not in txt.replace('"producto"', ''), 'estado: sin correos ni datos personales')

# ---------- 5) Rechazos (protocolo §3) ----------
ahora = int(time.time())
malos = {
    'firma mala (otro secreto)': token(secreto=secrets.token_hex(32)),
    'firma cortada': token()[:-3],
    'otra instancia': token(inst='otra-instancia'),
    'tipo cambiado (token de sso para estado)': token('sso'),
    'vencido': token(iat=ahora - 200, exp=ahora - 140),
    'del futuro': token(iat=ahora + 100, exp=ahora + 160),
    'dura más de 60 s': token(iat=ahora, exp=ahora + 120),
    'exp antes que iat': token(iat=ahora, exp=ahora - 5),
    'nonce corto': token(nonce='abc123'),
    'nonce que no es hex': token(nonce='z' * 32),
    'sin nonce': token(nonce=None),
    'correo inválido': token(email='no-es-un-correo'),
    'sin correo': token(email=None),
    'versión 2': token(v=2),
    'iat como texto': token(iat=str(ahora)),
    'sin punto': 'abc',
    'tres partes': token() + '.x',
    'basura': '!!!.???',
    'vacío': '',
}
for nombre, t in malos.items():
    r = pedir('estado', t)
    j = {}
    try:
        j = r.json()
    except Exception:
        pass
    chk(r.status_code == 401 and j.get('ok') is False and isinstance(j.get('error'), str) and j['error'] and j.get('codigo') in ('firma', 'inst', 'tipo', 'version', 'vencido', 'futuro', 'nonce', 'invalido'),
        'estado rechaza: ' + nombre + ' (' + str(r.status_code) + ' ' + str(j.get('codigo')) + ' ' + str(j.get('error')) + ')')
chk(pedir('estado', token(tipo='sso')).status_code == 401, 'estado rechaza tipo «sso»')
ahora = int(time.time())  # (28 oct 2026) la hora de nuevo: los pedidos de arriba pueden tardar más de 5 s con la máquina cargada
r = pedir('estado', token(iat=ahora - 80, exp=ahora - 25))
chk(r.status_code == 200, 'estado acepta un token vencido hace 25 s (30 s de tolerancia)')
r = pedir('estado', token(iat=ahora + 25, exp=ahora + 70))
chk(r.status_code == 200, 'estado acepta un iat 25 s adelantado (30 s de tolerancia)')
t1 = token()
chk(pedir('estado', t1).status_code == 200, 'nonce: primera vez entra')
r = pedir('estado', t1)
chk(r.status_code == 401 and 'usó' in r.json().get('error', ''), 'nonce repetido: rechazado')
n = secrets.token_hex(16)
chk(pedir('estado', token(nonce=n)).status_code == 200 and pedir('sso', token('sso', nonce=n)).status_code == 401, 'nonce repetido aunque cambie la acción: rechazado')
cuantos = int(php("echo (int)valor('SELECT COUNT(*) FROM tukan_nonces');"))
chk(cuantos >= 1, 'nonces guardados en la tabla tukan_nonces')
php("tc_preparar(); db()->exec(\"INSERT INTO tukan_nonces (nonce, exp) VALUES ('" + 'f' * 32 + "', 1000)\");")
pedir('estado', token())
chk(php("echo (int)valor(\"SELECT COUNT(*) FROM tukan_nonces WHERE nonce = '" + 'f' * 32 + "'\");") == '0', 'nonces vencidos se borran solos')
chk(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE tabla = 'tukan_nonces'\");") == '0', 'los nonces no llenan la bitácora')
# SSO con token malo: página simple en español con enlace al panel central, código 401
r = pedir('sso', token('sso', secreto=secrets.token_hex(32)))
chk(r.status_code == 401 and 'text/html' in r.headers.get('Content-Type', '') and URL_CENTRAL in r.text and 'Panel Tukán' in r.text and 'Firma' in r.text,
    'sso con firma mala: 401, página en español con enlace al panel central')
ahora = int(time.time())
for nombre, t in [('vencido', token('sso', iat=ahora - 200, exp=ahora - 140)), ('otra instancia', token('sso', inst='zz')), ('tipo estado', token('estado')),
                  ('correo inválido', token('sso', email='x@')), ('del futuro', token('sso', iat=ahora + 100, exp=ahora + 150))]:
    r = pedir('sso', t)
    chk(r.status_code == 401 and URL_CENTRAL in r.text and 'Location' not in r.headers, 'sso rechaza: ' + nombre)
# (protocolo v2 §3.1 paso 15) acción desconocida o sin acción: 403 «no_permitido» con motivo «no_disponible» (antes 404)
r1, r2 = pedir('nada', token()), requests.get(B + 'tukan_central.php')
chk(all(x.status_code == 403 and x.json().get('codigo') == 'no_permitido' and x.json().get('detalle', {}).get('motivo') == 'no_disponible' for x in (r1, r2)), 'acción desconocida o sin acción: 403 no_permitido')
# Límite: 30 pedidos RECHAZADOS por minuto por IP (y 120 en total; v2 §3.1: 429 «limite» con Retry-After)
fija = ip()
codigos = [pedir('estado', 'basura', desde=fija).status_code for _ in range(30)]
r = pedir('estado', token(), desde=fija)
chk(all(c == 401 for c in codigos) and r.status_code == 429 and r.json().get('codigo') == 'limite' and 'Demasiados' in r.json().get('error', '') and r.headers.get('Retry-After') == '60',
    'límite: después de 30 rechazados en un minuto desde la misma IP se rechaza (429) aunque el token sea bueno')
chk(pedir('estado', token()).status_code == 200, 'límite: otra IP sigue entrando')

# ---------- 6) SSO ----------
EMAIL_NUEVO = 'dimitry+central@tukanec.com'
chk(php("echo (int)valor('SELECT COUNT(*) FROM usuarios WHERE lower(email) = ?', ['" + EMAIL_NUEVO + "']);") == '0', 'sso: el super nuevo todavía no existe')
s = requests.Session()
s.get(B + 'admin.php?p=login')
ses_antes = s.cookies.get(SES)
r = pedir('sso', token('sso', email=EMAIL_NUEVO.upper(), nombre='Dimitry Central'), s=s)
chk(r.status_code in (302, 303) and r.headers.get('Location', '').startswith('admin.php'), 'sso: redirige al panel de administración (' + str(r.status_code) + ' ' + r.headers.get('Location', '') + ')')
chk(ses_antes and s.cookies.get(SES) and s.cookies.get(SES) != ses_antes, 'sso: id de sesión nuevo (regenerado)')
u1 = pj("fila('SELECT id, nombre, rol, liga_id, activo, clave FROM usuarios WHERE lower(email) = ?', ['" + EMAIL_NUEVO + "'])")
chk(u1 and u1['rol'] == 'super' and u1['liga_id'] is None and u1['activo'] == 1 and u1['nombre'] == 'Dimitry Central', 'sso: crea el super la primera vez (con el nombre del token)')
chk(u1 and u1['clave'].startswith('$2') and php("echo password_verify('', '" + (u1 or {}).get('clave', '') + "') ? 'si' : 'no';") == 'no', 'sso: con contraseña aleatoria (hash) que nadie conoce')
p = s.get(B + 'admin.php')
chk(p.status_code == 200 and 'salir' in p.text.lower(), 'sso: entra al panel del super')
chk('← Panel Tukán' in p.text and 'href="' + URL_CENTRAL + '"' in p.text, 'sso: aparece «← Panel Tukán» con la dirección del panel central')
chk('← Panel Tukán' in s.get(B + 'admin.php?p=ligas').text, 'sso: el enlace sigue en otras páginas del panel')
bit = pj("filas(\"SELECT descripcion FROM bitacora WHERE operacion = 'evento' AND registro_id = ? ORDER BY id\", [" + str(u1['id'] if u1 else 0) + "])")
desc = [b['descripcion'] for b in bit or []]
chk('Entró desde el Panel Tukán Central' in desc, 'sso: bitácora «Entró desde el Panel Tukán Central»')
chk('Superadministrador creado por el Panel Tukán Central' in desc, 'sso: bitácora del super creado')
ult = php("echo valor('SELECT ultimo_ingreso FROM usuarios WHERE id = ?', [" + str(u1['id'] if u1 else 0) + "]);")
chk(ult.startswith(date.today().isoformat()[:7]), 'sso: anota el último ingreso')
s2 = requests.Session()
r = pedir('sso', token('sso', email=EMAIL_NUEVO, nombre='Otro Nombre'), s=s2)
chk(r.status_code in (302, 303), 'sso: segunda vez entra')
chk(php("echo (int)valor('SELECT COUNT(*) FROM usuarios WHERE lower(email) = ?', ['" + EMAIL_NUEVO + "']);") == '1'
    and pj("valor('SELECT id FROM usuarios WHERE lower(email) = ?', ['" + EMAIL_NUEVO + "'])") == (u1 or {}).get('id'), 'sso: la segunda vez reutiliza el mismo usuario (no lo duplica)')
chk('← Panel Tukán' in s2.get(B + 'admin.php').text, 'sso: segunda sesión también con «← Panel Tukán»')
id_super = pj("valor(\"SELECT id FROM usuarios WHERE email = 'super@prueba.com'\")")
s3 = requests.Session()
pedir('sso', token('sso', email='super@prueba.com'), s=s3)
chk('← Panel Tukán' in s3.get(B + 'admin.php').text and php("echo (int)valor(\"SELECT COUNT(*) FROM usuarios WHERE rol = 'super'\");") == '2',
    'sso: con el correo del super que ya existía entra con ese mismo usuario')
s3.get(B + 'admin.php?p=salir')
chk('Panel Tukán' not in s3.get(B + 'admin.php?p=login').text, 'sso: al salir se termina la sesión del panel central')
# Un correo de cliente no se convierte en super
id_liga = pj("valor(\"SELECT id FROM usuarios WHERE email = 'liga@prueba.com'\")")
chk(id_liga is not None, 'existe el usuario liga@prueba.com')
s4 = requests.Session()
r = pedir('sso', token('sso', email='liga@prueba.com'), s=s4)
chk(r.status_code == 403 and URL_CENTRAL in r.text, 'sso con el correo de un cliente: 403 con enlace al panel central')
chk(php("echo valor(\"SELECT rol FROM usuarios WHERE email = 'liga@prueba.com'\");") == 'liga' and 'salir' not in s4.get(B + 'admin.php').text.lower(),
    'sso con el correo de un cliente: el usuario sigue siendo cliente y no entra')
php("q(\"UPDATE usuarios SET activo = 0 WHERE lower(email) = ?\", ['" + EMAIL_NUEVO + "']);")
chk(pedir('sso', token('sso', email=EMAIL_NUEVO)).status_code == 403, 'sso con un super desactivado: 403')
php("q(\"UPDATE usuarios SET activo = 1 WHERE lower(email) = ?\", ['" + EMAIL_NUEVO + "']);")
# Doble verificación obligatoria: la sesión del central no se manda a activarla
php("q(\"INSERT OR REPLACE INTO ajustes (clave, valor) VALUES ('exigir_2fa', '1')\");")
s5 = requests.Session()
pedir('sso', token('sso', email=EMAIL_NUEVO), s=s5)
r = s5.get(B + 'admin.php')
chk(r.status_code == 200 and 'p=seguridad' not in r.url and 'salir' in r.text.lower(), 'sso con doble verificación obligatoria: entra sin que le pida activarla (el panel central ya verificó)')
s6, r = entrar('super@prueba.com')
chk('seguridad' in r.url, 'ingreso normal con doble verificación obligatoria: igual que antes (pide activarla)')
php("q(\"DELETE FROM ajustes WHERE clave = 'exigir_2fa'\");")

# ---------- 7) El ingreso normal no cambia ----------
s, r = entrar('super@prueba.com')
chk('salir' in r.text.lower(), 'ingreso normal: el super entra con su clave')
chk('Panel Tukán' not in s.get(B + 'admin.php').text, 'ingreso normal: sin «← Panel Tukán»')
s, r = entrar('liga@prueba.com')
chk('salir' in r.text.lower(), 'ingreso normal: el cliente entra con su clave')
s, r = entrar('liga@prueba.com', 'mala-clave')
chk('incorrectos' in r.text and 'salir' not in r.text.lower(), 'ingreso normal: clave mala rechazada')
s, r = entrar('super@prueba.com', 'mala-clave')
chk('incorrectos' in r.text, 'ingreso normal: clave mala del super rechazada')

# ---------- 8) TUKAN_SOLO_CENTRAL=1 ----------
cod, out = conectar('--solo-central', '1')
chk(cod == 0 and 'OK:' in out and 'TUKAN_SOLO_CENTRAL=1' in open(ENV).read() and ascii_limpio(out), 'script --solo-central 1')
s, r = entrar('super@prueba.com')
chk('salir' not in r.text.lower() and 'id="solo-central"' in r.text and 'Panel Tukán Central' in r.text and 'href="' + URL_CENTRAL + '"' in r.text,
    'solo central: el super NO entra con su clave y ve un mensaje claro con enlace al panel')
s, r = entrar('super@prueba.com', 'mala-clave')
chk('id="solo-central"' in r.text and 'incorrectos' not in r.text, 'solo central: con clave mala, el mismo mensaje (no revela la clave)')
chk(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE descripcion LIKE 'Ingreso local del superadministrador BLOQUEADO%'\");") != '0', 'solo central: queda en la bitácora')
s, r = entrar('liga@prueba.com')
chk('salir' in r.text.lower(), 'solo central: los clientes siguen entrando igual')
s, r = entrar('escuela@prueba.com')
chk('salir' in r.text.lower(), 'solo central: la escuela/academia sigue entrando igual')
s = requests.Session()
r = pedir('sso', token('sso', email='super@prueba.com'), s=s)
chk(r.status_code in (302, 303) and 'salir' in s.get(B + 'admin.php').text.lower(), 'solo central: desde el panel central sí entra')
r = requests.get(B + 'admin.php?p=login&solo_central=1')
chk('id="solo-central"' in r.text, 'solo central: el mensaje también se ve con ?solo_central=1')
cod, out = conectar('--solo-central', '0')
chk(cod == 0 and 'TUKAN_SOLO_CENTRAL=0' in open(ENV).read(), 'script --solo-central 0 (emergencia)')
s, r = entrar('super@prueba.com')
chk('salir' in r.text.lower(), 'solo central apagado: el super vuelve a entrar con su clave')
chk('id="solo-central"' not in requests.get(B + 'admin.php?p=login&solo_central=1').text, 'solo central apagado: no se muestra el mensaje')
cod, out = conectar(INST, SECRETO, URL_CENTRAL)
chk(cod == 0 and 'TUKAN_SOLO_CENTRAL=0' in open(ENV).read(), 'script: reconectar conserva TUKAN_SOLO_CENTRAL')
conectar('--solo-central', '1')
cod, out = conectar(INST, SECRETO, URL_CENTRAL)
chk('TUKAN_SOLO_CENTRAL=1' in open(ENV).read(), 'script: reconectar conserva TUKAN_SOLO_CENTRAL=1')
# Con el conector apagado, TUKAN_SOLO_CENTRAL no hace nada (nadie queda afuera)
escribir_env('TUKAN_SOLO_CENTRAL=1\n')
s, r = entrar('super@prueba.com')
chk('salir' in r.text.lower(), 'TUKAN_SOLO_CENTRAL=1 sin ID ni secreto: no tiene efecto, el super entra')

# ---------- 9) Desconectar ----------
conectar(INST, SECRETO, URL_CENTRAL)
cod, out = conectar('--desconectar')
chk(cod == 0 and not os.path.exists(ENV) and pedir('estado', token()).status_code == 404, 'script --desconectar: apaga el conector (404)')
cod, out = conectar('--probar')
chk(cod == 1 and 'ERROR' in out, 'script --probar sin configurar: error claro')

# ---------- 10) Sin errores de PHP ----------
for log in [os.path.join(DIR, 'web.log'), os.path.join(REPO, 'pruebas', 'v38', 'web.log')]:
    if os.path.exists(log) and os.path.getmtime(log) > time.time() - 3600:
        t = open(log, errors='replace').read()
        m = ERRORES.search(t)
        chk(not m, 'php -S sin errores de PHP (' + os.path.relpath(log, REPO) + ')')
        if m:
            print('   →', t[max(0, m.start() - 200):m.end() + 300])
        break

print()
print('Resultado: %d/%d bien' % (ok, ok + len(fallos)))
if fallos:
    print('Fallaron:', *fallos, sep='\n - ')
sys.exit(1 if fallos else 0)
