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


## Teléfono de Ventas integrado en Inicio (2026-10-07)

- En **Inicio** el Asesor puede teclear números nacionales de diez dígitos
  (se antepone 52) o números en formato internacional.
- Reutiliza la misma sesión WebRTC Zadarma y la extensión personal; no hay pipeline comercial.
- El Inicio del Asesor con permisos muestra el marcador, historial y agenda personal en su propio dashboard.
- El menú **Marcador** se oculta para el rol Asesor de Ventas (escritorio y móvil).
  Los enlaces anteriores a `telefonia&action=marcador` redirigen a Inicio en ese rol.
  Para otros roles con permisos de telefonía se conserva el acceso independiente,
  sin alterar sus llamadas ni la configuración administrativa.
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
En **Inicio** se presenta un único bloque de telefonía para Ventas:

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


## Grabaciones en el historial de Ventas (2026-10-08)

En **Asesor de Ventas > Inicio > Llamadas recientes** se reutiliza el diseño
**Grabación** de las llamadas del expediente de Seguimientos: un botón despliega
el mismo reproductor con pausa, desplazamiento, volumen y descarga de audio.
La tabla utiliza `seguimiento_llamadas_expediente.css` y
`seguimiento_llamadas_player.css`, sin crear un estilo inconsistente.

- El historial proviene de `TelefoniaActividadService` y agrupa eventos
  por `pbx_call_id` y extensión asignada.
- Para llamadas salientes Zadarma con grabación reportada (`NOTIFY_RECORD`,
  `is_recorded` o `call_id_with_rec`) y un `out_<hash>` válido, aparece
  **Grabación**. Si una llamada reciente finalizada aún espera el evento
  puede aparecer **Procesando**; las demás indican **Sin grabación**.
- El navegador solicita el audio a
  `prueba_telefonia/api/grabacion_ventas.php?pbx_call_id=...`,
  no directamente a una URL de Zadarma.
- La ruta exige sesión activa y permisos `telefonia.usar` y
  `telefonia.salientes`; vuelve a leer los permisos y comprueba que la
  llamada saliente provenga de la extensión propia actualmente asignada
  y que exista un evento de grabación. No recibe enlaces arbitrarios.
- El servicio reutiliza `ZadarmaRecordingService`, el mismo que
  reproduce las llamadas del expediente, y admite rangos de bytes
  para mover la posición de reproducción y la descarga opcional.
- Solo se muestra actividad de los últimos 30 días y las 30 llamadas
  más recientes. Sin credenciales reales o con grabación deshabilitada
  en la PBX no aparecerá audio, aunque existan llamadas en el historial.

**Validación real pendiente con la cuenta Zadarma:** habilitar grabación en
la extensión/regla PBX, configurar el webhook público firmado, realizar una
llamada contestada, esperar `NOTIFY_RECORD`, actualizar Inicio y comprobar
reproducción y descarga. También confirmar que el asesor B **no pueda**
reproducir la grabación del asesor A cambiando el ID en la URL.


## Clasificación del resultado de llamadas de Ventas (2026-10-08)

**Inicio > Marcador:** tras finalizar una llamada Zadarma, el asesor registra
el resultado antes de iniciar la siguiente. Se ofrece:
- **Hablé con una persona** (única clasificación que habilita el audio)
- **Buzón de voz**
- **Fuera de horario de servicio**
- **Número inexistente o incorrecto**
- **No contestó**
- **Línea ocupada**
- **Mensaje de operadora o grabadora**
- **Otra situación sin contacto**

El historial conserva todos los intentos e incluye controles para
**clasificar o corregir** posteriormente un resultado.

El resultado se guarda en `telefonia_ventas_resultados` (migración
`database/migrations/2026_10_08_telefonia_ventas_resultados.sql`).
El método `telefonia&action=guardarResultadoVenta` exige POST, CSRF, sesión
del asesor, permisos de telefonía y `NOTIFY_OUT_END` firmado para el
`pbx_call_id` saliente de su propia extensión. No se confía en la etiqueta
`ANSWERED` del proveedor para contar personas que sí contestaron.

### Regla de grabaciones (cumplimiento del flujo)

Para habilitar audio en el historial son necesarias **ambas** condiciones:

1. La persona asesora marcó **Hablé con una persona** para esa llamada.
2. Zadarma reportó grabación y todavía está disponible mediante su API.

Si el resultado está pendiente o el asesor eligió buzón, fuera de horario,
número inexistente, no contestó, ocupado, mensaje automático u otro sin
contacto, **no se muestra el audio**. La ruta
`prueba_telefonia/api/grabacion_ventas.php` verifica la clasificación
en el servidor y devuelve 404 incluso si se intenta abrir la URL directamente.

**Limitación importante:** esta clasificación ocurre después de la llamada.
Por ello no impide que la centralita Zadarma genere un archivo de audio
de buzón o locución antes de recibir el resultado. El CRM evita su
reproducción/descarga, pero la desactivación o eliminación de archivos en
origen requiere una política/configuración adicional de Zadarma. Nunca
afirmar que el audio se borró o que no fue grabado en el proveedor.

### Pruebas a realizar con el webhook activo

1. Llamar al buzón de voz; marcar **Buzón de voz**. La llamada aparece
   en el historial, pero sin reproductor; URL de audio debe responder 404.
2. Marcar **Fuera de horario** o **Número inexistente** y verificar igual.
3. Conversar con una persona real, registrar **Hablé con una persona**,
   esperar `NOTIFY_RECORD`, actualizar y reproducir mediante el mismo
   reproductor del expediente.
4. Intentar clasificar un ID ajeno, inexistente o sin evento
   `NOTIFY_OUT_END`; el servidor debe rechazarlo.
5. Cambiar de **Hablé con una persona** a **Buzón de voz** desde el
   historial; al actualizar, debe desaparecer el botón y rechazarse la
   reproducción directa de una URL previamente conocida.
6. Comprobar que no se afectaron las grabaciones de los analistas.


## Agenda de prospectos: búsqueda y paginación (2026-10-08)

El bloque **Contactos telefónicos** dentro de **Asesor de Ventas > Inicio**
muestra **6 contactos por página** y controles numéricos para navegar
entre todas las páginas. Cuando hay muchas, se presentan extremos,
números próximos a la página activa y puntos suspensivos.

El campo **Buscar prospecto**, encima del formulario, filtra al escribir
sin botón de búsqueda ni recargar. Encuentra coincidencias por nombre
(sin distinguir mayúsculas o acentos) y número de teléfono, incluso si
el usuario introduce espacios o guiones en los dígitos. El paginador
se recalcula según los resultados; se muestra un aviso cuando no hay
coincidencias y otro cuando la agenda está vacía.

La agenda personal sigue siendo exclusiva del usuario autenticado y
tiene su límite actual de **150** registros en
`TelefoniaContactosService`. Por ese máximo, búsqueda y paginación
se realizan en el navegador sobre los contactos privados ya cargados.
Al guardar un prospecto se filtra su nombre para mostrarlo de inmediato;
al eliminar, la cantidad y el número de páginas se actualizan sin
recargar. No se modifica la lógica de llamadas, resultados o grabaciones.


## Separación definitiva de Marcador de Ventas y llamadas de Vinculación

**Asesor de Ventas (rol 3):** trabaja exclusivamente desde **Inicio** con
teléfono, agenda personal, registro de resultados, historial y grabaciones
comerciales. No se muestra un módulo Marcador adicional.

**Analista y otros roles:** no tienen acceso al Marcador comercial, ni
siquiera escribiendo manualmente la antigua ruta
`index.php?controller=telefonia&action=marcador`. Los analistas conservan
sus permisos WebRTC y llaman desde Seguimientos > Vinculación, registrando
las llamadas en el expediente. El módulo administrativo de Telefonía no
se elimina.

**Aislamiento del historial comercial:** ya NO basta con filtrar los
webhooks de Zadarma por extensión. Las extensiones pueden reasignarse y
mostrar llamadas antiguas de otro usuario o proceso. Antes de cada llamada
desde Inicio de Ventas se crea un intento exclusivo por asesor en
`telefonia_ventas_marcaciones` (migración
`database/migrations/2026_10_08_b_telefonia_ventas_marcaciones.sql`).
El inicio utiliza un token aleatorio de 128 bits que se mantiene en el
contexto DIALER del motor WebRTC sin exponerlo a otras cuentas.

Al obtener pbx_call_id, el cliente intenta asociarlo al registro:
el servidor exige sesión de Ventas, CSRF, extensión propia, destino
coincidente y evento NOTIFY_OUT_START dentro de la ventana de marcación.
Se rechazan llamadas asociadas a interacciones_vinculacion.
Si se cierra la ventana antes de que llegue el webhook, el servicio del
Inicio reconcilia pendientes con esos mismos controles al recargar.

El historial personal contabiliza únicamente llamadas con registro
comercial vinculado; por ahora **solo salientes desde Inicio de Ventas**.
Ni entrantes ni llamadas de Vinculación se suman a sus indicadores.
La clasificación humana y el acceso a las grabaciones comerciales
requieren también esa misma asociación comprobada en servidor.
Las grabaciones de Vinculación continúan en sus expedientes.

**Compatibilidad:** las llamadas antiguas de la extensión que no tengan
un vínculo comercial comprobable no se importan automáticamente al
historial de Ventas. Los eventos de Zadarma y las interacciones
institucionales anteriores se conservan en la base de datos: el cambio
es de visibilidad y propiedad, no una eliminación de registros.

**Pruebas con Zadarma:** marcar como Ventas desde Inicio, comprobar la
aparición en su propio historial y la clasificación posterior; simular
pérdida de conexión antes de NOTIFY_OUT_START y verificar la recuperación
al recargar; confirmar que un ID de llamada institucional no pueda
registrarse, reproducirse ni aparecer en el historial comercial;
validar que el analista siga llamando desde Vinculación.


## Marketing · Recepción telefónica en Inicio (2026-10-08)

**No se crea el rol Recepción**: el usuario Marketing con permisos
`telefonia.usar` y `telefonia.recibir` utiliza el mismo icono de audífonos
de la barra superior. Solo puede atender llamadas cuando dispone de su
propia extensión Zadarma activa y con `permite_entrantes` habilitado. El
indicador muestra **Verificando extensión**, **Extensión pendiente**,
**Recepción por activar** o **Recepción activa** conforme al estado
comprobado del motor WebRTC. El botón **Activar recepción** de Inicio abre
el mismo host WebRTC; no crea un teléfono ni un servicio independiente.

El Inicio de Marketing incorpora **Recepción telefónica**, respetando las
tarjetas y proporciones del CRM. Presenta los indicadores recibidas,
atendidas, perdidas y transferidas; tabla de los últimos 30 días,
búsqueda en vivo por número, filtro por resultado, ocho filas por página,
botones numerados y actualización automática cada 45 segundos mientras
el navegador está visible (no interrumpe audio en reproducción).

El endpoint de solo lectura
`prueba_telefonia/api/historial_recepcion_marketing.php` valida sesión,
rol Marketing y permisos, y filtra por la extensión activa **del usuario
autenticado**, excluyendo llamadas anteriores a la asignación o a la
última actualización de esa asignación para no exponer datos de otros
usuarios que utilizaron la misma extensión. Se usan únicamente eventos
`NOTIFY_INTERNAL` para establecer que la llamada entró a la extensión;
`NOTIFY_ANSWER`, `NOTIFY_END` y los datos de transferencias de los
webhooks determinan sus estados. No utiliza `telefonia_ventas_marcaciones`
ni expedientes de Vinculación.

**Grabaciones:** el historial abre el reproductor que también usan
Seguimientos y Ventas, pero mediante la ruta independiente
`prueba_telefonia/api/grabacion_recepcion_marketing.php`. El servidor
verifica sesión, permisos, usuario, extensión vigente, recepción,
contestación y existencia de audio en Zadarma. Por seguridad, **no se
habilita la reproducción de grabaciones transferidas**: un mismo archivo
de PBX puede incluir conversaciones de otra extensión. No se exponen
links privados de Zadarma al navegador.

**Límites:** el número institucional debe estar direccionado en la PBX
de Zadarma a la extensión de Tania para recibir llamadas reales. El
indicador verde confirma el motor WebRTC disponible, no la portabilidad
ni el enrutamiento del número 800. Las métricas dependen de webhooks
firmados y actualizados; una llamada transferida atendida puede contar
tanto en atendidas como en transferidas. Si falta un evento de la PBX,
el estado puede seguir pendiente. No se añaden tablas nuevas ni se
alteran grabaciones o resultados de Ventas y Vinculación.

**Pruebas reales pendientes:** asignar a Tania una extensión de recepción
activa desde el Administrador, activar el botón, llamar al número
institucional, atender, dejar una llamada sin respuesta y transferir otra.
Verificar el historial exclusivo, las métricas, el comportamiento cuando
no existe extensión y el bloqueo de audio de transferidas. Comprobar que
un usuario de otra extensión o sin permisos no pueda descargar el audio
alterando el `pbx_call_id`.

## Ajuste de prioridad del panel Marketing y audífonos por usuario (2026-10-08)

El rol Marketing se comparte entre quienes trabajan convocatorias y la
persona encargada de recibir llamadas. Los permisos de recepción se
administran por rol, pero **la interfaz telefónica de Marketing solo debe
mostrarse si ese usuario concreto tiene una extensión PBX Zadarma activa
y `permite_entrantes=1`**. La comprobación está centralizada en
`TelefoniaMarketingAccessHelper::marketingTieneRecepcionAsignada()` y se
aplica tanto al icono de audífonos de la barra superior como al panel de
Inicio. No se identifican usuarios por nombre, correo o rol especial.

Una cuenta Marketing sin extensión (p. ej. quien se dedica exclusivamente
a convocatorias) conserva el panel principal de convocatorias sin la
tarjeta de recepción ni un icono de audífonos que no pueda activar.

Cuando la extensión se haya asignado desde Administrador > Telefonía,
la cuenta recibirá los controles de recepción en su siguiente carga del
CRM. El botón abrirá el host WebRTC, sujeto a la conexión real con Zadarma;
no se puede activar sin extensión.

Se prioriza el contenido de Marketing: encabezado, indicadores de
convocatorias, gestión de convocatorias y publicaciones recientes. La
sección Recepción telefónica aparece **después de Publicaciones recientes**
y comienza **compacta**. Su botón «Ver historial» expande los indicadores,
filtros, grabaciones y paginación de recepción, sin abandonar la página.
No se elimina ni se mezcla ningún dato de las demás áreas.


## Administrador · Centro de Control Telefónico (2026-10-08)

`Telefonía > Control de llamadas` (`telefonia&action=index`) es
la pantalla principal de supervisión. Su consulta
`TelefoniaControlService` procesa webhooks firmados, permitiendo:

- Períodos personalizados de hasta 90 días recientes, fecha inicial y
  final incluidas; filtros por rol, usuario y dirección
- Llamadas únicas por PBX Call ID (distintas de las atenciones por extensión)
- Conectadas PBX (no necesariamente una conversación humana)
- Conversaciones verificadas por resultados humanos de Ventas/Vinculación,
  excluyendo buzón y notas `[SIN_CONTACTO_EFECTIVO]`
- Duración de atención observada (horas, minutos y segundos), no los
  minutos que facture Zadarma
- Ranking por usuario que puede ordenarse por atenciones, tiempo o
  conversaciones; tabla de distribución por roles y por extensiones
- Tendencia de los últimos 14 días del intervalo seleccionado
- Historial global de hasta 500 atenciones recientes, búsqueda en vivo
  y paginación de 10 filas; las métricas comprenden el período completo

**Precaución de atribución:** para asignar una llamada a una persona se
requiere un vínculo específico con el proceso: `interacciones_vinculacion`
para analistas, `telefonia_ventas_marcaciones` para Ventas o una
recepción entrante de Marketing ocurrida después de la última fecha de
asignación de su extensión. Las llamadas sin vínculo suficiente se
muestran como **Sin atribución verificada** y no se adjudican al usuario
actual en el ranking. Una transferencia puede generar varias atenciones
de una misma llamada y sumar tiempo en más de una extensión.

`Telefonía > Extensiones` (`telefonia&action=extensiones`) es
la pantalla separada de configuración. Mantiene los modales existentes,
las validaciones y los permisos `telefonia.configurar`. Los formularios
de guardar y liberar regresan a Extensiones para mostrar el resultado.
Los usuarios elegibles provienen de los permisos del rol, sin limitarse
a Analistas, Ventas ni Marketing; un rol futuro como Cuenta Clave podrá
aparecer si se le otorga `telefonia.usar`, siempre que cuente con las
capacidades y flujo telefónico necesarios. No se cambiaron esos permisos
automáticamente.

**Pruebas de aceptación pendientes con la PBX y base de datos reales:**
comprobar que 1 PBX ID transferido a dos extensiones cuenta como una
llamada única y dos atenciones; probar clasificaciones Ventas y
Vinculación incluyendo buzón; filtrar y ordenar ranking, revisar 90 días;
guardar y liberar extensión; verificar que otros perfiles no accedan al
Centro ni a Extensiones mediante URL directa.
