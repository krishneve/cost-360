import openpyxl

wb_formula = openpyxl.load_workbook('plant.xlsx', data_only=False)
wb_values = openpyxl.load_workbook('plant.xlsx', data_only=True)

# 1. Search for all rows in Plant where Costing Basis is NOT a direct 1-to-1 cost centre
sheet_f = wb_formula['Plant']
sheet_v = wb_values['Plant']

headers = {}
for c in range(1, 35):
    h = sheet_f.cell(4, c).value
    if h:
        headers[c] = str(h).strip()

cc_names = [headers[c] for c in range(9, 32)]

print("=== ALL ROWS WITH NON-DIRECT COSTING BASIS IN Plant SHEET ===")
for r in range(5, sheet_f.max_row + 1):
    aname = str(sheet_f.cell(r, 3).value or '').strip()
    acode = str(sheet_f.cell(r, 4).value or '').strip()
    basis = str(sheet_f.cell(r, 7).value or '').strip()
    closing = sheet_v.cell(r, 6).value
    
    if aname and aname != '0' and aname.upper() != 'TOTAL':
        if basis not in cc_names:
            print(f"Row {r}: Code={acode}, Name='{aname}', Basis='{basis}', Closing={closing}")
            # Check which columns have non-zero or formulas
            allocs = []
            for c in range(9, 32):
                cc_name = headers.get(c, f"Col_{c}")
                val = sheet_v.cell(r, c).value
                form = sheet_f.cell(r, c).value
                if val or (form and str(form) != '=IF($G{r}={col}$4,$F{r},0)'.format(r=r, col=openpyxl.utils.get_column_letter(c))):
                    allocs.append((cc_name, form, val))
            if allocs:
                print(f"   Allocations:")
                for cc, f, v in allocs:
                    print(f"     {cc}: Formula={repr(f)}, Value={v}")

