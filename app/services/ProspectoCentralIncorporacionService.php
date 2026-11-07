<?php
require_once __DIR__.'/ProspectoCentralRepository.php';

/** Copia vinculada y recuperable; no cambia formularios ni deduplica personas. */
final class ProspectoCentralIncorporacionService
{
    private mysqli $db;
    private ProspectoCentralRepository $repo;
    public function __construct(mysqli $db) {$this->db=$db;$this->repo=new ProspectoCentralRepository($db);}

    public static function despuesDeGuardar(int $id): void
    {
        $db=null;
        try {
            require_once __DIR__.'/../../config/db_connection.php';
            // Sesión independiente: jamás confirmar/revertir la transacción del formulario.
            $db=(new Database())->connect(false);
            $svc=new self($db);
            if (!$svc->repo->estructura()['disponible']) return;
            $resultado=$svc->incorporarRegistro($id);
            // Se asigna después de confirmar tanto formulario como almacén.
            // Un fallo de asignación nunca revierte el registro original.
            if ((int)($resultado['prospecto_id']??0)>0) {
                try {
                    require_once __DIR__.'/ProspectoDistribucionMotor.php';
                    $motor=new ProspectoDistribucionMotor($db);
                    if ($motor->disponible()) {
                        $motor->asignarAliado((int)$resultado['prospecto_id']);
                    }
                } catch (Throwable $e) {
                    // El registro original ya quedó confirmado. Si el motor
                    // está ocupado, encolar para el reintento sin duplicar.
                    try {
                        if (isset($motor) && $motor instanceof ProspectoDistribucionMotor) {
                            $motor->encolarAliado((int)$resultado['prospecto_id']);
                        }
                    } catch (Throwable $pendiente) {
                        error_log('[prospectos_cola_aliado] '.get_class($pendiente));
                    }
                    error_log('[prospectos_asignacion_aliado_pendiente] '.
                        get_class($e).' codigo='.(int)$e->getCode());
                }
            }
        } catch (Throwable $e) {
            // Sin SQL, nombres, teléfonos, correo, tokens ni mensajes del proveedor.
            error_log('[prospectos_incorporacion_pendiente] '.get_class($e).' codigo='.(int)$e->getCode());
        } finally {if ($db instanceof mysqli) $db->close();}
    }

    public function incorporarRegistro(int $id): array
    {
        if ($id<=0 || !$this->repo->estructura()['disponible']) throw new RuntimeException('El almacén no está preparado.');
        $this->db->begin_transaction();
        try {
            // Serializar por la solicitud original evita dos identidades al conciliar
            // mientras el formulario recién guardado intenta incorporarse también.
            $row=$this->repo->consulta('SELECT * FROM formulario_registros WHERE id=? FOR UPDATE',[$id])->fetch_assoc();
            if (!$row) throw new DomainException('Registro original no encontrado.');
            $ap=$this->repo->consulta("SELECT * FROM formulario_aliado_procedencias WHERE tipo='registro' AND registro_id=? ORDER BY id FOR UPDATE",[$id])->fetch_all(MYSQLI_ASSOC);
            $existing=$this->repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$id);
            $persona=$existing?(int)$existing['prospecto_id']:$this->repo->crearPersona($row);
            $before=(int)$this->repo->consulta('SELECT COUNT(*) n FROM prospecto_origenes WHERE prospecto_id=?',[$persona])->fetch_assoc()['n'];
            $date=$this->fecha($row['created_at']??null);
            $common=['registro_tabla'=>'formulario_registros','registro_id'=>(string)$id,'formulario_id'=>'registro',
                'formulario_nombre'=>'Formulario de Registro','fecha_registro_original'=>$date,
                'fecha_registro_texto'=>$row['created_at']??null,'zona_horaria_origen'=>'UTC'];
            $root=$this->repo->guardarOrigen($persona,$common+['origen_padre_id'=>null,'tipo_origen'=>$ap?'ALIADO':'FORMULARIO_GENERAL',
                'sistema_origen'=>'IMPE_FORMULARIOS','tipo_registro'=>'REGISTRO','identificador_origen'=>(string)$id,
                'datos_originales_ref'=>'formulario_registros:'.$id,
                'fuente_hash'=>hash('sha256',json_encode([$row,$ap],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))]);
            $this->repo->preferencia($root,$row['horario_contacto']??null);
            foreach ($ap as $origin) {
                $child=$this->repo->guardarOrigen($persona,$common+['origen_padre_id'=>$root,'tipo_origen'=>'ALIADO',
                    'sistema_origen'=>'IMPE_FORMULARIOS','tipo_registro'=>'PROCEDENCIA_ALIADO','identificador_origen'=>(string)$origin['id'],
                    'aliado_id'=>(int)$origin['aliado_id'],'aliado_nombre'=>$origin['aliado_nombre'],
                    'datos_originales_ref'=>'formulario_aliado_procedencias:'.$origin['id']]);
                $program=$this->programa((string)$origin['programa_codigo'],(string)($row['perfil_interes']??''));
                $this->repo->interes($persona,$child,$program['codigo'],$program['nombre'],$date);
            }
            if (!$ap) {
                $program=$this->programa(null,(string)($row['perfil_interes']??''));
                $this->repo->interes($persona,$root,$program['codigo'],$program['nombre'],$date);
            }
            $after=(int)$this->repo->consulta('SELECT COUNT(*) n FROM prospecto_origenes WHERE prospecto_id=?',[$persona])->fetch_assoc()['n'];
            $this->db->commit();
            return ['prospecto_id'=>$persona,'nuevo'=>$existing===null,'procedencias_nuevas'=>$after-$before];
        } catch (Throwable $e) {$this->db->rollback();throw $e;}
    }
    private function fecha(?string $v): ?string
    {
        if ($v===null) return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d H:i:s')===$v && $v>='1000-01-01 00:00:00' ? $v : null;
    }
    private function programa(?string $codigo,string $nombre): array
    {
        // Sólo correspondencias explícitas y únicas con el catálogo; nunca títulos
        // de campañas, nombres de archivo ni parecido de palabras.
        $rows=$codigo!==null
            ? $this->repo->consulta('SELECT codigo,nombre FROM formulario_programas WHERE BINARY codigo=?',[$codigo])->fetch_all(MYSQLI_ASSOC)
            : $this->repo->consulta('SELECT codigo,nombre FROM formulario_programas WHERE BINARY nombre=?',[$nombre])->fetch_all(MYSQLI_ASSOC);
        return count($rows)===1 ? $rows[0] : ['codigo'=>null,'nombre'=>$nombre!==''?$nombre:($codigo??'')];
    }
}
