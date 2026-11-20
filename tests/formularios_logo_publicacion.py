import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('publisher', ROOT/'tools/publicar_formularios_logo.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)


class LogoPublicacionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.site = Path(self.temp.name)/'public_html'
        self.backups = Path(self.temp.name)/'respaldos'
        self.before = {}
        verified = {name: set(hashes) for name, hashes in M.BASES.items()}
        self.authorization = patch.object(M, 'BASES', verified)
        self.authorization.start()
        self.addCleanup(self.authorization.stop)
        for name in M.PATHS[:-1]:
            data = (ROOT/name).read_bytes().replace(M.NEW_SRC, M.OLD_SRC).replace(M.NEW_ALT, M.OLD_ALT)
            data += b'\n<!-- Personalizacion de produccion conservada -->\r\n'
            target = self.site/name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            self.before[name] = data
            M.BASES[name].add(M.digest(data))

    def assert_original(self):
        for name, data in self.before.items():
            self.assertEqual((self.site/name).read_bytes(), data)
        self.assertFalse((self.site/M.PATHS[-1]).exists())

    def test_check_apply_idempotence_restore(self):
        M.prepare(self.site)
        self.assert_original()
        self.assertFalse(self.backups.exists())
        M.apply(self.site, self.backups, None)
        for name, data in self.before.items():
            expected = data.replace(M.OLD_SRC, M.NEW_SRC).replace(M.OLD_ALT, M.NEW_ALT)
            self.assertEqual((self.site/name).read_bytes(), expected)
        self.assertEqual(M.digest((self.site/M.PATHS[-1]).read_bytes()), M.LOGO_SHA)
        backups = list(self.backups.glob('2*'))
        self.assertEqual(len(backups), 1)
        M.apply(self.site, self.backups, None)
        self.assertEqual(list(self.backups.glob('2*')), backups)
        M.restore(self.site, self.backups, backups[0])
        self.assert_original()

    def test_unexpected_header_blocks_all(self):
        target = self.site/M.PATHS[2]
        target.write_bytes(target.read_bytes().replace(M.OLD_ALT, b'alt="Otra institucion"'))
        with self.assertRaises(RuntimeError):
            M.apply(self.site, self.backups, None)
        self.assertFalse(self.backups.exists())
        self.assertFalse((self.site/M.PATHS[-1]).exists())
        self.assertEqual((self.site/M.PATHS[0]).read_bytes(), self.before[M.PATHS[0]])

    def test_partial_failure_restores_changes(self):
        original = M.atomic
        count = 0
        def failing(*args):
            nonlocal count
            count += 1
            if count == 2:
                raise OSError('fallo simulado')
            return original(*args)
        with patch.object(M, 'atomic', side_effect=failing):
            with self.assertRaises(OSError):
                M.apply(self.site, self.backups, None)
        self.assert_original()

    def test_restore_rejects_later_edits(self):
        M.apply(self.site, self.backups, None)
        target = self.site/M.PATHS[0]
        target.write_bytes(target.read_bytes()+b'<!-- posterior -->')
        with self.assertRaises(RuntimeError):
            M.restore(self.site, self.backups, next(self.backups.glob('2*')))


if __name__ == '__main__':
    with contextlib.redirect_stdout(io.StringIO()):
        unittest.main()
