<?php

/** Confianza específica de INEGI; no altera el almacén TLS del servidor ni otras APIs. */
class DenueTlsPolicy
{
    public static function opciones(string $url): array
    {
        $opciones = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https' &&
            strtolower((string)parse_url($url, PHP_URL_HOST)) === 'www.inegi.org.mx') {
            // CA de Mozilla más intermedio oficial de Sectigo. Nunca confiar en el certificado hoja.
            $opciones[CURLOPT_CAINFO] = __DIR__ . '/../../config/certificates/inegi-ca-chain.pem';
        }
        return $opciones;
    }
}
