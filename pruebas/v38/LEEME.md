# Pruebas (TuAcademia, base TuVocalía v38)

Se corren en una computadora, **nunca en el servidor** (`reiniciar.sh` borra la base; no corre si existe `data/.servidor`).
Puerto de la web: `TA_PUERTO` (8196). Simulador de Google Play y Payphone: `TA_SIM` (8297).

- `reiniciar.sh`: base nueva, súper, liga y escuela de prueba, simuladores vacíos. `TA_PRODUCTO=ligas` para el modo TuVocalía (Google Play encendido).
- `simulador_google.php`: simula OAuth, la API de Google Play y (TuAcademia) la confirmación de Payphone.
- `prueba38.py` (modo ligas): 100 verificaciones de las apps Android y Google Play.
- `navegador38.py` (modo ligas): Chromium real con la Digital Goods API simulada.
- `recorrido.py`: abre todas las páginas (súper, liga, escuela y, si se corrió antes `../academia/prueba_academia.py`, las 3 academias y los portales de padres) buscando errores PHP.
- `capturas.py`: capturas de teléfono para la ficha de Google Play.
- `../academia/prueba_academia.py` y `../academia/navegador_academia.py` (modo academia): lo nuevo de TuAcademia.

Resultado 13-oct-2026: prueba_academia 236/236, navegador_academia 19/19, prueba38 100/100, navegador38 13/13, recorrido 11.074 páginas sin errores PHP.
Resultado 18-oct-2026 (Anexo B y sin prueba gratis): prueba_academia 239/239, navegador_academia 19/19, prueba_arreglos_14oct 105/105, prueba38 100/100, navegador38 13/13, recorrido sin errores PHP, conector v1 125/125 y 121/121, v2 380/380 y 374/374, prueba_modulos 61/61 y 62/62.
