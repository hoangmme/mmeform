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

  /* Kiểm tra từng ô và báo lỗi ngay dưới ô (thay bong bóng mặc định của trình duyệt) */

  function fieldLabel(field) {
    var label = field.querySelector(":scope > label");
    var text = label ? label.textContent.replace("*", "").trim() : "";
    return text ? text.charAt(0).toLocaleLowerCase("vi") + text.slice(1) : "thông tin này";
  }

  function fieldControls(field) {
    // Bỏ ô tìm kiếm quốc gia mà intl-tel-input chèn vào cạnh ô số điện thoại
    return Array.prototype.slice.call(field.querySelectorAll("input:not([type=hidden]):not([type=search]):not(.iti__search-input), textarea, select"));
  }

  function setFieldError(field, message) {
    var error = field.querySelector(".mme-field-error");
    var controls = fieldControls(field);
    if (!error && message) {
      error = document.createElement("p");
      error.className = "mme-field-error";
      error.id = (controls[0] && controls[0].id ? controls[0].id : "mme-field-" + Date.now()) + "-error";
      field.appendChild(error);
    }
    field.classList.toggle("has-error", !!message);
    if (error) {
      error.textContent = message || "";
      error.hidden = !message;
    }
    controls.forEach(function (control) {
      if (message) {
        control.setAttribute("aria-invalid", "true");
        control.setAttribute("aria-describedby", error.id);
      } else {
        control.removeAttribute("aria-invalid");
        if (error && control.getAttribute("aria-describedby") === error.id) control.removeAttribute("aria-describedby");
      }
    });
  }

  function fieldMessage(field, itiInstances) {
    var controls = fieldControls(field);
    if (!controls.length) return "";
    var first = controls[0];

    if (first.type === "radio") {
      var required = controls.some(function (c) { return c.required; });
      var checked = controls.some(function (c) { return c.checked; });
      return required && !checked ? "Vui lòng chọn " + fieldLabel(field) + "." : "";
    }

    var value = first.value.trim();
    if (first.required && !value) {
      return (first.tagName === "SELECT" ? "Vui lòng chọn " : "Vui lòng nhập ") + fieldLabel(field) + ".";
    }
    if (!value) return "";
    if (first.type === "email" && first.validity && first.validity.typeMismatch) {
      return "Email chưa đúng, ví dụ: ten@congty.vn.";
    }
    if (first.type === "tel") {
      // isValidNumber() trả về null khi thư viện utils chưa tải xong: khi đó không chặn khách
      var instance = itiInstances.find(function (item) { return item.input === first; });
      if (instance && instance.iti.isValidNumber() === false) {
        return "Số điện thoại chưa đúng, vui lòng kiểm tra lại.";
      }
    }
    return first.validity && !first.validity.valid ? "Thông tin chưa hợp lệ, vui lòng kiểm tra lại." : "";
  }

  /** Báo lỗi mọi ô sai; trả về ô sai đầu tiên (hoặc null). */
  function validateFields(form, itiInstances) {
    var firstInvalid = null;
    form.querySelectorAll(".mme-form-field").forEach(function (field) {
      var message = fieldMessage(field, itiInstances);
      setFieldError(field, message);
      if (message && !firstInvalid) firstInvalid = field;
    });
    return firstInvalid;
  }

  function watchFieldErrors(form, itiInstances) {
    function recheck(event) {
      var field = event.target.closest && event.target.closest(".mme-form-field");
      if (field && field.classList.contains("has-error")) {
        setFieldError(field, fieldMessage(field, itiInstances));
      }
    }
    form.addEventListener("input", recheck);
    form.addEventListener("change", recheck);
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

  /* Bản nháp form khi khách sang Zalo xác nhận: điện thoại có thể tải lại trang lúc quay về */

  function draftKey(formId) {
    return "mme_form_draft_" + formId;
  }

  function saveDraft(form, formId) {
    var values = [];
    form.querySelectorAll('[name^="fields["]').forEach(function (control) {
      if ((control.type === "radio" || control.type === "checkbox") && !control.checked) return;
      values.push([control.name, control.value]);
    });
    try { window.sessionStorage.setItem(draftKey(formId), JSON.stringify(values)); } catch (error) {}
  }

  function clearDraft(formId) {
    try { window.sessionStorage.removeItem(draftKey(formId)); } catch (error) {}
  }

  function restoreDraft(form, formId) {
    var values = null;
    try { values = JSON.parse(window.sessionStorage.getItem(draftKey(formId)) || "null"); } catch (error) {}
    if (!Array.isArray(values)) return false;
    values.forEach(function (pair) {
      form.querySelectorAll('[name="' + String(pair[0]).replace(/"/g, "") + '"]').forEach(function (control) {
        if (control.type === "radio" || control.type === "checkbox") control.checked = control.value === pair[1];
        else control.value = pair[1];
      });
    });
    return true;
  }

  /**
   * Xác minh qua MMeAuth gộp vào nút gửi: bấm gửi → popup của SDK auth.mme.vn/sdk/v1.js
   * (QR trên máy tính, "Mở Zalo" trên điện thoại) → khách xác nhận trong Mini App → nhận id_token → gửi form.
   * Họ tên và số điện thoại lấy từ token; máy chủ WordPress tự kiểm tra chữ ký token.
   */
  function setupMMeAuth(form, formId) {
    var gateway = form.dataset.mmeauthUrl;
    var siteKey = form.dataset.mmeauthSite;
    if (!gateway || !siteKey) return null;
    var persist = "mme-form-" + formId;
    var token = "";
    var profile = null;

    function sdk() {
      return window.MMeAuth && typeof window.MMeAuth.prompt === "function" ? window.MMeAuth : null;
    }

    /** Token còn dùng được (chưa gửi thành công, còn hạn ít nhất 30 giây). */
    function usable() {
      if (!token) return false;
      var claims = decodeJwtPayload(token) || {};
      return !claims.exp || claims.exp * 1000 > Date.now() + 30000;
    }

    function verify(resume) {
      if (usable()) return Promise.resolve({ token: token, profile: profile });
      var api = sdk();
      if (!api) return Promise.reject(new Error("Không tải được xác minh Zalo. Vui lòng tải lại trang rồi thử lại."));
      return api.prompt({
        site: siteKey,
        gateway: gateway,
        purpose: "form",
        anchor: form.querySelector(".mme-form-submit-wrap"),
        persist: persist,
        resume: resume || null,
      }).then(function (result) {
        token = result.token;
        profile = result.profile || {};
        return result;
      });
    }

    return {
      verify: verify,
      credential: function () { return token; },
      profile: function () { return profile; },
      /** Phiên đang chờ từ lần trước (trang bị tải lại khi khách ở Zalo). */
      pending: function () {
        var api = sdk();
        return api && api.pending ? api.pending(persist) : null;
      },
      reset: function () {
        token = "";
        profile = null;
      },
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

    watchFieldErrors(form, itiInstances);
    openChat(shell, sourceUrl);
    emitTracking("mme_form_view", Object.assign({ form_id: formId }, sourceParts(sourceUrl)));

    var auth = setupMMeAuth(form, formId);

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

    async function send(identity) {
      submit.disabled = true;
      form.classList.add("is-submitting");
      var who = identity && identity.profile && identity.profile.name ? identity.profile.name + " · " : "";
      showStatus(status, "", identity ? "Đã xác minh " + who + "đang gửi..." : "Đang gửi...");

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
        mmeauth_token: identity ? identity.token : "",
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
          // Token hỏng / đã dùng / hết hạn: lần bấm sau sẽ mở xác minh lại
          if (auth && data.data && data.data.auth_required) auth.reset();
          throw new Error(data.message || "Không thể gửi form.");
        }

        showStatus(status, "is-success", data.message || status.dataset.success);
        emitTracking("mme_form_submit_success", Object.assign({ form_id: formId, event_id: data.event_id }, sourceParts(currentInput.value || sourceUrl)));
        form.reset();
        clearDraft(formId);
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
    }

    /** Xác minh Zalo (nếu form bật) rồi gửi. Khách đóng popup thì dừng, không báo lỗi. */
    function verifyThenSend(resume) {
      if (!auth) return send(null);
      submit.disabled = true;
      saveDraft(form, formId);
      return auth.verify(resume)
        .then(function (identity) {
          return send(identity);
        }, function (error) {
          submit.disabled = false;
          if (!error || error.code !== "cancelled") {
            showStatus(status, "is-error", error && error.message ? error.message : "Không xác minh được, vui lòng thử lại.");
          } else {
            clearDraft(formId);
          }
          resizeParent(formId);
        });
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      if (submit.disabled) return;
      showStatus(status, "", "");
      var invalidField = validateFields(form, itiInstances);
      if (invalidField) {
        var control = fieldControls(invalidField)[0];
        invalidField.scrollIntoView({ behavior: "smooth", block: "center" });
        if (control) control.focus({ preventScroll: true });
        resizeParent(formId);
        return;
      }
      itiInstances.forEach(function (instance) {
        if (instance.iti.isValidNumber()) {
          instance.input.value = instance.iti.getNumber();
        }
      });

      // Khung xem trước trong trình sửa form: kiểm tra như thật nhưng không gửi đi
      if (form.dataset.preview) {
        showStatus(status, "is-success", auth
          ? "Bản xem trước: form hợp lệ. Khi chạy thật, bấm gửi sẽ mở xác minh Zalo rồi tự gửi."
          : "Bản xem trước: form hợp lệ, nhưng không gửi đi.");
        return;
      }

      verifyThenSend(null);
    });

    // Quay lại từ Zalo mà trang đã bị tải lại: điền lại form và chờ tiếp phiên xác minh cũ
    if (auth && !form.dataset.preview) {
      var resume = auth.pending();
      if (resume && restoreDraft(form, formId)) {
        verifyThenSend(resume);
      } else {
        clearDraft(formId);
      }
    }

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
