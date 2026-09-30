<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/SeguimientoActividadPresentacionService.php';
require_once __DIR__ . '/SeguimientoFlujoService.php';
require_once __DIR__ . '/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/ReunionResultadoService.php';
require_once __DIR__ . '/AgendaReunionService.php';
require_once __DIR__ . '/SeguimientoCorreoService.php';
require_once __DIR__ . '/ReunionFechaGuardService.php';

class ReporteSeguimientoInstitucionDetalleService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function construir($seguimientoId, $usuarioId, $modoAcceso): array
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $modoAcceso = (string)$modoAcceso;

        if ($seguimientoId <= 0 || $usuarioId <= 0) {
            return [];
        }

        $modelo = new SeguimientoVinculacionModel();
        $seguimiento = $this->obtenerSeguimientoAutorizado(
            $modelo,
            $seguimientoId,
            $usuarioId,
            $modoAcceso
        );

        if (!$seguimiento) {
            return [];
        }

        $interaccionesHumanas = [];
        $interaccionesPorDia = [];
        $correosRecientes = [];
        $oficios = [];
        $observaciones = [];

        try {
            $interaccionesHumanas = $this->obtenerInteraccionesHumanasRecientes(
                $seguimientoId,
                8
            );
            $presentadorActividad = new SeguimientoActividadPresentacionService();
            $interaccionesHumanas = array_map(
                static function (array $interaccion) use ($presentadorActividad) {
                    $interaccion['presentacion'] = $presentadorActividad->presentar($interaccion);
                    return $interaccion;
                },
                $interaccionesHumanas
            );
        } catch (Throwable $error) {
            error_log('[reporte_institucion_interacciones] ' . $error->getMessage());
        }

        try {
            $presentadorActividad = $presentadorActividad ?? new SeguimientoActividadPresentacionService();
            $correosRecientes = $this->obtenerCorreosRecientes($seguimientoId, 4);
            $correosRecientes = array_map(
                static function (array $interaccion) use ($presentadorActividad) {
                    $interaccion['presentacion'] = $presentadorActividad->presentar($interaccion);
                    return $interaccion;
                },
                $correosRecientes
            );
        } catch (Throwable $error) {
            error_log('[reporte_institucion_correos] ' . $error->getMessage());
        }

        try {
            $interaccionesPorDia = $this->obtenerInteraccionesPorDia($seguimientoId);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_interacciones_dia] ' . $error->getMessage());
        }

        try {
            $oficios = $this->obtenerOficiosRecientes($seguimientoId, 4);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_oficios] ' . $error->getMessage());
        }

        try {
            $observaciones = $modelo->obtenerUltimasObservacionesSeguimiento($seguimientoId, 4);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_observaciones] ' . $error->getMessage());
        }

        $reuniones = $this->obtenerReuniones($seguimientoId);
        $postEnvio = $this->obtenerPostEnvio($seguimientoId);
        $flujoUsuarioId = (int)($seguimiento['analista_id'] ?? 0);
        if ($flujoUsuarioId <= 0) {
            $flujoUsuarioId = $usuarioId;
        }
        $flujo = $this->obtenerFlujoEjecutivo(
            $seguimientoId,
            $flujoUsuarioId
        );

        return [
            'seguimiento' => $seguimiento,
            'contacto' => $this->contacto($seguimiento),
            'ultima_interaccion_humana' => $interaccionesHumanas[0] ?? null,
            'interacciones_recientes' => $interaccionesHumanas,
            'interacciones_por_dia' => $interaccionesPorDia,
            'correos_recientes' => $correosRecientes,
            'oficios' => $oficios,
            'observaciones' => $observaciones,
            'reuniones' => $reuniones,
            'ultima_reunion' => $reuniones[0] ?? null,
            'post_envio' => $postEnvio,
            'flujo' => $flujo,
            'hitos' => $this->construirHitos(
                $seguimiento,
                $oficios,
                $reuniones,
                $postEnvio,
                $flujo
            )
        ];
    }

    private function obtenerSeguimientoAutorizado(
        SeguimientoVinculacionModel $modelo,
        int $seguimientoId,
        int $usuarioId,
        string $modoAcceso
    ) {
        if ($modoAcceso === 'administrador') {
            return $modelo->obtenerSeguimientoAdministrador($seguimientoId);
        }

        if ($modoAcceso === 'supervisor') {
            return $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
        }

        return $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
    }

    private function contacto(array $seguimiento): array
    {
        $telefono = trim((string)($seguimiento['telefono_verificado'] ?? ''));
        if ($telefono === '') {
            $telefono = trim((string)($seguimiento['telefono_fuente'] ?? ''));
        }

        $correo = trim((string)($seguimiento['correo_verificado'] ?? ''));
        if ($correo === '') {
            $correo = trim((string)($seguimiento['correo_fuente'] ?? ''));
        }

        return [
            'nombre' => trim((string)($seguimiento['contacto_nombre'] ?? '')),
            'cargo' => trim((string)($seguimiento['contacto_cargo'] ?? '')),
            'telefono' => $telefono,
            'whatsapp' => trim((string)($seguimiento['whatsapp_verificado'] ?? '')),
            'correo' => $correo,
            'sitio_web' => trim((string)($seguimiento['sitio_web_fuente'] ?? '')),
            'direccion' => trim((string)($seguimiento['direccion_fuente'] ?? '')),
            'actividad_giro' => trim((string)($seguimiento['actividad_giro'] ?? '')),
            'datos_verificados' => (int)($seguimiento['datos_verificados'] ?? 0) === 1
        ];
    }

    private function obtenerInteraccionesHumanasRecientes(
        int $seguimientoId,
        int $limite
    ): array {
        $limite = max(1, min(8, $limite));
        $sql = "SELECT
                    interacciones.id,
                    interacciones.seguimiento_id,
                    interacciones.usuario_id,
                    interacciones.canal,
                    interacciones.resultado,
                    interacciones.fecha_inicio,
                    interacciones.notas,
                    interacciones.telefono_destino,
                    interacciones.correo_destino,
                    interacciones.duracion_segundos,
                    interacciones.proveedor_externo,
                    interacciones.id_externo,
                    usuarios.nombre,
                    usuarios.apellidos
                FROM interacciones_vinculacion interacciones
                INNER JOIN usuarios
                    ON usuarios.id = interacciones.usuario_id
                WHERE interacciones.seguimiento_id = ?
                  AND UPPER(TRIM(COALESCE(interacciones.canal, ''))) <> 'SISTEMA'
                ORDER BY interacciones.fecha_inicio DESC, interacciones.id DESC
                LIMIT $limite";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function obtenerInteraccionesPorDia(int $seguimientoId): array
    {
        $sql = "SELECT
                    DATE(interacciones.fecha_inicio) AS fecha,
                    COUNT(*) AS total
                FROM interacciones_vinculacion interacciones
                WHERE interacciones.seguimiento_id = ?
                  AND UPPER(TRIM(COALESCE(interacciones.canal, ''))) <> 'SISTEMA'
                  AND interacciones.fecha_inicio IS NOT NULL
                GROUP BY DATE(interacciones.fecha_inicio)
                ORDER BY DATE(interacciones.fecha_inicio) ASC";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return array_map(static function (array $fila): array {
            $fecha = trim((string)($fila['fecha'] ?? ''));
            $etiqueta = $fecha;
            try {
                $etiqueta = (new DateTime($fecha))->format('d/m');
            } catch (Throwable $error) {
            }

            return [
                'fecha' => $fecha,
                'etiqueta' => $etiqueta,
                'total' => (int)($fila['total'] ?? 0)
            ];
        }, $filas);
    }

    private function obtenerCorreosRecientes(
        int $seguimientoId,
        int $limite
    ): array {
        $limite = max(1, min(6, $limite));
        $sql = "SELECT
                    interacciones.id,
                    interacciones.seguimiento_id,
                    interacciones.usuario_id,
                    interacciones.canal,
                    interacciones.resultado,
                    interacciones.fecha_inicio,
                    interacciones.notas,
                    usuarios.nombre,
                    usuarios.apellidos
                FROM interacciones_vinculacion interacciones
                INNER JOIN usuarios
                    ON usuarios.id = interacciones.usuario_id
                WHERE interacciones.seguimiento_id = ?
                  AND UPPER(TRIM(COALESCE(interacciones.canal, ''))) = 'CORREO'
                ORDER BY interacciones.fecha_inicio DESC, interacciones.id DESC
                LIMIT $limite";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function obtenerOficiosRecientes(
        int $seguimientoId,
        int $limite
    ): array {
        $limite = max(1, min(4, $limite));
        $sql = "SELECT
                    id,
                    folio,
                    destinatario_nombre,
                    destinatario_cargo,
                    destinatario_correo,
                    estado_oficio,
                    fecha_generacion,
                    fecha_envio
                FROM oficios_vinculacion
                WHERE seguimiento_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT $limite";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function obtenerReuniones(int $seguimientoId): array
    {
        if (!$this->tablaDisponible('reuniones_vinculacion')) {
            return [];
        }

        try {
            $sql = "SELECT
                        r.*,
                        TRIM(CONCAT(COALESCE(k.nombre, ''), ' ', COALESCE(k.apellidos, ''))) AS cuenta_clave_nombre
                    FROM reuniones_vinculacion r
                    LEFT JOIN usuarios k ON k.id = r.cuenta_clave_id
                    WHERE r.seguimiento_id = ?
                    ORDER BY r.fecha_propuesta DESC, r.id DESC
                    LIMIT 5";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $seguimientoId);
            $stmt->execute();

            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_reuniones] ' . $error->getMessage());
            return [];
        }
    }

    private function obtenerPostEnvio(int $seguimientoId): array
    {
        if (!$this->tablaDisponible('seguimientos_vinculacion_post_envio')) {
            return [];
        }

        try {
            $stmt = $this->connection->prepare(
                "SELECT *
                 FROM seguimientos_vinculacion_post_envio
                 WHERE seguimiento_id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('i', $seguimientoId);
            $stmt->execute();

            return $stmt->get_result()->fetch_assoc() ?: [];
        } catch (Throwable $error) {
            error_log('[reporte_institucion_post_envio] ' . $error->getMessage());
            return [];
        }
    }

    private function obtenerFlujoEjecutivo(int $seguimientoId, int $usuarioId): array
    {
        $flujo = [];

        try {
            $respuestaBase = (new SeguimientoFlujoService())->obtenerEstado(
                $seguimientoId,
                $usuarioId
            );
            if (($respuestaBase['ok'] ?? false) && is_array($respuestaBase['flujo'] ?? null)) {
                $flujo = $respuestaBase['flujo'];
            }
        } catch (Throwable $error) {
            error_log('[reporte_institucion_flujo_base] ' . $error->getMessage());
        }

        $postAplica = false;
        try {
            $respuestaPost = (new SeguimientoPostEnvioService())->obtenerFlujoSiAplica(
                $seguimientoId,
                $usuarioId
            );
            if (
                ($respuestaPost['ok'] ?? false) &&
                ($respuestaPost['aplica'] ?? false) &&
                is_array($respuestaPost['flujo'] ?? null)
            ) {
                $flujo = $respuestaPost['flujo'];
                $postAplica = true;
            }
        } catch (Throwable $error) {
            error_log('[reporte_institucion_flujo_post] ' . $error->getMessage());
        }

        if ($postAplica && !empty($flujo)) {
            try {
                $flujo = (new AgendaReunionService())->ajustarFlujoAnalista(
                    $seguimientoId,
                    $usuarioId,
                    $flujo
                );
                $flujo = (new SeguimientoCorreoService())->ajustarFlujo(
                    $seguimientoId,
                    $usuarioId,
                    $flujo
                );
                $flujo = (new ReunionFechaGuardService())->ajustarFlujo(
                    $seguimientoId,
                    $usuarioId,
                    $flujo
                );
                $flujo = (new ReunionResultadoService())->ajustarFlujo(
                    $seguimientoId,
                    $usuarioId,
                    $flujo
                );
            } catch (Throwable $error) {
                error_log('[reporte_institucion_flujo_ajustes] ' . $error->getMessage());
            }
        }

        return is_array($flujo) ? $flujo : [];
    }

    private function construirHitos(
        array $seguimiento,
        array $oficios,
        array $reuniones,
        array $postEnvio,
        array $flujo
    ): array {
        $hitos = [];
        $agregar = static function (
            array &$destino,
            string $clave,
            string $titulo,
            string $estado,
            string $fecha = '',
            string $detalle = ''
        ): void {
            $destino[] = [
                'clave' => $clave,
                'titulo' => $titulo,
                'estado' => $estado,
                'fecha' => $fecha,
                'detalle' => $detalle
            ];
        };

        $agregar(
            $hitos,
            'datos',
            'Datos de contacto',
            (int)($seguimiento['datos_verificados'] ?? 0) === 1 ? 'COMPLETADO' : 'PENDIENTE',
            (string)($seguimiento['datos_verificados_at'] ?? ''),
            (int)($seguimiento['datos_verificados'] ?? 0) === 1
                ? 'Información de contacto verificada.'
                : 'La información de contacto aún no está marcada como verificada.'
        );

        $oficio = $oficios[0] ?? [];
        $estadoOficio = strtoupper(trim((string)($oficio['estado_oficio'] ?? '')));
        $agregar(
            $hitos,
            'oficio',
            'Oficio institucional',
            $estadoOficio === 'ENVIADO'
                ? 'COMPLETADO'
                : (!empty($oficio) ? 'EN_PROCESO' : 'PENDIENTE'),
            (string)($oficio['fecha_envio'] ?? $oficio['fecha_generacion'] ?? ''),
            !empty($oficio)
                ? ('Folio ' . (trim((string)($oficio['folio'] ?? '')) !== '' ? (string)$oficio['folio'] : 'pendiente') . '.')
                : 'Aún no existe un oficio registrado.'
        );

        $respuestaAt = trim((string)($postEnvio['respuesta_at'] ?? ''));
        $respuestaTipo = strtoupper(trim((string)($postEnvio['respuesta_tipo'] ?? '')));
        $respuestaLabels = [
            'INTERESADO' => 'Interesado',
            'MAS_INFORMACION' => 'Solicitó más información',
            'QUIERE_REUNION' => 'Solicitó reunión',
            'CONTACTAR_DESPUES' => 'Solicitó retomar contacto',
            'NO_INTERESADO' => 'No interesado'
        ];
        $agregar(
            $hitos,
            'respuesta',
            'Respuesta de la institución',
            $respuestaAt !== '' ? 'COMPLETADO' : 'PENDIENTE',
            $respuestaAt,
            $respuestaAt !== ''
                ? ($respuestaLabels[$respuestaTipo] ?? 'Respuesta registrada')
                : 'Pendiente de respuesta.'
        );

        $reunion = $reuniones[0] ?? [];
        $estadoReunion = strtoupper(trim((string)($reunion['estado'] ?? '')));
        $fechaReunion = trim((string)($reunion['fecha_propuesta'] ?? ''));
        $realizadaAt = trim((string)($reunion['realizada_at'] ?? ''));
        $resultadoReunion = strtoupper(trim((string)($reunion['reunion_resultado'] ?? '')));
        $resultadoLabels = [
            'AVANZAR_CONVENIO' => 'Avanzar a convenio',
            'REQUIERE_SEGUIMIENTO' => 'Requiere seguimiento',
            'NO_INTERESADO' => 'No interesado'
        ];
        $estadoHitoReunion = $realizadaAt !== ''
            ? 'COMPLETADO'
            : (!empty($reunion) ? 'EN_PROCESO' : 'PENDIENTE');
        $detalleReunion = '';
        if ($resultadoReunion !== '') {
            $detalleReunion = $resultadoLabels[$resultadoReunion] ?? 'Resultado registrado';
        } elseif ($estadoReunion !== '') {
            $detalleReunion = ucfirst(strtolower(str_replace('_', ' ', $estadoReunion)));
        } else {
            $detalleReunion = 'Aún no hay una reunión registrada.';
        }
        $agregar(
            $hitos,
            'reunion',
            'Reunión',
            $estadoHitoReunion,
            $realizadaAt !== '' ? $realizadaAt : $fechaReunion,
            $detalleReunion
        );

        $convenioAt = trim((string)($postEnvio['convenio_formalizado_at'] ?? ''));
        $resultadoPost = strtoupper(trim((string)($postEnvio['reunion_resultado'] ?? '')));
        $agregar(
            $hitos,
            'convenio',
            'Convenio / formalización',
            $convenioAt !== ''
                ? 'COMPLETADO'
                : ($resultadoPost === 'AVANZAR_CONVENIO' ? 'EN_PROCESO' : 'PENDIENTE'),
            $convenioAt,
            $convenioAt !== ''
                ? 'Convenio formalizado.'
                : ($resultadoPost === 'AVANZAR_CONVENIO'
                    ? 'La relación está lista para avanzar a convenio.'
                    : 'La formalización todavía no ha iniciado.')
        );

        $pasoActual = (int)($flujo['paso_actual'] ?? 0);
        if ($pasoActual > 0) {
            foreach ($hitos as &$hito) {
                $orden = [
                    'datos' => 4,
                    'oficio' => 7,
                    'respuesta' => 9,
                    'reunion' => 12,
                    'convenio' => 13
                ][$hito['clave']] ?? 99;

                if ($hito['estado'] === 'PENDIENTE' && $pasoActual > $orden) {
                    $hito['estado'] = 'COMPLETADO';
                }
            }
            unset($hito);
        }

        return $hitos;
    }

    private function tablaDisponible(string $tabla): bool
    {
        $permitidas = [
            'reuniones_vinculacion',
            'seguimientos_vinculacion_post_envio'
        ];

        if (!in_array($tabla, $permitidas, true)) {
            return false;
        }

        $resultado = $this->connection->query("SHOW TABLES LIKE '" . $tabla . "'");
        return $resultado && $resultado->num_rows > 0;
    }
}
