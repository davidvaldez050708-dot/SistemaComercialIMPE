# Estabilización compartida de telefonía

Incremento sobre PR #116 y commits recientes `c10e2a5c`, `0d091e5a`, `9d5cd1c8`; conserva PR #110/#115 y Silenciar. No contiene migraciones ni cambia registros históricos. No publica archivos de configuración, credenciales ni datos privados.

Desde el clon privado de la rama `fix/ventas-etapa2-audio-operacion`, fuera de `public_html`:

```bash
export IMPE_TELEFONIA_ESTABILIZACION_SOURCE="$PWD"
bash tools/publicar_telefonia_estabilizacion_hostinger.sh --check
# Sólo tras revisar que --check terminó correctamente:
bash tools/publicar_telefonia_estabilizacion_hostinger.sh --apply
# Restaurar con la ruta EXACTA impresa por --apply:
bash tools/publicar_telefonia_estabilizacion_hostinger.sh --restore "$HOME/respaldos-crm/RUTA_DEL_RESPALDO"
```

Quince archivos selectivos. Huellas y parches fijados a commits, `php -l`, bloqueo de cambios concurrentes, respaldo externo y restauración exacta. Conserva PHP personalizado compatible, CRLF y la variante revisada del shell de Hostinger. Un JS/CSS desconocido o un conflicto detienen la publicación sin sobrescribirlo. No ejecuta SQL. No usar `git pull` en el sitio.

Pruebas dirigidas: WebAudio real medido en Edge (no prueba altavoces físicos); disponibilidad Recepción saliente/entrante, reapertura, aceptación manual, rechazo/vencimiento y BD; rechazo SIP, recuperación PBX y Silenciar; referencia histórica por contexto consumido, segmentos propios, permisos, captura persistente y responsive. Las asociaciones del expediente son de lectura; si falta evidencia de transferencia o de tramo, permanecen pendientes.

Validación real pendiente tras publicación manual: sonido físico y dispositivo seleccionado en Analista/Ventas, transferencia saliente 100 hacia cada rol, recepción de una invitación nueva tras rechazo y cierre/audio reales de Zadarma. Registrar PBX Call ID, extensiones y respuestas firmadas/API del tramo; no basta teléfono/hora. No se probó contra la centralita ni se modificó producción.
