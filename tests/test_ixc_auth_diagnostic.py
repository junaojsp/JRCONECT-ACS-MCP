import importlib.util
from pathlib import Path
import subprocess
import unittest

spec = importlib.util.spec_from_file_location('diagnostic', Path(__file__).resolve().parents[1] / 'scripts/stage-ixc-auth-diagnostic.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class DiagnosticTests(unittest.TestCase):
    def test_rejects_unknown_and_repeated_patch(self):
        with self.assertRaises(ValueError):
            module.patch('unknown server')
        source = 'const connections = new ConnectionRegistry();\n' + module.ANCHOR + '\ntrue) {}'
        patched = module.patch(source)
        with self.assertRaises(ValueError):
            module.patch(patched)
        restored = patched.replace(module.DECLARATION, '', 1).replace(module.BLOCK, '', 1)
        self.assertEqual(restored, source)

    def test_all_boolean_combinations_privacy_dedup_and_failures(self):
        harness = r'''
const assert = require('node:assert/strict');
const logs = [];
const run = new Function('payload','process','ixcWebserviceAuthorized','console','jrcIxcDiagnosticSeen', BLOCK);
const privateSubject = '12345678-1234-1234-1234-123456789abc';
const seen = new Set();
for (let mask = 0; mask < 128; mask++) {
 const flags = Array.from({length:7}, (_, n) => Boolean(mask & (1 << n)));
 const [enabled, client, subject, admin, read, authorized, shield] = flags;
 const payload = {sub:privateSubject, azp:client?'chatgpt-mcp':'private-client', email:'private-email', realm_access:{roles:[...(admin?['ixc-mcp-admin']:[]), ...(read?['ixc-mcp-read']:[])]}};
 const env = {IXC_WS_MCP_ENABLED:enabled?'true':'false', IXC_WS_ALLOWED_SUB:subject?` other, ${privateSubject} `:'other', SHIELD_MCP_ALLOWED_SUB:shield?privateSubject:''};
 const logger = {info:value => logs.push(value)};
 run(payload, {env}, () => authorized, logger, seen);
 run(payload, {env}, () => authorized, logger, seen);
 assert.deepEqual(Object.values(JSON.parse(logs.at(-1).split(' ').slice(1).join(' '))), flags);
}
assert.equal(logs.length, 128);
for (const secret of [privateSubject,'private-email','private-client']) assert(!logs.join('').includes(secret));
assert.doesNotThrow(() => run({}, {env:{}}, () => {throw Error('secret');}, console, seen));
assert.doesNotThrow(() => run({}, {env:{}}, () => false, {info:()=>{throw Error('logger');}}, new Set()));
'''
        import json
        result = subprocess.run(['node', '-e', 'const BLOCK = ' + json.dumps(module.BLOCK) + ';\n' + harness], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)

if __name__ == '__main__':
    unittest.main()
