<?php

require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/../models/ReporteAdministradorModel.php';

class ReporteAdministradorDataService
{
    private $modeloUsuario;
    private $modeloReporte;

    public function __construct()
    {
        $this->modeloUsuario = new UsuarioModel();
        $this->modeloReporte = new ReporteAdministradorModel();
    }

    public function obtenerRolesDisponibles()
    {
        return $this->modeloUsuario->obtenerRolesActivos();
    }

    public function resolverRolesSeleccionados(array $fuente)
    {
        if (!isset($fuente['filtrar_roles'])) {
            return null;
        }

        if ((string)($fuente['todos_roles'] ?? '') === '1') {
            return null;
        }

        $rolesRecibidos = $fuente['roles'] ?? [];

        if (!is_array($rolesRecibidos) || empty($rolesRecibidos)) {
            throw new InvalidArgumentException(
                'Selecciona al menos un rol o la opción Todos los roles.'
            );
        }

        $rolesSeleccionados = [];

        foreach ($rolesRecibidos as $rolId) {
            if (!is_scalar($rolId) || preg_match('/^\d+$/', (string)$rolId) !== 1) {
                throw new InvalidArgumentException('Se recibió un rol no válido.');
            }

            $rolId = (int)$rolId;

            if ($rolId <= 0) {
                throw new InvalidArgumentException('Se recibió un rol no válido.');
            }

            $rolesSeleccionados[] = $rolId;
        }

        $rolesSeleccionados = array_values(array_unique($rolesSeleccionados));
        $idsDisponibles = array_map(
            static function ($rol) {
                return (int)($rol['id'] ?? 0);
            },
            $this->obtenerRolesDisponibles()
        );

        foreach ($rolesSeleccionados as $rolId) {
            if (!in_array($rolId, $idsDisponibles, true)) {
                throw new InvalidArgumentException(
                    'Uno de los roles seleccionados no está disponible.'
                );
            }
        }

        return $rolesSeleccionados;
    }

    public function prepararDatos($rolesSeleccionados = null)
    {
        $rolesFiltro = is_array($rolesSeleccionados) ? $rolesSeleccionados : [];

        $usuarios = $this->modeloReporte->obtenerUsuariosConSeguimiento($rolesFiltro);

        if ($rolesSeleccionados === null) {
            $conteoUsuarios = $this->modeloUsuario->contarUsuarios();
            $rolesRegistrados = $this->modeloUsuario->contarRoles();
        } else {
            $usuariosActivos = 0;
            $usuariosInactivos = 0;

            foreach ($usuarios as $usuario) {
                if ((int)($usuario['estado'] ?? 0) === 1) {
                    $usuariosActivos++;
                } else {
                    $usuariosInactivos++;
                }
            }

            $conteoUsuarios = [
                'registrados' => count($usuarios),
                'activos' => $usuariosActivos,
                'inactivos' => $usuariosInactivos
            ];
            $rolesRegistrados = count($rolesSeleccionados);
        }

        $totalSeguimientos = 0;
        $usuariosPorRol = [];
        $usuariosSinAcceso = [];
        $mayorCarga = 0;
        $usuarioMayorCarga = null;

        foreach ($usuarios as $usuario) {
            $totalUsuario = (int)($usuario['total_seguimientos'] ?? 0);
            $totalSeguimientos += $totalUsuario;
            $rol = trim((string)($usuario['rol'] ?? ''));
            $rol = $rol !== '' ? $rol : 'Sin rol';
            $usuariosPorRol[$rol] = ($usuariosPorRol[$rol] ?? 0) + 1;
            $nombre = trim((string)($usuario['nombre'] ?? '') . ' ' . (string)($usuario['apellidos'] ?? ''));
            $nombre = $nombre !== '' ? $nombre : (string)($usuario['usuario'] ?? '—');

            if ($totalUsuario > $mayorCarga) {
                $mayorCarga = $totalUsuario;
                $usuarioMayorCarga = [$nombre, $rol];
            }

            if (trim((string)($usuario['ultimo_acceso'] ?? '')) === '') {
                $fechaRegistro = substr((string)($usuario['fecha_registro'] ?? ''), 0, 10);
                $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaRegistro);
                $usuariosSinAcceso[] = [
                    $nombre,
                    $rol,
                    (int)($usuario['estado'] ?? 0) === 1 ? 'Activo' : 'Inactivo',
                    $fecha && $fecha->format('Y-m-d') === $fechaRegistro ? $fecha->format('d/m/Y') : '—'
                ];
            }
        }

        arsort($usuariosPorRol);

        $datosRoles = [];
        foreach ($usuariosPorRol as $rol => $total) {
            $datosRoles[] = [
                'etiqueta' => (string)$rol,
                'valor' => (int)$total
            ];
        }

        $resumen = [
            'usuarios_registrados' => (int)($conteoUsuarios['registrados'] ?? 0),
            'usuarios_activos' => (int)($conteoUsuarios['activos'] ?? 0),
            'usuarios_inactivos' => (int)($conteoUsuarios['inactivos'] ?? 0),
            'roles_registrados' => (int)$rolesRegistrados,
            'total_seguimientos' => $totalSeguimientos
        ];

        $cantidadSinAcceso = count($usuariosSinAcceso);
        $textoSinAcceso = 'Todos los usuarios incluidos tienen acceso registrado.';
        if ($cantidadSinAcceso > 0) {
            $textoSinAcceso = ($cantidadSinAcceso === 1
                ? 'Se identificó 1 usuario sin inicio de sesión registrado.'
                : 'Se identificaron ' . number_format($cantidadSinAcceso) . ' usuarios sin inicio de sesión registrado.') .
                ' Se presenta el detalle de las cuentas, incluyendo nombre, rol, estado y fecha de registro, para facilitar su revisión administrativa.';
        }

        $cargaFormateada = number_format($mayorCarga);
        $hallazgos = [
            [
                'texto' => $textoSinAcceso,
                'columnas' => ['Nombre', 'Rol', 'Estado', 'Fecha de registro'],
                'filas' => $usuariosSinAcceso
            ],
            [
                'texto' => $usuarioMayorCarga !== null
                    ? 'Se identificó al usuario con mayor número de seguimientos registrados, con un total de ' .
                        $cargaFormateada . '. Se presenta su nombre, rol y participación respecto al total de seguimientos para facilitar el análisis de la distribución de actividades.'
                    : 'No se registran seguimientos asignados a usuarios.',
                'columnas' => ['Nombre', 'Rol', 'Seguimientos', 'Participación del total'],
                'filas' => $usuarioMayorCarga !== null
                    ? [array_merge($usuarioMayorCarga, [
                        $cargaFormateada,
                        number_format(($mayorCarga / $totalSeguimientos) * 100, 2) . '%'
                    ])]
                    : []
            ]
        ];

        return [
            'resumen' => $resumen,
            'usuarios' => $usuarios,
            'usuarios_por_rol' => $datosRoles,
            'estado_usuarios' => [
                ['etiqueta' => 'Activos', 'valor' => (int)$resumen['usuarios_activos']],
                ['etiqueta' => 'Inactivos', 'valor' => (int)$resumen['usuarios_inactivos']]
            ],
            'hallazgos' => $hallazgos
        ];
    }
}
