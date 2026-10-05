/* Shared front-end helpers: API calls, escaping, formatting, toasts, modals and page chrome. */
"use strict";

const App = (() => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
  const isAdmin = document.body.dataset.role === "admin";

  /** Escape text for safe insertion into HTML. */
  function esc(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  /**
   * Call a JSON endpoint. Throws an Error carrying the server's message on failure.
   * @param {string} url
   * @param {{method?: string, body?: any, params?: Record<string, any>}} options
   */
  async function api(url, { method = "GET", body, params } = {}) {
    if (params) {
      const query = new URLSearchParams();
      Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== "") query.set(key, value);
      });
      const qs = query.toString();
      if (qs) url += (url.includes("?") ? "&" : "?") + qs;
    }
    const headers = { Accept: "application/json" };
    if (method !== "GET") {
      headers["X-CSRF-Token"] = csrfToken;
      headers["Content-Type"] = "application/json";
    }
    const response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: "same-origin",
    });
    let data = null;
    try {
      data = await response.json();
    } catch {
      /* non-JSON response */
    }
    if (response.status === 401) {
      window.location.href = "/index.php";
    }
    if (!response.ok) {
      throw new Error(data?.error || `Request failed (${response.status})`);
    }
    return data;
  }

  // ------------------------------------------------------------------ formatting

  const numberFormats = {};
  function fmt(value, digits = 0) {
    if (value === null || value === undefined || value === "" || Number.isNaN(Number(value))) return "–";
    numberFormats[digits] ??= new Intl.NumberFormat("en-US", {
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    });
    return numberFormats[digits].format(Number(value));
  }

  /** Signed number, e.g. +1.5 / −2.0 */
  function fmtSigned(value, digits = 1) {
    if (value === null || value === undefined) return "–";
    const n = Number(value);
    const abs = fmt(Math.abs(n), digits);
    if (Math.abs(n) < 0.5 * 10 ** -digits) return abs;
    return (n > 0 ? "+" : "−") + abs;
  }

  function fmtPct(value, digits = 0) {
    return value === null || value === undefined ? "–" : `${fmt(value, digits)}%`;
  }

  /** Minutes as "12 h 30 min". */
  function fmtMinutes(minutes) {
    const m = Math.round(Number(minutes) || 0);
    const h = Math.floor(m / 60);
    return h ? `${fmt(h)} h ${m % 60} min` : `${m} min`;
  }

  function fmtDate(value) {
    if (!value) return "–";
    const [y, mo, d] = value.split("-").map(Number);
    return new Date(y, mo - 1, d).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
  }

  const STATUS = {
    understaffed: { label: "Understaffed", icon: "fa-arrow-down" },
    overstaffed: { label: "Overstaffed", icon: "fa-arrow-up" },
    balanced: { label: "Balanced", icon: "fa-check" },
  };

  /** Status pill with icon + label (never color alone). */
  function statusBadge(status) {
    const s = STATUS[status] ?? STATUS.balanced;
    return `<span class="status-badge status-${status}"><i class="fa ${s.icon}" aria-hidden="true"></i>${s.label}</span>`;
  }

  function gapCell(gap, digits = 1) {
    const cls = gap <= -0.5 ? "gap-negative" : gap >= 0.5 ? "gap-positive" : "";
    return `<span class="${cls}">${fmtSigned(gap, digits)}</span>`;
  }

  function emptyState(message, icon = "fa-inbox") {
    return `<div class="empty-state"><i class="fa ${icon}" aria-hidden="true"></i>${esc(message)}</div>`;
  }

  // ------------------------------------------------------------------ DOM helpers

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  /**
   * Replace a <select>'s options.
   * @param {HTMLSelectElement} select
   * @param {Array} items
   * @param {{placeholder?: string, value?: any, label?: (item) => string, id?: (item) => any}} options
   */
  function fillSelect(select, items, { placeholder = null, value = null, label = (i) => i.name, id = (i) => i.id } = {}) {
    const options = placeholder !== null ? [`<option value="">${esc(placeholder)}</option>`] : [];
    items.forEach((item) => {
      const v = id(item);
      options.push(`<option value="${esc(v)}"${String(v) === String(value) ? " selected" : ""}>${esc(label(item))}</option>`);
    });
    select.innerHTML = options.join("");
  }

  function debounce(fn, wait = 250) {
    let timer;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), wait);
    };
  }

  // ------------------------------------------------------------------ feedback

  function toast(message, type = "success") {
    const stack = document.getElementById("toast-stack");
    if (!stack) return;
    const el = document.createElement("div");
    el.className = `app-toast ${type}`;
    el.setAttribute("role", type === "error" ? "alert" : "status");
    const icon = type === "error" ? "fa-circle-exclamation" : "fa-circle-check";
    el.innerHTML = `<i class="fa ${icon}" aria-hidden="true"></i><div>${esc(message)}</div>`;
    stack.appendChild(el);
    setTimeout(() => el.remove(), type === "error" ? 7000 : 3500);
  }

  /** Run an async action and report errors as a toast. Returns the action's result or undefined. */
  async function attempt(action, successMessage) {
    try {
      const result = await action();
      if (successMessage) toast(successMessage);
      return result;
    } catch (error) {
      toast(error.message, "error");
      return undefined;
    }
  }

  function createModal(html) {
    const wrapper = document.createElement("div");
    wrapper.className = "modal fade";
    wrapper.tabIndex = -1;
    wrapper.setAttribute("aria-modal", "true");
    wrapper.setAttribute("role", "dialog");
    wrapper.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">${html}</div></div>`;
    document.body.appendChild(wrapper);
    const modal = new bootstrap.Modal(wrapper);
    wrapper.addEventListener("hidden.bs.modal", () => {
      modal.dispose();
      wrapper.remove();
    });
    return { el: wrapper, modal };
  }

  /**
   * Confirmation dialog. Resolves true when confirmed.
   * @param {{title: string, message: string, confirmText?: string, danger?: boolean}} options
   */
  function confirm({ title, message, confirmText = "Confirm", danger = true }) {
    return new Promise((resolve) => {
      const { el, modal } = createModal(`
        <div class="modal-header"><h2 class="modal-title">${esc(title)}</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">${message}</div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn ${danger ? "btn-danger" : "btn-primary"}" data-confirm>${esc(confirmText)}</button>
        </div>`);
      let confirmed = false;
      el.querySelector("[data-confirm]").addEventListener("click", () => {
        confirmed = true;
        modal.hide();
      });
      el.addEventListener("hidden.bs.modal", () => resolve(confirmed));
      el.addEventListener("shown.bs.modal", () => el.querySelector("[data-confirm]").focus());
      modal.show();
    });
  }

  /**
   * Modal with a form. `onSubmit(formData, el)` may throw to keep the dialog open (the message is shown inline).
   * @param {{title: string, body: string, submitText?: string, size?: string, onOpen?: (el) => void, onSubmit: Function}} options
   */
  function formModal({ title, body, submitText = "Save", size = "", onOpen, onSubmit }) {
    const { el, modal } = createModal(`
      <form novalidate>
        <div class="modal-header"><h2 class="modal-title">${esc(title)}</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="alert alert-danger py-2 d-none" data-error role="alert"></div>
          ${body}
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">${esc(submitText)}</button>
        </div>
      </form>`);
    if (size) el.querySelector(".modal-dialog").classList.add(size);
    const form = el.querySelector("form");
    const errorBox = el.querySelector("[data-error]");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submit = form.querySelector('[type="submit"]');
      submit.disabled = true;
      errorBox.classList.add("d-none");
      try {
        await onSubmit(Object.fromEntries(new FormData(form)), el);
        modal.hide();
      } catch (error) {
        errorBox.textContent = error.message;
        errorBox.classList.remove("d-none");
      } finally {
        submit.disabled = false;
      }
    });
    el.addEventListener("shown.bs.modal", () => form.querySelector("input:not([type=hidden]), select, textarea")?.focus());
    onOpen?.(el);
    modal.show();
    return el;
  }

  // ------------------------------------------------------------------ page chrome

  function initChrome() {
    document.getElementById("spinner")?.classList.add("hidden");

    // Sidebar: collapsible on wide screens, an overlay drawer on narrow ones.
    const toggler = document.querySelector(".sidebar-toggler");
    const narrow = window.matchMedia("(max-width: 991.98px)");
    const sidebarOpen = () =>
      narrow.matches ? document.body.classList.contains("sidebar-open") : !document.body.classList.contains("sidebar-collapsed");
    const syncToggler = () => toggler?.setAttribute("aria-expanded", String(sidebarOpen()));
    toggler?.addEventListener("click", (event) => {
      event.stopPropagation();
      document.body.classList.toggle(narrow.matches ? "sidebar-open" : "sidebar-collapsed");
      syncToggler();
    });
    document.addEventListener("click", (event) => {
      if (narrow.matches && document.body.classList.contains("sidebar-open") && !event.target.closest(".sidebar")) {
        document.body.classList.remove("sidebar-open");
        syncToggler();
      }
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && document.body.classList.contains("sidebar-open")) {
        document.body.classList.remove("sidebar-open");
        syncToggler();
        toggler?.focus();
      }
    });
    narrow.addEventListener("change", syncToggler);
    syncToggler();

    const backToTop = document.querySelector(".back-to-top");
    if (backToTop) {
      const toggle = () => (backToTop.style.display = window.scrollY > 300 ? "inline-flex" : "none");
      window.addEventListener("scroll", toggle, { passive: true });
      toggle();
      backToTop.addEventListener("click", (event) => {
        event.preventDefault();
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    }

    document.getElementById("change-password")?.addEventListener("click", changePasswordModal);
  }

  /** Let the signed-in user change their own password. */
  function changePasswordModal() {
    formModal({
      title: "Change your password",
      submitText: "Change password",
      body: `<p>You stay signed in here; your other sessions are signed out.</p>
        <div class="mb-3"><label class="form-label" for="pw-current">Current password</label>
          <input id="pw-current" name="current_password" type="password" class="form-control" autocomplete="current-password" required></div>
        <div class="mb-3"><label class="form-label" for="pw-new">New password</label>
          <input id="pw-new" name="new_password" type="password" class="form-control" minlength="10" autocomplete="new-password" required>
          <div class="form-text">At least 10 characters.</div></div>
        <div><label class="form-label" for="pw-repeat">Repeat new password</label>
          <input id="pw-repeat" name="repeat_password" type="password" class="form-control" autocomplete="new-password" required></div>`,
      onSubmit: async (data) => {
        if (data.new_password !== data.repeat_password) throw new Error("The new passwords do not match.");
        await api("/app/api/account.php", {
          method: "POST",
          body: { current_password: data.current_password, new_password: data.new_password },
        });
        toast("Password changed.");
      },
    });
  }

  document.addEventListener("DOMContentLoaded", initChrome);

  return {
    isAdmin, esc, api, fmt, fmtSigned, fmtPct, fmtMinutes, fmtDate, statusBadge, gapCell, emptyState,
    $, $$, fillSelect, debounce, toast, attempt, confirm, formModal,
  };
})();
