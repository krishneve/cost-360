with open('config/auth.php', 'r', encoding='utf-8') as f:
    content = f.read()

target = """        try {
            $pdo = getDbConnection();
            $hasDiv = ($divisionId !== null && $divisionId !== '' && (string)$divisionId !== 'ALL');

            if (isPlatformAdmin($userId)) {
                $sql = '
                    SELECT p.plant_id, p.division_id, p.plant_code, p.plant_name, p.location, p.status
                    FROM plants p
                    INNER JOIN divisions d ON d.division_id = p.division_id
                    WHERE d.company_id = :company_id AND UPPER(p.status) = \\'ACTIVE\\'
                ';
                $params = [':company_id' => $companyId];
                if ($hasDiv) {
                    $sql .= ' AND p.division_id = :division_id';
                    $params[':division_id'] = $divisionId;
                }
                $sql .= ' ORDER BY p.plant_name ASC, p.plant_id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }"""

replacement = """        try {
            $pdo = getDbConnection();
            $hasDiv = ($divisionId !== null && $divisionId !== '' && (string)$divisionId !== 'ALL' && is_numeric($divisionId));
            $isAllCompany = ((string)$companyId === 'ALL' || !is_numeric($companyId));

            if (isPlatformAdmin($userId)) {
                if ($isAllCompany) {
                    $sql = '
                        SELECT p.plant_id, p.division_id, p.plant_code, p.plant_name, p.location, p.status
                        FROM plants p
                        WHERE UPPER(p.status) = \\'ACTIVE\\'
                    ';
                    $params = [];
                    if ($hasDiv) {
                        $sql .= ' AND p.division_id = :division_id';
                        $params[':division_id'] = (int)$divisionId;
                    }
                    $sql .= ' ORDER BY p.plant_name ASC, p.plant_id ASC';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                $sql = '
                    SELECT p.plant_id, p.division_id, p.plant_code, p.plant_name, p.location, p.status
                    FROM plants p
                    INNER JOIN divisions d ON d.division_id = p.division_id
                    WHERE d.company_id = :company_id AND UPPER(p.status) = \\'ACTIVE\\'
                ';
                $params = [':company_id' => (int)$companyId];
                if ($hasDiv) {
                    $sql .= ' AND p.division_id = :division_id';
                    $params[':division_id'] = (int)$divisionId;
                }
                $sql .= ' ORDER BY p.plant_name ASC, p.plant_id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }"""

assert target in content, "Target block in auth.php not found"
content = content.replace(target, replacement, 1)

with open('config/auth.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Successfully hardened getUserAccessiblePlants in config/auth.php!")

