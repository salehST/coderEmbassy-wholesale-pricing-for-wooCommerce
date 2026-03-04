/* =============================================================
   CoderEmbassy Wholesale Pricing — Admin SPA (Vanilla JS, no build step)
   Pages: Dashboard, Price Rules, CSV Import, Settings only.
   ============================================================= */

(() => {
'use strict';

const API   = (typeof CEWP !== 'undefined' && CEWP.rest_url) ? CEWP.rest_url : '';
const NONCE = (typeof CEWP !== 'undefined' && CEWP.nonce) ? CEWP.nonce : '';
const CUR   = (typeof CEWP !== 'undefined' && CEWP.currency) ? CEWP.currency : '';
const CURRENT_USER = (typeof CEWP !== 'undefined' && CEWP.current_user) ? CEWP.current_user : { display_name: 'Admin' };
const MAX_RULES = (typeof CEWP !== 'undefined' && typeof CEWP.max_rules !== 'undefined') ? CEWP.max_rules : 10;

// ── API helper ─────────────────────────────────────────────
async function api(path, method = 'GET', body = null) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
  };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(API + path, opts);
  const text = await res.text();
  if (!res.ok) {
    const err = new Error(text);
    try {
      err.payload = JSON.parse(text);
    } catch (_) {}
    throw err;
  }
  return text ? JSON.parse(text) : null;
}

// ── Root (toasts and modal append here) ────────────────────
const root = document.getElementById('cewp-root') || document.body;
const toastWrap = document.createElement('div');
toastWrap.className = 'dpp-toast-wrap';
document.body.appendChild(toastWrap);

function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `dpp-toast${type === 'error' ? ' error' : ''}`;
  el.textContent = msg;
  toastWrap.appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// ── SVG icons ──────────────────────────────────────────────
const icons = {
  dashboard: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>`,
  prices:    `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`,
  import:    `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5-5 5 5"/><path d="M12 5v14"/></svg>`,
  settings:  `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`,
  plus:      `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>`,
  edit:      `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
  trash:     `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>`,
  close:     `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>`,
  search:    `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>`,
  moon:      `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>`,
  sun:       `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>`,
};

// ── State ──────────────────────────────────────────────────
const state = {
  page:   'dashboard',
  prices: { rows: [], total: 0, page: 1, search: '', product_id: 0, product_name: '', scope_type: 'role', scope_value: '', price_type: '' },
  stats:  {},
  theme:  'light',
  settings: { delete_on_uninstall: false, catalog_mode: false, hide_retail_from_wholesale: false },
  roles:  [],
  importer: { file_name: '', csv: '', preview: [], result: null },
};

// ── Utilities ──────────────────────────────────────────────
function escHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  const a = parts[0]?.[0] || 'A';
  const b = parts[1]?.[0] || parts[0]?.[1] || '';
  return (a + b).toUpperCase();
}

// ── Theme ──────────────────────────────────────────────────
function loadTheme() {
  const saved = localStorage.getItem('cewp_theme');
  state.theme = saved === 'dark' ? 'dark' : 'light';
  if (root.dataset) root.dataset.theme = state.theme;
}

function toggleTheme() {
  state.theme = state.theme === 'dark' ? 'light' : 'dark';
  if (root.dataset) root.dataset.theme = state.theme;
  localStorage.setItem('cewp_theme', state.theme);
  render();
}

// ── Modal factory: one overlay at a time, no duplicate IDs ──
function createModal(title, body, onSave) {
  // Remove any existing modal so we never have duplicate IDs
  root.querySelectorAll('.dpp-overlay').forEach(el => el.remove());

  const ov = document.createElement('div');
  ov.className = 'dpp-overlay';
  ov.innerHTML = `
    <div class="dpp-modal">
      <div class="dpp-modal-header">
        <div class="dpp-modal-title">${title}</div>
        <button type="button" class="dpp-modal-close" aria-label="Close">${icons.close}</button>
      </div>
      <div class="dpp-modal-body">${body}</div>
      <div class="dpp-modal-footer">
        <button type="button" class="dpp-btn dpp-modal-cancel">Cancel</button>
        <button type="button" class="dpp-btn dpp-btn-primary dpp-modal-save">Save</button>
      </div>
    </div>
  `;
  root.appendChild(ov);

  const close = () => ov.remove();
  ov.querySelector('.dpp-modal-close').addEventListener('click', close);
  ov.querySelector('.dpp-modal-cancel').addEventListener('click', close);
  ov.addEventListener('click', e => { if (e.target === ov) close(); });

  const saveBtn = ov.querySelector('.dpp-modal-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<span class="dpp-spinner"></span>';
      const ok = await onSave();
      if (ok !== false) close();
      else { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
    });
  }
  return ov;
}

// ── Product autocomplete (inputId, listId, hiddenId; optional scopeElement, optional variationHiddenId) ──
function bindProductAC(inputId, listId, hiddenId, scopeElement, variationHiddenId) {
  const input = document.getElementById(inputId);
  const list  = document.getElementById(listId);
  const hidden = document.getElementById(hiddenId);
  const variationHidden = variationHiddenId ? document.getElementById(variationHiddenId) : null;
  if (!input || !list) return;

  input.addEventListener('input', debounce(async () => {
    const q = input.value.trim();
    if (!q) { list.style.display = 'none'; return; }
    const results = await api(`products/search?q=${encodeURIComponent(q)}`).catch(() => []);
    if (!results.length) { list.style.display = 'none'; return; }
    list.innerHTML = results.map(p => `
      <div class="dpp-ac-item" data-id="${p.id}" data-vid="${p.variation_id != null ? p.variation_id : 0}" data-name="${escHtml(p.name)}">
        <strong>${escHtml(p.name)}</strong>
        <small>SKU: ${escHtml(p.sku || '—')} | ${CUR}${parseFloat(p.price || 0).toFixed(2)}${p.variation_id ? ' (variation)' : ''}</small>
      </div>
    `).join('');
    list.style.display = 'block';
    list.querySelectorAll('.dpp-ac-item').forEach(el => {
      el.addEventListener('click', () => {
        input.value = el.dataset.name.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');
        if (hidden) hidden.value = el.dataset.id || '';
        if (variationHidden) variationHidden.value = el.dataset.vid || '0';
        list.style.display = 'none';
      });
    });
  }, 300));
  const clickTarget = scopeElement || document;
  clickTarget.addEventListener('click', function clickAway(e) {
    if (!input.contains(e.target) && !list.contains(e.target)) list.style.display = 'none';
  });
}

// ── Topbar ──────────────────────────────────────────────────
function renderTopbar() {
  const themeIcon = state.theme === 'dark' ? icons.sun : icons.moon;
  const showSearch = state.page === 'prices';
  return `
    <div class="dpp-topbar">
      ${showSearch ? `
      <div class="dpp-search" role="search" aria-label="Filter price rules">
        ${icons.search}
        <input id="dpp-global-search" placeholder="Search product or scope value…" value="${escHtml(state.prices.search)}">
      </div>
      ` : ''}
      <div class="dpp-top-actions">
        <button class="dpp-iconbtn" id="dpp-theme-toggle" title="Toggle theme" aria-label="Toggle theme">
          ${themeIcon}
        </button>
        <div class="dpp-userpill" title="${escHtml(CURRENT_USER.display_name)}">
          <div class="dpp-avatar">${initials(CURRENT_USER.display_name)}</div>
          <div class="name">${escHtml(CURRENT_USER.display_name)}</div>
        </div>
      </div>
    </div>
  `;
}

function bindTopbar() {
  document.getElementById('dpp-theme-toggle')?.addEventListener('click', toggleTheme);
  const g = document.getElementById('dpp-global-search');
  g?.addEventListener('input', debounce((e) => {
    if (state.page !== 'prices') return;
    state.prices.search = e.target.value;
    state.prices.page = 1;
    loadPrices();
  }, 250));
}

// ── Sidebar (only Dashboard, Price Rules, CSV Import, Settings) ──
function renderSidebar() {
  const nav = [
    { key: 'dashboard', icon: 'dashboard', label: 'Dashboard' },
    { key: 'prices',    icon: 'prices',    label: 'Price Rules' },
    { key: 'import',    icon: 'import',    label: 'CSV Import' },
    { key: 'settings', icon: 'settings',  label: 'Settings' },
  ];

  const logoUrl = (typeof CEWP !== 'undefined' && (state.theme === 'dark' ? CEWP.logo_dark : CEWP.logo_light)) || '';
  return `
    <aside class="dpp-sidenav">
      <div class="dpp-brand">
        ${logoUrl
          ? `<img src="${escHtml(logoUrl)}" alt="CoderEmbassy Wholesale Pricing" class="dpp-brand-logo" width="140" height="40">`
          : `<div class="dpp-brand-mark" aria-hidden="true"></div>
        <div>
          <div class="dpp-brand-title">CoderEmbassy Wholesale</div>
          <div class="dpp-brand-sub">Pricing</div>
        </div>`}
      </div>
      <nav class="dpp-nav" aria-label="Dealer Pricing navigation">
        ${nav.map(n => `
          <a class="${state.page === n.key ? 'active' : ''}" data-page="${n.key}">
            ${icons[n.icon]} <span>${n.label}</span>
          </a>
        `).join('')}
      </nav>
      <div class="dpp-sidenav-footer">
        <div>Theme: <strong>${state.theme === 'dark' ? 'Dark' : 'Light'}</strong></div>
      </div>
    </aside>
  `;
}

function bindNav() {
  document.querySelectorAll('.dpp-nav a[data-page]').forEach(el => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      state.page = el.dataset.page;
      render();
      loadPage();
    });
  });
}

// ── Main render ────────────────────────────────────────────
function render() {
  if (!root) return;
  root.innerHTML = `
    <div class="dpp-app">
      ${renderSidebar()}
      <section class="dpp-content">
        ${renderTopbar()}
        <main id="dpp-main">${renderPage()}</main>
      </section>
    </div>
  `;
  bindNav();
  bindTopbar();
}

// ── Page router ─────────────────────────────────────────────
function renderPage() {
  switch (state.page) {
    case 'dashboard': return renderDashboard();
    case 'prices':    return renderPricesPage();
    case 'import':    return renderImportPage();
    case 'settings':  return renderSettingsPage();
    default:          return renderDashboard();
  }
}

async function loadPage() {
  switch (state.page) {
    case 'dashboard': await loadDashboard(); break;
    case 'prices':    await loadPrices();    break;
    case 'import':    await loadImport();   break;
    case 'settings':  await loadSettings(); break;
  }
}

// ════════════════════════════════════════
// DASHBOARD
// ════════════════════════════════════════
function statCard(val, label) {
  return `<div class="dpp-stat"><div class="val">${val}</div><div class="lab">${label}</div></div>`;
}

function renderDashboard() {
  const s = state.stats;
  const rulesUsed = s.rules_used ?? s.total_rules ?? 0;
  const maxR = MAX_RULES;
  const upgradeUrl = (typeof CEWP !== 'undefined' && CEWP.upgrade_url) ? escHtml(CEWP.upgrade_url) : '#';
  return `
    <div class="dpp-grid">
      <div class="dpp-hero">
        <h2>CoderEmbassy Wholesale Pricing</h2>
        <p>
          Manage up to ${maxR} dealer price rules by role. Use the theme toggle in the top-right to switch between light and dark mode.
        </p>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
          <button class="dpp-btn dpp-btn-primary" id="dpp-cta-add">${icons.plus} Add a price rule</button>
          <button class="dpp-btn" id="dpp-cta-import">${icons.import} Import CSV</button>
        </div>
      </div>

      <div class="dpp-stats" id="dpp-stats">
        ${statCard(s.total_rules ?? '—', 'Price Rules')}
        ${statCard(s.total_products ?? '—', 'Products Priced')}
      </div>

      <div class="dpp-card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <div style="font-weight:900;font-size:14px">Rules limit</div>
            <div style="color:var(--muted);font-size:13px;margin-top:2px">${rulesUsed} of ${maxR} rules used</div>
          </div>
          
        </div>
      </div>
    </div>
  `;
}

async function loadDashboard() {
  try {
    const stats = await api('stats');
    state.stats = {
      total_rules:   stats.total_rules ?? 0,
      total_products: stats.total_products ?? 0,
      rules_used:    stats.rules_used ?? stats.total_rules ?? 0,
      rule_limit:   stats.rule_limit ?? MAX_RULES,
    };
    const statsEl = document.getElementById('dpp-stats');
    if (statsEl) {
      statsEl.innerHTML =
        statCard(state.stats.total_rules, 'Price Rules') +
        statCard(state.stats.total_products, 'Products Priced');
    }

    document.getElementById('dpp-cta-add')?.addEventListener('click', () => {
      state.page = 'prices';
      render();
      loadPage();
      setTimeout(() => openPriceModal(), 0);
    });
    document.getElementById('dpp-cta-import')?.addEventListener('click', () => {
      state.page = 'import';
      render();
      loadPage();
    });
  } catch (e) { /* ignore */ }
}

// ════════════════════════════════════════
// PRICE RULES (scope_type always "role", scope_value = role slug)
// ════════════════════════════════════════
function renderPricesPage() {
  const rulesUsed = state.stats.rules_used ?? state.stats.total_rules ?? 0;
  const ruleLimit = state.stats.rule_limit ?? MAX_RULES;
  const atLimit = rulesUsed >= ruleLimit;
  const overLimit = rulesUsed > ruleLimit;
  const upgradeUrl = (typeof CEWP !== 'undefined' && CEWP.upgrade_url) ? escHtml(CEWP.upgrade_url) : '#';
  return `
    <div class="dpp-pagehead">
      <div>
        <div class="dpp-h1">Price rules</div>
        <div class="dpp-sub">Custom prices per role</div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="dpp-btn dpp-btn-primary" id="dpp-add-price" ${atLimit ? 'disabled' : ''}>${icons.plus} Add rule</button>
      </div>
    </div>
    ${overLimit ? `
    <div class="dpp-card" style="border-left:4px solid var(--warning, #d97706);background:var(--bg-2);margin-bottom:16px">
      <div style="font-weight:700;margin-bottom:4px">You have ${rulesUsed} rules. Starter allows ${ruleLimit}.</div>
      <div style="color:var(--muted);font-size:14px">All your rules still apply on the storefront. To add new rules or edit the ones above the limit, <a href="${upgradeUrl}" target="_blank" rel="noopener" style="color:var(--primary)">upgrade to Business</a>.</div>
    </div>
    ` : ''}
    <div class="dpp-card">
      <div class="dpp-form-grid" style="align-items:end;margin-bottom:12px;gap:12px">
        <div class="dpp-form-group">
          <label class="dpp-label">Search</label>
          <input class="dpp-input" id="dpp-price-search" placeholder="Product or scope value…" value="${escHtml(state.prices.search)}">
        </div>
        <div class="dpp-form-group">
          <label class="dpp-label">Product</label>
          <div class="dpp-autocomplete">
            <input class="dpp-input" id="dpp-price-product-search" placeholder="Search product to filter…" value="${escHtml(state.prices.product_name || '')}">
            <input type="hidden" id="dpp-price-product-id" value="${state.prices.product_id || ''}">
            <div class="dpp-ac-list" id="dpp-price-product-list" style="display:none"></div>
          </div>
          <button type="button" class="dpp-btn dpp-btn-sm" id="dpp-price-product-clear" style="margin-top:4px;display:${state.prices.product_id ? 'inline-block' : 'none'}">Clear</button>
        </div>
        <div class="dpp-form-group">
          <label class="dpp-label">Scope type</label>
          <select class="dpp-select" id="dpp-price-scope-type" disabled>
            <option value="role" selected>Role</option>
          </select>
        </div>
        <div class="dpp-form-group">
          <label class="dpp-label">Role</label>
          <select class="dpp-select" id="dpp-price-scope-value">
            <option value="">Any</option>
            ${(state.roles || []).map(r => `<option value="${escHtml(r.role_key || r.slug)}" ${state.prices.scope_value === (r.role_key || r.slug) ? 'selected' : ''}>${escHtml(r.label || r.name)}</option>`).join('')}
          </select>
        </div>
        <div class="dpp-form-group">
          <label class="dpp-label">Price type</label>
          <select class="dpp-select" id="dpp-price-price-type">
            <option value="">Any</option>
            <option value="fixed" ${state.prices.price_type === 'fixed' ? 'selected' : ''}>Fixed</option>
            <option value="percent_discount" ${state.prices.price_type === 'percent_discount' ? 'selected' : ''}>Percent discount</option>
            <option value="fixed_discount" ${state.prices.price_type === 'fixed_discount' ? 'selected' : ''}>Fixed discount</option>
          </select>
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end;color:var(--muted);font-size:13px;font-weight:700;margin-bottom:10px" id="dpp-price-count"></div>
      <div id="dpp-prices-table"><div class="dpp-loading"><span class="dpp-spinner"></span> Loading…</div></div>
      <div style="margin-top:12px;display:flex;align-items:center;justify-content:flex-end;gap:10px" id="dpp-price-pagination"></div>
    </div>
  `;
}

async function loadPrices() {
  const { search, page: p, product_id, scope_type, scope_value, price_type } = state.prices;
  const params = new URLSearchParams({ page: p, per_page: 20 });
  if (search) params.set('search', search);
  if (product_id) params.set('product_id', product_id);
  params.set('scope_type', 'role');
  if (scope_value) params.set('scope_value', scope_value);
  if (price_type) params.set('price_type', price_type);

  try {
    state.roles = await api('roles').catch(() => []);
    if (state.stats.rule_limit == null) {
      const stats = await api('stats').catch(() => ({}));
      state.stats = { ...state.stats, rules_used: stats.rules_used ?? state.stats.rules_used, rule_limit: stats.rule_limit ?? MAX_RULES };
    }
    const data = await api(`prices?${params.toString()}`);
    state.prices.rows  = data.rows || [];
    state.prices.total = data.total || 0;

    document.getElementById('dpp-price-count').textContent = `${state.prices.total} rules`;

    const tbl = document.getElementById('dpp-prices-table');
    if (!tbl) return;
    if (!state.prices.rows.length) {
      tbl.innerHTML = `<div class="dpp-empty">
        ${icons.prices}
        <h3>No price rules yet</h3>
        <p>Click "Add Rule" to create your first dealer price.</p>
      </div>`;
    } else {
      const roleMap = {};
      (state.roles || []).forEach(r => { roleMap[r.role_key || r.slug] = r.label || r.name; });
      tbl.innerHTML = `
        <div class="dpp-table-wrap">
        <table class="dpp-table">
          <thead><tr>
            <th>Product</th><th>Applied to</th>
            <th>Price Type</th><th>Price</th><th>Min Qty</th><th></th>
          </tr></thead>
          <tbody>
            ${state.prices.rows.map(r => {
              const roleName = roleMap[r.scope_value] || r.scope_display_name || r.scope_value;
              const manageable = r.manageable !== false;
              const editTitle = manageable ? 'Edit' : 'Upgrade to Business to edit this rule';
              return `
              <tr>
                <td><strong>${escHtml(r.product_name || 'Product #' + r.product_id)}</strong>
                  ${r.variation_id > 0 ? `<br><small style="color:var(--muted)">Var #${r.variation_id}</small>` : ''}
                </td>
                <td>${escHtml(roleName)}</td>
                <td><span class="dpp-badge ${r.price_type === 'fixed' ? 'dpp-badge-fixed' : 'dpp-badge-pct'}">${escHtml(r.price_type)}</span></td>
                <td class="mono">
                  ${r.price_type === 'fixed' ? CUR + parseFloat(r.price_value).toFixed(2) :
                    r.price_type === 'percent_discount' ? r.price_value + '% off' :
                    CUR + r.price_value + ' off'}
                </td>
                <td>${r.min_qty}</td>
                <td>
                  <div style="display:flex;gap:6px">
                    <button class="dpp-btn dpp-btn-sm dpp-edit-price" data-id="${r.id}" data-manageable="${manageable ? '1' : '0'}" title="${escHtml(editTitle)}" ${manageable ? '' : 'disabled'}>${icons.edit}</button>
                    <button class="dpp-btn dpp-btn-danger dpp-btn-sm dpp-del-price" data-id="${r.id}">${icons.trash}</button>
                  </div>
                </td>
              </tr>
            `; }).join('')}
          </tbody>
        </table>
        </div>
      `;
    }

    const totalPages = Math.ceil(state.prices.total / 20) || 1;
    const pagEl = document.getElementById('dpp-price-pagination');
    if (pagEl) {
      pagEl.innerHTML = totalPages > 1 ? `
        <button class="dpp-btn dpp-btn-sm" id="dpp-prev" ${p <= 1 ? 'disabled' : ''}>← Prev</button>
        <span style="color:var(--muted);font-weight:700">Page ${p} of ${totalPages}</span>
        <button class="dpp-btn dpp-btn-sm" id="dpp-next" ${p >= totalPages ? 'disabled' : ''}>Next →</button>
      ` : '';
    }

    document.getElementById('dpp-add-price')?.addEventListener('click', () => openPriceModal());
    document.querySelectorAll('.dpp-edit-price').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.manageable === '0') {
        toast('Upgrade to Business to edit rules above the Starter limit.', 'error');
        return;
      }
      openPriceModal(b.dataset.id);
    }));
    document.querySelectorAll('.dpp-del-price').forEach(b => b.addEventListener('click', () => deletePrice(b.dataset.id)));
    const applyFilters = () => { state.prices.page = 1; loadPrices(); };
    document.getElementById('dpp-price-search')?.addEventListener('input', debounce(e => {
      state.prices.search = e.target.value;
      applyFilters();
    }, 350));
    document.getElementById('dpp-price-scope-value')?.addEventListener('change', e => {
      state.prices.scope_value = e.target.value;
      applyFilters();
    });
    document.getElementById('dpp-price-price-type')?.addEventListener('change', e => {
      state.prices.price_type = e.target.value;
      applyFilters();
    });
    document.getElementById('dpp-price-product-clear')?.addEventListener('click', () => {
      state.prices.product_id = 0;
      state.prices.product_name = '';
      const inp = document.getElementById('dpp-price-product-search');
      const hid = document.getElementById('dpp-price-product-id');
      if (inp) inp.value = '';
      if (hid) hid.value = '';
      document.getElementById('dpp-price-product-clear')?.style.setProperty('display', 'none');
      applyFilters();
    });
    bindProductAC('dpp-price-product-search', 'dpp-price-product-list', 'dpp-price-product-id');
    document.getElementById('dpp-price-product-list')?.addEventListener('click', () => {
      setTimeout(() => {
        const hid = document.getElementById('dpp-price-product-id');
        const inp = document.getElementById('dpp-price-product-search');
        state.prices.product_id = Number(hid?.value || 0) || 0;
        state.prices.product_name = (inp?.value || '').trim();
        document.getElementById('dpp-price-product-clear')?.style.setProperty('display', state.prices.product_id ? 'inline-block' : 'none');
        applyFilters();
      }, 0);
    });
    document.getElementById('dpp-prev')?.addEventListener('click', () => { state.prices.page--; loadPrices(); });
    document.getElementById('dpp-next')?.addEventListener('click', () => { state.prices.page++; loadPrices(); });
  } catch (e) {
    toast('Failed to load prices: ' + e.message, 'error');
  }
}

async function deletePrice(id) {
  if (!confirm('Delete this price rule?')) return;
  try {
    await api(`prices/${id}`, 'DELETE');
    toast('Price rule deleted');
    loadPrices();
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

async function openPriceModal(id = null) {
  let row = null;
  if (id) {
    try { row = await api(`prices/${id}`); } catch (e) {}
  }

  const roles = await api('roles').catch(() => []);
  const uid = 'pm-' + Date.now();

  const ov = createModal(id ? 'Edit Price Rule' : 'Add Price Rule', `
    <div id="pm-error-${uid}" class="dpp-form-error" role="alert" style="display:none"></div>
    <div class="dpp-form-grid">
      <div class="dpp-form-group full">
        <label class="dpp-label" for="pm-product-search-${uid}">Product *</label>
        <div class="dpp-autocomplete">
          <input class="dpp-input" id="pm-product-search-${uid}" placeholder="Search product or variation…"
            value="${row ? escHtml(row.product_name || '') : ''}" autocomplete="off">
          <input type="hidden" id="pm-product-id-${uid}" value="${row?.product_id || ''}">
          <input type="hidden" id="pm-variation-id-${uid}" value="${row?.variation_id ?? 0}">
          <div class="dpp-ac-list" id="pm-product-list-${uid}" style="display:none"></div>
        </div>
      </div>
      <div class="dpp-form-group">
        <label class="dpp-label" for="pm-scope-value-${uid}">Role *</label>
        <select class="dpp-select" id="pm-scope-value-${uid}">
          ${roles.map(r => `<option value="${escHtml(r.role_key || r.slug)}" ${row?.scope_value === (r.role_key || r.slug) ? 'selected' : ''}>${escHtml(r.label || r.name)}</option>`).join('')}
        </select>
      </div>
      <div class="dpp-form-group">
        <label class="dpp-label" for="pm-price-type-${uid}">Price Type *</label>
        <select class="dpp-select" id="pm-price-type-${uid}">
          <option value="fixed" ${row?.price_type === 'fixed' ? 'selected' : ''}>Fixed Price</option>
          <option value="percent_discount" ${row?.price_type === 'percent_discount' ? 'selected' : ''}>Percent Discount (%)</option>
          <option value="fixed_discount" ${row?.price_type === 'fixed_discount' ? 'selected' : ''}>Fixed Discount (amount off)</option>
        </select>
      </div>
      <div class="dpp-form-group">
        <label class="dpp-label" id="pm-price-label-${uid}" for="pm-price-value-${uid}">Price (${CUR}) *</label>
        <input class="dpp-input" id="pm-price-value-${uid}" type="number" step="0.01" min="0"
          value="${row?.price_value ?? ''}" placeholder="0.00">
      </div>
      <div class="dpp-form-group">
        <label class="dpp-label" for="pm-min-qty-${uid}">Minimum Quantity</label>
        <input class="dpp-input" id="pm-min-qty-${uid}" type="number" min="1" value="${row?.min_qty ?? 1}">
      </div>
    </div>
  `, async () => {
    const productId = document.getElementById('pm-product-id-' + uid)?.value;
    const variationId = document.getElementById('pm-variation-id-' + uid)?.value ?? 0;
    const scopeValue = document.getElementById('pm-scope-value-' + uid)?.value;
    const priceType = document.getElementById('pm-price-type-' + uid)?.value;
    const priceValue = document.getElementById('pm-price-value-' + uid)?.value;

    if (!productId) { toast('Please select a product', 'error'); return false; }
    if (!scopeValue) { toast('Please select a role', 'error'); return false; }
    if (!priceValue) { toast('Please enter a price value', 'error'); return false; }

    try {
      await api(id ? `prices/${id}` : 'prices', id ? 'PUT' : 'POST', {
        product_id: productId,
        variation_id: parseInt(variationId, 10) || 0,
        scope_type: 'role',
        scope_value: scopeValue,
        price_type: priceType,
        price_value: priceValue,
        min_qty: document.getElementById('pm-min-qty-' + uid)?.value || 1,
      });
      toast(id ? 'Rule updated!' : 'Rule created!');
      loadPrices();
      return true;
    } catch (e) {
      const msg = (e.payload && e.payload.message) ? e.payload.message : (e.message || '');
      const errEl = document.getElementById('pm-error-' + uid);
      if (errEl) {
        errEl.textContent = msg;
        errEl.style.display = msg ? 'block' : 'none';
      }
      if (msg.indexOf('limit') !== -1) toast('Rule limit reached. Upgrade for more rules.', 'error');
      else if (msg) toast(msg, 'error');
      else toast('Save failed', 'error');
      return false;
    }
  });

  const priceTypeEl = document.getElementById('pm-price-type-' + uid);
  if (priceTypeEl) {
    priceTypeEl.addEventListener('change', e => {
      const lbl = { fixed: `Price (${CUR})`, percent_discount: 'Discount (%)', fixed_discount: `Discount (${CUR})` };
      const labelEl = document.getElementById('pm-price-label-' + uid);
      if (labelEl) labelEl.textContent = (lbl[e.target.value] || 'Price') + ' *';
    });
  }
  bindProductAC('pm-product-search-' + uid, 'pm-product-list-' + uid, 'pm-product-id-' + uid, ov, 'pm-variation-id-' + uid);
}

// ════════════════════════════════════════
// CSV IMPORT (no Export, no bulk)
// ════════════════════════════════════════
function renderImportPage() {
  const p = state.importer.preview || [];
  const r = state.importer.result;
  return `
    <div class="dpp-pagehead">
      <div>
        <div class="dpp-h1">CSV import</div>
        <div class="dpp-sub">Import dealer pricing rules from a CSV file</div>
      </div>
    </div>
    <div class="dpp-card">
      <div style="font-weight:900">Upload CSV</div>
      <div style="color:var(--muted);margin-top:6px">
        Columns: product_id, variation_id (optional), scope_type (role), scope_value (role slug), price_type, price_value, min_qty.
      </div>
      <div style="margin-top:12px" class="dpp-form-grid">
        <div class="dpp-form-group full">
          <label class="dpp-label">CSV file *</label>
          <input class="dpp-input" id="dpp-import-file" type="file" accept=".csv,text/csv">
        </div>
        <div class="dpp-form-group">
          <label class="dpp-label">First row is header</label>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;color:var(--text);font-weight:700">
            <input type="checkbox" id="dpp-import-has-header" checked>
            <span>Yes</span>
          </label>
        </div>
      </div>
      <div style="margin-top:14px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
        <button class="dpp-btn dpp-btn-primary" id="dpp-import-run">${icons.import} Import</button>
      </div>
    </div>

    ${p.length ? `
      <div class="dpp-card">
        <div style="font-weight:900">Preview (first ${p.length} lines)</div>
        <div style="margin-top:10px" class="dpp-table-wrap">
          <table class="dpp-table">
            <thead><tr><th>Line</th><th>Content</th></tr></thead>
            <tbody>
              ${p.map(x => `<tr><td class="mono">${x.line}</td><td class="mono">${escHtml(x.text)}</td></tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>
    ` : ''}

    ${r ? `
      <div class="dpp-card">
        <div style="font-weight:900">Import result</div>
        <div style="color:var(--muted);margin-top:8px;line-height:1.8">
          Created: <strong style="color:var(--text)">${r.created ?? 0}</strong><br>
          Errors: <strong style="color:var(--text)">${(r.errors || []).length}</strong>
        </div>
        ${(r.errors || []).length ? `
          <div style="margin-top:10px" class="dpp-table-wrap">
            <table class="dpp-table">
              <thead><tr><th>Line</th><th>Error</th></tr></thead>
              <tbody>
                ${(r.errors || []).slice(0, 100).map(e => `<tr><td class="mono">${escHtml(e.line)}</td><td>${escHtml(e.message)}</td></tr>`).join('')}
              </tbody>
            </table>
          </div>
        ` : ''}
      </div>
    ` : ''}
  `;
}

async function loadImport() {
  const fileEl = document.getElementById('dpp-import-file');
  fileEl?.addEventListener('change', async () => {
    const f = fileEl.files?.[0];
    if (!f) return;
    const text = await f.text();
    state.importer.file_name = f.name;
    state.importer.csv = text;
    const lines = String(text || '').replace(/^\uFEFF/, '').split(/\r\n|\n|\r/).filter(Boolean);
    state.importer.preview = lines.slice(0, 10).map((t, i) => ({ line: i + 1, text: t.slice(0, 2000) }));
    state.importer.result = null;
    render();
    loadPage();
  });

  document.getElementById('dpp-import-run')?.addEventListener('click', async () => {
    const csv = state.importer.csv;
    if (!csv) return toast('Please choose a CSV file first', 'error');

    const has_header = !!document.getElementById('dpp-import-has-header')?.checked;
    try {
      const btn = document.getElementById('dpp-import-run');
      btn.disabled = true;
      btn.innerHTML = '<span class="dpp-spinner"></span> Importing…';
      const res = await api('import/csv', 'POST', { csv, has_header });
      state.importer.result = res;
      toast('Import complete');
      render();
      loadPage();
    } catch (e) {
      toast('Import failed: ' + e.message, 'error');
    } finally {
      const btn = document.getElementById('dpp-import-run');
      if (btn) { btn.disabled = false; btn.innerHTML = `${icons.import} Import`; }
    }
  });
}

// ════════════════════════════════════════
// SETTINGS
// ════════════════════════════════════════
function renderSettingsPage() {
  const s = state.settings;
  return `
    <div class="dpp-pagehead">
      <div>
        <div class="dpp-h1">Settings</div>
        <div class="dpp-sub">Plugin configuration</div>
      </div>
    </div>

    <div class="dpp-card">
      <div style="font-weight:900">General</div>
      <div style="margin-top:10px" class="dpp-form-grid">
        <div class="dpp-form-group full">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;color:var(--text);font-weight:700">
            <input type="checkbox" id="dpp-del-on-uninstall" ${s.delete_on_uninstall ? 'checked' : ''}>
            <span>Delete all plugin data when uninstalling</span>
          </label>
          <div style="color:var(--muted);font-size:13px;margin-top:6px">
            If enabled, uninstalling the plugin will drop plugin tables.
          </div>
        </div>
        <div class="dpp-form-group full">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;color:var(--text);font-weight:700">
            <input type="checkbox" id="dpp-catalog-mode" ${s.catalog_mode ? 'checked' : ''}>
            <span>Catalog mode (hide prices and Add to Cart from guests)</span>
          </label>
        </div>
        <div class="dpp-form-group full">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;color:var(--text);font-weight:700">
            <input type="checkbox" id="dpp-hide-retail-from-wholesale" ${s.hide_retail_from_wholesale ? 'checked' : ''}>
            <span>Hide retail-only products from wholesale customers</span>
          </label>
        </div>
      </div>
      <div id="dpp-settings-msg" class="dpp-settings-saved-msg" role="status" aria-live="polite" style="display:none"></div>
      <div style="margin-top:14px;display:flex;gap:10px;justify-content:flex-end;align-items:center">
        <button class="dpp-btn dpp-btn-primary" id="cewp-save-settings">Save settings</button>
      </div>
    </div>
  `;
}

async function loadSettings() {
  try {
    const s = await api('settings').catch(() => ({}));
    state.settings = {
      delete_on_uninstall: !!s.delete_on_uninstall,
      catalog_mode: !!s.catalog_mode,
      hide_retail_from_wholesale: !!s.hide_retail_from_wholesale,
    };
    const cbDel = document.getElementById('dpp-del-on-uninstall');
    const cbCat = document.getElementById('dpp-catalog-mode');
    const cbHide = document.getElementById('dpp-hide-retail-from-wholesale');
    if (cbDel) cbDel.checked = state.settings.delete_on_uninstall;
    if (cbCat) cbCat.checked = state.settings.catalog_mode;
    if (cbHide) cbHide.checked = state.settings.hide_retail_from_wholesale;
  } catch (e) {
    toast('Failed to load settings', 'error');
  }
}

async function saveSettings() {
  const msgEl = document.getElementById('dpp-settings-msg');
  const btn = document.getElementById('cewp-save-settings');
  if (msgEl) { msgEl.style.display = 'block'; msgEl.textContent = 'Saving…'; msgEl.classList.remove('error'); }
  if (btn) { btn.disabled = true; }
  try {
    await api('settings', 'POST', {
      delete_on_uninstall: !!document.getElementById('dpp-del-on-uninstall')?.checked,
      catalog_mode: !!document.getElementById('dpp-catalog-mode')?.checked,
      hide_retail_from_wholesale: !!document.getElementById('dpp-hide-retail-from-wholesale')?.checked,
    });
    if (msgEl) {
      msgEl.textContent = 'Settings saved successfully.';
      msgEl.classList.remove('error');
      setTimeout(() => { msgEl.style.display = 'none'; }, 5000);
    }
  } catch (e) {
    toast('Save failed: ' + e.message, 'error');
    if (msgEl) {
      msgEl.textContent = 'Save failed: ' + (e.message || 'Unknown error');
      msgEl.classList.add('error');
    }
  } finally {
    if (btn) btn.disabled = false;
  }
}

// ── Init ────────────────────────────────────────────────────
loadTheme();
render();
loadPage();

// Delegated click so Save settings works even before loadSettings() finishes
(document.getElementById('cewp-root') || document.body).addEventListener('click', function(e) {
  if (e.target.id === 'cewp-save-settings' || e.target.closest('#cewp-save-settings')) {
    e.preventDefault();
    saveSettings();
  }
});

})();
