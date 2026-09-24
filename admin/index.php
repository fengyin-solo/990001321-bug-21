<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

// 后台页面不缓存，审核后刷新始终取服务端最新状态
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 筛选参数
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, ['0', '1', '2'])) {
    $where .= " AND status = ?";
    $params[] = intval($status);
}
if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
    $where .= " AND type = ?";
    $params[] = $type;
}
if ($keyword) {
    $where .= " AND (title LIKE ? OR content LIKE ? OR nickname LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

// 列表、筛选总数、待审数必须来自同一数据库快照，
// 否则并发审核时三者可能对不上（如待审数量与列表显示不一致）
$db->beginTransaction();
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    // 统计
    $pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
$totalPages = ceil($total / $pageSize);

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>留言管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待审核</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已通过</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已拒绝</option>
                </select>
                <select name="type">
                    <option value="">全部类型</option>
                    <option value="help" <?= $type === 'help' ? 'selected' : '' ?>>居民求助</option>
                    <option value="suggest" <?= $type === 'suggest' ? 'selected' : '' ?>>意见建议</option>
                    <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>失物招领</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="index.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 留言表格 -->
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <th>状态</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="8" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr data-id="<?= $msg['id'] ?>" data-status="<?= (int)$msg['status'] ?>">
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>" data-role="status"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions" data-role="actions">
                            <button type="button" class="btn btn-xs btn-info" data-act="view" data-id="<?= $msg['id'] ?>">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button type="button" class="btn btn-xs btn-success" data-act="audit" data-id="<?= $msg['id'] ?>" data-status="1">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button type="button" class="btn btn-xs btn-warning" data-act="audit" data-id="<?= $msg['id'] ?>" data-status="2">拒绝</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-xs btn-danger" data-act="delete" data-id="<?= $msg['id'] ?>">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?page=<?= $page - 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?page=<?= $i ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?page=<?= $page + 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 查看弹窗 -->
<div class="modal" id="viewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>留言详情</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">加载中...</div>
    </div>
</div>

<script>
// 正在处理中的留言ID集合，防止重复点击 / 通过与拒绝并发提交
var pendingIds = {};
// 弹窗当前展示的留言ID与状态
var currentDetailId = null;
var currentDetailStatus = null;

var STATUS_CLASS = {0: 'pending', 1: 'approved', 2: 'rejected'};

/**
 * 统一的后台 POST 请求
 * 只有服务端明确返回 code === 0 才算成功；
 * 网络异常、非 JSON 响应（如登录过期 302、服务器 500 HTML）一律按失败处理并说明原因
 */
function postAction(params, onDone) {
    var body = Object.keys(params).map(function(k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');

    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body,
        redirect: 'error',
        cache: 'no-store'
    }).then(function(resp) {
        if (!resp.ok) {
            throw new Error('服务器返回异常（HTTP ' + resp.status + '），请检查网络或重新登录后重试');
        }
        return resp.text();
    }).then(function(text) {
        var data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            // 典型场景：登录会话过期被跳转登录页，或服务器输出了错误页
            throw new Error('服务端响应异常，操作未生效，请重新登录后重试');
        }
        onDone(data);
    }).catch(function(err) {
        // 网络失败 / 解析失败：明确提示原因，不更新任何界面状态
        onDone({code: -1, msg: err && err.message ? err.message : '网络请求失败，请检查网络后重试'});
    });
}

function setRowBusy(id, busy) {
    var row = document.querySelector('tr[data-id="' + id + '"]');
    if (row) {
        row.querySelectorAll('button').forEach(function(btn) { btn.disabled = busy; });
    }
    var modalBtns = document.querySelectorAll('#modalBody button[data-id="' + id + '"]');
    modalBtns.forEach(function(btn) { btn.disabled = busy; });
}

/**
 * 服务端确认成功后的统一处理：
 * 先关闭可能展示旧状态的详情弹窗，再整体刷新页面，
 * 让列表、筛选结果、待审数量、详情全部以服务端最新数据重新渲染
 */
function applyServerConfirmed(msg) {
    closeModal();
    alert(msg || '操作成功');
    location.reload(); // reload 强制不使用缓存，避免刷新后仍看到旧状态
}

function auditMessage(id, status) {
    var action = status === 1 ? '通过' : '拒绝';
    if (pendingIds[id]) {
        alert('该留言正在处理中，请勿重复点击');
        return;
    }
    if (!confirm('确定要' + action + '这条留言吗？')) return;

    pendingIds[id] = true;
    setRowBusy(id, true);

    postAction({action: 'audit', id: id, status: status}, function(data) {
        if (data.code === 0) {
            // 只有服务端确认后才更新界面
            applyServerConfirmed(data.msg || (action + '成功'));
            return;
        }
        // 失败：恢复原状态与按钮，保留现场允许重试
        pendingIds[id] = false;
        setRowBusy(id, false);
        if (data.code === 2 && data.data) {
            // 状态冲突（已被其他人/其他操作审核）：不覆盖，提示并以服务端状态为准刷新
            alert(data.msg || '该留言已被审核，审核结果未改变');
            location.reload();
        } else {
            alert((data.msg || '操作失败') + '\n状态未改变，可重新点击按钮重试');
        }
    });
}

function deleteMessage(id) {
    if (pendingIds[id]) {
        alert('该留言正在处理中，请勿重复点击');
        return;
    }
    if (!confirm('确定要删除这条留言吗？此操作不可恢复！')) return;

    pendingIds[id] = true;
    setRowBusy(id, true);

    postAction({action: 'delete', id: id}, function(data) {
        if (data.code === 0) {
            applyServerConfirmed(data.msg || '删除成功');
            return;
        }
        pendingIds[id] = false;
        setRowBusy(id, false);
        alert((data.msg || '删除失败') + '\n留言未删除，可重新点击按钮重试');
    });
}

function viewMessage(id) {
    currentDetailId = id;
    currentDetailStatus = null;
    var modal = document.getElementById('viewModal');
    var body = document.getElementById('modalBody');
    modal.style.display = 'flex';
    body.innerHTML = '加载中...';
    fetch('api.php?action=detail&id=' + id, {cache: 'no-store'})
        .then(function(resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        })
        .then(function(data) {
            if (data.code !== 0) throw new Error(data.msg || '详情加载失败');
            renderDetail(data.data);
        })
        .catch(function(err) {
            // 详情加载失败不残留旧内容，给出原因和重试入口
            body.innerHTML = '<div class="text-center" style="padding:20px;">'
                + '详情加载失败：' + (err && err.message ? err.message : '网络错误')
                + '<br><br><button type="button" class="btn btn-primary btn-sm" onclick="viewMessage(' + id + ')">重试</button>'
                + '</div>';
        });
}

function renderDetail(d) {
    currentDetailStatus = parseInt(d.status, 10);
    var html = '<div class="detail-view">';
    html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
    html += '<p><strong>标题：</strong>' + d.title + '</p>';
    html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
    html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
    html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
    if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
    html += '<p><strong>状态：</strong><span class="status-badge status-' + (d.status_class || STATUS_CLASS[currentDetailStatus]) + '">' + d.status_label + '</span></p>';
    html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
    html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
    // 详情内也可直接审核，走同一套“服务端确认后才更新”的流程
    html += '<div class="form-actions" style="margin-top:16px;">';
    if (currentDetailStatus !== 1) {
        html += '<button type="button" class="btn btn-success btn-sm" data-id="' + d.id + '" onclick="auditMessage(' + d.id + ', 1)">通过</button>';
    }
    if (currentDetailStatus !== 2) {
        html += '<button type="button" class="btn btn-warning btn-sm" data-id="' + d.id + '" onclick="auditMessage(' + d.id + ', 2)">拒绝</button>';
    }
    html += '<button type="button" class="btn btn-danger btn-sm" data-id="' + d.id + '" onclick="deleteMessage(' + d.id + ')">删除</button>';
    html += '</div></div>';
    document.getElementById('modalBody').innerHTML = html;
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
    currentDetailId = null;
    currentDetailStatus = null;
}

// 事件委托：列表内所有操作按钮统一处理
document.querySelector('.admin-table tbody').addEventListener('click', function(e) {
    var btn = e.target.closest('button');
    if (!btn) return;
    var id = parseInt(btn.dataset.id, 10);
    if (!id) return;
    if (btn.dataset.act === 'view') {
        viewMessage(id);
    } else if (btn.dataset.act === 'audit') {
        auditMessage(id, parseInt(btn.dataset.status, 10));
    } else if (btn.dataset.act === 'delete') {
        deleteMessage(id);
    }
});

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
