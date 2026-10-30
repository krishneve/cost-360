import zipfile
import xml.etree.ElementTree as ET

path = 'Document/EXCEL For Upload center (1).xlsx'
with zipfile.ZipFile(path, 'r') as z:
    wb_xml = z.read('xl/workbook.xml')
    root = ET.fromstring(wb_xml)
    print("Sheets in workbook:")
    for sheet in root.findall('.//{http://schemas.openxmlformats.org/spreadsheetml/2006/main}sheet'):
        print(sheet.attrib)
