<?php
session_start();
require_once '../config/database.php';
require_once '../config/cloudflare.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$action = getGet('action', 'list');
$messages = getMessages();

// 确保Cloudflare账户表存在
$db->exec("CREATE TABLE IF NOT EXISTS cloudflare_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    api_key TEXT NOT NULL,
    status INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 处理添加Cloudflare账户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    $name = getPost('name');
    $email = getPost('email');
    $api_key = getPost('api_key');
    
    if ($name && $email && $api_key) {
        // 验证Cloudflare API
        try {
            $cf = new CloudflareAPI($api_key, $email);
            
            // 获取详细验证信息
            $details = $cf->getVerificationDetails();
            
            if ($details['api_token_valid'] || $details['global_key_valid']) {
                $stmt = $db->prepare("INSERT INTO cloudflare_accounts (name, email, api_key) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $name, SQLITE3_TEXT);
                $stmt->bindValue(2, $email, SQLITE3_TEXT);
                $stmt->bindValue(3, $api_key, SQLITE3_TEXT);
                
                if ($stmt->execute()) {
                    $auth_type = $details['api_token_valid'] ? 'API Token' : 'Global API Key';
                    logAction('admin', $_SESSION['admin_id'], 'add_cf_account', "添加Cloudflare账户: $name (使用 $auth_type)");
                    showSuccess("Cloudflare账户添加成功！(验证方式: $auth_type)");
                } else {
                    showError('账户添加失败！');
                }
            } else {
                $error_msg = 'Cloudflare API验证失败！';
                if ($details['error_message']) {
                    $error_msg .= ' 详细信息: ' . $details['error_message'];
                }
                $error_msg .= ' 请检查：1) 邮箱是否正确 2) API密钥是否有效 3) 网络连接是否正常';
                showError($error_msg);
            }
        } catch (Exception $e) {
            showError('API连接错误: ' . $e->getMessage() . ' 请检查网络连接或API密钥格式');
        }
    } else {
        showError('请填写完整信息！');
    }
    redirect('domains.php');
}

// 处理删除Cloudflare账户
if ($action === 'delete_account' && getGet('id')) {
    $id = (int)getGet('id');
    $account = $db->querySingle("SELECT name FROM cloudflare_accounts WHERE id = $id", true);
    
    if ($account) {
        $db->exec("DELETE FROM cloudflare_accounts WHERE id = $id");
        logAction('admin', $_SESSION['admin_id'], 'delete_cf_account', "删除Cloudflare账户: {$account['name']}");
        showSuccess('Cloudflare账户删除成功！');
    } else {
        showError('账户不存在！');
    }
    redirect('domains.php');
}

// 处理添加域名
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_domain'])) {
    $account_id = getPost('account_id');
    $domain_name = getPost('domain_name');
    $zone_id = getPost('zone_id');
    $proxied_default = getPost('proxied_default', 0);
    
    if ($account_id && $domain_name && $zone_id) {
        // 获取账户信息
        $account = $db->querySingle("SELECT * FROM cloudflare_accounts WHERE id = $account_id", true);
        if ($account) {
            $stmt = $db->prepare("INSERT INTO domains (domain_name, api_key, email, zone_id, proxied_default) VALUES (?, ?, ?, ?, ?)");
            $stmt->bindValue(1, $domain_name, SQLITE3_TEXT);
            $stmt->bindValue(2, $account['api_key'], SQLITE3_TEXT);
            $stmt->bindValue(3, $account['email'], SQLITE3_TEXT);
            $stmt->bindValue(4, $zone_id, SQLITE3_TEXT);
            $stmt->bindValue(5, $proxied_default, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                logAction('admin', $_SESSION['admin_id'], 'add_domain', "添加域名: $domain_name");
                showSuccess('域名添加成功！');
            } else {
                showError('域名添加失败，可能已存在！');
            }
        } else {
            showError('Cloudflare账户不存在！');
        }
    } else {
        showError('请填写完整信息！');
    }
    redirect('domains.php');
}

// 处理获取域名列表（第一步）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_domains'])) {
    $account_id = getPost('account_id');
    
    if ($account_id) {
        $account = $db->querySingle("SELECT * FROM cloudflare_accounts WHERE id = $account_id", true);
        if ($account) {
            try {
                $cf = new CloudflareAPI($account['api_key'], $account['email']);
                $zones = $cf->getZones();
                
                // 将域名列表存储在session中，用于选择
                $_SESSION['fetched_zones'] = $zones;
                $_SESSION['selected_account'] = $account;
                
                showSuccess("成功获取到 " . count($zones) . " 个域名，请选择要添加的域名！");
            } catch (Exception $e) {
                showError('获取域名失败: ' . $e->getMessage());
            }
        } else {
            showError('Cloudflare账户不存在！');
        }
    } else {
        showError('请选择Cloudflare账户！');
    }
    redirect('domains.php?action=select_domains');
}

// 处理批量添加选中的域名
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_selected_domains'])) {
    $selected_domains = isset($_POST['selected_domains']) ? $_POST['selected_domains'] : [];
    $proxied_default = getPost('proxied_default', 0);
    
    if (!empty($selected_domains) && isset($_SESSION['selected_account'])) {
        $account = $_SESSION['selected_account'];
        $zones = $_SESSION['fetched_zones'];
        
        $added_count = 0;
        foreach ($selected_domains as $zone_id) {
            // 找到对应的域名信息
            $zone_info = null;
            foreach ($zones as $zone) {
                if ($zone['id'] === $zone_id) {
                    $zone_info = $zone;
                    break;
                }
            }
            
            if ($zone_info) {
                // 检查域名是否已存在
                $exists = $db->querySingle("SELECT COUNT(*) FROM domains WHERE domain_name = '{$zone_info['name']}'");
                if (!$exists) {
                    $stmt = $db->prepare("INSERT INTO domains (domain_name, api_key, email, zone_id, proxied_default) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bindValue(1, $zone_info['name'], SQLITE3_TEXT);
                    $stmt->bindValue(2, $account['api_key'], SQLITE3_TEXT);
                    $stmt->bindValue(3, $account['email'], SQLITE3_TEXT);
                    $stmt->bindValue(4, $zone_info['id'], SQLITE3_TEXT);
                    $stmt->bindValue(5, $proxied_default, SQLITE3_INTEGER);
                    
                    if ($stmt->execute()) {
                        $added_count++;
                    }
                }
            }
        }
        
        // 清除session数据
        unset($_SESSION['fetched_zones']);
        unset($_SESSION['selected_account']);
        
        logAction('admin', $_SESSION['admin_id'], 'batch_add_domains', "批量添加域名，成功添加了 $added_count 个域名");
        showSuccess("成功添加 $added_count 个域名！");
    } else {
        // 提供更详细的错误信息
        if (empty($selected_domains)) {
            showError('请至少选择一个域名进行添加！');
        } elseif (!isset($_SESSION['selected_account'])) {
            showError('会话已过期，请重新获取域名列表！');
        } else {
            showError('请选择要添加的域名！');
        }
    }
    redirect('domains.php');
}

// 处理删除域名
if ($action === 'delete' && getGet('id')) {
    $id = (int)getGet('id');
    $domain = $db->querySingle("SELECT domain_name FROM domains WHERE id = $id", true);
    
    if ($domain) {
        $db->exec("DELETE FROM domains WHERE id = $id");
        logAction('admin', $_SESSION['admin_id'], 'delete_domain', "删除域名: {$domain['domain_name']}");
        showSuccess('域名删除成功！');
    } else {
        showError('域名不存在！');
    }
    redirect('domains.php');
}

// 获取Cloudflare账户列表
$cf_accounts = [];
$result = $db->query("SELECT * FROM cloudflare_accounts ORDER BY created_at DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $cf_accounts[] = $row;
}

// 获取域名列表
$domains = [];
$result = $db->query("SELECT * FROM domains ORDER BY created_at DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $domains[] = $row;
}

$page_title = '域名管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">域名管理</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                        <i class="fas fa-user-plus me-1"></i>添加CF账户
                    </button>
                    <button type="button" class="btn btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#addDomainModal">
                        <i class="fas fa-plus me-1"></i>手动添加域名
                    </button>
                    <button type="button" class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#fetchDomainsModal">
                        <i class="fas fa-download me-1"></i>获取域名列表
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
            
            <?php if ($action === 'select_domains' && isset($_SESSION['fetched_zones'])): ?>
            <!-- 域名选择界面 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">选择要添加的域名</h6>
                    <small class="text-muted">账户: <?php echo htmlspecialchars($_SESSION['selected_account']['name']); ?> (<?php echo htmlspecialchars($_SESSION['selected_account']['email']); ?>)</small>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="proxied_default" name="proxied_default" value="1" checked>
                                <label class="form-check-label" for="proxied_default">
                                    默认启用Cloudflare代理
                                </label>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                                        </th>
                                        <th>域名</th>
                                        <th>Zone ID</th>
                                        <th>状态</th>
                                        <th>已存在</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_SESSION['fetched_zones'] as $zone): ?>
                                    <?php 
                                        $exists = $db->querySingle("SELECT COUNT(*) FROM domains WHERE domain_name = '{$zone['name']}'");
                                    ?>
                                    <tr <?php echo $exists ? 'class="table-secondary"' : ''; ?>>
                                        <td>
                                            <?php if (!$exists): ?>
                                            <input type="checkbox" name="selected_domains[]" value="<?php echo htmlspecialchars($zone['id']); ?>" class="domain-checkbox">
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($zone['name']); ?></strong></td>
                                        <td><code><?php echo htmlspecialchars($zone['id']); ?></code></td>
                                        <td>
                                            <span class="badge bg-<?php echo $zone['status'] === 'active' ? 'success' : 'warning'; ?>">
                                                <?php echo $zone['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($exists): ?>
                                                <span class="badge bg-secondary">已存在</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">可添加</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between">
                            <a href="domains.php" class="btn btn-secondary">取消</a>
                            <button type="submit" name="add_selected_domains" class="btn btn-primary" onclick="return validateSelection()">添加选中的域名</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            
            <!-- Cloudflare账户列表 -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Cloudflare账户列表</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>账户名称</th>
                                    <th>邮箱</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cf_accounts as $account): ?>
                                <tr>
                                    <td><?php echo $account['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($account['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($account['email']); ?></td>
                                    <td>
                                        <?php if ($account['status']): ?>
                                            <span class="badge bg-success">正常</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">禁用</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($account['created_at']); ?></td>
                                    <td>
                                        <a href="?action=delete_account&id=<?php echo $account['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirmDelete('确定要删除账户 <?php echo htmlspecialchars($account['name']); ?> 吗？')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 域名列表 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">域名列表</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="domainsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>域名</th>
                                    <th>邮箱</th>
                                    <th>Zone ID</th>
                                    <th>默认代理</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($domains as $domain): ?>
                                <tr>
                                    <td><?php echo $domain['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($domain['domain_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($domain['email']); ?></td>
                                    <td><code><?php echo htmlspecialchars($domain['zone_id']); ?></code></td>
                                    <td>
                                        <?php if ($domain['proxied_default']): ?>
                                            <span class="badge bg-success">是</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">否</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($domain['status']): ?>
                                            <span class="badge bg-success">正常</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">禁用</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($domain['created_at']); ?></td>
                                    <td>
                                        <a href="domain_dns.php?domain_id=<?php echo $domain['id']; ?>" 
                                           class="btn btn-sm btn-primary me-1" 
                                           title="管理DNS记录">
                                            <i class="fas fa-cog"></i>
                                        </a>
                                        <a href="?action=delete&id=<?php echo $domain['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirmDelete('确定要删除域名 <?php echo htmlspecialchars($domain['domain_name']); ?> 吗？')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- 添加Cloudflare账户模态框 -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">添加Cloudflare账户</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="account_name" class="form-label">账户名称</label>
                        <input type="text" class="form-control" id="account_name" name="name" placeholder="例如：主账户、备用账户" required>
                        <div class="form-text">用于区分不同的Cloudflare账户</div>
                    </div>
                    <div class="mb-3">
                        <label for="account_email" class="form-label">Cloudflare邮箱</label>
                        <input type="email" class="form-control" id="account_email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="account_api_key" class="form-label">API密钥</label>
                        <input type="text" class="form-control" id="account_api_key" name="api_key" required>
                        <div class="form-text">Global API Key 或 Zone API Token</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_account" class="btn btn-primary">添加账户</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 手动添加域名模态框 -->
<div class="modal fade" id="addDomainModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">手动添加域名</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="account_id" class="form-label">选择Cloudflare账户</label>
                        <select class="form-control" id="account_id" name="account_id" required>
                            <option value="">请选择账户</option>
                            <?php foreach ($cf_accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['name']); ?> (<?php echo htmlspecialchars($account['email']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="domain_name" class="form-label">域名</label>
                        <input type="text" class="form-control" id="domain_name" name="domain_name" placeholder="example.com" required>
                    </div>
                    <div class="mb-3">
                        <label for="zone_id" class="form-label">Zone ID</label>
                        <input type="text" class="form-control" id="zone_id" name="zone_id" required>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="domain_proxied_default" name="proxied_default" value="1" checked>
                            <label class="form-check-label" for="domain_proxied_default">
                                默认启用Cloudflare代理
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_domain" class="btn btn-primary">添加域名</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 获取域名列表模态框 -->
<div class="modal fade" id="fetchDomainsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">获取域名列表</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        系统将从选择的Cloudflare账户获取所有域名，然后您可以选择要添加的域名。
                    </div>
                    <div class="mb-3">
                        <label for="fetch_account_id" class="form-label">选择Cloudflare账户</label>
                        <select class="form-control" id="fetch_account_id" name="account_id" required>
                            <option value="">请选择账户</option>
                            <?php foreach ($cf_accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['name']); ?> (<?php echo htmlspecialchars($account['email']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="fetch_domains" class="btn btn-success">获取域名列表</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(message) {
    return confirm(message);
}

function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.domain-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
}

function validateSelection() {
    const checkboxes = document.querySelectorAll('.domain-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('请至少选择一个域名进行添加！');
        return false;
    }
    return true;
}
</script>

<?php include 'includes/footer.php'; ?>