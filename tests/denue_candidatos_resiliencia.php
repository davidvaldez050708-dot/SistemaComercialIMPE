<?php
// Sin DB ni INEGI: transporte simulado y caché temporal privada.
define('DENUE_TOKEN', 'token-ficticio');
define('DENUE_BASE_URL', 'https://fixture.invalid/consulta');
require __DIR__ . '/../app/services/DenueService.php';

function comprobar($valor, string $mensaje): void {
    if (!$valor) throw new RuntimeException($mensaje);
}
class DenueSimulado extends DenueCandidatosHttpClient {
    public array $solicitudes = [];
    public string $modo = 'correcto';
    public function consultar(array $solicitudes, int $concurrencia = 4): array {
        $this->solicitudes = $solicitudes;
        $respuestas = [];
        foreach ($solicitudes as $i => $solicitud) {
            $fila = ['Id' => '123', 'Nombre' => 'Institución ficticia', 'AreaGeo' => '09014',
                'Codigo_act' => '611311', 'Clase_actividad' => 'Educación', 'Estrato' => '11 a 30 personas'];
            if ($this->modo === 'parcial' && $i === 0) $fila['Id'] = '456';
            $respuesta = $this->modo === 'correcto' || ($this->modo === 'parcial' && $i === 0)
                ? ['ok' => true, 'datos' => [$fila]]
                : ['ok' => false, 'codigo' => $this->modo === 'autorizacion' ? 'AUTORIZACION' : 'CONEXION',
                    'mensaje' => $this->modo === 'autorizacion' ? 'Autorización rechazada.' : 'Conexión interrumpida.',
                    'reintentable' => $this->modo !== 'autorizacion'];
            if ($this->modo === 'vacio') $respuesta = ['ok' => true, 'datos' => []];
            $respuestas[] = $respuesta + ['perfil' => $solicitud['perfil']];
        }
        return $respuestas;
    }
}
$dir = sys_get_temp_dir() . '/denue-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$transporte = new DenueSimulado();
$servicio = new DenueService($transporte, $dir);
$envejecer = static function (int $edad) use ($dir) {
    foreach (glob($dir . '/*.json') as $archivo) touch($archivo, time() - $edad);
    clearstatcache();
};
try {
    $r = $servicio->buscarCandidatosRecomendados('09', '014', 250);
    comprobar($r['ok'] && count($r['resultados']) === 1, 'Deduplicar las 18 consultas.');
    comprobar(count($transporte->solicitudes) === 18, 'Conservar sectores y estratos.');
    foreach ($transporte->solicitudes as $solicitud) {
        comprobar(str_contains($solicitud['url'], '/09/014/'), 'Conservar estado/municipio en cada solicitud.');
    }
    $archivo = glob($dir . '/*.json')[0];
    $original = file_get_contents($archivo);
    $transporte->modo = 'fallo';
    comprobar($servicio->buscarCandidatosRecomendados('09', '014')['cache'], 'Reutilizar caché fresca.');
    $envejecer(2000);
    $r = $servicio->buscarCandidatosRecomendados('09', '014');
    comprobar($r['ok'] && $r['cache'] && count($r['advertencias']) === 1, 'Recuperar copia reciente con aviso.');
    comprobar(file_get_contents($archivo) === $original, 'No sustituir ni rejuvenecer la copia ante fallo.');
    $r = $servicio->buscarCandidatosRecomendados('09', '015');
    comprobar(!$r['ok'], 'No mezclar municipios.');
    $r = $servicio->buscarCandidatosRecomendados('10', '014');
    comprobar(!$r['ok'], 'No mezclar estados.');
    $transporte->modo = 'parcial';
    $r = $servicio->buscarCandidatosRecomendados('09', '014');
    comprobar(count($r['resultados']) === 2 && $r['cache'], 'Combinar respuesta parcial con copia del mismo ámbito.');
    comprobar(file_get_contents($archivo) === $original, 'Parcial no borra catálogo completo.');
    $transporte->modo = 'autorizacion';
    $r = $servicio->buscarCandidatosRecomendados('09', '014');
    comprobar(!$r['ok'] && $r['codigo'] === 'AUTORIZACION', 'No ocultar rechazo de autorización con copia vieja.');
    $transporte->modo = 'fallo';
    $envejecer(86401);
    comprobar(!$servicio->buscarCandidatosRecomendados('09', '014')['ok'], 'Respaldo máximo de 24 horas.');
    file_put_contents($archivo, '{incompleto');
    comprobar(!$servicio->buscarCandidatosRecomendados('09', '014')['ok'], 'Caché corrupta no bloquea la consulta.');
    $transporte->modo = 'correcto';
    $r = $servicio->buscarDenuePorNombre('09', '014', 'universidad');
    comprobar($r['ok'] && count($r['resultados']) === 1, 'Búsqueda por nombre conserva normalización.');
    $envejecer(2000);
    $transporte->modo = 'fallo';
    comprobar($servicio->buscarDenuePorNombre('09', '014', 'universidad')['cache'], 'Rescate por término exacto normalizado.');
    comprobar(!$servicio->buscarDenuePorNombre('09', '014', 'hospital')['ok'], 'No mezclar términos.');
    $transporte->modo = 'vacio';
    $r = $servicio->buscarCandidatosRecomendados('12', '001');
    comprobar($r['ok'] && !$r['resultados'], 'Vacío válido conserva significado.');
    comprobar(!$servicio->buscarCandidatosRecomendados('xx', '014')['ok'], 'Validación territorial intacta.');
    echo "DENUE: normalización, filtros, caché, fallos, autorización y recuperación OK\n";
} finally {
    foreach (glob($dir . '/*') as $archivo) unlink($archivo);
    rmdir($dir);
}
