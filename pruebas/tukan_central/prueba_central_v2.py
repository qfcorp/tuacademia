#!/usr/bin/env python3
"""
Pruebas del conector con el Panel Tukán Central — PROTOCOLO v2 (tukan-central/docs/PROTOCOLO_V2.md §11) contra php -S.
El mismo archivo sirve para TuVocalía y TuAcademia (en TuAcademia se corre en los dos modos: academia y ligas).

Antes (base nueva):
  TuVocalía:   TA_PUERTO=8272 bash pruebas/tukan_central/reiniciar.sh
  TuAcademia:  TA_PUERTO=8272 TA_SIM=8273 bash pruebas/v38/reiniciar.sh                      (modo academia)
               TA_PRODUCTO=ligas TA_PUERTO=8272 TA_SIM=8273 bash pruebas/v38/reiniciar.sh  (modo ligas)
Correr:        TA_PUERTO=8272 python3 pruebas/tukan_central/prueba_central_v2.py

Prueba: capacidades; token v2 (sin bh, bh de otro cuerpo, cuerpo cambiado, versión, tipo, instancia, vida de 31 s en ejecutar,
vencido, futuro, nonce repetido, correo); método, tipo de contenido, tamaño y JSON; códigos de error; clientes (los 5 estados,
uno en gracia y uno que vence en 7 días exactos, = estado.negocios), filtros y orden; cliente; cotizar; planes; solicitudes de
los 11 tipos, solicitud, adjunto (y que no se pueda bajar el de otra solicitud ni con «..»); finanzas = pagos de los datos de
prueba; ejecutar con cada operación y su efecto real en la base; idempotencia (mismo operacion_id dos veces → un solo pago;
con otros datos → 409; crear_demo repetido sin clave); bitácora con el correo del Central; aislamiento (demo del sistema y,
en modo academia, ligas); privacidad (las claves no se guardan); SSO con «ir»; límite de 120 pedidos por minuto; estado v1
con huella de solicitudes. Al final revisa que php -S no haya registrado errores de PHP.
"""
import base64, hashlib, hmac, json, os, re, secrets, subprocess, sys, time
from datetime import date, timedelta
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
ENV = os.path.join(REPO, 'data', 'tukan_central.env')
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
SECRETO = secrets.token_hex(32)
CODIGOS = {'firma', 'inst', 'tipo', 'version', 'vencido', 'futuro', 'nonce', 'sin_configurar', 'limite', 'no_encontrado', 'invalido', 'no_permitido', 'conflicto', 'interno'}
ok, fallos = 0, []
HOY = date.today()


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


def ip():
    return '10.%d.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250), 1 + secrets.randbelow(250))


def b64(b):
    return base64.urlsafe_b64encode(b).decode().rstrip('=')


def firmar(p, secreto=None):
    a = b64(json.dumps(p, separators=(',', ':'), ensure_ascii=False).encode())
    return a + '.' + b64(hmac.new((secreto or SECRETO).encode(), a.encode(), hashlib.sha256).digest())


INST = ''
EMAIL_CENTRAL = 'dimitry+v2@tukanec.com'


def token2(tipo, cuerpo, secreto=None, sin=(), **cambios):
    ahora = int(time.time())
    p = {'v': 2, 'tipo': tipo, 'inst': INST, 'email': EMAIL_CENTRAL, 'nombre': 'Dimitry', 'rol': 'dueno', 'iat': ahora,
         'exp': ahora + (30 if tipo == 'ejecutar' else 60), 'nonce': secrets.token_hex(16), 'bh': b64(hashlib.sha256(cuerpo).digest())}
    p.update(cambios)
    for k in sin:
        p.pop(k, None)
    return firmar(p, secreto)


def post2(accion, cuerpo=None, raw=None, t=None, desde=None, tipo_c='application/json; charset=utf-8', **cambios):
    if raw is None:
        raw = json.dumps(cuerpo if cuerpo is not None else {}, ensure_ascii=False).encode()
    if t is None:
        t = token2(accion, raw, **cambios)
    return requests.post(B + 'tukan_central.php', params={'accion': accion}, data=raw, timeout=30,
                         headers={'Content-Type': tipo_c, 'X-Tukan-Token': t, 'CF-Connecting-IP': desde or ip()}, allow_redirects=False)


def j(r):
    try:
        return r.json()
    except Exception:
        return {}


def pedir(accion, cuerpo=None):
    r = post2(accion, cuerpo or {})
    if r.status_code != 200:
        print('   ', accion, r.status_code, r.text[:400])
    return j(r)


def op_id():
    return secrets.token_hex(16)


def ejecutar(accion, datos=None, cliente_id=None, oid=None):
    cuerpo = {'operacion_id': oid or op_id(), 'accion': accion, 'datos': datos or {}}
    if cliente_id is not None:
        cuerpo['cliente_id'] = str(cliente_id)
    r = post2('ejecutar', cuerpo)
    return r, j(r)


def es_error(r, codigo, http=None):
    d = j(r)
    return (http is None or r.status_code == http) and d.get('ok') is False and d.get('codigo') == codigo and isinstance(d.get('error'), str) and d['error'] != ''


def token1(tipo='estado', **cambios):
    ahora = int(time.time())
    p = {'v': 1, 'tipo': tipo, 'inst': INST, 'email': EMAIL_CENTRAL, 'nombre': 'Dimitry', 'iat': ahora, 'exp': ahora + 60, 'nonce': secrets.token_hex(16)}
    p.update(cambios)
    return firmar(p)


def get1(accion, t, s=None):
    return (s or requests).get(B + 'tukan_central.php', params={'accion': accion, 't': t}, headers={'CF-Connecting-IP': ip()}, allow_redirects=False, timeout=30)


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def entrar(email, clave='clave12345'):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': clave}, headers={'CF-Connecting-IP': ip()})
    return s, r


def bitacora_central(texto_parecido):
    return int(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE operacion = 'evento' AND tabla = 'tukan_central' AND usuario LIKE ? AND descripcion LIKE ?\", ['%"
                   + EMAIL_CENTRAL + "%', '%" + texto_parecido.replace("'", "") + "%']);") or 0)


PRODUCTO = php('echo tc_producto()[0];')
ACADEMIA = php('echo tc_producto()[2] ? "1" : "0";') == '1'
INST = PRODUCTO
print('Producto:', PRODUCTO, '(modo academia)' if ACADEMIA else '(modo ligas)', '· puerto', PUERTO)

# ---------- 0) Sin configurar: 404 «sin_configurar» ----------
if os.path.exists(ENV):
    os.remove(ENV)
r = post2('capacidades', {})
chk(r.status_code == 404 and j(r).get('codigo') == 'sin_configurar', 'sin configurar: capacidades da 404 sin_configurar')
with open(ENV, 'w') as f:
    f.write('TUKAN_CENTRAL_ID=%s\nTUKAN_CENTRAL_SECRET=%s\nTUKAN_CENTRAL_URL=https://panel.tukanec.com\n' % (INST, SECRETO))

# ---------- 1) Datos de prueba ----------
FIX = r'''
$acad = tc_producto()[2];
$T = $acad ? 'escuela' : 'liga';
$d = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
$c = fn(int $n) => gmdate('Y-m-d H:i:s', strtotime($n . ' days'));
guardar_ajuste('dias_gracia', 7); guardar_ajuste('dias_gracia_demo', 0); guardar_ajuste('dias_demo', 7);
$pl = (int)valor("SELECT id FROM planes WHERE activo = 1 AND tipo_cuenta IN ('ambos', ?) AND precio_mensual > 0 ORDER BY precio_mensual, id LIMIT 1", [$T]);
if (!$pl) $pl = (int)valor("SELECT id FROM planes WHERE tipo_cuenta IN ('ambos', ?) ORDER BY id LIMIT 1", [$T]);
q('UPDATE planes SET activo = 1 WHERE id = ?', [$pl]);
$pl2 = (int)valor("SELECT id FROM planes WHERE tipo_cuenta IN ('ambos', ?) AND id <> ? ORDER BY activo DESC, id LIMIT 1", [$T, $pl]);
q('UPDATE planes SET activo = 1 WHERE id = ?', [$pl2]);
$pldom = (int)valor("SELECT id FROM planes WHERE dominio_anual = 1 AND tipo_cuenta IN ('ambos', ?) ORDER BY id LIMIT 1", [$T]);
if (!$pldom) { $pldom = $pl2; q('UPDATE planes SET dominio_anual = 1 WHERE id = ?', [$pldom]); }
$otro = $T === 'escuela' ? 'liga' : 'escuela';
$plotro = (int)valor("SELECT id FROM planes WHERE tipo_cuenta = ? ORDER BY id LIMIT 1", [$otro]);
$id = [];
$nueva = function (string $k, string $n, string $vence, int $prueba, int $activa, int $demo, string $creado, string $tipo, array $mas = []) use (&$id, $pl) {
    $datos = ['nombre' => $n, 'slug' => 'v2-' . strtolower($k) . '-' . bin2hex(random_bytes(3)), 'vence' => $vence, 'es_prueba' => $prueba, 'activa' => $activa, 'es_demo' => $demo,
              'creado' => $creado, 'tipo' => $tipo, 'plan_id' => $pl, 'contacto_nombre' => 'Contacto ' . $k, 'contacto_telefono' => '09912345' . sprintf('%02d', count($id)),
              'contacto_email' => strtolower($k) . '@v2.ejemplo.com', 'ciudad' => 'Quito'] + $mas;
    q('INSERT INTO ligas (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_fill(0, count($datos), '?')) . ')', array_values($datos));
    $id[$k] = (int)db()->lastInsertId();
};
$nueva('P1', 'V2 Prueba Activa', $d(10), 1, 1, 0, $c(-3), $T);
$nueva('P2', 'V2 Demo Dos Dias', $d(2), 1, 1, 0, $c(-5), $T);
$nueva('V7', 'V2 Vence Siete', $d(7), 0, 1, 0, $c(-60), $T);
$nueva('V8', 'V2 Vence Ocho', $d(8), 0, 1, 0, $c(-90), $T);
$nueva('G', 'V2 En Gracia', $d(-2), 0, 1, 0, $c(-120), $T);
$nueva('VD', 'V2 Vencida', $d(-20), 0, 1, 0, $c(-200), $T);
$nueva('PV', 'V2 Demo Terminada', $d(-5), 1, 1, 0, $c(-12), $T);
$nueva('SU', 'V2 Suspendida', $d(40), 0, 0, 0, $c(-50), $T, ['suspendida_motivo' => 'Prueba de suspensión']);
$nueva('SL', 'V2 Sin Limite', '', 0, 1, 0, $c(-300), $acad ? 'escuela' : 'escuela');
$nueva('R', 'V2 Registro Nuevo', $d(5), 1, 1, 0, $c(-1), $T, ['origen' => 'registro']);
$nueva('DEMO', 'V2 Demo Del Sistema', $d(3), 1, 1, 1, $c(-1), $T);
if ($acad) $nueva('K', 'V2 Liga Que Quedo', $d(2), 0, 1, 0, $c(-1), 'liga');
// dueños con clave conocida
foreach (['P1', 'V8', 'PV'] as $k) q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id, perfil) VALUES (?, ?, ?, 'liga', ?, 'admin')", ['Dueño ' . $k, strtolower($k) . '@v2.ejemplo.com', password_hash('clave12345', PASSWORD_DEFAULT), $id[$k]]);
q("UPDATE usuarios SET ultimo_ingreso = ? WHERE email = 'v8@v2.ejemplo.com'", [date('Y-m-d H:i:s', strtotime('-1 hour'))]);
// vendedor
q("INSERT INTO vendedores (nombre, codigo, comision_pct, comision_meses, telefono) VALUES ('Carlos V2', 'V2CARLOS', 20, 12, '0987654321')");
$vend = (int)db()->lastInsertId();
q('UPDATE ligas SET vendedor_id = ? WHERE id IN (?, ?)', [$vend, $id['V8'], $id['P1']]);
// pagos de suscripción
$pagos = [];
$pg = function (string $k, float $m, int $meses, string $f, string $desde, string $hasta, string $forma = 'transferencia', string $por = 'Super Prueba', ?int $plan = null) use (&$pagos, $id, $pl) {
    q('INSERT INTO pagos_suscripcion (liga_id, plan_id, monto, meses, desde, hasta, forma_pago, nota, fecha, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$id[$k], $plan ?? $pl, $m, $meses, $desde, $hasta, $forma, 'fixture', $f, $por]);
    $pagos[] = ['id' => (int)db()->lastInsertId(), 'k' => $k, 'monto' => $m, 'fecha' => $f, 'forma' => $forma, 'meses' => $meses];
    return (int)db()->lastInsertId();
};
$pg('V7', 19.00, 1, $d(-23), $d(-23), $d(7));
$pg('V7', 20.00, 1, $d(-3), $d(-3), $d(7), 'google_play', 'Google Play');
$pg('V8', 95.00, 6, $d(0), $d(0), $d(8));
$pg('V8', 10.00, 1, $d(-1), $d(-1), $d(8), 'tarjeta', 'Pago en línea (Plux)');
$pg('G', 30.00, 1, $d(-32), $d(-32), $d(-2));
$pvd1 = $pg('VD', 25.00, 1, $d(-80), $d(-80), $d(-50));
$pvd2 = $pg('VD', 25.00, 1, $d(-50), $d(-50), $d(-20));
$pg('SL', 190.00, 12, $d(-40), $d(-40), $d(325), 'transferencia', 'Super Prueba', $pldom);
$pg('DEMO', 999.00, 1, $d(0), $d(0), $d(30));
if ($acad) $pg('K', 555.00, 1, $d(0), $d(0), $d(30));
// comisiones: una pendiente (V8) y una PAGADA en el último pago de VD
q("INSERT INTO comisiones (vendedor_id, liga_id, pago_suscripcion_id, monto_pago, meses, porcentaje, comision, estado, creado) VALUES (?, ?, ?, 95, 6, 20, 19, 'pendiente', ?)", [$vend, $id['V8'], $pagos[2]['id'], gmdate('Y-m-d H:i:s')]);
q("INSERT INTO comisiones (vendedor_id, liga_id, pago_suscripcion_id, monto_pago, meses, porcentaje, comision, estado, pagada_en, creado) VALUES (?, ?, ?, 25, 1, 20, 5, 'pagada', ?, ?)", [$vend, $id['VD'], $pvd2, date('Y-m-d'), gmdate('Y-m-d H:i:s')]);
// cobros con tarjeta (pagos_linea)
$tok = fn() => bin2hex(random_bytes(16));
q("INSERT INTO pagos_linea (token, liga_id, destino, concepto, total, neto, comision_tuvocalia, comision_pasarela, estado, pagado_en, creado) VALUES (?, ?, 'organizacion', 'Pensión', 50, 47, 0.5, 2.5, 'pagado', ?, ?)", [$tok(), $id['V8'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
q("INSERT INTO pagos_linea (token, liga_id, destino, concepto, total, neto, comision_tuvocalia, comision_pasarela, estado, pagado_en, creado) VALUES (?, ?, 'tuvocalia', 'Suscripción', 10, 9.1, 0, 0.9, 'pagado', ?, ?)", [$tok(), $id['V8'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
// pedidos de demo
q("INSERT INTO solicitudes_demo (tipo, organizacion, ciudad, contacto, telefono, email, tamano, plan_id, mensaje, vendedor_id, estado) VALUES (?, 'V2 Org Nueva', 'Cuenca', 'Ana Pérez', '0991112233', 'ana@v2.ejemplo.com', '50 alumnos', ?, 'Quiero ver la demo', ?, 'nueva')", [$T, $pl, $vend]);
$sd1 = (int)db()->lastInsertId();
q("INSERT INTO solicitudes_demo (tipo, organizacion, ciudad, contacto, telefono, email, estado) VALUES (?, 'V2 Org Contactada', 'Loja', 'Luis Mora', '0993334455', 'luis@v2.ejemplo.com', 'contactada')", [$T]);
$sd2 = (int)db()->lastInsertId();
// comprobantes (con foto real)
@mkdir(RAIZ . '/data/fotos', 0770, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents(RAIZ . '/data/fotos/v2_comprobante.png', $png);
file_put_contents(RAIZ . '/data/fotos/v2_ticket.png', $png);
file_put_contents(RAIZ . '/data/secreto_v2.png', $png);
q("INSERT INTO solicitudes_pago (liga_id, plan_id, meses, monto, forma_pago, archivo, nota, enviado_por, estado) VALUES (?, ?, 6, 95, 'transferencia', 'fotos/v2_comprobante.png', 'Pichincha 123', 'Dueño P1 <p1@v2.ejemplo.com>', 'pendiente')", [$id['P1'], $pl2]);
$cp1 = (int)db()->lastInsertId();
q("INSERT INTO solicitudes_pago (liga_id, plan_id, meses, monto, forma_pago, archivo, nota, enviado_por, estado) VALUES (?, ?, 1, 19, 'transferencia', 'fotos/v2_comprobante.png', '', 'V7', 'pendiente')", [$id['V7'], $pl]);
$cp2 = (int)db()->lastInsertId();
q("INSERT INTO solicitudes_pago (liga_id, plan_id, meses, monto, forma_pago, archivo, nota, enviado_por, estado) VALUES (?, ?, 1, 19, 'transferencia', '../secreto_v2.png', '', 'G', 'pendiente')", [$id['G'], $pl]);
$cp3 = (int)db()->lastInsertId();
q("INSERT INTO solicitudes_pago (liga_id, plan_id, meses, monto, forma_pago, archivo, estado) VALUES (?, ?, 1, 99, 'transferencia', 'fotos/v2_comprobante.png', 'pendiente')", [$id['DEMO'], $pl]);
$cpdemo = (int)db()->lastInsertId();
// soporte
$ahora = date('Y-m-d H:i:s');
q("INSERT INTO soporte_tickets (liga_id, autor, email, telefono, tipo, asunto, prioridad, estado, sin_leer_super, creado, actualizado) VALUES (?, 'Dueño V8', 'v8@v2.ejemplo.com', '0995556677', 'problema', 'No imprime', 'urgente', 'nuevo', 1, ?, ?)", [$id['V8'], $ahora, $ahora]);
$t1 = (int)db()->lastInsertId();
q("INSERT INTO soporte_mensajes (ticket_id, de, autor, texto, adjunto, creado) VALUES (?, 'cliente', 'Dueño V8', 'No me deja imprimir los carnets', 'fotos/v2_ticket.png', ?)", [$t1, $ahora]);
$m1 = (int)db()->lastInsertId();
q("INSERT INTO soporte_tickets (liga_id, autor, email, tipo, asunto, prioridad, estado, sin_leer_super, creado, actualizado) VALUES (?, 'Dueño P1', 'p1@v2.ejemplo.com', 'pregunta', '¿Cómo cargo alumnos?', 'normal', 'esperando', 0, ?, ?)", [$id['P1'], $ahora, $ahora]);
$t2 = (int)db()->lastInsertId();
q("INSERT INTO soporte_mensajes (ticket_id, de, autor, texto, creado) VALUES (?, 'cliente', 'Dueño P1', 'Pregunta', ?)", [$t2, $ahora]);
// derechos de datos dirigidos a Tukán
q("INSERT INTO solicitudes_derechos (liga_id, tipo, nombre, cedula, email, telefono, detalle, estado, creado) VALUES (NULL, 'acceso', 'María Titular', '1712345678', 'maria@v2.ejemplo.com', '0990001122', 'Quiero saber qué datos tienen de mí', 'nueva', ?)", [$ahora]);
$der = (int)db()->lastInsertId();
// (Anexo B) pedido de módulos desde Mi plan (un módulo que el plan no trae y que no necesita otro)
$cat = catalogo_modulos($T);
$del = modulos_del_plan_cuenta(['tipo' => $T, 'plan_id' => $pl], fila('SELECT * FROM planes WHERE id = ?', [$pl]), $cat);
$modped = (string)array_values(array_filter(array_keys($cat), fn($k) => !in_array($k, $del, true) && !$cat[$k]['depende_de']))[0];
q("INSERT INTO modulos_pedidos (liga_id, encender, mensaje, pedido_por, creado) VALUES (?, ?, 'Lo necesito', 'Dueño V8', ?)", [$id['V8'], $modped, date('Y-m-d H:i:s')]);
$ped1 = (int)db()->lastInsertId();
echo json_encode(['modped' => $modped, 'ped1' => $ped1, 'id' => $id, 'pl' => $pl, 'pl2' => $pl2, 'pldom' => $pldom, 'plotro' => $plotro, 'vend' => $vend, 'pagos' => $pagos, 'pvd1' => $pvd1, 'pvd2' => $pvd2,
    'sd1' => $sd1, 'sd2' => $sd2, 'cp1' => $cp1, 'cp2' => $cp2, 'cp3' => $cp3, 'cpdemo' => $cpdemo, 't1' => $t1, 't2' => $t2, 'm1' => $m1, 'der' => $der, 'png' => base64_encode($png),
    'base' => (int)valor("SELECT COUNT(*) FROM ligas l WHERE COALESCE(l.es_demo, 0) = 0" . tc_solo())]);
'''
fx = json.loads(php(FIX))
ID = {k: str(v) for k, v in fx['id'].items()}

# ---------- 2) capacidades ----------
r = post2('capacidades', {})
cap = j(r)
chk(r.status_code == 200 and r.headers.get('Content-Type', '').startswith('application/json') and 'no-store' in r.headers.get('Cache-Control', ''), 'capacidades: 200 JSON no-store')
chk(cap.get('ok') is True and cap.get('protocolo') == 2 and cap.get('accion') == 'capacidades' and cap.get('producto') == PRODUCTO and re.search(r'[+-]\d\d:\d\d$', cap.get('generado', '')),
    'capacidades: ok, protocolo 2, accion, producto y generado con zona')
OPS = ['crear_demo', 'extender_prueba', 'registrar_pago', 'anular_pago', 'aprobar_comprobante', 'rechazar_comprobante', 'suspender', 'reactivar', 'cambiar_plan',
       'nueva_clave_dueno', 'nota_interna', 'atender_solicitud', 'responder_ticket', 'pausar', 'borrar_cliente', 'restaurar_cliente', 'cambiar_modulos', 'guardar_modulos']
chk(cap.get('ejecutar') == OPS, 'capacidades: las 18 operaciones de TuVocalía/TuAcademia, con las del anexo A y B (sin guardar_plan ni cancelar)')
chk(set(cap.get('acciones', [])) == {'capacidades', 'estado', 'sso', 'clientes', 'cliente', 'cotizar', 'planes', 'solicitudes', 'solicitud', 'adjunto', 'finanzas', 'ejecutar', 'modulos'}, 'capacidades: las 13 acciones (con «modulos»)')
chk(cap.get('sso_ir') is True and cap.get('rutas_sso') == ['/admin.php'] and cap.get('unico') is False and cap.get('moneda') == 'USD' and cap.get('zona_horaria') == 'America/Guayaquil', 'capacidades: sso_ir, rutas, moneda y zona')
chk(cap.get('meses_libres') is True and cap.get('planes_editar') is False and cap.get('limites', {}).get('dias_prueba_max') == 90 and cap.get('revision') == 3, 'capacidades: meses libres, planes no editables, límites (revisión 3: anexo B)')
chk(len(cap.get('tipos_solicitud', [])) == 12 and 'cambio_modulos' in cap.get('tipos_solicitud', []) and 'cambio_plan' not in cap.get('tipos_solicitud', []), 'capacidades: 12 tipos de solicitud (con cambio_modulos)')
chk(cap.get('modulos') is True and cap.get('finanzas_por_modulo') is True, 'capacidades: programa modular (anexo B)')
campos_demo = [c['campo'] for c in cap.get('formularios', {}).get('crear_demo', [])]
chk(campos_demo == (['ciudad', 'disciplina'] if ACADEMIA else ['tipo', 'ciudad']), 'capacidades: campos propios de crear_demo ' + str(campos_demo))
metodos = [m['metodo'] for m in cap.get('metodos_pago', [])]
chk('transferencia' in metodos and 'efectivo' in metodos and (('deuna' in metodos) == (PRODUCTO == 'tuacademia')), 'capacidades: formas de pago del programa ' + str(metodos))
for a in ('guardar_plan', 'cancelar'):
    rr, d = ejecutar(a, {}, ID['V8'])
    chk(es_error(rr, 'no_permitido', 403) and d.get('detalle', {}).get('motivo') == 'no_disponible', 'ejecutar ' + a + ': 403 no_permitido (no disponible)')
r = requests.post(B + 'tukan_central.php', params={'accion': 'nada'}, data='{}', headers={'Content-Type': 'application/json'})
chk(es_error(r, 'no_permitido', 403) and j(r).get('detalle', {}).get('motivo') == 'no_disponible', 'acción desconocida: 403 no_permitido con motivo no_disponible')

# ---------- 3) Token v2, hash del cuerpo y validación ----------
cuerpo = b'{"pagina":1}'
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, sin=('bh',))), 'firma', 401), 'token v2 sin bh → 401 firma')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', b'{"pagina":2}')), 'firma', 401), 'token v2 con bh de otro cuerpo → 401 firma')
r = post2('clientes', raw=b'{"pagina":2}', t=token2('clientes', cuerpo))
chk(es_error(r, 'firma', 401) and j(r).get('detalle', {}).get('parte') == 'cuerpo', 'cuerpo cambiado un byte → 401 firma (parte cuerpo)')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, secreto=secrets.token_hex(32))), 'firma', 401), 'firma con otro secreto → 401 firma')
chk(es_error(post2('clientes', raw=cuerpo, t='basura'), 'firma', 401), 'token basura → 401 firma')
chk(es_error(post2('clientes', raw=cuerpo, t=''), 'firma', 401), 'sin token → 401 firma')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, v=1)), 'version', 401), 'token v:1 en una acción v2 → 401 version')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('planes', cuerpo)), 'tipo', 401), 'tipo distinto de la acción → 401 tipo')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, inst='otra')), 'inst', 401), 'otra instancia → 401 inst')
ahora = int(time.time())
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, iat=ahora - 200, exp=ahora - 140)), 'vencido', 401), 'vencido → 401 vencido')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, iat=ahora + 100, exp=ahora + 150)), 'futuro', 401), 'del futuro → 401 futuro')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, iat=ahora, exp=ahora + 61)), 'invalido', 401), 'lectura con vida de 61 s → 401 invalido')
cu = json.dumps({'operacion_id': op_id(), 'accion': 'nota_interna', 'cliente_id': ID['V8'], 'datos': {'texto': 'x'}}).encode()
chk(es_error(post2('ejecutar', raw=cu, t=token2('ejecutar', cu, iat=ahora, exp=ahora + 31)), 'invalido', 401), 'ejecutar con exp - iat = 31 → 401 invalido')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, email='no-es-correo')), 'invalido', 401), 'correo inválido → 401 invalido')
chk(es_error(post2('clientes', raw=cuerpo, t=token2('clientes', cuerpo, nonce='abc')), 'nonce', 401), 'nonce malo → 401 nonce')
t = token2('clientes', cuerpo)
chk(post2('clientes', raw=cuerpo, t=t).status_code == 200, 'token v2 correcto: 200')
chk(es_error(post2('clientes', raw=cuerpo, t=t), 'nonce', 401), 'token v2 repetido → 401 nonce')
t_roto = token2('clientes', cuerpo)
post2('clientes', raw=b'{"pagina":3}', t=t_roto)
chk(post2('clientes', raw=cuerpo, t=t_roto).status_code == 200, 'un token rechazado por el cuerpo no gasta su nonce')
r = requests.get(B + 'tukan_central.php', params={'accion': 'clientes'}, headers={'X-Tukan-Token': token2('clientes', b'')})
chk(es_error(r, 'invalido', 405), 'GET a una acción v2 → 405 invalido')
chk(es_error(post2('clientes', raw=b'esto no es json'), 'invalido', 400), 'cuerpo que no es JSON → 400 invalido')
chk(es_error(post2('clientes', raw=b'[1,2]'), 'invalido', 400), 'cuerpo JSON que no es objeto → 400 invalido')
chk(es_error(post2('clientes', raw=b'{}', tipo_c='text/plain'), 'invalido', 415), 'Content-Type que no es JSON → 415 invalido')
grande = json.dumps({'buscar': 'x' * 70000}).encode()
chk(es_error(post2('clientes', raw=grande), 'invalido', 413), 'cuerpo de más de 64 KiB → 413 invalido')
r = post2('clientes', {'por_pagina': 500})
chk(es_error(r, 'invalido', 422) and j(r).get('campo') == 'por_pagina', 'por_pagina 500 → 422 invalido con campo')
r = post2('clientes', {'estados': ['raro']})
chk(es_error(r, 'invalido', 422) and j(r).get('campo') == 'estados', 'estado desconocido → 422 con campo estados')

# ---------- 4) clientes = estado.negocios (los 5 estados) ----------
cl = pedir('clientes', {'por_pagina': 200})
e = j(get1('estado', token1()))
neg = e.get('negocios', {})
cont = cl.get('contadores', {})
chk(cont == {'total': neg.get('total'), 'prueba': neg.get('prueba'), 'activo': neg.get('activos'), 'por_vencer': neg.get('por_vencer'), 'vencido': neg.get('vencidos'), 'suspendido': neg.get('suspendidos')},
    'clientes.contadores = estado.negocios ' + json.dumps(cont) + ' / ' + json.dumps(neg))
chk(cont.get('total') == fx['base'] and cl.get('total') == fx['base'], 'clientes: total = cuentas reales (%s)' % fx['base'])
C = {c['id']: c for c in cl.get('clientes', [])}
esper = {'P1': ('prueba', 'prueba'), 'P2': ('prueba', 'prueba'), 'V7': ('por_vencer', 'activa'), 'V8': ('activo', 'activa'), 'G': ('vencido', 'gracia'), 'VD': ('vencido', 'vencida'),
         'PV': ('vencido', 'prueba_vencida'), 'SU': ('suspendido', 'suspendida'), 'SL': ('activo', 'sin_limite'), 'R': ('prueba', 'prueba')}
for k, (est, det) in esper.items():
    c = C.get(ID[k], {})
    chk(c.get('estado') == est and c.get('estado_detalle') == det, 'cliente %s: estado %s/%s (%s/%s)' % (k, est, det, c.get('estado'), c.get('estado_detalle')))
chk(C.get(ID['V7'], {}).get('fechas', {}).get('dias_para_vencer') == 7, 'cliente que vence en 7 días exactos: por_vencer con dias_para_vencer 7')
chk(C.get(ID['G'], {}).get('en_gracia') is True and C.get(ID['G'], {}).get('bloqueado') is False, 'cliente en gracia: en_gracia y no bloqueado')
chk(C.get(ID['VD'], {}).get('bloqueado') is True and C.get(ID['SU'], {}).get('bloqueado') is True, 'vencida y suspendida: bloqueadas')
chk(ID['DEMO'] not in C, 'la demo del sistema no es cliente')
if ACADEMIA:
    chk(ID['K'] not in C, '(academia) la liga que quedó de TuVocalía no es cliente')
v8 = C.get(ID['V8'], {})
chk(v8.get('pagos', {}).get('total') == 105.0 and v8.get('pagos', {}).get('cantidad') == 2 and v8.get('pagos', {}).get('ultimo_monto') == 95.0, 'cliente V8: pagos (total, cantidad, último)')
chk(v8.get('mensual_estimado') == round(95 / 6, 2), 'cliente V8: mensual_estimado = último pago ÷ meses (%s)' % v8.get('mensual_estimado'))
chk(C.get(ID['G'], {}).get('mensual_estimado') == 0.0 and C.get(ID['P1'], {}).get('mensual_estimado') == 0.0, 'mensual_estimado 0 si no está pagando')
chk(v8.get('vendedor', {}).get('nombre') == 'Carlos V2' and v8.get('dueno', {}).get('whatsapp', '').startswith('5939') and v8.get('dueno', {}).get('email') == 'v8@v2.ejemplo.com', 'cliente V8: vendedor y dueño con WhatsApp')
chk(v8.get('ir') == '/admin.php?p=ligas&editar=' + ID['V8'] and v8.get('ir_trabajar') == '/admin.php?p=ligas&entrar=' + ID['V8'], 'cliente: ir e ir_trabajar')
chk(v8.get('ultima_actividad') is not None and C.get(ID['SL'], {}).get('ultima_actividad') is None, 'cliente: ultima_actividad (último ingreso)')
chk(C.get(ID['R'], {}).get('origen') == 'registro' and v8.get('origen') in ('super', 'desconocido'), 'cliente: origen')
chk(set(v8.get('uso', {}).keys()) == ({'Alumnos', 'Cursos'} if ACADEMIA else {'Equipos', 'Campeonatos'}), 'cliente: uso ' + str(v8.get('uso')))
chk(C.get(ID['P1'], {}).get('fechas', {}).get('prueba_hasta') == (HOY + timedelta(days=10)).isoformat() and C.get(ID['P1'], {}).get('fechas', {}).get('activo_hasta') is None, 'cliente en prueba: prueba_hasta y sin activo_hasta')
# filtros, orden y paginado
r = pedir('clientes', {'estados': ['vencido'], 'orden': 'nombre'})
chk([c['nombre'] for c in r.get('clientes', [])] == sorted([c['nombre'] for c in r.get('clientes', [])], key=lambda x: x.lower()) and {c['id'] for c in r.get('clientes', [])} >= {ID['G'], ID['VD'], ID['PV']}
    and all(c['estado'] == 'vencido' for c in r.get('clientes', [])) and r.get('contadores') == cont, 'clientes: filtro por estado y orden por nombre (contadores sin filtro)')
r = pedir('clientes', {'buscar': 'VENCE ocho'})
chk([c['id'] for c in r.get('clientes', [])] == [ID['V8']], 'clientes: buscar sin distinguir mayúsculas')
r = pedir('clientes', {'buscar': '0991234503'})
chk(len(r.get('clientes', [])) == 1, 'clientes: buscar por teléfono')
r = pedir('clientes', {'vence_desde': HOY.isoformat(), 'vence_hasta': (HOY + timedelta(days=8)).isoformat()})
ids = [c['id'] for c in r.get('clientes', [])]
chk(ID['V7'] in ids and ID['V8'] in ids and ID['P2'] in ids and ID['G'] not in ids and ID['SL'] not in ids, 'clientes: filtro por vencimiento')
chk([c['fechas']['vence'] for c in r.get('clientes', [])] == sorted(c['fechas']['vence'] for c in r.get('clientes', [])), 'clientes: orden por vencimiento')
r = pedir('clientes', {'ids': [ID['V8'], ID['G']]})
chk(sorted(c['id'] for c in r.get('clientes', [])) == sorted([ID['V8'], ID['G']]), 'clientes: ids')
r = pedir('clientes', {'por_pagina': 3, 'pagina': 2})
chk(len(r.get('clientes', [])) == 3 and r.get('pagina') == 2 and r.get('paginas') == -(-fx['base'] // 3), 'clientes: paginado')
r = pedir('clientes', {'sin_uso_dias': 1})
chk(ID['V8'] not in [c['id'] for c in r.get('clientes', [])] and ID['SL'] in [c['id'] for c in r.get('clientes', [])], 'clientes: sin_uso_dias')
r = pedir('clientes', {'orden': 'vence'})
vs = [c['fechas']['vence'] for c in r.get('clientes', [])]
chk(vs.count(None) >= 1 and [v is None for v in vs] == sorted(v is None for v in vs), 'clientes: los sin fecha van al final')

# ---------- 5) cliente (ficha) ----------
r = post2('cliente', {'id': ID['V8']})
f = j(r).get('cliente', {})
chk(r.status_code == 200 and f.get('id') == ID['V8'] and len(f.get('pagos_detalle', [])) == 2 and f['pagos_detalle'][0]['monto'] == 95.0, 'cliente: ficha con pagos_detalle (el más nuevo primero)')
chk(f.get('pagos_detalle', [{}])[1].get('origen') == 'tarjeta' and f.get('pagos_detalle', [{}])[0].get('periodo') == 'semestral', 'cliente: origen y período de cada pago')
chk(f.get('enlace_publico', '').endswith('v2-v8-' + f.get('enlace_publico', '').split('v2-v8-')[-1]) and 'v2-v8-' in f.get('enlace_publico', ''), 'cliente: enlace público')
acc = [a['accion'] for a in f.get('acciones_posibles', [])]
chk({'registrar_pago', 'cambiar_plan', 'suspender', 'nueva_clave_dueno', 'nota_interna', 'whatsapp', 'abrir'} <= set(acc) and 'extender_prueba' not in acc and 'reactivar' not in acc,
    'cliente que paga: botones (sin alargar prueba) ' + str(acc))
rp = next((a for a in f.get('acciones_posibles', []) if a['accion'] == 'registrar_pago'), {})
chk([c['campo'] for c in rp.get('campos', [])] == ['plan', 'periodo', 'monto', 'metodo', 'fecha', 'nota'] and rp.get('datos', {}).get('cliente_id') == ID['V8'], 'cliente: formulario de registrar_pago')
chk(isinstance(f.get('mensajes_sugeridos', {}).get('bienvenida'), str) and f.get('usuarios') == 1 and isinstance(f.get('registro'), list), 'cliente: mensajes sugeridos, usuarios y registro')
chk(any(s['tipo'] == 'soporte' for s in f.get('solicitudes', [])), 'cliente: solicitudes abiertas de ese cliente')
fp = j(post2('cliente', {'id': ID['P1']})).get('cliente', {})
chk('extender_prueba' in [a['accion'] for a in fp.get('acciones_posibles', [])] and fp.get('mensajes_sugeridos', {}).get('recordar_prueba'), 'cliente en prueba: alargar prueba y mensaje de la demo')
fs = j(post2('cliente', {'id': ID['SU']})).get('cliente', {})
chk('reactivar' in [a['accion'] for a in fs.get('acciones_posibles', [])] and 'suspender' not in [a['accion'] for a in fs.get('acciones_posibles', [])], 'cliente suspendido: botón reactivar')
chk(es_error(post2('cliente', {'id': '999999'}), 'no_encontrado', 404), 'cliente que no existe → 404 no_encontrado')
chk(es_error(post2('cliente', {'id': ID['DEMO']}), 'no_encontrado', 404), 'la demo del sistema → 404 no_encontrado')
if ACADEMIA:
    chk(es_error(post2('cliente', {'id': ID['K']}), 'no_encontrado', 404), '(academia) una liga → 404 no_encontrado')
chk(es_error(post2('cliente', {}), 'invalido', 422), 'cliente sin id → 422')
txt = json.dumps(cl, ensure_ascii=False)
chk('"clave"' not in txt and '$2y$' not in txt, 'clientes: sin contraseñas')

# ---------- 6) cotizar y planes ----------
pl = pj('fila("SELECT * FROM planes WHERE id = ?", [' + str(fx['pl']) + '])')
ops_pl = pj('opciones_precio(fila("SELECT * FROM planes WHERE id = ?", [' + str(fx['pl']) + ']), fila("SELECT * FROM ligas WHERE id = ?", [' + ID['V8'] + ']))')
per = next(iter(ops_pl))
r = post2('cotizar', {'cliente_id': ID['V8'], 'plan': str(fx['pl']), 'periodo': per})
q = j(r)
desde_esp = (HOY + timedelta(days=9)).isoformat()
chk(r.status_code == 200 and q.get('monto') == round(ops_pl[per]['precio'], 2) and q.get('meses') == ops_pl[per]['meses'] and q.get('desde') == desde_esp,
    'cotizar: monto del período y vigencia desde el día siguiente al vencimiento (%s %s)' % (q.get('monto'), q.get('desde')))
hasta_esp = php("echo date('Y-m-d', strtotime(date('Y-m-d', strtotime('+8 days')) . ' +" + str(ops_pl[per]['meses']) + " months'));")
chk(q.get('hasta') == hasta_esp, 'cotizar: hasta con la regla de registrar_pago (%s)' % q.get('hasta'))
q2 = j(post2('cotizar', {'cliente_id': ID['G'], 'plan': str(fx['pl']), 'meses': 2}))
mens = ops_pl.get('mensual', {}).get('precio')
chk(q2.get('meses') == 2 and q2.get('desde') == HOY.isoformat() and (mens is None or q2.get('monto') == round(mens * 2, 2)), 'cotizar: meses libres (2 meses, desde hoy porque ya venció) ' + str(q2.get('monto')))
chk(es_error(post2('cotizar', {'cliente_id': ID['V8'], 'plan': '99999', 'periodo': 'mensual'}), 'invalido', 422), 'cotizar: plan que no existe → 422')
if fx['plotro']:
    chk(es_error(post2('cotizar', {'cliente_id': ID['V8'], 'plan': str(fx['plotro']), 'periodo': 'mensual'}), 'invalido', 422), 'cotizar: plan de otro tipo de cuenta → 422')
pls = pedir('planes')
chk(pls.get('editable') is False and any(p['codigo'] == str(fx['pl']) for p in pls.get('planes', [])) and pls.get('ajustes', {}).get('dias_gracia') == 7
    and all(isinstance(p['precios'], list) for p in pls.get('planes', [])), 'planes: lista con precios y ajustes')
if ACADEMIA:
    chk(all(p['para'] != 'Ligas' for p in pls.get('planes', [])), '(academia) planes: sin los planes de ligas')

# ---------- 7) solicitudes (los 11 tipos), solicitud y adjunto ----------
sols = pedir('solicitudes', {'por_pagina': 200})
S = {s['id']: s for s in sols.get('solicitudes', [])}
v8_vence = (HOY + timedelta(days=8)).strftime('%Y%m%d')
esperadas = {
    'demo_pedida:%d' % fx['sd1']: 'pendiente', 'demo_pedida:%d' % fx['sd2']: 'en_proceso', 'registro_nuevo:' + ID['R']: 'pendiente',
    'comprobante:%d' % fx['cp1']: 'pendiente', 'comprobante:%d' % fx['cp2']: 'pendiente', 'soporte:%d' % fx['t1']: 'pendiente', 'soporte:%d' % fx['t2']: 'en_proceso',
    'derechos_datos:%d' % fx['der']: 'pendiente', 'pago_automatico:%d' % fx['pagos'][1]['id']: 'pendiente', 'pago_automatico:%d' % fx['pagos'][3]['id']: 'pendiente',
    'seguimiento_prueba:%s:faltan_2:%s' % (ID['P2'], (HOY + timedelta(days=2)).strftime('%Y%m%d')): 'pendiente',
    'seguimiento_prueba:%s:terminada:%s' % (ID['PV'], (HOY - timedelta(days=5)).strftime('%Y%m%d')): 'pendiente',
    'renovacion:%s:vencida:%s' % (ID['G'], (HOY - timedelta(days=2)).strftime('%Y%m%d')): 'pendiente',
    'renovacion:%s:vencida:%s' % (ID['VD'], (HOY - timedelta(days=20)).strftime('%Y%m%d')): 'pendiente',
    'dominio_gratis:%d' % fx['pagos'][7]['id']: 'pendiente',
    'cambio_modulos:%d' % fx['ped1']: 'pendiente',
    'liquidacion_pendiente:' + ID['V8'] + ':' + php("echo (int)valor(\"SELECT MAX(id) FROM pagos_linea WHERE destino = 'organizacion'\");"): 'pendiente',
}
com_id = php("echo (int)valor(\"SELECT id FROM comisiones WHERE estado = 'pendiente' AND liga_id = " + ID['V8'] + "\");")
esperadas['comision_vendedor:' + com_id] = 'pendiente'
for sid, est in esperadas.items():
    chk(sid in S and S[sid]['estado'] == est, 'solicitudes: %s (%s)' % (sid, S.get(sid, {}).get('estado')))
chk({s['tipo'] for s in S.values()} >= set(cap.get('tipos_solicitud', [])), 'solicitudes: aparecen los 12 tipos ' + str(sorted({s['tipo'] for s in S.values()})))
chk(sols.get('total') == len(S) and sum(sols.get('contadores', {}).values()) == len(S), 'solicitudes: total y contadores por tipo')
chk('comprobante:%d' % fx['cp3'] in S and 'comprobante:%d' % fx['cpdemo'] not in S, 'solicitudes: el comprobante de la demo del sistema no sale')
chk(not any(s['cliente_id'] == ID['DEMO'] for s in S.values()), 'solicitudes: nada de la demo del sistema')
if ACADEMIA:
    chk(not any(s['cliente_id'] == ID['K'] for s in S.values()), '(academia) solicitudes: nada de la liga que quedó')
chk(not any(s['cliente_id'] == ID['V7'] and s['tipo'] == 'renovacion' for s in S.values()), 'renovación: a 7 días todavía no es recordatorio (solo 0 a 5)')
cp = S.get('comprobante:%d' % fx['cp1'], {})
chk(cp.get('monto') == 95.0 and cp.get('detalle', {}).get('cambia_plan') is True and cp.get('titulo') == 'Comprobante y cambio de plan' and cp.get('adjuntos', [{}])[0].get('id') == '1'
    and [a['accion'] for a in cp.get('acciones_posibles', [])][:2] == ['aprobar_comprobante', 'rechazar_comprobante'] and cp.get('prioridad') == 'alta', 'solicitud comprobante: monto, cambio de plan, adjunto y botones')
dp = S.get('demo_pedida:%d' % fx['sd1'], {})
chk(dp.get('quien', {}).get('whatsapp') == '593991112233' and 'crear_demo' in [a['accion'] for a in dp.get('acciones_posibles', [])] and dp.get('detalle', {}).get('vendedor') == 'Carlos V2',
    'solicitud de demo: WhatsApp, crear_demo y vendedor')
cd = next((a for a in dp.get('acciones_posibles', []) if a['accion'] == 'crear_demo'), {})
chk({c['campo']: c.get('valor') for c in cd.get('campos', [])}.get('email') == 'ana@v2.ejemplo.com', 'solicitud de demo: formulario crear_demo ya lleno')
so = S.get('soporte:%d' % fx['t1'], {})
chk(so.get('prioridad') == 'alta' and so.get('detalle', {}).get('subtipo') == 'problema' and so.get('adjuntos', [{}])[0].get('id') == str(fx['m1']), 'solicitud de soporte: prioridad, subtipo y adjunto')
dd = S.get('derechos_datos:%d' % fx['der'], {})
chk(dd.get('vence_el') == (HOY + timedelta(days=15)).isoformat() and dd.get('detalle', {}).get('cedula', '').endswith('5678') and '1712345678' not in json.dumps(dd), 'solicitud LOPDP: vence a los 15 días y cédula enmascarada')
r = post2('solicitud', {'id': 'soporte:%d' % fx['t1']})
sd = j(r).get('solicitud', {})
chk(r.status_code == 200 and sd.get('detalle', {}).get('mensajes', [{}])[0].get('texto') == 'No me deja imprimir los carnets' and sd['detalle']['mensajes'][0].get('adjunto_id') == str(fx['m1']), 'solicitud: conversación completa del ticket')
chk(es_error(post2('solicitud', {'id': 'soporte:999999'}), 'no_encontrado', 404), 'solicitud que no existe → 404')
r = post2('adjunto', {'solicitud_id': 'comprobante:%d' % fx['cp1'], 'adjunto_id': '1'})
a = j(r)
chk(r.status_code == 200 and a.get('tipo_mime') == 'image/png' and a.get('base64') == fx['png'] and a.get('tamano') == len(base64.b64decode(fx['png'])), 'adjunto: foto del comprobante (bytes exactos)')
a = j(post2('adjunto', {'solicitud_id': 'soporte:%d' % fx['t1'], 'adjunto_id': str(fx['m1'])}))
chk(a.get('base64') == fx['png'], 'adjunto: captura del ticket')
chk(es_error(post2('adjunto', {'solicitud_id': 'soporte:%d' % fx['t2'], 'adjunto_id': str(fx['m1'])}), 'no_encontrado', 404), 'adjunto de otra solicitud → 404 no_encontrado')
chk(es_error(post2('adjunto', {'solicitud_id': 'comprobante:%d' % fx['cp3'], 'adjunto_id': '1'}), 'no_encontrado', 404), 'adjunto con «..» → 404 no_encontrado')
chk(es_error(post2('adjunto', {'solicitud_id': 'comprobante:%d' % fx['cpdemo'], 'adjunto_id': '1'}), 'no_encontrado', 404), 'adjunto de la demo del sistema → 404')
chk(es_error(post2('adjunto', {'solicitud_id': 'comprobante:%d' % fx['cp1'], 'adjunto_id': '2'}), 'no_encontrado', 404), 'adjunto con otro id → 404')
r = pedir('solicitudes', {'tipos': ['comprobante']})
chk(r.get('solicitudes') and all(s['tipo'] == 'comprobante' for s in r['solicitudes']), 'solicitudes: filtro por tipos')
r = pedir('solicitudes', {'cliente_id': ID['V8']})
chk(r.get('solicitudes') and all(s['cliente_id'] == ID['V8'] for s in r['solicitudes']), 'solicitudes: filtro por cliente')
e = j(get1('estado', token1()))
chk(e.get('protocolo') == 1 and e.get('protocolo_max') == 2 and e.get('solicitudes_abiertas') == len(S) and re.fullmatch(r'[0-9a-f]{16}', e.get('solicitudes_huella', '')), 'estado v1: protocolo_max, solicitudes_abiertas y huella')
huella1 = e.get('solicitudes_huella')

# ---------- 8) finanzas = pagos de los datos de prueba ----------
fi = pedir('finanzas')
mes = HOY.strftime('%Y-%m')
reales = [p for p in fx['pagos'] if p['k'] not in ('DEMO', 'K')]
chk(len(fi.get('meses', [])) == 12 and fi['meses'][-1]['mes'] == mes, 'finanzas: los 12 meses, el último es este mes')
por_mes = {}
for p in reales:
    por_mes[p['fecha'][:7]] = round(por_mes.get(p['fecha'][:7], 0) + p['monto'], 2)
M = {m['mes']: m for m in fi.get('meses', [])}
chk(all(M[m]['ingresos'] == por_mes.get(m, 0.0) for m in M), 'finanzas: ingresos de cada mes = pagos de prueba (sin la demo del sistema) ' + json.dumps({m: M[m]['ingresos'] for m in M if M[m]['ingresos']}))
chk(fi.get('totales', {}).get('ingresos') == round(sum(p['monto'] for p in reales if p['fecha'][:7] in M), 2) and fi['totales']['pagos'] == len([p for p in reales if p['fecha'][:7] in M]), 'finanzas: totales')
mm = M.get(mes, {})
chk(sum(x['monto'] for x in mm.get('por_metodo', [])) == mm.get('ingresos') and sum(x['pagos'] for x in mm.get('por_plan', [])) == mm.get('pagos'), 'finanzas: por método y por plan suman el mes')
chk(mm.get('clientes_que_pagaron') == len({p['k'] for p in reales if p['fecha'][:7] == mes}), 'finanzas: clientes que pagaron este mes')
chk({'concepto': 'gestion_cobros'}.items() <= (mm.get('otros_ingresos') or [{}])[0].items() and mm['otros_ingresos'][0]['monto'] == 0.5, 'finanzas: gestión de cobros con tarjeta (1 %)')
costos = {c['concepto']: c for c in mm.get('costos', [])}
chk(costos.get('comision_pasarela', {}).get('monto') == 0.9 and costos.get('comision_pasarela', {}).get('categoria') == 'comisiones_pasarela', 'finanzas: comisión de Plux en suscripciones')
chk(costos.get('comision_vendedores', {}).get('monto') == 24.0 and costos['comision_vendedores']['categoria'] == 'sueldos_servicios', 'finanzas: comisiones de vendedores (19 + 5)')
gp_mes = next((p for p in reales if p['forma'] == 'google_play'), None)
if gp_mes and gp_mes['fecha'][:7] == mes:
    chk(costos.get('google_play', {}).get('monto') == round(20 * 0.15, 2) and costos['google_play'].get('estimado') is True and costos['google_play'].get('categoria') == 'tiendas_apps', 'finanzas: Google Play estimado 15 %')
mrr_esp = round(sum(c['mensual_estimado'] for c in cl.get('clientes', [])), 2)
chk(fi.get('mrr') == mrr_esp, 'finanzas: mrr = suma de mensual_estimado (%s)' % fi.get('mrr'))
chk(all(m['devengado'] >= 0 for m in fi['meses']) and M[mes]['devengado'] > 0, 'finanzas: devengado (lo pagado repartido entre los meses que cubre)')
a24 = HOY.year * 12 + HOY.month - 1 - 23
fi24 = pedir('finanzas', {'desde': '%04d-%02d' % (a24 // 12, a24 % 12 + 1), 'hasta': mes})
chk(len(fi24.get('meses', [])) == 24, 'finanzas: rango de 24 meses')
chk(es_error(post2('finanzas', {'desde': '2020-01', 'hasta': mes}), 'invalido', 422), 'finanzas: más de 24 meses → 422')
chk(es_error(post2('finanzas', {'desde': '2026-13'}), 'invalido', 422), 'finanzas: mes mal escrito → 422')

# ---------- 9) ejecutar: idempotencia y efecto real de cada operación ----------
n_pagos = lambda k: int(php('echo (int)valor("SELECT COUNT(*) FROM pagos_suscripcion WHERE liga_id = ?", [' + ID[k] + ']);'))
liga = lambda k: pj('fila("SELECT * FROM ligas WHERE id = ?", [' + ID[k] + '])')
# registrar_pago dos veces con el mismo operacion_id
oid = op_id()
antes = n_pagos('V7')
datos_pago = {'plan': str(fx['pl']), 'periodo': per, 'monto': 19.0, 'metodo': 'transferencia', 'fecha': HOY.isoformat(), 'nota': 'Pichincha 777'}
r1, d1 = ejecutar('registrar_pago', datos_pago, ID['V7'], oid)
vence_v7 = liga('V7')['vence']
r2, d2 = ejecutar('registrar_pago', datos_pago, ID['V7'], oid)
chk(r1.status_code == 200 and d1.get('ok') and d1.get('repetida') is False and d1.get('operacion') == 'registrar_pago' and d1.get('resultado', {}).get('activo_hasta') == vence_v7,
    'registrar_pago: hecho, con activo_hasta ' + str(d1.get('mensaje')))
chk(r2.status_code == 200 and d2.get('repetida') is True and d2.get('resultado') == d1.get('resultado'), 'registrar_pago repetido (otro token, mismo operacion_id): repetida y el mismo resultado')
chk(n_pagos('V7') == antes + 1 and liga('V7')['vence'] == vence_v7, 'registrar_pago repetido: UN solo pago y la vigencia no se suma dos veces')
r3, d3 = ejecutar('registrar_pago', dict(datos_pago, monto=25.0), ID['V7'], oid)
chk(es_error(r3, 'conflicto', 409) and n_pagos('V7') == antes + 1, 'mismo operacion_id con otro monto → 409 conflicto')
chk(d1.get('cliente', {}).get('id') == ID['V7'] and d1['cliente']['estado'] == 'activo' and d1['cliente']['pagos']['cantidad'] == antes + 1, 'registrar_pago: devuelve el cliente actualizado')
pago = pj('fila("SELECT * FROM pagos_suscripcion WHERE id = ?", [' + d1.get('resultado', {}).get('pago_id', '0') + '])')
chk(pago and pago['monto'] == 19 and pago['nota'] == 'Pichincha 777' and pago['registrado_por'] == 'Panel Tukán Central (Dimitry)' and pago['desde'] == (HOY + timedelta(days=8)).isoformat(),
    'registrar_pago: el pago en la base (monto, nota, quién y desde el día después del vencimiento)')
chk(bitacora_central('Pago registrado') >= 1 and bitacora_central('op ' + oid[:8]) == 1, 'registrar_pago: una línea en la bitácora con el correo del Central y la operación')
r, d = ejecutar('registrar_pago', dict(datos_pago, metodo='bitcoin'), ID['V7'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.metodo', 'registrar_pago: forma de pago mala → 422 con campo')
r, d = ejecutar('registrar_pago', dict(datos_pago, fecha=(HOY + timedelta(days=1)).isoformat()), ID['V7'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.fecha', 'registrar_pago: fecha futura → 422')
r, d = ejecutar('registrar_pago', {'plan': str(fx['pl']), 'meses': 2, 'metodo': 'efectivo'}, ID['G'])
chk(r.status_code == 200 and d.get('resultado', {}).get('meses') == 2 and liga('G')['vence'] == php("echo date('Y-m-d', strtotime('+2 months'));"), 'registrar_pago: meses libres y sin monto (usa cotizar); la cuenta vencida suma desde hoy')
chk(es_error(ejecutar('registrar_pago', datos_pago, '999999')[0], 'no_encontrado', 404), 'registrar_pago a un cliente que no existe → 404')
chk(es_error(ejecutar('registrar_pago', datos_pago, ID['DEMO'])[0], 'no_encontrado', 404), 'registrar_pago a la demo del sistema → 404')
# un error de validación también se guarda: el reintento con el mismo cuerpo da el mismo error, repetida
oid_e = op_id()
cu_e = {'operacion_id': oid_e, 'accion': 'registrar_pago', 'cliente_id': ID['V7'], 'datos': dict(datos_pago, metodo='bitcoin')}
r1 = post2('ejecutar', cu_e)
r2 = post2('ejecutar', cu_e)
chk(es_error(r1, 'invalido', 422) and r2.status_code == 422 and j(r2).get('repetida') is True, 'operación con error: el reintento devuelve el mismo error guardado')
# comisión del vendedor al pagar
r, d = ejecutar('registrar_pago', dict(datos_pago, monto=50.0), ID['V8'])
chk(d.get('resultado', {}).get('comision', {}).get('vendedor') == 'Carlos V2' and d['resultado']['comision']['monto'] == 10.0, 'registrar_pago: comisión del vendedor (20 % de $50)')
pago_v8 = d.get('resultado', {}).get('pago_id')
# anular_pago: vuelve la fecha al pago anterior
vence_antes = liga('V8')['vence']
r, d = ejecutar('anular_pago', {'pago_id': pago_v8, 'motivo': 'Se registró por error'}, ID['V8'])
chk(r.status_code == 200 and liga('V8')['vence'] == (HOY + timedelta(days=8)).isoformat() and vence_antes != liga('V8')['vence'] and d.get('resultado', {}).get('activo_hasta') == (HOY + timedelta(days=8)).isoformat(),
    'anular_pago: borra el pago y la fecha vuelve a la del pago anterior (' + str(d.get('mensaje')) + ')')
chk(php('echo (int)valor("SELECT COUNT(*) FROM pagos_suscripcion WHERE id = ?", [' + str(pago_v8) + ']);') == '0'
    and php('echo (int)valor("SELECT COUNT(*) FROM comisiones WHERE pago_suscripcion_id = ?", [' + str(pago_v8) + ']);') == '0', 'anular_pago: el pago y su comisión pendiente ya no están')
chk(es_error(ejecutar('anular_pago', {'pago_id': pago_v8, 'motivo': 'otra vez'}, ID['V8'])[0], 'no_encontrado', 404), 'anular_pago de un pago ya anulado → 404')
chk(es_error(ejecutar('anular_pago', {'pago_id': str(fx['pvd2']), 'motivo': 'Comisión ya pagada'}, ID['VD'])[0], 'no_permitido', 403), 'anular_pago con la comisión del vendedor PAGADA → 403')
chk(es_error(ejecutar('anular_pago', {'pago_id': str(fx['pvd1']), 'motivo': 'Pago de otra cuenta'}, ID['V8'])[0], 'no_encontrado', 404), 'anular_pago de un pago de OTRA cuenta → 404')
r, d = ejecutar('anular_pago', {'pago_id': str(fx['pvd1']), 'motivo': 'x'}, ID['VD'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.motivo', 'anular_pago: motivo corto → 422')
r, d = ejecutar('anular_pago', {'pago_id': str(fx['pvd1']), 'motivo': 'Pago viejo duplicado'}, ID['VD'])
chk(r.status_code == 200 and liga('VD')['vence'] == (HOY - timedelta(days=20)).isoformat(), 'anular_pago de un pago que no era el último: la fecha no cambia')
# extender_prueba
r, d = ejecutar('extender_prueba', {'dias': 7}, ID['P1'])
chk(r.status_code == 200 and liga('P1')['vence'] == (HOY + timedelta(days=17)).isoformat() and liga('P1')['es_prueba'] == 1 and d['resultado']['prueba_hasta'] == (HOY + timedelta(days=17)).isoformat(),
    'extender_prueba: suma desde la fecha de fin')
r, d = ejecutar('extender_prueba', {'dias': 10}, ID['PV'])
chk(r.status_code == 200 and liga('PV')['vence'] == (HOY + timedelta(days=10)).isoformat(), 'extender_prueba de una demo terminada sin pagos: desde hoy')
r, d = ejecutar('extender_prueba', {'dias': 7}, ID['V8'])
chk(es_error(r, 'no_permitido', 403) and liga('V8')['es_prueba'] == 0, 'extender_prueba de un cliente que paga → 403 no_permitido (no vuelve a demo)')
chk(es_error(ejecutar('extender_prueba', {'dias': 7}, ID['VD'])[0], 'no_permitido', 403), 'extender_prueba de una cuenta vencida que pagó → 403')
chk(es_error(ejecutar('extender_prueba', {'dias': 91}, ID['P1'])[0], 'invalido', 422), 'extender_prueba con 91 días → 422')
# aprobar / rechazar comprobante
r, d = ejecutar('aprobar_comprobante', {'solicitud_id': 'comprobante:%d' % fx['cp1'], 'monto': 95.0, 'nota': 'ok'})
sp = pj('fila("SELECT * FROM solicitudes_pago WHERE id = ?", [' + str(fx['cp1']) + '])')
chk(r.status_code == 200 and sp['estado'] == 'aprobada' and sp['pago_id'] and liga('P1')['es_prueba'] == 0 and liga('P1')['plan_id'] == fx['pl2'] and d.get('solicitud', {}).get('estado') == 'atendida',
    'aprobar_comprobante: pago registrado, la cuenta sale de la demo con el plan del comprobante y la solicitud queda atendida')
r, d = ejecutar('aprobar_comprobante', {'solicitud_id': 'comprobante:%d' % fx['cp1']})
chk(es_error(r, 'conflicto', 409) and d.get('detalle', {}).get('estado') == 'aprobada', 'aprobar_comprobante de uno ya aprobado → 409 conflicto (detalle.estado)')
r, d = ejecutar('rechazar_comprobante', {'solicitud_id': 'comprobante:%d' % fx['cp2'], 'motivo': 'El valor no coincide'})
sp2 = pj('fila("SELECT * FROM solicitudes_pago WHERE id = ?", [' + str(fx['cp2']) + '])')
chk(r.status_code == 200 and sp2['estado'] == 'rechazada' and sp2['respuesta'] == 'El valor no coincide', 'rechazar_comprobante: rechazado con el motivo')
chk(es_error(ejecutar('rechazar_comprobante', {'solicitud_id': 'comprobante:%d' % fx['cp2'], 'motivo': 'otra vez no'})[0], 'conflicto', 409), 'rechazar_comprobante ya atendido → 409')
chk(es_error(ejecutar('aprobar_comprobante', {'solicitud_id': 'comprobante:%d' % fx['cpdemo']})[0], 'no_encontrado', 404), 'aprobar_comprobante de la demo del sistema → 404')
chk(es_error(ejecutar('atender_solicitud', {'solicitud_id': 'comprobante:%d' % fx['cp3'], 'resultado': 'atendida'})[0], 'no_permitido', 403), 'atender_solicitud de un comprobante → 403 (usar aprobar/rechazar)')
# suspender / reactivar (suspensión real)
r, d = ejecutar('suspender', {'motivo': 'No pagó la renovación'}, ID['V8'])
chk(r.status_code == 200 and liga('V8')['activa'] == 0 and liga('V8')['suspendida_motivo'] == 'No pagó la renovación' and d.get('resultado', {}).get('estado') == 'suspendido' and d.get('cliente', {}).get('estado') == 'suspendido',
    'suspender: la cuenta queda suspendida con su motivo')
r, d = ejecutar('suspender', {'motivo': 'No pagó la renovación'}, ID['V8'])
chk(r.status_code == 200 and d.get('sin_cambios') is True, 'suspender a un suspendido: ok con sin_cambios')
s, rr = entrar('v8@v2.ejemplo.com')
chk('id="cuenta-suspendida"' in rr.text and 'Cuenta suspendida' in rr.text, 'suspensión real: el cliente ve el aviso de cuenta suspendida')
p = s.get(B + 'admin.php?p=usuarios')
r = s.post(B + 'admin.php?p=usuarios', data={'csrf': csrf(p.text), 'accion': 'crear', 'nombre': 'No Debe', 'email': 'nodebe@v2.ejemplo.com', 'clave': 'clave12345', 'perfil': 'admin'}, headers={'CF-Connecting-IP': ip()})
chk('Cuenta suspendida' in r.text and php("echo (int)valor(\"SELECT COUNT(*) FROM usuarios WHERE email = 'nodebe@v2.ejemplo.com'\");") == '0', 'suspensión real: el cliente no puede guardar cambios')
chk(php('echo liga_puede_editar(fila("SELECT * FROM ligas WHERE id = ?", [' + ID['V8'] + '])) ? "si" : "no";') == 'no', 'suspensión real: liga_puede_editar() = no')
chk('suspendida' in php('try { exigir_suscripcion(fila("SELECT * FROM ligas WHERE id = ?", [' + ID['V8'] + '])); echo "paso"; } catch (RuntimeException $e) { echo $e->getMessage(); }'), 'suspensión real: exigir_suscripcion() (vocal, árbitro, delegado) la frena')
slug = liga('V8')['slug']
chk(requests.get(B + 'index.php', params={'l': slug}).status_code == 404, 'suspensión: la página pública se oculta')
r, d = ejecutar('reactivar', {}, ID['V8'])
chk(r.status_code == 200 and liga('V8')['activa'] == 1 and liga('V8')['suspendida_motivo'] == '' and d.get('resultado', {}).get('estado') == 'activo', 'reactivar: vuelve al estado que le toca por fechas')
r, d = ejecutar('reactivar', {}, ID['V8'])
chk(d.get('sin_cambios') is True, 'reactivar a un activo: sin_cambios')
chk(requests.get(B + 'index.php', params={'l': slug}).status_code == 200, 'reactivar: la página pública vuelve')
# cambiar_plan
r, d = ejecutar('cambiar_plan', {'plan': str(fx['pl2'])}, ID['V8'])
chk(r.status_code == 200 and liga('V8')['plan_id'] == fx['pl2'] and d.get('resultado', {}).get('plan', {}).get('codigo') == str(fx['pl2']), 'cambiar_plan: plan cambiado sin cobrar')
r, d = ejecutar('cambiar_plan', {'plan': str(fx['pl2'])}, ID['V8'])
chk(d.get('sin_cambios') is True, 'cambiar_plan al mismo plan: sin_cambios')
if fx['plotro']:
    chk(es_error(ejecutar('cambiar_plan', {'plan': str(fx['plotro'])}, ID['V8'])[0], 'invalido', 422), 'cambiar_plan a un plan de otro tipo de cuenta → 422')
# nueva_clave_dueno
oid_c = op_id()
r, d = ejecutar('nueva_clave_dueno', {}, ID['P1'], oid_c)
clave = d.get('resultado', {}).get('clave', '')
chk(r.status_code == 200 and len(clave) == 10 and d['resultado'].get('usuario') == 'p1@v2.ejemplo.com' and clave in d['resultado'].get('mensaje_whatsapp', ''), 'nueva_clave_dueno: clave de 10 caracteres para el dueño')
s, rr = entrar('p1@v2.ejemplo.com', clave)
chk('salir' in rr.text.lower(), 'nueva_clave_dueno: el dueño entra con la clave nueva')
s, rr = entrar('p1@v2.ejemplo.com', 'clave12345')
chk('salir' not in rr.text.lower(), 'nueva_clave_dueno: la clave anterior ya no sirve')
r, d = ejecutar('nueva_clave_dueno', {}, ID['P1'], oid_c)
chk(d.get('repetida') is True and d.get('resultado', {}).get('clave') is None and d['resultado'].get('clave_oculta') is True and clave not in json.dumps(d), 'nueva_clave_dueno repetida: clave null y clave_oculta')
chk(es_error(ejecutar('nueva_clave_dueno', {}, ID['SL'])[0], 'no_permitido', 403), 'nueva_clave_dueno sin administrador → 403')
# nota_interna
r, d = ejecutar('nota_interna', {'texto': 'Pidió factura con RUC 1790012345001'}, ID['V8'])
chk(r.status_code == 200 and liga('V8')['notas_admin'] == 'Pidió factura con RUC 1790012345001' and j(post2('cliente', {'id': ID['V8']})).get('cliente', {}).get('notas_admin') == 'Pidió factura con RUC 1790012345001',
    'nota_interna: guardada y visible en la ficha')
r, d = ejecutar('nota_interna', {'texto': 'Pidió factura con RUC 1790012345001'}, ID['V8'])
chk(d.get('sin_cambios') is True, 'nota_interna igual: sin_cambios')
r, d = ejecutar('nota_interna', {'texto': ''}, ID['V8'])
chk(r.status_code == 200 and liga('V8')['notas_admin'] == '', 'nota_interna vacía: borra la nota')
chk(es_error(ejecutar('nota_interna', {'texto': 'x' * 4001}, ID['V8'])[0], 'invalido', 422), 'nota_interna de más de 4000 → 422')
# atender_solicitud: recordatorio (sale de abiertas; la siguiente etapa sí sale)
sid_f2 = 'seguimiento_prueba:%s:faltan_2:%s' % (ID['P2'], (HOY + timedelta(days=2)).strftime('%Y%m%d'))
r, d = ejecutar('atender_solicitud', {'solicitud_id': sid_f2, 'resultado': 'atendida', 'nota': 'Le escribí'})
ab = {s['id'] for s in pedir('solicitudes', {'por_pagina': 200}).get('solicitudes', [])}
chk(r.status_code == 200 and sid_f2 not in ab and d.get('solicitud', {}).get('estado') == 'atendida', 'atender_solicitud de un recordatorio: ya no sale en abiertas')
chk(pj('fila("SELECT * FROM tukan_central_atenciones WHERE solicitud_id = ?", ["' + sid_f2 + '"])')['email'] == EMAIL_CENTRAL, 'atender_solicitud: guardado en tukan_central_atenciones con el correo del Central')
cer = {s['id'] for s in pedir('solicitudes', {'estado': 'cerradas', 'por_pagina': 200}).get('solicitudes', [])}
chk(sid_f2 in cer, 'atender_solicitud: sale en cerradas')
r, d = ejecutar('atender_solicitud', {'solicitud_id': sid_f2, 'resultado': 'atendida'})
chk(d.get('sin_cambios') is True, 'atender_solicitud con el mismo resultado: sin_cambios')
php('q("UPDATE ligas SET vence = ? WHERE id = ?", [date("Y-m-d"), ' + ID['P2'] + ']);')
sid_hoy = 'seguimiento_prueba:%s:vence_hoy:%s' % (ID['P2'], HOY.strftime('%Y%m%d'))
chk(sid_hoy in {s['id'] for s in pedir('solicitudes', {'por_pagina': 200}).get('solicitudes', [])}, 'la siguiente etapa del recordatorio (vence hoy) sí sale')
e = j(get1('estado', token1()))
chk(e.get('solicitudes_huella') != huella1, 'estado: la huella cambia cuando cambian las solicitudes')
# atender_solicitud: demo pedida, soporte, LOPDP, en proceso
r, d = ejecutar('atender_solicitud', {'solicitud_id': 'demo_pedida:%d' % fx['sd2'], 'resultado': 'descartada', 'nota': 'No contesta'})
chk(r.status_code == 200 and pj('fila("SELECT estado, nota FROM solicitudes_demo WHERE id = ?", [' + str(fx['sd2']) + '])') == {'estado': 'descartada', 'nota': 'No contesta'}, 'atender_solicitud demo_pedida: descartada (en su tabla)')
r, d = ejecutar('atender_solicitud', {'solicitud_id': 'soporte:%d' % fx['t2'], 'resultado': 'atendida'})
tk = pj('fila("SELECT estado, sin_leer_super FROM soporte_tickets WHERE id = ?", [' + str(fx['t2']) + '])')
chk(r.status_code == 200 and tk == {'estado': 'resuelto', 'sin_leer_super': 0}, 'atender_solicitud soporte: resuelto')
r, d = ejecutar('atender_solicitud', {'solicitud_id': 'derechos_datos:%d' % fx['der'], 'resultado': 'atendida'})
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.respuesta', 'atender_solicitud LOPDP sin respuesta → 422')
r, d = ejecutar('atender_solicitud', {'solicitud_id': 'derechos_datos:%d' % fx['der'], 'resultado': 'atendida', 'respuesta': 'Le enviamos sus datos por correo'})
dr = pj('fila("SELECT estado, respuesta, respondido_por FROM solicitudes_derechos WHERE id = ?", [' + str(fx['der']) + '])')
chk(r.status_code == 200 and dr['estado'] == 'respondida' and dr['respuesta'] == 'Le enviamos sus datos por correo' and 'Panel Tukán Central' in dr['respondido_por'], 'atender_solicitud LOPDP: respondida con la respuesta (en su tabla)')
sid_reg = 'registro_nuevo:' + ID['R']
r, d = ejecutar('atender_solicitud', {'solicitud_id': sid_reg, 'resultado': 'en_proceso'})
chk(r.status_code == 200 and S and next((s for s in pedir('solicitudes', {'tipos': ['registro_nuevo']}).get('solicitudes', []) if s['id'] == sid_reg), {}).get('estado') == 'en_proceso',
    'atender_solicitud en_proceso: sigue abierta como en proceso')
sid_dom = 'dominio_gratis:%d' % fx['pagos'][7]['id']
r, d = ejecutar('atender_solicitud', {'solicitud_id': sid_dom, 'resultado': 'atendida', 'dominio': 'https://www.MiEscuela.com/'})
chk(r.status_code == 200 and pj('valor("SELECT nota FROM tukan_central_atenciones WHERE solicitud_id = ?", ["' + sid_dom + '"])').startswith('Dominio: miescuela.com'), 'atender_solicitud dominio gratis: el dominio queda en la nota')
chk(es_error(ejecutar('atender_solicitud', {'solicitud_id': 'renovacion:%s:vencida:20000101' % ID['G'], 'resultado': 'atendida'})[0], 'no_encontrado', 404), 'atender_solicitud de un recordatorio que no corresponde → 404')
chk(es_error(ejecutar('atender_solicitud', {'solicitud_id': sid_reg, 'resultado': 'cerrada'})[0], 'invalido', 422), 'atender_solicitud con resultado malo → 422')
# responder_ticket
r, d = ejecutar('responder_ticket', {'solicitud_id': 'soporte:%d' % fx['t1'], 'texto': 'Ya lo revisamos: vuelva a intentar', 'estado': 'esperando'})
m = pj('fila("SELECT * FROM soporte_mensajes WHERE ticket_id = ? ORDER BY id DESC", [' + str(fx['t1']) + '])')
tk = pj('fila("SELECT estado, sin_leer_super, sin_leer_cliente FROM soporte_tickets WHERE id = ?", [' + str(fx['t1']) + '])')
chk(r.status_code == 200 and m['de'] == 'soporte' and m['texto'] == 'Ya lo revisamos: vuelva a intentar' and tk == {'estado': 'esperando', 'sin_leer_super': 0, 'sin_leer_cliente': 1}
    and d.get('solicitud', {}).get('estado') == 'en_proceso', 'responder_ticket: mensaje de soporte, avisa al cliente y queda esperando')
chk(es_error(ejecutar('responder_ticket', {'solicitud_id': 'soporte:%d' % fx['t1'], 'texto': ''})[0], 'invalido', 422), 'responder_ticket sin texto → 422')
chk(es_error(ejecutar('responder_ticket', {'solicitud_id': 'demo_pedida:%d' % fx['sd1'], 'texto': 'hola'})[0], 'no_permitido', 403), 'responder_ticket a algo que no es ticket → 403')
# crear_demo (con el pedido de demo) y repetido
oid_d = op_id()
datos_demo = {'nombre': 'V2 Demo Desde Central', 'contacto_nombre': 'Ana Pérez', 'email': 'ana@v2.ejemplo.com', 'telefono': '0991112233', 'plan': None, 'dias': 9,
              'solicitud_id': 'demo_pedida:%d' % fx['sd1'], 'enviar_correo': True, 'extra': {'ciudad': 'Cuenca'} if ACADEMIA else {'tipo': 'liga', 'ciudad': 'Cuenca'}}
cuentas_antes = int(php('echo (int)valor("SELECT COUNT(*) FROM ligas");'))
r1, d1 = ejecutar('crear_demo', datos_demo, None, oid_d)
res = d1.get('resultado', {})
nueva = pj('fila("SELECT * FROM ligas WHERE id = ?", [' + str(res.get('cliente_id', 0)) + '])') or {}
chk(r1.status_code == 200 and nueva.get('es_prueba') == 1 and nueva.get('vence') == (HOY + timedelta(days=9)).isoformat() and nueva.get('origen') == 'demo_pedida' and nueva.get('ciudad') == 'Cuenca',
    'crear_demo: cuenta en prueba por 9 días con su origen ' + str(d1.get('mensaje')))
chk(nueva.get('tipo') == ('escuela' if ACADEMIA else 'liga') and nueva.get('plan_id') == fx['pl'] and nueva.get('vendedor_id') == fx['vend'], 'crear_demo: tipo, plan y vendedor del pedido de demo')
chk(pj('valor("SELECT estado FROM solicitudes_demo WHERE id = ?", [' + str(fx['sd1']) + '])') == 'creada' and pj('valor("SELECT liga_id FROM solicitudes_demo WHERE id = ?", [' + str(fx['sd1']) + '])') == nueva.get('id'),
    'crear_demo: el pedido de demo queda «Demo creada» con la cuenta')
clave_d = res.get('acceso', {}).get('clave', '')
chk(len(clave_d) == 10 and res.get('acceso', {}).get('usuario') == 'ana@v2.ejemplo.com' and clave_d in res.get('mensaje_whatsapp', '') and res.get('whatsapp_url', '').startswith('https://wa.me/593991112233?text='),
    'crear_demo: accesos, mensaje y enlace de WhatsApp')
s, rr = entrar('ana@v2.ejemplo.com', clave_d)
chk('salir' in rr.text.lower(), 'crear_demo: el dueño entra con la clave entregada')
r2, d2 = ejecutar('crear_demo', datos_demo, None, oid_d)
chk(r2.status_code == 200 and d2.get('repetida') is True and d2.get('resultado', {}).get('acceso', {}).get('clave') is None and d2['resultado']['acceso'].get('clave_oculta') is True
    and clave_d not in json.dumps(d2, ensure_ascii=False), 'crear_demo repetido: misma respuesta, con clave null (y sin la clave en el mensaje)')
chk(int(php('echo (int)valor("SELECT COUNT(*) FROM ligas");')) == cuentas_antes + 1, 'crear_demo repetido: no crea dos cuentas')
chk(es_error(ejecutar('crear_demo', dict(datos_demo, email='otra@v2.ejemplo.com'))[0], 'conflicto', 409), 'crear_demo con un pedido que ya tiene demo → 409')
r, d = ejecutar('crear_demo', dict(datos_demo, solicitud_id=None))
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.email', 'crear_demo con un correo que ya existe → 422 datos.email')
r, d = ejecutar('crear_demo', {'nombre': 'V2 Demo Simple', 'contacto_nombre': 'Pedro Paz', 'email': 'pedro@v2.ejemplo.com', 'telefono': '0998887766', 'enviar_correo': False})
nueva2 = pj('fila("SELECT * FROM ligas WHERE id = ?", [' + str(d.get('resultado', {}).get('cliente_id', 0)) + '])') or {}
chk(r.status_code == 200 and nueva2.get('origen') == 'central' and nueva2.get('vence') == (HOY + timedelta(days=7)).isoformat() and d['resultado'].get('correo_enviado') is False,
    'crear_demo sin pedido: origen central y los días por defecto (7)')
chk(es_error(ejecutar('crear_demo', {'nombre': 'X', 'contacto_nombre': 'Pedro', 'email': 'p@x.com', 'telefono': '0998887766'})[0], 'invalido', 422), 'crear_demo con nombre corto → 422')
chk(es_error(ejecutar('crear_demo', {'nombre': 'V2 Otro', 'contacto_nombre': 'Pedro Paz', 'email': 'p3@x.com', 'telefono': '123'})[0], 'invalido', 422), 'crear_demo con celular corto → 422')
# ejecutar: errores del pedido
chk(es_error(post2('ejecutar', {'accion': 'nota_interna', 'cliente_id': ID['V8'], 'datos': {}}), 'invalido', 422), 'ejecutar sin operacion_id → 422')
chk(es_error(post2('ejecutar', {'operacion_id': 'XYZ', 'accion': 'nota_interna', 'cliente_id': ID['V8'], 'datos': {}}), 'invalido', 422), 'ejecutar con operacion_id malo → 422')
chk(es_error(ejecutar('nota_interna', {'texto': 'x'})[0], 'invalido', 422), 'ejecutar sin cliente_id → 422')
# Bitácora y privacidad
chk(int(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE tabla = 'tukan_central' AND operacion = 'evento' AND usuario LIKE '%" + EMAIL_CENTRAL + "%'\");")) >= 20, 'bitácora: cada ejecutar correcto deja su línea con el correo del Central')
chk(bitacora_central('Suspendió la cuenta') == 1 and bitacora_central('Creó la demo V2 Demo Desde Central') == 1 and bitacora_central('Pago anulado') == 2, 'bitácora: las líneas dicen qué se hizo')
ops_txt = php("echo implode(' ', array_column(filas('SELECT respuesta FROM tukan_central_operaciones'), 'respuesta'));")
bit_txt = php("echo implode(' ', array_column(filas('SELECT COALESCE(descripcion, \\'\\') || COALESCE(despues, \\'\\') AS t FROM bitacora'), 't'));")
chk(clave and clave_d and clave not in ops_txt and clave_d not in ops_txt, 'privacidad: las claves de un solo uso no se guardan en tukan_central_operaciones')
chk(clave not in bit_txt and clave_d not in bit_txt, 'privacidad: las claves no quedan en la bitácora')
chk(int(php("echo (int)valor(\"SELECT COUNT(*) FROM tukan_central_operaciones WHERE estado = 'en_curso'\");")) == 0, 'operaciones: ninguna quedó en curso')
chk(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE tabla = 'tukan_central_operaciones'\");") == '0', 'operaciones: no llenan la bitácora')
e = j(get1('estado', token1()))
chk('@' not in json.dumps(e, ensure_ascii=False).replace('"producto"', ''), 'estado: sin correos ni datos personales')
cl2 = pedir('clientes', {'por_pagina': 200})
chk(cl2.get('contadores') == {'total': e['negocios']['total'], 'prueba': e['negocios']['prueba'], 'activo': e['negocios']['activos'], 'por_vencer': e['negocios']['por_vencer'],
                              'vencido': e['negocios']['vencidos'], 'suspendido': e['negocios']['suspendidos']}, 'después de las operaciones: clientes.contadores sigue igual a estado.negocios')

# ---------- 9b) Anexo A: pausar, borrar (papelera), restaurar y borrado definitivo ----------
FIXA = r'''
$acad = tc_producto()[2];
$T = $acad ? 'escuela' : 'liga';
guardar_ajuste('ventas_whatsapp', '0991234567');
$ins = function (string $t, array $v): int {
    foreach (db()->query("PRAGMA table_info($t)")->fetchAll() as $c) {
        if ((int)$c['notnull'] === 1 && $c['dflt_value'] === null && (int)$c['pk'] === 0 && !array_key_exists($c['name'], $v)) {
            $ty = strtoupper((string)$c['type']);
            $v[$c['name']] = str_contains($ty, 'INT') ? 0 : (str_contains($ty, 'REAL') ? 0.0 : 'x');
        }
    }
    q('INSERT INTO ' . $t . ' (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(', ', array_fill(0, count($v), '?')) . ')', array_values($v));
    return (int)db()->lastInsertId();
};
$r = [];
foreach (['BO' => 'Anexo Borrar', 'OT' => 'Anexo Otra', 'PA' => 'Anexo Pausar', 'PB' => 'Anexo Pausa Borrar', 'CR' => 'Anexo Cron'] as $k => $n) {
    $lid = $ins('ligas', ['nombre' => $n . ' ' . $k, 'slug' => 'anexo-' . strtolower($k) . '-' . bin2hex(random_bytes(3)), 'vence' => date('Y-m-d', strtotime('+40 days')), 'es_prueba' => 0,
        'activa' => 1, 'es_demo' => 0, 'tipo' => $T, 'plan_id' => (int)valor('SELECT id FROM planes ORDER BY id LIMIT 1'), 'contacto_nombre' => 'Dueño ' . $k,
        'contacto_telefono' => '0998877' . sprintf('%03d', count($r)), 'contacto_email' => strtolower($k) . '@anexo.ejemplo.com', 'ciudad' => 'Quito']);
    $u = $ins('usuarios', ['nombre' => 'Dueño ' . $k, 'email' => strtolower($k) . '@anexo.ejemplo.com', 'clave' => password_hash('clave12345', PASSWORD_DEFAULT), 'rol' => 'liga', 'liga_id' => $lid, 'perfil' => 'admin', 'activo' => 1]);
    $e1 = $ins('equipos', ['liga_id' => $lid, 'nombre' => 'Equipo A ' . $k]);
    $e2 = $ins('equipos', ['liga_id' => $lid, 'nombre' => 'Equipo B ' . $k]);
    $j1 = $ins('jugadores', ['equipo_id' => $e1, 'nombres' => 'Juan', 'apellidos' => 'Uno ' . $k]);
    $ins('jugadores', ['equipo_id' => $e1, 'nombres' => 'Pedro', 'apellidos' => 'Dos ' . $k]);
    $ins('jugadores', ['equipo_id' => $e2, 'nombres' => 'Luis', 'apellidos' => 'Tres ' . $k]);
    $t = $ins('torneos', ['liga_id' => $lid, 'nombre' => 'Torneo ' . $k, 'temporada' => date('Y')]);
    $pt = $ins('partidos', ['torneo_id' => $t, 'local_id' => $e1, 'visitante_id' => $e2]);
    $ins('eventos', ['partido_id' => $pt, 'equipo_id' => $e1, 'jugador_id' => $j1, 'tipo' => 'gol', 'minuto' => 10]);
    $ins('eventos', ['partido_id' => $pt, 'equipo_id' => $e1, 'jugador_id' => $j1, 'tipo' => 'gol', 'minuto' => 20]);
    $g = $ins('esc_grupos', ['liga_id' => $lid, 'nombre' => 'Grupo ' . $k]);
    $a1 = $ins('esc_alumnos', ['liga_id' => $lid, 'nombres' => 'Ana', 'apellidos' => 'Alumna ' . $k, 'estado' => 'activo']);
    $a2 = $ins('esc_alumnos', ['liga_id' => $lid, 'nombres' => 'Eva', 'apellidos' => 'Alumna ' . $k, 'estado' => 'activo']);
    $se = $ins('esc_sesiones', ['grupo_id' => $g, 'fecha' => date('Y-m-d')]);
    $ins('esc_asistencia', ['sesion_id' => $se, 'alumno_id' => $a1, 'estado' => 'P']);
    $ins('esc_asistencia', ['sesion_id' => $se, 'alumno_id' => $a2, 'estado' => 'T']);
    $ins('esc_cargos', ['liga_id' => $lid, 'alumno_id' => $a1, 'descripcion' => 'Pensión', 'monto' => 30, 'tipo' => 'mensualidad', 'estado' => 'pendiente']);
    $ins('esc_cargos', ['liga_id' => $lid, 'alumno_id' => $a2, 'descripcion' => 'Pensión', 'monto' => 30, 'tipo' => 'mensualidad', 'estado' => 'pendiente']);
    $ins('pagos_suscripcion', ['liga_id' => $lid, 'plan_id' => (int)valor('SELECT id FROM planes ORDER BY id LIMIT 1'), 'monto' => 77.0, 'meses' => 1, 'desde' => date('Y-m-d', strtotime('-10 days')),
        'hasta' => date('Y-m-d', strtotime('+40 days')), 'forma_pago' => 'transferencia', 'fecha' => date('Y-m-d', strtotime('-10 days')), 'registrado_por' => 'Super Prueba']);
    $tk = $ins('soporte_tickets', ['liga_id' => $lid, 'autor' => 'Dueño ' . $k, 'email' => strtolower($k) . '@anexo.ejemplo.com', 'tipo' => 'problema', 'asunto' => 'Ayuda ' . $k,
        'prioridad' => 'normal', 'estado' => 'nuevo', 'sin_leer_super' => 1, 'creado' => date('Y-m-d H:i:s'), 'actualizado' => date('Y-m-d H:i:s')]);
    $ins('soporte_mensajes', ['ticket_id' => $tk, 'de' => 'cliente', 'autor' => 'Dueño ' . $k, 'texto' => 'Hola ' . $k, 'creado' => date('Y-m-d H:i:s')]);
    $ins('telegram_cola', ['liga_id' => $lid, 'chat_id' => '12345', 'texto' => 'Aviso pendiente ' . $k]);
    $r[$k] = ['id' => $lid, 'usuario' => $u, 'ticket' => $tk];
}
echo json_encode($r);
'''
AX = json.loads(php(FIXA))
AID = {k: str(v['id']) for k, v in AX.items()}
ESPERADO = {'ligas': 1, 'usuarios': 1, 'equipos': 2, 'jugadores': 3, 'torneos': 1, 'partidos': 1, 'eventos': 2, 'esc_grupos': 1, 'esc_alumnos': 2, 'esc_sesiones': 1,
            'esc_asistencia': 2, 'esc_cargos': 2, 'pagos_suscripcion': 1, 'soporte_tickets': 1, 'soporte_mensajes': 1, 'telegram_cola': 1}
ligaA = lambda k: pj('fila("SELECT * FROM ligas WHERE id = ?", [' + AID[k] + '])')
slugA = {k: ligaA(k)['slug'] for k in AID}
CUENTA_TABLAS = 'json_encode(array_combine(array_keys(papelera_plan()), array_map(fn($t) => (int)valor("SELECT COUNT(*) FROM \\"$t\\""), array_keys(papelera_plan()))))'
def filas_de(k):  # filas de una cuenta por tabla (con la misma regla que la papelera)
    return json.loads(php('echo json_encode(papelera_marcar(' + AID[k] + ', papelera_plan()));') or '{}')
def totales():
    return json.loads(php('echo ' + CUENTA_TABLAS + ';') or '{}')
sin_log = lambda x: {t: n for t, n in x.items() if t not in ('bitacora', 'tukan_central_operaciones')}
def estado_v1():
    return j(get1('estado', token1()))

cap = pedir('capacidades')
chk([o['valor'] for o in cap.get('tipos_pausa', [])] == ['falta_pago', 'pedido_cliente', 'revision', 'abuso', 'otro'] and cap.get('papelera') is True
    and 'estado' in cap.get('filtros_clientes', []) and cap.get('limites', {}).get('papelera_dias') == 30, 'anexo A: capacidades con tipos de pausa, papelera y filtro estado')

# --- pausar ---
r, d = ejecutar('pausar', {'motivo': 'No pagó'}, AID['PA'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.tipo', 'pausar sin tipo → 422 datos.tipo')
r, d = ejecutar('pausar', {'tipo': 'vacaciones', 'motivo': 'No pagó'}, AID['PA'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.tipo', 'pausar con tipo desconocido → 422 datos.tipo')
r, d = ejecutar('pausar', {'tipo': 'falta_pago', 'motivo': 'x'}, AID['PA'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.motivo', 'pausar con motivo de 1 letra → 422 datos.motivo')
r, d = ejecutar('pausar', {'tipo': 'falta_pago', 'motivo': 'x' * 301}, AID['PA'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.motivo', 'pausar con motivo de 301 letras → 422 datos.motivo')
s_pa, _ = entrar('pa@anexo.ejemplo.com')   # sesión abierta antes de pausar
tok_pa = csrf(s_pa.get(B + 'admin.php?p=clave').text)
oid_p = op_id()
r, d = ejecutar('pausar', {'tipo': 'falta_pago', 'motivo': 'Falta el pago de octubre'}, AID['PA'], oid_p)
L = ligaA('PA')
chk(r.status_code == 200 and L['activa'] == 0 and L['suspendida_tipo'] == 'falta_pago' and L['suspendida_motivo'] == 'Falta el pago de octubre' and L['suspendida_en'] != '',
    'pausar: suspensión real con tipo, motivo y fecha')
chk(d.get('cliente', {}).get('estado') == 'suspendido' and d['cliente'].get('estado_detalle') == 'pausada' and (d['cliente'].get('pausa') or {}).get('tipo') == 'falta_pago'
    and d.get('resultado', {}).get('tipo') == 'falta_pago', 'pausar: el cliente devuelto dice pausada con su tipo')
r2, d2 = ejecutar('pausar', {'tipo': 'falta_pago', 'motivo': 'Falta el pago de octubre'}, AID['PA'], oid_p)
chk(d2.get('repetida') is True, 'pausar repetido con el mismo operacion_id → repetida')
r2, d2 = ejecutar('pausar', {'tipo': 'falta_pago', 'motivo': 'Falta el pago de octubre'}, AID['PA'])
chk(r2.status_code == 200 and d2.get('sin_cambios') is True, 'pausar otra vez igual → sin_cambios')
chk(bitacora_central('Pausó la cuenta (Falta de pago)') >= 1, 'pausar: auditado en la bitácora con el correo del Central')
p = s_pa.get(B + 'admin.php')
chk(p.status_code == 403 and 'Tu cuenta está pausada' in p.text and 'Falta el pago de octubre' in p.text and 'wa.me/593991234567' in p.text,
    'pausar: el usuario (con la sesión abierta) ve «Tu cuenta está pausada» con el motivo y el WhatsApp de Tukán')
s2, p = entrar('pa@anexo.ejemplo.com')
chk('Tu cuenta está pausada' in p.text and 'id="cuenta-pausada"' in p.text, 'pausar: al entrar con su clave ve la pantalla de pausa')
p = s_pa.post(B + 'admin.php?p=equipos', data={'csrf': tok_pa, 'accion': 'guardar', 'nombre': 'No debe'}, headers={'CF-Connecting-IP': ip()})
chk(tok_pa != '' and p.status_code == 403 and 'Tu cuenta está pausada' in p.text and int(php('echo (int)valor("SELECT COUNT(*) FROM equipos WHERE nombre = \'No debe\'");')) == 0, 'pausar: no puede guardar nada')
p = requests.get(B + 'index.php', params={'l': slugA['PA']})
chk(p.status_code == 503 and 'temporalmente no disponible' in p.text, 'pausar: su página pública dice «temporalmente no disponible»')
r, d = ejecutar('pausar', {'tipo': 'abuso', 'motivo': 'Fraude con tarjetas robadas'}, AID['PA'])
chk(r.status_code == 200 and d.get('sin_cambios') is False and ligaA('PA')['suspendida_tipo'] == 'abuso', 'pausar: se puede cambiar el tipo y el motivo')
p = s2.get(B + 'admin.php')
chk('Tu cuenta está pausada' in p.text and 'Fraude' not in p.text and 'Estamos revisando su cuenta' in p.text, 'pausa por abuso: el cliente ve un texto general, no el motivo')
fs = pedir('cliente', {'id': AID['PA']}).get('cliente', {})
acc = {a['accion']: a for a in fs.get('acciones_posibles', [])}
chk('reactivar' in acc and 'pausar' in acc and 'borrar_cliente' in acc and 'suspender' not in acc and fs.get('pausa', {}).get('motivo_cliente', '').startswith('Estamos revisando'),
    'cliente pausado: acciones reactivar / cambiar la pausa / borrar')
chk(any('Pausó la cuenta' in x.get('texto', '') for x in fs.get('registro', [])), 'cliente pausado: el historial de la ficha dice quién pausó y cuándo')
fo = pedir('cliente', {'id': AID['OT']}).get('cliente', {})
acc_o = {a['accion']: a for a in fo.get('acciones_posibles', [])}
chk('pausar' in acc_o and [c['campo'] for c in acc_o['pausar']['campos']] == ['tipo', 'motivo'] and 'borrar_cliente' in acc_o
    and [c['campo'] for c in acc_o['borrar_cliente']['campos']] == ['confirmar', 'motivo'] and acc_o['borrar_cliente']['estilo'] == 'peligro' and 'restaurar_cliente' not in acc_o,
    'cliente normal: acciones pausar (tipo, motivo) y borrar (confirmar, motivo)')
r, d = ejecutar('reactivar', {}, AID['PA'])
L = ligaA('PA')
chk(r.status_code == 200 and L['activa'] == 1 and L['suspendida_tipo'] == '' and L['suspendida_motivo'] == '' and d.get('cliente', {}).get('pausa') is None, 'reactivar: quita la pausa')
chk(s2.get(B + 'admin.php').status_code == 200 and requests.get(B + 'index.php', params={'l': slugA['PA']}).status_code == 200, 'reactivar: vuelve a entrar y su página pública vuelve')

# --- borrar_cliente (papelera) ---
e0 = estado_v1()
cl0 = pedir('clientes', {'por_pagina': 200})
fin0 = pedir('finanzas', {})
chk(all(filas_de('BO').get(t) == n for t, n in ESPERADO.items()), 'papelera: el plan sacado del esquema encuentra todas las filas de la cuenta ' + str({t: filas_de('BO').get(t) for t in ESPERADO}))
s_bo, _ = entrar('bo@anexo.ejemplo.com')
chk(s_bo.get(B + 'admin.php').status_code == 200, 'antes de borrar: el dueño entra')
antes_bo = filas_de('BO')   # después de entrar (al abrir el panel, el programa puede crear cosas, p. ej. las pensiones del mes)
r, d = ejecutar('borrar_cliente', {'confirmar': 'Anexo Borrar', 'motivo': 'Pidió la baja'}, AID['BO'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.confirmar', 'borrar_cliente con el nombre mal escrito → 422 datos.confirmar')
r, d = ejecutar('borrar_cliente', {'motivo': 'Pidió la baja'}, AID['BO'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.confirmar', 'borrar_cliente sin confirmar → 422 datos.confirmar')
r, d = ejecutar('borrar_cliente', {'confirmar': 'Anexo Borrar BO'}, AID['BO'])
chk(es_error(r, 'invalido', 422) and d.get('campo') == 'datos.motivo', 'borrar_cliente sin motivo → 422 datos.motivo')
chk(ligaA('BO')['eliminada'] == '', 'borrar_cliente con errores: no borra nada')
oid_b = op_id()
r, d = ejecutar('borrar_cliente', {'confirmar': 'Anexo Borrar BO', 'motivo': 'Pidió la baja por correo'}, AID['BO'], oid_b)
L = ligaA('BO')
copia = d.get('copia') or ''
chk(r.status_code == 200 and d.get('ok') is True and copia.startswith('data/borrados/' + AID['BO'] + '_') and copia.endswith('.json.gz')
    and d.get('borrar_definitivo_el') == (HOY + timedelta(days=30)).isoformat() and d.get('resultado', {}).get('copia') == copia,
    'borrar_cliente: responde ok, copia (fuera de public/) y borrar_definitivo_el (30 días)')
chk(L['eliminada'] != '' and L['activa'] == 0 and L['eliminada_activa'] == 1 and L['eliminada_motivo'] == 'Pidió la baja por correo' and L['eliminada_hasta'] == d.get('borrar_definitivo_el')
    and L['eliminada_copia'] == copia and 'Dimitry' in L['eliminada_por'], 'borrar_cliente: borrado lógico con motivo, quién y fecha')
chk(d.get('cliente', {}).get('papelera', {}).get('motivo') == 'Pidió la baja por correo' and d['cliente'].get('estado_detalle') == 'papelera', 'borrar_cliente: el cliente devuelto está en la papelera')
ruta = os.path.join(REPO, copia)
import gzip
try:
    cop = json.loads(gzip.open(ruta).read().decode())
except Exception as ex:
    cop = {}
    print('   copia ilegible:', ex)
chk(cop.get('formato') == 'tukan-papelera-1' and cop.get('cuenta', {}).get('id') == int(AID['BO']) and cop.get('motivo') == 'Pidió la baja por correo', 'copia: gzip JSON legible con la cuenta y el motivo')
cnt = {t: len(x.get('filas', [])) for t, x in cop.get('tablas', {}).items()}
chk(sin_log(cnt) == sin_log(antes_bo) and cop.get('conteo') == cnt and cnt.get('bitacora', 0) >= antes_bo.get('bitacora', 0), 'copia: tiene TODAS las filas de la cuenta, tabla por tabla (' + str(sum(cnt.values())) + ' filas)')
if sin_log(cnt) != sin_log(antes_bo):
    print('   copia:', {t: (cnt.get(t), antes_bo.get(t)) for t in set(cnt) | set(antes_bo) if cnt.get(t) != antes_bo.get(t)})
chk(all(cnt.get(t) == n for t, n in ESPERADO.items()), 'copia: incluye las tablas hijas (jugadores, eventos, asistencia, mensajes de soporte…)')
jug = cop.get('tablas', {}).get('jugadores', {})
chk(jug and sorted(r_[jug['columnas'].index('apellidos')] for r_ in jug['filas']) == ['Dos BO', 'Tres BO', 'Uno BO'], 'copia: las filas tienen sus datos (jugadores de la cuenta, ninguno de otra)')
dump = json.dumps(cop.get('tablas', {}), ensure_ascii=False)
chk(not any(x in dump for x in ('Anexo Otra OT', 'ot@anexo.ejemplo.com', 'Uno OT', 'Alumna OT', 'Ayuda OT', 'Equipo A OT')), 'copia: no trae nada de otra cuenta')
chk(os.path.isfile(ruta) and not ruta.startswith(os.path.join(REPO, 'public')) and requests.get(B + copia).status_code == 404 and requests.get(B + '../' + copia).status_code in (400, 404),
    'copia: guardada fuera de la carpeta pública (no se puede bajar por la web)')
r2, d2 = ejecutar('borrar_cliente', {'confirmar': 'Anexo Borrar BO', 'motivo': 'Pidió la baja por correo'}, AID['BO'], oid_b)
chk(d2.get('repetida') is True and d2.get('copia') == copia, 'borrar_cliente repetido con el mismo operacion_id → repetida, misma copia')
r2, d2 = ejecutar('borrar_cliente', {'confirmar': AID['BO'], 'motivo': 'Otra vez'}, AID['BO'])
chk(r2.status_code == 200 and d2.get('sin_cambios') is True and d2.get('copia') == copia and len([f for f in os.listdir(os.path.join(REPO, 'data', 'borrados')) if f.startswith(AID['BO'] + '_')]) == 1,
    'borrar_cliente de uno que ya está en la papelera (confirmando con el id) → sin_cambios, sin otra copia')
chk(bitacora_central('Borró la cuenta (a la papelera') >= 1, 'borrar_cliente: auditado en la bitácora')
# desaparece de todo
e1 = estado_v1()
cl1 = pedir('clientes', {'por_pagina': 200})
chk(e1['negocios']['total'] == e0['negocios']['total'] - 1 and cl1.get('total') == cl0.get('total') - 1 and AID['BO'] not in [c['id'] for c in cl1.get('clientes', [])],
    'papelera: ya no sale en estado ni en clientes')
chk(cl1.get('contadores') == {'total': e1['negocios']['total'], 'prueba': e1['negocios']['prueba'], 'activo': e1['negocios']['activos'], 'por_vencer': e1['negocios']['por_vencer'],
                              'vencido': e1['negocios']['vencidos'], 'suspendido': e1['negocios']['suspendidos']} and cl1.get('papelera_total') == 1, 'papelera: contadores = estado.negocios y papelera_total = 1')
pp = pedir('clientes', {'filtros': {'estado': 'papelera'}})
pp2 = pedir('clientes', {'estado': 'papelera'})
c_bo = (pp.get('clientes') or [{}])[0]
chk(pp.get('total') == 1 and c_bo.get('id') == AID['BO'] and c_bo.get('papelera', {}).get('hasta') == d.get('borrar_definitivo_el') and c_bo['papelera'].get('motivo') == 'Pidió la baja por correo'
    and c_bo['papelera'].get('desde', '').startswith(HOY.isoformat()) and [a['accion'] for a in c_bo.get('acciones_posibles', [])] == ['restaurar_cliente'] and pp2.get('total') == 1,
    'clientes con filtros.estado = papelera: lo lista con papelera {desde, hasta, motivo} y Restaurar')
chk(pp.get('contadores') == cl1.get('contadores'), 'clientes papelera: los contadores siguen siendo los de los clientes normales')
fb = pedir('cliente', {'id': AID['BO']}).get('cliente', {})
chk(fb.get('papelera', {}).get('copia') == copia and [a['accion'] for a in fb.get('acciones_posibles', [])] == ['restaurar_cliente'], 'cliente (ficha) de la papelera: solo Restaurar')
chk(es_error(ejecutar('registrar_pago', {'plan': str(fx['pl']), 'meses': 1}, AID['BO'])[0], 'no_encontrado', 404) and es_error(ejecutar('pausar', {'tipo': 'otro', 'motivo': 'abc'}, AID['BO'])[0], 'no_encontrado', 404),
    'papelera: las demás operaciones dan 404')
sols = pedir('solicitudes', {'estado': 'todas'}).get('solicitudes', [])
chk(not any(s_.get('cliente_id') == AID['BO'] for s_ in sols) and any(s_.get('cliente_id') == AID['OT'] for s_ in sols), 'papelera: sus tickets y avisos ya no salen en solicitudes (los de otra cuenta sí)')
fin1 = pedir('finanzas', {})
mrr_bo = next((c['mensual_estimado'] for c in cl0.get('clientes', []) if c['id'] == AID['BO']), 0)
chk(fin1.get('totales', {}).get('ingresos') == fin0.get('totales', {}).get('ingresos') and abs(fin1.get('mrr', 0) - (fin0.get('mrr', 0) - mrr_bo)) < 0.01,
    'finanzas: lo que pagó antes de borrarla sigue en los meses pasados y sale del MRR')
r = requests.get(B + 'index.php', params={'l': slugA['BO']})
chk(r.status_code == 404, 'papelera: su página pública da 404')
chk(s_bo.get(B + 'admin.php', allow_redirects=False).headers.get('Location', '') == 'admin.php?p=login', 'papelera: la sesión que tenía abierta ya no sirve')
s3, p = entrar('bo@anexo.ejemplo.com')
chk('Correo o contraseña incorrectos' in p.text and s3.get(B + 'admin.php', allow_redirects=False).status_code in (302, 303), 'papelera: el dueño ya no puede entrar')
chk(php('tg_encolar(' + AID['BO'] + ', "999", "no debe salir"); echo (int)valor("SELECT COUNT(*) FROM telegram_cola WHERE texto = \'no debe salir\'");') == '0'
    and php('echo (int)valor("SELECT COUNT(*) FROM telegram_cola WHERE liga_id = ? AND enviado = \'\' AND intentos < 5", [' + AID['BO'] + ']);') == '0',
    'papelera: no se le encolan avisos de Telegram y los pendientes ya no salen')
chk(php('echo enviar_correo("bo@anexo.ejemplo.com", "x", "x", ["x"], null, ' + AID['BO'] + ') ? "si" : "no";') == 'no', 'papelera: no se le mandan correos')
s_su, _ = entrar('super@prueba.com')
p = s_su.get(B + 'admin.php?p=ligas')
pz = s_su.get(B + 'admin.php?p=ligas&papelera=1')
chk('Anexo Borrar BO' not in p.text and 'Papelera (1)' in p.text and 'Anexo Borrar BO' in pz.text and 'Pidió la baja por correo' in pz.text and 'Restaurar' in pz.text,
    'súper panel: no sale en Ligas; filtro Papelera con Restaurar')
for pag in ('suscripciones', 'usuarios', 'embudo', 'negocio'):
    tx = s_su.get(B + 'admin.php?p=' + pag).text
    chk('Anexo Borrar BO' not in tx and 'bo@anexo.ejemplo.com' not in tx, 'súper panel: tampoco sale en ' + pag)
    if 'Anexo Borrar BO' in tx or 'bo@anexo.ejemplo.com' in tx:
        i = max(tx.find('Anexo Borrar BO'), tx.find('bo@anexo.ejemplo.com'))
        print('   →', re.sub(r'\s+', ' ', tx[max(0, i - 300):i + 100]))
chk(s_su.get(B + 'admin.php?p=ligas&entrar=' + AID['BO'], allow_redirects=False).headers.get('Location', '').endswith('papelera=1'), 'súper panel: no se puede entrar a una cuenta de la papelera')
# restaurar
r, d = ejecutar('restaurar_cliente', {}, AID['OT'])
chk(r.status_code == 200 and d.get('sin_cambios') is True, 'restaurar_cliente de uno que no está en la papelera → sin_cambios')
oid_r = op_id()
r, d = ejecutar('restaurar_cliente', {}, AID['BO'], oid_r)
L = ligaA('BO')
chk(r.status_code == 200 and L['eliminada'] == '' and L['activa'] == 1 and d.get('cliente', {}).get('id') == AID['BO'] and d['cliente'].get('papelera') is None, 'restaurar_cliente: vuelve tal cual (activa)')
chk(sin_log(filas_de('BO')) == sin_log(antes_bo), 'restaurar_cliente: no se perdió ninguna fila')
chk(ejecutar('restaurar_cliente', {}, AID['BO'], oid_r)[1].get('repetida') is True and ejecutar('restaurar_cliente', {}, AID['BO'])[1].get('sin_cambios') is True, 'restaurar_cliente: idempotente')
chk(requests.get(B + 'index.php', params={'l': slugA['BO']}).status_code == 200 and entrar('bo@anexo.ejemplo.com')[0].get(B + 'admin.php').status_code == 200
    and estado_v1()['negocios']['total'] == e0['negocios']['total'], 'restaurar_cliente: vuelve su página, su acceso y sus cifras')
chk(bitacora_central('Restauró la cuenta desde la papelera') >= 1, 'restaurar_cliente: auditado')
# una pausada que se borra y se restaura vuelve PAUSADA
ejecutar('pausar', {'tipo': 'pedido_cliente', 'motivo': 'Vacaciones de fin de año'}, AID['PB'])
r, d = ejecutar('borrar_cliente', {'confirmar': 'Anexo Pausa Borrar PB', 'motivo': 'Prueba'}, AID['PB'])
chk(r.status_code == 200 and requests.get(B + 'index.php', params={'l': slugA['PB']}).status_code == 404, 'pausada y borrada: su página da 404 (no «no disponible»)')
r, d = ejecutar('restaurar_cliente', {}, AID['PB'])
L = ligaA('PB')
chk(L['activa'] == 0 and L['suspendida_tipo'] == 'pedido_cliente' and L['suspendida_motivo'] == 'Vacaciones de fin de año' and d.get('cliente', {}).get('estado_detalle') == 'pausada',
    'pausada, borrada y restaurada: sigue pausada con su motivo')
# súper panel: restaurar desde la papelera
ejecutar('borrar_cliente', {'confirmar': 'Anexo Pausa Borrar PB', 'motivo': 'Prueba 2'}, AID['PB'])
pz = s_su.get(B + 'admin.php?p=ligas&papelera=1')
p = s_su.post(B + 'admin.php?p=ligas', data={'csrf': csrf(pz.text), 'accion': 'restaurar', 'id': AID['PB']}, headers={'CF-Connecting-IP': ip()})
chk(ligaA('PB')['eliminada'] == '' and 'salió de la papelera' in p.text, 'súper panel: Restaurar desde la papelera')

# --- borrado definitivo (fecha simulada) ---
r, d = ejecutar('borrar_cliente', {'confirmar': 'Anexo Borrar BO', 'motivo': 'Ahora sí'}, AID['BO'])
copia2 = d.get('copia') or ''
exp2 = json.loads(gzip.open(os.path.join(REPO, copia2)).read().decode()) if copia2 else {}
otra_antes = filas_de('OT')
tot1 = totales()
pr = json.loads(php('echo json_encode(papelera_purgar(date("Y-m-d", strtotime("+29 days"))));') or '{}')
chk(pr.get('cuentas') == [] and ligaA('BO') is not None, 'borrado definitivo: a los 29 días todavía no')
pr = json.loads(php('echo json_encode(papelera_purgar(date("Y-m-d", strtotime("+30 days"))));') or '{}')
tot2 = totales()
chk(AID['BO'] in [str(k) for k in (pr.get('cuentas') or {})] and ligaA('BO') is None, 'borrado definitivo: a los 30 días (fecha simulada) la tarea la borra de verdad')
dif = {t: tot1.get(t, 0) - tot2.get(t, 0) for t in tot1 if tot1.get(t, 0) != tot2.get(t, 0)}
exp_c = {t: len(x['filas']) for t, x in exp2.get('tablas', {}).items()}
chk(sin_log(dif) == sin_log(exp_c), 'borrado definitivo: se borraron exactamente las filas de la copia (ni una más ni una menos) ' + str(sin_log(dif)))
chk(filas_de('OT') == otra_antes, 'borrado definitivo: la otra cuenta quedó intacta')
huerf = php('echo json_encode(db()->query("PRAGMA foreign_key_check")->fetchAll());')
chk(huerf in ('[]', ''), 'borrado definitivo: no quedan filas huérfanas (PRAGMA foreign_key_check)')
quedan = php('$n = 0; foreach (db()->query("SELECT name FROM sqlite_master WHERE type = \'table\'")->fetchAll(PDO::FETCH_COLUMN) as $t) { $c = array_column(db()->query("PRAGMA table_info(\\"$t\\")")->fetchAll(), "name"); if (in_array("liga_id", $c) && $t !== "bitacora") $n += (int)valor("SELECT COUNT(*) FROM \\"$t\\" WHERE liga_id = ?", [' + AID['BO'] + ']); } echo $n;')
chk(quedan == '0', 'borrado definitivo: ninguna tabla con liga_id tiene filas de esa cuenta (salvo la bitácora, que el programa no deja borrar)')
chk(exp2.get('conteo', {}).get('bitacora', 0) > 0, 'borrado definitivo: las líneas de la bitácora de esa cuenta van en la copia')
chk(php('echo valor("SELECT estado FROM ligas_borradas WHERE cuenta_id = ? ORDER BY id DESC LIMIT 1", [' + AID['BO'] + ']);') == 'borrada' and os.path.isfile(os.path.join(REPO, copia2)),
    'borrado definitivo: queda anotado y la copia se guarda')
chk(es_error(ejecutar('restaurar_cliente', {}, AID['BO'])[0], 'no_encontrado', 404) and requests.get(B + 'index.php', params={'l': slugA['BO']}).status_code == 404, 'borrado definitivo: ya no se puede restaurar (404)')
chk(int(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE descripcion LIKE 'Borrado definitivo de la cuenta Anexo Borrar BO%' AND liga_id IS NULL\");")) == 1
    and int(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE tabla = 'jugadores' AND operacion = 'eliminar' AND antes LIKE '%Uno BO%'\");")) == 0,
    'borrado definitivo: anotado en la bitácora, sin copiar los datos borrados en ella')
# la tarea automática real (app/tareas.php) hace el borrado definitivo
r, d = ejecutar('borrar_cliente', {'confirmar': 'Anexo Cron CR', 'motivo': 'Prueba de la tarea'}, AID['CR'])
php('q("UPDATE ligas SET eliminada_hasta = ? WHERE id = ?", [date("Y-m-d", strtotime("-1 day")), ' + AID['CR'] + ']); guardar_ajuste("papelera_revision", "");')
viejo = os.path.join(REPO, 'data', 'borrados', '999999_20240101-000000.json.gz')
with gzip.open(viejo, 'wb') as f_:
    f_.write(b'{}')
subprocess.run(['php', 'app/tareas.php'], cwd=REPO, capture_output=True, text=True, timeout=120)
chk(ligaA('CR') is None and not os.path.exists(viejo) and os.path.isfile(os.path.join(REPO, d.get('copia') or 'x')), 'tarea automática (app/tareas.php): borra la cuenta vencida en la papelera y las copias de más de 1 año')
# aislamiento: en modo academia, una liga que quedó de TuVocalía no se puede borrar ni pausar desde el Central
if ACADEMIA:
    chk(es_error(ejecutar('borrar_cliente', {'confirmar': 'V2 Liga Que Quedo', 'motivo': 'abc'}, ID['K'])[0], 'no_encontrado', 404)
        and es_error(ejecutar('pausar', {'tipo': 'otro', 'motivo': 'abc'}, ID['K'])[0], 'no_encontrado', 404), 'modo academia: una liga no se borra ni se pausa (404)')
chk(es_error(ejecutar('borrar_cliente', {'confirmar': 'V2 Demo Del Sistema', 'motivo': 'abc'}, ID['DEMO'])[0], 'no_encontrado', 404), 'la demo del sistema no se puede borrar (404)')
e9 = estado_v1()
cl9 = pedir('clientes', {'por_pagina': 200})
chk(cl9.get('contadores') == {'total': e9['negocios']['total'], 'prueba': e9['negocios']['prueba'], 'activo': e9['negocios']['activos'], 'por_vencer': e9['negocios']['por_vencer'],
                              'vencido': e9['negocios']['vencidos'], 'suspendido': e9['negocios']['suspendidos']}, 'anexo A: al final, clientes.contadores = estado.negocios')

# ---------- 9c) Anexo B: programas modulares ----------
FIXB = r"""
$acad = tc_producto()[2];
$T = $acad ? 'escuela' : 'liga';
$pl = (int)valor("SELECT id FROM planes WHERE activo = 1 AND tipo_cuenta IN ('ambos', ?) AND precio_mensual > 0 AND modulos <> '*' ORDER BY precio_mensual, id LIMIT 1", [$T]);
$r = [];
foreach (['MA' => 'Modulos Activa', 'MB' => 'Modulos Otra'] as $k => $n) {
    q("INSERT INTO ligas (nombre, slug, vence, es_prueba, activa, es_demo, tipo, plan_id, contacto_nombre, contacto_telefono, contacto_email, ciudad, creado) VALUES (?, ?, ?, 0, 1, 0, ?, ?, ?, '0991230000', ?, 'Quito', ?)",
      [$n, 'mod-' . strtolower($k) . '-' . bin2hex(random_bytes(3)), date('Y-m-d', strtotime('+40 days')), $T, $pl, 'Dueño ' . $k, strtolower($k) . '@mod.ejemplo.com', gmdate('Y-m-d H:i:s')]);
    $lid = (int)db()->lastInsertId();
    q("INSERT INTO pagos_suscripcion (liga_id, plan_id, monto, meses, desde, hasta, forma_pago, nota, fecha, registrado_por) VALUES (?, ?, 10, 1, ?, ?, 'transferencia', 'mod', ?, 'Super Prueba')",
      [$lid, $pl, date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('+40 days')), date('Y-m-d', strtotime('-5 days'))]);
    $r[$k] = $lid;
}
$cat = catalogo_modulos($T);
$del = modulos_del_plan_cuenta(['tipo' => $T], fila('SELECT * FROM planes WHERE id = ?', [$pl]), $cat);
// un módulo con dependencia que el plan no trae, y la dependencia tampoco (para ver que se enciende sola)
$con = null;
foreach ($cat as $k => $x) if ($x['depende_de'] && !in_array($k, $del, true)) { $con = $k; break; }
$libre = array_values(array_filter(array_keys($cat), fn($k) => !in_array($k, $del, true) && !$cat[$k]['depende_de'] && !in_array($k, $cat[$con]['depende_de'] ?? [], true)))[0];
echo json_encode(['ids' => $r, 'pl' => $pl, 'del' => $del, 'con' => $con, 'dep' => $con ? $cat[$con]['depende_de'] : [], 'libre' => $libre, 'quitar' => array_values(array_filter($del, fn($k) => !array_filter($cat, fn($x) => in_array($k, $x['depende_de'], true)) && $k !== $libre))[0],
                  'precios' => array_map(fn($x) => $x['precio_mensual'], $cat), 'plan_mes' => precio_mensual_plan(fila('SELECT * FROM planes WHERE id = ?', [$pl]))]);
"""
BX = json.loads(php(FIXB))
MA, MB = str(BX['ids']['MA']), str(BX['ids']['MB'])
PR = BX['precios']
ligaM = lambda i: pj('fila("SELECT * FROM ligas WHERE id = ?", [' + i + '])')
# acción «modulos»
m = pedir('modulos')
ids_cat = [x['id'] for x in m.get('modulos', [])]
chk(m.get('ok') is True and m.get('accion') == 'modulos' and m.get('base') and isinstance(m.get('base'), list) and len(ids_cat) >= 8
    and all(set(x) >= {'id', 'nombre', 'icono', 'descripcion', 'precio_mensual', 'depende_de', 'grupo'} for x in m['modulos']), 'modulos: base, catálogo con precio, dependencias y grupo')
chk(all(set(p_) >= {'codigo', 'nombre', 'precio_mensual', 'modulos'} for p_ in m.get('planes', [])) and any(p_['codigo'] == str(BX['pl']) and set(p_['modulos']) == set(BX['del']) for p_ in m.get('planes', [])),
    'modulos: cada plan con su precio y sus módulos')
if ACADEMIA:
    chk('vocalia' not in ids_cat and 'arbitros' not in ids_cat, '(academia) modulos: sin los módulos solo de ligas')
# cliente.modulos
c = pedir('cliente', {'id': MA}).get('cliente', {})
cm = c.get('modulos', {})
chk(set(cm.get('del_plan', [])) == set(BX['del']) and cm.get('extra') == [] and cm.get('quitados') == [] and set(cm.get('activos', [])) == set(BX['del'])
    and cm.get('precio_plan') == BX['plan_mes'] and cm.get('mensual') == BX['plan_mes'] and cm.get('precio_acordado') is None, 'cliente.modulos: del plan, activos y mensual = precio del plan ' + str(cm))
bm = [b for b in c.get('acciones_posibles', []) if b['accion'] == 'cambiar_modulos']
chk(bm and any(f['campo'] == 'mod_' + BX['libre'] and f['tipo'] == 'si_no' and f['valor'] is False for f in bm[0]['campos']), 'cliente: botón «Módulos» con un sí/no por módulo')
# encender uno sin dependencias
r, d = ejecutar('cambiar_modulos', {'encender': [BX['libre']], 'motivo': 'Lo pidió por WhatsApp'}, MA)
chk(r.status_code == 200 and d.get('sin_cambios') is False and BX['libre'] in d.get('modulos', {}).get('extra', []) and d['modulos']['mensual'] == round(BX['plan_mes'] + PR[BX['libre']], 2),
    'cambiar_modulos: enciende un extra y el mensual sube su precio')
chk(bitacora_central('Cambió los módulos') >= 1, 'cambiar_modulos: queda en la bitácora con el correo del Central')
chk(d.get('cliente', {}).get('mensual_estimado') == d['modulos']['mensual'], 'cambiar_modulos: mensual_estimado del cliente = plan + extra')
# lo mismo otra vez (otra operación): sin cambios
r, d = ejecutar('cambiar_modulos', {'encender': [BX['libre']]}, MA)
chk(r.status_code == 200 and d.get('sin_cambios') is True, 'cambiar_modulos: repetir lo mismo → sin_cambios')
# mismo operacion_id → repetida
oid = op_id()
r1, d1 = ejecutar('cambiar_modulos', {'apagar': [BX['libre']]}, MA, oid=oid)
r2, d2 = ejecutar('cambiar_modulos', {'apagar': [BX['libre']]}, MA, oid=oid)
chk(d1.get('sin_cambios') is False and d2.get('repetida') is True and BX['libre'] not in ligaM(MA)['modulos_extra'], 'cambiar_modulos: mismo operacion_id → repetida, una sola vez')
# dependencias: encender uno que necesita otro → se enciende solo y lo dice
if BX['con']:
    r, d = ejecutar('cambiar_modulos', {'encender': [BX['con']]}, MA)
    chk(r.status_code == 200 and set(d.get('resultado', {}).get('tambien_encendidos', [])) == set(BX['dep']) - set(BX['del']) and set(BX['dep']) <= set(d['modulos']['activos']),
        'cambiar_modulos: enciende también lo que necesita ' + str(d.get('resultado', {}).get('tambien_encendidos')))
    r, d = ejecutar('cambiar_modulos', {'apagar': BX['dep']}, MA)
    chk(es_error(r, 'invalido', 422) and d.get('detalle', {}).get('motivo') == 'dependencia' and 'depende' in d.get('error', ''), 'cambiar_modulos: no se apaga uno del que depende otro encendido')
    r, d = ejecutar('cambiar_modulos', {'apagar': [BX['con']] + BX['dep']}, MA)
    chk(r.status_code == 200 and BX['con'] not in d['modulos']['activos'], 'cambiar_modulos: apagando los dos a la vez sí se puede')
    if [x for x in BX['dep'] if x in BX['del']]:
        ejecutar('cambiar_modulos', {'encender': [x for x in BX['dep'] if x in BX['del']]}, MA)  # vuelve a como estaba
# quitar uno del plan con descuento, y precio acordado
r, d = ejecutar('cambiar_modulos', {'apagar': [BX['quitar']], 'descuentos': {BX['quitar']: 2}}, MA)
chk(r.status_code == 200 and d['modulos']['quitados'] == [BX['quitar']] and d['modulos']['descuento'] == 2 and d['modulos']['mensual'] == round(BX['plan_mes'] - 2, 2),
    'cambiar_modulos: apaga uno del plan con descuento (mensual = plan − descuento)')
q_txt = php('echo modulo_activo(fila("SELECT * FROM ligas WHERE id = ' + MA + '"), "' + BX['quitar'] + '") ? "si" : "no";')
chk(q_txt == 'no', 'el módulo quitado queda apagado en el programa (modulo_activo)')
r, d = ejecutar('cambiar_modulos', {'precio_acordado': 7.5}, MA)
chk(r.status_code == 200 and d['modulos']['precio_acordado'] == 7.5 and d['modulos']['mensual'] == 7.5, 'cambiar_modulos: precio acordado fijo')
co = pedir('cotizar', {'cliente_id': MA, 'periodo': 'mensual'})
chk(co.get('monto') == 7.5 or co.get('ok') is False, 'cotizar: sugiere el precio acordado ' + str(co.get('monto')))
r, d = ejecutar('cambiar_modulos', {'precio_acordado': None, 'encender': [BX['quitar']]}, MA)
chk(r.status_code == 200 and d['modulos']['precio_acordado'] is None and d['modulos']['quitados'] == [], 'cambiar_modulos: quitar el precio acordado y volver a encender')
# formulario sí/no de la ficha
r, d = ejecutar('cambiar_modulos', {'mod_' + BX['libre']: True}, MA)
chk(r.status_code == 200 and BX['libre'] in d['modulos']['activos'], 'cambiar_modulos: el formulario sí/no de la ficha funciona')
r, d = ejecutar('cambiar_modulos', {'encender': ['no_existe']}, MA)
chk(es_error(r, 'invalido', 422), 'cambiar_modulos: módulo que no existe → 422')
# finanzas por módulo
f = pedir('finanzas', {})
pm = {x['modulo']: x for x in f.get('por_modulo', [])}
chk(BX['libre'] in pm and pm[BX['libre']]['clientes'] >= 1 and pm[BX['libre']]['mensual'] >= PR[BX['libre']], 'finanzas: por_modulo con el ingreso estimado de los extras')
# solicitud cambio_modulos: aprobar (= cambiar_modulos con solicitud_id)
sid = 'cambio_modulos:%d' % fx['ped1']
so = pedir('solicitud', {'id': sid}).get('solicitud', {})
acc = {b['accion']: b for b in so.get('acciones_posibles', [])}
chk(so.get('tipo') == 'cambio_modulos' and so.get('estado') == 'pendiente' and 'cambiar_modulos' in acc and 'atender_solicitud' in acc and acc['cambiar_modulos']['datos'].get('encender') == [fx['modped']],
    'solicitud cambio_modulos: Aprobar y No aprobar')
r, d = ejecutar('cambiar_modulos', dict(acc['cambiar_modulos']['datos']), ID['V8'])
chk(r.status_code == 200 and fx['modped'] in d.get('modulos', {}).get('activos', []) and (d.get('solicitud') or {}).get('estado') == 'atendida', 'aprobar pedido: enciende el módulo y la solicitud queda atendida')
r, d = ejecutar('cambiar_modulos', dict(acc['cambiar_modulos']['datos']), ID['V8'])
chk(es_error(r, 'conflicto', 409) and d.get('detalle', {}).get('motivo') == 'ya_atendida', 'aprobar otra vez el mismo pedido → 409 ya atendido')
# pedido desde «Mi plan» (pantalla) y No aprobar desde el Central
q_id = php("echo crear_pedido_modulos(fila('SELECT * FROM ligas WHERE id = " + MB + "'), 'Dueño MB', ['" + BX['libre'] + "'], [], 'por favor');")
sid2 = 'cambio_modulos:' + q_id
chk(any(x['id'] == sid2 for x in pedir('solicitudes', {'tipos': ['cambio_modulos']}).get('solicitudes', [])), 'pedido nuevo de Mi plan aparece en solicitudes')
r, d = ejecutar('atender_solicitud', {'solicitud_id': sid2, 'resultado': 'descartada', 'nota': 'Ahora no'})
chk(r.status_code == 200 and php("echo valor('SELECT estado FROM modulos_pedidos WHERE id = " + q_id + "');") == 'rechazada' and BX['libre'] not in ligaM(MB)['modulos_extra'], 'No aprobar: el pedido queda no aprobado y no enciende nada')
r, d = ejecutar('atender_solicitud', {'solicitud_id': sid2, 'resultado': 'en_proceso'})
chk(r.status_code in (403, 409), 'atender un pedido ya cerrado como en proceso → no se puede')
# guardar_modulos: precio del catálogo y módulos de un plan
r, d = ejecutar('guardar_modulos', {'modulos': [{'id': BX['libre'], 'precio_mensual': 9.25, 'descripcion': 'Texto nuevo para vender'}], 'planes': [{'codigo': str(BX['pl']), 'modulos': BX['del'] + [BX['libre']]}]})
m2 = {x['id']: x for x in pedir('modulos').get('modulos', [])}
chk(r.status_code == 200 and d.get('resultado', {}).get('cambios', 0) >= 2 and m2[BX['libre']]['precio_mensual'] == 9.25 and m2[BX['libre']]['descripcion'] == 'Texto nuevo para vender', 'guardar_modulos: precio y texto del catálogo')
c = pedir('cliente', {'id': MA}).get('cliente', {}).get('modulos', {})
chk(BX['libre'] in c.get('del_plan', []) and BX['libre'] not in c.get('extra', []), 'guardar_modulos: el plan ahora trae el módulo (ya no se cobra aparte)')
r, d = ejecutar('guardar_modulos', {'modulos': [{'id': BX['libre'], 'precio_mensual': 9.25}]})
chk(r.status_code == 200 and d.get('sin_cambios') is True, 'guardar_modulos: lo mismo otra vez → sin_cambios')
r, d = ejecutar('guardar_modulos', {'modulos': [{'id': BX['libre'], 'precio_mensual': -1}]})
chk(es_error(r, 'invalido', 422), 'guardar_modulos: precio negativo → 422')
ejecutar('guardar_modulos', {'planes': [{'codigo': str(BX['pl']), 'modulos': BX['del']}]})
# crear_demo con plan y módulos elegidos (y sin módulos: todo)
r, d = ejecutar('crear_demo', {'nombre': 'Demo Con Modulos', 'contacto_nombre': 'Ana Demo', 'email': 'demo.modulos@v2.ejemplo.com', 'telefono': '0991112299', 'plan': str(BX['pl']),
                               'modulos': [BX['del'][0]], 'enviar_correo': False, 'extra': {'ciudad': 'Quito'} if ACADEMIA else {'tipo': 'liga', 'ciudad': 'Quito'}})
dm = d.get('resultado', {}).get('modulos', {})
chk(r.status_code == 200 and dm.get('activos') == [BX['del'][0]] and dm.get('elegidos') is True, 'crear_demo con módulos: solo esos encendidos ' + str(dm.get('activos')))
r, d = ejecutar('crear_demo', {'nombre': 'Demo Con Todo', 'contacto_nombre': 'Eva Demo', 'email': 'demo.todo@v2.ejemplo.com', 'telefono': '0991112288', 'enviar_correo': False,
                               'extra': {'ciudad': 'Quito'} if ACADEMIA else {'tipo': 'liga', 'ciudad': 'Quito'}})
dm = d.get('resultado', {}).get('modulos', {})
chk(r.status_code == 200 and dm.get('todos_en_demo') is True and set(dm.get('activos', [])) == {k for k, x in m2.items() if ('escuela' if ACADEMIA else 'liga') in x['para']}, 'crear_demo sin módulos: la demo tiene todo')
r, d = ejecutar('crear_demo', {'nombre': 'Demo Mala', 'contacto_nombre': 'Eva Demo', 'email': 'demo.mala@v2.ejemplo.com', 'telefono': '0991112277', 'modulos': ['no_existe'],
                               'extra': {'ciudad': 'Quito'} if ACADEMIA else {'tipo': 'liga', 'ciudad': 'Quito'}})
chk(es_error(r, 'invalido', 422), 'crear_demo con un módulo que no existe → 422')
# apagar nunca borra datos: el módulo apagado sigue con sus filas
chk(int(php("echo (int)valor('SELECT COUNT(*) FROM modulos_pedidos WHERE liga_id = " + MB + "');")) == 1, 'apagar / no aprobar no borra datos')

# ---------- 10) Pantallas del programa con las mismas reglas (Ventas, Embudo) ----------
s, rr = entrar('super@prueba.com')
r, d = ejecutar('registrar_pago', dict(datos_pago, monto=19.0), ID['V7'])
pid = d.get('resultado', {}).get('pago_id')
v_antes = liga('V7')['vence']
p = s.get(B + 'admin.php?p=suscripciones')
r = s.post(B + 'admin.php?p=suscripciones', data={'csrf': csrf(p.text), 'accion': 'anular_pago', 'id': pid}, headers={'CF-Connecting-IP': ip()})
chk(liga('V7')['vence'] != v_antes and 'vuelve a vencer' in r.text, 'Ventas → eliminar pago: la fecha de vencimiento vuelve a la del pago anterior')
p = s.get(B + 'admin.php?p=embudo')
vence_v8 = liga('V8')['vence']
r = s.post(B + 'admin.php?p=embudo', data={'csrf': csrf(p.text), 'accion': 'alargar', 'id': ID['V8'], 'dias': '7'}, headers={'CF-Connecting-IP': ip()})
chk(liga('V8')['es_prueba'] == 0 and liga('V8')['vence'] == vence_v8 and 'ya es una cuenta que paga' in r.text, 'Embudo → +7 días a una cuenta que paga: se niega (no la vuelve a poner en demo)')

# ---------- 11) SSO con enlace directo «ir» ----------
for ir_, esperado in [('/admin.php?p=ligas&editar=' + ID['V8'], '/admin.php?p=ligas&editar=' + ID['V8']), ('//malo.com/admin.php', 'admin.php'), ('https://malo.com/admin.php', 'admin.php'),
                      ('/index.php', 'admin.php'), ('/admin.phpx', 'admin.php'), ('/admin.php\\..', 'admin.php'), ('/admin.php?p=x\n', 'admin.php')]:
    s = requests.Session()
    r = get1('sso', token1('sso', ir=ir_), s=s)
    chk(r.status_code in (302, 303) and r.headers.get('Location') == esperado, 'sso con ir=%r → %s (%s)' % (ir_, esperado, r.headers.get('Location')))
s = requests.Session()
r = get1('sso', token1('sso', ir='/admin.php?p=ligas&editar=' + ID['V8']), s=s)
p = s.get(B + r.headers.get('Location', '/').lstrip('/'))
chk(p.status_code == 200 and 'V2 Vence Ocho' in p.text, 'sso con ir: llega a la ficha de ese cliente')
chk(int(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE descripcion = ?\", ['Entró desde el Panel Tukán Central → /admin.php?p=ligas&editar=" + ID['V8'] + "']);")) >= 1, 'sso con ir: anotado en la bitácora')
chk(int(php("echo (int)valor(\"SELECT COUNT(*) FROM bitacora WHERE descripcion LIKE 'Entró desde el Panel Tukán Central (ir no válido%'\");")) >= 5, 'sso con ir no válido: anotado «ir no válido»')
r = get1('sso', token1('sso', email='x@'))
chk(r.status_code == 401 and r.headers.get('X-Tukan-Codigo') == 'invalido', 'sso con error: cabecera X-Tukan-Codigo')
r = get1('estado', token1(inst='otra'))
chk(r.status_code == 401 and j(r).get('codigo') == 'inst' and j(r).get('ok') is False, 'estado con error: trae codigo')
# un Central v1 sigue funcionando
r = get1('estado', token1())
chk(r.status_code == 200 and j(r).get('protocolo') == 1 and 'negocios' in j(r), 'Central v1: estado sigue igual (protocolo 1)')
s = requests.Session()
r = get1('sso', token1('sso'), s=s)
chk(r.status_code in (302, 303) and r.headers.get('Location') == 'admin.php', 'Central v1: sso sin ir abre el inicio')

# ---------- 12) Todos los errores con código conocido y sin detalles técnicos ----------
malos = [post2('clientes', raw=b'x'), post2('cliente', {'id': '1; DROP'}), post2('solicitud', {'id': '../../etc'}), post2('adjunto', {'solicitud_id': 'comprobante:1', 'adjunto_id': '../x'}),
         post2('finanzas', {'desde': 'ayer'}), ejecutar('registrar_pago', {'plan': 'x'}, ID['V8'])[0], post2('clientes', raw=b'{"pagina":"uno"}')]
chk(all(j(x).get('codigo') in CODIGOS and isinstance(j(x).get('error'), str) and not re.search(r'(SQLSTATE|\.php|Stack|Exception|line \d)', j(x).get('error', '')) for x in malos),
    'errores: siempre con un código de la lista y mensaje en español sin detalles técnicos')

# ---------- 13) Límite: 121 pedidos en un minuto → 429 ----------
fija = ip()
cods = []
for _ in range(121):
    cods.append(post2('capacidades', {}, desde=fija))
ultimo = cods[-1]
chk(all(x.status_code == 200 for x in cods[:120]) and es_error(ultimo, 'limite', 429) and ultimo.headers.get('Retry-After') == '60', 'límite: el pedido 121 en un minuto → 429 limite con Retry-After')
chk(post2('capacidades', {}).status_code == 200, 'límite: otra IP sigue entrando')
fija = ip()
for _ in range(30):
    post2('clientes', raw=b'{}', t='basura', desde=fija)
chk(es_error(post2('capacidades', {}, desde=fija), 'limite', 429), 'límite: después de 30 rechazados en un minuto → 429')

# ---------- 14) Sin errores de PHP ----------
for log in [os.path.join(DIR, 'web.log'), os.path.join(REPO, 'pruebas', 'v38', 'web.log')]:
    if os.path.exists(log) and os.path.getmtime(log) > time.time() - 3600:
        t = open(log, errors='replace').read()
        m = ERRORES.search(t)
        chk(not m, 'php -S sin errores de PHP (' + os.path.relpath(log, REPO) + ')')
        if m:
            print('   →', t[max(0, m.start() - 200):m.end() + 300])
        break
os.remove(ENV)

print()
print('Resultado: %d/%d bien' % (ok, ok + len(fallos)))
if fallos:
    print('Fallaron:', *fallos, sep='\n - ')
sys.exit(1 if fallos else 0)
