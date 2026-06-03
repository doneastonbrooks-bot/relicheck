/* qual-coder.js — second coder's dedicated coding workspace controller */
'use strict';
(function () {

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function api(path, opts) {
    opts = opts || {};
    return fetch(path, Object.assign({ headers: { 'Content-Type': 'application/json' } }, opts))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.message || d.error || 'API error');
        return d;
      });
  }

  var state = {
    codes:        [],
    segments:     [],
    total:        0,
    searchQuery:  '',
    uncodedOnly:  false,
  };

  // ── Stats update ─────────────────────────────────────────────────────────
  function updateStats() {
    var total     = state.total;
    var coded     = state.segments.filter(function (s) { return s.code_count > 0; }).length;
    var remaining = total - coded;
    var el = function (id) { return document.getElementById(id); };
    if (el('stat-total'))     el('stat-total').textContent     = total;
    if (el('stat-coded'))     el('stat-coded').textContent     = coded;
    if (el('stat-remaining')) el('stat-remaining').textContent = remaining;

    var banner = document.getElementById('done-banner');
    if (banner) banner.style.display = (total > 0 && remaining === 0) ? 'flex' : 'none';
  }

  // ── Load ──────────────────────────────────────────────────────────────────
  function load() {
    var qs = '/api/qual/get-segments.php?project_id=' + BOOT.projectId + '&limit=500'
      + (state.uncodedOnly ? '&uncoded=1' : '');
    return api(qs).then(function (d) {
      state.segments = d.segments || [];
      state.total    = d.total    || 0;
      renderList();
      updateStats();
    });
  }

  // ── Render list ───────────────────────────────────────────────────────────
  function renderList() {
    var list = document.getElementById('seg-list');
    if (!list) return;

    var filtered = state.searchQuery
      ? state.segments.filter(function (s) {
          return s.raw_text.toLowerCase().indexOf(state.searchQuery.toLowerCase()) !== -1;
        })
      : state.segments;

    var coded   = filtered.filter(function (s) { return s.code_count > 0; }).length;
    var uncoded = filtered.filter(function (s) { return s.code_count === 0; }).length;
    var countEl = document.getElementById('seg-counts');
    if (countEl) {
      countEl.textContent = filtered.length + ' segments · ' + coded + ' coded · ' + uncoded + ' uncoded';
    }

    if (!filtered.length) {
      list.innerHTML = '<div class="placeholder">No segments ' + (state.uncodedOnly ? 'left to code.' : 'found.') + '</div>';
      return;
    }
    list.innerHTML = filtered.map(renderSegCard).join('');
  }

  function renderSegCard(seg) {
    var meta = seg.metadata_json || {};
    var metaItems = Object.keys(meta).slice(0, 4).map(function (k) {
      return '<span class="seg-pid">' + esc(k) + ': ' + esc(String(meta[k])) + '</span>';
    }).join('');
    var pid = seg.participant_id ? '<span class="seg-pid">ID: ' + esc(seg.participant_id) + '</span>' : '';
    var q   = seg.question_ref   ? '<span class="seg-q">'  + esc(seg.question_ref)   + '</span>' : '';

    var chips = (seg.codes || []).map(function (c) {
      return '<span class="chip">' + esc(c.name)
        + '<button class="chip-x" data-seg="' + seg.id + '" data-code="' + c.id + '">&times;</button></span>';
    }).join('');

    var pickerItems = state.codes.length
      ? state.codes.map(function (c) {
          return '<button class="picker-item" data-seg="' + seg.id + '" data-code="' + c.id + '" data-name="' + esc(c.name) + '">'
            + esc(c.name) + '</button>';
        }).join('')
      : '<div class="picker-empty">No codes in codebook yet. Ask the project lead to add codes.</div>';

    var flag = seg.code_count === 0 ? '<span class="flag uncoded">Uncoded</span>' : '';

    return '<div class="seg-card ' + (seg.code_count > 0 ? 'coded' : '') + '" id="seg-' + seg.id + '">'
      + '<div class="seg-meta">' + pid + q + metaItems + '</div>'
      + '<div class="seg-text">' + esc(seg.raw_text) + '</div>'
      + '<div class="code-chips" id="chips-' + seg.id + '">' + chips + '</div>'
      + '<div class="seg-actions">'
      + '<div class="picker-wrap" id="pw-' + seg.id + '">'
      + '<button class="add-code-btn" data-seg="' + seg.id + '">+ Add code</button>'
      + '<div class="picker" id="picker-' + seg.id + '" style="display:none">' + pickerItems + '</div>'
      + '</div>'
      + flag + '</div>'
      + '</div>';
  }

  // ── Event delegation ──────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {

    // Load codes then segments
    api('/api/qual/get-codes.php?project_id=' + BOOT.projectId)
      .then(function (d) {
        state.codes = d.codes || [];
        return load();
      })
      .catch(function (e) {
        var list = document.getElementById('seg-list');
        if (list) list.innerHTML = '<div class="notice err">Could not load: ' + esc(e.message) + '</div>';
      });

    var searchEl = document.getElementById('seg-search');
    if (searchEl) {
      searchEl.addEventListener('input', function () {
        state.searchQuery = this.value;
        renderList();
      });
    }

    document.getElementById('filter-all').addEventListener('click', function () {
      state.uncodedOnly = false;
      this.classList.add('active');
      document.getElementById('filter-uncoded').classList.remove('active');
      load();
    });
    document.getElementById('filter-uncoded').addEventListener('click', function () {
      state.uncodedOnly = true;
      this.classList.add('active');
      document.getElementById('filter-all').classList.remove('active');
      load();
    });

    var segList = document.getElementById('seg-list');
    if (segList) {
      segList.addEventListener('click', function (e) {
        var addBtn    = e.target.closest('.add-code-btn');
        var item      = e.target.closest('.picker-item');
        var removeBtn = e.target.closest('.chip-x');

        if (addBtn) {
          var sid = addBtn.dataset.seg;
          // Close other pickers
          document.querySelectorAll('.picker').forEach(function (p) {
            if (p.id !== 'picker-' + sid) p.style.display = 'none';
          });
          var picker = document.getElementById('picker-' + sid);
          if (picker) picker.style.display = picker.style.display === 'none' ? 'block' : 'none';
          // Close on outside click
          setTimeout(function () {
            document.addEventListener('click', function close(ev) {
              if (!ev.target.closest('#pw-' + sid)) {
                var p = document.getElementById('picker-' + sid);
                if (p) p.style.display = 'none';
                document.removeEventListener('click', close);
              }
            });
          }, 10);
        }

        if (item) {
          var sid   = item.dataset.seg;
          var cid   = item.dataset.code;
          var cname = item.dataset.name;
          var picker = document.getElementById('picker-' + sid);
          if (picker) picker.style.display = 'none';
          applyCode(+sid, +cid, cname);
        }

        if (removeBtn) {
          var sid = +removeBtn.dataset.seg;
          var cid = +removeBtn.dataset.code;
          api('/api/qual/remove-code.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid, code_id: cid }),
          }).then(function () {
            var chip = removeBtn.closest('.chip');
            if (chip) chip.remove();
            var seg = state.segments.find(function (s) { return s.id === sid; });
            if (seg) {
              seg.codes = seg.codes.filter(function (c) { return c.id !== cid; });
              seg.code_count = seg.codes.length;
              var card = document.getElementById('seg-' + sid);
              if (card && seg.code_count === 0) card.classList.remove('coded');
            }
            updateStats();
          }).catch(function (ex) { alert('Error: ' + ex.message); });
        }
      });
    }
  });

  function applyCode(sid, cid, cname) {
    api('/api/qual/apply-code.php', {
      method: 'POST',
      body: JSON.stringify({ project_id: BOOT.projectId, segment_id: sid, code_id: cid }),
    }).then(function () {
      var seg = state.segments.find(function (s) { return s.id === sid; });
      if (seg && !seg.codes.find(function (c) { return c.id === cid; })) {
        seg.codes.push({ id: cid, name: cname });
        seg.code_count = seg.codes.length;
      }
      var chipsEl = document.getElementById('chips-' + sid);
      if (chipsEl && seg) {
        chipsEl.innerHTML = seg.codes.map(function (c) {
          return '<span class="chip">' + esc(c.name)
            + '<button class="chip-x" data-seg="' + sid + '" data-code="' + c.id + '">&times;</button></span>';
        }).join('');
      }
      var card = document.getElementById('seg-' + sid);
      if (card && seg && seg.code_count > 0) card.classList.add('coded');
      updateStats();
    }).catch(function (e) { alert('Could not apply code: ' + e.message); });
  }

})();
