# Programas de Aliados: origen y aviso duplicado

Corrección selectiva de public/javascript/aliados_registro.js, versión instalada SHA256 82241e8ba9001d555da19d6c65f8299cd9125a3628f1d59cb9fb136df2c8b9af.

Al cargar un aliado institucional, la copia de campos del seguimiento sobrescribía el input oculto origen=institucional con s.origen=DENUE/MANUAL. La petición de detalle era correcta, pero el guardado enviaba otro tipo y el servidor lo rechazaba. Los aliados manuales no tenían esa colisión.

Se excluye origen junto a id de la copia de campos y se mantienen todos los valores editables. No se relaja la validación del servidor ni se modifica su catálogo de orígenes. Los errores del modal de programas se notifican sólo con el toast existente: cargar, guardar, selección vacía y desactivar. El recuadro se limpia/oculta. Los formularios independientes de contactos y los estilos no se modifican.

La prueba de navegador reproduce ambas fallas con el JS instalado y comprueba la corrección con fuentes DENUE/MANUAL/otra, agregar/quitar programas, aliado manual, CSRF/ID, selección vacía y errores del servidor/permisos. Sólo usa transporte simulado, sin SQL. Se prueba el publicador en Python 3.6.8 y moderno con check sin escrituras, versión desconocida, integridad, idempotencia y restauración protegida.

El paquete modifica un único archivo, publica JS con 644 y guarda respaldo privado. No ejecuta SQL, envía mensajes ni modifica otros módulos. Para restaurar usar el mismo publicador y manifiesto con --site, --backup-root y --restore /ruta/respaldo. No sustituir variantes desconocidas ni desplegar la rama completa.
