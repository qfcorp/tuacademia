"""Primeros pasos de TuAcademia (guía corta para el cliente, PDF A4).
Uso: python3 hacer_pdf.py   ->  ../TuAcademia_Primeros_Pasos.pdf
Las capturas están en capturas/ (sacadas de la «Academia Demo» en modo academia)."""
import os
from playwright.sync_api import sync_playwright
D = os.path.dirname(os.path.abspath(__file__))
CAP = os.path.join(D, 'capturas')
u = lambda p: 'file://' + p
img = lambda n: u(os.path.join(CAP, n + '.png'))
NAVY, SAND, TEAL, GOLD = '#14213D', '#F5F1EA', '#2A9D8F', '#FFC93C'
PROG = 'TuAcademia'

def paso(n, titulo, donde, items, consejo=''):
    lis = ''.join(f'<li>{x}</li>' for x in items)
    c = f'<p class="tip"><b>Consejo:</b> {consejo}</p>' if consejo else ''
    return f'<div class="paso"><div class="num">{n}</div><div class="cuerpo"><h3>{titulo}</h3><p class="donde">{donde}</p><ol>{lis}</ol>{c}</div></div>'

css = f"""
@font-face{{font-family:SG;src:url('{u(D)}/fonts/space-grotesk-latin-700-normal.woff2')}}
@font-face{{font-family:DM;src:url('{u(D)}/fonts/dm-sans-latin-400-normal.woff2')}}
@page{{size:A4;margin:16mm 15mm 18mm}}
*{{box-sizing:border-box}} body{{font-family:DM,Arial,sans-serif;color:{NAVY};font-size:10.5pt;line-height:1.42;margin:0}}
h1{{font-family:SG;font-size:30pt;margin:6mm 0 1mm;letter-spacing:-.5px}} h2{{font-family:SG;font-size:15pt;margin:7mm 0 3mm;color:{NAVY};border-bottom:3px solid {TEAL};padding-bottom:1.5mm}}
h3{{font-family:SG;font-size:12pt;margin:0 0 .5mm}}
.top{{display:flex;justify-content:space-between;align-items:center}} .top img{{height:11mm}}
.chip{{display:inline-block;background:{GOLD};font-family:SG;font-size:9pt;padding:1mm 3mm;border-radius:99px}}
.lead{{font-size:11.5pt;color:#33405C;margin:1mm 0 0}}
.paso{{display:flex;gap:4mm;margin:0 0 4mm;break-inside:avoid}}
.num{{min-width:9mm;height:9mm;border-radius:2.5mm;background:{TEAL};color:#fff;font-family:SG;font-size:13pt;text-align:center;line-height:9mm}}
.cuerpo{{flex:1}} .donde{{margin:0 0 1mm;color:{TEAL};font-weight:bold;font-size:9.5pt}}
ol{{margin:0;padding-left:5mm}} li{{margin:.4mm 0}}
.tip{{background:{SAND};border-left:4px solid {GOLD};padding:1.5mm 3mm;margin:1.5mm 0 0;font-size:9.5pt}}
.box{{background:{SAND};border-radius:3mm;padding:3mm 4mm;margin:3mm 0;break-inside:avoid}}
.fotos{{display:flex;gap:4mm;justify-content:center;margin:2mm 0 4mm;break-inside:avoid}}
.fotos figure{{margin:0;text-align:center;font-size:8.5pt;color:#4A5470}}
.fotos img{{border:1px solid #DED8CC;border-radius:2mm}}
.fin{{background:{NAVY};color:#fff;border-radius:3mm;padding:4mm 5mm;margin-top:5mm;break-inside:avoid}}
.fin b{{color:{GOLD}}}
.junto{{break-inside:avoid}}
"""

h = []
h.append(f'''<div class="top"><img src="{u(D)}/logo_oscuro.png"><span class="chip">Guía rápida</span></div>
<h1>Primeros pasos en {PROG}</h1>
<p class="lead">Lo básico para empezar a trabajar hoy. Para todo lo demás está el <b>Manual de academias</b>.</p>
<div class="box"><b>Antes de empezar:</b> lo que ve en el menú depende de su perfil y de los módulos que contrató.
En el <b>celular</b>, los botones principales están abajo (Resumen, Alumnos, Asistencia, Pagos) y todo lo demás en <b>Más</b>.
La configuración se hace mejor en la <b>computadora</b>. Las palabras se adaptan a su disciplina: en una academia deportiva verá <b>Grupos y horarios</b> en vez de Cursos y horarios.</div>''')

h.append('<h2>Parte 1 · Deje lista su academia (una sola vez)</h2>')
h.append(paso(1, 'Entre al programa', 'La dirección, el correo y la contraseña se los enviamos nosotros', [
    'Abra la dirección de su academia en el navegador (celular o computadora).',
    'Escriba su <b>Correo</b> y su <b>Contraseña</b>, y toque <b>Ingresar</b>.',
    'Llega al <b>Resumen</b>. La caja <b>Primeros pasos</b> le marca lo que falta: toque cada línea y lo lleva a esa pantalla.']))
h.append(paso(2, 'Datos de la academia y pensiones', 'Menú Configuración → Parámetros', [
    'En <b>Datos de la academia</b>: nombre, <b>Disciplina principal</b>, logo, colores, teléfono y los <b>Datos para pagar</b> (banco y cuenta que verán los padres). Toque <b>Guardar</b>.',
    'En <b>Pensiones</b>: <b>Pensión general</b>, <b>Matrícula</b>, <b>Día de vencimiento de la pensión</b> y <b>Descuento por hermano</b>. Toque <b>Guardar</b>.',
    'Si cobra por DeUna, escriba el <b>Celular de DeUna de la academia</b>. En <b>Sedes</b> agregue cada local con <b>+ Agregar sede</b>.'],
    'cada parte de la pantalla tiene su propio botón Guardar.'))
h.append(paso(3, 'Sus profesores', 'Menú Dinero → Profesores', [
    'Toque <b>+ Nuevo profesor</b>: nombre, celular, especialidad, <b>Cómo se le paga</b> (por clase dictada, sueldo fijo o por alumno) y el valor.',
    'Toque <b>Guardar profesor</b>. El programa calcula solo lo que se le debe cada mes.']))
h.append(paso(4, 'Cursos y horarios', 'Menú Alumnos → Cursos y horarios', [
    'En <b>+ Nuevo curso</b> ponga el nombre (ej. Ballet infantil, Piano nivel 1) y la <b>Disciplina</b>.',
    'Elija las <b>Edades</b>, la sede, el profesor, los <b>Días de clase</b>, la hora, el lugar (salón, aula, piscina) y el cupo máximo.',
    'Si este curso paga distinto, llene <b>Pensión de este curso</b>; si no, usa la de Parámetros. Toque <b>Guardar curso</b>.']))
h.append(f'''<div class="fotos"><figure><img src="{img('cursos')}" style="height:40mm"><figcaption>Cursos y horarios</figcaption></figure></div>''')
h.append(paso(5, 'Su personal', 'Menú Configuración → Usuarios', [
    'En <b>Nuevo usuario</b>: nombre, <b>Correo (será su usuario)</b> y una contraseña de 8 caracteres o más.',
    'Elija el <b>Perfil</b> (Administrador, Secretario, Tesorero, Profesor…), marque la autorización de datos y toque <b>Crear usuario</b>.',
    'Cada perfil ve solo lo que le toca: el profesor toma lista de sus cursos y no ve los pagos. Los padres no necesitan usuario: entran a su portal con su cédula.']))

h.append('<div class="junto"><h2>Parte 2 · El día a día</h2>' + paso(6, 'Inscribir a un alumno', 'Menú Alumnos → Alumnos → + Nuevo alumno', [
    'Llene nombres, apellidos, <b>Fecha de nacimiento</b>, el curso en <b>Grupo</b> y la foto.',
    'Llene los datos del representante (nombre, cédula, <b>Celular (WhatsApp)</b>). Los hermanos se reconocen por la misma cédula del representante.',
    'Deje marcado <b>Cobrar matrícula</b> si corresponde, marque <b>Tengo la autorización del representante</b> y toque <b>Inscribir alumno</b>.'],
    'si abre la <b>Inscripción en línea</b> en Parámetros, los padres llenan la ficha y usted la aprueba en Alumnos → <b>Por aprobar</b>.') + '</div>')
h.append(paso(7, 'Tomar asistencia', 'Menú Alumnos → Asistencia, o Tomar asistencia en el Resumen', [
    'Elija el curso y la fecha (viene la de hoy). Toque <b>Todos presentes</b> y corrija a los que faltaron: <b>P</b> presente, <b>T</b> tarde, <b>J</b> justificó, <b>A</b> faltó.',
    'Deje marcado <b>El profesor dio la clase</b> (cuenta para su pago) y toque <b>💾 Guardar asistencia</b>. Los padres lo ven en su portal.']))
h.append(f'''<div class="fotos"><figure><img src="{img('asistencia_celular')}" style="height:52mm"><figcaption>Asistencia en el celular</figcaption></figure>
<figure><img src="{img('cobrar')}" style="height:52mm"><figcaption>Cobrar desde la ficha del alumno</figcaption></figure></div>''')
h.append(paso(8, 'Cobrar las pensiones', 'Menú Dinero → Pagos', [
    'Las pensiones se crean solas cada mes. En <b>Estado de pagos</b> ve quién está <b>Al día</b>, <b>Por vencer</b> o <b>Vencido</b>.',
    'Toque <b>Cobrar</b> junto al alumno, marque lo que paga en <b>Pendiente</b>, elija la forma de pago y toque <b>💵 Cobrar y emitir recibo</b>.',
    'Si la familia pagó por DeUna o transferencia, revise la foto en <b>📤 Comprobantes</b> y toque <b>Aprobar y emitir recibo</b>.']))
h.append(paso(9, 'Recordar los pagos por WhatsApp', 'Menú Dinero → Mensajes de hoy', [
    'Cada día salen las familias con pensiones por vencer o vencidas, con el mensaje ya escrito (valores, DeUna, transferencia y enlace al portal).',
    'Toque <b>💬 WhatsApp</b>, envíe el mensaje y toque <b>✓ Marcar como enviado</b>. El número rojo del menú dice cuántos faltan.']))

h.append(f'''<div class="fin"><b>¿Listo? Ya puede trabajar.</b> Para niveles y exámenes, recitales, clases particulares, evaluaciones, rol de pago de profesores y todo lo demás,
vea el <b>Manual de academias</b>.<br><br><b>¿Cómo se hace algo?</b> En <b>❓ Ayuda</b> escriba su pregunta y le respondemos al instante con el manual. El <b>✨ Asistente IA</b> (informe del avance del alumno para los padres y mensaje de cobro amable) viene incluido en el plan Completo; en el Básico se pide en Mi plan.<br><br>¿Dudas? Escríbanos por <b>WhatsApp 099 640 8397</b> · info@tukansoftware.com · tukansoftware.com</div>''')

html = f'<!doctype html><html lang="es"><head><meta charset="utf-8"><style>{css}</style></head><body>{"".join(h)}</body></html>'
open(f'{D}/primeros_pasos.html', 'w').write(html)
out = os.path.join(os.path.dirname(D), 'TuAcademia_Primeros_Pasos.pdf')
with sync_playwright() as pw:
    b = pw.chromium.launch(); pg = b.new_page()
    pg.goto(u(f'{D}/primeros_pasos.html')); pg.wait_for_load_state('networkidle'); pg.evaluate('document.fonts.ready')
    pg.pdf(path=out, format='A4', print_background=True, display_header_footer=True, header_template='<span></span>',
           footer_template=f'<div style="font-size:8px;color:#4A5470;width:100%;text-align:center">{PROG} · Primeros pasos · página <span class="pageNumber"></span> de <span class="totalPages"></span></div>',
           margin={'top': '16mm', 'bottom': '18mm', 'left': '15mm', 'right': '15mm'})
    b.close()
print('ok', out)
