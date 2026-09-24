<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// 管理接口返回 JSON：未登录时也必须返回 JSON，避免前端把登录跳转页误当作成功响应
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    jsonResponse(401, '登录已过期，请重新登录后再试');
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * 全局兜底：任何数据库/运行时异常都返回明确的 JSON 错误，
 * 避免前端收到非 JSON 内容（如报错 HTML）后误判为成功。
 */
set_exception_handler(function (Throwable $e) {
    error_log('[admin/api] ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(1, '服务器繁忙，操作未生效，请稍后重试');
});

$db = getDB();

/**
 * 当前待审核留言数（以服务端数据为准，返回给前端同步显示）
 */
function getPendingMessageCount(PDO $db) {
    return (int) $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
}

try {

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在或已被删除');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['status_class'] = getStatusClass($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        $msg['phone'] = $msg['phone'] ? cleanInput($msg['phone']) : '';
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if ($id <= 0) jsonResponse(1, '缺少留言ID');
        if (!in_array($status, [1, 2], true)) jsonResponse(1, '无效的审核状态');

        $db->beginTransaction();
        try {
            // 行锁 + 条件更新：只有“待审核(0)”才能被审核，
            // 保证重复点击 / 并发请求不会改变已经确定的审核结果
            $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $msg = $stmt->fetch();
            if (!$msg) {
                $db->rollBack();
                jsonResponse(1, '留言不存在或已被删除，操作未生效');
            }

            $currentStatus = (int) $msg['status'];

            // 幂等：重复提交相同结果不报错，但不会再改变任何数据
            if ($currentStatus === $status) {
                $db->rollBack();
                jsonResponse(0, '该留言已是' . getStatusLabel($status) . '状态，无需重复操作', [
                    'id' => $id,
                    'status' => $currentStatus,
                    'status_label' => getStatusLabel($currentStatus),
                    'status_class' => getStatusClass($currentStatus),
                    'changed' => false,
                    'pending_count' => getPendingMessageCount($db),
                ]);
            }

            // 已被另一个请求/管理员审核成其它结果：拒绝覆盖，返回服务端真实状态
            if ($currentStatus !== 0) {
                $db->rollBack();
                jsonResponse(1, '该留言已被审核为“' . getStatusLabel($currentStatus) . '”，页面将同步为最新状态，请勿重复操作', [
                    'id' => $id,
                    'status' => $currentStatus,
                    'status_label' => getStatusLabel($currentStatus),
                    'status_class' => getStatusClass($currentStatus),
                    'changed' => false,
                    'pending_count' => getPendingMessageCount($db),
                ]);
            }

            $upd = $db->prepare("UPDATE messages SET status = ? WHERE id = ? AND status = 0");
            $upd->execute([$status, $id]);
            if ($upd->rowCount() !== 1) {
                $db->rollBack();
                jsonResponse(1, '审核未生效（数据状态已变化），请刷新后重试');
            }

            $db->commit();

            jsonResponse(0, ($status === 1 ? '通过' : '拒绝') . '成功', [
                'id' => $id,
                'status' => $status,
                'status_label' => getStatusLabel($status),
                'status_class' => getStatusClass($status),
                'changed' => true,
                'pending_count' => getPendingMessageCount($db),
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
                $db->rollBack();
                // 已不存在视为幂等失败：明确告知，前端保留/刷新状态，绝不假报删除成功
                jsonResponse(1, '留言不存在或已被删除，操作未生效', [
                    'id' => $id,
                    'pending_count' => getPendingMessageCount($db),
                ]);
            }

            $del = $db->prepare("DELETE FROM messages WHERE id = ?");
            $del->execute([$id]);
            if ($del->rowCount() !== 1) {
                $db->rollBack();
                jsonResponse(1, '删除未生效，请刷新后重试');
            }

            $image = $msg['image'];
            $pendingCount = getPendingMessageCount($db);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // 事务提交（删除已确认落库）后再删除物理文件；文件残留不影响数据一致性
        if (!empty($image)) {
            $imgFile = __DIR__ . '/../' . $image;
            if (is_file($imgFile)) @unlink($imgFile);
        }

        jsonResponse(0, '删除成功', [
            'id' => $id,
            'pending_count' => $pendingCount,
        ]);
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
                // 可能已被处理；回滚后返回明确信息，前端同步真实状态而不是提示成功
                $db->rollBack();
                jsonResponse(1, '举报不存在或已被处理，操作未生效');
            }

            $image = null;
            if ($status === 1) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg) {
                    $image = $msg['image'];
                    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
                }
                // 留言已被删除时仍允许完成举报处理（幂等），不阻断
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ? AND status = 0");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);
            if ($stmt->rowCount() !== 1) {
                $db->rollBack();
                jsonResponse(1, '举报状态已变化，处理未生效，请刷新后重试');
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // 提交后再清理物理文件
        if (!empty($image)) {
            $imgFile = __DIR__ . '/../' . $image;
            if (is_file($imgFile)) @unlink($imgFile);
        }

        $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
        jsonResponse(0, $statusMsg[$status] . '成功');
        break;

    default:
        jsonResponse(1, '未知操作');
}

} catch (Throwable $e) {
    error_log('[admin/api] ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(1, '服务器繁忙，操作未生效，请稍后重试');
}
