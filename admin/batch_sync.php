<?php
session_start();
require_once '../config/database.php';
require_once '../config/cloudflare.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理批量同步
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_sync'])) {
    $selected_domains = $_POST['selected_domains'] ?? [];
    
    if (empty($selected_domains)) {
        showError('请至少选择一个域名进行同步！');
    } else {
        $sync_results = [];
        $total_synced = 0;
        $total_errors = 0;
        
        foreach ($selected_domains as $domain_id) {
            $domain_id = intval($domain_id);
            
            // 获取域名信息
            $stmt = $db->prepare("SELECT * FROM domains WHERE id = ? AND status = 1");
            $stmt->bindValue(1, $domain_id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $domain = $result->fetchArray(SQLITE3_ASSOC);
            
            if (!$domain) {
                $sync_results[] = [
                    'domain' => "域名ID: $domain_id",
                    'status' => 'error',
                    'message' => '域名不存在或已禁用',
                    'count' => 0
                ];
                $total_errors++;
                continue;
            }
            
            try {
                $cf = new CloudflareAPI($domain['api_key'], $domain['email']);
                
                // 从Cloudflare获取所有DNS记录
                $cloudflare_records = $cf->getDNSRecords($domain['zone_id']);
                $synced_count = 0;
                
                foreach ($cloudflare_records as $cf_record) {
                    // 检查本地是否已存在该记录
                    $stmt = $db->prepare("SELECT COUNT(*) FROM dns_records WHERE cloudflare_id = ?");
                    $stmt->bindValue(1, $cf_record['id'], SQLITE3_TEXT);
                    $result = $stmt->execute();
                    $exists = $result->fetchArray(SQLITE3_NUM)[0] > 0;
                    
                    if (!$exists) {
                        // 提取子域名
                        $subdomain = str_replace('.' . $domain['domain_name'], '', $cf_record['name']);
                        if ($subdomain === $domain['domain_name']) {
                            $subdomain = '@';
                        }
                        
                        // 插入新记录（标记为系统记录，user_id设为NULL避免外键约束）
                        $stmt = $db->prepare("INSERT INTO dns_records (user_id, domain_id, subdomain, type, content, proxied, cloudflare_id, status, created_at, is_system) VALUES (?, ?, ?, ?, ?, ?, ?, 1, datetime('now'), 1)");
                        $stmt->bindValue(1, null, SQLITE3_NULL); // NULL表示系统记录
                        $stmt->bindValue(2, $domain['id'], SQLITE3_INTEGER);
                        $stmt->bindValue(3, $subdomain, SQLITE3_TEXT);
                        $stmt->bindValue(4, $cf_record['type'], SQLITE3_TEXT);
                        $stmt->bindValue(5, $cf_record['content'], SQLITE3_TEXT);
                        $stmt->bindValue(6, $cf_record['proxied'] ? 1 : 0, SQLITE3_INTEGER);
                        $stmt->bindValue(7, $cf_record['id'], SQLITE3_TEXT);
                        
                        if ($stmt->execute()) {
                            $synced_count++;
                        }
                    }
                }
                
                $sync_results[] = [
                    'domain' => $domain['domain_name'],
                    'status' => 'success',
                    'message' => "同步成功",
                    'count' => $synced_count
                ];
                $total_synced += $synced_count;
                
            } catch (Exception $e) {
                $sync_results[] = [
                    'domain' => $domain['domain_name'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'count' => 0
                ];
                $total_errors++;
            }
        }
        
        // 记录操作日志
        $selected_count = count($selected_domains);
        logAction('admin', $_SESSION['admin_id'], 'batch_sync_dns', "批量同步 $selected_count 个域名的DNS记录，共同步 $total_synced 条记录，$total_errors 个错误");
        
        if ($total_errors === 0) {
            showSuccess("批量同步完成！共同步 $total_synced 条DNS记录。");
        } else {
            showError("批量同步完成，但有 $total_errors 个域名同步失败。共同步 $total_synced 条DNS记录。");
        }
        
        // 存储同步结果到session，用于显示详细信息
        $_SESSION['sync_results'] = $sync_results;
    }
    
    redirect('batch_sync.php');
}

// 获取同步结果
$sync_results = $_SESSION['sync_results'] ?? [];
unset($_SESSION['sync_results']);

// 获取所有可用域名
$domains = [];
$result = $db->query("SELECT * FROM domains WHERE status = 1 ORDER BY domain_name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // 获取每个域名的记录数量
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ? AND status = 1");
    $count_stmt->bindValue(1, $row['id'], SQLITE3_INTEGER);
    $count_result = $count_stmt->execute();
    $row['record_count'] = $count_result->fetchArray(SQLITE3_NUM)[0];
    
    $domains[] = $row;
}

$page_title = '批量同步DNS记录';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-sync-alt me-2"></i>批量同步DNS记录
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-success" onclick="selectAllDomains()">
                        <i class="fas fa-check-double me-1"></i>全选
                    </button>
                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="clearSelection()">
                        <i class="fas fa-times me-1"></i>清空
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
            
            <!-- 功能说明 -->
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle me-2"></i>批量同步说明</h6>
                <ul class="mb-0">
                    <li><strong>系统记录同步：</strong>从Cloudflare获取选中域名的所有DNS记录并标记为系统记录</li>
                    <li><strong>避免重复：</strong>只会同步本地数据库中不存在的记录，避免重复导入</li>
                    <li><strong>用户隔离：</strong>同步的记录标记为系统记录(user_id=NULL)，用户界面不会显示</li>
                    <li><strong>不消耗积分：</strong>系统记录不会消耗用户积分，也不会影响用户的记录管理</li>
                    <li><strong>管理员可见：</strong>只有管理员可以在后台查看和管理这些系统记录</li>
                    <li><strong>建议时机：</strong>建议在系统维护时间或新增域名后进行批量同步操作</li>
                </ul>
            </div>
            
            <?php if (!empty($sync_results)): ?>
            <!-- 同步结果 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-bar me-2"></i>同步结果详情
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>域名</th>
                                    <th>状态</th>
                                    <th>同步记录数</th>
                                    <th>详细信息</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sync_results as $result): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($result['domain']); ?></td>
                                    <td>
                                        <?php if ($result['status'] === 'success'): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check me-1"></i>成功
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-times me-1"></i>失败
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $result['count']; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['message']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- 域名选择 -->
            <?php if (!empty($domains)): ?>
            <form method="POST" id="batchSyncForm">
                <div class="card">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-2"></i>选择要同步的域名
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($domains as $domain): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card domain-card h-100">
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input class="form-check-input domain-checkbox" 
                                                   type="checkbox" 
                                                   name="selected_domains[]" 
                                                   value="<?php echo $domain['id']; ?>" 
                                                   id="domain_<?php echo $domain['id']; ?>">
                                            <label class="form-check-label w-100" for="domain_<?php echo $domain['id']; ?>">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($domain['domain_name']); ?></h6>
                                                        <small class="text-muted">
                                                            <i class="fas fa-list me-1"></i>
                                                            当前记录: <?php echo $domain['record_count']; ?> 条
                                                        </small>
                                                    </div>
                                                    <span class="badge bg-<?php echo $domain['status'] ? 'success' : 'secondary'; ?>">
                                                        <?php echo $domain['status'] ? '启用' : '禁用'; ?>
                                                    </span>
                                                </div>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        <i class="fas fa-envelope me-1"></i>
                                                        <?php echo htmlspecialchars($domain['email']); ?>
                                                    </small>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-4 text-center">
                            <button type="submit" name="batch_sync" class="btn btn-primary btn-lg" onclick="return confirmSync()">
                                <i class="fas fa-sync-alt me-2"></i>开始批量同步
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                暂无可用域名，请先添加域名配置。
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<style>
.domain-card {
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.domain-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.domain-checkbox:checked + label .domain-card,
.form-check-input:checked ~ .domain-card {
    border-color: #0d6efd;
    background-color: rgba(13, 110, 253, 0.05);
}

.form-check-label {
    cursor: pointer;
}

.card-body .form-check {
    margin-bottom: 0;
}

.badge {
    font-size: 0.75rem;
}
</style>

<script>
function selectAllDomains() {
    const checkboxes = document.querySelectorAll('.domain-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
        updateCardStyle(checkbox);
    });
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.domain-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
        updateCardStyle(checkbox);
    });
}

function updateCardStyle(checkbox) {
    const card = checkbox.closest('.domain-card');
    if (checkbox.checked) {
        card.style.borderColor = '#0d6efd';
        card.style.backgroundColor = 'rgba(13, 110, 253, 0.05)';
    } else {
        card.style.borderColor = 'transparent';
        card.style.backgroundColor = '';
    }
}

function confirmSync() {
    const checkedBoxes = document.querySelectorAll('.domain-checkbox:checked');
    
    if (checkedBoxes.length === 0) {
        alert('请至少选择一个域名进行同步！');
        return false;
    }
    
    const domainNames = Array.from(checkedBoxes).map(cb => {
        return cb.closest('.card-body').querySelector('h6').textContent.trim();
    });
    
    const message = `确定要同步以下 ${checkedBoxes.length} 个域名的DNS记录吗？\n\n${domainNames.join('\n')}\n\n注意：此操作可能需要较长时间，请耐心等待。`;
    
    return confirm(message);
}

// 为所有复选框添加事件监听器
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.domain-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateCardStyle(this);
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>