<?php
// Settings View for Incident Management Plugin

if (!has_permission('incident_management_manage_settings') && !has_permission('admin.panel')) {
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. Required permission: <code>incident_management_manage_settings</code></div>';
    return;
}

$currentUser = $_SESSION['user']['name'] ?? ($_SESSION['user']['display_name'] ?? 'User');
$em = new EventManager($currentUser);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    if (isset($_POST['action']) && $_POST['action'] === 'update_defaults') {
        foreach ($_POST['settings'] ?? [] as $key => $value) {
            $em->updateDefault($key, $value);
        }
        $message = "Incident system integration settings updated successfully.";
    }
}

$defaultsList = $em->getDefaults();
$defaults = [];
foreach ($defaultsList as $d) {
    $defaults[$d['setting_key']] = $d['setting_value'];
}

$azureGroups = [];
if (method_exists(get_auth(), 'getAccessToken')) {
    $token = get_auth()->getAccessToken();
    if ($token && method_exists(get_auth(), 'getSSO')) {
        $azureGroups = get_auth()->getSSO()->getAllGroups($token) ?? [];
    }
}
?>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-sliders text-primary me-2"></i>Incident System Settings</h1>
        <p class="text-muted">Manage system integration options for Microsoft Teams, OTRS Ticketing, and NetBox Circuit communications.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm text-start" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="POST" class="text-start">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="update_defaults">

    <div class="row justify-content-center">
        <!-- Microsoft Teams Integration -->
        <div class="col-md-10 mb-4">
            <div class="card shadow-sm border">
                <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa-brands fa-microsoft me-2"></i>Microsoft Teams Integration</span>
                    <span class="badge bg-light text-primary"><?= ($defaults['teams_enabled'] ?? '1') === '1' ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Enable Microsoft Teams Integration</label>
                            <select name="settings[teams_enabled]" class="form-select form-select-sm">
                                <option value="1" <?= ($defaults['teams_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= ($defaults['teams_enabled'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                            <div class="form-text small">Controls creation of Teams chats & notifications on incidents.</div>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label fw-bold small">Mandatory Azure AD Group ID</label>
                            <?php if (!empty($azureGroups)): ?>
                                <select name="settings[always_include_azure_group_id]" class="form-select form-select-sm">
                                    <option value="">-- None --</option>
                                    <?php foreach ($azureGroups as $g): ?>
                                        <option value="<?= htmlspecialchars($g['id']) ?>" <?= ($defaults['always_include_azure_group_id'] ?? '') == $g['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($g['displayName']) ?> (<?= htmlspecialchars($g['id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="settings[always_include_azure_group_id]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['always_include_azure_group_id'] ?? '') ?>" placeholder="Azure Group GUID">
                            <?php endif; ?>
                            <div class="form-text small">Azure AD Group ID to automatically invite to all incident Teams chats.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- OTRS Integration -->
        <div class="col-md-10 mb-4">
            <div class="card shadow-sm border">
                <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-ticket me-2"></i>OTRS Ticketing & Database Integration</span>
                    <span class="badge bg-secondary"><?= ($defaults['otrs_enabled'] ?? '0') === '1' ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Enable OTRS Integration</label>
                            <select name="settings[otrs_enabled]" class="form-select form-select-sm">
                                <option value="1" <?= ($defaults['otrs_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= ($defaults['otrs_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">OTRS API Base URL</label>
                            <input type="text" name="settings[otrs_url]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_url'] ?? '') ?>" placeholder="https://otrs.example.com/api/v1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">OTRS API Key</label>
                            <input type="password" name="settings[otrs_key]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_key'] ?? '') ?>" placeholder="API Key">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">OTRS Ticket Queue</label>
                            <input type="text" name="settings[otrs_queue]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_queue'] ?? 'Raw') ?>" placeholder="e.g. Raw or Queue ID">
                        </div>
                    </div>

                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold small">Default Customer User</label>
                            <input type="text" name="settings[otrs_customer_user]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_customer_user'] ?? 'customer@example.com') ?>">
                        </div>
                    </div>

                    <h6 class="fw-bold text-secondary mb-2 small"><i class="fa-solid fa-database me-1"></i>OTRS Direct MySQL Connection Credentials</h6>
                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">DB Host</label>
                            <input type="text" name="settings[otrs_db_host]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_db_host'] ?? '127.0.0.1') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">DB Name</label>
                            <input type="text" name="settings[otrs_db_name]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_db_name'] ?? 'otrs') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">DB Username</label>
                            <input type="text" name="settings[otrs_db_user]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_db_user'] ?? 'otrs_user') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">DB Password</label>
                            <input type="password" name="settings[otrs_db_pass]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_db_pass'] ?? '') ?>" placeholder="******">
                        </div>
                    </div>

                    <h6 class="fw-bold text-secondary mb-2 small"><i class="fa-solid fa-link me-1"></i>OTRS Deep Links</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Agent Ticket Link Base URL</label>
                            <input type="text" name="settings[otrs_ticket_link]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_ticket_link'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Agent Change Link Base URL</label>
                            <input type="text" name="settings[otrs_change_link]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_change_link'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- NetBox Integration -->
        <div class="col-md-10 mb-4">
            <div class="card shadow-sm border">
                <div class="card-header bg-success text-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-network-wired me-2"></i>NetBox Circuit & External Messaging Integration</span>
                    <span class="badge bg-light text-success"><?= ($defaults['netbox_enabled'] ?? '0') === '1' ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Enable NetBox Integration</label>
                            <select name="settings[netbox_enabled]" class="form-select form-select-sm">
                                <option value="1" <?= ($defaults['netbox_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= ($defaults['netbox_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">NetBox API URL</label>
                            <input type="text" name="settings[netbox_url]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['netbox_url'] ?? '') ?>" placeholder="https://netbox.example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">NetBox API Token</label>
                            <input type="password" name="settings[netbox_token]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['netbox_token'] ?? '') ?>" placeholder="Token">
                        </div>
                    </div>

                    <div>
                        <label class="form-label fw-bold small">External Email Notification Template</label>
                        <textarea name="settings[external_email_template]" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($defaults['external_email_template'] ?? '') ?></textarea>
                        <div class="form-text small">Placeholders: <code>{circuit_cid}</code>, <code>{description}</code>, <code>{update_text}</code></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-10 mb-4 text-center">
            <button type="submit" class="btn btn-primary btn-md fw-bold px-4">
                <i class="fa-solid fa-save me-1"></i>Save Integration Settings
            </button>
        </div>
    </div>
</form>

<div class="row justify-content-center text-start">
    <div class="col-md-10">
        <div class="card shadow-sm border">
            <div class="card-header bg-secondary text-white fw-bold">
                <i class="fa-solid fa-clock-rotate-left me-2"></i>Settings Audit History
            </div>
            <div class="card-body p-0">
                <div class="audit-list overflow-auto p-3" style="max-height: 250px;">
                    <?php
                    $audits = $em->getAuditTrail('plug_incident_management_defaults');
                    if (empty($audits)) echo '<p class="text-muted small mb-0">No settings changes recorded in audit history.</p>';
                    foreach ($audits as $audit):
                        $new = json_decode($audit['new_values'] ?? '{}', true);
                    ?>
                        <div class="mb-2 p-2 bg-light border rounded small" style="font-size: 0.75rem;">
                            <div class="text-muted" style="font-size:0.65rem;"><?= $audit['timestamp'] ?> by <?= htmlspecialchars($audit['user']) ?></div>
                            <div>Updated <strong><?= htmlspecialchars($new['setting_key'] ?? 'N/A') ?></strong> to <code><?= htmlspecialchars(in_array($new['setting_key'] ?? '', ['otrs_db_pass', 'otrs_key', 'netbox_token']) ? '******' : ($new['setting_value'] ?? 'NULL')) ?></code></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
