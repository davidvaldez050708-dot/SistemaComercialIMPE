<?php
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Entrega 1A: configuración de jornadas, sin asignar prospectos ni llamar PBX.
 * Todas las escrituras requieren permiso, POST con CSRF (controlador) y versión.
 */
final class ProspectoAsesorConfiguracionService
{
    public const META_DIARIOS = 80;
    public const DIAS = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    private mysqli $db;

    public function __construct(mysqli $db) {$this->db=$db;}

    public static function autorizado(): bool
    {
        return !empty($_SESSION['usuario_id'])
            && tienePermiso('prospectos.ver_todos')
            && tienePermiso('prospectos.configurar_asesores');
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf_prospectos_asesores'])) {
            $_SESSION['csrf_prospectos_asesores']=bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_prospectos_asesores'];
    }

    public static function verificarCsrf($token): void
    {
        if (!is_string($token) || !hash_equals(self::csrf(),$token)) {
            throw new DomainException('La sesión de seguridad expiró. Recarga la página e inténtalo de nuevo.');
        }
    }

    public function disponible(): bool
    {
        $nombres=['prospecto_asesores_config','prospecto_asesores_horarios','prospecto_asesores_auditoria','prospecto_asesores_programas'];
        $encontradas=[];
        foreach ($nombres as $nombre) {
            $st=$this->db->prepare('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $st->bind_param('s',$nombre);$st->execute();
            $encontradas[]=(int)$st->get_result()->fetch_assoc()['n']===1;$st->close();
        }
        if (!in_array(true,$encontradas,true)) return false;
        if (in_array(false,$encontradas,true)) throw new RuntimeException('Configuración comercial parcialmente instalada.');
        foreach ([
            'prospecto_asesores_config'=>['usuario_id','programa_codigo','jornada','activo','version','actualizado_por','updated_at'],
            'prospecto_asesores_horarios'=>['usuario_id','dia_semana','hora_desde','hora_hasta'],
            'prospecto_asesores_auditoria'=>['id','usuario_id','actor_id','antes_json','despues_json','created_at'],
            'prospecto_asesores_programas'=>['usuario_id','programa_codigo']
        ] as $tabla=>$campos) {
            $rs=$this->db->query("SHOW COLUMNS FROM ".$tabla);
            $existentes=array_column($rs->fetch_all(MYSQLI_ASSOC),'Field');
            if (array_diff($campos,$existentes)) throw new RuntimeException('Estructura comercial incompatible.');
        }
        return true;
    }

    public function programas(): array
    {
        return $this->db->query('SELECT codigo,nombre FROM formulario_programas WHERE activo=1 ORDER BY nombre,codigo')
            ->fetch_all(MYSQLI_ASSOC);
    }

    public function asesores(bool $disponible): array
    {
        $join=$disponible?'LEFT JOIN prospecto_asesores_config c ON c.usuario_id=u.id':'';
        $campos=$disponible
            ? 'c.programa_codigo,c.jornada,c.activo configuracion_activa,c.version,c.actualizado_por,c.updated_at'
            : 'NULL programa_codigo,NULL jornada,NULL configuracion_activa,0 version,NULL actualizado_por,NULL updated_at';
        $sql="SELECT u.id,u.nombre,u.apellidos,u.foto_perfil,u.estado usuario_activo,$campos
              FROM usuarios u JOIN roles r ON r.id=u.rol_id $join
              WHERE r.nombre='Asesor de Ventas' ORDER BY u.estado DESC,u.nombre,u.apellidos,u.id";
        $asesores=$this->db->query($sql)->fetch_all(MYSQLI_ASSOC);
        $horarios=[];$programas=[];
        if ($disponible) {
            $todos=$this->db->query('SELECT usuario_id,programa_codigo FROM prospecto_asesores_programas ORDER BY programa_codigo')
                ->fetch_all(MYSQLI_ASSOC);
            foreach ($todos as $fila) $programas[(int)$fila['usuario_id']][]=(string)$fila['programa_codigo'];
            $filas=$this->db->query('SELECT h.usuario_id,h.dia_semana,h.hora_desde,h.hora_hasta FROM prospecto_asesores_horarios h ORDER BY h.usuario_id,h.dia_semana')
                ->fetch_all(MYSQLI_ASSOC);
            foreach ($filas as $fila) {
                $hora=static fn($v)=>substr((string)$v,0,5);
                $horarios[(int)$fila['usuario_id']][(int)$fila['dia_semana']]=[
                    'desde'=>$hora($fila['hora_desde']),'hasta'=>$hora($fila['hora_hasta'])
                ];
            }
        }
        foreach ($asesores as &$asesor) {
            $asesor['horarios']=$horarios[(int)$asesor['id']]??[];
            // Fallback defensivo: preserva la selección antigua si la migración
            // fue aplicada sobre un registro que aún no se ha guardado de nuevo.
            $asesor['programas']=$programas[(int)$asesor['id']]
                ?? ($asesor['programa_codigo']!==null?[(string)$asesor['programa_codigo']]:[]);
            $asesor['version']=(int)$asesor['version'];
        }
        unset($asesor);
        return $asesores;
    }

    /** Valida datos sin consultar ni modificar BD; reutilizable en pruebas. */
    public static function validar(array $datos,array $programas): array
    {
        $seleccion=$datos['programas']??null;
        if (!is_array($seleccion)||!$seleccion||count($seleccion)>500) {
            throw new DomainException('Selecciona al menos un programa educativo.');
        }
        $codigos=[];
        foreach ($seleccion as $codigo) {
            if (!is_string($codigo)||$codigo===''||strlen($codigo)>64||!in_array($codigo,$programas,true)) {
                throw new DomainException('Selecciona únicamente programas vigentes del catálogo.');
            }
            $codigos[$codigo]=$codigo;
        }
        $codigos=array_values($codigos);
        sort($codigos,SORT_STRING);
        $codigo=$codigos[0];
        $jornada=$datos['jornada']??null;
        $activo=($datos['activo']??'0')==='1';
        $version=filter_var($datos['version']??null,FILTER_VALIDATE_INT,[
            'options'=>['min_range'=>0]
        ]);
        if (!is_string($jornada)||!in_array($jornada,['MEDIO_TIEMPO','TIEMPO_COMPLETO'],true)) {
            throw new DomainException('Selecciona una jornada laboral válida.');
        }
        if ($version===false) throw new DomainException('La versión de la configuración no es válida.');
        $entradas=$datos['dias']??[];
        if (!is_array($entradas)) throw new DomainException('Los horarios no son válidos.');
        $horarios=[];
        foreach ($entradas as $dia=>$fila) {
            if (!ctype_digit((string)$dia)||(int)$dia<1||(int)$dia>7||!is_array($fila)) {
                throw new DomainException('Se recibió un día laboral no válido.');
            }
            if (($fila['activo']??'0')!=='1') continue;
            $desde=$fila['desde']??null;$hasta=$fila['hasta']??null;
            if (!is_string($desde)||!is_string($hasta)
                ||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$desde)
                ||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$hasta)
                ||$desde >= $hasta) {
                throw new DomainException('El horario de '.self::DIAS[(int)$dia].' debe tener una entrada anterior a la salida.');
            }
            $horarios[(int)$dia]=['desde'=>$desde,'hasta'=>$hasta];
        }
        if ($activo&&!$horarios) throw new DomainException('Selecciona al menos un día de trabajo para activar al asesor.');
        ksort($horarios);
        return ['programa_codigo'=>$codigo,'programas'=>$codigos,'jornada'=>$jornada,'activo'=>$activo?1:0,
            'version'=>$version,'horarios'=>$horarios,'meta_diarios'=>self::META_DIARIOS];
    }

    public function guardar(int $usuarioId,int $actorId,array $datos): void
    {
        if (!self::autorizado()) throw new DomainException('No tienes permiso para configurar asesores.');
        if ($usuarioId<1||$actorId<1) throw new DomainException('Selecciona un asesor válido.');
        if (!$this->disponible()) throw new DomainException('Falta instalar la estructura de configuración comercial.');
        $validado=self::validar($datos,array_column($this->programas(),'codigo'));
        $this->db->begin_transaction();
        try {
            $st=$this->db->prepare("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id
                WHERE u.id=? AND u.estado=1 AND r.nombre='Asesor de Ventas' FOR UPDATE");
            $st->bind_param('i',$usuarioId);$st->execute();
            $asesor=$st->get_result()->fetch_assoc();$st->close();
            if (!$asesor) throw new DomainException('El usuario no es un asesor de ventas activo.');
            $st=$this->db->prepare('SELECT * FROM prospecto_asesores_config WHERE usuario_id=? FOR UPDATE');
            $st->bind_param('i',$usuarioId);$st->execute();
            $actual=$st->get_result()->fetch_assoc();$st->close();
            if ((int)($actual['version']??0)!==$validado['version']) {
                throw new DomainException('Otro usuario modificó esta configuración. Recarga y revisa los cambios.');
            }
            $st=$this->db->prepare('SELECT dia_semana,hora_desde,hora_hasta FROM prospecto_asesores_horarios WHERE usuario_id=? ORDER BY dia_semana');
            $st->bind_param('i',$usuarioId);$st->execute();
            $antesHoras=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
            $st=$this->db->prepare('SELECT programa_codigo FROM prospecto_asesores_programas WHERE usuario_id=? ORDER BY programa_codigo FOR UPDATE');
            $st->bind_param('i',$usuarioId);$st->execute();
            $antesProgramas=array_column($st->get_result()->fetch_all(MYSQLI_ASSOC),'programa_codigo');$st->close();
            $antes=['configuracion'=>$actual,'horarios'=>$antesHoras,'programas'=>$antesProgramas];
            $codigo=$validado['programa_codigo'];$jornada=$validado['jornada'];$activo=$validado['activo'];
            if ($actual) {
                $st=$this->db->prepare('UPDATE prospecto_asesores_config SET programa_codigo=?,jornada=?,activo=?,version=version+1,actualizado_por=?,updated_at=UTC_TIMESTAMP() WHERE usuario_id=?');
                $st->bind_param('ssiii',$codigo,$jornada,$activo,$actorId,$usuarioId);
            } else {
                $st=$this->db->prepare('INSERT INTO prospecto_asesores_config(usuario_id,programa_codigo,jornada,activo,version,actualizado_por,updated_at) VALUES (?,?,?,?,1,?,UTC_TIMESTAMP())');
                $st->bind_param('issii',$usuarioId,$codigo,$jornada,$activo,$actorId);
            }
            $st->execute();$st->close();
            $st=$this->db->prepare('DELETE FROM prospecto_asesores_programas WHERE usuario_id=?');
            $st->bind_param('i',$usuarioId);$st->execute();$st->close();
            foreach ($validado['programas'] as $programaCodigo) {
                $st=$this->db->prepare('INSERT INTO prospecto_asesores_programas(usuario_id,programa_codigo) VALUES (?,?)');
                $st->bind_param('is',$usuarioId,$programaCodigo);$st->execute();$st->close();
            }
            $st=$this->db->prepare('DELETE FROM prospecto_asesores_horarios WHERE usuario_id=?');
            $st->bind_param('i',$usuarioId);$st->execute();$st->close();
            foreach ($validado['horarios'] as $dia=>$horas) {
                $st=$this->db->prepare('INSERT INTO prospecto_asesores_horarios(usuario_id,dia_semana,hora_desde,hora_hasta) VALUES (?,?,?,?)');
                $st->bind_param('iiss',$usuarioId,$dia,$horas['desde'],$horas['hasta']);$st->execute();$st->close();
            }
            $despues=$validado;
            $antesJson=json_encode($antes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $despuesJson=json_encode($despues,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $st=$this->db->prepare('INSERT INTO prospecto_asesores_auditoria(usuario_id,actor_id,antes_json,despues_json,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
            $st->bind_param('iiss',$usuarioId,$actorId,$antesJson,$despuesJson);$st->execute();$st->close();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && in_array((int)$e->getCode(),[1062,1213,1205],true)) {
                throw new DomainException('La configuración cambió durante el guardado. Recarga e inténtalo nuevamente.');
            }
            throw $e;
        }
    }
}
