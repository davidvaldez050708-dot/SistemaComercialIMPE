<?php
require_once __DIR__ . '/../../config/db_connection.php';

class ProspectoModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function listar($pagina = 1, $porPagina = 25)
    {
        $porPagina = max(1, min(100, (int)$porPagina));
        $totalResultado = $this->connection->query('SELECT COUNT(*) AS total FROM formulario_registros');
        if (!$totalResultado) {
            throw new RuntimeException('No se pudo consultar la tabla de registros.');
        }
        $total = (int)($totalResultado->fetch_assoc()['total'] ?? 0);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min((int)$pagina, $paginas));
        $offset = ($pagina - 1) * $porPagina;

        $sql = "SELECT r.id, r.nombre, r.apellido, r.fecha_nacimiento,
                       r.movil, r.movil_secundario, r.tel_recomendado,
                       r.correo, r.perfil_interes, r.lugar_laboras,
                       r.cargo_puesto, r.origen, r.created_at,
                       e.nombre AS estado, m.nombre AS municipio
                FROM formulario_registros r
                LEFT JOIN estados e ON e.id = r.estado_id
                LEFT JOIN municipios m ON m.id = r.municipio_id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT ? OFFSET ?";
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de prospectos.');
        }
        $stmt->bind_param('ii', $porPagina, $offset);
        if (!$stmt->execute()) {
            throw new RuntimeException('No se pudo obtener el listado de prospectos.');
        }
        $resultado = $stmt->get_result();
        $registros = [];
        while ($fila = $resultado->fetch_assoc()) {
            $registros[] = $fila;
        }
        $stmt->close();

        return [
            'registros' => $registros,
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas
        ];
    }
}
