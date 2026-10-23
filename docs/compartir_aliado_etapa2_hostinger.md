# Etapa 2 — Compartir convocatorias por aliado

PR #124, rama `feat/aliados-programas-registro`. Aplicación: `17e29ec5e3fbe3e6b4c9a727482546837eb43976`. Entrega selectiva: `237cc026ededc5e509c1049f4022b57406d5e991`.

Antes: sólo se ofrecía Compartir para Vinculación y se validaba territorio, sin probar pertenencia. Ahora el mismo modal recibe origen e ID reales, consulta publicaciones asociadas y autorizadas por programa, conserva el enlace estable del aliado y permite elegir correo o número confirmado/autorizado. La difusión territorial continúa como opción explícita; sus endpoints antiguos excluyen publicaciones exclusivas para evitar eludir la pertenencia omitiendo el origen. Las relaciones de Registro internacionales ligadas realmente al expediente también se consideran; no se ofrecen relaciones de Inscripción.

Correo reutiliza el proveedor, remitente, tarjeta y tratamiento de imágenes institucionales. Se registra sólo después de su respuesta positiva. WhatsApp prepara material; abrir/copiar no registra un envío. Marcar como compartida requiere una actuación explícita. Reintentos con la misma operación no repiten envíos y un nuevo reenvío requiere confirmación. Historial institucional existente y `aliados_manuales_difusiones` para manuales: la nota conserva ID, programa, título y destinatario, sin seguimientos ficticios.

## Alcance de publicación

Sólo siete archivos operativos:

- `app/controllers/AliadoController.php`
- `app/models/AliadoModel.php`
- `app/services/AliadoCompartirService.php` (nuevo)
- `app/services/AliadoDifusionService.php`
- `app/services/AliadoRegistroService.php`
- `app/views/aliados/estado.php`
- `public/javascript/aliados.js`

No se publican CSS, componentes de Canales de la etapa 1, Convenios, creación de convocatorias, QR, Prospectos ni Telefonía. No hay migración nueva. La entrega presupone las tablas V3 y las opciones de canales ya instaladas. No ejecutar scripts SQL para esta etapa.

## Descargar y comprobar sin instalar

En SSH, fuera de `public_html`:

```bash
REV=237cc026ededc5e509c1049f4022b57406d5e991
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PREP="$HOME/impe-compartir-etapa2-237cc026"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/compartir_aliado_etapa2_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && printf '%s\n' \
 'da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar.py' \
 '4c5bef9cda3bad177e2dbf7c9eb59fc9bec21c64f8b65f439b6870f058caedb3  entrega.json' | sha256sum -c -) &&
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-compartir" --check
```

Revisar el diff y exigir revisión compatible. El publicador Python 3.6 concilia personalizaciones no superpuestas y conserva CRLF; un conflicto o un servicio nuevo preexistente desconocido detiene toda la instalación. Valida PHP y también JavaScript cuando Node está disponible. La compatibilidad de una variante desconocida de Hostinger sólo queda confirmada cuando este `--check` termina correctamente y se revisa el diff; no forzar archivos completos de GitHub.

## Aplicar y restaurar

Sólo después de la revisión compatible:

```bash
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-compartir" --apply
```

Guardar la ruta `Respaldo:`. Está fuera del sitio. Volver a ejecutar `--check` debe indicar cero archivos pendientes. Para restaurar exclusivamente esta entrega:

```bash
RESPALDO="/ruta/exacta/mostrada/en/Respaldo"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-compartir" --restore "$RESPALDO"
```

El rollback verifica integridad y no sobrescribe ediciones posteriores. Conserva los datos de difusión. No usar `git pull` en producción, ni el manifiesto global del PR, ni reinstalar la entrega V3.

## Validación

Local: sintaxis PHP/JS; 56 comprobaciones DB/proveedor simulado; 66 Playwright/HTTP en Edge con Manrope e iconos locales, a 1440/1024/768/390/360 px; 65 regresiones DB de Aliados y suites existentes `formulario_aliados_db.php` / `aliados_internacionales.php` para Handly. Cinco pruebas del paquete: check intacto, aplicación repetida, restauración exacta, LF/CRLF, personalizaciones y bloqueos antes de escribir. AST del publicador compatible con Python 3.6; no se ejecutó un intérprete 3.6 local.

Tras publicar, recargar sin caché y comprobar un aliado de cada origen: seleccionar dos convocatorias propias, comparar programa/imagen/fechas/enlace; escoger otro número autorizado; copiar material sin crear historial; marcar una difusión efectivamente realizada. Para correo, verificar primero con un destinatario de prueba autorizado y revisar proveedor e historial. Los correos locales usaron transporte simulado: no acreditan entrega física desde Hostinger. Comprobar también una institución sin convocatorias y la opción territorial de Vinculación. Sin publicación ni SQL de producción ejecutados por Codex.
