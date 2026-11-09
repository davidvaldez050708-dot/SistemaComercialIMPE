<?php
require_once __DIR__ . '/../../config/db_connection.php';

/** Lectura exclusiva del panel de Marketing; no inicializa ni migra tablas. */
class MarketingIndicadoresModel
{
    private $db;
    public function __construct($db = null) { $this->db = $db; }
    private function filas(string $sql): array
    {
        $this->db ??= (new Database())->connect();
        $resultado = $this->db->query($sql);
        if (!$resultado) { throw new RuntimeException('MARKETING_INDICADORES_CONSULTA'); }
        $filas = [];
        while ($fila = $resultado->fetch_assoc()) { $filas[] = $fila; }
        return $filas;
    }
    private static function porcentaje(int $parte, int $total): float
    {
        return $total > 0 ? round(100 * $parte / $total, 1) : 0;
    }
    public function obtener(array $resumen, array $cobertura): array
    {
        // Reutilizar las métricas existentes, sin filtros mensuales nuevos.
        $totalEstados = (int)($cobertura['total_estados'] ?? 0);
        $cubiertos = (int)($cobertura['estados_cubiertos'] ?? 0);
        $activas = (int)($resumen['activas'] ?? 0);
        $proximas = (int)($resumen['proximas_finalizar'] ?? 0);
        $datos = [
            'publicaciones' => (int)($resumen['total'] ?? 0),
            'total_estados' => $totalEstados, 'estados_cubiertos' => $cubiertos,
            'cobertura_porcentaje' => self::porcentaje($cubiertos, $totalEstados),
            'proximas' => $proximas, 'activas' => $activas,
            'proximas_porcentaje' => self::porcentaje($proximas, $activas),
            'convenios' => null, 'estados_convenios' => null, 'convenios_porcentaje' => null,
            'aliados' => null, 'aliados_con_convocatoria' => null, 'aliados_porcentaje' => null,
        ];
        try {
            $tablas = array_column($this->filas("SELECT table_name AS nombre_tabla FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name IN ('aliados_manuales',
                'marketing_convenio_convocatorias', 'marketing_aliado_manual_convocatorias',
                'marketing_aliado_convocatorias_origen')"), 'nombre_tabla');
            $tiene = static fn(string $tabla): bool => in_array($tabla, $tablas, true);
            $relaciones = [];
            if ($tiene('marketing_convenio_convocatorias')) {
                $relaciones[] = "SELECT 'institucional' AS origen, seguimiento_id AS origen_id, convocatoria_id FROM marketing_convenio_convocatorias";
            }
            if ($tiene('marketing_aliado_manual_convocatorias')) {
                $relaciones[] = "SELECT 'manual' AS origen, manual_id AS origen_id, convocatoria_id FROM marketing_aliado_manual_convocatorias";
            }
            if ($tiene('marketing_aliado_convocatorias_origen')) {
                $relaciones[] = 'SELECT origen_tipo AS origen, origen_id, convocatoria_id FROM marketing_aliado_convocatorias_origen';
            }
            $relacion = $relaciones ? implode(' UNION ', $relaciones) : null;
            $activa = static fn(string $tipo, string $id): string => $relacion === null ? '0' :
                "EXISTS(SELECT 1 FROM ($relacion) r JOIN convocatorias c ON c.id=r.convocatoria_id
                    WHERE r.origen='$tipo' AND r.origen_id=$id AND c.estado=1)";
            // Convenios: fuente de CrearConvocatoriaModel (asignaciones vigentes,
            // incluidos históricos/migrados). Aliados: formalización de AliadoModel.
            $asignado = 'EXISTS(SELECT 1 FROM aliados_asignaciones a WHERE a.seguimiento_id=s.id AND a.activo=1)';
            $institucionales = $this->filas("SELECT DISTINCT 'institucional' AS origen, s.id, s.estado_id,
                $asignado AS convenio,
                CASE WHEN p.convenio_formalizado_at IS NOT NULL THEN 1 ELSE 0 END AS aliado,
                " . $activa('institucional', 's.id') . " AS convocatoria_activa
                FROM seguimientos_vinculacion s JOIN estados e ON e.id=s.estado_id
                LEFT JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id=s.id
                WHERE s.activo=1 AND ($asignado OR p.convenio_formalizado_at IS NOT NULL)");
            $manuales = $tiene('aliados_manuales') ? $this->filas("SELECT 'manual' AS origen, m.id, m.estado_id,
                1 AS convenio, 1 AS aliado, " . $activa('manual', 'm.id') . " AS convocatoria_activa
                FROM aliados_manuales m JOIN estados e ON e.id=m.estado_id WHERE m.activo=1") : [];
            $convenios = []; $aliados = []; $conActiva = []; $estados = [];
            foreach (array_merge($institucionales, $manuales) as $fila) {
                $clave = $fila['origen'] . ':' . (int)$fila['id'];
                if ((int)$fila['convenio'] === 1) {
                    $convenios[$clave] = true;
                    $estados[(int)$fila['estado_id']] = true;
                }
                if ((int)$fila['aliado'] === 1) {
                    $aliados[$clave] = true;
                    if ((int)$fila['convocatoria_activa'] === 1) { $conActiva[$clave] = true; }
                }
            }
            $datos['convenios'] = count($convenios);
            $datos['estados_convenios'] = count($estados);
            $datos['convenios_porcentaje'] = self::porcentaje(count($estados), $totalEstados);
            $datos['aliados'] = count($aliados);
            $datos['aliados_con_convocatoria'] = $relacion === null ? null : count($conActiva);
            $datos['aliados_porcentaje'] = $relacion === null ? null : self::porcentaje(count($conActiva), count($aliados));
        } catch (Throwable $error) {
            // Sin SQL, contactos ni detalles de conexión en el registro.
            error_log('MARKETING_INDICADORES exception=' . get_class($error) . ' code=' . (int)$error->getCode());
        }
        return $datos;
    }
}
