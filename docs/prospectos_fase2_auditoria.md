# Auditoría previa: Prospectos Fase 2

## Base comprobada antes del desarrollo

Rama independiente `feat/prospectos-filtros-fase2`, creada desde `feat/prospectos-busqueda-automatica` en `cd26140deb7b351e73588c649a17b10df9b4eee6`. Código operativo de Fase 1: `16caa746ec6336e29d457271dfca60da40494aaf`; paquete publicado: `3c4266a1da07ae6d0847d556efba16251f887667`.

Se contrastaron las tres ramas remotas solicitadas: Fase 1 `ee583bcb6ca059c52ed55782e1d9c963084d0f8f`, corrección territorial `7c2b545acf130a1625b8cd25cc24632df74b431b` y búsqueda automática `cd26140deb7b351e73588c649a17b10df9b4eee6`. No se parte de main.

El usuario verificó mediante SSH los cinco hashes de Hostinger. Todos coinciden con la última entrega y con su variante de tipografía V3:

| Archivo | SHA256 instalado |
| --- | --- |
| app/controllers/ProspectoController.php | 9965b91255a4450989752f984a9ad2be12182ef1ac123f0a5f1d979919df5f3e |
| app/models/ProspectoModel.php | 134f842cce551ad6206194f6f77b3b915cbbbeb5094c3d69d1310e2882a66d31 |
| app/views/prospectos/index.php | d7424a2908ec9d85e7dd1affa4d455493fa09f6015e0d196c76886d542dc6634 |
| public/javascript/prospectos_fase1.js | 85c79da9ba42f69b9239573ce92a0ba11977c1a68369950a5d0ae0ead24b0c3a |
| public/css/prospectos_fase1.css | 088d37e770a742168d817a04282eb520124aa0b10e1c5be9339f89c2583a3235 |

Además se descargaron los recursos públicos CSS/JS del dominio y sus bytes coinciden. La vista de Hostinger conserva las reglas Manrope V3; no se sustituirán por una copia de main.

## Funcionamiento existente

Prospectos lee formulario_registros. La procedencia se relaciona exclusivamente por tipo=registro y registro_id; escoge MIN(id) para no multiplicar una fila histórica si existen procedencias repetidas. El selector existente usa ap.aliado_id como identidad, aunque su catálogo aún es nacional y muestra MAX(aliado_nombre) de las procedencias.

Por estados resuelve el territorio del aliado a través de formulario_aliados → formulario_aliado_registro → aliados_manuales/seguimientos_vinculacion, con compatibilidad de vínculos antiguos por seguimiento_id. La residencia de formulario_registros sólo se muestra en sus columnas; no determina el ámbito. Todos conserva los generales y los orígenes sin territorio verificable.

La búsqueda actual por nombre, apellidos, móvil y correo consulta PHP con debounce, conserva foco y descarta respuestas atrasadas. La tabla tiene 14 campos, páginas de 25 y orden created_at DESC,id DESC. El acceso requiere sesión y prospectos.ver_todos.

## Programas y componentes

FormularioAliadoService valida el perfil elegido contra la oferta del aliado e inserta programa_codigo en formulario_aliado_procedencias. formulario_registros.perfil_interes conserva el nombre mostrado al registrar, también en registros generales. No se deducirá un código a partir de ese texto.

Los programas de aliados se identificarán por el código de su procedencia; los perfiles generales por su valor realmente almacenado, con opciones diferenciadas por origen. formulario_programas sólo aportará etiquetas; la oferta vigente de formulario_aliado_programas no excluirá registros históricos de programas retirados. No se infiere ninguna relación prospecto-convocatoria.

Los catálogos de opciones se limitarán al estado y al aliado seleccionados. La búsqueda textual y el programa activo filtrarán resultados, sin hacer desaparecer las otras opciones válidas del selector. Los nombres duplicados se distinguirán por ID real del aliado.

Se reutilizan Manrope, colores, tamaños y clases uniform-select del CRM con mejora local de búsqueda y lista acotada; no se modifica el componente global. Se conservará el select nativo y el envío GET como alternativa sin JavaScript.

## Límites

Sólo Fase 2. Sin migraciones, SQL de escritura, exportaciones, pantallas intermedias, cambios de columnas, permisos nuevos ni modificaciones de formularios públicos, QR u otros módulos. Las pruebas de integridad usarán un esquema sintético y huella antes/después; no se modificarán registros de producción.
