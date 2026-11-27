# Recepción WhatsApp V1 — preflight

Candidato selectivo sobre los hashes de Hostinger auditados el 27 de noviembre. El ZIP contiene manifiesto de parches, comprobador Python y consulta de esquema de solo lectura. SHA-256: `cf1f2e2d741168566172655a80c322d6696d3ccff1edcb5a3695c75372d04d83`.

CHECK únicamente, sin APPLY ni modificación de runtime en esta rama. Descomprimir en directorio privado y ejecutar `python3 comprobar_recepcion.py --site RUTA --check`. Faltan pruebas transaccionales MariaDB, regresión de asignaciones, concurrencia y reversión antes de autorizar publicación. No desplegar esta rama completa; la base Git no representa todos los archivos personalizados de Hostinger.
