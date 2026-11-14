import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

src = Path(__file__).resolve().parents[1] / "scripts/acs-v2-cutover-3106.py"
spec = importlib.util.spec_from_file_location("acs_cutover", src)
app = importlib.util.module_from_spec(spec)
spec.loader.exec_module(app)

ORIGINAL = """server {
  listen 443 ssl;
  server_name mcp.jrconect.com;
  location = /mcp {
    proxy_pass http://127.0.0.1:3104;
    proxy_http_version 1.1;
    proxy_buffering off;
  }
  location /mcp-finance {
    proxy_pass http://127.0.0.1:3100;
  }
  location /mcp-admin {
    proxy_pass http://127.0.0.1:3100;
  }
  location /mcp-acs {
    proxy_pass http://127.0.0.1:3103/mcp;
  }
}
"""

def fake_container(port, image, *, is_new=False):
    env=[
        "FINANCE_MCP_ENABLED=true",
        "FINANCE_MCP_ALLOWED_SUB=some-sub",
        "IXC_WS_MCP_ENABLED=true",
        "IXC_WS_ALLOWED_SUB=some-sub",
        "OPA_API_MCP_ENABLED=true",
        "OPA_API_ALLOWED_SUB=some-sub",
        "SHIELD_MCP_ALLOWED_SUB=some-sub",
        "KEYCLOAK_JWKS_URL=https://auth.jrconect.com/realms/jrconect/protocol/openid-connect/certs",
        "OPA_API_ADMIN_ENABLED=false",
    ]
    if is_new:
        env+=["ACS_MONITOR_MCP_ENABLED=true","ACS_ADMIN_MCP_ENABLED=false"]
    return {
        "State":{"Running":True},
        "Image":image,
        "Config":{"Env":env},
        "NetworkSettings":{"Networks":{"jrconect-mcp_default":{}, "jrconect-auth_auth-internal":{}}},
        "HostConfig":{
            "ReadonlyRootfs":is_new,
            "PortBindings":{"3100/tcp":[{"HostPort":str(port),"HostIp":"127.0.0.1"}]},
        },
        "Mounts":[
            {"Destination":"/data","Source":"/tmp/state-"+str(port),"RW":True,"Type":"bind"},
            {"Destination":"/run/secrets/opa-token","Source":"/root/secrets/opa-token",
             "RW":False,"Type":"bind"},
        ],
    }

class ACSPromoteTests(unittest.TestCase):
    def test_only_exact_nginx_mcp_location_is_changed(self):
        updated=app.new_config(ORIGINAL.encode()).decode()
        self.assertEqual(app.selected_upstream(ORIGINAL),3104)
        self.assertEqual(app.selected_upstream(updated),3106)
        self.assertEqual(updated.count("proxy_pass http://127.0.0.1:3106;"),1)
        for unchanged in ["/mcp-finance", "/mcp-admin", "/mcp-acs",
                          "proxy_pass http://127.0.0.1:3103/mcp;",
                          "proxy_http_version 1.1;"]:
            self.assertIn(unchanged,updated)
        # Only the precise proxy_pass line is different.
        self.assertEqual(updated.replace(
            "proxy_pass http://127.0.0.1:3106;",
            "proxy_pass http://127.0.0.1:3104;",1),ORIGINAL)

    def test_refuse_missing_duplicate_or_wrong_upstream(self):
        for bad in (
            ORIGINAL.replace("3104;", "3100;",1),
            ORIGINAL.replace("  location = /mcp {","  location /mcp {"),
            ORIGINAL + "\n" + ORIGINAL,
            ORIGINAL.replace("proxy_pass http://127.0.0.1:3104;",
                             "proxy_pass http://127.0.0.1:3104;\n    proxy_pass http://127.0.0.1:3104;"),
        ):
            with self.subTest(bad=bad[:100]), self.assertRaises(app.Blocked):
                app.new_config(bad.encode())

    def test_mount_readonly_required(self):
        old=fake_container(3104,"sha256:old")
        self.assertEqual(app.mount_policy(old),{"/run/secrets/opa-token":"/root/secrets/opa-token"})
        old["Mounts"][1]["RW"]=True
        with self.assertRaisesRegex(app.Blocked,"SEGREDO_GRAVAVEL"):
            app.mount_policy(old)

    def test_compatibility_of_old_env_and_images(self):
        older=fake_container(3104,"sha256:old")
        newer=fake_container(3106,"sha256:new",is_new=True)
        def mock_inspect(name):
            return older if name==app.OLD else newer
        def mock_run(argv,**kwargs):
            if argv[:3]==["docker","image","inspect"]:return "sha256:new"
            return None
        with patch.object(app,"inspect",side_effect=mock_inspect), \
             patch.object(app,"run",side_effect=mock_run), \
             patch.object(app,"health"), \
             patch.object(app,"unauthenticated_401"):
            old_id,new_id=app.compare_containers()
        self.assertEqual(old_id,"sha256:old")
        self.assertEqual(new_id,"sha256:new")

    def test_missing_old_env_fails_closed(self):
        older=fake_container(3104,"sha256:old")
        newer=fake_container(3106,"sha256:new",is_new=True)
        newer["Config"]["Env"]=[
            x for x in newer["Config"]["Env"] if not x.startswith("IXC_WS_MCP_ENABLED=")]
        with patch.object(app,"inspect",side_effect=lambda name:
                          older if name==app.OLD else newer), \
             patch.object(app,"run",return_value="sha256:new"), \
             patch.object(app,"health"), \
             patch.object(app,"unauthenticated_401"):
            with self.assertRaisesRegex(app.Blocked,"VARIAVEL_LEGADA"):
                app.compare_containers()

    def test_admin_flag_must_remain_disabled(self):
        older=fake_container(3104,"sha256:old")
        newer=fake_container(3106,"sha256:new",is_new=True)
        newer["Config"]["Env"]=[
            "ACS_ADMIN_MCP_ENABLED=true" if x=="ACS_ADMIN_MCP_ENABLED=false" else x
            for x in newer["Config"]["Env"]]
        with patch.object(app,"inspect",side_effect=lambda name:
                          older if name==app.OLD else newer), \
             patch.object(app,"run",return_value="sha256:new"), \
             patch.object(app,"health"), \
             patch.object(app,"unauthenticated_401"):
            with self.assertRaisesRegex(app.Blocked,"ACS_ADMIN"):
                app.compare_containers()

    def test_activation_requires_operator_ack_before_any_write(self):
        with patch.dict(app.os.environ,{},clear=True), \
             patch.object(app,"preflight",side_effect=AssertionError("should not probe")):
            with self.assertRaisesRegex(app.Blocked,"APROVACAO_OPERADOR_AUSENTE"):
                app.activate()

    def test_rollback_refuses_unknown_nginx_changes(self):
        path=type("F",(),{"read_bytes":lambda self:b"third party edit"})()
        state={"nginx_path":"/tmp/example", "nginx_original_sha256":app.sha(b"orig"),
               "nginx_active_sha256":app.sha(b"candidate")}
        with patch.object(app.os,"geteuid",return_value=0), \
             patch.object(app,"guarded_lock",return_value=3), \
             patch.object(app,"load_state",return_value=(state,b"orig")), \
             patch.object(app,"live_nginx",return_value=path), \
             patch.object(app.os,"close"):
            with self.assertRaises(app.Blocked):
                app.rollback()

    def test_no_docker_migration_or_keycloak_changes(self):
        script=src.read_text()
        for banned in (
            '"docker", "restart"', '"docker", "rm"', '"docker", "stop"',
            '"docker","restart"', '"docker","rm"', '"docker","stop"',
            '"docker", "compose"', "docker.sock", "client_secret",
            "JRC_APPROVE_ACS_V2_CUTOVER=YES",
        ):
            self.assertNotIn(banned,script)
        self.assertIn('OLD_PORT = 3104',script)
        self.assertIn('NEW_PORT = 3106',script)
        self.assertIn("NGINX_LINK = Path",script)
        self.assertIn("NGINX_MUDOU_DURANTE_ATIVACAO",script)
        self.assertIn("NGINX_MODIFICADO_POR_TERCEIROS_NAO_SOBRESCREVER",script)

if __name__=="__main__":
    unittest.main()
