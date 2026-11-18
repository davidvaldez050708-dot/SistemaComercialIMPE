#!/usr/bin/env python3
"""Tarjetas comerciales compactas: presentación exclusivamente, sin SQL de escritura."""
import sys
import publicar_tipos_actividad_v3_hostinger as tipos

ARCHIVOS = [('public/css/embudo_v3_operativo.css', 'b5d90da2694e4864d565922a623c742f000b9817', '645f825858bb2d93c67016d0a8b0e6c56376136d'), ('app/services/EmbudoComercialPresentacion.php', 'fde041c40296dbef9405ec492da1366ec6e0716b', '4fc7af9c3969700950e8b57c5ff4c850ad2da3e3'), ('app/views/embudo_comercial/operativo_v3.php', '875b513a097e8b3cf0c4127b7e780a97aaa67e01', 'af214cb21fc5322b6cbb2e0a204ee8c5e32cfe15')]
DEPENDENCIAS=tipos.DEPENDENCIAS.copy()
DEPENDENCIAS.update({rel:new for rel,_,new in tipos.ARCHIVOS})
for rel,_,_ in ARCHIVOS:DEPENDENCIAS.pop(rel,None)
publicador=tipos.publicador
TELEFONIA_REL=tipos.TELEFONIA_REL
TELEFONIA_HOSTINGER=tipos.TELEFONIA_HOSTINGER
comprobar_esquema=tipos.comprobar_esquema

def configurar_dependencias(sitio):
    tipos.DEPENDENCIAS=DEPENDENCIAS
    tipos.configurar_dependencias(sitio)

def main():
    # Reutilizar el motor de archivos; no invocar tipos.main ni su ampliación SQL.
    supervision=tipos.anterior.supervision
    supervision.ARCHIVOS=ARCHIVOS
    supervision.DEPENDENCIAS=DEPENDENCIAS
    supervision.comprobar_esquema=comprobar_esquema
    supervision.main()

if __name__=='__main__':
    try:main()
    except (publicador.Bloqueo,OSError,ValueError) as error:
        print('BLOQUEADO: '+str(error),file=sys.stderr)
        sys.exit(2)
