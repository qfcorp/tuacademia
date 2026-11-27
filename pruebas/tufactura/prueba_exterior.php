<?php
declare(strict_types=1);

/**
 * Ventas al EXTERIOR: sin IVA en ningún producto (27 nov 2026, pedido de Dimitry).
 * Revisa la regla (país, ciudad, dirección, tipo 08) y TukanFactura::armar (todo a 0 %, el total es lo que pagó el cliente).
 * No necesita base de datos grande ni TuFactura. Correr:  php pruebas/tufactura/prueba_exterior.php
 */
require dirname(__DIR__, 2) . '/app/TukanFactura.php';

$bien = 0; $mal = 0;
function ok(bool $c, string $que, mixed $ver = null): void
{
    global $bien, $mal;
    if ($c) { $bien++; return; }
    $mal++;
    echo "  FALLA: $que\n";
    if ($ver !== null) echo '    ' . json_encode($ver, JSON_UNESCAPED_UNICODE) . "\n";
}

// ---- La regla
$casos = [
    [['pais' => 'Ecuador'], false, 'país Ecuador'],
    [['pais' => 'ec'], false, 'país EC'],
    [['pais' => 'ECU'], false, 'país ECU'],
    [['pais' => 'Paraguay'], true, 'país Paraguay'],
    [['pais' => 'PY'], true, 'país PY (código)'],
    [['pais' => 'Estados Unidos', 'direccion' => 'Quito'], true, 'el país manda sobre la dirección'],
    [['pais' => 'Ecuador', 'direccion' => 'Asunción, Paraguay'], false, 'el país Ecuador manda sobre la dirección'],
    [['ciudad' => 'Asunción', 'direccion' => 'Av. Mariscal López 123, Paraguay'], true, 'dirección que nombra Paraguay'],
    [['direccion' => 'Calle 80 #20-10, Bogotá, Colombia'], true, 'dirección que nombra Colombia'],
    [['direccion' => 'Madrid, España'], true, 'España con tilde/ñ'],
    [['direccion' => 'Miami, USA'], true, 'USA'],
    [['direccion' => 'Av. Amazonas y Colón, Quito'], false, 'dirección ecuatoriana sin escribir Ecuador'],
    [['direccion' => 'Guayaquil, Ecuador'], false, 'dirección que dice Ecuador'],
    [['direccion' => 'Av. Colombia y Peru, Quito, Ecuador'], false, 'calle con nombre de país pero dice Ecuador'],
    [['direccion' => ''], false, 'sin datos: con IVA'],
    [[], false, 'cliente vacío: con IVA'],
    [['tipo_id' => '08'], true, 'identificación del exterior (08) sin más datos'],
    [['tipo_id' => '08', 'direccion' => 'Cuenca, Ecuador'], false, '08 pero vive en Ecuador'],
    [['tipo_id' => '05', 'identificacion' => '1710034065'], false, 'cédula ecuatoriana'],
    [['direccion' => 'Av. Peru 55, Lima, Perú'], true, 'Perú con tilde'],
    [['direccion' => 'Calle Paraguaná, Quito'], false, 'palabra parecida (no es el país)'],
];
foreach ($casos as [$cli, $espera, $nombre]) {
    ok(TukanFactura::esExterior($cli) === $espera, 'enlace: ' . $nombre, $cli);
}

// ---- Enlace: armar
$pdo = new PDO('sqlite::memory:');
$tf = new TukanFactura($pdo, str_repeat('k', 32), 'Prueba');
$con = ['precios_con_iva' => 1, 'iva' => 15];
$items = [['descripcion' => 'Suscripción', 'cantidad' => 1, 'precio' => 115], ['descripcion' => 'Soporte', 'cantidad' => 2, 'precio' => 10, 'iva' => 15]];
$local = $tf->armar($con, 'venta', '1', ['identificacion' => '1710034065', 'nombre' => 'Ana', 'direccion' => 'Quito'], $items);
ok($local['items'][0]['iva'] === 15 && $local['items'][0]['precio'] < 115, 'cliente de Ecuador: con IVA y precio sin IVA separado', $local['items']);
$ext = $tf->armar($con, 'venta', '2', ['identificacion' => 'PY1234567', 'nombre' => 'Federación Paraguaya', 'pais' => 'Paraguay', 'ciudad' => 'Asunción'], $items);
ok($ext['items'][0]['iva'] === 0 && $ext['items'][1]['iva'] === 0, 'cliente de Paraguay: todas las líneas a 0 %', $ext['items']);
ok($ext['items'][0]['precio'] === 115.0 && $ext['items'][1]['precio'] === 10.0, 'cliente de Paraguay: no se le quita IVA (el total es lo que pagó)', $ext['items']);
ok(($ext['cliente']['pais'] ?? '') === 'Paraguay' && ($ext['cliente']['ciudad'] ?? '') === 'Asunción', 'el país y la ciudad viajan a TuFactura', $ext['cliente']);
$ext2 = $tf->armar(['precios_con_iva' => 0, 'iva' => 15], 'venta', '3', ['identificacion' => 'AB123456', 'nombre' => 'Cliente', 'direccion' => 'Madrid, España'], [['descripcion' => 'X', 'precio' => 50, 'iva' => 15]]);
ok($ext2['items'][0]['iva'] === 0, 'una línea que traía 15 % sale a 0 % si la dirección es de España', $ext2['items']);
$ext3 = $tf->armar($con, 'venta', '4', ['pais' => 'Chile'], [['descripcion' => 'X', 'precio' => 30]]);
ok($ext3['cliente']['identificacion'] === '9999999999999' && $ext3['items'][0]['iva'] === 0, 'exterior sin identificación: consumidor final pero igual sin IVA', $ext3);

echo "\nExterior sin IVA — correctas: $bien · fallas: $mal\n";
exit($mal ? 1 : 0);
