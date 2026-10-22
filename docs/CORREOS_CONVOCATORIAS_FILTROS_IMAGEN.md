# Correos: filtros de convocatorias e imagen embebida

**Reparación posterior de menús/carga:** para filtros ya instalados, consultar [la guía v2](CORREOS_FILTROS_REPARACION_HOSTINGER.md). Este documento conserva el instalador v1 original; no usarlo para reparar los desplegables vacíos.

Rama `codex/correos-filtros-convocatorias`, basada en `origin/main` (`a90b6ade`). Código/publicador: **2ba65dc4a4ab95445c2c97c1f1c0afbac4bcabad**. Publicador `correo-convocatorias-v1-e61738d49768ea8e`.

## Alcance

- `CorreoConvocatoriaConsultaModel.php`: SELECT de convocatorias activas y del catálogo existente `estados`; cobertura mediante `convocatoria_estados`. Convenios mediante `marketing_convenio_convocatorias.seguimiento_id` → `seguimientos_vinculacion.id`, con la referencia de `seguimientos_vinculacion_post_envio`. No ejecuta DDL ni modifica relaciones. Si no existe la tabla de relaciones, conserva las convocatorias sin inventar convenios.
- `CorreoMarketingController.php`: consultas protegidas del catálogo y su imagen; integración de composición por inyección del remitente existente. Conserva los métodos/validaciones de envío, archivos y controles comerciales. Añade metadatos privados de selección después de un resultado correcto.
- `CorreoConvocatoriaComposicion.php`: valida ID, filtros y archivo; utiliza las interfaces existentes `html_adicional` e `imagenes_embebidas` de `CorreoSalidaInstitucionalService`. No modifica ese servicio ni su transporte. La imagen original se agrega una sola vez mediante CID; no como adjunto visible adicional. Texto, destinatario, remitente, adjuntos manuales y firma siguen sus reglas previas.
- `app/views/correos/index.php`, `correo_convocatorias.js` y tres eventos aislados en `correos_marketing.js`: Estado → Convenio → Convocatoria, filtros combinados, etiquetas con ID/convenio/cobertura, limpieza y vista previa. Un catálogo por página; filtros locales y protección contra respuestas tardías. No cambia De/Para/Asunto/Mensaje, estilos globales, dimensiones ni botones del modal.

La selección sigue siendo opcional. Cambiarla o limpiarla retira únicamente la imagen automática; no reescribe texto ni elimina adjuntos manuales. Una convocatoria sin imagen válida permite enviar sin imagen y muestra un aviso. No se sustituye por otra imagen.

## Imagen, borrador e historial

La fuente es `convocatorias.imagen`. Se aceptan JPG/PNG/WEBP originales dentro de `public/uploads/convocatorias/`, con MIME y dimensiones comprobados, máximo 5 MB y sin enlaces/rutas arbitrarias. El tamaño conjunto de imagen y adjuntos no excede 20 MB. La vista previa requiere sesión Marketing; la imagen recibida viaja embebida, sin enlaces públicos nuevos ni necesidad de sesión del destinatario.

Se reutiliza `CorreoEstadoPrivado` sin modificarlo para asociar ID, filtros y referencia/hash de imagen a usuario/correo, fuera del sitio, en el directorio de estado privado existente. No hay columnas nuevas ni migraciones. El texto del borrador, adjuntos e historial continúan guardándose exactamente por `CorreoMarketingService`, que permanece intacto. La referencia aceptada se conserva ante reintentos. Un fallo de metadatos informa sin convertir un envío aceptado en un error que invite a reenviar.

Los borradores antiguos que sólo guardaron tipo CONVOCATORIA carecen de ID recuperable: no se adivina por el título. Si la imagen histórica original se eliminó o cambió, se informa que no está disponible; no se muestra otra ni se reconstruye. Al editar, una versión distinta exige revisar la selección. No se copian ni modifican imágenes originales.

## Pruebas dirigidas

- `correo_convocatorias_test.php`: SQL/API simulados; relaciones, cobertura múltiple, filtros manipulados, nombres repetidos, rutas/MIME, CID y bytes originales, adjunto manual, texto íntegro, sin imagen, cambios, borrador/propietario e historial.
- `correo_convocatorias_interfaz_test.js`: filtros, catálogo único, cambio/limpieza, respuesta atrasada, sin duplicados, borrador e historial, texto/archivos conservados.
- `correo_convocatorias_aislamiento_test.py`: **22 archivos protegidos idénticos a main**, incluidos envío, recuperación, firma, modelos originales y CSS; endpoints nuevos rechazan un perfil sin permiso. Sin contraseñas ni recuperación real.
- `correo_convocatorias_publicador_test.py`: sitios ficticios de main `a90b6ade` y Hostinger v4 `1375a90a`; check sin escritura, apply/restore exactos, idempotencia, CRLF/comentarios y conservación de transporte/credenciales/recuperación. Rechaza incompatibles y ediciones posteriores.
- Regresión `correos_cuentas_test.php`: envío/adjuntos/identidad/errores e idempotencia simulados. PHP lint, sintaxis JS/Bash y diff correctos. Sin CI global.
- Chrome real sobre una vista PHP con datos ficticios: scripts existente/nuevo juntos, filtros, imagen cargada, texto y archivo manual, escritorio/móvil sin desbordamiento horizontal y un único envío interceptado. No utiliza CRM, BD ni proveedores reales.

**Pendiente de aceptación:** publicar con autorización y enviar desde el CRM a una cuenta de prueba autorizada; comprobar recepción real con imagen dentro del cuerpo, adjunto manual, From e historial. Los payloads simulados no prueban cómo lo muestra el cliente de correo receptor. No se enviaron correos reales ni se modificó producción.

## Publicación selectiva en Hostinger

Instala sólo seis archivos: modelo/compositor/JS nuevo y parches limitados de controlador/vista/JS existente. Admite main y la versión Hostinger v4 ya instalada, conservando sus diferencias. No publica ni sobrescribe servicios de Hostinger, SMTP, configuración privada, tokens o recuperación. No utilizar un publicador comercial anterior para instalar esta mejora.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/2ba65dc4a4ab95445c2c97c1f1c0afbac4bcabad/tools/publicar_correo_convocatorias_hostinger.sh" \
  -o "$HOME/impe-publicadores/correo-convocatorias-2ba65dc4.sh" &&
printf '%s  %s\n' \
  '7e4a4f1151f3dcf3f8925d0ab372af3caf784b85489dd60c9438864a495df0ec' \
  "$HOME/impe-publicadores/correo-convocatorias-2ba65dc4.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/correo-convocatorias-2ba65dc4.sh" --check
```

Sólo tras hash y revisión compatibles, sin envíos activos y al decidir publicar:

```bash
bash "$HOME/impe-publicadores/correo-convocatorias-2ba65dc4.sh" --apply
```

Guardar el comando **Rollback** exacto que imprime: usa ese mismo script con `--restore` y la ruta real del respaldo. No forzar archivos personalizados. Recargar el CRM con Ctrl+F5, comprobar Estado/Convenio y guardar/reabrir un borrador antes de la prueba de recepción autorizada. El publicador no consulta ni modifica la base de datos y no ejecuta envíos.

Sin fusión ni despliegue automáticos. Hostinger Mail API, sus tokens, recuperación de contraseñas y otros módulos permanecen fuera del cambio.
