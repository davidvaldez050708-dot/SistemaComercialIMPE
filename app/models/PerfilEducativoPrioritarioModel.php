<?php

require_once __DIR__ . '/../../config/db_connection.php';

class PerfilEducativoPrioritarioModel
{
    private $connection;

    private const GRUPOS = [
        '25-29',
        '30-34',
        '35-39',
        '40-44',
        '45-49'
    ];

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function tablaDisponible(): bool
    {
        try {
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE 'perfil_educativo_prioritario'"
            );

            return $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    public function obtenerPorEstado(int $estadoId, string $claveEstado): array
    {
        $claveEstado = str_pad(
            preg_replace('/\D+/', '', $claveEstado) ?? '',
            2,
            '0',
            STR_PAD_LEFT
        );

        if (!$this->tablaDisponible() || !preg_match('/^\d{2}$/', $claveEstado)) {
            return $this->vacio();
        }

        try {
            $sql = "SELECT
                        clave_estado,
                        clave_municipio,
                        nombre_geografia,
                        grupo_edad,
                        anio,
                        poblacion_total,
                        sin_media_superior_concluida,
                        sin_superior,
                        fuente,
                        referencia_fuente,
                        archivo_origen,
                        fecha_consulta
                    FROM perfil_educativo_prioritario
                    WHERE clave_estado = ?
                      AND grupo_edad IN ('25-29','30-34','35-39','40-44','45-49')
                    ORDER BY clave_municipio ASC,
                             FIELD(grupo_edad,'25-29','30-34','35-39','40-44','45-49')";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('s', $claveEstado);
            $stmt->execute();
            $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (empty($filas)) {
                return $this->vacio();
            }

            $estado = null;
            $municipios = [];
            $meta = [
                'anio' => 0,
                'fuente' => '',
                'referencia_fuente' => '',
                'archivo_origen' => '',
                'fecha_consulta' => ''
            ];

            foreach ($filas as $fila) {
                $nombreGeografia = trim((string)($fila['nombre_geografia'] ?? ''));

                /*
                 * Algunos libros estatales incluyen hojas auxiliares por tamaño
                 * de localidad (p. ej. "000-499 999 habitantes"). Esas filas no
                 * son municipios y nunca deben entrar al ranking municipal.
                 */
                if ($this->esEstratoNoMunicipal($nombreGeografia)) {
                    continue;
                }

                $municipio = str_pad(
                    preg_replace('/\D+/', '', (string)($fila['clave_municipio'] ?? '')) ?? '',
                    3,
                    '0',
                    STR_PAD_LEFT
                );
                $grupo = trim((string)($fila['grupo_edad'] ?? ''));

                if (!in_array($grupo, self::GRUPOS, true)) {
                    continue;
                }

                $destino = $municipio === '000' ? 'estado' : 'municipio';
                $registro = [
                    'grupo_edad' => $grupo,
                    'poblacion_total' => (int)($fila['poblacion_total'] ?? 0),
                    'sin_media_superior_concluida' => $fila['sin_media_superior_concluida'] === null
                        ? null
                        : (int)$fila['sin_media_superior_concluida'],
                    'sin_superior' => $fila['sin_superior'] === null
                        ? null
                        : (int)$fila['sin_superior']
                ];

                if ($destino === 'estado') {
                    if ($estado === null) {
                        $estado = [
                            'clave_estado' => $claveEstado,
                            'clave_municipio' => '000',
                            'nombre' => $nombreGeografia,
                            'grupos' => []
                        ];
                    }
                    $estado['grupos'][] = $registro;
                } else {
                    if (!isset($municipios[$municipio])) {
                        $municipios[$municipio] = [
                            'clave_estado' => $claveEstado,
                            'clave_municipio' => $municipio,
                            'nombre' => $nombreGeografia,
                            'grupos' => []
                        ];
                    }
                    $municipios[$municipio]['grupos'][] = $registro;
                }

                if ((int)($fila['anio'] ?? 0) >= $meta['anio']) {
                    $meta = [
                        'anio' => (int)($fila['anio'] ?? 0),
                        'fuente' => trim((string)($fila['fuente'] ?? '')),
                        'referencia_fuente' => trim((string)($fila['referencia_fuente'] ?? '')),
                        'archivo_origen' => trim((string)($fila['archivo_origen'] ?? '')),
                        'fecha_consulta' => trim((string)($fila['fecha_consulta'] ?? ''))
                    ];
                }
            }

            if ($estado !== null) {
                $estado['metricas'] = $this->agregar($estado['grupos']);
            }

            foreach ($municipios as &$municipio) {
                $municipio['metricas'] = $this->agregar($municipio['grupos']);
            }
            unset($municipio);

            $municipios = array_values($municipios);
            usort($municipios, static function (array $a, array $b): int {
                $aValor = (int)($a['metricas']['sin_media_superior_concluida_25_49'] ?? 0);
                $bValor = (int)($b['metricas']['sin_media_superior_concluida_25_49'] ?? 0);

                if ($aValor === $bValor) {
                    return strcmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
                }

                return $bValor <=> $aValor;
            });

            return [
                'disponible' => $estado !== null &&
                    $estado['metricas']['sin_media_superior_concluida_25_49'] !== null,
                'metodologia' => 'Cruce directo edad × escolaridad; no estimado.',
                'rango_edad' => '25-49',
                'estado' => $estado,
                'municipios' => $municipios,
                'meta' => $meta
            ];
        } catch (Throwable $error) {
            error_log(
                'Perfil educativo prioritario: ' .
                $error->getMessage()
            );

            return $this->vacio();
        }
    }

    private function agregar(array $grupos): array
    {
        $poblacion = 0;
        $sinMedia = 0;
        $sinSuperior = 0;
        $mediaCompleta = true;
        $superiorCompleta = true;
        $desglose = [];

        foreach ($grupos as $grupo) {
            $edad = (string)($grupo['grupo_edad'] ?? '');
            $p = (int)($grupo['poblacion_total'] ?? 0);
            $sm = $grupo['sin_media_superior_concluida'] ?? null;
            $ss = $grupo['sin_superior'] ?? null;

            $poblacion += $p;

            if ($sm === null) {
                $mediaCompleta = false;
            } else {
                $sinMedia += (int)$sm;
            }

            if ($ss === null) {
                $superiorCompleta = false;
            } else {
                $sinSuperior += (int)$ss;
            }

            $sinMediaGrupo = $sm === null ? null : (int)$sm;
            $sinSuperiorGrupo = $ss === null ? null : (int)$ss;
            $mediaSinSuperiorGrupo =
                $sinMediaGrupo !== null &&
                $sinSuperiorGrupo !== null &&
                $sinSuperiorGrupo >= $sinMediaGrupo
                    ? $sinSuperiorGrupo - $sinMediaGrupo
                    : null;
            $conSuperiorGrupo =
                $sinSuperiorGrupo !== null &&
                $p >= $sinSuperiorGrupo
                    ? $p - $sinSuperiorGrupo
                    : null;

            $desglose[$edad] = [
                'poblacion_total' => $p,
                /*
                 * El nombre físico se conserva por compatibilidad histórica,
                 * pero metodológicamente este valor significa personas sin
                 * estudios de educación media superior, no "sin concluirla".
                 */
                'sin_media_superior_concluida' => $sinMediaGrupo,
                'sin_estudios_media_superior' => $sinMediaGrupo,
                'sin_estudios_media_superior_pct' =>
                    $sinMediaGrupo !== null && $p > 0
                        ? round(($sinMediaGrupo / $p) * 100, 2)
                        : null,
                'sin_superior' => $sinSuperiorGrupo,
                'media_superior_sin_superior' => $mediaSinSuperiorGrupo,
                'media_superior_sin_superior_pct' =>
                    $mediaSinSuperiorGrupo !== null && $p > 0
                        ? round(($mediaSinSuperiorGrupo / $p) * 100, 2)
                        : null,
                'con_educacion_superior' => $conSuperiorGrupo,
                'con_educacion_superior_pct' =>
                    $conSuperiorGrupo !== null && $p > 0
                        ? round(($conSuperiorGrupo / $p) * 100, 2)
                        : null
            ];
        }

        $valorMedia = $mediaCompleta ? $sinMedia : null;
        $valorSuperior = $superiorCompleta ? $sinSuperior : null;

        $mediaSinSuperior =
            $valorMedia !== null &&
            $valorSuperior !== null &&
            $valorSuperior >= $valorMedia
                ? $valorSuperior - $valorMedia
                : null;
        $conSuperior =
            $valorSuperior !== null &&
            $poblacion >= $valorSuperior
                ? $poblacion - $valorSuperior
                : null;

        return [
            'poblacion_25_49' => $poblacion,

            // Alias legado: se conserva para no romper consumidores antiguos.
            'sin_media_superior_concluida_25_49' => $valorMedia,
            'sin_media_superior_concluida_pct' =>
                $valorMedia !== null && $poblacion > 0
                    ? round(($valorMedia / $poblacion) * 100, 2)
                    : null,

            // Nombre metodológicamente correcto para el valor calculado.
            'sin_estudios_media_superior_25_49' => $valorMedia,
            'sin_estudios_media_superior_pct' =>
                $valorMedia !== null && $poblacion > 0
                    ? round(($valorMedia / $poblacion) * 100, 2)
                    : null,

            'sin_superior_25_49' => $valorSuperior,
            'sin_superior_pct' =>
                $valorSuperior !== null && $poblacion > 0
                    ? round(($valorSuperior / $poblacion) * 100, 2)
                    : null,

            // Segmentos mutuamente excluyentes dentro del universo 25-49.
            'media_superior_sin_superior_25_49' => $mediaSinSuperior,
            'media_superior_sin_superior_pct' =>
                $mediaSinSuperior !== null && $poblacion > 0
                    ? round(($mediaSinSuperior / $poblacion) * 100, 2)
                    : null,
            'con_educacion_superior_25_49' => $conSuperior,
            'con_educacion_superior_pct' =>
                $conSuperior !== null && $poblacion > 0
                    ? round(($conSuperior / $poblacion) * 100, 2)
                    : null,

            'grupos' => $desglose
        ];
    }

    private function esEstratoNoMunicipal(string $nombre): bool
    {
        $normalizado = mb_strtolower(trim($nombre), 'UTF-8');

        if ($normalizado === '') {
            return false;
        }

        return preg_match(
            '/\bhabitantes\b|tamaño\s+de\s+localidad|rango\s+de\s+poblaci[oó]n/u',
            $normalizado
        ) === 1;
    }

    private function vacio(): array
    {
        return [
            'disponible' => false,
            'metodologia' => 'Cruce directo edad × escolaridad; no estimado.',
            'rango_edad' => '25-49',
            'estado' => null,
            'municipios' => [],
            'meta' => []
        ];
    }
}
