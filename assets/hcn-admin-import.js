document.addEventListener("DOMContentLoaded", () => {
  // If we're not on the Import tab, HCN_IMPORT won't exist — that's fine.
  if (!window.HCN_IMPORT || !window.HCN_IMPORT.ajaxUrl) return;

  const ajaxUrl = window.HCN_IMPORT.ajaxUrl;
  const nonce = window.HCN_IMPORT.nonce;
  const editBase = window.HCN_IMPORT.editBase || "";

  const logEl = document.getElementById("hcn-log");
  const listEl = document.getElementById("hcn-imported-list");

  const loggiaTestBtn = document.getElementById("hcn-loggia-test-btn");
  const loggiaOut = document.getElementById("hcn-loggia-test-output");

  loggiaTestBtn?.addEventListener("click", async (e) => {
    e.preventDefault();
    if (loggiaOut) loggiaOut.textContent = "Testing…";

    try {
      const fd = new FormData();
      fd.append("action", "hcn_loggia_test");
      fd.append("nonce", nonce);

      const res = await fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: fd });
      const json = await res.json();

      if (!json || !json.success) {
        const msg = (json && json.data && json.data.message) ? json.data.message : "Test failed";
        if (loggiaOut) loggiaOut.textContent = "❌ " + msg;
        return;
      }

      if (loggiaOut) loggiaOut.textContent = "✅ " + json.data.message + "\nFirst property_id: " + (json.data.first_property_id || "n/a");
    } catch (err) {
      if (loggiaOut) loggiaOut.textContent = "❌ " + err.message;
    }
  });

  let logOffset = 0;
  let polling = false;

  function setListEmpty() {
    if (!listEl) return;
    listEl.innerHTML = "<em>Nothing imported yet in this session.</em>";
  }

  function escapeHtml(s) {
    return String(s || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function addImportedItem({ name, post_id, provider }) {
    if (!listEl) return;
    if (listEl.querySelector("em")) listEl.innerHTML = "";

    const div = document.createElement("div");
    div.style.padding = "6px 0";
    const link = post_id
      ? `<a href="${editBase}${post_id}" target="_blank" rel="noopener">edit</a>`
      : "";
    div.innerHTML = `<strong>${escapeHtml(name)}</strong> <span style="opacity:.7">(${provider || "hostfully"})</span> — post_id=${post_id} ${link}`;
    listEl.appendChild(div);
  }

  async function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));

    const res = await fetch(ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: fd,
    });

    const json = await res.json().catch(() => null);
    if (!json || !json.success) {
      const msg =
        json && json.data && json.data.message ? json.data.message : "Request failed";
      throw new Error(msg);
    }
    return json.data;
  }

  async function pollLog() {
    if (polling) return;
    polling = true;

    const tick = async () => {
      if (!polling) return;

      try {
        const data = await post({
          action: "hcn_get_log",
          nonce,
          offset: String(logOffset),
        });

        if (logEl && data && typeof data.chunk === "string" && data.chunk.length) {
          logEl.value += data.chunk;
          logEl.scrollTop = logEl.scrollHeight;
        }

        if (data && typeof data.offset === "number") logOffset = data.offset;
      } catch (e) {
        // ignore polling errors
      } finally {
        if (polling) setTimeout(tick, 3000);
      }
    };

    tick();
  }

  function stopPolling() {
    polling = false;
  }

  async function startQueue({ provider, mode, property_uid } = {}) {
    if (logEl) logEl.value = "";
    logOffset = 0;
    setListEmpty();

    const data = await post({
      action: "hcn_import_start",
      nonce,
      provider: provider || "hostfully",
      mode: mode || "all",
      property_uid: property_uid || "",
    });

    await pollLog();
    return data;
  }

  async function importIndex(jobId, index) {
    const data = await post({
      action: "hcn_import_single",
      nonce,
      job_id: jobId,
      index: String(index),
    });

    addImportedItem({
      name: data.name,
      post_id: data.post_id,
      provider: data.provider,
    });

    return data;
  }

  async function finishQueue(jobId) {
    if (!jobId) return;
    try {
      await post({
        action: "hcn_import_finish",
        nonce,
        job_id: jobId,
      });
    } catch (e) {
      // ignore
    } finally {
      stopPolling();
    }
  }

  async function runBox(box, action) {
    const provider = box.dataset.provider || "hostfully";
    const modeEl = box.querySelector('[data-role="mode"]');
    const mode = modeEl ? modeEl.value : (box.dataset.mode || "all");

    const firstNEl = box.querySelector('[data-role="first-n"]');
    const firstN = firstNEl ? Math.max(1, parseInt(firstNEl.value || "1", 10)) : 10;

    const singleEl = box.querySelector('[data-role="single-id"]');
    const singleId = singleEl ? (singleEl.value || "").trim() : "";

    if (action === "run-single" && !singleId) {
      alert("Enter an ID/UID first.");
      return;
    }

    const q = await startQueue({
      provider,
      mode,
      // Hostfully-only: server uses property_uid to build a 1-item queue
      property_uid: (provider === "hostfully" && action === "run-single") ? singleId : "",
    });

    const total = q.total || 0;

    if (action === "run-first") {
      const limit = Math.min(firstN, total);
      for (let i = 0; i < limit; i++) await importIndex(q.job_id, i);
    } else if (action === "run-all") {
      for (let i = 0; i < total; i++) await importIndex(q.job_id, i);
    } else if (action === "run-single") {
      if (provider === "hostfully") {
        if (total > 0) await importIndex(q.job_id, 0);
      } else if (provider === "loggia") {
        alert("Loggia single import needs a small server-side tweak. Use Run First N for now.");
      } else {
        alert("Single import is only supported in the Hostfully/Loggia sections.");
      }
    }

    await finishQueue(q.job_id);
  }

  const boxes = document.querySelectorAll(".hcn-import-box");
  // quick safety: if this is 0, you're on the wrong tab or markup mismatch
  if (!boxes.length) return;

  boxes.forEach((box) => {
    box.querySelectorAll("[data-action]").forEach((btn) => {
      btn.addEventListener("click", async (e) => {
        e.preventDefault();
        const action = btn.dataset.action;
        try {
          await runBox(box, action);
        } catch (err) {
          alert(err.message);
          stopPolling();
        }
      });
    });
  });
});