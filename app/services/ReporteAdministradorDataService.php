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
        $usuariosSinAcceso = 0;
        $mayorCarga = 0;

        foreach ($usuarios as $usuario) {
            $totalUsuario = (int)($usuario['total_seguimientos'] ?? 0);
            $totalSeguimientos += $totalUsuario;
            $mayorCarga = max($mayorCarga, $totalUsuario);

            $rol = trim((string)($usuario['rol'] ?? ''));
            $rol = $rol !== '' ? $rol : 'Sin rol';
            $usuariosPorRol[$rol] = ($usuariosPorRol[$rol] ?? 0) + 1;

            if (trim((string)($usuario['ultimo_acceso'] ?? '')) === '') {
                $usuariosSinAcceso++;
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

        $hallazgos = [
            $usuariosSinAcceso > 0
                ? $usuariosSinAcceso . ' usuarios no tienen acceso registrado.'
                : 'Todos los usuarios incluidos tienen acceso registrado.',
            $mayorCarga > 0
                ? 'La mayor carga registrada por un usuario es de ' . $mayorCarga . ' seguimientos.'
                : 'No se registran seguimientos asignados a usuarios.'
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
