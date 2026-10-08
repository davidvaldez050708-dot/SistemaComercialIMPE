<?php

require_once __DIR__ . '/../models/EscolaridadAdultaModel.php';

/**
 * CSV transcrito desde una consulta/tabulado oficial.
 * No se puede deducir "media superior concluida" de "algún grado" de ITER.
 */
class EscolaridadAdultaImportService
{
    public function importarCsv(string $ruta, string $archivo): array
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            return ['ok' => false, 'mensaje' => 'No fue posible leer el CSV.'];
        }
        $fp = fopen($ruta, 'rb');
        if (!$fp) {
            return ['ok' => false, 'mensaje' => 'No fue posible abrir el CSV.'];
        }
        try {
            $primera = fgets($fp);
            if ($primera === false) {
                throw new InvalidArgumentException('El archivo está vacío.');
            }
            $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera);
            $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
            $cabecera = str_getcsv(trim($primera), $separador);
            $esperada = ['clave_estado', 'codigo_indicador', 'anio', 'poblacion_base',
                'cantidad_personas', 'fuente', 'referencia_url', 'metodologia'];
            if ($cabecera !== $esperada) {
                throw new InvalidArgumentException('Encabezados incompatibles. Consulta la plantilla CSV de escolaridad adulta.');
            }

            $estados = $this->mapaEstados();
            $filas = [];
            $vistas = [];
            $porEstado = [];
            $linea = 1;
            while (($campos = fgetcsv($fp, 8192, $separador)) !== false) {
                $linea++;
                if ($campos === [null] || (count($campos) === 1 && trim((string)$campos[0]) === '')) {
                    continue;
                }
                if (count($campos) !== count($esperada)) {
                    throw new InvalidArgumentException("Línea $linea: número de columnas incorrecto.");
                }
                $fila = array_combine($esperada, array_map('trim', $campos));
                $clave = $fila['clave_estado'];
                if (!preg_match('/^\d{2}$/', $clave) || !isset($estados[$clave])) {
                    throw new InvalidArgumentException("Línea $linea: clave INEGI del Estado inexistente o inactiva.");
                }
                $codigo = $fila['codigo_indicador'];
                if (!in_array($codigo, [
                    EscolaridadAdultaModel::SIN_SUPERIOR_25,
                    EscolaridadAdultaModel::SIN_MEDIA_CONCLUIDA_18
                ], true)) {
                    throw new InvalidArgumentException("Línea $linea: código de indicador no permitido.");
                }
                $anio = $fila['anio'];
                $base = $fila['poblacion_base'];
                $cantidad = $fila['cantidad_personas'];
                if (!preg_match('/^20\d{2}$/', $anio) || (int)$anio > (int)date('Y') ||
                    !preg_match('/^[1-9]\d*$/', $base) ||
                    !preg_match('/^\d+$/', $cantidad) ||
                    (float)$cantidad > (float)$base ||
                    (float)$base > PHP_INT_MAX || (float)$cantidad > PHP_INT_MAX) {
                    throw new InvalidArgumentException("Línea $linea: año, población o cantidad inválidos.");
                }
                $fuente = $fila['fuente'];
                $url = $fila['referencia_url'];
                $host = strtolower((string)parse_url($url, PHP_URL_HOST));
                if (mb_strlen($fuente) < 5 || mb_strlen($fuente) > 255 ||
                    stripos($fuente, 'INEGI') === false ||
                    !str_starts_with($url, 'https://') ||
                    !($host === 'inegi.org.mx' || str_ends_with($host, '.inegi.org.mx')) ||
                    strlen($url) > 1024 ||
                    mb_strlen($fila['metodologia']) < 35 ||
                    mb_strlen($fila['metodologia']) > 5000) {
                    throw new InvalidArgumentException("Línea $linea: se requiere fuente INEGI, URL oficial HTTPS y metodología detallada.");
                }
                $llave = $clave . ':' . $codigo;
                if (isset($vistas[$llave])) {
                    throw new InvalidArgumentException("Línea $linea: indicador duplicado para el Estado.");
                }
                $vistas[$llave] = true;
                $porEstado[$clave][$codigo] = $anio;
                $fila['estado_id'] = $estados[$clave];
                $filas[] = $fila;
                if (count($filas) > 64) {
                    throw new InvalidArgumentException('El CSV admite un máximo de dos indicadores por cada uno de los 32 Estados.');
                }
            }
            if (!$filas) {
                throw new InvalidArgumentException('El CSV no contiene indicadores.');
            }
            foreach ($porEstado as $clave => $codigos) {
                if (count($codigos) !== 2 || count(array_unique(array_values($codigos))) !== 1) {
                    throw new InvalidArgumentException("Estado $clave: se requieren los dos indicadores del mismo año.");
                }
            }
            $modelo = new EscolaridadAdultaModel();
            if (!$modelo->tablaDisponible()) {
                throw new InvalidArgumentException('Falta aplicar la migración de escolaridad adulta.');
            }
            $modelo->importarLote($filas, basename($archivo));
            return ['ok' => true, 'estados' => count($porEstado), 'indicadores' => count($filas)];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('Importación escolaridad adulta: ' . $e->getMessage());
            return ['ok' => false, 'mensaje' => 'No fue posible procesar o guardar los indicadores. Revisa la migración y el archivo.'];
        } finally {
            fclose($fp);
        }
    }

    private function mapaEstados(): array
    {
        $con = (new Database())->connect();
        $rs = $con->query('SELECT id, clave_inegi FROM estados WHERE estado = 1');
        $salida = [];
        while ($fila = $rs->fetch_assoc()) {
            $clave = str_pad((string)$fila['clave_inegi'], 2, '0', STR_PAD_LEFT);
            if (preg_match('/^\d{2}$/', $clave)) {
                $salida[$clave] = (int)$fila['id'];
            }
        }
        return $salida;
    }
}
