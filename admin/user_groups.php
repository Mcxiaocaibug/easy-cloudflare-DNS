<?php
/**
 * 用户组管理页面
 * 支持用户组的增删改查和域名权限配置
 * 自动检测并初始化数据库表结构
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_groups.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 🔥 自动检测并初始化用户组表
initializeUserGroupTables($db);

/**
 * 初始化用户组表结构
 * 检测表是否存在，不存在则创建
 * 检测字段是否完整，缺失则添加
 */
function initializeUserGroupTables($db) {
    try {
        // 1. 检查 user_groups 表是否存在
        $table_exists = $db->querySingle("
            SELECT COUNT(*) FROM sqlite_master 
            WHERE type='table' AND name='user_groups'
        ");
        
        if (!$table_exists) {
            // 创建用户组表
            $db->exec("CREATE TABLE user_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_name TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL,
                points_per_record INTEGER DEFAULT 1,
                description TEXT DEFAULT '',
                is_active INTEGER DEFAULT 1,
                can_access_all_domains INTEGER DEFAULT 0,
                max_records INTEGER DEFAULT -1,
                priority INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            // 插入默认数据
            $default_groups = [
                ['default', '默认组', 1, '普通用户，基础权限', 0, 0, 100],
                ['vip', 'VIP组', 1, 'VIP用户，享受更多域名权限', 10, 0, 500],
                ['svip', 'SVIP组', 0, '超级VIP用户，免积分解析，全域名权限', 20, 1, -1]
            ];
            
            foreach ($default_groups as $group) {
                $stmt = $db->prepare("
                    INSERT INTO user_groups 
                    (group_name, display_name, points_per_record, description, priority, can_access_all_domains, max_records) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bindValue(1, $group[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $group[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $group[2], SQLITE3_INTEGER);
                $stmt->bindValue(4, $group[3], SQLITE3_TEXT);
                $stmt->bindValue(5, $group[4], SQLITE3_INTEGER);
                $stmt->bindValue(6, $group[5], SQLITE3_INTEGER);
                $stmt->bindValue(7, $group[6], SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
        
        // 2. 检查 user_group_domains 表是否存在
        $table_exists = $db->querySingle("
            SELECT COUNT(*) FROM sqlite_master 
            WHERE type='table' AND name='user_group_domains'
        ");
        
        if (!$table_exists) {
            $db->exec("CREATE TABLE user_group_domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER NOT NULL,
                domain_id INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
                FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
                UNIQUE(group_id, domain_id)
            )");
        }
        
        // 3. 检查 users 表是否有 group_id 字段
        $columns = $db->query("PRAGMA table_info(users)");
        $has_group_id = false;
        
        if ($columns) {
            while ($column = $columns->fetchArray(SQLITE3_ASSOC)) {
                if ($column['name'] === 'group_id') {
                    $has_group_id = true;
                    break;
                }
            }
        }
        
        if (!$has_group_id) {
            $db->exec("ALTER TABLE users ADD COLUMN group_id INTEGER DEFAULT 1");
            $db->exec("ALTER TABLE users ADD COLUMN group_changed_at TIMESTAMP DEFAULT NULL");
            $db->exec("ALTER TABLE users ADD COLUMN group_changed_by INTEGER DEFAULT NULL");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_users_group_id ON users(group_id)");
        }
        
        // 4. 创建索引
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_group_domains_group ON user_group_domains(group_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_user_group_domains_domain ON user_group_domains(domain_id)");
        
        return true;
        
    } catch (Exception $e) {
        error_log("初始化用户组表失败: " . $e->getMessage());
        return false;
    }
}

$manager = new UserGroupManager($db);
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 处理添加用户组
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group'])) {
    $group_name = trim($_POST['group_name']);
    $display_name = trim($_POST['display_name']);
    $points_per_record = intval($_POST['points_per_record']);
    $max_records = intval($_POST['max_records']);
    $priority = intval($_POST['priority']);
    $description = trim($_POST['description']);
    $can_access_all_domains = isset($_POST['can_access_all_domains']) ? 1 : 0;
    
    if (empty($group_name) || empty($display_name)) {
        showError('组名和显示名称不能为空！');
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO user_groups 
                (group_name, display_name, points_per_record, max_records, priority, description, can_access_all_domains, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->bindValue(1, $group_name, SQLITE3_TEXT);
            $stmt->bindValue(2, $display_name, SQLITE3_TEXT);
            $stmt->bindValue(3, $points_per_record, SQLITE3_INTEGER);
            $stmt->bindValue(4, $max_records, SQLITE3_INTEGER);
            $stmt->bindValue(5, $priority, SQLITE3_INTEGER);
            $stmt->bindValue(6, $description, SQLITE3_TEXT);
            $stmt->bindValue(7, $can_access_all_domains, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                logAction('admin', $_SESSION['admin_id'], 'add_user_group', "添加用户组: {$display_name}");
                showSuccess('用户组添加成功！');
            } else {
                showError('用户组添加失败！');
            }
        } catch (Exception $e) {
            showError('添加失败: ' . $e->getMessage());
        }
    }
    redirect('user_groups.php');
}

// 处理编辑用户组
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_group'])) {
    $group_id = intval($_POST['group_id']);
    $display_name = trim($_POST['display_name']);
    $points_per_record = intval($_POST['points_per_record']);
    $max_records = intval($_POST['max_records']);
    $priority = intval($_POST['priority']);
    $description = trim($_POST['description']);
    $can_access_all_domains = isset($_POST['can_access_all_domains']) ? 1 : 0;
    $domain_ids = isset($_POST['domain_ids']) ? $_POST['domain_ids'] : [];
    
    if (empty($display_name)) {
        showError('显示名称不能为空！');
    } else {
        try {
            $stmt = $db->prepare("
                UPDATE user_groups 
                SET display_name = ?, 
                    points_per_record = ?, 
                    max_records = ?, 
                    priority = ?, 
                    description = ?, 
                    can_access_all_domains = ?,
                    updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->bindValue(1, $display_name, SQLITE3_TEXT);
            $stmt->bindValue(2, $points_per_record, SQLITE3_INTEGER);
            $stmt->bindValue(3, $max_records, SQLITE3_INTEGER);
            $stmt->bindValue(4, $priority, SQLITE3_INTEGER);
            $stmt->bindValue(5, $description, SQLITE3_TEXT);
            $stmt->bindValue(6, $can_access_all_domains, SQLITE3_INTEGER);
            $stmt->bindValue(7, $group_id, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                // 更新域名权限
                $manager->setGroupDomains($group_id, $domain_ids);
                
                logAction('admin', $_SESSION['admin_id'], 'edit_user_group', "编辑用户组ID: {$group_id}");
                showSuccess('用户组更新成功！');
            } else {
                showError('用户组更新失败！');
            }
        } catch (Exception $e) {
            showError('更新失败: ' . $e->getMessage());
        }
    }
    redirect('user_groups.php');
}

// 处理删除用户组
if ($action === 'delete' && isset($_GET['id'])) {
    $group_id = intval($_GET['id']);
    
    // 不允许删除默认组（ID=1）
    if ($group_id == 1) {
        showError('不能删除默认用户组！');
        redirect('user_groups.php');
    }
    
    // 检查是否有用户使用该组
    $user_count = $db->querySingle("SELECT COUNT(*) FROM users WHERE group_id = $group_id");
    
    if ($user_count > 0) {
        showError("该用户组还有 {$user_count} 个用户，无法删除！请先将用户转移到其他组。");
    } else {
        try {
            $stmt = $db->prepare("DELETE FROM user_groups WHERE id = ?");
            $stmt->bindValue(1, $group_id, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                logAction('admin', $_SESSION['admin_id'], 'delete_user_group', "删除用户组ID: {$group_id}");
                showSuccess('用户组删除成功！');
            } else {
                showError('用户组删除失败！');
            }
        } catch (Exception $e) {
            showError('删除失败: ' . $e->getMessage());
        }
    }
    redirect('user_groups.php');
}

// 处理启用/禁用用户组
if ($action === 'toggle_status' && isset($_GET['id'])) {
    $group_id = intval($_GET['id']);
    
    // 不允许禁用默认组（ID=1）
    if ($group_id == 1) {
        showError('不能禁用默认用户组！');
        redirect('user_groups.php');
    }
    
    try {
        $stmt = $db->prepare("UPDATE user_groups SET is_active = 1 - is_active, updated_at = datetime('now') WHERE id = ?");
        $stmt->bindValue(1, $group_id, SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            logAction('admin', $_SESSION['admin_id'], 'toggle_user_group_status', "切换用户组状态ID: {$group_id}");
            showSuccess('状态更新成功！');
        } else {
            showError('状态更新失败！');
        }
    } catch (Exception $e) {
        showError('操作失败: ' . $e->getMessage());
    }
    redirect('user_groups.php');
}

// 获取所有用户组
$groups = $manager->getAllGroups();

// 获取所有域名
$domains = [];
$result = $db->query("SELECT * FROM domains ORDER BY domain_name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $domains[] = $row;
}

// 如果是编辑模式，获取用户组信息
$edit_group = null;
$group_domain_ids = [];
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_group = $manager->getGroupById($edit_id);
    if ($edit_group) {
        $group_domain_ids = $manager->getGroupDomains($edit_id);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户组管理 - 六趣DNS管理系统</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/fontawesome.min.css" rel="stylesheet">
    <link href="../assets/css/dashboard-custom.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-users-cog me-2"></i>用户组管理
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal">
                            <i class="fas fa-plus me-1"></i>添加用户组
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
                
                <!-- 用户组列表 -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>组名</th>
                                        <th>显示名称</th>
                                        <th>积分/条</th>
                                        <th>最大记录数</th>
                                        <th>优先级</th>
                                        <th>全域名访问</th>
                                        <th>状态</th>
                                        <th>用户数</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groups as $group): ?>
                                        <?php
                                        $user_count = $db->querySingle("SELECT COUNT(*) FROM users WHERE group_id = {$group['id']}");
                                        ?>
                                        <tr>
                                            <td><?php echo $group['id']; ?></td>
                                            <td>
                                                <code><?php echo htmlspecialchars($group['group_name']); ?></code>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($group['display_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $group['points_per_record'] == 0 ? 'success' : 'primary'; ?>">
                                                    <?php echo $group['points_per_record']; ?> 分
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                if ($group['max_records'] == -1) {
                                                    echo '<span class="badge bg-success">无限制</span>';
                                                } else {
                                                    echo '<span class="badge bg-info">' . $group['max_records'] . ' 条</span>';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo $group['priority']; ?></td>
                                            <td>
                                                <?php if ($group['can_access_all_domains'] == 1): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check"></i> 是</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><i class="fas fa-times"></i> 否</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($group['is_active'] == 1): ?>
                                                    <span class="badge bg-success">启用</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">禁用</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $user_count; ?> 人</span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?edit=<?php echo $group['id']; ?>" class="btn btn-primary" title="编辑">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($group['id'] != 1): // 默认组不可删除和禁用 ?>
                                                        <a href="?action=toggle_status&id=<?php echo $group['id']; ?>" 
                                                           class="btn btn-warning" 
                                                           onclick="return confirm('确定要切换此用户组的状态吗？');"
                                                           title="切换状态">
                                                            <i class="fas fa-power-off"></i>
                                                        </a>
                                                        <a href="?action=delete&id=<?php echo $group['id']; ?>" 
                                                           class="btn btn-danger" 
                                                           onclick="return confirm('确定要删除此用户组吗？删除后无法恢复！');"
                                                           title="删除">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- 统计信息 -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">用户组总数</h5>
                                <h2 class="text-primary"><?php echo count($groups); ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">启用的组</h5>
                                <h2 class="text-success">
                                    <?php echo count(array_filter($groups, function($g) { return $g['is_active'] == 1; })); ?>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">总用户数</h5>
                                <h2 class="text-info">
                                    <?php echo $db->querySingle("SELECT COUNT(*) FROM users"); ?>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">域名总数</h5>
                                <h2 class="text-warning">
                                    <?php echo count($domains); ?>
                                </h2>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- 添加用户组Modal -->
    <div class="modal fade" id="addGroupModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">添加用户组</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">组名 (英文标识) *</label>
                                <input type="text" name="group_name" class="form-control" required 
                                       pattern="[a-zA-Z0-9_]+" title="只能包含字母、数字和下划线">
                                <small class="text-muted">例如: premium, gold</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">显示名称 *</label>
                                <input type="text" name="display_name" class="form-control" required>
                                <small class="text-muted">例如: 高级组, 黄金会员</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">每条记录积分 *</label>
                                <input type="number" name="points_per_record" class="form-control" 
                                       value="1" min="0" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">最大记录数 *</label>
                                <input type="number" name="max_records" class="form-control" 
                                       value="100" required>
                                <small class="text-muted">-1 表示无限制</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">优先级 *</label>
                                <input type="number" name="priority" class="form-control" 
                                       value="0" required>
                                <small class="text-muted">数字越大优先级越高</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">描述</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="can_access_all_domains" class="form-check-input" id="add_all_domains">
                                <label class="form-check-label" for="add_all_domains">
                                    允许访问所有域名
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" name="add_group" class="btn btn-primary">添加</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 编辑用户组Modal -->
    <?php if ($edit_group): ?>
    <div class="modal fade show" id="editGroupModal" tabindex="-1" style="display: block;" aria-modal="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">编辑用户组: <?php echo htmlspecialchars($edit_group['display_name']); ?></h5>
                    <a href="user_groups.php" class="btn-close"></a>
                </div>
                <form method="POST">
                    <input type="hidden" name="group_id" value="<?php echo $edit_group['id']; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">组名 (不可修改)</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_group['group_name']); ?>" disabled>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">显示名称 *</label>
                                <input type="text" name="display_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($edit_group['display_name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">每条记录积分 *</label>
                                <input type="number" name="points_per_record" class="form-control" 
                                       value="<?php echo $edit_group['points_per_record']; ?>" min="0" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">最大记录数 *</label>
                                <input type="number" name="max_records" class="form-control" 
                                       value="<?php echo $edit_group['max_records']; ?>" required>
                                <small class="text-muted">-1 表示无限制</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">优先级 *</label>
                                <input type="number" name="priority" class="form-control" 
                                       value="<?php echo $edit_group['priority']; ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">描述</label>
                            <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($edit_group['description']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="can_access_all_domains" class="form-check-input" 
                                       id="edit_all_domains" <?php echo $edit_group['can_access_all_domains'] ? 'checked' : ''; ?>
                                       onchange="toggleDomainSelection(this)">
                                <label class="form-check-label" for="edit_all_domains">
                                    允许访问所有域名
                                </label>
                            </div>
                        </div>
                        <div id="domainSelectionDiv" style="display: <?php echo $edit_group['can_access_all_domains'] ? 'none' : 'block'; ?>;">
                            <label class="form-label">可访问的域名</label>
                            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach ($domains as $domain): ?>
                                    <div class="form-check">
                                        <input type="checkbox" name="domain_ids[]" class="form-check-input" 
                                               value="<?php echo $domain['id']; ?>" id="domain_<?php echo $domain['id']; ?>"
                                               <?php echo in_array($domain['id'], $group_domain_ids) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="domain_<?php echo $domain['id']; ?>">
                                            <?php echo htmlspecialchars($domain['domain_name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($domains)): ?>
                                    <p class="text-muted">暂无域名</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="user_groups.php" class="btn btn-secondary">取消</a>
                        <button type="submit" name="edit_group" class="btn btn-primary">保存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
    <?php endif; ?>
    
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleDomainSelection(checkbox) {
            const domainDiv = document.getElementById('domainSelectionDiv');
            domainDiv.style.display = checkbox.checked ? 'none' : 'block';
        }
    </script>
</body>
</html>

