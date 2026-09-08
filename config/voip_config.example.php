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
