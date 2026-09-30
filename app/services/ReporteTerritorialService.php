<?php

require_once __DIR__ . '/../models/DataTerritorialModel.php';
require_once __DIR__ . '/../models/PoblacionObjetivoEducativaModel.php';

class ReporteTerritorialService
{
    private DataTerritorialModel $modeloTerritorial;
    private PoblacionObjetivoEducativaModel $modeloEducativo;

    public function __construct()
    {
        $this->modeloTerritorial = new DataTerritorialModel();
        $this->modeloEducativo = new PoblacionObjetivoEducativaModel();
    }

    public function construir(int $estadoId): array
    {
        $estado = $this->modeloTerritorial->obtenerEstado($estadoId);

        if (!$estado) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible localizar la información del territorio seleccionado.'
            ];
        }

        $actividadEconomica = $this->modeloTerritorial->obtenerActividadEconomicaEstado($estadoId);
        $comparacionEconomica = $this->modeloTerritorial->obtenerComparacionEconomicaNacional($estadoId);
        $poderAdquisitivo = $this->modeloTerritorial->obtenerPoderAdquisitivoEstado($estadoId);
        $rezagoEducativo = $this->modeloTerritorial->obtenerRezagoEducativoOficialEstado($estadoId);
        $indicadoresEducativos = $this->modeloTerritorial->obtenerIndicadoresEducativos($estadoId);
        $priorizacionMunicipal = $this->modeloTerritorial->obtenerPriorizacionMunicipal($estadoId, 5);
        $secretarias = $this->modeloTerritorial->obtenerSecretarias($estadoId);
        $fuentes = $this->modeloTerritorial->obtenerFuentesPorEstado($estadoId);
        $perfilEducativo = $this->modeloEducativo->tablaDisponible()
            ? $this->modeloEducativo->obtenerIndicadorEstado($estadoId)
            : [
                'disponible' => false,
                'codigo_indicador' => 'SECUNDARIA_COMPLETA_15_MAS',
                'historico' => []
            ];

        $calculos = $this->calcularIndicadores(
            $estado,
            $actividadEconomica,
            $comparacionEconomica,
            $poderAdquisitivo,
            $rezagoEducativo,
            $perfilEducativo,
            $secretarias
        );

        return [
            'ok' => true,
            'estado' => $estado,
            'actividad_economica' => $actividadEconomica,
            'comparacion_economica' => $comparacionEconomica,
            'poder_adquisitivo' => $poderAdquisitivo,
            'rezago_educativo' => $rezagoEducativo,
            'indicadores_educativos' => $indicadoresEducativos,
            'perfil_educativo' => $perfilEducativo,
            'priorizacion_municipal' => $priorizacionMunicipal,
            'secretarias' => $secretarias,
            'fuentes' => $fuentes,
            'calculos' => $calculos,
            'resumen_ejecutivo' => $this->construirResumenEjecutivo(
                $estado,
                $actividadEconomica,
                $poderAdquisitivo,
                $rezagoEducativo,
                $perfilEducativo,
                $priorizacionMunicipal,
                $fuentes,
                $calculos
            ),
            'lecturas' => $this->construirLecturas(
                $actividadEconomica,
                $poderAdquisitivo,
                $rezagoEducativo,
                $perfilEducativo,
                $priorizacionMunicipal,
                $calculos
            ),
            'fecha_generacion' => date('Y-m-d H:i:s')
        ];
    }

    private function calcularIndicadores(
        array $estado,
        array $actividadEconomica,
        array $comparacionEconomica,
        array $poderAdquisitivo,
        array $rezagoEducativo,
        array $perfilEducativo,
        array $secretarias
    ): array {
        $poblacion = max(0, (int)($estado['poblacion'] ?? 0));
        $totalMunicipios = max(
            0,
            (int)(($estado['total_municipios'] ?? null) !== null
                ? $estado['total_municipios']
                : ($estado['municipios_cargados'] ?? 0))
        );
        $totalEstablecimientos = max(
            0,
            (int)($actividadEconomica['total_establecimientos'] ?? 0)
        );
        $sectores = array_values($actividadEconomica['sectores'] ?? []);
        $topCincoSectores = array_slice($sectores, 0, 5);
        $concentracionTopCinco = 0.0;

        foreach ($topCincoSectores as $sector) {
            $concentracionTopCinco += (float)($sector['porcentaje'] ?? 0);
        }

        $totalNacional = (int)($comparacionEconomica['total_establecimientos_nacional'] ?? 0);
        $participacionNacional =
            ($comparacionEconomica['disponible'] ?? false) === true &&
            $totalNacional > 0 &&
            $totalEstablecimientos > 0
                ? round(($totalEstablecimientos / $totalNacional) * 100, 2)
                : null;

        $rezagoDiferencia = ($rezagoEducativo['disponible'] ?? false) === true
            ? ($rezagoEducativo['diferencia_nacional'] ?? null)
            : null;
        $perfilPorcentaje = ($perfilEducativo['disponible'] ?? false) === true
            ? ($perfilEducativo['porcentaje'] ?? null)
            : null;

        return [
            'poblacion_promedio_municipio' => $poblacion > 0 && $totalMunicipios > 0
                ? (int)round($poblacion / $totalMunicipios)
                : null,
            'establecimientos_por_10000_habitantes' => $poblacion > 0 && $totalEstablecimientos > 0
                ? round(($totalEstablecimientos / $poblacion) * 10000, 1)
                : null,
            'concentracion_top_5_sectores' => !empty($topCincoSectores)
                ? round($concentracionTopCinco, 2)
                : null,
            'sector_principal' => !empty($sectores) ? $sectores[0] : null,
            'participacion_establecimientos_nacional' => $participacionNacional,
            'diferencia_ingreso_nacional' => ($poderAdquisitivo['disponible'] ?? false) === true
                ? ($poderAdquisitivo['diferencia_ingreso_nacional'] ?? null)
                : null,
            'diferencia_pobreza_nacional' => ($poderAdquisitivo['disponible'] ?? false) === true
                ? ($poderAdquisitivo['diferencia_pobreza_nacional'] ?? null)
                : null,
            'diferencia_rezago_nacional' => $rezagoDiferencia,
            'perfil_educativo_porcentaje' => $perfilPorcentaje,
            'total_secretarias_activas' => count(array_filter(
                $secretarias,
                static fn(array $secretaria): bool => (int)($secretaria['estado'] ?? 0) === 1
            ))
        ];
    }

    private function construirResumenEjecutivo(
        array $estado,
        array $actividadEconomica,
        array $poderAdquisitivo,
        array $rezagoEducativo,
        array $perfilEducativo,
        array $priorizacionMunicipal,
        array $fuentes,
        array $calculos
    ): array {
        $fuentesDisponibles = 0;
        foreach ($fuentes as $fuente) {
            if (is_array($fuente) && trim((string)($fuente['fuente'] ?? '')) !== '') {
                $fuentesDisponibles++;
            }
        }

        if (
            ($perfilEducativo['disponible'] ?? false) === true &&
            trim((string)($perfilEducativo['fuente'] ?? '')) !== ''
        ) {
            $fuentesDisponibles++;
        }

        $conteos = is_array($priorizacionMunicipal['conteos'] ?? null)
            ? $priorizacionMunicipal['conteos']
            : [];

        return [
            'poblacion' => max(0, (int)($estado['poblacion'] ?? 0)),
            'municipios' => max(
                0,
                (int)($estado['total_municipios'] ?? $estado['municipios_cargados'] ?? 0)
            ),
            'establecimientos' => max(
                0,
                (int)($actividadEconomica['total_establecimientos'] ?? 0)
            ),
            'establecimientos_por_10000_habitantes' =>
                $calculos['establecimientos_por_10000_habitantes'] ?? null,
            'fuentes_disponibles' => $fuentesDisponibles,
            'priorizacion_disponible' => ($priorizacionMunicipal['disponible'] ?? false) === true,
            'municipios_clasificables' => max(
                0,
                (int)($priorizacionMunicipal['total_municipios_clasificables'] ?? 0)
            ),
            'municipios_con_poblacion' => max(
                0,
                (int)($priorizacionMunicipal['total_municipios_con_poblacion'] ?? 0)
            ),
            'prioridad_alta' => max(0, (int)($conteos['ALTA'] ?? 0)),
            'prioridad_media' => max(0, (int)($conteos['MEDIA'] ?? 0)),
            'prioridad_baja' => max(0, (int)($conteos['BAJA'] ?? 0)),
            'sector_principal' => $calculos['sector_principal'] ?? null,
            'concentracion_top_5' => $calculos['concentracion_top_5_sectores'] ?? null,
            'participacion_nacional' =>
                $calculos['participacion_establecimientos_nacional'] ?? null,
            'ingreso_laboral_real_per_capita' =>
                ($poderAdquisitivo['disponible'] ?? false) === true
                    ? ($poderAdquisitivo['ingreso_laboral_real_per_capita'] ?? null)
                    : null,
            'pobreza_laboral' =>
                ($poderAdquisitivo['disponible'] ?? false) === true
                    ? ($poderAdquisitivo['pobreza_laboral'] ?? null)
                    : null,
            'diferencia_ingreso_nacional' =>
                ($poderAdquisitivo['disponible'] ?? false) === true
                    ? ($poderAdquisitivo['diferencia_ingreso_nacional'] ?? null)
                    : null,
            'diferencia_pobreza_nacional' =>
                ($poderAdquisitivo['disponible'] ?? false) === true
                    ? ($poderAdquisitivo['diferencia_pobreza_nacional'] ?? null)
                    : null,
            'rezago_educativo' =>
                ($rezagoEducativo['disponible'] ?? false) === true
                    ? ($rezagoEducativo['porcentaje'] ?? null)
                    : null,
            'diferencia_rezago_nacional' =>
                ($rezagoEducativo['disponible'] ?? false) === true
                    ? ($rezagoEducativo['diferencia_nacional'] ?? null)
                    : null,
            'perfil_educativo_porcentaje' =>
                ($perfilEducativo['disponible'] ?? false) === true
                    ? ($perfilEducativo['porcentaje'] ?? null)
                    : null,
            'perfil_educativo_nombre' => (string)(
                $perfilEducativo['nombre_indicador'] ?? 'Perfil educativo'
            )
        ];
    }

    private function construirLecturas(
        array $actividadEconomica,
        array $poderAdquisitivo,
        array $rezagoEducativo,
        array $perfilEducativo,
        array $priorizacionMunicipal,
        array $calculos
    ): array {
        $lecturas = [];

        $sectorPrincipal = $calculos['sector_principal'] ?? null;
        if (is_array($sectorPrincipal)) {
            $lecturas[] = [
                'titulo' => 'Estructura económica',
                'texto' => sprintf(
                    'El sector con mayor presencia concentra %s%% de los establecimientos registrados en el territorio.',
                    number_format((float)($sectorPrincipal['porcentaje'] ?? 0), 2, '.', ',')
                )
            ];
        }

        if (($poderAdquisitivo['disponible'] ?? false) === true) {
            $diferenciaPobreza = $poderAdquisitivo['diferencia_pobreza_nacional'] ?? null;
            if ($diferenciaPobreza !== null) {
                $lecturas[] = [
                    'titulo' => 'Condición laboral',
                    'texto' => sprintf(
                        'La pobreza laboral se ubica %s puntos porcentuales respecto de la referencia nacional del mismo periodo.',
                        $this->formatearDiferencia((float)$diferenciaPobreza)
                    )
                ];
            }
        }

        if (($rezagoEducativo['disponible'] ?? false) === true) {
            $diferenciaRezago = $rezagoEducativo['diferencia_nacional'] ?? null;
            if ($diferenciaRezago !== null) {
                $lecturas[] = [
                    'titulo' => 'Contexto educativo',
                    'texto' => sprintf(
                        'El rezago educativo se ubica %s puntos porcentuales frente a la referencia nacional del mismo año.',
                        $this->formatearDiferencia((float)$diferenciaRezago)
                    )
                ];
            }
        }

        $conteos = is_array($priorizacionMunicipal['conteos'] ?? null)
            ? $priorizacionMunicipal['conteos']
            : [];
        $alta = (int)($conteos['ALTA'] ?? 0);
        $media = (int)($conteos['MEDIA'] ?? 0);
        $clasificables = (int)($priorizacionMunicipal['total_municipios_clasificables'] ?? 0);

        if (($priorizacionMunicipal['disponible'] ?? false) === true && $clasificables > 0) {
            $lecturas[] = [
                'titulo' => 'Cobertura municipal',
                'texto' => sprintf(
                    'La priorización territorial clasifica %s municipios: %s para ATACAR y %s para OFRECER; el resto queda en OBSERVAR.',
                    number_format($clasificables, 0, '.', ','),
                    number_format($alta, 0, '.', ','),
                    number_format($media, 0, '.', ',')
                )
            ];
        }

        return $lecturas;
    }

    private function formatearDiferencia(float $valor): string
    {
        $signo = $valor > 0 ? '+' : ($valor < 0 ? '−' : '');
        return $signo . number_format(abs($valor), 2, '.', ',');
    }
}
