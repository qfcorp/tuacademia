# Funciones comunes de los scripts de TuAcademia en el VPS (no se ejecuta solo)
verde()    { echo -e "\e[32m$1\e[0m"; }
rojo()     { echo -e "\e[31m$1\e[0m"; }
amarillo() { echo -e "\e[33m$1\e[0m"; }
paso()     { echo; echo -e "\e[1;36m==> $1\e[0m"; }
DESTINO="/var/www/ligas"
RESPALDOS="/var/www/ligas_respaldos"
OPT="/opt/tuacademia"
CONTENEDOR="tuacademia-php"
IMAGEN="tuacademia-php:8.5"
PUERTO_WEB="8095"   # nginx de TuAcademia: SOLO dentro del servidor (127.0.0.1), lo publica el tunel de Cloudflare
PUERTO_PHP="9085"   # PHP 8.5 dentro de Docker: SOLO dentro del servidor (127.0.0.1)
# Limites para que TuAcademia nunca le quite recursos al ERP ni al SRT/Restreamer
LIM_CPUS="1.5"      # como maximo 1,5 de los 6 nucleos
LIM_PRIORIDAD="256" # prioridad baja (lo normal es 1024): si el servidor esta ocupado, el ERP y el SRT van primero
LIM_RAM="1536m"     # como maximo 1,5 GB de los 12 GB
php_tv() { docker exec -u www-data "$CONTENEDOR" php "$@"; }
