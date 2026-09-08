<?php

/**
 * Normalizador genérico para futuros indicadores educativos importados.
 *
 * El flujo automático actual de población objetivo usa InegiEducacionService.
 * Este servicio se conserva preparado para futuros archivos oficiales XLSX/CSV
 * sin mezclar dichos indicadores con el rezago educativo de Pobreza
 * Multidimensional.
 */
class IndicadoresEducativosImportService
{
    public const CODIGO_SECUNDARIA_COMPLETA = 'SECUNDARIA_COMPLETA_15_MAS';

    public static function nombreIndicadorPrincipal(): string
    {
        return 'Población de 15 años y más con secundaria completa';
    }

    public static function grupoEdadPrincipal(): string
    {
        return '15 años y más';
    }

    public function normalizarRegistros(array $registros): array
    {
        $normalizados = [];

        foreach ($registros as $registro) {
            if (!is_array($registro)) {
                continue;
            }

            $clave = str_pad(
                trim((string)($registro['clave_geografica'] ?? '')),
                2,
                '0',
                STR_PAD_LEFT
            );
            $anio = (int)($registro['anio'] ?? 0);
            $cantidad = $registro['cantidad_personas'] ?? null;
            $poblacionBase = $registro['poblacion_base'] ?? null;
            $porcentaje = $registro['porcentaje'] ?? null;

            if (!preg_match('/^\d{2}$/', $clave) || $anio < 2000 || $anio > 2100) {
                continue;
            }

            if (
                $cantidad !== null &&
                (!is_numeric($cantidad) || (int)$cantidad < 0)
            ) {
                continue;
            }

            if (
                $poblacionBase !== null &&
                (!is_numeric($poblacionBase) || (int)$poblacionBase <= 0)
            ) {
                continue;
            }

            if (
                $porcentaje !== null &&
                (!is_numeric($porcentaje) || (float)$porcentaje < 0 || (float)$porcentaje > 100)
            ) {
                continue;
            }

            if ($cantidad === null && $porcentaje === null) {
                continue;
            }

            $normalizados[] = [
                'clave_geografica' => $clave,
                'anio' => $anio,
                'codigo_indicador' => trim(
                    (string)($registro['codigo_indicador'] ?? self::CODIGO_SECUNDARIA_COMPLETA)
                ),
                'nombre_indicador' => trim(
                    (string)($registro['nombre_indicador'] ?? self::nombreIndicadorPrincipal())
                ),
                'grupo_edad' => trim(
                    (string)($registro['grupo_edad'] ?? self::grupoEdadPrincipal())
                ),
                'cantidad_personas' => $cantidad === null ? null : (int)$cantidad,
                'poblacion_base' => $poblacionBase === null ? null : (int)$poblacionBase,
                'porcentaje' => $porcentaje === null
                    ? null
                    : round((float)$porcentaje, 2)
            ];
        }

        return $normalizados;
    }
}
