<?php
/* Planilla de juego en blanco (para imprimir antes del partido) */
$liga = requiere_liga();
$p0 = partido_de_liga((int)get('id'), (int)$liga['id']);
doc_planilla(partido_completo((int)$p0['id']), $liga, 'admin.php?p=partido&id=' . (int)$p0['id']);
