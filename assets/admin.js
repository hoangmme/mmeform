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

    function createRow(field) {
      var row = document.createElement("tr");
      row.className = "mme-field-row";
      row.draggable = true;

      var handleCell = document.createElement("td");
      handleCell.className = "mme-drag-handle";
      handleCell.textContent = "⋮⋮";
      handleCell.title = "Kéo để đổi thứ tự";

      var labelCell = document.createElement("td");
      var labelInput = makeInput("mme-field-label");
      labelInput.value = field.label || "";
      labelCell.appendChild(labelInput);

      var nameCell = document.createElement("td");
      var nameInput = makeInput("mme-field-name");
      nameInput.value = field.name || "";
      nameCell.appendChild(nameInput);

      var widthCell = document.createElement("td");
      var widthSelect = document.createElement("select");
      widthSelect.className = "mme-field-width";
      [
        ["100", "1 cột (100%)"],
        ["50", "2 cột (50%)"],
      ].forEach(function (option) {
        var node = document.createElement("option");
        node.value = option[0];
        node.textContent = option[1];
        node.selected = (field.width || "100") === option[0];
        widthSelect.appendChild(node);
      });
      widthCell.appendChild(widthSelect);

      var typeCell = document.createElement("td");
      var typeSelect = document.createElement("select");
      typeSelect.className = "mme-field-type";
      [
        ["text", "Text"],
        ["email", "Email"],
        ["tel", "Phone"],
        ["date", "Date"],
        ["time", "Time"],
        ["textarea", "Text area"],
        ["select", "Dropdown"],
        ["radio", "Radio"],
      ].forEach(function (option) {
        var node = document.createElement("option");
        node.value = option[0];
        node.textContent = option[1];
        node.selected = (field.type || "text") === option[0];
        typeSelect.appendChild(node);
      });
      typeCell.appendChild(typeSelect);

      var detailsCell = document.createElement("td");
      var placeholderInput = makeInput("mme-field-placeholder");
      placeholderInput.placeholder = "Placeholder";
      placeholderInput.value = field.placeholder || "";
      var optionsInput = document.createElement("textarea");
      optionsInput.className = "mme-field-options";
      optionsInput.rows = 3;
      optionsInput.placeholder = "Mỗi option một dòng";
      optionsInput.value = Array.isArray(field.options) ? field.options.join("\n") : "";
      detailsCell.appendChild(placeholderInput);
      detailsCell.appendChild(optionsInput);

      var requiredCell = document.createElement("td");
      var requiredInput = makeInput("mme-field-required", "checkbox");
      requiredInput.checked = Boolean(field.required);
      requiredCell.appendChild(requiredInput);

      var removeCell = document.createElement("td");
      var removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "button-link-delete mme-remove-field";
      removeButton.textContent = "Xóa";
      removeCell.appendChild(removeButton);

      [handleCell, labelCell, nameCell, widthCell, typeCell, detailsCell, requiredCell, removeCell].forEach(function (cell) {
        row.appendChild(cell);
      });

      function updateDetailsVisibility() {
        var needsOptions = typeSelect.value === "select" || typeSelect.value === "radio";
        optionsInput.hidden = !needsOptions;
        placeholderInput.hidden = typeSelect.value === "radio";
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
        row.remove();
        serialize();
      });
      row.addEventListener("input", serialize);
      row.addEventListener("change", serialize);

      row.addEventListener("dragstart", function () {
        draggedRow = row;
        row.classList.add("is-dragging");
      });
      row.addEventListener("dragend", function () {
        draggedRow = null;
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
      hidden.value = JSON.stringify(nextFields);
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

    // Modal Live Preview Handler
    var previewModal = document.getElementById("mme-preview-modal");
    var openPreviewBtn = document.getElementById("mme-open-preview-modal-btn");
    if (previewModal && openPreviewBtn) {
      var previewIframe = document.getElementById("mme-preview-iframe");
      var previewClose = previewModal.querySelector(".mme-modal-close");
      var previewBackdrop = previewModal.querySelector(".mme-modal-backdrop");
      var modeButtons = previewModal.querySelectorAll(".mme-btn-mode");
      var bgButtons = previewModal.querySelectorAll(".mme-btn-bg");
      var modalBody = document.getElementById("mme-modal-body-wrapper");

      var currentMode = "full";
      var currentBg = "white";

      function getPreviewUrl() {
        var base = previewModal.dataset.baseUrl || "";
        var delim = base.indexOf("?") !== -1 ? "&" : "?";
        return base + delim + "mode=" + currentMode + "&bg_sim=" + currentBg;
      }

      function updatePreview() {
        if (!previewIframe) return;
        previewIframe.src = getPreviewUrl();
        if (modalBody) {
          var bgColors = {
            white: "#ffffff",
            gray: "#e2e8f0",
            dark: "#0f172a",
          };
          modalBody.style.backgroundColor = bgColors[currentBg] || "#ffffff";
        }
      }

      function openModal() {
        previewModal.style.display = "flex";
        previewModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        updatePreview();
      }

      function closeModal() {
        previewModal.style.display = "none";
        previewModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        if (previewIframe) {
          previewIframe.src = "about:blank";
        }
      }

      openPreviewBtn.addEventListener("click", function (e) {
        e.preventDefault();
        openModal();
      });

      if (previewClose) {
        previewClose.addEventListener("click", closeModal);
      }
      if (previewBackdrop) {
        previewBackdrop.addEventListener("click", closeModal);
      }

      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && previewModal.style.display !== "none") {
          closeModal();
        }
      });

      modeButtons.forEach(function (mBtn) {
        mBtn.addEventListener("click", function (e) {
          e.preventDefault();
          modeButtons.forEach(function (b) { b.classList.remove("is-active"); });
          mBtn.classList.add("is-active");
          currentMode = mBtn.dataset.mode || "full";
          updatePreview();
        });
      });

      bgButtons.forEach(function (bBtn) {
        bBtn.addEventListener("click", function (e) {
          e.preventDefault();
          bgButtons.forEach(function (b) { b.classList.remove("is-active"); });
          bBtn.classList.add("is-active");
          currentBg = bBtn.dataset.bg || "white";
          updatePreview();
        });
      });
    }
  });

  // Appearance Box Tab Navigation
  ready(function () {
    var navTabs = document.querySelectorAll(".mme-tab-nav .nav-tab");
    if (!navTabs.length) return;

    navTabs.forEach(function (tab) {
      tab.addEventListener("click", function (e) {
        e.preventDefault();
        var targetId = tab.dataset.tab;
        if (!targetId) return;

        navTabs.forEach(function (t) {
          t.classList.remove("nav-tab-active");
        });
        tab.classList.add("nav-tab-active");

        var wrapper = tab.closest(".mme-admin-tabs-wrapper");
        if (!wrapper) return;
        var panes = wrapper.querySelectorAll(".mme-tab-pane");
        panes.forEach(function (pane) {
          if (pane.id === targetId) {
            pane.style.display = "block";
            pane.classList.add("is-active");
          } else {
            pane.style.display = "none";
            pane.classList.remove("is-active");
          }
        });
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
          "Bạn có chắc chắn muốn tải và ghi đè phiên bản mới nhất của MME Form từ GitHub không?"
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
        '<span class="dashicons dashicons-update" style="animation: mme-spin 1.5s infinite linear; margin-top: 2px;"></span> Đang tải & cập nhật...';

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
          if (res.success) {
            btn.innerHTML =
              '<span class="dashicons dashicons-yes-alt" style="color: #10b981; margin-top: 2px;"></span> Cập nhật thành công! Đang tải lại...';
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

