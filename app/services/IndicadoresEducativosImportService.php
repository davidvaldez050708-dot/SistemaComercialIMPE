<?php

/**
 * Importador de indicadores educativos oficiales orientados a identificar
 * población potencial para continuidad educativa.
 *
 * Formato esperado del XLSX/CSV normalizado antes de este servicio:
 * clave_geografica, nombre, anio, codigo_indicador, nombre_indicador,
 * grupo_edad, cantidad_personas, porcentaje
 *
 * Este servicio no reemplaza RezagoEducativoImportService: ambos conceptos
 * se conservan separados para no alterar la medición oficial de rezago.
 */
class IndicadoresEducativosImportService
{
    public const CODIGO_SECUNDARIA_MAXIMO = 'SECUNDARIA_MAXIMO_15_MAS';

    public static function nombreIndicadorPrincipal(): string
    {
        return 'Población de 15 años y más con secundaria como máximo nivel de escolaridad';
    }

    public static function grupoEdadPrincipal(): string
    {
        return '15 años y más';
    }

    /**
     * Normaliza datos ya extraídos de una fuente oficial.
     * Se deja desacoplado del formato específico de INEGI para poder conectar
     * posteriormente API, XLSX o tabulado sin cambiar modelo ni vista.
     */
    public function normalizarRegistros(array $registros): array
    {
        $normalizados = [];

        foreach ($registros as $registro) {
            if (!is_array($registro)) {
                continue;
            }

            $clave = str_pad(trim((string)($registro['clave_geografica'] ?? '')), 2, '0', STR_PAD_LEFT);
            $anio = (int)($registro['anio'] ?? 0);
            $cantidad = $registro['cantidad_personas'] ?? null;
            $porcentaje = $registro['porcentaje'] ?? null;

            if (!preg_match('/^\d{2}$/', $clave) || $anio < 2000 || $anio > 2100) {
                continue;
            }

            if ($cantidad !== null && (!is_numeric($cantidad) || (int)$cantidad < 0)) {
                continue;
            }

            if ($porcentaje !== null && (!is_numeric($porcentaje) || (float)$porcentaje < 0 || (float)$porcentaje > 100)) {
                continue;
            }

            if ($cantidad === null && $porcentaje === null) {
                continue;
            }

            $normalizados[] = [
                'clave_geografica' => $clave,
                'anio' => $anio,
                'codigo_indicador' => trim((string)($registro['codigo_indicador'] ?? self::CODIGO_SECUNDARIA_MAXIMO)),
                'nombre_indicador' => trim((string)($registro['nombre_indicador'] ?? self::nombreIndicadorPrincipal())),
                'grupo_edad' => trim((string)($registro['grupo_edad'] ?? self::grupoEdadPrincipal())),
                'cantidad_personas' => $cantidad === null ? null : (int)$cantidad,
                'porcentaje' => $porcentaje === null ? null : round((float)$porcentaje, 2)
            ];
        }

        return $normalizados;
    }
}
