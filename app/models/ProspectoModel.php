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

    private function joinProcedencia(): string
    {
        // El mismo origen se muestra, se cuenta y se filtra; los duplicados no suman filas.
        return " LEFT JOIN (SELECT registro_id,MIN(id) id FROM formulario_aliado_procedencias WHERE tipo='registro' GROUP BY registro_id) primera ON primera.registro_id=r.id LEFT JOIN formulario_aliado_procedencias ap ON ap.id=primera.id ";
    }

    private function tablaTerritorial(string $tabla, array $requeridas): bool
    {
        if (!$this->connection->query("SHOW TABLES LIKE '$tabla'")->num_rows) return false;
        $columnas = array_column($this->connection->query("SHOW COLUMNS FROM `$tabla`")->fetch_all(MYSQLI_ASSOC), 'Field');
        if (array_diff($requeridas, $columnas)) throw new DomainException('No se puede verificar el territorio del aliado.');
        return true;
    }

    private function territorioAliado(): array
    {
        if (!$this->tieneProcedencias() ||
            !$this->tablaTerritorial('formulario_aliados', ['id','seguimiento_id']) ||
            !$this->tablaTerritorial('seguimientos_vinculacion', ['id','estado_id'])) {
            throw new DomainException('No se puede verificar el territorio del aliado.');
        }
        // Identificadores reales: nunca residencia, ubicación libre ni nombres similares.
        // No se exige activo: los prospectos históricos conservan su origen.
        $join = ' LEFT JOIN formulario_aliados fa ON fa.id=ap.aliado_id ';
        $seguimiento = 'fa.seguimiento_id';
        $estado = 'sa.estado_id';
        if ($this->tablaTerritorial('formulario_aliado_registro', ['aliado_id','manual_id','seguimiento_id'])) {
            if (!$this->tablaTerritorial('aliados_manuales', ['id','estado_id'])) throw new DomainException('No se puede verificar el territorio del aliado manual.');
            $join .= ' LEFT JOIN formulario_aliado_registro perfil ON perfil.aliado_id=fa.id LEFT JOIN aliados_manuales ma ON ma.id=perfil.manual_id ';
            $seguimiento = 'CASE WHEN perfil.aliado_id IS NULL THEN fa.seguimiento_id WHEN perfil.manual_id IS NULL THEN perfil.seguimiento_id ELSE NULL END';
            $estado = 'CASE WHEN perfil.manual_id IS NOT NULL AND perfil.seguimiento_id IS NULL THEN ma.estado_id WHEN perfil.manual_id IS NULL THEN sa.estado_id ELSE NULL END';
        }
        $join .= " LEFT JOIN seguimientos_vinculacion sa ON sa.id=($seguimiento) ";
        return [$join, $estado];
    }

    public function territorios(): array
    {
        [$join, $estado] = $this->territorioAliado();
        $procedencia = $this->joinProcedencia();
        return $this->connection->query("SELECT e.id,e.nombre,COALESCE(origen.total,0) total
            FROM estados e LEFT JOIN (
                SELECT $estado estado_id,COUNT(*) total FROM formulario_registros r $procedencia $join GROUP BY $estado
            ) origen ON origen.estado_id=e.id
            WHERE e.estado=1 ORDER BY e.nombre,e.id")->fetch_all(MYSQLI_ASSOC);
    }

    public function listar($pagina = 1, $porPagina = 25, array $filtros = [])
    {
        $porPagina = max(1, min(100, (int)$porPagina));
        $procedencias = $this->tieneProcedencias();
        // Una procedencia repetida no multiplica la fila ni la paginación.
        $join = $procedencias ? $this->joinProcedencia() : '';
        $where = ' WHERE 1=1'; $params=[]; $types='';
        if (isset($filtros['estado_id'])) {
            $estadoId = filter_var($filtros['estado_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($estadoId === false) throw new DomainException('Estado no válido.');
            $estado = $this->connection->prepare('SELECT id FROM estados WHERE id=? AND estado=1');
            $estado->bind_param('i',$estadoId); $estado->execute();
            if (!$estado->get_result()->num_rows) throw new DomainException('Estado no encontrado.');
            [$joinTerritorial, $estadoAliado] = $this->territorioAliado();
            $join .= $joinTerritorial;
            $where .= " AND ($estadoAliado)=?"; $params[]=$estadoId; $types.='i';
        }
        $buscar = trim((string)($filtros['buscar'] ?? ''));
        if ($buscar !== '') { $where .= ' AND (r.nombre LIKE ? OR r.apellido LIKE ? OR r.movil LIKE ?)'; $params=array_merge($params,array_fill(0,3,'%'.$buscar.'%')); $types.='sss'; }
        $origen = (string)($filtros['aliado_id'] ?? '');
        if (!$procedencias && $origen !== '') throw new DomainException('No está disponible la información de procedencia.');
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
