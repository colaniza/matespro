/**
 * ECOWATTS - JavaScript para Login y Registro
 * • Toggle mostrar/ocultar contraseña
 * • Filtro región → comuna en el registro
 * • Tema claro / oscuro (persiste en localStorage)
 * • Validación en tiempo real de contraseñas
 */

document.addEventListener("DOMContentLoaded", () => {

  /* ─────────────────────────────────────────
     1. TEMA CLARO / OSCURO
  ───────────────────────────────────────── */
  const btnTheme   = document.getElementById("btn-theme-toggle");
  const savedTheme = localStorage.getItem("ecowatts-theme");

  // Aplicar tema guardado o detectar preferencia del sistema
  const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  const initialTheme = savedTheme || (prefersDark ? "dark" : "light");
  applyTheme(initialTheme);

  if (btnTheme) {
    btnTheme.addEventListener("click", () => {
      const isLight = document.body.classList.contains("light-mode");
      applyTheme(isLight ? "dark" : "light");
    });
  }

  function applyTheme(theme) {
    if (theme === "light") {
      document.body.classList.add("light-mode");
      if (btnTheme) btnTheme.textContent = "🌙";
      if (btnTheme) btnTheme.setAttribute("title", "Cambiar a modo oscuro");
    } else {
      document.body.classList.remove("light-mode");
      if (btnTheme) btnTheme.textContent = "☀️";
      if (btnTheme) btnTheme.setAttribute("title", "Cambiar a modo claro");
    }
    localStorage.setItem("ecowatts-theme", theme);
  }

  /* ─────────────────────────────────────────
     2. FILTRO REGIÓN → COMUNA (solo registro)
  ───────────────────────────────────────── */
  const selectRegion = document.getElementById("select-region");
  const selectComuna = document.getElementById("comuna_id");

  if (selectRegion && selectComuna) {
    // Guardar todas las opciones de comunas originales
    const todasOpciones = Array.from(selectComuna.querySelectorAll("option")).map(o => ({
      value: o.value,
      text:  o.textContent,
      region: o.getAttribute("data-region")
    }));

    selectRegion.addEventListener("change", () => {
      const regionElegida = selectRegion.value;
      // Limpiar y repoblar el select de comunas
      selectComuna.innerHTML = '<option value="">-- Selecciona tu comuna --</option>';

      const filtradas = todasOpciones.filter(o => o.region === regionElegida || regionElegida === "");
      filtradas.forEach(o => {
        const opt = document.createElement("option");
        opt.value = o.value;
        opt.textContent = o.text;
        opt.setAttribute("data-region", o.region);
        selectComuna.appendChild(opt);
      });

      // Si solo hay una opción, seleccionarla automáticamente
      if (filtradas.length === 1) {
        selectComuna.value = filtradas[0].value;
      }

      selectComuna.disabled = filtradas.length === 0;
    });

    // Disparar al cargar si ya hay región preseleccionada
    if (selectRegion.value) selectRegion.dispatchEvent(new Event("change"));
  }

  /* ─────────────────────────────────────────
     3. TOGGLE MOSTRAR / OCULTAR CONTRASEÑA
  ───────────────────────────────────────── */
  const toggleButtons = document.querySelectorAll(".toggle-password");

  toggleButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const targetId = btn.getAttribute("data-target");
      const input = document.getElementById(targetId);
      if (!input) return;

      if (input.type === "password") {
        input.type = "text";
        btn.setAttribute("title", "Ocultar contraseña");
        btn.innerHTML = `
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
            <line x1="1" y1="1" x2="23" y2="23"></line>
          </svg>`;
      } else {
        input.type = "password";
        btn.setAttribute("title", "Mostrar contraseña");
        btn.innerHTML = `
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          </svg>`;
      }
    });
  });

  /* ─────────────────────────────────────────
     4. VALIDACIÓN CONTRASEÑAS (registro)
  ───────────────────────────────────────── */
  const formRegistro = document.getElementById("form-registro");
  if (formRegistro) {
    formRegistro.addEventListener("submit", (e) => {
      const pass        = document.getElementById("pass");
      const confirmPass = document.getElementById("confirm_pass");
      const clientAlert = document.getElementById("client-alert");

      if (pass && confirmPass) {
        if (pass.value !== confirmPass.value) {
          e.preventDefault();
          mostrarAlertaCliente(clientAlert, "Las contraseñas no coinciden. Por favor verifícalas.");
          confirmPass.focus();
          return;
        }
        if (pass.value.length < 4) {
          e.preventDefault();
          mostrarAlertaCliente(clientAlert, "La contraseña debe tener al menos 4 caracteres.");
          pass.focus();
          return;
        }
      }

      // Validar que se haya elegido una comuna
      if (selectComuna && !selectComuna.value) {
        e.preventDefault();
        mostrarAlertaCliente(clientAlert, "Por favor selecciona tu región y comuna.");
        selectComuna.focus();
      }
    });
  }

  function mostrarAlertaCliente(alertEl, msg) {
    if (alertEl) {
      alertEl.textContent = msg;
      alertEl.style.display = "flex";
    } else {
      alert(msg);
    }
  }

});