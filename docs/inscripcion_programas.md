# Inscripción por programa

## Alcance y disponibilidad

- La captura interna usa las tarjetas, campos, Manrope y controles de Registro dentro del dashboard. La ruta anterior del piloto ahora presenta esa misma captura interna.
- Cada tarjeta abre su captura y un enlace `ya-estas-inscrito/?programa=SLUG`. Sólo el enlace público usa la fotografía y los dos logotipos del encabezado de Registro actual. Sin HLC ni el bloque de texto separado «Acuerdo 286». Registro, sus alias `obtener-beca`, los horarios y formularios de Aliados no se sustituyen.
- Cinco programas; los slugs anteriores se conservan, incluido `bachillerato-286` (nombre visible canónico: Bachillerato General).
- Titulación–Ejecutivas conserva los diez perfiles A286 y el contrato aportado, confirmados por el usuario el 05/11/2026.
- Los programas de Titulación (Ejecutivas y Experiencia Laboral) conservan el contrato A286 compartido; Bachillerato General / Examen Único usa su contrato y aviso de privacidad **originales de Educación Media Superior**. **Inglés y Bachillerato en 2 años** siguen sin términos confirmados y no pueden enviar.
- Bachillerato General **no requiere ni muestra Perfil de evaluación**, tampoco acepta perfiles ajenos ni lo guarda en el JSON de la inscripción. El campo histórico `formulario_inscripciones.perfil_interes` mantiene el nombre del programa sin migraciones SQL. La sección laboral y los datos personales, CURP, pago, domicilio y consentimientos se conservan.
- **Diferencia documental por revisar:** el original Bachillerato aportado indica Teopanzolco **98** en contrato y privacidad; el contrato de Titulación indica **101** y su aviso **107**. Los textos se conservaron tal cual; la institución debe verificar domicilios, responsables y vigencia antes de difundir el nuevo enlace. No se presupone que la cuota del contrato de Titulación sea aplicable a Bachillerato.
- Experiencia laboral tiene ocho áreas en el [catálogo público consultado](https://www.fundacionredmex.org/titulacion-experiencia.html), consultado el 05/11/2026. No incorpora Administración o Pedagogía por inferencia. El catálogo del piloto confirmado por el usuario se mantiene separado de las [Carreras Ejecutivas con RVOE](https://www.fundacionredmex.org/carreras-ejecutivas-detalle.html).

## Ajuste Bachillerato General — Examen Único A286

El formulario usa `ya-estas-inscrito/?programa=bachillerato-286` y conserva encabezado, foto y logotipos públicos de Registro. Agrega sólo la leyenda `Examen Único · Acuerdo 286` para distinguir este programa, sin modificar los otros encabezados.

Documentos específicos `app/resources/inscripcion/terminos-bachillerato-general.txt` y `privacidad-bachillerato-general.txt`, extraídos del formulario original proporcionado por la institución, sin reescribir cláusulas. Estos textos **no son idénticos** al contrato ni al aviso de Titulación y no deben intercambiarse. La aceptación sigue registrada mediante la huella de cada archivo y fecha.

No incluye el campo `Apertura` porque fue retirado del formulario unificado de Inscripción. No se incorpora una nueva tabla ni se cambian filas históricas. El publicador `publicar_inscripcion_bachillerato_general_hostinger.py` modifica cuatro PHP y agrega dos textos legales con respaldo privado y restauración; `--check` no escribe.

## Datos y validación

Sin Apertura. Incluye datos personales, CURP, nacimiento, sexo, correo y móvil confirmados, nombres de padres, domicilio con catálogos de estado/municipio, empleo, perfil, fecha de inscripción, comprobante y consentimientos independientes.

La CURP se verifica localmente por estructura, entidad de nacimiento, fecha, siglo, sexo y dígito verificador. Los nombres conservan su escritura y acentos; la confirmación de cotejo con la constancia es obligatoria. No equivale a consultar RENAPO ni acredita identidad oficial. El comprobante queda recibido para revisión, no como pago validado.

PDF, JPG y PNG hasta 8 MB, inspeccionados en el servidor por MIME real, firma PDF o dimensiones de imagen. Se guardan con nombre aleatorio y permisos 0600 **fuera de public_html**, en `../impe-inscripciones-private`. No se exponen URLs públicas de archivos. La descarga requiere el mismo acceso interno de Marketing del módulo, mediante `formulario/comprobanteInscripcion&id=ID`.

Los datos básicos siguen en `formulario_inscripciones`. Los campos completos, huellas de documentos aceptados, fecha de consentimiento y comprobante están en `formulario_inscripciones_completas`. Una transacción conserva ambas filas o revierte ambas, con limpieza del archivo nuevo. El token de formulario permite reintentar sin duplicar; una CURP ya recibida para el mismo programa requiere revisión del equipo en vez de otra inscripción. No se modifican registros históricos ni se incorporan automáticamente prospectos.

## Publicación controlada

El paquete de código contiene 15 archivos y conserva exactamente la variante instalada del controlador (SHA256 inicial `9494565f6f4d825dde1afefc320e65b36a1edc78285042db3cb7bd94e41504b3`). Sus métodos de Registro, horarios, alias y autorización están cubiertos por una prueba de igualdad contra la copia reconstruida de Hostinger.

1. Respaldar la base de datos y conservar el directorio privado de comprobantes en los respaldos futuros.
2. Revisar la ampliación aditiva `database/migrations/2026_11_05_inscripcion_completa.sql`. No altera tablas existentes ni incorpora personas. **No se ejecuta desde el instalador.** El CLI `habilitar_inscripcion_completa.php --site RUTA --backup-root RUTA_PRIVADA --schema ARCHIVO --check` valida el esquema sin escribir; con `--apply` guarda respaldo de la estructura y crea únicamente la tabla nueva. No modifica ni borra filas existentes. Rechaza tablas adicionales incompatibles.
3. Descargar desde el mismo commit `publicar_inscripcion_completa.py` y `inscripcion_completa_release.json`, comprobar sus hashes y ejecutar `--check`. No consulta SQL ni escribe archivos del sitio. Rechaza variantes desconocidas y dependencias distintas.
4. Ejecutar `--apply` únicamente tras revisar el resultado. Produce respaldo privado, reemplazos atómicos, permisos públicos 644 y directorios nuevos 755. Es compatible con Python 3.6.
5. Si la tabla adicional no está preparada, el formulario muestra su estado y deshabilita el envío; no intenta crear tablas automáticamente.
6. Para volver a la versión anterior: mismo instalador, `--site RUTA --backup-root RUTA --restore RESPALDO`. No elimina inscripciones recibidas, comprobantes ni tablas. No retirar la tabla ni el almacén privado para revertir sólo la interfaz.

Requiere PHP 8.1+, mysqli, mbstring y fileinfo; configurar el PHP del sitio con `upload_max_filesize=8M` o superior y `post_max_size=10M` o superior. El CLI y el PHP web pueden tener configuraciones distintas.

## Pruebas

- Playwright y HTTP multipart reales en una base exclusivamente de pruebas: CURP inválida/siglo, correo/móvil, CSRF, programa manipulado, municipio de otro estado, comprobante falso, consentimientos, duplicados con el mismo y con otro token, toast de éxito, escritorio y móvil.
- Fallo SQL sintético después de mover el comprobante: reversión de filas y ausencia de archivos huérfanos.
- Réplica temporal: check sin escrituras, apply, idempotencia, restauración exacta, dependencia sellada y rechazo de variantes desconocidas; ejecutado con Python 3.12 y 3.6.8.
- Regresiones anteriores del piloto y de los publicadores de etapas previas. Estos prueban sus versiones publicadas inmutables, no los archivos de esta nueva interfaz.
- CI añade MySQL 8.0 y MariaDB 11.8; los workflows existentes de Aliados se conservan.

No se ejecutaron SQL ni importaciones reales en Hostinger.

El CLI de habilitación se distribuye aparte del paquete: debe descargarse y comprobarse por hash desde el mismo commit. Su check puede ejecutarse antes de instalar los archivos. La creación de tabla requiere --apply explícito; el publicador Python sigue sin ejecutar SQL.

## Ajuste visual de campos y consentimientos

El publicador `publicar_inscripcion_estilos.py` con `inscripcion_estilos_release.json` actualiza solamente `public/css/formularios_inscripcion_completa.css`. Reconoce por hash el CSS publicado anteriormente, crea respaldo privado y permite check/apply/restore; no ejecuta SQL ni cambia formularios, datos o validaciones. El paquete completo también incluye el mismo CSS actualizado y admite la versión anterior instalada.

Revisión en navegador con las hojas reales del formulario público: selector de archivo de 42 px con botón centrado, consentimientos de 11 px y títulos de documentos de 12 px. Los botones de enlace tienen 40 px de alto y se alinean con el campo en escritorio; en móvil pasan juntos debajo del enlace sin desbordamiento. Verificados el paquete independiente con Python 3.12/3.6.8 y la publicación completa.
