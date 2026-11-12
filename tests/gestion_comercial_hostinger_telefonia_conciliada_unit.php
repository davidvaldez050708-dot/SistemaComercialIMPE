<?php
/**
 * Contratos de Telefonía Hostinger conciliada:
 * preserva reintentos y connection pool antes de permitir No contactar.
 * Sin DB, sin servicios telefónicos, sin peticiones externas.
 */
function exigirContratoTelefonia(bool $ok,string $nombre): void {
    if(!$ok)throw new RuntimeException('TELEFONIA_HOSTINGER_CONCILIADA: '.$nombre);
}
$phone=file_get_contents(__DIR__.'/../app/services/TelefoniaMarcacionesVentasService.php');
$cp=file_get_contents(__DIR__.'/../prueba_telefonia/api/zadarma_estado_llamada.php');
exigirContratoTelefonia(is_string($phone)&&is_string($cp),'ambos archivos legibles');
$start=strpos($phone,'function iniciar(');
$guard=strpos($phone,'ProspectoNoContactarV3::exigirPermitido');
$insert=strpos($phone,'INSERT INTO telefonia_ventas_marcaciones');
exigirContratoTelefonia($start!==false&&$guard>$start&&$insert>$guard,
    'iniciar no genera llamada antes de validar restricción');
exigirContratoTelefonia(
    strpos($phone,'catch (mysqli_sql_exception $e)')!==false &&
    strpos($phone,'function reconciliarPendientes(')!==false &&
    strpos($phone,'interacciones_vinculacion')!==false,
    'se conservan reconciliación de llamadas duplicadas y separación de Vinculación');
$point=strpos($cp,'if ($solicitarCheckpoint)');
$cpGuard=strpos($cp,'ProspectoNoContactarV3::exigirPermitido');
$watermark=strpos($cp,'ultimoInicioSalienteId(');
exigirContratoTelefonia($point!==false&&$cpGuard>$point&&$watermark>$cpGuard,
    'checkpoint valida bloqueo ANTES de marca de agua de Zadarma');
exigirContratoTelefonia(
    strpos($cp,'Database::compartida()')!==false &&
    strpos($cp,'Database::responderFalloTemporal')!==false &&
    strpos($cp,'new TelefoniaExtensionService(Database::compartida())')!==false &&
    strpos($cp,'new ZadarmaWebhookEventStoreService(Database::compartida())')!==false &&
    strpos($cp,'(new Database())->connect()')===false,
    'se conserva el pool MySQL y las respuestas temporales de Hostinger');
exigirContratoTelefonia(
    strpos($cp,"['ok'=>false,'mensaje'=>\$e->getMessage()],409")!==false &&
    strpos($cp,'503')!==false,
    'denegación y error transitorio no inician llamadas');
echo "CHECK_OK: conexión compartida, límites PBX, vinculación segura y No contactar, sin BD ni red.\n";
