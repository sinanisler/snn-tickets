/* SNN Tickets admin. No build step, no dependencies. */
(function () {
  'use strict';
  var CFG = window.SNNT || {};
  var I = CFG.i18n || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function t(k, fallback) { return I[k] || fallback || k; }
  function ajax(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', CFG.nonce || '');
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(CFG.ajax, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
  }
  function toast(msg, bad) {
    var n = document.createElement('div');
    n.setAttribute('role', 'status');
    n.textContent = msg;
    n.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:100100;padding:10px 16px;border-radius:6px;color:#fff;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,.2);background:' + (bad ? '#b3261e' : '#1d2327');
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 2600);
  }
  window.snnToast = toast;
  function slug(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ı/g, 'i')
      .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
  }

  /* ================= generic behaviours ================= */

  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-copy]');
    if (c) {
      e.preventDefault();
      var v = c.getAttribute('data-copy');
      var done = function () { c.classList.add('snn-copied'); setTimeout(function () { c.classList.remove('snn-copied'); }, 1500); toast(t('copied', 'Copied')); };
      try { navigator.clipboard.writeText(v).then(done, function () { window.prompt(t('copyThis', 'Copy this:'), v); }); }
      catch (err) { window.prompt(t('copyThis', 'Copy this:'), v); }
      return;
    }
    var cf = e.target.closest('[data-confirm]');
    if (cf && !window.confirm(cf.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); return; }
    var op = e.target.closest('[data-open]');
    if (op) {
      e.preventDefault();
      var d = document.getElementById(op.getAttribute('data-open'));
      if (d && d.showModal) d.showModal();
      if (op.getAttribute('data-tab-to')) switchDialogTab(d, op.getAttribute('data-tab-to'));
      return;
    }
    var cl = e.target.closest('[data-close]');
    if (cl) { var dlg = cl.closest('dialog'); if (dlg) { e.preventDefault(); dlg.close(); } }
    var dt = e.target.closest('[data-dtab]');
    if (dt) { e.preventDefault(); switchDialogTab(dt.closest('dialog'), dt.getAttribute('data-dtab')); }
  });

  function switchDialogTab(dlg, name) {
    if (!dlg) return;
    $$('[data-dtab]', dlg).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-dtab') === name); });
    $$('[data-dpane]', dlg).forEach(function (p) { p.hidden = p.getAttribute('data-dpane') !== name; });
    $$('[data-dpane] input,[data-dpane] select,[data-dpane] textarea', dlg).forEach(function (el) {
      el.disabled = el.closest('[data-dpane]').hidden;
    });
    var go = $('[data-dtab-label]', dlg);
    if (go) {
      var labels = JSON.parse(go.getAttribute('data-dtab-label'));
      go.textContent = labels[name] || go.textContent;
      go.setAttribute('value', name);
    }
  }
  $$('dialog.snn-dialog').forEach(function (d) {
    var on = $('[data-dtab].on', d);
    if (on) switchDialogTab(d, on.getAttribute('data-dtab'));
    if (d.hasAttribute('data-autoopen') && d.showModal) d.showModal();
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
  });

  // Type-to-confirm: the button unlocks when the word is typed.
  $$('[data-type-confirm]').forEach(function (inp) {
    var btn = document.getElementById(inp.getAttribute('data-type-confirm'));
    inp.addEventListener('input', function () { btn.disabled = inp.value.trim().toUpperCase() !== inp.getAttribute('data-word'); });
  });

  // Selects that apply themselves.
  $$('[data-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { el.form.submit(); }); });

  // Unsaved-changes warning.
  $$('form[data-dirty]').forEach(function (f) {
    var dirty = false, sending = false;
    var mark = function () { dirty = true; var n = $('[data-dirty-note]', f); if (n) n.hidden = false; };
    f.addEventListener('input', mark);
    f.addEventListener('change', mark);
    f.addEventListener('snn-change', mark);
    f.addEventListener('submit', function () { sending = true; });
    window.addEventListener('beforeunload', function (e) { if (dirty && !sending) { e.preventDefault(); e.returnValue = ''; } });
  });

  // Bulk selection bar on the People table.
  $$('[data-bulk-form]').forEach(function (f) {
    var bar = $('[data-bulk-bar]', f), n = $('[data-bulk-n]', f), all = $('[data-bulk-all]', f);
    var boxes = function () { return $$('input[name="ids[]"]', f); };
    var update = function () {
      var c = boxes().filter(function (b) { return b.checked; }).length;
      bar.hidden = !c;
      n.textContent = (t('selected', '%d selected')).replace('%d', c);
    };
    if (all) all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); update(); });
    f.addEventListener('change', function (e) { if (e.target.name === 'ids[]') update(); });
    f.addEventListener('click', function (e) {
      var b = e.target.closest('[data-bulk-do]');
      if (!b) return;
      if (b.value === 'clear') { e.preventDefault(); boxes().forEach(function (x) { x.checked = false; }); if (all) all.checked = false; update(); return; }
      var c = boxes().filter(function (x) { return x.checked; }).length;
      var ask = b.getAttribute('data-ask');
      if (ask && !window.confirm(ask.replace('%d', c))) e.preventDefault();
    });
    update();
  });

  /* ================= email editor ================= */

  function EmailEditor(root) {
    var cfg = JSON.parse(root.getAttribute('data-cfg'));
    var rte = $('[data-rte]', root), html = $('[data-html]', root), body = $('[data-body]', root);
    var subject = $('[data-subject]', root), prev = $('[data-preview]', root);
    var htmlMode = false, timer = null, device = 'desktop';
    var TAGS = {};
    (cfg.tags || []).forEach(function (x) { TAGS[x[0]] = {label: x[1], block: !!x[3]}; });
    (cfg.answers || []).forEach(function (x) { TAGS[x[0]] = {label: x[1], block: false}; });

    function chip(tag) {
      var d = TAGS[tag];
      var label = d ? d.label : tag.replace(/[{}]/g, '');
      return (d && d.block)
        ? '<div class="snn-tag blk" contenteditable="false" data-tag="' + esc(tag) + '">' + esc(label) + '</div>'
        : '<span class="snn-tag" contenteditable="false" data-tag="' + esc(tag) + '">' + esc(label) + '</span>';
    }
    function toChips(src) {
      src = String(src || '');
      // A block chip wrapped in its own paragraph stands alone.
      src = src.replace(/<p[^>]*>\s*(\{(?:ticket_card|wallet_buttons|qr_block|review_button)\})\s*<\/p>/gi, '$1');
      return src.replace(/\{[a-z_]+(?::[a-z0-9_]+)?\}/gi, function (m) { return TAGS[m] || /^\{field:/.test(m) ? chip(m) : m; });
    }
    function fromChips() {
      var c = rte.cloneNode(true);
      $$('.snn-tag', c).forEach(function (el) { el.replaceWith(document.createTextNode(el.getAttribute('data-tag'))); });
      return c.innerHTML.replace(/&nbsp;/g, ' ').trim();
    }
    function value() { return htmlMode ? html.value : fromChips(); }
    function sync() { body.value = value(); root.dispatchEvent(new CustomEvent('snn-change', {bubbles: true})); schedule(); }

    rte.innerHTML = toChips(body.value);
    rte.addEventListener('input', sync);
    if (html) html.addEventListener('input', sync);
    if (subject) subject.addEventListener('input', schedule);

    // Keep the caret inside the editor when a button is pressed.
    root.addEventListener('mousedown', function (e) { if (e.target.closest('[data-ins],[data-cmd]')) e.preventDefault(); });

    function placeCaret() {
      var sel = window.getSelection();
      if (sel.rangeCount && rte.contains(sel.anchorNode)) return;
      rte.focus();
      var r = document.createRange(); r.selectNodeContents(rte); r.collapse(false);
      sel.removeAllRanges(); sel.addRange(r);
    }

    root.addEventListener('click', function (e) {
      var ins = e.target.closest('[data-ins]');
      if (ins) {
        e.preventDefault();
        var tag = ins.getAttribute('data-ins');
        if (htmlMode) {
          var s = html.selectionStart || 0;
          html.value = html.value.slice(0, s) + tag + html.value.slice(html.selectionEnd || s);
        } else {
          placeCaret();
          document.execCommand('insertHTML', false, chip(tag) + (TAGS[tag] && TAGS[tag].block ? '<p><br></p>' : '&nbsp;'));
        }
        sync(); return;
      }
      var cmd = e.target.closest('[data-cmd]');
      if (cmd) {
        e.preventDefault();
        var c = cmd.getAttribute('data-cmd');
        if (c === 'html') {
          htmlMode = !htmlMode;
          if (htmlMode) { html.value = fromChips(); } else { rte.innerHTML = toChips(html.value); }
          html.hidden = !htmlMode; rte.hidden = htmlMode;
          cmd.textContent = htmlMode ? t('visual', 'Visual') : 'HTML';
          sync(); return;
        }
        if (htmlMode) return;
        placeCaret();
        if (c === 'createLink') {
          var url = window.prompt(t('linkUrl', 'Link address'), 'https://');
          if (url) document.execCommand('createLink', false, url);
        } else if (c === 'h2' || c === 'p') {
          document.execCommand('formatBlock', false, c);
        } else {
          document.execCommand(c, false, null);
        }
        sync(); return;
      }
      var dev = e.target.closest('[data-dev]');
      if (dev) { e.preventDefault(); device = dev.getAttribute('data-dev'); render(); return; }
      if (e.target.closest('[data-reset]')) {
        e.preventDefault();
        if (!window.confirm(t('resetAsk', 'Replace the text with the default wording?'))) return;
        load(cfg.defaults.subject, cfg.defaults.body);
        return;
      }
      if (e.target.closest('[data-save-template]')) {
        e.preventDefault();
        var name = window.prompt(t('tplName', 'Name this template'), subject ? subject.value : '');
        if (!name) return;
        ajax('snn_save_template', {name: name, role: cfg.role, subject: subject ? subject.value : '', body: value()}).then(function (j) {
          toast(j && j.success ? j.data.message : ((j && j.data && j.data.message) || t('error')), !(j && j.success));
          if (j && j.success) {
            var sel = $('[data-start-template]', root);
            if (sel && !$('option[value="' + CSS.escape(name) + '"]', sel)) {
              var o = document.createElement('option'); o.value = name; o.textContent = name; sel.appendChild(o);
            }
            cfg.templates = cfg.templates || {};
            cfg.templates[name] = {subject: subject ? subject.value : '', body: value()};
          }
        });
        return;
      }
      if (e.target.closest('[data-test]')) {
        e.preventDefault();
        var to = $('[data-test-to]', root), res = $('[data-test-res]', root);
        res.textContent = t('sending', 'Sending…'); res.style.color = '';
        ajax('snn_email_test', {role: cfg.role, subject: subject ? subject.value : '', body: value(), list_id: cfg.list || 0, to: to ? to.value : ''}).then(function (j) {
          res.textContent = (j && j.data && j.data.message) || t('error');
          res.style.color = j && j.success ? '#0a7d32' : '#b3261e';
        }).catch(function () { res.textContent = t('error'); res.style.color = '#b3261e'; });
      }
    });

    var start = $('[data-start-template]', root);
    if (start) start.addEventListener('change', function () {
      var tpl = (cfg.templates || {})[start.value];
      if (!tpl) return;
      if (value().trim() !== '' && !window.confirm(t('replaceAsk', 'Replace the current text with this template?'))) { start.value = ''; return; }
      load(tpl.subject, tpl.body);
      start.value = '';
    });

    function load(s, b) {
      if (subject) subject.value = s || '';
      if (htmlMode) html.value = b || ''; else rte.innerHTML = toChips(b || '');
      sync();
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(render, 500); }
    function render() {
      if (!prev) return;
      prev.classList.add('loading');
      ajax('snn_email_preview', {role: cfg.role, subject: subject ? subject.value : '', body: value(), list_id: cfg.list || 0}).then(function (j) {
        prev.classList.remove('loading');
        prev.classList.toggle('mobile', device === 'mobile');
        if (!j || !j.success) { prev.innerHTML = '<p>' + esc((j && j.data && j.data.message) || t('error')) + '</p>'; return; }
        prev.innerHTML = '<div class="dev"><button type="button" class="button button-small' + (device === 'desktop' ? ' button-primary' : '') + '" data-dev="desktop">' + esc(t('desktop', 'Desktop')) + '</button>'
          + '<button type="button" class="button button-small' + (device === 'mobile' ? ' button-primary' : '') + '" data-dev="mobile">' + esc(t('phone', 'Phone')) + '</button></div>'
          + '<p class="subj">' + esc(t('subject', 'Subject')) + ': <b>' + esc(j.data.subject) + '</b></p><div class="frame"><iframe title="Email preview" scrolling="no"></iframe></div>'
          + (j.data.attachments ? '<p class="att">📎 ' + esc(j.data.attachments.toUpperCase().split(',').join(', ')) + '</p>' : '');
        var f = $('iframe', prev);
        f.onload = function () { try { f.style.height = f.contentDocument.documentElement.scrollHeight + 'px'; } catch (err) {} };
        f.srcdoc = j.data.html;
      }).catch(function () { prev.classList.remove('loading'); });
    }

    var form = root.closest('form');
    if (form) form.addEventListener('submit', function () { body.value = value(); });
    render();
  }
  $$('[data-email-editor]').forEach(function (el) { new EmailEditor(el); });

  /* ================= questions, rules and preview ================= */

  function Builder(root) {
    var cfg = JSON.parse(root.getAttribute('data-cfg'));
    var fields = cfg.fields || [], settings = cfg.settings || {};
    var TYPES = cfg.types, OPS = cfg.ops, VALUELESS = cfg.valueless || [];
    var NEEDS = ['select', 'radio', 'checkbox'];
    var listEl = $('[data-questions]', root), addEl = $('[data-add-question]', root);
    var rulesEl = $('[data-rules]', root), pvEl = $('[data-form-preview]', root);
    var form = root.closest('form');
    var open = -1;
    settings.rules = settings.rules || [];

    function changed() { if (form) form.dispatchEvent(new CustomEvent('snn-change')); renderPreview(); }
    function uniqueKey(base, skip) {
      var k = slug(base) || 'field', n = 2, b = k;
      while (fields.some(function (f, i) { return i !== skip && f.key === k; })) k = b + '_' + n++;
      return k;
    }
    function locked(f) { return f.map_to === 'name' || f.map_to === 'email'; }

    function renderQuestions() {
      if (!listEl) return;
      listEl.innerHTML = fields.map(function (f, i) {
        var head = '<div class="snn-qh" data-q="' + i + '">'
          + '<span class="ord"><button type="button" data-up="' + i + '" aria-label="' + esc(t('moveUp', 'Move up')) + '">▲</button><button type="button" data-down="' + i + '" aria-label="' + esc(t('moveDown', 'Move down')) + '">▼</button></span>'
          + '<span class="lbl">' + esc(f.label || t('untitled', 'Untitled question')) + (f.required ? ' <span class="snn-req">*</span>' : '') + '</span>'
          + (locked(f) ? '<span class="snn-chip info">🔒 ' + esc(f.map_to === 'name' ? t('ticketName', 'Name on the ticket') : t('ticketGoes', 'Ticket goes here')) + '</span>' : '<span class="t">' + esc(TYPES[f.type] || f.type) + '</span>')
          + '</div>';
        if (i !== open) return '<div class="snn-qc">' + head + '</div>';
        var b = '<div class="snn-qb"><label class="snn-field"><span>' + esc(t('question', 'Question')) + '</span><input type="text" data-k="label" value="' + esc(f.label) + '"></label>';
        if (locked(f)) {
          b += '<p class="snn-muted snn-small" style="margin:0">' + esc(f.map_to === 'name' ? t('nameHelp') : t('emailHelp')) + '</p>'
            + '<label class="snn-field"><span>' + esc(t('hint', 'Hint inside the box')) + ' <small>' + esc(t('optional', '(optional)')) + '</small></span><input type="text" data-k="placeholder" value="' + esc(f.placeholder || '') + '"></label>';
        } else {
          b += '<div class="snn-grid2"><label class="snn-field"><span>' + esc(t('answerType', 'Answer type')) + '</span><select data-k="type">'
            + Object.keys(TYPES).map(function (k) { return '<option value="' + k + '"' + (k === f.type ? ' selected' : '') + '>' + esc(TYPES[k]) + '</option>'; }).join('') + '</select></label>';
          if (f.type === 'hidden') {
            b += '<label class="snn-field"><span>' + esc(t('value', 'Value')) + '</span><input type="text" data-k="default" value="' + esc(f['default'] || '') + '"></label></div>'
              + '<p class="snn-muted snn-small" style="margin:0">' + esc(t('hiddenHelp')) + '</p>';
          } else {
            b += '<label class="snn-field"><span>' + esc(t('hint', 'Hint inside the box')) + ' <small>' + esc(t('optional', '(optional)')) + '</small></span><input type="text" data-k="placeholder" value="' + esc(f.placeholder || '') + '"></label></div>';
          }
          if (NEEDS.indexOf(f.type) !== -1) {
            b += '<label class="snn-field"><span>' + esc(t('choices', 'Choices')) + ' <small>' + esc(t('onePerLine', '(one per line)')) + '</small></span><textarea rows="4" data-k="options">' + esc((f.options || []).join('\n')) + '</textarea></label>';
          }
          if (f.type !== 'hidden') {
            b += '<details class="snn-more"><summary>' + esc(t('moreOptions', 'More options')) + '</summary><div>'
              + '<label class="snn-field"><span>' + esc(t('prefilled', 'Pre-filled answer')) + '</span><input type="text" data-k="default" value="' + esc(f['default'] || '') + '"></label>'
              + '<p class="snn-muted snn-small" style="margin:0">' + esc(t('chipHelp', 'In emails this answer is the chip')) + ' <span class="snn-tag">' + esc(f.label) + '</span></p></div></details>';
          }
          b += '<div class="snn-row">' + (f.type !== 'hidden' ? '<label class="snn-switch"><input type="checkbox" data-k="required"' + (f.required ? ' checked' : '') + '><i></i>' + esc(t('required', 'Required')) + '</label>' : '')
            + '<span class="snn-spacer"></span><button type="button" class="button button-small" data-dup="' + i + '">' + esc(t('duplicate', 'Duplicate')) + '</button>'
            + '<button type="button" class="button button-small button-link-delete" data-del="' + i + '">' + esc(t('remove', 'Remove')) + '</button></div>';
        }
        return '<div class="snn-qc open" data-i="' + i + '">' + head + b + '</div></div>';
      }).join('');
      renderRules();
      renderPreview();
    }

    if (listEl) {
      listEl.addEventListener('click', function (e) {
        var mv = e.target.closest('[data-up],[data-down]');
        if (mv) {
          e.stopPropagation();
          var up = mv.hasAttribute('data-up'), i = +mv.getAttribute(up ? 'data-up' : 'data-down'), j = up ? i - 1 : i + 1;
          if (j < 0 || j >= fields.length) return;
          var tmp = fields[i]; fields[i] = fields[j]; fields[j] = tmp;
          open = open === i ? j : (open === j ? i : open);
          renderQuestions(); changed(); return;
        }
        var del = e.target.closest('[data-del]');
        if (del) {
          var k = +del.getAttribute('data-del');
          if (!window.confirm(t('removeAsk', 'Remove this question? Answers already given are kept.'))) return;
          fields.splice(k, 1); open = -1; renderQuestions(); changed(); return;
        }
        var dup = e.target.closest('[data-dup]');
        if (dup) {
          var d = +dup.getAttribute('data-dup'), copy = JSON.parse(JSON.stringify(fields[d]));
          copy.label += ' ' + t('copySuffix', '(copy)'); copy.key = uniqueKey(copy.label, -1); copy.map_to = ''; delete copy._saved;
          fields.splice(d + 1, 0, copy); open = d + 1; renderQuestions(); changed(); return;
        }
        var h = e.target.closest('[data-q]');
        if (h) { var n = +h.getAttribute('data-q'); open = open === n ? -1 : n; renderQuestions(); }
      });
      var onEdit = function (e) {
        var el = e.target.closest('[data-k]'); if (!el) return;
        var card = el.closest('[data-i]'); if (!card) return;
        var f = fields[+card.getAttribute('data-i')], k = el.getAttribute('data-k');
        if (k === 'required') f.required = el.checked ? 1 : 0;
        else if (k === 'options') f.options = el.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
        else f[k] = el.value;
        if (k === 'label') {
          if (!f._saved && !locked(f)) f.key = uniqueKey(f.label, +card.getAttribute('data-i'));
          $('.lbl', card).innerHTML = esc(f.label || t('untitled', 'Untitled question')) + (f.required ? ' <span class="snn-req">*</span>' : '');
        }
        if (k === 'type' && e.type === 'change') {
          if (NEEDS.indexOf(f.type) !== -1 && !(f.options || []).length) f.options = [t('opt1', 'Option 1'), t('opt2', 'Option 2')];
          renderQuestions();
        }
        if (k === 'required') renderQuestions();
        changed();
      };
      listEl.addEventListener('input', onEdit);
      listEl.addEventListener('change', onEdit);
    }

    if (addEl) {
      addEl.innerHTML = '<span class="snn-muted snn-small">+ ' + esc(t('add', 'Add')) + ':</span>' + Object.keys(TYPES).map(function (k) {
        return '<button type="button" data-add="' + k + '">' + esc(TYPES[k]) + '</button>';
      }).join('');
      addEl.addEventListener('click', function (e) {
        var b = e.target.closest('[data-add]'); if (!b) return;
        var type = b.getAttribute('data-add'), label = (cfg.newLabels || {})[type] || TYPES[type];
        fields.push({key: uniqueKey(label, -1), type: type, label: label, placeholder: '', required: 0,
          options: NEEDS.indexOf(type) !== -1 ? [t('opt1', 'Option 1'), t('opt2', 'Option 2')] : [], map_to: '', 'default': type === 'hidden' ? 'website' : ''});
        open = fields.length - 1; renderQuestions(); changed();
        var inp = $('.snn-qc.open input', listEl); if (inp) { inp.focus(); inp.select(); }
      });
    }

    /* ---- rules ---- */
    function renderRules() {
      if (!rulesEl) return;
      var fopts = function (sel) { return fields.map(function (f) { return '<option value="' + esc(f.key) + '"' + (f.key === sel ? ' selected' : '') + '>' + esc(f.label || f.key) + '</option>'; }).join(''); };
      var used = {};
      settings.rules.forEach(function (r) { used[r.field] = true; });
      rulesEl.innerHTML = '<div class="snn-rules">'
        + '<div class="snn-row"><b>' + esc(t('approveWhen', 'Give a ticket right away when')) + '</b><select data-rs="rules_match"><option value="all"' + (settings.rules_match !== 'any' ? ' selected' : '') + '>' + esc(t('all', 'all')) + '</option><option value="any"' + (settings.rules_match === 'any' ? ' selected' : '') + '>' + esc(t('any', 'any')) + '</option></select><b>' + esc(t('ofThese', 'of these are true:')) + '</b></div>'
        + (settings.rules.length ? settings.rules.map(function (r, i) {
            var noValue = VALUELESS.indexOf(r.op) !== -1;
            return '<div class="snn-rl"><select data-r="field" data-i="' + i + '">' + fopts(r.field) + '</select>'
              + '<select data-r="op" data-i="' + i + '">' + Object.keys(OPS).map(function (o) { return '<option value="' + o + '"' + (o === r.op ? ' selected' : '') + '>' + esc(OPS[o]) + '</option>'; }).join('') + '</select>'
              + (noValue ? '<span class="snn-muted snn-small">' + esc(t('noValue', 'no value needed')) + '</span>' : '<input type="text" data-r="value" data-i="' + i + '" value="' + esc(r.value) + '" placeholder="' + esc(r.op === 'in_list' ? t('listPh', 'a, b, c') : t('valuePh', 'value')) + '">')
              + '<button type="button" class="button button-small" data-rdel="' + i + '" aria-label="' + esc(t('remove', 'Remove')) + '">✕</button></div>';
          }).join('') : '<p class="snn-muted" style="margin:0">' + esc(t('noRules', 'No conditions yet.')) + '</p>')
        + '<div><button type="button" class="button button-small" data-radd>+ ' + esc(t('addCondition', 'Add condition')) + '</button></div>'
        + '<div class="snn-row"><b>' + esc(t('everyoneElse', 'Everyone else:')) + '</b><select data-rs="rules_fallback"><option value="manual"' + (settings.rules_fallback !== 'reject' ? ' selected' : '') + '>' + esc(t('waitsForMe', 'waits for my approval')) + '</option><option value="reject"' + (settings.rules_fallback === 'reject' ? ' selected' : '') + '>' + esc(t('declinedAuto', 'is declined automatically')) + '</option></select></div>'
        + (settings.rules.length ? '<div class="snn-tester"><b>' + esc(t('tryIt', 'Try it:')) + '</b>' + Object.keys(used).map(function (k) {
            var f = fields.filter(function (x) { return x.key === k; })[0];
            return '<input type="text" data-try="' + esc(k) + '" placeholder="' + esc(f ? f.label : k) + '" aria-label="' + esc(f ? f.label : k) + '">';
          }).join('') + '<span data-try-res></span></div>' : '')
        + '</div>';
      test();
    }
    function match(r, data) {
      var raw = data[r.field] || '', v = String(raw).trim().toLowerCase(), target = String(r.value || '').trim().toLowerCase();
      switch (r.op) {
        case 'equals': return v === target;
        case 'not_equals': return v !== target;
        case 'contains': return target !== '' && v.indexOf(target) !== -1;
        case 'starts_with': return target !== '' && v.indexOf(target) === 0;
        case 'ends_with': return target !== '' && v.slice(-target.length) === target;
        case 'in_list': return target.split(',').map(function (s) { return s.trim(); }).filter(Boolean).indexOf(v) !== -1;
        case 'email_domain': return v.indexOf('@') !== -1 && v.split('@').pop() === target;
        case 'not_empty': return v !== '';
        case 'is_empty': return v === '';
        case 'checked': return !!raw;
        case 'not_checked': return !raw;
      }
      return false;
    }
    function test() {
      var res = rulesEl && $('[data-try-res]', rulesEl); if (!res) return;
      var data = {}, any = false;
      $$('[data-try]', rulesEl).forEach(function (i) { data[i.getAttribute('data-try')] = i.value; if (i.value) any = true; });
      if (!any) { res.innerHTML = '<span class="snn-muted snn-small">' + esc(t('tryHelp', 'type sample answers to see what happens')) + '</span>'; return; }
      var hits = settings.rules.map(function (r) { return match(r, data); });
      var ok = settings.rules_match === 'any' ? hits.some(Boolean) : hits.every(Boolean);
      res.innerHTML = ok ? '<span class="snn-chip ok">→ ' + esc(t('getsTicket', 'gets a ticket right away')) + '</span>'
        : (settings.rules_fallback === 'reject' ? '<span class="snn-chip bad">→ ' + esc(t('isDeclined', 'is declined')) + '</span>' : '<span class="snn-chip warn">→ ' + esc(t('waits', 'waits for you')) + '</span>');
    }
    if (rulesEl) {
      rulesEl.addEventListener('change', function (e) {
        var el = e.target;
        if (el.hasAttribute('data-rs')) { settings[el.getAttribute('data-rs')] = el.value; test(); changed(); return; }
        if (el.hasAttribute('data-r')) {
          settings.rules[+el.getAttribute('data-i')][el.getAttribute('data-r')] = el.value;
          if (el.getAttribute('data-r') !== 'value') renderRules();
          changed();
        }
      });
      rulesEl.addEventListener('input', function (e) {
        var el = e.target;
        if (el.getAttribute('data-r') === 'value') { settings.rules[+el.getAttribute('data-i')].value = el.value; test(); }
        if (el.hasAttribute('data-try')) test();
      });
      rulesEl.addEventListener('click', function (e) {
        var d = e.target.closest('[data-rdel]');
        if (d) { settings.rules.splice(+d.getAttribute('data-rdel'), 1); renderRules(); changed(); }
        if (e.target.closest('[data-radd]')) {
          var em = fields.filter(function (f) { return f.map_to === 'email'; })[0] || fields[0];
          settings.rules.push({field: em ? em.key : '', op: em && em.map_to === 'email' ? 'ends_with' : 'equals', value: ''});
          renderRules(); changed();
        }
      });
    }

    /* ---- settings controls outside the builder ---- */
    var scope = form || root;
    $$('[data-setting]', scope).forEach(function (el) {
      var k = el.getAttribute('data-setting');
      var apply = function () {
        if (el.type === 'checkbox') settings[k] = el.checked ? 1 : 0;
        else if (el.type === 'radio') { if (el.checked) settings[k] = el.value; }
        else settings[k] = el.value;
        toggleRules(); renderPreview();
      };
      el.addEventListener('change', apply);
      el.addEventListener('input', apply);
    });
    function toggleRules() {
      var box = $('[data-rules-box]', scope);
      if (box) box.hidden = settings.approval_mode !== 'conditional';
    }
    toggleRules();

    /* ---- preview ---- */
    function renderPreview() {
      if (!pvEl) return;
      var head = cfg.previewHead ? cfg.previewHead() : (root.getAttribute('data-head') || '');
      var color = settings.accent_color || '#2271b1';
      var h = head;
      if (Number(settings.show_remaining) && Number(settings.max_tickets) > 0) h += '<p style="margin:0;font-weight:700;color:' + esc(color) + '">' + esc((t('spotsLeft', '%d spots left')).replace('%d', settings.max_tickets)) + '</p>';
      fields.forEach(function (f) {
        if (f.type === 'hidden') return;
        var star = f.required ? ' <span class="snn-req">*</span>' : '', lab = esc(f.label || t('question', 'Question'));
        if (f.type === 'consent') { h += '<div class="snn-pf"><span class="ol"><i></i>' + lab + star + '</span></div>'; return; }
        h += '<div class="snn-pf"><span class="lb">' + lab + star + '</span>';
        if (f.type === 'radio' || f.type === 'checkbox') h += (f.options || []).map(function (o) { return '<span class="ol"><i class="' + (f.type === 'radio' ? 'r' : '') + '"></i>' + esc(o) + '</span>'; }).join('');
        else if (f.type === 'select') h += '<div class="box">' + esc(f.placeholder || t('choose', 'Choose…')) + ' ▾</div>';
        else h += '<div class="box' + (f.type === 'textarea' ? ' tall' : '') + '">' + esc(f.placeholder || f['default'] || '') + '</div>';
        h += '</div>';
      });
      h += '<div class="snn-pbtn" style="background:' + esc(color) + '">' + esc(settings.submit_label || t('getTicket', 'Get my ticket')) + '</div>';
      pvEl.innerHTML = h;
    }
    root.snnRefresh = renderPreview;

    if (form) form.addEventListener('submit', function (e) {
      if (!fields.some(function (f) { return f.map_to === 'email'; })) {
        if (!window.confirm(t('noEmail'))) { e.preventDefault(); return; }
      }
      var fj = $('[name="fields_json"]', form), sj = $('[name="settings_json"]', form);
      if (fj) fj.value = JSON.stringify(fields.map(function (f) { var c = Object.assign({}, f); delete c._saved; return c; }));
      if (sj) sj.value = JSON.stringify(settings);
    });

    fields.forEach(function (f) { if (cfg.saved) f._saved = true; });
    renderQuestions();
    return root;
  }
  $$('[data-builder]').forEach(function (el) { new Builder(el); });

  /* ================= new event guide ================= */

  var wiz = $('[data-wizard]');
  if (wiz) {
    var step = 1, max = $$('[data-wstep]', wiz).length, builder = $('[data-builder]', wiz);
    var head = function () {
      var name = ($('#w-name') || {}).value || '';
      var d = ($('#w-date') || {}).value, st = ($('#w-start') || {}).value, venue = ($('#w-venue') || {}).value || '';
      var when = '';
      if (d) { try { when = new Date(d + 'T' + (st || '00:00')).toLocaleDateString(undefined, {weekday: 'short', day: 'numeric', month: 'short'}) + (st ? ' · ' + st : ''); } catch (e) {} }
      return '<p class="snn-muted snn-small" style="margin:0;font-weight:700;text-transform:uppercase;letter-spacing:.05em">' + esc([when, venue].filter(Boolean).join(' · ')) + '</p><h2>' + esc(name || t('yourEvent', 'Your event')) + '</h2>';
    };
    if (builder) builder.setAttribute('data-head', head());
    var show = function () {
      $$('[data-wstep]', wiz).forEach(function (s) { s.hidden = +s.getAttribute('data-wstep') !== step; });
      $$('.snn-wsteps li', wiz).forEach(function (li, i) { li.className = i + 1 < step ? 'done' : (i + 1 === step ? 'on' : ''); });
      $('[data-wback]', wiz).style.visibility = step === 1 ? 'hidden' : 'visible';
      $('[data-whint]', wiz).textContent = (t('stepOf', 'Step %1$d of %2$d')).replace('%1$d', step).replace('%2$d', max);
      $('[data-wnext]', wiz).hidden = step === max;
      $('[data-wfinish]', wiz).hidden = step !== max;
      var live = $('[data-live-url]', wiz), nm = $('#w-name');
      if (live && nm) live.textContent = live.getAttribute('data-base') + (slug(nm.value).replace(/_/g, '-') || 'event') + '/';
      if (builder) { builder.setAttribute('data-head', head()); if (builder.snnRefresh) builder.snnRefresh(); }
      window.scrollTo(0, 0);
    };
    $('[data-wnext]', wiz).addEventListener('click', function () {
      var nm = $('#w-name');
      if (step === 1 && nm && !nm.value.trim()) { nm.focus(); toast(t('nameFirst', 'Give the event a name first.'), true); return; }
      step++; show();
    });
    $('[data-wback]', wiz).addEventListener('click', function () { if (step > 1) { step--; show(); } });
    wiz.addEventListener('input', function (e) { if (builder && e.target.closest('[data-wstep="1"]')) { builder.setAttribute('data-head', head()); if (builder.snnRefresh) builder.snnRefresh(); } });
    var lim = $('#w-limit'), cap = $('#w-cap-wrap');
    if (lim && cap) { var tl = function () { cap.hidden = !lim.checked; }; lim.addEventListener('change', tl); tl(); }
    show();
  }

  /* ================= look: live ticket from the colour pickers ================= */

  $$('[data-look-editor]').forEach(function (box) {
    var presets = JSON.parse(box.getAttribute('data-presets'));
    var mini = $('[data-mini]', box);
    function colors() { var o = {}; $$('input[type=color][data-key]', box).forEach(function (i) { o[i.getAttribute('data-key')] = i.value; }); return o; }
    function draw() {
      var c = colors();
      mini.innerHTML = '<div style="background:' + c.bg + ';padding:14px;border-radius:6px"><div style="max-width:380px;background:' + c.card + ';border-radius:8px;overflow:hidden;border:1px solid rgba(0,0,0,.08)">'
        + '<div style="background:' + c.header_bg + ';color:' + c.header_text + ';padding:10px 14px"><div style="font-size:10px;letter-spacing:.12em;text-transform:uppercase;opacity:.85">Admit one</div><b style="font-size:15px">Summer Meetup</b></div>'
        + '<div style="padding:12px 14px;color:' + c.text + ';font-size:13px"><div style="font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:' + c.muted + '">Name</div><b>Ayşe Demir</b></div></div>'
        + '<p style="margin:10px 0 0"><span style="display:inline-block;padding:7px 12px;border-radius:6px;font-weight:600;background:' + c.accent + ';color:' + c.accent_text + '">Download PDF</span></p></div>';
    }
    box.addEventListener('input', draw);
    $$('input[name="design[preset]"]', box).forEach(function (r) {
      r.addEventListener('change', function () {
        var p = presets[r.value] || {};
        $$('input[type=color][data-key]', box).forEach(function (i) { if (p[i.getAttribute('data-key')]) i.value = p[i.getAttribute('data-key')]; });
        draw();
      });
    });
    var reset = $('[data-colors-reset]', box);
    if (reset) reset.addEventListener('click', function (e) {
      e.preventDefault();
      var r = $('input[name="design[preset]"]:checked', box), p = presets[r ? r.value : ''] || {};
      $$('input[type=color][data-key]', box).forEach(function (i) { if (p[i.getAttribute('data-key')]) i.value = p[i.getAttribute('data-key')]; });
      draw();
    });
    draw();
  });

  // Logo picker from the media library.
  $$('[data-media]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      if (!window.wp || !wp.media) return;
      var frame = wp.media({title: b.getAttribute('data-media'), library: {type: 'image'}, multiple: false});
      frame.on('select', function () {
        var a = frame.state().get('selection').first().toJSON();
        var inp = document.getElementById(b.getAttribute('data-target'));
        inp.value = a.url;
        var img = document.getElementById(b.getAttribute('data-target') + '-img');
        if (img) { img.src = a.url; img.hidden = false; }
        inp.dispatchEvent(new Event('input', {bubbles: true}));
      });
      frame.open();
    });
  });
})();
