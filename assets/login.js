/* MME Form – Đăng nhập bằng Zalo (MMeAuth). Nút: [data-mme-login] (đăng nhập), [data-mme-link] (liên kết Zalo cho tài khoản đang đăng nhập).
   API: window.MMEFormLogin.start({ purpose, redirect, link }). */
(function () {
  "use strict";
  var cfg = window.MMEFormLogin || {};
  var PERSIST = "mme-login";
  var busy = false;

  function sdk() {
    return window.MMeAuth && typeof window.MMeAuth.prompt === "function" ? window.MMeAuth : null;
  }

  function showError(btn, msg) {
    var box = null;
    if (btn) {
      var next = btn.nextElementSibling || (btn.parentElement && btn.parentElement.nextElementSibling);
      if (next && next.classList.contains("mme-login-error")) box = next;
    }
    if (box) {
      box.textContent = msg;
      box.hidden = false;
    } else if (msg) {
      window.alert(msg);
    }
  }

  function exchange(token, redirect, link) {
    var headers = { "Content-Type": "application/json" };
    if (link && cfg.nonce) headers["X-WP-Nonce"] = cfg.nonce;
    return fetch(link ? cfg.linkEndpoint : cfg.endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: headers,
      body: JSON.stringify({ token: token, redirect: redirect || window.location.href }),
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) throw new Error((data && data.message) || cfg.i18n.error);
        return data;
      });
    });
  }

  function remember(redirect) {
    try { window.sessionStorage.setItem(PERSIST + ":redirect", redirect || ""); } catch (e) {}
  }

  function recall() {
    try { return window.sessionStorage.getItem(PERSIST + ":redirect") || ""; } catch (e) { return ""; }
  }

  /** Mở popup xác minh rồi đăng nhập. Trả về Promise dữ liệu đăng nhập (đã chuyển trang nếu có redirect). */
  function start(opts) {
    opts = opts || {};
    var auth = sdk();
    if (!auth || !cfg.site) return Promise.reject(new Error(cfg.i18n ? cfg.i18n.error : "MMeAuth chưa sẵn sàng"));
    if (busy) return Promise.reject(new Error("busy"));
    busy = true;
    var redirect = opts.redirect || window.location.href;
    if (!opts.link) remember(redirect);
    var promptOpts = { site: cfg.site, purpose: opts.purpose === "register" ? "register" : "login", persist: opts.link ? PERSIST + "-link" : PERSIST };
    if (opts.resume) promptOpts.resume = opts.resume;
    return auth.prompt(promptOpts)
      .then(function (r) { return exchange(r.token, redirect, !!opts.link); })
      .then(function (data) {
        try { window.sessionStorage.removeItem(PERSIST + ":redirect"); } catch (e) {}
        if (opts.link) {
          if (data.moved_from && data.moved_from.length) window.alert("Đã chuyển liên kết Zalo từ tài khoản: " + data.moved_from.join(", "));
          window.location.reload();
        } else if (opts.navigate !== false) window.location.assign(data.redirect || redirect);
        return data;
      })
      .finally(function () { busy = false; });
  }

  function onClick(e) {
    var btn = e.target.closest("[data-mme-login],[data-mme-link]");
    if (!btn) return;
    e.preventDefault();
    var label = btn.querySelector(".mme-login-label");
    var original = label ? label.textContent : "";
    showError(btn, "");
    start({ purpose: btn.getAttribute("data-purpose"), redirect: btn.getAttribute("data-redirect"), link: btn.hasAttribute("data-mme-link") })
      .then(function () {
        if (label) label.textContent = cfg.i18n.working;
        btn.disabled = true;
      })
      .catch(function (err) {
        if (label) label.textContent = original;
        if (err && (err.code === "cancelled" || err.message === "busy")) return;
        showError(btn, (err && err.message) || cfg.i18n.error);
      });
  }

  document.addEventListener("click", onClick);

  // Điện thoại: trang tải lại khi khách chuyển sang Zalo → chờ tiếp phiên đang dở.
  function resume() {
    var auth = sdk();
    if (!auth || typeof auth.pending !== "function") return;
    var pending = auth.pending(PERSIST);
    if (pending) {
      start({ resume: pending, redirect: recall() }).catch(function () {});
    }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", resume);
  else resume();

  cfg.start = start;
  window.MMEFormLogin = cfg;
})();
