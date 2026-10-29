/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 *
 * Shared front-end helpers — chip-row builders, table kit (Tabulator), CSV in/out.
 * Loaded on every page that renders a table. Nothing here is page-specific.
 */

// ==================== CHIP-ROW BUILDERS ====================
// [label chip] on the left, value pinned right.
function rsEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
}
function rsChip(cls, icon, label) {
    return '<span class="chip ' + cls + '"><i class="fas ' + icon + '"></i>' + label + '</span>';
}
function rsCrow(chipHtml, value, valCls) {
    return '<div class="chip-row">' + chipHtml + '<span class="val ' + (valCls || '') + '">' + value + '</span></div>';
}
function rsStack(rows) { return '<div class="cell-stack">' + rows.filter(Boolean).join('') + '</div>'; }
function rsBox(s) { return '<span class="val-box">' + rsEsc(s) + '</span>'; }
function rsTitle(s) { return '<div class="cell-title">' + rsEsc(s) + '</div>'; }
var RS_DASH = '<span class="val-muted">&mdash;</span>';

// ==================== TABLE COLUMN DEFS ====================
// chips for display, the real value for sort, a text blob for search — miss this and
// sorting sorts by markup while the search box quietly stops matching anything
function rsCol(title, build, sortKey, blob, opts) {
    var c = {
        data: null, title: title, name: sortKey,
        render: function (d, t, r) {
            return t === 'display' ? build(r) : (t === 'filter' ? (blob ? blob(r) : '') : r[sortKey]);
        }
    };
    return Object.assign(c, opts || {});
}

// plain column — sorts/filters on the raw field, `show` only dresses the display
function rsFlat(title, key, show, opts) {
    return Object.assign({
        data: key, name: key, title: title, defaultContent: '',
        render: function (d, t, r) { return t === 'display' ? (show ? show(r) : rsEsc(d)) : d; }
    }, opts || {});
}

// layout switch: more than RS_CHIP_MIN data columns -> chip groups, otherwise plain columns.
// lead/tail (photo, actions) never count. sort + filter by column name so both layouts work
var RS_CHIP_MIN = 10;
function rsLayout(sel, o) {
    var chip = o.flat.length > RS_CHIP_MIN,
        cols = (o.lead || []).concat(chip ? o.groups : o.flat, o.tail || []);
    $(sel).toggleClass('chip-table', chip);
    return {
        chip: chip, columns: cols,
        at: function (name) { return Math.max(0, cols.findIndex(function (c) { return c.name === name; })); }
    };
}

// header search box -> table search (debounced, one draw per pause)
function rsDtSearch(dt, input) {
    var t;
    input = typeof input === 'string' ? document.getElementById(input) : input;
    if (!input || input._rsDt) return;
    input._rsDt = true;
    input.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () { rsTbSearch(dt(), input.value); }, 200);
    });
}

// date range quick picks for an [from, to] pair of date inputs -> fires change on both.
// btn (optional) = the segment that was pressed; it stays lit until the dates are edited by hand
function rsDateRange(fromId, toId, days, btn) {
    var pad = function (n) { return (n < 10 ? '0' : '') + n; },
        ymd = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); },
        to = new Date(), from = new Date(), seg = btn && btn.parentNode;
    from.setDate(from.getDate() - (days || 0));
    rsDateRange.busy = true;
    [[fromId, ymd(from)], [toId, ymd(to)]].forEach(function (p) {
        var el = document.getElementById(p[0]);
        el.value = p[1];
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    rsDateRange.busy = false;
    if (seg) [].forEach.call(seg.children, function (b) { b.classList.toggle('is-on', b === btn); });
    [fromId, toId].forEach(function (id) {
        var el = document.getElementById(id);
        if (el._rsSeg) return;
        el._rsSeg = true;
        el.addEventListener('change', function () { // hand edit / clear -> no segment is "the" range any more
            if (rsDateRange.busy) return;
            document.querySelectorAll('.hf-seg .is-on').forEach(function (b) { b.classList.remove('is-on'); });
        });
    });
}

// ==================== SEARCHABLE DROPDOWN ====================
// upgrades a <select>: the select stays in the DOM as the value + change source, so existing
// listeners keep working. Placeholder = the option with value ''.
function rsSelect(sel) {
    sel = typeof sel === 'string' ? document.getElementById(sel) : sel;
    if (!sel || sel._rsSd) return sel && sel._rsSd;
    var wrap = document.createElement('div'), trig = document.createElement('div'), panel = document.createElement('div');
    wrap.className = 'sd-wrap';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.tabIndex = -1;
    trig.className = 'sd-trigger';
    trig.tabIndex = 0;
    trig.setAttribute('role', 'combobox');
    trig.innerHTML = '<span class="sd-label"></span><i class="fas fa-chevron-down sd-arrow"></i>';
    wrap.insertBefore(trig, sel);
    panel.className = 'sd-panel';
    panel.innerHTML = '<input type="text" class="sd-search" placeholder="Search…" autocomplete="off" aria-label="Search options"><div class="sd-list" role="listbox"></div>';
    var search = panel.firstChild, list = panel.lastChild, shown = [], hl = 0;
    var ph = (sel.querySelector('option[value=""]') || {}).text || 'Select…';

    function paint() {
        var op = sel.value !== '' && sel.options[sel.selectedIndex], l = trig.firstChild;
        l.textContent = op ? op.text : ph;
        l.classList.toggle('sd-ph', !op);
    }
    function draw() {
        var k = search.value.toLowerCase();
        shown = [].slice.call(sel.options).filter(function (o) { return !k || o.text.toLowerCase().indexOf(k) > -1; });
        hl = Math.min(hl, shown.length - 1);
        list.innerHTML = shown.length ? shown.map(function (o, i) {
            return '<div class="sd-item' + (o.value === sel.value ? ' sel' : '') + (i === hl ? ' hl' : '') + '" data-i="' + i + '">' + rsEsc(o.text) + '</div>';
        }).join('') : '<div class="sd-empty">No results found</div>';
    }
    function place() {
        var r = trig.getBoundingClientRect(), below = innerHeight - r.bottom, up = below < 240 && r.top > below;
        panel.style.left = Math.max(4, Math.min(r.left, innerWidth - Math.max(r.width, 200) - 4)) + 'px';
        panel.style.width = Math.max(r.width, 200) + 'px';
        panel.style.top = up ? '' : (r.bottom + 2) + 'px';
        panel.style.bottom = up ? (innerHeight - r.top + 2) + 'px' : '';
    }
    function open() {
        if (panel.isConnected) return;
        document.body.appendChild(panel);
        wrap.classList.add('open');
        search.value = ''; hl = 0; draw(); place(); search.focus();
    }
    function close(refocus) {
        if (!panel.isConnected) return;
        panel.remove(); wrap.classList.remove('open');
        if (refocus) trig.focus();
    }
    function pick(o) {
        sel.value = o.value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        close(true);
    }
    trig.addEventListener('click', function () { panel.isConnected ? close() : open(); });
    trig.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); open(); } });
    search.addEventListener('input', function () { hl = 0; draw(); });
    search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); hl = Math.max(0, Math.min(shown.length - 1, hl + (e.key === 'ArrowDown' ? 1 : -1))); draw(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (shown[hl]) pick(shown[hl]); }
        else if (e.key === 'Escape' || e.key === 'Tab') close(e.key === 'Escape');
    });
    list.addEventListener('mousedown', function (e) { var it = e.target.closest('.sd-item'); if (it) { e.preventDefault(); pick(shown[+it.dataset.i]); } });
    document.addEventListener('mousedown', function (e) { if (!wrap.contains(e.target) && !panel.contains(e.target)) close(); }, true);
    addEventListener('resize', function () { close(); });
    document.addEventListener('scroll', function (e) { if (panel.isConnected && !panel.contains(e.target)) place(); }, true);
    sel.addEventListener('change', paint); // programmatic resets (Clear) repaint too
    paint();
    return (sel._rsSd = { paint: paint, close: close });
}

// ==================== CSV ====================
// RFC 4180 — quoted fields, escaped quotes, CRLF, all of it
function rsParseCSV(text) {
    var rows = [], row = [], val = '', q = false, i = 0;
    text = String(text).replace(/^﻿/, '');
    for (; i < text.length; i++) {
        var ch = text[i];
        if (q) {
            if (ch === '"') { text[i + 1] === '"' ? (val += '"', i++) : (q = false); }
            else val += ch;
        } else if (ch === '"') q = true;
        else if (ch === ',') { row.push(val); val = ''; }
        else if (ch === '\n') { row.push(val); rows.push(row); row = []; val = ''; }
        else if (ch !== '\r') val += ch;
    }
    if (val !== '' || row.length) { row.push(val); rows.push(row); }
    return rows.filter(function (r) { return r.some(function (c) { return String(c).trim() !== ''; }); });
}

// rows -> objects keyed by the header line, trimmed
function rsCsvObjects(text) {
    var rows = rsParseCSV(text);
    if (rows.length < 2) return { head: rows[0] || [], list: [] };
    var head = rows[0].map(function (h) { return String(h).trim().toLowerCase(); });
    return {
        head: head,
        list: rows.slice(1).map(function (r) {
            var o = {};
            head.forEach(function (h, i) { o[h] = String(r[i] == null ? '' : r[i]).trim(); });
            return o;
        })
    };
}

function rsCsvCell(v) {
    v = String(v == null ? '' : v);
    return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
}

// build + hand over a .csv without touching the server
function rsDownloadCSV(filename, rows) {
    var csv = rows.map(function (r) { return r.map(rsCsvCell).join(','); }).join('\r\n');
    var url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
}

// one hidden input, reused — read a picked .csv as text
function rsPickCSV(cb) {
    var inp = document.getElementById('rsCsvPicker');
    if (!inp) {
        inp = document.createElement('input');
        inp.type = 'file'; inp.accept = '.csv,text/csv'; inp.id = 'rsCsvPicker'; inp.className = 'initially-hidden';
        document.body.appendChild(inp);
    }
    inp.onchange = function () {
        var f = this.files[0];
        if (!f) return;
        var fr = new FileReader();
        fr.onload = function () { cb(String(fr.result), f.name); };
        fr.readAsText(f);
        inp.value = ''; // same file twice in a row still fires
    };
    inp.click();
}

// ---------- Tabulator helpers (users list; same names as the table doc) ----------

// min/max header filter — 'number' or 'date' (ISO strings compare as text)
function rangeFilter(type) {
    return function (cell, onRendered, success, cancel) {
        var box = document.createElement('span'), v = cell.getValue() || {};
        var mk = function (ph, val) { var i = document.createElement('input'); i.type = type; i.placeholder = ph; i.value = val || ''; return i; };
        var a = mk(type === 'date' ? 'From' : 'Min', v.start), b = mk(type === 'date' ? 'To' : 'Max', v.end);
        var send = function () { success({ start: a.value, end: b.value }); };
        box.className = 'tb-range';
        [a, b].forEach(function (el) {
            el.addEventListener('change', send);
            el.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); if (e.key === 'Escape') cancel(); });
            box.appendChild(el);
        });
        return box;
    };
}
// a 0 is a real value; a datetime row value is cut to the bound's length so "to 2026-07-14" keeps that whole day
function inRange(h, v) {
    if (!h || (h.start === '' && h.end === '')) return true;
    if (v == null || v === '') return false;
    if (typeof v === 'number' || /^-?\d+(\.\d+)?$/.test(v)) {
        return (h.start === '' || Number(v) >= Number(h.start)) && (h.end === '' || Number(v) <= Number(h.end));
    }
    var s = String(v);
    return (h.start === '' || s >= h.start) && (h.end === '' || s.slice(0, h.end.length) <= h.end);
}
function rangeCol(type) {
    return { headerFilter: rangeFilter(type), headerFilterFunc: inRange, headerFilterLiveFilter: false, minWidth: type === 'date' ? 230 : 150,
             headerFilterEmptyCheck: function (h) { return !h || (h.start === '' && h.end === ''); } };
}

// cellEdited -> save one field; the cell spins, a failure puts the old value back (no cellEdited re-fire)
function saveCell(cell, save) {
    var el = cell.getElement(), d = cell.getRow().getData();
    el.classList.remove('tb-saved', 'tb-err'); el.classList.add('tb-saving');
    return Promise.resolve(save(d.id, cell.getField(), cell.getValue()))
        .then(function (r) { if (!r || !r.success) throw new Error((r && r.message) || 'Save failed'); el.classList.add('tb-saved'); })
        .catch(function (e) {
            cell.restoreOldValue(); el.classList.add('tb-err');
            Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: (e && e.message) || 'Could not save — check your connection', timer: 2500, showConfirmButton: false });
        })
        .finally(function () { el.classList.remove('tb-saving'); setTimeout(function () { el.classList.remove('tb-saved', 'tb-err'); }, 1200); });
}

// jsPDF + AutoTable only when someone asks for a PDF
function loadPdfDeps(cb) {
    if (window.jspdf && window.jspdf.jsPDF && window.jspdf.jsPDF.API.autoTable) return cb();
    var urls = ['https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js',
                'https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.4/dist/jspdf.plugin.autotable.min.js'];
    (function next(i) {
        if (i === urls.length) return cb();
        var s = document.createElement('script');
        s.src = urls[i];
        s.onload = function () { next(i + 1); };
        s.onerror = function () { Swal.fire('Error', 'Could not load the PDF library', 'error'); };
        document.head.appendChild(s);
    })(0);
}

// ---------- rsReport — one document model, two renderers (A4 print + PDF) that look the same ----------
// minimal corporate look: black & white, thin borders, no fills. brand comes from window.RS_BRAND
var rsReport = (function () {
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var stamp = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };
    // jsPDF core fonts are WinAnsi — no arrows / rupee sign
    var safe = function (v) { return String(v == null ? '' : v).replace(/→/g, '->').replace(/←/g, '<-').replace(/₹/g, 'Rs.').replace(/[^\x00-\xFF·—–…‘’“”•€]/g, ''); };

    // fill defaults once: brand, number, date
    function norm(doc) {
        var now = new Date(), b = window.RS_BRAND || {};
        return Object.assign({
            brand: { name: b.name || 'Dashboard', tagline: b.tagline || '', contact: b.contact || '', logo: b.logo || '' },
            number: 'RPT-' + now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate()) + '-' + pad(now.getHours()) + pad(now.getMinutes()),
            date: stamp(now), info: [], party: null, totals: [], notes: '',
            footNote: 'Computer-generated document. No signature or stamp is required.'
        }, doc);
    }
    var cell = function (c, r) { return c.get ? c.get(r) : r[c.field]; };

    var CSS = '@page{size:A4;margin:12.7mm 14mm 16mm}'
        + '@page{@bottom-left{content:"__FL__";font:7pt Helvetica,Arial,sans-serif;color:#666;border-top:.4pt solid #000;vertical-align:top;padding-top:4pt}'
        + '@bottom-center{content:"__FC__";font:7pt Helvetica,Arial,sans-serif;color:#666;border-top:.4pt solid #000;vertical-align:top;padding-top:4pt}'
        + '@bottom-right{content:"Page " counter(page) " of " counter(pages);font:7pt Helvetica,Arial,sans-serif;color:#666;border-top:.4pt solid #000;vertical-align:top;padding-top:4pt}}'
        + '*{box-sizing:border-box}html,body{margin:0;background:#fff;color:#000;font:9pt/1.25 Helvetica,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        + '.rp-wm{position:fixed;top:50%;left:50%;width:320px;height:320px;margin:-160px 0 0 -160px;opacity:.08;object-fit:contain;z-index:0}'
        + '.rp{position:relative;z-index:1}'
        + '.rp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16pt}'
        + '.rp-brand b{display:block;font-size:14pt;letter-spacing:1pt}.rp-brand small,.rp-title small{display:block;font-size:8pt;color:#666}'
        + '.rp-title{text-align:right}.rp-title b{display:block;font-size:16pt;letter-spacing:2pt}.rp-title strong{display:block;font-size:10pt;margin-top:1pt}'
        + '.rp-rule{border:0;border-top:.6pt solid #000;margin:8pt 0 10pt}'
        + 'table{border-collapse:collapse;width:100%}th,td{border:.5pt solid #000;padding:3pt 5pt;text-align:left;vertical-align:top}'
        + 'th{font-size:8pt;font-weight:700;letter-spacing:.5pt;text-transform:uppercase}'
        + '.rp-boxes{display:flex;gap:8pt;margin-bottom:10pt;align-items:flex-start}.rp-boxes>table{flex:1}.rp-boxes>table.rp-meta{flex:0 0 200pt}'
        + '.rp-meta th{width:42%;text-transform:none;letter-spacing:0}.rp-meta td{text-align:right;font-size:8pt}'
        + '.rp-party b{font-size:10pt}.rp-party small{display:block;font-size:8pt;color:#555}'
        + '.rp-subject{font-weight:700;font-size:10pt;margin:0 0 6pt}'
        + '.rp-table{margin-bottom:8pt}.rp-table thead{display:table-header-group}.rp-table tr{break-inside:avoid}.rp-table td{font-size:9pt}'
        + '.rp-table .r{text-align:right}.rp-table .c{text-align:center}.rp-table td small{display:block;font-size:8pt;color:#555}'
        + '.rp-empty{text-align:center;color:#555;font-style:italic}'
        + '.rp-totals{width:220pt;margin:0 0 12pt auto}.rp-totals td{text-align:right}.rp-totals tr:last-child td{font-weight:700;font-size:10pt}'
        + '.rp-notes{margin:6pt 0 10pt}.rp-notes td{font-size:8pt;color:#222;line-height:1.35;white-space:pre-line}'
        + '.rp-foot{text-align:center;font-style:italic;font-size:7pt;color:#666;margin-top:8pt;padding-top:6pt;border-top:.4pt solid #999}';

    function html(doc, wm) {
        doc = norm(doc);
        var b = doc.brand, al = function (c) { return c.align === 'right' ? ' class="r"' : c.align === 'center' ? ' class="c"' : ''; };
        var party = doc.party ? '<table class="rp-party"><tr><th>' + esc(doc.party.label || 'Details') + '</th></tr><tr><td><b>' + esc(doc.party.name || '')
            + '</b>' + (doc.party.lines || []).map(function (l) { return '<small>' + esc(l) + '</small>'; }).join('') + '</td></tr></table>' : '';
        var meta = doc.info.length ? '<table class="rp-meta">' + doc.info.map(function (i) { return '<tr><th>' + esc(i[0]) + '</th><td>' + esc(i[1]) + '</td></tr>'; }).join('') + '</table>' : '';
        var body = doc.rows.length ? doc.rows.map(function (r, n) {
            return '<tr>' + doc.cols.map(function (c) { var v = c.field === '#' ? n + 1 : cell(c, r), sub = c.sub ? c.sub(r) : '';
                return '<td' + al(c) + '>' + (c.bold ? '<b>' + esc(v) + '</b>' : esc(v)) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</td>'; }).join('') + '</tr>';
        }).join('') : '<tr><td class="rp-empty" colspan="' + doc.cols.length + '">No records</td></tr>';
        var cssStr = function (v) { return String(v).replace(/[\\"<>]/g, ''); };   // safe inside a CSS string in a <style> tag
        return '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(doc.number) + '</title><style>'
            + CSS.replace('__FL__', cssStr(b.name + (b.contact ? '  |  ' + b.contact : ''))).replace('__FC__', cssStr(doc.number)) + '</style></head><body>'
            + (wm ? '<img class="rp-wm" src="' + wm + '" alt="">' : '')
            + '<div class="rp"><div class="rp-head"><div class="rp-brand"><b>' + esc(String(b.name).toUpperCase()) + '</b>'
            + (b.tagline ? '<small>' + esc(b.tagline) + '</small>' : '') + (b.contact ? '<small>' + esc(b.contact) + '</small>' : '') + '</div>'
            + '<div class="rp-title"><b>' + esc(String(doc.title).toUpperCase()) + '</b><strong>' + esc(doc.number) + '</strong><small>' + esc(doc.date) + '</small></div></div>'
            + '<hr class="rp-rule">'
            + (party || meta ? '<div class="rp-boxes">' + (party || '<span style="flex:1"></span>') + meta + '</div>' : '')
            + (doc.subject ? '<p class="rp-subject">' + esc(doc.subject) + '</p>' : '')
            + '<table class="rp-table"><thead><tr>' + doc.cols.map(function (c) { return '<th' + al(c) + '>' + esc(c.title) + '</th>'; }).join('') + '</tr></thead><tbody>' + body + '</tbody></table>'
            + (doc.totals.length ? '<table class="rp-totals">' + doc.totals.map(function (t) { return '<tr><td>' + esc(t[0]) + '</td><td>' + esc(t[1]) + '</td></tr>'; }).join('') + '</table>' : '')
            + (doc.notes ? '<table class="rp-notes"><tr><th>' + esc(doc.notesTitle || 'Notes') + '</th></tr><tr><td>' + esc(doc.notes) + '</td></tr></table>' : '')
            + (doc.footNote ? '<div class="rp-foot">' + esc(doc.footNote) + '</div>' : '')
            + '</div></body></html>';
    }

    // hidden iframe -> print dialog, page never leaves. watermark = the same data url the PDF uses
    function print(doc) {
        return logoData(norm(doc).brand.logo).then(function (wm) {
            var f = document.createElement('iframe');
            f.setAttribute('aria-hidden', 'true');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
            document.body.appendChild(f);
            var w = f.contentWindow, d = w.document;
            d.open(); d.write(html(doc, wm)); d.close();
            setTimeout(function () { w.focus(); w.print(); setTimeout(function () { f.remove(); }, 1000); }, 60);
            return f;
        });
    }

    // logo -> greyscale data url, shared by print + PDF. same-origin or CORS-enabled only:
    // a logo the canvas can't read means no watermark in EITHER output, so the two always match
    function logoData(url) {
        return new Promise(function (res) {
            if (!url) return res(null);
            var im = new Image(); im.crossOrigin = 'anonymous';
            setTimeout(function () { res(null); }, 2500);   // slow host -> no watermark, never a stuck print
            im.onload = function () { try { var c = document.createElement('canvas'), x = c.getContext('2d'); c.width = im.naturalWidth; c.height = im.naturalHeight; x.filter = 'grayscale(1)'; x.drawImage(im, 0, 0); res(c.toDataURL('image/png')); } catch (e) { res(null); } };
            im.onerror = function () { res(null); };
            im.src = url;
        });
    }

    // same anatomy drawn with jsPDF + AutoTable (pt units, A4)
    function pdf(doc, filename) {
        doc = norm(doc);
        return new Promise(function (resolve) { loadPdfDeps(resolve); }).then(function () { return logoData(doc.brand.logo); }).then(function (wm) {
            var J = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' }), W = J.internal.pageSize.getWidth(), H = J.internal.pageSize.getHeight(), M = 40, b = doc.brand;
            var grid = { theme: 'grid', styles: { font: 'helvetica', fontSize: 9, textColor: 0, lineColor: 0, lineWidth: 0.5, cellPadding: { top: 3, bottom: 3, left: 5, right: 5 }, fillColor: false },
                         headStyles: { fillColor: false, textColor: 0, fontStyle: 'bold', fontSize: 8 }, margin: { left: M, right: M, bottom: 50 } };
            var water = function () { if (!wm) return; J.saveGraphicsState(); J.setGState(new J.GState({ opacity: 0.08 })); J.addImage(wm, 'PNG', (W - 320) / 2, (H - 320) / 2, 320, 320); J.restoreGraphicsState(); };
            water();
            // letterhead
            J.setFont('helvetica', 'bold'); J.setFontSize(14); J.text(safe(String(b.name).toUpperCase()), M, 50, { charSpace: 1 });
            J.setFont('helvetica', 'normal'); J.setFontSize(8); J.setTextColor(102);
            var y = 62; [b.tagline, b.contact].filter(Boolean).forEach(function (l) { J.text(safe(l), M, y); y += 10; });
            J.setTextColor(0); J.setFont('helvetica', 'bold'); J.setFontSize(16);
            var tt = safe(String(doc.title).toUpperCase()), tw = J.getTextWidth(tt) + 2 * tt.length;   // align:right ignores charSpace (added after every letter)
            J.text(tt, W - M - tw, 50, { charSpace: 2 });
            J.setFontSize(10); J.text(safe(doc.number), W - M, 63, { align: 'right' });
            J.setFont('helvetica', 'normal'); J.setFontSize(8); J.setTextColor(102); J.text(safe(doc.date), W - M, 74, { align: 'right' }); J.setTextColor(0);
            y = Math.max(y, 80) + 4; J.setLineWidth(0.6); J.line(M, y, W - M, y); y += 12;
            // party (left) + meta (right) boxes, side by side
            var boxTop = y, ends = [y];
            if (doc.party) {
                J.autoTable(Object.assign({}, grid, { startY: boxTop, tableWidth: W - 2 * M - (doc.info.length ? 208 : 0),
                    head: [[safe(String(doc.party.label || 'Details').toUpperCase())]],
                    // one box: bold name, grey lines, inner borders hidden
                    body: [doc.party.name].concat(doc.party.lines || []).map(function (l, i, a) {
                        return [{ content: safe(l), styles: { fontStyle: i ? 'normal' : 'bold', fontSize: i ? 8 : 10, textColor: i ? 85 : 0,
                            cellPadding: { top: i ? 0 : 3, bottom: i === a.length - 1 ? 3 : 1, left: 5, right: 5 },
                            lineWidth: { left: 0.5, right: 0.5, top: i ? 0 : 0.5, bottom: i === a.length - 1 ? 0.5 : 0 } } }];
                    }) }));
                ends.push(J.lastAutoTable.finalY);
            }
            if (doc.info.length) {
                J.autoTable(Object.assign({}, grid, { startY: boxTop, tableWidth: 200, margin: { left: W - M - 200, right: M },
                    styles: Object.assign({}, grid.styles, { fontSize: 8 }), body: doc.info.map(function (i) { return [safe(i[0]), safe(i[1])]; }),
                    columnStyles: { 0: { fontStyle: 'bold' }, 1: { halign: 'right' } } }));
                ends.push(J.lastAutoTable.finalY);
            }
            y = Math.max.apply(null, ends) + (ends.length > 1 ? 10 : 0);
            if (doc.subject) { J.setFont('helvetica', 'bold'); J.setFontSize(10); J.text(safe(doc.subject), M, y + 8); y += 16; }
            // main table — header repeats on every page, watermark goes under each new page
            var halign = {}; doc.cols.forEach(function (c, i) { if (c.align) halign[i] = { halign: c.align }; if (c.bold) halign[i] = Object.assign(halign[i] || {}, { fontStyle: 'bold' }); });
            J.autoTable(Object.assign({}, grid, { startY: y, showHead: 'everyPage',
                head: [doc.cols.map(function (c) { return safe(String(c.title).toUpperCase()); })],
                body: doc.rows.length ? doc.rows.map(function (r, n) { return doc.cols.map(function (c) { var v = c.field === '#' ? n + 1 : cell(c, r), s = c.sub ? c.sub(r) : ''; return safe(v) + (s ? '\n' + safe(s) : ''); }); })
                                      : [[{ content: 'No records', colSpan: doc.cols.length, styles: { halign: 'center', fontStyle: 'italic', textColor: 85 } }]],
                columnStyles: halign, willDrawPage: function (d) { if (d.pageNumber > 1) water(); },
                didParseCell: function (d) { var c = doc.cols[d.column.index]; if (d.section === 'head' && c && c.align) d.cell.styles.halign = c.align; } }));
            y = J.lastAutoTable.finalY + 8;
            if (doc.totals.length) {
                J.autoTable(Object.assign({}, grid, { startY: y, tableWidth: 220, margin: { left: W - M - 220, right: M },
                    body: doc.totals.map(function (t, i, a) { var last = i === a.length - 1; return [{ content: safe(t[0]), styles: { halign: 'right', fontStyle: last ? 'bold' : 'normal', fontSize: last ? 10 : 9 } }, { content: safe(t[1]), styles: { halign: 'right', fontStyle: last ? 'bold' : 'normal', fontSize: last ? 10 : 9 } }]; }) }));
                y = J.lastAutoTable.finalY + 12;
            }
            if (doc.notes) {
                J.autoTable(Object.assign({}, grid, { startY: y, head: [[safe(String(doc.notesTitle || 'Notes').toUpperCase())]], body: [[safe(doc.notes)]], styles: Object.assign({}, grid.styles, { fontSize: 8 }) }));
                y = J.lastAutoTable.finalY + 10;
            }
            if (doc.footNote) {
                if (y > H - 80) { J.addPage(); water(); y = 50; }
                J.setDrawColor(153); J.setLineWidth(0.4); J.line(M, y, W - M, y); J.setDrawColor(0);
                J.setFont('helvetica', 'italic'); J.setFontSize(7); J.setTextColor(102); J.text(safe(doc.footNote), W / 2, y + 12, { align: 'center' }); J.setTextColor(0);
            }
            // footer on every page: brand | number | Page x of y
            var n = J.getNumberOfPages();
            for (var p = 1; p <= n; p++) {
                J.setPage(p); J.setLineWidth(0.4); J.line(M, H - 32, W - M, H - 32);
                J.setFont('helvetica', 'normal'); J.setFontSize(7); J.setTextColor(102);
                J.text(safe(b.name + (b.contact ? '  |  ' + b.contact : '')), M, H - 22);
                J.text(safe(doc.number), W / 2, H - 22, { align: 'center' });
                J.text('Page ' + p + ' of ' + n, W - M, H - 22, { align: 'right' });
                J.setTextColor(0);
            }
            J.save((filename || doc.number) + '.pdf');
            return J;
        });
    }

    return { html: html, print: print, pdf: pdf, css: CSS };
})();

// header Print / PDF buttons -> the shared report (PDF build shows the processing overlay)
function rsReportExport(kind, doc, file) {
    if (kind === 'print') return rsReport.print(doc);
    var done = window.rsOverlay ? rsOverlay('Building PDF…') : function () {};
    rsReport.pdf(doc, file).then(done, function (e) { done(); Swal.fire('Error', 'Could not build the PDF', 'error'); console.error(e); });
}

// ---------- rsTable — one Tabulator setup for every list page ----------
// takes the page's column defs ({data,title,render(d,type,row),orderable,searchable,className,visible,width,name})
// and maps them to Tabulator: field sorts + filters, render('display') only paints, render('export') feeds CSV/PDF/Print
var rsTxt = function (h) {
    if (h == null) return '';
    if (typeof h !== 'string' || h.indexOf('<') === -1) return typeof h === 'string' ? h.replace(/&amp;/g, '&').trim() : h;
    var d = document.createElement('div'); d.innerHTML = h; return d.textContent.replace(/\s+/g, ' ').trim();
};
// numbers as numbers (mysqli hands back strings), the rest natural order — auto sorter guesses before rows exist
function rsTbSort(a, b) {
    var x = +a, y = +b;
    if (a !== '' && b !== '' && a != null && b != null && !isNaN(x) && !isNaN(y)) return x - y;
    return String(a == null ? '' : a).localeCompare(String(b == null ? '' : b), undefined, { numeric: true });
}
// rendered text width in px (canvas, body font) — sizes columns to their content
var rsTxtW = (function () {
    var cx;
    return function (txt, head) {
        cx = cx || document.createElement('canvas').getContext('2d');
        cx.font = (head ? '600 12.5px ' : '13px ') + getComputedStyle(document.body).fontFamily;
        return Math.ceil(cx.measureText(String(txt == null ? '' : txt)).width) + (head ? 40 : 30);
    };
})();

function rsTbCol(c, i, finds, ests) {
    if (c.field || c.formatter) return c; // already a Tabulator column
    var val = function (r) { var v = c.data != null ? r[c.data] : r; return v == null ? (c.defaultContent || '') : v; };
    var show = function (r, type) { return c.render ? c.render(val(r), type, r) : (c.data != null ? rsEsc(val(r)) : ''); };
    var act = c.data == null && c.orderable === false, field = c.data != null ? c.data : (c.name || '_c' + i);
    if (!act && c.searchable !== false) finds.push(function (r) { return rsTxt(show(r, 'filter')); });
    if (!act && ests) ests.push({ col: null, html: function (r) { return show(r, 'display'); } });
    var col = {
        title: rsTxt(c.title || ''), field: field, headerSort: c.orderable !== false, visible: c.visible !== false,
        cssClass: c.className || '', minWidth: act ? Math.max(136, parseInt(c.width, 10) || 0) : (parseInt(c.width, 10) || 60),
        formatter: function (cell) { return show(cell.getRow().getData(), 'display'); },
        download: !act && c.exportable !== false && !!c.title,
        accessorDownload: function (v, r) { return rsTxt(show(r, 'export')); }
    };
    if (!act) col.sorter = c.sorter || rsTbSort;
    if (act) Object.assign(col, { responsive: 0, hozAlign: 'right', headerHozAlign: 'right', resizable: false, width: col.minWidth });
    else if (ests) ests[ests.length - 1].col = col;
    if (c.responsivePriority != null) col.responsive = c.responsivePriority;
    return col;
}

// o: { columns, data, title (print heading), file (export name), sort: [field, dir], rowClass(row) -> class,
//      onFilter(rows) after every filter/data change, empty (placeholder text), bulk (force tick bar), opts (raw Tabulator opts) }
// a 'bulk-col' column in the old defs turns on the tick bar instead of rendering a checkbox column
function rsTable(el, o) {
    el = typeof el === 'string' ? document.querySelector(el) : el;
    if (el && el.tagName === 'TABLE') { // tabulator wants a div — keep id + classes so page selectors still hit
        var d = document.createElement('div'); d.id = el.id; d.className = el.className.replace(/\bdisplay\b/, '').trim();
        el.parentNode.replaceChild(d, el); el = d;
    }
    var bulk = false, finds = [], ests = [], cols = [];
    (o.columns || []).forEach(function (c, i) {
        if (/\bbulk-col\b/.test(c.className || '')) { bulk = true; return; }
        cols.push(rsTbCol(c, i, finds, ests));
    });
    // each column at least as wide as its header / widest of the first rows (capped — long text wraps),
    // spare room is then shared out evenly by fitColumns
    var sample = (o.data || []).slice(0, 60);
    if (sample.length && ests.length) {
        // real cell markup measured off-screen once: badges, monospace amounts and icons all count
        var box = document.createElement('div');
        box.className = 'rs-measure tabulator';
        box.innerHTML = ests.map(function (e) { return sample.map(function (r) { return '<div>' + e.html(r) + '</div>'; }).join(''); }).join('');
        document.body.appendChild(box);
        var kids = box.children;
        ests.forEach(function (e, i) {
            var w = rsTxtW(e.col.title, true);
            for (var k = i * sample.length; k < (i + 1) * sample.length; k++) w = Math.max(w, kids[k].offsetWidth + 22);
            e.col.minWidth = Math.max(e.col.minWidth || 0, Math.min(w, 260));
        });
        box.remove();
    } else ests.forEach(function (e) { e.col.minWidth = Math.max(e.col.minWidth || 0, rsTxtW(e.col.title, true)); });
    if (o.bulk != null) bulk = !!o.bulk;
    // phones keep the name column: first visible data column after the id never folds
    var cand = cols.filter(function (c) { return c.visible !== false && c.field !== 'id' && c.title && c.responsive == null && c.hozAlign !== 'right'; });
    var lead = cand.filter(function (c) { return /name|title|agent|buyer|customer|user/i.test(c.title); })[0] || cand[0];
    if (lead) lead.responsive = 0;
    // +/- toggle for columns folded into the row; tabulator hides it when nothing is folded
    cols.unshift({ formatter: 'responsiveCollapse', width: 34, minWidth: 34, hozAlign: 'center', resizable: false, headerSort: false,
        download: false, responsive: 0, cssClass: 'tb-fold' });
    var t = new Tabulator(el, Object.assign({
        data: o.data || [], layout: 'fitColumns', responsiveLayout: 'collapse', responsiveLayoutCollapseStartOpen: false,
        placeholder: o.empty || 'No records found', columnDefaults: { minWidth: 60, headerWordWrap: true },
        pagination: 'local', paginationSize: 10, paginationSizeSelector: [10, 25, 50, 100, true], paginationCounter: 'rows',
        movableColumns: true, selectableRows: bulk ? 'highlight' : false,
        rowHeader: bulk ? { formatter: 'rowSelection', titleFormatter: 'rowSelection', titleFormatterParams: { rowRange: 'active' },
            headerSort: false, resizable: false, frozen: true, headerHozAlign: 'center', hozAlign: 'center', width: 46, minWidth: 46,
            download: false, responsive: 0, cellClick: function (e, cell) { cell.getRow().toggleSelect(); } } : false,
        initialSort: o.sort ? [{ column: o.sort[0], dir: o.sort[1] || 'asc' }] : [],
        rowFormatter: function (row) {
            if (!o.rowClass) return;
            var e = row.getElement(), c = o.rowClass(row.getData()) || '';
            if (e._rc) e.classList.remove(e._rc);
            if (c) e.classList.add(c);
            e._rc = c;
        },
        columns: cols
    }, o.opts || {}));
    var blobs = new WeakMap(), blob = function (r) {
        var b = blobs.get(r);
        if (b == null) { b = finds.map(function (f) { return f(r); }).join(' ').toLowerCase(); blobs.set(r, b); }
        return b;
    };
    var fire = function () { if (o.onFilter) o.onFilter(t.getData('active')); };
    Object.assign(t, { rsQ: '', rsWhere: {}, rsTitle: o.title || '', rsFile: o.file || 'export', rsInfo: o.info || null, rsBuilt: false, rsBlob: blob });
    t.on('tableBuilt', function () {
        t.rsBuilt = true;
        if (t.rsNext) { t.replaceData(t.rsNext); t.rsNext = null; }
        rsTbApply(t); fire();
    });
    t.on('dataFiltered', function () { if (t.rsBuilt) setTimeout(fire, 0); });
    t.on('dataLoaded', function () { if (t.rsBuilt) setTimeout(fire, 0); });
    return t;
}
// search box + named predicates -> one filter fn
function rsTbApply(t) {
    if (!t || !t.rsBuilt) return;
    var q = String(t.rsQ || '').trim().toLowerCase(),
        ps = Object.keys(t.rsWhere).map(function (k) { return t.rsWhere[k]; }).filter(Boolean);
    if (!q && !ps.length) return t.clearFilter();
    t.setFilter(function (r) { return ps.every(function (p) { return p(r); }) && (!q || t.rsBlob(r).indexOf(q) > -1); });
}
function rsTbSearch(t, q) { if (!t) return; t.rsQ = q || ''; rsTbApply(t); }
// rsTbWhere(t, 'state', row => row.state === 'X') — null clears it
function rsTbWhere(t, key, fn) { if (!t) return; t.rsWhere[key] = fn || null; rsTbApply(t); }
function rsTbClear(t) { if (!t) return; t.rsQ = ''; t.rsWhere = {}; rsTbApply(t); }
// rows after search + filters, in the on-screen sort
function rsTbRows(t) { return t && t.rsBuilt ? t.getData('active') : []; }
// refetch -> swap rows, never rebuild (keeps sort, page, filters)
function rsTbData(t, rows) { if (!t) return; t.rsBuilt ? t.replaceData(rows) : (t.rsNext = rows); }

// list table inside a pop-up tab: built the first time its pane is on screen (a hidden pane has no width),
// redrawn on every later show. same options as rsTable; returns { t } — t is null until first shown
function rsTabTable(el, o) {
    el = typeof el === 'string' ? document.querySelector(el) : el;
    if (!el) return { t: null };
    var h = { t: null };
    var show = function () {
        if (h.t) return h.t.rsBuilt && h.t.redraw(true);
        h.t = rsTable(el, Object.assign({ empty: 'Nothing to show yet' }, o));
    };
    if (el.offsetWidth) { show(); return h; }
    var io = new IntersectionObserver(function (es) {
        if (!el.isConnected) return io.disconnect(); // pop-up re-rendered
        if (es.some(function (e) { return e.isIntersecting; })) show();
    });
    io.observe(el);
    return h;
}

// ---------- paper helpers: money as a currency CODE (PDF fonts carry no ₹), dates spelled out ----------
function rsMoney(v) {
    var b = window.RS_BRAND || {}, n = Number(v) || 0;
    return (n < 0 ? '-' : '') + (b.cur ? b.cur + ' ' : '') + Math.abs(n).toLocaleString(b.cur === 'USD' || b.cur === 'EUR' || b.cur === 'GBP' ? 'en-US' : 'en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
// screen text -> paper text: the currency glyph becomes the code
function rsPaperTxt(s) {
    var b = window.RS_BRAND || {};
    s = String(s == null ? '' : s);
    return b.sym && b.cur && b.sym !== b.cur && s.indexOf(b.sym) > -1 ? s.split(b.sym).join(b.cur + ' ').replace(/\s{2,}/g, ' ').trim() : s;
}
function rsPaperDate(d) {
    if (!d || !/^\d{4}-\d{2}-\d{2}/.test(String(d))) return d ? String(d) : '';
    var x = new Date(String(d).slice(0, 10) + 'T00:00:00');
    return isNaN(x) ? String(d) : ('0' + x.getDate()).slice(-2) + ' ' + 'Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec'.split(' ')[x.getMonth()] + ' ' + x.getFullYear();
}
// local YYYYMMDD for document numbers
function rsStamp() { var x = new Date(); return x.getFullYear() + ('0' + (x.getMonth() + 1)).slice(-2) + ('0' + x.getDate()).slice(-2); }

// CSV = plain download; PDF / Print = the shared A4 report from the rows on screen
function rsTbExport(t, kind) {
    if (!t || !t.download) return;
    if (kind === 'csv' || kind === 'excel') return t.download('csv', t.rsFile + '.csv', { bom: true }, 'active');
    rsReportExport(kind, rsTbDoc(t), t.rsFile);
}
// rows on screen -> report model: # + paper columns, filters spelled out, money columns totalled
function rsTbDoc(t) {
    var rows = rsTbRows(t), b = window.RS_BRAND || {},
        num = function (v) { return Number(String(v).replace(/[^\d.-]/g, '')) || 0; };
    var cols = t.getColumns().map(function (c) { return c.getDefinition(); })
        .filter(function (d) { return d.title && d.download !== false && d.visible !== false; })
        .map(function (d) {
            var get = function (r) { return rsPaperTxt(d.accessorDownload ? d.accessorDownload(r[d.field], r) : rsTxt(r[d.field])); };
            var vals = rows.slice(0, 50).map(get).filter(function (v) { return v !== '' && v != null; }).map(String);
            var isNum = vals.length && vals.every(function (v) { return /^-?\s*(?:[A-Za-z.]{2,4}\s)?-?[\d,]+(\.\d+)?%?$/.test(v.trim()); });
            return { title: d.title, get: get, align: isNum ? 'right' : '', money: isNum && !!b.cur && vals.some(function (v) { return v.indexOf(b.cur) > -1; }) };
        });
    // sum money columns; a running balance / rate / average is never summed
    var totals = cols.filter(function (c) { return c.money && !/balance|rate|avg|average/i.test(c.title); }).map(function (c) {
        return [/^total/i.test(c.title) ? c.title : 'Total ' + c.title.toLowerCase(), rsMoney(rows.reduce(function (s, r) { return s + num(c.get(r)); }, 0))];
    });
    var filters = (t.rsQ ? ['Search "' + t.rsQ + '"'] : []).concat((t.rsInfo ? t.rsInfo() || [] : [])
        .filter(function (p) { return p[1] && !/^(all\b|none$|—$|-$)/i.test(String(p[1]).trim()); })
        .map(function (p) { return p[0] + ': ' + p[1]; }));
    return {
        title: t.rsTitle || t.rsFile,
        info: [['Rows', rows.length + ' of ' + t.getDataCount()], ['Filters', filters.join(' · ') || 'None'], ['Generated by', b.user || '—']],
        cols: [{ title: '#', field: '#', align: 'center' }].concat(cols),
        rows: rows,
        totals: totals.length ? [['Records', String(rows.length)]].concat(totals) : []
    };
}

// ledger statement (agent / buyer / bank): oldest first, opening -> closing balance
// o: { title, number, party, period, rows, cr(r), dr(r), bal(r), desc(r), sub(r), file, filters: [] }
function rsStatement(kind, o) {
    var rows = o.rows.slice().sort(function (a, b) {
        return String(a.transaction_date).localeCompare(String(b.transaction_date)) || (Number(a.id) - Number(b.id));
    });
    var cr = 0, dr = 0, first = rows[0], last = rows[rows.length - 1];
    rows.forEach(function (r) { cr += o.cr(r); dr += o.dr(r); });
    var b = window.RS_BRAND || {}, opening = o.bal(first) - o.cr(first) + o.dr(first);
    rsReportExport(kind, {
        title: o.title, number: o.number, party: o.party,
        info: [['Period', o.period], ['Entries', String(rows.length)], ['Filters', (o.filters || []).join(' · ') || 'None'], ['Generated by', b.user || '—']],
        cols: [
            { title: '#', field: '#', align: 'center' },
            { title: 'Date', get: function (r) { return rsPaperDate(r.transaction_date); } },
            { title: 'Description', get: o.desc, sub: o.sub },
            { title: 'Credit', get: function (r) { return o.cr(r) ? rsMoney(o.cr(r)) : ''; }, align: 'right' },
            { title: 'Debit', get: function (r) { return o.dr(r) ? rsMoney(o.dr(r)) : ''; }, align: 'right' },
            { title: 'Balance', get: function (r) { return rsMoney(o.bal(r)); }, align: 'right' }
        ],
        rows: rows,
        totals: [['Opening balance', rsMoney(opening)], ['Total credit', rsMoney(cr)], ['Total debit', rsMoney(dr)], ['Closing balance', rsMoney(o.bal(last))]],
        footNote: 'Computer-generated statement. No signature or stamp is required.'
    }, o.file);
}
