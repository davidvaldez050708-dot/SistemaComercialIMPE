<?php

require_once __DIR__ . '/../models/PerfilTitulacionExperienciaEnoeModel.php';

class InegiEnoeTitulacionImportService
{
    private const FUENTE =
        'INEGI - Encuesta Nacional de Ocupación y Empleo (ENOE), cuestionario ampliado';

    private const LLAVE = [
        'CD_A',
        'ENT',
        'CON',
        'UPM',
        'D_SEM',
        'N_PRO_VIV',
        'V_SEL',
        'N_HOG',
        'H_MUD',
        'N_ENT',
        'PER',
        'N_REN'
    ];

    public function importar(
        string $rutaSdem,
        string $rutaCoe1,
        int $anio,
        int $trimestre = 1
    ): array {
        if (
            !is_file($rutaSdem) ||
            !is_readable($rutaSdem) ||
            !is_file($rutaCoe1) ||
            !is_readable($rutaCoe1)
        ) {
            return $this->error('No fue posible leer los archivos CSV de ENOE.');
        }

        if ($anio < 2005 || $anio > ((int)date('Y') + 1) || $trimestre < 1 || $trimestre > 4) {
            return $this->error('Periodo ENOE no válido.');
        }

        try {
            $base = $this->leerSdem($rutaSdem);

            if (empty($base)) {
                return $this->error(
                    'SDEMT no contiene casos 25-49 con bachillerato aprobado para analizar.'
                );
            }

            $resultado = $this->leerCoe1(
                $rutaCoe1,
                $base,
                $anio
            );

            $modelo = new PerfilTitulacionExperienciaEnoeModel();

            if (!$modelo->tablaDisponible()) {
                return $this->error(
                    'Falta aplicar la migración perfil_titulacion_experiencia_enoe.'
                );
            }

            $guardados = 0;

            foreach ($resultado as $estado => $fila) {
                if ((int)$fila['muestra_base'] <= 0) {
                    continue;
                }

                $ponderadaBase = (int)$fila['poblacion_base_ponderada'];
                $ponderada3 = (int)$fila['poblacion_3_mas_ponderada'];
                $proporcion = $ponderadaBase > 0
                    ? round(($ponderada3 / $ponderadaBase) * 100, 4)
                    : null;

                $modelo->guardar([
                    'clave_estado' => $estado,
                    'anio' => $anio,
                    'trimestre' => $trimestre,
                    'muestra_base' => (int)$fila['muestra_base'],
                    'muestra_3_mas' => (int)$fila['muestra_3_mas'],
                    'poblacion_base_ponderada' => $ponderadaBase,
                    'poblacion_3_mas_ponderada' => $ponderada3,
                    'proporcion_3_mas' => $proporcion,
                    'criterio_escolar' =>
                        '25-49 años con preparatoria/bachillerato y 3 o más años aprobados; sin nivel profesional o superior.',
                    'criterio_laboral' =>
                        'Antigüedad de 3 o más años en el empleo o negocio actual, aproximada con P3R_ANIO.',
                    'fuente' => self::FUENTE,
                    'archivo_sdem' => basename($rutaSdem),
                    'archivo_coe1' => basename($rutaCoe1)
                ]);

                $guardados++;
            }

            return [
                'ok' => true,
                'mensaje' =>
                    'ENOE procesada. La estimación mide antigüedad en el empleo actual; no equivale a elegibilidad individual.',
                'estados_importados' => $guardados,
                'anio' => $anio,
                'trimestre' => $trimestre
            ];
        } catch (Throwable $error) {
            error_log('Importación ENOE titulación: ' . $error->getMessage());

            return $this->error(
                'No fue posible procesar los microdatos ENOE.'
            );
        }
    }

    private function leerSdem(string $ruta): array
    {
        [$handle, $headers] = $this->abrirCsv($ruta);
        $this->validarColumnas(
            $headers,
            array_merge(
                self::LLAVE,
                ['EDA', 'CS_P13_1', 'CS_P13_2', 'ENT', 'FAC_TRI']
            ),
            'SDEMT'
        );

        $indice = array_flip($headers);
        $base = [];

        while (($row = fgetcsv($handle)) !== false) {
            $edad = $this->entero($row[$indice['EDA']] ?? null);
            $nivel = str_pad(
                (string)$this->entero($row[$indice['CS_P13_1']] ?? null),
                2,
                '0',
                STR_PAD_LEFT
            );
            $grados = $this->entero($row[$indice['CS_P13_2']] ?? null);
            $entidad = str_pad(
                (string)$this->entero($row[$indice['ENT']] ?? null),
                2,
                '0',
                STR_PAD_LEFT
            );
            $factor = $this->entero($row[$indice['FAC_TRI']] ?? null);

            if (
                $edad === null ||
                $edad < 25 ||
                $edad > 49 ||
                $nivel !== '04' ||
                $grados === null ||
                $grados < 3 ||
                !preg_match('/^\d{2}$/', $entidad) ||
                $factor === null ||
                $factor <= 0
            ) {
                continue;
            }

            $llave = $this->llaveDesdeFila($row, $indice);

            if ($llave === '') {
                continue;
            }

            $base[$llave] = [
                'entidad' => $entidad,
                'factor' => $factor
            ];
        }

        fclose($handle);
        return $base;
    }

    private function leerCoe1(
        string $ruta,
        array $base,
        int $anioReferencia
    ): array {
        [$handle, $headers] = $this->abrirCsv($ruta);
        $this->validarColumnas(
            $headers,
            array_merge(self::LLAVE, ['P3R_ANIO']),
            'COE1T'
        );

        $indice = array_flip($headers);
        $salida = [];

        foreach ($base as $caso) {
            $estado = $caso['entidad'];
            if (!isset($salida[$estado])) {
                $salida[$estado] = [
                    'muestra_base' => 0,
                    'muestra_3_mas' => 0,
                    'poblacion_base_ponderada' => 0,
                    'poblacion_3_mas_ponderada' => 0
                ];
            }

            $salida[$estado]['muestra_base']++;
            $salida[$estado]['poblacion_base_ponderada'] += (int)$caso['factor'];
        }

        while (($row = fgetcsv($handle)) !== false) {
            $llave = $this->llaveDesdeFila($row, $indice);

            if ($llave === '' || !isset($base[$llave])) {
                continue;
            }

            $inicio = $this->entero($row[$indice['P3R_ANIO']] ?? null);

            if (
                $inicio === null ||
                $inicio === 9999 ||
                $inicio < 1900 ||
                $inicio > $anioReferencia
            ) {
                continue;
            }

            $antiguedad = $anioReferencia - $inicio;

            if ($antiguedad < 3) {
                continue;
            }

            $estado = $base[$llave]['entidad'];
            $factor = (int)$base[$llave]['factor'];

            $salida[$estado]['muestra_3_mas']++;
            $salida[$estado]['poblacion_3_mas_ponderada'] += $factor;
        }

        fclose($handle);
        return $salida;
    }

    private function abrirCsv(string $ruta): array
    {
        $handle = fopen($ruta, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No fue posible abrir CSV.');
        }

        $headers = fgetcsv($handle);

        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            throw new RuntimeException('CSV sin encabezados.');
        }

        $headers = array_map(static function ($header): string {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header);
            return strtoupper(trim($header));
        }, $headers);

        return [$handle, $headers];
    }

    private function validarColumnas(
        array $headers,
        array $requeridas,
        string $archivo
    ): void {
        $faltantes = array_values(array_diff($requeridas, $headers));

        if (!empty($faltantes)) {
            throw new RuntimeException(
                $archivo . ' no contiene columnas requeridas: ' .
                implode(', ', $faltantes)
            );
        }
    }

    private function llaveDesdeFila(array $row, array $indice): string
    {
        $partes = [];

        foreach (self::LLAVE as $campo) {
            if (!isset($indice[$campo])) {
                return '';
            }

            $valor = trim((string)($row[$indice[$campo]] ?? ''));

            if ($valor === '') {
                return '';
            }

            $partes[] = $valor;
        }

        return implode('|', $partes);
    }

    private function entero($valor): ?int
    {
        $valor = trim((string)$valor);

        if ($valor === '' || !preg_match('/^-?\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        return (int)$valor;
    }

    private function error(string $mensaje): array
    {
        return ['ok' => false, 'mensaje' => $mensaje];
    }
}
