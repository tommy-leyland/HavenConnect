/* hcn-results-layout.js
   Handles:
     - Property count pulled from rendered tiles after each search
     - Sort select (client-side reorder of .hcn-tile elements)
     - Hide / Show map toggle
*/
(function () {
'use strict';

const init = () => {
    const layout    = document.querySelector('[data-hcn-layout]');
    if (!layout) return;

    const countEl   = layout.querySelector('[data-hcn-layout-count]');
    const sortEl    = layout.querySelector('[data-hcn-layout-sort]');
    const mapToggle = layout.querySelector('[data-hcn-layout-map-toggle]');
    const mapLabel  = layout.querySelector('[data-hcn-layout-map-label]');
    const mapCol    = layout.querySelector('[data-hcn-layout-map]');
    const resultsEl = layout.querySelector('[data-hcn-layout-results]');

    // ── Count ────────────────────────────────────────────────────
    const updateCount = () => {
        if (!countEl) return;
        const grid = resultsEl ? resultsEl.querySelector('.hcn-results--grid') : null;
        if (!grid) {
            // Empty state or error message rendered — clear count
            countEl.textContent = '';
            return;
        }
        const n = grid.querySelectorAll('.hcn-tile').length;
        countEl.textContent = n === 1 ? '1 property found' : `${n} properties found`;
    };

    // ── Sort ─────────────────────────────────────────────────────
    const applySort = (value) => {
        const grid = resultsEl ? resultsEl.querySelector('.hcn-results--grid') : null;
        if (!grid) return;

        const tiles = Array.from(grid.querySelectorAll('.hcn-tile'));
        if (!tiles.length) return;

        const getPrice = (tile) => {
            const priceEl = tile.querySelector('.hcn-tile__price strong');
            if (!priceEl) return Infinity;
            // Strip £ and commas, parse
            return parseFloat(priceEl.textContent.replace(/[^0-9.]/g, '')) || Infinity;
        };

        const getName = (tile) => {
            const titleEl = tile.querySelector('.hcn-tile__title');
            return titleEl ? titleEl.textContent.trim().toLowerCase() : '';
        };

        if (value === 'price-asc') {
            tiles.sort((a, b) => getPrice(a) - getPrice(b));
        } else if (value === 'price-desc') {
            tiles.sort((a, b) => getPrice(b) - getPrice(a));
        } else if (value === 'name-asc') {
            tiles.sort((a, b) => getName(a).localeCompare(getName(b)));
        } else {
            // 'default' — restore DOM order (tiles carry data-hcn-order set below)
            tiles.sort((a, b) => {
                return parseInt(a.dataset.hcnOrder || '0', 10)
                     - parseInt(b.dataset.hcnOrder || '0', 10);
            });
        }

        tiles.forEach(tile => grid.appendChild(tile));
    };

    // Stamp original order so "Default" can restore it
    const stampOrder = () => {
        const grid = resultsEl ? resultsEl.querySelector('.hcn-results--grid') : null;
        if (!grid) return;
        grid.querySelectorAll('.hcn-tile').forEach((tile, i) => {
            tile.dataset.hcnOrder = String(i);
        });
    };

    sortEl?.addEventListener('change', () => {
        applySort(sortEl.value);
    });

    // ── Map toggle ────────────────────────────────────────────────
    let mapVisible = true;

    mapToggle?.addEventListener('click', () => {
        mapVisible = !mapVisible;
        layout.classList.toggle('hcn-layout--map-hidden', !mapVisible);
        if (mapLabel) mapLabel.textContent = mapVisible ? 'Hide map' : 'Show map';
    });

    // ── React to search results updating ─────────────────────────
    // hcn:results-updated fires on document from hcn-search.js after each AJAX result
    document.addEventListener('hcn:results-updated', () => {
        stampOrder();
        updateCount();
        // Re-apply current sort if one is selected
        if (sortEl && sortEl.value !== 'default') applySort(sortEl.value);
    }); 

    // Initial render (SSR tiles already in DOM on page load)
    stampOrder();
    updateCount();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
})();