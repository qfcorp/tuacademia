<?php
/*
 * (v28) VIDEOS DEL JUGADOR Y DEL ALUMNO (módulo "videos")
 *  - La liga o escuela sube un video corto (máximo 2 minutos) a un jugador o alumno: su gol, una jugada, la técnica…
 *  - En su portal (jugador: mi.php · padres: familia.php) le aparece "🎬 Tiene un video nuevo", lo ve y lo descarga.
 *  - Se borra solo a los 10 días (se le avisa la fecha).
 *  - Dónde se guardan: en el mismo servidor (data/videos) o en la LAPTOP con disco grande (Ajustes → Videos),
 *    publicada por el túnel de Cloudflare con el receptor de videos (vps/laptop_videos.sh).
 */
require_once APP_DIR . '/videos_receptor.php';

const VIDEO_MAX_SEG = 120;
const VIDEO_DIAS = 10;
const VIDEO_MAX_MB = 400;

/** 'laptop' si está configurado el receptor externo; si no, 'local'. */
function videos_modo(): string
{
    return (string)ajuste('videos_url') !== '' && (string)ajuste('videos_clave') !== '' ? 'laptop' : 'local';
}

/** Clave para firmar (la del receptor de la laptop, o una propia para el servidor). */
function videos_clave(string $modo = ''): string
{
    $modo = $modo ?: videos_modo();
    if ($modo === 'laptop') return (string)ajuste('videos_clave');
    $c = (string)ajuste('videos_clave_local');
    if ($c === '') { $c = bin2hex(random_bytes(24)); guardar_ajuste('videos_clave_local', $c); }
    return $c;
}

function videos_dir_local(): string
{
    return RAIZ . '/data/videos';
}

/** Dirección del receptor (para subir, ver y borrar). */
function url_receptor(string $modo): string
{
    return $modo === 'laptop' ? rtrim((string)ajuste('videos_url'), '/') . '/index.php' : url_base() . 'video.php';
}

/** Enlace firmado a una acción del receptor. */
function enlace_video(array $v, string $a, int $minutos = 30, array $extra = []): string
{
    $exp = time() + $minutos * 60;
    $q = ['a' => $a, 'f' => $v['archivo'], 'exp' => $exp, 'sig' => vr_firma(videos_clave($v['servidor']), $a . '|' . $v['archivo'] . '|' . $exp)] + $extra;
    return url_receptor($v['servidor']) . '?' . http_build_query($q);
}

/** Persona a la que pertenece el video: ['nombre', 'liga_id', 'telefono', 'portal']. */
function persona_video(string $tipo, int $id): ?array
{
    if ($tipo === 'jugador') {
        $j = fila('SELECT j.*, e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ?', [$id]);
        return $j ? ['nombre' => trim($j['nombres'] . ' ' . $j['apellidos']), 'liga_id' => (int)$j['liga_id'], 'telefono' => (string)$j['representante_telefono'], 'portal' => url_base() . 'mi.php'] : null;
    }
    $a = fila('SELECT * FROM esc_alumnos WHERE id = ?', [$id]);
    return $a ? ['nombre' => trim($a['nombres'] . ' ' . $a['apellidos']), 'liga_id' => (int)$a['liga_id'], 'telefono' => (string)$a['representante_telefono'], 'portal' => url_base() . 'familia.php'] : null;
}

/** Empieza un video: crea el registro y devuelve los datos para que el navegador lo suba por pedazos. */
function iniciar_video(array $liga, string $tipo, int $persona_id, string $titulo, string $nota, string $ext, int $bytes, ?int $partido_id = null): array
{
    if (!in_array($tipo, ['jugador', 'alumno'], true)) throw new RuntimeException('Tipo no válido.');
    $p = persona_video($tipo, $persona_id);
    if (!$p || $p['liga_id'] !== (int)$liga['id']) throw new RuntimeException('Elija el jugador o alumno.');
    $ext = strtolower($ext);
    if (!in_array($ext, ['mp4', 'mov', 'webm', 'm4v'], true)) throw new RuntimeException('Formato no válido: use un video MP4 o MOV del celular.');
    if ($bytes <= 0 || $bytes > VIDEO_MAX_MB * 1048576) throw new RuntimeException('El video pesa más de ' . VIDEO_MAX_MB . ' MB.');
    $modo = videos_modo();
    $archivo = bin2hex(random_bytes(12)) . '.' . $ext;
    q('INSERT INTO videos (liga_id, tipo, persona_id, partido_id, titulo, nota, archivo, servidor, bytes, estado, subido_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        $liga['id'], $tipo, $persona_id, $partido_id, mb_substr(trim($titulo) ?: 'Video', 0, 80), mb_substr(trim($nota), 0, 300), $archivo, $modo, $bytes, 'subiendo', mb_substr((string)(usuario()['nombre'] ?? ''), 0, 80)]);
    $id = (int)db()->lastInsertId();
    $v = fila('SELECT * FROM videos WHERE id = ?', [$id]);
    $exp = time() + 3 * 3600;
    $c = videos_clave($modo);
    return ['id' => $id, 'pedazo' => VR_PEDAZO, 'max_seg' => VIDEO_MAX_SEG,
        'subir' => url_receptor($modo) . '?' . http_build_query(['a' => 'subir', 'f' => $archivo, 'exp' => $exp, 'sig' => vr_firma($c, 'subir|' . $archivo . '|' . $exp)]),
        'fin' => url_receptor($modo) . '?' . http_build_query(['a' => 'fin', 'f' => $archivo, 'exp' => $exp, 'sig' => vr_firma($c, 'fin|' . $archivo . '|' . $exp)])];
}

/** El navegador terminó de subir: se verifica la firma del receptor y el video queda listo por 10 días. */
function terminar_video(array $liga, int $id, int $bytes, string $duracion, string $firma): array
{
    $v = fila("SELECT * FROM videos WHERE id = ? AND liga_id = ? AND estado = 'subiendo'", [$id, $liga['id']]) ?? throw new RuntimeException('Video no encontrado.');
    if (!hash_equals(vr_firma(videos_clave($v['servidor']), 'listo|' . $v['archivo'] . '|' . $bytes . '|' . $duracion), $firma)) throw new RuntimeException('No se pudo confirmar el video. Vuelva a subirlo.');
    $expira = date('Y-m-d H:i:s', strtotime('+' . VIDEO_DIAS . ' days'));
    q("UPDATE videos SET estado = 'listo', bytes = ?, duracion = ?, listo_en = ?, expira = ? WHERE id = ?", [$bytes, $duracion === '' ? null : (float)$duracion, date('Y-m-d H:i:s'), $expira, $id]);
    $v = fila('SELECT * FROM videos WHERE id = ?', [$id]);
    $p = persona_video($v['tipo'], (int)$v['persona_id']);
    bitacora_evento('videos', 'Subió el video «' . $v['titulo'] . '» para ' . ($p['nombre'] ?? ''), (int)$liga['id'], $id);
    // Aviso por Telegram a los padres (si la familia tiene Telegram) — los jugadores lo ven en su portal
    return $v;
}

/** Borra un video (archivo y registro queda como "borrado"). */
function borrar_video(array $v): void
{
    if ($v['servidor'] === 'local') {
        if (vr_nombre_valido($v['archivo'])) @unlink(videos_dir_local() . '/' . $v['archivo']);
    } else {
        // El receptor de la laptop también borra solo a los 10 días; esto lo borra antes si se puede
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        @file_get_contents(enlace_video($v, 'borrar', 5), false, $ctx);
    }
    q("UPDATE videos SET estado = 'borrado', borrado_en = ? WHERE id = ?", [date('Y-m-d H:i:s'), $v['id']]);
}

/** Tarea diaria: borra los videos vencidos y los que se quedaron a medio subir. */
function limpiar_videos(): int
{
    $n = 0;
    foreach (filas("SELECT * FROM videos WHERE (estado = 'listo' AND expira <> '' AND expira < ?) OR (estado = 'subiendo' AND creado < ?)", [date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('-1 day'))]) as $v) {
        borrar_video($v);
        $n++;
    }
    if (is_dir(videos_dir_local())) vr_limpiar(videos_dir_local(), VIDEO_DIAS + 1);
    return $n;
}

/** Videos vigentes de una persona (para su portal). */
function videos_de(string $tipo, int $persona_id): array
{
    return filas("SELECT * FROM videos WHERE tipo = ? AND persona_id = ? AND estado = 'listo' AND expira >= ? ORDER BY id DESC", [$tipo, $persona_id, date('Y-m-d H:i:s')]);
}

function texto_bytes(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
}

/** Días que le quedan a un video (0 = se borra hoy). */
function dias_video(array $v): int
{
    return max(0, (int)floor((strtotime($v['expira']) - time()) / 86400));
}

/** Tarjeta "🎬 Tiene un video nuevo" para el portal del jugador o de los padres. */
function html_videos_portal(string $tipo, int $persona_id, string $nombre = ''): string
{
    $vs = videos_de($tipo, $persona_id);
    if (!$vs) return '';
    $nuevos = count(array_filter($vs, fn($v) => $v['visto_en'] === null || $v['visto_en'] === ''));
    ob_start(); ?>
    <div class="tarjeta videos-portal" style="border:2px solid var(--acento)">
      <h2 style="margin-top:0">🎬 <?= $nuevos ? ($nuevos === 1 ? 'Tiene un video nuevo' : 'Tiene ' . $nuevos . ' videos nuevos') : 'Sus videos' ?><?= $nombre !== '' ? ' <small class="suave">· ' . e($nombre) . '</small>' : '' ?></h2>
      <?php foreach ($vs as $v): $d = dias_video($v); ?>
        <div class="video-item">
          <video controls preload="metadata" playsinline src="<?= e(enlace_video($v, 'ver', 180)) ?>" style="width:100%;max-width:520px;border-radius:10px;background:#000"></video>
          <p style="margin:.4rem 0"><b><?= e($v['titulo']) ?></b><?= ($v['visto_en'] ?? '') === '' ? ' <span class="etiqueta roja">nuevo</span>' : '' ?><?= $v['nota'] !== '' ? '<br><span class="suave">' . e($v['nota']) . '</span>' : '' ?></p>
          <div class="acciones"><a class="btn" href="<?= e(enlace_video($v, 'ver', 180, ['d' => 1, 'nombre' => slugify($v['titulo'])])) ?>">⬇ Descargar</a>
            <span class="aviso aviso-info" style="margin:0;padding:6px 10px">⏳ Se borra el <b><?= e(fecha_bonita(substr($v['expira'], 0, 10))) ?></b> (<?= $d === 0 ? 'hoy' : 'en ' . $d . ' día' . ($d === 1 ? '' : 's') ?>). Descárguelo para guardarlo.</span></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    q("UPDATE videos SET visto_en = ? WHERE tipo = ? AND persona_id = ? AND estado = 'listo' AND (visto_en IS NULL OR visto_en = '')", [date('Y-m-d H:i:s'), $tipo, $persona_id]);
    return (string)ob_get_clean();
}

/** Espacio que ocupan los videos vigentes de una cuenta. */
function uso_videos(int $lid): array
{
    $r = fila("SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS b FROM videos WHERE liga_id = ? AND estado = 'listo'", [$lid]);
    return ['n' => (int)$r['n'], 'bytes' => (int)$r['b']];
}
