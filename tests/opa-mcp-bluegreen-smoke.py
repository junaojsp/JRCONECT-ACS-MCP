#!/usr/bin/env python3
import importlib.util
from pathlib import Path

spec=importlib.util.spec_from_file_location("cutover",Path("scripts/opa-mcp-bluegreen.py"))
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

sample="""server {
    location = /mcp {
        proxy_pass http://127.0.0.1:3100;
        proxy_http_version 1.1;
        proxy_buffering off;
    }
    location = /mcp-finance {
        proxy_pass http://127.0.0.1:3100;
    }
    location = /mcp-admin {
        proxy_pass http://127.0.0.1:3100;
    }
    location = /mcp-acs {
        proxy_pass http://127.0.0.1:3103/mcp;
    }
}
"""
assert mod.upstream(sample)==3100
a,b,block=mod.nginx_block(sample)
modified=sample[:a]+block.replace("proxy_pass http://127.0.0.1:3100;",
                                    "proxy_pass http://127.0.0.1:3104;")+sample[b:]
assert mod.upstream(modified)==3104
assert modified.count("proxy_pass http://127.0.0.1:3104;")==1
assert "location = /mcp-finance {\n        proxy_pass http://127.0.0.1:3100;" in modified
assert "location = /mcp-admin {\n        proxy_pass http://127.0.0.1:3100;" in modified
assert "proxy_pass http://127.0.0.1:3103/mcp;" in modified
for text in [sample+sample, sample.replace("location = /mcp","location /mcp"),sample.replace("127.0.0.1:3100","localhost:3100")]:
    try:
        mod.upstream(text)
    except RuntimeError:
        pass
    else:
        raise AssertionError("Expected fail-closed on unsupported config")
print("PASS: nginx only exact /mcp route changed; finance/admin/ACS unchanged")
