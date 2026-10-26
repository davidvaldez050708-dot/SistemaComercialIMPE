# Marketing: tres widgets operativos

Base: `a5fd2478`. Se sustituye exclusivamente la tabla Publicaciones recientes por Publicaciones del mes, Actividad reciente y Cobertura territorial. Indicadores Clave, bienvenida y los widgets inferiores existentes permanecen intactos.

Archivos de producción: `HomeController.php` (sólo la rama Marketing), `marketing.php`, nuevos `marketing_widgets.php` y `MarketingWidgetsModel.php`, y el bloque específico de `convocatorias.css`. No cambia `ConvocatoriaModel.php`: se reutiliza `obtenerRecientesDashboard(4)` con su orden por timestamp e ID. No hay migraciones, escrituras ni nuevos endpoints en esta implementación.

## Definiciones de datos

- El CRM no tiene un estado editorial separado. El mes usa **created_at**, como sus consultas de publicaciones por periodo; no confunde el inicio de vigencia con una hora de publicación. Periodo: mes calendario actual según la fecha de la BD.
- Publicaciones totales: todos los registros del mes, incluidos inactivos. Convocatorias publicadas: los del mes con `estado=1` y hoy entre inicio y término. Inactivas: el complemento del mes, coherente con la descripción «No vigentes»; incluye vencidas o programadas aún no vigentes. No se cambia ningún estado almacenado.
- Próximas: convocatorias actualmente vigentes que terminan entre hoy y hoy+7, ambos inclusive, aunque se registraran otro mes. No es una meta ni un porcentaje.
- Recientes: consulta existente limitada a cuatro, con `COALESCE(created_at, updated_at) DESC, id DESC`. Se mantienen los estados reales Activa, Próxima a vencer, Inactiva y Finalizada (gris). Se muestra fecha/hora de **created_at**; si no existe se indica «Fecha no disponible», sin inventar hora ni presentar una edición como publicación.
- Top territorial: `COUNT(DISTINCT c.id)` por estado, convocatorias con `estado=1` vigentes hoy y entidades activas. Una convocatoria multiestado contribuye una vez en cada estado. Orden: cantidad descendente, nombre e ID; límite cuatro, sin rellenar estados vacíos.
- El nuevo modelo ejecuta dos SELECT. Un fallo muestra indisponibilidad en la sección correspondiente, sin fabricar ceros; el log contiene sólo contexto y código numérico.

Ver todas y Ver mapa conservan `controller=convocatoria&action=index`; este último abre el selector territorial existente con siluetas. Los estados añaden `territorio_id`, y los enlaces de actividad los filtros existentes. Se respeta `convocatorias.ver`. No se requiere acceso adicional a Información Territorial.

Se reutilizan `MapaEstadoHelper.php` y `public/img/estados/*.png`. Se normalizan tildes sólo en el nuevo componente para resolver las rutas también en Windows; helper e imágenes originales no se modifican. Si falta un recurso se utiliza el icono territorial existente, sin inventar una silueta.

## Validación dirigida

`tests/marketing_widgets_test.php`: SQL real adaptado a SQLite en memoria, mes completo/límites, vigencias, duplicados, cobertura multiestado, desempates, cuatro recientes, estados, cambios de datos, vacíos y fallos. No conecta a Hostinger.

`tests/marketing_widgets_browser.cjs`: siete anchos entre 320 y 2172 px; orden y altura de las tres tarjetas en escritorio, móvil vertical, textos completos, imágenes cargadas, enlaces existentes, vacíos y permisos. Compara HTML, datos, dimensiones y estilos de Indicadores Clave y de los widgets conservados contra la base. No añade desbordamiento a la página; no modifica el desbordamiento preexistente ajeno al componente.

`tests/marketing_widgets_publicador_test.py`: aislamiento de los cambios, PHP/otros roles y CSS ajeno intactos; publicación selectiva con LF/CRLF, comentarios preservados, helper existente/ausente, check sin escritura, apply idempotente, restore exacto y rechazo de personalizaciones incompatibles. Sin CI global ni envíos.

Capturas del render aislado, **con datos de prueba, no cifras de producción**: [escritorio](evidencia/marketing-widgets/ancho.png), [móvil](evidencia/marketing-widgets/movil.png). Manrope, títulos de 18 px, cifras de 32 px, blancos/azules institucionales, bordes finos, tres columnas en escritorio y reorganización en tableta/móvil. Se conserva todo el texto; el escritorio compacto adapta anchos y tipografía auxiliar.

## Publicación manual

Publicador: `tools/publicar_widgets_marketing_hostinger.sh`.
SHA256: `e3c1c5393e160a18fffd4a684ea082d3efb160a25dd94ba3949cdca5b03cd46c`.

Requiere la base funcional con la homologación de Indicadores Clave y retirada de la tarjeta anterior ya aplicadas (PR #136/#137). Comprueba versiones conocidas antes de modificar. Instala cinco archivos del cambio y, sólo si falta, el helper de mapas original; no reemplaza helpers personalizados. Las siluetas existentes no se sobrescriben.

Después de descargar el publicador revisado en `$HOME/impe-publicadores/widgets-marketing.sh` y verificar su SHA256:

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
bash "$HOME/impe-publicadores/widgets-marketing.sh" --check
```

Con check compatible, ejecutar `bash "$HOME/impe-publicadores/widgets-marketing.sh" --apply`. Conservar el comando `--restore` con su ruta exacta que imprime. El respaldo queda fuera de `public_html`. Sin SQL, mensajes ni cambios en credenciales durante la publicación.

Falta aplicar y contrastar cifras, rutas y disponibilidad de siluetas en Hostinger. No hubo despliegue automático, prueba de negocio en producción ni fusión. La rama conserva los avances previos del PR #137; revisar los commits de esta implementación por separado del diff acumulado hacia main.
