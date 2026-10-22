<?php
require_once __DIR__ . '/CorreoHostingerApiService.php';

/** Interfaz conservada para los módulos; sólo utiliza Hostinger central. */
final class CorreoCuentaConectadaService
{
    private $identityLoader, $api;
    public function __construct(?callable $identityLoader=null) { $this->identityLoader=$identityLoader;$this->api=new CorreoHostingerApiService(); }
    public function configurarConsultaApi(callable $request): void { $this->api=new CorreoHostingerApiService($request); }
    public function remitentes(int $user): array
    {
        try { $r=$this->api->permitidos($user,$this->identidad($user));return ['ok'=>true,'remitentes'=>array_keys($r['boxes']),
            'diagnostico'=>$r['diagnostico'],'actualizado_utc'=>gmdate('c')]; }
        catch(Throwable $e) { return $this->api->fallo($e); }
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
            'mensaje'=>'El remitente debe ser un buzón de @rededucativamexico.org devuelto por Hostinger y permitido por el CRM. Compartir dominio no autoriza una dirección ausente de la lista.'];
    }
}
