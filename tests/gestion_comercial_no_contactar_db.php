<?php
/**
 * Bloqueo No contactar: MySQL/MariaDB DESHECHABLES, sin llamadas ni Meta.
 * Nunca ejecutar contra una base existente.
 */
if (getenv('IMPE_NO_CONTACTAR_TEST_DB')!=='YES') {
    fwrite(STDERR,"BLOQUEADO: habilitar solo fixture temporal con IMPE_NO_CONTACTAR_TEST_DB=YES.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/services/ProspectoNoContactarV3.php';

function checarContacto(bool $ok,string $msg): void {
    if (!$ok) throw new RuntimeException('NO_CONTACTAR: '.$msg);
}
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
    '',(int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_test_consentimiento_'.bin2hex(random_bytes(5));
$created=false;
try {
    $db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $created=true;
    $db->select_db($name);
    $db->query("CREATE TABLE prospectos_comerciales(
       id BIGINT UNSIGNED PRIMARY KEY, telefono_normalizado VARCHAR(24) NULL,
       INDEX idx_telefono (telefono_normalizado)) ENGINE=InnoDB");
    $db->query("CREATE TABLE prospecto_asignaciones(
       id BIGINT UNSIGNED PRIMARY KEY, prospecto_id BIGINT UNSIGNED NOT NULL,
       vigente TINYINT NOT NULL, INDEX idx_prospecto (prospecto_id)) ENGINE=InnoDB");
    $db->query("CREATE TABLE prospecto_embudo_subetapas(
       asignacion_id BIGINT UNSIGNED PRIMARY KEY, subetapa VARCHAR(40) NOT NULL
       ) ENGINE=InnoDB");
    $db->query("INSERT INTO prospectos_comerciales VALUES
      (1,'+527771234567'),(2,'+5217771112222'),(3,'+15551234567'),(4,'+527771231111')");
    $db->query("INSERT INTO prospecto_asignaciones VALUES
      (10,1,0),(20,2,1),(30,3,1),(40,4,1)");
    $db->query("INSERT INTO prospecto_embudo_subetapas VALUES
      (10,'NO_CONTACTAR'),(20,'NO_CONTACTAR'),(30,'NO_CONTACTAR'),(40,'PENDIENTE_REVISION')");

    foreach (['+527771234567','7771234567','52 777 123 4567',
              '+52 1 777 123 4567','7771112222','+5217771112222',
              '+15551234567'] as $number) {
        checarContacto(ProspectoNoContactarV3::bloqueado($db,$number),
            'no bloquea formato autorizado: '.$number);
    }
    foreach (['7771231111','+527771231111','+15551234568',
              '+17771234567','+34911223344'] as $number) {
        checarContacto(!ProspectoNoContactarV3::bloqueado($db,$number),
            'bloquea a destinatario ajeno: '.$number);
    }
    try {
        ProspectoNoContactarV3::exigirPermitido($db,'7771234567');
        throw new RuntimeException('Una marcación bloqueada continuó.');
    } catch(DomainException $ok) {
        checarContacto(strpos($ok->getMessage(),'No contactar')!==false,
            'motivo seguro y comprensible');
    }
    // Un registro histórico sin vigencia sigue prohibiendo nuevos contactos.
    checarContacto(ProspectoNoContactarV3::bloqueado($db,'7771234567'),
        'no revocar la prohibición al finalizar la asignación');

    foreach (['','abc','000','+52-XYZ'] as $bad) {
        try{
            ProspectoNoContactarV3::bloqueado($db,$bad);
            throw new RuntimeException('Número inválido admitido.');
        }catch(DomainException $ok){}
    }

    $phone=file_get_contents(__DIR__.'/../app/services/TelefoniaMarcacionesVentasService.php');
    $phoneGuard=strpos($phone,'ProspectoNoContactarV3::exigirPermitido(');
    $phoneInsert=strpos($phone,'INSERT INTO telefonia_ventas_marcaciones');
    // La clase incluye DDL del mismo nombre antes de iniciar: comprobar
    // especificamente la sentencia de insertar de iniciar().
    $inicio=strpos($phone,'public function iniciar(');
    checarContacto($inicio!==false&&$phoneGuard>$inicio&&$phoneInsert>$phoneGuard,
        'Zadarma: guardia debe preceder al INSERT de marcación en iniciar');

    $whatsapp=file_get_contents(__DIR__.'/../app/services/WhatsAppCloudApiService.php');
    $guard=strpos($whatsapp,'ProspectoNoContactarV3::exigirPermitido(');
    $call=strpos($whatsapp,'curl_exec($curl);',$guard);
    checarContacto($guard!==false&&$call!==false&&$call>$guard,
        'WhatsApp: guardia debe preceder al envío hacia Meta');

    $checkpoint=file_get_contents(
        __DIR__.'/../prueba_telefonia/api/zadarma_estado_llamada.php');
    $start=strpos($checkpoint,'if ($solicitarCheckpoint)');
    $rule=strpos($checkpoint,'ProspectoNoContactarV3::exigirPermitido');
    $lastId=strpos($checkpoint,'ultimoInicioSalienteId(');
    checarContacto($start!==false && $rule>$start && $lastId>$rule,
        'el checkpoint común protege la salida WebRTC de todos los roles');

    echo "CHECK_OK: teléfono, checkpoint Zadarma y WhatsApp bloquean No contactar.\n";
} finally {
    if ($created) $db->query('DROP DATABASE '.$name);
    $db->close();
}
