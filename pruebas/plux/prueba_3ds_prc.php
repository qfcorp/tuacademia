<?php
// Prueba del regreso del banco (public/plux_3ds.php) con Plux SIMULADO (no llama a Plux).
// 10 nov 2026: Plux pide que el cierre 3D Secure (dataTransactionThreeDsResource) lleve los 4 datos: pti, pcc, ptk y prc.
// Uso (en una COPIA del repo o en el entorno de pruebas, nunca en el servidor):  php pruebas/plux/prueba_3ds_prc.php
// Cada caso corre en un proceso aparte porque plux_3ds.php termina con exit.
$repo = realpath(__DIR__ . '/../..');
if (str_starts_with($repo, '/var/www/') || is_file("$repo/data/.servidor")) { echo "ESTO ES UN SERVIDOR: no se prueba aquí\n"; exit(1); }
if (($argv[1] ?? '') === 'caso') {
    $log = $argv[2];
    parse_str($argv[3], $_GET);
    $_SERVER += ['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => '127.0.0.1', 'REQUEST_URI' => '/plux_3ds.php'];
    define('PLUX_SIMULADOR', function (string $ruta, array $body) use ($log) {
        file_put_contents($log, json_encode(['ruta' => $ruta, 'body' => $body]));
        return ['code' => 2, 'description' => 'Plux simulado'];
    });
    ob_start();
    require "$repo/public/plux_3ds.php";
    exit;
}
$bien = $mal = 0;
$check = function (string $que, $esperado, $salio) use (&$bien, &$mal) {
    if ($esperado === $salio) { $bien++; echo "  OK    $que\n"; } else { $mal++; echo "  FALLA $que — esperado " . json_encode($esperado) . ', salió ' . json_encode($salio) . "\n"; }
};
$correr = function (string $query) use ($repo): array {
    $log = tempnam(sys_get_temp_dir(), 'p3ds');
    file_put_contents($log, '');
    shell_exec('cd ' . escapeshellarg($repo) . ' && php ' . escapeshellarg(__FILE__) . ' caso ' . escapeshellarg($log) . ' ' . escapeshellarg($query) . ' 2>&1');
    $r = json_decode((string) file_get_contents($log), true) ?: [];
    unlink($log);
    return $r;
};
echo "== Regreso del banco (3D Secure) -> cierre con Plux\n";
$r = $correr('pti=PTI-1&pcc=PCC-1&ptk=TK-1&prc=PRC-1');
$check('Llama a dataTransactionThreeDsResource', 'integrations/dataTransactionThreeDsResource', $r['ruta'] ?? null);
$check('Manda los 4 datos: pti, pcc, ptk y prc', ['pti' => 'PTI-1', 'pcc' => 'PCC-1', 'ptk' => 'TK-1', 'prc' => 'PRC-1'], $r['body'] ?? null);
$r = $correr('pti=PTI-2&pcc=PCC-2&ptk=TK-2');
$check('Si el banco no manda prc, no se inventa', ['pti' => 'PTI-2', 'pcc' => 'PCC-2', 'ptk' => 'TK-2'], $r['body'] ?? null);
$r = $correr('pti=PTI-3&pcc=PCC-3');
$check('Sin ptk no se llama a Plux', [], $r);
echo "Resultado: $bien correctas, $mal fallas\n";
exit($mal ? 1 : 0);
