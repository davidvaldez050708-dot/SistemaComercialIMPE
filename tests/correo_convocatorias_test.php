<?php
// Sólo fixtures, dobles de SQL/API y estado temporal. No conexiones ni envíos reales.
require __DIR__.'/correos_marketing_test.php';
require dirname(__DIR__).'/app/services/CorreoConvocatoriaComposicion.php';
function cvCheck($ok,$message) { if(!$ok) { throw new RuntimeException($message); } }
class CvDb
{
    public $relation=true;
    public $agreementFailure=false;
    public $rows=[];
    public function query($sql) {
        cvCheck(str_starts_with($sql,'SELECT'),'Modelo exclusivamente de lectura');
        if(str_contains($sql,'information_schema')) { return new MarketingRows([['total'=>$this->relation?1:0]]); }
        if(str_contains($sql,'FROM estados')) { return new MarketingRows([['id'=>11,'nombre'=>'Guanajuato'],['id'=>25,'nombre'=>'Sinaloa']]); }
        if(str_contains($sql,'FROM aliados_asignaciones')) {
            if($this->agreementFailure) { throw new RuntimeException('No registrar este contenido ficticio'); }
            return new MarketingRows([
            ['id'=>90,'nombre'=>'Convenio Alfa','estado_id'=>11,'referencia'=>'F-90'],
            ['id'=>91,'nombre'=>'Convenio Beta','estado_id'=>25,'referencia'=>'F-91'],
            ['id'=>92,'nombre'=>'Sin convocatoria','estado_id'=>11,'referencia'=>'F-92']]); }
        if(str_contains($sql,'FROM convocatoria_estados')) { return new MarketingRows([
            ['convocatoria_id'=>1,'id'=>11,'nombre'=>'Guanajuato'],['convocatoria_id'=>1,'id'=>25,'nombre'=>'Sinaloa'],
            ['convocatoria_id'=>2,'id'=>25,'nombre'=>'Sinaloa'],['convocatoria_id'=>3,'id'=>11,'nombre'=>'Guanajuato']]); }
        if(str_contains($sql,'FROM marketing_convenio_convocatorias')) { return new MarketingRows([
            ['convocatoria_id'=>1,'id'=>90,'nombre'=>'Convenio Alfa','referencia'=>'F-90'],
            ['convocatoria_id'=>2,'id'=>91,'nombre'=>'Convenio Beta','referencia'=>'F-91']]); }
        return new MarketingRows($this->rows);
    }
    public function prepare($sql) { cvCheck(str_contains($sql,'WHERE id = ?'),'Identificador parametrizado');return new CvStmt($this); }
}
class CvStmt {
    private $db,$id;
    public function __construct($db) { $this->db=$db; }
    public function bind_param($types,&...$args) { $this->id=(int)$args[0]; }
    public function execute() {}
    public function get_result() { return new MarketingRows(array_values(array_filter($this->db->rows,fn($r)=>$r['id']===$this->id))); }
}
$suffix=bin2hex(random_bytes(5));$fixture=dirname(__DIR__).'/tmp/cv-fixture-'.$suffix;
$private=sys_get_temp_dir().'/impe-convocatorias-'.$suffix;mkdir($private,0700,true);
$imageDir=$fixture.'/public/uploads/convocatorias';mkdir($imageDir,0700,true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR1sAAAAASUVORK5CYII=');
file_put_contents($imageDir.'/prueba.png',$png);file_put_contents($imageDir.'/otra.png',$png);
putenv('IMPE_MAIL_STATE_DIR='.$private);putenv('IMPE_PRIVATE_CONFIG_DIR='.$private);
file_put_contents($private.'/mail_accounts.local.php','<?php return '.var_export(['accounts'=>['qa@rededucativamexico.org'=>['activo'=>true,'usuarios'=>[7],'transport'=>'hostinger_api','api_token'=>'TOKEN_FICTICIO']]],true).';');
try {
    $db=new CvDb();$db->rows=[
        ['id'=>1,'titulo'=>'Handly','estado'=>1,'imagen'=>'public/uploads/convocatorias/prueba.png'],
        ['id'=>2,'titulo'=>'Handly','estado'=>1,'imagen'=>'public/uploads/convocatorias/otra.png'],
        ['id'=>3,'titulo'=>'Sin imagen','estado'=>1,'imagen'=>'']];
    $model=new CorreoConvocatoriaConsultaModel($db);$composition=new CorreoConvocatoriaComposicion($model,$fixture);
    $catalog=$composition->catalogo();
    cvCheck(count($catalog['convocatorias'])===3 && count($catalog['convocatorias'][0]['estados'])===2,'Cobertura múltiple y nombres repetidos conservados');
    cvCheck($catalog['convocatorias'][0]['convenios'][0]['id']===90 && !isset($catalog['convocatorias'][0]['imagen_ref']),'Relación real de convenio sin exponer rutas');
    cvCheck(count($catalog['convenios'])===3 && $catalog['convenios'][2]['id']===92,'Convenios independientes de las relaciones con convocatorias');
    $selection=$composition->seleccionar(['convocatoria_id'=>1,'convocatoria_estado_id'=>11,'convocatoria_convenio_id'=>90]);
    $public=$composition->publico($selection);
    cvCheck($public['imagen_url']!=='' && !str_contains(json_encode($public),$fixture),'Vista previa autenticada por ID y versión');
    try {$composition->seleccionar(['convocatoria_id'=>2,'convocatoria_estado_id'=>11]);throw new LogicException('Filtro omitido');} catch(RuntimeException $e) {cvCheck($e->getMessage()==='CONVOCATORIA_FILTROS','Rechazo de selección territorial manipulada');}
    try {$composition->seleccionar(['convocatoria_id'=>1,'convocatoria_convenio_id'=>91]);throw new LogicException('Convenio ajeno');} catch(RuntimeException $e) {cvCheck($e->getMessage()==='CONVOCATORIA_FILTROS','Rechazo de convenio no relacionado');}
    cvCheck($composition->seleccionar([])===[],'Selección opcional');
    foreach(['../../config/app.php','https://example.test/imagen.png','public/uploads/convocatorias/../../prueba.png','public/uploads/convocatorias/invalida.png'] as $ref) {
        if(str_ends_with($ref,'invalida.png')) {file_put_contents($imageDir.'/invalida.png','NO_ES_IMAGEN');}
        cvCheck($composition->imagen(['id'=>1,'imagen_ref'=>$ref])===null,'Rutas remotas/traversal/MIME inválidos rechazados');
    }
    $payloads=[];$posts=0;
    $accounts=new CorreoCuentaConectadaService(static fn()=>['correo'=>'qa@rededucativamexico.org','estado'=>1,'rol'=>'Marketing']);
    $transport=new CorreoSalidaInstitucionalService(null,$accounts,static function($method,$url,$token,$payload)use(&$payloads,&$posts){
        cvCheck($token==='TOKEN_FICTICIO','Token sin transformación');
        if($method==='GET') { return ['ok'=>true,'codigo_http'=>200,'json'=>['data'=>['mailboxes'=>[['address'=>'qa@rededucativamexico.org','resourceId'=>'ACqa']]]]]; }
        $posts++;$payloads[]=$payload;return ['ok'=>true,'codigo_http'=>204];
    });
    $text="Texto íntegro del usuario\nCon acentos: México";
    $input=['usuario_id'=>7,'remitente'=>'qa@rededucativamexico.org','destinatario'=>'destino@example.test','asunto'=>'Prueba ficticia','cuerpo'=>$text,
        'idempotency_key'=>'cv-fixture-primero','adjuntos'=>[['ruta'=>__FILE__,'nombre'=>'manual.txt','mime'=>'text/plain']]];
    $r=$composition->envio($selection,$transport)->enviar($input);
    cvCheck($r['ok'] && $posts===1,'Transporte existente acepta contenido compuesto (simulado)');
    $p=$payloads[0];$embedded=array_values(array_filter($p['attachments'],fn($a)=>!empty($a['cid'])));
    cvCheck(count($embedded)===1 && base64_decode($embedded[0]['content'])===$png && str_contains($p['html'],'cid:'.$embedded[0]['cid']),'CID en HTML coincide con contenido original de la imagen');
    cvCheck($p['text']===$text && count($p['attachments'])===2 && $p['to']===['destino@example.test'],'Texto/destino/adjunto manual conservados; imagen embebida una vez');
    $without=$composition->seleccionar(['convocatoria_id'=>3]);$input['idempotency_key']='cv-fixture-sin-imagen';
    cvCheck($composition->envio($without,$transport)->enviar($input)['ok'] && count($payloads[1]['attachments'])===1,'Convocatoria sin imagen permite enviar sin imagen ajena');
    $other=$composition->seleccionar(['convocatoria_id'=>2]);$input['idempotency_key']='cv-fixture-segunda';
    cvCheck($composition->envio($other,$transport)->enviar($input)['ok'] && str_contains($payloads[2]['html'],'cid:convocatoria-2-') && !str_contains($payloads[2]['html'],'cid:convocatoria-1-'),'Cambio sustituye únicamente la imagen automática');
    $draftDb=new MarketingDb();$capture=new MarketingSender();$service=new CorreoMarketingService($draftDb,$composition->envio($selection,$capture),static fn()=>['correo'=>'qa@rededucativamexico.org','nombre'=>'QA']);
    $draftInput=['destinatario'=>'destino@example.test','asunto'=>'QA','cuerpo'=>$text,'convocatoria_id'=>1];
    $saved=$service->guardarBorrador(7,$draftInput);$composition->registrar(7,$saved['correo_id'],$selection);
    cvCheck($service->obtener(7,$saved['correo_id'])['cuerpo']===$text && $composition->leer(7,$saved['correo_id'])['id']===1,'Borrador conserva cuerpo y metadatos independientes');
    cvCheck($service->obtener(8,$saved['correo_id'])===null && $composition->leer(8,$saved['correo_id'])===[],'Aislamiento por propietario');
    $composition->registrar(7,$saved['correo_id'],[]);cvCheck($composition->leer(7,$saved['correo_id'])===[],'Limpiar elimina sólo selección automática');
    $composition->registrar(7,$saved['correo_id'],$selection);
    $sent=$service->enviar(7,$draftInput+['correo_id'=>$saved['correo_id'],'idempotency_key'=>'cv-draft-sent-001']);
    $composition->registrar(7,$sent['correo_id'],$selection,true);
    $composition->registrar(7,$sent['correo_id'],$other,true);
    cvCheck($sent['ok'] && $draftDb->sentWrites===1 && $composition->leer(7,$sent['correo_id'])['id']===1,'Historial existente y referencia aceptada inmutable ante reintento');
    file_put_contents($imageDir.'/prueba.png',$png.'CAMBIO');
    try {$composition->seleccionar(['convocatoria_id'=>1,'convocatoria_imagen_sha'=>$selection['imagen_sha']]);throw new LogicException('Cambio no detectado');} catch(RuntimeException $e) {cvCheck($e->getMessage()==='CONVOCATORIA_IMAGEN_CAMBIO','Cambio posterior detectado');}
    cvCheck($composition->publico($selection)['imagen_url']==='','Historial no sustituye una imagen histórica modificada');
    $input['idempotency_key']='cv-fixture-modificada';
    cvCheck(!$composition->envio($selection,$transport)->enviar($input)['ok'] && $posts===3,'Imagen alterada no llega al transporte');
    $db->relation=false;$noRelations=(new CorreoConvocatoriaConsultaModel($db))->catalogo();
    cvCheck(!$noRelations['convenios_disponibles'] && $noRelations['convocatorias'][0]['convenios']===[],'Ausencia de tabla de relaciones no crea estructura ni inventa convenios');
    cvCheck(count($noRelations['convenios'])===3,'Los convenios existentes no requieren relaciones de convocatoria');
    $db->agreementFailure=true;$partial=(new CorreoConvocatoriaConsultaModel($db))->catalogo();
    cvCheck(count($partial['estados'])===2 && count($partial['convocatorias'])===3 && $partial['avisos']===['CONVENIOS_CONSULTA_NO_DISPONIBLE'],'Un fallo de convenios no borra los otros catálogos y avisa sin datos internos');
    echo "OK: consultas de lectura, relaciones reales, cobertura múltiple, filtros manipulados, nombres repetidos, rutas/MIME, CID/bytes originales, adjunto manual, texto completo, sin imagen, cambio de imagen, borrador/propietario/historial y referencia inmutable. API/SQL simulados; sin recepción real.\n";
} finally {
    foreach([$private,$fixture] as $dir) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $p) { $p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname()); }rmdir($dir);
    }
    putenv('IMPE_MAIL_STATE_DIR');putenv('IMPE_PRIVATE_CONFIG_DIR');
}
