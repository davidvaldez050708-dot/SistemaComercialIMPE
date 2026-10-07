<?php

require_once __DIR__ . '/SeguimientoFlujoService.php';
require_once __DIR__ . '/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/SeguimientoCorreoService.php';
require_once __DIR__ . '/AgendaReunionService.php';
require_once __DIR__ . '/ReunionFechaGuardService.php';
require_once __DIR__ . '/ReunionResultadoService.php';
require_once __DIR__ . '/ConvenioDocumentosService.php';
require_once __DIR__ . '/SeguimientoHistorialImportadoService.php';

class SeguimientoRutaOperativaService
{
    private $flujoService;
    private $postEnvioService;
    private $correoService;
    private $agendaService;
    private $fechaGuardService;
    private $resultadoService;
    private $convenioDocumentosService;
    private $historialImportadoService;

    public function __construct(
        $flujoService = null,
        $postEnvioService = null,
        $correoService = null,
        $agendaService = null,
        $fechaGuardService = null,
        $resultadoService = null,
        $convenioDocumentosService = null,
        $historialImportadoService = null
    ) {
        $this->flujoService = $flujoService ?: new SeguimientoFlujoService();
        $this->postEnvioService = $postEnvioService ?: new SeguimientoPostEnvioService();
        $this->correoService = $correoService ?: new SeguimientoCorreoService();
        $this->agendaService = $agendaService ?: new AgendaReunionService();
        $this->fechaGuardService = $fechaGuardService ?: new ReunionFechaGuardService();
        $this->resultadoService = $resultadoService ?: new ReunionResultadoService();
        $this->convenioDocumentosService = $convenioDocumentosService ?: new ConvenioDocumentosService();
        $this->historialImportadoService = $historialImportadoService
            ?: new SeguimientoHistorialImportadoService();
    }

    public function resolver($seguimientoId, $analistaId, $seguimientoBase = [])
    {
        $seguimientoId = (int)$seguimientoId;
        $analistaId = (int)$analistaId;
        $seguimientoBase = is_array($seguimientoBase)
            ? $seguimientoBase
            : [];

        if ($seguimientoId <= 0 || $analistaId <= 0) {
            return [
                'ok' => false,
                'mensaje' => 'El seguimiento no tiene un Analista responsable válido.',
                'codigo_http' => 422
            ];
        }

        try {
            $flujo = null;
            $postEnvio = $this->postEnvioService->obtenerFlujoSiAplica(
                $seguimientoId,
                $analistaId
            );

            if (($postEnvio['ok'] ?? false) && ($postEnvio['aplica'] ?? false)) {
                /*
                 * Primero resolvemos si el Paso 10 ya fue cerrado de forma
                 * explícita. Así la agenda recibe un flujo ya ubicado en el
                 * Paso 11 y puede reflejar correctamente solicitud, cambio o
                 * confirmación de reunión.
                 */
                $flujo = $this->correoService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $postEnvio['flujo']
                );
                $flujo = $this->agendaService->ajustarFlujoAnalista(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                $flujo = $this->fechaGuardService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                $flujo = $this->resultadoService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                $flujo = $this->convenioDocumentosService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
            } else {
                $resultado = $this->flujoService->obtenerEstado(
                    $seguimientoId,
                    $analistaId
                );

                if (
                    !($resultado['ok'] ?? false) ||
                    !is_array($resultado['flujo'] ?? null)
                ) {
                    return $resultado;
                }

                $flujo = $this->ajustarPasoInicial(
                    $resultado['flujo'],
                    $seguimientoBase
                );
            }

            $flujo = $this->aplicarPisoHistoricoImportado(
                $flujo,
                $seguimientoBase
            );

            /*
             * Si el piso histórico coloca el expediente en Convenio, dejamos
             * que el servicio documental complete la acción vigente. Esto
             * permite continuar el proceso real sin fabricar reuniones ni
             * respuestas anteriores a la migración.
             */
            if ((int)($flujo['paso_actual'] ?? 0) === 13) {
                $flujo = $this->convenioDocumentosService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
            }

            return [
                'ok' => true,
                'flujo' => $flujo
            ];
        } catch (Throwable $error) {
            error_log(
                '[SeguimientoRutaOperativaService] ' . $error->getMessage()
            );

            return [
                'ok' => false,
                'mensaje' => 'No fue posible calcular la ruta del seguimiento.',
                'codigo_http' => 500
            ];
        }
    }

    private function aplicarPisoHistoricoImportado($flujo, $seguimiento)
    {
        if (!is_array($flujo) || !is_array($seguimiento)) {
            return $flujo;
        }

        $etapa = $this->historialImportadoService->resolverEtapa($seguimiento);

        if (!is_array($etapa)) {
            return $flujo;
        }

        $pasoHistorico = (int)($etapa['paso'] ?? 0);
        $pasoActual = (int)($flujo['paso_actual'] ?? 0);

        /*
         * El historial importado funciona como piso, nunca como techo.
         * En cuanto el CRM tenga evidencia real igual o más avanzada, esa
         * evidencia operativa vuelve a ser la fuente autoritativa.
         */
        if ($pasoHistorico <= 0 || $pasoActual >= $pasoHistorico) {
            return $flujo;
        }

        return $this->construirFlujoHistoricoImportado(
            $seguimiento,
            $etapa
        );
    }

    private function construirFlujoHistoricoImportado($seguimiento, $etapa)
    {
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
            ['numero' => 12, 'clave' => 'REUNION_REALIZADA', 'titulo' => 'Reunión y acuerdos'],
            ['numero' => 13, 'clave' => 'CONVENIO', 'titulo' => 'Convenio']
        ];

        $paso = max(1, min(13, (int)($etapa['paso'] ?? 1)));
        $indice = $paso - 1;
        $actual = $pasos[$indice];

        if (trim((string)($etapa['etapa'] ?? '')) !== '') {
            $actual['titulo'] = (string)$etapa['etapa'];
        }

        return [
            'seguimiento_id' => (int)($seguimiento['id'] ?? 0),
            'paso_actual' => $paso,
            'total_pasos' => 13,
            'porcentaje' => (int)round(($paso / 13) * 100),
            'titulo' => (string)($etapa['titulo'] ?? $actual['titulo']),
            'descripcion' => (string)($etapa['descripcion'] ?? ''),
            'faltantes' => [],
            'accion_principal' => null,
            'accion_secundaria' => null,
            'ventana' => [
                'anterior' => $indice > 0 ? $pasos[$indice - 1] : null,
                'actual' => $actual,
                'siguiente' => isset($pasos[$indice + 1])
                    ? $pasos[$indice + 1]
                    : null
            ],
            'contexto' => [
                'estado_seguimiento' =>
                    (string)($seguimiento['estado_seguimiento'] ?? ''),
                'historico_importado' => true,
                'historico_fuente' => 'Excel',
                'historico_evidencia' =>
                    (string)($etapa['evidencia'] ?? ''),
                'es_aliado' => false
            ]
        ];
    }

    private function ajustarPasoInicial($flujo, $seguimiento)
    {
        if (!is_array($flujo) || !is_array($seguimiento)) {
            return $flujo;
        }

        if ((int)($flujo['paso_actual'] ?? 0) !== 2) {
            return $flujo;
        }

        if (
            strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? ''))) !== 'NUEVO'
        ) {
            return $flujo;
        }

        if (
            (int)($seguimiento['datos_verificados'] ?? 0) === 1 ||
            trim((string)($seguimiento['ultima_interaccion_at'] ?? '')) !== ''
        ) {
            return $flujo;
        }

        $creado = trim((string)($seguimiento['created_at'] ?? ''));
        $actualizado = trim((string)($seguimiento['updated_at'] ?? ''));

        if ($creado !== '' && $actualizado !== '') {
            try {
                $fechaCreado = new DateTime($creado);
                $fechaActualizado = new DateTime($actualizado);

                if ($fechaActualizado > $fechaCreado) {
                    return $flujo;
                }
            } catch (Throwable $error) {
                // Conserva el criterio de NUEVO sin actividad.
            }
        }

        $totalPasos = max(13, (int)($flujo['total_pasos'] ?? 13));
        $flujo['paso_actual'] = 1;
        $flujo['total_pasos'] = $totalPasos;
        $flujo['porcentaje'] = (int)round((1 / $totalPasos) * 100);
        $flujo['titulo'] = 'Iniciar investigación';
        $flujo['descripcion'] =
            'El seguimiento acaba de registrarse. Revisa la información disponible y comienza la investigación de datos para avanzar en la ruta.';
        $flujo['faltantes'] = is_array($flujo['faltantes'] ?? null)
            ? $flujo['faltantes']
            : [];
        $flujo['accion_principal'] = [
            'codigo' => 'COMPLETAR_DATOS',
            'etiqueta' => 'Comenzar investigación',
            'icono' => 'bi-search'
        ];
        $flujo['accion_secundaria'] = null;
        $flujo['ventana'] = [
            'anterior' => null,
            'actual' => [
                'numero' => 1,
                'clave' => 'INICIO',
                'titulo' => 'Seguimiento iniciado'
            ],
            'siguiente' => [
                'numero' => 2,
                'clave' => 'INVESTIGACION',
                'titulo' => 'Investigación de datos'
            ]
        ];

        return $flujo;
    }
}
