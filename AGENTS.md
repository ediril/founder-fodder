# Lint/Test Commands
- Lint Python code: `flake8 . --max-line-length=150`
- Type check: `mypy --ignore-missing-imports .`

# General Coding Guidelines
- Regarding imports, group standard library, third-party, and local imports with a blank line between groups.
- Don't keep backwards compatibility unless explicitly told to do so.

# JavaScript
- Use ES6 features, camelCase for variables and functions.
- Keep all JavaScript in a single file.

# PHP
- PHP 8.3 is installed on the web server.
- Put functions that are used in multiple places into `common.php`.

# CSS Coding Guidelines
- Keep all CSS in a single file.
- Follow BEM (Block Element Modifier) naming convention for CSS.
- Make sure CSS stays tight; remove duplications and redundant styling.
- Use [Bulma CSS](https://bulma.io/)
- Use Font Awesome for icons.

# Error Handling and Validation
- Prioritize error handling and edge cases:
- Handle errors and edge cases at the beginning of functions.
- Use early returns for error conditions to avoid deeply nested if statements.
- Place the happy path last in the function for improved readability.
- Avoid unnecessary else statements; use the if-return pattern instead.
- Use guard clauses to handle preconditions and invalid states early.
- Implement proper error logging and user-friendly error messages.
- Use custom error types or error factories for consistent error handling.

# Key Principles
- Implement exactly what is asked. If there is ambiguity in the request and you'll need to implement something in addition, first ask to confirm.
- Prefer iteration and modularization over code duplication.
- Use descriptive variable names with auxiliary verbs (e.g., is_active, has_permission).
- Use lowercase with underscores for directories and files (e.g., blueprints/user_routes.py).
- Favor named exports for routes and utility functions.
- Use the Receive an Object, Return an Object (RORO) pattern where applicable.