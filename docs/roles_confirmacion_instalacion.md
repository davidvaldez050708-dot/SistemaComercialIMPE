# Confirmación al activar o desactivar roles

El interruptor de la lista de Roles enviaba el POST inmediatamente.
Ahora abre un modal con las mismas clases, botones y medidas de la
confirmación de Usuarios. Identifica el rol, la acción y el efecto sobre
el inicio de sesión. Cancelar, cerrar o pulsar Escape no envía cambios.
Sólo el botón Activar/Desactivar confirma el POST existente.

Se conservan los permisos `roles.cambiar_estado`, el Administrador protegido,
el controlador y la auditoría actuales. No se cambia el editor de roles.
El nombre del rol se muestra mediante `textContent`, sin interpretar HTML.

La entrega sólo sustituye `app/views/roles/index.php`, tomando la versión
de Hostinger verificada: SHA-256
`7d92a963823c4107c2d0e92602c6a32a2ff817325e92aa3627e4c366f9ea6f5f`.
Se conserva su dependencia de `reportes.convocatorias`.
No se ejecuta SQL ni se modifican cuentas, permisos o telefonía al instalar.

## Instalación

Descargar `tools/publicar_roles_confirmacion.py` y
`tools/roles_confirmacion_release.json` del mismo commit en una carpeta
privada, fuera de `public_html`, y verificar sus hashes publicados.

```bash
python3 publicar_roles_confirmacion.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" --check
python3 publicar_roles_confirmacion.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" --apply
```

El publicador exige la versión revisada, verifica sintaxis PHP y conserva
un respaldo privado en `$HOME/respaldos-impe-roles-confirmacion`.
Para restaurar, usar la ruta de respaldo que imprime al instalar:

```bash
python3 publicar_roles_confirmacion.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --restore "$HOME/respaldos-impe-roles-confirmacion/roles-FECHA"
```

## Comprobaciones

- Pulsar el interruptor de un rol activo: debe aparecer «Desactivar rol»,
  con el nombre correcto y botones Cancelar/Desactivar.
- Cancelar o cerrar: el estado permanece igual.
- Confirmar sobre un rol de pruebas: usa el cambio de estado y aviso existentes.
- En un rol inactivo, el diálogo debe ofrecer Activar.
- Administrador conserva su interruptor deshabilitado. Sin permiso de cambio
  no se presentan ni interruptores ni modal.

Verificado en navegador con datos ficticios y sin SQL: no hay POST al abrir,
cancelar, cerrar o pulsar Escape; confirmar envía exactamente el rol/estado
seleccionados. Revisado a 1440 y 390 px, sin errores JavaScript. PHP lint
correcto. Paquete probado en un sitio temporal: comprobación sin cambios,
bloqueo de versiones desconocidas, instalación, idempotencia y restauración.
