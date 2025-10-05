<?php
session_start();
require_once '../config/database.php';
require_once '../config/cloudflare.php';
require_once '../config/dns_manager.php';
require_once '../includes/functions.php';
require_once '../includes/user_groups.php';

checkUserLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理从主页传递的参数
$auto_prefix = isset($_GET['prefix']) ? trim($_GET['prefix']) : '';
$auto_domain_id = isset($_GET['domain_id']) ? intval($_GET['domain_id']) : 0;
$auto_fill_mode = !empty($auto_prefix) && $auto_domain_id > 0;

// 更新用户积分到session
$user_points = $db->querySingle("SELECT points FROM users WHERE id = {$_SESSION['user_id']}");
$_SESSION['user_points'] = $user_points;

// 获取用户组信息
$user_group = getUserGroup($_SESSION['user_id']);
$required_points = getRequiredPoints($_SESSION['user_id']);
$current_record_count = getUserCurrentRecordCount($_SESSION['user_id']);

// 获取用户可访问的域名（根据用户组权限）
$domains = getUserAccessibleDomains($_SESSION['user_id']);

// 处理域名选择
$selected_domain_id = $_SESSION['selected_domain_id'] ?? ($domains[0]['id'] ?? null);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_domain'])) {
    $selected_domain_id = (int)getPost('domain_id');
    $_SESSION['selected_domain_id'] = $selected_domain_id;
    redirect('dashboard.php');
}

// 如果通过GET参数切换域名（但不是自动填充模式）
if (isset($_GET['domain_id']) && !$auto_fill_mode) {
    $selected_domain_id = (int)$_GET['domain_id'];
    $_SESSION['selected_domain_id'] = $selected_domain_id;
    redirect('dashboard.php');
}

// 如果是自动填充模式，直接设置选中的域名
if ($auto_fill_mode) {
    $selected_domain_id = $auto_domain_id;
    $_SESSION['selected_domain_id'] = $selected_domain_id;
}

// 获取当前域名配置
$current_domain = null;
foreach ($domains as $domain) {
    if ($domain['id'] == $selected_domain_id) {
        $current_domain = $domain;
        break;
    }
}

// 处理添加DNS记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    $subdomain = getPost('subdomain');
    $type = getPost('type');
    $content = getPost('content');
    $proxied = getPost('proxied', 0);
    $remark = getPost('remark', '');
    
    if (!$current_domain) {
        showError('请先选择一个域名！');
    } elseif (!$subdomain || !$type || !$content) {
        showError('请填写完整信息！');
    } elseif (!isDNSTypeEnabled($type)) {
        showError('该DNS记录类型未启用！');
    } elseif (!checkUserDomainPermission($_SESSION['user_id'], $current_domain['id'])) {
        showError('您的用户组无权访问此域名！请联系管理员升级用户组。');
    } elseif (!checkUserRecordLimit($_SESSION['user_id'])) {
        $max = $user_group['max_records'];
        showError("您已达到用户组的最大记录数限制（{$max}条）！请联系管理员升级用户组。");
    } elseif ($user_points < $required_points) {
        showError("积分不足！需要 {$required_points} 积分，您当前有 {$user_points} 积分。");
    } elseif (isSubdomainBlocked($subdomain)) {
        showError("前缀 \"$subdomain\" 已被系统拦截，无法创建此子域名！");
    } else {
        try {
            // 使用统一的DNS管理器
            $dns_manager = new DNSManager($current_domain);
            $full_name = $subdomain === '@' ? $current_domain['domain_name'] : $subdomain . '.' . $current_domain['domain_name'];
            
            // 检查是否已存在冲突的DNS记录
            $existing_records = $dns_manager->getDNSRecords($current_domain['zone_id']);
            $conflict_found = false;
            $existing_record = null;
            
            foreach ($existing_records as $record) {
                if (strtolower($record['name']) === strtolower($full_name)) {
                    $record_type = strtoupper($record['type']);
                    $target_type = strtoupper($type);
                    
                    // A、AAAA、CNAME记录之间会冲突
                    $conflicting_types = ['A', 'AAAA', 'CNAME'];
                    
                    if (in_array($record_type, $conflicting_types) && in_array($target_type, $conflicting_types)) {
                        $conflict_found = true;
                        $existing_record = $record;
                        break;
                    }
                    
                    // 完全相同的记录类型和内容
                    if ($record_type === $target_type) {
                        if ($record['content'] === $content) {
                            throw new Exception("相同的DNS记录已存在！记录名称: {$full_name}, 类型: {$type}, 内容: {$content}");
                        } else {
                            $conflict_found = true;
                            $existing_record = $record;
                            break;
                        }
                    }
                }
            }
            
            if ($conflict_found) {
                $conflict_msg = "DNS记录冲突：域名 '{$full_name}' 已存在 {$existing_record['type']} 记录";
                $conflict_msg .= "（内容: {$existing_record['content']}）";
                $conflict_msg .= "。无法添加 {$type} 记录到相同名称。";
                $conflict_msg .= "建议：1) 使用不同的子域名前缀；2) 删除现有记录后重新添加；3) 使用编辑功能修改现有记录。";
                throw new Exception($conflict_msg);
            }
            
            // 某些记录类型不能启用代理
            $non_proxiable_types = ['NS', 'MX', 'TXT', 'SRV', 'CAA'];
            $final_proxied = in_array(strtoupper($type), $non_proxiable_types) ? false : (bool)$proxied;
            
            // 对于彩虹DNS，代理功能不可用
            if (isset($current_domain['provider_type']) && $current_domain['provider_type'] === 'rainbow') {
                $final_proxied = false;
            }
            
            // 对于彩虹DNS，需要使用子域名而不是完整域名
            if (isset($current_domain['provider_type']) && $current_domain['provider_type'] === 'rainbow') {
                $record_name = $subdomain; // 彩虹DNS使用子域名
            } else {
                $record_name = $full_name; // Cloudflare使用完整域名
            }
            
            $result = $dns_manager->createDNSRecord($current_domain['zone_id'], $type, $record_name, $content, [
                'proxied' => $final_proxied,
                'remark' => $remark
            ]);
            
            // 保存到数据库
            $stmt = $db->prepare("INSERT INTO dns_records (user_id, domain_id, subdomain, type, content, proxied, cloudflare_id, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $current_domain['id'], SQLITE3_INTEGER);
            $stmt->bindValue(3, $subdomain, SQLITE3_TEXT);
            $stmt->bindValue(4, $type, SQLITE3_TEXT);
            $stmt->bindValue(5, $content, SQLITE3_TEXT);
            $stmt->bindValue(6, $final_proxied ? 1 : 0, SQLITE3_INTEGER);
            // 对于彩虹DNS，使用RecordId；对于Cloudflare，使用id
            $record_id = isset($result['RecordId']) ? $result['RecordId'] : $result['id'];
            $stmt->bindValue(7, $record_id, SQLITE3_TEXT);
            $stmt->bindValue(8, $remark, SQLITE3_TEXT);
            
            if ($stmt->execute()) {
                // 扣除积分（根据用户组策略）
                $points_cost = $required_points;
                if ($points_cost > 0) {
                    $db->exec("UPDATE users SET points = points - $points_cost WHERE id = {$_SESSION['user_id']}");
                    $_SESSION['user_points'] -= $points_cost;
                }
                
                logAction('user', $_SESSION['user_id'], 'add_dns_record', "添加DNS记录: $full_name ($type)");
                showSuccess('DNS记录添加成功！');
            } else {
                showError('记录保存失败！');
            }
        } catch (Exception $e) {
            showError('添加失败: ' . $e->getMessage());
        }
    }
    redirect('dashboard.php');
}

// 处理修改DNS记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_record'])) {
    $record_id = (int)getPost('record_id');
    $subdomain = trim(getPost('subdomain'));
    $type = getPost('type');
    $content = trim(getPost('content'));
    $remark = trim(getPost('remark'));
    $proxied = isset($_POST['proxied']) ? 1 : 0;
    
    $record = $db->querySingle("SELECT * FROM dns_records WHERE id = $record_id AND user_id = {$_SESSION['user_id']}", true);
    if ($record && $current_domain) {
        // 检查新的前缀是否被拦截（如果前缀发生了变化）
        if ($subdomain !== $record['subdomain'] && isSubdomainBlocked($subdomain)) {
            showError("前缀 \"$subdomain\" 已被系统拦截，无法修改为此子域名！");
            redirect('dashboard.php');
        }
        
        try {
            // 使用统一的DNS管理器
            $dns_manager = new DNSManager($current_domain);
            $full_name = $subdomain === '@' ? $current_domain['domain_name'] : $subdomain . '.' . $current_domain['domain_name'];
            
            // 对于彩虹DNS，代理功能不可用
            if (isset($current_domain['provider_type']) && $current_domain['provider_type'] === 'rainbow') {
                $proxied = 0;
            }
            
            // 对于彩虹DNS，需要使用子域名而不是完整域名
            if (isset($current_domain['provider_type']) && $current_domain['provider_type'] === 'rainbow') {
                $record_name = $subdomain; // 彩虹DNS使用子域名
            } else {
                $record_name = $full_name; // Cloudflare使用完整域名
            }
            
            // 更新DNS记录
            $dns_manager->updateDNSRecord($current_domain['zone_id'], $record['cloudflare_id'], $type, $record_name, $content, [
                'proxied' => (bool)$proxied,
                'remark' => $remark
            ]);
            
            // 更新本地数据库
            $stmt = $db->prepare("UPDATE dns_records SET subdomain = ?, type = ?, content = ?, remark = ?, proxied = ? WHERE id = ?");
            $stmt->bindValue(1, $subdomain, SQLITE3_TEXT);
            $stmt->bindValue(2, $type, SQLITE3_TEXT);
            $stmt->bindValue(3, $content, SQLITE3_TEXT);
            $stmt->bindValue(4, $remark, SQLITE3_TEXT);
            $stmt->bindValue(5, $proxied, SQLITE3_INTEGER);
            $stmt->bindValue(6, $record_id, SQLITE3_INTEGER);
            $stmt->execute();
            
            logAction('user', $_SESSION['user_id'], 'edit_dns_record', "修改DNS记录: {$subdomain}.{$current_domain['domain_name']}");
            showSuccess('DNS记录修改成功！');
        } catch (Exception $e) {
            showError('修改失败: ' . $e->getMessage());
        }
    } else {
        showError('记录不存在或无权限修改！');
    }
    redirect('dashboard.php');
}

// 处理删除DNS记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    $record_id = (int)getPost('record_id');
    
    $record = $db->querySingle("SELECT * FROM dns_records WHERE id = $record_id AND user_id = {$_SESSION['user_id']}", true);
    if ($record && $current_domain) {
        try {
            // 使用统一的DNS管理器
            $dns_manager = new DNSManager($current_domain);
            $dns_manager->deleteDNSRecord($current_domain['zone_id'], $record['cloudflare_id']);
            
            $db->exec("DELETE FROM dns_records WHERE id = $record_id");
            
            logAction('user', $_SESSION['user_id'], 'delete_dns_record', "删除DNS记录: {$record['subdomain']}.{$current_domain['domain_name']}");
            showSuccess('DNS记录删除成功！');
        } catch (Exception $e) {
            showError('删除失败: ' . $e->getMessage());
        }
    } else {
        showError('记录不存在或无权限删除！');
    }
    redirect('dashboard.php');
}

// 获取用户的DNS记录
$dns_records = [];
if ($current_domain) {
    $stmt = $db->prepare("SELECT * FROM dns_records WHERE user_id = ? AND domain_id = ? AND (is_system = 0 OR is_system IS NULL) ORDER BY created_at DESC");
    $stmt->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(2, $current_domain['id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $dns_records[] = $row;
    }
}

// 获取启用的DNS记录类型
$enabled_dns_types = getEnabledDNSTypes();

// 获取用户需要显示的公告
$user_announcements = getUserAnnouncements($_SESSION['user_id']);

// 获取被拦截的前缀列表（用于前端验证）
$blocked_prefixes = getBlockedPrefixes();

$page_title = '用户面板';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 1px solid rgba(255, 255, 255, 0.2);">
                <div>
                    <h1 class="h2">DNS管理面板</h1>
                    <?php if ($auto_fill_mode): ?>
                    <p class="text-muted mb-0">
                        <i class="fas fa-magic me-1"></i>
                        准备添加解析：<strong><?php echo htmlspecialchars($auto_prefix . '.' . $current_domain['domain_name']); ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <?php if ($user_group): 
                        $badge_class = 'bg-secondary';
                        if ($user_group['group_name'] === 'vip') $badge_class = 'bg-info';
                        if ($user_group['group_name'] === 'svip') $badge_class = 'bg-warning text-dark';
                    ?>
                    <span class="badge <?php echo $badge_class; ?> fs-6 me-2" title="<?php echo htmlspecialchars($user_group['description']); ?>">
                        <i class="fas fa-user-tag me-1"></i><?php echo htmlspecialchars($user_group['display_name']); ?>
                    </span>
                    <?php endif; ?>
                    <span class="badge bg-primary fs-6 me-2">
                        <i class="fas fa-coins me-1"></i>积分: <?php echo $_SESSION['user_points']; ?>
                    </span>
                    <span class="badge bg-success fs-6 me-2">
                        <i class="fas fa-list me-1"></i>记录: <?php echo $current_record_count; ?><?php if ($user_group && $user_group['max_records'] != -1): ?>/<?php echo $user_group['max_records']; ?><?php endif; ?>
                    </span>
                    <?php if ($current_domain): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecordModal" 
                            <?php if ($auto_fill_mode): ?>onclick="autoFillForm()"<?php endif; ?>>
                        <i class="fas fa-plus me-1"></i>添加记录
                    </button>
                    <?php endif; ?>
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
            
            <!-- 域名选择 -->
            <?php if (!empty($domains)): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <form method="POST" class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label for="domain_id" class="form-label">选择域名</label>
                            <select class="form-select" id="domain_id" name="domain_id" onchange="switchDomain(this.value)">
                                <?php foreach ($domains as $domain): ?>
                                <option value="<?php echo $domain['id']; ?>" <?php echo $domain['id'] == $selected_domain_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($domain['domain_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="select_domain" class="btn btn-outline-primary">
                                <i class="fas fa-sync me-1"></i>手动切换
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- DNS记录列表 -->
            <?php if ($current_domain): ?>
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <?php echo htmlspecialchars($current_domain['domain_name']); ?> - DNS记录
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($dns_records)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>子域名</th>
                                    <th>类型</th>
                                    <th>内容</th>
                                    <th>备注</th>
                                    <th>代理状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dns_records as $record): ?>
                                <tr>
                                    <td>
                                        <code><?php echo htmlspecialchars($record['subdomain']); ?></code>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $record['type']; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['content']); ?></td>
                                    <td>
                                        <?php if (!empty($record['remark'])): ?>
                                            <span class="text-muted" title="<?php echo htmlspecialchars($record['remark']); ?>">
                                                <i class="fas fa-comment-alt me-1"></i>
                                                <?php echo htmlspecialchars(mb_strlen($record['remark']) > 20 ? mb_substr($record['remark'], 0, 20) . '...' : $record['remark']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['proxied']): ?>
                                            <span class="badge bg-warning">已代理</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">仅DNS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($record['created_at']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary me-1" 
                                                onclick="editRecord(<?php echo htmlspecialchars(json_encode($record)); ?>)"
                                                title="修改记录">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" name="delete_record" class="btn btn-sm btn-danger" 
                                                    onclick="return confirmDelete('确定要删除这条DNS记录吗？')"
                                                    title="删除记录">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">暂无DNS记录</p>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecordModal"
                                <?php if ($auto_fill_mode): ?>onclick="autoFillForm()"<?php endif; ?>>
                            <i class="fas fa-plus me-1"></i>添加第一条记录
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                系统暂无可用域名，请联系管理员添加域名配置。
            </div>
            <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- 添加DNS记录模态框 -->
<?php if ($current_domain): ?>
<div class="modal fade" id="addRecordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">添加DNS记录</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <?php if ($user_group): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        您的用户组：<strong><?php echo htmlspecialchars($user_group['display_name']); ?></strong> | 
                        <?php if ($required_points > 0): ?>
                            添加一条DNS记录需要消耗 <strong><?php echo $required_points; ?></strong> 积分
                        <?php else: ?>
                            <strong class="text-success">免费添加</strong> DNS记录
                        <?php endif; ?>
                        <?php if ($user_group['max_records'] != -1): ?>
                            | 已用 <strong><?php echo $current_record_count; ?>/<?php echo $user_group['max_records']; ?></strong> 条
                        <?php else: ?>
                            | 已用 <strong><?php echo $current_record_count; ?></strong> 条（无限制）
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="subdomain" class="form-label">子域名</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="subdomain" name="subdomain" 
                                   placeholder="www" 
                                   value="<?php echo $auto_fill_mode ? htmlspecialchars($auto_prefix) : ''; ?>" 
                                   required oninput="checkAndUpdateConflict()">
                            <span class="input-group-text">.<?php echo htmlspecialchars($current_domain['domain_name']); ?></span>
                        </div>
                        <div class="form-text">输入 @ 表示根域名</div>
                        <div id="conflict-warning-add" style="display: none;"></div>
                        <?php if ($auto_fill_mode): ?>
                        <div class="alert alert-success mt-2">
                            <i class="fas fa-magic me-2"></i>已自动填入前缀：<strong><?php echo htmlspecialchars($auto_prefix); ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="type" class="form-label">记录类型</label>
                        <select class="form-select" id="type" name="type" required onchange="updateContentPlaceholder(); checkAndUpdateConflict();">
                            <?php if (empty($enabled_dns_types)): ?>
                                <option value="">暂无可用的记录类型</option>
                            <?php else: ?>
                                <?php foreach ($enabled_dns_types as $dns_type): ?>
                                    <option value="<?php echo $dns_type['type_name']; ?>">
                                        <?php echo htmlspecialchars($dns_type['type_name']); ?> - <?php echo htmlspecialchars($dns_type['description']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if (empty($enabled_dns_types)): ?>
                            <div class="form-text text-danger">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                管理员暂未启用任何DNS记录类型，请联系管理员。
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="content" class="form-label">记录值</label>
                        <input type="text" class="form-control" id="content" name="content" placeholder="192.168.1.1" required oninput="checkAndUpdateConflict()">
                        <div class="form-text" id="content-help">
                            请输入对应记录类型的值
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="remark" class="form-label">备注 <span class="text-muted">(可选)</span></label>
                        <input type="text" class="form-control" id="remark" name="remark" placeholder="例如：网站主页、API接口、邮件服务器等" maxlength="100">
                        <div class="form-text">添加备注可以帮助您区分不同解析记录的用途</div>
                    </div>
                    <?php if (!isset($current_domain['provider_type']) || $current_domain['provider_type'] !== 'rainbow'): ?>
                    <div class="mb-3" id="proxied-section">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="proxied" name="proxied" value="1" 
                                   <?php echo $current_domain['proxied_default'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="proxied">
                                启用Cloudflare代理
                            </label>
                        </div>
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            仅A、AAAA、CNAME记录支持代理功能。NS、MX、TXT、SRV等记录类型会自动禁用代理。
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        彩虹DNS不支持代理功能，所有记录将直接解析。
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_record" class="btn btn-primary" <?php echo empty($enabled_dns_types) ? 'disabled' : ''; ?>>
                        添加记录
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 修改DNS记录模态框 -->
<?php if ($current_domain): ?>
<div class="modal fade" id="editRecordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">修改DNS记录</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="edit_record_id" name="record_id">
                    <div class="mb-3">
                        <label for="edit_subdomain" class="form-label">子域名</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="edit_subdomain" name="subdomain" placeholder="www" required>
                            <span class="input-group-text">.<?php echo htmlspecialchars($current_domain['domain_name']); ?></span>
                        </div>
                        <div class="form-text">输入 @ 表示根域名</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">记录类型</label>
                        <select class="form-select" id="edit_type" name="type" required onchange="updateEditContentPlaceholder()">
                            <?php if (empty($enabled_dns_types)): ?>
                                <option value="">暂无可用的记录类型</option>
                            <?php else: ?>
                                <?php foreach ($enabled_dns_types as $dns_type): ?>
                                    <option value="<?php echo $dns_type['type_name']; ?>">
                                        <?php echo htmlspecialchars($dns_type['type_name']); ?> - <?php echo htmlspecialchars($dns_type['description']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_content" class="form-label">记录值</label>
                        <input type="text" class="form-control" id="edit_content" name="content" placeholder="192.168.1.1" required>
                        <div class="form-text" id="edit-content-help">
                            请输入对应记录类型的值
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_remark" class="form-label">备注 <span class="text-muted">(可选)</span></label>
                        <input type="text" class="form-control" id="edit_remark" name="remark" placeholder="例如：网站主页、API接口、邮件服务器等" maxlength="100">
                        <div class="form-text">添加备注可以帮助您区分不同解析记录的用途</div>
                    </div>
                    <?php if (!isset($current_domain['provider_type']) || $current_domain['provider_type'] !== 'rainbow'): ?>
                    <div class="mb-3" id="edit-proxied-section">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_proxied" name="proxied" value="1">
                            <label class="form-check-label" for="edit_proxied">
                                启用Cloudflare代理
                            </label>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        彩虹DNS不支持代理功能，所有记录将直接解析。
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="edit_record" class="btn btn-primary">
                        保存修改
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// 自动填充参数
const autoPrefix = '<?php echo addslashes($auto_prefix); ?>';
const autoFillMode = <?php echo $auto_fill_mode ? 'true' : 'false'; ?>;

function switchDomain(domainId) {
    if (domainId) {
        window.location.href = 'dashboard.php?domain_id=' + domainId;
    }
}

function autoFillForm() {
    if (autoFillMode && autoPrefix) {
        // 延迟执行，确保模态框已完全显示
        setTimeout(function() {
            document.getElementById('subdomain').value = autoPrefix;
            document.getElementById('subdomain').focus();
            
            // 添加高亮效果
            const subdomainField = document.getElementById('subdomain');
            subdomainField.style.backgroundColor = '#d4edda';
            subdomainField.style.borderColor = '#28a745';
            
            // 3秒后移除高亮
            setTimeout(function() {
                subdomainField.style.backgroundColor = '';
                subdomainField.style.borderColor = '';
            }, 3000);
            
            // 显示提示信息
            showAutoFillNotice();
        }, 300);
    }
}

function showAutoFillNotice() {
    // 创建提示元素
    const notice = document.createElement('div');
    notice.className = 'alert alert-success alert-dismissible fade show mt-3';
    notice.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        <strong>自动填充完成！</strong> 前缀已填入，请选择记录类型和记录值。
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // 插入到表单顶部
    const modalBody = document.querySelector('#addRecordModal .modal-body');
    const firstChild = modalBody.firstElementChild.nextElementSibling;
    modalBody.insertBefore(notice, firstChild);
    
    // 5秒后自动移除
    setTimeout(function() {
        if (notice.parentNode) {
            notice.remove();
        }
    }, 5000);
}

// 页面加载时检查是否需要自动打开模态框
document.addEventListener('DOMContentLoaded', function() {
    if (autoFillMode) {
        // 自动打开添加记录模态框
        setTimeout(function() {
            const addModal = new bootstrap.Modal(document.getElementById('addRecordModal'));
            addModal.show();
            autoFillForm();
        }, 500);
    }
});

function updateContentPlaceholder() {
    const typeSelect = document.getElementById('type');
    const contentInput = document.getElementById('content');
    const contentHelp = document.getElementById('content-help');
    const proxiedSection = document.getElementById('proxied-section');
    
    const type = typeSelect.value;
    const isRainbowDNS = <?php echo (isset($current_domain['provider_type']) && $current_domain['provider_type'] === 'rainbow') ? 'true' : 'false'; ?>;
    
    // 定义记录类型的配置
    const typeConfigs = {
        'A': {
            placeholder: '192.168.1.1',
            help: '请输入IPv4地址',
            showProxied: true
        },
        'AAAA': {
            placeholder: '2001:db8::1',
            help: '请输入IPv6地址',
            showProxied: true
        },
        'CNAME': {
            placeholder: 'example.com',
            help: '请输入目标域名',
            showProxied: true
        },
        'MX': {
            placeholder: '10 mail.example.com',
            help: '请输入优先级和邮件服务器地址',
            showProxied: false
        },
        'NS': {
            placeholder: 'ns1.example.com',
            help: '请输入域名服务器地址',
            showProxied: false
        },
        'TXT': {
            placeholder: 'v=spf1 include:_spf.google.com ~all',
            help: '请输入文本内容',
            showProxied: false
        },
        'SRV': {
            placeholder: '10 5 443 target.example.com',
            help: '请输入优先级 权重 端口 目标',
            showProxied: false
        },
        'PTR': {
            placeholder: 'example.com',
            help: '请输入反向解析的域名',
            showProxied: false
        },
        'CAA': {
            placeholder: '0 issue "letsencrypt.org"',
            help: '请输入CAA记录值',
            showProxied: false
        }
    };
    
    const config = typeConfigs[type];
    if (config) {
        contentInput.placeholder = config.placeholder;
        contentHelp.textContent = config.help;
        // 对于彩虹DNS，隐藏代理选项
        if (proxiedSection) {
            proxiedSection.style.display = (config.showProxied && !isRainbowDNS) ? 'block' : 'none';
        }
    } else {
        contentInput.placeholder = '';
        contentHelp.textContent = '请输入对应记录类型的值';
        if (proxiedSection) {
            proxiedSection.style.display = 'none';
        }
    }
}

function updateEditContentPlaceholder() {
    const typeSelect = document.getElementById('edit_type');
    const contentInput = document.getElementById('edit_content');
    const contentHelp = document.getElementById('edit-content-help');
    const proxiedSection = document.getElementById('edit-proxied-section');
    
    const type = typeSelect.value;
    const isRainbowDNS = <?php echo (isset($current_domain['provider_type']) && $current_domain['provider_type'] === 'rainbow') ? 'true' : 'false'; ?>;
    
    // 定义记录类型的配置
    const typeConfigs = {
        'A': {
            placeholder: '192.168.1.1',
            help: '请输入IPv4地址',
            showProxied: true
        },
        'AAAA': {
            placeholder: '2001:db8::1',
            help: '请输入IPv6地址',
            showProxied: true
        },
        'CNAME': {
            placeholder: 'example.com',
            help: '请输入目标域名',
            showProxied: true
        },
        'MX': {
            placeholder: '10 mail.example.com',
            help: '请输入优先级和邮件服务器地址',
            showProxied: false
        },
        'NS': {
            placeholder: 'ns1.example.com',
            help: '请输入域名服务器地址',
            showProxied: false
        },
        'TXT': {
            placeholder: 'v=spf1 include:_spf.google.com ~all',
            help: '请输入文本内容',
            showProxied: false
        },
        'SRV': {
            placeholder: '10 5 443 target.example.com',
            help: '请输入优先级 权重 端口 目标',
            showProxied: false
        },
        'PTR': {
            placeholder: 'example.com',
            help: '请输入反向解析的域名',
            showProxied: false
        },
        'CAA': {
            placeholder: '0 issue "letsencrypt.org"',
            help: '请输入CAA记录值',
            showProxied: false
        }
    };
    
    const config = typeConfigs[type];
    if (config) {
        contentInput.placeholder = config.placeholder;
        contentHelp.textContent = config.help;
        // 对于彩虹DNS，隐藏代理选项
        if (proxiedSection) {
            proxiedSection.style.display = (config.showProxied && !isRainbowDNS) ? 'block' : 'none';
        }
    } else {
        contentInput.placeholder = '';
        contentHelp.textContent = '请输入对应记录类型的值';
        if (proxiedSection) {
            proxiedSection.style.display = 'none';
        }
    }
}

function editRecord(record) {
    // 填充表单数据
    document.getElementById('edit_record_id').value = record.id;
    document.getElementById('edit_subdomain').value = record.subdomain;
    document.getElementById('edit_type').value = record.type;
    document.getElementById('edit_content').value = record.content;
    document.getElementById('edit_remark').value = record.remark || '';
    document.getElementById('edit_proxied').checked = record.proxied == 1;
    
    // 更新内容占位符和帮助文本
    updateEditContentPlaceholder();
    
    // 显示模态框
    var modal = new bootstrap.Modal(document.getElementById('editRecordModal'));
    modal.show();
}

function confirmDelete(message) {
    return confirm(message);
}

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    updateContentPlaceholder();
    
    // 显示公告
    showAnnouncements();
    
    // 初始化前缀检查
    initPrefixValidation();
});

// 被拦截的前缀列表
const blockedPrefixes = <?php echo json_encode($blocked_prefixes); ?>;

// 初始化前缀验证
function initPrefixValidation() {
    const subdomainInput = document.getElementById('subdomain');
    const editSubdomainInput = document.getElementById('edit_subdomain');
    
    if (subdomainInput) {
        subdomainInput.addEventListener('input', function() {
            validateSubdomain(this, 'subdomain-warning');
        });
        subdomainInput.addEventListener('blur', function() {
            validateSubdomain(this, 'subdomain-warning');
        });
    }
    
    if (editSubdomainInput) {
        editSubdomainInput.addEventListener('input', function() {
            validateSubdomain(this, 'edit-subdomain-warning');
        });
        editSubdomainInput.addEventListener('blur', function() {
            validateSubdomain(this, 'edit-subdomain-warning');
        });
    }
}

// 验证子域名前缀
function validateSubdomain(input, warningId) {
    const subdomain = input.value.toLowerCase().trim();
    const warningElement = document.getElementById(warningId);
    
    // 移除现有的警告元素
    if (warningElement) {
        warningElement.remove();
    }
    
    // 检查是否为空
    if (!subdomain) {
        input.classList.remove('is-invalid', 'is-valid');
        return;
    }
    
    // 检查是否被拦截
    if (blockedPrefixes.includes(subdomain)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        
        // 添加警告提示
        const warning = document.createElement('div');
        warning.id = warningId;
        warning.className = 'invalid-feedback';
        warning.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>此前缀已被系统拦截，无法使用！';
        input.parentNode.appendChild(warning);
        
        // 禁用提交按钮
        const submitBtn = input.closest('form').querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
        }
    } else {
        input.classList.add('is-valid');
        input.classList.remove('is-invalid');
        
        // 启用提交按钮
        const submitBtn = input.closest('form').querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = false;
        }
    }
}

// 显示公告弹窗
function showAnnouncements() {
    const announcements = <?php echo json_encode($user_announcements); ?>;
    
    if (announcements.length > 0) {
        // 延迟1秒显示公告，让页面完全加载
        setTimeout(() => {
            showAnnouncementModal(announcements, 0);
        }, 1000);
    }
}

// 显示公告模态框
function showAnnouncementModal(announcements, index) {
    if (index >= announcements.length) {
        return; // 所有公告都显示完了
    }
    
    const announcement = announcements[index];
    
    // 设置模态框内容
    document.getElementById('announcementTitle').textContent = announcement.title;
    document.getElementById('announcementContent').innerHTML = announcement.content;
    document.getElementById('announcementTime').textContent = '发布时间：' + formatDateTime(announcement.created_at);
    
    // 设置公告类型样式
    const modal = document.getElementById('announcementModal');
    const header = modal.querySelector('.modal-header');
    
    // 移除之前的类型样式
    header.classList.remove('bg-info', 'bg-success', 'bg-warning', 'bg-danger');
    
    // 添加对应的类型样式
    switch (announcement.type) {
        case 'success':
            header.classList.add('bg-success');
            break;
        case 'warning':
            header.classList.add('bg-warning');
            break;
        case 'danger':
            header.classList.add('bg-danger');
            break;
        default:
            header.classList.add('bg-info');
    }
    
    // 显示模态框
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
    
    // 记录查看
    markAnnouncementAsViewed(announcement.id);
    
    // 当模态框关闭时，显示下一个公告
    modal.addEventListener('hidden.bs.modal', function() {
        setTimeout(() => {
            showAnnouncementModal(announcements, index + 1);
        }, 500);
    }, { once: true });
}

// 标记公告为已查看
function markAnnouncementAsViewed(announcementId) {
    fetch('mark_announcement_viewed.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            announcement_id: announcementId
        })
    }).catch(error => {
        console.error('标记公告查看状态失败:', error);
    });
}

// 格式化日期时间
function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// DNS冲突检测功能
function checkDNSConflict(subdomain, type, content) {
    if (!subdomain || !type || !content) return { hasConflict: false };
    
    const domainName = '<?php echo addslashes($current_domain['domain_name'] ?? ''); ?>';
    const fullName = subdomain === '@' ? domainName : `${subdomain}.${domainName}`;
    const targetType = type.toUpperCase();
    const conflictingTypes = ['A', 'AAAA', 'CNAME'];
    
    // 获取现有记录（从页面表格中提取）
    const existingRecords = [];
    const tableRows = document.querySelectorAll('table tbody tr');
    
    tableRows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 3) {
            const recordName = cells[0].textContent.trim();
            const recordType = cells[1].textContent.trim().toUpperCase();
            const recordContent = cells[2].textContent.trim();
            
            existingRecords.push({
                name: recordName,
                type: recordType,
                content: recordContent
            });
        }
    });
    
    // 检查冲突
    for (const record of existingRecords) {
        if (record.name.toLowerCase() === fullName.toLowerCase()) {
            // 检查类型冲突
            if (conflictingTypes.includes(record.type) && conflictingTypes.includes(targetType)) {
                return {
                    hasConflict: true,
                    type: 'type_conflict',
                    existingRecord: record,
                    message: `域名 '${fullName}' 已存在 ${record.type} 记录，无法添加 ${targetType} 记录`
                };
            }
            
            // 检查完全相同的记录
            if (record.type === targetType) {
                if (record.content === content) {
                    return {
                        hasConflict: true,
                        type: 'exact_match',
                        existingRecord: record,
                        message: `完全相同的DNS记录已存在`
                    };
                } else {
                    return {
                        hasConflict: true,
                        type: 'same_type',
                        existingRecord: record,
                        message: `域名 '${fullName}' 已存在相同类型的 ${record.type} 记录`
                    };
                }
            }
        }
    }
    
    return { hasConflict: false };
}

// 显示冲突警告
function showConflictWarning(elementId, conflictResult) {
    const warningElement = document.getElementById(elementId);
    if (!warningElement) return;
    
    if (conflictResult.hasConflict) {
        warningElement.innerHTML = `
            <div class="alert alert-warning alert-sm mt-2">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>冲突警告：</strong>${conflictResult.message}<br>
                <small>现有记录：${conflictResult.existingRecord.type} → ${conflictResult.existingRecord.content}</small>
            </div>
        `;
        warningElement.style.display = 'block';
    } else {
        warningElement.style.display = 'none';
    }
}

// 检查并更新冲突状态
function checkAndUpdateConflict() {
    const subdomainInput = document.getElementById('subdomain');
    const typeSelect = document.getElementById('type');
    const contentInput = document.getElementById('content');
    const submitButton = document.querySelector('button[name="add_record"]');
    
    if (subdomainInput && typeSelect && contentInput && submitButton) {
        const subdomain = subdomainInput.value;
        const type = typeSelect.value;
        const content = contentInput.value;
        
        if (subdomain && type && content) {
            const conflictResult = checkDNSConflict(subdomain, type, content);
            showConflictWarning('conflict-warning-add', conflictResult);
            
            // 如果有冲突，禁用提交按钮
            if (conflictResult.hasConflict) {
                submitButton.disabled = true;
                submitButton.title = '存在DNS记录冲突，无法提交';
                submitButton.classList.add('btn-secondary');
                submitButton.classList.remove('btn-primary');
            } else {
                submitButton.disabled = false;
                submitButton.title = '';
                submitButton.classList.add('btn-primary');
                submitButton.classList.remove('btn-secondary');
            }
        } else {
            // 清除警告
            showConflictWarning('conflict-warning-add', { hasConflict: false });
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.classList.add('btn-primary');
                submitButton.classList.remove('btn-secondary');
            }
        }
    }
}
</script>

<style>
/* 修复域名选择下拉框样式 - 深色主题适配 */
#domain_id {
    background-color: rgba(255, 255, 255, 0.1) !important;
    color: #fff !important;
    border-color: rgba(255, 255, 255, 0.2) !important;
}

#domain_id option {
    background-color: rgba(10, 14, 39, 0.95) !important;
    color: #fff !important;
    padding: 8px 12px;
}

#domain_id option:hover {
    background-color: rgba(0, 212, 255, 0.2) !important;
}

/* 修复其他表单元素的深色主题 */
.form-select {
    background-color: rgba(255, 255, 255, 0.1);
    color: #fff;
    border-color: rgba(255, 255, 255, 0.2);
}

.form-select option {
    background-color: rgba(10, 14, 39, 0.95);
    color: #fff;
}

.form-control {
    background-color: rgba(255, 255, 255, 0.1);
    color: #fff;
    border-color: rgba(255, 255, 255, 0.2);
}

.form-control:focus {
    background-color: rgba(255, 255, 255, 0.15);
    color: #fff;
    border-color: #00d4ff;
    box-shadow: 0 0 0 0.2rem rgba(0, 212, 255, 0.25);
}

.form-control::placeholder {
    color: rgba(255, 255, 255, 0.5);
}
</style>

<!-- 公告弹窗模态框 -->
<?php if (!empty($user_announcements)): ?>
<div class="modal fade" id="announcementModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header text-white">
                <h5 class="modal-title">
                    <i class="fas fa-bullhorn me-2"></i>
                    <span id="announcementTitle">系统公告</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="announcementContent" class="mb-3">
                    <!-- 公告内容将通过JavaScript填充 -->
                </div>
                <div class="text-muted small" id="announcementTime">
                    <!-- 发布时间将通过JavaScript填充 -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                    <i class="fas fa-check me-1"></i>我知道了
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>