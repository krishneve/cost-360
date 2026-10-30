import openpyxl

wb_v = openpyxl.load_workbook('plant.xlsx', data_only=True)
sheet_v = wb_v['Plant']

# Row 62: EMPLOYER PF DEDUCTION
# Row 69: SALARY A/C
# Row 122: PROFESSIONAL CHARGES

headers = {}
for c in range(1, 35):
    h = sheet_v.cell(4, c).value
    if h: headers[c] = str(h).strip()

def analyze_row(r, name):
    closing = sheet_v.cell(r, 6).value
    print(f"\n=======================================================")
    print(f"ANALYSIS OF ROW {r}: {name}")
    print(f"CLOSING: {closing:,.4f}")
    
    allocs = {}
    total_alloc = 0.0
    for c in range(9, 32):
        val = sheet_v.cell(r, c).value
        if val and abs(val) > 1e-6:
            cc = headers[c]
            allocs[cc] = val
            total_alloc += val
            
    print(f"Total Allocated: {total_alloc:,.4f} | Difference: {(closing - total_alloc):,.4f}")
    print("Breakdown:")
    for cc, val in allocs.items():
        pct = (val / closing) * 100
        ratio = val / closing
        print(f"  * {cc:25s}: {val:18,.4f} | {pct:12.6f}% | Ratio: {ratio:.10f}")

analyze_row(69, "SALARY A/C (Row 69)")
analyze_row(62, "EMPLOYER PF DEDUCTION (Row 62)")
analyze_row(122, "PROFESSIONAL CHARGES (Row 122)")
analyze_row(61, "DIRECTORS REMUNARATION (Row 61)")

