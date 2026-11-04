<?php
define('DENUE_TOKEN', 'ficticio');
define('DENUE_BASE_URL', 'https://fixture.invalid');
require __DIR__ . '/../app/services/DenueCandidatosConsultaService.php';
function verificarDenue($condicion, $mensaje) { if (!$condicion) throw new RuntimeException($mensaje); }
class DenuePaginadoFicticio extends DenueService {
    public array $consultas = [];
    public int $total = 1005;
    public int $fallarEn = 0;
    public bool $duplicar = false;
    public function __construct() {}
    public function buscarPaginaCandidatos(string $estado, string $municipio, string $termino, int $inicio): array {
        $this->consultas[] = $inicio;
        if ($inicio === $this->fallarEn) {
            $this->fallarEn = 0;
            return ['ok' => false, 'reintentable' => true, 'mensaje' => 'Interrupción ficticia.'];
        }
        $rows = [];
        for ($i = $inicio; $i <= min($inicio + 99, $this->total); $i++) {
            $id = $this->duplicar && $i === 101 ? 1 : $i;
            $rows[] = ['origen' => 'DENUE', 'clave_origen' => 'DENUE:'.$id, 'nombre' => 'Candidato '.$id,
                'sector' => $i > 1000 ? '61' : '31', 'estrato_valor' => 1];
        }
        return ['ok' => true, 'resultados' => $rows, 'recibidos' => count($rows), 'agotado' => count($rows) === 0];
    }
}
$dir = sys_get_temp_dir() . '/denue-paginado-' . bin2hex(random_bytes(6));
$api = new DenuePaginadoFicticio();
$service = new DenueCandidatosConsultaService($api, $dir);
$filtros = ['estado' => '09', 'municipio' => '011', 'tipo' => 'TODOS', 'termino' => ''];
$todos = static fn($rows) => $rows;
try {
    $ids = [];
    for ($pagina = 1; $pagina <= 101; $pagina++) {
        do { $r = $service->pagina('usuario-ficticio', $filtros, $pagina, $todos); } while ($r['ok'] && $r['denue_continuar']);
        verificarDenue($r['ok'] && !$r['denue_continuar'], 'Página visible completa.');
        foreach ($r['candidatos'] as $c) $ids[] = $c['clave_origen'];
        if ($pagina === 1) verificarDenue(!$r['total_exacto'] && $r['hay_mas'], 'No fingir total definitivo antes de recorrer API.');
    }
    verificarDenue(count($ids) === 1005 && count(array_unique($ids)) === 1005, 'Todos los 1005 resultados sin límite 250/300.');
    verificarDenue($r['total_exacto'] && !$r['hay_mas'] && $r['total_resultados'] === 1005, 'Final sólo al agotar API.');
    verificarDenue($api->consultas === array_merge(range(1, 1001, 100), [1006]), 'Rangos sucesivos sin saltos y confirmación de final.');
    $antes = count($api->consultas);
    $r = $service->pagina('usuario-ficticio', $filtros, 1, $todos);
    verificarDenue($r['candidatos'][0]['clave_origen'] === 'DENUE:1' && count($api->consultas) === $antes, 'Volver a páginas previas sin red.');
    // Un apéndice no confirmado nunca se interpreta como resultado.
    $cache = glob($dir.'/*/candidatos.jsonl')[0];
    $tamano = filesize($cache);
    file_put_contents($cache, "{incompleto", FILE_APPEND);
    $service->pagina('usuario-ficticio', $filtros, 1, $todos);
    clearstatcache();
    verificarDenue(filesize($cache) === $tamano, 'Recuperar caché interrumpida sin perder registros confirmados.');
    // Una caída en 101 no hace avanzar el cursor ni entrega el conjunto anterior como éxito.
    $api->fallarEn = 101;
    $service->pagina('otro-usuario', $filtros, 1, $todos);
    $r = $service->pagina('otro-usuario', $filtros, 11, $todos);
    verificarDenue(!$r['ok'], 'Un fallo no es una página completa.');
    $r = $service->pagina('otro-usuario', $filtros, 11, $todos);
    verificarDenue($r['ok'] && $r['candidatos'][0]['clave_origen'] === 'DENUE:101', 'Reanudar desde el rango fallido exacto.');
    // El filtro puede encontrar sus primeras coincidencias después del registro 1000.
    $filtros['tipo'] = 'INSTITUCIONES';
    $filtrar = static fn($rows) => array_values(array_filter($rows, static fn($r) => $r['sector'] === '61'));
    $continuaciones = 0;
    do {
        $r = $service->pagina('usuario-ficticio', $filtros, 1, $filtrar);
        verificarDenue($r['ok'], 'Continuación válida.');
        $continuaciones++;
    } while ($r['denue_continuar']);
    verificarDenue($continuaciones === 12 && count($r['candidatos']) === 5 && $r['total_exacto'], 'Buscar más allá de páginas sin coincidencias.');
    $api->total = 200;
    $filtros['tipo'] = 'TODOS'; $filtros['municipio'] = '012';
    $api->duplicar = true;
    $service->pagina('usuario-ficticio', $filtros, 1, $todos);
    $service->pagina('usuario-ficticio', $filtros, 11, $todos);
    $r = $service->pagina('usuario-ficticio', $filtros, 20, $todos);
    verificarDenue($r['total_exacto'] && $r['total_resultados'] === 199 && !$r['hay_mas'], 'Deduplicar sin saltar el resto de API y comprobar página vacía final.');
    $api->total = 0; $filtros['municipio'] = '013';
    $r = $service->pagina('usuario-ficticio', $filtros, 1, $todos);
    verificarDenue($r['ok'] && $r['total_exacto'] && $r['total_resultados'] === 0, 'Vacío sólo con respuesta válida completa.');
    $http = new class extends DenueCandidatosHttpClient {
        public array $urls = [];
        public function consultar(array $solicitudes, int $concurrencia = 4): array {
            $this->urls[] = $solicitudes[0]['url'];
            return [['ok' => true, 'datos' => count($this->urls) === 1
                ? [['Id'=>'777', 'Nombre'=>'Ficticio', 'AreaGeo'=>'090110001', 'Estrato'=>'0 a 5 personas']]
                : []]];
        }
    };
    $normalizar = new DenueService($http);
    $r = $normalizar->buscarPaginaCandidatos('09','011','',1);
    verificarDenue($r['ok'] && !$r['agotado'] && $r['recibidos'] === 1, 'Una página corta no prueba que se agotó DENUE.');
    verificarDenue($r['resultados'][0]['clave_area'] === '09011', 'El filtro municipal conserva la entidad y municipio aunque venga localidad.');
    verificarDenue(str_contains($http->urls[0], '/09/011/0/0/0/0/0/0/0/0/1/100/0/0/'), 'Todos los sectores y estratos; rango real de 100.');
    $r = $normalizar->buscarPaginaCandidatos('09','011','',2);
    verificarDenue($r['agotado'] && str_contains($http->urls[1], '/2/101/'), 'Avanzar por registros recibidos y confirmar final con respuesta vacía.');
    echo "DENUE paginado: 1005 registros, filtros, continuación, cursor, deduplicación y caché transaccional OK\n";
} finally {
    // Sólo los archivos de esta carpeta temporal creada por la prueba.
    foreach (glob($dir.'/*') ?: [] as $job) {
        foreach (glob($job.'/*') ?: [] as $file) unlink($file);
        rmdir($job);
    }
    if (is_dir($dir)) rmdir($dir);
}
