<?php
/* (9 nov 2026) Factura electrónica con TuFactura (módulo «facturacion», apagado de fábrica): app/factura_tukan.php.
 * Apagado: explica TuFactura y cómo pedirla. Encendido: conectar la clave, facturar los cobros y ver las facturas. */
$liga = requiere_liga();
// (10 nov 2026, identidad gráfica) El PDF de la factura y el guardar (redirige) van sin pantalla; lo demás con la barra y el menú del panel
if (get('pdf') !== '' || es_post()) {
    FacturaTukan::pagina($liga);
    return;
}
cabecera_admin('Factura electrónica');
?>
<div class="tk-titulo-pagina"><div><h1>🧾 Factura electrónica</h1><p>Facturas electrónicas del SRI de sus cobros, con TuFactura</p></div></div>
<?php FacturaTukan::pagina($liga);
pie_admin();
