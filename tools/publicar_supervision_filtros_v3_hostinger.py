#!/usr/bin/env python3
"""Parche de filtros Todos los asesores: URLs compatibles y navegación sin asesor=0."""
import sys
import publicar_supervision_comercial_v3_hostinger as supervision

ARCHIVOS = [('app/services/EmbudoComercialRepository.php', '591023ccd75aef4be31b334cf34fa75be83ce205', '0ee88c0f7ff62a49d1e7dc20506fa51f1c243e9a'), ('app/views/embudo_comercial/operativo_v3.php', 'ed45e1374f3f62961850fdcd0cd37ffd7d8b4f0a', '7a808c745d9d2c96e569d166165a72902d0f528d'), ('app/views/embudo_comercial/bandejas_v3.php', '665c5b511bdb01deb982a72b9c1e3ba001e4fb2b', 'd8a90af671220d465c871b7e70ca40dcb36a0395'), ('app/views/embudo_comercial/expedientes.php', 'f05b8af71461037ef76b76e4e75eda9e88780537', 'c57d33326c46f610e12b70b80ab6f9427cad2365')]
DEPENDENCIAS = supervision.DEPENDENCIAS.copy()
DEPENDENCIAS.update({'app/controllers/EmbudoComercialController.php': '20299073d4d263c3f37a641f6830e98efd497e97', 'app/views/embudo_comercial/detalle.php': 'd49d30db98ea861834e9c214246ac0fc1ea78b2c'})
publicador = supervision.publicador
TELEFONIA_REL = supervision.TELEFONIA_REL
TELEFONIA_HOSTINGER = supervision.TELEFONIA_HOSTINGER
comprobar_esquema = supervision.comprobar_esquema

def configurar_dependencias(sitio):
    supervision.DEPENDENCIAS=DEPENDENCIAS
    supervision.configurar_dependencias(sitio)

def main():
    supervision.ARCHIVOS=ARCHIVOS
    supervision.DEPENDENCIAS=DEPENDENCIAS
    supervision.main()

if __name__=='__main__':
    try:
        main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
