# Supervisión: paginación y carga del historial

La publicación cambia únicamente:
- `app/services/SupervisionLlamadasRepository.php`
- `app/views/telefonia/supervision_llamadas.php`
- `public/css/supervision_llamadas.css`

Base exacta: `e18a6d75` (`fix/audio-ventas-segun-resultado`). Los seis hashes comprobados en Hostinger coinciden con esta base. Controlador, servicio de audio y JavaScript se verifican como dependencias de sólo lectura. No se ejecutan SQL, migraciones, sincronizaciones, llamadas ni solicitudes de grabaciones durante la instalación.

## Comportamiento

Paginación numerada con página activa, anterior/siguiente, primera/última y elipsis. Conserva todos los filtros. En móvil oculta números vecinos cuando falta espacio, sin ocultar la página actual ni los extremos. Mantiene tipografía y colores institucionales, foco de teclado y movimiento reducido.

Para asesores, el listado consulta metadatos y resultados del periodo autorizado; carga eventos y evidencia sólo para las 30 tarjetas visibles. La lista de resultados y el total siguen correspondiendo al periodo completo. Elimina el límite de 3,000 llamadas de asesores. Las búsquedas por mensajes de estado PBX y las referencias históricas SDK/sin referencia conservan la normalización completa, al igual que la búsqueda de números recuperados de eventos. Esos casos pueden tardar más.

Las relaciones precargadas de Analistas se indexan por las mismas claves exactas, conservando duplicados y ambigüedad. Sus fuentes mixtas, deduplicación y límites existentes no cambian. No modifica la atribución histórica ni la autorización territorial, los resultados guardados o las reglas para habilitar audio.

## Validación

`tests/supervision_paginacion_db.php` compara arrays completos contra el repositorio original, con 2,670 llamadas sintéticas/89 páginas. Incluye páginas intermedias/finales, filtros, alias y dos resultados, acceso territorial, permiso revocado, referencias SDK y números recuperados de eventos. Comprueba también 3,100 llamadas/104 páginas y búsquedas por estado. Usa una base temporal y exige puerto distinto de 3306. La dependencia original y tres dependencias de la instalación selectiva están encapsuladas y verificadas en el fixture; no se publican.

Medición local orientativa: alrededor de 1,460 ms frente a 59 ms; 34,542,935 frente a 1,087,510 bytes SQL (96.9% menos). Los tiempos reales de Hostinger requieren comprobación después de instalar; no son una garantía de latencia. El test exige reducción de tráfico, no un umbral temporal frágil.

`tests/supervision_paginacion_browser.cjs` usa la vista PHP real a 1366, 768, 390 y 320 px. El QA existente del modal comprueba sus estilos, resultados, foco, audio y filtros. El paquete se prueba con Python moderno y Python 3.6.8, incluyendo bloqueo de variantes/dependencias desconocidas, integridad, idempotencia y restauración protegida.

## Instalación controlada

Descargar el publicador y manifiesto del mismo commit. Ejecutar `--check` antes de `--apply`, indicando `--site` y un `--backup-root` privado fuera de `public_html`. Los archivos públicos quedan en 644; los PHP conservan sus permisos. El instalador no ejecuta el CRM.

Si falla la compatibilidad, comparar la variante; no ampliar hashes sin reconstruir y probar. Para revertir usar el mismo publicador y manifiesto con `--restore /ruta/RESPALDO`, manteniendo el mismo sitio y backup-root. Se bloquea si hubo ediciones posteriores. Probar primera/intermedia/última página, los filtros de persona/fecha/resultado y consultar un resultado/audio ya existentes. No es necesario realizar llamadas nuevas.
