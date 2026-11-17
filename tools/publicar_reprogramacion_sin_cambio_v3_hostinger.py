#!/usr/bin/env python3
"""Parche de dos archivos: rechazar reprogramaciones sin cambio de fecha/hora."""
import sys
import publicar_seguimiento_acciones_v3_hostinger as entrega

ARCHIVOS = [('app/services/EmbudoComercialV3SeguimientoService.php', '82826856bbeb79465e5af8fda2dc057aeee28e45', 'cc1cd1d61d9f59fbe4d56d68a7dfffb2be27e619'), ('public/javascript/embudo_comercial_v3_operativo.js', 'ade8e1194e9a55d3bbf1a57b68e5fa70896727f9', '249e617cccfcad255694d0eac1777af7582a40d3')]
DEPENDENCIAS = entrega.DEPENDENCIAS.copy()
DEPENDENCIAS.update({rel:nuevo for rel,_,nuevo in entrega.ARCHIVOS if rel not in {fila[0] for fila in ARCHIVOS}})
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
