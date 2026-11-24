/**
 * Rural Banking System - multilingual UI (13 Indian languages) + read-aloud.
 * Adapted from the original script supplied by the project owner:
 *  - talks to api/translate.php (Groq runs on the server, the key never reaches the browser)
 *  - injects its own language button, so pages need only ONE <script> tag
 *  - translates interface text only; customer data (tables, names, numbers) is never sent
 * (c) 2026 Soumya Das
 */
(function () {
  "use strict";

  var LANGUAGES = [
    { code: "en", native: "English" }, { code: "hi", native: "हिंदी" }, { code: "gu", native: "ગુજરાતી" },
    { code: "bn", native: "বাংলা" },   { code: "ta", native: "தமிழ்" }, { code: "te", native: "తెలుగు" },
    { code: "mr", native: "मराठी" },   { code: "kn", native: "ಕನ್ನಡ" }, { code: "ml", native: "മലയാളം" },
    { code: "pa", native: "ਪੰਜਾਬੀ" },  { code: "or", native: "ଓଡ଼ିଆ" },  { code: "ur", native: "اردو" },
    { code: "as", native: "অসমীয়া" }
  ];
  var SPEECH_LOCALE = { en: "en-IN", hi: "hi-IN", gu: "gu-IN", bn: "bn-IN", ta: "ta-IN", te: "te-IN", mr: "mr-IN",
                        kn: "kn-IN", ml: "ml-IN", pa: "pa-IN", or: "or-IN", ur: "ur-IN", as: "as-IN" };
  var LANG_SET = {};
  LANGUAGES.forEach(function (l) { LANG_SET[l.code] = true; });

  var STORAGE_KEY = "rb_language";
  var CACHE_PREFIX = "rb_tr_v1_";
  var MAX_PER_BATCH = 50, MAX_UNCACHED = 600, MAX_PARALLEL = 3, MAX_NODES = 3000;

  var scriptEl = document.currentScript;
  var ENDPOINT = window.RB_TRANSLATE_ENDPOINT ||
    (scriptEl && scriptEl.src ? scriptEl.src.replace(/assets\/js\/rb-i18n\.js.*$/, "api/translate.php") : "/InternetBanking/api/translate.php");

  /* Portals: only static interface elements are translated (privacy by design).
     Public pages (landing / sign-in) set <body data-i18n-scope="all">. */
  var PORTAL_SELECTORS = [
    ".main-sidebar .nav-link p", ".main-sidebar .nav-header", ".card-title", ".card-header h3", "label",
    "thead th", ".btn", "button", ".info-box-text", ".small-box p", ".description-text", ".main-footer",
    ".login-box-msg", ".content-header h1.rb-static"
  ].join(",");
  var ALWAYS_SKIP = "script,style,noscript,code,pre,iframe,textarea,select,option,svg,[data-no-translate],.rb-i18n,.swal-overlay,.sweet-alert";
  var PORTAL_SKIP = "tbody,td,address,.user-panel,.info-box-number,.breadcrumb,.dataTables_info,.dataTables_paginate";

  var originalText = new Map();       // text node -> {pre, core, post}
  var originalPlaceholder = new Map();
  var currentLang = "en";
  var busy = false;

  function isPublicScope() { return (document.body.getAttribute("data-i18n-scope") || "") === "all"; }
  function normalize(s) { return s.replace(/\s+/g, " ").trim(); }

  /* Anything that looks like personal / financial data stays on the device. */
  function isSensitive(t) { return /\d{3,}/.test(t) || t.indexOf("@") !== -1 || /https?:\/\//i.test(t); }
  function shouldTranslate(t) {
    var n = normalize(t);
    return n.length > 0 && n.length <= 800 && /[A-Za-z]/.test(n) && !isSensitive(n);
  }

  function inScope(el) {
    if (!el || el.closest(ALWAYS_SKIP)) return false;
    if (!isPublicScope() && el.closest(PORTAL_SKIP)) return false;
    return true;
  }

  function collectRoots() {
    if (isPublicScope()) return [document.body];
    var roots = [], seen = new Set();
    document.querySelectorAll(PORTAL_SELECTORS).forEach(function (el) {
      if (!seen.has(el)) { seen.add(el); roots.push(el); }
    });
    return roots;
  }

  function collectTextNodes() {
    var out = [], seen = new Set();
    collectRoots().forEach(function (root) {
      if (out.length >= MAX_NODES || !inScope(root)) return;
      var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
      while (walker.nextNode() && out.length < MAX_NODES) {
        var node = walker.currentNode;
        if (seen.has(node) || !node.parentElement || !inScope(node.parentElement)) continue;
        var rec = originalText.get(node);
        if (!rec) {
          var v = node.nodeValue || "";
          var m = v.match(/^(\s*)([\s\S]*?)(\s*)$/);
          rec = { pre: m[1], core: m[2], post: m[3] };
          originalText.set(node, rec);
        }
        if (!shouldTranslate(rec.core)) continue;
        seen.add(node);
        out.push({ node: node, rec: rec });
      }
    });
    return out;
  }

  function collectPlaceholders() {
    var out = [];
    document.querySelectorAll("input[placeholder], textarea[placeholder]").forEach(function (f) {
      if (!inScope(f)) return;
      var original = originalPlaceholder.get(f);
      if (original === undefined) { original = f.getAttribute("placeholder") || ""; originalPlaceholder.set(f, original); }
      if (shouldTranslate(original)) out.push({ el: f, original: original });
    });
    return out;
  }

  /* ---- caches -------------------------------------------------------- */
  function loadCache(lang) { try { return JSON.parse(localStorage.getItem(CACHE_PREFIX + lang) || "{}"); } catch (e) { return {}; } }
  function saveCache(lang, data) { try { localStorage.setItem(CACHE_PREFIX + lang, JSON.stringify(data)); } catch (e) {} }

  /* ---- UI ------------------------------------------------------------ */
  var ui = {};
  function injectStyles() {
    var css =
      ".rb-i18n{position:fixed;right:18px;bottom:18px;z-index:99999;font-family:'Plus Jakarta Sans',system-ui,sans-serif}" +
      ".rb-i18n button{font:inherit;cursor:pointer}" +
      ".rb-i18n__fab{display:flex;align-items:center;gap:8px;padding:11px 16px;border:0;border-radius:999px;background:#0f4d38;color:#fff;font-weight:700;font-size:14px;box-shadow:0 12px 28px -10px rgba(0,0,0,.55)}" +
      ".rb-i18n__fab:hover{background:#1f6f4f}" +
      ".rb-i18n__panel{position:absolute;right:0;bottom:56px;width:272px;max-height:min(70vh,440px);overflow:auto;background:#fff;color:#14281f;border:1px solid #e6dfce;border-radius:18px;box-shadow:0 24px 50px -18px rgba(0,0,0,.45);padding:12px;display:none}" +
      ".rb-i18n.open .rb-i18n__panel{display:block}" +
      ".rb-i18n__title{font-weight:700;font-size:13px;color:#5d7167;margin:2px 6px 8px;display:flex;justify-content:space-between;align-items:center}" +
      ".rb-i18n__grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}" +
      ".rb-i18n__opt{display:flex;justify-content:space-between;align-items:center;padding:9px 11px;border:1.5px solid #e6dfce;border-radius:11px;background:#fbfaf6;font-size:14px;color:#14281f;text-align:left}" +
      ".rb-i18n__opt small{color:#86998f;font-size:10px}" +
      ".rb-i18n__opt:hover{border-color:#1f6f4f}" +
      ".rb-i18n__opt.active{background:#0f4d38;border-color:#0f4d38;color:#fff}.rb-i18n__opt.active small{color:#f2c25b}" +
      ".rb-i18n__tts{border:1.5px solid #e6dfce;background:#fff;border-radius:10px;padding:5px 10px;font-size:12px;font-weight:700;color:#0f4d38}" +
      ".rb-i18n__tts.on{background:#f2c25b;border-color:#f2c25b}" +
      ".rb-i18n__toast{position:absolute;right:0;bottom:56px;white-space:nowrap;background:#14281f;color:#fff;border-radius:12px;padding:9px 14px;font-size:13px;display:none}" +
      ".rb-i18n__toast.show{display:block}" +
      ".rb-i18n.open .rb-i18n__toast{display:none}" +
      "@media(max-width:520px){.rb-i18n{right:12px;bottom:12px}.rb-i18n__fab span.t{display:none}}";
    var st = document.createElement("style");
    st.textContent = css;
    document.head.appendChild(st);
  }

  function buildWidget() {
    var wrap = document.createElement("div");
    wrap.className = "rb-i18n";
    wrap.setAttribute("data-no-translate", "true");
    wrap.innerHTML =
      '<div class="rb-i18n__toast" role="status" aria-live="polite"></div>' +
      '<div class="rb-i18n__panel" role="dialog" aria-label="Choose language">' +
        '<div class="rb-i18n__title"><span>Choose language</span><button type="button" class="rb-i18n__tts" aria-label="Read this page aloud">&#128266; Listen</button></div>' +
        '<div class="rb-i18n__grid"></div>' +
      '</div>' +
      '<button type="button" class="rb-i18n__fab" aria-haspopup="dialog" aria-expanded="false"><span aria-hidden="true">&#127760;</span><span class="t"></span></button>';
    document.body.appendChild(wrap);

    ui.wrap = wrap;
    ui.fab = wrap.querySelector(".rb-i18n__fab");
    ui.fabLabel = wrap.querySelector(".rb-i18n__fab .t");
    ui.grid = wrap.querySelector(".rb-i18n__grid");
    ui.toast = wrap.querySelector(".rb-i18n__toast");
    ui.tts = wrap.querySelector(".rb-i18n__tts");

    LANGUAGES.forEach(function (l) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "rb-i18n__opt";
      b.setAttribute("data-lang", l.code);
      b.innerHTML = "<span>" + l.native + "</span><small>" + l.code.toUpperCase() + "</small>";
      b.addEventListener("click", function () { closePanel(); applyLanguage(l.code); });
      ui.grid.appendChild(b);
    });

    ui.fab.addEventListener("click", function () {
      var open = !wrap.classList.contains("open");
      wrap.classList.toggle("open", open);
      ui.fab.setAttribute("aria-expanded", open ? "true" : "false");
    });
    document.addEventListener("click", function (e) { if (!wrap.contains(e.target)) closePanel(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") closePanel(); });
    ui.tts.addEventListener("click", toggleSpeech);
  }

  function closePanel() { ui.wrap.classList.remove("open"); ui.fab.setAttribute("aria-expanded", "false"); }

  function markActive(lang) {
    ui.grid.querySelectorAll(".rb-i18n__opt").forEach(function (b) { b.classList.toggle("active", b.getAttribute("data-lang") === lang); });
    var l = LANGUAGES.filter(function (x) { return x.code === lang; })[0];
    ui.fabLabel.textContent = l ? l.native : "English";
  }

  var toastTimer;
  function toast(message, sticky) {
    ui.toast.textContent = message;
    ui.toast.classList.add("show");
    clearTimeout(toastTimer);
    if (!sticky) toastTimer = setTimeout(function () { ui.toast.classList.remove("show"); }, 3000);
  }
  function hideToast() { clearTimeout(toastTimer); ui.toast.classList.remove("show"); }

  /* ---- networking ---------------------------------------------------- */
  function requestTranslations(texts, lang, attempt) {
    attempt = attempt || 0;
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, attempt === 0 ? 30000 : 45000);
    return fetch(ENDPOINT, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
      body: JSON.stringify({ target_lang: lang, texts: texts }),
      signal: controller.signal
    }).then(function (res) {
      if (!res.ok && res.status !== 429) throw new Error("HTTP " + res.status);
      return res.json();
    }).catch(function (err) {
      if (err && err.name === "AbortError" && attempt === 0 && texts.length > 1) {
        var mid = Math.ceil(texts.length / 2);
        return Promise.all([requestTranslations(texts.slice(0, mid), lang, 1), requestTranslations(texts.slice(mid), lang, 1)])
          .then(function (r) {
            return { fallback: !!(r[0].fallback || r[1].fallback), translations: (r[0].translations || []).concat(r[1].translations || []) };
          });
      }
      return { fallback: true, translations: texts };
    }).then(function (data) { clearTimeout(timer); return data; });
  }

  function chunk(arr, size) { var out = []; for (var i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size)); return out; }

  /* ---- apply --------------------------------------------------------- */
  function restoreEnglish() {
    collectTextNodes().forEach(function (it) { it.node.nodeValue = it.rec.pre + it.rec.core + it.rec.post; });
    collectPlaceholders().forEach(function (it) { it.el.setAttribute("placeholder", it.original); });
  }

  function applyLanguage(lang) {
    if (!LANG_SET[lang]) lang = "en";
    if (busy) return Promise.resolve();
    try { localStorage.setItem(STORAGE_KEY, lang); } catch (e) {}
    currentLang = lang;
    document.documentElement.setAttribute("lang", lang);
    document.documentElement.setAttribute("dir", lang === "ur" ? "rtl" : "ltr");
    markActive(lang);
    stopSpeech();

    if (lang === "en") { restoreEnglish(); hideToast(); return Promise.resolve(); }

    var nodes = collectTextNodes(), holders = collectPlaceholders(), cache = loadCache(lang);
    var entries = nodes.map(function (n) { return { kind: "t", node: n.node, rec: n.rec, src: normalize(n.rec.core) }; })
      .concat(holders.map(function (h) { return { kind: "p", el: h.el, src: normalize(h.original) }; }));

    var missing = [], seen = {};
    entries.forEach(function (e) { if (!cache[e.src] && !seen[e.src]) { seen[e.src] = 1; missing.push(e.src); } });

    busy = true;
    toast("Translating\u2026", true);
    var failed = false;

    function paint() {
      entries.forEach(function (e) {
        var out = cache[e.src] || e.src;
        if (e.kind === "t") e.node.nodeValue = e.rec.pre + out + e.rec.post;
        else e.el.setAttribute("placeholder", out);
      });
    }
    paint(); // instantly show everything we already know

    var batches = chunk(missing.slice(0, MAX_UNCACHED), MAX_PER_BATCH);
    var chain = Promise.resolve();
    for (var i = 0; i < batches.length; i += MAX_PARALLEL) {
      (function (group) {
        chain = chain.then(function () {
          return Promise.all(group.map(function (b) { return requestTranslations(b, lang); })).then(function (results) {
            results.forEach(function (res, gi) {
              if (res.fallback) failed = true;
              var list = res.translations || [];
              group[gi].forEach(function (src, k) { if (list[k] && list[k] !== src) cache[src] = list[k]; });
            });
            paint();
          });
        });
      })(batches.slice(i, i + MAX_PARALLEL));
    }
    return chain.then(function () {
      saveCache(lang, cache);
      if (failed) toast("Some text could not be translated right now.");
      else hideToast();
    }).catch(function () { toast("Translation is busy. Please try again."); })
      .then(function () { busy = false; });
  }

  /* ---- read aloud ---------------------------------------------------- */
  function pageText() {
    var parts = [], limit = 2500, total = 0;
    var heads = isPublicScope() ? document.querySelectorAll("main h1,main h2,main p,h1,h2,h3,.lead,.feat p,.steps p,label") : document.querySelectorAll(".content-header h1,.card-title,label,.info-box-text");
    heads.forEach(function (el) {
      if (!inScope(el)) return;
      var t = normalize(el.textContent || "");
      if (t && !isSensitive(t) && total < limit) { parts.push(t); total += t.length; }
    });
    return parts.join(". ");
  }
  function stopSpeech() { if ("speechSynthesis" in window) window.speechSynthesis.cancel(); if (ui.tts) ui.tts.classList.remove("on"); }
  function toggleSpeech() {
    if (!("speechSynthesis" in window)) { toast("Your browser cannot read aloud."); return; }
    if (window.speechSynthesis.speaking) { stopSpeech(); return; }
    var text = pageText();
    if (!text) return;
    var u = new SpeechSynthesisUtterance(text);
    u.lang = SPEECH_LOCALE[currentLang] || "en-IN";
    u.rate = 0.92;
    u.onend = u.onerror = function () { ui.tts.classList.remove("on"); };
    ui.tts.classList.add("on");
    window.speechSynthesis.speak(u);
  }

  function defaultLanguage() {
    var saved = null;
    try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) {}
    if (saved && LANG_SET[saved]) return saved;
    return "en"; // never switch language behind the user's back
  }

  function init() {
    injectStyles();
    buildWidget();
    var lang = defaultLanguage();
    markActive(lang);
    if (lang !== "en") applyLanguage(lang);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
})();
