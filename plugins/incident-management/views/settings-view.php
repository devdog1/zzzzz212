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
        $message = "Incident management settings updated successfully.";
    }
}

$defaults = $em->getDefaults();
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
        <p class="text-muted">Configure OTRS ticketing and database integration, NetBox circuit management, MS Teams defaults, and external notifications.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm text-start" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row text-start justify-content-center">
    <div class="col-md-8 mb-4">
        <div class="card shadow-sm border mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="fa-solid fa-gears me-2"></i>Global Defaults & Integration Settings
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_defaults">

                    <?php foreach ($defaults as $d): ?>
                        <div class="mb-4 border-bottom pb-3">
                            <label class="form-label fw-bold text-dark mb-1"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($d['setting_key']))) ?></label>
                            <div class="text-muted small mb-2"><?= htmlspecialchars($d['description']) ?></div>

                            <?php if ($d['setting_key'] === 'always_include_azure_group_id'): ?>
                                <?php if (!empty($azureGroups)): ?>
                                    <select name="settings[<?= $d['setting_key'] ?>]" class="form-select form-select-sm">
                                        <option value="">-- None --</option>
                                        <?php foreach ($azureGroups as $g): ?>
                                            <option value="<?= htmlspecialchars($g['id']) ?>" <?= $d['setting_value'] == $g['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($g['id']) ?> (<?= htmlspecialchars($g['displayName']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="settings[<?= $d['setting_key'] ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars($d['setting_value'] ?? '') ?>" placeholder="Enter Azure Group GUID">
                                <?php endif; ?>
                            <?php elseif (in_array($d['setting_key'], ['otrs_enabled', 'netbox_enabled'])): ?>
                                <select name="settings[<?= $d['setting_key'] ?>]" class="form-select form-select-sm">
                                    <option value="0" <?= $d['setting_value'] === '0' ? 'selected' : '' ?>>Disabled</option>
                                    <option value="1" <?= $d['setting_value'] === '1' ? 'selected' : '' ?>>Enabled</option>
                                </select>
                            <?php elseif ($d['setting_key'] === 'otrs_db_pass'): ?>
                                <input type="password" name="settings[<?= $d['setting_key'] ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars($d['setting_value'] ?? '') ?>" placeholder="Password">
                            <?php elseif ($d['setting_key'] === 'external_email_template'): ?>
                                <textarea name="settings[<?= $d['setting_key'] ?>]" class="form-control form-control-sm" rows="4"><?= htmlspecialchars($d['setting_value'] ?? '') ?></textarea>
                            <?php else: ?>
                                <input type="text" name="settings[<?= $d['setting_key'] ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars($d['setting_value'] ?? '') ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn btn-primary btn-sm fw-bold">
                        <i class="fa-solid fa-save me-1"></i>Save Configuration
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border">
            <div class="card-header bg-dark text-white fw-bold">
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
                            <div>Updated <strong><?= htmlspecialchars($new['setting_key'] ?? 'N/A') ?></strong> to <code><?= htmlspecialchars(($new['setting_key'] ?? '') === 'otrs_db_pass' ? '******' : ($new['setting_value'] ?? 'NULL')) ?></code></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
