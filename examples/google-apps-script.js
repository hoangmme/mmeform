/**
 * MME Form -> Google Sheets
 * Deploy as a Web App (Execute as Me, access: Anyone), then use:
 * https://script.google.com/macros/s/DEPLOYMENT_ID/exec?secret=YOUR_SECRET
 */

const WEBHOOK_SECRET = "CHANGE_ME";
const SPREADSHEET_ID = "";
const SHEET_NAME = "Leads";

const HEADERS = [
  "Thời gian",
  "Event ID",
  "Form",
  "Họ và tên",
  "Số điện thoại",
  "Email",
  "Nhu cầu",
  "Booking",
  "URL nguồn",
  "Referrer",
  "UTM Source",
  "UTM Medium",
  "UTM Campaign",
  "Toàn bộ fields",
];

function jsonResponse(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

function doGet() {
  return jsonResponse({ ok: true, service: "MME Form webhook", method: "GET" });
}

function getSheet() {
  const spreadsheet = SPREADSHEET_ID
    ? SpreadsheetApp.openById(SPREADSHEET_ID)
    : SpreadsheetApp.getActiveSpreadsheet();
  if (!spreadsheet) throw new Error("Set SPREADSHEET_ID or attach Apps Script to a Google Sheet.");

  let sheet = spreadsheet.getSheetByName(SHEET_NAME);
  if (!sheet) sheet = spreadsheet.insertSheet(SHEET_NAME);
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(HEADERS);
    sheet.getRange(1, 1, 1, HEADERS.length).setFontWeight("bold");
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function asText(value) {
  return value ? "'" + String(value) : "";
}

function doPost(event) {
  try {
    const rawBody = event && event.postData ? event.postData.contents : "{}";
    const payload = JSON.parse(rawBody);
    const suppliedSecret = String(payload.secret || (event.parameter && event.parameter.secret) || "");
    if (WEBHOOK_SECRET && suppliedSecret !== WEBHOOK_SECRET) {
      return jsonResponse({ ok: false, error: "Unauthorized" });
    }

    const contact = payload.contact || {};
    const lead = payload.lead || {};
    const source = payload.source || {};
    const attribution = payload.attribution || {};
    getSheet().appendRow([
      new Date(),
      payload.event_id || "",
      (payload.form && payload.form.title) || "",
      contact.name || "",
      asText(contact.phone),
      contact.email || "",
      lead.need || "",
      lead.booking_requested ? "Yes" : "No",
      source.url || "",
      source.referrer || "",
      attribution.utm_source || "",
      attribution.utm_medium || "",
      attribution.utm_campaign || "",
      JSON.stringify(payload.fields || {}),
    ]);

    return jsonResponse({ ok: true, method: "POST", row_appended: true, event_id: payload.event_id || "" });
  } catch (error) {
    return jsonResponse({ ok: false, error: error && error.message ? error.message : String(error) });
  }
}
