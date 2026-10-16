/**
 * Cost360 - Upload Centre Interactive Frontend Controller
 * Supports finalized 11-category Excel structure from EXCEL For Upload center (1).xlsx.
 * Handles multi-sheet Excel workbooks, dynamic Master Group and Target Entity classification,
 * source-to-target field mapping, pre-flight data preview, organization/audit scope display,
 * controlled atomic import into dedicated Customer DB (cust<ID>), and downloadable error logs.
 */

(function () {
  'use strict';

  const $ = selector => document.querySelector(selector);
  const $$ = selector => [...document.querySelectorAll(selector)];

  let selectedFiles = [];
  let currentUploadRunId = null;
  let masterGroupOptions = [];
  let analyzedWorksheets = [];
  let availablePeriods = [];
  let activePeriod = null;

  // Canonical 11 Target Datasets
  let targetDatasetOptions = [
    { key: '01_ITEM_PRODUCT_MASTER', table_name: 'items', dataset_type: 'MASTER', name: 'Item / Product Master', display_label: 'Item / Product Master (items)', sheet_name: '01.ITEM MASTER PRODUCT MASTER' },
    { key: '02_WORK_CENTER_MASTER', table_name: 'work_centers', dataset_type: 'MASTER', name: 'Work Center Master', display_label: 'Work Center Master (work_centers)', sheet_name: '02.WORK CENTER MASTER' },
    { key: '03_PARTY_MASTER', table_name: 'party_master', dataset_type: 'MASTER', name: 'Party Master', display_label: 'Party Master (party_master)', sheet_name: '03.PARTY MASTER' },
    { key: '04_COST_CENTER_MASTER', table_name: 'cost_center_master', dataset_type: 'MASTER', name: 'Cost Center Master', display_label: 'Cost Center Master (cost_center_master)', sheet_name: '04.COST_CENTER_MASTER' },
    { key: '05_STANDARD_COST', table_name: 'standard_cost', dataset_type: 'TRANSACTION', name: 'Standard Cost', display_label: 'Standard Cost (standard_cost)', sheet_name: '05. STANDARD_COST' },
    { key: '05A_BOM', table_name: 'bom', dataset_type: 'TRANSACTION', name: 'Bill of Materials', display_label: 'BOM (bom)', sheet_name: '05.A_BOM' },
    { key: '05B_ROUTING', table_name: 'routing', dataset_type: 'TRANSACTION', name: 'Routing', display_label: 'Routing (routing)', sheet_name: '05.B_ROUTING' },
    { key: '06_MATERIAL_TRANSACTION', table_name: 'material_transaction', dataset_type: 'TRANSACTION', name: 'Material Transaction', display_label: 'Material Transaction (material_transaction)', sheet_name: '06.MATERIAL TRANSACTION' },
    { key: '07_DOWNTIME', table_name: 'downtime', dataset_type: 'TRANSACTION', name: 'Downtime', display_label: 'Downtime (downtime)', sheet_name: '07.DOWNTIME' },
    { key: '08_FINANCE_TRANSACTION', table_name: 'finance_transaction', dataset_type: 'TRANSACTION', name: 'Finance Transaction', display_label: 'Finance Transaction (finance_transaction)', sheet_name: '08.FINANCE TRANSACTION' },
    { key: '09_TRIAL_BALANCE', table_name: 'trial_balance', dataset_type: 'TRANSACTION', name: 'Trial Balance', display_label: 'Trial Balance (trial_balance)', sheet_name: '09.TRIAL BALANCE' }
  ];

  // Authoritative active period from global scope
  let activePeriodError = null;

  function isMasterDataset(tableOrKey) {
    if (!tableOrKey) return false;
    const clean = String(tableOrKey).toLowerCase().replace(/[^a-z0-9_]/g, '');
    const masterTables = ['items', 'item_master', 'work_centers', 'work_center_master', 'party_master', 'cost_center_master'];
    const masterKeys = ['01_item_product_master', '02_work_center_master', '03_party_master', '04_cost_center_master'];
    return masterTables.includes(clean) || masterKeys.includes(clean);
  }

  function formatDisplayDate(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length === 3) {
      const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      const mIdx = parseInt(parts[1], 10) - 1;
      return `${String(parts[2]).padStart(2, '0')}-${months[mIdx] || parts[1]}-${parts[0]}`;
    }
    return dateStr;
  }

  function formatDisplayRange(start, end) {
    if (!start && !end) return 'Custom Date Range';
    const s = formatDisplayDate(start) || 'Start Date';
    const e = formatDisplayDate(end) || 'End Date';
    return `${s} to ${e}`;
  }

  const fileInput = $('#fileInput');
  const folderInput = $('#folderInput');
  const dropZone = $('#dropZone');
  const queueContainer = $('#fileQueue');
  const analyzeBtn = $('#analyzeBtn');
  const clearBtn = $('#clearBtn');
  const importBtn = $('#importBtn');
  const selectBtn = $('#selectBtn');
  const folderBtn = $('#folderBtn');

  async function init() {
    if (!dropZone) return;

    // Load master group dropdown options and accounting periods from API
    try {
      const res = await fetch('api/upload_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=dropdowns'
      });
      const data = await res.json();
      if (data.ok) {
        masterGroupOptions = data.master_groups || [];
        if (data.target_datasets && data.target_datasets.length) {
          targetDatasetOptions = data.target_datasets;
        }
      }
    } catch (e) {
      console.warn('Could not pre-fetch dropdown options:', e);
    }

    try {
      const pRes = await fetch('api/upload_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=periods'
      });
      const pData = await pRes.json();
      if (pData.ok) {
        availablePeriods = pData.periods || [];
        activePeriod = pData.active_period || null;
        activePeriodError = pData.period_error || null;
      }
    } catch (e) {
      console.warn('Could not pre-fetch accounting periods:', e);
    }

    // File input listeners
    selectBtn?.addEventListener('click', () => fileInput?.click());
    folderBtn?.addEventListener('click', () => folderInput?.click());

    fileInput?.addEventListener('change', e => addFiles(e.target.files));
    folderInput?.addEventListener('change', e => addFiles(e.target.files));

    // Drag and drop listeners
    ['dragenter', 'dragover'].forEach(eventName => {
      dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        dropZone.classList.add('drag-active');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        dropZone.classList.remove('drag-active');
      });
    });

    dropZone.addEventListener('drop', e => {
      if (e.dataTransfer?.files?.length) {
        addFiles(e.dataTransfer.files);
      }
    });

    clearBtn?.addEventListener('click', clearQueue);
    analyzeBtn?.addEventListener('click', analyzeFiles);
    importBtn?.addEventListener('click', executeImport);
  }

  function addFiles(files) {
    if (!files || !files.length) return;
    selectedFiles = [...selectedFiles, ...Array.from(files)];
    renderQueue();
  }

  function removeFile(index) {
    selectedFiles.splice(index, 1);
    renderQueue();
  }
  window.removeFile = removeFile;

  function clearQueue() {
    selectedFiles = [];
    currentUploadRunId = null;
    analyzedWorksheets = [];
    if (importBtn) importBtn.style.display = 'none';
    renderQueue();
  }

  function renderQueue() {
    if (!queueContainer) return;

    if (!selectedFiles.length) {
      queueContainer.classList.add('hidden');
      queueContainer.innerHTML = '';
      if (analyzeBtn) {
        analyzeBtn.disabled = true;
        analyzeBtn.innerHTML = 'Analyze Files';
      }
      return;
    }

    queueContainer.classList.remove('hidden');
    if (analyzeBtn) {
      analyzeBtn.disabled = false;
      analyzeBtn.innerHTML = `Analyze ${selectedFiles.length} File${selectedFiles.length > 1 ? 's' : ''}`;
    }

    queueContainer.innerHTML = selectedFiles.map((file, i) => {
      const ext = file.name.split('.').pop().toUpperCase();
      return `
        <div class="queue-item-row">
          <span class="file-ext-badge">${escapeHtml(ext)}</span>
          <div class="file-info-col">
            <strong class="file-title">${escapeHtml(file.name)}</strong>
            <small class="file-size">${formatBytes(file.size)}</small>
          </div>
          <button type="button" class="btn-remove-file" onclick="removeFile(${i})" title="Remove file">&times;</button>
        </div>
      `;
    }).join('');
  }

  async function analyzeFiles() {
    if (!selectedFiles.length) return alert('Please select at least one Excel or CSV file to upload.');

    const formData = new FormData();
    formData.append('action', 'upload');
    selectedFiles.forEach(file => formData.append('files[]', file));

    analyzeBtn.disabled = true;
    analyzeBtn.innerHTML = '<span class="spinner"></span> Analyzing Workbook & Worksheets...';

    try {
      const response = await fetch('api/upload_api.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();
      if (!result.ok) throw new Error(result.message || 'Analysis failed.');

      currentUploadRunId = result.upload_id;
      if (result.master_groups) {
        masterGroupOptions = result.master_groups;
      }
      analyzedWorksheets = result.files || [];

      renderAnalysisResults(result.files, result.duplicate_warning);

      analyzeBtn.innerHTML = '✓ Worksheets Analyzed';
      if (importBtn) {
        importBtn.style.display = 'inline-flex';
        importBtn.disabled = false;
        importBtn.innerHTML = `Confirm & Import (${analyzedWorksheets.length} Sheets)`;
      }
    } catch (err) {
      analyzeBtn.disabled = false;
      analyzeBtn.innerHTML = 'Analyze Files';
      if (typeof showImportFailureModal === 'function') {
        showImportFailureModal('Upload Analysis Error', err.message, currentUploadRunId);
      } else {
        alert('Upload Analysis Error: ' + err.message);
      }
    }
  }

  function renderAnalysisResults(files, duplicateWarning = null) {
    if (!queueContainer) return;

    let warningHtml = '';
    if (duplicateWarning) {
      warningHtml = `
        <div style="padding: 0.85rem 1.25rem; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.35); border-radius: 8px; color: #F59E0B; margin-bottom: 1rem; font-size: 0.88rem; display: flex; align-items: center; gap: 0.6rem;">
          <span style="font-size: 1.2rem;">&#9888;</span>
          <span>${escapeHtml(duplicateWarning)}</span>
        </div>
      `;
    }

    const hasTransactions = files.some(f => !isMasterDataset(f.target_table));
    let periodInfoHtml = '';
    if (hasTransactions) {
      const periodName = activePeriod ? (activePeriod.period_name || ('Period ' + activePeriod.period_id)) : 'No Active Period Selected';
      const rangeText = (activePeriod && activePeriod.start_date && activePeriod.end_date)
        ? ` (${formatDisplayDate(activePeriod.start_date)} to ${formatDisplayDate(activePeriod.end_date)})`
        : '';
      const lockBadge = (activePeriod && activePeriod.is_locked)
        ? `<span style="background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.72rem; font-weight: 700; margin-left: 0.4rem;">LOCKED</span>`
        : '';
      const statusNote = activePeriodError
        ? `<span style="color: #DC2626; font-weight: 700;">⚠️ ${escapeHtml(activePeriodError)}</span>`
        : `<span style="color: #0369A1;">Using active accounting period from global scope: <strong>${escapeHtml(periodName)}</strong>${rangeText}</span>${lockBadge}`;

      periodInfoHtml = `
        <div class="c360-period-info-banner" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; background: #F0F9FF; border: 1.5px solid #BAE6FD; border-radius: 8px; padding: 0.85rem 1.15rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(2, 132, 199, 0.05);">
          <div style="display: flex; align-items: center; gap: 0.6rem; font-size: 0.88rem; font-weight: 600;">
            <span style="font-size: 1.15rem;">📅</span>
            ${statusNote}
          </div>
          <small style="color: #64748B; font-size: 0.8rem; font-weight: 500;">
            Period is managed via the global scope selector in the top header
          </small>
        </div>
      `;
    }

    const headerBarHtml = `
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.85rem 1.25rem; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; margin-bottom: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <input type="checkbox" id="selectAllSheets" checked onchange="toggleSelectAllSheets(this)" style="cursor: pointer; width: 17px; height: 17px; accent-color: #0284C7;">
          <label for="selectAllSheets" style="cursor: pointer; color: #0F172A; font-size: 0.95rem; font-weight: 700; margin: 0;">Detected Worksheets (${files.length})</label>
        </div>
        <small style="color: #64748B; font-size: 0.82rem; font-weight: 500;">Select worksheets to import into their dedicated database tables</small>
      </div>
    `;

    const cardsHtml = files.map(file => {
      const isMaster = isMasterDataset(file.target_table);
      const datasetBadge = isMaster
        ? `<span class="classification-badge" id="badge_${file.id}" style="display: inline-flex; align-items: center; gap: 0.35rem; background: #FAF5FF; border: 1px solid #E9D5FF; color: #7C3AED; font-size: 0.74rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 4px;">⚡ MASTER DATA</span>`
        : `<span class="classification-badge" id="badge_${file.id}" style="display: inline-flex; align-items: center; gap: 0.35rem; background: #EFF6FF; border: 1px solid #BFDBFE; color: #0284C7; font-size: 0.74rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 4px;">📅 PERIOD-DEPENDENT DATA</span>`;

      const scopeNote = isMaster
        ? `<span id="scopeNote_${file.id}" style="color: #7C3AED; font-size: 0.76rem; font-weight: 600;">Persistent Master Upsert</span>`
        : `<span id="scopeNote_${file.id}" style="color: #0284C7; font-size: 0.76rem; font-weight: 600;">Bound to: <strong>${escapeHtml(activePeriod ? (activePeriod.period_name || ('Period ' + activePeriod.period_id)) : 'Active Period')}</strong></span>`;

      // Build Single TARGET DATASET Dropdown Options
      const datasetSelectOptions = targetDatasetOptions.map(d => {
        const isSelected = (
          (file.target_table && d.table_name.toLowerCase() === file.target_table.toLowerCase()) ||
          (file.category && d.key === file.category.key) ||
          (file.sheet_name && d.sheet_name === file.sheet_name)
        );
        return `<option value="${escapeHtml(d.table_name)}" ${isSelected ? 'selected' : ''}>${escapeHtml(d.display_label || (d.name + ' (' + d.table_name + ')'))}</option>`;
      }).join('');

      let warningPills = '';
      if (file.missing_headers && file.missing_headers.length > 0) {
        warningPills += `<span style="font-size: 0.72rem; padding: 0.2rem 0.5rem; background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; border-radius: 4px; font-weight: 600;">Missing: ${file.missing_headers.slice(0, 2).join(', ')}${file.missing_headers.length > 2 ? ' +' + (file.missing_headers.length - 2) : ''}</span>`;
      }
      if (file.unexpected_headers && file.unexpected_headers.length > 0) {
        warningPills += `<span style="font-size: 0.72rem; padding: 0.2rem 0.5rem; background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; border-radius: 4px; font-weight: 600;">Extra: ${file.unexpected_headers.length} cols</span>`;
      }

      return `
        <div class="analysis-result-card" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 0.75rem; gap: 1rem;">
          
          <div style="display: flex; align-items: center; gap: 0.75rem;">
            <input type="checkbox" class="sheet-checkbox" data-file-id="${file.id}" checked onchange="updateImportBtnCount()" style="cursor: pointer; width: 17px; height: 17px; accent-color: #0284C7;">
            <div class="file-ext-badge" style="background: #0284C7; color: #FFFFFF; font-size: 0.72rem; font-weight: 700; padding: 0.3rem 0.6rem; border-radius: 6px; letter-spacing: 0.04em;">${escapeHtml(file.sheet_name ? 'SHEET' : file.type)}</div>
          </div>

          <div class="file-info-col" style="flex: 1; min-width: 200px;">
            <strong class="file-title" style="color: #0F172A; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.25rem; display: block;">${escapeHtml(file.sheet_name || file.name)}</strong>
            <div class="file-meta" style="color: #64748B; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; font-size: 0.8rem;">
              ${datasetBadge}
              <span id="targetTag_${file.id}" style="display: inline-flex; align-items: center; gap: 0.35rem; background: #F0FDF4; border: 1px solid #BBF7D0; color: #15803D; font-size: 0.76rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 4px;">
                Target: <code style="background: transparent; color: #166534; font-weight: 700; padding: 0;">${escapeHtml(file.target_table)}</code>
              </span>
              ${file.has_saved_mapping ? `<span id="savedMapTag_${file.id}" style="display: inline-flex; align-items: center; gap: 0.35rem; background: #ECFDF5; border: 1px solid #A7F3D0; color: #059669; font-size: 0.74rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 4px;">💾 Saved Mapping Applied</span>` : `<span id="savedMapTag_${file.id}" style="display: none;"></span>`}
              <span style="color: #CBD5E1;">&bull;</span>
              ${scopeNote}
              <span style="color: #CBD5E1;">&bull;</span>
              <span><strong>${file.rows.toLocaleString()}</strong> rows</span>
              <span style="color: #CBD5E1;">&bull;</span>
              <span><strong>${file.columns}</strong> cols</span>
              ${warningPills}
            </div>
          </div>

          <!-- SINGLE TARGET DATASET CONTROL -->
          <div class="dropdown-control-group" style="min-width: 250px;">
            <label class="dropdown-label" style="color: #64748B; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; display: block;">Target Dataset</label>
            <select class="cost360-select" id="datasetSelect_${file.id}" onchange="onTargetDatasetChange(this, ${file.id})" style="background: #FFFFFF; color: #0F172A; border: 1.5px solid #CBD5E1; border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.84rem; font-weight: 600; cursor: pointer; width: 100%;">
              ${datasetSelectOptions}
            </select>
          </div>

          <div class="action-col" style="display: flex; align-items: center;">
            <button type="button" class="btn-action-sm" onclick="previewFile(${file.id})" style="padding: 0.5rem 1rem; background: #F8FAFC; color: #0284C7; border: 1px solid #BAE6FD; border-radius: 6px; font-weight: 700; font-size: 0.84rem; cursor: pointer; transition: all 0.2s ease; white-space: nowrap;">
              Preview & Map
            </button>
          </div>
        </div>
      `;
    }).join('');

    queueContainer.innerHTML = warningHtml + periodInfoHtml + headerBarHtml + cardsHtml;
  }

  function toggleSelectAllSheets(masterCheckbox) {
    const checkboxes = $$('.sheet-checkbox');
    checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
    updateImportBtnCount();
  }
  window.toggleSelectAllSheets = toggleSelectAllSheets;

  function updateImportBtnCount() {
    const selected = $$('.sheet-checkbox:checked');
    if (importBtn) {
      if (selected.length === 0) {
        importBtn.disabled = true;
        importBtn.innerHTML = 'Select at least 1 Sheet';
      } else {
        importBtn.disabled = false;
        importBtn.innerHTML = `Confirm & Import (${selected.length} Selected)`;
      }
    }
  }
  window.updateImportBtnCount = updateImportBtnCount;

  async function onTargetDatasetChange(selectEl, fileId) {
    const targetKeyOrTable = selectEl.value;
    const targetDef = targetDatasetOptions.find(d => d.key === targetKeyOrTable || d.table_name === targetKeyOrTable);
    const targetTable = targetDef ? targetDef.table_name : targetKeyOrTable;
    const isMaster = isMasterDataset(targetTable);

    // Update in-memory worksheet state
    const fIdx = analyzedWorksheets.findIndex(f => f.id == fileId);
    if (fIdx !== -1) {
      analyzedWorksheets[fIdx].target_table = targetTable;
      analyzedWorksheets[fIdx].dataset_type = isMaster ? 'MASTER' : 'TRANSACTION';
    }

    // Update DOM card elements
    const badgeEl = $(`#badge_${fileId}`);
    const scopeNoteEl = $(`#scopeNote_${fileId}`);
    const targetTagEl = $(`#targetTag_${fileId}`);

    if (badgeEl) {
      if (isMaster) {
        badgeEl.style.background = '#FAF5FF';
        badgeEl.style.border = '1px solid #E9D5FF';
        badgeEl.style.color = '#7C3AED';
        badgeEl.innerHTML = '⚡ MASTER DATA';
      } else {
        badgeEl.style.background = '#EFF6FF';
        badgeEl.style.border = '1px solid #BFDBFE';
        badgeEl.style.color = '#0284C7';
        badgeEl.innerHTML = '📅 PERIOD-DEPENDENT DATA';
      }
    }

    if (scopeNoteEl) {
      if (isMaster) {
        scopeNoteEl.style.color = '#7C3AED';
        scopeNoteEl.innerHTML = 'Persistent Master Upsert';
      } else {
        scopeNoteEl.style.color = '#0284C7';
        scopeNoteEl.innerHTML = `Bound to: <strong>${escapeHtml(activePeriod ? (activePeriod.period_name || ('Period ' + activePeriod.period_id)) : 'Active Period')}</strong>`;
      }
    }

    if (targetTagEl) {
      targetTagEl.innerHTML = `Target: <code style="background: transparent; color: #166534; font-weight: 700; padding: 0;">${escapeHtml(targetTable)}</code>`;
    }

    // Persist to server
    try {
      const fd = new FormData();
      fd.append('action', 'update_classification');
      fd.append('file_id', fileId);
      fd.append('target_dataset', targetKeyOrTable);
      await fetch('api/upload_api.php', { method: 'POST', body: fd });
    } catch (e) {
      console.error('Failed to update file classification on server:', e);
    }
  }
  window.onTargetDatasetChange = onTargetDatasetChange;

  function ensureModalStyles() {
    if (document.getElementById('c360-modal-dynamic-styles')) return;
    const styleEl = document.createElement('style');
    styleEl.id = 'c360-modal-dynamic-styles';
    styleEl.textContent = `
      .c360-modal-backdrop {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(6px);
        display: flex; align-items: center; justify-content: center;
        z-index: 99999; padding: 1.25rem;
        animation: c360FadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      }
      @keyframes c360FadeIn {
        from { opacity: 0; transform: scale(0.98); }
        to { opacity: 1; transform: scale(1); }
      }
      .c360-preview-dialog {
        background: #FFFFFF;
        border-radius: 16px;
        width: 100%; max-width: 1120px;
        max-height: 92vh;
        display: flex; flex-direction: column;
        box-shadow: 0 25px 60px -15px rgba(3, 54, 101, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.8);
        overflow: hidden;
        color: #1E293B;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      }
      .c360-modal-head {
        background: linear-gradient(135deg, #033665 0%, #063D70 100%);
        padding: 1.25rem 1.75rem;
        display: flex; justify-content: space-between; align-items: center;
        border-bottom: 1px solid rgba(255,255,255,0.12);
        color: #FFFFFF;
      }
      .c360-modal-head-danger {
        background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%) !important;
      }
      .c360-modal-title {
        margin: 0; font-size: 1.15rem; font-weight: 700;
        display: flex; align-items: center; gap: 0.6rem;
        color: #FFFFFF;
      }
      .c360-modal-meta-row {
        display: flex; align-items: center; flex-wrap: wrap; gap: 0.6rem;
        margin-top: 0.45rem; font-size: 0.8rem;
      }
      .c360-btn-close {
        background: rgba(255,255,255,0.15); border: none; color: #FFFFFF;
        width: 32px; height: 32px; border-radius: 8px; font-size: 1.3rem;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: all 0.15s; line-height: 1;
      }
      .c360-btn-close:hover { background: rgba(255,255,255,0.3); }
      .c360-chip {
        display: inline-flex; align-items: center; gap: 0.35rem;
        padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.74rem; font-weight: 600;
      }
      .c360-chip-cyan { background: rgba(0, 207, 239, 0.15); color: #00CFEF; border: 1px solid rgba(0, 207, 239, 0.3); }
      .c360-chip-green { background: rgba(16, 185, 129, 0.15); color: #34D399; border: 1px solid rgba(16, 185, 129, 0.3); }
      .c360-chip-gray { background: rgba(255,255,255,0.12); color: #E2E8F0; }
      .c360-scope-banner {
        background: #F0F9FF; border-bottom: 1px solid #BAE6FD;
        padding: 0.65rem 1.75rem; font-size: 0.8rem; color: #0369A1;
        display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;
      }
      .c360-scope-tag {
        font-weight: 700; color: #0C4A6E; background: #E0F2FE;
        padding: 0.15rem 0.45rem; border-radius: 4px; border: 1px solid #BAE6FD;
      }
      .c360-security-notice {
        display: flex; align-items: center; gap: 0.4rem; font-size: 0.75rem; color: #0284C7; font-weight: 600;
      }
      .c360-modal-body {
        padding: 1.5rem 1.75rem; overflow-y: auto; flex: 1;
        background: #F8FAFC;
      }
      .c360-section-title-bar {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 0.85rem; flex-wrap: wrap; gap: 0.75rem;
      }
      .c360-section-heading {
        margin: 0; font-size: 0.95rem; font-weight: 700; color: #0F172A;
        display: flex; align-items: center; gap: 0.5rem;
      }
      .c360-filter-bar {
        display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
      }
      .c360-search-box { position: relative; }
      .c360-search-input {
        padding: 0.4rem 0.75rem 0.4rem 2rem;
        border: 1px solid #CBD5E1; border-radius: 6px;
        font-size: 0.8rem; background: #FFFFFF; color: #1E293B;
        outline: none; width: 190px; transition: all 0.2s;
      }
      .c360-search-input:focus {
        border-color: #0878D1; width: 230px; box-shadow: 0 0 0 2px rgba(8, 120, 209, 0.15);
      }
      .c360-search-icon {
        position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%);
        color: #94A3B8; pointer-events: none;
      }
      .c360-filter-tab {
        background: #FFFFFF; border: 1px solid #CBD5E1;
        color: #64748B; font-size: 0.78rem; font-weight: 600;
        padding: 0.35rem 0.65rem; border-radius: 6px;
        cursor: pointer; transition: all 0.15s;
      }
      .c360-filter-tab:hover { background: #F1F5F9; color: #1E293B; }
      .c360-filter-tab.active { background: #0878D1; border-color: #0878D1; color: #FFFFFF; }
      .c360-table-card {
        background: #FFFFFF; border: 1px solid #E2E8F0;
        border-radius: 10px; overflow: hidden;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        margin-bottom: 1.5rem;
      }
      .c360-mapping-table {
        width: 100%; border-collapse: collapse; text-align: left;
        font-size: 0.86rem;
      }
      .c360-mapping-table th {
        background: #F1F5F9; color: #475569;
        font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em;
        padding: 0.65rem 0.85rem; border-bottom: 1px solid #CBD5E1;
      }
      .c360-mapping-row {
        border-bottom: 1px solid #F1F5F9; transition: background 0.15s ease;
      }
      .c360-mapping-row:hover { background: #F8FAFC; }
      .c360-col-idx {
        width: 35px; text-align: center; color: #94A3B8; font-size: 0.78rem; font-weight: 600;
        padding: 0.6rem 0.5rem;
      }
      .c360-col-source { padding: 0.6rem 0.85rem; width: 34%; }
      .c360-source-name { font-weight: 700; color: #0F172A; font-size: 0.88rem; }
      .c360-map-subtext { font-size: 0.74rem; color: #64748B; margin-top: 0.15rem; }
      .c360-map-subtext code { background: #E2E8F0; color: #0F172A; padding: 0.05rem 0.35rem; border-radius: 4px; font-weight: 600; }
      .c360-col-arrow { width: 30px; text-align: center; color: #0878D1; font-weight: 800; font-size: 1.1rem; }
      .c360-col-target { padding: 0.6rem 0.85rem; width: 40%; }
      .c360-mapping-select {
        width: 100%; padding: 0.45rem 0.75rem;
        background: #FFFFFF; border: 1.5px solid #CBD5E1; border-radius: 6px;
        color: #0F172A; font-size: 0.85rem; font-weight: 600;
        outline: none; transition: all 0.2s;
      }
      .c360-mapping-select:focus { border-color: #0878D1; box-shadow: 0 0 0 3px rgba(8, 120, 209, 0.15); }
      .c360-col-badge { padding: 0.6rem 0.85rem; width: 18%; text-align: right; }
      .c360-badge {
        display: inline-flex; align-items: center; gap: 0.3rem;
        padding: 0.25rem 0.6rem; border-radius: 20px;
        font-size: 0.74rem; font-weight: 700;
      }
      .c360-badge-green  { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
      .c360-badge-cyan   { background: #E0F2FE; color: #0284C7; border: 1px solid #BAE6FD; }
      .c360-badge-purple { background: #F3E8FF; color: #7E22CE; border: 1px solid #E9D5FF; }
      .c360-badge-gray   { background: #F1F5F9; color: #64748B; border: 1px solid #CBD5E1; }
      .c360-badge-red    { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
      .c360-badge-amber  { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
      .c360-sample-table-scroll { overflow-x: auto; max-height: 280px; }
      .c360-sample-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
      .c360-sample-table th {
        background: #033665; color: #FFFFFF; position: sticky; top: 0;
        padding: 0.55rem 0.75rem; text-align: left; font-weight: 600;
        border-bottom: 2px solid rgba(255,255,255,0.2); white-space: nowrap;
      }
      .c360-sample-table td {
        padding: 0.5rem 0.75rem; border-bottom: 1px solid #E2E8F0;
        color: #1E293B; white-space: nowrap;
      }
      .c360-sample-table tr:nth-child(even) { background: #F8FAFC; }
      .c360-sample-idx { color: #94A3B8; font-weight: 600; text-align: center; }
      .c360-empty-preview { padding: 1.5rem; text-align: center; color: #64748B; font-size: 0.86rem; }
      .c360-modal-foot {
        background: #FFFFFF; border-top: 1px solid #E2E8F0;
        padding: 1.1rem 1.75rem;
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 1rem;
      }
      .c360-foot-status {
        font-size: 0.85rem; color: #475569; font-weight: 600;
        display: flex; align-items: center; gap: 0.5rem;
      }
      .c360-foot-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #10B981; }
      .c360-foot-actions { display: flex; align-items: center; gap: 0.75rem; }
      .c360-btn-ghost {
        background: transparent; border: 1.5px solid #CBD5E1; color: #475569;
        font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.25rem;
        border-radius: 8px; cursor: pointer; transition: all 0.2s;
      }
      .c360-btn-ghost:hover { background: #F1F5F9; color: #0F172A; }
      .c360-btn-approve {
        background: #10B981; border: none; color: #FFFFFF;
        font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.4rem;
        border-radius: 8px; cursor: pointer;
        display: inline-flex; align-items: center; gap: 0.5rem;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        transition: all 0.2s; text-decoration: none;
      }
      .c360-btn-approve:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35); }
      .c360-btn-danger {
        background: #DC2626; border: none; color: #FFFFFF;
        font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.4rem;
        border-radius: 8px; cursor: pointer;
        display: inline-flex; align-items: center; gap: 0.5rem;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25);
        transition: all 0.2s; text-decoration: none;
      }
      .c360-btn-danger:hover { background: #B91C1C; transform: translateY(-1px); }
      .c360-btn-secondary {
        background: #0284C7; border: none; color: #FFFFFF;
        font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.4rem;
        border-radius: 8px; cursor: pointer;
        display: inline-flex; align-items: center; gap: 0.5rem;
        box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
        transition: all 0.2s; text-decoration: none;
      }
      .c360-btn-secondary:hover { background: #0369A1; }
    `;
    document.head.appendChild(styleEl);
  }

  async function previewFile(fileId) {
    try {
      ensureModalStyles();
      const formData = new FormData();
      formData.append('action', 'preview');
      formData.append('id', fileId);
      if (activePeriod && activePeriod.period_id) {
        formData.append('preview_period_id', activePeriod.period_id);
      }

      const response = await fetch('api/upload_api.php', { method: 'POST', body: formData });
      const result = await response.json();

      if (!result.ok) return alert(result.message || 'Failed to load preview.');

      const headers = result.headers || [];
      const data = result.data || [];
      const targetCols = result.target_columns || [];
      const autoMapping = result.auto_mapping || {};
      const orgContext = result.org_context || {};

      const sysOrgFields = ['customer_code', 'company_code', 'division_code', 'location_code'];
      const sysAuditFields = ['created_at', 'created_by', 'modified_at', 'modified_by'];

      let directMatchCount = 0;
      let sysCount = 0;
      let auditCount = 0;
      let skippedCount = 0;

      // Build High-Density Mapping Table Rows
      const mappingRowsHtml = headers.map((header, idx) => {
        const cleanH = header.replace(/[^a-z0-9_]/gi, '').toLowerCase();
        const mappedCol = autoMapping[header] || cleanH;

        const isSysOrg = sysOrgFields.includes(mappedCol) || sysOrgFields.includes(cleanH);
        const isSysAudit = sysAuditFields.includes(mappedCol) || sysAuditFields.includes(cleanH);
        const isIgnored = mappedCol === '__IGNORE__';

        let badgeHtml = '';
        let rowClass = 'filter-matched';
        let noteHtml = '';

        if (isSysOrg) {
          sysCount++;
          rowClass = 'filter-system';
          badgeHtml = '<span class="c360-badge c360-badge-cyan"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> System Controlled</span>';
          noteHtml = `<div class="c360-map-subtext">Overridden by server scope (<code>${escapeHtml(orgContext[mappedCol] || orgContext.customer_code || 'Active')}</code>)</div>`;
        } else if (isSysAudit) {
          auditCount++;
          rowClass = 'filter-audit';
          badgeHtml = '<span class="c360-badge c360-badge-purple"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Audit Managed</span>';
          noteHtml = '<div class="c360-map-subtext">Automated server timestamp & user ID</div>';
        } else if (isIgnored) {
          skippedCount++;
          rowClass = 'filter-skipped';
          badgeHtml = '<span class="c360-badge c360-badge-gray">Skipped</span>';
        } else {
          directMatchCount++;
          rowClass = 'filter-matched';
          badgeHtml = '<span class="c360-badge c360-badge-green"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Direct Match</span>';
        }

        const colOptions = targetCols.map(col => {
          const selected = (col === mappedCol) ? 'selected' : '';
          return `<option value="${col}" ${selected}>${escapeHtml(col)}</option>`;
        }).join('');

        return `
          <tr class="c360-mapping-row ${rowClass}" data-col="${escapeHtml(header.toLowerCase())}" data-target="${escapeHtml(mappedCol.toLowerCase())}">
            <td class="c360-col-idx">${idx + 1}</td>
            <td class="c360-col-source">
              <span class="c360-source-name">${escapeHtml(header)}</span>
              ${noteHtml}
            </td>
            <td class="c360-col-arrow">
              <span class="c360-arrow-icon">&rarr;</span>
            </td>
            <td class="c360-col-target">
              <select class="c360-mapping-select" data-source-header="${escapeHtml(header)}" onchange="window.c360OnMappingSelectChange(this)">
                ${colOptions}
                <option value="__IGNORE__" ${isIgnored ? 'selected' : ''}>&mdash; Skip / Do Not Import &mdash;</option>
              </select>
            </td>
            <td class="c360-col-badge">
              ${badgeHtml}
            </td>
          </tr>
        `;
      }).join('');

      // Sample Data Rows (First 25 Rows)
      let sampleDataHtml = '<div class="c360-empty-preview">Template headers detected &bull; No sample data rows in this sheet.</div>';
      if (data.length > 0) {
        sampleDataHtml = `
          <div class="c360-sample-table-scroll">
            <table class="c360-sample-table">
              <thead>
                <tr>
                  <th>#</th>
                  ${headers.map(h => `<th>${escapeHtml(h)}</th>`).join('')}
                </tr>
              </thead>
              <tbody>
                ${data.map((row, idx) => `
                  <tr>
                    <td class="c360-sample-idx">${idx + 1}</td>
                    ${headers.map(h => `<td>${escapeHtml(row[h] || '')}</td>`).join('')}
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        `;
      }

      // Inject UI modal with unified styles
      const modalHtml = `
        <div id="cost360-preview-modal-root" class="c360-modal-backdrop" onclick="this.remove()">
          <style id="c360-modal-dynamic-styles">
            .c360-modal-backdrop {
              position: fixed; inset: 0;
              background: rgba(15, 23, 42, 0.72);
              backdrop-filter: blur(6px);
              display: flex; align-items: center; justify-content: center;
              z-index: 99999; padding: 1.25rem;
              animation: c360FadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes c360FadeIn {
              from { opacity: 0; transform: scale(0.98); }
              to { opacity: 1; transform: scale(1); }
            }
            @keyframes c360Spin {
              from { transform: rotate(0deg); }
              to { transform: rotate(360deg); }
            }
            .c360-preview-dialog {
              background: #FFFFFF;
              border-radius: 16px;
              width: 100%; max-width: 1120px;
              max-height: 92vh;
              display: flex; flex-direction: column;
              box-shadow: 0 25px 60px -15px rgba(3, 54, 101, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.8);
              overflow: hidden;
              color: #1E293B;
              font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            /* HEADER */
            .c360-modal-head {
              background: linear-gradient(135deg, #033665 0%, #063D70 100%);
              padding: 1.25rem 1.75rem;
              display: flex; justify-content: space-between; align-items: center;
              border-bottom: 1px solid rgba(255,255,255,0.12);
              color: #FFFFFF;
            }
            .c360-modal-title {
              margin: 0; font-size: 1.15rem; font-weight: 700;
              display: flex; align-items: center; gap: 0.6rem;
              color: #FFFFFF;
            }
            .c360-modal-meta-row {
              display: flex; align-items: center; flex-wrap: wrap; gap: 0.6rem;
              margin-top: 0.45rem; font-size: 0.8rem;
            }
            .c360-chip {
              display: inline-flex; align-items: center; gap: 0.35rem;
              padding: 0.2rem 0.6rem; border-radius: 6px;
              font-size: 0.76rem; font-weight: 600;
            }
            .c360-chip-cyan { background: rgba(0, 207, 239, 0.15); color: #00CFEF; border: 1px solid rgba(0, 207, 239, 0.3); }
            .c360-chip-green { background: rgba(52, 211, 153, 0.15); color: #34D399; border: 1px solid rgba(52, 211, 153, 0.3); }
            .c360-chip-gray { background: rgba(255, 255, 255, 0.12); color: #E2E8F0; }
            .c360-btn-close {
              background: rgba(255,255,255,0.1); border: none;
              width: 34px; height: 34px; border-radius: 50%;
              color: #FFFFFF; font-size: 1.25rem;
              display: flex; align-items: center; justify-content: center;
              cursor: pointer; transition: all 0.2s;
            }
            .c360-btn-close:hover { background: rgba(255,255,255,0.22); transform: rotate(90deg); }

            /* SECURITY BANNER */
            .c360-scope-banner {
              background: #F0F9FF; border-bottom: 1px solid #BAE6FD;
              padding: 0.75rem 1.75rem;
              display: flex; align-items: center; justify-content: space-between;
              flex-wrap: wrap; gap: 0.75rem; font-size: 0.84rem;
            }
            .c360-scope-tag {
              background: #FFFFFF; border: 1px solid #BAE6FD;
              color: #0369A1; font-weight: 700; padding: 0.15rem 0.5rem;
              border-radius: 4px; font-size: 0.78rem; font-family: monospace;
            }
            .c360-security-notice {
              color: #0284C7; font-size: 0.78rem; font-weight: 600;
              display: inline-flex; align-items: center; gap: 0.4rem;
            }

            /* BODY */
            .c360-modal-body {
              padding: 1.25rem 1.75rem;
              overflow-y: auto; flex: 1;
              background: #F8FAFC;
            }
            .c360-section-title-bar {
              display: flex; justify-content: space-between; align-items: center;
              flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.85rem;
            }
            .c360-section-heading {
              font-size: 0.95rem; font-weight: 700; color: #0F172A;
              margin: 0; display: flex; align-items: center; gap: 0.5rem;
            }

            /* FILTER & SEARCH BAR */
            .c360-filter-bar {
              display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
            }
            .c360-search-box {
              position: relative;
            }
            .c360-search-input {
              padding: 0.4rem 0.75rem 0.4rem 2rem;
              border: 1px solid #CBD5E1; border-radius: 6px;
              font-size: 0.8rem; background: #FFFFFF; color: #1E293B;
              outline: none; width: 180px; transition: all 0.2s;
            }
            .c360-search-input:focus {
              border-color: #0878D1; width: 220px; box-shadow: 0 0 0 2px rgba(8, 120, 209, 0.15);
            }
            .c360-search-icon {
              position: absolute; left: 0.65rem; top: 50%; transform: translateY(-50%);
              color: #94A3B8; pointer-events: none;
            }
            .c360-filter-tab {
              background: #FFFFFF; border: 1px solid #E2E8F0;
              color: #64748B; font-size: 0.78rem; font-weight: 600;
              padding: 0.35rem 0.65rem; border-radius: 6px;
              cursor: pointer; transition: all 0.15s;
            }
            .c360-filter-tab:hover { background: #F1F5F9; color: #1E293B; }
            .c360-filter-tab.active { background: #0878D1; border-color: #0878D1; color: #FFFFFF; }

            /* MAPPING TABLE */
            .c360-table-card {
              background: #FFFFFF; border: 1px solid #E2E8F0;
              border-radius: 10px; overflow: hidden;
              box-shadow: 0 2px 6px rgba(0,0,0,0.03);
              margin-bottom: 1.5rem;
            }
            .c360-mapping-table {
              width: 100%; border-collapse: collapse; text-align: left;
              font-size: 0.86rem;
            }
            .c360-mapping-table th {
              background: #F1F5F9; color: #475569;
              font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em;
              padding: 0.65rem 0.85rem; border-bottom: 1px solid #CBD5E1;
            }
            .c360-mapping-row {
              border-bottom: 1px solid #F1F5F9; transition: background 0.15s ease;
            }
            .c360-mapping-row:hover { background: #F8FAFC; }
            .c360-col-idx {
              width: 35px; text-align: center; color: #94A3B8; font-size: 0.78rem; font-weight: 600;
              padding: 0.6rem 0.5rem;
            }
            .c360-col-source {
              padding: 0.6rem 0.85rem; width: 34%;
            }
            .c360-source-name {
              font-weight: 700; color: #0F172A; font-size: 0.88rem;
            }
            .c360-map-subtext {
              font-size: 0.74rem; color: #64748B; margin-top: 0.15rem;
            }
            .c360-map-subtext code {
              background: #E2E8F0; color: #0F172A; padding: 0.05rem 0.35rem; border-radius: 4px; font-weight: 600;
            }
            .c360-col-arrow {
              width: 30px; text-align: center; color: #0878D1; font-weight: 800; font-size: 1.1rem;
            }
            .c360-col-target {
              padding: 0.6rem 0.85rem; width: 40%;
            }
            .c360-mapping-select {
              width: 100%; padding: 0.45rem 0.75rem;
              background: #FFFFFF; border: 1.5px solid #CBD5E1; border-radius: 6px;
              color: #0F172A; font-size: 0.85rem; font-weight: 600;
              outline: none; transition: all 0.2s;
            }
            .c360-mapping-select:focus {
              border-color: #0878D1; box-shadow: 0 0 0 3px rgba(8, 120, 209, 0.15);
            }
            .c360-col-badge {
              padding: 0.6rem 0.85rem; width: 18%; text-align: right;
            }

            /* STATUS BADGES */
            .c360-badge {
              display: inline-flex; align-items: center; gap: 0.3rem;
              padding: 0.25rem 0.6rem; border-radius: 20px;
              font-size: 0.74rem; font-weight: 700;
            }
            .c360-badge-green { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
            .c360-badge-cyan  { background: #E0F2FE; color: #0284C7; border: 1px solid #BAE6FD; }
            .c360-badge-purple{ background: #F3E8FF; color: #7E22CE; border: 1px solid #E9D5FF; }
            .c360-badge-gray  { background: #F1F5F9; color: #64748B; border: 1px solid #CBD5E1; }

            /* SAMPLE DATA */
            .c360-sample-table-scroll {
              overflow-x: auto; max-height: 280px;
            }
            .c360-sample-table {
              width: 100%; border-collapse: collapse; font-size: 0.82rem;
            }
            .c360-sample-table th {
              background: #033665; color: #FFFFFF; position: sticky; top: 0;
              padding: 0.55rem 0.75rem; text-align: left; font-weight: 600;
              border-bottom: 2px solid rgba(255,255,255,0.2); white-space: nowrap;
            }
            .c360-sample-table td {
              padding: 0.5rem 0.75rem; border-bottom: 1px solid #E2E8F0;
              color: #1E293B; white-space: nowrap;
            }
            .c360-sample-table tr:nth-child(even) { background: #F8FAFC; }
            .c360-sample-idx { color: #94A3B8; font-weight: 600; text-align: center; }
            .c360-empty-preview {
              padding: 1.5rem; text-align: center; color: #64748B; font-size: 0.86rem;
            }

            /* FOOTER */
            .c360-modal-foot {
              background: #FFFFFF; border-top: 1px solid #E2E8F0;
              padding: 1.1rem 1.75rem;
              display: flex; justify-content: space-between; align-items: center;
              flex-wrap: wrap; gap: 1rem;
            }
            .c360-foot-status {
              font-size: 0.85rem; color: #475569; font-weight: 600;
              display: flex; align-items: center; gap: 0.5rem;
            }
            .c360-foot-status-dot {
              width: 8px; height: 8px; border-radius: 50%; background: #10B981;
            }
            .c360-foot-actions {
              display: flex; align-items: center; gap: 0.75rem;
            }
            .c360-btn-ghost {
              background: transparent; border: 1.5px solid #CBD5E1; color: #475569;
              font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.25rem;
              border-radius: 8px; cursor: pointer; transition: all 0.2s;
            }
            .c360-btn-ghost:hover { background: #F1F5F9; color: #0F172A; }
            .c360-btn-approve {
              background: #10B981; border: none; color: #FFFFFF;
              font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.4rem;
              border-radius: 8px; cursor: pointer;
              display: inline-flex; align-items: center; gap: 0.5rem;
              box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
              transition: all 0.2s;
            }
            .c360-btn-approve:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35); }
          </style>

          <div class="c360-preview-dialog" onclick="event.stopPropagation()">
            <!-- HEADER -->
            <div class="c360-modal-head">
              <div>
                <h3 class="c360-modal-title">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                  Data Contract Mapping Preview: ${escapeHtml(result.file.original_name)}
                </h3>
                <div class="c360-modal-meta-row">
                  <span class="c360-chip c360-chip-cyan">Target Table: <strong>${escapeHtml(result.target_table)}</strong></span>
                  <span class="c360-chip c360-chip-green">Database: <strong>${escapeHtml(result.database_name)}</strong></span>
                  ${result.dataset_type === 'TRANSACTION'
                    ? `<span class="c360-chip" style="background: rgba(14, 165, 233, 0.2); color: #00CFEF; border: 1px solid rgba(0, 207, 239, 0.35);">📅 PERIOD DATA (${escapeHtml(result.active_period ? (result.active_period.period_name || ('Period ' + result.active_period.period_id)) : 'Active Period')})</span>`
                    : `<span class="c360-chip" style="background: rgba(168, 85, 247, 0.2); color: #D8B4FE; border: 1px solid rgba(168, 85, 247, 0.35);">⚡ MASTER DATA (Persistent Upsert)</span>`
                  }
                  <span class="c360-chip c360-chip-gray">📊 ${result.file.columns_count} Columns</span>
                  <span class="c360-chip c360-chip-gray">📝 ${result.file.rows_count} Rows</span>
                  ${result.is_saved_mapping ? `<span class="c360-chip" style="background: rgba(16, 185, 129, 0.2); color: #34D399; border: 1px solid rgba(52, 211, 153, 0.4);">💾 SAVED MAPPING ACTIVE</span>` : ''}
                </div>
              </div>
              <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
            </div>

            <!-- ENFORCED SCOPE BANNER -->
            <div class="c360-scope-banner">
              <div>
                <strong style="color: #0369A1;">Enforced Scope:</strong>
                Customer: <span class="c360-scope-tag">${escapeHtml(orgContext.customer_code || '—')}</span> &bull;
                Company: <span class="c360-scope-tag">${escapeHtml(orgContext.company_code || '—')}</span> &bull;
                Location: <span class="c360-scope-tag">${escapeHtml(orgContext.location_code || '—')}</span> &bull;
                Period: <span class="c360-scope-tag">${escapeHtml(result.active_period ? (result.active_period.period_name || ('Period ' + result.active_period.period_id)) : (orgContext.period_name || 'Active Period'))}</span>
              </div>
              <div class="c360-security-notice">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Context enforced server-side against workbook tampering
              </div>
            </div>

            <!-- PRE-FLIGHT RECORD PROFILING BANNER -->
            <div style="background: #F8FAFC; border-bottom: 1px solid #E2E8F0; padding: 0.75rem 1.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
              <div style="display: flex; align-items: center; gap: 0.85rem; font-size: 0.84rem; flex-wrap: wrap;">
                <strong style="color: #0F172A;">Pre-flight Record Profiling:</strong>
                <span style="color: #059669; font-weight: 700; background: #DCFCE7; border: 1px solid #BBF7D0; padding: 0.2rem 0.6rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.3rem;">
                  ➕ New (Insert): ${(result.preview_summary?.new_records || 0).toLocaleString()}
                </span>
                <span style="color: #D97706; font-weight: 700; background: #FEF3C7; border: 1px solid #FDE68A; padding: 0.2rem 0.6rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.3rem;">
                  🔄 Existing (Update): ${(result.preview_summary?.update_records || 0).toLocaleString()}
                </span>
                <span style="color: #475569; font-weight: 700; background: #E2E8F0; border: 1px solid #CBD5E1; padding: 0.2rem 0.6rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.3rem;">
                  ✔ Unchanged (Preserve): ${(result.preview_summary?.unchanged_records || 0).toLocaleString()}
                </span>
              </div>
              <div style="font-size: 0.78rem; color: #475569; font-weight: 600;">
                ${result.dataset_type === 'MASTER' ? 'Idempotent upsert &bull; Creation audit preserved' : 'Period scoped &bull; Cross-period isolation enforced'}
              </div>
            </div>

            <!-- BODY -->
            <div class="c360-modal-body">
              ${(result.validation_errors && result.validation_errors.length > 0) ? `
                <div style="padding: 0.85rem 1.25rem; background: #FEF2F2; border: 1.5px solid #FCA5A5; border-radius: 8px; color: #991B1B; margin-bottom: 1.25rem; font-size: 0.86rem;">
                  <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700; margin-bottom: 0.35rem;">
                    <span style="font-size: 1.15rem;">⛔</span>
                    <span>Data Integrity Validation: ${result.validation_errors.length} blocking error(s) detected in sample rows</span>
                  </div>
                  <div style="font-size: 0.8rem; color: #B91C1C; line-height: 1.5;">
                    ${result.validation_errors.slice(0, 4).map(v => '• ' + escapeHtml(v.error_message || v.message || '')).join('<br>')}
                    ${result.validation_errors.length > 4 ? '<br><em>• ...and ' + (result.validation_errors.length - 4) + ' more error(s)</em>' : ''}
                  </div>
                </div>
              ` : ''}
              ${(result.validation_warnings && result.validation_warnings.length > 0) ? `
                <div style="padding: 0.85rem 1.25rem; background: #FFFBEB; border: 1.5px solid #FCD34D; border-radius: 8px; color: #92400E; margin-bottom: 1.25rem; font-size: 0.86rem;">
                  <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700; margin-bottom: 0.35rem;">
                    <span style="font-size: 1.15rem;">⚠️</span>
                    <span>Data Integrity Advisory: ${result.validation_warnings.length} warning(s) detected in sample rows</span>
                  </div>
                  <div style="font-size: 0.8rem; color: #B45309; line-height: 1.5;">
                    ${result.validation_warnings.slice(0, 4).map(w => '• ' + escapeHtml(w.error_message || w.message || '')).join('<br>')}
                    ${result.validation_warnings.length > 4 ? '<br><em>• ...and ' + (result.validation_warnings.length - 4) + ' more warning(s)</em>' : ''}
                  </div>
                </div>
              ` : ''}
              ${(result.date_violations && result.date_violations.length > 0) ? `
                <div style="padding: 0.85rem 1.25rem; background: #FEF2F2; border: 1.5px solid #FCA5A5; border-radius: 8px; color: #991B1B; margin-bottom: 1.25rem; font-size: 0.86rem;">
                  <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700; margin-bottom: 0.35rem;">
                    <span style="font-size: 1.15rem;">⚠️</span>
                    <span>Date Range Warning: ${result.date_violations.length} sample row(s) contain transaction dates outside the active period (${escapeHtml(result.active_period ? (result.active_period.period_name + ' [' + (result.active_period.start_date || '') + ' to ' + (result.active_period.end_date || '') + ']') : 'Active Period')})</span>
                  </div>
                  <div style="font-size: 0.8rem; color: #B91C1C; line-height: 1.5;">
                    ${result.date_violations.slice(0, 4).map(v => '• ' + escapeHtml(v.message)).join('<br>')}
                    ${result.date_violations.length > 4 ? '<br><em>• ...and ' + (result.date_violations.length - 4) + ' more rows</em>' : ''}
                  </div>
                </div>
              ` : ''}
              ${(result.period_error) ? `
                <div style="padding: 0.85rem 1.25rem; background: #FEF2F2; border: 1.5px solid #FCA5A5; border-radius: 8px; color: #991B1B; margin-bottom: 1.25rem; font-size: 0.86rem;">
                  <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
                    <span style="font-size: 1.15rem;">🚫</span>
                    <span>${escapeHtml(result.period_error)}</span>
                  </div>
                </div>
              ` : ''}
              <div class="c360-section-title-bar">
                <h4 class="c360-section-heading">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
                  Source Sheet Column &rarr; Customer Database Field Mapping
                </h4>

                <!-- Search and Quick Filter Pills -->
                <div class="c360-filter-bar">
                  <div class="c360-search-box">
                    <svg class="c360-search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="c360-search-input" placeholder="Search column..." oninput="window.c360FilterMapping(this.value)">
                  </div>
                  <button class="c360-filter-tab active" data-filter="all" onclick="window.c360SetTabFilter('all', this)">All (${headers.length})</button>
                  <button class="c360-filter-tab" data-filter="matched" onclick="window.c360SetTabFilter('matched', this)">Matched (${directMatchCount})</button>
                  <button class="c360-filter-tab" data-filter="system" onclick="window.c360SetTabFilter('system', this)">System (${sysCount})</button>
                  <button class="c360-filter-tab" data-filter="audit" onclick="window.c360SetTabFilter('audit', this)">Audit (${auditCount})</button>
                </div>
              </div>

              <!-- TABLE CARD -->
              <div class="c360-table-card">
                <table class="c360-mapping-table">
                  <thead>
                    <tr>
                      <th style="width: 35px; text-align: center;">#</th>
                      <th style="width: 34%;">Source Sheet Column (Excel)</th>
                      <th style="width: 30px; text-align: center;"></th>
                      <th style="width: 40%;">Target Customer DB Field (PostgreSQL)</th>
                      <th style="width: 18%; text-align: right;">Contract Status</th>
                    </tr>
                  </thead>
                  <tbody id="c360-mapping-tbody">
                    ${mappingRowsHtml}
                  </tbody>
                </table>
              </div>

              <!-- SAMPLE DATA ACCORDION/CARD -->
              <div class="c360-section-title-bar" style="margin-top: 1rem;">
                <h4 class="c360-section-heading">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                  Worksheet Sample Records (First 25 Rows)
                </h4>
                <span style="font-size: 0.78rem; color: #64748B; font-weight: 600;">${data.length} preview row(s) loaded</span>
              </div>
              <div class="c360-table-card">
                ${sampleDataHtml}
              </div>
            </div>

            <!-- FOOTER -->
            <div class="c360-modal-foot">
              <div class="c360-foot-status">
                <span class="c360-foot-status-dot"></span>
                <span><strong>${headers.length} of ${headers.length}</strong> columns validated & ready to import</span>
              </div>
              <div class="c360-foot-actions">
                <button class="c360-btn-ghost" onclick="this.closest('.c360-modal-backdrop').remove()">Close Preview</button>
                <button class="c360-btn-approve" id="c360-btn-approve-mapping" onclick="window.c360ApproveMapping(${result.file.id}, this)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                  Approve &amp; Save Mapping
                </button>
              </div>
            </div>
          </div>
        </div>
      `;

      // Helper functions for dynamic searching and tab filters
      window.c360ActiveTabFilter = 'all';
      window.c360ActiveSearchQuery = '';

      window.c360OnMappingSelectChange = function(selectEl) {
        const row = selectEl.closest('.c360-mapping-row');
        if (!row) return;
        const val = selectEl.value;
        const colName = row.getAttribute('data-col') || '';
        row.setAttribute('data-target', val.toLowerCase());

        const badgeCol = row.querySelector('.c360-col-badge');
        if (badgeCol) {
          if (val === '__IGNORE__') {
            row.className = 'c360-mapping-row filter-skipped';
            badgeCol.innerHTML = '<span class="c360-badge c360-badge-gray">Skipped</span>';
          } else if (val.toLowerCase() === colName.replace(/[^a-z0-9_]/gi, '').toLowerCase()) {
            row.className = 'c360-mapping-row filter-matched';
            badgeCol.innerHTML = '<span class="c360-badge c360-badge-green"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Direct Match</span>';
          } else {
            row.className = 'c360-mapping-row filter-matched';
            badgeCol.innerHTML = '<span class="c360-badge c360-badge-cyan"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Custom Mapped</span>';
          }
        }

        // Update footer counts
        const allSelects = document.querySelectorAll('#c360-mapping-tbody .c360-mapping-select');
        const activeCount = Array.from(allSelects).filter(s => s.value !== '__IGNORE__').length;
        const statusSpan = document.querySelector('.c360-foot-status span');
        if (statusSpan) {
          statusSpan.innerHTML = `<strong>${activeCount} of ${allSelects.length}</strong> columns validated & ready to import`;
        }
      };

      window.c360ApproveMapping = async function(fileId, btnEl) {
        if (!fileId) return;
        const selects = document.querySelectorAll('#c360-mapping-tbody .c360-mapping-select');
        const mapping = {};
        selects.forEach(sel => {
          const src = sel.getAttribute('data-source-header');
          if (src) {
            mapping[src] = sel.value;
          }
        });

        // Validate duplicate target assignments (Phase 2A - Change 1)
        const targetUsage = {};
        const duplicates = [];
        for (const [src, tgt] of Object.entries(mapping)) {
          if (tgt && tgt !== '__IGNORE__') {
            const lowerTgt = tgt.toLowerCase();
            if (!targetUsage[lowerTgt]) {
              targetUsage[lowerTgt] = [src];
            } else {
              targetUsage[lowerTgt].push(src);
              if (targetUsage[lowerTgt].length === 2) {
                duplicates.push(tgt);
              }
            }
          }
        }

        if (duplicates.length > 0) {
          alert('Cannot approve mapping: Conflicting target column assignments detected.\n\nThe following target column(s) are assigned to multiple source columns: ' + duplicates.join(', ') + '.\n\nPlease resolve conflicts or select __IGNORE__ for extra columns before approving.');
          return;
        }

        const origHtml = btnEl.innerHTML;
        btnEl.disabled = true;
        btnEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="animation: c360Spin 1s linear infinite;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Saving Mapping...';

        try {
          const fd = new FormData();
          fd.append('action', 'save_mapping');
          fd.append('file_id', fileId);
          fd.append('mapping', JSON.stringify(mapping));

          const res = await fetch('api/upload_api.php', { method: 'POST', body: fd });
          const json = await res.json();

          if (!json.ok) {
            btnEl.disabled = false;
            btnEl.innerHTML = origHtml;
            alert(json.message || 'Failed to save column mapping.');
            return;
          }

          // Visual confirmation on button
          btnEl.style.background = '#059669';
          btnEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Mapping Saved!';

          // Update main queue card if present in DOM
          const savedTag = document.getElementById(`savedMapTag_${fileId}`);
          if (savedTag) {
            savedTag.style.display = 'inline-flex';
            savedTag.innerHTML = '💾 Saved Mapping Applied';
          }

          // Update in-memory worksheets
          const fIdx = analyzedWorksheets.findIndex(f => f.id == fileId);
          if (fIdx !== -1) {
            analyzedWorksheets[fIdx].has_saved_mapping = true;
            analyzedWorksheets[fIdx].mapping = 'Saved Mapping Applied';
            analyzedWorksheets[fIdx].status = 'Ready';
          }

          setTimeout(() => {
            const modal = document.getElementById('cost360-preview-modal-root');
            if (modal) modal.remove();
          }, 600);
        } catch (err) {
          btnEl.disabled = false;
          btnEl.innerHTML = origHtml;
          alert('Network or server error while saving mapping: ' + err.message);
        }
      };

      window.c360UpdateRowVisibility = function() {
        const rows = document.querySelectorAll('#c360-mapping-tbody .c360-mapping-row');
        const query = (window.c360ActiveSearchQuery || '').toLowerCase().trim();
        const tab = window.c360ActiveTabFilter || 'all';

        rows.forEach(r => {
          const colName = r.getAttribute('data-col') || '';
          const targetName = r.getAttribute('data-target') || '';
          const matchesQuery = !query || colName.includes(query) || targetName.includes(query);

          let matchesTab = true;
          if (tab === 'matched') matchesTab = r.classList.contains('filter-matched');
          else if (tab === 'system') matchesTab = r.classList.contains('filter-system');
          else if (tab === 'audit') matchesTab = r.classList.contains('filter-audit');
          else if (tab === 'skipped') matchesTab = r.classList.contains('filter-skipped');

          r.style.display = (matchesQuery && matchesTab) ? '' : 'none';
        });
      };

      window.c360FilterMapping = function(query) {
        window.c360ActiveSearchQuery = query;
        window.c360UpdateRowVisibility();
      };

      window.c360SetTabFilter = function(tabName, el) {
        window.c360ActiveTabFilter = tabName;
        document.querySelectorAll('.c360-filter-tab').forEach(t => t.classList.remove('active'));
        if (el) el.classList.add('active');
        window.c360UpdateRowVisibility();
      };

      document.body.insertAdjacentHTML('beforeend', modalHtml);
    } catch (err) {
      alert('Preview Error: ' + err.message);
    }
  }
  window.previewFile = previewFile;

  async function viewImportErrors(uploadId, fallbackErrors = null) {
    ensureModalStyles();
    document.querySelectorAll('.c360-modal-backdrop').forEach(el => el.remove());

    try {
      let errors = [];

      if (uploadId) {
        const formData = new FormData();
        formData.append('action', 'errors');
        formData.append('upload_id', uploadId);

        const response = await fetch('api/upload_api.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.ok && Array.isArray(result.errors)) {
          errors = result.errors;
        }
      }

      if (!errors.length && fallbackErrors && fallbackErrors.length) {
        errors = fallbackErrors.map((v, i) => ({
          id: i + 1,
          upload_id: uploadId || 0,
          sheet_name: v.sheet_name || 'Main Sheet',
          row_number: v.row_number || 'N/A',
          field: v.field || 'N/A',
          error_type: v.error_type || 'Validation Conflict',
          error_message: v.message || v.error_message || 'Data integrity violation',
          severity: v.severity || 'Critical'
        }));
      }

      if (!errors.length) {
        const emptyModalHtml = `
          <div class="c360-modal-backdrop" onclick="this.remove()">
            <div class="c360-preview-dialog" onclick="event.stopPropagation()" style="max-width: 540px;">
              <div class="c360-modal-head" style="background: linear-gradient(135deg, #065F46 0%, #047857 100%);">
                <div>
                  <h3 class="c360-modal-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Clean Import Run
                  </h3>
                  <div class="c360-modal-meta-row">
                    <span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Run ID: #${uploadId || 'N/A'}</span>
                  </div>
                </div>
                <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
              </div>
              <div class="c360-modal-body" style="padding: 1.75rem; text-align: center;">
                <div style="font-size: 2.2rem; margin-bottom: 0.75rem;">🎉</div>
                <h4 style="margin: 0 0 0.5rem; color: #065F46; font-size: 1.1rem; font-weight: 700;">No Errors Logged</h4>
                <p style="color: #475569; font-size: 0.88rem; line-height: 1.5; margin: 0;">
                  No row-level errors were recorded for this upload run. All worksheets and records were verified, mapped, and committed without integrity exceptions.
                </p>
              </div>
              <div class="c360-modal-foot" style="justify-content: flex-end;">
                <button class="c360-btn-ghost" onclick="this.closest('.c360-modal-backdrop').remove()">Close</button>
              </div>
            </div>
          </div>
        `;
        document.body.insertAdjacentHTML('beforeend', emptyModalHtml);
        return;
      }

      // Extract unique sheets for filter dropdown
      const uniqueSheets = [...new Set(errors.map(e => e.sheet_name || 'Main Sheet'))];
      const sheetOptions = uniqueSheets.map(s => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('');

      window.c360ErrorRecords = errors;

      window.c360FilterErrors = function() {
        const q = (document.getElementById('c360-err-search')?.value || '').toLowerCase().trim();
        const sheet = document.getElementById('c360-err-sheet')?.value || 'ALL';
        const rows = document.querySelectorAll('#c360-errors-tbody tr');
        let count = 0;

        rows.forEach(r => {
          const rSheet = r.getAttribute('data-sheet') || '';
          const rText = r.getAttribute('data-search') || '';
          const matchQ = !q || rText.includes(q);
          const matchSheet = (sheet === 'ALL') || (rSheet === sheet);

          if (matchQ && matchSheet) {
            r.style.display = '';
            count++;
          } else {
            r.style.display = 'none';
          }
        });

        const cntEl = document.getElementById('c360-err-count-badge');
        if (cntEl) cntEl.textContent = count;
      };

      window.c360CopyErrorTrace = function() {
        const rows = document.querySelectorAll('#c360-errors-tbody tr');
        let tsv = "Worksheet\tLine\tField\tSeverity\tError Message\n";
        rows.forEach(r => {
          if (r.style.display !== 'none') {
            const cells = r.querySelectorAll('td');
            if (cells.length >= 6) {
              tsv += `${cells[1].innerText.trim()}\t${cells[2].innerText.trim()}\t${cells[3].innerText.trim()}\t${cells[4].innerText.trim()}\t${cells[5].innerText.trim()}\n`;
            }
          }
        });

        navigator.clipboard.writeText(tsv).then(() => {
          const btn = document.getElementById('c360-copy-trace-btn');
          if (btn) {
            const original = btn.innerHTML;
            btn.innerHTML = '✔ Copied to Clipboard!';
            btn.style.background = '#059669';
            setTimeout(() => { btn.innerHTML = original; btn.style.background = ''; }, 2200);
          }
        }).catch(() => {
          prompt('Copy Error Trace:', tsv);
        });
      };

      const rowsHtml = errors.map((err, idx) => {
        const sName = err.sheet_name || 'Main Sheet';
        const rowNum = err.row_number || 'N/A';
        const field = err.field || 'N/A';
        const eType = err.error_type || 'Validation Error';
        const eMsg = err.error_message || err.message || '';
        const sev = (err.severity || 'High').toLowerCase();

        const badgeStyle = sev === 'critical'
          ? 'background: #FEE2E2; color: #991B1B; border: 1.5px solid #F87171; font-weight: 800;'
          : (sev === 'high'
            ? 'background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA;'
            : 'background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A;');

        const searchableText = `${sName} ${rowNum} ${field} ${eType} ${eMsg}`.toLowerCase();

        return `
          <tr style="border-bottom: 1px solid #F1F5F9; font-size: 0.85rem;" data-sheet="${escapeHtml(sName)}" data-search="${escapeHtml(searchableText)}">
            <td style="padding: 0.65rem 0.85rem; color: #94A3B8; font-weight: 600; text-align: center;">${idx + 1}</td>
            <td style="padding: 0.65rem 0.85rem; color: #0F172A; font-weight: 700; white-space: nowrap;">
              <span style="display: inline-flex; align-items: center; gap: 0.35rem;">
                📄 ${escapeHtml(sName)}
              </span>
            </td>
            <td style="padding: 0.65rem 0.85rem; white-space: nowrap;">
              <span style="background: #F1F5F9; color: #334155; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 4px; border: 1px solid #CBD5E1; font-size: 0.8rem;">
                Line ${rowNum}
              </span>
            </td>
            <td style="padding: 0.65rem 0.85rem;">
              <code style="background: #E2E8F0; padding: 0.2rem 0.5rem; border-radius: 4px; color: #0F172A; font-weight: 700; font-size: 0.8rem;">${escapeHtml(field)}</code>
            </td>
            <td style="padding: 0.65rem 0.85rem; white-space: nowrap;">
              <span class="c360-badge" style="${badgeStyle}">
                ${escapeHtml(err.severity || 'High')}
              </span>
              <div style="font-size: 0.72rem; color: #64748B; margin-top: 0.2rem; font-weight: 600;">${escapeHtml(eType)}</div>
            </td>
            <td style="padding: 0.65rem 0.85rem; color: #B91C1C; font-family: 'Consolas', 'Courier New', monospace; font-size: 0.82rem; font-weight: 600; line-height: 1.45;">
              ${escapeHtml(eMsg)}
            </td>
          </tr>
        `;
      }).join('');

      const modalHtml = `
        <div class="c360-modal-backdrop" onclick="this.remove()">
          <div class="c360-preview-dialog" onclick="event.stopPropagation()" style="max-width: 1160px; max-height: 90vh;">
            <!-- HEADER -->
            <div class="c360-modal-head c360-modal-head-danger">
              <div>
                <h3 class="c360-modal-title">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  Row-Level Import Error Traceability Log
                </h3>
                <div class="c360-modal-meta-row">
                  <span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Run ID: #${uploadId || 'Active'}</span>
                  <span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Total Errors: <strong>${errors.length}</strong></span>
                  <span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Logged in Neon PostgreSQL</span>
                </div>
              </div>
              <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
            </div>

            <!-- TOOLBAR -->
            <div style="background: #FFFFFF; border-bottom: 1px solid #E2E8F0; padding: 0.85rem 1.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
              <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <div class="c360-search-box">
                  <svg class="c360-search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  <input type="text" id="c360-err-search" class="c360-search-input" placeholder="Search line, column, error trace..." style="width: 240px;" oninput="window.c360FilterErrors()">
                </div>
                ${uniqueSheets.length > 1 ? `
                  <div style="display: flex; align-items: center; gap: 0.4rem;">
                    <label style="font-size: 0.8rem; color: #64748B; font-weight: 600;">Worksheet:</label>
                    <select id="c360-err-sheet" class="c360-mapping-select" style="width: auto; padding: 0.4rem 0.75rem;" onchange="window.c360FilterErrors()">
                      <option value="ALL">All Worksheets (${errors.length})</option>
                      ${sheetOptions}
                    </select>
                  </div>
                ` : ''}
              </div>

              <div style="display: flex; align-items: center; gap: 0.65rem;">
                <span style="font-size: 0.82rem; color: #64748B; font-weight: 600;">
                  Showing <strong id="c360-err-count-badge" style="color: #DC2626;">${errors.length}</strong> row(s)
                </span>
                <button type="button" id="c360-copy-trace-btn" class="c360-btn-ghost" style="padding: 0.45rem 0.85rem; font-size: 0.8rem;" onclick="window.c360CopyErrorTrace()">
                  📋 Copy Trace
                </button>
                ${uploadId ? `
                  <a href="api/upload_api.php?action=export_errors&upload_id=${uploadId}" class="c360-btn-danger" style="padding: 0.45rem 1rem; font-size: 0.8rem;">
                    &darr; CSV Export
                  </a>
                ` : ''}
              </div>
            </div>

            <!-- TABLE BODY -->
            <div class="c360-modal-body" style="padding: 1.25rem 1.75rem;">
              <div class="c360-table-card" style="margin-bottom: 0;">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                  <thead>
                    <tr style="background: #F1F5F9; color: #475569; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em;">
                      <th style="padding: 0.65rem 0.85rem; width: 35px; text-align: center;">#</th>
                      <th style="padding: 0.65rem 0.85rem; width: 22%;">Worksheet</th>
                      <th style="padding: 0.65rem 0.85rem; width: 100px;">Line #</th>
                      <th style="padding: 0.65rem 0.85rem; width: 16%;">Column / Field</th>
                      <th style="padding: 0.65rem 0.85rem; width: 15%;">Severity & Type</th>
                      <th style="padding: 0.65rem 0.85rem;">Error Trace & Details</th>
                    </tr>
                  </thead>
                  <tbody id="c360-errors-tbody">
                    ${rowsHtml}
                  </tbody>
                </table>
              </div>
            </div>

            <!-- FOOTER -->
            <div class="c360-modal-foot">
              <div style="font-size: 0.82rem; color: #64748B;">
                Tip: Fix highlighted cells in your spreadsheet and upload again to resume import.
              </div>
              <div style="display: flex; gap: 0.75rem;">
                ${uploadId ? `
                  <a href="api/upload_api.php?action=export_errors&upload_id=${uploadId}" class="c360-btn-danger">
                    &darr; Download CSV Error Report
                  </a>
                ` : ''}
                <button class="c360-btn-ghost" onclick="this.closest('.c360-modal-backdrop').remove()">Close Error Log</button>
              </div>
            </div>
          </div>
        </div>
      `;

      document.body.insertAdjacentHTML('beforeend', modalHtml);
    } catch (err) {
      alert('Error Log Fetch Failed: ' + err.message);
    }
  }
  window.viewImportErrors = viewImportErrors;

  function showImportFailureModal(title, message, uploadId = null, violations = null) {
    ensureModalStyles();
    document.querySelectorAll('.c360-modal-backdrop').forEach(el => el.remove());

    const hasErrorsToTrace = Boolean(uploadId || (violations && violations.length));
    const violationCount = violations ? violations.length : null;

    const modalHtml = `
      <div class="c360-modal-backdrop" onclick="this.remove()">
        <div class="c360-preview-dialog" onclick="event.stopPropagation()" style="max-width: 680px;">
          <div class="c360-modal-head c360-modal-head-danger">
            <div>
              <h3 class="c360-modal-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                ${escapeHtml(title || 'Operation Blocked / Failed')}
              </h3>
              <div class="c360-modal-meta-row">
                <span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Integrity Check &bull; Neon PostgreSQL Protection</span>
                ${uploadId ? `<span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Run ID: #${uploadId}</span>` : ''}
              </div>
            </div>
            <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
          </div>

          <div class="c360-modal-body" style="padding: 1.75rem;">
            <div style="background: #FEF2F2; border: 1.5px solid #FCA5A5; border-radius: 10px; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem;">
              <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                <span style="font-size: 1.5rem; line-height: 1;">⚠️</span>
                <div style="flex: 1;">
                  <h4 style="margin: 0 0 0.35rem; color: #991B1B; font-size: 0.95rem; font-weight: 700;">Validation or Execution Conflict Detected</h4>
                  <div style="color: #7F1D1D; font-size: 0.88rem; line-height: 1.5; font-family: 'Consolas', monospace; white-space: pre-wrap; word-break: break-word;">${escapeHtml(message || 'An unexpected error occurred during processing.')}</div>
                </div>
              </div>
            </div>

            <p style="font-size: 0.85rem; color: #64748B; margin: 0 0 1.25rem; line-height: 1.5;">
              All database changes for this operation were safely rolled back. You can inspect the exact line-by-line error trace and export a CSV audit report below to rectify the source file.
            </p>

            ${hasErrorsToTrace ? `
              <div style="display: flex; flex-direction: column; gap: 0.65rem;">
                <button type="button" class="c360-btn-danger" style="width: 100%; justify-content: center; padding: 0.75rem;" onclick="this.closest('.c360-modal-backdrop').remove(); viewImportErrors(${uploadId || 'null'}, ${violations ? JSON.stringify(violations).replace(/"/g, '&quot;') : 'null'})">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                  Inspect Row-by-Row Error Trace ${violationCount ? `(${violationCount} Violations)` : ''}
                </button>
                ${uploadId ? `
                  <a href="api/upload_api.php?action=export_errors&upload_id=${uploadId}" class="c360-btn-ghost" style="text-align: center; justify-content: center; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; padding: 0.65rem;">
                    &darr; Download CSV Error Report
                  </a>
                ` : ''}
              </div>
            ` : ''}
          </div>

          <div class="c360-modal-foot" style="justify-content: flex-end;">
            <button class="c360-btn-ghost" onclick="this.closest('.c360-modal-backdrop').remove()">Dismiss</button>
          </div>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
  }
  window.showImportFailureModal = showImportFailureModal;

  function showImportSummaryModal(result, uploadId) {
    ensureModalStyles();
    document.querySelectorAll('.c360-modal-backdrop').forEach(el => el.remove());

    const hasErrors = (result.failed || 0) > 0;
    const dbName = result.database_name || 'Customer DB';

    const modalHtml = `
      <div class="c360-modal-backdrop" onclick="this.remove()">
        <div class="c360-preview-dialog" onclick="event.stopPropagation()" style="max-width: 680px;">
          <div class="c360-modal-head" style="${hasErrors ? 'background: linear-gradient(135deg, #92400E 0%, #B45309 100%);' : 'background: linear-gradient(135deg, #065F46 0%, #047857 100%);'}">
            <div>
              <h3 class="c360-modal-title">
                ${hasErrors 
                  ? '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Import Completed with Row Errors' 
                  : '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Import Completed Successfully'
                }
              </h3>
              <div class="c360-modal-meta-row">
                <span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Target: <strong>${escapeHtml(dbName)}</strong></span>
                ${result.period ? `<span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Period: <strong>${escapeHtml(result.period)}</strong></span>` : ''}
              </div>
            </div>
            <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
          </div>

          <div class="c360-modal-body" style="padding: 1.75rem;">
            <!-- KPI Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
              <div style="background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #065F46; font-weight: 700; text-transform: uppercase;">Inserted</div>
                <div style="font-size: 1.4rem; color: #047857; font-weight: 800; margin-top: 0.2rem;">${(result.imported || 0).toLocaleString()}</div>
              </div>
              <div style="background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #92400E; font-weight: 700; text-transform: uppercase;">Updated</div>
                <div style="font-size: 1.4rem; color: #B45309; font-weight: 800; margin-top: 0.2rem;">${(result.updated || 0).toLocaleString()}</div>
              </div>
              <div style="background: #F1F5F9; border: 1px solid #CBD5E1; border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #475569; font-weight: 700; text-transform: uppercase;">Unchanged</div>
                <div style="font-size: 1.4rem; color: #334155; font-weight: 800; margin-top: 0.2rem;">${(result.unchanged || 0).toLocaleString()}</div>
              </div>
              <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: #64748B; font-weight: 700; text-transform: uppercase;">Skipped</div>
                <div style="font-size: 1.4rem; color: #64748B; font-weight: 800; margin-top: 0.2rem;">${(result.skipped || 0).toLocaleString()}</div>
              </div>
              <div style="background: ${hasErrors ? '#FEF2F2' : '#F8FAFC'}; border: 1px solid ${hasErrors ? '#FECACA' : '#E2E8F0'}; border-radius: 8px; padding: 0.75rem; text-align: center;">
                <div style="font-size: 0.72rem; color: ${hasErrors ? '#991B1B' : '#64748B'}; font-weight: 700; text-transform: uppercase;">Failed</div>
                <div style="font-size: 1.4rem; color: ${hasErrors ? '#DC2626' : '#64748B'}; font-weight: 800; margin-top: 0.2rem;">${(result.failed || 0).toLocaleString()}</div>
              </div>
            </div>

            ${hasErrors ? `
              <div style="background: #FEF2F2; border: 1.5px solid #FCA5A5; border-radius: 10px; padding: 1.15rem 1.35rem; margin-bottom: 1.5rem;">
                <div style="display: flex; align-items: flex-start; gap: 0.65rem;">
                  <span style="font-size: 1.3rem; line-height: 1;">⚠️</span>
                  <div>
                    <h4 style="margin: 0 0 0.25rem; color: #991B1B; font-size: 0.92rem; font-weight: 700;">${result.failed} Record(s) Encountered Validation Conflicts</h4>
                    <p style="margin: 0; color: #7F1D1D; font-size: 0.82rem; line-height: 1.45;">
                      These rows were skipped while all valid records were safely committed. You can inspect the error trace to examine exact row numbers, field names, and validation rules.
                    </p>
                  </div>
                </div>
              </div>

              <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                <button type="button" class="c360-btn-danger" style="flex: 1; justify-content: center; padding: 0.7rem;" onclick="this.closest('.c360-modal-backdrop').remove(); viewImportErrors(${uploadId || 'null'})">
                  🔍 Inspect Row Error Trace
                </button>
                <a href="api/upload_api.php?action=export_errors&upload_id=${uploadId}" class="c360-btn-ghost" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; padding: 0.7rem;">
                  &darr; Download CSV
                </a>
              </div>
            ` : `
              <div style="background: #ECFDF5; border: 1.5px solid #A7F3D0; border-radius: 10px; padding: 1.15rem 1.35rem; margin-bottom: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 0.65rem;">
                  <span style="font-size: 1.4rem;">🎉</span>
                  <div>
                    <h4 style="margin: 0 0 0.25rem; color: #065F46; font-size: 0.92rem; font-weight: 700;">All Records Successfully Verified & Committed</h4>
                    <p style="margin: 0; color: #047857; font-size: 0.82rem;">0 errors recorded. Your customer database has been cleanly updated.</p>
                  </div>
                </div>
              </div>
            `}
          </div>

          <div class="c360-modal-foot">
            <button type="button" class="c360-btn-secondary" style="background: #F0FDF4; border: 1px solid #86EFAC; color: #166534; font-weight: 700;" onclick="this.closest('.c360-modal-backdrop').remove(); window.openFinancialReconciliationModal(0.00);">
              ⚖️ Reconcile Trial Balance
            </button>
            <a href="dashboard.php?page=history" class="c360-btn-secondary" style="text-decoration: none;">
              Go to Import History &rarr;
            </a>
            <button class="c360-btn-ghost" onclick="this.closest('.c360-modal-backdrop').remove()">Close</button>
          </div>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
  }
  window.showImportSummaryModal = showImportSummaryModal;

  async function executeImport() {
    if (!currentUploadRunId) return alert('No active upload run found to import.');

    const selectedCheckboxes = $$('.sheet-checkbox:checked');
    if (!selectedCheckboxes.length) {
      return alert('Please select at least one worksheet to import.');
    }

    const selectedIds = selectedCheckboxes.map(cb => cb.getAttribute('data-file-id'));

    // Check if any selected worksheet is period-dependent
    const hasTxSelected = analyzedWorksheets.some(f => 
      selectedIds.includes(String(f.id)) && !isMasterDataset(f.target_table)
    );

    if (hasTxSelected) {
      if (!activePeriod || !activePeriod.period_id) {
        showImportFailureModal(
          'Missing Active Accounting Period',
          'A valid accounting period must be selected in the Global Scope selector (header) before importing period-dependent transactions.\n\n' +
          (activePeriodError ? `Details: ${activePeriodError}\n\n` : '') +
          'Please select an active accounting period using the global scope selector at the top of the screen.'
        );
        return;
      }
      if (activePeriod.is_locked) {
        showImportFailureModal(
          'Accounting Period Locked',
          `Cannot import: The active accounting period "${activePeriod.period_name}" is LOCKED. Transaction records cannot be imported into a locked accounting period.`
        );
        return;
      }
      if (activePeriodError) {
        showImportFailureModal(
          'Active Accounting Period Error',
          `${activePeriodError}\n\nPlease verify your active scope in the global header.`
        );
        return;
      }
    }

    const confirmMsg = hasTxSelected 
      ? `Are you sure you want to import ${selectedIds.length} worksheet(s) using the active accounting period "${activePeriod.period_name}" (${formatDisplayRange(activePeriod.start_date, activePeriod.end_date)}) into your dedicated Customer Database?\n\nMaster data will be upserted idempotently, and period-dependent transactions will be scoped to the active global period.`
      : `Are you sure you want to import ${selectedIds.length} master data worksheet(s) into your dedicated Customer Database?\n\nMaster data records will be inserted or updated idempotently.`;

    if (!confirm(confirmMsg)) {
      return;
    }

    importBtn.disabled = true;
    importBtn.innerHTML = '<span class="spinner"></span> Importing to Customer DB...';

    try {
      const formData = new FormData();
      formData.append('action', 'import');
      formData.append('upload_id', currentUploadRunId);
      if (activePeriod && activePeriod.period_id) {
        formData.append('preview_period_id', activePeriod.period_id);
        formData.append('period_id', activePeriod.period_id);
      }

      selectedIds.forEach(id => formData.append('file_ids[]', id));

      const response = await fetch('api/upload_api.php', { method: 'POST', body: formData });
      const result = await response.json();

      importBtn.disabled = false;
      importBtn.innerHTML = `Confirm & Import (${selectedIds.length} Selected)`;

      if (!result.ok) {
        showImportFailureModal(
          'Import Execution Failed',
          result.message || 'Import failed.',
          result.upload_id || currentUploadRunId,
          result.violations || null
        );
        return;
      }

      showImportSummaryModal(result, result.upload_id || currentUploadRunId);
    } catch (err) {
      importBtn.disabled = false;
      importBtn.innerHTML = `Confirm & Import (${selectedIds.length} Selected)`;
      showImportFailureModal('Import Execution Error', err.message, currentUploadRunId);
    }
  }

  async function openFinancialReconciliationModal(tolerance = 0.00) {
    const existing = $('.c360-recon-modal-backdrop');
    if (existing) existing.remove();

    const loadingHtml = `
      <div class="c360-modal-backdrop c360-recon-modal-backdrop" style="position: fixed; inset: 0; background: rgba(3, 27, 50, 0.75); display: flex; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px);">
        <div class="c360-modal-card" style="background: #fff; width: 90%; max-width: 900px; max-height: 90vh; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
          <div class="c360-modal-head" style="padding: 1.25rem 1.75rem; background: #033665; color: #fff; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700;">⚖️ Financial Reconciliation (Trial Balance & GL)</h3>
            <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
          </div>
          <div class="c360-modal-body" style="padding: 3rem; text-align: center;">
            <div class="spinner" style="margin: 0 auto 1rem; width: 40px; height: 40px; border: 4px solid #CBD5E1; border-top-color: #0284C7; border-radius: 50%; animation: c360Spin 1s linear infinite;"></div>
            <p style="color: #475569; font-weight: 600;">Running Financial Reconciliation across Customer Database...</p>
          </div>
        </div>
      </div>
    `;
    document.body.insertAdjacentHTML('beforeend', loadingHtml);

    try {
      const formData = new FormData();
      formData.append('action', 'financial_reconciliation_overview');
      formData.append('tolerance', tolerance);
      if (activePeriod && activePeriod.period_id) {
        formData.append('period_id', activePeriod.period_id);
      }

      const response = await fetch('api/upload_api.php', { method: 'POST', body: formData });
      const res = await response.json();

      const modalEl = $('.c360-recon-modal-backdrop');
      if (!modalEl) return;

      if (!res.ok) {
        modalEl.querySelector('.c360-modal-body').innerHTML = `
          <div style="padding: 1.5rem; text-align: center; color: #DC2626;">
            <h4>Reconciliation Failed</h4>
            <p>${escapeHtml(res.message || 'Error occurred during reconciliation.')}</p>
          </div>
        `;
        return;
      }

      const tb = res.trial_balance || {};
      const inv = res.inventory_gl || {};
      const closing = tb.closing || {};
      const trans = tb.transacted || {};
      const open = tb.opening || {};
      const integrity = tb.integrity || {};

      const isBalanced = (tb.status === 'Balanced');
      const isUnbalanced = (tb.status === 'Unbalanced');
      const statusBg = isBalanced ? '#ECFDF5' : (isUnbalanced ? '#FEF2F2' : '#FFFBEB');
      const statusBorder = isBalanced ? '#A7F3D0' : (isUnbalanced ? '#FCA5A5' : '#FCD34D');
      const statusColor = isBalanced ? '#065F46' : (isUnbalanced ? '#991B1B' : '#92400E');
      const statusIcon = isBalanced ? '🟢' : (isUnbalanced ? '🔴' : '⚠️');

      const dupCount = (integrity.duplicates || []).length;
      const missCount = (integrity.missing_accounts || []).length;
      const signCount = (integrity.invalid_signs || []).length;
      const flowCount = (integrity.flow_discrepancies || []).length;
      const totalIssues = dupCount + missCount + signCount + flowCount;

      modalEl.querySelector('.c360-modal-body').innerHTML = `
        <!-- Scope Bar -->
        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; font-size: 0.85rem;">
          <div>
            <strong>Company:</strong> <code>${escapeHtml(res.company_code || '—')}</code> &bull;
            <strong>Period:</strong> ${escapeHtml(res.period_name || ('Period ' + (res.period_id || 'Active')))}
          </div>
          <div style="display: flex; align-items: center; gap: 0.4rem;">
            <label style="font-weight: 600; color: #475569;">Tolerance:</label>
            <select onchange="window.openFinancialReconciliationModal(parseFloat(this.value))" style="padding: 0.3rem 0.5rem; border-radius: 4px; border: 1px solid #CBD5E1; font-size: 0.8rem;">
              <option value="0.00" ${tolerance === 0 ? 'selected' : ''}>Exact (0.00)</option>
              <option value="0.01" ${tolerance === 0.01 ? 'selected' : ''}>0.01</option>
              <option value="1.00" ${tolerance === 1 ? 'selected' : ''}>1.00</option>
              <option value="10.00" ${tolerance === 10 ? 'selected' : ''}>10.00</option>
            </select>
          </div>
        </div>

        <!-- Status Banner -->
        <div style="padding: 1rem 1.25rem; background: ${statusBg}; border: 1.5px solid ${statusBorder}; border-radius: 8px; color: ${statusColor}; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
          <span style="font-size: 1.5rem;">${statusIcon}</span>
          <div>
            <div style="font-weight: 800; font-size: 1.05rem;">Trial Balance Status: ${escapeHtml(tb.status || 'Unable to Reconcile')}</div>
            <div style="font-size: 0.84rem; margin-top: 0.15rem;">${escapeHtml(tb.message || '')}</div>
          </div>
        </div>

        <!-- KPI Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 1.25rem;">
          <div style="background: #F0F9FF; border: 1px solid #BAE6FD; border-radius: 8px; padding: 0.75rem; text-align: center;">
            <div style="font-size: 0.72rem; color: #0369A1; font-weight: 700; text-transform: uppercase;">Closing Debits</div>
            <div style="font-size: 1.25rem; color: #0284C7; font-weight: 800; margin-top: 0.2rem;">${(closing.debit || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
          </div>
          <div style="background: #F0F9FF; border: 1px solid #BAE6FD; border-radius: 8px; padding: 0.75rem; text-align: center;">
            <div style="font-size: 0.72rem; color: #0369A1; font-weight: 700; text-transform: uppercase;">Closing Credits</div>
            <div style="font-size: 1.25rem; color: #0284C7; font-weight: 800; margin-top: 0.2rem;">${(closing.credit || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
          </div>
          <div style="background: ${closing.is_balanced ? '#ECFDF5' : '#FEF2F2'}; border: 1px solid ${closing.is_balanced ? '#A7F3D0' : '#FECACA'}; border-radius: 8px; padding: 0.75rem; text-align: center;">
            <div style="font-size: 0.72rem; color: ${closing.is_balanced ? '#065F46' : '#991B1B'}; font-weight: 700; text-transform: uppercase;">Net Variance</div>
            <div style="font-size: 1.25rem; color: ${closing.is_balanced ? '#059669' : '#DC2626'}; font-weight: 800; margin-top: 0.2rem;">${(closing.difference || 0).toFixed(4)}</div>
          </div>
          <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 0.75rem; text-align: center;">
            <div style="font-size: 0.72rem; color: #475569; font-weight: 700; text-transform: uppercase;">Accounts Audited</div>
            <div style="font-size: 1.25rem; color: #1E293B; font-weight: 800; margin-top: 0.2rem;">${(tb.record_count || 0).toLocaleString()}</div>
          </div>
        </div>

        <!-- Double Entry Table -->
        <div style="border: 1px solid #E2E8F0; border-radius: 8px; overflow: hidden; margin-bottom: 1.25rem;">
          <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
              <tr style="background: #F1F5F9; color: #475569; border-bottom: 1px solid #E2E8F0; text-align: left;">
                <th style="padding: 0.6rem 0.85rem;">Balance Category</th>
                <th style="padding: 0.6rem 0.85rem; text-align: right;">Total Debit</th>
                <th style="padding: 0.6rem 0.85rem; text-align: right;">Total Credit</th>
                <th style="padding: 0.6rem 0.85rem; text-align: right;">Difference</th>
                <th style="padding: 0.6rem 0.85rem; text-align: center;">State</th>
              </tr>
            </thead>
            <tbody>
              <tr style="border-bottom: 1px solid #F1F5F9;">
                <td style="padding: 0.6rem 0.85rem;">Opening Balance</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right;">${(open.debit || 0).toFixed(2)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right;">${(open.credit || 0).toFixed(2)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right; font-weight: 700;">${(open.difference || 0).toFixed(4)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: center;"><span style="color: ${open.is_balanced ? '#059669' : '#DC2626'}; font-weight: 700;">${open.is_balanced ? 'Balanced' : 'Unbalanced'}</span></td>
              </tr>
              <tr style="border-bottom: 1px solid #F1F5F9;">
                <td style="padding: 0.6rem 0.85rem;">Period Movements</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right;">${(trans.debit || 0).toFixed(2)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right;">${(trans.credit || 0).toFixed(2)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right; font-weight: 700;">${(trans.difference || 0).toFixed(4)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: center;"><span style="color: ${trans.is_balanced ? '#059669' : '#DC2626'}; font-weight: 700;">${trans.is_balanced ? 'Balanced' : 'Unbalanced'}</span></td>
              </tr>
              <tr style="background: #F8FAFC; font-weight: 700;">
                <td style="padding: 0.6rem 0.85rem; color: #0284C7;">Closing Balance</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right; color: #0284C7;">${(closing.debit || 0).toFixed(2)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right; color: #0284C7;">${(closing.credit || 0).toFixed(2)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: right; color: ${closing.is_balanced ? '#059669' : '#DC2626'};">${(closing.difference || 0).toFixed(4)}</td>
                <td style="padding: 0.6rem 0.85rem; text-align: center;"><span style="color: ${closing.is_balanced ? '#059669' : '#DC2626'}; font-weight: 800;">${closing.is_balanced ? 'Balanced' : 'Unbalanced'}</span></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Inventory to GL Architectural Gap Notice -->
        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.82rem; color: #475569;">
          <strong style="color: #0369A1;">Inventory Valuation & GL Control Account Assessment:</strong><br>
          ${escapeHtml(inv.gap_report || 'Canonical schema lacks item-to-GL account mappings.')}
          <div style="margin-top: 0.4rem;">
            Period Material Transactions: <strong>${(inv.material_transactions?.total_records || 0)} records</strong> (Valuation: <strong>$${(inv.material_transactions?.total_amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</strong>) &bull;
            Candidate GL Inventory Accounts: <strong>${(inv.candidate_gl_inventory?.accounts_count || 0)} accounts</strong> (Closing Net: <strong>$${(inv.candidate_gl_inventory?.total_closing_net || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</strong>)
          </div>
        </div>

        ${totalIssues > 0 ? `
          <div style="padding: 0.75rem 1rem; background: #FEF2F2; border: 1px solid #FCA5A5; border-radius: 8px; color: #991B1B; font-size: 0.82rem; margin-bottom: 0.5rem;">
            <strong>Integrity Warnings:</strong> Detected ${totalIssues} issue(s) (Duplicates: ${dupCount}, Missing Codes: ${missCount}, Flow Variances: ${flowCount}).
          </div>
        ` : ''}
      `;

      // Footer
      const foot = modalEl.querySelector('.c360-modal-card');
      const footHtml = `
        <div class="c360-modal-foot" style="padding: 1rem 1.5rem; background: #F8FAFC; border-top: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center;">
          <a href="reconciliation.php?tolerance=${tolerance}" class="c360-btn-secondary" style="text-decoration: none; font-size: 0.84rem;">
            Open Full CMA Reconciliation Desk &rarr;
          </a>
          <button class="c360-btn-ghost" onclick="this.closest('.c360-modal-backdrop').remove()">Close</button>
        </div>
      `;
      foot.insertAdjacentHTML('beforeend', footHtml);
    } catch (err) {
      alert('Error running financial reconciliation: ' + err.message);
      const modalEl = $('.c360-recon-modal-backdrop');
      if (modalEl) modalEl.remove();
    }
  }
  window.openFinancialReconciliationModal = openFinancialReconciliationModal;

  function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(2) + ' MB';
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>'"]/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[c]));
  }

  document.addEventListener('DOMContentLoaded', init);
})();
