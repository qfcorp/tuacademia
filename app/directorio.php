<?php
/*
 * (v20) DIRECTORIO PÚBLICO DE CLIENTES
 * Menús "🏆 Ligas" y "🎓 Escuelas" en la cabecera de la página principal, y las páginas
 * /ligas (todas las ligas) y /nuestras-escuelas (todas las escuelas).
 * Aparecen las ligas y escuelas activas, que no son demostración y que tienen marcado
 * "Mostrar en el directorio público" (superadministrador → Ligas y escuelas → Editar).
 */

/** Clientes publicados de un tipo ('liga' o 'escuela'), con sus números para mostrar. */
function clientes_directorio(string $tipo, int $limite = 0): array
{
    static $cache = [];
    $k = $tipo . ':' . $limite;
    if (isset($cache[$k])) return $cache[$k];
    $es = $tipo === 'escuela';
    $sql = "SELECT l.id, l.nombre, l.slug, l.logo, l.color, l.ciudad, l.modalidad, l.tipo, l.deporte_escuela,
              (SELECT COUNT(*) FROM equipos e WHERE e.liga_id = l.id) AS n_equipos,
              (SELECT COUNT(*) FROM torneos t WHERE t.liga_id = l.id AND t.estado <> 'finalizado') AS n_torneos
            FROM ligas l
            WHERE l.activa = 1 AND l.es_demo = 0 AND COALESCE(l.en_directorio, 1) = 1 AND "
         . ($es ? "l.tipo = 'escuela'" : "COALESCE(l.tipo, 'liga') <> 'escuela'")
         . " ORDER BY l.nombre COLLATE NOCASE" . ($limite > 0 ? ' LIMIT ' . $limite : '');
    try {
        $filas = filas($sql);
    } catch (Throwable $e) {
        $filas = []; // base todavía sin la columna (antes de migrar): no romper la portada
    }
    if ($es) {
        foreach ($filas as &$f) {
            try {
                $f['n_alumnos'] = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo'", [$f['id']]);
                $f['n_grupos'] = (int)valor('SELECT COUNT(*) FROM esc_grupos WHERE liga_id = ?', [$f['id']]);
            } catch (Throwable $e) {
                $f['n_alumnos'] = 0; $f['n_grupos'] = 0;
            }
        }
        unset($f);
    }
    return $cache[$k] = $filas;
}

/** Texto corto de cada cliente: "Fútbol 7 · Quito" o "Fútbol · Quito". */
function detalle_cliente(array $c): string
{
    $partes = [];
    if (($c['tipo'] ?? '') === 'escuela') {
        // (v21) Escuelas multideporte: "Fútbol, Natación y Tenis"
        $deps = function_exists('deportes_de_escuela') && !empty($c['id']) ? deportes_de_escuela($c) : [];
        $partes[] = count($deps) > 1 ? texto_deportes($deps) : (deportes_escuela()[$c['deporte_escuela'] ?? ''] ?? 'Escuela deportiva');
    } else {
        $partes[] = nombre_modalidad((string)$c['modalidad']);
    }
    if (($c['ciudad'] ?? '') !== '') $partes[] = $c['ciudad'];
    return implode(' · ', $partes);
}

/** Menús desplegables de la cabecera pública (Ligas y Escuelas). */
function html_menu_clientes(): string
{
    if (liga_por_dominio()) return ''; // en el dominio propio de una liga no se muestran otros clientes
    $h = '<nav class="menu-clientes" aria-label="Ligas y escuelas que usan ' . e(marca()) . '">';
    foreach ([['liga', '🏆', 'Ligas', 'ligas', 'Todas las ligas', 'Su liga puede ser la primera en aparecer aquí.'],
              ['escuela', '🎓', 'Escuelas', 'nuestras-escuelas', 'Todas las escuelas', 'Su escuela puede ser la primera en aparecer aquí.']] as [$tipo, $ico, $tit, $ruta, $todas, $vacio]) {
        $lista = clientes_directorio($tipo);
        $h .= '<details class="mc-grupo"><summary>' . $ico . ' ' . $tit . ($lista ? ' <span class="mc-n">' . count($lista) . '</span>' : '') . '</summary><div class="mc-panel">';
        if ($lista) {
            foreach (array_slice($lista, 0, 8) as $c) {
                $h .= '<a class="mc-item" href="index.php?l=' . e($c['slug']) . '">' . escudo($c['logo'], $c['nombre'], $c['color'], 'mini') . '<span><b>' . e($c['nombre']) . '</b><small>' . e(detalle_cliente($c)) . '</small></span></a>';
            }
            $h .= '<a class="mc-todas" href="' . $ruta . '">' . e($todas) . ' (' . count($lista) . ') →</a>';
        } else {
            $h .= '<p class="mc-vacio">' . e($vacio) . '</p><a class="mc-todas" href="' . e(url_empezar($tipo === 'escuela' ? 'escuela' : '')) . '">' . e(texto_empezar(true)) . ' →</a>';
        }
        $h .= '</div></details>';
    }
    return $h . '</nav>' . <<<'JS'
<script>
document.addEventListener('click', function (ev) {
  document.querySelectorAll('.mc-grupo[open]').forEach(function (d) { if (!d.contains(ev.target)) d.removeAttribute('open'); });
});
document.querySelectorAll('.mc-grupo').forEach(function (d) {
  d.addEventListener('toggle', function () { if (d.open) document.querySelectorAll('.mc-grupo[open]').forEach(function (o) { if (o !== d) o.removeAttribute('open'); }); });
});
</script>
JS;
}
