import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

path=Path(__file__).resolve().parents[1]/"scripts/acs-oauth-pkce-hml.py"
spec=importlib.util.spec_from_file_location("acs_hml_auth", path)
a=importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)

class TestOAuthPkce(unittest.TestCase):
    def test_creates_pkce_s256_valid_state_and_preexisting_redirect(self):
        url, verifier, state=a.new_login()
        from urllib.parse import urlsplit, parse_qs
        query=parse_qs(urlsplit(url).query)
        self.assertEqual(urlsplit(url).scheme,'https')
        self.assertEqual(query['client_id'],['chatgpt-mcp'])
        self.assertEqual(query['redirect_uri'],[a.REDIRECT])
        self.assertEqual(query['code_challenge_method'],['S256'])
        self.assertEqual(query['code_challenge'],[a.b64url(a.hashlib.sha256(verifier.encode()).digest())])
        self.assertEqual(query['state'],[state])
        self.assertEqual(query['prompt'],['login'])
        self.assertTrue(43<=len(verifier)<=128)

    def test_accepts_exact_callback_and_rejects_wrong_origin_or_state(self):
        state='right'
        callback=a.REDIRECT+'?state=right&code=abcdefghABCDEFGH'
        self.assertEqual(a.parse_callback(callback,state),'abcdefghABCDEFGH')
        for invalid in [
            a.REDIRECT+'?state=wrong&code=abcdefghABCDEFGH',
            'http://other.example:8765/callback?state=right&code=abcdefghABCDEFGH',
            'http://127.0.0.1:8766/callback?state=right&code=abcdefghABCDEFGH',
            a.REDIRECT+'?state=right&state=right&code=abcdefghABCDEFGH',
            a.REDIRECT+'?state=right&code=abcdefghABCDEFGH&code=ijklmnop',
            a.REDIRECT+'?state=right&error=access_denied',
        ]:
            with self.subTest(invalid=invalid), self.assertRaises(a.SafeFailure):
                a.parse_callback(invalid,state)

    def test_token_exchange_never_includes_client_secret(self):
        saved=[]
        def mock_http(request,**kwargs):
            self.assertEqual(request.full_url,a.TOKEN)
            self.assertEqual(request.get_method(),'POST')
            self.assertNotIn(b'client_secret',request.data)
            self.assertIn(b'code_verifier=verifier',request.data)
            saved.append(True)
            return {'access_token':'VALID_TOKEN'},200
        with patch.object(a,'http_json',side_effect=mock_http):
            self.assertEqual(a.exchange_code('abcdefghij','verifier'),'VALID_TOKEN')
        self.assertEqual(len(saved),1)

    def test_only_fixed_mcp_ports_and_readonly_tool_calls(self):
        with self.assertRaises(a.SafeFailure):a.MCP(3100,'token')
        client=a.MCP(3105,'secret')
        self.assertEqual(client.url,'http://127.0.0.1:3105/mcp')
        with self.assertRaises(a.SafeFailure):client.call_readonly('acs_admin_executar')
        with self.assertRaises(a.SafeFailure):client.rpc('resources/write',{})

    def test_mcp_event_stream_parses_only_matching_jsonrpc_message(self):
        import io
        class Response:
            status=200
            headers={'Content-Type':'text/event-stream'}
            def __init__(self):
                self.stream=io.BytesIO(b':ping\n\nevent: message\ndata: {"jsonrpc":"2.0","id":1,"result":{"ok":true}}\n\n')
            def readline(self,n):return self.stream.readline(n)
        self.assertTrue(a.read_rpc_response(Response(),1)['result']['ok'])

    def test_read_only_comparison_detects_missing_legacy_tools(self):
        class FakeMCP:
            def __init__(self,port,token):self.port=port
            def initialize(self):pass
            def tools(self):
                return {'legacy','status_servidor'} if self.port==3104 else {'status_servidor',*a.TEST_TOOLS}
            def call_readonly(self,tool):
                raise AssertionError('tools must not run when missing legacy tool')
        with patch.object(a,'MCP',FakeMCP):
            self.assertEqual(a.run_smoke('secret'),2)

    def test_calls_readonly_tools_only_after_catalog_equivalence(self):
        calls=[]
        class FakeMCP:
            def __init__(self,port,token):self.port=port
            def initialize(self):pass
            def tools(self):return {'legacy','status_servidor'} | (set(a.TEST_TOOLS) if self.port==3105 else set())
            def call_readonly(self,tool):
                self.assert_port()
                calls.append(tool)
                return True
            def assert_port(self):
                if self.port != 3105:raise AssertionError('production tool invoked')
        with patch.object(a,'MCP',FakeMCP):
            self.assertEqual(a.run_smoke('secret'),0)
            self.assertEqual(calls,list(a.TEST_TOOLS))

if __name__=='__main__':unittest.main()
