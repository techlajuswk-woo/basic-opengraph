# Basic OpenGraph by Techlaju

WordPress plugin that adds Open Graph and Twitter Card meta tags so links shared on WhatsApp, Facebook, X, and similar apps show a proper title, description, and image preview.

## What it does

When a page is viewed on the frontend, the plugin outputs tags such as:

- `og:title`, `og:description`, `og:image`, `og:url`, `og:type`, `og:site_name`, `og:locale`
- `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`

## Features

- **Default share image** — upload a logo / OG image (recommended 1200 × 630 px) used when a page has no featured image
- **Title format** — `Site Name | Page Title` with a configurable separator
- **Default sharing description** — custom homepage / fallback text for social previews
- **Per-page description** — optional Open Graph description box on posts and pages
- **Smart fallbacks** — if no custom text is set, uses excerpt, then page content, then the site tagline

## Description priority

1. Custom Open Graph description on the post/page  
2. Default sharing description (homepage / fallback)  
3. Post excerpt  
4. Extracted page content  
5. WordPress site tagline / site name  

## Setup

1. Upload the plugin to `wp-content/plugins/basic-opengraph` (or install from zip).
2. Activate **Basic OpenGraph by Techlaju** in WordPress.
3. Go to **Settings → OpenGraph**.
4. Set the default image, title separator, and default sharing description.
5. Optionally edit any post/page and fill in **Open Graph description** in the sidebar.

## Developer

[Techlaju](https://www.techlaju.com)
