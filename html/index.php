<?php
declare(strict_types=1);
/** / — home. Phase 0: a placeholder that proves the sign-on landed (who you are, the site, the roles the kernel sent there); the shell and the home screen are Phase 2 (docs/build-specs/sso-shell.md). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();
$pdo = db();
$me = current_member();
if (!is_member_here()) {                       // admitted by the kernel with no site (a grant naming no restaurant): told why
    refuse(403, right_refusal('ri.read'));
}
$site = current_site();
log_screen_view($pdo, 'home');
$roles = $pdo->query('SELECT r.role_key, r.name FROM ri_roles r WHERE r.role_key = ANY (ri_member_roles_at(app_current_member_id(), ' . (int) current_site_id() . ')) ORDER BY r.sort_order')->fetchAll();
if (wants_json()) {
    respond_screen(['member_id' => (int) $me['id'], 'display_name' => $me['display_name'], 'site_id' => current_site_id(), 'site' => $site['name'] ?? null,
        'roles' => array_column($roles, 'role_key'), 'sees_cost' => sees_cost(), 'phase' => 'Phase 0: the shell is Phase 2']);
}
echo view('home/placeholder.php', ['me' => $me, 'roles' => $roles, 'site' => $site, 'sites' => held_sites()]);
