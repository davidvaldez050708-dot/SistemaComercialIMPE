<?php

require_once __DIR__ . '/../../config/db_connection.php';

class PerfilTitulacionExperienciaEnoeModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function tablaDisponible(): bool
    {
        try {
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE 'perfil_titulacion_experiencia_enoe'"
            );

            return $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    public function obtenerPorEstado(string $claveEstado): array
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
                        anio,
                        trimestre,
                        muestra_base,
                        muestra_3_mas,
                        poblacion_base_ponderada,
                        poblacion_3_mas_ponderada,
                        proporcion_3_mas,
                        criterio_escolar,
                        criterio_laboral,
                        fuente,
                        archivo_sdem,
                        archivo_coe1,
                        fecha_importacion
                    FROM perfil_titulacion_experiencia_enoe
                    WHERE clave_estado = ?
                    ORDER BY anio DESC, trimestre DESC
                    LIMIT 1";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('s', $claveEstado);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();

            if (!$fila) {
                return $this->vacio();
            }

            return [
                'disponible' => true,
                'clave_estado' => $claveEstado,
                'anio' => (int)$fila['anio'],
                'trimestre' => (int)$fila['trimestre'],
                'muestra_base' => (int)$fila['muestra_base'],
                'muestra_3_mas' => (int)$fila['muestra_3_mas'],
                'poblacion_base_ponderada' => (int)$fila['poblacion_base_ponderada'],
                'poblacion_3_mas_ponderada' => (int)$fila['poblacion_3_mas_ponderada'],
                'proporcion_3_mas' => $fila['proporcion_3_mas'] === null
                    ? null
                    : (float)$fila['proporcion_3_mas'],
                'criterio_escolar' => (string)$fila['criterio_escolar'],
                'criterio_laboral' => (string)$fila['criterio_laboral'],
                'fuente' => (string)$fila['fuente'],
                'fecha_importacion' => (string)$fila['fecha_importacion']
            ];
        } catch (Throwable $error) {
            error_log(
                'Perfil titulación ENOE: ' . $error->getMessage()
            );

            return $this->vacio();
        }
    }

    public function guardar(array $fila): void
    {
        $sql = "INSERT INTO perfil_titulacion_experiencia_enoe (
                    clave_estado,
                    anio,
                    trimestre,
                    muestra_base,
                    muestra_3_mas,
                    poblacion_base_ponderada,
                    poblacion_3_mas_ponderada,
                    proporcion_3_mas,
                    criterio_escolar,
                    criterio_laboral,
                    fuente,
                    archivo_sdem,
                    archivo_coe1,
                    fecha_importacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    muestra_base = VALUES(muestra_base),
                    muestra_3_mas = VALUES(muestra_3_mas),
                    poblacion_base_ponderada = VALUES(poblacion_base_ponderada),
                    poblacion_3_mas_ponderada = VALUES(poblacion_3_mas_ponderada),
                    proporcion_3_mas = VALUES(proporcion_3_mas),
                    criterio_escolar = VALUES(criterio_escolar),
                    criterio_laboral = VALUES(criterio_laboral),
                    fuente = VALUES(fuente),
                    archivo_sdem = VALUES(archivo_sdem),
                    archivo_coe1 = VALUES(archivo_coe1),
                    fecha_importacion = NOW()";

        $stmt = $this->connection->prepare($sql);

        $clave = (string)$fila['clave_estado'];
        $anio = (int)$fila['anio'];
        $trimestre = (int)$fila['trimestre'];
        $muestraBase = (int)$fila['muestra_base'];
        $muestra3 = (int)$fila['muestra_3_mas'];
        $pobBase = (int)$fila['poblacion_base_ponderada'];
        $pob3 = (int)$fila['poblacion_3_mas_ponderada'];
        $prop = $fila['proporcion_3_mas'] === null
            ? null
            : (float)$fila['proporcion_3_mas'];
        $criterioEscolar = (string)$fila['criterio_escolar'];
        $criterioLaboral = (string)$fila['criterio_laboral'];
        $fuente = (string)$fila['fuente'];
        $sdem = (string)($fila['archivo_sdem'] ?? '');
        $coe1 = (string)($fila['archivo_coe1'] ?? '');

        $stmt->bind_param(
            'siiiiiidsssss',
            $clave,
            $anio,
            $trimestre,
            $muestraBase,
            $muestra3,
            $pobBase,
            $pob3,
            $prop,
            $criterioEscolar,
            $criterioLaboral,
            $fuente,
            $sdem,
            $coe1
        );
        $stmt->execute();
    }

    private function vacio(): array
    {
        return [
            'disponible' => false,
            'metodologia' =>
                'Pendiente de importar ENOE ampliada para estimar antigüedad laboral de 3+ años.'
        ];
    }
}
