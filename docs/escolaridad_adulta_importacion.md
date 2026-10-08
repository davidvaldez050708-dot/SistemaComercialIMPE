## Oaxaca · respaldo oficial nacional del Censo 2020

Si el archivo municipal `cpv2020_b_oax_07_educacion.xlsx` responde con HTML en
lugar de XLSX, el sincronizador intenta exclusivamente para Oaxaca (clave 20)
el libro **oficial nacional**:
`https://www.inegi.org.mx/contenidos/programas/ccpv/2020/tabulados/cpv2020_b_eum_07_educacion.xlsx`.

La hoja nacional de educación tiene un **cuadro por entidad federativa** con las
mismas 28 categorías educativas necesarias. El lector comprueba sus encabezados,
selecciona solo las filas `20 Oaxaca`, `Sexo = Total`, valida totales y grupos
15–17, 18+ y 25+ y guarda los tres indicadores como parte de la actualización
oficial normal. No inventa porcentajes ni utiliza el tabulado de tamaño de
localidad.

**Prueba automatizada:** `tests/inegi_oaxaca_nacional_smoke.php` descarga el libro
real en GitHub Actions y comprueba todos los grupos y universos sin acceso a la BD.
Los demás estados conservan sus URLs originales. Basta con repetir la
actualización masiva: los 31 estados ya completos se omiten y Oaxaca se reintenta.

---

# Sincronización automática de escolaridad por edades — INEGI

## Importación automática (recomendada)

1. Aplica una sola vez la migración database/migrations/2026_10_08_escolaridad_adulta.sql.
2. En Información Territorial, abre Veracruz, Morelos u otro Estado y selecciona Actualizar información oficial → Escolaridad por edades · 15–17, 18+ y 25+.
3. Pulsa Actualizar información. El CRM intenta descargar de INEGI el tabulado del Censo 2020 B2020_07_08_M, valida el ZIP/XLSX, totales estatales y grupos de edad, y guarda las tres cifras (15–17, 18+ y 25+), con fuente, año, denominador y metodología.
4. Para los 32 Estados, desde la pantalla general de Información Territorial usa la misma opción en Actualizar información oficial y revisa las incidencias por Estado.

No hace falta preparar un CSV. Se requiere PHP con cURL, ZipArchive, DOMDocument y mbstring, y permiso data_territorial.actualizar_oficial. Una respuesta HTML, ZIP corrupto o archivo incompleto no produce ninguna cifra; el Estado queda pendiente y puede reintentarse.

## Definiciones y límites del cálculo censal

- 15–17 años sin media superior concluida (MÍNIMO IDENTIFICADO): se suman únicamente edades individuales 15, 16 y 17 de la desagregación 15–19 y las categorías 2,3,4,8,12,18; denominador: población total de 15–17. **No mide abandono escolar**: incluye a jóvenes todavía cursando media superior. No asignar a nivel inconcluso las personas con grado técnico o escolaridad no especificada.
- 25+ sin educación superior: suma de categorías censales sin nivel superior aprobado; no especificados fuera del numerador. Denominador: población de 25 años o más.
- 18+ sin media superior concluida (MÍNIMO IDENTIFICADO): niveles inferiores a media superior y uno o dos grados de preparatoria/bachillerato. Los grados de estudios técnicos con secundaria, normal básica y escolaridad no especificada no acreditan ni excluyen conclusión: no se suman automáticamente. Por tanto el número calculado es un mínimo documentable, NO el total exacto de quienes no concluyeron media superior.

El tabulado presenta edades individuales 18 y 19, 20–24 y grupos desde 25–29 hasta 85+. No se duplica el subtotal 20–24 con las edades individuales. Referencia metodológica: https://www.inegi.org.mx/contenidos/programas/ccpv/2020/doc/Censo2020_criterios_tabulados_CPV_est_mun.pdf (B2020_07_08_M).

## CSV manual (solo si la descarga automática falla)
La importación CSV admite los dos indicadores adultos históricos y, opcionalmente, un tercer indicador juvenil:
* SIN_EDUCACION_SUPERIOR_25_MAS — Personas de **25 años y más sin ningún grado de educación superior**.
* SIN_MEDIA_SUPERIOR_CONCLUIDA_18_MAS — Personas de **18 años y más sin media superior concluida**, incluyendo estudios incompletos.
* SIN_MEDIA_SUPERIOR_CONCLUIDA_15_17 — **Mínimo identificado** de jóvenes de 15 a 17 años sin media superior concluida, no abandono escolar.

## Fuente

El CSV debe prepararse cotejando un tabulado oficial que proporcione el numerador y denominador exactos de cada indicador. El tabulado resumido quinquenal 15–19 no permite separar edades individuales. Usar el cuadro B2020_07_08_M, que sí desglosa 15, 16, 17, 18 y 19. Las variables "algún grado de educación posbásica" de ITER no equivalen a media superior **concluida**.

IMPORTANTE: La importación comprueba encabezados, edades representadas por el código, coherencia de las cantidades, cobertura, fuente y referencia declaradas. **No valida contra INEGI las cifras escritas**, por lo que el analista debe comprobar el cálculo y conservar la metodología de cada fuente. No atribuir verificación automática a esta carga.

## Instalación

Aplicar database/migrations/2026_10_08_escolaridad_adulta.sql antes de utilizar el importador. El sistema permanece funcional sin esa tabla y muestra las tarjetas como pendientes.

## CSV UTF-8 (coma o punto y coma)

Encabezado exacto:

    clave_estado,codigo_indicador,anio,poblacion_base,cantidad_personas,fuente,referencia_url,metodologia

Cada Estado debe tener **ambos indicadores adultos para el mismo año**; el tercero juvenil es opcional para conservar compatibilidad con archivos CSV anteriores. La actualización automática sí exige los tres. Puede ser un Estado o las 32 entidades. Clave INEGI de 2 dígitos (p. ej. 20 para Oaxaca), población base mayor que cero y cantidad entre cero y población base. Referencia HTTPS en inegi.org.mx. Explicar en metodología los niveles/grados incluidos y excluidos; para el indicador de 18+ distinguir explícitamente media superior incompleta y concluida.

La importación es transaccional y no admite filas duplicadas por Estado y código. Las consultas muestran el año más reciente de cada indicador, con fuente y metodología. El porcentaje es 100 × cantidad / población_base. La base es exclusivamente la población del grupo de edad correspondiente.

No importar números del ejemplo de la imagen sin cotejar su procedencia y significado.
