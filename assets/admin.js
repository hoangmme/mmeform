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
  });
})();
