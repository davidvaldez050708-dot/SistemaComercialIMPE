<?php

/*
 * Plantilla SIN credenciales reales para los servicios oficiales.
 * Copia los valores secretos a config/api_keys.local.php (ignorado por Git),
 * o al directorio privado de Hostinger.
 */

define('INEGI_INDICADORES_TOKEN', '');

define(
    'INEGI_INDICADORES_BASE_URL',
    'https://www.inegi.org.mx/app/api/indicadores/desarrolladores/jsonxml'
);

define('DENUE_TOKEN', '');

define(
    'DENUE_BASE_URL',
    'https://www.inegi.org.mx/app/api/denue/v1/consulta'
);