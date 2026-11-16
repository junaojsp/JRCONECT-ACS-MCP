/* ACS Optical Report v5: scope to the report's signal table only.
 * Handles asynchronous table-row rendering and inline black backgrounds.
 * Presentation only: no API requests, changes to signal classes or CPE actions.
 */
(() => {
  'use strict';
  const colors = {head:'#164366', odd:'#102c49', even:'#143450'};
  const mark = 'data-jrc-optical-blue-v5';
  const norm = value => (value || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  function isOpticalTable(table) {
    const header = norm(Array.from(table.querySelectorAll('thead th,thead td'))
      .map(x => x.textContent).join(' '));
    if (header.includes('sinal') && (header.includes('onu') || header.includes('rx') || header.includes('equipamento'))) return true;
    // Some live reports render their table head as the first row.
    const first = norm(Array.from(table.querySelectorAll('tr:first-child th'))
      .map(x => x.textContent).join(' '));
    return first.includes('sinal') && (first.includes('rx') || first.includes('equipamento') || first.includes('onu'));
  }
  function set(node, prop, val) {
    if (node.style.getPropertyValue(prop) !== val || node.style.getPropertyPriority(prop) !== 'important') {
      node.style.setProperty(prop, val, 'important');
    }
  }
  function paint(table) {
    if (!isOpticalTable(table)) return;
    table.setAttribute(mark, 'true');
    set(table, 'background-color', colors.odd);
    set(table, 'color', '#e7f4ff');
    const head = table.tHead;
    if (head) {
      for (const row of head.rows) {
        set(row, 'background-color', colors.head);
        for (const cell of row.cells) {
          set(cell, 'background-color', colors.head);
          set(cell, 'background-image', 'none');
          set(cell, 'box-shadow', 'none');
        }
      }
    }
    const rows = table.tBodies.length ?
      Array.from(table.tBodies).flatMap(section => Array.from(section.rows)) :
      Array.from(table.rows).slice(head ? head.rows.length : 1);
    rows.forEach((row, index) => {
      const c = index % 2 ? colors.even : colors.odd;
      set(row, 'background-color', c);
      set(row, 'background-image', 'none');
      set(row, 'box-shadow', 'none');
      for (const cell of row.cells) {
        set(cell, 'background-color', c);
        set(cell, 'background-image', 'none');
        set(cell, 'box-shadow', 'none');
        set(cell, '--bs-table-bg', c);
        set(cell, '--bs-table-accent-bg', 'transparent');
        set(cell, 'color', '#e7f4ff');
      }
    });
  }
  let scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      document.querySelectorAll('table').forEach(paint);
    });
  }
  function start() {
    schedule();
    // Only DOM structural changes: style writes above cannot retrigger observer.
    const observer = new MutationObserver(schedule);
    observer.observe(document.body, {subtree:true, childList:true});
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, {once:true});
  } else start();
})();
