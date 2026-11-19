/* MME Form – Đăng nhập bằng Zalo (MMeAuth). Nút: [data-mme-login]; API: window.MMEFormLogin.start({ purpose, redirect }). */
(function () {
  "use strict";
  var cfg = window.MMEFormLogin || {};
  var PERSIST = "mme-login";
  var busy = false;

  function sdk() {
    return window.MMeAuth && typeof window.MMeAuth.prompt === "function" ? window.MMeAuth : null;
  }

  function showError(btn, msg) {
    var box = btn && btn.nextElementSibling && btn.nextElementSibling.classList.contains("mme-login-error") ? btn.nextElementSibling : null;
    if (box) {
      box.textContent = msg;
      box.hidden = false;
    } else if (msg) {
      window.alert(msg);
    }
  }

  function exchange(token, redirect) {
    return fetch(cfg.endpoint, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
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
    remember(redirect);
    var promptOpts = { site: cfg.site, purpose: opts.purpose === "register" ? "register" : "login", persist: PERSIST };
    if (opts.resume) promptOpts.resume = opts.resume;
    return auth.prompt(promptOpts)
      .then(function (r) { return exchange(r.token, redirect); })
      .then(function (data) {
        try { window.sessionStorage.removeItem(PERSIST + ":redirect"); } catch (e) {}
        if (opts.navigate !== false) window.location.assign(data.redirect || redirect);
        return data;
      })
      .finally(function () { busy = false; });
  }

  function onClick(e) {
    var btn = e.target.closest("[data-mme-login]");
    if (!btn) return;
    e.preventDefault();
    var label = btn.querySelector(".mme-login-label");
    var original = label ? label.textContent : "";
    showError(btn, "");
    start({ purpose: btn.getAttribute("data-purpose"), redirect: btn.getAttribute("data-redirect") })
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
