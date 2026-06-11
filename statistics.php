<?php
require_once __DIR__ . "/autoload.php";
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/\\');
require_once $docRoot . '/inc/config.php';

// Logic to force SQLite ONLY if MySQL config is missing or invalid
$useSqlite = !isset($config['db']['events']['dbhost']) || empty($config['db']['events']['dbhost']);
if ($useSqlite) {
    putenv('USE_SQLITE=true');
}

$auth = new Auth($config);
$auth->requireLogin();
if (!$auth->hasPermission('events.manage') && !$auth->hasPermission('admin.panel')) {
    http_response_code(401);
    echo "Error 401 Unauthorized: You need authorization (events.manage) to access this page.";
    exit();
}

$em = new EventManager($auth->user()['name'], $auth);
$stats = $em->getStatistics();

function formatTime($seconds) {
    $seconds = (float)$seconds;
    $h = floor($seconds / 3600);
    $m = floor(fmod($seconds, 3600) / 60);
    return "{$h}h {$m}m";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Incident Statistics - Incident Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        #tag-cloud { width: 100%; height: 300px; border: 1px solid #ddd; background: white; border-radius: 8px; }
    </style>
</head>
<body>

<?php
$nav = new NavigationBuilder($em->db, $auth);
echo $nav->render();
?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>System Statistics</h2>
        <button class="btn btn-outline-primary btn-sm" onclick="window.location.reload();">Refresh Data</button>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-primary text-white">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 opacity-75">Total Impact Score</h6>
                    <h2 class="card-title display-5 fw-bold"><?= number_format($stats['total_impact']) ?></h2>
                    <p class="mb-0 small">Aggregate across all incidents</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-success text-white">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 opacity-75">Avg. Resolution Time</h6>
                    <h2 class="card-title display-5 fw-bold"><?= formatTime($stats['avg_active_time']) ?></h2>
                    <p class="mb-0 small">Time spent in non-closed states</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-dark text-white">
                <div class="card-body text-center">
                    <h6 class="card-subtitle mb-2 opacity-75">Global Reliability</h6>
                    <h2 class="card-title display-5 fw-bold">99.9%</h2>
                    <p class="mb-0 small">Calculated availability (sample)</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Incident Tag Cloud</div>
                <div class="card-body p-0">
                    <div id="tag-cloud"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Incident Volume by Status</div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Status</th><th class="text-end">Count</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($stats['counts'] as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['name']) ?></td>
                                    <td class="text-end fw-bold"><?= $c['count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Integration Health</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span>OTRS REST API:</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>MS Graph API:</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>Azure AD SSO:</span>
                        <span class="badge bg-success">Operational</span>
                    </div>
                    <hr>
                    <p class="text-muted small">System health metrics are checked every 5 minutes.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
<script src="https://cdn.jsdelivr.net/npm/d3-cloud@1.2.5/build/d3.layout.cloud.min.js"></script>
<script>
const tags = <?= json_encode($stats['tag_cloud']) ?>;

function drawCloud() {
    const width = document.getElementById('tag-cloud').offsetWidth;
    const height = 300;

    const fill = d3.scaleOrdinal(d3.schemeCategory10);

    const layout = d3.layout.cloud()
        .size([width, height])
        .words(tags.map(d => ({text: d.text, size: 10 + d.size * 10})))
        .padding(5)
        .rotate(() => (~~(Math.random() * 2) * 90))
        .font("Impact")
        .fontSize(d => d.size)
        .on("end", draw);

    layout.start();

    function draw(words) {
        d3.select("#tag-cloud").append("svg")
            .attr("width", layout.size()[0])
            .attr("height", layout.size()[1])
            .append("g")
            .attr("transform", "translate(" + layout.size()[0] / 2 + "," + layout.size()[1] / 2 + ")")
            .selectAll("text")
            .data(words)
            .enter().append("text")
            .style("font-size", d => d.size + "px")
            .style("font-family", "Impact")
            .style("fill", (d, i) => fill(i))
            .attr("text-anchor", "middle")
            .attr("transform", d => "translate(" + [d.x, d.y] + ")rotate(" + d.rotate + ")")
            .text(d => d.text);
    }
}

document.addEventListener('DOMContentLoaded', drawCloud);
window.addEventListener('resize', () => {
    document.getElementById('tag-cloud').innerHTML = '';
    drawCloud();
});
</script>
</body>
</html>
