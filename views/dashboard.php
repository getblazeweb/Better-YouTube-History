<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <title><?php echo e($pageTitle); ?> - <?php echo e((string) config('app_name')); ?></title>
    <link rel="stylesheet" href="<?php echo e(asset_url('assets/style.css')); ?>">
</head>
<body>
    <header class="topbar">
        <div class="container topbar-inner">
            <div class="brand"><?php echo e((string) config('app_name')); ?></div>
            <nav class="topbar-actions">
                <a href="<?php echo e(app_url('index.php')); ?>" class="link">Dashboard</a>
                <a href="<?php echo e(app_url('security.php')); ?>" class="link">Security</a>
                <a href="<?php echo e(app_url('logout.php')); ?>" class="link link-logout">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo e($flash['type'] ?? 'success'); ?>">
                <?php echo e($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1><?php echo e($pageTitle); ?></h1>
                <p class="muted"><?php echo (int) $totalCount; ?> videos in history</p>
            </div>
            <div class="header-actions">
                <button type="button" class="button mobile-filter-toggle" aria-expanded="false" aria-controls="search-panel">Filter</button>
                <div id="search-panel" class="search-panel">
                    <form method="get" class="search-form">
                        <input type="hidden" name="page" value="1">
                        <input type="text" name="q" placeholder="Search title or channel" value="<?php echo e($search); ?>">
                        <label class="date-field">
                            <span class="date-field-label">From</span>
                            <input type="date" name="from" value="<?php echo e($dateFrom); ?>" aria-label="From date">
                        </label>
                        <label class="date-field">
                            <span class="date-field-label">To</span>
                            <input type="date" name="to" value="<?php echo e($dateTo); ?>" aria-label="To date">
                        </label>
                        <div class="search-form-actions">
                            <button type="submit" class="button">Search</button>
                            <a href="<?php echo e(app_url('index.php')); ?>" class="button">Clear Filter</a>
                        </div>
                    </form>
                </div>
                <a href="<?php echo e(app_url('api/refresh.php')); ?>" class="button">Refresh</a>
            </div>
        </div>

        <?php if (empty($grouped)): ?>
            <div class="card">
                <div class="empty-state">
                    <p class="muted">No watch history yet.</p>
                    <p class="muted">Upload your Google Takeout <code>watch-history.html</code> or <code>watch-history.json</code> to get started.</p>
                    <a href="<?php echo e(app_url('api/refresh.php')); ?>" class="button primary" style="margin-top:16px;">Import History</a>
                </div>
            </div>
        <?php else:
            $monthNames = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec'];
            $timelineByYear = [];
            foreach ($timelineDates as $date) {
                $parts = explode('-', $date);
                $y = $parts[0] ?? '';
                $m = $parts[1] ?? '';
                $d = $parts[2] ?? '';
                if ($y && $m && $d) {
                    $timelineByYear[$y][$m][$d] = $date;
                }
            }
            foreach ($timelineByYear as $year => $months) {
                foreach ($months as $month => $days) {
                    krsort($timelineByYear[$year][$month], SORT_NUMERIC);
                }
                krsort($timelineByYear[$year], SORT_STRING);
            }
            krsort($timelineByYear, SORT_STRING);
            $timelineBaseQuery = http_build_query(array_filter([
                'q' => $search !== '' ? $search : null,
                'from' => $dateFrom !== '' ? $dateFrom : null,
                'to' => $dateTo !== '' ? $dateTo : null,
            ]));
            $timelineBaseUrl = app_url('index.php') . '?' . ($timelineBaseQuery !== '' ? $timelineBaseQuery . '&' : '');
        ?>
            <div class="dashboard-layout">
                <aside class="timeline-sidebar">
                    <nav class="timeline-nav" aria-label="Jump to date">
                        <?php foreach ($timelineByYear as $year => $months): ?>
                            <details class="timeline-details">
                                <summary class="timeline-year"><?php echo e($year); ?></summary>
                                <?php foreach ($months as $month => $days): ?>
                                    <details class="timeline-details">
                                        <summary class="timeline-month"><?php echo e($monthNames[$month] ?? $month); ?></summary>
                                        <div class="timeline-days">
                                            <?php foreach ($days as $day => $date): ?>
                                                <?php $targetPage = $dateToPageMap[$date] ?? 1; ?>
                                                <a href="<?php echo e($timelineBaseUrl . 'page=' . $targetPage . '#date-' . $date); ?>" class="timeline-day"><?php echo e((int) $day); ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                            </details>
                        <?php endforeach; ?>
                    </nav>
                    <?php if (!empty($grouped) && $totalPages > 1): ?>
                        <?php
                        $baseQuery = http_build_query(array_filter([
                            'q' => $search !== '' ? $search : null,
                            'from' => $dateFrom !== '' ? $dateFrom : null,
                            'to' => $dateTo !== '' ? $dateTo : null,
                        ]));
                        $baseUrl = app_url('index.php') . '?' . ($baseQuery !== '' ? $baseQuery . '&' : '');
                        ?>
                        <nav class="pagination pagination-sidebar" aria-label="History pages">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo e($baseUrl . 'page=' . ($page - 1)); ?>" class="button">Previous</a>
                            <?php endif; ?>
                            <span class="pagination-info">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo e($baseUrl . 'page=' . ($page + 1)); ?>" class="button primary">Next</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </aside>
                <div class="dashboard-content">
            <?php foreach ($grouped as $date => $items): ?>
                <div class="card timeline-group" id="date-<?php echo e($date); ?>">
                    <div class="timeline-date"><?php echo e($date); ?></div>
                    <?php foreach ($items as $item): ?>
                        <?php $videoUrl = $item['url'] ?? 'https://www.youtube.com/watch?v=' . $item['video_id']; ?>
                        <div class="timeline-item">
                            <a href="<?php echo e($videoUrl); ?>" target="_blank" rel="noopener" class="timeline-item-thumb">
                                <picture>
                                    <source srcset="https://i.ytimg.com/vi_webp/<?php echo e($item['video_id']); ?>/mqdefault.webp" type="image/webp">
                                    <img src="https://i.ytimg.com/vi/<?php echo e($item['video_id']); ?>/mqdefault.jpg" alt="" loading="lazy" width="320" height="180">
                                </picture>
                            </a>
                            <div class="timeline-item-body">
                                <div>
                                    <a href="<?php echo e($videoUrl); ?>" target="_blank" rel="noopener">
                                        <?php echo e($item['title'] ?: 'Video'); ?>
                                    </a>
                                <?php if (!empty($item['channel'])): ?>
                                    <div class="timeline-meta">
                                        <?php if (!empty($item['channel_url'])): ?>
                                            <a href="<?php echo e($item['channel_url']); ?>" target="_blank" rel="noopener"><?php echo e($item['channel']); ?></a>
                                        <?php else: ?>
                                            <a href="https://www.youtube.com/results?search_query=<?php echo e(urlencode($item['channel'])); ?>" target="_blank" rel="noopener"><?php echo e($item['channel']); ?></a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                </div>
                                <div class="timeline-meta">
                                    <?php echo e(substr((string) $item['watched_at'], 11, 5)); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <script>
        (function() {
            var btn = document.querySelector('.mobile-filter-toggle');
            var panel = document.getElementById('search-panel');
            if (btn && panel) {
                btn.addEventListener('click', function() {
                    var open = panel.classList.toggle('is-open');
                    btn.setAttribute('aria-expanded', open);
                });
            }
        })();
    </script>
</body>
</html>
