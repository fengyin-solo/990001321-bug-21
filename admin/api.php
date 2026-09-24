<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

// API 响应一律不允许缓存，保证详情、统计每次都取服务端最新状态
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

// 任何未预期的异常（数据库错误等）都返回结构化 JSON，
// 而不是让前端拿到一段 HTML 后误判或静默失败
try {
switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在或已被删除');
        // 所有输出字段统一转义，避免弹窗内拼接 HTML 时产生 XSS
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['status_class'] = getStatusClass($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        $msg['phone'] = $msg['phone'] !== null && $msg['phone'] !== '' ? cleanInput($msg['phone']) : '';
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if ($id <= 0) jsonResponse(1, '缺少留言ID');
        if (!in_array($status, [1, 2], true)) jsonResponse(1, '无效的审核状态');

        // 行锁 + 条件更新，保证重复点击 / 并发点击不会改变第一次已确认的审核结果
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT id, status FROM messages WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $msg = $stmt->fetch();

            if (!$msg) {
                $db->rollBack();
                jsonResponse(1, '留言不存在或已被删除，请刷新列表后重试');
            }

            if ((int)$msg['status'] === $status) {
                // 同状态重复提交（网络重试、重复点击）：结果幂等，视为成功
                $db->rollBack();
                jsonResponse(0, $status === 1 ? '该留言已是“已通过”状态' : '该留言已是“已拒绝”状态');
            }

            if ((int)$msg['status'] !== 0) {
                // 已经被审核为另一种结果（可能由其他管理员操作）：不覆盖，返回冲突并带回当前状态
                $db->rollBack();
                jsonResponse(2, '该留言已被审核为“' . getStatusLabel($msg['status']) . '”，审核结果未改变', [
                    'id' => (int)$msg['id'],
                    'status' => (int)$msg['status'],
                ]);
            }

            $upd = $db->prepare("UPDATE messages SET status = ? WHERE id = ? AND status = 0");
            $upd->execute([$status, $id]);

            if ($upd->rowCount() !== 1) {
                $db->rollBack();
                jsonResponse(1, '审核未生效，请刷新页面后重试');
            }

            $db->commit();
            jsonResponse(0, $status === 1 ? '已通过' : '已拒绝', [
                'id' => $id,
                'status' => $status,
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '缺少留言ID');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT image FROM messages WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $msg = $stmt->fetch();

            if (!$msg) {
                // 删除天然幂等：目标已不存在（如重复点击），直接返回成功
                $db->rollBack();
                jsonResponse(0, '留言已删除');
            }

            if ($msg['image']) {
                $imgFile = __DIR__ . '/../' . $msg['image'];
                if (file_exists($imgFile)) {
                    @unlink($imgFile); // 图片删除失败不影响数据库操作
                }
            }
            $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
            $db->commit();

            jsonResponse(0, '删除成功', ['id' => $id]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if (!in_array($status, [1, 2, 3], true)) jsonResponse(1, '无效状态');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) {
                $db->rollBack();
                jsonResponse(1, '举报不存在或已被处理，请刷新列表后重试');
            }

            if ($status === 1) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ? FOR UPDATE");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) {
                        @unlink($imgFile);
                    }
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ? AND status = 0");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        break;

    default:
        jsonResponse(1, '未知操作');
}
} catch (Throwable $e) {
    // 出错时不改变任何前端状态，明确告知失败原因，便于用户重试
    jsonResponse(500, '服务器处理失败：' . $e->getMessage());
}
