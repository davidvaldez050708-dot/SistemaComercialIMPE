<?php

require_once __DIR__ . '/../models/WhatsAppModel.php';
require_once __DIR__ . '/../services/WhatsAppCloudApiService.php';

class WhatsAppWebhookController
{
    public function webhook()
    {
        $metodo = strtoupper(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
        );

        if ($metodo === 'GET') {
            $this->verificar();
        }

        if ($metodo === 'POST') {
            $this->recibir();
        }

        http_response_code(405);
        echo 'Método no permitido.';
        exit;
    }

    public function verificar()
    {
        $servicio = new WhatsAppCloudApiService();

        $modo = trim((string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? ''));
        $token = trim((string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''));
        $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        $esperado = trim($servicio->obtenerVerifyToken());

        if (
            $modo === 'subscribe' &&
            $esperado !== '' &&
            $token !== '' &&
            hash_equals($esperado, $token)
        ) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
            exit;
        }

        http_response_code(403);
        echo 'Verificación de webhook rechazada.';
        exit;
    }

    public function recibir()
    {
        $payload = (string)file_get_contents('php://input');
        $firma = trim((string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));

        $servicio = new WhatsAppCloudApiService();

        if (!$servicio->validarFirmaWebhook($payload, $firma)) {
            http_response_code(401);
            echo 'Firma de webhook inválida.';
            exit;
        }

        $modelo = new WhatsAppModel();

        if (!$modelo->estructuraDisponible()) {
            http_response_code(503);
            echo 'Estructura de WhatsApp no disponible.';
            exit;
        }

        $datos = json_decode($payload, true);

        if (!is_array($datos)) {
            http_response_code(400);
            echo 'Payload inválido.';
            exit;
        }

        $eventoId = $modelo->registrarEventoWebhook(
            $payload,
            $this->detectarTipoEvento($datos)
        );

        if ($eventoId === 0) {
            http_response_code(200);
            echo 'EVENT_RECEIVED';
            exit;
        }

        try {
            $this->procesarPayload($modelo, $datos);
            $modelo->finalizarEventoWebhook($eventoId);
        } catch (Throwable $error) {
            $modelo->finalizarEventoWebhook(
                $eventoId,
                $error->getMessage()
            );

            error_log(
                'WhatsApp webhook: ' . $error->getMessage()
            );

            /*
             * Meta reintenta respuestas no 2xx. En errores de procesamiento
             * transitorios interesa conservar ese comportamiento.
             */
            http_response_code(500);
            echo 'EVENT_PROCESSING_ERROR';
            exit;
        }

        http_response_code(200);
        echo 'EVENT_RECEIVED';
        exit;
    }

    private function procesarPayload(WhatsAppModel $modelo, array $datos)
    {
        foreach ($datos['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = is_array($change['value'] ?? null)
                    ? $change['value']
                    : [];

                $phoneNumberId = trim(
                    (string)($value['metadata']['phone_number_id'] ?? '')
                );

                if ($phoneNumberId === '') {
                    continue;
                }

                $cuenta = $modelo->obtenerCuentaPorPhoneNumberId(
                    $phoneNumberId
                );

                if (!$cuenta) {
                    /*
                     * No auto-creamos canales desde un webhook. El Administrador
                     * debe registrar explícitamente cada Phone Number ID.
                     */
                    continue;
                }

                $nombrePorTelefono = [];

                foreach ($value['contacts'] ?? [] as $contacto) {
                    $waId = preg_replace(
                        '/[^0-9]+/',
                        '',
                        (string)($contacto['wa_id'] ?? '')
                    );

                    if ($waId === '') {
                        continue;
                    }

                    $nombrePorTelefono[$waId] = trim(
                        (string)($contacto['profile']['name'] ?? '')
                    );
                }

                foreach ($value['messages'] ?? [] as $mensaje) {
                    $telefono = preg_replace(
                        '/[^0-9]+/',
                        '',
                        (string)($mensaje['from'] ?? '')
                    );

                    if ($telefono === '') {
                        continue;
                    }

                    [$tipo, $contenido] =
                        $this->extraerContenidoMensaje($mensaje);

                    $modelo->registrarMensajeEntrada(
                        (int)$cuenta['id'],
                        $telefono,
                        $nombrePorTelefono[$telefono] ?? '',
                        (string)($mensaje['id'] ?? ''),
                        $tipo,
                        $contenido,
                        (int)($mensaje['timestamp'] ?? 0)
                    );
                }

                foreach ($value['statuses'] ?? [] as $estado) {
                    $modelo->actualizarEstadoMensaje(
                        (string)($estado['id'] ?? ''),
                        (string)($estado['status'] ?? ''),
                        (int)($estado['timestamp'] ?? 0),
                        is_array($estado['errors'] ?? null)
                            ? $estado['errors']
                            : []
                    );
                }
            }
        }
    }

    private function extraerContenidoMensaje(array $mensaje)
    {
        $tipo = strtolower(trim((string)($mensaje['type'] ?? 'unknown')));

        if ($tipo === 'text') {
            return [
                'TEXT',
                (string)($mensaje['text']['body'] ?? '')
            ];
        }

        if ($tipo === 'button') {
            return [
                'BUTTON',
                (string)($mensaje['button']['text'] ?? '[Botón]')
            ];
        }

        if ($tipo === 'interactive') {
            $interactive = is_array($mensaje['interactive'] ?? null)
                ? $mensaje['interactive']
                : [];

            if (($interactive['type'] ?? '') === 'button_reply') {
                return [
                    'INTERACTIVE',
                    (string)(
                        $interactive['button_reply']['title'] ??
                        '[Respuesta interactiva]'
                    )
                ];
            }

            if (($interactive['type'] ?? '') === 'list_reply') {
                return [
                    'INTERACTIVE',
                    (string)(
                        $interactive['list_reply']['title'] ??
                        '[Respuesta de lista]'
                    )
                ];
            }
        }

        if ($tipo === 'image') {
            $caption = trim((string)($mensaje['image']['caption'] ?? ''));
            return [
                'IMAGE',
                $caption !== '' ? $caption : '[Imagen recibida]'
            ];
        }

        if ($tipo === 'document') {
            $nombre = trim(
                (string)($mensaje['document']['filename'] ?? '')
            );
            return [
                'DOCUMENT',
                $nombre !== '' ? '[Documento] ' . $nombre : '[Documento recibido]'
            ];
        }

        if ($tipo === 'audio' || $tipo === 'voice') {
            return ['AUDIO', '[Audio recibido]'];
        }

        if ($tipo === 'video') {
            $caption = trim((string)($mensaje['video']['caption'] ?? ''));
            return [
                'VIDEO',
                $caption !== '' ? $caption : '[Video recibido]'
            ];
        }

        if ($tipo === 'location') {
            return ['LOCATION', '[Ubicación recibida]'];
        }

        return [
            strtoupper($tipo !== '' ? $tipo : 'UNKNOWN'),
            '[Mensaje recibido: ' . ($tipo !== '' ? $tipo : 'desconocido') . ']'
        ];
    }

    private function detectarTipoEvento(array $datos)
    {
        foreach ($datos['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = is_array($change['value'] ?? null)
                    ? $change['value']
                    : [];

                if (!empty($value['messages'])) {
                    return 'MESSAGE';
                }

                if (!empty($value['statuses'])) {
                    return 'STATUS';
                }
            }
        }

        return 'OTHER';
    }
}
