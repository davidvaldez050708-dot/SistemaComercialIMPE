<?php
final class TelefoniaBorradorService
{
    private $db;
    public function __construct($db) {$this->db=$db;}
    public static function validarId(string $id): bool {return (bool)preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$id);}
    public static function limpiar(array $datos): array
    {
        $limites=['nombre'=>180,'persona'=>140,'telefono'=>40,'correo'=>160,'notas'=>1000,
            'contacto_nombre'=>140,'contacto_cargo'=>150,'nombre_entidad'=>180,'persona_atendio'=>140,
            'telefono_capturado'=>40,'correo_capturado'=>160,'observacion'=>1000,
            'nuevo_contacto_nombre'=>140,'nuevo_contacto_cargo'=>150,'nuevo_telefono_contacto'=>40,
            'nuevo_correo_contacto'=>160,'decision'=>30,'seguimiento_id'=>12,'estado_id'=>12,'municipio_id'=>12,
            'nombre_entidad_actualizado'=>180,'actualizar_datos_llamada'=>1,
            'tipo_entidad'=>30,'resultado'=>40,'proxima_accion'=>40,'proxima_accion_at'=>30,
            'verificacion_telefono_confirmado'=>1,'verificacion_correo_confirmado'=>1,
            'verificacion_contacto_confirmado'=>1,'descartar'=>1,'motivo_descarte'=>500,
            'catalogo_version'=>4,'contacto_antes_corte'=>20,'contacto_id'=>12,'contacto_persona'=>150,
            'extension'=>10,'guardar_contacto'=>1,'prospecto_asignacion_id'=>12,'prospecto_nombre'=>150,
            'resultados_seleccionados'=>2000,'resultado_principal'=>80,'envio_informacion'=>30,
            'motivo_sin_whatsapp'=>500,'confirmacion_manual_sdk'=>1,'institucion_nombre'=>180,
            'intento_id'=>20,'pbx_call_id'=>100,'sdk_session_id'=>100,'sdk_reference'=>100,
            'llamada_pbx_id'=>100,'origen_llamada'=>30,'canal'=>30,'nota'=>1000];
        $out=[];
        foreach ($limites as $campo=>$max) {
            if (!isset($datos[$campo])) continue;
            if (!is_string($datos[$campo]) || !preg_match('//u',$datos[$campo]) || mb_strlen($datos[$campo],'UTF-8')>$max) {
                throw new InvalidArgumentException('Uno de los datos del borrador no es válido.');
            }
            $out[$campo]=$datos[$campo];
        }
        return $out;
    }
    public function leer(int $usuario,string $id): ?array
    {
        try {
            $stmt=$this->db->prepare('SELECT datos_json FROM telefonia_borradores WHERE usuario_id=? AND referencia=BINARY ?');
            if (!$stmt) {if ((int)$this->db->errno===1146) return null;throw new RuntimeException('No se pudo recuperar el borrador.');}
            $stmt->bind_param('is',$usuario,$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
            $data=$row?json_decode($row['datos_json'],true):null;
            return empty($data['closed'])?$data:null;
        } catch (mysqli_sql_exception $e) {if ($e->getCode()===1146) return null;throw $e;}
    }
    /** Sólo captura del usuario; no acredita llamadas ni modifica indicadores. */
    public function pendientes(int $usuario): array
    {
        $stmt=$this->db->prepare("SELECT referencia,datos_json FROM telefonia_borradores WHERE usuario_id=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(datos_json),datos_json,'{}'),'$.closed')),'false')<>'true' ORDER BY updated_at,referencia");
        $stmt->bind_param('i',$usuario);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $out=[];
        foreach($rows as $row){
            $data=json_decode($row['datos_json'],true);
            if(is_array($data)&&empty($data['closed']))$out[]=['referencia'=>$row['referencia'],'borrador'=>$data];
        }
        return $out;
    }

    private static function validarReferencias(int $usuario,array $ids): array
    {
        if($usuario<1||!$ids||count($ids)>3)throw new InvalidArgumentException('Referencias inválidas.');
        foreach($ids as $id)if(!is_string($id)||!self::validarId($id))throw new InvalidArgumentException('Llamada inválida.');
        $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);return $ids;
    }

    private static function limpiarContexto(array $contexto,int $revision):array
    {
        if(!$contexto)return [];
        if(!in_array($contexto['adaptador']??'', ['VENTAS','ANALISTA','RECEPCION','SEGUIMIENTO'],true))
            throw new InvalidArgumentException('Captura inválida.');
        $clean=['adaptador'=>$contexto['adaptador'],'pendiente'=>true];
        if(array_key_exists('cierre_local',$contexto)){
            $local=$contexto['cierre_local'];
            if(!is_array($local)||!is_int($local['at']??null)||$local['at']<1||$local['at']>$revision||
                !is_bool($local['transferencia']??null)||!is_bool($local['conciliado']??false))
                throw new InvalidArgumentException('Observación local de captura inválida.');
            // Aviso de interfaz: jamás se usa como prueba PBX ni resultado comercial.
            $clean['cierre_local']=['at'=>$local['at'],'transferencia'=>$local['transferencia'],
                'conciliado'=>$local['conciliado']??false];
        }
        return $clean;
    }

    public function guardar(int $usuario,array $ids,array $datos,int $revision,array $contexto=[]): array
    {
        $ids=self::validarReferencias($usuario,$ids);$datos=self::limpiar($datos);
        if($revision<1)throw new InvalidArgumentException('Revisión inválida.');
        $contexto=self::limpiarContexto($contexto,$revision);
        $this->db->begin_transaction();
        try {
            $previous=[];
            // Primero bloquear TODOS los alias. Ninguno se modifica hasta
            // verificar revisiones, cierres y conflictos del conjunto completo.
            foreach($ids as $id){
                $stmt=$this->db->prepare("INSERT IGNORE INTO telefonia_borradores (usuario_id,referencia,datos_json,expires_at) VALUES (?,?,'{}',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 YEAR))");
                $stmt->bind_param('is',$usuario,$id);$stmt->execute();$stmt->close();
                $stmt=$this->db->prepare('SELECT datos_json FROM telefonia_borradores WHERE usuario_id=? AND referencia=BINARY ? FOR UPDATE');
                $stmt->bind_param('is',$usuario,$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
                if(!$row)throw new DomainException('La referencia difiere de otra captura. No se reemplazó.');
                $data=json_decode($row['datos_json'],true);
                if(!is_array($data))throw new DomainException('Existe una captura que requiere revisión. No se reemplazó.');
                if(!empty($data['closed']))throw new DomainException('La atención ya fue guardada; el borrador está cerrado.');
                if((int)($data['saved_at']??0)>$revision)throw new DomainException('Existe un borrador más reciente. Recupera la captura antes de reemplazarla.');
                $previous[]=$data;
            }
            usort($previous,static fn($a,$b)=>(int)($a['saved_at']??0)<=>(int)($b['saved_at']??0));
            $merged=[];$latest=[];$revisionValues=[];$receipts=[];
            foreach($previous as $data){
                foreach((array)($data['aplicaciones']??[]) as $key=>$receipt){
                    if(isset($receipts[$key])&&$receipts[$key]!==$receipt)
                        throw new DomainException('Las referencias contienen confirmaciones distintas. No se fusionaron.');
                    $receipts[$key]=$receipt;
                }
                $priorValues=self::limpiar((array)($data['valores']??[]));$at=(int)($data['saved_at']??0);
                foreach($priorValues as $field=>$value){
                    if(isset($revisionValues[$at][$field]) && $revisionValues[$at][$field]!==$value && !array_key_exists($field,$datos))
                        throw new DomainException('Dos capturas de la misma revisión difieren. Revisa los datos antes de unir sus referencias.');
                    $revisionValues[$at][$field]=$value;
                }
                $merged=array_replace($merged,$priorValues);$latest=$data;
            }
            // Cada formulario aporta sus campos. No borra los que pertenecen
            // a otro adaptador de la MISMA llamada. Un vacío explícito sí limpia.
            $saved=['saved_at'=>$revision,'valores'=>array_replace($merged,$datos)];
            if($contexto)$saved['contexto']=array_replace((array)($latest['contexto']??[]),$contexto);
            elseif(!empty($latest['contexto']))$saved['contexto']=$latest['contexto'];
            if($receipts)$saved['aplicaciones']=$receipts;
            $json=json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            foreach($previous as $data){
                if((int)($data['saved_at']??0)===$revision && json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)!==$json)
                    throw new DomainException('Existe otra captura con la misma revisión. No se reemplazó.');
            }
            foreach($ids as $id){
                $stmt=$this->db->prepare('UPDATE telefonia_borradores SET datos_json=?,updated_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 YEAR) WHERE usuario_id=? AND referencia=BINARY ?');
                $stmt->bind_param('sis',$json,$usuario,$id);$stmt->execute();$stmt->close();
            }
            $this->db->commit();return $saved;
        }catch(Throwable $error){$this->db->rollback();throw $error;}
    }
    public function eliminar(int $usuario,array $ids,?int $revision=null): void
    {
        $ids=self::validarReferencias($usuario,$ids);
        $this->db->begin_transaction();
        try{
            foreach($ids as $id){
                $stmt=$this->db->prepare("INSERT IGNORE INTO telefonia_borradores (usuario_id,referencia,datos_json,expires_at) VALUES (?,?,'{}',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 YEAR))");
                $stmt->bind_param('is',$usuario,$id);$stmt->execute();$stmt->close();
                $stmt=$this->db->prepare('SELECT referencia,datos_json FROM telefonia_borradores WHERE usuario_id=? AND referencia=BINARY ? FOR UPDATE');
                $stmt->bind_param('is',$usuario,$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
                if(!$row)throw new DomainException('La referencia difiere de otra captura. No se reemplazó.');
                $prior=json_decode($row['datos_json'],true);
                if(!is_array($prior))throw new DomainException('La captura requiere revisión antes de cerrar.');
                if(!empty($prior['closed']))continue;
                if(empty($prior['closed']) && (($revision!==null && (int)($prior['saved_at']??0)>$revision) ||
                    ($revision===null && !empty($prior['contexto']))))
                    throw new DomainException('La captura cambió mientras se guardaba el resultado. Se conservó la edición más reciente.');
                // Cerrar impide reabrir por una petición vieja. Conservar además
                // la captura íntegra: un ACK no destruye los datos aportados.
                $prior['closed']=true;$prior['closed_revision']=$revision??(int)($prior['saved_at']??0);
                $closed=json_encode($prior,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $stmt=$this->db->prepare('UPDATE telefonia_borradores SET datos_json=?,updated_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 YEAR) WHERE usuario_id=? AND referencia=BINARY ?');
                $stmt->bind_param('sis',$closed,$usuario,$id);$stmt->execute();$stmt->close();
            }
            $this->db->commit();
        }catch(Throwable $error){$this->db->rollback();throw $error;}
    }

    /** Se invoca dentro de la MISMA transacción del resultado nativo validado.
     * No abre ni confirma transacciones y no acredita evidencia o resultados.
     * El identificador nativo sólo puede proceder del INSERT autorizado del
     * adaptador comercial, nunca de un valor suministrado por el navegador.
     */
    public function confirmarAplicacionEnTransaccion(int $usuario,array $ids,int $revision,
        string $requestId,string $adaptador,string $tipo,int $nativeId,array $valores=[]):array
    {
        $ids=self::validarReferencias($usuario,$ids);$valores=self::limpiar($valores);
        $types=['VENTAS'=>'RESULTADO_VENTAS','RECEPCION'=>'ATENCION_RECEPCION',
            'ANALISTA'=>'INTERACCION_VINCULACION','SEGUIMIENTO'=>'INTERACCION_VINCULACION'];
        if($revision<1||$nativeId<1||!preg_match('/^[a-zA-Z0-9_-]{12,100}$/D',$requestId)||
            ($types[$adaptador]??null)!==$tipo)
            throw new InvalidArgumentException('Confirmación de captura inválida.');
        $canonical=$valores;ksort($canonical);
        $receipt=['request_id'=>$requestId,'revision'=>$revision,'adaptador'=>$adaptador,'tipo'=>$tipo,'id'=>$nativeId,
            'huella'=>hash('sha256',json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
        $rows=[];$existing=null;
        // Mantener el mismo orden de locks que guardar/eliminar. Validar el
        // conjunto completo antes de escribir cualquiera de sus alias.
        foreach($ids as $id){
            $s=$this->db->prepare("INSERT IGNORE INTO telefonia_borradores(usuario_id,referencia,datos_json,expires_at) VALUES(?,?,'{}',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 YEAR))");
            $s->bind_param('is',$usuario,$id);$s->execute();$s->close();
            $s=$this->db->prepare('SELECT datos_json FROM telefonia_borradores WHERE usuario_id=? AND referencia=BINARY ? FOR UPDATE');
            $s->bind_param('is',$usuario,$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            $prior=$row?json_decode($row['datos_json'],true):null;
            if(!is_array($prior))throw new DomainException('No se pudo confirmar la misma captura.');
            $saved=$prior['aplicaciones'][$requestId]??null;
            if($saved!==null){
                if(!is_array($saved)||array_intersect_key($saved,$receipt)!==$receipt)
                    throw new DomainException('El intento ya confirmó otro registro. No se reemplazó.');
                $existing??=$saved;
            }elseif(!empty($prior['closed'])){
                throw new DomainException('La captura ya tiene otro cierre. No se reemplazó.');
            }
            $rows[$id]=$prior;
        }
        $receipt=$existing??($receipt+['confirmado_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
        foreach($rows as $id=>$prior){
            if(isset($prior['aplicaciones'][$requestId]))continue;
            $newer=(int)($prior['saved_at']??0)>$revision;
            $saved=$prior;
            $saved['aplicaciones'][$requestId]=$receipt;
            // Un ACK del envío anterior no borra ni cierra una edición nueva.
            if(!$newer){
                $saved['saved_at']=$revision;
                $saved['valores']=array_replace((array)($prior['valores']??[]),$valores);
                $saved['contexto']=array_replace((array)($prior['contexto']??[]),['adaptador'=>$adaptador,'pendiente'=>false]);
                $saved['closed']=true;$saved['closed_revision']=$revision;
            }
            if($saved===$prior)continue;
            $json=json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $s=$this->db->prepare('UPDATE telefonia_borradores SET datos_json=?,updated_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 YEAR) WHERE usuario_id=? AND referencia=BINARY ?');
            $s->bind_param('sis',$json,$usuario,$id);$s->execute();$s->close();
        }
        return $receipt;
    }

    public function leerAplicacion(int $usuario,array $ids,string $requestId):?array
    {
        $ids=self::validarReferencias($usuario,$ids);$found=null;
        if(!preg_match('/^[a-zA-Z0-9_-]{12,100}$/D',$requestId))throw new InvalidArgumentException('Intento inválido.');
        foreach($ids as $id){
            $s=$this->db->prepare('SELECT datos_json FROM telefonia_borradores WHERE usuario_id=? AND referencia=BINARY ?');
            $s->bind_param('is',$usuario,$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            $data=$row?json_decode($row['datos_json'],true):null;
            $receipt=$data['aplicaciones'][$requestId]??null;
            if($receipt===null)continue;
            if(!is_array($receipt)||($found!==null&&$found!==$receipt))
                throw new DomainException('Las confirmaciones de la captura requieren revisión.');
            $found=$receipt;
        }
        return $found;
    }
}
