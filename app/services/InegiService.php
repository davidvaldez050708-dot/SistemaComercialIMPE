<?php

require_once __DIR__ . '/../../config/api_keys.php';

class InegiService
{
    public function obtenerIndicadorReciente(
        string $indicador,
        string $areaGeografica
    ): array {
        $indicador = trim($indicador);
        $areaGeografica = trim($areaGeografica);

        if ($indicador === '' || !ctype_digit($indicador)) {
            return [
                'ok' => false,
                'mensaje' => 'El indicador solicitado no es válido.'
            ];
        }

        if ($areaGeografica === '' || !ctype_digit($areaGeografica)) {
            return [
                'ok' => false,
                'mensaje' => 'El área geográfica solicitada no es válida.'
            ];
        }

        if (
            !defined('INEGI_INDICADORES_TOKEN') ||
            !defined('INEGI_INDICADORES_BASE_URL') ||
            trim((string)INEGI_INDICADORES_TOKEN) === '' ||
            trim((string)INEGI_INDICADORES_BASE_URL) === ''
        ) {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de INEGI no está disponible.'
            ];
        }

        $url = rtrim(INEGI_INDICADORES_BASE_URL, '/') .
            '/INDICATOR/' .
            rawurlencode($indicador) .
            '/es/' .
            rawurlencode($areaGeografica) .
            '/true/BISE/2.0/' .
            rawurlencode(INEGI_INDICADORES_TOKEN) .
            '?type=json';

        $ch = curl_init();

        if ($ch === false) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con INEGI.'
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);

        $respuesta = curl_exec($ch);
        $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);

        curl_close($ch);

        if ($respuesta === false || $errorCurl !== '') {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con INEGI.'
            ];
        }

        if ($codigoHttp !== 200) {
            return [
                'ok' => false,
                'mensaje' => 'INEGI respondió con un código HTTP no válido.'
            ];
        }

        $datos = json_decode($respuesta, true);

        if (!is_array($datos)) {
            return [
                'ok' => false,
                'mensaje' => 'La respuesta de INEGI no tiene un formato válido.'
            ];
        }

        if (empty($datos['Series'][0]) || !is_array($datos['Series'][0])) {
            return [
                'ok' => false,
                'mensaje' => 'INEGI no devolvió información para el indicador solicitado.'
            ];
        }

        $serie = $datos['Series'][0];

        if (
            empty($serie['OBSERVATIONS'][0]) ||
            !is_array($serie['OBSERVATIONS'][0])
        ) {
            return [
                'ok' => false,
                'mensaje' => 'INEGI no devolvió observaciones para el indicador solicitado.'
            ];
        }

        $observacion = $serie['OBSERVATIONS'][0];
        $valor = $this->normalizarValorNumerico(
            (string)($observacion['OBS_VALUE'] ?? '')
        );

        if ($valor === null) {
            return [
                'ok' => false,
                'mensaje' => 'La respuesta de INEGI no tiene un formato válido.'
            ];
        }

        return [
            'ok' => true,
            'indicador' => (string)($serie['INDICADOR'] ?? $indicador),
            'area_geografica' => (string)($observacion['COBER_GEO'] ?? $areaGeografica),
            'valor' => $valor,
            'periodo' => (string)($observacion['TIME_PERIOD'] ?? ''),
            'ultima_actualizacion_fuente' => (string)($serie['LASTUPDATE'] ?? '')
        ];
    }

    public function obtenerPoblacionEstado(string $claveInegi): array
    {
        return $this->obtenerIndicadorReciente(
            '1002000001',
            $claveInegi
        );
    }

    private function normalizarValorNumerico(string $valor)
    {
        $valor = trim($valor);

        if ($valor === '' || !preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
            return null;
        }

        if (strpos($valor, '.') === false) {
            return (int)$valor;
        }

        [$entero, $decimal] = explode('.', $valor, 2);

        if (trim($decimal, '0') === '') {
            return (int)$entero;
        }

        return (float)$valor;
    }
}
