# Gestión comercial — Previsualización V3 de cinco etapas (preparación)

## Punto de partida confirmado en Hostinger

El 11 de noviembre de 2026 se instalaron exclusivamente dos tablas vacías de subetapas (con respaldo privado del esquema). La conciliación posterior, ejecutada mediante script de lectura, encontró:
- `INTERESADO: 1`, con tres llamadas comerciales vinculadas; `PROSPECTO_POTENCIAL` declarado en una llamada. **No** se encontró programa codificado ni acreditación documental o de requisitos. No se convierte automáticamente en `POTENCIAL`.
- `SEGUIMIENTO: 1`, sin llamadas comerciales vinculadas, con próxima acción registrada. **Debe conservarse** su etapa y fecha.
- No se actualizaron filas comerciales ni telefonía. Los tres registros PBX ligados al primer prospecto **no** se interpretan como tres intentos sin respuesta: se requieren resultados individuales y evidencia PBX distinta.

## Alcance del código de esta entrega

Se agrega una **vista alternativa de consulta**, para supervisores que ya tienen acceso a Gestión comercial. Acceso hipotético una vez desplegada y verificada:

`index.php?controller=embudoComercial&action=index&vista=embudo&previsualizacion_v3=1`

La nueva URL no reemplaza ni redirige el tablero habitual; un asesor sin permiso de supervisión sigue viendo el tablero original. Se conservan los filtros existentes, por programa, origen, búsqueda, asesor y vencimientos. No hay nuevos endpoints POST.

La previsualización consulta los datos reales con las mismas restricciones de propietario/supervisor del repositorio, sin DDL ni escritura:
- **Cinco columnas activas:** Nuevos, Contactados, Potenciales, Seguimiento, Proceso Inscripción.
- **Apartado aparte de clasificación pendiente:** incluye `INTERESADO`, `PAGO_CONFIRMADO` y otros códigos antiguos/inesperados. **Nunca desaparecen** de los totales ni se convierten silenciosamente en Potenciales, Contactados o Proceso Inscripción.
- **Fuera del tablero:** Recuperación y Descartados se resumen como bandejas externas. El código `EN_CONTACTO` se contabiliza visualmente en Recuperación, siguiendo la compatibilidad ya existente.
- Subetapa vigente leída, si la hay, de `prospecto_embudo_subetapas`. La nueva vista muestra `Subetapa pendiente` si está vacía: **no fabrica una calificación**.
- Fecha de próxima acción existente conserva su señal visual, sin alterar agenda.
- Cada grupo muestra hasta 20 registros; cuando existen más, muestra aviso de truncamiento y el total correcto. **No sustituye la paginación del tablero original.**
- **Solo lectura**: no contiene `draggable`, botones para llamar, acciones de movimiento ni formularios V4.

## No está activado ni resuelto aún

- No hay cambios ni despliegue en Hostinger en esta rama. El tablero, ficha y movimiento siguen funcionando con los códigos anteriores.
- El resultado V4 y el puente productivo siguen usando su mapeo anterior; un primer no contacto aún puede mover a Recuperación. **No activar la política tres intentos antes de reemplazar ese puente de modo coordinado.**
- No se han creado criterios de calificación ni almacenamiento de evidencia documental/programa revisado. El prospecto histórico se mantiene en «Clasificación pendiente».
- No se activa remarketing, distribución Meta/Aliados, inscripción ni pago.
- Para cambiar definitivamente el tablero deberán validarse la transición de códigos, permisos de movimientos y la política PBX en el backend junto con la UI, en un solo despliegue compatible.

## Pruebas

`tests/embudo_comercial_v3_previsualizacion_db.php` crea bases desechables (MySQL 8/MariaDB 10.11) con asignaciones inventadas. Verifica que:
1. Un `INTERESADO` con programa sin confirmar quede en clasificación pendiente, no ascienda automáticamente.
2. `SEGUIMIENTO` conserve su próxima acción y subetapa.
3. Nuevos/Potenciales/Proceso Inscripción tengan grupos separados.
4. Un `PAGO_CONFIRMADO` antiguo tampoco se convierta automáticamente en trámite.
5. Los datos de otros asesores no se muestren en la consulta propia; la vista de supervisor aplique el filtro autorizado.
6. La paginación de la vista normal no cambie; la previa limite 20 por grupo con aviso explícito.
7. No hay escrituras, llamadas ni arrastre.

**Esta rama no debe publicarse por sí sola en el sitio hasta verificar el estado de las personalizaciones y revisar los PR anteriores.**
