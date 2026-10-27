# Auditoría de horarios — 27 de octubre de 2026

## Hallazgo confirmado en Hostinger

A las 08:53:34 de México, la consulta facilitada por el usuario devolvió:

| Fuente | Configuración | Hora |
| --- | --- | --- |
| PHP | America/Mexico_City | 2026-10-27 08:53:34 |
| PHP UTC | UTC | 2026-10-27 14:53:34 |
| MySQL NOW() | SYSTEM; servidor UTC | 2026-10-27 14:53:34 |
| MySQL UTC_TIMESTAMP() | UTC | 2026-10-27 14:53:34 |

Los relojes representan el mismo instante. La diferencia visible aparece cuando se interpreta una fecha UTC sin zona como si ya fuera hora local. PHP configura México; la conexión SQL no configura otra zona y hereda UTC.

El diagnóstico confirmó `usuarios.ultimo_acceso` como DATETIME y `usuarios.created_at`/`updated_at` como TIMESTAMP. También confirmó `interacciones_vinculacion.fecha_inicio` y `reuniones_vinculacion.fecha_propuesta` como DATETIME: estos últimos no se pueden convertir todos de la misma manera porque sus escritores usan convenciones distintas.

## Corrección preparada: fechas de Usuarios

`UsuarioModel::actualizarUltimoAcceso` guardaba NOW() (UTC en el servidor actual). Tabla, formulario, Mi perfil e Inicio lo recibían sin conversión. El reporte administrativo y su PDF también consumían ese valor sin convertir.

La corrección afecta únicamente a `UsuarioModel`, `ReporteAdministradorModel` y el nuevo `UsuarioFechaHoraHelper`:

- Los accesos nuevos se guardan expresamente con UTC_TIMESTAMP(), manteniendo la convención UTC de los valores existentes y evitando depender de la zona de la conexión.
- La lectura transforma ese DATETIME UTC a America/Mexico_City. Se conserva `ultimo_acceso_utc` como referencia interna y se evita aplicar dos conversiones.
- Las fechas TIMESTAMP de alta/modificación se leen también mediante UNIX_TIMESTAMP y se presentan por su instante real, independientemente de la zona de la conexión SQL.
- Los valores vacíos permanecen sin registro. Las fechas inválidas no se sustituyen por fechas inventadas. Se admiten fracciones de segundo.
- Usuarios, sus filtros, formulario de edición, Mi perfil, usuarios recientes de Inicio, reportes administrativos y sus exportaciones reciben la misma hora local sin cambiar sus plantillas, estilos o diseños.

Por ejemplo: `27/10/2026 14:34 UTC` se presenta como `27/10/2026 08:34` en México. `27/10/2026 03:04 UTC` corresponde a `26/10/2026 21:04` local: la corrección puede cambiar también el día mostrado.

La instalación no resta horas a los registros existentes, no ejecuta migraciones, no cambia la configuración de la base de datos y no cambia las fechas ni los audios de llamadas.

## Otros puntos encontrados en el código auditado

Estos hallazgos identifican dónde puede aparecer el otro desfase que recuerda el usuario. No se incluyen en el parche de Usuarios; necesitan una corrección por tipo de fecha y una comprobación de las versiones instaladas antes de publicarse.

| Área | Evidencia | Efecto posible / estado |
| --- | --- | --- |
| Recordatorios de seguimiento | `ReminderHelper.php` compara `proxima_accion_at` contra NOW()/DATE_ADD(NOW()). La acción elegida en formulario es local. | La selección y prioridad SQL pueden adelantarse seis horas. Algunos avisos vuelven a comprobar el tiempo en PHP; no se afirma que todos los toast se envíen antes. El desfase de la comparación se reprodujo con una base aislada en UTC. |
| Inicio del analista: atención, reuniones, próximos eventos | `AnalistaDashboardModel.php`, `AnalistaDashboardReunionService.php` y `AnalistaDashboardIntegrityService.php` usan NOW()/CURDATE() para fechas de próxima acción y reuniones. | El resumen puede discrepar de la agenda o de los recordatorios calculados en PHP local. |
| Agenda de reuniones | Varias acciones de `AgendaReunionRepository.php` ya comparan contra la hora PHP, pero otros filtros y servicios de resultado/fecha conservan NOW(). | Hay una política mezclada: mostrar, permitir una acción y clasificar su vencimiento pueden dar resultados diferentes. |
| Reportes y límites de día/mes | Consultas con CURDATE(), DATE(), DATE_FORMAT() y fechas de origen distinto. | En UTC el día cambia seis horas antes que en México; un corte diario/mensual o agrupación puede caer en otro día. No todas las consultas están afectadas: algunas reciben el rango local explícitamente. |
| Vigencia de territorios | Las consultas de asignaciones usan CURDATE() de SQL para fecha_inicio/fecha_fin. | Una asignación que inicia a medianoche de México puede activarse desde las 18:00 locales del día anterior si se evalúa por la fecha UTC. Es un límite de fecha, no una razón para reescribir asignaciones. |
| Cronología de gestiones e interacciones | Hay DATETIME generados con NOW(), otros con date() de PHP, fechas históricas de Excel y sesiones SDK en UTC. | No existe una regla segura que consista en restar seis horas a toda la tabla. Se debe distinguir origen y propósito antes de corregir lectura, escritura o agrupación. |
| Llamadas / grabaciones | `TelefoniaFechaHoraHelper` convierte exclusivamente ZADARMA_WEBRTC y conserva las interacciones locales. Transferencias/disponibilidad usan UTC explícito y otras rutas ya convierten al presentar. | Las conversiones existentes son necesarias y se conservan. Cambiar globalmente la zona SQL o convertir indiscriminadamente puede producir una segunda conversión. |
| Programadas del asesor | `TelefoniaProgramacionVentasService` calcula pendientes con la hora local PHP; otros campos técnicos de cierre usan NOW(). | El vencimiento local y la marca técnica de atención son conceptos distintos; revisar cada campo sin modificar por defecto la fecha programada por el asesor. |

Ejemplo reproducido: con hora local 08:53 y SQL 14:53, una tarea local para las 10:00 es futura en México, pero la condición `fecha < NOW()` en SQL la clasifica como vencida. La prueba conserva este caso como evidencia; el parche de Usuarios no altera esas consultas.

## Criterio para la siguiente corrección de horarios operativos

1. Fechas elegidas por una persona (agenda, próxima acción, vigencia por día) deben compararse con el reloj y calendario de negocio de México.
2. Instantes técnicos (accesos, eventos de proveedores, auditoría) deben tener origen explícito y convertirse una vez al mostrar.
3. Duraciones y plazos de segundos deben compararse sobre instantes de la misma zona.
4. Los datos históricos con orígenes mezclados requieren revisar sus escritores y metadatos. No se corrigen desplazando toda la columna.

## Pruebas y publicación

`tests/usuarios_hora_mexico_mysql.py` usa una copia temporal con configuración local y una base efímera exclusiva. Nunca carga credenciales ni INSERT con datos reales del CRM. Prueba nueve combinaciones de zonas PHP/SQL, cambio de día/mes/año, nulos, valores inválidos y microsegundos; accesos nuevos UTC con SQL en otra zona; edición de usuario sin alterar su acceso; tabla real, serializador de Mi perfil, formatter PDF y conservación del historial telefónico. Compara las fechas almacenadas antes y después de las lecturas y ejecuta el comprobador CLI real en una transacción de sólo lectura.

`tests/publicacion_usuarios_hora_mexico_test.py` prueba las versiones exactas de Hostinger, LF/CRLF, check sin escrituras, rechazo de cambios desconocidos, aplicación idempotente, respaldo y restauración. El helper de llamadas y una grabación de control permanecen intactos.

Publicación selectiva: `tools/publicar_usuarios_hora_mexico.py` y `tools/usuarios_hora_mexico_release.json`. Sólo instala los tres archivos de Usuarios. No se debe copiar la rama completa al sitio. Los hashes iniciales comprobados son UsuarioModel `0136bbf6…` y ReporteAdministradorModel `8b3b5b00…`.

```bash
python3 publicar_usuarios_hora_mexico.py --site "$SITIO" --backup-root "$RESPALDOS" --check
python3 publicar_usuarios_hora_mexico.py --site "$SITIO" --backup-root "$RESPALDOS" --apply
php verificar_hora_usuarios.php --site "$SITIO"
```

La verificación final sólo imprime relojes, tipos de columnas y la fecha de acceso más reciente en UTC/México. No imprime personas, correos ni teléfonos. Para revertir usar `--restore` con la ruta privada de RESPALDO que imprime la instalación.
