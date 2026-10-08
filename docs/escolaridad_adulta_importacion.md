# Indicadores de escolaridad adulta — Información Territorial

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
