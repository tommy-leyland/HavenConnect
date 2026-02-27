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

      const res = await fetch(ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body: fd
      });

      const ct = (res.headers.get("content-type") || "").toLowerCase();
      if (!ct.includes("application/json")) {
        const text = await res.text();
        const where = res.redirected ? ` (redirected to ${res.url})` : "";
        throw new Error(`Non-JSON response ${res.status}${where}: ${text.slice(0, 200)}`);
      }

      const json = await res.json();
      if (!json || !json.success) {
        const msg = (json && json.data && json.data.message) ? json.data.message : "Test failed";
        if (loggiaOut) loggiaOut.textContent = "❌ " + msg;
        return;
      }

      if (loggiaOut) {
        loggiaOut.textContent = "✅ " + json.data.message + "\nFirst property_id: " + (json.data.first_property_id || "n/a");
      }
    } catch (err) {
      if (loggiaOut) loggiaOut.textContent = "❌ " + err.message;
    }
  });

  let logOffset = 0;
  let polling = false;

  function setListEmpty() {
    if (!listEl) return;
    listEl.innerHTML = "Nothing imported yet in this session.";
  }

  function escapeHtml(s) {
    return String(s || "")
      .replaceAll("&", "&")
      .replaceAll("<", "<")
      .replaceAll(">", ">")
      .replaceAll('"', """)
      .replaceAll("'", "'");
  }

  function addImportedItem({ name, post_id, provider }) {
    if (!listEl) return;
    if (listEl.querySelector("em")) listEl.innerHTML = "";
    const div = document.createElement("div");
    div.style.padding = "6px 0";
    const link = post_id ? `` : "";
    div.innerHTML = `${escapeHtml(name)} (${provider || "hostfully"}) — post_id=${post_id} ${link}`;
    listEl.appendChild(div);
  }

  async function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));

    const res = await fetch(ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd,
    });

    const ct = (res.headers.get("content-type") || "").toLowerCase();
    const text = await res.text();

    if (!ct.includes("application/json")) {
      throw new Error(`Non-JSON response ${res.status}: ${text.slice(0, 200)}`);
    }

    const json = JSON.parse(text);
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

  // ✅ MINIMAL CHANGE: accept first_n and pass it to server
  async function startQueue({ provider, mode, property_uid, first_n } = {}) {
    if (logEl) logEl.value = "";
    logOffset = 0;
    setListEmpty();

    const data = await post({
      action: "hcn_import_start",
      nonce,
      provider: provider || "hostfully",
      mode: mode || "all",
      property_uid: property_uid || "",
      first_n: String(first_n || 0), // <-- NEW
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
    const provider = (box.dataset.provider === "all" ? "both" : box.dataset.provider) || "hostfully";

    const modeEl = box.querySelector('[data-role="mode"]');
    const mode = modeEl ? modeEl.value : (box.dataset.mode || "all");

    const firstNEl = box.querySelector('[data-role="first-n"]');
    const firstN = firstNEl ? Math.max(1, parseInt(firstNEl.value || "1", 10)) : 10;

    const singleEl = box.querySelector('[data-role="single-id"]');
    const singleId = singleEl ? (singleEl.value || "").trim() : "";

    // --- Loggia: Sync availability/pricing (no queue) ---
    if (action === "sync-avail") {
      const fromEl = box.querySelector('[data-role="avail-from"]');
      const toEl = box.querySelector('[data-role="avail-to"]');
      const from = fromEl && fromEl.value ? fromEl.value : "";
      const to = toEl && toEl.value ? toEl.value : "";
      const pid = (singleId || "").trim();
      if (!pid) throw new Error("Enter a Loggia property ID first (use the ‘Test single ID’ box).");

      const res = await post({
        action: "hcn_loggia_availability_sync",
        nonce,
        property_id: pid,
        from,
        to
      });

      alert(res.message || "Availability sync complete.");
      return;
    }

    if (action === "run-single" && !singleId) {
      alert("Enter an ID/UID first.");
      return;
    }

    // ✅ MINIMAL CHANGE: for run-first, tell server our first_n
    const q = await startQueue({
      provider,
      mode,
      // Hostfully-only single test
      property_uid: (action === "run-single") ? singleId : "",
      first_n: (action === "run-first") ? firstN : 0, // <-- NEW
    });

    const total = q.total || 0;

    if (action === "run-first") {
      const limit = Math.min(firstN, total);
      for (let i = 0; i < limit; i++) await importIndex(q.job_id, i);
    } else if (action === "run-all") {
      for (let i = 0; i < total; i++) await importIndex(q.job_id, i);
    } else if (action === "run-single") {
      if (total > 0) await importIndex(q.job_id, 0);
    }

    await finishQueue(q.job_id);
  }

  const pingBtn = document.getElementById("hcn-ping-btn");
  const pingOut = document.getElementById("hcn-ping-out");

  pingBtn?.addEventListener("click", async (e) => {
    e.preventDefault();
    if (pingOut) pingOut.textContent = "Pinging…";
    try {
      const fd = new FormData();
      fd.append("action", "hcn_ping");
      fd.append("nonce", nonce);

      const res = await fetch(ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        body: fd
      });

      const ct = (res.headers.get("content-type") || "").toLowerCase();
      const text = await res.text();

      if (!ct.includes("application/json")) {
        throw new Error(`Non-JSON response ${res.status}: ${text.slice(0, 120)}`);
      }

      const json = JSON.parse(text);
      pingOut.textContent = json.success ? `✅ ${json.data.message}` : `❌ ${json.data.message}`;
    } catch (err) {
      if (pingOut) pingOut.textContent = "❌ " + err.message;
    }
  });

  const boxes = document.querySelectorAll(".hcn-import-box");
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