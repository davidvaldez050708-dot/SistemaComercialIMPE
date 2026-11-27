<?php
declare(strict_types=1);
namespace Zadarma_API {
 class Api {
  public const PROD_URL='https://fixture.invalid';public const SANDBOX_URL='https://sandbox.fixture.invalid';
  public function __construct($key,$secret,$sandbox=false){}
  protected function encodeSignature($value){return 'synthetic-signature';}
 }
}
namespace {
 if(PHP_SAPI!=='cli')exit;
 require dirname(__DIR__,3).'/app/services/TelefoniaZadarmaApi.php';
 $directory=sys_get_temp_dir().'/impe-provider-fixture-'.bin2hex(random_bytes(8));mkdir($directory,0700);
 $checks=0;$calls=0;$wire=null;
 $assert=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);++$checks;};
 $transport=static function(array $request)use(&$calls,&$wire):array{
  ++$calls;$wire=$request;return ['status'=>200,'headers'=>[],'body'=>'{"status":"success","stats":[]}'];
 };
 try{
  $api=new TelefoniaZadarmaApi('fixture-key','fixture-secret',false,$transport,$directory);
  $api->call('/v1/statistics/pbx/',['pbx_call_id'=>'fixture-a']);$api->call('/v1/statistics/pbx/',['pbx_call_id'=>'fixture-a']);
  $assert($calls===1,'Exact provider cache prevents duplicate transport');
  $assert($wire['verify_peer']===true&&$wire['verify_host']===2&&str_starts_with($wire['url'],'https://'),'Provider request requires TLS identity validation');
  $budget=glob($directory.'/*-budget.json')[0];
  foreach(['{','',json_encode(['all'=>'bad','statistics'=>[]])] as $corrupt){
   file_put_contents($budget,$corrupt);$rejected=false;
   try{$api->call('/v1/statistics/pbx/',['pbx_call_id'=>'fixture-corrupt-'.strlen($corrupt)]);}catch(RuntimeException $e){$rejected=$e->getCode()===503;}
   $assert($rejected&&$calls===1,'Corrupt/truncated budget cannot reset quota or call provider');
  }
  file_put_contents($budget,json_encode(['all'=>array_fill(0,100,microtime(true)),'statistics'=>[]]));$rejected=false;
  try{$api->call('/v1/pbx/record/request/',['call_id'=>'fixture-quota']);}catch(RuntimeException $e){$rejected=$e->getCode()===429;}
  $assert($rejected&&$calls===1,'Shared full budget fails closed');
  $now=time();$account=str_repeat('a',64);$expired=[];
  for($i=0;$i<9;++$i){$name=$account.'-'.hash('sha256','retention-'.$i).'.json';$path=$directory.'/'.$name;
   file_put_contents($path,json_encode(['until'=>$now-200000,'wire'=>[]]));touch($path,$now-200000);$expired[]=$path;}
  $foreign=$directory.'/foreign.json';file_put_contents($foreign,'{}');touch($foreign,$now-200000);
  $cursor='';$removed=0;$pages=0;
  do{$page=TelefoniaZadarmaApi::limpiarCache($directory,2,$now,$cursor);$cursor=$page['siguiente'];$removed+=$page['eliminados'];++$pages;}while($cursor!==null&&$pages<20);
  $assert($removed===9&&$pages>1,'Bounded retention cursor reaches later expired pages');
  $assert(is_file($foreign)&&is_file($budget),'Retention preserves foreign files and provider budget');
  echo "PASS $checks provider cache assertions: exact reuse, TLS, corrupt/empty budget fail-closed, quota and complete bounded retention. Fake transport only.\n";
 }finally{
  foreach(new \DirectoryIterator($directory) as $file)if($file->isFile()&&!$file->isLink())unlink($file->getPathname());rmdir($directory);
 }
}
