<?php
require_once __DIR__ . '/FormularioAliadoService.php';

/** Integra la configuración original con la institución y el convenio existentes. */
class AliadoInternacionalService
{
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db = $db; }
    public static function paises(): array
    {
        return ['MX'=>'México','US'=>'Estados Unidos','CA'=>'Canadá','ES'=>'España','GT'=>'Guatemala','CO'=>'Colombia','AR'=>'Argentina','BR'=>'Brasil','CL'=>'Chile','CR'=>'Costa Rica','CU'=>'Cuba','EC'=>'Ecuador','SV'=>'El Salvador','HN'=>'Honduras','NI'=>'Nicaragua','PA'=>'Panamá','PE'=>'Perú','DO'=>'República Dominicana','UY'=>'Uruguay','VE'=>'Venezuela','OTRO'=>'Otro país'];
    }
    public static function puedeConsultar(): bool
    {
        // Consulta internacional sin exigir una asignación territorial mexicana.
        // Configurar, formalizar y crear conservan sus permisos independientes.
        return isset($_SESSION['usuario_id']) && (
            (tienePermiso('aliados.ver') && ((int)($_SESSION['rol_id'] ?? 0) === 1 || tienePermiso('convocatorias.ver') || tienePermiso('aliados.internacionales.configurar'))) ||
            (tienePermiso('convocatorias.ver') && tienePermiso('convocatorias.crear'))
        );
    }
    public function obtener(int $id, bool $lock = false): array
    {
        $s=$this->db->prepare('SELECT * FROM formulario_aliados WHERE id=?' . ($lock?' FOR UPDATE':''));
        $s->bind_param('i',$id); $s->execute(); $a=$s->get_result()->fetch_assoc();
        if (!$a || ($a['pais_codigo'] ?? '') === 'MX') { throw new DomainException('Aliado internacional no disponible.'); }
        return $a;
    }
    public function guardar(array $d, int $usuario): int
    {
        foreach (['id','nombre','pais_codigo','pais_nombre','region','ciudad','contacto_nombre','contacto_telefono'] as $campo) { if (isset($d[$campo])&&!is_scalar($d[$campo])) { throw new DomainException('Datos de institución no válidos.'); } }
        $id=(int)($d['id']??0); $nombre=trim((string)($d['nombre']??''));
        $pais=(string)($d['pais_codigo']??''); $region=trim((string)($d['region']??'')); $ciudad=trim((string)($d['ciudad']??''));
        if (!isset(self::paises()[$pais]) || $pais==='MX') { throw new DomainException('Para México utiliza el alta territorial existente.'); }
        $paisNombre=$pais==='OTRO'?trim((string)($d['pais_nombre']??'')):self::paises()[$pais];
        foreach ([[$nombre,180],[$paisNombre,100],[$region,120],[$ciudad,120]] as [$valor,$max]) {
            if (mb_strlen((string)$valor)<2 || mb_strlen((string)$valor)>$max) { throw new DomainException('Completa nombre, país, estado o región y ciudad con datos válidos.'); }
        }
        $contacto=trim((string)($d['contacto_nombre']??'')); $telefono=trim((string)($d['contacto_telefono']??''));
        if (mb_strlen($contacto)>180 || mb_strlen($telefono)>80) { throw new DomainException('El contacto o teléfono supera la longitud permitida.'); }
        $ubicacion=$ciudad.', '.$region.', '.$paisNombre;
        if (mb_strlen($ubicacion)>180) { throw new DomainException('La ubicación completa no puede superar 180 caracteres.'); }
        $this->db->begin_transaction();
        try {
            if ($id) {
                $a=$this->obtener($id,true);
                // No crea ni sustituye tokens, programas o vínculos al editar.
                $s=$this->db->prepare('UPDATE formulario_aliados SET nombre=?, pais_codigo=?, pais_nombre=?, region=?, ciudad=?, ubicacion=?, contacto_nombre=?, contacto_telefono=? WHERE id=?');
                $s->bind_param('ssssssssi',$nombre,$pais,$paisNombre,$region,$ciudad,$ubicacion,$contacto,$telefono,$id); $s->execute();
                if (!empty($a['seguimiento_id'])) {
                    $s=$this->db->prepare('UPDATE seguimientos_vinculacion SET nombre_entidad=?, direccion_fuente=?, contacto_nombre=?, telefono_fuente=? WHERE id=?');
                    $s->bind_param('ssssi',$nombre,$ubicacion,$contacto,$telefono,$a['seguimiento_id']); $s->execute();
                }
            } else {
                $programas=$d['programas']??[];
                if (!is_array($programas) || !$programas) { throw new DomainException('Selecciona al menos un programa autorizado.'); }
                $token=bin2hex(random_bytes(32)); $slug='internacional-'.bin2hex(random_bytes(12));
                $s=$this->db->prepare('SELECT id FROM formulario_aliados WHERE nombre=? AND pais_codigo=? AND region=? AND ciudad=? FOR UPDATE');
                $s->bind_param('ssss',$nombre,$pais,$region,$ciudad);$s->execute();
                if ($s->get_result()->num_rows) { throw new DomainException('Esta institución ya está registrada. Edita el aliado existente.'); }
                $s=$this->db->prepare('INSERT INTO formulario_aliados (slug,nombre,ubicacion,token,pais_codigo,pais_nombre,region,ciudad,contacto_nombre,contacto_telefono,creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('ssssssssssi',$slug,$nombre,$ubicacion,$token,$pais,$paisNombre,$region,$ciudad,$contacto,$telefono,$usuario);$s->execute();$id=(int)$this->db->insert_id;
                foreach (array_unique($programas) as $codigo) {
                    if (!is_string($codigo)) { throw new DomainException('Programa no válido.'); }
                    $s=$this->db->prepare('INSERT INTO formulario_aliado_programas (aliado_id,programa_codigo) SELECT ?,codigo FROM formulario_programas WHERE codigo=? AND activo=1');
                    $s->bind_param('is',$id,$codigo);$s->execute();
                    if ($s->affected_rows!==1) { throw new DomainException('Programa no autorizado o inexistente.'); }
                }
            }
            $this->db->commit();return $id;
        } catch (Throwable $e) { $this->db->rollback();throw $e; }
    }
    public function convenio(int $id, array $d, int $usuario): int
    {
        foreach (['fecha','referencia','notas'] as $campo) { if (isset($d[$campo])&&!is_scalar($d[$campo])) { throw new DomainException('Datos de convenio no válidos.'); } }
        $fecha=trim((string)($d['fecha']??''));$ref=trim((string)($d['referencia']??''));$notas=trim((string)($d['notas']??''));
        $f=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
        if (!$f || $f->format('Y-m-d')!==$fecha || $ref==='' || mb_strlen($ref)>180 || mb_strlen($notas)>10000) { throw new DomainException('Completa fecha y referencia del convenio.'); }
        $this->db->begin_transaction();
        try {
            $a=$this->obtener($id,true);$sid=(int)$a['seguimiento_id'];
            if (!$sid) {
                // Una única institución canónica. Sin territorio ni analista mexicano ficticio.
                $clave='ALIADO_FORMULARIO:'.$id;
                $s=$this->db->prepare("SELECT id FROM seguimientos_vinculacion WHERE clave_origen=? FOR UPDATE");$s->bind_param('s',$clave);$s->execute();$fila=$s->get_result()->fetch_assoc();
                if ($fila) { $sid=(int)$fila['id']; }
                else {
                    $s=$this->db->prepare("INSERT INTO seguimientos_vinculacion (estado_id,analista_id,origen,clave_origen,tipo_entidad,nombre_entidad,direccion_fuente,contacto_nombre,telefono_fuente) VALUES (NULL,NULL,'MANUAL',?,'INSTITUCION',?,?,?,?)");
                    $s->bind_param('sssss',$clave,$a['nombre'],$a['ubicacion'],$a['contacto_nombre'],$a['contacto_telefono']);$s->execute();$sid=(int)$this->db->insert_id;
                }
                $s=$this->db->prepare('UPDATE formulario_aliados SET seguimiento_id=? WHERE id=?');$s->bind_param('ii',$sid,$id);$s->execute();
            }
            $s=$this->db->prepare('INSERT INTO seguimientos_vinculacion_post_envio (seguimiento_id,convenio_fecha,convenio_referencia,convenio_notas,convenio_formalizado_at,convenio_formalizado_por) VALUES (?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE convenio_fecha=VALUES(convenio_fecha),convenio_referencia=VALUES(convenio_referencia),convenio_notas=VALUES(convenio_notas),convenio_formalizado_at=COALESCE(convenio_formalizado_at,NOW()),convenio_formalizado_por=COALESCE(convenio_formalizado_por,VALUES(convenio_formalizado_por))');
            $s->bind_param('isssi',$sid,$fecha,$ref,$notas,$usuario);$s->execute();$this->db->commit();return $sid;
        } catch (Throwable $e) { $this->db->rollback();throw $e; }
    }
    public function ofertas(int $id): array
    {
        $a=$this->obtener($id); $a=(new FormularioAliadoService($this->db))->resolver($a['token']);$r=[];
        $map=['bachillerato-general'=>['bachillerato|bachillerato-286','Bachillerato General (286)'],'titulacion-experiencia-laboral'=>['titulacion|experiencia-laboral','Titulación por experiencia laboral'],'ingles'=>['bachillerato|ingles','Inglés'],'bachillerato-2-anos'=>['bachillerato|bachillerato-2-anos','Bachillerato en 2 años'],'titulacion-ejecutivas'=>['titulacion|ejecutivas','Ejecutivas']];
        foreach ($a['programas'] as $p) {
            if (isset($map[$p['codigo']])) { [$valor,$label]=$map[$p['codigo']];$r[]=['codigo'=>$p['codigo'],'valor'=>$valor,'etiqueta'=>$label]; }
        }
        return $r;
    }
    public function relacionar(int $id, int $convocatoria, string $codigo, string $formulario, string $ref, int $usuario): void
    {
        $a=$this->obtener($id,true);
        $autorizado=(new FormularioAliadoService($this->db))->resolver($a['token'],true);
        if (!in_array($codigo,array_column($autorizado['programas'],'codigo'),true)) { throw new DomainException('Programa no autorizado.'); }
        $s=$this->db->prepare('INSERT INTO formulario_aliado_convocatorias (aliado_id,convocatoria_id,programa_codigo,formulario,referencia,convenio_seguimiento_id) VALUES (?,?,?,?,?,?)');
        $sid=$a['seguimiento_id'];$s->bind_param('iisssi',$id,$convocatoria,$codigo,$formulario,$ref,$sid);$s->execute();
        if ($sid) {
            $s=$this->db->prepare('INSERT INTO marketing_convenio_convocatorias (seguimiento_id,convocatoria_id,creado_por) VALUES (?,?,?)');$s->bind_param('iii',$sid,$convocatoria,$usuario);$s->execute();
            $s=$this->db->prepare('INSERT INTO marketing_convenio_atencion (seguimiento_id,atendido_por) VALUES (?,?) ON DUPLICATE KEY UPDATE atendido_por=VALUES(atendido_por)');$s->bind_param('ii',$sid,$usuario);$s->execute();
        }
    }
    public function convocatorias(): array
    {
        return $this->db->query('SELECT x.*,p.nombre AS programa_nombre,c.titulo,c.enlace_registro,c.estado,c.fecha_inicio,c.fecha_termino FROM formulario_aliado_convocatorias x INNER JOIN convocatorias c ON c.id=x.convocatoria_id INNER JOIN formulario_programas p ON p.codigo=x.programa_codigo ORDER BY c.created_at DESC,c.id DESC')->fetch_all(MYSQLI_ASSOC);
    }
}
