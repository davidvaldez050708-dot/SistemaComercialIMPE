<?php

require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../services/AliadoDifusionService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class AliadoController
{
    public function index()
    {
        $this->validarPermiso('aliados.ver');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $modelo = new AliadoModel();

        $estructuraAliadosDisponible = $modelo->estructuraDisponible();
        $filtros = [
            'buscar' => trim((string)($_GET['buscar'] ?? '')),
            'estado_id' => (int)($_GET['estado_id'] ?? 0),
            'municipio_id' => (int)($_GET['municipio_id'] ?? 0),
            'analista_id' => (int)($_GET['analista_id'] ?? 0)
        ];

        $aliadosBase = $estructuraAliadosDisponible
            ? $modelo->obtenerListado($usuarioId, $esAdministrador, [])
            : [];
        // El directorio se carga completo dentro del alcance autorizado.
        // Los filtros son reactivos en cliente, igual que en Seguimiento.
        $aliados = $aliadosBase;

        $estadosAliados = [];
        $municipiosAliados = [];
        $analistasAliados = [];

        foreach ($aliadosBase as $aliado) {
            $estadoId = (int)($aliado['estado_id'] ?? 0);
            $municipioId = (int)($aliado['municipio_id'] ?? 0);
            $analistaId = (int)($aliado['analista_id'] ?? 0);

            if ($estadoId > 0) {
                $estadosAliados[$estadoId] = [
                    'id' => $estadoId,
                    'nombre' => (string)($aliado['estado_nombre'] ?? '')
                ];
            }

            if ($municipioId > 0) {
                $municipiosAliados[$municipioId] = [
                    'id' => $municipioId,
                    'estado_id' => $estadoId,
                    'nombre' => (string)($aliado['municipio_nombre'] ?? ''),
                    'estado_nombre' => (string)($aliado['estado_nombre'] ?? '')
                ];
            }

            if ($analistaId > 0) {
                $analistasAliados[$analistaId] = [
                    'id' => $analistaId,
                    'nombre' => (string)($aliado['analista_nombre'] ?? '')
                ];
            }
        }

        uasort($estadosAliados, static function ($a, $b) {
            return strcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });
        uasort($municipiosAliados, static function ($a, $b) {
            $estado = strcasecmp(
                (string)$a['estado_nombre'],
                (string)$b['estado_nombre']
            );
            return $estado !== 0
                ? $estado
                : strcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });
        uasort($analistasAliados, static function ($a, $b) {
            return strcasecmp((string)$a['nombre'], (string)$b['nombre']);
        });

        $resumenAliados = [
            'total' => count($aliadosBase),
            'con_correo' => 0,
            'con_whatsapp' => 0,
            'convocatorias_vigentes' => 0
        ];

        foreach ($aliadosBase as $aliado) {
            $correo = trim((string)($aliado['correo_contacto'] ?? ''));
            $whatsappVerificado = trim(
                (string)($aliado['whatsapp_verificado'] ?? '')
            );
            $whatsappDifusionConfirmado =
                (int)($aliado['contacto_difusion_confirmado_whatsapp'] ?? 0) === 1;

            if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $resumenAliados['con_correo']++;
            }
            if ($whatsappVerificado !== '' || $whatsappDifusionConfirmado) {
                $resumenAliados['con_whatsapp']++;
            }
        }

        $puedeConsultarConvocatorias = tienePermiso('convocatorias.ver');

        if ($estructuraAliadosDisponible && $puedeConsultarConvocatorias) {
            $resumenAliados['convocatorias_vigentes'] =
                $modelo->contarConvocatoriasVigentesPorEstados(
                    array_keys($estadosAliados)
                );
        }

        $puedeCompartirCorreo =
            tienePermiso('aliados.compartir_correo') &&
            $puedeConsultarConvocatorias;
        $puedeVerHistorial = tienePermiso('aliados.ver_historial');
        $estructuraContactosDisponible = $modelo->contactosDisponibles();
        $puedeGestionarContactos =
            tienePermiso('aliados.gestionar_contactos') &&
            $estructuraContactosDisponible;

        $tituloPagina = 'Aliados';
        $subtituloPagina =
            'Instituciones con convenio formalizado y relación institucional activa';
        $opcionActiva = 'aliados';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/aliados/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function prepararEnvio()
    {
        $this->validarPermiso('aliados.compartir_correo');
        $this->validarPermiso('convocatorias.ver');

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $convocatorias = $modelo->obtenerConvocatoriasDisponibles(
            (int)$aliado['estado_id']
        );

        $this->responder([
            'ok' => true,
            'aliado' => $aliado,
            'convocatorias' => $convocatorias
        ]);
    }

    public function borradorEnvio()
    {
        $this->validarPermiso('aliados.compartir_correo');
        $this->validarPermiso('convocatorias.ver');

        $modelo = new AliadoModel();
        $servicio = new AliadoDifusionService();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);
        $convocatoriaId = (int)($_GET['convocatoria_id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $convocatoria = $modelo->obtenerConvocatoriaAplicable(
            $convocatoriaId,
            (int)$aliado['estado_id']
        );

        if (!$convocatoria) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La convocatoria no está vigente para este territorio.'
            ], 409);
        }

        $this->responder([
            'ok' => true,
            'borrador' => $servicio->construirBorrador($aliado, $convocatoria),
            'convocatoria' => $convocatoria
        ]);
    }

    public function historial()
    {
        $this->validarPermiso('aliados.ver_historial');

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        $this->responder([
            'ok' => true,
            'aliado' => $aliado,
            'historial' => $modelo->obtenerHistorial(
                $seguimientoId,
                $usuarioId,
                $esAdministrador
            )
        ]);
    }

    public function contactos()
    {
        $this->validarPermiso('aliados.ver');

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_GET['id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        if (!$modelo->contactosDisponibles()) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Falta aplicar la migración de contactos de difusión.'
            ], 409);
        }

        $this->responder([
            'ok' => true,
            'aliado' => $aliado,
            'contactos' => $modelo->obtenerContactosDifusion($aliado),
            'puede_gestionar' => tienePermiso('aliados.gestionar_contactos')
        ]);
    }

    public function guardarContacto()
    {
        $this->validarPermiso('aliados.gestionar_contactos');
        $this->validarMetodoPost();

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        if (!$modelo->contactosDisponibles()) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Falta aplicar la migración de contactos de difusión.'
            ], 409);
        }

        $numero = trim((string)($_POST['numero'] ?? ''));
        $digitos = preg_replace('/[^0-9]+/', '', $numero);

        if (strlen($digitos) < 7 || strlen($digitos) > 15) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Ingresa un número válido de entre 7 y 15 dígitos.'
            ], 422);
        }

        $etiqueta = trim((string)($_POST['etiqueta'] ?? 'Difusión'));
        if ($etiqueta === '') {
            $etiqueta = 'Difusión';
        }
        if (mb_strlen($etiqueta) > 80) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La etiqueta del contacto es demasiado larga.'
            ], 422);
        }

        $origen = strtoupper(trim((string)($_POST['origen'] ?? 'CUENTA_CLAVE')));
        $origenesPermitidos = [
            'CUENTA_CLAVE',
            'WHATSAPP_VERIFICADO',
            'TELEFONO_VERIFICADO',
            'TELEFONO_FUENTE'
        ];

        if (!in_array($origen, $origenesPermitidos, true)) {
            $origen = 'CUENTA_CLAVE';
        }

        $campoOrigen = [
            'WHATSAPP_VERIFICADO' => 'whatsapp_verificado',
            'TELEFONO_VERIFICADO' => 'telefono_verificado',
            'TELEFONO_FUENTE' => 'telefono_fuente'
        ];

        if (isset($campoOrigen[$origen])) {
            $numeroOrigen = preg_replace(
                '/[^0-9]+/',
                '',
                (string)($aliado[$campoOrigen[$origen]] ?? '')
            );

            if ($numeroOrigen === '' || $numeroOrigen !== $digitos) {
                $origen = 'CUENTA_CLAVE';
            }
        }

        try {
            $modelo->guardarContactoDifusion(
                $seguimientoId,
                $usuarioId,
                [
                    'id' => (int)($_POST['contacto_id'] ?? 0),
                    'numero' => $numero,
                    'etiqueta' => $etiqueta,
                    'origen' => $origen,
                    'confirmado_whatsapp' =>
                        (int)($_POST['confirmado_whatsapp'] ?? 0) === 1,
                    'preferido_difusion' =>
                        (int)($_POST['preferido_difusion'] ?? 0) === 1
                ]
            );
        } catch (Throwable $error) {
            $this->responder([
                'ok' => false,
                'mensaje' => $error->getMessage()
            ], 409);
        }

        $aliadoActualizado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        $this->responder([
            'ok' => true,
            'mensaje' => 'Contacto de difusión guardado correctamente.',
            'contactos' => $modelo->obtenerContactosDifusion($aliadoActualizado)
        ]);
    }

    public function eliminarContacto()
    {
        $this->validarPermiso('aliados.gestionar_contactos');
        $this->validarMetodoPost();

        $modelo = new AliadoModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $contactoId = (int)($_POST['contacto_id'] ?? 0);

        $aliado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        if (!$aliado) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este aliado.'
            ], 403);
        }

        if ($contactoId <= 0 || !$modelo->desactivarContactoDifusion(
            $contactoId,
            $seguimientoId,
            $usuarioId
        )) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible retirar el contacto de difusión.'
            ], 422);
        }

        $aliadoActualizado = $modelo->obtenerAliado(
            $seguimientoId,
            $usuarioId,
            $esAdministrador
        );

        $this->responder([
            'ok' => true,
            'mensaje' => 'Contacto retirado del directorio de difusión.',
            'contactos' => $modelo->obtenerContactosDifusion($aliadoActualizado)
        ]);
    }

    public function enviarConvocatoria()
    {
        $this->validarPermiso('aliados.compartir_correo');
        $this->validarPermiso('convocatorias.ver');
        $this->validarMetodoPost();

        $servicio = new AliadoDifusionService();
        $resultado = $servicio->enviar(
            (int)($_SESSION['usuario_id'] ?? 0),
            (int)($_POST['seguimiento_id'] ?? 0),
            (int)($_POST['convocatoria_id'] ?? 0),
            $_POST['asunto'] ?? '',
            $_POST['mensaje'] ?? '',
            (int)($_POST['confirmar_reenvio'] ?? 0) === 1,
            (int)($_SESSION['rol_id'] ?? 0) === 1
        );

        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigoHttp);
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo 'Sesión no válida.';
            exit;
        }

        if (!tienePermiso($codigo)) {
            http_response_code(403);
            echo 'No tienes permiso para realizar esta operación.';
            exit;
        }
    }

    private function validarMetodoPost()
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
