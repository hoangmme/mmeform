(function () {
  "use strict";

  function ready(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback);
    } else {
      callback();
    }
  }

  ready(function () {
    var builder = document.getElementById("mme-form-builder");
    if (!builder) return;

    var body = document.getElementById("mme-fields-body");
    var hidden = document.getElementById("mme-form-fields-json");
    var addButton = document.getElementById("mme-add-field");
    var draggedRow = null;
    var fields = [];

    try {
      fields = JSON.parse(builder.dataset.fields || "[]");
    } catch (error) {
      fields = [];
    }

    function slugify(value) {
      return String(value || "")
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .replace(/đ/g, "d")
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+|_+$/g, "");
    }

    function makeInput(className, type) {
      var input = document.createElement("input");
      input.className = className;
      input.type = type || "text";
      return input;
    }

    var TYPES = [
      ["text", "Chữ ngắn"],
      ["tel", "Số điện thoại"],
      ["email", "Email"],
      ["textarea", "Đoạn văn"],
      ["select", "Danh sách chọn"],
      ["radio", "Chọn một (nút)"],
      ["date", "Ngày"],
      ["time", "Giờ"],
    ];

    function makeSelect(className, options, value, label) {
      var select = document.createElement("select");
      select.className = className;
      if (label) select.setAttribute("aria-label", label);
      options.forEach(function (option) {
        var node = document.createElement("option");
        node.value = option[0];
        node.textContent = option[1];
        node.selected = value === option[0];
        select.appendChild(node);
      });
      return select;
    }

    function makeLabelled(text, control, className) {
      var wrap = document.createElement("label");
      wrap.className = "mme-fcard__field" + (className ? " " + className : "");
      var span = document.createElement("span");
      span.textContent = text;
      wrap.appendChild(span);
      wrap.appendChild(control);
      return wrap;
    }

    // Mỗi trường là một thẻ: dòng trên (nhãn, loại, độ rộng, bắt buộc), dòng dưới (gợi ý / lựa chọn, tên biến)
    function createRow(field) {
      var row = document.createElement("div");
      row.className = "mme-field-row mme-fcard";

      var handle = document.createElement("span");
      handle.className = "mme-drag-handle";
      handle.textContent = "⋮⋮";
      handle.title = "Kéo để đổi thứ tự";
      handle.setAttribute("aria-hidden", "true");

      var labelInput = makeInput("mme-field-label");
      labelInput.value = field.label || "";
      labelInput.placeholder = "Nhãn, ví dụ: Họ và tên";
      labelInput.setAttribute("aria-label", "Nhãn trường");

      var typeSelect = makeSelect("mme-field-type", TYPES, field.type || "text", "Loại trường");
      var widthSelect = makeSelect("mme-field-width", [["100", "Cả dòng"], ["50", "Nửa dòng"]], field.width || "100", "Độ rộng");

      var requiredInput = makeInput("mme-field-required", "checkbox");
      requiredInput.checked = Boolean(field.required);
      var requiredLabel = document.createElement("label");
      requiredLabel.className = "mme-fcard__required";
      requiredLabel.appendChild(requiredInput);
      requiredLabel.appendChild(document.createTextNode("Bắt buộc"));

      var removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "mme-fcard__remove mme-remove-field";
      removeButton.title = "Xoá trường";
      removeButton.setAttribute("aria-label", "Xoá trường");
      removeButton.innerHTML = '<span class="dashicons dashicons-trash" aria-hidden="true"></span>';

      var head = document.createElement("div");
      head.className = "mme-fcard__head";
      [handle, labelInput, typeSelect, widthSelect, requiredLabel, removeButton].forEach(function (node) {
        head.appendChild(node);
      });

      var placeholderInput = makeInput("mme-field-placeholder");
      placeholderInput.value = field.placeholder || "";
      placeholderInput.placeholder = "Ví dụ: Nguyễn Văn A";
      var optionsInput = document.createElement("textarea");
      optionsInput.className = "mme-field-options";
      optionsInput.rows = 3;
      optionsInput.placeholder = "Mỗi lựa chọn một dòng";
      optionsInput.value = Array.isArray(field.options) ? field.options.join("\n") : "";
      var nameInput = makeInput("mme-field-name");
      nameInput.value = field.name || "";
      nameInput.placeholder = "tu_dong_tu_nhan";

      var placeholderWrap = makeLabelled("Chữ gợi ý trong ô", placeholderInput);
      var optionsWrap = makeLabelled("Các lựa chọn", optionsInput, "mme-fcard__field--options");
      var nameWrap = makeLabelled("Tên biến (Sheets / CRM)", nameInput, "mme-fcard__field--name");

      var bodyRow = document.createElement("div");
      bodyRow.className = "mme-fcard__body";
      [placeholderWrap, optionsWrap, nameWrap].forEach(function (node) {
        bodyRow.appendChild(node);
      });

      row.appendChild(head);
      row.appendChild(bodyRow);

      function updateDetailsVisibility() {
        var needsOptions = typeSelect.value === "select" || typeSelect.value === "radio";
        optionsWrap.hidden = !needsOptions;
        placeholderWrap.hidden = typeSelect.value === "radio" || typeSelect.value === "date" || typeSelect.value === "time";
        row.dataset.type = typeSelect.value;
      }

      labelInput.addEventListener("input", function () {
        if (!nameInput.value || nameInput.dataset.auto === "yes") {
          nameInput.value = slugify(labelInput.value);
          nameInput.dataset.auto = "yes";
          serialize();
        }
      });
      nameInput.addEventListener("input", function () {
        nameInput.dataset.auto = "no";
        serialize();
      });
      nameInput.addEventListener("blur", function () {
        nameInput.value = nameInput.value.trim().replace(/\s+/g, '_');
        serialize();
      });
      typeSelect.addEventListener("change", updateDetailsVisibility);
      removeButton.addEventListener("click", function () {
        var label = labelInput.value || "trường này";
        if (!window.confirm('Xoá "' + label + '"?')) return;
        row.remove();
        serialize();
      });
      row.addEventListener("input", serialize);
      row.addEventListener("change", serialize);

      // Chỉ kéo được khi cầm tay nắm, để vẫn bôi chọn chữ trong ô nhập bình thường
      handle.addEventListener("mousedown", function () { row.draggable = true; });
      row.addEventListener("dragstart", function () {
        draggedRow = row;
        row.classList.add("is-dragging");
      });
      row.addEventListener("dragend", function () {
        draggedRow = null;
        row.draggable = false;
        row.classList.remove("is-dragging");
        serialize();
      });
      row.addEventListener("dragover", function (event) {
        event.preventDefault();
        if (!draggedRow || draggedRow === row) return;
        var rect = row.getBoundingClientRect();
        body.insertBefore(draggedRow, event.clientY < rect.top + rect.height / 2 ? row : row.nextSibling);
      });

      updateDetailsVisibility();
      return row;
    }

    function serialize() {
      var nextFields = Array.prototype.map.call(body.querySelectorAll(".mme-field-row"), function (row, index) {
        var name = row.querySelector(".mme-field-name").value || "field_" + (index + 1);
        return {
          label: row.querySelector(".mme-field-label").value || "Field " + (index + 1),
          name: name.trim().replace(/\s+/g, '_') || "field_" + (index + 1),
          width: row.querySelector(".mme-field-width").value || "100",
          type: row.querySelector(".mme-field-type").value,
          placeholder: row.querySelector(".mme-field-placeholder").value,
          required: row.querySelector(".mme-field-required").checked,
          options: row
            .querySelector(".mme-field-options")
            .value.split(/\r?\n/)
            .map(function (item) { return item.trim(); })
            .filter(Boolean),
        };
      });
      var json = JSON.stringify(nextFields);
      if (hidden.value !== json) {
        hidden.value = json;
        hidden.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    fields.forEach(function (field) {
      body.appendChild(createRow(field));
    });
    addButton.addEventListener("click", function () {
      var row = createRow({ type: "text", required: false, options: [] });
      body.appendChild(row);
      row.querySelector(".mme-field-label").focus();
      serialize();
    });
    serialize();

    var mediaButton = document.getElementById("mme-pick-image");
    var imageInput = document.getElementById("mme-image-url");
    if (mediaButton && imageInput && window.wp && wp.media) {
      mediaButton.addEventListener("click", function () {
        var frame = wp.media({ title: "Chọn ảnh cho MME Form", button: { text: "Dùng ảnh này" }, multiple: false });
        frame.on("select", function () {
          var attachment = frame.state().get("selection").first().toJSON();
          imageInput.value = attachment.url || "";
          imageInput.dispatchEvent(new Event("change", { bubbles: true }));
        });
        frame.open();
      });
    }

    var mediaButtonMobile = document.getElementById("mme-pick-image-mobile");
    var imageInputMobile = document.getElementById("mme-image-url-mobile");
    if (mediaButtonMobile && imageInputMobile && window.wp && wp.media) {
      mediaButtonMobile.addEventListener("click", function () {
        var frame = wp.media({ title: "Chọn ảnh cho MME Form (Mobile)", button: { text: "Dùng ảnh này" }, multiple: false });
        frame.on("select", function () {
          var attachment = frame.state().get("selection").first().toJSON();
          imageInputMobile.value = attachment.url || "";
          imageInputMobile.dispatchEvent(new Event("change", { bubbles: true }));
        });
        frame.open();
      });
    }

    var copyGasButton = document.getElementById("mme-copy-gas");
    if (copyGasButton) {
      copyGasButton.addEventListener("click", function () {
        var template = document.getElementById("mme-gas-template");
        if (template && navigator.clipboard) {
          navigator.clipboard.writeText(template.innerHTML.trim()).then(function () {
            var originalText = copyGasButton.textContent;
            copyGasButton.textContent = "Đã copy thành công!";
            setTimeout(function () {
              copyGasButton.textContent = originalText;
            }, 3000);
          });
        }
      });
    }
    var checkTwentyBtn = document.getElementById("mme-twenty-check-fields");
    if (checkTwentyBtn) {
      checkTwentyBtn.addEventListener("click", function () {
        var statusSpan = document.getElementById("mme-twenty-check-status");
        var resultsDiv = document.getElementById("mme-twenty-check-results");
        
        statusSpan.textContent = "Đang giả lập gửi dữ liệu Test đến CRM...";
        statusSpan.style.color = "#444";
        resultsDiv.style.display = "none";
        checkTwentyBtn.disabled = true;
        
        var formData = new FormData();
        formData.append("action", "mme_twenty_check_fields");
        formData.append("nonce", checkTwentyBtn.dataset.nonce);
        formData.append("post_id", checkTwentyBtn.dataset.postId);
        
        // Also serialize current fields so we check the latest even if not saved
        serialize();
        
        fetch(ajaxurl, {
          method: "POST",
          body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
          checkTwentyBtn.disabled = false;
          if (!res.success) {
            statusSpan.textContent = "Lỗi: " + (res.data || "Không xác định");
            statusSpan.style.color = "#d63638";
            return;
          }
          
          if (res.data.all_good) {
            statusSpan.textContent = "Kiểm tra lý thuyết: Các Field đã khớp!";
            statusSpan.style.color = "#00a32a";
          } else {
            statusSpan.textContent = "Kiểm tra lý thuyết: Có Field chưa khớp!";
            statusSpan.style.color = "#d63638";
          }
          
          var html = '';
          if (res.data.test_result) {
              if (res.data.test_result.success) {
                  html += '<div style="margin-bottom: 15px; padding: 12px; background: #e5f5ea; border-left: 4px solid #00a32a;">';
                  html += '<p style="margin: 0; color: #00a32a; font-weight: 600; font-size: 14px;">✅ Thành công 100%!</p>';
                  html += '<p style="margin: 5px 0 0; color: #333; font-size: 13px;">Dữ liệu test giả lập đã được Twenty CRM chấp nhận không có lỗi. Các field của bạn đã hoạt động hoàn hảo!</p>';
                  html += '</div>';
              } else {
                  html += '<div style="margin-bottom: 15px; padding: 12px; background: #fcf0f1; border-left: 4px solid #d63638;">';
                  html += '<p style="margin: 0; color: #d63638; font-weight: 600; font-size: 14px;">❌ Lỗi gửi dữ liệu Test đến Twenty CRM!</p>';
                  html += '<p style="margin: 5px 0 0; color: #333; font-size: 13px;">Lý thuyết thì tên Field khớp, nhưng lúc gửi thật thì CRM từ chối nhận. Lỗi từ CRM:</p>';
                  var err = res.data.test_result.error || JSON.stringify(res.data.test_result.response || 'Không xác định');
                  html += '<pre style="margin: 10px 0 0; background: #fff; padding: 10px; border: 1px solid #ffcdd2; color: #c62828; white-space: pre-wrap; font-size: 12px; font-family: monospace;">' + err + '</pre>';
                  html += '</div>';
              }
          }
          
          html += '<ul style="margin: 0; padding-left: 20px;">';
          res.data.results.forEach(function(item) {
            var color = item.status === 'green' ? '#00a32a' : (item.status === 'orange' ? '#f5c60d' : '#d63638');
            var icon = item.status === 'green' ? '✓' : (item.status === 'orange' ? '⚠' : '✗');
            html += '<li style="color: ' + color + '; font-weight: 500; margin-bottom: 5px;">' + icon + ' ' + item.label + ' (' + item.name + ')';
            if (item.message) {
                html += '<div style="font-size: 12px; color: #666; font-weight: normal; margin-left: 15px;">' + item.message + '</div>';
            }
            html += '</li>';
          });
          html += '</ul>';
          
          if (!res.data.all_good && res.data.twenty_fields && res.data.twenty_fields.length > 0) {
            var customFields = res.data.twenty_fields.filter(function(f) { 
              return f !== 'name' && f !== 'emails' && f !== 'phones' && f !== 'id' && f !== 'createdAt' && f !== 'updatedAt' && f !== 'createdBy' && f !== 'position';
            });
            if (customFields.length > 0) {
              html += '<div style="margin-top: 15px; padding: 10px; background: #fff8e5; border-left: 4px solid #f5c60d;">';
              html += '<p style="margin: 0 0 5px; font-weight: 600;">Gợi ý: Các field hiện có trên Twenty CRM của bạn là:</p>';
              html += '<p style="margin: 0; font-family: monospace; color: #333;">' + customFields.join(', ') + '</p>';
              html += '<p style="margin: 5px 0 0; font-size: 12px; color: #666;">Hãy copy tên field ở trên và dán vào ô Tên Field trong Form Builder sao cho khớp nhé.</p>';
              html += '</div>';
            }
          }
          
          resultsDiv.innerHTML = html;
          resultsDiv.style.display = "block";
        })
        .catch(function(err) {
          checkTwentyBtn.disabled = false;
          statusSpan.textContent = "Lỗi mạng hoặc server.";
          statusSpan.style.color = "#d63638";
        });
      });
    }

    var importBtn = document.getElementById("mme-import-json-btn");
    var importWrap = document.getElementById("mme-import-json-wrap");
    var importText = document.getElementById("mme-import-json-text");
    var importSubmit = document.getElementById("mme-import-json-submit");

    if (importBtn && importWrap && importText && importSubmit) {
      importBtn.addEventListener("click", function () {
        importWrap.style.display = importWrap.style.display === "none" ? "block" : "none";
        importText.value = JSON.stringify(fields, null, 2);
      });

      importSubmit.addEventListener("click", function () {
        try {
          var newFields = JSON.parse(importText.value);
          if (Array.isArray(newFields)) {
            fields = newFields;
            body.innerHTML = "";
            fields.forEach(function (field) {
              body.appendChild(createRow(field));
            });
            serialize();
            importWrap.style.display = "none";
            alert("Import thành công! Hãy bấm Cập nhật bài viết để lưu lại cấu hình mới.");
          } else {
            alert("Lỗi: Dữ liệu JSON phải là một mảng [] (array) chứa các object form field.");
          }
        } catch (e) {
          alert("Lỗi cú pháp JSON: Vui lòng kiểm tra lại cấu trúc.\nChi tiết: " + e.message);
        }
      });
    }

    // Copy Buttons Handler
    var copyBtns = document.querySelectorAll(".mme-copy-btn");
    copyBtns.forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var targetId = btn.dataset.target;
        var targetInput = document.getElementById(targetId);
        if (!targetInput) return;

        var textToCopy = targetInput.value || targetInput.textContent || "";
        var origText = btn.textContent;

        function showSuccess() {
          btn.textContent = "✓ Đã chép!";
          var fbId = btn.dataset.feedback;
          if (fbId) {
            var fb = document.getElementById(fbId);
            if (fb) {
              fb.classList.add("is-shown");
              setTimeout(function () {
                fb.classList.remove("is-shown");
              }, 2200);
            }
          }
          setTimeout(function () {
            btn.textContent = origText;
          }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(textToCopy).then(showSuccess).catch(function () {
            targetInput.select();
            document.execCommand("copy");
            showSuccess();
          });
        } else {
          targetInput.select();
          document.execCommand("copy");
          showSuccess();
        }
      });
    });

  });

  // Trình sửa form: tab, nút Lưu, bộ màu nhanh, xem trước trực tiếp
  ready(function () {
    var editor = document.querySelector(".mme-editor");
    if (!editor) return;

    var postForm = document.getElementById("post");
    var tabs = editor.querySelectorAll(".mme-editor__tab");
    var panes = editor.querySelectorAll(".mme-editor__pane");
    var STORE = "mmeFormEditor";

    function store(key, value) {
      try {
        var data = JSON.parse(localStorage.getItem(STORE) || "{}");
        if (value === undefined) return data[key];
        data[key] = value;
        localStorage.setItem(STORE, JSON.stringify(data));
      } catch (e) {
        return undefined;
      }
    }

    function openPane(name) {
      var found = false;
      tabs.forEach(function (tab) {
        var active = tab.dataset.pane === name;
        found = found || active;
        tab.classList.toggle("is-active", active);
        tab.setAttribute("aria-selected", active ? "true" : "false");
      });
      if (!found) return;
      panes.forEach(function (pane) {
        pane.hidden = pane.dataset.pane !== name;
        pane.classList.toggle("is-active", pane.dataset.pane === name);
      });
      store("pane", name);
    }

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () { openPane(tab.dataset.pane); });
    });
    document.addEventListener("click", function (e) {
      var link = e.target.closest("[data-mme-open-pane]");
      if (!link) return;
      e.preventDefault();
      openPane(link.dataset.mmeOpenPane);
      editor.scrollIntoView({ behavior: "smooth", block: "start" });
    });
    openPane(store("pane") || "fields");

    // Nút Lưu trong trình sửa = nút Cập nhật / Đăng của WordPress (bị ẩn khi mở xem trước)
    var saveBtn = editor.querySelector("[data-mme-save]");
    if (saveBtn) {
      saveBtn.addEventListener("click", function () {
        var publish = document.getElementById("publish") || document.getElementById("save-post");
        if (publish) publish.click();
      });
    }

    // Bộ màu nhanh
    editor.querySelectorAll("[data-mme-preset]").forEach(function (button) {
      button.addEventListener("click", function () {
        var values = {};
        try { values = JSON.parse(button.dataset.mmePreset); } catch (e) { return; }
        Object.keys(values).forEach(function (key) {
          var input = editor.querySelector('[name="mme_settings[' + key + ']"]');
          if (!input) return;
          input.value = values[key];
          input.dispatchEvent(new Event("change", { bubbles: true }));
        });
        editor.querySelectorAll(".mme-preset").forEach(function (b) { b.classList.toggle("is-active", b === button); });
      });
    });

    /* Xem trước trực tiếp: POST trường + cài đặt chưa lưu vào iframe, tự cập nhật sau mỗi thay đổi */
    var preview = editor.querySelector(".mme-editor__preview");
    var frame = editor.querySelector(".mme-preview-frame");
    var stage = editor.querySelector(".mme-preview-stage");
    var toggles = document.querySelectorAll("[data-mme-preview-toggle]");
    var options = { mode: "full", device: "desktop", bg: "white" };
    var timer = null;
    var open = false;

    function previewUrl() {
      var url = editor.dataset.previewUrl;
      return url + (url.indexOf("?") === -1 ? "?" : "&") + "mode=" + options.mode + "&bg_sim=" + options.bg;
    }

    function refresh() {
      if (!open || !postForm) return;
      clearTimeout(timer);
      var data = new FormData(postForm);
      var shadow = document.createElement("form");
      shadow.method = "POST";
      shadow.action = previewUrl();
      shadow.target = "mme-live-preview";
      shadow.hidden = true;
      function add(name, value) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value;
        shadow.appendChild(input);
      }
      add("mme_preview", "1");
      add("_mme_preview_nonce", editor.dataset.previewNonce);
      data.forEach(function (value, key) {
        if (key === "mme_form_fields_json" || key.indexOf("mme_settings[") === 0) add(key, value);
      });
      document.body.appendChild(shadow);
      stage.classList.add("is-loading");
      shadow.submit();
      shadow.remove();
    }

    function schedule() {
      if (!open) return;
      clearTimeout(timer);
      timer = setTimeout(refresh, 450);
    }

    if (frame) {
      frame.addEventListener("load", function () { stage.classList.remove("is-loading"); });
    }

    function setOpen(next) {
      open = next;
      preview.hidden = !open;
      document.body.classList.toggle("mme-editor-split", open);
      toggles.forEach(function (t) {
        t.setAttribute("aria-pressed", open ? "true" : "false");
        var label = t.querySelector(".mme-preview-toggle__label");
        if (label) label.textContent = open ? "Đóng xem trước" : "Xem trước";
      });
      store("preview", open);
      if (open) {
        refresh();
        editor.scrollIntoView({ behavior: "smooth", block: "start" });
      } else if (frame) {
        frame.src = "about:blank";
      }
    }

    toggles.forEach(function (t) {
      t.addEventListener("click", function () { setOpen(!open); });
    });

    editor.querySelectorAll("[data-mme-preview-opt]").forEach(function (group) {
      var key = group.dataset.mmePreviewOpt;
      group.querySelectorAll("button").forEach(function (button) {
        button.addEventListener("click", function () {
          group.querySelectorAll("button").forEach(function (b) { b.classList.toggle("is-active", b === button); });
          options[key] = button.dataset.value;
          if (key === "device") {
            stage.classList.toggle("is-mobile", options.device === "mobile");
          } else {
            refresh();
          }
        });
      });
    });

    if (postForm) {
      postForm.addEventListener("input", function (e) {
        if (editor.contains(e.target)) schedule();
      });
      postForm.addEventListener("change", function (e) {
        if (editor.contains(e.target) || e.target.id === "mme-form-fields-json") schedule();
      });
    }

    // Màn hình rộng: mở lại xem trước nếu lần trước để mở
    if (store("preview") && window.matchMedia("(min-width: 1280px)").matches) {
      setOpen(true);
    }
  });

  ready(function () {
    // Sync Card Style Options (Hide card background/border/shadow when transparent)
    var cardStyleSelect = document.getElementById("mme-fields-card-style");
    var cardOptionsGroup = document.getElementById("mme-card-options-group");
    if (cardStyleSelect && cardOptionsGroup) {
      function syncCardOptions() {
        if (cardStyleSelect.value === "transparent") {
          cardOptionsGroup.style.display = "none";
        } else {
          cardOptionsGroup.style.display = "grid";
        }
      }
      cardStyleSelect.addEventListener("change", syncCardOptions);
      syncCardOptions();
    }

    // Sync Heading Visibility Options
    var showHeadingSelect = document.getElementById("mme-fields-show-heading");
    var headingTextWrapper = document.getElementById("mme-heading-text-wrapper");
    if (showHeadingSelect && headingTextWrapper) {
      function syncHeadingInputs() {
        if (showHeadingSelect.value === "no") {
          headingTextWrapper.style.opacity = "0.5";
        } else {
          headingTextWrapper.style.opacity = "1";
        }
      }
      showHeadingSelect.addEventListener("change", syncHeadingInputs);
      syncHeadingInputs();
    }
  });

  // Quick View for Submissions
  ready(function () {
    var modal = document.getElementById("mme-quick-view-modal");
    if (!modal) return;

    var backdrop = document.getElementById("mme-quick-view-backdrop");
    var closeBtn = document.getElementById("mme-quick-view-close");
    var titleEl = document.getElementById("mme-quick-view-title");
    var contentEl = document.getElementById("mme-quick-view-content");

    function closeModal() {
      modal.style.display = "none";
      document.body.classList.remove("mme-modal-open");
    }

    if (backdrop) backdrop.addEventListener("click", closeModal);
    if (closeBtn) closeBtn.addEventListener("click", closeModal);
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && modal.style.display !== "none") {
        closeModal();
      }
    });

    var cache = {};
    document.addEventListener("mme:submission-updated", function (e) {
      delete cache[e.detail.id];
    });

    document.addEventListener("click", function (e) {
      var trigger = e.target.closest(".mme-open-quick-view");
      if (!trigger) return;

      e.preventDefault();
      var id = trigger.dataset.id;
      if (!id) return;

      modal.style.display = "flex";
      document.body.classList.add("mme-modal-open");

      if (cache[id]) {
        if (titleEl) titleEl.textContent = cache[id].title || "Chi tiết Submission";
        if (contentEl) contentEl.innerHTML = cache[id].html;
        return;
      }

      if (titleEl) titleEl.textContent = "Đang tải dữ liệu...";
      if (contentEl) {
        contentEl.innerHTML =
          '<div style="text-align: center; padding: 50px 20px; color: #64748b;">' +
          '<span class="dashicons dashicons-update" style="animation: mme-spin 1.5s infinite linear; font-size: 32px; width: 32px; height: 32px;"></span>' +
          '<p style="margin-top: 12px; font-size: 14px; font-weight: 500;">Đang tải thông tin khách hàng...</p>' +
          '</div>';
      }

      var ajaxUrl =
        (window.mme_form_admin_data && window.mme_form_admin_data.ajax_url) ||
        (typeof ajaxurl !== "undefined" ? ajaxurl : "/wp-admin/admin-ajax.php");
      var nonce =
        (window.mme_form_admin_data && window.mme_form_admin_data.nonce) || "";

      var formData = new URLSearchParams();
      formData.append("action", "mme_submission_quick_view");
      formData.append("id", id);
      formData.append("nonce", nonce);

      fetch(ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: formData.toString(),
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (res) {
          if (res.success && res.data) {
            cache[id] = res.data;
            if (titleEl) titleEl.textContent = res.data.title || "Chi tiết Submission";
            if (contentEl) contentEl.innerHTML = res.data.html;
          } else {
            if (contentEl) {
              contentEl.innerHTML =
                '<div style="text-align: center; padding: 40px; color: #ef4444;">' +
                '<span class="dashicons dashicons-warning" style="font-size: 36px; width: 36px; height: 36px;"></span>' +
                '<p style="margin-top: 10px; font-size: 14px;">' +
                (res.data || "Không thể tải dữ liệu submission.") +
                "</p>" +
                "</div>";
            }
          }
        })
        .catch(function () {
          if (contentEl) {
            contentEl.innerHTML =
              '<div style="text-align: center; padding: 40px; color: #ef4444;">' +
              '<p style="font-size: 14px;">Lỗi kết nối máy chủ.</p>' +
              "</div>";
          }
        });
    });
  });

  // Gửi lại các tích hợp lỗi của 1 submission (Quick View + trang chi tiết)
  ready(function () {
    document.addEventListener("click", function (e) {
      var btn = e.target.closest(".mme-retry-integrations");
      if (!btn) return;
      e.preventDefault();

      var wrap = btn.closest(".mme-qv-wrap");
      var id = btn.dataset.id;
      var formData = new URLSearchParams();
      formData.append("action", "mme_submission_retry");
      formData.append("id", id);
      formData.append("nonce", (window.mme_form_admin_data && window.mme_form_admin_data.nonce) || "");
      if (btn.closest("#mme-quick-view-content")) formData.append("in_modal", "1");

      btn.disabled = true;
      btn.textContent = "Đang gửi lại...";

      fetch((window.mme_form_admin_data && window.mme_form_admin_data.ajax_url) || "/wp-admin/admin-ajax.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: formData.toString(),
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (res) {
          if (!res.success || !res.data) throw new Error(res.data || "Gửi lại thất bại.");
          document.dispatchEvent(new CustomEvent("mme:submission-updated", { detail: { id: id } }));
          if (wrap) wrap.outerHTML = res.data.html;
        })
        .catch(function (err) {
          alert(err.message || "Lỗi kết nối máy chủ.");
          btn.disabled = false;
          btn.textContent = "Gửi lại tích hợp lỗi";
        });
    });
  });

  // 1-Click Force Updater from GitHub
  ready(function () {
    document.addEventListener("click", function (e) {
      var btn = e.target.closest(".mme-force-update-btn");
      if (!btn) return;

      e.preventDefault();

      if (
        !confirm(
          "Kiểm tra GitHub và cài bản MME Form mới nhất (nếu có)?"
        )
      ) {
        return;
      }

      var originalHtml = btn.innerHTML;
      btn.style.pointerEvents = "none";
      if (btn.tagName === "BUTTON") {
        btn.disabled = true;
      }
      btn.innerHTML =
        '<span class="dashicons dashicons-update" style="animation: mme-spin 1.5s infinite linear; margin-top: 2px;"></span> Đang kiểm tra & cập nhật...';

      var ajaxUrl =
        (window.mme_form_admin_data && window.mme_form_admin_data.ajax_url) ||
        (typeof ajaxurl !== "undefined" ? ajaxurl : "/wp-admin/admin-ajax.php");
      var nonce =
        window.mme_form_admin_data && window.mme_form_admin_data.nonce
          ? window.mme_form_admin_data.nonce
          : "";

      var formData = new URLSearchParams();
      formData.append("action", "mme_form_force_update");
      formData.append("nonce", nonce);

      fetch(ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: formData.toString(),
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (res) {
          if (res.success && res.data && res.data.up_to_date) {
            alert(res.data.message);
            btn.innerHTML = originalHtml;
            if (btn.tagName === "BUTTON") {
              btn.disabled = false;
            }
            btn.style.pointerEvents = "";
          } else if (res.success) {
            btn.innerHTML =
              '<span class="dashicons dashicons-yes-alt" style="color: #10b981; margin-top: 2px;"></span> ' +
              (res.data.message || "Cập nhật thành công!") + " Đang tải lại...";
            setTimeout(function () {
              location.reload();
            }, 1200);
          } else {
            alert("Cập nhật thất bại: " + (res.data || "Lỗi không xác định"));
            btn.innerHTML = originalHtml;
            if (btn.tagName === "BUTTON") {
              btn.disabled = false;
            }
            btn.style.pointerEvents = "";
          }
        })
        .catch(function () {
          alert("Lỗi kết nối máy chủ trong quá trình cập nhật.");
          btn.innerHTML = originalHtml;
          if (btn.tagName === "BUTTON") {
            btn.disabled = false;
          }
          btn.style.pointerEvents = "";
        });
    });
  });
})();

