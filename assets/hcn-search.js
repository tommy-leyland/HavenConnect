document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector(".hcn-search-form");
  if (!form) return;

  const ajaxUrl = form.dataset.ajax;
  const nonce = form.dataset.nonce;
  const perPage = form.dataset.perPage || "12";

  const statusEl = form.parentElement.querySelector(".hcn-search-status");
  const resultsWrap = form.parentElement.querySelector(".hcn-results-wrap");

  async function runSearch({ pushUrl = true } = {}) {
    const fd = new FormData(form);

    // Build querystring for URL
    const params = new URLSearchParams();
    for (const [k, v] of fd.entries()) {
      if (v !== "" && v !== null) params.set(k, v);
    }

    if (pushUrl) {
      const newUrl = `${window.location.pathname}?${params.toString()}`;
      window.history.pushState({ hcn: true }, "", newUrl);
    }

    fd.append("action", "hcn_property_search");
    fd.append("nonce", nonce);
    fd.append("per_page", perPage);

    statusEl.textContent = "Searching…";

    try {
      const res = await fetch(ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: fd,
      });

      const json = await res.json();

      if (!json || !json.success) {
        statusEl.textContent =
          (json && json.data && json.data.message) ? json.data.message : "Search failed.";
        return;
      }

      resultsWrap.innerHTML = json.data.html || "<p>No properties found.</p>";
      statusEl.textContent = "";

    } catch (err) {
      statusEl.textContent = "Search error. Check console/logs.";
      console.error(err);
    }
  }

  ["change"].forEach((evt) => {
    form.addEventListener(evt, (e) => {
        if (!e.target.name) return;
        runSearch({ pushUrl: true });
    });
    });

  // Submit handler
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    runSearch({ pushUrl: true });
  });

  // Back/forward handler
  window.addEventListener("popstate", () => {
    // Put URL params back into the form
    const urlParams = new URLSearchParams(window.location.search);
    for (const el of form.elements) {
      if (!el.name) continue;
      if (urlParams.has(el.name)) el.value = urlParams.get(el.name);
      else el.value = ""; // reset if param missing
    }
    runSearch({ pushUrl: false });
  });

    // Load results on first page load (no params needed)
    runSearch({ pushUrl: false });

});