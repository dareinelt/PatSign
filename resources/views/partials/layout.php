<?php require_once __DIR__ . '/helpers.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($title ?? 'PatSign') ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php include __DIR__ . '/icons.php'; ?>
<?= $content ?? '' ?>
<script src="/js/ui.js"></script>
<?php foreach (($scripts ?? []) as $script): ?>
    <script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
