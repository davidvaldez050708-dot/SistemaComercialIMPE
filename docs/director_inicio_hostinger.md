# Inicio de Dirección de Operaciones

Inicio nacional para el rol 2, con los componentes visuales de Cuenta Clave: bienvenida, resumen operativo, prioridades, agenda, actividad telefónica y cobertura. Las prioridades utilizan `SeguimientoAtencionOperativaService`, compartido con Analista y Cuenta Clave. La actividad telefónica utiliza el mismo servicio y los mismos criterios del reporte de asesores.

## Contenido y permisos

| Bloque | Permiso necesario | Alcance |
| --- | --- | --- |
| Analistas y asesores activos | `usuarios.ver` | Personas activas de cada rol, sin duplicar asignaciones |
| Estados y asignaciones | `territorios.ver` | Todos los estados activos del catálogo; Dirección no requiere asignaciones |
| Cartera y prioridades | `seguimientos_vinculacion.ver` | Seguimientos activos, excluyendo descartados y convenios formalizados |
| Convenios formalizados | `convenios.ver` o `convenios.gestionar` | Relaciones institucionales activas formalizadas en Vinculación |
| Convocatorias vigentes | `convocatorias.ver` | Publicaciones activas dentro de sus fechas de vigencia |
| Agenda nacional | `reuniones.ver` | Las próximas seis reuniones solicitadas, confirmadas o con confirmación enviada de los siguientes siete días |
| Actividad telefónica de asesores de hoy | `reportes.ver`, `reportes.ventas` y `reportes.ventas.global` | Los mismos datos nacionales de Reportes; se muestran aparte llamadas, conexiones, conversaciones y resultados pendientes |
| Desempeño | `desempeno.ver` y `desempeno.ver_global` | Acceso al ranking del área seleccionada |
| Supervisión de llamadas | `llamadas.supervision.ver` | Acceso al módulo existente de historiales y grabaciones |
| Aliados y Prospectos | `aliados.ver` / `prospectos.ver_todos` | Accesos a las consultas habilitadas |

El acceso a Reportes exige además al menos una familia disponible. Se utilizan los permisos existentes, releídos desde la base de datos; no se crean ni conceden permisos nuevos. Los bloques sin autorización no consultan ni muestran sus datos. Las rutas de destino mantienen sus propias comprobaciones.

La cobertura muestra inicialmente ocho estados y permite desplegar el resto, incluyendo territorios sin asignación. El indicador de seguimientos y los de llamadas corresponden a sus fuentes respectivas; las llamadas de asesores no se atribuyen a estados. El tiempo confirmado corresponde únicamente al asesor.

Si falla una consulta, se muestra un aviso y su bloque queda no disponible. No se convierte el fallo en un cero ni se afirma que toda la operación está al día. Los datos se actualizan al volver a cargar Inicio.

## Instalación en SSH

El paquete publica cuatro archivos: actualiza `HomeController.php` y la vista de Dirección; añade el servicio de datos y una hoja de estilo propia. Reutiliza el CSS de Cuenta Clave sin modificarlo. La versión instalada se comprobó con los hashes proporcionados:

- Home: `fa9685c7a026022468ac7d13908a456ddf46b7c9a34dd49a0a53ddd51be3a4d2`.
- Vista: `ae2a5f568b794e8db2e841a19b20fcef99d3d541c934da7b04e80d6cd50ba289`.
- CSS compartido: `ce711828dff97f976b8e83f4ce669555f0e5a4d9bbd4e995b5710c65c6f90e4a`.

Ejecutar el bloque completo:

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-inicio-director-31daa607"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/31daa6073f307eb85567e77fc23be1a78dac3ac8"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_director_inicio.py" -o publicar_director_inicio.py
curl -fsSL "$BASE/tools/director_inicio_release.json" -o director_inicio_release.json
printf '%s\n' \
 '630e29d0a228b1f21c69e23e78d78cbf1b0296b85eb31a6d292421de7cdd5836  publicar_director_inicio.py' \
 '9ee66c384206f4fc87e3ef443aa05b9ed83e3d531b98b0384e96ac5bd66619ed  director_inicio_release.json' | sha256sum -c -
python3 publicar_director_inicio.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-inicio-director" --check
python3 publicar_director_inicio.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-inicio-director" --apply
)
```

La comprobación no escribe en el sitio. Las versiones desconocidas bloquean la instalación antes de modificar archivos. El respaldo privado conserva los originales y permite restaurarlos. Se admiten archivos LF y CRLF.

No hay migraciones ni actualizaciones de registros. Abrir Inicio no activa o desactiva convocatorias, no modifica reuniones y no consulta al proveedor telefónico. El motor, los reproductores y las API de audio permanecen intactos. Los inicios de los demás roles mantienen sus ramas actuales.

## Verificación

Después de instalar, abrir **Inicio** con Dirección de Operaciones:

1. Comparar prioridades con la cartera del equipo y abrir un seguimiento desde su tarjeta.
2. Abrir una próxima reunión; la agenda debe posicionarse en su mes y reunión.
3. Comparar las cifras de asesores con Reportes → Hoy.
4. Expandir la cobertura y comprobar los 32 estados si todos están activos en el catálogo.
5. Revocar un permiso de consulta y volver a cargar Inicio: su bloque desaparece.

Las pruebas aisladas incluyen SQL y HTTP reales, permisos revocados con una sesión antigua, 32 estados sin asignar a Dirección, exclusión de inactivos y descartados, criterios de atención compartidos, agenda, vigencia de convocatorias, datos de telefonía del servicio común, consultas sin escrituras, textos escapados, diseño a 320/390/768/1366 px y publicación/restauración exactas.

## Restaurar

Utilizar la carpeta exacta indicada al terminar la publicación:

```bash
python3 "$HOME/impe-inicio-director-31daa607/publicar_director_inicio.py" \
 --site "$HOME/domains/fundacionrededumex.org/public_html" \
 --backup-root "$HOME/respaldos-impe-inicio-director" \
 --restore "/ruta/exacta/impresa/del/respaldo"
```

La restauración bloquea cambios posteriores desconocidos y no modifica datos del CRM.
