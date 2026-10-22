<?php
require_once __DIR__.'/CorreoSalidaInstitucionalService.php';
require_once __DIR__.'/CorreoEstadoPrivado.php';
require_once dirname(__DIR__).'/models/CorreoConvocatoriaConsultaModel.php';

/** Selección e imagen del mensaje. El transporte y la persistencia comercial se delegan intactos. */
class CorreoConvocatoriaComposicion
{
    private $model;
    private $root;
    public function __construct(?CorreoConvocatoriaConsultaModel $model=null,?string $root=null)
    {
        $this->model=$model??new CorreoConvocatoriaConsultaModel();$this->root=realpath($root??dirname(__DIR__,2));
    }
    public function catalogo(): array { return $this->model->catalogo(); }
    public static function id($value): int
    {
        if($value===null || $value==='' || $value===0 || $value==='0') { return 0; }
        if(!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value) || (float)$value>2147483647) { throw new RuntimeException('CONVOCATORIA_ID'); }
        return (int)$value;
    }
    public function imagen(array $row): ?array
    {
        $ref=str_replace('\\','/',(string)($row['imagen_ref']??''));
        if(!preg_match('~^public/uploads/convocatorias/[a-zA-Z0-9_.-]+\.(?:png|jpe?g|webp)$~iD',$ref)) { return null; }
        $base=$this->root.'/public/uploads/convocatorias';
        for($p=$base;$p!==$this->root && dirname($p)!==$p;$p=dirname($p)) { if(is_link($p)) { return null; } }
        $path=$this->root.'/'.$ref;$real=realpath($path);$baseReal=realpath($base);
        if(is_link($path) || !$baseReal || !$real || !str_starts_with($real,$baseReal.DIRECTORY_SEPARATOR) || !is_file($real) || !is_readable($real)) { return null; }
        $size=@filesize($real);
        if($size<=0 || $size>5*1024*1024) { return null; }
        $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=@$finfo->file($real);$info=@getimagesize($real);
        if(!in_array($mime,['image/png','image/jpeg','image/webp'],true) || !$info || ($info['mime']??'')!==$mime || $info[0]*$info[1]>25000000) { return null; }
        $sha=@hash_file('sha256',$real);
        if(!is_string($sha)) { return null; }
        if(isset($row['imagen_sha']) && !hash_equals((string)$row['imagen_sha'],$sha)) { return null; }
        return ['ruta'=>$real,'ref'=>$ref,'sha'=>$sha,'mime'=>$mime,'tamano'=>$size,'nombre'=>basename($ref),'cid'=>'convocatoria-'.(int)$row['id'].'-'.substr($sha,0,20)];
    }
    public function leer(int $user,int $mail): array
    {
        if($user<=0 || $mail<=0) { return []; }
        return CorreoEstadoPrivado::ejecutar('marketing-convocatoria',"$user:$mail",static fn($state)=>$state);
    }
    public function registrar(int $user,int $mail,array $selection,bool $accepted=false): void
    {
        if($user<=0 || $mail<=0) { return; }
        CorreoEstadoPrivado::ejecutar('marketing-convocatoria',"$user:$mail",static function($state,$save)use($selection,$accepted){
            if(!empty($state['_envio_aceptado'])) { return; }
            if($accepted && $selection) { $selection['_envio_aceptado']=true; }
            $save($selection);
        });
    }
    public function seleccionar(array $input,array $previous=[]): array
    {
        $id=self::id($input['convocatoria_id']??'');
        if(!$id) { return []; }
        $row=$this->model->buscar($id);
        if(!$row || (!$row['activa'] && (int)($previous['id']??0)!==$id)) { throw new RuntimeException('CONVOCATORIA_NO_DISPONIBLE'); }
        $state=self::id($input['convocatoria_estado_id']??'');$agreement=CorreoConvocatoriaConsultaModel::convenioId($input['convocatoria_convenio_id']??'');
        if(($state && !in_array($state,array_column($row['estados'],'id'),true)) || ($agreement && !in_array($agreement,array_column($row['convenios'],'id'),true))) { throw new RuntimeException('CONVOCATORIA_FILTROS'); }
        $image=$this->imagen($row);$expected=$input['convocatoria_imagen_sha']??'';
        if(!is_string($expected) || ($expected!=='' && !preg_match('/^[a-f0-9]{64}$/D',$expected))) { throw new RuntimeException('CONVOCATORIA_IMAGEN_VERSION'); }
        if(array_key_exists('convocatoria_imagen_sha',$input) && !hash_equals($image['sha']??'',$expected)) { throw new RuntimeException('CONVOCATORIA_IMAGEN_CAMBIO'); }
        $row['filtro_estado']=$state;$row['filtro_convenio']=$agreement;
        $row['imagen_sha']=$image['sha']??'';
        // Referencia comprobada, sin rutas absolutas ni contenido del mensaje en el estado privado.
        return $row;
    }
    public function detalle(int $id): array
    {
        $row=$this->model->buscar($id);
        if(!$row || !$row['activa']) { throw new RuntimeException('CONVOCATORIA_NO_DISPONIBLE'); }
        $image=$this->imagen($row);$row['imagen_sha']=$image['sha']??'';return $row;
    }
    public function publico(array $row,int $mail=0): ?array
    {
        if(!$row) { return null; }
        $image=$this->imagen($row);
        $out=$row;unset($out['imagen_ref'],$out['_envio_aceptado']);
        $out['imagen_sha']=$image['sha']??'';
        $out['imagen_url']=$image?'index.php?controller=correoMarketing&action=imagenConvocatoria&id='.(int)$row['id'].'&v='.$image['sha'].($mail?'&correo_id='.$mail:''):'';
        $out['imagen_mensaje']=$image?'Imagen que se incluirá dentro del correo.':'Esta convocatoria no tiene una imagen disponible. Puedes enviar el correo sin imagen.';
        return $out;
    }
    public function envio(array $selection,?CorreoSalidaInstitucionalService $delegate=null): CorreoSalidaInstitucionalService
    {
        $image=$selection?$this->imagen($selection):null;
        return new CorreoConvocatoriaMensaje($selection,$image,$delegate);
    }
}

/** Decorador de composición: reutiliza enviar() y sus interfaces html_adicional/imagenes_embebidas. */
class CorreoConvocatoriaMensaje extends CorreoSalidaInstitucionalService
{
    private $selection;
    private $image;
    private $delegate;
    public function __construct(array $selection,?array $image,?CorreoSalidaInstitucionalService $delegate=null)
    {
        $this->selection=$selection;$this->image=$image;$this->delegate=$delegate??new CorreoSalidaInstitucionalService();
    }
    // Marketing v4 autoriza antes de enviar. Usar la misma instancia configurada,
    // nunca los métodos heredados que dependen de propiedades privadas del padre.
    public function prepararSalida(string $from,int $user): array
    {
        try { return $this->delegate->prepararSalida($from,$user); }
        catch(Throwable $error) {
            error_log('MAIL_CONVOCATORIA phase=preparar_salida exception='.get_class($error).' code='.(int)$error->getCode());
            throw $error;
        }
    }
    public function validarRemitente(string $from,int $user): array
    {
        try { return $this->delegate->validarRemitente($from,$user); }
        catch(Throwable $error) {
            error_log('MAIL_CONVOCATORIA phase=validar_remitente exception='.get_class($error).' code='.(int)$error->getCode());
            throw $error;
        }
    }
    public function enviar($data)
    {
        if(!empty($this->selection['imagen_sha']) && !$this->image) { return ['ok'=>false,'codigo_http'=>422,'estado_entrega'=>'RECHAZADO','mensaje'=>'La imagen de la convocatoria cambió. Vuelve a seleccionarla antes de enviar.']; }
        if($this->image) {
            $i=$this->image;
            $current=is_file($i['ruta'])?@hash_file('sha256',$i['ruta']):false;
            if(!is_string($current) || !hash_equals($i['sha'],$current)) { return ['ok'=>false,'codigo_http'=>422,'estado_entrega'=>'RECHAZADO','mensaje'=>'La imagen de la convocatoria cambió. Vuelve a seleccionarla antes de enviar.']; }
            $total=$i['tamano'];
            foreach(($data['adjuntos']??[]) as $file) { if(is_file($file['ruta']??'')) { $total+=filesize($file['ruta']); } }
            if($total>20*1024*1024) { return ['ok'=>false,'codigo_http'=>422,'estado_entrega'=>'RECHAZADO','mensaje'=>'Los archivos y la imagen de la convocatoria superan el límite de 20 MB.']; }
            $data['imagenes_embebidas'][]=array_intersect_key($i,array_flip(['ruta','nombre','mime','cid']));
            $title=htmlspecialchars($this->selection['titulo'],ENT_QUOTES,'UTF-8');
            $data['html_adicional']=($data['html_adicional']??'').'<div style="margin-top:18px"><img src="cid:'.$i['cid'].'" alt="'.$title.'" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0"></div>';
        }
        try { $result=$this->delegate->enviar($data); }
        catch(Throwable $error) {
            error_log('MAIL_CONVOCATORIA phase=transporte exception='.get_class($error).' code='.(int)$error->getCode());
            throw $error;
        }
        $state=$result['estado_entrega']??(!empty($result['ok'])?'ACEPTADO':'RECHAZADO');
        if(!in_array($state,['ACEPTADO','RECHAZADO','DESCONOCIDO'],true)) { $state='DESCONOCIDO'; }
        error_log('MAIL_CONVOCATORIA phase=resultado_transporte estado='.$state.' solicitud='.substr(hash('sha256',(string)($data['idempotency_key']??'')),0,12));
        return $result;
    }
}
