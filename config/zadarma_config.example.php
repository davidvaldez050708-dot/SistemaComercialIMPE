<?php

return [
    // Claves privadas generadas en Zadarma > Configuración > Integraciones y API.
    'api_key' => 'TU_API_KEY',
    'api_secret' => 'TU_API_SECRET',

    // Compatibilidad temporal con la prueba técnica anterior.
    // Se usa únicamente mientras telefonia_extensiones no tenga ninguna
    // asignación activa. En producción cada usuario debe tener su propia
    // extensión registrada en telefonia_extensiones.
    'pbx_extension' => '100',
];
