<?php

require_once __DIR__ . '/TelefoniaExtensionService.php';
require_once __DIR__ . '/TelefoniaActividadService.php';
require_once __DIR__ . '/TelefoniaContactosService.php';
require_once __DIR__ . '/TelefoniaMarcacionesVentasService.php';

/** Fuente compartida del Inicio de Ventas y del marcador autónomo. */
class TelefoniaMarcadorPanelService
{
    public function obtener(int $usuarioId): array
    {
        $panel = [
            'extension' => '',
            'caller_id' => '',
            'mensaje' => '',
            'mensaje_contactos' => '',
            'contactos' => [],
            'historial' => [
                'atenciones' => 0,
                'contestadas' => 0,
                'salientes' => 0,
                'entrantes' => 0,
                'segundos' => 0,
                'recientes' => []
            ],
            'csrf' => TelefoniaContactosService::tokenFormulario()
        ];

        try {
            $asignacion = (new TelefoniaExtensionService())->resolverParaUsuario($usuarioId);
            if ($asignacion && !empty($asignacion['permite_salientes'])) {
                $panel['extension'] = (string)($asignacion['extension'] ?? '');
                $panel['caller_id'] = (string)($asignacion['caller_id'] ?? '');
                try {
                    // El historiador reconstruye la asociación cuando el
                    // webhook llegó después de cerrar el marcador.
                    (new TelefoniaMarcacionesVentasService())
                        ->reconciliarPendientes($usuarioId, $panel['extension']);
                    $panel['historial'] = (new TelefoniaActividadService())
                        ->consultar($panel['extension'], $usuarioId);
                } catch (Throwable $e) {
                    error_log('Historial del marcador: ' . $e->getMessage());
                    $panel['mensaje'] = 'La extensión está disponible, pero el historial no se pudo consultar.';
                }
            } else {
                $panel['mensaje'] = 'El administrador debe asignarte una extensión activa con llamadas salientes.';
            }
        } catch (Throwable $e) {
            error_log('Extensión del marcador: ' . $e->getMessage());
            $panel['mensaje'] = 'No fue posible consultar tu extensión. Inténtalo nuevamente.';
        }

        try {
            $panel['contactos'] = (new TelefoniaContactosService())->listar($usuarioId);
        } catch (Throwable $e) {
            error_log('Teléfonos personales: ' . $e->getMessage());
            $panel['mensaje_contactos'] = 'No fue posible consultar los teléfonos guardados.';
        }

        return $panel;
    }
}
