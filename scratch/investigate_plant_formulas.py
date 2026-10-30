import openpyxl
import json
import sys

wb_formula = openpyxl.load_workbook('plant.xlsx', data_only=False)
wb_values = openpyxl.load_workbook('plant.xlsx', data_only=True)

sheet_f = wb_formula['Plant']
sheet_v = wb_values['Plant']

target_codes = ['5000493', '5000413', '6300213']
target_names = ['EMPLOYER PF DEDUCTION', 'SALARY A/C', 'PROFESSIONAL CHARGES']

print(f"=== ALL SHEETS IN WORKBOOK plant.xlsx ===")
for sheetname in wb_formula.sheetnames:
    ws = wb_formula[sheetname]
    print(f"Sheet: {sheetname} (State: {ws.sheet_state}, Rows: {ws.max_row}, Cols: {ws.max_column})")

print(f"\n=== INSPECTING ROW 4 HEADERS ===")
headers = {}
for c in range(1, 35):
    h = sheet_f.cell(4, c).value
    if h:
        headers[c] = str(h).strip()
        print(f"Col {c} ({openpyxl.utils.get_column_letter(c)}): {h}")

print(f"\n=== INSPECTING TARGET ACCOUNTS ===")
for r in range(5, sheet_f.max_row + 1):
    aname = str(sheet_f.cell(r, 3).value or '').strip()
    acode = str(sheet_f.cell(r, 4).value or '').strip()
    
    if acode in target_codes or any(t in aname.upper() for t in target_names):
        closing_f = sheet_f.cell(r, 6).value
        closing_v = sheet_v.cell(r, 6).value
        basis_f = sheet_f.cell(r, 7).value
        nature_f = sheet_f.cell(r, 8).value
        total_f = sheet_f.cell(r, 32).value
        total_v = sheet_v.cell(r, 32).value
        check_f = sheet_f.cell(r, 33).value
        check_v = sheet_v.cell(r, 33).value

        print(f"\n" + "="*80)
        print(f"ROW {r}: Account {acode} - {aname}")
        print(f"  Costing Basis: {basis_f} | Nature: {nature_f}")
        print(f"  CLOSING Formula: {repr(closing_f)} | Value: {closing_v}")
        print(f"  Total Formula:   {repr(total_f)} | Value: {total_v}")
        print(f"  Check Formula:   {repr(check_f)} | Value: {check_v}")
        print(f"  Cost Centre Allocations:")

        for c in range(9, 32):
            cc_name = headers.get(c, f"Col_{c}")
            col_letter = openpyxl.utils.get_column_letter(c)
            cell_f = sheet_f.cell(r, c).value
            cell_v = sheet_v.cell(r, c).value
            
            if cell_f is not None or (cell_v is not None and cell_v != 0):
                print(f"    Col {col_letter} ({cc_name}):")
                print(f"      Formula: {repr(cell_f)}")
                print(f"      Value:   {cell_v}")

