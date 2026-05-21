/**
 * xp_notify.js — XP toast notification system for HakDel
 *
 * Usage:
 *   showXPNotification(data)
 *
 * data shape:
 *   { messages: string[], total_xp_awarded: number,
 *     leveled_up: bool, new_level: number,
 *     current_xp: number, xp_progress: number }
 *
 * Also updates the topbar XP display live.
 */

(function () {
  'use strict';

  // ── Toast container ─────────────────────────────────────────────────────────
  function getContainer() {
    var c = document.getElementById('xp-toast-container');
    if (!c) {
      c = document.createElement('div');
      c.id = 'xp-toast-container';
      c.style.cssText = [
        'position:fixed', 'top:68px', 'right:18px', 'z-index:9999',
        'display:flex', 'flex-direction:column', 'gap:8px',
        'pointer-events:none', 'width:280px'
      ].join(';');
      document.body.appendChild(c);
    }
    return c;
  }

  // ── Single toast ─────────────────────────────────────────────────────────────
  function createToast(text, opts) {
    opts = opts || {};
    var isLevelUp  = opts.levelUp  || false;
    var isStreak   = opts.streak   || false;
    var duration   = opts.duration || (isLevelUp ? 4000 : 2500);

    var toast = document.createElement('div');
    toast.style.cssText = [
      'background:' + (isLevelUp ? '#1a1a2e' : 'var(--bg2,#1a1a1a)'),
      'border:1px solid ' + (isLevelUp ? 'rgba(255,209,102,0.4)' : 'rgba(0,212,170,0.25)'),
      'border-radius:8px',
      'padding:' + (isLevelUp ? '14px 16px' : '10px 14px'),
      'font-family:var(--mono,monospace)',
      'font-size:' + (isLevelUp ? '13px' : '12px'),
      'color:' + (isLevelUp ? '#ffd166' : 'var(--accent,#00d4aa)'),
      'box-shadow:0 4px 20px rgba(0,0,0,0.4)',
      'pointer-events:auto',
      'transform:translateX(320px)',
      'transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s',
      'opacity:0',
      'line-height:1.4'
    ].join(';');

    if (isLevelUp) {
      toast.innerHTML =
        '<div style="font-size:18px;margin-bottom:4px">&#9651;</div>' +
        '<div style="font-weight:700;font-size:14px">' + escHtml(text) + '</div>';
    } else {
      toast.textContent = text;
    }

    return { el: toast, duration: duration };
  }

  // ── Show a single toast in the stack ─────────────────────────────────────────
  function showToast(text, opts) {
    var container = getContainer();
    var t = createToast(text, opts);
    container.appendChild(t.el);

    // Animate in
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        t.el.style.transform = 'translateX(0)';
        t.el.style.opacity   = '1';
      });
    });

    // Animate out
    setTimeout(function () {
      t.el.style.transform = 'translateX(320px)';
      t.el.style.opacity   = '0';
      setTimeout(function () {
        if (t.el.parentNode) t.el.parentNode.removeChild(t.el);
      }, 350);
    }, t.duration);
  }

  // ── Update topbar XP display ──────────────────────────────────────────────────
  function updateTopbarXP(data) {
    if (!data) return;

    var labelEl = document.getElementById('topbar-xp-label');
    var fillEl  = document.getElementById('topbar-xp-fill');
    var xpNumEl = document.getElementById('topbar-xp-num');

    if (data.new_level !== undefined && labelEl) {
      // Animate counter
      var target = data.current_xp || 0;
      var label  = 'LVL ' + data.new_level + ' \u00B7 ' + target + ' XP';
      if (xpNumEl) {
        animateCount(xpNumEl, parseInt(xpNumEl.textContent) || 0, target, 600);
      } else if (labelEl) {
        labelEl.textContent = label;
      }
    }

    if (data.xp_progress !== undefined && fillEl) {
      setTimeout(function () {
        fillEl.style.transition = 'width 0.8s ease';
        fillEl.style.width = Math.min(100, data.xp_progress) + '%';
      }, 300);
    }
  }

  function animateCount(el, from, to, ms) {
    var start = null;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / ms, 1);
      el.textContent = Math.round(from + (to - from) * p);
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  // ── Main public API ───────────────────────────────────────────────────────────
  window.showXPNotification = function (data) {
    if (!data) return;

    var msgs    = data.messages || [];
    var levelUp = data.leveled_up || false;
    var delay   = 0;

    msgs.forEach(function (msg) {
      var isLU     = msg.indexOf('Level') !== -1 && msg.indexOf('!') !== -1;
      var isStreak = msg.indexOf('streak') !== -1;
      ;(function (m, d, lu, sk) {
        setTimeout(function () {
          showToast(m, { levelUp: lu, streak: sk });
        }, d);
      })(msg, delay, isLU, isStreak);
      delay += isLU ? 600 : 400;
    });

    // Update topbar after toasts start
    setTimeout(function () {
      updateTopbarXP(data);
    }, 200);
  };

  // ── Auto-show pending notification injected by PHP ────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    if (window.PENDING_XP_NOTIFY) {
      setTimeout(function () {
        window.showXPNotification(window.PENDING_XP_NOTIFY);
      }, 500); // slight delay so page is settled
    }
  });

  // ── Helpers ───────────────────────────────────────────────────────────────────
  function escHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

})();
