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
            'lecturas' => $this->construirLecturas(
                $estado,
                $actividadEconomica,
                $poderAdquisitivo,
                $rezagoEducativo,
                $perfilEducativo,
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

    private function construirLecturas(
        array $estado,
        array $actividadEconomica,
        array $poderAdquisitivo,
        array $rezagoEducativo,
        array $perfilEducativo,
        array $calculos
    ): array {
        $lecturas = [];
        $poblacion = (int)($estado['poblacion'] ?? 0);
        $totalMunicipios = (int)($estado['total_municipios'] ?? 0);

        if ($poblacion > 0 && $totalMunicipios > 0 && $calculos['poblacion_promedio_municipio'] !== null) {
            $lecturas[] = sprintf(
                'El territorio registra %s habitantes distribuidos en %s municipios; el promedio simple es de %s habitantes por municipio.',
                number_format($poblacion, 0, '.', ','),
                number_format($totalMunicipios, 0, '.', ','),
                number_format((int)$calculos['poblacion_promedio_municipio'], 0, '.', ',')
            );
        }

        $totalEstablecimientos = (int)($actividadEconomica['total_establecimientos'] ?? 0);
        if ($totalEstablecimientos > 0 && $calculos['establecimientos_por_10000_habitantes'] !== null) {
            $lecturas[] = sprintf(
                'Se registran %s establecimientos, equivalentes a %s por cada 10 mil habitantes.',
                number_format($totalEstablecimientos, 0, '.', ','),
                number_format((float)$calculos['establecimientos_por_10000_habitantes'], 1, '.', ',')
            );
        }

        $sectorPrincipal = $calculos['sector_principal'] ?? null;
        if (is_array($sectorPrincipal)) {
            $lecturas[] = sprintf(
                'El sector con mayor presencia registrada es “%s”, con %s establecimientos y %s%% del total estatal registrado.',
                (string)($sectorPrincipal['nombre_sector'] ?? 'Sin nombre'),
                number_format((int)($sectorPrincipal['establecimientos'] ?? 0), 0, '.', ','),
                number_format((float)($sectorPrincipal['porcentaje'] ?? 0), 2, '.', ',')
            );
        }

        if (($poderAdquisitivo['disponible'] ?? false) === true) {
            $diferenciaPobreza = $poderAdquisitivo['diferencia_pobreza_nacional'] ?? null;
            if ($diferenciaPobreza !== null) {
                $lecturas[] = sprintf(
                    'La pobreza laboral presenta una diferencia de %s puntos porcentuales respecto de la referencia nacional del mismo periodo.',
                    $this->formatearDiferencia((float)$diferenciaPobreza)
                );
            }
        }

        if (($rezagoEducativo['disponible'] ?? false) === true) {
            $diferenciaRezago = $rezagoEducativo['diferencia_nacional'] ?? null;
            if ($diferenciaRezago !== null) {
                $lecturas[] = sprintf(
                    'El rezago educativo presenta una diferencia de %s puntos porcentuales frente a la referencia nacional del mismo año.',
                    $this->formatearDiferencia((float)$diferenciaRezago)
                );
            }
        }

        if (($perfilEducativo['disponible'] ?? false) === true && isset($perfilEducativo['porcentaje'])) {
            $lecturas[] = sprintf(
                '%s: %s%% de la población base registrada para %s.',
                (string)($perfilEducativo['nombre_indicador'] ?? 'Indicador educativo'),
                number_format((float)$perfilEducativo['porcentaje'], 2, '.', ','),
                (string)($perfilEducativo['anio'] ?? 'el periodo disponible')
            );
        }

        return $lecturas;
    }

    private function formatearDiferencia(float $valor): string
    {
        $signo = $valor > 0 ? '+' : ($valor < 0 ? '−' : '');
        return $signo . number_format(abs($valor), 2, '.', ',');
    }
}
