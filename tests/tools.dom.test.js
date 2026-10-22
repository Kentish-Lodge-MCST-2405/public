"use strict";
/* DOM smoke tests for the estate-records tools:
   tools/issues.html, tools/visits.html, tools/letters.html */
const fs = require("fs");
const path = require("path");
const { JSDOM } = require("jsdom");

const ROOT = path.resolve(__dirname, "..");
let failures = 0;
function check(name, cond) {
  console.log((cond ? "PASS" : "FAIL") + "  " + name);
  if (!cond) failures++;
}
function load(file, url) {
  const html = fs.readFileSync(path.join(ROOT, file), "utf8");
  return new JSDOM(html, {
    url: url,
    runScripts: "dangerously",
    pretendToBeVisual: true,
    beforeParse(window) {
      window.matchMedia = window.matchMedia || function () { return { matches: false }; };
      if (/letters\.html$/.test(file)) {
        window.KENTISH_BYLAWS = JSON.parse(
          fs.readFileSync(path.join(ROOT, "assets", "bylaws-index.js"), "utf8")
            .replace(/^[\s\S]*?var KENTISH_BYLAWS = /, "")
            .replace(/;\nif \(typeof module[\s\S]*$/, "")
        );
      }
    },
  });
}
const fin = (name) => {
  console.log((failures ? "\nSOME TESTS FAILED (" + failures + ")" : "\nALL TESTS PASSED") + " — " + name);
  process.exit(failures ? 1 : 0);
};

/* ================= issues.html ================= */
{
  const dom = load("tools/issues.html", "https://info.kentishlodge.com/tools/issues.html");
  const d = dom.window.document;
  check("issues: tabs present", !!d.querySelector("#tabReport") && !!d.querySelector("#tabTrack") && !!d.querySelector("#tabSignin"));
  check("issues: 10 category pills", d.querySelectorAll("#catOpts .opt").length === 10);
  check("issues: report panel visible by default", d.querySelector("#panelReport").classList.contains("on"));

  d.querySelector("#reportForm").dispatchEvent(new dom.window.Event("submit", { cancelable: true }));
  check("issues: empty submit flagged category", d.querySelector("#f-category").classList.contains("invalid"));
  check("issues: empty submit flagged title", d.querySelector("#f-title").classList.contains("invalid"));
  check("issues: empty submit flagged consent", d.querySelector("#f-consent").classList.contains("invalid"));

  const cat = d.querySelector('#catOpts input[value="water"]');
  cat.checked = true;
  cat.dispatchEvent(new dom.window.Event("change", { bubbles: true }));
  check("issues: category selection highlights pill", cat.closest(".opt").classList.contains("on"));
  check("issues: category selection clears the flag", !d.querySelector("#f-category").classList.contains("invalid"));

  const authTabs = d.querySelectorAll("#panelSignin .tabs .tab");
  check("issues: sign-in has login+register toggles", authTabs.length === 2);
}

{
  const dom = load("tools/issues.html", "https://info.kentishlodge.com/tools/issues.html?ref=KL-IR-TEST");
  const d = dom.window.document;
  check("issues: ?ref= deep-link opens track tab", d.querySelector("#panelTrack").classList.contains("on"));
  check("issues: ?ref= prefills reference", d.querySelector("#trackRef").value === "KL-IR-TEST");
}

/* ================= visits.html ================= */
{
  const dom = load("tools/visits.html", "https://info.kentishlodge.com/tools/visits.html");
  const d = dom.window.document;
  check("visits: gate visible before key", !d.querySelector("#gateMain").hidden && d.querySelector("#appMain").hidden);
  check("visits: gate prompt does not expose app", d.querySelector("#appMain").getAttribute("hidden") !== null);

  /* areas pills are inside the hidden main — still rendered */
  check("visits: 12 area pills", d.querySelectorAll("#vAreas .opt").length === 12);

  d.querySelector("#addFinding").click();
  check("visits: add finding creates a finding block", d.querySelectorAll("#findings .finding").length === 1);
  const f = d.querySelector("#findings .finding");
  check("visits: finding has severity select", !!f.querySelector("[data-k='severity']"));
  check("visits: finding has raise-as-issue toggle", !!f.querySelector("[data-k='create_issue']"));
  const collected = f._collect();
  check("visits: finding collects defaults", collected.severity === "observation" && collected.create_issue === false);
}

/* ================= letters.html ================= */
{
  const dom = load("tools/letters.html", "https://info.kentishlodge.com/tools/letters.html");
  const d = dom.window.document;
  check("letters: 10 templates listed", d.querySelectorAll("#tplList .tpl").length === 10);
  check("letters: warn-1 selected by default", d.querySelector('#tplList .tpl[data-id="warn-1"]').classList.contains("sel"));
  check("letters: sheet shows first-warning subject", d.querySelector("#sheet").textContent.indexOf("FIRST WRITTEN WARNING") >= 0);
  check("letters: default template has appeal block", !!d.querySelector("#sheet .appeal"));

  /* deadline math: letter_date + 14 days */
  const dateEl = d.querySelector("#v-letter_date");
  dateEl.value = "2026-10-21";
  dateEl.dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  const daysEl = d.querySelector("#v-days");
  daysEl.value = "14";
  daysEl.dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  check("letters: 14-day deadline computed (4 November 2026)", d.querySelector("#sheet").textContent.indexOf("4 November 2026") >= 0);

  /* erect-14 template: default citations include Rule 32 */
  d.querySelector('#tplList .tpl[data-id="erect-14"]').dispatchEvent(new dom.window.Event("click", { bubbles: true }));
  const sheet = d.querySelector("#sheet").textContent;
  check("letters: erect-14 cites Rule 32", sheet.indexOf("Rule 32") >= 0);
  check("letters: erect-14 mentions 14 DAYS", sheet.indexOf("14 DAYS") >= 0);

  /* clamp-release subject carries vehicle reg */
  d.querySelector('#tplList .tpl[data-id="clamp-release"]').dispatchEvent(new dom.window.Event("click", { bubbles: true }));
  const reg = d.querySelector("#v-vehicle_reg");
  reg.value = "SXX1234Z";
  reg.dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  check("letters: clamp notice subject carries vehicle no.", d.querySelector("#sheet").textContent.indexOf("SXX1234Z") >= 0);
  check("letters: clamp notice states S$100 release charge", d.querySelector("#sheet").textContent.indexOf("S$100.00") >= 0);

  /* service letter has no appeal block */
  d.querySelector('#tplList .tpl[data-id="ack-issue"]').dispatchEvent(new dom.window.Event("click", { bubbles: true }));
  check("letters: acknowledgment has no appeal block", !d.querySelector("#sheet .appeal"));

  /* bylaw citation picker populated from generated index */
  check("letters: bylaw picker lists 38 bylaws", d.querySelectorAll("#citeDoc option").length === 39); /* +placeholder */
  check("letters: office settings dialog exists", !!d.querySelector("#settingsDlg"));
}

fin("tools.dom.test.js");
