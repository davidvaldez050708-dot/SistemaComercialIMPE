# Prospectos → Campañas → Historial de importaciones de Meta

## Objetivo
Tania puede importar uno o muchos CSV/TSV/XLSX de diferentes formularios de Meta la misma noche. La operación original realiza lotes transaccionales de hasta 75 leads. El **historial permanente** registra **una entrada por archivo totalmente finalizado**, no una por cada lote interno ni una por lead.

Cada entrada contiene fecha de confirmación (almacenada en UTC, mostrada en **Ciudad de México**), usuario responsable, nombre original del archivo saneado, filas leídas, válidas, nuevos, ya existentes en CRM, repetidos dentro del archivo, rechazados, campañas, formularios y programas confirmados. Los programas son la clasificación explícita por ID de formulario y sus excepciones autorizadas, no inferencias por nombres de campaña.

**No almacena** archivos originales, teléfonos, nombres, correos, fechas de nacimiento o identificadores individuales de los leads. Solo guarda metadatos agregados y nombres/IDs de formularios. No permite editar ni eliminar registros desde la interfaz.

## Ubicación y acceso
**Prospectos → Campañas**: junto a los accesos secundarios de **Configurar formularios** y primario **Importar archivo**, hay un botón discreto **Historial**. Cambia a una vista exclusiva con **Volver a campañas**, sin un segundo panel permanente ni modificar la vista de prospectos.

El historial se muestra únicamente a sesiones con permisos `prospectos.ver_todos` y `prospectos.importar_meta`. No se modifican roles ni permisos. La búsqueda permite nombre de archivo o formulario; filtros de fechas aplican al momento de finalización en hora de Ciudad de México.

La tabla muestra 15 archivos por página, con paginación numérica visible, página actual, anterior/siguiente y puntos suspensivos. Los totales corresponden a la búsqueda y fechas activas (no solamente a la página). El desglose por formulario es desplegable en cada archivo.

## Invariantes de integridad
- Identificador único `lote_ref` por archivo completado: reintento idéntico no duplica ni reescribe cantidades/fecha. Nuevo archivo/lote puede tener el **mismo nombre** sin ser una duplicación falsa.
- Los contadores cumplen `nuevos + repetidos_crm = importables` y `importables + repetidos_archivo + rechazados = filas_leidas`.
- Al completar el último lote, `registrarCompletada()` y `confirmarLoteMeta()` se ejecutan en la **misma transacción**. Ante error, no se marca completado ni se descarta la sesión de importación; se puede reintentar sin crear registros duplicados.
- El historial solo cubre importaciones completadas **después de instalar esta versión**. No se inventan archivos/fechas/cifras de cargas anteriores. Los prospectos ya incorporados no cambian.
- El motor Meta y Aliados permanecen desactivados hasta habilitación posterior. No se configuran tareas cron ni se toca telefonía, agenda, llamadas o embudo.

## Despliegue seguro
1. Respaldar la base y disponer de una ventana sin importaciones en curso. Ejecutar `--check` del publicador sobre el sitio de Hostinger y verificar SHAs de archivos y dependencias. Un conflicto detiene el proceso sin escrituras.
2. Aplicar **ANTES del PHP** la migración aditiva `database/migrations/2026_11_07_prospectos_meta_historial_importaciones.sql`. Crea exclusivamente `prospecto_meta_importaciones_historial`, FK a `usuarios`, índices por fecha y responsable. Confirmar tabla vacía y motores Meta/Aliados apagados. No ejecutar si ya existe una estructura incompatible.
3. Publicar exclusivamente seis archivos desde `tools/prospectos_meta_historial_release.json` con `tools/publicar_prospectos_meta_historial.py --site RUTA --backup-root PRIVADO --apply`. Mantener respaldo fuera de `public_html`. Se protegen cinco dependencias. `--restore` recupera la versión anterior si el sitio coincide, sin SQL.
4. Verificar `php -l` de servicios/controlador/vistas, `--check`, entrar a Campañas y comprobar que se visualiza el estado vacío, la paginación y filtros; luego realizar **una importación de prueba autorizada con datos ficticios** y confirmar exactamente una entrada. Las pruebas CI usan MySQL desechable con 36 importaciones.
5. No modificar programación nocturna, permisos, estados ni asignaciones. Mantener los dos interruptores de distribución en cero.

## Notas
No confundir el **historial de asociaciones** (quién cambió el programa de un formulario y cuándo) con el **historial de importaciones** (qué archivo se procesó y qué resultados tuvo). Son funciones distintas, mantenidas en la misma sección Campañas.
