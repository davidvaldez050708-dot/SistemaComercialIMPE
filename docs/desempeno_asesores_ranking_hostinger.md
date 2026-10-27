# Asesores dentro de Desempeño y Reportes independiente

Actualización posterior: [retirar el filtro territorial de Asesores](desempeno_asesores_sin_territorio_hostinger.md). El filtro descrito en esta entrega inicial seleccionaba asesores por asignación, sin atribuir llamadas a estados.

La entrega `a8e02fa593c8abdcaab519ece74e37ee244e81f9` integra a los asesores en la misma vista de Desempeño del analista. El selector **Área** permite cambiar entre Analistas, Cuenta Clave y Asesores según el alcance y los permisos. En Cuenta Clave, la vista de equipo permite alternar entre sus analistas y sus asesores. La vista personal del asesor conserva únicamente su propia información.

Desempeño utiliza los componentes existentes: encabezado, estado de actualización, filtros, cinco indicadores, ranking, destacados, evolución y criterios. Reportes de actividad telefónica conserva los filtros, el detalle y su exportación; se eliminaron sus pestañas de Reportes, Desempeño y Otras áreas.

## Datos y alcance

Se reutilizan las mismas llamadas y cálculos del reporte. No se cambia ninguna grabación, resultado ni evento. Dirección y Administración consultan todos los asesores sin requerir asignaciones propias. Cuenta Clave sólo consulta asesores relacionados expresamente con su asignación vigente. El filtro de territorio selecciona asesores por su asignación actual; no representa residencia del prospecto ni reconstruye territorios históricos.

El índice operativo pondera volumen de llamadas (25%), volumen de contactos confirmados (35%), tasa de contacto (25%) y cumplimiento de resultados (15%). Los volúmenes se normalizan respecto al máximo del grupo filtrado. La conexión y el resultado deben confirmar conversación para contar contacto. Las duraciones pendientes no afectan el índice. Los empates se ordenan por contactos, llamadas y nombre; los reconocimientos comparten el mérito. La vista personal no asigna un índice competitivo.

La exportación de Desempeño conserva el ranking, los destacados, el historial y los criterios del mismo corte, con encabezado, A4 y paginación del PDF del analista. La exportación de Reportes mantiene el detalle de llamadas. Al cambiar de área se limpian persona y territorio para evitar trasladar filtros de otra población. Al editar filtros se oculta la exportación del corte anterior hasta actualizar.

## Aplicar la corrección

Requiere la instalación previa de Reportes de asesores. No reinstala permisos ni modifica la base de datos. Cambia diez archivos con versiones conocidas y comprueba siete dependencias sin modificarlas. Un archivo desconocido bloquea toda la aplicación antes de escribir. El respaldo queda fuera del sitio público y conserva los bytes originales, incluidos LF/CRLF.

Ejecutar este bloque completo en SSH:

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-ranking-asesores-a8e02fa5"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/a8e02fa593c8abdcaab519ece74e37ee244e81f9"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_desempeno_asesores_ranking.py" -o publicar_desempeno_asesores_ranking.py
curl -fsSL "$BASE/tools/desempeno_asesores_ranking_release.json" -o desempeno_asesores_ranking_release.json
printf '%s\n' \
'20d79d38243ddc3327a82ce04ae807b1563b8c62db5e05c5cbbd8882c69164b4  publicar_desempeno_asesores_ranking.py' \
'1dc481c71aeba45be1705da375aabd8e46d659ba8ee5af687cab854585ed64d6  desempeno_asesores_ranking_release.json' | sha256sum -c -
python3 publicar_desempeno_asesores_ranking.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-ranking-asesores" --check
python3 publicar_desempeno_asesores_ranking.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-ranking-asesores" --apply
)
```

Después de **APLICADO**, recargar el CRM. Entrar a **Desempeño → Área → Asesores**. Reportes permanece en **Reportes → Actividad telefónica de asesores**. No se necesita volver a conceder permisos.

Para restaurar, usar la ruta exacta que imprimió `APLICADO. RESPALDO:`:

```bash
python3 "$HOME/impe-ranking-asesores-a8e02fa5/publicar_desempeno_asesores_ranking.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-ranking-asesores" \
  --restore "$HOME/respaldos-impe-ranking-asesores/REEMPLAZAR_POR_EL_RESPALDO_GENERADO"
```

## Comprobaciones incluidas

- Métricas, índice, empates, cero actividad y vista individual.
- Paridad de cifras entre Reportes y Desempeño.
- SQL y HTTP con MySQL 8 y MariaDB 10.11: filtros territoriales, equipo explícito, permisos, revocación, PDF y ausencia de escrituras en telefonía.
- Chromium y WebKit: escritorio, móvil, cambio de área, limpieza de filtros y exportación del corte mostrado.
- PDF A4 de reportes, desempeño de asesores y regresión del analista.
- Publicador: versiones exactas, dependencias intactas, LF/CRLF, conflictos, reinstalación y restauración.

La entrega inmutable se validó con las seis comprobaciones aprobadas (PDF, publicación, Chromium, WebKit, MySQL y MariaDB): [ejecución 40258828517](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40258828517).
