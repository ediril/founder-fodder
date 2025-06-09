## Lint/Test Commands
- Lint Python code: `flake8 . --max-line-length=150`
- Type check: `mypy --ignore-missing-imports .`

## General Coding Guidelines
- Regarding imports, group standard library, third-party, and local imports with a blank line between groups.
- Don't keep backwards compatibility unless explicitly told to do so.

# JavaScript
- Regarding JavaScript, use ES6 features, camelCase for variables and functions.

# PHP
- PHP 8.3 is installed on the web server.
- Put functions that are used in multiple places into `common.php`.

## CSS Coding Guidelines
- Use Bulma CSS.
- Follow BEM (Block Element Modifier) naming convention for CSS.
- Put all CSS in `static/css/site.css` file.
- Make sure CSS stays tight; remove duplications and redundant styling.

