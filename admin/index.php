<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

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

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link" id="pendingCountLink">⏳ 待审核 <span id="pendingCountBadge" data-role="pending-count"><?= $pendingCount > 0 ? "($pendingCount)" : '' ?></span></a>
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
                    <tr data-message-id="<?= $msg['id'] ?>">
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>" data-role="status-badge" data-status="<?= (int)$msg['status'] ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions" data-role="actions">
                            <button type="button" class="btn btn-xs btn-info" data-act="view">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button type="button" class="btn btn-xs btn-success" data-act="audit" data-status="1">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button type="button" class="btn btn-xs btn-warning" data-act="audit" data-status="2">拒绝</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-xs btn-danger" data-act="delete">删除</button>
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
(function () {
    'use strict';

    // 与后端状态定义保持一致：0待审核 1已通过 2已拒绝
    var STATUS_LABELS = {0: '待审核', 1: '已通过', 2: '已拒绝'};
    var STATUS_CLASSES = {0: 'pending', 1: 'approved', 2: 'rejected'};

    // 列表当前的状态筛选条件（PHP 输出），用于判断审核后该行是否还应留在当前列表
    var currentFilterStatus = '<?= cleanInput($status) ?>';

    // 正在请求中的留言ID，防止重复点击产生并发请求
    var inflight = {};
    // 当前弹窗展示的留言：{id: Number, data: Object}
    var currentDetail = null;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ---- 轻量提示（后台未加载 main.js，这里自带 toast；console 保留便于排查） ----
    function notify(message, type) {
        type = type || 'info';
        var old = document.getElementById('adminToast');
        if (old) old.remove();
        var toast = document.createElement('div');
        toast.id = 'adminToast';
        toast.textContent = message;
        var colors = {success: '#10b981', error: '#ef4444', info: '#3b82f6', warning: '#f59e0b'};
        toast.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);' +
            'padding:10px 20px;border-radius:8px;color:#fff;font-size:14px;z-index:99999;' +
            'box-shadow:0 4px 12px rgba(0,0,0,.15);max-width:86%;text-align:center;' +
            'background:' + (colors[type] || colors.info);
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.style.transition = 'opacity .3s';
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 2600);
    }

    // ---- 统一请求封装 ----
    // 只有 HTTP 成功、响应为合法 JSON 且业务码 code === 0 时才算成功；
    // 其余情况（断网/超时/服务器 5xx/登录过期/业务拒绝）一律抛错，调用方保留原状态并提示原因。
    function apiRequest(url, options) {
        return fetch(url, options).then(function (resp) {
            return resp.text().then(function (text) {
                var data = null;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    var err = new Error(resp.ok
                        ? '服务器返回内容异常，操作结果未知，请刷新列表核实后再决定是否重试'
                        : '服务器错误（HTTP ' + resp.status + '），操作未生效，请稍后重试');
                    err.unknown = resp.ok;
                    throw err;
                }
                if (!resp.ok || data.code !== 0) {
                    var err2 = new Error(data.msg || ('操作未生效（HTTP ' + resp.status + '）'));
                    err2.data = data.data || null;
                    err2.code = data.code;
                    throw err2;
                }
                return data;
            });
        }, function () {
            // fetch 网络层失败（断网、跨域、被中断等）
            throw new Error('网络异常，请求未到达服务器，状态保持不变，请检查网络后重试');
        });
    }

    function getRow(id) {
        return document.querySelector('tr[data-message-id="' + id + '"]');
    }

    function setRowBusy(row, busy) {
        if (!row) return;
        var btns = row.querySelectorAll('button[data-act]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].disabled = busy;
        }
    }

    // 按服务端确认后的最新状态重建操作按钮，保证与真实状态一致
    function renderActions(actionsCell, status) {
        actionsCell.innerHTML =
            '<button type="button" class="btn btn-xs btn-info" data-act="view">查看</button>' +
            (status !== 1 ? '<button type="button" class="btn btn-xs btn-success" data-act="audit" data-status="1">通过</button>' : '') +
            (status !== 2 ? '<button type="button" class="btn btn-xs btn-warning" data-act="audit" data-status="2">拒绝</button>' : '') +
            '<button type="button" class="btn btn-xs btn-danger" data-act="delete">删除</button>';
    }

    function updateStatusBadge(row, status, label, cls) {
        var badge = row.querySelector('[data-role="status-badge"]');
        if (!badge) return;
        badge.className = 'status-badge status-' + (cls || STATUS_CLASSES[status] || '');
        badge.textContent = label || STATUS_LABELS[status] || '未知';
        badge.setAttribute('data-status', status);
    }

    // 待审数量一律以服务端返回值为准（与列表筛选 status=0 时的结果同源）
    function updatePendingCount(n) {
        if (typeof n !== 'number') return;
        var el = document.querySelector('[data-role="pending-count"]');
        if (el) el.textContent = n > 0 ? '(' + n + ')' : '';
    }

    // 行被移除后同步分页“共 X 条”，保证统计与列表一致
    function decrementTotalInfo() {
        var info = document.querySelector('.page-info');
        if (info) {
            var m = info.textContent.match(/\d+/);
            if (m) info.textContent = '共 ' + Math.max(0, parseInt(m[0], 10) - 1) + ' 条';
        }
    }

    function ensureEmptyRow() {
        var tbody = document.querySelector('.admin-table tbody');
        if (!tbody) return;
        if (!tbody.querySelector('tr[data-message-id]')) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center">暂无数据</td></tr>';
        }
    }

    function removeRow(row) {
        if (!row || !row.parentNode) return;
        row.parentNode.removeChild(row);
        decrementTotalInfo();
        ensureEmptyRow();
    }

    // 审核成功后：依据服务端返回的权威状态更新行、弹窗与待审数
    function applyAuditResult(data) {
        var row = getRow(data.id);
        if (row) {
            // 当前列表有状态筛选，且新状态不属于该筛选条件 → 从列表移除（与刷新后看到的一致）
            if (currentFilterStatus !== '' && parseInt(currentFilterStatus, 10) !== data.status) {
                removeRow(row);
            } else {
                updateStatusBadge(row, data.status, data.status_label, data.status_class);
                renderActions(row.querySelector('[data-role="actions"]'), data.status);
            }
        }
        // 详情弹窗若正打开的是同一条，同步更新，避免残留旧状态
        if (currentDetail && currentDetail.id === data.id) {
            currentDetail.data.status = data.status;
            currentDetail.data.status_label = data.status_label;
            currentDetail.data.status_class = data.status_class;
            var line = document.querySelector('[data-role="detail-status"]');
            if (line) {
                line.innerHTML = '<strong>状态：</strong><span class="status-badge status-' +
                    escapeHtml(data.status_class) + '">' + escapeHtml(data.status_label) + '</span>';
            }
        }
        updatePendingCount(data.pending_count);
    }

    // 审核（通过/拒绝）。只有收到服务端成功确认才更新界面。
    function auditMessage(id, status) {
        if (inflight[id]) {
            notify('该留言正在处理中，请勿重复点击', 'warning');
            return;
        }
        var actionText = status === 1 ? '通过' : '拒绝';
        if (!window.confirm('确定要' + actionText + '这条留言吗？')) return;

        var row = getRow(id);
        inflight[id] = true;
        setRowBusy(row, true);

        apiRequest('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=audit&id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(status)
        }).then(function (res) {
            var d = res.data || {};
            applyAuditResult(d);
            if (d.changed === false) {
                // 重复提交相同结果：最终结果未改变，仅提示
                notify(res.msg || '该留言已是此审核状态，结果未改变', 'info');
            } else {
                notify(res.msg || '操作成功', 'success');
            }
        }).catch(function (err) {
            // 冲突（已被另一请求/管理员审核）：以服务端状态为准同步界面，并说明原因
            if (err.data && typeof err.data.status !== 'undefined') {
                applyAuditResult(err.data);
                notify(err.message, 'warning');
            } else {
                // 任何失败都不改动本地状态（按钮恢复后即可重试）
                notify(err.message || '操作失败，状态保持不变，请重试', 'error');
            }
        }).finally(function () {
            delete inflight[id];
            setRowBusy(row, false);
        });
    }

    function deleteMessage(id) {
        if (inflight[id]) {
            notify('该留言正在处理中，请勿重复点击', 'warning');
            return;
        }
        if (!window.confirm('确定要删除这条留言吗？此操作不可恢复！')) return;

        var row = getRow(id);
        inflight[id] = true;
        setRowBusy(row, true);

        apiRequest('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=delete&id=' + encodeURIComponent(id)
        }).then(function (res) {
            var d = res.data || {};
            if (currentDetail && currentDetail.id === id) closeModal();
            removeRow(getRow(id));
            if (typeof d.pending_count === 'number') updatePendingCount(d.pending_count);
            notify(res.msg || '删除成功', 'success');
        }).catch(function (err) {
            if (err.data && typeof err.data.pending_count === 'number') {
                updatePendingCount(err.data.pending_count);
            }
            notify(err.message || '删除失败，状态保持不变，请重试', 'error');
        }).finally(function () {
            delete inflight[id];
            setRowBusy(row, false);
        });
    }

    function detailHtml(d) {
        var html = '<div class="detail-view">';
        html += '<p><strong>类型：</strong>' + escapeHtml(d.type_label) + '</p>';
        html += '<p><strong>标题：</strong>' + escapeHtml(d.title) + '</p>';
        html += '<p><strong>昵称：</strong>' + escapeHtml(d.nickname) + '</p>';
        html += '<p><strong>电话：</strong>' + (d.phone ? escapeHtml(d.phone) : '未填写') + '</p>';
        html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
        if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + escapeHtml(d.image) + '" style="max-width:100%;margin-top:8px;"></p>';
        html += '<p data-role="detail-status"><strong>状态：</strong><span class="status-badge status-' +
            escapeHtml(d.status_class || STATUS_CLASSES[d.status]) + '">' +
            escapeHtml(d.status_label || STATUS_LABELS[d.status]) + '</span></p>';
        html += '<p><strong>浏览量：</strong>' + escapeHtml(d.views) + '</p>';
        html += '<p><strong>时间：</strong>' + escapeHtml(d.created_at) + '</p>';
        html += '</div>';
        return html;
    }

    // 查看详情：每次都从服务端拉取最新数据，避免读到缓存的旧状态
    function viewMessage(id) {
        var modal = document.getElementById('viewModal');
        var body = document.getElementById('modalBody');
        modal.style.display = 'flex';
        currentDetail = {id: id, data: null};
        body.innerHTML = '加载中...';

        apiRequest('api.php?action=detail&id=' + encodeURIComponent(id)).then(function (res) {
            // 响应回来前弹窗可能已被关闭或切换，仍记录缓存，供审核后同步
            currentDetail = {id: id, data: res.data};
            body.innerHTML = detailHtml(res.data);
        }).catch(function (err) {
            // 失败时显示原因并提供“重试”，不展示任何猜测的旧数据
            body.innerHTML = '<div class="text-center" style="padding:16px;">' +
                '<p style="color:#ef4444;margin-bottom:12px;">' + escapeHtml(err.message) + '</p>' +
                '<button type="button" class="btn btn-primary btn-sm" id="retryDetailBtn">重试</button>' +
                '</div>';
            var retry = document.getElementById('retryDetailBtn');
            if (retry) retry.addEventListener('click', function () { viewMessage(id); });
        });
    }

    function closeModal() {
        document.getElementById('viewModal').style.display = 'none';
        currentDetail = null;
    }

    // 事件委托：动态重建的按钮也能响应；同时避免内联 onclick 被重复触发
    document.querySelector('.admin-table tbody').addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('button[data-act]') : null;
        if (!btn) return;
        var row = btn.closest('tr[data-message-id]');
        if (!row) return;
        var id = parseInt(row.getAttribute('data-message-id'), 10);
        var act = btn.getAttribute('data-act');
        if (act === 'view') viewMessage(id);
        else if (act === 'audit') auditMessage(id, parseInt(btn.getAttribute('data-status'), 10));
        else if (act === 'delete') deleteMessage(id);
    });

    document.getElementById('viewModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    // 兼容弹窗关闭按钮的内联 onclick
    window.closeModal = closeModal;
})();
</script>
