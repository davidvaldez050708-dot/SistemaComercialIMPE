# Mapas estatales de cobertura · Cuenta Clave

Se reutilizan exactamente las siluetas PNG de `public/img/estados/` que
ya emplean Seguimiento e Información Territorial. No se crean imágenes
ni dependencias nuevas.

Se conserva el nombre corto de cada territorio en la interfaz, pero se
añade `nombre_estado` al servicio para obtener la imagen por nombre oficial.
El helper local resuelve acentos y abreviaturas, incluida `Q. Roo`.
`Michoacán` utiliza el nombre real con tilde del archivo existente.

Las imágenes aparecen **solo en cobertura por estados**. La modalidad
municipal conserva el ícono original porque el proyecto no contiene
imágenes equivalentes para municipios. Si falta un PNG se usa el ícono
en vez de una imagen rota.

Verificación:
```bash
php tests/cuenta_clave_mapa_cobertura_smoke.php
php -l app/helpers/MapaEstadoHelper.php
php -l app/services/CuentaClaveDashboardService.php
php -l app/views/dashboard/cuenta_clave.php
```

Hostinger: copiar únicamente estos archivos desde `~/crm-preparacion`:
- `app/helpers/MapaEstadoHelper.php`
- `app/services/CuentaClaveDashboardService.php`
- `app/views/dashboard/cuenta_clave.php`
- `public/css/dashboard_cuenta_clave.css`

Los PNG estatales ya forman parte del repositorio; comprobar si existen en
`public_html/public/img/estados/`. Sin migraciones SQL ni operaciones
sobre los archivos personales de usuarios.
