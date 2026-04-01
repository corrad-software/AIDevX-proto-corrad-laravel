/**
 * KERISI User Chat — launcher for MYFIS2 / Corrad (legacy PHP) pages.
 *
 * Usage (before </body>):
 * <script
 *   src="https://YOUR-CMS-DOMAIN/kerisi-user-chat-launcher.js"
 *   data-cms-url="https://YOUR-CMS-DOMAIN"
 *   data-open-mode="overlay"
 *   defer
 * ></script>
 *
 * data-cms-url — Root URL of Laravel + Vue admin (KERISI Support).
 * data-open-mode — "overlay" (popup dalam halaman + iframe) | "popup" | "tab"
 * data-position — "fixed" (FAB kanan bawah, default) | "manual" — tiada FAB; guna window.KerisiUserChat.open()
 * data-label — aria-label FAB
 * data-overlay-title — tajuk bar panel overlay
 * data-overlay-max-width — contoh 420px atau 100%
 * data-overlay-max-height — contoh min(720px, 92vh)
 *
 * Untuk iframe: set KERISI_EMBED_IFRAME_ORIGINS pada server Laravel + SESSION_SAME_SITE=none (HTTPS).
 */
(function () {
  var script = document.currentScript;
  if (!script) return;

  var cmsBase = (script.getAttribute("data-cms-url") || "").replace(/\/$/, "");
  if (!cmsBase) {
    console.warn("[KerisiUserChat] Set data-cms-url on the script tag (root URL of KERISI admin).");
    return;
  }

  var label = script.getAttribute("data-label") || "Bantuan AI";
  var mode = (script.getAttribute("data-open-mode") || "popup").toLowerCase();
  var position = (script.getAttribute("data-position") || "fixed").toLowerCase();
  var path = "/admin/kerisi/user-chat?embed=1";
  var overlayTitle = script.getAttribute("data-overlay-title") || "AINA — User Chat";
  var overlayMaxW = script.getAttribute("data-overlay-max-width") || "420px";
  var overlayMaxH = script.getAttribute("data-overlay-max-height") || "min(720px, 92vh)";

  var overlayRoot = null;

  function chatUrl() {
    return cmsBase + path;
  }

  function closeOverlay() {
    if (!overlayRoot) return;
    if (overlayRoot.parentNode) overlayRoot.parentNode.removeChild(overlayRoot);
    overlayRoot = null;
  }

  function openOverlay() {
    if (overlayRoot) {
      closeOverlay();
      return;
    }

    var backdrop = document.createElement("div");
    backdrop.setAttribute("role", "dialog");
    backdrop.setAttribute("aria-modal", "true");
    backdrop.setAttribute("aria-label", overlayTitle);
    backdrop.style.cssText = [
      "position:fixed",
      "inset:0",
      "z-index:2147483645",
      "background:rgba(15,23,42,.5)",
      "display:flex",
      "align-items:flex-end",
      "justify-content:flex-end",
      "padding:12px",
      "padding-bottom:max(12px,env(safe-area-inset-bottom))",
      "padding-right:max(12px,env(safe-area-inset-right))",
      "box-sizing:border-box",
    ].join(";");

    var panel = document.createElement("div");
    panel.style.cssText = [
      "position:relative",
      "width:min(100%," + overlayMaxW + ")",
      "height:" + overlayMaxH,
      "max-height:92vh",
      "background:#fff",
      "border-radius:12px",
      "box-shadow:0 25px 50px -12px rgba(0,0,0,.4)",
      "overflow:hidden",
      "display:flex",
      "flex-direction:column",
    ].join(";");

    var header = document.createElement("div");
    header.style.cssText = [
      "flex-shrink:0",
      "display:flex",
      "align-items:center",
      "justify-content:space-between",
      "gap:8px",
      "padding:10px 12px",
      "background:#059669",
      "color:#fff",
      "font-family:system-ui,-apple-system,sans-serif",
      "font-size:14px",
      "font-weight:600",
    ].join(";");

    var titleEl = document.createElement("span");
    titleEl.textContent = overlayTitle;
    titleEl.style.flex = "1";
    titleEl.style.minWidth = "0";
    titleEl.style.overflow = "hidden";
    titleEl.style.textOverflow = "ellipsis";
    titleEl.style.whiteSpace = "nowrap";

    var closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.setAttribute("aria-label", "Tutup");
    closeBtn.style.cssText =
      "flex-shrink:0;border:none;background:transparent;color:#fff;cursor:pointer;font-size:24px;line-height:1;padding:2px 8px;border-radius:6px";
    closeBtn.innerHTML = "&times;";
    closeBtn.onmouseenter = function () {
      closeBtn.style.background = "rgba(255,255,255,.15)";
    };
    closeBtn.onmouseleave = function () {
      closeBtn.style.background = "transparent";
    };
    closeBtn.onclick = function (e) {
      e.stopPropagation();
      closeOverlay();
    };

    header.appendChild(titleEl);
    header.appendChild(closeBtn);

    var iframe = document.createElement("iframe");
    iframe.src = chatUrl();
    iframe.title = label;
    iframe.style.cssText = "border:0;flex:1;width:100%;min-height:0;background:#f8fafc";
    iframe.setAttribute(
      "sandbox",
      "allow-scripts allow-same-origin allow-forms allow-popups allow-modals allow-downloads",
    );

    panel.appendChild(header);
    panel.appendChild(iframe);
    backdrop.appendChild(panel);

    backdrop.addEventListener("click", function (e) {
      if (e.target === backdrop) closeOverlay();
    });

    overlayRoot = backdrop;
    document.body.appendChild(backdrop);
  }

  function openChat() {
    var url = chatUrl();
    if (mode === "overlay") {
      openOverlay();
      return;
    }
    if (mode === "tab") {
      window.open(url, "_blank", "noopener,noreferrer");
      return;
    }
    var w = Math.min(440, window.screen.availWidth - 40);
    var h = Math.min(720, window.screen.availHeight - 80);
    var left = Math.max(0, window.screen.availLeft + (window.screen.availWidth - w) / 2);
    var top = Math.max(0, window.screen.availTop + (window.screen.availHeight - h) / 2);
    window.open(
      url,
      "KerisiUserChat",
      "noopener,noreferrer,width=" +
        w +
        ",height=" +
        h +
        ",left=" +
        left +
        ",top=" +
        top +
        ",scrollbars=yes,resizable=yes",
    );
  }

  window.KerisiUserChat = {
    open: openChat,
    close: function () {
      if (mode === "overlay") closeOverlay();
    },
    url: chatUrl,
    isOpen: function () {
      return !!overlayRoot;
    },
  };

  if (position === "manual") return;

  var btn = document.createElement("button");
  btn.type = "button";
  btn.setAttribute("aria-label", label);
  btn.innerHTML =
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
  btn.style.cssText = [
    "position:fixed",
    "bottom:24px",
    "right:24px",
    "z-index:2147483646",
    "width:56px",
    "height:56px",
    "border-radius:9999px",
    "border:none",
    "cursor:pointer",
    "background:#059669",
    "color:#fff",
    "box-shadow:0 10px 25px rgba(0,0,0,.2)",
    "display:flex",
    "align-items:center",
    "justify-content:center",
    "transition:transform .15s ease,background .15s ease",
  ].join(";");

  btn.onmouseenter = function () {
    btn.style.transform = "scale(1.05)";
    btn.style.background = "#047857";
  };
  btn.onmouseleave = function () {
    btn.style.transform = "scale(1)";
    btn.style.background = "#059669";
  };

  btn.addEventListener("click", openChat);

  function mount() {
    document.body.appendChild(btn);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount);
  } else {
    mount();
  }
})();
