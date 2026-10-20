# Handly: programas autorizados y enlaces exclusivos

Entrega de aplicación: `d966b1d4313b62c5cc3b871eeec6fbcceed55e76`, basada en main `d93de87d2887557a495584084b953c0717fc9b4a`. No incluye los cambios de Ventas del PR110.

## Comportamiento

Registro e Inscripción exclusivos de **Handly Lenguaje Center · Houston, Texas, EE. UU.** muestran únicamente **Bachillerato General** y **Titulación por experiencia laboral**. Ambos POST validan el programa contra la configuración actual del aliado. Se revalida dentro de una transacción antes de insertar. Un token vacío, desconocido, mal formado, un aliado inactivo o sin programas no se convierte en formulario general: GET 404 y POST 422.

Los formularios generales, incluidos sus seis programas, las capturas internas y los enlaces existentes sin parámetro `aliado` conservan su comportamiento. No se modifican requisitos de domicilio del solicitante, teléfonos ni catálogos geográficos en este cambio. Houston identifica al aliado, no impone residencia al solicitante.

El token del enlace determina el aliado. No se acepta `aliado_id` del POST. Un reenvío del mismo correo normalizado, móvil, fecha de nacimiento y programa, dentro del mismo aliado y tipo de formulario, devuelve el registro original sin actualizar datos. Registro e Inscripción son gestiones independientes. No se deduplican ni se reasignan registros generales o de otros aliados.

## Almacenamiento aditivo

- `formulario_programas`: catálogo de programas.
- `formulario_aliados`: configuración pública del aliado, token aleatorio de 256 bits, ubicación, estado activo y `seguimiento_id` opcional, único, para asociar una institución ya existente.
- `formulario_aliado_programas`: asociación de cada aliado con sus programas autorizados. Una selección vacía no autoriza ninguno.
- `formulario_aliado_procedencias`: relación con `formulario_registros.id` o `formulario_inscripciones.id` según `tipo`, programa y copia histórica del nombre/ubicación del aliado. La clave única y el bloqueo transaccional impiden duplicados por reenvío.

No se crean instituciones de Vinculación ni se duplican expedientes. Handly se configura como aliado externo; si ya hay un expediente institucional, se puede relacionar su ID existente mediante `seguimiento_id` después de revisarlo. No se asigna uno por coincidencia de nombre. Los aliados actuales no son inscritos automáticamente en esta nueva configuración ni pierden sus enlaces.

Para futuras instituciones, un administrador de BD puede crear una fila de configuración con token generado por `random_bytes(32)`, asociar el `seguimiento_id` existente y añadir exclusivamente las filas autorizadas en `formulario_aliado_programas`. No hay pantalla nueva de administración. No publicar tokens en el repositorio ni regenerarlos durante una actualización. Revocar con `activo=0`. La configuración inicial de Handly es idempotente: una configuración existente distinta provoca error, nunca se sobreescribe silenciosamente.

## Pruebas

PHP/MySQL/MariaDB: ambos programas, ambos flujos, token inválido, aliado inactivo, lista vacía, programas de otro aliado, intentos de Inglés y otros programas, reenvío, procedencia y rollback del INSERT al fallar la relación. Navegador real: formularios originales con su JS y municipios, envío y redirección, POST adulterados, reenvíos simultáneos, formularios generales con Inglés, ausencia de escrituras en rechazos, escritorio 1440px y móvil 390px sin desbordamiento. Las pruebas crean un esquema local nuevo `impe_test_handly*`; no usan la BD del CRM.

El publicador se prueba en réplicas: base, personalización PHP fuera de los cambios, CRLF, conflicto y archivo nuevo que ya existe; incluye restauración exacta e idempotencia. No requiere Python en Hostinger; las pruebas del publicador también utilizan sintaxis compatible con Python 3.6.

## Publicación manual segura en Hostinger

**No ejecutar `git pull` en `public_html`. No se ha publicado nada automáticamente.**

1. Descargar el publicador de la revisión de este PR (usar la URL fijada a su commit de entrega), guardarlo fuera de `public_html` y revisar:

```bash
bash "$HOME/publicar-handly-programas.sh" --check
```

La preparación HTTPS queda fuera del sitio en `$HOME/crm-publicaciones/handly-programas-actual`. Revisa únicamente cinco archivos PHP. Conserva modificaciones PHP fuera de los bloques tocados; se detiene ante conflictos. `--check` no cambia archivos del sitio ni ejecuta SQL. No permite enlaces simbólicos en rutas de publicación o respaldo. Si pide revisión, no forzar el reemplazo.

2. Exportar una copia completa de la BD desde hPanel (o con la herramienta habitual de respaldo verificada), guardarla fuera de `public_html` y comprobar que es recuperable. La migración crea tablas; MySQL confirma DDL implícitamente. No ejecutarla sin respaldo.

3. Extraer únicamente el preparador y sus dependencias en una carpeta privada nueva:

```bash
VERSION=d966b1d4313b62c5cc3b871eeec6fbcceed55e76
PREPARACION="$HOME/crm-handly-$VERSION"
mkdir -m 700 "$PREPARACION" &&
git -C "$HOME/crm-publicaciones/handly-programas-actual" archive "$VERSION" \
  tools/preparar_handly_formularios.php \
  app/services/FormularioAliadoService.php \
  database/migrations/2026_10_19_formulario_aliado_programas.sql | tar -x -C "$PREPARACION"
```

4. Ejecutar **explícitamente** la preparación aditiva, usando la configuración privada del CRM real. Esta orden sí cambia la BD y requiere el respaldo del paso 2. No cambia tablas de expedientes, aliados actuales ni las dos tablas originales de formularios. Exige que ambas tablas originales sean InnoDB para asegurar el guardado transaccional.

```bash
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$PREPARACION/tools/preparar_handly_formularios.php" --apply
```

Si ya existe una selección Handly diferente, se detiene para revisión en lugar de sobrescribirla. DDL aditivo incompleto puede retomarse; los datos de configuración nuevos se crean dentro de una transacción.

5. Instalar los archivos y obtener los enlaces exclusivos:

```bash
bash "$HOME/publicar-handly-programas.sh" --apply
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$PREPARACION/tools/preparar_handly_formularios.php" --check
```

`--apply` respalda los archivos cambiados en `$HOME/respaldos-crm/handly-programas-FECHA-PID`, comprueba que no cambiaron desde la revisión, instala por archivo de forma atómica y restaura los alcanzados si falla. No ejecuta la migración. El preparador imprime los enlaces de Registro e Inscripción con el token real generado en esa BD: no utilizar los tokens de pruebas.

6. Abrir ambos enlaces reales: comprobar los dos programas, enviar una solicitud controlada de cada tipo, reenviarla y verificar una sola fila por solicitud y su procedencia. Adulterar el POST con Inglés debe devolver 422 sin inserción. Un enlace con token inválido debe mostrar no disponible. Comprobar un enlace general y uno previo de otro aliado.

## Restauración

Usar la ruta exacta impresa al instalar:

```bash
bash "$HOME/publicar-handly-programas.sh" --restore "$HOME/respaldos-crm/handly-programas-FECHA-PID"
```

Rechaza la restauración si el archivo instalado fue modificado después. La reversión de archivos no borra tablas ni procedencias nuevas. Conservarlas para evitar pérdida de registros recibidos. Para deshabilitar sólo los enlaces Handly basta revocar `activo`; no borrar registros ni restaurar toda la BD por un problema de presentación. Si se revierte el código, dejar de distribuir los nuevos enlaces exclusivos hasta reinstalarlo.
