# Elementor 2 MD

WordPress plugin that exports all Elementor content (pages, templates, design system) to Markdown + JSON for AI consumption.

## Features

- Export pages, templates, and theme builder layouts to structured Markdown
- Export Elementor Kit (design system: colors, fonts, spacing) as JSON
- One-click cleanup of all Elementor data from WordPress
- Admin UI + WP-CLI support

## Install

Download the latest zip from [Releases](https://github.com/frankie0736/elementor-2-md/releases), then upload via **Plugins → Add New → Upload Plugin** in WordPress.

## WP-CLI

```bash
wp e2md export [--output-dir=/path/to/dir]
wp e2md clean
```

## License

GPL-2.0-or-later
