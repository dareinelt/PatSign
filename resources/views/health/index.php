<?php require_once __DIR__ . '/../partials/helpers.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Systemstatus – PatSign</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="health-page">
    <main class="health-container">
        <header class="health-header">
            <div>
                <h1>Systemstatus</h1>
                <p class="health-meta">Stand: <span id="health-generated"><?= e($generatedAt) ?></span> · aktualisiert automatisch alle 60&nbsp;Sekunden</p>
            </div>
            <div class="health-overall is-<?= e($overall) ?>" id="health-overall">
                <span class="status-dot is-<?= e($overall) ?>" aria-hidden="true"></span>
                <span id="health-overall-label"><?= $overall === 'ok' ? 'Alle Systeme betriebsbereit' : ($overall === 'warn' ? 'Eingeschränkter Betrieb' : 'Störung') ?></span>
            </div>
        </header>

        <section class="card health-card" aria-labelledby="health-components-title">
            <h2 id="health-components-title">Komponenten</h2>
            <ul class="health-check-list" id="health-check-list">
                <?php foreach ($checks as $check): ?>
                    <li class="health-check is-<?= e($check['status']) ?>" data-key="<?= e($check['key']) ?>">
                        <span class="status-dot is-<?= e($check['status']) ?>" aria-hidden="true"></span>
                        <span class="health-check-label"><?= e($check['label']) ?></span>
                        <span class="health-check-detail"><?= e($check['detail']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card health-card" aria-labelledby="health-timeline-title">
            <h2 id="health-timeline-title">Verlauf der letzten 48 Stunden</h2>
            <div class="health-timeline" id="health-timeline">
                <?php foreach ($timeline as $row): ?>
                    <div class="health-timeline-row" data-key="<?= e($row['key']) ?>">
                        <span class="health-timeline-label"><?= e($row['label']) ?></span>
                        <div class="health-timeline-slots">
                            <?php foreach ($row['slots'] as $slot): ?>
                                <span class="health-slot is-<?= e($slot['status']) ?>" title="<?= e($slot['hour']) ?>: <?= e(['ok' => 'OK', 'warn' => 'Warnung', 'error' => 'Fehler', 'none' => 'Keine Daten'][$slot['status']] ?? $slot['status']) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="health-legend">
                <span><span class="health-slot is-ok"></span> OK</span>
                <span><span class="health-slot is-warn"></span> Warnung</span>
                <span><span class="health-slot is-error"></span> Fehler</span>
                <span><span class="health-slot is-none"></span> Keine Daten</span>
            </div>
        </section>
    </main>
    <script src="/js/health.js"></script>
</body>
</html>
