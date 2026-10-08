# Telefonía integrada

La telefonía del sistema utiliza **Zadarma WebRTC** como flujo principal para las llamadas de Vinculación. La carpeta conserva el nombre `prueba_telefonia` por compatibilidad con rutas existentes, pero sus endpoints Zadarma forman parte del flujo operativo actual del CRM.

Twilio permanece como compatibilidad histórica para llamadas/grabaciones antiguas y no debe ser el proveedor principal de nuevas llamadas de Vinculación.

## Arquitectura productiva

La telefonía se gobierna por permisos, no por nombres de rol:

- `telefonia.usar`: acceder al motor WebRTC.
- `telefonia.salientes`: originar llamadas.
- `telefonia.recibir`: mantener la extensión disponible para llamadas entrantes.
- `telefonia.transferir`: transferir una conversación a otra extensión.
- `telefonia.configurar`: administrar asignaciones PBX; reservado al Administrador.

Configuración inicial recomendada para Fundación Red Educativa:

- Extensión **100**: recepción / Marketing (Lic. Tania), entrantes + transferencias.
- Extensión **101**: Diego Bahena, llamadas salientes y entrantes.
- Extensiones siguientes: equipo conforme se incorporen.
- Número público: **800 044 0189** cuando finalice su portabilidad a Zadarma.

El número 800 debe apuntar en Zadarma al escenario/extensión de recepción. La extensión 100 recibe la llamada y puede transferirla a cualquier usuario con extensión activa y capacidad de recibir llamadas.

## Flujo productivo

1. El Administrador asigna una extensión PBX Zadarma a cada usuario desde **Telefonía**.
2. El Analista abre un seguimiento y pulsa **Llamar**.
3. El navegador abre/reutiliza el host WebRTC persistente y solicita una clave temporal a Zadarma.
4. La llamada se realiza desde la extensión asignada al usuario.
5. Zadarma publica eventos firmados al webhook del CRM.
6. El CRM persiste esos eventos en `telefonia_zadarma_eventos`.
7. Al terminar la llamada, el Analista registra el resultado.
8. La interacción exacta se vincula al `pbx_call_id`, duración y proveedor.
9. Si existe grabación, se muestra en el expediente cuando Zadarma termina de procesarla.
10. Solo las llamadas técnicamente válidas pueden alimentar las métricas de desempeño.

## Recepción y transferencias

Un usuario con `telefonia.recibir` dispone de un botón de auriculares en la barra superior.

Al comenzar la jornada debe pulsar **Activar recepción telefónica**. Ese clic abre el host WebRTC persistente y registra su extensión en Zadarma. La ventana técnica puede permanecer minimizada mientras el usuario navega por el CRM.

Cuando Zadarma publica `NOTIFY_INTERNAL` para la extensión:

1. el CRM identifica la llamada entrante por `pbx_call_id`;
2. muestra el número que llama;
3. trae al frente los controles oficiales WebRTC para contestar;
4. al recibir `NOTIFY_ANSWER`, cambia a conversación activa;
5. al recibir `NOTIFY_END`, finaliza el estado;
6. si aparece otro `NOTIFY_INTERNAL` con `transfer_from`, identifica la transferencia.

El panel **Transferir** consulta únicamente usuarios que tengan:

- extensión Zadarma activa;
- llamadas entrantes habilitadas;
- permiso `telefonia.usar`;
- permiso `telefonia.recibir`.

Zadarma define las combinaciones PBX:

- transferencia directa: `#101#`;
- transferencia consultada: `*101#`.

El CRM intenta enviar esa secuencia DTMF cuando la versión del widget la expone. Si el SDK no publica un método compatible, abre los controles oficiales de Zadarma y muestra el código exacto, evitando depender de una API JavaScript no documentada.

## Requisitos del servidor

- Sitio publicado mediante **HTTPS**.
- PHP con cURL habilitado.
- Dependencias instaladas mediante Composer.
- Permiso de escritura sobre `storage/` para el log diagnóstico.
- Base de datos actualizada con las migraciones de telefonía.

Migraciones relacionadas:

- `2026_09_29_resultados_telefonicos_vinculacion.sql`
- `2026_10_06_e_telefonia_extensiones_multiusuario.sql`
- `2026_10_06_f_telefonia_configuracion_admin.sql`
- `2026_10_07_b_zadarma_webhook_eventos.sql`

La aplicación también asegura automáticamente la existencia de las tablas de extensiones y eventos, pero las migraciones deben conservarse como fuente de despliegue.

## Configuración privada

Copia:

`config/zadarma_config.example.php`

como:

`config/zadarma_config.php`

y configura únicamente en el servidor:

- `api_key`
- `api_secret`

`pbx_extension` es solo un fallback legado. En producción cada usuario debe tener su propia extensión en `telefonia_extensiones`.

No subas `zadarma_config.php` con credenciales reales al repositorio.

## Configuración del webhook en Zadarma

Configura como URL pública:

`https://TU-DOMINIO/RUTA-DEL-SISTEMA/prueba_telefonia/api/zadarma_webhook.php`

Activa los eventos necesarios para llamadas salientes y grabaciones, al menos los equivalentes a:

- inicio de llamada saliente;
- respuesta;
- fin de llamada saliente;
- grabación disponible.

El endpoint valida la firma de Zadarma antes de aceptar eventos.

Los eventos se guardan en `telefonia_zadarma_eventos`. El archivo `storage/zadarma_webhooks.log` queda únicamente como respaldo diagnóstico y se rota automáticamente para evitar crecimiento indefinido.

## Navegador

El usuario debe permitir:

- micrófono;
- ventanas emergentes del mismo sitio.

La ventana emergente es un host técnico persistente. Puede permanecer minimizada; mantiene el WebRTC vivo mientras el usuario navega dentro del CRM.

## Reglas de contabilización

Una interacción de llamada se guarda como `LLAMADA_IP`.

Una llamada válida para métricas requiere:

- proveedor externo;
- identificador externo;
- duración de conversación mayor a cero.

La duración contabilizable proviene de Zadarma. El cronómetro del navegador no convierte por sí solo una llamada sin respuesta u ocupada en una llamada válida.

Una verificación efectiva requiere además:

- que Zadarma confirme que hubo respuesta;
- evidencia de datos verificados;
- persona que atendió;
- marca `[VERIFICACION_EFECTIVA]`.

Una misma institución se contabiliza como verificación efectiva una sola vez por día.

Las llamadas manuales, de prueba o sin vínculo técnico no deben contar como efectivas.

## Grabaciones

Las grabaciones se sirven mediante:

`prueba_telefonia/api/grabacion_interaccion.php`

El endpoint valida sesión, permisos y acceso al seguimiento antes de recuperar audio.

No se intenta mostrar grabación para resultados sin conversación, como:

- sin respuesta;
- ocupado;
- buzón de voz;
- fuera de servicio;
- número incorrecto.

## Verificador antes de llamar

Antes de una prueba real ejecuta desde la raíz del proyecto:

```powershell
C:\xampp\php\php.exe tools\verificar_telefonia_zadarma.php
```

En un servidor Linux/Hostinger con PHP disponible por CLI:

```bash
php tools/verificar_telefonia_zadarma.php
```

El script no imprime API key ni API secret. Comprueba PHP/cURL, Composer, base de datos, tablas, extensiones activas y conectividad real con la centralita Zadarma.

## Prueba integral recomendada

Realiza al menos estos casos con un seguimiento real de prueba:

1. Llamada contestada sin verificación.
2. Llamada contestada con verificación de teléfono/correo/contacto.
3. Sin respuesta.
4. Ocupado.
5. Colgar antes de respuesta.
6. Grabación disponible después del procesamiento.
7. Navegar a otra pantalla durante una llamada y regresar.
8. Intentar iniciar una segunda llamada mientras existe otra activa.

Después verifica:

- interacción exacta guardada;
- `proveedor_externo = ZADARMA`;
- `id_externo = pbx_call_id`;
- duración coherente;
- resultado correcto;
- historial visible;
- grabación visible cuando corresponda;
- actualización correcta de meta diaria y desempeño.

## Compatibilidad histórica

Los endpoints y servicios Twilio se conservan para no romper llamadas antiguas ya vinculadas con ese proveedor. No deben utilizarse para iniciar nuevas llamadas de Vinculación salvo que explícitamente se reactive ese proveedor.
