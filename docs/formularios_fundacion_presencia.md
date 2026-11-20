# Mayor presencia de Fundación

Se aumenta un 25 % la altura del logo de Fundación respecto al ajuste anterior:
el factor pasa de 0.68 a 0.85 de la altura de la imagen SEP. No cambia SEP, el
divisor ni los espacios. La regla compartida aplica también a enlaces existentes.

El publicador `tools/publicar_formularios_fundacion_presencia.py` añade únicamente
`tools/formularios_fundacion_presencia.css` al encabezado con la corrección anterior
ya instalada. Reconoce los hashes derivados de las dos bases auditadas más el
bloque anterior exacto. No cambia PHP, imágenes ni datos. Conserva íntegramente el
CSS previo y el fondo de producción. Admite comprobación sin escrituras,
aplicación idempotente con respaldo privado y restauración protegida.

Validación: las cuatro vistas PHP reales en 1752, 1440, 1024, 390 y 320 px; aumento
del 25 %, tamaño de SEP conservado, separación visible junto al divisor y ausencia
de desbordamiento. La publicación se verifica sobre las dos variantes anteriores,
incluidos respaldo, restauración y rechazo de cambios desconocidos.
