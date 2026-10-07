# Telefonía integrada

La telefonía del sistema utiliza **Zadarma WebRTC** como flujo principal para las llamadas de Vinculación. La carpeta conserva el nombre `prueba_telefonia` por compatibilidad con rutas existentes, pero sus endpoints Zadarma forman parte del flujo operativo actual del CRM.

Twilio permanece como compatibilidad histórica para llamadas/grabaciones antiguas y no debe ser el proveedor principal de nuevas llamadas de Vinculación.

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
