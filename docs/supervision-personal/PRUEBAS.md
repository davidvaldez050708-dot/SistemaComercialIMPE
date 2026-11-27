# Pruebas de supervisión de personal

Las pruebas automatizadas se ejecutaron con PHP 8.2, MariaDB local aislada en `127.0.0.1:33319` y Chrome, con personas e instituciones ficticias. No cargan la configuración MySQL de producción. El publicador se probó exclusivamente sobre carpetas temporales.

## Ejecución local

`tests/supervision_personal_db.php` admite sólo puertos locales 33319 o 33320 y genera una base `impe_sp_test_*` que elimina al terminar. Requiere un MariaDB de pruebas sin datos reales con cuenta local `root` sin contraseña, exclusivamente en ese puerto. No apuntarlo al servicio de desarrollo habitual.

```powershell
php tests/supervision_personal_db.php
```

Incluye `supervision_personal_integracion.php`, que ejecuta métodos de las clases reales sin cargar sus configuraciones externas ni invocar proveedores. Comprueba acceso directo, autor exacto en audio, coautores, filtros manipulados, desempeño entre estados, alcance propio/nacional e integridad territorial.

Para probar la interfaz, crear la base efímera conservada con `SP_KEEP_DATABASE=1`; el marcador privado local queda en `tmp/sp-test-state.json`. La prueba de navegador usa el controlador real conectado sólo a esa base. La variable debe retirarse al terminar. Requiere Playwright y Bootstrap en `../browser-legacy-deps/node_modules`, PHP y Chrome locales; las rutas están al inicio del archivo. `SP_SCREENSHOT_DIR` permite guardar capturas temporales. Si se dispone de una copia de los CSS actuales en `tmp/hostinger-base/public/css`, la revisión usa esa referencia institucional.

```powershell
$env:SP_KEEP_DATABASE='1'
php tests/supervision_personal_db.php
Remove-Item Env:SP_KEEP_DATABASE
node tests/supervision_personal_browser.cjs
```

Al terminar, eliminar sólo las bases efímeras identificadas por esa ejecución y detener únicamente el servidor local creado para esta prueba.

Las pruebas del paquete requieren una copia privada de referencia de los archivos existentes (estructura idéntica al sitio). No la descargan ni crean automáticamente:

```powershell
$env:SP_BASELINE_DIR='RUTA_DE_LA_COPIA_PRIVADA'
py -3 -m unittest discover -s tests -p test_supervision_personal_release.py -v
```

Valida fuentes/manifiesto, personalizaciones ajenas, LF/CRLF, idempotencia, autorización del publicador, bloqueo sin escrituras y reversión que respeta cambios posteriores. Para reconstruir el manifiesto tras una revisión de código, usar `tools/generar_release_supervision_personal.py --baseline-dir RUTA_DE_LA_COPIA_PRIVADA` y volver a ejecutar estas pruebas.

## Matriz obligatoria de staging antes de autorizar producción

| Área | Validación | Resultado esperado |
|---|---|---|
| Administración | Admin con permiso; Dirección/KAM y acceso directo sin permiso | Sólo el administrador autorizado consulta o modifica el módulo |
| Selección | Buscar, filtrar, seleccionar varias personas, volver del resumen, cancelar | Conserva selección; cancelar/previsualizar no escribe |
| Confirmación | Dos administradores abren la misma revisión; uno guarda primero | El segundo recibe conflicto y debe revisar; no sobrescribe |
| Idempotencia | Respuesta de guardado perdida, repetir la misma confirmación | Un movimiento, sin duplicar bitácora |
| Alcance | José/Jalisco supervisa a Mayra/CDMX, que sigue con Sarahi | Ambos acceden a la persona; no cambian territorio, KAM operativo ni cartera |
| Revocación | Retirar sólo a José, sin renovar sesiones | Nuevas consultas, exportaciones, detalles y audio de José denegados; Sarahi conserva acceso |
| Reportes | Actividad analistas/asesores, HTML y exportaciones/PDF; filtros persona/estado e IDs alterados | Sólo autores supervisados; sin duplicar hechos ni filtrar coautores de un expediente compartido |
| Otros reportes | Cartera e institución | Mismo alcance territorial y resultados que antes |
| Audio | Supervisión y enlace antiguo de interacción; reproducción/rangos/descarga autorizados | Permiso general y autor supervisado obligatorios; no consultar proveedor para un ID ajeno |
| Permisos | Retirar permiso general de reportes, desempeño o audio | La relación de supervisión no sustituye el permiso retirado |
| Desempeño | Analistas y asesores en distintos estados, Dirección nacional | Alcance por personas; los indicadores nacionales no se multiplican |
| Históricos | Colaborador inactivo asignado; cambio de rol o usuario eliminado | Conserva evidencia; sin altas nuevas de inactivos ni bajas silenciosas al filtrar |
| Operación | Convocatorias, información territorial, seguimiento/expedientes y prospectos | Mismos territorios, permisos, responsables y datos |
| Telefonía | Prueba autorizada de llamada, cierre/resultado, transferencia y escalamiento | Mismo flujo instalado; ninguna decisión de enrutamiento usa la tabla nueva |
| Migración | Plan obsoleto/alterado, ambigüedad sin decidir, preparación parcial | Bloqueo; no corregir ni eliminar asignaciones operativas |
| Reversión | Desactivar modo y restaurar archivos con respaldo | Alcance anterior; conserva nuevas relaciones y bitácora; bloquea sobreescribir cambios posteriores |
| Interfaz | Escritorio/móvil, carga fallida, sesión expirada, CSRF, error validación y éxito | Estilo institucional, controles coherentes y toasts con cierre automático |

Esta matriz en staging con la versión personalizada completa, los datos reales auditados y los enlaces de audio autorizados queda pendiente. Las pruebas locales no acreditan funcionamiento de llamadas reales, permisos efectivos del alojamiento, exportación PDF completa ni convivencia con cambios de telefonía que se publiquen posteriormente.
