"""SMTP efímero sólo en loopback. Datos ficticios; no reenvía a Internet.
Usa cryptography del runtime de pruebas, sin instalar dependencias del CRM.
Uso: python tests/correos_smtp_tls_test.py RUTA_PHP
"""
import datetime as dt
import email
from email import policy
import ipaddress
from pathlib import Path
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="impe-smtp-tls-") as tmp:
    tmp = Path(tmp)
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "localhost")])
    now = dt.datetime.now(dt.timezone.utc)
    cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
            .serial_number(x509.random_serial_number()).not_valid_before(now - dt.timedelta(minutes=5))
            .not_valid_after(now + dt.timedelta(hours=1))
            .add_extension(x509.SubjectAlternativeName([x509.DNSName("localhost"), x509.IPAddress(ipaddress.ip_address("127.0.0.1"))]), False)
            .add_extension(x509.BasicConstraints(ca=True, path_length=None), True).sign(key, hashes.SHA256()))
    (tmp / "cert.pem").write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    (tmp / "key.pem").write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(tmp / "cert.pem", tmp / "key.pem")
    messages, failures = [], []
    server = socket.socket()
    server.bind(("127.0.0.1", 0)); server.listen(); server.settimeout(35)

    def run_server():
        try:
            for _ in range(3):
                conn, _ = server.accept(); conn.settimeout(15)
                stream = conn.makefile("rb")
                conn.sendall(b"220 localhost SMTP QA\r\n")
                secure, auth = False, False
                try:
                    while True:
                        line = stream.readline()
                        if not line:
                            break
                        command = line.split(b" ", 1)[0].strip().upper()
                        if command in (b"EHLO", b"HELO"):
                            conn.sendall(b"250-localhost\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n")
                        elif command == b"STARTTLS":
                            conn.sendall(b"220 Begin TLS\r\n"); stream.close()
                            conn = context.wrap_socket(conn, server_side=True)
                            stream = conn.makefile("rb"); secure = True
                        elif command == b"AUTH":
                            assert secure, "AUTH sin TLS"
                            import base64
                            conn.sendall(b"334 VXNlcm5hbWU6\r\n"); assert base64.b64decode(stream.readline()) == b"qa@example.test"
                            conn.sendall(b"334 UGFzc3dvcmQ6\r\n"); assert base64.b64decode(stream.readline()) == b"FICTICIO"
                            auth = True; conn.sendall(b"235 Authenticated\r\n")
                        elif command in (b"MAIL", b"RCPT"):
                            assert auth, "sobre sin autenticación"
                            conn.sendall(b"250 OK\r\n")
                        elif command == b"DATA":
                            assert auth and secure
                            conn.sendall(b"354 End with dot\r\n"); lines = []
                            while True:
                                part = stream.readline()
                                if part in (b".\r\n", b""):
                                    break
                                lines.append(part[1:] if part.startswith(b"..") else part)
                            messages.append(email.message_from_bytes(b"".join(lines), policy=policy.default))
                            conn.sendall(b"250 Accepted QA\r\n")
                        elif command == b"QUIT":
                            conn.sendall(b"221 Bye\r\n"); break
                        else:
                            conn.sendall(b"250 OK\r\n")
                except ssl.SSLError:
                    # La prueba negativa rechaza nuestro certificado efímero no confiable.
                    assert not auth
                finally:
                    stream.close(); conn.close()
        except BaseException as err:
            failures.append(err)
        finally:
            server.close()

    thread = threading.Thread(target=run_server, daemon=True); thread.start()
    result = subprocess.run([sys.argv[1], str(root / "tests/correos_smtp_tls_client.php"), str(tmp), str(server.getsockname()[1])],
                            cwd=root, capture_output=True, text=True, timeout=55)
    thread.join(1)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    assert not failures, failures
    assert len(messages) == 2, "Sólo recuperación y comercial, sin envío con TLS inválido"
    recovery, commercial = messages
    assert recovery["From"].addresses[0].addr_spec == "qa@example.test"
    assert "30 minutos" in recovery.get_body(preferencelist=("plain",)).get_content()
    assert commercial["To"].addresses[0].addr_spec == "comercial@educacion.test"
    assert commercial["From"].addresses[0].addr_spec == "qa@example.test"
    assert commercial["Reply-To"].addresses[0].addr_spec == "contacto@empleado.test"
    assert commercial["Subject"] == "Prueba TLS con acentos"
    assert "Contenido íntegro" in commercial.get_body(preferencelist=("plain",)).get_content()
    attachments = list(commercial.iter_attachments())
    assert len(attachments) == 1 and attachments[0].get_filename() == "muestra.txt"
    assert attachments[0].get_payload(decode=True) == (root / "tests/correos_smtp_tls_client.php").read_bytes()
    print(result.stdout.strip())
    print("OK: certificado no confiable rechazado; MIME, destinatario externo, contenido y adjunto íntegros. Entrega real a proveedores pendiente.")
