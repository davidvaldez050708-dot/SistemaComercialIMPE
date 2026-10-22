<?php
require_once __DIR__ . '/TelefoniaExtensionService.php';
require_once __DIR__ . '/TelefoniaContactosService.php';
require_once __DIR__ . '/../../config/db_connection.php';

/**
 * Clasificación humana de llamadas salientes de Ventas.
 * El evento de Zadarma es la fuente de identidad; NO se deduce conversación
 * real por el estado ANSWERED (que también incluye buzón y locuciones).
 */
class TelefoniaResultadoVentasService
{
    public const CONVERSACION = 'CONVERSACION_PERSONA';
    public const BUZON = 'BUZON_VOZ';
    public const FUERA_HORARIO = 'FUERA_HORARIO';
    public const NUMERO_INEXISTENTE = 'NUMERO_INEXISTENTE';
    public const SIN_RESPUESTA = 'SIN_RESPUESTA';
    public const OCUPADO = 'OCUPADO';
    public const MENSAJE_AUTOMATICO = 'MENSAJE_AUTOMATICO';
    public const OTRO_SIN_CONTACTO = 'OTRO_SIN_CONTACTO';

    public static function opciones(): array
    {
        return [
            self::CONVERSACION => 'Conversación con el prospecto',
            'INTERESADO' => 'Prospecto interesado',
            'SOLICITA_INFORMACION' => 'Solicita información',
            'REQUIERE_SEGUIMIENTO' => 'Requiere seguimiento',
            'NO_INTERESADO' => 'No interesado',
            'COMUNICACION_INTERRUMPIDA' => 'Comunicación interrumpida',
            'OTRO' => 'Otro resultado con observaciones',
            self::BUZON => 'Buzón de voz',
            self::FUERA_HORARIO => 'Fuera de horario de servicio',
            self::NUMERO_INEXISTENTE => 'Número inexistente o incorrecto',
            self::SIN_RESPUESTA => 'No contestó',
            self::OCUPADO => 'Línea ocupada',
            self::MENSAJE_AUTOMATICO => 'Mensaje de operadora o grabadora',
            self::OTRO_SIN_CONTACTO => 'Otra situación sin contacto'
        ];
    }

    public static function esConversacion(?string $resultado): bool
    {
        return in_array($resultado, [self::CONVERSACION,'INTERESADO','SOLICITA_INFORMACION','REQUIERE_SEGUIMIENTO','NO_INTERESADO'], true);
    }

    private $db;

    public function __construct(?mysqli $db=null)
    {
        $this->db = $db ?? (new Database())->connect();
        $this->asegurarEstructura();
    }

    public function asegurarEstructura(): void
    {
        $ok = $this->db->query(
            "CREATE TABLE IF NOT EXISTS telefonia_ventas_resultados (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id INT NOT NULL,
                extension VARCHAR(20) NOT NULL,
                pbx_call_id VARCHAR(100) NOT NULL,
                resultado VARCHAR(36) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_ventas_llamada_extension (pbx_call_id, extension),
                KEY idx_ventas_usuario_fecha (usuario_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        if (!$ok) {
            throw new RuntimeException('No fue posible preparar los resultados de llamadas de Ventas.');
        }
    }

    public static function extensionPropia(int $usuarioId): string
    {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('Sesión no válida.');
        }

        $asignacion = (new TelefoniaExtensionService())->resolverParaUsuario($usuarioId);
        $extension = trim((string)($asignacion['extension'] ?? ''));
        if (
            !$asignacion ||
            empty($asignacion['permite_salientes']) ||
            !preg_match('/^\d{3,6}$/', $extension)
        ) {
            throw new RuntimeException('No tienes una extensión activa para clasificar llamadas.');
        }
        return $extension;
    }

    /**
     * El resultado es una declaración humana; no es un cierre de Zadarma.
     * Para la única excepción sin END PBX se exige la operación de transferencia
     * exacta, propietario histórico, ruta verificada y ANSWER del asesor.
     * No se infiere por teléfono, hora, RECORD, SIP local ni el fin de Recepción.
     */
    private function transferenciaAtendidaPropia(int $usuarioId, array $call): bool
    {
        $ext=(string)($call['extension']??'');
        $ref=(string)($call['pbx_call_id']??'');
        $source=(string)($call['origen_extension']??'');
        if(empty($call['conectada']) || $source==='' || $source===$ext ||
            empty($call['solicitud_id']) || ($call['direccion']??'')!=='Entrante') return false;
        $request=(int)$call['solicitud_id'];
        $stmt=$this->db->prepare("SELECT d.ruta_json FROM telefonia_transferencia_operaciones o
            JOIN telefonia_disponibilidad d ON d.id=?
                AND d.destino_usuario=o.destino_usuario
                AND d.origen_usuario=o.origen_usuario
                AND d.origen_extension=BINARY o.origen_extension
                AND d.destino_extension=BINARY o.destino_extension
                AND d.referencia=BINARY o.referencia
            WHERE o.destino_usuario=? AND o.destino_extension=BINARY ?
                AND o.origen_extension=BINARY ? AND o.referencia=BINARY ?
                AND EXISTS (SELECT 1 FROM telefonia_zadarma_eventos e
                    WHERE e.pbx_call_id=BINARY o.referencia AND e.internal=BINARY o.destino_extension
                      AND e.evento='NOTIFY_ANSWER' AND e.id>o.evento_desde)
            LIMIT 2");
        $stmt->bind_param('iisss',$request,$usuarioId,$ext,$source,$ref);
        $stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if(count($rows)!==1)return false;
        $route=json_decode((string)$rows[0]['ruta_json'],true)?:[];
        return ($route['tipo']??'')==='PROSPECTO'
            && (int)($route['responsable_id']??0)===$usuarioId
            && (string)($route['extension']??'')===$ext
            && (string)($route['referencia_llamada']??'')===$ref;
    }

    /**
     * Permite resultado definitivo sin cierre PBX SOLO en transferencia
     * comercial atendida y vinculada al asesor; PBX/audio siguen pendientes.
     */
    public function guardar(int $usuarioId, string $pbxCallId, string $resultado, array $datos=[]): array
    {
        require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
        $svc=new TelefoniaVentasLlamadasService($this->db);$svc->sincronizar($usuarioId);
        $call=$svc->obtener($usuarioId,trim($pbxCallId),trim((string)($datos['extension']??'')));
        if(!array_key_exists($resultado,self::opciones()))throw new InvalidArgumentException('Selecciona un resultado comercial válido.');
        $pbxPendiente=empty($call['finalizada']);
        if($pbxPendiente&&!$this->transferenciaAtendidaPropia($usuarioId,$call))
            throw new DomainException('No se ha acreditado la transferencia atendida por tu extensión. No se guardó el resultado.');
        if(self::esConversacion($resultado)&&!$call['conectada'])throw new DomainException('Falta la confirmación PBX de conexión de tu extensión.');
        $note=trim((string)($datos['observacion']??''));$name=trim((string)($datos['prospecto_nombre']??$call['nombre']));
        if(mb_strlen($note)>1000||mb_strlen($name)>150)throw new InvalidArgumentException('Limita el nombre a 150 caracteres y las observaciones a 1000.');
        if(in_array($resultado,['OTRO',self::OTRO_SIN_CONTACTO],true)&&mb_strlen($note)<3)throw new InvalidArgumentException('Describe lo que ocurrió en las observaciones.');
        $contact=(int)($datos['contacto_id']??0);$contact=$contact>0?$contact:null;
        if($contact!==null){
            $s=$this->db->prepare('SELECT id FROM telefonia_contactos_personales WHERE id=? AND usuario_id=?');$s->bind_param('ii',$contact,$usuarioId);$s->execute();$own=$s->get_result()->fetch_assoc();$s->close();
            if(!$own)throw new DomainException('El contacto no pertenece a tu agenda.');
        }
        $next=trim((string)($datos['proxima_accion_at']??''));$next=str_replace('T',' ',$next);
        if($next!==''){
            $format=strlen($next)===16?'!Y-m-d H:i':'!Y-m-d H:i:s';$date=DateTimeImmutable::createFromFormat($format,$next);
            if(!$date||$date->format(substr($format,1))!==$next||$date->getTimestamp()<=time())throw new InvalidArgumentException('Indica una próxima llamada con fecha futura válida.');
            $next=$date->format('Y-m-d H:i:s');
        }else $next=null;
        if($resultado==='REQUIERE_SEGUIMIENTO'&&$next===null)throw new InvalidArgumentException('Programa la fecha del seguimiento telefónico.');
        $cols=array_column($this->db->query('SHOW COLUMNS FROM telefonia_ventas_resultados')->fetch_all(MYSQLI_ASSOC),'Field');
        if(!in_array('observacion',$cols,true))throw new DomainException('Falta aplicar la migración comercial antes de guardar resultados.');
        $extension=$call['extension'];$pbxCallId=$call['pbx_call_id'];
        require_once __DIR__.'/TelefoniaDevolucionVentasService.php';
        $callbacks=null;
        if($this->db->query("SHOW TABLES LIKE 'telefonia_ventas_devolucion_intentos'")->num_rows){$callbacks=new TelefoniaDevolucionVentasService($this->db);$callbacks->reconciliar($usuarioId);}
        $saveContact=($datos['guardar_contacto']??'')==='1';
        if($saveContact&&$contact!==null)throw new InvalidArgumentException('Selecciona un contacto existente o guarda uno nuevo.');
        // Los constructores legacy que preparan tablas se ejecutan antes de la transacción.
        $agenda=$saveContact?new TelefoniaContactosService($this->db):null;
        $this->db->begin_transaction();
        try {
        // Resultado definitivo: no cambiar propietario ni sobrescribir un registro previo.
        $s=$this->db->prepare('INSERT INTO telefonia_ventas_resultados
            (usuario_id,extension,pbx_call_id,resultado,observacion,prospecto_nombre,contacto_id,proxima_accion_at)
            VALUES (?,?,?,?,?,?,?,?)');
        try{$s->bind_param('isssssis',$usuarioId,$extension,$pbxCallId,$resultado,$note,$name,$contact,$next);$s->execute();}
        catch(mysqli_sql_exception $e){if($e->getCode()===1062)throw new DomainException('El resultado de esta llamada ya se guardó y es definitivo.');throw $e;}
        finally{$s->close();}
        if($agenda){
            $saved=$agenda->guardar($usuarioId,$name,$call['numero'],['correo'=>(string)($datos['correo']??''),'observacion'=>mb_substr($note,0,500),'proxima_accion_at'=>'']);
            $contact=(int)$saved['id'];$update=$this->db->prepare('UPDATE telefonia_ventas_resultados SET contacto_id=? WHERE usuario_id=? AND extension=? AND pbx_call_id=?');$update->bind_param('iiss',$contact,$usuarioId,$extension,$pbxCallId);$update->execute();$update->close();
        }
        if($callbacks)$callbacks->aplicarResultado($usuarioId,$call,$resultado);
        $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
        return ['pbx_call_id'=>$pbxCallId,'extension'=>$extension,'resultado'=>$resultado,
            'etiqueta'=>self::opciones()[$resultado],'contacto_real'=>self::esConversacion($resultado),
            'pbx_pendiente'=>$pbxPendiente];
    }

    /**
     * El servicio de actividad consulta resultados de una extensión, sin
     * confiar en IDs enviados desde un navegador ni exponer otros usuarios.
     */
    public function consultarExtension(int $usuarioId, string $extension): array
    {
        if ($extension !== self::extensionPropia($usuarioId)) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT pbx_call_id, resultado
             FROM telefonia_ventas_resultados
             WHERE usuario_id = ? AND extension = ?
             AND updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        if (!$stmt) throw new RuntimeException('No se pudieron consultar los resultados.');
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $rs = $stmt->get_result();
        $map = [];
        while ($fila = $rs->fetch_assoc()) {
            $map[(string)$fila['pbx_call_id']] = (string)$fila['resultado'];
        }
        $stmt->close();
        return $map;
    }
}
