document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".hcn-map-wrap");
  if (!wrap) return;

  const ajaxUrl = wrap.dataset.ajax;
  const nonce = wrap.dataset.nonce;
  const perPage = wrap.dataset.perPage || "250";

  const mapEl = document.getElementById("hcn-map");
  const cardEl = wrap.querySelector(".hcn-map-card-inner");

  const formatGBP = (n) => "£" + Math.round(Number(n || 0));

  const getFiltersFromUrl = () => {
    const p = new URLSearchParams(window.location.search);
    return {
      checkin: p.get("checkin") || "",
      checkout: p.get("checkout") || "",
      guests: p.get("guests") || "",
      bedrooms: p.get("bedrooms") || "",
      bathrooms: p.get("bathrooms") || "",
    };
  };

  const map = new google.maps.Map(mapEl, {
    center: { lat: 52.5, lng: -1.9 },
    zoom: 6,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true,
  });

  let markers = [];

  async function fetchMarkers() {
    // Clear markers
    markers.forEach(m => m.setMap(null));
    markers = [];

    const filters = getFiltersFromUrl();

    const fd = new FormData();
    fd.append("action", "hcn_map_properties");
    fd.append("nonce", nonce);
    fd.append("per_page", perPage);
    Object.entries(filters).forEach(([k, v]) => fd.append(k, v));

    const res = await fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: fd });
    const json = await res.json();
    const items = (json && json.success && json.data && json.data.items) ? json.data.items : [];

    if (!items.length) {
      cardEl.innerHTML = "<p>No properties found for these filters.</p>";
      return;
    }

    const bounds = new google.maps.LatLngBounds();

    items.forEach(item => {
      const labelText = item.from ? `£${item.from}` : "£—";

      const marker = new google.maps.Marker({
        position: { lat: item.lat, lng: item.lng },
        map,
        label: { text: labelText, fontSize: "12px", fontWeight: "600" },
        title: item.title,
      });

      marker.addListener("click", async () => {
        cardEl.innerHTML = `
          <div style="display:flex;gap:10px;">
            ${item.thumb ? `<img src="${item.thumb}" style="width:96px;height:74px;object-fit:cover;border-radius:10px;">` : ""}
            <div style="min-width:0;">
              <div style="font-weight:700;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${item.title}</div>
              <div style="color:#666;font-size:13px;">Sleeps ${item.sleeps} • ${item.bedrooms} bedrooms • ${item.bathrooms} baths</div>
              <div class="hcn-price" style="margin-top:8px;font-weight:800;">From ${item.from ? "£" + item.from : "£—"}/night</div>
              <div style="margin-top:8px;"><a href="${item.url}">View property</a></div>
            </div>
          </div>
        `;

        const f = getFiltersFromUrl();

        // Only quote if dates are present
        if (f.checkin && f.checkout && item.uid) {
          const qfd = new FormData();
          qfd.append("action", "hcn_quote_nightly");
          qfd.append("nonce", nonce);
          qfd.append("uid", item.uid);
          qfd.append("checkin", f.checkin);
          qfd.append("checkout", f.checkout);
          qfd.append("guests", f.guests || "");

          const qres = await fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: qfd });
          const qjson = await qres.json();

          if (qjson && qjson.success && qjson.data) {
            const perNight = qjson.data.perNight;
            const priceEl = cardEl.querySelector(".hcn-price");
            if (priceEl) priceEl.textContent = `From ${formatGBP(perNight)}/night`;

            marker.setLabel({ text: `${formatGBP(perNight)}`, fontSize: "12px", fontWeight: "600" });
          } else {
            const msg = (qjson && qjson.data && qjson.data.message) ? qjson.data.message : "Quote failed.";
            const priceEl = cardEl.querySelector(".hcn-price");
            if (priceEl) priceEl.textContent = msg;
          }
        }
      });

      bounds.extend(marker.getPosition());
      markers.push(marker);
    });

    map.fitBounds(bounds);
  }

  fetchMarkers();

  // If your search updates URL via pushState, reload markers on back/forward:
  window.addEventListener("popstate", fetchMarkers);

  // Optional: if your search JS dispatches an event, listen for it.
  window.addEventListener("hcn:search-updated", fetchMarkers);
});