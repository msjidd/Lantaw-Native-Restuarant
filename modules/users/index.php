<?php
/**
 * modules/users/index.php : user management (administrator only).
 * Create, edit, activate/deactivate, reset password and delete accounts.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_admin();

$backUrl = APP_URL . '/modules/users/index.php';
$me = (int)(current_user()['id'] ?? 0);

/** True when another active administrator exists besides $exceptId. */
function lantaw_other_active_admins(int $exceptId): int
{
    return (int)scalar(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?",
        [$exceptId]
    );
}

/* ---------------- POST actions ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    $username = preg_replace('/[^A-Za-z0-9_.-]/', '', input_str('username', 50));
    $fullName = input_str('full_name', 100);
    $email    = input_str('email', 100);
    $role     = in_array($_POST['role'] ?? '', ['admin', 'staff'], true) ? $_POST['role'] : '';

    if ($action === 'create') {
        $password = (string)($_POST['password'] ?? '');
        $errors = [];
        if ($username === '' || mb_strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 valid characters (letters, numbers, dot, dash, underscore).';
        }
        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($role === '') {
            $errors[] = 'Please choose a role.';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($username !== '' && scalar('SELECT id FROM users WHERE username = ? LIMIT 1', [$username]) !== null) {
            $errors[] = 'That username is already taken.';
        }
        if ($errors) {
            flash_set('danger', implode(' ', $errors));
            redirect($backUrl);
        }
        q('INSERT INTO users (username, password_hash, full_name, email, role, is_active)
           VALUES (?, ?, ?, ?, ?, 1)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email !== '' ? $email : null, $role]);
        $newId = (int)scalar('SELECT id FROM users WHERE username = ? LIMIT 1', [$username]);
        log_audit('create_user', 'users', $newId, 'Created ' . $username . ' (' . $role . ')');
        flash_set('success', 'User "' . $username . '" created.');
        redirect($backUrl);
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $user = row('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if (!$user) {
            flash_set('danger', 'User not found.');
            redirect($backUrl);
        }
        $errors = [];
        if ($username === '' || mb_strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 valid characters.';
        }
        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($role === '') {
            $errors[] = 'Please choose a role.';
        }
        if ($username !== '' && scalar('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1', [$username, $id]) !== null) {
            $errors[] = 'That username is already taken.';
        }
        if ($id === $me && $role !== 'admin') {
            $errors[] = 'You cannot remove your own administrator role.';
        }
        if ($user['role'] === 'admin' && (int)$user['is_active'] === 1 && $role !== 'admin'
            && lantaw_other_active_admins($id) === 0) {
            $errors[] = 'At least one active administrator must remain.';
        }
        if ($errors) {
            flash_set('danger', implode(' ', $errors));
            redirect($backUrl);
        }
        q('UPDATE users SET username = ?, full_name = ?, email = ?, role = ? WHERE id = ?',
            [$username, $fullName, $email !== '' ? $email : null, $role, $id]);
        log_audit('update_user', 'users', $id, 'Updated account for ' . $username);
        flash_set('success', 'User "' . $username . '" updated.');
        redirect($backUrl);
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $user = row('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if (!$user) {
            flash_set('danger', 'User not found.');
            redirect($backUrl);
        }
        if ($id === $me) {
            flash_set('danger', 'You cannot deactivate your own account.');
            redirect($backUrl);
        }
        $newState = (int)$user['is_active'] === 1 ? 0 : 1;
        if ($newState === 0 && $user['role'] === 'admin' && lantaw_other_active_admins($id) === 0) {
            flash_set('danger', 'At least one active administrator must remain.');
            redirect($backUrl);
        }
        q('UPDATE users SET is_active = ? WHERE id = ?', [$newState, $id]);
        log_audit('toggle_user', 'users', $id,
            ($newState === 1 ? 'Activated ' : 'Deactivated ') . $user['username']);
        flash_set('success', 'User "' . $user['username'] . '" ' . ($newState === 1 ? 'activated' : 'deactivated') . '.');
        redirect($backUrl);
    }

    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $password = (string)($_POST['new_password'] ?? '');
        $user = row('SELECT id, username FROM users WHERE id = ? LIMIT 1', [$id]);
        if (!$user) {
            flash_set('danger', 'User not found.');
            redirect($backUrl);
        }
        if (mb_strlen($password) < 8) {
            flash_set('danger', 'The new password must be at least 8 characters.');
            redirect($backUrl);
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        log_audit('reset_password', 'users', $id, 'Password reset for ' . $user['username']);
        flash_set('success', 'Password reset for "' . $user['username'] . '".');
        redirect($backUrl);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $user = row('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if (!$user) {
            flash_set('danger', 'User not found.');
            redirect($backUrl);
        }
        if ($id === $me) {
            flash_set('danger', 'You cannot delete your own account.');
            redirect($backUrl);
        }
        if ($user['role'] === 'admin' && lantaw_other_active_admins($id) === 0) {
            flash_set('danger', 'At least one active administrator must remain.');
            redirect($backUrl);
        }
        try {
            q('DELETE FROM users WHERE id = ?', [$id]);
        } catch (Throwable $e) {
            error_log('[users] delete failed: ' . $e->getMessage());
            flash_set('danger', 'This user has recorded transactions and cannot be deleted. Deactivate the account instead.');
            redirect($backUrl);
        }
        log_audit('delete_user', 'users', $id, 'Deleted account ' . $user['username']);
        flash_set('success', 'User "' . $user['username'] . '" deleted.');
        redirect($backUrl);
    }

    redirect($backUrl);
}

/* ---------------- page data ---------------- */
$users = rows(
    'SELECT u.*,
            (SELECT COUNT(*) FROM orders o WHERE o.created_by = u.id) AS orders_cnt
     FROM users u ORDER BY (u.role = "admin") DESC, u.username'
);
$totalUsers = count($users);
$adminCount = (int)scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$activeCount = (int)scalar('SELECT COUNT(*) FROM users WHERE is_active = 1');

$page_title = 'User Management';
$page_active = 'users';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    <div class="kpi" style="--kpi-color:#244A0C;--kpi-bg:#E9F0D6">
        <div class="kpi-top"><span class="kpi-label">Total accounts</span>
            <span class="kpi-icon"><i data-lucide="users"></i></span></div>
        <div class="kpi-value"><?= number_format($totalUsers) ?></div>
        <div class="kpi-foot">Administrators and staff</div>
    </div>
    <div class="kpi" style="--kpi-color:#8B4513;--kpi-bg:#F7E8D8">
        <div class="kpi-top"><span class="kpi-label">Administrators</span>
            <span class="kpi-icon"><i data-lucide="shield-check"></i></span></div>
        <div class="kpi-value"><?= number_format($adminCount) ?></div>
        <div class="kpi-foot">Full access accounts</div>
    </div>
    <div class="kpi" style="--kpi-color:#27833B;--kpi-bg:#E6F4EA">
        <div class="kpi-top"><span class="kpi-label">Active accounts</span>
            <span class="kpi-icon"><i data-lucide="user-check"></i></span></div>
        <div class="kpi-value"><?= number_format($activeCount) ?></div>
        <div class="kpi-foot"><?= number_format($totalUsers - $activeCount) ?> inactive</div>
    </div>
</div>

<div class="card-box mb-3">
    <div class="card-head">
        <h4>Add a new user</h4>
        <span class="hint">Passwords are stored as bcrypt hashes only</span>
    </div>
    <form method="post" action="index.php" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-12 col-md-3">
            <label class="form-label" for="username">Username</label>
            <input class="form-control" type="text" id="username" name="username" maxlength="50" required
                   pattern="[A-Za-z0-9_.\-]{3,50}" title="At least 3 characters: letters, numbers, dot, dash, underscore">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label" for="full_name">Full name</label>
            <input class="form-control" type="text" id="full_name" name="full_name" maxlength="100" required>
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label" for="email">Email <span class="small-note">(optional)</span></label>
            <input class="form-control" type="email" id="email" name="email" maxlength="100">
        </div>
        <div class="col-6 col-md-1">
            <label class="form-label" for="role">Role</label>
            <select class="form-select" id="role" name="role" required>
                <option value="staff">Staff</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" minlength="8" required
                   autocomplete="new-password">
        </div>
        <div class="col-12">
            <button class="btn btn-success btn-icon" type="submit"><i data-lucide="user-plus"></i> Create user</button>
        </div>
    </form>
</div>

<div class="card-box">
    <div class="card-head">
        <h4>Accounts <span class="badge bg-primary"><?= number_format($totalUsers) ?></span></h4>
        <span class="hint">Deactivate accounts instead of deleting them when transactions exist.</span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="num">Orders</th>
                    <th>Last login</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= (int)$u['is_active'] === 1 ? '' : 'row-muted' ?>">
                    <td>
                        <strong><?= e($u['full_name']) ?></strong>
                        <?php if ((int)$u['id'] === $me): ?>
                            <span class="badge bg-light text-dark border">You</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['username']) ?></td>
                    <td><?= e($u['email'] ?? '—') ?></td>
                    <td><?= $u['role'] === 'admin'
                        ? '<span class="badge bg-primary">Admin</span>'
                        : '<span class="badge bg-light text-dark border">Staff</span>' ?></td>
                    <td><?= status_badge((int)$u['is_active'] === 1 ? 'active' : 'inactive') ?></td>
                    <td class="num"><?= number_format((int)$u['orders_cnt']) ?></td>
                    <td><?= e(format_date($u['last_login_at'], true)) ?></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-light btn-icon" type="button"
                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                data-id="<?= (int)$u['id'] ?>"
                                data-username="<?= e($u['username']) ?>"
                                data-full-name="<?= e($u['full_name']) ?>"
                                data-email="<?= e($u['email'] ?? '') ?>"
                                data-role="<?= e($u['role']) ?>">
                            <i data-lucide="pencil"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-light btn-icon" type="button"
                                data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                                data-id="<?= (int)$u['id'] ?>"
                                data-username="<?= e($u['username']) ?>">
                            <i data-lucide="key-round"></i> Reset
                        </button>
                        <form method="post" action="index.php" class="d-inline"
                              data-confirm="<?= (int)$u['is_active'] === 1 ? 'Deactivate' : 'Activate' ?> <?= e($u['username']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm btn-light btn-icon" type="submit">
                                <i data-lucide="<?= (int)$u['is_active'] === 1 ? 'user-x' : 'user-check' ?>"></i>
                                <?= (int)$u['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                        <?php if ((int)$u['id'] !== $me): ?>
                            <form method="post" action="index.php" class="d-inline"
                                  data-confirm="Permanently delete <?= e($u['username']) ?>? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger btn-icon" type="submit">
                                    <i data-lucide="trash-2"></i> Delete
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="index.php" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="editUserId">
            <div class="modal-header">
                <h5 class="modal-title">Edit user</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="editUsername">Username</label>
                    <input class="form-control" type="text" id="editUsername" name="username" maxlength="50" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="editFullName">Full name</label>
                    <input class="form-control" type="text" id="editFullName" name="full_name" maxlength="100" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="editEmail">Email</label>
                    <input class="form-control" type="email" id="editEmail" name="email" maxlength="100">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="editRole">Role</label>
                    <select class="form-select" id="editRole" name="role" required>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Save changes</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="index.php" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="resetUserId">
            <div class="modal-header">
                <h5 class="modal-title">Reset password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small-note">Set a new password for <strong id="resetUserName"></strong>.
                    Share it privately and ask the user to change it after signing in.</p>
                <label class="form-label" for="newPassword">New password</label>
                <input class="form-control" type="password" id="newPassword" name="new_password"
                       minlength="8" required autocomplete="new-password">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Reset password</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var editModal = document.getElementById('editUserModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var b = event.relatedTarget;
            if (!b) return;
            document.getElementById('editUserId').value = b.getAttribute('data-id');
            document.getElementById('editUsername').value = b.getAttribute('data-username');
            document.getElementById('editFullName').value = b.getAttribute('data-full-name');
            document.getElementById('editEmail').value = b.getAttribute('data-email');
            document.getElementById('editRole').value = b.getAttribute('data-role');
        });
    }
    var resetModal = document.getElementById('resetPasswordModal');
    if (resetModal) {
        resetModal.addEventListener('show.bs.modal', function (event) {
            var b = event.relatedTarget;
            if (!b) return;
            document.getElementById('resetUserId').value = b.getAttribute('data-id');
            document.getElementById('resetUserName').textContent = b.getAttribute('data-username');
            document.getElementById('newPassword').value = '';
        });
    }
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
