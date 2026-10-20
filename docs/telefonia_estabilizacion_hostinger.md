# Estabilización compartida de telefonía

Incremento sobre PR #116 y commits recientes `c10e2a5c`, `0d091e5a`, `9d5cd1c8`; conserva PR #110/#115 y Silenciar. No contiene migraciones ni cambia registros históricos. No publica archivos de configuración, credenciales ni datos privados.

Desde la carpeta personal de Hostinger (`~`), descargar el publicador de la entrega publicada. El script prepara automáticamente su repositorio privado; no requiere estar dentro de un clon existente:

```bash
export IMPE_TELEFONIA_ESTABILIZACION_SOURCE="$HOME/crm-publicaciones/telefonia-estabilizacion-actual"
curl --fail --location --show-error \
  'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/b9a1c409c79cb30bc426616d538e87d65cb198c2/tools/publicar_telefonia_estabilizacion_hostinger.sh' \
  --output "$HOME/publicar-telefonia-estabilizacion-b9a1c409.sh" && \
bash "$HOME/publicar-telefonia-estabilizacion-b9a1c409.sh" --check
```

Ejecutar primero sólo ese bloque y revisar su resultado. `--check` no instala archivos en el sitio. Si termina correctamente, la publicación manual es:

```bash
bash "$HOME/publicar-telefonia-estabilizacion-b9a1c409.sh" --apply
```

`--restore` se utiliza únicamente después de una aplicación que haya generado un respaldo. Usar el comando con la ruta real impresa por `--apply`; `RUTA_DEL_RESPALDO` no es una carpeta existente que deba copiarse literalmente.

Quince archivos selectivos. Huellas y parches fijados a commits, `php -l`, bloqueo de cambios concurrentes, respaldo externo y restauración exacta. Conserva PHP personalizado compatible, CRLF y la variante revisada del shell de Hostinger. Un JS/CSS desconocido o un conflicto detienen la publicación sin sobrescribirlo. No ejecuta SQL. No usar `git pull` en el sitio.

Pruebas dirigidas: WebAudio real medido en Edge (no prueba altavoces físicos); disponibilidad Recepción saliente/entrante, reapertura, aceptación manual, rechazo/vencimiento y BD; rechazo SIP, recuperación PBX y Silenciar; referencia histórica por contexto consumido, segmentos propios, permisos, captura persistente y responsive. Las asociaciones del expediente son de lectura; si falta evidencia de transferencia o de tramo, permanecen pendientes.

Validación real pendiente tras publicación manual: sonido físico y dispositivo seleccionado en Analista/Ventas, transferencia saliente 100 hacia cada rol, recepción de una invitación nueva tras rechazo y cierre/audio reales de Zadarma. Registrar PBX Call ID, extensiones y respuestas firmadas/API del tramo; no basta teléfono/hora. No se probó contra la centralita ni se modificó producción.
