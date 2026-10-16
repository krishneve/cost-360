/**
 * Cost360 - Uploaded Data Reports Frontend Controller
 *
 * Provides a unified, database-driven reporting interface across all 11 canonical datasets.
 * Strictly adheres to role-based access control, customer isolation, company allocation,
 * and zero mock data requirements.
 */

(function () {
  'use strict';

  // Global Controller State
  const state = {
    view: 'overview', // 'overview' | 'summary' | 'details'
    datasetKey: '',
    datasetDef: null,
    catalogue: [],
    overallSummary: null,
    userContext: null,
    activeCompanyCode: '',
    activePeriodId: '',
    activeCategory: 'ALL',
    searchQuery: '',
    detailsSearch: '',
    page: 1,
    limit: 25,
    sortCol: '',
    sortDir: 'ASC',
    columnFilters: {},
    startDate: '',
    endDate: '',
    isLoading: false,
    searchDebounceTimer: null
  };

  const API_URL = 'api/reports_api.php';

  // Helper: Format Number with commas
  function formatNumber(num) {
    if (num === null || num === undefined || isNaN(num)) return '0';
    return Number(num).toLocaleString('en-US');
  }

  // Helper: Format Currency / Amount
  function formatAmount(num) {
    if (num === null || num === undefined || isNaN(num)) return '0.00';
    return Number(num).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // Helper: Escape HTML to avoid XSS
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Helper: Show Error Alert Banner
  function showErrorBanner(message) {
    const banner = document.getElementById('repErrorBanner');
    const msgEl = document.getElementById('repErrorMessage');
    if (banner && msgEl) {
      msgEl.textContent = message || 'An unexpected error occurred while communicating with the server.';
      banner.style.display = 'flex';
      banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  // Helper: Hide Error Alert Banner
  function hideErrorBanner() {
    const banner = document.getElementById('repErrorBanner');
    if (banner) banner.style.display = 'none';
  }

  // Helper: Display Access Denied View
  function showAccessDenied(message) {
    const viewContainer = document.getElementById('repViewContainer');
    if (viewContainer) {
      viewContainer.innerHTML = `
        <div class="section-card" style="text-align: center; padding: 3rem 1.5rem; background: #fff; border-radius: 12px; border: 1px solid var(--color-border); box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
          <div style="font-size: 3rem; margin-bottom: 1rem;">🔒</div>
          <h2 style="color: #EF4444; font-size: 1.5rem; margin-bottom: 0.75rem; font-weight: 700;">Access Denied</h2>
          <p style="color: var(--color-secondary-text); font-size: 0.95rem; max-width: 500px; margin: 0 auto 1.5rem auto; line-height: 1.6;">
            ${escapeHtml(message || 'You are not authorized to view or access this data under current tenant and company allocation rules.')}
          </p>
          <button type="button" class="btn-primary" onclick="window.Cost360Reports.switchView('overview')" style="padding: 0.6rem 1.25rem;">
            Return to Reports Overview
          </button>
        </div>
      `;
    }
  }

  // API Call Wrapper with JSON parsing and 401/403 security handling
  async function apiCall(action, params = {}, method = 'GET') {
    hideErrorBanner();
    const queryParams = new URLSearchParams();
    queryParams.append('action', action);

    if (state.activeCompanyCode) {
      queryParams.append('company_code', state.activeCompanyCode);
    }
    if (state.activePeriodId) {
      queryParams.append('period_id', state.activePeriodId);
    }

    let url = `${API_URL}?${queryParams.toString()}`;
    let fetchOptions = {
      method: method,
      headers: {
        'Accept': 'application/json'
      }
    };

    if (method === 'GET' && Object.keys(params).length > 0) {
      const extraParams = new URLSearchParams(params);
      url += `&${extraParams.toString()}`;
    } else if (method === 'POST') {
      const formData = new FormData();
      for (const [key, val] of Object.entries(params)) {
        if (val !== null && val !== undefined) {
          formData.append(key, val);
        }
      }
      fetchOptions.body = formData;
    }

    try {
      const response = await fetch(url, fetchOptions);

      if (response.status === 401) {
        showAccessDenied('Your session has expired or is unauthorized. Please log in to Cost360.');
        throw new Error('Unauthorized');
      }

      if (response.status === 403) {
        const errorData = await response.json().catch(() => ({}));
        showAccessDenied(errorData.message || 'Access Denied: You are not authorized to perform this action.');
        throw new Error(errorData.message || 'Forbidden');
      }

      const data = await response.json();
      if (!data.ok) {
        throw new Error(data.message || 'Server returned an error status.');
      }
      return data;
    } catch (err) {
      if (err.message !== 'Unauthorized' && err.message !== 'Forbidden') {
        showErrorBanner(err.message);
      }
      throw err;
    }
  }

  // Initialize Module
  async function init() {
    try {
      // 1. Fetch User Security Context (Customer, Allocated Companies, Accessible Periods, Permissions)
      const userCtx = await apiCall('user_context');
      state.userContext = userCtx;

      // Populate Tenant & User Header Badges
      const custBadge = document.getElementById('repActiveCustomerBadge');
      if (custBadge && userCtx.customer) {
        custBadge.textContent = `${userCtx.customer.customer_name} (${userCtx.customer.customer_code})`;
      }

      // Populate Company Selector strictly from backend allocated companies
      const compSelect = document.getElementById('repCompanyFilter');
      if (compSelect) {
        compSelect.innerHTML = '';
        const companies = userCtx.companies || [];

        if (companies.length === 0) {
          compSelect.innerHTML = '<option value="">No Allocated Companies</option>';
          compSelect.disabled = true;
        } else if (companies.length === 1) {
          const c = companies[0];
          compSelect.innerHTML = `<option value="${escapeHtml(c.company_code)}" selected>${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`;
          state.activeCompanyCode = c.company_code;
          compSelect.disabled = true;
          compSelect.title = 'User has access to exactly one company';
        } else {
          compSelect.innerHTML = '<option value="">All Allocated Companies</option>';
          companies.forEach(c => {
            compSelect.innerHTML += `<option value="${escapeHtml(c.company_code)}">${escapeHtml(c.company_name)} (${escapeHtml(c.company_code)})</option>`;
          });
        }
      }

      // Populate Period Selector from accessible periods
      const periodSelect = document.getElementById('repPeriodFilter');
      if (periodSelect) {
        periodSelect.innerHTML = '<option value="">All Accounting Periods</option>';
        const periods = userCtx.periods || [];
        periods.forEach(p => {
          periodSelect.innerHTML += `<option value="${p.period_id}">${escapeHtml(p.period_name)}${p.is_locked ? ' [LOCKED]' : ''}</option>`;
        });
      }

      // Configure Export Visibility based on RBAC permissions
      const canExport = userCtx.permissions && userCtx.permissions.can_export;
      const exportBtns = document.querySelectorAll('.rep-export-btn');
      exportBtns.forEach(btn => {
        btn.style.display = canExport ? 'inline-flex' : 'none';
      });

      // Bind Listeners
      bindGlobalEvents();

      // Parse URL Hash or initial route
      handleHashNavigation();

    } catch (err) {
      console.error('Reports initialization error:', err);
    }
  }

  // Bind Global Filter & Navigation Events
  function bindGlobalEvents() {
    // Company Filter Change
    const compSelect = document.getElementById('repCompanyFilter');
    if (compSelect) {
      compSelect.addEventListener('change', function () {
        state.activeCompanyCode = this.value.trim();
        state.page = 1;
        refreshActiveView();
      });
    }

    // Period Filter Change
    const periodSelect = document.getElementById('repPeriodFilter');
    if (periodSelect) {
      periodSelect.addEventListener('change', function () {
        state.activePeriodId = this.value.trim();
        state.page = 1;
        refreshActiveView();
      });
    }

    // Refresh Button
    const refreshBtn = document.getElementById('repRefreshBtn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        refreshActiveView(true);
      });
    }

    // Hashchange listener for smooth browser back/forward support
    window.addEventListener('hashchange', handleHashNavigation);
  }

  // Handle URL Hash Navigation
  function handleHashNavigation() {
    const hash = window.location.hash.replace(/^#/, '');
    if (hash.startsWith('details/')) {
      const key = hash.replace('details/', '');
      switchView('details', key);
    } else if (hash.startsWith('summary/')) {
      const key = hash.replace('summary/', '');
      switchView('summary', key);
    } else {
      switchView('overview');
    }
  }

  // Switch between Overview, Summary, and Details
  async function switchView(targetView, datasetKey = '') {
    state.view = targetView;
    if (datasetKey) {
      state.datasetKey = datasetKey;
    }

    // Update Breadcrumbs & Switcher Buttons
    updateBreadcrumbs();

    // Render loading state
    const viewContainer = document.getElementById('repViewContainer');
    if (viewContainer) {
      viewContainer.innerHTML = `
        <div style="padding: 4rem 1rem; text-align: center; color: var(--color-royal-blue);">
          <div class="c360-spinner" style="width: 42px; height: 42px; border: 4px solid rgba(8, 120, 209, 0.2); border-top-color: var(--color-royal-blue); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 1rem auto;"></div>
          <p style="font-size: 0.95rem; font-weight: 600; color: var(--color-primary-text);">Querying live database records...</p>
        </div>
      `;
    }

    if (targetView === 'overview') {
      window.location.hash = 'overview';
      await loadOverview();
    } else if (targetView === 'summary') {
      window.location.hash = `summary/${state.datasetKey}`;
      await loadDatasetSummary();
    } else if (targetView === 'details') {
      window.location.hash = `details/${state.datasetKey}`;
      await loadDatasetDetails();
    }
  }

  // Refresh currently active view
  async function refreshActiveView(userInitiated = false) {
    if (state.view === 'overview') {
      await loadOverview();
    } else if (state.view === 'summary') {
      await loadDatasetSummary();
    } else if (state.view === 'details') {
      await loadDatasetDetails();
    }
  }

  // Update Breadcrumbs
  function updateBreadcrumbs() {
    const breadcrumbEl = document.getElementById('repBreadcrumbNav');
    if (!breadcrumbEl) return;

    if (state.view === 'overview') {
      breadcrumbEl.innerHTML = `
        <span style="color: #fff; font-weight: 700;">📊 Catalogue Overview</span>
      `;
    } else if (state.view === 'summary') {
      const def = state.datasetDef || state.catalogue.find(d => d.dataset_key === state.datasetKey);
      const name = def ? def.display_name : state.datasetKey;
      breadcrumbEl.innerHTML = `
        <a href="javascript:void(0);" onclick="window.Cost360Reports.switchView('overview')" style="color: rgba(255,255,255,0.75); text-decoration: none;">📊 Catalogue Overview</a>
        <span style="color: rgba(255,255,255,0.4); margin: 0 0.5rem;">/</span>
        <span style="color: #fff; font-weight: 700;">📈 ${escapeHtml(name)} Summary</span>
      `;
    } else if (state.view === 'details') {
      const def = state.datasetDef || state.catalogue.find(d => d.dataset_key === state.datasetKey);
      const name = def ? def.display_name : state.datasetKey;
      breadcrumbEl.innerHTML = `
        <a href="javascript:void(0);" onclick="window.Cost360Reports.switchView('overview')" style="color: rgba(255,255,255,0.75); text-decoration: none;">📊 Catalogue Overview</a>
        <span style="color: rgba(255,255,255,0.4); margin: 0 0.5rem;">/</span>
        <a href="javascript:void(0);" onclick="window.Cost360Reports.switchView('summary', '${escapeHtml(state.datasetKey)}')" style="color: rgba(255,255,255,0.75); text-decoration: none;">📈 Summary</a>
        <span style="color: rgba(255,255,255,0.4); margin: 0 0.5rem;">/</span>
        <span style="color: #fff; font-weight: 700;">📄 ${escapeHtml(name)} Records</span>
      `;
    }
  }

  /* =========================================================================
     VIEW MODE 1: OVERVIEW & CATALOGUE
     ========================================================================= */
  async function loadOverview() {
    try {
      const [overallRes, catRes] = await Promise.all([
        apiCall('overall_summary'),
        apiCall('catalogue')
      ]);

      state.overallSummary = overallRes;
      state.catalogue = catRes.datasets || [];

      renderOverviewView(overallRes, catRes);
    } catch (err) {
      console.error('Failed to load overview data:', err);
    }
  }

  function renderOverviewView(summary, cat) {
    const viewContainer = document.getElementById('repViewContainer');
    if (!viewContainer) return;

    const batches = summary.batch_statistics || {};
    const records = summary.customer_records || {};

    const availableCount = cat.available_datasets_count || 0;
    const totalDatasets = cat.total_registered_datasets || 11;
    const totalRecords = cat.total_records_accessible || records.total_records_all || 0;
    const totalBatches = batches.total_batches || 0;
    const errorRecords = batches.total_errors_recorded || 0;

    // Filter categories count
    const categories = ['ALL', 'Master Data', 'Standard Rates & Engineering', 'Operational & Inventory', 'Financial & Reconciliation'];
    const countsByCategory = { 'ALL': state.catalogue.length };
    state.catalogue.forEach(d => {
      countsByCategory[d.category] = (countsByCategory[d.category] || 0) + 1;
    });

    viewContainer.innerHTML = `
      <!-- KPI STAT CARDS ROW -->
      <section class="grid-cards" style="margin-bottom: 1.5rem;">
        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Available Datasets</span>
            <span style="font-size: 1.25rem;">📦</span>
          </div>
          <div class="stat-number" style="color: var(--color-royal-blue);">${availableCount} <span style="font-size: 1rem; color: var(--color-secondary-text); font-weight: 400;">/ ${totalDatasets}</span></div>
          <div class="stat-desc">Canonical upload tables populated</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Total Records Accessible</span>
            <span style="font-size: 1.25rem;">💾</span>
          </div>
          <div class="stat-number" style="color: #033665;">${formatNumber(totalRecords)}</div>
          <div class="stat-desc">Within active authorized scope</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Upload Batches</span>
            <span style="font-size: 1.25rem;">📑</span>
          </div>
          <div class="stat-number" style="color: var(--color-bright-cyan);">${formatNumber(totalBatches)}</div>
          <div class="stat-desc">Recorded ingestion batches</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Data Quality &amp; Errors</span>
            <span style="font-size: 1.25rem;">🛡️</span>
          </div>
          <div class="stat-number" style="color: ${errorRecords > 0 ? '#EF4444' : '#10B981'};">${formatNumber(errorRecords)}</div>
          <div class="stat-desc">${errorRecords === 0 ? 'Clean import status' : 'Exceptions flagged during import'}</div>
        </div>
      </section>

      <!-- CATALOGUE TOOLBAR -->
      <section class="section-card" style="margin-bottom: 1.25rem; padding: 1rem 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
          
          <!-- Category Tabs -->
          <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;" id="repCategoryTabs">
            ${categories.map(catName => {
              const activeClass = state.activeCategory === catName ? 'background: #033665; color: #fff; font-weight: 700;' : 'background: #F1F5F9; color: var(--color-primary-text);';
              const label = catName === 'ALL' ? 'All Datasets' : catName;
              const cnt = countsByCategory[catName] || 0;
              return `
                <button type="button" class="btn-secondary rep-cat-tab" data-cat="${escapeHtml(catName)}" 
                  style="border-radius: 20px; padding: 0.35rem 0.85rem; font-size: 0.82rem; border: none; cursor: pointer; transition: all 0.2s; ${activeClass}">
                  ${escapeHtml(label)} <span style="opacity: 0.75; font-size: 0.75rem;">(${cnt})</span>
                </button>
              `;
            }).join('')}
          </div>

          <!-- Live Catalogue Filter Input -->
          <div style="min-width: 240px; position: relative;">
            <input type="text" id="repCatalogueSearch" placeholder="🔍 Quick-filter datasets..." 
              value="${escapeHtml(state.searchQuery)}"
              class="cost360-select" style="width: 100%; padding: 0.45rem 0.85rem; font-size: 0.85rem; border-radius: 8px; border: 1px solid var(--color-border);" />
          </div>

        </div>
      </section>

      <!-- DATASET CARDS GRID -->
      <section id="repDatasetGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem;">
        <!-- Filled dynamically -->
      </section>
    `;

    // Render Cards
    renderDatasetCards();

    // Bind Category Filter Tabs
    const catButtons = viewContainer.querySelectorAll('.rep-cat-tab');
    catButtons.forEach(btn => {
      btn.addEventListener('click', function () {
        state.activeCategory = this.dataset.cat;
        catButtons.forEach(b => {
          b.style.background = '#F1F5F9';
          b.style.color = 'var(--color-primary-text)';
          b.style.fontWeight = 'normal';
        });
        this.style.background = '#033665';
        this.style.color = '#fff';
        this.style.fontWeight = '700';
        renderDatasetCards();
      });
    });

    // Bind Search Input
    const searchInput = document.getElementById('repCatalogueSearch');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        state.searchQuery = this.value.trim().toLowerCase();
        renderDatasetCards();
      });
    }
  }

  function renderDatasetCards() {
    const grid = document.getElementById('repDatasetGrid');
    if (!grid) return;

    let filtered = state.catalogue;

    // Filter by Category
    if (state.activeCategory !== 'ALL') {
      filtered = filtered.filter(d => d.category === state.activeCategory);
    }

    // Filter by Quick Search
    if (state.searchQuery) {
      filtered = filtered.filter(d => 
        (d.display_name && d.display_name.toLowerCase().includes(state.searchQuery)) ||
        (d.dataset_key && d.dataset_key.toLowerCase().includes(state.searchQuery)) ||
        (d.target_table && d.target_table.toLowerCase().includes(state.searchQuery)) ||
        (d.description && d.description.toLowerCase().includes(state.searchQuery))
      );
    }

    if (filtered.length === 0) {
      grid.innerHTML = `
        <div style="grid-column: 1 / -1; padding: 3rem 1.5rem; text-align: center; background: #fff; border-radius: 12px; border: 1px dashed var(--color-border);">
          <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔍</div>
          <p style="color: var(--color-secondary-text); font-size: 0.95rem; margin: 0;">No datasets match your search criteria or category filter.</p>
        </div>
      `;
      return;
    }

    grid.innerHTML = filtered.map(d => {
      const isPopulated = (d.record_count || 0) > 0;
      const countLabel = formatNumber(d.record_count || 0);
      const scopedCount = d.scoped_record_count !== null ? d.scoped_record_count : d.record_count;
      const isScoped = state.activeCompanyCode || state.activePeriodId;

      return `
        <div class="section-card" style="display: flex; flex-direction: column; justify-content: space-between; border: 1px solid var(--color-border); border-radius: 12px; padding: 1.25rem; transition: transform 0.2s, box-shadow 0.2s; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.04);">
          <div>
            <!-- Top Badges -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
              <span class="c360-badge" style="background: rgba(3, 54, 101, 0.08); color: #033665; font-size: 0.72rem; font-weight: 700; font-family: monospace;">
                ${escapeHtml(d.dataset_key)}
              </span>
              <span class="c360-badge" style="background: ${isPopulated ? 'rgba(16, 185, 129, 0.12)' : 'rgba(100, 116, 139, 0.12)'}; color: ${isPopulated ? '#10B981' : '#64748B'}; font-size: 0.72rem; font-weight: 600;">
                ${isPopulated ? '● Active in DB' : '○ Empty Table'}
              </span>
            </div>

            <!-- Title & Target Table -->
            <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--color-primary-text); margin: 0 0 0.35rem 0; line-height: 1.3;">
              ${escapeHtml(d.display_name)}
            </h3>
            <div style="font-size: 0.75rem; color: var(--color-secondary-text); margin-bottom: 0.65rem;">
              Target: <code style="background: #F1F5F9; padding: 0.15rem 0.35rem; border-radius: 4px; color: #033665;">public.${escapeHtml(d.target_table)}</code>
            </div>

            <!-- Description -->
            <p style="font-size: 0.82rem; color: var(--color-secondary-text); line-height: 1.45; margin: 0 0 1rem 0; min-height: 48px;">
              ${escapeHtml(d.description || 'No description provided.')}
            </p>
          </div>

          <!-- Bottom Metrics & Actions -->
          <div>
            <div style="padding: 0.65rem 0.85rem; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
              <div>
                <div style="font-size: 0.7rem; color: var(--color-secondary-text); text-transform: uppercase; font-weight: 700;">Records</div>
                <div style="font-size: 1.15rem; font-weight: 700; color: #033665;">
                  ${formatNumber(scopedCount)}
                  ${isScoped ? `<span style="font-size:0.72rem; color:var(--color-secondary-text); font-weight:400;">(of ${countLabel})</span>` : ''}
                </div>
              </div>
              <div style="text-align: right;">
                <span class="c360-badge" style="background: #EAF5FC; color: var(--color-royal-blue); font-size: 0.72rem;">
                  ${escapeHtml(d.category || 'General')}
                </span>
              </div>
            </div>

            <div style="display: flex; gap: 0.5rem;">
              <button type="button" class="btn-secondary" onclick="window.Cost360Reports.switchView('summary', '${escapeHtml(d.dataset_key)}')"
                style="flex: 1; padding: 0.45rem 0.5rem; font-size: 0.82rem; text-align: center; justify-content: center;">
                📈 Summary
              </button>
              <button type="button" class="btn-primary" onclick="window.Cost360Reports.switchView('details', '${escapeHtml(d.dataset_key)}')"
                style="flex: 1; padding: 0.45rem 0.5rem; font-size: 0.82rem; text-align: center; justify-content: center;">
                📄 View Details
              </button>
            </div>
          </div>
        </div>
      `;
    }).join('');
  }

  /* =========================================================================
     VIEW MODE 2: DATASET SUMMARY
     ========================================================================= */
  async function loadDatasetSummary() {
    try {
      const summaryRes = await apiCall('dataset_summary', { dataset: state.datasetKey });
      state.datasetDef = summaryRes.dataset_definition || {};
      renderSummaryView(summaryRes);
    } catch (err) {
      console.error('Failed to load dataset summary:', err);
    }
  }

  function renderSummaryView(data) {
    const viewContainer = document.getElementById('repViewContainer');
    if (!viewContainer) return;

    const def = data.dataset_definition || {};
    const metrics = data.metrics || {};
    const totalRecords = metrics.total_records !== undefined ? metrics.total_records : (data.record_count || 0);
    const canExport = state.userContext && state.userContext.permissions && state.userContext.permissions.can_export;

    // Build metric cards dynamically depending on dataset return structure
    const metricCardsHtml = generateDatasetMetricCards(state.datasetKey, metrics);

    viewContainer.innerHTML = `
      <!-- SUMMARY HEADER BANNER -->
      <section class="section-card" style="margin-bottom: 1.5rem; background: #fff; border: 1px solid var(--color-border); border-radius: 12px; padding: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
          <div>
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
              <button type="button" class="btn-secondary" onclick="window.Cost360Reports.switchView('overview')" style="padding: 0.25rem 0.65rem; font-size: 0.78rem;">
                ← Back to Catalogue
              </button>
              <span class="c360-badge" style="background: rgba(3, 54, 101, 0.08); color: #033665; font-size: 0.75rem; font-family: monospace; font-weight: 700;">
                ${escapeHtml(def.dataset_key || state.datasetKey)}
              </span>
              <span class="c360-badge" style="background: #EAF5FC; color: var(--color-royal-blue); font-size: 0.75rem;">
                ${escapeHtml(def.category || 'General')}
              </span>
            </div>
            <h2 style="font-size: 1.6rem; font-weight: 700; color: var(--color-primary-text); margin: 0 0 0.35rem 0;">
              ${escapeHtml(def.display_name || state.datasetKey)}
            </h2>
            <div style="font-size: 0.85rem; color: var(--color-secondary-text);">
              Target Database Table: <strong style="color: #033665;">public.${escapeHtml(def.target_table || '')}</strong> &bull;
              Business Key: <code>${(def.business_key || []).join(', ')}</code>
            </div>
          </div>

          <div style="display: flex; gap: 0.5rem; align-items: center;">
            ${canExport ? `
              <button type="button" class="btn-secondary rep-export-btn" onclick="window.Cost360Reports.exportCsv('${escapeHtml(state.datasetKey)}')"
                style="padding: 0.55rem 1rem; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                📥 Export Dataset CSV
              </button>
            ` : ''}
            <button type="button" class="btn-primary" onclick="window.Cost360Reports.switchView('details', '${escapeHtml(state.datasetKey)}')"
              style="padding: 0.55rem 1.25rem; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.35rem;">
              📄 View Detailed Records
            </button>
          </div>
        </div>
      </section>

      <!-- DYNAMIC BUSINESS METRICS GRID -->
      <section class="grid-cards" style="margin-bottom: 1.5rem;">
        <div class="stat-card">
          <div class="stat-header">
            <span class="stat-label">Total Records</span>
            <span style="font-size: 1.25rem;">📊</span>
          </div>
          <div class="stat-number" style="color: #033665;">${formatNumber(totalRecords)}</div>
          <div class="stat-desc">Matching current scope</div>
        </div>
        ${metricCardsHtml}
      </section>

      <!-- DATASET METADATA & SCHEMA INFO CARD -->
      <section class="section-card" style="background: #fff; border: 1px solid var(--color-border); border-radius: 12px; padding: 1.25rem;">
        <h3 style="font-size: 1rem; font-weight: 700; color: var(--color-primary-text); margin: 0 0 0.75rem 0;">
          Schema &amp; Auditing Information
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; font-size: 0.85rem;">
          <div>
            <div style="color: var(--color-secondary-text); margin-bottom: 0.25rem;">Display Columns (${(def.display_columns || []).length}):</div>
            <div style="color: var(--color-primary-text); font-family: monospace; font-size: 0.78rem; word-break: break-all; background: #F8FAFC; padding: 0.5rem; border-radius: 6px; border: 1px solid #E2E8F0;">
              ${(def.display_columns || []).join(', ')}
            </div>
          </div>
          <div>
            <div style="color: var(--color-secondary-text); margin-bottom: 0.25rem;">Searchable Fields:</div>
            <div style="color: var(--color-primary-text); font-family: monospace; font-size: 0.78rem; word-break: break-all; background: #F8FAFC; padding: 0.5rem; border-radius: 6px; border: 1px solid #E2E8F0;">
              ${(def.searchable_columns || []).join(', ')}
            </div>
          </div>
          <div>
            <div style="color: var(--color-secondary-text); margin-bottom: 0.25rem;">Allowed Sort Columns:</div>
            <div style="color: var(--color-primary-text); font-family: monospace; font-size: 0.78rem; word-break: break-all; background: #F8FAFC; padding: 0.5rem; border-radius: 6px; border: 1px solid #E2E8F0;">
              ${(def.allowed_sort_columns || []).join(', ')}
            </div>
          </div>
        </div>
      </section>
    `;
  }

  // Generate metric tiles based on dataset specific output
  function generateDatasetMetricCards(datasetKey, metrics) {
    const cards = [];

    // 01_ITEM_PRODUCT_MASTER
    if (metrics.by_item_type) {
      const types = metrics.by_item_type || {};
      const rm = types['RM'] || 0;
      const fg = types['FG'] || 0;
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Item Types</span><span>🏷️</span></div>
          <div class="stat-number" style="font-size: 1.25rem; color: var(--color-royal-blue);">
            RM: ${formatNumber(rm)} &bull; FG: ${formatNumber(fg)}
          </div>
          <div class="stat-desc">Raw Materials vs Finished Goods</div>
        </div>
      `);
    }
    if (metrics.items_with_positive_rate !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Priced Items</span><span>💰</span></div>
          <div class="stat-number" style="color: #10B981;">${formatNumber(metrics.items_with_positive_rate)}</div>
          <div class="stat-desc">Items with standard rate &gt; 0</div>
        </div>
      `);
    }
    if (metrics.distinct_grades !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Material Grades</span><span>⚙️</span></div>
          <div class="stat-number" style="color: var(--color-bright-cyan);">${formatNumber(metrics.distinct_grades)}</div>
          <div class="stat-desc">Distinct material specifications</div>
        </div>
      `);
    }

    // 06_MATERIAL_TRANSACTION
    if (metrics.total_quantity !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Total Quantity</span><span>📦</span></div>
          <div class="stat-number" style="color: var(--color-royal-blue);">${formatNumber(metrics.total_quantity)}</div>
          <div class="stat-desc">Total units moved / consumed</div>
        </div>
      `);
    }
    if (metrics.total_amount !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Valuation Amount</span><span>💵</span></div>
          <div class="stat-number" style="color: #033665;">$${formatAmount(metrics.total_amount)}</div>
          <div class="stat-desc">Total transaction valuation</div>
        </div>
      `);
    }

    // 09_TRIAL_BALANCE
    if (metrics.total_debit !== undefined && metrics.total_credit !== undefined) {
      const isBalanced = metrics.is_balanced;
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Total Debits</span><span>📈</span></div>
          <div class="stat-number" style="color: #033665;">$${formatAmount(metrics.total_debit)}</div>
          <div class="stat-desc">Closing debits sum</div>
        </div>
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Total Credits</span><span>📉</span></div>
          <div class="stat-number" style="color: #033665;">$${formatAmount(metrics.total_credit)}</div>
          <div class="stat-desc">Closing credits sum</div>
        </div>
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Balance Status</span><span>⚖️</span></div>
          <div class="stat-number" style="color: ${isBalanced ? '#10B981' : '#EF4444'};">
            ${isBalanced ? 'MATCHED' : 'VARIANCE'}
          </div>
          <div class="stat-desc">${isBalanced ? 'Debits equal Credits (Net 0.00)' : 'Out-of-balance discrepancy detected'}</div>
        </div>
      `);
    }

    // 02_WORK_CENTER_MASTER
    if (metrics.total_capacity_hrs !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Total Capacity</span><span>⏱️</span></div>
          <div class="stat-number" style="color: var(--color-royal-blue);">${formatNumber(metrics.total_capacity_hrs)} hrs</div>
          <div class="stat-desc">Daily operational capacity</div>
        </div>
      `);
    }

    // 05A_BOM
    if (metrics.unique_parents !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Parent Assemblies</span><span>📐</span></div>
          <div class="stat-number" style="color: var(--color-royal-blue);">${formatNumber(metrics.unique_parents)}</div>
          <div class="stat-desc">Distinct manufactured parents</div>
        </div>
      `);
    }
    if (metrics.unique_components !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Child Components</span><span>🔩</span></div>
          <div class="stat-number" style="color: var(--color-bright-cyan);">${formatNumber(metrics.unique_components)}</div>
          <div class="stat-desc">Distinct constituent parts</div>
        </div>
      `);
    }

    // 08_FINANCE_TRANSACTION
    if (metrics.total_cost !== undefined) {
      cards.push(`
        <div class="stat-card">
          <div class="stat-header"><span class="stat-label">Total Cost</span><span>💰</span></div>
          <div class="stat-number" style="color: #033665;">$${formatAmount(metrics.total_cost)}</div>
          <div class="stat-desc">Financial transaction total</div>
        </div>
      `);
    }

    // Fallback: If no cards match, display key/value stats
    if (cards.length === 0) {
      for (const [k, v] of Object.entries(metrics)) {
        if (k === 'total_records' || typeof v === 'object') continue;
        const label = k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        cards.push(`
          <div class="stat-card">
            <div class="stat-header"><span class="stat-label">${escapeHtml(label)}</span><span>📈</span></div>
            <div class="stat-number" style="color: #033665;">${typeof v === 'number' ? formatNumber(v) : escapeHtml(v)}</div>
            <div class="stat-desc">Computed from database</div>
          </div>
        `);
        if (cards.length >= 3) break;
      }
    }

    return cards.join('');
  }

  /* =========================================================================
     VIEW MODE 3: DETAILED RECORDS GRID
     ========================================================================= */
  async function loadDatasetDetails() {
    try {
      const params = {
        dataset: state.datasetKey,
        page: state.page,
        per_page: state.limit,
        sort_by: state.sortCol,
        sort_dir: state.sortDir,
        search: state.detailsSearch,
        start_date: state.startDate,
        end_date: state.endDate
      };

      // Merge column filters
      for (const [k, v] of Object.entries(state.columnFilters)) {
        if (v !== '' && v !== null) {
          params[k] = v;
        }
      }

      const detailsRes = await apiCall('dataset_details', params);
      state.datasetDef = detailsRes.dataset_definition || {};

      renderDetailsView(detailsRes);
    } catch (err) {
      console.error('Failed to load dataset details:', err);
    }
  }

  function renderDetailsView(data) {
    const viewContainer = document.getElementById('repViewContainer');
    if (!viewContainer) return;

    const def = data.dataset_definition || {};
    const pagination = data.pagination || {};
    const rows = data.data || [];
    const columns = def.display_columns || (rows.length > 0 ? Object.keys(rows[0]) : []);
    const canExport = state.userContext && state.userContext.permissions && state.userContext.permissions.can_export;

    const totalRecords = pagination.total_records || 0;
    const totalPages = pagination.total_pages || 1;
    const curPage = pagination.current_page || 1;
    const startRecord = totalRecords > 0 ? ((curPage - 1) * state.limit) + 1 : 0;
    const endRecord = Math.min(curPage * state.limit, totalRecords);

    viewContainer.innerHTML = `
      <!-- TOP ACTION & FILTER TOOLBAR -->
      <section class="section-card" style="margin-bottom: 1rem; padding: 1.25rem; background: #fff; border: 1px solid var(--color-border); border-radius: 12px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1rem;">
          
          <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="btn-secondary" onclick="window.Cost360Reports.switchView('summary', '${escapeHtml(state.datasetKey)}')" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
              ← Back to Summary
            </button>
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--color-primary-text); margin: 0;">
              ${escapeHtml(def.display_name || state.datasetKey)}
            </h2>
            <span class="c360-badge" style="background: rgba(3, 54, 101, 0.08); color: #033665; font-size: 0.75rem; font-family: monospace;">
              public.${escapeHtml(def.target_table || '')}
            </span>
          </div>

          <div style="display: flex; gap: 0.5rem; align-items: center;">
            ${canExport ? `
              <button type="button" class="btn-secondary rep-export-btn" onclick="window.Cost360Reports.exportCsv('${escapeHtml(state.datasetKey)}')"
                style="padding: 0.45rem 0.85rem; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                📥 Export Filtered CSV
              </button>
            ` : ''}
            <button type="button" class="btn-secondary" onclick="window.Cost360Reports.clearFilters()"
              style="padding: 0.45rem 0.85rem; font-size: 0.82rem;">
              ✕ Clear Filters
            </button>
          </div>

        </div>

        <!-- SEARCH AND QUICK CONTROLS -->
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
          <!-- Live Text Search -->
          <div style="flex: 2; min-width: 240px; position: relative;">
            <input type="text" id="repDetailsSearchInput" placeholder="🔍 Search records across text columns..." 
              value="${escapeHtml(state.detailsSearch)}"
              class="cost360-select" style="width: 100%; padding: 0.45rem 0.85rem; font-size: 0.85rem; border-radius: 8px; border: 1px solid var(--color-border);" />
          </div>

          <!-- Date Filters (if dataset has date column) -->
          ${def.primary_date_column ? `
            <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem; color: var(--color-secondary-text);">
              <span>Date:</span>
              <input type="date" id="repStartDate" value="${escapeHtml(state.startDate)}" class="cost360-select" style="padding: 0.35rem 0.5rem; font-size: 0.8rem; border-radius: 6px; border: 1px solid var(--color-border);" />
              <span>to</span>
              <input type="date" id="repEndDate" value="${escapeHtml(state.endDate)}" class="cost360-select" style="padding: 0.35rem 0.5rem; font-size: 0.8rem; border-radius: 6px; border: 1px solid var(--color-border);" />
            </div>
          ` : ''}

          <!-- Page Size -->
          <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem; color: var(--color-secondary-text);">
            <span>Show:</span>
            <select id="repPageSizeSelect" class="cost360-select" style="padding: 0.35rem 0.65rem; font-size: 0.82rem; border-radius: 6px; border: 1px solid var(--color-border);">
              <option value="25" ${state.limit === 25 ? 'selected' : ''}>25</option>
              <option value="50" ${state.limit === 50 ? 'selected' : ''}>50</option>
              <option value="100" ${state.limit === 100 ? 'selected' : ''}>100</option>
              <option value="250" ${state.limit === 250 ? 'selected' : ''}>250</option>
            </select>
          </div>
        </div>
      </section>

      <!-- DATA TABLE CONTAINER -->
      <section class="section-card" style="padding: 0; background: #fff; border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
        <div class="table-responsive" style="max-height: 65vh; overflow-y: auto;">
          <table class="preview-data-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
              <tr style="position: sticky; top: 0; z-index: 2; background: #033665; color: #fff;">
                <th style="padding: 0.75rem 0.85rem; border-bottom: 2px solid var(--color-border); text-align: center; width: 50px; background: #033665; color: #fff;">#</th>
                ${columns.map(col => {
                  const isSortable = (def.allowed_sort_columns || []).includes(col);
                  const isCurrentSort = state.sortCol === col;
                  const arrow = isCurrentSort ? (state.sortDir === 'ASC' ? ' ▲' : ' ▼') : (isSortable ? ' ↕' : '');
                  const cursor = isSortable ? 'cursor: pointer; user-select: none;' : '';
                  return `
                    <th style="padding: 0.75rem 0.85rem; border-bottom: 2px solid var(--color-border); text-align: left; background: #033665; color: #fff; font-weight: 700; ${cursor}"
                      ${isSortable ? `onclick="window.Cost360Reports.sortBy('${escapeHtml(col)}')"` : ''}
                      title="${isSortable ? 'Click to sort' : ''}">
                      ${escapeHtml(col.replace(/_/g, ' ').toUpperCase())}${arrow}
                    </th>
                  `;
                }).join('')}
              </tr>
            </thead>
            <tbody>
              ${rows.length === 0 ? `
                <tr>
                  <td colspan="${columns.length + 1}" style="text-align: center; padding: 3rem 1.5rem; color: var(--color-secondary-text);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📂</div>
                    <p style="margin: 0; font-size: 0.95rem;">No records found matching current company, period, or search criteria.</p>
                  </td>
                </tr>
              ` : rows.map((row, idx) => {
                const rowNum = startRecord + idx;
                const isEven = idx % 2 === 0;
                return `
                  <tr style="background: ${isEven ? '#FFFFFF' : '#F8FAFC'}; transition: background 0.15s;" onmouseover="this.style.background='#F0F7FD'" onmouseout="this.style.background='${isEven ? '#FFFFFF' : '#F8FAFC'}'">
                    <td style="padding: 0.6rem 0.85rem; border-bottom: 1px solid var(--color-border); text-align: center; color: var(--color-light-text); font-size: 0.78rem;">
                      ${rowNum}
                    </td>
                    ${columns.map(col => {
                      const val = row[col];
                      const isNumeric = (def.numeric_columns || []).includes(col) || (typeof val === 'number');
                      const align = isNumeric ? 'text-align: right;' : 'text-align: left;';
                      let displayVal = val;

                      if (val === null || val === undefined) {
                        displayVal = '<span style="color: #CBD5E1;">—</span>';
                      } else if (typeof val === 'boolean') {
                        displayVal = val ? '<span class="c360-badge" style="background:#EAF5FC; color:#0878D1;">TRUE</span>' : '<span class="c360-badge" style="background:#F1F5F9; color:#64748B;">FALSE</span>';
                      } else if (col.toLowerCase() === 'status') {
                        const s = String(val).toUpperCase();
                        const color = s === 'ACTIVE' || s === 'COMPLETED' ? '#10B981' : (s === 'PENDING' || s === 'DRAFT' ? '#F59E0B' : '#64748B');
                        displayVal = `<span class="c360-badge" style="background: rgba(0,0,0,0.05); color: ${color}; font-weight: 700;">${escapeHtml(s)}</span>`;
                      } else if (isNumeric && typeof val === 'number') {
                        displayVal = formatAmount(val);
                      } else {
                        displayVal = escapeHtml(String(val));
                      }

                      return `
                        <td style="padding: 0.6rem 0.85rem; border-bottom: 1px solid var(--color-border); ${align} font-family: ${isNumeric ? 'monospace' : 'inherit'}; color: var(--color-primary-text);">
                          ${displayVal}
                        </td>
                      `;
                    }).join('')}
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>

        <!-- PAGINATION CONTROLS BAR -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; padding: 0.85rem 1.25rem; background: #FAFBFD; border-top: 1px solid var(--color-border);">
          <div style="font-size: 0.85rem; color: var(--color-secondary-text);">
            Showing <strong>${formatNumber(startRecord)}</strong> to <strong>${formatNumber(endRecord)}</strong> of <strong>${formatNumber(totalRecords)}</strong> records
          </div>

          <div style="display: flex; gap: 0.35rem; align-items: center;">
            <button type="button" class="btn-secondary" onclick="window.Cost360Reports.goToPage(1)" ${curPage <= 1 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''} style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
              « First
            </button>
            <button type="button" class="btn-secondary" onclick="window.Cost360Reports.goToPage(${curPage - 1})" ${curPage <= 1 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''} style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
              ‹ Prev
            </button>
            
            <span style="font-size: 0.85rem; font-weight: 600; padding: 0 0.5rem; color: var(--color-primary-text);">
              Page ${curPage} of ${totalPages}
            </span>

            <button type="button" class="btn-secondary" onclick="window.Cost360Reports.goToPage(${curPage + 1})" ${curPage >= totalPages ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''} style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
              Next ›
            </button>
            <button type="button" class="btn-secondary" onclick="window.Cost360Reports.goToPage(${totalPages})" ${curPage >= totalPages ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''} style="padding: 0.35rem 0.65rem; font-size: 0.8rem;">
              Last »
            </button>
          </div>
        </div>
      </section>
    `;

    // Bind Search Input with debounce
    const searchInput = document.getElementById('repDetailsSearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(state.searchDebounceTimer);
        state.searchDebounceTimer = setTimeout(() => {
          state.detailsSearch = searchInput.value.trim();
          state.page = 1;
          loadDatasetDetails();
        }, 350);
      });
    }

    // Bind Date Pickers
    const startDateInput = document.getElementById('repStartDate');
    const endDateInput = document.getElementById('repEndDate');
    if (startDateInput) {
      startDateInput.addEventListener('change', function () {
        state.startDate = this.value;
        state.page = 1;
        loadDatasetDetails();
      });
    }
    if (endDateInput) {
      endDateInput.addEventListener('change', function () {
        state.endDate = this.value;
        state.page = 1;
        loadDatasetDetails();
      });
    }

    // Bind Page Size Dropdown
    const pageSizeSelect = document.getElementById('repPageSizeSelect');
    if (pageSizeSelect) {
      pageSizeSelect.addEventListener('change', function () {
        state.limit = parseInt(this.value, 10);
        state.page = 1;
        loadDatasetDetails();
      });
    }
  }

  // Go to specific page
  function goToPage(p) {
    if (p < 1) return;
    state.page = p;
    loadDatasetDetails();
  }

  // Sort by Column
  function sortBy(column) {
    if (state.sortCol === column) {
      state.sortDir = state.sortDir === 'ASC' ? 'DESC' : 'ASC';
    } else {
      state.sortCol = column;
      state.sortDir = 'ASC';
    }
    state.page = 1;
    loadDatasetDetails();
  }

  // Clear Filters (preserves user's allocated company and period scope)
  function clearFilters() {
    state.detailsSearch = '';
    state.startDate = '';
    state.endDate = '';
    state.sortCol = '';
    state.sortDir = 'ASC';
    state.columnFilters = {};
    state.page = 1;
    loadDatasetDetails();
  }

  // Export CSV
  function exportCsv(datasetKey) {
    const key = datasetKey || state.datasetKey;
    if (!key) return;

    const queryParams = new URLSearchParams();
    queryParams.append('action', 'export_csv');
    queryParams.append('dataset', key);

    if (state.activeCompanyCode) {
      queryParams.append('company_code', state.activeCompanyCode);
    }
    if (state.activePeriodId) {
      queryParams.append('period_id', state.activePeriodId);
    }
    if (state.detailsSearch) {
      queryParams.append('search', state.detailsSearch);
    }
    if (state.startDate) {
      queryParams.append('start_date', state.startDate);
    }
    if (state.endDate) {
      queryParams.append('end_date', state.endDate);
    }

    const exportUrl = `${API_URL}?${queryParams.toString()}`;
    window.location.href = exportUrl;
  }

  // Export Public API to Window
  window.Cost360Reports = {
    init: init,
    switchView: switchView,
    refresh: refreshActiveView,
    goToPage: goToPage,
    sortBy: sortBy,
    clearFilters: clearFilters,
    exportCsv: exportCsv
  };

  // Auto-initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
