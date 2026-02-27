(function () {
  const init = () => {
    const form = document.querySelector("[data-hcn-searchbar]");
    if (!form) return;

    const cfg = window.HCN_SEARCH_BAR || {};
    const ajaxMode = !!cfg.ajax;

    // Inputs (source of truth for URL sync)
    const locationInput = form.querySelector("[data-hcn-location]");
    const checkinHidden = form.querySelector("[data-hcn-checkin]");
    const checkoutHidden = form.querySelector("[data-hcn-checkout]");
    const guestsHidden = form.querySelector("[data-hcn-guests]");

    // Triggers in the bar
    const trigLoc = form.querySelector('[data-hcn-sheet-open="location"]');
    const trigDates = form.querySelector('[data-hcn-sheet-open="dates"]');
    const trigGuests = form.querySelector('[data-hcn-sheet-open="guests"]');

    // Labels in the bar
    const locLabel = form.querySelector("[data-hcn-location-label]");
    const datesLabel = form.querySelector("[data-hcn-dates-label]");
    const guestsLabel = form.querySelector("[data-hcn-guests-label]");

    // Sheet elements
    const overlay = form.querySelector("[data-hcn-sheet-overlay]");
    const closeBtn = form.querySelector("[data-hcn-sheet-close]");
    const clearBtn = form.querySelector("[data-hcn-sheet-clear]");
    const applyBtn = form.querySelector("[data-hcn-sheet-apply]");

    const accLoc = form.querySelector('[data-hcn-acc="location"]');
    const accDates = form.querySelector('[data-hcn-acc="dates"]');
    const accGuests = form.querySelector('[data-hcn-acc="guests"]');

    const allAcc = [accLoc, accDates, accGuests].filter(Boolean);

    // Location panel
    const locSearch = form.querySelector("[data-hcn-loc-search]");
    const locItems = form.querySelectorAll("[data-hcn-loc-pick]");

    // Guests panel
    const gAdults = form.querySelector('[data-hcn-guest="adults"]');
    const gChildren = form.querySelector('[data-hcn-guest="children"]');
    const gInfants = form.querySelector('[data-hcn-guest="infants"]');
    const gPets = form.querySelector('[data-hcn-guest="pets"]');

    // Calendar panel
    const calGrid = form.querySelector("[data-hcn-cal-grid]");
    const calMonth = form.querySelector("[data-hcn-cal-month]");
    const btnPrev = form.querySelector("[data-hcn-cal-prev]");
    const btnNext = form.querySelector("[data-hcn-cal-next]");
    const hint = form.querySelector("[data-hcn-cal-hint]");

    // ---------- URL sync / events ----------
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

    // ---------- helpers ----------
    const openSheet = (section) => {
      if (!overlay) return;
      overlay.classList.add("is-open");
      overlay.setAttribute("aria-hidden", "false");
      openAccordion(section);
    };

    const closeSheet = () => {
      if (!overlay) return;
      overlay.classList.remove("is-open");
      overlay.setAttribute("aria-hidden", "true");
    };

    const openAccordion = (section) => {
      allAcc.forEach((el) => el.classList.remove("is-open"));
      const target =
        section === "location" ? accLoc :
        section === "dates" ? accDates :
        section === "guests" ? accGuests : null;
      if (target) target.classList.add("is-open");
    };

    const setBarLabels = () => {
      // Location
      if (locLabel) {
        const v = (locationInput?.value || "").trim();
        locLabel.textContent = v || "Add location";
      }

      // Dates
      if (datesLabel) {
        const a = (checkinHidden?.value || "").trim();
        const b = (checkoutHidden?.value || "").trim();
        datesLabel.textContent = (a && b) ? `${a} → ${b}` : "Add dates";
      }

      // Guests
      if (guestsLabel) {
        const n = parseInt(guestsHidden?.value || "0", 10) || 0;
        guestsLabel.textContent = n ? String(n) : "Add guests";
      }

      // Accordion header values
      const locVal = accLoc?.querySelector("[data-hcn-acc-value]");
      const datVal = accDates?.querySelector("[data-hcn-acc-value]");
      const gueVal = accGuests?.querySelector("[data-hcn-acc-value]");
      if (locVal) locVal.textContent = (locationInput?.value || "").trim() || "Add location";
      if (datVal) datVal.textContent = (datesLabel?.textContent || "Add dates");
      if (gueVal) gueVal.textContent = (guestsLabel?.textContent || "Add guests");
    };

    // ---------- accordion header clicks ----------
    allAcc.forEach((acc) => {
      const head = acc.querySelector("[data-hcn-acc-head]");
      head?.addEventListener("click", () => {
        const key = acc.getAttribute("data-hcn-acc");
        openAccordion(key);
      });
    });

    // ---------- triggers ----------
    trigLoc?.addEventListener("click", (e) => { e.preventDefault(); openSheet("location"); });
    trigDates?.addEventListener("click", (e) => { e.preventDefault(); openSheet("dates"); });
    trigGuests?.addEventListener("click", (e) => { e.preventDefault(); openSheet("guests"); });

    closeBtn?.addEventListener("click", (e) => { e.preventDefault(); closeSheet(); });
    overlay?.addEventListener("click", (e) => {
      if (e.target === overlay) closeSheet();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeSheet();
    });

    // ---------- Location ----------
    locItems.forEach((item) => {
      item.addEventListener("click", () => {
        const val = item.getAttribute("data-hcn-loc-pick") || "";
        if (locationInput) locationInput.value = val;
        if (locSearch) locSearch.value = val;
        setBarLabels();
      });
    });

    locSearch?.addEventListener("input", () => {
      // For now, just write through. (We can wire real suggestions later.)
      if (locationInput) locationInput.value = locSearch.value;
      setBarLabels();
    });

    // ---------- Guests ----------
    const stepper = (wrap) => {
      if (!wrap) return;
      const minus = wrap.querySelector('[data-hcn-step="-"]');
      const plus = wrap.querySelector('[data-hcn-step="+"]');
      const out = wrap.querySelector("[data-hcn-step-out]");
      const key = wrap.getAttribute("data-hcn-guest");

      const get = () => parseInt(out?.textContent || "0", 10) || 0;
      const set = (n) => { if (out) out.textContent = String(Math.max(0, n)); };

      minus?.addEventListener("click", (e) => {
        e.preventDefault();
        set(get() - 1);
        updateGuestsTotal();
      });

      plus?.addEventListener("click", (e) => {
        e.preventDefault();
        set(get() + 1);
        updateGuestsTotal();
      });

      // init from hidden?
      if (key === "adults") {
        // if guests already set and breakdown empty, leave as-is
      }
    };

    const updateGuestsTotal = () => {
      const a = parseInt(gAdults?.querySelector("[data-hcn-step-out]")?.textContent || "0", 10) || 0;
      const c = parseInt(gChildren?.querySelector("[data-hcn-step-out]")?.textContent || "0", 10) || 0;
      const i = parseInt(gInfants?.querySelector("[data-hcn-step-out]")?.textContent || "0", 10) || 0;
      const p = parseInt(gPets?.querySelector("[data-hcn-step-out]")?.textContent || "0", 10) || 0;

      // What counts as "guests" in the main search? Usually adults + children only.
      const total = a + c; 
      if (guestsHidden) guestsHidden.value = total ? String(total) : "";

      // Optional: store breakdown (handy later)
      form.querySelector('[name="adults"]')?.setAttribute("value", String(a));
      form.querySelector('[name="children"]')?.setAttribute("value", String(c));
      form.querySelector('[name="infants"]')?.setAttribute("value", String(i));
      form.querySelector('[name="pets"]')?.setAttribute("value", String(p));

      setBarLabels();
    };

    [gAdults, gChildren, gInfants, gPets].forEach(stepper);

    // ---------- Dates (range calendar) ----------
    let view = new Date();
    view.setDate(1);

    let selIn = (checkinHidden?.value || "");
    let selOut = (checkoutHidden?.value || "");

    const ymd = (d) => {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, "0");
      const da = String(d.getDate()).padStart(2, "0");
      return `${y}-${m}-${da}`;
    };

    const parseYmd = (s) => (s ? new Date(s + "T00:00:00") : null);

    const renderCal = () => {
      if (!calGrid || !calMonth) return;

      const year = view.getFullYear();
      const month = view.getMonth();

      calMonth.textContent = view.toLocaleString(undefined, { month: "long", year: "numeric" });

      const first = new Date(year, month, 1);
      const firstDay = (first.getDay() + 6) % 7; // Monday start
      const daysInMonth = new Date(year, month + 1, 0).getDate();

      calGrid.innerHTML = "";

      const inDate = parseYmd(selIn);
      const outDate = parseYmd(selOut);

      for (let i = 0; i < firstDay; i++) {
        const spacer = document.createElement("div");
        calGrid.appendChild(spacer);
      }

      for (let day = 1; day <= daysInMonth; day++) {
        const d = new Date(year, month, day);
        const s = ymd(d);

        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "hcn-cal-day";
        btn.textContent = String(day);

        if (selIn === s || selOut === s) btn.classList.add("is-selected");
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

    btnPrev?.addEventListener("click", (e) => { e.preventDefault(); view.setMonth(view.getMonth() - 1); renderCal(); });
    btnNext?.addEventListener("click", (e) => { e.preventDefault(); view.setMonth(view.getMonth() + 1); renderCal(); });

    // ---------- Footer buttons ----------
    clearBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      if (locationInput) locationInput.value = "";
      if (locSearch) locSearch.value = "";
      if (checkinHidden) checkinHidden.value = "";
      if (checkoutHidden) checkoutHidden.value = "";
      if (guestsHidden) guestsHidden.value = "";

      // reset guests steppers
      form.querySelectorAll("[data-hcn-step-out]").forEach((el) => (el.textContent = "0"));

      selIn = "";
      selOut = "";
      if (hint) hint.textContent = "Select check-in, then check-out";
      renderCal();
      setBarLabels();
      syncUrlFromForm();
    });

    applyBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      if (checkinHidden) checkinHidden.value = selIn || "";
      if (checkoutHidden) checkoutHidden.value = selOut || "";
      setBarLabels();
      closeSheet();
      syncUrlFromForm();
    });

    // Init
    setBarLabels();
    if (hint) hint.textContent = "Select check-in, then check-out";
    renderCal();
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();