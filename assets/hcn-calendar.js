(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.hcn-cal-wrap').forEach(initCalendar);
  });

  function initCalendar(wrap) {
    const postId      = wrap.dataset.postId;
    const ajaxUrl     = wrap.dataset.ajax;
    const nonce       = wrap.dataset.nonce;
    const months      = parseInt(wrap.dataset.months, 10) || 3;
    const propertyUid = wrap.dataset.propertyUid || '';
    const maxGuests   = parseInt(wrap.dataset.maxGuests, 10) || 20;
    const baseGuests  = parseInt(wrap.dataset.baseGuests, 10) || 1;

    const inputEl  = wrap.querySelector('.hcn-cal-input');
    const loading  = wrap.querySelector('.hcn-cal-loading');
    const panel    = wrap.querySelector('.hcn-cal-panel');
    const noticeEl = wrap.querySelector('.hcn-cal-notice');
    const guestEl  = wrap.querySelector('.hcn-cal-guests');

    if (!inputEl) return;

    // Guest count state (hoisted so quote callback can use it)
    let guestCount  = baseGuests;
    let checkinStr  = null;
    let checkoutStr = null;
    let fp          = null;

    if (guestEl) {
      guestEl.value = String(guestCount);
      guestEl.max   = String(maxGuests);
    }

    // ------------------------------------------------------------------ //
    // Fetch availability data
    // ------------------------------------------------------------------ //
    const fd = new FormData();
    fd.append('action', 'hcn_calendar_data');
    fd.append('nonce',   nonce);
    fd.append('post_id', postId);
    fd.append('months',  String(months * 2));

    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(r => r.json())
      .then(json => {
        if (!json.success) {
          showNotice(json.data?.message || 'Could not load availability.');
          if (loading) loading.remove();
          return;
        }
        if (loading) loading.remove();
        boot(json.data);
      })
      .catch(() => {
        showNotice('Could not load availability. Please refresh the page.');
        if (loading) loading.remove();
      });

    // ------------------------------------------------------------------ //
    // Boot Flatpickr
    // ------------------------------------------------------------------ //
    function boot(data) {
      const days           = data.days || {};
      const currency       = data.currency || '£';
      const defaultMinStay = data.default_min_stay || 1;

      const dayData = (s) => days[s] || null;

      const toStr = (d) => {
        const y  = d.getFullYear();
        const m  = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dd}`;
      };

      const addDays = (s, n) => {
        const d = new Date(s + 'T00:00:00');
        d.setDate(d.getDate() + n);
        return toStr(d);
      };

      const diffDays = (a, b) => {
        const da = new Date(a + 'T00:00:00');
        const db = new Date(b + 'T00:00:00');
        return Math.round((db - da) / 86400000);
      };

      const fmtPrice = (p) => p != null ? `${currency}${Math.round(p)}` : null;
      const getMinStay = (s) => { const d = dayData(s); return (d && d.mn) ? d.mn : defaultMinStay; };

      // FIX 2: A "hard block" means the date is unavailable AND NOT a valid checkout.
      // A date with u=1 AND co=1 is a "checkout wall" — guests can end their stay here
      // but cannot stay through it or check in.
      const isHardBlocked = (s) => {
        const d = dayData(s);
        if (!d) return true;
        return d.u === 1 && d.co !== 1;
      };

      const isCheckinBlocked = (s) => {
        const d = dayData(s);
        if (!d) return true;
        if (d.u === 1) return true;
        if (d.ci === 0) return true;
        return false;
      };

      const isCheckoutAllowed = (s) => {
        const d = dayData(s);
        if (!d) return false;
        if (d.u === 1 && d.co === 1) return true;  // checkout wall — allowed as endpoint
        if (d.u === 1) return false;
        if (d.co === 0) return false;
        return true;
      };

      // Find the first date after checkin that is hard-blocked (the "wall").
      // The guest can check out ON this date but not beyond it.
      const firstBlockAfter = (cinStr) => {
        for (let i = 1; i <= 365; i++) {
          const s = addDays(cinStr, i);
          const d = dayData(s);
          if (!d || isHardBlocked(s)) return s;
        }
        return null;
      };

      // Disable function — evaluated per-cell on every redraw.
      // Phase A (no checkin): block unavailable / no-checkin / no-data dates.
      // Phase B (checkin selected): block dates that can't be a checkout, and everything past the wall.
      const disableFn = (dateObj) => {
        const s     = toStr(dateObj);
        const today = toStr(new Date());
        if (s < today) return true;

        if (checkinStr && !checkoutStr) {
          // Phase B
          if (s <= checkinStr) return true;
          const wall = firstBlockAfter(checkinStr);
          if (wall && s > wall) return true;
          if (wall && s === wall) return !isCheckoutAllowed(s);
          return !isCheckoutAllowed(s);
        }

        // Phase A
        const row = dayData(s);
        if (!row) return true;
        if (row.u === 1) return true;
        if (row.ci === 0) return true;
        return false;
      };

      fp = flatpickr(inputEl, {
        mode:       'range',
        inline:     true,
        showMonths: 1,          // FIX 1: single month
        minDate:    'today',
        dateFormat: 'Y-m-d',
        disable:    [disableFn],

        onDayCreate(dObj, dStr, fp, dayElem) {
          const s   = toStr(dayElem.dateObj);
          const row = dayData(s);
          if (!row) return;

          if (row.p != null) {
            const tag = document.createElement('span');
            tag.className = 'hcn-cal-price';
            tag.textContent = fmtPrice(row.p);
            dayElem.appendChild(tag);
          }

          // FIX 2: checkout-wall styling (u=1, co=1) — available as checkout endpoint only
          if (row.u === 1 && row.co === 1) {
            dayElem.classList.add('hcn-cal-checkout-wall');
            dayElem.title = 'Check-out only';
          } else if (row.ci === 0 && row.u !== 1) {
            dayElem.classList.add('hcn-cal-no-checkin');
            dayElem.title = 'Check-out only';
          }
        },

        onChange(selectedDates) {
          hideNotice();

          if (selectedDates.length === 0) {
            checkinStr = checkoutStr = null;
            fp.redraw();
            renderPanel(null, null);
            return;
          }

          if (selectedDates.length === 1) {
            // Always reset both first — this handles re-selection after a full range
            checkoutStr = null;
            checkinStr  = toStr(selectedDates[0]);

            if (isCheckinBlocked(checkinStr)) {
              showNotice('That date is not available for check-in. Please choose another.');
              fp.clear();
              checkinStr = null;
              fp.redraw();
              return;
            }

            fp.redraw(); // re-evaluate disable in Phase B (checkout picking mode)
            const minStay = getMinStay(checkinStr);
            if (minStay > 1) showNotice(`Minimum stay from this date is ${minStay} nights.`);
            renderPanel(checkinStr, null);
            return;
          }

          checkinStr  = toStr(selectedDates[0]);
          checkoutStr = toStr(selectedDates[1]);
          const nights = diffDays(checkinStr, checkoutStr);

          const minStay = getMinStay(checkinStr);
          if (nights < minStay) {
            showNotice(`Minimum stay is ${minStay} nights from this check-in. Please select a later check-out.`);
            fp.setDate([checkinStr], true);
            checkoutStr = null;
            fp.redraw();
            return;
          }

          const row = dayData(checkinStr);
          if (row && row.mx && nights > row.mx) {
            showNotice(`Maximum stay from this date is ${row.mx} nights.`);
            fp.setDate([checkinStr], true);
            checkoutStr = null;
            fp.redraw();
            return;
          }

          // No hard-blocked night inside the stay (checkout date itself is fine)
          for (let i = 1; i < nights; i++) {
            if (isHardBlocked(addDays(checkinStr, i))) {
              showNotice('Your selected dates include an unavailable night. Please adjust your check-out.');
              fp.setDate([checkinStr], true);
              checkoutStr = null;
              fp.redraw();
              return;
            }
          }

          hideNotice();
          fp.redraw();
          renderPanel(checkinStr, checkoutStr);
        },
      });

      // ---------------------------------------------------------------- //
      // Panel
      // ---------------------------------------------------------------- //
      function renderPanel(cin, cout) {
        if (!cin) {
          panel.innerHTML = `<div class="hcn-cal-panel__inner hcn-cal-panel--empty"><p class="hcn-cal-panel__hint">Select your check-in date to get started.</p></div>`;
          return;
        }

        if (!cout) {
          const minStay = getMinStay(cin);
          panel.innerHTML = `
            <div class="hcn-cal-panel__inner hcn-cal-panel--checkin">
              <div class="hcn-cal-panel__row"><span class="hcn-cal-panel__label">Check-in</span><span class="hcn-cal-panel__value">${formatDate(cin)}</span></div>
              <p class="hcn-cal-panel__hint">Now select your check-out date.${minStay > 1 ? ` Min stay: <strong>${minStay} nights</strong>.` : ''}</p>
            </div>`;
          return;
        }

        const nights = diffDays(cin, cout);
        panel.innerHTML = `
          <div class="hcn-cal-panel__inner hcn-cal-panel--ready">
            <div class="hcn-cal-panel__dates">
              <div class="hcn-cal-panel__row"><span class="hcn-cal-panel__label">Check-in</span><span class="hcn-cal-panel__value">${formatDate(cin)}</span></div>
              <div class="hcn-cal-panel__row"><span class="hcn-cal-panel__label">Check-out</span><span class="hcn-cal-panel__value">${formatDate(cout)}</span></div>
              <div class="hcn-cal-panel__row"><span class="hcn-cal-panel__label">Nights</span><span class="hcn-cal-panel__value">${nights}</span></div>
              <div class="hcn-cal-panel__row hcn-cal-panel__row--total">
                <span class="hcn-cal-panel__label">Total</span>
                <span class="hcn-cal-panel__value hcn-cal-panel__total" id="hcn-quote-total"><span class="hcn-cal-panel__calculating">Calculating…</span></span>
              </div>
            </div>
            <button type="button" class="hcn-cal-book-btn" data-checkin="${cin}" data-checkout="${cout}" data-nights="${nights}" disabled>Book Now</button>
            <button type="button" class="hcn-cal-clear-btn">Clear dates</button>
          </div>`;

        panel.querySelector('.hcn-cal-book-btn')?.addEventListener('click', onBook);
        panel.querySelector('.hcn-cal-clear-btn')?.addEventListener('click', () => {
          fp.clear();
          checkinStr = checkoutStr = null;
          fp.redraw();
          renderPanel(null, null);
          hideNotice();
        });

        // FIX 3: get real quote from Hostfully
        runQuote(cin, cout, guestCount);
      }

      // ---------------------------------------------------------------- //
      // FIX 3: Live Hostfully quote
      // ---------------------------------------------------------------- //
      function runQuote(cin, cout, guests) {
        console.log('[HCN] runQuote called:', { cin, cout, guests, propertyUid, ajaxUrl });

        const totalEl = document.getElementById('hcn-quote-total');
        const bookBtn = panel.querySelector('.hcn-cal-book-btn');

        if (!totalEl) {
          console.warn('[HCN] runQuote: hcn-quote-total element not found in DOM');
          return;
        }

        // No Hostfully UID — fall back to summing DB nightly rates
        if (!propertyUid) {
          console.warn('[HCN] runQuote: propertyUid is empty — falling back to DB total. Check _havenconnect_uid post meta exists.');
          const dbTotal = calcDbTotal(cin, cout);
          if (dbTotal != null) {
            totalEl.textContent = `${currency}${Math.round(dbTotal).toLocaleString()}`;
            if (bookBtn) { bookBtn.removeAttribute('disabled'); bookBtn.dataset.total = String(Math.round(dbTotal)); }
          } else {
            totalEl.textContent = 'Price on request';
            if (bookBtn) bookBtn.removeAttribute('disabled');
          }
          return;
        }

        console.log('[HCN] runQuote: firing AJAX to', ajaxUrl, 'uid=', propertyUid, 'guests=', guests);
        if (totalEl) totalEl.innerHTML = '<span class="hcn-cal-panel__calculating">Calculating…</span>';
        if (bookBtn) bookBtn.setAttribute('disabled', '');

        const qfd = new FormData();
        qfd.append('action',   'hcn_calendar_quote');
        qfd.append('nonce',    nonce);
        qfd.append('uid',      propertyUid);
        qfd.append('checkin',  cin);
        qfd.append('checkout', cout);
        qfd.append('guests',   String(guests));

        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: qfd })
          .then(r => r.json())
          .then(json => {
            console.log('[HCN] runQuote: AJAX response:', json);
            if (!json.success || !json.data?.total) {
              console.warn('[HCN] runQuote: quote failed or missing total — falling back to DB total. Response:', json);
              const dbTotal = calcDbTotal(cin, cout);
              if (dbTotal != null) {
                totalEl.innerHTML = `${currency}${Math.round(dbTotal).toLocaleString()} <small>(est.)</small>`;
                if (bookBtn) { bookBtn.removeAttribute('disabled'); bookBtn.dataset.total = String(Math.round(dbTotal)); }
              } else {
                totalEl.textContent = 'Price on request';
                if (bookBtn) bookBtn.removeAttribute('disabled');
              }
              return;
            }
            console.log('[HCN] runQuote: success, total =', json.data.total, 'guests =', json.data.guests);
            const total = json.data.total;
            totalEl.textContent = `${currency}${Math.round(total).toLocaleString()}`;
            if (bookBtn) { bookBtn.removeAttribute('disabled'); bookBtn.dataset.total = String(Math.round(total)); }
          })
          .catch((err) => {
            console.error('[HCN] runQuote: fetch error:', err);
            totalEl.textContent = 'Price on request';
            if (bookBtn) bookBtn.removeAttribute('disabled');
          });
      }


      // ---------------------------------------------------------------- //
      // Wire guest stepper — must be inside boot() so runQuote is in scope
      // ---------------------------------------------------------------- //
      if (guestEl) {
        const updateGuests = (n) => {
          guestCount    = Math.max(1, Math.min(maxGuests, n));
          guestEl.value = String(guestCount);
          const minusBtn = wrap.querySelector('.hcn-cal-guest-btn[data-dir="-"]');
          const plusBtn  = wrap.querySelector('.hcn-cal-guest-btn[data-dir="+"]');
          if (minusBtn) minusBtn.disabled = guestCount <= 1;
          if (plusBtn)  plusBtn.disabled  = guestCount >= maxGuests;
          // Re-run quote immediately if dates are already selected
          if (checkinStr && checkoutStr) runQuote(checkinStr, checkoutStr, guestCount);
        };

        guestEl.addEventListener('change', () => updateGuests(parseInt(guestEl.value, 10) || 1));
        wrap.querySelectorAll('.hcn-cal-guest-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            updateGuests(guestCount + (btn.dataset.dir === '+' ? 1 : -1));
          });
        });
        updateGuests(guestCount); // set initial button disabled states
      }

      function calcDbTotal(cin, cout) {
        const nights = diffDays(cin, cout);
        let total = 0;
        for (let i = 0; i < nights; i++) {
          const row = dayData(addDays(cin, i));
          if (!row || row.p == null) return null;
          total += row.p;
        }
        return total;
      }

      // ---------------------------------------------------------------- //
      // Book
      // ---------------------------------------------------------------- //
      function onBook(e) {
        const btn   = e.currentTarget;
        const total = parseFloat(btn.dataset.total || '0') || null;
        const detail = {
          postId:      postId,
          propertyUid: propertyUid,
          checkin:     btn.dataset.checkin,
          checkout:    btn.dataset.checkout,
          nights:      parseInt(btn.dataset.nights, 10),
          guests:      guestCount,
          total:       total,
        };
        window.dispatchEvent(new CustomEvent('hcn:book', { detail }));
        const checkoutPage = wrap.dataset.checkoutUrl || window.hcnCheckoutUrl || '/checkout/';
        const params = new URLSearchParams({ post_id: detail.postId, checkin: detail.checkin, checkout: detail.checkout, guests: detail.guests });
        if (detail.total) params.set('total', String(detail.total));
        window.location.href = `${checkoutPage}?${params.toString()}`;
      }

      function formatDate(str) {
        return new Date(str + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
      }

      function showNotice(msg) { if (noticeEl) { noticeEl.textContent = msg; noticeEl.removeAttribute('hidden'); } }
      function hideNotice()    { if (noticeEl) { noticeEl.setAttribute('hidden', ''); noticeEl.textContent = ''; } }
    }
  }

})();