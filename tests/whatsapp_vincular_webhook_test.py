import contextlib
from io import BytesIO, StringIO
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'tools'))
SPEC = importlib.util.spec_from_file_location('linker', ROOT/'tools/whatsapp_vincular_webhook.py')
M = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(M)
APP, ACCOUNT, PHONE, OTHER = '1794938525563302', '2683955112050855', '1508758925647776', '2202427980234937'
CONFIG = {'access_token':'synthetic-token-only', 'app_secret':'a'*32, 'graph_version':'v25.0'}


def apps(ids):
    return {'data':[{'whatsapp_business_api_data':{'id':value}} for value in ids]}


class FakeGraph:
    def __init__(self, responses):
        self.responses = iter(responses)
        self.calls = []
    def request(self, path, auth, query=None, method='GET'):
        self.calls.append((path, method))
        return next(self.responses)


class LinkTest(unittest.TestCase):
    def debug(self, **updates):
        data = {'app_id':APP, 'is_valid':True, 'scopes':list(M.PERMISSIONS)}
        data.update(updates)
        return {'data':data}

    def link(self, graph, subscribe=True):
        output = StringIO()
        with contextlib.redirect_stdout(output):
            result = M.link(CONFIG, APP, ACCOUNT, PHONE, subscribe, graph)
        for secret in (CONFIG['access_token'], CONFIG['app_secret']):
            self.assertNotIn(secret, output.getvalue())
        return result, output.getvalue()

    def test_subscription_adds_only_own_app_then_checks_and_keeps_other(self):
        graph = FakeGraph([self.debug(), {'data':[{'id':PHONE}]}, apps([OTHER]),
                           {'success':True}, apps([OTHER, APP])])
        result, output = self.link(graph)
        self.assertTrue(result)
        self.assertIn('SUSCRIPCION_OK', output)
        self.assertEqual([call for call in graph.calls if call[1]!='GET'], [(ACCOUNT+'/subscribed_apps', 'POST')])
        self.assertFalse(any('/messages' in path or method=='DELETE' for path, method in graph.calls))

    def test_check_never_writes(self):
        graph = FakeGraph([self.debug(), {'data':[{'id':PHONE}]}, apps([OTHER])])
        result, output = self.link(graph, subscribe=False)
        self.assertFalse(result)
        self.assertIn('SUSCRIPCION_FALTANTE', output)
        self.assertTrue(all(method=='GET' for _, method in graph.calls))

    def test_existing_subscription_does_not_post(self):
        graph = FakeGraph([self.debug(), {'data':[{'id':PHONE}]}, apps([OTHER, APP])])
        self.assertTrue(self.link(graph)[0])
        self.assertTrue(all(method=='GET' for _, method in graph.calls))

    def test_wrong_app_expired_missing_permission_and_wrong_phone_block_write(self):
        cases = [
            [self.debug(app_id=OTHER)],
            [self.debug(is_valid=False)],
            [self.debug(scopes=['whatsapp_business_messaging'])],
            [self.debug(), {'data':[{'id':'9999'}]}],
        ]
        for responses in cases:
            graph = FakeGraph(responses)
            with self.assertRaises(RuntimeError):
                self.link(graph)
            self.assertTrue(all(method=='GET' for _, method in graph.calls))

    def test_post_success_without_matching_readback_is_not_confirmed(self):
        graph = FakeGraph([self.debug(), {'data':[{'id':PHONE}]}, apps([OTHER]),
                           {'success':True}, apps([OTHER])])
        with self.assertRaisesRegex(RuntimeError, 'SUSCRIPCION_NO_CONFIRMADA'):
            self.link(graph)

    def test_provider_error_does_not_expose_secret_response_or_url(self):
        token = CONFIG['access_token']
        response = ('{"error":{"code":100,"message":"'+token+'"}}').encode()
        failure = HTTPError('https://graph.facebook.com/debug_token?input_token='+token,
                            400, token, {}, BytesIO(response))
        with patch.object(M, 'build_opener') as opener:
            opener.return_value.open.side_effect = failure
            with self.assertRaises(RuntimeError) as captured:
                M.Graph('v25.0').request('debug_token', APP+'|'+CONFIG['app_secret'], {'input_token':token})
        self.assertNotIn(token, str(captured.exception))
        self.assertIn('HTTP 400 codigo 100', str(captured.exception))


if __name__ == '__main__':
    unittest.main()
