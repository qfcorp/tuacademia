#!/bin/bash
# TuAcademia - Como esta en el VPS (cuanto consume, si responde). Uso: sudo bash vps_estado.sh
. "$(cd "$(dirname "$0")" && pwd)/comun.sh"
paso "Contenedor PHP 8.5 de TuAcademia (limites: $LIM_CPUS nucleos, $LIM_RAM)"
docker stats --no-stream --format 'CPU {{.CPUPerc}}  |  RAM {{.MemUsage}}  |  procesos {{.PIDs}}' "$CONTENEDOR" 2>/dev/null || rojo "El contenedor no esta corriendo: sudo docker start $CONTENEDOR"
paso "Pagina"
C="$(curl -s -o /dev/null -w '%{http_code} en %{time_total} s' -H 'Host: tuacademia.tukanec.com' http://127.0.0.1:$PUERTO_WEB/index.php)"; echo "Respuesta: $C"
paso "Servidor completo"
echo "Carga: $(cut -d' ' -f1-3 /proc/loadavg) (6 nucleos)"; free -h | awk 'NR==2{print "RAM usada " $3 " de " $2}'; df -h / | awk 'NR==2{print "Disco " $5}'
paso "Tareas automaticas"
[ -f /etc/cron.d/tuacademia ] && verde "Activadas (produccion)" || amarillo "Apagadas (modo prueba)"
paso "Ultimos errores"
tail -n 5 /var/log/nginx/tuacademia_error.log 2>/dev/null; docker logs --tail 200 "$CONTENEDOR" 2>&1 | grep -iE "warning|error|fatal|deprecated" | tail -n 5
