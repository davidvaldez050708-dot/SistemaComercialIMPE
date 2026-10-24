<?php
require_once __DIR__.'/AliadoRegistroService.php';
require_once __DIR__.'/AliadoDifusionService.php';
require_once __DIR__.'/AliadoAvanceService.php';

/** Adaptador de Compartir: origen explícito; la difusión territorial histórica no cambia. */
class AliadoCompartirService
{
    private mysqli $db;
    private AliadoModel $modelo;
    private AliadoDifusionService $difusion;
    public function __construct(?mysqli $db=null, ?AliadoDifusionService $difusion=null)
    {
        $this->db=$db??(new Database())->connect();
        $this->modelo=new AliadoModel();
        $this->difusion=$difusion??new AliadoDifusionService();
    }
    private function query(string $sql,array $params=[]): mysqli_result
    {
        $s=$this->db->prepare($sql);
        if ($params) $s->bind_param(str_repeat('s',count($params)),...$params);
        $s->execute(); return $s->get_result();
    }
    private function permiso(string $codigo): void
    {
        if (empty($_SESSION['usuario_id'])) throw new DomainException('Inicia sesión nuevamente.',401);
        if (!tienePermiso($codigo)) throw new DomainException('No tienes permiso para esta operación.',403);
    }
    private function origen(array $datos): array
    {
        $this->permiso('aliados.ver'); $this->permiso('convocatorias.ver');
        $tipo=(string)($datos['origen_tipo']??''); $id=(int)($datos['origen_id']??0);
        if (!in_array($tipo,['manual','institucional'],true)||$id<=0) throw new DomainException('Origen de aliado inválido.',422);
        if ($tipo==='manual') {
            $aliado=(new AliadoRegistroService($this->db))->origen($tipo,$id,puedeConsultarAliadosNacional());
        } else {
            $aliado=$this->modelo->obtenerAliado($id,(int)$_SESSION['usuario_id'],puedeConsultarAliadosNacional());
            if (!$aliado) throw new DomainException('No tienes acceso a este aliado.',403);
        }
        $ambito=(string)($datos['ambito']??'asociadas');
        if (!in_array($ambito,['asociadas','territorial'],true)||($tipo==='manual'&&$ambito!=='asociadas')) throw new DomainException('Ámbito de difusión inválido.',422);
        return ['tipo'=>$tipo,'id'=>$id,'aliado'=>$aliado,'ambito'=>$ambito];
    }
    private function canales(array $o): array
    {
        $correos=[]; $telefonos=[];
        $agregarCorreo=function($email)use(&$correos){$email=trim((string)$email);if(filter_var($email,FILTER_VALIDATE_EMAIL))$correos[strtolower($email)]=$email;};
        $agregarCorreo($o['aliado']['correo_contacto']??'');
        if ($o['tipo']==='manual') {
            $contactos=$this->query('SELECT id,telefono numero,etiqueta,whatsapp_confirmado confirmado_whatsapp,whatsapp_autorizado autorizado_whatsapp,preferido_difusion,correo FROM aliados_manuales_contactos WHERE aliado_id=? AND activo=1 ORDER BY preferido_difusion DESC,id',[$o['id']])->fetch_all(MYSQLI_ASSOC);
        } else $contactos=$this->modelo->obtenerContactosDifusion($o['aliado']);
        foreach ($contactos as $c) {
            $agregarCorreo($c['correo']??'');
            if (empty($c['confirmado_whatsapp'])||empty($c['autorizado_whatsapp'])) continue;
            $numero=preg_replace('/\D+/','',(string)$c['numero']);
            if(strlen($numero)===10)$numero='52'.$numero;
            if(strlen($numero)<11||strlen($numero)>15)continue;
            $telefonos[]=['id'=>(int)$c['id'],'telefono'=>$c['numero'],'telefono_whatsapp'=>$numero,'etiqueta'=>$c['etiqueta'],'disponible'=>true];
        }
        $correos=array_values($correos);
        $correoDisponible=tienePermiso('aliados.compartir_correo')&&(bool)$correos;
        $waDisponible=tienePermiso('aliados.preparar_whatsapp')&&(bool)$telefonos;
        return ['correo'=>['disponible'=>$correoDisponible,'destinatario'=>$correos[0]??'','destinatarios'=>$correoDisponible?$correos:[],'motivo'=>'No existe un correo válido o tu perfil no puede enviar correo.'],
            'whatsapp_manual'=>array_merge(['disponible'=>$waDisponible,'contactos'=>$waDisponible?$telefonos:[],'motivo'=>'No hay números confirmados y autorizados para WhatsApp, o falta el permiso.'],$waDisponible?$telefonos[0]:[])];
    }
    private function publicaciones(array $o): array
    {
        if($o['ambito']==='territorial') return $this->modelo->obtenerConvocatoriasDisponibles((int)$o['aliado']['estado_id']);
        return $this->modelo->obtenerConvocatoriasAsociadas($o['tipo'],$o['id']);
    }
    public function preparar(array $datos): array
    {
        $o=$this->origen($datos);
        if(!tieneAlgunPermiso(['aliados.compartir_correo','aliados.preparar_whatsapp']))throw new DomainException('No tienes canales autorizados.',403);
        // Una clave de operación emitida por servidor evita repetir un envío al reintentar HTTP.
        $nonce=bin2hex(random_bytes(24));
        $solicitudes=$_SESSION['aliado_compartir_operaciones']??[];
        $solicitudes[$nonce]=['origen'=>$o['tipo'].':'.$o['id'],'creado'=>time()];
        $_SESSION['aliado_compartir_operaciones']=array_slice($solicitudes,-40,null,true);
        $avance=(new AliadoAvanceService($this->db))->publicaciones($o['tipo'],$o['id'],$this->publicaciones($o));
        return ['ok'=>true,'aliado'=>$o['aliado'],'convocatorias'=>$avance['convocatorias'],'avance'=>$avance,'canales'=>$this->canales($o),'operacion_id'=>$nonce];
    }
    public function borrador(array $datos): array
    {
        $o=$this->origen($datos); $id=(int)($datos['convocatoria_id']??0);
        foreach($this->publicaciones($o) as $c)if((int)$c['id']===$id)return ['ok'=>true,'convocatoria'=>$c,'borrador'=>$this->difusion->construirBorrador($o['aliado'],$c),'borrador_whatsapp'=>$this->difusion->construirBorradorWhatsapp($o['aliado'],$c)];
        throw new DomainException('La convocatoria no pertenece a este aliado o no está publicada, habilitada y vigente.',409);
    }
    public function ejecutar(array $datos,string $canal): array
    {
        if(!in_array($canal,['CORREO','WHATSAPP_MANUAL'],true))throw new DomainException('Canal inválido.',422);
        $o=$this->origen($datos); $this->permiso($canal==='CORREO'?'aliados.compartir_correo':'aliados.preparar_whatsapp');
        $nonce=(string)($datos['operacion_id']??''); $operacion=$_SESSION['aliado_compartir_operaciones'][$nonce]??null;
        if(!$operacion||$operacion['origen']!==$o['tipo'].':'.$o['id']||$operacion['creado']<time()-7200)throw new DomainException('La operación venció. Vuelve a abrir Compartir.',409);
        $c=$this->borrador($datos)['convocatoria']; $canales=$this->canales($o);
        $destino=trim((string)($datos['destinatario']??''));
        if($canal==='CORREO'){
            if(!filter_var($destino,FILTER_VALIDATE_EMAIL)||!in_array($destino,$canales['correo']['destinatarios'],true))throw new DomainException('Selecciona un correo válido registrado para este aliado.',422);
        }else{
            $elegido=null;foreach($canales['whatsapp_manual']['contactos'] as $contacto)if((int)$contacto['id']===(int)($datos['contacto_id']??0))$elegido=$contacto;
            if(!$elegido)throw new DomainException('Selecciona un número confirmado y autorizado de este aliado.',422);
            $destino=$elegido['telefono'];
        }
        $mensaje=trim((string)($datos['mensaje']??''));$asunto=trim((string)($datos['asunto']??''));
        if($mensaje===''||mb_strlen($mensaje)>($canal==='CORREO'?20000:4096)||($canal==='CORREO'&&($asunto===''||mb_strlen($asunto)>255)))throw new DomainException('Revisa el asunto y el mensaje.',422);
        $firma=hash('sha256',json_encode([$o['ambito'],$c['id'],$canal,$destino,$asunto,$mensaje]));
        if(isset($operacion['firma'])&&$operacion['firma']!==$firma)throw new DomainException('La operación ya fue utilizada. Vuelve a abrir Compartir.',409);
        if(isset($operacion['respuesta']))return $operacion['respuesta'];
        $lock='impe-difusion-'.substr(hash('sha256',$o['tipo'].':'.$o['id'].':'.$c['id'].':'.$canal),0,40);
        if(!(int)$this->query('SELECT GET_LOCK(?,2) adquirido',[$lock])->fetch_assoc()['adquirido'])throw new DomainException('Ya se está procesando esta difusión.',409);
        try{
            if($o['tipo']==='manual'){
                $prefix='Convocatoria #'.$c['id'].' · ';
                $previo=$this->query('SELECT id FROM aliados_manuales_difusiones WHERE aliado_id=? AND canal=? AND LEFT(nota,CHAR_LENGTH(?))=? LIMIT 1',[$o['id'],$canal==='CORREO'?'CORREO':'WHATSAPP',$prefix,$prefix])->num_rows;
            }else $previo=(bool)$this->modelo->obtenerUltimoEnvioExitoso($o['id'],(int)$c['id'],$canal);
            if($previo&&empty($datos['confirmar_reenvio']))return ['ok'=>false,'codigo_http'=>409,'requiere_confirmacion'=>true,'mensaje'=>'Esta convocatoria ya tiene una difusión registrada por este canal. Confirma únicamente si realizarás otra.'];
            $_SESSION['aliado_compartir_operaciones'][$nonce]['firma']=$firma;
            $resultado=$canal==='CORREO'?$this->difusion->enviarCorreoPreparado((int)$_SESSION['usuario_id'],$o['aliado'],$c,$asunto,$mensaje,$destino):['ok'=>true];
            if(empty($resultado['ok'])){
                $_SESSION['aliado_compartir_operaciones'][$nonce]['respuesta']=$resultado;return $resultado;
            }
            try{
                if($o['tipo']==='manual'){
                    $nota='Convocatoria #'.$c['id'].' · '.($c['programa_nombre']??'').' · '.$c['titulo'].' · '.$destino;
                    // Historial comercial existente; nunca un seguimiento ficticio.
                    $s=$this->db->prepare('INSERT INTO aliados_manuales_difusiones (aliado_id,canal,nota,registrado_por) VALUES (?,?,?,?)');
                    $id=$o['id'];$canalManual=$canal==='CORREO'?'CORREO':'WHATSAPP';$nota=mb_substr($nota,0,500);$usuario=(int)$_SESSION['usuario_id'];
                    $s->bind_param('issi',$id,$canalManual,$nota,$usuario);$s->execute();
                }else{
                    $envio=$this->modelo->registrarEnvio(['seguimiento_id'=>$o['id'],'convocatoria_id'=>$c['id'],'usuario_id'=>(int)$_SESSION['usuario_id'],'canal'=>$canal,'destinatario'=>$destino,'asunto'=>$asunto,'mensaje'=>$mensaje,'convocatoria_titulo'=>$c['titulo'],'convocatoria_imagen'=>$c['imagen']??'','convocatoria_enlace_registro'=>$c['enlace_registro']??'','convocatoria_fecha_inicio'=>$c['fecha_inicio'],'convocatoria_fecha_termino'=>$c['fecha_termino'],'estado_envio'=>$canal==='CORREO'?'ENVIADO':'COMPARTIDO','proveedor'=>$resultado['proveedor']??'MANUAL']);
                    if(!$envio)throw new RuntimeException('No se guardó el historial.');
                    $this->modelo->iniciarSeguimientoConvocatoriaPorEnvio($envio,(int)$_SESSION['usuario_id']);
                }
                $respuesta=['ok'=>true,'mensaje'=>$canal==='CORREO'?'Correo enviado y registrado.':'Difusión realizada registrada. El CRM no envió WhatsApp.'];
            }catch(Throwable $e){error_log('[aliado_compartir_historial] '.$e->getMessage());$respuesta=['ok'=>false,'codigo_http'=>500,'mensaje'=>'La acción se completó, pero no se pudo guardar el historial. No repitas el envío; revisa la operación.'];}
            $_SESSION['aliado_compartir_operaciones'][$nonce]['respuesta']=$respuesta;return $respuesta;
        }finally{$this->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
