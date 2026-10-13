<?php
/*
 * Conexión a la base de datos (SQLite: un solo archivo, no necesita servidor MySQL).
 * La primera vez crea todas las tablas automáticamente.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(CFG['db']);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $pdo = new PDO('sqlite:' . CFG['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    crear_esquema($pdo);
    return $pdo;
}

function crear_esquema(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        esquema_v1($pdo);
    }
    if ($version < 2) {
        $pdo->beginTransaction();
        try {
            esquema_v2($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 2');
    }
    if ($version < 3) {
        $pdo->beginTransaction();
        try {
            esquema_v3($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 3');
    }
    if ($version < 4) {
        $pdo->beginTransaction();
        try {
            esquema_v4($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 4');
    }
    if ($version < 5) {
        $pdo->beginTransaction();
        try {
            esquema_v5($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 5');
    }
    if ($version < 6) {
        $pdo->beginTransaction();
        try {
            esquema_v6($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 6');
    }
    if ($version < 7) {
        $pdo->beginTransaction();
        try {
            esquema_v7($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 7');
    }
    if ($version < 8) {
        $pdo->beginTransaction();
        try {
            esquema_v8($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 8');
    }
    if ($version < 9) {
        $pdo->beginTransaction();
        try {
            esquema_v9($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 9');
    }
    if ($version < 10) {
        $pdo->beginTransaction();
        try {
            esquema_v10($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 10');
    }
    if ($version < 11) {
        $pdo->beginTransaction();
        try {
            esquema_v11($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 11');
    }
    if ($version < 12) {
        $pdo->beginTransaction();
        try {
            esquema_v12($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 12');
    }
    if ($version < 13) {
        $pdo->beginTransaction();
        try {
            esquema_v13($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 13');
    }
    if ($version < 14) {
        $pdo->beginTransaction();
        try {
            esquema_v14($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 14');
    }
    if ($version < 15) {
        $pdo->beginTransaction();
        try {
            esquema_v15($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 15');
    }
    if ($version < 16) {
        $pdo->beginTransaction();
        try {
            esquema_v16($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 16');
    }
    if ($version < 17) {
        $pdo->beginTransaction();
        try {
            esquema_v17($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 17');
    }
    if ($version < 18) {
        $pdo->beginTransaction();
        try {
            esquema_v18($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 18');
    }
    if ($version < 19) {
        $pdo->beginTransaction();
        try {
            esquema_v19($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 19');
    }
    if ($version < 20) {
        $pdo->beginTransaction();
        try {
            esquema_v20($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 20');
    }
    if ($version < 21) {
        $pdo->beginTransaction();
        try {
            esquema_v21($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 21');
    }
    if ($version < 22) {
        $pdo->beginTransaction();
        try {
            esquema_v22($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 22');
    }
    if ($version < 23) {
        $pdo->beginTransaction();
        try {
            esquema_v23($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 23');
    }
    if ($version < 24) {
        $pdo->beginTransaction();
        try {
            esquema_v24($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 24');
    }
    if ($version < 25) {
        $pdo->beginTransaction();
        try {
            esquema_v25($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 25');
    }
    if ($version < 26) {
        $pdo->beginTransaction();
        try {
            esquema_v26($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 26');
    }
    if ($version < 27) {
        $pdo->beginTransaction();
        try {
            esquema_v27($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 27');
    }
    if ($version < 28) {
        $pdo->beginTransaction();
        try {
            esquema_v28($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 28');
    }
    if ($version < 29) {
        $pdo->beginTransaction();
        try {
            esquema_v29($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 29');
    }
    if ($version < 30) {
        $pdo->beginTransaction();
        try {
            esquema_v30($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 30');
    }
    if ($version < 31) {
        $pdo->beginTransaction();
        try {
            esquema_v31($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 31');
    }
    if ($version < 32) {
        $pdo->beginTransaction();
        try {
            esquema_v32($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 32');
    }
    if ($version < 33) {
        $pdo->beginTransaction();
        try {
            esquema_v33($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 33');
    }
    if ($version < 34) {
        $pdo->beginTransaction();
        try {
            esquema_v34($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('PRAGMA user_version = 34');
    }
}

/*
 * Versión 34 (TuAcademia, oct-2026): ACADEMIAS de baile, música, natación y deportes.
 *  - esc_alumnos: instrumento (música), talla de vestuario (baile), carril (natación).
 *  - esc_grados.disciplina: el nivel (o cinturón) es de una disciplina (un alumno puede tener nivel en ballet y en piano).
 *  - esc_cargos: comprobante_forma (transferencia / deuna), comprobante_grupo (un comprobante para varios valores), referencia.
 *  - ligas: DeUna (celular y titular) y Payphone (token y Store ID) de cada academia.
 *  - acad_eventos, acad_numeros, acad_participantes, acad_inscritos, acad_ensayos: recitales y presentaciones.
 *  - acad_paquetes, acad_paquetes_alumno, acad_horario, acad_clases: clases particulares con paquetes de clases.
 *  - acad_mensajes: recordatorios de pensiones por WhatsApp marcados como enviados.
 *  - acad_payphone: pagos con tarjeta de las familias (Payphone).
 *  - Planes de TuAcademia (Básico $19 y Completo $29) y ajustes (prueba gratis de 14 días).
 */
function esquema_v34(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $agregar('esc_alumnos', ['instrumento' => "TEXT NOT NULL DEFAULT ''", 'talla_vestuario' => "TEXT NOT NULL DEFAULT ''", 'carril' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('esc_grados', ['disciplina' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('esc_cargos', ['comprobante_forma' => "TEXT NOT NULL DEFAULT ''", 'comprobante_grupo' => "TEXT NOT NULL DEFAULT ''", 'comprobante_ref' => "TEXT NOT NULL DEFAULT ''", 'comprobante_fecha' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('ligas', ['deuna_celular' => "TEXT NOT NULL DEFAULT ''", 'deuna_titular' => "TEXT NOT NULL DEFAULT ''",
        'payphone_token' => "TEXT NOT NULL DEFAULT ''", 'payphone_store_id' => "TEXT NOT NULL DEFAULT ''",
        'acad_dias_aviso' => 'INTEGER NOT NULL DEFAULT 3', 'acad_falta_descuenta' => 'INTEGER NOT NULL DEFAULT 1']);
    $pdo->exec(<<<SQL
CREATE INDEX IF NOT EXISTS idx_esc_grados_disc ON esc_grados(alumno_id, disciplina, fecha);
CREATE INDEX IF NOT EXISTS idx_esc_cargos_grupo_comp ON esc_cargos(comprobante_grupo);
CREATE TABLE IF NOT EXISTS acad_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL DEFAULT 'recital',
    nombre TEXT NOT NULL,
    fecha TEXT NOT NULL,
    hora TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    direccion TEXT NOT NULL DEFAULT '',
    descripcion TEXT NOT NULL DEFAULT '',
    valor_inscripcion REAL NOT NULL DEFAULT 0,
    valor_vestuario REAL NOT NULL DEFAULT 0,
    vence_cobro TEXT NOT NULL DEFAULT '',
    publico INTEGER NOT NULL DEFAULT 1,
    estado TEXT NOT NULL DEFAULT 'preparacion',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_acad_eventos ON acad_eventos(liga_id, fecha);
CREATE TABLE IF NOT EXISTS acad_numeros (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES acad_eventos(id) ON DELETE CASCADE,
    orden INTEGER NOT NULL DEFAULT 0,
    titulo TEXT NOT NULL,
    disciplina TEXT NOT NULL DEFAULT '',
    grupo_id INTEGER REFERENCES esc_grupos(id) ON DELETE SET NULL,
    profesor TEXT NOT NULL DEFAULT '',
    autor TEXT NOT NULL DEFAULT '',
    duracion INTEGER NOT NULL DEFAULT 0,
    vestuario TEXT NOT NULL DEFAULT '',
    valor_vestuario REAL,
    notas TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_acad_numeros ON acad_numeros(evento_id, orden);
CREATE TABLE IF NOT EXISTS acad_participantes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    numero_id INTEGER NOT NULL REFERENCES acad_numeros(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    rol TEXT NOT NULL DEFAULT '',
    talla TEXT NOT NULL DEFAULT '',
    cargo_id INTEGER,
    UNIQUE (numero_id, alumno_id)
);
CREATE TABLE IF NOT EXISTS acad_inscritos (
    evento_id INTEGER NOT NULL REFERENCES acad_eventos(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    cargo_id INTEGER,
    PRIMARY KEY (evento_id, alumno_id)
);
CREATE TABLE IF NOT EXISTS acad_ensayos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES acad_eventos(id) ON DELETE CASCADE,
    numero_id INTEGER REFERENCES acad_numeros(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    hora_inicio TEXT NOT NULL DEFAULT '',
    hora_fin TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    tipo TEXT NOT NULL DEFAULT 'ensayo',
    notas TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_acad_ensayos ON acad_ensayos(evento_id, fecha);
CREATE TABLE IF NOT EXISTS acad_paquetes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    clases INTEGER NOT NULL DEFAULT 4,
    precio REAL NOT NULL DEFAULT 0,
    vigencia_dias INTEGER NOT NULL DEFAULT 45,
    minutos INTEGER NOT NULL DEFAULT 60,
    disciplina TEXT NOT NULL DEFAULT '',
    uso TEXT NOT NULL DEFAULT 'particular',
    activo INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS acad_paquetes_alumno (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    paquete_id INTEGER REFERENCES acad_paquetes(id) ON DELETE SET NULL,
    profesor_id INTEGER REFERENCES esc_profesores(id) ON DELETE SET NULL,
    nombre TEXT NOT NULL DEFAULT '',
    disciplina TEXT NOT NULL DEFAULT '',
    clases_total INTEGER NOT NULL DEFAULT 0,
    clases_usadas INTEGER NOT NULL DEFAULT 0,
    precio REAL NOT NULL DEFAULT 0,
    uso TEXT NOT NULL DEFAULT 'particular',
    desde TEXT NOT NULL DEFAULT '',
    vence TEXT NOT NULL DEFAULT '',
    cargo_id INTEGER,
    estado TEXT NOT NULL DEFAULT 'activo',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_acad_paq_al ON acad_paquetes_alumno(alumno_id, estado);
CREATE TABLE IF NOT EXISTS acad_horario (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    profesor_id INTEGER REFERENCES esc_profesores(id) ON DELETE SET NULL,
    dia INTEGER NOT NULL,
    hora_inicio TEXT NOT NULL,
    hora_fin TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    disciplina TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_acad_horario ON acad_horario(liga_id, dia, activo);
CREATE TABLE IF NOT EXISTS acad_clases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    profesor_id INTEGER REFERENCES esc_profesores(id) ON DELETE SET NULL,
    paquete_alumno_id INTEGER REFERENCES acad_paquetes_alumno(id) ON DELETE SET NULL,
    sesion_id INTEGER REFERENCES esc_sesiones(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    hora TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'asistio',
    descuenta INTEGER NOT NULL DEFAULT 1,
    tema TEXT NOT NULL DEFAULT '',
    notas TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (alumno_id, fecha, hora)
);
CREATE INDEX IF NOT EXISTS idx_acad_clases ON acad_clases(liga_id, fecha);
CREATE TABLE IF NOT EXISTS acad_mensajes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    clave TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'pension',
    destinatario TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    texto TEXT NOT NULL DEFAULT '',
    cargos TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL,
    enviado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_acad_mensajes ON acad_mensajes(liga_id, fecha, clave);
CREATE TABLE IF NOT EXISTS acad_payphone (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    familia_cedula TEXT NOT NULL DEFAULT '',
    cargos TEXT NOT NULL DEFAULT '',
    monto REAL NOT NULL DEFAULT 0,
    cliente_tx TEXT NOT NULL UNIQUE,
    payphone_id TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    recibo INTEGER,
    respuesta TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
    // Planes de TuAcademia (provisionales, se editan en Ventas → Planes). Semestral: paga 5 y recibe 6. Anual: paga 10 y recibe 12 + dominio.
    $academia = (defined('PRODUCTO') ? PRODUCTO : 'academia') === 'academia';
    $existe = fn(string $slug) => (int)$pdo->query('SELECT COUNT(*) FROM planes WHERE slug = ' . $pdo->quote($slug))->fetchColumn() > 0;
    $ins = $pdo->prepare('INSERT INTO planes (nombre, slug, tipo_cuenta, precio_mensual, precio_trimestral, precio_semestral, precio_anual, max_campeonatos,
                          max_equipos, max_equipos_torneo, max_alumnos, modulos, eslogan, descripcion, destacado, activo, orden, dominio_anual)
                          VALUES (?,?,?,?,0,?,?,0,0,0,0,?,?,?,?,?,?,1)');
    foreach ([
        ['Básico', 'academia-basico', 19, 95, 190, 'carnets,esc_familias,avisos,acad_niveles', 'Alumnos, cursos, asistencia, pensiones y portal de padres',
            "Mensajes de cobro por WhatsApp\nDeUna y transferencia con comprobante\nNiveles con certificado QR", 0, 10],
        ['Completo', 'academia-completo', 29, 145, 290, '*', 'Toda la academia: recitales, clases particulares, profesores y más',
            "Recitales y presentaciones con cobro de vestuario\nClases particulares con paquetes\nPago con tarjeta (Payphone)\nSoporte prioritario por WhatsApp", 1, 11],
    ] as [$nom, $slug, $pm, $ps, $pa, $mods, $esl, $desc, $dest, $ord]) {
        if (!$existe($slug)) $ins->execute([$nom, $slug, 'escuela', $pm, $ps, $pa, $mods, $esl, $desc, $dest, $academia ? 1 : 0, $ord]);
    }
    if ($academia) {
        // En TuAcademia solo se venden los planes de academia (los de ligas y escuelas de TuVocalía quedan guardados, apagados)
        $pdo->exec("UPDATE planes SET activo = 0, destacado = 0 WHERE slug NOT IN ('academia-basico', 'academia-completo')");
        $aj = $pdo->prepare('INSERT OR REPLACE INTO ajustes (clave, valor) VALUES (?, ?)');
        foreach (['marca' => 'TuAcademia', 'empresa' => 'Tukán Software', 'responsable_nombre' => 'Tukán Software', 'dias_prueba' => '14', 'dias_demo' => '14', 'registro_abierto' => '1',
            'combo_descuento' => '0', 'landing_titulo' => 'El sistema para su academia', 'landing_subtitulo' => 'Baile, música, natación y deportes: alumnos, pensiones, asistencia, niveles con certificado, recitales y portal de padres.',
            'nota_dominio' => 'El dominio gratis se da al pagar el plan por 1 año. La academia lo usa mientras mantenga su plan al día.'] as $k => $v) $aj->execute([$k, $v]);
    }
    $n = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    foreach ([
        ['💃🎹🏊 Academias de baile, música y natación', 'Cree cursos de ballet, salsa, piano, guitarra, canto, natación y más. Cada disciplina trae sus niveles, sus palabras y sus campos (instrumento, talla de vestuario, carril).', 'escuelas', 'admin.php?p=grupos', 'academia', '2026-10-13'],
        ['📲 Mensajes de hoy', 'Cada día, la lista de pensiones por vencer y vencidas con el mensaje de WhatsApp listo para cada padre. Un toque para abrir WhatsApp y otro para marcarlo como enviado.', 'escuelas', 'admin.php?p=mensajes', 'academia', '2026-10-13'],
        ['🎭 Recitales y presentaciones', 'Arme el recital por coreografías u obras, con los alumnos de cada una, los ensayos y el cobro del vestuario y la inscripción.', 'escuelas', 'admin.php?p=recitales', 'academia', '2026-10-13'],
    ] as $x) $n->execute(array_merge($x, [$x[0]]));
}

/*
 * Versión 33 (paquete v37, 12-oct-2026): OLIMPIADAS / JUEGOS MULTIDEPORTIVOS (módulo «olimpiadas»).
 * Eventos que agrupan campeonatos de varios deportes (disciplinas), delegaciones con portal propio, deportistas con una sola
 * ficha por evento e inscripción en varias disciplinas, medallero general y tabla de puntos por delegación, puntos extra,
 * y administradores de cada evento (perfil «evento»).
 */
function esquema_v33(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS olimp_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    slug TEXT NOT NULL DEFAULT '',
    descripcion TEXT NOT NULL DEFAULT '',
    sede TEXT NOT NULL DEFAULT '',
    fecha_ini TEXT NOT NULL DEFAULT '',
    fecha_fin TEXT NOT NULL DEFAULT '',
    logo TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#0b6e4f',
    estado TEXT NOT NULL DEFAULT 'preparacion',
    config TEXT NOT NULL DEFAULT '',
    requisitos TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_olimp_eventos_liga ON olimp_eventos(liga_id);
CREATE TABLE IF NOT EXISTS olimp_delegaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES olimp_eventos(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    sigla TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#333333',
    logo TEXT NOT NULL DEFAULT '',
    contacto TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    token TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_olimp_del_evento ON olimp_delegaciones(evento_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_olimp_del_token ON olimp_delegaciones(token);
CREATE TABLE IF NOT EXISTS olimp_deportistas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES olimp_eventos(id) ON DELETE CASCADE,
    delegacion_id INTEGER NOT NULL REFERENCES olimp_delegaciones(id) ON DELETE CASCADE,
    nombres TEXT NOT NULL,
    apellidos TEXT NOT NULL DEFAULT '',
    cedula TEXT NOT NULL DEFAULT '',
    sexo TEXT NOT NULL DEFAULT '',
    fecha_nac TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    cargo TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    nota TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_olimp_dep_evento ON olimp_deportistas(evento_id, delegacion_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_olimp_dep_cedula ON olimp_deportistas(evento_id, cedula);
CREATE TABLE IF NOT EXISTS olimp_disciplinas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES olimp_eventos(id) ON DELETE CASCADE,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL DEFAULT '',
    factor REAL NOT NULL DEFAULT 1,
    rama TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    hora_ini TEXT NOT NULL DEFAULT '',
    hora_fin TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    max_por_delegacion INTEGER NOT NULL DEFAULT 0,
    manual TEXT NOT NULL DEFAULT '',
    orden INTEGER NOT NULL DEFAULT 0,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_olimp_disc_torneo ON olimp_disciplinas(torneo_id);
CREATE INDEX IF NOT EXISTS idx_olimp_disc_evento ON olimp_disciplinas(evento_id);
CREATE TABLE IF NOT EXISTS olimp_inscripciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES olimp_eventos(id) ON DELETE CASCADE,
    deportista_id INTEGER NOT NULL REFERENCES olimp_deportistas(id) ON DELETE CASCADE,
    disciplina_id INTEGER NOT NULL REFERENCES olimp_disciplinas(id) ON DELETE CASCADE,
    categoria_id INTEGER NOT NULL DEFAULT 0,
    eventos TEXT NOT NULL DEFAULT '[]',
    pareja TEXT NOT NULL DEFAULT '',
    ref_tabla TEXT NOT NULL DEFAULT '',
    ref_id INTEGER,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_olimp_insc_unica ON olimp_inscripciones(deportista_id, disciplina_id, categoria_id);
CREATE INDEX IF NOT EXISTS idx_olimp_insc_disc ON olimp_inscripciones(disciplina_id);
CREATE TABLE IF NOT EXISTS olimp_equipos (
    disciplina_id INTEGER NOT NULL REFERENCES olimp_disciplinas(id) ON DELETE CASCADE,
    delegacion_id INTEGER NOT NULL REFERENCES olimp_delegaciones(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    PRIMARY KEY (disciplina_id, delegacion_id)
);
CREATE TABLE IF NOT EXISTS olimp_admins (
    evento_id INTEGER NOT NULL REFERENCES olimp_eventos(id) ON DELETE CASCADE,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    PRIMARY KEY (evento_id, usuario_id)
);
CREATE TABLE IF NOT EXISTS olimp_puntos_extra (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES olimp_eventos(id) ON DELETE CASCADE,
    delegacion_id INTEGER NOT NULL REFERENCES olimp_delegaciones(id) ON DELETE CASCADE,
    puntos REAL NOT NULL DEFAULT 0,
    motivo TEXT NOT NULL DEFAULT '',
    por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
    foreach (['jugadores', 'duelo_participantes', 'prueba_participantes', 'carrera_participantes'] as $tb) $agregar($tb, ['olimp_dep_id' => 'INTEGER']);
    // módulo nuevo en el catálogo del súper
    if (!function_exists('modulos_deporte_base')) require_once APP_DIR . '/modulos_deporte.php';
    $orden = (int)$pdo->query('SELECT COALESCE(MAX(orden), 0) FROM modulos_deporte')->fetchColumn();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO modulos_deporte (clave, precio_mensual, orden) VALUES (?,?,?)');
    foreach (modulos_deporte_base() as $k => $m) { $ins->execute([$k, $m[5], $orden + 1]); if ($ins->rowCount()) $orden++; }
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    $n = ['🏅 Olimpiadas y juegos multideportivos', 'Organice olimpiadas interempresas, interbancarias o intercolegiales: varios deportes a la vez, delegaciones con su propio enlace para inscribir a su gente, una sola ficha por deportista, medallero general y tabla de puntos configurable, y administradores por evento.', 'ligas', 'admin.php?p=olimpiadas', 'v37', '2026-10-12'];
    $ins->execute(array_merge($n, [$n[0]]));
}

/*
 * Versión 32 (paquete v36, 8-oct-2026): SQUASH y nuevos formatos de duelos (grupos y luego llave, consolación, Monrad,
 * sistema suizo, escalera de desafíos), reglas de games (a 11 con diferencia de 2 o punto de oro), ranking del circuito,
 * AJEDREZ y E-SPORTS (duelos).
 */
function esquema_v32(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $agregar('duelo_combates', ['bloque' => 'INTEGER NOT NULL DEFAULT 0', 'antes' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('duelo_categorias', ['grupo_tam' => 'INTEGER NOT NULL DEFAULT 3', 'grupo_pasan' => 'INTEGER NOT NULL DEFAULT 2',
        'escalera_salto' => 'INTEGER NOT NULL DEFAULT 3', 'rondas' => 'INTEGER NOT NULL DEFAULT 0']);
    $agregar('torneos', ['duelo_game_pts' => 'INTEGER NOT NULL DEFAULT 0', 'duelo_game_dif' => 'INTEGER NOT NULL DEFAULT 2',
        'duelo_factor' => 'INTEGER NOT NULL DEFAULT 100', 'duelo_rank_pts' => "TEXT NOT NULL DEFAULT ''"]);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_duelo_comb_bloque ON duelo_combates(categoria_id, bloque, ronda, pos)');
    esquema_v32_resto($pdo, $agregar);
}

/** (v36) Resto de la versión 32 (atletismo, carreras de ruta, pesas, triatlón, equipos nuevos): se completa en este mismo paquete. */
function esquema_v32_resto(PDO $pdo, callable $agregar): void
{
    // módulos nuevos en el catálogo editable del súper (precio sugerido; el súper lo cambia)
    if (!function_exists('modulos_deporte_base')) require_once APP_DIR . '/modulos_deporte.php';
    $orden = (int)$pdo->query('SELECT COALESCE(MAX(orden), 0) FROM modulos_deporte')->fetchColumn();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO modulos_deporte (clave, precio_mensual, orden) VALUES (?,?,?)');
    foreach (modulos_deporte_base() as $k => $m) { $ins->execute([$k, $m[5], $orden + 1]); if ($ins->rowCount()) $orden++; }
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    foreach ([
        ['🎾 Squash y nuevos formatos de llave', 'Squash con games a 11 (diferencia de 2 o punto de oro), grupos y luego llave, consolación, Monrad (todos los puestos), escalera de desafíos del club, sistema suizo y ranking del circuito. También para tenis, pádel y tenis de mesa.', 'todos', 'admin.php?p=torneos', 'v36', '2026-10-08'],
        ['🏃 Atletismo completo y carreras de ruta', 'Series y final con viento, concursos con intentos, salto alto y garrocha por alturas, heptatlón y decatlón con la tabla de World Athletics, vallas, obstáculos y marcha. Carreras de ruta, trail y cross con tiempo de chip, grupos de edad, buscador por dorsal y diplomas.', 'todos', 'admin.php?p=torneos', 'v36', '2026-10-08'],
        ['🧩 Nuevos deportes', 'Nuevos módulos: ajedrez (suizo con Buchholz), e-sports, levantamiento de pesas (IWF), triatlón, balonmano y béisbol / softbol con su pizarra por entradas.', 'todos', 'admin.php?p=mi_plan', 'v36', '2026-10-08'],
    ] as $n) $ins->execute(array_merge($n, [$n[0]]));
}

/*
 * Versión 30 (paquete v34, 6-oct-2026): CICLISMO según la UCI.
 *  - RUTA (CICLO): etapas con tipo (en línea, contrarreloj individual / por equipos, critérium), bonificaciones en meta y sprints,
 *    regla del mismo tiempo (1 s), fuera de control (% del ganador), puntos, montaña, mejor joven y equipos (ciclo_etapas + columnas).
 *  - MTB: carreras XCO por vueltas (regla del 80 %), descenso (mejor bajada) y enduro (suma de tramos): carrera_fechas.formato / vueltas_total.
 *  - PISTA, BMX RACING y BMX FREESTYLE: sobre las pruebas (prueba_eventos.formato/config/datos, prueba_resultados.datos) y prueba_series
 *    (mangas, motos, rondas, duelos y pasadas con jueces).
 */
/*
 * Versión 31 (paquete v35, 7-oct-2026): MÓDULOS DEPORTIVOS. Catálogo editable (modulos_deporte), módulos de cada cuenta
 * (ligas.deportes; '*' = todos), módulos pedidos en solicitudes de demo y comprobantes de pago, y requisitos por módulo
 * (datos extra y documentos de jugadores, competidores y alumnos).
 */
function esquema_v31(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS modulos_deporte (
    clave TEXT PRIMARY KEY,
    precio_mensual REAL NOT NULL DEFAULT 10,
    activo INTEGER NOT NULL DEFAULT 1,
    orden INTEGER NOT NULL DEFAULT 0,
    descripcion TEXT NOT NULL DEFAULT '',
    requisitos TEXT NOT NULL DEFAULT '',
    reglas TEXT NOT NULL DEFAULT '',
    actualizado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS requisitos_datos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    tabla TEXT NOT NULL,
    ref_id INTEGER NOT NULL,
    modulo TEXT NOT NULL,
    datos TEXT NOT NULL DEFAULT '',
    actualizado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tabla, ref_id, modulo)
);
CREATE TABLE IF NOT EXISTS requisitos_docs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    tabla TEXT NOT NULL,
    ref_id INTEGER NOT NULL,
    modulo TEXT NOT NULL,
    doc TEXT NOT NULL,
    archivo TEXT NOT NULL,
    vence TEXT NOT NULL DEFAULT '',
    subido TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tabla, ref_id, modulo, doc)
);
CREATE INDEX IF NOT EXISTS idx_req_docs_liga ON requisitos_docs(liga_id);
SQL);
    $agregar('ligas', ['deportes' => "TEXT NOT NULL DEFAULT '*'"]);
    $agregar('solicitudes_pago', ['deportes' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('solicitudes_demo', ['deportes' => "TEXT NOT NULL DEFAULT ''"]);
    if (!function_exists('modulos_deporte_base')) require_once APP_DIR . '/modulos_deporte.php';
    $orden = 0;
    $ins = $pdo->prepare('INSERT OR IGNORE INTO modulos_deporte (clave, precio_mensual, orden) VALUES (?,?,?)');
    foreach (modulos_deporte_base() as $k => $m) $ins->execute([$k, $m[5], ++$orden]);
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    foreach ([
        ['🧩 Módulos deportivos', 'El sistema ahora se arma por deporte: Fútbol, Básquet, Vóley, Ecuavóley, Ciclismo, Deportes de combate, Deportes de raqueta, Natación, Atletismo, Bolos y Automovilismo. Agregue a su cuenta los deportes que necesite con descuento desde el segundo módulo; todo queda en el mismo panel. Cada deporte pide sus propios datos y documentos (licencia, certificado médico, peso, grado…).', 'todos', 'admin.php?p=mi_plan', 'v35', '2026-10-07'],
    ] as $n) $ins->execute(array_merge($n, [$n[0]]));
}

function esquema_v30(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ciclo_etapas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fecha_id INTEGER NOT NULL REFERENCES carrera_fechas(id) ON DELETE CASCADE,
    manga INTEGER NOT NULL DEFAULT 1,
    tipo TEXT NOT NULL DEFAULT 'linea',
    nombre TEXT NOT NULL DEFAULT '',
    km REAL NOT NULL DEFAULT 0,
    limite_pct REAL NOT NULL DEFAULT 0,
    bonif_meta TEXT NOT NULL DEFAULT '10,6,4',
    puntos_meta TEXT NOT NULL DEFAULT '15,12,10,8,6,5,4,3,2,1',
    mismo_tiempo INTEGER NOT NULL DEFAULT 1,
    UNIQUE (fecha_id, manga)
);
CREATE TABLE IF NOT EXISTS prueba_series (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES prueba_eventos(id) ON DELETE CASCADE,
    fase TEXT NOT NULL DEFAULT 'r1',
    serie INTEGER NOT NULL DEFAULT 1,
    manga INTEGER NOT NULL DEFAULT 1,
    participante_id INTEGER NOT NULL REFERENCES prueba_participantes(id) ON DELETE CASCADE,
    carril INTEGER NOT NULL DEFAULT 0,
    puesto INTEGER,
    estado TEXT NOT NULL DEFAULT 'ok',
    tiempo_ms INTEGER,
    datos TEXT NOT NULL DEFAULT '',
    UNIQUE (evento_id, fase, serie, manga, participante_id)
);
CREATE INDEX IF NOT EXISTS idx_prueba_series ON prueba_series(evento_id, fase, serie);
SQL);
    $agregar('carrera_fechas', ['formato' => "TEXT NOT NULL DEFAULT ''", 'vueltas_total' => 'INTEGER NOT NULL DEFAULT 0']);
    $agregar('carrera_resultados', ['vueltas' => 'INTEGER', 'bonif_seg' => 'REAL NOT NULL DEFAULT 0', 'pts_sprint' => 'INTEGER NOT NULL DEFAULT 0', 'pts_montana' => 'INTEGER NOT NULL DEFAULT 0']);
    $agregar('carrera_participantes', ['nacimiento' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('torneos', ['ciclo_joven' => 'INTEGER NOT NULL DEFAULT 22']);
    $agregar('prueba_eventos', ['formato' => "TEXT NOT NULL DEFAULT 'marca'", 'config' => "TEXT NOT NULL DEFAULT ''", 'datos' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('prueba_resultados', ['datos' => "TEXT NOT NULL DEFAULT ''"]);
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    foreach ([
        ['🚴 Ciclismo con las reglas de la UCI', 'Ruta: etapas en línea, contrarreloj y critérium, bonificaciones, mismo tiempo del grupo, fuera de control y clasificaciones de puntos, montaña, mejor joven y equipos. Nuevos deportes: ciclismo de pista (velocidad, keirin, persecución, puntos, scratch, eliminación, tempo y omnium), BMX Racing (motos, cuartos, semis y final), BMX Freestyle (Park y Flatland con jueces) y MTB (cross-country por vueltas, descenso y enduro).', 'ligas', 'admin.php?p=torneos', 'v34', '2026-10-06'],
    ] as $n) $ins->execute(array_merge($n, [$n[0]]));
}

/*
 * Versión 29 (paquete v33, 4-oct-2026): contratar / renovar por transferencia con comprobante (solicitudes_pago),
 * ajustes nuevos (dias_gracia_demo = 0, url_sitio) y la novedad de la versión.
 * OJO: la rama guardada v32-wallet también usa esquema_v29 / user_version 29; al unirla, renumerar la de la billetera.
 */
function esquema_v29(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS solicitudes_pago (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    plan_id INTEGER,
    meses INTEGER NOT NULL DEFAULT 1,
    monto REAL NOT NULL DEFAULT 0,
    forma_pago TEXT NOT NULL DEFAULT 'transferencia',
    archivo TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT '',
    enviado_por TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    respuesta TEXT NOT NULL DEFAULT '',
    pago_id INTEGER,
    atendido_por TEXT NOT NULL DEFAULT '',
    atendido_en TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE INDEX IF NOT EXISTS idx_solpago_estado ON solicitudes_pago(estado, liga_id);
SQL);
    $st = $pdo->prepare('INSERT OR IGNORE INTO ajustes (clave, valor) VALUES (?, ?)');
    $st->execute(['dias_gracia_demo', '0']);
    $st->execute(['url_sitio', '']);
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    foreach ([
        ['💵 Contrate o renueve sin salir del sistema', 'En ⭐ Mi plan ahora puede elegir su plan, transferir y subir la foto del comprobante. Lo revisamos y su cuenta queda activa el mismo día; le avisamos por correo y Telegram. Si está en demo, al contratar sigue con todo lo que ya cargó.', 'todos', 'admin.php?p=mi_plan#contratar', 'v33', '2026-10-04'],
        ['✉️ Avisos por correo', 'Además de Telegram, ahora recibe por correo la bienvenida con sus accesos, los avisos de vencimiento y la confirmación de cada pago. Revise que el correo de contacto de su cuenta esté bien (Parámetros).', 'todos', 'admin.php?p=config', 'v33', '2026-10-04'],
    ] as $n) $ins->execute(array_merge($n, [$n[0]]));
}

/*
 * Versión 22 (paquete v24, 1-oct-2026):
 *  - PROTECCIÓN DE DATOS: consentimientos (prueba de cada autorización) y solicitudes_derechos (acceso, rectificación, eliminación…).
 *  - CAMPEONATOS DE DUELOS (tenis, pádel, tenis de mesa, artes marciales, boxeo): duelo_categorias, duelo_participantes,
 *    duelo_combates y reglas duelo_* en torneos.
 *  - BOLOS: usa las tablas de carreras (participantes, fechas = jornadas, resultados = líneas) con carrera_participantes.handicap,
 *    carrera_resultados.pinos y reglas bolos_* en torneos.
 */
function esquema_v22(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS consentimientos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER REFERENCES ligas(id) ON DELETE SET NULL,
    formulario TEXT NOT NULL,
    ref_tabla TEXT NOT NULL DEFAULT '',
    ref_id INTEGER,
    titular TEXT NOT NULL DEFAULT '',
    documento TEXT NOT NULL DEFAULT '',
    por TEXT NOT NULL DEFAULT 'titular',
    representante TEXT NOT NULL DEFAULT '',
    texto TEXT NOT NULL DEFAULT '',
    version TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    agente TEXT NOT NULL DEFAULT '',
    usuario TEXT NOT NULL DEFAULT '',
    revocado TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_consent_liga ON consentimientos(liga_id, creado);
CREATE TABLE IF NOT EXISTS solicitudes_derechos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER REFERENCES ligas(id) ON DELETE SET NULL,
    tipo TEXT NOT NULL,
    nombre TEXT NOT NULL,
    cedula TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    en_nombre_de TEXT NOT NULL DEFAULT '',
    detalle TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'nueva',
    respuesta TEXT NOT NULL DEFAULT '',
    respondido_por TEXT NOT NULL DEFAULT '',
    respondido_en TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_derechos_liga ON solicitudes_derechos(liga_id, estado);
CREATE TABLE IF NOT EXISTS duelo_categorias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    orden INTEGER NOT NULL DEFAULT 0,
    formato TEXT NOT NULL DEFAULT 'llave',
    rama TEXT NOT NULL DEFAULT '',
    peso_min REAL NOT NULL DEFAULT 0,
    peso_max REAL NOT NULL DEFAULT 0,
    edad_min INTEGER NOT NULL DEFAULT 0,
    edad_max INTEGER NOT NULL DEFAULT 0,
    nota TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_duelo_cat_torneo ON duelo_categorias(torneo_id, orden);
CREATE TABLE IF NOT EXISTS duelo_participantes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    categoria_id INTEGER REFERENCES duelo_categorias(id) ON DELETE SET NULL,
    nombre TEXT NOT NULL,
    pareja TEXT NOT NULL DEFAULT '',
    club TEXT NOT NULL DEFAULT '',
    ciudad TEXT NOT NULL DEFAULT '',
    rama TEXT NOT NULL DEFAULT '',
    nacimiento TEXT NOT NULL DEFAULT '',
    peso REAL,
    grado TEXT NOT NULL DEFAULT '',
    siembra INTEGER NOT NULL DEFAULT 0,
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_duelo_part ON duelo_participantes(torneo_id, categoria_id);
CREATE TABLE IF NOT EXISTS duelo_combates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    categoria_id INTEGER NOT NULL REFERENCES duelo_categorias(id) ON DELETE CASCADE,
    ronda INTEGER NOT NULL DEFAULT 1,
    pos INTEGER NOT NULL DEFAULT 1,
    tercer INTEGER NOT NULL DEFAULT 0,
    a_id INTEGER REFERENCES duelo_participantes(id) ON DELETE SET NULL,
    b_id INTEGER REFERENCES duelo_participantes(id) ON DELETE SET NULL,
    fecha TEXT NOT NULL DEFAULT '',
    hora TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    ganador_id INTEGER,
    metodo TEXT NOT NULL DEFAULT '',
    marcador TEXT NOT NULL DEFAULT '',
    pts_a REAL,
    pts_b REAL,
    round_fin INTEGER NOT NULL DEFAULT 0,
    nota TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_duelo_comb_cat ON duelo_combates(categoria_id, ronda, pos);
CREATE INDEX IF NOT EXISTS idx_duelo_comb_torneo ON duelo_combates(torneo_id, fecha);
SQL);
    $agregar('torneos', [
        'duelo_sets' => 'INTEGER NOT NULL DEFAULT 3', 'duelo_rounds' => 'INTEGER NOT NULL DEFAULT 3',
        'duelo_pts_gana' => 'INTEGER NOT NULL DEFAULT 2', 'duelo_pts_empate' => 'INTEGER NOT NULL DEFAULT 1', 'duelo_pts_pierde' => 'INTEGER NOT NULL DEFAULT 0',
        'duelo_bronce' => "TEXT NOT NULL DEFAULT ''",
        'bolos_lineas' => 'INTEGER NOT NULL DEFAULT 3', 'bolos_hcp' => "TEXT NOT NULL DEFAULT 'no'", 'bolos_hcp_base' => 'INTEGER NOT NULL DEFAULT 200',
        'bolos_hcp_pct' => 'INTEGER NOT NULL DEFAULT 80', 'bolos_clasif' => "TEXT NOT NULL DEFAULT 'total'",
    ]);
    $agregar('carrera_participantes', ['handicap' => 'REAL NOT NULL DEFAULT 0']);
    $agregar('carrera_resultados', ['pinos' => 'INTEGER']);
}

/*
 * Versión 21 (paquete v23, 30-sep-2026): PENALIZACIONES CON NOMBRE en los campeonatos de carreras.
 *  - carrera_penalidades: catálogo por campeonato (salida en falso, puerta no pasada, etc.) con su valor en segundos
 *    (negativo = bonificación).
 *  - carrera_resultados.pens: cuántas veces se aplicó cada penalización en la manga, en JSON {"id": veces}.
 *  - carrera_resultados.motivo: motivo de la descalificación / de no terminar, o una nota del comisario.
 *  A los campeonatos de carreras que ya existían se les carga el catálogo típico de su deporte.
 */
function esquema_v21(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS carrera_penalidades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    segundos REAL NOT NULL DEFAULT 0,
    orden INTEGER NOT NULL DEFAULT 0,
    activo INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_carr_pen_torneo ON carrera_penalidades(torneo_id, orden);
SQL);
    $ya = array_column($pdo->query('PRAGMA table_info(carrera_resultados)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('pens', $ya, true)) $pdo->exec("ALTER TABLE carrera_resultados ADD COLUMN pens TEXT NOT NULL DEFAULT ''");
    if (!in_array('motivo', $ya, true)) $pdo->exec("ALTER TABLE carrera_resultados ADD COLUMN motivo TEXT NOT NULL DEFAULT ''");
    $ins = $pdo->prepare('INSERT INTO carrera_penalidades (torneo_id, nombre, segundos, orden) VALUES (?,?,?,?)');
    foreach ($pdo->query("SELECT id, modalidad FROM torneos WHERE modalidad IN ('AUTOX','CICLO')")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM carrera_penalidades WHERE torneo_id = ' . (int)$t['id'])->fetchColumn();
        if ($n) continue;
        foreach (carrera_penalidades_base($t['modalidad']) as $i => [$nom, $seg]) $ins->execute([$t['id'], $nom, $seg, $i + 1]);
    }
}

/*
 * Versión 20 (paquete v22, 29-sep-2026): CAMPEONATOS DE CARRERAS (autocross y ciclismo).
 *  Categorías (por cilindrada / tipo de motor o por edad y rama), participantes, fechas con varias mangas y
 *  resultados con tiempo, conos, penalizaciones/bonificaciones en segundos y estado (terminó, no terminó, descalificado, no largó).
 *  Las reglas del campeonato de carreras van en columnas nuevas de torneos (carrera_*).
 */
function esquema_v20(PDO $pdo): void
{
    $ya = array_column($pdo->query('PRAGMA table_info(torneos)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ([
        'carrera_puntos' => "TEXT NOT NULL DEFAULT '25,20,16,13,11,10,9,8,7,6,5,4,3,2,1'",
        'carrera_mangas' => 'INTEGER NOT NULL DEFAULT 2',
        'carrera_modo' => "TEXT NOT NULL DEFAULT 'mejor'",
        'carrera_pen_cono' => 'REAL NOT NULL DEFAULT 2',
        'carrera_descarte' => 'INTEGER NOT NULL DEFAULT 0',
        'carrera_clasif' => "TEXT NOT NULL DEFAULT 'puntos'",
    ] as $c => $def) {
        if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE torneos ADD COLUMN $c $def");
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS carrera_categorias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    orden INTEGER NOT NULL DEFAULT 0,
    cc_min INTEGER NOT NULL DEFAULT 0,
    cc_max INTEGER NOT NULL DEFAULT 0,
    aspiracion TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_carr_cat_torneo ON carrera_categorias(torneo_id, orden);
CREATE TABLE IF NOT EXISTS carrera_participantes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    categoria_id INTEGER REFERENCES carrera_categorias(id) ON DELETE SET NULL,
    numero TEXT NOT NULL DEFAULT '',
    nombre TEXT NOT NULL,
    club TEXT NOT NULL DEFAULT '',
    ciudad TEXT NOT NULL DEFAULT '',
    vehiculo TEXT NOT NULL DEFAULT '',
    cc INTEGER NOT NULL DEFAULT 0,
    aspiracion TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_carr_part_torneo ON carrera_participantes(torneo_id, categoria_id);
CREATE TABLE IF NOT EXISTS carrera_fechas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    num INTEGER NOT NULL DEFAULT 1,
    nombre TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    mangas INTEGER NOT NULL DEFAULT 0,
    nota TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_carr_fecha_torneo ON carrera_fechas(torneo_id, num);
CREATE TABLE IF NOT EXISTS carrera_resultados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fecha_id INTEGER NOT NULL REFERENCES carrera_fechas(id) ON DELETE CASCADE,
    participante_id INTEGER NOT NULL REFERENCES carrera_participantes(id) ON DELETE CASCADE,
    manga INTEGER NOT NULL DEFAULT 1,
    tiempo_ms INTEGER,
    conos INTEGER NOT NULL DEFAULT 0,
    ajuste_seg REAL NOT NULL DEFAULT 0,
    estado TEXT NOT NULL DEFAULT 'ok',
    UNIQUE (fecha_id, participante_id, manga)
);
CREATE INDEX IF NOT EXISTS idx_carr_res_fecha ON carrera_resultados(fecha_id);
SQL);
}

/*
 * Versión 19 (paquete v21, 29-sep-2026; antes paquete v20):
 *  - Directorio público de clientes: cada liga o escuela puede aparecer (o no) en los menús
 *    "Ligas" y "Escuelas" de la página principal (columna ligas.en_directorio, 1 = sí).
 */
function esquema_v19(PDO $pdo): void
{
    // Por si la base venía de un paquete de prueba que numeró el directorio como versión 18 sin la escuela multideporte
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'esc_competencias'")->fetchColumn()) esquema_v18($pdo);
    $ya = array_column($pdo->query('PRAGMA table_info(ligas)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('en_directorio', $ya, true)) $pdo->exec('ALTER TABLE ligas ADD COLUMN en_directorio INTEGER NOT NULL DEFAULT 1');
}

/*
 * Versión 18 (paquete v21, 29-sep-2026): ESCUELA MULTIDEPORTE.
 *  Deporte por grupo; tests, habilidades y ejercicios con su deporte ('' = todos); alumno en varios grupos
 *  (esc_alumno_grupos) con una mensualidad por cada uno (esc_cargos.grupo_id); club por deporte: básquet
 *  (rebotes, faltas), vóley (detalle de sets), combates y pruebas individuales (esc_competencias) y cinturones (esc_grados).
 */
function esquema_v18(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    foreach (['esc_grupos', 'esc_tests', 'esc_habilidades', 'esc_ejercicios'] as $t) $agregar($t, ['deporte' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('esc_cargos', ['grupo_id' => 'INTEGER']);
    // Una mensualidad por alumno, mes Y grupo (antes solo una por alumno y mes)
    $pdo->exec('DROP INDEX IF EXISTS idx_esc_mensualidad');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_esc_mensualidad_g ON esc_cargos(alumno_id, tipo, periodo, COALESCE(grupo_id, 0)) WHERE tipo = 'mensualidad'");
    $agregar('ligas', ['esc_desc_segundo' => 'REAL NOT NULL DEFAULT 0']);
    $agregar('esc_partidos', ['sets' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('esc_partido_jugadores', ['rebotes' => 'INTEGER NOT NULL DEFAULT 0', 'faltas' => 'INTEGER NOT NULL DEFAULT 0']);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS esc_alumno_grupos (
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    grupo_id INTEGER NOT NULL REFERENCES esc_grupos(id) ON DELETE CASCADE,
    desde TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (alumno_id, grupo_id)
);
CREATE INDEX IF NOT EXISTS idx_esc_ag_grupo ON esc_alumno_grupos(grupo_id);
CREATE TABLE IF NOT EXISTS esc_competencias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    grupo_id INTEGER REFERENCES esc_grupos(id) ON DELETE SET NULL,
    deporte TEXT NOT NULL DEFAULT '',
    tipo TEXT NOT NULL DEFAULT 'combate',
    fecha TEXT NOT NULL,
    evento TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    rival TEXT NOT NULL DEFAULT '',
    rival_club TEXT NOT NULL DEFAULT '',
    categoria TEXT NOT NULL DEFAULT '',
    peso REAL,
    rounds INTEGER NOT NULL DEFAULT 0,
    resultado TEXT NOT NULL DEFAULT '',
    metodo TEXT NOT NULL DEFAULT '',
    prueba TEXT NOT NULL DEFAULT '',
    marca TEXT NOT NULL DEFAULT '',
    valor REAL,
    unidad TEXT NOT NULL DEFAULT '',
    puesto INTEGER,
    medalla TEXT NOT NULL DEFAULT '',
    notas TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_esc_comp_alumno ON esc_competencias(alumno_id);
CREATE INDEX IF NOT EXISTS idx_esc_comp_liga ON esc_competencias(liga_id, fecha);
CREATE TABLE IF NOT EXISTS esc_grados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    grado TEXT NOT NULL,
    examinador TEXT NOT NULL DEFAULT '',
    notas TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_esc_grados_alumno ON esc_grados(alumno_id, fecha);
SQL);
    // Lo que ya tenían las escuelas era de su deporte (casi siempre fútbol): se marca con su familia.
    $fam = ['futbol' => 'futbol', 'indor' => 'futbol', 'basquet' => 'basquet', 'ecuavoley' => 'voley', 'voley' => 'voley', 'natacion' => 'natacion'];
    $generales = ['Movilidad articular y skipping', 'Velocidad de reacción', 'Fuerza con el propio cuerpo', 'Estiramientos y charla final'];
    foreach ($pdo->query("SELECT id, deporte_escuela FROM ligas WHERE tipo = 'escuela'")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $f = $fam[$l['deporte_escuela']] ?? 'otro';
        $pdo->prepare("UPDATE esc_tests SET deporte = ? WHERE liga_id = ? AND tipo = 'tecnico' AND deporte = ''")->execute([$f, $l['id']]);
        $pdo->prepare("UPDATE esc_habilidades SET deporte = ? WHERE liga_id = ? AND nombre <> 'Actitud y disciplina' AND deporte = ''")->execute([$f, $l['id']]);
        $in = implode(',', array_fill(0, count($generales), '?'));
        $pdo->prepare("UPDATE esc_ejercicios SET deporte = ? WHERE liga_id = ? AND deporte = '' AND nombre NOT IN ($in)")->execute(array_merge([$f, $l['id']], $generales));
    }
}

/*
 * Versión 17 (paquete v18, 29-sep-2026):
 *  - Cumpleañeros y aniversarios (fecha de fundación del equipo) en la página pública.
 *  - Documentos de la liga (reglamento, bases…) en PDF o imagen.
 *  - CONTABILIDAD: facturas recibidas (importadas del SRI con XML, TXT o clave de acceso, o escritas a mano)
 *    que se incluyen o no como gasto; los gastos guardan proveedor y número de factura.
 */
function esquema_v17(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $agregar('equipos', ['fundado' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('ligas', ['ruc' => "TEXT NOT NULL DEFAULT ''", 'mostrar_cumples' => 'INTEGER NOT NULL DEFAULT 1']);
    $agregar('movimientos', ['compra_id' => 'INTEGER', 'proveedor' => "TEXT NOT NULL DEFAULT ''", 'factura' => "TEXT NOT NULL DEFAULT ''", 'factura_archivo' => "TEXT NOT NULL DEFAULT ''"]);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS liga_documentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    torneo_id INTEGER REFERENCES torneos(id) ON DELETE CASCADE,
    titulo TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'reglamento',
    archivo TEXT NOT NULL,
    publico INTEGER NOT NULL DEFAULT 1,
    orden INTEGER NOT NULL DEFAULT 0,
    subido_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_liga_doc ON liga_documentos(liga_id);
CREATE TABLE IF NOT EXISTS compras (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    clave_acceso TEXT NOT NULL DEFAULT '',
    tipo TEXT NOT NULL DEFAULT 'factura',
    ruc_emisor TEXT NOT NULL DEFAULT '',
    razon_social TEXT NOT NULL DEFAULT '',
    numero TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    id_comprador TEXT NOT NULL DEFAULT '',
    base_0 REAL NOT NULL DEFAULT 0,
    base_iva REAL NOT NULL DEFAULT 0,
    iva REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    detalle TEXT NOT NULL DEFAULT '',
    doc_modificado TEXT NOT NULL DEFAULT '',
    origen TEXT NOT NULL DEFAULT 'xml',
    completa INTEGER NOT NULL DEFAULT 1,
    xml TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    movimiento_id INTEGER REFERENCES movimientos(id) ON DELETE SET NULL,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_compras_liga ON compras(liga_id, estado);
CREATE UNIQUE INDEX IF NOT EXISTS idx_compras_clave ON compras(liga_id, clave_acceso) WHERE clave_acceso <> '';
SQL);
}

/*
 * Versión 16 (paquete v17, 29-sep-2026): ESCUELAS — ENTRENAMIENTOS, DESARROLLO Y DIVISIONES FORMATIVAS.
 *  A) Planificación de clases por adelantado, biblioteca de ejercicios, ejercicios con minutos por clase, intensidad,
 *     fotos de la clase, nota individual por alumno y ciclos de trabajo; los padres ven el diario de entrenamientos.
 *  B) Metas individuales y baremos (semáforo por edad) para el panel de desarrollo y el informe.
 *  C) Jornadas de evaluación (resultados y medidas quedan unidos a la jornada).
 *  D) Divisiones formativas (se activa por escuela): partidos con minutos, notas del staff por área, valoraciones,
 *     seguimiento escolar, maduración (talla sentado, talla de los padres) y lesiones con fases de regreso.
 */
function esquema_v16(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $agregar('ligas', ['esc_formativas' => 'INTEGER NOT NULL DEFAULT 0']);
    $agregar('usuarios', ['area' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('esc_sesiones', [
        'estado' => "TEXT NOT NULL DEFAULT 'dictada'", 'motivo' => "TEXT NOT NULL DEFAULT ''",
        'duracion' => 'INTEGER NOT NULL DEFAULT 0', 'intensidad' => 'INTEGER NOT NULL DEFAULT 0', 'rpe' => 'INTEGER NOT NULL DEFAULT 0',
        'visible_familia' => 'INTEGER NOT NULL DEFAULT 1', 'ciclo_id' => 'INTEGER',
    ]);
    $agregar('esc_resultados', ['jornada_id' => 'INTEGER']);
    $agregar('esc_mediciones', ['jornada_id' => 'INTEGER', 'talla_sentado' => 'REAL']);
    $agregar('esc_alumnos', ['talla_padre' => 'REAL', 'talla_madre' => 'REAL']);
    $agregar('esc_lesiones', ['diagnostico' => "TEXT NOT NULL DEFAULT ''", 'fase' => "TEXT NOT NULL DEFAULT ''", 'retorno_estimado' => "TEXT NOT NULL DEFAULT ''", 'profesional' => "TEXT NOT NULL DEFAULT ''"]);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS esc_ejercicios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'tecnico',
    objetivo TEXT NOT NULL DEFAULT '',
    descripcion TEXT NOT NULL DEFAULT '',
    duracion INTEGER NOT NULL DEFAULT 10,
    jugadores TEXT NOT NULL DEFAULT '',
    materiales TEXT NOT NULL DEFAULT '',
    edades TEXT NOT NULL DEFAULT '',
    video TEXT NOT NULL DEFAULT '',
    imagen TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_esc_ejer_liga ON esc_ejercicios(liga_id, activo);
CREATE TABLE IF NOT EXISTS esc_sesion_ejercicios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sesion_id INTEGER NOT NULL REFERENCES esc_sesiones(id) ON DELETE CASCADE,
    ejercicio_id INTEGER REFERENCES esc_ejercicios(id) ON DELETE SET NULL,
    nombre TEXT NOT NULL DEFAULT '',
    fase TEXT NOT NULL DEFAULT 'principal',
    minutos INTEGER NOT NULL DEFAULT 0,
    notas TEXT NOT NULL DEFAULT '',
    orden INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_esc_sesej ON esc_sesion_ejercicios(sesion_id, orden);
CREATE TABLE IF NOT EXISTS esc_sesion_fotos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sesion_id INTEGER NOT NULL REFERENCES esc_sesiones(id) ON DELETE CASCADE,
    archivo TEXT NOT NULL,
    descripcion TEXT NOT NULL DEFAULT '',
    publica INTEGER NOT NULL DEFAULT 1,
    subido_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_notas_clase (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sesion_id INTEGER NOT NULL REFERENCES esc_sesiones(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL DEFAULT 'nota',
    texto TEXT NOT NULL DEFAULT '',
    visible_familia INTEGER NOT NULL DEFAULT 1,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (sesion_id, alumno_id)
);
CREATE INDEX IF NOT EXISTS idx_esc_notas_alum ON esc_notas_clase(alumno_id);
CREATE TABLE IF NOT EXISTS esc_ciclos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    grupo_id INTEGER REFERENCES esc_grupos(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    desde TEXT NOT NULL,
    hasta TEXT NOT NULL,
    objetivo TEXT NOT NULL DEFAULT '',
    contenidos TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_jornadas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    fecha TEXT NOT NULL,
    tests TEXT NOT NULL DEFAULT '',
    grupos TEXT NOT NULL DEFAULT '',
    medidas INTEGER NOT NULL DEFAULT 1,
    estado TEXT NOT NULL DEFAULT 'abierta',
    cerrada_en TEXT NOT NULL DEFAULT '',
    notas TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_metas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    area TEXT NOT NULL DEFAULT 'tecnica',
    texto TEXT NOT NULL,
    fecha_objetivo TEXT NOT NULL DEFAULT '',
    avance INTEGER NOT NULL DEFAULT 0,
    estado TEXT NOT NULL DEFAULT 'en_curso',
    comentario TEXT NOT NULL DEFAULT '',
    visible_familia INTEGER NOT NULL DEFAULT 1,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_baremos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    test_id INTEGER NOT NULL REFERENCES esc_tests(id) ON DELETE CASCADE,
    edad_desde INTEGER NOT NULL DEFAULT 0,
    edad_hasta INTEGER NOT NULL DEFAULT 99,
    genero TEXT NOT NULL DEFAULT '',
    amarillo REAL NOT NULL,
    verde REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS esc_staff_notas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    area TEXT NOT NULL DEFAULT 'entrenador',
    fecha TEXT NOT NULL,
    texto TEXT NOT NULL,
    privada INTEGER NOT NULL DEFAULT 1,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_esc_staff_alum ON esc_staff_notas(alumno_id, fecha);
CREATE TABLE IF NOT EXISTS esc_partidos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    grupo_id INTEGER REFERENCES esc_grupos(id) ON DELETE SET NULL,
    fecha TEXT NOT NULL,
    hora TEXT NOT NULL DEFAULT '',
    rival TEXT NOT NULL,
    competencia TEXT NOT NULL DEFAULT 'Amistoso',
    condicion TEXT NOT NULL DEFAULT 'local',
    lugar TEXT NOT NULL DEFAULT '',
    goles_favor INTEGER,
    goles_contra INTEGER,
    duracion INTEGER NOT NULL DEFAULT 70,
    notas TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_partido_jugadores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partido_id INTEGER NOT NULL REFERENCES esc_partidos(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    convocado INTEGER NOT NULL DEFAULT 1,
    titular INTEGER NOT NULL DEFAULT 0,
    minutos INTEGER NOT NULL DEFAULT 0,
    goles INTEGER NOT NULL DEFAULT 0,
    asistencias INTEGER NOT NULL DEFAULT 0,
    amarillas INTEGER NOT NULL DEFAULT 0,
    rojas INTEGER NOT NULL DEFAULT 0,
    calificacion REAL,
    nota TEXT NOT NULL DEFAULT '',
    UNIQUE (partido_id, alumno_id)
);
CREATE INDEX IF NOT EXISTS idx_esc_pj_alum ON esc_partido_jugadores(alumno_id);
CREATE TABLE IF NOT EXISTS esc_valoraciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    posicion TEXT NOT NULL DEFAULT '',
    posicion2 TEXT NOT NULL DEFAULT '',
    perfil TEXT NOT NULL DEFAULT '',
    fortalezas TEXT NOT NULL DEFAULT '',
    mejorar TEXT NOT NULL DEFAULT '',
    potencial TEXT NOT NULL DEFAULT 'B',
    recomendacion TEXT NOT NULL DEFAULT 'mantener',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_academico (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    periodo TEXT NOT NULL,
    promedio REAL,
    asistencia REAL,
    conducta TEXT NOT NULL DEFAULT '',
    materias_bajas TEXT NOT NULL DEFAULT '',
    observacion TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_lesion_seguimiento (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lesion_id INTEGER NOT NULL REFERENCES esc_lesiones(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    fase TEXT NOT NULL DEFAULT '',
    texto TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
}

/*
 * Versión 15 (paquete v16, 28-sep-2026): PRECIOS NUEVOS, PLANES DE ESCUELA, DOMINIO GRATIS ANUAL Y COMBO LIGA + ESCUELA.
 *  Ligas: Torneo $10 por 3 meses (1 torneo, 20 equipos) · Básico $10 al mes (3 torneos a la vez, equipos ilimitados)
 *         · Profesional $15 al mes (todo ilimitado, todos los módulos; dominio gratis pagando 1 año). Se quita Liga Grande ($25).
 *  Escuelas: Escuela Básico $10 (con cobros con tarjeta y avisos por Telegram) · Escuela Completo $15 (todo; dominio gratis anual).
 *  Semestre: 1 mes gratis (5 × mensual). Año: 2 meses gratis (10 × mensual).
 *  Combo: la misma persona con una liga y una escuela (ligas.combo_id) tiene 30% de descuento en los dos planes.
 */
function esquema_v15(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('dominio_anual', $cols('planes'), true)) $pdo->exec("ALTER TABLE planes ADD COLUMN dominio_anual INTEGER NOT NULL DEFAULT 0");
    if (!in_array('combo_id', $cols('ligas'), true)) $pdo->exec("ALTER TABLE ligas ADD COLUMN combo_id INTEGER");
    $id = fn(string $slug) => (int)$pdo->query("SELECT id FROM planes WHERE slug = " . $pdo->quote($slug) . " LIMIT 1")->fetchColumn();
    $upd = $pdo->prepare('UPDATE planes SET nombre = ?, tipo_cuenta = ?, precio_mensual = ?, precio_trimestral = ?, precio_semestral = ?, precio_anual = ?,
                          max_campeonatos = ?, max_equipos = ?, max_equipos_torneo = ?, max_alumnos = 0, modulos = ?, eslogan = ?, descripcion = ?,
                          destacado = ?, activo = 1, orden = ?, dominio_anual = ? WHERE id = ?');
    $ins = $pdo->prepare('INSERT INTO planes (nombre, slug, tipo_cuenta, precio_mensual, precio_trimestral, precio_semestral, precio_anual, max_campeonatos,
                          max_equipos, max_equipos_torneo, max_alumnos, modulos, eslogan, descripcion, destacado, activo, orden, dominio_anual)
                          VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,1,?,?)');
    $planes = [
        // slug => [nombre, tipo, mensual, trimestral, semestral, anual, torneos, equipos, equipos por torneo, módulos, eslogan, descripción, destacado, orden, dominio anual]
        'torneo' => ['Torneo', 'liga', 0, 10, 0, 0, 1, 20, 20, '*', 'Un campeonato de 3 meses con TODO incluido', 'Ideal para campeonatos relámpago, de verano o de barrio', 0, 0, 0],
        'basico' => ['Básico', 'liga', 10, 0, 50, 100, 3, 0, 0, 'vocalia,carnets,inscripcion,finanzas,redes', 'Para ordenar su liga: 3 torneos a la vez y equipos ilimitados', '', 0, 1, 0],
        'profesional' => ['Profesional', 'liga', 15, 0, 75, 150, 0, 0, 0, '*', 'Todo incluido y todo ilimitado', 'Soporte prioritario por WhatsApp', 1, 2, 1],
        'escuela-basico' => ['Escuela Básico', 'escuela', 10, 0, 50, 100, 0, 0, 0, 'carnets,esc_familias,pagos_tarjeta,avisos', 'Alumnos, mensualidades, cobros con tarjeta y avisos por Telegram', '', 0, 5, 0],
        'escuela-completo' => ['Escuela Completo', 'escuela', 15, 0, 75, 150, 0, 0, 0, '*', 'La escuela completa: profesores, evaluaciones, tienda y más', 'Soporte prioritario por WhatsApp', 1, 6, 1],
    ];
    foreach ($planes as $slug => $d) {
        [$nom, $tipo, $pm, $pt, $ps, $pa, $mc, $me, $met, $mod, $esl, $desc, $dest, $ord, $dom] = $d;
        if ($pid = $id($slug)) $upd->execute([$nom, $tipo, $pm, $pt, $ps, $pa, $mc, $me, $met, $mod, $esl, $desc, $dest, $ord, $dom, $pid]);
        else $ins->execute([$nom, $slug, $tipo, $pm, $pt, $ps, $pa, $mc, $me, $met, $mod, $esl, $desc, $dest, $ord, $dom]);
    }
    // Las cuentas pasan a su plan nuevo: ligas en Liga Grande → Profesional; escuelas → Escuela Básico / Escuela Completo
    $grande = $id('liga-grande');
    [$bas, $pro, $eb, $ec] = [$id('basico'), $id('profesional'), $id('escuela-basico'), $id('escuela-completo')];
    if ($grande) {
        $pdo->exec("UPDATE ligas SET plan_id = $pro WHERE plan_id = $grande AND COALESCE(tipo, 'liga') <> 'escuela'");
        $pdo->exec("UPDATE ligas SET plan_id = $ec WHERE plan_id = $grande AND tipo = 'escuela'");
        $pdo->exec("UPDATE planes SET activo = 0, destacado = 0, eslogan = 'Ya no se ofrece: todo lo de Liga Grande está en Profesional' WHERE id = $grande");
    }
    $pdo->exec("UPDATE ligas SET plan_id = $eb WHERE plan_id = $bas AND tipo = 'escuela'");
    $pdo->exec("UPDATE ligas SET plan_id = $ec WHERE plan_id = $pro AND tipo = 'escuela'");
    // Otros planes que tuvieran "ambos" y ya no correspondan quedan como estaban (el superadministrador los revisa en Planes y precios)
    $st = $pdo->prepare('INSERT OR IGNORE INTO ajustes (clave, valor) VALUES (?, ?)');
    foreach (['combo_descuento' => '30', 'nota_dominio' => ajustes_por_defecto()['nota_dominio']] as $k => $v) $st->execute([$k, $v]);
}

/*
 * Versión 14: DEMO POR SOLICITUD. Se quita la prueba gratis automática: el interesado llena
 * "Solicitar demo" y el superadministrador decide a quién se la crea (y por cuántos días).
 */
function esquema_v14(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS solicitudes_demo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL DEFAULT 'liga',
    organizacion TEXT NOT NULL,
    ciudad TEXT NOT NULL DEFAULT '',
    contacto TEXT NOT NULL,
    telefono TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    tamano TEXT NOT NULL DEFAULT '',
    plan_id INTEGER REFERENCES planes(id) ON DELETE SET NULL,
    mensaje TEXT NOT NULL DEFAULT '',
    vendedor_id INTEGER REFERENCES vendedores(id) ON DELETE SET NULL,
    estado TEXT NOT NULL DEFAULT 'nueva',
    nota TEXT NOT NULL DEFAULT '',
    liga_id INTEGER REFERENCES ligas(id) ON DELETE SET NULL,
    ip TEXT NOT NULL DEFAULT '',
    atendido_por TEXT NOT NULL DEFAULT '',
    atendido_en TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sol_demo_estado ON solicitudes_demo(estado);
SQL);
    // La prueba gratis automática queda cerrada (se puede volver a abrir en Ajustes)
    $pdo->exec("INSERT INTO ajustes (clave, valor) VALUES ('registro_abierto', '0') ON CONFLICT(clave) DO UPDATE SET valor = '0'");
    $pdo->exec("INSERT OR IGNORE INTO ajustes (clave, valor) VALUES ('dias_demo', '7')");
}

/*
 * Versión 13 (v13): foto o PDF de la factura en los pagos a árbitros y a profesores (con su número de factura).
 */
function esquema_v13(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['pagos_arbitro', 'esc_pagos_profesor'] as $t) {
        $ya = $cols($t);
        if (!$ya) continue;
        if (!in_array('factura', $ya, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN factura TEXT NOT NULL DEFAULT ''");
        if (!in_array('factura_numero', $ya, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN factura_numero TEXT NOT NULL DEFAULT ''");
        if (!in_array('factura_subida', $ya, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN factura_subida TEXT NOT NULL DEFAULT ''");
    }
}

/*
 * Versión 12: planes por módulos (precio por mes, trimestre, semestre y año; módulos activos; torneos, equipos por torneo,
 * alumnos), plan "Torneo", cobros con tarjeta (Plux) de las organizaciones hacia TuAcademia y de sus equipos/familias hacia
 * ellas con TuAcademia de intermediario, liquidaciones, y rol de pago de los profesores.
 */
function esquema_v12(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $agregar('planes', [
        'precio_trimestral' => 'REAL NOT NULL DEFAULT 0', 'precio_semestral' => 'REAL NOT NULL DEFAULT 0',
        'modulos' => "TEXT NOT NULL DEFAULT '*'", 'max_equipos_torneo' => 'INTEGER NOT NULL DEFAULT 0', 'max_alumnos' => 'INTEGER NOT NULL DEFAULT 0',
        'tipo_cuenta' => "TEXT NOT NULL DEFAULT 'ambos'", 'eslogan' => "TEXT NOT NULL DEFAULT ''", 'slug' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $agregar('ligas', [
        'modulos_extra' => "TEXT NOT NULL DEFAULT ''", 'pagos_tarjeta' => 'INTEGER NOT NULL DEFAULT 0',
        'pagos_comision' => "TEXT NOT NULL DEFAULT 'organizacion'", 'cuenta_liquidacion' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $agregar('esc_profesores', ['iess_pct' => 'REAL NOT NULL DEFAULT 0', 'cargo' => "TEXT NOT NULL DEFAULT 'Profesor'"]);
    $agregar('esc_pagos_profesor', [
        'base' => 'REAL NOT NULL DEFAULT 0', 'bonos' => 'REAL NOT NULL DEFAULT 0', 'bonos_detalle' => "TEXT NOT NULL DEFAULT ''",
        'iess' => 'REAL NOT NULL DEFAULT 0', 'anticipos' => 'REAL NOT NULL DEFAULT 0', 'otros_desc' => 'REAL NOT NULL DEFAULT 0', 'desc_detalle' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pagos_linea (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT NOT NULL UNIQUE,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    destino TEXT NOT NULL DEFAULT 'organizacion',
    concepto TEXT NOT NULL,
    detalle TEXT NOT NULL DEFAULT '',
    datos TEXT NOT NULL DEFAULT '',
    equipo_id INTEGER REFERENCES equipos(id) ON DELETE SET NULL,
    plan_id INTEGER REFERENCES planes(id) ON DELETE SET NULL,
    meses INTEGER NOT NULL DEFAULT 0,
    pagador_nombre TEXT NOT NULL DEFAULT '',
    pagador_email TEXT NOT NULL DEFAULT '',
    pagador_cedula TEXT NOT NULL DEFAULT '',
    pagador_telefono TEXT NOT NULL DEFAULT '',
    monto_base REAL NOT NULL DEFAULT 0,
    recargo REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    quien_comision TEXT NOT NULL DEFAULT 'organizacion',
    tipo_tarjeta TEXT NOT NULL DEFAULT '',
    comision_pasarela REAL NOT NULL DEFAULT 0,
    comision_tuvocalia REAL NOT NULL DEFAULT 0,
    neto REAL NOT NULL DEFAULT 0,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    pasarela TEXT NOT NULL DEFAULT '',
    pasarela_id TEXT NOT NULL DEFAULT '',
    respuesta TEXT NOT NULL DEFAULT '',
    aplicado TEXT NOT NULL DEFAULT '',
    liquidacion_id INTEGER,
    ip TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT '',
    pagado_en TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_pagos_linea_liga ON pagos_linea(liga_id, estado, destino);
CREATE TABLE IF NOT EXISTS liquidaciones_org (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    pagos INTEGER NOT NULL DEFAULT 0,
    cobrado REAL NOT NULL DEFAULT 0,
    comisiones REAL NOT NULL DEFAULT 0,
    neto REAL NOT NULL DEFAULT 0,
    fecha TEXT NOT NULL DEFAULT '',
    forma TEXT NOT NULL DEFAULT 'transferencia',
    referencia TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
    // Precios pedidos por QF (28-sep-2026): se mantienen $10/$15/$25 al mes; semestre con 1 mes gratis; año con 2 meses gratis.
    // Módulos de cada plan de fábrica (el superadministrador los cambia en Planes y precios). Solo si el plan sigue con el precio de fábrica.
    $mods = [
        'Básico' => [10, 'vocalia,carnets,inscripcion,finanzas,redes,esc_familias', 'Para empezar a ordenar su liga o escuela'],
        'Profesional' => [15, 'vocalia,carnets,inscripcion,finanzas,redes,arbitros,publico,avisos,reservas,pagos_tarjeta,esc_familias,esc_profesores,esc_evaluaciones', 'La liga completa, con árbitros, patrocinadores y cobros'],
        'Liga Grande' => [25, '*', 'Todo, sin límites, con su propio dominio'],
    ];
    $up = $pdo->prepare("UPDATE planes SET precio_semestral = ?, modulos = ?, eslogan = ? WHERE nombre = ? AND precio_mensual = ? AND precio_semestral = 0");
    foreach ($mods as $nombre => [$pm, $m, $esl]) $up->execute([$pm * 5, $m, $esl, $nombre, $pm]);
    // Las descripciones de fábrica repetían lo que ahora dicen los módulos y los límites: se dejan solo los extras
    $pdo->exec("UPDATE planes SET descripcion = '' WHERE descripcion LIKE 'Hasta 12 equipos%' OR descripcion LIKE 'Hasta 30 equipos%'");
    $pdo->exec("UPDATE planes SET descripcion = 'Soporte prioritario por WhatsApp' WHERE descripcion LIKE 'Equipos ilimitados%Soporte prioritario%'");
    $pdo->exec("UPDATE planes SET slug = lower(replace(replace(replace(nombre, ' ', '-'), 'á', 'a'), 'é', 'e')) WHERE slug = ''");
    $pdo->exec("UPDATE planes SET slug = 'basico' WHERE slug = 'básico'");
    if (!(int)$pdo->query("SELECT COUNT(*) FROM planes WHERE lower(nombre) = 'torneo'")->fetchColumn()) {
        $pdo->prepare('INSERT INTO planes (nombre, slug, precio_mensual, precio_trimestral, precio_semestral, precio_anual, max_equipos, max_campeonatos, max_equipos_torneo, modulos, tipo_cuenta, eslogan, descripcion, destacado, activo, orden)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            'Torneo', 'torneo', 0, 10, 0, 0, 20, 1, 20, '*', 'liga', 'Un campeonato de 3 meses con TODO incluido',
            'Ideal para campeonatos relámpago, de verano o de barrio', 0, 1, 0,
        ]);
    }
    $st = $pdo->prepare('INSERT OR IGNORE INTO ajustes (clave, valor) VALUES (?, ?)');
    foreach (ajustes_por_defecto() as $k => $v) $st->execute([$k, (string)$v]);
}

/*
 * Versión 11: MÓDULO DE ESCUELAS DE FÚTBOL (y de otros deportes).
 * Una escuela es una cuenta más (fila de "ligas" con tipo = 'escuela'), así comparte planes, usuarios, perfiles,
 * suscripción, logo, dominio propio y bitácora. Sus datos van en tablas esc_*.
 */
function esquema_v11(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $agregar('ligas', [
        'tipo' => "TEXT NOT NULL DEFAULT 'liga'", 'deporte_escuela' => "TEXT NOT NULL DEFAULT 'futbol'",
        'esc_mensualidad' => 'REAL NOT NULL DEFAULT 0', 'esc_matricula' => 'REAL NOT NULL DEFAULT 0',
        'esc_dia_vence' => 'INTEGER NOT NULL DEFAULT 5', 'esc_desc_hermano' => 'REAL NOT NULL DEFAULT 0',
        'esc_inscripcion' => 'INTEGER NOT NULL DEFAULT 0', 'esc_texto_inscripcion' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $agregar('solicitudes_equipo', ['escuela_grupo_id' => 'INTEGER']);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS esc_sedes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    direccion TEXT NOT NULL DEFAULT '',
    activa INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS esc_profesores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    cedula TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    foto TEXT NOT NULL DEFAULT '',
    especialidad TEXT NOT NULL DEFAULT '',
    tipo_pago TEXT NOT NULL DEFAULT 'mensual',
    valor_pago REAL NOT NULL DEFAULT 0,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_grupos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    sede_id INTEGER REFERENCES esc_sedes(id) ON DELETE SET NULL,
    nombre TEXT NOT NULL,
    categoria TEXT NOT NULL DEFAULT 'abierta',
    anio_desde INTEGER,
    anio_hasta INTEGER,
    profesor_id INTEGER REFERENCES esc_profesores(id) ON DELETE SET NULL,
    dias TEXT NOT NULL DEFAULT '',
    hora_inicio TEXT NOT NULL DEFAULT '',
    hora_fin TEXT NOT NULL DEFAULT '',
    cancha TEXT NOT NULL DEFAULT '',
    cupo INTEGER NOT NULL DEFAULT 0,
    mensualidad REAL,
    activo INTEGER NOT NULL DEFAULT 1,
    equipo_destino_id INTEGER REFERENCES equipos(id) ON DELETE SET NULL,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_alumnos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    grupo_id INTEGER REFERENCES esc_grupos(id) ON DELETE SET NULL,
    nombres TEXT NOT NULL,
    apellidos TEXT NOT NULL DEFAULT '',
    cedula TEXT NOT NULL DEFAULT '',
    fecha_nac TEXT NOT NULL DEFAULT '',
    genero TEXT NOT NULL DEFAULT '',
    foto TEXT NOT NULL DEFAULT '',
    posicion TEXT NOT NULL DEFAULT '',
    pie TEXT NOT NULL DEFAULT '',
    direccion TEXT NOT NULL DEFAULT '',
    colegio TEXT NOT NULL DEFAULT '',
    representante_nombre TEXT NOT NULL DEFAULT '',
    representante_cedula TEXT NOT NULL DEFAULT '',
    representante_telefono TEXT NOT NULL DEFAULT '',
    representante_email TEXT NOT NULL DEFAULT '',
    parentesco TEXT NOT NULL DEFAULT '',
    tipo_sangre TEXT NOT NULL DEFAULT '',
    alergias TEXT NOT NULL DEFAULT '',
    condiciones TEXT NOT NULL DEFAULT '',
    medicamentos TEXT NOT NULL DEFAULT '',
    seguro TEXT NOT NULL DEFAULT '',
    emergencia_nombre TEXT NOT NULL DEFAULT '',
    emergencia_telefono TEXT NOT NULL DEFAULT '',
    beca REAL NOT NULL DEFAULT 0,
    mensualidad_especial REAL,
    dia_vence INTEGER,
    fecha_ingreso TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'activo',
    origen TEXT NOT NULL DEFAULT 'panel',
    consentimiento TEXT NOT NULL DEFAULT '',
    consentimiento_por TEXT NOT NULL DEFAULT '',
    foto_publica INTEGER NOT NULL DEFAULT 0,
    carnet_token TEXT NOT NULL DEFAULT '',
    notas TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_esc_alum_liga ON esc_alumnos(liga_id, estado);
CREATE INDEX IF NOT EXISTS idx_esc_alum_rep ON esc_alumnos(representante_cedula);
CREATE TABLE IF NOT EXISTS esc_sesiones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    grupo_id INTEGER NOT NULL REFERENCES esc_grupos(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    profesor_id INTEGER REFERENCES esc_profesores(id) ON DELETE SET NULL,
    profesor_asistio INTEGER NOT NULL DEFAULT 1,
    objetivo TEXT NOT NULL DEFAULT '',
    contenido TEXT NOT NULL DEFAULT '',
    observaciones TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (grupo_id, fecha)
);
CREATE TABLE IF NOT EXISTS esc_asistencia (
    sesion_id INTEGER NOT NULL REFERENCES esc_sesiones(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    estado TEXT NOT NULL DEFAULT 'P',
    PRIMARY KEY (sesion_id, alumno_id)
);
CREATE TABLE IF NOT EXISTS esc_cargos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL DEFAULT 'mensualidad',
    periodo TEXT NOT NULL DEFAULT '',
    descripcion TEXT NOT NULL DEFAULT '',
    monto REAL NOT NULL DEFAULT 0,
    descuento REAL NOT NULL DEFAULT 0,
    vence TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    pagado_en TEXT NOT NULL DEFAULT '',
    forma_pago TEXT NOT NULL DEFAULT '',
    recibo INTEGER,
    comprobante TEXT NOT NULL DEFAULT '',
    comprobante_estado TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_esc_mensualidad ON esc_cargos(alumno_id, tipo, periodo) WHERE tipo = 'mensualidad';
CREATE INDEX IF NOT EXISTS idx_esc_cargos_liga ON esc_cargos(liga_id, estado, vence);
CREATE TABLE IF NOT EXISTS esc_pagos_profesor (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    profesor_id INTEGER NOT NULL REFERENCES esc_profesores(id) ON DELETE CASCADE,
    periodo TEXT NOT NULL,
    clases INTEGER NOT NULL DEFAULT 0,
    alumnos INTEGER NOT NULL DEFAULT 0,
    monto REAL NOT NULL DEFAULT 0,
    fecha TEXT NOT NULL DEFAULT '',
    forma_pago TEXT NOT NULL DEFAULT 'efectivo',
    nota TEXT NOT NULL DEFAULT '',
    anulado INTEGER NOT NULL DEFAULT 0,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_mediciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    peso REAL, talla REAL, cintura REAL, cadera REAL, brazo REAL, muslo REAL, grasa REAL, envergadura REAL,
    nota TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_tests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    unidad TEXT NOT NULL DEFAULT '',
    mejor TEXT NOT NULL DEFAULT 'mayor',
    tipo TEXT NOT NULL DEFAULT 'fisico',
    descripcion TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    orden INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS esc_resultados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    test_id INTEGER NOT NULL REFERENCES esc_tests(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    valor REAL NOT NULL,
    nota TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    UNIQUE (alumno_id, test_id, fecha)
);
CREATE TABLE IF NOT EXISTS esc_habilidades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    activo INTEGER NOT NULL DEFAULT 1,
    orden INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS esc_eval_tecnica (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    valores TEXT NOT NULL DEFAULT '{}',
    comentario TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_lesiones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    zona TEXT NOT NULL DEFAULT '',
    tipo TEXT NOT NULL DEFAULT '',
    descripcion TEXT NOT NULL DEFAULT '',
    dias_baja INTEGER NOT NULL DEFAULT 0,
    alta TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_libretas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    periodo TEXT NOT NULL,
    comentario TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    actualizado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (alumno_id, periodo)
);
CREATE TABLE IF NOT EXISTS esc_productos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    talla TEXT NOT NULL DEFAULT '',
    precio REAL NOT NULL DEFAULT 0,
    stock INTEGER NOT NULL DEFAULT 0,
    activo INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS esc_ventas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    producto_id INTEGER REFERENCES esc_productos(id) ON DELETE SET NULL,
    alumno_id INTEGER REFERENCES esc_alumnos(id) ON DELETE SET NULL,
    cantidad INTEGER NOT NULL DEFAULT 1,
    precio REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    modo TEXT NOT NULL DEFAULT 'contado',
    forma_pago TEXT NOT NULL DEFAULT 'efectivo',
    cargo_id INTEGER REFERENCES esc_cargos(id) ON DELETE SET NULL,
    fecha TEXT NOT NULL DEFAULT '',
    anulado INTEGER NOT NULL DEFAULT 0,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS esc_familias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    cedula TEXT NOT NULL,
    pin TEXT NOT NULL DEFAULT '',
    codigo TEXT NOT NULL DEFAULT '',
    ultimo TEXT NOT NULL DEFAULT '',
    UNIQUE (liga_id, cedula)
);
SQL);
}

/*
 * Versión 10: dirección propia de cada campeonato, liga de demostración, tablas acumuladas,
 * pedidos de los delegados (reprogramación, descargo, apelación), recuperar contraseña y
 * notificaciones "Seguir a mi equipo" (web push).
 */
function esquema_v10(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $agregar('torneos', ['slug' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('ligas', ['es_demo' => 'INTEGER NOT NULL DEFAULT 0']);
    $pdo->exec(<<<SQL
CREATE UNIQUE INDEX IF NOT EXISTS idx_torneos_slug ON torneos(liga_id, slug) WHERE slug <> '';
CREATE TABLE IF NOT EXISTS tablas_acumuladas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    torneos TEXT NOT NULL DEFAULT '',
    publica INTEGER NOT NULL DEFAULT 1,
    nota TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_acum_liga ON tablas_acumuladas(liga_id);
CREATE TABLE IF NOT EXISTS pedidos_delegado (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    tipo TEXT NOT NULL,
    partido_id INTEGER REFERENCES partidos(id) ON DELETE SET NULL,
    sancion_id INTEGER REFERENCES sanciones(id) ON DELETE SET NULL,
    sancion_equipo_id INTEGER REFERENCES sanciones_equipo(id) ON DELETE SET NULL,
    fecha_propuesta TEXT NOT NULL DEFAULT '',
    hora_propuesta TEXT NOT NULL DEFAULT '',
    motivo TEXT NOT NULL DEFAULT '',
    archivo TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    respuesta TEXT NOT NULL DEFAULT '',
    resuelto_por TEXT NOT NULL DEFAULT '',
    resuelto_en TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pedidos_liga ON pedidos_delegado(liga_id, estado);
CREATE INDEX IF NOT EXISTS idx_pedidos_equipo ON pedidos_delegado(equipo_id);
CREATE TABLE IF NOT EXISTS recuperaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    codigo TEXT NOT NULL,
    canal TEXT NOT NULL DEFAULT '',
    expira TEXT NOT NULL,
    intentos INTEGER NOT NULL DEFAULT 0,
    usado INTEGER NOT NULL DEFAULT 0,
    ip TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_recup_usuario ON recuperaciones(usuario_id, usado);
CREATE TABLE IF NOT EXISTS push_suscripciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    ip TEXT NOT NULL DEFAULT '',
    fallos INTEGER NOT NULL DEFAULT 0,
    ultimo_envio TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS push_seguimientos (
    suscripcion_id INTEGER NOT NULL REFERENCES push_suscripciones(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (suscripcion_id, equipo_id)
);
CREATE INDEX IF NOT EXISTS idx_push_seg_equipo ON push_seguimientos(equipo_id);
CREATE TABLE IF NOT EXISTS push_cola (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    suscripcion_id INTEGER NOT NULL REFERENCES push_suscripciones(id) ON DELETE CASCADE,
    titulo TEXT NOT NULL,
    cuerpo TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '',
    clave TEXT,
    intentos INTEGER NOT NULL DEFAULT 0,
    enviado TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_push_clave ON push_cola(clave) WHERE clave IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_push_pend ON push_cola(enviado, intentos);
SQL);
    // Dirección propia para los campeonatos que ya existen (ej: apertura-2026)
    $usados = [];
    foreach ($pdo->query("SELECT id, liga_id, nombre, temporada, slug FROM torneos ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if ($t['slug'] !== '') { $usados[$t['liga_id']][] = $t['slug']; continue; }
        $s = slug_torneo_base($t['nombre'], $t['temporada']);
        $final = $s;
        $n = 2;
        while (in_array($final, $usados[$t['liga_id']] ?? [], true)) $final = $s . '-' . $n++;
        $usados[$t['liga_id']][] = $final;
        $pdo->prepare('UPDATE torneos SET slug = ? WHERE id = ?')->execute([$final, $t['id']]);
    }
    // Precios pedidos por QF (10/15/25 al mes, anual = 10 meses: 2 meses gratis), solo si los planes siguen con los precios de fábrica
    $pl = $pdo->prepare('UPDATE planes SET precio_mensual = ?, precio_anual = ? WHERE nombre = ? AND precio_mensual = ? AND precio_anual = ?');
    $pl->execute([10, 100, 'Básico', 15, 150]);
    $pl->execute([15, 150, 'Profesional', 29, 290]);
    $pl->execute([25, 250, 'Liga Grande', 49, 490]);
    $st = $pdo->prepare('INSERT OR IGNORE INTO ajustes (clave, valor) VALUES (?, ?)');
    foreach (ajustes_por_defecto() as $k => $v) $st->execute([$k, (string)$v]);
}

/*
 * Versión 9: vendedores con comisión (venden TuAcademia con su propio código) y copiar campeonato para la nueva temporada.
 */
function esquema_v9(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS vendedores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    cedula TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    ciudad TEXT NOT NULL DEFAULT '',
    codigo TEXT NOT NULL UNIQUE,
    comision_pct REAL NOT NULL DEFAULT 20,
    comision_meses INTEGER NOT NULL DEFAULT 12,
    datos_pago TEXT NOT NULL DEFAULT '',
    notas TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS comisiones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    vendedor_id INTEGER NOT NULL REFERENCES vendedores(id) ON DELETE CASCADE,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    pago_suscripcion_id INTEGER UNIQUE REFERENCES pagos_suscripcion(id) ON DELETE CASCADE,
    monto_pago REAL NOT NULL DEFAULT 0,
    meses INTEGER NOT NULL DEFAULT 0,
    porcentaje REAL NOT NULL DEFAULT 0,
    comision REAL NOT NULL DEFAULT 0,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    pagada_en TEXT NOT NULL DEFAULT '',
    forma_pago TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_comisiones_vend ON comisiones(vendedor_id, estado);
SQL);
    $agregar('ligas', ['vendedor_id' => 'INTEGER REFERENCES vendedores(id) ON DELETE SET NULL']);
    $agregar('usuarios', ['vendedor_id' => 'INTEGER REFERENCES vendedores(id) ON DELETE CASCADE']);
}

/*
 * Versión 8: llaves eliminatorias automáticas, pagos con comprobante del delegado, liquidación de árbitros,
 * portal del jugador, asistencias y estadísticas, y reserva de canchas.
 */
function esquema_v8(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $pago_arb = ['pago_central' => 'REAL NOT NULL DEFAULT 0', 'pago_asistente' => 'REAL NOT NULL DEFAULT 0', 'pago_cuarto' => 'REAL NOT NULL DEFAULT 0'];
    $agregar('ligas', $pago_arb + ['reservas_activas' => 'INTEGER NOT NULL DEFAULT 0', 'reservas_texto' => "TEXT NOT NULL DEFAULT ''", 'reservas_whatsapp' => "TEXT NOT NULL DEFAULT ''", 'datos_pago' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('torneos', $pago_arb + ['llaves_tercer' => 'INTEGER NOT NULL DEFAULT 0']);
    $agregar('partidos', ['llave_pos' => 'INTEGER']);
    $agregar('jugadores', ['portal_pin' => "TEXT NOT NULL DEFAULT ''", 'portal_codigo' => "TEXT NOT NULL DEFAULT ''", 'portal_ultimo' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('canchas', ['reservable' => 'INTEGER NOT NULL DEFAULT 0', 'precio_hora' => 'REAL NOT NULL DEFAULT 0', 'abre' => "TEXT NOT NULL DEFAULT '08:00'",
        'cierra' => "TEXT NOT NULL DEFAULT '22:00'", 'duracion' => 'INTEGER NOT NULL DEFAULT 60']);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS comprobantes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    torneo_id INTEGER REFERENCES torneos(id) ON DELETE SET NULL,
    monto REAL NOT NULL DEFAULT 0,
    fecha TEXT NOT NULL DEFAULT '',
    forma_pago TEXT NOT NULL DEFAULT 'transferencia',
    referencia TEXT NOT NULL DEFAULT '',
    archivo TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    respuesta TEXT NOT NULL DEFAULT '',
    movimiento_id INTEGER REFERENCES movimientos(id) ON DELETE SET NULL,
    revisado_por TEXT NOT NULL DEFAULT '',
    revisado_en TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_comprob_liga ON comprobantes(liga_id, estado);
CREATE TABLE IF NOT EXISTS pagos_arbitro (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    arbitro_id INTEGER NOT NULL REFERENCES arbitros(id) ON DELETE CASCADE,
    monto REAL NOT NULL DEFAULT 0,
    partidos INTEGER NOT NULL DEFAULT 0,
    fecha TEXT NOT NULL DEFAULT '',
    forma_pago TEXT NOT NULL DEFAULT 'efectivo',
    nota TEXT NOT NULL DEFAULT '',
    movimiento_id INTEGER REFERENCES movimientos(id) ON DELETE SET NULL,
    anulado INTEGER NOT NULL DEFAULT 0,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS pagos_arbitro_partidos (
    pago_id INTEGER NOT NULL REFERENCES pagos_arbitro(id) ON DELETE CASCADE,
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    arbitro_id INTEGER NOT NULL REFERENCES arbitros(id) ON DELETE CASCADE,
    rol TEXT NOT NULL DEFAULT '',
    monto REAL NOT NULL DEFAULT 0,
    PRIMARY KEY (partido_id, arbitro_id)
);
CREATE TABLE IF NOT EXISTS reservas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    cancha_id INTEGER NOT NULL REFERENCES canchas(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    hora_inicio TEXT NOT NULL,
    hora_fin TEXT NOT NULL,
    cliente_nombre TEXT NOT NULL DEFAULT '',
    cliente_telefono TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    precio REAL NOT NULL DEFAULT 0,
    pagado REAL NOT NULL DEFAULT 0,
    origen TEXT NOT NULL DEFAULT 'panel',
    nota TEXT NOT NULL DEFAULT '',
    codigo TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_reservas_dia ON reservas(liga_id, fecha);
SQL);
}

/*
 * Versión 7: límites por liga (campeonatos y equipos), perfiles de usuarios de la liga, segundo color,
 * patrocinadores con reporte de vistas, votación del público, polla de pronósticos, otros deportes
 * (indor, ecuavóley y básquet: sets por partido) e importación desde Excel.
 */
function esquema_v7(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $reglas_v7 = [
        'sets_ganar' => 'INTEGER NOT NULL DEFAULT 2', 'puntos_set' => 'INTEGER NOT NULL DEFAULT 15',
        'puntos_set_final' => 'INTEGER NOT NULL DEFAULT 15', 'faltas_limite' => 'INTEGER NOT NULL DEFAULT 5',
    ];
    $agregar('ligas', $reglas_v7 + [
        'max_campeonatos' => 'INTEGER', 'max_equipos' => 'INTEGER', 'modo_campeonatos' => "TEXT NOT NULL DEFAULT ''",
        'color2' => "TEXT NOT NULL DEFAULT ''",
        'polla_activa' => 'INTEGER NOT NULL DEFAULT 0', 'polla_exacto' => 'INTEGER NOT NULL DEFAULT 3',
        'polla_resultado' => 'INTEGER NOT NULL DEFAULT 1', 'polla_premio' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $agregar('torneos', $reglas_v7);
    $agregar('usuarios', ['perfil' => "TEXT NOT NULL DEFAULT 'admin'"]);
    $agregar('partidos', ['patrocinador_id' => 'INTEGER REFERENCES patrocinadores(id) ON DELETE SET NULL']);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS patrocinadores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    logo TEXT NOT NULL DEFAULT '',
    banner TEXT NOT NULL DEFAULT '',
    enlace TEXT NOT NULL DEFAULT '',
    texto TEXT NOT NULL DEFAULT '',
    contacto TEXT NOT NULL DEFAULT '',
    en_pagina INTEGER NOT NULL DEFAULT 1,
    en_partidos INTEGER NOT NULL DEFAULT 1,
    en_imagenes INTEGER NOT NULL DEFAULT 0,
    en_carnets INTEGER NOT NULL DEFAULT 0,
    desde TEXT NOT NULL DEFAULT '',
    hasta TEXT NOT NULL DEFAULT '',
    valor REAL NOT NULL DEFAULT 0,
    activo INTEGER NOT NULL DEFAULT 1,
    orden INTEGER NOT NULL DEFAULT 0,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_patro_liga ON patrocinadores(liga_id);
CREATE TABLE IF NOT EXISTS patrocinio_vistas (
    patrocinador_id INTEGER NOT NULL REFERENCES patrocinadores(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL,
    lugar TEXT NOT NULL DEFAULT 'pagina',
    vistas INTEGER NOT NULL DEFAULT 0,
    clics INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (patrocinador_id, fecha, lugar)
);
CREATE TABLE IF NOT EXISTS partido_sets (
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    num INTEGER NOT NULL,
    puntos_local INTEGER NOT NULL DEFAULT 0,
    puntos_visitante INTEGER NOT NULL DEFAULT 0,
    cerrado INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (partido_id, num)
);
CREATE TABLE IF NOT EXISTS votaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    torneo_id INTEGER REFERENCES torneos(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL DEFAULT 'partido',
    partido_id INTEGER REFERENCES partidos(id) ON DELETE CASCADE,
    jornada INTEGER NOT NULL DEFAULT 0,
    titulo TEXT NOT NULL,
    premio TEXT NOT NULL DEFAULT '',
    patrocinador_id INTEGER REFERENCES patrocinadores(id) ON DELETE SET NULL,
    cierra TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'abierta',
    ganador_opcion_id INTEGER,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vot_liga ON votaciones(liga_id, estado);
CREATE TABLE IF NOT EXISTS votacion_opciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    votacion_id INTEGER NOT NULL REFERENCES votaciones(id) ON DELETE CASCADE,
    jugador_id INTEGER REFERENCES jugadores(id) ON DELETE CASCADE,
    evento_id INTEGER REFERENCES eventos(id) ON DELETE SET NULL,
    texto TEXT NOT NULL DEFAULT '',
    detalle TEXT NOT NULL DEFAULT '',
    orden INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_vopc ON votacion_opciones(votacion_id);
CREATE TABLE IF NOT EXISTS votos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    votacion_id INTEGER NOT NULL REFERENCES votaciones(id) ON DELETE CASCADE,
    opcion_id INTEGER NOT NULL REFERENCES votacion_opciones(id) ON DELETE CASCADE,
    huella TEXT NOT NULL,
    ip TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (votacion_id, huella)
);
CREATE INDEX IF NOT EXISTS idx_votos_ip ON votos(votacion_id, ip);
CREATE TABLE IF NOT EXISTS polla_participantes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    telefono TEXT NOT NULL,
    pin TEXT NOT NULL,
    token TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (liga_id, telefono)
);
CREATE TABLE IF NOT EXISTS polla_pronosticos (
    participante_id INTEGER NOT NULL REFERENCES polla_participantes(id) ON DELETE CASCADE,
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    goles_local INTEGER NOT NULL,
    goles_visitante INTEGER NOT NULL,
    actualizado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (participante_id, partido_id)
);
CREATE INDEX IF NOT EXISTS idx_polla_partido ON polla_pronosticos(partido_id);
SQL);
    // Texto de la página de venta: si nunca se cambió, se actualiza con los deportes nuevos
    $st = $pdo->prepare('UPDATE ajustes SET valor = ? WHERE clave = ? AND valor = ?');
    $st->execute([ajustes_por_defecto()['landing_subtitulo'], 'landing_subtitulo', 'Fixture, sorteo, tabla, goleadores, tarjetas, vocalía en vivo desde el celular, carnets con QR, árbitros, finanzas y página pública. Para fútbol 5, 7, 9 y 11.']);
}

/*
 * Versión 6: venta (planes, suscripciones, prueba gratis, dominio propio), categorías por edad,
 * pases e historial, programación automática (canchas), vocalía sin internet, Telegram,
 * protección de datos (LOPDP) y doble verificación.
 */
function esquema_v6(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
        }
    };
    $agregar('ligas', [
        'plan_id' => 'INTEGER', 'vence' => "TEXT NOT NULL DEFAULT ''", 'es_prueba' => 'INTEGER NOT NULL DEFAULT 0',
        'dominio' => "TEXT NOT NULL DEFAULT ''", 'contacto_nombre' => "TEXT NOT NULL DEFAULT ''", 'contacto_telefono' => "TEXT NOT NULL DEFAULT ''",
        'contacto_email' => "TEXT NOT NULL DEFAULT ''", 'costo_pase' => 'REAL NOT NULL DEFAULT 0', 'pases_delegados' => 'INTEGER NOT NULL DEFAULT 1',
        'tg_chat' => "TEXT NOT NULL DEFAULT ''", 'tg_chat_nombre' => "TEXT NOT NULL DEFAULT ''", 'tg_codigo' => "TEXT NOT NULL DEFAULT ''",
        'tg_opciones' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $agregar('torneos', [
        'categoria' => "TEXT NOT NULL DEFAULT ''", 'edad_min' => 'INTEGER NOT NULL DEFAULT 0', 'edad_max' => 'INTEGER NOT NULL DEFAULT 0',
        'edad_corte' => "TEXT NOT NULL DEFAULT ''", 'pasados_plantilla' => 'INTEGER NOT NULL DEFAULT 0', 'pasados_partido' => 'INTEGER NOT NULL DEFAULT 0',
        'pasados_max_anios' => 'INTEGER NOT NULL DEFAULT 0',
        'campeon_id' => 'INTEGER REFERENCES equipos(id) ON DELETE SET NULL', 'subcampeon_id' => 'INTEGER REFERENCES equipos(id) ON DELETE SET NULL',
    ]);
    $datos_personales = [
        'consentimiento' => "TEXT NOT NULL DEFAULT ''", 'consentimiento_por' => "TEXT NOT NULL DEFAULT ''",
        'representante_nombre' => "TEXT NOT NULL DEFAULT ''", 'representante_cedula' => "TEXT NOT NULL DEFAULT ''",
        'representante_telefono' => "TEXT NOT NULL DEFAULT ''", 'foto_publica' => 'INTEGER NOT NULL DEFAULT 1',
    ];
    $agregar('jugadores', $datos_personales + ['anonimizado' => 'INTEGER NOT NULL DEFAULT 0']);
    $agregar('solicitudes_jugador', $datos_personales);
    $agregar('usuarios', [
        'totp_secreto' => "TEXT NOT NULL DEFAULT ''", 'totp_activo' => 'INTEGER NOT NULL DEFAULT 0', 'totp_respaldo' => "TEXT NOT NULL DEFAULT ''",
        'tg_chat' => "TEXT NOT NULL DEFAULT ''", 'tg_codigo' => "TEXT NOT NULL DEFAULT ''", 'ultimo_ingreso' => "TEXT NOT NULL DEFAULT ''",
    ]);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ajustes (
    clave TEXT PRIMARY KEY,
    valor TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS planes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    precio_mensual REAL NOT NULL DEFAULT 0,
    precio_anual REAL NOT NULL DEFAULT 0,
    max_equipos INTEGER NOT NULL DEFAULT 0,
    max_campeonatos INTEGER NOT NULL DEFAULT 0,
    descripcion TEXT NOT NULL DEFAULT '',
    destacado INTEGER NOT NULL DEFAULT 0,
    activo INTEGER NOT NULL DEFAULT 1,
    orden INTEGER NOT NULL DEFAULT 0,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS pagos_suscripcion (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    plan_id INTEGER REFERENCES planes(id) ON DELETE SET NULL,
    monto REAL NOT NULL DEFAULT 0,
    meses INTEGER NOT NULL DEFAULT 1,
    desde TEXT NOT NULL DEFAULT '',
    hasta TEXT NOT NULL DEFAULT '',
    forma_pago TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pagsus_liga ON pagos_suscripcion(liga_id);
CREATE TABLE IF NOT EXISTS canchas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    direccion TEXT NOT NULL DEFAULT '',
    activa INTEGER NOT NULL DEFAULT 1,
    orden INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_canchas_liga ON canchas(liga_id);
CREATE TABLE IF NOT EXISTS pases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    jugador_id INTEGER NOT NULL REFERENCES jugadores(id) ON DELETE CASCADE,
    origen_id INTEGER REFERENCES equipos(id) ON DELETE SET NULL,
    destino_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    motivo TEXT NOT NULL DEFAULT '',
    respuesta TEXT NOT NULL DEFAULT '',
    costo REAL NOT NULL DEFAULT 0,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    solicitado_por TEXT NOT NULL DEFAULT '',
    revisado_por TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pases_liga ON pases(liga_id, estado);
CREATE INDEX IF NOT EXISTS idx_pases_jugador ON pases(jugador_id);
CREATE TABLE IF NOT EXISTS telegram_cola (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER,
    chat_id TEXT NOT NULL,
    texto TEXT NOT NULL,
    foto_url TEXT NOT NULL DEFAULT '',
    clave TEXT,
    intentos INTEGER NOT NULL DEFAULT 0,
    enviado TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tg_clave ON telegram_cola(clave) WHERE clave IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_tg_pend ON telegram_cola(enviado, intentos);
CREATE TABLE IF NOT EXISTS acciones_offline (
    cid TEXT PRIMARY KEY,
    partido_id INTEGER NOT NULL,
    accion TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS intentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL,
    ip TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_intentos ON intentos(tipo, ip, creado);
SQL);
    // Datos iniciales: ajustes y planes de ejemplo (el superadministrador los cambia)
    $st = $pdo->prepare('INSERT OR IGNORE INTO ajustes (clave, valor) VALUES (?, ?)');
    foreach (ajustes_por_defecto() as $k => $v) {
        $st->execute([$k, (string)$v]);
    }
    $st->execute(['tg_secreto', bin2hex(random_bytes(12))]);
    if ((int)$pdo->query('SELECT COUNT(*) FROM planes')->fetchColumn() === 0) {
        $pl = $pdo->prepare('INSERT INTO planes (nombre, precio_mensual, precio_anual, max_equipos, max_campeonatos, descripcion, destacado, orden) VALUES (?,?,?,?,?,?,?,?)');
        $pl->execute(['Básico', 10, 100, 12, 2, "Hasta 12 equipos\n2 campeonatos a la vez\nFixture, sorteo y tabla automáticos\nVocalía en vivo desde el celular\nCarnets con QR\nPágina pública de la liga", 0, 1]);
        $pl->execute(['Profesional', 15, 150, 30, 0, "Hasta 30 equipos\nCampeonatos ilimitados\nTodo lo del plan Básico\nÁrbitros con informe y calificación\nFinanzas y recibos\nImágenes para redes sociales\nAvisos por Telegram", 1, 2]);
        $pl->execute(['Liga Grande', 25, 250, 0, 0, "Equipos ilimitados\nVarias categorías (Sub-12, Máster...)\nTodo lo del plan Profesional\nDominio propio (www.suliga.com)\nSoporte prioritario por WhatsApp", 0, 3]);
    }
}

/** Valores iniciales de la configuración general del sistema (los cambia el superadministrador). */
function ajustes_por_defecto(): array
{
    return [
        'marca' => 'TuAcademia',
        'empresa' => 'Tukán Software',
        'dias_prueba' => 30,
        'dias_gracia' => 7,
        'dias_gracia_demo' => 0, // (v33) la demo se bloquea al vencer
        'url_sitio' => '', // (v33) dirección pública recordada (para correos y avisos desde la tarea automática)
        'registro_abierto' => 0,
        'ventas_whatsapp' => '',
        'ventas_email' => '',
        'landing_titulo' => 'El sistema completo para administrar tu liga de fútbol',
        'landing_subtitulo' => 'Fixture, sorteo, tabla, goleadores, tarjetas, vocalía en vivo desde el celular, carnets con QR, árbitros, finanzas, patrocinadores y página pública. Para fútbol 5, 7, 9 y 11, indor, ecuavóley y básquet.',
        'responsable_nombre' => 'QF Producciones',
        'responsable_email' => '',
        'exigir_2fa' => 0,
        'tg_token' => '',
        'tg_usuario' => '',
        // (v10) datos para que las ligas paguen su suscripción (aviso de vencimiento)
        'ventas_datos_pago' => '',
        // (v10) correo saliente (SMTP) para enviar códigos de "Olvidé mi contraseña"
        'smtp_host' => '', 'smtp_puerto' => '587', 'smtp_seguridad' => 'tls', 'smtp_usuario' => '', 'smtp_clave' => '', 'smtp_remitente' => '',
        // (v10) liga de demostración
        'demo_activa' => 0,
        // (v12) cobros con tarjeta: 'apagado' | 'prueba' (solo el superadministrador completa pagos simulados) | 'plux'
        'pasarela_modo' => 'apagado', 'comision_credito' => '4.02', 'comision_debito' => '2.00', 'iva_pct' => '15', 'comision_tuvocalia' => '1.00',
        'plux_ambiente' => 'sandbox', 'plux_id_establecimiento' => '', 'plux_id_cliente' => '', 'plux_clave_secreta' => '', 'plux_llave_publica' => '',
        // (v13) dirección de producción de Plux y si el cobro va con IVA
        'plux_url_produccion' => '', 'plux_iva_suscripcion' => '1', 'plux_iva_cobros' => '0',
        // (v16) combo liga + escuela (% de descuento en los dos planes) y nota del dominio gratis anual
        'combo_descuento' => '30',
        'nota_dominio' => 'El dominio gratis se da al pagar el plan por 1 año. El dominio es propiedad de tupass.com; la organización lo usa mientras mantenga su plan al día.',
    ];
}

/* Versión 5: bitácora de auditoría, delegados e inscripción en línea */
function esquema_v5(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('equipo_id', $cols('usuarios'), true)) {
        $pdo->exec('ALTER TABLE usuarios ADD COLUMN equipo_id INTEGER REFERENCES equipos(id) ON DELETE CASCADE');
    }
    foreach (['jugadores_hasta' => "TEXT NOT NULL DEFAULT ''", 'equipos_hasta' => "TEXT NOT NULL DEFAULT ''"] as $c => $def) {
        if (!in_array($c, $cols('torneos'), true)) $pdo->exec("ALTER TABLE torneos ADD COLUMN $c $def");
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS bitacora (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fecha TEXT NOT NULL DEFAULT (datetime('now')),
    usuario_id INTEGER,
    usuario TEXT NOT NULL DEFAULT '',
    rol TEXT NOT NULL DEFAULT '',
    liga_id INTEGER,
    ip TEXT NOT NULL DEFAULT '',
    origen TEXT NOT NULL DEFAULT '',
    tabla TEXT NOT NULL,
    operacion TEXT NOT NULL,
    registro_id INTEGER,
    antes TEXT,
    despues TEXT,
    descripcion TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_bit_fecha ON bitacora(fecha);
CREATE INDEX IF NOT EXISTS idx_bit_liga ON bitacora(liga_id, fecha);
CREATE INDEX IF NOT EXISTS idx_bit_tabla ON bitacora(tabla, registro_id);
CREATE INDEX IF NOT EXISTS idx_bit_usuario ON bitacora(usuario_id);
-- La bitácora no se puede modificar ni borrar desde el sistema
CREATE TRIGGER IF NOT EXISTS bitacora_no_modificar BEFORE UPDATE ON bitacora BEGIN SELECT RAISE(ABORT, 'La bitácora no se puede modificar'); END;
CREATE TRIGGER IF NOT EXISTS bitacora_no_borrar BEFORE DELETE ON bitacora BEGIN SELECT RAISE(ABORT, 'La bitácora no se puede borrar'); END;

CREATE TABLE IF NOT EXISTS solicitudes_jugador (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    tipo TEXT NOT NULL DEFAULT 'alta',
    jugador_id INTEGER REFERENCES jugadores(id) ON DELETE SET NULL,
    nombres TEXT NOT NULL DEFAULT '',
    apellidos TEXT NOT NULL DEFAULT '',
    cedula TEXT NOT NULL DEFAULT '',
    numero INTEGER,
    posicion TEXT NOT NULL DEFAULT '',
    fecha_nac TEXT NOT NULL DEFAULT '',
    foto TEXT NOT NULL DEFAULT '',
    motivo TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    respuesta TEXT NOT NULL DEFAULT '',
    revisado_por TEXT NOT NULL DEFAULT '',
    revisado_en TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_solj_equipo ON solicitudes_jugador(equipo_id, estado);

CREATE TABLE IF NOT EXISTS solicitudes_equipo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    torneo_id INTEGER REFERENCES torneos(id) ON DELETE SET NULL,
    nombre TEXT NOT NULL,
    abreviatura TEXT NOT NULL DEFAULT '',
    escudo TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#333333',
    delegado TEXT NOT NULL DEFAULT '',
    cedula TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    clave TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    respuesta TEXT NOT NULL DEFAULT '',
    equipo_id INTEGER REFERENCES equipos(id) ON DELETE SET NULL,
    revisado_por TEXT NOT NULL DEFAULT '',
    revisado_en TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sole_liga ON solicitudes_equipo(liga_id, estado);
SQL);
}

/** Reglas de dinero (por campeonato; la liga guarda los valores por defecto). */
function columnas_finanzas(): array
{
    return [
        'cuota_inscripcion' => 'REAL NOT NULL DEFAULT 0',
        'arbitraje_por_equipo' => 'REAL NOT NULL DEFAULT 0',
        'bloqueo_deuda' => "TEXT NOT NULL DEFAULT 'no'",
        'deuda_maxima' => 'REAL NOT NULL DEFAULT 0',
        // v8: lo que la liga paga a cada árbitro por partido, según su función
        'pago_central' => 'REAL NOT NULL DEFAULT 0',
        'pago_asistente' => 'REAL NOT NULL DEFAULT 0',
        'pago_cuarto' => 'REAL NOT NULL DEFAULT 0',
    ];
}

/* Versión 4: carnet con QR, finanzas, imágenes para redes */
function esquema_v4(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('carnet_token', $cols('jugadores'), true)) {
        $pdo->exec("ALTER TABLE jugadores ADD COLUMN carnet_token TEXT NOT NULL DEFAULT ''");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_jugadores_carnet ON jugadores(carnet_token) WHERE carnet_token <> ''");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jugadores_cedula ON jugadores(cedula)');
    foreach (['ligas', 'torneos'] as $t) {
        $ya = $cols($t);
        foreach (columnas_finanzas() as $c => $def) {
            if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def");
        }
    }
    $ya = $cols('ligas');
    foreach (['patrocinador_logo' => "TEXT NOT NULL DEFAULT ''", 'patrocinador_nombre' => "TEXT NOT NULL DEFAULT ''", 'recibo_siguiente' => 'INTEGER NOT NULL DEFAULT 1'] as $c => $def) {
        if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE ligas ADD COLUMN $c $def");
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS movimientos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    torneo_id INTEGER REFERENCES torneos(id) ON DELETE SET NULL,
    equipo_id INTEGER REFERENCES equipos(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL,
    concepto TEXT NOT NULL DEFAULT 'otro',
    descripcion TEXT NOT NULL DEFAULT '',
    monto REAL NOT NULL,
    fecha TEXT NOT NULL,
    forma_pago TEXT NOT NULL DEFAULT '',
    comprobante TEXT NOT NULL DEFAULT '',
    recibo INTEGER,
    ref TEXT,
    anulado INTEGER NOT NULL DEFAULT 0,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_mov_ref ON movimientos(ref) WHERE ref IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_mov_liga ON movimientos(liga_id);
CREATE INDEX IF NOT EXISTS idx_mov_equipo ON movimientos(equipo_id);
CREATE TABLE IF NOT EXISTS figuras (
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    jornada INTEGER NOT NULL,
    jugador_id INTEGER NOT NULL REFERENCES jugadores(id) ON DELETE CASCADE,
    motivo TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (torneo_id, jornada)
);
SQL);
}

/** Reglas que se definen por campeonato (la liga guarda los valores por defecto). */
function columnas_reglas(): array
{
    return [
        'modalidad' => "TEXT NOT NULL DEFAULT 'F7'",
        'jugadores_cancha' => 'INTEGER NOT NULL DEFAULT 7',
        'min_para_jugar' => 'INTEGER NOT NULL DEFAULT 5',
        'max_suplentes' => 'INTEGER NOT NULL DEFAULT 7',
        'max_cambios' => 'INTEGER NOT NULL DEFAULT 0',
        'num_tiempos' => 'INTEGER NOT NULL DEFAULT 2',
        'minutos_tiempo' => 'INTEGER NOT NULL DEFAULT 25',
        'min_plantilla' => 'INTEGER NOT NULL DEFAULT 9',
        'max_plantilla' => 'INTEGER NOT NULL DEFAULT 18',
        'puntos_ganado' => 'INTEGER NOT NULL DEFAULT 3',
        'puntos_empate' => 'INTEGER NOT NULL DEFAULT 1',
        'puntos_perdido' => 'INTEGER NOT NULL DEFAULT 0',
        'amarillas_suspension' => 'INTEGER NOT NULL DEFAULT 3',
        'partidos_susp_amarillas' => 'INTEGER NOT NULL DEFAULT 1',
        'partidos_susp_roja' => 'INTEGER NOT NULL DEFAULT 1',
        'doble_amarilla_roja' => 'INTEGER NOT NULL DEFAULT 1',
        'goles_wo' => 'INTEGER NOT NULL DEFAULT 3',
        'num_arbitros' => 'INTEGER NOT NULL DEFAULT 1',
        'num_arbitros_final' => 'INTEGER NOT NULL DEFAULT 0',
        'calificar_arbitro' => "TEXT NOT NULL DEFAULT 'no'",
        // v7: ecuavóley (sets) y básquet (faltas)
        'sets_ganar' => 'INTEGER NOT NULL DEFAULT 2',
        'puntos_set' => 'INTEGER NOT NULL DEFAULT 15',
        'puntos_set_final' => 'INTEGER NOT NULL DEFAULT 15',
        'faltas_limite' => 'INTEGER NOT NULL DEFAULT 5',
    ];
}

/* Versión 3: reglas por campeonato, hasta 4 árbitros, calificación del árbitro */
function esquema_v3(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $en_ligas = $cols('ligas');
    foreach (['min_para_jugar' => 'INTEGER NOT NULL DEFAULT 5', 'num_arbitros' => 'INTEGER NOT NULL DEFAULT 1', 'num_arbitros_final' => 'INTEGER NOT NULL DEFAULT 0', 'calificar_arbitro' => "TEXT NOT NULL DEFAULT 'no'"] as $c => $def) {
        if (!in_array($c, $en_ligas, true)) $pdo->exec("ALTER TABLE ligas ADD COLUMN $c $def");
    }
    $en_torneos = $cols('torneos');
    // (las reglas agregadas en la versión 7 se crean en esquema_v7)
    foreach (array_diff_key(columnas_reglas(), array_flip(['sets_ganar', 'puntos_set', 'puntos_set_final', 'faltas_limite'])) as $c => $def) {
        if (!in_array($c, $en_torneos, true)) {
            $pdo->exec("ALTER TABLE torneos ADD COLUMN $c $def");
            // Los campeonatos existentes toman las reglas que tenía su liga
            $pdo->exec("UPDATE torneos SET $c = (SELECT l.$c FROM ligas l WHERE l.id = torneos.liga_id)");
        }
    }
    if (!in_array('cuarto_id', $cols('partidos'), true)) {
        $pdo->exec('ALTER TABLE partidos ADD COLUMN cuarto_id INTEGER REFERENCES arbitros(id) ON DELETE SET NULL');
    }
    // Número de árbitros propio de un partido (NULL = lo que diga el campeonato)
    if (!in_array('num_arbitros', $cols('partidos'), true)) {
        $pdo->exec('ALTER TABLE partidos ADD COLUMN num_arbitros INTEGER');
    }
    // Si ya había asistentes asignados, el campeonato conserva ese número de árbitros
    $pdo->exec("UPDATE torneos SET num_arbitros = MAX(num_arbitros,
        CASE WHEN EXISTS (SELECT 1 FROM partidos p WHERE p.torneo_id = torneos.id AND p.asistente2_id IS NOT NULL) THEN 3
             WHEN EXISTS (SELECT 1 FROM partidos p WHERE p.torneo_id = torneos.id AND p.asistente1_id IS NOT NULL) THEN 2 ELSE 1 END)");
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS calificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    arbitro_id INTEGER NOT NULL REFERENCES arbitros(id) ON DELETE CASCADE,
    nota INTEGER NOT NULL,
    puntualidad INTEGER,
    presentacion INTEGER,
    criterio INTEGER,
    control INTEGER,
    comentario TEXT NOT NULL DEFAULT '',
    calificado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (partido_id, arbitro_id)
);
CREATE INDEX IF NOT EXISTS idx_calif_arbitro ON calificaciones(arbitro_id);
SQL);
}

/* Versión 2: árbitros, vocalía en vivo, informes, fotos internas, sanciones a equipos y multas */
function esquema_v2(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, string $col, string $def) use ($pdo, $cols) {
        if (!in_array($col, $cols($tabla), true)) {
            $pdo->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
        }
    };

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS arbitros (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    cedula TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    categoria TEXT NOT NULL DEFAULT '',
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS vocales_partido (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'directivo',
    equipo_id INTEGER REFERENCES equipos(id) ON DELETE SET NULL,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS alineaciones (
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    jugador_id INTEGER NOT NULL REFERENCES jugadores(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    PRIMARY KEY (partido_id, jugador_id)
);
CREATE TABLE IF NOT EXISTS informes (
    partido_id INTEGER PRIMARY KEY REFERENCES partidos(id) ON DELETE CASCADE,
    arbitro_id INTEGER REFERENCES arbitros(id) ON DELETE SET NULL,
    hora_inicio TEXT NOT NULL DEFAULT '',
    hora_fin TEXT NOT NULL DEFAULT '',
    goles_local INTEGER,
    goles_visitante INTEGER,
    incidentes TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'borrador',
    enviado_en TEXT NOT NULL DEFAULT '',
    actualizado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS informe_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    jugador_id INTEGER NOT NULL REFERENCES jugadores(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL,
    minuto INTEGER,
    nota TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS fotos_partido (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    archivo TEXT NOT NULL,
    descripcion TEXT NOT NULL DEFAULT '',
    subido_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS sanciones_equipo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    puntos INTEGER NOT NULL DEFAULT 0,
    multa REAL NOT NULL DEFAULT 0,
    pagada INTEGER NOT NULL DEFAULT 0,
    motivo TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_arbitros_liga ON arbitros(liga_id);
CREATE INDEX IF NOT EXISTS idx_vocales_partido ON vocales_partido(partido_id);
CREATE INDEX IF NOT EXISTS idx_inf_ev_partido ON informe_eventos(partido_id);
CREATE INDEX IF NOT EXISTS idx_fotos_partido ON fotos_partido(partido_id);
CREATE INDEX IF NOT EXISTS idx_sanc_eq_torneo ON sanciones_equipo(torneo_id);
SQL);

    $agregar('partidos', 'arbitro_id', 'INTEGER REFERENCES arbitros(id) ON DELETE SET NULL');
    $agregar('partidos', 'asistente1_id', 'INTEGER REFERENCES arbitros(id) ON DELETE SET NULL');
    $agregar('partidos', 'asistente2_id', 'INTEGER REFERENCES arbitros(id) ON DELETE SET NULL');
    $agregar('partidos', 'hora_inicio', "TEXT NOT NULL DEFAULT ''");
    $agregar('partidos', 'hora_fin', "TEXT NOT NULL DEFAULT ''");
    $agregar('partidos', 'vivo_periodo', 'INTEGER NOT NULL DEFAULT 0');
    $agregar('partidos', 'vivo_desde', 'INTEGER NOT NULL DEFAULT 0');
    $agregar('partidos', 'vivo_pausa', 'INTEGER NOT NULL DEFAULT 0');
    $agregar('partidos', 'vocal_token', "TEXT NOT NULL DEFAULT ''");
    $agregar('partidos', 'vocal_obs', "TEXT NOT NULL DEFAULT ''");
    $agregar('partidos', 'vocal_cerrada', 'INTEGER NOT NULL DEFAULT 0');
    $agregar('partidos', 'vocal_cerrada_en', "TEXT NOT NULL DEFAULT ''");
    $agregar('partidos', 'revisado', 'INTEGER NOT NULL DEFAULT 0');
    $agregar('eventos', 'nota', "TEXT NOT NULL DEFAULT ''");
    $agregar('eventos', 'origen', "TEXT NOT NULL DEFAULT 'admin'");
    $agregar('eventos', 'registrado', "TEXT NOT NULL DEFAULT ''");
    $agregar('sanciones', 'multa', 'REAL NOT NULL DEFAULT 0');
    $agregar('sanciones', 'pagada', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_partidos_arbitro ON partidos(arbitro_id)');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_partidos_token ON partidos(vocal_token) WHERE vocal_token <> ''");
}

function esquema_v1(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ligas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    ciudad TEXT NOT NULL DEFAULT '',
    logo TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#0b6e4f',
    modalidad TEXT NOT NULL DEFAULT 'F7',
    jugadores_cancha INTEGER NOT NULL DEFAULT 7,
    max_suplentes INTEGER NOT NULL DEFAULT 7,
    max_cambios INTEGER NOT NULL DEFAULT 0,
    num_tiempos INTEGER NOT NULL DEFAULT 2,
    minutos_tiempo INTEGER NOT NULL DEFAULT 25,
    min_plantilla INTEGER NOT NULL DEFAULT 9,
    max_plantilla INTEGER NOT NULL DEFAULT 18,
    puntos_ganado INTEGER NOT NULL DEFAULT 3,
    puntos_empate INTEGER NOT NULL DEFAULT 1,
    puntos_perdido INTEGER NOT NULL DEFAULT 0,
    amarillas_suspension INTEGER NOT NULL DEFAULT 3,
    partidos_susp_amarillas INTEGER NOT NULL DEFAULT 1,
    partidos_susp_roja INTEGER NOT NULL DEFAULT 1,
    doble_amarilla_roja INTEGER NOT NULL DEFAULT 1,
    goles_wo INTEGER NOT NULL DEFAULT 3,
    activa INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    clave TEXT NOT NULL,
    rol TEXT NOT NULL DEFAULT 'liga',
    liga_id INTEGER REFERENCES ligas(id) ON DELETE CASCADE,
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS equipos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    abreviatura TEXT NOT NULL DEFAULT '',
    escudo TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#333333',
    delegado TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS jugadores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    nombres TEXT NOT NULL,
    apellidos TEXT NOT NULL DEFAULT '',
    cedula TEXT NOT NULL DEFAULT '',
    numero INTEGER,
    posicion TEXT NOT NULL DEFAULT '',
    fecha_nac TEXT NOT NULL DEFAULT '',
    foto TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS torneos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    temporada TEXT NOT NULL DEFAULT '',
    formato TEXT NOT NULL DEFAULT 'liga',
    num_grupos INTEGER NOT NULL DEFAULT 1,
    vueltas INTEGER NOT NULL DEFAULT 1,
    estado TEXT NOT NULL DEFAULT 'inscripcion',
    fecha_sorteo TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS torneo_equipos (
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    grupo TEXT NOT NULL DEFAULT '',
    orden_sorteo INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (torneo_id, equipo_id)
);

CREATE TABLE IF NOT EXISTS partidos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    fase TEXT NOT NULL DEFAULT 'regular',
    grupo TEXT NOT NULL DEFAULT '',
    jornada INTEGER NOT NULL DEFAULT 0,
    local_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    visitante_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    fecha TEXT NOT NULL DEFAULT '',
    hora TEXT NOT NULL DEFAULT '',
    cancha TEXT NOT NULL DEFAULT '',
    arbitro TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'programado',
    goles_local INTEGER,
    goles_visitante INTEGER,
    penales_local INTEGER,
    penales_visitante INTEGER,
    wo_ganador INTEGER,
    observaciones TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partido_id INTEGER NOT NULL REFERENCES partidos(id) ON DELETE CASCADE,
    jugador_id INTEGER NOT NULL REFERENCES jugadores(id) ON DELETE CASCADE,
    equipo_id INTEGER NOT NULL REFERENCES equipos(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL,
    minuto INTEGER
);

CREATE TABLE IF NOT EXISTS sanciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    jugador_id INTEGER NOT NULL REFERENCES jugadores(id) ON DELETE CASCADE,
    partidos INTEGER NOT NULL DEFAULT 1,
    motivo TEXT NOT NULL DEFAULT '',
    desde TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_equipos_liga ON equipos(liga_id);
CREATE INDEX IF NOT EXISTS idx_jugadores_equipo ON jugadores(equipo_id);
CREATE INDEX IF NOT EXISTS idx_torneos_liga ON torneos(liga_id);
CREATE INDEX IF NOT EXISTS idx_partidos_torneo ON partidos(torneo_id);
CREATE INDEX IF NOT EXISTS idx_eventos_partido ON eventos(partido_id);
CREATE INDEX IF NOT EXISTS idx_eventos_jugador ON eventos(jugador_id);
CREATE INDEX IF NOT EXISTS idx_sanciones_torneo ON sanciones(torneo_id);

PRAGMA user_version = 1;
SQL);
}


/**
 * (v26) NATACIÓN y ATLETISMO (competencias por pruebas), INSCRIPCIÓN EN LÍNEA de deportes individuales
 */
function esquema_v23(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS prueba_eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    num INTEGER NOT NULL DEFAULT 1,
    nombre TEXT NOT NULL,
    medida TEXT NOT NULL DEFAULT 'tiempo',
    rama TEXT NOT NULL DEFAULT '',
    edad_min INTEGER NOT NULL DEFAULT 0,
    edad_max INTEGER NOT NULL DEFAULT 0,
    categoria TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    hora TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_prueba_ev_torneo ON prueba_eventos(torneo_id, num);
CREATE TABLE IF NOT EXISTS prueba_participantes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    numero TEXT NOT NULL DEFAULT '',
    nombre TEXT NOT NULL,
    club TEXT NOT NULL DEFAULT '',
    ciudad TEXT NOT NULL DEFAULT '',
    rama TEXT NOT NULL DEFAULT '',
    nacimiento TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_prueba_part ON prueba_participantes(torneo_id);
CREATE TABLE IF NOT EXISTS prueba_resultados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES prueba_eventos(id) ON DELETE CASCADE,
    participante_id INTEGER NOT NULL REFERENCES prueba_participantes(id) ON DELETE CASCADE,
    serie INTEGER NOT NULL DEFAULT 0,
    carril INTEGER NOT NULL DEFAULT 0,
    marca REAL,
    estado TEXT NOT NULL DEFAULT 'ok',
    nota TEXT NOT NULL DEFAULT '',
    UNIQUE (evento_id, participante_id)
);
CREATE INDEX IF NOT EXISTS idx_prueba_res_part ON prueba_resultados(participante_id);
CREATE TABLE IF NOT EXISTS inscripciones_indiv (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    torneo_id INTEGER NOT NULL REFERENCES torneos(id) ON DELETE CASCADE,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    pareja TEXT NOT NULL DEFAULT '',
    cedula TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    club TEXT NOT NULL DEFAULT '',
    ciudad TEXT NOT NULL DEFAULT '',
    rama TEXT NOT NULL DEFAULT '',
    nacimiento TEXT NOT NULL DEFAULT '',
    peso REAL,
    grado TEXT NOT NULL DEFAULT '',
    categoria_id INTEGER,
    extra TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    motivo TEXT NOT NULL DEFAULT '',
    pago_token TEXT NOT NULL DEFAULT '',
    pagado TEXT NOT NULL DEFAULT '',
    participante_id INTEGER,
    ip TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revisado_por TEXT NOT NULL DEFAULT '',
    revisado_en TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_insc_indiv ON inscripciones_indiv(torneo_id, estado);
SQL);
    $agregar('torneos', [
        'insc_indiv_hasta' => "TEXT NOT NULL DEFAULT ''", 'insc_indiv_valor' => 'REAL NOT NULL DEFAULT 0', 'insc_indiv_info' => "TEXT NOT NULL DEFAULT ''",
    ]);
}

/*
 * Versión 24 (paquete v27, 1-oct-2026):
 *  - SOPORTE: soporte_tickets y soporte_mensajes (tickets, pedidos de funcionalidad, conversación con el superadministrador).
 *  - NOVEDADES del sistema (avisos de cada versión) y usuarios.novedad_vista.
 *  - Seguimiento de demos: ligas.demo_avisos (qué avisos automáticos ya se enviaron).
 *  - La demo por solicitud dura 7 días.
 */
function esquema_v24(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS soporte_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER REFERENCES ligas(id) ON DELETE SET NULL,
    usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
    autor TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    tipo TEXT NOT NULL DEFAULT 'problema',
    asunto TEXT NOT NULL DEFAULT '',
    pagina TEXT NOT NULL DEFAULT '',
    prioridad TEXT NOT NULL DEFAULT 'normal',
    estado TEXT NOT NULL DEFAULT 'nuevo',
    detalle TEXT NOT NULL DEFAULT '',
    sin_leer_super INTEGER NOT NULL DEFAULT 1,
    sin_leer_cliente INTEGER NOT NULL DEFAULT 0,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_en TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_tickets_liga ON soporte_tickets(liga_id, estado);
CREATE INDEX IF NOT EXISTS idx_tickets_estado ON soporte_tickets(estado, actualizado);
CREATE TABLE IF NOT EXISTS soporte_mensajes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL REFERENCES soporte_tickets(id) ON DELETE CASCADE,
    de TEXT NOT NULL DEFAULT 'cliente',
    autor TEXT NOT NULL DEFAULT '',
    usuario_id INTEGER,
    texto TEXT NOT NULL DEFAULT '',
    adjunto TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mensajes_ticket ON soporte_mensajes(ticket_id);
CREATE TABLE IF NOT EXISTS novedades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    titulo TEXT NOT NULL,
    texto TEXT NOT NULL DEFAULT '',
    para TEXT NOT NULL DEFAULT 'todos',
    enlace TEXT NOT NULL DEFAULT '',
    version TEXT NOT NULL DEFAULT '',
    publicada INTEGER NOT NULL DEFAULT 1,
    fecha TEXT NOT NULL DEFAULT ''
);
SQL);
    $agregar('usuarios', ['novedad_vista' => 'INTEGER NOT NULL DEFAULT 0']);
    $agregar('ligas', ['demo_avisos' => "TEXT NOT NULL DEFAULT ''"]);
    $pdo->exec("INSERT OR REPLACE INTO ajustes (clave, valor) VALUES ('dias_demo', '7')");
    // Novedades de las últimas versiones (los usuarios que ya existen las ven como nuevas una sola vez)
    if ((int)$pdo->query('SELECT COUNT(*) FROM novedades')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) VALUES (?,?,?,?,?,?)');
        foreach ([
            ['🏐 Vóley, 🏊 natación y 🏃 atletismo', 'Nuevos deportes: vóley 6×6 como deporte de equipo, y campeonatos de natación y atletismo con programa de pruebas, tiempos, medallero y puntos por club.', 'ligas', 'manual/ligas#s25', 'v26', '2026-09-30'],
            ['📝 Inscripción en línea para deportes individuales', 'Los competidores se inscriben solos (y pueden pagar con tarjeta). Además: imágenes para redes, hojas para imprimir, resultados en vivo y copiar para la nueva temporada.', 'ligas', 'manual/ligas#s26', 'v26', '2026-09-30'],
            ['🎾 Pádel, 🏓 tenis de mesa, 🎳 bolos y 🚴 ciclismo en escuelas', 'Cree grupos de estos deportes con sus pruebas físicas, habilidades y ejercicios de fábrica.', 'escuelas', 'manual/escuelas#s21', 'v26', '2026-09-30'],
            ['📘 Manuales en la web y botón de Ayuda', 'Toque el botón ❓ Ayuda (abajo a la derecha) en cualquier pantalla: se abre la parte del manual que explica esa pantalla. También puede descargar el manual en PDF.', 'todos', 'manual', 'v27', '2026-10-01'],
            ['🎫 Soporte y pedidos de funcionalidades', 'Desde el botón ❓ Ayuda puede abrir un ticket de soporte o pedirnos una funcionalidad nueva. Le respondemos ahí mismo.', 'todos', 'admin.php?p=soporte', 'v27', '2026-10-01'],
            ['📥 Subida masiva desde Excel', 'Descargue las plantillas y suba de una vez equipos, jugadores, árbitros, competidores, sedes, profesores, grupos y alumnos (planes completos).', 'todos', 'admin.php?p=cargas', 'v27', '2026-10-01'],
        ] as $n) $ins->execute($n);
    }
}



/*
 * Versión 25 (paquete v28, oct-2026):
 *  - FEDERACIONES: ligas.es_federacion, ligas.federacion_id/federacion_estado/fed_codigo, equipos.origen_id y fed_sanciones.
 *  - VIDEOS del jugador / alumno (máx. 2 min, se borran a los 10 días): videos.
 *  - ENTRADAS con QR (opcional por liga): bol_ventas, bol_localidades, bol_ordenes, bol_items, bol_tickets, bol_escaneos y ligas.entradas_*.
 *  - IDIOMAS: ligas.idioma (página pública) y usuarios.idioma.
 *  - REFERIDOS (recomiende y gane): referidos, ligas.ref_codigo/referido_por, solicitudes_demo.recomendado_por.
 */
function esquema_v25(PDO $pdo): void
{
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $agregar = function (string $tabla, array $nuevas) use ($pdo, $cols) {
        $ya = $cols($tabla);
        foreach ($nuevas as $c => $def) if (!in_array($c, $ya, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $c $def");
    };
    $agregar('ligas', ['es_federacion' => 'INTEGER NOT NULL DEFAULT 0', 'federacion_id' => 'INTEGER', 'federacion_estado' => "TEXT NOT NULL DEFAULT ''", 'fed_codigo' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('equipos', ['origen_id' => 'INTEGER']);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS fed_sanciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    federacion_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    cedula TEXT NOT NULL,
    nombre TEXT NOT NULL DEFAULT '',
    motivo TEXT NOT NULL DEFAULT '',
    desde TEXT NOT NULL DEFAULT '',
    hasta TEXT NOT NULL DEFAULT '',
    anulada INTEGER NOT NULL DEFAULT 0,
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fed_sanciones ON fed_sanciones(federacion_id, cedula);
CREATE INDEX IF NOT EXISTS idx_ligas_federacion ON ligas(federacion_id);
CREATE TABLE IF NOT EXISTS videos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL DEFAULT 'jugador',
    persona_id INTEGER NOT NULL,
    partido_id INTEGER,
    titulo TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT '',
    archivo TEXT NOT NULL,
    servidor TEXT NOT NULL DEFAULT 'local',
    bytes INTEGER NOT NULL DEFAULT 0,
    duracion REAL,
    estado TEXT NOT NULL DEFAULT 'subiendo',
    subido_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    listo_en TEXT NOT NULL DEFAULT '',
    expira TEXT NOT NULL DEFAULT '',
    visto_en TEXT,
    borrado_en TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_videos_persona ON videos(tipo, persona_id, estado);
CREATE INDEX IF NOT EXISTS idx_videos_liga ON videos(liga_id, estado);
CREATE TABLE IF NOT EXISTS referidos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    referido_id INTEGER NOT NULL UNIQUE REFERENCES ligas(id) ON DELETE CASCADE,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    meses INTEGER NOT NULL DEFAULT 0,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    premiado_en TEXT NOT NULL DEFAULT ''
);
SQL);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS bol_ventas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    partido_id INTEGER,
    titulo TEXT NOT NULL DEFAULT '',
    fecha TEXT NOT NULL DEFAULT '',
    hora TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    descripcion TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'borrador',
    venta_hasta TEXT NOT NULL DEFAULT '',
    max_por_orden INTEGER NOT NULL DEFAULT 10,
    puerta_clave TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_bol_ventas ON bol_ventas(liga_id, estado);
CREATE TABLE IF NOT EXISTS bol_localidades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    venta_id INTEGER NOT NULL REFERENCES bol_ventas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'entrada',
    precio REAL NOT NULL DEFAULT 0,
    cupo INTEGER NOT NULL DEFAULT 0,
    orden INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS bol_ordenes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    venta_id INTEGER NOT NULL REFERENCES bol_ventas(id) ON DELETE CASCADE,
    liga_id INTEGER NOT NULL,
    codigo TEXT NOT NULL UNIQUE,
    nombre TEXT NOT NULL DEFAULT '',
    cedula TEXT NOT NULL DEFAULT '',
    telefono TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    canal TEXT NOT NULL DEFAULT 'web',
    metodo TEXT NOT NULL DEFAULT 'transferencia',
    estado TEXT NOT NULL DEFAULT 'pendiente',
    total REAL NOT NULL DEFAULT 0,
    iva REAL NOT NULL DEFAULT 0,
    imp_municipal REAL NOT NULL DEFAULT 0,
    comprobante TEXT NOT NULL DEFAULT '',
    pago_token TEXT NOT NULL DEFAULT '',
    expira_en TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pagada_en TEXT NOT NULL DEFAULT '',
    revisado_por TEXT NOT NULL DEFAULT '',
    nota TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_bol_ordenes ON bol_ordenes(venta_id, estado);
CREATE TABLE IF NOT EXISTS bol_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    orden_id INTEGER NOT NULL REFERENCES bol_ordenes(id) ON DELETE CASCADE,
    localidad_id INTEGER NOT NULL,
    cantidad INTEGER NOT NULL DEFAULT 1,
    precio REAL NOT NULL DEFAULT 0,
    placas TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS bol_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    orden_id INTEGER NOT NULL REFERENCES bol_ordenes(id) ON DELETE CASCADE,
    venta_id INTEGER NOT NULL,
    localidad_id INTEGER NOT NULL,
    codigo TEXT NOT NULL UNIQUE,
    titular TEXT NOT NULL DEFAULT '',
    placa TEXT NOT NULL DEFAULT '',
    estado TEXT NOT NULL DEFAULT 'valido',
    usado_en TEXT NOT NULL DEFAULT '',
    puerta TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_bol_tickets_venta ON bol_tickets(venta_id, estado);
CREATE TABLE IF NOT EXISTS bol_escaneos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    venta_id INTEGER NOT NULL,
    ticket_id INTEGER,
    codigo TEXT NOT NULL DEFAULT '',
    resultado TEXT NOT NULL DEFAULT '',
    puerta TEXT NOT NULL DEFAULT '',
    offline INTEGER NOT NULL DEFAULT 0,
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_bol_escaneos ON bol_escaneos(venta_id);
SQL);
    $agregar('ligas', ['entradas_activas' => 'INTEGER NOT NULL DEFAULT 0', 'entradas_tarjeta' => 'INTEGER NOT NULL DEFAULT 0', 'entradas_transferencia' => 'INTEGER NOT NULL DEFAULT 1',
        'entradas_parqueo' => 'INTEGER NOT NULL DEFAULT 0', 'entradas_iva' => 'REAL NOT NULL DEFAULT 0', 'entradas_municipal' => 'REAL NOT NULL DEFAULT 0', 'entradas_condiciones' => "TEXT NOT NULL DEFAULT ''"]);
    $agregar('ligas', ['ref_codigo' => "TEXT NOT NULL DEFAULT ''", 'referido_por' => 'INTEGER', 'idioma' => "TEXT NOT NULL DEFAULT 'es'"]);
    $agregar('usuarios', ['idioma' => "TEXT NOT NULL DEFAULT ''"]);
    // Novedades de la versión 28 (se ven una sola vez)
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    foreach ([
        ['🏛 Federaciones', 'Varias ligas y escuelas de cualquier deporte bajo un mismo paraguas: resumen, partidos de la semana, sanciones federativas que se respetan en todas las ligas y campeonatos inter-ligas. Su liga puede afiliarse en Parámetros → Federación.', 'todos', 'manual/ligas#s29', 'v28', '2026-10-01'],
        ['🎟 Venta de entradas con QR', 'Opcional: venda las entradas de sus partidos por internet (tarjeta o transferencia), en taquilla y cortesías, con parqueadero. En la puerta se valida con el celular, aunque no haya internet. Se activa en Parámetros.', 'ligas', 'manual/ligas#s30', 'v28', '2026-10-01'],
        ['🎬 Videos del jugador y del alumno', 'Suba videos cortos (hasta 2 minutos) a cada jugador o alumno: les llegan a su portal para verlos y descargarlos. Se borran solos a los 10 días.', 'todos', 'admin.php?p=videos', 'v28', '2026-10-01'],
        ['📈 Indicadores y 🤝 Recomiende y gane', 'Vea cómo va su liga o escuela mes a mes, y gane meses gratis por cada liga o escuela que contrate con su recomendación.', 'todos', 'admin.php?p=indicadores', 'v28', '2026-10-01'],
        ['🌐 Idiomas', 'El sistema ya está en inglés, francés, portugués e italiano: use el botón 🌐 de la barra de arriba. Elija el idioma de su página pública en Parámetros.', 'todos', '', 'v28', '2026-10-01'],
    ] as $n) $ins->execute(array_merge($n, [$n[0]]));
    $agregar('solicitudes_demo', ['recomendado_por' => 'INTEGER']);
}

/*
 * Versión 26 (paquete v29, 1-oct-2026): fixture completo imprimible y esquema del campeonato.
 *  - torneos.llaves_por_grupo: fase final prevista (cuántos clasifican por grupo a la llave; 0 = sin fase final).
 *  - El plan Torneo deja de tener "todos los módulos": se le pone la lista completa MENOS 'fixture_pro', que queda
 *    exclusivo del plan Profesional (Básico ya tiene una lista propia sin él). Solo si nadie lo cambió a mano.
 */
function esquema_v26(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(torneos)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('llaves_por_grupo', $cols, true)) $pdo->exec('ALTER TABLE torneos ADD COLUMN llaves_por_grupo INTEGER NOT NULL DEFAULT 0');
    $sin_fixture_pro = 'vocalia,carnets,inscripcion,finanzas,arbitros,redes,publico,reservas,avisos,pagos_tarjeta,dominio,esc_familias,esc_profesores,'
        . 'esc_entrenamientos,esc_evaluaciones,esc_formativas,esc_tienda,entradas,videos,carga_masiva,contabilidad';
    $pdo->prepare("UPDATE planes SET modulos = ? WHERE slug = 'torneo' AND trim(modulos) IN ('*', '')")->execute([$sin_fixture_pro]);
    $pdo->exec("UPDATE planes SET eslogan = 'Un campeonato de 3 meses con casi todo incluido' WHERE slug = 'torneo' AND eslogan = 'Un campeonato de 3 meses con TODO incluido'");
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    $n = ['🗺️ Fixture para imprimir y esquema del campeonato', 'Exclusivo del plan Profesional: imprima el fixture completo de cada campeonato (con resultados o en blanco para llenar a mano) y vea el esquema de todo el torneo desde el primer día, con la llave de la fase final que se llena sola con los clasificados. Está en cada campeonato → 2. Fixture y resultados.', 'ligas', 'admin.php?p=torneos', 'v29', '2026-10-01'];
    $ins->execute(array_merge($n, [$n[0]]));
}

/*
 * Versión 27 (paquete v30, 1-oct-2026): perfil Asistente con permisos a medida.
 *  - usuarios.permisos: JSON {"ver": [áreas], "editar": [áreas]} que elige el administrador (solo se usa con perfil 'asistente').
 */
function esquema_v27(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(usuarios)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('permisos', $cols, true)) $pdo->exec("ALTER TABLE usuarios ADD COLUMN permisos TEXT NOT NULL DEFAULT ''");
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    foreach ([
        ['🧑‍💼 Perfil Asistente', 'Cree un usuario con el perfil Asistente y elija usted, sección por sección, qué puede ver y en qué puede hacer cambios. Está en Configuración → Usuarios.', 'todos', 'admin.php?p=usuarios', 'v30', '2026-10-01'],
        ['📘 Manuales en 5 idiomas', 'Los manuales de ligas y de escuelas ya están en español, inglés, francés, portugués e italiano, con todo lo nuevo. Elija el idioma con el botón 🌐.', 'todos', 'manual', 'v30', '2026-10-01'],
    ] as $n) $ins->execute(array_merge($n, [$n[0]]));
}

/*
 * Versión 28 (paquete v31, 1-oct-2026): escuelas de artes marciales (taekwondo, karate, judo).
 *  - esc_cinturones: lista de cinturones de cada escuela y disciplina (grado, nombre, color, punta, forma).
 *  - esc_examenes + esc_examen_inscritos: exámenes de grado con cobro, resultados y código de certificado.
 *  - esc_formas_alumno: poomsae / kata que el alumno está aprendiendo o domina.
 *  - esc_grados.cinturon_id y esc_grados.examen_id.
 */
function esquema_v28(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS esc_cinturones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    disciplina TEXT NOT NULL DEFAULT 'artes_marciales',
    orden INTEGER NOT NULL DEFAULT 0,
    grado TEXT NOT NULL DEFAULT '',
    nombre TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT 'blanco',
    punta TEXT NOT NULL DEFAULT '',
    forma TEXT NOT NULL DEFAULT '',
    activo INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_esc_cinturones ON esc_cinturones(liga_id, disciplina, orden);
CREATE TABLE IF NOT EXISTS esc_examenes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    liga_id INTEGER NOT NULL REFERENCES ligas(id) ON DELETE CASCADE,
    disciplina TEXT NOT NULL DEFAULT 'artes_marciales',
    fecha TEXT NOT NULL,
    hora TEXT NOT NULL DEFAULT '',
    lugar TEXT NOT NULL DEFAULT '',
    examinador TEXT NOT NULL DEFAULT '',
    valor REAL NOT NULL DEFAULT 0,
    estado TEXT NOT NULL DEFAULT 'abierto',
    notas TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    creado TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_esc_examenes ON esc_examenes(liga_id, fecha);
CREATE TABLE IF NOT EXISTS esc_examen_inscritos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    examen_id INTEGER NOT NULL REFERENCES esc_examenes(id) ON DELETE CASCADE,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    grado_actual TEXT NOT NULL DEFAULT '',
    grado_aspira TEXT NOT NULL DEFAULT '',
    cinturon_id INTEGER,
    forma TEXT NOT NULL DEFAULT '',
    forma_ok INTEGER NOT NULL DEFAULT 0,
    nota TEXT NOT NULL DEFAULT '',
    resultado TEXT NOT NULL DEFAULT 'pendiente',
    observaciones TEXT NOT NULL DEFAULT '',
    cargo_id INTEGER,
    certificado TEXT NOT NULL DEFAULT '',
    UNIQUE (examen_id, alumno_id)
);
CREATE INDEX IF NOT EXISTS idx_esc_examen_cert ON esc_examen_inscritos(certificado);
CREATE TABLE IF NOT EXISTS esc_formas_alumno (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alumno_id INTEGER NOT NULL REFERENCES esc_alumnos(id) ON DELETE CASCADE,
    forma TEXT NOT NULL,
    estado TEXT NOT NULL DEFAULT 'aprendiendo',
    fecha TEXT NOT NULL DEFAULT '',
    registrado_por TEXT NOT NULL DEFAULT '',
    UNIQUE (alumno_id, forma)
);
SQL);
    $cols = array_column($pdo->query('PRAGMA table_info(esc_grados)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('cinturon_id', $cols, true)) $pdo->exec('ALTER TABLE esc_grados ADD COLUMN cinturon_id INTEGER');
    if (!in_array('examen_id', $cols, true)) $pdo->exec('ALTER TABLE esc_grados ADD COLUMN examen_id INTEGER');
    $ins = $pdo->prepare('INSERT INTO novedades (titulo, texto, para, enlace, version, fecha) SELECT ?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM novedades WHERE titulo = ?)');
    $n = ['🥋 Escuelas de taekwondo, karate y judo', 'Elija Taekwondo, Karate o Judo como deporte del grupo y tendrá: su propia lista de cinturones (kup/dan con puntas), categorías de peso oficiales, poomsae o kata de cada cinturón y exámenes de grado con cobro, acta y certificado con código QR. Está en Alumnos → Exámenes de grado.', 'escuelas', 'admin.php?p=examenes', 'v31', '2026-10-01'];
    $ins->execute(array_merge($n, [$n[0]]));
}

