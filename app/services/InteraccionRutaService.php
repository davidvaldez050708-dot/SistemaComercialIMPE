<?php

require_once __DIR__ . '/../../config/db_connection.php';

class InteraccionRutaService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function registrarInformativa($seguimientoId, $usuarioId, $datos)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        $seguimiento = $this->obtenerSeguimientoAvanzado($seguimientoId, $usuarioId);

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        if ((string)($seguimiento['estado_seguimiento'] ?? '') === 'DESCARTADO') {
            return $this->error('Este seguimiento está descartado.', 409);
        }

        if (!$this->estaEnRutaAvanzada($seguimiento)) {
            return $this->error(
                'La interacción informativa se usa a partir del envío del oficio/correo.',
                409
            );
        }

        $canalFormulario = strtoupper(trim((string)($datos['canal'] ?? '')));
        $canales = [
            'LLAMADA' => 'LLAMADA_IP',
            'WHATSAPP' => 'WHATSAPP',
            'CORREO' => 'CORREO',
            'OTRO' => 'NOTA'
        ];
        $canal = $canales[$canalFormulario] ?? '';

        if ($canal === '') {
            return $this->error('Selecciona un canal válido.', 422);
        }

        $resultadoFormulario = strtoupper(trim((string)($datos['resultado'] ?? '')));
        $resultados = [
            'SIN_RESPUESTA' => 'SIN_RESPUESTA',
            'NUMERO_INCORRECTO' => 'NUMERO_INCORRECTO',
            'CONTACTO_INCORRECTO' => 'OCUPADO',
            'CONTACTO_CORRECTO' => 'CONTACTADO',
            'SOLICITO_INFORMACION' => 'MENSAJE_ENVIADO',
            'SOLICITO_LLAMAR_DESPUES' => 'SOLICITO_LLAMAR_DESPUES',
            'NO_INTERESADO' => 'OTRO',
            'OTRO' => 'OTRO'
        ];
        $resultado = $resultados[$resultadoFormulario] ?? '';

        if ($resultado === '') {
            return $this->error('Selecciona un resultado válido.', 422);
        }

        $personaAtendio = trim((string)($datos['persona_atendio'] ?? ''));
        $observacion = trim((string)($datos['observacion'] ?? ''));
        $fechaInicio = $this->normalizarFechaHora($datos['fecha_inicio'] ?? '');

        if ($fechaInicio === null) {
            $fechaInicio = date('Y-m-d H:i:s');
        }

        $notas = trim(implode("\n", array_filter([
            $personaAtendio !== '' ? 'Persona atendió: ' . $personaAtendio : '',
            $resultadoFormulario === 'NO_INTERESADO'
                ? 'Resultado registrado: No interesado'
                : '',
            $observacion
        ])));

        if ($notas === '') {
            $notas = 'Interacción informativa registrada durante la ruta de vinculación.';
        }

        $this->connection->begin_transaction();

        try {
            $sql = "INSERT INTO interacciones_vinculacion (
                        seguimiento_id,
                        usuario_id,
                        canal,
                        fecha_inicio,
                        resultado,
                        notas
                    ) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'iissss',
                $seguimientoId,
                $usuarioId,
                $canal,
                $fechaInicio,
                $resultado,
                $notas
            );
            $stmt->execute();

            $interaccionId = (int)$this->connection->insert_id;

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                    SET ultima_interaccion_at = ?
                    WHERE id = ?
                        AND analista_id = ?
                        AND activo = 1";
            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param(
                'sii',
                $fechaInicio,
                $seguimientoId,
                $usuarioId
            );
            $stmtSeguimiento->execute();

            $this->connection->commit();

            return [
                'ok' => true,
                'mensaje' => 'Interacción registrada en el expediente sin modificar la ruta.',
                'interaccion' => [
                    'id' => $interaccionId,
                    'fecha_label' => $this->formatearFecha($fechaInicio),
                    'canal_label' => $this->etiquetarCanal($canal),
                    'resultado_label' => $this->etiquetarResultado($resultadoFormulario),
                    'notas' => $notas
                ]
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Error registrando interacción informativa: ' . $error->getMessage());

            return $this->error(
                'No fue posible registrar la interacción.',
                500
            );
        }
    }

    private function obtenerSeguimientoAvanzado($seguimientoId, $usuarioId)
    {
        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.estado_seguimiento,
                    oficio.fecha_envio,
                    oficio.estado_oficio
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

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function estaEnRutaAvanzada($seguimiento)
    {
        return trim((string)($seguimiento['fecha_envio'] ?? '')) !== '' ||
            strtoupper(trim((string)($seguimiento['estado_oficio'] ?? ''))) === 'ENVIADO' ||
            strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? ''))) === 'ESPERANDO_RESPUESTA';
    }

    private function normalizarFechaHora($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        $formatos = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

        foreach ($formatos as $formato) {
            $fecha = DateTime::createFromFormat($formato, $valor);

            if ($fecha instanceof DateTime) {
                return $fecha->format('Y-m-d H:i:s');
            }
        }

        try {
            return (new DateTime($valor))->format('Y-m-d H:i:s');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function formatearFecha($fecha)
    {
        try {
            $objeto = new DateTime((string)$fecha);
            $meses = [
                'Jan' => 'ene',
                'Feb' => 'feb',
                'Mar' => 'mar',
                'Apr' => 'abr',
                'May' => 'may',
                'Jun' => 'jun',
                'Jul' => 'jul',
                'Aug' => 'ago',
                'Sep' => 'sep',
                'Oct' => 'oct',
                'Nov' => 'nov',
                'Dec' => 'dic'
            ];

            return strtr($objeto->format('d M Y · H:i'), $meses);
        } catch (Throwable $error) {
            return 'Ahora';
        }
    }

    private function etiquetarCanal($canal)
    {
        $etiquetas = [
            'LLAMADA_IP' => 'Llamada',
            'WHATSAPP' => 'WhatsApp',
            'CORREO' => 'Correo',
            'NOTA' => 'Nota'
        ];

        return $etiquetas[$canal] ?? 'Interacción';
    }

    private function etiquetarResultado($resultado)
    {
        $etiquetas = [
            'SIN_RESPUESTA' => 'Sin respuesta',
            'NUMERO_INCORRECTO' => 'Número incorrecto',
            'CONTACTO_INCORRECTO' => 'Contacto incorrecto',
            'CONTACTO_CORRECTO' => 'Contacto correcto',
            'SOLICITO_INFORMACION' => 'Solicitó información',
            'SOLICITO_LLAMAR_DESPUES' => 'Solicitó volver a llamar',
            'NO_INTERESADO' => 'No interesado',
            'OTRO' => 'Otro'
        ];

        return $etiquetas[$resultado] ?? 'Otro';
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
