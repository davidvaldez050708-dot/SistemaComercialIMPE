<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_v5_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../app/models/ConvocatoriaModel.php';
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
$db=(new Database())->connect(false);
if(($argv[1]??'')==='--complete-manual-location'){
    $db->query("UPDATE aliados_manuales SET estado_id=2,municipio_id=2 WHERE nombre_entidad='V4 Manual'");
    echo "OK: ubicación sintética manual completada\n";return;
}
$db->query("UPDATE estados SET nombre=CASE id WHEN 1 THEN 'Querétaro' WHEN 2 THEN 'Guanajuato' ELSE nombre END");
$db->query("UPDATE seguimientos_vinculacion SET nombre_entidad='IQJ' WHERE id=10");
$db->query("UPDATE municipios SET nombre='Querétaro' WHERE id=1");
foreach(['created_at','updated_at'] as $c)if(!$db->query("SHOW COLUMNS FROM convocatorias LIKE '$c'")->num_rows)$db->query("ALTER TABLE convocatorias ADD $c DATETIME DEFAULT CURRENT_TIMESTAMP");
$db->query("ALTER TABLE formulario_aliados ADD pais_codigo VARCHAR(2) DEFAULT 'MX', ADD region VARCHAR(100) DEFAULT '',ADD ciudad VARCHAR(100) DEFAULT ''");
$db->query("CREATE TABLE marketing_convenio_convocatorias (id INT AUTO_INCREMENT PRIMARY KEY,seguimiento_id INT,convocatoria_id INT,creado_por INT,created_at DATETIME) ENGINE=InnoDB");
$db->query("CREATE TABLE formulario_aliado_convocatorias (id INT AUTO_INCREMENT PRIMARY KEY,aliado_id BIGINT UNSIGNED,convocatoria_id INT,programa_codigo VARCHAR(64)) ENGINE=InnoDB");
$db->query("INSERT INTO formulario_aliados (slug,nombre,ubicacion,token,activo,pais_codigo,region,ciudad) VALUES ('v5-internacional','Instituto Internacional Sintético','Guatemala',REPEAT('c',64),1,'GT','Guatemala','Ciudad')");
$international=(int)$db->insert_id;
$model=new ConvocatoriaModel();
$make=static function(string $title,string $type,string $sub,int $state=1,int $active=1)use($model):int {
    return $model->crear(['titulo'=>$title,'tipo_convocatoria'=>$type,'subtipo_convocatoria'=>$sub,'imagen'=>'public/img/brand/Logo-Sep-Blanco.png','enlace_registro'=>'https://example.test/registro/estable','fecha_inicio'=>date('Y-m-d'),'fecha_termino'=>date('Y-m-d',strtotime('+20 days')),'estado'=>$active,'usuario_id'=>4],[$state]);
};
$manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='V4 Manual'")->fetch_row()[0];
$db->query("UPDATE aliados_manuales SET estado_id=2,municipio_id=2 WHERE id=$manual");
$specs=[['territorial','Convocatoria territorial histórica','bachillerato','bachillerato-general',1,1],['historica','Título sin nombre institucional','bachillerato','bachillerato-general',1,1],['manual','Publicación técnica','titulacion','experiencia-laboral',2,1],['internacional','Publicación internacional','bachillerato','ingles',1,1],['conflicto','Relación contradictoria','bachillerato','bachillerato-general',1,1],['vencida','General desactivada','bachillerato','bachillerato-general',1,0],['ejecutivas','Oferta ejecutiva','titulacion','ejecutivas',2,1],['dos-anos','Bachillerato dos años','bachillerato','bachillerato-2-anos',1,1],['abiertas','Inscripciones permanentes','bachillerato','inscripciones-abiertas',1,1],['general','Título independiente del aliado','bachillerato','bachillerato-general',1,1]];
foreach($specs as [$key,$title,$type,$sub,$state,$active])$ids[$key]=$make($title,$type,$sub,$state,$active);
foreach(['historica','historica','conflicto'] as $key)$db->query("INSERT INTO marketing_convenio_convocatorias(seguimiento_id,convocatoria_id,creado_por,created_at) VALUES(10,{$ids[$key]},4,NOW())");
$db->query("INSERT INTO marketing_aliado_convocatorias_origen (convocatoria_id,origen_tipo,origen_id,programa_codigo,creado_por) VALUES ({$ids['general']},'institucional',10,'bachillerato-general',4),({$ids['manual']},'manual',$manual,'titulacion-experiencia-laboral',4),({$ids['conflicto']},'manual',$manual,'bachillerato-general',4)");
$db->query("INSERT INTO marketing_aliado_manual_convocatorias (manual_id,convocatoria_id,programa_codigo,creado_por) VALUES ($manual,{$ids['manual']},'titulacion-experiencia-laboral',4)");
$db->query("INSERT INTO formulario_aliado_convocatorias (aliado_id,convocatoria_id,programa_codigo) VALUES ($international,{$ids['internacional']},'ingles')");
echo 'V5_IDS '.json_encode($ids).PHP_EOL;
