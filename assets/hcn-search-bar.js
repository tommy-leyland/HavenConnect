(function () {
  const init = () => {
    const form = document.querySelector('[data-hcn-searchbar]');
    if (!form) return;

    const cfg = window.HCN_SEARCH_BAR || {};
    const ajaxMode = !!cfg.ajax;

    const modal = (name) => form.querySelector(`[data-hcn-modal="${name}"]`);
    const openBtns = form.querySelectorAll("[data-hcn-open]");
    const closeEls = form.querySelectorAll("[data-hcn-close]");
    const datesLabel = form.querySelector("[data-hcn-dates-label]");

    const checkinHidden = form.querySelector("[data-hcn-checkin]");
    const checkoutHidden = form.querySelector("[data-hcn-checkout]");
    const guestsMain = form.querySelector("[data-hcn-guests]");
    const locationMain = form.querySelector("[data-hcn-location]");
    const bedroomsHidden = form.querySelector("[data-hcn-bedrooms]");
    const bathroomsHidden = form.querySelector("[data-hcn-bathrooms]");

    const dot = form.querySelector("[data-hcn-filters-dot]");

    const showModal = (name) => {
      const m = modal(name);
      if (!m) return;
      m.setAttribute("aria-hidden", "false");
    };

    const hideModals = () => {
      form.querySelectorAll(".hcn-modal").forEach(m => m.setAttribute("aria-hidden", "true"));
    };

    openBtns.forEach(btn => {
      btn.addEventListener("click", () => showModal(btn.getAttribute("data-hcn-open")));
    });

    closeEls.forEach(el => el.addEventListener("click", hideModals));
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") hideModals(); });

    const setDatesLabel = () => {
      const a = (checkinHidden?.value || "").trim();
      const b = (checkoutHidden?.value || "").trim();
      if (!datesLabel) return;
      if (a && b) datesLabel.textContent = `${a} → ${b}`;
      else datesLabel.textContent = "Add dates";
    };

    const updateDot = () => {
      const has =
        (guestsMain && guestsMain.value) ||
        (bedroomsHidden && bedroomsHidden.value) ||
        (bathroomsHidden && bathroomsHidden.value);
      if (dot) dot.style.display = has ? "block" : "none";
    };

    // ---------- AJAX mode: update URL + trigger event ----------
    const emitSearchUpdated = () => {
      window.dispatchEvent(new CustomEvent("hcn:search-updated"));
    };

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
      // Replace only our params, keep any other existing params if you want.
      // (Simpler: replace all query with ours)
      url.search = p.toString();
      window.history.replaceState({}, "", url.toString());
      emitSearchUpdated();
    };

    // Debounced URL updates (typing location)
    let t = null;
    const debouncedSync = () => {
      if (!ajaxMode) return;
      if (t) clearTimeout(t);
      t = setTimeout(syncUrlFromForm, 250);
    };

    // ---------- Filters modal logic ----------
    const fg = form.querySelector("[data-hcn-filter-guests]");
    const fb = form.querySelector("[data-hcn-filter-bedrooms]");
    const fa = form.querySelector("[data-hcn-filter-bathrooms]");
    const applyFilters = form.querySelector("[data-hcn-apply-filters]");
    const clearFilters = form.querySelector("[data-hcn-clear-filters]");

    applyFilters?.addEventListener("click", () => {
      if (guestsMain && fg) guestsMain.value = fg.value || "";
      if (bedroomsHidden && fb) bedroomsHidden.value = fb.value || "";
      if (bathroomsHidden && fa) bathroomsHidden.value = fa.value || "";
      updateDot();
      hideModals();
      syncUrlFromForm();
    });

    clearFilters?.addEventListener("click", () => {
      if (fg) fg.value = "";
      if (fb) fb.value = "";
      if (fa) fa.value = "";
      if (bedroomsHidden) bedroomsHidden.value = "";
      if (bathroomsHidden) bathroomsHidden.value = "";
      // leave main guests as-is, or clear it too:
      // if (guestsMain) guestsMain.value = "";
      updateDot();
      hideModals();
      syncUrlFromForm();
    });

    // ---------- Dates range calendar ----------
    const calGrid  = form.querySelector("[data-hcn-cal-grid]");
    const calMonth = form.querySelector("[data-hcn-cal-month]");
    const btnPrev  = form.querySelector("[data-hcn-cal-prev]");
    const btnNext  = form.querySelector("[data-hcn-cal-next]");
    const hint     = form.querySelector("[data-hcn-cal-hint]");

    const applyDates = form.querySelector("[data-hcn-apply-dates]");
    const clearDates = form.querySelector("[data-hcn-clear-dates]");

    let view = new Date();
    view.setDate(1);

    let selIn  = (checkinHidden?.value || "");
    let selOut = (checkoutHidden?.value || "");

    const ymd = (d) => {
      const y = d.getFullYear();
      const m = String(d.getMonth()+1).padStart(2,"0");
      const da = String(d.getDate()).padStart(2,"0");
      return `${y}-${m}-${da}`;
    };
    const parseYmd = (s) => s ? new Date(s + "T00:00:00") : null;

    const renderCal = () => {
      if (!calGrid || !calMonth) return;

      const year = view.getFullYear();
      const month = view.getMonth();
      calMonth.textContent = view.toLocaleString(undefined, { month: "long", year: "numeric" });

      const first = new Date(year, month, 1);
      const firstDay = (first.getDay() + 6) % 7; // Monday start
      const daysInMonth = new Date(year, month+1, 0).getDate();

      calGrid.innerHTML = "";

      const inDate = parseYmd(selIn);
      const outDate = parseYmd(selOut);

      for (let i=0;i<firstDay;i++){
        const b = document.createElement("button");
        b.type="button";
        b.className="hcn-cal__day is-muted";
        b.textContent="";
        b.disabled=true;
        calGrid.appendChild(b);
      }

      for (let day=1; day<=daysInMonth; day++){
        const d = new Date(year, month, day);
        const s = ymd(d);

        const btn = document.createElement("button");
        btn.type="button";
        btn.className="hcn-cal__day";
        btn.textContent = String(day);

        if (selIn && s === selIn) btn.classList.add("is-start");
        if (selOut && s === selOut) btn.classList.add("is-end");
        if (inDate && outDate && d > inDate && d < outDate) btn.classList.add("is-inrange");

        btn.addEventListener("click", () => {
          if (!selIn || (selIn && selOut)) {
            selIn = s;
            selOut = "";
            if (hint) hint.textContent = "Now pick check-out";
          } else {
            if (s <= selIn) {
              selIn = s;
              selOut = "";
              if (hint) hint.textContent = "Now pick check-out";
            } else {
              selOut = s;
              if (hint) hint.textContent = "Dates selected";
            }
          }
          renderCal();
        });

        calGrid.appendChild(btn);
      }
    };

    btnPrev?.addEventListener("click", () => { view.setMonth(view.getMonth()-1); renderCal(); });
    btnNext?.addEventListener("click", () => { view.setMonth(view.getMonth()+1); renderCal(); });

    applyDates?.addEventListener("click", () => {
      if (checkinHidden) checkinHidden.value = selIn || "";
      if (checkoutHidden) checkoutHidden.value = selOut || "";
      setDatesLabel();
      hideModals();
      syncUrlFromForm();
    });

    clearDates?.addEventListener("click", () => {
      selIn = ""; selOut = "";
      if (checkinHidden) checkinHidden.value = "";
      if (checkoutHidden) checkoutHidden.value = "";
      if (hint) hint.textContent = "Select check-in, then check-out";
      setDatesLabel();
      renderCal();
      hideModals();
      syncUrlFromForm();
    });

    // Refresh calendar selection when opening dates modal
    form.querySelector('[data-hcn-open="dates"]')?.addEventListener("click", () => {
      selIn = checkinHidden?.value || "";
      selOut = checkoutHidden?.value || "";
      if (hint) hint.textContent = selIn && !selOut ? "Now pick check-out" : "Select check-in, then check-out";
      renderCal();
    });

    // Live updates from main fields
    locationMain?.addEventListener("input", debouncedSync);
    guestsMain?.addEventListener("input", debouncedSync);

    // Also keep filters modal numbers in sync when opened
    form.querySelector('[data-hcn-open="filters"]')?.addEventListener("click", () => {
      if (fg && guestsMain) fg.value = guestsMain.value || "";
      if (fb && bedroomsHidden) fb.value = bedroomsHidden.value || "";
      if (fa && bathroomsHidden) fa.value = bathroomsHidden.value || "";
      updateDot();
    });

    // Init
    setDatesLabel();
    updateDot();
    renderCal();
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();