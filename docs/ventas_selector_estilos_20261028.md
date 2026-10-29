# Corrección de estilos del selector de resultados

El menú múltiple heredaba el tamaño base de Bootstrap en lugar de la escala del formulario; las etiquetas también heredaban peso y separación inadecuados. Se acota la corrección a la vista de resultado, con texto de 12.5 px como los otros campos, peso normal, filas compactas, bordes y colores del sistema. Se conserva la flecha de selector durante el foco. Se retira la utilidad p-2 para que el relleno siga el mismo criterio de los selectores compartidos.

No se modifican servicios, JavaScript, datos, opciones, límite de dos resultados, llamadas, grabaciones, metas ni permisos.

Se verificó el formulario real con Chrome aislado en escritorio y móvil. El menú y el texto de sus opciones tienen el mismo tamaño y familia tipográfica que el selector de agenda; las filas cortas no exceden la altura de ese control. Continúan funcionando una o dos selecciones, borradores y consulta de registros anteriores. La publicación selectiva contiene únicamente `app/views/telefonia/resultado_ventas_form.php`, con respaldo y comprobación de la versión anterior.
