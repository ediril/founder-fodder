# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Founder Fodder is a content curation web application that aggregates and summarizes stories from Hacker News specifically targeted at founders, makers, and tinkerers. It uses AI (Google Gemini) to generate summaries of stories and their comments.

## Tech Stack

- **Backend**: PHP with SQLite3 database
- **Frontend**: Vanilla HTML/CSS/JavaScript with Bulma CSS framework
- **AI**: Google Gemini PHP Client for content summarization
- **Analytics**: Banalytiq (custom server-side analytics submodule)
- **Deployment**: FTP-based deployment with Python script

## Development Commands

### Local Development
```bash
# Run local PHP development server
php -S localhost:8000

# Install PHP dependencies
composer install

# Initialize git submodules (after clone)
git submodule update --init --recursive

# Update git submodules
git submodule update --remote
```

### Content Management
```bash
# Fetch new stories from Hacker News (default: top 50)
php fetcher.php [--type=top|new|best|active --limit=50]

# Generate AI summaries for fetched stories
php summarizer.php [--type=top|new|best|active --limit=50]

# Generate inspiration story images (includes person profile image download)
php generate_inspiration.php <story-name>
```

### Deployment
```bash
# Deploy content only (images, summaries, database)
python _deploy.py --content

# Deploy code only (PHP, JS, CSS, templates)
python _deploy.py --code

# Deploy everything
python _deploy.py --all
```

### Debugging
For VS Code debugging with Xdebug:
1. Start "Listen for Xdebug" session in VS Code (F5)
2. Run PHP script: `php <script.php> [args]`

## Architecture

### Core Components

1. **fetcher.php**: Fetches stories from HN API, stores metadata in SQLite, downloads story images
2. **summarizer.php**: Downloads story content and comments, generates AI summaries using Google Gemini
3. **index.php**: Main web interface with mixed ranking algorithm, pagination, and filtering

### Database Schema (SQLite)

- `stories`: Main story data (id, title, url, score, descendants, time, author, type, image_path, is_summarized)
- `ranks`: Story rankings by type (story_id, rank, type, fetched_at)

### Directory Structure

- `_originals/`: Raw story and comment text files
- `static/content/`: AI-generated markdown summaries
- `static/img/stories/`: Story thumbnail images
- `templates/`: PHP templates with partials
- `banalytiq/`: Analytics submodule (separate git repo)

### Key Features

- **Mixed Ranking**: Combines stories from different HN feeds (top, new, best, active) with weighted scoring
- **Lock Files**: Prevents concurrent execution of fetcher/summarizer using PID lock files
- **Signal Handling**: Graceful shutdown with SIGINT/SIGTERM support
- **Image Cleanup**: Automatic removal of unused images during deployment
- **Cache Busting**: Version-based cache control for static assets

## Important Notes

- Always check for existing patterns before adding new features
- The project uses Bulma CSS framework - follow its conventions
- Lock files (fetcher.lock, summarizer.lock) prevent concurrent runs
- Database is SQLite (hn_stories.db) - handle file locking appropriately
- Deployment uses FTP over TLS - credentials in ftp_config.yaml
- Banalytiq is a git submodule - commit changes separately

## Adding New Pages

Use the standard template structure:
```php
<?php $page_title = 'New Page'; include __DIR__ . '/partials/head.php'; ?>
<!-- Your content here -->
<?php include __DIR__ . '/partials/footer.php'; ?>
```