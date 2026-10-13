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
      alert('Upload Analysis Error: ' + err.message);
      analyzeBtn.disabled = false;
      analyzeBtn.innerHTML = 'Analyze Files';
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

    const hasTransactions = files.some(f => f.dataset_type === 'TRANSACTION' || ['material_transaction','downtime','finance_transaction','trial_balance'].includes(f.target_table));
    let periodToolbarHtml = '';
    if (hasTransactions) {
      const pOptions = (availablePeriods.length ? availablePeriods : [{period_id: 1, period_name: 'October 2026', period_code: '2026-10', is_locked: false}]).map(p => {
        const isSel = (activePeriod && (p.period_id == activePeriod.period_id || p.period_code == activePeriod.period_code)) ? 'selected' : '';
        const lockLabel = p.is_locked ? ' [LOCKED]' : ' [OPEN]';
        return `<option value="${p.period_id}" data-locked="${p.is_locked ? '1' : '0'}" ${isSel}>${escapeHtml(p.period_name || p.period_code || ('Period ' + p.period_id))}${lockLabel}</option>`;
      }).join('');

      periodToolbarHtml = `
        <div class="c360-period-bar" style="display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1.25rem; background: #EFF6FF; border: 1.5px solid #BFDBFE; border-radius: 8px; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
          <div style="display: flex; align-items: center; gap: 0.75rem;">
            <span style="font-size: 1.25rem;">&#128197;</span>
            <label for="c360SelectedPeriod" style="margin: 0; color: #1E3A8A; font-size: 0.92rem; font-weight: 700;">Target Accounting Period for Transaction Sheets:</label>
            <select id="c360SelectedPeriod" class="cost360-select" style="background: #FFFFFF; color: #0F172A; border: 1.5px solid #93C5FD; border-radius: 6px; padding: 0.4rem 0.75rem; font-size: 0.86rem; font-weight: 700; cursor: pointer;">
              ${pOptions}
            </select>
          </div>
          <span style="color: #1D4ED8; font-size: 0.8rem; font-weight: 600;">
            Transactions are period-scoped &bull; Locked periods prevent accidental overwrites
          </span>
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
      const isTx = file.dataset_type === 'TRANSACTION' || ['material_transaction','downtime','finance_transaction','trial_balance'].includes(file.target_table);
      const datasetBadge = isTx
        ? `<span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #EFF6FF; border: 1px solid #BFDBFE; color: #0284C7; font-size: 0.74rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 4px;">📅 PERIOD TRANSACTION</span>`
        : `<span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #FAF5FF; border: 1px solid #E9D5FF; color: #7C3AED; font-size: 0.74rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 4px;">⚡ MASTER UPSERT</span>`;

      const confBadgeClass = file.confidence >= 90 ? 'conf-high' : (file.confidence >= 70 ? 'conf-med' : 'conf-low');
      const mgSelectOptions = masterGroupOptions.map(g => {
        const selected = (g.name === file.master || g.id == file.master_group_id) ? 'selected' : '';
        return `<option value="${g.id}" ${selected}>${escapeHtml(g.name)}</option>`;
      }).join('');

      const selectedMg = masterGroupOptions.find(g => g.name === file.master || g.id == file.master_group_id) || masterGroupOptions[0];
      const subs = selectedMg ? selectedMg.sub_categories : [];

      const subSelectOptions = subs.map(s => {
        const selected = (s.name === file.sub || s.id == file.sub_category_id) ? 'selected' : '';
        return `<option value="${s.id}" ${selected}>${escapeHtml(s.name)} (${escapeHtml(s.table_name || 'table')})</option>`;
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
              <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #F0FDF4; border: 1px solid #BBF7D0; color: #15803D; font-size: 0.76rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 4px;">
                Target: <code style="background: transparent; color: #166534; font-weight: 700; padding: 0;">${escapeHtml(file.target_table)}</code>
              </span>
              <span style="color: #CBD5E1;">&bull;</span>
              <span><strong>${file.rows.toLocaleString()}</strong> data rows</span>
              <span style="color: #CBD5E1;">&bull;</span>
              <span><strong>${file.columns}</strong> columns</span>
              ${warningPills}
            </div>
          </div>

          <div class="dropdown-control-group" style="min-width: 170px;">
            <label class="dropdown-label" style="color: #64748B; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; display: block;">Master Category</label>
            <select class="cost360-select" onchange="onMasterGroupChange(this, ${file.id})" style="background: #FFFFFF; color: #0F172A; border: 1px solid #CBD5E1; border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.84rem; font-weight: 600; cursor: pointer;">
              ${mgSelectOptions}
            </select>
          </div>

          <div class="dropdown-control-group" style="min-width: 190px;">
            <label class="dropdown-label" style="color: #64748B; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; display: block;">Target Entity</label>
            <select class="cost360-select" id="subSelect_${file.id}" onchange="onSubCategoryChange(this, ${file.id})" style="background: #FFFFFF; color: #0F172A; border: 1px solid #CBD5E1; border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.84rem; font-weight: 600; cursor: pointer;">
              ${subSelectOptions}
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

    queueContainer.innerHTML = warningHtml + periodToolbarHtml + headerBarHtml + cardsHtml;
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

  function onMasterGroupChange(selectEl, fileId) {
    const mgId = selectEl.value;
    const mg = masterGroupOptions.find(g => g.id == mgId);
    const subSelect = $(`#subSelect_${fileId}`);
    if (!subSelect || !mg) return;

    subSelect.innerHTML = mg.sub_categories.map(s => `
      <option value="${s.id}">${escapeHtml(s.name)} (${escapeHtml(s.table_name || 'table')})</option>
    `).join('');

    if (mg.sub_categories.length) {
      onSubCategoryChange(subSelect, fileId);
    }
  }
  window.onMasterGroupChange = onMasterGroupChange;

  async function onSubCategoryChange(selectEl, fileId) {
    const subId = selectEl.value;
    try {
      const fd = new FormData();
      fd.append('action', 'update_classification');
      fd.append('file_id', fileId);
      fd.append('sub_category_id', subId);

      await fetch('api/upload_api.php', { method: 'POST', body: fd });
    } catch (e) {
      console.error('Failed to update file classification:', e);
    }
  }
  window.onSubCategoryChange = onSubCategoryChange;

  async function previewFile(fileId) {
    try {
      const formData = new FormData();
      formData.append('action', 'preview');
      formData.append('id', fileId);

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
              <select class="c360-mapping-select">
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
                    ? `<span class="c360-chip" style="background: rgba(14, 165, 233, 0.2); color: #00CFEF; border: 1px solid rgba(0, 207, 239, 0.35);">📅 TRANSACTION DATA (${escapeHtml(result.period_context?.period_name || 'Active Period')} [${escapeHtml(result.period_context?.period_code || '')}])</span>`
                    : `<span class="c360-chip" style="background: rgba(168, 85, 247, 0.2); color: #D8B4FE; border: 1px solid rgba(168, 85, 247, 0.35);">⚡ MASTER DATA (Persistent Upsert)</span>`
                  }
                  <span class="c360-chip c360-chip-gray">📊 ${result.file.columns_count} Columns</span>
                  <span class="c360-chip c360-chip-gray">📝 ${result.file.rows_count} Rows</span>
                </div>
              </div>
              <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
            </div>

            <!-- ENFORCED SCOPE BANNER -->
            <div class="c360-scope-banner">
              <div>
                <strong style="color: #0369A1;">Enforced Scope:</strong>
                Customer: <span class="c360-scope-tag">${escapeHtml(orgContext.customer_code || 'CUST001')}</span> &bull;
                Company: <span class="c360-scope-tag">${escapeHtml(orgContext.company_code || 'ABC02')}</span> &bull;
                Location: <span class="c360-scope-tag">${escapeHtml(orgContext.location_code || 'NASH02')}</span>
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
                <button class="c360-btn-approve" onclick="this.closest('.c360-modal-backdrop').remove()">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                  Approve Mapping
                </button>
              </div>
            </div>
          </div>
        </div>
      `;

      // Helper functions for dynamic searching and tab filters
      window.c360ActiveTabFilter = 'all';
      window.c360ActiveSearchQuery = '';

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

  async function viewImportErrors(uploadId) {
    try {
      const formData = new FormData();
      formData.append('action', 'errors');
      formData.append('upload_id', uploadId);

      const response = await fetch('api/upload_api.php', { method: 'POST', body: formData });
      const result = await response.json();

      if (!result.ok) return alert(result.message || 'Failed to load error trace.');

      const errors = result.errors || [];
      if (!errors.length) {
        return alert('No row-level errors recorded for this import run. All records were successfully processed.');
      }

      const rowsHtml = errors.map((err, idx) => `
        <tr style="border-bottom: 1px solid #F1F5F9; font-size: 0.85rem;">
          <td style="padding: 0.65rem 0.85rem; color: #94A3B8; font-weight: 600; text-align: center;">${idx + 1}</td>
          <td style="padding: 0.65rem 0.85rem; color: #0F172A; font-weight: 700;">${escapeHtml(err.sheet_name || 'Main Sheet')}</td>
          <td style="padding: 0.65rem 0.85rem; color: #475569; font-weight: 600;">Line ${err.row_number}</td>
          <td style="padding: 0.65rem 0.85rem;"><code style="background: #E2E8F0; padding: 0.15rem 0.45rem; border-radius: 4px; color: #0F172A; font-weight: 600;">${escapeHtml(err.field || 'N/A')}</code></td>
          <td style="padding: 0.65rem 0.85rem;"><span class="c360-badge" style="background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA;">${escapeHtml(err.error_type)}</span></td>
          <td style="padding: 0.65rem 0.85rem; color: #DC2626; font-family: monospace; font-size: 0.8rem; font-weight: 500;">${escapeHtml(err.error_message)}</td>
        </tr>
      `).join('');

      const modalHtml = `
        <div class="c360-modal-backdrop" onclick="this.remove()">
          <div class="c360-preview-dialog" onclick="event.stopPropagation()" style="max-width: 1100px;">
            <div class="c360-modal-head" style="background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%);">
              <div>
                <h3 class="c360-modal-title" style="color: #FFFFFF;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  Row-Level Import Error Traceability Log
                </h3>
                <div class="c360-modal-meta-row">
                  <span class="c360-chip" style="background: rgba(255,255,255,0.2); color: #FFF;">Showing ${errors.length} failed rows recorded in Neon PostgreSQL</span>
                </div>
              </div>
              <button class="c360-btn-close" onclick="this.closest('.c360-modal-backdrop').remove()">&times;</button>
            </div>

            <div class="c360-modal-body" style="padding: 1.25rem 1.75rem;">
              <div class="c360-table-card">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                  <thead>
                    <tr style="background: #F1F5F9; color: #475569; font-size: 0.78rem; text-transform: uppercase;">
                      <th style="padding: 0.65rem 0.85rem; width: 35px; text-align: center;">#</th>
                      <th style="padding: 0.65rem 0.85rem;">Worksheet</th>
                      <th style="padding: 0.65rem 0.85rem;">Line #</th>
                      <th style="padding: 0.65rem 0.85rem;">Field</th>
                      <th style="padding: 0.65rem 0.85rem;">Category</th>
                      <th style="padding: 0.65rem 0.85rem;">Error Trace</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${rowsHtml}
                  </tbody>
                </table>
              </div>
            </div>

            <div class="c360-modal-foot">
              <a href="api/upload_api.php?action=export_errors&upload_id=${uploadId}" class="c360-btn-approve" style="background: #DC2626; text-decoration: none;">
                &darr; Download CSV Error Report
              </a>
              <button class="c360-btn-ghost" onclick="this.closest('.c360-modal-backdrop').remove()">Close Error Log</button>
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

  async function executeImport() {
    if (!currentUploadRunId) return alert('No active upload run found to import.');

    const selectedCheckboxes = $$('.sheet-checkbox:checked');
    if (!selectedCheckboxes.length) {
      return alert('Please select at least one worksheet to import.');
    }

    const selectedIds = selectedCheckboxes.map(cb => cb.getAttribute('data-file-id'));

    const periodSelect = $('#c360SelectedPeriod');
    let selectedPeriodId = periodSelect ? periodSelect.value : (activePeriod ? activePeriod.period_id : null);
    if (periodSelect) {
      const selectedOpt = periodSelect.options[periodSelect.selectedIndex];
      if (selectedOpt && selectedOpt.getAttribute('data-locked') === '1') {
        const hasTxSelected = analyzedWorksheets.some(f => selectedIds.includes(String(f.id)) && (f.dataset_type === 'TRANSACTION' || ['material_transaction','downtime','finance_transaction','trial_balance'].includes(f.target_table)));
        if (hasTxSelected) {
          alert('Cannot import: The selected Accounting Period is LOCKED. Transaction records cannot be imported into a locked accounting period.');
          return;
        }
      }
    }

    if (!confirm(`Are you sure you want to import ${selectedIds.length} worksheet(s) into your dedicated Customer Database?\n\nOrganization scope and audit metadata will be populated automatically by the server.`)) {
      return;
    }

    importBtn.disabled = true;
    importBtn.innerHTML = '<span class="spinner"></span> Importing to Customer DB...';

    try {
      const formData = new FormData();
      formData.append('action', 'import');
      formData.append('upload_id', currentUploadRunId);
      if (selectedPeriodId) {
        formData.append('period_id', selectedPeriodId);
      }
      selectedIds.forEach(id => formData.append('file_ids[]', id));

      const response = await fetch('api/upload_api.php', { method: 'POST', body: formData });
      const result = await response.json();

      if (!result.ok) throw new Error(result.message || 'Import failed.');

      alert(`Import Complete!\n\nTarget Database: ${result.database_name}\n\n➕ ${result.imported || 0} new records inserted\n🔄 ${result.updated || 0} existing records updated\n✔ ${result.unchanged || 0} unchanged records verified\n⚠ ${result.skipped || 0} empty/instruction rows skipped\n✖ ${result.failed || 0} records failed validation`);
      
      if (result.failed > 0) {
        if (confirm('Some records encountered validation or constraint failures. Would you like to view the error trace now?')) {
          viewImportErrors(currentUploadRunId);
          return;
        }
      }
      
      window.location.href = 'dashboard.php?page=history';
    } catch (err) {
      alert('Import Failed: ' + err.message);
      importBtn.disabled = false;
      importBtn.innerHTML = 'Confirm & Import';
    }
  }

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
