# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Workflow
- Always show your changes as a diff in VSCode before making any changes to allow for review
- Never commit changes without explicit approval

## Build Commands
- Run application: `python app.py`
- Fetch HN stories: `python hn_fetcher.py --type [top|new|best|all] --limit [count]`
- Install dependencies: `pip install -r requirements.txt`

## Lint/Test Commands
- Lint Python code: `flake8 . --max-line-length=100`
- Type check: `mypy --ignore-missing-imports .`
- Run tests: `pytest tests/`
- Run a single test: `pytest tests/test_file.py::test_function`

## Code Style Guidelines
- **Imports**: Group standard library, third-party, and local imports with a blank line between groups
- **Formatting**: Follow PEP 8 style guide with 4-space indentation and max line length of 100
- **Types**: Use docstrings with type hints rather than type annotations
- **Naming**: Use snake_case for functions and variables, CamelCase for classes
- **Error handling**: Use try/except blocks with specific exceptions and meaningful error messages
- **Comments**: Use docstrings for functions, classes, and modules; inline comments for complex logic
- **CSS**: Follow BEM (Block Element Modifier) naming convention
- **JavaScript**: Use ES6 features, camelCase for variables and functions