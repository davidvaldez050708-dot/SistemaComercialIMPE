<?php

require_once __DIR__ . '/../../config/db_connection.php';

if (!function_exists('obtenerRecordatoriosSeguimientoAnalista')) {
    function obtenerRecordatoriosSeguimientoAnalista($usuarioId, $limite = 8)
    {
        $usuarioId = (int)$usuarioId;
        $limite = max(1, min(12, (int)$limite));

        if ($usuarioId <= 0) {
            return [];
        }

        try {
            $database = new Database();
            $connection = $database->connect();

            $sql = "SELECT
                        seguimientos.id,
                        seguimientos.estado_id,
                        seguimientos.nombre_entidad,
                        seguimientos.proxima_accion_at,
                        (
                            SELECT
                                CASE
                                    WHEN interacciones.notas LIKE '%Próxima acción:%'
                                    THEN TRIM(
                                        SUBSTRING_INDEX(
                                            SUBSTRING_INDEX(interacciones.notas, 'Próxima acción: ', -1),
                                            '\n',
                                            1
                                        )
                                    )
                                    ELSE NULL
                                END
                            FROM interacciones_vinculacion interacciones
                            WHERE interacciones.seguimiento_id = seguimientos.id
                            ORDER BY interacciones.fecha_inicio DESC, interacciones.id DESC
                            LIMIT 1
                        ) AS proxima_accion_texto
                    FROM seguimientos_vinculacion seguimientos
                    WHERE seguimientos.analista_id = ?
                        AND seguimientos.activo = 1
                        AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                        AND seguimientos.proxima_accion_at IS NOT NULL
                        AND seguimientos.proxima_accion_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
                    ORDER BY
                        CASE
                            WHEN seguimientos.proxima_accion_at < NOW() THEN 0
                            ELSE 1
                        END ASC,
                        CASE
                            WHEN seguimientos.proxima_accion_at < NOW()
                            THEN seguimientos.proxima_accion_at
                        END DESC,
                        CASE
                            WHEN seguimientos.proxima_accion_at >= NOW()
                            THEN seguimientos.proxima_accion_at
                        END ASC
                    LIMIT 40";

            $stmt = $connection->prepare($sql);
            $stmt->bind_param('i', $usuarioId);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $recordatorios = [];

            while ($fila = $resultado->fetch_assoc()) {
                $accion = trim((string)($fila['proxima_accion_texto'] ?? ''));

                if (!esAccionConRecordatorioSeguimiento($accion)) {
                    continue;
                }

                $fila['recordatorio'] = describirRecordatorioSeguimiento(
                    $fila['proxima_accion_at'] ?? ''
                );
                $recordatorios[] = $fila;

                if (count($recordatorios) >= $limite) {
                    break;
                }
            }

            return $recordatorios;
        } catch (Throwable $error) {
            error_log('No fue posible cargar recordatorios de seguimiento: ' . $error->getMessage());
            return [];
        }
    }
}

if (!function_exists('esAccionConRecordatorioSeguimiento')) {
    function esAccionConRecordatorioSeguimiento($accion)
    {
        $accion = normalizarAccionRecordatorioSeguimiento($accion);

        return in_array($accion, [
            'volver a llamar',
            'enviar whatsapp',
            'enviar oficio/correo',
            'enviar oficio y correo',
            'enviar correo/oficio'
        ], true);
    }
}

if (!function_exists('normalizarAccionRecordatorioSeguimiento')) {
    function normalizarAccionRecordatorioSeguimiento($accion)
    {
        $accion = trim((string)$accion);

        if ($accion === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($accion, 'UTF-8');
        }

        return strtolower($accion);
    }
}

if (!function_exists('describirRecordatorioSeguimiento')) {
    function describirRecordatorioSeguimiento($fecha)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return [
                'etiqueta' => 'Sin fecha',
                'estado' => 'normal'
            ];
        }

        try {
            $ahora = new DateTime();
            $momento = new DateTime($fecha);
            $hoy = (clone $ahora)->setTime(0, 0, 0);
            $manana = (clone $hoy)->modify('+1 day');
            $momentoDia = (clone $momento)->setTime(0, 0, 0);
            $hora = $momento->format('H:i');

            if ($momento < $ahora) {
                $prefijo = $momentoDia == $hoy
                    ? 'Vencida hoy'
                    : 'Vencida';

                return [
                    'etiqueta' => $prefijo . ' · ' . $hora,
                    'estado' => 'vencida'
                ];
            }

            $segundos = $momento->getTimestamp() - $ahora->getTimestamp();

            if ($segundos <= 3600) {
                $minutos = max(1, (int)ceil($segundos / 60));

                return [
                    'etiqueta' => 'En ' . $minutos . ' min · ' . $hora,
                    'estado' => 'proxima'
                ];
            }

            if ($momentoDia == $hoy) {
                return [
                    'etiqueta' => 'Hoy · ' . $hora,
                    'estado' => 'hoy'
                ];
            }

            if ($momentoDia == $manana) {
                return [
                    'etiqueta' => 'Mañana · ' . $hora,
                    'estado' => 'manana'
                ];
            }

            return [
                'etiqueta' => $momento->format('d/m/Y · H:i'),
                'estado' => 'normal'
            ];
        } catch (Throwable $error) {
            return [
                'etiqueta' => 'Fecha pendiente',
                'estado' => 'normal'
            ];
        }
    }
}

if (!function_exists('iconoAccionRecordatorioSeguimiento')) {
    function iconoAccionRecordatorioSeguimiento($accion)
    {
        $accion = normalizarAccionRecordatorioSeguimiento($accion);

        if ($accion === 'volver a llamar') {
            return 'bi-telephone';
        }

        if ($accion === 'enviar whatsapp') {
            return 'bi-whatsapp';
        }

        return 'bi-envelope';
    }
}
