<?php
require_once __DIR__ . '/../../config/db_connection.php';

/** Lecturas exclusivas de los tres widgets de Inicio Marketing. Sin escrituras. */
class MarketingWidgetsModel
{
    private $db;
    public function __construct($db = null) { $this->db = $db; }

    public function obtener(): array
    {
        $datos = ['resumen' => null, 'territorios' => null];
        try {
            $this->db ??= (new Database())->connect();
            // created_at es el registro de publicación usado por el resumen por periodo existente.
            // No hay un estado editorial separado: publicadas = vigentes, inactivas = no vigentes.
            $mes = "created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                    AND created_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)";
            $vigente = 'estado = 1 AND CURDATE() BETWEEN fecha_inicio AND fecha_termino';
            $r = $this->db->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m') AS periodo,
                       CURDATE() AS hoy,
                       COALESCE(SUM(CASE WHEN $mes THEN 1 ELSE 0 END), 0) AS total,
                       COALESCE(SUM(CASE WHEN $mes AND ($vigente) THEN 1 ELSE 0 END), 0) AS publicadas,
                       COALESCE(SUM(CASE WHEN $vigente
                           AND fecha_termino <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                           THEN 1 ELSE 0 END), 0) AS proximas
                    FROM convocatorias");
            $fila = $r->fetch_assoc();
            if (!is_array($fila)) { throw new RuntimeException('Sin resumen'); }
            $datos['resumen'] = [
                'periodo' => (string)$fila['periodo'], 'hoy' => (string)$fila['hoy'],
                'total' => (int)$fila['total'], 'publicadas' => (int)$fila['publicadas'],
                'inactivas' => (int)$fila['total'] - (int)$fila['publicadas'],
                'proximas' => (int)$fila['proximas']
            ];
        } catch (Throwable $e) {
            error_log('Marketing widgets: resumen no disponible; codigo=' . (int)$e->getCode());
        }
        try {
            $this->db ??= (new Database())->connect();
            // COUNT DISTINCT evita relaciones repetidas y respeta cobertura multiestado.
            $r = $this->db->query("SELECT e.id, e.nombre,
                       COUNT(DISTINCT c.id) AS convocatorias_activas
                    FROM convocatoria_estados ce
                    INNER JOIN convocatorias c ON c.id = ce.convocatoria_id
                    INNER JOIN estados e ON e.id = ce.estado_id
                    WHERE c.estado = 1 AND e.estado = 1
                      AND CURDATE() BETWEEN c.fecha_inicio AND c.fecha_termino
                    GROUP BY e.id, e.nombre
                    ORDER BY convocatorias_activas DESC, e.nombre ASC, e.id ASC
                    LIMIT 4");
            $datos['territorios'] = [];
            while ($fila = $r->fetch_assoc()) { $datos['territorios'][] = $fila; }
        } catch (Throwable $e) {
            $datos['territorios'] = null;
            error_log('Marketing widgets: cobertura no disponible; codigo=' . (int)$e->getCode());
        }
        return $datos;
    }
}
