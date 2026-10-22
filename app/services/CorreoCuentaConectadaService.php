<?php
require_once __DIR__ . '/CorreoHostingerApiService.php';

/** Interfaz conservada para los módulos; sólo utiliza Hostinger central. */
final class CorreoCuentaConectadaService
{
    private $identityLoader, $api;
    public function __construct(?callable $identityLoader=null) { $this->identityLoader=$identityLoader;$this->api=new CorreoHostingerApiService(); }
    public function configurarConsultaApi(callable $request): void { $this->api=new CorreoHostingerApiService($request); }
    public function remitentes(int $user,string $from=''): array
    {
        try {
            $identity=$this->identidad($user);$r=$this->api->permitidos($user,$identity);
            $result=['ok'=>true,'remitentes'=>array_keys($r['boxes']),'diagnostico'=>$r['diagnostico'],'actualizado_utc'=>gmdate('c')];
            if($from!=='') { $selected=$this->seleccionarSalida($from,$user,$identity,$r);if(empty($selected['ok'])) { return $selected; }$result['salida']=$selected['salida']; }
            return $result;
        }
        catch(Throwable $e) { return $this->api->fallo($e); }
    }
    public function resolverSalida(string $from,int $user): array
    {
        try {
            $identity=$this->identidad($user);
            if(!$this->api->institucional(strtolower(trim($from)))) { return $this->rechazo(); }
            return $this->seleccionarSalida($from,$user,$identity,$this->api->permitidos($user,$identity));
        } catch(Throwable $e) { return $this->api->fallo($e); }
    }
    private function seleccionarSalida(string $from,int $user,array $identity,array $r): array
    {
        $from=strtolower(trim($from));
        if(!$this->api->institucional($from)) { return $this->rechazo(); }
        if(!$r['boxes']) { return ['ok'=>false,'codigo_http'=>403,'mensaje'=>'No tienes un buzón de salida permitido por la configuración comercial.']; }
        $own=strtolower(trim((string)($identity['correo']??'')));
        $real=$from;$direct=isset($r['boxes'][$from]);
        if(!$direct) {
            $preferred=$r['central_preferido']??'';
            if($preferred!=='' && !isset($r['boxes'][$preferred])) { return ['ok'=>false,'codigo_http'=>403,'mensaje'=>'El buzón central configurado no está autorizado para tu usuario. Solicita revisar la configuración comercial.']; }
            $real=$preferred!==''?$preferred:(isset($r['boxes'][$own])?$own:array_key_first($r['boxes']));
        }
        // El dominio no demuestra propiedad: sólo el correo registrado o un buzón permitido.
        $contact=$direct || hash_equals($own,$from)?$from:'';
        $name=trim((string)($identity['nombre']??'').' '.(string)($identity['apellidos']??''));
        $salida=['modo'=>$direct?'directo':'central','remitente_real'=>$real,'contacto_autorizado'=>$contact,
            'redactor_id'=>$user,'redactor_nombre'=>$name,'respuestas_a'=>$real,'reply_to_personalizado'=>false,
            'aviso'=>($direct?'Se enviará desde ':'Se usará el buzón central ').$real.'. Las respuestas se recibirán en ese buzón.'.
                ($contact!=='' && !$direct?' Tu contacto institucional '.$contact.' se incluirá en el mensaje.':
                (!$direct?' La dirección escrita no está verificada como contacto tuyo y no se comunicará al destinatario.':''))];
        return ['ok'=>true,'account'=>$r['account']+['correo'=>$real],'salida'=>$salida];
    }
    public function resolver(string $from,int $user,bool $marketing=false): array
    {
        try {
            $identity=$this->identidad($user);$from=strtolower(trim($from));
            if(!$this->api->institucional($from)) { return $this->rechazo(); }
            $r=$this->api->permitidos($user,$identity);
            if(!isset($r['boxes'][$from])) { return $this->rechazo(); }
            return ['ok'=>true,'account'=>$r['account']+['correo'=>$from]];
        } catch(Throwable $e) { return $this->api->fallo($e); }
    }
    private function identidad(int $user): array
    {
        if($user<=0 || (isset($_SESSION['usuario_id'])&&(int)$_SESSION['usuario_id']!==$user)) { throw new RuntimeException('HOSTINGER_SESION_INVALIDA'); }
        if($this->identityLoader) { $identity=($this->identityLoader)($user); }
        else { require_once __DIR__.'/../models/UsuarioModel.php';$identity=(new UsuarioModel())->buscarPorId($user); }
        if(!$identity || (int)($identity['estado']??0)!==1 || (int)($identity['rol_estado']??1)!==1) { throw new RuntimeException('HOSTINGER_SESION_INVALIDA'); }
        return $identity;
    }
    private function rechazo(): array
    {
        return ['ok'=>false,'codigo_http'=>422,'estado_entrega'=>'RECHAZADO','entrega_confirmada'=>false,
            'mensaje'=>'Introduce una dirección válida de @rededucativamexico.org. La salida comercial utiliza únicamente buzones permitidos por Hostinger y el CRM.'];
    }
}
