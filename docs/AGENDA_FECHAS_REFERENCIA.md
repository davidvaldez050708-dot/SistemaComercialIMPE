# Referencias del calendario de Agenda: 2026 y 2027

Datos consultados el 29/10/2026 en [calendario.mx 2026](https://calendario.mx/) y
[calendario.mx 2027](https://calendario.mx/2027/). Se utilizan sólo nombres,
fechas y categorías; no se incorpora código, publicidad o recursos remotos del
sitio. Los datos estáticos están en `app/data/agenda_fechas_2026_2027.json`.

Se incluyen las dos anualidades completas para cubrir los días restantes de
2026 y todo 2027, con las categorías solicitadas:

| Categoría | Símbolo y color | Contenido |
| --- | --- | --- |
| Oficiales | ● rojo | 7 fechas de la lista anual de cada año; además la jornada electoral del 6/6/2027 que el sitio incluye como tipo `otro` y representa dentro de Oficiales. |
| Tradicionales | ◆ ámbar | 28 fechas de cada año, incluido el Día del Padre del 21/6/2026 y 20/6/2027. |
| Semana Santa | ✦ morado | Las 7 fechas de Cuaresma/Semana Santa de cada año publicadas por la fuente, incluidos Carnaval y Miércoles de Ceniza. |
| Fines de semana largos | ▰ gris | Los 5 periodos de 2026 y los 4 de 2027, con todos los días de cada rango. |

No se inventan puentes adicionales ni vacaciones escolares. Los días con varias
categorías conservan todas sus etiquetas: por ejemplo, Candelaria y Constitución
el 2/2/2026, y Domingo de Ramos/natalicio conmemorativo el 21/3/2027. La fecha
conmemorativa y el descanso trasladado son entradas diferentes.

La leyenda permite mostrar/ocultar únicamente las referencias. Cada etiqueta
tiene símbolo, nombre, categoría accesible y tooltip. Hoy conserva su estilo y
la selección del día sigue abriendo Reuniones. Los datos no generan eventos,
comentarios, notificaciones, recordatorios o restricciones de horario. No se
modifican permisos, solicitudes, confirmaciones, datos de BD o toasts.

La integración añade cuatro archivos (datos, parcial, CSS y JS) y una inclusión
en `app/views/seguimiento_vinculacion/agenda.php`, antes del calendario. El JS
consulta sólo las celdas ya existentes y no modifica botones o datos de
reuniones/comentarios. No hace fetch ni necesita conexión con la fuente. No
incluye categorías de luna, numeración semanal u otros cambios no solicitados.

`tools/publicar_agenda_fechas_hostinger.sh --check|--apply|--restore RUTA` instala
esta capa de forma selectiva con respaldo privado, preservación de LF/CRLF,
idempotencia y rechazo previo de variantes incompatibles. No ejecuta SQL ni
envíos. Los archivos nuevos se instalan antes de la inclusión y se retiran
después de restaurarla. Se genera con `tools/generar_publicador_agenda_fechas.py`.

Validación aislada: `tests/agenda_fechas_datos_test.py`, navegador real con
`tests/agenda_fechas_browser.cjs`, regresión de comentarios y
`tests/agenda_fechas_publicador_test.py`. No se han creado reuniones o mensajes
reales durante las pruebas. Los datos se guardan como una instantánea; cualquier
cambio futuro de la fuente requiere revisar y actualizar este archivo local.
