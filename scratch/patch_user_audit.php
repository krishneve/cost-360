<?php
$content = file_get_contents('admin/users/api.php');

$pattern = '/function writeAuditLog\([\s\S]*?\n\}/';

$replacement = <<<'NEW'
function writeAuditLog(
    PDO $pdo,
    int $currentUserId,
    int $entityId,
    string $action,
    ?array $oldValue,
    ?array $newValue
): void {
    try {
        // Map standard audit actions (Phase 17)
        $mappedAction = $action;
        if ($action === 'INSERT') {
            $mappedAction = 'USER_CREATED';
        } elseif ($action === 'UPDATE') {
            if (isset($newValue['status']) && isset($oldValue['status']) && $newValue['status'] !== $oldValue['status']) {
                $mappedAction = (strtoupper($newValue['status']) === 'ACTIVE') ? 'USER_ACTIVATED' : 'USER_DEACTIVATED';
            } else {
                $mappedAction = 'USER_UPDATED';
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO audit_log
            (
                user_id,
                entity_name,
                entity_id,
                action,
                old_value,
                new_value,
                timestamp,
                ip_address
            )
            VALUES
            (
                :user_id,
                :entity_name,
                :entity_id,
                :action,
                :old_value,
                :new_value,
                CURRENT_TIMESTAMP,
                :ip_address
            )
        ");

        $stmt->execute([
            ':user_id' => $currentUserId > 0 ? $currentUserId : null,
            ':entity_name' => 'users',
            ':entity_id' => $entityId,
            ':action' => $mappedAction,
            ':old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            ':new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        error_log("Write User Audit Log Warning: " . $e->getMessage());
        // Fallback to basic INSERT/UPDATE/DELETE if constraint still exists
        try {
            $fallbackAction = in_array(strtoupper($action), ['INSERT', 'UPDATE', 'DELETE'], true) ? strtoupper($action) : 'INSERT';
            $fallbackStmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, entity_name, entity_id, action, old_value, new_value, timestamp, ip_address)
                VALUES (:user_id, 'users', :entity_id, :action, :old_value, :new_value, CURRENT_TIMESTAMP, :ip_address)
            ");
            $fallbackStmt->execute([
                ':user_id' => $currentUserId > 0 ? $currentUserId : null,
                ':entity_id' => $entityId,
                ':action' => $fallbackAction,
                ':old_value' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                ':new_value' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Throwable $f) {
            error_log("Fallback User Audit Log Error: " . $f->getMessage());
        }
    }
}
NEW;

$newContent = preg_replace($pattern, $replacement, $content, 1, $count);
if ($count > 0) {
    file_put_contents('admin/users/api.php', $newContent);
    echo "Successfully updated writeAuditLog in admin/users/api.php (count: {$count})\n";
} else {
    echo "Regex pattern match failed.\n";
}
