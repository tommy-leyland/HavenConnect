document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector(".hcn-search-form");
  if (!form) return;

  const ajaxUrl = form.dataset.ajax;
  const nonce = form.dataset.nonce;
  const perPage = form.dataset.perPage || "12";

  // Robust root lookup (Elementor-safe)
  const root = form.closest("[data-hcn-results-root]") || form.closest(".hcn-search-results") || form.parentElement;
  const statusEl = root ? root.querySelector(".hcn-search-status") : null;
  const resultsWrap = root ? root.querySelector(".hcn-results-wrap") : null;
  if (!resultsWrap) return;

  const syncFormFromUrl = () => {
    const urlParams = new URLSearchParams(window.location.search);
    for (const el of form.elements) {
      if (!el.name) continue;
      el.value = urlParams.has(el.name) ? urlParams.get(el.name) : "";
    }
  };

  const paramsFromForm = () => {
    const fd = new FormData(form);
    const p = new URLSearchParams();
    for (const [k, v] of fd.entries()) {
      const val = String(v ?? "").trim();
      if (val !== "") p.set(k, val);
    }
    return p;
  };

  const emitSearchUpdated = () => {
    // Your map + search bar already use window, so keep it consistent.
    window.dispatchEvent(new CustomEvent("hcn:search-updated"));
  };

  const pushUrlFromForm = (push = true) => {
    const p = paramsFromForm();
    const url = new URL(window.location.href);
    url.search = p.toString();
    if (push) window.history.pushState({ hcn: true }, "", url.toString());
    else window.history.replaceState({ hcn: true }, "", url.toString());
  };

  const runSearch = async () => {
    const fd = new FormData(form);
    fd.append("action", "hcn_property_search");
    fd.append("nonce", nonce);
    fd.append("per_page", perPage);

    if (statusEl) statusEl.textContent = "Searching…";

    try {
      const res = await fetch(ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: fd,
      });

      const json = await res.json();

      if (!json || !json.success) {
        if (statusEl) {
          statusEl.textContent =
            (json && json.data && json.data.message) ? json.data.message : "Search failed.";
        }
        return;
      }

      resultsWrap.innerHTML = json.data.html || "";
      if (statusEl) statusEl.textContent = "";

      // Results should ONLY emit this event
      document.dispatchEvent(new CustomEvent("hcn:results-updated"));
    } catch (err) {
      if (statusEl) statusEl.textContent = "Search error. Check console/logs.";
      console.error(err);
    }
  };

  // Manual submit (button inside results shortcode)
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    pushUrlFromForm(true);
    emitSearchUpdated();
  });

  // Auto-search on change (keeps old behaviour)
  form.addEventListener("change", (e) => {
    if (!e.target || !e.target.name) return;
    pushUrlFromForm(false); // replace (avoid spamming history)
    emitSearchUpdated();
  });

  // External search bar -> emits on window; we sync + run
  window.addEventListener("hcn:search-updated", () => {
    syncFormFromUrl();
    runSearch();
  });

  // Back/forward
  window.addEventListener("popstate", () => {
    syncFormFromUrl();
    runSearch();
  });

  // Initial
  syncFormFromUrl();
  runSearch();
});