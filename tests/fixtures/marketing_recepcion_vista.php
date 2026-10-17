<?php
// Render aislado: no abre conexiones ni usa sesiones de usuarios reales.
$_SESSION=['csrf_token'=>'preview-csrf'];
function marketingTieneRecepcionAsignada(): bool {return true;}
require dirname(__DIR__,2).'/app/views/telefonia/recepcion_marketing.php';
