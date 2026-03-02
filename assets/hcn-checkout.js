(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const wrap = document.getElementById('hcn-checkout');
    if (!wrap) return;

    const ajaxUrl   = wrap.dataset.ajax;
    const nonce     = wrap.dataset.nonce;
    const stripeKey = wrap.dataset.stripeKey;

    // Parse URL params
    const params    = new URLSearchParams(window.location.search);
    const postId    = params.get('post_id')  || '';
    const checkin   = params.get('checkin')  || '';
    const checkout  = params.get('checkout') || '';
    const guests    = params.get('guests')   || '1';
    const totalHint = parseFloat(params.get('total') || '0');

    // Show confirmation screen immediately if redirected back after booking
    if (params.get('booking_confirmed') === '1') {
      wrap.innerHTML = `
<div class="hcn-co-confirmed">
  <div class="hcn-co-confirmed__icon">✓</div>
  <h2>Booking Confirmed!</h2>
  <p>Thank you — you'll receive a confirmation email shortly.</p>
  <p class="hcn-co-confirmed__ref">Reference: ${params.get('lead_uid') || ''}</p>
</div>`;
      return;
    }

    if (!postId || !checkin || !checkout) {
      wrap.innerHTML = `<div class="hcn-co-error"><p>Missing booking details. Please <a href="javascript:history.back()">go back</a> and select your dates.</p></div>`;
      return;
    }

    // ------------------------------------------------------------------ //
    // State
    // ------------------------------------------------------------------ //
    let state = {
      property: {},
      booking:  {},
      quote:    { total: totalHint, currency: 'GBP', line_items: [] },
      optional_fees: [],
      selected_fees: [],
      promo_code: '',
      rental_agreement_url: '',
      agency_uid: '',
      stripe: null,
      elements: null,
      payment_element: null,
      loading: false,
    };

    // ------------------------------------------------------------------ //
    // 1. Load booking data
    // ------------------------------------------------------------------ //
    const fd = new FormData();
    fd.append('action',   'hcn_checkout_data');
    fd.append('nonce',    nonce);
    fd.append('post_id',  postId);
    fd.append('checkin',  checkin);
    fd.append('checkout', checkout);
    fd.append('guests',   guests);

    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(r => r.json())
      .then(json => {
        if (!json.success) throw new Error(json.data?.message || 'Failed to load booking.');
        const d = json.data;
        state.property             = d.property;
        state.booking              = d.booking;
        state.quote                = d.quote;
        state.optional_fees        = d.optional_fees || [];
        state.rental_agreement_url = d.rental_agreement_url || '';
        state.agency_uid           = d.agency_uid || '';
        render();
      })
      .catch(err => {
        wrap.innerHTML = `<div class="hcn-co-error"><p>${err.message}</p></div>`;
      });

    // ------------------------------------------------------------------ //
    // 2. Render
    // ------------------------------------------------------------------ //
    function fmt(amount, currency) {
      return new Intl.NumberFormat('en-GB', { style: 'currency', currency: currency || 'GBP', minimumFractionDigits: 0 }).format(amount);
    }

    function formatDate(str) {
      return new Date(str + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function totalWithFees() {
      let t = state.quote.total;
      state.selected_fees.forEach(uid => {
        const f = state.optional_fees.find(f => f.uid === uid);
        if (f) t += f.amount;
      });
      return t;
    }

    function render() {
      const totalGuests = parseInt(guests, 10) || 1;
      const cur = state.quote.currency || 'GBP';
      const total = totalWithFees();
      const totalPence = Math.round(total * 100);

      wrap.innerHTML = `
<div class="hcn-co-wrap">

  <!-- LEFT COLUMN -->
  <div class="hcn-co-left">

    <!-- 1. Guest details -->
    <section class="hcn-co-section" id="hcn-section-guest">
      <h2 class="hcn-co-section__title">
        <span class="hcn-co-section__num">1</span>
        Your Details
      </h2>
      <div class="hcn-co-fields">
        <div class="hcn-co-field-row">
          <div class="hcn-co-field">
            <label for="hcn-first-name">First name <span class="req">*</span></label>
            <input type="text" id="hcn-first-name" autocomplete="given-name" placeholder="Jane">
          </div>
          <div class="hcn-co-field">
            <label for="hcn-last-name">Last name <span class="req">*</span></label>
            <input type="text" id="hcn-last-name" autocomplete="family-name" placeholder="Smith">
          </div>
        </div>
        <div class="hcn-co-field">
          <label for="hcn-email">Email address <span class="req">*</span></label>
          <input type="email" id="hcn-email" autocomplete="email" placeholder="jane@example.com">
        </div>
        <div class="hcn-co-field">
          <label for="hcn-phone">Phone number</label>
          <input type="tel" id="hcn-phone" autocomplete="tel" placeholder="+44 7700 000000">
        </div>
      </div>
    </section>

    ${state.optional_fees.length ? `
    <!-- 2. Add-ons -->
    <section class="hcn-co-section" id="hcn-section-addons">
      <h2 class="hcn-co-section__title">
        <span class="hcn-co-section__num">2</span>
        Optional Add-ons
      </h2>
      <div class="hcn-co-addons">
        ${state.optional_fees.map(f => `
        <label class="hcn-co-addon ${state.selected_fees.includes(f.uid) ? 'is-selected' : ''}">
          <input type="checkbox" class="hcn-co-addon__check" value="${f.uid}" ${state.selected_fees.includes(f.uid) ? 'checked' : ''}>
          <span class="hcn-co-addon__info">
            <span class="hcn-co-addon__name">${f.name}</span>
            ${f.type ? `<span class="hcn-co-addon__type">${f.type}</span>` : ''}
          </span>
          <span class="hcn-co-addon__price">${fmt(f.amount, cur)}</span>
        </label>`).join('')}
      </div>
    </section>
    ` : ''}

    <!-- 3. Payment -->
    <section class="hcn-co-section" id="hcn-section-payment">
      <h2 class="hcn-co-section__title">
        <span class="hcn-co-section__num">${state.optional_fees.length ? '3' : '2'}</span>
        Payment Details
      </h2>
      <div id="hcn-payment-element" class="hcn-co-payment-element">
        <div class="hcn-co-spinner"></div>
      </div>
      <div id="hcn-payment-message" class="hcn-co-payment-msg" hidden></div>
    </section>

    <!-- 4. Rental agreement -->
    <section class="hcn-co-section" id="hcn-section-agreement">
      <h2 class="hcn-co-section__title">
        <span class="hcn-co-section__num">${state.optional_fees.length ? '4' : '3'}</span>
        Rental Agreement
      </h2>
      ${state.rental_agreement_url ? `
      <a href="${state.rental_agreement_url}" target="_blank" rel="noopener" class="hcn-co-agreement-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
        Read the rental agreement
      </a>` : ''}
      <label class="hcn-co-agree">
        <input type="checkbox" id="hcn-agree">
        <span>I have read and agree to the <strong>rental agreement</strong> and <strong>booking terms</strong></span>
      </label>
    </section>

    <!-- Book button -->
    <button type="button" id="hcn-book-btn" class="hcn-co-book-btn" disabled>
      <span class="hcn-co-book-btn__label">Complete Booking — ${fmt(total, cur)}</span>
      <span class="hcn-co-book-btn__spinner" hidden></span>
    </button>
    <div id="hcn-book-error" class="hcn-co-book-error" hidden></div>

  </div><!-- /.hcn-co-left -->

  <!-- RIGHT COLUMN -->
  <div class="hcn-co-right">
    <div class="hcn-co-summary">

      <!-- Property card -->
      <div class="hcn-co-property">
        ${state.property.thumb ? `<img src="${state.property.thumb}" alt="${state.property.title}" class="hcn-co-property__img">` : ''}
        <div class="hcn-co-property__info">
          <div class="hcn-co-property__name">${state.property.title || ''}</div>
          ${state.property.city ? `<div class="hcn-co-property__city">${state.property.city}</div>` : ''}
        </div>
      </div>

      <!-- Dates & guests -->
      <div class="hcn-co-dates">
        <div class="hcn-co-dates__row">
          <span class="hcn-co-dates__label">Check-in</span>
          <span class="hcn-co-dates__val">${formatDate(state.booking.checkin)}</span>
        </div>
        <div class="hcn-co-dates__row">
          <span class="hcn-co-dates__label">Check-out</span>
          <span class="hcn-co-dates__val">${formatDate(state.booking.checkout)}</span>
        </div>
        <div class="hcn-co-dates__row">
          <span class="hcn-co-dates__label">Nights</span>
          <span class="hcn-co-dates__val">${state.booking.nights}</span>
        </div>
        <div class="hcn-co-dates__row">
          <span class="hcn-co-dates__label">Guests</span>
          <span class="hcn-co-dates__val">${state.booking.guests}</span>
        </div>
      </div>
	  
		<!-- Guest breakdown -->
		<div class="hcn-co-guest-breakdown">
		  <div class="hcn-co-guest-breakdown__title">Guest breakdown</div>

		  <div class="hcn-co-field-row">
			<div class="hcn-co-field">
			  <label for="hcn-adults">Adults <span class="req">*</span></label>
			  <input type="number" id="hcn-adults" min="1" step="1" value="${totalGuests}">
			</div>
			<div class="hcn-co-field">
			  <label for="hcn-children">Children</label>
			  <input type="number" id="hcn-children" min="0" step="1" value="0">
			</div>
		  </div>

		  <div class="hcn-co-field-row">
			<div class="hcn-co-field">
			  <label for="hcn-infants">Infants</label>
			  <input type="number" id="hcn-infants" min="0" step="1" value="0">
			</div>
			<div class="hcn-co-field">
			  <label for="hcn-pets">Pets</label>
			  <input type="number" id="hcn-pets" min="0" step="1" value="0">
			</div>
		  </div>

		  <div class="hcn-co-guest-breakdown__meta">
			Total: <span id="hcn-guest-total">${totalGuests}</span> / ${totalGuests}
		  </div>
		</div>

		      <!-- Price breakdown -->
      <div class="hcn-co-breakdown">
        <div class="hcn-co-breakdown__title">Price breakdown</div>
        ${state.quote.line_items.map(li => `
        <div class="hcn-co-breakdown__row">
          <span>${li.label}</span>
          <span>${fmt(li.amount, cur)}</span>
        </div>`).join('')}
        ${state.selected_fees.map(uid => {
          const f = state.optional_fees.find(f => f.uid === uid);
          return f ? `<div class="hcn-co-breakdown__row hcn-co-breakdown__row--addon">
            <span>${f.name}</span><span>${fmt(f.amount, cur)}</span></div>` : '';
        }).join('')}
        ${state.promo_code ? `<div class="hcn-co-breakdown__row hcn-co-breakdown__row--promo"><span>Promo: ${state.promo_code}</span><span>Applied ✓</span></div>` : ''}
        <div class="hcn-co-breakdown__total">
          <span>Total</span>
          <span id="hcn-summary-total">${fmt(total, cur)}</span>
        </div>
      </div>

      <!-- Promo code -->
      <div class="hcn-co-promo">
        <div class="hcn-co-promo__label">Have a promo code?</div>
        <div class="hcn-co-promo__row">
          <input type="text" id="hcn-promo-input" placeholder="SUMMERSALE" value="${state.promo_code}">
          <button type="button" id="hcn-promo-btn">Apply</button>
        </div>
        <div id="hcn-promo-msg" class="hcn-co-promo__msg" hidden></div>
      </div>

    </div><!-- /.hcn-co-summary -->
  </div><!-- /.hcn-co-right -->

</div><!-- /.hcn-co-wrap -->
`;

      bindEvents(totalPence, cur);
      initStripe(totalPence, cur);
    }

    // ------------------------------------------------------------------ //
    // 3. Bind events
    // ------------------------------------------------------------------ //
    function bindEvents(totalPence, cur) {
      // Add-on checkboxes
      wrap.querySelectorAll('.hcn-co-addon__check').forEach(chk => {
        chk.addEventListener('change', () => {
          const uid = chk.value;
          if (chk.checked) {
            if (!state.selected_fees.includes(uid)) state.selected_fees.push(uid);
            chk.closest('.hcn-co-addon').classList.add('is-selected');
          } else {
            state.selected_fees = state.selected_fees.filter(f => f !== uid);
            chk.closest('.hcn-co-addon').classList.remove('is-selected');
          }
          updateTotals();
        });
      });
	  
		const totalGuests = parseInt(guests, 10) || 1;

		const elAdults   = wrap.querySelector('#hcn-adults');
		const elChildren = wrap.querySelector('#hcn-children');
		const elInfants  = wrap.querySelector('#hcn-infants');
		const elGuestTot = wrap.querySelector('#hcn-guest-total');

		function clampInt(v, min, max) {
		  v = parseInt(v || '0', 10);
		  if (isNaN(v)) v = 0;
		  return Math.max(min, Math.min(max, v));
		}

		function enforceGuestCap(changed) {
		  let a = clampInt(elAdults?.value, 1, totalGuests);
		  let c = clampInt(elChildren?.value, 0, totalGuests);
		  let i = clampInt(elInfants?.value, 0, totalGuests);

		  // pets are NOT counted towards guest total (recommended)
		  // If you want pets to count, include p in the sum.
		  const maxNonAdult = totalGuests - 1; // because adults must be >= 1
		  c = Math.min(c, maxNonAdult);
		  i = Math.min(i, totalGuests); // independent cap

		  let sum = a + c; // infants do NOT count towards guest total

		  if (sum > totalGuests) {
			// Reduce adults first to keep UX smooth
			a = Math.max(1, totalGuests - c);
			sum = a + c;
		  }

		  if (elAdults) elAdults.value = String(a);
		  if (elChildren) elChildren.value = String(c);
		  if (elInfants) elInfants.value = String(i);
		  if (elGuestTot) elGuestTot.textContent = String(sum);
		}

		[elAdults, elChildren, elInfants].forEach(el => {
		  el?.addEventListener('input', () => enforceGuestCap(el?.id));
		});

		enforceGuestCap();

      // Agreement checkbox
      const agreeChk = wrap.querySelector('#hcn-agree');
      const bookBtn  = wrap.querySelector('#hcn-book-btn');
      agreeChk?.addEventListener('change', () => {
        bookBtn.disabled = !agreeChk.checked;
      });

      // Promo
      wrap.querySelector('#hcn-promo-btn')?.addEventListener('click', applyPromo);
      wrap.querySelector('#hcn-promo-input')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') applyPromo();
      });

      // Book
      bookBtn?.addEventListener('click', handleBook);
    }

    function updateTotals() {
      const cur   = state.quote.currency || 'GBP';
      const total = totalWithFees();
      const totalPence = Math.round(total * 100);

      // Update summary total
      const summaryEl = wrap.querySelector('#hcn-summary-total');
      if (summaryEl) summaryEl.textContent = fmt(total, cur);

      // Update book button label
      const btnLabel = wrap.querySelector('.hcn-co-book-btn__label');
      if (btnLabel) btnLabel.textContent = `Complete Booking — ${fmt(total, cur)}`;

      // Rebuild breakdown rows for add-ons
      const bdown = wrap.querySelector('.hcn-co-breakdown');
      if (bdown) {
        // Remove old addon rows then re-add
        bdown.querySelectorAll('.hcn-co-breakdown__row--addon').forEach(r => r.remove());
        const totalRow = bdown.querySelector('.hcn-co-breakdown__total');
        state.selected_fees.forEach(uid => {
          const f = state.optional_fees.find(f => f.uid === uid);
          if (!f) return;
          const row = document.createElement('div');
          row.className = 'hcn-co-breakdown__row hcn-co-breakdown__row--addon';
          row.innerHTML = `<span>${f.name}</span><span>${fmt(f.amount, cur)}</span>`;
          bdown.insertBefore(row, totalRow);
        });
      }

      // Re-init Stripe with new amount
      initStripe(totalPence, cur);
    }

    // ------------------------------------------------------------------ //
    // 4. Stripe
    // ------------------------------------------------------------------ //
    function initStripe(amountPence, cur) {
      if (!stripeKey || amountPence < 100) return;

      // Create PaymentIntent
      const fd = new FormData();
      fd.append('action',        'hcn_checkout_intent');
      fd.append('nonce',         nonce);
      fd.append('amount_pence',  String(amountPence));
      fd.append('currency',      cur.toLowerCase());

      fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(r => r.json())
        .then(json => {
          if (!json.success) throw new Error(json.data?.message || 'Payment init failed.');
          mountStripe(json.data.client_secret);
        })
        .catch(err => {
          const el = wrap.querySelector('#hcn-payment-element');
          if (el) el.innerHTML = `<p class="hcn-co-pay-err">Payment could not be loaded. ${err.message}</p>`;
        });
    }

    function mountStripe(clientSecret) {
      if (!window.Stripe) return;

      state.stripe   = window.Stripe(stripeKey);
      state.elements = state.stripe.elements({ clientSecret, appearance: {
        theme: 'stripe',
        variables: {
          colorPrimary:       '#1a1a1a',
          colorBackground:    '#ffffff',
          colorText:          '#1a1a1a',
          fontFamily:         'inherit',
          borderRadius:       '8px',
          spacingUnit:        '4px',
        }
      }});

      state.payment_element = state.elements.create('payment');
      const mountEl = wrap.querySelector('#hcn-payment-element');
      if (mountEl) {
        mountEl.innerHTML = '';
        state.payment_element.mount(mountEl);
      }
    }

    // ------------------------------------------------------------------ //
    // 5. Promo
    // ------------------------------------------------------------------ //
    function applyPromo() {
      const input  = wrap.querySelector('#hcn-promo-input');
      const msgEl  = wrap.querySelector('#hcn-promo-msg');
      const code   = (input?.value || '').trim().toUpperCase();
      if (!code) return;

      msgEl.hidden    = false;
      msgEl.className = 'hcn-co-promo__msg hcn-co-promo__msg--loading';
      msgEl.textContent = 'Checking…';

      const fd = new FormData();
      fd.append('action',   'hcn_checkout_apply_promo');
      fd.append('nonce',    nonce);
      fd.append('code',     code);
      fd.append('post_id',  postId);
      fd.append('checkin',  checkin);
      fd.append('checkout', checkout);
      fd.append('guests',   guests);

      fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(r => r.json())
        .then(json => {
          if (!json.success) {
            msgEl.className   = 'hcn-co-promo__msg hcn-co-promo__msg--error';
            msgEl.textContent = json.data?.message || 'Invalid code.';
            return;
          }
          state.quote.total      = json.data.total;
          state.quote.line_items = json.data.line_items;
          state.promo_code       = json.data.code;
          msgEl.className        = 'hcn-co-promo__msg hcn-co-promo__msg--ok';
          msgEl.textContent      = json.data.message;
          updateTotals();
          // Add promo row to breakdown
          const bdown = wrap.querySelector('.hcn-co-breakdown');
          if (bdown && !bdown.querySelector('.hcn-co-breakdown__row--promo')) {
            const totalRow = bdown.querySelector('.hcn-co-breakdown__total');
            const row = document.createElement('div');
            row.className = 'hcn-co-breakdown__row hcn-co-breakdown__row--promo';
            row.innerHTML = `<span>Promo: ${code}</span><span>Applied ✓</span>`;
            bdown.insertBefore(row, totalRow);
          }
        })
        .catch(() => {
          msgEl.className   = 'hcn-co-promo__msg hcn-co-promo__msg--error';
          msgEl.textContent = 'Could not apply code. Try again.';
        });
    }

    // ------------------------------------------------------------------ //
    // 6. Book
    // ------------------------------------------------------------------ //
    async function handleBook() {
      const bookBtn  = wrap.querySelector('#hcn-book-btn');
      const errEl    = wrap.querySelector('#hcn-book-error');
      const spinner  = wrap.querySelector('.hcn-co-book-btn__spinner');
      const label    = wrap.querySelector('.hcn-co-book-btn__label');

      const first = wrap.querySelector('#hcn-first-name')?.value.trim();
      const last  = wrap.querySelector('#hcn-last-name')?.value.trim();
      const email = wrap.querySelector('#hcn-email')?.value.trim();
      const phone = wrap.querySelector('#hcn-phone')?.value.trim();
	  const adults   = wrap.querySelector('#hcn-adults')?.value || '1';
		const children = wrap.querySelector('#hcn-children')?.value || '0';
		const infants  = wrap.querySelector('#hcn-infants')?.value || '0';
		const pets     = wrap.querySelector('#hcn-pets')?.value || '0';

	  const totalGuests = parseInt(guests, 10) || 1;

      if (!first || !last || !email) {
        showError('Please enter your first name, last name, and email address.');
        return;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showError('Please enter a valid email address.');
        return;
      }

      setLoading(true);

      let intentId = '';

      try {
        // Confirm payment with Stripe
        if (state.stripe && state.elements) {
          const stripeResult = await state.stripe.confirmPayment({
            elements: state.elements,
            redirect: 'if_required',
          });
          if (stripeResult.error) {
            showError(stripeResult.error.message || 'Payment failed. Please check your card details.');
            setLoading(false);
            return;
          }
          intentId = stripeResult.paymentIntent?.id || '';
        }

        // intentId set above if Stripe was used

        // Create lead in Hostfully
        const fd = new FormData();
        fd.append('action',           'hcn_checkout_book');
        fd.append('nonce',            nonce);
        fd.append('first_name',       first);
        fd.append('last_name',        last);
        fd.append('email',            email);
        fd.append('phone',            phone);
        fd.append('post_id',          postId);
        fd.append('checkin',          checkin);
        fd.append('checkout',         checkout);
        fd.append('guests',           guests);
        fd.append('total',            String(totalWithFees()));
        fd.append('currency',         state.quote.currency || 'GBP');
        fd.append('promo_code',       state.promo_code);
        fd.append('agency_uid',       state.agency_uid);
        fd.append('payment_intent',   intentId);
        fd.append('rental_agreement', '1');
		fd.append('adults', adults);
		fd.append('children', children);
		fd.append('infants', infants);
		fd.append('pets', pets);

        state.selected_fees.forEach(uid => fd.append('selected_fees[]', uid));

        const res  = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
        const json = await res.json();

        if (!json.success) {
          showError(json.data?.message || 'Booking failed. Please contact us.');
          setLoading(false);
          return;
        }

        // Success — redirect or show confirmation
        const successUrl = new URL(window.location.href);
        successUrl.searchParams.set('booking_confirmed', '1');
        successUrl.searchParams.set('lead_uid', json.data.lead_uid);
        window.location.href = successUrl.toString();

      } catch (err) {
        console.error('[HCN checkout] handleBook error:', err);
        showError('An unexpected error occurred: ' + (err?.message || String(err)));
        setLoading(false);
      }
    }

    function setLoading(on) {
      const btn     = wrap.querySelector('#hcn-book-btn');
      const spinner = btn?.querySelector('.hcn-co-book-btn__spinner');
      const label   = btn?.querySelector('.hcn-co-book-btn__label');
      if (!btn) return;
      btn.disabled = on;
      if (spinner) spinner.hidden = !on;
      if (label)   label.hidden   =  on;
    }

    function showError(msg) {
      const el = wrap.querySelector('#hcn-book-error');
      if (!el) return;
      el.textContent = msg;
      el.hidden = false;
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }



  });
})(); 
