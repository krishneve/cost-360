/**
 * Cost360 — Quantity Reco Frontend Engine (assets/js/quantity_reco.js)
 * 
 * Provides:
 * - Dynamic Cost Centre matrix generation (100% dynamic column headers)
 * - Plant and Period switching with dynamic master queries
 * - Full generation lifecycle with real loading and error states
 * - Source-level drilldown & auditability modal
 * - Unmapped exception inspection and resolution modal
 * - Configurable Cost Basis mappings CRUD interface
 * - Indian currency number formatting (₹)
 * - Responsive table with horizontal scrolling & sticky headers
 */

(function() {
  'use strict';

  // State Management
  const state = {
    activePlantId: null,
    activePeriodId: null,
    costCentres: [],
    recoData: null,
    currentRunId: null,
    viewMode: 'excel', // 'excel' (plant.xlsx primary grid) or 'summary'
    searchQuery: '',
    costingBasisFilter: 'ALL',
    natureFilter: 'ALL',
    checkFilter: 'ALL',
    isLoading: false,
    isGenerating: false
  };

  const API_ENDPOINT = (window.cost360BaseUrlPrefix || '') + 'api/quantity_reco_api.php';

  // Format amount as Indian Rupee (INR)
  function formatINR(val, withDecimals = false, allowZero = true) {
    if (val === null || val === undefined || isNaN(val)) return allowZero ? '0' : '—';
    const num = Number(val);
    if (num === 0) return allowZero ? '0' : '—';
    const sign = num < 0 ? '-' : '';
    const abs = Math.abs(num);
    const fixed = withDecimals ? abs.toFixed(2) : Math.round(abs).toString();
    const parts = fixed.split('.');
    let intPart = parts[0];
    const decPart = parts.length > 1 ? '.' + parts[1] : '';

    if (intPart.length > 3) {
      const last3 = intPart.substring(intPart.length - 3);
      const otherNumbers = intPart.substring(0, intPart.length - 3);
      intPart = otherNumbers.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + "," + last3;
    }
    return `${sign}₹${intPart}${decPart}`;
  }

  function formatNumber(val) {
    if (val === null || val === undefined || isNaN(val)) return '0';
    return Number(val).toLocaleString('en-IN');
  }

  // Toast / Notification Helper
  function showNotification(type, message) {
    const banner = document.getElementById('qrAlertBanner');
    const msgEl = document.getElementById('qrAlertMessage');
    const iconEl = document.getElementById('qrAlertIcon');
    if (!banner || !msgEl) return;

    banner.className = 'qr-alert-banner qr-alert-' + type;
    msgEl.textContent = message;

    if (type === 'success') {
      iconEl.textContent = '✅';
      banner.style.background = '#ECFDF5';
      banner.style.borderColor = '#A7F3D0';
      banner.style.color = '#065F46';
    } else if (type === 'warning') {
      iconEl.textContent = '⚠️';
      banner.style.background = '#FFFBEB';
      banner.style.borderColor = '#FCD34D';
      banner.style.color = '#92400E';
    } else {
      iconEl.textContent = '❌';
      banner.style.background = '#FEF2F2';
      banner.style.borderColor = '#FCA5A5';
      banner.style.color = '#B91C1C';
    }

    banner.style.display = 'flex';
    // Auto-dismiss success notifications after 5 seconds
    if (type === 'success') {
      setTimeout(() => {
        if (banner.style.display === 'flex') banner.style.display = 'none';
      }, 5000);
    }
  }

  function hideNotification() {
    const banner = document.getElementById('qrAlertBanner');
    if (banner) banner.style.display = 'none';
  }

  // Initialize Module
  function init() {
    const container = document.getElementById('qrMainContainer');
    if (!container) return;

    const plantSelect = document.getElementById('qrPlantSelect');
    const periodSelect = document.getElementById('qrPeriodSelect');
    const generateBtn = document.getElementById('qrGenerateBtn');
    const refreshBtn = document.getElementById('qrRefreshBtn');
    const exceptionsBtn = document.getElementById('qrExceptionsBtn');
    const mappingsBtn = document.getElementById('qrMappingsBtn');
    const reconcileBtn = document.getElementById('qrReconcileBtn');
    const syncTbBtn = document.getElementById('qrSyncTbBtn');
    const searchInput = document.getElementById('qrSearchInput');
    const viewBreakdownBtn = document.getElementById('qrViewBreakdownBtn');
    const viewSummaryBtn = document.getElementById('qrViewSummaryBtn');
    const exportCsvBtn = document.getElementById('qrExportCsvBtn');

    if (plantSelect) {
      state.activePlantId = parseInt(plantSelect.value, 10) || null;
      plantSelect.addEventListener('change', function() {
        state.activePlantId = parseInt(this.value, 10) || null;
        onScopeChanged();
      });
    }

    if (periodSelect) {
      state.activePeriodId = parseInt(periodSelect.value, 10) || null;
      periodSelect.addEventListener('change', function() {
        state.activePeriodId = parseInt(this.value, 10) || null;
        onScopeChanged();
      });
    }

    if (generateBtn) {
      generateBtn.addEventListener('click', handleGenerate);
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => loadQuantityReco(false));
    }

    if (exceptionsBtn) {
      exceptionsBtn.addEventListener('click', openExceptionsModal);
    }

    if (mappingsBtn) {
      mappingsBtn.addEventListener('click', openMappingsModal);
    }

    const allocRulesBtn = document.getElementById('qrAllocRulesBtn');
    if (allocRulesBtn) {
      allocRulesBtn.addEventListener('click', () => openAllocRulesModal());
    }

    if (reconcileBtn) {
      reconcileBtn.addEventListener('click', openReconciliationModal);
    }

    if (syncTbBtn) {
      syncTbBtn.addEventListener('click', handleSyncTb);
    }

    if (searchInput) {
      searchInput.addEventListener('input', function() {
        state.searchQuery = this.value.trim().toLowerCase();
        renderTable();
      });
    }

    if (viewBreakdownBtn && viewSummaryBtn) {
      viewBreakdownBtn.addEventListener('click', () => {
        state.viewMode = 'breakdown';
        viewBreakdownBtn.classList.add('active');
        viewSummaryBtn.classList.remove('active');
        renderTable();
      });
      viewSummaryBtn.addEventListener('click', () => {
        state.viewMode = 'summary';
        viewSummaryBtn.classList.add('active');
        viewBreakdownBtn.classList.remove('active');
        renderTable();
      });
    }

    if (exportCsvBtn) {
      exportCsvBtn.addEventListener('click', exportMatrixToCsv);
    }

    // Modal Close Buttons
    document.querySelectorAll('.qr-modal-close-trigger').forEach(btn => {
      btn.addEventListener('click', closeAllModals);
    });

    // Close on overlay click
    document.querySelectorAll('.cost360-modal-overlay').forEach(overlay => {
      overlay.addEventListener('click', function(e) {
        if (e.target === this) closeAllModals();
      });
    });

    // Close on ESC key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeAllModals();
    });

    // Initial Load
    onScopeChanged();
  }

  // Handle Plant or Period Change
  function onScopeChanged() {
    hideNotification();
    if (!state.activePlantId) {
      renderEmptyState("Select a plant to view Quantity Reco.", "🏭");
      updateRunInfoBar(null);
      return;
    }
    if (!state.activePeriodId) {
      renderEmptyState("Select a period to view Quantity Reco.", "📅");
      updateRunInfoBar(null);
      return;
    }
    loadQuantityReco(false);
  }

  // Fetch Quantity Reco Matrix from Backend
  async function loadQuantityReco(forceRefresh = false) {
    if (!state.activePlantId || !state.activePeriodId) return;

    setLoading(true, "Loading Quantity Reco matrix & dynamic Cost Centres...");

    try {
      const url = `${API_ENDPOINT}?action=get_reco&plant_id=${state.activePlantId}&period_id=${state.activePeriodId}&t=${Date.now()}`;
      const response = await fetch(url);
      const data = await response.json();

      setLoading(false);

      if (!data.ok) {
        showNotification('error', data.error || 'Failed to retrieve Quantity Reco matrix.');
        renderErrorState(data.error || 'An error occurred while loading data.');
        return;
      }

      state.recoData = data;
      state.costCentres = data.columns || [];
      state.currentRunId = data.run ? data.run.run_id : null;

      updateRunInfoBar(data);

      if (!data.has_run || !data.run) {
        renderGenerationRequiredState();
        return;
      }

      if (state.costCentres.length === 0) {
        renderEmptyState("No active Cost Centres configured for this Plant.", "ℹ️");
        return;
      }

      renderTable();

    } catch (err) {
      setLoading(false);
      showNotification('error', "Network error connecting to Quantity Reco API: " + err.message);
      renderErrorState("Network connection failed. Please check your network and try again.");
    }
  }

  // Handle Generation
  async function handleGenerate() {
    if (!state.activePlantId || !state.activePeriodId) {
      showNotification('warning', "Please select both a Plant and a Period before generating.");
      return;
    }

    const genBtn = document.getElementById('qrGenerateBtn');
    if (genBtn) {
      genBtn.disabled = true;
      genBtn.innerHTML = `<span class="c360-spinner-sm" style="display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;margin-right:6px;"></span> Generating...`;
    }

    setLoading(true, `
      <div style="font-weight:700;font-size:1.05rem;color:var(--c360-navy-deep);margin-bottom:0.5rem;">Generating Quantity Reco...</div>
      <div style="color:var(--c360-text-secondary);font-size:0.86rem;line-height:1.5;">
        1. Processing source transactions...<br>
        2. Applying Cost Basis mappings...<br>
        3. Allocating to plant-specific Cost Centres...<br>
        4. Isolating exceptions &amp; verifying audit integrity...
      </div>
    `);

    try {
      const formData = new FormData();
      formData.append('action', 'generate');
      formData.append('plant_id', state.activePlantId);
      formData.append('period_id', state.activePeriodId);

      const response = await fetch(API_ENDPOINT, {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (genBtn) {
        genBtn.disabled = false;
        genBtn.innerHTML = `⚡ Generate`;
      }

      if (result.ok) {
        showNotification('success', `Quantity Reco successfully generated! Mapped: ${formatINR(result.summary.mapped_amount)} across ${result.summary.mapped_records_count} records.`);
        await loadQuantityReco(true);
      } else {
        setLoading(false);
        showNotification('error', result.error || 'Failed to generate Quantity Reco.');
        renderErrorState(result.error || 'Generation failed.');
      }

    } catch (err) {
      if (genBtn) {
        genBtn.disabled = false;
        genBtn.innerHTML = `⚡ Generate`;
      }
      setLoading(false);
      showNotification('error', "Generation request failed: " + err.message);
    }
  }

  // Update Run Information Bar
  function updateRunInfoBar(data) {
    const bar = document.getElementById('qrRunInfoBar');
    const excBadge = document.getElementById('qrExceptionsCountBadge');
    if (!bar) return;

    if (!data || !data.has_run || !data.run) {
      bar.style.display = 'none';
      if (excBadge) excBadge.textContent = '0';
      return;
    }

    bar.style.display = 'flex';

    const run = data.run;
    const plantName = data.plant ? data.plant.plant_name : '—';
    const periodName = data.period ? data.period.period_name : '—';

    document.getElementById('qrInfoPlant').textContent = plantName;
    document.getElementById('qrInfoPeriod').textContent = periodName;
    document.getElementById('qrInfoStatus').textContent = run.status || 'COMPLETED';
    document.getElementById('qrInfoTotalRecords').textContent = formatNumber(run.total_source_records);
    document.getElementById('qrInfoMappedRecords').textContent = formatNumber(run.mapped_records_count);
    document.getElementById('qrInfoUnmappedRecords').textContent = formatNumber(run.unmapped_records_count);
    document.getElementById('qrInfoMappedAmount').textContent = formatINR(run.mapped_amount);
    document.getElementById('qrInfoUnmappedAmount').textContent = formatINR(run.unmapped_amount);

    const excCount = parseInt(run.unmapped_records_count, 10) || 0;
    if (excBadge) {
      excBadge.textContent = excCount;
      excBadge.style.display = excCount > 0 ? 'inline-block' : 'none';
    }

    const unmappedPill = document.getElementById('qrUnmappedStatPill');
    if (unmappedPill) {
      if (excCount > 0) {
        unmappedPill.style.background = '#FEF2F2';
        unmappedPill.style.borderColor = '#FCA5A5';
        unmappedPill.style.color = '#B91C1C';
      } else {
        unmappedPill.style.background = '#F8FAFC';
        unmappedPill.style.borderColor = '#E2E8F0';
        unmappedPill.style.color = 'var(--c360-text-secondary)';
      }
    }

    if (run.generated_at) {
      const d = new Date(run.generated_at);
      document.getElementById('qrInfoGeneratedAt').textContent = d.toLocaleString('en-IN', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: true
      });
    } else {
      document.getElementById('qrInfoGeneratedAt').textContent = 'Just now';
    }
  }

  // Render Table
  function renderTable() {
    const container = document.getElementById('qrMainContainer');
    if (!container || !state.recoData) return;

    const data = state.recoData;
    const columns = data.columns || [];

    if (columns.length === 0) {
      renderEmptyState("No active Cost Centres configured for this Plant.", "ℹ️");
      return;
    }

    // 1. Prepare Rows
    let allRows = data.rows || [];
    if (allRows.length === 0 && data.details && data.details.length > 0) {
      allRows = data.details.map(d => {
        const amt = parseFloat(d.amount) || 0.0;
        return {
          type: 'MAPPED',
          detail_id: d.detail_id,
          aname: d.source_description || '',
          acode: d.account_code || d.source_row_ref || '',
          pnl: d.pnl_classification || 'Rev from Operations',
          closing: amt,
          costing_basis: d.source_cost_basis || '',
          nature: d.nature || 'Variable',
          cost_centre_id: d.cost_centre_id,
          total: amt,
          check: 0.0,
          is_balanced: true,
          check_status: 'Balanced'
        };
      });
    }

    // Extract unique Costing Bases and Natures for toolbar filters
    const basisSet = new Set();
    const natureSet = new Set();
    allRows.forEach(r => {
      if (r.costing_basis) basisSet.add(r.costing_basis);
      if (r.nature) natureSet.add(r.nature);
    });

    // 2. Filter Rows
    const filteredRows = allRows.filter(r => {
      if (state.searchQuery) {
        const q = state.searchQuery;
        const nameMatch = (r.aname || '').toLowerCase().includes(q);
        const codeMatch = (r.acode || '').toLowerCase().includes(q);
        const basisMatch = (r.costing_basis || '').toLowerCase().includes(q);
        const pnlMatch = (r.pnl || '').toLowerCase().includes(q);
        if (!nameMatch && !codeMatch && !basisMatch && !pnlMatch) return false;
      }
      if (state.costingBasisFilter !== 'ALL' && r.costing_basis !== state.costingBasisFilter) {
        return false;
      }
      if (state.natureFilter !== 'ALL' && r.nature !== state.natureFilter) {
        return false;
      }
      if (state.checkFilter === 'BALANCED' && !r.is_balanced) {
        return false;
      }
      if (state.checkFilter === 'UNBALANCED' && r.is_balanced) {
        return false;
      }
      return true;
    });

    // Compute dynamic totals based on currently filtered rows
    let filteredClosingTotal = 0.0;
    let filteredAllocTotal = 0.0;
    const filteredCcTotals = {};
    columns.forEach(c => { filteredCcTotals[c.cost_centre_id] = 0.0; });

    filteredRows.forEach(r => {
      const cls = parseFloat(r.closing) || 0.0;
      filteredClosingTotal += cls;
      if (r.is_balanced && r.cost_centre_id) {
        filteredAllocTotal += cls;
        if (filteredCcTotals[r.cost_centre_id] !== undefined) {
          filteredCcTotals[r.cost_centre_id] += cls;
        }
      }
    });

    const filteredCheckTotal = filteredClosingTotal - filteredAllocTotal;
    const isFilteredBalanced = Math.abs(filteredCheckTotal) < 0.0001;

    // 3. Toolbar HTML
    let html = `
      <div class="qr-table-card">
        <div class="qr-table-toolbar" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;padding:0.85rem 1rem;background:#F8FAFC;border-bottom:1px solid var(--c360-border);">
          <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
            <div style="font-weight:800;font-size:0.95rem;color:var(--c360-navy-deep);display:flex;align-items:center;gap:0.4rem;">
              <span>📑 Plant Quantity Reco Grid</span>
              <span class="qr-badge-pill" style="background:#E2E8F0;color:#334155;font-size:0.72rem;">${filteredRows.length} of ${allRows.length} Rows</span>
              <span class="qr-badge-pill" style="background:#DBEAFE;color:#1E40AF;font-size:0.72rem;">${columns.length} Cost Centres</span>
            </div>
          </div>

          <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
            <!-- Live Search -->
            <div class="qr-search-box">
              <span class="qr-search-icon">🔍</span>
              <input type="text" id="qrSearchInput" placeholder="Filter ANAME, ACODE..." value="${escapeHtml(state.searchQuery)}">
            </div>

            <!-- Costing Basis Dropdown Filter -->
            <select id="qrCostingBasisFilter" class="qr-filter-select" title="Filter by Costing Basis">
              <option value="ALL">All Costing Bases</option>
              ${[...basisSet].sort().map(b => `<option value="${escapeHtml(b)}" ${state.costingBasisFilter === b ? 'selected' : ''}>${escapeHtml(b)}</option>`).join('')}
            </select>

            <!-- Nature Dropdown Filter -->
            <select id="qrNatureFilter" class="qr-filter-select" title="Filter by Nature">
              <option value="ALL">All Natures</option>
              ${[...natureSet].sort().map(n => `<option value="${escapeHtml(n)}" ${state.natureFilter === n ? 'selected' : ''}>${escapeHtml(n)}</option>`).join('')}
            </select>

            <!-- Check Status Dropdown Filter -->
            <select id="qrCheckFilter" class="qr-filter-select" title="Filter by Check Status">
              <option value="ALL" ${state.checkFilter === 'ALL' ? 'selected' : ''}>All Rows</option>
              <option value="BALANCED" ${state.checkFilter === 'BALANCED' ? 'selected' : ''}>Balanced (Check = 0)</option>
              <option value="UNBALANCED" ${state.checkFilter === 'UNBALANCED' ? 'selected' : ''}>Unbalanced (Check != 0)</option>
            </select>

            <!-- View Mode Toggle Buttons -->
            <div class="qr-btn-group">
              <button type="button" class="qr-toggle-btn ${state.viewMode === 'excel' ? 'active' : ''}" id="qrViewExcelBtn">Excel Grid</button>
              <button type="button" class="qr-toggle-btn ${state.viewMode === 'summary' ? 'active' : ''}" id="qrViewSummaryBtn">Summary Matrix</button>
            </div>

            <!-- Export CSV -->
            <button type="button" class="btn-secondary" id="qrExportCsvBtn" style="font-size:0.8rem;padding:0.35rem 0.75rem;">
              📥 Export CSV
            </button>
          </div>
        </div>

        <div class="table-responsive qr-scrollable-table-wrap">
          <table class="qr-matrix-table qr-excel-grid" id="qrMatrixTable">
    `;

    // 4. Primary View: Excel-style Quantity Reco Grid
    if (state.viewMode === 'excel') {
      html += `
        <thead>
          <!-- Super Header Groups Row -->
          <tr>
            <th colspan="6" class="qr-grp-header-fixed qr-sticky-col">
              1. FIXED SOURCE INFORMATION (6 COLUMNS)
            </th>
            <th colspan="${columns.length}" class="qr-grp-header-cc">
              2. DYNAMIC COST CENTRES (${columns.length} COLUMNS)
            </th>
            <th colspan="2" class="qr-grp-header-calc">
              3. RECONCILIATION
            </th>
          </tr>

          <!-- Individual Column Headers Row -->
          <tr>
            <th class="qr-sticky-col qr-th-fixed" style="min-width: 220px; z-index: 25;">ANAME</th>
            <th class="qr-th-fixed" style="min-width: 95px;">ACODE</th>
            <th class="qr-th-fixed" style="min-width: 120px;">P&amp;L</th>
            <th class="qr-th-fixed" style="min-width: 130px; text-align: right;">CLOSING</th>
            <th class="qr-th-fixed" style="min-width: 120px;">Costing Basis</th>
            <th class="qr-th-fixed" style="min-width: 90px;">Nature</th>
      `;

      // Dynamic Cost Centres
      columns.forEach(col => {
        html += `
          <th class="qr-th-cc" style="min-width: 130px; text-align: right;" title="Cost Centre: ${escapeHtml(col.cost_centre_name)} (${escapeHtml(col.cost_centre_code)})">
            <div style="font-weight: 700;">${escapeHtml(col.cost_centre_name)}</div>
            <div style="font-size: 0.65rem; color: var(--c360-royal-blue); font-family: var(--c360-font-mono);">${escapeHtml(col.cost_centre_code)}</div>
          </th>
        `;
      });

      // Calculated Total & Check
      html += `
            <th class="qr-th-calc" style="min-width: 130px; text-align: right;">Total</th>
            <th class="qr-th-calc" style="min-width: 110px; text-align: center;">Check</th>
          </tr>
        </thead>
        <tbody>
      `;

      if (filteredRows.length === 0) {
        html += `
          <tr>
            <td colspan="${columns.length + 8}" style="text-align: center; padding: 3rem 1rem; color: var(--c360-text-muted);">
              ${state.searchQuery || state.costingBasisFilter !== 'ALL' || state.checkFilter !== 'ALL' ? 'No records match the active filters.' : 'No allocation records available.'}
            </td>
          </tr>
        `;
      } else {
        filteredRows.forEach((row, idx) => {
          const closing = parseFloat(row.closing) || 0.0;
          const totalVal = parseFloat(row.total) || 0.0;
          const checkVal = parseFloat(row.check) || 0.0;
          const isTargetCC = (cid) => (row.cost_centre_id === cid);

          html += `
            <tr class="qr-matrix-row ${!row.is_balanced ? 'qr-row-unbalanced' : ''}">
              <td class="qr-sticky-col">
                <div style="font-weight: 700; color: var(--c360-text-primary); font-size: 0.82rem;">${escapeHtml(row.aname || 'Account #' + (idx+1))}</div>
                ${row.source_row_ref ? `<div style="font-size: 0.68rem; color: var(--c360-text-muted);">Ref: <code>${escapeHtml(row.source_row_ref)}</code></div>` : ''}
              </td>
              <td><code>${escapeHtml(row.acode || '—')}</code></td>
              <td><span style="font-size: 0.76rem; color: #475569; font-weight: 600;">${escapeHtml(row.pnl || 'Rev from Operations')}</span></td>
              <td class="qr-num-cell ${closing < 0 ? 'qr-negative-val' : ''}">
                ${formatINR(closing, false, true)}
              </td>
              <td>
                <span class="qr-badge-pill" style="background:#EBF4FC; color:var(--c360-royal-blue); font-weight:700;">
                  ${escapeHtml(row.costing_basis || '—')}
                </span>
              </td>
              <td><span style="font-size: 0.76rem; color: #64748B;">${escapeHtml(row.nature || 'Variable')}</span></td>
          `;

          // Dynamic Cost Centre Columns
          columns.forEach(col => {
            const matches = isTargetCC(col.cost_centre_id);
            if (matches) {
              html += `
                <td class="qr-num-cell qr-clickable-cell ${closing < 0 ? 'qr-negative-val' : ''}"
                    data-run-id="${state.currentRunId}" data-cc-id="${col.cost_centre_id}" data-cc-name="${escapeHtml(col.cost_centre_name)}" data-amount="${closing}"
                    title="Click to view contributing source records">
                  ${formatINR(closing, false, true)}
                </td>
              `;
            } else {
              html += `<td class="qr-zero-val">0</td>`;
            }
          });

          // Total Column
          html += `
            <td class="qr-num-cell" style="font-weight: 700; background: #FAF5FF; color: var(--c360-navy-deep);">
              ${formatINR(totalVal, false, true)}
            </td>
          `;

          // Check Column
          html += `
            <td style="text-align: center;">
              ${row.is_balanced
                ? `<span class="qr-check-badge qr-check-balanced" title="Balanced: CLOSING equals Total">✓ 0</span>`
                : `<span class="qr-check-badge qr-check-unbalanced" title="Discrepancy: ${formatINR(checkVal, false, true)} (${escapeHtml(row.unmapped_reason || 'Unmapped')})">⚠️ ${formatINR(checkVal, false, true)}</span>`
              }
            </td>
          </tr>
          `;
        });
      }

      // Grid Footer Total Row
      html += `
        </tbody>
        <tfoot>
          <tr class="qr-totals-row">
            <th class="qr-sticky-col" style="background: #F1F5F9; font-weight: 800; color: var(--c360-navy-deep);">
              TOTAL
            </th>
            <th style="background: #F1F5F9;"></th>
            <th style="background: #F1F5F9;"></th>
            <th class="qr-num-cell" style="background: #F1F5F9; font-weight: 800; color: var(--c360-navy-deep); font-size: 0.86rem;">
              ${formatINR(filteredClosingTotal, false, true)}
            </th>
            <th style="background: #F1F5F9;"></th>
            <th style="background: #F1F5F9;"></th>
      `;

      columns.forEach(col => {
        const cAmt = filteredCcTotals[col.cost_centre_id] || 0.0;
        html += `
          <th class="qr-num-cell" style="background: #EBF4FC; font-weight: 800; color: var(--c360-navy-deep); font-size: 0.84rem;">
            ${formatINR(cAmt, false, true)}
          </th>
        `;
      });

      html += `
            <th class="qr-num-cell" style="background: #EEF4FA; font-weight: 900; color: var(--c360-royal-blue); font-size: 0.92rem;">
              ${formatINR(filteredAllocTotal, false, true)}
            </th>
            <th style="text-align: center; background: #EEF4FA;">
              ${isFilteredBalanced
                ? `<span class="qr-check-badge qr-check-balanced">✓ 0</span>`
                : `<span class="qr-check-badge qr-check-unbalanced">⚠️ ${formatINR(filteredCheckTotal, false, true)}</span>`
              }
            </th>
          </tr>
        </tfoot>
      `;

    } else {
      // 5. Alternative View: Summary Matrix
      const aggregates = data.aggregates || {};
      const grandTotal = parseFloat(data.grand_total) || 0.0;

      html += `
        <thead>
          <tr>
            <th class="qr-sticky-col" style="min-width: 220px; z-index: 20;">Cost Centre Summary</th>
      `;

      columns.forEach(col => {
        html += `
          <th class="qr-cc-header-cell" style="min-width: 140px; text-align: right;">
            <div style="font-weight: 700;">${escapeHtml(col.cost_centre_name)}</div>
            <div style="font-size: 0.65rem; color: var(--c360-royal-blue); font-family: var(--c360-font-mono);">${escapeHtml(col.cost_centre_code)}</div>
          </th>
        `;
      });

      html += `
            <th style="min-width: 140px; text-align: right; background: #EEF4FA; font-weight: 800;">Total Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr class="qr-matrix-row" style="background: #FFFFFF;">
            <td class="qr-sticky-col" style="font-weight: 700; color: var(--c360-navy-deep);">
              Allocated Quantity Reco
              <div style="font-size: 0.72rem; color: var(--c360-text-muted); font-weight: normal;">Summary per dynamic Cost Centre</div>
            </td>
      `;

      columns.forEach(col => {
        const agg = aggregates[col.cost_centre_id] || { total_amount: 0, record_count: 0 };
        const amt = parseFloat(agg.total_amount) || 0;
        const cnt = parseInt(agg.record_count, 10) || 0;
        const isNonZero = (amt !== 0);

        html += `
          <td class="${isNonZero ? 'qr-amount-cell qr-clickable-cell' : 'qr-empty-cell'}" style="text-align: right;"
              ${isNonZero ? `data-run-id="${state.currentRunId}" data-cc-id="${col.cost_centre_id}" data-cc-name="${escapeHtml(col.cost_centre_name)}" data-amount="${amt}" title="Click to view contributing source records (${cnt} records)"` : ''}>
            <div style="font-weight: 700;">${formatINR(amt, false, true)}</div>
            ${cnt > 0 ? `<div style="font-size: 0.7rem; color: var(--c360-royal-blue);">${cnt} ${cnt === 1 ? 'record' : 'records'}</div>` : ''}
          </td>
        `;
      });

      html += `
            <td style="text-align: right; font-weight: 800; font-size: 0.95rem; background: #EEF4FA; color: var(--c360-royal-blue);">
              ${formatINR(grandTotal, false, true)}
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="qr-totals-row">
            <th class="qr-sticky-col" style="font-weight: 800; color: var(--c360-navy-deep); background: #F1F5F9;">TOTAL ALLOCATION</th>
      `;

      columns.forEach(col => {
        const agg = aggregates[col.cost_centre_id] || { total_amount: 0 };
        const colTotal = parseFloat(agg.total_amount) || 0;
        html += `
          <th style="text-align: right; font-weight: 800; color: var(--c360-navy-deep); background: #F1F5F9; font-size: 0.88rem;">
            ${formatINR(colTotal, false, true)}
          </th>
        `;
      });

      html += `
            <th style="text-align: right; font-weight: 900; color: var(--c360-royal-blue); background: #E2EEF9; font-size: 0.98rem;">
              ${formatINR(grandTotal, false, true)}
            </th>
          </tr>
        </tfoot>
      `;
    }

    html += `
          </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;font-size:0.78rem;color:var(--c360-text-muted);border-top:1px solid var(--c360-border);background:#FAFAFA;">
          <div>💡 <em>Structure aligned with Plant sheet (ANAME, ACODE, P&amp;L, CLOSING, Costing Basis, Nature, [Dynamic Cost Centres], Total, Check). Click any allocated amount to drill down.</em></div>
          <div>Dynamic Cost Centres resolved from <strong>${escapeHtml(data.plant.plant_name)}</strong> Master</div>
        </div>
      </div>
    `;

    container.innerHTML = html;

    // Attach Click Handlers on Amount Cells for Source Traceability
    container.querySelectorAll('.qr-clickable-cell').forEach(cell => {
      cell.addEventListener('click', function() {
        const runId = parseInt(this.getAttribute('data-run-id'), 10);
        const ccId = parseInt(this.getAttribute('data-cc-id'), 10);
        const ccName = this.getAttribute('data-cc-name') || 'Cost Centre';
        const amount = parseFloat(this.getAttribute('data-amount')) || 0;
        if (runId && ccId) {
          openTraceabilityModal(runId, ccId, ccName, amount);
        }
      });
    });

    // Wire Toolbar Controls
    const searchInput = document.getElementById('qrSearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function() {
        state.searchQuery = this.value.trim().toLowerCase();
        renderTable();
      });
    }

    const basisFilter = document.getElementById('qrCostingBasisFilter');
    if (basisFilter) {
      basisFilter.addEventListener('change', function() {
        state.costingBasisFilter = this.value;
        renderTable();
      });
    }

    const natureFilter = document.getElementById('qrNatureFilter');
    if (natureFilter) {
      natureFilter.addEventListener('change', function() {
        state.natureFilter = this.value;
        renderTable();
      });
    }

    const checkFilter = document.getElementById('qrCheckFilter');
    if (checkFilter) {
      checkFilter.addEventListener('change', function() {
        state.checkFilter = this.value;
        renderTable();
      });
    }

    const viewExcelBtn = document.getElementById('qrViewExcelBtn');
    const viewSummaryBtn = document.getElementById('qrViewSummaryBtn');
    if (viewExcelBtn && viewSummaryBtn) {
      viewExcelBtn.addEventListener('click', () => {
        state.viewMode = 'excel';
        renderTable();
      });
      viewSummaryBtn.addEventListener('click', () => {
        state.viewMode = 'summary';
        renderTable();
      });
    }

    const exportCsvBtn = document.getElementById('qrExportCsvBtn');
    if (exportCsvBtn) {
      exportCsvBtn.addEventListener('click', exportMatrixToCsv);
    }
  }

  // Source Traceability Modal (Audit Drill-down)
  async function openTraceabilityModal(runId, costCentreId, costCentreName, targetAmount) {
    const modal = document.getElementById('qrTraceModal');
    const titleEl = document.getElementById('qrTraceModalTitle');
    const subtitleEl = document.getElementById('qrTraceModalSubtitle');
    const bodyEl = document.getElementById('qrTraceModalBody');
    if (!modal || !bodyEl) return;

    titleEl.textContent = `Source Traceability — ${costCentreName}`;
    subtitleEl.textContent = `Auditing allocation of ${formatINR(targetAmount)} across source records`;
    bodyEl.innerHTML = `
      <div style="text-align: center; padding: 3rem 1rem;">
        <div class="c360-spinner" style="margin: 0 auto 1rem auto;"></div>
        <p style="color: var(--c360-text-secondary); font-weight: 600;">Querying source transactions &amp; audit trails...</p>
      </div>
    `;

    modal.style.display = 'flex';

    try {
      const url = `${API_ENDPOINT}?action=trace_source&run_id=${runId}&cost_centre_id=${costCentreId}&t=${Date.now()}`;
      const response = await fetch(url);
      const res = await response.json();

      if (!res.ok) {
        bodyEl.innerHTML = `<div class="empty-state" style="color:#DC2626;">Error: ${escapeHtml(res.error || 'Failed to retrieve source records')}</div>`;
        return;
      }

      const records = res.source_records || [];
      if (records.length === 0) {
        bodyEl.innerHTML = `<div class="empty-state">No source records found for this Cost Centre in this run.</div>`;
        return;
      }

      let subtotal = 0;
      let rowsHtml = '';
      records.forEach((rec, idx) => {
        const amt = parseFloat(rec.amount) || 0;
        subtotal += amt;
        rowsHtml += `
          <tr>
            <td><code>${escapeHtml(rec.source_row_ref || 'ROW-' + (idx+1))}</code></td>
            <td><strong>${escapeHtml(rec.source_description || '—')}</strong></td>
            <td><span class="qr-badge-pill" style="background:#EBF4FC;color:var(--c360-royal-blue);">${escapeHtml(rec.source_cost_basis || '—')}</span></td>
            <td><code>${escapeHtml(rec.source || 'TB')}</code></td>
            <td>${escapeHtml(rec.account_code || rec.item_code || '—')}</td>
            <td style="text-align:right; font-weight:700; color:var(--c360-navy-deep);">${formatINR(amt)}</td>
          </tr>
        `;
      });

      bodyEl.innerHTML = `
        <div style="background:#F0F7FD; border:1px solid #C8DEF2; border-radius:8px; padding:0.85rem 1.25rem; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
          <div>
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--c360-royal-blue); font-weight:700;">Contributing Records</div>
            <div style="font-size:1.05rem; font-weight:800; color:var(--c360-navy-deep);">${records.length} ${records.length === 1 ? 'Source Record' : 'Source Records'}</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--c360-text-secondary); font-weight:700;">Verified Subtotal</div>
            <div style="font-size:1.15rem; font-weight:900; color:var(--c360-royal-blue);">${formatINR(subtotal)}</div>
          </div>
        </div>

        <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
          <table>
            <thead>
              <tr>
                <th>Reference</th>
                <th>Description</th>
                <th>Cost Basis</th>
                <th>Source Feed</th>
                <th>Account / Item</th>
                <th style="text-align:right;">Amount</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml}
            </tbody>
            <tfoot>
              <tr style="background:#F8FAFC; font-weight:800;">
                <td colspan="5">Subtotal</td>
                <td style="text-align:right; color:var(--c360-royal-blue);">${formatINR(subtotal)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      `;

    } catch (err) {
      bodyEl.innerHTML = `<div class="empty-state" style="color:#DC2626;">Network error: ${escapeHtml(err.message)}</div>`;
    }
  }

  // Unmapped Exceptions Modal
  async function openExceptionsModal() {
    const modal = document.getElementById('qrExceptionsModal');
    const bodyEl = document.getElementById('qrExceptionsModalBody');
    if (!modal || !bodyEl) return;

    if (!state.currentRunId && (!state.activePlantId || !state.activePeriodId)) {
      showNotification('warning', "Please generate or load a Quantity Reco run first.");
      return;
    }

    modal.style.display = 'flex';
    bodyEl.innerHTML = `
      <div style="text-align: center; padding: 3rem 1rem;">
        <div class="c360-spinner" style="margin: 0 auto 1rem auto;"></div>
        <p style="color: var(--c360-text-secondary); font-weight: 600;">Fetching unmapped exception records...</p>
      </div>
    `;

    try {
      const url = `${API_ENDPOINT}?action=get_exceptions&run_id=${state.currentRunId || ''}&plant_id=${state.activePlantId}&period_id=${state.activePeriodId}&t=${Date.now()}`;
      const response = await fetch(url);
      const res = await response.json();

      if (!res.ok) {
        bodyEl.innerHTML = `<div class="empty-state" style="color:#DC2626;">Error: ${escapeHtml(res.error || 'Failed to fetch exceptions')}</div>`;
        return;
      }

      const exceptions = res.exceptions || [];
      if (exceptions.length === 0) {
        bodyEl.innerHTML = `
          <div style="text-align: center; padding: 3rem 1rem; color: #059669;">
            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🎉</div>
            <h3 style="margin: 0 0 0.5rem 0; font-weight: 800; color: #065F46;">Zero Unmapped Exceptions!</h3>
            <p style="color: #047857; font-size: 0.9rem; max-width: 450px; margin: 0 auto;">All source transactions in this period were successfully resolved and allocated to configured Cost Centres.</p>
          </div>
        `;
        return;
      }

      let totalExcAmount = 0;
      let rowsHtml = '';
      exceptions.forEach((exc, idx) => {
        const amt = parseFloat(exc.amount) || 0;
        totalExcAmount += amt;
        rowsHtml += `
          <tr>
            <td><code>${escapeHtml(exc.source_reference || 'REF-' + (idx+1))}</code></td>
            <td><strong>${escapeHtml(exc.source_description || '—')}</strong></td>
            <td><span class="qr-badge-pill" style="background:#FEF3C7;color:#92400E;font-weight:700;">${escapeHtml(exc.cost_basis || 'Unspecified')}</span></td>
            <td style="text-align:right; font-weight:700; color:#DC2626;">${formatINR(amt)}</td>
            <td><span style="font-size:0.8rem;color:#B91C1C;">${escapeHtml(exc.reason || 'Unmapped')}</span></td>
            <td><span class="status-badge" style="color:#DC2626;border-color:#FCA5A5;background:#FEF2F2;">${escapeHtml(exc.status || 'UNMAPPED')}</span></td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <button type="button" class="btn-secondary qr-map-inline-btn" 
                        data-basis="${escapeHtml(exc.cost_basis)}"
                        title="Map this Cost Basis 1-to-1 to a Cost Centre"
                        style="font-size:0.75rem;padding:0.25rem 0.55rem;background:#F0FDF4;border-color:#86EFAC;color:#166534;">
                  ➕ Map 1-to-1
                </button>
                <button type="button" class="btn-secondary qr-alloc-inline-btn"
                        data-acode="${escapeHtml(exc.account_code || '')}"
                        data-basis="${escapeHtml(exc.cost_basis || 'Dept')}"
                        title="Configure multi-Cost Centre ratio or driver split for this account"
                        style="font-size:0.75rem;padding:0.25rem 0.55rem;background:#F5F3FF;border-color:#C4B5FD;color:#6D28D9;">
                  📋 Split Rule
                </button>
              </div>
            </td>
          </tr>
        `;
      });

      bodyEl.innerHTML = `
        <div style="background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; padding:0.85rem 1.25rem; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
          <div>
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#92400E; font-weight:700;">Unmapped Transactions</div>
            <div style="font-size:1.05rem; font-weight:800; color:#78350F;">${exceptions.length} ${exceptions.length === 1 ? 'Record' : 'Records'} Awaiting Mapping</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#92400E; font-weight:700;">Unallocated Total</div>
            <div style="font-size:1.15rem; font-weight:900; color:#DC2626;">${formatINR(totalExcAmount)}</div>
          </div>
        </div>
        <p style="font-size:0.84rem;color:var(--c360-text-secondary);margin-bottom:0.85rem;">
          These records were <strong>not silently discarded</strong> or randomly allocated. Click <strong>"Map 1-to-1"</strong> for direct Cost Basis mappings, or <strong>"Split Rule"</strong> to configure multi-Cost Centre percentage distributions or driver rules.
        </p>

        <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
          <table>
            <thead>
              <tr>
                <th>Reference</th>
                <th>Description</th>
                <th>Cost Basis</th>
                <th style="text-align:right;">Amount</th>
                <th>Diagnostic Reason</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml}
            </tbody>
          </table>
        </div>
      `;

      // Attach inline mapping click handlers
      bodyEl.querySelectorAll('.qr-map-inline-btn').forEach(btn => {
        btn.addEventListener('click', function() {
          const basis = this.getAttribute('data-basis');
          closeAllModals();
          openMappingsModal(basis);
        });
      });

      // Attach inline allocation rule click handlers
      bodyEl.querySelectorAll('.qr-alloc-inline-btn').forEach(btn => {
        btn.addEventListener('click', function() {
          const acode = this.getAttribute('data-acode');
          const basis = this.getAttribute('data-basis');
          closeAllModals();
          openAllocRulesModal(acode, basis);
        });
      });

    } catch (err) {
      bodyEl.innerHTML = `<div class="empty-state" style="color:#DC2626;">Network error: ${escapeHtml(err.message)}</div>`;
    }
  }

  // Cost Basis Mappings Management Modal
  async function openMappingsModal(prefillBasis = null) {
    const modal = document.getElementById('qrMappingsModal');
    const bodyEl = document.getElementById('qrMappingsModalBody');
    if (!modal || !bodyEl) return;

    if (!state.activePlantId) {
      showNotification('warning', "Please select a Plant first to manage mappings.");
      return;
    }

    modal.style.display = 'flex';
    bodyEl.innerHTML = `
      <div style="text-align: center; padding: 3rem 1rem;">
        <div class="c360-spinner" style="margin: 0 auto 1rem auto;"></div>
        <p style="color: var(--c360-text-secondary); font-weight: 600;">Loading Cost Basis mappings...</p>
      </div>
    `;

    try {
      // 1. Fetch current plant cost centres
      const ccUrl = `${API_ENDPOINT}?action=get_cost_centres&plant_id=${state.activePlantId}&all=1&t=${Date.now()}`;
      const ccResp = await fetch(ccUrl);
      const ccData = await ccResp.json();
      const plantCCs = ccData.cost_centres || [];

      // 2. Fetch current plant mappings
      const mapUrl = `${API_ENDPOINT}?action=get_mappings&plant_id=${state.activePlantId}&all=1&t=${Date.now()}`;
      const mapResp = await fetch(mapUrl);
      const mapData = await mapResp.json();
      const mappings = mapData.mappings || [];

      let ccOptionsHtml = '';
      plantCCs.forEach(cc => {
        ccOptionsHtml += `<option value="${cc.id}">${escapeHtml(cc.cost_center_name)} (${escapeHtml(cc.cost_center_code)})</option>`;
      });

      let mappingsRowsHtml = '';
      if (mappings.length === 0) {
        mappingsRowsHtml = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--c360-text-muted);">No mappings configured for this Plant yet.</td></tr>`;
      } else {
        mappings.forEach((m, idx) => {
          mappingsRowsHtml += `
            <tr>
              <td><code>${idx + 1}</code></td>
              <td><strong style="color:var(--c360-navy-deep);">${escapeHtml(m.source_cost_basis)}</strong></td>
              <td>
                <span class="qr-badge-pill" style="background:#EBF4FC;color:var(--c360-royal-blue);font-weight:700;">
                  ${escapeHtml(m.cost_centre_name || m.cost_center_name)}
                </span>
                <span style="font-size:0.75rem;color:var(--c360-text-muted);margin-left:4px;">(${escapeHtml(m.cost_centre_code || m.cost_center_code)})</span>
              </td>
              <td><code>${m.priority}</code></td>
              <td>
                <span class="status-badge" style="${m.is_active ? 'color:#059669;border-color:#A7F3D0;background:#ECFDF5;' : 'color:#DC2626;border-color:#FCA5A5;background:#FEF2F2;'}">
                  ${m.is_active ? 'ACTIVE' : 'INACTIVE'}
                </span>
              </td>
              <td style="text-align:right;">
                <button type="button" class="btn-secondary qr-delete-map-btn" data-id="${m.mapping_id}" style="font-size:0.75rem;padding:0.25rem 0.55rem;color:#DC2626;border-color:#FCA5A5;">
                  🗑️ Delete
                </button>
              </td>
            </tr>
          `;
        });
      }

      bodyEl.innerHTML = `
        <!-- Add / Update Form Card -->
        <div style="background:#F8FAFC;border:1px solid var(--c360-border);border-radius:8px;padding:1.15rem;margin-bottom:1.5rem;">
          <div style="font-weight:700;font-size:0.92rem;color:var(--c360-navy-deep);margin-bottom:0.75rem;display:flex;align-items:center;gap:0.4rem;">
            <span>➕ Configure New Cost Basis Mapping</span>
          </div>
          <form id="qrMappingForm" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;align-items:flex-end;">
            <div>
              <label style="display:block;font-size:0.78rem;font-weight:600;color:var(--c360-text-secondary);margin-bottom:0.3rem;">Source Cost Basis *</label>
              <input type="text" id="mapInputBasis" class="c360-input" required placeholder="e.g. Electricity, Power, RM" value="${escapeHtml(prefillBasis || '')}" style="width:100%;">
            </div>
            <div>
              <label style="display:block;font-size:0.78rem;font-weight:600;color:var(--c360-text-secondary);margin-bottom:0.3rem;">Target Cost Centre *</label>
              <select id="mapSelectCC" class="c360-select" required style="width:100%;">
                <option value="">Select Plant Cost Centre...</option>
                ${ccOptionsHtml}
              </select>
            </div>
            <div style="max-width:110px;">
              <label style="display:block;font-size:0.78rem;font-weight:600;color:var(--c360-text-secondary);margin-bottom:0.3rem;">Priority</label>
              <input type="number" id="mapInputPriority" class="c360-input" value="10" min="1" max="999" style="width:100%;">
            </div>
            <div>
              <button type="submit" class="btn-primary" style="height:38px;width:100%;">
                💾 Save Mapping
              </button>
            </div>
          </form>
        </div>

        <!-- Existing Mappings List -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
          <h4 style="margin:0;font-size:0.95rem;font-weight:700;color:var(--c360-navy-deep);">Configured Mappings (${mappings.length})</h4>
          <span style="font-size:0.78rem;color:var(--c360-text-muted);">Scoped to selected Plant</span>
        </div>

        <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
          <table>
            <thead>
              <tr>
                <th style="width:40px;">#</th>
                <th>Source Cost Basis</th>
                <th>Target Cost Centre</th>
                <th>Priority</th>
                <th>Status</th>
                <th style="text-align:right;">Action</th>
              </tr>
            </thead>
            <tbody>
              ${mappingsRowsHtml}
            </tbody>
          </table>
        </div>
      `;

      // Handle Save Mapping Form Submit
      const form = document.getElementById('qrMappingForm');
      if (form) {
        form.addEventListener('submit', async function(e) {
          e.preventDefault();
          const basis = document.getElementById('mapInputBasis').value.trim();
          const ccId = parseInt(document.getElementById('mapSelectCC').value, 10);
          const priority = parseInt(document.getElementById('mapInputPriority').value, 10) || 10;

          if (!basis || !ccId) {
            alert('Please specify both Source Cost Basis and Target Cost Centre.');
            return;
          }

          const savePayload = {
            action: 'save_mapping',
            plant_id: state.activePlantId,
            source_cost_basis: basis,
            cost_centre_id: ccId,
            priority: priority,
            is_active: true
          };

          const resp = await fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(savePayload)
          });
          const res = await resp.json();

          if (res.ok) {
            showNotification('success', `Mapping for "${basis}" saved successfully.`);
            openMappingsModal();
          } else {
            alert('Error saving mapping: ' + (res.error || 'Unknown error'));
          }
        });
      }

      // Handle Delete Mapping Click
      bodyEl.querySelectorAll('.qr-delete-map-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
          const mappingId = parseInt(this.getAttribute('data-id'), 10);
          if (!confirm('Are you sure you want to delete this Cost Basis mapping?')) return;

          const delPayload = new FormData();
          delPayload.append('action', 'delete_mapping');
          delPayload.append('mapping_id', mappingId);

          const resp = await fetch(API_ENDPOINT, { method: 'POST', body: delPayload });
          const res = await resp.json();

          if (res.ok) {
            showNotification('success', 'Mapping deleted successfully.');
            openMappingsModal();
          } else {
            alert('Error deleting mapping: ' + (res.error || 'Unknown error'));
          }
        });
      });

    } catch (err) {
      bodyEl.innerHTML = `<div class="empty-state" style="color:#DC2626;">Network error: ${escapeHtml(err.message)}</div>`;
    }
  }

  // Department Allocation Rules Management Modal (Phase 3.3)
  async function openAllocRulesModal(prefillAccount = null, prefillBasis = null) {
    const modal = document.getElementById('qrAllocRulesModal');
    const bodyEl = document.getElementById('qrAllocRulesModalBody');
    if (!modal || !bodyEl) return;

    if (!state.activePlantId) {
      showNotification('warning', "Please select a Plant first to manage allocation rules.");
      return;
    }

    modal.style.display = 'flex';
    bodyEl.innerHTML = `
      <div style="text-align: center; padding: 3rem 1rem;">
        <div class="c360-spinner" style="margin: 0 auto 1rem auto;"></div>
        <p style="color: var(--c360-text-secondary); font-weight: 600;">Loading Department Allocation Rules...</p>
      </div>
    `;

    try {
      // 1. Fetch current plant Cost Centres
      const ccUrl = `${API_ENDPOINT}?action=get_cost_centres&plant_id=${state.activePlantId}&all=1&t=${Date.now()}`;
      const ccResp = await fetch(ccUrl);
      const ccData = await ccResp.json();
      const plantCCs = ccData.cost_centres || [];

      // 2. Fetch current plant allocation rules
      const rulesUrl = `${API_ENDPOINT}?action=get_allocation_rules&plant_id=${state.activePlantId}&all=1&t=${Date.now()}`;
      const rulesResp = await fetch(rulesUrl);
      const rulesData = await rulesResp.json();
      const allRules = rulesData.rules || [];

      // Update count badge
      const countBadge = document.getElementById('qrAllocRulesCountBadge');
      if (countBadge) {
        countBadge.textContent = `${allRules.length} RULES`;
      }

      // Group rules by account_code + period_id + method
      const groupedRules = {};
      allRules.forEach(r => {
        const pKey = r.period_id ? `p_${r.period_id}` : 'global';
        const aKey = (r.account_code || r.cost_basis || 'UNKNOWN').trim();
        const mKey = r.allocation_method || 'RATIO';
        const groupKey = `${aKey}___${pKey}___${mKey}`;

        if (!groupedRules[groupKey]) {
          groupedRules[groupKey] = {
            groupKey: groupKey,
            plant_id: r.plant_id,
            period_id: r.period_id,
            period_name: r.period_name || 'All Periods (Global Scope)',
            account_code: r.account_code || '',
            cost_basis: r.cost_basis || 'Dept',
            allocation_method: mKey,
            driver_account_code: r.driver_account_code || '',
            effective_from: r.effective_from || '',
            effective_to: r.effective_to || '',
            is_active: r.is_active,
            splits: []
          };
        }

        if (r.target_cost_centre_id) {
          groupedRules[groupKey].splits.push({
            rule_id: r.rule_id,
            target_cost_centre_id: r.target_cost_centre_id,
            cost_center_code: r.cost_center_code,
            cost_center_name: r.cost_center_name,
            allocation_ratio: parseFloat(r.allocation_ratio) || 0,
            display_order: r.display_order || 0
          });
        } else {
          groupedRules[groupKey].rule_id = r.rule_id;
        }
      });

      // Cost Centre options for select dropdowns
      let ccOptionsHtml = '';
      plantCCs.forEach(cc => {
        ccOptionsHtml += `<option value="${cc.id}">${escapeHtml(cc.cost_center_name)} (${escapeHtml(cc.cost_center_code)})</option>`;
      });

      // Accessible periods for scope dropdown
      const periodSelect = document.getElementById('qrPeriodSelect');
      let periodOptionsHtml = '<option value="">All Periods (Global Scope)</option>';
      if (periodSelect) {
        Array.from(periodSelect.options).forEach(opt => {
          if (opt.value) {
            periodOptionsHtml += `<option value="${escapeHtml(opt.value)}">${escapeHtml(opt.textContent)}</option>`;
          }
        });
      }

      // Accounts datalist for autocomplete
      let accountDatalistHtml = '';
      if (state.matrixData && state.matrixData.rows) {
        const seen = new Set();
        state.matrixData.rows.forEach(r => {
          if (r.acode && !seen.has(r.acode)) {
            seen.add(r.acode);
            accountDatalistHtml += `<option value="${escapeHtml(r.acode)}">${escapeHtml(r.aname || '')}</option>`;
          }
        });
      }

      // Generate HTML for configured rules table
      const groupsList = Object.values(groupedRules);
      let rulesTableRowsHtml = '';

      if (groupsList.length === 0) {
        rulesTableRowsHtml = `
          <tr>
            <td colspan="7" style="text-align: center; padding: 2.5rem 1rem; color: var(--c360-text-muted);">
              No allocation rules configured for this Plant yet. Configure an account rule below.
            </td>
          </tr>
        `;
      } else {
        groupsList.forEach((g, idx) => {
          let distHtml = '';
          if (g.allocation_method === 'DRIVER_ACCOUNT') {
            distHtml = `
              <div style="display:flex;align-items:center;gap:0.4rem;">
                <span class="qr-badge-pill" style="background:#FEF3C7;color:#92400E;font-weight:700;">DRIVER ACCOUNT</span>
                <span style="font-size:0.84rem;color:var(--c360-navy-deep);">
                  Proportional to <strong>${escapeHtml(g.driver_account_code)}</strong>
                </span>
              </div>
            `;
          } else {
            let sumP = 0;
            const chips = g.splits.map(sp => {
              const pct = sp.allocation_ratio * 100;
              sumP += pct;
              return `<span class="qr-badge-pill" style="background:#EDE9FE;color:#5B21B6;margin:2px 3px 2px 0;">
                ${escapeHtml(sp.cost_center_name || 'CC')}: <strong>${pct.toFixed(2)}%</strong>
              </span>`;
            }).join('');

            const isBalanced = Math.abs(sumP - 100.0) < 0.05;
            const balBadge = isBalanced
              ? `<span class="qr-badge-pill" style="background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0;font-size:0.72rem;margin-left:4px;">100% Balanced</span>`
              : `<span class="qr-badge-pill" style="background:#FEF2F2;color:#991B1B;border:1px solid #FCA5A5;font-size:0.72rem;margin-left:4px;">Sum: ${sumP.toFixed(2)}%</span>`;

            distHtml = `<div style="display:flex;flex-wrap:wrap;align-items:center;">${chips} ${balBadge}</div>`;
          }

          const scopeLabel = g.period_id 
            ? `<span class="qr-badge-pill" style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;">${escapeHtml(g.period_name)}</span>`
            : `<span class="qr-badge-pill" style="background:#F1F5F9;color:#475569;border:1px solid #CBD5E1;">All Periods</span>`;

          const datesLabel = (g.effective_from || g.effective_to)
            ? `<span style="font-size:0.75rem;color:var(--c360-text-secondary);">${escapeHtml(g.effective_from || '—')} to ${escapeHtml(g.effective_to || 'Ongoing')}</span>`
            : `<span style="font-size:0.75rem;color:var(--c360-text-muted);">Active Always</span>`;

          rulesTableRowsHtml += `
            <tr data-group-key="${escapeHtml(g.groupKey)}">
              <td><code>${idx + 1}</code></td>
              <td>${scopeLabel}</td>
              <td>
                <strong style="color:var(--c360-navy-deep);">${escapeHtml(g.account_code)}</strong>
                <div style="font-size:0.75rem;color:var(--c360-text-muted);">${escapeHtml(g.cost_basis)}</div>
              </td>
              <td>
                <span class="qr-badge-pill" style="${g.allocation_method === 'DRIVER_ACCOUNT' ? 'background:#FEF3C7;color:#92400E;' : 'background:#EDE9FE;color:#5B21B6;'}font-weight:700;">
                  ${escapeHtml(g.allocation_method)}
                </span>
              </td>
              <td>${distHtml}</td>
              <td>
                <button type="button" class="status-badge qr-toggle-active-rule-btn" data-group-key="${escapeHtml(g.groupKey)}" 
                        style="cursor:pointer;border-radius:12px;padding:2px 8px;font-size:0.72rem;font-weight:700;${g.is_active ? 'color:#059669;border:1px solid #A7F3D0;background:#ECFDF5;' : 'color:#DC2626;border:1px solid #FCA5A5;background:#FEF2F2;'}"
                        title="Click to toggle Active/Inactive">
                  ${g.is_active ? '● ACTIVE' : '○ INACTIVE'}
                </button>
                <div style="margin-top:2px;">${datesLabel}</div>
              </td>
              <td style="text-align:right;white-space:nowrap;">
                <button type="button" class="btn-secondary qr-edit-rule-btn" data-group-key="${escapeHtml(g.groupKey)}" style="font-size:0.75rem;padding:0.25rem 0.55rem;margin-right:4px;">
                  ✏️ Edit
                </button>
                <button type="button" class="btn-secondary qr-delete-rule-btn" data-group-key="${escapeHtml(g.groupKey)}" style="font-size:0.75rem;padding:0.25rem 0.55rem;color:#DC2626;border-color:#FCA5A5;">
                  🗑️ Delete
                </button>
              </td>
            </tr>
          `;
        });
      }

      bodyEl.innerHTML = `
        <datalist id="arAccountDatalist">
          ${accountDatalistHtml}
        </datalist>

        <!-- Form Card for Create / Edit -->
        <div id="arFormCard" style="background:#FAF5FF;border:1px solid #DDD6FE;border-radius:10px;padding:1.25rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(124,58,237,0.06);">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.85rem;border-bottom:1px solid #EDE9FE;padding-bottom:0.5rem;">
            <div style="font-weight:800;font-size:0.98rem;color:#5B21B6;display:flex;align-items:center;gap:0.4rem;">
              <span id="arFormTitle">➕ Configure New Allocation Rule</span>
            </div>
            <button type="button" id="arResetFormBtn" class="btn-secondary" style="font-size:0.75rem;padding:0.2rem 0.6rem;">
              ↺ Reset Form
            </button>
          </div>

          <form id="qrAllocRuleForm">
            <!-- Top Row: Account, Cost Basis, Scope, Method -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.85rem;margin-bottom:1rem;">
              <div>
                <label style="display:block;font-size:0.78rem;font-weight:700;color:#5B21B6;margin-bottom:0.3rem;">Account Code *</label>
                <input type="text" id="arInputAccount" class="c360-input" required list="arAccountDatalist" placeholder="e.g. 5000413, 5000493" value="${escapeHtml(prefillAccount || '')}" style="width:100%;font-weight:600;">
              </div>
              <div>
                <label style="display:block;font-size:0.78rem;font-weight:700;color:#5B21B6;margin-bottom:0.3rem;">Cost Basis</label>
                <input type="text" id="arInputBasis" class="c360-input" value="${escapeHtml(prefillBasis || 'Dept')}" placeholder="e.g. Dept" style="width:100%;">
              </div>
              <div>
                <label style="display:block;font-size:0.78rem;font-weight:700;color:#5B21B6;margin-bottom:0.3rem;">Period Scope</label>
                <select id="arSelectPeriod" class="c360-select" style="width:100%;">
                  ${periodOptionsHtml}
                </select>
              </div>
              <div>
                <label style="display:block;font-size:0.78rem;font-weight:700;color:#5B21B6;margin-bottom:0.3rem;">Allocation Method *</label>
                <select id="arSelectMethod" class="c360-select" style="width:100%;font-weight:700;">
                  <option value="RATIO">RATIO — Percentage Split Across Cost Centres</option>
                  <option value="DRIVER_ACCOUNT">DRIVER_ACCOUNT — Proportional to Driver Account</option>
                </select>
              </div>
            </div>

            <!-- Method Section A: RATIO Multi-Cost Centre Splits -->
            <div id="arRatioSection" style="background:#FFFFFF;border:1px solid #E9D5FF;border-radius:8px;padding:1rem;margin-bottom:1rem;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                <div style="font-size:0.85rem;font-weight:700;color:#5B21B6;">Target Cost Centre Splits:</div>
                <div style="display:flex;align-items:center;gap:0.6rem;">
                  <span id="arRatioTotalBadge" class="qr-badge-pill" style="background:#FEF3C7;color:#92400E;font-size:0.8rem;padding:3px 8px;font-weight:800;">
                    Total: 0.00%
                  </span>
                  <button type="button" id="arAddSplitRowBtn" class="btn-secondary" style="font-size:0.78rem;padding:0.25rem 0.65rem;background:#FAF5FF;border-color:#C4B5FD;color:#6D28D9;font-weight:700;">
                    ➕ Add Cost Centre Split
                  </button>
                </div>
              </div>

              <div id="arSplitsContainer" style="display:flex;flex-direction:column;gap:0.5rem;max-height:220px;overflow-y:auto;padding-right:4px;">
                <!-- Dynamic split rows injected here -->
              </div>
            </div>

            <!-- Method Section B: DRIVER_ACCOUNT Config -->
            <div id="arDriverSection" style="display:none;background:#FFFFFF;border:1px solid #FDE68A;border-radius:8px;padding:1rem;margin-bottom:1rem;">
              <div style="font-size:0.85rem;font-weight:700;color:#92400E;margin-bottom:0.4rem;">Driver Account Configuration:</div>
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:0.85rem;align-items:center;">
                <div>
                  <label style="display:block;font-size:0.78rem;font-weight:600;color:#78350F;margin-bottom:0.25rem;">Driver Account Code *</label>
                  <input type="text" id="arInputDriver" class="c360-input" list="arAccountDatalist" placeholder="e.g. 5000413 (SALARY A/C)" style="width:100%;font-weight:600;">
                </div>
                <div style="font-size:0.8rem;color:#78350F;line-height:1.4;background:#FEF3C7;padding:0.6rem;border-radius:6px;border:1px solid #FCD34D;">
                  💡 The closing amount of this account will be allocated across Cost Centres in exact proportion to the driver account's allocations in the active run.
                </div>
              </div>
            </div>

            <!-- Bottom Row: Dates, Active, and Submit -->
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.85rem;">
              <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                <div style="display:inline-flex;align-items:center;gap:0.35rem;">
                  <label style="font-size:0.78rem;font-weight:600;color:#5B21B6;">Effective From:</label>
                  <input type="date" id="arInputFrom" class="c360-input" value="${new Date().toISOString().slice(0,10)}" style="font-size:0.8rem;padding:0.25rem 0.5rem;">
                </div>
                <div style="display:inline-flex;align-items:center;gap:0.35rem;">
                  <label style="font-size:0.78rem;font-weight:600;color:#5B21B6;">Effective To:</label>
                  <input type="date" id="arInputTo" class="c360-input" style="font-size:0.8rem;padding:0.25rem 0.5rem;">
                </div>
                <div style="display:inline-flex;align-items:center;gap:0.35rem;">
                  <label style="font-size:0.8rem;font-weight:700;color:#5B21B6;cursor:pointer;">
                    <input type="checkbox" id="arCheckActive" checked style="margin-right:4px;">
                    Active Rule
                  </label>
                </div>
              </div>

              <div>
                <button type="submit" id="arSaveSubmitBtn" class="btn-primary" style="background:#7C3AED;border-color:#6D28D9;font-weight:700;padding:0.5rem 1.4rem;">
                  💾 Save Allocation Rule
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- List of Configured Allocation Rules Table -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
          <h4 style="margin:0;font-size:0.95rem;font-weight:800;color:var(--c360-navy-deep);">
            Configured Plant Allocation Rules (${groupsList.length} Accounts)
          </h4>
          <span style="font-size:0.78rem;color:var(--c360-text-muted);">Scoped to active Plant</span>
        </div>

        <div class="table-responsive" style="max-height:360px;overflow-y:auto;border:1px solid var(--c360-border);border-radius:8px;">
          <table>
            <thead>
              <tr>
                <th style="width:35px;">#</th>
                <th style="width:130px;">Scope</th>
                <th style="width:140px;">Account &amp; Basis</th>
                <th style="width:130px;">Method</th>
                <th>Target Cost Centre Distribution</th>
                <th style="width:130px;">Status &amp; Dates</th>
                <th style="text-align:right;width:130px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              ${rulesTableRowsHtml}
            </tbody>
          </table>
        </div>
      `;

      // Helper to add split row
      const splitsContainer = document.getElementById('arSplitsContainer');
      const ratioBadge = document.getElementById('arRatioTotalBadge');

      function updateRatioTotal() {
        let total = 0;
        splitsContainer.querySelectorAll('.ar-split-row').forEach(row => {
          const input = row.querySelector('.ar-split-ratio');
          if (input) {
            const v = parseFloat(input.value) || 0;
            total += v;
          }
        });

        // Determine if total is balanced
        const isBalanced = (Math.abs(total - 100.0) < 0.05) || (Math.abs(total - 1.0) < 0.0005);
        if (isBalanced) {
          ratioBadge.style.background = '#ECFDF5';
          ratioBadge.style.color = '#065F46';
          ratioBadge.style.border = '1px solid #A7F3D0';
          ratioBadge.textContent = `✓ 100.00% Balanced`;
        } else if (total < 100.0 && total > 0) {
          ratioBadge.style.background = '#FFFBEB';
          ratioBadge.style.color = '#92400E';
          ratioBadge.style.border = '1px solid #FCD34D';
          ratioBadge.textContent = `Total: ${total.toFixed(2)}% (Under)`;
        } else if (total > 100.0) {
          ratioBadge.style.background = '#FEF2F2';
          ratioBadge.style.color = '#991B1B';
          ratioBadge.style.border = '1px solid #FCA5A5';
          ratioBadge.textContent = `Total: ${total.toFixed(2)}% (Over)`;
        } else {
          ratioBadge.style.background = '#F1F5F9';
          ratioBadge.style.color = '#475569';
          ratioBadge.style.border = '1px solid #CBD5E1';
          ratioBadge.textContent = `Total: 0.00%`;
        }
      }

      function addSplitRow(selectedCcId = '', ratioValue = '') {
        const row = document.createElement('div');
        row.className = 'ar-split-row';
        row.style.cssText = 'display:grid;grid-template-columns:1fr 140px 38px;gap:0.5rem;align-items:center;background:#F8FAFC;padding:0.4rem 0.6rem;border-radius:6px;border:1px solid #E2E8F0;';
        row.innerHTML = `
          <div>
            <select class="c360-select ar-split-cc" required style="width:100%;font-size:0.82rem;padding:0.35rem 0.5rem;">
              <option value="">Select Target Cost Centre...</option>
              ${ccOptionsHtml}
            </select>
          </div>
          <div>
            <div style="display:flex;align-items:center;gap:3px;">
              <input type="number" step="any" min="0" max="100" class="c360-input ar-split-ratio" required placeholder="Ratio %" value="${ratioValue}" style="width:100%;font-size:0.82rem;padding:0.35rem 0.5rem;font-weight:600;">
              <span style="font-size:0.8rem;color:#5B21B6;font-weight:700;">%</span>
            </div>
          </div>
          <div>
            <button type="button" class="btn-secondary ar-remove-split-btn" style="padding:0.3rem 0.45rem;color:#DC2626;border-color:#FCA5A5;font-size:0.8rem;line-height:1;" title="Remove Split">
              &times;
            </button>
          </div>
        `;

        if (selectedCcId) {
          row.querySelector('.ar-split-cc').value = selectedCcId;
        }

        row.querySelector('.ar-split-ratio').addEventListener('input', updateRatioTotal);
        row.querySelector('.ar-remove-split-btn').addEventListener('click', function() {
          row.remove();
          updateRatioTotal();
        });

        splitsContainer.appendChild(row);
        updateRatioTotal();
      }

      // Add Split Button Listener
      const addSplitBtn = document.getElementById('arAddSplitRowBtn');
      if (addSplitBtn) {
        addSplitBtn.addEventListener('click', () => addSplitRow());
      }

      // Method change listener
      const methodSelect = document.getElementById('arSelectMethod');
      const ratioSection = document.getElementById('arRatioSection');
      const driverSection = document.getElementById('arDriverSection');

      methodSelect.addEventListener('change', function() {
        if (this.value === 'DRIVER_ACCOUNT') {
          ratioSection.style.display = 'none';
          driverSection.style.display = 'block';
        } else {
          ratioSection.style.display = 'block';
          driverSection.style.display = 'none';
          if (splitsContainer.children.length === 0) {
            addSplitRow();
          }
        }
      });

      // Default: add initial split row if none
      if (splitsContainer.children.length === 0) {
        addSplitRow();
      }

      // Reset Form listener
      const resetBtn = document.getElementById('arResetFormBtn');
      if (resetBtn) {
        resetBtn.addEventListener('click', function() {
          document.getElementById('arFormTitle').textContent = '➕ Configure New Allocation Rule';
          document.getElementById('arInputAccount').value = '';
          document.getElementById('arInputBasis').value = 'Dept';
          document.getElementById('arSelectPeriod').value = '';
          document.getElementById('arSelectMethod').value = 'RATIO';
          methodSelect.dispatchEvent(new Event('change'));
          splitsContainer.innerHTML = '';
          addSplitRow();
          document.getElementById('arInputFrom').value = new Date().toISOString().slice(0,10);
          document.getElementById('arInputTo').value = '';
          document.getElementById('arCheckActive').checked = true;
          document.getElementById('arSaveSubmitBtn').textContent = '💾 Save Allocation Rule';
        });
      }

      // Form Submit handler
      const form = document.getElementById('qrAllocRuleForm');
      if (form) {
        form.addEventListener('submit', async function(e) {
          e.preventDefault();

          const account = document.getElementById('arInputAccount').value.trim();
          const basis = document.getElementById('arInputBasis').value.trim() || 'Dept';
          const periodId = document.getElementById('arSelectPeriod').value ? parseInt(document.getElementById('arSelectPeriod').value, 10) : null;
          const method = document.getElementById('arSelectMethod').value;
          const effectiveFrom = document.getElementById('arInputFrom').value || null;
          const effectiveTo = document.getElementById('arInputTo').value || null;
          const isActive = document.getElementById('arCheckActive').checked;

          if (!account) {
            alert('Please enter an Account Code.');
            return;
          }

          let payload = {
            action: 'save_allocation_rule',
            plant_id: state.activePlantId,
            period_id: periodId,
            account_code: account,
            cost_basis: basis,
            allocation_method: method,
            effective_from: effectiveFrom,
            effective_to: effectiveTo,
            is_active: isActive
          };

          if (method === 'DRIVER_ACCOUNT') {
            const driverAccount = document.getElementById('arInputDriver').value.trim();
            if (!driverAccount) {
              alert('Please enter a Driver Account Code.');
              return;
            }
            if (driverAccount.toLowerCase() === account.toLowerCase()) {
              alert('Driver Account Code cannot be the same as the target Account Code.');
              return;
            }
            payload.driver_account_code = driverAccount;
            payload.allocation_ratio = 0;
            payload.target_cost_centre_id = null;
          } else {
            // RATIO splits validation
            const splitRows = splitsContainer.querySelectorAll('.ar-split-row');
            if (splitRows.length === 0) {
              alert('Please add at least one Target Cost Centre split.');
              return;
            }

            const splits = [];
            let totalRatio = 0;
            let hasEmptyCC = false;

            splitRows.forEach((row, idx) => {
              const ccId = parseInt(row.querySelector('.ar-split-cc').value, 10);
              let rVal = parseFloat(row.querySelector('.ar-split-ratio').value) || 0;
              if (!ccId) hasEmptyCC = true;
              totalRatio += rVal;
              splits.push({
                target_cost_centre_id: ccId,
                allocation_ratio: rVal,
                display_order: idx + 1
              });
            });

            if (hasEmptyCC) {
              alert('Please select a Target Cost Centre for each split row.');
              return;
            }

            // Ratio sum validation
            const isBalanced = (Math.abs(totalRatio - 100.0) < 0.05) || (Math.abs(totalRatio - 1.0) < 0.0005);
            if (!isBalanced) {
              alert(`Allocation ratios must sum to 100%. Current total: ${totalRatio.toFixed(2)}%`);
              return;
            }

            payload.splits = splits;
          }

          const saveBtn = document.getElementById('arSaveSubmitBtn');
          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving...';

          try {
            const resp = await fetch(API_ENDPOINT, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload)
            });
            const res = await resp.json();

            if (res.ok) {
              showNotification('success', `Allocation rule for account "${account}" saved successfully.`);
              openAllocRulesModal(); // Refresh modal content
            } else {
              alert('Error saving allocation rule: ' + (res.error || 'Unknown error'));
              saveBtn.disabled = false;
              saveBtn.textContent = '💾 Save Allocation Rule';
            }
          } catch (err) {
            alert('Network error: ' + err.message);
            saveBtn.disabled = false;
            saveBtn.textContent = '💾 Save Allocation Rule';
          }
        });
      }

      // Edit Button Handlers
      bodyEl.querySelectorAll('.qr-edit-rule-btn').forEach(btn => {
        btn.addEventListener('click', function() {
          const groupKey = this.getAttribute('data-group-key');
          const g = groupedRules[groupKey];
          if (!g) return;

          document.getElementById('arFormTitle').textContent = `✏️ Edit Allocation Rule — ${g.account_code}`;
          document.getElementById('arInputAccount').value = g.account_code;
          document.getElementById('arInputBasis').value = g.cost_basis;
          document.getElementById('arSelectPeriod').value = g.period_id || '';
          document.getElementById('arSelectMethod').value = g.allocation_method;
          methodSelect.dispatchEvent(new Event('change'));

          if (g.allocation_method === 'DRIVER_ACCOUNT') {
            document.getElementById('arInputDriver').value = g.driver_account_code;
          } else {
            splitsContainer.innerHTML = '';
            g.splits.forEach(sp => {
              const pctVal = (sp.allocation_ratio * 100).toFixed(4).replace(/\.?0+$/, '');
              addSplitRow(sp.target_cost_centre_id, pctVal);
            });
          }

          document.getElementById('arInputFrom').value = g.effective_from || new Date().toISOString().slice(0,10);
          document.getElementById('arInputTo').value = g.effective_to || '';
          document.getElementById('arCheckActive').checked = g.is_active;
          document.getElementById('arSaveSubmitBtn').textContent = '💾 Update Allocation Rule';

          // Smooth scroll to form card
          document.getElementById('arFormCard').scrollIntoView({ behavior: 'smooth' });
        });
      });

      // Toggle Active Status Handlers
      bodyEl.querySelectorAll('.qr-toggle-active-rule-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
          const groupKey = this.getAttribute('data-group-key');
          const g = groupedRules[groupKey];
          if (!g) return;

          const newActive = !g.is_active;
          const payload = {
            action: 'save_allocation_rule',
            plant_id: state.activePlantId,
            period_id: g.period_id,
            account_code: g.account_code,
            cost_basis: g.cost_basis,
            allocation_method: g.allocation_method,
            is_active: newActive
          };

          if (g.allocation_method === 'DRIVER_ACCOUNT') {
            payload.driver_account_code = g.driver_account_code;
            payload.rule_id = g.rule_id;
          } else {
            payload.splits = g.splits.map(sp => ({
              target_cost_centre_id: sp.target_cost_centre_id,
              allocation_ratio: sp.allocation_ratio,
              display_order: sp.display_order
            }));
          }

          const resp = await fetch(API_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          const res = await resp.json();

          if (res.ok) {
            showNotification('success', `Allocation rule status updated to ${newActive ? 'ACTIVE' : 'INACTIVE'}.`);
            openAllocRulesModal();
          } else {
            alert('Error updating status: ' + (res.error || 'Unknown error'));
          }
        });
      });

      // Delete Button Handlers
      bodyEl.querySelectorAll('.qr-delete-rule-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
          const groupKey = this.getAttribute('data-group-key');
          const g = groupedRules[groupKey];
          if (!g) return;

          if (!confirm(`Are you sure you want to delete the allocation rule for Account "${g.account_code}"?`)) return;

          const delPayload = new FormData();
          delPayload.append('action', 'delete_allocation_rule');
          delPayload.append('plant_id', state.activePlantId);
          delPayload.append('account_code', g.account_code);
          if (g.period_id) {
            delPayload.append('period_id', g.period_id);
          }

          const resp = await fetch(API_ENDPOINT, { method: 'POST', body: delPayload });
          const res = await resp.json();

          if (res.ok) {
            showNotification('success', `Allocation rule for "${g.account_code}" deleted successfully.`);
            openAllocRulesModal();
          } else {
            alert('Error deleting allocation rule: ' + (res.error || 'Unknown error'));
          }
        });
      });

    } catch (err) {
      bodyEl.innerHTML = `<div class="empty-state" style="color:#DC2626;">Network error: ${escapeHtml(err.message)}</div>`;
    }
  }

  // Close All Modals
  function closeAllModals() {
    document.querySelectorAll('.cost360-modal-overlay').forEach(modal => {
      modal.style.display = 'none';
    });
  }

  // Loading State Display
  function setLoading(isLoading, message = '') {
    state.isLoading = isLoading;
    const container = document.getElementById('qrMainContainer');
    if (!container) return;

    if (isLoading) {
      container.innerHTML = `
        <div class="qr-loading-card">
          <div class="c360-spinner" style="width: 48px; height: 48px; border: 4px solid rgba(8, 120, 209, 0.2); border-top-color: var(--c360-royal-blue); border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 1.25rem auto;"></div>
          <div style="font-size: 0.95rem; font-weight: 600; color: var(--c360-text-primary); max-width: 500px; margin: 0 auto;">
            ${message || 'Processing...'}
          </div>
        </div>
      `;
    }
  }

  // Empty State Display
  function renderEmptyState(message, icon = 'ℹ️') {
    const container = document.getElementById('qrMainContainer');
    if (!container) return;

    container.innerHTML = `
      <div class="qr-empty-card">
        <div style="font-size: 3rem; margin-bottom: 0.75rem;">${icon}</div>
        <h3 style="margin: 0 0 0.5rem 0; font-size: 1.15rem; font-weight: 700; color: var(--c360-navy-deep);">${escapeHtml(message)}</h3>
        <p style="margin: 0; color: var(--c360-text-muted); font-size: 0.88rem;">Please select a Plant and Period from the filters above to continue.</p>
      </div>
    `;
  }

  // Generation Required State Display
  function renderGenerationRequiredState() {
    const container = document.getElementById('qrMainContainer');
    if (!container) return;

    container.innerHTML = `
      <div class="qr-empty-card" style="border: 1.5px dashed #CBD5E1; background: #F8FAFC;">
        <div style="font-size: 3rem; margin-bottom: 0.75rem;">⚡</div>
        <h3 style="margin: 0 0 0.5rem 0; font-size: 1.2rem; font-weight: 800; color: var(--c360-navy-deep);">Quantity Reco Not Yet Generated</h3>
        <p style="margin: 0 0 1.5rem 0; color: var(--c360-text-secondary); font-size: 0.9rem; max-width: 480px;">
          Quantity Reconciliation has not been generated for the selected Plant and Period. Click the button below to execute allocation.
        </p>
        <button type="button" class="btn-primary" onclick="document.getElementById('qrGenerateBtn').click();" style="padding: 0.65rem 1.75rem; font-size: 0.92rem;">
          ⚡ Generate Quantity Reco Now
        </button>
      </div>
    `;
  }

  // Error State Display
  function renderErrorState(errorMessage) {
    const container = document.getElementById('qrMainContainer');
    if (!container) return;

    container.innerHTML = `
      <div class="qr-empty-card" style="border: 1px solid #FCA5A5; background: #FEF2F2;">
        <div style="font-size: 2.8rem; margin-bottom: 0.75rem;">⚠️</div>
        <h3 style="margin: 0 0 0.5rem 0; font-size: 1.15rem; font-weight: 800; color: #991B1B;">Unable to Load Quantity Reco</h3>
        <p style="margin: 0 0 1.25rem 0; color: #B91C1C; font-size: 0.9rem; max-width: 500px;">
          ${escapeHtml(errorMessage)}
        </p>
        <button type="button" class="btn-secondary" onclick="document.getElementById('qrRefreshBtn').click();" style="padding: 0.5rem 1.25rem;">
          ⟳ Retry Loading
        </button>
      </div>
    `;
  }

  // Export Matrix to CSV (Full plant.xlsx Structure)
  function exportMatrixToCsv() {
    if (!state.recoData) return;

    const data = state.recoData;
    const columns = data.columns || [];
    const rows = (data.rows && data.rows.length > 0) ? data.rows : (data.details || []);
    const totals = data.totals || {};
    const plantName = (data.plant ? data.plant.plant_name : 'Plant').replace(/[^a-zA-Z0-9_-]/g, '_');
    const periodName = (data.period ? data.period.period_name : 'Period').replace(/[^a-zA-Z0-9_-]/g, '_');

    let csv = '';
    // Header row
    const headers = [
      'ANAME',
      'ACODE',
      'P&L',
      'CLOSING',
      'Costing Basis',
      'Nature',
      ...columns.map(c => `"${c.cost_centre_name} (${c.cost_centre_code})"`),
      'Total',
      'Check'
    ];
    csv += headers.join(',') + '\r\n';

    // Data rows
    rows.forEach(r => {
      const closing = parseFloat(r.closing !== undefined ? r.closing : r.amount) || 0.0;
      const total = parseFloat(r.total !== undefined ? r.total : r.amount) || 0.0;
      const check = parseFloat(r.check !== undefined ? r.check : 0.0) || 0.0;
      const aname = r.aname || r.source_description || '';
      const acode = r.acode || r.account_code || r.source_row_ref || '';
      const pnl = r.pnl || r.pnl_classification || 'Rev from Operations';
      const basis = r.costing_basis || r.source_cost_basis || '';
      const nature = r.nature || 'Variable';

      const rowCols = [
        `"${aname.replace(/"/g, '""')}"`,
        `"${acode.replace(/"/g, '""')}"`,
        `"${pnl.replace(/"/g, '""')}"`,
        closing.toFixed(2),
        `"${basis.replace(/"/g, '""')}"`,
        `"${nature.replace(/"/g, '""')}"`
      ];

      columns.forEach(col => {
        let val = 0.0;
        if (r.allocations && r.allocations[col.cost_centre_id] !== undefined) {
          val = parseFloat(r.allocations[col.cost_centre_id]) || 0.0;
        } else if (r.cost_centre_id === col.cost_centre_id) {
          val = closing;
        }
        rowCols.push(val.toFixed(2));
      });

      rowCols.push(total.toFixed(2));
      rowCols.push(check.toFixed(2));

      csv += rowCols.join(',') + '\r\n';
    });

    // Totals row
    const totalsCols = [
      '"TOTAL"',
      '""',
      '""',
      Number(totals.closing || data.grand_total || 0).toFixed(2),
      '""',
      '""'
    ];
    columns.forEach(col => {
      const amt = (totals.allocations && totals.allocations[col.cost_centre_id] !== undefined)
        ? totals.allocations[col.cost_centre_id]
        : ((data.aggregates && data.aggregates[col.cost_centre_id]) ? data.aggregates[col.cost_centre_id].total_amount : 0);
      totalsCols.push(Number(amt || 0).toFixed(2));
    });
    totalsCols.push(Number(totals.total || data.grand_total || 0).toFixed(2));
    totalsCols.push(Number(totals.check || 0).toFixed(2));
    csv += totalsCols.join(',') + '\r\n';

    // Trigger download
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', `Quantity_Reco_${plantName}_${periodName}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Initialize on DOM Ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
