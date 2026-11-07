<?php
/**
 * Pruebas MySQL en base DESECHABLE. Nunca toca Hostinger.
 * Cobertura: un archivo/un registro, reintento, 36 cargas, paginación,
 * filtros por fecha México, duplicados y conteos consolidados.
 */
if (getenv('IMPE_HIST_META_DB_TEST')!=='YES') {fwrite(STDERR,"Requiere base de prueba desechable.\n");exit(2);}
require_once __DIR__.'/../app/services/ProspectoMetaImportacionesHistorial.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'','',
    (int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_meta_hist_'.bin2hex(random_bytes(5));
$n=0;
$check=static function(bool $condition,string $label) use (&$n) {
    if (!$condition)throw new RuntimeException('FALLO: '.$label);
    $n++;
};
try {
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    $db->query("CREATE TABLE usuarios (
       id INT NOT NULL PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100)
    ) ENGINE=InnoDB");
    $db->query("INSERT INTO usuarios VALUES (10,'Tania','Prueba'),(20,'Otro','Importador')");
    $migration=file_get_contents(
        __DIR__.'/../database/migrations/2026_11_07_prospectos_meta_historial_importaciones.sql'
    );
    $db->multi_query($migration);
    do {$r=$db->store_result();if($r)$r->free();}while($db->more_results()&&$db->next_result());
    $service=new ProspectoMetaImportacionesHistorial($db);
    $check($service->disponible(),'esquema instalado');
    $data=[
        'total_filas'=>5,'validas'=>3,'rechazadas'=>1,'repetidas_archivo'=>1,
        'formularios'=>[
            ['clave'=>'tit','id'=>'f:111','nombre'=>'Titulación Exp','total'=>2],
            ['clave'=>'bach','id'=>'f:222','nombre'=>'Bachillerato','total'=>1]
        ],
        'campanias'=>[
            ['id'=>'c1'],['id'=>'c2']
        ],
        'filas'=>[
            ['formulario_clave'=>'tit','campania_clave'=>'ct'],
            ['formulario_clave'=>'tit','campania_clave'=>'ct'],
            ['formulario_clave'=>'bach','campania_clave'=>'cb']
        ]
    ];
    $programas=[
        'version'=>2,
        'formularios'=>[
            'tit'=>['codigo'=>'TIT_EXP','nombre'=>'Titulación por experiencia'],
            'bach'=>['codigo'=>'BACH','nombre'=>'Bachillerato General'],
        ],
        'campanias'=>['ct'=>null,'cb'=>null]
    ];
    $stage=[
        'token'=>str_repeat('a',32),'usuario_id'=>10,'archivo'=>'Titulacion_2026-11-07.csv',
        'avance'=>3,'nuevos'=>2,'repetidos'=>1,'programas'=>$programas
    ];
    $db->begin_transaction();
    $service->registrarCompletada($stage,$data);
    $db->commit();
    $row=$db->query(
        'SELECT COUNT(*) n,SUM(nuevos) nuevos,SUM(repetidos_crm) rep,
         SUM(repetidos_archivo) rep_arch FROM prospecto_meta_importaciones_historial'
    )->fetch_assoc();
    $check((int)$row['n']===1 && (int)$row['nuevos']===2 &&
        (int)$row['rep']===1 && (int)$row['rep_arch']===1,
        'cuentas reales al completar un archivo');
    $service->registrarCompletada($stage,$data);
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_meta_importaciones_historial'
    )->fetch_assoc()['n']===1,'reintento mismo lote no duplica');
    $wrong=$stage;$wrong['nuevos']=3;$wrong['repetidos']=0;
    try {$service->registrarCompletada($wrong,$data);throw new Exception('Conflicto no rechazado');}
    catch (DomainException $esperada) {$n++;}
    $check((int)$db->query(
        'SELECT nuevos FROM prospecto_meta_importaciones_historial LIMIT 1'
    )->fetch_assoc()['nuevos']===2,'historial inmutable ante conflicto');
    $malos=$stage;$malos['token']=str_repeat('f',32);$malos['avance']=1;
    try {$service->registrarCompletada($malos,$data);throw new Exception('Carga parcial aceptada');}
    catch (RuntimeException $esperada) {$n++;}
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_meta_importaciones_historial'
    )->fetch_assoc()['n']===1,'archivo parcial no se registra');
    // El mismo archivo reimportado con otro lote registra otro EVENTO,
    // pero todos los leads ya existentes: cero nuevos y tres repetidos.
    $sameFile=$stage;$sameFile['token']=str_repeat('b',32);
    $sameFile['nuevos']=0;$sameFile['repetidos']=3;
    $service->registrarCompletada($sameFile,$data);
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_meta_importaciones_historial'
    )->fetch_assoc()['n']===2,'dos cargas del mismo archivo cuentan como eventos distintos');
    $history=$service->listar();
    $check($history['total']===2 && $history['indicadores']['nuevos']===2 &&
        $history['indicadores']['repetidos']===6 &&
        $history['indicadores']['filas']===10 &&
        $history['indicadores']['rechazados']===2,
        'indicadores agregados conservan repetidos y rechazados');
    $detail=$history['registros'][0]['formularios_detalle'];
    $check(count($detail)===2 && $detail[0]['programas'][0]==='Titulación por experiencia',
        'programa procede de clasificación confirmada y formulario');
    $check(strpos(json_encode($detail),'Tania')===false,
        'resumen de formularios no contiene nombres de responsables/leads');
    for($i=1;$i<=34;$i++) {
        $new=$stage;
        $new['token']=str_pad(dechex($i),32,'c',STR_PAD_LEFT);
        $new['archivo']='bachillerato_'.sprintf('%02d',$i).'.csv';
        $new['usuario_id']=($i%2===0)?10:20;
        $service->registrarCompletada($new,$data);
    }
    $all=$service->listar();
    $check($all['total']===36 && $all['paginas']===3 &&
        count($all['registros'])===15 && $all['pagina']===1,
        '36 archivos => 3 páginas numéricas de 15');
    $page2=$service->listar(['pagina'=>2]);
    $page3=$service->listar(['pagina'=>3]);
    $check(count($page2['registros'])===15 && count($page3['registros'])===6,
        'página 2 y 3 correctas');
    $checks=[];
    foreach ([$all,$page2,$page3] as $list)
        foreach ($list['registros'] as $r) $checks[$r['lote_ref']]=true;
    $check(count($checks)===36,'navegación sin filas duplicadas entre páginas');
    $far=$service->listar(['pagina'=>999999]);
    $check($far['pagina']===3,'página excesiva recortada');
    $filtrado=$service->listar(['buscar'=>'bachillerato_']);
    $check($filtrado['total']===34 && $filtrado['paginas']===3,
        'filtro de archivo y paginación conservada');
    $form=$service->listar(['buscar'=>'Titulación']);
    $check($form['total']===36,'búsqueda por formulario sin depender de teléfono');
    $none=$service->listar(['desde'=>'2030-01-01','hasta'=>'2030-12-31']);
    $check($none['total']===0 && $none['paginas']===1,'filtro de fechas sin filas');
    try {$service->listar(['desde'=>'2026-14-99']);throw new Exception('Fecha inválida aceptada');}
    catch (DomainException $esperada) {$n++;}
    try {$service->listar(['desde'=>'2026-12-01','hasta'=>'2026-11-01']);
        throw new Exception('Intervalo invertido aceptado');}
    catch (DomainException $esperada) {$n++;}
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_meta_importaciones_historial'
    )->fetch_assoc()['n']===36,'filtros de sólo lectura no alteran filas');
    echo "OK: $n pruebas MySQL del historial de Meta, duplicados, filtros y 36 archivos.\n";
} finally {
    $db->select_db('information_schema');
    $db->query("DROP DATABASE IF EXISTS `$name`");
    $db->close();
}
