<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../models/ConvocatoriaModel.php';

/**
 * Centro de supervisión del Administrador.
 *
 * No reutiliza recordatorios personales de Analista, Cuenta Clave o Marketing:
 * resume únicamente pendientes globales que requieren visibilidad administrativa.
 */
class AdminNotificationService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtener()
    {
        $recordatorios = [];
        $total = 0;

        try {
            // Mantiene el estado temporal de convocatorias sincronizado aunque
            // no haya un usuario de Marketing consultando su campana.
            try {
                (new ConvocatoriaModel())->sincronizarConvocatoriasPorFecha();
            } catch (Throwable $error) {
                error_log(
                    'No fue posible sincronizar convocatorias para el centro administrativo: ' .
                    $error->getMessage()
                );
            }

            $reuniones = $this->resumenReuniones();
            if ((int)$reuniones['total'] > 0) {
                $cantidad = (int)$reuniones['total'];
                $total += $cantidad;
                $recordatorios[] = [
                    'id' => 0,
                    'nombre_entidad' => 'Reuniones',
                    'accion' => $cantidad === 1
                        ? '1 reunión requiere coordinación'
                        : $cantidad . ' reuniones requieren coordinación',
                    'fecha' => (string)($reuniones['fecha'] ?? ''),
                    'etiqueta' => $cantidad . ' pendiente' .
                        ($cantidad === 1 ? '' : 's'),
                    'estado' => 'normal',
                    'icono' => 'bi-calendar-check',
                    'prioridad' => 1,
                    'url' => 'index.php?controller=agendaReunion&action=index'
                ];
            }

            $seguimientos = $this->resumenSeguimientosVencidos();
            if ((int)$seguimientos['total'] > 0) {
                $cantidad = (int)$seguimientos['total'];
                $total += $cantidad;
                $recordatorios[] = [
                    'id' => 0,
                    'nombre_entidad' => 'Seguimiento comercial',
                    'accion' => $cantidad === 1
                        ? '1 próxima acción está vencida'
                        : $cantidad . ' próximas acciones están vencidas',
                    'fecha' => (string)($seguimientos['fecha'] ?? ''),
                    'etiqueta' => $cantidad . ' vencida' .
                        ($cantidad === 1 ? '' : 's'),
                    // El Administrador supervisa; no repetimos toasts operativos
                    // personales cada ocho minutos.
                    'estado' => 'normal',
                    'icono' => 'bi-exclamation-circle',
                    'prioridad' => 2,
                    'url' => 'index.php?controller=seguimientoVinculacionReporte&action=index&tipo_reporte=cartera'
                ];
            }

            $convocatorias = $this->resumenConvocatorias();
            $totalConvocatorias =
                (int)$convocatorias['inician_hoy'] +
                (int)$convocatorias['vencen_pronto'];

            if ($totalConvocatorias > 0) {
                $total += $totalConvocatorias;
                $partes = [];

                if ((int)$convocatorias['inician_hoy'] > 0) {
                    $cantidad = (int)$convocatorias['inician_hoy'];
                    $partes[] = $cantidad === 1
                        ? '1 inicia hoy'
                        : $cantidad . ' inician hoy';
                }

                if ((int)$convocatorias['vencen_pronto'] > 0) {
                    $cantidad = (int)$convocatorias['vencen_pronto'];
                    $partes[] = $cantidad === 1
                        ? '1 vence en las próximas 48 h'
                        : $cantidad . ' vencen en las próximas 48 h';
                }

                $recordatorios[] = [
                    'id' => 0,
                    'nombre_entidad' => 'Convocatorias',
                    'accion' => implode(' · ', $partes),
                    'fecha' => date('Y-m-d H:i:s'),
                    'etiqueta' => $totalConvocatorias . ' alerta' .
                        ($totalConvocatorias === 1 ? '' : 's'),
                    'estado' => 'normal',
                    'icono' => 'bi-megaphone',
                    'prioridad' => 3,
                    'url' => 'index.php?controller=convocatoria&action=index'
                ];
            }

            usort(
                $recordatorios,
                static function ($a, $b) {
                    return (int)($a['prioridad'] ?? 9) <=>
                        (int)($b['prioridad'] ?? 9);
                }
            );

            return [
                'ok' => true,
                'requiere_migracion' => false,
                'avisos' => [],
                'recordatorios' => $recordatorios,
                'total' => $total
            ];
        } catch (Throwable $error) {
            error_log(
                'No fue posible cargar notificaciones administrativas: ' .
                $error->getMessage()
            );

            return [
                'ok' => false,
                'requiere_migracion' => false,
                'avisos' => [],
                'recordatorios' => [],
                'total' => 0
            ];
        }
    }

    private function resumenReuniones()
    {
        if (!$this->tablaDisponible('reuniones_vinculacion')) {
            return ['total' => 0, 'fecha' => ''];
        }

        $sql = "SELECT
                    COUNT(*) AS total,
                    MIN(r.fecha_propuesta) AS fecha
                FROM reuniones_vinculacion r
                INNER JOIN seguimientos_vinculacion s
                    ON s.id = r.seguimiento_id
                WHERE s.activo = 1
                  AND s.estado_seguimiento <> 'DESCARTADO'
                  AND r.estado IN (
                      'SOLICITADA',
                      'CAMBIO_SOLICITADO',
                      'CANCELACION_SOLICITADA',
                      'CONFIRMADA'
                  )";

        $resultado = $this->connection->query($sql);
        $fila = $resultado ? $resultado->fetch_assoc() : [];

        return [
            'total' => (int)($fila['total'] ?? 0),
            'fecha' => (string)($fila['fecha'] ?? '')
        ];
    }

    private function resumenSeguimientosVencidos()
    {
        if (!$this->tablaDisponible('seguimientos_vinculacion')) {
            return ['total' => 0, 'fecha' => ''];
        }

        $sql = "SELECT
                    COUNT(*) AS total,
                    MIN(proxima_accion_at) AS fecha
                FROM seguimientos_vinculacion
                WHERE activo = 1
                  AND estado_seguimiento <> 'DESCARTADO'
                  AND proxima_accion_at IS NOT NULL
                  AND proxima_accion_at < NOW()";

        $resultado = $this->connection->query($sql);
        $fila = $resultado ? $resultado->fetch_assoc() : [];

        return [
            'total' => (int)($fila['total'] ?? 0),
            'fecha' => (string)($fila['fecha'] ?? '')
        ];
    }

    private function resumenConvocatorias()
    {
        if (!$this->tablaDisponible('convocatorias')) {
            return [
                'inician_hoy' => 0,
                'vencen_pronto' => 0
            ];
        }

        $sql = "SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN estado = 1
                             AND fecha_inicio = CURDATE()
                            THEN 1 ELSE 0
                        END
                    ), 0) AS inician_hoy,
                    COALESCE(SUM(
                        CASE
                            WHEN estado = 1
                             AND fecha_termino BETWEEN CURDATE()
                                AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
                            THEN 1 ELSE 0
                        END
                    ), 0) AS vencen_pronto
                FROM convocatorias";

        $resultado = $this->connection->query($sql);
        $fila = $resultado ? $resultado->fetch_assoc() : [];

        return [
            'inician_hoy' => (int)($fila['inician_hoy'] ?? 0),
            'vencen_pronto' => (int)($fila['vencen_pronto'] ?? 0)
        ];
    }

    private function tablaDisponible($tabla)
    {
        $tabla = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$tabla);
        if ($tabla === '') {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW TABLES LIKE '" . $this->connection->real_escape_string($tabla) . "'"
        );

        return $resultado && $resultado->num_rows > 0;
    }
}
