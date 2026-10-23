# Aliados: retirar Cobertura internacional

Base `12d6755c`. Único cambio de producción: eliminar el bloque visual de Cobertura internacional — Aliados externos, su icono, descripción, botón y condición de presentación en `app/views/aliados/index.php`. No se oculta mediante CSS ni se sustituye por otro componente.

No necesita CSS: el selector territorial ocupa naturalmente el espacio. El resto de la vista, incluido su JavaScript de búsqueda, filtros, selección, paginación y navegación, permanece idéntico. La ruta `controller=aliado&action=internacionales`, controladores, modelos, servicios, permisos y vistas de directorio/internacionales no cambian. No se accedió a la BD ni se eliminaron registros, contactos, enlaces, respuestas, archivos o relaciones.

Pruebas dirigidas: lint PHP; comparación de la vista real antes/después con fixtures en Chrome a 1517, 820 y 390 px. Se comprueba ausencia de tarjeta/botón/hueco, contenido/dimensiones/estilos de componentes nacionales iguales, búsqueda, filtros Con/Sin aliados, selección territorial y destino de Ver aliados. Cuenta Clave permanece igual. La navegación se intercepta para no ejecutar controladores ni operar datos reales; no es una prueba de negocio en producción. Sin CI global.

Publicador único: `tools/publicar_retirar_cobertura_aliados_hostinger.sh`. SHA256 `c71086f1ff9fe984a58af2be177870f48aaa331d10a7f8084c56c9fca74f77ec`. Modifica sólo esa vista conocida, verifica sintaxis y preserva permisos y LF/CRLF; rechaza personalizaciones incompatibles. Pruebas en sitio temporal: check sin escritura, apply idempotente, respaldo, restore exacto y rechazo de ediciones antes/después; archivos protegidos intactos.

Tras descargar y verificar como `$HOME/impe-publicadores/retirar-cobertura-aliados.sh`, ejecutar `bash "$HOME/impe-publicadores/retirar-cobertura-aliados.sh" --check`; con revisión compatible, `bash "$HOME/impe-publicadores/retirar-cobertura-aliados.sh" --apply`. Guardar el comando `--restore` con la ruta exacta del respaldo que imprime fuera de `public_html`. Recargar Aliados con Ctrl+F5 y comprobar la interfaz existente.

No se desplegó ni fusionó automáticamente. Falta comprobar en Hostinger después de aplicar. La rama conserva los avances del PR #141; revisar la retirada mediante su commit específico.
