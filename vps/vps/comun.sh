# Funciones comunes de los scripts de TuAcademia en el VPS (no se ejecuta solo)
# TuAcademia vive APARTE de TuVocalia: otra carpeta, otro contenedor, otros puertos. No se tocan entre si.
verde()    { echo -e "\e[32m$1\e[0m"; }
rojo()     { echo -e "\e[31m$1\e[0m"; }
amarillo() { echo -e "\e[33m$1\e[0m"; }
paso()     { echo; echo -e "\e[1;36m==> $1\e[0m"; }
DESTINO="/var/www/tuacademia"
RESPALDOS="/var/www/tuacademia_respaldos"
OPT="/opt/tuacademia"
CONTENEDOR="tuacademia-php"
IMAGEN="tuacademia-php:8.5"
SITIO="tuacademia.tukanec.com"   # subdominio provisional (se cambia cuando se elija el nombre definitivo)
PUERTO_WEB="8096"   # nginx de TuAcademia: SOLO dentro del servidor (127.0.0.1). TuVocalia usa 8095. Lo publica el tunel de Cloudflare
PUERTO_PHP="9086"   # PHP 8.5 dentro de Docker: SOLO dentro del servidor (127.0.0.1). TuVocalia usa 9085
# Limites para que TuAcademia nunca le quite recursos al ERP, a TuVocalia ni al SRT/Restreamer
LIM_CPUS="1.0"      # como maximo 1 de los 6 nucleos
LIM_PRIORIDAD="256" # prioridad baja (lo normal es 1024): si el servidor esta ocupado, el ERP y el SRT van primero
LIM_RAM="1024m"     # como maximo 1 GB de los 12 GB
php_ta() { docker exec -u www-data "$CONTENEDOR" php "$@"; }
