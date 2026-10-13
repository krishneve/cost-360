<?php
$content = file_get_contents('config/upload_centre.php');

$pattern1 = '/\/\/ Backend Organizational Scope Validation[\s\S]*?if\s*\(\$userId && !isPlatformAdmin\(\$userId\)\)\s*\{[\s\S]*?\}\s*\}/';

$replacement1 = <<<'NEW'
// Backend Organizational Scope Validation
    $orgContext = getActiveOrganizationContext();
    $customerId = $orgContext['customer_id'] ?? ($_SESSION['active_customer_id'] ?? null);
    $companyId = $orgContext['company_id'] ?? ($_SESSION['active_company_id'] ?? null);
    $divisionId = $orgContext['division_id'] ?? ($_SESSION['active_division_id'] ?? null);
    $activeLoc = getActiveOperationalLocation();
    $locationType = $activeLoc['type_code'] ?? ($_SESSION['active_location_type'] ?? 'plant');
    $locationId = $activeLoc['id'] ?? ($_SESSION['active_plant_id'] ?? $_SESSION['active_warehouse_id'] ?? null);
    $periodId = $orgContext['period_id'] ?? ($_SESSION['active_period_id'] ?? null);

    if (!$customerId && $userId && function_exists('getUserCustomerTenantId')) {
        $customerId = getUserCustomerTenantId($userId);
    }

    if (!$companyId || !$locationId || !$periodId) {
        return ['ok' => false, 'message' => 'Validation Error: Active scope (Company, Location, and Period) must be selected before uploading files.'];
    }

    if ($userId && !isPlatformAdmin($userId) && !isCmaAdmin($userId)) {
        if ($customerId && function_exists('userCanAccessCustomer') && !userCanAccessCustomer($userId, $customerId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized for the selected customer tenant.'];
        }
        if ($companyId && function_exists('userCanAccessCompany') && !userCanAccessCompany($userId, $companyId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized to upload data for the selected company scope.'];
        }
        if ($locationId && function_exists('userCanAccessLocation') && !userCanAccessLocation($userId, $companyId, $divisionId, $locationType, $locationId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized to upload data for the selected location scope.'];
        }
        if ($periodId && function_exists('userCanAccessPeriod') && !userCanAccessPeriod($periodId, $userId)) {
            return ['ok' => false, 'message' => 'Authorization Error: You are not authorized to upload data for the selected period scope.'];
        }
    }
NEW;

$newContent = preg_replace($pattern1, $replacement1, $content, 1, $count1);

$pattern2 = '/\/\/ Backend scope authorization validation[\s\S]*?if\s*\(\$targetPeriodId && function_exists\(\'userCanAccessPeriod\'\) && !userCanAccessPeriod\(\$targetPeriodId, \$userId\)\)\s*\{[\s\S]*?\}\s*\}/';

$replacement2 = <<<'NEW2'
// Backend scope authorization validation
    if ($userId && function_exists('isPlatformAdmin') && !isPlatformAdmin($userId) && !isCmaAdmin($userId)) {
        if ($targetCompanyId && function_exists('userCanAccessCompany') && !userCanAccessCompany($userId, $targetCompanyId)) {
            return ['ok' => false, 'message' => 'Authorization Error: Access denied to target company scope.'];
        }
        if ($targetLocationId && function_exists('userCanAccessLocation') && !userCanAccessLocation($userId, $targetCompanyId, $targetDivisionId ?? null, $targetLocationType, $targetLocationId)) {
            return ['ok' => false, 'message' => 'Authorization Error: Access denied to target location scope.'];
        }
        if ($targetPeriodId && function_exists('userCanAccessPeriod') && !userCanAccessPeriod($targetPeriodId, $userId)) {
            return ['ok' => false, 'message' => 'Authorization Error: Access denied to target period scope.'];
        }
    }
NEW2;

$newContent = preg_replace($pattern2, $replacement2, $newContent, 1, $count2);

if ($count1 > 0 && $count2 > 0) {
    file_put_contents('config/upload_centre.php', $newContent);
    echo "Successfully patched both scope validation blocks in config/upload_centre.php!\n";
} else {
    echo "Patch counts: count1=$count1, count2=$count2\n";
}
