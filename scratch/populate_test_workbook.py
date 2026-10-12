import zipfile
import xml.etree.ElementTree as ET
import os
import shutil

source_path = 'Document/EXCEL For Upload center (1).xlsx'
target_path = 'scratch/test_upload_populated.xlsx'

# Sample data rows for each of the 11 sheets:
# Note: First 4 cols in excel are customer_code, company_code, division_code, location_code.
# We put spoofed dummy values like 'SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC' to prove the server overrides them!
# Last 4 cols are created_at, created_by, modified_at, modified_by. We put 'SPOOF_TIME', '999999', 'SPOOF_TIME', '999999' to prove server overrides them!

sheet_data = {
    '01.ITEM MASTER PRODUCT MASTER': [
        # customer_code, company_code, division_code, location_code, item_type, item_group_code, item_group_name, item_code, item_name, unit_of_measure, std_rate, material_rate, labour_rate, overheads, actual_rate, total, grade, net_weight_kg, gross_weight_kg, upload, raw_material, product, std_yield_pct, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'Raw Material', 'GRP01', 'Steel Products', 'ITEM-TEST-001', 'Test Steel Plate 5mm', 'KG', '120.50', '90.00', '15.00', '15.50', '120.50', '120.50', 'Grade A', '25.5', '26.0', 'Y', 'STEEL', 'PLATE', '98.5', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999'],
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'Finished Good', 'GRP02', 'Assemblies', 'ITEM-TEST-002', 'Test Assembled Motor', 'PCS', '4500.00', '3200.00', '800.00', '500.00', '4500.00', '4500.00', 'Grade B', '15.0', '16.2', 'Y', 'VARIOUS', 'MOTOR', '99.0', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '02.WORK CENTER MASTER': [
        # customer_code, company_code, division_code, location_code, wc_code, wc_name, wc_group_code, wc_group_name, cc_code, cc_name, std_mhr, rated_hrs_day, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'WC-TEST-01', 'CNC Milling Center 1', 'WCG01', 'Milling', 'CC-TEST-01', 'Machine Shop', '450.00', '16.00', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '03.PARTY MASTER': [
        # customer_code, company_code, division_code, location_code, type, party_code, party_name, party_pan, party_account, state, payment_terms_days, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'Supplier', 'PARTY-TEST-01', 'Tata Steel Ltd', 'AAACT1234F', 'ACC-98765', 'Maharashtra', '45', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '04.COST_CENTER_MASTER': [
        # customer_code, company_code, division_code, location_code, cc_code, cc_name, cc_type, is_production, shift_capacity_hrs_per_month, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'CC-TEST-01', 'Machine Shop CC', 'Production', '1', '400.00', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '05. STANDARD_COST': [
        # customer_code, company_code, division_code, location_code, product_code, std_material, std_conversion, std_total_cost, std_revision_date, processed_material, processed_conversion, processed_total_cost, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'ITEM-TEST-002', '3200.00', '1300.00', '4500.00', '2026-07-01', '3200.00', '1300.00', '4500.00', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '05.A_BOM': [
        # customer_code, company_code, division_code, location_code, parent_item_code, item_code, parent_item_qty, child_item_qty, uom, material_rate, labour_rate, std_rate, processed_material_rate, processed_labour_rate, processed_std_rate, scrap_item_code, scrap_qty, scrap_unit, byproduct_item_code, byproduct_qty, byproduct_unit, wc_code, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'ITEM-TEST-002', 'ITEM-TEST-001', '1.0', '2.5', 'KG', '120.50', '25.00', '145.50', '120.50', '25.00', '145.50', 'SCRAP-01', '0.1', 'KG', '', '0', '', 'WC-TEST-01', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '05.B_ROUTING': [
        # customer_code, company_code, division_code, location_code, product_code, cc_code, machine_code, std_machine_hrs, std_labour_hrs, std_machine_hr_rate, std_labour_rate, std_conversion_cost, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'ITEM-TEST-002', 'CC-TEST-01', 'WC-TEST-01', '1.5', '2.0', '450.00', '250.00', '1175.00', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '06.MATERIAL TRANSACTION': [
        # customer_code, company_code, division_code, location_code, doc_type, doc_no, doc_date, party_code, party_name, item_code, item_name, uom, qty, rejected_qty, gross_weight, net_weight, weight_unit, actual_yield, std_yield, rate, amount, std_rate, actual_rate, std_realisation_rate, approval_ref, remarks, cc_code, product_code, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'GRN', 'GRN-2026-001', '2026-07-10', 'PARTY-TEST-01', 'Tata Steel Ltd', 'ITEM-TEST-001', 'Test Steel Plate 5mm', 'KG', '500.0', '5.0', '510.0', '500.0', 'KG', '99.0', '98.5', '120.50', '60250.00', '120.50', '120.50', '120.50', 'APR-001', 'July Batch Receipt', 'CC-TEST-01', '', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '07.DOWNTIME': [
        # customer_code, company_code, division_code, location_code, log_date, machine_code, machine_name, cc_code, rated_hrs, run_hrs, breakdown_hrs, idle_hrs, power_units_kwh, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', '2026-07-12', 'WC-TEST-01', 'CNC Milling Center 1', 'CC-TEST-01', '16.0', '14.5', '1.0', '0.5', '120.0', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '08.FINANCE TRANSACTION': [
        # customer_code, company_code, division_code, location_code, no, date, oh_code, oh_name, oh_behaviour, cc_code, cc_name, amount, remarks, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'VOUCH-001', '2026-07-15', 'OH-ELEC', 'Factory Electricity', 'Variable', 'CC-TEST-01', 'Machine Shop', '35000.00', 'July Power Bill allocation', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ],
    '09.TRIAL BALANCE': [
        # customer_code, company_code, division_code, location_code, acc_code, description, opening_balance, opening_balance_sign_d_c, opening_debit_opening_credit, transacted_debit, transacted_credit, closing_balance, closing_balance_sign_d_c, closing_debit_closing_credit, created_at, created_by, modified_at, modified_by
        ['SPOOF_CUST', 'SPOOF_COMP', 'SPOOF_DIV', 'SPOOF_LOC', 'GL-50100', 'Raw Material Consumption Inventory', '150000.00', 'D', '150000.00', '60250.00', '25000.00', '185250.00', 'D', '185250.00', 'SPOOF_TIME', '9999', 'SPOOF_TIME', '9999']
    ]
}

def col_letter(col_idx):
    res = ''
    while col_idx >= 0:
        res = chr(col_idx % 26 + 65) + res
        col_idx = col_idx // 26 - 1
    return res

with zipfile.ZipFile(source_path, 'r') as zin, zipfile.ZipFile(target_path, 'w', compression=zipfile.ZIP_DEFLATED) as zout:
    # Read sheet relationships
    wb_xml = ET.fromstring(zin.read('xl/workbook.xml'))
    sheets = wb_xml.findall('{http://schemas.openxmlformats.org/spreadsheetml/2006/main}sheets/{http://schemas.openxmlformats.org/spreadsheetml/2006/main}sheet')
    rels_xml = ET.fromstring(zin.read('xl/_rels/workbook.xml.rels'))
    rel_map = {r.attrib['Id']: r.attrib['Target'] for r in rels_xml}

    sheet_file_map = {}
    for s in sheets:
        s_name = s.attrib['name']
        rId = s.attrib['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id']
        t = rel_map[rId]
        target = 'xl/' + t if not t.startswith('xl/') else t
        sheet_file_map[s_name] = target

    for item in zin.infolist():
        data = zin.read(item.filename)
        # Check if item is a sheet we want to populate
        matched_sheet_name = None
        for s_name, s_target in sheet_file_map.items():
            if item.filename == s_target:
                matched_sheet_name = s_name
                break

        if matched_sheet_name and matched_sheet_name in sheet_data:
            s_xml = ET.fromstring(data)
            sheetData = s_xml.find('{http://schemas.openxmlformats.org/spreadsheetml/2006/main}sheetData')
            # keep row 1 (header)
            rows = sheetData.findall('{http://schemas.openxmlformats.org/spreadsheetml/2006/main}row')
            # remove any extra empty rows
            for r in rows[1:]:
                sheetData.remove(r)

            # Add data rows using inlineStr
            for r_idx, row_values in enumerate(sheet_data[matched_sheet_name], start=2):
                new_row = ET.SubElement(sheetData, '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}row', {'r': str(r_idx)})
                for c_idx, val in enumerate(row_values):
                    cell_ref = f"{col_letter(c_idx)}{r_idx}"
                    cell = ET.SubElement(new_row, '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}c', {'r': cell_ref, 't': 'inlineStr'})
                    is_el = ET.SubElement(cell, '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}is')
                    t_el = ET.SubElement(is_el, '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}t')
                    t_el.text = str(val)

            data = ET.tostring(s_xml, xml_declaration=True, encoding='utf-8')

        zout.writestr(item, data)

print(f"Created populated test workbook at: {target_path}")
