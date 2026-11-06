# Inscripciones recibidas — tabla de inscritos

Módulo independiente en sidebar (escritorio y móvil), junto a Prospectos. Muestra sólo
**inscripciones completas** recibidas por los cinco formularios, sin mezclar
Prospectos, Registro ni inscripciones históricas sin detalle/comprobante.

## Funciones

- Tabla única estilo Prospectos: fecha de registro, nombre completo, programa,
  correo, teléfono, origen y acción **Ver ficha**.
- Filtros por programa, rango inclusivo de fechas en horario de México y
  búsqueda automática (nombre, apellidos, correo, móvil y CURP). Petición AJAX
  con debounce y paginación de 25 filas; consultas SQL parametrizadas.
- Ficha privada en panel lateral, con datos personales, domicilio, actividad
  laboral, programa, fecha de inscripción, recepción de comprobante y
  constancia de aceptación de términos y aviso. **Recibido no significa pago validado.**
- Descarga de comprobante sólo si el rol tiene también los permisos existentes
  \`formularios.ver\` y \`formularios.comprobantes.ver\`.
- Excel **.xlsx** genuino con columnas descriptivas, filtro automático y
  encabezado institucional. Contiene **todas las coincidencias de los filtros**
  (no solo la página actual). Máximo 10,000 filas; si supera ese límite, rechaza
  la exportación sin truncarla. Celdas OOXML como cadenas, incluso si comienzan
  con \`=\`, \`@\` o \`+\`, para evitar ejecución de fórmulas. Requiere PHP ZipArchive.

## Seguridad y permisos

- \`inscritos.ver\`: permite visualizar el módulo y consultar fichas.
- \`inscritos.exportar\`: permite descargar XLSX. Requiere también \`inscritos.ver\`.
- El acceso a Formularios o a Prospectos **no basta** para consultar inscritos.
- Los permisos se agregan desde el catálogo de **Roles y permisos**, donde
  Administrador puede activarlos o revocarlos. Como habilitación inicial única,
  Marketing recibe ambos únicamente si ya conserva \`formularios.ver\`;
  las revocaciones posteriores nunca se restablecen.
- Todas las rutas (tabla, ficha, Excel) comprueban permisos **en servidor**.
  Las URLs no son públicas y no hay nuevos datos en el HTML inicial.
- GET sin POST ni SQL DDL. Respuestas JSON/Excel sin caché privada. No se
  divulgan tokens CSRF ni nombres de archivos privados en la ficha ni en Excel.
- La descarga XLSX incluye CURP y domicilio: utilizarla solo desde equipos
  y cuentas autorizadas; manejar el archivo fuera del sistema como dato personal.
- La consulta del comprobante usa el controlador existente con su permiso
  separado; el módulo no declara pagos como conciliados o validados.

## Implementación

- \`app/controllers/InscritosController.php\` (index/listar/detalle/exportar)
- \`app/models/InscritosModel.php\` (JOIN solo tablas completas, filtros idénticos
  en tabla y Excel, paginación, ficha blanca en servidor)
- \`app/helpers/InscritosPermisos.php\` y catálogo en RolModel
- \`app/services/InscritosExcelService.php\` (XLSX sin Composer adicional)
- \`app/views/inscritos/index.php\`, \`public/css/inscritos.css\`,
  \`public/javascript/inscritos.js\`
- Sidebar desktop y móvil y ruta privada \`controller=inscritos\`.
- **No se crean tablas** ni se migra ninguna inscripción. Se leen
  \`formulario_inscripciones\` y \`formulario_inscripciones_completas\` existentes.
- Publicador \`tools/publicar_inscritos_hostinger.py\` con \`--check\` (solo
  lectura), \`--apply\` (respaldo privado), \`--restore\` (verificación de hashes).
  Cambia sólo diez archivos, tres conciliados y siete nuevos.

## Verificación después de publicar

1. Ejecutar \`--check\` y analizar compatibilidad, ZIP, PHP y JS. No aplicar
   con conflictos ni antes de asegurar que PR #225 (Bachillerato) está publicado.
2. \`--apply\` con respaldo. Abrir **Roles y permisos** con una sesión autorizada
   para inicializar los dos códigos, revisar sus asignaciones y conservar
   la segregación de consulta/exportación. Los permisos se refrescan en cada petición.
3. Entrar en **Inscripciones recibidas**. Probar programa, fechas, correo,
   teléfono, nombre, búsqueda sin resultados, paginación y panel lateral.
4. Exportar la combinación filtrada y cotejar las filas con el total visible.
   Probar rol que únicamente puede ver fichas: no debe descargar Excel.
5. Confirmar que la salida XML .xlsx se abre correctamente con Excel, y que
   un nombre iniciado por \`=\` se muestra como texto, sin fórmula ejecutable.
6. No publicar datos reales ni registros de producción en logs de diagnóstico.
