(function () {
  const init = () => {
    const form = document.querySelector("[data-hcn-searchbar]");
    if (!form) return;

    const cfg = window.HCN_SEARCH_BAR || {};
    const ajaxMode = !!cfg.ajax;

    // open button already exists in your bar
    const openBtn = form.querySelector('[data-hcn-open="filters"]');

    const overlay = form.querySelector("[data-hcn-filters-overlay]");
    const closeBtn = form.querySelector("[data-hcn-filters-close]");
    const clearBtn = form.querySelector("[data-hcn-filters-clear]");
    const applyBtn = form.querySelector("[data-hcn-filters-apply]");

    const accPrice = form.querySelector('[data-hcn-facc="price"]');
    const accRooms = form.querySelector('[data-hcn-facc="rooms"]');
    const accFeat  = form.querySelector('[data-hcn-facc="features"]');
    const allAcc = [accPrice, accRooms, accFeat].filter(Boolean);

    // Hidden inputs (must exist in shortcode)
    const inMin = form.querySelector('[name="min_price"]');
    const inMax = form.querySelector('[name="max_price"]');
    const inBeds = form.querySelector('[name="bedrooms"]');
    const inBaths = form.querySelector('[name="bathrooms"]');
    const inFeatures = form.querySelector('[name="features"]'); // comma-separated slugs

    // Price UI
    const minBox = form.querySelector("[data-hcn-price-min]");
    const maxBox = form.querySelector("[data-hcn-price-max]");
    const minR = form.querySelector("[data-hcn-price-min-range]");
    const maxR = form.querySelector("[data-hcn-price-max-range]");

    // Rooms UI
    const bedOut = form.querySelector("[data-hcn-bed-out]");
    const bathOut = form.querySelector("[data-hcn-bath-out]");

    // Features UI
    const chipsWrap = form.querySelector("[data-hcn-feature-chips]");

    const emitSearchUpdated = () => window.dispatchEvent(new CustomEvent("hcn:search-updated"));

    const buildParamsFromForm = () => {
      const fd = new FormData(form);
      const p = new URLSearchParams();
      for (const [k, v] of fd.entries()) {
        const val = String(v ?? "").trim();
        if (val !== "") p.set(k, val);
      }
      return p;
    };

    const syncUrlFromForm = () => {
      if (!ajaxMode) return;
      const p = buildParamsFromForm();
      const url = new URL(window.location.href);
      url.search = p.toString();
      window.history.replaceState({}, "", url.toString());
      emitSearchUpdated();
    };

    const openPopup = (section = "price") => {
      if (!overlay) return;
      overlay.classList.add("is-open");
      overlay.setAttribute("aria-hidden", "false");
      openAccordion(section);
    };

    const closePopup = () => {
      if (!overlay) return;
      overlay.classList.remove("is-open");
      overlay.setAttribute("aria-hidden", "true");
    };

    const openAccordion = (key) => {
      allAcc.forEach(a => a.classList.remove("is-open"));
      const target =
        key === "price" ? accPrice :
        key === "rooms" ? accRooms :
        key === "features" ? accFeat : null;
      if (target) target.classList.add("is-open");
    };

    // header clicks
    allAcc.forEach(acc => {
      const head = acc.querySelector("[data-hcn-facc-head]");
      head?.addEventListener("click", () => openAccordion(acc.getAttribute("data-hcn-facc")));
    });

    // Open/close events
    openBtn?.addEventListener("click", (e) => { e.preventDefault(); openPopup("price"); });
    closeBtn?.addEventListener("click", (e) => { e.preventDefault(); closePopup(); });
    overlay?.addEventListener("click", (e) => { if (e.target === overlay) closePopup(); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") closePopup(); });

    // ---------- init values from hidden inputs ----------
    const num = (v) => {
      const n = parseInt(String(v || "").trim(), 10);
      return Number.isFinite(n) ? n : 0;
    };

    // Price ranges: keep sane clamp + non-crossing
    const clamp = (n, a, b) => Math.max(a, Math.min(b, n));

    const getRangeBounds = () => {
      // Use cfg.priceBounds if present, else fallback 0–1000
      const min = num(cfg.priceMin) || 0;
      const max = num(cfg.priceMax) || 1000;
      return { min, max };
    };

    const setPriceUI = (minVal, maxVal) => {
      const b = getRangeBounds();
      let a = clamp(minVal, b.min, b.max);
      let c = clamp(maxVal, b.min, b.max);
      if (a > c) [a, c] = [c, a];

      if (minBox) minBox.value = a ? String(a) : "";
      if (maxBox) maxBox.value = c ? String(c) : "";
      if (minR) minR.value = String(a);
      if (maxR) maxR.value = String(c);

      // hidden inputs
      if (inMin) inMin.value = a ? String(a) : "";
      if (inMax) inMax.value = c ? String(c) : "";

      // accordion value
      const v = accPrice?.querySelector("[data-hcn-facc-value]");
      if (v) v.textContent = (a || c) ? `£${a || 0} – £${c || b.max}` : "Any";
    };

    const setRoomsUI = (beds, baths) => {
      const b = Math.max(0, beds);
      const ba = Math.max(0, baths);

      if (bedOut) bedOut.textContent = String(b);
      if (bathOut) bathOut.textContent = String(ba);

      if (inBeds) inBeds.value = b ? String(b) : "";
      if (inBaths) inBaths.value = ba ? String(ba) : "";

      const v = accRooms?.querySelector("[data-hcn-facc-value]");
      if (v) v.textContent = (b || ba) ? `${b || 0}+ beds · ${ba || 0}+ baths` : "Any";
    };

    const selectedFeatures = new Set(
      String(inFeatures?.value || "").split(",").map(s => s.trim()).filter(Boolean)
    );

    const renderChips = () => {
      if (!chipsWrap) return;
      const feats = Array.isArray(cfg.featuredFeatures) ? cfg.featuredFeatures : [];

      if (!feats.length) {
        chipsWrap.innerHTML = `<div class="hcn-muted" style="font-size:12px; opacity:.7;">No featured features set in Settings yet.</div>`;
        return;
      }

      chipsWrap.innerHTML = feats.map(f => {
        const on = selectedFeatures.has(String(f.slug));
        return `<button type="button" class="hcn-chip ${on ? "is-on" : ""}" data-slug="${String(f.slug)}">${String(f.name)}</button>`;
      }).join("");

      chipsWrap.querySelectorAll(".hcn-chip").forEach(btn => {
        btn.addEventListener("click", () => {
          const slug = btn.getAttribute("data-slug");
          if (!slug) return;
          if (selectedFeatures.has(slug)) selectedFeatures.delete(slug);
          else selectedFeatures.add(slug);

          // write hidden
          if (inFeatures) inFeatures.value = Array.from(selectedFeatures).join(",");

          // update accordion value
          const v = accFeat?.querySelector("[data-hcn-facc-value]");
          if (v) v.textContent = selectedFeatures.size ? `${selectedFeatures.size} selected` : "Any";

          renderChips();
        });
      });
    };

    // steppers
    const bindStepper = (wrap, outEl, onChange) => {
      if (!wrap) return;
      const minus = wrap.querySelector('[data-hcn-step="-"]');
      const plus = wrap.querySelector('[data-hcn-step="+"]');

      const get = () => parseInt(outEl?.textContent || "0", 10) || 0;
      const set = (n) => { if (outEl) outEl.textContent = String(Math.max(0, n)); };

      minus?.addEventListener("click", (e) => { e.preventDefault(); set(get() - 1); onChange(); });
      plus?.addEventListener("click", (e) => { e.preventDefault(); set(get() + 1); onChange(); });
    };

    // price bind
    const onPriceChange = () => setPriceUI(num(minBox?.value), num(maxBox?.value));

    minBox?.addEventListener("input", onPriceChange);
    maxBox?.addEventListener("input", onPriceChange);

    minR?.addEventListener("input", () => setPriceUI(num(minR.value), num(maxR?.value)));
    maxR?.addEventListener("input", () => setPriceUI(num(minR?.value), num(maxR.value)));

    // rooms bind
    const roomsWrap = form.querySelector("[data-hcn-rooms-wrap]");
    const bedsWrap = roomsWrap?.querySelector('[data-hcn-room="bedrooms"]');
    const bathsWrap = roomsWrap?.querySelector('[data-hcn-room="bathrooms"]');
    bindStepper(bedsWrap, bedOut, () => setRoomsUI(num(bedOut?.textContent), num(bathOut?.textContent)));
    bindStepper(bathsWrap, bathOut, () => setRoomsUI(num(bedOut?.textContent), num(bathOut?.textContent)));

    // Init UI from query
    setPriceUI(num(inMin?.value), num(inMax?.value));
    setRoomsUI(num(inBeds?.value), num(inBaths?.value));

    // Init features value label
    const fv = accFeat?.querySelector("[data-hcn-facc-value]");
    if (fv) fv.textContent = selectedFeatures.size ? `${selectedFeatures.size} selected` : "Any";
    renderChips();

    // Clear / Apply
    clearBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      if (inMin) inMin.value = "";
      if (inMax) inMax.value = "";
      if (inBeds) inBeds.value = "";
      if (inBaths) inBaths.value = "";
      if (inFeatures) inFeatures.value = "";
      selectedFeatures.clear();

      setPriceUI(0, getRangeBounds().max);
      setRoomsUI(0, 0);
      renderChips();
      syncUrlFromForm();
    });

    applyBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      closePopup();
      syncUrlFromForm();
    });
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();