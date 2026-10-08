# Escolaridad juvenil (15–17) y adulta (18+ / 25+): INEGI 2020

## Instalación del indicador de jóvenes

**Nueva migración obligatoria (una sola vez):** ejecutar `database/migrations/2026_10_08_escolaridad_juvenil.sql` sobre la misma BD del CRM. La anterior tabla adulta permanece intacta.

Desde **Información Territorial → Actualizar información oficial**, marcar:

- **Escolaridad juvenil · 15–17 años**: descarga el cuadro del Censo 2020 B2020_07_08_M, suma las edades individuales 15, 16 y 17 y guarda el mínimo identificable de jóvenes sin media superior concluida.
- **Escolaridad adulta · 18+ y 25+**: conserva la actualización existente de adultos.

Ambas opciones están disponibles por Estado o en la modalidad masiva para 32 Estados. Cada una usa un archivo oficial descargado de INEGI, validación de totales y tratamiento independiente de fallos. No se necesita CSV para jóvenes. La alternativa CSV visible en Educación corresponde **solo a adultos** y permanece contraída por defecto.

### Fórmula juvenil (2020)

**Población base:** suma de población Total estatal, sexo Total, edades 15, 16 y 17 (columna estadística 1).

**Numerador mínimo identificable:** para cada una de esas tres edades, suma de categorías 2 (sin escolaridad), 3 (preescolar), 4 (primaria total), 8 (secundaria total), 12 (técnicos con primaria) y 18 (uno o dos grados aprobados de preparatoria/bachillerato). No sumar simultáneamente subtotales y sus grados.

**Indeterminados:** categorías 14 (uno/dos grados técnicos con secundaria), 16 (grados técnicos sin especificar), 20 (grado de bachillerato sin especificar), 21 (normal básica) y 28 (nivel no especificado). No sumarlos al numerador. **Técnicos con secundaria de 3 o más grados (15)** se consideran acreditación identificable y tampoco entran al numerador.

**Incidencia (%):** `100 × numerador mínimo / población total 15–17`. No es una tasa de deserción, rezago educativo ni abandono escolar. En esta edad numerosas personas continúan cursando bachillerato.

### Valores de control contra fuentes reales de INEGI

| Entidad | Población 15–17 | Sin media superior concluida, mínimo | Porcentaje | Indeterminados |
| --- | ---: | ---: | ---: | ---: |
| Veracruz (clave 30) | 418,032 | 405,268 | 96.95% | 1,264 |
| Morelos (clave 17) | 100,192 | 96,646 | 96.46% | 876 |

**Archivos oficiales de control:**
- Veracruz: https://www.inegi.org.mx/contenidos/programas/ccpv/2020/tabulados/cpv2020_b_ver_07_educacion.xlsx
- Morelos: https://www.inegi.org.mx/contenidos/programas/ccpv/2020/tabulados/cpv2020_b_mor_07_educacion.xlsx

La prueba automatizada `tests/inegi_veracruz_real_smoke.php` comprueba ambos XLSX sin modificar la base de datos. Cada actualización real requiere que INEGI entregue un XLSX válido y que las tablas existan. Estados pendientes se mantienen como Pendiente.

---
# Sincronización automática de escolaridad adulta — INEGI

## Importación automática (recomendada)

1. Aplica una sola vez la migración database/migrations/2026_10_08_escolaridad_adulta.sql.
2. En Información Territorial, abre Veracruz, Morelos u otro Estado y selecciona Actualizar información oficial → Escolaridad adulta · 18+ y 25+.
3. Pulsa Actualizar información. El CRM intenta descargar de INEGI el tabulado del Censo 2020 B2020_07_08_M, valida el ZIP/XLSX, totales estatales y grupos de edad, y guarda las cifras.
4. Para los 32 Estados, desde la pantalla general de Información Territorial usa la misma opción en Actualizar información oficial y revisa las incidencias por Estado.

No hace falta preparar un CSV. Se requiere PHP con cURL, ZipArchive, DOMDocument y mbstring, y permiso data_territorial.actualizar_oficial. Una respuesta HTML, ZIP corrupto o archivo incompleto no produce ninguna cifra; el Estado queda pendiente y puede reintentarse.

## Definiciones y límites del cálculo censal

- 25+ sin educación superior: suma de categorías censales sin nivel superior aprobado; no especificados fuera del numerador. Denominador: población de 25 años o más.
- 18+ sin media superior concluida (MÍNIMO IDENTIFICADO): niveles inferiores a media superior y uno o dos grados de preparatoria/bachillerato. Los grados de estudios técnicos con secundaria, normal básica y escolaridad no especificada no acreditan ni excluyen conclusión: no se suman automáticamente. Por tanto el número calculado es un mínimo documentable, NO el total exacto de quienes no concluyeron media superior.

El tabulado presenta edades individuales 18 y 19, 20–24 y grupos desde 25–29 hasta 85+. No se duplica el subtotal 20–24 con las edades individuales. Referencia metodológica: https://www.inegi.org.mx/contenidos/programas/ccpv/2020/doc/Censo2020_criterios_tabulados_CPV_est_mun.pdf (B2020_07_08_M).

## CSV manual (solo si la descarga automática falla)
La actualización importa dos indicadores estatales con rangos definidos:
* SIN_EDUCACION_SUPERIOR_25_MAS — Personas de **25 años y más sin ningún grado de educación superior**.
* SIN_MEDIA_SUPERIOR_CONCLUIDA_18_MAS — Personas de **18 años y más sin media superior concluida**, incluyendo estudios incompletos.

## Fuente

El CSV debe prepararse cotejando un tabulado oficial que proporcione el numerador y denominador exactos de cada indicador. El tabulado quinquenal de 15–19 años no permite aislar 18 y 19 años. Las variables "algún grado de educación posbásica" de ITER no equivalen a media superior **concluida**.

IMPORTANTE: La importación comprueba encabezados, edades representadas por el código, coherencia de las cantidades, cobertura, fuente y referencia declaradas. **No valida contra INEGI las cifras escritas**, por lo que el analista debe comprobar el cálculo y conservar la metodología de cada fuente. No atribuir verificación automática a esta carga.

## Instalación

Aplicar database/migrations/2026_10_08_escolaridad_adulta.sql antes de utilizar el importador. El sistema permanece funcional sin esa tabla y muestra las tarjetas como pendientes.

## CSV UTF-8 (coma o punto y coma)

Encabezado exacto:

    clave_estado,codigo_indicador,anio,poblacion_base,cantidad_personas,fuente,referencia_url,metodologia

Cada Estado debe tener **ambos indicadores para el mismo año**. Puede ser un Estado o las 32 entidades. Clave INEGI de 2 dígitos (p. ej. 20 para Oaxaca), población base mayor que cero y cantidad entre cero y población base. Referencia HTTPS en inegi.org.mx. Explicar en metodología los niveles/grados incluidos y excluidos; para el indicador de 18+ distinguir explícitamente media superior incompleta y concluida.

La importación es transaccional y no admite filas duplicadas por Estado y código. Las consultas muestran el año más reciente de cada indicador, con fuente y metodología. El porcentaje es 100 × cantidad / población_base. La base es exclusivamente la población del grupo de edad correspondiente.

No importar números del ejemplo de la imagen sin cotejar su procedencia y significado.
