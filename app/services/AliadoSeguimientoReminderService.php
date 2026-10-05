<?php

require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../helpers/ReminderHelper.php';

class AliadoSeguimientoReminderService
{
    private $modelo;
    private $connection;

    public function __construct()
    {
        $this->modelo = new AliadoModel();

        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtener($usuarioId, $limite = 10)
    {
        $usuarioId = (int)$usuarioId;
        $limite = max(1, min(30, (int)$limite));

        if (
            $usuarioId <= 0 ||
            !$this->modelo->seguimientoConvocatoriasDisponible()
        ) {
            return [
                'ok' => true,
                'requiere_migracion' => false,
                'recordatorios' => [],
                'avisos' => []
            ];
        }

        $filas = $this->modelo->obtenerRecordatoriosSeguimientoAliados(
            $usuarioId,
            $limite
        );

        $recordatorios = [];
        $avisos = [];
        $tablaRecordatoriosDisponible =
            existeTablaRecordatoriosVinculacion($this->connection);
        $ahora = new DateTime();
        $limiteVentana = (clone $ahora)->modify('+24 hours');

        foreach ($filas as $fila) {
            $fecha = trim(
                (string)($fila['proximo_seguimiento_at'] ?? '')
            );

            if ($fecha === '') {
                continue;
            }

            try {
                $momento = new DateTime($fecha);
            } catch (Throwable $error) {
                continue;
            }

            if ($momento > $limiteVentana) {
                continue;
            }

            $seguimientoConvocatoriaId =
                (int)($fila['seguimiento_convocatoria_id'] ?? 0);
            $seguimientoId = (int)($fila['seguimiento_id'] ?? 0);

            if (
                $seguimientoConvocatoriaId <= 0 ||
                $seguimientoId <= 0
            ) {
                continue;
            }

            $convocatoria = trim(
                (string)($fila['convocatoria_titulo'] ?? '')
            );
            $estadoSeguimiento = strtoupper(
                trim((string)($fila['estado'] ?? ''))
            );
            $accion = $estadoSeguimiento === 'SOLICITA_INFORMACION'
                ? 'Responder información pendiente'
                : 'Dar seguimiento por WhatsApp';

            if ($convocatoria !== '') {
                $accion .= ' · ' . $convocatoria;
            }

            $descripcion = describirRecordatorioSeguimiento($fecha);
            $url = BASE_URL .
                'index.php?controller=aliado&action=estado&estado_id=' .
                (int)($fila['estado_id'] ?? 0) .
                '&abrir_seguimiento=' .
                $seguimientoId .
                '&seguimiento_convocatoria_id=' .
                $seguimientoConvocatoriaId;

            $recordatorio = [
                'id' => $seguimientoId,
                'seguimiento_id' => $seguimientoId,
                'seguimiento_convocatoria_id' =>
                    $seguimientoConvocatoriaId,
                'nombre_entidad' =>
                    (string)($fila['nombre_entidad'] ?? 'Aliado'),
                'accion' => $accion,
                'fecha' => $momento->format('Y-m-d H:i:s'),
                'estado' => (string)($descripcion['estado'] ?? 'normal'),
                'etiqueta' => (string)($descripcion['etiqueta'] ?? ''),
                'icono' => 'bi-whatsapp',
                'prioridad' => $this->prioridad(
                    (string)($descripcion['estado'] ?? 'normal')
                ),
                'url' => $url
            ];

            $recordatorios[] = $recordatorio;

            if (!$tablaRecordatoriosDisponible) {
                continue;
            }

            $claveCiclo = 'ALIADO_CONVOCATORIA:' .
                $seguimientoConvocatoriaId;

            asegurarCicloRecordatorioVinculacion(
                $this->connection,
                $seguimientoId,
                $usuarioId,
                $claveCiclo,
                $momento->format('Y-m-d H:i:s')
            );

            $segundosRestantes =
                $momento->getTimestamp() - $ahora->getTimestamp();
            $tipoAviso = resolverTipoAvisoRecordatorio(
                $segundosRestantes
            );

            if ($tipoAviso === '') {
                continue;
            }

            if (!marcarAvisoRecordatorioComoEnviado(
                $this->connection,
                $seguimientoId,
                $usuarioId,
                $claveCiclo,
                $momento->format('Y-m-d H:i:s'),
                $tipoAviso
            )) {
                continue;
            }

            $aviso = construirAvisoRecordatorioSeguimiento(
                [
                    'id' => $seguimientoId,
                    'nombre_entidad' =>
                        (string)($fila['nombre_entidad'] ?? 'Aliado'),
                    'proxima_accion_texto' => $accion,
                    'proxima_accion_at' =>
                        $momento->format('Y-m-d H:i:s')
                ],
                $tipoAviso
            );

            $aviso['id'] = $seguimientoId;
            $aviso['seguimiento_id'] = $seguimientoId;
            $aviso['seguimiento_convocatoria_id'] =
                $seguimientoConvocatoriaId;
            $aviso['nombre_entidad'] =
                (string)($fila['nombre_entidad'] ?? 'Aliado');
            $aviso['icono'] = 'bi-whatsapp';
            $aviso['url'] = $url;

            $avisos[] = $aviso;
        }

        return [
            'ok' => true,
            'requiere_migracion' => !$tablaRecordatoriosDisponible,
            'recordatorios' => $recordatorios,
            'avisos' => $avisos
        ];
    }

    private function prioridad($estado)
    {
        $estado = strtolower(trim((string)$estado));

        if ($estado === 'vencida') {
            return 0;
        }

        if ($estado === 'proxima' || $estado === 'hoy') {
            return 2;
        }

        if ($estado === 'manana' || $estado === 'mañana') {
            return 3;
        }

        return 4;
    }
}
