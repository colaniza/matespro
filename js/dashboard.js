/**
 * ECOWATTS — Lógica del panel
 * 1. Presentación · 2. Calculadora · 3. Resumen · 4. Gráficos · 5. Asistente
 * Incluye transiciones animadas entre vistas, selector Región → Comuna,
 * tema claro/oscuro y gráficos sincronizados con el tema.
 */

document.addEventListener("DOMContentLoaded", () => {

  /* ─────────────────────────────────────────────────────────────
     0. ESTADO GLOBAL
     Declarado al inicio para que esté disponible antes de cualquier
     llamada (evita errores de "Cannot access before initialization").
     ───────────────────────────────────────────────────────────── */
  let activeCategory      = "cocina";
  let activePeriodo       = "1_mes";
  let lastPayloadData     = null;
  let chartConsumoInst    = null;
  let chartAhorroInst     = null;
  let currentView         = null;
  let viewToken           = 0;
  let isVoiceSpeaking     = false;
  let voiceUtterance      = null;
  let currentAudioElement = null;
  let karaokeInterval     = null;
  let karaokeWords        = [];
  let karaokeActiveIndex  = -1;

  const fmtCLP = (num) => new Intl.NumberFormat("es-CL").format(Math.round(num || 0));

  /* ─────────────────────────────────────────────────────────────
     1. TEMA CLARO / OSCURO
     ───────────────────────────────────────────────────────────── */
  const btnThemeTopbar = document.getElementById("btn-theme-topbar");
  const ICON_SUN  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  const ICON_MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';

  function applyDashboardTheme(theme) {
    const isLight = theme === "light";
    document.body.classList.toggle("light-mode", isLight);
    document.documentElement.classList.remove("light-preload");
    if (btnThemeTopbar) {
      btnThemeTopbar.innerHTML = isLight ? ICON_MOON : ICON_SUN;
      btnThemeTopbar.title = isLight ? "Cambiar a modo oscuro" : "Cambiar a modo claro";
    }
    localStorage.setItem("ecowatts-theme", theme);
    updateChartsTheme();
  }

  const savedTheme  = localStorage.getItem("ecowatts-theme");
  const prefersLight = window.matchMedia("(prefers-color-scheme: light)").matches;
  applyDashboardTheme(savedTheme || (prefersLight ? "light" : "dark"));

  if (btnThemeTopbar) {
    btnThemeTopbar.addEventListener("click", () => {
      applyDashboardTheme(document.body.classList.contains("light-mode") ? "dark" : "light");
    });
  }

  /* ─────────────────────────────────────────────────────────────
     2. NAVEGACIÓN CON TRANSICIONES ANIMADAS
     ───────────────────────────────────────────────────────────── */
  const VIEW_ORDER = ["intro", "calc", "cart", "analytics", "ai"];

  const views = {
    intro:     document.getElementById("view-intro"),
    calc:      document.getElementById("view-calculator"),
    cart:      document.getElementById("view-cart"),
    analytics: document.getElementById("view-analytics"),
    ai:        document.getElementById("view-ai")
  };

  const steppers = {
    intro:     document.getElementById("stepper-step-1"),
    calc:      document.getElementById("stepper-step-2"),
    cart:      document.getElementById("stepper-step-3"),
    analytics: document.getElementById("stepper-step-4"),
    ai:        document.getElementById("stepper-step-5")
  };

  const navButtons = {
    intro:     document.getElementById("nav-btn-intro"),
    calc:      document.getElementById("nav-btn-calc"),
    cart:      document.getElementById("nav-btn-cart"),
    analytics: document.getElementById("nav-btn-analytics"),
    ai:        document.getElementById("nav-btn-ai")
  };

  const topbarTitle = document.getElementById("topbar-view-title");
  const sidebar     = document.getElementById("sidebar");
  const overlay     = document.getElementById("sidebarOverlay");
  const toggleBtn   = document.getElementById("toggleSidebar");

  function setActiveIndicators(viewName) {
    Object.keys(views).forEach(key => {
      if (steppers[key])   steppers[key].classList.toggle("active", key === viewName);
      if (navButtons[key]) navButtons[key].classList.toggle("active", key === viewName);
    });

    if (topbarTitle && views[viewName]) {
      const newTitle = views[viewName].dataset.title || "";
      if (topbarTitle.textContent !== newTitle) {
        topbarTitle.classList.add("is-changing");
        setTimeout(() => {
          topbarTitle.textContent = newTitle;
          topbarTitle.classList.remove("is-changing");
        }, 220);
      }
    }
  }

  function showView(viewName, direction) {
    const next = views[viewName];
    if (!next) return;

    // Ocultar cualquier otra vista que haya quedado visible
    Object.keys(views).forEach(key => {
      if (key !== viewName && views[key]) {
        views[key].style.display = "none";
        views[key].classList.remove("is-entering", "is-leaving");
      }
    });

    next.style.setProperty("--dir", direction);
    next.style.display = "block";
    next.classList.remove("is-entering", "is-leaving");
    void next.offsetWidth; // reiniciar animación
    next.classList.add("is-entering");

    if (viewName === "analytics") {
      // Esperar a que el contenedor tenga dimensiones reales
      requestAnimationFrame(() => renderOrUpdateCharts(true));
    }
  }

  function switchView(viewName) {
    if (!views[viewName] || viewName === currentView) return;

    const prevName  = currentView;
    const prev      = prevName ? views[prevName] : null;
    const direction = prevName && VIEW_ORDER.indexOf(viewName) < VIEW_ORDER.indexOf(prevName) ? -1 : 1;
    const token     = ++viewToken;

    currentView = viewName;
    if (viewName !== "intro") {
      stopVoiceExplanation();
    }
    setActiveIndicators(viewName);
    closeMobileSidebar();

    if (prev && prev.style.display !== "none") {
      prev.style.setProperty("--dir", direction);
      prev.classList.remove("is-entering");
      prev.classList.add("is-leaving");
      setTimeout(() => {
        if (token !== viewToken) return; // otra navegación tomó el control
        prev.classList.remove("is-leaving");
        showView(viewName, direction);
        window.scrollTo({ top: 0, behavior: "smooth" });
      }, 300);
    } else {
      showView(viewName, direction);
    }
  }

  function bindNav(id, viewName) {
    const el = document.getElementById(id);
    if (el) el.addEventListener("click", (e) => { e.preventDefault(); switchView(viewName); });
  }

  Object.keys(navButtons).forEach(key => {
    if (navButtons[key]) navButtons[key].addEventListener("click", () => switchView(key));
  });

  bindNav("btn-goto-calculator", "calc");
  bindNav("btn-back-to-intro", "intro");
  bindNav("btn-goto-cart", "cart");
  bindNav("btn-back-to-calculator", "calc");
  bindNav("btn-goto-analytics-from-cart", "analytics");
  bindNav("btn-back-to-cart-from-analytics", "cart");
  bindNav("btn-goto-ai-from-analytics", "ai");

  // El stepper también es navegable
  Object.keys(steppers).forEach(key => {
    if (steppers[key]) {
      steppers[key].style.cursor = "pointer";
      steppers[key].addEventListener("click", () => switchView(key));
    }
  });

  // Menú lateral en móviles
  function closeMobileSidebar() {
    if (sidebar) sidebar.classList.remove("mobile-open");
    if (overlay) overlay.classList.remove("active");
  }

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener("click", () => {
      sidebar.classList.toggle("mobile-open");
      if (overlay) overlay.classList.toggle("active");
    });
  }
  if (overlay) overlay.addEventListener("click", closeMobileSidebar);

  /* ─────────────────────────────────────────────────────────────
     3. CATÁLOGO DE ARTEFACTOS
     ───────────────────────────────────────────────────────────── */
  const applianceCategories = {
    cocina: [
      { nombre: "Refrigerador", watts: 200, horas: 8.0 },
      { nombre: "Hervidor de Agua", watts: 1800, horas: 0.5 },
      { nombre: "Horno Microondas", watts: 1200, horas: 0.5 },
      { nombre: "Horno Eléctrico", watts: 1500, horas: 0.5 },
      { nombre: "Cocina Eléctrica", watts: 2000, horas: 1.0 },
      { nombre: "Licuadora", watts: 400, horas: 0.5 }
    ],
    clima: [
      { nombre: "Aire Acondicionado", watts: 1200, horas: 4.0 },
      { nombre: "Estufa Eléctrica", watts: 1500, horas: 3.0 },
      { nombre: "Ventilador", watts: 60, horas: 6.0 },
      { nombre: "Termo Calefont", watts: 1500, horas: 3.0 }
    ],
    tech: [
      { nombre: "Televisor", watts: 110, horas: 5.0 },
      { nombre: "Computador de Mesa", watts: 350, horas: 6.0 },
      { nombre: "Notebook / Laptop", watts: 65, horas: 6.0 },
      { nombre: "Módem Wi-Fi", watts: 15, horas: 24.0 }
    ],
    hogar: [
      { nombre: "Ampolleta LED", watts: 9, horas: 6.0 },
      { nombre: "Lavadora", watts: 500, horas: 1.0 },
      { nombre: "Secadora de Ropa", watts: 2200, horas: 1.0 },
      { nombre: "Plancha de Ropa", watts: 1500, horas: 0.5 },
      { nombre: "Aspiradora", watts: 1400, horas: 0.5 },
      { nombre: "Secador de Pelo", watts: 1600, horas: 0.5 }
    ]
  };

  /* ─────────────────────────────────────────────────────────────
     4. ELEMENTOS DEL DOM
     ───────────────────────────────────────────────────────────── */
  const $ = (id) => document.getElementById(id);

  const selectRegionTop   = $("select-region-topbar");
  const selectComunaTop   = $("select-comuna-topbar");
  const selectPeriodoCart = $("select-periodo-cart");
  const applianceGrid     = $("appliance-grid");
  const categoryTabs      = document.querySelectorAll(".category-clean-btn");

  const formAddDevice     = $("form-add-device");
  const inputDeviceName   = $("device-name");
  const inputDeviceWatts  = $("device-watts");
  const selectDeviceHours = $("device-hours-select");
  const inputDeviceQty    = $("device-qty");
  const calcSavedBadge    = $("calc-saved-badge");

  const cartTableBody     = $("cart-items-tbody");
  const cartEmptyMsg      = $("cart-empty-msg");
  const btnClearCart      = $("btn-clear-cart");

  const thCostoPeriodo        = $("th-costo-periodo");
  const kpiCostoHora          = $("kpi-costo-hora");
  const kpiKwhDiario          = $("kpi-kwh-diario");
  const kpiKwhPeriodo         = $("kpi-kwh-periodo");
  const totalBannerPeriodName = $("total-banner-period-name");
  const totalBannerAmount     = $("total-banner-amount");
  const miniComunaLabel       = $("mini-comuna-label");
  const badgeAnalyticsComuna  = $("badge-analytics-comuna");

  const navCartBadge = $("nav-cart-badge");
  const btnCartBadge = $("btn-cart-badge");

  const savingsContainer = $("savings-recommendation-container");
  const compValActual    = $("comp-val-actual");
  const compValSaving    = $("comp-val-saving");
  const compValTarget    = $("comp-val-target");

  const editModal      = $("editModal");
  const btnCloseModal  = $("btn-close-modal");
  const btnCancelModal = $("btn-cancel-modal");
  const formEditItem   = $("form-edit-item");
  const editItemId     = $("edit-item-id");
  const editItemWatts  = $("edit-item-watts");
  const editItemHours  = $("edit-item-hours");
  const editItemQty    = $("edit-item-qty");

  /* Toast */
  const toastEl      = $("ecowatts-toast");
  const toastTitleEl = $("toast-title");
  const toastMsgEl   = $("toast-msg");
  const toastIconEl  = $("toast-icon");
  let toastTimer = null;

  function showToast(title, message, icon = "✓") {
    if (!toastEl) return;
    toastTitleEl.textContent = title;
    toastMsgEl.textContent   = message;
    toastIconEl.textContent  = icon;
    toastEl.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove("show"), 3600);
  }

  function updateCartBadges(count, triggerBounce = false) {
    const displayVal = count > 9 ? "9+" : String(count);
    [navCartBadge, btnCartBadge].forEach(badge => {
      if (!badge) return;
      badge.textContent = displayVal;
      badge.style.display = count > 0 ? "inline-flex" : "none";
      if (triggerBounce && count > 0) {
        badge.classList.remove("cart-badge-bounce");
        void badge.offsetWidth;
        badge.classList.add("cart-badge-bounce");
      }
    });
  }

  function escapeHtml(text) {
    if (text === null || text === undefined) return "";
    return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  /* Animación numérica suave para montos */
  function animateNumber(el, target, formatter) {
    if (!el) return;
    const start = parseFloat(el.dataset.value || "0");
    const end   = target;
    el.dataset.value = end;
    if (start === end) { el.textContent = formatter(end); return; }
    const duration = 700;
    const t0 = performance.now();
    const step = (now) => {
      const p = Math.min(1, (now - t0) / duration);
      const eased = 1 - Math.pow(1 - p, 4);
      el.textContent = formatter(start + (end - start) * eased);
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }

  /* ─────────────────────────────────────────────────────────────
     5. PETICIÓN AJAX
     ───────────────────────────────────────────────────────────── */
  async function sendAjaxRequest(formData) {
    formData.append("periodo", activePeriodo);
    try {
      const response = await fetch("ajax_carrito.php", { method: "POST", body: formData });
      const data = await response.json();
      if (!data.success) {
        showToast("No se pudo completar", data.error || "Ocurrió un error al procesar la solicitud.", "!");
        return null;
      }
      return data;
    } catch (err) {
      console.error("Error AJAX:", err);
      showToast("Error de conexión", "No fue posible comunicarse con el servidor.", "!");
      return null;
    }
  }

  /* ─────────────────────────────────────────────────────────────
     6. SELECTOR EN CASCADA REGIÓN → COMUNA
     ───────────────────────────────────────────────────────────── */
  function populateCascadeComunas(regionNombre, comunasPorRegion, selectedComunaId = null) {
    if (!selectComunaTop) return null;
    const comunasDeRegion = comunasPorRegion[regionNombre] || [];
    selectComunaTop.innerHTML = "";

    if (comunasDeRegion.length === 0) {
      selectComunaTop.innerHTML = '<option value="">Sin comunas disponibles</option>';
      return null;
    }

    let idSeleccionado = null;
    comunasDeRegion.forEach((c, index) => {
      const opt = document.createElement("option");
      opt.value = c.id;
      opt.dataset.region = c.region;
      opt.textContent = `${c.comuna} · $${parseFloat(c.tarifa_kwh).toFixed(1)}/kWh`;
      const isSelected = selectedComunaId ? parseInt(c.id) === parseInt(selectedComunaId) : index === 0;
      if (isSelected) { opt.selected = true; idSeleccionado = c.id; }
      selectComunaTop.appendChild(opt);
    });

    return idSeleccionado || comunasDeRegion[0].id;
  }

  async function updateComuna(comunaId) {
    const fd = new FormData();
    fd.append("action", "update_comuna");
    fd.append("comuna_id", comunaId);
    const data = await sendAjaxRequest(fd);
    if (data) {
      renderUI(data);
      showToast("Ubicación actualizada", `Tarifa de ${data.comuna_actual.comuna}: $${parseFloat(data.comuna_actual.tarifa_kwh).toFixed(1)}/kWh.`);
    }
  }

  if (selectRegionTop) {
    selectRegionTop.addEventListener("change", () => {
      if (!lastPayloadData || !lastPayloadData.comunas_por_region) return;
      const nuevaComunaId = populateCascadeComunas(selectRegionTop.value, lastPayloadData.comunas_por_region);
      if (nuevaComunaId) updateComuna(nuevaComunaId);
    });
  }

  if (selectComunaTop) {
    selectComunaTop.addEventListener("change", () => {
      if (selectComunaTop.value) updateComuna(selectComunaTop.value);
    });
  }

  /* ─────────────────────────────────────────────────────────────
     7. RENDERIZADO DE DATOS
     ───────────────────────────────────────────────────────────── */
  function renderUI(data) {
    if (!data || !data.success) return;
    lastPayloadData = data;

    const comuna        = data.comuna_actual;
    const items         = data.items || [];
    const totales       = data.totales || {};
    const nombrePeriodo = data.nombre_periodo || "1 Mes (30 días)";
    activePeriodo = data.periodo_actual || activePeriodo;

    // Región y comuna
    if (selectRegionTop && comuna.region) selectRegionTop.value = comuna.region;
    if (data.comunas_por_region && comuna.region) {
      populateCascadeComunas(comuna.region, data.comunas_por_region, comuna.id);
    }

    if (miniComunaLabel) miniComunaLabel.textContent = comuna.comuna;
    if (badgeAnalyticsComuna) {
      badgeAnalyticsComuna.textContent = `${comuna.comuna} · $${parseFloat(comuna.tarifa_kwh).toFixed(1)}/kWh · ${comuna.empresa}`;
    }

    // Período y totales
    if (thCostoPeriodo) thCostoPeriodo.textContent = `Total (${nombrePeriodo})`;
    if (totalBannerPeriodName) totalBannerPeriodName.textContent = nombrePeriodo;

    const costoPeriodoRaw = parseFloat(totales.costo_total_periodo || 0);
    animateNumber(totalBannerAmount, costoPeriodoRaw, (v) => `$ ${fmtCLP(v)} CLP`);
    if (kpiCostoHora)  kpiCostoHora.textContent  = `$ ${totales.costo_hora_total_fmt || "0"} CLP/h`;
    if (kpiKwhDiario)  kpiKwhDiario.textContent  = `${totales.kwh_diario || "0.00"} kWh/día`;
    if (kpiKwhPeriodo) kpiKwhPeriodo.textContent = `${totales.kwh_periodo || "0.0"} kWh`;

    const cantEquipos = totales.total_items !== undefined ? totales.total_items : items.length;
    if (calcSavedBadge) calcSavedBadge.textContent = `${cantEquipos} artefacto${cantEquipos === 1 ? "" : "s"}`;
    updateCartBadges(cantEquipos, false);

    // Comparativa de ahorro
    const ahorroMonto    = Math.round(costoPeriodoRaw * 0.25);
    const costoConAhorro = Math.max(0, Math.round(costoPeriodoRaw - ahorroMonto));

    if (savingsContainer) {
      if (cantEquipos > 0 && costoPeriodoRaw > 0) {
        savingsContainer.style.display = "block";
        if (compValActual) compValActual.textContent = `$ ${fmtCLP(costoPeriodoRaw)}`;
        if (compValSaving) compValSaving.textContent = `−$ ${fmtCLP(ahorroMonto)}`;
        if (compValTarget) compValTarget.textContent = `$ ${fmtCLP(costoConAhorro)}`;
      } else {
        savingsContainer.style.display = "none";
      }
    }

    // Indicadores de gráficos (siempre mensuales)
    const tarifa         = parseFloat(comuna.tarifa_kwh || 145.5);
    const kwhMesEstimado = parseFloat(totales.kwh_diario || 0) * 30;
    const clpMesEstimado = kwhMesEstimado * tarifa;

    const setText = (id, txt) => { const el = $(id); if (el) el.textContent = txt; };
    setText("chart-lbl-kwh-mes", `${kwhMesEstimado.toFixed(1)} kWh`);
    setText("chart-lbl-clp-mes", `$ ${fmtCLP(clpMesEstimado)}`);
    setText("chart-lbl-gasto-actual", `$ ${fmtCLP(clpMesEstimado)}`);
    setText("chart-lbl-ahorro-posible", `−$ ${fmtCLP(clpMesEstimado * 0.25)}`);

    renderOrUpdateCharts(false);

    // Tabla
    if (!cartTableBody) return;
    cartTableBody.innerHTML = "";

    if (items.length === 0) {
      if (cartEmptyMsg) cartEmptyMsg.style.display = "block";
      return;
    }
    if (cartEmptyMsg) cartEmptyMsg.style.display = "none";

    items.forEach((item, idx) => {
      const tr = document.createElement("tr");
      tr.style.animationDelay = `${idx * 45}ms`;
      const horasVal = item.horas_uso_dia !== null && item.horas_uso_dia > 0
        ? `${item.horas_uso_dia} h`
        : `${item.horas_efectivas} h <span style="color:var(--text-muted)">(est.)</span>`;

      tr.innerHTML = `
        <td><span class="item-name-bold">${escapeHtml(item.nombre_dispositivo)}</span></td>
        <td>${item.watts} W</td>
        <td>${horasVal}</td>
        <td>${item.cantidad}</td>
        <td><span class="tag-clean-cost">$ ${item.costo_hora_fmt}</span></td>
        <td>${item.kwh_diario}</td>
        <td><span class="tag-clean-total">$ ${item.costo_periodo_fmt}</span></td>
        <td style="text-align:center;">
          <button type="button" class="btn-table-action delete" data-id="${item.id}" title="Quitar artefacto">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
          </button>
        </td>`;
      cartTableBody.appendChild(tr);
    });

    cartTableBody.querySelectorAll(".btn-table-action.delete").forEach(btn => {
      btn.addEventListener("click", async () => {
        if (!confirm("¿Desea quitar este artefacto de su lista?")) return;
        const row = btn.closest("tr");
        if (row) { row.style.transition = "opacity .3s, transform .3s"; row.style.opacity = "0"; row.style.transform = "translateX(12px)"; }
        const fd = new FormData();
        fd.append("action", "delete_item");
        fd.append("item_id", btn.dataset.id);
        const data = await sendAjaxRequest(fd);
        renderUI(data);
        if (data) showToast("Artefacto eliminado", "Su resumen ha sido actualizado.");
      });
    });
  }

  /* ─────────────────────────────────────────────────────────────
     8. GRÁFICOS (Chart.js) — colores tomados del tema activo
     ───────────────────────────────────────────────────────────── */
  function cssVar(name) {
    return getComputedStyle(document.body).getPropertyValue(name).trim();
  }

  function hexToRgba(hex, alpha) {
    const h = hex.replace("#", "");
    if (h.length !== 6) return hex;
    const n = parseInt(h, 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
  }

  function getThemeColors() {
    return {
      text:     cssVar("--text-primary") || "#ece8e1",
      muted:    cssVar("--text-muted") || "#85817a",
      grid:     cssVar("--border-soft") || "rgba(255,255,255,0.08)",
      accent:   cssVar("--color-accent") || "#c9a96e",
      positive: cssVar("--color-positive") || "#93ad8e",
      negative: cssVar("--color-negative") || "#c48b7c",
      surface:  cssVar("--bg-surface") || "#16171a"
    };
  }

  function baseChartOptions(colors) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 900, easing: "easeOutQuart" },
      interaction: { mode: "index", intersect: false },
      plugins: {
        legend: {
          position: "bottom",
          labels: { color: colors.muted, usePointStyle: true, pointStyle: "circle", boxWidth: 6, padding: 18, font: { family: "Inter", size: 11.5 } }
        },
        tooltip: {
          backgroundColor: colors.surface,
          titleColor: colors.text,
          bodyColor: colors.muted,
          borderColor: colors.grid,
          borderWidth: 1,
          padding: 12,
          cornerRadius: 6,
          titleFont: { family: "Inter", weight: "500" },
          bodyFont: { family: "Inter" }
        }
      },
      scales: {
        x: { ticks: { color: colors.muted, font: { family: "Inter", size: 11 } }, grid: { display: false }, border: { color: colors.grid } },
        y: { ticks: { color: colors.muted, font: { family: "Inter", size: 11 } }, grid: { color: colors.grid }, border: { display: false } }
      }
    };
  }

  function updateChartsTheme() {
    if (!chartConsumoInst && !chartAhorroInst) return;
    // Recrear con la nueva paleta para una transición limpia
    if (chartConsumoInst) { chartConsumoInst.destroy(); chartConsumoInst = null; }
    if (chartAhorroInst)  { chartAhorroInst.destroy();  chartAhorroInst  = null; }
    renderOrUpdateCharts(true);
  }

  function renderOrUpdateCharts(allowCreate) {
    if (typeof Chart === "undefined") return;
    const canvasConsumo = $("chart-consumo-mensual");
    const canvasAhorro  = $("chart-gasto-ahorro");
    if (!canvasConsumo || !canvasAhorro) return;

    const analyticsVisible = views.analytics && views.analytics.style.display !== "none";
    if (!chartConsumoInst && !(allowCreate && analyticsVisible)) return;

    const totales = (lastPayloadData && lastPayloadData.totales) || {};
    const comuna  = (lastPayloadData && lastPayloadData.comuna_actual) || { tarifa_kwh: 145.5 };
    const tarifa  = parseFloat(comuna.tarifa_kwh || 145.5);

    const kwhDiarioBase = parseFloat(totales.kwh_diario || 0);
    const kwhMesBase    = kwhDiarioBase > 0 ? kwhDiarioBase * 30 : 180;
    const clpMesBase    = kwhMesBase * tarifa;
    const colors        = getThemeColors();

    // Gráfico 1 — Consumo mensual (estacionalidad típica en Chile)
    const meses   = ["May", "Jun", "Jul", "Ago", "Sep", "Oct"];
    const factores= [0.92, 1.15, 1.25, 1.18, 1.05, 1.00];
    const kwhData = factores.map(f => Math.round(kwhMesBase * f));
    const clpData = kwhData.map(k => Math.round(k * tarifa));

    // Gráfico 2 — Gasto vs ahorro
    const meses2      = ["Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
    const gastoSerie  = [1.25, 1.18, 1.05, 1.00, 0.95, 0.98].map(f => Math.round(clpMesBase * f));
    const ahorroSerie = gastoSerie.map(g => Math.round(g * 0.25));
    const optimSerie  = gastoSerie.map((g, i) => g - ahorroSerie[i]);

    const clpTick = (v) => `$${fmtCLP(v)}`;

    if (chartConsumoInst) {
      chartConsumoInst.data.datasets[0].data = kwhData;
      chartConsumoInst.data.datasets[1].data = clpData;
      chartConsumoInst.update();
    } else {
      const opts = baseChartOptions(colors);
      opts.scales.y.title = { display: true, text: "kWh", color: colors.muted, font: { family: "Inter", size: 11 } };
      opts.scales.y1 = {
        position: "right",
        ticks: { color: colors.muted, font: { family: "Inter", size: 11 }, callback: clpTick },
        grid: { drawOnChartArea: false },
        border: { display: false }
      };
      opts.plugins.tooltip.callbacks = {
        label: (ctx) => ctx.dataset.yAxisID === "y1"
          ? ` Gasto: $ ${fmtCLP(ctx.raw)} CLP`
          : ` Consumo: ${ctx.raw} kWh`
      };

      chartConsumoInst = new Chart(canvasConsumo, {
        data: {
          labels: meses,
          datasets: [
            {
              type: "bar",
              label: "Consumo (kWh)",
              data: kwhData,
              backgroundColor: hexToRgba(colors.accent, 0.28),
              hoverBackgroundColor: hexToRgba(colors.accent, 0.5),
              borderColor: colors.accent,
              borderWidth: { top: 1.5, left: 0, right: 0, bottom: 0 },
              borderRadius: 3,
              barPercentage: 0.55,
              yAxisID: "y"
            },
            {
              type: "line",
              label: "Gasto estimado ($)",
              data: clpData,
              borderColor: colors.text,
              backgroundColor: colors.text,
              borderWidth: 1.5,
              tension: 0.4,
              pointRadius: 3,
              pointHoverRadius: 5,
              pointBackgroundColor: colors.surface,
              pointBorderColor: colors.text,
              yAxisID: "y1"
            }
          ]
        },
        options: opts
      });
    }

    if (chartAhorroInst) {
      chartAhorroInst.data.datasets[0].data = gastoSerie;
      chartAhorroInst.data.datasets[1].data = ahorroSerie;
      chartAhorroInst.data.datasets[2].data = optimSerie;
      chartAhorroInst.update();
    } else {
      const opts = baseChartOptions(colors);
      opts.scales.y.ticks.callback = clpTick;
      opts.plugins.tooltip.callbacks = { label: (ctx) => ` ${ctx.dataset.label}: $ ${fmtCLP(ctx.raw)}` };

      const barStyle = (color, alpha) => ({
        backgroundColor: hexToRgba(color, alpha),
        hoverBackgroundColor: hexToRgba(color, Math.min(1, alpha + 0.2)),
        borderColor: color,
        borderWidth: { top: 1.5, left: 0, right: 0, bottom: 0 },
        borderRadius: 3,
        barPercentage: 0.75,
        categoryPercentage: 0.7
      });

      chartAhorroInst = new Chart(canvasAhorro, {
        type: "bar",
        data: {
          labels: meses2,
          datasets: [
            { label: "Gasto actual", data: gastoSerie, ...barStyle(colors.negative, 0.3) },
            { label: "Ahorro (25%)", data: ahorroSerie, ...barStyle(colors.positive, 0.4) },
            { label: "Cuenta optimizada", data: optimSerie, ...barStyle(colors.accent, 0.3) }
          ]
        },
        options: opts
      });
    }
  }

  /* ─────────────────────────────────────────────────────────────
     9. ASISTENTE
     ───────────────────────────────────────────────────────────── */
  const aiChatMessagesView = $("ai-chat-messages-view");
  const aiFormView         = $("ai-form-view");
  const aiInputView        = $("ai-input-view");
  const btnToggleAiWidget  = $("btn-toggle-ai-widget");
  const btnCloseAiWidget   = $("btn-close-ai-widget");
  const aiWidgetWindow     = $("ai-widget-window");
  const aiWidgetMessages   = $("ai-widget-messages");
  const aiWidgetForm       = $("ai-widget-form");
  const aiWidgetInput      = $("ai-widget-input");
  const userInitial        = (document.querySelector(".user-avatar") || {}).textContent || "U";

  function openWidget() {
    aiWidgetWindow.classList.remove("is-closing");
    aiWidgetWindow.style.display = "flex";
    setTimeout(() => aiWidgetInput && aiWidgetInput.focus(), 150);
  }

  function closeWidget() {
    aiWidgetWindow.classList.add("is-closing");
    setTimeout(() => {
      aiWidgetWindow.style.display = "none";
      aiWidgetWindow.classList.remove("is-closing");
    }, 240);
  }

  if (btnToggleAiWidget && aiWidgetWindow) {
    btnToggleAiWidget.addEventListener("click", () => {
      aiWidgetWindow.style.display === "none" ? openWidget() : closeWidget();
    });
  }
  if (btnCloseAiWidget && aiWidgetWindow) btnCloseAiWidget.addEventListener("click", closeWidget);

  function formatAiMarkdown(text) {
    let html = escapeHtml(text);
    html = html.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
    html = html.replace(/(^|[^*])\*(?!\s)(.*?)\*/g, "$1<em>$2</em>");
    html = html.replace(/\n\n/g, "</p><p>");
    html = html.replace(/\n/g, "<br>");
    return `<p>${html}</p>`;
  }

  function appendChatMessage(container, role, text) {
    if (!container) return;
    const msg = document.createElement("div");
    msg.className = `ai-message ${role}`;
    const avatar = role === "assistant" ? "E" : escapeHtml(userInitial.trim());
    const body   = role === "assistant" ? formatAiMarkdown(text) : `<p>${escapeHtml(text)}</p>`;
    msg.innerHTML = `<div class="ai-msg-avatar">${avatar}</div><div class="ai-msg-bubble">${body}</div>`;
    container.appendChild(msg);
    container.scrollTop = container.scrollHeight;
  }

  function appendTyping(container, id) {
    if (!container) return;
    const el = document.createElement("div");
    el.id = id;
    el.className = "ai-message assistant";
    el.innerHTML = `<div class="ai-msg-avatar">E</div><div class="ai-msg-bubble"><span class="ai-typing"><span></span><span></span><span></span></span></div>`;
    container.appendChild(el);
    container.scrollTop = container.scrollHeight;
  }

  async function askAiAssistant(question, container, input) {
    if (!question || !question.trim()) return;
    appendChatMessage(container, "user", question.trim());
    if (input) input.value = "";

    const typingId = "ai-typing-" + Date.now();
    appendTyping(container, typingId);

    try {
      const c = lastPayloadData || {};
      const fd = new FormData();
      fd.append("pregunta", question.trim());
      fd.append("contexto[comuna]", c.comuna_actual ? c.comuna_actual.comuna : "Chile");
      fd.append("contexto[tarifa]", c.comuna_actual ? c.comuna_actual.tarifa_kwh : "150");
      fd.append("contexto[total_mes]", c.totales ? c.totales.costo_total_fmt : "0");
      fd.append("contexto[total_items]", c.totales ? c.totales.total_items : "0");

      const res  = await fetch("ajax_asistente.php", { method: "POST", body: fd });
      const data = await res.json();

      // Pequeña pausa para una sensación de respuesta natural
      await new Promise(r => setTimeout(r, 450));
      const typing = $(typingId);
      if (typing) typing.remove();

      appendChatMessage(container, "assistant",
        data && data.success && data.respuesta
          ? data.respuesta
          : "Disculpe, ocurrió un inconveniente al procesar su consulta. Intente nuevamente.");
    } catch (err) {
      const typing = $(typingId);
      if (typing) typing.remove();
      appendChatMessage(container, "assistant", "No fue posible comunicarse con el asistente en este momento.");
    }
  }

  if (aiFormView) aiFormView.addEventListener("submit", (e) => { e.preventDefault(); askAiAssistant(aiInputView.value, aiChatMessagesView, aiInputView); });
  if (aiWidgetForm) aiWidgetForm.addEventListener("submit", (e) => { e.preventDefault(); askAiAssistant(aiWidgetInput.value, aiWidgetMessages, aiWidgetInput); });

  document.querySelectorAll(".ai-chip-btn").forEach(chip => {
    chip.addEventListener("click", () => askAiAssistant(chip.dataset.query, aiChatMessagesView, aiInputView));
  });
  document.querySelectorAll(".ai-mini-chip").forEach(chip => {
    chip.addEventListener("click", () => askAiAssistant(chip.dataset.query, aiWidgetMessages, aiWidgetInput));
  });

  /* ─────────────────────────────────────────────────────────────
     9.1 ASISTENTE DE VOZ: NARRACIÓN CON VOZ URSA DE GEMINI
     ───────────────────────────────────────────────────────────── */

  function getBestSpanishVoice() {
    if (!("speechSynthesis" in window)) return null;
    const voices = window.speechSynthesis.getVoices();
    if (!voices || voices.length === 0) return null;

    // Voces masculinas conocidas para descartar estrictamente
    const isMale = (v) => {
      const n = (v.name + " " + (v.voiceURI || "")).toLowerCase();
      return n.includes("male") || n.includes("hombre") || n.includes("raul") ||
             n.includes("jorge") || n.includes("pablo") || n.includes("diego") ||
             n.includes("carlos") || n.includes("alvaro") || n.includes("miguel") ||
             n.includes("david") || n.includes("enrique") || n.includes("tomas") ||
             n.includes("gonzalo") || n.includes("julio");
    };

    // Identificación de voces femeninas
    const isFemale = (v) => {
      const n = (v.name + " " + (v.voiceURI || "")).toLowerCase();
      return n.includes("female") || n.includes("mujer") ||
             n.includes("sabina") || n.includes("zira") || n.includes("elena") ||
             n.includes("paulina") || n.includes("catalina") || n.includes("lucia") ||
             n.includes("monica") || n.includes("laura") || n.includes("soledad") ||
             n.includes("victoria") || n.includes("francisca") || n.includes("carmen") ||
             n.includes("camila") || n.includes("sofia") || n.includes("valeria") ||
             n.includes("paloma") || n.includes("jimena") || n.includes("mia") ||
             n.includes("dalia") || n.includes("natural") || n.includes("neural") ||
             (v.name.includes("Google") && v.lang.startsWith("es"));
    };

    // 1. Buscar primero específicamente 'Sabina' (voz femenina en español de Windows)
    const sabina = voices.find(v => v.name.toLowerCase().includes("sabina"));
    if (sabina) return sabina;

    // 2. Buscar cualquier voz femenina en español
    const esFemale = voices.find(v => v.lang && v.lang.toLowerCase().startsWith("es") && isFemale(v) && !isMale(v));
    if (esFemale) return esFemale;

    // 3. Voces neuronales/naturales femeninas
    const neuralFemale = voices.find(v => {
      const n = v.name.toLowerCase();
      return (n.includes("natural") || n.includes("neural") || n.includes("online")) && isFemale(v) && !isMale(v);
    });
    if (neuralFemale) return neuralFemale;

    // 4. Cualquier voz femenina disponible que no sea masculina
    const anyFemale = voices.find(v => isFemale(v) && !isMale(v));
    if (anyFemale) return anyFemale;

    return null;
  }

  /* ─────────────────────────────────────────────────────────────
     9.1 ASISTENTE DE VOZ: LOCUCIÓN CON EFECTO KARAOKE ELEGANTE
     Destaque sincronizado palabra por palabra fiel a la estética Ecowatts.
     ───────────────────────────────────────────────────────────── */
  const btnVoiceIntro      = $("btn-voice-intro");
  const btnVoiceIntroLabel = $("btn-voice-intro-label");
  const heroKaraokeBox     = $("hero-karaoke-box");
  const heroKaraokeText    = $("hero-karaoke-text");

  function renderKaraokeTranscript(text) {
    if (!heroKaraokeText || !heroKaraokeBox) return;
    heroKaraokeText.innerHTML = "";
    karaokeWords = [];
    karaokeActiveIndex = -1;

    // Fragment para inserción DOM ultrarrápida sin repintados (cero lagueo)
    const frag = document.createDocumentFragment();
    const tokens = text.split(/\s+/).filter(w => w.length > 0);
    tokens.forEach((wordStr, index) => {
      const span = document.createElement("span");
      span.className = "karaoke-word";
      span.dataset.index = index;
      span.textContent = wordStr;
      frag.appendChild(span);
      karaokeWords.push(span);
    });

    heroKaraokeText.appendChild(frag);
    heroKaraokeBox.style.display = "block";
    heroKaraokeBox.scrollTop = 0;
  }

  function highlightKaraokeWord(index) {
    if (!karaokeWords || karaokeWords.length === 0 || index === karaokeActiveIndex) return;

    // Actualización quirúrgica O(1): solo tocamos la palabra anterior y la nueva
    const prevIndex = karaokeActiveIndex;
    karaokeActiveIndex = index;

    if (prevIndex >= 0 && prevIndex < karaokeWords.length) {
      const prevSpan = karaokeWords[prevIndex];
      prevSpan.classList.remove("is-current");
      prevSpan.classList.add("is-spoken");
    }

    if (index >= 0 && index < karaokeWords.length) {
      const currSpan = karaokeWords[index];
      currSpan.classList.add("is-spoken", "is-current");

      // Auto-scroll fluido solo si la palabra se sale del viewport visible
      if (heroKaraokeBox) {
        const spanTop = currSpan.offsetTop;
        const boxScroll = heroKaraokeBox.scrollTop;
        const boxHeight = heroKaraokeBox.clientHeight;
        if (spanTop < boxScroll || spanTop > boxScroll + boxHeight - 40) {
          heroKaraokeBox.scrollTo({
            top: Math.max(0, spanTop - 36),
            behavior: "smooth"
          });
        }
      }
    }
  }

  function startKaraokeTimer(totalDurationSeconds) {
    if (karaokeInterval) clearInterval(karaokeInterval);
    if (!karaokeWords || karaokeWords.length === 0) return;

    const totalWords = karaokeWords.length;
    const intervalMs = Math.max(90, Math.floor((totalDurationSeconds * 1000) / totalWords));
    let currentIndex = 0;

    highlightKaraokeWord(0);
    karaokeInterval = setInterval(() => {
      currentIndex++;
      if (currentIndex >= totalWords) {
        clearInterval(karaokeInterval);
        karaokeInterval = null;
        return;
      }
      highlightKaraokeWord(currentIndex);
    }, intervalMs);
  }

  function stopVoiceExplanation() {
    if (karaokeInterval) {
      clearInterval(karaokeInterval);
      karaokeInterval = null;
    }

    if (heroKaraokeBox) {
      // Ocultar suavemente teleprompter tras concluir
      heroKaraokeBox.style.display = "none";
    }

    // Detener audio HTML5 si se está reproduciendo
    if (currentAudioElement) {
      try {
        currentAudioElement.pause();
        currentAudioElement.currentTime = 0;
      } catch (err) {}
      currentAudioElement = null;
    }

    // Detener Web Speech si estaba activo
    if ("speechSynthesis" in window) {
      window.speechSynthesis.cancel();
    }

    isVoiceSpeaking = false;
    if (btnVoiceIntro) {
      btnVoiceIntro.classList.remove("is-speaking");
      btnVoiceIntro.setAttribute("aria-pressed", "false");
    }
    if (btnVoiceIntroLabel) {
      btnVoiceIntroLabel.textContent = "Escuchar explicación";
    }
  }

  function setVoicePlayingState() {
    isVoiceSpeaking = true;
    if (btnVoiceIntro) {
      btnVoiceIntro.classList.add("is-speaking");
      btnVoiceIntro.setAttribute("aria-pressed", "true");
    }
    if (btnVoiceIntroLabel) {
      btnVoiceIntroLabel.textContent = "Detener locución";
    }
  }

  function playBrowserSpeechFallback(speechText) {
    if (!("speechSynthesis" in window)) {
      stopVoiceExplanation();
      showToast("Voz no disponible", "Su navegador no cuenta con soporte de audio.");
      return;
    }

    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(speechText);
    voiceUtterance  = utterance;
    utterance.lang  = "es-CL";
    utterance.rate  = 0.94;
    utterance.pitch = 1.0;

    const voice = getBestSpanishVoice();
    if (voice) {
      utterance.voice = voice;
      utterance.lang  = voice.lang;
    }

    renderKaraokeTranscript(speechText);

    // Detección precisa de palabra mediante charIndex exacto del habla (evita desincronización)
    utterance.onboundary = (event) => {
      if (event.name === "word" && typeof event.charIndex === "number") {
        const textUpToChar = speechText.substring(0, event.charIndex);
        const wordIdx = (textUpToChar.match(/\S+/g) || []).length;
        highlightKaraokeWord(wordIdx);
      }
    };

    utterance.onstart = () => {
      setVoicePlayingState();
    };

    utterance.onend = () => {
      stopVoiceExplanation();
    };

    utterance.onerror = () => {
      stopVoiceExplanation();
    };

    window.speechSynthesis.speak(utterance);
  }

  async function startVoiceExplanation() {
    // Si ya está reproduciendo, lo detenemos de inmediato
    if (isVoiceSpeaking) {
      stopVoiceExplanation();
      return;
    }

    stopVoiceExplanation();

    // Obtener datos del contexto actual para enriquecer la locución
    const c = lastPayloadData || {};
    const comuna = c.comuna_actual ? c.comuna_actual.comuna : "tu comuna";
    const tarifa = c.comuna_actual ? Math.round(c.comuna_actual.tarifa_kwh) : "150";

    const speechText = 
      `Hola. Te doy la bienvenida a Ecowatts. ` +
      `Soy tu asistente de eficiencia energética. ` +
      `Diseñamos este programa para que descubras con total claridad cuánto cuesta la electricidad que consumes en casa, ` +
      `utilizando la tarifa real de ${tarifa} pesos por kilowatt hora en ${comuna}. ` +
      `Puedes comenzar presionando el botón Comenzar con la calculadora. ` +
      `Ahí seleccionas artefactos como el refrigerador, el televisor o el hervidor, indicando cuántas horas al día están encendidos. ` +
      `Luego, en tu resumen y en la sección de gráficos, podrás ver tu proyección mensual y aplicar recomendaciones prácticas para ahorrar hasta un 30% en tu cuenta de luz. ` +
      `Cualquier pregunta que tengas, puedes consultarme directamente en el chat. ` +
      `Estoy aquí para ayudarte a ahorrar.`;

    if (btnVoiceIntroLabel) {
      btnVoiceIntroLabel.textContent = "Cargando voz Ursa…";
    }

    // 1. Intentar reproducir con la voz 'Ursa' de Google Gemini a través de Puter.js
    if (typeof window.puter !== "undefined" && window.puter.ai && typeof window.puter.ai.txt2speech === "function") {
      try {
        console.log("Solicitando síntesis de voz Gemini Ursa...");
        const ttsPromise = window.puter.ai.txt2speech(speechText, {
          provider: "gemini",
          voice: "Ursa"
        });

        const timeoutPromise = new Promise((_, reject) =>
          setTimeout(() => reject(new Error("Timeout conectando con servicio de voz Ursa")), 9000)
        );

        const audio = await Promise.race([ttsPromise, timeoutPromise]);

        if (audio) {
          currentAudioElement = audio;
          renderKaraokeTranscript(speechText);

          audio.onplay = () => {
            setVoicePlayingState();
          };

          // Sincronización ultrarrápida a 60fps usando currentTime directo
          const updateAudioSync = () => {
            if (!isVoiceSpeaking || !currentAudioElement || currentAudioElement.paused) return;
            const dur = audio.duration;
            if (dur && !isNaN(dur) && dur > 0 && karaokeWords.length > 0) {
              const progress = Math.max(0, Math.min(1, audio.currentTime / dur));
              const targetIndex = Math.min(karaokeWords.length - 1, Math.floor(progress * karaokeWords.length));
              highlightKaraokeWord(targetIndex);
            }
            requestAnimationFrame(updateAudioSync);
          };

          audio.onplaying = () => {
            requestAnimationFrame(updateAudioSync);
          };

          audio.onended = () => stopVoiceExplanation();
          audio.onerror = (e) => {
            console.warn("Error reproduciendo audio Gemini Ursa, pasando a voz de respaldo:", e);
            playBrowserSpeechFallback(speechText);
          };

          setVoicePlayingState();
          await audio.play();
          return;
        }
      } catch (geminiError) {
        console.warn("Aviso Gemini Ursa TTS:", geminiError);
      }
    }

    // 2. Si Puter.js no está disponible o falló la red, usar síntesis local con karaoke
    playBrowserSpeechFallback(speechText);
  }

  // Carga asíncrona de voces del navegador
  if ("speechSynthesis" in window) {
    window.speechSynthesis.onvoiceschanged = () => {
      // Las voces están listas en memoria
    };
  }

  if (btnVoiceIntro) {
    btnVoiceIntro.addEventListener("click", () => {
      startVoiceExplanation();
    });
  }

  /* ─────────────────────────────────────────────────────────────
     10. CALCULADORA
     ───────────────────────────────────────────────────────────── */
  function renderCategoryGrid(categoryKey) {
    if (!applianceGrid) return;
    activeCategory = categoryKey;
    applianceGrid.innerHTML = "";

    (applianceCategories[categoryKey] || []).forEach(item => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "appliance-clean-btn";
      btn.innerHTML = `
        <span class="appliance-btn-left"><span class="appliance-btn-name">${escapeHtml(item.nombre)}</span></span>
        <span class="appliance-btn-watts">${item.watts} W</span>`;

      btn.addEventListener("click", () => {
        applianceGrid.querySelectorAll(".appliance-clean-btn").forEach(b => b.classList.remove("selected"));
        btn.classList.add("selected");
        inputDeviceName.value   = item.nombre;
        inputDeviceWatts.value  = item.watts;
        selectDeviceHours.value = String(item.horas % 1 === 0 ? item.horas : item.horas);
        if (selectDeviceHours.value === "") selectDeviceHours.value = "";
      });

      applianceGrid.appendChild(btn);
    });
  }

  categoryTabs.forEach(tab => {
    tab.addEventListener("click", () => {
      categoryTabs.forEach(t => t.classList.remove("active"));
      tab.classList.add("active");
      renderCategoryGrid(tab.dataset.category);
    });
  });

  if (selectPeriodoCart) {
    selectPeriodoCart.addEventListener("change", async () => {
      activePeriodo = selectPeriodoCart.value;
      const fd = new FormData();
      fd.append("action", "get_cart");
      renderUI(await sendAjaxRequest(fd));
    });
  }

  if (formAddDevice) {
    formAddDevice.addEventListener("submit", async (e) => {
      e.preventDefault();
      const name  = inputDeviceName.value.trim();
      const watts = inputDeviceWatts.value.trim();
      if (!name || !watts) {
        showToast("Datos incompletos", "Seleccione o escriba un artefacto y su potencia.", "!");
        return;
      }

      const fd = new FormData();
      fd.append("action", "add_item");
      fd.append("nombre", name);
      fd.append("watts", watts);
      fd.append("horas_uso", selectDeviceHours.value);
      fd.append("cantidad", inputDeviceQty.value.trim() || 1);

      const data = await sendAjaxRequest(fd);
      if (data) {
        formAddDevice.reset();
        applianceGrid.querySelectorAll(".appliance-clean-btn").forEach(b => b.classList.remove("selected"));
        renderUI(data);
        const cant = data.totales && data.totales.total_items !== undefined ? data.totales.total_items : 1;
        updateCartBadges(cant, true);
        showToast("Artefacto añadido", `${name} se incorporó a su resumen.`);
      }
    });
  }

  if (btnClearCart) {
    btnClearCart.addEventListener("click", async () => {
      if (!confirm("¿Desea eliminar todos los artefactos de la lista?")) return;
      const fd = new FormData();
      fd.append("action", "clear_cart");
      renderUI(await sendAjaxRequest(fd));
    });
  }

  function closeEditModal() { if (editModal) editModal.style.display = "none"; }
  if (btnCloseModal)  btnCloseModal.addEventListener("click", closeEditModal);
  if (btnCancelModal) btnCancelModal.addEventListener("click", closeEditModal);

  if (formEditItem) {
    formEditItem.addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData();
      fd.append("action", "update_item");
      fd.append("item_id", editItemId.value);
      fd.append("watts", editItemWatts.value);
      fd.append("horas_uso", editItemHours.value);
      fd.append("cantidad", editItemQty.value);
      const data = await sendAjaxRequest(fd);
      if (data) { closeEditModal(); renderUI(data); }
    });
  }

  /* ─────────────────────────────────────────────────────────────
     11. INICIO
     ───────────────────────────────────────────────────────────── */
  switchView("intro");
  renderCategoryGrid("cocina");

  (async () => {
    const fd = new FormData();
    fd.append("action", "get_cart");
    renderUI(await sendAjaxRequest(fd));
  })();
});