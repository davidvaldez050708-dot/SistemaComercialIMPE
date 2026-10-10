# Revisión responsive · Agenda y Expediente de Seguimiento

## Hallazgos y cambios

La Agenda ya conservaba un calendario de siete columnas desplazable dentro
de su tarjeta a menos de 992 px. Sin embargo, no explicaba el gesto al usuario
y el contenedor no podía enfocarse directamente con teclado. Se añadió una
indicación contextual, `role=region`, `tabindex=0` y un foco visible.

En el Expediente, `seguimiento_expediente.js` **ya implementaba** navegación
por pestañas con teclado y roles ARIA. No se modificó esa lógica. Los cambios
se limitan a evitar que nombres largos, etapas, acciones y formularios
excedan el ancho en móvil y a conservar el desplazamiento dentro de la
barra de pestañas.

## Verificación

```bash
php tests/agenda_expediente_responsive_smoke.php
php tests/responsive_smoke.php
php tests/responsive_overflow_smoke.php
php -l app/views/seguimiento_vinculacion/agenda.php
```

Probar manualmente con Analista, Cuenta Clave y otros roles autorizados a 320,
375, 430, 768, 1024 y 1440 px: cambiar mes, entrar en reunión, confirmar,
proponer/reprogramar, correos, vista previa, pestañas del expediente, actividad,
convenios, observaciones y eventos con nombres institucionales largos.
El desplazamiento horizontal debe quedar limitado al calendario y la barra.

**No es certificación visual completa**: los smoke son estructurales, no
pruebas de navegador con datos ni sesiones reales.

## Hostinger

Sin DB ni cambios de permisos. Sincronizar SOLO:

- `public/css/responsive_sistema.css`
- `app/views/seguimiento_vinculacion/agenda.php`

No usar `rsync --delete` ni cambiar archivos privados.
