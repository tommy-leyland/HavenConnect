document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector(".hcn-search-form");
  if (!form) return;

  const ajaxUrl = form.dataset.ajax;
  const nonce = form.dataset.nonce;
  const perPage = form.dataset.perPage || "12";

  const root = form.closest("[data-hcn-results-root]") || form.parentElement;
  const statusEl = root ? root.querySelector(".hcn-search-status") : null;
  const resultsWrap = root ? root.querySelector(".hcn-results-wrap") : null;

  function syncFormFromUrl() {
    const urlParams = new URLSearchParams(window.location.search);
    for (const el of form.elements) {
      if (!el.name) continue;
      el.value = urlParams.has(el.name) ? urlParams.get(el.name) : "";
    }
  }

  async function runSearch() {
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

      if (resultsWrap) resultsWrap.innerHTML = json.data.html || "";
      if (statusEl) statusEl.textContent = "";

      // results should ONLY emit this event
      document.dispatchEvent(new CustomEvent("hcn:results-updated"));
    } catch (err) {
      if (statusEl) statusEl.textContent = "Search error. Check console/logs.";
      console.error(err);
    }
  }

  // Initial load
  syncFormFromUrl();
  runSearch();

  // When search bar updates URL, it should emit this
  document.addEventListener("hcn:search-updated", () => {
    syncFormFromUrl();
    runSearch();
  });

  // Back/forward
  window.addEventListener("popstate", () => {
    syncFormFromUrl();
    runSearch();
  });
});