<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioDestinatarioSyncService
{
    private $connection;
    private $rootPath;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function sincronizar($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.contacto_nombre,
                    seguimientos.contacto_cargo,
                    seguimientos.correo_verificado,
                    oficio.id AS oficio_id,
                    oficio.destinatario_nombre,
                    oficio.destinatario_cargo,
                    oficio.destinatario_correo,
                    oficio.archivo_pdf,
                    oficio.estado_oficio,
                    oficio.fecha_envio
                FROM seguimientos_vinculacion seguimientos
                LEFT JOIN oficios_vinculacion oficio
                    ON oficio.id = (
                        SELECT oficio_reciente.id
                        FROM oficios_vinculacion oficio_reciente
                        WHERE oficio_reciente.seguimiento_id = seguimientos.id
                        ORDER BY oficio_reciente.id DESC
                        LIMIT 1
                    )
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;

        if (!$fila) {
            return $this->error(
                'No fue posible validar los datos actuales del seguimiento.',
                403
            );
        }

        $oficioId = (int)($fila['oficio_id'] ?? 0);

        if ($oficioId <= 0) {
            return [
                'ok' => true,
                'cambios' => false,
                'pdf_invalidado' => false
            ];
        }

        if (
            strtoupper(trim((string)($fila['estado_oficio'] ?? ''))) === 'ENVIADO' ||
            trim((string)($fila['fecha_envio'] ?? '')) !== ''
        ) {
            return [
                'ok' => true,
                'cambios' => false,
                'pdf_invalidado' => false,
                'enviado' => true
            ];
        }

        $nombreActual = trim((string)($fila['contacto_nombre'] ?? ''));
        $cargoActual = trim((string)($fila['contacto_cargo'] ?? ''));
        $correoActual = trim((string)($fila['correo_verificado'] ?? ''));

        if ($correoActual !== '' && !filter_var($correoActual, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'El correo verificado actual no tiene un formato válido.',
                422
            );
        }

        $nombreAnterior = trim((string)($fila['destinatario_nombre'] ?? ''));
        $cargoAnterior = trim((string)($fila['destinatario_cargo'] ?? ''));
        $correoAnterior = trim((string)($fila['destinatario_correo'] ?? ''));
        $cambioDocumento =
            $nombreActual !== $nombreAnterior ||
            $cargoActual !== $cargoAnterior;
        $cambioCorreo = $correoActual !== $correoAnterior;

        if (!$cambioDocumento && !$cambioCorreo) {
            return [
                'ok' => true,
                'cambios' => false,
                'pdf_invalidado' => false
            ];
        }

        $archivoAnterior = trim((string)($fila['archivo_pdf'] ?? ''));
        $pdfInvalidado = $cambioDocumento && $archivoAnterior !== '';

        if ($pdfInvalidado) {
            $sqlActualizar = "UPDATE oficios_vinculacion
                    SET destinatario_nombre = ?,
                        destinatario_cargo = ?,
                        destinatario_correo = ?,
                        archivo_pdf = NULL,
                        fecha_generacion = NULL,
                        estado_oficio = 'BORRADOR',
                        asunto_correo = NULL,
                        cuerpo_correo = NULL,
                        error_envio = NULL
                    WHERE id = ?
                        AND seguimiento_id = ?";
        } else {
            $sqlActualizar = "UPDATE oficios_vinculacion
                    SET destinatario_nombre = ?,
                        destinatario_cargo = ?,
                        destinatario_correo = ?,
                        error_envio = NULL
                    WHERE id = ?
                        AND seguimiento_id = ?";
        }

        $stmtActualizar = $this->connection->prepare($sqlActualizar);
        $stmtActualizar->bind_param(
            'sssii',
            $nombreActual,
            $cargoActual,
            $correoActual,
            $oficioId,
            $seguimientoId
        );

        if (!$stmtActualizar->execute()) {
            return $this->error(
                'No fue posible sincronizar los datos verificados con el oficio.',
                500
            );
        }

        if ($pdfInvalidado) {
            $rutaAnterior = $this->rutaAbsolutaPdf($archivoAnterior);

            if ($rutaAnterior !== '' && is_file($rutaAnterior)) {
                @unlink($rutaAnterior);
            }
        }

        return [
            'ok' => true,
            'cambios' => true,
            'cambio_correo' => $cambioCorreo,
            'cambio_documento' => $cambioDocumento,
            'pdf_invalidado' => $pdfInvalidado,
            'correo_actual' => $correoActual
        ];
    }

    private function rutaAbsolutaPdf($rutaRelativa)
    {
        $rutaRelativa = trim((string)$rutaRelativa);

        if ($rutaRelativa === '') {
            return '';
        }

        $ruta = $this->rootPath . DIRECTORY_SEPARATOR . str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            ltrim($rutaRelativa, '/\\')
        );
        $rutaReal = realpath($ruta);
        $base = realpath(
            $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oficios'
        );

        if ($rutaReal === false || $base === false) {
            return '';
        }

        $prefijo = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($rutaReal, $prefijo) === 0 ? $rutaReal : '';
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
