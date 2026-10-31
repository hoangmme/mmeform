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

    // Setup Email OTP
    var otpWrap = form.querySelector(".mme-otp-wrap");
    if (otpWrap) {
      var sendOtpBtn = otpWrap.querySelector(".mme-btn-send-otp");
      var otpInputBox = otpWrap.querySelector(".mme-otp-input-box");
      var otpStatus = otpWrap.querySelector(".mme-otp-status");
      var emailFieldName = otpWrap.dataset.otpFor || "email";
      var emailInput = form.querySelector('[name="fields[' + emailFieldName + ']"]');

      if (sendOtpBtn && emailInput) {
        var cooldownTimer = null;

        sendOtpBtn.addEventListener("click", async function (e) {
          e.preventDefault();
          var emailVal = (emailInput.value || "").trim();
          if (!emailVal || !emailInput.checkValidity()) {
            emailInput.reportValidity();
            if (otpStatus) {
              otpStatus.style.display = "block";
              otpStatus.className = "mme-otp-status is-error";
              otpStatus.textContent = "Vui lòng nhập địa chỉ email hợp lệ trước khi lấy mã OTP.";
            }
            return;
          }

          sendOtpBtn.disabled = true;
          var originalBtnText = sendOtpBtn.textContent;
          sendOtpBtn.textContent = "Đang gửi OTP...";

          try {
            var otpEndpoint = form.dataset.otpEndpoint || "/wp-json/mme-form/v1/send-otp";
            var res = await fetch(otpEndpoint, {
              method: "POST",
              credentials: "same-origin",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                form_id: Number(formId),
                email: emailVal,
              }),
            });
            var resData = await res.json().catch(function () { return {}; });

            if (!res.ok) {
              throw new Error(resData.message || "Không thể gửi mã OTP.");
            }

            if (otpInputBox) otpInputBox.style.display = "block";
            if (otpStatus) {
              otpStatus.style.display = "block";
              otpStatus.className = "mme-otp-status is-success";
              otpStatus.textContent = resData.message || "Đã gửi mã OTP đến email. Vui lòng kiểm tra hộp thư!";
            }

            var seconds = Number(resData.cooldown) || 60;
            sendOtpBtn.textContent = "Gửi lại (" + seconds + "s)";
            if (cooldownTimer) clearInterval(cooldownTimer);
            cooldownTimer = setInterval(function () {
              seconds--;
              if (seconds <= 0) {
                clearInterval(cooldownTimer);
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = "Gửi lại mã OTP";
              } else {
                sendOtpBtn.textContent = "Gửi lại (" + seconds + "s)";
              }
            }, 1000);

            var otpCodeInput = otpWrap.querySelector('input[name="otp_code"]');
            if (otpCodeInput) otpCodeInput.focus();
            resizeParent(formId);
          } catch (err) {
            sendOtpBtn.disabled = false;
            sendOtpBtn.textContent = originalBtnText;
            if (otpStatus) {
              otpStatus.style.display = "block";
              otpStatus.className = "mme-otp-status is-error";
              otpStatus.textContent = err.message || "Lỗi khi gửi mã OTP.";
            }
            resizeParent(formId);
          }
        });
      }
    }

    window.addEventListener("message", function (event) {
      var data = event.data || {};
      if (data.type === "MME_FORM_CONTEXT" && data.formId === formId && data.url) {
        currentInput.value = data.url;
        sourceUrl = data.url;
      }
    });

    form.addEventListener("submit", async function (event) {
      event.preventDefault();
      if (!form.reportValidity()) return;
      
      itiInstances.forEach(function (instance) {
        if (instance.iti.isValidNumber()) {
          instance.input.value = instance.iti.getNumber();
        }
      });

      submit.disabled = true;
      form.classList.add("is-submitting");
      status.className = "mme-form-status";
      status.textContent = "Đang gửi...";

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
        otp_code: (formData.get("otp_code") || "").trim(),
        attribution: attribution(currentInput.value || sourceUrl),
      };

      try {
        var response = await fetch(form.dataset.endpoint, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify(payload),
        });
        var data = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(data.message || "Không thể gửi form.");

        status.classList.add("is-success");
        status.textContent = data.message || status.dataset.success;
        emitTracking("mme_form_submit_success", Object.assign({ form_id: formId, event_id: data.event_id }, sourceParts(currentInput.value || sourceUrl)));
        form.reset();
        currentInput.value = sourceUrl;
        referrerInput.value = document.referrer || "";
        startedInput.value = String(Date.now());
        
        setTimeout(function() {
          status.classList.remove("is-success");
          status.textContent = "";
        }, 2000);
      } catch (error) {
        status.classList.add("is-error");
        status.textContent = error instanceof Error ? error.message : "Không thể gửi form. Vui lòng thử lại.";
        emitTracking("mme_form_submit_error", Object.assign({ form_id: formId }, sourceParts(currentInput.value || sourceUrl)));
      } finally {
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
