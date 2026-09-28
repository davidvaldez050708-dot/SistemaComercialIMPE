<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoCambioDatosService
{
    private $connection;

    private const CAMPOS = [
        'telefono_verificado' => 'Teléfono actual de contacto',
        'whatsapp_verificado' => 'WhatsApp',
        'correo_verificado' => 'Correo de contacto',
        'contacto_nombre' => 'Persona de contacto',
        'contacto_cargo' => 'Cargo / Área',
        'observaciones' => 'Observaciones'
    ];

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function registrarCambios($seguimientoId, $autorId, $antes, $despues)
    {
        $seguimientoId = (int)$seguimientoId;
        $autorId = (int)$autorId;
        $antes = is_array($antes) ? $antes : [];
        $despues = is_array($despues) ? $despues : [];

        if (
            $seguimientoId <= 0 ||
            $autorId <= 0 ||
            !$this->tablaDisponible()
        ) {
            return [
                'ok' => true,
                'cambios' => 0,
                'notificados' => 0
            ];
        }

        $destinatarioId = $this->resolverCuentaClave($seguimientoId);
        $cambios = 0;
        $notificados = 0;
        $etiquetasCambiadas = [];

        foreach (self::CAMPOS as $campo => $etiqueta) {
            $anterior = $this->normalizarValor($antes[$campo] ?? '');
            $nuevo = $this->normalizarValor($despues[$campo] ?? '');

            if ($anterior === $nuevo) {
                continue;
            }

            /*
             * Agregar información a un campo vacío forma parte del trabajo normal
             * del Analista. Sustituir un dato que ya existía sí se considera una
             * modificación relevante (incluida su eliminación) y genera aviso
             * a Cuenta Clave.
             *
             * Las observaciones se auditan, pero no generan aviso automático:
             * son contexto adicional, no un dato operativo que sustituya contacto.
             */
            $requiereNotificacion =
                $campo !== 'observaciones' &&
                $anterior !== '' &&
                $destinatarioId > 0;

            $sql = "INSERT INTO seguimientos_vinculacion_cambios_datos (
                        seguimiento_id,
                        autor_id,
                        destinatario_id,
                        campo,
                        etiqueta,
                        valor_anterior,
                        valor_nuevo,
                        requiere_notificacion
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->connection->prepare($sql);
            $notificar = $requiereNotificacion ? 1 : 0;
            $stmt->bind_param(
                'iiissssi',
                $seguimientoId,
                $autorId,
                $destinatarioId,
                $campo,
                $etiqueta,
                $anterior,
                $nuevo,
                $notificar
            );
            $stmt->execute();

            $cambios++;
            $etiquetasCambiadas[] = $etiqueta;

            if ($requiereNotificacion) {
                $notificados++;
            }
        }

        if (!empty($etiquetasCambiadas)) {
            $nota = 'Datos del seguimiento actualizados: ' .
                implode(', ', array_values(array_unique($etiquetasCambiadas))) . '.';

            if ($notificados > 0) {
                $nota .= ' Cuenta Clave fue notificada de los cambios relevantes.';
            }

            try {
                $sqlActividad = "INSERT INTO interacciones_vinculacion (
                                    seguimiento_id,
                                    usuario_id,
                                    canal,
                                    fecha_inicio,
                                    resultado,
                                    notas
                                ) VALUES (?, ?, 'SISTEMA', NOW(), 'OTRO', ?)";
                $stmtActividad = $this->connection->prepare($sqlActividad);
                $stmtActividad->bind_param(
                    'iis',
                    $seguimientoId,
                    $autorId,
                    $nota
                );
                $stmtActividad->execute();
            } catch (Throwable $error) {
                error_log(
                    'No fue posible registrar actividad de cambio de datos: ' .
                    $error->getMessage()
                );
            }
        }

        return [
            'ok' => true,
            'cambios' => $cambios,
            'notificados' => $notificados
        ];
    }

    public function obtenerNotificaciones($destinatarioId, $limite = 10)
    {
        $destinatarioId = (int)$destinatarioId;
        $limite = max(1, min(30, (int)$limite));

        if (
            $destinatarioId <= 0 ||
            !$this->tablaDisponible()
        ) {
            return [
                'recordatorios' => [],
                'avisos' => []
            ];
        }

        try {
            $sql = "SELECT
                        cambios.id,
                        cambios.seguimiento_id,
                        cambios.campo,
                        cambios.etiqueta,
                        cambios.valor_anterior,
                        cambios.valor_nuevo,
                        cambios.created_at,
                        seguimientos.nombre_entidad,
                        usuarios.nombre,
                        usuarios.apellidos
                    FROM seguimientos_vinculacion_cambios_datos cambios
                    INNER JOIN seguimientos_vinculacion seguimientos
                        ON seguimientos.id = cambios.seguimiento_id
                    INNER JOIN usuarios
                        ON usuarios.id = cambios.autor_id
                    WHERE cambios.destinatario_id = ?
                      AND cambios.requiere_notificacion = 1
                      AND cambios.leida_at IS NULL
                      AND seguimientos.activo = 1
                    ORDER BY cambios.created_at DESC, cambios.id DESC
                    LIMIT {$limite}";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $destinatarioId);
            $stmt->execute();
            $resultado = $stmt->get_result();

            $recordatorios = [];
            $avisos = [];
            $mostradas = is_array($_SESSION['cambios_datos_mostrados'] ?? null)
                ? $_SESSION['cambios_datos_mostrados']
                : [];

            while ($fila = $resultado->fetch_assoc()) {
                $cambioId = (int)($fila['id'] ?? 0);
                $seguimientoId = (int)($fila['seguimiento_id'] ?? 0);
                $institucion = trim((string)($fila['nombre_entidad'] ?? ''));
                $etiqueta = trim((string)($fila['etiqueta'] ?? 'Dato'));
                $autor = trim(
                    (string)($fila['nombre'] ?? '') . ' ' .
                    (string)($fila['apellidos'] ?? '')
                );
                $fecha = trim((string)($fila['created_at'] ?? ''));
                $url = 'index.php?controller=seguimientoVinculacion&action=verCambioDatos&cambio_id=' .
                    $cambioId;

                if ($institucion === '') {
                    $institucion = 'Seguimiento';
                }
                if ($autor === '') {
                    $autor = 'Analista';
                }

                $recordatorios[] = [
                    'id' => $seguimientoId,
                    'seguimiento_id' => $seguimientoId,
                    'cambio_id' => $cambioId,
                    'nombre_entidad' => $institucion,
                    'accion' => $autor . ' modificó ' . $etiqueta,
                    'fecha' => $fecha,
                    'etiqueta' => 'Dato actualizado',
                    'estado' => 'normal',
                    'icono' => 'bi-pencil-square',
                    'prioridad' => 1,
                    'url' => $url
                ];

                if ($cambioId > 0 && empty($mostradas[$cambioId])) {
                    $avisos[] = [
                        'id' => $seguimientoId,
                        'seguimiento_id' => $seguimientoId,
                        'cambio_id' => $cambioId,
                        'tipo' => 'CAMBIO_DATOS',
                        'titulo' => 'Dato de seguimiento actualizado',
                        'mensaje' => $autor . ' modificó ' . $etiqueta . ' en ' . $institucion . '.',
                        'icono' => 'bi-pencil-square',
                        'url' => $url
                    ];
                    $mostradas[$cambioId] = time();
                }
            }

            if (count($mostradas) > 100) {
                arsort($mostradas);
                $mostradas = array_slice($mostradas, 0, 100, true);
            }

            $_SESSION['cambios_datos_mostrados'] = $mostradas;

            return [
                'recordatorios' => $recordatorios,
                'avisos' => $avisos
            ];
        } catch (Throwable $error) {
            error_log('No fue posible consultar cambios de datos: ' . $error->getMessage());

            return [
                'recordatorios' => [],
                'avisos' => []
            ];
        }
    }

    public function marcarLeido($cambioId, $destinatarioId)
    {
        $cambioId = (int)$cambioId;
        $destinatarioId = (int)$destinatarioId;

        if ($cambioId <= 0 || $destinatarioId <= 0 || !$this->tablaDisponible()) {
            return null;
        }

        $sql = "SELECT id, seguimiento_id
                FROM seguimientos_vinculacion_cambios_datos
                WHERE id = ?
                  AND destinatario_id = ?
                  AND requiere_notificacion = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $cambioId, $destinatarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;

        if (!$fila) {
            return null;
        }

        $sqlUpdate = "UPDATE seguimientos_vinculacion_cambios_datos
                      SET leida_at = COALESCE(leida_at, NOW()),
                          leida_por = COALESCE(leida_por, ?)
                      WHERE id = ?
                        AND destinatario_id = ?";
        $stmtUpdate = $this->connection->prepare($sqlUpdate);
        $stmtUpdate->bind_param('iii', $destinatarioId, $cambioId, $destinatarioId);
        $stmtUpdate->execute();

        return [
            'id' => (int)$fila['id'],
            'seguimiento_id' => (int)$fila['seguimiento_id']
        ];
    }

    private function resolverCuentaClave($seguimientoId)
    {
        try {
            $sql = "SELECT cuenta.usuario_id AS cuenta_clave_id
                    FROM seguimientos_vinculacion s
                    JOIN asignaciones_territorio analista
                        ON analista.estado_id = s.estado_id
                        AND analista.usuario_id = s.analista_id
                        AND analista.tipo_asignacion = 'ANALISTA_DATOS'
                        AND analista.activo = 1
                        AND (analista.fecha_inicio IS NULL OR analista.fecha_inicio <= CURDATE())
                        AND (analista.fecha_fin IS NULL OR analista.fecha_fin >= CURDATE())
                    JOIN asignaciones_territorio cuenta
                        ON cuenta.id = analista.cuenta_clave_asignacion_id
                        AND cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                        AND cuenta.activo = 1
                        AND (cuenta.fecha_inicio IS NULL OR cuenta.fecha_inicio <= CURDATE())
                        AND (cuenta.fecha_fin IS NULL OR cuenta.fecha_fin >= CURDATE())
                    JOIN usuarios usuario_cuenta
                        ON usuario_cuenta.id = cuenta.usuario_id
                        AND usuario_cuenta.rol_id = 6
                        AND usuario_cuenta.estado = 1
                    WHERE s.id = ?
                    ORDER BY analista.id DESC
                    LIMIT 1";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $seguimientoId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();

            return (int)($fila['cuenta_clave_id'] ?? 0);
        } catch (Throwable $error) {
            error_log('No fue posible resolver Cuenta Clave para cambio de datos: ' . $error->getMessage());
            return 0;
        }
    }

    private function tablaDisponible()
    {
        try {
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE 'seguimientos_vinculacion_cambios_datos'"
            );

            return $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function normalizarValor($valor)
    {
        return trim((string)$valor);
    }
}
