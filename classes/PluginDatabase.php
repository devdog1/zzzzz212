<?php
// PluginDatabase.php - Secure database isolation, safety mapping, and prefix wrapper for Plugins

class PluginDatabase
{
    private $plugin_slug;
    private $prefix;
    private $db;

    public function __construct($plugin_slug)
    {
        // Enforce safe clean slugs to prevent malicious SQL injections
        $this->plugin_slug = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace('-', '_', $plugin_slug));
        $this->prefix = 'plug_' . $this->plugin_slug . '_';
        if (function_exists('get_db_connection')) {
            $this->db = get_db_connection();
        } else {
            $database = new Database();
            $ref = new ReflectionClass($database);
            $prop = $ref->getProperty('pdo');
            $prop->setAccessible(true);
            $this->db = $prop->getValue($database);
        }
    }

    /**
     * Get prefix for the plugin namespace
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Helper to get full table name with plugin specific safety prefix.
     */
    public function getTableName($table_name)
    {
        $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
        return $this->prefix . $safe_table;
    }

    /**
     * Inspects mutating SQL queries to enforce strict table isolation, preventing plugins
     * from modifying core base tables or tables belonging to other plugins.
     */
    private function validateMutationAccess($sql)
    {
        $clean_sql = trim(preg_replace('/\s+/', ' ', $sql));
        $upper_sql = strtoupper($clean_sql);

        // Identify mutating statements
        $is_mutation = false;
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'TRUNCATE '] as $keyword) {
            if (strpos($upper_sql, $keyword) === 0 || strpos($upper_sql, ' ' . $keyword) !== false) {
                $is_mutation = true;
                break;
            }
        }

        if (!$is_mutation) {
            return; // Read-only SELECT queries are allowed
        }

        // Strip ON DUPLICATE KEY UPDATE clause to avoid matching column names after UPDATE in ON DUPLICATE KEY
        $sql_without_odku = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$/i', '', $clean_sql);

        $target_tables = [];

        // 1. INSERT INTO <table>
        if (preg_match('/INSERT\s+(?:INTO\s+)?[`\']?([a-zA-Z0-9_]+)[`\']?/i', $sql_without_odku, $m)) {
            $target_tables[] = $m[1];
        }

        // 2. UPDATE <table> SET
        if (preg_match('/UPDATE\s+[`\']?([a-zA-Z0-9_]+)[`\']?\s+SET/i', $sql_without_odku, $m)) {
            $target_tables[] = $m[1];
        }

        // 3. DELETE FROM <table>
        if (preg_match('/DELETE\s+FROM\s+[`\']?([a-zA-Z0-9_]+)[`\']?/i', $sql_without_odku, $m)) {
            $target_tables[] = $m[1];
        }

        // 4. DROP TABLE [IF EXISTS] <table>
        if (preg_match('/DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+[`\']?([a-zA-Z0-9_]+)[`\']?/i', $sql_without_odku, $m)) {
            $target_tables[] = $m[1];
        }

        // 5. ALTER TABLE <table>
        if (preg_match('/ALTER\s+TABLE\s+[`\']?([a-zA-Z0-9_]+)[`\']?/i', $sql_without_odku, $m)) {
            $target_tables[] = $m[1];
        }

        // 6. TRUNCATE [TABLE] <table>
        if (preg_match('/TRUNCATE\s+(?:TABLE\s+)?[`\']?([a-zA-Z0-9_]+)[`\']?/i', $sql_without_odku, $m)) {
            $target_tables[] = $m[1];
        }

        foreach ($target_tables as $target_table) {
            // Target table MUST start with $this->prefix
            if (strpos($target_table, $this->prefix) !== 0) {
                throw new Exception("Security Violation: Module '{$this->plugin_slug}' attempted unauthorized modification on table '{$target_table}'. Modules can only modify tables matching their own prefix '{$this->prefix}'.");
            }
        }
    }

    /**
     * Safely run a query on plugin-prefixed tables.
     * Enforces prefix rules on query keywords to prevent modification of core or sibling plugin tables.
     */
    public function query($sql, $params = [])
    {
        // Enforce strict security mutation validation
        $this->validateMutationAccess($sql);

        // Run prepared statements safely
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Create a table safely inside the plugin's namespace.
     */
    public function createTable($table_name, $columns_sql)
    {
        $full_table = $this->getTableName($table_name);
        $sql = "CREATE TABLE IF NOT EXISTS {$full_table} ({$columns_sql}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $this->db->exec($sql);
            return true;
        } catch (Exception $e) {
            error_log("Failed to create table {$full_table} for plugin {$this->plugin_slug}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Drop a plugin table safely.
     */
    public function dropTable($table_name)
    {
        $full_table = $this->getTableName($table_name);
        $sql = "DROP TABLE IF EXISTS {$full_table};";

        // Enforce security check before dropping
        $this->validateMutationAccess($sql);

        $this->db->exec($sql);
        return true;
    }
}
