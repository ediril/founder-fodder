<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title ?? 'Founder Fodder'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('/static/css/site.css'); ?>">
    <?php if (!empty($additional_css)): ?>
        <?php foreach ($additional_css as $css_url): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($css_url); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script src="<?php echo asset_url('/static/js/site.js'); ?>" defer></script>
</head>
<body> 