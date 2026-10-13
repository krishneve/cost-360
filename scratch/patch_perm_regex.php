<?php
$content = file_get_contents('config/auth.php');

$pattern = '/if\s*\(!function_exists\(\'userHasPermission\'\)\)\s*\{[\s\S]*?return false;\s*\}\s*\}/';

$replacement = <<<'NEW'
if (!function_exists('userHasPermission')) {

    function userHasPermission(
        $permissionCode,
        $menuCode = null,
        $userId = null
    ) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if ($userId && isPlatformAdmin($userId)) {
            return true;
        }

        $permissions = getUserAuthorizedPermissions($userId);
        $normReqMenu = $menuCode ? str_replace(['centre', '-', '_', '/', ' '], ['center', '', '', '', ''], strtolower(trim($menuCode))) : null;

        foreach ($permissions as $p) {
            if (strcasecmp($p['permission_code'], $permissionCode) === 0) {
                if ($menuCode === null) {
                    return true;
                }
                if (strcasecmp($p['menu_code'], $menuCode) === 0) {
                    return true;
                }
                $normPermMenu = str_replace(['centre', '-', '_', '/', ' '], ['center', '', '', '', ''], strtolower(trim($p['menu_code'] ?? '')));
                if ($normReqMenu !== '' && ($normPermMenu === $normReqMenu || str_contains($normPermMenu, $normReqMenu) || str_contains($normReqMenu, $normPermMenu))) {
                    return true;
                }
            }
        }

        return false;
    }
}
NEW;

$newContent = preg_replace($pattern, $replacement, $content, 1, $count);
if ($count > 0) {
    file_put_contents('config/auth.php', $newContent);
    echo "Successfully replaced userHasPermission (count: {$count})\n";
} else {
    echo "Regex pattern match for userHasPermission failed.\n";
}
