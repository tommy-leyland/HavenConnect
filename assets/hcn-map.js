document.addEventListener("DOMContentLoaded", () => {
  const wrap = document.querySelector(".hcn-map-wrap");
  if (!wrap) return;

  const ajaxUrl = wrap.dataset.ajax;
  const nonce = wrap.dataset.nonce;
  const perPage = wrap.dataset.perPage || "250";

  const mapEl = document.getElementById("hcn-map");
  const popEl = wrap.querySelector(".hcn-map-popover");
  if (!mapEl || !popEl) return;

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

  // ---------- Popover overlay ----------
  class PopoverOverlay extends google.maps.OverlayView {
    constructor(map, el) {
      super();
      this.map = map;
      this.el = el;
      this.pos = null;
      this.setMap(map);

      this.el.addEventListener("click", (e) => {
        if (e.target.closest("[data-hcn-close]")) this.hide();
      });
    }
    onAdd() {
      this.getPanes().floatPane.appendChild(this.el);
      this.el.style.position = "absolute";
      this.el.style.display = "none";
    }
    draw() {
      if (!this.pos) return;
      const proj = this.getProjection();
      if (!proj) return;
      const p = proj.fromLatLngToDivPixel(this.pos);
      if (!p) return;
      this.el.style.left = `${p.x}px`;
      this.el.style.top = `${p.y}px`;
    }
    show(latLng, html) {
      this.pos = latLng;
      this.el.innerHTML = html;
      this.el.style.display = "block";
      this.draw();
      const img = this.el.querySelector("img");
      if (img) img.addEventListener("load", () => this.draw(), { once: true });
    }
    hide() {
      this.pos = null;
      this.el.style.display = "none";
      this.el.innerHTML = "";
      document.querySelectorAll(".hcn-price-pill.is-active").forEach(el => el.classList.remove("is-active"));
    }
  }

  // ---------- Price pill overlay ----------
  class PricePillOverlay extends google.maps.OverlayView {
    constructor(map, item, onClick) {
      super();
      this.map = map;
      this.item = item;
      this.onClick = onClick;
      this.pos = new google.maps.LatLng(item.lat, item.lng);

      this.el = document.createElement("button");
      this.el.type = "button";
      this.el.className = "hcn-price-pill";
      this.el.textContent = item.from ? `£${item.from}` : "£—";

      this.el.addEventListener("click", (e) => {
        e.preventDefault();
        document.querySelectorAll(".hcn-price-pill.is-active").forEach(el => el.classList.remove("is-active"));
        this.el.classList.add("is-active");
        this.onClick(this.item, this.pos, this);
      });

      this.setMap(map);
    }
    onAdd() {
      this.getPanes().overlayMouseTarget.appendChild(this.el);
      this.el.style.position = "absolute";
    }
    draw() {
      const proj = this.getProjection();
      if (!proj) return;
      const p = proj.fromLatLngToDivPixel(this.pos);
      if (!p) return;
      this.el.style.left = `${p.x}px`;
      this.el.style.top = `${p.y}px`;
      this.el.style.transform = "translate(-50%, -50%)";
    }
    onRemove() {
      if (this.el?.parentNode) this.el.parentNode.removeChild(this.el);
    }
    setLabelText(text) {
      this.el.textContent = text;
    }
  }

  const map = new google.maps.Map(mapEl, {
    center: { lat: 52.5, lng: -1.9 },
    zoom: 6,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: true,
  });

  const pop = new PopoverOverlay(map, popEl);
  let pills = [];

  async function postAjax(fd) {
    const res = await fetch(ajaxUrl, { method: "POST", credentials: "same-origin", body: fd });
    return res.json();
  }

  function cardHtml(item) {
    return `
      <div class="hcn-pop">
        <button class="hcn-pop__close" type="button" data-hcn-close aria-label="Close">×</button>
        ${item.thumb ? `<div class="hcn-pop__img"><img src="${item.thumb}" alt=""></div>` : `<div class="hcn-pop__img hcn-pop__img--ph"></div>`}
        <div class="hcn-pop__body">
          <div class="hcn-pop__title">${item.title}</div>
          <div class="hcn-pop__sub">${item.sub || ""}</div>
          <div class="hcn-pop__icons">
            <span>👤 ${item.sleeps || 0}</span>
            <span>🛏 ${item.bedrooms || 0}</span>
            <span>🛁 ${item.bathrooms || 0}</span>
          </div>
          <div class="hcn-pop__price hcn-price">From <strong>${item.from ? "£" + item.from : "£—"}</strong> per night</div>
          <a class="hcn-pop__link" href="${item.url}">View property</a>
        </div>
      </div>
    `;
  }

  async function fetchMarkers() {
    pills.forEach(p => p.setMap(null));
    pills = [];
    pop.hide();

    const filters = getFiltersFromUrl();
    const fd = new FormData();
    fd.append("action", "hcn_map_properties");
    fd.append("nonce", nonce);
    fd.append("per_page", perPage);
    Object.entries(filters).forEach(([k, v]) => fd.append(k, v));

    const json = await postAjax(fd);
    const items = json?.data?.items || [];
    if (!items.length) return;

    const bounds = new google.maps.LatLngBounds();

    items.forEach((item) => {
      const pill = new PricePillOverlay(map, item, async (it, latLng, pillInstance) => {
        pop.show(latLng, cardHtml(it));

        const f = getFiltersFromUrl();
        if (f.checkin && f.checkout && it.provider === "hostfully" && it.uid) {
          const qfd = new FormData();
          qfd.append("action", "hcn_quote_nightly");
          qfd.append("nonce", nonce);
          qfd.append("uid", it.uid);
          qfd.append("checkin", f.checkin);
          qfd.append("checkout", f.checkout);
          qfd.append("guests", f.guests || "");

          const qjson = await postAjax(qfd);
          if (qjson?.data?.perNight) {
            const perNight = qjson.data.perNight;
            const priceEl = popEl.querySelector(".hcn-price");
            if (priceEl) priceEl.innerHTML = `From <strong>${formatGBP(perNight)}</strong> per night`;
            pillInstance.setLabelText(formatGBP(perNight));
          }
        }
      });

      pills.push(pill);
      bounds.extend(new google.maps.LatLng(item.lat, item.lng));
    });

    map.fitBounds(bounds);
  }

  fetchMarkers();
  window.addEventListener("popstate", fetchMarkers);
  window.addEventListener("hcn:search-updated", fetchMarkers);
});