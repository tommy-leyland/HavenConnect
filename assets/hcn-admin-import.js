document.addEventListener("DOMContentLoaded", () => {
  if (!window.HCN_IMPORT || !window.HCN_IMPORT.ajaxUrl) return;

  const ajaxUrl = window.HCN_IMPORT.ajaxUrl;
  const nonce   = window.HCN_IMPORT.nonce;
  const editBase = window.HCN_IMPORT.editBase || "";

  // Your current IDs:
  const runFirstBtn  = document.getElementById("hcn-run-first-btn");
  const runAllBtn    = document.getElementById("hcn-run-all-btn");
  const runSingleBtn = document.getElementById("hcn-run-single-btn");

  const firstNEl  = document.getElementById("hcn-run-first-n");
  const singleEl  = document.getElementById("hcn-single-uid");

  const logEl     = document.getElementById("hcn-log");
  const listEl    = document.getElementById("hcn-imported-list");

  // Optional (if you add them later; safe if missing)
  const providerEl = document.getElementById("hcn-provider"); // hostfully|loggia|both
  const modeEl     = document.getElementById("hcn-mode");     // all|featured

  let jobId = null;
  let logOffset = 0;
  let polling = false;

  function setListEmpty() {
    if (listEl) listEl.innerHTML = "<em>Nothing imported yet in this session.</em>";
  }

  function addImportedItem({ name, post_id, provider }) {
    if (!listEl) return;
    if (listEl.querySelector("em")) listEl.innerHTML = "";

    const div = document.createElement("div");
    div.style.padding = "6px 0";
    const link = post_id ? `<a href="${editBase}${post_id}" target="_blank" rel="noopener">edit</a>` : "";
    div.innerHTML = `<strong>${escapeHtml(name)}</strong> <span style="opacity:.7">(${provider || "hostfully"})</span> — post_id=${post_id} ${link}`;
    listEl.appendChild(div);
  }

  function escapeHtml(s) {
    return String(s || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
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
      const msg = (json && json.data && json.data.message) ? json.data.message : "Request failed";
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

  function getProvider() {
    // Default to both if you want BOTH imports without changing UI.
    // If you add a dropdown later, it will override.
    return providerEl ? providerEl.value : "both";
  }

  function getMode() {
    // Hostfully only; default all
    return modeEl ? modeEl.value : "all";
  }

    async function startQueue({ property_uid = "" } = {}) {
    if (logEl) logEl.value = "";
    logOffset = 0;
    setListEmpty();

    const data = await post({
        action: "hcn_import_start",
        nonce,
        provider: getProvider(),
        mode: getMode(),
        property_uid: property_uid || "",
    });

    jobId = data.job_id;

    // Start polling only after queue exists
    await pollLog();

    return data;
    }

  async function importIndex(index) {
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

  async function finishQueue() {
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

  async function runFirstN() {
    const n = Math.max(1, parseInt(firstNEl?.value || "1", 10));

    const q = await startQueue();
    const total = q.total || 0;
    const limit = Math.min(n, total);

    for (let i = 0; i < limit; i++) {
      await importIndex(i);
    }

    await finishQueue();
  }

  async function runAll() {
    const q = await startQueue();
    const total = q.total || 0;

    for (let i = 0; i < total; i++) {
      await importIndex(i);
    }

    await finishQueue();
  }

  async function runSingle() {
    const uid = (singleEl?.value || "").trim();
    if (!uid) {
      alert("Enter a property UID");
      return;
    }

    // Single UID is Hostfully-only; provider still “both” is fine (Loggia just won’t add single uid)
    const q = await startQueue({ property_uid: uid });
    const total = q.total || 0;
    if (total > 0) await importIndex(0);

    await finishQueue();
  }

  runFirstBtn?.addEventListener("click", async (e) => {
    e.preventDefault();
    try { await runFirstN(); } catch (err) { alert(err.message); stopPolling(); }
  });

  runAllBtn?.addEventListener("click", async (e) => {
    e.preventDefault();
    try { await runAll(); } catch (err) { alert(err.message); stopPolling(); }
  });

  runSingleBtn?.addEventListener("click", async (e) => {
    e.preventDefault();
    try { await runSingle(); } catch (err) { alert(err.message); stopPolling(); }
  });
});