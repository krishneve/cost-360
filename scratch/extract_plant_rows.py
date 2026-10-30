import openpyxl, json, sys

excel_path = sys.argv[1] if len(sys.argv) > 1 else 'plant.xlsx'
wb = openpyxl.load_workbook(excel_path, data_only=True)
sheet = wb['Plant']

headers = [sheet.cell(row=4, column=c).value for c in range(3, 34)]
cc_headers = [str(h).strip() for h in headers[6:29]]

rows = []
for r in range(5, 178):
    aname = sheet.cell(row=r, column=3).value
    acode = sheet.cell(row=r, column=4).value
    pnl = sheet.cell(row=r, column=5).value
    closing = sheet.cell(row=r, column=6).value
    basis = sheet.cell(row=r, column=7).value
    nature = sheet.cell(row=r, column=8).value
    total = sheet.cell(row=r, column=32).value
    check = sheet.cell(row=r, column=33).value
    
    if aname is not None and str(aname).strip() != '' and str(aname).strip() != '0':
        cc_vals = {}
        for i, cc in enumerate(cc_headers):
            val = sheet.cell(row=r, column=9 + i).value
            cc_vals[cc] = float(val or 0.0)
            
        rows.append({
            'row_idx': r,
            'row_ref': 'ROW-' + str(r),
            'aname': str(aname).strip(),
            'acode': str(acode or '').strip(),
            'pnl': str(pnl or '').strip(),
            'closing': float(closing or 0.0),
            'basis': str(basis or '').strip(),
            'nature': str(nature or '').strip(),
            'cc_vals': cc_vals,
            'total': float(total or 0.0),
            'check': float(check or 0.0)
        })

print(json.dumps(rows))
