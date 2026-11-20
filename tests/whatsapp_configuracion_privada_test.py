import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('setup', ROOT/'tools/configurar_whatsapp_privado.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
TOKEN = 'synthetic-token-0123456789-abcd'
SECRET = '0123456789abcdef0123456789abcdef'


@unittest.skipUnless(PHP, 'PHP CLI requerido')
class ConfigurationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.site = Path(self.temp.name)/'public_html'
        (self.site/'config').mkdir(parents=True)
        (self.site/'index.php').write_text('<?php throw new Exception("NO EJECUTAR");')
        shutil.copyfile(ROOT/'config/private_config.php', self.site/'config/private_config.php')
        self.env = patch.dict(os.environ, {key: '' for key in [
            'IMPE_PRIVATE_CONFIG_DIR', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_APP_SECRET',
            'WHATSAPP_VERIFY_TOKEN', 'WHATSAPP_GRAPH_VERSION']})
        self.env.start()
        self.addCleanup(self.env.stop)
        self.output = io.StringIO()

    def configure(self, token=TOKEN, secret=SECRET):
        with patch.object(M, 'hidden', side_effect=[token, secret]), contextlib.redirect_stdout(self.output):
            M.configure(self.site, PHP)

    def test_setup_php_readback_and_safe_check(self):
        self.configure()
        dest = M.destination(self.site)
        self.assertNotIn(self.site, dest.parents)
        state = M.read_state(self.site, PHP)
        self.assertEqual(state['config']['access_token'], TOKEN)
        self.assertEqual(state['config']['app_secret'], SECRET)
        self.assertGreaterEqual(len(state['config']['verify_token']), 32)
        self.assertNotIn(TOKEN, self.output.getvalue())
        self.assertNotIn(SECRET, self.output.getvalue())
        safe = io.StringIO()
        with contextlib.redirect_stdout(safe):
            config, missing = M.status(self.site, PHP)
        self.assertEqual(missing, [])
        for value in [TOKEN, SECRET, config['verify_token']]:
            self.assertNotIn(value, safe.getvalue())
        if os.name == 'posix':
            self.assertEqual(dest.stat().st_mode & 0o777, 0o600)

    def test_update_keeps_verify_token_and_makes_exact_backup(self):
        self.configure()
        dest = M.destination(self.site)
        original = dest.read_bytes()
        first = M.read_state(self.site, PHP)['config']
        self.configure(token=TOKEN+'-renewed')
        now = M.read_state(self.site, PHP)['config']
        self.assertEqual(now['verify_token'], first['verify_token'])
        self.assertEqual(now['access_token'], TOKEN+'-renewed')
        backups = list(dest.parent.glob('whatsapp-respaldo-*/whatsapp.local.php'))
        self.assertEqual(len(backups), 1)
        self.assertEqual(backups[0].read_bytes(), original)

    def test_existing_fallback_and_unrelated_keys_preserved(self):
        old = self.site/'config/whatsapp.local.php'
        data = b'<?php return ["test_template"=>"custom_template","extra"=>["enabled"=>true]];'
        old.write_bytes(data)
        self.configure()
        loaded = M.read_state(self.site, PHP)['config']
        self.assertEqual(loaded['extra'], {'enabled': True})
        self.assertEqual(loaded['test_template'], 'custom_template')
        self.assertEqual(old.read_bytes(), data)

    def test_invalid_secret_writes_nothing(self):
        with self.assertRaises(RuntimeError):
            self.configure(secret='not-an-app-secret')
        self.assertFalse(M.destination(self.site).exists())

    def test_environment_overrides_block_update(self):
        with patch.dict(os.environ, WHATSAPP_ACCESS_TOKEN=TOKEN):
            with self.assertRaises(RuntimeError):
                self.configure()
        self.assertFalse(M.destination(self.site).exists())

    def test_failed_readback_restores_previous_config(self):
        self.configure()
        dest = M.destination(self.site)
        original = dest.read_bytes()
        reader = M.read_state
        calls = 0
        def failing(*args):
            nonlocal calls
            calls += 1
            if calls == 3:
                raise RuntimeError('fallo simulado al comprobar')
            return reader(*args)
        with patch.object(M, 'read_state', side_effect=failing), self.assertRaises(RuntimeError):
            self.configure(token=TOKEN+'-new')
        self.assertEqual(dest.read_bytes(), original)

    def test_probe_challenge_without_post_or_secret_output(self):
        class Response:
            status = 200
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, size): return self.challenge.encode('ascii')
        class Opener:
            def open(self, request, timeout):
                from urllib.parse import urlsplit, parse_qs
                self_test.assertEqual(request.get_method(), 'GET')
                query = parse_qs(urlsplit(request.full_url).query)
                self_test.assertEqual(query['hub.verify_token'], ['synthetic-private-verify'])
                result = Response()
                result.challenge = query['hub.challenge'][0]
                return result
        self_test = self
        output = io.StringIO()
        with patch.object(M, 'build_opener', return_value=Opener()), contextlib.redirect_stdout(output):
            M.probe_webhook({'verify_token':'synthetic-private-verify'}, 'https://crm.example')
        self.assertIn('WEBHOOK_GET_OK', output.getvalue())
        self.assertNotIn('synthetic-private-verify', output.getvalue())

    def test_no_plain_http_no_secret_url_redirect(self):
        for base in ['http://example.com', 'https://user:password@example.com', 'https://example.com?secret=1']:
            with self.assertRaises(RuntimeError):
                M.probe_webhook({'verify_token':'synthetic'}, base)
        self.assertIsNone(M.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://other.example'))


if __name__ == '__main__':
    unittest.main()
