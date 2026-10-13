<?php
/* (v11) Recibo de pago de la escuela */
$liga = requiere_liga();
doc_recibo_escuela($liga, (int)get('n'), 'admin.php?p=cobros');
