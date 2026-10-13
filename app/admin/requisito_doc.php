<?php
/* (v35) Ver un documento de requisitos (privado): solo la propia cuenta o el superadministrador */
$liga = requiere_liga();
$d = fila('SELECT * FROM requisitos_docs WHERE id = ?', [(int)get('id')]);
if (!$d || ((int)$d['liga_id'] !== (int)$liga['id'] && !es_super())) { http_response_code(404); exit('No encontrado'); }
$abs = RAIZ . '/data/' . $d['archivo'];
if (str_contains($d['archivo'], '..') || !is_file($abs)) { http_response_code(404); exit('No encontrado'); }
header('Content-Type: ' . (mime_content_type($abs) ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . preg_replace('/[^a-z0-9_.-]/i', '_', $d['doc'] . '.' . pathinfo($abs, PATHINFO_EXTENSION)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($abs);
exit;
