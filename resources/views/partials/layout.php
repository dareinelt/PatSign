<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'PatSign' ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; }
        .error { color: #a10000; }
        .card { border: 1px solid #ddd; padding: 1rem; margin-bottom: 1rem; }
        input, button, textarea, select { padding: 0.5rem; margin: 0.25rem 0; width: 100%; max-width: 640px; }
    </style>
</head>
<body>
<?= $content ?? '' ?>
</body>
</html>
