<?php

require_once __DIR__ . '/../app/models/TerritorioModel.php';

class ConexionTerritorialFalsa
{
    public $insert_id = 0;
    public $commitCount = 0;
    public $rollbackCount = 0;
    public $asignaciones = 0;
    public $movimientos = 0;

    public function begin_transaction() {}
    public function commit() { $this->commitCount++; }
    public function rollback() { $this->rollbackCount++; }
    public function prepare($sql) { return new ConsultaTerritorialFalsa($this, $sql); }
}

class ConsultaTerritorialFalsa
{
    private $conexion;
    private $sql;

    public function __construct($conexion, $sql)
    {
        $this->conexion = $conexion;
        $this->sql = $sql;
    }

    public function bind_param($tipos, &...$valores) {}
    public function execute()
    {
        if (strpos($this->sql, 'INSERT INTO asignaciones_territorio') !== false) {
            $this->conexion->asignaciones++;
            $this->conexion->insert_id++;
        } elseif (strpos($this->sql, 'INSERT INTO bitacora_movimientos_territoriales') !== false) {
            $this->conexion->movimientos++;
        }
        return true;
    }
    public function get_result()
    {
        return new class {
            public function fetch_assoc() { return ['id' => 1]; }
        };
    }
}

$modelo = (new ReflectionClass(TerritorioModel::class))->newInstanceWithoutConstructor();
$conexion = new ConexionTerritorialFalsa();
$propiedad = new ReflectionProperty(TerritorioModel::class, 'connection');
$propiedad->setAccessible(true);
$propiedad->setValue($modelo, $conexion);
$bitacora = new ReflectionProperty(TerritorioModel::class, 'bitacoraDisponible');
$bitacora->setAccessible(true);
$bitacora->setValue($modelo, true);

$asignaciones = [];
foreach ([1, 2] as $estadoId) {
    $asignaciones[] = [
        'estado_id' => $estadoId,
        'usuario_id' => 7,
        'tipo_asignacion' => 'ASESOR',
        'fecha_inicio' => '2026-11-13',
        'observaciones' => ''
    ];
}

$total = $modelo->crearAsignacionesMasivas($asignaciones, 3, function () { return []; });
if ($total !== 2 || $conexion->commitCount !== 1 ||
    $conexion->asignaciones !== 2 || $conexion->movimientos !== 2) {
    throw new RuntimeException('No se guardaron las dos asignaciones y sus movimientos.');
}

try {
    $modelo->crearAsignacionesMasivas($asignaciones, 3, function ($datos) {
        return $datos['estado_id'] === 2 ? ['estado_id' => 'Estado no válido'] : [];
    });
    throw new RuntimeException('Faltó rechazar el segundo estado.');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'Estado no válido' || $conexion->rollbackCount !== 1) {
        throw $error;
    }
}

echo "Asignación masiva: confirmación y reversión verificadas.\n";
