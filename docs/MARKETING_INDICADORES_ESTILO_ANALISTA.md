# Indicadores Marketing: homologación visual con Analista

Cambio visual `0fae0e54`, basado en `04e2ada1`. Único archivo de producción modificado: `public/css/convocatorias.css`, exclusivamente el bloque `.marketing-key-indicators`. El resto de ese CSS es idéntico.

Se eliminó la reducción a 20 px del escritorio compacto y se copiaron las declaraciones de `dashboard_analista_boceto.css` y `dashboard_analista_boceto_ajustes.css`, comprobando sus estilos calculados con Chrome y el orden real de las hojas del Inicio.

| Elemento | Escritorio/tableta | Móvil | Peso/color |
| --- | --- | --- | --- |
| Cifras | 24 px, interlineado 24 px | 21 px, interlineado 21 px | 800, `#0e1726` |
| Etiquetas | 11 px, interlineado 16.5 px | 10 px / 15 px | 700, `#58687f` |
| Descripciones | 10 px / 15 px | 9 px / 13.5 px | 600, `#8a96a8` |
| Encabezado | 13 px / 15.6 px | 12 px / 14.4 px | 800, `#183f76` |
| Descripción del encabezado | 11 px / 16.5 px | 10 px / 15 px | 600, `#748198` |
| Botón | 11 px, altura mínima 52 px | 10 px, altura mínima 48 px | 800, `#17478c` |

Manrope heredada, sin importar fuentes. Cifra→etiqueta: 2 px; etiqueta→barra: 9 px; barra→descripción: 6 px (5 px móvil). Barras de 7 px, radio 999 px, azul `#4b8fd7` sobre `#e6ebf2`. Separadores `#e2e7ef`; panel con radio 14 px y padding 19 × 20 px en escritorio. Botón con padding 0 × 14 px, borde `#d9e4f2`, radio 10 px y fondo `#f8fbff`.

Se ocultan únicamente los iconos decorativos de las cifras, como hace Analista; el botón conserva su icono institucional con recuadro de 30 px. Para conservar cinco métricas, se adapta el ancho de columnas y padding horizontal en escritorio compacto; tableta usa tres columnas y móvil dos. No se trunca ni oculta texto. El panel mide 130 px de alto a 2172 px de pantalla, aproximadamente 140 px a 1517 px y 156 px a 1280 px; su altura puede crecer al envolver textos.

## Evidencia y alcance

Capturas locales con el render aislado de pruebas existente, **sin datos ni conexión a la BD de Hostinger**. Las cifras de esa prueba no representan los registros actuales del CRM. La referencia usa el fragmento PHP real de Analista y las cifras de la captura del usuario.

- [Marketing antes](evidencia/marketing-estilo-analista/marketing-antes.png).
- [Marketing después](evidencia/marketing-estilo-analista/marketing-escritorio.png).
- [Referencia Analista](evidencia/marketing-estilo-analista/referencia-analista.png).
- [Marketing móvil](evidencia/marketing-estilo-analista/marketing-movil.png).

Validación dirigida: `tests/marketing_estilo_analista_browser.cjs` compara tipografía, colores, interlineado, márgenes, barras y botón contra Analista; verifica cinco métricas, cuatro barras, publicaciones sin barra, texto/valores/porcentajes/ruta idénticos antes y después, otros widgets y ausencia de nuevos desbordamientos. Anchos: 2172, 1517, 1440, 1280, 1200, 820, 390, 360 y 320 px. Barras alineadas en escritorio. No se ejecutó CI global.

`tests/marketing_estilo_publicador_test.py` verifica LF/CRLF, check sin escritura, apply de un único CSS, idempotencia, restore exacto y rechazo de personalizaciones. Controladores, modelos, vistas, JavaScript, permisos, datos y dashboard Analista permanecen intactos. Se conserva el desbordamiento ajeno al panel que ya existía en otro widget a ciertos anchos; este cambio no lo amplía.

## Publicación selectiva (manual)

Requiere el panel funcional de `ddf5b732`/`04e2ada1` ya instalado. Si encuentra otro CSS personalizado o incompatible, se detiene sin sustituirlo. No utilizar el publicador anterior de seis archivos para esta corrección estética.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/codex/marketing-indicadores-estilo-analista/tools/publicar_estilo_indicadores_marketing_hostinger.sh' \
  -o "$HOME/impe-publicadores/marketing-estilo-analista.sh"
printf '%s  %s\n' \
  '42720352850ca6a7d84fcd7478f4506371384aa07290a844164585b3279c814a' \
  "$HOME/impe-publicadores/marketing-estilo-analista.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/marketing-estilo-analista.sh" --check
```

Después de un check compatible:

```bash
bash "$HOME/impe-publicadores/marketing-estilo-analista.sh" --apply
```

Imprime la ruta exacta del respaldo fuera de `public_html` y su comando `--restore`. Conservar esa salida y ejecutarlo si se requiere volver al CSS anterior; no reemplazar la ruta por un ejemplo. Restore también rechaza ediciones posteriores. No toca credenciales, SQL, correo ni otros archivos de producción.

Después de aplicar, recargar Inicio como Marketing con Ctrl+F5 y contrastar las cinco cifras con las previas. Falta esa comprobación en Hostinger: no hubo despliegue automático ni fusión del PR.
