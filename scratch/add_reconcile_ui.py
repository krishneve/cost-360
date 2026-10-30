import re

with open('dashboard.php', 'r', encoding='utf-8') as f:
    content = f.read()

btn_target = '<button type="button" id="qrMappingsBtn" class="btn-secondary" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff;" title="Manage Plant Cost Basis Mappings">'

extra_btns = '''<button type="button" id="qrReconcileBtn" class="btn-secondary" style="background: rgba(16, 185, 129, 0.2); border: 1px solid rgba(16, 185, 129, 0.4); color: #6EE7B7; font-weight: 700;" title="Run Authoritative 7-Level Reconciliation Audit against plant.xlsx">
                📊 Excel Reconciliation
              </button>
              <button type="button" id="qrSyncTbBtn" class="btn-secondary" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff;" title="Sync source records from Trial Balance">
                🔄 Sync TB
              </button>
              <button type="button" id="qrMappingsBtn" class="btn-secondary" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff;" title="Manage Plant Cost Basis Mappings">'''

if 'qrReconcileBtn' not in content:
    assert btn_target in content, "Button target not found in dashboard.php"
    content = content.replace(btn_target, extra_btns, 1)

# Now add modal
modal_target = '<!-- MODAL 3: COST BASIS MAPPINGS MANAGEMENT -->'
assert modal_target in content, "Modal target not found in dashboard.php"

reconcile_modal = '''<!-- MODAL 4: AUTHORITATIVE EXCEL RECONCILIATION AUDIT -->
        <div id="qrReconcileModal" class="cost360-modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(2, 30, 59, 0.65); backdrop-filter: blur(4px); z-index: 1200; justify-content: center; align-items: center; padding: 1.5rem;">
          <div class="cost360-modal" style="background: #fff; width: 100%; max-width: 1050px; max-height: 88vh; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 16px 40px rgba(0,0,0,0.3);">
            <div class="cost360-modal-header" style="background: #F0FDF4; padding: 1.15rem 1.5rem; border-bottom: 1px solid #BBF7D0; display: flex; justify-content: space-between; align-items: center;">
              <div>
                <h3 class="cost360-modal-title" style="margin: 0; font-size: 1.15rem; color: #166534; display: flex; align-items: center; gap: 0.5rem;">
                  <span>📊 Authoritative Excel Reconciliation Audit</span>
                  <span id="qrAuditStatusBadge" class="qr-badge-pill" style="background: #15803D; color: #fff; font-size: 0.75rem; padding: 2px 8px;">AUDITING</span>
                </h3>
                <div style="font-size: 0.82rem; color: #15803D; margin-top: 2px;">
                  Cell-by-cell 7-Level comparison between Cost360 generated matrix and authoritative <code>plant.xlsx</code>
                </div>
              </div>
              <button type="button" class="cost360-modal-close qr-modal-close-trigger" style="color: #166534;">&times;</button>
            </div>
            <div id="qrReconcileModalBody" class="cost360-modal-body" style="padding: 1.5rem; overflow-y: auto; flex: 1;">
              <!-- Dynamic Reconciliation Content -->
            </div>
            <div class="cost360-modal-footer" style="background: #F8FAFC; padding: 0.85rem 1.5rem; border-top: 1px solid var(--c360-border); display: flex; justify-content: space-between; align-items: center;">
              <span style="font-size: 0.78rem; color: var(--c360-text-muted);">Reference: <code>plant.xlsx (Sheet: Plant)</code></span>
              <button type="button" class="btn-secondary qr-modal-close-trigger">Close</button>
            </div>
          </div>
        </div>

        <!-- MODAL 3: COST BASIS MAPPINGS MANAGEMENT -->'''

if 'qrReconcileModal' not in content:
    content = content.replace(modal_target, reconcile_modal, 1)

with open('dashboard.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Successfully updated dashboard.php with Reconciliation UI!")

