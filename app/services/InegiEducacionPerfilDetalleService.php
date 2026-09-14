<?php

class InegiEducacionPerfilDetalleService
{
    public function enriquecer(array $resultado, string $claveEstado): array
    {
        if (($resultado['ok'] ?? false) !== true) {
            return $resultado;
        }

        if ($this->tieneMetricasCompletas($resultado)) {
            return $resultado;
        }

        $periodo = (int)($resultado['periodo'] ?? 0);
        if ($periodo !== 2020) {
            return $resultado;
        }

        $detalle = $this->consultarIter2020($claveEstado);
        if (($detalle['ok'] ?? false) !== true) {
            return $resultado;
        }

        $resultado['estado'] = $detalle['estado'];
        $resultado['municipios'] = $detalle['municipios'];
        $resultado['municipios_total'] = count($detalle['municipios']);
        $resultado['detalle_perfil'] = 'ITER 2020';

        return $resultado;
    }

    private function consultarIter2020(string $claveEstado): array
    {
        $claveEstado = str_pad(preg_replace('/\D+/', '', $claveEstado) ?? '', 2, '0', STR_PAD_LEFT);
        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveEstado) || !function_exists('curl_init')) {
            return ['ok' => false];
        }

        $base = 'https://lcidsig.inegi.org.mx/server/rest/services/Hosted/' .
            'conjunto_de_datos_iter_' . $claveEstado . '_2020/FeatureServer/0/query';

        $url = $base . '?' . http_build_query([
            'where' => 'entidad=' . (int)$claveEstado . ' AND loc=0',
            'outFields' => implode(',', [
                'entidad', 'nom_ent', 'mun', 'nom_mun', 'loc',
                'p_15ymas', 'p15sec_co',
                'p_15a17', 'p15a17a',
                'p_18a24', 'p18a24a',
                'p18ym_pb', 'graproes'
            ]),
            'returnGeometry' => 'false',
            'resultRecordCount' => 1000,
            'orderByFields' => 'mun ASC',
            'f' => 'json'
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain;q=0.9,*/*;q=0.5']
        ]);

        $texto = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($texto === false || $error !== '' || $http < 200 || $http >= 300) {
            return ['ok' => false];
        }

        $datos = json_decode((string)$texto, true);
        if (!is_array($datos) || !empty($datos['error'])) {
            return ['ok' => false];
        }

        $estado = null;
        $municipios = [];

        foreach (($datos['features'] ?? []) as $feature) {
            $raw = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : null;
            if ($raw === null) {
                continue;
            }

            $r = $this->normalizarRegistro($raw);
            $entidad = $this->clave($r['ENTIDAD'] ?? '', 2);
            $municipio = $this->clave($r['MUN'] ?? '', 3);
            $localidad = $this->clave($r['LOC'] ?? '', 4);

            if ($entidad !== $claveEstado || $localidad !== '0000') {
                continue;
            }

            $metricas = $this->metricas($r);
            if (!$this->metricasBaseValidas($metricas)) {
                continue;
            }

            if ($municipio === '000') {
                $estado = [
                    'clave' => $entidad,
                    'nombre' => trim((string)($r['NOM_ENT'] ?? '')),
                    'metricas' => $metricas
                ];
                continue;
            }

            $municipios[] = [
                'clave' => $municipio,
                'nombre' => trim((string)($r['NOM_MUN'] ?? '')),
                'metricas' => $metricas
            ];
        }

        if (!is_array($estado)) {
            return ['ok' => false];
        }

        usort($municipios, static function (array $a, array $b): int {
            $aValor = (int)($a['metricas']['fuera_15_24'] ?? 0);
            $bValor = (int)($b['metricas']['fuera_15_24'] ?? 0);
            return $aValor === $bValor
                ? strcmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''))
                : ($bValor <=> $aValor);
        });

        return [
            'ok' => true,
            'estado' => $estado,
            'municipios' => $municipios
        ];
    }

    private function metricas(array $r): array
    {
        $p15Mas = $this->entero($r['P_15YMAS'] ?? null);
        $secundaria = $this->entero($r['P15SEC_CO'] ?? null);
        $p15a17 = $this->entero($r['P_15A17'] ?? null);
        $asiste15a17 = $this->entero($r['P15A17A'] ?? null);
        $p18a24 = $this->entero($r['P_18A24'] ?? null);
        $asiste18a24 = $this->entero($r['P18A24A'] ?? null);
        $posbasica = $this->entero($r['P18YM_PB'] ?? null);
        $gradoPromedio = $this->decimal($r['GRAPROES'] ?? null);

        $fuera15a17 = $this->resta($p15a17, $asiste15a17);
        $fuera18a24 = $this->resta($p18a24, $asiste18a24);
        $p15a24 = $this->suma($p15a17, $p18a24);
        $fuera15a24 = $this->suma($fuera15a17, $fuera18a24);

        return [
            'poblacion_15_mas' => $p15Mas,
            'secundaria_completa' => $secundaria,
            'secundaria_completa_pct' => $this->porcentaje($secundaria, $p15Mas),
            'poblacion_15_17' => $p15a17,
            'asiste_15_17' => $asiste15a17,
            'fuera_15_17' => $fuera15a17,
            'fuera_15_17_pct' => $this->porcentaje($fuera15a17, $p15a17),
            'poblacion_18_24' => $p18a24,
            'asiste_18_24' => $asiste18a24,
            'fuera_18_24' => $fuera18a24,
            'fuera_18_24_pct' => $this->porcentaje($fuera18a24, $p18a24),
            'poblacion_15_24' => $p15a24,
            'fuera_15_24' => $fuera15a24,
            'fuera_15_24_pct' => $this->porcentaje($fuera15a24, $p15a24),
            'educacion_posbasica_18_mas' => $posbasica,
            'grado_promedio_escolaridad' => $gradoPromedio
        ];
    }

    private function tieneMetricasCompletas(array $resultado): bool
    {
        $m = $resultado['estado']['metricas'] ?? null;
        if (!is_array($m)) {
            return false;
        }

        foreach ([
            'fuera_15_17', 'fuera_15_17_pct',
            'fuera_18_24', 'fuera_18_24_pct',
            'fuera_15_24', 'fuera_15_24_pct',
            'educacion_posbasica_18_mas', 'grado_promedio_escolaridad'
        ] as $clave) {
            if (!array_key_exists($clave, $m)) {
                return false;
            }
        }

        return true;
    }

    private function metricasBaseValidas(array $m): bool
    {
        $p = $m['poblacion_15_mas'] ?? null;
        $s = $m['secundaria_completa'] ?? null;
        return is_int($p) && $p > 0 && is_int($s) && $s >= 0 && $s <= $p;
    }

    private function normalizarRegistro(array $registro): array
    {
        $salida = [];
        foreach ($registro as $clave => $valor) {
            $salida[strtoupper((string)$clave)] = $valor;
        }
        return $salida;
    }

    private function entero($valor): ?int
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }
        return max(0, (int)round((float)$valor));
    }

    private function decimal($valor): ?float
    {
        return $valor !== null && $valor !== '' && is_numeric($valor)
            ? round((float)$valor, 2)
            : null;
    }

    private function resta(?int $total, ?int $parte): ?int
    {
        return $total === null || $parte === null ? null : max(0, $total - $parte);
    }

    private function suma(?int $a, ?int $b): ?int
    {
        return $a === null || $b === null ? null : $a + $b;
    }

    private function porcentaje(?int $parte, ?int $total): ?float
    {
        return $parte === null || $total === null || $total <= 0
            ? null
            : round(($parte / $total) * 100, 2);
    }

    private function clave($valor, int $longitud): string
    {
        $valor = preg_replace('/\D+/', '', trim((string)$valor)) ?? '';
        return str_pad($valor === '' ? '0' : $valor, $longitud, '0', STR_PAD_LEFT);
    }
}
