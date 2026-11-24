# Medición de vistas en la sesión de Chrome — 23 de noviembre de 2026

El usuario confirmó que el acceso mejoró después de reducir las consultas de
marcaciones, pero mostró cargas transitorias con menú y título visibles y el
contenido de Recuperación comercial e Inscritos todavía en blanco.

Se le solicitó abrir Inscritos manualmente en Chrome. Se inspeccionó su ventana
mediante Computer Use y DevTools. No se introdujeron credenciales, enviaron
mensajes, iniciaron llamadas ni modificaron registros comerciales. Las lecturas
adicionales fueron GET de Inscritos y Recuperación; se imprimieron sólo tiempos,
nombres de archivos y cabeceras de transporte, nunca el contenido de las fichas.

## Lecturas con la conexión anterior al CDN

- Navegación a Inscritos: espera de respuesta 1425 ms, documento recibido en
  1571 ms, DOMContentLoaded en 1615 ms. El total de navegación registrado al
  terminar la carga fue 1640 ms.
- CSS y JavaScript propios de Inscritos: 8 ms cada uno, servidos de caché.
- Petición de datos `inscritos.listar`: 198 ms.
- Otra lectura del HTML de Inscritos: 1664 ms en Chrome, con
  `x-hcdn-upstream-rt: 0.081`.
- Una lectura posterior completó en torno a 2476 ms. El tiempo de cabeceras
  observado por JavaScript fue 189 ms y la cabecera del origen indicó 0.076 s.
  Las diferencias entre la entrega al cliente y el origen requieren una prueba
  de transporte; no pueden atribuirse automáticamente a consultas SQL.
- DevTools mostró que la simulación de conexión lenta estaba desactivada.
- Las peticiones utilizaron HTTP/3 hacia `hcdn`. No había service worker activo
  en los tiempos de navegación consultados.

Los datos no justifican cambiar el diseño, añadir cargadores ni convertir
globalmente los scripts a defer: en esta navegación los recursos del módulo
estaban en caché y el listado respondió rápido. El HTML ya contiene los filtros
y la tabla antes de que JavaScript solicite los datos.

Se vio además un error de promesa en `telefonia_audio_dispositivos.js`, al leer
`value` en `render`. Es una observación separada; no se ha demostrado que cause
las esperas de red y no se modificó ese código en este diagnóstico.

## Prueba de CDN autorizada

El usuario autorizó explícitamente desactivar temporalmente sólo el CDN del
dominio y restaurarlo si no mejora. Antes de actuar se conservaron en memoria
la configuración y la zona DNS completa. No se guardan valores TXT/MX en el repo.

Hostinger confirmó `applied.cdn=false`. La lectura posterior confirmó:

- CDN desactivado; SSL activo y redirección HTTPS conservada.
- Caché de servidor y modo de desarrollo conservados como desactivados.
- A del dominio: 82.180.138.104; AAAA: 2a02:4780:b:864:0:a27:278c:3.
- `www` apunta al dominio; el subdominio de pruebas conserva su configuración.
- Registros MX y TXT idénticos a los anteriores.

Tres lecturas externas nuevas resolvieron al servidor directo, `Server: LiteSpeed`,
HTTP 200, con TTFB de 287, 233 y 243 ms. Sin embargo la ventana de Chrome existente
siguió utilizando una conexión HTTP/3 abierta al CDN (`Server: hcdn`). Esas
lecturas NO son una medición de la ruta nueva, aunque se hicieran después de
desactivar el CDN. Se solicitó una ventana de incógnito para comparar sin cerrar
la sesión ni el teléfono de la ventana original.

## Resultado con conexión nueva y servidor directo

El usuario abrió Inscritos manualmente en una ventana de incógnito. Se verificó
la ventana y se repitieron las lecturas autenticadas. Todas devolvieron
`Server: LiteSpeed`, sin `x-hcdn-upstream-rt`; el protocolo siguió siendo HTTP/3.
Por tanto, estos resultados no justifican culpar por sí solo al protocolo HTTP/3.

| Vista o consulta | Con conexión al CDN observada | Conexión nueva al servidor directo |
|---|---:|---:|
| HTML de Inscritos | 1664–2316 ms en las lecturas comparadas | 105 ms |
| Datos de Inscritos | 198–223 ms | 93 ms |
| HTML de Recuperación | 2227 ms | 101 ms |

La navegación inicial de Inscritos en la ventana nueva registró TTFB de 94 ms,
documento recibido en 101 ms y DOMContentLoaded en 198 ms. Las dos vistas HTML
se descargaron en aproximadamente 4 ms después de recibir las cabeceras.

Hay una mejora fuerte y verificable al conectar directamente con el origen.
La ventana nueva también cambia el contexto de conexiones y extensiones, por
lo que no se extrapola una causa exclusiva para cada demora anterior ni se
promete un tiempo idéntico en todas las vistas y equipos. Los resultados sí
justifican conservar el CDN desactivado bajo la prueba autorizada: se había
acordado restaurarlo si no mejoraba. El código y las reglas de acceso del CRM
no se modificaron durante esta prueba.

La ventana normal puede conservar la conexión antigua. Reiniciar Chrome,
cuando no haya llamadas activas, permite que tome la ruta DNS actual. No se
cerraron ventanas del usuario ni se automatizó el inicio/cierre de sesión.
