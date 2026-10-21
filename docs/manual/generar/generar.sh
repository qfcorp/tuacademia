#!/usr/bin/env bash
# Regenera el manual de TuAcademia para el cliente: base de prueba nueva en modo academia con la «Academia Demo»,
# capturas reales (sin precios), el PDF del manual y la guía «Primeros pasos».
#   bash docs/manual/generar/generar.sh
# Variables opcionales: TA_PUERTO (8461), TA_SIM (8462), SOLO_PDF=1 (no toma capturas, solo vuelve a armar los PDF).
# Necesita: PHP 8.3+ con sqlite, Python 3 con playwright y pypdf, y Chromium de Playwright (PLAYWRIGHT_BROWSERS_PATH).
# ¡Solo en una computadora de pruebas! Usa pruebas/v38/reiniciar.sh, que BORRA data/ (en el servidor no corre).
set -euo pipefail
AQUI=$(cd "$(dirname "$0")" && pwd)
MANUAL=$(cd "$AQUI/.." && pwd)
RAIZ=$(cd "$MANUAL/../.." && pwd)
export TA_PUERTO=${TA_PUERTO:-8461} TA_SIM=${TA_SIM:-8462} TA_PRODUCTO=academia TZ=America/Guayaquil
export PLAYWRIGHT_BROWSERS_PATH=${PLAYWRIGHT_BROWSERS_PATH:-/opt/pw-browsers}
cd "$RAIZ"
if [ "${SOLO_PDF:-0}" != "1" ]; then
  echo "== 1. Base de prueba nueva (modo academia) y Academia Demo"
  bash pruebas/v38/reiniciar.sh > /dev/null
  trap 'pkill -f "php -S 127.0.0.1:$TA_PUERTO" || true; pkill -f "php -S 127.0.0.1:$TA_SIM" || true' EXIT
  # tg_token de mentira: solo para que «Avisos por Telegram» se vea como en el servidor (los envíos van al simulador)
  php -r 'require "app/bootstrap.php"; crear_academia_demo(); guardar_ajuste("tg_token", "123456:PRUEBA"); guardar_ajuste("tg_usuario", "TuAcademiaBot");'
  for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$TA_PUERTO/" && break; sleep 0.3; done
  echo "== 2. Capturas (manual y guía)"
  rm -f "$MANUAL"/capturas/*.png
  python3 "$AQUI/capturas.py" --base "http://127.0.0.1:$TA_PUERTO" --salida "$MANUAL/capturas" --guia "$RAIZ/docs/primeros_pasos/capturas"
  if grep -q "PHP Fatal\|PHP Warning" pruebas/v38/web.log 2>/dev/null; then echo "Errores de PHP durante las capturas:"; grep "PHP Fatal\|PHP Warning" pruebas/v38/web.log | cut -c1-300; fi
fi
echo "== 3. PDF del manual y de la guía"
python3 "$AQUI/pdf.py" "$MANUAL/manual.html" "$RAIZ/docs/Manual_TuAcademia.pdf" "Manual de TuAcademia"
# El mismo PDF se descarga desde la web del programa: /manual (app/publico/manual.php, modo academia)
mkdir -p public/assets/manual && cp "$RAIZ/docs/Manual_TuAcademia.pdf" public/assets/manual/Manual-TuAcademia-Academias.pdf
# La Ayuda del programa («¿En qué le ayudamos?») lee este mismo manual: el VPS solo recibe app/ y public/
cp "$MANUAL/manual.html" "$RAIZ/app/manuales/academia.html"
python3 docs/primeros_pasos/hacer_pdf.py
pdfinfo "$RAIZ/docs/Manual_TuAcademia.pdf" 2>/dev/null | grep -E "Pages" || true
