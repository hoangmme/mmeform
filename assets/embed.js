(function () {
  "use strict";

  var script = document.currentScript;
  if (!script) return;

  var endpoint = script.dataset.endpoint || "";
  var formId = script.dataset.mmeForm || "";
  if (!endpoint || !formId) return;

  var iframe = document.createElement("iframe");
  var separator = endpoint.indexOf("?") === -1 ? "?" : "&";
  iframe.src = endpoint + separator + "mme_parent_url=" + encodeURIComponent(window.location.href);
  iframe.title = script.dataset.title || "Contact form";
  iframe.loading = "lazy";
  iframe.allow = "clipboard-write";
  iframe.style.cssText = [
    "display:block",
    "width:100%",
    "height:560px",
    "border:0",
    "overflow:hidden",
    "background:transparent",
  ].join(";");

  var wrapper = document.createElement("div");
  wrapper.className = "mme-form-embed";
  wrapper.style.cssText = "width:100%;max-width:100%;margin:0 auto;";
  wrapper.appendChild(iframe);
  script.parentNode.insertBefore(wrapper, script.nextSibling);

  var endpointOrigin = "";
  try {
    endpointOrigin = new URL(endpoint).origin;
  } catch (error) {
    return;
  }

  function track(name, detail) {
    if (Array.isArray(window.dataLayer)) {
      window.dataLayer.push(Object.assign({ event: name }, detail || {}));
    }
    if (typeof window.gtag === "function") {
      window.gtag("event", name, detail || {});
    }
    if (typeof window.fbq === "function") {
      window.fbq("trackCustom", name === "mme_form_submit_success" ? "MMEFormSubmit" : "MMEFormView", detail || {});
    }
  }

  window.addEventListener("message", function (event) {
    if (event.origin !== endpointOrigin || event.source !== iframe.contentWindow) return;
    var data = event.data || {};
    if (data.type === "MME_FORM_RESIZE" && String(data.formId) === String(formId)) {
      var height = Math.max(240, Math.min(1600, Number(data.height) || 560));
      iframe.style.height = height + "px";
    }
    if (data.type === "MME_FORM_EVENT") {
      track(data.name, data.detail);
    }
  });

  iframe.addEventListener("load", function () {
    iframe.contentWindow.postMessage({ type: "MME_FORM_CONTEXT", formId: String(formId), url: window.location.href }, endpointOrigin);
  });
})();
