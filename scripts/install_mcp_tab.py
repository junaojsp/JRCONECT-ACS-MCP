#!/usr/bin/env python3
"""Adiciona uma aba MCP na configuracao local, preservando abas nao versionadas."""
from pathlib import Path
from datetime import datetime
import argparse
import os
import re
import shutil
import subprocess
import tempfile

TAB = """            <li class="nav-item" role="presentation">
                <button class="nav-link" id="mcp-tab" data-bs-toggle="tab"
                    data-bs-target="#mcp-config" type="button" role="tab"
                    aria-controls="mcp-config" aria-selected="false">
                    <i class="bi bi-diagram-3"></i> Configuracao MCP
                </button>
            </li>
"""
PANE = """            <?php include __DIR__ . '/views/partials/mcp_configuration_tab.php'; ?>
"""

def matching_close(source, start):
    depth = 0
    for m in re.finditer(r"</?div\b[^>]*>", source[start:], re.I):
        depth += -1 if m.group().startswith("</") else 1
        if depth == 0:
            return start + m.start()
    raise ValueError("Fechamento da tab-content nao encontrado")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--target", default="/var/www/gacs/configuration.php")
    args = parser.parse_args()
    path = Path(args.target)
    source = path.read_text(encoding="utf-8")
    if 'id="mcp-tab"' in source or 'id="mcp-config"' in source:
        print("Aba MCP ja existe: sem alteracoes")
        return
    nav = source.find('id="configTabs"')
    content = source.find('id="configTabsContent"')
    if nav < 0 or content < 0:
        raise ValueError("Estrutura da pagina diferente do esperado")
    end_ul = source.rfind("</ul>", nav, content)
    start_content = source.rfind("<div", 0, content)
    if end_ul < 0 or start_content < 0:
        raise ValueError("Menu/conteudo nao localizado")
    close_content = matching_close(source, start_content)
    if close_content <= content:
        raise ValueError("Fechamento de conteudo invalido")
    updated = source[:end_ul] + TAB + source[end_ul:close_content] + PANE + source[close_content:]
    backup = path.with_name(path.name + ".bak-mcp-" + datetime.now().strftime("%Y%m%d-%H%M%S"))
    shutil.copy2(path, backup)
    fd, temp = tempfile.mkstemp(prefix=".mcp-config-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(updated)
        shutil.copymode(path, temp)
        os.replace(temp, path)
        result = subprocess.run(["php", "-l", str(path)], text=True, capture_output=True)
        if result.returncode:
            shutil.copy2(backup, path)
            raise RuntimeError("PHP invalido; restaurado backup: " + result.stderr)
        print(result.stdout.strip())
        print("Aba MCP instalada. Backup:", backup)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)

if __name__ == "__main__":
    main()
