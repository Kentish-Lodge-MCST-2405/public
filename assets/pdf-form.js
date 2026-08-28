/* ============================================================
   Kentish Lodge MCST — submission PDF builder
   Environment-agnostic (browser + Node). Depends on pdf-lib.
   API: KLForms.buildPdf(pdfLib, input) -> {bytes:Uint8Array, pages:Number}
   input = {
     def:    form definition (formNo,title,estate,intro,notes,sections,officeUse,fileSlug)
     values: {k:v}  ({} for blank form)
     sigImages: {k: dataURL-PNG}
     attachments: [{name, bytes:Uint8Array}]  (pdf/jpg/png)
     ref: "KL-..." or ""
   }
   Page 1 is the form, auto-scaled 60–100% to fit one A4 page;
   attachments are appended as pages of the same PDF.
   ============================================================ */
(function (global) {
  "use strict";

  var W = 595.28, H = 841.89, M = 40, LBL = 180;

  function bytesFromDataUrl(u) {
    var b64 = u.split(",")[1], bin = atob(b64), arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return arr;
  }

  async function buildPdf(L, input) {
    var def = input.def, V = input.values || {};
    var doc = await L.PDFDocument.create();
    var F = {
      r: await doc.embedFont(L.StandardFonts.TimesRoman),
      b: await doc.embedFont(L.StandardFonts.TimesRomanBold),
      i: await doc.embedFont(L.StandardFonts.TimesRomanItalic)
    };
    var INK = L.rgb(0.12, 0.12, 0.12), RULE = L.rgb(0.45, 0.45, 0.45), LIGHT = L.rgb(0.62, 0.62, 0.62);

    /* ----- pre-embed attachments (need page numbers for the form page) ----- */
    var atts = [], pageNo = 2, i;
    for (i = 0; i < (input.attachments || []).length; i++) {
      var a = input.attachments[i];
      var ext = (a.name.split(".").pop() || "").toLowerCase();
      try {
        if (ext === "pdf") {
          var src = await L.PDFDocument.load(a.bytes, { ignoreEncryption: true });
          atts.push({ name: a.name, kind: "pdf", src: src, from: pageNo, count: src.getPageCount() });
          pageNo += src.getPageCount();
        } else if (ext === "jpg" || ext === "jpeg") {
          atts.push({ name: a.name, kind: "img", img: await doc.embedJpg(a.bytes), from: pageNo, count: 1 }); pageNo++;
        } else if (ext === "png") {
          atts.push({ name: a.name, kind: "img", img: await doc.embedPng(a.bytes), from: pageNo, count: 1 }); pageNo++;
        } else {
          atts.push({ name: a.name, kind: "err" });
        }
      } catch (e) { atts.push({ name: a.name, kind: "err" }); }
    }
    var attByName = {};
    atts.forEach(function (x) { if (x.kind !== "err") attByName[x.name] = x; });

    /* ----- signature images ----- */
    var sigImg = {};
    for (var k in (input.sigImages || {})) {
      try { sigImg[k] = await doc.embedPng(bytesFromDataUrl(input.sigImages[k])); } catch (e) {}
    }

    /* ----- layout engine (layout units, origin top-left, y grows down) ----- */
    function wrap(text, font, size, maxW) {
      var words = String(text == null ? "" : text).split(/\s+/), lines = [], cur = "";
      for (var i2 = 0; i2 < words.length; i2++) {
        var w = words[i2];
        if (!w) continue;
        var t = cur ? cur + " " + w : w;
        if (font.widthOfTextAtSize(t, size) <= maxW) cur = t;
        else { if (cur) lines.push(cur); cur = w; }
      }
      if (cur) lines.push(cur);
      return lines.length ? lines : [""];
    }

    function layout(s) {
      var maxW = (W - 2 * M) / s;
      var cmds = [], y = 8;
      function txt(x, yy, str, f, z) { if (str !== "") cmds.push({ t: "t", x: x, y: yy, f: f, z: z, s: String(str) }); }
      function line(x1, y1, x2, y2, w) { cmds.push({ t: "l", x1: x1, y1: y1, x2: x2, y2: y2, w: w || 0.7 }); }
      function rect(x, yy, ww, hh, w) { cmds.push({ t: "r", x: x, y: yy, w: ww, h: hh, b: w || 0.8 }); }
      function ell(x, yy, rr, fill) { cmds.push({ t: "e", x: x, y: yy, r: rr, f: !!fill }); }
      function img(x, yy, ww, hh, key) { cmds.push({ t: "i", x: x, y: yy, w: ww, h: hh, k: key }); }
      function para(str, x, width, f, z, lead) {
        wrap(str, F[f], z, width).forEach(function (l) { y += z * 1.18; txt(x, y, l, f, z); });
        y += (lead == null ? 3 : lead);
      }
      function center(str, f, z) {
        y += z * 1.15;
        var w = F[f].widthOfTextAtSize(str, z);
        txt((maxW - w) / 2, y, str, f, z);
      }

      /* masthead */
      center(def.estate, "b", 11.5);
      center(def.title, "b", 13.5);
      center(def.formNo + (input.ref ? "   ·   Ref " + input.ref : ""), "r", 8.6);
      if (input.ref) center("Submitted " + (input.submitted || ""), "r", 8.2);
      y += 8; line(0, y, maxW, y, 1.8); y += 14;

      /* intro + notes */
      para(def.intro, 0, maxW, "i", 8.6, 7);
      (def.notes || []).forEach(function (n) {
        if (n.type === "matrix") {
          var c1 = maxW * 0.36, c2 = maxW - c1 - 8;
          var topY = y + 2;
          wrap(n.head[0], F.b, 8.2, c1).forEach(function (l, ix) { y += 9.4; txt(0, y, l, "b", 8.2); });
          var headBottom = y + 3;
          var hy = topY;
          n.head[1] && wrap(n.head[1], F.b, 8.2, c2).forEach(function (l) { hy += 9.4; txt(c1 + 8, hy, l, "b", 8.2); });
          y = Math.max(y, hy) + 2; line(0, y, maxW, y, 0.9);
          n.rows.forEach(function (row) {
            var rowTop = y, ly = y, ry = y;
            wrap(row[0], F.r, 8.4, c1).forEach(function (l) { ly += 9.8; txt(0, ly, l, "r", 8.4); });
            wrap(row[1], F.r, 8.4, c2).forEach(function (l) { ry += 9.8; txt(c1 + 8, ry, l, "r", 8.4); });
            y = Math.max(ly, ry) + 2.5; line(0, y, maxW, y, 0.5);
          });
          line(c1 + 4, topY, c1 + 4, y, 0.5);
          y += 7;
        } else if (n.type === "p") {
          var plain = String(n.html).replace(/<[^>]+>/g, "");
          para(plain, 0, maxW, "r", 8.4, 7);
        }
      });

      /* sections */
      (def.sections || []).forEach(function (sec) {
        y += 10;
        var head = sec.no + "  —  " + sec.title.toUpperCase();
        y += 11; txt(0, y, head, "b", 10); y += 4; line(0, y, maxW, y, 1.1); y += 7;
        sec.fields.forEach(function (f) {
          var vis = (!f.showIf) || V[f.showIf.f] === f.showIf.eq;
          if (!vis) return;
          var v = V[f.k] || "";
          if (f.type === "check") {
            var boxY = y + 1.5;
            rect(0, boxY, 8, 8, 0.8);
            if (v) { line(1.5, boxY + 1.5, 6.5, boxY + 6.5, 0.8); line(6.5, boxY + 1.5, 1.5, boxY + 6.5, 0.8); }
            var tw = maxW - 14;
            wrap(f.label, F.r, 8.8, tw).forEach(function (l, ix) {
              y += ix === 0 ? 8.5 : 10; txt(14, y, l, "r", 8.8);
            });
            y += 7;
          } else if (f.type === "radio") {
            y += 9.5; txt(0, y, f.label, "b", 8.8); y += 6;
            var cx = 0;
            f.opts.forEach(function (o) {
              var label = o, wBox = 9, gap = 4;
              var lw = F.r.widthOfTextAtSize(label, 8.6);
              var need = wBox + gap + lw + 12;
              if (cx + need > maxW) { cx = 0; y += 12; }
              var cy = y - 2.5;
              cmds.push({ t: "c", x: cx + 4.5, y: cy, r: 3.6, f: v === o });
              txt(cx + 12, y, label, "r", 8.6);
              cx += need;
            });
            y += 12;
            if (f.other) {
              var ov = V[f.other.key] || "";
              txt(0, y, f.other.label + ":", "r", 8.4);
              var ox = F.r.widthOfTextAtSize(f.other.label + ":", 8.4) + 6;
              line(ox, y + 2.5, maxW, y + 2.5, 0.6);
              if (ov) txt(ox + 3, y, ov, "r", 8.6);
              y += 11;
            }
          } else if (f.type === "signature") {
            y += 9.5; txt(0, y, f.label, "b", 8.8); y += 4;
            var base = y + 36;
            line(0, base, Math.min(maxW, 200), base, 0.8);
            var im = sigImg[f.k];
            if (im) {
              var iw = Math.min(180, im.width * (30 / im.height));
              img(2, base - 30 * (im.height / im.height) - 0, iw, 30, f.k);
            }
            y = base + 9;
          } else if (f.type === "file") {
            y += 9.5; txt(0, y, f.label, "b", 8.6); y += 5;
            var att = attByName[f._fileName || ""];
            var fn = f._fileName;
            if (fn) {
              var note = att ? ("Attached: " + fn + (att.count > 1 ? " (pages " + att.from + "–" + (att.from + att.count - 1) + ")" : " (page " + att.from + ")") + ")") : ("Attached: " + fn + ")");
              txt(10, y, note, "i", 8.2);
              y += 11;
            } else { line(10, y + 2.5, maxW, y + 2.5, 0.5); y += 11; }
          } else {
            /* text / tel / email / date row */
            var vTop = y;
            var lLines = wrap(f.label, F.b, 8.6, LBL - 10);
            var valStr = (f.type === "date" && v) ? fmtD(v) : v;
            var vLines = wrap(valStr, F.r, 8.8, maxW - LBL - 8);
            var rows = Math.max(lLines.length, vLines.length, 1);
            for (var ri = 0; ri < rows; ri++) {
              y += 10.5;
              if (lLines[ri] != null) txt(0, y, lLines[ri], "b", 8.6);
              if (vLines[ri] != null && vLines[ri] !== "") txt(LBL, y, vLines[ri], "r", 8.8);
            }
            y += 3.5; line(LBL, y, maxW, y, 0.6); y += 6;
          }
        });
      });

      /* office use */
      if (def.officeUse) {
        y += 8;
        var boxTop = y + 2;
        y += 9.5; txt(4, y, "FOR OFFICE USE ONLY", "b", 8); y += 3;
        ["Received by / date", "Documents verified by", "Approved by / date", "RFID tag serial / remarks"].forEach(function (lab) {
          y += 11;
          txt(4, y, lab, "r", 8.2);
          line(120, y + 2.5, maxW - 4, y + 2.5, 0.5);
        });
        y += 5; rect(0, boxTop, maxW, y - boxTop + 3, 1);
        y += 8;
      }

      /* footer */
      y += 6; line(0, y, maxW, y, 0.5); y += 9;
      txt(0, y, def.estate + " — " + def.formNo, "r", 7.4);
      var fw = F.r.widthOfTextAtSize("info.kentishlodge.com", 7.4);
      txt(maxW - fw, y, "info.kentishlodge.com", "r", 7.4);
      y += 6;
      return { cmds: cmds, height: y };
    }
    function fmtD(s) { var p = String(s).split("-"); return p.length === 3 ? p[2] + "/" + p[1] + "/" + p[0] : s; }

    /* two-pass auto-fit (60–100%) */
    var scale = 1, lay;
    for (var pass = 0; pass < 6; pass++) {
      lay = layout(scale);
      var avail = H - 2 * M;
      if (lay.height <= avail) break;
      var next = Math.max(0.6, (avail - 6) / lay.height);
      if (Math.abs(next - scale) < 0.005) { scale = next; lay = layout(scale); break; }
      scale = next;
    }

    /* render page 1 */
    var page = doc.addPage([W, H]);
    function RX(x) { return M + x * scale; }
    function RY(yy) { return H - M - yy * scale; }
    lay.cmds.forEach(function (c) {
      if (c.t === "t") page.drawText(c.s, { x: RX(c.x), y: RY(c.y), size: c.z * scale, font: F[c.f], color: INK });
      else if (c.t === "l") page.drawLine({ start: { x: RX(c.x1), y: RY(c.y1) }, end: { x: RX(c.x2), y: RY(c.y2) }, thickness: Math.max(0.35, c.w * scale), color: RULE });
      else if (c.t === "r") page.drawRectangle({ x: RX(c.x), y: RY(c.y) - c.h * scale, width: c.w * scale, height: c.h * scale, borderColor: INK, borderWidth: Math.max(0.35, c.b * scale) });
      else if (c.t === "c") page.drawEllipse({ x: RX(c.x), y: RY(c.y), xScale: c.r * scale, yScale: c.r * scale, borderColor: INK, borderWidth: 0.7 * Math.max(scale, 0.6), color: c.f ? INK : undefined });
      else if (c.t === "e") page.drawEllipse({ x: RX(c.x), y: RY(c.y), xScale: c.r * scale, yScale: c.r * scale, borderColor: INK, borderWidth: 0.8 });
      else if (c.t === "i") {
        var im2 = sigImg[c.k]; if (!im2) return;
        var hh = c.h * scale, ww = Math.min(c.w * scale, im2.width * (hh / im2.height));
        page.drawImage(im2, { x: RX(c.x), y: RY(c.y) - hh, width: ww, height: hh });
      }
    });

    /* attachment pages */
    for (i = 0; i < atts.length; i++) {
      var at = atts[i];
      if (at.kind === "img") {
        var p2 = doc.addPage([W, H]);
        p2.drawText("Attachment — " + at.name, { x: M, y: H - M - 8, size: 9, font: F.i, color: RULE });
        var maxWw = W - 2 * M, maxHh = H - 2 * M - 40;
        var sc = Math.min(maxWw / at.img.width, maxHh / at.img.height);
        var iw2 = at.img.width * sc, ih2 = at.img.height * sc;
        p2.drawImage(at.img, { x: (W - iw2) / 2, y: (H - ih2) / 2, width: iw2, height: ih2 });
      } else if (at.kind === "pdf") {
        var idxs = []; for (var pi = 0; pi < at.src.getPageCount(); pi++) idxs.push(pi);
        var copied = await doc.copyPages(at.src, idxs);
        copied.forEach(function (cp) { doc.addPage(cp); });
      } else {
        var p3 = doc.addPage([W, H]);
        p3.drawText("Attachment could not be embedded: " + at.name, { x: M, y: H / 2, size: 11, font: F.b, color: INK });
      }
    }

    var bytes = await doc.save();
    return { bytes: bytes, pages: doc.getPageCount(), scale: scale };
  }

  global.KLForms = { buildPdf: buildPdf };
})(typeof window !== "undefined" ? window : globalThis);
