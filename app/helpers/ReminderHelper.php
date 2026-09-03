<?php

require_once __DIR__ . '/../../config/db_connection.php';

if (!function_exists('obtenerRecordatoriosSeguimientoAnalista')) {
    function obtenerRecordatoriosSeguimientoAnalista($usuarioId, $limite = 8)
    {
        $usuarioId = (int)$usuarioId;
        $limite = max(1, min(50, (int)$limite));

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
                            SELECT TRIM(
                                SUBSTRING_INDEX(
                                    SUBSTRING_INDEX(interacciones.notas, 'Próxima acción: ', -1),
                                    '\n',
                                    1
                                )
                            )
                            FROM interacciones_vinculacion interacciones
                            WHERE interacciones.seguimiento_id = seguimientos.id
                                AND interacciones.notas LIKE '%Próxima acción:%'
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
                    LIMIT 100";

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

if (!function_exists('obtenerAvisosPendientesRecordatoriosAnalista')) {
    function obtenerAvisosPendientesRecordatoriosAnalista($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0) {
            return [
                'ok' => true,
                'requiere_migracion' => false,
                'avisos' => [],
                'recordatorios' => []
            ];
        }

        $recordatorios = obtenerRecordatoriosSeguimientoAnalista($usuarioId, 50);

        try {
            $database = new Database();
            $connection = $database->connect();

            if (!existeTablaRecordatoriosVinculacion($connection)) {
                return [
                    'ok' => true,
                    'requiere_migracion' => true,
                    'avisos' => [],
                    'recordatorios' => $recordatorios
                ];
            }

            $avisos = [];
            $ahora = new DateTime();

            foreach ($recordatorios as $recordatorio) {
                $seguimientoId = (int)($recordatorio['id'] ?? 0);
                $accion = trim((string)($recordatorio['proxima_accion_texto'] ?? ''));
                $fecha = trim((string)($recordatorio['proxima_accion_at'] ?? ''));

                if ($seguimientoId <= 0 || $accion === '' || $fecha === '') {
                    continue;
                }

                try {
                    $momento = new DateTime($fecha);
                } catch (Throwable $error) {
                    continue;
                }

                asegurarCicloRecordatorioVinculacion(
                    $connection,
                    $seguimientoId,
                    $usuarioId,
                    $accion,
                    $fecha
                );

                $segundosRestantes = $momento->getTimestamp() - $ahora->getTimestamp();
                $tipoAviso = resolverTipoAvisoRecordatorio($segundosRestantes);

                if ($tipoAviso === '') {
                    continue;
                }

                if (!marcarAvisoRecordatorioComoEnviado(
                    $connection,
                    $seguimientoId,
                    $usuarioId,
                    $accion,
                    $fecha,
                    $tipoAviso
                )) {
                    continue;
                }

                $avisos[] = construirAvisoRecordatorioSeguimiento(
                    $recordatorio,
                    $tipoAviso
                );
            }

            return [
                'ok' => true,
                'requiere_migracion' => false,
                'avisos' => $avisos,
                'recordatorios' => $recordatorios
            ];
        } catch (Throwable $error) {
            error_log('No fue posible procesar avisos de seguimiento: ' . $error->getMessage());

            return [
                'ok' => false,
                'requiere_migracion' => false,
                'avisos' => [],
                'recordatorios' => $recordatorios
            ];
        }
    }
}

if (!function_exists('existeTablaRecordatoriosVinculacion')) {
    function existeTablaRecordatoriosVinculacion($connection)
    {
        $resultado = $connection->query("SHOW TABLES LIKE 'recordatorios_vinculacion'");

        return $resultado && $resultado->num_rows > 0;
    }
}

if (!function_exists('asegurarCicloRecordatorioVinculacion')) {
    function asegurarCicloRecordatorioVinculacion(
        $connection,
        $seguimientoId,
        $usuarioId,
        $accion,
        $fecha
    ) {
        $sql = "INSERT IGNORE INTO recordatorios_vinculacion (
                    seguimiento_id,
                    usuario_id,
                    accion,
                    proxima_accion_at
                ) VALUES (?, ?, ?, ?)";

        $stmt = $connection->prepare($sql);
        $stmt->bind_param(
            'iiss',
            $seguimientoId,
            $usuarioId,
            $accion,
            $fecha
        );
        $stmt->execute();
    }
}

if (!function_exists('resolverTipoAvisoRecordatorio')) {
    function resolverTipoAvisoRecordatorio($segundosRestantes)
    {
        $segundosRestantes = (int)$segundosRestantes;

        if ($segundosRestantes <= 0) {
            return 'VENCIDA';
        }

        if ($segundosRestantes <= 10 * 60) {
            return '10M';
        }

        if ($segundosRestantes <= 60 * 60) {
            return '1H';
        }

        if ($segundosRestantes <= 3 * 60 * 60) {
            return '3H';
        }

        return '';
    }
}

if (!function_exists('marcarAvisoRecordatorioComoEnviado')) {
    function marcarAvisoRecordatorioComoEnviado(
        $connection,
        $seguimientoId,
        $usuarioId,
        $accion,
        $fecha,
        $tipoAviso
    ) {
        $campos = [
            '3H' => 'aviso_3h_at',
            '1H' => 'aviso_1h_at',
            '10M' => 'aviso_10m_at',
            'VENCIDA' => 'aviso_vencida_at'
        ];
        $campoObjetivo = $campos[$tipoAviso] ?? '';

        if ($campoObjetivo === '') {
            return false;
        }

        $asignaciones = [];

        if (in_array($tipoAviso, ['3H', '1H', '10M', 'VENCIDA'], true)) {
            $asignaciones[] = 'aviso_3h_at = COALESCE(aviso_3h_at, NOW())';
        }

        if (in_array($tipoAviso, ['1H', '10M', 'VENCIDA'], true)) {
            $asignaciones[] = 'aviso_1h_at = COALESCE(aviso_1h_at, NOW())';
        }

        if (in_array($tipoAviso, ['10M', 'VENCIDA'], true)) {
            $asignaciones[] = 'aviso_10m_at = COALESCE(aviso_10m_at, NOW())';
        }

        if ($tipoAviso === 'VENCIDA') {
            $asignaciones[] = 'aviso_vencida_at = COALESCE(aviso_vencida_at, NOW())';
        }

        $sql = "UPDATE recordatorios_vinculacion
                SET " . implode(', ', $asignaciones) . "
                WHERE seguimiento_id = ?
                    AND usuario_id = ?
                    AND accion = ?
                    AND proxima_accion_at = ?
                    AND $campoObjetivo IS NULL";

        $stmt = $connection->prepare($sql);
        $stmt->bind_param(
            'iiss',
            $seguimientoId,
            $usuarioId,
            $accion,
            $fecha
        );
        $stmt->execute();

        return $stmt->affected_rows > 0;
    }
}

if (!function_exists('construirAvisoRecordatorioSeguimiento')) {
    function construirAvisoRecordatorioSeguimiento($recordatorio, $tipoAviso)
    {
        $accion = trim((string)($recordatorio['proxima_accion_texto'] ?? ''));
        $institucion = trim((string)($recordatorio['nombre_entidad'] ?? 'Seguimiento'));
        $fecha = trim((string)($recordatorio['proxima_accion_at'] ?? ''));
        $etiquetas = [
            '3H' => 'En aproximadamente 3 horas',
            '1H' => 'En aproximadamente 1 hora',
            '10M' => 'En aproximadamente 10 minutos',
            'VENCIDA' => 'Acción vencida'
        ];

        return [
            'seguimiento_id' => (int)($recordatorio['id'] ?? 0),
            'institucion' => $institucion,
            'accion' => $accion,
            'fecha' => $fecha,
            'tipo' => $tipoAviso,
            'titulo' => $etiquetas[$tipoAviso] ?? 'Recordatorio',
            'mensaje' => $tipoAviso === 'VENCIDA'
                ? $accion . ' · ' . $institucion
                : $accion . ' · ' . $institucion,
            'icono' => iconoAccionRecordatorioSeguimiento($accion)
        ];
    }
}

if (!function_exists('serializarRecordatoriosSeguimiento')) {
    function serializarRecordatoriosSeguimiento($recordatorios)
    {
        $salida = [];

        foreach ($recordatorios as $recordatorio) {
            $accion = trim((string)($recordatorio['proxima_accion_texto'] ?? ''));
            $salida[] = [
                'id' => (int)($recordatorio['id'] ?? 0),
                'nombre_entidad' => (string)($recordatorio['nombre_entidad'] ?? 'Seguimiento'),
                'accion' => $accion,
                'fecha' => (string)($recordatorio['proxima_accion_at'] ?? ''),
                'etiqueta' => (string)($recordatorio['recordatorio']['etiqueta'] ?? ''),
                'estado' => (string)($recordatorio['recordatorio']['estado'] ?? 'normal'),
                'icono' => iconoAccionRecordatorioSeguimiento($accion)
            ];
        }

        return $salida;
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
