# Seguimientos de analistas en territorios compartidos

La consulta se autoriza por la asignación territorial activa y vigente. La
operación exige además que `seguimientos_vinculacion.analista_id` corresponda
al usuario. El responsable guardado al generar, crear o importar el seguimiento
se conserva. No se reasignan expedientes ni se modifica el historial.

Los listados y el expediente permiten consultar a los compañeros del territorio.
La tabla muestra el Analista responsable; el panel ajeno indica «Solo consulta».
El filtro de Analista distingue responsables, incluso si comparten estado.

Las acciones del servidor, los servicios operativos de oficios/correo,
interacciones y reuniones utilizan el alcance de propietario. Las consultas de
ruta siguen usando el alcance de lectura. Los recordatorios y la lectura de
observaciones se restringen al responsable; las anticipaciones no cambian.
Las nuevas observaciones se dirigen al responsable del seguimiento, en lugar de
seleccionar al primer analista del estado.

Los seguimientos cuyo responsable ya no tenga territorio vigente quedan visibles
al equipo territorial pero no pasan automáticamente a su propiedad. Cualquier
reasignación de esos expedientes requiere un proceso separado.

## Validación

`tests/cartera_territorial_mysql.py` usa una copia temporal de la aplicación y una
base efímera con datos sintéticos. No carga configuración del CRM. Admite
`CARTERA_TEST_PORT`, `CARTERA_TEST_PASSWORD` y `CARTERA_TEST_VENDOR` para el entorno
local de pruebas. Comprueba dos analistas del mismo estado, propietarios reales,
consulta compartida, acceso fuera del territorio, usuarios/roles inactivos,
cinco acciones de controlador denegadas con HTTP 403, contacto propio,
correo, ruta, reuniones, avisos, observaciones, creación e importación Excel y
detección de instituciones ya registradas por otro analista.

`tests/publicacion_seguimientos_propios_test.py` comprueba la publicación en un
sitio ficticio: conservación de personalizaciones, LF/CRLF, comprobación sin
escritura, bloqueo ante bloques distintos, aplicación, repetición y restauración.

## Publicación

El paquete contiene `publicar_seguimientos_propios.py` y
`seguimientos_propios_release.json`. Colocarlos juntos fuera de `public_html`.

```bash
python3 publicar_seguimientos_propios.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-seguimientos-propios" --check
python3 publicar_seguimientos_propios.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-seguimientos-propios" --apply
```

El instalador aplica únicamente bloques cuyo contexto sea único y conocido,
conservando otros ajustes del archivo instalado. Comprueba todos los bloques y
la sintaxis PHP antes de escribir. Si encuentra diferencias en esos bloques,
se detiene sin tocar el sitio. Guarda el contenido original fuera del sitio
público y revierte si falla una escritura.

La instalación no accede a MySQL, no envía mensajes ni realiza llamadas.
Tras aplicar, comprobar con dos analistas reales que ambos ven la institución,
que el compañero abre el panel en solo consulta y que el responsable conserva
sus acciones. Repetir con un seguimiento importado. No se ha afirmado que esta
entrega ya esté instalada en producción.
