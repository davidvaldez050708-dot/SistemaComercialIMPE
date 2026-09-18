<?php

require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/../models/ReporteAdministradorModel.php';

class ReporteAdministradorDataService
{
    private const ESTADOS_SEGUIMIENTO = [
        'NUEVO' => 'Nuevo',
        'CONTACTANDO' => 'Contactando',
        'DATOS_VERIFICADOS' => 'Datos verificados',
        'NO_LOCALIZADO' => 'No localizado',
        'DESCARTADO' => 'Descartado',
        'OFICIO_PREPARADO' => 'Oficio preparado',
        'ESPERANDO_RESPUESTA' => 'Esperando respuesta'
    ];

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
        $pendientes = $this->modeloReporte->obtenerSeguimientosQueRequierenAtencion($rolesFiltro);

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
        $accionesPendientes = 0;
        $accionesVencidas = 0;
        $usuariosPorRol = [];
        $usuariosSinAcceso = 0;
        $mayorCarga = 0;

        foreach ($usuarios as $usuario) {
            $totalUsuario = (int)($usuario['total_seguimientos'] ?? 0);
            $totalSeguimientos += $totalUsuario;
            $accionesPendientes += (int)($usuario['acciones_pendientes'] ?? 0);
            $accionesVencidas += (int)($usuario['acciones_vencidas'] ?? 0);
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

        $requierenAtencion = 0;
        $ahora = new DateTimeImmutable();

        foreach ($pendientes as &$pendiente) {
            $codigo = (string)($pendiente['estado_seguimiento'] ?? '');
            $pendiente['estado_label'] = self::ESTADOS_SEGUIMIENTO[$codigo] ?? $codigo;

            $sinActividad = trim((string)($pendiente['ultima_interaccion_at'] ?? '')) === '';
            $masSieteDias = !$sinActividad && (int)($pendiente['dias_sin_actividad'] ?? 0) > 7;
            $proximaAccion = $this->crearFecha($pendiente['proxima_accion_at'] ?? null);
            $accionVencida = $proximaAccion instanceof DateTimeImmutable && $proximaAccion <= $ahora;

            if ($sinActividad || $masSieteDias || $accionVencida) {
                $requierenAtencion++;
            }
        }
        unset($pendiente);

        $resumen = [
            'usuarios_registrados' => (int)($conteoUsuarios['registrados'] ?? 0),
            'usuarios_activos' => (int)($conteoUsuarios['activos'] ?? 0),
            'usuarios_inactivos' => (int)($conteoUsuarios['inactivos'] ?? 0),
            'roles_registrados' => (int)$rolesRegistrados,
            'total_seguimientos' => $totalSeguimientos,
            'acciones_pendientes' => $accionesPendientes,
            'acciones_vencidas' => $accionesVencidas,
            'requieren_atencion' => $requierenAtencion
        ];

        $hallazgos = [
            $accionesVencidas > 0
                ? 'Existen ' . $accionesVencidas . ' acciones vencidas.'
                : 'No se registran acciones vencidas.',
            $requierenAtencion > 0
                ? $requierenAtencion . ' seguimientos requieren atención.'
                : 'No se registran seguimientos que requieran atención.',
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
            'pendientes' => $pendientes,
            'usuarios_por_rol' => $datosRoles,
            'estado_usuarios' => [
                ['etiqueta' => 'Activos', 'valor' => (int)$resumen['usuarios_activos']],
                ['etiqueta' => 'Inactivos', 'valor' => (int)$resumen['usuarios_inactivos']]
            ],
            'hallazgos' => $hallazgos
        ];
    }

    private function crearFecha($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor);
        } catch (Exception $error) {
            return null;
        }
    }
}
