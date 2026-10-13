# Pruebas de la v38 (apps Android y Google Play)
- `simulador_google.php`: simula OAuth y la API de Google Play (compras, reconocer, crear suscripciones, precios por país).
- `prueba38.py`: 100 verificaciones (precios, productos, compras, renovaciones, deportes, permisos, APK, assetlinks).
- `navegador38.py`: Chromium real con la Digital Goods API simulada (guardián de la cookie y pago desde Mi plan).
- `recorrido.py`: abre todas las páginas (súper, liga, escuela; web, app de Play y APK) buscando errores PHP.
- `capturas.py`: capturas de teléfono para la ficha de Google Play.
Resultado 12-oct-2026: prueba38 100/100, navegador38 13/13, recorrido 8.400 páginas sin errores PHP.
