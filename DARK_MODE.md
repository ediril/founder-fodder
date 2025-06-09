# Dark Mode Implementation

This project includes a comprehensive dark mode implementation that automatically adapts to user system preferences and provides manual toggle functionality.

## Features

- **Automatic Detection**: Automatically detects and applies dark mode based on system preferences (`prefers-color-scheme: dark`)
- **Manual Toggle**: Provides a floating toggle button for manual theme switching
- **Persistent Preferences**: Remembers user's manual theme choice using localStorage
- **System Changes**: Automatically responds to system theme changes when no manual preference is set
- **Modular Design**: CSS and JavaScript are organized in separate files for easy maintenance

## Files

### CSS Files
- `static/css/site.css` - Contains both light and dark mode styles using CSS custom properties

### JavaScript Files
- `static/js/site.js` - Site functionality including dark mode manager with automatic detection and toggle functionality

### Templates
- `templates/partials/head.php` - Updated to include consolidated assets

## How It Works

### CSS Variables Approach
The dark mode implementation uses CSS custom properties (variables) defined at the `:root` level:

```css
:root {
  --bg-primary: #f5f7fa;
  --text-primary: #333;
  /* ... other light mode variables */
}

[data-theme="dark"] {
  --bg-primary: #1a1a1a;
  --text-primary: #e0e0e0;
  /* ... other dark mode variables */
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    /* Automatic dark mode variables */
  }
}
```

### JavaScript Manager
The `DarkModeManager` class handles:
- System preference detection
- Manual theme toggling
- Preference persistence
- Dynamic toggle button creation
- System change listening

### Theme States
- **System Default**: No `data-theme` attribute, follows system preference
- **Light Mode**: `data-theme="light"` forces light mode
- **Dark Mode**: `data-theme="dark"` forces dark mode

## Usage

The dark mode is automatically initialized when the page loads. No additional code is required.

### Manual API
If you need to control dark mode programmatically:

```javascript
// Get the manager instance
const darkMode = DarkModeManager.getInstance();

// Toggle theme
darkMode.toggleTheme();

// Set specific theme
darkMode.setTheme('dark');   // Force dark
darkMode.setTheme('light');  // Force light
darkMode.setTheme('system'); // Follow system

// Reset to system preference
darkMode.resetToSystem();
```

## Browser Support

- **Modern Browsers**: Full support with `addEventListener`
- **Older Browsers**: Fallback with `addListener`
- **No JavaScript**: Falls back to system preference using CSS media queries

## Customization

### Adding New Elements
To add dark mode support for new elements, add them to the dark mode section in `site.css`:

```css
.new-element {
  background-color: var(--bg-card);
  color: var(--text-primary);
  border: 1px solid var(--border-color);
}
```

### Modifying Colors
Update the CSS custom properties in the `:root` and `[data-theme="dark"]` selectors in the dark mode section of `site.css`.

## Performance

- CSS variables provide excellent performance with no JavaScript required for styling
- JavaScript only runs for preference management and toggle functionality
- Minimal DOM manipulation - only updates `data-theme` attribute
- Assets are loaded with cache busting via `asset_url()` function 