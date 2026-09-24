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
    } elseif (isset($_POST['action']) && $_POST['action'] === 'add_email_rule') {
        $trigger = $_POST['trigger_event'] ?? '';
        $recipients = $_POST['recipients'] ?? '';
        if ($em->createEmailRule($trigger, $recipients)) {
            $message = "Outbound email rule added successfully.";
        } else {
            $message = "Failed to add email rule. Please check input.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_email_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($em->deleteEmailRule($ruleId)) {
            $message = "Outbound email rule deleted successfully.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_email_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $isEnabled = (int)($_POST['is_enabled'] ?? 0);
        if ($em->toggleEmailRule($ruleId, $isEnabled)) {
            $message = "Outbound email rule status updated successfully.";
        }
    }
}

$defaultsList = $em->getDefaults();
$defaults = [];
foreach ($defaultsList as $d) {
    $defaults[$d['setting_key']] = $d['setting_value'];
}

$azureGroups = [];
$authObj = function_exists('get_auth') ? get_auth() : null;
if ($authObj && method_exists($authObj, 'getAccessToken')) {
    $token = $authObj->getAccessToken();
    if ($token && method_exists($authObj, 'getSSO')) {
        $azureGroups = $authObj->getSSO()->getAllGroups($token) ?? [];
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
        <!-- SLA & Escalation Settings -->
        <div class="col-md-10 mb-4">
            <div class="card shadow-sm border border-warning">
                <div class="card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-bell me-2"></i>SLA Alerting & Escalation Thresholds</span>
                    <span class="badge bg-dark text-white">Active</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Stale Incident SLA Threshold (Minutes)</label>
                            <input type="number" name="settings[sla_threshold_minutes]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['sla_threshold_minutes'] ?? '30') ?>" placeholder="30">
                            <div class="form-text small">Highlights active incidents with a visual SLA warning badge if no updates or state changes occur within this timeframe.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                    <h6 class="fw-bold text-primary mb-2 small"><i class="fa-solid fa-gears me-1"></i>OTRS API Ticket & Article Defaults</h6>
                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Enable OTRS Integration</label>
                            <select name="settings[otrs_enabled]" class="form-select form-select-sm">
                                <option value="1" <?= ($defaults['otrs_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= ($defaults['otrs_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold small">OTRS API Base URL</label>
                            <input type="text" name="settings[otrs_url]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_url'] ?? '') ?>" placeholder="https://otrs.example.com/otrs/nph-genericinterface.pl/Webservice/GenericInterface">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">OTRS API Key</label>
                            <input type="password" name="settings[otrs_key]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_key'] ?? '') ?>" placeholder="API Key">
                        </div>
                    </div>

                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">OTRS Ticket Queue</label>
                            <input type="text" name="settings[otrs_queue]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_queue'] ?? 'Raw') ?>" placeholder="e.g. Raw or Queue ID">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Default Ticket Type</label>
                            <input type="text" name="settings[otrs_type]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_type'] ?? 'Unclassified') ?>" placeholder="e.g. Unclassified">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Default Ticket State</label>
                            <input type="text" name="settings[otrs_state]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_state'] ?? 'new') ?>" placeholder="e.g. new">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Default Priority</label>
                            <input type="text" name="settings[otrs_priority]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_priority'] ?? '3 normal') ?>" placeholder="e.g. 3 normal">
                        </div>
                    </div>

                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Default Agent User ID</label>
                            <input type="text" name="settings[otrs_user_id]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['otrs_user_id'] ?? '1') ?>" placeholder="Default User ID for API">
                        </div>
                        <div class="col-md-6">
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
                        <label class="form-label fw-bold small">External Email Notification Template #1 (Default)</label>
                        <textarea name="settings[external_email_template]" class="form-control form-control-sm mb-3" rows="2"><?= htmlspecialchars($defaults['external_email_template'] ?? '') ?></textarea>

                        <label class="form-label fw-bold small">External Email Notification Template #2 (Outage / Advisory)</label>
                        <textarea name="settings[external_email_template_2]" class="form-control form-control-sm mb-3" rows="2"><?= htmlspecialchars($defaults['external_email_template_2'] ?? "ADVISORY: Service degradation affecting circuit {circuit_cid}. Details: {update_text}") ?></textarea>

                        <label class="form-label fw-bold small">External Email Notification Template #3 (Resolution)</label>
                        <textarea name="settings[external_email_template_3]" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($defaults['external_email_template_3'] ?? "RESOLVED: Service restored on circuit {circuit_cid}. Update: {update_text}") ?></textarea>

                        <div class="form-text small mt-2">Placeholders: <code>{circuit_cid}</code>, <code>{description}</code>, <code>{update_text}</code></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Outbound Email Settings & Confidentiality Statement -->
        <div class="col-md-10 mb-4">
            <div class="card shadow-sm border border-info">
                <div class="card-header bg-info text-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-envelope-circle-check me-2"></i>Outbound Email Settings & Confidentiality Statement</span>
                    <span class="badge bg-light text-info">Configurable</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3 border-bottom pb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small"><i class="fa-solid fa-at me-1 text-info"></i>Outbound Sender Email Address (From & Postfix Envelope Sender)</label>
                            <input type="email" name="settings[outbound_email_from]" class="form-control form-control-sm" value="<?= htmlspecialchars($defaults['outbound_email_from'] ?? 'noreply@example.com') ?>" placeholder="noreply@example.com">
                            <div class="form-text small">Used as the From header and passed as Postfix envelope-from (-f) parameter.</div>
                        </div>
                    </div>
                    <div>
                        <label class="form-label fw-bold small"><i class="fa-solid fa-shield-halved me-1 text-info"></i>Confidentiality Statement Footer (Appended to all outbound emails)</label>
                        <textarea name="settings[email_confidentiality_footer]" class="form-control form-control-sm" rows="2" placeholder="Enter confidentiality statement footer text..."><?= htmlspecialchars($defaults['email_confidentiality_footer'] ?? '') ?></textarea>
                        <div class="form-text small">This footer is automatically appended to all outbound notification emails generated by event triggers.</div>
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

<!-- Outbound Email Rules Management Section -->
<div class="row justify-content-center text-start mb-4">
    <div class="col-md-10">
        <div class="card shadow-sm border">
            <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-list-check me-2"></i>Outbound Email Notification Rules</span>
                <span class="badge bg-secondary"><?= count($em->listEmailRules()) ?> Rules</span>
            </div>
            <div class="card-body">
                <!-- Add New Rule Form -->
                <form method="POST" class="row g-2 mb-4 p-3 bg-light border rounded align-items-end">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add_email_rule">
                    <div class="col-md-4">
                        <label class="form-label fw-bold small">Event Trigger</label>
                        <select name="trigger_event" class="form-select form-select-sm" required>
                            <option value="">-- Select Trigger --</option>
                            <option value="creation">Incident Creation</option>
                            <option value="update">Incident Update</option>
                            <option value="metadata">Metadata Change</option>
                            <option value="closure">Incident Closure</option>
                            <option value="pir_closure">PIR on Closure</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">Recipient Email Address(es)</label>
                        <input type="text" name="recipients" class="form-control form-control-sm" placeholder="e.g. noc@example.com, management@example.com" required>
                        <div class="form-text small" style="font-size:0.7rem;">Comma or semicolon separated email addresses.</div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-success btn-sm w-100 fw-bold">
                            <i class="fa-solid fa-plus me-1"></i>Add Rule
                        </button>
                    </div>
                </form>

                <!-- Rules List Table -->
                <?php
                $emailRules = $em->listEmailRules();
                $triggerLabels = [
                    'creation' => 'Incident Creation',
                    'update' => 'Incident Update',
                    'metadata' => 'Metadata Change',
                    'metadata_change' => 'Metadata Change',
                    'closure' => 'Incident Closure',
                    'pir_closure' => 'PIR on Closure',
                    'pir' => 'PIR on Closure'
                ];
                ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Trigger Event</th>
                                <th>Recipient Email(s)</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($emailRules)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4 small">No email notification rules configured yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($emailRules as $rule): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-primary">
                                                <?= htmlspecialchars($triggerLabels[$rule['trigger_event']] ?? ucfirst($rule['trigger_event'])) ?>
                                            </span>
                                        </td>
                                        <td><code><?= htmlspecialchars($rule['recipients']) ?></code></td>
                                        <td>
                                            <?php if ($rule['is_enabled']): ?>
                                                <span class="badge bg-success">Enabled</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_email_rule">
                                                <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                                <input type="hidden" name="is_enabled" value="<?= $rule['is_enabled'] ? 0 : 1 ?>">
                                                <button type="submit" class="btn btn-xs btn-outline-<?= $rule['is_enabled'] ? 'warning' : 'success' ?> btn-sm me-1">
                                                    <i class="fa-solid fa-toggle-<?= $rule['is_enabled'] ? 'on' : 'off' ?> me-1"></i><?= $rule['is_enabled'] ? 'Disable' : 'Enable' ?>
                                                </button>
                                            </form>

                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this email rule?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_email_rule">
                                                <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                                <button type="submit" class="btn btn-xs btn-outline-danger btn-sm">
                                                    <i class="fa-solid fa-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

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
