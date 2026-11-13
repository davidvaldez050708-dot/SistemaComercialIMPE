<?php

require_once __DIR__ . '/../app/models/TerritorioModel.php';

class ConexionTerritorialFalsa
{
    public $insert_id = 0;
    public $commitCount = 0;
    public $rollbackCount = 0;
    public $asignaciones = 0;
    public $movimientos = 0;
    public $cuentas = [];
    private $anterior;

    public function begin_transaction()
    {
        $this->anterior = [$this->asignaciones, $this->movimientos, $this->cuentas];
    }
    public function commit() { $this->commitCount++; $this->anterior = null; }
    public function rollback()
    {
        list($this->asignaciones, $this->movimientos, $this->cuentas) = $this->anterior;
        $this->rollbackCount++;
        $this->anterior = null;
    }
    public function prepare($sql) { return new ConsultaTerritorialFalsa($this, $sql); }
}

class ConsultaTerritorialFalsa
{
    private $conexion;
    private $sql;
    private $valores = [];

    public function __construct($conexion, $sql)
    {
        $this->conexion = $conexion;
        $this->sql = $sql;
    }

    public function bind_param($tipos, &...$valores) { $this->valores = $valores; }
    public function execute()
    {
        if (strpos($this->sql, 'INSERT INTO asignaciones_territorio') !== false) {
            $this->conexion->asignaciones++;
            $this->conexion->insert_id++;
            if ($this->valores[2] === 'CUENTA_CLAVE') {
                $this->conexion->cuentas[$this->valores[0]][] = $this->conexion->insert_id;
            }
        } elseif (strpos($this->sql, 'INSERT INTO bitacora_movimientos_territoriales') !== false) {
            $this->conexion->movimientos++;
        }
        return true;
    }
    public function get_result()
    {
        $filas = strpos($this->sql, 'FROM asignaciones_territorio') !== false
            ? array_map(function ($id) { return ['id' => $id]; },
                array_slice($this->conexion->cuentas[$this->valores[0]] ?? [], 0, 2))
            : [['id' => 1]];
        return new class($filas) {
            private $filas;
            public function __construct($filas) { $this->filas = $filas; }
            public function fetch_assoc() { return array_shift($this->filas); }
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

$conexionNueva = new ConexionTerritorialFalsa();
$propiedad->setValue($modelo, $conexionNueva);
$equipo = [
    [
        'estado_id' => 1, 'usuario_id' => 7,
        'tipo_asignacion' => 'CUENTA_CLAVE',
        'fecha_inicio' => '2026-11-13', 'observaciones' => ''
    ],
    [
        'estado_id' => 1, 'usuario_id' => 8,
        'tipo_asignacion' => 'ANALISTA_DATOS',
        'cuenta_clave_referencia' => 'nuevo:7',
        'fecha_inicio' => '2026-11-13', 'observaciones' => ''
    ]
];
$vinculados = [];
$modelo->crearAsignacionesMasivas($equipo, 3, function ($datos) use (&$vinculados) {
    if ($datos['tipo_asignacion'] === 'ANALISTA_DATOS') {
        $vinculados[] = $datos['cuenta_clave_asignacion_id'];
    }
    return [];
});
if ($vinculados !== [1] || $conexionNueva->asignaciones !== 2 ||
    $conexionNueva->movimientos !== 2 || $conexionNueva->commitCount !== 1) {
    throw new RuntimeException('El Analista no quedó vinculado a la nueva Cuenta Clave.');
}
echo "Vinculación de Analista con Cuenta Clave nueva verificada.\n";

$conexionEquipo = new ConexionTerritorialFalsa();
$propiedad->setValue($modelo, $conexionEquipo);
$equipo = [
    ['estado_id' => 1, 'usuario_id' => 7, 'tipo_asignacion' => 'CUENTA_CLAVE',
        'fecha_inicio' => '2026-11-13', 'observaciones' => ''],
    ['estado_id' => 1, 'usuario_id' => 8, 'tipo_asignacion' => 'ANALISTA_DATOS',
        'cuenta_clave_referencia' => '', 'fecha_inicio' => '2026-11-13', 'observaciones' => ''],
    ['estado_id' => 1, 'usuario_id' => 9, 'tipo_asignacion' => 'ASESOR',
        'cuenta_clave_referencia' => '', 'fecha_inicio' => '2026-11-13', 'observaciones' => '']
];
$vinculados = [];
$modelo->crearAsignacionesMasivas($equipo, 3, function ($datos) use (&$vinculados) {
    if ($datos['tipo_asignacion'] !== 'CUENTA_CLAVE') {
        $vinculados[$datos['tipo_asignacion']] = $datos['cuenta_clave_asignacion_id'];
    }
    return [];
});
if ($vinculados !== ['ANALISTA_DATOS' => 1, 'ASESOR' => 1] ||
    $conexionEquipo->commitCount !== 1 || $conexionEquipo->asignaciones !== 3) {
    throw new RuntimeException('El equipo con una Cuenta Clave no se vinculó automáticamente.');
}

$conexionVarias = new ConexionTerritorialFalsa();
$propiedad->setValue($modelo, $conexionVarias);
$dosCuentas = [
    ['estado_id' => 1, 'usuario_id' => 7, 'tipo_asignacion' => 'CUENTA_CLAVE',
        'fecha_inicio' => '2026-11-13', 'observaciones' => ''],
    ['estado_id' => 1, 'usuario_id' => 10, 'tipo_asignacion' => 'CUENTA_CLAVE',
        'fecha_inicio' => '2026-11-13', 'observaciones' => ''],
    ['estado_id' => 1, 'usuario_id' => 9, 'tipo_asignacion' => 'ASESOR',
        'cuenta_clave_referencia' => '', 'fecha_inicio' => '2026-11-13', 'observaciones' => '']
];
try {
    $modelo->crearAsignacionesMasivas($dosCuentas, 3, function () { return []; });
    throw new RuntimeException('Faltó exigir una Cuenta Clave para el Asesor.');
} catch (InvalidArgumentException $error) {
    if ($conexionVarias->rollbackCount !== 1) throw $error;
}
$dosCuentas[2]['cuenta_clave_referencia'] = 'nuevo:10';
$vinculado = 0;
$modelo->crearAsignacionesMasivas($dosCuentas, 3, function ($datos) use (&$vinculado) {
    if ($datos['tipo_asignacion'] === 'ASESOR') {
        $vinculado = $datos['cuenta_clave_asignacion_id'];
    }
    return [];
});
if ($vinculado !== 4 || $conexionVarias->commitCount !== 1) {
    throw new RuntimeException('El Asesor no quedó vinculado a la Cuenta Clave elegida.');
}
echo "Vinculación automática y elección con varias Cuentas Clave verificadas.\n";

$conexionExistente = new ConexionTerritorialFalsa();
$conexionExistente->cuentas[2] = [41];
$propiedad->setValue($modelo, $conexionExistente);
$asesor = [[
    'estado_id' => 2, 'usuario_id' => 9, 'tipo_asignacion' => 'ASESOR',
    'cuenta_clave_referencia' => '', 'fecha_inicio' => '2026-11-13',
    'observaciones' => ''
]];
$vinculado = 0;
$modelo->crearAsignacionesMasivas($asesor, 3, function ($datos) use (&$vinculado) {
    $vinculado = $datos['cuenta_clave_asignacion_id'];
    return [];
});
if ($vinculado !== 41) {
    throw new RuntimeException('El Asesor no se vinculó a la única Cuenta Clave existente.');
}
$conexionExistente->cuentas[2][] = 42;
try {
    $modelo->crearAsignacionesMasivas($asesor, 3, function () { return []; });
    throw new RuntimeException('Faltó exigir elección entre Cuentas Clave existentes.');
} catch (InvalidArgumentException $error) {
    if ($conexionExistente->rollbackCount !== 1) throw $error;
}
$asesor[0]['cuenta_clave_referencia'] = '42';
$asesor[0]['cuenta_clave_asignacion_id'] = 42;
$modelo->crearAsignacionesMasivas($asesor, 3, function ($datos) use (&$vinculado) {
    $vinculado = $datos['cuenta_clave_asignacion_id'];
    return [];
});
if ($vinculado !== 42) {
    throw new RuntimeException('El Asesor no quedó vinculado a la Cuenta Clave existente elegida.');
}
echo "Vinculación con Cuentas Clave existentes verificada.\n";

$conexionTodos = new ConexionTerritorialFalsa();
$asignacionesTodos = [];
foreach (range(1, 32) as $estadoId) {
    $conexionTodos->cuentas[$estadoId] = [1000 + $estadoId];
    foreach ([['CUENTA_CLAVE', 7], ['ANALISTA_DATOS', 8], ['ASESOR', 9]] as $persona) {
        $asignacionesTodos[] = [
            'estado_id' => $estadoId, 'usuario_id' => $persona[1],
            'tipo_asignacion' => $persona[0], 'cuenta_clave_referencia' => '',
            'fecha_inicio' => '2026-11-13', 'observaciones' => ''
        ];
    }
}
$propiedad->setValue($modelo, $conexionTodos);
$vinculosTodos = 0;
$totalTodos = $modelo->crearAsignacionesMasivas($asignacionesTodos, 3,
    function ($datos) use ($conexionTodos, &$vinculosTodos) {
        if ($datos['tipo_asignacion'] !== 'CUENTA_CLAVE') {
            $cuentas = $conexionTodos->cuentas[$datos['estado_id']];
            if ($datos['cuenta_clave_asignacion_id'] !== end($cuentas)) {
                return ['cuenta' => 'No se eligió la nueva Cuenta Clave.'];
            }
            $vinculosTodos++;
        }
        return [];
    });
if ($totalTodos !== 96 || $vinculosTodos !== 64 ||
    $conexionTodos->commitCount !== 1 || $conexionTodos->asignaciones !== 96) {
    throw new RuntimeException('No se vinculó todo el equipo en los 32 estados.');
}
echo "Vinculación automática en 32 estados con Cuentas Clave previas verificada.\n";
