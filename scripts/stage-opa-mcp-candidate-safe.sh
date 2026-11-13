#!/usr/bin/env bash
# Creates an Opa MCP image candidate FROM THE EXACT RUNNING IMAGE.
# Only add three Opa JS files. Never change the live container, server.js,
# Compose, Keycloak or tokens. No promotion command in this script.
set -Eeuo pipefail
umask 077

SERVICE=jrconect-mcp
ROOT=/opt/jrconect-mcp
# The merge commit of approved PR #144. Never fetch mutable "main" for deployment.
REVISION=86bdf2c07c5054b2f8e58df44814b79576cf8b65
RAW=https://raw.githubusercontent.com/junaojsp/JRCONECT-ACS-MCP
die() { echo "BLOQUEADO: $*" >&2; exit 2; }

[[ "$(id -u)" == 0 ]] || die "Execute como root no ACS"
for executable in docker curl python3; do
  command -v "$executable" >/dev/null || die "Comando ausente: $executable"
done
cd "$ROOT" || die "Pasta do MCP ausente"
[[ -s secrets/opa/api-token ]] || die "Token de API Opa nao encontrado no host (nao crie outro)"
[[ -f docker-compose.yml && -f docker-compose.ixc.yml && -f docker-compose.opa.yml ]] || die "Compose base, IXC ou Opa ausente"
[[ "$(docker inspect -f '{{.State.Running}}' "$SERVICE" 2>/dev/null)" == true ]] || die "MCP original nao esta ativo"
curl --fail --silent --show-error --max-time 3 http://127.0.0.1:3100/health >/dev/null || die "MCP original nao responde /health"

COMPOSE_FILES="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.config_files"}}' "$SERVICE")"
case "$COMPOSE_FILES" in
  "$ROOT/docker-compose.yml,$ROOT/docker-compose.ixc.yml") ;;
  *) die "Compose real mudou. Ativo: revisar os nomes em docker inspect antes de continuar" ;;
esac
BASE_ID="$(docker inspect -f '{{.Image}}' "$SERVICE")"
BASE_NAME="$(docker inspect -f '{{.Config.Image}}' "$SERVICE")"
[[ -n "$BASE_ID" ]] || die "Imagem original nao identificada"

STAGE="$(mktemp -d /root/jrc-opa-candidate.XXXXXXXX)"
chmod 700 "$STAGE"
trap 'rm -f "$STAGE/proposed-compose.json"' EXIT
echo "STAGE: $STAGE"
echo "MCP ATUAL: ativo, health OK; imagem base preservada"

docker cp "$SERVICE:/app/integrations/opa-api-tools.js" "$STAGE/opa-api-tools.js" || die "Ferramentas Opa nao instaladas na imagem ativa"
docker cp "$SERVICE:/app/server.js" "$STAGE/live-server.js" || die "server.js ativo nao acessivel"
for marker in opaApiAuthorized registerOpaApiTools opaCentralMcpHandler; do
  grep -Fq "$marker" "$STAGE/live-server.js" || die "Handler OAuth Opa nao validado ($marker); revisar antes de atualizar"
done

for file in opa-queue-summary.js opa-resource-summary.js; do
  echo "Obtendo modulo aprovado: $file"
  curl --fail --silent --show-error --location --connect-timeout 10 --max-time 40 \
    "$RAW/$REVISION/mcp/integrations/$file" -o "$STAGE/$file" || die "Download falhou: $file"
done
grep -Fq 'OPA_FILA_PAGINA_REPETIDA' "$STAGE/opa-queue-summary.js" || die "Modulo fila nao corresponde ao aprovado"
grep -Fq 'OPA_SKIP_NAO_CONFIRMADO' "$STAGE/opa-resource-summary.js" || die "Modulo recursos nao corresponde ao aprovado"

# Patch only a COPY of the module currently running (not the GitHub snapshot).
# Preserve every preexisting Opa handler and all other MCP modules.
python3 - "$STAGE/opa-api-tools.js" <<'PY'
from pathlib import Path
import re
import sys
p = Path(sys.argv[1])
source = p.read_text()
markers = ("opa_fila_resumo", "opa_catalogo_recursos", "opa_resumo_recurso")
if any(marker in source for marker in markers):
    sys.exit("STOP: alguma ferramenta nova ja existe. Reconciliar manualmente para evitar duplicacao.")
pattern = r"(export\s+function\s+registerOpaApiTools\s*\(\s*mcp\s*,\s*client\s*\)\s*\{)"
if len(re.findall(pattern, source)) != 1:
    sys.exit("STOP: assinatura registerOpaApiTools diverge do esperado")
imports = """import { z as opaZ } from 'zod';
import { createOpaQueueClient as createOpaQueueClientV2 } from './opa-queue-summary.js';
import { OPA_RESOURCE_CATALOG as opaCatalogV2, createOpaResourceClient as createOpaResourceClientV2 } from './opa-resource-summary.js';
"""
tools = """
  // JRC_OPA_READONLY_V2: only registered by the preexisting OAuth Opa handler.
  mcp.registerTool('opa_fila_resumo', {
    description: 'Conta atendimentos aguardando (AG) por setor, sem dados pessoais. Apenas leitura e validacao de paginacao.',
  }, async () => {
    try {
      const r = await createOpaQueueClientV2().resumo();
      return { content:[{type:'text',text:JSON.stringify(r)}] };
    } catch(e) {
      return { isError:true,content:[{type:'text',text:String(e.message||e)}] };
    }
  });
  mcp.registerTool('opa_catalogo_recursos', {
    description: 'Catalogo dos grupos Opa, distinguindo rotas GET e recursos nao validados.',
  }, async () => ({
    content:[{type:'text',text:JSON.stringify({sistema:'Opa Suite',catalogo:opaCatalogV2})}]
  }));
  mcp.registerTool('opa_resumo_recurso', {
    description: 'Resumo agregado e paginado de recursos permitidos; sem registros pessoais. Contatos estao bloqueados por incompatibilidade.',
    inputSchema: {
      recurso:opaZ.enum(['atendimentos','mensagens','canais','templates','usuarios','contatos']),
      status:opaZ.enum(['AG','EA','PS','A','I']).optional(),
      id_rota:opaZ.string().regex(/^[a-f0-9]{24}$/i).optional(),
    }
  }, async ({recurso,status,id_rota}) => {
    try {
      const r = await createOpaResourceClientV2().resumo({recurso,status,id_rota});
      return {content:[{type:'text',text:JSON.stringify(r)}]};
    } catch(e) {
      return {isError:true,content:[{type:'text',text:String(e.message||e)}]};
    }
  });
"""
patched, count = re.subn(pattern, lambda match: match.group(1) + tools, source, count=1)
assert count == 1
p.write_text(imports + patched)
print("PATCH PREPARADO: apenas copia isolada do registro Opa; outros handlers preservados")
PY

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BASE_TAG="jrconect-mcp:opa-before-$STAMP"
CANDIDATE_TAG="jrconect-mcp:opa-candidate-$STAMP"
docker image tag "$BASE_ID" "$BASE_TAG" || die "Nao foi possivel preservar referencia da imagem original"
cat > "$STAGE/Dockerfile" <<EOF
FROM $BASE_TAG
COPY --chmod=0644 opa-api-tools.js /app/integrations/opa-api-tools.js
COPY --chmod=0644 opa-queue-summary.js /app/integrations/opa-queue-summary.js
COPY --chmod=0644 opa-resource-summary.js /app/integrations/opa-resource-summary.js
EOF

echo "Construindo imagem candidata sobre a imagem JA EM EXECUCAO, sem dados secretos"
docker build --pull=false --network none -f "$STAGE/Dockerfile" \
  -t "$CANDIDATE_TAG" "$STAGE" >/dev/null || die "Build da candidata falhou"

for file in opa-api-tools.js opa-queue-summary.js opa-resource-summary.js; do
  docker run --rm --network none --read-only --cap-drop ALL --entrypoint node \
    "$CANDIDATE_TAG" --check "/app/integrations/$file" || die "Falha no node --check: $file"
done

JS='import {registerOpaApiTools} from "/app/integrations/opa-api-tools.js";
const names=[];
const mcp={registerTool(name){names.push(name);},tool(name){names.push(name);}};
registerOpaApiTools(mcp,{});
if(new Set(names).size!==names.length) throw Error("Ferramenta duplicada");
console.log(JSON.stringify(names.sort()));'
docker run --rm --network none --read-only --cap-drop ALL --entrypoint node \
  "$BASE_TAG" --input-type=module -e "$JS" > "$STAGE/old-tools.json" || die "Nao foi possivel listar ferramentas Opa originais"
docker run --rm --network none --read-only --cap-drop ALL --entrypoint node \
  "$CANDIDATE_TAG" --input-type=module -e "$JS" > "$STAGE/candidate-tools.json" || die "Registro Opa da candidata falhou"
python3 - "$STAGE" <<'PY'
import json, pathlib, sys
d=pathlib.Path(sys.argv[1])
old=set(json.loads((d/"old-tools.json").read_text()))
new=set(json.loads((d/"candidate-tools.json").read_text()))
added={"opa_fila_resumo","opa_catalogo_recursos","opa_resumo_recurso"}
if not old.issubset(new) or not added.issubset(new):
    sys.exit("STOP: regressao no catalogo Opa; nao ativar")
if new-old != added:
    sys.exit("STOP: ferramentas Opa inesperadas; nao ativar")
print("SMOKE OK: todas as ferramentas Opa antigas preservadas e 3 novas adicionadas")
print("FERRAMENTAS ANTERIORES:", len(old), "FERRAMENTAS CANDIDATA:", len(new))
PY

# Compose preflight only; NEVER apply it here.
docker compose -f docker-compose.yml -f docker-compose.ixc.yml \
  -f docker-compose.opa.yml config --format json > "$STAGE/proposed-compose.json" \
  || die "Compose proposto nao pode ser validado"

python3 - "$STAGE/proposed-compose.json" <<'PY'
import json,sys
data=json.load(open(sys.argv[1]))
svc=data.get("services",{}).get("jrconect-mcp",{})
env=svc.get("environment",{})
if isinstance(env,list):
    env=dict(x.split("=",1) for x in env if "=" in x)
volumes=svc.get("volumes",[])
opa_mount=any(
    isinstance(v,dict) and v.get("target")=="/run/secrets/opa-api-token"
    and bool(v.get("read_only")) for v in volumes
)
enabled=str(env.get("OPA_API_MCP_ENABLED","")).lower()=="true"
url=str(env.get("OPA_API_BASE_URL","")).startswith("https://opa.jrconect.com")
allow=bool(str(env.get("OPA_API_ALLOWED_SUB","")).strip())
admin=str(env.get("OPA_API_ADMIN_ENABLED","")).lower()=="true"
print("COMPOSE PROPOSTO OPA: token read-only =",opa_mount,
      "| API habilitada =",enabled,"| URL =",url,
      "| allowlist preenchida =",allow,"| escrita desativada =",not admin)
if not (opa_mount and enabled and url and allow and not admin):
    print("PENDENTE: configuracao do Compose Opa/Keycloak. NAO ATIVAR ate validar.")
else:
    print("COMPOSE: criterios basicos validados; confirmar role Keycloak em teste autorizado.")
PY

[[ "$(docker inspect -f '{{.Image}}' "$SERVICE")" == "$BASE_ID" ]] || die "Imagem ativa mudou enquanto candidato era construido"
curl --fail --silent --show-error --max-time 3 http://127.0.0.1:3100/health >/dev/null || die "MCP de producao nao responde health"

echo "=== RESULTADO ==="
echo "CANDIDATA: $CANDIDATE_TAG"
echo "BASE_RESTAURACAO: $BASE_TAG"
echo "DIRETORIO_AUDITORIA: $STAGE"
echo "MCP PRODUCAO: preservado e respondendo /health"
echo "NAO ATIVADO. NENHUM CONTAINER FOI RECRIADO."
echo "O proximo passo e validar OAuth/Compose e executar uma mudanca separada aprovada."
