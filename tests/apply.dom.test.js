"use strict";
/* DOM smoke test for forms/apply.html — verifies radio pills, conditional
   fields, and validation behavior in a real DOM (jsdom). */
const fs = require("fs");
const path = require("path");
const { JSDOM } = require("jsdom");

const htmlPath = "C:\\Users\\Desktop\\.openclaw-autoclaw\\agents\\kentish-agent\\workspace\\mcst-public\\forms\\apply.html";
const html = fs.readFileSync(htmlPath, "utf8");

let failures = 0;
function check(name, cond) {
  console.log((cond ? "PASS" : "FAIL") + "  " + name);
  if (!cond) failures++;
}

function makeDom(url) {
  return new JSDOM(html, {
    url: url,
    runScripts: "dangerously",
    pretendToBeVisual: true,
    beforeParse(window) {
      const ctxStub = new Proxy({}, {
        get: (t, p) => (p === "canvas" ? null : function () {}),
        set: () => true,
      });
      window.HTMLCanvasElement.prototype.getContext = function () { return ctxStub; };
      window.HTMLElement.prototype.scrollIntoView = function () {};
    },
  });
}

// ---------- VR-01 ----------
const dom1 = makeDom("https://info.kentishlodge.com/forms/apply.html?form=vr-01");
const w1 = dom1.window, d1 = w1.document;

check("boot: form rendered (VR-01 sections)", d1.querySelectorAll("#theForm .field").length > 15);

// radio pills: click updates state + highlight
const role = d1.querySelector('input[name="applicant_role"][value="Tenant"]');
role.click();
check("radio click sets value", w1.values.applicant_role === "Tenant");
check("radio click adds .on to pill", role.closest(".opt").classList.contains("on"));
const otherRolePill = d1.querySelector('input[name="applicant_role"][value="Owner"]').closest(".opt");
check("radio pills are mutually exclusive", !otherRolePill.classList.contains("on"));

// keyboard change event (arrow-key selection) also works
const owner = d1.querySelector('input[name="applicant_role"][value="Owner"]');
owner.checked = true;
owner.dispatchEvent(new w1.Event("change", { bubbles: true }));
check("keyboard change event updates value", w1.values.applicant_role === "Owner");
check("keyboard change updates highlight", owner.closest(".opt").classList.contains("on") && !role.closest(".opt").classList.contains("on"));

// conditional visibility: named_on_doc = No reveals LOA fields
d1.querySelector('input[name="named_on_doc"][value="No"]').click();
const loaField = d1.querySelector('.field[data-k="loa_signatory_name"]');
check("named_on_doc=No reveals LOA name field", loaField.style.display !== "none");
check("named_on_doc=No reveals LOA upload", d1.querySelector('.field[data-k="loa_upload"]').style.display !== "none");

// named_on_doc = Yes hides them again
d1.querySelector('input[name="named_on_doc"][value="Yes"]').click();
check("named_on_doc=Yes hides LOA name field", loaField.style.display === "none");

// vehicle_type Other reveals the specify box
d1.querySelector('input[name="vehicle_type"][value="Other"]').click();
const oth = d1.querySelector('input[data-k="vehicle_type_other"]');
check("vehicle_type=Other shows specify box", oth.style.display !== "none");
d1.querySelector('input[name="vehicle_type"][value="Car"]').click();
check("vehicle_type=Car hides specify box", oth.style.display === "none");

// validation: submit with empty required fields flags invalid fields
d1.querySelector("#btnSubmit").click();
check("validation flags empty required fields", d1.querySelectorAll("#theForm .field.invalid").length > 3);
check("validation error message shown", (d1.querySelector('.field[data-k="unit_no"] .err-msg') || {}).textContent === "This field is required");

// filling a required text field clears its error on submit attempt
const unit = d1.querySelector('input[data-k="unit_no"]');
unit.value = "12-34";
unit.dispatchEvent(new w1.Event("input", { bubbles: true }));
d1.querySelector('input[data-k="applicant_name"]').value = "Test Person";
d1.querySelector('input[data-k="applicant_name"]').dispatchEvent(new w1.Event("input", { bubbles: true }));
d1.querySelector('input[data-k="nric_fin"]').value = "S1234567D";
d1.querySelector('input[data-k="nric_fin"]').dispatchEvent(new w1.Event("input", { bubbles: true }));
d1.querySelector('input[data-k="mobile"]').value = "91234567";
d1.querySelector('input[data-k="mobile"]').dispatchEvent(new w1.Event("input", { bubbles: true }));
d1.querySelector("#btnSubmit").click();
const unitErr = d1.querySelector('.field[data-k="unit_no"]');
check("filled field no longer flagged invalid", !unitErr.classList.contains("invalid"));

// draft saved to localStorage
const draft = w1.localStorage.getItem("kl-form-draft-vr-01");
check("draft persisted to localStorage", !!draft && draft.indexOf("12-34") !== -1);

// print sheet renders from current values (filled mode)
w1.values._ref = "KL-TEST";
const sheet = w1.renderSheet(false);
check("print sheet contains filled value", sheet.indexOf("12-34") !== -1);
check("print sheet shows ☑ for selected radio", sheet.indexOf("☑ Owner") !== -1);
const blankSheet = w1.renderSheet(true);
check("blank sheet has no filled value", blankSheet.indexOf("12-34") === -1);

// ---------- VR-02 ----------
const dom2 = makeDom("https://info.kentishlodge.com/forms/apply.html?form=vr-02");
const w2 = dom2.window, d2 = w2.document;
check("boot: VR-02 rendered", !!d2.querySelector('input[name="capacity"]'));
check("VR-02 has two signature pads", d2.querySelectorAll("canvas.sig").length === 2);
check("VR-02 pad buttons bound (4 clear/undo)", d2.querySelectorAll("[data-pad]").length === 4);
d2.querySelector('input[name="capacity"][value="Lessee (rental / lease agreement attached)"]').click();
check("VR-02 capacity radio works", w2.values.capacity === "Lessee (rental / lease agreement attached)");

console.log("");
if (failures) { console.log(failures + " test(s) FAILED"); process.exit(1); }
console.log("ALL TESTS PASSED");
