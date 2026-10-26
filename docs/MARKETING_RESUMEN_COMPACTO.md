# Marketing: resumen compacto y retirada de dos paneles

Base: `b0acb38c`. Sólo cambian `app/views/dashboard/marketing.php` y `public/css/convocatorias.css`.

- Se retira el HTML/PHP de Publicaciones por tipo y de Cobertura territorial de convocatorias activas. No se ocultan con CSS ni se alteran sus consultas compartidas.
- El resumen mensual conserva su HTML, cuatro valores, periodo y distribución. Cifras: 32→28 px; título: 18→16 px; etiquetas: 12→11 px (10 px en escritorio compacto); descripciones: 11→10 px. Menos padding y márgenes, mismos colores, ancho y separadores. En escritorio su altura se ajusta al 82% de la fila con mínimo de contenido; las otras tarjetas mantienen sus dimensiones.
- Estados sin convocatoria activa aprovecha el ancho disponible al retirar el contenedor de dos columnas. Conserva su contenido, estilos y enlaces. No quedan contenedores vacíos ni se cambia el orden de las secciones.

Validación local dirigida con datos aislados, sin producción ni CI global:

| Pantalla | Altura anterior | Altura nueva | Reducción |
| --- | --- | --- | --- |
| 2172 px | 390.9 px | 320.5 px | 18.0% |
| 1517 px | 444.6 px | 364.6 px | 18.0% |
| 820 px | 380 px | 312 px | 17.9% |
| 390 px | 364.4 px | 295.3 px | 19.0% |

En 320 px la reducción es 13.2% para conservar todo el texto. Se verificaron también 1280 y 1200 px, sin recortes ni desbordamiento añadido. Separación entre secciones: 24 px.

`tests/marketing_compacto_browser.cjs` compara antes/después: datos/textos/HTML iguales en el resumen; Indicadores Clave, bienvenida, Actividad reciente y Convocatorias activas por estado con contenido, estilos y dimensiones idénticos; Estado sin convocatoria con contenido/estilos/enlaces conservados. Los dos paneles y el selector de periodo ya no se renderizan. Lint PHP y diff de alcance correctos.

[Antes](evidencia/marketing-compacto/resumen-antes.png) · [Después](evidencia/marketing-compacto/resumen-despues.png). Capturas de pruebas, no cifras de producción.

Publicador único: `tools/publicar_marketing_compacto_hostinger.sh`. SHA256 `03862b64e59cb4892d825bb932b1a0d8d03282eba6bda3c806cd0062a8a9bb43`. Requiere los tres widgets de la base ya instalados. Sólo aplica parches a vista y CSS conocidos; rechaza personalizaciones incompatibles, preserva comentarios compatibles, permisos y LF/CRLF. `tests/marketing_compacto_publicador_test.py` verifica check sin escritura, apply idempotente, respaldo, restore exacto y archivos funcionales protegidos intactos.

Tras descargar y verificar el publicador como `$HOME/impe-publicadores/marketing-compacto.sh`, ejecutar `bash "$HOME/impe-publicadores/marketing-compacto.sh" --check`; con resultado compatible, `bash "$HOME/impe-publicadores/marketing-compacto.sh" --apply`. Guardar el comando `--restore` con la ruta exacta del respaldo que imprime fuera de `public_html`. No se toca BD, credenciales ni otros módulos. Recargar Inicio con Ctrl+F5 y comprobar los datos existentes.

No se desplegó ni fusionó automáticamente. Falta la comprobación visual en Hostinger después de aplicar. La rama conserva los avances del PR #139 pendiente hacia main; revisar esta petición por su commit específico.
