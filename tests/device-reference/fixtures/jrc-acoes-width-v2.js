/* JRC_ACOES_WIDTH_JS_V2 */
(function () {
  /* JRC_ACOES_WIDTH_JS_V2 — estica até a borda de .main-content, sem parar na margem do menu. */
  function widen() {
      var jra = document.querySelector('#acs-diagnostics-content .jra') || document.querySelector('.jra');
      if (!jra) return;
      var jb = jra.getBoundingClientRect();
      if (jb.width < 40) return;
      var edge = jra.closest('.main-content');
      if (!edge) {
          var n = jra.parentElement;
          var bestRight = jb.right;
          while (n && n !== document.body && n !== document.documentElement) {
              var r = n.getBoundingClientRect();
              if (r.width > 80 && r.right > bestRight + 30) {
                  edge = n;
                  bestRight = r.right;
              }
              n = n.parentElement;
          }
      }
      if (!edge) return;
      var nodes = [];
      var x = jra;
      while (x && x !== edge) {
          nodes.push(x);
          x = x.parentElement;
      }
      if (!nodes.length) return;
      var cs = getComputedStyle(edge);
      var right = edge.getBoundingClientRect().right - (parseFloat(cs.paddingRight) || 0) - (parseFloat(cs.borderRightWidth) || 0);
      var left = nodes[nodes.length - 1].getBoundingClientRect().left;
      var target = Math.floor(right - left - 8);
      var cap = Math.floor(window.innerWidth - left - 16);
      if (target > cap) target = cap;
      if (target < 280) return;
      var forced = jra.getAttribute('data-jra-widen') === '1';
      if (!forced && target < jb.width + 48) return;
      if (forced && Math.abs(target - jb.width) < 16) return;
      var px = target + 'px';
      var i;
      for (i = 0; i < nodes.length; i++) {
          var y = nodes[i];
          var rw = y.getBoundingClientRect().width;
          if (y.getAttribute('data-jra-widen') !== '1' && rw >= target - 12) continue;
          y.setAttribute('data-jra-widen', '1');
          y.style.setProperty('box-sizing', 'border-box', 'important');
          y.style.setProperty('width', px, 'important');
          y.style.setProperty('max-width', 'none', 'important');
          y.style.setProperty('min-width', '0', 'important');
          y.style.setProperty('flex', '1 1 ' + px, 'important');
          y.style.setProperty('grid-column', '1 / -1', 'important');
          y.style.setProperty('justify-self', 'stretch', 'important');
          y.style.setProperty('overflow', 'visible', 'important');
      }
      var grid = jra.querySelector('.jra-grid');
      if (grid) {
          grid.style.setProperty('width', '100%', 'important');
          grid.style.setProperty('max-width', 'none', 'important');
      }
  }
  var n = 0;
  var iv = setInterval(function () {
    widen();
    if (++n > 40) clearInterval(iv);
  }, 500);
  window.addEventListener('resize', widen);
  document.addEventListener('click', function () { setTimeout(widen, 30); });
  document.addEventListener('shown.bs.tab', function () { setTimeout(widen, 30); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', widen);
  else widen();
})();
