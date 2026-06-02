<?php

class Auth
{
    private PDO $db;
    private ?AzureADSSO $sso = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->db = $this->initDb($config);
    }

    /**
     * Lazy-load Azure SSO so scripts never interact with it directly
     */
    private function sso(): AzureADSSO
    {
        if ($this->sso instanceof AzureADSSO) {
            return $this->sso;
        }

        $azure = $this->config['azure'] ?? null;

        if (!$azure) {
            throw new RuntimeException("Azure SSO configuration missing.");
        }

        $this->sso = new AzureADSSO(
            $azure['clientId'],
            $azure['clientSecret'],
            $azure['redirectUri'],
            $azure['tenantId']
        );

        return $this->sso;
    }

    private function initDb(array $config): PDO
    {
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=utf8mb4",
            $config['db']['local']['dbhost'],
            $config['db']['local']['dbname']
        );

        return new PDO(
            $dsn,
            $config['db']['local']['dbuser'],
            $config['db']['local']['dbpass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }

    /* =========================================================
     * LOGIN
     * ========================================================= */
    public function login(): void
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2_state'] = $state;

        header("Location: " . $this->sso()->getAuthUrl($state));
        exit;
    }

    /* =========================================================
     * CALLBACK
     * ========================================================= */
    public function handleCallback(): bool
    {
        if (
            !isset($_GET['code'], $_GET['state']) ||
            $_GET['state'] !== ($_SESSION['oauth2_state'] ?? null)
        ) {
            return false;
        }

        $tokens = $this->sso()->getAccessToken($_GET['code']);
        if (!$tokens) return false;

        $userInfo = $this->sso()->getUserInfo($tokens['id_token']);
        $me = $this->sso()->getMe($tokens['access_token']);
        $groups   = $this->sso()->getUserGroups($tokens['access_token']);

        $azureOid = $me['id'] ?? $userInfo['sub'] ?? '';
        $email    = $userInfo['preferred_username'] ?? '';
        $name     = $userInfo['name'] ?? '';

        $userId = $this->syncUser($azureOid, $email, $name);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user'] = [
            'azure_oid' => $azureOid,
            'email'     => $email,
            'name'      => $name,
            'groups'    => $groups,
            'access_token' => $tokens['access_token']
        ];

        $_SESSION['roles'] = $this->getRoles($userId, $groups);
        $_SESSION['permissions'] = $this->getPermissions($userId, $groups);

        return true;
    }

    /* =========================================================
     * OPTIONAL: expose logout without exposing SSO class
     * ========================================================= */
    public function getLoginUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth2_state'] = $state;

        return $this->sso()->getAuthUrl($state);
    }

    public function getLogoutUrl(string $redirect): string
    {
        return $this->sso()->getLogoutUrl($redirect);
    }

    /* =========================================================
     * USER HELPERS (UNCHANGED BELOW)
     * ========================================================= */

    private function syncUser(string $azureOid, string $email, string $name): int
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE azure_oid = ?");
        $stmt->execute([$azureOid]);
        $user = $stmt->fetch();

        if ($user) {
            $stmt = $this->db->prepare("
                UPDATE users
                SET email = ?, display_name = ?, last_login = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$email, $name, $user['id']]);

            return (int)$user['id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (azure_oid, username, email, display_name, auto_provisioned, last_login)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");

        $stmt->execute([$azureOid, $email, $email, $name]);

        $userId = (int)$this->db->lastInsertId();
        $this->assignDefaultRoles($userId);

        return $userId;
    }

    private function assignDefaultRoles(int $userId): void
    {
        $roles = $this->db->query("SELECT role_id FROM default_roles")->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO user_roles (user_id, role_id)
            VALUES (?, ?)
        ");

        foreach ($roles as $roleId) {
            $stmt->execute([$userId, $roleId]);
        }
    }

    private function getRoles(int $userId, array $groups): array
    {
        $roles = [];

        if (!empty($groups)) {
            $in = implode(',', array_fill(0, count($groups), '?'));

            $stmt = $this->db->prepare("
                SELECT DISTINCT r.role_name
                FROM azure_group_roles agr
                JOIN roles r ON r.id = agr.role_id
                WHERE agr.azure_group_name IN ($in)
            ");

            $stmt->execute($groups);

            foreach ($stmt->fetchAll() as $row) {
                $roles[$row['role_name']] = true;
            }
        }

        $stmt = $this->db->prepare("
            SELECT r.role_name
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
        ");

        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            $roles[$row['role_name']] = true;
        }

        return $roles;
    }

    public function getPermissions(int $userId, array $groups): array
    {
        $permissions = [];

        $stmt = $this->db->prepare("
            SELECT p.permission_name
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN user_roles ur ON ur.role_id = rp.role_id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            $permissions[$row['permission_name']] = true;
        }

        if (!empty($groups)) {
            $in = implode(',', array_fill(0, count($groups), '?'));

            $stmt = $this->db->prepare("
                SELECT p.permission_name
                FROM permissions p
                JOIN role_permissions rp ON rp.permission_id = p.id
                JOIN azure_group_roles agr ON agr.role_id = rp.role_id
                WHERE agr.azure_group_name IN ($in)
            ");

            $stmt->execute($groups);

            foreach ($stmt->fetchAll() as $row) {
                $permissions[$row['permission_name']] = true;
            }
        }

        $stmt = $this->db->prepare("
            SELECT p.permission_name
            FROM user_permissions up
            JOIN permissions p ON p.id = up.permission_id
            WHERE up.user_id = ?
        ");
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            $permissions[$row['permission_name']] = true;
        }

        $stmt = $this->db->prepare("
            SELECT p.permission_name
            FROM denied_permissions dp
            JOIN permissions p ON p.id = dp.permission_id
            WHERE dp.user_id = ?
        ");
        $stmt->execute([$userId]);

        foreach ($stmt->fetchAll() as $row) {
            unset($permissions[$row['permission_name']]);
        }

        return $permissions;
    }

    public function hasPermission(string $permission): bool
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) return false;

        $groups = $_SESSION['user']['groups'] ?? [];

        return isset($this->getPermissions($userId, $groups)[$permission]);
    }

    public function hasRole(string $role): bool
    {
        return isset($_SESSION['roles'][$role]);
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function getAccessToken(): ?string
    {
        return $_SESSION['user']['access_token'] ?? null;
    }

    public function getSSO(): AzureADSSO
    {
        return $this->sso();
    }

    public function requireLogin(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
