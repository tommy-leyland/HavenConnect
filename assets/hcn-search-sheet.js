(function () {
    const init = () => {
        const form = document.querySelector("[data-hcn-searchbar]");
        if (!form) return;

        const cfg = window.HCN_SEARCH_BAR || {};
        const ajaxMode = !!cfg.ajax;

        // Hidden inputs
        const locationInput  = form.querySelector("[data-hcn-location]");
        const checkinHidden  = form.querySelector("[data-hcn-checkin]");
        const checkoutHidden = form.querySelector("[data-hcn-checkout]");
        const guestsHidden   = form.querySelector("[data-hcn-guests]");
        const petsHidden     = form.querySelector("[data-hcn-pets]");
        const tolVal         = form.querySelector("[data-hcn-tol-val]");
        const flexDur        = form.querySelector("[data-hcn-flex-dur]");
        const flexMonthsVal  = form.querySelector("[data-hcn-flex-months-val]");

        // Bar triggers
        const trigLoc    = form.querySelector('[data-hcn-sheet-open="location"]');
        const trigDates  = form.querySelector('[data-hcn-sheet-open="dates"]');
        const trigGuests = form.querySelector('[data-hcn-sheet-open="guests"]');

        // Bar labels
        const locLabel    = form.querySelector("[data-hcn-location-label]");
        const datesLabel  = form.querySelector("[data-hcn-dates-label]");
        const guestsLabel = form.querySelector("[data-hcn-guests-label]");

        // Sheet
        const overlay  = form.querySelector("[data-hcn-sheet-overlay]");
        const sheet    = form.querySelector("[data-hcn-sheet]");
        const closeBtn = form.querySelector("[data-hcn-sheet-close]");
        const clearBtn = form.querySelector("[data-hcn-sheet-clear]");
        const applyBtn = form.querySelector("[data-hcn-sheet-apply]");
        const bar      = form.querySelector("[data-hcn-bar]");

        // Accordions
        const accLoc    = form.querySelector('[data-hcn-acc="location"]');
        const accDates  = form.querySelector('[data-hcn-acc="dates"]');
        const accGuests = form.querySelector('[data-hcn-acc="guests"]');
        const allAcc    = [accLoc, accDates, accGuests].filter(Boolean);

        // Location
        const locSearch   = form.querySelector("[data-hcn-loc-search]");
        const locListWrap = form.querySelector("[data-hcn-popular-wrap]");

        // Guests
        const gAdults   = form.querySelector('[data-hcn-guest="adults"]');
        const gChildren = form.querySelector('[data-hcn-guest="children"]');
        const gInfants  = form.querySelector('[data-hcn-guest="infants"]');
        const gPets     = form.querySelector('[data-hcn-guest="pets"]');

        // Calendar (dual-month)
        const calGridA  = form.querySelector("[data-hcn-cal-grid-a]");
        const calGridB  = form.querySelector("[data-hcn-cal-grid-b]");
        const calMonthA = form.querySelector("[data-hcn-cal-month-a]");
        const calMonthB = form.querySelector("[data-hcn-cal-month-b]");
        const btnPrev   = form.querySelector("[data-hcn-cal-prev]");
        const btnNext   = form.querySelector("[data-hcn-cal-next]");
        const calHint   = form.querySelector("[data-hcn-cal-hint]");

        // Date tabs / panels
        const dateTabs   = form.querySelectorAll("[data-hcn-date-tab]");
        const datePanels = form.querySelectorAll("[data-hcn-date-panel]");

        // Flexible
        const durPills     = form.querySelectorAll("[data-hcn-dur]");
        const flexMonthsEl = form.querySelector("[data-hcn-flex-months]");
        const tolPills     = form.querySelectorAll("[data-hcn-tol]");

        // State
        let view = new Date(); view.setDate(1);
        let selIn  = checkinHidden?.value  || '';
        let selOut = checkoutHidden?.value || '';

        // ── URL sync
        const emitSearch = () => window.dispatchEvent(new CustomEvent("hcn:search-updated"));
        const syncUrl = () => {
            if (!ajaxMode) return;
            const fd = new FormData(form);
            const p  = new URLSearchParams();
            for (const [k, v] of fd.entries()) { const val = String(v ?? '').trim(); if (val) p.set(k, val); }
            const url = new URL(window.location.href);
            url.search = p.toString();
            window.history.replaceState({}, '', url.toString());
            emitSearch();
        };

        // ── Position sheet below the trigger bar segment
        const positionSheet = (trigEl) => {
            if (!sheet) return;
            // Move sheet to body so absolute positioning works relative to viewport/document
            if (sheet.parentElement !== document.body) document.body.appendChild(sheet);
            const barRect  = bar ? bar.getBoundingClientRect() : {bottom:72};
            const scrollY  = window.scrollY || window.pageYOffset;
            const scrollX  = window.scrollX || window.pageXOffset;
            sheet.style.position = 'absolute';
            sheet.style.top  = (barRect.bottom + scrollY + 8) + 'px';
            // Align with trigger if available, else left of bar
            const trigRect = trigEl ? trigEl.getBoundingClientRect() : barRect;
            const left = Math.max(8, Math.min(trigRect.left + scrollX, document.documentElement.clientWidth - sheet.offsetWidth - 8));
            sheet.style.left = left + 'px';
            sheet.style.right = 'auto';
        };

        // ── Open / close
        const openSheet = (section, trigEl) => {
            if (!overlay) return;
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            openAcc(section);
            requestAnimationFrame(() => positionSheet(trigEl));
        };
        const closeSheet = () => {
            if (!overlay) return;
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
        };
        const openAcc = (key) => {
            allAcc.forEach(el => el.classList.remove('is-open'));
            const t = key === 'location' ? accLoc : key === 'dates' ? accDates : accGuests;
            if (t) t.classList.add('is-open');
        };

        allAcc.forEach(acc => acc.querySelector('[data-hcn-acc-head]')?.addEventListener('click', () => openAcc(acc.getAttribute('data-hcn-acc'))));
        trigLoc?.addEventListener('click',    e => { e.preventDefault(); openSheet('location', trigLoc);    });
        trigDates?.addEventListener('click',  e => { e.preventDefault(); openSheet('dates',    trigDates);  renderCal(); });
        trigGuests?.addEventListener('click', e => { e.preventDefault(); openSheet('guests',   trigGuests); });
        closeBtn?.addEventListener('click',   e => { e.preventDefault(); closeSheet(); });
        overlay?.addEventListener('click',    e => { if (e.target === overlay) closeSheet(); });
        document.addEventListener('keydown',  e => { if (e.key === 'Escape') closeSheet(); });

        // ── Bar labels
        const setBarLabels = () => {
            const capWords = s => s ? s.replace(/[-_]/g,' ').replace(/\b\w/g, c => c.toUpperCase()) : '';
            if (locLabel) {
                const v = (locationInput?.value || '').trim();
                locLabel.textContent = v ? capWords(v) : 'Add location';
            }
            if (datesLabel) {
                const a = (checkinHidden?.value  || '').trim();
                const b = (checkoutHidden?.value || '').trim();
                datesLabel.textContent = (a && b) ? (a + ' \u2192 ' + b) : 'Add dates';
            }
            if (guestsLabel) {
                const n = parseInt(guestsHidden?.value || '0', 10) || 0;
                guestsLabel.textContent = n ? (n + ' guest' + (n !== 1 ? 's' : '')) : 'Add guests';
            }
            // Accordion value summaries (right side of collapsed header)
            const locVal = accLoc?.querySelector('[data-hcn-acc-value]');
            const datVal = accDates?.querySelector('[data-hcn-acc-value]');
            const gueVal = accGuests?.querySelector('[data-hcn-acc-value]');
            if (locVal) { const v = (locationInput?.value || '').trim(); locVal.textContent = v ? capWords(v) : ''; }
            if (datVal) { const a = (checkinHidden?.value || '').trim(), b = (checkoutHidden?.value || '').trim(); datVal.textContent = (a && b) ? (a + ' \u2192 ' + b) : ''; }
            if (gueVal) { const n = parseInt(guestsHidden?.value || '0', 10) || 0; gueVal.textContent = n ? (n + ' guest' + (n !== 1 ? 's' : '')) : ''; }
        };

        // ── Location list with expand
        let locExpanded = false;
        const SHOW_INIT = 3;
        const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

        const renderListItems = (items) => {
            if (!locListWrap) return;
            if (!items || !items.length) { locListWrap.innerHTML = '<div class="hcn-muted">No matches found.</div>'; return; }
            const vis = locExpanded ? items : items.slice(0, SHOW_INIT);
            const pinSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#b69068" stroke-width="2"><path stroke-linecap="round" d="M12 2C8.686 2 6 4.686 6 8c0 5.25 6 13 6 13s6-7.75 6-13c0-3.314-2.686-6-6-6z"/></svg>';
            const chevron = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>';
            locListWrap.innerHTML = vis.map(it =>
                '<div class="hcn-loc-item" data-slug="' + esc(it.slug || it.name) + '" data-name="' + esc(it.name) + '">' + pinSvg + ' ' + esc(it.name) + '</div>'
            ).join('') + (items.length > SHOW_INIT && !locExpanded ? '<button type="button" class="hcn-loc-expand" data-hcn-loc-expand>' + chevron + ' Show more</button>' : '');

            locListWrap.querySelectorAll('[data-slug]').forEach(el => {
                el.addEventListener('click', () => {
                    if (locationInput) locationInput.value = el.getAttribute('data-slug') || '';
                    if (locSearch)     locSearch.value     = el.getAttribute('data-name') || '';
                    setBarLabels();
                });
            });
            locListWrap.querySelector('[data-hcn-loc-expand]')?.addEventListener('click', () => { locExpanded = true; renderPopular(); });
        };

        const renderPopular = () => renderListItems(Array.isArray(cfg.popularLocations) ? cfg.popularLocations : []);

        const fetchLocSuggestions = async q => {
            const url = new URL(cfg.ajaxUrl || '');
            url.searchParams.set('action','hcn_location_suggest');
            url.searchParams.set('tax', cfg.locTax || 'property_loc');
            url.searchParams.set('q', q);
            const res = await fetch(url.toString(), {credentials:'same-origin'});
            const json = await res.json();
            return (json?.success && Array.isArray(json.data?.items)) ? json.data.items : [];
        };

        renderPopular();

        let locTimer = null;
        locSearch?.addEventListener('input', () => {
            const q = (locSearch.value || '').trim();
            if (locationInput) locationInput.value = q;
            locExpanded = false;
            setBarLabels();
            clearTimeout(locTimer);
            locTimer = setTimeout(async () => {
                if (!q) { renderPopular(); return; }
                try { renderListItems(await fetchLocSuggestions(q)); } catch { renderPopular(); }
            }, 180);
        });

        // ── Guest steppers
        const stepper = wrap => {
            if (!wrap) return;
            const minus = wrap.querySelector('[data-hcn-step="-"]');
            const plus  = wrap.querySelector('[data-hcn-step="+"]');
            const out   = wrap.querySelector('[data-hcn-step-out]');
            const get   = () => parseInt(out?.textContent || '0', 10) || 0;
            const set   = n  => { if (out) out.textContent = String(Math.max(0, n)); };
            minus?.addEventListener('click', e => { e.preventDefault(); set(get()-1); updateGuestsTotal(); });
            plus?.addEventListener('click',  e => { e.preventDefault(); set(get()+1); updateGuestsTotal(); });
        };
        const updateGuestsTotal = () => {
            const a = parseInt(gAdults?.querySelector('[data-hcn-step-out]')?.textContent || '0', 10) || 0;
            const c = parseInt(gChildren?.querySelector('[data-hcn-step-out]')?.textContent || '0', 10) || 0;
            const p = parseInt(gPets?.querySelector('[data-hcn-step-out]')?.textContent || '0', 10) || 0;
            if (guestsHidden) guestsHidden.value = (a + c) ? String(a + c) : '';
            if (petsHidden)   petsHidden.value   = p > 0   ? String(p)    : '';
            setBarLabels();
        };
        [gAdults, gChildren, gInfants, gPets].forEach(stepper);

        // ── Dual-month calendar
        const ymd = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
        const parseYmd = s => s ? new Date(s + 'T00:00:00') : null;
        const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

        const renderCalMonth = (grid, monthEl, year, month) => {
            if (!grid || !monthEl) return;
            monthEl.textContent = MONTH_NAMES[month] + ' ' + year;
            const firstDay  = (new Date(year, month, 1).getDay() + 6) % 7;
            const daysTotal = new Date(year, month+1, 0).getDate();
            const inDate    = parseYmd(selIn);
            const outDate   = parseYmd(selOut);
            const today     = new Date(); today.setHours(0,0,0,0);
            grid.innerHTML  = '';
            for (let i=0; i<firstDay; i++) grid.appendChild(document.createElement('div'));
            for (let day=1; day<=daysTotal; day++) {
                const d   = new Date(year, month, day);
                const s   = ymd(d);
                const btn = document.createElement('button');
                btn.type  = 'button';
                btn.className = 'hcn-cal-day';
                btn.textContent = String(day);
                if (s === selIn)  btn.classList.add('is-start');
                if (s === selOut) btn.classList.add('is-end');
                if (inDate && outDate && d > inDate && d < outDate) btn.classList.add('is-inrange');
                if (d < today) { btn.classList.add('is-past'); btn.disabled = true; }
                btn.addEventListener('click', () => {
                    if (!selIn || (selIn && selOut)) { selIn = s; selOut = ''; if (calHint) calHint.textContent = 'Now pick your check-out date'; }
                    else if (s <= selIn) { selIn = s; selOut = ''; if (calHint) calHint.textContent = 'Now pick your check-out date'; }
                    else { selOut = s; if (calHint) calHint.textContent = 'Dates selected \u2713'; }
                    renderCal();
                });
                grid.appendChild(btn);
            }
        };

        const renderCal = () => {
            const y  = view.getFullYear(), m = view.getMonth();
            const ny = m === 11 ? y+1 : y, nm = m === 11 ? 0 : m+1;
            renderCalMonth(calGridA, calMonthA, y, m);
            renderCalMonth(calGridB, calMonthB, ny, nm);
        };

        btnPrev?.addEventListener('click', e => { e.preventDefault(); view.setMonth(view.getMonth()-1); renderCal(); });
        btnNext?.addEventListener('click', e => { e.preventDefault(); view.setMonth(view.getMonth()+1); renderCal(); });

        // ── Date tabs
        dateTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const key = tab.getAttribute('data-hcn-date-tab');
                dateTabs.forEach(t => t.classList.toggle('is-active', t === tab));
                datePanels.forEach(p => { p.style.display = p.getAttribute('data-hcn-date-panel') === key ? '' : 'none'; });
            });
        });

        // ── Tolerance pills
        tolPills.forEach(pill => {
            pill.addEventListener('click', () => {
                tolPills.forEach(p => p.classList.toggle('is-active', p === pill));
                if (tolVal) tolVal.value = pill.getAttribute('data-hcn-tol') || '0';
            });
        });

        // ── Flexible duration pills
        durPills.forEach(pill => {
            pill.addEventListener('click', () => {
                durPills.forEach(p => p.classList.toggle('is-active', p === pill));
                if (flexDur) flexDur.value = pill.getAttribute('data-hcn-dur') || '';
            });
        });

        // ── Flexible month grid (12 months from now)
        const selectedFlexMonths = new Set((flexMonthsVal?.value || '').split(',').filter(Boolean));
        const renderFlexMonths = () => {
            if (!flexMonthsEl) return;
            const now = new Date();
            const months = Array.from({length:12}, (_,i) => new Date(now.getFullYear(), now.getMonth()+i, 1));
            flexMonthsEl.innerHTML = months.map(d => {
                const key = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
                const on  = selectedFlexMonths.has(key);
                return '<div class="hcn-flex-month ' + (on ? 'is-on' : '') + '" data-flex-month="' + key + '">'
                    + '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>'
                    + '<div class="hcn-flex-month__name">' + MONTH_NAMES[d.getMonth()] + '</div>'
                    + '<div class="hcn-flex-month__year">' + d.getFullYear() + '</div>'
                    + '</div>';
            }).join('');
            flexMonthsEl.querySelectorAll('[data-flex-month]').forEach(el => {
                el.addEventListener('click', () => {
                    const k = el.getAttribute('data-flex-month');
                    if (selectedFlexMonths.has(k)) selectedFlexMonths.delete(k); else selectedFlexMonths.add(k);
                    if (flexMonthsVal) flexMonthsVal.value = Array.from(selectedFlexMonths).join(',');
                    renderFlexMonths();
                });
            });
        };
        renderFlexMonths();

        // ── Clear / Apply
        clearBtn?.addEventListener('click', e => {
            e.preventDefault();
            if (locationInput)  locationInput.value  = '';
            if (locSearch)      locSearch.value      = '';
            if (checkinHidden)  checkinHidden.value  = '';
            if (checkoutHidden) checkoutHidden.value = '';
            if (guestsHidden)   guestsHidden.value   = '';
            if (petsHidden)     petsHidden.value     = '';
            if (tolVal)         tolVal.value         = '0';
            if (flexDur)        flexDur.value        = '';
            if (flexMonthsVal)  flexMonthsVal.value  = '';
            form.querySelectorAll('[data-hcn-step-out]').forEach(el => el.textContent = '0');
            tolPills.forEach(p => p.classList.toggle('is-active', p.getAttribute('data-hcn-tol') === '0'));
            durPills.forEach(p => p.classList.remove('is-active'));
            selectedFlexMonths.clear();
            selIn = ''; selOut = '';
            locExpanded = false;
            renderCal(); renderPopular(); renderFlexMonths(); setBarLabels(); syncUrl();
        });

        applyBtn?.addEventListener('click', e => {
            e.preventDefault();
            if (checkinHidden)  checkinHidden.value  = selIn  || '';
            if (checkoutHidden) checkoutHidden.value = selOut || '';
            setBarLabels(); closeSheet(); syncUrl();
        });

        // Init
        setBarLabels();
        if (calHint) calHint.textContent = 'Select check-in, then check-out';
        renderCal();
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})(); 