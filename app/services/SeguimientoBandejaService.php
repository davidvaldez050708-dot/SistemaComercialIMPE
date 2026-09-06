<?php

require_once __DIR__ . '/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/AgendaReunionService.php';
require_once __DIR__ . '/ReunionFechaGuardService.php';

class SeguimientoBandejaService
{
    private $postEnvioService;
    private $agendaReunionService;
    private $reunionFechaGuardService;

    public function __construct()
    {
        $this->postEnvioService = new SeguimientoPostEnvioService();
        $this->agendaReunionService = new AgendaReunionService();
        $this->reunionFechaGuardService = new ReunionFechaGuardService();
    }

    public function sincronizarProximasAcciones($seguimientos)
    {
        if (!is_array($seguimientos) || empty($seguimientos)) {
            return is_array($seguimientos) ? $seguimientos : [];
        }

        foreach ($seguimientos as $indice => $seguimiento) {
            $seguimientoId = (int)($seguimiento['id'] ?? 0);
            $analistaId = (int)($seguimiento['analista_id'] ?? 0);

            if ($seguimientoId <= 0 || $analistaId <= 0) {
                continue;
            }

            try {
                $postEnvio = $this->postEnvioService->obtenerFlujoSiAplica(
                    $seguimientoId,
                    $analistaId
                );

                if (!($postEnvio['ok'] ?? false) || !($postEnvio['aplica'] ?? false)) {
                    continue;
                }

                $flujo = $this->agendaReunionService->ajustarFlujoAnalista(
                    $seguimientoId,
                    $analistaId,
                    $postEnvio['flujo'] ?? []
                );

                $flujo = $this->reunionFechaGuardService->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );

                $titulo = trim((string)($flujo['titulo'] ?? ''));

                if ($titulo !== '') {
                    $seguimientos[$indice]['proxima_accion_texto'] = $titulo;
                }
            } catch (Throwable $error) {
                error_log(
                    'No fue posible sincronizar la próxima acción del seguimiento ' .
                    $seguimientoId . ': ' . $error->getMessage()
                );
            }
        }

        return $seguimientos;
    }
}
