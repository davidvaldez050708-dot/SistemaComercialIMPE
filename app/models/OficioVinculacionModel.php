<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioVinculacionModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerEstadoSeguimiento($seguimientoId, $usuarioId, $modoAcceso = 'analista')
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $sql = $this->consultaEstadoSeguimientoBase();

        if ($modoAcceso === 'administrador') {
            $sql .= "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $seguimientoId);
        } elseif ($modoAcceso === 'supervisor') {
            $sql .= "
                INNER JOIN asignaciones_territorio asignacion_analista
                    ON asignacion_analista.usuario_id = seguimientos.analista_id
                    AND asignacion_analista.estado_id = seguimientos.estado_id
                    AND asignacion_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignacion_analista.activo = 1
                    AND " . $this->condicionAsignacionVigente('asignacion_analista') . "
                INNER JOIN asignaciones_territorio cuenta_clave
                    ON cuenta_clave.id = asignacion_analista.cuenta_clave_asignacion_id
                    AND cuenta_clave.estado_id = seguimientos.estado_id
                    AND cuenta_clave.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuenta_clave.activo = 1
                    AND cuenta_clave.usuario_id = ?
                    AND " . $this->condicionAsignacionVigente('cuenta_clave') . "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $usuarioId, $seguimientoId);
        } else {
            $sql .= "
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        }

        $stmt->execute();
        $seguimiento = $stmt->get_result()->fetch_assoc() ?: null;

        if (!$seguimiento) {
            return null;
        }

        return $this->completarEstadoOficio($seguimiento);
    }

    public function generarBorrador($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $this->connection->begin_transaction();

        try {
            $sqlSeguimiento = "SELECT
                        seguimientos.id,
                        seguimientos.estado_id,
                        seguimientos.analista_id,
                        seguimientos.estado_seguimiento,
                        seguimientos.datos_verificados,
                        seguimientos.contacto_nombre,
                        seguimientos.contacto_cargo,
                        seguimientos.correo_verificado,
                        estados.clave_inegi,
                        estados.nombre AS estado_nombre
                    FROM seguimientos_vinculacion seguimientos
                    INNER JOIN estados
                        ON estados.id = seguimientos.estado_id
                    WHERE seguimientos.id = ?
                        AND seguimientos.analista_id = ?
                        AND seguimientos.activo = 1
                    LIMIT 1
                    FOR UPDATE";

            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
            $stmtSeguimiento->execute();
            $seguimiento = $stmtSeguimiento->get_result()->fetch_assoc() ?: null;

            if (!$seguimiento) {
                $this->connection->rollback();
                return $this->error('No tienes acceso a este seguimiento.', 403);
            }

            $sqlOficioExistente = "SELECT id, folio, estado_oficio
                    FROM oficios_vinculacion
                    WHERE seguimiento_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                    FOR UPDATE";
            $stmtOficioExistente = $this->connection->prepare($sqlOficioExistente);
            $stmtOficioExistente->bind_param('i', $seguimientoId);
            $stmtOficioExistente->execute();
            $oficioExistente = $stmtOficioExistente->get_result()->fetch_assoc() ?: null;

            if ($oficioExistente && trim((string)($oficioExistente['folio'] ?? '')) !== '') {
                $this->connection->commit();
                $estado = $this->obtenerEstadoSeguimiento($seguimientoId, $usuarioId);

                return [
                    'ok' => true,
                    'existente' => true,
                    'mensaje' => 'Este seguimiento ya tiene un oficio preparado.',
                    'oficio_id' => (int)$oficioExistente['id'],
                    'folio' => (string)$oficioExistente['folio'],
                    'estado' => $estado
                ];
            }

            if ((int)($seguimiento['datos_verificados'] ?? 0) !== 1) {
                $this->connection->rollback();
                return $this->error(
                    'Primero marca la información de contacto como verificada.',
                    422
                );
            }

            if ((string)($seguimiento['estado_seguimiento'] ?? '') !== 'DATOS_VERIFICADOS') {
                $this->connection->rollback();
                return $this->error(
                    'El oficio solo puede generarse desde la etapa Datos verificados.',
                    422
                );
            }

            $contactoNombre = trim((string)($seguimiento['contacto_nombre'] ?? ''));
            $contactoCargo = trim((string)($seguimiento['contacto_cargo'] ?? ''));
            $correoVerificado = trim((string)($seguimiento['correo_verificado'] ?? ''));
            $faltantes = [];

            if ($contactoNombre === '') {
                $faltantes[] = 'persona de contacto';
            }

            if ($contactoCargo === '') {
                $faltantes[] = 'cargo';
            }

            if ($correoVerificado === '') {
                $faltantes[] = 'correo verificado';
            }

            if (!empty($faltantes)) {
                $this->connection->rollback();
                return $this->error(
                    'Completa ' . implode(', ', $faltantes) . ' antes de generar el oficio.',
                    422
                );
            }

            if (!filter_var($correoVerificado, FILTER_VALIDATE_EMAIL)) {
                $this->connection->rollback();
                return $this->error('El correo verificado no tiene un formato válido.', 422);
            }

            $estadoId = (int)$seguimiento['estado_id'];
            $anio = (int)date('Y');

            $sqlConsecutivo = "INSERT INTO consecutivos_vinculacion (
                        estado_id,
                        anio,
                        ultimo_consecutivo
                    ) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        ultimo_consecutivo = ultimo_consecutivo + 1";
            $stmtConsecutivo = $this->connection->prepare($sqlConsecutivo);
            $stmtConsecutivo->bind_param('ii', $estadoId, $anio);
            $stmtConsecutivo->execute();

            $sqlLeerConsecutivo = "SELECT ultimo_consecutivo
                    FROM consecutivos_vinculacion
                    WHERE estado_id = ?
                        AND anio = ?
                    LIMIT 1
                    FOR UPDATE";
            $stmtLeerConsecutivo = $this->connection->prepare($sqlLeerConsecutivo);
            $stmtLeerConsecutivo->bind_param('ii', $estadoId, $anio);
            $stmtLeerConsecutivo->execute();
            $filaConsecutivo = $stmtLeerConsecutivo->get_result()->fetch_assoc() ?: [];
            $consecutivo = (int)($filaConsecutivo['ultimo_consecutivo'] ?? 0);

            if ($consecutivo <= 0) {
                throw new RuntimeException('No fue posible obtener el consecutivo del oficio.');
            }

            $codigoEstado = $this->codigoEstadoFolio((string)($seguimiento['clave_inegi'] ?? ''));
            $folio = sprintf(
                'REDMEX-%s-%d-%04d',
                $codigoEstado,
                $anio,
                $consecutivo
            );

            if ($oficioExistente) {
                $oficioId = (int)$oficioExistente['id'];
                $sqlOficio = "UPDATE oficios_vinculacion
                        SET folio = ?,
                            destinatario_nombre = ?,
                            destinatario_cargo = ?,
                            destinatario_correo = ?,
                            estado_oficio = 'BORRADOR',
                            solicita_reunion = 1,
                            error_envio = NULL
                        WHERE id = ?";
                $stmtOficio = $this->connection->prepare($sqlOficio);
                $stmtOficio->bind_param(
                    'ssssi',
                    $folio,
                    $contactoNombre,
                    $contactoCargo,
                    $correoVerificado,
                    $oficioId
                );
                $stmtOficio->execute();
            } else {
                $sqlOficio = "INSERT INTO oficios_vinculacion (
                            seguimiento_id,
                            folio,
                            destinatario_nombre,
                            destinatario_cargo,
                            destinatario_correo,
                            solicita_reunion,
                            estado_oficio
                        ) VALUES (?, ?, ?, ?, ?, 1, 'BORRADOR')";
                $stmtOficio = $this->connection->prepare($sqlOficio);
                $stmtOficio->bind_param(
                    'issss',
                    $seguimientoId,
                    $folio,
                    $contactoNombre,
                    $contactoCargo,
                    $correoVerificado
                );
                $stmtOficio->execute();
                $oficioId = (int)$this->connection->insert_id;
            }

            $notas = "Oficio preparado\nFolio: {$folio}\nPróxima acción: Enviar oficio/correo";
            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                        seguimiento_id,
                        usuario_id,
                        canal,
                        fecha_inicio,
                        resultado,
                        notas
                    ) VALUES (?, ?, 'SISTEMA', NOW(), 'OTRO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param('iis', $seguimientoId, $usuarioId, $notas);
            $stmtInteraccion->execute();

            $sqlActualizarSeguimiento = "UPDATE seguimientos_vinculacion
                    SET estado_seguimiento = 'OFICIO_PREPARADO',
                        ultima_interaccion_at = NOW(),
                        proxima_accion_at = NULL
                    WHERE id = ?
                        AND activo = 1";
            $stmtActualizarSeguimiento = $this->connection->prepare($sqlActualizarSeguimiento);
            $stmtActualizarSeguimiento->bind_param('i', $seguimientoId);
            $stmtActualizarSeguimiento->execute();

            if ($stmtActualizarSeguimiento->affected_rows <= 0) {
                throw new RuntimeException('No fue posible actualizar la etapa del seguimiento.');
            }

            $this->connection->commit();
            $estado = $this->obtenerEstadoSeguimiento($seguimientoId, $usuarioId);

            return [
                'ok' => true,
                'existente' => false,
                'mensaje' => 'Oficio preparado correctamente.',
                'oficio_id' => $oficioId,
                'folio' => $folio,
                'estado' => $estado
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();

            return $this->error('No fue posible generar el oficio.', 500);
        }
    }

    private function consultaEstadoSeguimientoBase()
    {
        return "SELECT DISTINCT
                    seguimientos.id,
                    seguimientos.estado_id,
                    seguimientos.analista_id,
                    seguimientos.estado_seguimiento,
                    seguimientos.datos_verificados,
                    seguimientos.contacto_nombre,
                    seguimientos.contacto_cargo,
                    seguimientos.correo_verificado,
                    estados.clave_inegi,
                    estados.nombre AS estado_nombre,
                    oficio.id AS oficio_id,
                    oficio.folio,
                    oficio.estado_oficio
                FROM seguimientos_vinculacion seguimientos
                INNER JOIN estados
                    ON estados.id = seguimientos.estado_id
                LEFT JOIN oficios_vinculacion oficio
                    ON oficio.id = (
                        SELECT oficio_reciente.id
                        FROM oficios_vinculacion oficio_reciente
                        WHERE oficio_reciente.seguimiento_id = seguimientos.id
                        ORDER BY oficio_reciente.id DESC
                        LIMIT 1
                    )";
    }

    private function completarEstadoOficio($seguimiento)
    {
        $faltantes = [];

        if (trim((string)($seguimiento['contacto_nombre'] ?? '')) === '') {
            $faltantes[] = 'persona de contacto';
        }

        if (trim((string)($seguimiento['contacto_cargo'] ?? '')) === '') {
            $faltantes[] = 'cargo';
        }

        $correo = trim((string)($seguimiento['correo_verificado'] ?? ''));

        if ($correo === '') {
            $faltantes[] = 'correo verificado';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $faltantes[] = 'correo verificado válido';
        }

        $folio = trim((string)($seguimiento['folio'] ?? ''));
        $datosVerificados = (int)($seguimiento['datos_verificados'] ?? 0) === 1;
        $etapa = (string)($seguimiento['estado_seguimiento'] ?? '');
        $cumpleRequisitos =
            $datosVerificados &&
            $etapa === 'DATOS_VERIFICADOS' &&
            $folio === '' &&
            empty($faltantes);

        $seguimiento['faltantes'] = $faltantes;
        $seguimiento['cumple_requisitos_generacion'] = $cumpleRequisitos;
        $seguimiento['puede_generar'] = $cumpleRequisitos;

        return $seguimiento;
    }

    private function condicionAsignacionVigente($alias)
    {
        return "(
            ($alias.fecha_inicio IS NULL OR $alias.fecha_inicio <= CURDATE())
            AND ($alias.fecha_fin IS NULL OR $alias.fecha_fin >= CURDATE())
        )";
    }

    private function codigoEstadoFolio($claveInegi)
    {
        $codigos = [
            '01' => 'AGS',
            '02' => 'BC',
            '03' => 'BCS',
            '04' => 'CAM',
            '05' => 'COA',
            '06' => 'COL',
            '07' => 'CHP',
            '08' => 'CHH',
            '09' => 'CDMX',
            '10' => 'DGO',
            '11' => 'GTO',
            '12' => 'GRO',
            '13' => 'HGO',
            '14' => 'JAL',
            '15' => 'MEX',
            '16' => 'MIC',
            '17' => 'MOR',
            '18' => 'NAY',
            '19' => 'NL',
            '20' => 'OAX',
            '21' => 'PUE',
            '22' => 'QRO',
            '23' => 'QROO',
            '24' => 'SLP',
            '25' => 'SIN',
            '26' => 'SON',
            '27' => 'TAB',
            '28' => 'TAM',
            '29' => 'TLAX',
            '30' => 'VER',
            '31' => 'YUC',
            '32' => 'ZAC'
        ];

        $clave = str_pad(trim($claveInegi), 2, '0', STR_PAD_LEFT);

        return $codigos[$clave] ?? $clave;
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
