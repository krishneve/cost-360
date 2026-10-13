<?php
$content = file_get_contents('config/auth.php');

$oldMenuAccess = <<<'OLD'
if (!function_exists('userHasMenuAccess')) {

    function userHasMenuAccess(
        $menuRouteOrCode,
        $userId = null
    ) {

        $menus =
            getUserAuthorizedMenus(
                $userId
            );

        $cleanTarget =
            strtolower(
                trim($menuRouteOrCode)
            );

        foreach ($menus as $m) {

            $mCode =
                strtolower(
                    trim($m['menu_code'])
                );

            $mRoute =
                strtolower(
                    trim($m['route'])
                );

            if (
                $mCode === $cleanTarget ||
                $mRoute === $cleanTarget
            ) {
                return true;
            }

            if (
                $mRoute !== '' &&
                (
                    str_contains(
                        $mRoute,
                        $cleanTarget
                    ) ||
                    str_contains(
                        $cleanTarget,
                        $mCode
                    )
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
OLD;

$newMenuAccess = <<<'NEW'
if (!function_exists('userHasMenuAccess')) {

    function userHasMenuAccess(
        $menuRouteOrCode,
        $userId = null
    ) {
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            return false;
        }

        if (isPlatformAdmin($userId)) {
            return true;
        }

        $menus = getUserAuthorizedMenus($userId);
        $cleanTarget = strtolower(trim($menuRouteOrCode));
        $cleanTargetNorm = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], $cleanTarget);

        $aliasMap = [
            'upload_center' => ['upload_centre', 'upload-centre.php', '/data/upload', 'data/upload', 'upload', 'data_management', '/data'],
            'upload_centre' => ['upload_center', 'upload-centre.php', '/data/upload', 'data/upload', 'upload', 'data_management', '/data'],
            'mapping' => ['mapping_center', 'mapping_centre', 'mapping-centre.php', '/data/mapping', 'data/mapping', 'data_management', '/data'],
            'mapping_centre' => ['mapping', 'mapping_center', 'mapping-centre.php', '/data/mapping', 'data/mapping', 'data_management', '/data'],
            'import_history' => ['import-history.php', 'upload_center', 'upload_centre', 'data_management', '/data'],
            'audit_logs' => ['audit-logs.php', 'audit', 'audit_trail', 'audit_desk', 'reconciliation', '/audit'],
            'master_groups' => ['master-groups.php', 'data_management', 'settings'],
        ];

        foreach ($menus as $m) {
            $mCode = strtolower(trim($m['menu_code'] ?? ''));
            $mRoute = strtolower(trim($m['route'] ?? ''));
            $mCodeNorm = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], $mCode);
            $mRouteNorm = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], $mRoute);

            if ($mCode === $cleanTarget || $mRoute === $cleanTarget) {
                return true;
            }

            if ($mCodeNorm !== '' && ($mCodeNorm === $cleanTargetNorm || str_contains($cleanTargetNorm, $mCodeNorm) || str_contains($mCodeNorm, $cleanTargetNorm))) {
                return true;
            }

            if ($mRouteNorm !== '' && ($mRouteNorm === $cleanTargetNorm || str_contains($mRouteNorm, $cleanTargetNorm) || str_contains($cleanTargetNorm, $mRouteNorm))) {
                return true;
            }

            if (isset($aliasMap[$cleanTarget])) {
                foreach ($aliasMap[$cleanTarget] as $alt) {
                    $normAlt = str_replace(['centre', '-', '_', '/', '.php', ' '], ['center', '', '', '', '', ''], strtolower($alt));
                    if ($mCode === strtolower($alt) || $mRoute === strtolower($alt) || $mCodeNorm === $normAlt || $mRouteNorm === $normAlt) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
NEW;

if (strpos($content, $oldMenuAccess) !== false) {
    $content = str_replace($oldMenuAccess, $newMenuAccess, $content);
    file_put_contents('config/auth.php', $content);
    echo "Updated userHasMenuAccess successfully.\n";
} else {
    echo "Old pattern not found.\n";
}
