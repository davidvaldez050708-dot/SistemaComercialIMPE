# Desempeño: avatar de Resumen personal

Base `59716bbf`. Sólo cambian `app/views/desempeno/index.php` y `public/css/desempeno.css`.

**Causa comprobada antes de editar:** el HTML recibía «DM», pero `.performance-person span` sobrescribía el avatar con `display:block`, texto de 8.5 px, margen superior de 3 px y color del rol. El círculo seguía midiendo 44×44 px: las letras quedaban pequeñas y desplazadas. El avatar del encabezado calculaba flex centrado, letra de 14 px y margen cero en su círculo de 36 px.

**Fotografías:** la vista concatenaba BASE_URL y la ruta sin usar la validación institucional. Su `<img>` tampoco llevaba la clase ni los datos reconocidos por el fallback compartido. Una foto ausente podía dejar la imagen rota/alt en el círculo. La prueba 404 descubrió además una carrera: el error inicial puede ocurrir antes de que el footer registre su listener en DOMContentLoaded.

El ajuste anterior de perfil sólo aplicaba a `.topbar-user-avatar` y `.sidebar-user`; no podía corregir este componente independiente ni su carga de imagen. Esto se confirma en el código actual; no se inspeccionó una URL de foto real del servidor.

**Corrección:** sólo en `vista=propio`, se reutiliza `renderAvatarUsuario` con el nombre recibido de la fila y su ruta original; no se calcula el usuario desde el rol. El helper original valida la fotografía y obtiene DM de Diana marke. Se conserva el avatar de 44 px, ahora con flex centrado, Manrope 16 px/peso 700, azul institucional y fondo claro. El margen simétrico conserva la altura de la tarjeta, también en móvil. Imagen centrada con `object-fit:cover`.

Un pequeño script de esa vista reentrega al listener existente sólo errores de imágenes personales ya fallidas antes de DOMContentLoaded. No duplica la lógica que reemplaza por iniciales ni oculta el 404. Helper, footer, encabezado, CSS global, ranking, reconocimientos, consultas, controladores, servicios y carga de fotografías no cambian.

**Pruebas dirigidas:** `tests/desempeno_avatar_browser.cjs` reproduce primero el fallo con la vista/CSS de base. Luego verifica DM, centrado con diferencia inferior a 0.01 px, fotos de fixture horizontales/verticales y URL original, archivo ausente, 404 inicial y fallback real del footer, comparación de colores/centrado con encabezado y escritorio/tableta/móvil. Nombre, rol, filtros, enlace PDF, métricas, anchos y alturas de la tarjeta iguales antes/después. Las vistas globales de Marketing, Analista y Cuenta Clave no cambian. Sin datos de usuarios ni BD real.

[Antes](evidencia/desempeno-avatar/antes.png) · [Iniciales corregidas](evidencia/desempeno-avatar/escritorio-iniciales.png) · [Foto de prueba](evidencia/desempeno-avatar/foto-vertical.png). Las imágenes de colores son fixtures, no fotografías de usuarios.

Publicador: `tools/publicar_avatar_desempeno_hostinger.sh`. SHA256 `f68ecf95ade80dad168ff02148b15a434404a4ac071d1c819833b32705c1df2c`. Sólo aplica los dos archivos visuales conocidos; requiere el helper institucional existente y rechaza personalizaciones incompatibles. `tests/desempeno_avatar_publicador_test.py` verifica LF/CRLF, vista sin salto final, comentarios compatibles, check sin escritura, apply idempotente y restore exacto. Helper, footer, CSS global, controladores, modelos y configuraciones de prueba permanecen intactos. Lint y revisión de diff correctos, sin CI global.

Tras descargar y verificar como `$HOME/impe-publicadores/avatar-desempeno.sh`, ejecutar `bash "$HOME/impe-publicadores/avatar-desempeno.sh" --check`; con resultado compatible, `bash "$HOME/impe-publicadores/avatar-desempeno.sh" --apply`. Se imprime la ruta exacta del respaldo fuera de `public_html` y el comando `--restore`: conservar esa salida. No toca credenciales ni base de datos.

**Pendiente en Hostinger:** no se desplegó ni fusionó automáticamente. Tras aplicar, recargar con Ctrl+F5 y comprobar el resumen con la cuenta autorizada, incluyendo su foto real si existe. No se afirma verificación de producción ni de la URL autenticada original hasta realizarla. La rama conserva los avances previos del PR #140; revisar esta corrección mediante su commit específico.
