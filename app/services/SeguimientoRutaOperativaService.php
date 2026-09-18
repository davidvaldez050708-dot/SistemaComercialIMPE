<?php

require_once __DIR__ . '/SeguimientoFlujoService.php';
require_once __DIR__ . '/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/SeguimientoCorreoService.php';
require_once __DIR__ . '/AgendaReunionService.php';
require_once __DIR__ . '/ReunionFechaGuardService.php';
require_once __DIR__ . '/ReunionResultadoService.php';

class SeguimientoRutaOperativaService
{
    private $flujoService;
    private $postEnvioService;
    private $correoService;
    private $agendaService;
    private $fechaGuardService;
    private $resultadoService;

    public function __construct()
    {
        $this->flujoService = new SeguimientoFlujoService();
        $this->postEnvioService = new SeguimientoPostEnvioService();
        $this->correoService = new SeguimientoCorreoService();
        $this->agendaService = new AgendaReunionService();
        $this->fechaGuardService = new ReunionFechaGuardService();
        $this->resultadoService = new ReunionResultadoService();
    }

    public function resolver($seguimientoId, $analistaId, $seguimientoBase = [])
    {
        $seguimientoId = (int)$seguimientoId;
        $analistaId = (int)$analistaId;

        if ($seguimientoId <= 0 || $analistaId <= 0) {
            return [
                'ok' => false,
                'mensaje' => 'El seguimiento no tiene un Analista responsable válido.',
                'codigo_http' => 422
            ];
        }

        try {
            $postEnvio = $this->postEnvioService->obtenerFlujoSiAplica(
                $seguimientoId,
                $analistaId
            );

            if (($postEnvio['ok'] ?? false) && ($postEnvio['aplica'] ?? false)) {
                $flujo = $this->agendaService->ajustarFlujoAnalista(
                    $seguimientoId,
                    $analistaId,
                    $postEnvio['flujo']
                );
                $flujo = $this->correoService->ajustarFlujo(
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

                return [
                    'ok' => true,
                    'flujo' => $flujo
                ];
            }

            $resultado = $this->flujoService->obtenerEstado(
                $seguimientoId,
                $analistaId
            );

            if (
                ($resultado['ok'] ?? false) &&
                is_array($resultado['flujo'] ?? null)
            ) {
                $resultado['flujo'] = $this->ajustarPasoInicial(
                    $resultado['flujo'],
                    is_array($seguimientoBase) ? $seguimientoBase : []
                );
            }

            return $resultado;
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
