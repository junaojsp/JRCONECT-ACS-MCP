// Fixed-scope Opa Suite API inventory and read-only aggregate queries.
// Do not return raw records: they may contain CPF, phone numbers and messages.
import {readFileSync} from 'node:fs';
import {request} from 'node:https';

const PAGE_SIZE = 100;
const MAX_PAGES = 40;
const MAX_BYTES = 5 * 1024 * 1024;
const OBJECT_ID = /^[a-f0-9]{24}$/i;

export const OPA_RESOURCE_CATALOG = Object.freeze([
  {recurso:'atendimentos',rotas_get_documentadas:['/api/v1/atendimento'],consulta_resumida:true,observacao:'AG, EA e PS; finalizados por periodo usam opa_relatorio_atendimentos'},
  {recurso:'mensagens',rotas_get_documentadas:['/api/v1/atendimento/mensagem'],consulta_resumida:true,observacao:'exige id_rota; nao devolve texto'},
  {recurso:'canais',rotas_get_documentadas:['/api/v1/canal-comunicacao/'],consulta_resumida:true,observacao:'por status, sem credenciais'},
  {recurso:'templates',rotas_get_documentadas:['/api/v1/template'],consulta_resumida:true,observacao:'contagem, sem corpo dos templates'},
  {recurso:'clientes',rotas_get_documentadas:[],consulta_resumida:false,observacao:'grupo documentado; confirmar rota e permissao de leitura antes de ativar'},
  {recurso:'usuarios',rotas_get_documentadas:['/api/v1/usuario/'],consulta_resumida:true,observacao:'por status; sem nomes ou contatos'},
  {recurso:'contatos',rotas_get_documentadas:['/api/v1/contato/'],consulta_resumida:true,observacao:'contagem; pode exceder limite de paginacao'},
  {recurso:'etiquetas',rotas_get_documentadas:[],consulta_resumida:false,observacao:'grupo documentado; confirmar endpoint GET'},
  {recurso:'notificacoes',rotas_get_documentadas:[],consulta_resumida:false,observacao:'grupo documentado; confirmar se existe GET'},
  {recurso:'departamentos',rotas_get_documentadas:[],consulta_resumida:false,observacao:'grupo documentado; confirmar endpoint GET'},
  {recurso:'motivos',rotas_get_documentadas:[],consulta_resumida:false,observacao:'grupo documentado; confirmar endpoint GET'},
  {recurso:'periodos',rotas_get_documentadas:[],consulta_resumida:false,observacao:'grupo documentado; confirmar endpoint GET'},
]);

const ROUTES = Object.freeze({
  atendimentos:'/api/v1/atendimento',
  mensagens:'/api/v1/atendimento/mensagem',
  canais:'/api/v1/canal-comunicacao/',
  templates:'/api/v1/template',
  usuarios:'/api/v1/usuario/',
  contatos:'/api/v1/contato/',
});

export function querySpecification({recurso,status,id_rota} = {}) {
  if (!Object.hasOwn(ROUTES,recurso)) throw Error('Recurso ainda nao habilitado para consulta');
  if (id_rota !== undefined && recurso !== 'mensagens') throw Error('Filtro id_rota nao permitido neste recurso');
  if (status !== undefined && !['atendimentos','canais','usuarios'].includes(recurso)) {
    throw Error('Filtro status nao permitido neste recurso');
  }
  let filter={};
  if (recurso==='atendimentos') {
    if (!['AG','EA','PS'].includes(status)) throw Error('Informe status AG, EA ou PS; F usa o relatorio por periodo');
    filter={status};
  } else if (recurso==='mensagens') {
    if (typeof id_rota!=='string' || !OBJECT_ID.test(id_rota)) throw Error('id_rota invalido');
    filter={id_rota};
  } else if (recurso==='canais' || recurso==='usuarios') {
    if (status!==undefined && !['A','I'].includes(status)) throw Error('Status A ou I esperado');
    filter={status:status||'A'};
  }
  // For templates and contacts the vendor documents empty filters as valid for listing.
  // A 400 from the installation is a validation failure, never an excuse to probe other routes.
  return {url:ROUTES[recurso], filter};
}

function safeCode(value) {
  return typeof value==='string' && /^[A-Za-z0-9_-]{1,64}$/.test(value) ? value : 'nao_identificado';
}
function groupFor(recurso,row) {
  switch(recurso) {
    case 'atendimentos': {
      const setor=row.setor && typeof row.setor==='object' ? row.setor._id : row.setor;
      return safeCode(setor);
    }
    case 'canais': return safeCode(row.canal);
    case 'usuarios': return safeCode(row.tipo);
    case 'templates': return safeCode(row.tipo_mensagem);
    case 'contatos': return typeof row.lead==='boolean' ? (row.lead?'lead':'nao_lead') : 'nao_identificado';
    default: return 'total';
  }
}

export async function summarizeOpaResource(fetchPage, query, {pageSize=PAGE_SIZE,maxPages=MAX_PAGES}={}) {
  const spec=querySpecification(query);
  if(typeof fetchPage!=='function' ||
     !Number.isInteger(pageSize) || pageSize<1 || pageSize>200 ||
     !Number.isInteger(maxPages) || maxPages<1 || maxPages>100) {
    throw Error('Parametros de consulta invalidos');
  }
  let total=0;
  const groups=new Map();
  for(let p=0;p<maxPages;p++){
    const rows=await fetchPage({path:spec.url,filter:spec.filter,limit:pageSize,skip:p*pageSize});
    if(!Array.isArray(rows) || rows.length>pageSize) throw Error('Resposta Opa fora do contrato de listagem');
    for(const row of rows){
      if(!row || typeof row!=='object' || Array.isArray(row)) throw Error('Registro Opa com estrutura invalida');
      if(spec.filter.status && row.status!==spec.filter.status) {
        throw Error('Filtro status nao aplicado; consulta cancelada');
      }
      if(spec.filter.id_rota && row.id_rota!==undefined && row.id_rota!==spec.filter.id_rota) {
        throw Error('Filtro id_rota nao aplicado; consulta cancelada');
      }
      total++;
      const key=groupFor(query.recurso,row);
      groups.set(key,(groups.get(key)||0)+1);
    }
    if(rows.length<pageSize){
      return {
        sistema:'Opa Suite',recurso:query.recurso,criterio:spec.filter,
        total,por_grupo:[...groups].map(([codigo,quantidade])=>({codigo,quantidade})).sort((a,b)=>b.quantidade-a.quantidade),
        consultado_em:new Date().toISOString(),
        auditoria:{paginas:p+1,paginacao_completa:true,limite_registros:pageSize*maxPages},
        observacao:'Dados agregados, sem dados pessoais. Leitura paginada nao e snapshot transacional.',
      };
    }
  }
  throw Error('Limite de paginas atingido; total parcial nao disponibilizado');
}

export function createOpaResourceClient({
  baseUrl=process.env.OPA_API_BASE_URL,
  tokenFile='/run/secrets/opa-api-token',
}={}){
  if(!baseUrl)throw Error('OPA_API_BASE_URL ausente');
  const base=new URL(baseUrl);
  if(base.protocol!=='https:' || base.username || base.password ||
     base.pathname!=='/' || base.search || base.hash)throw Error('OPA_API_BASE_URL invalida');

  function fetchPage({path,filter,limit,skip}){
    // path is only supplied by the internal fixed allowlist, not the caller.
    if(!Object.values(ROUTES).includes(path))throw Error('Endpoint fora da allowlist');
    const token=readFileSync(tokenFile,'utf8').trim();
    if(!token || /\s/.test(token))throw Error('Token Opa invalido');
    const body=JSON.stringify({filter,options:{limit,skip}});
    const url=new URL(path,base);
    return new Promise((resolve,reject)=>{
      const req=request(url,{method:'GET',timeout:15000,headers:{
        Authorization:'Bearer '+token,Accept:'application/json',
        'Content-Type':'application/json','Content-Length':Buffer.byteLength(body)
      }},res=>{
        let bytes=0;const chunks=[];
        res.on('data',chunk=>{
          bytes+=chunk.length;
          if(bytes>MAX_BYTES)return req.destroy(Error('Resposta Opa muito grande'));
          chunks.push(chunk);
        });
        res.on('end',()=>{
          if(res.statusCode!==200)return reject(Error('Opa API HTTP '+res.statusCode));
          try{
            const body=JSON.parse(Buffer.concat(chunks).toString('utf8'));
            if(body.status!=='success' || !Array.isArray(body.data)) throw Error('Formato de resposta Opa invalido');
            resolve(body.data);
          }catch(e){reject(e);}
        });
        res.on('error',reject);
      });
      req.on('timeout',()=>req.destroy(Error('Timeout Opa')));
      req.on('error',reject);
      req.end(body);
    });
  }
  return {resumo:(args)=>summarizeOpaResource(fetchPage,args)};
}
