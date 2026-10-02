<?php

/*
 * Configuración local de WhatsApp Business Platform (Cloud API).
 * Copia este archivo como:
 *     config/whatsapp.local.php
 *
 * No subas whatsapp.local.php al repositorio.
 *
 * También puedes definir estos valores mediante variables de entorno:
 * WHATSAPP_ACCESS_TOKEN
 * WHATSAPP_VERIFY_TOKEN
 * WHATSAPP_APP_SECRET
 * WHATSAPP_GRAPH_VERSION
 * WHATSAPP_TEST_TEMPLATE
 * WHATSAPP_TEST_TEMPLATE_LANG
 */

return [
    // Token permanente o temporal de Meta para pruebas.
    'access_token' => '',

    // Token elegido por ustedes para validar el webhook con Meta.
    'verify_token' => '',

    // App Secret de la aplicación de Meta. Se usa para validar X-Hub-Signature-256.
    'app_secret' => '',

    // Versión vigente para esta integración al momento de la configuración.
    // Actualízala cuando Meta publique una versión posterior compatible.
    'graph_version' => 'v26.0',

    // La cuenta de prueba de Meta normalmente incluye una plantilla inicial.
    'test_template' => 'hello_world',
    'test_template_lang' => 'en_US'
];
