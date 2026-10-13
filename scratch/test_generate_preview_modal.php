<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'preview';
$_POST['id'] = 1;

session_start();
$_SESSION['user_id'] = 3;
$_SESSION['username'] = 'aarav.cma@cost360.com';
$_SESSION['active_customer_id'] = 1;
$_SESSION['active_company_id'] = 2;
$_SESSION['active_company_code'] = 'ABC02';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload_centre.php';

$user = getAuthUser();
$result = getFilePreviewData(1, $user);

// Now let's emulate previewFile JS generation in PHP to see the rendered HTML
$headers = $result['headers'] ?? [];
$data = $result['data'] ?? [];
$targetCols = $result['target_columns'] ?? [];
$autoMapping = $result['auto_mapping'] ?? [];
$orgContext = $result['org_context'] ?? [];

$sysOrgFields = ['customer_code', 'company_code', 'division_code', 'location_code'];
$sysAuditFields = ['created_at', 'created_by', 'modified_at', 'modified_by'];

$mappingRowsHtml = '';
foreach ($headers as $idx => $header) {
    $cleanH = strtolower(preg_replace('/[^a-z0-9_]/i', '', $header));
    $mappedCol = $autoMapping[$header] ?? $cleanH;

    $isSysOrg = in_array($mappedCol, $sysOrgFields, true) || in_array($cleanH, $sysOrgFields, true);
    $isSysAudit = in_array($mappedCol, $sysAuditFields, true) || in_array($cleanH, $sysAuditFields, true);
    $isIgnored = ($mappedCol === '__IGNORE__');

    $badgeHtml = '';
    $rowClass = 'filter-matched';
    $noteHtml = '';

    if ($isSysOrg) {
        $rowClass = 'filter-system';
        $badgeHtml = '<span class="c360-badge c360-badge-cyan">System Controlled</span>';
    } elseif ($isSysAudit) {
        $rowClass = 'filter-audit';
        $badgeHtml = '<span class="c360-badge c360-badge-purple">Audit Managed</span>';
    } elseif ($isIgnored) {
        $rowClass = 'filter-skipped';
        $badgeHtml = '<span class="c360-badge c360-badge-gray">Skipped</span>';
    } else {
        $rowClass = 'filter-matched';
        $badgeHtml = '<span class="c360-badge c360-badge-green">Direct Match</span>';
    }

    $colOptions = '';
    foreach ($targetCols as $col) {
        $selected = ($col === $mappedCol) ? 'selected' : '';
        $colOptions .= "<option value=\"$col\" $selected>$col</option>";
    }

    $colIdx = $idx + 1;
    $mappingRowsHtml .= "
      <tr class=\"c360-mapping-row $rowClass\" data-col=\"$cleanH\" data-target=\"$mappedCol\">
        <td class=\"c360-col-idx\">$colIdx</td>
        <td class=\"c360-col-source\">
          <span class=\"c360-source-name\">$header</span>
          $noteHtml
        </td>
        <td class=\"c360-col-arrow\"><span class=\"c360-arrow-icon\">&rarr;</span></td>
        <td class=\"c360-col-target\">
          <select class=\"c360-mapping-select\">
            $colOptions
            <option value=\"__IGNORE__\">&mdash; Skip / Do Not Import &mdash;</option>
          </select>
        </td>
        <td class=\"c360-col-badge\">$badgeHtml</td>
      </tr>";
}

$sampleDataHtml = '';
if (!empty($data)) {
    $thHtml = '';
    foreach ($headers as $h) {
        $thHtml .= "<th>$h</th>";
    }
    $trHtml = '';
    foreach ($data as $idx => $row) {
        $rowIdx = $idx + 1;
        $tdHtml = '';
        foreach ($headers as $h) {
            $tdHtml .= "<td>" . htmlspecialchars($row[$h] ?? '') . "</td>";
        }
        $trHtml .= "<tr><td class=\"c360-sample-idx\">$rowIdx</td>$tdHtml</tr>";
    }
    $sampleDataHtml = "
      <div class=\"c360-sample-table-scroll\">
        <table class=\"c360-sample-table\">
          <thead><tr><th>#</th>$thHtml</tr></thead>
          <tbody>$trHtml</tbody>
        </table>
      </div>";
}

$fullHtml = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Preview Modal Test</title>
<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">
<style>
body { font-family: 'Inter', sans-serif; background: #033665; margin: 0; padding: 2rem; }
.c360-modal-backdrop {
  position: relative;
  background: transparent;
  display: flex; align-items: center; justify-content: center;
}
.c360-preview-dialog {
  background: #FFFFFF;
  border-radius: 16px;
  width: 100%; max-width: 1120px;
  display: flex; flex-direction: column;
  box-shadow: 0 25px 60px -15px rgba(3, 54, 101, 0.35);
  overflow: hidden;
  color: #1E293B;
}
.c360-modal-head {
  background: linear-gradient(135deg, #033665 0%, #063D70 100%);
  padding: 1.25rem 1.75rem;
  display: flex; justify-content: space-between; align-items: center;
  color: #FFFFFF;
}
.c360-modal-title { margin: 0; font-size: 1.15rem; font-weight: 700; color: #FFFFFF; }
.c360-modal-meta-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.45rem; font-size: 0.8rem; }
.c360-chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.76rem; font-weight: 600; }
.c360-chip-cyan { background: rgba(0, 207, 239, 0.15); color: #00CFEF; border: 1px solid rgba(0, 207, 239, 0.3); }
.c360-chip-green { background: rgba(52, 211, 153, 0.15); color: #34D399; border: 1px solid rgba(52, 211, 153, 0.3); }
.c360-chip-gray { background: rgba(255, 255, 255, 0.12); color: #E2E8F0; }
.c360-btn-close { background: rgba(255,255,255,0.1); border: none; width: 34px; height: 34px; border-radius: 50%; color: #FFFFFF; font-size: 1.25rem; cursor: pointer; }
.c360-scope-banner { background: #F0F9FF; border-bottom: 1px solid #BAE6FD; padding: 0.75rem 1.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; font-size: 0.84rem; color: #0369A1; }
.c360-scope-tag { background: #FFFFFF; border: 1px solid #BAE6FD; color: #0369A1; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.78rem; font-family: monospace; }
.c360-modal-body { padding: 1.25rem 1.75rem; background: #F8FAFC; }
.c360-table-card { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 10px; overflow: hidden; margin-bottom: 1.5rem; }
.c360-mapping-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
.c360-mapping-table th { background: #F1F5F9; color: #475569; font-weight: 700; font-size: 0.78rem; text-transform: uppercase; padding: 0.65rem 0.85rem; border-bottom: 1px solid #CBD5E1; }
.c360-mapping-row td { padding: 0.6rem 0.85rem; border-bottom: 1px solid #F1F5F9; }
.c360-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.74rem; font-weight: 700; }
.c360-badge-green { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
.c360-badge-cyan  { background: #E0F2FE; color: #0284C7; border: 1px solid #BAE6FD; }
.c360-badge-purple{ background: #F3E8FF; color: #7E22CE; border: 1px solid #E9D5FF; }
.c360-badge-gray  { background: #F1F5F9; color: #64748B; border: 1px solid #CBD5E1; }
.c360-mapping-select { width: 100%; padding: 0.45rem 0.75rem; background: #FFFFFF; border: 1.5px solid #CBD5E1; border-radius: 6px; color: #0F172A; font-size: 0.85rem; font-weight: 600; }
.c360-sample-table-scroll { overflow-x: auto; max-height: 280px; }
.c360-sample-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.c360-sample-table th { background: #033665; color: #FFFFFF; padding: 0.55rem 0.75rem; text-align: left; font-weight: 600; }
.c360-sample-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #E2E8F0; color: #1E293B; }
.c360-modal-foot { background: #FFFFFF; border-top: 1px solid #E2E8F0; padding: 1.1rem 1.75rem; display: flex; justify-content: space-between; align-items: center; }
.c360-btn-ghost { background: transparent; border: 1.5px solid #CBD5E1; color: #475569; font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.25rem; border-radius: 8px; cursor: pointer; }
.c360-btn-approve { background: #10B981; border: none; color: #FFFFFF; font-weight: 700; font-size: 0.86rem; padding: 0.6rem 1.4rem; border-radius: 8px; cursor: pointer; }
</style>
</head>
<body>
<div class=\"c360-modal-backdrop\">
  <div class=\"c360-preview-dialog\">
    <div class=\"c360-modal-head\">
      <div>
        <h3 class=\"c360-modal-title\">Data Contract Mapping Preview: {$result['file']['original_name']}</h3>
        <div class=\"c360-modal-meta-row\">
          <span class=\"c360-chip c360-chip-cyan\">Target Table: <strong>{$result['target_table']}</strong></span>
          <span class=\"c360-chip c360-chip-green\">Database: <strong>{$result['database_name']}</strong></span>
          <span class=\"c360-chip\" style=\"background: rgba(168, 85, 247, 0.2); color: #D8B4FE;\">⚡ MASTER DATA</span>
          <span class=\"c360-chip c360-chip-gray\">📊 {$result['file']['columns_count']} Columns</span>
          <span class=\"c360-chip c360-chip-gray\">📝 {$result['file']['rows_count']} Rows</span>
        </div>
      </div>
      <button class=\"c360-btn-close\">&times;</button>
    </div>
    <div class=\"c360-scope-banner\">
      <div>
        <strong>Enforced Scope:</strong> Customer: <span class=\"c360-scope-tag\">CUST001</span> &bull; Company: <span class=\"c360-scope-tag\">ABC02</span>
      </div>
    </div>
    <div class=\"c360-modal-body\">
      <h4 style=\"margin: 0 0 0.85rem; color: #0F172A;\">Source Sheet Column &rarr; Customer Database Field Mapping</h4>
      <div class=\"c360-table-card\">
        <table class=\"c360-mapping-table\">
          <thead><tr><th style=\"width:35px;\">#</th><th>Source Sheet Column</th><th style=\"width:30px;\"></th><th>Target Customer DB Field</th><th style=\"text-align:right;\">Contract Status</th></tr></thead>
          <tbody>$mappingRowsHtml</tbody>
        </table>
      </div>
      <h4 style=\"margin: 1rem 0 0.85rem; color: #0F172A;\">Worksheet Sample Records (First 25 Rows)</h4>
      <div class=\"c360-table-card\">$sampleDataHtml</div>
    </div>
    <div class=\"c360-modal-foot\">
      <div><strong>" . count($headers) . " of " . count($headers) . "</strong> columns validated & ready to import</div>
      <div>
        <button class=\"c360-btn-ghost\">Close Preview</button>
        <button class=\"c360-btn-approve\">Approve &amp; Save Mapping</button>
      </div>
    </div>
  </div>
</div>
</body></html>";

file_put_contents(__DIR__ . '/rendered_preview_modal.html', $fullHtml);
echo "Written rendered_preview_modal.html (length: " . strlen($fullHtml) . ")\n";
