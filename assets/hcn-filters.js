(function () {
'use strict';
const init = () => {
    const form = document.querySelector('[data-hcn-searchbar]');
    if (!form) return;
    const cfg      = window.HCN_SEARCH_BAR || {};
    const ajaxMode = !!cfg.ajax;
    const clearBtn   = form.querySelector('[data-hcn-filters-clear]');
    const applyBtn   = form.querySelector('[data-hcn-filters-apply]');
    const openBtn    = form.querySelector('[data-hcn-open="filters"]');
    const pop        = form.querySelector('[data-hcn-modal="filters"]');
    const closeBtn   = form.querySelector('[data-hcn-close]');
    const dot        = form.querySelector('[data-hcn-filters-dot]');
    const openPop  = () => { if (!pop) return; pop.setAttribute('aria-hidden','false'); };
    const closePop = () => { if (!pop) return; pop.setAttribute('aria-hidden','true'); };
    openBtn?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation();
        pop?.getAttribute('aria-hidden') === 'false' ? closePop() : openPop(); });
    closeBtn?.addEventListener('click', e => { e.preventDefault(); closePop(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closePop(); });
    document.addEventListener('click', e => {
        if (!pop || pop.getAttribute('aria-hidden') === 'true') return;
        if (pop.contains(e.target) || openBtn?.contains(e.target)) return;
        closePop();
    }, true);
    const inMin      = form.querySelector('[name="min_price"]');
    const inMax      = form.querySelector('[name="max_price"]');
    const inBeds     = form.querySelector('[name="bedrooms"]');
    const inBaths    = form.querySelector('[name="bathrooms"]');
    const inFeatures = form.querySelector('[name="features"]');
    const inPolicies = form.querySelector('[name="policies"]');
    const minR       = form.querySelector('[data-hcn-price-min-range]');
    const maxR       = form.querySelector('[data-hcn-price-max-range]');
    const priceLabel = form.querySelector('[data-hcn-price-label]');
    const rangeFill  = form.querySelector('[data-hcn-range-fill]');
    const bedOut     = form.querySelector('[data-hcn-bed-out]');
    const bathOut    = form.querySelector('[data-hcn-bath-out]');
    const bedAny     = form.querySelector('[data-hcn-bed-any]');
    const bathAny    = form.querySelector('[data-hcn-bath-any]');
    const featChips  = form.querySelector('[data-hcn-feature-chips]');
    const polChips   = form.querySelector('[data-hcn-policy-chips]');
    const polSection = form.querySelector('[data-hcn-policies-section]');
    // URL sync
    const emitSearch = () => window.dispatchEvent(new CustomEvent('hcn:search-updated'));
    const syncUrl = () => {
        if (!ajaxMode) return;
        const fd = new FormData(form), p = new URLSearchParams();
        fd.forEach((v,k) => { const val=String(v||'').trim(); if(val) p.set(k,val); });
        const url = new URL(window.location.href);
        url.search = p.toString();
        window.history.replaceState({}, '', url.toString());
        emitSearch();
    };
    // Price slider
    const getBounds = () => ({ min: parseInt(cfg.priceMin||0,10)||0, max: parseInt(cfg.priceMax||14500,10)||14500 });
    const fmt = n => '£' + Number(n).toLocaleString('en-GB');
    const updateFill = () => {
        const b = getBounds();
        const minV = parseInt(minR?.value||b.min, 10);
        const maxV = parseInt(maxR?.value||b.max, 10);
        const pMin = ((minV-b.min)/(b.max-b.min))*100;
        const pMax = ((maxV-b.min)/(b.max-b.min))*100;
        if (rangeFill) { rangeFill.style.left=pMin+'%'; rangeFill.style.width=(pMax-pMin)+'%'; }
    };
    const setPriceUI = (minV, maxV) => {
        const b = getBounds();
        let a = Math.max(b.min, Math.min(b.max, minV));
        let c = Math.max(b.min, Math.min(b.max, maxV));
        if (a > c) [a,c]=[c,a];
        if (minR) minR.value = String(a);
        if (maxR) maxR.value = String(c);
        if (inMin) inMin.value = a > b.min ? String(a) : '';
        if (inMax) inMax.value = c < b.max ? String(c) : '';
        if (priceLabel) priceLabel.textContent = `${fmt(a)} to ${fmt(c)}`;
        updateFill(); updateDot();
    };
    minR?.addEventListener('input', () => {
        let minV=parseInt(minR.value,10), maxV=parseInt(maxR?.value||0,10);
        if (minV > maxV) { minV=maxV; minR.value=String(minV); }
        setPriceUI(minV, maxV);
    });
    maxR?.addEventListener('input', () => {
        let minV=parseInt(minR?.value||0,10), maxV=parseInt(maxR.value,10);
        if (maxV < minV) { maxV=minV; maxR.value=String(maxV); }
        setPriceUI(minV, maxV);
    });
    // Beds / baths steppers
    const updateRoomUI = () => {
        const beds  = parseInt(bedOut?.textContent  ||'0',10)||0;
        const baths = parseInt(bathOut?.textContent ||'0',10)||0;
        if (inBeds)  inBeds.value  = beds  > 0 ? String(beds)  : '';
        if (inBaths) inBaths.value = baths > 0 ? String(baths) : '';
        if (bedAny)  bedAny.style.display  = beds  === 0 ? '' : 'none';
        if (bathAny) bathAny.style.display = baths === 0 ? '' : 'none';
        if (bedOut)  bedOut.style.display   = beds  > 0  ? '' : 'none';
        if (bathOut) bathOut.style.display  = baths > 0  ? '' : 'none';
        updateDot();
    };
    const bindRoomStepper = (key, outEl) => {
        const minus = form.querySelector(`[data-hcn-step="-"][data-hcn-room="${key}"]`);
        const plus  = form.querySelector(`[data-hcn-step="+"][data-hcn-room="${key}"]`);
        const get   = () => parseInt(outEl?.textContent||'0',10)||0;
        const set   = n  => { if (outEl) outEl.textContent = String(Math.max(0,n)); };
        minus?.addEventListener('click', e => { e.preventDefault(); set(get()-1); updateRoomUI(); });
        plus?.addEventListener('click',  e => { e.preventDefault(); set(get()+1); updateRoomUI(); });
    };
    bindRoomStepper('bedrooms',  bedOut);
    bindRoomStepper('bathrooms', bathOut);
    // Feature chips
    const selectedFeatures = new Set((inFeatures?.value||'').split(',').map(s=>s.trim()).filter(Boolean));
    const renderFeatChips = () => {
        if (!featChips) return;
        const feats = Array.isArray(cfg.featuredFeatures) ? cfg.featuredFeatures : [];
        if (!feats.length) { featChips.innerHTML = '<span class="hcn-muted-sm">No features configured in Settings yet.</span>'; return; }
        featChips.innerHTML = feats.map(f => {
            const on = selectedFeatures.has(String(f.slug));
            return `<button type="button" class="hcn-chip${on?' is-on':''}" data-feat-slug="${f.slug}">${f.name}</button>`;
        }).join('');
        featChips.querySelectorAll('[data-feat-slug]').forEach(btn => btn.addEventListener('click', () => {
            const slug = btn.getAttribute('data-feat-slug');
            if (!slug) return;
            if (selectedFeatures.has(slug)) selectedFeatures.delete(slug); else selectedFeatures.add(slug);
            if (inFeatures) inFeatures.value = [...selectedFeatures].join(',');
            renderFeatChips(); updateDot();
        }));
    };
    // Policy chips
    const selectedPolicies = new Set((inPolicies?.value||'').split(',').map(s=>s.trim()).filter(Boolean));
    const renderPolChips = () => {
        const pols = Array.isArray(cfg.featuredPolicies) ? cfg.featuredPolicies : [];
        if (!polSection) return;
        if (!pols.length) { polSection.style.display = 'none'; return; }
        polSection.style.display = '';
        if (!polChips) return;
        polChips.innerHTML = pols.map(p => {
            const on = selectedPolicies.has(String(p.slug));
            return `<button type="button" class="hcn-chip${on?' is-on':''}" data-pol-slug="${p.slug}">${p.name}</button>`;
        }).join('');
        polChips.querySelectorAll('[data-pol-slug]').forEach(btn => btn.addEventListener('click', () => {
            const slug = btn.getAttribute('data-pol-slug');
            if (!slug) return;
            if (selectedPolicies.has(slug)) selectedPolicies.delete(slug); else selectedPolicies.add(slug);
            if (inPolicies) inPolicies.value = [...selectedPolicies].join(',');
            renderPolChips(); updateDot();
        }));
    };
    // Dot indicator — shows count of active filters
    const updateDot = () => {
        let count = 0;
        if (inMin?.value)      count++;
        if (inMax?.value)      count++;
        if (inBeds?.value)     count++;
        if (inBaths?.value)    count++;
        if (inFeatures?.value) count += inFeatures.value.split(',').filter(Boolean).length;
        if (inPolicies?.value) count += inPolicies.value.split(',').filter(Boolean).length;
        if (dot) {
            dot.style.display = count > 0 ? '' : 'none';
            dot.textContent   = count > 0 ? String(count) : '';
        }
    };
    // Clear / Apply
    clearBtn?.addEventListener('click', e => {
        e.preventDefault();
        if (inMin)      inMin.value      = '';
        if (inMax)      inMax.value      = '';
        if (inBeds)     inBeds.value     = '';
        if (inBaths)    inBaths.value    = '';
        if (inFeatures) inFeatures.value = '';
        if (inPolicies) inPolicies.value = '';
        if (bedOut)     bedOut.textContent  = '0';
        if (bathOut)    bathOut.textContent = '0';
        selectedFeatures.clear(); selectedPolicies.clear();
        const b = getBounds();
        setPriceUI(b.min, b.max); updateRoomUI(); renderFeatChips(); renderPolChips(); syncUrl();
    });
    applyBtn?.addEventListener('click', e => { e.preventDefault(); syncUrl(); });
    // Dynamic price bounds from map
    window.addEventListener('hcn:price-bounds', e => {
        const b = e?.detail || {};
        const minB = parseInt(b.min||0,10)||0, maxB = parseInt(b.max||0,10)||0;
        if (!maxB) return;
        cfg.priceMin = minB; cfg.priceMax = maxB;
        if (minR) { minR.min=String(minB); minR.max=String(maxB); }
        if (maxR) { maxR.min=String(minB); maxR.max=String(maxB); }
        setPriceUI(inMin?.value?parseInt(inMin.value,10):minB, inMax?.value?parseInt(inMax.value,10):maxB);
    });
    if (window.HCN_PRICE_BOUNDS) window.dispatchEvent(new CustomEvent('hcn:price-bounds', {detail: window.HCN_PRICE_BOUNDS}));
    // Init
    const b = getBounds();
    setPriceUI(inMin?.value?parseInt(inMin.value,10):b.min, inMax?.value?parseInt(inMax.value,10):b.max);
    updateRoomUI(); renderFeatChips(); renderPolChips(); updateDot();
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
})(); 