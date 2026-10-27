<?php
putenv('IMPE_BASE_URL=http://supervision.test/');require __DIR__.'/../config/config.php';
$scope='equipo';$puedeAudio=($argv[1]??'')!=='sin_audio';
$f=['area'=>'asesores','persona_id'=>0,'desde'=>'2026-10-19','hasta'=>'2026-10-25','direccion'=>'','resultado'=>'','buscar'=>'','pagina'=>1];
$input=[];parse_str($argv[2]??'',$input);$f=array_replace($f,array_intersect_key($input,$f));$f['persona_id']=(int)$f['persona_id'];$f['pagina']=(int)$f['pagina'];
$data=['personas'=>[['id'=>301,'nombre'=>'María Fernanda García Hernández','activo'=>1]],'resultados'=>['CONVERSACION_PERSONA'=>'Conversación con el prospecto'],'total'=>2,'paginas'=>1,'pagina'=>1,'llamadas'=>[]];
for($i=1;$i<=2;$i++)$data['llamadas'][]=['key'=>'venta:'.$i,'persona'=>'María Fernanda García Hernández','nombre'=>'Persona <script>no ejecutar</script>','foto_perfil'=>'','fecha'=>'2026-10-25 12:00:00','direccion'=>'Entrante','numero'=>'5550000000','extension'=>'150','conectada'=>true,'resultado_texto'=>'Conversación con el prospecto','segundos'=>$i===1?13:null,'observada'=>false,'audio_consultable'=>true];
echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/icons/bootstrap-icons.css"><body style="padding:16px;background:#f4f7fa">';
require ROOT_PATH.'/app/views/telefonia/supervision_llamadas.php';echo '</body></html>';
