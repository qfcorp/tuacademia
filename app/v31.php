<?php
/*
 * VERSIÓN 31 — ESCUELAS DE ARTES MARCIALES (taekwondo, karate, judo)
 *  - Deportes nuevos en el catálogo de escuelas: Taekwondo, Karate y Judo (familia 'artes_marciales': todo lo que ya
 *    existía para artes marciales —tests, habilidades, ejercicios, combates, cinturones— sigue funcionando igual).
 *  - CINTURONES EDITABLES por escuela y disciplina (tabla esc_cinturones): grado (10.º Kup, 3.er Kyu, 1.er Dan…),
 *    color, punta (segundo color) y la forma (poomsae / kata) de ese cinturón. Viene de fábrica la lista de cada disciplina.
 *  - CATEGORÍAS DE PESO por disciplina: taekwondo (World Taekwondo) por edad (infantil, cadete, junior, adulto),
 *    karate (WKF) y judo (IJF) adultos; boxeo sigue con las suyas.
 *  - PALABRAS PROPIAS de cada disciplina: combate (kyorugi) y poomsae en taekwondo; kumite y kata en karate; randori y kata en judo.
 *  - EXÁMENES DE GRADO (esc_examenes, esc_examen_inscritos): inscritos con su grado actual y el que aspiran, cobro del derecho
 *    a examen (se suma a Pagos), resultados, acta y CERTIFICADO DE GRADO en PDF con código QR que se verifica en
 *    certificado.php?c=CÓDIGO. Al aprobar, el cinturón nuevo queda en la ficha, el carnet y el portal de los padres.
 *  - POOMSAE / KATA POR CINTURÓN: cada cinturón tiene su forma y el profesor marca si el alumno la está aprendiendo o la domina
 *    (esc_formas_alumno); se ve en la ficha, en el portal de los padres y al inscribirlo en el examen.
 */

/* ============================================================
 * 1) DISCIPLINAS Y PALABRAS PROPIAS
 * ============================================================ */

/** Disciplinas de artes marciales: clave del deporte => nombre. 'artes_marciales' = otra o sin especificar. */
function disciplinas_marciales(): array
{
    return ['taekwondo' => 'Taekwondo', 'karate' => 'Karate', 'judo' => 'Judo', 'artes_marciales' => 'Artes marciales'];
}

function es_deporte_marcial(string $d): bool
{
    return familia_deporte($d) === 'artes_marciales';
}

/** Disciplina (clave) de un deporte de la escuela: taekwondo | karate | judo | artes_marciales. */
function disciplina_de(string $d): string
{
    return array_key_exists($d, disciplinas_marciales()) ? $d : 'artes_marciales';
}

/** Disciplinas de artes marciales que enseña la escuela (según sus grupos). */
function disciplinas_de_escuela(array $liga): array
{
    $r = [];
    foreach (deportes_de_escuela($liga) as $d) if (es_deporte_marcial($d)) $r[] = disciplina_de($d);
    return array_values(array_unique($r));
}

/** Palabras propias de cada disciplina. */
function vocabulario_marcial(string $disc): array
{
    return match ($disc) {
        'taekwondo' => ['combate' => 'Combate (kyorugi)', 'combates' => 'Combates (kyorugi)', 'forma' => 'Poomsae', 'formas' => 'Poomsaes', 'maestro' => 'Sabonim', 'lugar' => 'Dojang', 'cinturon' => 'Cinturón'],
        'karate' => ['combate' => 'Kumite', 'combates' => 'Kumite', 'forma' => 'Kata', 'formas' => 'Katas', 'maestro' => 'Sensei', 'lugar' => 'Dojo', 'cinturon' => 'Cinturón (obi)'],
        'judo' => ['combate' => 'Randori / shiai', 'combates' => 'Combates (shiai)', 'forma' => 'Kata', 'formas' => 'Katas', 'maestro' => 'Sensei', 'lugar' => 'Dojo', 'cinturon' => 'Cinturón (obi)'],
        default => ['combate' => 'Combate', 'combates' => 'Combates', 'forma' => 'Forma', 'formas' => 'Formas', 'maestro' => 'Maestro', 'lugar' => 'Dojo', 'cinturon' => 'Cinturón'],
    };
}

/** Palabras para la escuela: si enseña una sola disciplina, las de esa; si enseña varias, las generales. */
function vocabulario_escuela(array $liga): array
{
    $ds = disciplinas_de_escuela($liga);
    return vocabulario_marcial(count($ds) === 1 ? $ds[0] : 'artes_marciales');
}

/* ============================================================
 * 2) CINTURONES (editables por escuela y disciplina)
 * ============================================================ */

/** Colores de cinturón: clave => [nombre, color CSS]. */
function colores_cinturon(): array
{
    return [
        'blanco' => ['Blanco', '#f4f4f2'], 'amarillo' => ['Amarillo', '#f5c518'], 'naranja' => ['Naranja', '#f28c28'], 'verde' => ['Verde', '#2e9e4f'],
        'azul' => ['Azul', '#2563c9'], 'morado' => ['Morado', '#7b3fb0'], 'marron' => ['Marrón', '#7a4a24'], 'rojo' => ['Rojo', '#d7263d'],
        'negro' => ['Negro', '#151515'], 'rojinegro' => ['Rojo y negro', '#7a1020'], 'gris' => ['Gris', '#9aa3a0'], 'celeste' => ['Celeste', '#5ab4e8'],
    ];
}

/**
 * Lista de fábrica de cada disciplina: [grado, nombre, color, punta, forma].
 * Taekwondo: sistema kup/dan con puntas usado por las escuelas de World Taekwondo (los poomsae Taegeuk y los de Dan).
 * Cada escuela la puede cambiar (hay escuelas que usan otros colores o no usan puntas).
 */
function cinturones_fabrica(string $disc): array
{
    return match ($disc) {
        'taekwondo' => [
            ['10.º Kup', 'Blanco', 'blanco', '', 'Kibon (movimientos básicos)'],
            ['9.º Kup', 'Blanco punta amarilla', 'blanco', 'amarillo', 'Kibon (movimientos básicos)'],
            ['8.º Kup', 'Amarillo', 'amarillo', '', 'Taegeuk Il Jang'],
            ['7.º Kup', 'Amarillo punta verde', 'amarillo', 'verde', 'Taegeuk Ee Jang'],
            ['6.º Kup', 'Verde', 'verde', '', 'Taegeuk Sam Jang'],
            ['5.º Kup', 'Verde punta azul', 'verde', 'azul', 'Taegeuk Sa Jang'],
            ['4.º Kup', 'Azul', 'azul', '', 'Taegeuk Oh Jang'],
            ['3.er Kup', 'Azul punta roja', 'azul', 'rojo', 'Taegeuk Yuk Jang'],
            ['2.º Kup', 'Rojo', 'rojo', '', 'Taegeuk Chil Jang'],
            ['1.er Kup', 'Rojo punta negra', 'rojo', 'negro', 'Taegeuk Pal Jang'],
            ['1.er Dan', 'Negro', 'negro', '', 'Koryo'],
            ['2.º Dan', 'Negro', 'negro', '', 'Keumgang'],
            ['3.er Dan', 'Negro', 'negro', '', 'Taebaek'],
            ['4.º Dan', 'Negro', 'negro', '', 'Pyongwon'],
            ['5.º Dan', 'Negro', 'negro', '', 'Sipjin'],
        ],
        'karate' => [
            ['10.º Kyu', 'Blanco', 'blanco', '', 'Kihon (básicos)'],
            ['9.º Kyu', 'Amarillo', 'amarillo', '', 'Heian Shodan'],
            ['8.º Kyu', 'Naranja', 'naranja', '', 'Heian Nidan'],
            ['7.º Kyu', 'Verde', 'verde', '', 'Heian Sandan'],
            ['6.º Kyu', 'Azul', 'azul', '', 'Heian Yondan'],
            ['5.º Kyu', 'Morado', 'morado', '', 'Heian Godan'],
            ['4.º Kyu', 'Marrón', 'marron', '', 'Tekki Shodan'],
            ['3.er Kyu', 'Marrón', 'marron', '', 'Bassai Dai'],
            ['2.º Kyu', 'Marrón', 'marron', '', 'Kanku Dai'],
            ['1.er Kyu', 'Marrón', 'marron', '', 'Empi / Jion'],
            ['1.er Dan', 'Negro', 'negro', '', 'Kata libre superior'],
            ['2.º Dan', 'Negro', 'negro', '', 'Kata libre superior'],
            ['3.er Dan', 'Negro', 'negro', '', 'Kata libre superior'],
        ],
        'judo' => [
            ['6.º Kyu', 'Blanco', 'blanco', '', ''],
            ['5.º Kyu', 'Amarillo', 'amarillo', '', ''],
            ['4.º Kyu', 'Naranja', 'naranja', '', ''],
            ['3.er Kyu', 'Verde', 'verde', '', ''],
            ['2.º Kyu', 'Azul', 'azul', '', ''],
            ['1.er Kyu', 'Marrón', 'marron', '', ''],
            ['1.er Dan', 'Negro', 'negro', '', 'Nage-no-kata'],
            ['2.º Dan', 'Negro', 'negro', '', 'Katame-no-kata'],
            ['3.er Dan', 'Negro', 'negro', '', 'Kime-no-kata'],
        ],
        default => [
            ['', 'Blanco', 'blanco', '', ''], ['', 'Amarillo', 'amarillo', '', ''], ['', 'Naranja', 'naranja', '', ''], ['', 'Verde', 'verde', '', ''],
            ['', 'Azul', 'azul', '', ''], ['', 'Morado', 'morado', '', ''], ['', 'Marrón', 'marron', '', ''], ['', 'Rojo', 'rojo', '', ''],
            ['1.er Dan', 'Negro', 'negro', '', ''], ['2.º Dan', 'Negro', 'negro', '', ''], ['3.er Dan', 'Negro', 'negro', '', ''],
        ],
    };
}

/** Carga la lista de fábrica de una disciplina en la escuela (borra la anterior si $reemplazar). */
function cargar_cinturones_fabrica(int $lid, string $disc, bool $reemplazar = false): void
{
    if ($reemplazar) q('DELETE FROM esc_cinturones WHERE liga_id = ? AND disciplina = ?', [$lid, $disc]);
    foreach (cinturones_fabrica($disc) as $i => [$grado, $nombre, $color, $punta, $forma]) {
        q('INSERT INTO esc_cinturones (liga_id, disciplina, orden, grado, nombre, color, punta, forma) VALUES (?,?,?,?,?,?,?,?)', [$lid, $disc, $i + 1, $grado, $nombre, $color, $punta, $forma]);
    }
}

/** Cinturones de la escuela para una disciplina, en orden (si no tiene, carga los de fábrica). */
function cinturones_escuela(int $lid, string $disc, bool $solo_activos = true): array
{
    $disc = disciplina_de($disc);
    $sql = 'SELECT * FROM esc_cinturones WHERE liga_id = ? AND disciplina = ?' . ($solo_activos ? ' AND activo = 1' : '') . ' ORDER BY orden, id';
    $r = filas($sql, [$lid, $disc]);
    if (!$r && !valor('SELECT COUNT(*) FROM esc_cinturones WHERE liga_id = ? AND disciplina = ?', [$lid, $disc])) {
        cargar_cinturones_fabrica($lid, $disc);
        $r = filas($sql, [$lid, $disc]);
    }
    return $r;
}

/** "Amarillo punta verde (7.º Kup)". */
function etiqueta_cinturon(array $c): string
{
    return trim($c['nombre'] . ($c['grado'] !== '' ? ' (' . $c['grado'] . ')' : ''));
}

/** Dibujo del cinturón (franja de color con su punta) + su nombre. */
function html_cinturon(?array $c, string $texto = '', bool $con_texto = true): string
{
    $col = colores_cinturon();
    $c1 = $c ? ($col[$c['color']][1] ?? '#ccc') : '#ccc';
    $c2 = $c && $c['punta'] !== '' ? ($col[$c['punta']][1] ?? '') : '';
    $txt = $texto !== '' ? $texto : ($c ? etiqueta_cinturon($c) : '');
    $fondo = $c2 !== '' ? 'linear-gradient(90deg,' . $c1 . ' 0 72%,' . $c2 . ' 72% 100%)' : $c1;
    return '<span class="cinturon" title="' . e($txt) . '"><span class="cinturon-tira" style="background:' . $fondo . '"></span>' . ($con_texto ? '<span>' . e($txt) . '</span>' : '') . '</span>';
}

/** Busca en la lista de la escuela el cinturón que corresponde a un texto de grado guardado (ej. "Amarillo (8.º Kup)" o "Amarillo"). */
function cinturon_por_texto(array $lista, string $texto): ?array
{
    $t = mb_strtolower(trim($texto));
    if ($t === '') return null;
    foreach ($lista as $c) if (mb_strtolower(etiqueta_cinturon($c)) === $t) return $c;
    foreach ($lista as $c) if (mb_strtolower($c['nombre']) === $t || ($c['grado'] !== '' && mb_strtolower($c['grado']) === $t)) return $c;
    return null;
}

/** Disciplina de un alumno: la de su primer grupo de artes marciales. */
function disciplina_alumno(array $a, ?array $liga = null): string
{
    foreach (deportes_alumno($a, $liga) as $d) if (es_deporte_marcial($d)) return disciplina_de($d);
    return 'artes_marciales';
}

/** Cinturón actual del alumno: [fila de esc_grados|null, cinturón de la lista|null, siguiente cinturón|null]. */
function cinturon_alumno(array $a, ?array $liga = null): array
{
    $gr = grado_actual((int)$a['id']);
    $lista = cinturones_escuela((int)$a['liga_id'], disciplina_alumno($a, $liga));
    $act = null;
    if ($gr) {
        if (!empty($gr['cinturon_id'])) foreach ($lista as $c) if ((int)$c['id'] === (int)$gr['cinturon_id']) $act = $c;
        $act ??= cinturon_por_texto($lista, $gr['grado']);
    }
    $sig = null;
    if ($act) {
        $ver = false;
        foreach ($lista as $c) { if ($ver) { $sig = $c; break; } if ((int)$c['id'] === (int)$act['id']) $ver = true; }
    } elseif (!$gr) {
        $sig = $lista[0] ?? null;
        if ($sig && count($lista) > 1) $sig = $lista[1]; // sin grado registrado: empieza con el primero (blanco) y aspira al segundo
    }
    return [$gr, $act, $sig];
}

/* ============================================================
 * 3) CATEGORÍAS DE PESO POR DISCIPLINA
 * ============================================================ */

/** Grupo de edad de World Taekwondo según la edad que cumple en el año. */
function grupo_edad_marcial(?string $fecha_nac): string
{
    if (!$fecha_nac || !preg_match('/^(\d{4})-/', $fecha_nac, $m)) return 'Adulto';
    $e = (int)date('Y') - (int)$m[1];
    return $e <= 11 ? 'Infantil' : ($e <= 14 ? 'Cadete' : ($e <= 17 ? 'Junior' : 'Adulto'));
}

/**
 * Categorías de peso (en kg, con "-" = hasta y "+" = más de) según la disciplina, el género y el grupo de edad.
 * Taekwondo: World Taekwondo (cadete 12-14, junior 15-17, adulto); infantil es referencial (cada federación tiene la suya).
 * Karate: WKF adultos. Judo: IJF adultos. Otras: las de boxeo de antes.
 */
function categorias_peso_disciplina(string $disc, string $genero = 'M', string $grupo = 'Adulto'): array
{
    $f = $genero === 'F';
    return match ($disc) {
        'taekwondo' => match ($grupo) {
            'Infantil' => $f ? ['-21', '-24', '-27', '-30', '-33', '-36', '-40', '+40'] : ['-21', '-24', '-27', '-30', '-33', '-37', '-41', '-45', '+45'],
            'Cadete' => $f ? ['-29', '-33', '-37', '-41', '-44', '-47', '-51', '-55', '-59', '+59'] : ['-33', '-37', '-41', '-45', '-49', '-53', '-57', '-61', '-65', '+65'],
            'Junior' => $f ? ['-42', '-44', '-46', '-49', '-52', '-55', '-59', '-63', '-68', '+68'] : ['-45', '-48', '-51', '-55', '-59', '-63', '-68', '-73', '-78', '+78'],
            default => $f ? ['-46', '-49', '-53', '-57', '-62', '-67', '-73', '+73'] : ['-54', '-58', '-63', '-68', '-74', '-80', '-87', '+87'],
        },
        'karate' => $f ? ['-50', '-55', '-61', '-68', '+68'] : ['-60', '-67', '-75', '-84', '+84'],
        'judo' => $f ? ['-48', '-52', '-57', '-63', '-70', '-78', '+78'] : ['-60', '-66', '-73', '-81', '-90', '-100', '+100'],
        default => array_map(fn($x) => str_starts_with($x, '+') ? $x : '-' . $x, categorias_peso($genero)),
    };
}

/** Categoría sugerida: "Cadete masculino -45 kg". */
function categoria_marcial(?float $kg, string $disc, string $genero = 'M', ?string $fecha_nac = null): string
{
    if (!$kg) return '';
    $grupo = $disc === 'taekwondo' ? grupo_edad_marcial($fecha_nac) : 'Adulto';
    $cats = categorias_peso_disciplina($disc, $genero, $grupo);
    $sel = end($cats);
    foreach ($cats as $c) if ($c[0] === '-' && $kg <= (float)substr($c, 1)) { $sel = $c; break; }
    return ($disc === 'taekwondo' ? $grupo . ' ' . ($genero === 'F' ? 'femenino' : 'masculino') . ' ' : '') . $sel . ' kg';
}

/* ============================================================
 * 4) POOMSAE / KATA DE CADA ALUMNO
 * ============================================================ */

function estados_forma(): array
{
    return ['aprendiendo' => ['📖 Aprendiendo', 'chip-pend'], 'domina' => ['✅ La domina', 'chip-ok']];
}

/** Formas del alumno: [forma => fila]. */
function formas_alumno(int $aid): array
{
    $r = [];
    foreach (filas('SELECT * FROM esc_formas_alumno WHERE alumno_id = ? ORDER BY fecha, id', [$aid]) as $f) $r[$f['forma']] = $f;
    return $r;
}

function guardar_forma_alumno(int $aid, string $forma, string $estado, string $por): void
{
    $forma = mb_substr(trim($forma), 0, 80);
    if ($forma === '') throw new RuntimeException('Ese cinturón no tiene forma asignada.');
    if ($estado === 'quitar') { q('DELETE FROM esc_formas_alumno WHERE alumno_id = ? AND forma = ?', [$aid, $forma]); return; }
    $estado = array_key_exists($estado, estados_forma()) ? $estado : 'aprendiendo';
    q('INSERT INTO esc_formas_alumno (alumno_id, forma, estado, fecha, registrado_por) VALUES (?,?,?,?,?)
       ON CONFLICT(alumno_id, forma) DO UPDATE SET estado = excluded.estado, fecha = excluded.fecha, registrado_por = excluded.registrado_por', [$aid, $forma, $estado, date('Y-m-d'), $por]);
}

/** Chip del estado de la forma. */
function chip_forma(?array $f): string
{
    if (!$f) return '<span class="chip-estado">— sin empezar</span>';
    [$t, $cls] = estados_forma()[$f['estado']] ?? ['', ''];
    return '<span class="chip-estado ' . $cls . '">' . $t . '</span>';
}

/** Tarjeta "Cinturón y poomsae" para la ficha del alumno y el portal de los padres. */
function html_cinturon_alumno(array $a, ?array $liga = null): string
{
    $tiene = false;
    foreach (deportes_alumno($a, $liga) as $d) if (es_deporte_marcial($d)) $tiene = true;
    if (!$tiene) return '';
    $disc = disciplina_alumno($a, $liga);
    $voc = vocabulario_marcial($disc);
    [$gr, $act, $sig] = cinturon_alumno($a, $liga);
    $fs = formas_alumno((int)$a['id']);
    $h = '<div class="tarjeta tarjeta-cinturon"><h3 style="margin-top:0">🥋 ' . e($voc['cinturon']) . ' y ' . e(mb_strtolower($voc['forma'])) . ' · ' . e(disciplinas_marciales()[$disc]) . '</h3>';
    $h .= '<p style="margin:.2rem 0">' . ($gr ? html_cinturon($act, $act ? '' : $gr['grado']) . ' <small class="suave"><span>desde</span> <span>' . e(fecha_corta($gr['fecha'])) . '</span></small>' : '<span class="suave">Todavía sin grado registrado.</span>') . '</p>';
    if ($sig) {
        $h .= '<p style="margin:.4rem 0">Próximo: ' . html_cinturon($sig) . '</p>';
        if ($sig['forma'] !== '') $h .= '<p style="margin:.2rem 0">' . e($voc['forma']) . ' del próximo cinturón: <b>' . e($sig['forma']) . '</b> ' . chip_forma($fs[$sig['forma']] ?? null) . '</p>';
    }
    $dom = array_filter($fs, fn($f) => $f['estado'] === 'domina');
    if ($dom) $h .= '<p style="margin:.4rem 0 0"><small class="suave"><span>' . e($voc['formas']) . ' que domina:</span> <span>' . e(implode(', ', array_keys($dom))) . '</span></small></p>';
    return $h . '</div>';
}

/* ============================================================
 * 5) EXÁMENES DE GRADO Y CERTIFICADOS
 * ============================================================ */

function resultados_examen(): array
{
    return ['pendiente' => ['⏳ Pendiente', 'chip-pend'], 'aprobado' => ['✅ Aprobado', 'chip-ok'], 'reprobado' => ['✗ No aprobó', 'chip-mal'], 'ausente' => ['— No se presentó', '']];
}

function examen_de_liga(int $id, int $lid): array
{
    return fila('SELECT * FROM esc_examenes WHERE id = ? AND liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Examen no encontrado.');
}

/** Inscritos del examen con los datos del alumno. */
function inscritos_examen(int $eid): array
{
    return filas('SELECT i.*, a.nombres, a.apellidos, a.cedula, a.fecha_nac, a.genero, a.liga_id, a.grupo_id, a.foto, a.carnet_token
                  FROM esc_examen_inscritos i JOIN esc_alumnos a ON a.id = i.alumno_id WHERE i.examen_id = ? ORDER BY a.apellidos, a.nombres', [$eid]);
}

/** Código del certificado (12 letras y números, sin confusiones). */
function nuevo_codigo_certificado(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $c = '';
        for ($i = 0; $i < 12; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
    } while (valor('SELECT COUNT(*) FROM esc_examen_inscritos WHERE certificado = ?', [$c]));
    return $c;
}

function url_certificado(string $codigo): string
{
    return rtrim(url_base(), '/') . '/certificado.php?c=' . rawurlencode($codigo);
}

/**
 * Cierra el examen: a cada aprobado le registra el cinturón nuevo (esc_grados, con el examinador y la fecha del examen)
 * y le genera el código del certificado. Se puede volver a cerrar si se corrige un resultado (no duplica).
 */
function cerrar_examen(array $ex, string $por): int
{
    $n = 0;
    foreach (inscritos_examen((int)$ex['id']) as $i) {
        if ($i['resultado'] === 'aprobado') {
            if (!valor('SELECT COUNT(*) FROM esc_grados WHERE alumno_id = ? AND examen_id = ?', [$i['alumno_id'], $ex['id']])) {
                q('INSERT INTO esc_grados (alumno_id, fecha, grado, examinador, notas, registrado_por, cinturon_id, examen_id) VALUES (?,?,?,?,?,?,?,?)', [
                    $i['alumno_id'], $ex['fecha'], mb_substr($i['grado_aspira'], 0, 60), $ex['examinador'], trim('Examen de grado del ' . fecha_corta($ex['fecha']) . ($i['nota'] !== '' ? ' · Nota: ' . $i['nota'] : '')), $por,
                    $i['cinturon_id'] ?: null, $ex['id']]);
                $n++;
            }
            if ($i['certificado'] === '') q('UPDATE esc_examen_inscritos SET certificado = ? WHERE id = ?', [nuevo_codigo_certificado(), $i['id']]);
        } else {
            // Si antes estaba aprobado y se corrigió, se quita el grado que había dado este examen
            q('DELETE FROM esc_grados WHERE alumno_id = ? AND examen_id = ?', [$i['alumno_id'], $ex['id']]);
            if ($i['certificado'] !== '') q("UPDATE esc_examen_inscritos SET certificado = '' WHERE id = ?", [$i['id']]);
        }
    }
    q("UPDATE esc_examenes SET estado = 'cerrado' WHERE id = ?", [$ex['id']]);
    return $n;
}

/** Acta del examen (lista de inscritos y resultados) para imprimir. */
function doc_acta_examen(array $ex, array $liga, string $volver): void
{
    $ins = inscritos_examen((int)$ex['id']);
    $voc = vocabulario_marcial($ex['disciplina']);
    doc_inicio('Acta del examen de grado ' . fecha_corta($ex['fecha']), $volver);
    doc_cabecera_financiera($liga, 'ACTA DEL EXAMEN DE GRADO · ' . mb_strtoupper(disciplinas_marciales()[$ex['disciplina']] ?? ''));
    ?>
  <div class="meta">
    <div><b>Fecha:</b> <?= e(fecha_larga($ex['fecha'])) ?><?= $ex['hora'] ? ' · ' . e($ex['hora']) : '' ?></div>
    <div><b>Lugar:</b> <?= e($ex['lugar'] ?: '—') ?></div>
    <div><b>Examinador:</b> <?= e($ex['examinador'] ?: '—') ?></div>
    <div><b>Inscritos:</b> <?= count($ins) ?> · <b>Aprobados:</b> <?= count(array_filter($ins, fn($i) => $i['resultado'] === 'aprobado')) ?></div>
  </div>
  <table class="pl"><tr><th>#</th><th>Alumno</th><th>Cédula</th><th>Grado actual</th><th>Aspira a</th><th><?= e($voc['forma']) ?></th><th>Nota</th><th>Resultado</th><th>Firma</th></tr>
    <?php foreach ($ins as $k => $i): ?><tr><td><?= $k + 1 ?></td><td><?= e(nombre_alumno($i)) ?></td><td><?= e($i['cedula']) ?></td><td><?= e($i['grado_actual'] ?: '—') ?></td><td><b><?= e($i['grado_aspira']) ?></b></td>
      <td><?= e($i['forma']) ?><?= $i['forma_ok'] ? ' ✓' : '' ?></td><td><?= e($i['nota']) ?></td><td><?= e(strip_tags(resultados_examen()[$i['resultado']][0] ?? '')) ?></td><td style="width:90px"></td></tr><?php endforeach; ?>
  </table>
  <?php if ($ex['notas'] !== ''): ?><p><b>Observaciones:</b> <?= nl2br(e($ex['notas'])) ?></p><?php endif; ?>
  <div class="firmas" style="grid-template-columns:1fr 1fr 1fr"><div>Examinador</div><div>Director de la escuela</div><div>Secretaría</div></div>
  <p style="font-size:11px;color:#555">Generado el <?= date('d/m/Y H:i') ?> con <?= e(marca()) ?>.</p>
    <?php
    doc_fin();
}

/** Certificados de grado (uno por hoja horizontal) de los aprobados; $solo = id de un inscrito. */
function doc_certificados(array $ex, array $liga, string $volver, int $solo = 0): void
{
    $ins = array_values(array_filter(inscritos_examen((int)$ex['id']), fn($i) => $i['resultado'] === 'aprobado' && $i['certificado'] !== '' && (!$solo || (int)$i['id'] === $solo)));
    $disc = disciplinas_marciales()[$ex['disciplina']] ?? 'Artes marciales';
    $col = colores_cinturon();
    doc_inicio('Certificados de grado ' . fecha_corta($ex['fecha']), $volver);
    ?>
<style>
  @page { size: A4 landscape; margin: 8mm; }
  .cert { position: relative; border: 10px double #1d3b2f; padding: 26px 40px; min-height: 175mm; margin: 0 auto 14px; max-width: 280mm; text-align: center; background: #fffdf7; break-after: page; page-break-after: always; box-sizing: border-box; }
  .cert:last-of-type { break-after: auto; page-break-after: auto; }
  .cert .cab-cert { display: flex; align-items: center; justify-content: center; gap: 14px; }
  .cert h1 { font-size: 34px; letter-spacing: .12em; margin: 14px 0 4px; color: #1d3b2f; }
  .cert .sub { font-size: 15px; color: #444; margin: 0 0 12px; }
  .cert .nombre { font-size: 30px; font-weight: 800; margin: 10px 0; border-bottom: 2px solid #1d3b2f; display: inline-block; padding: 0 30px 4px; }
  .cert .grado { font-size: 22px; margin: 10px 0; }
  .cert .tira { display: inline-block; width: 220px; height: 22px; border-radius: 4px; border: 1px solid #555; vertical-align: middle; margin: 6px 0; }
  .cert .pie-cert { display: grid; grid-template-columns: 1fr 140px 1fr; gap: 20px; align-items: end; margin-top: 26px; }
  .cert .firma { border-top: 1px solid #000; padding-top: 4px; font-size: 13px; }
  .cert .qr { width: 110px; height: 110px; margin: 0 auto; }
  .cert .codigo { font-size: 10px; color: #555; margin-top: 4px; word-break: break-all; }
  .contenedor.doc { max-width: 1120px; }
  @media print { .contenedor.doc { max-width: none; width: auto; padding: 0; margin: 0; } .cert { max-width: none; width: 100%; height: 192mm; min-height: 0; margin: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: space-evenly; align-items: center; } .cert .pie-cert { width: 100%; } .cert > * { margin-top: 0; margin-bottom: 0; } }
</style>
<?php if (!$ins): ?><p>No hay certificados: primero marque a los aprobados y toque <b>Cerrar examen y entregar cinturones</b>.</p><?php endif; ?>
<?php foreach ($ins as $i):
    $c = $i['cinturon_id'] ? fila('SELECT * FROM esc_cinturones WHERE id = ?', [$i['cinturon_id']]) : null;
    $c1 = $c ? ($col[$c['color']][1] ?? '#ccc') : '#ccc';
    $c2 = $c && $c['punta'] !== '' ? ($col[$c['punta']][1] ?? '') : '';
    $fondo = $c2 !== '' ? 'linear-gradient(90deg,' . $c1 . ' 0 72%,' . $c2 . ' 72% 100%)' : $c1; ?>
  <div class="cert">
    <div class="cab-cert"><?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo-grande') ?><div style="text-align:left"><div style="font-size:22px;font-weight:800"><?= e($liga['nombre']) ?></div><div><?= e($disc) ?><?= $liga['ciudad'] !== '' ? ' · ' . e($liga['ciudad']) : '' ?></div></div></div>
    <h1>CERTIFICADO DE GRADO</h1>
    <p class="sub">Se certifica que</p>
    <div class="nombre"><?= e(nombre_alumno($i)) ?></div>
    <p class="sub"><?php if ($i['cedula'] !== ''): ?><span>con cédula</span> <span><?= e(cedula_oculta($i['cedula'])) ?></span>, <?php endif; ?><span>aprobó el examen de grado rendido el</span> <span><?= e(fecha_larga($ex['fecha'])) ?></span><?php if ($ex['lugar'] !== ''): ?> (<span><?= e($ex['lugar']) ?></span>)<?php endif; ?>, <span>y se le otorga el</span></p>
    <div class="grado"><span class="tira" style="background:<?= $fondo ?>"></span><br><b><?= e($i['grado_aspira']) ?></b></div>
    <div class="pie-cert">
      <div class="firma"><?= e($ex['examinador'] ?: 'Examinador') ?><br><small>Examinador</small></div>
      <div><div class="qr" data-url="<?= e(url_certificado($i['certificado'])) ?>"></div><div class="codigo">Código: <b><?= e($i['certificado']) ?></b><br><span>Verificar en</span> <span><?= e(preg_replace(['#^https?://#', '#\?.*$#'], '', url_certificado($i['certificado']))) ?></span></div></div>
      <div class="firma"><?= e($liga['contacto_nombre'] ?: 'Director') ?><br><small>Director de la escuela</small></div>
    </div>
  </div>
<?php endforeach; ?>
<script src="assets/js/qrcode.js"></script>
<script>document.querySelectorAll('.cert .qr').forEach(function (el) { var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-url')); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 3, margin: 0, scalable: true }); });</script>
    <?php
    doc_fin();
}
