const fs = require('fs');

// Load API response
const apiOutput = JSON.parse(fs.readFileSync('scratch/api_preview_response.json', 'utf8'));

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

const result = apiOutput;
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

try {
  const mappingRowsHtml = headers.map((header, idx) => {
    const cleanH = header.replace(/[^a-z0-9_]/gi, '').toLowerCase();
    const mappedCol = autoMapping[header] || cleanH;
    return `<tr><td>${idx}</td><td>${cleanH}</td></tr>`;
  }).join('');

  console.log('Mapping rows generated successfully. Length:', mappingRowsHtml.length);

  // Test full modalHtml interpolation
  const modalHtml = `
    <div id="cost360-preview-modal-root" class="c360-modal-backdrop" onclick="this.remove()">
      <div class="c360-preview-dialog" onclick="event.stopPropagation()">
        <h3>${escapeHtml(result.file.original_name)}</h3>
        <span>Target Table: ${escapeHtml(result.target_table)}</span>
        <span>Database: ${escapeHtml(result.database_name)}</span>
        ${result.dataset_type === 'TRANSACTION'
          ? `<span>PERIOD DATA (${escapeHtml(result.active_period ? (result.active_period.period_name || ('Period ' + result.active_period.period_id)) : 'Active Period')})</span>`
          : `<span>MASTER DATA</span>`
        }
        <span>Columns: ${result.file.columns_count}</span>
        <span>Rows: ${result.file.rows_count}</span>
        <span>Customer: ${escapeHtml(orgContext.customer_code || 'CUST001')}</span>
        <span>Company: ${escapeHtml(orgContext.company_code || 'ABC02')}</span>
        <span>New: ${(result.preview_summary?.new_records || 0).toLocaleString()}</span>
        <span>Existing: ${(result.preview_summary?.update_records || 0).toLocaleString()}</span>
        <span>Unchanged: ${(result.preview_summary?.unchanged_records || 0).toLocaleString()}</span>
        ${(result.date_violations && result.date_violations.length > 0) ? 'violations' : ''}
        ${(result.period_error) ? 'error' : ''}
      </div>
    </div>
  `;
  console.log('Modal HTML generated successfully. Length:', modalHtml.length);
} catch (e) {
  console.error('ERROR during generation:', e);
}
