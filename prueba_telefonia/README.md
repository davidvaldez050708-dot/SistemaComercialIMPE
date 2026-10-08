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
- `2026_10_07_c_zadarma_entrantes_indice.sql`

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

Activa las notificaciones PBX necesarias para entrantes y salientes:

- `NOTIFY_START`;
- `NOTIFY_INTERNAL` (entrada a la extensión);
- `NOTIFY_ANSWER`;
- `NOTIFY_END`;
- `NOTIFY_OUT_START`;
- `NOTIFY_OUT_END`;
- `NOTIFY_RECORD` cuando esté disponible para grabaciones.

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


## Marcador independiente de Ventas (2026-10-07)

- En **Teléfono > Marcador** el Asesor puede teclear números nacionales de diez dígitos
  (se antepone 52) o números en formato internacional.
- Reutiliza la misma sesión WebRTC Zadarma y la extensión personal; no hay pipeline comercial.
- El Inicio del Asesor con permisos muestra el marcador, historial y agenda personal en su propio dashboard.
- Muestra sus atenciones registradas en los últimos 30 días. Solo consulta su extensión.
- Administración > Telefonía muestra atenciones por extensión y minutos observados por
  webhooks. **No es saldo, coste o minutos incluidos del plan**; requiere consultar la
  API de estadísticas y facturación de Zadarma cuando estén disponibles sus credenciales.
- Una transferencia puede producir varios registros por extensión del mismo pbx_call_id:
  no equivalen al total de llamadas externas únicas.
- Para habilitar llamadas entrantes transferidas, se requiere `telefonia.recibir`,
  extensión habilitada para recepción y activación de WebRTC en la barra superior.

## Activación de la cuenta y número institucional

1. Activar la centralita virtual en Zadarma y definir las extensiones de Tania,
   analistas y Ventas; validar que todas existan en PBX.
2. Definir si el número existente en Callpicker se portará o se conectará como
   línea externa SIP. No asumir que el paquete contratado importa el número
   automáticamente. Coordinar con ambos proveedores y evitar dar de baja
   la línea hasta que el cambio esté validado.
3. Asociar el número entrante al escenario de recepción de Tania y configurar
   en PBX los reintentos/fallback cuando no atienda.
4. Obtener **API key y API secret** en Configuración > Integraciones y API,
   así como credenciales/extensiones PBX y Caller ID autorizado.
   Guardar solo en `config/zadarma_config.php` excluido del repositorio;
   nunca pegarlas en el chat ni en el código versionado.
5. Configurar URL HTTPS pública del webhook y notificaciones
   `NOTIFY_START`, `NOTIFY_INTERNAL`, `NOTIFY_ANSWER`,
   `NOTIFY_END`, `NOTIFY_OUT_START`, `NOTIFY_OUT_END`.
6. Ejecutar `php tools/verificar_telefonia_zadarma.php` y probar:
   entrante a Tania, transferencia directa/consultada a una extensión,
   devolución por ausencia, saliente desde Ventas y registro en historial.
7. Conciliar el reporte local de minutos observados con los segundos facturados
   de la API Zadarma antes de utilizarlo como indicador de consumo del paquete.


## Inicio del Asesor de Ventas y agenda personal

El perfil de Ventas no utiliza el proceso de vinculación ni crea un CRM comercial.
En Inicio y en Teléfono > Marcador se presenta el mismo bloque de telefonía:

- Teclado y marcación desde la extensión PBX individual asignada.
- Identificador de llamada (Caller ID) solo cuando esté configurado en la cuenta.
- Resumen e historial personal de eventos PBX de los últimos 30 días.
- Agenda privada para guardar hasta 150 personas prospecto con nombre y teléfono,
  seleccionar números para marcar y eliminar registros. No se deben guardar
  nombres de instituciones en este formulario. Pulsar "Usar número"
  **no inicia una llamada**.
- El historial es técnico, no facturación, y depende de los eventos recibidos
  por el webhook. La agenda guarda solamente contactos telefónicos de personas;
  no crea instituciones, oportunidades, expedientes de prospecto ni seguimientos.

La agenda se prepara mediante la migración
`database/migrations/2026_10_07_d_telefonia_contactos_personales.sql`.
También se asegura al iniciar el servicio. Los endpoints autenticados son:

- `telefonia&action=guardarContacto` (POST, token CSRF).
- `telefonia&action=eliminarContacto` (POST, token CSRF).

Ambas acciones usan el ID del usuario de sesión, comprueban permisos
`telefonia.usar` y `telefonia.salientes` y no admiten elegir otros usuarios.

### Recepción de Tania: sin rol nuevo

Tania conserva el rol **Marketing**. En Roles y permisos el administrador debe
verificar `telefonia.usar`, `telefonia.recibir` y `telefonia.transferir`.
Si dichos permisos fueron revocados manualmente, no se restauran por defecto.

La extensión PBX asignada a Tania debe tener llamadas entrantes activas. Solo
después de asociar el número institucional al escenario de recepción en Zadarma
podrán entrar llamadas de ese número; la portabilidad/conexión con Callpicker
se debe verificar con los proveedores.

Los asesores y analistas que puedan recibir transferencias requieren
`telefonia.recibir` y su extensión propia configurada para entradas.
El fallback legado de extensión compartida queda limitado al rol
Analista de Datos y no habilita Ventas con la extensión institucional.

### Pruebas previas a operación

1. Entrar como Asesor: Inicio debe mostrar marcador, agenda e historial, sin
   opciones de seguimiento comercial.
2. Sin extensión: botón Llamar inhabilitado, pero agenda accesible.
3. Con extensión activa: llamada saliente por WebRTC; confirmar audio y
   finalización sin obligar a registrar un resultado comercial.
4. Registrar, actualizar nombre, seleccionar y eliminar un teléfono privado.
5. Abrir sesión de otro asesor y confirmar aislamiento total de agendas
   e historiales personales.
6. Entrar como Marketing (Tania) y confirmar recepción, transferencia y
   el botón de auriculares.
7. Comparar los eventos recibidos en el historial con llamadas reales en
   Zadarma antes de habilitar supervisión operativa.
