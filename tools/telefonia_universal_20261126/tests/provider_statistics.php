<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/services/TelefoniaPbxEstadisticasService.php';
final class StatisticsFakeApi {
    public array $calls=[];
    public function __construct(public array $pages,public string $zone='UTC+0',public bool $badClock=false){}
    public function call(string $path,array $params,string $type):string {
        $this->calls[]=[$path,$params,$type];
        if($path==='/v1/info/timezone/'){
            $name=preg_replace('/^UTC([+-])(\d)$/','$1\2:00',$this->zone);
            try{$z=new DateTimeZone($name);}catch(Throwable $e){$z=new DateTimeZone('UTC');}
            $now=new DateTimeImmutable('now',$z);
            return json_encode(['status'=>'success','timezone'=>$this->zone,'unixtime'=>$now->getTimestamp()+($this->badClock?3600:0),'datetime'=>$now->format('Y-m-d H:i:s')],JSON_THROW_ON_ERROR);
        }
        $page=(int)(($params['skip']??0)/1000);
        return json_encode($this->pages[$page]??['status'=>'success','stats'=>[]],JSON_THROW_ON_ERROR);
    }
}
$count=0;$check=static function(bool $ok,string $why)use(&$count){if(!$ok)throw new RuntimeException($why);++$count;};
$reject=static function(callable $fn)use($check){try{$fn();}catch(RuntimeException $e){$check(true,'Rejected incomplete CDR');return;}$check(false,'Incomplete CDR was accepted');};
date_default_timezone_set('Pacific/Auckland');
$from=TelefoniaPbxEstadisticasService::fechaLocal('2026-11-25 00:00:00')->getTimestamp();$to=$from+3600;
$row=['pbx_call_id'=>'out_'.str_repeat('a',32),'call_id'=>'17490000000000001234567890','sip'=>'102','destination'=>'5550000101','callstart'=>'2026-11-25 06:30:00','seconds'=>10,'disposition'=>'answered','is_recorded'=>true];
$api=new StatisticsFakeApi([['status'=>'success','stats'=>[$row]]]);$service=new TelefoniaPbxEstadisticasService($api);$rows=$service->consultar($from,$to,'out');
$check($rows[0]['callstart']==='2026-11-25 00:30:00','UTC account converted exactly once to Mexico regardless of PHP timezone');
$check($rows[0]['call_id']===$row['call_id'],'Opaque recording ID preserved');
$check($api->calls[1][1]['start']==='2026-11-25 06:00:00'&&$api->calls[1][1]['end']==='2026-11-25 07:00:00','Query in provider account zone');
$service->consultar($from,$to,'out');$check(count($api->calls)===2,'Complete catalog reused within request');
$page=[];for($n=0;$n<1000;$n++)$page[]=array_replace($row,['call_id'=>'fixture-record-'.$n]);
$api=new StatisticsFakeApi([['status'=>'success','stats'=>$page],['status'=>'success','stats'=>[$row]]]);
$rows=(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to,'in');
$check(count($rows)===1001,'Fetch second page before deciding exact association');
$check($api->calls[2][1]['skip']===1000&&$api->calls[2][1]['call_type']==='in','Stable pagination offset and direction');
$api=new StatisticsFakeApi([['status'=>'success','stats'=>$page]]);$rows=(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to);
$check(count($rows)===1000&&count($api->calls)===3,'Exactly 1000 rows requires proof of final empty page');
$api=new StatisticsFakeApi([['status'=>'success','stats'=>$page],['status'=>'success','stats'=>$page]]);
$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
$api=new StatisticsFakeApi([['status'=>'success','stats'=>$page],['status'=>'error']]);
$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
$pages=[];for($p=0;$p<3;$p++){$items=[];foreach($page as $n=>$r)$items[]=array_replace($r,['call_id'=>'fixture-'.$p.'-'.$n]);$pages[]=['status'=>'success','stats'=>$items];}
$api=new StatisticsFakeApi($pages);$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
$check(count($api->calls)===4,'At most three statistics calls, never unbounded pagination');
$api=new StatisticsFakeApi([['status'=>'success','stats'=>[$row]]],'UTC+0',true);$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
$api=new StatisticsFakeApi([['status'=>'success','stats'=>[$row]]],'unknown-zone');$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
$api=new StatisticsFakeApi([['status'=>'success','stats'=>[array_replace($row,['callstart'=>'2026-02-30 06:30:00'])]]]);$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
$api=new StatisticsFakeApi([['status'=>'success','stats'=>[$row],'start'=>'2026-11-25 06:01:00']]);$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
$api=new StatisticsFakeApi([['status'=>'success','stats'=>[array_replace($row,['callstart'=>'2026-11-25 09:30:00'])]]],'UTC+3');$rows=(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to);
$check($rows[0]['callstart']==='2026-11-25 00:30:00','Nonzero provider offset normalized without fixed six-hour arithmetic');
foreach(['UTC-6','America/Mexico_City'] as $name){
 $api=new StatisticsFakeApi([['status'=>'success','stats'=>[array_replace($row,['callstart'=>'2026-11-25 00:30:00'])]]],$name);
 $rows=(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to);
 $check($rows[0]['callstart']==='2026-11-25 00:30:00','Mexico account offset/name is never shifted twice');
}
$api=new StatisticsFakeApi([['status'=>'success','stats'=>[array_replace($row,['callstart'=>'2026-11-26 06:30:00'])]]]);
$reject(fn()=>(new TelefoniaPbxEstadisticasService($api))->consultar($from,$to));
echo "PASS $count shared CDR catalog checks: account timezone, bounded complete pagination, incomplete responses, query cache and immutable recording IDs. Fake provider; no network/SQL.\n";
