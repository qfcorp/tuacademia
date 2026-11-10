<?php
/* (9 nov 2026) Factura electrónica con TuFactura (módulo «facturacion», apagado de fábrica): app/factura_tukan.php.
 * Apagado: explica TuFactura y cómo pedirla. Encendido: conectar la clave, facturar los cobros y ver las facturas. */
$liga = requiere_liga();
?>
<h1>🧾 Factura electrónica</h1>
<?php FacturaTukan::pagina($liga);
