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

    public function listar($pagina = 1, $porPagina = 25, array $filtros = [])
    {
        $porPagina = max(1, min(100, (int)$porPagina));
        $procedencias = (bool)$this->connection->query("SHOW TABLES LIKE 'formulario_aliado_procedencias'")->num_rows;
        $join = $procedencias ? " LEFT JOIN formulario_aliado_procedencias ap ON ap.registro_id=r.id AND ap.tipo='registro' " : '';
        $where = ' WHERE 1=1'; $params=[]; $types='';
        $buscar = trim((string)($filtros['buscar'] ?? ''));
        if ($buscar !== '') { $where .= ' AND (r.nombre LIKE ? OR r.apellido LIKE ? OR r.movil LIKE ?)'; $params=array_fill(0,3,'%'.$buscar.'%'); $types='sss'; }
        $origen = (string)($filtros['aliado_id'] ?? '');
        if ($procedencias && $origen === 'general') $where .= ' AND ap.id IS NULL';
        elseif ($procedencias && ctype_digit($origen) && (int)$origen>0) { $where .= ' AND ap.aliado_id=?'; $params[]=(int)$origen; $types.='i'; }
        $count = $this->connection->prepare('SELECT COUNT(*) total FROM formulario_registros r'.$join.$where);
        if ($params) $count->bind_param($types,...$params);
        $count->execute(); $totalResultado=$count->get_result();
        if (!$totalResultado) {
            throw new RuntimeException('No se pudo consultar la tabla de registros.');
        }
        $total = (int)($totalResultado->fetch_assoc()['total'] ?? 0);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min((int)$pagina, $paginas));
        $offset = ($pagina - 1) * $porPagina;

        $origenSelect = $procedencias ? ",ap.aliado_id,ap.aliado_nombre,ap.programa_codigo" : ",NULL aliado_id,NULL aliado_nombre,NULL programa_codigo";
        $sql = "SELECT r.id, r.nombre, r.apellido, r.fecha_nacimiento,
                       r.movil, r.movil_secundario, r.tel_recomendado,
                       r.correo, r.perfil_interes, r.lugar_laboras,
                       r.cargo_puesto, r.origen, r.created_at,
                       e.nombre AS estado, m.nombre AS municipio $origenSelect
                FROM formulario_registros r $join
                LEFT JOIN estados e ON e.id = r.estado_id
                LEFT JOIN municipios m ON m.id = r.municipio_id
                $where ORDER BY r.created_at DESC, r.id DESC
                LIMIT ? OFFSET ?";
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de prospectos.');
        }
        $params[]=$porPagina; $params[]=$offset; $types.='ii';
        $stmt->bind_param($types,...$params);
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
            'aliados' => $procedencias ? $this->connection->query("SELECT aliado_id id,MAX(aliado_nombre) nombre FROM formulario_aliado_procedencias WHERE tipo='registro' GROUP BY aliado_id ORDER BY nombre")->fetch_all(MYSQLI_ASSOC) : [],
            'registros' => $registros,
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas
        ];
    }
}
