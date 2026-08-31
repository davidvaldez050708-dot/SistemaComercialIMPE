<?php

class InegiGeoService
{
    private string $baseUrl = 'https://gaia.inegi.org.mx/wscatgeo/v2';

    public function obtenerMunicipiosEstado(string $claveEstado): array
    {
        $claveEstado = trim($claveEstado);

        if (!preg_match('/^\d{2}$/', $claveEstado)) {
            return [
                'ok' => false,
                'mensaje' => 'La clave del Estado no es válida.',
                'municipios' => []
            ];
        }

        $url = $this->baseUrl . '/mgem/' . rawurlencode($claveEstado);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);

        $respuesta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);

        curl_close($ch);

        if ($respuesta === false || $errorCurl !== '') {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible consultar la información municipal de INEGI.',
                'municipios' => []
            ];
        }

        if ($codigoHttp !== 200) {
            return [
                'ok' => false,
                'mensaje' => 'INEGI no devolvió una respuesta válida.',
                'municipios' => []
            ];
        }

        $datos = json_decode($respuesta, true);

        if (!is_array($datos)) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible interpretar la respuesta de INEGI.',
                'municipios' => []
            ];
        }

        /*
         * El servicio puede devolver directamente el arreglo de registros
         * o envolverlo dentro de una propiedad.
         */
        if (isset($datos['datos']) && is_array($datos['datos'])) {
            $datos = $datos['datos'];
        }

        if (isset($datos['municipios']) && is_array($datos['municipios'])) {
            $datos = $datos['municipios'];
        }

        $municipios = [];

        foreach ($datos as $registro) {
            if (!is_array($registro)) {
                continue;
            }

            $claveEntidad = str_pad(
                trim((string) ($registro['cve_ent'] ?? '')),
                2,
                '0',
                STR_PAD_LEFT
            );

            $claveMunicipio = str_pad(
                trim((string) ($registro['cve_mun'] ?? '')),
                3,
                '0',
                STR_PAD_LEFT
            );

            $claveGeo = trim((string) ($registro['cvegeo'] ?? ''));
            $nombre = trim((string) ($registro['nomgeo'] ?? ''));
            $poblacionRaw = trim((string) ($registro['pob_total'] ?? ''));

            if (
                $claveEntidad !== $claveEstado ||
                !preg_match('/^\d{3}$/', $claveMunicipio) ||
                $nombre === ''
            ) {
                continue;
            }

            if ($claveGeo === '') {
                $claveGeo = $claveEntidad . $claveMunicipio;
            }

            $poblacion = null;

            if ($poblacionRaw !== '' && is_numeric($poblacionRaw)) {
                $poblacion = (int) $poblacionRaw;
            }

            $municipios[] = [
                'clave_estado' => $claveEntidad,
                'clave_municipio' => $claveMunicipio,
                'clave_geoestadistica' => $claveGeo,
                'nombre' => $nombre,
                'poblacion' => $poblacion
            ];
        }

        usort($municipios, function ($a, $b) {
            return strcmp(
                $a['clave_municipio'],
                $b['clave_municipio']
            );
        });

        if (empty($municipios)) {
            return [
                'ok' => false,
                'mensaje' => 'INEGI no devolvió municipios válidos para este Estado.',
                'municipios' => []
            ];
        }

        return [
            'ok' => true,
            'clave_estado' => $claveEstado,
            'total_municipios' => count($municipios),
            'municipios' => $municipios
        ];
    }
}