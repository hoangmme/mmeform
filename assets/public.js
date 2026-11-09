(function () {
  "use strict";

  function emitTracking(name, detail) {
    var safeDetail = {
      form_id: detail.form_id,
      page_host: detail.page_host || "",
      page_path: detail.page_path || "",
      event_id: detail.event_id || "",
    };

    if (Array.isArray(window.dataLayer)) {
      window.dataLayer.push(Object.assign({ event: name }, safeDetail));
    }
    if (typeof window.gtag === "function") {
      window.gtag("event", name, safeDetail);
    }
    if (typeof window.fbq === "function") {
      window.fbq("trackCustom", name === "mme_form_submit_success" ? "MMEFormSubmit" : "MMEFormView", safeDetail);
    }
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: "MME_FORM_EVENT", name: name, detail: safeDetail }, "*");
    }
  }

  function sourceParts(sourceUrl) {
    try {
      var url = new URL(sourceUrl);
      return { page_host: url.host, page_path: url.pathname };
    } catch (error) {
      return { page_host: "", page_path: "" };
    }
  }

  function attribution(sourceUrl) {
    var output = {};
    try {
      var params = new URL(sourceUrl).searchParams;
      ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "gclid", "fbclid"].forEach(function (key) {
        if (params.has(key)) output[key] = params.get(key);
      });
    } catch (error) {
      return output;
    }
    return output;
  }

  function getInitialSourceUrl() {
    var params = new URLSearchParams(window.location.search);
    return params.get("mme_parent_url") || document.referrer || window.location.href;
  }

  function resizeParent(formId) {
    if (!window.parent || window.parent === window) return;
    var height = Math.ceil(document.documentElement.scrollHeight);
    window.parent.postMessage({ type: "MME_FORM_RESIZE", formId: formId, height: height }, "*");
  }

  function openChat(shell, sourceUrl) {
    var button = shell.querySelector(".mme-form-chat-toggle");
    var panel = shell.querySelector(".mme-form-chat-panel");
    if (!button || !panel) return;

    button.addEventListener("click", function () {
      if (!panel.querySelector("iframe")) {
        var base = String(button.dataset.chatBase || "").replace(/\/$/, "");
        var tenant = button.dataset.chatTenant || "";
        var iframe = document.createElement("iframe");
        iframe.title = "MME AI Support";
        iframe.loading = "lazy";
        iframe.allow = "clipboard-write";
        iframe.src = base + "/embed/" + encodeURIComponent(tenant) +
          "?parentOrigin=" + encodeURIComponent(new URL(sourceUrl).origin) +
          "&parentUrl=" + encodeURIComponent(sourceUrl);
        panel.appendChild(iframe);
      }
      panel.hidden = !panel.hidden;
      button.setAttribute("aria-expanded", panel.hidden ? "false" : "true");
      resizeParent(shell.dataset.formId);
    });
  }

  function showStatus(status, kind, message) {
    status.className = "mme-form-status" + (kind ? " " + kind : "");
    status.textContent = message;
  }

  function decodeJwtPayload(token) {
    try {
      var base64 = token.split(".")[1].replace(/-/g, "+").replace(/_/g, "/");
      var json = decodeURIComponent(atob(base64).split("").map(function (c) {
        return "%" + ("00" + c.charCodeAt(0).toString(16)).slice(-2);
      }).join(""));
      return JSON.parse(json);
    } catch (error) {
      return null;
    }
  }

  /**
   * "Xác minh bằng Zalo" qua MMeAuth: tạo phiên ở gateway → QR (máy tính) hoặc mở Zalo (điện thoại)
   * → chờ khách xác nhận trong Mini App → nhận id_token. Máy chủ WordPress tự kiểm tra chữ ký token;
   * dữ liệu giải mã ở đây chỉ để hiển thị và điền sẵn SĐT/họ tên.
   */
  function setupMMeAuth(form, formId, fillPhone) {
    var gateway = form.dataset.mmeauthUrl;
    var siteKey = form.dataset.mmeauthSite;
    var box = form.querySelector(".mme-auth-box");
    if (!gateway || !siteKey || !box) return null;

    var tokenInput = box.querySelector('input[name="mmeauth_token"]');
    var signedOut = box.querySelector(".mme-auth-signed-out");
    var signedIn = box.querySelector(".mme-auth-signed-in");
    var startBtn = box.querySelector(".mme-auth-start");
    var pending = box.querySelector(".mme-auth-pending");
    var qrEl = box.querySelector(".mme-auth-qr");
    var openLink = box.querySelector(".mme-auth-open");
    var errorEl = box.querySelector(".mme-auth-error");
    var phoneInputs = form.querySelectorAll('input[type="tel"]');
    var nameInput = form.querySelector('[name="fields[full_name]"], [name="fields[name]"], [name="fields[ho_ten]"]');
    var isMobile = window.matchMedia && window.matchMedia("(pointer: coarse)").matches;
    var session = null;
    var pollTimer = null;

    function showError(message) {
      errorEl.textContent = message;
      errorEl.hidden = !message;
      resizeParent(formId);
    }

    function stopPolling() {
      if (pollTimer) clearTimeout(pollTimer);
      pollTimer = null;
      session = null;
    }

    function showStart() {
      stopPolling();
      pending.hidden = true;
      startBtn.hidden = false;
      startBtn.disabled = false;
      resizeParent(formId);
    }

    function reset() {
      showStart();
      tokenInput.value = "";
      box.dataset.state = "signed-out";
      signedIn.hidden = true;
      signedOut.hidden = false;
      phoneInputs.forEach(function (input) { input.readOnly = false; });
      resizeParent(formId);
    }

    function onVerified(idToken, profile) {
      var claims = decodeJwtPayload(idToken) || {};
      stopPolling();
      tokenInput.value = idToken;
      box.dataset.state = "signed-in";
      box.querySelector(".mme-auth-name").textContent = (profile && profile.name) || claims.name || "Đã xác minh";
      box.querySelector(".mme-auth-phone").textContent = "Đã xác minh qua Zalo · " + ((profile && profile.phone_masked) || "");
      var avatar = box.querySelector(".mme-auth-avatar");
      var picture = (profile && profile.avatar) || claims.picture || "";
      avatar.hidden = !picture;
      if (picture) avatar.src = picture;
      if (claims.phone) {
        fillPhone(claims.phone);
        phoneInputs.forEach(function (input) { input.readOnly = true; });
      }
      if (nameInput && !nameInput.value && claims.name) nameInput.value = claims.name;
      showError("");
      pending.hidden = true;
      signedOut.hidden = true;
      signedIn.hidden = false;
      resizeParent(formId);
    }

    function poll() {
      if (!session) return;
      var current = session;
      fetch(gateway + "/api/sessions/" + encodeURIComponent(current.sid) + "/status", {
        headers: { Authorization: "Bearer " + current.poll_token },
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (session !== current) return;
          if (data.status === "verified" && data.id_token) {
            onVerified(data.id_token, data.profile);
          } else if (data.status === "pending") {
            pollTimer = setTimeout(poll, (current.poll_interval || 2) * 1000);
          } else {
            showStart();
            showError(data.status === "expired" ? "Mã xác minh đã hết hạn, vui lòng bấm xác minh lại." : (data.error || "Không xác minh được, vui lòng thử lại."));
          }
        })
        .catch(function () {
          // Mất mạng tạm thời (điện thoại chuyển sang Zalo) — thử lại chậm hơn
          if (session === current) pollTimer = setTimeout(poll, 4000);
        });
    }

    startBtn.addEventListener("click", function () {
      showError("");
      startBtn.disabled = true;
      fetch(gateway + "/api/sessions", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ site_key: siteKey, purpose: "form" }),
      })
        .then(function (res) {
          return res.json().then(function (data) {
            if (!res.ok) throw new Error(data.error || "Không tạo được phiên xác minh.");
            return data;
          });
        })
        .then(function (data) {
          session = data;
          openLink.href = data.link;
          qrEl.innerHTML = "";
          if (!isMobile) {
            // SVG dạng ảnh: không chạy được script dù nội dung đến từ máy chủ khác
            var img = document.createElement("img");
            img.alt = "Mã QR xác minh Zalo";
            img.src = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(data.qr_svg);
            qrEl.appendChild(img);
          }
          qrEl.hidden = isMobile;
          box.querySelector(".mme-auth-qr-note").hidden = isMobile;
          openLink.hidden = !isMobile;
          startBtn.hidden = true;
          pending.hidden = false;
          resizeParent(formId);
          pollTimer = setTimeout(poll, (data.poll_interval || 2) * 1000);
        })
        .catch(function (error) {
          startBtn.disabled = false;
          showError(error && error.message ? error.message : "Không kết nối được MMeAuth, vui lòng thử lại.");
        });
    });

    box.querySelector(".mme-auth-cancel").addEventListener("click", showStart);
    box.querySelector(".mme-auth-switch").addEventListener("click", reset);
    document.addEventListener("visibilitychange", function () {
      // Khách quay lại từ Zalo: hỏi kết quả ngay thay vì chờ hết nhịp poll
      if (document.visibilityState === "visible" && session) {
        if (pollTimer) clearTimeout(pollTimer);
        poll();
      }
    });

    return {
      credential: function () { return tokenInput.value; },
      reset: reset,
      showError: showError,
    };
  }

  function setupForm(shell) {
    var form = shell.querySelector("form.mme-form");
    if (!form) return;

    var sourceUrl = getInitialSourceUrl();
    var currentInput = form.querySelector('[name="current_url"]');
    var referrerInput = form.querySelector('[name="referrer_url"]');
    var startedInput = form.querySelector('[name="started_at"]');
    var status = form.querySelector(".mme-form-status");
    var submit = form.querySelector(".mme-form-submit");
    var formId = shell.dataset.formId;

    currentInput.value = currentInput.value || sourceUrl;
    referrerInput.value = document.referrer || "";
    startedInput.value = String(Date.now());
    sourceUrl = currentInput.value;

    var itiInstances = [];
    form.querySelectorAll('input[type="tel"]').forEach(function (telInput) {
      if (window.intlTelInput) {
        var iti = window.intlTelInput(telInput, {
          initialCountry: "vn",
          showSelectedDialCode: false,
          nationalMode: true,
          utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/23.0.4/js/utils.js"
        });
        itiInstances.push({ input: telInput, iti: iti });
      }
    });

    openChat(shell, sourceUrl);
    emitTracking("mme_form_view", Object.assign({ form_id: formId }, sourceParts(sourceUrl)));

    var auth = setupMMeAuth(form, formId, function (phone) {
      form.querySelectorAll('input[type="tel"]').forEach(function (input) {
        var instance = itiInstances.find(function (item) { return item.input === input; });
        if (instance) instance.iti.setNumber(phone);
        else input.value = phone;
      });
    });

    var parentOrigin = "";
    try {
      parentOrigin = new URL(new URLSearchParams(window.location.search).get("mme_parent_url") || "").origin;
    } catch (error) {
      parentOrigin = "";
    }
    window.addEventListener("message", function (event) {
      if (event.source !== window.parent || !parentOrigin || event.origin !== parentOrigin) return;
      var data = event.data || {};
      if (data.type === "MME_FORM_CONTEXT" && data.formId === formId && data.url) {
        currentInput.value = data.url;
        sourceUrl = data.url;
      }
    });

    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      showStatus(status, "", "");
      if (auth) auth.showError("");
      if (!form.reportValidity()) return;

      // isValidNumber() trả về null khi thư viện utils chưa tải xong — khi đó không chặn khách
      var badPhone = itiInstances.find(function (instance) {
        return instance.input.value.trim() !== "" && instance.iti.isValidNumber() === false;
      });
      if (badPhone) {
        showStatus(status, "is-error", "Số điện thoại chưa đúng, vui lòng kiểm tra lại.");
        badPhone.input.focus();
        return;
      }
      itiInstances.forEach(function (instance) {
        if (instance.iti.isValidNumber()) {
          instance.input.value = instance.iti.getNumber();
        }
      });

      if (auth && !auth.credential()) {
        auth.showError("Vui lòng bấm \"Xác minh bằng Zalo\" trước khi gửi.");
        form.querySelector(".mme-auth-box").scrollIntoView({ behavior: "smooth", block: "center" });
        return;
      }

      submit.disabled = true;
      form.classList.add("is-submitting");
      showStatus(status, "", "Đang gửi...");

      var formData = new FormData(form);
      var values = {};
      formData.forEach(function (value, key) {
        var match = key.match(/^fields\[([^\]]+)\]$/);
        if (match) values[match[1]] = String(value);
      });
      var payload = {
        fields: values,
        form_id: Number(formId),
        current_url: currentInput.value || sourceUrl,
        referrer_url: referrerInput.value,
        started_at: Number(startedInput.value),
        website: formData.get("website") || "",
        mmeauth_token: auth ? auth.credential() : "",
        attribution: attribution(currentInput.value || sourceUrl),
      };

      var controller = window.AbortController ? new AbortController() : null;
      var timeout = controller ? setTimeout(function () { controller.abort(); }, 30000) : null;
      try {
        var response = await fetch(form.dataset.endpoint, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify(payload),
          signal: controller ? controller.signal : undefined,
        });
        var data = await response.json().catch(function () { return {}; });
        if (!response.ok) {
          if (auth && data.data && data.data.auth_required) auth.reset();
          throw new Error(data.message || "Không thể gửi form.");
        }

        showStatus(status, "is-success", data.message || status.dataset.success);
        emitTracking("mme_form_submit_success", Object.assign({ form_id: formId, event_id: data.event_id }, sourceParts(currentInput.value || sourceUrl)));
        form.reset();
        if (auth) auth.reset();
        currentInput.value = sourceUrl;
        referrerInput.value = document.referrer || "";
        startedInput.value = String(Date.now());

        // Giữ thông báo thành công cho tới khi khách bắt đầu nhập lần mới
        form.addEventListener("input", function clearSuccess() {
          showStatus(status, "", "");
          form.removeEventListener("input", clearSuccess);
        });
      } catch (error) {
        var message = error && error.name === "AbortError"
          ? "Máy chủ phản hồi quá lâu, vui lòng thử lại."
          : (error instanceof Error ? error.message : "Không thể gửi form. Vui lòng thử lại.");
        showStatus(status, "is-error", message);
        emitTracking("mme_form_submit_error", Object.assign({ form_id: formId }, sourceParts(currentInput.value || sourceUrl)));
      } finally {
        if (timeout) clearTimeout(timeout);
        submit.disabled = false;
        form.classList.remove("is-submitting");
        resizeParent(formId);
      }
    });

    if (window.ResizeObserver) {
      new ResizeObserver(function () { resizeParent(formId); }).observe(shell);
    } else {
      window.addEventListener("load", function () { resizeParent(formId); });
    }
  }

  function init() {
    document.querySelectorAll(".mme-form-wrapper").forEach(setupForm);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
