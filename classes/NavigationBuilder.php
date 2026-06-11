<?php

class NavigationBuilder {
    private $db;
    private $auth;

    public function __construct($db, $auth = null) {
        $this->db = $db;
        $this->auth = $auth;
    }

    public function render() {
        $items = $this->getNavItems();
        $tree = $this->buildTree($items);

        $leftItems = array_filter($tree, function($i) { return $i['alignment'] === 'left'; });
        $rightItems = array_filter($tree, function($i) { return $i['alignment'] === 'right'; });

        ob_start();
        ?>
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
            <div class="container">
                <a class="navbar-brand h1 mb-0" href="index.php">Incident Manager</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav me-auto">
                        <?php foreach ($leftItems as $item) $this->renderItem($item); ?>
                    </ul>
                    <ul class="navbar-nav ms-auto">
                        <?php foreach ($rightItems as $item) $this->renderItem($item); ?>
                        <?php if ($this->auth && $this->auth->user()): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                    <strong><?= htmlspecialchars($this->auth->user()['name']) ?></strong>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                                </ul>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </nav>
        <?php
        return ob_get_clean();
    }

    private function renderItem($item) {
        $hasChildren = !empty($item['children']);
        $target = $item['is_external'] ? 'target="_blank"' : '';
        $activeClass = (basename($_SERVER['PHP_SELF']) === $item['url']) ? 'active' : '';

        if ($hasChildren): ?>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle <?= $activeClass ?>" href="#" role="button" data-bs-toggle="dropdown">
                    <?= htmlspecialchars($item['label']) ?>
                </a>
                <ul class="dropdown-menu">
                    <?php foreach ($item['children'] as $child): ?>
                        <li>
                            <a class="dropdown-item" href="<?= htmlspecialchars($child['url']) ?>" <?= $child['is_external'] ? 'target="_blank"' : '' ?>>
                                <?= htmlspecialchars($child['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php else: ?>
            <li class="nav-item">
                <a class="nav-link <?= $activeClass ?>" href="<?= htmlspecialchars($item['url']) ?>" <?= $target ?>>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            </li>
        <?php endif;
    }

    private function getNavItems() {
        $sql = "SELECT * FROM navigation ORDER BY weight ASC, label ASC";
        $items = $this->db->query($sql)->fetchAll();

        // Filter by permission
        return array_filter($items, function($item) {
            if (empty($item['permission'])) return true;
            if (!$this->auth) return false;
            return $this->auth->hasPermission($item['permission']);
        });
    }

    private function buildTree(array $elements, $parentId = null) {
        $branch = [];
        foreach ($elements as $element) {
            if ($element['parent_id'] == $parentId) {
                $children = $this->buildTree($elements, $element['id']);
                if ($children) {
                    $element['children'] = $children;
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }
}
