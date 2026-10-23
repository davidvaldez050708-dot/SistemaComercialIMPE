<?php
require_once __DIR__ . '/../../config/db_connection.php';

class ProspectoModel
{
    private $connection;
    private $procedenciasDisponibles;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    private function tieneProcedencias(): bool
    {
        if ($this->procedenciasDisponibles !== null) return $this->procedenciasDisponibles;
        if (!$this->connection->query("SHOW TABLES LIKE 'formulario_aliado_procedencias'")->num_rows) return $this->procedenciasDisponibles = false;
        $columnas = array_column($this->connection->query('SHOW COLUMNS FROM formulario_aliado_procedencias')->fetch_all(MYSQLI_ASSOC), 'Field');
        return $this->procedenciasDisponibles = !array_diff(['id','tipo','registro_id','aliado_id','aliado_nombre','programa_codigo'], $columnas);
    }

    public function resumen(): array
    {
        $procedencias = $this->tieneProcedencias();
        $origen = $procedencias ? ",COALESCE(SUM(EXISTS(SELECT 1 FROM formulario_aliado_procedencias ap WHERE ap.registro_id=r.id AND ap.tipo='registro')),0) aliados" : '';
        $fila = $this->connection->query('SELECT COUNT(*) total'.$origen.' FROM formulario_registros r')->fetch_assoc();
        $total = (int)$fila['total'];
        return ['total'=>$total, 'aliados'=>$procedencias?(int)$fila['aliados']:null, 'generales'=>$procedencias?$total-(int)$fila['aliados']:null, 'procedencias_disponibles'=>$procedencias];
    }

    public function territorios(): array
    {
        // Residencia declarada en Registro; no utiliza el territorio del aliado.
        return $this->connection->query("SELECT e.id,e.nombre,COALESCE(r.total,0) total
            FROM estados e LEFT JOIN (SELECT estado_id,COUNT(*) total FROM formulario_registros GROUP BY estado_id) r ON r.estado_id=e.id
            WHERE e.estado=1 ORDER BY e.nombre,e.id")->fetch_all(MYSQLI_ASSOC);
    }

    public function listar($pagina = 1, $porPagina = 25, array $filtros = [])
    {
        $porPagina = max(1, min(100, (int)$porPagina));
        $procedencias = $this->tieneProcedencias();
        // Una procedencia repetida no multiplica la fila ni la paginación.
        $join = $procedencias ? " LEFT JOIN (SELECT registro_id,MIN(id) id FROM formulario_aliado_procedencias WHERE tipo='registro' GROUP BY registro_id) primera ON primera.registro_id=r.id LEFT JOIN formulario_aliado_procedencias ap ON ap.id=primera.id " : '';
        $where = ' WHERE 1=1'; $params=[]; $types='';
        if (isset($filtros['estado_id'])) {
            $estadoId = filter_var($filtros['estado_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($estadoId === false) throw new DomainException('Estado no válido.');
            $estado = $this->connection->prepare('SELECT id FROM estados WHERE id=? AND estado=1');
            $estado->bind_param('i',$estadoId); $estado->execute();
            if (!$estado->get_result()->num_rows) throw new DomainException('Estado no encontrado.');
            $where .= ' AND r.estado_id=?'; $params[]=$estadoId; $types.='i';
        }
        $buscar = trim((string)($filtros['buscar'] ?? ''));
        if ($buscar !== '') { $where .= ' AND (r.nombre LIKE ? OR r.apellido LIKE ? OR r.movil LIKE ?)'; $params=array_merge($params,array_fill(0,3,'%'.$buscar.'%')); $types.='sss'; }
        $origen = (string)($filtros['aliado_id'] ?? '');
        if (!$procedencias && $origen !== '') throw new DomainException('No está disponible la información de procedencia.');
        if ($procedencias && $origen === 'general') $where .= " AND NOT EXISTS(SELECT 1 FROM formulario_aliado_procedencias origen WHERE origen.registro_id=r.id AND origen.tipo='registro')";
        elseif ($procedencias && ctype_digit($origen) && (int)$origen>0) { $where .= " AND EXISTS(SELECT 1 FROM formulario_aliado_procedencias origen WHERE origen.registro_id=r.id AND origen.tipo='registro' AND origen.aliado_id=?)"; $params[]=(int)$origen; $types.='i'; }
        $count = $this->connection->prepare('SELECT COUNT(*) total FROM formulario_registros r'.$where);
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
