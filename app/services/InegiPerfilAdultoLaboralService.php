<?php

/**
 * Consulta el perfil adulto/laboral desde ITER 2020 de INEGI.
 *
 * La fuente contiene totales estatales y municipales (LOC=0000), por lo que no
 * es necesario estimar grupos de edad. Los rangos del producto se agregan así:
 * 25-34 = P_25A29 + P_30A34
 * 35-44 = P_35A39 + P_40A44
 * 45-54 = P_45A49 + P_50A54
 *
 * PEA y población ocupada se conservan como contexto laboral general (12+);
 * no se presentan como si correspondieran exclusivamente a las personas 25-54.
 */
class InegiPerfilAdultoLaboralService
{
    private const PERIODO = 2020;
    private const FUENTE = 'INEGI - Censo de Población y Vivienda 2020 (ITER)';

    public function obtenerPorEstado(string $claveEstado): array
    {
        $claveEstado = str_pad(trim($claveEstado), 2, '0', STR_PAD_LEFT);

        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEstado)) {
            return $this->error('La clave del Estado no es válida.');
        }

        $urlBase = 'https://lcidsig.inegi.org.mx/server/rest/services/Hosted/' .
            'conjunto_de_datos_iter_' . $claveEstado . '_2020/FeatureServer/0/query';

        $url = $urlBase . '?' . http_build_query([
            'where' => 'entidad=' . (int)$claveEstado . ' AND loc=0',
            'outFields' => implode(',', [
                'entidad', 'nom_ent', 'mun', 'nom_mun', 'loc',
                'p_25a29', 'p_30a34', 'p_35a39', 'p_40a44',
                'p_45a49', 'p_50a54', 'pea', 'pocupada'
            ]),
            'returnGeometry' => 'false',
            'resultRecordCount' => 1000,
            'orderByFields' => 'mun ASC',
            'f' => 'json'
        ], '', '&', PHP_QUERY_RFC3986);

        $respuesta = $this->getJson($url);

        if (!is_array($respuesta) || !empty($respuesta['error'])) {
            return $this->error('No fue posible consultar el perfil adulto/laboral oficial de INEGI.');
        }

        $estado = null;
        $municipios = [];

        foreach (($respuesta['features'] ?? []) as $feature) {
            $raw = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : null;
            if ($raw === null) {
                continue;
            }

            $registro = $this->normalizarRegistro($raw);
            $entidad = $this->claveNumerica($registro['ENTIDAD'] ?? '', 2);
            $municipio = $this->claveNumerica($registro['MUN'] ?? '', 3);
            $localidad = $this->claveNumerica($registro['LOC'] ?? '', 4);

            if ($entidad !== $claveEstado || $localidad !== '0000') {
                continue;
            }

            $metricas = $this->metricas($registro);
            if (!$this->metricasValidas($metricas)) {
                continue;
            }

            $fila = [
                'clave_estado' => $entidad,
                'clave_municipio' => $municipio,
                'clave_geografica' => $entidad . ($municipio === '000' ? '' : $municipio),
                'nombre' => trim((string)(
                    $municipio === '000'
                        ? ($registro['NOM_ENT'] ?? '')
                        : ($registro['NOM_MUN'] ?? '')
                )),
                'metricas' => $metricas
            ];

            if ($municipio === '000') {
                $estado = $fila;
            } else {
                $municipios[] = $fila;
            }
        }

        if ($estado === null) {
            return $this->error(
                'INEGI respondió, pero no devolvió el total estatal con los grupos adultos requeridos.'
            );
        }

        usort($municipios, static function (array $a, array $b): int {
            $poblacionA = (int)($a['metricas']['poblacion_25_54'] ?? 0);
            $poblacionB = (int)($b['metricas']['poblacion_25_54'] ?? 0);

            return $poblacionA === $poblacionB
                ? strcmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''))
                : $poblacionB <=> $poblacionA;
        });

        return [
            'ok' => true,
            'fuente' => self::FUENTE,
            'periodo' => self::PERIODO,
            'archivo_origen' => 'FeatureServer/0',
            'compatibilidad' => 'PERFIL_ADULTO_LABORAL_VALIDADO',
            'metodologia' => [
                'poblacion_25_34' => 'Suma de P_25A29 y P_30A34.',
                'poblacion_35_44' => 'Suma de P_35A39 y P_40A44.',
                'poblacion_45_54' => 'Suma de P_45A49 y P_50A54.',
                'poblacion_25_54' => 'Suma de los seis grupos quinquenales entre 25 y 54 años.',
                'laboral' =>
                    'PEA y POCUPADA corresponden a la población de 12 años y más del ITER; se conservan como contexto laboral general y no como subconjunto de 25 a 54 años.'
            ],
            'estado' => $estado,
            'municipios' => $municipios,
            'municipios_total' => count($municipios),
            'generado_at' => date('Y-m-d H:i:s')
        ];
    }

    private function metricas(array $registro): array
    {
        $p25a29 = $this->numero($registro['P_25A29'] ?? null);
        $p30a34 = $this->numero($registro['P_30A34'] ?? null);
        $p35a39 = $this->numero($registro['P_35A39'] ?? null);
        $p40a44 = $this->numero($registro['P_40A44'] ?? null);
        $p45a49 = $this->numero($registro['P_45A49'] ?? null);
        $p50a54 = $this->numero($registro['P_50A54'] ?? null);

        $p25a34 = $this->suma([$p25a29, $p30a34]);
        $p35a44 = $this->suma([$p35a39, $p40a44]);
        $p45a54 = $this->suma([$p45a49, $p50a54]);

        return [
            'poblacion_25_34' => $p25a34,
            'poblacion_35_44' => $p35a44,
            'poblacion_45_54' => $p45a54,
            'poblacion_25_54' => $this->suma([$p25a34, $p35a44, $p45a54]),
            'poblacion_economicamente_activa' => $this->numero($registro['PEA'] ?? null),
            'poblacion_ocupada' => $this->numero($registro['POCUPADA'] ?? null)
        ];
    }

    private function metricasValidas(array $metricas): bool
    {
        foreach (['poblacion_25_34', 'poblacion_35_44', 'poblacion_45_54', 'poblacion_25_54'] as $clave) {
            if (!is_int($metricas[$clave] ?? null) || $metricas[$clave] < 0) {
                return false;
            }
        }

        return (int)$metricas['poblacion_25_54'] > 0;
    }

    private function suma(array $valores): ?int
    {
        foreach ($valores as $valor) {
            if ($valor === null) {
                return null;
            }
        }

        return array_sum($valores);
    }

    private function numero($valor): ?int
    {
        $valor = str_replace([',', ' '], '', trim((string)$valor));

        if ($valor === '' || !preg_match('/^-?\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return max(0, (int)$valor);
    }

    private function normalizarRegistro(array $registro): array
    {
        $salida = [];

        foreach ($registro as $clave => $valor) {
            $salida[strtoupper(trim((string)$clave))] = $valor;
        }

        return $salida;
    }

    private function claveNumerica($valor, int $longitud): string
    {
        $valor = preg_replace('/\D+/', '', trim((string)$valor)) ?? '';
        return str_pad($valor === '' ? '0' : $valor, $longitud, '0', STR_PAD_LEFT);
    }

    private function getJson(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host !== 'lcidsig.inegi.org.mx') {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain;q=0.9,*/*;q=0.5']
        ]);

        $texto = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($texto === false || $error !== '' || $http < 200 || $http >= 300) {
            return null;
        }

        $datos = json_decode((string)$texto, true);
        return is_array($datos) ? $datos : null;
    }

    private function error(string $mensaje): array
    {
        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
