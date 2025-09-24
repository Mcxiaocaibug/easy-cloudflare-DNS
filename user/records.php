<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkUserLogin();

$db = Database::getInstance()->getConnection();

// 获取用户的所有DNS记录（包含域名信息，排除系统同步的记录）
$dns_records = [];
$stmt = $db->prepare("
    SELECT dr.*, d.domain_name 
    FROM dns_records dr 
    JOIN domains d ON dr.domain_id = d.id 
    WHERE dr.user_id = ? AND (dr.is_system = 0 OR dr.is_system IS NULL)
    ORDER BY dr.created_at DESC
");
$stmt->bindValue(1, $_SESSION['user_id'], SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $dns_records[] = $row;
}

// 统计信息
$stats = [
    'total_records' => count($dns_records),
    'a_records' => count(array_filter($dns_records, function($r) { return $r['type'] === 'A'; })),
    'cname_records' => count(array_filter($dns_records, function($r) { return $r['type'] === 'CNAME'; })),
    'proxied_records' => count(array_filter($dns_records, function($r) { return $r['proxied']; }))
];

$page_title = '我的记录';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 1px solid rgba(255, 255, 255, 0.2);">
                <h1 class="h2">我的DNS记录</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="dashboard.php" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i>添加记录
                    </a>
                </div>
            </div>
            
            <!-- 统计卡片 -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">总记录数</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_records']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-list fa-2x text-gray-300"></i>
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
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">A记录</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['a_records']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-server fa-2x text-gray-300"></i>
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
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">CNAME记录</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['cname_records']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-link fa-2x text-gray-300"></i>
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
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">已代理</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['proxied_records']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-shield-alt fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 记录列表 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">DNS记录列表</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($dns_records)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>域名</th>
                                    <th>子域名</th>
                                    <th>完整域名</th>
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
                                <?php 
                                $full_domain = $record['subdomain'] === '@' ? 
                                    $record['domain_name'] : 
                                    $record['subdomain'] . '.' . $record['domain_name'];
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($record['domain_name']); ?></strong>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($record['subdomain']); ?></code>
                                    </td>
                                    <td>
                                        <span class="text-primary" style="cursor: pointer;" 
                                              onclick="copyToClipboard('<?php echo htmlspecialchars($full_domain); ?>')"
                                              title="点击复制">
                                            <?php echo htmlspecialchars($full_domain); ?>
                                            <i class="fas fa-copy ms-1"></i>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $record['type']; ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $content = htmlspecialchars($record['content']);
                                        $truncated = mb_strlen($content) > 50 ? mb_substr($content, 0, 50) . '...' : $content;
                                        ?>
                                        <span style="cursor: pointer;" 
                                              onclick="copyToClipboard('<?php echo $content; ?>')"
                                              title="完整内容: <?php echo $content; ?> (点击复制)">
                                            <?php echo $truncated; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['remark'])): ?>
                                            <span class="text-muted" title="<?php echo htmlspecialchars($record['remark']); ?>">
                                                <i class="fas fa-comment-alt me-1 text-info"></i>
                                                <?php echo htmlspecialchars(mb_strlen($record['remark']) > 15 ? mb_substr($record['remark'], 0, 15) . '...' : $record['remark']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['proxied']): ?>
                                            <span class="badge bg-warning">
                                                <i class="fas fa-shield-alt me-1"></i>已代理
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-globe me-1"></i>仅DNS
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($record['created_at']); ?></td>
                                    <td>
                                        <a href="dashboard.php" class="btn btn-sm btn-outline-primary" title="管理记录">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">暂无DNS记录</h5>
                        <p class="text-muted">您还没有添加任何DNS记录</p>
                        <a href="dashboard.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>添加第一条记录
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </main>
    </div>
</div>

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

.text-gray-800 {
    color: #5a5c69 !important;
}

.text-gray-300 {
    color: #dddfeb !important;
}
</style>

<?php include 'includes/footer.php'; ?>