#!/bin/bash
# TuAcademia - Deja la PRUEBA GRATIS en 7 dias en el servidor (ajuste "dias_prueba" de la base de datos) y lo comprueba.
# Uso (en el VPS):  sudo bash tuacademia/vps/vps/poner_prueba_7_dias.sh
# Se puede repetir sin problema. No toca nada mas (ni cuentas, ni pagos, ni fotos).
# Nota: al actualizar el programa con vps_2_codigo.sh la base pasa a la version 35 y eso ya lo deja en 7 una vez;
#       este comando sirve para confirmarlo o para volver a ponerlo en 7 si alguien lo cambio en Ajustes.
DIR="$(cd "$(dirname "$0")" && pwd)"
. "$DIR/comun.sh"
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0"; exit 1; }
docker ps --format '{{.Names}}' | grep -qx "$CONTENEDOR" || { rojo "El contenedor $CONTENEDOR no esta corriendo."; exit 1; }

paso "1/2 Poniendo la prueba gratis en 7 dias"
R="$(php_ta -r 'require "'"$DESTINO"'/app/bootstrap.php"; guardar_ajuste("dias_prueba", 7); if ((int)ajuste("dias_demo", 7) !== 7) guardar_ajuste("dias_demo", 7); echo "ok ", ajuste("dias_prueba"), " ", ajuste("dias_demo");' 2>&1)"
if echo "$R" | grep -q "^ok 7 7"; then verde "Listo: prueba gratis = 7 dias, demo = 7 dias."; else rojo "No se pudo cambiar:"; echo "$R"; exit 1; fi

paso "2/2 Revisando la pagina de registro"
P="$(curl -s -L -H "Host: $SITIO" "http://127.0.0.1:$PUERTO_WEB/index.php?v=registro")"
if echo "$P" | grep -q "gratis por 7 d"; then
  verde "OK: la pagina de registro dice \"gratis por 7 dias\"."
elif echo "$P" | grep -q "solicitar"; then
  amarillo "El registro abierto esta apagado (la pagina manda a pedir demo). El ajuste ya quedo en 7."
else
  amarillo "La pagina no dijo \"7 dias\". Revise en el navegador: https://$SITIO/registro"
  echo "$P" | grep -o "gratis por [0-9]* d" | head -n 1
fi
