<?php
// Entrada pública estable. La autorización de programas sigue en el controlador existente.
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/../../app/services/RegistroEnlaceService.php';
try {
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    $code=$_GET['codigo']??'';if(!is_string($code))throw new DomainException('Enlace de Registro no válido.',404);
    $_GET=(new RegistroEnlaceService((new Database())->connect(false)))->resolver($code);
    $_REQUEST=$_GET;
}catch(Throwable $e){
    $status=$e instanceof DomainException?$e->getCode():503;http_response_code(in_array($status,[404,503],true)?$status:503);
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Registro</title><h1>Registro no disponible</h1><p>Verifica el enlace o inténtalo más tarde.</p></html>';exit;
}
require __DIR__.'/../../index.php';
