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
            'BUZON_VOZ' => 'BUZON_VOZ',
            'FUERA_SERVICIO' => 'FUERA_SERVICIO',
            'NUMERO_INCORRECTO' => 'NUMERO_INCORRECTO',
            'CONTACTO_INCORRECTO' => 'CONTACTO_INCORRECTO',
            'CONTACTO_CORRECTO' => 'CONTACTADO',
            'CONTACTO_REFERIDO' => 'CONTACTADO',
            'SOLICITO_INFORMACION' => 'SOLICITO_INFORMACION',
            'SOLICITO_LLAMAR_DESPUES' => 'SOLICITO_LLAMAR_DESPUES',
            'NO_INTERESADO' => 'NO_INTERESADO',
            'OTRO' => 'OTRO'
        ];
        $resultado = $resultados[$resultadoFormulario] ?? '';

        if ($resultado === '') {
            return $this->error('Selecciona un resultado válido.', 422);
        }

        $personaAtendio = trim((string)($datos['persona_atendio'] ?? ''));
        $observacion = trim((string)($datos['observacion'] ?? ''));
        $origenLlamada = strtoupper(trim((string)($datos['origen_llamada'] ?? 'MANUAL')));
        if (!in_array($origenLlamada, ['ZADARMA', 'PRUEBA', 'MANUAL'], true)) {
            $origenLlamada = 'MANUAL';
        }

        $nuevoTelefonoContacto = trim((string)($datos['nuevo_telefono_contacto'] ?? ''));
        $nuevoCorreoContacto = trim((string)($datos['nuevo_correo_contacto'] ?? ''));
        $nuevoContactoNombre = trim((string)($datos['nuevo_contacto_nombre'] ?? ''));
        $nuevoContactoCargo = trim((string)($datos['nuevo_contacto_cargo'] ?? ''));

        if ($resultadoFormulario === 'CONTACTO_REFERIDO') {
            if ($canalFormulario !== 'LLAMADA') {
                return $this->error(
                    'El contacto referido solo puede registrarse como resultado de una llamada.',
                    422
                );
            }

            if ($nuevoTelefonoContacto === '' && $nuevoCorreoContacto === '') {
                return $this->error(
                    'Captura al menos el nuevo teléfono o correo proporcionado por la institución.',
                    422
                );
            }

            if (strlen($nuevoTelefonoContacto) > 80) {
                return $this->error('El nuevo teléfono de contacto es demasiado largo.', 422);
            }

            if (
                $nuevoCorreoContacto !== '' &&
                !filter_var($nuevoCorreoContacto, FILTER_VALIDATE_EMAIL)
            ) {
                return $this->error(
                    'El nuevo correo proporcionado no tiene un formato válido.',
                    422
                );
            }
        } else {
            $nuevoTelefonoContacto = '';
            $nuevoCorreoContacto = '';
            $nuevoContactoNombre = '';
            $nuevoContactoCargo = '';
        }

        $resultadoAdmiteVerificacion = in_array(
            $resultadoFormulario,
            [
                'CONTACTO_CORRECTO',
                'CONTACTO_REFERIDO',
                'SOLICITO_INFORMACION',
                'SOLICITO_LLAMAR_DESPUES',
                'NO_INTERESADO'
            ],
            true
        );

        $telefonoActual = trim((string)(
            !empty($seguimiento['telefono_verificado'])
                ? $seguimiento['telefono_verificado']
                : ($seguimiento['telefono_fuente'] ?? '')
        ));
        $correoActual = trim((string)(
            !empty($seguimiento['correo_verificado'])
                ? $seguimiento['correo_verificado']
                : ($seguimiento['correo_fuente'] ?? '')
        ));
        $contactoActual = trim((string)($seguimiento['contacto_nombre'] ?? ''));
        $evidenciasVerificacion = [];

        if ($canalFormulario === 'LLAMADA' && $resultadoAdmiteVerificacion) {
            if ((int)($datos['verificacion_telefono_confirmado'] ?? 0) === 1) {
                if ($telefonoActual === '') {
                    return $this->error(
                        'No existe un teléfono registrado que pueda marcarse como confirmado.',
                        422
                    );
                }
                $evidenciasVerificacion[] = 'Teléfono confirmado';
            }

            if ((int)($datos['verificacion_correo_confirmado'] ?? 0) === 1) {
                if ($correoActual === '') {
                    return $this->error(
                        'No existe un correo registrado que pueda marcarse como confirmado.',
                        422
                    );
                }
                $evidenciasVerificacion[] = 'Correo confirmado';
            }

            if ((int)($datos['verificacion_contacto_confirmado'] ?? 0) === 1) {
                if ($contactoActual === '') {
                    return $this->error(
                        'No existe una persona de contacto registrada que pueda marcarse como confirmada.',
                        422
                    );
                }
                $evidenciasVerificacion[] = 'Contacto institucional confirmado';
            }

            if ($resultadoFormulario === 'CONTACTO_REFERIDO') {
                if ($nuevoTelefonoContacto !== '') {
                    $evidenciasVerificacion[] = 'Nuevo teléfono proporcionado';
                }
                if ($nuevoCorreoContacto !== '') {
                    $evidenciasVerificacion[] = 'Nuevo correo proporcionado';
                }
            }
        }

        $evidenciasVerificacion = array_values(array_unique($evidenciasVerificacion));
        $esCandidataVerificacion =
            $canalFormulario === 'LLAMADA' &&
            $resultadoAdmiteVerificacion &&
            !empty($evidenciasVerificacion);
        $esVerificacionEfectiva =
            $esCandidataVerificacion &&
            $origenLlamada === 'ZADARMA';

        if ($esCandidataVerificacion && $personaAtendio === '') {
            return $this->error(
                'Indica quién atendió la llamada para registrar una verificación efectiva.',
                422
            );
        }

        $fechaOriginal = trim((string)($datos['fecha_inicio'] ?? ''));
        $fechaInicio = $this->normalizarFechaHora($fechaOriginal);

        if ($fechaOriginal === '') {
            $fechaInicio = date('Y-m-d H:i:s');
        } elseif ($fechaInicio === null) {
            return $this->error('La fecha de interacción no es válida.', 422);
        }

        $fechaInicioTs = strtotime((string)$fechaInicio);
        if ($fechaInicioTs === false || $fechaInicioTs > (time() + 60)) {
            return $this->error('La fecha de interacción no puede estar en el futuro.', 422);
        }

        $notas = trim(implode("\n", array_filter([
            $personaAtendio !== '' ? 'Persona atendió: ' . $personaAtendio : '',
            $resultadoFormulario === 'NO_INTERESADO'
                ? 'Resultado registrado: No interesado'
                : '',
            $resultadoFormulario === 'CONTACTO_REFERIDO' && $nuevoTelefonoContacto !== ''
                ? 'Contacto referido: nuevo teléfono ' . $nuevoTelefonoContacto
                : '',
            $resultadoFormulario === 'CONTACTO_REFERIDO' && $nuevoCorreoContacto !== ''
                ? 'Contacto referido: nuevo correo ' . $nuevoCorreoContacto
                : '',
            $nuevoContactoNombre !== '' ? 'Nuevo contacto: ' . $nuevoContactoNombre : '',
            $nuevoContactoCargo !== '' ? 'Cargo / Área: ' . $nuevoContactoCargo : '',
            $esVerificacionEfectiva ? '[VERIFICACION_EFECTIVA]' : '',
            $esVerificacionEfectiva ? '[VERIFICACION_PENDIENTE_TELEFONIA]' : '',
            $esCandidataVerificacion && $origenLlamada === 'PRUEBA'
                ? '[REGISTRO_LLAMADA_PRUEBA]'
                : '',
            $esCandidataVerificacion && $origenLlamada === 'MANUAL'
                ? '[REGISTRO_LLAMADA_MANUAL]'
                : '',
            $esCandidataVerificacion
                ? (
                    $esVerificacionEfectiva
                        ? 'Verificación obtenida: '
                        : 'Verificación registrada sin contabilizar: '
                  ) . implode(' · ', $evidenciasVerificacion)
                : '',
            $observacion
        ])));

        if ($notas === '') {
            $notas = 'Interacción informativa registrada durante la ruta de vinculación.';
        }

        $this->connection->begin_transaction();
        $etapaPersistencia = 'guardar la interacción';

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

            if ($nuevoTelefonoContacto !== '' || $nuevoCorreoContacto !== '') {
                $etapaPersistencia = 'actualizar el contacto referido';
                $telefonoDestinoContacto = $nuevoTelefonoContacto !== ''
                    ? $nuevoTelefonoContacto
                    : trim((string)($seguimiento['telefono_verificado'] ?? ''));
                $correoDestinoContacto = $nuevoCorreoContacto !== ''
                    ? $nuevoCorreoContacto
                    : trim((string)($seguimiento['correo_verificado'] ?? ''));
                $nombreDestinoContacto = $nuevoContactoNombre !== ''
                    ? $nuevoContactoNombre
                    : trim((string)($seguimiento['contacto_nombre'] ?? ''));
                $cargoDestinoContacto = $nuevoContactoCargo !== ''
                    ? $nuevoContactoCargo
                    : trim((string)($seguimiento['contacto_cargo'] ?? ''));

                $sqlContacto = "UPDATE seguimientos_vinculacion
                        SET telefono_verificado = ?,
                            correo_verificado = ?,
                            contacto_nombre = ?,
                            contacto_cargo = ?
                        WHERE id = ?
                          AND analista_id = ?
                          AND activo = 1";
                $stmtContacto = $this->connection->prepare($sqlContacto);
                $stmtContacto->bind_param(
                    'ssssii',
                    $telefonoDestinoContacto,
                    $correoDestinoContacto,
                    $nombreDestinoContacto,
                    $cargoDestinoContacto,
                    $seguimientoId,
                    $usuarioId
                );
                $stmtContacto->execute();
            }

            $etapaPersistencia = 'actualizar la fecha de última interacción';
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
                ],
                'verificacion_telefonica' => [
                    'candidata' => $esCandidataVerificacion,
                    'contabilizable' => $esVerificacionEfectiva,
                    'origen' => $origenLlamada,
                    'evidencias' => $evidenciasVerificacion
                ]
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log(
                'Error registrando interacción informativa (' .
                $etapaPersistencia . '): ' . $error->getMessage()
            );

            return $this->error(
                'No fue posible ' . $etapaPersistencia . '.',
                500
            );
        }
    }

    private function obtenerSeguimientoAvanzado($seguimientoId, $usuarioId)
    {
        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.estado_seguimiento,
                    seguimientos.telefono_fuente,
                    seguimientos.correo_fuente,
                    seguimientos.telefono_verificado,
                    seguimientos.correo_verificado,
                    seguimientos.contacto_nombre,
                    seguimientos.contacto_cargo,
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
            'BUZON_VOZ' => 'Buzón de voz',
            'FUERA_SERVICIO' => 'Fuera del área / fuera de servicio',
            'NUMERO_INCORRECTO' => 'Número incorrecto',
            'CONTACTO_INCORRECTO' => 'Contacto incorrecto',
            'CONTACTO_CORRECTO' => 'Contacto correcto',
            'CONTACTO_REFERIDO' => 'Me proporcionaron otro contacto',
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
