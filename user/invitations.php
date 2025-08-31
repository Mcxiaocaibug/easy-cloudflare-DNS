<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkUserLogin();

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];
$messages = getMessages();

// 检查邀请系统是否启用
$invitation_enabled = getSetting('invitation_enabled', '1');
if (!$invitation_enabled) {
    showError('邀请系统已关闭');
    redirect('dashboard.php');
}

// 检查用户是否已有邀请码，如果没有则自动生成
$user_invitation = $db->querySingle("SELECT * FROM invitations WHERE inviter_id = $user_id", true);
if (!$user_invitation) {
    // 为老用户自动生成邀请码
    do {
        $invitation_code = 'INV' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        $exists = $db->querySingle("SELECT COUNT(*) FROM invitations WHERE invitation_code = '$invitation_code'");
    } while ($exists > 0);
    
    $current_reward_points = (int)getSetting('invitation_reward_points', '10');
    
    $stmt = $db->prepare("INSERT INTO invitations (inviter_id, invitation_code, reward_points) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $invitation_code, SQLITE3_TEXT);
    $stmt->bindValue(3, $current_reward_points, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('user', $user_id, 'auto_generate_invitation', "自动生成邀请码: $invitation_code");
    $user_invitation = $db->querySingle("SELECT * FROM invitations WHERE inviter_id = $user_id", true);
}

// 获取用户的邀请记录（每个用户只有一个邀请码）
$my_invitation = $db->querySingle("
    SELECT i.*,
           (SELECT COUNT(*) FROM invitation_uses WHERE invitation_id = i.id) as actual_use_count,
           (SELECT GROUP_CONCAT(u.username, ', ') FROM invitation_uses iu 
            JOIN users u ON iu.invitee_id = u.id 
            WHERE iu.invitation_id = i.id 
            ORDER BY iu.used_at DESC LIMIT 5) as recent_users
    FROM invitations i 
    WHERE i.inviter_id = $user_id
", true);

// 获取统计信息
$stats = [
    'has_invitation' => $my_invitation ? 1 : 0,
    'total_uses' => $my_invitation ? $my_invitation['use_count'] : 0,
    'unique_users' => $my_invitation ? $db->querySingle("SELECT COUNT(DISTINCT invitee_id) FROM invitation_uses WHERE invitation_id = {$my_invitation['id']}") : 0,
    'total_rewards' => $my_invitation ? $my_invitation['total_rewards'] : 0
];

$page_title = '邀请管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-user-friends me-2"></i>邀请管理</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <?php if ($my_invitation): ?>
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" onclick="copyInvitationCode('<?php echo $my_invitation['invitation_code']; ?>')">
                            <i class="fas fa-link me-2"></i>复制邀请链接
                        </button>
                        <button type="button" class="btn btn-outline-primary" onclick="shareInvitation('<?php echo $my_invitation['invitation_code']; ?>')">
                            <i class="fas fa-share-alt me-2"></i>分享邀请
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="viewInvitationDetails('<?php echo $my_invitation['invitation_code']; ?>')">
                            <i class="fas fa-eye me-2"></i>查看详情
                        </button>
                    </div>
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
            
            <!-- 统计卡片 -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">邀请码状态</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php if ($my_invitation): ?>
                                            <span class="text-success">已激活</span>
                                        <?php else: ?>
                                            <span class="text-muted">未生成</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-ticket-alt fa-2x text-gray-300"></i>
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
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">使用次数</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_uses']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-chart-line fa-2x text-gray-300"></i>
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
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">邀请用户数</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['unique_users']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-users fa-2x text-gray-300"></i>
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
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">获得积分</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_rewards']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-coins fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 我的邀请码 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">我的邀请码</h6>
                </div>
                <div class="card-body">
                    <?php if (!$my_invitation): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-friends fa-3x text-muted mb-3"></i>
                            <p class="text-muted">系统正在为您生成邀请码，请刷新页面...</p>
                            <a href="invitations.php" class="btn btn-primary">
                                <i class="fas fa-refresh me-2"></i>刷新页面
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title text-primary">
                                            <i class="fas fa-ticket-alt me-2"></i>邀请码信息
                                        </h6>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">我的邀请码</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" value="<?php echo $my_invitation['invitation_code']; ?>" readonly>
                                                <button class="btn btn-outline-secondary" onclick="copyInvitationCodeOnly('<?php echo $my_invitation['invitation_code']; ?>')" title="复制邀请码">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">邀请链接</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" value="<?php echo $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI'], 2) . '/user/login.php?invite=' . $my_invitation['invitation_code']; ?>" readonly>
                                                <button class="btn btn-outline-primary" onclick="copyInvitationCode('<?php echo $my_invitation['invitation_code']; ?>')" title="复制邀请链接">
                                                    <i class="fas fa-link"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-0">
                                            <label class="form-label fw-bold">状态</label>
                                            <div>
                                                <?php if ($my_invitation['is_active']): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i>永久有效
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">
                                                        <i class="fas fa-times-circle me-1"></i>已禁用
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title text-success">
                                            <i class="fas fa-chart-bar me-2"></i>使用统计
                                        </h6>
                                        
                                        <div class="row text-center mb-3">
                                            <div class="col-6">
                                                <div class="border-end">
                                                    <h4 class="text-primary mb-1"><?php echo $my_invitation['use_count'] ?: 0; ?></h4>
                                                    <small class="text-muted">使用次数</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <h4 class="text-success mb-1"><?php echo $my_invitation['total_rewards'] ?: 0; ?></h4>
                                                <small class="text-muted">获得积分</small>
                                            </div>
                                        </div>
                                        
                                        <?php if ($my_invitation['recent_users']): ?>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">最近使用用户</label>
                                            <div class="text-success">
                                                <i class="fas fa-users me-1"></i>
                                                <?php 
                                                $users = explode(', ', $my_invitation['recent_users']);
                                                echo htmlspecialchars(implode(', ', array_slice($users, 0, 3)));
                                                if (count($users) > 3) echo '...';
                                                ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-0">
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                创建时间：<?php echo formatTime($my_invitation['created_at']); ?>
                                            </small>
                                            <?php if ($my_invitation['last_used_at']): ?>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-history me-1"></i>
                                                最后使用：<?php echo formatTime($my_invitation['last_used_at']); ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 邀请说明信息 -->
            <div class="alert alert-info mt-4">
                <i class="fas fa-info-circle me-2"></i>
                <strong>邀请奖励说明：</strong>
                <ul class="mb-0 mt-2">
                    <li>每次有用户通过您的邀请码注册，您将获得 <strong><?php echo getSetting('invitation_reward_points', '10'); ?></strong> 积分奖励</li>
                    <li>被邀请用户将额外获得 <strong><?php echo getSetting('invitee_bonus_points', '5'); ?></strong> 积分</li>
                    <li><strong class="text-success">邀请码永久有效，可重复使用</strong></li>
                    <li>同一用户只能使用同一邀请码注册一次</li>
                    <li><strong class="text-primary">每个用户只有一个专属邀请码</strong></li>
                </ul>
            </div>
        </main>
    </div>
</div>

<script>
// 复制邀请链接
function copyInvitationCode(code) {
    const baseUrl = window.location.origin + window.location.pathname.replace('/user/invitations.php', '');
    const inviteUrl = baseUrl + '/user/login.php?invite=' + code;
    
    copyToClipboard(inviteUrl, '邀请链接已复制到剪贴板');
}

// 仅复制邀请码
function copyInvitationCodeOnly(code) {
    copyToClipboard(code, '邀请码已复制到剪贴板');
}

// 通用复制函数
function copyToClipboard(text, successMessage) {
    if (navigator.clipboard && window.isSecureContext) {
        // 现代浏览器支持
        navigator.clipboard.writeText(text).then(function() {
            showToast(successMessage, 'success');
            // 添加复制成功的视觉反馈
            addCopyFeedback();
        }).catch(function(err) {
            fallbackCopy(text, successMessage);
        });
    } else {
        // 降级方案
        fallbackCopy(text, successMessage);
    }
}

// 降级复制方案
function fallbackCopy(text, successMessage) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showToast(successMessage, 'success');
        addCopyFeedback();
    } catch (err) {
        showToast('复制失败，请手动复制', 'error');
    }
    
    document.body.removeChild(textArea);
}

// 添加复制成功的视觉反馈
function addCopyFeedback() {
    // 创建一个临时的复制成功图标
    const feedback = document.createElement('div');
    feedback.innerHTML = '<i class="fas fa-check-circle text-success"></i>';
    feedback.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 3rem;
        z-index: 10000;
        pointer-events: none;
        animation: copyFeedback 0.8s ease-out;
    `;
    
    // 添加CSS动画
    if (!document.getElementById('copyFeedbackStyle')) {
        const style = document.createElement('style');
        style.id = 'copyFeedbackStyle';
        style.textContent = `
            @keyframes copyFeedback {
                0% { opacity: 0; transform: translate(-50%, -50%) scale(0.5); }
                50% { opacity: 1; transform: translate(-50%, -50%) scale(1.2); }
                100% { opacity: 0; transform: translate(-50%, -50%) scale(1); }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(feedback);
    
    // 800ms后移除
    setTimeout(() => {
        if (feedback.parentNode) {
            feedback.parentNode.removeChild(feedback);
        }
    }, 800);
}

// 分享邀请
function shareInvitation(code) {
    const baseUrl = window.location.origin + window.location.pathname.replace('/user/invitations.php', '');
    const inviteUrl = baseUrl + '/user/login.php?invite=' + code;
    const shareText = `邀请您加入 ${document.title.split(' - ')[1] || 'DNS管理系统'}！\n注册即可获得额外积分奖励。\n邀请链接：${inviteUrl}`;
    
    if (navigator.share) {
        navigator.share({
            title: '邀请注册',
            text: shareText,
            url: inviteUrl
        });
    } else {
        copyInvitationCode(code);
    }
}

// 获取当前用户的邀请码
function getCurrentInvitationCode() {
    // 从页面中获取邀请码
    const codeInput = document.querySelector('input[value*="INV"]');
    return codeInput ? codeInput.value : null;
}

// 查看邀请详情
function viewInvitationDetails(code) {
    // 创建详情模态框
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'invitationDetailsModal';
    modal.innerHTML = `
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>邀请码详情
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">加载中...</span>
                        </div>
                        <p class="mt-2">正在加载邀请详情...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                    <button type="button" class="btn btn-primary" onclick="copyInvitationCode('${code}')">
                        <i class="fas fa-copy me-2"></i>复制邀请链接
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
    
    // 模拟加载详情数据（实际项目中可以通过AJAX获取）
    setTimeout(() => {
        const modalBody = modal.querySelector('.modal-body');
        const baseUrl = window.location.origin + window.location.pathname.replace('/user/invitations.php', '');
        const inviteUrl = baseUrl + '/user/login.php?invite=' + code;
        
        modalBody.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-ticket-alt me-2"></i>邀请码信息
                    </h6>
                    <div class="card bg-light">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">邀请码</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" value="${code}" readonly>
                                    <button class="btn btn-outline-secondary" onclick="copyInvitationCodeOnly('${code}')" title="复制邀请码">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">邀请链接</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" value="${inviteUrl}" readonly>
                                    <button class="btn btn-outline-primary" onclick="copyInvitationCode('${code}')" title="复制邀请链接">
                                        <i class="fas fa-link"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label fw-bold">状态</label>
                                <div>
                                    <span class="badge bg-success">
                                        <i class="fas fa-check-circle me-1"></i>永久有效
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-success mb-3">
                        <i class="fas fa-chart-bar me-2"></i>使用统计
                    </h6>
                    <div class="card bg-light">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="border-end">
                                        <h4 class="text-primary mb-1">
                                            <i class="fas fa-chart-line"></i>
                                        </h4>
                                        <small class="text-muted">使用次数</small>
                                        <div class="fw-bold">查看表格</div>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <h4 class="text-success mb-1">
                                        <i class="fas fa-coins"></i>
                                    </h4>
                                    <small class="text-muted">获得积分</small>
                                    <div class="fw-bold">查看表格</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h6 class="text-info mb-3">
                            <i class="fas fa-share-alt me-2"></i>分享方式
                        </h6>
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-primary btn-sm" onclick="shareToWeChat('${code}')">
                                <i class="fab fa-weixin me-2"></i>分享到微信
                            </button>
                            <button class="btn btn-outline-info btn-sm" onclick="shareToQQ('${code}')">
                                <i class="fab fa-qq me-2"></i>分享到QQ
                            </button>
                            <button class="btn btn-outline-success btn-sm" onclick="shareInvitation('${code}')">
                                <i class="fas fa-share me-2"></i>系统分享
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }, 1000);
    
    // 模态框关闭时移除
    modal.addEventListener('hidden.bs.modal', function() {
        document.body.removeChild(modal);
    });
}

// 分享到微信
function shareToWeChat(code) {
    const baseUrl = window.location.origin + window.location.pathname.replace('/user/invitations.php', '');
    const inviteUrl = baseUrl + '/user/login.php?invite=' + code;
    const shareText = `🎉 邀请您加入DNS管理系统！\n\n✨ 使用邀请码注册可获得额外积分奖励\n🔗 邀请链接：${inviteUrl}\n📝 邀请码：${code}`;
    
    copyToClipboard(shareText, '微信分享内容已复制，请粘贴到微信');
}

// 分享到QQ
function shareToQQ(code) {
    const baseUrl = window.location.origin + window.location.pathname.replace('/user/invitations.php', '');
    const inviteUrl = baseUrl + '/user/login.php?invite=' + code;
    const shareText = `🎉 邀请您加入DNS管理系统！\n\n✨ 使用邀请码注册可获得额外积分奖励\n🔗 邀请链接：${inviteUrl}\n📝 邀请码：${code}`;
    
    copyToClipboard(shareText, 'QQ分享内容已复制，请粘贴到QQ');
}

// 显示提示
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="fas fa-${type === 'success' ? 'check' : 'info'} me-2"></i>${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    document.body.appendChild(toast);
    
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 5000);
}
</script>

<style>
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}

.invitation-code {
    background-color: #f8f9fa;
    padding: 6px 10px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-weight: bold;
    color: #495057;
    border: 1px solid #dee2e6;
    transition: all 0.2s ease;
    user-select: all;
    min-width: 120px;
    display: inline-block;
}

.invitation-code:hover {
    background-color: #e9ecef;
    border-color: #007bff;
    cursor: pointer;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,123,255,0.15);
}

.invitation-code:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0,123,255,0.15);
}

/* 复制按钮组样式 */
.copy-btn-group {
    opacity: 0.7;
    transition: opacity 0.2s ease;
}

.copy-btn-group:hover {
    opacity: 1;
}

.copy-btn-group .btn {
    border-radius: 4px;
    transition: all 0.2s ease;
}

.copy-btn-group .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.copy-btn-group .btn:active {
    transform: translateY(0);
}

/* 复制成功动画增强 */
@keyframes copySuccess {
    0% { 
        opacity: 0; 
        transform: scale(0.8); 
        background-color: transparent;
    }
    50% { 
        opacity: 1; 
        transform: scale(1.05); 
        background-color: rgba(40, 167, 69, 0.1);
    }
    100% { 
        opacity: 0; 
        transform: scale(1); 
        background-color: transparent;
    }
}

.copy-success-flash {
    animation: copySuccess 0.6s ease-out;
}

/* 响应式优化 */
@media (max-width: 768px) {
    .invitation-code {
        font-size: 0.8rem;
        padding: 4px 6px;
        min-width: 100px;
    }
    
    .copy-btn-group .btn {
        padding: 0.25rem 0.4rem;
        font-size: 0.8rem;
    }
}

/* 工具提示样式增强 */
.btn[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 5px;
}
</style>

<?php include 'includes/footer.php'; ?>