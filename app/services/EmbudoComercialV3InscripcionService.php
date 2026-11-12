<?php
/**
 * V3 · vinculación auditable de una solicitud YA guardada en
 * formulario_inscripciones con el prospecto asignado al asesor.
 *
 * No genera inscripciones, pagos ni enrolamientos ficticios.
 * No admite comparar únicamente teléfono ni seleccionar formularios ajenos.
 */
final class EmbudoComercialV3InscripcionService
{
    private mysqli $db;

    public function __construct(mysqli $db) {$this->db=$db;}

    private static function autorizado(int $usuario): void
    {
        require_once __DIR__.'/../helpers/PermissionHelper.php';
        require_once __DIR__.'/EmbudoComercialV3AccesoControlado.php';
        if ($usuario<1 || (int)($_SESSION['usuario_id']??0)!==$usuario ||
            !EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuario) ||
            !tienePermiso('ventas.embudo.ver') ||
            !tienePermiso('ventas.embudo.mover')) {
            throw new DomainException('No tienes permiso para vincular una solicitud de inscripción.');
        }
    }

    private function asignacion(int $usuario,int $asignacion,bool $bloquear): array
    {
        if ($asignacion<1) throw new DomainException('Asignación comercial inválida.');
        $sql="SELECT a.id,a.prospecto_id,a.asesor_id,a.vigente,a.asignado_at,
                 p.correo,p.telefono_normalizado,
                 COALESCE(e.etapa,'NUEVO') etapa,
                 s.subetapa
              FROM prospecto_asignaciones a
              JOIN prospectos_comerciales p ON p.id=a.prospecto_id
              LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
              LEFT JOIN prospecto_embudo_subetapas s ON s.asignacion_id=a.id
              WHERE a.id=?".($bloquear?' FOR UPDATE':'');
        $st=$this->db->prepare($sql);
        try {
            $st->bind_param('i',$asignacion);
            $st->execute();
            $row=$st->get_result()->fetch_assoc();
        } finally {$st->close();}
        if (!$row || (int)$row['asesor_id']!==$usuario ||
            (int)$row['vigente']!==1 ||
            (string)$row['etapa']!=='POTENCIAL') {
            throw new DomainException('Solo puedes revisar solicitudes de tus Potenciales vigentes.');
        }
        if ((string)($row['subetapa']??'')==='NO_CONTACTAR') {
            throw new DomainException('La solicitud requiere revisión institucional por restricción No contactar.');
        }
        return $row;
    }

    /** El formulario público histórico solo admite móvil mexicano de 10 dígitos. */
    private static function identidad(array $a): array
    {
        $correo=mb_strtolower(trim((string)($a['correo']??'')),'UTF-8');
        $tel=trim((string)($a['telefono_normalizado']??''));
        $digitos=preg_replace('/\\D+/','',$tel)??'';
        if (strlen($correo)<5 || strlen($correo)>190 ||
            !filter_var($correo,FILTER_VALIDATE_EMAIL) ||
            !in_array(strlen($digitos),[12,13],true) ||
            !(
                strlen($digitos)===12 && substr($digitos,0,2)==='52' ||
                strlen($digitos)===13 && substr($digitos,0,3)==='521'
            )) {
            throw new DomainException(
                'La identidad del prospecto requiere correo válido y móvil mexicano acreditados.');
        }
        return [substr($digitos,-10),$correo];
    }

    private function candidatas(array $a): array
    {
        [$movil,$correo]=self::identidad($a);
        // Se descarta cualquier solicitud anterior a la asignación actual,
        // dejando un margen de seis horas por diferencias de zona horaria.
        $sql="SELECT f.id,f.created_at
              FROM formulario_inscripciones f
              WHERE BINARY f.movil=BINARY ?
                AND LOWER(TRIM(f.correo))=?
                AND f.created_at>=DATE_SUB(?,INTERVAL 6 HOUR)
              ORDER BY f.created_at DESC,f.id DESC
              LIMIT 2";
        $st=$this->db->prepare($sql);
        try {
            $fecha=(string)$a['asignado_at'];
            $st->bind_param('sss',$movil,$correo,$fecha);
            $st->execute();
            return $st->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {$st->close();}
    }

    public function candidato(int $usuario,int $asignacion): array
    {
        self::autorizado($usuario);
        $a=$this->asignacion($usuario,$asignacion,false);
        $rows=$this->candidatas($a);
        if (count($rows)>1) {
            return [
                'estado'=>'REVISION_INSTITUCIONAL',
                'mensaje'=>'Existen varias solicitudes de inscripción coincidentes. Solicita conciliación institucional.'
            ];
        }
        if (!$rows) {
            return [
                'estado'=>'SIN_SOLICITUD',
                'mensaje'=>'Todavía no se encontró un formulario de inscripción que coincida con el móvil y correo de este prospecto.'
            ];
        }
        return [
            'estado'=>'CANDIDATA',
            'formulario_id'=>(int)$rows[0]['id'],
            'fecha'=>(string)$rows[0]['created_at']
        ];
    }

    public function vincular(int $usuario,int $asignacion,int $formularioId,
        string $motivo,bool $confirmar): array
    {
        self::autorizado($usuario);
        $motivo=trim($motivo);
        if ($formularioId<1 || !$confirmar || mb_strlen($motivo)<8 ||
            mb_strlen($motivo)>125) {
            throw new DomainException('Identifica la solicitud y confirma la gestión con un motivo válido.');
        }
        $this->db->begin_transaction();
        try {
            $a=$this->asignacion($usuario,$asignacion,true);
            $rows=$this->candidatas($a);
            if (count($rows)!==1 || (int)$rows[0]['id']!==$formularioId) {
                throw new DomainException('La solicitud no coincide o requiere conciliación institucional.');
            }
            // Única vinculación de la solicitud, incluso bajo operaciones
            // concurrentes desde distintos asesores o distintas asignaciones.
            $st=$this->db->prepare(
                "INSERT INTO prospecto_embudo_inscripciones_v3
                    (asignacion_id,prospecto_id,formulario_inscripcion_id,vinculado_por,
                     evidencia_tipo,verificado_at)
                 VALUES (?,?,?,?,'FORMULARIO_INSCRIPCION',UTC_TIMESTAMP())");
            $prospecto=(int)$a['prospecto_id'];
            $st->bind_param('iiii',$asignacion,$prospecto,$formularioId,$usuario);
            $st->execute();$st->close();

            $nuevo='PROCESO_INSCRIPCION';
            $previo='POTENCIAL';
            $razon=mb_substr('Solicitud institucional #'.$formularioId.
                ' verificada: '.$motivo,0,190);

            $st=$this->db->prepare(
                "INSERT INTO prospecto_embudo_etapas
                    (asignacion_id,etapa,cambiado_por,actualizado_at)
                 VALUES (?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE etapa=VALUES(etapa),
                   cambiado_por=VALUES(cambiado_por),
                   actualizado_at=VALUES(actualizado_at)");
            $st->bind_param('isi',$asignacion,$nuevo,$usuario);
            $st->execute();$st->close();

            $subAnterior=$a['subetapa'];
            $subNueva='SOLICITUD_INICIADA';
            $st=$this->db->prepare(
                "INSERT INTO prospecto_embudo_subetapas
                    (asignacion_id,subetapa,actualizado_por,actualizado_at,version)
                 VALUES (?,?,?,UTC_TIMESTAMP(),1)
                 ON DUPLICATE KEY UPDATE subetapa=VALUES(subetapa),
                    actualizado_por=VALUES(actualizado_por),
                    actualizado_at=VALUES(actualizado_at),version=version+1");
            $st->bind_param('isi',$asignacion,$subNueva,$usuario);
            $st->execute();$st->close();

            $st=$this->db->prepare(
                "INSERT INTO prospecto_embudo_subetapas_historial
                    (asignacion_id,etapa_en_evento,subetapa_anterior,subetapa_nueva,
                     motivo,origen,usuario_id,creado_at)
                 VALUES (?,?,?,?,?,'ASESOR_V3',?,UTC_TIMESTAMP())");
            $st->bind_param('issssi',$asignacion,$nuevo,$subAnterior,$subNueva,$razon,$usuario);
            $st->execute();$st->close();

            $st=$this->db->prepare(
                "INSERT INTO prospecto_embudo_movimientos
                    (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
                 VALUES (?,?,?,?,?,UTC_TIMESTAMP())");
            $st->bind_param('isssi',$asignacion,$previo,$nuevo,$razon,$usuario);
            $st->execute();$mov=(int)$this->db->insert_id;$st->close();
            $this->db->commit();
            return [
                'etapa'=>$nuevo,'subetapa'=>$subNueva,
                'movimiento_id'=>$mov,'formulario_inscripcion_id'=>$formularioId
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode()===1062) {
                throw new DomainException(
                    'Esta solicitud ya quedó vinculada a otro expediente. Solicita revisión institucional.',0,$e);
            }
            throw $e;
        }
    }
}
