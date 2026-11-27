<?php
declare(strict_types=1);

/** Alcance de personas; nunca concede permisos generales ni acceso operativo a expedientes. */
final class SupervisionPersonalAcceso
{
    private ?bool $activo = null;
    private static ?WeakMap $instancias = null;
    private array $consultasAutorizadas = [];
    public function __construct(private mysqli $db) {}

    public static function crear(?mysqli $db = null): self
    {
        if (!$db) {
            require_once __DIR__.'/../../config/db_connection.php';
            $db = method_exists('Database','compartida') ? Database::compartida() : (new Database())->connect(false);
        }
        self::$instancias ??= new WeakMap();
        return self::$instancias[$db] ??= new self($db);
    }
    public static function invalidar(mysqli $db): void
    {
        if(self::$instancias!==null && isset(self::$instancias[$db]))unset(self::$instancias[$db]);
    }
    public function activa(): bool
    {
        if ($this->activo !== null) return $this->activo;
        $s=$this->db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supervision_personal_estado'");
        $s->execute();$exists=(bool)$s->get_result()->fetch_row();$s->close();
        if (!$exists) return $this->activo=false;
        $row=$this->db->query('SELECT activo FROM supervision_personal_estado WHERE id=1')->fetch_assoc();
        if (!$row) throw new RuntimeException('Estado de supervisión incompleto.');
        return $this->activo=(bool)$row['activo'];
    }
    public function revision(): int
    {
        if (!$this->activa()) return 0;
        $r=$this->db->query('SELECT revision FROM supervision_personal_estado WHERE id=1')->fetch_assoc();
        return (int)$r['revision'];
    }
    public function validarConsulta(int $usuario, string $contexto): void
    {
        if(isset($this->consultasAutorizadas[$usuario.':'.$contexto]))return;
        $codigos=match($contexto) {
            'llamadas'=>['llamadas.supervision.ver'],
            'grabaciones'=>['llamadas.supervision.ver','llamadas.supervision.audio'],
            'actividad_asesores'=>['reportes.ver','reportes.ventas','reportes.ventas.equipo'],
            'actividad_analistas'=>['reportes.ver','reportes.seguimiento.actividad','seguimientos_vinculacion.ver','seguimientos_vinculacion.supervisar'],
            'desempeno'=>['desempeno.ver','desempeno.ver_equipo'],
            default=>throw new InvalidArgumentException('Contexto de supervisión inválido.')
        };
        $s=$this->db->prepare('SELECT u.rol_id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.estado=1');
        $s->bind_param('i',$usuario);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        if ((int)($row['rol_id']??0)!==6) throw new DomainException('No tienes acceso al equipo de supervisión.',403);
        $s=$this->db->prepare('SELECT p.codigo FROM permisos p JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE rp.rol_id=6 AND p.estado=1');
        $s->execute();$permisos=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'codigo');$s->close();
        if (array_diff($codigos,$permisos)) throw new DomainException('No tienes permiso para esta consulta.',403);
        $this->consultasAutorizadas[$usuario.':'.$contexto]=true;
    }
    /** NULL conserva el comportamiento instalado hasta la activación explícita. */
    public function sql(string $columna, int $kam, string $contexto, ?int $rol = null): ?string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D',$columna)) throw new InvalidArgumentException('Columna de supervisión inválida.');
        if ($rol!==null && !in_array($rol,[3,4],true)) throw new InvalidArgumentException('Rol de colaborador inválido.');
        if (!$this->activa()) return null;
        $this->validarConsulta($kam,$contexto);
        $roles=$rol===null?'3,4':(string)$rol;
        // EXISTS evita multiplicar hechos cuando una persona tiene varios supervisores.
        // El estado del colaborador no borra su actividad histórica al darlo de baja.
        return "EXISTS (SELECT 1 FROM supervision_personal sp
            JOIN usuarios sp_kam ON sp_kam.id=sp.kam_id AND sp_kam.estado=1 AND sp_kam.rol_id=6
            JOIN roles sp_rol_kam ON sp_rol_kam.id=sp_kam.rol_id AND sp_rol_kam.estado=1
            JOIN usuarios sp_col ON sp_col.id=sp.colaborador_id AND sp_col.rol_id IN ($roles)
            JOIN roles sp_rol_col ON sp_rol_col.id=sp_col.rol_id AND sp_rol_col.estado=1
            WHERE sp.kam_id=$kam AND sp.activo=1 AND sp.colaborador_id=$columna)";
    }
    public function personas(int $kam, string $contexto, ?int $rol = null): ?array
    {
        $sql=$this->sql('u.id',$kam,$contexto,$rol);
        if ($sql===null) return null;
        return $this->db->query("SELECT u.id,u.nombre,u.apellidos,u.foto_perfil,u.estado activo,
            TRIM(CONCAT_WS(' ',u.nombre,u.apellidos)) nombre_completo
            FROM usuarios u WHERE $sql ORDER BY u.nombre,u.apellidos,u.id")->fetch_all(MYSQLI_ASSOC);
    }
    public function sqlSeguimiento(string $alias,int $kam,string $contexto='actividad_analistas'): ?string
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$alias))throw new InvalidArgumentException('Alias de seguimiento inválido.');
        $responsable=$this->sql($alias.'.analista_id',$kam,$contexto,4);
        if($responsable===null)return null;
        $autor=$this->sql('sp_i.usuario_id',$kam,$contexto,4);
        return "($responsable OR EXISTS(SELECT 1 FROM interacciones_vinculacion sp_i WHERE sp_i.seguimiento_id=$alias.id AND $autor))";
    }
    public function estados(int $kam,string $contexto,int $rol=4): ?array
    {
        $sql=$this->sql('a.usuario_id',$kam,$contexto,$rol);
        if ($sql===null) return null;
        $tipo=$rol===3?'ASESOR':'ANALISTA_DATOS';
        // Los estados sólo son un filtro de los colaboradores, no una concesión al KAM.
        return $this->db->query("SELECT DISTINCT e.id,e.nombre FROM estados e
            JOIN asignaciones_territorio a ON a.estado_id=e.id
            WHERE e.estado=1 AND a.activo=1 AND a.tipo_asignacion='$tipo'
            AND (a.fecha_inicio IS NULL OR a.fecha_inicio<=CURDATE())
            AND (a.fecha_fin IS NULL OR a.fecha_fin>=CURDATE()) AND $sql ORDER BY e.nombre")->fetch_all(MYSQLI_ASSOC);
    }
}
