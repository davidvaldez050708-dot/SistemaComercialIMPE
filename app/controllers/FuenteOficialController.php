<?php

class FuenteOficialController
{
    private const URL_PM = 'https://www.inegi.org.mx/desarrollosocial/pm/';
    private const URL_PM_TABULADOS = 'https://www.inegi.org.mx/desarrollosocial/pm/#tabulados';
    private const URL_PM_ARCHIVO_BASE = 'https://www.inegi.org.mx/contenidos/desarrollosocial/pm/tabulados/pm_ct_%d.xlsx';

    public function rezagoEducativo(): void
    {
        if (
            function_exists('tienePermiso') &&
            !tienePermiso('data_territorial.actualizar_oficial')
        ) {
            http_response_code(403);
            echo 'No tienes permiso para consultar esta fuente oficial.';
            return;
        }

        $pagina = $this->consultarPagina(self::URL_PM);
        $urlArchivo = $pagina !== null
            ? $this->resolverArchivoRezago($pagina)
            : null;

        $destino = $urlArchivo ?: self::URL_PM_TABULADOS;

        header('Location: ' . $destino, true, 302);
        exit;
    }

    private function resolverArchivoRezago(string $html): ?string
    {
        $htmlDecodificado = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $patronesArchivo = [
            '#https?://[^\s"\'<>]+/contenidos/desarrollosocial/pm/tabulados/pm_ct_(20\d{2})\.xlsx#i',
            '#/contenidos/desarrollosocial/pm/tabulados/pm_ct_(20\d{2})\.xlsx#i'
        ];

        $candidatos = [];

        foreach ($patronesArchivo as $patron) {
            if (!preg_match_all($patron, $htmlDecodificado, $coincidencias, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($coincidencias as $coincidencia) {
                $anio = (int)($coincidencia[1] ?? 0);

                if ($anio < 2000 || $anio > 2100) {
                    continue;
                }

                $candidatos[$anio] = sprintf(self::URL_PM_ARCHIVO_BASE, $anio);
            }
        }

        if (!empty($candidatos)) {
            krsort($candidatos, SORT_NUMERIC);
            return reset($candidatos) ?: null;
        }

        $patronesPeriodo = [
            '/resultados\s+de\s+la\s+medici[oó]n\s+de\s+la\s+pobreza\s+multidimensional\s+correspondiente\s+a\s+(20\d{2})/iu',
            '/pobreza\s+multidimensional\s*\(?(?:pm)?\)?\s*(20\d{2})/iu'
        ];

        foreach ($patronesPeriodo as $patron) {
            if (!preg_match($patron, $htmlDecodificado, $coincidencia)) {
                continue;
            }

            $anio = (int)($coincidencia[1] ?? 0);

            if ($anio >= 2000 && $anio <= 2100) {
                return sprintf(self::URL_PM_ARCHIVO_BASE, $anio);
            }
        }

        return null;
    }

    private function consultarPagina(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);

            if ($curl !== false) {
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_USERAGENT => 'SistemaComercialIMPE/1.0 (+consulta de fuente oficial INEGI)',
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml'
                    ]
                ]);

                $contenido = curl_exec($curl);
                $codigo = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);

                if (is_string($contenido) && $contenido !== '' && $codigo >= 200 && $codigo < 400) {
                    return $contenido;
                }
            }
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $contexto = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "User-Agent: SistemaComercialIMPE/1.0\r\nAccept: text/html,application/xhtml+xml\r\n"
            ]
        ]);

        $contenido = @file_get_contents($url, false, $contexto);

        return is_string($contenido) && $contenido !== ''
            ? $contenido
            : null;
    }
}
