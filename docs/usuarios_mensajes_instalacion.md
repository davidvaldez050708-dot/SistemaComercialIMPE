# Mensajes de Gestión de usuarios

Al enviar un formulario vacío aparece «Revisa los campos señalados.» y cada
campo muestra su mensaje debajo. El foco pasa al primer campo con error.
La validación del servidor sigue siendo la autoridad para duplicados, roles
y protección del administrador. Si falla una escritura se muestra un aviso
breve, se conservan los datos capturados y no se exponen detalles SQL.
Las contraseñas y su hash no se guardan en los datos de recuperación del formulario.

Se conservan los selectores nativos de rol de Hostinger, la foto, los permisos,
las confirmaciones y la desactivación lógica de usuarios. No hay migraciones,
SQL de instalación ni cambios en telefonía.

## Instalación selectiva

Descargar `tools/publicar_usuarios_mensajes.py` y
`tools/usuarios_mensajes_release.json` desde el mismo commit, en una carpeta
privada fuera de `public_html`. Comprobar sus hashes publicados antes de ejecutarlos.

```bash
python3 publicar_usuarios_mensajes.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-usuarios-mensajes" --check
python3 publicar_usuarios_mensajes.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-usuarios-mensajes" --apply
```

El paquete verifica todos los archivos antes de escribir, comprueba sintaxis
PHP, guarda originales fuera del sitio y bloquea variantes desconocidas.
Es idempotente y revierte lo instalado si falla una escritura.

Bases exactas de Hostinger aceptadas (también admite sus equivalentes CRLF):

| Archivo | SHA-256 |
| --- | --- |
| app/controllers/UsuarioController.php | 2c6785a7aac55ceb68d1c88f1f0bb9d3a97d03b8e470fc33f036df42524a7452 |
| app/views/usuarios/index.php | e9e424c7344daebbe7a6b00147144975607f0fb0a51512993ffb01be145cbbd3 |

Los otros tres archivos son nuevos: el helper `UsuarioFormularioErrores.php`,
la hoja `usuarios_validacion.css` y el script `usuarios_validacion.js`.

## Verificación

1. Recargar Gestión de usuarios. Abrir Nuevo usuario y guardar vacío: aviso corto,
   campos señalados y foco en Nombre. Corregir un campo elimina su error.
2. Probar correo inválido, contraseñas diferentes y correo/usuario duplicados.
   El modal debe conservar los demás datos; la contraseña se vuelve a capturar
   si la respuesta vino del servidor.
3. Abrir Editar y comprobar nombre vacío/correo inválido; corregir y guardar.
4. Comprobar que la cuenta actual conserva bloqueados rol y estado. No se permite
   desactivarla ni dejar el sistema sin administrador activo.
5. En una cuenta de pruebas, confirmar la desactivación y comprobar el aviso
   de éxito. El usuario se conserva en la base de datos.

Pruebas locales realizadas con cuentas ficticias y base aislada: validación
cliente y servidor, alta válida, duplicados, edición, protecciones, desactivación,
fallo SQL simulado y presentación a 1440 y 390 px.
También se prueba el publicador sobre un sitio temporal, incluyendo rollback.

```powershell
$env:PHP_BINARY='C:/xampp/php/php.exe'
$env:NODE_PATH='C:/Users/chill/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules'
node tests/usuarios_errores_browser.cjs
```

La prueba de navegador necesita MariaDB local en `127.0.0.1:33319` y dependencias
de Bootstrap, Bootstrap Icons y Manrope en `../browser-deps/node_modules`.
La base de pruebas se elimina al finalizar; las guardas rechazan otra conexión.

## Restauración

Usar la ruta de respaldo que imprime `APLICADO`:

```bash
python3 publicar_usuarios_mensajes.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --restore "$HOME/respaldos-impe-usuarios-mensajes/usuarios-FECHA"
```

La restauración exige que los archivos instalados no hayan cambiado después.
No restaura ni modifica datos de usuarios.
