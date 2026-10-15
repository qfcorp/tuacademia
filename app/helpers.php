<?php
/*
 * Funciones de ayuda generales.
 */

function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function q(string $sql, array $p = []): PDOStatement
{
    // Antes del primer cambio de datos se activa la bitácora de auditoría
    if (!auditoria_activa() && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) {
        activar_auditoria();
    }
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function fila(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r ?: null;
}

function filas(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll();
}

function valor(string $sql, array $p = [])
{
    return q($sql, $p)->fetchColumn();
}

function ir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function post(string $k, string $def = ''): string
{
    $v = $_POST[$k] ?? $def;
    return is_string($v) ? trim($v) : $def;
}

function get(string $k, string $def = ''): string
{
    $v = $_GET[$k] ?? $def;
    return is_string($v) ? trim($v) : $def;
}

function entero($v, int $min = 0, ?int $max = null): int
{
    $n = (int)$v;
    if ($n < $min) $n = $min;
    if ($max !== null && $n > $max) $n = $max;
    return $n;
}

function entero_o_null($v): ?int
{
    if ($v === null || $v === '') return null;
    return max(0, (int)$v);
}

/* ---------- Mensajes flash ---------- */

function flash(string $msg, string $tipo = 'ok'): void
{
    $_SESSION['flash'][] = [$tipo, $msg];
}

function tomar_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Seguridad de formularios (CSRF) ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verificar(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Formulario vencido. Vuelva atrás, recargue la página e intente de nuevo.');
    }
}

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/* ---------- Usuarios y permisos ---------- */

function usuario(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $id = $_SESSION['uid'] ?? 0;
    $u = $id ? fila('SELECT * FROM usuarios WHERE id = ? AND activo = 1', [$id]) : null;
    // (Anexo A) los usuarios de una cuenta que está en la papelera ya no pueden entrar (aunque tuvieran la sesión abierta)
    if ($u && $u['liga_id'] && function_exists('liga_id_eliminada') && liga_id_eliminada((int)$u['liga_id'])) $u = null;
    // Vista previa: el superadministrador mira el portal del árbitro o del club como esa persona (solo mirar)
    if ($u && $u['rol'] === 'super' && !empty($_SESSION['vista_previa']['uid'])) {
        $otro = fila("SELECT * FROM usuarios WHERE id = ? AND rol IN ('arbitro', 'delegado', 'liga') AND activo = 1", [(int)$_SESSION['vista_previa']['uid']]);
        if ($otro) {
            $otro['vista_super'] = (int)$u['id'];
            $u = $otro;
        }
    }
    // (v16) Combo liga + escuela: el administrador trabaja en la otra cuenta del combo con su mismo usuario
    if ($u && $u['rol'] === 'liga' && !empty($_SESSION['liga_combo']) && ($u['perfil'] ?? 'admin') === 'admin') {
        $otra = (int)$_SESSION['liga_combo'];
        if ($otra !== (int)$u['liga_id'] && (int)valor('SELECT COUNT(*) FROM ligas a JOIN ligas b ON b.id = a.combo_id AND b.combo_id = a.id WHERE a.id = ? AND b.id = ? AND b.activa = 1', [(int)$u['liga_id'], $otra])) {
            $u['liga_propia'] = (int)$u['liga_id'];
            $u['liga_id'] = $otra;
        } else {
            unset($_SESSION['liga_combo']);
        }
    }
    return $u;
}

function es_super(): bool
{
    $u = usuario();
    return $u && $u['rol'] === 'super';
}

function requiere_login(): array
{
    $u = usuario();
    if (!$u) {
        ir('admin.php?p=login');
    }
    return $u;
}

/** Liga con la que se está trabajando en el panel. */
function liga_trabajo(): ?array
{
    $u = usuario();
    if (!$u) return null;
    if ($u['rol'] === 'super') {
        $id = (int)($_SESSION['liga_trabajo'] ?? 0);
    } else {
        $id = (int)$u['liga_id'];
    }
    return $id ? fila('SELECT * FROM ligas WHERE id = ?', [$id]) : null;
}

function requiere_liga(): array
{
    $l = liga_trabajo();
    if (!$l) {
        flash('Primero elija la liga que va a administrar.', 'error');
        ir('admin.php');
    }
    return $l;
}

/* ---------- Textos ---------- */

function slugify(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    return $s !== '' ? $s : 'liga';
}

function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $ini = '';
    foreach ($partes as $p) {
        if ($p !== '') $ini .= mb_substr($p, 0, 1, 'UTF-8');
        if (mb_strlen($ini, 'UTF-8') >= 2) break;
    }
    return mb_strtoupper($ini !== '' ? $ini : '?', 'UTF-8');
}

function nombre_jugador(array $j): string
{
    return trim(($j['nombres'] ?? '') . ' ' . ($j['apellidos'] ?? ''));
}

function fecha_bonita(?string $f): string
{
    if (!$f) return 'Por definir';
    $t = strtotime($f);
    if (!$t) return e($f);
    $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return $dias[(int)date('w', $t)] . ' ' . date('j', $t) . ' ' . $meses[(int)date('n', $t)];
}

function edad(?string $fecha_nac): ?int
{
    if (!$fecha_nac) return null;
    try {
        return (new DateTime($fecha_nac))->diff(new DateTime('today'))->y;
    } catch (Exception) {
        return null;
    }
}

function color_valido(string $c, string $def = '#0b6e4f'): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $def;
}

function nombre_fase(string $f): string
{
    return [
        'regular'   => 'Fase regular',
        'octavos'   => 'Octavos de final',
        'cuartos'   => 'Cuartos de final',
        'semifinal' => 'Semifinal',
        'tercer'    => 'Tercer lugar',
        'final'     => 'Final',
    ][$f] ?? ucfirst($f);
}

function fases(): array
{
    return ['regular', 'octavos', 'cuartos', 'semifinal', 'tercer', 'final'];
}

function nombre_estado_partido(string $e): string
{
    return [
        'programado' => 'Programado',
        'en_vivo'    => 'En vivo',
        'jugado'     => 'Jugado',
        'wo'         => 'W.O.',
        'suspendido' => 'Suspendido',
        'postergado' => 'Postergado',
    ][$e] ?? $e;
}

function nombre_modalidad(string $m): string
{
    return ['F5' => 'Fútbol 5', 'F6' => 'Fútbol 6', 'F7' => 'Fútbol 7', 'F8' => 'Fútbol 8', 'F9' => 'Fútbol 9', 'F11' => 'Fútbol 11', 'OTRO' => 'Fútbol personalizado',
        'INDOR' => 'Indor (fútbol sala)', 'ECUA' => 'Ecuavóley', 'VOLEY' => 'Vóley (6×6)', 'BASQ' => 'Básquet 5×5', 'BASQ3' => 'Básquet 3×3',
        'AUTOX' => 'Autocross (automovilismo)', 'CICLO' => 'Ciclismo de ruta', 'MTB' => 'Ciclismo de montaña (MTB)', 'PISTA' => 'Ciclismo de pista', 'BMX' => 'BMX Racing', 'BMXFS' => 'BMX Freestyle',
        'TENIS' => 'Tenis', 'PADEL' => 'Pádel', 'TMESA' => 'Tenis de mesa', 'SQUASH' => 'Squash', 'AJED' => 'Ajedrez', 'ESPOR' => 'E-sports', 'MARC' => 'Artes marciales', 'BOXEO' => 'Boxeo', 'BOLOS' => 'Bolos', 'NATAC' => 'Natación', 'ATLET' => 'Atletismo', 'BALON' => 'Balonmano (handball)', 'BEISB' => 'Béisbol', 'SOFTB' => 'Softbol',
        'RUTA' => 'Carreras de ruta, trail y cross', 'PESAS' => 'Levantamiento de pesas', 'TRIAT' => 'Triatlón'][$m] ?? $m;
}

/** Valores sugeridos por modalidad (el administrador puede cambiarlos). */
/** (v35) Valores sugeridos por modalidad con las reglas que fijó el superadministrador en cada módulo deportivo. */
function presets_modalidad(): array
{
    $p = presets_modalidad_fabrica();
    if (function_exists('reglas_modulo_modalidad')) foreach ($p as $m => $v) $p[$m] = array_merge($v, array_intersect_key(reglas_modulo_modalidad($m), $v + ['faltas_limite' => 0]));
    return $p;
}

function presets_modalidad_fabrica(): array
{
    // (v7) también puntos, W.O. y los datos de sets/faltas, para que al cambiar de deporte todo quede coherente
    $b = ['max_cambios' => 0, 'num_tiempos' => 2, 'amarillas_suspension' => 3, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 1,
          'puntos_ganado' => 3, 'puntos_empate' => 1, 'puntos_perdido' => 0, 'goles_wo' => 3, 'sets_ganar' => 2, 'puntos_set' => 15, 'puntos_set_final' => 15, 'faltas_limite' => 5];
    return [
        'F5'  => $b + ['jugadores_cancha' => 5,  'min_para_jugar' => 3, 'max_suplentes' => 7,  'minutos_tiempo' => 20, 'min_plantilla' => 7,  'max_plantilla' => 12, 'num_arbitros' => 1],
        'F6'  => $b + ['jugadores_cancha' => 6,  'min_para_jugar' => 4, 'max_suplentes' => 7,  'minutos_tiempo' => 20, 'min_plantilla' => 8,  'max_plantilla' => 14, 'num_arbitros' => 1],
        'F7'  => $b + ['jugadores_cancha' => 7,  'min_para_jugar' => 5, 'max_suplentes' => 7,  'minutos_tiempo' => 25, 'min_plantilla' => 9,  'max_plantilla' => 18, 'num_arbitros' => 2],
        'F8'  => $b + ['jugadores_cancha' => 8,  'min_para_jugar' => 6, 'max_suplentes' => 8,  'minutos_tiempo' => 30, 'min_plantilla' => 10, 'max_plantilla' => 20, 'num_arbitros' => 2],
        'F9'  => $b + ['jugadores_cancha' => 9,  'min_para_jugar' => 7, 'max_suplentes' => 9,  'minutos_tiempo' => 35, 'min_plantilla' => 12, 'max_plantilla' => 22, 'num_arbitros' => 2],
        'F11' => ['max_cambios' => 5, 'num_tiempos' => 2, 'amarillas_suspension' => 4, 'partidos_susp_amarillas' => 1, 'partidos_susp_roja' => 2,
                  'jugadores_cancha' => 11, 'min_para_jugar' => 7, 'max_suplentes' => 12, 'minutos_tiempo' => 45, 'min_plantilla' => 14, 'max_plantilla' => 30, 'num_arbitros' => 3] + $b,
    ] + presets_otros_deportes();
}

function modalidades(): array
{
    return ['F5', 'F6', 'F7', 'F8', 'F9', 'F11', 'OTRO', 'INDOR', 'ECUA', 'VOLEY', 'BASQ', 'BASQ3', 'BALON', 'BEISB', 'SOFTB'];
}

/**
 * Funciones de los árbitros según cuántos pitan el partido (1 a 4).
 * Devuelve [campo_en_partidos => etiqueta].
 */
function roles_arbitro(int $n): array
{
    $n = max(1, min(4, $n));
    return match ($n) {
        1 => ['arbitro_id' => 'Árbitro'],
        2 => ['arbitro_id' => 'Árbitro central', 'asistente1_id' => 'Segundo árbitro'],
        3 => ['arbitro_id' => 'Árbitro central', 'asistente1_id' => 'Asistente 1 (línea)', 'asistente2_id' => 'Asistente 2 (línea)'],
        default => ['arbitro_id' => 'Árbitro central', 'asistente1_id' => 'Asistente 1 (línea)', 'asistente2_id' => 'Asistente 2 (línea)', 'cuarto_id' => 'Cuarto árbitro'],
    };
}

function nombre_calificacion(string $c): string
{
    return ['no' => 'No se califica', 'opcional' => 'Opcional', 'obligatorio' => 'Obligatoria'][$c] ?? $c;
}

/* ---------- Imágenes ---------- */

function url_img(string $ruta): string
{
    return $ruta !== '' ? e($ruta) : '';
}

/** Muestra escudo/logo o un círculo con iniciales si no hay imagen. */
function escudo(?string $ruta, string $nombre, string $color = '#555555', string $clase = 'escudo'): string
{
    if ($ruta) {
        return '<img class="' . e($clase) . '" src="' . e($ruta) . '" alt="' . e($nombre) . '" loading="lazy">';
    }
    return '<span class="' . e($clase) . ' escudo-vacio" style="background:' . e(color_valido($color, '#555555')) . '">' . e(iniciales($nombre)) . '</span>';
}

/** ¿Se puede mostrar la foto? En la página pública solo si el jugador (o su representante) lo autorizó. */
function foto_permitida(array $j): bool
{
    if (empty($j['foto'])) return false;
    if (defined('VISTA_PUBLICA') && array_key_exists('foto_publica', $j) && (int)$j['foto_publica'] !== 1) return false;
    return true;
}

function foto_jugador(array $j, string $clase = 'foto'): string
{
    if (foto_permitida($j)) {
        return '<img class="' . e($clase) . '" src="' . e($j['foto']) . '" alt="' . e(nombre_jugador($j)) . '" loading="lazy">';
    }
    return '<span class="' . e($clase) . ' foto-vacia">' . e(iniciales(nombre_jugador($j))) . '</span>';
}

/**
 * Sube una imagen desde un formulario y la guarda (redimensionada) en uploads/<carpeta>/.
 * Devuelve la ruta relativa (uploads/...) o null si no se envió archivo.
 */
function subir_imagen(string $campo, string $carpeta, int $max = 800): ?string
{
    if (empty($_FILES[$campo]) || is_array($_FILES[$campo]['name'] ?? null) || ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $nombre = guardar_imagen($_FILES[$campo], UPLOADS_DIR . '/' . $carpeta, $max);
    return 'uploads/' . $carpeta . '/' . $nombre;
}

/**
 * Fotos INTERNAS (no públicas): se guardan fuera de la carpeta web, en data/fotos/.
 * Acepta un campo con varias fotos (name="fotos[]" multiple). Devuelve las rutas relativas a data/.
 */
function subir_fotos_privadas(string $campo, int $max = 1600): array
{
    if (empty($_FILES[$campo])) return [];
    $f = $_FILES[$campo];
    $lista = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            $lista[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
    } else {
        $lista[] = $f;
    }
    $rutas = [];
    $sub = 'fotos/' . date('Ym');
    foreach ($lista as $archivo) {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $rutas[] = $sub . '/' . guardar_imagen($archivo, RAIZ . '/data/' . $sub, $max);
    }
    return $rutas;
}

/** Valida, corrige rotación, redimensiona y guarda una imagen subida. Devuelve el nombre del archivo. */
function guardar_imagen(array $f, string $dir, int $max): string
{
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la imagen (código ' . $f['error'] . '). Puede que sea muy pesada.');
    }
    if ($f['size'] > 12 * 1024 * 1024) {
        throw new RuntimeException('La imagen pesa más de 12 MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($tipos[$info[2]])) {
        throw new RuntimeException('El archivo no es una imagen válida (use JPG, PNG o WEBP).');
    }
    $ext = $tipos[$info[2]];
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $nombre = date('Ymd') . '_' . bin2hex(random_bytes(6));

    // Si el servidor tiene GD, se redimensiona y se corrige la rotación de fotos de celular.
    if (function_exists('imagecreatetruecolor') && $ext !== 'gif') {
        $img = match ($ext) {
            'jpg'  => @imagecreatefromjpeg($f['tmp_name']),
            'png'  => @imagecreatefrompng($f['tmp_name']),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false,
            default => false,
        };
        if ($img) {
            if ($ext === 'jpg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($f['tmp_name']);
                $o = (int)($exif['Orientation'] ?? 1);
                if ($o === 3) $img = imagerotate($img, 180, 0);
                elseif ($o === 6) $img = imagerotate($img, -90, 0);
                elseif ($o === 8) $img = imagerotate($img, 90, 0);
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $escala = min(1, $max / max($w, $h));
            $nw = max(1, (int)round($w * $escala));
            $nh = max(1, (int)round($h * $escala));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($ext === 'jpg') {
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            } else {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            if ($ext === 'jpg') {
                $ok = imagejpeg($dst, "$dir/$nombre.jpg", 85);
                $final = "$nombre.jpg";
            } elseif ($ext === 'webp' && function_exists('imagewebp')) {
                $ok = imagewebp($dst, "$dir/$nombre.webp", 85);
                $final = "$nombre.webp";
            } else {
                $ok = imagepng($dst, "$dir/$nombre.png", 6);
                $final = "$nombre.png";
            }
            if ($ok) {
                return $final;
            }
        }
    }
    // Sin GD: se guarda tal cual.
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nombre.$ext")) {
        throw new RuntimeException('No se pudo guardar la imagen en el servidor (revise permisos de la carpeta).');
    }
    return $nombre . '.' . $ext;
}

function borrar_imagen(?string $ruta): void
{
    if (!$ruta || !str_starts_with($ruta, 'uploads/')) return;
    $abs = PUBLIC_DIR . '/' . $ruta;
    if (is_file($abs) && !str_contains($ruta, '..')) {
        @unlink($abs);
    }
}

function borrar_foto_privada(?string $ruta): void
{
    if (!$ruta || !str_starts_with($ruta, 'fotos/') || str_contains($ruta, '..')) return;
    $abs = RAIZ . '/data/' . $ruta;
    if (is_file($abs)) @unlink($abs);
}

/* ---------- Varios v2 ---------- */

function es_arbitro(): bool
{
    $u = usuario();
    return $u && $u['rol'] === 'arbitro';
}

function dinero($v): string
{
    return ((float)$v < 0 ? '-' : '') . '$' . number_format(abs((float)$v), 2, '.', ',');
}

function hora_valida(string $h): string
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : '';
}

function nombre_evento(string $t, string $deporte = ''): string
{
    if ($deporte === 'balonmano') { // (v36)
        $b = ['penal' => 'Gol de 7 metros', 'amarilla' => 'Amonestación', 'roja' => 'Descalificación'];
        if (isset($b[$t])) return $b[$t];
    }
    if ($deporte === 'beisbol') {
        $b = ['amarilla' => 'Advertencia', 'roja' => 'Expulsión'];
        if (isset($b[$t])) return $b[$t];
    }
    if ($deporte === 'basquet') {
        $b = ['p1' => '1 punto (tiro libre)', 'p2' => '2 puntos', 'p3' => '3 puntos (triple)', 'falta' => 'Falta personal', 'amarilla' => 'Falta técnica', 'roja' => 'Expulsión'];
        if (isset($b[$t])) return $b[$t];
    }
    return ['gol' => 'Gol', 'penal' => 'Gol de penal', 'autogol' => 'Autogol', 'amarilla' => 'Tarjeta amarilla', 'roja' => 'Tarjeta roja',
        'p1' => '1 punto', 'p2' => '2 puntos', 'p3' => '3 puntos', 'falta' => 'Falta personal', 'asistencia' => 'Asistencia',
        'exclusion' => 'Exclusión de 2 minutos', 'carrera' => 'Carrera', 'jonron' => 'Jonrón', 'hit' => 'Hit', 'error' => 'Error', 'ponche' => 'Ponche (strikeout)'][$t] ?? $t;
}

function icono_evento(string $t, string $deporte = ''): string
{
    if ($deporte === 'basquet' && $t === 'amarilla') return '<span class="ev-tec" title="Falta técnica">T</span>';
    if ($deporte === 'basquet' && $t === 'roja') return '<span class="tarjeta-ro" title="Expulsión"></span>';
    if ($deporte === 'balonmano' && $t === 'gol') return '🤾'; // (v36)
    if ($deporte === 'balonmano' && $t === 'penal') return '🤾<small>(7 m)</small>';
    if ($deporte === 'beisbol' && $t === 'amarilla') return '<span class="ev-tec" title="Advertencia">⚠</span>';
    return [
        'gol' => '⚽', 'penal' => '⚽<small>(p)</small>', 'autogol' => '⚽<small>(a.g.)</small>',
        'amarilla' => '<span class="tarjeta-am"></span>', 'roja' => '<span class="tarjeta-ro"></span>',
        'p1' => '🏀<small>+1</small>', 'p2' => '🏀<small>+2</small>', 'p3' => '🏀<small>+3</small>', 'falta' => '✋', 'asistencia' => '<span class="ev-asis">A</span>',
        'exclusion' => '⏱<small>2 min</small>', 'carrera' => '⚾', 'jonron' => '💥<small>HR</small>', 'hit' => '🏏', 'error' => '❌<small>E</small>', 'ponche' => '<b>K</b>',
    ][$t] ?? e($t);
}

/** Todos los tipos de evento que existen (de todos los deportes). Para un campeonato use tipos_evento_de(). */
function tipos_evento(): array
{
    return ['gol', 'penal', 'autogol', 'p1', 'p2', 'p3', 'asistencia', 'amarilla', 'roja', 'falta', 'exclusion', 'carrera', 'jonron', 'hit', 'error', 'ponche'];
}

function nuevo_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Dirección completa del sitio (para enlaces que se envían por WhatsApp). */
function url_base(): string
{
    // (v33) Desde la tarea automática (sin navegador) se usa la dirección pública recordada, para que los enlaces de correos y avisos sean correctos
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        static $guardada = null;
        if ($guardada === null) { try { $guardada = (string)ajuste('url_sitio', ''); } catch (Throwable) { $guardada = ''; } }
        if ($guardada !== '' && preg_match('#^https?://[a-z0-9.\-:]+/$#i', $guardada)) return $guardada;
    }
    $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '' && str_contains($_SERVER['HTTP_CF_VISITOR'], 'https'));
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host);
    return ($https ? 'https' : 'http') . '://' . $host . '/';
}

/** Opciones <option> con los equipos de la liga. */
function opciones_equipos(array $equipos, $seleccionado = null): string
{
    $h = '';
    foreach ($equipos as $e) {
        $h .= '<option value="' . (int)$e['id'] . '"' . ((int)$seleccionado === (int)$e['id'] ? ' selected' : '') . '>' . e($e['nombre']) . '</option>';
    }
    return $h;
}
