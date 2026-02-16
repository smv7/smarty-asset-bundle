# Smarty Asset Bundle

Combine, minify and preload CSS/JS assets in Smarty 5 with zero‑blocking and automatic HTTP/3 `Link` headers.

## Features

- **Zero‑blocking** – first request serves original files, cache generates in background  
- **Smart caching** – content‑based hashing for instant cache validation  
- **HTTP/3 preload** – automatic `Link` headers for learned resources (requires APCu)  
- **CDN ready** – configurable cache URL for any delivery network  
- **Windows / Linux compatible** – path handling works everywhere  

## Install

```bash
composer require smv7/smarty-asset-bundle
```

## Quick Start

```php
require_once 'vendor/autoload.php';

use SmartyBundler\AssetBundle;
use SmartyBundler\BundleExtension;

$combiner = new AssetBundle([
    'cache_dir'     => __DIR__ . '/cache/assets',   // must be writable
    'cache_url'     => 'https://cdn.example.com/assets',
    'document_root' => $_SERVER['DOCUMENT_ROOT'],   // required for file resolution
    'enable_apcu'   => extension_loaded('apcu'),    // auto‑detect
]);

// Send preload headers learned from previous requests (optional)
$combiner->sendCachedPreloads('main.tpl');

// Register Smarty plugin
$smarty->addExtension(new BundleExtension($combiner));
$smarty->display('main.tpl');
```

## Usage in Templates

### Basic bundle

```smarty
{bundle input=['/css/file1.css', '/css/file2.css']}
{bundle input=['/js/app.js']}
```

### With preload

```smarty
{bundle input=['/css/critical.css'] preload=1}
{bundle input=['/js/main.js'] defer=1 preload=1}
```

### Inline content

```smarty
{bundle content='.hide { display: none; }' type='css'}
{bundle content='console.log("ready");' type='js' defer=1}
```

### Media queries & async loading

```smarty
{bundle input=['/css/print.css'] media="print" onload="this.media='all'"}
{bundle input=['/js/analytics.js'] async=1}
```

## Configuration

### AssetBundle constructor options

| Option          | Required | Description                                |
|-----------------|----------|--------------------------------------------|
| `cache_dir`     | yes      | Absolute path to writable cache folder     |
| `cache_url`     | yes      | Base URL for cached bundles (no trailing slash) |
| `document_root` | yes      | Server document root (for file resolution) |
| `enable_apcu`   | no       | Enable APCu preload learning (default: auto-detected) |

### `{bundle}` parameters

| Parameter | Type           | Description                                   |
|-----------|----------------|-----------------------------------------------|
| `input`   | array\|string  | File(s) to bundle                             |
| `content` | string         | Inline CSS/JS content (instead of files)      |
| `type`    | string         | Force type `css` or `js` (auto‑detected)      |
| `preload` | bool           | Emit `Link` preload header for this resource  |
| `defer`   | bool           | Add `defer` attribute (JS only)               |
| `async`   | bool           | Add `async` attribute (JS only)               |
| `media`   | string         | CSS media query                               |
| `onload`  | string         | JavaScript to run when CSS loads              |

## Advanced

### Send preload headers early

Call `sendCachedPreloads()` before any output to let the browser discover critical resources sooner. You can pass a template name (used as APCu key).

### Disable APCu learning

```php
$combiner = new AssetBundle([
    // ...
    'enable_apcu' => false,
]);
```

### Clear cache

Manually delete all files in `cache_dir` or use the cache manager’s method if you expose it:

```php
// If you add a getter for CacheManager
$combiner->getCacheManager()->clearCache();
```

## Requirements

- PHP 8.5+
- Smarty 5.7+
- APCu (optional, for preload learning)
- `wikimedia/minify` (installed automatically)

## Troubleshooting

**Empty `src`/`href` attributes on Windows**  
Make sure all paths use forward slashes. The latest version normalises paths automatically – update to the current release.

**Cache not written**  
Check that `cache_dir` exists and is writable by the web server.

**Preload headers not sent**  
Verify APCu is installed and `enable_apcu` is `true`. Headers are sent only once per request.

**Double slashes in URLs**  
Do **not** add a trailing slash to `cache_url` – the library adds it internally.

**APCu required warning during install**  
The package checks for APCu; if you don’t need preload, you can ignore the warning or set `enable_apcu` to `false` in config.

## License

MIT
