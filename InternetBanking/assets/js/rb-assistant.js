/**
 * Rural Banking System - "Mitra" help assistant with voice input and spoken answers.
 *  - typing OR speaking (browser speech recognition, Chrome/Edge/Android)
 *  - answers in the language picked with the language button (rb-i18n.js)
 *  - answers can be read aloud (browser speech synthesis)
 * The AI itself runs on the server (api/assistant.php); no key is ever in the browser.
 * (c) 2026 Soumya Das
 */
(function () {
  "use strict";
  var scriptEl = document.currentScript;
  var ENDPOINT = window.RB_ASSISTANT_ENDPOINT ||
    (scriptEl && scriptEl.src ? scriptEl.src.replace(/assets\/js\/rb-assistant\.js.*$/, "api/assistant.php") : "/InternetBanking/api/assistant.php");
  var LOCALE = { en: "en-IN", hi: "hi-IN", gu: "gu-IN", bn: "bn-IN", ta: "ta-IN", te: "te-IN", mr: "mr-IN",
                 kn: "kn-IN", ml: "ml-IN", pa: "pa-IN", or: "or-IN", ur: "ur-IN", as: "as-IN" };
  var SUGGESTIONS = ["How do I send money?", "What is a recurring deposit?", "How can I check my balance?", "I forgot my password"];

  var history = [], busy = false, listening = false, speakAnswers = false, recog = null;
  var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  var el = {};

  function lang() { try { return localStorage.getItem("rb_language") || "en"; } catch (e) { return "en"; } }

  function css() {
    var s = document.createElement("style");
    s.textContent =
      ".rb-ai{position:fixed;left:18px;bottom:18px;z-index:99998;font-family:'Plus Jakarta Sans',system-ui,sans-serif}" +
      ".rb-ai,.rb-ai *{box-sizing:border-box}.rb-ai button,.rb-ai input{font:inherit}" +
      ".rb-ai__fab{display:flex;align-items:center;gap:8px;padding:11px 16px;border:0;border-radius:999px;background:#f2c25b;color:#2a1d00;font-weight:700;font-size:14px;cursor:pointer;box-shadow:0 12px 28px -10px rgba(0,0,0,.55)}" +
      ".rb-ai__fab:hover{background:#e8b440}" +
      ".rb-ai__panel{position:absolute;left:0;bottom:56px;width:360px;max-width:calc(100vw - 28px);height:480px;max-height:calc(100vh - 100px);display:none;flex-direction:column;background:#fff;color:#14281f;border:1px solid #e6dfce;border-radius:20px;box-shadow:0 28px 60px -20px rgba(0,0,0,.5);overflow:hidden}" +
      ".rb-ai.open .rb-ai__panel{display:flex}" +
      ".rb-ai__head{display:flex;align-items:center;gap:10px;padding:13px 16px;background:linear-gradient(135deg,#1f6f4f,#0f4d38);color:#fff}" +
      ".rb-ai__head b{font-family:Fraunces,Georgia,serif;font-size:17px}.rb-ai__head small{display:block;opacity:.8;font-size:11.5px}" +
      ".rb-ai__head .sp{flex:1}" +
      ".rb-ai__x{border:0;background:rgba(255,255,255,.15);color:#fff;width:30px;height:30px;border-radius:9px;cursor:pointer}" +
      ".rb-ai__msgs{flex:1;overflow:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f7f3ea}" +
      ".rb-ai__m{max-width:86%;padding:10px 13px;border-radius:15px;font-size:14.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word}" +
      ".rb-ai__m.bot{align-self:flex-start;background:#fff;border:1px solid #e6dfce;border-bottom-left-radius:5px}" +
      ".rb-ai__m.me{align-self:flex-end;background:#0f4d38;color:#fff;border-bottom-right-radius:5px}" +
      ".rb-ai__m.typing{color:#86998f;font-style:italic}" +
      ".rb-ai__chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}" +
      ".rb-ai__chip{border:1.5px solid #e6dfce;background:#fff;border-radius:999px;padding:6px 11px;font-size:12.5px;font-weight:600;color:#0f4d38;cursor:pointer}" +
      ".rb-ai__chip:hover{border-color:#1f6f4f;background:#e3f1ea}" +
      ".rb-ai__bar{display:flex;gap:8px;align-items:center;padding:10px;border-top:1px solid #e6dfce;background:#fff}" +
      ".rb-ai__in{flex:1;min-width:0;height:42px;border:1.5px solid #e6dfce;border-radius:12px;padding:0 12px;font-size:14.5px;background:#fbfaf6}" +
      ".rb-ai__in:focus{outline:none;border-color:#1f6f4f;box-shadow:0 0 0 3px rgba(31,111,79,.14)}" +
      ".rb-ai__ic{width:42px;height:42px;border:0;border-radius:12px;cursor:pointer;background:#e3f1ea;color:#0f4d38;font-size:16px;flex:none}" +
      ".rb-ai__ic.send{background:#0f4d38;color:#fff}.rb-ai__ic.rec{background:#b3261e;color:#fff;animation:rbpulse 1s infinite}" +
      "@keyframes rbpulse{50%{opacity:.6}}" +
      ".rb-ai__foot{display:flex;justify-content:space-between;align-items:center;padding:0 12px 9px;background:#fff;font-size:11.5px;color:#5d7167}" +
      ".rb-ai__foot label{display:flex;gap:6px;align-items:center;cursor:pointer;margin:0;font-weight:600;font-size:11.5px;color:#5d7167}" +
      "@media(max-width:520px){.rb-ai{left:12px;bottom:12px}.rb-ai__fab span.t{display:none}}";
    document.head.appendChild(s);
  }

  function build() {
    var w = document.createElement("div");
    w.className = "rb-ai";
    w.setAttribute("data-no-translate", "true");
    w.innerHTML =
      '<div class="rb-ai__panel" role="dialog" aria-label="Help assistant">' +
        '<div class="rb-ai__head"><span style="font-size:22px">&#129309;</span><div><b>Mitra</b><small>Your banking helper</small></div><span class="sp"></span>' +
        '<button type="button" class="rb-ai__x" aria-label="Close">&#10005;</button></div>' +
        '<div class="rb-ai__msgs" aria-live="polite"></div>' +
        '<div class="rb-ai__bar">' +
          '<button type="button" class="rb-ai__ic mic" aria-label="Speak your question" title="Speak">&#127908;</button>' +
          '<input class="rb-ai__in" type="text" maxlength="500" placeholder="Ask a question\u2026" aria-label="Your question">' +
          '<button type="button" class="rb-ai__ic send" aria-label="Send">&#10148;</button>' +
        '</div>' +
        '<div class="rb-ai__foot"><label><input type="checkbox" class="spk"> Read answers aloud</label><span>AI can make mistakes</span></div>' +
      '</div>' +
      '<button type="button" class="rb-ai__fab" aria-expanded="false"><span aria-hidden="true">&#128172;</span><span class="t">Help</span></button>';
    document.body.appendChild(w);
    el.wrap = w;
    el.msgs = w.querySelector(".rb-ai__msgs");
    el.input = w.querySelector(".rb-ai__in");
    el.mic = w.querySelector(".mic");
    el.send = w.querySelector(".send");
    el.spk = w.querySelector(".spk");
    el.fab = w.querySelector(".rb-ai__fab");

    el.fab.addEventListener("click", function () { toggle(!w.classList.contains("open")); });
    w.querySelector(".rb-ai__x").addEventListener("click", function () { toggle(false); });
    el.send.addEventListener("click", function () { ask(el.input.value, false); });
    el.input.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); ask(el.input.value, false); } });
    el.spk.addEventListener("change", function () { speakAnswers = el.spk.checked; if (!speakAnswers) stopSpeaking(); });
    if (Recognition) { el.mic.addEventListener("click", toggleMic); } else { el.mic.style.display = "none"; }

    addBot("Namaste! I am Mitra. Ask me about accounts, sending money or deposits, by typing" + (Recognition ? " or by tapping the microphone." : "."));
    var chips = document.createElement("div");
    chips.className = "rb-ai__chips";
    SUGGESTIONS.forEach(function (q) {
      var c = document.createElement("button");
      c.type = "button"; c.className = "rb-ai__chip"; c.textContent = q;
      c.addEventListener("click", function () { chips.remove(); ask(q, false); });
      chips.appendChild(c);
    });
    el.msgs.appendChild(chips);
  }

  function toggle(open) {
    el.wrap.classList.toggle("open", open);
    el.fab.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) setTimeout(function () { el.input.focus(); }, 50); else { stopMic(); stopSpeaking(); }
  }

  function addMsg(text, cls) {
    var d = document.createElement("div");
    d.className = "rb-ai__m " + cls;
    d.textContent = text;
    el.msgs.appendChild(d);
    el.msgs.scrollTop = el.msgs.scrollHeight;
    return d;
  }
  function addBot(t) { return addMsg(t, "bot"); }

  function stopSpeaking() { if ("speechSynthesis" in window) window.speechSynthesis.cancel(); }
  function speak(text) {
    if (!("speechSynthesis" in window)) return;
    stopSpeaking();
    var u = new SpeechSynthesisUtterance(text);
    u.lang = LOCALE[lang()] || "en-IN"; u.rate = 0.92;
    window.speechSynthesis.speak(u);
  }

  function toggleMic() { if (listening) { stopMic(); } else { startMic(); } }
  function stopMic() { listening = false; el.mic.classList.remove("rec"); if (recog) { try { recog.stop(); } catch (e) {} } }
  function startMic() {
    stopSpeaking();
    recog = new Recognition();
    recog.lang = LOCALE[lang()] || "en-IN";
    recog.interimResults = false;
    recog.maxAlternatives = 1;
    recog.onresult = function (e) {
      var t = e.results[0][0].transcript;
      el.input.value = t;
      ask(t, true);
    };
    recog.onerror = function (e) {
      stopMic();
      addBot(e.error === "not-allowed" ? "Please allow microphone access in your browser to use voice." : "I could not hear you. Please try again or type your question.");
    };
    recog.onend = function () { stopMic(); };
    try { recog.start(); listening = true; el.mic.classList.add("rec"); } catch (e) { stopMic(); }
  }

  function ask(text, spoken) {
    text = (text || "").trim();
    if (!text || busy) return;
    busy = true;
    el.input.value = "";
    addMsg(text, "me");
    var typing = addMsg("Thinking\u2026", "bot typing");
    fetch(ENDPOINT, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
      body: JSON.stringify({ message: text, lang: lang(), history: history.slice(-6) })
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        var reply = d.reply || d.error || "Sorry, something went wrong.";
        typing.remove();
        addBot(reply);
        if (!d.fallback) { history.push({ role: "user", content: text }, { role: "assistant", content: reply }); }
        if (spoken || speakAnswers) speak(reply);
      })
      .catch(function () { typing.remove(); addBot("I could not connect. Please check your internet and try again."); })
      .then(function () { busy = false; });
  }

  function init() { css(); build(); }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init); else init();
})();
