# Cadena de confianza HTTPS de INEGI

`inegi-ca-chain.pem` contiene las CA públicas del almacén de Mozilla distribuido por curl y el intermedio **Sectigo Public Server Authentication CA OV R36**. No contiene el certificado del sitio ni claves privadas. Se aplica sólo al host HTTPS `www.inegi.org.mx` desde el cliente DENUE; no cambia el almacén del servidor, la telefonía u otras APIs.

El 3 de noviembre de 2026 la conexión PHP/cURL falló con error 60, `unable to get local issuer certificate`. La inspección del sitio autenticada con el almacén nativo de Windows mostró un único certificado entregado por el servidor: `www.inegi.org.mx`, emitido por OV R36, vigente desde el 2 de noviembre de 2026 hasta el 19 de mayo de 2027. OpenSSL verificó ese certificado con nombre de host y cadena completa.

Procedencia del paquete:

- Almacén de CA descargado por HTTPS de <https://curl.se/ca/cacert.pem>. Conserva su encabezado y procedencia de Mozilla (MPL 2.0).
- Intermedio identificado por Authority Information Access del certificado: <http://crt.sectigo.com/SectigoPublicServerAuthenticationCAOVR36.crt>. Es información pública de la CA, no una consulta autenticada a DENUE. Su contenido fue validado criptográficamente con las raíces de Mozilla antes de incorporarlo; la descarga no se utiliza como ancla de confianza por sí misma.
- Certificado intermedio SHA-256: `6542d176bed50f193c0ce297ae44ecd8a0a86bec2ede682769344059b4e78530`.
- Raíz Sectigo Public Server Authentication Root R46 SHA-256: `7bb647a62aeeac88bf257aa522d01ffea395e0ab45c73f93f65654ec38f25a06`.
- El intermedio vence el 21 de marzo de 2036; el paquete no fija ni confía directamente en el certificado hoja que se renueva periódicamente.

La verificación de certificado y nombre de host permanece activa. La instalación comprueba la integridad del paquete. No se descargan CA ni certificados durante las búsquedas. Para actualizarlo, utilizar las fuentes oficiales, verificar firmas/vigencia y ejecutar nuevamente las pruebas; no utilizar `--insecure` ni desactivar `CURLOPT_SSL_VERIFYPEER`.

Documentación primaria: [TLS en curl](https://curl.se/docs/sslcerts.html), [intermedios de Sectigo](https://www.sectigo.com/knowledge-base/detail/Sectigo-Intermediate-Certificates).
