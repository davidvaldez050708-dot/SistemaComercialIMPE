# Aliados → Registro → Prospectos

Base revisada: `main` a90b6aded4a7f606eccc7a27749658e3fed9b9ed. Rama: `feat/aliados-programas-registro`.

## Flujo y alcance

Cuenta Clave abre **Aliados → estado → Agregar aliado**, captura institución y municipio, y selecciona programas activos. Puede editar, desactivar o gestionar los programas de un aliado procedente de Vinculación. Se valida permiso y asignación territorial vigente en cada operación; Administración conserva la consulta.

Marketing encuentra ambos orígenes en **Convenios**, abre el detalle y genera el enlace estable de **Registro**, su QR local o deshabilita la captura. Generar de nuevo no cambia el token ni reactiva un enlace deshabilitado. Crear convocatoria sigue siendo una operación independiente; un aliado manual necesita un expediente real para esa operación, no para Registro.

La persona externa selecciona sólo programas autorizados y su estado/municipio de residencia. El servidor vuelve a validar token, habilitación, programa y municipio antes de insertar. Prospectos muestra la institución de origen y permite filtrar por ella o por Registro general. Se conservan los programas y nombres históricos.

No se editaron vistas, scripts ni métodos operativos de Inscripción, ni archivos de Telefonía. Los nuevos perfiles sólo son válidos para Registro. Los tokens y autorizaciones anteriores de Handly se conservan.

## Auditoría y dependencias

La base ya contenía `FormularioAliadoService`, procedencias transaccionales y autorizaciones por programa, pero no gestión manual, configuración desde Aliados, enlaces desde Convenios ni lectura de origen en Prospectos. La lista general de programas estaba en PHP; se reconcilia el catálogo mediante códigos y nombres exactos, sin renombrar ni equiparar programas históricos. `bachillerato-286` sigue siendo Bachillerato 286; no se equipara con Bachillerato General de Handly.

Los PR abiertos #113 y #114 comparten formularios/convocatorias. El manifiesto admite ahora la revisión internacional `20ec4fb64f211b06cde114da76d6c84e65a96db5`, comprobada en Hostinger por comparación sin diferencias de controlador, servicio y modelo públicos. Las diez variantes revisadas conservan país/estado/ciudad, convocatoria, CSRF y la persistencia internacional. Cinco superposiciones semánticas se resuelven mediante los parches versionados en `tools/compat/aliados_registro_pr114`; las restantes se concilian sin solapamiento. El publicador compara el contenido completo (admite CRLF), nunca acepta una variante por nombre o rol. Una personalización desconocida superpuesta sigue bloqueando toda la instalación. No instala SQL, no sustituye Inscripción y no despliega el resto de PR114.

Para regenerar la entrega reproducible: `python3 tools/preparar_entrega_aliados_registro.py --base a90b6aded4a7f606eccc7a27749658e3fed9b9ed --source bc8e5c1611e7bff62fc91e12af7166e9dc36861d --compatible-pr114`. Las pruebas construyen una réplica de esa revisión, aplican los 30 archivos, ejecutan Registro nacional e internacional/Inscripción y verifican restauración byte a byte. La comprobación real del sitio completo sigue siendo necesaria; si aparece otro conflicto, detenerse y revisar la variante concreta.

QR: biblioteca MIT local `qrcode-generator`, fuente y commit fijados en `public/vendor/qrcode-generator/SOURCE.txt`. El personalizador anterior enviaba la URL a un servicio externo; esta sección reutiliza los controles y estilos institucionales pero genera la imagen localmente, para no transmitir tokens a terceros.

## Datos y permisos

- `aliados_manuales`: institución, estado, municipio, contacto, fecha de relación opcional, referencia, notas, situación, baja lógica, autor y fechas. La fecha de relación no acredita formalización.
- `formulario_aliado_registro`: origen manual o seguimiento real, único por origen; referencia al `formulario_aliados` utilizado por el servicio existente; publicación, habilitación y último responsable.
- Se reutilizan `formulario_programas`, `formulario_aliado_programas` y `formulario_aliado_procedencias`. Los prospectos permanecen en `formulario_registros`. El JOIN de Prospectos exige `tipo='registro'`; no mezcla Inscripciones con IDs coincidentes.
- Los perfiles nuevos mantienen `formulario_aliados.activo=0` para lectores antiguos e Inscripción. Sólo el resolver de Registro admite ese perfil mediante su configuración explícita. Una restauración del código anterior deja esos enlaces cerrados, sin habilitar Inscripción accidentalmente.
- Permisos nuevos: `aliados.crear_manual`, `aliados.editar_manual`, `aliados.gestionar_programas` para Cuenta Clave; `formularios_aliados.gestionar_enlaces` para Marketing. No se asignan operaciones nuevas a Administración.
- Bloqueo transaccional y claves únicas para altas/solicitudes concurrentes. CSRF interno; CSRF, señuelo y límite por sesión para los nuevos enlaces públicos. Los formularios generales y enlaces históricos conservan su contrato.

La migración `2026_10_21_aliados_registro.sql` es aditiva e idempotente. Requiere las tablas territoriales, usuarios, permisos y la migración `2026_10_19_formulario_aliado_programas.sql`. El preparador rechaza colisiones de códigos/nombres en vez de adivinar equivalencias. No instala Handly ni cambia sus programas.

## Pruebas

- `tests/aliados_registro_db.php`: 47 comprobaciones de gestión, territorio, programas, procedencia, históricos, bajas, permisos y aislamiento de Inscripción.
- `tests/aliados_registro_browser.cjs`: 52 comprobaciones con navegador real, endpoints reales de sesión/CSRF, recorrido completo, QR decodificado, filtros y concurrencia de cuatro procesos independientes.
- `tests/aliados_registro_snapshot.php`: dos conexiones reales fuerzan el snapshot anterior al bloqueo y recuperan una única procedencia. Registro utiliza READ COMMITTED sólo para su transacción y lectura actual de la clave idempotente; no altera la sesión ni Inscripción.
- Escritorio 1366/1440, móvil 390 y horizontal 844×390: modales con footer accesible y sin desbordamiento horizontal de página. Revisión de capturas de los componentes nativos.
- Regresiones existentes `formulario_aliados_db.php`, `aliados_internacionales.php` y `formulario_aliados_browser.cjs`: Handly, Registro/Inscripción generales, autorizaciones, duplicados y consulta internacional.
- `tests/publicacion_aliados_registro_test.py`: revisión sin escritura al sitio, mezcla de personalizaciones, CRLF, conflictos, integridad, sintaxis, aplicación idempotente y restauración, incluido el manifiesto real.
- PHP 8.2, Node 22 y MariaDB **10.4.32 local**. El workflow `aliados-registro.yml` ejecuta además la matriz **MySQL 8 / MariaDB 10.11**; consultar su resultado en el PR antes de integrar. No atribuir a esos motores una prueba local con 10.4.

El router de pruebas sólo funciona en localhost, con bandera explícita y esquema sintético. Nunca debe utilizarse para acceder al CRM de producción.

## Archivos de publicación

La lista exacta de 30 archivos, sus versiones base, candidatos y SHA-256 están en `tools/aliados_registro_release.json`. Abarca los controladores/modelos/servicios anteriores, vistas de Aliados/Convenios/Registro/Prospectos, carga de assets, CSS/JS compartidos del módulo, biblioteca QR local, migración y preparador. No incluye pruebas, credenciales, configuración privada ni Telefonía/Inscripción.

El manifiesto contiene los archivos del commit de aplicación `bc8e5c1611e7bff62fc91e12af7166e9dc36861d`. Se instala con el publicador y manifiesto del commit de entrega indicado en el PR.

## Comandos en Hostinger — ejecución manual

Reemplazar `COMMIT_DE_ENTREGA_REVISADO` por el commit de entrega del PR, no por `main`. Python 3.6 es suficiente; se requieren `git` y `php`. Los archivos del paquete y respaldos quedan fuera de `public_html`.

```bash
ENTREGA=COMMIT_DE_ENTREGA_REVISADO
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/entregas-impe/aliados-$ENTREGA"
mkdir -p "$PAQUETE" "$HOME/respaldos-impe-aliados" "$HOME/respaldos-impe-bd"
chmod 700 "$PAQUETE" "$HOME/respaldos-impe-aliados" "$HOME/respaldos-impe-bd"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$ENTREGA/tools"
curl -fL "$BASE/publicar_aliados_registro.py" -o "$PAQUETE/publicar_aliados_registro.py" &&
curl -fL "$BASE/aliados_registro_release.json" -o "$PAQUETE/aliados_registro_release.json" &&
python3 "$PAQUETE/publicar_aliados_registro.py" --check --site "$SITIO"
```

Revisar el diff completo. Ante `CONFLICTO`, **no aplicar ni reemplazar el archivo manualmente**. Si hay compatibilidad y el respaldo completo de MySQL está exportado en hPanel, aplicar los archivos:

```bash
python3 "$PAQUETE/publicar_aliados_registro.py" --apply --site "$SITIO"
php "$SITIO/tools/migrar_aliados_registro.php" --check
```

`--check` del preparador devuelve 2 si falta la migración, sin modificar datos. Instalarla explícitamente sólo después de comprobar dependencias y respaldo completo. El preparador guarda además las tablas afectadas en un SQL privado antes de instalar:

```bash
php "$SITIO/tools/migrar_aliados_registro.php" --apply --backup-dir="$HOME/respaldos-impe-bd" &&
php "$SITIO/tools/migrar_aliados_registro.php" --check &&
python3 "$PAQUETE/publicar_aliados_registro.py" --check --site "$SITIO"
```

Validar con Cuenta Clave y Marketing reales: crear aliado de prueba, seleccionar programas, generar dos veces el mismo enlace, comprobar QR, deshabilitar/habilitar, enviar Registro y localizar la procedencia en Prospectos. Revisar un aliado institucional y los enlaces anteriores de Handly. Ningún paso se ejecutó en producción durante el desarrollo.

Para restaurar archivos, usar la ruta **exacta** anunciada por `PUBLICADO. Respaldo:`:

```bash
python3 "$PAQUETE/publicar_aliados_registro.py" --restore \
  "$HOME/respaldos-impe-aliados/aliados-registro-FECHA-EXACTA" --site "$SITIO"
```

La restauración se detiene si un archivo cambió después del despliegue. Restituye bytes originales y elimina únicamente archivos nuevos de esa entrega. Conserva tablas y respuestas; **no deshace SQL ni borra históricos**. El SQL privado previo es una evidencia/restauración sobre copia aislada, no un script que deba importarse ciegamente sobre producción. Cualquier recuperación de BD requiere revisión del respaldo completo y de respuestas recibidas desde la publicación.
