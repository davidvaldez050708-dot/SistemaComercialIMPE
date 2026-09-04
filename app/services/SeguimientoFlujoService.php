<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoFlujoService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerEstado($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.analista_id,
                    seguimientos.nombre_entidad,
                    seguimientos.estado_seguimiento,
                    seguimientos.datos_verificados,
                    seguimientos.telefono_fuente,
                    seguimientos.correo_fuente,
                    seguimientos.sitio_web_fuente,
                    seguimientos.telefono_verificado,
                    seguimientos.whatsapp_verificado,
                    seguimientos.correo_verificado,
                    seguimientos.contacto_nombre,
                    seguimientos.contacto_cargo,
                    seguimientos.proxima_accion_at,
                    (
                        SELECT COUNT(*)
                        FROM interacciones_vinculacion interacciones
                        WHERE interacciones.seguimiento_id = seguimientos.id
                    ) AS total_interacciones,
                    (
                        SELECT COUNT(*)
                        FROM interacciones_vinculacion interacciones
                        WHERE interacciones.seguimiento_id = seguimientos.id
                            AND interacciones.canal = 'LLAMADA_IP'
                    ) AS total_llamadas,
                    oficio.id AS oficio_id,
                    oficio.folio,
                    oficio.estado_oficio,
                    oficio.archivo_pdf,
                    oficio.asunto_correo,
                    oficio.cuerpo_correo,
                    oficio.fecha_envio
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
        $seguimiento = $stmt->get_result()->fetch_assoc() ?: null;

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        return [
            'ok' => true,
            'flujo' => $this->resolverFlujo($seguimiento)
        ];
    }

    private function resolverFlujo($seguimiento)
    {
        $estado = (string)($seguimiento['estado_seguimiento'] ?? 'NUEVO');
        $datosVerificados = (int)($seguimiento['datos_verificados'] ?? 0) === 1;
        $telefonoDisponible = trim((string)(
            $seguimiento['telefono_verificado'] ??
            $seguimiento['telefono_fuente'] ??
            ''
        ));

        if ($telefonoDisponible === '') {
            $telefonoDisponible = trim((string)($seguimiento['telefono_fuente'] ?? ''));
        }

        $contactoNombre = trim((string)($seguimiento['contacto_nombre'] ?? ''));
        $contactoCargo = trim((string)($seguimiento['contacto_cargo'] ?? ''));
        $correoVerificado = trim((string)($seguimiento['correo_verificado'] ?? ''));
        $folio = trim((string)($seguimiento['folio'] ?? ''));
        $archivoPdf = trim((string)($seguimiento['archivo_pdf'] ?? ''));
        $asuntoCorreo = trim((string)($seguimiento['asunto_correo'] ?? ''));
        $cuerpoCorreo = trim((string)($seguimiento['cuerpo_correo'] ?? ''));
        $fechaEnvio = trim((string)($seguimiento['fecha_envio'] ?? ''));
        $proximaAccionAt = trim((string)($seguimiento['proxima_accion_at'] ?? ''));
        $totalInteracciones = (int)($seguimiento['total_interacciones'] ?? 0);
        $totalLlamadas = (int)($seguimiento['total_llamadas'] ?? 0);

        $faltantesContacto = [];

        if ($contactoNombre === '') {
            $faltantesContacto[] = 'persona de contacto';
        }

        if ($contactoCargo === '') {
            $faltantesContacto[] = 'cargo / área';
        }

        if ($correoVerificado === '' || !filter_var($correoVerificado, FILTER_VALIDATE_EMAIL)) {
            $faltantesContacto[] = 'correo válido';
        }

        $pdfGenerado = false;

        if ($archivoPdf !== '') {
            $rutaPdf = defined('ROOT_PATH')
                ? ROOT_PATH . '/' . ltrim(str_replace('\\', '/', $archivoPdf), '/')
                : '';
            $pdfGenerado = $rutaPdf !== '' && is_file($rutaPdf);
        }

        $correoPreparado = $asuntoCorreo !== '' && $cuerpoCorreo !== '';
        $oficioGenerado = $folio !== '';
        $envioRealizado =
            $fechaEnvio !== '' ||
            (string)($seguimiento['estado_oficio'] ?? '') === 'ENVIADO' ||
            $estado === 'ESPERANDO_RESPUESTA';

        // La ruta principal termina cuando el convenio queda formalizado.
        // Los estados internos de preparación/programación del correo se resuelven
        // dentro del paso 7 para no inflar artificialmente la ruta del Analista.
        $pasos = [
            ['numero' => 1, 'clave' => 'INICIO', 'titulo' => 'Seguimiento iniciado'],
            ['numero' => 2, 'clave' => 'INVESTIGACION', 'titulo' => 'Investigación de datos'],
            ['numero' => 3, 'clave' => 'CONTACTO', 'titulo' => 'Contacto y validación'],
            ['numero' => 4, 'clave' => 'VERIFICACION', 'titulo' => 'Datos verificados'],
            ['numero' => 5, 'clave' => 'OFICIO', 'titulo' => 'Oficio preparado'],
            ['numero' => 6, 'clave' => 'PDF', 'titulo' => 'PDF generado'],
            ['numero' => 7, 'clave' => 'ENVIO', 'titulo' => 'Oficio / correo enviado'],
            ['numero' => 8, 'clave' => 'ESPERA', 'titulo' => 'Esperando respuesta'],
            ['numero' => 9, 'clave' => 'RESPUESTA', 'titulo' => 'Respuesta recibida'],
            ['numero' => 10, 'clave' => 'SEGUIMIENTO_CORREO', 'titulo' => 'Seguimiento por correo'],
            ['numero' => 11, 'clave' => 'REUNION_AGENDADA', 'titulo' => 'Reunión agendada'],
            ['numero' => 12, 'clave' => 'REUNION_REALIZADA', 'titulo' => 'Reunión realizada'],
            ['numero' => 13, 'clave' => 'CONVENIO', 'titulo' => 'Convenio formalizado']
        ];

        if ($estado === 'DESCARTADO') {
            return $this->construirRespuesta(
                $pasos,
                1,
                'Seguimiento descartado',
                'Este seguimiento no tiene acciones operativas pendientes.',
                [],
                null,
                null,
                $seguimiento
            );
        }

        if ($envioRealizado) {
            return $this->construirRespuesta(
                $pasos,
                8,
                'Esperar y registrar respuesta',
                'El oficio y el correo ya fueron enviados. Cuando la institución responda, registra la interacción para continuar con el seguimiento, la reunión y el convenio.',
                [],
                [
                    'codigo' => 'REGISTRAR_INTERACCION',
                    'etiqueta' => 'Registrar respuesta',
                    'icono' => 'bi-chat-left-text'
                ],
                null,
                $seguimiento
            );
        }

        if ($oficioGenerado || $estado === 'OFICIO_PREPARADO') {
            if (!$pdfGenerado) {
                return $this->construirRespuesta(
                    $pasos,
                    6,
                    'Revisar y generar PDF',
                    'El oficio ya tiene folio. Revisa la vista previa y genera el documento PDF.',
                    [],
                    [
                        'codigo' => 'GENERAR_PDF',
                        'etiqueta' => 'Revisar y generar PDF',
                        'icono' => 'bi-file-earmark-pdf'
                    ],
                    null,
                    $seguimiento
                );
            }

            if (!$correoPreparado) {
                return $this->construirRespuesta(
                    $pasos,
                    7,
                    'Preparar correo institucional',
                    'El PDF está listo. Revisa el destinatario, asunto y mensaje que acompañarán al oficio antes de enviarlo.',
                    [],
                    [
                        'codigo' => 'PREPARAR_CORREO',
                        'etiqueta' => 'Preparar correo',
                        'icono' => 'bi-envelope-paper'
                    ],
                    null,
                    $seguimiento
                );
            }

            $accionProgramacion = $proximaAccionAt === ''
                ? [
                    'codigo' => 'PROGRAMAR_ENVIO',
                    'etiqueta' => 'Programar para después',
                    'icono' => 'bi-calendar-event'
                ]
                : [
                    'codigo' => 'REPROGRAMAR_ENVIO',
                    'etiqueta' => 'Reprogramar envío',
                    'icono' => 'bi-calendar-check'
                ];

            return $this->construirRespuesta(
                $pasos,
                7,
                'Enviar oficio / correo',
                $proximaAccionAt === ''
                    ? 'El PDF y el correo están listos. Revisa el mensaje y envíalo ahora, o prográmalo para una fecha posterior.'
                    : 'El envío tiene una fecha programada. También puedes revisar el correo y enviarlo ahora si ya corresponde.',
                [],
                [
                    'codigo' => 'PREPARAR_CORREO',
                    'etiqueta' => 'Revisar y enviar correo',
                    'icono' => 'bi-send'
                ],
                $accionProgramacion,
                $seguimiento
            );
        }

        if ($datosVerificados || $estado === 'DATOS_VERIFICADOS') {
            if (!empty($faltantesContacto)) {
                return $this->construirRespuesta(
                    $pasos,
                    5,
                    'Completar datos para generar oficio',
                    'La información fue marcada como verificada, pero el oficio necesita destinatario, cargo y correo válido. Completa esos datos para poder asignar el folio.',
                    $faltantesContacto,
                    [
                        'codigo' => 'COMPLETAR_DATOS',
                        'etiqueta' => 'Completar datos',
                        'icono' => 'bi-person-lines-fill'
                    ],
                    null,
                    $seguimiento
                );
            }

            return $this->construirRespuesta(
                $pasos,
                5,
                'Generar oficio',
                'El contacto ya está validado. El siguiente paso es crear el oficio institucional y asignar su folio.',
                [],
                [
                    'codigo' => 'GENERAR_OFICIO',
                    'etiqueta' => 'Generar oficio',
                    'icono' => 'bi-file-earmark-text'
                ],
                null,
                $seguimiento
            );
        }

        if (empty($faltantesContacto)) {
            return $this->construirRespuesta(
                $pasos,
                4,
                'Verificar información de contacto',
                'Ya están capturados persona, cargo y correo. Confirma que la información sea correcta para avanzar al oficio.',
                [],
                [
                    'codigo' => 'VERIFICAR_CONTACTO',
                    'etiqueta' => 'Verificar contacto',
                    'icono' => 'bi-patch-check'
                ],
                null,
                $seguimiento
            );
        }

        if ($estado === 'CONTACTANDO' || $totalInteracciones > 0 || $totalLlamadas > 0) {
            return $this->construirRespuesta(
                $pasos,
                3,
                'Continuar validación por llamada',
                $telefonoDisponible !== ''
                    ? 'Usa el teléfono disponible para confirmar con quién debe dirigirse la Fundación y registra los datos nuevos que te proporcionen.'
                    : 'Continúa investigando un teléfono útil para contactar a la institución y validar con quién debe dirigirse la Fundación.',
                $faltantesContacto,
                [
                    'codigo' => $telefonoDisponible !== '' ? 'REGISTRAR_LLAMADA' : 'COMPLETAR_DATOS',
                    'etiqueta' => $telefonoDisponible !== '' ? 'Registrar llamada' : 'Completar datos',
                    'icono' => $telefonoDisponible !== '' ? 'bi-telephone' : 'bi-pencil-square'
                ],
                $telefonoDisponible !== ''
                    ? [
                        'codigo' => 'COMPLETAR_DATOS',
                        'etiqueta' => 'Actualizar contacto',
                        'icono' => 'bi-person-lines-fill'
                    ]
                    : null,
                $seguimiento
            );
        }

        return $this->construirRespuesta(
            $pasos,
            2,
            'Revisar y completar información',
            $telefonoDisponible !== ''
                ? 'Revisa los datos encontrados, completa lo que puedas investigar y después realiza la primera llamada de validación.'
                : 'Antes de llamar, investiga un teléfono útil y completa la información disponible de la institución.',
            $this->faltantesInvestigacion($seguimiento),
            [
                'codigo' => 'COMPLETAR_DATOS',
                'etiqueta' => 'Revisar datos',
                'icono' => 'bi-search'
            ],
            $telefonoDisponible !== ''
                ? [
                    'codigo' => 'REGISTRAR_LLAMADA',
                    'etiqueta' => 'Registrar primera llamada',
                    'icono' => 'bi-telephone'
                ]
                : null,
            $seguimiento
        );
    }

    private function faltantesInvestigacion($seguimiento)
    {
        $faltantes = [];

        if (
            trim((string)($seguimiento['telefono_verificado'] ?? '')) === '' &&
            trim((string)($seguimiento['telefono_fuente'] ?? '')) === ''
        ) {
            $faltantes[] = 'teléfono';
        }

        if (
            trim((string)($seguimiento['correo_verificado'] ?? '')) === '' &&
            trim((string)($seguimiento['correo_fuente'] ?? '')) === ''
        ) {
            $faltantes[] = 'correo';
        }

        if (trim((string)($seguimiento['sitio_web_fuente'] ?? '')) === '') {
            $faltantes[] = 'sitio web';
        }

        return $faltantes;
    }

    private function construirRespuesta(
        $pasos,
        $pasoActual,
        $titulo,
        $descripcion,
        $faltantes,
        $accionPrincipal,
        $accionSecundaria,
        $seguimiento
    ) {
        $pasoActual = max(1, min(count($pasos), (int)$pasoActual));
        $indice = $pasoActual - 1;
        $anterior = $indice > 0 ? $pasos[$indice - 1] : null;
        $actual = $pasos[$indice] ?? null;
        $siguiente = isset($pasos[$indice + 1]) ? $pasos[$indice + 1] : null;

        return [
            'seguimiento_id' => (int)($seguimiento['id'] ?? 0),
            'paso_actual' => $pasoActual,
            'total_pasos' => count($pasos),
            'porcentaje' => (int)round(($pasoActual / count($pasos)) * 100),
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'faltantes' => array_values($faltantes),
            'accion_principal' => $accionPrincipal,
            'accion_secundaria' => $accionSecundaria,
            'ventana' => [
                'anterior' => $anterior,
                'actual' => $actual,
                'siguiente' => $siguiente
            ],
            'contexto' => [
                'estado_seguimiento' => (string)($seguimiento['estado_seguimiento'] ?? ''),
                'telefono_disponible' => trim((string)(
                    $seguimiento['telefono_verificado'] ??
                    $seguimiento['telefono_fuente'] ??
                    ''
                )),
                'folio' => trim((string)($seguimiento['folio'] ?? '')),
                'proxima_accion_at' => trim((string)($seguimiento['proxima_accion_at'] ?? ''))
            ]
        ];
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
