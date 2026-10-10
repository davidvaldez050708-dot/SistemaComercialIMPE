# Revisión responsive · Cuenta Clave, Marketing y Administrador

## Hallazgos respaldados por el CSS existente

- **Cuenta Clave**: métricas, agenda, equipo, cobertura y vínculos de acción
  mostraban textos de 6.7–9px. Nombres de aliados o tareas largas se truncaban
  con una sola línea. Para móvil se mejoró la tipografía, la división en dos
  líneas y la legibilidad táctil, sin cambiar el grid ni las funciones del rol.
- **Marketing**: encabezados de publicaciones con enlace «Ver» pueden competir
  por el ancho de teléfonos. El modal de convocatorias por vencer necesitaba
  una altura controlada en móvil y ajuste de nombres institucionales largos.
  Se conserva la tabla con desplazamiento horizontal **dentro** del contenedor.
- **Administrador**: la tabla de usuarios ya usa `table-responsive` y los
  indicadores usan la cuadrícula global. No se forzaron cambios innecesarios.

## Pruebas

```bash
php tests/roles_cuenta_clave_marketing_responsive_smoke.php
php tests/responsive_smoke.php
php tests/responsive_overflow_smoke.php
```

Validar realmente en navegador: Cuenta Clave y Marketing a 320, 375, 430,
768, 1024 y 1440 px, con nombres largos, listas vacías y llenas, acciones,
menú, modales y tablas, así como Administrador con usuarios y reportes.

**Esta revisión es de código, no una certificación visual total.**

## Actualización en Hostinger

Tras la integración de la PR, actualizar `~/crm-preparacion`, pasar las pruebas
y copiar **solo** `public/css/dashboard_cuenta_clave.css` y
`public/css/convocatorias.css` a `public_html/public/css/`.
No usar `--delete`; no tocar MySQL, configuraciones ni ficheros de usuarios.
