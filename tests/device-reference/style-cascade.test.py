"""Offline CSS cascade checks on actual class names, including modules loading after the theme.
Requires tinycss2 and cssselect2; does not claim to test browser geometry or rendering.
"""
from pathlib import Path
import re
import unittest
from xml.etree import ElementTree as ET
import tinycss2
import cssselect2

REPO=Path(__file__).resolve().parents[2]
HTML='''<html class="jrc-approved-acs jrc-ipam-page jrc-alertas-page"><body class="jrc-mcp-standalone"><div id="mainContent" class="main-content"><div class="content-wrapper">
<div class="jrc-mcp-page"><div id="mcp" class="jrc-mcp-hub"><section id="mcp-section" class="jrc-mcp-hub__section"/></div></div>
<div id="mcp-config"><section id="config-card" class="card"/></div>
<section id="ip" class="jrc-ip-card"/><button id="alert" class="jrc-al-stat"/>
<section id="report" class="card"><table class="table"><tbody><tr><td/></tr><tr><td id="report-cell"/></tr></tbody></table></section>
<div id="jrc-device-reference"><ul id="deviceTabs"/><div class="fb-source-line"/><div id="deviceTabContent"><div id="acs-diagnostics-content"><div class="jra"><div id="actions-grid" class="jra-grid"><aside class="jra-pal"/><section class="jra-main"><input id="action-input" class="jra-input"/><button id="safe-run" class="jra-run"/><button id="danger-run" class="jra-run is-danger"/></section></div></div></div></div></div>
</div></div></body></html>'''


def cascade(width):
    matcher=cssselect2.Matcher();order=0
    # Load legacy last intentionally: the approved rules must still win.
    sources=[(REPO/'assets/css/jrc-approved-acs.css').read_text(),(REPO/'tests/device-reference/fixtures/legacy-live-rules.css').read_text()]
    def add(rules):
        nonlocal order
        for rule in rules:
            assert rule.type!='error',rule
            if rule.type=='at-rule' and rule.lower_at_keyword=='media':
                media=tinycss2.serialize(rule.prelude)
                maxes=re.findall(r'max-width:\s*(\d+)px',media)
                if maxes and width>min(map(int,maxes)):continue
                add(tinycss2.parse_rule_list(rule.content,skip_comments=True,skip_whitespace=True))
            elif rule.type=='qualified-rule':
                declarations=[]
                for d in tinycss2.parse_declaration_list(rule.content,skip_comments=True,skip_whitespace=True):
                    assert d.type!='error',d
                    if d.type!='declaration':continue
                    value=tinycss2.serialize(d.value).strip()
                    declarations.append((d.lower_name,value,d.important))
                    if d.lower_name=='background':
                        declarations.append(('background-color',value if not value.startswith(('linear-gradient','conic-gradient')) else 'transparent',d.important))
                order+=1
                for selector in cssselect2.compile_selector_list(tinycss2.serialize(rule.prelude)):
                    matcher.add_selector(selector,(order,declarations))
    for source in sources:add(tinycss2.parse_stylesheet(source,skip_comments=True,skip_whitespace=True))
    result={}
    for el in cssselect2.ElementWrapper.from_html_root(ET.fromstring(HTML)).iter_subtree():
        winners={}
        for specificity,_,pseudo,(rule_order,declarations) in matcher.match(el):
            if pseudo:continue
            for prop,value,important in declarations:
                score=(important,specificity,rule_order)
                if prop not in winners or score>=winners[prop][0]:winners[prop]=(score,value)
        result[el.etree_element.get('id') or el.etree_element.tag]={p:v[1] for p,v in winners.items()}
    return result


class ActualModulesCascade(unittest.TestCase):
    @classmethod
    def setUpClass(cls):cls.desktop=cascade(1363)
    def test_modules_override_their_old_css_even_loaded_later(self):
        for name in ['mcp','mcp-section','config-card','ip','alert','report']:
            self.assertEqual(self.desktop[name]['background-color'],'var(--jrc-panel)',name)
        self.assertEqual(self.desktop['body']['background-color'],'var(--jrc-bg)')
        self.assertEqual(self.desktop['report-cell']['background-color'],'transparent')
    def test_action_components_and_danger_states(self):
        self.assertEqual(self.desktop['action-input']['background-color'],'#172131')
        self.assertEqual(self.desktop['safe-run']['background-color'],'#267bcc')
        self.assertEqual(self.desktop['danger-run']['background-color'],'#81374b')
        self.assertEqual(self.desktop['deviceTabContent']['grid-column'],'2')
        self.assertEqual(self.desktop['acs-diagnostics-content']['display'],'block')
        self.assertEqual(self.desktop['actions-grid']['grid-template-columns'],'210px minmax(0,1fr)')
    def test_mobile_uses_separate_rows(self):
        mobile=cascade(390)
        self.assertEqual(mobile['deviceTabContent']['grid-column'],'1')
        self.assertEqual(mobile['deviceTabContent']['grid-row'],'3')
        self.assertEqual(mobile['actions-grid']['grid-template-columns'],'minmax(0,1fr)')


if __name__=='__main__':unittest.main()
