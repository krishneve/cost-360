import openpyxl

wb_formula = openpyxl.load_workbook('plant.xlsx', data_only=False)
wb_values = openpyxl.load_workbook('plant.xlsx', data_only=True)

sheet2_f = wb_formula['Cost Sheet Qty reco']
sheet2_v = wb_values['Cost Sheet Qty reco']

print(f"Sheet 2 dimensions: {sheet2_f.max_row} rows, {sheet2_f.max_column} cols")

# Check for salary, pf, dept, professional in sheet 2
matches = []
for r in range(1, min(100, sheet2_f.max_row + 1)):
    for c in range(1, min(20, sheet2_f.max_column + 1)):
        val = str(sheet2_v.cell(r, c).value or '')
        val_upper = val.upper()
        if any(k in val_upper for k in ['SALARY', 'PF', 'PROFESSIONAL', 'DEPT', 'HEADCOUNT', 'RATIO', 'EMPLOYER']):
            matches.append((r, c, val))

print(f"Matches in Sheet 2: {len(matches)}")
for m in matches:
    print(f"  Row {m[0]}, Col {m[1]}: {m[2]}")

# Also check for external workbook links in the whole workbook
print("\n=== EXTERNAL LINKS IN WORKBOOK ===")
for link in getattr(wb_formula, '_external_links', []):
    print("External link:", link.file_link.Target if hasattr(link, 'file_link') else link)

