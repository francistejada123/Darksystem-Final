<?php
// modules/users/index.php
// User management

require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$page_title = 'User Management';
$current_page = 'users';
$base_url = BASE_URL;

$search = sanitize($_GET['search'] ?? '');
$role_filter = sanitize($_GET['role'] ?? '');
$status_filter = sanitize($_GET['status'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$where = "1=1";
$params = [];

if ($search) {
    $where .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($role_filter) {
    $where .= " AND u.role_id = ?";
    $params[] = $role_filter;
}

if ($status_filter === 'active') {
    $where .= " AND u.is_active = 1";
} elseif ($status_filter === 'inactive') {
    $where .= " AND u.is_active = 0";
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM users u WHERE $where";
$count_result = getRecord($count_sql, $params);
$total_records = $count_result ? $count_result['total'] : 0;
$total_pages = ceil($total_records / $limit);

// Get users
$sql = "SELECT u.*, r.role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        WHERE $where 
        ORDER BY u.created_at DESC 
        LIMIT $offset, $limit";

$users = getRecords($sql, $params);

// Get roles for filter / modals
$roles = getRecords("SELECT * FROM roles ORDER BY role_name");

// Roles that can be picked in the "Add User" form specifically. Teacher
// accounts are created from Teachers > Add Teacher (modules/teachers/add.php),
// which also creates the matching `users` row — Add User staying generic for
// them just invited duplicate/out-of-sync accounts. Student, Parent, and
// Registrar aren't created through this generic form either; Enrollment
// Officer is the new role for staff who process enrollment. The Edit User
// modal and the filter dropdown above still show every role, since those
// deal with accounts that may already hold any of them.
$addable_role_names = ['Administrator', 'Enrollment Officer'];
$addable_roles = array_values(array_filter($roles, function ($r) use ($addable_role_names) {
    return in_array($r['role_name'], $addable_role_names, true);
}));

// Get sections (for optional adviser assignment context — not required, kept light)
$csrf_token = generateCSRFToken();

// Summary stats (whole table, not just the current filtered page)
$stat_total = getCount('users');
$stat_active = getCount('users', 'is_active = 1');
$stat_inactive = getCount('users', 'is_active = 0');
$stat_admins = getCount('users', 'role_id = (SELECT role_id FROM roles WHERE role_name = ?)', ['Administrator']);

// Role -> badge color + icon map (falls back to secondary/user icon for unlisted roles)
$role_style = [
    'Administrator' => ['bg' => 'bg-ink',     'icon' => 'fa-user-shield'],
    'Registrar'      => ['bg' => 'bg-info',    'icon' => 'fa-id-card'],
    'Teacher'        => ['bg' => 'bg-success', 'icon' => 'fa-chalkboard-teacher'],
    'Student'        => ['bg' => 'bg-primary', 'icon' => 'fa-user-graduate'],
    'Parent'         => ['bg' => 'bg-warning', 'icon' => 'fa-user-friends'],
    'Enrollment Officer' => ['bg' => 'bg-dark', 'icon' => 'fa-user-plus'],
];

function userInitials($first, $last) {
    $a = $first !== '' ? mb_strtoupper(mb_substr($first, 0, 1)) : '';
    $b = $last !== '' ? mb_strtoupper(mb_substr($last, 0, 1)) : '';
    return $a . $b ?: '?';
}

function avatarColor($seed) {
    $palette = ['#14213D', '#356EA8', '#238863', '#B7791F', '#8854D0', '#C94A4A'];
    $index = crc32($seed) % count($palette);
    return $palette[$index];
}

include '../../includes/header.php';
?>

<div class="user-management-page">
<style>
.user-management-page { animation: userPageIn .28s ease-out backwards; }
.user-management-page h2 { color:#14213d; font-weight:800; letter-spacing:-.03em; }
.user-management-page > .row:first-child { margin-top:18px !important; }
.user-management-page > .row:first-child p { color:#6e809c !important; }
.user-management-page > .row:first-child .btn-primary { border:0; border-radius:10px; padding:11px 18px; box-shadow:0 8px 18px rgba(39,105,232,.2); transition:transform .18s ease, box-shadow .18s ease; }
.user-management-page > .row:first-child .btn-primary:hover { transform:translateY(-2px); box-shadow:0 11px 24px rgba(39,105,232,.28); }
.user-management-page .stats-card,
.user-management-page > .card { border:1px solid #e6edf6 !important; border-radius:14px !important; box-shadow:0 5px 18px rgba(35,75,125,.06) !important; }
.user-management-page .stats-card { transition:transform .18s ease, box-shadow .18s ease; }
.user-management-page .stats-card:hover { transform:translateY(-3px); box-shadow:0 10px 24px rgba(35,75,125,.11) !important; }
.user-management-page .stats-card .card-body { padding:18px 20px; }
.user-management-page .stats-card h6 { font-size:12.5px; text-transform:uppercase; letter-spacing:.04em; font-weight:800; color:#73849d !important; }
.user-management-page .stats-card h2 { color:#14213d; font-size:28px; }
.user-management-page .stats-icon { width:44px; height:44px; display:grid; place-items:center; border-radius:13px; font-size:18px; }
.user-management-page > .card { overflow:hidden; }
.user-management-page > .card .card-body { padding:17px 20px; }
.user-management-page form .form-label { color:#405875; font-size:12.5px; font-weight:800; margin-bottom:5px; text-transform:uppercase; letter-spacing:.03em; }
.user-management-page form .form-control,
.user-management-page form .form-select { height:40px; border-color:#dbe4ef; border-radius:9px; color:#304766; font-size:14.5px; }
.user-management-page form .form-control:focus,
.user-management-page form .form-select:focus { border-color:#4b7bec; box-shadow:0 0 0 3px rgba(75,123,236,.12); }
.user-management-page form .btn { height:40px; border-radius:9px; font-size:13.5px; font-weight:700; }
.user-management-page > .card .card-header { padding:17px 20px 13px !important; }
.user-management-page > .card .card-header h5 { color:#14213d; font-weight:800; }
.user-management-page > .card .card-header h5 i { color:#2769e8 !important; }
.user-management-page .table thead th { background:#f5f8fc; color:#70829c; font-size:12px; text-transform:uppercase; letter-spacing:.05em; font-weight:800; padding:12px 16px; border-bottom:1px solid #e5edf6; }
.user-management-page .table tbody td { padding:14px 16px; color:#304766; font-size:14.5px; border-color:#edf2f7; }
.user-management-page .table tbody tr { transition:background .16s ease, transform .16s ease; }
.user-management-page .table tbody tr:hover { background:#f8fbff; }
.user-management-page .avatar-chip { width:40px; height:40px; display:grid; place-items:center; border-radius:50%; color:#fff; font-size:13.5px; font-weight:800; box-shadow:0 3px 8px rgba(35,75,125,.12); }
.user-management-page .table .fw-semibold { color:#1c3152; font-size:14.5px; }
.user-management-page .table .badge { border-radius:7px; padding:6px 9px; font-size:12px; font-weight:800; }
.user-management-page .table .btn-group .btn { width:34px; height:34px; padding:0; display:inline-grid; place-items:center; border-radius:8px !important; margin:0 2px; }
.user-management-page .pagination .page-link { border:0; border-radius:7px; margin:0 2px; color:#58708f; font-size:13.5px; }
.user-management-page .pagination .active .page-link { background:#2769e8; color:#fff; }
.user-management-page .modal-content { border:0; border-radius:16px; box-shadow:0 24px 70px rgba(15,26,60,.22); }
.user-management-page .modal-header { padding:18px 22px; border-bottom:1px solid #e8eef6; }
.user-management-page .modal-body { padding:20px 22px; }
.user-management-page .modal-footer { padding:14px 22px 18px; border-top:1px solid #e8eef6; }
@keyframes userPageIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:none; } }
@media (max-width:700px) {
    .user-management-page > .row:first-child .btn-primary { width:100%; }
    .user-management-page .stats-card .card-body { padding:15px; }
}
</style>
<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="fas fa-users-cog text-primary me-2"></i>User Management</h2>
                <p class="text-muted mb-0">Manage administrator, teacher, and staff accounts for EGRADEPRO.</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-2"></i>Add User
            </button>
        </div>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Users</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($stat_total); ?></h2>
                    </div>
                    <div class="stats-icon bg-primary bg-opacity-10"><i class="fas fa-users text-primary"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Active Accounts</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($stat_active); ?></h2>
                    </div>
                    <div class="stats-icon bg-success bg-opacity-10"><i class="fas fa-user-check text-success"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Inactive Accounts</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($stat_inactive); ?></h2>
                    </div>
                    <div class="stats-icon bg-danger bg-opacity-10"><i class="fas fa-user-xmark text-danger"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card stats-card border-0 shadow-sm rounded-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Administrators</h6>
                        <h2 class="mb-0 fw-bold"><?php echo number_format($stat_admins); ?></h2>
                    </div>
                    <div class="stats-icon bg-warning bg-opacity-10"><i class="fas fa-user-shield text-warning"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card shadow-sm rounded-3 mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0"
                        placeholder="Name, username, or email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Role</label>
                <select name="role" class="form-select">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['role_id']; ?>" <?php echo $role_filter == $role['role_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role['role_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="fas fa-filter me-1"></i>Filter
                </button>
                <a href="index.php" class="btn btn-outline-secondary" title="Reset filters">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card shadow-sm rounded-3">
    <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fas fa-list text-primary me-2"></i>All Users</h5>
        <span class="text-muted small"><?php echo number_format($total_records); ?> result<?php echo $total_records == 1 ? '' : 's'; ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users && count($users) > 0): ?>
                        <?php foreach ($users as $user):
                            $style = $role_style[$user['role_name']] ?? ['bg' => 'bg-secondary', 'icon' => 'fa-user'];
                            $full_name = trim($user['first_name'] . ' ' . $user['last_name']);
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-chip" style="background: <?php echo avatarColor($user['username']); ?>;">
                                            <?php echo htmlspecialchars(userInitials($user['first_name'], $user['last_name'])); ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($full_name); ?></div>
                                            <div class="text-muted small">@<?php echo htmlspecialchars($user['username']); ?> &middot; ID <?php echo $user['user_id']; ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="badge <?php echo $style['bg']; ?>">
                                        <i class="fas <?php echo $style['icon']; ?> me-1"></i><?php echo htmlspecialchars($user['role_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo $user['last_login'] ? date('M d, Y g:i A', strtotime($user['last_login'])) : '<span class="text-muted">Never</span>'; ?></td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary" title="Edit user"
                                            onclick='openEditUser(<?php echo json_encode([
                                                "id" => (int)$user["user_id"],
                                                "username" => $user["username"],
                                                "email" => $user["email"],
                                                "first_name" => $user["first_name"],
                                                "last_name" => $user["last_name"],
                                                "role_id" => (int)$user["role_id"],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <?php if ($user['is_active']): ?>
                                                <button type="button" class="btn btn-outline-danger" title="Deactivate account"
                                                    onclick="confirmToggle(<?php echo $user['user_id']; ?>, 0, '<?php echo htmlspecialchars(addslashes($full_name)); ?>')">
                                                    <i class="fas fa-user-slash"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-success" title="Reactivate account"
                                                    onclick="confirmToggle(<?php echo $user['user_id']; ?>, 1, '<?php echo htmlspecialchars(addslashes($full_name)); ?>')">
                                                    <i class="fas fa-user-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-outline-secondary" disabled title="This is your own account">
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-users fa-2x d-block mb-2"></i>
                                No users found<?php echo ($search || $role_filter || $status_filter) ? ' for the selected filters.' : '.'; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-0">
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                Previous
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                Next
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus text-primary me-2"></i>Add User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="add.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="addUserPassword" class="form-control" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('addUserPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimum 6 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Role</label>
                        <select name="role_id" class="form-select" required>
                            <option value="">Select Role</option>
                            <?php foreach ($addable_roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Teacher, Student, Parent, and Registrar accounts are created from their own sections (e.g. Teachers &gt; Add Teacher).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus me-2"></i>Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-pen text-primary me-2"></i>Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="edit.php" id="editUserForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="user_id" id="editUserId">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" id="editUsername" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="editUserPassword" class="form-control" minlength="6" placeholder="Leave blank to keep current password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('editUserPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" id="editFirstName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" id="editLastName" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Role</label>
                        <select name="role_id" id="editRoleId" class="form-select" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .bg-ink { background: var(--ink) !important; color: #fff !important; }
</style>

<script>
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function openEditUser(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editEmail').value = user.email;
    document.getElementById('editFirstName').value = user.first_name;
    document.getElementById('editLastName').value = user.last_name;
    document.getElementById('editRoleId').value = user.role_id;
    document.getElementById('editUserPassword').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
}

function confirmToggle(userId, activate, name) {
    const message = activate
        ? 'Reactivate ' + name + '\'s account? They will be able to log in again.'
        : 'Deactivate ' + name + '\'s account? They will no longer be able to log in.';
    if (confirm(message)) {
        window.location.href = 'toggle_status.php?id=' + userId + '&activate=' + activate;
    }
}
</script>

</div>
<?php include '../../includes/footer.php'; ?>
