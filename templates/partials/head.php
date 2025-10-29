<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title ?? 'FounderFodder'); ?></title>
    
    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo htmlspecialchars($page_description ?? 'For Founders, Makers and Tinkerers. Curated stories from Hacker News for entrepreneurs and builders.'); ?>">
    <meta name="keywords" content="founders, entrepreneurs, startups, hacker news, technology, innovation, business">
    <meta name="author" content="FounderFodder">
    
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?php echo htmlspecialchars($og_title ?? $page_title ?? 'FounderFodder'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($og_description ?? $page_description ?? 'For Founders, Makers and Tinkerers. Curated stories from Hacker News for entrepreneurs and builders.'); ?>">
    <meta property="og:type" content="<?php echo htmlspecialchars($og_type ?? 'website'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($og_url ?? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>">
    <meta property="og:site_name" content="FounderFodder">
    <?php if (!empty($og_image)): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($og_image); ?>">
    <meta property="og:image:alt" content="<?php echo htmlspecialchars($og_image_alt ?? 'FounderFodder'); ?>">
    <?php else: ?>
    <meta property="og:image" content="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath(); ?>/founder-fodder-og.jpg">
    <meta property="og:image:alt" content="FounderFodder - For Founders, Makers and Tinkerers">
    <?php endif; ?>
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    
    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@emrahdma">
    <meta name="twitter:creator" content="@emrahdma">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($og_title ?? $page_title ?? 'FounderFodder'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($og_description ?? $page_description ?? 'For Founders, Makers and Tinkerers. Curated stories from Hacker News for entrepreneurs and builders.'); ?>">
    <?php if (!empty($og_image)): ?>
    <meta name="twitter:image" content="<?php echo htmlspecialchars($og_image); ?>">
    <?php else: ?>
    <meta name="twitter:image" content="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath(); ?>/founder-fodder-og.jpg">
    <?php endif; ?>
    <meta name="twitter:image:alt" content="<?php echo htmlspecialchars($og_image_alt ?? 'FounderFodder'); ?>">
    
    <!-- Additional Meta Tags -->
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#d84800">
    <link rel="canonical" href="<?php echo htmlspecialchars($og_url ?? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>">
    
    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon.ico">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('/static/css/site.css'); ?>">
    <?php if (!empty($additional_css)): ?>
        <?php foreach ($additional_css as $css_url): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($css_url); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body> 