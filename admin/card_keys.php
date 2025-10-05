<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理生成卡密
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_card'])) {
    $points = (int)getPost('points');
    $max_uses = (int)getPost('max_uses');
    $quantity = (int)getPost('quantity');
    
    if ($points <= 0) {
        showError('积分数量必须大于0');
    } elseif ($max_uses <= 0) {
        showError('使用次数必须大于0');
    } elseif ($quantity <= 0 || $quantity > 100) {
        showError('生成数量必须在1-100之间');
    } else {
        $generated = 0;
        for ($i = 0; $i < $quantity; $i++) {
            // 生成唯一卡密
            do {
                $card_key = strtoupper(generateRandomString(16));
                $exists = $db->querySingle("SELECT COUNT(*) FROM card_keys WHERE card_key = '$card_key'");
            } while ($exists);
            
            $stmt = $db->prepare("INSERT INTO card_keys (card_key, points, max_uses, created_by) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $card_key, SQLITE3_TEXT);
            $stmt->bindValue(2, $points, SQLITE3_INTEGER);
            $stmt->bindValue(3, $max_uses, SQLITE3_INTEGER);
            $stmt->bindValue(4, $_SESSION['admin_id'], SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                $generated++;
            }
        }
        
        if ($generated > 0) {
            logAction('admin', $_SESSION['admin_id'], 'generate_cards', "生成了 $generated 张卡密，每张 $points 积分，最多使用 $max_uses 次");
            showSuccess("成功生成 $generated 张卡密！");
        } else {
            showError('生成卡密失败！');
        }
    }
    redirect('card_keys.php');
}

// 处理删除卡密
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_card'])) {
    $card_id = (int)getPost('card_id');
    
    if ($card_id <= 0) {
        showError('无效的卡密ID！');
    } else {
        $card = $db->querySingle("SELECT card_key FROM card_keys WHERE id = $card_id", true);
        if ($card) {
            $stmt = $db->prepare("DELETE FROM card_keys WHERE id = ?");
            $stmt->bindValue(1, $card_id, SQLITE3_INTEGER);
            
            if ($stmt->execute() && $db->changes() > 0) {
                logAction('admin', $_SESSION['admin_id'], 'delete_card', "删除卡密: {$card['card_key']}");
                showSuccess('卡密删除成功！');
            } else {
                showError('删除失败！');
            }
        } else {
            showError('卡密不存在！');
        }
    }
    redirect('card_keys.php');
}

// 处理禁用/启用卡密
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $card_id = (int)getPost('card_id');
    $new_status = (int)getPost('new_status');
    
    if ($card_id <= 0) {
        showError('无效的卡密ID！');
    } else {
        $card = $db->querySingle("SELECT card_key, status FROM card_keys WHERE id = $card_id", true);
        if ($card) {
            $stmt = $db->prepare("UPDATE card_keys SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bindValue(1, $new_status, SQLITE3_INTEGER);
            $stmt->bindValue(2, $card_id, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                $action = $new_status ? '启用' : '禁用';
                logAction('admin', $_SESSION['admin_id'], 'toggle_card_status', "$action 卡密: {$card['card_key']}");
                showSuccess("卡密 $action 成功！");
            } else {
                showError('操作失败！');
            }
        } else {
            showError('卡密不存在！');
        }
    }
    redirect('card_keys.php');
}

// 获取卡密列表
$page = (int)getGet('page', 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$total_cards = $db->querySingle("SELECT COUNT(*) FROM card_keys");
$total_pages = ceil($total_cards / $limit);

$cards = [];
$result = $db->query("SELECT ck.*, a.username as created_by_name 
                     FROM card_keys ck 
                     LEFT JOIN admins a ON ck.created_by = a.id 
                     ORDER BY ck.created_at DESC 
                     LIMIT $limit OFFSET $offset");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $cards[] = $row;
}

// 获取统计信息
$stats = [
    'total' => $db->querySingle("SELECT COUNT(*) FROM card_keys"),
    'active' => $db->querySingle("SELECT COUNT(*) FROM card_keys WHERE status = 1"),
    'used' => $db->querySingle("SELECT COUNT(*) FROM card_keys WHERE used_count > 0"),
    'unused' => $db->querySingle("SELECT COUNT(*) FROM card_keys WHERE used_count = 0 AND status = 1")
];

$page_title = '卡密管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">卡密管理</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateCardModal">
                        <i class="fas fa-plus me-1"></i>生成卡密
                    </button>
                </div>
            </div>
            
            <!-- 消息提示 -->
            <?php if (isset($messages['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($messages['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($messages['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($messages['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- 统计卡片 -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">总卡密数</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-credit-card fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">有效卡密</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['active']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">已使用</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['used']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">未使用</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['unused']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-hourglass-half fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 卡密列表 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">卡密列表</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>卡密</th>
                                    <th>积分</th>
                                    <th>最大使用次数</th>
                                    <th>已使用次数</th>
                                    <th>状态</th>
                                    <th>创建者</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cards)): ?>
                                <tr>
                                    <td colspan="9" class="text-center">暂无卡密</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($cards as $card): ?>
                                <tr>
                                    <td><?php echo $card['id']; ?></td>
                                    <td>
                                        <code><?php echo htmlspecialchars($card['card_key']); ?></code>
                                        <button class="btn btn-sm btn-outline-secondary ms-1" onclick="copyToClipboard('<?php echo $card['card_key']; ?>')">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                    <td><span class="badge bg-success"><?php echo $card['points']; ?></span></td>
                                    <td><?php echo $card['max_uses']; ?></td>
                                    <td><?php echo $card['used_count']; ?></td>
                                    <td>
                                        <?php if ($card['status'] == 1): ?>
                                            <?php if ($card['used_count'] >= $card['max_uses']): ?>
                                                <span class="badge bg-secondary">已用完</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">有效</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-danger">已禁用</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($card['created_by_name'] ?? '未知'); ?></td>
                                    <td><?php echo formatTime($card['created_at']); ?></td>
                                    <td>
                                        <?php if ($card['status'] == 1): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="card_id" value="<?php echo $card['id']; ?>">
                                            <input type="hidden" name="new_status" value="0">
                                            <button type="submit" name="toggle_status" class="btn btn-sm btn-warning" title="禁用">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="card_id" value="<?php echo $card['id']; ?>">
                                            <input type="hidden" name="new_status" value="1">
                                            <button type="submit" name="toggle_status" class="btn btn-sm btn-success" title="启用">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除这张卡密吗？')">
                                            <input type="hidden" name="card_id" value="<?php echo $card['id']; ?>">
                                            <button type="submit" name="delete_card" class="btn btn-sm btn-danger" title="删除">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- 分页 -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- 生成卡密模态框 -->
<div class="modal fade" id="generateCardModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">生成卡密</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="points" class="form-label">积分数量</label>
                        <input type="number" class="form-control" id="points" name="points" min="1" value="100" required>
                        <div class="form-text">每张卡密包含的积分数量</div>
                    </div>
                    <div class="mb-3">
                        <label for="max_uses" class="form-label">最大使用次数</label>
                        <input type="number" class="form-control" id="max_uses" name="max_uses" min="1" value="1" required>
                        <div class="form-text">每张卡密最多可以使用的次数</div>
                    </div>
                    <div class="mb-3">
                        <label for="quantity" class="form-label">生成数量</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" min="1" max="100" value="1" required>
                        <div class="form-text">一次最多生成100张卡密</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="generate_card" class="btn btn-primary">生成卡密</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('卡密已复制到剪贴板！');
    }, function(err) {
        console.error('复制失败: ', err);
    });
}
</script>

<style>
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}
.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
</style>

<?php include 'includes/footer.php'; ?>