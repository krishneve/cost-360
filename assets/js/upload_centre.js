/**
 * Cost360 - Upload Centre Interactive Frontend Controller
 * Features full dynamic dropdown selectors for Master Groups, Sub Categories,
 * Target Entities, and Source-to-Target Database Column Field Mappings.
 */

(function () {
  'use strict';

  const $ = selector => document.querySelector(selector);
  const $$ = selector => [...document.querySelectorAll(selector)];

  let selectedFiles = [];
  let currentUploadRunId = null;
  let masterGroupOptions = [];

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

    // Load master group dropdown options from API
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
    if (importBtn) importBtn.style.display = 'none';
    renderQueue();
  }

  function renderQueue() {
    if (!queueContainer) return;

    if (!selectedFiles.length) {
      queueContainer.classList.add('hidden');
      queueContainer.innerHTML = '';
      if (analyzeBtn) analyzeBtn.disabled = true;
      return;
    }

    queueContainer.classList.remove('hidden');
    if (analyzeBtn) analyzeBtn.disabled = false;

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
    if (!selectedFiles.length) return alert('Please select at least one file to upload.');

    const formData = new FormData();
    formData.append('action', 'upload');
    selectedFiles.forEach(file => formData.append('files[]', file));

    analyzeBtn.disabled = true;
    analyzeBtn.innerHTML = '<span class="spinner"></span> Analyzing File Structure...';

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
      renderAnalysisResults(result.files);

      analyzeBtn.innerHTML = '✓ Analysis Complete';
      if (importBtn) {
        importBtn.style.display = 'inline-flex';
        importBtn.disabled = false;
      }
    } catch (err) {
      alert('Upload Analysis Error: ' + err.message);
      analyzeBtn.disabled = false;
      analyzeBtn.innerHTML = 'Analyze Files';
    }
  }

  function renderAnalysisResults(files) {
    if (!queueContainer) return;

    queueContainer.innerHTML = files.map(file => {
      const confBadgeClass = file.confidence >= 90 ? 'conf-high' : (file.confidence >= 70 ? 'conf-med' : 'conf-low');
      const statusBadgeClass = file.status.toLowerCase().replace(/\s+/g, '-');

      // Master Group Dropdown options
      const mgSelectOptions = masterGroupOptions.map(g => {
        const selected = (g.name === file.master || g.id == file.master_group_id) ? 'selected' : '';
        return `<option value="${g.id}" ${selected}>${escapeHtml(g.name)}</option>`;
      }).join('');

      // Find initial subcategories for selected master group
      const selectedMg = masterGroupOptions.find(g => g.name === file.master || g.id == file.master_group_id) || masterGroupOptions[0];
      const subs = selectedMg ? selectedMg.sub_categories : [];

      const subSelectOptions = subs.map(s => {
        const selected = (s.name === file.sub || s.id == file.sub_category_id) ? 'selected' : '';
        return `<option value="${s.id}" ${selected}>${escapeHtml(s.name)} (${escapeHtml(s.table_name || 'table')})</option>`;
      }).join('');

      return `
        <div class="analysis-result-card">
          <div class="file-ext-badge">${escapeHtml(file.type)}</div>
          <div class="file-info-col">
            <strong class="file-title">${escapeHtml(file.name)}</strong>
            <small class="file-meta">${file.rows.toLocaleString()} rows &bull; ${file.columns} columns &bull; ${formatBytes(file.size)}</small>
          </div>

          <!-- Master Group Dropdown -->
          <div class="dropdown-control-group">
            <label class="dropdown-label">Master Group</label>
            <select class="cost360-select" onchange="onMasterGroupChange(this, ${file.id})">
              ${mgSelectOptions}
            </select>
          </div>

          <!-- Sub Category / Target Table Dropdown -->
          <div class="dropdown-control-group">
            <label class="dropdown-label">Target Master Entity</label>
            <select class="cost360-select" id="subSelect_${file.id}" onchange="onSubCategoryChange(this, ${file.id})">
              ${subSelectOptions}
            </select>
          </div>

          <div class="confidence-col">
            <span class="confidence-badge ${confBadgeClass}">${file.confidence}% Confidence</span>
          </div>

          <div class="action-col">
            <button type="button" class="btn-action-sm" onclick="previewFile(${file.id})">Preview & Map Fields</button>
          </div>
        </div>
      `;
    }).join('');
  }

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
      const targetCols = result.target_columns || ['item_code', 'item_description', 'uom', 'rate', 'category', 'created_by'];

      // Header Mapping Row Builder
      const mappingRowsHtml = headers.map(header => {
        const cleanH = header.replace(/[^a-z0-9_]/gi, '').toLowerCase();
        
        const colOptions = targetCols.map(col => {
          const cleanCol = col.replace(/[^a-z0-9_]/gi, '').toLowerCase();
          const selected = (cleanH === cleanCol || cleanH.includes(cleanCol) || cleanCol.includes(cleanH)) ? 'selected' : '';
          return `<option value="${col}" ${selected}>${escapeHtml(col)}</option>`;
        }).join('');

        return `
          <div class="mapping-grid-row">
            <div class="source-header-name">
              <strong>${escapeHtml(header)}</strong>
            </div>
            <div class="mapping-arrow">&rarr;</div>
            <div class="target-field-select-wrap">
              <select class="cost360-select-sm">
                ${colOptions}
                <option value="__IGNORE__">&mdash; Skip / Do Not Import &mdash;</option>
              </select>
            </div>
            <div class="mapping-match-status">
              <span class="confidence-badge conf-high">Match Found</span>
            </div>
          </div>
        `;
      }).join('');

      const modalHtml = `
        <div class="cost360-modal-overlay" onclick="this.remove()">
          <div class="cost360-modal-dialog" onclick="event.stopPropagation()">
            <div class="modal-header">
              <div>
                <h3>Data Preview & Column Field Mapping: ${escapeHtml(result.file.original_name)}</h3>
                <small>Target Table: <code>${escapeHtml(result.target_table)}</code> &bull; ${result.file.rows_count} total rows &bull; ${result.file.columns_count} file headers</small>
              </div>
              <button class="modal-close-btn" onclick="this.closest('.cost360-modal-overlay').remove()">&times;</button>
            </div>

            <div class="modal-body-tablewrap">
              <h4 style="margin-bottom: 0.75rem; font-size: 0.95rem; color: var(--color-primary-text);">Source Column &rarr; Neon Database Field Dropdown Mapping</h4>
              <div class="mapping-grid-container">
                ${mappingRowsHtml}
              </div>

              <h4 style="margin-top: 1.5rem; margin-bottom: 0.75rem; font-size: 0.95rem; color: var(--color-primary-text);">First 25 Sample Rows Preview</h4>
              <table class="preview-data-table">
                <thead>
                  <tr>${headers.map(h => `<th>${escapeHtml(h)}</th>`).join('')}</tr>
                </thead>
                <tbody>
                  ${data.map(row => `
                    <tr>${headers.map(h => `<td>${escapeHtml(row[h] || '')}</td>`).join('')}</tr>
                  `).join('')}
                </tbody>
              </table>
            </div>

            <div class="modal-footer">
              <button class="btn-secondary" onclick="this.closest('.cost360-modal-overlay').remove()">Cancel & Close</button>
              <button class="btn-primary" style="background: #10B981;" onclick="this.closest('.cost360-modal-overlay').remove()">Save Mapping & Close</button>
            </div>
          </div>
        </div>
      `;

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
        return alert('No row-level errors logged for this import run.');
      }

      const rowsHtml = errors.map(err => `
        <tr>
          <td><strong>Line ${err.row_number}</strong></td>
          <td><code>${escapeHtml(err.field || 'N/A')}</code></td>
          <td><span class="confidence-badge conf-low">${escapeHtml(err.error_type)}</span></td>
          <td style="color: #DC2626; font-family: monospace; font-size: 0.8rem;">${escapeHtml(err.error_message)}</td>
        </tr>
      `).join('');

      const modalHtml = `
        <div class="cost360-modal-overlay" onclick="this.remove()">
          <div class="cost360-modal-dialog" onclick="event.stopPropagation()">
            <div class="modal-header">
              <div>
                <h3 style="color: #DC2626;">Row-Level Import Error Traceability Log</h3>
                <small>Showing ${errors.length} failed rows recorded in Neon PostgreSQL <code>public.import_errors</code></small>
              </div>
              <button class="modal-close-btn" onclick="this.closest('.cost360-modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body-tablewrap">
              <table class="preview-data-table">
                <thead>
                  <tr>
                    <th>CSV Line #</th>
                    <th>Failed Column</th>
                    <th>Error Category</th>
                    <th>Exact Neon Database Error Trace</th>
                  </tr>
                </thead>
                <tbody>
                  ${rowsHtml}
                </tbody>
              </table>
            </div>
            <div class="modal-footer">
              <button class="btn-secondary" onclick="this.closest('.cost360-modal-overlay').remove()">Close Error Log</button>
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

    if (!confirm('Are you sure you want to import this validated dataset into your Neon PostgreSQL database?')) {
      return;
    }

    importBtn.disabled = true;
    importBtn.innerHTML = '<span class="spinner"></span> Importing to Neon DB...';

    try {
      const formData = new FormData();
      formData.append('action', 'import');
      formData.append('upload_id', currentUploadRunId);

      const response = await fetch('api/upload_api.php', { method: 'POST', body: formData });
      const result = await response.json();

      if (!result.ok) throw new Error(result.message || 'Import failed.');

      alert(`Import Complete!\n\n✔ ${result.imported} records successfully imported into Neon PostgreSQL.\n⚠ ${result.skipped} records skipped.\n✖ ${result.failed} records failed.`);
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
