<?php

require_once __DIR__ . '/../models/AliadoModel.php';

class AliadoSeguimientoReminderService
{
    private $modelo;

    public function __construct()
    {
        $this->modelo = new AliadoModel();
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
                'recordatorios' => [],
                'avisos' => []
            ];
        }

        $filas = $this->modelo->obtenerRecordatoriosSeguimientoAliados(
            $usuarioId,
            $limite
        );
        $recordatorios = [];

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

            $estadoVisual = $this->estadoVisual($momento);
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

            $recordatorios[] = [
                'id' => (int)($fila['seguimiento_id'] ?? 0),
                'seguimiento_id' => (int)($fila['seguimiento_id'] ?? 0),
                'seguimiento_convocatoria_id' =>
                    (int)($fila['seguimiento_convocatoria_id'] ?? 0),
                'nombre_entidad' =>
                    (string)($fila['nombre_entidad'] ?? 'Aliado'),
                'accion' => $accion,
                'fecha' => $momento->format('Y-m-d H:i:s'),
                'estado' => $estadoVisual,
                'etiqueta' => $this->etiqueta($momento, $estadoVisual),
                'icono' => 'bi-whatsapp',
                'prioridad' => $this->prioridad($estadoVisual),
                'url' => BASE_URL .
                    'index.php?controller=aliado&action=estado&estado_id=' .
                    (int)($fila['estado_id'] ?? 0) .
                    '&abrir_seguimiento=' .
                    (int)($fila['seguimiento_id'] ?? 0)
            ];
        }

        return [
            'recordatorios' => $recordatorios,
            'avisos' => []
        ];
    }

    private function estadoVisual(DateTime $momento)
    {
        $ahora = new DateTime();
        if ($momento <= $ahora) {
            return 'vencida';
        }

        $hoy = $ahora->format('Y-m-d');
        $fecha = $momento->format('Y-m-d');

        if ($fecha === $hoy) {
            return 'hoy';
        }

        $manana = (clone $ahora)
            ->modify('+1 day')
            ->format('Y-m-d');

        if ($fecha === $manana) {
            return 'manana';
        }

        return 'proxima';
    }

    private function etiqueta(DateTime $momento, $estado)
    {
        if ($estado === 'vencida') {
            return 'Vencida · ' . $momento->format('d/m · H:i');
        }

        if ($estado === 'hoy') {
            return 'Hoy · ' . $momento->format('H:i');
        }

        if ($estado === 'manana') {
            return 'Mañana · ' . $momento->format('H:i');
        }

        return $momento->format('d/m · H:i');
    }

    private function prioridad($estado)
    {
        if ($estado === 'vencida') {
            return 0;
        }

        if ($estado === 'hoy') {
            return 2;
        }

        if ($estado === 'manana') {
            return 3;
        }

        return 4;
    }
}
