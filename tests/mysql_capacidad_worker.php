<?php
declare(strict_types=1);

// Protocolo de laboratorio. Nunca utiliza las credenciales del CRM.
$port = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
$mode = $argv[2] ?? '';
if (PHP_SAPI !== 'cli' || !$port || !in_array($mode, ['observer', 'worker'], true)) {
    exit(2);
}

function abrir(int $port, bool $observador, bool $persistente = false): mysqli
{
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $db->real_connect(($persistente ? 'p:' : '') . '127.0.0.1',
        $observador ? 'root' : 'impe_capacity', '', 'impe_test_capacidad', $port);
    return $db;
}

if ($mode === 'observer') {
    $db = abrir($port, true);
    $dataDir = str_replace('\\', '/', $db->query('SELECT @@datadir')->fetch_row()[0]);
    if (!preg_match('~/mysql-capacidad-[a-f0-9]+/data/?$~D', $dataDir)) {
        exit(3);
    }
    while (fgets(STDIN) !== false) {
        $sesiones = $db->query("SELECT COUNT(*) total, COALESCE(SUM(COMMAND='Sleep'),0) ociosas
            FROM information_schema.PROCESSLIST WHERE USER='impe_capacity'")->fetch_assoc();
        $intentos = $db->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch_row()[1];
        echo json_encode([microtime(true), (int)$intentos, (int)$sesiones['total'], (int)$sesiones['ociosas']]), "\n";
        fflush(STDOUT);
    }
    exit(0);
}

echo "listo\n";
fflush(STDOUT);
$config = json_decode((string)fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$resultados = [];
for ($solicitud = 0; $solicitud < 4; $solicitud++) {
    $meta = $config['inicio'] + $solicitud * 0.25;
    while (($espera = $meta - microtime(true)) > 0) {
        usleep((int)min(50000, max(1, $espera * 1000000)));
    }
    $inicio = hrtime(true);
    $conexiones = [];
    $ids = [];
    $error = 0;
    try {
        for ($i = 0; $i < $config['sesiones']; $i++) {
            $db = abrir($port, false, $config['persistente']);
            $conexiones[] = $db;
            $ids[] = $db->thread_id;
        }
        for ($i = 0; $i < 4; $i++) {
            $conexiones[$i % count($conexiones)]->query('SELECT 1')->free();
        }
        usleep(45000);
    } catch (mysqli_sql_exception $e) {
        $error = (int)$e->getCode();
    } finally {
        foreach ($conexiones as $db) {
            $db->close();
        }
    }
    $resultados[] = ['ms' => (hrtime(true) - $inicio) / 1e6, 'error' => $error, 'ids' => $ids];
}
echo json_encode($resultados), "\n";
fflush(STDOUT);
fgets(STDIN); // Conserva los pools para medir Sleep antes de reciclar.
