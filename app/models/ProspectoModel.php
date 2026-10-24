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

    private function consultar(string $sql, array $params = [], string $types = '')
    {
        $stmt = $this->connection->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $stmt->close();
        if (!$resultado) throw new RuntimeException('No se pudo consultar Prospectos.');
        return $resultado;
    }

    public static function clavePrograma(string $origen, string $valor): string
    {
        return $origen.':'.rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }

    private function opcionesAliados(string $join, string $where, array $params, string $types, string $seleccion): array
    {
        if (!$this->tablaTerritorial('formulario_aliados', ['id','nombre'])) throw new DomainException('Información de procedencia no disponible.');
        $reales = array_column($this->consultar('SELECT id,nombre FROM formulario_aliados')->fetch_all(MYSQLI_ASSOC), null, 'id');
        if ($seleccion !== '' && $seleccion !== 'general' && !isset($reales[$seleccion])) throw new DomainException('El aliado seleccionado no está disponible.');
        $filas = $this->consultar("SELECT DISTINCT ap.aliado_id id FROM formulario_registros r $join $where AND ap.id IS NOT NULL", $params, $types)->fetch_all(MYSQLI_ASSOC);
        $opciones = [];
        foreach ($filas as $fila) if (isset($reales[$fila['id']])) $opciones[$fila['id']] = $reales[$fila['id']];
        // Conservar una selección por URL aunque no tenga registros en este territorio.
        if ($seleccion !== '' && $seleccion !== 'general') $opciones[$seleccion] = $reales[$seleccion];
        usort($opciones, static fn($a,$b) => strcasecmp($a['nombre'],$b['nombre']) ?: (int)$a['id'] <=> (int)$b['id']);
        return $opciones;
    }

    private function opcionesProgramas(string $join, string $where, array $params, string $types): array
    {
        $catalogo = [];
        if ($this->tablaTerritorial('formulario_programas', ['codigo','nombre'])) {
            // El catálogo sólo da etiquetas. No se exige activo ni oferta vigente: son prospectos históricos.
            $catalogo = array_column($this->consultar('SELECT codigo,nombre FROM formulario_programas')->fetch_all(MYSQLI_ASSOC), 'nombre', 'codigo');
        }
        $aliados = $this->consultar("SELECT DISTINCT BINARY COALESCE(ap.programa_codigo,'') valor FROM formulario_registros r $join $where AND ap.id IS NOT NULL", $params, $types)->fetch_all(MYSQLI_ASSOC);
        $generales = $this->consultar("SELECT DISTINCT BINARY COALESCE(r.perfil_interes,'') valor FROM formulario_registros r $join $where AND ap.id IS NULL", $params, $types)->fetch_all(MYSQLI_ASSOC);
        $opciones = [];
        foreach ($aliados as $fila) {
            $valor = (string)$fila['valor'];
            $opciones[] = ['valor'=>self::clavePrograma('a',$valor), 'dato'=>$valor, 'origen'=>'a', 'nombre'=>'Aliados · '.($catalogo[$valor] ?? ($valor !== '' ? 'Código: '.$valor : 'Sin programa registrado'))];
        }
        foreach ($generales as $fila) {
            $valor = (string)$fila['valor'];
            $opciones[] = ['valor'=>self::clavePrograma('g',$valor), 'dato'=>$valor, 'origen'=>'g', 'nombre'=>'Registro general · '.($valor !== '' ? $valor : 'Sin perfil registrado')];
        }
        usort($opciones, static fn($a,$b) => strcasecmp($a['nombre'],$b['nombre']) ?: strcmp($a['valor'],$b['valor']));
        return $opciones;
    }

    public function listar($pagina = 1, $porPagina = 25, array $filtros = [])
    {
        $porPagina = max(1, min(100, (int)$porPagina));
        foreach (['buscar','aliado_id','programa'] as $campo) if (isset($filtros[$campo]) && !is_string($filtros[$campo]) && !($campo === 'aliado_id' && is_int($filtros[$campo]))) throw new DomainException('Filtro no válido.');
        $buscar = trim((string)($filtros['buscar'] ?? ''));
        $origen = (string)($filtros['aliado_id'] ?? '');
        $programa = (string)($filtros['programa'] ?? '');
        if (mb_strlen($buscar)>180 || ($origen !== '' && $origen !== 'general' && filter_var($origen,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) === false)) throw new DomainException('Filtro no válido.');
        if ($programa !== '' && !preg_match('/^[ag]:[A-Za-z0-9_-]{0,1024}$/D',$programa)) throw new DomainException('Programa no válido.');
        $procedencias = $this->tieneProcedencias();
        $join = $procedencias ? $this->joinProcedencia() : '';
        $where = ' WHERE 1=1'; $params=[]; $types='';
        if (isset($filtros['estado_id'])) {
            $estadoId = filter_var($filtros['estado_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($estadoId === false) throw new DomainException('Estado no válido.');
            if (!$this->consultar('SELECT id FROM estados WHERE id=? AND estado=1',[$estadoId],'i')->num_rows) throw new DomainException('Estado no encontrado.');
            [$joinTerritorial, $estadoAliado] = $this->territorioAliado();
            $join .= $joinTerritorial;
            $where .= " AND ($estadoAliado)=?"; $params[]=$estadoId; $types.='i';
        }
        if (!$procedencias && ($origen !== '' || $programa !== '')) throw new DomainException('Información de procedencia no disponible.');
        $aliados=[]; $errorAliados='';
        if ($procedencias) {
            try { $aliados=$this->opcionesAliados($join,$where,$params,$types,$origen); }
            catch (Throwable $e) {
                if ($origen !== '') throw new DomainException('El aliado seleccionado no está disponible.');
                error_log('[prospectos_aliados] '.$e->getMessage()); $errorAliados='Información de procedencia no disponible.';
            }
        }
        if ($procedencias && $origen === 'general') $where .= ' AND ap.id IS NULL';
        elseif ($procedencias && $origen !== '') { $where .= ' AND ap.aliado_id=?'; $params[]=(int)$origen; $types.='i'; }
        $programas=[]; $errorProgramas=$procedencias?'':'Información de procedencia no disponible.';
        if ($procedencias) {
            try { $programas=$this->opcionesProgramas($join,$where,$params,$types); }
            catch (Throwable $e) { error_log('[prospectos_programas] '.$e->getMessage()); $errorProgramas='No fue posible cargar los programas.'; }
        }
        if ($programa !== '' && $errorProgramas !== '') throw new DomainException($errorProgramas);
        $opcion = array_column($programas,null,'valor')[$programa] ?? null;
        // Una opción de otro aliado se restablece a Todos, también sin JavaScript.
        $programaAplicado = $opcion ? $programa : '';
        if ($opcion) {
            $where .= $opcion['origen']==='a' ? " AND ap.id IS NOT NULL AND BINARY COALESCE(ap.programa_codigo,'')=?" : " AND ap.id IS NULL AND BINARY COALESCE(r.perfil_interes,'')=?";
            $params[]=$opcion['dato']; $types.='s';
        }
        if ($buscar !== '') { $where .= ' AND (r.nombre LIKE ? OR r.apellido LIKE ? OR r.movil LIKE ? OR r.correo LIKE ?)'; $params=array_merge($params,array_fill(0,4,'%'.$buscar.'%')); $types.='ssss'; }
        $total = (int)$this->consultar('SELECT COUNT(*) total FROM formulario_registros r'.$join.$where,$params,$types)->fetch_assoc()['total'];
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min((int)$pagina, $paginas));
        $offset = ($pagina - 1) * $porPagina;
        $origenSelect = $procedencias ? ',ap.aliado_id,ap.aliado_nombre,ap.programa_codigo' : ',NULL aliado_id,NULL aliado_nombre,NULL programa_codigo';
        $horarioSelect = $this->connection->query("SHOW COLUMNS FROM formulario_registros WHERE Field='horario_contacto'")->num_rows
            ? ',r.horario_contacto' : ',NULL horario_contacto';
        $sql = "SELECT r.id,r.nombre,r.apellido,r.fecha_nacimiento,r.movil,r.movil_secundario,r.tel_recomendado,
                       r.correo,r.perfil_interes,r.lugar_laboras,r.cargo_puesto,r.origen,r.created_at,
                       e.nombre estado,m.nombre municipio $origenSelect $horarioSelect
                FROM formulario_registros r $join
                LEFT JOIN estados e ON e.id=r.estado_id LEFT JOIN municipios m ON m.id=r.municipio_id
                $where ORDER BY r.created_at DESC,r.id DESC LIMIT ? OFFSET ?";
        $registros = $this->consultar($sql,[...$params,$porPagina,$offset],$types.'ii')->fetch_all(MYSQLI_ASSOC);
        return ['aliados'=>$aliados,'programas'=>$programas,'programa_aplicado'=>$programaAplicado,
            'error_aliados'=>$errorAliados,'error_programas'=>$errorProgramas,
            'registros'=>$registros,'total'=>$total,'pagina'=>$pagina,'paginas'=>$paginas];
    }
}
