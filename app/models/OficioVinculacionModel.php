<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioVinculacionModel
{
    private const LOCK_FOLIO_GLOBAL = 'sistema_comercial_impe_oficio_folio_global';

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

    public function listarPlantillasOficioDocx()
    {
        $sql = "SELECT id, nombre, 'Plantilla personalizada' AS descripcion
                FROM plantillas_vinculacion
                WHERE tipo = 'OFICIO'
                    AND activo = 1
                    AND archivo_docx IS NOT NULL
                    AND archivo_docx <> ''
                    AND archivo_docx NOT LIKE 'storage/templates/oficios_personalizados/generacion%'
                ORDER BY created_at DESC, id DESC";
        $resultado = $this->connection->query($sql);

        return $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function obtenerPlantillaGuardada($id)
    {
        $stmt = $this->connection->prepare("SELECT id, nombre, archivo_docx
            FROM plantillas_vinculacion WHERE id = ? AND tipo = 'OFICIO' AND activo = 1
            AND archivo_docx IS NOT NULL AND archivo_docx <> ''
            AND archivo_docx NOT LIKE 'storage/templates/oficios_personalizados/generacion%' LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function registrarPlantillaOficioDocx($nombre, $ruta, $usuarioId)
    {
        $descripcion = 'Plantilla personalizada';
        $contenido = '';
        $sql = "INSERT INTO plantillas_vinculacion
                    (nombre, tipo, descripcion, contenido, archivo_docx, activo, creado_por)
                VALUES (?, 'OFICIO', ?, ?, ?, 1, ?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssssi', $nombre, $descripcion, $contenido, $ruta, $usuarioId);

        return $stmt->execute() ? (int)$this->connection->insert_id : 0;
    }

    public function eliminarPlantillaOficioDocx($plantillaId)
    {
        $sql = "DELETE FROM plantillas_vinculacion
                WHERE id = ? AND tipo = 'OFICIO' AND archivo_docx IS NOT NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $plantillaId);

        return $stmt->execute();
    }

    public function generarBorrador($seguimientoId, $usuarioId, $plantillaId = 0)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $plantillaId = (int)$plantillaId;
        $bloqueoFolio = false;
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

            if ($plantillaId > 0 && !$this->plantillaDocxActiva($plantillaId)) {
                $this->connection->rollback();
                return $this->error('La plantilla de oficio seleccionada no está disponible.', 422);
            }

            $bloqueoFolio = $this->adquirirBloqueoFolioGlobal();

            if (!$bloqueoFolio) {
                throw new RuntimeException(
                    'No fue posible reservar el consecutivo general del oficio.'
                );
            }

            $consecutivo = $this->siguienteConsecutivoFolioGeneral();

            if ($consecutivo <= 0) {
                throw new RuntimeException('No fue posible obtener el consecutivo del oficio.');
            }

            $folio = sprintf(
                'REDMEX/%04d/%s',
                $consecutivo,
                date('d-m/y')
            );
            $plantillaSeleccionada = $plantillaId > 0 ? $plantillaId : null;

            if ($oficioExistente) {
                $oficioId = (int)$oficioExistente['id'];
                $sqlOficio = "UPDATE oficios_vinculacion
                        SET folio = ?,
                            plantilla_oficio_id = ?,
                            destinatario_nombre = ?,
                            destinatario_cargo = ?,
                            destinatario_correo = ?,
                            estado_oficio = 'BORRADOR',
                            solicita_reunion = 1,
                            error_envio = NULL
                        WHERE id = ?";
                $stmtOficio = $this->connection->prepare($sqlOficio);
                $stmtOficio->bind_param(
                    'sisssi',
                    $folio,
                    $plantillaSeleccionada,
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
                            plantilla_oficio_id,
                            solicita_reunion,
                            estado_oficio
                        ) VALUES (?, ?, ?, ?, ?, ?, 1, 'BORRADOR')";
                $stmtOficio = $this->connection->prepare($sqlOficio);
                $stmtOficio->bind_param(
                    'issssi',
                    $seguimientoId,
                    $folio,
                    $contactoNombre,
                    $contactoCargo,
                    $correoVerificado,
                    $plantillaSeleccionada
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
            $this->liberarBloqueoFolioGlobal();
            $bloqueoFolio = false;
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

            if ($bloqueoFolio) {
                $this->liberarBloqueoFolioGlobal();
            }

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

    private function plantillaDocxActiva($plantillaId)
    {
        $sql = "SELECT id FROM plantillas_vinculacion
                WHERE id = ? AND tipo = 'OFICIO' AND activo = 1
                    AND archivo_docx IS NOT NULL AND archivo_docx <> '' LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $plantillaId);
        $stmt->execute();

        return (bool)$stmt->get_result()->fetch_assoc();
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

    private function adquirirBloqueoFolioGlobal()
    {
        $nombre = self::LOCK_FOLIO_GLOBAL;
        $sql = "SELECT GET_LOCK(?, 5) AS adquirido";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: [];

        return (int)($fila['adquirido'] ?? 0) === 1;
    }

    private function liberarBloqueoFolioGlobal()
    {
        $nombre = self::LOCK_FOLIO_GLOBAL;
        $sql = "SELECT RELEASE_LOCK(?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $nombre);
        $stmt->execute();
    }

    private function siguienteConsecutivoFolioGeneral()
    {
        $sql = "SELECT COALESCE(MAX(
                    CAST(
                        SUBSTRING_INDEX(
                            SUBSTRING_INDEX(folio, '/', 2),
                            '/',
                            -1
                        ) AS UNSIGNED
                    )
                ), 0) AS ultimo
                FROM oficios_vinculacion
                WHERE folio REGEXP '^REDMEX/[0-9]+/[0-9]{2}-[0-9]{2}/[0-9]{2}$'";
        $resultado = $this->connection->query($sql);
        $fila = $resultado ? ($resultado->fetch_assoc() ?: []) : [];

        return (int)($fila['ultimo'] ?? 0) + 1;
    }

    private function condicionAsignacionVigente($alias)
    {
        return "(
            ($alias.fecha_inicio IS NULL OR $alias.fecha_inicio <= CURDATE())
            AND ($alias.fecha_fin IS NULL OR $alias.fecha_fin >= CURDATE())
        )";
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
