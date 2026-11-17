import importlib.util
from pathlib import Path
import subprocess
import unittest

spec = importlib.util.spec_from_file_location('patch', Path(__file__).resolve().parents[1] / 'scripts/deploy-acs-dashboard-consistency.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class Tests(unittest.TestCase):
    def test_singleflight_and_failure_retry(self):
        source = '''var calls=0; var release;
async function loadFinalDiscoveryData() { calls++; return new Promise((resolve,reject)=>{release={resolve,reject};}); }
'''
        patched = m.dashboard_patch(source)
        self.assertEqual(m.dashboard_patch(patched), patched)
        check = '''
(async()=>{
 const a=loadFinalDiscoveryData(), b=loadFinalDiscoveryData();
 if(a!==b) throw Error('not deduplicated');
 await Promise.resolve(); if(calls!==1) throw Error('duplicate call');
 release.reject(Error('expected')); await a.catch(()=>{});
 const c=loadFinalDiscoveryData(); await Promise.resolve();
 if(calls!==2) throw Error('failure locked loader');
 release.resolve(123); if(await c!==123) throw Error('result lost');
 const d=loadFinalDiscoveryData(); await Promise.resolve();
 if(calls!==3) throw Error('success locked loader');
 release.resolve(456); await d;
})().catch(e=>{console.error(e);process.exitCode=1});
'''
        subprocess.run(['node', '-e', 'var window=globalThis;\n' + patched + check], check=True)

    def test_unknown_loader_refused(self):
        with self.assertRaises(ValueError): m.dashboard_patch('function other() {}')

    def test_threshold_and_labels(self):
        text = 'if (rx <= -28) state.critical++;\nconst label="RX \\u2264 -28 dBm";\nroot.renderReferenceDashboard = render;'
        patched = m.chart_patch(text)
        self.assertIn('rx <= -27', patched)
        self.assertNotIn('-28', patched)
        self.assertEqual(m.chart_patch(patched), patched)

    def test_ambiguous_threshold_refused(self):
        with self.assertRaises(ValueError):
            m.chart_patch('root.renderReferenceDashboard = render; rx <= -28; rx <= -28;')

if __name__ == '__main__': unittest.main()
