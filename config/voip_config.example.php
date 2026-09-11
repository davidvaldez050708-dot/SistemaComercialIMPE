<?php

/*
 * Configuración opcional para llamadas desde un cliente SIP/VoIP.
 *
 * tel    -> abre el marcador o aplicación telefónica predeterminada.
 * callto -> abre un cliente compatible con el protocolo callto.
 * sip    -> abre sip:NUMERO@DOMINIO en el softphone configurado.
 */
define('VOIP_SCHEME', 'sip');
define('VOIP_SIP_DOMAIN', 'pbx.ejemplo.com');

/**
 * Telefonía integrada con Twilio.
 *
 * Copia este archivo como config/voip_config.php y completa los valores reales.
 * config/voip_config.php está ignorado por Git y NO debe subirse al repositorio.
 */
return [
    // Endpoint /voice-token del quickstart Serverless ya inicializado en Twilio.
    // Ejemplo: https://mi-quickstart-1234-dev.twil.io/voice-token
    'token_url' => 'https://TU-DOMINIO-TWILIO.twil.io/voice-token',

    // Se usan únicamente desde PHP para validar Caller IDs, consultar historial
    // y reproducir grabaciones. Nunca expongas estos valores en JavaScript.
    'account_sid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'auth_token' => 'TU_AUTH_TOKEN_PRIVADO',

    // Número institucional/base que ya utiliza la cuenta. Se conserva como
    // referencia y también se considera autorizado si coincide con el teléfono
    // de un usuario. Las llamadas productivas toman el Caller ID desde
    // usuarios.telefono y requieren que ese número esté comprado o verificado
    // en Twilio. Ver prueba_telefonia/TWILIO_CALLER_ID_USUARIO.md.
    'caller_id' => '+1XXXXXXXXXX',
];
