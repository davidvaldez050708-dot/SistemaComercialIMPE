<?php

require_once __DIR__ . '/../../config/api_keys.php';

class DenueService
{
    private const ACTIVIDAD_TODAS = '0';
    private const ESTRATO_TODOS = '0';

    public function obtenerSectoresEstado(string $claveInegi): array
    {
        $claveInegi = trim($claveInegi);

        if ($claveInegi === '' || !ctype_digit($claveInegi) || strlen($claveInegi) !== 2) {
            return [
                'ok' => false,
                'mensaje' => 'El área geográfica solicitada no es válida.'
            ];
        }

        if (
            !defined('DENUE_TOKEN') ||
            !defined('DENUE_BASE_URL') ||
            trim((string)DENUE_TOKEN) === '' ||
            trim((string)DENUE_BASE_URL) === ''
        ) {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de DENUE no está disponible.'
            ];
        }

        $respuesta = $this->consultarCuantificar($claveInegi);

        if (!$respuesta['ok']) {
            return $respuesta;
        }

        return $this->procesarSectores($respuesta['datos'], $claveInegi);
    }

    private function consultarCuantificar(string $claveInegi): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

        $url = rtrim(DENUE_BASE_URL, '/') .
            '/Cuantificar/' .
            self::ACTIVIDAD_TODAS .
            '/' .
            rawurlencode($claveInegi) .
            '/' .
            self::ESTRATO_TODOS .
            '/' .
            rawurlencode(DENUE_TOKEN);

        $ch = curl_init();

        if ($ch === false) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

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

        $contenido = curl_exec($ch);
        $codigoHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);

        curl_close($ch);

        if ($contenido === false || $errorCurl !== '') {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con DENUE.'
            ];
        }

        if ($codigoHttp !== 200) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE respondió con un código HTTP no válido.'
            ];
        }

        $datos = json_decode($contenido, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($datos)) {
            return [
                'ok' => false,
                'mensaje' => 'La respuesta de DENUE no tiene un formato válido.'
            ];
        }

        return [
            'ok' => true,
            'datos' => $datos
        ];
    }

    private function procesarSectores(array $datos, string $claveInegi): array
    {
        if (empty($datos)) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE no devolvió información económica para el territorio.'
            ];
        }

        $valoresPorCodigo = [];

        foreach ($datos as $registro) {
            if (!is_array($registro)) {
                continue;
            }

            $claveActividad = trim((string)($registro['AE'] ?? ''));
            $claveArea = trim((string)($registro['AG'] ?? ''));

            if (strlen($claveActividad) !== 2 || $claveArea !== $claveInegi) {
                continue;
            }

            $valoresPorCodigo[$claveActividad] = $this->normalizarEntero(
                $registro['Total'] ?? 0
            );
        }

        if (empty($valoresPorCodigo)) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE no devolvió información económica para el territorio.'
            ];
        }

        $establecimientosPorSector = $this->construirSectoresAgrupados($valoresPorCodigo);
        $totalEstablecimientos = array_sum($establecimientosPorSector);

        if ($totalEstablecimientos <= 0) {
            return [
                'ok' => false,
                'mensaje' => 'DENUE no devolvió información económica para el territorio.'
            ];
        }

        $sectores = [];
        $catalogo = $this->obtenerCatalogoSectores();

        foreach ($establecimientosPorSector as $clave => $establecimientos) {
            $sectores[] = [
                'clave_sector' => $clave,
                'nombre_sector' => $catalogo[$clave],
                'establecimientos' => $establecimientos,
                'porcentaje' => round(($establecimientos / $totalEstablecimientos) * 100, 2)
            ];
        }

        usort($sectores, function (array $a, array $b): int {
            $comparacionEstablecimientos = $b['establecimientos'] <=> $a['establecimientos'];

            if ($comparacionEstablecimientos !== 0) {
                return $comparacionEstablecimientos;
            }

            return strcmp($a['nombre_sector'], $b['nombre_sector']);
        });

        return [
            'ok' => true,
            'area_geografica' => $claveInegi,
            'total_establecimientos' => $totalEstablecimientos,
            'sectores' => $sectores
        ];
    }

    private function construirSectoresAgrupados(array $valoresPorCodigo): array
    {
        return [
            '11' => $valoresPorCodigo['11'] ?? 0,
            '21' => $valoresPorCodigo['21'] ?? 0,
            '22' => $valoresPorCodigo['22'] ?? 0,
            '23' => $valoresPorCodigo['23'] ?? 0,
            '31-33' => ($valoresPorCodigo['31'] ?? 0) +
                ($valoresPorCodigo['32'] ?? 0) +
                ($valoresPorCodigo['33'] ?? 0),
            '43' => $valoresPorCodigo['43'] ?? 0,
            '46' => $valoresPorCodigo['46'] ?? 0,
            '48-49' => ($valoresPorCodigo['48'] ?? 0) +
                ($valoresPorCodigo['49'] ?? 0),
            '51' => $valoresPorCodigo['51'] ?? 0,
            '52' => $valoresPorCodigo['52'] ?? 0,
            '53' => $valoresPorCodigo['53'] ?? 0,
            '54' => $valoresPorCodigo['54'] ?? 0,
            '55' => $valoresPorCodigo['55'] ?? 0,
            '56' => $valoresPorCodigo['56'] ?? 0,
            '61' => $valoresPorCodigo['61'] ?? 0,
            '62' => $valoresPorCodigo['62'] ?? 0,
            '71' => $valoresPorCodigo['71'] ?? 0,
            '72' => $valoresPorCodigo['72'] ?? 0,
            '81' => $valoresPorCodigo['81'] ?? 0,
            '93' => $valoresPorCodigo['93'] ?? 0
        ];
    }

    private function obtenerCatalogoSectores(): array
    {
        return [
            '11' => 'Agricultura, cría y explotación de animales, aprovechamiento forestal, pesca y caza',
            '21' => 'Minería',
            '22' => 'Generación, transmisión, distribución y comercialización de energía eléctrica, suministro de agua y gas natural',
            '23' => 'Construcción',
            '31-33' => 'Industrias manufactureras',
            '43' => 'Comercio al por mayor',
            '46' => 'Comercio al por menor',
            '48-49' => 'Transportes, correos y almacenamiento',
            '51' => 'Información en medios masivos',
            '52' => 'Servicios financieros y de seguros',
            '53' => 'Servicios inmobiliarios y de alquiler de bienes muebles e intangibles',
            '54' => 'Servicios profesionales, científicos y técnicos',
            '55' => 'Dirección y administración de grupos empresariales o corporativos',
            '56' => 'Servicios de apoyo a los negocios y manejo de residuos, y servicios de remediación',
            '61' => 'Servicios educativos',
            '62' => 'Servicios de salud y de asistencia social',
            '71' => 'Servicios de esparcimiento, culturales y deportivos, y otros servicios recreativos',
            '72' => 'Servicios de alojamiento temporal y de preparación de alimentos y bebidas',
            '81' => 'Otros servicios excepto actividades gubernamentales',
            '93' => 'Actividades legislativas, gubernamentales, de impartición de justicia y de organismos internacionales y extraterritoriales'
        ];
    }

    private function normalizarEntero($valor): int
    {
        if (is_int($valor)) {
            return max(0, $valor);
        }

        if (is_float($valor)) {
            return max(0, (int)$valor);
        }

        $valor = trim((string)$valor);

        if ($valor === '' || !is_numeric($valor)) {
            return 0;
        }

        return max(0, (int)$valor);
    }
}
