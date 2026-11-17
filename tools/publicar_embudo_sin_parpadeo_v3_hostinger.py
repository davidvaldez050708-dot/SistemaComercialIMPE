#!/usr/bin/env python3
"""Cuatro scripts: actualización parcial del tablero V3 sin recargar el documento."""
import sys
import publicar_seguimiento_acciones_v3_hostinger as entrega

ARCHIVOS = [('public/javascript/embudo_comercial_v3_operativo.js', '249e617cccfcad255694d0eac1777af7582a40d3', '28e3b4b26d5dd5c94dc1b877bc1a34ad45fb663f'), ('public/javascript/embudo_comercial_v3_filtros.js', 'a5cc508a92ec08ddf2d63f845a2aeb7aa12edcf6', '3a46fdafc6acef9aa5030040e132439f54f73c13'), ('public/javascript/embudo_comercial_v3_inscripcion.js', '426d4f12b95696c5f2c37a13d05c350e5f769c74', '6da0995c4ccc3d9ca912895095e32c7d7450d3d5'), ('public/javascript/embudo_comercial_v3_bandejas.js', '172434d9dde862dd9bd2596e7fe0eb657b741b59', 'b382ae0dfacf39f976c00dcbd9a002acedb671fd')]
DEPENDENCIAS = entrega.DEPENDENCIAS.copy()
DEPENDENCIAS.update({'app/services/EmbudoComercialV3ProximaAccion.php': 'f87c3d688cdafa3118158af88453baa02dd178a4', 'app/services/EmbudoComercialPoliticaPropuesta.php': '7db5633df7417252a1d84b5298b44bf6612c2a1d', 'app/services/EmbudoComercialV3SeguimientoService.php': 'cc1cd1d61d9f59fbe4d56d68a7dfffb2be27e619', 'app/services/EmbudoComercialV3GestionPotencialService.php': '86519bcf2d6aea461a619035d7d6acbea814b27c', 'app/services/EmbudoComercialV3InscripcionService.php': '78117b36187694a383a638aecf4de1bae64606b4', 'app/services/EmbudoComercialResultadoBridge.php': 'a9666352990f2a5965deaaf98264f6a22e4be40d', 'app/views/embudo_comercial/operativo_v3.php': '94ffd131aad08d1125bddc86ebe6c46eedc12556'})

publicador = entrega.publicador
TELEFONIA_REL = entrega.TELEFONIA_REL
TELEFONIA_HOSTINGER = entrega.TELEFONIA_HOSTINGER
comprobar_esquema = entrega.comprobar_esquema

def configurar_dependencias(sitio):
    entrega.DEPENDENCIAS=DEPENDENCIAS
    entrega.configurar_dependencias(sitio)

def main():
    entrega.ARCHIVOS=ARCHIVOS
    entrega.DEPENDENCIAS=DEPENDENCIAS
    entrega.main()

if __name__=='__main__':
    try:
        main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
