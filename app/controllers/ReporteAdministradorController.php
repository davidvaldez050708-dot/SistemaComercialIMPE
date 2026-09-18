<?php

require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/../models/ReporteAdministradorModel.php';
require_once __DIR__ . '/../services/ReporteAdministradorPdfService.php';

class ReporteAdministradorController
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

    public function exportarPdf()
    {
        $this->validarAdministrador();

        try {
            $modeloUsuario = new UsuarioModel();
            $modeloReporte = new ReporteAdministradorModel();
            $rolesSeleccionados = $this->obtenerRolesSeleccionados($modeloUsuario);

            $usuarios = $modeloReporte->obtenerUsuariosConSeguimiento($rolesSeleccionados ?? []);
            $pendientes = $modeloReporte->obtenerSeguimientosQueRequierenAtencion($rolesSeleccionados ?? []);

            if ($rolesSeleccionados === null) {
                $conteoUsuarios = $modeloUsuario->contarUsuarios();
                $rolesRegistrados = $modeloUsuario->contarRoles();
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

            foreach ($usuarios as $usuario) {
                $totalSeguimientos += (int)($usuario['total_seguimientos'] ?? 0);
                $accionesPendientes += (int)($usuario['acciones_pendientes'] ?? 0);
                $accionesVencidas += (int)($usuario['acciones_vencidas'] ?? 0);
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

            $servicio = new ReporteAdministradorPdfService();
            $resultado = $servicio->generar([
                'resumen' => [
                    'usuarios_registrados' => (int)($conteoUsuarios['registrados'] ?? 0),
                    'usuarios_activos' => (int)($conteoUsuarios['activos'] ?? 0),
                    'usuarios_inactivos' => (int)($conteoUsuarios['inactivos'] ?? 0),
                    'roles_registrados' => $rolesRegistrados,
                    'total_seguimientos' => $totalSeguimientos,
                    'acciones_pendientes' => $accionesPendientes,
                    'acciones_vencidas' => $accionesVencidas,
                    'requieren_atencion' => $requierenAtencion
                ],
                'usuarios' => $usuarios,
                'pendientes' => $pendientes,
                'fecha_generacion' => date('d/m/Y H:i'),
                'generado_por' => trim(
                    (string)($_SESSION['nombre'] ?? '') . ' ' .
                    (string)($_SESSION['apellidos'] ?? '')
                ),
                'generado_por_rol' => (string)($_SESSION['rol'] ?? '')
            ]);

            if (!($resultado['ok'] ?? false)) {
                error_log(
                    '[reporte_administrador_pdf] ' .
                    (string)($resultado['mensaje_tecnico'] ?? $resultado['mensaje'] ?? 'Error sin detalle.')
                );
                $this->responderError(
                    (string)($resultado['mensaje'] ?? 'No fue posible generar el reporte administrativo.')
                );
            }

            $contenidoPdf = (string)($resultado['contenido_pdf'] ?? '');
            $nombreArchivo = (string)($resultado['nombre_archivo'] ?? 'Reporte_Administrativo_Usuarios.pdf');

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
            header('Content-Length: ' . strlen($contenidoPdf));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo $contenidoPdf;
            exit;
        } catch (Throwable $error) {
            error_log('[reporte_administrador_pdf] ' . $error->getMessage());
            $this->responderError('No fue posible generar el reporte administrativo.');
        }
    }

    private function obtenerRolesSeleccionados(UsuarioModel $modeloUsuario)
    {
        if (!isset($_GET['filtrar_roles'])) {
            return null;
        }

        if ((string)($_GET['todos_roles'] ?? '') === '1') {
            return null;
        }

        $rolesRecibidos = $_GET['roles'] ?? [];

        if (!is_array($rolesRecibidos) || empty($rolesRecibidos)) {
            $this->responderSolicitudInvalida(
                'Selecciona al menos un rol o la opción Todos los roles.'
            );
        }

        $rolesSeleccionados = [];

        foreach ($rolesRecibidos as $rolId) {
            if (!is_scalar($rolId) || preg_match('/^\d+$/', (string)$rolId) !== 1) {
                $this->responderSolicitudInvalida('Se recibió un rol no válido.');
            }

            $rolId = (int)$rolId;

            if ($rolId <= 0) {
                $this->responderSolicitudInvalida('Se recibió un rol no válido.');
            }

            $rolesSeleccionados[] = $rolId;
        }

        $rolesSeleccionados = array_values(array_unique($rolesSeleccionados));
        $rolesDisponibles = $modeloUsuario->obtenerRolesActivos();
        $idsDisponibles = array_map(
            static function ($rol) {
                return (int)($rol['id'] ?? 0);
            },
            $rolesDisponibles
        );

        foreach ($rolesSeleccionados as $rolId) {
            if (!in_array($rolId, $idsDisponibles, true)) {
                $this->responderSolicitudInvalida(
                    'Uno de los roles seleccionados no está disponible.'
                );
            }
        }

        return $rolesSeleccionados;
    }

    private function responderSolicitudInvalida($mensaje)
    {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)$mensaje;
        exit;
    }

    private function validarAdministrador()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header('Location: ' . BASE_URL . 'index.php?controller=login&action=mostrarLogin');
            exit;
        }

        if ((int)($_SESSION['rol_id'] ?? 0) !== 1) {
            http_response_code(403);
            die('No tienes permiso para generar este reporte.');
        }
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

    private function responderError($mensaje)
    {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string)$mensaje;
        exit;
    }
}
