<?php
/*
 * (10 nov 2026) IDENTIDAD GRÁFICA COMÚN DE TUKÁN — piezas del Inicio (dashboard) que son iguales en todos los programas:
 * saludo según la hora de Ecuador, tira con el aviso más importante y tarjetas de sección. El diseño está en
 * public/assets/tukan-ui.css (kit común, idéntico en todos los programas) y el color del programa en tk_color_programa() (layout.php).
 * Ver docs/LINEA_GRAFICA.md.
 */

/** Saludo del Inicio: «🌙 Buenas noches, Nombre 👋» según la hora de Ecuador + una línea de qué es esta pantalla. */
function tk_saludo(string $linea): string
{
    $u = usuario();
    $h = (int)date('G'); // la zona del programa es America/Guayaquil (bootstrap.php)
    [$ico, $txt] = $h >= 5 && $h < 12 ? ['☀️', 'Buenos días'] : ($h >= 12 && $h < 19 ? ['🌤️', 'Buenas tardes'] : ['🌙', 'Buenas noches']);
    $nombre = $u ? trim((string)strtok(trim((string)$u['nombre']), ' ')) : '';
    return '<div class="tk-saludo"><h1>' . $ico . ' <span class="tk-hora">' . $txt . '</span>' . ($nombre !== '' ? ', ' . e($nombre) : '') . ' 👋</h1><p>' . e($linea) . '</p></div>';
}

/**
 * Tira del Inicio con el aviso más importante: la demo (días que quedan, manual y «Ver Mi plan»); si no, la novedad sin ver.
 * La suscripción vencida o en gracia y los términos por aceptar ya salen arriba en todas las pantallas (layout.php).
 */
function tk_tira_inicio(array $liga): string
{
    if (cuenta_en_demo($liga)) return html_tarjeta_demo($liga);
    $n = novedades_sin_ver();
    if (!$n) return '';
    $u = usuario();
    $vista = (int)valor('SELECT novedad_vista FROM usuarios WHERE id = ?', [$u['id']]);
    foreach (novedades_para($liga, 10) as $x) {
        if ((int)$x['id'] > $vista) {
            return '<div class="tk-tira banner-novedad"><span>🆕 <b>Novedad:</b> ' . e($x['titulo']) . ($n > 1 ? ' <small>y ' . ($n - 1) . ' más</small>' : '') . '</span><a class="tk-boton chico" href="admin.php?p=novedades">Ver novedades</a></div>';
        }
    }
    return '';
}

/** Cabeza de una tarjeta de sección: título en mayúsculas con emoji, descripción y botón «Ver todo…» (opcional). */
function tk_cabeza(string $titulo, string $desc, string $url = '', string $boton = '', string $clase_boton = ''): string
{
    return '<div class="tk-tarjeta-cabeza"><div><h2>' . $titulo . '</h2>' . ($desc !== '' ? '<p>' . e($desc) . '</p>' : '') . '</div>'
        . ($url !== '' ? '<a class="tk-boton chico' . ($clase_boton !== '' ? ' ' . $clase_boton : '') . '" href="' . e($url) . '">' . e($boton) . '</a>' : '') . '</div>';
}

/** Cifra grande del dashboard (tarjeta oscura). $tipo: programa | ok | mal | aviso | '' (azul). */
function tk_cifra(string $titulo, string $valor, string $detalle = '', string $tipo = ''): string
{
    return '<div class="tk-cifra' . ($tipo !== '' ? ' ' . $tipo : '') . '"><div class="tk-cifra-titulo">' . e($titulo) . '</div><div class="tk-cifra-valor">' . $valor . '</div>'
        . ($detalle !== '' ? '<div class="tk-cifra-detalle">' . $detalle . '</div>' : '') . '</div>';
}
