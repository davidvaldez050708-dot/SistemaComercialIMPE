#!/usr/bin/env python3
"""Publica la confirmación pública de Inscripción sin cambios de datos."""
import sys

import publicar_inscripcion_estilos as publicador


publicador.PATHS = ['app/views/formularios/publico_gracias_inscripcion.php', 'app/services/FormularioInscripcionPantalla.php', 'public/javascript/formularios_inscripcion_completa.js']
publicador.MANIFEST_SHA256 = '0d63b85f25f5be6a496f79a7eb0e98bc02f94ca980ae3f69e7d926d3d7927102'


if __name__ == '__main__':
    try:
        publicador.main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
